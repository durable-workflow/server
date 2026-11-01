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
                "workflow_command_contracts":{"echo":{"signals":["data"],"signal_contracts":[{
                    "name":"data","parameters":[{"name":"value","position":0,"type":"int",
                        "required":true,"variadic":false,"default_available":false,"allows_null":false}]}]}}
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
        assert_eq!(
            before.1["events"][1]["payload"]["declared_signal_contracts"][0]["name"],
            "data"
        );
        assert_eq!(
            before.1["events"][1]["payload"]["declared_signal_contracts"][0]["parameters"][0]["type"],
            "int"
        );
        let timed = scoped(&app,namespaces[index],"POST",&format!("/api/worker/workflow-tasks/{}/complete",task["task_id"].as_str().unwrap()),
            completion(&task,json!([{"type":"open_condition_wait","condition_key":"data:0","condition_definition_fingerprint":fingerprint,"timeout_seconds":-1}]))).await;
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
            // PHP projects only its PROJECTED_FIELDS into the envelope.
            assert_eq!(accepted.1["resolved_run_id"], starts[index]["run_id"]);
            assert!(accepted.1["control_plane"].get("resolved_run_id").is_none());
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
        let inactive = scoped(
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
        assert_eq!(inactive.0, StatusCode::CONFLICT);
        assert_eq!(inactive.1["reason"], "run_not_active");
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
    assert!(poll(&app, "shared-query-worker", "query").await.is_null());
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

pub(super) async fn scoped(
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

pub(super) async fn create(app: &Router, name: &str) {
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

pub(super) async fn start_scoped(app: &Router, namespace: &str, workflow: &str) -> Value {
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
        ("POST", format!("{path}/terminate"), "instance_not_found"),
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
    // A refused completion in the namespace changes nothing. (Published PHP
    // serves every workflow command in a named namespace: `named_namespace_commands`.)
    let unsupported = scoped(
        &app,
        "alpha",
        "POST",
        &path,
        completion(
            &first_task,
            json!([{"type":"record_side_effect","result":envelope(Payload::Long(1))},
                {"type":"complete_workflow","result":envelope(Payload::Null)},{"type":"fail_workflow","message":"x"}]),
        ),
    )
    .await;
    assert_eq!(unsupported.0, StatusCode::CONFLICT, "{}", unsupported.1);
    assert_eq!(unsupported.1["reason"], "invalid_commands", "{}", unsupported.1);
    assert_eq!(
        scoped(&app, "alpha", "GET", &history_path, Value::Null).await,
        before
    );
    // The workflow list is scoped to the request's namespace.
    let listed = scoped(&app, "alpha", "GET", "/api/workflows", Value::Null).await;
    assert_eq!(listed.0, StatusCode::OK, "{}", listed.1);
    let ids: Vec<_> = listed.1["workflows"]
        .as_array()
        .unwrap()
        .iter()
        .map(|run| run["workflow_id"].clone())
        .collect();
    assert_eq!(ids, vec![json!("namespace-first")], "{}", listed.1);
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

#[tokio::test]
async fn named_namespace_workflow_task_failure_retries_only_in_its_own_namespace() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    for name in ["alpha", "beta"] {
        create(&app, name).await;
        let (status, body) = scoped(&app, name, "POST", "/api/worker/register", json!({
            "worker_id":"shared-worker","task_queue":"test","runtime":"php",
            "supported_workflow_types":["echo"],"supported_activity_types":[]})).await;
        assert_eq!(status, StatusCode::CREATED, "{body}");
    }
    let run = start_scoped(&app, "alpha", "retry-alpha").await;
    let poll = json!({"worker_id":"shared-worker","task_queue":"test","timeout_seconds":0});
    let task = scoped(&app, "alpha", "POST", "/api/worker/workflow-tasks/poll", poll.clone()).await.1["task"].clone();
    let path = format!("/api/worker/workflow-tasks/{}/fail", task["task_id"].as_str().unwrap());
    let body = json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],
        "failure":{"message":"named failure","type":"RuntimeException"}});
    let refused = scoped(&app, "beta", "POST", &path, body.clone()).await;
    assert_eq!(refused.0, StatusCode::NOT_FOUND, "{}", refused.1);
    assert_eq!(refused.1["reason"], "task_not_found");

    let (status, failed) = scoped(&app, "alpha", "POST", &path, body).await;
    assert_eq!(status, StatusCode::OK, "{failed}");
    assert_eq!([&failed["outcome"], &failed["recorded"]], [&json!("failed"), &json!(true)]);
    let foreign = scoped(&app, "beta", "POST", "/api/worker/workflow-tasks/poll", poll.clone()).await;
    assert_eq!(foreign.0, StatusCode::OK, "{}", foreign.1);
    assert!(foreign.1["task"].is_null(), "{}", foreign.1);
    let retry = scoped(&app, "alpha", "POST", "/api/worker/workflow-tasks/poll", poll).await.1["task"].clone();
    assert_eq!(retry["task_id"], failed["next_task_id"], "{retry}");
    assert_eq!([&retry["run_id"], &retry["workflow_task_attempt"]], [&run["run_id"], &json!(2)]);
}

#[tokio::test]
async fn namespace_updates_follow_published_retention_rules() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    create(&app, "updated").await;
    let update = |body: Value| {
        let app = app.clone();
        async move { scoped(&app, "default", "PUT", "/api/namespaces/UPDATED", body).await }
    };
    let (status, body) = update(json!({"description":"changed λ","retention_days":60})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["name"], "updated");
    assert_eq!(body["description"], "changed λ");
    assert_eq!(body["retention_mode"], "bounded");
    assert_eq!(body["retention_days"], 60);
    // A null description keeps the stored one.
    let (status, body) = update(json!({"description":null,"retention_mode":"forever"})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["description"], "changed λ");
    assert_eq!(body["retention_mode"], "forever");
    assert_eq!(body["retention_days"], Value::Null);
    for refused in [
        json!({"retention_mode":"bounded"}),
        json!({"retention_mode":"forever","retention_days":7}),
        json!({"retention_mode":"bounded","retention_days":null}),
        json!({"retention_days":366}),
        json!({"retention_mode":"sometimes"}),
        json!({"description":"λ".repeat(1001)}),
    ] {
        let (status, body) = update(refused.clone()).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{refused} {body}");
    }
    let (status, body) = update(json!({"retention_mode":"bounded","retention_days":14})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["retention_days"], 14);
    let (status, body) = scoped(&app, "default", "PUT", "/api/namespaces/missing", json!({})).await;
    assert_eq!(status, StatusCode::NOT_FOUND);
    assert_eq!(body["reason"], "namespace_not_found");
    assert_eq!(body["message"], "Namespace [missing] not found.");
    let (_, described) = scoped(
        &app,
        "default",
        "GET",
        "/api/namespaces/updated",
        Value::Null,
    )
    .await;
    assert_eq!(described["retention_days"], 14);
    assert_eq!(described["description"], "changed λ");
    runtime.close().await;
    database.remove().await;
}

// Waterline's run detail lists the instance's runs and reads the debug view
// and history export. In a named namespace they serve that namespace's run,
// and another namespace gets 404 for the globally unique workflow id.
#[tokio::test]
async fn named_namespaces_serve_the_run_reads_waterline_uses() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    for name in ["alpha", "beta"] {
        create(&app, name).await;
    }
    let started = start_scoped(&app, "alpha", "namespace-reads").await;
    let run = started["run_id"].as_str().unwrap();
    let reads = [
        "/api/workflows/namespace-reads/runs".to_owned(),
        "/api/workflows/namespace-reads/debug".to_owned(),
        format!("/api/workflows/namespace-reads/runs/{run}/debug"),
        format!("/api/workflows/namespace-reads/runs/{run}/history/export"),
    ];
    for uri in &reads {
        let (status, body) = scoped(&app, "alpha", "GET", uri, Value::Null).await;
        assert_eq!(status, StatusCode::OK, "{uri}: {body}");
        let (status, foreign) = scoped(&app, "beta", "GET", uri, Value::Null).await;
        assert_eq!(status, StatusCode::NOT_FOUND, "{uri}: {foreign}");
    }
    let (_, runs) = scoped(&app, "alpha", "GET", &reads[0], Value::Null).await;
    assert_eq!(runs["runs"][0]["run_id"], started["run_id"], "{runs}");
    let (_, debug) = scoped(&app, "alpha", "GET", &reads[2], Value::Null).await;
    assert_eq!(debug["run_id"], started["run_id"], "{debug}");
    runtime.close().await;
    database.remove().await;
}

// A child lives in its parent's namespace, as published PHP's
// ChildWorkflowNamespaceProjection places it, and its result resumes the
// parent there.
#[tokio::test]
async fn a_named_namespace_child_runs_and_resolves_in_its_parents_namespace() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    create(&app, "alpha").await;
    let registered = scoped(&app, "alpha", "POST", "/api/worker/register", json!({
        "worker_id":"family","task_queue":"test","runtime":"php",
        "supported_workflow_types":["echo","child"],"supported_activity_types":[]})).await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    let parent = start_scoped(&app, "alpha", "named-parent").await;
    let poll = || async {
        let (status, body) = scoped(&app, "alpha", "POST", "/api/worker/workflow-tasks/poll",
            json!({"worker_id":"family","task_queue":"test","timeout_seconds":0})).await;
        assert_eq!(status, StatusCode::OK, "{body}");
        assert!(body["task"]["task_id"].is_string(), "{body}");
        body["task"].clone()
    };
    let finish = |task: Value, commands: Value| {
        let app = app.clone();
        async move {
            let path = format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap());
            let (status, body) = scoped(&app, "alpha", "POST", &path, completion(&task, commands)).await;
            assert_eq!(status, StatusCode::OK, "{body}");
        }
    };
    let task = poll().await;
    assert_eq!(task["run_id"], parent["run_id"]);
    finish(task, json!([child_command(1)])).await;

    let child = poll().await;
    assert_ne!(child["run_id"], parent["run_id"]);
    let child_path = format!("/api/workflows/{}", child["workflow_id"].as_str().unwrap());
    let (status, described) = scoped(&app, "alpha", "GET", &child_path, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{described}");
    assert_eq!(described["namespace"], "alpha", "{described}");
    assert_eq!(request(&app, "GET", &child_path, Value::Null).await.0, StatusCode::NOT_FOUND);
    finish(child, json!([{"type":"complete_workflow","result":envelope(Payload::Long(7))}])).await;

    let resumed = poll().await;
    assert_eq!(resumed["run_id"], parent["run_id"]);
    finish(resumed, json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await;
    let (_, described) = scoped(&app, "alpha", "GET", "/api/workflows/named-parent", Value::Null).await;
    assert_eq!(described["status"], "completed", "{described}");
}

// `DELETE /api/namespaces/{namespace}` follows PHP's NamespaceController::destroy:
// the namespace's rows go in one transaction, the non-zero counts are reported,
// other namespaces keep theirs, and a missing namespace answers 404.
#[tokio::test]
async fn namespace_delete_removes_only_what_the_namespace_owns() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    create(&app, "doomed").await;
    let (status, body) = scoped(&app, "doomed", "POST", "/api/search-attributes",
        json!({"name":"CustomerTier","type":"keyword"})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    start_scoped(&app, "doomed", "namespace-delete-run").await;
    start_scoped(&app, "default", "namespace-delete-peer").await;
    let (status, body) = scoped(&app, "default", "DELETE", "/api/namespaces/DOOMED", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    // What published PHP 2.5.14 reports for one started run and one search
    // attribute definition.
    assert_eq!(body, json!({"name":"doomed","status":"deleted","deleted":{
        "workflow_commands":1,"workflow_history_events":2,"workflow_tasks":1,
        "search_attribute_definitions":1,"workflow_run_summaries":1,"workflow_runs":1,"workflow_instances":1}}));
    for (method, uri) in [("GET", "/api/namespaces/doomed"), ("DELETE", "/api/namespaces/doomed")] {
        let (status, body) = scoped(&app, "default", method, uri, Value::Null).await;
        assert_eq!(status, StatusCode::NOT_FOUND, "{method} {uri}: {body}");
        assert_eq!(body, json!({"message":"Namespace [doomed] not found.","reason":"namespace_not_found","namespace":"doomed"}));
    }
    let (status, body) = scoped(&app, "default", "GET", "/api/workflows/namespace-delete-peer", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "another namespace keeps its run: {body}");
    // The deleted run's workflow id is free again.
    let restarted = start_scoped(&app, "default", "namespace-delete-run").await;
    assert_eq!(restarted["outcome"], "started_new", "{restarted}");
    runtime.close().await;
    database.remove().await;
}

// A namespace holding a reference native cannot settle (here PHP's stored
// format) refuses, with PHP's answer for unavailable storage, and nothing is
// deleted. Native references are settled (`external_payloads::namespace_delete`).
#[tokio::test]
async fn namespace_delete_refuses_while_unsettled_external_payloads_remain() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    create(&app, "held").await;
    start_scoped(&app, "held", "namespace-delete-held").await;
    database
        .execute("UPDATE workflow_runs SET output='{\"reference\":\"dw-external-payload:v1:held\"}' WHERE namespace='held'")
        .await;
    let (status, body) = scoped(&app, "default", "DELETE", "/api/namespaces/held", Value::Null).await;
    assert_eq!(status, StatusCode::SERVICE_UNAVAILABLE, "{body}");
    assert_eq!(body["reason"], "external_payload_storage_driver_unavailable");
    assert_eq!(body["namespace"], "held");
    let (status, body) = scoped(&app, "default", "GET", "/api/namespaces/held", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "the refused namespace remains: {body}");
    // The planted reference makes describe resolve an external payload, so
    // the listing shows the run is still there.
    let (status, body) = scoped(&app, "held", "GET", "/api/workflows", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert!(body.to_string().contains("namespace-delete-held"), "its run remains: {body}");
    runtime.close().await;
    database.remove().await;
}

async fn claim_named(app: &Router, namespace: &str) -> Value {
    let (status, body) = scoped(app, namespace, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"named-worker","task_queue":"test","timeout_seconds":0})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body["task"].clone()
}

async fn complete_named(app: &Router, namespace: &str, task: &Value, commands: Value) -> (StatusCode, Value) {
    let path = format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap());
    scoped(app, namespace, "POST", &path, completion(task, commands)).await
}

// Published PHP serves every workflow command and operation in a named
// namespace: version markers, side effects, updates and their completion,
// repair, cooperative cancellation requests and continue-as-new
// (`named_namespace_commands`).
#[tokio::test]
async fn named_namespace_commands_like_php() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    create(&app, "gamma").await;
    let (status, body) = scoped(&app, "gamma", "POST", "/api/worker/register", json!({"worker_id":"named-worker",
        "task_queue":"test","runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":[],
        "capabilities":["workflow_updates","cooperative_cancellation"],"workflow_command_contracts":{"echo":{
            "signals":[],"queries":[],"updates":["set"],"update_validators":[],"update_contracts":[{"name":"set","parameters":[
                {"name":"value","type":"int","required":true,"variadic":false,"default_available":false,"allows_null":false}]}]}}})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");

    let started = start_scoped(&app, "gamma", "named-commands").await;
    let task = claim_named(&app, "gamma").await;
    let (status, body) = complete_named(&app, "gamma", &task, json!([
        {"type":"record_version_marker","change_id":"c","version":1,"min_supported":1,"max_supported":1},
        {"type":"record_side_effect","result":envelope(Payload::Long(1))},
        {"type":"start_timer","delay_seconds":3600}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");

    let (status, body) = scoped(&app, "gamma", "POST", "/api/workflows/named-commands/update/set",
        json!({"input":[1],"wait_for":"accepted"})).await;
    assert!(status.is_success(), "{status} {body}");
    let update = body["update_id"].clone();
    let task = claim_named(&app, "gamma").await;
    assert_eq!(task["workflow_update_id"], update, "{task}");
    let (status, body) = complete_named(&app, "gamma", &task,
        json!([{"type":"complete_update","update_id":update,"result":envelope(Payload::Long(2))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");

    let (status, body) = scoped(&app, "gamma", "POST", "/api/workflows/named-commands/repair", json!({})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (status, body) = scoped(&app, "gamma", "POST", "/api/workflows/named-commands/request-cancellation",
        json!({"reason":"stop"})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    let history_path = format!("/api/workflows/named-commands/runs/{}/history", started["run_id"].as_str().unwrap());
    let (_, history) = scoped(&app, "gamma", "GET", &history_path, Value::Null).await;
    let kinds: Vec<_> = history["events"].as_array().unwrap().iter().map(|event| event["event_type"].clone()).collect();
    for kind in ["VersionMarkerRecorded", "SideEffectRecorded", "UpdateCompleted"] {
        assert!(kinds.contains(&json!(kind)), "{kind}: {history}");
    }

    let continued = start_scoped(&app, "gamma", "named-continue").await;
    let mut task = claim_named(&app, "gamma").await;
    while task["workflow_id"] != "named-continue" {
        task = claim_named(&app, "gamma").await;
    }
    let (status, body) = complete_named(&app, "gamma", &task, json!([{"type":"continue_as_new"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["run_status"], "completed", "{body}");
    let (_, described) = scoped(&app, "gamma", "GET", "/api/workflows/named-continue", Value::Null).await;
    assert_ne!(described["run_id"], continued["run_id"], "{described}");
    runtime.close().await;
    database.remove().await;
}
