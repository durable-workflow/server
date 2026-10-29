use super::*;

// Published Server 2.11.5 bounds only pending updates
// (DW_V2_LIMIT_PENDING_UPDATES), not how many a run takes over its life, and
// describes a run by its open waits. The many-updates fixture records a run
// waiting for a signal through 65 completed updates, then a 66th left
// accepted.

async fn describe(app: &Router) -> (Value, Value) {
    let (_, described) = request(app, "GET", "/api/workflows/many-updates", Value::Null).await;
    (described["wait_kind"].clone(), described["wait_reason"].clone())
}

#[tokio::test]
async fn a_run_is_described_by_its_waits_after_more_than_64_updates() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "update-worker").await;
    start(&app, "many-updates").await;
    open_update_wait(&app, "update-worker").await;
    let path = "/api/workflows/many-updates/update/set";
    for index in 0..65 {
        let (status, beat) = request(&app, "POST", "/api/worker/heartbeat", json!({"worker_id":"update-worker"})).await;
        assert_eq!(status, StatusCode::OK, "{beat}");
        let receipt = request(&app, "POST", path, json!({"input":envelope(Payload::Array(vec![Payload::Long(index)])),
            "request_id":format!("many:{index}"),"wait_for":"accepted"})).await;
        assert_eq!(receipt.0, StatusCode::ACCEPTED, "{}", receipt.1);
        let task = poll(&app, "update-worker", "workflow").await;
        assert_eq!(task["workflow_update_id"], receipt.1["update_id"]);
        let result = request(&app, "POST", &format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap()),
            completion(&task, json!([{"type":"complete_update","update_id":receipt.1["update_id"],
                "result":envelope(Payload::Long(index))}]))).await;
        assert_eq!(result.0, StatusCode::OK, "{}", result.1);
    }
    assert_eq!(describe(&app).await, (json!("signal"), json!("Waiting for signal finish")));
    let pending = request(&app, "POST", path, json!({"input":envelope(Payload::Array(vec![Payload::Long(65)])),
        "request_id":"many:65","wait_for":"accepted"})).await;
    assert_eq!(pending.0, StatusCode::ACCEPTED, "{}", pending.1);
    assert_eq!(describe(&app).await, (json!("update"), json!("Waiting for update set")));
    runtime.close().await;
    database.remove().await;
}
