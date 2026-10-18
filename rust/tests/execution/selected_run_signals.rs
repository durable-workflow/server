use super::*;
use namespaces::{create, scoped, start_scoped};
use sqlx::Row;

impl TestDatabase {
    async fn signal_state(&self, run: &str) -> Vec<i64> {
        // Independent physical observation: no public projection is trusted
        // to prove that a refused command did or did not mutate storage.
        let queries = [
            "SELECT COUNT(*) FROM workflow_commands WHERE workflow_run_id=$1",
            "SELECT COUNT(*) FROM workflow_signal_records WHERE workflow_run_id=$1",
            "SELECT COUNT(*) FROM workflow_history_events WHERE workflow_run_id=$1",
            "SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id=$1",
            "SELECT CAST(last_command_sequence AS BIGINT) FROM workflow_runs WHERE id=$1",
            "SELECT CAST(last_message_sequence AS BIGINT) FROM workflow_instances WHERE current_run_id=$1",
        ];
        macro_rules! observe {
            ($pool:expr, $mysql:expr) => {{
                let pool = $pool;
                let mut state = Vec::new();
                for query in queries {
                    let query = if $mysql {
                        query.replace("$1", "?").replace("AS BIGINT", "AS SIGNED")
                    } else {
                        query.into()
                    };
                    state.push(
                        sqlx::query_scalar::<_, i64>(sqlx::AssertSqlSafe(query))
                            .bind(run)
                            .fetch_one(&pool)
                            .await
                            .unwrap(),
                    );
                }
                pool.close().await;
                state
            }};
        }
        match self {
            Self::Sqlite(dir) => observe!(
                sqlx::SqlitePool::connect(&format!(
                    "sqlite://{}",
                    dir.path().join("runtime.sqlite").display()
                ))
                .await
                .unwrap(),
                false
            ),
            Self::Postgres { options, .. } => observe!(
                sqlx::postgres::PgPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap(),
                false
            ),
            Self::MySql { options, .. } => observe!(
                sqlx::mysql::MySqlPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap(),
                true
            ),
        }
    }

    async fn rejected_signal(&self, command: &str, arguments: Payload, errors: Payload) {
        let query = "SELECT c.status,c.outcome,c.rejection_reason,c.payload,s.status AS signal_status,s.arguments,s.signal_wait_id,s.workflow_sequence FROM workflow_commands c JOIN workflow_signal_records s ON s.workflow_command_id=c.id WHERE c.id=$1 AND c.accepted_at IS NULL AND c.applied_at IS NULL AND c.rejected_at IS NOT NULL AND s.received_at IS NULL AND s.applied_at IS NULL AND s.rejected_at IS NOT NULL AND s.closed_at IS NOT NULL";
        macro_rules! inspect {
            ($pool:expr, $mysql:expr) => {{
                let pool = $pool;
                let query = if $mysql {
                    query.replace("$1", "?")
                } else {
                    query.into()
                };
                let row = sqlx::query(sqlx::AssertSqlSafe(query))
                    .bind(command)
                    .fetch_one(&pool)
                    .await
                    .unwrap();
                assert_eq!(row.get::<String, _>("status"), "rejected");
                assert_eq!(row.get::<String, _>("signal_status"), "rejected");
                assert!(row.get::<Option<String>, _>("signal_wait_id").is_none());
                let codec = ValueCodec::new().unwrap();
                assert_eq!(
                    codec.decode(&row.get::<String, _>("arguments")).unwrap(),
                    arguments
                );
                let Payload::Map(payload) = codec.decode(&row.get::<String, _>("payload")).unwrap()
                else {
                    panic!("command payload must be a map")
                };
                assert_eq!(payload["arguments"], arguments);
                assert_eq!(payload["validation_errors"], errors);
                pool.close().await;
            }};
        }
        match self {
            Self::Sqlite(dir) => inspect!(
                sqlx::SqlitePool::connect(&format!(
                    "sqlite://{}",
                    dir.path().join("runtime.sqlite").display()
                ))
                .await
                .unwrap(),
                false
            ),
            Self::Postgres { options, .. } => inspect!(
                sqlx::postgres::PgPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap(),
                false
            ),
            Self::MySql { options, .. } => inspect!(
                sqlx::mysql::MySqlPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap(),
                true
            ),
        }
    }
}

#[tokio::test]
async fn selected_run_guards_and_durable_rejections_preserve_original_execution() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    create(&app, "alpha").await;
    create(&app, "beta").await;
    let registration = scoped(&app, "alpha", "POST", "/api/worker/register", json!({
        "worker_id":"selected-worker","task_queue":"test","runtime":"php",
        "supported_workflow_types":["echo"],"supported_activity_types":[],
        "workflow_command_contracts":{"echo":{"signals":["data"],"signal_contracts":[{
            "name":"data","parameters":[{"name":"value","position":0,"type":"int",
                "required":true,"variadic":false,"default_available":false,"allows_null":false}]}]}}
    })).await;
    assert_eq!(registration.0, StatusCode::CREATED, "{}", registration.1);
    let started = start_scoped(&app, "alpha", "selected-original").await;
    let run = started["run_id"].as_str().unwrap();
    let task = scoped(
        &app,
        "alpha",
        "POST",
        "/api/worker/workflow-tasks/poll",
        json!({
            "worker_id":"selected-worker","task_queue":"test","timeout_seconds":0
        }),
    )
    .await
    .1["task"]
        .clone();
    let opened = scoped(&app, "alpha", "POST", &format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap()),
        completion(&task, json!([{"type":"open_condition_wait","condition_key":"data:0","condition_definition_fingerprint":format!("sha256:{}", "a".repeat(64))}]))).await;
    assert_eq!(opened.0, StatusCode::OK, "{}", opened.1);
    let initial = database.signal_state(run).await;
    let path = format!("/api/workflows/selected-original/runs/{run}/signal/data");
    let input = envelope(Payload::Array(vec![Payload::Long(9007199254740993)]));
    let foreign = scoped(&app, "beta", "POST", &path, json!({"input":input})).await;
    assert_eq!(foreign.0, StatusCode::NOT_FOUND, "{}", foreign.1);
    assert_eq!(foreign.1["control_plane"]["operation"], "signal");
    assert_eq!(database.signal_state(run).await, initial);
    let mismatch = scoped(
        &app,
        "alpha",
        "POST",
        "/api/workflows/selected-original/runs/not-current/signal/data",
        json!({"input":input}),
    )
    .await;
    assert_eq!(mismatch.0, StatusCode::CONFLICT, "{}", mismatch.1);
    assert_eq!(mismatch.1["reason"], "historical_run_command_rejected");
    assert_eq!(mismatch.1["target_scope"], "run");
    assert_eq!(database.signal_state(run).await, initial);
    for (index, (name, arguments, status, reason, errors)) in [
        (
            "undeclared",
            Payload::Array(vec![Payload::Long(9007199254740993)]),
            StatusCode::NOT_FOUND,
            "unknown_signal",
            Payload::Array(vec![]),
        ),
        (
            "data",
            Payload::Array(vec![]),
            StatusCode::UNPROCESSABLE_ENTITY,
            "invalid_signal_arguments",
            Payload::Map(std::collections::BTreeMap::from([(
                "value".into(),
                Payload::Array(vec![Payload::String(
                    "The value argument is required.".into(),
                )]),
            )])),
        ),
    ]
    .into_iter()
    .enumerate()
    {
        let response = scoped(
            &app,
            "alpha",
            "POST",
            &format!("/api/workflows/selected-original/runs/{run}/signal/{name}"),
            json!({"input":envelope(arguments.clone())}),
        )
        .await;
        assert_eq!(response.0, status, "{}", response.1);
        assert_eq!(response.1["reason"], reason);
        assert_eq!(response.1["command_status"], "rejected");
        assert_eq!(response.1["target_scope"], "instance");
        assert!(response.1["requested_run_id"].is_null());
        assert_eq!(response.1["control_plane"]["operation"], "signal");
        let mut expected = initial.clone();
        for position in [0, 1, 4, 5] {
            expected[position] += (index + 1) as i64;
        }
        assert_eq!(database.signal_state(run).await, expected);
        database
            .rejected_signal(
                response.1["command_id"].as_str().unwrap(),
                arguments,
                errors,
            )
            .await;
        let described = scoped(
            &app,
            "alpha",
            "GET",
            &format!("/api/workflows/selected-original/runs/{run}"),
            Value::Null,
        )
        .await;
        assert_eq!(described.0, StatusCode::OK, "{}", described.1);
        assert_eq!(described.1["commands_scope"], "selected_run");
        let journal = described.1["commands"].as_array().unwrap();
        assert_eq!(journal.len(), index + 2);
        assert_eq!(journal.last().unwrap()["id"], response.1["command_id"]);
        assert_eq!(journal.last().unwrap()["status"], "rejected");
        assert_eq!(journal.last().unwrap()["target_name"], name);
        assert_eq!(journal.last().unwrap()["rejection_reason"], reason);
    }
    let accepted = scoped(&app, "alpha", "POST", &path, json!({"input":input})).await;
    assert_eq!(accepted.0, StatusCode::ACCEPTED, "{}", accepted.1);
    assert_eq!(accepted.1["command_sequence"], 4);
    assert_eq!(accepted.1["control_plane"]["resolved_run_id"], run);
    let resumed = scoped(
        &app,
        "alpha",
        "POST",
        "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"selected-worker","task_queue":"test","timeout_seconds":0}),
    )
    .await
    .1["task"]
        .clone();
    let completed = scoped(&app, "alpha", "POST", &format!("/api/worker/workflow-tasks/{}/complete", resumed["task_id"].as_str().unwrap()),
        completion(&resumed, json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}]))).await;
    assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
    let before_closed = database.signal_state(run).await;
    let inactive = scoped(&app, "alpha", "POST", &path, json!({"input":input})).await;
    assert_eq!(inactive.0, StatusCode::CONFLICT, "{}", inactive.1);
    assert_eq!(inactive.1["reason"], "run_not_active");
    assert_eq!(inactive.1["command_sequence"], 5);
    let mut expected = before_closed;
    for position in [0, 1, 4, 5] {
        expected[position] += 1;
    }
    assert_eq!(database.signal_state(run).await, expected);
    database
        .rejected_signal(
            inactive.1["command_id"].as_str().unwrap(),
            Payload::Array(vec![Payload::Long(9007199254740993)]),
            Payload::Array(vec![]),
        )
        .await;
    runtime.close().await;
    database.remove().await;
}
