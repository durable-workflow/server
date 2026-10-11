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
                "name":"payload","parameters":[{"name":"value","position":0,"type":"int",
                    "required":true,"variadic":false,"default_available":false,"allows_null":false}]}]}}}),
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
