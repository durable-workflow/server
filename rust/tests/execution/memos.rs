use super::*;

#[tokio::test]
async fn memo_floor_and_unavailable_runtime_refuse_without_mutating_the_original() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let registration = json!({"worker_id":"memo-floor-worker", "task_queue":"test", "runtime":"php",
        "supported_workflow_types":["echo"], "supported_activity_types":[], "capabilities":["memo_upserts"]});
    let (status, refused) = request_protocol(
        &app,
        "POST",
        "/api/worker/register",
        registration.clone(),
        "1.13",
    )
    .await;
    assert_eq!(status, StatusCode::CONFLICT);
    assert_eq!(
        refused["reason"],
        "workflow_metadata_capability_protocol_mismatch"
    );
    assert_eq!(
        request_protocol(&app, "POST", "/api/worker/register", registration, "1.14")
            .await
            .0,
        StatusCode::CREATED
    );
    let original = start(&app, "memo-floor-original").await;
    let task = poll(&app, "memo-floor-worker", "workflow").await;
    let body = json!({"lease_owner":task["lease_owner"], "workflow_task_attempt":task["workflow_task_attempt"],
        "commands":[{"type":"upsert_memo", "entries":envelope(Payload::Map(std::collections::BTreeMap::from([
            ("valid".into(), Payload::Long(1))])))}]});
    let path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        task["task_id"].as_str().unwrap()
    );
    let history_path = format!(
        "/api/workflows/memo-floor-original/runs/{}/history",
        original["run_id"].as_str().unwrap()
    );
    let before = request(&app, "GET", &history_path, Value::Null).await;
    let (status, floor) = request_protocol(&app, "POST", &path, body.clone(), "1.13").await;
    assert_eq!(status, StatusCode::CONFLICT);
    assert_eq!(
        floor["reason"],
        "workflow_metadata_capability_protocol_mismatch"
    );
    let (status, unsupported) = request_protocol(&app, "POST", &path, body, "1.14").await;
    assert_eq!(status, StatusCode::CONFLICT);
    assert_eq!(unsupported["reason"], "workflow_memo_updates_unavailable");
    assert_eq!(unsupported["recorded"], false);
    assert_eq!(
        request(&app, "GET", &history_path, Value::Null).await,
        before
    );
    let (status, completed) = request(&app, "POST", &path, json!({
        "lease_owner":task["lease_owner"], "workflow_task_attempt":task["workflow_task_attempt"],
        "commands":[{"type":"complete_workflow", "result":envelope(Payload::Long(7))}]
    })).await;
    assert_eq!(status, StatusCode::OK, "{completed}");
    assert_eq!(completed["task_id"], task["task_id"]);
    assert_eq!(completed["recorded"], true);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn initial_memo_survives_cold_open_and_uses_the_same_sequence_portable_value() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let value = json!({"integer":9007199254740993_i64, "optional":null,
        "unicode":"memo λ", "false":false, "list":[], "map":{}, "double":7.0});
    let mut projected = value.clone();
    projected["map"] = json!({"$type":"map", "entries":[]});
    let (status, admitted) = request(
        &app,
        "POST",
        "/api/workflows",
        json!({
            "workflow_id":"memo-initial", "workflow_type":"echo", "task_queue":"test",
        "input":envelope(Payload::Array(vec![])), "memo":{"metadata":value, "deleted":null}
        }),
    )
    .await;
    assert_eq!(status, StatusCode::CREATED, "{admitted}");
    runtime.close().await;
    drop(app);
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let path = format!(
        "/api/workflows/memo-initial/runs/{}",
        admitted["run_id"].as_str().unwrap()
    );
    let (status, original) = request(&app, "GET", &path, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{original}");
    assert_eq!(original["memo"], json!({"metadata":projected}));
    assert_eq!(
        original["memo"]["metadata"]["integer"].as_i64(),
        Some(9007199254740993)
    );
    assert!(original["memo"]["metadata"]["double"].as_i64().is_none());
    let (status, history) = request(&app, "GET", &format!("{path}/history"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{history}");
    for event in history["events"].as_array().unwrap().iter().take(2) {
        assert_eq!(
            event["payload"]["memo"],
            json!({"metadata":value, "deleted":null})
        );
    }

    // Reproduce an older writer advancing the logical value without updating
    // the host's portable sequence. Stale Avro bytes must not win authority.
    database.execute("UPDATE workflow_memos SET value='\"legacy\"',portable_value_sequence=1 WHERE workflow_instance_id='memo-initial'").await;
    let (status, legacy) = request(&app, "GET", &path, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{legacy}");
    assert_eq!(legacy["memo"], json!({"metadata":"legacy"}));
    database.execute("UPDATE workflow_memos SET portable_value_sequence=upserted_at_sequence WHERE workflow_instance_id='memo-initial'").await;
    let (status, restored) = request(&app, "GET", &path, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{restored}");
    assert_eq!(restored["memo"], original["memo"]);
    assert_eq!(restored["run_id"], original["run_id"]);
    assert_eq!(restored["run_deadline_at"], original["run_deadline_at"]);
    assert_eq!(
        restored["execution_deadline_at"],
        original["execution_deadline_at"]
    );
    database.execute("UPDATE workflow_memos SET portable_value='null' WHERE workflow_instance_id='memo-initial'").await;
    let (status, null_portable) = request(&app, "GET", &path, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{null_portable}");
    assert_eq!(null_portable["memo"], json!({"metadata":"legacy"}));
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn invalid_initial_memo_is_refused_before_any_original_is_admitted() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let many: serde_json::Map<_, _> = (0..101).map(|n| (format!("key-{n}"), json!(1))).collect();
    for (index, memo) in [
        json!({"0":1}),
        json!({"bad key":1}),
        json!([1]),
        json!({"large":"x".repeat(10_240)}),
        Value::Object(many),
    ]
    .into_iter()
    .enumerate()
    {
        let id = format!("memo-invalid-{index}");
        let (status, response) = request(
            &app,
            "POST",
            "/api/workflows",
            json!({
                "workflow_id":id, "workflow_type":"echo", "task_queue":"test",
                "input":envelope(Payload::Array(vec![])), "memo":memo
            }),
        )
        .await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{response}");
        assert_eq!(response["reason"], "workflow_memo_validation_failed");
        assert_eq!(
            request(&app, "GET", &format!("/api/workflows/{id}"), Value::Null)
                .await
                .0,
            StatusCode::NOT_FOUND,
            "invalid original was not admitted"
        );
    }
    runtime.close().await;
    database.remove().await;
}
