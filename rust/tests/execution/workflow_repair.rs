use super::*;

const OLD: &str = "2020-01-02 03:04:05.000000";

async fn repair(app: &Router, workflow: &str) -> (StatusCode, Value) {
    request(app, "POST", &format!("/api/workflows/{workflow}/repair"), json!([])).await
}

async fn repairs(app: &Router, started: &Value) -> Vec<Value> {
    let history = run_history(app, &started["workflow_id"], &started["run_id"]).await;
    history.as_array().unwrap().iter().filter(|event| event["event_type"] == "RepairRequested").cloned().collect()
}

// Published PHP repairs only what the run's summary says needs repair:
// an overdue ready task is redispatched, an expired workflow-task lease
// returns to ready, a healthy run records that no repair was needed, and a
// closed run's repair is a durable rejection.
#[tokio::test]
async fn repair_follows_the_run_summary_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let overdue = start(&app, "repair-overdue").await;
    database.execute(sqlx::AssertSqlSafe(format!(
        "UPDATE workflow_tasks SET available_at='{OLD}',created_at='{OLD}',last_dispatched_at='{OLD}',last_dispatch_attempt_at='{OLD}'"
    ))).await;
    let (status, dispatched) = repair(&app, "repair-overdue").await;
    assert_eq!(status, StatusCode::OK, "{dispatched}");
    assert_eq!((dispatched["outcome"].clone(), dispatched["command_status"].clone()), (json!("repair_dispatched"), json!("accepted")));
    assert_eq!(dispatched["control_plane"]["operation"], "repair");
    let event = &repairs(&app, &overdue).await[0]["payload"];
    assert_eq!((event["liveness_state"].clone(), event["wait_kind"].clone()), (json!("repair_needed"), json!("workflow-task")), "{event}");
    assert_eq!((event["task_type"].clone(), event["task"]["repair_count"].clone()), (json!("workflow"), json!(1)), "{event}");
    // The repair dispatched the task, so it is no longer overdue.
    let (status, again) = repair(&app, "repair-overdue").await;
    assert_eq!((status, again["outcome"].clone()), (StatusCode::OK, json!("repair_not_needed")), "{again}");
    let second = &repairs(&app, &overdue).await[1]["payload"];
    assert!(second["task_id"].is_null() && second.get("task").is_none(), "{second}");

    // The overdue run completes.
    register(&app, "worker", json!(["echo"]), json!([])).await;
    let task = poll(&app, "worker", "workflow").await;
    assert_eq!(task["workflow_id"], "repair-overdue", "{task}");
    let complete = json!({"type":"complete_workflow","result":envelope(Payload::Long(1))});
    assert_eq!(finish_task(&app, &task, json!([complete])).await.0, StatusCode::OK);

    // A worker claims the next run's task, then its lease expires.
    let leased = start(&app, "repair-leased").await;
    let task = poll(&app, "worker", "workflow").await;
    assert_eq!(task["workflow_id"], "repair-leased", "{task}");
    let (status, healthy) = repair(&app, "repair-leased").await;
    assert_eq!((status, healthy["outcome"].clone()), (StatusCode::OK, json!("repair_not_needed")), "{healthy}");
    let run = leased["run_id"].as_str().unwrap();
    database.execute(sqlx::AssertSqlSafe(format!("UPDATE workflow_tasks SET lease_expires_at='{OLD}' WHERE workflow_run_id='{run}'"))).await;
    let (status, recovered) = repair(&app, "repair-leased").await;
    assert_eq!((status, recovered["outcome"].clone()), (StatusCode::OK, json!("repair_dispatched")), "{recovered}");
    let reclaimed = poll(&app, "worker", "workflow").await;
    assert_eq!(reclaimed["task_id"], task["task_id"], "{reclaimed}");

    // A closed run's repair is recorded as rejected, without a history event.
    let (status, rejected) = repair(&app, "repair-overdue").await;
    assert_eq!(status, StatusCode::CONFLICT, "{rejected}");
    assert_eq!(
        [&rejected["accepted"], &rejected["command_status"], &rejected["outcome"], &rejected["reason"]],
        [&json!(false), &json!("rejected"), &json!("rejected_not_active"), &json!("run_not_active")]
    );
    assert!(rejected["command_id"].is_string());
    assert_eq!(repairs(&app, &overdue).await.len(), 2, "two accepted repairs, no event for the rejection");
}

// Published PHP's repair command recovers a run whose task was lost
// (`TaskRepair::createMissingTask`): a missing workflow, activity or timer
// task is created again, counting one repair, and the run is described by
// its wait again. Recorded against published Server 2.11.7 with the task row
// deleted from its SQLite database.
#[tokio::test]
async fn repair_creates_a_missing_workflow_activity_or_timer_task_like_php() {
    let activity = json!({"type":"schedule_activity","activity_type":"act","arguments":envelope(Payload::Array(vec![]))});
    let timer = json!({"type":"start_timer","delay_seconds":3600});
    for (id, command, kind, wait_kind) in [
        ("missing-workflow", None, "workflow", "workflow-task"),
        ("missing-activity", Some(activity), "activity", "activity"),
        ("missing-timer", Some(timer), "timer", "timer"),
    ] {
        // Each case in its own database, so one case's repaired task is not
        // claimed by the next.
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        runtime.set_maintenance_repair(false);
        let app = router(runtime.clone());
        register(&app, "worker", json!(["echo"]), json!(["act"])).await;
        let started = start(&app, id).await;
        if let Some(command) = command {
            let task = poll(&app, "worker", "workflow").await;
            assert_eq!(finish_task(&app, &task, json!([command])).await.0, StatusCode::OK, "{id}");
        }
        let run = started["run_id"].as_str().unwrap();
        database.execute(sqlx::AssertSqlSafe(format!("DELETE FROM workflow_tasks WHERE workflow_run_id='{run}' AND status IN ('ready','leased')"))).await;
        let (status, repaired) = repair(&app, id).await;
        assert_eq!((status, repaired["outcome"].clone()), (StatusCode::OK, json!("repair_dispatched")), "{id}: {repaired}");
        let event = &repairs(&app, &started).await[0]["payload"];
        assert_eq!((event["liveness_state"].clone(), event["task_type"].clone()), (json!("repair_needed"), json!(kind)), "{id}: {event}");
        assert_eq!(event["task"]["repair_count"], 1, "{id}: {event}");
        let (_, described) = request(&app, "GET", &format!("/api/workflows/{id}"), Value::Null).await;
        assert_eq!(described["wait_kind"], wait_kind, "{id}: {described}");
        // The recreated workflow or activity task is claimed again.
        if kind != "timer" {
            let claimed = poll(&app, "worker", kind).await;
            assert_eq!(claimed["workflow_id"], id, "{id}: {claimed}");
        }
        runtime.close().await;
        database.remove().await;
    }
}

// An expired lease on a running activity is settled by its retry policy, as
// `ActivityTimeoutEnforcer::enforceLeaseExpiry` settles it, exactly once:
// the attempt expires and the retry, or the workflow task that sees the
// failure, follows. Native's timer ticker settles expired activity leases
// every 250 ms whatever the maintenance setting, so it or the repair command
// settles this one, whichever runs first (as the repair pass test allows).
// When the command settles it, the next task is the repaired task; when the
// ticker did, the command finds nothing to repair, as PHP's command does
// after its repair loop.
#[tokio::test]
async fn repair_settles_an_expired_activity_lease_by_its_retry_policy_like_php() {
    for (id, attempts, kind, settled) in [("lease-retry", 2, "activity", "ActivityRetryScheduled"), ("lease-final", 1, "workflow", "ActivityFailed")] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        runtime.set_maintenance_repair(false);
        let app = router(runtime.clone());
        register(&app, "worker", json!(["echo"]), json!(["act"])).await;
        let started = start(&app, id).await;
        let task = poll(&app, "worker", "workflow").await;
        let command = json!({"type":"schedule_activity","activity_type":"act","arguments":envelope(Payload::Array(vec![])),
            "retry_policy":{"max_attempts":attempts}});
        assert_eq!(finish_task(&app, &task, json!([command])).await.0, StatusCode::OK, "{id}");
        let leased = poll(&app, "worker", "activity").await;
        assert_eq!(leased["workflow_id"], id, "{leased}");
        let run = started["run_id"].as_str().unwrap();
        database.execute(sqlx::AssertSqlSafe(format!("UPDATE workflow_tasks SET lease_expires_at='{OLD}' WHERE workflow_run_id='{run}' AND task_type='activity'"))).await;
        let (status, repaired) = repair(&app, id).await;
        assert_eq!(status, StatusCode::OK, "{id}: {repaired}");
        let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
        let kinds: Vec<_> = history.as_array().unwrap().iter().map(|event| event["event_type"].as_str().unwrap().to_owned()).collect();
        assert_eq!(kinds.iter().filter(|kind| **kind == settled).count(), 1, "{id}: settled once: {kinds:?}");
        let event = &repairs(&app, &started).await[0]["payload"];
        match repaired["outcome"].as_str() {
            Some("repair_dispatched") => {
                assert_eq!(kinds[kinds.len() - 2..], [settled.to_owned(), "RepairRequested".to_owned()], "{id}: {kinds:?}");
                assert_eq!(event["task_type"], kind, "{id}: {event}");
            }
            Some("repair_not_needed") => assert!(event["task_id"].is_null(), "{id}: {event}"),
            other => panic!("{id}: unexpected repair outcome {other:?}: {repaired}"),
        }
        // Either way the next task is ready for a worker.
        let next = poll(&app, "worker", kind).await;
        assert_eq!(next["workflow_id"], id, "{id}: {next}");
        runtime.close().await;
        database.remove().await;
    }
}

/// The run's open tasks' types and payloads, oldest first.
async fn open_tasks(database: &TestDatabase, run: &str) -> Vec<(String, Value)> {
    let sql = |payload: &str| format!("SELECT task_type, {payload} FROM workflow_tasks WHERE workflow_run_id='{run}' AND status IN ('ready','leased') ORDER BY created_at, id");
    text_rows(database, &sql("payload"), &sql("payload::text"), &sql("CAST(payload AS CHAR)")).await
        .into_iter().map(|(kind, payload)| (kind, serde_json::from_str(&payload).unwrap())).collect()
}

/// When the run's open tasks are available, as stored.
async fn available(database: &TestDatabase, run: &str) -> Vec<(String, String)> {
    let sql = |at: &str| format!("SELECT task_type, {at} FROM workflow_tasks WHERE workflow_run_id='{run}' AND status IN ('ready','leased') ORDER BY created_at, id");
    text_rows(database, &sql("available_at"), &sql("available_at::text"), &sql("CAST(available_at AS CHAR)")).await
}

/// Waits until the run's history records `kind`.
async fn await_event(app: &Router, started: &Value, kind: &str) {
    tokio::time::timeout(std::time::Duration::from_secs(10), async {
        loop {
            let history = run_history(app, &started["workflow_id"], &started["run_id"]).await;
            if history.as_array().unwrap().iter().any(|event| event["event_type"] == kind) {
                break;
            }
            tokio::time::sleep(std::time::Duration::from_millis(50)).await;
        }
    })
    .await
    .unwrap_or_else(|_| panic!("{kind} is recorded"));
}

/// The original payload without the named keys.
fn without(payload: &Value, keys: &[&str]) -> Value {
    let mut payload = payload.clone();
    for key in keys {
        payload.as_object_mut().unwrap().remove(*key);
    }
    payload
}

// Published PHP's repair command also recreates the missing task of a run
// waiting on a received signal, an accepted update or a resolved child, and
// of a condition or signal wait's timeout (`TaskRepair::createMissingTask`
// with `forMissingWorkflowTask`'s payloads). Recorded against published
// Server 2.11.7 with the open task row deleted from its SQLite database:
// - a received signal or an accepted update gets a workflow task with the
//   same payload as the lost one;
// - a resolved child gets a workflow task with its child run's resume
//   fields, without the resolution event's sequence and type;
// - a pending timeout gets its timer task again, available at the deadline;
// - a fired timeout gets the workflow task that applies it, without the
//   timer kind and event type that firing records.
// The run is then described as it was before the task was lost.
#[tokio::test]
async fn repair_creates_a_missing_signal_update_child_or_timeout_task_like_php() {
    let int = || json!({"input":envelope(Payload::Array(vec![Payload::Long(1)]))});
    for (id, kind, wait_kind) in [
        ("missing-signal", "workflow", "workflow-task"),
        ("missing-update", "workflow", "update"),
        ("missing-child", "workflow", "workflow-task"),
        ("missing-signal-timeout", "timer", "signal"),
        ("missing-signal-timeout-fired", "workflow", "signal"),
        ("missing-condition-timeout", "timer", "condition"),
        ("missing-condition-timeout-fired", "workflow", "condition"),
    ] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        runtime.set_maintenance_repair(false);
        let app = router(runtime.clone());
        if id == "missing-child" {
            register(&app, "worker", json!(["echo", "child"]), json!([])).await;
        } else {
            update_worker(&app, "worker").await;
        }
        let started = start(&app, id).await;
        let run = started["run_id"].as_str().unwrap();
        let task = poll(&app, "worker", "workflow").await;
        let command = match id {
            "missing-signal" | "missing-update" => json!({"type":"open_signal_wait","signal_name":"finish"}),
            "missing-child" => child_command(1),
            "missing-signal-timeout" => json!({"type":"open_signal_wait","signal_name":"finish","timeout_seconds":3600}),
            "missing-signal-timeout-fired" => json!({"type":"open_signal_wait","signal_name":"finish","timeout_seconds":1}),
            "missing-condition-timeout" => json!({"type":"open_condition_wait","condition_key":"ready","timeout_seconds":3600}),
            _ => json!({"type":"open_condition_wait","condition_key":"ready","timeout_seconds":1}),
        };
        let (status, body) = finish_task(&app, &task, json!([command])).await;
        assert_eq!(status, StatusCode::OK, "{id}: {body}");
        match id {
            "missing-signal" => {
                let (status, body) = request(&app, "POST", &format!("/api/workflows/{id}/signal/finish"), int()).await;
                assert!(status.is_success(), "{id}: {body}");
            }
            "missing-update" => {
                let mut input = int();
                input["wait_for"] = json!("accepted");
                let (status, body) = request(&app, "POST", &format!("/api/workflows/{id}/update/set"), input).await;
                assert!(status.is_success(), "{id}: {body}");
            }
            "missing-child" => {
                let child = poll(&app, "worker", "workflow").await;
                assert_ne!(child["workflow_id"], id, "{child}");
                let complete = json!({"type":"complete_workflow","result":envelope(Payload::Long(1))});
                assert_eq!(finish_task(&app, &child, json!([complete])).await.0, StatusCode::OK, "{id}");
            }
            "missing-signal-timeout-fired" | "missing-condition-timeout-fired" => await_event(&app, &started, "TimerFired").await,
            _ => {}
        }
        let (_, described) = request(&app, "GET", &format!("/api/workflows/{id}"), Value::Null).await;
        assert_eq!(described["wait_kind"], wait_kind, "{id}: {described}");
        let lost = open_tasks(&database, run).await;
        assert_eq!(lost.len(), 1, "{id}: {lost:?}");
        assert_eq!(lost[0].0, kind, "{id}: {lost:?}");
        let lost_at = available(&database, run).await;
        database.execute(sqlx::AssertSqlSafe(format!("DELETE FROM workflow_tasks WHERE workflow_run_id='{run}' AND status IN ('ready','leased')"))).await;

        let (status, repaired) = repair(&app, id).await;
        assert_eq!((status, repaired["outcome"].clone()), (StatusCode::OK, json!("repair_dispatched")), "{id}: {repaired}");
        let event = &repairs(&app, &started).await[0]["payload"];
        let summary_kind = match id {
            "missing-signal" | "missing-signal-timeout" | "missing-signal-timeout-fired" => "signal",
            "missing-update" => "update",
            "missing-child" => "child",
            _ => "condition",
        };
        assert_eq!(
            (event["liveness_state"].clone(), event["wait_kind"].clone(), event["task_type"].clone(), event["task"]["repair_count"].clone()),
            (json!("repair_needed"), json!(summary_kind), json!(kind), json!(1)),
            "{id}: {event}"
        );
        let created = open_tasks(&database, run).await;
        assert_eq!(created.len(), 1, "{id}: {created:?}");
        let original = &lost[0].1;
        let expected = match id {
            // `forUpdate`, which names the wait; native's accepted update
            // task carries only the update and command.
            "missing-update" => {
                let update = original["workflow_update_id"].as_str().unwrap();
                let mut expected = original.clone();
                expected["workflow_wait_kind"] = json!("update");
                expected["open_wait_id"] = json!(format!("update:{update}"));
                expected["resume_source_id"] = json!(update);
                expected
            }
            "missing-child" => without(original, &["workflow_history_event_id", "workflow_sequence", "workflow_event_type"]),
            "missing-signal-timeout-fired" | "missing-condition-timeout-fired" => without(original, &["timer_kind", "workflow_event_type"]),
            _ => original.clone(),
        };
        assert_eq!(created[0], (kind.to_owned(), expected), "{id}: lost {original}");
        let (_, described) = request(&app, "GET", &format!("/api/workflows/{id}"), Value::Null).await;
        assert_eq!(described["wait_kind"], wait_kind, "{id}: {described}");
        if kind == "timer" {
            // The timer task is available at the timeout's deadline, as the
            // lost one was.
            assert_eq!(available(&database, run).await, lost_at, "{id}");
        } else {
            let claimed = poll(&app, "worker", "workflow").await;
            assert_eq!(claimed["workflow_id"], id, "{id}: {claimed}");
        }
        runtime.close().await;
        database.remove().await;
    }
}
