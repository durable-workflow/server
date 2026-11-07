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

async fn register_batch_workers(app: &Router) {
    let (status, body) = request(app, "POST", "/api/search-attributes", json!({"name":"Tenant","type":"keyword"})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    for (worker, types, capabilities) in [
        ("capable", json!(["echo","other"]), json!(["typed_search_attributes"])),
        ("plain", json!(["echo"]), json!([])),
    ] {
        let (status, body) = request(app, "POST", "/api/worker/register", json!({"worker_id":worker,"task_queue":"test",
            "runtime":"php","supported_workflow_types":types,"supported_activity_types":[],"capabilities":capabilities})).await;
        assert_eq!(status, StatusCode::CREATED, "{body}");
    }
}

async fn park_typed_candidate(app: &Router, workflow_id: &str) {
    start_keyed(app,workflow_id,"a").await;
    let task = poll(app,"capable","workflow").await;
    assert_eq!(task["workflow_id"],workflow_id,"{task}");
    let (status, body) = finish_task(app,&task,json!([
        {"type":"upsert_search_attributes","attributes":{"Tenant":"a"},"attribute_types":{"Tenant":"keyword"}},
        {"type":"open_signal_wait","signal_name":"go"}
    ])).await;
    assert_eq!(status,StatusCode::OK,"{body}");
}

async fn ready_typed_candidate(app: &Router, workflow_id: &str) {
    let (status, body) = request(app,"POST",&format!("/api/workflows/{workflow_id}/signal/go"),
        json!({"input":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(status,StatusCode::ACCEPTED,"{body}");
    tokio::time::sleep(std::time::Duration::from_millis(20)).await;
}

#[tokio::test]
async fn a_poll_claims_past_a_whole_unclaimable_fairness_batch() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register_batch_workers(&app).await;
    for index in 0..10 { park_typed_candidate(&app,&format!("blocked-{index}")).await; }
    for index in 0..10 { ready_typed_candidate(&app,&format!("blocked-{index}")).await; }
    start_keyed(&app,"after-blocked","b").await;
    let task = poll(&app,"plain","workflow").await;
    assert_eq!(task["workflow_id"],"after-blocked","{task}");
    assert!(poll(&app,"plain","workflow").await.is_null());
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn unsupported_types_are_removed_before_deciding_the_first_fairness_batch() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register_batch_workers(&app).await;
    park_typed_candidate(&app,"a1").await;
    ready_typed_candidate(&app,"a1").await;
    let (status, body) = request(&app,"POST","/api/workflows",json!({"workflow_id":"unsupported",
        "workflow_type":"other","task_queue":"test","input":envelope(Payload::Array(vec![])),"fairness_key":"ignored"})).await;
    assert_eq!(status,StatusCode::CREATED,"{body}");
    tokio::time::sleep(std::time::Duration::from_millis(20)).await;
    for index in 2..=9 { start_keyed(&app,&format!("a{index}"),"a").await; }
    start_keyed(&app,"b1","b").await;
    // The first ten raw rows contain only nine supported types. Reordering
    // that incomplete prefix would incorrectly pick a2 after blocked a1.
    // The full eligible batch includes b1, which fairness tries before a2.
    let task = poll(&app,"plain","workflow").await;
    assert_eq!(task["workflow_id"],"b1","{task}");
    assert_eq!(poll(&app,"plain","workflow").await["workflow_id"],"a2");
    runtime.close().await;
    database.remove().await;
}
