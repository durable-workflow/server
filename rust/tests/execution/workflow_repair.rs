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
        let app = router(runtime);
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
        database.remove().await;
    }
}

// An expired lease on a running activity is settled by its retry policy, as
// `ActivityTimeoutEnforcer::enforceLeaseExpiry` settles it: the attempt
// expires and the retry, or the workflow task that sees the failure, is the
// repaired task.
#[tokio::test]
async fn repair_settles_an_expired_activity_lease_by_its_retry_policy_like_php() {
    for (id, attempts, kind, settled) in [("lease-retry", 2, "activity", "ActivityRetryScheduled"), ("lease-final", 1, "workflow", "ActivityFailed")] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        runtime.set_maintenance_repair(false);
        let app = router(runtime);
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
        assert_eq!((status, repaired["outcome"].clone()), (StatusCode::OK, json!("repair_dispatched")), "{id}: {repaired}");
        let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
        let kinds: Vec<_> = history.as_array().unwrap().iter().map(|event| event["event_type"].as_str().unwrap().to_owned()).collect();
        assert_eq!(kinds[kinds.len() - 2..], [settled.to_owned(), "RepairRequested".to_owned()], "{id}: {kinds:?}");
        let event = &repairs(&app, &started).await[0]["payload"];
        assert_eq!(event["task_type"], kind, "{id}: {event}");
        database.remove().await;
    }
}
