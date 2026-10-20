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
