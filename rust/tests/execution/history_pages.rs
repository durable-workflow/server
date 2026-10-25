use super::*;
use base64::{Engine, engine::general_purpose::STANDARD};

// Server 2.10.0 and Workflow 2.9.0 serve a polled workflow task its whole
// history unless the worker asks for a page size or an encoding. A
// continuation page holds the requested size, else DW_HISTORY_PAGE_SIZE_DEFAULT,
// bounded by DW_HISTORY_PAGE_SIZE_MAX. Pages of 50 or more events go gzip or
// deflate compressed to a worker that accepts it.

const SIGNALS: usize = 120;

/// A started run whose history holds `SIGNALS` received signals after its
/// start, with its first workflow task ready.
async fn long_history(app: &Router, workflow: &str) -> Value {
    let started = start(app, workflow).await;
    for _ in 0..SIGNALS {
        let (status, body) = request(app, "POST", &format!("/api/workflows/{workflow}/signal/note"), json!([])).await;
        assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    }
    // Building a history can outlast the 30-second stale window, after which
    // a silent registration cannot poll (`WorkerPollFence::isFresh`).
    let (status, body) = request(app, "POST", "/api/worker/heartbeat", json!({"worker_id":"worker"})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    started
}

async fn poll_with(app: &Router, fields: Value) -> (StatusCode, Value) {
    let mut body = json!({"worker_id": "worker", "task_queue": "test", "timeout_seconds": 0});
    for (field, value) in fields.as_object().unwrap() {
        body[field] = value.clone();
    }
    request(app, "POST", "/api/worker/workflow-tasks/poll", body).await
}

async fn continuation(app: &Router, task: &Value, token: &Value, fields: Value) -> (StatusCode, Value) {
    let mut body = json!({"lease_owner": task["lease_owner"], "workflow_task_attempt": task["workflow_task_attempt"],
        "next_history_page_token": token});
    for (field, value) in fields.as_object().unwrap() {
        body[field] = value.clone();
    }
    request(app, "POST", &format!("/api/worker/workflow-tasks/{}/history", task["task_id"].as_str().unwrap()), body).await
}

fn sequences(events: &Value) -> Vec<i64> {
    events.as_array().unwrap().iter().map(|event| event["sequence"].as_i64().unwrap()).collect()
}

fn decompressed(page: &Value) -> Value {
    let bytes = STANDARD.decode(page["history_events_compressed"].as_str().unwrap()).unwrap();
    let raw = match page["history_events_encoding"].as_str().unwrap() {
        "gzip" => miniz_oxide::inflate::decompress_to_vec(&bytes[10..bytes.len() - 8]).unwrap(),
        "deflate" => miniz_oxide::inflate::decompress_to_vec(&bytes).unwrap(),
        other => panic!("unexpected encoding {other}"),
    };
    serde_json::from_slice(&raw).unwrap()
}

#[tokio::test]
async fn a_poll_without_a_page_size_returns_the_whole_history() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!([])).await;
    long_history(&app, "whole-history").await;
    // An explicit null is the same as no page size, as in Laravel.
    let (status, polled) = poll_with(&app, json!({"history_page_size": null, "accept_history_encoding": null})).await;
    assert_eq!(status, StatusCode::OK, "{polled}");
    let task = &polled["task"];
    let total = task["total_history_events"].as_i64().unwrap();
    assert!(total > SIGNALS as i64, "{total}");
    assert_eq!(sequences(&task["history_events"]), (1..=total).collect::<Vec<_>>());
    assert!(task["next_history_page_token"].is_null(), "{task}");
    assert!(task.get("history_events_compressed").is_none());
}

#[tokio::test]
async fn requested_pages_continue_at_the_default_size() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!([])).await;
    long_history(&app, "paged-history").await;
    let (status, polled) = poll_with(&app, json!({"history_page_size": 50})).await;
    assert_eq!(status, StatusCode::OK, "{polled}");
    let task = &polled["task"];
    let total = task["total_history_events"].as_i64().unwrap();
    assert_eq!(sequences(&task["history_events"]), (1..=50).collect::<Vec<_>>());
    let token = task["next_history_page_token"].clone();
    assert!(token.is_string(), "{task}");
    // A continuation page without a size holds the default 500 events.
    let (status, rest) = continuation(&app, task, &token, json!({})).await;
    assert_eq!(status, StatusCode::OK, "{rest}");
    assert_eq!(sequences(&rest["history_events"]), (51..=total).collect::<Vec<_>>());
    assert!(rest["next_history_page_token"].is_null(), "{rest}");
    // A requested continuation size applies too.
    let (status, small) = continuation(&app, task, &token, json!({"history_page_size": 10})).await;
    assert_eq!(status, StatusCode::OK, "{small}");
    assert_eq!(sequences(&small["history_events"]), (51..=60).collect::<Vec<_>>());
    assert!(small["next_history_page_token"].is_string(), "{small}");
    for invalid in [json!(0), json!(1001), json!("ten")] {
        let (status, refused) = continuation(&app, task, &token, json!({"history_page_size": invalid})).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{refused}");
        assert_eq!(refused["reason"], "invalid_page_size", "{refused}");
    }
}

#[tokio::test]
async fn an_accepted_encoding_compresses_pages_of_fifty_or_more_events() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!([])).await;
    long_history(&app, "gzip-history").await;
    long_history(&app, "deflate-history").await;
    for (accept, encoding) in [("br, GZIP", "gzip"), ("deflate", "deflate")] {
        // An encoding alone pages at the default 500, which holds this history.
        let (status, polled) = poll_with(&app, json!({"accept_history_encoding": accept})).await;
        assert_eq!(status, StatusCode::OK, "{polled}");
        let task = &polled["task"];
        let total = task["total_history_events"].as_i64().unwrap();
        assert_eq!(task["history_events"], json!([]), "{task}");
        assert_eq!(task["history_events_encoding"], encoding);
        assert!(task["next_history_page_token"].is_null(), "{task}");
        let events = decompressed(task);
        assert_eq!(sequences(&events), (1..=total).collect::<Vec<_>>());
        // The compressed events are the plain events a continuation serves.
        let (status, plain) = continuation(&app, task, &json!(STANDARD.encode("0")), json!({})).await;
        assert_eq!(status, StatusCode::OK, "{plain}");
        assert_eq!(plain["history_events"], events);
        // A page under 50 events stays plain.
        let start = json!(STANDARD.encode((total - 10).to_string()));
        let (status, tail) = continuation(&app, task, &start, json!({"accept_history_encoding": encoding})).await;
        assert_eq!(status, StatusCode::OK, "{tail}");
        assert_eq!(sequences(&tail["history_events"]), ((total - 9)..=total).collect::<Vec<_>>());
        assert!(tail.get("history_events_compressed").is_none(), "{tail}");
    }
    for (value, message) in [
        (json!(5), "The accept history encoding field must be a string."),
        (json!("x".repeat(65)), "The accept history encoding field must not be greater than 64 characters."),
    ] {
        let (status, refused) = poll_with(&app, json!({"accept_history_encoding": value})).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{refused}");
        assert_eq!(refused["errors"]["accept_history_encoding"], json!([message]), "{refused}");
    }
}

#[tokio::test]
async fn configured_page_sizes_apply_like_php() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap().with_query_task_config(durable_workflow_server::runtime::QueryTaskConfig {
        history_page_size_default: 30,
        history_page_size_max: 40,
        ..Default::default()
    });
    let app = router(runtime);
    register(&app, "worker", json!(["echo"]), json!([])).await;
    long_history(&app, "configured-history").await;
    // The maximum caps a requested size.
    let (status, polled) = poll_with(&app, json!({"history_page_size": 100})).await;
    assert_eq!(status, StatusCode::OK, "{polled}");
    let task = &polled["task"];
    assert_eq!(sequences(&task["history_events"]), (1..=40).collect::<Vec<_>>());
    // A continuation without a size uses the configured default.
    let (status, next) = continuation(&app, task, &task["next_history_page_token"], json!({})).await;
    assert_eq!(status, StatusCode::OK, "{next}");
    assert_eq!(sequences(&next["history_events"]), (41..=70).collect::<Vec<_>>());
}
