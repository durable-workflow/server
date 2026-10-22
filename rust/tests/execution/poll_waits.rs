use super::*;
use durable_workflow_server::runtime::QueryTaskConfig;
use std::time::{Duration, Instant};

// Server 2.10.0's `LongPoller`: a worker poll that names no `timeout_seconds`
// (or names null) waits DW_WORKER_POLL_TIMEOUT seconds, 30 by default. A
// requested wait is a Laravel integer from 0 to 60; the setting itself is not
// capped. A query-task poll caps either wait by DW_QUERY_TASK_POLL_TIMEOUT.

async fn timed_poll(app: &Router, path: &str, body: Value) -> (StatusCode, Value, Duration) {
    let began = Instant::now();
    let (status, response) = request(app, "POST", path, body).await;
    (status, response, began.elapsed())
}

const WORKFLOW: &str = "/api/worker/workflow-tasks/poll";
const ACTIVITY: &str = "/api/worker/activity-tasks/poll";
const QUERY: &str = "/api/worker/query-tasks/poll";

#[tokio::test]
async fn polls_without_a_wait_use_the_configured_worker_poll_wait() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let configured = |seconds| QueryTaskConfig { worker_poll_timeout_seconds: seconds, ..Default::default() };
    let one = router(runtime.clone().with_query_task_config(configured(1)));
    let zero = router(runtime.with_query_task_config(configured(0)));
    register(&one, "worker", json!(["echo"]), json!(["greet"])).await;

    // One configured second, where native used to wait five.
    for (path, body) in [
        (WORKFLOW, json!({"worker_id":"worker","task_queue":"test"})),
        (WORKFLOW, json!({"worker_id":"worker","task_queue":"test","timeout_seconds":null})),
        (ACTIVITY, json!({"worker_id":"worker","task_queue":"test"})),
        (QUERY, json!({"worker_id":"worker","task_queue":"test"})),
    ] {
        let (status, response, waited) = timed_poll(&one, path, body.clone()).await;
        assert_eq!(status, StatusCode::OK, "{path} {body}: {response}");
        assert!(response["task"].is_null(), "{response}");
        assert!(waited >= Duration::from_millis(900) && waited < Duration::from_secs(4), "{path} {body} waited {waited:?}");
    }

    // A zero setting answers at once, as does a requested zero given as a
    // string.
    for (app, body) in [
        (&zero, json!({"worker_id":"worker","task_queue":"test"})),
        (&one, json!({"worker_id":"worker","task_queue":"test","timeout_seconds":"0"})),
    ] {
        let (status, response, waited) = timed_poll(app, WORKFLOW, body.clone()).await;
        assert_eq!(status, StatusCode::OK, "{body}: {response}");
        assert!(waited < Duration::from_millis(800), "{body} waited {waited:?}");
    }

    // A requested wait outside 0 through 60 is refused.
    for timeout in [json!(61), json!(-1), json!("soon"), json!(1.5)] {
        for path in [WORKFLOW, ACTIVITY, QUERY] {
            let (status, response) =
                request(&one, "POST", path, json!({"worker_id":"worker","task_queue":"test","timeout_seconds":timeout})).await;
            assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{path} {timeout}: {response}");
        }
    }
}

#[tokio::test]
async fn a_task_that_arrives_during_the_default_wait_is_claimed() {
    let database = TestDatabase::new().await;
    let runtime = database
        .open()
        .await
        .unwrap()
        .with_query_task_config(QueryTaskConfig { worker_poll_timeout_seconds: 10, ..Default::default() });
    let app = router(runtime);
    register(&app, "worker", json!(["echo"]), json!([])).await;
    let waiting = {
        let app = app.clone();
        tokio::spawn(async move { timed_poll(&app, WORKFLOW, json!({"worker_id":"worker","task_queue":"test"})).await })
    };
    tokio::time::sleep(Duration::from_millis(300)).await;
    let started = start(&app, "arrives-while-waiting").await;
    let (status, response, waited) = waiting.await.unwrap();
    assert_eq!(status, StatusCode::OK, "{response}");
    assert_eq!(response["task"]["run_id"], started["run_id"], "{response}");
    assert!(waited < Duration::from_secs(8), "waited {waited:?}");
}

// `WorkflowController::validateOperationName` runs first on a signal, update
// or query: a name is one to 256 bytes without control characters.
#[tokio::test]
async fn operation_names_are_validated_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    start(&app, "named").await;
    // PHP stores signal and update names in 255-byte columns, so native
    // refuses a 256-byte name its validation would pass.
    let (status, body) = request(&app, "POST", &format!("/api/workflows/named/signal/{}", "s".repeat(255)), json!([])).await;
    assert_eq!(status, StatusCode::ACCEPTED, "a 255-byte signal name is accepted: {body}");
    for (kind, reason) in [("signal", "invalid_signal_name"), ("update", "invalid_update_name")] {
        let (status, body) = request(&app, "POST", &format!("/api/workflows/named/{kind}/{}", "s".repeat(256)), json!({})).await;
        assert_eq!((status, body["reason"].as_str()), (StatusCode::UNPROCESSABLE_ENTITY, Some(reason)), "{body}");
    }
    let (status, body) = request(&app, "POST", &format!("/api/workflows/missing/query/{}", "q".repeat(256)), json!({})).await;
    assert_eq!((status, body["reason"].as_str()), (StatusCode::NOT_FOUND, Some("instance_not_found")), "a 256-byte query name passes validation: {body}");

    let too_long = "s".repeat(257);
    for (kind, name, message) in [
        ("signal", too_long.as_str(), "The signal name exceeds the maximum length of 256 bytes."),
        ("signal", "bad%0Aname", "The signal name must not contain control characters."),
        ("update", too_long.as_str(), "The update name exceeds the maximum length of 256 bytes."),
        ("update", "bad%7Fname", "The update name must not contain control characters."),
        ("query", too_long.as_str(), "The query name exceeds the maximum length of 256 bytes."),
        ("query", "bad%09name", "The query name must not contain control characters."),
    ] {
        let (status, body) = request(&app, "POST", &format!("/api/workflows/named/{kind}/{name}"), json!({})).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{kind} {name}: {body}");
        assert_eq!(body["reason"], "validation_failed", "{body}");
        assert_eq!(body["errors"][format!("{kind}_name")], json!([message]), "{body}");
        assert_eq!(body["workflow_id"], "named", "{body}");
    }
}
