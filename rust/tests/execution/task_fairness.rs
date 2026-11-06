use super::*;

// Published PHP tries a poll's candidates in `TaskFairnessScheduler` order
// within each batch: fairness classes take turns, by weight. When the first
// candidate cannot be claimed, the next one comes from another class rather
// than the next ready task in time.

async fn start_keyed(app: &Router, id: &str, key: &str) {
    let (status, body) = request(app, "POST", "/api/workflows", json!({"workflow_id":id,"workflow_type":"echo",
        "task_queue":"test","input":envelope(Payload::Array(vec![])),"fairness_key":key})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    // Distinct availability, so the candidates' time order is fixed.
    tokio::time::sleep(std::time::Duration::from_millis(20)).await;
}

#[tokio::test]
async fn a_skipped_candidate_is_followed_by_another_fairness_class() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (status, body) = request(&app, "POST", "/api/search-attributes", json!({"name":"Tenant","type":"keyword"})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    for (worker, capabilities) in [("capable", json!(["typed_search_attributes"])), ("plain", json!([]))] {
        let (status, body) = request(&app, "POST", "/api/worker/register", json!({"worker_id":worker,"task_queue":"test",
            "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":[],"capabilities":capabilities})).await;
        assert_eq!(status, StatusCode::CREATED, "{body}");
    }
    // A1 records typed search attributes, which a worker without that
    // capability cannot replay, then waits for a signal that makes it ready.
    start_keyed(&app, "a1", "a").await;
    let task = poll(&app, "capable", "workflow").await;
    assert_eq!(task["workflow_id"], "a1", "{task}");
    let (status, body) = finish_task(&app, &task, json!([
        {"type":"upsert_search_attributes","attributes":{"Tenant":"a"},"attribute_types":{"Tenant":"keyword"}},
        {"type":"open_signal_wait","signal_name":"go"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (status, body) = request(&app, "POST", "/api/workflows/a1/signal/go", json!({"input":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    tokio::time::sleep(std::time::Duration::from_millis(20)).await;
    start_keyed(&app, "a2", "a").await;
    start_keyed(&app, "b1", "b").await;
    // Candidates in time order: a1 (class a, skipped), a2 (class a), b1
    // (class b). In fairness order: a1, b1, a2.
    let first = poll(&app, "plain", "workflow").await;
    assert_eq!(first["workflow_id"], "b1", "{first}");
    let second = poll(&app, "plain", "workflow").await;
    assert_eq!(second["workflow_id"], "a2", "{second}");
    assert!(poll(&app, "plain", "workflow").await.is_null());
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_poll_claims_past_a_full_batch_of_unsupported_activities() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "worker", json!(["echo"]), json!([])).await;
    register(&app, "picky", json!([]), json!(["wanted"])).await;
    for (index, activity) in ["other"; 10].into_iter().chain(["wanted"]).enumerate() {
        start(&app, &format!("batch-activity-{index}")).await;
        let task = poll(&app, "worker", "workflow").await;
        let (status, body) = finish_task(&app, &task, json!([{"type":"schedule_activity","activity_type":activity,
            "arguments":envelope(Payload::Array(vec![]))}])).await;
        assert_eq!(status, StatusCode::OK, "{body}");
        tokio::time::sleep(std::time::Duration::from_millis(20)).await;
    }
    let task = poll(&app, "picky", "activity").await;
    assert_eq!(task["activity_type"], "wanted", "{task}");
    assert!(poll(&app, "picky", "activity").await.is_null());
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn unsupported_workflow_types_do_not_shorten_the_deciding_fairness_batch() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (status, body) = request(&app, "POST", "/api/search-attributes", json!({"name":"Tenant","type":"keyword"})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    for (worker, capabilities) in [("capable", json!(["typed_search_attributes"])), ("plain", json!([]))] {
        let (status, body) = request(&app, "POST", "/api/worker/register", json!({"worker_id":worker,"task_queue":"test",
            "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":[],"capabilities":capabilities})).await;
        assert_eq!(status, StatusCode::CREATED, "{body}");
    }
    start_keyed(&app, "batch-a1", "a").await;
    let task = poll(&app, "capable", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([
        {"type":"upsert_search_attributes","attributes":{"Tenant":"a"},"attribute_types":{"Tenant":"keyword"}},
        {"type":"open_signal_wait","signal_name":"go"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (status, body) = request(&app, "POST", "/api/workflows/batch-a1/signal/go", json!({"input":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    tokio::time::sleep(std::time::Duration::from_millis(20)).await;
    start_keyed(&app, "batch-a2", "a").await;
    for index in 0..8 {
        let (status, body) = request(&app, "POST", "/api/workflows", json!({"workflow_id":format!("unsupported-{index}"),
            "workflow_type":"other","task_queue":"test","input":envelope(Payload::Array(vec![]))})).await;
        assert_eq!(status, StatusCode::CREATED, "{body}");
        tokio::time::sleep(std::time::Duration::from_millis(20)).await;
    }
    start_keyed(&app, "batch-b1", "b").await;
    // The raw first ten contain only a1/a2 of this worker's type. The full
    // eligible batch orders a1,b1,a2; plain skips typed a1 and must take b1.
    assert_eq!(poll(&app, "plain", "workflow").await["workflow_id"], "batch-b1");
    assert_eq!(poll(&app, "plain", "workflow").await["workflow_id"], "batch-a2");
    assert!(poll(&app, "plain", "workflow").await.is_null());
    runtime.close().await;
    database.remove().await;
}
