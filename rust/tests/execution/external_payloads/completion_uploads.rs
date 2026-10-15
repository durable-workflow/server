use super::*;

fn provider_files(path: &std::path::Path) -> usize {
    if !path.exists() {
        return 0;
    }
    std::fs::read_dir(path)
        .unwrap()
        .map(|entry| {
            let path = entry.unwrap().path();
            if path.is_dir() {
                provider_files(&path)
            } else {
                1
            }
        })
        .sum()
}

async fn upload(
    app: &Router,
    token: &str,
    namespace: &str,
    context: &Value,
    bytes: Vec<u8>,
    body: Option<Body>,
) -> (StatusCode, HeaderMap, Value) {
    let request = Request::builder()
        .method("POST")
        .uri("/api/external-payloads/v1")
        .header("authorization", format!("Bearer {token}"))
        .header("x-namespace", namespace)
        .header("content-type", "application/octet-stream")
        .header("x-durable-workflow-payload-codec", "avro")
        .header("x-durable-workflow-payload-size", bytes.len().to_string())
        .header("x-durable-workflow-payload-sha256", digest(&bytes))
        .header("x-durable-workflow-payload-completion", context.to_string())
        .body(body.unwrap_or_else(|| Body::from(bytes)))
        .unwrap();
    let response = app.clone().oneshot(request).await.unwrap();
    let status = response.status();
    let headers = response.headers().clone();
    let bytes = to_bytes(response.into_body(), 1024 * 1024).await.unwrap();
    (status, headers, serde_json::from_slice(&bytes).unwrap())
}

async fn original(
    start_to_close: i64,
) -> (
    tempfile::TempDir,
    TestDatabase,
    Runtime,
    Router,
    Value,
    Vec<u8>,
) {
    let temp = tempfile::tempdir().unwrap();
    let mut config = ExternalPayloadConfig::default();
    config.completion_max_bytes = Some(64);
    config.timeout = Duration::from_secs(5);
    let (database, runtime, app) = setup(config, &temp.path().join("objects")).await;
    let registered = json_request(
        &app,
        "POST",
        "/api/worker/register",
        "worker-token",
        json!({
            "worker_id":"completion-worker/λ😀", "task_queue":"test", "runtime":"php",
            "supported_workflow_types":["echo"], "supported_activity_types":["echo_activity"]
        }),
    )
    .await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    let admitted = json_request(&app, "POST", "/api/workflows", "operator-token", json!({
        "workflow_id":"completion-upload", "workflow_type":"echo", "task_queue":"test",
        "input":envelope(Payload::Array(vec![])), "run_timeout_seconds":120, "execution_timeout_seconds":600
    })).await;
    assert_eq!(admitted.0, StatusCode::CREATED, "{}", admitted.1);
    let poll =
        json!({"worker_id":"completion-worker/λ😀", "task_queue":"test", "timeout_seconds":0});
    let task = json_request(
        &app,
        "POST",
        "/api/worker/workflow-tasks/poll",
        "worker-token",
        poll.clone(),
    )
    .await
    .1["task"]
        .clone();
    let scheduled = json_request(&app, "POST", &format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap()), "worker-token", completion(&task, json!([{
        "type":"schedule_activity", "activity_type":"echo_activity", "arguments":envelope(Payload::Array(vec![])),
        "retry_policy":{"max_attempts":1,"backoff_seconds":[]}, "start_to_close_timeout":start_to_close,
        "schedule_to_close_timeout":600
    }]))).await;
    assert_eq!(scheduled.0, StatusCode::OK, "{}", scheduled.1);
    let task = json_request(
        &app,
        "POST",
        "/api/worker/activity-tasks/poll",
        "worker-token",
        poll,
    )
    .await
    .1["task"]
        .clone();
    assert_eq!(task["run_id"], admitted.1["run_id"]);
    let bytes = ValueCodec::new()
        .unwrap()
        .encode(&Payload::Array(vec![
            Payload::Long(9007199254740993),
            Payload::Null,
            Payload::String("rangeλ".into()),
        ]))
        .unwrap()
        .into_bytes();
    (temp, database, runtime, app, task, bytes)
}

fn context(task: &Value) -> Value {
    json!({"schema":"durable-workflow.v2.payload-completion-context.v1", "kind":"activity",
        "task_id":task["task_id"], "attempt":task["activity_attempt_id"], "lease_owner":task["lease_owner"],
        "operation":"complete", "slot":["result"]})
}

#[tokio::test]
async fn activity_completion_uploads_preserve_authority_allowance_reference_and_cold_parent() {
    let (_provider, database, runtime, app, task, bytes) = original(120).await;
    let context = context(&task);
    let mut utf8 = context.clone();
    utf8["lease_owner"] = json!("foreign/λ😀");
    let refused = upload(&app, "worker-token", "default", &utf8, bytes.clone(), None).await;
    assert_eq!(
        refused.0,
        StatusCode::CONFLICT,
        "valid UTF-8 context: {}",
        refused.2
    );
    assert_eq!(
        refused.2["reason"],
        "external_payload_completion_lease_rejected"
    );
    let named = json_request(
        &app,
        "POST",
        "/api/namespaces",
        "admin-token",
        json!({"name":"other"}),
    )
    .await;
    assert_eq!(named.0, StatusCode::CREATED, "{}", named.1);
    let foreign = upload(&app, "worker-token", "other", &context, bytes.clone(), None).await;
    assert_eq!(foreign.0, StatusCode::CONFLICT, "{}", foreign.2);
    assert_eq!(
        foreign.2["reason"],
        "external_payload_completion_lease_rejected"
    );
    for role in ["operator-token", "admin-token"] {
        assert_eq!(
            upload(&app, role, "default", &context, bytes.clone(), None)
                .await
                .0,
            StatusCode::FORBIDDEN
        );
    }
    for field in ["task_id", "attempt", "lease_owner"] {
        let read = Arc::new(AtomicUsize::new(0));
        let observed = read.clone();
        let body = Body::from_stream(futures_util::stream::once(async move {
            observed.fetch_add(1, Ordering::SeqCst);
            Ok::<_, std::io::Error>(Bytes::from_static(b"unread"))
        }));
        let mut bad = context.clone();
        bad[field] = json!("foreign");
        let response = upload(
            &app,
            "worker-token",
            "default",
            &bad,
            bytes.clone(),
            Some(body),
        )
        .await;
        assert_eq!(response.0, StatusCode::CONFLICT, "{}", response.2);
        assert_eq!(
            response.2["reason"],
            "external_payload_completion_lease_rejected"
        );
        assert_eq!(
            read.load(Ordering::SeqCst),
            0,
            "fenced context must not demand provider bytes"
        );
    }
    assert_eq!(unclaimed(&database).await, 0);
    for field in ["task_id", "attempt"] {
        let mut alias = context.clone();
        let original = alias[field].as_str().unwrap();
        let lower = original.to_ascii_lowercase();
        let changed = if lower == original {
            original.to_ascii_uppercase()
        } else {
            lower
        };
        assert_ne!(changed, original);
        alias[field] = json!(changed);
        let response = upload(&app, "worker-token", "default", &alias, bytes.clone(), None).await;
        assert_eq!(
            response.0,
            StatusCode::CONFLICT,
            "noncanonical {field}: {}",
            response.2
        );
        assert_eq!(
            response.2["reason"],
            "external_payload_completion_lease_rejected"
        );
    }
    let budget = upload(
        &app,
        "worker-token",
        "default",
        &context,
        vec![b'x'; 65],
        None,
    )
    .await;
    assert_eq!(budget.0, StatusCode::TOO_MANY_REQUESTS, "{}", budget.2);
    assert_eq!(
        budget.2["reason"],
        "external_payload_completion_budget_exhausted"
    );
    assert_eq!(budget.2["retry_after_seconds"], 5);
    assert_eq!(budget.1["retry-after"], "5");
    assert_eq!(unclaimed(&database).await, 0);
    let uploaded = upload(
        &app,
        "worker-token",
        "default",
        &context,
        bytes.clone(),
        None,
    )
    .await;
    assert_eq!(uploaded.0, StatusCode::CREATED, "{}", uploaded.2);
    let reference = uploaded.2["reference"].clone();
    assert_eq!(reference["sha256"], digest(&bytes));
    let duplicate = upload(
        &app,
        "worker-token",
        "default",
        &context,
        bytes.clone(),
        None,
    )
    .await;
    assert_eq!(duplicate.0, StatusCode::CREATED);
    assert_eq!(duplicate.2["reference"], reference);
    let conflict = upload(
        &app,
        "worker-token",
        "default",
        &context,
        b"changed".to_vec(),
        None,
    )
    .await;
    assert_eq!(conflict.0, StatusCode::CONFLICT);
    assert_eq!(conflict.2["reason"], "external_payload_completion_conflict");
    assert_eq!(unclaimed(&database).await, 1);
    let completed = json_request(
        &app,
        "POST",
        &format!(
            "/api/worker/activity-tasks/{}/complete",
            task["task_id"].as_str().unwrap()
        ),
        "worker-token",
        json!({
            "activity_attempt_id":task["activity_attempt_id"], "lease_owner":task["lease_owner"],
            "result":{"codec":"avro","external_payload":reference}
        }),
    )
    .await;
    assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
    assert_eq!(completed.1["recorded"], true);
    assert_eq!(unclaimed(&database).await, 0);
    let duplicate = upload(
        &app,
        "worker-token",
        "default",
        &context,
        bytes.clone(),
        None,
    )
    .await;
    assert_eq!(duplicate.0, StatusCode::CREATED, "{}", duplicate.2);
    assert_eq!(duplicate.2["reference"], reference);
    let cold = database.open().await.unwrap().with_role_tokens(
        Some("worker-token".into()),
        Some("operator-token".into()),
        Some("admin-token".into()),
    );
    let cold_app = router(cold.clone());
    let parent = json_request(
        &cold_app,
        "POST",
        "/api/worker/workflow-tasks/poll",
        "worker-token",
        json!({
            "worker_id":"completion-worker", "task_queue":"test", "timeout_seconds":0
        }),
    )
    .await
    .1["task"]
        .clone();
    assert_eq!(parent["run_id"], task["run_id"]);
    let events = parent["history_events"]
        .as_array()
        .unwrap()
        .iter()
        .filter(|event| event["event_type"] == "ActivityCompleted")
        .collect::<Vec<_>>();
    assert_eq!(events.len(), 1);
    assert_eq!(
        events[0]["payload"]["result"]["external_payload"],
        reference
    );
    let fetched = request(
        &cold_app,
        "GET",
        &format!(
            "/api/external-payloads/v1/{}",
            reference["reference_id"].as_str().unwrap()
        ),
        "worker-token",
        "default",
        Some(&reference),
        Body::empty(),
    )
    .await;
    assert_eq!(fetched.0, StatusCode::OK);
    assert_eq!(fetched.2, bytes);
    let result = ValueCodec::new()
        .unwrap()
        .decode(std::str::from_utf8(&fetched.2).unwrap())
        .unwrap();
    assert_eq!(
        result,
        Payload::Array(vec![
            Payload::Long(9007199254740993),
            Payload::Null,
            Payload::String("rangeλ".into())
        ])
    );
    let final_result = json_request(
        &cold_app,
        "POST",
        &format!(
            "/api/worker/workflow-tasks/{}/complete",
            parent["task_id"].as_str().unwrap()
        ),
        "worker-token",
        completion(
            &parent,
            json!([{"type":"complete_workflow","result":envelope(result)}]),
        ),
    )
    .await;
    assert_eq!(final_result.0, StatusCode::OK, "{}", final_result.1);
    cold.close().await;
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn activity_expiry_during_upload_leaves_no_ready_reference_or_provider_write() {
    let (provider, database, runtime, app, task, bytes) = original(1).await;
    let body_bytes = bytes.clone();
    let body = Body::from_stream(futures_util::stream::once(async move {
        tokio::time::sleep(Duration::from_millis(2500)).await;
        Ok::<_, std::io::Error>(Bytes::from(body_bytes))
    }));
    let refused = upload(
        &app,
        "worker-token",
        "default",
        &context(&task),
        bytes,
        Some(body),
    )
    .await;
    assert_eq!(refused.0, StatusCode::CONFLICT, "{}", refused.2);
    assert_eq!(
        refused.2["reason"],
        "external_payload_completion_lease_rejected"
    );
    assert_eq!(unclaimed(&database).await, 0);
    assert_eq!(provider_files(provider.path()), 0);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn activity_completion_during_upload_cannot_reopen_its_closed_allowance() {
    let (provider, database, runtime, app, task, bytes) = original(120).await;
    let entered = Arc::new(tokio::sync::Notify::new());
    let observed = entered.clone();
    let (release, wait) = tokio::sync::oneshot::channel();
    let body_bytes = bytes.clone();
    let body = Body::from_stream(futures_util::stream::once(async move {
        observed.notify_one();
        wait.await.unwrap();
        Ok::<_, std::io::Error>(Bytes::from(body_bytes))
    }));
    let upload_app = app.clone();
    let original_context = context(&task);
    let pending = tokio::spawn(async move {
        upload(
            &upload_app,
            "worker-token",
            "default",
            &original_context,
            bytes,
            Some(body),
        )
        .await
    });
    tokio::time::timeout(Duration::from_secs(3), entered.notified())
        .await
        .unwrap();
    let complete = json_request(&app, "POST", &format!("/api/worker/activity-tasks/{}/complete", task["task_id"].as_str().unwrap()), "worker-token", json!({
        "activity_attempt_id":task["activity_attempt_id"],"lease_owner":task["lease_owner"],"result":envelope(Payload::Null)
    })).await;
    assert_eq!(complete.0, StatusCode::OK, "{}", complete.1);
    assert_eq!(complete.1["recorded"], true);
    release.send(()).unwrap();
    let refused = pending.await.unwrap();
    assert_eq!(refused.0, StatusCode::CONFLICT, "{}", refused.2);
    assert_eq!(
        refused.2["reason"],
        "external_payload_completion_lease_rejected"
    );
    assert_eq!(unclaimed(&database).await, 0);
    assert_eq!(provider_files(provider.path()), 0);
    runtime.close().await;
    database.remove().await;
}
