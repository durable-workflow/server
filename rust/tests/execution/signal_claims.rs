//! A signal task's claim carries the signal's identity and arguments, as
//! published PHP's `workflowTaskResumeContext` delivers them. The Rust SDK
//! resumes a signal wait with these arguments.
use super::*;

#[tokio::test]
async fn a_signal_claim_carries_the_signal_arguments() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "signal-worker").await;
    start(&app, "claimed-signal").await;
    open_update_wait(&app, "signal-worker").await;
    let input = envelope(Payload::Array(vec![Payload::Long(9007199254740993)]));
    let signal = request(&app, "POST", "/api/workflows/claimed-signal/signal/finish", json!({"input":input})).await;
    assert_eq!(signal.0, StatusCode::ACCEPTED, "{}", signal.1);
    let task = poll(&app, "signal-worker", "workflow").await;
    assert_eq!(task["resume_source_kind"], "workflow_signal", "{task}");
    assert_eq!(task["signal_name"], "finish");
    assert_eq!(task["signal_arguments"], input);
    assert_eq!(task["workflow_command_id"], signal.1["command_id"]);
    assert!(task["workflow_signal_id"].as_str().is_some_and(|id| !id.is_empty()), "{task}");
    runtime.close().await;
    database.remove().await;
}
