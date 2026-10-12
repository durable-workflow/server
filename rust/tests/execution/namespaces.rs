use super::*;

#[tokio::test]
async fn named_signal_waits_keep_both_original_runs_and_foreign_polls_isolated() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let namespaces = ["alpha", "beta"];
    let workflows = ["namespace-signal-first", "namespace-signal-second"];
    let values = [9007199254740993, 9007199254740995];
    let fingerprint = format!("sha256:{}", "a".repeat(64));
    let mut starts = Vec::new();
    for index in 0..2 {
        create(&app, namespaces[index]).await;
        let registration = scoped(
            &app,
            namespaces[index],
            "POST",
            "/api/worker/register",
            json!({
                "worker_id":"signal-worker","task_queue":"test","runtime":"php",
                "supported_workflow_types":["echo"],"supported_activity_types":[],
                "workflow_command_contracts":{"echo":{"signals":["data"]}}
            }),
        )
        .await;
        assert_eq!(registration.0, StatusCode::CREATED, "{}", registration.1);
        starts.push(start_scoped(&app, namespaces[index], workflows[index]).await);
        let task = scoped(
            &app,
            namespaces[index],
            "POST",
            "/api/worker/workflow-tasks/poll",
            json!({
                "worker_id":"signal-worker","task_queue":"test","timeout_seconds":0,
                "poll_request_id":"original-signal-poll"
            }),
        )
        .await
        .1["task"]
            .clone();
        assert_eq!(task["run_id"], starts[index]["run_id"]);
        let history_path = format!(
            "/api/workflows/{}/runs/{}/history",
            workflows[index],
            starts[index]["run_id"].as_str().unwrap()
        );
        let before = scoped(&app, namespaces[index], "GET", &history_path, Value::Null).await;
        let timed = scoped(&app,namespaces[index],"POST",&format!("/api/worker/workflow-tasks/{}/complete",task["task_id"].as_str().unwrap()),
            completion(&task,json!([{"type":"open_condition_wait","condition_key":"data:0","condition_definition_fingerprint":fingerprint,"timeout_seconds":1}]))).await;
        assert_eq!(timed.0, StatusCode::UNPROCESSABLE_ENTITY);
        assert_eq!(
            scoped(&app, namespaces[index], "GET", &history_path, Value::Null).await,
            before
        );
        let opening = scoped(&app, namespaces[index], "POST", &format!("/api/worker/workflow-tasks/{}/complete",task["task_id"].as_str().unwrap()),
            completion(&task,json!([{"type":"open_condition_wait","condition_key":"data:0","condition_definition_fingerprint":fingerprint}]))).await;
        assert_eq!(opening.0, StatusCode::OK, "{}", opening.1);
    }
    let mut command_ids = Vec::new();
    for signal in 0..2 {
        for index in 0..2 {
            let history_path = format!(
                "/api/workflows/{}/runs/{}/history",
                workflows[index],
                starts[index]["run_id"].as_str().unwrap()
            );
            let before = scoped(&app, namespaces[index], "GET", &history_path, Value::Null).await;
            let input = envelope(Payload::Array(vec![Payload::Long(values[index])]));
            let signal_path = format!("/api/workflows/{}/signal/data", workflows[index]);
            let foreign = scoped(
                &app,
                namespaces[1 - index],
                "POST",
                &signal_path,
                json!({"input":input}),
            )
            .await;
            assert_eq!(foreign.0, StatusCode::NOT_FOUND, "{}", foreign.1);
            assert_eq!(foreign.1["reason"], "instance_not_found");
            assert_eq!(foreign.1["control_plane"]["operation"], "signal");
            assert_eq!(foreign.1["control_plane"]["workflow_id"], workflows[index]);
            assert_eq!(foreign.1["control_plane"]["operation_name"], "data");
            assert_eq!(
                foreign.1["control_plane"]["operation_name_field"],
                "signal_name"
            );
            assert_eq!(foreign.1["message"], "Workflow not found.");
            assert_eq!(
                scoped(&app, namespaces[index], "GET", &history_path, Value::Null).await,
                before
            );
            let accepted = scoped(
                &app,
                namespaces[index],
                "POST",
                &signal_path,
                json!({"input":input}),
            )
            .await;
            assert_eq!(accepted.0, StatusCode::ACCEPTED, "{}", accepted.1);
            assert_eq!(accepted.1["run_id"], starts[index]["run_id"]);
            assert_eq!(
                accepted.1["control_plane"]["command_id"],
                accepted.1["command_id"]
            );
            assert_eq!(
                accepted.1["control_plane"]["resolved_run_id"],
                starts[index]["run_id"]
            );
            assert_eq!(accepted.1["control_plane"]["operation_name"], "data");
            command_ids.push(accepted.1["command_id"].as_str().unwrap().to_owned());
            let ready = scoped(&app, namespaces[index], "GET", &history_path, Value::Null).await;
            let foreign_poll = scoped(
                &app,
                namespaces[1 - index],
                "POST",
                "/api/worker/workflow-tasks/poll",
                json!({
                    "worker_id":"signal-worker","task_queue":"test","timeout_seconds":0,
                    "poll_request_id":format!("foreign-{index}-{signal}")
                }),
            )
            .await;
            assert_eq!(foreign_poll.0, StatusCode::OK, "{}", foreign_poll.1);
            assert!(foreign_poll.1["task"].is_null());
            assert_eq!(
                scoped(&app, namespaces[index], "GET", &history_path, Value::Null).await,
                ready
            );
            let claimed = scoped(
                &app,
                namespaces[index],
                "POST",
                "/api/worker/workflow-tasks/poll",
                json!({
                    "worker_id":"signal-worker","task_queue":"test","timeout_seconds":0,
                    "poll_request_id":format!("correct-{index}-{signal}")
                }),
            )
            .await
            .1["task"]
                .clone();
            assert_eq!(claimed["run_id"], starts[index]["run_id"]);
            assert_eq!(claimed["workflow_task_attempt"], 1);
            let received: Vec<_> = claimed["history_events"]
                .as_array()
                .unwrap()
                .iter()
                .filter(|event| event["event_type"] == "SignalReceived")
                .collect();
            assert_eq!(received.len(), signal + 1);
            for event in received {
                assert_eq!(event["payload"]["workflow_instance_id"], workflows[index]);
                assert_eq!(event["payload"]["workflow_run_id"], starts[index]["run_id"]);
                assert_eq!(event["payload"]["arguments"], input);
            }
            let command = if signal == 0 {
                json!({"type":"open_condition_wait","condition_key":"data:1","condition_definition_fingerprint":fingerprint})
            } else {
                json!({"type":"complete_workflow","result":envelope(Payload::Long(values[index]))})
            };
            let outcome = scoped(
                &app,
                namespaces[index],
                "POST",
                &format!(
                    "/api/worker/workflow-tasks/{}/complete",
                    claimed["task_id"].as_str().unwrap()
                ),
                completion(&claimed, json!([command])),
            )
            .await;
            assert_eq!(outcome.0, StatusCode::OK, "{}", outcome.1);
        }
    }
    command_ids.sort();
    command_ids.dedup();
    assert_eq!(command_ids.len(), 4);
    for index in 0..2 {
        let described = scoped(
            &app,
            namespaces[index],
            "GET",
            &format!("/api/workflows/{}", workflows[index]),
            Value::Null,
        )
        .await;
        assert_eq!(described.0, StatusCode::OK);
        assert_eq!(described.1["status"], "completed");
        assert_eq!(described.1["run_id"], starts[index]["run_id"]);
        assert_eq!(described.1["namespace"], namespaces[index]);
        let unqualified = scoped(
            &app,
            namespaces[index],
            "POST",
            &format!(
                "/api/workflows/{}/runs/{}/signal/data",
                workflows[index],
                starts[index]["run_id"].as_str().unwrap()
            ),
            json!({"input":envelope(Payload::Array(vec![Payload::Long(1)]))}),
        )
        .await;
        assert_eq!(unqualified.0, StatusCode::UNPROCESSABLE_ENTITY);
        assert_eq!(
            unqualified.1["reason"],
            "development_namespace_operation_unqualified"
        );
    }
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn named_idle_query_poll_preserves_same_id_default_namespace_claim() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    create(&app, "alpha").await;
    query_worker(&app, "shared-query-worker").await;
    let started = start(&app, "default-query-peer").await;
    let task = poll(&app, "shared-query-worker", "workflow").await;
    let result = envelope(Payload::Long(9007199254740993));
    assert_eq!(
        request(
            &app,
            "POST",
            &format!(
                "/api/worker/workflow-tasks/{}/complete",
                task["task_id"].as_str().unwrap()
            ),
            completion(&task, json!([{"type":"complete_workflow","result":result}]))
        )
        .await
        .0,
        StatusCode::OK
    );
    let waiting = {
        let app = app.clone();
        tokio::spawn(async move {
            request(
                &app,
                "POST",
                &format!(
                    "/api/workflows/default-query-peer/runs/{}/query/state",
                    started["run_id"].as_str().unwrap()
                ),
                json!({"input":envelope(Payload::Array(vec![Payload::Long(9007199254740993)]))}),
            )
            .await
        })
    };
    let original = await_query(&app, "shared-query-worker").await;
    assert_eq!(original["namespace"], "default");
    let registered = scoped(&app,"alpha","POST","/api/worker/register",json!({
        "worker_id":"shared-query-worker","task_queue":"test","runtime":"php",
        "supported_workflow_types":["echo"],"supported_activity_types":[],"capabilities":["query_tasks"],
        "workflow_command_contracts":{"echo":{"queries":["state"]}}
    })).await;
    assert_eq!(registered.0, StatusCode::CREATED);
    let idle = scoped(
        &app,
        "alpha",
        "POST",
        "/api/worker/query-tasks/poll",
        json!({
            "worker_id":"shared-query-worker","task_queue":"test","timeout_seconds":0
        }),
    )
    .await;
    assert_eq!(idle.0, StatusCode::OK, "{}", idle.1);
    assert!(
        idle.1["task"].is_null(),
        "named poll must not replay another namespace's same-ID lease"
    );
    assert_eq!(poll(&app, "shared-query-worker", "query").await, original);
    assert_eq!(request(&app,"POST",
        &format!("/api/worker/query-tasks/{}/complete",original["query_task_id"].as_str().unwrap()),
        json!({"lease_owner":"shared-query-worker","query_task_attempt":original["query_task_attempt"],"result_envelope":result})).await.0,
        StatusCode::OK);
    let outcome = waiting.await.unwrap();
    assert_eq!(outcome.0, StatusCode::OK, "{}", outcome.1);
    assert_eq!(outcome.1["result_envelope"], result);
    runtime.close().await;
    database.remove().await;
}

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
