use super::*;

async fn scoped(
    app: &Router,
    namespace: &str,
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
        Some("test-token"),
        Some(if worker { "1.20" } else { "2" }),
        Some(namespace),
        body,
    )
    .await;
    (status, body)
}

async fn create(app: &Router, name: &str) {
    let (status, body) = scoped(
        app,
        "does-not-exist",
        "POST",
        "/api/namespaces",
        json!({"name":name.to_uppercase(),"description":"namespace λ","retention_days":30}),
    )
    .await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    assert_eq!(body["name"], name);
    assert_eq!(body["retention_mode"], "bounded");
    assert_eq!(body["retention_days"], 30);
}

async fn start_scoped(app: &Router, namespace: &str, workflow: &str) -> Value {
    let (status, body) = scoped(
        app,
        namespace,
        "POST",
        "/api/workflows",
        json!({"workflow_id":workflow,"workflow_type":"echo","task_queue":"test",
            "input":envelope(Payload::Array(vec![Payload::Long(9007199254740993)]))}),
    )
    .await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    assert_eq!(body["namespace"], namespace);
    body
}

#[tokio::test]
async fn named_namespaces_preserve_original_runs_and_global_workflow_id_reservation() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    for name in ["alpha", "beta"] {
        create(&app, name).await;
    }
    let first = start_scoped(&app, "alpha", "namespace-first").await;
    let second = start_scoped(&app, "beta", "namespace-second").await;
    let path = format!(
        "/api/workflows/namespace-first/runs/{}",
        first["run_id"].as_str().unwrap()
    );
    let before = scoped(
        &app,
        "alpha",
        "GET",
        &format!("{path}/history"),
        Value::Null,
    )
    .await;
    assert_eq!(before.0, StatusCode::OK);
    for (method, uri, reason) in [
        (
            "GET",
            "/api/workflows/namespace-first".to_owned(),
            "instance_not_found",
        ),
        ("GET", path.clone(), "run_not_found"),
        ("GET", format!("{path}/history"), "run_not_found"),
        ("POST", format!("{path}/cancel"), "instance_not_found"),
    ] {
        let (status, body) = scoped(
            &app,
            "beta",
            method,
            &uri,
            json!({"reason":"foreign must not mutate"}),
        )
        .await;
        assert_eq!(status, StatusCode::NOT_FOUND, "{body}");
        assert_eq!(body["reason"], reason);
        assert_eq!(body["workflow_id"], "namespace-first");
        assert_eq!(body["control_plane"]["workflow_id"], "namespace-first");
        assert_eq!(
            body["message"],
            if reason == "run_not_found" {
                "Workflow run not found."
            } else {
                "Workflow not found."
            }
        );
        assert_eq!(body["control_plane"]["message"], body["message"]);
        if uri != "/api/workflows/namespace-first" {
            assert_eq!(body["run_id"], first["run_id"]);
            assert_eq!(body["control_plane"]["run_id"], first["run_id"]);
        }
    }
    let (status, body) = scoped(&app, "beta", "POST", "/api/workflows",
        json!({"workflow_id":"namespace-first","workflow_type":"echo","task_queue":"test",
            "duplicate_policy":"use-existing","input":envelope(Payload::Array(vec![Payload::Long(7)]))})).await;
    assert_eq!(status, StatusCode::CONFLICT, "{body}");
    assert_eq!(body["reason"], "workflow_id_reserved_in_namespace");
    assert_eq!(body["control_plane"]["operation"], "start");
    assert_eq!(body["command_status"], "rejected");
    assert_eq!(
        body["outcome"],
        "rejected_workflow_id_reserved_in_namespace"
    );
    assert_eq!(
        scoped(
            &app,
            "alpha",
            "GET",
            &format!("{path}/history"),
            Value::Null
        )
        .await,
        before
    );
    assert_ne!(first["run_id"], second["run_id"]);
    assert_eq!(
        scoped(
            &app,
            "ALPHA",
            "GET",
            "/api/workflows/namespace-first",
            Value::Null
        )
        .await
        .1["namespace"],
        "alpha"
    );
    let listed = scoped(&app, "default", "GET", "/api/namespaces", Value::Null).await;
    assert_eq!(listed.0, StatusCode::OK);
    assert!(
        listed.1["namespaces"]
            .as_array()
            .unwrap()
            .iter()
            .any(|row| row["name"] == "alpha")
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn same_worker_id_isolated_claims_completion_and_deregistration_by_namespace() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    for name in ["alpha", "beta"] {
        create(&app, name).await;
        assert_eq!(
            scoped(
                &app,
                name,
                "POST",
                "/api/worker/register",
                json!({
                    "worker_id":"shared-worker","task_queue":"test","runtime":"php",
                    "supported_workflow_types":["echo"],"supported_activity_types":[]
                })
            )
            .await
            .0,
            StatusCode::CREATED
        );
    }
    let first = start_scoped(&app, "alpha", "namespace-first").await;
    let second = start_scoped(&app, "beta", "namespace-second").await;
    let body = json!({"worker_id":"shared-worker","task_queue":"test","timeout_seconds":0,"poll_request_id":"same-poll-id"});
    let first_task = scoped(
        &app,
        "alpha",
        "POST",
        "/api/worker/workflow-tasks/poll",
        body.clone(),
    )
    .await
    .1["task"]
        .clone();
    let second_task = scoped(
        &app,
        "beta",
        "POST",
        "/api/worker/workflow-tasks/poll",
        body,
    )
    .await
    .1["task"]
        .clone();
    assert_eq!(first_task["run_id"], first["run_id"]);
    assert_eq!(second_task["run_id"], second["run_id"]);
    assert_ne!(first_task["task_id"], second_task["task_id"]);
    let path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        first_task["task_id"].as_str().unwrap()
    );
    let commands = completion(
        &first_task,
        json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}]),
    );
    let refused = scoped(&app, "beta", "POST", &path, commands.clone()).await;
    assert_eq!(refused.0, StatusCode::NOT_FOUND, "{}", refused.1);
    assert_eq!(refused.1["reason"], "task_not_found");
    assert_eq!(refused.1["task_id"], first_task["task_id"]);
    assert_eq!(
        refused.1["workflow_task_attempt"],
        first_task["workflow_task_attempt"]
    );
    assert_eq!(refused.1["error"], "Workflow task not found.");
    assert_eq!(refused.1["protocol_version"], "1.20");
    let history_path = format!(
        "/api/workflows/namespace-first/runs/{}/history",
        first["run_id"].as_str().unwrap()
    );
    let before = scoped(&app, "alpha", "GET", &history_path, Value::Null).await;
    let unsupported = scoped(
        &app,
        "alpha",
        "POST",
        &path,
        completion(
            &first_task,
            json!([{"type":"start_timer","delay_seconds":0}]),
        ),
    )
    .await;
    assert_eq!(unsupported.0, StatusCode::UNPROCESSABLE_ENTITY);
    assert_eq!(
        unsupported.1["reason"],
        "development_namespace_command_unqualified"
    );
    assert_eq!(
        scoped(&app, "alpha", "GET", &history_path, Value::Null).await,
        before
    );
    for uri in ["/api/workflows", "/api/schedules/unqualified"] {
        let unavailable = scoped(&app, "alpha", "GET", uri, Value::Null).await;
        assert_eq!(unavailable.0, StatusCode::UNPROCESSABLE_ENTITY);
        assert_eq!(
            unavailable.1["reason"],
            "development_namespace_operation_unqualified"
        );
    }
    assert_eq!(
        scoped(&app, "alpha", "POST", &path, commands).await.0,
        StatusCode::OK
    );
    assert_eq!(
        scoped(
            &app,
            "beta",
            "GET",
            "/api/workflows/namespace-second",
            Value::Null
        )
        .await
        .1["status"],
        "pending"
    );
    assert_eq!(
        scoped(
            &app,
            "alpha",
            "DELETE",
            "/api/worker/registrations/shared-worker",
            Value::Null
        )
        .await
        .0,
        StatusCode::OK
    );
    assert_eq!(
        scoped(
            &app,
            "beta",
            "POST",
            "/api/worker/heartbeat",
            json!({"worker_id":"shared-worker"})
        )
        .await
        .0,
        StatusCode::OK
    );
    let heartbeat = scoped(&app,"beta","POST",
        &format!("/api/worker/workflow-tasks/{}/heartbeat",second_task["task_id"].as_str().unwrap()),
        json!({"lease_owner":second_task["lease_owner"],"workflow_task_attempt":second_task["workflow_task_attempt"]})).await;
    assert_eq!(heartbeat.0, StatusCode::OK, "{}", heartbeat.1);
    assert_eq!(
        heartbeat.1["renewed"], true,
        "other namespace deregistration preserves the actual original task lease"
    );
    let second_path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        second_task["task_id"].as_str().unwrap()
    );
    assert_eq!(scoped(&app, "beta", "POST", &second_path, completion(&second_task,
        json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}]))).await.0, StatusCode::OK);
    runtime.close().await;
    database.remove().await;
}
