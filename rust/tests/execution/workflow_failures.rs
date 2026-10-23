use super::*;

fn command() -> Value {
    json!({"type":"fail_workflow","message":" outer failure λ ",
        "exception_class":"OuterException","exception_type":"outer-v1",
        "exception":{"class":"DomainException","type":"domain-v1","message":" inner failure λ ",
            "code":9007199254740993_i64,"file":"workflow.php","line":17,"trace":[],
            "properties":[{"declaring_class":"DomainException","name":"original","value":9007199254740993_i64}]},
        "non_retryable":true})
}

async fn history(app: &Router, run: &Value) -> Value {
    run_history(app, &run["workflow_id"], &run["run_id"]).await
}

async fn snapshot(database: &TestDatabase, app: &Router, run: &Value) {
    snapshot_command(database, app, run, command()).await;
}

async fn snapshot_command(database: &TestDatabase, app: &Router, run: &Value, command: Value) {
    let mut summary = run_observation_active_summaries::observe(app, "default", run).await;
    assert_eq!(summary["summary_state"], "observed", "{summary}");
    summary["reference_failure_command"] = command;
    let history = summary["history"].clone();
    run_observation_summaries::export_reference_for(
        database,
        &summary,
        &history,
        "DW_TEST_WORKFLOW_FAILURE_REFERENCE",
    )
    .await;
}

#[tokio::test]
async fn workflow_failure_is_terminal_atomic_and_retains_one_receipt_after_cold_reopen() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "failure-worker", json!(["echo"]), json!([])).await;
    let run = start(&app, "workflow-failure-cold").await;
    let task = poll(&app, "failure-worker", "workflow").await;
    let commands = json!([command()]);
    let accepted = finish_task(&app, &task, commands.clone()).await;
    assert_eq!(accepted.0, StatusCode::OK, "{}", accepted.1);
    assert_eq!(accepted.1["run_status"], "failed");
    snapshot(&database, &app, &run).await;
    let original = history(&app, &run).await;
    let terminal = original.as_array().unwrap().last().unwrap();
    assert_eq!(terminal["event_type"], "WorkflowFailed");
    assert_eq!(task_retry::rows(&database,"SELECT COUNT(*) FROM workflow_history_events h JOIN workflow_tasks t ON t.id=h.workflow_task_id WHERE h.event_type='WorkflowFailed' AND t.status='failed'").await,1);
    let payload = &terminal["payload"];
    assert_eq!(payload["task"]["id"], task["task_id"]);
    assert_eq!(payload["task"]["status"], "leased");
    assert_eq!(payload["task"]["lease_owner"], task["lease_owner"]);
    assert_eq!(
        payload["task"]["attempt_count"],
        task["workflow_task_attempt"]
    );
    assert_eq!(
        chrono::DateTime::parse_from_rfc3339(payload["task"]["lease_expires_at"].as_str().unwrap())
            .unwrap(),
        chrono::DateTime::parse_from_rfc3339(task["lease_expires_at"].as_str().unwrap()).unwrap()
    );
    assert_eq!(payload["source_kind"], "workflow_run");
    assert_eq!(payload["source_id"], run["run_id"]);
    assert_eq!(payload["message"], "inner failure λ");
    assert_eq!(payload["exception_class"], "DomainException");
    assert_eq!(payload["exception_type"], "domain-v1");
    assert_eq!(payload["non_retryable"], true);
    // Laravel's TrimStrings trims every string in the request.
    assert_eq!(payload["exception"]["message"], "inner failure λ");
    assert_eq!(payload["exception"]["code"], 9007199254740993_i64);
    assert_eq!(
        payload["exception"]["properties"],
        command()["exception"]["properties"]
    );
    assert_eq!(task_retry::rows(&database,"SELECT COUNT(*) FROM workflow_failures WHERE source_kind='workflow_run' AND propagation_kind='terminal' AND failure_category='application' AND non_retryable=TRUE AND handled=FALSE AND file='' AND line=0 AND trace_preview=''").await,1);
    assert_eq!(task_retry::rows(&database,"SELECT COUNT(*) FROM workflow_tasks WHERE task_type='workflow' AND status='failed' AND lease_expires_at IS NULL").await,1);
    runtime.close().await;
    let cold = database.open().await.unwrap();
    let cold_app = router(cold.clone());
    let duplicate = finish_task(&cold_app, &task, commands).await;
    assert_eq!(duplicate.0, StatusCode::OK, "{}", duplicate.1);
    assert_eq!(duplicate.1["recorded"], false);
    let changed = finish_task(
        &cold_app,
        &task,
        json!([{"type":"fail_workflow","message":"different"}]),
    )
    .await;
    assert_eq!(changed.0, StatusCode::CONFLICT);
    // PHP's ownership guard: the task is no longer leased and its run closed.
    assert_eq!(changed.1["reason"], "run_closed", "{}", changed.1);
    assert_eq!(changed.1["can_continue"], false);
    // A task that failed its run is stored as failed, as PHP stores it.
    assert_eq!(changed.1["task_status"], "failed");
    assert_eq!(changed.1["run_status"], "failed");
    assert_eq!(changed.1["stop_reason"], "run_failed");
    assert_eq!(history(&cold_app, &run).await, original);
    snapshot(&database, &cold_app, &run).await;
    assert!(
        poll(&cold_app, "failure-worker", "workflow")
            .await
            .is_null()
    );
    cold.close().await;
    database.remove().await;
}

#[tokio::test]
async fn workflow_failure_resolves_original_child_failure_and_one_parent_wakeup() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "failure-parent", json!(["echo"]), json!([])).await;
    register(
        &app,
        "failure-child",
        json!(["child"]),
        json!(["echo_activity"]),
    )
    .await;
    let parent = start(&app, "failed-child-parent").await;
    let initial = poll(&app, "failure-parent", "workflow").await;
    assert_eq!(
        finish_task(&app, &initial, json!([child_command(9007199254740993_i64)]))
            .await
            .0,
        StatusCode::OK
    );
    let child = poll(&app, "failure-child", "workflow").await;
    assert_eq!(finish_task(&app,&child,json!([{"type":"schedule_activity","activity_type":"echo_activity","arguments":envelope(Payload::Array(vec![]))}])).await.0,StatusCode::OK);
    let activity = poll(&app, "failure-child", "activity").await;
    let activity_failed=request(&app,"POST",&format!("/api/worker/activity-tasks/{}/fail",activity["task_id"].as_str().unwrap()),
        json!({"lease_owner":activity["lease_owner"],"activity_attempt_id":activity["activity_attempt_id"],"failure":{"type":"RuntimeException","message":"earlier handled activity","non_retryable":true}})).await;
    assert_eq!(activity_failed.0, StatusCode::OK, "{}", activity_failed.1);
    let activity_history = run_history(&app, &child["workflow_id"], &child["run_id"]).await;
    let activity_failure = activity_history
        .as_array()
        .unwrap()
        .iter()
        .find(|event| event["event_type"] == "ActivityFailed")
        .unwrap()["payload"]["failure_id"]
        .clone();
    assert!(activity_failure.as_str().is_some_and(|id| !id.is_empty()));
    let child = poll(&app, "failure-child", "workflow").await;
    snapshot(&database, &app, &parent).await;
    let mut child_failure = command();
    child_failure
        .as_object_mut()
        .unwrap()
        .remove("exception_type");
    child_failure["exception"]
        .as_object_mut()
        .unwrap()
        .remove("type");
    let accepted = finish_task(&app, &child, json!([child_failure.clone()])).await;
    assert_eq!(accepted.0, StatusCode::OK, "{}", accepted.1);
    snapshot_command(&database, &app, &child, child_failure).await;
    let child_history = run_history(&app, &child["workflow_id"], &child["run_id"]).await;
    let failure = &child_history.as_array().unwrap().last().unwrap()["payload"];
    let parent_history = history(&app, &parent).await;
    let resolved = parent_history.as_array().unwrap().last().unwrap();
    assert_eq!(resolved["event_type"], "ChildRunFailed");
    assert_eq!(resolved["payload"]["failure_id"], failure["failure_id"]);
    assert!(resolved["payload"].get("non_retryable").is_none());
    assert!(resolved["payload"].get("exception_type").is_none());
    assert_eq!(resolved["payload"]["exception"], failure["exception"]);
    assert_ne!(failure["failure_id"], activity_failure);
    assert_eq!(task_retry::rows(&database,"SELECT COUNT(*) FROM workflow_child_calls c JOIN workflow_failures f ON f.id=c.failure_reference AND f.workflow_run_id=c.resolved_child_run_id WHERE c.status='failed' AND f.propagation_kind='terminal'").await,1);
    assert_eq!(
        resolved["payload"]["child_workflow_run_id"],
        child["run_id"]
    );
    assert_eq!(resolved["payload"]["message"], "inner failure λ");
    snapshot(&database, &app, &parent).await;
    let resumed = poll(&app, "failure-parent", "workflow").await;
    assert_eq!(resumed["run_id"], parent["run_id"]);
    assert_eq!(resumed["child_workflow_run_id"], child["run_id"]);
    snapshot(&database, &app, &parent).await;
    assert!(poll(&app, "failure-parent", "workflow").await.is_null());
    let restarted = finish_task(&app, &resumed, json!([child_command(9007199254740994_i64)])).await;
    assert_eq!(restarted.0, StatusCode::OK, "{}", restarted.1);
    snapshot(&database, &app, &parent).await;
    let next_child = poll(&app, "failure-child", "workflow").await;
    assert_ne!(next_child["run_id"], child["run_id"]);
    assert_eq!(finish_task(&app,&next_child,json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740994_i64))}])).await.0,StatusCode::OK);
    let resumed = poll(&app, "failure-parent", "workflow").await;
    assert_eq!(resumed["run_id"], parent["run_id"]);
    let completed = finish_task(
        &app,
        &resumed,
        json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}]),
    )
    .await;
    assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
    snapshot(&database, &app, &parent).await;
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn workflow_failure_preserves_named_namespace_identity_and_discovery() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    namespaces::create(&app, "failure-named").await;
    let registered=namespaces::scoped(&app,"failure-named","POST","/api/worker/register",
        json!({"worker_id":"named-failure-worker","task_queue":"test","runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":[]})).await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    assert!(
        registered.1["server_capabilities"]["supported_workflow_task_commands"]
            .as_array()
            .unwrap()
            .iter()
            .any(|v| v == "fail_workflow")
    );
    let run = namespaces::start_scoped(&app, "failure-named", "failure-named-original").await;
    let claimed = namespaces::scoped(
        &app,
        "failure-named",
        "POST",
        "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"named-failure-worker","task_queue":"test","timeout_seconds":0}),
    )
    .await;
    let task = &claimed.1["task"];
    assert!(!task.is_null(), "{}", claimed.1);
    let path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        task["task_id"].as_str().unwrap()
    );
    assert_eq!(
        request(&app, "POST", &path, completion(task, json!([command()])))
            .await
            .0,
        StatusCode::NOT_FOUND
    );
    let accepted = namespaces::scoped(
        &app,
        "failure-named",
        "POST",
        &path,
        completion(task, json!([command()])),
    )
    .await;
    assert_eq!(accepted.0, StatusCode::OK, "{}", accepted.1);
    assert_eq!(accepted.1["run_status"], "failed");
    let summary = run_observation_active_summaries::observe(&app, "failure-named", &run).await;
    assert_eq!(summary["summary_state"], "observed", "{summary}");
    assert_eq!(summary["liveness_state"], "closed");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn workflow_failure_competing_pools_commit_one_terminal_failure() {
    let database = TestDatabase::new().await;
    let one = database.open().await.unwrap();
    let two = database.open().await.unwrap();
    let a = router(one.clone());
    let b = router(two.clone());
    register(&a, "failure-worker", json!(["echo"]), json!([])).await;
    let run = start(&a, "failure-competing").await;
    let task = poll(&a, "failure-worker", "workflow").await;
    let (left, right) = tokio::join!(
        finish_task(&a, &task, json!([command()])),
        finish_task(&b, &task, json!([command()]))
    );
    assert_eq!(left.0, StatusCode::OK, "{}", left.1);
    assert_eq!(right.0, StatusCode::OK, "{}", right.1);
    assert_ne!(left.1["recorded"], right.1["recorded"]);
    assert_eq!(
        task_retry::rows(
            &database,
            "SELECT COUNT(*) FROM workflow_failures WHERE propagation_kind='terminal'"
        )
        .await,
        1
    );
    assert_eq!(
        history(&b, &run)
            .await
            .as_array()
            .unwrap()
            .iter()
            .filter(|e| e["event_type"] == "WorkflowFailed")
            .count(),
        1
    );
    snapshot(&database, &b, &run).await;
    one.close().await;
    two.close().await;
    database.remove().await;
}

#[tokio::test]
async fn workflow_failure_rejects_malformed_batches_and_stale_attempts_without_mutation() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "failure-worker", json!(["echo"]), json!([])).await;
    let run = start(&app, "workflow-failure-invalid").await;
    let task = poll(&app, "failure-worker", "workflow").await;
    let original = history(&app, &run).await;
    // Two terminal commands are the bridge's 409 `invalid_commands`, as PHP's.
    for (commands, expected) in [
        (json!([{"type":"fail_workflow","message":" \t\u{000b}"}]), StatusCode::UNPROCESSABLE_ENTITY),
        (json!([{"type":"fail_workflow","message":"failure","non_retryable":"true"}]), StatusCode::UNPROCESSABLE_ENTITY),
        (json!([{"type":"start_timer","delay_seconds":5},{"type":"fail_workflow","message":"failure","failed_step_sequence":1}]), StatusCode::UNPROCESSABLE_ENTITY),
        (json!([{"type":"fail_workflow","message":"one"},{"type":"fail_workflow","message":"two"}]), StatusCode::CONFLICT),
        (json!([{"type":"fail_workflow","message":"one"},{"type":"complete_workflow","result":envelope(Payload::Null)}]), StatusCode::CONFLICT),
        (json!([{"type":"fail_workflow","message":"one"},{"type":"unknown"}]), StatusCode::UNPROCESSABLE_ENTITY),
    ] {
        assert_eq!(
            finish_task(&app, &task, commands).await.0,
            expected
        );
        assert_eq!(history(&app, &run).await, original);
        assert_eq!(
            task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_failures").await,
            0
        );
        assert_eq!(
            task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_run_timers").await,
            0
        );
        assert_eq!(
            task_retry::rows(
                &database,
                "SELECT COUNT(*) FROM workflow_tasks WHERE status='leased'"
            )
            .await,
            1
        );
    }
    for (field, value) in [
        ("lease_owner", json!("other")),
        ("workflow_task_attempt", json!(99)),
    ] {
        let mut body = completion(&task, json!([command()]));
        body[field] = value;
        assert_eq!(
            request(
                &app,
                "POST",
                &format!(
                    "/api/worker/workflow-tasks/{}/complete",
                    task["task_id"].as_str().unwrap()
                ),
                body
            )
            .await
            .0,
            StatusCode::CONFLICT
        );
        assert_eq!(history(&app, &run).await, original);
    }
    assert_eq!(
        finish_task(&app, &task, json!([command()])).await.0,
        StatusCode::OK
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn workflow_failure_applies_authored_commands_before_terminal_and_closes_pending_work() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(
        &app,
        "failure-worker",
        json!(["echo"]),
        json!(["echo_activity"]),
    )
    .await;
    let run = start(&app, "workflow-failure-pending").await;
    let task = poll(&app, "failure-worker", "workflow").await;
    let commands = json!([command(),{"type":"start_timer","delay_seconds":60},
        {"type":"schedule_activity","activity_type":"echo_activity","arguments":envelope(Payload::Array(vec![Payload::Long(9007199254740993)]))}]);
    let accepted = finish_task(&app, &task, commands).await;
    assert_eq!(accepted.0, StatusCode::OK, "{}", accepted.1);
    let events = history(&app, &run).await;
    assert_eq!(
        events
            .as_array()
            .unwrap()
            .iter()
            .map(|e| e["event_type"].as_str().unwrap())
            .collect::<Vec<_>>(),
        vec![
            "StartAccepted",
            "WorkflowStarted",
            "TimerScheduled",
            "ActivityScheduled",
            "WorkflowFailed"
        ]
    );
    assert_eq!(
        task_retry::rows(
            &database,
            "SELECT COUNT(*) FROM workflow_run_timers WHERE status='pending'"
        )
        .await,
        1
    );
    assert_eq!(
        task_retry::rows(
            &database,
            "SELECT COUNT(*) FROM activity_executions WHERE status='pending'"
        )
        .await,
        1
    );
    assert!(poll(&app, "failure-worker", "activity").await.is_null());
    assert!(poll(&app, "failure-worker", "workflow").await.is_null());
    assert_eq!(history(&app, &run).await, events);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn workflow_failure_yields_to_original_cooperative_cancellation() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let registered=request_protocol(&app,"POST","/api/worker/register",json!({"worker_id":"cleanup-worker","task_queue":"test","runtime":"php",
        "supported_workflow_types":["echo"],"supported_activity_types":[],"capabilities":["cooperative_cancellation"]}),"1.20").await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    let run = start(&app, "workflow-failure-cancel").await;
    let requested = request(
        &app,
        "POST",
        &format!(
            "/api/workflows/{}/runs/{}/request-cancellation",
            run["workflow_id"].as_str().unwrap(),
            run["run_id"].as_str().unwrap()
        ),
        json!({"reason":"original root","cleanup_timeout_seconds":30}),
    )
    .await;
    assert_eq!(requested.0, StatusCode::ACCEPTED, "{}", requested.1);
    let task = cooperative_poll(&app, "workflow").await;
    assert!(!task.is_null());
    let accepted = request_protocol(
        &app,
        "POST",
        &format!(
            "/api/worker/workflow-tasks/{}/complete",
            task["task_id"].as_str().unwrap()
        ),
        completion(&task, json!([command()])),
        "1.20",
    )
    .await;
    assert_eq!(accepted.0, StatusCode::OK, "{}", accepted.1);
    assert_eq!(accepted.1["run_status"], "cancelled");
    let events = history(&app, &run).await;
    assert!(
        !events
            .as_array()
            .unwrap()
            .iter()
            .any(|e| e["event_type"] == "WorkflowFailed")
    );
    let terminal = &events.as_array().unwrap().last().unwrap()["payload"];
    assert_eq!(
        terminal["cancellation_cleanup"]["request_id"],
        requested.1["cancellation_request"]["request_id"]
    );
    assert_eq!(
        terminal["cancellation_cleanup"]["cleanup_deadline_at"],
        requested.1["cancellation_request"]["cleanup_deadline_at"]
    );
    assert_eq!(
        task_retry::rows(
            &database,
            "SELECT COUNT(*) FROM workflow_failures WHERE propagation_kind='terminal'"
        )
        .await,
        0
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn workflow_failure_terminal_position_does_not_split_authored_selection_members() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(
        &app,
        "failure-worker",
        json!(["echo"]),
        json!(["echo_activity"]),
    )
    .await;
    let run = start(&app, "failure-selection-terminal-position").await;
    let task = poll(&app, "failure-worker", "workflow").await;
    let mut commands = selections::mixed_commands(2);
    commands[2]["delay_seconds"] = json!(60);
    commands.as_array_mut().unwrap().insert(2, command());
    let accepted = finish_task(&app, &task, commands).await;
    assert_eq!(accepted.0, StatusCode::OK, "{}", accepted.1);
    let events = history(&app, &run).await;
    assert_eq!(
        events
            .as_array()
            .unwrap()
            .iter()
            .map(|e| e["event_type"].as_str().unwrap())
            .collect::<Vec<_>>(),
        vec![
            "StartAccepted",
            "WorkflowStarted",
            "VersionMarkerRecorded",
            "ActivityScheduled",
            "TimerScheduled",
            "WorkflowFailed"
        ]
    );
    assert_eq!(events[3]["payload"]["sequence"], 2);
    assert_eq!(events[4]["payload"]["sequence"], 3);
    assert_eq!(
        events[3]["payload"]["parallel_group_id"],
        events[4]["payload"]["parallel_group_id"]
    );
    snapshot(&database, &app, &run).await;
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn workflow_failure_attributes_only_the_exact_latest_activity_failure() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(
        &app,
        "failure-worker",
        json!(["echo"]),
        json!(["echo_activity"]),
    )
    .await;
    for exact in [false, true] {
        let run = start(&app, &format!("workflow-failure-activity-{exact}")).await;
        let task = poll(&app, "failure-worker", "workflow").await;
        assert_eq!(finish_task(&app,&task,json!([{"type":"schedule_activity","activity_type":"echo_activity","arguments":envelope(Payload::Array(vec![]))}])).await.0,StatusCode::OK);
        let activity = poll(&app, "failure-worker", "activity").await;
        let failed=request(&app,"POST",&format!("/api/worker/activity-tasks/{}/fail",activity["task_id"].as_str().unwrap()),
            json!({"lease_owner":activity["lease_owner"],"activity_attempt_id":activity["activity_attempt_id"],"failure":{"type":"RuntimeException","message":"original activity","non_retryable":true}})).await;
        assert_eq!(failed.0, StatusCode::OK, "{}", failed.1);
        let resumed = poll(&app, "failure-worker", "workflow").await;
        let activity_history = history(&app, &run).await;
        let activity_failure = &activity_history.as_array().unwrap().last().unwrap()["payload"];
        let mut terminal = command();
        terminal["failed_step_sequence"] = json!(1);
        terminal["failed_activity_execution_id"] = if exact {
            activity["activity_execution_id"].clone()
        } else {
            json!("different-original")
        };
        assert_eq!(
            finish_task(&app, &resumed, json!([terminal])).await.0,
            StatusCode::OK
        );
        let terminal_history = history(&app, &run).await;
        let payload = &terminal_history.as_array().unwrap().last().unwrap()["payload"];
        assert_ne!(payload["failure_id"], activity_failure["failure_id"]);
        assert_eq!(payload.get("failed_step_sequence").is_some(), exact);
        if exact {
            assert_eq!(payload["failed_step_kind"], "activity");
            assert_eq!(payload["failed_step_sequence"], 1);
        }
    }
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn workflow_failure_rolls_back_child_and_parent_when_parent_notification_fails() {
    if std::env::var_os("DW_EXECUTION_POSTGRES_URL").is_some()
        || std::env::var_os("DW_EXECUTION_MYSQL_URL").is_some()
    {
        return;
    }
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "failure-parent", json!(["echo"]), json!([])).await;
    register(&app, "failure-child", json!(["child"]), json!([])).await;
    let parent = start(&app, "failed-child-rollback").await;
    let initial = poll(&app, "failure-parent", "workflow").await;
    assert_eq!(
        finish_task(&app, &initial, json!([child_command(1)]))
            .await
            .0,
        StatusCode::OK
    );
    let child = poll(&app, "failure-child", "workflow").await;
    let before = run_history(&app, &child["workflow_id"], &child["run_id"]).await;
    let parent_before = history(&app, &parent).await;
    database.execute("CREATE TRIGGER fail_child_notification BEFORE INSERT ON workflow_history_events WHEN NEW.event_type='ChildRunFailed' BEGIN SELECT RAISE(ABORT,'fixture notification failure'); END").await;
    let failed = finish_task(&app, &child, json!([command()])).await;
    assert_eq!(failed.0, StatusCode::SERVICE_UNAVAILABLE, "{}", failed.1);
    assert_eq!(
        run_history(&app, &child["workflow_id"], &child["run_id"]).await,
        before
    );
    assert_eq!(history(&app, &parent).await, parent_before);
    assert_eq!(
        task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_failures").await,
        0
    );
    assert_eq!(
        task_retry::rows(
            &database,
            "SELECT COUNT(*) FROM workflow_tasks WHERE status='leased'"
        )
        .await,
        1
    );
    database
        .execute("DROP TRIGGER fail_child_notification")
        .await;
    assert_eq!(
        finish_task(&app, &child, json!([command()])).await.0,
        StatusCode::OK
    );
    runtime.close().await;
    database.remove().await;
}
