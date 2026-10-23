use super::*;

// Native delivers an accepted update only to a worker that advertises
// `workflow_updates` and declares the update for the run's type, as the PHP,
// Python and Rust SDKs do exactly when they register its handler. Published
// PHP 2.11.1 hands the update to any worker of the type, so during a rolling
// deploy a worker without the handler fails it (`UnknownUpdate`). Server #690
// records that defect.
#[tokio::test]
async fn accepted_updates_reach_only_workers_that_declare_them() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let contract = json!([{"name":"u","parameters":[{"name":"value","type":"string","required":true,"variadic":false,
        "default_available":false,"allows_null":false}]}]);
    for (worker, capabilities, updates, contracts) in [
        ("old-worker", json!([]), json!([]), json!([])),
        ("new-worker", json!(["workflow_updates"]), json!(["u"]), contract),
    ] {
        let (status, body) = request(&app, "POST", "/api/worker/register", json!({"worker_id":worker,"task_queue":"test",
            "runtime":"python","supported_workflow_types":["echo"],"supported_activity_types":[],"capabilities":capabilities,
            "workflow_command_contracts":{"echo":{"signals":[],"queries":[],"updates":updates,"update_validators":[],
                "update_contracts":contracts}}})).await;
        assert_eq!(status, StatusCode::CREATED, "{body}");
    }
    let (status, started) = request(&app, "POST", "/api/workflows", json!({"workflow_id":"rolling","workflow_type":"echo",
        "task_queue":"test","input":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    let task = poll(&app, "new-worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"start_timer","delay_seconds":3600}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (status, update) = request(&app, "POST", "/api/workflows/rolling/update/u",
        json!({"input":envelope(Payload::Array(vec![Payload::String("x".into())])),"wait_for":"accepted"})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{update}");
    assert_eq!(update["update_status"], "accepted", "{update}");

    // The worker without the handler is never offered the update; the one
    // that declares it is.
    assert_eq!(poll(&app, "old-worker", "workflow").await, Value::Null);
    let task = poll(&app, "new-worker", "workflow").await;
    assert_eq!(task["workflow_update_id"], update["update_id"], "{task}");
    runtime.close().await;
    database.remove().await;
}
