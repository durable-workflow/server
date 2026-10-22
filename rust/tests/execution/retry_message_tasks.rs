//! A failed workflow task that carries an update or a signal is retried as
//! published PHP retries it: a new ready task with the same payload.
use super::*;

async fn fail_task(app: &Router, task: &Value, message: &str) -> (StatusCode, Value) {
    request(app, "POST",
        &format!("/api/worker/workflow-tasks/{}/fail", task["task_id"].as_str().unwrap()),
        json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],
            "failure":{"message":message,"type":"RuntimeException"}})).await
}

#[tokio::test]
async fn a_failed_update_task_is_retried_with_its_update() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "update-worker").await;
    let started = start(&app, "retried-update").await;
    open_update_wait(&app, "update-worker").await;
    let receipt = request(&app, "POST", "/api/workflows/retried-update/update/set",
        json!({"input":envelope(Payload::Array(vec![Payload::Long(7)])),"wait_for":"accepted"})).await;
    assert_eq!(receipt.0, StatusCode::ACCEPTED, "{}", receipt.1);
    let task = poll(&app, "update-worker", "workflow").await;
    assert_eq!(task["workflow_update_id"], receipt.1["update_id"]);
    let failed = fail_task(&app, &task, "handler threw").await;
    assert_eq!(failed.0, StatusCode::OK, "{}", failed.1);
    assert_eq!(failed.1["recorded"], true);
    let retry_id = failed.1["next_task_id"].clone();
    assert!(retry_id.is_string(), "{}", failed.1);
    // The retry carries the same update, and the worker can complete it.
    let retry = poll(&app, "update-worker", "workflow").await;
    assert_eq!(retry["task_id"], retry_id);
    assert_eq!(retry["workflow_update_id"], receipt.1["update_id"]);
    let completed = request(&app, "POST",
        &format!("/api/worker/workflow-tasks/{}/complete", retry["task_id"].as_str().unwrap()),
        completion(&retry, json!([{"type":"complete_update","update_id":receipt.1["update_id"],
            "result":envelope(Payload::Long(7))}]))).await;
    assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let completions = history.as_array().unwrap().iter()
        .filter(|event| event["event_type"] == "UpdateCompleted").count();
    assert_eq!(completions, 1, "{history}");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_failed_signal_task_is_retried_with_its_signal() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "update-worker").await;
    let started = start(&app, "retried-signal").await;
    open_update_wait(&app, "update-worker").await;
    let signal = request(&app, "POST", "/api/workflows/retried-signal/signal/finish",
        json!({"input":envelope(Payload::Array(vec![Payload::Long(1)]))})).await;
    assert_eq!(signal.0, StatusCode::ACCEPTED, "{}", signal.1);
    let task = poll(&app, "update-worker", "workflow").await;
    assert_eq!(task["resume_source_kind"], "workflow_signal", "{task}");
    let failed = fail_task(&app, &task, "signal handler failed").await;
    assert_eq!(failed.0, StatusCode::OK, "{}", failed.1);
    let retry = poll(&app, "update-worker", "workflow").await;
    assert_eq!(retry["task_id"], failed.1["next_task_id"]);
    assert_eq!(retry["resume_source_kind"], "workflow_signal");
    assert_eq!(retry["signal_wait_id"], task["signal_wait_id"]);
    let completed = request(&app, "POST",
        &format!("/api/worker/workflow-tasks/{}/complete", retry["task_id"].as_str().unwrap()),
        completion(&retry, json!([{"type":"complete_workflow","result":envelope(Payload::Long(1))}]))).await;
    assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
    let run = request(&app, "GET", "/api/workflows/retried-signal", Value::Null).await;
    assert_eq!(run.1["status"], "completed", "{}", run.1);
    let _ = started;
    runtime.close().await;
    database.remove().await;
}
