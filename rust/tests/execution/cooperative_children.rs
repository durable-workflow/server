use super::*;

async fn setup(app: &Router) -> (Value, Value) {
    let registered = request_protocol(app, "POST", "/api/worker/register", json!({
        "worker_id":"cleanup-worker","task_queue":"test","runtime":"php",
        "supported_workflow_types":["echo"],"supported_activity_types":[],
        "capabilities":["cooperative_cancellation"]}), "1.20").await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    register(app, "abandoned-worker", json!(["child"]), json!([])).await;
    let parent = start(app, "abandon-parent").await;
    let first = cooperative_poll(app, "workflow").await;
    let scheduled = finish_task(app, &first, json!([child_command(9007199254740993)])).await;
    assert_eq!(scheduled.0, StatusCode::OK, "{}", scheduled.1);
    let events = run_history(app, &parent["workflow_id"], &parent["run_id"]).await;
    let started = events.as_array().unwrap().iter().find(|event| event["event_type"] == "ChildRunStarted").unwrap();
    // Incoming child cancellation remains outside the supported root slice.
    let refused = request(app, "POST", &format!("/api/workflows/{}/request-cancellation",
        started["payload"]["child_workflow_instance_id"].as_str().unwrap()), json!({})).await;
    assert_eq!(refused.0, StatusCode::UNPROCESSABLE_ENTITY, "{}", refused.1);
    assert_eq!(refused.1["reason"], "cooperative_cancellation_propagation_not_available");
    let child = poll(app, "abandoned-worker", "workflow").await;
    assert_eq!(child["run_id"], started["payload"]["child_workflow_run_id"]);
    assert!(child["lease_expires_at"].is_string(), "{child}");
    (parent, child)
}

async fn root_request(app: &Router, budget: i64) -> Value {
    let response = request(app, "POST", "/api/workflows/abandon-parent/request-cancellation",
        json!({"reason":"original parent λ","cleanup_timeout_seconds":budget})).await;
    assert_eq!(response.0, StatusCode::ACCEPTED, "{}", response.1);
    assert_eq!(response.1["duplicate"], false);
    response.1["cancellation_request"].clone()
}

async fn deliver(app: &Router, task: &Value, pending: &Value, sequence: i64, kind: &str) -> (StatusCode, Value) {
    request_protocol(app, "POST", &format!("/api/worker/workflow-tasks/{}/deliver-cancellation", task["task_id"].as_str().unwrap()),
        json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],
            "request_id":pending["request_id"],"sequence":sequence,"call_kind":kind}), "1.20").await
}

async fn reference(database: &TestDatabase, app: &Router, parent: &Value, pending: &Value, sequence: i64, kind: &str, reason: Option<&str>) {
    let history = run_history(app, &parent["workflow_id"], &parent["run_id"]).await;
    run_observation_summaries::export_reference_for(database,
        &json!({"run_id":parent["run_id"],"workflow_id":parent["workflow_id"],"request":pending,
            "sequence":sequence,"call_kind":kind,"expected_reason":reason}),
        &json!({"events":history}), "DW_TEST_CHILD_CANCELLATION_REFERENCE").await;
}

async fn closed(app: &Router, parent: &Value, pending: &Value, outcome: &str, sequence: i64) -> Value {
    let response = request(app, "GET", "/api/workflows/abandon-parent", Value::Null).await;
    assert_eq!(response.0, StatusCode::OK, "{}", response.1);
    assert_eq!(response.1["status"], "cancelled");
    let history = run_history(app, &parent["workflow_id"], &parent["run_id"]).await;
    let events = history.as_array().unwrap();
    let terminals: Vec<_> = events.iter().filter(|event| event["event_type"] == "WorkflowCancelled").collect();
    assert_eq!(terminals.len(), 1);
    let cleanup = &terminals[0]["payload"]["cancellation_cleanup"];
    assert_eq!(cleanup["request_id"], pending["request_id"]);
    assert_eq!(cleanup["cleanup_deadline_at"], pending["cleanup_deadline_at"]);
    assert_eq!(cleanup["outcome"], outcome);
    assert_eq!(cleanup["delivery_sequence"], sequence);
    assert!(!events.iter().any(|event| matches!(event["event_type"].as_str(), Some("WorkflowCompleted" | "WorkflowFailed"))));
    let duplicate = request(app, "POST", "/api/workflows/abandon-parent/request-cancellation",
        json!({"reason":"replacement","cleanup_timeout_seconds":3600})).await;
    assert_eq!(duplicate.0, StatusCode::OK, "{}", duplicate.1);
    assert_eq!(duplicate.1["duplicate"], true);
    for field in ["request_id", "requested_at", "cleanup_deadline_at"] {
        assert_eq!(duplicate.1["cancellation_request"][field], pending[field]);
    }
    assert_eq!(run_history(app, &parent["workflow_id"], &parent["run_id"]).await, history);
    history
}

async fn finish_child(app: &Router, child: &Value) {
    let output = envelope(Payload::Array(vec![Payload::Long(9007199254740993), Payload::String("日本語 λ".into()), Payload::Boolean(false)]));
    let commands = json!([{"type":"complete_workflow","result":output}]);
    let finished = finish_task(app, child, commands.clone()).await;
    assert_eq!(finished.0, StatusCode::OK, "{}", finished.1);
    let duplicate = finish_task(app, child, commands).await;
    assert_eq!(duplicate.0, StatusCode::OK, "{}", duplicate.1);
    let history = run_history(app, &child["workflow_id"], &child["run_id"]).await;
    let completed: Vec<_> = history.as_array().unwrap().iter().filter(|event| event["event_type"] == "WorkflowCompleted").collect();
    assert_eq!(completed.len(), 1);
    assert_eq!(completed[0]["payload"]["output"], output);
    assert!(!history.as_array().unwrap().iter().any(|event| event["event_type"] == "WorkflowCancelled"));
}

async fn worker_terminal(fail: bool, rollback: bool) {
    let database = if rollback { TestDatabase::Sqlite(tempfile::tempdir().unwrap()) } else { TestDatabase::new().await };
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (parent, child) = setup(&app).await;
    let child_prefix = run_history(&app, &child["workflow_id"], &child["run_id"]).await;
    let pending = root_request(&app, 30).await;
    let root = cooperative_poll(&app, "workflow").await;
    reference(&database, &app, &parent, &pending, 1, "child", None).await;
    let wrong = deliver(&app, &root, &pending, 1, "timer").await;
    assert_eq!(wrong.0, StatusCode::CONFLICT, "{}", wrong.1);
    assert_eq!(wrong.1["reason"], "cancellation_delivery_shape_mismatch");
    let delivery = deliver(&app, &root, &pending, 1, "child").await;
    assert_eq!(delivery.0, StatusCode::OK, "{}", delivery.1);
    assert_eq!(deliver(&app, &root, &pending, 1, "child").await, delivery);
    let commands = if fail { json!([{"type":"fail_workflow","message":"cleanup failure"}]) }
        else { json!([{"type":"complete_workflow","result":envelope(Payload::Null)}]) };
    let prefix = run_history(&app, &parent["workflow_id"], &parent["run_id"]).await;
    if rollback {
        let before = run_observation_active_summaries::observe(&app, "default", &parent).await;
        database.execute("CREATE TRIGGER reject_abandon_terminal BEFORE INSERT ON workflow_history_events WHEN NEW.event_type='WorkflowCancelled' BEGIN SELECT RAISE(ABORT, 'parent cancellation rejected'); END").await;
        let rejected = finish_task(&app, &root, commands.clone()).await;
        assert_eq!(rejected.0, StatusCode::SERVICE_UNAVAILABLE, "{}", rejected.1);
        assert_eq!(run_history(&app, &parent["workflow_id"], &parent["run_id"]).await, prefix);
        assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_failures").await, 0);
        assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_child_calls WHERE status='started' AND closed_at IS NULL").await, 1);
        assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_tasks WHERE status='leased' AND lease_expires_at IS NOT NULL").await, 2);
        let after = run_observation_active_summaries::observe(&app, "default", &parent).await;
        for field in ["status", "history_event_count", "history_size_bytes", "active_workflow_task_count", "pending_child_count"] {
            assert_eq!(after[field], before[field], "{field}");
        }
        database.execute("DROP TRIGGER reject_abandon_terminal").await;
    }
    runtime.close().await;
    let cold = database.open().await.unwrap();
    let app = router(cold.clone());
    let finished = finish_task(&app, &root, commands.clone()).await;
    assert_eq!(finished.0, StatusCode::OK, "{}", finished.1);
    let history = closed(&app, &parent, &pending, "completed", 1).await;
    assert_eq!(run_history(&app, &child["workflow_id"], &child["run_id"]).await, child_prefix);
    assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_tasks WHERE status='leased' AND lease_expires_at IS NOT NULL").await, 1);
    finish_child(&app, &child).await;
    assert_eq!(run_history(&app, &parent["workflow_id"], &parent["run_id"]).await, history);
    assert!(cooperative_poll(&app, "workflow").await.is_null());
    assert_eq!(finish_task(&app, &root, commands).await.0, StatusCode::OK);
    cold.close().await;
    let again = database.open().await.unwrap();
    assert_eq!(closed(&router(again.clone()), &parent, &pending, "completed", 1).await, history);
    again.close().await;
    database.remove().await;
}

#[tokio::test]
async fn cooperative_children_completion_abandons_original_child_lease() { worker_terminal(false, false).await; }

#[tokio::test]
async fn cooperative_children_failure_keeps_root_cancellation_precedence() { worker_terminal(true, false).await; }

#[tokio::test]
async fn cooperative_children_parent_terminal_write_rolls_back_with_child_unchanged() { worker_terminal(false, true).await; }

#[tokio::test]
async fn cooperative_children_original_deadline_survives_cold_reopen() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (parent, child) = setup(&app).await;
    let pending = root_request(&app, 2).await;
    let root = cooperative_poll(&app, "workflow").await;
    reference(&database, &app, &parent, &pending, 1, "child", None).await;
    let delivery = deliver(&app, &root, &pending, 1, "child").await;
    assert_eq!(delivery.0, StatusCode::OK, "{}", delivery.1);
    runtime.close().await;
    let deadline = chrono::DateTime::parse_from_rfc3339(pending["cleanup_deadline_at"].as_str().unwrap()).unwrap();
    let remaining = (deadline.with_timezone(&chrono::Utc) - chrono::Utc::now()).to_std().unwrap_or_default();
    assert!(remaining <= std::time::Duration::from_secs(2));
    tokio::time::sleep(remaining + std::time::Duration::from_millis(25)).await;
    let cold = database.open().await.unwrap();
    let app = router(cold.clone());
    let limit = tokio::time::Instant::now() + std::time::Duration::from_secs(5);
    loop {
        let response = request(&app, "GET", "/api/workflows/abandon-parent", Value::Null).await;
        assert_eq!(response.0, StatusCode::OK);
        if response.1["status"] == "cancelled" { break; }
        assert!(tokio::time::Instant::now() < limit, "original deadline must close parent");
        tokio::time::sleep(std::time::Duration::from_millis(25)).await;
    }
    let history = closed(&app, &parent, &pending, "deadline_expired", 1).await;
    let stale = finish_task(&app, &root, json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await;
    assert_eq!(stale.0, StatusCode::CONFLICT, "{}", stale.1);
    finish_child(&app, &child).await;
    assert_eq!(run_history(&app, &parent["workflow_id"], &parent["run_id"]).await, history);
    assert!(cooperative_poll(&app, "workflow").await.is_null());
    cold.close().await;
    database.remove().await;
}

async fn resolution_race(before_request: bool) {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (parent, child) = setup(&app).await;
    if before_request { finish_child(&app, &child).await; }
    let pending = root_request(&app, 30).await;
    // The request must reuse an ordinary child resume or create one cleanup task.
    let root = cooperative_poll(&app, "workflow").await;
    if !before_request { finish_child(&app, &child).await; }
    assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_tasks WHERE task_type='workflow' AND status IN ('ready','leased')").await, 1);
    let reason = before_request.then_some("cancellation_delivery_not_eligible");
    reference(&database, &app, &parent, &pending, 1, "child", reason).await;
    let delivered = deliver(&app, &root, &pending, 1, "child").await;
    let sequence = if before_request {
        assert_eq!(delivered.0, StatusCode::CONFLICT, "{}", delivered.1);
        assert_eq!(delivered.1["reason"], "cancellation_delivery_not_eligible");
        reference(&database, &app, &parent, &pending, 2, "timer", None).await;
        let next = deliver(&app, &root, &pending, 2, "timer").await;
        assert_eq!(next.0, StatusCode::OK, "{}", next.1);
        2
    } else {
        assert_eq!(delivered.0, StatusCode::OK, "{}", delivered.1);
        1
    };
    let events = run_history(&app, &parent["workflow_id"], &parent["run_id"]).await;
    let events = events.as_array().unwrap();
    let resolved = events.iter().position(|event| event["event_type"] == "ChildRunCompleted").unwrap();
    let requested = events.iter().position(|event| event["event_type"] == "CooperativeCancellationRequested").unwrap();
    assert_eq!(resolved < requested, before_request);
    let closed_result = finish_task(&app, &root, json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await;
    assert_eq!(closed_result.0, StatusCode::OK, "{}", closed_result.1);
    closed(&app, &parent, &pending, "completed", sequence).await;
    assert!(cooperative_poll(&app, "workflow").await.is_null());
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn cooperative_children_post_request_resolution_reuses_parent_cleanup_claim() { resolution_race(false).await; }

#[tokio::test]
async fn cooperative_children_pre_request_resolution_rejects_old_boundary() { resolution_race(true).await; }
