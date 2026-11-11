//! Server #962: a run started before its worker registered binds the
//! completing worker's update contract once, at its first accepted
//! workflow task completion.
use super::*;

async fn update(app: &Router, workflow: &str, name: &str, value: i64) -> (StatusCode, Value) {
    request(app, "POST", &format!("/api/workflows/{workflow}/update/{name}"),
        json!({"input":envelope(Payload::Array(vec![Payload::Long(value)]))})).await
}

fn kinds(history: &Value) -> Vec<String> {
    history.as_array().unwrap().iter().map(|event| event["event_type"].as_str().unwrap().to_owned()).collect()
}

#[tokio::test]
async fn an_early_run_binds_its_first_completing_workers_contract_once() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let started = start(&app, "early-update").await;

    // No contract yet: retryable, and nothing is recorded on the run.
    let before = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let (status, pending) = update(&app, "early-update", "set", 5).await;
    assert_eq!(status, StatusCode::CONFLICT, "{pending}");
    assert_eq!(pending["reason"], "contract_pending", "{pending}");
    assert_eq!(pending["retryable"], true, "{pending}");
    assert_eq!(run_history(&app, &started["workflow_id"], &started["run_id"]).await, before);

    update_worker(&app, "update-a").await;
    // A claim alone binds nothing.
    let task = poll(&app, "update-a", "workflow").await;
    assert!(task["task_id"].is_string(), "{task}");
    let (status, still) = update(&app, "early-update", "set", 5).await;
    assert_eq!(status, StatusCode::CONFLICT, "{still}");
    assert_eq!(still["reason"], "contract_pending", "{still}");

    let (status, body) = finish_task(&app, &task, json!([{"type":"open_signal_wait","signal_name":"finish"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (status, admitted) = update(&app, "early-update", "set", 5).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{admitted}");
    // The bound contract's argument rules apply.
    let (status, invalid) = request(&app, "POST", "/api/workflows/early-update/update/set",
        json!({"input":envelope(Payload::Array(vec![Payload::String("five".into())]))})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{invalid}");
    assert_eq!(invalid["reason"], "invalid_update_arguments", "{invalid}");

    // The same worker re-registering another contract, then completing the
    // run's next task, cannot widen the run's updates.
    let (status, body) = request(&app, "POST", "/api/worker/register", json!({
        "worker_id":"update-a","task_queue":"test","runtime":"php","supported_workflow_types":["echo"],
        "supported_activity_types":[],"capabilities":["workflow_updates"],
        "workflow_command_contracts":{"echo":{"signals":["finish"],"updates":["set","other"],
            "update_contracts":[{"name":"set","parameters":[]},{"name":"other","parameters":[]}]}}})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    let task = poll(&app, "update-a", "workflow").await;
    assert!(task["task_id"].is_string(), "{task}");
    let (status, body) = finish_task(&app, &task, json!([{"type":"open_signal_wait","signal_name":"finish"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (status, unknown) = update(&app, "early-update", "other", 1).await;
    assert_eq!(status, StatusCode::NOT_FOUND, "{unknown}");
    assert_eq!(unknown["outcome"], "rejected_unknown_update", "{unknown}");

    // History records updates only; the binding is not an event.
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let kinds = kinds(&history);
    assert!(kinds.iter().all(|kind| !kind.contains("Contract")), "{kinds:?}");
    assert!(kinds.contains(&"UpdateAccepted".to_owned()), "{kinds:?}");
}

#[tokio::test]
async fn a_start_captured_contract_is_never_rebound() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    update_worker(&app, "update-a").await;
    start(&app, "captured-update").await;
    open_update_wait(&app, "update-a").await;
    let (status, admitted) = update(&app, "captured-update", "set", 5).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{admitted}");
}

#[tokio::test]
async fn a_worker_without_a_contract_binds_no_updates() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    start(&app, "plain-update").await;
    register(&app, "plain", json!(["echo"]), json!([])).await;
    let task = poll(&app, "plain", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"open_signal_wait","signal_name":"finish"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    // The pending state ends: the update is unknown, as before binding existed.
    let (status, unknown) = update(&app, "plain-update", "set", 5).await;
    assert_eq!(status, StatusCode::NOT_FOUND, "{unknown}");
    assert_eq!(unknown["outcome"], "rejected_unknown_update", "{unknown}");
}
