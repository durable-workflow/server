use super::*;

#[tokio::test]
async fn cancellation_retains_original_timer_selection_metadata_across_reopen() {
    let database = TestDatabase::new().await;
    let first = database.open().await.unwrap();
    let app = router(first.clone());
    register(
        &app,
        "selection-cancel",
        json!(["echo"]),
        json!(["echo_activity"]),
    )
    .await;
    let started = start(&app, "selection-cancel-metadata").await;
    let task = poll(&app, "selection-cancel", "workflow").await;
    let mut commands = mixed_commands(2);
    commands[2]["delay_seconds"] = json!(60);
    assert_eq!(finish_task(&app, &task, commands).await.0, StatusCode::OK);
    let before = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let original = before
        .as_array()
        .unwrap()
        .iter()
        .find(|e| e["event_type"] == "TimerScheduled")
        .unwrap()["payload"]
        .clone();
    assert!(original["parallel_group_path"].is_array());
    let cancelled = request(
        &app,
        "POST",
        &format!(
            "/api/workflows/{}/cancel",
            started["workflow_id"].as_str().unwrap()
        ),
        json!({"reason":"retain authored timer identity"}),
    )
    .await;
    assert!(cancelled.0.is_success(), "{}", cancelled.1);
    drop(app);
    first.close().await;
    let second = database.open().await.unwrap();
    let app = router(second.clone());
    let after = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(
        &after.as_array().unwrap()[..before.as_array().unwrap().len()],
        before.as_array().unwrap()
    );
    let events = after
        .as_array()
        .unwrap()
        .iter()
        .filter(|e| e["event_type"] == "TimerCancelled")
        .collect::<Vec<_>>();
    assert_eq!(events.len(), 1);
    let actual = &events[0]["payload"];
    for field in ["timer_id", "sequence", "delay_seconds", "fire_at"] {
        assert_eq!(actual[field], original[field], "{field}");
    }
    for (field, value) in original.as_object().unwrap() {
        if field.starts_with("parallel_group_") || field.starts_with("selection_member_") {
            assert_eq!(&actual[field], value, "{field}");
        }
    }
    second.close().await;
    database.remove().await;
}

async fn observe_selection(database: &TestDatabase, app: &Router, started: &Value) -> Value {
    let summary = run_observation_active_summaries::observe(app, "default", started).await;
    assert_eq!(summary["summary_state"], "observed", "{summary}");
    assert!(summary["history_fan_out"].as_i64().unwrap() >= 2);
    run_observation_summaries::export_reference(database, &summary, &summary["history"]).await;
    summary
}

impl TestDatabase {
    async fn selection_history_id(&self, run: &str, sequence: i64) -> String {
        // Public HTTP history intentionally omits the internal event ID.
        // Verify the winner's physical relationship against the database,
        // rather than synthesizing an ID in the public representation.
        let query =
            "SELECT id FROM workflow_history_events WHERE workflow_run_id=$1 AND sequence=$2";
        match self {
            Self::Sqlite(dir) => {
                let pool = sqlx::SqlitePool::connect(&format!(
                    "sqlite://{}",
                    dir.path().join("runtime.sqlite").display()
                ))
                .await
                .unwrap();
                let identity = sqlx::query_scalar::<_, String>(query)
                    .bind(run)
                    .bind(sequence)
                    .fetch_one(&pool)
                    .await
                    .unwrap();
                pool.close().await;
                identity
            }
            Self::Postgres { options, .. } => {
                let pool = sqlx::postgres::PgPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap();
                let identity = sqlx::query_scalar::<_, String>(query)
                    .bind(run)
                    .bind(sequence)
                    .fetch_one(&pool)
                    .await
                    .unwrap();
                pool.close().await;
                identity
            }
            Self::MySql { options, .. } => {
                let pool = sqlx::mysql::MySqlPoolOptions::new()
                    .connect_with(options.as_ref().clone())
                    .await
                    .unwrap();
                let identity = sqlx::query_scalar::<_, String>(
                    "SELECT id FROM workflow_history_events WHERE workflow_run_id=? AND sequence=?",
                )
                .bind(run)
                .bind(sequence)
                .fetch_one(&pool)
                .await
                .unwrap();
                pool.close().await;
                identity
            }
        }
    }
}

pub(super) fn mixed_commands(base: i64) -> Value {
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

fn selection_pair(base: usize, group_kind: &str, members: [(Value, &str, &str); 2]) -> Value {
    let commands = members.into_iter().enumerate().map(|(index, (mut command, key, kind))| {
        let member = json!({
            "parallel_group_id": format!("select-calls:{base}:2"),
            "parallel_group_kind": group_kind,
            "parallel_group_mode": "select",
            "parallel_group_base_sequence": base,
            "parallel_group_size": 2,
            "parallel_group_index": index,
            "selection_member_key": key,
            "selection_member_index": index,
            "selection_member_base_sequence": base + index,
            "selection_member_size": 1,
            "selection_member_kind": kind,
        });
        command.as_object_mut().unwrap().extend(member.as_object().unwrap().clone());
        command["parallel_group_path"] = json!([member]);
        command
    }).collect();
    Value::Array(commands)
}

fn child_and_signal_selection() -> Value {
    let child = json!({
        "type": "start_child_workflow",
        "workflow_type": "selection-child",
        "payload_codec": "avro",
        "arguments": envelope(Payload::Array(vec![Payload::String("loser".into())])),
    });
    let signal = json!({"type": "open_signal_wait", "signal_name": "first"});
    selection_pair(1, "mixed", [(child, "child", "child"), (signal, "first", "signal")])
}

fn nested_child_selection(children_first: bool) -> Value {
    let (child_base, signal_index) = if children_first { (1, 2) } else { (2, 0) };
    let selection = |offset, key, logical_index, base, size, kind| json!({
        "parallel_group_id": "select-calls:1:3",
        "parallel_group_kind": "mixed",
        "parallel_group_mode": "select",
        "parallel_group_base_sequence": 1,
        "parallel_group_size": 3,
        "parallel_group_index": offset,
        "selection_member_key": key,
        "selection_member_index": logical_index,
        "selection_member_base_sequence": base,
        "selection_member_size": size,
        "selection_member_kind": kind,
    });
    let mut signal = selection(signal_index, "first", usize::from(children_first), signal_index + 1, 1, "signal");
    signal["parallel_group_path"] = json!([signal.clone()]);
    signal["type"] = json!("open_signal_wait");
    signal["signal_name"] = json!("first");
    let children = (0..2).map(|offset| {
        let mut child = json!({
            "parallel_group_id": format!("parallel-children:{child_base}:2"),
            "parallel_group_kind": "child",
            "parallel_group_base_sequence": child_base,
            "parallel_group_size": 2,
            "parallel_group_index": offset,
        });
        let outer = selection(child_base + offset - 1, "child", usize::from(!children_first), child_base, 2, "group");
        child["parallel_group_path"] = json!([outer, child.clone()]);
        child["type"] = json!("start_child_workflow");
        child["workflow_type"] = json!("selection-child");
        child["payload_codec"] = json!("avro");
        child["arguments"] = envelope(Payload::Array(vec![Payload::Long(offset as i64)]));
        child
    });
    let mut commands = Vec::with_capacity(3);
    if children_first {
        commands.extend(children);
        commands.push(signal);
    } else {
        commands.push(signal);
        commands.extend(children);
    }
    json!(commands)
}

#[tokio::test]
async fn signal_can_win_a_selection_while_the_child_group_remains_available() {
    recover_selected_child(true).await;
}

#[tokio::test]
async fn signal_can_win_a_selection_while_the_started_child_remains_available() {
    recover_selected_child(false).await;
}

async fn recover_selected_child(nested: bool) {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "selection-parent-worker", json!(["echo"]), json!([])).await;
    register(&app, "selection-child-worker", json!(["selection-child"]), json!([])).await;
    let started = start(&app, "child-signal-selection").await;
    let initial = poll(&app, "selection-parent-worker", "workflow").await;
    let initial_commands = if nested { nested_child_selection(true) } else { child_and_signal_selection() };
    if nested {
        let mut invalid = initial_commands.clone();
        invalid[1]["parallel_group_path"][0]["selection_member_base_sequence"] = json!(2);
        let before = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
        assert_eq!(finish_task(&app, &initial, invalid).await.0, StatusCode::UNPROCESSABLE_ENTITY);
        assert_eq!(run_history(&app, &started["workflow_id"], &started["run_id"]).await, before);
    }
    let (status, scheduled) = finish_task(&app, &initial, initial_commands).await;
    assert_eq!(status, StatusCode::OK, "{scheduled}");
    let selection_size = if nested { 3 } else { 2 };
    let selected_group = format!("select-calls:1:{selection_size}");
    let subsequent_group = format!("select-calls:{}:2", selection_size + 1);
    let child_task = poll(&app, "selection-child-worker", "workflow").await;
    assert!(child_task["task_id"].is_string(), "{child_task}");
    let (status, signal) = request(&app, "POST", "/api/workflows/child-signal-selection/signal/first",
        json!({"input":envelope(Payload::Array(vec![Payload::Long(1)]))})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{signal}");
    let next = poll(&app, "selection-parent-worker", "workflow").await;
    let later = selection_pair(selection_size + 1, "signal", [
        (json!({"type":"open_signal_wait","signal_name":"second"}), "second", "signal"),
        (json!({"type":"open_signal_wait","signal_name":"third"}), "third", "signal"),
    ]);
    let (status, scheduled) = finish_task(&app, &next, later).await;
    assert_eq!(status, StatusCode::OK, "{scheduled}");
    let (status, signal) = request(&app, "POST", "/api/workflows/child-signal-selection/signal/second",
        json!({"input":envelope(Payload::Array(vec![Payload::Long(2)]))})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{signal}");
    let next = poll(&app, "selection-parent-worker", "workflow").await;
    let waiting_path = format!("/api/worker/workflow-tasks/{}/fail", next["task_id"].as_str().unwrap());
    let handle = json!({
        "selection_group_id": selected_group,
        "member_base_sequence": 1,
        "member_size": selection_size - 1,
        "operation_identity": if nested { json!("group:1:2") } else { child_task["run_id"].clone() },
        "await_position": selection_size + 3,
        "status": "waiting",
    });
    let waiting = json!({
        "lease_owner": next["lease_owner"],
        "workflow_task_attempt": next["workflow_task_attempt"],
        "failure": {
            "type": "WorkflowTaskWaitingForHistory",
            "message": "Waiting for the original child.",
            "selection_handle": handle,
        },
    });
    let mut mismatched = waiting.clone();
    mismatched["failure"]["selection_handle"]["selection_group_id"] = json!(&subsequent_group);
    let before = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let (status, refused) = request(&app, "POST", &waiting_path, mismatched).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{}", refused["reason"]);
    assert_eq!(refused["reason"], "invalid_child_selection_await");
    assert_eq!(run_history(&app, &started["workflow_id"], &started["run_id"]).await, before);
    let (status, acknowledged) = request(&app, "POST", &waiting_path, waiting).await;
    assert_eq!(status, StatusCode::OK, "{acknowledged}");
    assert_eq!(acknowledged["outcome"], "waiting_for_history");
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let events = history.as_array().unwrap();
    let recorded_wait = events.iter().filter(|event| event["event_type"] == "ChildWorkflowAwaited").collect::<Vec<_>>();
    assert_eq!(recorded_wait.len(), 1);
    assert_eq!(recorded_wait[0]["payload"]["selection_handle"], handle);
    let summary = observe_selection(&database, &app, &started).await;
    assert_eq!(summary["liveness_state"], "waiting_for_child");
    for (group_id, signal_name, index) in [(&selected_group, "first", 1), (&subsequent_group, "second", 0)] {
        let opened = events.iter().find(|event| event["event_type"] == "SignalWaitOpened"
            && event["payload"]["signal_name"] == signal_name).unwrap();
        assert_eq!(opened["payload"]["selection_member_key"], signal_name);
        let winner = events.iter().find(|event| event["event_type"] == "SelectionResolved"
            && event["payload"]["selection_group_id"] == group_id.as_str()).unwrap();
        assert_eq!(winner["payload"]["operation_kind"], "signal");
        assert_eq!(winner["payload"]["operation_identity"], opened["payload"]["signal_wait_id"]);
        assert_eq!(winner["payload"]["member_index"], index);
    }
    let (status, completed) = finish_task(&app, &child_task,
        json!([{"type":"complete_workflow","result":envelope(Payload::Long(7))}])).await;
    assert_eq!(status, StatusCode::OK, "{completed}");
    if nested {
        assert!(poll(&app, "selection-parent-worker", "workflow").await.is_null());
        let summary = observe_selection(&database, &app, &started).await;
        assert_eq!(summary["liveness_state"], "waiting_for_child");
        let second = poll(&app, "selection-child-worker", "workflow").await;
        assert_ne!(second["run_id"], child_task["run_id"]);
        let (status, completed) = finish_task(&app, &second,
            json!([{"type":"complete_workflow","result":envelope(Payload::Long(8))}])).await;
        assert_eq!(status, StatusCode::OK, "{completed}");
    }
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(history.as_array().unwrap().iter().filter(|event| event["event_type"] == "SelectionResolved").count(), 2);
    assert!(history.as_array().unwrap().iter().any(|event| event["event_type"] == "ChildRunCompleted"));
    let resumed = poll(&app, "selection-parent-worker", "workflow").await;
    assert_eq!(resumed["run_id"], started["run_id"], "{resumed}");
    let run_id = started["run_id"].as_str().unwrap();
    let lost_task = resumed["task_id"].as_str().unwrap();
    assert!(ulid::Ulid::from_string(lost_task).is_ok());
    database.execute(sqlx::AssertSqlSafe(format!(
        "DELETE FROM workflow_tasks WHERE id='{lost_task}' AND workflow_run_id='{run_id}'",
    ))).await;
    let repair_path = format!("/api/workflows/child-signal-selection/runs/{run_id}/repair");
    let (status, repair) = request(&app, "POST", &repair_path, json!({})).await;
    assert_eq!(status, StatusCode::OK, "{}", repair["reason"]);
    let restored = poll(&app, "selection-parent-worker", "workflow").await;
    assert_eq!(restored["run_id"], started["run_id"], "{restored}");
    assert_ne!(restored["task_id"], resumed["task_id"]);
    let mut consumed = handle;
    consumed["status"] = json!("resumed");
    let restored_path = format!("/api/worker/workflow-tasks/{}/complete", restored["task_id"].as_str().unwrap());
    let mut next_turn = completion(&restored, json!([{"type":"open_signal_wait","signal_name":"after"}]));
    next_turn["selection_handle"] = consumed.clone();
    let (status, result) = request(&app, "POST", &restored_path, next_turn.clone()).await;
    assert_eq!(status, StatusCode::OK, "{}", result["reason"]);
    let (status, duplicate) = request(&app, "POST", &restored_path, next_turn).await;
    assert_eq!(status, StatusCode::OK, "{}", duplicate["reason"]);
    assert_eq!(duplicate["recorded"], false);
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let markers: Vec<_> = history.as_array().unwrap().iter()
        .filter(|event| event["event_type"] == "ChildWorkflowAwaited").collect();
    assert_eq!(markers.len(), 2);
    assert_eq!(markers[1]["payload"]["selection_handle"], consumed);
    let summary = observe_selection(&database, &app, &started).await;
    assert_eq!(summary["liveness_state"], "waiting_for_signal");
    assert_eq!(summary["liveness_reason"], "Waiting for signal after.");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn child_can_win_a_selection_while_the_signal_wait_is_open() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "selection-parent-worker", json!(["echo"]), json!([])).await;
    register(&app, "selection-child-worker", json!(["selection-child"]), json!([])).await;
    let started = start(&app, "child-wins-selection").await;
    let initial = poll(&app, "selection-parent-worker", "workflow").await;
    assert_eq!(finish_task(&app, &initial, child_and_signal_selection()).await.0, StatusCode::OK);
    let child = poll(&app, "selection-child-worker", "workflow").await;
    let (status, completed) = finish_task(&app, &child,
        json!([{"type":"complete_workflow","result":envelope(Payload::Long(7))}])).await;
    assert_eq!(status, StatusCode::OK, "{completed}");
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let winner = history.as_array().unwrap().iter().find(|event| event["event_type"] == "SelectionResolved").unwrap();
    assert_eq!(winner["payload"]["operation_kind"], "child");
    assert_eq!(winner["payload"]["operation_identity"], child["run_id"]);
    assert_eq!(winner["payload"]["resolution_event_type"], "ChildRunCompleted");
    let resumed = poll(&app, "selection-parent-worker", "workflow").await;
    assert_eq!(resumed["run_id"], started["run_id"], "{resumed}");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn selected_child_group_wins_only_when_its_last_child_finishes() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "selection-parent-worker", json!(["echo"]), json!([])).await;
    register(&app, "selection-child-worker", json!(["selection-child"]), json!([])).await;
    let started = start(&app, "child-group-wins-selection").await;
    let initial = poll(&app, "selection-parent-worker", "workflow").await;
    let (status, scheduled) = finish_task(&app, &initial, nested_child_selection(false)).await;
    assert_eq!(status, StatusCode::OK, "{scheduled}");

    for value in [4, 9] {
        let child = poll(&app, "selection-child-worker", "workflow").await;
        let (status, completed) = finish_task(&app, &child,
            json!([{"type":"complete_workflow","result":envelope(Payload::Long(value))}])).await;
        assert_eq!(status, StatusCode::OK, "{completed}");
        if value == 4 {
            assert!(poll(&app, "selection-parent-worker", "workflow").await.is_null());
            let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
            assert!(history.as_array().unwrap().iter().all(|event| event["event_type"] != "SelectionResolved"));
        }
    }

    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let events = history.as_array().unwrap();
    let winner = events.iter().find(|event| event["event_type"] == "SelectionResolved").unwrap();
    let last = events.iter().rev().find(|event| event["event_type"] == "ChildRunCompleted").unwrap();
    let event_id = database.selection_history_id(
        started["run_id"].as_str().unwrap(), last["sequence"].as_i64().unwrap(),
    ).await;
    assert_eq!(winner["payload"]["member_index"], 1);
    assert_eq!(winner["payload"]["member_base_sequence"], 2);
    assert_eq!(winner["payload"]["member_size"], 2);
    assert_eq!(winner["payload"]["operation_kind"], "group");
    assert_eq!(winner["payload"]["operation_identity"], "group:2:2");
    assert_eq!(winner["payload"]["resolution_event_id"], event_id);
    assert_eq!(winner["payload"]["resolution_event_type"], "ChildRunCompleted");
    let resumed = poll(&app, "selection-parent-worker", "workflow").await;
    assert_eq!(resumed["run_id"], started["run_id"], "{resumed}");
    observe_selection(&database, &app, &started).await;
    runtime.close().await;
    database.remove().await;
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
    assert_eq!(
        observe_selection(&database, &app, &started).await["current_waits"]["returned_count"],
        2
    );
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
        observe_selection(&database, &app, &started).await["current_waits"]["returned_count"],
        1
    );
    assert_eq!(
        selected[6]["payload"]["resolution_event_id"],
        database
            .selection_history_id(
                started["run_id"].as_str().unwrap(),
                selected[5]["sequence"].as_i64().unwrap()
            )
            .await
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
    observe_selection(&database, &app, &started).await;
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
        observe_selection(&database, &app, &started).await["liveness_state"],
        "workflow_replay_blocked"
    );
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
    observe_selection(&database, &app, &started).await;
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
    observe_selection(&database, &app, &started).await;
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
    let mut activity_worker = json!({"worker_id":"activity-only","task_queue":"test","runtime":"php",
        "supported_workflow_types":[],"supported_activity_types":["echo_activity"],
        "workflow_command_contracts":[],"workflow_definition_fingerprints":[]});
    for invalid in [json!([{"echo":{}}]), json!(false), json!("empty")] {
        activity_worker["workflow_command_contracts"] = invalid;
        assert_eq!(
            request(
                &app,
                "POST",
                "/api/worker/register",
                activity_worker.clone()
            )
            .await
            .0,
            StatusCode::UNPROCESSABLE_ENTITY
        );
    }
    activity_worker["workflow_command_contracts"] = json!([]);
    assert_eq!(
        request(&app, "POST", "/api/worker/register", activity_worker)
            .await
            .0,
        StatusCode::CREATED
    );
    assert_eq!(
        run_history(&app, &started["workflow_id"], &started["run_id"]).await,
        repaired_history
    );
    assert_eq!(debug(&app, &started).await["pending_activities"], pending);
    let activity = poll(&app, "activity-only", "activity").await;
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
    run_observation_summaries::check_completed(&database, &app, "default", &started, 0, 2).await;
    assert!(poll(&app, "restored", "workflow").await.is_null());
    assert!(poll(&app, "activity-only", "activity").await.is_null());
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
    assert_eq!(
        observe_selection(&database, &app, &started).await["current_waits"]["returned_count"],
        2
    );
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
        observe_selection(&database, &app, &started).await["current_waits"]["returned_count"],
        1
    );
    assert_eq!(
        winner[7]["payload"]["operation_identity"],
        activity["activity_execution_id"]
    );
    assert_eq!(
        winner[7]["payload"]["resolution_event_id"],
        database
            .selection_history_id(
                started["run_id"].as_str().unwrap(),
                winner[6]["sequence"].as_i64().unwrap()
            )
            .await
    );
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
    observe_selection(&database, &app, &started).await;
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
    observe_selection(&database, &app, &started).await;
    assert_eq!(winner_task["history_events"].as_array().unwrap().len(), 7);
    let activity = poll(&app, "leased-selection", "activity").await;
    observe_selection(&database, &app, &started).await;
    assert!(
        activity["activity_execution_id"].is_string(),
        "activity lease: {activity}"
    );
    let completed = request(&app, "POST", &format!("/api/worker/activity-tasks/{}/complete", activity["task_id"].as_str().unwrap()),
        json!({"lease_owner":activity["lease_owner"],"activity_attempt_id":activity["activity_attempt_id"],
            "result":envelope(Payload::Long(9007199254740993))})).await;
    assert_eq!(completed.0, StatusCode::OK);
    assert_ne!(completed.1["next_task_id"], winner_task["task_id"]);
    observe_selection(&database, &app, &started).await;
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

#[tokio::test]
async fn selection_observation_timeout_winner_keeps_original_waits_and_failure() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(
        &app,
        "timeout-select",
        json!(["echo"]),
        json!(["echo_activity"]),
    )
    .await;
    let run = start(&app, "timeout-select").await;
    let task = poll(&app, "timeout-select", "workflow").await;
    let mut commands = mixed_commands(2);
    commands[1]["schedule_to_start_timeout"] = json!(1);
    commands[2]["delay_seconds"] = json!(3);
    assert_eq!(finish_task(&app, &task, commands).await.0, StatusCode::OK);
    observe_selection(&database, &app, &run).await;
    let first = request(
        &app,
        "POST",
        "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"timeout-select","task_queue":"test","timeout_seconds":5}),
    )
    .await;
    assert_eq!(first.0, StatusCode::OK, "{}", first.1);
    let first = first.1["task"].clone();
    assert_eq!(first["history_events"][5]["event_type"], "ActivityTimedOut");
    assert_eq!(
        first["history_events"][6]["event_type"],
        "SelectionResolved"
    );
    assert_eq!(first["history_events"][6]["payload"]["outcome"], "failed");
    let selected = observe_selection(&database, &app, &run).await;
    assert_eq!(selected["current_waits"]["returned_count"], 1);
    let history = wait_for_events(&app, &run, 8).await;
    assert_eq!(history[7]["event_type"], "TimerFired");
    assert_eq!(
        history
            .as_array()
            .unwrap()
            .iter()
            .filter(|event| event["event_type"] == "SelectionResolved")
            .count(),
        1
    );
    observe_selection(&database, &app, &run).await;
    assert_eq!(
        finish_task(
            &app,
            &first,
            json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}])
        )
        .await
        .0,
        StatusCode::OK
    );
    run_observation_summaries::check_completed(&database, &app, "default", &run, 1, 2).await;
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn selection_observation_deadline_retry_keeps_member_until_actual_completion() {
    let database = TestDatabase::new().await;
    let first = database.open().await.unwrap();
    let app = router(first.clone());
    register(
        &app,
        "retry-select",
        json!(["echo"]),
        json!(["echo_activity"]),
    )
    .await;
    let run = start(&app, "retry-select").await;
    let task = poll(&app, "retry-select", "workflow").await;
    let mut commands = mixed_commands(2);
    commands[1]["start_to_close_timeout"] = json!(1);
    commands[1]["retry_policy"] = json!({"max_attempts":2,"backoff_seconds":[0]});
    commands[2]["delay_seconds"] = json!(4);
    assert_eq!(finish_task(&app, &task, commands).await.0, StatusCode::OK);
    let activity = poll(&app, "retry-select", "activity").await;
    observe_selection(&database, &app, &run).await;
    let deadline = tokio::time::Instant::now() + std::time::Duration::from_secs(3);
    loop {
        let events = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
        if events
            .as_array()
            .unwrap()
            .iter()
            .any(|event| event["event_type"] == "ActivityRetryScheduled")
        {
            break;
        }
        assert!(tokio::time::Instant::now() < deadline, "{events}");
        tokio::time::sleep(std::time::Duration::from_millis(25)).await;
    }
    let retry = observe_selection(&database, &app, &run).await;
    assert_eq!(
        retry["current_waits"]["waits"]
            .as_array()
            .unwrap()
            .iter()
            .filter(|wait| wait["kind"] == "activity_retry")
            .count(),
        1
    );
    drop(app);
    first.close().await;
    let second = database.open().await.unwrap();
    let app = router(second.clone());
    let retried = poll(&app, "retry-select", "activity").await;
    assert_ne!(retried["task_id"], activity["task_id"]);
    assert_eq!(retried["attempt_number"], 2);
    observe_selection(&database, &app, &run).await;
    let response = request(&app,"POST",&format!("/api/worker/activity-tasks/{}/complete",retried["task_id"].as_str().unwrap()),json!({"lease_owner":retried["lease_owner"],"activity_attempt_id":retried["activity_attempt_id"],"result":envelope(Payload::Long(9007199254740993))})).await;
    assert_eq!(response.0, StatusCode::OK, "{}", response.1);
    observe_selection(&database, &app, &run).await;
    let resumed = poll(&app, "retry-select", "workflow").await;
    let events = wait_for_events(&app, &run, 11).await;
    assert_eq!(
        events
            .as_array()
            .unwrap()
            .iter()
            .filter(|event| event["event_type"] == "SelectionResolved")
            .count(),
        1
    );
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
    run_observation_summaries::check_completed(&database, &app, "default", &run, 0, 2).await;
    second.close().await;
    database.remove().await;
}

#[tokio::test]
async fn selection_observation_sqlite_failures_keep_original_selection_and_no_read_repair() {
    let database = TestDatabase::Sqlite(tempfile::tempdir().unwrap());
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(
        &app,
        "atomic-select",
        json!(["echo"]),
        json!(["echo_activity"]),
    )
    .await;
    let run = start(&app, "atomic-select").await;
    let task = poll(&app, "atomic-select", "workflow").await;
    database.execute("CREATE TRIGGER refuse_select_timer_wait BEFORE INSERT ON workflow_run_waits WHEN NEW.kind='timer' BEGIN SELECT RAISE(ABORT,'selection wait failure'); END").await;
    assert_eq!(
        finish_task(&app, &task, mixed_commands(2)).await.0,
        StatusCode::SERVICE_UNAVAILABLE
    );
    assert_eq!(
        task_retry::rows(&database, "SELECT COUNT(*) FROM activity_executions").await,
        0
    );
    assert_eq!(
        task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_run_timers").await,
        0
    );
    assert_eq!(
        task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_history_events").await,
        2
    );
    database
        .execute("DROP TRIGGER refuse_select_timer_wait")
        .await;
    assert_eq!(
        finish_task(&app, &task, mixed_commands(2)).await.0,
        StatusCode::OK
    );
    observe_selection(&database, &app, &run).await;
    let events = wait_for_events(&app, &run, 7).await;
    database.execute("CREATE TABLE saved_resolution AS SELECT payload FROM workflow_history_events WHERE event_type='SelectionResolved'").await;
    database.execute("UPDATE workflow_history_events SET payload=json_set(payload,'$.resolution_event_id','unrelated') WHERE event_type='SelectionResolved'").await;
    let claimed = poll(&app, "atomic-select", "workflow").await;
    for _ in 0..2 {
        assert_eq!(
            run_observation_active_summaries::observe(&app, "default", &run).await["summary_state"],
            "unavailable"
        );
    }
    assert_eq!(
        task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_run_summaries").await,
        0
    );
    database.execute("UPDATE workflow_history_events SET payload=(SELECT payload FROM saved_resolution) WHERE event_type='SelectionResolved'").await;
    let renewed = request(&app,"POST",&format!("/api/worker/workflow-tasks/{}/heartbeat",claimed["task_id"].as_str().unwrap()),json!({"lease_owner":claimed["lease_owner"],"workflow_task_attempt":claimed["workflow_task_attempt"]})).await;
    assert_eq!(renewed.0, StatusCode::OK, "{}", renewed.1);
    observe_selection(&database, &app, &run).await;
    assert_eq!(
        &run_history(&app, &run["workflow_id"], &run["run_id"])
            .await
            .as_array()
            .unwrap()[..7],
        events.as_array().unwrap()
    );
    database
        .execute("DELETE FROM workflow_run_waits WHERE kind='activity'")
        .await;
    let activity = poll(&app, "atomic-select", "activity").await;
    assert_eq!(
        run_observation_active_summaries::observe(&app, "default", &run).await["summary_state"],
        "unavailable"
    );
    assert_eq!(
        task_retry::rows(
            &database,
            "SELECT COUNT(*) FROM workflow_run_waits WHERE kind='activity'"
        )
        .await,
        0
    );
    let complete = request(&app,"POST",&format!("/api/worker/activity-tasks/{}/complete",activity["task_id"].as_str().unwrap()),json!({"lease_owner":activity["lease_owner"],"activity_attempt_id":activity["activity_attempt_id"],"result":envelope(Payload::Long(9007199254740993))})).await;
    assert_eq!(complete.0, StatusCode::OK, "{}", complete.1);
    assert_eq!(
        finish_task(
            &app,
            &claimed,
            json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}])
        )
        .await
        .0,
        StatusCode::OK
    );
    run_observation_summaries::check_completed(&database, &app, "default", &run, 0, 2).await;
    runtime.close().await;
    database.remove().await;
}
