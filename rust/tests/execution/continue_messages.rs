use super::*;

fn kinds(history: &Value) -> Vec<String> {
    history.as_array().unwrap().iter().map(|event| event["event_type"].as_str().unwrap().to_owned()).collect()
}

fn event<'a>(history: &'a Value, kind: &str) -> &'a Value {
    history.as_array().unwrap().iter().find(|event| event["event_type"] == kind).unwrap_or_else(|| panic!("{kind} in {history}"))
}

// Workflow 2.9.0 `ContinuedRunMessageHandoff`: a received signal with a
// declared handler and an accepted update not yet applied move to the
// continued run, in command order, recorded there again. The predecessor
// rejects neither.
#[tokio::test]
async fn continue_as_new_carries_received_signals_and_pending_updates_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    update_worker(&app, "carry-worker").await;
    let started = start(&app, "carry").await;
    let task = poll(&app, "carry-worker", "workflow").await;

    let (status, signal) = request(&app, "POST", "/api/workflows/carry/signal/finish",
        json!({"input":envelope(Payload::Array(vec![Payload::Long(7)]))})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{signal}");
    let (status, update) = request(&app, "POST", "/api/workflows/carry/update/set",
        json!({"input":envelope(Payload::Array(vec![Payload::Long(5)])),"wait_for":"accepted"})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{update}");

    let (status, body) = finish_task(&app, &task, json!([{"type":"continue_as_new"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (_, described) = request(&app, "GET", "/api/workflows/carry", Value::Null).await;
    let next = described["run_id"].clone();
    assert_ne!(next, started["run_id"], "{described}");

    let old = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(kinds(&old).last().map(String::as_str), Some("WorkflowContinuedAsNew"), "{old}");
    assert!(!kinds(&old).iter().any(|kind| kind == "SignalRejected" || kind == "UpdateCompleted"), "{old}");

    let continued = run_history(&app, &started["workflow_id"], &next).await;
    let order: Vec<_> = kinds(&continued).into_iter().filter(|kind| kind != "StartAccepted").collect();
    assert_eq!(order, ["WorkflowStarted", "SignalReceived", "UpdateAccepted"], "{continued}");
    let received = &event(&continued, "SignalReceived")["payload"];
    assert_eq!(received["workflow_command_id"], signal["command_id"], "{received}");
    assert_eq!(received["workflow_run_id"], next);
    assert_eq!(received["signal_wait_id"], format!("signal-command:{}", signal["command_id"].as_str().unwrap()));
    assert_eq!(received["arguments"], envelope(Payload::Array(vec![Payload::Long(7)])));
    let accepted = &event(&continued, "UpdateAccepted")["payload"];
    assert_eq!(accepted["update_id"], update["update_id"], "{accepted}");
    assert_eq!(accepted["workflow_command_id"], update["command_id"]);
    assert_eq!(accepted["workflow_run_id"], next);
    assert!(accepted.get("ordering_state").is_none(), "PHP's handoff records no ordering state");

    // The continued run's first task replays the moved messages, and the
    // next task delivers one of them on the continued run.
    let task = poll(&app, "carry-worker", "workflow").await;
    assert_eq!(task["run_id"], next, "{task}");
    let events: Vec<_> = task["history_events"].as_array().unwrap().iter().map(|event| event["event_type"].clone()).collect();
    assert!(events.contains(&json!("SignalReceived")) && events.contains(&json!("UpdateAccepted")), "{events:?}");
    let (status, body) = finish_task(&app, &task, json!([{"type":"open_signal_wait","signal_name":"finish"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let delivery = poll(&app, "carry-worker", "workflow").await;
    assert_eq!(delivery["run_id"], next, "{delivery}");
    let carried = [&signal["command_id"], &update["command_id"]];
    assert!(carried.contains(&&delivery["workflow_command_id"]), "{delivery}");
}
