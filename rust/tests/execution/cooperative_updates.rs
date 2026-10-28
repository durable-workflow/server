use super::{terminal_updates::*, *};

async fn claim(app: &Router, timeout: i64) -> Value {
    let response = request_protocol(app, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"cooperative-update-worker","task_queue":"test","timeout_seconds":timeout}), "1.20").await;
    assert_eq!(response.0, StatusCode::OK, "{}", response.1);
    response.1["task"].clone()
}

pub(super) async fn pending(_database: &TestDatabase, app: &Router) -> (Value, Value, Value, Value) {
    let registered = request_protocol(app, "POST", "/api/worker/register",
        json!({"worker_id":"cooperative-update-worker","task_queue":"test","runtime":"php",
            "supported_workflow_types":["echo"],"supported_activity_types":[],
            "capabilities":["cooperative_cancellation","workflow_updates"],
            "workflow_command_contracts":{"echo":{"signals":["finish"],"updates":["set"],"update_validators":[],
                "update_contracts":[{"name":"set","parameters":[{"name":"value","type":"int","required":true,
                    "variadic":false,"default_available":false,"allows_null":false}]}]}}}), "1.20").await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    let run = start(app, "cooperative-update").await;
    let initial = claim(app, 0).await;
    let opened = finish_task(
        app,
        &initial,
        json!([{"type":"open_signal_wait","signal_name":"finish","timeout_seconds":1}]),
    )
    .await;
    assert_eq!(opened.0, StatusCode::OK, "{}", opened.1);
    let accepted = request(
        app,
        "POST",
        "/api/workflows/cooperative-update/update/set",
        input("original"),
    )
    .await;
    assert_eq!(accepted.0, StatusCode::ACCEPTED, "{}", accepted.1);
    let update_task = claim(app, 0).await;
    assert_eq!(update_task["workflow_update_id"], accepted.1["update_id"]);

    let history = run_history(app, &run["workflow_id"], &run["run_id"]).await;
    let timer = history
        .as_array()
        .unwrap()
        .iter()
        .find(|event| event["event_type"] == "TimerScheduled")
        .unwrap();
    let deadline =
        chrono::DateTime::parse_from_rfc3339(timer["payload"]["fire_at"].as_str().unwrap())
            .unwrap();
    let remaining = (deadline.with_timezone(&chrono::Utc) - chrono::Utc::now())
        .to_std()
        .unwrap_or_default();
    assert!(remaining <= std::time::Duration::from_secs(1));
    tokio::time::sleep(remaining + std::time::Duration::from_millis(25)).await;
    let root = claim(app, 2).await;
    assert!(root["task_id"].is_string(), "{root}");
    assert!(root["workflow_update_id"].is_null(), "{root}");
    (run, accepted.1, update_task, root)
}

async fn request_root(app: &Router, budget: i64) -> Value {
    let response = request(
        app,
        "POST",
        "/api/workflows/cooperative-update/request-cancellation",
        json!({"reason":"original root λ","cleanup_timeout_seconds":budget}),
    )
    .await;
    assert_eq!(response.0, StatusCode::ACCEPTED, "{}", response.1);
    assert_eq!(response.1["duplicate"], false);
    response.1["cancellation_request"].clone()
}

async fn deliver(app: &Router, root: &Value, pending: &Value) {
    let response = request_protocol(app, "POST",
        &format!("/api/worker/workflow-tasks/{}/deliver-cancellation", root["task_id"].as_str().unwrap()),
        json!({"lease_owner":root["lease_owner"],"workflow_task_attempt":root["workflow_task_attempt"],
            "request_id":pending["request_id"],"sequence":2,"call_kind":"timer"}), "1.20").await;
    assert_eq!(response.0, StatusCode::OK, "{}", response.1);
    assert_eq!(response.1["delivered"], true);
}

async fn verify_closed(
    app: &Router,
    run: &Value,
    update: &Value,
    pending: &Value,
    outcome: &str,
) -> Value {
    let receipt = closed_receipt(app, run, update, "cancelled").await;
    let history = run_history(app, &run["workflow_id"], &run["run_id"]).await;
    let events = history.as_array().unwrap();
    let terminal = events
        .iter()
        .position(|event| event["event_type"] == "WorkflowCancelled")
        .unwrap();
    assert_eq!(
        events
            .iter()
            .filter(|event| event["event_type"] == "WorkflowCancelled")
            .count(),
        1
    );
    assert_eq!(events[terminal + 1]["event_type"], "UpdateCompleted");
    assert_eq!(
        events[terminal]["payload"]["cancellation_cleanup"]["request_id"],
        pending["request_id"]
    );
    assert_eq!(
        events[terminal]["payload"]["cancellation_cleanup"]["cleanup_deadline_at"],
        pending["cleanup_deadline_at"]
    );
    assert_eq!(
        events[terminal]["payload"]["cancellation_cleanup"]["outcome"],
        outcome
    );
    assert!(!events.iter().any(|event| matches!(
        event["event_type"].as_str(),
        Some("WorkflowCompleted" | "WorkflowFailed")
    )));
    let duplicate = request(
        app,
        "POST",
        "/api/workflows/cooperative-update/request-cancellation",
        json!({"reason":"replacement","cleanup_timeout_seconds":3600}),
    )
    .await;
    assert_eq!(duplicate.0, StatusCode::OK, "{}", duplicate.1);
    assert_eq!(duplicate.1["duplicate"], true);
    for field in [
        "request_id",
        "requested_at",
        "cleanup_deadline_at",
        "history_refresh_page_token",
    ] {
        assert_eq!(
            duplicate.1["cancellation_request"][field], pending[field],
            "{field}"
        );
    }
    assert_eq!(duplicate.1["cancellation_request"]["delivery_sequence"], 2);
    assert!(duplicate.1["cancellation_request"]["delivered_at"].is_string());
    assert_eq!(
        run_history(app, &run["workflow_id"], &run["run_id"]).await,
        history
    );
    receipt
}

async fn fenced_update(app: &Router, task: &Value, update: &Value) {
    let stale = finish_task(
        app,
        task,
        json!([{"type":"complete_update","update_id":update["update_id"],
        "result":envelope(Payload::Long(42))}]),
    )
    .await;
    assert_eq!(stale.0, StatusCode::CONFLICT, "{}", stale.1);
}

async fn worker_terminal(failed: bool, rollback: bool) {
    let database = if rollback {
        TestDatabase::Sqlite(tempfile::tempdir().unwrap())
    } else {
        TestDatabase::new().await
    };
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (run, update, owned, root) = pending(&database, &app).await;
    let before = reference_before(&database, &app, &run, &update).await;
    let request = request_root(&app, 30).await;
    deliver(&app, &root, &request).await;
    let commands = if failed {
        json!([{"type":"fail_workflow","message":"cleanup terminal failure"}])
    } else {
        json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])
    };
    let prefix = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    if rollback {
        let original_summary =
            run_observation_active_summaries::observe(&app, "default", &run).await;
        database.execute("CREATE TRIGGER reject_cooperative_update BEFORE INSERT ON workflow_history_events WHEN NEW.event_type='UpdateCompleted' BEGIN SELECT RAISE(ABORT, 'cooperative update rejected'); END").await;
        let failed = finish_task(&app, &root, commands.clone()).await;
        assert_eq!(failed.0, StatusCode::SERVICE_UNAVAILABLE, "{}", failed.1);
        assert_eq!(
            run_history(&app, &run["workflow_id"], &run["run_id"]).await,
            prefix
        );
        assert_eq!(
            task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_failures").await,
            0
        );
        assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_updates WHERE status='accepted' AND closed_at IS NULL").await, 1);
        assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_tasks WHERE status='leased' AND lease_expires_at IS NOT NULL").await, 2);
        assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_runs WHERE closed_at IS NULL AND message_cursor_position=0").await, 1);
        let unchanged = run_observation_active_summaries::observe(&app, "default", &run).await;
        for field in [
            "status",
            "closed_reason",
            "history_event_count",
            "history_size_bytes",
            "exception_count",
            "wait_kind",
            "liveness_state",
        ] {
            assert_eq!(unchanged[field], original_summary[field], "{field}");
        }
        database
            .execute("DROP TRIGGER reject_cooperative_update")
            .await;
    }
    let done = finish_task(&app, &root, commands.clone()).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    assert_eq!(done.1["run_status"], "cancelled");
    let receipt = verify_closed(&app, &run, &update, &request, "completed").await;
    reference_after(&database, &app, &run, before).await;
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let event = history
        .as_array()
        .unwrap()
        .iter()
        .find(|event| event["event_type"] == "UpdateCompleted")
        .unwrap();
    assert_eq!(event["payload"]["task"]["id"], root["task_id"]);
    assert_eq!(event["payload"]["task"]["status"], "leased");
    assert_eq!(event["payload"]["task"]["lease_owner"], root["lease_owner"]);
    assert_eq!(
        finish_task(&app, &root, commands).await.1["recorded"],
        false
    );
    fenced_update(&app, &owned, &update).await;
    assert_eq!(
        run_history(&app, &run["workflow_id"], &run["run_id"]).await,
        history
    );
    runtime.close().await;
    let cold = database.open().await.unwrap();
    let app = router(cold.clone());
    assert_eq!(
        verify_closed(&app, &run, &update, &request, "completed").await,
        receipt
    );
    fenced_update(&app, &owned, &update).await;
    assert!(claim(&app, 0).await.is_null());
    assert_eq!(
        run_history(&app, &run["workflow_id"], &run["run_id"]).await,
        history
    );
    cold.close().await;
    database.remove().await;
}

#[tokio::test]
async fn cooperative_updates_completion_closes_original_update_and_fences_cold_retry() {
    worker_terminal(false, false).await;
}

#[tokio::test]
async fn cooperative_updates_failure_keeps_root_precedence_and_original_update_snapshot() {
    worker_terminal(true, false).await;
}

#[tokio::test]
async fn cooperative_updates_sqlite_write_failure_rolls_back_root_and_update_together() {
    worker_terminal(false, true).await;
}

#[tokio::test]
async fn cooperative_updates_original_deadline_closes_update_after_cold_reopen() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (run, update, owned, root) = pending(&database, &app).await;
    let before = reference_before(&database, &app, &run, &update).await;
    let pending = request_root(&app, 2).await;
    deliver(&app, &root, &pending).await;
    runtime.close().await;
    let deadline =
        chrono::DateTime::parse_from_rfc3339(pending["cleanup_deadline_at"].as_str().unwrap())
            .unwrap();
    let remaining = (deadline.with_timezone(&chrono::Utc) - chrono::Utc::now())
        .to_std()
        .unwrap_or_default();
    assert!(remaining <= std::time::Duration::from_secs(2));
    tokio::time::sleep(remaining + std::time::Duration::from_millis(25)).await;
    let cold = database.open().await.unwrap();
    let app = router(cold.clone());
    // Maintenance runs independently of poll and enforces the stored deadline.
    // Waiting for that transition does not grant a new cleanup budget.
    let maintenance_limit = tokio::time::Instant::now() + std::time::Duration::from_secs(5);
    loop {
        let described = request(
            &app,
            "GET",
            "/api/workflows/cooperative-update",
            Value::Null,
        )
        .await;
        assert_eq!(described.0, StatusCode::OK, "{}", described.1);
        if described.1["status"] == "cancelled" {
            break;
        }
        assert!(
            tokio::time::Instant::now() < maintenance_limit,
            "original root deadline must close the run"
        );
        tokio::time::sleep(std::time::Duration::from_millis(25)).await;
    }
    assert!(claim(&app, 0).await.is_null());
    let receipt = verify_closed(&app, &run, &update, &pending, "deadline_expired").await;
    reference_after(&database, &app, &run, before).await;
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let update_event = history
        .as_array()
        .unwrap()
        .iter()
        .find(|event| event["event_type"] == "UpdateCompleted")
        .unwrap();
    assert!(update_event["payload"].get("task").is_none());
    fenced_update(&app, &owned, &update).await;
    assert_eq!(
        finish_task(
            &app,
            &root,
            json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])
        )
        .await
        .0,
        StatusCode::CONFLICT
    );
    assert_eq!(
        run_history(&app, &run["workflow_id"], &run["run_id"]).await,
        history
    );
    cold.close().await;
    let again = database.open().await.unwrap();
    assert_eq!(
        verify_closed(
            &router(again.clone()),
            &run,
            &update,
            &pending,
            "deadline_expired"
        )
        .await,
        receipt
    );
    again.close().await;
    database.remove().await;
}

/// `WorkerPollFence::isFresh` decides cooperative delivery with the configured
/// stale window (default 30 seconds), as PHP's
/// `CooperativeCancellationController` does. A lease owner last heard from 45
/// seconds ago is refused; native used a fixed 60 seconds and delivered.
#[tokio::test]
async fn cooperative_delivery_uses_the_configured_stale_window() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let (_run, _update, _update_task, root) = pending(&database, &app).await;
    let pending_request = request_root(&app, 30).await;
    let heard = (chrono::Utc::now() - chrono::Duration::seconds(45)).format("%Y-%m-%d %H:%M:%S");
    database.execute(sqlx::AssertSqlSafe(format!("UPDATE workflow_worker_registrations SET last_heartbeat_at='{heard}' WHERE worker_id='cooperative-update-worker'"))).await;
    let body = json!({"lease_owner":root["lease_owner"],"workflow_task_attempt":root["workflow_task_attempt"],
        "request_id":pending_request["request_id"],"sequence":2,"call_kind":"timer"});
    let path = format!("/api/worker/workflow-tasks/{}/deliver-cancellation", root["task_id"].as_str().unwrap());
    let refused = request_protocol(&app, "POST", &path, body.clone(), "1.20").await;
    assert_eq!((refused.0, refused.1["reason"].clone()), (StatusCode::CONFLICT, json!("stale_worker_registration")), "{}", refused.1);
    // A heartbeat inside the window makes the same delivery succeed.
    let beat = request_protocol(&app, "POST", "/api/worker/heartbeat", json!({"worker_id":"cooperative-update-worker"}), "1.20").await;
    assert_eq!(beat.0, StatusCode::OK, "{}", beat.1);
    deliver(&app, &root, &pending_request).await;
}
