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
    let dir = tempfile::tempdir().unwrap();
    let database = dir.path().join("runtime.sqlite");
    let runtime = Runtime::open(database.to_str().unwrap(), "test-token".into())
        .await
        .unwrap();
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
    let reopened = Runtime::open(database.to_str().unwrap(), "test-token".into())
        .await
        .unwrap();
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
}

#[tokio::test]
async fn independent_nodes_claim_once_and_revoked_attempt_cannot_commit() {
    let dir = tempfile::tempdir().unwrap();
    let database = dir.path().join("runtime.sqlite");
    let node_a = Runtime::open(database.to_str().unwrap(), "test-token".into())
        .await
        .unwrap();
    let node_b = Runtime::open(database.to_str().unwrap(), "test-token".into())
        .await
        .unwrap();
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
}

#[tokio::test]
async fn activity_commit_wakes_replay_and_stale_activity_attempt_is_refused() {
    let dir = tempfile::tempdir().unwrap();
    let database = dir.path().join("runtime.sqlite");
    let runtime = Runtime::open(database.to_str().unwrap(), "test-token".into())
        .await
        .unwrap();
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
}

#[tokio::test]
async fn repeated_poll_retains_the_original_claim_and_worker_history_paginates() {
    let dir = tempfile::tempdir().unwrap();
    let database = dir.path().join("runtime.sqlite");
    let node_a = Runtime::open(database.to_str().unwrap(), "test-token".into())
        .await
        .unwrap();
    let node_b = Runtime::open(database.to_str().unwrap(), "test-token".into())
        .await
        .unwrap();
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
    let dir = tempfile::tempdir().unwrap();
    let database = dir.path().join("runtime.sqlite");
    let runtime = Runtime::open(database.to_str().unwrap(), "test-token".into())
        .await
        .unwrap();
    let app = router(runtime.clone());
    assert_eq!(
        request(&app, "GET", "/api/ready", Value::Null).await.0,
        StatusCode::OK
    );
    let pool = sqlx::SqlitePool::connect(&format!("sqlite://{}", database.display()))
        .await
        .unwrap();
    sqlx::query("DROP TABLE dw_poll_receipts")
        .execute(&pool)
        .await
        .unwrap();
    pool.close().await;
    assert_eq!(
        request(&app, "GET", "/api/ready", Value::Null).await.0,
        StatusCode::SERVICE_UNAVAILABLE
    );
    runtime.close().await;
    assert!(
        Runtime::open(database.to_str().unwrap(), "test-token".into())
            .await
            .is_err()
    );
}

#[tokio::test]
async fn authentication_precedes_protocol_and_namespace_checks() {
    let dir = tempfile::tempdir().unwrap();
    let runtime = Runtime::open(
        dir.path().join("db.sqlite").to_str().unwrap(),
        "test-token".into(),
    )
    .await
    .unwrap();
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
}
