use super::*;

#[tokio::test]
async fn memo_capability_refusal_and_cold_replay_routing_preserve_original_history() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let worker = "memo-routing-author";
    register(&app, worker, json!(["echo"]), json!(["echo_activity"])).await;
    let original = start(&app, "memo-routing-original").await;
    let task = poll(&app, worker, "workflow").await;
    let path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        task["task_id"].as_str().unwrap()
    );
    let history_path = format!(
        "/api/workflows/memo-routing-original/runs/{}/history",
        original["run_id"].as_str().unwrap()
    );
    let before = request(&app, "GET", &history_path, Value::Null).await.1;
    let mut body = completion(
        &task,
        json!([
            {"type":"upsert_memo","entries":envelope(Payload::Map(std::collections::BTreeMap::from([
                ("counter".into(), Payload::Long(9007199254740993))
            ])))},
            {"type":"schedule_activity","activity_type":"echo_activity",
                "arguments":envelope(Payload::Array(vec![Payload::Long(7)]))}
        ]),
    );
    let refused = request(&app, "POST", &path, body.clone()).await;
    assert_eq!(refused.0, StatusCode::CONFLICT);
    assert_eq!(
        refused.1["reason"],
        "workflow_metadata_capability_not_advertised"
    );
    assert_eq!(refused.1["recorded"], false);
    assert_eq!(
        request(&app, "GET", &history_path, Value::Null).await.1,
        before
    );
    assert_eq!(
        request(
            &app,
            "POST",
            "/api/worker/register",
            json!({"worker_id":worker,"task_queue":"test","runtime":"php",
            "supported_workflow_types":["echo"],"supported_activity_types":["echo_activity"],
            "capabilities":["memo_upserts"]})
        )
        .await
        .0,
        StatusCode::CREATED
    );
    let replacement = poll(&app, worker, "workflow").await;
    assert_eq!(replacement["task_id"], task["task_id"]);
    assert_eq!(replacement["run_id"], original["run_id"]);
    assert_eq!(
        request(&app, "POST", &path, body.clone()).await.0,
        StatusCode::CONFLICT,
        "changed registration does not upgrade the old claim authority"
    );
    body["lease_owner"] = replacement["lease_owner"].clone();
    body["workflow_task_attempt"] = replacement["workflow_task_attempt"].clone();
    let accepted = request(&app, "POST", &path, body.clone()).await;
    assert_eq!(accepted.0, StatusCode::OK, "{}", accepted.1);
    let activity = poll(&app, worker, "activity").await;
    let completed = request(&app, "POST", &format!("/api/worker/activity-tasks/{}/complete", activity["task_id"].as_str().unwrap()),
        json!({"lease_owner":activity["lease_owner"],"activity_attempt_id":activity["activity_attempt_id"],
            "result":envelope(Payload::Long(7))})).await;
    assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
    let prefix = request(&app, "GET", &history_path, Value::Null).await.1;
    runtime.close().await;
    let cold = database.open().await.unwrap();
    let app = router(cold.clone());
    register(&app, "memo-routing-incapable", json!(["echo"]), json!([])).await;
    assert!(
        poll(&app, "memo-routing-incapable", "workflow")
            .await
            .is_null()
    );
    assert_eq!(
        request(&app, "GET", &history_path, Value::Null).await.1,
        prefix
    );
    let duplicate = request(&app, "POST", &path, body.clone()).await;
    assert_eq!(duplicate.0, StatusCode::OK);
    assert_eq!(duplicate.1["recorded"], false);
    let mut stale = body;
    stale["workflow_task_attempt"] = json!(99);
    assert_eq!(
        request(&app, "POST", &path, stale).await.0,
        StatusCode::CONFLICT
    );
    assert_eq!(
        request(&app, "GET", &history_path, Value::Null).await.1,
        prefix
    );
    let parent = poll(&app, worker, "workflow").await;
    assert_eq!(parent["run_id"], original["run_id"]);
    assert!(
        parent["history_events"]
            .as_array()
            .unwrap()
            .iter()
            .any(|event| event["event_type"] == "MemoUpserted")
    );
    assert_eq!(
        finish_task(
            &app,
            &parent,
            json!([
                {"type":"complete_workflow","result":envelope(Payload::Long(7))}
            ])
        )
        .await
        .0,
        StatusCode::OK
    );
    let final_history = request(&app, "GET", &history_path, Value::Null).await.1;
    assert_eq!(
        final_history["events"]
            .as_array()
            .unwrap()
            .iter()
            .filter(|event| event["event_type"] == "MemoUpserted")
            .count(),
        1
    );
    assert_eq!(
        request(
            &app,
            "GET",
            "/api/workflows/memo-routing-original",
            Value::Null
        )
        .await
        .1["memo"],
        json!({"counter":9007199254740993_i64})
    );
    cold.close().await;
    database.remove().await;
}

#[tokio::test]
async fn memo_protocol_floor_preserves_original_and_capable_claim_can_upsert() {
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
            ("valid".into(), Payload::Long(1))])))},
            {"type":"complete_workflow", "result":envelope(Payload::Long(7))}]});
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
    assert_eq!(
        request(&app, "GET", &history_path, Value::Null).await,
        before
    );
    let (status, completed) = request_protocol(&app, "POST", &path, body, "1.14").await;
    assert_eq!(status, StatusCode::OK, "{completed}");
    assert_eq!(completed["task_id"], task["task_id"]);
    assert_eq!(completed["recorded"], true);
    let (_, history) = request(&app, "GET", &history_path, Value::Null).await;
    assert_eq!(
        history["events"]
            .as_array()
            .unwrap()
            .iter()
            .map(|event| event["event_type"].as_str().unwrap())
            .collect::<Vec<_>>(),
        [
            "StartAccepted",
            "WorkflowStarted",
            "MemoUpserted",
            "WorkflowCompleted"
        ]
    );
    let (_, described) = request(
        &app,
        "GET",
        &format!(
            "/api/workflows/memo-floor-original/runs/{}",
            original["run_id"].as_str().unwrap()
        ),
        Value::Null,
    )
    .await;
    assert_eq!(described["memo"], json!({"valid":1}));
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn activity_history_snapshots_preserve_the_persisted_repair_counter() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(
        &app,
        "memo-history-worker",
        json!(["echo"]),
        json!(["echo"]),
    )
    .await;
    for repair_count in [0, 17] {
        let id = format!("memo-history-{repair_count}");
        let original = start(&app, &id).await;
        let workflow = poll(&app, "memo-history-worker", "workflow").await;
        let (status, _) = request(&app, "POST", &format!("/api/worker/workflow-tasks/{}/complete", workflow["task_id"].as_str().unwrap()), completion(&workflow, json!([
            {"type":"schedule_activity","activity_type":"echo","arguments":envelope(Payload::Long(7))}
        ]))).await;
        assert_eq!(status, StatusCode::OK);
        // The nonzero control proves history reads the stored value. It does
        // not claim an activity repair API or a manually repaired SDK run.
        database.execute(if repair_count == 0 {
            "UPDATE workflow_tasks SET repair_count=0 WHERE task_type='activity' AND status='ready'"
        } else {
            "UPDATE workflow_tasks SET repair_count=17 WHERE task_type='activity' AND status='ready'"
        }).await;
        let activity = poll(&app, "memo-history-worker", "activity").await;
        let (status, _) = request(&app, "POST", &format!("/api/worker/activity-tasks/{}/complete", activity["task_id"].as_str().unwrap()),
            json!({"lease_owner":activity["lease_owner"],"activity_attempt_id":activity["activity_attempt_id"],"result":envelope(Payload::Long(7))})).await;
        assert_eq!(status, StatusCode::OK);
        let parent = poll(&app, "memo-history-worker", "workflow").await;
        assert_eq!(
            request(
                &app,
                "POST",
                &format!(
                    "/api/worker/workflow-tasks/{}/complete",
                    parent["task_id"].as_str().unwrap()
                ),
                completion(
                    &parent,
                    json!([{"type":"complete_workflow","result":envelope(Payload::Long(7))}])
                )
            )
            .await
            .0,
            StatusCode::OK
        );
        let (status, history) = request(
            &app,
            "GET",
            &format!(
                "/api/workflows/{id}/runs/{}/history",
                original["run_id"].as_str().unwrap()
            ),
            Value::Null,
        )
        .await;
        assert_eq!(status, StatusCode::OK);
        for event in history["events"]
            .as_array()
            .unwrap()
            .iter()
            .filter(|event| {
                ["ActivityStarted", "ActivityCompleted"]
                    .contains(&event["event_type"].as_str().unwrap())
            })
        {
            assert_eq!(event["payload"]["task"]["repair_count"], repair_count);
            assert_eq!(event["payload"]["task"]["id"], activity["task_id"]);
        }
    }
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn initial_json_boundary_matches_frozen_admission_and_survives_cold_open() {
    let fixture: Value =
        serde_json::from_str(include_str!("../fixtures/memo-initial-json-v1.json")).unwrap();
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let mut originals = Vec::new();
    for case in fixture["cases"].as_array().unwrap() {
        let id = format!("memo-json-{}", case["id"].as_str().unwrap());
        let mut raw = json!({"workflow_id":id, "workflow_type":"echo", "task_queue":"test",
            "input":envelope(Payload::Array(vec![]))})
        .to_string();
        raw.pop();
        raw.push_str(",\"memo\":{\"metadata\":");
        raw.push_str(case["json"].as_str().unwrap());
        raw.push_str("}}");
        let response = app
            .clone()
            .oneshot(
                Request::builder()
                    .method("POST")
                    .uri("/api/workflows")
                    .header("authorization", "Bearer test-token")
                    .header("content-type", "application/json")
                    .header("x-namespace", "default")
                    .header("x-durable-workflow-control-plane-version", "2")
                    .body(Body::from(raw))
                    .unwrap(),
            )
            .await
            .unwrap();
        let status = response.status();
        let original: Value = serde_json::from_slice(
            &to_bytes(response.into_body(), 4 * 1024 * 1024)
                .await
                .unwrap(),
        )
        .unwrap();
        if case["accepted"] == false {
            assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{original}");
            assert_eq!(
                request(&app, "GET", &format!("/api/workflows/{id}"), Value::Null)
                    .await
                    .0,
                StatusCode::NOT_FOUND
            );
        } else {
            assert_eq!(status, StatusCode::CREATED, "{original}");
            if let Some(bits) = case["expected_avro_double_bits"].as_str() {
                let (_, history) = request(
                    &app,
                    "GET",
                    &format!(
                        "/api/workflows/{id}/runs/{}/history",
                        original["run_id"].as_str().unwrap()
                    ),
                    Value::Null,
                )
                .await;
                let events = history["events"].as_array().unwrap();
                assert_eq!(events.len(), 2);
                assert_eq!(events[0]["event_type"], "StartAccepted");
                assert_eq!(events[1]["event_type"], "WorkflowStarted");
                for event in events {
                    let number = &event["payload"]["memo"]["metadata"];
                    assert!(number.as_i64().is_none());
                    assert_eq!(
                        format!("{:016x}", number.as_f64().unwrap().to_bits().swap_bytes()),
                        bits
                    );
                }
            }
            originals.push((
                id,
                original["run_id"].as_str().unwrap().to_owned(),
                case["expected"].clone(),
            ));
        }
    }
    let raw = r#"{"workflow_id":"memo-json-history","workflow_type":"echo","task_queue":"test","input":{"codec":"avro","blob":"wwHioz3/VYAiNwA="},"memo":{"metadata":{"map":{},"numeric":{"1":"one","0":"zero"}},"deleted":null}}"#;
    let response = app
        .clone()
        .oneshot(
            Request::builder()
                .method("POST")
                .uri("/api/workflows")
                .header("authorization", "Bearer test-token")
                .header("content-type", "application/json")
                .header("x-namespace", "default")
                .header("x-durable-workflow-control-plane-version", "2")
                .body(Body::from(raw))
                .unwrap(),
        )
        .await
        .unwrap();
    assert_eq!(response.status(), StatusCode::CREATED);
    let original: Value = serde_json::from_slice(
        &to_bytes(response.into_body(), 4 * 1024 * 1024)
            .await
            .unwrap(),
    )
    .unwrap();
    let run = original["run_id"].as_str().unwrap();
    let (_, history) = request(
        &app,
        "GET",
        &format!("/api/workflows/memo-json-history/runs/{run}/history"),
        Value::Null,
    )
    .await;
    for event in history["events"].as_array().unwrap().iter().take(2) {
        assert_eq!(
            event["payload"]["memo"],
            json!({"metadata":{"map":[],"numeric":{"1":"one","0":"zero"}},"deleted":null})
        );
    }
    originals.push((
        "memo-json-history".into(),
        run.into(),
        json!({"map":[], "numeric":["zero","one"]}),
    ));
    runtime.close().await;
    drop(app);
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    for (id, run, expected) in originals {
        let (status, description) = request(
            &app,
            "GET",
            &format!("/api/workflows/{id}/runs/{run}"),
            Value::Null,
        )
        .await;
        assert_eq!(status, StatusCode::OK, "{description}");
        assert_eq!(description["memo"], json!({"metadata":expected}));
    }
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
    projected["map"] = json!([]);
    let mut admitted_value = value.clone();
    admitted_value["map"] = json!([]);
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
            json!({"metadata":admitted_value, "deleted":null})
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
