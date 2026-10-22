use super::*;

async fn started_on(app: &Router, workflow: &str, queue: &str) -> Value {
    let (status, body) = request(app, "POST", "/api/workflows", json!({"workflow_id":workflow,"workflow_type":"echo","task_queue":queue,"input":[]})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    body
}

// Published PHP's repair status reports its policy and the tasks and runs its
// repair pass would select, grouped by scope and picked round-robin.
#[tokio::test]
async fn repair_status_reports_candidates_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let (status, empty) = request(&app, "GET", "/api/system/repair", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{empty}");
    assert_eq!(empty["policy"]["redispatch_after_seconds"], 3);
    assert_eq!((empty["candidates"]["total_candidates"].clone(), empty["candidates"]["scopes"].clone()), (json!(0), json!([])));

    started_on(&app, "overdue-a1", "repair-a").await;
    started_on(&app, "overdue-a2", "repair-a").await;
    started_on(&app, "overdue-b", "repair-b").await;
    let stalled = started_on(&app, "stalled", "repair-c").await;
    // The ready tasks were made available long before the redispatch cutoff.
    // (Laravel compares "now" in whole seconds, so a task made available in
    // the current second is not yet due.)
    database.execute("UPDATE workflow_tasks SET created_at='2020-01-02 03:04:05.000000',available_at='2020-01-02 03:04:05.000000',last_dispatched_at='2020-01-02 03:04:05.000000'").await;
    // The task in queue repair-b is leased, but its lease expired.
    database.execute("UPDATE workflow_tasks SET status='leased',lease_owner='gone',lease_expires_at='2020-01-02 03:04:05.000000' WHERE queue='repair-b'").await;
    // The run in queue repair-c lost its task and needs repair.
    let run = stalled["run_id"].as_str().unwrap();
    database.execute(sqlx::AssertSqlSafe(format!("DELETE FROM workflow_tasks WHERE workflow_run_id='{run}'"))).await;
    database.execute(sqlx::AssertSqlSafe(format!(
        "UPDATE workflow_run_summaries SET liveness_state='repair_needed',next_task_id=NULL,wait_started_at=NULL WHERE id='{run}'"
    ))).await;

    let (status, body) = request(&app, "GET", "/api/system/repair", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let candidates = &body["candidates"];
    assert_eq!(
        [&candidates["existing_task_candidates"], &candidates["missing_task_candidates"], &candidates["total_candidates"], &candidates["selected_total_candidates"]],
        [&json!(3), &json!(1), &json!(4), &json!(4)],
        "{candidates}"
    );
    assert_eq!(candidates["oldest_task_candidate_created_at"], "2020-01-02T03:04:05.000000Z");
    assert!(candidates["max_task_candidate_age_ms"].as_i64().unwrap() > 0);
    assert!(candidates["oldest_missing_run_started_at"].is_string(), "{candidates}");
    let scopes = candidates["scopes"].as_array().unwrap();
    // The busiest scope first.
    assert_eq!(scopes[0]["scope_key"], "default:repair-a:any");
    assert_eq!((scopes[0]["existing_task_candidates"].clone(), scopes[0]["selected_total_candidates"].clone()), (json!(2), json!(2)));
    let missing: Vec<_> = scopes.iter().filter(|scope| scope["missing_task_candidates"] == 1).collect();
    assert_eq!(missing.len(), 1, "{candidates}");
    assert_eq!(missing[0]["queue"], "repair-c");
}
