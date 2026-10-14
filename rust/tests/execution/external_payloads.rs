use super::*;
use axum::{body::Bytes, http::HeaderMap};
use durable_workflow_server::runtime::ExternalPayloadConfig;
use sha2::{Digest, Sha256};
use std::{
    sync::{
        Arc,
        atomic::{AtomicUsize, Ordering},
    },
    time::Duration,
};

fn digest(bytes: &[u8]) -> String {
    Sha256::digest(bytes)
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect()
}

async fn request(
    router: &Router,
    method: &str,
    path: &str,
    token: &str,
    namespace: &str,
    metadata: Option<&Value>,
    body: Body,
) -> (StatusCode, HeaderMap, Vec<u8>) {
    let mut request = Request::builder()
        .method(method)
        .uri(path)
        .header("authorization", format!("Bearer {token}"))
        .header("x-namespace", namespace)
        .header("content-type", "application/octet-stream");
    if let Some(metadata) = metadata {
        request = request
            .header("x-durable-workflow-payload-codec", "avro")
            .header(
                "x-durable-workflow-payload-size",
                metadata["size_bytes"].as_i64().unwrap().to_string(),
            )
            .header(
                "x-durable-workflow-payload-sha256",
                metadata["sha256"].as_str().unwrap(),
            );
    }
    let response = router
        .clone()
        .oneshot(request.body(body).unwrap())
        .await
        .unwrap();
    let status = response.status();
    let headers = response.headers().clone();
    let body = to_bytes(response.into_body(), 80 * 1024 * 1024)
        .await
        .unwrap()
        .to_vec();
    (status, headers, body)
}

async fn policy(
    router: &Router,
    namespace: &str,
    root: &std::path::Path,
    enabled: bool,
) -> StatusCode {
    let response = router
        .clone()
        .oneshot(
            Request::builder()
                .method("PUT")
                .uri(format!("/api/namespaces/{namespace}/external-storage"))
                .header("authorization", "Bearer admin-token")
                .header("x-durable-workflow-control-plane-version", "2")
                .header("content-type", "application/json")
                .body(Body::from(
                    json!({"driver":"local","enabled":enabled,"threshold_bytes":16,
            "config":{"uri":format!("file://{}",root.display())}})
                    .to_string(),
                ))
                .unwrap(),
        )
        .await
        .unwrap();
    response.status()
}

fn metadata(bytes: &[u8]) -> Value {
    json!({"size_bytes":bytes.len() as i64,"sha256":digest(bytes)})
}

async fn json_request(
    router: &Router,
    method: &str,
    path: &str,
    token: &str,
    value: Value,
) -> (StatusCode, Value) {
    let version = if path.starts_with("/api/worker/") {
        ("x-durable-workflow-protocol-version", "1.20")
    } else {
        ("x-durable-workflow-control-plane-version", "2")
    };
    let response = router
        .clone()
        .oneshot(
            Request::builder()
                .method(method)
                .uri(path)
                .header("authorization", format!("Bearer {token}"))
                .header(version.0, version.1)
                .header("content-type", "application/json")
                .body(Body::from(value.to_string()))
                .unwrap(),
        )
        .await
        .unwrap();
    let status = response.status();
    let bytes = to_bytes(response.into_body(), 4 * 1024 * 1024)
        .await
        .unwrap();
    (status, serde_json::from_slice(&bytes).unwrap())
}

async fn unclaimed(database: &TestDatabase) -> i64 {
    let query = "SELECT COUNT(*) FROM runtime_external_payloads WHERE retained_at IS NULL AND expires_at IS NOT NULL";
    match database {
        TestDatabase::Sqlite(dir) => {
            let pool = sqlx::SqlitePool::connect(&format!(
                "sqlite://{}",
                dir.path().join("runtime.sqlite").display()
            ))
            .await
            .unwrap();
            let count = sqlx::query_scalar(query).fetch_one(&pool).await.unwrap();
            pool.close().await;
            count
        }
        TestDatabase::Postgres { options, .. } => {
            let pool = sqlx::postgres::PgPoolOptions::new()
                .connect_with(options.as_ref().clone())
                .await
                .unwrap();
            let count = sqlx::query_scalar(query).fetch_one(&pool).await.unwrap();
            pool.close().await;
            count
        }
        TestDatabase::MySql { options, .. } => {
            let pool = sqlx::mysql::MySqlPoolOptions::new()
                .connect_with(options.as_ref().clone())
                .await
                .unwrap();
            let count = sqlx::query_scalar(query).fetch_one(&pool).await.unwrap();
            pool.close().await;
            count
        }
    }
}

#[tokio::test]
async fn external_workflow_input_retains_one_reference_with_start_and_survives_cold_worker_completion()
 {
    let temp = tempfile::tempdir().unwrap();
    let root = temp.path().join("objects");
    let mut config = ExternalPayloadConfig::default();
    config.max_bytes = 8 * 1024 * 1024;
    let (database, runtime, app) = setup(config, &root).await;
    let blob = durable_workflow_server::codec::ValueCodec::new()
        .unwrap()
        .encode(&Payload::Array(vec![
            Payload::String("x".repeat(3 * 1024 * 1024)),
            Payload::Long(9007199254740993),
            Payload::Null,
        ]))
        .unwrap();
    assert!(blob.len() > 3 * 1024 * 1024);
    let (status, _, bytes) = request(
        &app,
        "POST",
        "/api/external-payloads/v1",
        "worker-token",
        "default",
        Some(&metadata(blob.as_bytes())),
        Body::from(blob.clone()),
    )
    .await;
    assert_eq!(status, StatusCode::CREATED);
    let uploaded: Value = serde_json::from_slice(&bytes).unwrap();
    let reference = &uploaded["reference"];
    assert_eq!(unclaimed(&database).await, 1);
    let mut body = json!({"workflow_id":"invalid id", "workflow_type":"echo", "task_queue":"external-input",
        "input":{"codec":"avro","external_payload":reference}});
    assert_eq!(
        json_request(
            &app,
            "POST",
            "/api/workflows",
            "operator-token",
            body.clone()
        )
        .await
        .0,
        StatusCode::UNPROCESSABLE_ENTITY
    );
    assert_eq!(
        unclaimed(&database).await,
        1,
        "failed state admission rolls retention back"
    );
    body["workflow_id"] = json!("external-input-original");
    let (status, original) =
        json_request(&app, "POST", "/api/workflows", "operator-token", body).await;
    assert_eq!(status, StatusCode::CREATED, "{original}");
    assert_eq!(unclaimed(&database).await, 0);
    runtime.close().await;
    let cold = database.open().await.unwrap().with_role_tokens(
        Some("worker-token".into()),
        Some("operator-token".into()),
        Some("admin-token".into()),
    );
    let app = router(cold.clone());
    assert_eq!(
        json_request(
            &app,
            "POST",
            "/api/worker/register",
            "worker-token",
            json!({
        "worker_id":"external-input-reader", "task_queue":"external-input", "runtime":"php",
        "supported_workflow_types":["echo"], "supported_activity_types":[]})
        )
        .await
        .0,
        StatusCode::CREATED
    );
    let (status, delivery) = json_request(&app, "POST", "/api/worker/workflow-tasks/poll", "worker-token",
        json!({"worker_id":"external-input-reader","task_queue":"external-input","timeout_seconds":0})).await;
    assert_eq!(status, StatusCode::OK);
    let task = &delivery["task"];
    assert_eq!(task["run_id"], original["run_id"]);
    assert_eq!(
        task["arguments"],
        json!({"codec":"avro","external_payload":reference})
    );
    assert!(
        task["arguments"].to_string().len() < 400,
        "durable delivery does not inline external bytes"
    );
    let path = format!(
        "/api/external-payloads/v1/{}",
        reference["reference_id"].as_str().unwrap()
    );
    assert_eq!(
        request(
            &app,
            "GET",
            &path,
            "worker-token",
            "default",
            Some(reference),
            Body::empty()
        )
        .await
        .2,
        blob.as_bytes()
    );
    let (status, receipt) = json_request(&app, "POST", &format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap()),
        "worker-token", completion(task, json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}]))).await;
    assert_eq!(status, StatusCode::OK, "{receipt}");
    assert_eq!(receipt["recorded"], true);
    let (_, result) = json_request(
        &app,
        "GET",
        "/api/workflows/external-input-original",
        "operator-token",
        Value::Null,
    )
    .await;
    assert_eq!(result["status"], "completed");
    assert_eq!(result["input_envelope"], task["arguments"]);
    assert_eq!(unclaimed(&database).await, 0);
    cold.close().await;
    database.remove().await;
}

#[tokio::test]
async fn malformed_and_expired_external_input_never_claims_or_admits_a_workflow() {
    let temp = tempfile::tempdir().unwrap();
    let (database, runtime, app) = setup(
        ExternalPayloadConfig::default(),
        &temp.path().join("objects"),
    )
    .await;
    let header = base64::Engine::decode(
        &base64::engine::general_purpose::STANDARD,
        "wwHioz3/VYAiNw==",
    )
    .unwrap();
    for (index, suffix) in [vec![12, 1, 2, 4, 0, 0], vec![2, 2], vec![10, 2, 255]]
        .into_iter()
        .enumerate()
    {
        let mut datum = header.clone();
        datum.extend(suffix);
        let blob = base64::Engine::encode(&base64::engine::general_purpose::STANDARD, datum);
        let (status, _, upload) = request(
            &app,
            "POST",
            "/api/external-payloads/v1",
            "worker-token",
            "default",
            Some(&metadata(blob.as_bytes())),
            Body::from(blob),
        )
        .await;
        assert_eq!(status, StatusCode::CREATED);
        let upload: Value = serde_json::from_slice(&upload).unwrap();
        let (status, refusal) = json_request(&app, "POST", "/api/workflows", "operator-token", json!({
            "workflow_id":format!("invalid-external-{index}"),"workflow_type":"echo","task_queue":"test",
            "input":{"codec":"avro","external_payload":upload["reference"]}})).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{refusal}");
        assert_eq!(refusal["reason"], "invalid_external_payload_datum");
        assert_eq!(unclaimed(&database).await, (index + 1) as i64);
        assert_eq!(
            json_request(
                &app,
                "GET",
                &format!("/api/workflows/invalid-external-{index}"),
                "operator-token",
                Value::Null
            )
            .await
            .0,
            StatusCode::NOT_FOUND
        );
    }
    let blob = durable_workflow_server::codec::ValueCodec::new()
        .unwrap()
        .encode(&Payload::Array(vec![]))
        .unwrap();
    let (_, _, upload) = request(
        &app,
        "POST",
        "/api/external-payloads/v1",
        "worker-token",
        "default",
        Some(&metadata(blob.as_bytes())),
        Body::from(blob),
    )
    .await;
    let upload: Value = serde_json::from_slice(&upload).unwrap();
    database
        .execute("UPDATE runtime_external_payloads SET expires_at='2000-01-01 00:00:00'")
        .await;
    let (status, refusal) = json_request(
        &app,
        "POST",
        "/api/workflows",
        "operator-token",
        json!({
        "workflow_id":"expired-external","workflow_type":"echo","task_queue":"test",
        "input":{"codec":"avro","external_payload":upload["reference"]}}),
    )
    .await;
    assert_eq!(status, StatusCode::GONE, "{refusal}");
    assert_eq!(refusal["reason"], "external_payload_expired");
    assert_eq!(unclaimed(&database).await, 4);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn external_workflow_completion_fences_before_retention_and_keeps_opaque_result_after_cold_open()
 {
    let temp = tempfile::tempdir().unwrap();
    let mut config = ExternalPayloadConfig::default();
    config.max_bytes = 8 * 1024 * 1024;
    let (database, runtime, app) = setup(config, &temp.path().join("objects")).await;
    assert_eq!(
        json_request(
            &app,
            "POST",
            "/api/worker/register",
            "worker-token",
            json!({
        "worker_id":"external-result-author","task_queue":"test","runtime":"php",
        "supported_workflow_types":["echo"],"supported_activity_types":[]})
        )
        .await
        .0,
        StatusCode::CREATED
    );
    let (status, original) = json_request(
        &app,
        "POST",
        "/api/workflows",
        "operator-token",
        json!({
        "workflow_id":"external-result-original","workflow_type":"echo","task_queue":"test",
        "input":envelope(Payload::Array(vec![]))}),
    )
    .await;
    assert_eq!(status, StatusCode::CREATED, "{original}");
    let (_, delivery) = json_request(
        &app,
        "POST",
        "/api/worker/workflow-tasks/poll",
        "worker-token",
        json!({
        "worker_id":"external-result-author","task_queue":"test","timeout_seconds":0}),
    )
    .await;
    let task = &delivery["task"];
    let result = Payload::Array(vec![
        Payload::String("x".repeat(3 * 1024 * 1024)),
        Payload::Long(9007199254740993),
        Payload::Null,
        Payload::String("rangeλ".into()),
    ]);
    let blob = durable_workflow_server::codec::ValueCodec::new()
        .unwrap()
        .encode(&result)
        .unwrap();
    let (status, _, upload) = request(
        &app,
        "POST",
        "/api/external-payloads/v1",
        "worker-token",
        "default",
        Some(&metadata(blob.as_bytes())),
        Body::from(blob.clone()),
    )
    .await;
    assert_eq!(status, StatusCode::CREATED);
    let upload: Value = serde_json::from_slice(&upload).unwrap();
    let output = json!({"codec":"avro","external_payload":upload["reference"]});
    let path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        task["task_id"].as_str().unwrap()
    );
    let body = completion(task, json!([{"type":"complete_workflow","result":output}]));
    let history_path = format!(
        "/api/workflows/external-result-original/runs/{}/history",
        original["run_id"].as_str().unwrap()
    );
    let before = json_request(&app, "GET", &history_path, "operator-token", Value::Null)
        .await
        .1;
    let mut stale = body.clone();
    stale["workflow_task_attempt"] = json!(99);
    assert_eq!(
        json_request(&app, "POST", &path, "worker-token", stale)
            .await
            .0,
        StatusCode::CONFLICT
    );
    assert_eq!(unclaimed(&database).await, 1);
    assert_eq!(
        json_request(&app, "GET", &history_path, "operator-token", Value::Null)
            .await
            .1,
        before
    );
    let mut unsupported = body.clone();
    unsupported["commands"][0]["unknown"] = json!(true);
    assert_eq!(
        json_request(&app, "POST", &path, "worker-token", unsupported)
            .await
            .0,
        StatusCode::UNPROCESSABLE_ENTITY
    );
    assert_eq!(unclaimed(&database).await, 1);
    let (status, receipt) = json_request(&app, "POST", &path, "worker-token", body.clone()).await;
    assert_eq!(status, StatusCode::OK, "{receipt}");
    assert_eq!(receipt["recorded"], true);
    assert_eq!(unclaimed(&database).await, 0);
    let prefix = json_request(&app, "GET", &history_path, "operator-token", Value::Null)
        .await
        .1;
    runtime.close().await;
    let cold = database.open().await.unwrap().with_role_tokens(
        Some("worker-token".into()),
        Some("operator-token".into()),
        Some("admin-token".into()),
    );
    let app = router(cold.clone());
    let (status, run) = json_request(
        &app,
        "GET",
        "/api/workflows/external-result-original",
        "operator-token",
        Value::Null,
    )
    .await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(run["status"], "completed");
    assert_eq!(run["output_envelope"], output);
    assert_eq!(
        json_request(&app, "GET", &history_path, "operator-token", Value::Null)
            .await
            .1,
        prefix
    );
    let reference = &upload["reference"];
    assert_eq!(
        request(
            &app,
            "GET",
            &format!(
                "/api/external-payloads/v1/{}",
                reference["reference_id"].as_str().unwrap()
            ),
            "worker-token",
            "default",
            Some(reference),
            Body::empty()
        )
        .await
        .2,
        blob.as_bytes()
    );
    // This observes only the existing native exact-body receipt, not a stronger
    // shared PHP receipt contract or content reconciliation after provider loss.
    let (status, duplicate) = json_request(&app, "POST", &path, "worker-token", body).await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(duplicate["recorded"], false);
    assert_eq!(
        json_request(&app, "GET", &history_path, "operator-token", Value::Null)
            .await
            .1,
        prefix
    );
    cold.close().await;
    database.remove().await;
}

async fn setup(
    config: ExternalPayloadConfig,
    root: &std::path::Path,
) -> (TestDatabase, Runtime, Router) {
    let database = TestDatabase::new().await;
    let runtime = database
        .open()
        .await
        .unwrap()
        .with_role_tokens(
            Some("worker-token".into()),
            Some("operator-token".into()),
            Some("admin-token".into()),
        )
        .with_external_payload_config(config);
    let router = router(runtime.clone());
    assert_eq!(policy(&router, "default", root, true).await, StatusCode::OK);
    (database, runtime, router)
}

#[tokio::test]
async fn authenticated_versioned_transport_keeps_exact_bytes_retry_identity_and_metadata() {
    let temp = tempfile::tempdir().unwrap();
    let root = temp.path().join("space λ");
    let mut config = ExternalPayloadConfig::default();
    config.max_bytes = 4 * 1024 * 1024;
    let (database, runtime, router) = setup(config, &root).await;
    // The versioned path accepts every authenticated role without either plane
    // version header, and its byte limit is independent of ordinary JSON bodies.
    let bytes = vec![b'x'; 3 * 1024 * 1024 + 1];
    let meta = metadata(&bytes);
    let (status, headers, body) = request(
        &router,
        "POST",
        "/api/external-payloads/v1",
        "worker-token",
        "default",
        Some(&meta),
        Body::from(bytes.clone()),
    )
    .await;
    assert_eq!(
        status,
        StatusCode::CREATED,
        "{}",
        String::from_utf8_lossy(&body)
    );
    assert_eq!(headers["cache-control"], "no-store, private");
    let upload: Value = serde_json::from_slice(&body).unwrap();
    assert_eq!(upload["transport_version"], 1);
    let reference = &upload["reference"];
    assert_eq!(reference.as_object().unwrap().len(), 5);
    for role in ["operator-token", "admin-token"] {
        let (status, _, body) = request(
            &router,
            "POST",
            "/api/external-payloads/v1",
            role,
            "default",
            Some(&meta),
            Body::from(bytes.clone()),
        )
        .await;
        assert_eq!(status, StatusCode::CREATED);
        assert_eq!(
            serde_json::from_slice::<Value>(&body).unwrap()["reference"],
            *reference
        );
    }
    let path = format!(
        "/api/external-payloads/v1/{}",
        reference["reference_id"].as_str().unwrap()
    );
    let (status, headers, observed) = request(
        &router,
        "GET",
        &path,
        "worker-token",
        "default",
        Some(reference),
        Body::empty(),
    )
    .await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(observed, bytes);
    assert_eq!(headers["x-durable-workflow-payload-sha256"], digest(&bytes));
    assert_eq!(headers["content-length"], bytes.len().to_string());
    assert_eq!(headers["cache-control"], "private, max-age=60, immutable");
    let provider = root
        .join("avro")
        .join(&digest(&bytes)[..2])
        .join(digest(&bytes));
    std::fs::write(&provider, b"partial").unwrap();
    let (status, _, body) = request(
        &router,
        "GET",
        &path,
        "operator-token",
        "default",
        Some(reference),
        Body::empty(),
    )
    .await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY);
    assert_eq!(
        serde_json::from_slice::<Value>(&body).unwrap()["reason"],
        "external_payload_integrity_mismatch"
    );
    let (status, _, body) = request(
        &router,
        "POST",
        "/api/external-payloads/v1",
        "worker-token",
        "default",
        Some(&meta),
        Body::from(bytes.clone()),
    )
    .await;
    assert_eq!(status, StatusCode::CREATED);
    assert_eq!(
        serde_json::from_slice::<Value>(&body).unwrap()["reference"],
        *reference
    );
    std::fs::remove_file(provider).unwrap();
    let (status, _, body) = request(
        &router,
        "GET",
        &path,
        "admin-token",
        "default",
        Some(reference),
        Body::empty(),
    )
    .await;
    assert_eq!(status, StatusCode::NOT_FOUND);
    assert_eq!(
        serde_json::from_slice::<Value>(&body).unwrap()["reason"],
        "external_payload_not_found"
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn namespace_binding_and_atomic_quotas_preserve_retry_and_neighbor_identity() {
    let temp = tempfile::tempdir().unwrap();
    let mut config = ExternalPayloadConfig::default();
    config.max_bytes_per_namespace = json!(6);
    config.max_objects_per_namespace = json!(3);
    config.namespace_overrides = json!({"other":{"max_bytes":3,"max_objects":1}});
    let (database, runtime, router) = setup(config, temp.path()).await;
    let response = router
        .clone()
        .oneshot(
            Request::builder()
                .method("POST")
                .uri("/api/namespaces")
                .header("authorization", "Bearer admin-token")
                .header("x-durable-workflow-control-plane-version", "2")
                .header("content-type", "application/json")
                .body(Body::from(json!({"name":"other"}).to_string()))
                .unwrap(),
        )
        .await
        .unwrap();
    assert_eq!(response.status(), StatusCode::CREATED);
    assert_eq!(
        policy(&router, "other", temp.path(), true).await,
        StatusCode::OK
    );
    let meta = metadata(b"abc");
    let (status, _, body) = request(
        &router,
        "POST",
        "/api/external-payloads/v1",
        "worker-token",
        "default",
        Some(&meta),
        Body::from("abc"),
    )
    .await;
    assert_eq!(status, StatusCode::CREATED);
    let first: Value = serde_json::from_slice::<Value>(&body).unwrap()["reference"].clone();
    let (status, _, body) = request(
        &router,
        "POST",
        "/api/external-payloads/v1",
        "worker-token",
        "other",
        Some(&meta),
        Body::from("abc"),
    )
    .await;
    assert_eq!(status, StatusCode::CREATED);
    let other: Value = serde_json::from_slice::<Value>(&body).unwrap()["reference"].clone();
    assert_ne!(first["reference_id"], other["reference_id"]);
    let first_path = format!(
        "/api/external-payloads/v1/{}",
        first["reference_id"].as_str().unwrap()
    );
    assert_eq!(
        request(
            &router,
            "GET",
            &first_path,
            "worker-token",
            "other",
            Some(&first),
            Body::empty()
        )
        .await
        .0,
        StatusCode::NOT_FOUND
    );
    let second = metadata(b"def");
    assert_eq!(
        request(
            &router,
            "POST",
            "/api/external-payloads/v1",
            "worker-token",
            "default",
            Some(&second),
            Body::from("def")
        )
        .await
        .0,
        StatusCode::CREATED
    );
    let rejected = metadata(b"g");
    for (namespace, reason) in [
        ("default", "external_payload_namespace_bytes_exhausted"),
        ("other", "external_payload_namespace_objects_exhausted"),
    ] {
        let (status, headers, body) = request(
            &router,
            "POST",
            "/api/external-payloads/v1",
            "worker-token",
            namespace,
            Some(&rejected),
            Body::from("g"),
        )
        .await;
        assert_eq!(status, StatusCode::TOO_MANY_REQUESTS);
        assert_eq!(headers["retry-after"], "60");
        assert_eq!(
            serde_json::from_slice::<Value>(&body).unwrap()["reason"],
            reason
        );
        let (status, _, body) = request(
            &router,
            "POST",
            "/api/external-payloads/v1",
            "worker-token",
            namespace,
            Some(&meta),
            Body::from("abc"),
        )
        .await;
        assert_eq!(status, StatusCode::CREATED);
        assert_eq!(
            serde_json::from_slice::<Value>(&body).unwrap()["reference"],
            if namespace == "other" {
                other.clone()
            } else {
                first.clone()
            }
        );
    }
    assert!(
        !temp
            .path()
            .join("avro")
            .join(&digest(b"g")[..2])
            .join(digest(b"g"))
            .exists()
    );
    runtime.close().await;
    database.remove().await;
}

fn untouched(counter: Arc<AtomicUsize>) -> Body {
    Body::from_stream(futures_util::stream::poll_fn(move |_| {
        counter.fetch_add(1, Ordering::Relaxed);
        std::task::Poll::Ready(None::<Result<Bytes, std::io::Error>>)
    }))
}

#[tokio::test]
async fn authentication_namespace_and_declared_limit_refuse_before_body_or_provider() {
    let temp = tempfile::tempdir().unwrap();
    let root = temp.path().join("objects");
    let mut config = ExternalPayloadConfig::default();
    config.max_bytes = 3;
    let (database, runtime, router) = setup(config, &root).await;
    let reads = Arc::new(AtomicUsize::new(0));
    let (status, _, body) = request(
        &router,
        "POST",
        "/api/external-payloads/v1",
        "wrong",
        "missing",
        None,
        untouched(reads.clone()),
    )
    .await;
    assert_eq!(status, StatusCode::UNAUTHORIZED);
    assert_eq!(
        serde_json::from_slice::<Value>(&body).unwrap()["reason"],
        "external_payload_unauthorized"
    );
    assert_eq!(reads.load(Ordering::Relaxed), 0);
    assert_eq!(
        request(
            &router,
            "POST",
            "/api/external-payloads/v1",
            "worker-token",
            "missing",
            None,
            untouched(reads.clone())
        )
        .await
        .0,
        StatusCode::NOT_FOUND
    );
    let oversized = metadata(b"four");
    assert_eq!(
        request(
            &router,
            "POST",
            "/api/external-payloads/v1",
            "worker-token",
            "default",
            Some(&oversized),
            untouched(reads.clone())
        )
        .await
        .0,
        StatusCode::PAYLOAD_TOO_LARGE
    );
    assert_eq!(reads.load(Ordering::Relaxed), 0);
    assert!(!root.exists());
    let declared = metadata(b"abc");
    assert_eq!(
        request(
            &router,
            "POST",
            "/api/external-payloads/v1",
            "worker-token",
            "default",
            Some(&declared),
            Body::from("abcd")
        )
        .await
        .0,
        StatusCode::PAYLOAD_TOO_LARGE
    );
    for bytes in ["abd", "ab"] {
        let (status, _, body) = request(
            &router,
            "POST",
            "/api/external-payloads/v1",
            "worker-token",
            "default",
            Some(&declared),
            Body::from(bytes),
        )
        .await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY);
        assert_eq!(
            serde_json::from_slice::<Value>(&body).unwrap()["reason"],
            "external_payload_integrity_mismatch"
        );
    }
    let response = router
        .clone()
        .oneshot(
            Request::builder()
                .method("POST")
                .uri("/api/external-payloads/v1")
                .header("authorization", "Bearer worker-token")
                .header("content-type", "application/octet-stream")
                .header("x-durable-workflow-payload-completion", "{}")
                .body(untouched(reads.clone()))
                .unwrap(),
        )
        .await
        .unwrap();
    assert_eq!(response.status(), StatusCode::UNPROCESSABLE_ENTITY);
    let body: Value =
        serde_json::from_slice(&to_bytes(response.into_body(), 4096).await.unwrap()).unwrap();
    assert_eq!(
        body["reason"],
        "development_payload_completion_upload_unqualified"
    );
    assert_eq!(reads.load(Ordering::Relaxed), 0);
    assert!(!root.exists());
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn stalled_upload_keeps_original_timeout_and_releases_capacity_for_next_original() {
    let temp = tempfile::tempdir().unwrap();
    let mut config = ExternalPayloadConfig::default();
    config.timeout = Duration::from_millis(100);
    let (database, runtime, router) = setup(config, temp.path()).await;
    let meta = metadata(b"abc");
    let start = tokio::time::Instant::now();
    let body = Body::from_stream(futures_util::stream::pending::<Result<Bytes, std::io::Error>>());
    let (status, _, _) = request(
        &router,
        "POST",
        "/api/external-payloads/v1",
        "worker-token",
        "default",
        Some(&meta),
        body,
    )
    .await;
    assert_eq!(status, StatusCode::SERVICE_UNAVAILABLE);
    assert!(start.elapsed() < Duration::from_secs(1));
    let (status, _, body) = request(
        &router,
        "POST",
        "/api/external-payloads/v1",
        "worker-token",
        "default",
        Some(&meta),
        Body::from("abc"),
    )
    .await;
    assert_eq!(
        status,
        StatusCode::CREATED,
        "{}",
        String::from_utf8_lossy(&body)
    );
    runtime.close().await;
    database.remove().await;
}
