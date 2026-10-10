use axum::{
    Router,
    body::{Body, to_bytes},
    http::{Request, StatusCode},
};
use durable_workflow_server::{
    codec::{Value as Payload, ValueCodec},
    runtime::{Runtime, router},
};
use serde_json::{Value, json};
use tower::ServiceExt;

use sqlx::{
    ConnectOptions, MySql, Postgres, migrate::MigrateDatabase, mysql::MySqlConnectOptions,
    postgres::PgConnectOptions,
};
use std::str::FromStr;

enum TestDatabase {
    Sqlite(tempfile::TempDir),
    Postgres {
        options: Box<PgConnectOptions>,
        url: String,
    },
    MySql {
        options: Box<MySqlConnectOptions>,
        url: String,
    },
}

impl TestDatabase {
    async fn new() -> Self {
        assert!(
            !(std::env::var_os("DW_EXECUTION_POSTGRES_URL").is_some()
                && std::env::var_os("DW_EXECUTION_MYSQL_URL").is_some()),
            "Select one execution backend per run"
        );
        if let Ok(url) = std::env::var("DW_EXECUTION_MYSQL_URL") {
            let name = format!(
                "dw_execution_{}",
                ulid::Ulid::new().to_string().to_lowercase()
            );
            let options = MySqlConnectOptions::from_str(&url)
                .unwrap()
                .database(&name)
                .timezone(Some("+00:00".into()));
            let url = options.to_url_lossy().to_string();
            MySql::create_database(&url).await.unwrap();
            return Self::MySql {
                options: Box::new(options),
                url,
            };
        }
        match std::env::var("DW_EXECUTION_POSTGRES_URL") {
            Ok(url) => {
                let name = format!(
                    "dw_execution_{}",
                    ulid::Ulid::new().to_string().to_lowercase()
                );
                let options = PgConnectOptions::from_str(&url).unwrap().database(&name);
                let url = options.to_url_lossy().to_string();
                Postgres::create_database(&url).await.unwrap();
                Self::Postgres {
                    options: Box::new(options),
                    url,
                }
            }
            Err(_) => Self::Sqlite(tempfile::tempdir().unwrap()),
        }
    }

    async fn open(&self) -> Result<Runtime, durable_workflow_server::runtime::RuntimeError> {
        match self {
            Self::Sqlite(dir) => {
                Runtime::open(
                    dir.path().join("runtime.sqlite").to_str().unwrap(),
                    "test-token".into(),
                )
                .await
            }
            Self::Postgres { options, .. } => {
                Runtime::open_postgres(options.as_ref().clone(), "test-token".into()).await
            }
            Self::MySql { options, .. } => {
                Runtime::open_mysql(options.as_ref().clone(), "test-token".into()).await
            }
        }
    }

    async fn failure_count(&self, activity_id: &str, failure_id: &str, non_retryable: bool) -> i64 {
        let query = "SELECT COUNT(*) FROM workflow_failures WHERE source_id=$1 AND id=$2 AND non_retryable=$3 AND source_kind='activity_execution' AND propagation_kind='activity' AND failure_category='activity' AND handled=false";
        match self {
            Self::Sqlite(dir) => {
                let pool = sqlx::SqlitePool::connect(&format!(
                    "sqlite://{}",
                    dir.path().join("runtime.sqlite").display()
                ))
                .await
                .unwrap();
                let count = sqlx::query_scalar(query)
                    .bind(activity_id)
                    .bind(failure_id)
                    .bind(non_retryable)
                    .fetch_one(&pool)
                    .await
                    .unwrap();
                pool.close().await;
                count
            }
            Self::Postgres { options, .. } => {
                let pool = sqlx::postgres::PgPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap();
                let count = sqlx::query_scalar(query)
                    .bind(activity_id)
                    .bind(failure_id)
                    .bind(non_retryable)
                    .fetch_one(&pool)
                    .await
                    .unwrap();
                pool.close().await;
                count
            }
            Self::MySql { options, .. } => {
                let pool = sqlx::mysql::MySqlPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap();
                let count = sqlx::query_scalar("SELECT COUNT(*) FROM workflow_failures WHERE source_id=? AND id=? AND non_retryable=? AND source_kind='activity_execution' AND propagation_kind='activity' AND failure_category='activity' AND handled=false")
                    .bind(activity_id)
                    .bind(failure_id)
                    .bind(non_retryable)
                    .fetch_one(&pool)
                    .await
                    .unwrap();
                pool.close().await;
                count
            }
        }
    }

    async fn cancellation_failure_count(&self, run: &str, failure: &str) -> i64 {
        let query = "SELECT COUNT(*) FROM workflow_failures WHERE workflow_run_id=$1 AND source_id=$2 AND id=$3 AND source_kind='workflow_run' AND propagation_kind='cancelled' AND failure_category='cancelled' AND handled=false";
        match self {
            Self::Sqlite(dir) => {
                let pool = sqlx::SqlitePool::connect(&format!(
                    "sqlite://{}",
                    dir.path().join("runtime.sqlite").display()
                ))
                .await
                .unwrap();
                let count = sqlx::query_scalar(query)
                    .bind(run)
                    .bind(run)
                    .bind(failure)
                    .fetch_one(&pool)
                    .await
                    .unwrap();
                pool.close().await;
                count
            }
            Self::Postgres { options, .. } => {
                let pool = sqlx::postgres::PgPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap();
                let count = sqlx::query_scalar(query)
                    .bind(run)
                    .bind(run)
                    .bind(failure)
                    .fetch_one(&pool)
                    .await
                    .unwrap();
                pool.close().await;
                count
            }
            Self::MySql { options, .. } => {
                let pool = sqlx::mysql::MySqlPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap();
                let count = sqlx::query_scalar("SELECT COUNT(*) FROM workflow_failures WHERE workflow_run_id=? AND source_id=? AND id=? AND source_kind='workflow_run' AND propagation_kind='cancelled' AND failure_category='cancelled' AND handled=false")
                    .bind(run).bind(run).bind(failure).fetch_one(&pool).await.unwrap();
                pool.close().await;
                count
            }
        }
    }

    async fn cancellation_failure_message(&self, failure: &str) -> String {
        let query = "SELECT message FROM workflow_failures WHERE id=$1";
        match self {
            Self::Sqlite(dir) => {
                let pool = sqlx::SqlitePool::connect(&format!(
                    "sqlite://{}",
                    dir.path().join("runtime.sqlite").display()
                ))
                .await
                .unwrap();
                let message = sqlx::query_scalar(query)
                    .bind(failure)
                    .fetch_one(&pool)
                    .await
                    .unwrap();
                pool.close().await;
                message
            }
            Self::Postgres { options, .. } => {
                let pool = sqlx::postgres::PgPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap();
                let message = sqlx::query_scalar(query)
                    .bind(failure)
                    .fetch_one(&pool)
                    .await
                    .unwrap();
                pool.close().await;
                message
            }
            Self::MySql { options, .. } => {
                let pool = sqlx::mysql::MySqlPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap();
                let message =
                    sqlx::query_scalar("SELECT message FROM workflow_failures WHERE id=?")
                        .bind(failure)
                        .fetch_one(&pool)
                        .await
                        .unwrap();
                pool.close().await;
                message
            }
        }
    }

    async fn execute(&self, statement: &'static str) {
        match self {
            Self::Sqlite(dir) => {
                let pool = sqlx::SqlitePool::connect(&format!(
                    "sqlite://{}",
                    dir.path().join("runtime.sqlite").display()
                ))
                .await
                .unwrap();
                sqlx::query(statement).execute(&pool).await.unwrap();
                pool.close().await;
            }
            Self::Postgres { options, .. } => {
                let pool = sqlx::postgres::PgPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap();
                sqlx::query(statement).execute(&pool).await.unwrap();
                pool.close().await;
            }
            Self::MySql { options, .. } => {
                let pool = sqlx::mysql::MySqlPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap();
                sqlx::query(statement).execute(&pool).await.unwrap();
                pool.close().await;
            }
        }
    }

    async fn remove(self) {
        match self {
            Self::Postgres { url, .. } => Postgres::drop_database(&url).await.unwrap(),
            Self::MySql { url, .. } => MySql::drop_database(&url).await.unwrap(),
            Self::Sqlite(_) => {}
        }
    }

    async fn replace_history_payload(&self, payload: Value) {
        match self {
            Self::Sqlite(dir) => {
                let pool = sqlx::SqlitePool::connect(&format!(
                    "sqlite://{}",
                    dir.path().join("runtime.sqlite").display()
                ))
                .await
                .unwrap();
                sqlx::query("UPDATE workflow_history_events SET payload=$1 WHERE sequence=1")
                    .bind(payload.to_string())
                    .execute(&pool)
                    .await
                    .unwrap();
                pool.close().await;
            }
            Self::Postgres { options, .. } => {
                let pool = sqlx::postgres::PgPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap();
                sqlx::query("UPDATE workflow_history_events SET payload=$1 WHERE sequence=1")
                    .bind(sqlx::types::Json(payload))
                    .execute(&pool)
                    .await
                    .unwrap();
                pool.close().await;
            }
            Self::MySql { options, .. } => {
                let pool = sqlx::mysql::MySqlPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap();
                sqlx::query("UPDATE workflow_history_events SET payload=? WHERE sequence=1")
                    .bind(sqlx::types::Json(payload))
                    .execute(&pool)
                    .await
                    .unwrap();
                pool.close().await;
            }
        }
    }

    async fn set_availability(&self, kind: &str, value: Option<chrono::DateTime<chrono::Utc>>) {
        match self {
            Self::Sqlite(dir) => {
                let pool = sqlx::SqlitePool::connect(&format!(
                    "sqlite://{}",
                    dir.path().join("runtime.sqlite").display()
                ))
                .await
                .unwrap();
                sqlx::query("UPDATE workflow_tasks SET available_at=$1 WHERE task_type=$2")
                    .bind(value.map(|instant| instant.to_rfc3339()))
                    .bind(kind)
                    .execute(&pool)
                    .await
                    .unwrap();
                pool.close().await;
            }
            Self::Postgres { options, .. } => {
                let pool = sqlx::postgres::PgPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap();
                sqlx::query("UPDATE workflow_tasks SET available_at=$1 WHERE task_type=$2")
                    .bind(value.map(|instant| instant.naive_utc()))
                    .bind(kind)
                    .execute(&pool)
                    .await
                    .unwrap();
                pool.close().await;
            }
            Self::MySql { options, .. } => {
                let pool = sqlx::mysql::MySqlPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap();
                sqlx::query("UPDATE workflow_tasks SET available_at=? WHERE task_type=?")
                    .bind(value)
                    .bind(kind)
                    .execute(&pool)
                    .await
                    .unwrap();
                pool.close().await;
            }
        }
    }

    async fn set_time(&self, started_at: bool, value: &str) {
        let statement = if started_at {
            "UPDATE workflow_runs SET started_at=$1"
        } else {
            "UPDATE workflow_tasks SET lease_expires_at=$1"
        };
        let value = chrono::DateTime::parse_from_rfc3339(value)
            .unwrap()
            .naive_utc();
        match self {
            Self::Sqlite(dir) => {
                let pool = sqlx::SqlitePool::connect(&format!(
                    "sqlite://{}",
                    dir.path().join("runtime.sqlite").display()
                ))
                .await
                .unwrap();
                sqlx::query(statement)
                    // The frozen PHP Workflow models store microsecond SQL
                    // timestamps. Ordinary PHP/native databases stay separate;
                    // this reproduces only that representation in native data.
                    .bind(value.format("%Y-%m-%d %H:%M:%S%.6f").to_string())
                    .execute(&pool)
                    .await
                    .unwrap();
                pool.close().await;
            }
            Self::Postgres { options, .. } => {
                let pool = sqlx::postgres::PgPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap();
                sqlx::query(statement)
                    .bind(value)
                    .execute(&pool)
                    .await
                    .unwrap();
                pool.close().await;
            }
            Self::MySql { options, .. } => {
                let statement = if started_at {
                    "UPDATE workflow_runs SET started_at=?"
                } else {
                    "UPDATE workflow_tasks SET lease_expires_at=?"
                };
                let pool = sqlx::mysql::MySqlPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap();
                sqlx::query(statement)
                    .bind(value)
                    .execute(&pool)
                    .await
                    .unwrap();
                pool.close().await;
            }
        }
    }
}

#[tokio::test]
async fn simultaneous_starts_and_completions_commit_one_durable_outcome() {
    let database = TestDatabase::new().await;
    let node_a = database.open().await.unwrap();
    let node_b = database.open().await.unwrap();
    let app_a = router(node_a.clone());
    let app_b = router(node_b.clone());
    let body = json!({"workflow_id":"concurrent","workflow_type":"echo","task_queue":"test","input":envelope(Payload::Null)});
    let (a, b) = tokio::join!(
        request(&app_a, "POST", "/api/workflows", body.clone()),
        request(&app_b, "POST", "/api/workflows", body)
    );
    assert!(
        matches!(
            (a.0, b.0),
            (StatusCode::CREATED, StatusCode::CONFLICT)
                | (StatusCode::CONFLICT, StatusCode::CREATED)
        ),
        "{a:?} {b:?}"
    );
    register(&app_a, "worker", json!(["echo"]), json!([])).await;
    let task = poll(&app_a, "worker", "workflow").await;
    let body = completion(
        &task,
        json!([{"type":"complete_workflow","result":envelope(Payload::Long(9))}]),
    );
    let path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        task["task_id"].as_str().unwrap()
    );
    let (a, b) = tokio::join!(
        request(&app_a, "POST", &path, body.clone()),
        request(&app_b, "POST", &path, body)
    );
    assert_eq!((a.0, b.0), (StatusCode::OK, StatusCode::OK), "{a:?} {b:?}");
    assert_ne!(a.1["recorded"], b.1["recorded"]);
    let history = request(
        &app_a,
        "GET",
        &format!(
            "/api/workflows/concurrent/runs/{}/history",
            task["run_id"].as_str().unwrap()
        ),
        Value::Null,
    )
    .await;
    assert_eq!(history.1["events"].as_array().unwrap().len(), 3);
    assert_eq!(history.1["events"][2]["event_type"], "WorkflowCompleted");
    node_a.close().await;
    node_b.close().await;
    database.remove().await;
}

#[tokio::test]
async fn history_budget_counts_utf8_bytes_before_hydration() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let started = start(&app, "utf8-budget").await;
    database
        .replace_history_payload(json!({"unicode":"界".repeat(3*1024*1024)}))
        .await;
    let history = request(
        &app,
        "GET",
        &format!(
            "/api/workflows/utf8-budget/runs/{}/history",
            started["run_id"].as_str().unwrap()
        ),
        Value::Null,
    )
    .await;
    assert_eq!(history.0, StatusCode::PAYLOAD_TOO_LARGE, "{}", history.1);
    assert_eq!(history.1["reason"], "history_event_exceeds_page_budget");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn stored_microseconds_survive_description_and_stale_lease_is_refused() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    start(&app, "precision").await;
    database.set_time(true, "2026-01-02T03:04:05.123456Z").await;
    let description = request(&app, "GET", "/api/workflows/precision", Value::Null).await;
    assert_eq!(description.0, StatusCode::OK, "{}", description.1);
    let time = chrono::DateTime::parse_from_rfc3339(description.1["started_at"].as_str().unwrap())
        .unwrap();
    assert_eq!(time.timestamp_subsec_nanos(), 123456000);
    register(&app, "worker", json!(["echo"]), json!([])).await;
    let task = poll(&app, "worker", "workflow").await;
    database
        .set_time(false, "2000-01-01T00:00:00.000001Z")
        .await;
    let outcome = request(
        &app,
        "POST",
        &format!(
            "/api/worker/workflow-tasks/{}/complete",
            task["task_id"].as_str().unwrap()
        ),
        completion(
            &task,
            json!([{"type":"complete_workflow","result":envelope(Payload::Null)}]),
        ),
    )
    .await;
    assert_eq!(outcome.0, StatusCode::CONFLICT, "{}", outcome.1);
    assert_eq!(outcome.1["reason"], "lease_expired");
    runtime.close().await;
    database.remove().await;
}

fn envelope(value: Payload) -> Value {
    json!({"codec": "avro", "blob": ValueCodec::new().unwrap().encode(&value).unwrap()})
}

async fn request(app: &Router, method: &str, path: &str, body: Value) -> (StatusCode, Value) {
    request_protocol(app, method, path, body, "1.19").await
}

async fn request_protocol(
    app: &Router,
    method: &str,
    path: &str,
    body: Value,
    worker_protocol: &str,
) -> (StatusCode, Value) {
    let req = Request::builder()
        .method(method)
        .uri(path)
        .header("authorization", "Bearer test-token")
        .header("content-type", "application/json")
        .header("x-namespace", "default")
        .header(
            if path.starts_with("/api/worker/") {
                "x-durable-workflow-protocol-version"
            } else {
                "x-durable-workflow-control-plane-version"
            },
            if path.starts_with("/api/worker/") {
                worker_protocol
            } else {
                "2"
            },
        )
        .body(Body::from(body.to_string()))
        .unwrap();
    let response = app.clone().oneshot(req).await.unwrap();
    let status = response.status();
    let body = to_bytes(response.into_body(), 4 * 1024 * 1024)
        .await
        .unwrap();
    (status, serde_json::from_slice(&body).unwrap())
}

async fn register(app: &Router, worker: &str, workflows: Value, activities: Value) {
    assert_eq!(
        request(
            app,
            "POST",
            "/api/worker/register",
            json!({"worker_id": worker,"task_queue":"test",
        "runtime":"php","supported_workflow_types":workflows,"supported_activity_types":activities})
        )
        .await
        .0,
        StatusCode::CREATED
    );
}

async fn start(app: &Router, workflow: &str) -> Value {
    let (status,body) = request(app,"POST","/api/workflows",json!({"workflow_id":workflow,"workflow_type":"echo",
        "task_queue":"test","input":envelope(Payload::Array(vec![Payload::Long(9007199254740993)]))})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    body
}

async fn poll(app: &Router, worker: &str, kind: &str) -> Value {
    let (status, body) = request(
        app,
        "POST",
        &format!("/api/worker/{kind}-tasks/poll"),
        json!({"worker_id":worker,
        "task_queue":"test","timeout_seconds":0}),
    )
    .await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body["task"].clone()
}

fn completion(task: &Value, commands: Value) -> Value {
    json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],"commands":commands})
}

async fn finish_task(app: &Router, task: &Value, commands: Value) -> (StatusCode, Value) {
    request(
        app,
        "POST",
        &format!(
            "/api/worker/workflow-tasks/{}/complete",
            task["task_id"].as_str().unwrap()
        ),
        completion(task, commands),
    )
    .await
}

async fn run_history(app: &Router, workflow: &Value, run: &Value) -> Value {
    let (status, body) = request(
        app,
        "GET",
        &format!(
            "/api/workflows/{}/runs/{}/history",
            workflow.as_str().unwrap(),
            run.as_str().unwrap()
        ),
        Value::Null,
    )
    .await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body["events"].clone()
}

fn child_command(value: i64) -> Value {
    json!({"type":"start_child_workflow","workflow_type":"child","arguments":envelope(Payload::Array(vec![Payload::Long(value)]))})
}

#[tokio::test]
async fn sequential_children_commit_original_links_results_and_one_parent_resumption() {
    let database = TestDatabase::new().await;
    let node_a = database.open().await.unwrap();
    let node_b = database.open().await.unwrap();
    let app_a = router(node_a.clone());
    let app_b = router(node_b.clone());
    register(&app_a, "parent-worker", json!(["echo"]), json!([])).await;
    let (status, body) = request(&app_b, "POST", "/api/worker/register", json!({"worker_id":"child-worker","task_queue":"test","runtime":"php","supported_workflow_types":["child"],"supported_activity_types":[],"workflow_command_contracts":{"child":{"signals":["finish"],"queries":[],"updates":[]}}})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    let parent = start(&app_a, "children").await;
    let mut parent_task = poll(&app_a, "parent-worker", "workflow").await;
    let mut original_children = Vec::new();
    for (index, value) in [9007199254740993_i64, 42].into_iter().enumerate() {
        let command = child_command(value);
        if index == 0 {
            for (field, option) in [
                ("parent_close_policy", json!("terminate")),
                ("cancellation_policy", json!("try_cancel")),
                ("retry_policy", json!({"maximum_attempts":2})),
                ("run_timeout_seconds", json!(10)),
                ("queue", json!("")),
                ("parallel_group_path", json!([])),
            ] {
                let mut unsupported = command.clone();
                unsupported[field] = option;
                assert_eq!(
                    finish_task(&app_a, &parent_task, json!([unsupported]))
                        .await
                        .0,
                    StatusCode::UNPROCESSABLE_ENTITY
                );
                assert_eq!(
                    run_history(&app_a, &parent["workflow_id"], &parent["run_id"])
                        .await
                        .as_array()
                        .unwrap()
                        .len(),
                    2
                );
                assert!(poll(&app_b, "child-worker", "workflow").await.is_null());
            }
        }
        let commands = json!([command]);
        let applied = finish_task(&app_a, &parent_task, commands.clone()).await;
        assert_eq!(applied.0, StatusCode::OK, "{}", applied.1);
        assert_eq!(applied.1["run_id"], parent["run_id"]);
        assert_eq!(
            finish_task(&app_b, &parent_task, commands).await.1["recorded"],
            false
        );
        assert_eq!(
            finish_task(&app_b, &parent_task, json!([child_command(7)]))
                .await
                .0,
            StatusCode::CONFLICT
        );
        assert!(poll(&app_a, "parent-worker", "workflow").await.is_null());
        let child = poll(&app_b, "child-worker", "workflow").await;
        assert_eq!(child["workflow_type"], "child");
        assert_eq!(
            child["arguments"],
            envelope(Payload::Array(vec![Payload::Long(value)]))
        );
        assert_eq!(child["history_events"].as_array().unwrap().len(), 1);
        let history = run_history(&app_a, &parent["workflow_id"], &parent["run_id"]).await;
        let scheduled = &history[2 + index * 3]["payload"];
        let started = &child["history_events"][0]["payload"];
        assert_eq!(
            started["parent_workflow_instance_id"],
            parent["workflow_id"]
        );
        assert_eq!(started["parent_workflow_run_id"], parent["run_id"]);
        assert_eq!(started["parent_sequence"], index + 1);
        assert_eq!(started["workflow_link_id"], scheduled["child_call_id"]);
        assert_eq!(started["child_call_id"], scheduled["workflow_link_id"]);
        assert_eq!(started["declared_signals"], json!(["finish"]));
        let described = request(
            &app_a,
            "GET",
            &format!(
                "/api/workflows/{}/runs/{}",
                child["workflow_id"].as_str().unwrap(),
                child["run_id"].as_str().unwrap()
            ),
            Value::Null,
        )
        .await;
        assert_eq!(described.0, StatusCode::OK, "{}", described.1);
        for field in [
            "execution_timeout_seconds",
            "run_timeout_seconds",
            "execution_deadline_at",
            "run_deadline_at",
        ] {
            assert!(described.1[field].is_null());
        }
        let complete =
            json!([{"type":"complete_workflow","result":envelope(Payload::Long(value))}]);
        let applied = finish_task(&app_b, &child, complete.clone()).await;
        assert_eq!(applied.0, StatusCode::OK, "{}", applied.1);
        assert_eq!(applied.1["run_id"], child["run_id"]);
        assert_eq!(
            finish_task(&app_a, &child, complete.clone()).await.1["recorded"],
            false
        );
        let (first, second) = tokio::join!(
            poll(&app_a, "parent-worker", "workflow"),
            poll(&app_b, "parent-worker", "workflow")
        );
        assert_ne!(
            first.is_null(),
            second.is_null(),
            "one durable parent resume claim"
        );
        parent_task = if first.is_null() { second } else { first };
        assert_eq!(parent_task["run_id"], parent["run_id"]);
        assert_eq!(parent_task["child_workflow_run_id"], child["run_id"]);
        assert_eq!(parent_task["child_call_id"], scheduled["child_call_id"]);
        assert_eq!(
            parent_task["open_wait_id"],
            format!("child:{}", scheduled["child_call_id"].as_str().unwrap())
        );
        assert_eq!(parent_task["resume_source_kind"], "child_workflow_run");
        assert_eq!(parent_task["resume_source_id"], child["run_id"]);
        assert_eq!(parent_task["workflow_wait_kind"], "child");
        assert_eq!(parent_task["workflow_sequence"], index + 1);
        assert_eq!(parent_task["workflow_event_type"], "ChildRunCompleted");
        let resolved = &parent_task["history_events"][4 + index * 3]["payload"];
        assert_eq!(resolved["output"], envelope(Payload::Long(value)));
        assert_eq!(resolved["result"], resolved["output"]);
        assert_eq!(resolved["child_workflow_run_id"], child["run_id"]);
        original_children.push((child, complete));
    }
    assert_ne!(
        original_children[0].0["run_id"],
        original_children[1].0["run_id"]
    );
    assert_eq!(finish_task(&app_a,&parent_task,json!([{"type":"complete_workflow","result":envelope(Payload::Array(vec![Payload::Long(9007199254740993),Payload::Long(42)]))}])).await.0,StatusCode::OK);
    let final_history = run_history(&app_b, &parent["workflow_id"], &parent["run_id"]).await;
    assert_eq!(final_history.as_array().unwrap().len(), 9);
    assert_eq!(final_history[8]["event_type"], "WorkflowCompleted");
    for (child, commands) in original_children {
        assert_eq!(
            finish_task(&app_b, &child, commands).await.1["recorded"],
            false
        );
        assert_eq!(
            finish_task(
                &app_b,
                &child,
                json!([{"type":"complete_workflow","result":envelope(Payload::Long(7))}])
            )
            .await
            .0,
            StatusCode::CONFLICT
        );
        assert_eq!(
            run_history(&app_a, &child["workflow_id"], &child["run_id"])
                .await
                .as_array()
                .unwrap()
                .len(),
            2
        );
    }
    assert_eq!(
        run_history(&app_a, &parent["workflow_id"], &parent["run_id"]).await,
        final_history
    );
    assert!(poll(&app_b, "parent-worker", "workflow").await.is_null());
    node_a.close().await;
    node_b.close().await;
    database.remove().await;
}

#[tokio::test]
async fn child_lease_expiry_and_fresh_pool_recovery_preserve_original_parent_resolution() {
    let database = TestDatabase::new().await;
    let old_node = database.open().await.unwrap();
    let old_app = router(old_node.clone());
    register(&old_app, "worker", json!(["echo", "child"]), json!([])).await;
    let parent = start(&old_app, "child-recovery").await;
    let parent_task = poll(&old_app, "worker", "workflow").await;
    assert_eq!(
        finish_task(
            &old_app,
            &parent_task,
            json!([child_command(9007199254740993)])
        )
        .await
        .0,
        StatusCode::OK
    );
    let old = poll(&old_app, "worker", "workflow").await;
    let commands =
        json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}]);
    old_node.close().await;
    drop(old_app);
    let replacement = database.open().await.unwrap();
    let app = router(replacement.clone());
    register(&app, "replacement", json!(["echo", "child"]), json!([])).await;
    assert!(poll(&app, "replacement", "workflow").await.is_null());
    let lease =
        chrono::DateTime::parse_from_rfc3339(old["lease_expires_at"].as_str().unwrap()).unwrap();
    let delay = (lease.with_timezone(&chrono::Utc) - chrono::Utc::now())
        .to_std()
        .unwrap_or_default()
        + std::time::Duration::from_millis(100);
    tokio::time::sleep(delay).await;
    assert_eq!(
        finish_task(&app, &old, commands.clone()).await.0,
        StatusCode::CONFLICT
    );
    assert_eq!(
        run_history(&app, &parent["workflow_id"], &parent["run_id"])
            .await
            .as_array()
            .unwrap()
            .len(),
        4
    );
    assert_eq!(
        run_history(&app, &old["workflow_id"], &old["run_id"])
            .await
            .as_array()
            .unwrap()
            .len(),
        1
    );
    let recovered = poll(&app, "replacement", "workflow").await;
    assert_eq!(recovered["task_id"], old["task_id"]);
    assert_eq!(recovered["run_id"], old["run_id"]);
    assert_eq!(recovered["workflow_task_attempt"], 2);
    assert_eq!(
        finish_task(&app, &old, commands.clone()).await.0,
        StatusCode::CONFLICT
    );
    assert_eq!(
        finish_task(&app, &recovered, commands.clone()).await.0,
        StatusCode::OK
    );
    let parent_resume = poll(&app, "replacement", "workflow").await;
    assert_eq!(parent_resume["run_id"], parent["run_id"]);
    assert_eq!(parent_resume["child_workflow_run_id"], old["run_id"]);
    assert_eq!(
        parent_resume["history_events"][4]["payload"]["output"],
        envelope(Payload::Long(9007199254740993))
    );
    assert_eq!(
        finish_task(&app, &recovered, commands).await.1["recorded"],
        false
    );
    assert_eq!(
        finish_task(
            &app,
            &parent_resume,
            json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}])
        )
        .await
        .0,
        StatusCode::OK
    );
    assert_eq!(
        run_history(&app, &parent["workflow_id"], &parent["run_id"])
            .await
            .as_array()
            .unwrap()
            .len(),
        6
    );
    replacement.close().await;
    database.remove().await;
}

#[tokio::test]
async fn mismatched_child_call_rolls_back_child_terminal_history_and_parent_resume() {
    for corruption in [
        "UPDATE workflow_child_calls SET resolved_child_run_id='stale-child-run'",
        "DELETE FROM workflow_links",
    ] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        register(&app, "worker", json!(["echo", "child"]), json!([])).await;
        let parent = start(&app, "child-mismatch").await;
        let parent_task = poll(&app, "worker", "workflow").await;
        assert_eq!(
            finish_task(&app, &parent_task, json!([child_command(7)]))
                .await
                .0,
            StatusCode::OK
        );
        let child = poll(&app, "worker", "workflow").await;
        database.execute(corruption).await;
        let result = finish_task(
            &app,
            &child,
            json!([{"type":"complete_workflow","result":envelope(Payload::Long(7))}]),
        )
        .await;
        assert_eq!(result.0, StatusCode::CONFLICT);
        assert_eq!(result.1["reason"], "child_call_mismatch");
        assert_eq!(
            run_history(&app, &child["workflow_id"], &child["run_id"])
                .await
                .as_array()
                .unwrap()
                .len(),
            1
        );
        assert_eq!(
            run_history(&app, &parent["workflow_id"], &parent["run_id"])
                .await
                .as_array()
                .unwrap()
                .len(),
            4
        );
        assert!(poll(&app, "worker", "workflow").await.is_null());
        runtime.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn direct_child_cancellation_preserves_original_failure_and_one_parent_resumption() {
    for pending_timer in [false, true] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        register(&app, "parent-worker", json!(["echo"]), json!([])).await;
        register(&app, "child-worker", json!(["child"]), json!([])).await;
        let parent = start(&app, "cancel-child-parent").await;
        let task = poll(&app, "parent-worker", "workflow").await;
        assert_eq!(
            finish_task(&app, &task, json!([child_command(9007199254740993_i64)]))
                .await
                .0,
            StatusCode::OK
        );
        let parent_before = run_history(&app, &parent["workflow_id"], &parent["run_id"]).await;
        let original = parent_before[3]["payload"].clone();
        let child_id = original["child_workflow_instance_id"].clone();
        let child_run = original["child_workflow_run_id"].clone();
        let described = request(
            &app,
            "GET",
            &format!(
                "/api/workflows/{}/runs/{}",
                child_id.as_str().unwrap(),
                child_run.as_str().unwrap()
            ),
            Value::Null,
        )
        .await;
        assert_eq!(described.0, StatusCode::OK);
        assert_eq!(
            described.1["status"], "pending",
            "unclaimed child retains published pending state"
        );
        let child_task = if pending_timer {
            let task = poll(&app, "child-worker", "workflow").await;
            assert_eq!(task["run_id"], child_run);
            assert_eq!(
                finish_task(
                    &app,
                    &task,
                    json!([{"type":"start_timer","delay_seconds":60}])
                )
                .await
                .0,
                StatusCode::OK
            );
            Some(task)
        } else {
            None
        };
        let path = format!(
            "/api/workflows/{}/runs/{}/cancel",
            child_id.as_str().unwrap(),
            child_run.as_str().unwrap()
        );
        let accepted = request(
            &app,
            "POST",
            &path,
            json!({"reason":"direct child cancellation λ"}),
        )
        .await;
        assert_eq!(accepted.0, StatusCode::OK, "{}", accepted.1);
        let child_history = run_history(&app, &child_id, &child_run).await;
        assert_eq!(
            child_history
                .as_array()
                .unwrap()
                .iter()
                .map(|event| event["event_type"].as_str().unwrap())
                .collect::<Vec<_>>(),
            if pending_timer {
                vec![
                    "WorkflowStarted",
                    "TimerScheduled",
                    "CancelRequested",
                    "TimerCancelled",
                    "WorkflowCancelled",
                ]
            } else {
                vec!["WorkflowStarted", "CancelRequested", "WorkflowCancelled"]
            }
        );
        let terminal = child_history.as_array().unwrap().last().unwrap();
        let failure = terminal["payload"]["failure_id"].as_str().unwrap();
        assert_eq!(
            database
                .cancellation_failure_count(child_run.as_str().unwrap(), failure)
                .await,
            1
        );
        assert_eq!(
            database.cancellation_failure_message(failure).await,
            "Workflow cancelled: direct child cancellation λ"
        );
        let parent_after = run_history(&app, &parent["workflow_id"], &parent["run_id"]).await;
        assert_eq!(parent_after.as_array().unwrap().len(), 5);
        let resolution = &parent_after[4];
        assert_eq!(resolution["event_type"], "ChildRunCancelled");
        for field in [
            "child_call_id",
            "workflow_link_id",
            "child_workflow_instance_id",
            "child_workflow_run_id",
            "child_workflow_type",
            "child_run_number",
        ] {
            assert_eq!(resolution["payload"][field], original[field]);
        }
        assert_eq!(resolution["payload"]["failure_id"], failure);
        assert_eq!(
            resolution["payload"]["message"],
            terminal["payload"]["message"]
        );
        runtime.close().await;
        let fresh = database.open().await.unwrap();
        let fresh_app = router(fresh.clone());
        let duplicate = request(
            &fresh_app,
            "POST",
            &path,
            json!({"reason":"replacement reason"}),
        )
        .await;
        assert_eq!(duplicate.0, StatusCode::CONFLICT);
        assert_eq!(duplicate.1["rejection_reason"], "run_not_active");
        assert_eq!(duplicate.1["outcome"], "rejected_not_active");
        assert_eq!(
            run_history(&fresh_app, &child_id, &child_run).await,
            child_history
        );
        assert_eq!(
            run_history(&fresh_app, &parent["workflow_id"], &parent["run_id"]).await,
            parent_after
        );
        if let Some(task) = child_task {
            assert_eq!(
                finish_task(
                    &fresh_app,
                    &task,
                    json!([{"type":"complete_workflow","result":envelope(Payload::Long(7))}])
                )
                .await
                .0,
                StatusCode::CONFLICT
            );
        }
        assert!(poll(&fresh_app, "child-worker", "workflow").await.is_null());
        let resumed = poll(&fresh_app, "parent-worker", "workflow").await;
        assert_eq!(resumed["run_id"], parent["run_id"]);
        assert_eq!(resumed["child_workflow_run_id"], child_run);
        assert_eq!(resumed["workflow_event_type"], "ChildRunCancelled");
        let complete = json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993_i64))}]);
        assert_eq!(
            finish_task(&fresh_app, &resumed, complete.clone()).await.0,
            StatusCode::OK
        );
        assert_eq!(
            finish_task(&fresh_app, &resumed, complete).await.1["recorded"],
            false
        );
        let final_history =
            run_history(&fresh_app, &parent["workflow_id"], &parent["run_id"]).await;
        assert_eq!(final_history.as_array().unwrap().len(), 6);
        assert_eq!(final_history[5]["event_type"], "WorkflowCompleted");
        assert!(
            poll(&fresh_app, "parent-worker", "workflow")
                .await
                .is_null()
        );
        assert_eq!(
            database
                .cancellation_failure_count(child_run.as_str().unwrap(), failure)
                .await,
            1
        );
        assert_eq!(
            database.cancellation_failure_message(failure).await,
            "Workflow cancelled: direct child cancellation λ"
        );
        fresh.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn mismatched_child_cancellation_rolls_back_original_child_and_parent() {
    for corruption in [
        "UPDATE workflow_child_calls SET resolved_child_run_id='stale-child-run'",
        "DELETE FROM workflow_links",
    ] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        register(&app, "worker", json!(["echo", "child"]), json!([])).await;
        let parent = start(&app, "cancel-child-mismatch").await;
        let task = poll(&app, "worker", "workflow").await;
        assert_eq!(
            finish_task(&app, &task, json!([child_command(7)])).await.0,
            StatusCode::OK
        );
        let parent_before = run_history(&app, &parent["workflow_id"], &parent["run_id"]).await;
        let child = parent_before[3]["payload"].clone();
        let id = &child["child_workflow_instance_id"];
        let run = &child["child_workflow_run_id"];
        let child_before = run_history(&app, id, run).await;
        database.execute(corruption).await;
        let path = format!(
            "/api/workflows/{}/runs/{}/cancel",
            id.as_str().unwrap(),
            run.as_str().unwrap()
        );
        let result = request(&app, "POST", &path, json!({"reason":"must roll back"})).await;
        assert_eq!(result.0, StatusCode::CONFLICT, "{}", result.1);
        assert_eq!(result.1["reason"], "child_call_mismatch");
        assert_eq!(run_history(&app, id, run).await, child_before);
        assert_eq!(
            run_history(&app, &parent["workflow_id"], &parent["run_id"]).await,
            parent_before
        );
        runtime.close().await;
        database.remove().await;
    }
}

async fn update_worker(app: &Router, worker: &str) {
    let (status, body) = request(app, "POST", "/api/worker/register", json!({
        "worker_id":worker,"task_queue":"test","runtime":"php","supported_workflow_types":["echo"],
        "supported_activity_types":[],"capabilities":["workflow_updates"],"workflow_command_contracts":{"echo":{
            "signals":["finish"],"signal_contracts":[{"name":"finish","parameters":[{"name":"value","type":"int","required":true,"variadic":false,"default_available":false,"allows_null":false}]}],
            "updates":["set"],"update_validators":[],"update_contracts":[{"name":"set","parameters":[{"name":"value","type":"int","required":true,"variadic":false,"default_available":false,"allows_null":false}]}]
        }}})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

async fn open_update_wait(app: &Router, worker: &str) {
    let task = poll(app, worker, "workflow").await;
    let path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        task["task_id"].as_str().unwrap()
    );
    let result = request(
        app,
        "POST",
        &path,
        completion(
            &task,
            json!([{"type":"open_signal_wait","signal_name":"finish"}]),
        ),
    )
    .await;
    assert_eq!(result.0, StatusCode::OK, "{}", result.1);
}

#[tokio::test]
async fn updates_preserve_original_receipts_and_fence_recovered_tasks_across_pools() {
    let database = TestDatabase::new().await;
    let first = database.open().await.unwrap();
    let second = database.open().await.unwrap();
    let a = router(first.clone());
    let b = router(second.clone());
    update_worker(&a, "update-a").await;
    update_worker(&b, "update-b").await;
    let started = start(&a, "durable-update").await;
    let path = format!(
        "/api/workflows/durable-update/runs/{}/update/set",
        started["run_id"].as_str().unwrap()
    );
    let history_path = format!(
        "/api/workflows/durable-update/runs/{}/history",
        started["run_id"].as_str().unwrap()
    );
    let input = json!({"input":envelope(Payload::Array(vec![Payload::Long(9007199254740993)])),
        "request_id":"original-update","wait_for":"accepted"});
    assert_eq!(
        request(&a, "POST", &path, input.clone()).await.1["reason"],
        "update_snapshot_busy"
    );
    open_update_wait(&a, "update-a").await;
    for (test_path, body, reason) in [
        (
            path.replace("/update/set", "/update/unknown"),
            input.clone(),
            "unknown_update",
        ),
        (
            path.clone(),
            json!({"input":envelope(Payload::Array(vec![Payload::String("wrong".into())]))}),
            "invalid_update_arguments",
        ),
        (
            path.clone(),
            json!({"input":{"codec":"avro","blob":"bad"}}),
            "invalid_update_arguments",
        ),
        (
            path.clone(),
            json!({"input":input["input"],"wait_for":"other"}),
            "invalid_update_wait_policy",
        ),
        (
            path.clone(),
            json!({"input":input["input"],"wait_timeout_seconds":31}),
            "unsupported_update_wait_timeout",
        ),
    ] {
        assert_eq!(
            request(&a, "POST", &test_path, body).await.1["reason"],
            reason
        );
    }
    let admitted = request(&a, "POST", &path, input.clone()).await;
    assert_eq!(admitted.0, StatusCode::ACCEPTED, "{}", admitted.1);
    let before = request(&a, "GET", &history_path, Value::Null).await.1;
    let mut duplicate = input.clone();
    duplicate["input"] = envelope(Payload::Array(vec![Payload::String(
        "must not overwrite original arguments".into(),
    )]));
    let repeated = request(&b, "POST", &path, duplicate.clone()).await;
    assert_eq!(repeated.0, StatusCode::ACCEPTED, "{}", repeated.1);
    assert_eq!(repeated.1["update_id"], admitted.1["update_id"]);
    assert_eq!(repeated.1["command_id"], admitted.1["command_id"]);
    assert_eq!(
        request(&b, "GET", &history_path, Value::Null).await.1,
        before
    );
    let mut another = input.clone();
    another["request_id"] = json!("another");
    assert_eq!(
        request(&a, "POST", &path, another).await.1["reason"],
        "update_snapshot_busy"
    );
    register(&b, "without-update-handler", json!(["echo"]), json!([])).await;
    assert!(
        poll(&b, "without-update-handler", "workflow")
            .await
            .is_null()
    );
    let old = poll(&a, "update-a", "workflow").await;
    assert_eq!(old["workflow_update_id"], admitted.1["update_id"]);
    assert_eq!(old["history_events"].as_array().unwrap().len(), 4);
    let complete_path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        old["task_id"].as_str().unwrap()
    );
    let commands = json!([{"type":"complete_update","update_id":admitted.1["update_id"],"result":envelope(Payload::Long(9007199254740993))}]);
    let original = completion(&old, commands.clone());
    for (field, value) in [
        ("lease_owner", json!("wrong")),
        ("workflow_task_attempt", json!(99)),
    ] {
        let mut wrong = original.clone();
        wrong[field] = value;
        assert_eq!(
            request(&b, "POST", &complete_path, wrong).await.0,
            StatusCode::CONFLICT
        );
    }
    let mut wrong_update = original.clone();
    wrong_update["commands"][0]["update_id"] = json!("another-update");
    assert_eq!(
        request(&a, "POST", &complete_path, wrong_update).await.1["reason"],
        "update_task_mismatch"
    );
    let mut malformed = original.clone();
    malformed["commands"][0]["result"]["blob"] = json!("not-avro");
    assert_eq!(
        request(&a, "POST", &complete_path, malformed).await.1["reason"],
        "invalid_update_result"
    );
    assert_eq!(
        request(
            &a,
            "POST",
            &complete_path,
            completion(
                &old,
                json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])
            )
        )
        .await
        .1["reason"],
        "update_task_mismatch"
    );
    assert_eq!(
        request(&a, "GET", &history_path, Value::Null).await.1,
        before
    );
    database.set_time(false, "2000-01-01T00:00:00Z").await;
    assert_eq!(
        request(&a, "POST", &complete_path, original.clone())
            .await
            .0,
        StatusCode::CONFLICT
    );
    let recovered = poll(&b, "update-b", "workflow").await;
    assert_eq!(recovered["task_id"], old["task_id"]);
    assert_eq!(recovered["workflow_update_id"], old["workflow_update_id"]);
    assert_eq!(recovered["workflow_task_attempt"], 2);
    assert_eq!(recovered["history_events"], old["history_events"]);
    let current = completion(&recovered, commands);
    // A registration that drops the update handler cannot commit its old lease.
    register(&b, "update-b", json!(["echo"]), json!([])).await;
    assert_eq!(
        request(&b, "POST", &complete_path, current.clone()).await.1["reason"],
        "update_worker_unavailable"
    );
    update_worker(&b, "update-b").await;
    duplicate["wait_for"] = json!("completed");
    duplicate["wait_timeout_seconds"] = json!(5);
    let waiting = {
        let app = a.clone();
        let path = path.clone();
        let body = duplicate.clone();
        tokio::spawn(async move { request(&app, "POST", &path, body).await })
    };
    tokio::time::sleep(std::time::Duration::from_millis(20)).await;
    assert!(
        !waiting.is_finished(),
        "completion client is waiting on the original accepted update"
    );
    let completed = tokio::time::timeout(
        std::time::Duration::from_secs(2),
        request(&b, "POST", &complete_path, current.clone()),
    )
    .await
    .expect("waiting update must release its database pool");
    assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
    let receipt = waiting.await.unwrap();
    assert_eq!(receipt.0, StatusCode::OK, "{}", receipt.1);
    assert_eq!(
        receipt.1["result_envelope"],
        envelope(Payload::Long(9007199254740993))
    );
    assert_eq!(receipt.1["update_id"], admitted.1["update_id"]);
    assert_eq!(receipt.1["workflow_sequence"], 2);
    assert_eq!(
        request(&b, "POST", &complete_path, current).await.1["recorded"],
        false
    );
    assert_eq!(
        request(&a, "POST", &complete_path, original).await.0,
        StatusCode::CONFLICT
    );
    let completed_history = request(&a, "GET", &history_path, Value::Null).await.1;
    assert_eq!(
        completed_history["events"]
            .as_array()
            .unwrap()
            .iter()
            .map(|event| event["event_type"].as_str().unwrap())
            .collect::<Vec<_>>(),
        [
            "StartAccepted",
            "WorkflowStarted",
            "SignalWaitOpened",
            "UpdateAccepted",
            "UpdateApplied",
            "UpdateCompleted",
            "MessageCursorAdvanced"
        ]
    );
    assert_eq!(
        completed_history["events"][3]["payload"]["update_id"],
        admitted.1["update_id"]
    );
    assert_eq!(
        completed_history["events"][5]["payload"]["result"],
        envelope(Payload::Long(9007199254740993))
    );
    assert_eq!(
        request(&a, "GET", "/api/workflows/durable-update", Value::Null)
            .await
            .1["status"],
        "waiting"
    );
    assert_eq!(
        request(
            &b,
            "POST",
            "/api/workflows/durable-update/signal/finish",
            json!({"input":envelope(Payload::Array(vec![Payload::Long(1)]))})
        )
        .await
        .0,
        StatusCode::ACCEPTED
    );
    let finish = poll(&b, "update-b", "workflow").await;
    assert!(finish["workflow_update_id"].is_null());
    let finish_path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        finish["task_id"].as_str().unwrap()
    );
    assert_eq!(request(&b, "POST", &finish_path, completion(&finish,
        json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}]))).await.0, StatusCode::OK);
    first.close().await;
    second.close().await;
    let reopened = database.open().await.unwrap();
    let app = router(reopened.clone());
    assert_eq!(
        request(&app, "POST", &path, duplicate).await.1["result_envelope"],
        envelope(Payload::Long(9007199254740993))
    );
    assert_eq!(
        request(&app, "GET", "/api/workflows/durable-update", Value::Null)
            .await
            .1["status"],
        "completed"
    );
    reopened.close().await;
    database.remove().await;
}

#[tokio::test]
async fn updates_bound_durable_inventory_and_preserve_accepted_work_after_wait_timeout() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "update-worker").await;
    let started = start(&app, "bounded-updates").await;
    open_update_wait(&app, "update-worker").await;
    let path = "/api/workflows/bounded-updates/update/set";
    for index in 0..64 {
        let body = json!({"input":envelope(Payload::Array(vec![Payload::Long(index)])),
            "request_id":format!("bounded:{index}"),"wait_for":"completed","wait_timeout_seconds":0});
        let receipt = request(&app, "POST", path, body).await;
        assert_eq!(receipt.0, StatusCode::ACCEPTED, "{}", receipt.1);
        assert_eq!(receipt.1["wait_timed_out"], true);
        let task = poll(&app, "update-worker", "workflow").await;
        assert_eq!(task["workflow_update_id"], receipt.1["update_id"]);
        let complete_path = format!(
            "/api/worker/workflow-tasks/{}/complete",
            task["task_id"].as_str().unwrap()
        );
        let result = request(&app, "POST", &complete_path, completion(&task, json!([{
            "type":"complete_update","update_id":receipt.1["update_id"],"result":envelope(Payload::Long(index))
        }]))).await;
        assert_eq!(result.0, StatusCode::OK, "{}", result.1);
    }
    let history_path = format!(
        "/api/workflows/bounded-updates/runs/{}/history?page_size=1000",
        started["run_id"].as_str().unwrap()
    );
    let before = request(&app, "GET", &history_path, Value::Null).await.1;
    assert_eq!(
        before["events"]
            .as_array()
            .unwrap()
            .iter()
            .filter(|event| event["event_type"] == "UpdateCompleted")
            .count(),
        64
    );
    let over_budget = request(&app, "POST", path, json!({
        "input":envelope(Payload::Array(vec![Payload::Long(65)])),"wait_for":"accepted","request_id":"over-budget"
    })).await;
    assert_eq!(over_budget.0, StatusCode::SERVICE_UNAVAILABLE);
    assert_eq!(over_budget.1["reason"], "update_inventory_exceeds_budget");
    assert_eq!(
        request(&app, "GET", &history_path, Value::Null).await.1,
        before
    );
    let duplicate = request(&app, "POST", path, json!({
        "input":envelope(Payload::Array(vec![Payload::Long(999)])),"request_id":"bounded:0","wait_for":"completed"
    })).await;
    assert_eq!(duplicate.0, StatusCode::OK, "{}", duplicate.1);
    assert_eq!(duplicate.1["result_envelope"], envelope(Payload::Long(0)));
    assert_eq!(
        request(&app, "GET", &history_path, Value::Null).await.1,
        before
    );
    runtime.close().await;
    database.remove().await;
}

async fn query_worker(app: &Router, worker: &str) {
    let (status, body) = request(app,"POST","/api/worker/register",json!({
        "worker_id":worker,"task_queue":"test","runtime":"php","supported_workflow_types":["echo"],
        "supported_activity_types":[],"capabilities":["query_tasks"],"workflow_command_contracts":{"echo":{
        "queries":["state"],"query_contracts":[{"name":"state","parameters":[{"name":"request","position":0,
        "type":"int","required":true,"variadic":false,"default_available":false,"allows_null":false}]}]}}})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

async fn await_query(app: &Router, worker: &str) -> Value {
    tokio::time::timeout(std::time::Duration::from_secs(5), async {
        loop {
            let task = poll(app, worker, "query").await;
            if !task.is_null() {
                break task;
            }
            tokio::time::sleep(std::time::Duration::from_millis(10)).await;
        }
    })
    .await
    .expect("query must be admitted without holding the database pool")
}

#[tokio::test]
async fn queries_fence_recovered_leases_across_pools_without_mutating_the_run() {
    let database = TestDatabase::new().await;
    let first = database.open().await.unwrap();
    let second = database.open().await.unwrap();
    let a = router(first.clone());
    let b = router(second.clone());
    query_worker(&a, "query-a").await;
    query_worker(&b, "query-b").await;
    let started = start(&a, "read-only-query").await;
    let query_path = format!(
        "/api/workflows/read-only-query/runs/{}/query/state",
        started["run_id"].as_str().unwrap()
    );
    let input = json!({"input":envelope(Payload::Array(vec![Payload::Long(9007199254740993)]))});
    assert_eq!(
        request(&a, "POST", &query_path, input.clone()).await.1["reason"],
        "query_snapshot_busy"
    );
    let workflow_task = poll(&a, "query-a", "workflow").await;
    assert_eq!(
        request(&a, "POST", &query_path, input.clone()).await.1["reason"],
        "query_snapshot_busy"
    );
    let complete_path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        workflow_task["task_id"].as_str().unwrap()
    );
    assert_eq!(
        request(
            &a,
            "POST",
            &complete_path,
            completion(
                &workflow_task,
                json!([{
        "type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}])
            )
        )
        .await
        .0,
        StatusCode::OK
    );
    let history_path = format!(
        "/api/workflows/read-only-query/runs/{}/history",
        started["run_id"].as_str().unwrap()
    );
    let before = request(&a, "GET", &history_path, Value::Null).await.1;
    let run_before = request(&a, "GET", "/api/workflows/read-only-query", Value::Null)
        .await
        .1;
    for (path, input, expected) in [
        (
            query_path.replace("/query/state", "/query/unknown"),
            input.clone(),
            "rejected_unknown_query",
        ),
        (
            query_path.clone(),
            json!({"input":envelope(Payload::Array(vec![Payload::String("wrong".into())]))}),
            "invalid_query_arguments",
        ),
        (
            query_path.clone(),
            json!({"input":{"codec":"avro","blob":"bad-avro"}}),
            "invalid_query_arguments",
        ),
    ] {
        assert_eq!(
            request(&a, "POST", &path, input).await.1["reason"],
            expected
        );
    }
    let waiting = {
        let app = a.clone();
        let path = query_path.clone();
        let input = input.clone();
        tokio::spawn(async move { request(&app, "POST", &path, input).await })
    };
    let claim = await_query(&b, "query-a").await;
    assert_eq!(claim["run_status"], "completed");
    // Worker snapshots retain durable row IDs/links; the control-plane
    // history endpoint exposes its documented timestamp projection.
    let projected = Value::Array(
        claim["history_events"]
            .as_array()
            .unwrap()
            .iter()
            .map(|event| {
                json!({"sequence":event["sequence"],"event_type":event["event_type"],
            "payload":event["payload"],"timestamp":event["recorded_at"]})
            })
            .collect(),
    );
    assert_eq!(projected, before["events"]);
    assert_eq!(claim["query_arguments"], input["input"]);
    assert_eq!(poll(&a, "query-a", "query").await, claim);
    assert!(poll(&b, "query-b", "query").await.is_null());
    let finish_path = format!(
        "/api/worker/query-tasks/{}/complete",
        claim["query_task_id"].as_str().unwrap()
    );
    let result = envelope(Payload::Long(9007199254740993));
    let completion = json!({"lease_owner":"query-a","query_task_attempt":claim["query_task_attempt"],"result_envelope":result});
    let mut wrong = completion.clone();
    wrong["lease_owner"] = json!("query-b");
    assert_eq!(
        request(&b, "POST", &finish_path, wrong).await.1["reason"],
        "query_task_lease_owner_mismatch"
    );
    let mut invalid = completion.clone();
    invalid["result_envelope"]["blob"] = json!("not-avro");
    assert_eq!(
        request(&b, "POST", &finish_path, invalid).await.1["reason"],
        "invalid_query_result"
    );
    match &database {
        TestDatabase::Sqlite(_) => database.execute("UPDATE dw_query_cache SET value=json_set(value,'$.lease_until',0) WHERE value LIKE '%\"status\":\"leased\"%'").await,
        TestDatabase::Postgres{..} => database.execute("UPDATE dw_query_cache SET value=jsonb_set(value::jsonb,'{lease_until}','0')::text WHERE value LIKE '%\"status\":\"leased\"%'").await,
        TestDatabase::MySql{..} => database.execute("UPDATE dw_query_cache SET value=JSON_SET(value,'$.lease_until',0) WHERE value LIKE '%\"status\":\"leased\"%'").await,
    }
    assert_eq!(
        request(&a, "POST", &finish_path, completion.clone())
            .await
            .1["reason"],
        "query_task_lease_expired"
    );
    let recovered = await_query(&a, "query-b").await;
    assert_eq!(recovered["query_task_id"], claim["query_task_id"]);
    assert_eq!(recovered["query_task_attempt"], 2);
    assert_eq!(recovered["history_events"], claim["history_events"]);
    assert_eq!(
        request(&a, "POST", &finish_path, completion.clone())
            .await
            .1["reason"],
        "query_task_lease_owner_mismatch"
    );
    let mut current = completion.clone();
    current["lease_owner"] = json!("query-b");
    assert_eq!(
        request(&b, "POST", &finish_path, current.clone()).await.1["reason"],
        "query_task_attempt_mismatch"
    );
    current["query_task_attempt"] = json!(2);
    assert_eq!(
        request(&b, "POST", &finish_path, current.clone()).await.0,
        StatusCode::OK
    );
    let outcome = waiting.await.unwrap();
    assert_eq!(outcome.0, StatusCode::OK, "{}", outcome.1);
    assert_eq!(outcome.1["result_envelope"], result);
    assert_eq!(
        request(&b, "POST", &finish_path, current).await.1["reason"],
        "query_task_not_leased"
    );
    assert_eq!(
        request(&a, "GET", &history_path, Value::Null).await.1,
        before
    );
    assert_eq!(
        request(&b, "GET", "/api/workflows/read-only-query", Value::Null)
            .await
            .1,
        run_before
    );
    first.close().await;
    second.close().await;
    database.remove().await;
}

#[tokio::test]
async fn queries_bound_abandoned_requests_reclaim_expired_entries_and_deliver_worker_failure() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    query_worker(&app, "query-worker").await;
    start(&app, "bounded-queries").await;
    let task = poll(&app, "query-worker", "workflow").await;
    let path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        task["task_id"].as_str().unwrap()
    );
    assert_eq!(
        request(
            &app,
            "POST",
            &path,
            completion(
                &task,
                json!([{"type":"complete_workflow",
        "result":envelope(Payload::Long(1))}])
            )
        )
        .await
        .0,
        StatusCode::OK
    );
    let input = json!({"input":envelope(Payload::Array(vec![Payload::Long(1)]))});
    let mut requests = tokio::task::JoinSet::new();
    for _ in 0..17 {
        let app = app.clone();
        let input = input.clone();
        requests.spawn(async move {
            request(
                &app,
                "POST",
                "/api/workflows/bounded-queries/query/state",
                input,
            )
            .await
        });
    }
    let rejected = tokio::time::timeout(std::time::Duration::from_secs(5), requests.join_next())
        .await
        .unwrap()
        .unwrap()
        .unwrap();
    assert_eq!(
        rejected.0,
        StatusCode::SERVICE_UNAVAILABLE,
        "{}",
        rejected.1
    );
    assert_eq!(rejected.1["reason"], "query_task_queue_full");
    requests.abort_all();
    while requests.join_next().await.is_some() {}
    assert_eq!(
        request(
            &app,
            "POST",
            "/api/workflows/bounded-queries/query/state",
            input.clone()
        )
        .await
        .1["reason"],
        "query_task_queue_full"
    );
    database
        .execute("UPDATE dw_query_cache SET expiration=0")
        .await;
    let waiting = {
        let app = app.clone();
        tokio::spawn(async move {
            request(
                &app,
                "POST",
                "/api/workflows/bounded-queries/query/state",
                input,
            )
            .await
        })
    };
    let claim = await_query(&app, "query-worker").await;
    let path = format!(
        "/api/worker/query-tasks/{}/fail",
        claim["query_task_id"].as_str().unwrap()
    );
    assert_eq!(request(&app,"POST",&path,json!({"lease_owner":"query-worker","query_task_attempt":1,
        "failure":{"reason":"query_rejected","message":"handler refused the request","type":"TestFailure"}})).await.0,StatusCode::OK);
    let result = waiting.await.unwrap();
    assert_eq!(result.0, StatusCode::CONFLICT);
    assert_eq!(result.1["reason"], "query_rejected");
    assert_eq!(result.1["message"], "handler refused the request");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn buffered_signals_survive_restart_and_each_wait_commits_once() {
    let database = TestDatabase::new().await;
    let first = database.open().await.unwrap();
    let app = router(first.clone());
    let definition = json!({"worker_id":"signal-worker","task_queue":"test","runtime":"php",
        "supported_workflow_types":["echo"],"supported_activity_types":[],
        "workflow_command_contracts":{"echo":{"signals":["payload"],"signal_contracts":[{"name":"payload","parameters":[{"name":"value","position":0,"type":"array","required":true,"variadic":false,"default_available":false,"allows_null":false}]}]}}});
    assert_eq!(
        request(&app, "POST", "/api/worker/register", definition)
            .await
            .0,
        StatusCode::CREATED
    );
    let started = start(&app, "buffered-signals").await;
    let task = poll(&app, "signal-worker", "workflow").await;
    let history_path = format!(
        "/api/workflows/buffered-signals/runs/{}/history",
        started["run_id"].as_str().unwrap()
    );
    let signal_path = format!(
        "/api/workflows/buffered-signals/runs/{}/signal/payload",
        started["run_id"].as_str().unwrap()
    );
    let value = Payload::Map(std::collections::BTreeMap::from([
        ("count".into(), Payload::Long(9007199254740993)),
        ("message".into(), Payload::String("signal λ".into())),
    ]));
    let input = json!({"input":envelope(Payload::Array(vec![value.clone()])),"request_id":"same-request-context"});
    let accepted_a = request(&app, "POST", &signal_path, input.clone()).await;
    let accepted_b = request(&app, "POST", &signal_path, input.clone()).await;
    assert_eq!(accepted_a.0, StatusCode::ACCEPTED, "{}", accepted_a.1);
    assert_eq!(accepted_b.0, StatusCode::ACCEPTED, "{}", accepted_b.1);
    assert_ne!(accepted_a.1["command_id"], accepted_b.1["command_id"]);
    assert_eq!(accepted_a.1["command_sequence"], 2);
    assert_eq!(accepted_b.1["command_sequence"], 3);
    // A leased initial task remains the only open task; accepted input waits
    // durably until completion opens a matching wait.
    assert!(poll(&app, "signal-worker", "workflow").await.is_null());
    let before = request(&app, "GET", &history_path, Value::Null).await.1;
    assert_eq!(before["events"].as_array().unwrap().len(), 4);
    let signals = [
        &before["events"][2]["payload"],
        &before["events"][3]["payload"],
    ];
    assert_ne!(signals[0]["signal_id"], signals[1]["signal_id"]);
    for (index, signal) in signals.iter().enumerate() {
        assert_eq!(signal["command"]["message_sequence"], index + 1);
        assert_eq!(signal["workflow_run_id"], started["run_id"]);
    }
    for (path, body, expected) in [
        (
            signal_path.clone(),
            json!({"input":envelope(Payload::Array(vec![Payload::Long(1)]))}),
            StatusCode::UNPROCESSABLE_ENTITY,
        ),
        (
            signal_path.clone(),
            json!({"input":{"codec":"avro","blob":"not-avro"}}),
            StatusCode::UNPROCESSABLE_ENTITY,
        ),
        (
            signal_path.replace("/signal/payload", "/signal/unknown"),
            input.clone(),
            StatusCode::NOT_FOUND,
        ),
        (
            signal_path.replace(started["run_id"].as_str().unwrap(), "historical-run"),
            input,
            StatusCode::CONFLICT,
        ),
    ] {
        assert_eq!(request(&app, "POST", &path, body).await.0, expected);
        assert_eq!(
            request(&app, "GET", &history_path, Value::Null).await.1,
            before
        );
    }
    let complete_path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        task["task_id"].as_str().unwrap()
    );
    let open = completion(
        &task,
        json!([{"type":"open_signal_wait","signal_name":"payload"}]),
    );
    assert_eq!(
        request(&app, "POST", &complete_path, open.clone()).await.0,
        StatusCode::OK
    );
    assert_eq!(
        request(&app, "POST", &complete_path, open).await.1["recorded"],
        false
    );
    first.close().await;
    let a = database.open().await.unwrap();
    let b = database.open().await.unwrap();
    let app_a = router(a.clone());
    let app_b = router(b.clone());
    let (claim_a, claim_b) = tokio::join!(
        poll(&app_a, "signal-worker", "workflow"),
        poll(&app_b, "signal-worker", "workflow")
    );
    assert_ne!(claim_a.is_null(), claim_b.is_null());
    let resumed = if claim_a.is_null() { claim_b } else { claim_a };
    assert_eq!(resumed["run_id"], started["run_id"]);
    let resume_path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        resumed["task_id"].as_str().unwrap()
    );
    let second_wait = completion(
        &resumed,
        json!([{"type":"open_signal_wait","signal_name":"payload"}]),
    );
    let mut stale = second_wait.clone();
    stale["workflow_task_attempt"] = json!(99);
    assert_eq!(
        request(&app_b, "POST", &resume_path, stale).await.0,
        StatusCode::CONFLICT
    );
    assert_eq!(
        request(&app_a, "POST", &resume_path, second_wait.clone())
            .await
            .0,
        StatusCode::OK
    );
    assert_eq!(
        request(&app_b, "POST", &resume_path, second_wait).await.1["recorded"],
        false
    );
    let final_task = poll(&app_b, "signal-worker", "workflow").await;
    let final_path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        final_task["task_id"].as_str().unwrap()
    );
    let finish = completion(
        &final_task,
        json!([{"type":"complete_workflow","result":envelope(value.clone())}]),
    );
    assert_eq!(
        request(&app_b, "POST", &final_path, finish.clone()).await.0,
        StatusCode::OK
    );
    assert_eq!(
        request(&app_a, "POST", &final_path, finish).await.1["recorded"],
        false
    );
    let history = request(&app_a, "GET", &history_path, Value::Null).await.1;
    let events = history["events"].as_array().unwrap();
    assert_eq!(
        events
            .iter()
            .map(|event| event["event_type"].as_str().unwrap())
            .collect::<Vec<_>>(),
        [
            "StartAccepted",
            "WorkflowStarted",
            "SignalReceived",
            "SignalReceived",
            "SignalWaitOpened",
            "MessageCursorAdvanced",
            "SignalApplied",
            "SignalWaitOpened",
            "MessageCursorAdvanced",
            "SignalApplied",
            "WorkflowCompleted"
        ]
    );
    for (index, (opened, applied)) in [(4, 6), (7, 9)].into_iter().enumerate() {
        assert_eq!(events[opened]["payload"]["sequence"], index + 1);
        assert_eq!(
            events[opened]["payload"]["signal_wait_id"],
            signals[index]["signal_wait_id"]
        );
        assert_eq!(
            events[applied]["payload"]["signal_id"],
            signals[index]["signal_id"]
        );
        assert_eq!(
            events[applied]["payload"]["workflow_command_id"],
            signals[index]["workflow_command_id"]
        );
        assert_eq!(
            events[applied]["payload"]["signal_wait_id"],
            signals[index]["signal_wait_id"]
        );
        assert_eq!(events[applied]["payload"]["sequence"], index + 1);
        assert_eq!(
            ValueCodec::new()
                .unwrap()
                .decode(
                    events[applied]["payload"]["value"]["blob"]
                        .as_str()
                        .unwrap()
                )
                .unwrap(),
            value
        );
    }
    assert_eq!(events[5]["payload"]["previous_position"], 0);
    assert_eq!(events[5]["payload"]["new_position"], 1);
    assert_eq!(events[8]["payload"]["previous_position"], 1);
    assert_eq!(events[8]["payload"]["new_position"], 2);
    assert!(poll(&app_a, "signal-worker", "workflow").await.is_null());
    a.close().await;
    b.close().await;
    database.remove().await;
}

#[tokio::test]
async fn durable_timer_survives_restart_and_two_nodes_fire_once_without_polling() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let started = start(&app, "durable-timer").await;
    register(&app, "worker", json!(["echo"]), json!([])).await;
    let task = poll(&app, "worker", "workflow").await;
    let path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        task["task_id"].as_str().unwrap()
    );
    let body = completion(&task, json!([{"type":"start_timer","delay_seconds":2}]));
    let scheduled = request(&app, "POST", &path, body.clone()).await;
    assert_eq!(scheduled.0, StatusCode::OK, "{}", scheduled.1);
    assert_eq!(
        request(&app, "POST", &path, body).await.1["recorded"],
        false
    );
    assert!(poll(&app, "worker", "workflow").await.is_null());
    let history_path = format!(
        "/api/workflows/durable-timer/runs/{}/history",
        started["run_id"].as_str().unwrap()
    );
    let before = request(&app, "GET", &history_path, Value::Null).await.1;
    assert_eq!(before["events"].as_array().unwrap().len(), 3);
    let timer = before["events"][2]["payload"].clone();
    runtime.close().await;

    let (a, b) = tokio::join!(database.open(), database.open());
    let (a, b) = (a.unwrap(), b.unwrap());
    let app_a = router(a.clone());
    let app_b = router(b.clone());
    // Timers must fire independently of a worker poll. Reopened nodes recover
    // only committed database state; no process-local timer survives close.
    let budget = tokio::time::Instant::now() + std::time::Duration::from_secs(10);
    let fired = loop {
        let history = request(&app_a, "GET", &history_path, Value::Null).await.1;
        if history["events"].as_array().unwrap().len() == 4 {
            break history;
        }
        assert!(
            tokio::time::Instant::now() < budget,
            "timer did not recover: {history}"
        );
        tokio::time::sleep(std::time::Duration::from_millis(50)).await;
    };
    let fired_payload = &fired["events"][3]["payload"];
    assert_eq!(fired["events"][3]["event_type"], "TimerFired");
    assert_eq!(fired_payload["timer_id"], timer["timer_id"]);
    assert_eq!(fired_payload["sequence"], 1);
    assert_eq!(fired_payload["fire_at"], timer["fire_at"]);
    assert!(
        chrono::DateTime::parse_from_rfc3339(fired_payload["fired_at"].as_str().unwrap()).unwrap()
            >= chrono::DateTime::parse_from_rfc3339(timer["fire_at"].as_str().unwrap()).unwrap()
    );
    let (claim_a, claim_b) = tokio::join!(
        poll(&app_a, "worker", "workflow"),
        poll(&app_b, "worker", "workflow")
    );
    assert_ne!(claim_a.is_null(), claim_b.is_null());
    let resumed = if claim_a.is_null() { claim_b } else { claim_a };
    assert_eq!(resumed["run_id"], started["run_id"]);
    let completed = request(
        &app_a,
        "POST",
        &format!(
            "/api/worker/workflow-tasks/{}/complete",
            resumed["task_id"].as_str().unwrap()
        ),
        completion(
            &resumed,
            json!([{"type":"complete_workflow","result":envelope(Payload::Long(7))}]),
        ),
    )
    .await;
    assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
    tokio::time::sleep(std::time::Duration::from_millis(300)).await;
    let history = request(&app_b, "GET", &history_path, Value::Null).await.1;
    assert_eq!(history["events"].as_array().unwrap().len(), 5);
    assert_eq!(history["events"][4]["event_type"], "WorkflowCompleted");
    a.close().await;
    b.close().await;
    database.remove().await;
}

#[tokio::test]
async fn null_task_availability_is_ready_but_future_work_remains_waiting() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let started = start(&app, "null-availability").await;
    register(&app, "worker", json!(["echo"]), json!(["activity-echo"])).await;

    let tomorrow = chrono::Utc::now() + chrono::Duration::days(1);
    database.set_availability("workflow", Some(tomorrow)).await;
    assert!(poll(&app, "worker", "workflow").await.is_null());
    database.set_availability("workflow", None).await;
    let task = poll(&app, "worker", "workflow").await;
    assert_eq!(task["run_id"], started["run_id"]);
    assert_eq!(task["workflow_task_attempt"], 1);
    assert!(poll(&app, "worker", "workflow").await.is_null());
    let path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        task["task_id"].as_str().unwrap()
    );
    let schedule = json!({"type":"schedule_activity","activity_type":"activity-echo","arguments":envelope(Payload::Array(vec![]))});
    assert_eq!(
        request(&app, "POST", &path, completion(&task, json!([schedule])))
            .await
            .0,
        StatusCode::OK
    );

    database.set_availability("activity", Some(tomorrow)).await;
    assert!(poll(&app, "worker", "activity").await.is_null());
    database.set_availability("activity", None).await;
    let activity = poll(&app, "worker", "activity").await;
    assert_eq!(activity["run_id"], started["run_id"]);
    assert_eq!(activity["attempt_number"], 1);
    assert!(poll(&app, "worker", "activity").await.is_null());
    let path = format!(
        "/api/worker/activity-tasks/{}/complete",
        activity["task_id"].as_str().unwrap()
    );
    let result = envelope(Payload::Long(7));
    let body = json!({"lease_owner":"worker","activity_attempt_id":activity["activity_attempt_id"],"result":result});
    assert_eq!(
        request(&app, "POST", &path, body.clone()).await.1["recorded"],
        true
    );
    assert_eq!(
        request(&app, "POST", &path, body).await.1["recorded"],
        false
    );
    let resumed = poll(&app, "worker", "workflow").await;
    let path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        resumed["task_id"].as_str().unwrap()
    );
    assert_eq!(
        request(
            &app,
            "POST",
            &path,
            completion(
                &resumed,
                json!([{"type":"complete_workflow","result":result}])
            )
        )
        .await
        .0,
        StatusCode::OK
    );
    let types: Vec<_> = resumed["history_events"]
        .as_array()
        .unwrap()
        .iter()
        .map(|e| e["event_type"].as_str().unwrap())
        .collect();
    assert_eq!(
        types,
        [
            "StartAccepted",
            "WorkflowStarted",
            "ActivityScheduled",
            "ActivityStarted",
            "ActivityCompleted"
        ]
    );
    let (status, described) =
        request(&app, "GET", "/api/workflows/null-availability", json!({})).await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(described["status"], "completed");
    assert_eq!(described["run_id"], started["run_id"]);
    assert_eq!(described["output_envelope"], result);
    let (status, history) = request(
        &app,
        "GET",
        &format!(
            "/api/workflows/null-availability/runs/{}/history",
            started["run_id"].as_str().unwrap()
        ),
        json!({}),
    )
    .await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(history["events"].as_array().unwrap().len(), 6);
    assert_eq!(history["events"][5]["event_type"], "WorkflowCompleted");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn cooperative_root_request_preserves_original_context_across_duplicates_and_fresh_pools() {
    let database = TestDatabase::new().await;
    let runtime_a = database.open().await.unwrap();
    let runtime_b = database.open().await.unwrap();
    let app_a = router(runtime_a.clone());
    let app_b = router(runtime_b.clone());
    let started = start(&app_a, "cooperative-root").await;
    let workflow = json!("cooperative-root");
    let run = started["run_id"].clone();
    let path = format!(
        "/api/workflows/cooperative-root/runs/{}/request-cancellation",
        run.as_str().unwrap()
    );
    let prefix = run_history(&app_b, &workflow, &run).await;
    let accepted = request(
        &app_a,
        "POST",
        &path,
        json!({"reason":"cooperative cancellation λ","cleanup_timeout_seconds":30}),
    )
    .await;
    // This is the tests-first native gap: the published root request contract
    // has already executed in PHP and embedded mode. Admission alone does not
    // establish cleanup, worker protocol support or a completed workflow.
    assert_eq!(accepted.0, StatusCode::ACCEPTED, "{}", accepted.1);
    assert_eq!(accepted.1["accepted"], true);
    assert_eq!(accepted.1["duplicate"], false);
    assert_eq!(accepted.1["run_status"], "pending");
    let pending = &accepted.1["cancellation_request"];
    assert!(
        pending["request_id"]
            .as_str()
            .is_some_and(|id| !id.is_empty())
    );
    assert_eq!(pending["delivery_sequence"], Value::Null);
    assert_eq!(pending["delivered_at"], Value::Null);
    let requested_at =
        chrono::DateTime::parse_from_rfc3339(pending["requested_at"].as_str().unwrap()).unwrap();
    let deadline =
        chrono::DateTime::parse_from_rfc3339(pending["cleanup_deadline_at"].as_str().unwrap())
            .unwrap();
    assert_eq!(
        (deadline - requested_at).num_microseconds(),
        Some(30_000_000)
    );
    let history = run_history(&app_b, &workflow, &run).await;
    let events = history.as_array().unwrap();
    assert_eq!(&events[..2], prefix.as_array().unwrap());
    assert_eq!(events.len(), 3);
    assert_eq!(events[2]["event_type"], "CooperativeCancellationRequested");
    let context = &events[2]["payload"]["cancellation"];
    assert_eq!(
        context["schema"],
        "durable-workflow.cancellation-context/v1"
    );
    assert_eq!(context["request_id"], pending["request_id"]);
    assert_eq!(context["root_request_id"], pending["request_id"]);
    assert_eq!(context["root_workflow_instance_id"], workflow);
    assert_eq!(context["root_workflow_run_id"], run);
    assert_eq!(context["parent_request_id"], Value::Null);
    assert_eq!(context["reason"], "cooperative cancellation λ");
    assert_eq!(context["requested_at"], pending["requested_at"]);
    assert_eq!(
        context["cleanup_deadline_at"],
        pending["cleanup_deadline_at"]
    );
    assert_eq!(
        context["requester"],
        json!({"type":"auth:token","id":"legacy-token","label":"Admin"})
    );
    assert_eq!(context["source"], "control_plane");
    assert_eq!(
        context["lineage"],
        json!([{"request_id":pending["request_id"],"workflow_instance_id":workflow,"workflow_run_id":run}])
    );
    for app in [&app_a, &app_b] {
        let duplicate = request(
            app,
            "POST",
            &path,
            json!({"reason":"replacement","cleanup_timeout_seconds":300}),
        )
        .await;
        assert_eq!(duplicate.0, StatusCode::OK, "{}", duplicate.1);
        assert_eq!(duplicate.1["duplicate"], true);
        assert_eq!(duplicate.1["cancellation_request"], *pending);
        assert_eq!(run_history(app, &workflow, &run).await, history);
    }
    runtime_a.close().await;
    runtime_b.close().await;
    let fresh = database.open().await.unwrap();
    let app = router(fresh.clone());
    let duplicate = request(
        &app,
        "POST",
        &path,
        json!({"reason":"fresh replacement","cleanup_timeout_seconds":3600}),
    )
    .await;
    assert_eq!(duplicate.0, StatusCode::OK, "{}", duplicate.1);
    assert_eq!(duplicate.1["cancellation_request"], *pending);
    assert_eq!(run_history(&app, &workflow, &run).await, history);
    fresh.close().await;
    database.remove().await;
}

#[tokio::test]
async fn cooperative_root_request_requires_original_capable_claim_not_later_registration() {
    for (original_protocol, original_capable) in [("1.19", true), ("1.20", false), ("1.20", true)] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        let registration = json!({"worker_id":"cooperative-owner","task_queue":"test","runtime":"php",
            "supported_workflow_types":["echo"],"supported_activity_types":[],
            "capabilities":if original_capable {json!(["cooperative_cancellation"])} else {json!([])}});
        assert_eq!(
            request(&app, "POST", "/api/worker/register", registration.clone())
                .await
                .0,
            StatusCode::CREATED
        );
        let started = start(&app, "immutable-claim").await;
        let workflow = json!("immutable-claim");
        let task = request_protocol(
            &app,
            "POST",
            "/api/worker/workflow-tasks/poll",
            json!({
            "worker_id":"cooperative-owner","task_queue":"test","timeout_seconds":0}),
            original_protocol,
        )
        .await;
        assert_eq!(task.0, StatusCode::OK, "{}", task.1);
        let task = &task.1["task"];
        assert!(!task.is_null());
        let prefix = run_history(&app, &workflow, &started["run_id"]).await;
        let mut replacement = registration;
        // Neither adding capabilities to an old claim nor removing them from
        // a capable claim rewrites the persisted original proof.
        replacement["capabilities"] = if original_protocol == "1.19" || !original_capable {
            json!(["cooperative_cancellation"])
        } else {
            json!([])
        };
        assert_eq!(
            request_protocol(&app, "POST", "/api/worker/register", replacement, "1.20")
                .await
                .0,
            StatusCode::CREATED
        );
        let requested = request(
            &app,
            "POST",
            &format!(
                "/api/workflows/immutable-claim/runs/{}/request-cancellation",
                started["run_id"].as_str().unwrap()
            ),
            json!({"reason":"original","cleanup_timeout_seconds":30}),
        )
        .await;
        if original_protocol == "1.19" || !original_capable {
            assert_eq!(requested.0, StatusCode::CONFLICT, "{}", requested.1);
            assert_eq!(
                requested.1["reason"],
                "active_claim_cancellation_not_supported"
            );
            assert_eq!(requested.1["task_id"], task["task_id"]);
            assert_eq!(
                requested.1["workflow_task_attempt"],
                task["workflow_task_attempt"]
            );
            assert_eq!(
                run_history(&app, &workflow, &started["run_id"]).await,
                prefix
            );
        } else {
            assert_eq!(requested.0, StatusCode::ACCEPTED, "{}", requested.1);
            assert_eq!(requested.1["run_status"], "running");
        }
        runtime.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn cooperative_requested_root_ignores_protocol_spoofing_and_requires_capable_poll() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let started = start(&app, "requested-root").await;
    let path = format!(
        "/api/workflows/requested-root/runs/{}/request-cancellation",
        started["run_id"].as_str().unwrap()
    );
    let accepted = request(
        &app,
        "POST",
        &path,
        json!({"reason":"original","cleanup_timeout_seconds":30}),
    )
    .await;
    assert_eq!(accepted.0, StatusCode::ACCEPTED, "{}", accepted.1);
    let registration = json!({"worker_id":"root-worker","task_queue":"test","runtime":"php",
        "supported_workflow_types":["echo"],"supported_activity_types":[],"capabilities":[]});
    assert_eq!(
        request(&app, "POST", "/api/worker/register", registration.clone())
            .await
            .0,
        StatusCode::CREATED
    );
    let poll_body = json!({"worker_id":"root-worker","task_queue":"test","timeout_seconds":0,"protocol_version":"1.20"});
    let incapable = request_protocol(
        &app,
        "POST",
        "/api/worker/workflow-tasks/poll",
        poll_body.clone(),
        "1.20",
    )
    .await;
    assert_eq!(incapable.0, StatusCode::OK, "{}", incapable.1);
    assert_eq!(incapable.1["task"], Value::Null);
    let mut capable = registration;
    capable["capabilities"] = json!(["cooperative_cancellation"]);
    assert_eq!(
        request(&app, "POST", "/api/worker/register", capable)
            .await
            .0,
        StatusCode::CREATED
    );
    let spoofed = request_protocol(
        &app,
        "POST",
        "/api/worker/workflow-tasks/poll",
        poll_body.clone(),
        "1.19",
    )
    .await;
    assert_eq!(spoofed.0, StatusCode::OK, "{}", spoofed.1);
    assert_eq!(spoofed.1["task"], Value::Null);
    let claimed = request_protocol(
        &app,
        "POST",
        "/api/worker/workflow-tasks/poll",
        poll_body,
        "1.20",
    )
    .await;
    assert_eq!(claimed.0, StatusCode::OK, "{}", claimed.1);
    assert_eq!(claimed.1["task"]["run_id"], started["run_id"]);
    assert_eq!(claimed.1["task"]["workflow_task_attempt"], 1);
    assert_eq!(
        claimed.1["task"]["cancellation_request"],
        accepted.1["cancellation_request"]
    );
    let expires = chrono::DateTime::parse_from_rfc3339(
        claimed.1["task"]["lease_expires_at"].as_str().unwrap(),
    )
    .unwrap();
    let deadline = chrono::DateTime::parse_from_rfc3339(
        accepted.1["cancellation_request"]["cleanup_deadline_at"]
            .as_str()
            .unwrap(),
    )
    .unwrap();
    assert!(expires <= deadline);
    assert!(expires <= chrono::Utc::now() + chrono::Duration::seconds(10));
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn cooperative_root_validation_and_concurrent_requests_preserve_one_original_command() {
    let database = TestDatabase::new().await;
    let a = database.open().await.unwrap();
    let b = database.open().await.unwrap();
    let app_a = router(a.clone());
    let app_b = router(b.clone());
    let started = start(&app_a, "concurrent-root").await;
    let workflow = json!("concurrent-root");
    let run = &started["run_id"];
    let path = format!(
        "/api/workflows/concurrent-root/runs/{}/request-cancellation",
        run.as_str().unwrap()
    );
    let prefix = run_history(&app_a, &workflow, run).await;
    assert_eq!(
        request(
            &app_a,
            "POST",
            "/api/workflows/concurrent-root/runs/missing/request-cancellation",
            json!({})
        )
        .await
        .0,
        StatusCode::NOT_FOUND
    );
    for budget in [json!(0), json!(-1), json!(3601), json!(1.5), json!(false)] {
        assert_eq!(
            request(
                &app_a,
                "POST",
                &path,
                json!({"cleanup_timeout_seconds":budget})
            )
            .await
            .0,
            StatusCode::UNPROCESSABLE_ENTITY
        );
    }
    assert_eq!(
        request(&app_a, "POST", &path, json!({"reason":"λ".repeat(1001)}))
            .await
            .0,
        StatusCode::UNPROCESSABLE_ENTITY
    );
    assert_eq!(run_history(&app_b, &workflow, run).await, prefix);
    let body = json!({"reason":" \t\0original λ\u{a0}","cleanup_timeout_seconds":30});
    let (left, right) = tokio::join!(
        request(&app_a, "POST", &path, body.clone()),
        request(&app_b, "POST", &path, body)
    );
    assert_eq!(
        [left.0, right.0]
            .iter()
            .filter(|status| **status == StatusCode::ACCEPTED)
            .count(),
        1
    );
    assert_eq!(
        [left.0, right.0]
            .iter()
            .filter(|status| **status == StatusCode::OK)
            .count(),
        1
    );
    assert_eq!(
        left.1["cancellation_request"],
        right.1["cancellation_request"]
    );
    let history = run_history(&app_b, &workflow, run).await;
    let events = history.as_array().unwrap();
    assert_eq!(events.len(), 3);
    assert_eq!(&events[..2], prefix.as_array().unwrap());
    assert_eq!(events[2]["payload"]["cancellation"]["reason"], "original λ");
    a.close().await;
    b.close().await;
    database.remove().await;
}

async fn cooperative_poll(app: &Router, kind: &str) -> Value {
    let response = request_protocol(
        app,
        "POST",
        &format!("/api/worker/{kind}-tasks/poll"),
        json!({"worker_id":"cleanup-worker","task_queue":"test","timeout_seconds":0}),
        "1.20",
    )
    .await;
    assert_eq!(response.0, StatusCode::OK, "{}", response.1);
    response.1["task"].clone()
}

#[tokio::test]
async fn cancellation_received_during_a_claim_resumes_after_the_ordinary_prefix_commit() {
    let database = TestDatabase::new().await;
    let original = database.open().await.unwrap();
    let app = router(original.clone());
    let registered = request_protocol(&app, "POST", "/api/worker/register",
        json!({"worker_id":"cleanup-worker","task_queue":"test","runtime":"php",
            "supported_workflow_types":["echo"],"supported_activity_types":[],"capabilities":["cooperative_cancellation"]}), "1.20").await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    let started = start(&app, "claimed-prefix").await;
    let workflow = json!("claimed-prefix");
    let run = started["run_id"].clone();
    let task = cooperative_poll(&app, "workflow").await;
    assert!(!task.is_null());
    let prefix = run_history(&app, &workflow, &run).await;
    let control = format!(
        "/api/workflows/claimed-prefix/runs/{}/request-cancellation",
        run.as_str().unwrap()
    );
    let requested = request(
        &app,
        "POST",
        &control,
        json!({"reason":"during ordinary prefix","cleanup_timeout_seconds":30}),
    )
    .await;
    assert_eq!(requested.0, StatusCode::ACCEPTED, "{}", requested.1);
    let pending = requested.1["cancellation_request"].clone();
    // The already claimed worker can commit an ordinary prefix before seeing
    // the new control request. That commit must leave a capable resumption.
    let completed = finish_task(
        &app,
        &task,
        json!([{"type":"start_timer","delay_seconds":60}]),
    )
    .await;
    assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
    let after_commit = run_history(&app, &workflow, &run).await;
    assert_eq!(
        &after_commit.as_array().unwrap()[..2],
        prefix.as_array().unwrap()
    );
    assert_eq!(
        after_commit[2]["event_type"],
        "CooperativeCancellationRequested"
    );
    assert_eq!(after_commit[3]["event_type"], "TimerScheduled");
    original.close().await;

    let fresh = database.open().await.unwrap();
    let app = router(fresh.clone());
    assert_eq!(run_history(&app, &workflow, &run).await, after_commit);
    let resumed = cooperative_poll(&app, "workflow").await;
    assert!(!resumed.is_null());
    assert_ne!(resumed["task_id"], task["task_id"]);
    assert_eq!(resumed["cancellation_request"], pending);
    let delivered = request_protocol(&app, "POST",
        &format!("/api/worker/workflow-tasks/{}/deliver-cancellation", resumed["task_id"].as_str().unwrap()),
        json!({"lease_owner":resumed["lease_owner"],"workflow_task_attempt":resumed["workflow_task_attempt"],
            "request_id":pending["request_id"],"sequence":1,"call_kind":"timer"}), "1.20").await;
    assert_eq!(delivered.0, StatusCode::OK, "{}", delivered.1);
    let finished = finish_task(
        &app,
        &resumed,
        json!([{"type":"complete_workflow","result":envelope(Payload::Null)}]),
    )
    .await;
    assert_eq!(finished.0, StatusCode::OK, "{}", finished.1);
    let history = run_history(&app, &workflow, &run).await;
    let types: Vec<_> = history
        .as_array()
        .unwrap()
        .iter()
        .map(|event| event["event_type"].as_str().unwrap())
        .collect();
    assert_eq!(
        types,
        [
            "StartAccepted",
            "WorkflowStarted",
            "CooperativeCancellationRequested",
            "TimerScheduled",
            "TimerCancelled",
            "CooperativeCancellationDelivered",
            "WorkflowCancelled"
        ]
    );
    assert!(cooperative_poll(&app, "workflow").await.is_null());
    let duplicate = request(
        &app,
        "POST",
        &control,
        json!({"reason":"replacement","cleanup_timeout_seconds":3600}),
    )
    .await;
    assert_eq!(duplicate.0, StatusCode::OK, "{}", duplicate.1);
    assert_eq!(
        duplicate.1["cancellation_request"]["request_id"],
        pending["request_id"]
    );
    assert_eq!(
        duplicate.1["cancellation_request"]["cleanup_deadline_at"],
        pending["cleanup_deadline_at"]
    );
    assert_eq!(run_history(&app, &workflow, &run).await, history);
    fresh.close().await;
    database.remove().await;
}

#[tokio::test]
async fn cooperative_delivery_cleanup_and_original_deadline_survive_fresh_runtime_pools() {
    for (phase, expired) in [
        ("before_claim", false),
        ("pending_timer", false),
        ("pending_timer", true),
    ] {
        let database = TestDatabase::new().await;
        let a = database.open().await.unwrap();
        let b = database.open().await.unwrap();
        let app_a = router(a.clone());
        let app_b = router(b.clone());
        assert_eq!(request_protocol(&app_a,"POST","/api/worker/register",json!({"worker_id":"cleanup-worker",
            "task_queue":"test","runtime":"php","supported_workflow_types":["echo"],
            "supported_activity_types":["echo-activity"],"capabilities":["cooperative_cancellation"]}),"1.20").await.0,StatusCode::CREATED);
        let started = start(&app_a, "cleanup-root").await;
        let workflow = json!("cleanup-root");
        let run = started["run_id"].clone();
        if phase == "pending_timer" {
            let original = cooperative_poll(&app_a, "workflow").await;
            let completed = finish_task(
                &app_b,
                &original,
                json!([{"type":"start_timer","delay_seconds":60}]),
            )
            .await;
            assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
        }
        let prefix = run_history(&app_b, &workflow, &run).await;
        let control = format!(
            "/api/workflows/cleanup-root/runs/{}/request-cancellation",
            run.as_str().unwrap()
        );
        let accepted = request(
            &app_a,
            "POST",
            &control,
            json!({"reason":"original λ","cleanup_timeout_seconds":if expired {5} else {30}}),
        )
        .await;
        assert_eq!(accepted.0, StatusCode::ACCEPTED, "{}", accepted.1);
        let pending = accepted.1["cancellation_request"].clone();
        let request_id = pending["request_id"].clone();
        let task = cooperative_poll(&app_b, "workflow").await;
        assert!(!task.is_null());
        let endpoint = format!(
            "/api/worker/workflow-tasks/{}/deliver-cancellation",
            task["task_id"].as_str().unwrap()
        );
        let delivery = json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],
            "request_id":request_id,"sequence":1,"call_kind":"timer","sequence_span":1});
        let before_delivery = run_history(&app_a, &workflow, &run).await;
        let old_protocol = request(&app_a, "POST", &endpoint, delivery.clone()).await;
        assert_eq!(old_protocol.0, StatusCode::CONFLICT);
        assert_eq!(
            old_protocol.1["reason"],
            "cooperative_cancellation_not_supported"
        );
        let mut wrong_request = delivery.clone();
        wrong_request["request_id"] = json!("replacement");
        let wrong = request_protocol(&app_a, "POST", &endpoint, wrong_request, "1.20").await;
        assert_eq!(wrong.0, StatusCode::CONFLICT);
        assert_eq!(wrong.1["reason"], "cancellation_request_mismatch");
        assert_eq!(run_history(&app_a, &workflow, &run).await, before_delivery);
        let delivered = request_protocol(&app_a, "POST", &endpoint, delivery.clone(), "1.20").await;
        assert_eq!(delivered.0, StatusCode::OK, "{}", delivered.1);
        assert_eq!(delivered.1["delivered"], true);
        let canonical_history = run_history(&app_a, &workflow, &run).await;
        let worker_history = request_protocol(&app_a, "POST",
            &format!("/api/worker/workflow-tasks/{}/history", task["task_id"].as_str().unwrap()),
            json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"]}), "1.20").await;
        assert_eq!(worker_history.0, StatusCode::OK, "{}", worker_history.1);
        let delivery_id = worker_history.1["history_events"]
            .as_array()
            .unwrap()
            .iter()
            .find(|event| event["event_type"] == "CooperativeCancellationDelivered")
            .unwrap()["id"]
            .clone();
        assert!(delivery_id.as_str().is_some_and(|id| !id.is_empty()));
        let repeated = request_protocol(&app_b, "POST", &endpoint, delivery.clone(), "1.20").await;
        assert_eq!(repeated, delivered);
        assert_eq!(
            run_history(&app_b, &workflow, &run).await,
            canonical_history
        );
        let mut wrong_boundary = delivery;
        wrong_boundary["sequence"] = json!(2);
        assert_eq!(
            request_protocol(&app_a, "POST", &endpoint, wrong_boundary, "1.20")
                .await
                .0,
            StatusCode::CONFLICT
        );
        assert_eq!(
            run_history(&app_b, &workflow, &run).await,
            canonical_history
        );
        let requested = canonical_history
            .as_array()
            .unwrap()
            .iter()
            .find(|event| event["event_type"] == "CooperativeCancellationRequested")
            .unwrap();
        let Payload::Map(command) = ValueCodec::new()
            .unwrap()
            .decode(
                requested["payload"]["command"]["payload"]["blob"]
                    .as_str()
                    .unwrap(),
            )
            .unwrap()
        else {
            panic!("official cancellation command map");
        };
        let context = command["cancellation"].clone();
        let renewal=request_protocol(&app_a,"POST",&format!("/api/worker/workflow-tasks/{}/heartbeat",task["task_id"].as_str().unwrap()),
            json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"]}),"1.20").await;
        assert_eq!(renewal.0, StatusCode::OK, "{}", renewal.1);
        assert_eq!(renewal.1["cancellation_request"]["request_id"], request_id);
        assert_eq!(
            renewal.1["cancellation_request"]["cleanup_deadline_at"],
            pending["cleanup_deadline_at"]
        );
        assert_eq!(renewal.1["cancellation_request"]["delivery_sequence"], 1);
        let cleanup_timer = finish_task(
            &app_a,
            &task,
            json!([{"type":"start_timer","delay_seconds":if expired {60} else {1}}]),
        )
        .await;
        assert_eq!(cleanup_timer.0, StatusCode::OK, "{}", cleanup_timer.1);
        let after_cleanup_started = run_history(&app_b, &workflow, &run).await;
        assert_eq!(
            after_cleanup_started.as_array().unwrap().last().unwrap()["payload"]["sequence"],
            2
        );
        a.close().await;
        b.close().await;
        let fresh = database.open().await.unwrap();
        let app = router(fresh.clone());
        let reopened_history = run_history(&app, &workflow, &run).await;
        let before_reopen = after_cleanup_started.as_array().unwrap();
        assert!(
            reopened_history
                .as_array()
                .unwrap()
                .starts_with(before_reopen)
        );
        let wait_deadline = tokio::time::Instant::now() + std::time::Duration::from_secs(10);
        let mut cleanup_activity = Value::Null;
        if expired {
            loop {
                let described =
                    request(&app, "GET", "/api/workflows/cleanup-root", json!({})).await;
                assert_eq!(described.0, StatusCode::OK, "{}", described.1);
                if described.1["status"] == "cancelled" {
                    break;
                }
                assert!(
                    tokio::time::Instant::now() < wait_deadline,
                    "ordinary scheduler enforces original root deadline"
                );
                tokio::time::sleep(std::time::Duration::from_millis(25)).await;
            }
        } else {
            let resumed = loop {
                let resumed = cooperative_poll(&app, "workflow").await;
                if !resumed.is_null() {
                    break resumed;
                }
                assert!(
                    tokio::time::Instant::now() < wait_deadline,
                    "ordinary cleanup timer fires"
                );
                tokio::time::sleep(std::time::Duration::from_millis(25)).await;
            };
            let arguments = envelope(Payload::Array(vec![
                Payload::Long(9007199254740993),
                context,
            ]));
            let scheduled=finish_task(&app,&resumed,json!([{"type":"schedule_activity","activity_type":"echo-activity","arguments":arguments}])).await;
            assert_eq!(scheduled.0, StatusCode::OK, "{}", scheduled.1);
            let activity = cooperative_poll(&app, "activity").await;
            assert!(!activity.is_null());
            cleanup_activity = activity.clone();
            let heartbeat_endpoint = format!(
                "/api/worker/activity-tasks/{}/heartbeat",
                activity["task_id"].as_str().unwrap()
            );
            let heartbeat = json!({"activity_attempt_id":activity["activity_attempt_id"],"lease_owner":activity["lease_owner"],"details":{"request_id":request_id}});
            let before_heartbeat = run_history(&app, &workflow, &run).await;
            let status_endpoint = format!(
                "/api/worker/activity-tasks/{}/status",
                activity["task_id"].as_str().unwrap()
            );
            let status_body = json!({"activity_attempt_id":activity["activity_attempt_id"],"lease_owner":activity["lease_owner"]});
            assert_eq!(
                request(&app, "POST", &status_endpoint, status_body.clone())
                    .await
                    .0,
                StatusCode::UNPROCESSABLE_ENTITY
            );
            let status =
                request_protocol(&app, "POST", &status_endpoint, status_body.clone(), "1.20").await;
            assert_eq!(status.0, StatusCode::OK, "{}", status.1);
            assert_eq!(status.1["can_continue"], true);
            assert_eq!(status.1["cancel_requested"], false);
            assert_eq!(status.1["heartbeat_recorded"], false);
            assert_eq!(status.1["lease_expires_at"], activity["lease_expires_at"]);
            assert_eq!(status.1["last_heartbeat_at"], Value::Null);
            let mut wrong_owner = status_body.clone();
            wrong_owner["lease_owner"] = json!("other-owner");
            assert_eq!(
                request_protocol(&app, "POST", &status_endpoint, wrong_owner, "1.20")
                    .await
                    .0,
                StatusCode::CONFLICT
            );
            let mut missing_attempt = status_body;
            missing_attempt["activity_attempt_id"] = json!("missing-attempt");
            assert_eq!(
                request_protocol(&app, "POST", &status_endpoint, missing_attempt, "1.20")
                    .await
                    .0,
                StatusCode::NOT_FOUND
            );
            assert_eq!(run_history(&app, &workflow, &run).await, before_heartbeat);
            let mut stale = heartbeat.clone();
            stale["activity_attempt_id"] = json!("stale-attempt");
            let refused = request_protocol(&app, "POST", &heartbeat_endpoint, stale, "1.20").await;
            assert_eq!(refused.0, StatusCode::CONFLICT);
            assert_eq!(refused.1["reason"], "stale_attempt");
            assert_eq!(run_history(&app, &workflow, &run).await, before_heartbeat);
            let recorded =
                request_protocol(&app, "POST", &heartbeat_endpoint, heartbeat, "1.20").await;
            assert_eq!(recorded.0, StatusCode::OK, "{}", recorded.1);
            assert_eq!(recorded.1["heartbeat_recorded"], true);
            let result = envelope(Payload::Long(9007199254740993));
            let completed=request_protocol(&app,"POST",&format!("/api/worker/activity-tasks/{}/complete",activity["task_id"].as_str().unwrap()),
                json!({"activity_attempt_id":activity["activity_attempt_id"],"lease_owner":activity["lease_owner"],"result":result}),"1.20").await;
            assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
            let resumed = cooperative_poll(&app, "workflow").await;
            let finished = finish_task(
                &app,
                &resumed,
                json!([{"type":"complete_workflow","result":result}]),
            )
            .await;
            assert_eq!(finished.0, StatusCode::OK, "{}", finished.1);
        }
        let history = run_history(&app, &workflow, &run).await;
        let events = history.as_array().unwrap();
        assert_eq!(
            &events[..prefix.as_array().unwrap().len()],
            prefix.as_array().unwrap()
        );
        let terminal = events.last().unwrap();
        assert_eq!(terminal["event_type"], "WorkflowCancelled");
        let cleanup = &terminal["payload"]["cancellation_cleanup"];
        assert_eq!(cleanup["request_id"], request_id);
        assert_eq!(
            cleanup["cleanup_deadline_at"],
            pending["cleanup_deadline_at"]
        );
        assert_eq!(cleanup["delivery_history_event_id"], delivery_id);
        assert_eq!(cleanup["delivery_sequence"], 1);
        assert_eq!(
            cleanup["outcome"],
            if expired {
                "deadline_expired"
            } else {
                "completed"
            }
        );
        let deadline =
            chrono::DateTime::parse_from_rfc3339(pending["cleanup_deadline_at"].as_str().unwrap())
                .unwrap();
        let finished =
            chrono::DateTime::parse_from_rfc3339(cleanup["finished_at"].as_str().unwrap()).unwrap();
        assert_eq!(finished >= deadline, expired);
        assert!(
            !events
                .iter()
                .any(|event| event["event_type"] == "WorkflowCompleted")
        );
        let types: Vec<_> = events
            .iter()
            .map(|event| event["event_type"].as_str().unwrap())
            .collect();
        let fixture: Value = serde_json::from_str(match (phase, expired) {
            (_, true) => {
                include_str!("../../tests/Fixtures/ServerParity/cooperative-cleanup-deadline.json")
            }
            ("before_claim", _) => include_str!(
                "../../tests/Fixtures/ServerParity/cooperative-before-claim-cleanup.json"
            ),
            _ => include_str!(
                "../../tests/Fixtures/ServerParity/cooperative-pending-timer-cleanup.json"
            ),
        })
        .unwrap();
        assert_eq!(json!(types), fixture["expected_events"]);
        let duplicate = request(
            &app,
            "POST",
            &control,
            json!({"reason":"replacement","cleanup_timeout_seconds":3600}),
        )
        .await;
        assert_eq!(duplicate.0, StatusCode::OK, "{}", duplicate.1);
        assert_eq!(duplicate.1["duplicate"], true);
        assert_eq!(
            duplicate.1["cancellation_request"]["cleanup_deadline_at"],
            pending["cleanup_deadline_at"]
        );
        assert_eq!(run_history(&app, &workflow, &run).await, history);
        for operation in ["heartbeat", "deliver-cancellation", "complete"] {
            let body = match operation {
                "deliver-cancellation" => {
                    json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],
                    "request_id":request_id,"sequence":1,"call_kind":"timer"})
                }
                "complete" => {
                    json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],
                    "commands":[{"type":"complete_workflow","result":envelope(Payload::Null)}]})
                }
                _ => {
                    json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"]})
                }
            };
            let late = request_protocol(
                &app,
                "POST",
                &format!(
                    "/api/worker/workflow-tasks/{}/{operation}",
                    task["task_id"].as_str().unwrap()
                ),
                body,
                "1.20",
            )
            .await;
            assert_eq!(late.0, StatusCode::CONFLICT, "{operation}: {}", late.1);
            assert_eq!(run_history(&app, &workflow, &run).await, history);
        }
        assert_eq!(
            database
                .cancellation_failure_count(
                    run.as_str().unwrap(),
                    terminal["payload"]["failure_id"].as_str().unwrap()
                )
                .await,
            1
        );
        let described = request(&app, "GET", "/api/workflows/cleanup-root", json!({})).await;
        assert_eq!(described.1["status"], "cancelled");
        assert_eq!(described.1["output_envelope"], Value::Null);
        assert_eq!(cleanup["finished_at"], described.1["closed_at"]);
        if !cleanup_activity.is_null() {
            let observed = request_protocol(&app,"POST",
                &format!("/api/worker/activity-tasks/{}/status",cleanup_activity["task_id"].as_str().unwrap()),
                json!({"lease_owner":cleanup_activity["lease_owner"],"activity_attempt_id":cleanup_activity["activity_attempt_id"]}),"1.20").await;
            assert_eq!(observed.0, StatusCode::OK, "{}", observed.1);
            assert_eq!(observed.1["can_continue"], false);
            assert_eq!(observed.1["cancel_requested"], true);
            assert_eq!(observed.1["reason"], "run_cancelled");
            assert_eq!(observed.1["lease_expires_at"], Value::Null);
            assert_eq!(run_history(&app, &workflow, &run).await, history);
        }
        fresh.close().await;
        let recovered = database.open().await.unwrap();
        assert_eq!(
            run_history(&router(recovered.clone()), &workflow, &run).await,
            history
        );
        recovered.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn immediate_cancellation_closes_original_work_once_and_survives_fresh_pools() {
    for phase in ["before_claim", "pending_timer", "leased_activity"] {
        let database = TestDatabase::new().await;
        let runtime_a = database.open().await.unwrap();
        let runtime_b = database.open().await.unwrap();
        let app_a = router(runtime_a.clone());
        let app_b = router(runtime_b.clone());
        register(
            &app_a,
            "cancel-a",
            json!(["echo"]),
            json!(["echo-activity"]),
        )
        .await;
        register(
            &app_b,
            "cancel-b",
            json!(["echo"]),
            json!(["echo-activity"]),
        )
        .await;
        let started = start(&app_a, phase).await;
        let workflow = json!(phase);
        let run = started["run_id"].clone();
        let path = format!(
            "/api/workflows/{phase}/runs/{}/cancel",
            run.as_str().unwrap()
        );
        let mut activity_task = Value::Null;
        if phase != "before_claim" {
            let task = poll(&app_a, "cancel-a", "workflow").await;
            let command = if phase == "pending_timer" {
                json!({"type":"start_timer","delay_seconds":60})
            } else {
                json!({"type":"schedule_activity","activity_type":"echo-activity","arguments":envelope(Payload::Array(vec![Payload::Long(9007199254740993)]))})
            };
            let completed = finish_task(&app_a, &task, json!([command])).await;
            assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
            if phase == "leased_activity" {
                activity_task = poll(&app_b, "cancel-b", "activity").await;
                assert!(!activity_task.is_null());
            }
        }
        let prefix = run_history(&app_b, &workflow, &run).await;
        let wrong_run = request(
            &app_b,
            "POST",
            &format!("/api/workflows/{phase}/runs/another-run/cancel"),
            json!({"reason":"replacement"}),
        )
        .await;
        assert_eq!(wrong_run.0, StatusCode::CONFLICT);
        assert_eq!(wrong_run.1["reason"], "historical_run_command_rejected");
        let invalid_reason =
            request(&app_b, "POST", &path, json!({"reason":"λ".repeat(1001)})).await;
        assert_eq!(invalid_reason.0, StatusCode::UNPROCESSABLE_ENTITY);
        assert_eq!(run_history(&app_b, &workflow, &run).await, prefix);
        // Published HTTP normalizes the reason before validation. Embedded
        // attemptCancel preserves its reason; the shared fixture declares both.
        let body = json!({"reason":" \t\0\u{a0}parity cancellation λ\u{200b}\u{1d173}\r\n"});
        let (left, right) = tokio::join!(
            request(&app_a, "POST", &path, body.clone()),
            request(&app_b, "POST", &path, body.clone())
        );
        assert_eq!(
            [left.0, right.0]
                .iter()
                .filter(|s| **s == StatusCode::OK)
                .count(),
            1,
            "{left:?} {right:?}"
        );
        assert_eq!(
            [left.0, right.0]
                .iter()
                .filter(|s| **s == StatusCode::CONFLICT)
                .count(),
            1
        );
        let accepted = if left.0 == StatusCode::OK {
            left.1
        } else {
            right.1
        };
        assert_eq!(accepted["outcome"], "cancelled");
        let history = run_history(&app_a, &workflow, &run).await;
        let events = history.as_array().unwrap();
        assert_eq!(
            &events[..prefix.as_array().unwrap().len()],
            prefix.as_array().unwrap()
        );
        let requested = &events[prefix.as_array().unwrap().len()];
        let terminal = events.last().unwrap();
        assert_eq!(requested["event_type"], "CancelRequested");
        assert_eq!(terminal["event_type"], "WorkflowCancelled");
        assert_eq!(
            requested["payload"]["workflow_command_id"],
            accepted["command_id"]
        );
        assert_eq!(
            terminal["payload"]["workflow_command_id"],
            accepted["command_id"]
        );
        assert_eq!(terminal["payload"]["reason"], "parity cancellation λ");
        assert_eq!(
            terminal["payload"]["exception_class"],
            "Workflow\\V2\\Exceptions\\WorkflowCancelledException"
        );
        assert_eq!(
            database
                .cancellation_failure_count(
                    run.as_str().unwrap(),
                    terminal["payload"]["failure_id"].as_str().unwrap()
                )
                .await,
            1
        );
        if phase == "pending_timer" {
            let scheduled = &events[2]["payload"];
            let cancelled = &events[4]["payload"];
            assert_eq!(events[4]["event_type"], "TimerCancelled");
            for field in ["timer_id", "sequence", "delay_seconds", "fire_at"] {
                assert_eq!(cancelled[field], scheduled[field]);
            }
        }
        let stale_path = format!(
            "/api/worker/activity-tasks/{}/complete",
            activity_task["task_id"].as_str().unwrap_or("unused")
        );
        let stale_body = json!({"lease_owner":activity_task["lease_owner"],"activity_attempt_id":activity_task["activity_attempt_id"],"result":envelope(Payload::Long(9007199254740993))});
        if phase == "leased_activity" {
            let cancelled = &events[5]["payload"];
            assert_eq!(events[5]["event_type"], "ActivityCancelled");
            assert_eq!(
                cancelled["activity_execution_id"],
                activity_task["activity_execution_id"]
            );
            assert_eq!(
                cancelled["activity_attempt_id"],
                activity_task["activity_attempt_id"]
            );
            assert_eq!(
                cancelled["activity_attempt"]["task_id"],
                activity_task["task_id"]
            );
            assert_eq!(
                cancelled["activity_attempt"]["lease_expires_at"],
                Value::Null
            );
            let stale = request(&app_a, "POST", &stale_path, stale_body.clone()).await;
            assert_eq!(stale.0, StatusCode::CONFLICT, "{}", stale.1);
            assert_eq!(stale.1["reason"], "run_cancelled");
            assert_eq!(stale.1["recorded"], false);
            assert_eq!(stale.1["task_id"], activity_task["task_id"]);
            assert_eq!(
                stale.1["activity_attempt_id"],
                activity_task["activity_attempt_id"]
            );
        }
        runtime_a.close().await;
        let runtime_c = database.open().await.unwrap();
        let app_c = router(runtime_c.clone());
        let repeated = request(
            &app_c,
            "POST",
            &path,
            json!({"reason":"replacement reason"}),
        )
        .await;
        assert_eq!(repeated.0, StatusCode::CONFLICT, "{}", repeated.1);
        assert_eq!(repeated.1["rejection_reason"], "run_not_active");
        assert_ne!(repeated.1["command_id"], accepted["command_id"]);
        let description = request(
            &app_c,
            "GET",
            &format!("/api/workflows/{phase}"),
            Value::Null,
        )
        .await
        .1;
        assert_eq!(description["status"], "cancelled");
        assert_eq!(description["status_bucket"], "closed");
        assert_eq!(description["is_terminal"], true);
        assert_eq!(description["closed_reason"], "cancelled");
        assert_eq!(
            ValueCodec::new()
                .unwrap()
                .decode(description["input_envelope"]["blob"].as_str().unwrap())
                .unwrap(),
            Payload::Array(vec![Payload::Long(9007199254740993)])
        );
        assert!(poll(&app_c, "cancel-a", "workflow").await.is_null());
        assert!(poll(&app_c, "cancel-a", "activity").await.is_null());
        if phase == "leased_activity" {
            assert_eq!(
                request(&app_c, "POST", &stale_path, stale_body).await.0,
                StatusCode::CONFLICT
            );
        }
        assert_eq!(run_history(&app_c, &workflow, &run).await, history);
        assert_eq!(
            database
                .cancellation_failure_count(
                    run.as_str().unwrap(),
                    terminal["payload"]["failure_id"].as_str().unwrap()
                )
                .await,
            1
        );
        runtime_b.close().await;
        runtime_c.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn nul_cancellation_preserves_reason_and_complete_durable_diagnostic() {
    for (reason, normalized) in [
        ("parity\0cancellation λ", "parity\0cancellation λ"),
        ("\0parity cancellation λ", "parity cancellation λ"),
    ] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        register(&app, "nul-worker", json!(["echo"]), json!([])).await;
        let started = start(&app, "nul-cancellation").await;
        let workflow = json!("nul-cancellation");
        let run = started["run_id"].clone();
        let path = format!(
            "/api/workflows/nul-cancellation/runs/{}/cancel",
            run.as_str().unwrap()
        );
        let receipt = request(&app, "POST", &path, json!({"reason":reason})).await;
        assert_eq!(receipt.0, StatusCode::OK, "{}", receipt.1);
        assert_eq!(receipt.1["outcome"], "cancelled");
        let history = run_history(&app, &workflow, &run).await;
        let events = history.as_array().unwrap();
        assert_eq!(events.len(), 4);
        assert_eq!(events[2]["event_type"], "CancelRequested");
        assert_eq!(events[3]["event_type"], "WorkflowCancelled");
        for event in &events[2..] {
            assert_eq!(event["payload"]["reason"], normalized);
            assert_eq!(
                event["payload"]["workflow_command_id"],
                receipt.1["command_id"]
            );
        }
        let diagnostic = format!("Workflow cancelled: {normalized}");
        let expected = if normalized.contains('\0') {
            serde_json::to_string(&diagnostic).unwrap()
        } else {
            diagnostic.clone()
        };
        let terminal = &events[3]["payload"];
        assert_eq!(terminal["message"], expected);
        assert!(!expected.contains('\0'));
        if normalized.contains('\0') {
            assert_eq!(
                serde_json::from_str::<String>(&expected).unwrap(),
                diagnostic
            );
        }
        let failure = terminal["failure_id"].as_str().unwrap();
        assert_eq!(
            database.cancellation_failure_message(failure).await,
            expected
        );
        assert_eq!(
            database
                .cancellation_failure_count(run.as_str().unwrap(), failure)
                .await,
            1
        );
        runtime.close().await;
        let recovered = database.open().await.unwrap();
        let fresh = router(recovered.clone());
        let duplicate = request(&fresh, "POST", &path, json!({"reason":"replacement"})).await;
        assert_eq!(duplicate.0, StatusCode::CONFLICT);
        assert_eq!(run_history(&fresh, &workflow, &run).await, history);
        let described = request(
            &fresh,
            "GET",
            "/api/workflows/nul-cancellation",
            Value::Null,
        )
        .await;
        assert_eq!(described.1["status"], "cancelled");
        assert_eq!(
            ValueCodec::new()
                .unwrap()
                .decode(described.1["input_envelope"]["blob"].as_str().unwrap())
                .unwrap(),
            Payload::Array(vec![Payload::Long(9007199254740993)])
        );
        assert!(poll(&fresh, "nul-worker", "workflow").await.is_null());
        assert_eq!(
            database.cancellation_failure_message(failure).await,
            expected
        );
        assert_eq!(
            database
                .cancellation_failure_count(run.as_str().unwrap(), failure)
                .await,
            1
        );
        recovered.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn completion_survives_restart_and_duplicate_receipt_adds_no_history() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let started = start(&app, "persistent").await;
    register(&app, "worker", json!(["echo"]), json!([])).await;
    let task = poll(&app, "worker", "workflow").await;
    let body = completion(
        &task,
        json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}]),
    );
    let path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        task["task_id"].as_str().unwrap()
    );
    let outcome = request(&app, "POST", &path, body.clone()).await;
    assert_eq!(outcome.0, StatusCode::OK, "{}", outcome.1);
    assert_eq!(outcome.1["recorded"], true);
    assert_eq!(
        request(&app, "POST", &path, body.clone()).await.1["recorded"],
        false
    );
    let mut conflicting = body;
    conflicting["commands"][0]["result"] = envelope(Payload::Long(42));
    assert_eq!(
        request(&app, "POST", &path, conflicting).await.0,
        StatusCode::CONFLICT
    );
    runtime.close().await;
    let reopened = database.open().await.unwrap();
    let app = router(reopened.clone());
    let description = request(&app, "GET", "/api/workflows/persistent", Value::Null).await;
    assert_eq!(description.1["status"], "completed");
    let codec = ValueCodec::new().unwrap();
    assert_eq!(
        codec
            .decode(description.1["output_envelope"]["blob"].as_str().unwrap())
            .unwrap(),
        Payload::Long(9007199254740993)
    );
    let first = request(
        &app,
        "GET",
        &format!(
            "/api/workflows/persistent/runs/{}/history?page_size=2",
            started["run_id"].as_str().unwrap()
        ),
        Value::Null,
    )
    .await
    .1;
    assert_eq!(first["events"].as_array().unwrap().len(), 2);
    let next = request(
        &app,
        "GET",
        &format!(
            "/api/workflows/persistent/runs/{}/history?page_size=2&next_page_token={}",
            started["run_id"].as_str().unwrap(),
            first["next_page_token"].as_str().unwrap()
        ),
        Value::Null,
    )
    .await
    .1;
    assert_eq!(next["events"].as_array().unwrap().len(), 1);
    assert_eq!(next["events"][0]["event_type"], "WorkflowCompleted");
    reopened.close().await;
    database.remove().await;
}

#[tokio::test]
async fn independent_nodes_claim_once_and_revoked_attempt_cannot_commit() {
    let database = TestDatabase::new().await;
    let node_a = database.open().await.unwrap();
    let node_b = database.open().await.unwrap();
    let app_a = router(node_a.clone());
    let app_b = router(node_b.clone());
    start(&app_a, "fenced").await;
    register(&app_a, "worker-a", json!(["echo"]), json!([])).await;
    register(&app_b, "worker-b", json!(["echo"]), json!([])).await;
    let (a, b) = tokio::join!(
        poll(&app_a, "worker-a", "workflow"),
        poll(&app_b, "worker-b", "workflow")
    );
    assert_ne!(
        a.is_null(),
        b.is_null(),
        "exactly one node obtains the durable claim"
    );
    let old = if a.is_null() { b } else { a };
    let owner = old["lease_owner"].as_str().unwrap();
    request(
        &app_a,
        "DELETE",
        &format!("/api/worker/registrations/{owner}"),
        Value::Null,
    )
    .await;
    let new_owner = if owner == "worker-a" {
        "worker-b"
    } else {
        "worker-a"
    };
    let new = poll(&app_b, new_owner, "workflow").await;
    assert_eq!(new["task_id"], old["task_id"]);
    assert_eq!(new["workflow_task_attempt"], 2);
    let path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        old["task_id"].as_str().unwrap()
    );
    let commands = json!([{"type":"complete_workflow","result":envelope(Payload::Null)}]);
    assert_eq!(
        request(&app_a, "POST", &path, completion(&old, commands.clone()))
            .await
            .0,
        StatusCode::CONFLICT
    );
    assert_eq!(
        request(&app_b, "POST", &path, completion(&new, commands))
            .await
            .0,
        StatusCode::OK
    );
    node_a.close().await;
    node_b.close().await;
    database.remove().await;
}

#[tokio::test]
async fn reported_activity_failure_commits_one_persistent_retry_and_fences_old_outcomes() {
    let database = TestDatabase::new().await;
    let node_a = database.open().await.unwrap();
    let node_b = database.open().await.unwrap();
    let app_a = router(node_a.clone());
    let app_b = router(node_b.clone());
    let started = start(&app_a, "retry").await;
    register(&app_a, "worker", json!(["echo"]), json!(["retry-activity"])).await;
    let workflow = poll(&app_a, "worker", "workflow").await;
    let arguments = envelope(Payload::Array(vec![Payload::Long(9007199254740993)]));
    let policy = json!({"max_attempts":2,"backoff_seconds":[1],"non_retryable_error_types":[]});
    for invalid in [
        json!({"max_attempts":0}),
        json!({"max_attempts":"2"}),
        json!({"max_attempts":1.5}),
        json!({"max_attempts":2,"backoff_seconds":[-1]}),
        json!({"max_attempts":2,"backoff_seconds":[i64::MAX]}),
        json!({"max_attempts":2,"non_retryable_error_types":[17]}),
        json!({"max_attempts":2,"unsupported":true}),
    ] {
        let refused = finish_task(&app_a,&workflow,json!([{"type":"schedule_activity","activity_type":"retry-activity","arguments":arguments,"retry_policy":invalid}])).await;
        assert_eq!(refused.0, StatusCode::UNPROCESSABLE_ENTITY, "{}", refused.1);
        assert!(poll(&app_b, "worker", "activity").await.is_null());
        assert_eq!(
            run_history(&app_b, &started["workflow_id"], &started["run_id"])
                .await
                .as_array()
                .unwrap()
                .len(),
            2
        );
    }
    let scheduled = finish_task(&app_a, &workflow, json!([{"type":"schedule_activity","activity_type":"retry-activity","arguments":arguments,"retry_policy":policy}])).await;
    assert_eq!(scheduled.0, StatusCode::OK, "{}", scheduled.1);
    let first = poll(&app_a, "worker", "activity").await;
    assert_eq!(first["attempt_number"], 1);
    let fail_path = format!(
        "/api/worker/activity-tasks/{}/fail",
        first["task_id"].as_str().unwrap()
    );
    let fail = json!({"lease_owner":"worker","activity_attempt_id":first["activity_attempt_id"],"failure":{"type":"RuntimeException","message":"retry λ","non_retryable":false}});
    let wrong_owner = json!({"lease_owner":"wrong","activity_attempt_id":first["activity_attempt_id"],"failure":fail["failure"]});
    assert_eq!(
        request(&app_b, "POST", &fail_path, wrong_owner).await.0,
        StatusCode::CONFLICT
    );
    assert_eq!(
        run_history(&app_b, &started["workflow_id"], &started["run_id"])
            .await
            .as_array()
            .unwrap()
            .len(),
        4
    );
    let (one, two) = tokio::join!(
        request(&app_a, "POST", &fail_path, fail.clone()),
        request(&app_b, "POST", &fail_path, fail.clone())
    );
    assert_eq!(one.0, StatusCode::OK, "{}", one.1);
    assert_eq!(two.0, StatusCode::OK, "{}", two.1);
    assert_ne!(one.1["recorded"], two.1["recorded"]);
    let receipt = if one.1["recorded"] == true {
        one.1
    } else {
        two.1
    };
    let original = run_history(&app_b, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(original.as_array().unwrap().len(), 5);
    let retry = &original[4]["payload"];
    assert_eq!(retry["retry_task_id"], receipt["next_task_id"]);
    assert_eq!(retry["retry_of_task_id"], first["task_id"]);
    assert_eq!(
        retry["retry_after_attempt_id"],
        first["activity_attempt_id"]
    );
    assert_eq!(retry["activity_attempt"]["status"], "failed");
    assert!(poll(&app_b, "worker", "workflow").await.is_null());
    assert!(
        poll(&app_b, "worker", "activity").await.is_null(),
        "retry waits the actual one-second backoff"
    );
    let mut conflicting = fail.clone();
    conflicting["failure"]["message"] = json!("different");
    assert_eq!(
        request(&app_b, "POST", &fail_path, conflicting).await.0,
        StatusCode::CONFLICT
    );
    let old_complete_path = format!(
        "/api/worker/activity-tasks/{}/complete",
        first["task_id"].as_str().unwrap()
    );
    let old_complete = json!({"lease_owner":"worker","activity_attempt_id":first["activity_attempt_id"],"result":envelope(Payload::Long(-1))});
    assert_eq!(
        request(&app_b, "POST", &old_complete_path, old_complete.clone())
            .await
            .0,
        StatusCode::CONFLICT
    );
    node_a.close().await;
    let recovered = database.open().await.unwrap();
    let app_c = router(recovered.clone());
    let available =
        chrono::DateTime::parse_from_rfc3339(retry["retry_available_at"].as_str().unwrap())
            .unwrap();
    let wait = (available.with_timezone(&chrono::Utc) - chrono::Utc::now())
        .to_std()
        .unwrap_or_default();
    tokio::time::sleep(wait + std::time::Duration::from_millis(10)).await;
    let (left, right) = tokio::join!(
        poll(&app_b, "worker", "activity"),
        poll(&app_c, "worker", "activity")
    );
    assert_ne!(
        left.is_null(),
        right.is_null(),
        "one retry claim across independent nodes"
    );
    let second = if left.is_null() { right } else { left };
    assert_eq!(second["task_id"], retry["retry_task_id"]);
    assert_eq!(
        second["activity_execution_id"],
        first["activity_execution_id"]
    );
    assert_eq!(second["idempotency_key"], first["idempotency_key"]);
    assert_eq!(second["run_id"], started["run_id"]);
    assert_eq!(second["arguments"], arguments);
    assert_eq!(second["attempt_number"], 2);
    assert_ne!(second["activity_attempt_id"], first["activity_attempt_id"]);
    assert_eq!(
        request(&app_b, "POST", &old_complete_path, old_complete)
            .await
            .0,
        StatusCode::CONFLICT
    );
    assert_eq!(
        request(&app_c, "POST", &fail_path, fail.clone()).await.1["recorded"],
        false
    );
    assert!(poll(&app_b, "worker", "workflow").await.is_null());
    let complete_path = format!(
        "/api/worker/activity-tasks/{}/complete",
        second["task_id"].as_str().unwrap()
    );
    assert_eq!(
        run_history(&app_b, &started["workflow_id"], &started["run_id"])
            .await
            .as_array()
            .unwrap()
            .len(),
        6
    );
    let result = envelope(Payload::Long(9007199254740993));
    let complete = json!({"lease_owner":"worker","activity_attempt_id":second["activity_attempt_id"],"result":result});
    assert_eq!(
        request(&app_c, "POST", &complete_path, complete.clone())
            .await
            .0,
        StatusCode::OK
    );
    assert_eq!(
        request(&app_b, "POST", &complete_path, complete).await.1["recorded"],
        false
    );
    let (left, right) = tokio::join!(
        poll(&app_b, "worker", "workflow"),
        poll(&app_c, "worker", "workflow")
    );
    assert_ne!(
        left.is_null(),
        right.is_null(),
        "one parent resumption after the successful retry"
    );
    let resumed = if left.is_null() { right } else { left };
    assert_eq!(resumed["history_events"].as_array().unwrap().len(), 7);
    let completed = finish_task(
        &app_c,
        &resumed,
        json!([{"type":"complete_workflow","result":result}]),
    )
    .await;
    assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
    let history = run_history(&app_b, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(history.as_array().unwrap().len(), 8);
    assert_eq!(
        history[4], original[4],
        "retry receipt/history remains immutable after recovery"
    );
    assert_eq!(
        request(&app_b, "POST", &fail_path, fail).await.1["recorded"],
        false
    );
    assert_eq!(
        run_history(&app_b, &started["workflow_id"], &started["run_id"]).await,
        history
    );
    node_b.close().await;
    recovered.close().await;
    database.remove().await;
}

#[tokio::test]
async fn terminal_activity_failures_resume_once_and_preserve_original_failure() {
    // Budget exhaustion, exact filters, explicit report and default policy.
    for (policy, count, reported_non_retryable, non_retryable) in [
        (
            json!({"max_attempts":2,"backoff_seconds":[0],"non_retryable_error_types":[]}),
            2,
            false,
            false,
        ),
        (
            json!({"max_attempts":3,"backoff_seconds":[1],"non_retryable_error_types":["RuntimeException"]}),
            1,
            false,
            true,
        ),
        (
            json!({"max_attempts":2,"backoff_seconds":[0],"non_retryable_error_types":["\u{2003}RuntimeException\u{2003}"]}),
            2,
            false,
            false,
        ),
        (
            json!({"max_attempts":3,"backoff_seconds":[1],"non_retryable_error_types":[]}),
            1,
            true,
            true,
        ),
        (Value::Null, 1, false, false),
    ] {
        let database = TestDatabase::new().await;
        let node_a = database.open().await.unwrap();
        let node_b = database.open().await.unwrap();
        let app_a = router(node_a.clone());
        let app_b = router(node_b.clone());
        let started = start(&app_a, "terminal").await;
        register(
            &app_a,
            "worker",
            json!(["echo"]),
            json!(["terminal-activity"]),
        )
        .await;
        let workflow = poll(&app_a, "worker", "workflow").await;
        let arguments = envelope(Payload::Array(vec![Payload::Long(9007199254740993)]));
        let scheduled = finish_task(&app_a, &workflow, json!([{"type":"schedule_activity","activity_type":"terminal-activity","arguments":arguments,"retry_policy":policy}])).await;
        assert_eq!(scheduled.0, StatusCode::OK, "{}", scheduled.1);
        let first = poll(&app_a, "worker", "activity").await;
        let mut last = first.clone();
        let failure = json!({"type":"RuntimeException","message":"terminal λ","non_retryable":reported_non_retryable});
        if count == 2 {
            let path = format!(
                "/api/worker/activity-tasks/{}/fail",
                first["task_id"].as_str().unwrap()
            );
            let receipt = request(&app_a, "POST", &path, json!({"lease_owner":"worker","activity_attempt_id":first["activity_attempt_id"],"failure":failure})).await;
            assert_eq!(receipt.0, StatusCode::OK, "{}", receipt.1);
            assert!(poll(&app_b, "worker", "workflow").await.is_null());
            last = poll(&app_b, "worker", "activity").await;
            assert_eq!(last["task_id"], receipt.1["next_task_id"]);
            assert_eq!(
                last["activity_execution_id"],
                first["activity_execution_id"]
            );
            assert_ne!(last["activity_attempt_id"], first["activity_attempt_id"]);
        }
        assert_eq!(last["attempt_number"], count);
        assert_eq!(last["arguments"], arguments);
        let path = format!(
            "/api/worker/activity-tasks/{}/fail",
            last["task_id"].as_str().unwrap()
        );
        let body = json!({"lease_owner":"worker","activity_attempt_id":last["activity_attempt_id"],"failure":failure});
        let mut wrong = body.clone();
        wrong["activity_attempt_id"] = json!("wrong-attempt");
        assert_eq!(
            request(&app_b, "POST", &path, wrong).await.0,
            StatusCode::CONFLICT
        );
        let (one, two) = tokio::join!(
            request(&app_a, "POST", &path, body.clone()),
            request(&app_b, "POST", &path, body.clone())
        );
        assert_eq!(one.0, StatusCode::OK, "{}", one.1);
        assert_eq!(two.0, StatusCode::OK, "{}", two.1);
        assert_ne!(one.1["recorded"], two.1["recorded"]);
        let receipt = if one.1["recorded"] == true {
            one.1
        } else {
            two.1
        };
        let original = run_history(&app_b, &started["workflow_id"], &started["run_id"]).await;
        let terminal = &original.as_array().unwrap().last().unwrap()["payload"];
        assert_eq!(
            original.as_array().unwrap().last().unwrap()["event_type"],
            "ActivityFailed"
        );
        assert_eq!(
            original
                .as_array()
                .unwrap()
                .iter()
                .filter(|e| e["event_type"] == "ActivityFailed")
                .count(),
            1
        );
        assert_eq!(
            terminal["activity_execution_id"],
            first["activity_execution_id"]
        );
        assert_eq!(terminal["activity_attempt_id"], last["activity_attempt_id"]);
        assert_eq!(terminal["attempt_number"], count);
        assert_eq!(terminal["activity"]["status"], "failed");
        assert_eq!(terminal["non_retryable"], non_retryable);
        assert_eq!(terminal["failure_category"], "activity");
        assert_eq!(terminal["message"], "terminal λ");
        assert_eq!(terminal["exception_type"], "RuntimeException");
        assert_eq!(
            terminal["exception"],
            json!({"type":"RuntimeException","message":"terminal λ","code":0,"non_retryable":reported_non_retryable})
        );
        assert!(
            terminal["failure_id"]
                .as_str()
                .is_some_and(|s| !s.is_empty())
        );
        assert_eq!(
            database
                .failure_count(
                    first["activity_execution_id"].as_str().unwrap(),
                    terminal["failure_id"].as_str().unwrap(),
                    non_retryable
                )
                .await,
            1,
            "original failure row and public history must agree"
        );
        assert!(poll(&app_b, "worker", "activity").await.is_null());
        let mut conflicting = body.clone();
        conflicting["failure"]["message"] = json!("different");
        assert_eq!(
            request(&app_b, "POST", &path, conflicting).await.0,
            StatusCode::CONFLICT
        );
        for claim in [&first, &last] {
            let stale = format!(
                "/api/worker/activity-tasks/{}/complete",
                claim["task_id"].as_str().unwrap()
            );
            assert_eq!(request(&app_b, "POST", &stale, json!({"lease_owner":"worker","activity_attempt_id":claim["activity_attempt_id"],"result":envelope(Payload::Null)})).await.0, StatusCode::CONFLICT);
        }
        node_a.close().await;
        let recovered = database.open().await.unwrap();
        let app_c = router(recovered.clone());
        assert_eq!(
            request(&app_c, "POST", &path, body.clone()).await.1["recorded"],
            false
        );
        assert_eq!(
            run_history(&app_c, &started["workflow_id"], &started["run_id"]).await,
            original
        );
        let (left, right) = tokio::join!(
            poll(&app_b, "worker", "workflow"),
            poll(&app_c, "worker", "workflow")
        );
        assert_ne!(
            left.is_null(),
            right.is_null(),
            "one terminal parent resumption across nodes"
        );
        let resumed = if left.is_null() { right } else { left };
        assert_eq!(resumed["task_id"], receipt["next_task_id"]);
        assert_eq!(resumed["run_id"], started["run_id"]);
        let worker_events = resumed["history_events"].as_array().unwrap();
        assert_eq!(
            worker_events.last().unwrap()["workflow_task_id"],
            last["task_id"]
        );
        let event_ids: std::collections::HashSet<_> = worker_events
            .iter()
            .map(|event| event["id"].as_str().filter(|id| !id.is_empty()).unwrap())
            .collect();
        assert_eq!(event_ids.len(), worker_events.len());
        // Worker history retains event IDs/attribution and recorded_at;
        // the control API explicitly returns these four common fields.
        let control_events: Vec<_> = worker_events
            .iter()
            .map(|event| {
                json!({
                    "sequence":event["sequence"],"event_type":event["event_type"],
                    "timestamp":event["recorded_at"],"payload":event["payload"]
                })
            })
            .collect();
        assert_eq!(json!(control_events), original);
        let result = envelope(Payload::Long(9007199254740993));
        assert_eq!(
            finish_task(
                &app_c,
                &resumed,
                json!([{"type":"complete_workflow","result":result}])
            )
            .await
            .0,
            StatusCode::OK
        );
        assert_eq!(
            finish_task(
                &app_b,
                &resumed,
                json!([{"type":"complete_workflow","result":result}])
            )
            .await
            .1["recorded"],
            false
        );
        let completed = run_history(&app_c, &started["workflow_id"], &started["run_id"]).await;
        assert_eq!(
            completed.as_array().unwrap().last().unwrap()["event_type"],
            "WorkflowCompleted"
        );
        assert_eq!(
            request(&app_b, "POST", &path, body).await.1["recorded"],
            false
        );
        assert_eq!(
            run_history(&app_b, &started["workflow_id"], &started["run_id"]).await,
            completed
        );
        assert!(poll(&app_b, "worker", "workflow").await.is_null());
        assert!(poll(&app_c, "worker", "activity").await.is_null());
        node_b.close().await;
        recovered.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn activity_commit_wakes_replay_and_stale_activity_attempt_is_refused() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    start(&app, "activity").await;
    register(&app, "worker", json!(["echo"]), json!(["activity-echo"])).await;
    let task = poll(&app, "worker", "workflow").await;
    let path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        task["task_id"].as_str().unwrap()
    );
    let schedule = json!({"type":"schedule_activity","activity_type":"activity-echo","arguments":envelope(Payload::Array(vec![]))});
    // A later unsupported effect must roll back the whole batch.
    assert_eq!(
        request(
            &app,
            "POST",
            &path,
            completion(
                &task,
                json!([schedule.clone(),{"type":"unimplemented_effect"}])
            )
        )
        .await
        .0,
        StatusCode::UNPROCESSABLE_ENTITY
    );
    assert!(poll(&app, "worker", "activity").await.is_null());
    for delay in [
        json!(-1),
        json!(1.5),
        json!("1"),
        Value::Null,
        json!(i64::MAX),
    ] {
        let outcome = request(
            &app,
            "POST",
            &path,
            completion(
                &task,
                json!([schedule.clone(),{"type":"start_timer","delay_seconds":delay}]),
            ),
        )
        .await;
        assert_eq!(outcome.0, StatusCode::UNPROCESSABLE_ENTITY, "{}", outcome.1);
        assert_eq!(outcome.1["reason"], "invalid_timer_delay");
        assert!(poll(&app, "worker", "activity").await.is_null());
    }
    assert_eq!(
        request(&app, "POST", &path, completion(&task, json!([schedule])))
            .await
            .0,
        StatusCode::OK
    );
    let activity = poll(&app, "worker", "activity").await;
    let path = format!(
        "/api/worker/activity-tasks/{}/complete",
        activity["task_id"].as_str().unwrap()
    );
    let mut body = json!({"lease_owner":"worker","activity_attempt_id":"wrong-attempt","result":envelope(Payload::Long(7))});
    assert_eq!(
        request(&app, "POST", &path, body.clone()).await.0,
        StatusCode::CONFLICT
    );
    body["activity_attempt_id"] = activity["activity_attempt_id"].clone();
    assert_eq!(
        request(&app, "POST", &path, body.clone()).await.0,
        StatusCode::OK
    );
    assert_eq!(
        request(&app, "POST", &path, body).await.1["recorded"],
        false
    );
    let resumed = poll(&app, "worker", "workflow").await;
    let types: Vec<_> = resumed["history_events"]
        .as_array()
        .unwrap()
        .iter()
        .map(|e| e["event_type"].as_str().unwrap())
        .collect();
    assert_eq!(
        types,
        [
            "StartAccepted",
            "WorkflowStarted",
            "ActivityScheduled",
            "ActivityStarted",
            "ActivityCompleted"
        ]
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn repeated_poll_retains_the_original_claim_and_worker_history_paginates() {
    let database = TestDatabase::new().await;
    let node_a = database.open().await.unwrap();
    let node_b = database.open().await.unwrap();
    let app_a = router(node_a.clone());
    let app_b = router(node_b.clone());
    start(&app_a, "first").await;
    start(&app_a, "second").await;
    register(&app_a, "worker", json!(["echo"]), json!([])).await;
    let body = json!({"worker_id":"worker","task_queue":"test","timeout_seconds":0,"history_page_size":1,"poll_request_id":"same-request"});
    let a = request(
        &app_a,
        "POST",
        "/api/worker/workflow-tasks/poll",
        body.clone(),
    )
    .await;
    let b = request(&app_b, "POST", "/api/worker/workflow-tasks/poll", body).await;
    assert_eq!(a.0, StatusCode::OK);
    assert_eq!(
        a.1, b.1,
        "retry on another process obtains the original task/snapshot/fence"
    );
    let task = &a.1["task"];
    assert_eq!(task["history_events"].as_array().unwrap().len(), 1);
    assert_eq!(task["total_history_events"], 2);
    let page = request(&app_b,"POST",&format!("/api/worker/workflow-tasks/{}/history",task["task_id"].as_str().unwrap()),json!({
        "lease_owner":"worker","workflow_task_attempt":1,"next_history_page_token":task["next_history_page_token"]})).await;
    assert_eq!(page.0, StatusCode::OK);
    assert_eq!(page.1["history_events"][0]["event_type"], "WorkflowStarted");
    assert!(page.1["next_history_page_token"].is_null());
    let next = poll(&app_b, "worker", "workflow").await;
    assert_ne!(next["task_id"], task["task_id"]);
    node_a.close().await;
    node_b.close().await;
    database.remove().await;
}

#[tokio::test]
async fn existing_php_database_is_refused_without_changes() {
    let dir = tempfile::tempdir().unwrap();
    let database = dir.path().join("php.sqlite");
    let pool = sqlx::SqlitePool::connect(&format!("sqlite://{}?mode=rwc", database.display()))
        .await
        .unwrap();
    sqlx::query("CREATE TABLE migrations (migration TEXT)")
        .execute(&pool)
        .await
        .unwrap();
    sqlx::query("INSERT INTO migrations VALUES ('php-preserved')")
        .execute(&pool)
        .await
        .unwrap();
    pool.close().await;
    let before = std::fs::read(&database).unwrap();
    assert!(
        Runtime::open(database.to_str().unwrap(), "test-token".into())
            .await
            .is_err()
    );
    assert_eq!(std::fs::read(&database).unwrap(), before);
    assert!(!database.with_extension("sqlite-wal").exists());
}

#[tokio::test]
async fn readiness_and_startup_refuse_a_marker_with_incomplete_tables() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    assert_eq!(
        request(&app, "GET", "/api/ready", Value::Null).await.0,
        StatusCode::OK
    );
    database.execute("DROP TABLE dw_poll_receipts").await;
    assert_eq!(
        request(&app, "GET", "/api/ready", Value::Null).await.0,
        StatusCode::SERVICE_UNAVAILABLE
    );
    runtime.close().await;
    assert!(database.open().await.is_err());
    database.remove().await;
}

#[tokio::test]
async fn authentication_precedes_protocol_and_namespace_checks() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let unauthenticated = app
        .clone()
        .oneshot(
            Request::builder()
                .uri("/api/workflows/missing")
                .header("x-namespace", "other")
                .body(Body::empty())
                .unwrap(),
        )
        .await
        .unwrap();
    assert_eq!(unauthenticated.status(), StatusCode::UNAUTHORIZED);
    let namespace = app
        .clone()
        .oneshot(
            Request::builder()
                .uri("/api/workflows/missing")
                .header("authorization", "Bearer test-token")
                .header("x-durable-workflow-control-plane-version", "2")
                .header("x-namespace", "other")
                .body(Body::empty())
                .unwrap(),
        )
        .await
        .unwrap();
    assert_eq!(namespace.status(), StatusCode::NOT_FOUND);
    let unsupported = request(&app,"POST","/api/workflows",json!({"workflow_id":"unsupported","workflow_type":"echo",
        "task_queue":"test","input":envelope(Payload::Null),"memo":{"ignored":"must not be accepted"}})).await;
    assert_eq!(unsupported.0, StatusCode::UNPROCESSABLE_ENTITY);
    assert_eq!(
        request(&app, "GET", "/api/workflows/unsupported", Value::Null)
            .await
            .0,
        StatusCode::NOT_FOUND
    );
    runtime.close().await;
    database.remove().await;
}
