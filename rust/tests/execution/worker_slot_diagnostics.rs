use super::*;

async fn register(app: &Router, id: &str, workflow_type: &str, fingerprint: &str, slots: Value) {
    let (status, body) = request(app, "POST", "/api/worker/register", json!({
        "worker_id":id,"task_queue":"test","runtime":"php",
        "supported_workflow_types":[workflow_type], "supported_activity_types":["act"],
        "workflow_definition_fingerprints":{(workflow_type):fingerprint},
        "task_slots": slots,
    })).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

async fn heartbeat(app: &Router, id: &str, slots: Value) {
    let (status, body) = request(app, "POST", "/api/worker/heartbeat", json!({
        "worker_id": id, "task_slots": slots,
    })).await;
    assert_eq!(status, StatusCode::OK, "{body}");
}

async fn busy(app: &Router, workflow: &str) -> Vec<Value> {
    let (status, body) = request(app, "GET", &format!("/api/workflows/{workflow}/debug"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body["findings"].as_array().unwrap().iter()
        .filter(|finding| finding["code"] == "eligible_worker_slots_busy")
        .cloned().collect()
}

#[tokio::test]
async fn a_busy_workflow_uses_fresh_slots_from_workers_that_can_take_its_definition() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app,"original","echo","sha256:original",json!({"workflow_available":0})).await;
    start(&app,"waiting-workflow-slots").await;
    register(&app,"different-definition","echo","sha256:other",json!({"workflow_available":8})).await;
    register(&app,"different-type","other","sha256:other",json!({"workflow_available":8})).await;

    let findings = busy(&app,"waiting-workflow-slots").await;
    assert_eq!(findings.len(), 1, "{findings:?}");
    assert_eq!(findings[0]["task_kind"], "workflow");
    assert_eq!(findings[0]["task_type"], "echo");
    assert_eq!(findings[0]["task_queue"], "test");
    assert_eq!(findings[0]["eligible_worker_count"], 1);

    register(&app,"missing","echo","sha256:original",Value::Null).await;
    assert!(busy(&app,"waiting-workflow-slots").await.is_empty());
    heartbeat(&app,"missing",json!({"workflow_available":1})).await;
    assert!(busy(&app,"waiting-workflow-slots").await.is_empty());
    heartbeat(&app,"missing",json!({"workflow_available":0})).await;
    heartbeat(&app,"missing",json!({"activity_available":7})).await;
    assert_eq!(busy(&app,"waiting-workflow-slots").await[0]["eligible_worker_count"], 2);
    let (_, worker) = request(&app,"GET","/api/workers/missing",Value::Null).await;
    assert_eq!(worker["task_slots"]["workflow_available"], 0);
    assert_eq!(worker["process_metrics"], json!({"_worker_protocol_version":"1.19"}));
    heartbeat(&app,"missing",json!({"workflow_available":null})).await;
    let rounded = (chrono::Utc::now() + chrono::Duration::seconds(2)).format("%Y-%m-%d %H:%M:%S");
    database.execute(sqlx::AssertSqlSafe(format!(
        "UPDATE workflow_worker_registrations SET last_heartbeat_at='{rounded}' WHERE worker_id='missing'"
    ))).await;
    assert!(busy(&app,"waiting-workflow-slots").await.is_empty());
    assert_eq!(poll(&app,"original","workflow").await["workflow_id"], "waiting-workflow-slots");

    drop(app);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn busy_activity_slots_require_due_ready_activity_work() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app,"worker","echo","sha256:original",json!({"workflow_available":1,"activity_available":0})).await;
    start(&app,"waiting-activity-slots").await;
    let task = poll(&app,"worker","workflow").await;
    let (status, result) = finish_task(&app,&task,json!([{
        "type":"schedule_activity", "activity_type":"act", "arguments":envelope(Payload::Array(vec![])),
    }])).await;
    assert_eq!(status, StatusCode::OK, "{result}");

    let findings = busy(&app,"waiting-activity-slots").await;
    assert_eq!(findings.len(), 1, "{findings:?}");
    assert_eq!(findings[0]["task_kind"], "activity");
    assert_eq!(findings[0]["task_type"], "act");
    database.set_availability("activity",Some(chrono::Utc::now() + chrono::Duration::hours(1))).await;
    assert!(busy(&app,"waiting-activity-slots").await.is_empty());
    database.set_availability("activity",None).await;
    assert_eq!(busy(&app,"waiting-activity-slots").await.len(), 1);
    let task = poll(&app,"worker","activity").await;
    assert_eq!(task["workflow_id"], "waiting-activity-slots");
    assert!(busy(&app,"waiting-activity-slots").await.is_empty());

    drop(app);
    runtime.close().await;
    database.remove().await;
}
