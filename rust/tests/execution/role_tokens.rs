use super::*;

fn configured(runtime: Runtime) -> Runtime {
    runtime.with_role_tokens(
        Some("worker-token".into()),
        Some("operator-token".into()),
        Some("admin-token".into()),
    )
}

async fn call(
    app: &Router,
    token: &str,
    method: &str,
    path: &str,
    body: Value,
) -> (StatusCode, Value) {
    let worker = path.starts_with("/api/worker/");
    let (status, _, body) = admission::admission_http(
        app,
        method,
        path,
        worker,
        Some(token),
        Some(if worker { "1.20" } else { "2" }),
        Some("default"),
        body,
    )
    .await;
    (status, body)
}

#[tokio::test]
async fn configured_roles_precede_protocol_namespace_and_preserve_original_peer() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let legacy = router(runtime.clone());
    let peer = start(&legacy, "role-pending").await;
    let path = format!(
        "/api/workflows/role-pending/runs/{}",
        peer["run_id"].as_str().unwrap()
    );
    let history_path = format!("{path}/history");
    let before = request(&legacy, "GET", &history_path, Value::Null).await.1;
    let app = router(configured(runtime.clone()));
    for (token, role, worker) in [
        ("worker-token", "worker", false),
        ("operator-token", "operator", true),
        ("admin-token", "admin", true),
        ("test-token", "admin", true),
    ] {
        let target = if worker {
            "/api/worker/workflow-tasks/poll".to_owned()
        } else {
            format!("{path}/cancel")
        };
        let (status, headers, body) = admission::admission_http(
            &app, "POST", &target, worker, Some(token), Some("999"), Some("ghost"),
            json!({"worker_id":"refused-worker","task_queue":"test","timeout_ms":0,"reason":"refused"}),
        ).await;
        assert_eq!(status, StatusCode::FORBIDDEN, "{body}");
        assert_eq!(body["reason"], "forbidden");
        assert_eq!(
            body["message"],
            "Authenticated role is not allowed to access this endpoint."
        );
        assert_eq!(body["role"], role);
        assert_eq!(
            body["allowed_roles"],
            if worker {
                json!(["worker"])
            } else {
                json!(["operator", "admin"])
            }
        );
        assert!(body.get("namespace").is_none());
        assert!(body.get("requested_version").is_none());
        if worker {
            assert_eq!(headers["x-durable-workflow-protocol-version"], "1.20");
            assert!(body["server_capabilities"].is_object());
        } else {
            assert_eq!(headers["x-durable-workflow-control-plane-version"], "2");
            assert_eq!(body["control_plane"]["operation"], "cancel");
            assert_eq!(body["control_plane"]["run_id"], peer["run_id"]);
        }
    }
    for token in ["operator-token", "admin-token", "test-token"] {
        let read = call(&app, token, "GET", &path, Value::Null).await;
        assert_eq!(read.0, StatusCode::OK, "{}", read.1);
        assert_eq!(read.1["status"], "pending");
    }
    assert_eq!(
        call(&app, "admin-token", "GET", &history_path, Value::Null)
            .await
            .1,
        before
    );
    let cleanup = call(
        &app,
        "operator-token",
        "POST",
        &format!("{path}/cancel"),
        json!({"reason":"authorized λ"}),
    )
    .await;
    assert_eq!(cleanup.0, StatusCode::OK, "{}", cleanup.1);
    assert_eq!(cleanup.1["run_id"], peer["run_id"]);
    assert_eq!(cleanup.1["outcome"], "cancelled");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn configured_worker_completes_original_lease_without_legacy_poll_bypass() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let legacy = router(runtime.clone());
    register(&legacy, "role-worker", json!(["echo"]), json!([])).await;
    let original = start(&legacy, "role-original").await;
    let app = router(configured(runtime.clone()));
    let poll_body = json!({"worker_id":"role-worker","task_queue":"test","timeout_seconds":0});
    let denied = call(
        &app,
        "test-token",
        "POST",
        "/api/worker/workflow-tasks/poll",
        poll_body.clone(),
    )
    .await;
    assert_eq!(denied.0, StatusCode::FORBIDDEN, "{}", denied.1);
    let claimed = call(
        &app,
        "worker-token",
        "POST",
        "/api/worker/workflow-tasks/poll",
        poll_body,
    )
    .await;
    assert_eq!(claimed.0, StatusCode::OK, "{}", claimed.1);
    let task = &claimed.1["task"];
    assert_eq!(task["workflow_id"], "role-original");
    assert_eq!(task["run_id"], original["run_id"]);
    let complete_path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        task["task_id"].as_str().unwrap()
    );
    let complete_body = completion(
        task,
        json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}]),
    );
    let wrong = call(
        &app,
        "admin-token",
        "POST",
        &complete_path,
        complete_body.clone(),
    )
    .await;
    assert_eq!(wrong.0, StatusCode::FORBIDDEN, "{}", wrong.1);
    let completed = call(&app, "worker-token", "POST", &complete_path, complete_body).await;
    assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
    let path = format!(
        "/api/workflows/role-original/runs/{}",
        original["run_id"].as_str().unwrap()
    );
    let run = call(&app, "operator-token", "GET", &path, Value::Null).await;
    assert_eq!(run.0, StatusCode::OK, "{}", run.1);
    assert_eq!(run.1["status"], "completed");
    for token in ["worker-token", "operator-token", "admin-token"] {
        let registered = call(
            &app,
            token,
            "POST",
            "/api/worker/register",
            json!({
                "worker_id":"diagnostic-registration","task_queue":"test","runtime":"php",
                "supported_workflow_types":["echo"],"supported_activity_types":[]
            }),
        )
        .await;
        assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    }
    assert_eq!(
        call(
            &app,
            "admin-token",
            "DELETE",
            "/api/worker/registrations/diagnostic-registration",
            json!({})
        )
        .await
        .0,
        StatusCode::FORBIDDEN
    );
    assert_eq!(
        call(
            &app,
            "worker-token",
            "DELETE",
            "/api/worker/registrations/diagnostic-registration",
            json!({})
        )
        .await
        .0,
        StatusCode::OK
    );
    runtime.close().await;
    database.remove().await;
}
