use super::*;

fn arguments(values: Vec<Payload>) -> Value {
    envelope(Payload::Array(values))
}

async fn start_activity(app: &Router, body: Value) -> (StatusCode, Value) {
    request(app, "POST", "/api/activities", body).await
}

fn kinds(history: &Value) -> Vec<String> {
    history.as_array().unwrap().iter().map(|event| event["event_type"].as_str().unwrap().to_owned()).collect()
}

fn last<'a>(history: &'a Value, kind: &str) -> &'a Value {
    &history.as_array().unwrap().iter().rev().find(|event| event["event_type"] == kind).unwrap()["payload"]
}

/// Waits for the native scheduler to settle what it owns.
async fn activity_until(app: &Router, id: &str, status: &str) -> Value {
    let deadline = tokio::time::Instant::now() + std::time::Duration::from_secs(10);
    loop {
        let (_, shown) = request(app, "GET", &format!("/api/activities/{id}"), Value::Null).await;
        if shown["status"] == status {
            return shown;
        }
        assert!(tokio::time::Instant::now() < deadline, "{shown}");
        tokio::time::sleep(std::time::Duration::from_millis(50)).await;
    }
}

// Published PHP 2.11.1's `POST /api/activities` writes a host run of type
// `dw.standalone_activity` with StartAccepted, WorkflowStarted and
// ActivityScheduled and a ready activity task. A worker claims it like any
// activity, and its result completes the host run with the same output.
#[tokio::test]
async fn a_standalone_activity_runs_in_its_own_host_run_like_php() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "worker", json!([]), json!(["greet"])).await;
    let input = arguments(vec![Payload::String("x".into())]);
    let (status, started) = start_activity(&app, json!({"activity_id":"sa-1","activity_type":"greet","task_queue":"test",
        "input":input,"business_key":"bk-1","retry_policy":{"max_attempts":3,"backoff_seconds":[0]},
        "start_to_close_timeout_seconds":60})).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    for (field, expected) in [("activity_id", json!("sa-1")), ("workflow_id", json!("sa-1")),
        ("workflow_type", json!("dw.standalone_activity")), ("activity_type", json!("greet")),
        ("activity_class", json!("greet")), ("task_queue", json!("test")), ("namespace", json!("default")),
        ("status", json!("running")), ("payload_codec", json!("avro")), ("schedule_to_start_deadline_at", Value::Null),
        ("command_status", json!("accepted")), ("command_source", json!("control_plane"))] {
        assert_eq!(started[field], expected, "{field}: {started}");
    }
    let (status, running) = start_activity(&app, json!({"activity_id":"sa-1","activity_type":"greet"})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{running}");
    assert_eq!(running["errors"], json!({"activity_id":["Standalone activity [sa-1] is already running."]}));

    let (_, pending) = request(&app, "GET", "/api/activities/sa-1", Value::Null).await;
    assert_eq!((pending["activity_status"].clone(), pending["attempt_count"].clone(), pending["attempts"].clone()),
        (json!("pending"), json!(0), json!([])), "{pending}");
    assert_eq!(pending["business_key"], "bk-1");

    let task = poll(&app, "worker", "activity").await;
    assert_eq!(task["arguments"], input, "{task}");
    assert_eq!(task["retry_policy"]["max_attempts"], 3, "{task}");
    let (_, claimed) = request(&app, "GET", "/api/activities/sa-1", Value::Null).await;
    assert_eq!(claimed["current_attempt"]["activity_attempt_id"], task["activity_attempt_id"], "{claimed}");
    assert_eq!((claimed["current_attempt"]["is_current"].clone(), claimed["current_attempt"]["can_continue"].clone()),
        (json!(true), json!(true)), "{claimed}");

    let path = format!("/api/worker/activity-tasks/{}/complete", task["task_id"].as_str().unwrap());
    let (status, completed) = request(&app, "POST", &path, json!({"lease_owner":task["lease_owner"],
        "activity_attempt_id":task["activity_attempt_id"],"result":input})).await;
    assert_eq!(status, StatusCode::OK, "{completed}");
    assert_eq!((completed["outcome"].clone(), completed["next_task_id"].clone()), (json!("completed"), Value::Null), "{completed}");

    let (_, done) = request(&app, "GET", "/api/activities/sa-1", Value::Null).await;
    assert_eq!((done["status"].clone(), done["closed_reason"].clone(), done["activity_status"].clone()),
        (json!("completed"), json!("completed"), json!("completed")), "{done}");
    assert_eq!(done["result"], input, "{done}");
    assert_eq!(done["current_attempt"]["stop_reason"], "attempt_closed", "{done}");
    let history = run_history(&app, &json!("sa-1"), &started["workflow_run_id"]).await;
    assert_eq!(kinds(&history), ["StartAccepted", "WorkflowStarted", "ActivityScheduled", "ActivityStarted",
        "ActivityCompleted", "WorkflowCompleted"]);
    assert_eq!(last(&history, "WorkflowCompleted")["output"], input);
    assert_eq!(last(&history, "WorkflowStarted")["business_key"], "bk-1");
    // No workflow task is ever created for a host run.
    assert_eq!(poll(&app, "worker", "workflow").await, Value::Null);

    // A closed activity's id starts a new run.
    let (status, again) = start_activity(&app, json!({"activity_id":"sa-1","activity_type":"greet","task_queue":"test"})).await;
    assert_eq!(status, StatusCode::CREATED, "{again}");
    assert_ne!(again["workflow_run_id"], started["workflow_run_id"]);
    let (_, runs) = request(&app, "GET", "/api/workflows/sa-1/runs", Value::Null).await;
    assert_eq!(runs["run_count"], 2, "{runs}");
    // Without input the activity has no arguments, as in PHP.
    let task = poll(&app, "worker", "activity").await;
    assert!(task.get("arguments").is_none(), "{task}");
    let (status, described) = request(&app, "GET", "/api/workflows/sa-1", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{described}");
    assert_eq!(described["input_envelope"], Value::Null, "{described}");
    runtime.close().await;
    database.remove().await;
}

// A terminal failure or timeout fails the host run with the activity's
// failure, as `closeStandaloneHostRun` and its timeout variant do.
#[tokio::test]
async fn a_standalone_activity_failure_or_timeout_fails_its_host_run_like_php() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "worker", json!([]), json!(["greet"])).await;
    let (_, started) = start_activity(&app, json!({"activity_id":"sa-2","activity_type":"greet","task_queue":"test"})).await;
    let task = poll(&app, "worker", "activity").await;
    let path = format!("/api/worker/activity-tasks/{}/fail", task["task_id"].as_str().unwrap());
    let (status, failed) = request(&app, "POST", &path, json!({"lease_owner":task["lease_owner"],
        "activity_attempt_id":task["activity_attempt_id"],"failure":{"class":"App\\Boom","message":"boom","type":"Boom"}})).await;
    assert_eq!(status, StatusCode::OK, "{failed}");
    assert_eq!(failed["next_task_id"], Value::Null, "{failed}");
    let (_, shown) = request(&app, "GET", "/api/activities/sa-2", Value::Null).await;
    assert_eq!((shown["status"].clone(), shown["closed_reason"].clone(), shown["activity_status"].clone()),
        (json!("failed"), json!("failed"), json!("failed")), "{shown}");
    let history = run_history(&app, &json!("sa-2"), &started["workflow_run_id"]).await;
    assert_eq!(kinds(&history).last().unwrap(), "WorkflowFailed");
    let activity_failed = last(&history, "ActivityFailed");
    let workflow_failed = last(&history, "WorkflowFailed");
    for (field, expected) in [("failure_id", activity_failed["failure_id"].clone()), ("source_kind", json!("activity_execution")),
        ("source_id", started["activity_execution_id"].clone()), ("failure_category", json!("activity")),
        ("non_retryable", json!(false)), ("exception_type", json!("Boom")), ("exception_class", json!("App\\Boom")),
        ("message", json!("boom")), ("exception", activity_failed["exception"].clone())] {
        assert_eq!(workflow_failed[field], expected, "{field}: {workflow_failed}");
    }

    let (_, timed) = start_activity(&app, json!({"activity_id":"sa-3","activity_type":"greet","task_queue":"nobody",
        "schedule_to_start_timeout_seconds":1})).await;
    assert!(timed["schedule_to_start_deadline_at"].is_string(), "{timed}");
    let shown = activity_until(&app, "sa-3", "failed").await;
    assert_eq!(shown["closed_reason"], "timed_out", "{shown}");
    let history = run_history(&app, &json!("sa-3"), &timed["workflow_run_id"]).await;
    assert_eq!(kinds(&history), ["StartAccepted", "WorkflowStarted", "ActivityScheduled", "ActivityTimedOut", "WorkflowFailed"]);
    let workflow_failed = last(&history, "WorkflowFailed");
    assert_eq!(workflow_failed["exception_class"], "Workflow\\V2\\Exceptions\\ActivityTimeoutException");
    assert_eq!((workflow_failed["failure_category"].clone(), workflow_failed["exception_type"].clone()),
        (json!("timeout"), Value::Null), "{workflow_failed}");
    assert_eq!(workflow_failed["exception"]["trace"], json!([]), "{workflow_failed}");
    runtime.close().await;
    database.remove().await;
}

// `ActivityController`'s refusals, identifiers and listing.
#[tokio::test]
async fn standalone_activity_requests_are_validated_and_listed_like_php() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (status, refused) = start_activity(&app, json!({"activity_id":"bad id!","task_queue":"q",
        "retry_policy":{"max_attempts":0,"backoff_seconds":[-1]},"heartbeat_timeout_seconds":0})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{refused}");
    assert_eq!(refused["errors"], json!({
        "activity_id":["The activity id field format is invalid."],
        "activity_type":["The activity type field is required."],
        "retry_policy.max_attempts":["The retry policy.max attempts field must be at least 1."],
        "heartbeat_timeout_seconds":["The heartbeat timeout seconds field must be at least 1."],
        "retry_policy.backoff_seconds.0":["The retry_policy.backoff_seconds.0 field must be at least 0."]}), "{refused}");

    start(&app, "wf-x").await;
    let (status, reserved) = start_activity(&app, json!({"activity_id":"wf-x","activity_type":"greet"})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{reserved}");
    assert_eq!(reserved["errors"]["activity_id"],
        json!(["Identifier [wf-x] is already reserved for workflow type [echo] and cannot be reused for a standalone activity."]));
    for missing in ["wf-x", "nope"] {
        let (status, absent) = request(&app, "GET", &format!("/api/activities/{missing}"), Value::Null).await;
        assert_eq!(status, StatusCode::NOT_FOUND, "{absent}");
        assert_eq!(absent, json!({"message":"Standalone activity not found.","reason":"activity_not_found"}));
    }

    let mut started = Vec::new();
    for _ in 0..3 {
        let (status, generated) = start_activity(&app, json!({"activity_type":"greet"})).await;
        assert_eq!(status, StatusCode::CREATED, "{generated}");
        assert_eq!(generated["task_queue"], "default", "{generated}");
        started.push(generated["activity_id"].clone());
    }
    let (_, page) = request(&app, "GET", "/api/activities?page_size=2", Value::Null).await;
    assert_eq!(page["activity_count"], 2, "{page}");
    assert_eq!(page["activities"][0]["activity_id"], started[2], "newest first: {page}");
    assert_eq!(page["next_page_token"], "Mg==", "{page}");
    let (_, rest) = request(&app, "GET", "/api/activities?page_size=2&next_page_token=Mg%3D%3D", Value::Null).await;
    assert_eq!((rest["activity_count"].clone(), rest["next_page_token"].clone()), (json!(1), Value::Null), "{rest}");
    assert_eq!(rest["activities"][0]["status_bucket"], "running", "{rest}");
    let (_, completed) = request(&app, "GET", "/api/activities?status=completed", Value::Null).await;
    assert_eq!(completed["activities"], json!([]), "{completed}");
    let (status, invalid) = request(&app, "GET", "/api/activities?status=cancelled&page_size=500", Value::Null).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{invalid}");
    assert_eq!(invalid["errors"], json!({"status":["The selected status is invalid."],
        "page_size":["The page size field must not be greater than 200."]}));
    runtime.close().await;
    database.remove().await;
}
