use super::*;

fn condition(occurrence: Value) -> Value {
    json!({"type":"open_condition_wait","condition_key":"ready",
        "condition_definition_fingerprint":format!("sha256:{}", "a".repeat(64)),
        "condition_wait_occurrence_id":occurrence})
}

async fn register_signal_worker(app: &Router, worker: &str) {
    let response = request(
        app,
        "POST",
        "/api/worker/register",
        json!({"worker_id":worker,"task_queue":"test","runtime":"rust","supported_workflow_types":["echo"],
            "supported_activity_types":[],
            "workflow_command_contracts":{"echo":{"signals":["payload"],"signal_contracts":[{
                "name":"payload","parameters":[{"name":"args","position":0,"type":null,
                    "required":false,"variadic":true,"default_available":false,"default":null,"allows_null":true}]}]}}}),
    )
    .await;
    assert_eq!(response.0, StatusCode::CREATED, "{}", response.1);
}

#[tokio::test]
async fn authored_occurrences_survive_restart_and_completion_fences() {
    let database = TestDatabase::new().await;
    let first = database.open().await.unwrap();
    let app = router(first.clone());
    register_signal_worker(&app, "occurrence-original").await;
    let started = start(&app, "occurrence-original-run").await;
    let initial = poll(&app, "occurrence-original", "workflow").await;
    let first_wait = json!([condition(json!("rust:condition-wait:0"))]);
    assert_eq!(
        finish_task(&app, &initial, first_wait.clone()).await.0,
        StatusCode::OK
    );
    let prefix = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(prefix.as_array().unwrap().len(), 3);
    assert_eq!(
        prefix[2]["payload"]["condition_wait_occurrence_id"],
        "rust:condition-wait:0"
    );
    assert_eq!(
        finish_task(&app, &initial, first_wait).await.1["recorded"],
        false
    );
    assert_eq!(
        run_history(&app, &started["workflow_id"], &started["run_id"]).await,
        prefix
    );
    drop(app);
    first.close().await;

    let second = database.open().await.unwrap();
    let app = router(second.clone());
    register_signal_worker(&app, "occurrence-resumed").await;
    let path = format!(
        "/api/workflows/{}/signal/payload",
        started["workflow_id"].as_str().unwrap()
    );
    let input = json!({"input":envelope(Payload::Array(vec![Payload::Long(9007199254740993)]))});
    let first_signal = request(&app, "POST", &path, input.clone()).await;
    assert_eq!(first_signal.0, StatusCode::ACCEPTED, "{}", first_signal.1);
    let resumed = poll(&app, "occurrence-resumed", "workflow").await;
    assert_eq!(
        resumed["history_events"][2]["payload"],
        prefix[2]["payload"]
    );
    let second_wait = json!([condition(json!("rust:condition-wait:1"))]);
    let mut stale = completion(&resumed, second_wait.clone());
    stale["lease_owner"] = json!("occurrence-original");
    let completion_path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        resumed["task_id"].as_str().unwrap()
    );
    let before = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(
        request(&app, "POST", &completion_path, stale).await.0,
        StatusCode::CONFLICT
    );
    assert_eq!(
        run_history(&app, &started["workflow_id"], &started["run_id"]).await,
        before
    );
    assert_eq!(
        finish_task(&app, &resumed, second_wait.clone()).await.0,
        StatusCode::OK
    );
    assert_eq!(
        finish_task(&app, &resumed, second_wait).await.1["recorded"],
        false
    );
    let middle = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(middle.as_array().unwrap().len(), 8);
    assert_eq!(
        middle[6]["payload"]["condition_wait_occurrence_id"],
        "rust:condition-wait:0"
    );
    assert_eq!(
        middle[6]["payload"]["condition_wait_id"],
        prefix[2]["payload"]["condition_wait_id"]
    );
    assert_eq!(
        middle[7]["payload"]["condition_wait_occurrence_id"],
        "rust:condition-wait:1"
    );
    drop(app);
    second.close().await;

    let third = database.open().await.unwrap();
    let app = router(third.clone());
    register_signal_worker(&app, "occurrence-replacement").await;
    let second_signal = request(&app, "POST", &path, input).await;
    assert_eq!(second_signal.0, StatusCode::ACCEPTED, "{}", second_signal.1);
    let replacement = poll(&app, "occurrence-replacement", "workflow").await;
    assert_eq!(
        replacement["history_events"][2]["payload"],
        prefix[2]["payload"]
    );
    assert_eq!(
        replacement["history_events"][6]["payload"],
        middle[6]["payload"]
    );
    assert_eq!(
        replacement["history_events"][7]["payload"],
        middle[7]["payload"]
    );
    let result = envelope(Payload::Long(9007199254740993));
    let commands = json!([{"type":"complete_workflow","result":result}]);
    assert_eq!(
        finish_task(&app, &replacement, commands.clone()).await.0,
        StatusCode::OK
    );
    assert_eq!(
        finish_task(&app, &replacement, commands).await.1["recorded"],
        false
    );
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(
        &history.as_array().unwrap()[..8],
        middle.as_array().unwrap().as_slice()
    );
    assert_eq!(
        history
            .as_array()
            .unwrap()
            .iter()
            .map(|event| event["event_type"].as_str().unwrap())
            .collect::<Vec<_>>(),
        [
            "StartAccepted",
            "WorkflowStarted",
            "ConditionWaitOpened",
            "SignalReceived",
            "MessageCursorAdvanced",
            "SignalApplied",
            "ConditionWaitSatisfied",
            "ConditionWaitOpened",
            "SignalReceived",
            "MessageCursorAdvanced",
            "SignalApplied",
            "ConditionWaitSatisfied",
            "WorkflowCompleted"
        ]
    );
    assert_eq!(
        history[11]["payload"]["condition_wait_occurrence_id"],
        "rust:condition-wait:1"
    );
    assert_eq!(
        history[11]["payload"]["condition_wait_id"],
        middle[7]["payload"]["condition_wait_id"]
    );
    assert_eq!(history[12]["payload"]["output"], result);
    third.close().await;
    database.remove().await;
}

#[tokio::test]
async fn optional_occurrences_match_php_and_invalid_types_leave_the_attempt_usable() {
    // PHP omits absent/null/empty occurrences and keeps authored strings without
    // imposing the unrelated durable-ID length limit on this history metadata.
    for occurrence in [
        None,
        Some(Value::Null),
        Some(json!("")),
        Some(json!("λ".repeat(300))),
    ] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        register(&app, "occurrence-validation", json!(["echo"]), json!([])).await;
        let started = start(&app, "occurrence-validation-run").await;
        let task = poll(&app, "occurrence-validation", "workflow").await;
        let prefix = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
        for invalid in [json!(7), json!(true), json!([]), json!({})] {
            assert_eq!(
                finish_task(&app, &task, json!([condition(invalid)]))
                    .await
                    .0,
                StatusCode::UNPROCESSABLE_ENTITY
            );
            assert_eq!(
                run_history(&app, &started["workflow_id"], &started["run_id"]).await,
                prefix
            );
        }
        let mut command = condition(occurrence.clone().unwrap_or(Value::Null));
        if occurrence.is_none() {
            command
                .as_object_mut()
                .unwrap()
                .remove("condition_wait_occurrence_id");
        }
        assert_eq!(
            finish_task(&app, &task, json!([command])).await.0,
            StatusCode::OK
        );
        let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
        let recorded = history[2]["payload"].get("condition_wait_occurrence_id");
        if occurrence
            .as_ref()
            .and_then(Value::as_str)
            .is_some_and(|value| !value.is_empty())
        {
            assert_eq!(recorded, occurrence.as_ref());
        } else {
            assert!(recorded.is_none());
        }
        runtime.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn variadic_signal_arguments_preserve_values_and_refuse_invalid_tails() {
    let tail = |position: usize, kind: Value, nullable: bool| {
        json!({"name":"args","position":position,"type":kind,"required":false,
            "variadic":true,"default_available":false,"default":null,"allows_null":nullable})
    };
    let prefix = json!({"name":"label","position":0,"type":"string","required":true,
        "variadic":false,"default_available":false,"allows_null":false});
    let label = Payload::String("prefix λ".into());
    let map = Payload::Map(std::collections::BTreeMap::from([(
        "message".into(),
        Payload::String("variadic λ".into()),
    )]));
    for (parameters, accepted, rejected, reason) in [
        (
            json!([tail(0, Value::Null, true)]),
            vec![
                vec![],
                vec![Payload::Null, Payload::Long(9007199254740993), map.clone()],
            ],
            vec![],
            "invalid_signal_arguments",
        ),
        (
            json!([prefix.clone(), tail(1, json!("int"), false)]),
            vec![
                vec![label.clone()],
                vec![
                    label.clone(),
                    Payload::Long(9007199254740993),
                    Payload::Long(-9),
                ],
            ],
            vec![
                vec![],
                vec![Payload::Long(1)],
                vec![label.clone(), Payload::Null],
                vec![label.clone(), Payload::Boolean(true)],
                vec![
                    label.clone(),
                    Payload::Long(1),
                    Payload::String("wrong tail".into()),
                ],
            ],
            "invalid_signal_arguments",
        ),
        (
            json!([tail(0, json!("float"), false)]),
            vec![
                vec![],
                vec![Payload::Long(9007199254740993), Payload::Double(1.25)],
            ],
            vec![
                vec![Payload::Null],
                vec![Payload::Boolean(false)],
                vec![Payload::String("1.25".into())],
            ],
            "invalid_signal_arguments",
        ),
        (
            json!([tail(0, Value::Null, false)]),
            vec![vec![map]],
            vec![vec![Payload::Null], vec![label.clone(), Payload::Null]],
            "invalid_signal_arguments",
        ),
        (
            json!([tail(0, Value::Null, true), prefix]),
            vec![],
            vec![vec![], vec![Payload::Long(1)]],
            "unsupported_signal_contract",
        ),
    ] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        let registered = request(
            &app,
            "POST",
            "/api/worker/register",
            json!({"worker_id":"variadic-worker","runtime":"rust","task_queue":"test",
                "supported_workflow_types":["echo"],"supported_activity_types":[],
                "workflow_command_contracts":{"echo":{"signals":["payload"],
                    "signal_contracts":[{"name":"payload","parameters":parameters}]}}}),
        )
        .await;
        assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
        let started = start(&app, "variadic-signal-run").await;
        // Keep the original workflow task leased: accepted signals are buffered,
        // while refused argument lists must add no command/history mutation.
        let _task = poll(&app, "variadic-worker", "workflow").await;
        let path = "/api/workflows/variadic-signal-run/signal/payload";
        for values in rejected {
            let before = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
            let response = request(
                &app,
                "POST",
                path,
                json!({"input":envelope(Payload::Array(values))}),
            )
            .await;
            assert_eq!(
                response.0,
                StatusCode::UNPROCESSABLE_ENTITY,
                "{}",
                response.1
            );
            assert_eq!(response.1["reason"], reason);
            assert_eq!(
                run_history(&app, &started["workflow_id"], &started["run_id"]).await,
                before
            );
        }
        for values in accepted {
            let before = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
            let expected = Payload::Array(values);
            let response = request(
                &app,
                "POST",
                path,
                json!({"input":envelope(expected.clone())}),
            )
            .await;
            assert_eq!(response.0, StatusCode::ACCEPTED, "{}", response.1);
            let after = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
            let events = after.as_array().unwrap();
            assert_eq!(events.len(), before.as_array().unwrap().len() + 1);
            assert_eq!(
                &events[..events.len() - 1],
                before.as_array().unwrap().as_slice()
            );
            let event = events.last().unwrap();
            assert_eq!(event["event_type"], "SignalReceived");
            assert_eq!(
                event["payload"]["workflow_command_id"],
                response.1["command_id"]
            );
            assert_eq!(
                ValueCodec::new()
                    .unwrap()
                    .decode(event["payload"]["arguments"]["blob"].as_str().unwrap())
                    .unwrap(),
                expected
            );
        }
        runtime.close().await;
        database.remove().await;
    }
}
