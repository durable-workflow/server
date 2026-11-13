//! Server #934: a caller's request_id makes a signal or update retry safe. A
//! retry with the same command intent gets the original receipt, before any
//! run gate and with nothing written; the same key with a different intent
//! is a typed conflict, also with nothing written.
use super::*;

fn input(value: i64, key: Option<&str>) -> Value {
    let mut body = json!({"input":envelope(Payload::Array(vec![Payload::Long(value)]))});
    if let Some(key) = key {
        body["request_id"] = json!(key);
    }
    body
}

async fn signal(app: &Router, workflow: &str, name: &str, body: Value) -> (StatusCode, Value) {
    request(app, "POST", &format!("/api/workflows/{workflow}/signal/{name}"), body).await
}

async fn received(app: &Router, started: &Value) -> usize {
    run_history(app, &started["workflow_id"], &started["run_id"]).await.as_array().unwrap().iter()
        .filter(|event| event["event_type"] == "SignalReceived").count()
}

fn assert_conflict(response: &(StatusCode, Value), retained: &Value, part: &str) {
    assert_eq!(response.0, StatusCode::CONFLICT, "{}", response.1);
    assert_eq!((response.1["reason"].clone(), response.1["outcome"].clone()),
        (json!("request_id_conflict"), json!("rejected_request_id_conflict")), "{}", response.1);
    assert_eq!(response.1["retained_command_id"], retained["command_id"], "{}", response.1);
    assert_eq!(response.1["conflict"], json!([part]), "{}", response.1);
}

#[tokio::test]
async fn signal_retries_return_the_original_receipt_and_conflicts_write_nothing() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "keyed-worker").await;
    let started = start(&app, "keyed-signals").await;

    let original = signal(&app, "keyed-signals", "finish", input(1, Some("lost-response"))).await;
    assert_eq!(original.0, StatusCode::ACCEPTED, "{}", original.1);
    let retry = signal(&app, "keyed-signals", "finish", input(1, Some("lost-response"))).await;
    assert_eq!(retry.0, StatusCode::ACCEPTED, "{}", retry.1);
    for field in ["command_id", "run_id", "command_sequence", "outcome", "signal_name"] {
        assert_eq!(retry.1[field], original.1[field], "{field}: {}", retry.1);
    }
    assert_eq!((retry.1["retained"].clone(), retry.1["intent_verified"].clone()), (json!(true), json!(true)), "{}", retry.1);
    assert_eq!(received(&app, &started).await, 1);

    // The same key with other arguments or another name is refused unwritten.
    let before = table_counts(&database).await;
    assert_conflict(&signal(&app, "keyed-signals", "finish", input(2, Some("lost-response"))).await, &original.1, "arguments");
    assert_conflict(&signal(&app, "keyed-signals", "other", input(1, Some("lost-response"))).await, &original.1, "name");
    assert_eq!(table_counts(&database).await, before, "a conflict writes nothing");

    // Without a key every signal is its own command, as before.
    for _ in 0..2 {
        assert_eq!(signal(&app, "keyed-signals", "finish", input(1, None)).await.0, StatusCode::ACCEPTED);
    }
    assert_eq!(received(&app, &started).await, 3);

    // A key at the 255-character request_id limit fits: it is stored as a
    // fixed-width token, not in the 191-character column as given.
    let long = "k".repeat(255);
    let first = signal(&app, "keyed-signals", "finish", input(1, Some(&long))).await;
    assert_eq!(first.0, StatusCode::ACCEPTED, "{}", first.1);
    assert_eq!(signal(&app, "keyed-signals", "finish", input(1, Some(&long))).await.1["command_id"], first.1["command_id"]);

    // Concurrent retries admit one signal and share its receipt.
    let racing: Vec<_> = (0..4).map(|_| signal(&app, "keyed-signals", "finish", input(5, Some("racing")))).collect();
    let answers = futures_util::future::join_all(racing).await;
    for answer in &answers {
        assert_eq!(answer.0, StatusCode::ACCEPTED, "{}", answer.1);
        assert_eq!(answer.1["command_id"], answers[0].1["command_id"], "{}", answer.1);
    }
    assert_eq!(received(&app, &started).await, 5);

    // After the run closes, the retry still gets the original receipt instead
    // of a closed-run refusal, and nothing is recorded.
    // Buffered signals reopen the run's task until each has been seen.
    for _ in 0..10 {
        let task = poll(&app, "keyed-worker", "workflow").await;
        if task.is_null() {
            break;
        }
        let (status, body) = finish_task(&app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await;
        assert_eq!(status, StatusCode::OK, "{body}");
    }
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert!(history.as_array().unwrap().iter().any(|event| event["event_type"] == "WorkflowCompleted"), "{history}");
    let before = table_counts(&database).await;
    let closed = signal(&app, "keyed-signals", "finish", input(1, Some("lost-response"))).await;
    assert_eq!((closed.0, closed.1["command_id"].clone()), (StatusCode::ACCEPTED, original.1["command_id"].clone()), "{}", closed.1);
    assert_eq!(table_counts(&database).await, before);
    let fresh = signal(&app, "keyed-signals", "finish", input(1, Some("after-close"))).await;
    assert_eq!((fresh.0, fresh.1["reason"].clone()), (StatusCode::CONFLICT, json!("run_not_active")), "{}", fresh.1);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn keyed_receipts_survive_continue_as_new() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "keyed-worker").await;
    let started = start(&app, "keyed-continued").await;
    let original = signal(&app, "keyed-continued", "finish", input(1, Some("before-continue"))).await;
    assert_eq!(original.0, StatusCode::ACCEPTED, "{}", original.1);
    let task = poll(&app, "keyed-worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"continue_as_new"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let retry = signal(&app, "keyed-continued", "finish", input(1, Some("before-continue"))).await;
    assert_eq!(retry.0, StatusCode::ACCEPTED, "{}", retry.1);
    assert_eq!((retry.1["command_id"].clone(), retry.1["run_id"].clone()),
        (original.1["command_id"].clone(), started["run_id"].clone()), "{}", retry.1);
    assert_conflict(&signal(&app, "keyed-continued", "finish", input(3, Some("before-continue"))).await, &original.1, "arguments");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn update_retries_with_different_arguments_are_conflicts() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "keyed-update-worker").await;
    start(&app, "keyed-update").await;
    open_update_wait(&app, "keyed-update-worker").await;
    let path = "/api/workflows/keyed-update/update/set";
    let body = |value: i64, key: &str| {
        let mut body = input(value, Some(key));
        body["wait_for"] = json!("accepted");
        body
    };
    let original = request(&app, "POST", path, body(1, "update-key")).await;
    assert_eq!(original.0, StatusCode::ACCEPTED, "{}", original.1);
    let retry = request(&app, "POST", path, body(1, "update-key")).await;
    assert_eq!((retry.1["update_id"].clone(), retry.1["command_id"].clone()),
        (original.1["update_id"].clone(), original.1["command_id"].clone()), "{}", retry.1);
    // Before #934 this retry silently got the original receipt.
    let before = table_counts(&database).await;
    assert_conflict(&request(&app, "POST", path, body(2, "update-key")).await, &original.1, "arguments");
    assert_eq!(table_counts(&database).await, before, "a conflict writes nothing");
    // A key longer than the 191-character column, as a token, is admitted.
    let long = "u".repeat(200);
    let admitted = request(&app, "POST", path, body(4, &long)).await;
    assert_eq!(admitted.0, StatusCode::ACCEPTED, "{}", admitted.1);
    assert_eq!(request(&app, "POST", path, body(4, &long)).await.1["update_id"], admitted.1["update_id"]);
    runtime.close().await;
    database.remove().await;
}
