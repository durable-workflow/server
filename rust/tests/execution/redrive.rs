use super::*;

const FINGERPRINT: &str = "sha256:abababababababababababababababababababababababababababababababab";

fn kinds(history: &Value) -> Vec<String> {
    history.as_array().unwrap().iter().map(|event| event["event_type"].as_str().unwrap().to_owned()).collect()
}

async fn complete_activity(app: &Router, task: &Value) {
    let path = format!("/api/worker/activity-tasks/{}/complete", task["task_id"].as_str().unwrap());
    let done = request(app, "POST", &path, json!({"lease_owner":task["lease_owner"],
        "activity_attempt_id":task["activity_attempt_id"],"result":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
}

/// A run whose second activity failed and whose workflow then failed at
/// that step, as the PHP SDK reports it.
async fn failed_at_second_activity(app: &Router, workflow: &str) -> Value {
    let started = start(app, workflow).await;
    let task = poll(app, "worker", "workflow").await;
    finish_task(app, &task, json!([{"type":"schedule_activity","activity_type":"a","arguments":envelope(Payload::Array(vec![]))}])).await;
    complete_activity(app, &poll(app, "worker", "activity").await).await;
    let task = poll(app, "worker", "workflow").await;
    finish_task(app, &task, json!([{"type":"schedule_activity","activity_type":"b","arguments":envelope(Payload::Array(vec![]))}])).await;
    let activity = poll(app, "worker", "activity").await;
    let path = format!("/api/worker/activity-tasks/{}/fail", activity["task_id"].as_str().unwrap());
    let failed = request(app, "POST", &path, json!({"lease_owner":activity["lease_owner"],
        "activity_attempt_id":activity["activity_attempt_id"],"failure":{"class":"App\\Boom","message":"boom"}})).await;
    assert_eq!(failed.0, StatusCode::OK, "{}", failed.1);
    let task = poll(app, "worker", "workflow").await;
    let done = finish_task(app, &task, json!([{"type":"fail_workflow","message":"gave up","failed_step_sequence":2,
        "failed_activity_execution_id":activity["activity_execution_id"]}])).await;
    assert_eq!(done.1["run_status"], "failed", "{}", done.1);
    started
}

// Published PHP 2.11.1's redrive continues a run that failed at an activity
// step in a new run: its history reuses the completed activities before the
// step, so the worker reschedules only the failed one.
#[tokio::test]
async fn redrive_resumes_a_failed_run_at_its_failed_activity_like_php() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let registered = request(&app, "POST", "/api/worker/register", json!({"worker_id":"worker","task_queue":"test",
        "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":["a","b"],
        "workflow_definition_fingerprints":{"echo":FINGERPRINT}})).await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    let source = failed_at_second_activity(&app, "rd").await;
    let source_run = source["run_id"].as_str().unwrap();
    let path = format!("/api/workflows/rd/runs/{source_run}/redrive");

    let (status, invalid) = request(&app, "POST", &path, json!({"request_id":"x".repeat(192)})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{invalid}");
    assert_eq!(invalid["errors"]["request_id"], json!(["The request id field must not be greater than 191 characters."]));
    let (status, missing) = request(&app, "POST", "/api/workflows/rd/runs/nope/redrive", json!({})).await;
    assert_eq!((status, missing["reason"].clone()), (StatusCode::NOT_FOUND, json!("run_not_found")), "{missing}");

    let (status, redriven) = request(&app, "POST", &path, json!({"request_id":"rq-1"})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{redriven}");
    for (field, expected) in [("continued_from_run_id", json!(source_run)), ("resume_step_sequence", json!(2)),
        ("command_status", json!("accepted")), ("outcome", json!("redriven")), ("reason", Value::Null)] {
        assert_eq!(redriven[field], expected, "{field}: {redriven}");
    }
    let run = redriven["run_id"].as_str().unwrap().to_owned();
    assert_eq!(redriven["workflow_run_id"], json!(run));
    let (status, again) = request(&app, "POST", &path, json!({"request_id":"rq-1"})).await;
    assert_eq!((status, again["run_id"].clone(), again["task_id"].clone()), (StatusCode::OK, json!(run), Value::Null), "{again}");
    let (status, current) = request(&app, "POST", &format!("/api/workflows/rd/runs/{run}/redrive"), json!({})).await;
    assert_eq!((status, current["reason"].clone()), (StatusCode::CONFLICT, json!("run_not_failed")), "{current}");

    let history = run_history(&app, &json!("rd"), &json!(run)).await;
    assert_eq!(kinds(&history), ["StartAccepted", "WorkflowStarted", "ActivityCompleted"]);
    let started = &history[1]["payload"];
    for (field, expected) in [("continued_from_run_id", json!(source_run)), ("resume_step_sequence", json!(2)),
        ("recovery_kind", json!("redrive")), ("workflow_definition_fingerprint", json!(FINGERPRINT))] {
        assert_eq!(started[field], expected, "{field}: {started}");
    }
    let reused = &history[2]["payload"];
    assert_eq!((reused["sequence"].clone(), reused["activity_type"].clone(), reused["reused_from_run_id"].clone()),
        (json!(1), json!("a"), json!(source_run)), "{reused}");

    // The worker replays the reused step and reschedules only the failed one.
    let task = poll(&app, "worker", "workflow").await;
    assert_eq!(task["run_id"], json!(run), "{task}");
    let resumed = finish_task(&app, &task, json!([{"type":"schedule_activity","activity_type":"b","arguments":envelope(Payload::Array(vec![]))}])).await;
    assert_eq!(resumed.0, StatusCode::OK, "{}", resumed.1);
    let activity = poll(&app, "worker", "activity").await;
    assert_eq!(activity["activity_type"], "b", "{activity}");
    complete_activity(&app, &activity).await;
    let task = poll(&app, "worker", "workflow").await;
    let done = finish_task(&app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Array(vec![]))}])).await;
    assert_eq!(done.1["run_status"], "completed", "{}", done.1);
    let history = run_history(&app, &json!("rd"), &json!(run)).await;
    let scheduled = history.as_array().unwrap().iter().find(|event| event["event_type"] == "ActivityScheduled").unwrap();
    assert_eq!(scheduled["payload"]["sequence"], 2, "{scheduled}");
    let (_, described) = request(&app, "GET", "/api/workflows/rd", Value::Null).await;
    assert_eq!((described["run_number"].clone(), described["continued_from_run_id"].clone(), described["recovery_kind"].clone()),
        (json!(2), json!(source_run), json!("redrive")), "{described}");

    // A run that failed without an activity boundary cannot be redriven.
    let plain = start(&app, "rd2").await;
    let task = poll(&app, "worker", "workflow").await;
    finish_task(&app, &task, json!([{"type":"fail_workflow","message":"x"}])).await;
    let (status, refused) = request(&app, "POST", &format!("/api/workflows/rd2/runs/{}/redrive", plain["run_id"].as_str().unwrap()), json!({})).await;
    assert_eq!(status, StatusCode::CONFLICT, "{refused}");
    for (field, expected) in [("reason", json!("unrecorded_failure_boundary")), ("command_status", json!("rejected")),
        ("outcome", json!("rejected")), ("accepted", json!(false)), ("workflow_run_id", Value::Null)] {
        assert_eq!(refused[field], expected, "{field}: {refused}");
    }
    runtime.close().await;
    database.remove().await;
}

// A redriven run closes as any other run. Its `redrive` link names the
// failed run it came from, and PHP's close follows only `child_workflow`
// links (`WorkflowStub::attemptCancel`, `attemptTerminate`).
#[tokio::test]
async fn a_redriven_run_cancels_and_terminates_like_php() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let registered = request(&app, "POST", "/api/worker/register", json!({"worker_id":"worker","task_queue":"test",
        "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":["a","b"],
        "workflow_definition_fingerprints":{"echo":FINGERPRINT}})).await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    for (workflow, command) in [("rd-cancel", "cancel"), ("rd-terminate", "terminate")] {
        let source = failed_at_second_activity(&app, workflow).await;
        let path = format!("/api/workflows/{workflow}/runs/{}/redrive", source["run_id"].as_str().unwrap());
        let (status, redriven) = request(&app, "POST", &path, json!({})).await;
        assert_eq!(status, StatusCode::ACCEPTED, "{redriven}");
        let (status, closed) = request(&app, "POST", &format!("/api/workflows/{workflow}/{command}"),
            json!({"reason":"redriven run"})).await;
        assert_eq!(status, StatusCode::OK, "{command}: {closed}");
        let (_, described) = request(&app, "GET", &format!("/api/workflows/{workflow}"), Value::Null).await;
        assert_eq!((described["run_id"].clone(), described["status"].clone()),
            (redriven["run_id"].clone(), json!(if command == "cancel" { "cancelled" } else { "terminated" })), "{described}");
    }
    runtime.close().await;
    database.remove().await;
}
