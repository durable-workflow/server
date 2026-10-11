use super::*;

fn mixed_commands(base: i64) -> Value {
    let mut activity = json!({"type":"schedule_activity","activity_type":"echo_activity",
        "arguments":envelope(Payload::Array(vec![Payload::Long(9007199254740993)]))});
    let mut timer = json!({"type":"start_timer","delay_seconds":1});
    for (index, (command, kind)) in [(&mut activity, "activity"), (&mut timer, "timer")]
        .into_iter()
        .enumerate()
    {
        let entry = json!({"parallel_group_id":format!("select-calls:{base}:2"),"parallel_group_kind":"mixed",
            "parallel_group_mode":"select","parallel_group_base_sequence":base,"parallel_group_size":2,
            "parallel_group_index":index,"selection_member_key":kind,"selection_member_index":index,
            "selection_member_base_sequence":base+index as i64,"selection_member_size":1,"selection_member_kind":kind});
        command
            .as_object_mut()
            .unwrap()
            .extend(entry.as_object().unwrap().clone());
        command["parallel_group_path"] = json!([entry]);
    }
    json!([{"type":"record_version_marker","change_id":"pending-repair","version":1,"min_supported":1,"max_supported":1}, activity, timer])
}

async fn debug(app: &Router, started: &Value) -> Value {
    let (status, value) = request(
        app,
        "GET",
        &format!(
            "/api/workflows/{}/runs/{}/debug",
            started["workflow_id"].as_str().unwrap(),
            started["run_id"].as_str().unwrap()
        ),
        Value::Null,
    )
    .await;
    assert_eq!(status, StatusCode::OK, "{value}");
    value
}

async fn wait_for_events(app: &Router, started: &Value, count: usize) -> Value {
    let deadline = tokio::time::Instant::now() + std::time::Duration::from_secs(10);
    loop {
        let events = run_history(app, &started["workflow_id"], &started["run_id"]).await;
        if events.as_array().unwrap().len() == count {
            return events;
        }
        assert!(
            tokio::time::Instant::now() < deadline,
            "expected {count} events: {events}"
        );
        tokio::time::sleep(std::time::Duration::from_millis(25)).await;
    }
}

#[tokio::test]
async fn timer_winner_and_pending_activity_survive_original_task_repair_and_restarts() {
    let database = TestDatabase::new().await;
    let first = database.open().await.unwrap();
    let app = router(first.clone());
    register(&app, "original", json!(["echo"]), json!(["echo_activity"])).await;
    let started = start(&app, "pending-select-repair").await;
    let initial = poll(&app, "original", "workflow").await;
    let commands = mixed_commands(2);
    assert_eq!(
        finish_task(&app, &initial, commands.clone()).await.0,
        StatusCode::OK
    );
    assert_eq!(
        finish_task(&app, &initial, commands).await.1["recorded"],
        false
    );
    let prefix = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(prefix.as_array().unwrap().len(), 5);
    let pending = debug(&app, &started).await["pending_activities"].clone();
    assert_eq!(pending[0]["attempt_count"], 0);
    drop(app);
    first.close().await;

    let (one, two) = tokio::join!(database.open(), database.open());
    let (one, two) = (one.unwrap(), two.unwrap());
    let app = router(one.clone());
    let selected = wait_for_events(&app, &started, 7).await;
    assert_eq!(
        &selected.as_array().unwrap()[..5],
        prefix.as_array().unwrap()
    );
    assert_eq!(selected[5]["event_type"], "TimerFired");
    assert_eq!(selected[6]["event_type"], "SelectionResolved");
    assert_eq!(selected[6]["payload"]["operation_kind"], "timer");
    assert_eq!(
        selected[6]["payload"]["resolution_event_id"],
        selected[5]["id"]
    );
    assert_eq!(
        selected[6]["payload"]["operation_identity"],
        prefix[4]["payload"]["timer_id"]
    );
    assert!(
        chrono::DateTime::parse_from_rfc3339(selected[5]["payload"]["fired_at"].as_str().unwrap())
            .unwrap()
            >= chrono::DateTime::parse_from_rfc3339(
                prefix[4]["payload"]["fire_at"].as_str().unwrap()
            )
            .unwrap()
    );
    register(&app, "incompatible", json!(["echo"]), json!([])).await;
    let blocked = poll(&app, "incompatible", "workflow").await;
    let failure = json!({"lease_owner":blocked["lease_owner"],"workflow_task_attempt":blocked["workflow_task_attempt"],
        "failure":{"type":"DurableWorkflow\\Exception\\NonDeterministicWorkflow","message":"version marker outside supported range",
            "reason":"version_marker_incompatible_range","sequence":1}});
    let fail_path = format!(
        "/api/worker/workflow-tasks/{}/fail",
        blocked["task_id"].as_str().unwrap()
    );
    assert_eq!(
        request(&app, "POST", &fail_path, failure).await.0,
        StatusCode::OK
    );
    assert_eq!(debug(&app, &started).await["pending_activities"], pending);
    assert_eq!(
        run_history(&app, &started["workflow_id"], &started["run_id"]).await,
        selected
    );
    drop(app);
    one.close().await;
    two.close().await;

    let restored = database.open().await.unwrap();
    let app = router(restored.clone());
    let repair_path = format!(
        "/api/workflows/{}/runs/{}/repair",
        started["workflow_id"].as_str().unwrap(),
        started["run_id"].as_str().unwrap()
    );
    assert_eq!(
        request(&app, "POST", &repair_path, json!({})).await.0,
        StatusCode::OK
    );
    let after = debug(&app, &started).await;
    assert_eq!(after["pending_activities"], pending);
    assert_eq!(
        after["pending_workflow_tasks"][0]["task_id"],
        blocked["task_id"]
    );
    assert_eq!(after["pending_workflow_tasks"][0]["repair_count"], 1);
    let repaired_history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let repair = &repaired_history[7]["payload"];
    assert_eq!(repair["task_id"], blocked["task_id"]);
    assert_eq!(repair["task_type"], "workflow");
    assert_eq!(repair["wait_kind"], "activity");
    let decoded = ValueCodec::new()
        .unwrap()
        .decode(repair["command"]["payload"]["blob"].as_str().unwrap())
        .unwrap();
    assert_eq!(
        decoded,
        Payload::Map(std::collections::BTreeMap::from([
            (
                "liveness_state".into(),
                Payload::String("workflow_replay_blocked".into())
            ),
            ("wait_kind".into(), Payload::String("activity".into())),
            (
                "task_id".into(),
                Payload::String(blocked["task_id"].as_str().unwrap().into())
            ),
            ("task_type".into(), Payload::String("workflow".into())),
        ]))
    );
    register(&app, "restored", json!(["echo"]), json!(["echo_activity"])).await;
    let repaired = poll(&app, "restored", "workflow").await;
    assert_eq!(repaired["task_id"], blocked["task_id"]);
    assert_eq!(repaired["workflow_task_attempt"], 2);
    assert_eq!(
        finish_task(
            &app,
            &blocked,
            json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])
        )
        .await
        .0,
        StatusCode::CONFLICT
    );
    let waiting = json!({"lease_owner":repaired["lease_owner"],"workflow_task_attempt":2,
        "failure":{"type":"WorkflowTaskWaitingForHistory","message":"workflow task waiting for scheduled history"}});
    assert_eq!(
        request(&app, "POST", &fail_path, waiting).await.1["outcome"],
        "waiting_for_history"
    );
    assert_eq!(
        run_history(&app, &started["workflow_id"], &started["run_id"]).await,
        repaired_history
    );
    assert_eq!(debug(&app, &started).await["pending_activities"], pending);
    let activity = poll(&app, "restored", "activity").await;
    assert!(
        activity["activity_execution_id"].is_string(),
        "activity lease: {activity}"
    );
    assert_eq!(
        activity["activity_execution_id"],
        prefix[3]["payload"]["activity_execution_id"]
    );
    let (status,value) = request(&app,"POST",&format!("/api/worker/activity-tasks/{}/complete",activity["task_id"].as_str().unwrap()),
        json!({"lease_owner":activity["lease_owner"],"activity_attempt_id":activity["activity_attempt_id"],"result":envelope(Payload::Long(9007199254740993))})).await;
    assert_eq!(status, StatusCode::OK, "{value}");
    let final_task = poll(&app, "restored", "workflow").await;
    assert_ne!(final_task["task_id"], blocked["task_id"]);
    assert_eq!(
        finish_task(
            &app,
            &final_task,
            json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}])
        )
        .await
        .0,
        StatusCode::OK
    );
    let events = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(events.as_array().unwrap().len(), 11);
    assert_eq!(
        &events.as_array().unwrap()[..8],
        repaired_history.as_array().unwrap()
    );
    assert_eq!(events[10]["event_type"], "WorkflowCompleted");
    assert!(poll(&app, "restored", "workflow").await.is_null());
    assert!(poll(&app, "restored", "activity").await.is_null());
    restored.close().await;
    database.remove().await;
}

#[tokio::test]
async fn activity_winner_survives_restart_and_losing_timer_without_another_wakeup() {
    let database = TestDatabase::new().await;
    let first = database.open().await.unwrap();
    let app = router(first.clone());
    register(
        &app,
        "activity-first",
        json!(["echo"]),
        json!(["echo_activity"]),
    )
    .await;
    let started = start(&app, "activity-first-selection").await;
    let initial = poll(&app, "activity-first", "workflow").await;
    let mut commands = mixed_commands(2);
    commands[2]["delay_seconds"] = json!(60);
    assert_eq!(
        finish_task(&app, &initial, commands).await.0,
        StatusCode::OK
    );
    let activity = poll(&app, "activity-first", "activity").await;
    assert!(
        activity["activity_execution_id"].is_string(),
        "activity lease: {activity}"
    );
    let before_failure = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let task_id = activity["task_id"].as_str().unwrap();
    let failure = request(&app, "POST", &format!("/api/worker/activity-tasks/{task_id}/fail"),
        json!({"lease_owner":activity["lease_owner"],"activity_attempt_id":activity["activity_attempt_id"],
            "failure":{"type":"RuntimeException","message":"selection failure unqualified","non_retryable":false}})).await;
    assert_eq!(failure.0, StatusCode::UNPROCESSABLE_ENTITY);
    assert_eq!(
        failure.1["reason"],
        "development_selection_activity_failure_unqualified"
    );
    assert_eq!(
        run_history(&app, &started["workflow_id"], &started["run_id"]).await,
        before_failure
    );
    let completion = json!({"lease_owner":activity["lease_owner"],"activity_attempt_id":activity["activity_attempt_id"],
        "result":envelope(Payload::Long(9007199254740993))});
    let path = format!("/api/worker/activity-tasks/{task_id}/complete");
    assert_eq!(
        request(&app, "POST", &path, completion.clone()).await.0,
        StatusCode::OK
    );
    assert_eq!(
        request(&app, "POST", &path, completion).await.1["recorded"],
        false
    );
    let winner = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(winner.as_array().unwrap().len(), 8);
    assert_eq!(winner[7]["event_type"], "SelectionResolved");
    assert_eq!(winner[7]["payload"]["operation_kind"], "activity");
    assert_eq!(
        winner[7]["payload"]["operation_identity"],
        activity["activity_execution_id"]
    );
    assert_eq!(winner[7]["payload"]["resolution_event_id"], winner[6]["id"]);
    drop(app);
    first.close().await;
    // Advance only this isolated test database's losing timer after the
    // activity winner is committed. No wall-clock race chooses the winner.
    database
        .execute(
            "UPDATE workflow_run_timers SET fire_at='2000-01-01 00:00:00' WHERE status='pending'",
        )
        .await;
    let (one, two) = tokio::join!(database.open(), database.open());
    let (one, two) = (one.unwrap(), two.unwrap());
    let app = router(one.clone());
    let other = router(two.clone());
    let after_timer = wait_for_events(&app, &started, 9).await;
    assert_eq!(
        &after_timer.as_array().unwrap()[..8],
        winner.as_array().unwrap()
    );
    assert_eq!(after_timer[8]["event_type"], "TimerFired");
    let (left, right) = tokio::join!(
        poll(&app, "activity-first", "workflow"),
        poll(&other, "activity-first", "workflow")
    );
    assert_ne!(
        left.is_null(),
        right.is_null(),
        "one original selection wakeup across pools"
    );
    let resumed = if left.is_null() { right } else { left };
    assert_eq!(
        finish_task(
            &app,
            &resumed,
            json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}])
        )
        .await
        .0,
        StatusCode::OK
    );
    assert_eq!(
        run_history(&app, &started["workflow_id"], &started["run_id"])
            .await
            .as_array()
            .unwrap()
            .len(),
        10
    );
    assert!(poll(&app, "activity-first", "workflow").await.is_null());
    assert!(poll(&app, "activity-first", "activity").await.is_null());
    one.close().await;
    two.close().await;
    database.remove().await;
}

#[tokio::test]
async fn losing_activity_preserves_a_later_wakeup_while_winner_workflow_is_leased() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(
        &app,
        "leased-selection",
        json!(["echo"]),
        json!(["echo_activity"]),
    )
    .await;
    let started = start(&app, "leased-selection-wakeup").await;
    let initial = poll(&app, "leased-selection", "workflow").await;
    assert_eq!(
        finish_task(&app, &initial, mixed_commands(2)).await.0,
        StatusCode::OK
    );
    wait_for_events(&app, &started, 7).await;
    let winner_task = poll(&app, "leased-selection", "workflow").await;
    assert_eq!(winner_task["history_events"].as_array().unwrap().len(), 7);
    let activity = poll(&app, "leased-selection", "activity").await;
    assert!(
        activity["activity_execution_id"].is_string(),
        "activity lease: {activity}"
    );
    let completed = request(&app, "POST", &format!("/api/worker/activity-tasks/{}/complete", activity["task_id"].as_str().unwrap()),
        json!({"lease_owner":activity["lease_owner"],"activity_attempt_id":activity["activity_attempt_id"],
            "result":envelope(Payload::Long(9007199254740993))})).await;
    assert_eq!(completed.0, StatusCode::OK);
    assert_ne!(completed.1["next_task_id"], winner_task["task_id"]);
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(history.as_array().unwrap().len(), 9);
    let waiting = request(&app, "POST", &format!("/api/worker/workflow-tasks/{}/fail", winner_task["task_id"].as_str().unwrap()),
        json!({"lease_owner":winner_task["lease_owner"],"workflow_task_attempt":winner_task["workflow_task_attempt"],
            "failure":{"type":"WorkflowTaskWaitingForHistory","message":"workflow task waiting for scheduled history"}})).await;
    assert_eq!(waiting.1["outcome"], "waiting_for_history");
    assert_eq!(
        run_history(&app, &started["workflow_id"], &started["run_id"]).await,
        history
    );
    let later = poll(&app, "leased-selection", "workflow").await;
    assert_eq!(later["task_id"], completed.1["next_task_id"]);
    assert_ne!(later["task_id"], winner_task["task_id"]);
    assert_eq!(later["history_events"].as_array().unwrap().len(), 9);
    assert_eq!(
        finish_task(
            &app,
            &later,
            json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}])
        )
        .await
        .0,
        StatusCode::OK
    );
    assert_eq!(
        run_history(&app, &started["workflow_id"], &started["run_id"])
            .await
            .as_array()
            .unwrap()
            .len(),
        10
    );
    assert!(poll(&app, "leased-selection", "workflow").await.is_null());
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn invalid_or_partial_selection_batches_leave_history_and_original_lease_usable() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "selection-validator", json!(["echo"]), json!([])).await;
    let started = start(&app, "selection-atomicity").await;
    let task = poll(&app, "selection-validator", "workflow").await;
    let prefix = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let valid = mixed_commands(2);
    let mut partial = valid.clone();
    partial.as_array_mut().unwrap().pop();
    let mut nested = valid.clone();
    let extra_path = nested[2]["parallel_group_path"][0].clone();
    nested[1]["parallel_group_path"]
        .as_array_mut()
        .unwrap()
        .push(extra_path);
    let mut duplicate = valid.clone();
    duplicate[2]["selection_member_key"] = json!("activity");
    duplicate[2]["parallel_group_path"][0]["selection_member_key"] = json!("activity");
    let mut zero_timer = valid.clone();
    zero_timer[2]["delay_seconds"] = json!(0);
    for bad in [partial, nested, duplicate, zero_timer] {
        assert_eq!(
            finish_task(&app, &task, bad).await.0,
            StatusCode::UNPROCESSABLE_ENTITY
        );
        assert_eq!(
            run_history(&app, &started["workflow_id"], &started["run_id"]).await,
            prefix
        );
    }
    assert_eq!(
        finish_task(&app, &task, mixed_commands(7)).await.0,
        StatusCode::CONFLICT
    );
    assert_eq!(
        run_history(&app, &started["workflow_id"], &started["run_id"]).await,
        prefix
    );
    assert_eq!(finish_task(&app, &task, valid).await.0, StatusCode::OK);
    runtime.close().await;
    database.remove().await;
}
