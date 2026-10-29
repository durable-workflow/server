use super::*;

// Published Server 2.11.5 answers worker-plane validation failures and
// unknown activity tasks in the worker envelope. The worker-error-bodies
// fixture records the same requests.

fn enveloped(body: &Value) -> bool {
    body["protocol_version"].is_string() && body["server_capabilities"].is_object()
}

#[tokio::test]
async fn poll_validation_failures_carry_laravels_messages_in_the_envelope() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (status, body) = request_protocol(&app, "POST", "/api/worker/workflow-tasks/poll", json!({}), "1.20").await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::UNPROCESSABLE_ENTITY, json!("validation_failed")), "{body}");
    assert_eq!(body["errors"], json!({"worker_id":["The worker id field is required."],"task_queue":["The task queue field is required."]}));
    assert_eq!(body["validation_errors"], body["errors"]);
    assert_eq!(body["message"], "The worker id field is required. (and 1 more error)");
    assert!(enveloped(&body), "{body}");
    let (status, body) = request_protocol(&app, "POST", "/api/worker/workflow-tasks/poll", json!({"worker_id":"w","task_queue":"q",
        "timeout_seconds":61,"history_page_size":0,"poll_request_id":"r".repeat(256),"task_kinds":[]}), "1.20").await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    assert_eq!(body["message"], "The poll request id field must not be greater than 255 characters. (and 3 more errors)");
    assert_eq!(body["errors"]["task_kinds"], json!(["The task kinds field must have at least 1 items."]));
    // `distinct` is part of the same validation: duplicate kinds are reported
    // beside the other fields' failures, every duplicate index included.
    let (status, body) = request_protocol(&app, "POST", "/api/worker/workflow-tasks/poll", json!({"worker_id":"w","task_queue":"q",
        "timeout_seconds":61,"task_kinds":["workflow","workflow"]}), "1.20").await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    assert_eq!(body["errors"], json!({"timeout_seconds":["The timeout seconds field must not be greater than 60."],
        "task_kinds.0":["The task_kinds.0 field has a duplicate value."],"task_kinds.1":["The task_kinds.1 field has a duplicate value."]}));
    assert_eq!(body["message"], "The timeout seconds field must not be greater than 60. (and 2 more errors)");
    // `distinct` compares with PHP's loose `==`: true equals "workflow".
    let (status, body) = request_protocol(&app, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"w","task_queue":"q","task_kinds":["workflow",true]}), "1.20").await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    assert_eq!(body["errors"], json!({"task_kinds.0":["The task_kinds.0 field has a duplicate value."],
        "task_kinds.1":["The task_kinds.1 field must be a string.","The task_kinds.1 field has a duplicate value.",
            "The selected task_kinds.1 is invalid."]}));
    let (status, body) = request_protocol(&app, "POST", "/api/worker/activity-tasks/poll",
        json!({"worker_id":"w","task_queue":"q","timeout_seconds":-1}), "1.20").await;
    assert_eq!((status, body["errors"].clone()), (StatusCode::UNPROCESSABLE_ENTITY,
        json!({"timeout_seconds":["The timeout seconds field must be at least 0."]})), "{body}");
    assert!(enveloped(&body), "{body}");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn an_unknown_activity_task_is_answered_with_its_identity_on_every_route() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let attempt = json!({"activity_attempt_id":"missing-attempt","lease_owner":"missing-worker"});
    for (route, extra) in [
        ("complete", json!({"result":envelope(Payload::Null)})),
        ("fail", json!({"failure":{"message":"missing"}})),
        ("heartbeat", json!({})),
    ] {
        let mut body = attempt.clone();
        body.as_object_mut().unwrap().extend(extra.as_object().unwrap().clone());
        let (status, answer) = request_protocol(&app, "POST", &format!("/api/worker/activity-tasks/missing-task/{route}"), body, "1.20").await;
        assert_eq!(status, StatusCode::NOT_FOUND, "{route}: {answer}");
        assert_eq!((answer["task_id"].clone(), answer["activity_attempt_id"].clone(), answer["error"].clone(), answer["reason"].clone()),
            (json!("missing-task"), json!("missing-attempt"), json!("Activity task not found."), json!("task_not_found")), "{route}: {answer}");
        assert!(enveloped(&answer), "{route}: {answer}");
    }
    runtime.close().await;
    database.remove().await;
}

// PHP's 404 and 405 do not depend on the namespace: an unknown worker route
// in a named namespace is not refused as an unqualified named operation.
#[tokio::test]
async fn unknown_worker_routes_answer_404_and_405_in_a_named_namespace() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (status, body) = request(&app, "POST", "/api/namespaces", json!({"name":"errors-elsewhere","description":"named"})).await;
    assert!(status.is_success(), "{body}");
    for (method, path, expected) in [
        ("GET", "/api/worker/nothing-here", StatusCode::NOT_FOUND),
        ("GET", "/api/worker/workflow-tasks/poll", StatusCode::METHOD_NOT_ALLOWED),
    ] {
        let response = app.clone().oneshot(Request::builder().method(method).uri(path)
            .header("authorization", "Bearer test-token").header("x-namespace", "errors-elsewhere")
            .header("x-durable-workflow-protocol-version", "1.20").body(Body::empty()).unwrap()).await.unwrap();
        assert_eq!(response.status(), expected, "{method} {path}");
        let body: Value = serde_json::from_slice(&axum::body::to_bytes(response.into_body(), usize::MAX).await.unwrap()).unwrap();
        assert!(enveloped(&body), "{body}");
    }
    runtime.close().await;
    database.remove().await;
}

// PHP's ownership guard finds an unknown workflow task before its command
// normalizer reads a payload: a result-less complete_workflow is not
// refused first.
#[tokio::test]
async fn an_unknown_workflow_task_is_answered_before_its_payloads_are_read() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (status, body) = request_protocol(&app, "POST", "/api/worker/workflow-tasks/missing-task/complete",
        json!({"lease_owner":"missing-worker","workflow_task_attempt":1,"commands":[{"type":"complete_workflow"}]}), "1.20").await;
    assert_eq!(status, StatusCode::NOT_FOUND, "{body}");
    assert_eq!((body["task_id"].clone(), body["workflow_task_attempt"].clone(), body["error"].clone(), body["reason"].clone()),
        (json!("missing-task"), json!(1), json!("Workflow task not found."), json!("task_not_found")), "{body}");
    assert!(enveloped(&body), "{body}");
    runtime.close().await;
    database.remove().await;
}

// Every request of the worker-error-bodies fixture, answered as published
// 2.11.5 answered it there (`server_capabilities` reduced to its presence).
#[tokio::test]
async fn the_fixture_requests_are_answered_as_published_php_answered_them() {
    let fixture: Value = serde_json::from_str(include_str!("../../../tests/Fixtures/ServerParity/worker-error-bodies.json")).unwrap();
    let definition = &fixture["worker_error_bodies"];
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    for (name, request) in definition["requests"].as_object().unwrap() {
        let (method, path, body) = (request[0].as_str().unwrap(), request[1].as_str().unwrap(), request[2].clone());
        let (status, mut answer) = request_protocol(&app, method, &format!("/api/{path}"), body, "1.20").await;
        let capabilities = answer["server_capabilities"].is_object();
        answer.as_object_mut().unwrap().remove("server_capabilities");
        let expected = &definition["expected"][name];
        assert_eq!((status.as_u16(), capabilities), (expected["status"].as_u64().unwrap() as u16, expected["server_capabilities"] == true),
            "{name}: {answer}");
        assert_eq!(answer, expected["body"], "{name}");
    }
    runtime.close().await;
    database.remove().await;
}
