use super::*;

// Workflow 2.9's `normalizeOpenConditionWaitCommand`: a condition wait's key
// and definition fingerprint are optional strings of any form. PHP trims them
// and leaves them out when empty.

fn opened(history: &Value) -> Value {
    history.as_array().unwrap().iter().rev().find(|event| event["event_type"] == "ConditionWaitOpened")
        .map(|event| event["payload"].clone()).unwrap()
}

#[tokio::test]
async fn condition_waits_need_neither_a_key_nor_a_fingerprint() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let started = start(&app, "keyless-condition").await;
    register(&app, "keyless", json!(["echo"]), json!([])).await;
    let task = poll(&app, "keyless", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"open_condition_wait"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let payload = opened(&history);
    assert!(payload.get("condition_key").is_none() && payload.get("condition_definition_fingerprint").is_none(), "{payload}");
    assert_eq!(task_retry::rows(&database,
        "SELECT COUNT(*) FROM workflow_run_summaries WHERE wait_kind='condition' AND wait_reason='Waiting for condition'").await, 1);
    assert_eq!(task_retry::rows(&database,
        "SELECT COUNT(*) FROM workflow_run_waits WHERE kind='condition' AND status='open' AND target_name IS NULL").await, 1);

    // A signal wakes the wait; the next one keeps a trimmed key and any
    // fingerprint, and drops a blank one.
    let (status, body) = request(&app, "POST", "/api/workflows/keyless-condition/signal/go", json!([])).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    let task = poll(&app, "keyless", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"open_condition_wait","condition_key":"  ready  ",
        "condition_definition_fingerprint":"any form"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let payload = opened(&run_history(&app, &started["workflow_id"], &started["run_id"]).await);
    assert_eq!((payload["condition_key"].clone(), payload["condition_definition_fingerprint"].clone()),
        (json!("ready"), json!("any form")), "{payload}");
    assert_eq!(task_retry::rows(&database,
        "SELECT COUNT(*) FROM workflow_run_summaries WHERE wait_reason='Waiting for condition ready'").await, 1);

    let (status, body) = request(&app, "POST", "/api/workflows/keyless-condition/signal/again", json!([])).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    let task = poll(&app, "keyless", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"open_condition_wait","condition_key":"   ",
        "condition_definition_fingerprint":"","timeout_seconds":60}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let payload = opened(&history);
    assert!(payload.get("condition_key").is_none() && payload.get("condition_definition_fingerprint").is_none(), "{payload}");
    let scheduled = history.as_array().unwrap().iter().rev().find(|event| event["event_type"] == "TimerScheduled").unwrap();
    assert!(scheduled["payload"].get("condition_key").is_none(), "{scheduled}");
    assert_eq!(task_retry::rows(&database,
        "SELECT COUNT(*) FROM workflow_run_summaries WHERE wait_reason='Waiting for condition or timeout'").await, 1);

    // A key that is not a string is still refused.
    let (status, body) = request(&app, "POST", "/api/workflows/keyless-condition/signal/third", json!([])).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    let task = poll(&app, "keyless", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"open_condition_wait","condition_key":7}])).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
}
