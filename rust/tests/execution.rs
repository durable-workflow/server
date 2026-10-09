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

use sqlx::{ConnectOptions, Postgres, migrate::MigrateDatabase, postgres::PgConnectOptions};
use std::str::FromStr;

enum TestDatabase {
    Sqlite(tempfile::TempDir),
    Postgres {
        options: PgConnectOptions,
        url: String,
    },
}

impl TestDatabase {
    async fn new() -> Self {
        match std::env::var("DW_EXECUTION_POSTGRES_URL") {
            Ok(url) => {
                let name = format!(
                    "dw_execution_{}",
                    ulid::Ulid::new().to_string().to_lowercase()
                );
                let options = PgConnectOptions::from_str(&url).unwrap().database(&name);
                let url = options.to_url_lossy().to_string();
                Postgres::create_database(&url).await.unwrap();
                Self::Postgres { options, url }
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
                Runtime::open_postgres(options.clone(), "test-token".into()).await
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
                    .connect_with(options.clone())
                    .await
                    .unwrap();
                sqlx::query(statement).execute(&pool).await.unwrap();
                pool.close().await;
            }
        }
    }

    async fn remove(self) {
        if let Self::Postgres { url, .. } = self {
            Postgres::drop_database(&url).await.unwrap();
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
                    .connect_with(options.clone())
                    .await
                    .unwrap();
                sqlx::query("UPDATE workflow_history_events SET payload=$1 WHERE sequence=1")
                    .bind(sqlx::types::Json(payload))
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
    database
        .execute("UPDATE workflow_runs SET started_at='2026-01-02T03:04:05.123456Z'")
        .await;
    let description = request(&app, "GET", "/api/workflows/precision", Value::Null).await;
    assert_eq!(description.0, StatusCode::OK, "{}", description.1);
    let time = chrono::DateTime::parse_from_rfc3339(description.1["started_at"].as_str().unwrap())
        .unwrap();
    assert_eq!(time.timestamp_subsec_nanos(), 123456000);
    register(&app, "worker", json!(["echo"]), json!([])).await;
    let task = poll(&app, "worker", "workflow").await;
    database
        .execute("UPDATE workflow_tasks SET lease_expires_at='2000-01-01T00:00:00.000001Z'")
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
                json!([schedule.clone(),{"type":"start_timer","delay_seconds":1}])
            )
        )
        .await
        .0,
        StatusCode::UNPROCESSABLE_ENTITY
    );
    assert!(poll(&app, "worker", "activity").await.is_null());
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
