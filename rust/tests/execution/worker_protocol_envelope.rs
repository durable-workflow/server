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

// A route's own refusal is an answer like any other: prepare and deliver
// carry the envelope at each stage they can refuse. They answer for a
// claimed task, so they are reached with a real claim.
#[tokio::test]
async fn scope_prepare_and_deliver_refusals_carry_the_envelope() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let registered = request_protocol(&app, "POST", "/api/worker/register",
        json!({"worker_id":"envelope-scope","task_queue":"test","runtime":"php","supported_workflow_types":["echo"],
            "supported_activity_types":[],"capabilities":["cooperative_cancellation"]}), "1.20").await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    start(&app, "envelope-scope").await;
    let (status, polled) = request_protocol(&app, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"envelope-scope","task_queue":"test","timeout_seconds":2}), "1.20").await;
    assert_eq!(status, StatusCode::OK, "{polled}");
    let task = &polled["task"];
    let delivery = json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],
        "scope_id":"no-such-scope","request_id":"envelope-request","sequence":1,"call_kind":"timer"});
    for route in ["prepare", "deliver"] {
        let path = format!("/api/worker/workflow-tasks/{}/cancellation-scopes/{route}", task["task_id"].as_str().unwrap());
        // request_protocol's sweep asserts the header and both members.
        for (body, protocol) in [(delivery.clone(), "1.19"), (json!({}), "1.20"), (delivery.clone(), "1.20")] {
            let (status, answer) = request_protocol(&app, "POST", &path, body, protocol).await;
            assert!(status.is_client_error(), "{route} {protocol}: {status} {answer}");
            assert!(answer["reason"].is_string() || answer["errors"].is_object(), "{route} {protocol}: {answer}");
        }
    }
    runtime.close().await;
    database.remove().await;
}

// A storage failure while resolving the namespace is still a worker answer:
// it gets the version header and the body envelope (server#1001 follow-up).
#[tokio::test]
async fn a_namespace_storage_failure_carries_the_envelope() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    runtime.close().await;
    let (status, header, body) = raw_poll(&app, "workflow", "envelope-closed").await;
    assert!(status.is_server_error(), "{status}: {body}");
    assert_eq!(header.as_deref(), Some("1.20"));
    assert_eq!(body["protocol_version"], "1.20", "{body}");
    assert!(body["server_capabilities"].is_object(), "{body}");
    database.remove().await;
}
