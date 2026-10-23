use super::*;

// Server 2.11.1 `WorkflowTaskPoller::historyEventsWithSignalArgumentEnvelopes`
// and `WorkflowHistoryEvent::taskSnapshot`: the history a worker reads shows a
// consumed signal's workflow sequence, and task snapshots keep their dispatch
// stamps.

fn wait(key: &str) -> Value {
    json!([{"type":"open_condition_wait","condition_key":key,
        "condition_definition_fingerprint":format!("sha256:{}", "b".repeat(64))}])
}

fn received(history: &Value) -> Vec<Value> {
    history.as_array().unwrap().iter().filter(|event| event["event_type"] == "SignalReceived")
        .map(|event| event["payload"].clone()).collect()
}

#[tokio::test]
async fn a_worker_reads_the_sequence_that_consumed_a_signal() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    // A run started before any worker declared a contract admits any signal.
    let started = start(&app, "presented-signals").await;
    let worker = json!({"worker_id":"presenter","task_queue":"test","runtime":"php","supported_workflow_types":["echo"],
        "supported_activity_types":[]});
    assert_eq!(request(&app, "POST", "/api/worker/register", worker).await.0, StatusCode::CREATED);
    let task = poll(&app, "presenter", "workflow").await;
    assert_eq!(finish_task(&app, &task, wait("first")).await.0, StatusCode::OK);
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let opened = history.as_array().unwrap().iter().find(|event| event["event_type"] == "ConditionWaitOpened").unwrap();
    let sequence = opened["payload"]["sequence"].clone();

    let (status, body) = request(&app, "POST", "/api/workflows/presented-signals/signal/go", json!([])).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    let task = poll(&app, "presenter", "workflow").await;
    assert!(received(&task["history_events"])[0].get("workflow_sequence").is_none(), "not consumed yet: {task}");
    assert_eq!(finish_task(&app, &task, wait("second")).await.0, StatusCode::OK);

    let (status, body) = request(&app, "POST", "/api/workflows/presented-signals/signal/again", json!([])).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    let task = poll(&app, "presenter", "workflow").await;
    let signals = received(&task["history_events"]);
    assert_eq!(signals[0]["workflow_sequence"], sequence, "the consuming wait's sequence: {signals:?}");
    assert!(signals[1].get("workflow_sequence").is_none(), "{signals:?}");
    // A history page reads the same.
    let (status, page) = request(&app, "POST", &format!("/api/worker/workflow-tasks/{}/history", task["task_id"].as_str().unwrap()),
        json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"]})).await;
    assert_eq!(status, StatusCode::OK, "{page}");
    assert_eq!(received(&page["history_events"])[0]["workflow_sequence"], sequence, "{page}");
    // The run's own history shows the event as recorded.
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert!(received(&history)[0].get("workflow_sequence").is_none(), "{history}");

    // The completing task was dispatched when the signal created it, and its
    // snapshot keeps the dispatch stamps.
    let done = json!([{"type":"complete_workflow","result":envelope(Payload::Null)}]);
    assert_eq!(finish_task(&app, &task, done).await.0, StatusCode::OK);
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let completed = history.as_array().unwrap().iter().find(|event| event["event_type"] == "WorkflowCompleted").unwrap();
    for stamp in ["last_dispatch_attempt_at", "last_dispatched_at"] {
        assert!(completed["payload"]["task"][stamp].is_string(), "{stamp}: {completed}");
    }
    assert!(completed["payload"]["task"].get("last_claim_error").is_none(), "unset fields stay out: {completed}");
}
