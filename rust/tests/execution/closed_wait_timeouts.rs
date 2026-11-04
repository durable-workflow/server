use super::*;

fn waits() -> Vec<Value> {
    vec![
        json!({"type":"open_condition_wait","condition_key":"ready",
            "condition_wait_occurrence_id":"closed-wait:0","timeout_seconds":600}),
        json!({"type":"open_signal_wait","signal_name":"wake","timeout_seconds":600}),
    ]
}

async fn rows(database: &TestDatabase, table: &str, run: &Value) -> Vec<(String, String)> {
    let run = run.as_str().unwrap();
    let query = format!("SELECT id,status FROM {table} WHERE workflow_run_id='{run}' ORDER BY id");
    text_rows(database, &query, &query, &query).await
}

async fn closed(case: &str) {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "close-worker", json!(["echo"]), json!([])).await;

    // A different live run retains its own pending condition timeout.
    let peer = start(&app, "wait-close-peer").await;
    let peer_task = poll(&app, "close-worker", "workflow").await;
    assert_eq!(
        finish_task(&app, &peer_task, json!([waits()[0]])).await.0,
        StatusCode::OK
    );
    let peer_timers = rows(&database, "workflow_run_timers", &peer["run_id"]).await;
    let peer_tasks = database.timer_task_states(&peer["run_id"]).await;

    let run = start(&app, &format!("wait-close-{case}")).await;
    let task = poll(&app, "close-worker", "workflow").await;
    let mut commands = waits();
    // Ordinary timers retain PHP's existing completion/failure/continue behavior.
    commands.push(json!({"type":"start_timer","delay_seconds":600}));
    let terminal = match case {
        "complete" => Some(json!({"type":"complete_workflow","result":envelope(Payload::Null)})),
        "fail" => Some(json!({"type":"fail_workflow","message":"close wait probe"})),
        "continue" => Some(json!({"type":"continue_as_new"})),
        _ => None,
    };
    if let Some(command) = terminal {
        commands.push(command);
    }
    let commands = json!(commands);
    let completed = finish_task(&app, &task, commands.clone()).await;
    assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
    if matches!(case, "cancel" | "terminate") {
        let path = format!(
            "/api/workflows/{}/{case}",
            run["workflow_id"].as_str().unwrap()
        );
        let response = request(&app, "POST", &path, json!({"reason":"close wait probe"})).await;
        assert_eq!(response.0, StatusCode::OK, "{}", response.1);
    }

    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let mut expected = vec![
        "StartAccepted",
        "WorkflowStarted",
        "ConditionWaitOpened",
        "TimerScheduled",
        "SignalWaitOpened",
        "TimerScheduled",
        "TimerScheduled",
    ];
    match case {
        "complete" => expected.push("WorkflowCompleted"),
        "fail" => expected.push("WorkflowFailed"),
        "continue" => expected.push("WorkflowContinuedAsNew"),
        "cancel" => expected.extend([
            "CancelRequested",
            "TimerCancelled",
            "TimerCancelled",
            "TimerCancelled",
            "WorkflowCancelled",
        ]),
        "terminate" => expected.extend([
            "TerminateRequested",
            "TimerCancelled",
            "TimerCancelled",
            "TimerCancelled",
            "WorkflowTerminated",
        ]),
        _ => unreachable!(),
    }
    assert_eq!(
        history
            .as_array()
            .unwrap()
            .iter()
            .map(|event| event["event_type"].as_str().unwrap())
            .collect::<Vec<_>>(),
        expected
    );
    let all_cancelled = matches!(case, "cancel" | "terminate");
    let timers = rows(&database, "workflow_run_timers", &run["run_id"]).await;
    assert_eq!(timers.len(), 3);
    assert_eq!(
        timers
            .iter()
            .filter(|(_, status)| status == "cancelled")
            .count(),
        if all_cancelled { 3 } else { 2 }
    );
    assert_eq!(
        timers
            .iter()
            .filter(|(_, status)| status == "pending")
            .count(),
        usize::from(!all_cancelled)
    );
    let tasks = database.timer_task_states(&run["run_id"]).await;
    assert_eq!(tasks.len(), 3);
    assert_eq!(
        tasks
            .iter()
            .filter(|(_, status)| status == "cancelled")
            .count(),
        if all_cancelled { 3 } else { 2 }
    );
    assert_eq!(
        tasks.iter().filter(|(_, status)| status == "ready").count(),
        usize::from(!all_cancelled)
    );
    let lease_query = format!(
        "SELECT id,status FROM workflow_tasks WHERE workflow_run_id='{}' AND task_type='timer' AND status='cancelled' AND lease_expires_at IS NOT NULL",
        run["run_id"].as_str().unwrap()
    );
    assert!(
        text_rows(&database, &lease_query, &lease_query, &lease_query)
            .await
            .is_empty()
    );
    assert_eq!(
        rows(&database, "workflow_run_timers", &peer["run_id"]).await,
        peer_timers
    );
    assert_eq!(
        database.timer_task_states(&peer["run_id"]).await,
        peer_tasks
    );

    // A duplicate completion after a fresh database open cannot recreate or
    // re-cancel timers, or add events to either original run.
    drop(app);
    runtime.close().await;
    let cold = database.open().await.unwrap();
    let app = router(cold.clone());
    let duplicate = finish_task(&app, &task, commands).await;
    assert_eq!(duplicate.0, StatusCode::OK, "{}", duplicate.1);
    assert_eq!(duplicate.1["recorded"], false);
    assert_eq!(
        run_history(&app, &run["workflow_id"], &run["run_id"]).await,
        history
    );
    assert_eq!(
        rows(&database, "workflow_run_timers", &run["run_id"]).await,
        timers
    );
    assert_eq!(database.timer_task_states(&run["run_id"]).await, tasks);
    assert_eq!(
        rows(&database, "workflow_run_timers", &peer["run_id"]).await,
        peer_timers
    );
    cold.close().await;
    database.remove().await;
}

#[tokio::test]
async fn completion_settles_only_wait_timeouts_and_preserves_php_history() {
    closed("complete").await;
}

#[tokio::test]
async fn failure_settles_only_wait_timeouts_and_preserves_php_history() {
    closed("fail").await;
}

#[tokio::test]
async fn continuation_settles_only_predecessor_wait_timeouts_and_preserves_php_history() {
    closed("continue").await;
}

#[tokio::test]
async fn cancellation_keeps_its_existing_timer_cancellation_history() {
    closed("cancel").await;
}

#[tokio::test]
async fn termination_keeps_its_existing_timer_cancellation_history() {
    closed("terminate").await;
}

async fn later_completion(missing_projection: bool, missing_task: bool) {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(
        &app,
        "later-worker",
        json!(["echo"]),
        json!(["activity-echo"]),
    )
    .await;
    let run = start(&app, "wait-close-later").await;
    let first = poll(&app, "later-worker", "workflow").await;
    let mut commands = waits();
    commands.push(json!({"type":"schedule_activity","activity_type":"activity-echo","arguments":envelope(Payload::Array(vec![]))}));
    assert_eq!(
        finish_task(&app, &first, json!(commands)).await.0,
        StatusCode::OK
    );
    let activity = poll(&app, "later-worker", "activity").await;
    let path = format!(
        "/api/worker/activity-tasks/{}/complete",
        activity["task_id"].as_str().unwrap()
    );
    let done = request(
        &app,
        "POST",
        &path,
        json!({"lease_owner":activity["lease_owner"],
        "activity_attempt_id":activity["activity_attempt_id"],"result":envelope(Payload::Null)}),
    )
    .await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    if missing_projection {
        // Cleanup uses scheduling history even if a retained projection or
        // physical task is unavailable; it must not rebuild deleted rows.
        let id = run["run_id"].as_str().unwrap();
        database.execute(sqlx::AssertSqlSafe(format!("DELETE FROM workflow_run_waits WHERE workflow_run_id='{id}' AND kind IN ('condition','signal')"))).await;
    }
    if missing_task {
        let id = run["run_id"].as_str().unwrap();
        database
            .execute(sqlx::AssertSqlSafe(format!(
                "DELETE FROM workflow_tasks WHERE workflow_run_id='{id}' AND task_type='timer'"
            )))
            .await;
    }
    if !missing_task {
        let id = run["run_id"].as_str().unwrap();
        let lease_until = (chrono::Utc::now() + chrono::Duration::hours(1))
            .format("%Y-%m-%d %H:%M:%S%.6f")
            .to_string();
        database.execute(sqlx::AssertSqlSafe(format!("UPDATE workflow_tasks SET status='leased',lease_expires_at='{lease_until}' WHERE workflow_run_id='{id}' AND task_type='timer'"))).await;
    }
    drop(app);
    runtime.close().await;
    let cold = database.open().await.unwrap();
    let app = router(cold.clone());
    let resumed = poll(&app, "later-worker", "workflow").await;
    assert_eq!(resumed["run_id"], run["run_id"]);
    let before = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    if missing_task && !missing_projection {
        let id = run["run_id"].as_str().unwrap();
        let surviving = format!(
            "SELECT id,task_status FROM workflow_run_waits WHERE workflow_run_id='{id}' AND kind IN ('condition','signal') AND task_backed=TRUE AND task_status='ready'"
        );
        assert_eq!(
            text_rows(&database, &surviving, &surviving, &surviving)
                .await
                .len(),
            2,
            "both task-backed projections survive without physical timer tasks before close"
        );
    }
    let complete = json!([{"type":"complete_workflow","result":envelope(Payload::Null)}]);
    let finished = finish_task(&app, &resumed, complete.clone()).await;
    assert_eq!(finished.0, StatusCode::OK, "{}", finished.1);
    assert_eq!(finished.1["run_status"], "completed");
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let events = history.as_array().unwrap();
    assert_eq!(
        &events[..events.len() - 1],
        before.as_array().unwrap().as_slice()
    );
    assert_eq!(events.last().unwrap()["event_type"], "WorkflowCompleted");
    let timers = rows(&database, "workflow_run_timers", &run["run_id"]).await;
    assert_eq!(timers.len(), 2);
    assert!(timers.iter().all(|(_, state)| state == "cancelled"));
    let tasks = database.timer_task_states(&run["run_id"]).await;
    assert_eq!(tasks.len(), if missing_task { 0 } else { 2 });
    assert!(tasks.iter().all(|(_, state)| state == "cancelled"));
    if missing_projection {
        let query = format!(
            "SELECT id,status FROM workflow_run_waits WHERE workflow_run_id='{}' AND kind IN ('condition','signal')",
            run["run_id"].as_str().unwrap()
        );
    } else {
        let id = run["run_id"].as_str().unwrap();
        let waits = format!(
            "SELECT id,status FROM workflow_run_waits WHERE workflow_run_id='{id}' AND kind IN ('condition','signal')"
        );
        let states = text_rows(&database, &waits, &waits, &waits).await;
        assert_eq!(states.len(), 2);
        assert!(states.iter().all(|(_, status)| status == "cancelled"));
        let backed = format!(
            "SELECT id,task_status FROM workflow_run_waits WHERE workflow_run_id='{id}' AND kind IN ('condition','signal') AND (task_backed=TRUE OR task_status<>'cancelled' OR task_status IS NULL)"
        );
        assert!(
            text_rows(&database, &backed, &backed, &backed)
                .await
                .is_empty(),
            "terminal waits retain no ready/leased task association"
        );
        assert!(
            text_rows(&database, &query, &query, &query)
                .await
                .is_empty()
        );
    }
    assert_eq!(
        finish_task(&app, &resumed, complete).await.1["recorded"],
        false
    );
    assert_eq!(
        run_history(&app, &run["workflow_id"], &run["run_id"]).await,
        history
    );
    cold.close().await;
    database.remove().await;
}

#[tokio::test]
async fn later_task_closes_original_timeouts_after_database_reopen() {
    later_completion(false, false).await;
}

#[tokio::test]
async fn retained_projection_or_task_absence_does_not_hide_pending_timeout_rows() {
    later_completion(true, true).await;
}

#[tokio::test]
async fn absent_timer_tasks_still_clear_surviving_wait_projection_associations() {
    later_completion(false, true).await;
}
