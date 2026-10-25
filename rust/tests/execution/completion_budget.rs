use super::*;
use durable_workflow_server::runtime::StructuralLimits;

// The completion budget of Workflow #930 (phase A, Workflow PR #934): an
// early whole-completion check of the local activities' exact events and
// rows, then a meter that charges every history event (its bytes as
// `HistoryBudget` measures them, and two rows) and every local activity row.
// A refusal writes nothing and leaves the task's lease as it was.

async fn budgeted(database: &TestDatabase, limits: StructuralLimits) -> Router {
    router(database.open().await.unwrap().with_structural_limits(limits))
}

async fn local_worker(app: &Router) {
    let (status, body) = request_protocol(app, "POST", "/api/worker/register", json!({"worker_id": "local",
        "task_queue": "test", "runtime": "python", "supported_workflow_types": ["echo"], "supported_activity_types": [],
        "capabilities": ["local_activities"]}), "1.20").await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

async fn complete(app: &Router, task: &Value, commands: Value) -> (StatusCode, Value) {
    let path = format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap());
    request_protocol(app, "POST", &path, completion(task, commands), "1.20").await
}

/// One completed local activity attempt with `heartbeats` heartbeats.
fn local(heartbeats: i64) -> Value {
    json!({"type": "record_local_activity", "activity_type": "local.echo",
        "arguments": {"codec": "avro", "blob": "wwHioz3/VYAiNwwA"}, "result": {"codec": "avro", "blob": "wwHioz3/VYAiNwoIZG9uZQ=="},
        "outcome": "completed", "attempts": [{"attempt_id": "w-1", "attempt_number": 1, "outcome": "completed",
            "duration_ms": 25, "heartbeats": (1..=heartbeats)
                .map(|elapsed| json!({"elapsed_ms": elapsed, "details": {"progress": "x".repeat(200)}}))
                .collect::<Vec<_>>()}]})
}

fn wait() -> Value {
    json!({"type": "open_signal_wait", "signal_name": "resume"})
}

#[tokio::test]
async fn an_over_budget_completion_writes_nothing_and_keeps_the_lease() {
    let database = TestDatabase::new().await;
    let app = budgeted(&database, StructuralLimits { history_events: Some(4), ..StructuralLimits::default() }).await;
    local_worker(&app).await;
    let run = start(&app, "budget-early").await;
    let task = poll(&app, "local", "workflow").await;
    let before = run_history(&app, &run["workflow_id"], &run["run_id"]).await;

    // Scheduled, started, three heartbeats and completed, then the wait: 7.
    let (status, body) = complete(&app, &task, json!([local(3), wait()])).await;

    assert_eq!(status, StatusCode::CONFLICT, "{body}");
    assert_eq!(body["reason"], "completion_budget_exceeded", "{body}");
    assert_eq!((&body["recorded"], &body["run_id"], &body["outcome"]), (&json!(false), &run["run_id"], &json!("completed")));
    assert_eq!(run_history(&app, &run["workflow_id"], &run["run_id"]).await, before);

    // Exactly the budget: scheduled, started, completed and the wait.
    let (status, body) = complete(&app, &task, json!([local(0), wait()])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["recorded"], true, "{body}");
}

#[tokio::test]
async fn the_meter_refuses_past_the_byte_limit_and_keeps_the_lease() {
    let database = TestDatabase::new().await;
    let app = budgeted(&database, StructuralLimits { history_bytes: Some(4_000), ..StructuralLimits::default() }).await;
    local_worker(&app).await;
    let run = start(&app, "budget-bytes").await;
    let task = poll(&app, "local", "workflow").await;
    let before = run_history(&app, &run["workflow_id"], &run["run_id"]).await;

    let (status, body) = complete(&app, &task, json!([local(5), wait()])).await;

    assert_eq!(status, StatusCode::CONFLICT, "{body}");
    assert_eq!(body["reason"], "history_transaction_limit_exceeded", "{body}");
    assert_eq!((&body["recorded"], &body["run_id"], &body["created_task_ids"]), (&json!(false), &Value::Null, &json!([])));
    assert_eq!(run_history(&app, &run["workflow_id"], &run["run_id"]).await, before);
    assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM activity_executions").await, 0);
    assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM activity_attempts").await, 0);

    // The same lease completes a smaller task.
    let (status, body) = complete(&app, &task, json!([wait()])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
}

#[tokio::test]
async fn the_meter_counts_a_local_activity_s_rows() {
    let database = TestDatabase::new().await;
    // Scheduled, started and completed are six rows, with the execution
    // and the attempt eight; the wait's event is two more.
    let app = budgeted(&database, StructuralLimits { history_rows: Some(9), ..StructuralLimits::default() }).await;
    local_worker(&app).await;
    let run = start(&app, "budget-rows").await;
    let task = poll(&app, "local", "workflow").await;

    let (status, body) = complete(&app, &task, json!([local(0), wait()])).await;
    assert_eq!(status, StatusCode::CONFLICT, "{body}");
    assert_eq!(body["reason"], "completion_budget_exceeded", "{body}");

    let roomy = budgeted(&database, StructuralLimits { history_rows: Some(10), ..StructuralLimits::default() }).await;
    // The meter charges the same ten rows the early check counted.
    let (status, body) = complete(&roomy, &task, json!([local(0), wait()])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM activity_attempts").await, 1);
    let events = run_history(&roomy, &run["workflow_id"], &run["run_id"]).await;
    assert!(events.as_array().unwrap().iter().any(|event| event["event_type"] == "SignalWaitOpened"), "{events}");
}

#[tokio::test]
async fn a_run_past_its_deadline_closes_before_the_budget_is_checked() {
    let database = TestDatabase::new().await;
    let app = budgeted(&database, StructuralLimits { history_events: Some(1), ..StructuralLimits::default() }).await;
    local_worker(&app).await;
    let (status, started) = request(&app, "POST", "/api/workflows", json!({"workflow_id": "budget-deadline",
        "workflow_type": "echo", "task_queue": "test", "input": envelope(Payload::Array(vec![])), "run_timeout_seconds": 600})).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    let task = poll(&app, "local", "workflow").await;
    database.execute("UPDATE workflow_runs SET run_deadline_at='2000-01-01 00:00:00'").await;

    // Far over the budget, but the run's deadline close comes first and is
    // the server's own work: the run times out instead of staying open.
    let (status, body) = complete(&app, &task, json!([local(3), wait()])).await;

    assert_eq!(status, StatusCode::CONFLICT, "{body}");
    assert_eq!(body["reason"], "run_timed_out", "{body}");
    let (status, described) = request(&app, "GET", "/api/workflows/budget-deadline", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{described}");
    assert_eq!((&described["status"], &described["closed_reason"]), (&json!("failed"), &json!("timed_out")));
}
