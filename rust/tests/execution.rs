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
                "1.19"
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
