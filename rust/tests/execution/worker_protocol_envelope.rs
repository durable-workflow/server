use super::*;

// Server #1001: like PHP's `WorkerProtocol::json`, every worker-plane JSON
// answer carries the `protocol_version` body member its header advertises,
// plus `server_capabilities`. Native 3.0.5 sent only the header on successful
// polls, so the published CLI's `dw workflow-task:poll` refused the answer.

/// The raw answer to a worker poll, with its protocol header.
async fn raw_poll(app: &Router, kind: &str, worker: &str) -> (StatusCode, Option<String>, Value) {
    let request = Request::builder()
        .method("POST")
        .uri(format!("/api/worker/{kind}-tasks/poll"))
        .header("authorization", "Bearer test-token")
        .header("content-type", "application/json")
        .header("x-namespace", "default")
        .header("x-durable-workflow-protocol-version", "1.0")
        .body(Body::from(json!({"worker_id":worker,"task_queue":"test","timeout_seconds":0}).to_string()))
        .unwrap();
    let response = app.clone().oneshot(request).await.unwrap();
    let status = response.status();
    let header = response
        .headers()
        .get("x-durable-workflow-protocol-version")
        .and_then(|value| value.to_str().ok())
        .map(str::to_owned);
    let body = to_bytes(response.into_body(), 4 * 1024 * 1024).await.unwrap();
    (status, header, serde_json::from_slice(&body).unwrap())
}

#[tokio::test]
async fn leased_and_empty_polls_echo_the_protocol_version_in_the_body() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "envelope-worker", json!(["echo"]), json!(["activity"])).await;
    start(&app, "envelope-poll").await;
    for (kind, leased) in [("workflow", true), ("workflow", false), ("activity", false)] {
        // A 1.0 worker, as the published CLI 2.3.3 sends, gets the server's
        // advertised version back in both places.
        let (status, header, body) = raw_poll(&app, kind, "envelope-worker").await;
        assert_eq!(status, StatusCode::OK, "{kind}: {body}");
        assert_eq!(body["task"].is_object(), leased, "{kind}: {body}");
        assert_eq!(header.as_deref(), Some("1.20"), "{kind}");
        assert_eq!(body["protocol_version"], "1.20", "{kind}: {body}");
        assert!(body["server_capabilities"].is_object(), "{kind}: {body}");
        if kind == "workflow" {
            assert_eq!(body["poll_status"], if leased { "leased" } else { "empty" }, "{kind}: {body}");
        }
    }
    runtime.close().await;
    database.remove().await;
}
