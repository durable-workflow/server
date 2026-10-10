use super::worker_deregistration::{deregister, registration};
use super::*;
use std::time::{Duration, Instant};

// An independent physical connection holds the actual native transition lock.
// No injected sqlx errors, shortened production timeouts or mocked storage.
enum ShutdownWriteLock {
    Sqlite(sqlx::Transaction<'static, sqlx::Sqlite>, sqlx::SqlitePool),
    Postgres(sqlx::Transaction<'static, sqlx::Postgres>, sqlx::PgPool),
    MySql(sqlx::Transaction<'static, sqlx::MySql>, sqlx::MySqlPool),
}

impl ShutdownWriteLock {
    async fn release(self) {
        match self {
            Self::Sqlite(tx, pool) => {
                tx.commit().await.unwrap();
                pool.close().await;
            }
            Self::Postgres(tx, pool) => {
                tx.commit().await.unwrap();
                pool.close().await;
            }
            Self::MySql(tx, pool) => {
                tx.commit().await.unwrap();
                pool.close().await;
            }
        }
    }
}

impl TestDatabase {
    async fn hold_shutdown_write(&self) -> ShutdownWriteLock {
        match self {
            Self::Sqlite(dir) => {
                let pool = sqlx::sqlite::SqlitePoolOptions::new()
                    .max_connections(1)
                    .connect(&format!(
                        "sqlite://{}",
                        dir.path().join("runtime.sqlite").display()
                    ))
                    .await
                    .unwrap();
                let tx = pool.begin_with("BEGIN IMMEDIATE").await.unwrap();
                ShutdownWriteLock::Sqlite(tx, pool)
            }
            Self::Postgres { options, .. } => {
                let pool = sqlx::postgres::PgPoolOptions::new()
                    .max_connections(1)
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap();
                let mut tx = pool.begin().await.unwrap();
                sqlx::query("SELECT pg_advisory_xact_lock(4924501473315676163)")
                    .execute(&mut *tx)
                    .await
                    .unwrap();
                ShutdownWriteLock::Postgres(tx, pool)
            }
            Self::MySql { options, .. } => {
                let pool = sqlx::mysql::MySqlPoolOptions::new()
                    .max_connections(1)
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap();
                let mut tx = pool.begin().await.unwrap();
                sqlx::query("SELECT engine FROM dw_server_schema WHERE engine='rust-development' FOR UPDATE")
                    .fetch_one(&mut *tx).await.unwrap();
                ShutdownWriteLock::MySql(tx, pool)
            }
        }
    }
}

#[tokio::test]
async fn real_shutdown_lock_pressure_preserves_original_leases_and_retries_same_token() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let worker = "pressure-worker";
    let accepted = registration(&app, worker).await;
    let first = start(&app, "pressure-original-completion").await;
    let original = poll(&app, worker, "workflow").await;
    let second = start(&app, "pressure-original-recovery").await;
    let recoverable = poll(&app, worker, "workflow").await;
    assert_eq!(original["workflow_id"], first["workflow_id"]);
    assert_eq!(recoverable["workflow_id"], second["workflow_id"]);
    assert_eq!(original["workflow_task_attempt"], 1);
    assert_eq!(recoverable["workflow_task_attempt"], 1);
    let mut protected = Vec::new();
    for root in [&first, &second] {
        let path = format!(
            "/api/workflows/{}/runs/{}",
            root["workflow_id"].as_str().unwrap(),
            root["run_id"].as_str().unwrap()
        );
        let view = request(&app, "GET", &path, Value::Null).await;
        assert_eq!(view.0, StatusCode::OK);
        let history = run_history(&app, &root["workflow_id"], &root["run_id"]).await;
        protected.push((path, view.1, history));
    }
    let lock = database.hold_shutdown_write().await;
    let waiting_app = app.clone();
    let original_token = accepted["registration_token"].clone();
    let started = Instant::now();
    let shutdown = tokio::spawn(async move {
        admission::admission_http(
            &waiting_app,
            "POST",
            "/api/worker/registrations/pressure-worker/deregister",
            true,
            Some("test-token"),
            Some("1.20"),
            Some("default"),
            json!({"registration_token":original_token}),
        )
        .await
    });
    tokio::time::sleep(Duration::from_millis(100)).await;
    assert!(
        !shutdown.is_finished(),
        "the real database lock blocks shutdown"
    );
    let health = tokio::time::timeout(
        Duration::from_secs(1),
        request(&app, "GET", "/api/health", Value::Null),
    )
    .await
    .expect("database wait must yield the executor for a concurrent health read");
    assert_eq!(health.0, StatusCode::OK, "{}", health.1);
    assert_eq!(health.1["status"], "serving");
    let failed = tokio::time::timeout(Duration::from_secs(10), shutdown)
        .await
        .expect("native database lock wait remains bounded")
        .unwrap();
    assert!(
        started.elapsed() >= Duration::from_secs(4),
        "exercise the real five-second backend lock timeout"
    );
    assert_eq!(failed.0, StatusCode::SERVICE_UNAVAILABLE, "{}", failed.2);
    assert_eq!(failed.1["retry-after"], "1");
    assert_eq!(failed.2["reason"], "backend_lock_pressure");
    assert_eq!(failed.2["operation"], "deregister_worker");
    assert_eq!(failed.2["worker_id"], worker);
    assert_eq!(
        failed.2["registration_token"],
        accepted["registration_token"]
    );
    assert_eq!(failed.2["outcome"], "unknown");
    assert_eq!(failed.2["retryable"], true);
    assert_eq!(failed.2["retry_after_seconds"], 1);
    assert_eq!(failed.2["protocol_version"], "1.20");
    // Keep the independent writer held while checking the unchanged full reads.
    for (root, (path, view, history)) in [&first, &second].into_iter().zip(&protected) {
        assert_eq!(request(&app, "GET", path, Value::Null).await.1, *view);
        assert_eq!(
            run_history(&app, &root["workflow_id"], &root["run_id"]).await,
            *history
        );
        assert_eq!(
            database
                .repair_commands(root["run_id"].as_str().unwrap())
                .await,
            0
        );
    }
    lock.release().await;
    let commands =
        json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}]);
    assert_eq!(
        finish_task(&app, &original, commands.clone()).await.0,
        StatusCode::OK,
        "original attempt remains authoritative after failed shutdown"
    );
    let first_completed = run_history(&app, &first["workflow_id"], &first["run_id"]).await;
    assert_eq!(first_completed.as_array().unwrap().len(), 3);
    assert_eq!(first_completed[2]["event_type"], "WorkflowCompleted");
    assert_eq!(first_completed[2]["payload"]["task"]["attempt_count"], 1);
    assert_eq!(first_completed[2]["payload"]["task"]["repair_count"], 0);
    let receipt = deregister(&app, worker, &accepted).await;
    assert_eq!(receipt.0, StatusCode::OK, "{}", receipt.1);
    assert_eq!(
        receipt.1["registration_token"],
        accepted["registration_token"]
    );
    assert_eq!(receipt.1["recovered_workflow_task_count"], 1);
    assert_eq!(receipt.1["outcome"], "deregistered");
    assert_eq!(
        run_history(&app, &first["workflow_id"], &first["run_id"]).await,
        first_completed
    );
    let repaired = run_history(&app, &second["workflow_id"], &second["run_id"]).await;
    assert_eq!(repaired.as_array().unwrap().len(), 3);
    assert_eq!(repaired[2]["event_type"], "RepairRequested");
    assert_eq!(repaired[2]["payload"]["task_id"], recoverable["task_id"]);
    assert_eq!(
        database
            .repair_commands(first["run_id"].as_str().unwrap())
            .await,
        0
    );
    assert_eq!(
        database
            .repair_commands(second["run_id"].as_str().unwrap())
            .await,
        1
    );
    let refused = finish_task(&app, &recoverable, commands.clone()).await;
    assert_eq!(refused.0, StatusCode::CONFLICT);
    assert_eq!(refused.1["reason"], "task_not_leased");
    registration(&app, "pressure-recovery-worker").await;
    let recovered = poll(&app, "pressure-recovery-worker", "workflow").await;
    assert_eq!(recovered["task_id"], recoverable["task_id"]);
    assert_eq!(recovered["run_id"], recoverable["run_id"]);
    assert_eq!(recovered["workflow_id"], second["workflow_id"]);
    assert_eq!(recovered["workflow_task_attempt"], 2);
    let replacement = registration(&app, worker).await;
    assert_ne!(
        replacement["registration_token"],
        accepted["registration_token"]
    );
    assert_eq!(
        deregister(&app, worker, &accepted).await,
        receipt,
        "same-token replay precedes replacement authority"
    );
    let stale = finish_task(&app, &recoverable, commands.clone()).await;
    assert_eq!(stale.0, StatusCode::CONFLICT);
    assert_eq!(stale.1["reason"], "workflow_task_attempt_mismatch");
    assert_eq!(
        run_history(&app, &second["workflow_id"], &second["run_id"]).await,
        repaired
    );
    assert_eq!(
        finish_task(&app, &recovered, commands).await.0,
        StatusCode::OK,
        "receipt replay preserves the actual recovered task lease"
    );
    let completed = run_history(&app, &second["workflow_id"], &second["run_id"]).await;
    assert_eq!(completed.as_array().unwrap().len(), 4);
    assert_eq!(completed[3]["event_type"], "WorkflowCompleted");
    assert_eq!(
        completed[3]["payload"]["task"]["id"],
        recoverable["task_id"]
    );
    assert_eq!(completed[3]["payload"]["task"]["attempt_count"], 2);
    assert_eq!(completed[3]["payload"]["task"]["repair_count"], 1);
    let expected = envelope(Payload::Long(9007199254740993));
    assert_eq!(completed[3]["payload"]["output"], expected);
    assert!(
        poll(&app, "pressure-recovery-worker", "workflow")
            .await
            .is_null()
    );
    assert_eq!(
        deregister(&app, worker, &replacement).await.1["recovered_workflow_task_count"],
        0
    );
    runtime.close().await;
    drop(app);
    database.remove().await;
}
