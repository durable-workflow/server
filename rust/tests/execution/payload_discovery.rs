use super::*;

async fn in_namespace(app: &Router, method: &str, path: &str, namespace: &str, body: Value) -> (StatusCode, Value) {
    let request = Request::builder()
        .method(method)
        .uri(path)
        .header("authorization", "Bearer test-token")
        .header("content-type", "application/json")
        .header("x-namespace", namespace)
        .header("x-durable-workflow-control-plane-version", "2")
        .body(Body::from(body.to_string()))
        .unwrap();
    let response = app.clone().oneshot(request).await.unwrap();
    let status = response.status();
    let body = to_bytes(response.into_body(), 4 * 1024 * 1024).await.unwrap();
    (status, serde_json::from_slice(&body).unwrap())
}

// Published PHP 2.11.1's HealthController::clusterInfo, as SDKs read it
// before uploading a payload: the request namespace's storage policy, the
// ordinary request limits and the transport manifest, which is also the worker
// capability `runtime_external_payload_transport`. The reviewed parity
// fixture `cluster-info` compares the same reads with published PHP.
#[tokio::test]
async fn cluster_info_discovers_runtime_payload_storage_like_php() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let uri = "file:///tmp/dw-native-discovery";
    for name in ["disc-on", "disc-plain", "disc-off"] {
        let created = in_namespace(&app, "POST", "/api/namespaces", "default",
            json!({"name": name, "description": "discovery λ", "retention_days": 30})).await;
        assert_eq!(created.0, StatusCode::CREATED, "{created:?}");
    }
    for (name, enabled, threshold) in [("disc-on", true, 16), ("disc-off", false, 32)] {
        let policy = json!({"driver": "local", "enabled": enabled, "threshold_bytes": threshold, "config": {"uri": uri}});
        let stored = in_namespace(&app, "PUT", &format!("/api/namespaces/{name}/external-storage"), name, policy.clone()).await;
        assert_eq!((stored.0, stored.1["external_payload_storage"].clone()), (StatusCode::OK, policy));
    }

    let mut transport = Value::Null;
    for (name, exists, configured, enabled, status, threshold) in [
        ("disc-on", true, true, true, "available", 16),
        ("disc-plain", true, false, false, "unconfigured", 2_097_152),
        ("disc-off", true, true, false, "disabled", 32),
        ("disc-absent", false, false, false, "unconfigured", 2_097_152),
    ] {
        let (code, info) = in_namespace(&app, "GET", "/api/cluster/info", name, Value::Null).await;
        assert_eq!(code, StatusCode::OK);
        let namespace = &info["namespace"];
        assert_eq!((namespace["name"].clone(), namespace["exists"].clone()), (json!(name), json!(exists)), "{name}");
        let row = if exists { json!(["active", "bounded", 30]) } else { json!([null, null, null]) };
        assert_eq!(json!([namespace["status"], namespace["retention_mode"], namespace["retention_days"]]), row, "{name}");
        let storage = &namespace["external_payload_storage"];
        assert_eq!(
            json!([storage["configured"], storage["enabled"], storage["status"], storage["configuration_error"], storage["threshold_bytes"]]),
            json!([configured, enabled, status, null, threshold]),
            "{name} storage policy"
        );
        assert_eq!(storage["schema"], "durable-workflow.v2.runtime-external-payload-reference.v1");
        assert_eq!(storage["provider_details_exposed"], false);
        assert_eq!(storage["transport"], info["worker_protocol"]["server_capabilities"]["runtime_external_payload_transport"],
            "{name} namespace transport is the worker capability");
        assert_eq!(info["limits"]["max_payload_bytes"], 2_097_152);
        assert_eq!(info["limits"]["max_memo_bytes"], 262_144);
        assert_eq!(info["limits"]["max_search_attributes"], 100);
        assert_eq!(info["default_namespace"], "default");
        assert!(info["server_id"].as_str().is_some_and(|id| !id.is_empty()), "server identity");
        transport = storage["transport"].clone();
    }

    // What an installed SDK checks before it uploads (PHP SDK 2.3.9's
    // RuntimePayloadUploads::policy), and the members native does not serve.
    assert_eq!(transport["schema"], "durable-workflow.v2.runtime-external-payload-transport.v1");
    assert_eq!((transport["version"].clone(), transport["mode"].clone()), (json!(1), json!("authenticated_namespace_runtime")));
    assert_eq!(transport["upload"]["path"], "/api/external-payloads/v1");
    assert_eq!(transport["fetch"]["path_template"], "/api/external-payloads/v1/{referenceId}");
    assert_eq!(transport["limits"]["max_payload_bytes"], 64 * 1024 * 1024);
    assert_eq!(transport["limits"]["request_timeout_seconds"], 30);
    let context = &transport["upload"]["completion_context"];
    assert_eq!(context["schema"], "durable-workflow.v2.payload-completion-context.v1");
    assert_eq!((context["prepared_schema"].clone(), context["scope_schema"].clone()), (Value::Null, Value::Null));
    assert_eq!(context["max_slots_per_lease"], 128);
    assert_eq!(transport["audit_events"], json!([]));
    assert_eq!(transport["typed_outcomes"].as_object().map(|outcomes| outcomes.len()), Some(14));

    // Every worker response carries the same capability, as PHP's do.
    register(&app, "discovery-worker", json!(["discovery"]), json!([])).await;
    let (_, registered) = request(&app, "POST", "/api/worker/register",
        json!({"worker_id": "discovery-worker", "task_queue": "test", "runtime": "php",
            "supported_workflow_types": ["discovery"], "supported_activity_types": []})).await;
    assert_eq!(registered["server_capabilities"]["runtime_external_payload_transport"], transport);
    runtime.close().await;
    database.remove().await;
}
