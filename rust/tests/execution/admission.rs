use super::*;
use axum::http::HeaderMap;

pub(super) async fn admission_http(
    app: &Router,
    method: &str,
    uri: &str,
    worker: bool,
    token: Option<&str>,
    version: Option<&str>,
    namespace: Option<&str>,
    body: Value,
) -> (StatusCode, HeaderMap, Value) {
    let mut request = Request::builder()
        .method(method)
        .uri(uri)
        .header("content-type", "application/json");
    if let Some(token) = token {
        request = request.header("authorization", format!("Bearer {token}"));
    }
    if let Some(version) = version {
        request = request.header(
            if worker {
                "x-durable-workflow-protocol-version"
            } else {
                "x-durable-workflow-control-plane-version"
            },
            version,
        );
    }
    if let Some(namespace) = namespace {
        request = request.header("x-namespace", namespace);
    }
    let response = app
        .clone()
        .oneshot(request.body(Body::from(body.to_string())).unwrap())
        .await
        .unwrap();
    let status = response.status();
    let headers = response.headers().clone();
    let body = serde_json::from_slice(&to_bytes(response.into_body(), 1024 * 1024).await.unwrap())
        .unwrap();
    (status, headers, body)
}

#[tokio::test]
async fn admission_authentication_precedes_protocol_and_namespace_in_both_planes() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "admission-worker", json!(["echo"]), json!([])).await;
    let root = start(&app, "admission-root").await;
    let task = poll(&app, "admission-worker", "workflow").await;
    assert_eq!(finish_task(&app, &task, json!([{"type":"complete_workflow", "result":envelope(Payload::Long(9007199254740993))}])).await.0, StatusCode::OK);
    let peer = start(&app, "admission-pending").await;
    let peer_path = format!(
        "/api/workflows/admission-pending/runs/{}",
        peer["run_id"].as_str().unwrap()
    );
    let before = request(&app, "GET", &format!("{peer_path}/history"), Value::Null)
        .await
        .1;
    for worker in [false, true] {
        for (token, version) in [(None, None), (Some("wrong-token"), Some("999"))] {
            let path = if worker {
                "/api/worker/workflow-tasks/poll".to_owned()
            } else {
                format!("{peer_path}/cancel")
            };
            let (status, headers, body) = admission_http(
                &app,
                "POST",
                &path,
                worker,
                token,
                version,
                Some("ghost"),
                json!({"reason":"refused", "worker_id":"refused-worker", "task_queue":"test"}),
            )
            .await;
            assert_eq!(status, StatusCode::UNAUTHORIZED);
            assert_eq!(
                body["reason"], "unauthorized",
                "authentication must precede protocol/namespace lookup"
            );
            assert_eq!(body["message"], "Invalid or missing authentication token.");
            assert!(body.get("namespace").is_none());
            assert!(body.get("supported_version").is_none());
            if worker {
                assert_eq!(headers["x-durable-workflow-protocol-version"], "1.20");
                assert_eq!(body["protocol_version"], "1.20");
                assert!(body["server_capabilities"].is_object());
                assert!(body.get("control_plane").is_none());
            } else {
                assert_eq!(headers["x-durable-workflow-control-plane-version"], "2");
                assert_eq!(body["control_plane"]["operation"], "cancel");
                assert_eq!(body["control_plane"]["run_id"], peer["run_id"]);
            }
        }
    }
    assert_eq!(
        request(&app, "GET", &format!("{peer_path}/history"), Value::Null)
            .await
            .1,
        before
    );
    assert_eq!(
        request(&app, "GET", &peer_path, Value::Null).await.1["status"],
        "pending"
    );
    assert_eq!(
        request(
            &app,
            "GET",
            &format!(
                "/api/workflows/admission-root/runs/{}",
                root["run_id"].as_str().unwrap()
            ),
            Value::Null
        )
        .await
        .1["status"],
        "completed"
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn admission_namespace_query_refuses_mutation_and_header_precedence_normalizes_names() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "namespace-worker", json!(["echo"]), json!([])).await;
    let peer = start(&app, "namespace-pending").await;
    let path = format!(
        "/api/workflows/namespace-pending/runs/{}",
        peer["run_id"].as_str().unwrap()
    );
    let before = request(&app, "GET", &format!("{path}/history"), Value::Null)
        .await
        .1;
    let denied = admission_http(
        &app,
        "POST",
        &format!("{path}/cancel?namespace=ghost"),
        false,
        Some("test-token"),
        Some("2"),
        None,
        json!({"reason":"must never become durable"}),
    )
    .await;
    let after = request(&app, "GET", &path, Value::Null).await.1;
    assert_eq!(
        after["status"], "pending",
        "unknown query namespace may not mutate the default peer"
    );
    assert_eq!(denied.0, StatusCode::NOT_FOUND);
    assert_eq!(denied.2["reason"], "namespace_not_found");
    assert_eq!(denied.2["namespace"], "ghost");
    assert_eq!(
        request(&app, "GET", &format!("{path}/history"), Value::Null)
            .await
            .1,
        before
    );
    for (header, query) in [
        (Some("DEFAULT"), "ghost"),
        (None, "DEFAULT"),
        (Some("default"), "ghost"),
    ] {
        let selected = admission_http(
            &app,
            "GET",
            &format!("{path}?namespace={query}"),
            false,
            Some("test-token"),
            Some("2"),
            header,
            Value::Null,
        )
        .await;
        assert_eq!(selected.0, StatusCode::OK, "{header:?} / {query}");
        assert_eq!(selected.2["namespace"], "default");
        assert_eq!(selected.2["run_id"], peer["run_id"]);
        assert_eq!(selected.2["status"], "pending");
    }
    let worker = admission_http(
        &app,
        "POST",
        "/api/worker/workflow-tasks/poll?namespace=ghost",
        true,
        Some("test-token"),
        Some("1.20"),
        None,
        json!({"worker_id":"namespace-worker", "task_queue":"test", "timeout_seconds":0}),
    )
    .await;
    assert_eq!(worker.0, StatusCode::NOT_FOUND);
    assert_eq!(worker.2["reason"], "namespace_not_found");
    assert_eq!(worker.2["namespace"], "ghost");
    let malformed = admission_http(
        &app,
        "POST",
        &format!("{path}/cancel?namespace%5B%5D=default"),
        false,
        Some("test-token"),
        Some("2"),
        None,
        json!({"reason":"must never become durable"}),
    )
    .await;
    assert_eq!(malformed.0, StatusCode::BAD_REQUEST);
    assert_eq!(malformed.2["reason"], "invalid_namespace");
    assert_eq!(
        request(&app, "GET", &path, Value::Null).await.1["status"],
        "pending"
    );
    assert_eq!(
        request(&app, "GET", &format!("{path}/history"), Value::Null)
            .await
            .1,
        before
    );
    runtime.close().await;
    database.remove().await;
}
