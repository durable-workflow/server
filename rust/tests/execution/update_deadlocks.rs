//! Server #960: update admission when InnoDB picks it as a deadlock victim,
//! on MySQL and MariaDB. Other backends return at once.
//!
//! A test connection locks the run's instance row and waits until
//! `admit_update`, holding the run row, waits for it. The test connection
//! then updates the run row, which closes a real two-connection cycle. The
//! test connection has already inserted many rows, so InnoDB rolls back
//! `admit_update`, the smaller transaction.
//!
//! Decision recorded here: keep the plain 503 `storage_unavailable`. The
//! deadlock victim rolls back as a whole and records nothing, so the answer
//! is safe to retry. A retry under the same `request_id` admits the update
//! once. The Python SDK's transport retry policy already retries 5xx answers
//! and resends the same command key. An in-process retry or a typed
//! `backend_lock_pressure` answer would add a second retry contract without
//! changing that outcome.
use super::update_validation::{complete, poll_validation, send_update, update_body, validating_worker, waiting_run};
use super::*;
use sqlx::{AssertSqlSafe, Connection};
use std::time::Duration;

/// A test transaction that has written many rows and holds the instance row.
struct Holder(sqlx::MySqlConnection);

impl Holder {
    async fn lock_instance(options: &MySqlConnectOptions, workflow: &str) -> Self {
        let mut connection = sqlx::MySqlConnection::connect_with(options).await.unwrap();
        sqlx::raw_sql("CREATE TABLE IF NOT EXISTS dw_test_deadlock_weight (id INT PRIMARY KEY)").execute(&mut connection).await.unwrap();
        sqlx::raw_sql("START TRANSACTION").execute(&mut connection).await.unwrap();
        let rows: Vec<String> = (1..=500).map(|id| format!("({id})")).collect();
        sqlx::raw_sql(AssertSqlSafe(format!("INSERT INTO dw_test_deadlock_weight (id) VALUES {}", rows.join(","))))
            .execute(&mut connection).await.unwrap();
        sqlx::query("UPDATE workflow_instances SET last_message_sequence=last_message_sequence WHERE id=?")
            .bind(workflow).execute(&mut connection).await.unwrap();
        assert_eq!(sqlx::query_scalar::<_, i64>("SELECT COUNT(*) FROM workflow_instances WHERE id=?").bind(workflow)
            .fetch_one(&mut connection).await.unwrap(), 1);
        Self(connection)
    }

    /// Returns once the admission runs its instance-row update, which only
    /// this test's lock keeps from finishing. InnoDB's own lock tables are a
    /// cache that concurrent readers can keep from refreshing.
    async fn until_admission_waits(&mut self, pending: &mut tokio::task::JoinHandle<(StatusCode, Value)>) {
        let deadline = tokio::time::Instant::now() + Duration::from_secs(4);
        loop {
            let waiting: i64 = sqlx::query_scalar(
                "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB=DATABASE() AND ID<>CONNECTION_ID() \
                 AND INFO LIKE 'UPDATE workflow_instances SET last_message_sequence%'",
            )
            .fetch_one(&mut self.0)
            .await
            .unwrap();
            if waiting == 1 {
                return;
            }
            if pending.is_finished() {
                panic!("the update answered without waiting: {:?}", pending.await.unwrap());
            }
            assert!(tokio::time::Instant::now() < deadline, "admit_update never waited on the instance row");
            tokio::time::sleep(Duration::from_millis(20)).await;
        }
    }

    /// Closes the cycle. InnoDB rolls back the waiting admission, so this
    /// statement completes. The test transaction then rolls back too.
    async fn close_cycle(mut self, run: &str) {
        sqlx::query("UPDATE workflow_runs SET last_command_sequence=last_command_sequence WHERE id=?")
            .bind(run).execute(&mut self.0).await.expect("the admission is the deadlock victim");
        sqlx::raw_sql("ROLLBACK").execute(&mut self.0).await.unwrap();
    }
}

/// The run's update rows, update commands and `UpdateAccepted` events.
async fn recorded(options: &MySqlConnectOptions, run: &Value) -> (i64, i64, i64) {
    let mut connection = sqlx::MySqlConnection::connect_with(options).await.unwrap();
    let mut counts = Vec::new();
    for sql in [
        "SELECT COUNT(*) FROM workflow_updates WHERE workflow_run_id=?",
        "SELECT COUNT(*) FROM workflow_commands WHERE workflow_run_id=? AND command_type='update'",
        "SELECT COUNT(*) FROM workflow_history_events WHERE workflow_run_id=? AND event_type='UpdateAccepted'",
    ] {
        counts.push(sqlx::query_scalar::<_, i64>(sql).bind(run.as_str().unwrap()).fetch_one(&mut connection).await.unwrap());
    }
    (counts[0], counts[1], counts[2])
}

fn storage_unavailable(answer: &(StatusCode, Value)) {
    assert_eq!(answer.0, StatusCode::SERVICE_UNAVAILABLE, "{}", answer.1);
    assert_eq!(answer.1["reason"], "storage_unavailable", "{}", answer.1);
}

#[tokio::test]
async fn a_deadlocked_direct_update_admission_rolls_back_and_its_retry_admits_once() {
    let database = TestDatabase::new().await;
    let TestDatabase::MySql { options, .. } = &database else { return };
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    validating_worker(&app, "deadlock-worker", "deadlock-direct", Some(json!([])), false).await;
    let started = waiting_run(&app, "deadlock-direct", "deadlock-direct", "deadlock-worker").await;

    let mut holder = Holder::lock_instance(options, "deadlock-direct").await;
    let mut pending = send_update(&app, "deadlock-direct", "plain", update_body(1, Some("deadlock-direct-1")));
    holder.until_admission_waits(&mut pending).await;
    holder.close_cycle(started["run_id"].as_str().unwrap()).await;
    storage_unavailable(&pending.await.unwrap());
    assert_eq!(recorded(options, &started["run_id"]).await, (0, 0, 0), "the victim committed nothing");

    // The same request ID admits the update once, and repeats retain it.
    let (status, admitted) = send_update(&app, "deadlock-direct", "plain", update_body(1, Some("deadlock-direct-1"))).await.unwrap();
    assert_eq!(status, StatusCode::ACCEPTED, "{admitted}");
    let (status, retained) = send_update(&app, "deadlock-direct", "plain", update_body(1, Some("deadlock-direct-1"))).await.unwrap();
    assert_eq!((status, &retained["update_id"]), (StatusCode::ACCEPTED, &admitted["update_id"]), "{retained}");
    assert_eq!(recorded(options, &started["run_id"]).await, (1, 1, 1));
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_deadlocked_validator_approved_admission_rolls_back_and_its_retry_admits_once() {
    let database = TestDatabase::new().await;
    let TestDatabase::MySql { options, .. } = &database else { return };
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    validating_worker(&app, "deadlock-validator", "deadlock-validated", Some(json!(["approve_me"])), true).await;
    let started = waiting_run(&app, "deadlock-validated", "deadlock-validated", "deadlock-validator").await;

    // The validation waits outside the admission transaction. The approved
    // admission is the transaction that deadlocks.
    let mut pending = send_update(&app, "deadlock-validated", "approve_me", update_body(3, Some("deadlock-validated-1")));
    let task = poll_validation(&app, "deadlock-validator", "deadlock-validated").await;
    let id = task["update_validation_task_id"].as_str().unwrap().to_owned();
    let mut holder = Holder::lock_instance(options, "deadlock-validated").await;
    let (status, approved) = complete(&app, &id, "approve", json!({"lease_owner":"deadlock-validator","update_validation_attempt":1})).await;
    assert_eq!(status, StatusCode::OK, "{approved}");
    holder.until_admission_waits(&mut pending).await;
    holder.close_cycle(started["run_id"].as_str().unwrap()).await;
    storage_unavailable(&pending.await.unwrap());
    assert_eq!(recorded(options, &started["run_id"]).await, (0, 0, 0), "the victim committed nothing");

    // The retry admits the approved update once, under the validation task's id.
    let (status, admitted) = send_update(&app, "deadlock-validated", "approve_me", update_body(3, Some("deadlock-validated-1"))).await.unwrap();
    assert_eq!(status, StatusCode::ACCEPTED, "{admitted}");
    assert_eq!(admitted["update_id"], json!(id), "{admitted}");
    let (status, retained) = send_update(&app, "deadlock-validated", "approve_me", update_body(3, Some("deadlock-validated-1"))).await.unwrap();
    assert_eq!((status, &retained["update_id"]), (StatusCode::ACCEPTED, &json!(id)), "{retained}");
    assert_eq!(recorded(options, &started["run_id"]).await, (1, 1, 1));
    runtime.close().await;
    database.remove().await;
}
