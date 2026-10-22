//! The value a `SignalApplied` event records, as published PHP's
//! `signalValueFromRecord` derives it from the signal's arguments.
use super::*;

#[tokio::test]
async fn applied_signal_values_follow_php() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (status, body) = request(&app, "POST", "/api/worker/register", json!({
        "worker_id":"signal-worker","task_queue":"test","runtime":"php","supported_workflow_types":["echo"],
        "supported_activity_types":[],"workflow_command_contracts":{"echo":{"signals":["go"]}}})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    let started = start(&app, "applied-values").await;
    let mut task = poll(&app, "signal-worker", "workflow").await;
    let cases = [
        (vec![], Payload::Boolean(true)),
        (vec![Payload::Long(7)], Payload::Long(7)),
        (vec![Payload::Long(1), Payload::Long(2)], Payload::Array(vec![Payload::Long(1), Payload::Long(2)])),
    ];
    for (arguments, _) in &cases {
        assert_eq!(finish_task(&app, &task, json!([{"type":"open_signal_wait","signal_name":"go"}])).await.0, StatusCode::OK);
        let signal = request(&app, "POST", "/api/workflows/applied-values/signal/go",
            json!({"input":envelope(Payload::Array(arguments.clone()))})).await;
        assert_eq!(signal.0, StatusCode::ACCEPTED, "{}", signal.1);
        task = poll(&app, "signal-worker", "workflow").await;
        assert!(!task.is_null());
    }
    assert_eq!(finish_task(&app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Long(0))}])).await.0, StatusCode::OK);
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let applied: Vec<Value> = history.as_array().unwrap().iter()
        .filter(|event| event["event_type"] == "SignalApplied")
        .map(|event| event["payload"]["value"].clone())
        .collect();
    let expected: Vec<Value> = cases.iter().map(|(_, value)| envelope(value.clone())).collect();
    assert_eq!(applied, expected, "{history}");
    runtime.close().await;
    database.remove().await;
}
