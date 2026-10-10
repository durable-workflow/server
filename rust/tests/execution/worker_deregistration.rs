use super::*;

impl TestDatabase {
    async fn receipt_fault(&self, enabled: bool) {
        match self {
            Self::Sqlite(dir) => {
                let pool = sqlx::SqlitePool::connect(&format!(
                    "sqlite://{}",
                    dir.path().join("runtime.sqlite").display()
                ))
                .await
                .unwrap();
                sqlx::raw_sql(if enabled {
                    "CREATE TRIGGER reject_shutdown BEFORE UPDATE ON dw_worker_registration_incarnations WHEN NEW.status='deregistered' BEGIN SELECT RAISE(ABORT,'qualification receipt fault'); END"
                } else {"DROP TRIGGER reject_shutdown"}).execute(&pool).await.unwrap();
                pool.close().await;
            }
            Self::Postgres { options, .. } => {
                let pool = sqlx::postgres::PgPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap();
                sqlx::raw_sql(if enabled {
                    "CREATE FUNCTION reject_shutdown_receipt() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.status='deregistered' THEN RAISE EXCEPTION 'qualification receipt fault'; END IF; RETURN NEW; END $$; CREATE TRIGGER reject_shutdown BEFORE UPDATE ON dw_worker_registration_incarnations FOR EACH ROW EXECUTE FUNCTION reject_shutdown_receipt()"
                } else {"DROP TRIGGER reject_shutdown ON dw_worker_registration_incarnations; DROP FUNCTION reject_shutdown_receipt()"}).execute(&pool).await.unwrap();
                pool.close().await;
            }
            Self::MySql { options, .. } => {
                let pool = sqlx::mysql::MySqlPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap();
                sqlx::raw_sql(if enabled {
                    "CREATE TRIGGER reject_shutdown BEFORE UPDATE ON dw_worker_registration_incarnations FOR EACH ROW BEGIN IF NEW.status='deregistered' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='qualification receipt fault'; END IF; END"
                } else {"DROP TRIGGER reject_shutdown"}).execute(&pool).await.unwrap();
                pool.close().await;
            }
        }
    }

    async fn age_receipt(&self, token: &str) {
        const QUERY: &str = "UPDATE dw_worker_registration_incarnations SET finished_at='2000-01-01 00:00:00.000000' WHERE token=$1 AND status='deregistered'";
        match self {
            Self::Sqlite(dir) => {
                let pool = sqlx::SqlitePool::connect(&format!(
                    "sqlite://{}",
                    dir.path().join("runtime.sqlite").display()
                ))
                .await
                .unwrap();
                sqlx::query(QUERY).bind(token).execute(&pool).await.unwrap();
                pool.close().await;
            }
            Self::Postgres { options, .. } => {
                let pool = sqlx::postgres::PgPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap();
                sqlx::query(QUERY).bind(token).execute(&pool).await.unwrap();
                pool.close().await;
            }
            Self::MySql { options, .. } => {
                let pool = sqlx::mysql::MySqlPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap();
                sqlx::query("UPDATE dw_worker_registration_incarnations SET finished_at='2000-01-01 00:00:00.000000' WHERE token=? AND status='deregistered'").bind(token).execute(&pool).await.unwrap();
                pool.close().await;
            }
        }
    }
}

async fn registration(app: &Router, worker: &str) -> Value {
    let (status,body)=request(app,"POST","/api/worker/register",json!({"worker_id":worker,
        "task_queue":"test","runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":[]})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    body
}

async fn deregister(app: &Router, worker: &str, registration: &Value) -> (StatusCode, Value) {
    request(
        app,
        "POST",
        &format!("/api/worker/registrations/{worker}/deregister"),
        json!({"registration_token":registration["registration_token"]}),
    )
    .await
}

#[tokio::test]
async fn completed_receipt_survives_restart_and_preserves_replacement_original_lease() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let worker = "fenced-worker";
    let original = registration(&app, worker).await;
    let root = start(&app, "fenced-original").await;
    let task = poll(&app, worker, "workflow").await;
    let history_path = format!(
        "/api/workflows/fenced-original/runs/{}/history",
        root["run_id"].as_str().unwrap()
    );
    let unknown = deregister(
        &app,
        worker,
        &json!({"registration_token":"00000000000000000000000000000000"}),
    )
    .await;
    assert_eq!(unknown.0, StatusCode::NOT_FOUND);
    assert_eq!(unknown.1["reason"], "worker_registration_token_not_found");
    let receipt = deregister(&app, worker, &original).await;
    assert_eq!(receipt.0, StatusCode::OK, "{}", receipt.1);
    assert_eq!(receipt.1["recovered_workflow_task_count"], 1);
    let stale = finish_task(
        &app,
        &task,
        json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}]),
    )
    .await;
    assert_eq!(stale.0, StatusCode::CONFLICT);
    assert_eq!(stale.1["reason"], "task_not_leased");
    assert_eq!(stale.1["task_id"], task["task_id"]);
    assert_eq!(
        stale.1["workflow_task_attempt"],
        task["workflow_task_attempt"]
    );
    let replacement = registration(&app, worker).await;
    let latest = registration(&app, worker).await;
    let history = request(&app, "GET", &history_path, Value::Null).await.1;
    let superseded = deregister(&app, worker, &replacement).await;
    assert_eq!(superseded.0, StatusCode::CONFLICT);
    assert_eq!(superseded.1["reason"], "worker_registration_lost_authority");
    assert_eq!(superseded.1["retryable"], false);
    let latest_task = poll(&app, worker, "workflow").await;
    assert_eq!(latest_task["task_id"], task["task_id"]);
    assert_eq!(latest_task["workflow_task_attempt"], 2);
    runtime.close().await;
    drop(app);
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    assert_eq!(
        deregister(&app, worker, &original).await,
        receipt,
        "replay completed token before inspecting replacement"
    );
    assert_eq!(
        request(&app, "GET", &history_path, Value::Null).await.1,
        history
    );
    assert_eq!(
        request(
            &app,
            "POST",
            "/api/worker/heartbeat",
            json!({"worker_id":worker})
        )
        .await
        .0,
        StatusCode::OK
    );
    let stale = finish_task(
        &app,
        &task,
        json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}]),
    )
    .await;
    assert_eq!(stale.0, StatusCode::CONFLICT);
    assert_eq!(stale.1["reason"], "workflow_task_attempt_mismatch");
    assert_eq!(
        request(&app, "GET", &history_path, Value::Null).await.1,
        history
    );
    assert_eq!(
        deregister(&app, worker, &latest).await.1["recovered_workflow_task_count"],
        1
    );
    registration(&app, "recovery-worker").await;
    let recovered = poll(&app, "recovery-worker", "workflow").await;
    assert_eq!(recovered["task_id"], task["task_id"]);
    assert_eq!(recovered["run_id"], task["run_id"]);
    assert_eq!(recovered["workflow_task_attempt"], 3);
    assert_eq!(
        finish_task(
            &app,
            &recovered,
            json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}])
        )
        .await
        .0,
        StatusCode::OK
    );
    let events = run_history(&app, &root["workflow_id"], &root["run_id"]).await;
    let events = events.as_array().unwrap();
    assert_eq!(
        events
            .iter()
            .map(|event| event["event_type"].as_str().unwrap())
            .collect::<Vec<_>>(),
        [
            "StartAccepted",
            "WorkflowStarted",
            "RepairRequested",
            "RepairRequested",
            "WorkflowCompleted"
        ]
    );
    for event in &events[2..4] {
        assert_eq!(event["payload"]["task_id"], task["task_id"]);
        assert_eq!(event["payload"]["command_type"], "repair");
        assert_eq!(event["payload"]["outcome"], "repair_dispatched");
        assert_eq!(event["payload"]["task"]["status"], "ready");
        assert_eq!(event["payload"]["command"]["status"], "accepted");
    }
    assert_eq!(events[4]["payload"]["task"]["attempt_count"], 3);
    assert_eq!(events[4]["payload"]["task"]["repair_count"], 2);
    assert!(poll(&app, "recovery-worker", "workflow").await.is_null());
    runtime.close().await;
    drop(app);
    database.remove().await;
}

#[tokio::test]
async fn fenced_shutdown_is_namespace_scoped_and_requires_worker_role_before_protocol() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let legacy = router(runtime.clone());
    assert_eq!(
        request(
            &legacy,
            "POST",
            "/api/namespaces",
            json!({"name":"alpha","retention_days":30})
        )
        .await
        .0,
        StatusCode::CREATED
    );
    let original = registration(&legacy, "scoped-worker").await;
    let root = start(&legacy, "scoped-original").await;
    let task = poll(&legacy, "scoped-worker", "workflow").await;
    let path = "/api/worker/registrations/scoped-worker/deregister";
    let body = json!({"registration_token":original["registration_token"]});
    let app = router(runtime.clone().with_role_tokens(
        Some("worker-token".into()),
        Some("operator-token".into()),
        Some("admin-token".into()),
    ));
    for token in ["test-token", "operator-token", "admin-token"] {
        let refusal = admission::admission_http(
            &app,
            "POST",
            path,
            true,
            Some(token),
            Some("999"),
            Some("ghost"),
            body.clone(),
        )
        .await;
        assert_eq!(refusal.0, StatusCode::FORBIDDEN);
        assert_eq!(refusal.2["reason"], "forbidden");
    }
    let foreign = admission::admission_http(
        &app,
        "POST",
        path,
        true,
        Some("worker-token"),
        Some("1.20"),
        Some("alpha"),
        body.clone(),
    )
    .await;
    assert_eq!(foreign.0, StatusCode::NOT_FOUND);
    assert_eq!(foreign.2["reason"], "worker_registration_token_not_found");
    let wrong_worker = admission::admission_http(
        &app,
        "POST",
        "/api/worker/registrations/other-worker/deregister",
        true,
        Some("worker-token"),
        Some("1.20"),
        Some("default"),
        body.clone(),
    )
    .await;
    assert_eq!(wrong_worker.0, StatusCode::NOT_FOUND);
    assert_eq!(
        wrong_worker.2["reason"],
        "worker_registration_token_not_found"
    );
    assert_eq!(
        run_history(&legacy, &root["workflow_id"], &root["run_id"])
            .await
            .as_array()
            .unwrap()
            .len(),
        2
    );
    let receipt = admission::admission_http(
        &app,
        "POST",
        path,
        true,
        Some("worker-token"),
        Some("1.20"),
        Some("default"),
        body,
    )
    .await;
    assert_eq!(receipt.0, StatusCode::OK, "{}", receipt.2);
    assert_eq!(receipt.2["recovered_workflow_task_count"], 1);
    registration(&legacy, "scoped-recovery").await;
    let recovered = poll(&legacy, "scoped-recovery", "workflow").await;
    assert_eq!(recovered["task_id"], task["task_id"]);
    assert_eq!(recovered["workflow_task_attempt"], 2);
    runtime.close().await;
    drop(app);
    drop(legacy);
    database.remove().await;
}

#[tokio::test]
async fn independent_nodes_commit_one_shutdown_receipt_and_one_original_repair() {
    let database = TestDatabase::new().await;
    let first = database.open().await.unwrap();
    let second = database.open().await.unwrap();
    let a = router(first.clone());
    let b = router(second.clone());
    let registration = registration(&a, "concurrent-worker").await;
    let root = start(&a, "concurrent-original").await;
    let task = poll(&a, "concurrent-worker", "workflow").await;
    let (left, right) = tokio::join!(
        deregister(&a, "concurrent-worker", &registration),
        deregister(&b, "concurrent-worker", &registration)
    );
    assert_eq!(left.0, StatusCode::OK, "{}", left.1);
    assert_eq!(right, left);
    assert_eq!(left.1["recovered_workflow_task_count"], 1);
    let history = run_history(&a, &root["workflow_id"], &root["run_id"]).await;
    assert_eq!(history.as_array().unwrap().len(), 3);
    assert_eq!(history[2]["payload"]["task_id"], task["task_id"]);
    assert_eq!(history[2]["payload"]["task"]["repair_count"], 1);
    first.close().await;
    second.close().await;
    drop(a);
    drop(b);
    database.remove().await;
}

#[tokio::test]
async fn receipt_write_failure_rolls_back_original_task_history_worker_and_authority() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let registration = registration(&app, "rollback-worker").await;
    let root = start(&app, "rollback-original").await;
    let task = poll(&app, "rollback-worker", "workflow").await;
    let before = run_history(&app, &root["workflow_id"], &root["run_id"]).await;
    database.receipt_fault(true).await;
    let failed = deregister(&app, "rollback-worker", &registration).await;
    assert_eq!(failed.0, StatusCode::SERVICE_UNAVAILABLE, "{}", failed.1);
    assert_eq!(
        failed.1["reason"], "storage_unavailable",
        "permanent trigger faults are not retryable backpressure"
    );
    assert!(failed.1.get("retryable").is_none());
    assert_eq!(
        run_history(&app, &root["workflow_id"], &root["run_id"]).await,
        before
    );
    assert_eq!(
        request(
            &app,
            "POST",
            "/api/worker/heartbeat",
            json!({"worker_id":"rollback-worker"})
        )
        .await
        .0,
        StatusCode::OK
    );
    database.receipt_fault(false).await;
    // The original attempt can still complete; the failed repair did not
    // clear its lease, increment repairs, or retire its registration token.
    assert_eq!(
        finish_task(
            &app,
            &task,
            json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}])
        )
        .await
        .0,
        StatusCode::OK
    );
    let events = run_history(&app, &root["workflow_id"], &root["run_id"]).await;
    assert_eq!(events.as_array().unwrap().len(), 3);
    assert_eq!(events[2]["payload"]["task"]["repair_count"], 0);
    let receipt = deregister(&app, "rollback-worker", &registration).await;
    assert_eq!(receipt.0, StatusCode::OK, "{}", receipt.1);
    assert_eq!(receipt.1["recovered_workflow_task_count"], 0);
    runtime.close().await;
    drop(app);
    database.remove().await;
}

#[tokio::test]
async fn expired_receipt_refuses_then_bounded_cleanup_preserves_newer_original_lease() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let original = registration(&app, "expiry-worker").await;
    assert_eq!(
        deregister(&app, "expiry-worker", &original).await.1["recovered_workflow_task_count"],
        0
    );
    let current = registration(&app, "expiry-worker").await;
    let root = start(&app, "expiry-original").await;
    let task = poll(&app, "expiry-worker", "workflow").await;
    database
        .age_receipt(original["registration_token"].as_str().unwrap())
        .await;
    let before = run_history(&app, &root["workflow_id"], &root["run_id"]).await;
    let expired = deregister(&app, "expiry-worker", &original).await;
    assert_eq!(expired.0, StatusCode::GONE);
    assert_eq!(expired.1["reason"], "worker_registration_receipt_expired");
    assert_eq!(
        expired.1["registration_token"],
        original["registration_token"]
    );
    assert_eq!(expired.1["retryable"], false);
    registration(&app, "cleanup-worker").await;
    let pruned = deregister(&app, "expiry-worker", &original).await;
    assert_eq!(pruned.0, StatusCode::NOT_FOUND);
    assert_eq!(pruned.1["reason"], "worker_registration_token_not_found");
    assert_eq!(
        run_history(&app, &root["workflow_id"], &root["run_id"]).await,
        before
    );
    assert_eq!(
        finish_task(
            &app,
            &task,
            json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}])
        )
        .await
        .0,
        StatusCode::OK
    );
    assert_eq!(
        deregister(&app, "expiry-worker", &current).await.0,
        StatusCode::OK
    );
    runtime.close().await;
    drop(app);
    database.remove().await;
}
