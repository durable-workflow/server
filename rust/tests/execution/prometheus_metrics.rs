use super::*;

const PROMETHEUS: &str = "/api/system/prometheus-metrics";

async fn summary(app: &Router) -> Value {
    let (status, body) = request(app, "GET", PROMETHEUS, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(
        [&body["schema"], &body["namespace"]],
        [&json!("durable-workflow.prometheus-metrics.v1"), &json!("default")]
    );
    body
}

async fn start_on(app: &Router, workflow: &str, workflow_type: &str, queue: &str) {
    let (status, body) = request(app, "POST", "/api/workflows", json!({"workflow_id": workflow,
        "workflow_type": workflow_type, "task_queue": queue, "input": envelope(Payload::Array(vec![]))})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

fn buckets(values: [i64; 10]) -> Value {
    let bounds = ["0.1", "0.5", "1", "2.5", "5", "10", "30", "60", "300", "900"];
    Value::Object(bounds.iter().zip(values).map(|(bound, value)| ((*bound).to_owned(), json!(value))).collect())
}

#[tokio::test]
async fn prometheus_summary_counts_runs_activities_and_queues_with_cumulative_latency() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let empty = summary(&app).await;
    assert_eq!(empty["series"], json!({"workflows": [], "activities": [], "task_queues": []}));
    assert_eq!(empty["latency_buckets_seconds"].to_string(), "[0.1,0.5,1,2.5,5,10,30,60,300,900]");
    assert_eq!(
        [&empty["series_count"], &empty["observed_series_count_precision"]],
        [&json!(0), &json!("exact")]
    );
    assert_eq!(empty["cardinality"]["series_limits"]["workflows"]["limit"], 100);

    let (status, body) = request(&app, "POST", "/api/worker/register", json!({"worker_id": "prom-worker",
        "task_queue": "test", "runtime": "php", "supported_workflow_types": ["echo", "other"],
        "supported_activity_types": ["act"], "max_concurrent_workflow_tasks": 4, "max_concurrent_activity_tasks": 3})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    start_on(&app, "prom-completed", "echo", "test").await;
    let task = poll(&app, "prom-worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type": "schedule_activity", "activity_type": "act",
        "arguments": envelope(Payload::Array(vec![])), "retry_policy": {"max_attempts": 1, "backoff_seconds": []},
        "start_to_close_timeout": 300}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let activity = poll(&app, "prom-worker", "activity").await;
    let (status, body) = request(&app, "POST",
        &format!("/api/worker/activity-tasks/{}/complete", activity["task_id"].as_str().unwrap()),
        json!({"activity_attempt_id": activity["activity_attempt_id"], "lease_owner": activity["lease_owner"],
            "result": envelope(Payload::Array(vec![]))})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let task = poll(&app, "prom-worker", "workflow").await;
    let (status, body) = finish_task(&app, &task,
        json!([{"type": "complete_workflow", "result": envelope(Payload::Array(vec![]))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    start_on(&app, "prom-terminated", "other", "test").await;
    let (status, body) = request(&app, "POST", "/api/workflows/prom-terminated/terminate", json!({"reason": "prom"})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    start_on(&app, "prom-waiting", "echo", "second").await;
    // Fixed durations: the completed run 2 s, the terminated run 50 ms and the
    // activity 1.5 s, so each falls in known buckets on every backend.
    database.execute("UPDATE workflow_run_summaries SET duration_ms=2000 WHERE workflow_type='echo' AND status='completed'").await;
    database.execute("UPDATE workflow_run_summaries SET duration_ms=50 WHERE workflow_type='other'").await;
    database.execute("UPDATE activity_executions SET started_at='2026-10-22 10:00:00', closed_at='2026-10-22 10:00:01.5'").await;
    // As in PHP, a task made available within the current second still counts
    // as delayed; this one became available earlier.
    database.execute("UPDATE workflow_tasks SET available_at='2026-10-22 09:00:00' WHERE queue='second'").await;

    let read = summary(&app).await;
    let workflows = read["series"]["workflows"].as_array().unwrap();
    assert_eq!(workflows.len(), 3, "{read}");
    assert_eq!(
        workflows[0],
        json!({"task_queue": "second", "workflow_type": "echo", "started_total": 1, "completed_total": 0,
            "failed_total": 0, "cancelled_total": 0, "terminated_total": 0, "running": 1,
            "latency_seconds": {"count": 0, "sum": 0, "buckets": buckets([0; 10])}})
    );
    assert_eq!(
        workflows[1],
        json!({"task_queue": "test", "workflow_type": "echo", "started_total": 1, "completed_total": 1,
            "failed_total": 0, "cancelled_total": 0, "terminated_total": 0, "running": 0,
            "latency_seconds": {"count": 1, "sum": 2, "buckets": buckets([0, 0, 0, 1, 1, 1, 1, 1, 1, 1])}})
    );
    assert_eq!(
        [&workflows[2]["terminated_total"], &workflows[2]["latency_seconds"]],
        [&json!(1), &json!({"count": 1, "sum": 0.05, "buckets": buckets([1; 10])})]
    );
    // SQLite measures activity durations with PHP's julianday difference, a
    // float accurate to about a millisecond.
    let mut activities = read["series"]["activities"].clone();
    let sum = activities[0]["latency_seconds"]["sum"].take().as_f64().unwrap();
    assert!((sum - 1.5).abs() < 0.001, "{sum}");
    assert_eq!(
        activities,
        json!([{"task_queue": "test", "workflow_type": "echo", "activity_type": "act", "started_total": 1,
            "completed_total": 1, "failed_total": 0, "cancelled_total": 0, "running": 0,
            "latency_seconds": {"count": 1, "sum": null, "buckets": buckets([0, 0, 0, 1, 1, 1, 1, 1, 1, 1])}}])
    );
    let queues = read["series"]["task_queues"].as_array().unwrap();
    assert_eq!(
        queues.iter().map(|queue| queue["task_queue"].as_str().unwrap()).collect::<Vec<_>>(),
        ["second", "test"]
    );
    assert_eq!(
        [&queues[0]["workflow_ready_tasks"], &queues[0]["workflow_ready_due_tasks"], &queues[0]["active_pollers"]],
        [&json!(1), &json!(1), &json!(0)]
    );
    assert_eq!(
        [
            &queues[1]["workflow_ready_tasks"],
            &queues[1]["activity_tasks_added_last_minute"],
            &queues[1]["active_pollers"],
            &queues[1]["workflow_slot_capacity"],
            &queues[1]["activity_slot_capacity"],
            &queues[1]["available_workflow_slots"],
            &queues[1]["available_activity_slots"],
        ],
        [&json!(0), &json!(1), &json!(1), &json!(4), &json!(3), &json!(4), &json!(3)]
    );
    assert_eq!(
        [&read["series_count"], &read["observed_series_count"], &read["suppressed_series_count"]],
        [&json!(6), &json!(6), &json!(0)]
    );

    // The summary is namespace-scoped: a named namespace sees only its own.
    super::namespaces::create(&app, "prom-named").await;
    let (status, named) = super::namespaces::scoped(&app, "prom-named", "GET", PROMETHEUS, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{named}");
    assert_eq!(
        [&named["namespace"], &named["series_count"]],
        [&json!("prom-named"), &json!(0)]
    );
}

#[tokio::test]
async fn prometheus_summary_bounds_workflow_series_and_discloses_the_suppressed_starts() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    for index in 0..101 {
        start_on(&app, &format!("bounded-{index:03}"), &format!("type-{index:03}"), "test").await;
    }
    let read = summary(&app).await;
    let workflows = read["series"]["workflows"].as_array().unwrap();
    assert_eq!(workflows.len(), 100);
    assert_eq!(
        [&workflows[0]["workflow_type"], &workflows[99]["workflow_type"]],
        [&json!("type-000"), &json!("type-099")]
    );
    assert_eq!(
        read["cardinality"]["series_limits"]["workflows"],
        json!({"label_dimensions": ["task_queue", "workflow_type"], "limit": 100, "observed_series_count": 101,
            "observed_series_count_precision": "lower_bound", "reported_series_count": 100,
            "suppressed_series_count": 1, "suppressed_started_total": 1, "suppressed_counts_precision": "lower_bound",
            "truncated": true, "selection": "bounded_task_queue_and_workflow_type_ascending"})
    );
    assert_eq!(
        [
            &read["observed_series_count"],
            &read["observed_series_count_precision"],
            &read["suppressed_series_count"],
            &read["suppressed_series_count_precision"],
        ],
        [&json!(102), &json!("lower_bound"), &json!(1), &json!("lower_bound")]
    );
}
