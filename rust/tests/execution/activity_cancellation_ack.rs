//! An activity worker's stop acknowledgement after cooperative delivery.
//! Native cancels a retried activity's live attempt and task, so its worker
//! can acknowledge the stop; published Server 2.11.1 delivers to the
//! execution's first task instead (Workflow #923), which the shared fixture
//! records as a documented reference boundary.
use super::*;

async fn acknowledge(app: &Router, task: &Value, attempt: &Value, owner: &Value, request_id: &Value) -> (StatusCode, Value) {
    request_protocol(app, "POST",
        &format!("/api/worker/activity-tasks/{}/acknowledge-cancellation", task.as_str().unwrap()),
        json!({"activity_attempt_id":attempt,"lease_owner":owner,"request_id":request_id}), "1.20").await
}

/// Whether a task row has this status.
async fn task_has_status(database: &TestDatabase, task: &Value, status: &str) -> bool {
    let sql = format!("SELECT COUNT(*) FROM workflow_tasks WHERE id='{}' AND status='{status}'", task.as_str().unwrap());
    task_retry::rows(database, Box::leak(sql.into_boxed_str())).await == 1
}

#[tokio::test]
async fn a_retried_activity_acknowledges_on_its_live_task() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let registered = request_protocol(&app, "POST", "/api/worker/register",
        json!({"worker_id":"cleanup-worker","task_queue":"test","runtime":"php",
            "supported_workflow_types":["echo"],"supported_activity_types":["stop-activity"],
            "capabilities":["cooperative_cancellation"]}), "1.20").await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    let started = start(&app, "retried-ack").await;
    let task = cooperative_poll(&app, "workflow").await;
    let scheduled = finish_task(&app, &task, json!([{"type":"schedule_activity","activity_type":"stop-activity",
        "arguments":envelope(Payload::Array(vec![])),"retry_policy":{"max_attempts":2,"backoff_seconds":[0]},
        "start_to_close_timeout":300}])).await;
    assert_eq!(scheduled.0, StatusCode::OK, "{}", scheduled.1);
    let first = cooperative_poll(&app, "activity").await;
    let failed = request_protocol(&app, "POST",
        &format!("/api/worker/activity-tasks/{}/fail", first["task_id"].as_str().unwrap()),
        json!({"activity_attempt_id":first["activity_attempt_id"],"lease_owner":first["lease_owner"],
            "failure":{"message":"retry me","type":"parity.retryable","non_retryable":false}}), "1.20").await;
    assert_eq!(failed.0, StatusCode::OK, "{}", failed.1);
    let second = cooperative_poll(&app, "activity").await;
    assert_ne!(second["task_id"], first["task_id"], "the retry has its own task");
    let requested = request(&app, "POST", "/api/workflows/retried-ack/request-cancellation",
        json!({"reason":"stop the retry","cleanup_timeout_seconds":60})).await;
    assert_eq!(requested.0, StatusCode::ACCEPTED, "{}", requested.1);
    let request_id = requested.1["cancellation_request"]["request_id"].clone();
    let resumed = cooperative_poll(&app, "workflow").await;
    let delivered = request_protocol(&app, "POST",
        &format!("/api/worker/workflow-tasks/{}/deliver-cancellation", resumed["task_id"].as_str().unwrap()),
        json!({"lease_owner":resumed["lease_owner"],"workflow_task_attempt":resumed["workflow_task_attempt"],
            "request_id":request_id,"sequence":1,"call_kind":"activity"}), "1.20").await;
    assert_eq!((delivered.0, delivered.1["delivered"].clone()), (StatusCode::OK, json!(true)), "{}", delivered.1);

    // The live task is cancelled and the earlier one keeps its outcome.
    assert!(task_has_status(&database, &second["task_id"], "cancelled").await, "live task cancelled");
    assert!(task_has_status(&database, &first["task_id"], "completed").await, "earlier task keeps its outcome");
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let cancelled = history.as_array().unwrap().iter().find(|event| event["event_type"] == "ActivityCancelled").unwrap();
    assert_eq!(cancelled["payload"]["task"]["id"], second["task_id"], "{cancelled}");
    assert_eq!(cancelled["payload"]["activity_attempt"]["id"], second["activity_attempt_id"], "{cancelled}");

    // The earlier attempt is fenced; the live attempt acknowledges once.
    let stale = acknowledge(&app, &first["task_id"], &first["activity_attempt_id"], &first["lease_owner"], &request_id).await;
    assert_eq!((stale.0, stale.1["reason"].clone()),
        (StatusCode::CONFLICT, json!("activity_cancellation_acknowledgement_fence_mismatch")), "{}", stale.1);
    let acknowledged = acknowledge(&app, &second["task_id"], &second["activity_attempt_id"], &second["lease_owner"], &request_id).await;
    assert_eq!((acknowledged.0, acknowledged.1["acknowledged"].clone(), acknowledged.1["duplicate"].clone()),
        (StatusCode::OK, json!(true), json!(false)), "{}", acknowledged.1);
    let repeated = acknowledge(&app, &second["task_id"], &second["activity_attempt_id"], &second["lease_owner"], &request_id).await;
    assert_eq!((repeated.0, repeated.1["duplicate"].clone(), repeated.1["history_event_id"].clone()),
        (StatusCode::OK, json!(true), acknowledged.1["history_event_id"].clone()), "{}", repeated.1);
    runtime.close().await;
    database.remove().await;
}
