//! Worker history pages and activity status checks change nothing, so they
//! must answer while a transition holds the backend's transition lock.
use super::*;
use sqlx::Connection;

enum Writer {
    Sqlite(sqlx::SqliteConnection),
    Postgres(sqlx::PgConnection),
    MySql(sqlx::MySqlConnection),
}

// Takes the lock a native transition takes, on a connection outside the
// runtime's pool, as a transition from another server process would.
async fn hold_transition_lock(database: &TestDatabase) -> Writer {
    match database {
        TestDatabase::Sqlite(dir) => {
            let mut connection = sqlx::SqliteConnection::connect(&format!(
                "sqlite://{}",
                dir.path().join("runtime.sqlite").display()
            ))
            .await
            .unwrap();
            sqlx::raw_sql("BEGIN IMMEDIATE")
                .execute(&mut connection)
                .await
                .unwrap();
            Writer::Sqlite(connection)
        }
        TestDatabase::Postgres { options, .. } => {
            let mut connection = sqlx::PgConnection::connect_with(options.as_ref())
                .await
                .unwrap();
            sqlx::raw_sql("BEGIN; SELECT pg_advisory_xact_lock(4924501473315676163)")
                .execute(&mut connection)
                .await
                .unwrap();
            Writer::Postgres(connection)
        }
        TestDatabase::MySql { options, .. } => {
            let mut connection = sqlx::MySqlConnection::connect_with(options.as_ref())
                .await
                .unwrap();
            sqlx::raw_sql("START TRANSACTION")
                .execute(&mut connection)
                .await
                .unwrap();
            sqlx::raw_sql(
                "SELECT engine FROM dw_server_schema WHERE engine='rust-development' FOR UPDATE",
            )
            .execute(&mut connection)
            .await
            .unwrap();
            Writer::MySql(connection)
        }
    }
}

impl Writer {
    async fn release(self) {
        match self {
            Self::Sqlite(mut connection) => {
                sqlx::raw_sql("ROLLBACK")
                    .execute(&mut connection)
                    .await
                    .unwrap();
                connection.close().await.unwrap();
            }
            Self::Postgres(mut connection) => {
                sqlx::raw_sql("ROLLBACK")
                    .execute(&mut connection)
                    .await
                    .unwrap();
                connection.close().await.unwrap();
            }
            Self::MySql(mut connection) => {
                sqlx::raw_sql("ROLLBACK")
                    .execute(&mut connection)
                    .await
                    .unwrap();
                connection.close().await.unwrap();
            }
        }
    }
}

#[tokio::test]
async fn worker_reads_answer_while_a_transition_holds_the_lock() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "snapshot-worker", json!(["echo"]), json!(["echo-activity"])).await;
    start(&app, "snapshot-activity").await;
    let scheduling = poll(&app, "snapshot-worker", "workflow").await;
    let scheduled = finish_task(
        &app,
        &scheduling,
        json!([{"type":"schedule_activity","activity_type":"echo-activity",
            "arguments":envelope(Payload::Array(vec![]))}]),
    )
    .await;
    assert_eq!(scheduled.0, StatusCode::OK, "{}", scheduled.1);
    let activity = poll(&app, "snapshot-worker", "activity").await;
    assert!(!activity.is_null());
    start(&app, "snapshot-history").await;
    let workflow = poll(&app, "snapshot-worker", "workflow").await;
    assert!(!workflow.is_null());

    let writer = hold_transition_lock(&database).await;
    let blocked = {
        let app = app.clone();
        tokio::spawn(async move { start(&app, "snapshot-blocked").await })
    };
    tokio::time::sleep(std::time::Duration::from_millis(200)).await;
    assert!(!blocked.is_finished(), "a transition waits for the held lock");
    // Every backend's lock wait outlasts this bound, so it is decisive.
    let decisive = std::time::Duration::from_secs(1);
    let history = tokio::time::timeout(
        decisive,
        request_protocol(
            &app,
            "POST",
            &format!(
                "/api/worker/workflow-tasks/{}/history",
                workflow["task_id"].as_str().unwrap()
            ),
            json!({"lease_owner":workflow["lease_owner"],
                "workflow_task_attempt":workflow["workflow_task_attempt"]}),
            "1.20",
        ),
    )
    .await
    .expect("a worker history page must not wait for the transition lock");
    assert_eq!(history.0, StatusCode::OK, "{}", history.1);
    assert!(
        history.1["history_events"]
            .as_array()
            .unwrap()
            .iter()
            .any(|event| event["event_type"] == "WorkflowStarted")
    );
    let status = tokio::time::timeout(
        decisive,
        request_protocol(
            &app,
            "POST",
            &format!(
                "/api/worker/activity-tasks/{}/status",
                activity["task_id"].as_str().unwrap()
            ),
            json!({"activity_attempt_id":activity["activity_attempt_id"],
                "lease_owner":activity["lease_owner"]}),
            "1.20",
        ),
    )
    .await
    .expect("an activity status check must not wait for the transition lock");
    assert_eq!(status.0, StatusCode::OK, "{}", status.1);
    assert_eq!(status.1["can_continue"], true);
    assert_eq!(status.1["heartbeat_recorded"], false);
    assert!(!blocked.is_finished(), "the lock was held for both reads");
    writer.release().await;
    blocked.await.unwrap();
    runtime.close().await;
    database.remove().await;
}
