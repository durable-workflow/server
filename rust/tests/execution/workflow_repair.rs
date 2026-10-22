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
