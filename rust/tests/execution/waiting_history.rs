use super::*;

fn waiting(task: &Value, failure: Value) -> Value {
    json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],"failure":failure})
}

fn waiting_failure() -> Value {
    json!({"type":"WorkflowTaskWaitingForHistory","message":"history is not ready yet"})
}

async fn report(app: &Router, task: &Value, body: Value) -> (StatusCode, Value) {
    request(
        app,
        "POST",
        &format!(
            "/api/worker/workflow-tasks/{}/fail",
            task["task_id"].as_str().unwrap()
        ),
        body,
    )
    .await
}

async fn await_replay(app: &Router) -> Value {
    let (status, body) = request(
        app,
        "POST",
        "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"waiting-worker","task_queue":"test","timeout_seconds":1}),
    )
    .await;
    assert_eq!(status, StatusCode::OK);
    assert!(body["task"].is_object(), "{body}");
    body["task"].clone()
}

#[tokio::test]
async fn waiting_reports_preserve_original_activity_and_history_across_restart() {
    for failure in [
        waiting_failure(),
        json!({"type":"PhpWorkflowTaskFailure","message":"workflow task waiting for scheduled history: original activity"}),
        json!({"type":" \tworkflowtaskwaitingforhistory\n","message":"typed case-insensitive wait"}),
    ] {
        let database = TestDatabase::new().await;
        let first = database.open().await.unwrap();
        let app = router(first.clone());
        register(
            &app,
            "waiting-worker",
            json!(["echo"]),
            json!(["echo_activity"]),
        )
        .await;
        let started = start(&app, "waiting-original").await;
        let initial = poll(&app, "waiting-worker", "workflow").await;
        assert_eq!(finish_task(&app, &initial, json!([
            {"type":"start_timer","delay_seconds":0},
            {"type":"schedule_activity","activity_type":"echo_activity","arguments":envelope(Payload::Array(vec![Payload::Long(9007199254740993)]))}
        ])).await.0, StatusCode::OK);
        let replay = await_replay(&app).await;
        assert_ne!(replay["task_id"], initial["task_id"]);
        let prefix = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
        assert_eq!(prefix.as_array().unwrap().len(), 5);
        assert_eq!(prefix[4]["event_type"], "TimerFired");
        let body = waiting(&replay, failure);
        let (status, acknowledgement) = report(&app, &replay, body.clone()).await;
        assert_eq!(status, StatusCode::OK, "{acknowledgement}");
        assert_eq!(
            acknowledgement,
            json!({"task_id":replay["task_id"],"workflow_task_attempt":replay["workflow_task_attempt"],
            "outcome":"waiting_for_history","recorded":true,"reason":null,"next_task_id":null})
        );
        assert_eq!(
            run_history(&app, &started["workflow_id"], &started["run_id"]).await,
            prefix
        );
        assert_eq!(poll(&app, "waiting-worker", "workflow").await, Value::Null);
        assert_eq!(
            report(&app, &replay, body.clone()).await.0,
            StatusCode::CONFLICT
        );
        let late = finish_task(&app, &replay, json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}])).await;
        assert_eq!(late.0, StatusCode::CONFLICT);
        assert_eq!(late.1["reason"], "task_not_leased");
        drop(app);
        first.close().await;
        let second = database.open().await.unwrap();
        let app = router(second.clone());
        assert_eq!(report(&app, &replay, body).await.0, StatusCode::CONFLICT);
        let activity = poll(&app, "waiting-worker", "activity").await;
        assert_eq!(
            activity["activity_execution_id"],
            prefix[3]["payload"]["activity_execution_id"]
        );
        assert_eq!(request(&app,"POST",&format!("/api/worker/activity-tasks/{}/complete",activity["task_id"].as_str().unwrap()),
            json!({"lease_owner":activity["lease_owner"],"activity_attempt_id":activity["activity_attempt_id"],"result":envelope(Payload::Long(9007199254740993))})).await.0,StatusCode::OK);
        let last = poll(&app, "waiting-worker", "workflow").await;
        assert_ne!(last["task_id"], replay["task_id"]);
        assert_eq!(finish_task(&app,&last,json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}])).await.0,StatusCode::OK);
        let final_history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
        assert_eq!(
            &final_history.as_array().unwrap()[..5],
            prefix.as_array().unwrap().as_slice()
        );
        assert_eq!(final_history.as_array().unwrap().len(), 8);
        second.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn invalid_or_stale_waiting_reports_leave_original_attempt_usable() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "waiting-worker", json!(["echo"]), json!([])).await;
    let started = start(&app, "waiting-validation").await;
    let task = poll(&app, "waiting-worker", "workflow").await;
    let prefix = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    for failure in [
        json!(null),
        json!({"message":3,"type":"WorkflowTaskWaitingForHistory"}),
        json!({"message":" ","type":"WorkflowTaskWaitingForHistory"}),
        json!({"message":"wait","type":7}),
        json!({"message":"wait","type":"WorkflowTaskWaitingForHistory","reason":false}),
        json!({"message":"wait","type":"WorkflowTaskWaitingForHistory","sequence":0}),
        json!({"message":"wait","type":"WorkflowTaskWaitingForHistory","stack_trace":[]}),
        json!({"message":"ordinary retryable failure","type":"WorkerFailure"}),
    ] {
        assert_eq!(
            report(&app, &task, waiting(&task, failure)).await.0,
            StatusCode::UNPROCESSABLE_ENTITY
        );
        assert_eq!(
            run_history(&app, &started["workflow_id"], &started["run_id"]).await,
            prefix
        );
    }
    for (field, value) in [
        ("lease_owner", json!("other-worker")),
        ("workflow_task_attempt", json!(99)),
    ] {
        let mut body = waiting(&task, waiting_failure());
        body[field] = value;
        assert_eq!(report(&app, &task, body).await.0, StatusCode::CONFLICT);
        assert_eq!(
            run_history(&app, &started["workflow_id"], &started["run_id"]).await,
            prefix
        );
    }
    assert_eq!(
        report(&app, &task, waiting(&task, waiting_failure()))
            .await
            .0,
        StatusCode::OK
    );
    assert_eq!(
        run_history(&app, &started["workflow_id"], &started["run_id"]).await,
        prefix
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn waiting_report_refuses_undelivered_signals_without_releasing_replay() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    assert_eq!(request(&app,"POST","/api/worker/register",json!({"worker_id":"waiting-worker","task_queue":"test","runtime":"php",
        "supported_workflow_types":["echo"],"supported_activity_types":[],"workflow_command_contracts":{"echo":{"signals":["payload"],
        "signal_contracts":[{"name":"payload","parameters":[{"name":"value","position":0,"type":"array","required":true,"variadic":false,"default_available":false,"allows_null":false}]}]}}})).await.0,StatusCode::CREATED);
    let started = start(&app, "waiting-buffered-signal").await;
    let task = poll(&app, "waiting-worker", "workflow").await;
    assert_eq!(request(&app,"POST",&format!("/api/workflows/waiting-buffered-signal/runs/{}/signal/payload",started["run_id"].as_str().unwrap()),
        json!({"input":envelope(Payload::Array(vec![Payload::Array(vec![Payload::Long(9007199254740993)])])),"request_id":"waiting-signal"})).await.0,StatusCode::ACCEPTED);
    let prefix = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let refusal = report(&app, &task, waiting(&task, waiting_failure())).await;
    assert_eq!(refusal.0, StatusCode::UNPROCESSABLE_ENTITY);
    assert_eq!(
        refusal.1["reason"],
        "development_workflow_task_waiting_messages_unqualified"
    );
    assert_eq!(
        run_history(&app, &started["workflow_id"], &started["run_id"]).await,
        prefix
    );
    assert_eq!(request(&app,"POST",&format!("/api/worker/workflow-tasks/{}/heartbeat",task["task_id"].as_str().unwrap()),
        json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"]})).await.0,StatusCode::OK);
    runtime.close().await;
    database.remove().await;
}
