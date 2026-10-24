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

/// The bridge's refused delivery (`DefaultWorkflowTaskBridge::deliverCancellation`
/// over `CooperativeCancellationDelivery::validate`), as published PHP answers
/// it under 409, without the capability map.
pub(super) fn refused_delivery(task: &Value, run_id: &Value, reason: &str) -> Value {
    json!({"delivered":false,"task_id":task["task_id"],"workflow_run_id":run_id,"request_id":null,"sequence":null,
        "call_kind":null,"sequence_span":null,"operation_sequence":null,"operation_sequence_span":null,
        "reason":reason,"protocol_version":"1.20"})
}

pub(super) async fn deliver_at(app: &Router, task: &Value, request_id: &Value, sequence: i64, kind: &str) -> (StatusCode, Value) {
    let (status, mut body) = request_protocol(app, "POST",
        &format!("/api/worker/workflow-tasks/{}/deliver-cancellation", task["task_id"].as_str().unwrap()),
        json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],
            "request_id":request_id,"sequence":sequence,"call_kind":kind}), "1.20").await;
    let capabilities = body.as_object_mut().and_then(|body| body.remove("server_capabilities"));
    assert!(capabilities.is_some_and(|map| map.is_object()), "every delivery answer carries the worker envelope: {body}");
    (status, body)
}

/// A run whose activity at sequence 1 completed before the request and whose
/// timer at sequence 2 is still pending, with the request's workflow task
/// claimed. Returns the run, the claimed task and the request id.
async fn resolved_activity_then_timer(app: &Router, workflow: &str) -> (Value, Value, Value) {
    let registered = request_protocol(app, "POST", "/api/worker/register",
        json!({"worker_id":"cleanup-worker","task_queue":"test","runtime":"php",
            "supported_workflow_types":["echo"],"supported_activity_types":["stop-activity"],
            "capabilities":["cooperative_cancellation"]}), "1.20").await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    let started = start(app, workflow).await;
    let task = cooperative_poll(app, "workflow").await;
    let scheduled = finish_task(app, &task, json!([{"type":"schedule_activity","activity_type":"stop-activity",
        "arguments":envelope(Payload::Array(vec![])),"retry_policy":{"max_attempts":1,"backoff_seconds":[]},
        "start_to_close_timeout":300}])).await;
    assert_eq!(scheduled.0, StatusCode::OK, "{}", scheduled.1);
    let activity = cooperative_poll(app, "activity").await;
    let completed = request_protocol(app, "POST",
        &format!("/api/worker/activity-tasks/{}/complete", activity["task_id"].as_str().unwrap()),
        json!({"activity_attempt_id":activity["activity_attempt_id"],"lease_owner":activity["lease_owner"],
            "result":envelope(Payload::String("done".into()))}), "1.20").await;
    assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
    let resumed = cooperative_poll(app, "workflow").await;
    let timer = finish_task(app, &resumed, json!([{"type":"start_timer","delay_seconds":3600}])).await;
    assert_eq!(timer.0, StatusCode::OK, "{}", timer.1);
    let requested = request(app, "POST", &format!("/api/workflows/{workflow}/request-cancellation"),
        json!({"reason":"stop after the activity","cleanup_timeout_seconds":60})).await;
    assert_eq!(requested.0, StatusCode::ACCEPTED, "{}", requested.1);
    let claimed = cooperative_poll(app, "workflow").await;
    assert_eq!(claimed["cancellation_request"]["request_id"], requested.1["cancellation_request"]["request_id"], "{claimed}");
    (started, claimed, requested.1["cancellation_request"]["request_id"].clone())
}

// Published Workflow 2.11.1 validates an authored activity boundary against
// its typed history before cancelling anything
// (CooperativeCancellationDelivery::validateCallBoundary): another call's
// sequence is a shape mismatch, and an activity that resolved before the
// request is not eligible. Neither refusal records a delivery, and the
// request can still be delivered at its real boundary.
#[tokio::test]
async fn an_activity_delivery_is_refused_at_a_resolved_activity_or_another_calls_sequence() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (started, task, request_id) = resolved_activity_then_timer(&app, "resolved-activity").await;
    let run_id = &started["run_id"];
    let before = run_history(&app, &started["workflow_id"], run_id).await;
    let undelivered = "SELECT COUNT(*) FROM workflow_runs WHERE cancellation_request_command_id IS NOT NULL AND cancellation_delivery_sequence IS NULL AND cancellation_delivered_at IS NULL";
    assert_eq!(task_retry::rows(&database, undelivered).await, 1);

    let resolved = deliver_at(&app, &task, &request_id, 1, "activity").await;
    assert_eq!(resolved, (StatusCode::CONFLICT, refused_delivery(&task, run_id, "cancellation_delivery_not_eligible")));
    let timer_sequence = deliver_at(&app, &task, &request_id, 2, "activity").await;
    assert_eq!(timer_sequence, (StatusCode::CONFLICT, refused_delivery(&task, run_id, "cancellation_delivery_shape_mismatch")));
    let activity_sequence = deliver_at(&app, &task, &request_id, 1, "timer").await;
    assert_eq!(activity_sequence, (StatusCode::CONFLICT, refused_delivery(&task, run_id, "cancellation_delivery_shape_mismatch")));
    let beyond = deliver_at(&app, &task, &request_id, 4, "activity").await;
    assert_eq!(beyond, (StatusCode::CONFLICT, refused_delivery(&task, run_id, "cancellation_delivery_sequence_mismatch")));

    // Nothing was delivered or cancelled.
    assert_eq!(run_history(&app, &started["workflow_id"], run_id).await, before);
    assert_eq!(task_retry::rows(&database, undelivered).await, 1);
    assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_run_timers WHERE status='pending'").await, 1);
    assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM activity_executions WHERE status='completed'").await, 1);
    let duplicate = request(&app, "POST", "/api/workflows/resolved-activity/request-cancellation", json!({})).await;
    assert_eq!((duplicate.0, duplicate.1["duplicate"].clone()), (StatusCode::OK, json!(true)), "{}", duplicate.1);
    assert_eq!((duplicate.1["cancellation_request"]["delivery_sequence"].clone(),
        duplicate.1["cancellation_request"]["delivered_at"].clone()), (Value::Null, Value::Null), "{}", duplicate.1);

    // The pending timer still takes the delivery.
    let delivered = deliver_at(&app, &task, &request_id, 2, "timer").await;
    assert_eq!((delivered.0, delivered.1["delivered"].clone(), delivered.1["sequence"].clone()),
        (StatusCode::OK, json!(true), json!(2)), "{}", delivered.1);
    let history = run_history(&app, &started["workflow_id"], run_id).await;
    let events = history.as_array().unwrap();
    assert_eq!(&events[..before.as_array().unwrap().len()], before.as_array().unwrap().as_slice());
    let appended: Vec<_> = events[before.as_array().unwrap().len()..].iter().map(|event| event["event_type"].clone()).collect();
    assert_eq!(appended, [json!("TimerCancelled"), json!("CooperativeCancellationDelivered")], "{history}");
    runtime.close().await;
    database.remove().await;
}

// The next sequence may be a call the workflow has not authored yet, so it
// takes an activity delivery without typed history, as published PHP does.
#[tokio::test]
async fn an_activity_delivery_at_the_next_sequence_needs_no_authored_activity() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (started, task, request_id) = resolved_activity_then_timer(&app, "next-activity").await;
    let before = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let delivered = deliver_at(&app, &task, &request_id, 3, "activity").await;
    assert_eq!((delivered.0, delivered.1["delivered"].clone(), delivered.1["sequence"].clone(), delivered.1["call_kind"].clone()),
        (StatusCode::OK, json!(true), json!(3), json!("activity")), "{}", delivered.1);
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let appended: Vec<_> = history.as_array().unwrap()[before.as_array().unwrap().len()..].iter()
        .map(|event| event["event_type"].clone()).collect();
    assert_eq!(appended, [json!("CooperativeCancellationDelivered")], "{history}");
    assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_run_timers WHERE status='pending'").await, 1);
    runtime.close().await;
    database.remove().await;
}
