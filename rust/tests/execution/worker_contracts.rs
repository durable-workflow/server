use super::*;

fn definition() -> Value {
    json!({"worker_id":"contract-worker","task_queue":"test","runtime":"python",
        "supported_workflow_types":["echo"],"supported_activity_types":[]})
}

async fn register_contract(app: &Router, declaration: Option<Value>) -> (StatusCode, Value) {
    let mut body = definition();
    if let Some(declaration) = declaration {
        body["workflow_command_contracts"] = declaration;
    }
    request(app, "POST", "/api/worker/register", body).await
}

async fn snapshot(app: &Router, started: &Value) -> Value {
    let history = run_history(app, &started["workflow_id"], &started["run_id"]).await;
    history
        .as_array()
        .unwrap()
        .iter()
        .find(|event| event["event_type"] == "WorkflowStarted")
        .unwrap()["payload"]
        .clone()
}

fn assert_empty(payload: &Value) {
    for field in [
        "declared_queries",
        "declared_signals",
        "declared_updates",
        "declared_query_contracts",
        "declared_signal_contracts",
        "declared_update_contracts",
    ] {
        assert_eq!(payload.get(field), Some(&json!([])), "{field}: {payload}");
    }
}

#[tokio::test]
async fn explicit_empty_declarations_survive_cold_reads_and_later_registration() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let mut originals = Vec::new();
    for (index, declaration) in [
        json!({}),
        json!([]),
        json!({"queries":[],"signals":[],
        "updates":[],"query_contracts":[],"signal_contracts":[],"update_contracts":[]}),
    ]
    .into_iter()
    .enumerate()
    {
        let registered = register_contract(&app, Some(json!({"echo":declaration}))).await;
        assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
        let started = start(&app, &format!("empty-contract-{index}")).await;
        let before = snapshot(&app, &started).await;
        assert_empty(&before);
        originals.push((started, before));
    }
    assert_eq!(
        register_contract(
            &app,
            Some(json!({"echo":{"query_contracts":[
        {"name":"state","parameters":[]}],"signals":["z","a","z"]}}))
        )
        .await
        .0,
        StatusCode::CREATED
    );
    let later = start(&app, "named-contract").await;
    let named = snapshot(&app, &later).await;
    assert_eq!(named["declared_queries"], json!(["state"]));
    assert_eq!(named["declared_signals"], json!(["a", "z"]));
    assert_eq!(register_contract(&app, None).await.0, StatusCode::CREATED);
    let omitted = start(&app, "reregister-omitted").await;
    assert_eq!(
        snapshot(&app, &omitted).await["declared_queries"],
        json!(["state"])
    );
    drop(app);
    drop(runtime);
    let cold = router(database.open().await.unwrap());
    for (started, before) in originals {
        assert_eq!(snapshot(&cold, &started).await, before);
    }
    drop(cold);
    database.remove().await;
}

#[tokio::test]
async fn legacy_missing_entries_remain_unknown_instead_of_becoming_empty() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    for (index, declaration) in [
        None,
        Some(Value::Null),
        Some(json!({})),
        Some(json!([])),
        Some(json!({"other":{}})),
    ]
    .into_iter()
    .enumerate()
    {
        let registered = register_contract(&app, declaration).await;
        assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
        let original = start(&app, &format!("legacy-contract-{index}")).await;
        let before = snapshot(&app, &original).await;
        assert!(before.get("declared_queries").is_none(), "{before}");
        assert_eq!(
            register_contract(&app, Some(json!({"echo":{}}))).await.0,
            StatusCode::CREATED
        );
        assert_eq!(snapshot(&app, &original).await, before);
        assert_eq!(
            register_contract(&app, Some(json!({}))).await.0,
            StatusCode::CREATED
        );
    }
    drop(app);
    database.remove().await;
}

#[tokio::test]
async fn invalid_declarations_refuse_before_registration_rotation_or_lease_release() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let accepted = register_contract(&app, Some(json!({"echo":{}}))).await;
    assert_eq!(accepted.0, StatusCode::CREATED);
    let started = start(&app, "contract-lease").await;
    let task = poll(&app, "contract-worker", "workflow").await;
    let before = snapshot(&app, &started).await;
    for declaration in [
        Value::Null,
        json!("bad"),
        json!({"queries":null}),
        json!({"queries":"state"}),
        json!({"queries":[17]}),
        json!({"signals":[" "]}),
        json!({"updates":{"key":"set"}}),
        json!({"update_validators":null}),
        json!({"query_contracts":"bad"}),
        json!({"signal_contracts":[null]}),
        json!({"query_contracts":[{}]}),
        json!({"update_contracts":[{"name":17}]}),
    ] {
        let refused = register_contract(&app, Some(json!({"echo":declaration}))).await;
        assert_eq!(refused.0, StatusCode::UNPROCESSABLE_ENTITY, "{}", refused.1);
        assert_eq!(snapshot(&app, &started).await, before);
    }
    let completed = finish_task(
        &app,
        &task,
        json!([{"type":"complete_workflow",
        "result":envelope(Payload::Long(9007199254740993))}]),
    )
    .await;
    assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
    assert_eq!(completed.1["recorded"], true);
    let retired = request_protocol(
        &app,
        "POST",
        "/api/worker/registrations/contract-worker/deregister",
        json!({"registration_token":accepted.1["registration_token"]}),
        "1.20",
    )
    .await;
    assert_eq!(retired.0, StatusCode::OK, "{}", retired.1);
    assert_eq!(retired.1["recovered_workflow_task_count"], 0);
    drop(app);
    database.remove().await;
}

async fn signal(app: &Router, workflow: &Value, name: &str, body: Value) -> (StatusCode, Value) {
    let path = format!("/api/workflows/{}/signal/{name}", workflow["workflow_id"].as_str().unwrap());
    request(app, "POST", &path, body).await
}

#[tokio::test]
async fn signals_follow_php_admission_with_and_without_a_recorded_contract() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let input = json!({"input":envelope(Payload::Array(vec![Payload::String("x".into())]))});
    // No worker has declared a contract: PHP admits every signal.
    let unregistered = start(&app, "before-any-worker").await;
    for body in [input.clone(), json!({}), json!({"input":null})] {
        let admitted = signal(&app, &unregistered, "anything", body).await;
        assert_eq!((admitted.0, admitted.1["outcome"].clone()), (StatusCode::ACCEPTED, json!("signal_received")), "{}", admitted.1);
        assert_eq!(admitted.1["workflow_type"], "echo");
    }
    let reserved = signal(&app, &unregistered, "__durable_workflow_message_stream", input.clone()).await;
    assert_eq!(reserved.0, StatusCode::UNPROCESSABLE_ENTITY);
    assert_eq!((reserved.1["reason"].clone(), reserved.1["accepted"].clone()), (json!("runtime_reserved_signal"), json!(false)));
    assert!(reserved.1.get("command_id").is_none(), "{}", reserved.1);
    let history = run_history(&app, &unregistered["workflow_id"], &unregistered["run_id"]).await;
    let received = history.as_array().unwrap().iter().filter(|event| event["event_type"] == "SignalReceived").count();
    assert_eq!(received, 3);
    // A registered contract is complete and durable, as PHP records it.
    let registered = register_contract(&app, Some(json!({"echo":{"signals":["a"]}}))).await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    let declared = start(&app, "after-a-worker").await;
    let started = snapshot(&app, &declared).await;
    assert_eq!(
        (started["declared_entry_method"].clone(), started["declared_entry_mode"].clone(), started["declared_entry_declaring_class"].clone()),
        (json!("handle"), json!("canonical"), json!("external:echo"))
    );
    let unknown = signal(&app, &declared, "b", input.clone()).await;
    assert_eq!((unknown.0, unknown.1["reason"].clone()), (StatusCode::NOT_FOUND, json!("unknown_signal")), "{}", unknown.1);
    // A declared signal without a parameter contract takes its arguments as given.
    let untyped = signal(&app, &declared, "a", input).await;
    assert_eq!(untyped.0, StatusCode::ACCEPTED, "{}", untyped.1);
    drop(app);
    database.remove().await;
}

async fn observed_contract(app: &Router, started: &Value) -> Value {
    let path = format!("/api/workflows/{}/runs/{}/observation",
        started["workflow_id"].as_str().unwrap(), started["run_id"].as_str().unwrap());
    let (status, body) = request(app, "GET", &path, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body["command_contract"].clone()
}

// Server #436, native part: numeric-string names keep their exact spelling
// (PHP once turned array keys like "17" into integers), the start records the
// contract of the selected build's worker only, and bounded observation tells
// an explicitly empty declaration from a missing one.
#[tokio::test]
async fn numeric_names_selected_builds_and_bounded_observation_keep_the_declared_contract() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let numeric = ["0", "17", "017"];
    let mut body = json!({"worker_id":"numeric-worker","task_queue":"test","runtime":"python",
        "supported_workflow_types":numeric,"supported_activity_types":[],"workflow_command_contracts":{}});
    for name in numeric {
        body["workflow_command_contracts"][name] = json!({"query_contracts":[{"name":name,"parameters":[]}],"signals":[name]});
    }
    let (status, receipt) = request(&app, "POST", "/api/worker/register", body).await;
    assert_eq!(status, StatusCode::CREATED, "{receipt}");
    for name in numeric {
        let (status, started) = request(&app, "POST", "/api/workflows", json!({"workflow_id":format!("numeric-{name}"),
            "workflow_type":name,"task_queue":"test","input":envelope(Payload::Array(vec![]))})).await;
        assert_eq!(status, StatusCode::CREATED, "{started}");
        let payload = snapshot(&app, &started).await;
        assert_eq!(payload["workflow_type"], json!(name), "{payload}");
        assert_eq!(payload["declared_queries"], json!([name]), "{payload}");
        assert_eq!(payload["declared_signals"], json!([name]), "{payload}");
        let observed = observed_contract(&app, &started).await;
        assert_eq!(observed["source"], "durable_history", "{observed}");
        assert_eq!(observed["query_targets"].as_array().map(Vec::len), Some(1), "{observed}");
    }

    // Two builds declare different contracts for one type; the promoted
    // build's worker is the only source of the start's snapshot.
    for (worker, build, query) in [("build-a", "a", "from_a"), ("build-b", "b", "from_b")] {
        let (status, receipt) = request(&app, "POST", "/api/worker/register", json!({"worker_id":worker,"task_queue":"test",
            "runtime":"python","build_id":build,"supported_workflow_types":["echo"],"supported_activity_types":[],
            "workflow_command_contracts":{"echo":{"queries":[query]}}})).await;
        assert_eq!(status, StatusCode::CREATED, "{receipt}");
    }
    let (status, promoted) = request(&app, "POST", "/api/task-queues/test/build-ids/promote", json!({"build_id":"b"})).await;
    assert_eq!(status, StatusCode::OK, "{promoted}");
    let routed = start(&app, "routed-contract").await;
    let payload = snapshot(&app, &routed).await;
    assert_eq!(payload["compatibility"], "b", "{payload}");
    assert_eq!(payload["declared_queries"], json!(["from_b"]), "{payload}");

    // Explicit empty is durable history; a missing declaration is unavailable.
    let (status, receipt) = request(&app, "POST", "/api/worker/register", json!({"worker_id":"build-b","task_queue":"test",
        "runtime":"python","build_id":"b","supported_workflow_types":["echo"],"supported_activity_types":[],
        "workflow_command_contracts":{"echo":{}}})).await;
    assert_eq!(status, StatusCode::CREATED, "{receipt}");
    let empty = start(&app, "observed-empty").await;
    assert_empty(&snapshot(&app, &empty).await);
    let observed = observed_contract(&app, &empty).await;
    assert_eq!((observed["source"].clone(), observed["backfill_needed"].clone()), (json!("durable_history"), json!(false)), "{observed}");
    // A build whose worker never declared a contract leaves it unavailable.
    let (status, receipt) = request(&app, "POST", "/api/worker/register", json!({"worker_id":"build-c","task_queue":"test",
        "runtime":"python","build_id":"c","supported_workflow_types":["echo"],"supported_activity_types":[]})).await;
    assert_eq!(status, StatusCode::CREATED, "{receipt}");
    let (status, promoted) = request(&app, "POST", "/api/task-queues/test/build-ids/promote", json!({"build_id":"c"})).await;
    assert_eq!(status, StatusCode::OK, "{promoted}");
    let missing = start(&app, "observed-missing").await;
    assert!(snapshot(&app, &missing).await.get("declared_queries").is_none());
    let observed = observed_contract(&app, &missing).await;
    assert_eq!((observed["source"].clone(), observed["backfill_needed"].clone()), (json!("unavailable"), json!(true)), "{observed}");
    runtime.close().await;
    database.remove().await;
}
