//! Server #577: pausing activity dispatch on a task queue holds new activity
//! leases only. The ready backlog keeps its attempt count, deadlines keep
//! running, workflow delivery continues, and the pause survives a restart.
use super::*;

async fn rows(database: &TestDatabase, sql: &'static str) -> i64 {
    task_retry::rows(database, sql).await
}

fn schedule(activity: &str, timeout: Option<&str>) -> Value {
    let mut command = json!({"type":"schedule_activity","activity_type":activity,
        "arguments":envelope(Payload::Array(vec![Payload::Long(1)]))});
    if let Some(field) = timeout {
        command[field] = json!(1);
    }
    command
}

/// A started workflow whose first workflow task scheduled one activity.
async fn scheduled(app: &Router, workflow: &str, timeout: Option<&str>) -> Value {
    let started = start(app, workflow).await;
    let task = poll(app, "pause-worker", "workflow").await;
    assert_eq!(task["workflow_id"], workflow, "{task}");
    let (status, body) = finish_task(app, &task, json!([schedule("pause_activity", timeout)])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    started
}

async fn dispatch(app: &Router, action: &str, body: Value) -> (StatusCode, Value) {
    let (method, path) = match action {
        "show" => ("GET", "/api/task-queues/test/activity-dispatch".to_owned()),
        action => ("POST", format!("/api/task-queues/test/activity-dispatch/{action}")),
    };
    request(app, method, &path, body).await
}

async fn describe(app: &Router) -> Value {
    let (status, body) = request(app, "GET", "/api/task-queues/test", json!({})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body
}

#[tokio::test]
async fn a_paused_queue_holds_activity_dispatch_and_resume_releases_the_same_backlog() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "pause-worker", json!(["echo"]), json!(["pause_activity"])).await;
    scheduled(&app, "pause-held", None).await;

    // Never paused: active with no audit.
    let (status, never) = dispatch(&app, "show", json!({})).await;
    assert_eq!(status, StatusCode::OK, "{never}");
    assert_eq!(never["activity_dispatch"], json!({"state":"active","paused":false,"reason":null,"paused_at":null,
        "paused_by":null,"resumed_at":null,"resumed_by":null}));

    let (status, paused) = dispatch(&app, "pause", json!({"reason":"  vendor outage  "})).await;
    assert_eq!(status, StatusCode::OK, "{paused}");
    assert_eq!((paused["outcome"].clone(), paused["namespace"].clone(), paused["task_queue"].clone()),
        (json!("paused"), json!("default"), json!("test")));
    let state = &paused["activity_dispatch"];
    assert_eq!((state["state"].clone(), state["paused"].clone(), state["reason"].clone()),
        (json!("paused"), json!(true), json!("vendor outage")));
    // The authenticated principal, never one the request names.
    assert_eq!(state["paused_by"], json!({"type":"auth:token","id":"legacy-token","label":"Admin"}));
    assert!(state["paused_at"].as_str().is_some_and(|at| at.ends_with('Z')), "{state}");
    // Pausing again changes nothing.
    let (status, again) = dispatch(&app, "pause", json!({"reason":"another"})).await;
    assert_eq!(status, StatusCode::OK, "{again}");
    assert_eq!(again["outcome"], "already_paused");
    assert_eq!(again["activity_dispatch"], paused["activity_dispatch"]);

    // No activity is leased, by an immediate or a held poll.
    assert!(poll(&app, "pause-worker", "activity").await.is_null());
    let (status, held) = request(&app, "POST", "/api/worker/activity-tasks/poll",
        json!({"worker_id":"pause-worker","task_queue":"test","timeout_seconds":1})).await;
    assert_eq!(status, StatusCode::OK, "{held}");
    assert_eq!((held["task"].clone(), held["poll_status"].clone()), (Value::Null, json!("empty")));
    // The task stays ready with no attempt used.
    assert_eq!(rows(&database, "SELECT COUNT(*) FROM workflow_tasks WHERE task_type='activity' AND status='ready' AND attempt_count=0").await, 1);
    assert_eq!(rows(&database, "SELECT COUNT(*) FROM activity_attempts").await, 0);
    let detail = describe(&app).await;
    assert_eq!(detail["activity_dispatch"], paused["activity_dispatch"]);
    assert_eq!(detail["stats"]["activity_tasks"]["ready_count"], 1, "{detail}");

    // Workflow delivery is unchanged: another run's workflow task is leased.
    let second = start(&app, "pause-workflow-still-runs").await;
    let workflow = poll(&app, "pause-worker", "workflow").await;
    assert_eq!(workflow["workflow_id"], second["workflow_id"], "{workflow}");
    assert_eq!(finish_task(&app, &workflow, json!([{"type":"complete_workflow","result":envelope(Payload::Long(2))}])).await.0, StatusCode::OK);

    // The pause is durable: a fresh runtime on the same database still holds.
    runtime.close().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    assert_eq!(dispatch(&app, "show", json!({})).await.1["activity_dispatch"], paused["activity_dispatch"]);
    assert!(poll(&app, "pause-worker", "activity").await.is_null());
    let (status, listed) = request(&app, "GET", "/api/task-queues", json!({})).await;
    assert_eq!(status, StatusCode::OK, "{listed}");
    let queue = listed["task_queues"].as_array().unwrap().iter().find(|queue| queue["name"] == "test").unwrap();
    assert_eq!(queue["activity_dispatch"]["paused"], true);

    // A held poll is released by the resume and leases the same task, as its
    // first attempt.
    let waiting = {
        let app = app.clone();
        tokio::spawn(async move {
            request(&app, "POST", "/api/worker/activity-tasks/poll",
                json!({"worker_id":"pause-worker","task_queue":"test","timeout_seconds":20})).await
        })
    };
    tokio::time::sleep(std::time::Duration::from_millis(300)).await;
    let began = std::time::Instant::now();
    let (status, resumed) = dispatch(&app, "resume", json!({})).await;
    assert_eq!(status, StatusCode::OK, "{resumed}");
    assert_eq!(resumed["outcome"], "resumed");
    let state = &resumed["activity_dispatch"];
    assert_eq!((state["state"].clone(), state["paused"].clone()), (json!("active"), json!(false)));
    // The last cycle stays visible: why and by whom it was paused and resumed.
    assert_eq!((state["reason"].clone(), state["paused_at"].clone()), (json!("vendor outage"), paused["activity_dispatch"]["paused_at"].clone()));
    assert_eq!(state["resumed_by"], json!({"type":"auth:token","id":"legacy-token","label":"Admin"}));
    let (status, leased) = waiting.await.unwrap();
    assert_eq!(status, StatusCode::OK, "{leased}");
    assert!(began.elapsed() < std::time::Duration::from_secs(10), "the resume wakes the held poll");
    assert_eq!(leased["task"]["activity_type"], "pause_activity", "{leased}");
    assert_eq!(leased["task"]["attempt_number"], 1, "{leased}");
    // Resuming again changes nothing and keeps the record.
    let (status, again) = dispatch(&app, "resume", json!({})).await;
    assert_eq!(status, StatusCode::OK, "{again}");
    assert_eq!(again["outcome"], "not_paused");
    assert_eq!(again["activity_dispatch"], resumed["activity_dispatch"]);
    assert_eq!(describe(&app).await["activity_dispatch"], resumed["activity_dispatch"]);

    // A new pause replaces the record and holds new activities again.
    let (status, repaused) = dispatch(&app, "pause", json!({})).await;
    assert_eq!(status, StatusCode::OK, "{repaused}");
    assert_eq!(repaused["outcome"], "paused");
    assert_eq!((repaused["activity_dispatch"]["reason"].clone(), repaused["activity_dispatch"]["resumed_at"].clone()), (Value::Null, Value::Null));
    scheduled(&app, "pause-held-again", None).await;
    assert!(poll(&app, "pause-worker", "activity").await.is_null());
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn activity_deadlines_keep_running_while_dispatch_is_paused() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "pause-worker", json!(["echo"]), json!(["pause_activity"])).await;
    assert_eq!(dispatch(&app, "pause", json!({})).await.1["outcome"], "paused");
    let started = scheduled(&app, "pause-deadline", Some("schedule_to_start_timeout")).await;
    assert!(poll(&app, "pause-worker", "activity").await.is_null());
    // The original schedule-to-start deadline passes while the task waits.
    database.execute("UPDATE activity_executions SET schedule_deadline_at='2020-01-02 03:04:05.123456'").await;
    let deadline = tokio::time::Instant::now() + std::time::Duration::from_secs(5);
    let history = loop {
        let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
        if history.as_array().unwrap().iter().any(|event| event["event_type"] == "ActivityTimedOut") {
            break history;
        }
        assert!(tokio::time::Instant::now() < deadline, "the paused activity still times out: {history}");
        tokio::time::sleep(std::time::Duration::from_millis(25)).await;
    };
    assert!(!history.as_array().unwrap().iter().any(|event| event["event_type"] == "ActivityStarted"), "{history}");
    assert_eq!(rows(&database, "SELECT COUNT(*) FROM activity_attempts").await, 0);
    // Resuming does not revive the timed-out activity.
    assert_eq!(dispatch(&app, "resume", json!({})).await.1["outcome"], "resumed");
    assert!(poll(&app, "pause-worker", "activity").await.is_null());
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn pause_requests_are_validated_before_anything_is_recorded() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (status, body) = dispatch(&app, "pause", json!({"reason":"r".repeat(1001)})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    assert!(body["errors"]["reason"].is_array(), "{body}");
    let (status, body) = dispatch(&app, "pause", json!({"reason":["not","text"]})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    let long = format!("/api/task-queues/{}/activity-dispatch/pause", "q".repeat(256));
    let (status, body) = request(&app, "POST", &long, json!({})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    assert_eq!(rows(&database, "SELECT COUNT(*) FROM dw_task_queue_pauses").await, 0);
    // Queue names are exact: pausing one does not pause another that differs
    // only in case.
    let (status, body) = request(&app, "POST", "/api/task-queues/Test/activity-dispatch/pause", json!({})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(dispatch(&app, "show", json!({})).await.1["activity_dispatch"]["paused"], false);
    // A queue with no tasks or workers is listed while it is paused.
    let (_, listed) = request(&app, "GET", "/api/task-queues", json!({})).await;
    assert!(listed["task_queues"].as_array().unwrap().iter().any(|queue| queue["name"] == "Test"
        && queue["activity_dispatch"]["paused"] == true), "{listed}");
    runtime.close().await;
    database.remove().await;
}
