use super::*;

// Published PHP (Workflow 2.9.0 PendingSignalCloser) rejects each signal a
// run received but never applied once the run closes, in arrival order. The
// signal command keeps its acceptance; SignalRejected records the outcome.
#[tokio::test]
async fn closing_a_run_rejects_its_unapplied_signals() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!([])).await;
    let started = start(&app, "rejected-signals").await;
    let mut commands = Vec::new();
    for name in ["first", "second"] {
        let (status, signal) = request(&app, "POST", &format!("/api/workflows/rejected-signals/signal/{name}"),
            json!({"input": [name]})).await;
        assert_eq!(status, StatusCode::ACCEPTED, "{signal}");
        commands.push(signal["command_id"].clone());
    }
    let (status, terminated) = request(&app, "POST", "/api/workflows/rejected-signals/terminate", json!({})).await;
    assert_eq!(status, StatusCode::OK, "{terminated}");

    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let events = history.as_array().unwrap();
    let kinds: Vec<_> = events.iter().map(|event| event["event_type"].as_str().unwrap()).collect();
    let terminal = kinds.iter().position(|kind| *kind == "WorkflowTerminated").unwrap_or_else(|| panic!("{kinds:?}"));
    assert_eq!(&kinds[terminal + 1..], ["SignalRejected", "SignalRejected"], "{kinds:?}");
    for (event, (name, command)) in events[terminal + 1..].iter().zip(["first", "second"].iter().zip(&commands)) {
        let payload = &event["payload"];
        assert_eq!(payload["signal_name"], *name, "{payload}");
        assert_eq!(payload["workflow_command_id"], *command, "{payload}");
        assert_eq!(payload["rejection_reason"], "run_closed_before_signal_applied");
        assert_eq!(payload["terminal_reason"], "terminated");
        assert!(payload["signal_id"].is_string() && payload["command_sequence"].is_i64(), "{payload}");
        // PHP records it with the command id, not the command: no snapshot.
        // The API terminate has no task, so neither has the rejection.
        assert!(payload.get("command").is_none() && payload.get("task").is_none(), "{payload}");
        assert_eq!(event["principal"], Value::Null, "{event}");
    }

    // The export shows each signal rejected while its command stays accepted.
    let run = started["run_id"].as_str().unwrap();
    let (status, export) = request(&app, "GET", &format!("/api/workflows/rejected-signals/runs/{run}/history/export"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{export}");
    for signal in export["signals"].as_array().unwrap() {
        assert_eq!((signal["status"].clone(), signal["outcome"].clone()), (json!("rejected"), json!("rejected_not_active")), "{signal}");
        assert_eq!(signal["rejection_reason"], "run_closed_before_signal_applied");
    }
    assert_eq!(export["signals"].as_array().unwrap().len(), 2, "{export}");
}

// A worker's completion records its task, and the rejection that follows
// repeats that task snapshot, as PHP records both with the same task.
#[tokio::test]
async fn a_completion_s_rejections_repeat_its_task_snapshot() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!([])).await;
    let started = start(&app, "completed-signals").await;
    let task = poll(&app, "worker", "workflow").await;
    let (status, signal) = request(&app, "POST", "/api/workflows/completed-signals/signal/late", json!({"input": []})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{signal}");
    let complete = json!({"type": "complete_workflow", "result": envelope(Payload::Long(1))});
    assert_eq!(finish_task(&app, &task, json!([complete])).await.0, StatusCode::OK);

    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let events = history.as_array().unwrap();
    let kinds: Vec<_> = events.iter().map(|event| event["event_type"].as_str().unwrap()).collect();
    assert_eq!(&kinds[kinds.len() - 2..], ["WorkflowCompleted", "SignalRejected"], "{kinds:?}");
    let (completed, rejected) = (&events[events.len() - 2], &events[events.len() - 1]);
    assert!(completed["payload"]["task"].is_object(), "{completed}");
    assert_eq!(rejected["payload"]["task"], completed["payload"]["task"]);
    assert_eq!(rejected["payload"]["terminal_reason"], "completed");
    assert!(rejected["payload"].get("command").is_none(), "{rejected}");
}
