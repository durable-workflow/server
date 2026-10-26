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
    // A second runtime on the same database registers the worker again.
    assert!(status == StatusCode::CREATED || status == StatusCode::OK, "{status}: {body}");
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

// The acceptance matrix: each limit admits its exact value and refuses one
// more, on every backend, and a refusal changes no durable row and leaves
// the lease, which then completes a smaller task.

/// A completed local activity with a large valid argument and `heartbeats`
/// heartbeats with details.
fn large(heartbeats: i64) -> Value {
    let mut command = local(heartbeats);
    command["arguments"] = envelope(Payload::Array(vec![Payload::String("argument ".repeat(30_000))]));
    command
}

/// Every table's row count, and the state of every task and run.
async fn durable_state(database: &TestDatabase) -> (Vec<(String, i64)>, Vec<(String, String)>, Vec<(String, String)>) {
    let counts = table_counts(database).await;
    let tasks = text_rows(database,
        "SELECT id, status || '|' || COALESCE(lease_owner, '') || '|' || COALESCE(lease_expires_at, '') || '|' || attempt_count FROM workflow_tasks ORDER BY id",
        "SELECT id, status || '|' || COALESCE(lease_owner, '') || '|' || COALESCE(lease_expires_at::text, '') || '|' || attempt_count::text FROM workflow_tasks ORDER BY id",
        "SELECT id, CONCAT(status, '|', COALESCE(lease_owner, ''), '|', COALESCE(CAST(lease_expires_at AS CHAR), ''), '|', attempt_count) FROM workflow_tasks ORDER BY id").await;
    let runs = text_rows(database,
        "SELECT id, status || '|' || last_history_sequence || '|' || COALESCE(updated_at, '') FROM workflow_runs ORDER BY id",
        "SELECT id, status || '|' || last_history_sequence::text || '|' || COALESCE(updated_at::text, '') FROM workflow_runs ORDER BY id",
        "SELECT id, CONCAT(status, '|', last_history_sequence, '|', COALESCE(CAST(updated_at AS CHAR), '')) FROM workflow_runs ORDER BY id").await;
    (counts, tasks, runs)
}

/// The stored history of a run, in order: each event's type and payload.
async fn stored_history(database: &TestDatabase, run_id: &str) -> Vec<(String, String)> {
    text_rows(database,
        &format!("SELECT event_type, payload FROM workflow_history_events WHERE workflow_run_id='{run_id}' ORDER BY sequence"),
        &format!("SELECT event_type, payload::text FROM workflow_history_events WHERE workflow_run_id='{run_id}' ORDER BY sequence"),
        &format!("SELECT event_type, CAST(payload AS CHAR) FROM workflow_history_events \
            FORCE INDEX (workflow_history_events_workflow_run_id_sequence_unique) WHERE workflow_run_id='{run_id}' ORDER BY sequence")).await
}

/// The bytes the meter charges for stored events: each event's type and
/// the compact JSON of its payload (none of these hold U+2028 or U+2029).
fn charged_bytes(events: &[(String, String)]) -> usize {
    events.iter().map(|(event_type, payload)| {
        let payload: Value = serde_json::from_str(payload).unwrap();
        event_type.len() + serde_json::to_string(&payload).unwrap().len()
    }).sum()
}

/// The exact limit admits `commands`; one less refuses them with `reason`
/// before anything is written, and the same lease then completes a wait.
async fn admits_exactly(database: &TestDatabase, tag: &str, exact: StructuralLimits, short: StructuralLimits,
    commands: Value, reason: &str) {
    let app = budgeted(database, exact).await;
    local_worker(&app).await;
    start(&app, &format!("{tag}-exact")).await;
    let task = poll(&app, "local", "workflow").await;
    let (status, body) = complete(&app, &task, commands.clone()).await;
    assert_eq!(status, StatusCode::OK, "{tag} at its exact limit: {body}");

    let app = budgeted(database, short).await;
    start(&app, &format!("{tag}-short")).await;
    let task = poll(&app, "local", "workflow").await;
    let before = durable_state(database).await;
    let (status, body) = complete(&app, &task, commands).await;
    assert_eq!(status, StatusCode::CONFLICT, "{tag} one over: {body}");
    assert_eq!(body["reason"], reason, "{tag}: {body}");
    assert_eq!(durable_state(database).await, before, "{tag}: a refusal changed durable state");
    let (status, body) = complete(&app, &task, json!([wait()])).await;
    assert_eq!(status, StatusCode::OK, "{tag}: the lease after a refusal: {body}");
}

fn only(events: Option<usize>, bytes: Option<usize>, rows: Option<usize>) -> StructuralLimits {
    StructuralLimits { history_events: events, history_bytes: bytes, history_rows: rows, ..StructuralLimits::default() }
}

#[tokio::test]
async fn the_event_limit_admits_its_exact_value_and_refuses_one_more() {
    let database = TestDatabase::new().await;
    // Scheduled, started, three heartbeats and completed, and the wait: 7.
    admits_exactly(&database, "events", only(Some(7), None, None), only(Some(6), None, None),
        json!([local(3), wait()]), "completion_budget_exceeded").await;
}

#[tokio::test]
async fn the_row_limit_admits_its_exact_value_and_refuses_one_more() {
    let database = TestDatabase::new().await;
    // Three events (six rows), the execution and the attempt, and the wait's
    // event (two rows): ten.
    admits_exactly(&database, "rows", only(None, None, Some(10)), only(None, None, Some(9)),
        json!([local(0), wait()]), "completion_budget_exceeded").await;
}

#[tokio::test]
async fn the_byte_limit_admits_its_exact_value_and_refuses_one_more() {
    let database = TestDatabase::new().await;
    let commands = json!([large(20), wait()]);
    // Measure the completion as stored, the way the meter charges it.
    let probe = budgeted(&database, only(None, None, None)).await;
    local_worker(&probe).await;
    let run = start(&probe, "bytes-probe").await;
    let run_id = run["run_id"].as_str().unwrap().to_owned();
    let task = poll(&probe, "local", "workflow").await;
    let before = stored_history(&database, &run_id).await;
    let (status, body) = complete(&probe, &task, commands.clone()).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let after = stored_history(&database, &run_id).await;
    let bytes = charged_bytes(&after[before.len()..]);
    // The large argument and heartbeats make this a multi-megabyte budget.
    assert!(bytes > 1_000_000, "{bytes}");

    admits_exactly(&database, "bytes", only(None, Some(bytes), None), only(None, Some(bytes - 1), None),
        commands, "history_transaction_limit_exceeded").await;
}

/// A terminal event is rewritten after it is recorded, to name the worker
/// that completed or failed the run; that growth is charged too, so the
/// stored terminal history is admitted at its exact size and refused one
/// byte short.
async fn terminal_admits_exactly(database: &TestDatabase, tag: &str, terminal: Value) {
    let commands = json!([large(5), terminal]);
    let probe = budgeted(database, only(None, None, None)).await;
    local_worker(&probe).await;
    let run = start(&probe, &format!("{tag}-probe")).await;
    let run_id = run["run_id"].as_str().unwrap().to_owned();
    let task = poll(&probe, "local", "workflow").await;
    let before = stored_history(database, &run_id).await;
    let (status, body) = complete(&probe, &task, commands.clone()).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let after = stored_history(database, &run_id).await;
    let terminal = after.last().unwrap();
    // The stored terminal event names the worker.
    let payload: Value = serde_json::from_str(&terminal.1).unwrap();
    assert_eq!(payload["command"]["source"], "worker_protocol", "{payload}");
    assert!(payload["command"]["principal_id"].is_string(), "{payload}");
    let bytes = charged_bytes(&after[before.len()..]);

    admits_exactly(database, tag, only(None, Some(bytes), None), only(None, Some(bytes - 1), None),
        commands, "history_transaction_limit_exceeded").await;
}

#[tokio::test]
async fn a_completed_run_s_attributed_terminal_event_is_charged() {
    let database = TestDatabase::new().await;
    terminal_admits_exactly(&database, "done", json!({"type": "complete_workflow", "result": envelope(Payload::Long(1))})).await;
}

#[tokio::test]
async fn a_failed_run_s_attributed_terminal_event_is_charged() {
    let database = TestDatabase::new().await;
    terminal_admits_exactly(&database, "fail", json!({"type": "fail_workflow", "message": "boom"})).await;
}
