use super::*;
use super::redrive::{FINGERPRINT, complete_activity, failed_at_second_activity};

// Server #1132 (.github#215): each workflow task carries the instant its
// run's replay starts from, so an SDK clock has a value at ordinary entry.

const SCHEMA: &str = "durable-workflow.worker-replay-clock/v1";

fn seconds(value: &Value) -> i64 {
    chrono::DateTime::parse_from_rfc3339(value.as_str().unwrap()).unwrap().timestamp()
}

/// A task's clock, checked against its run and the strict UTC form.
fn clock(task: &Value, source: &str) -> Value {
    let clock = task["replay_clock"].clone();
    assert_eq!((clock["schema"].as_str(), clock["source"].as_str()), (Some(SCHEMA), Some(source)), "{task}");
    assert_eq!(clock["workflow_run_id"], task["run_id"], "{clock}");
    assert_eq!(clock.as_object().unwrap().len(), 4, "{clock}");
    let started = clock["started_at"].as_str().unwrap();
    assert!(started.len() == 27 && started.ends_with('Z') && started.as_bytes()[19] == b'.', "{started}");
    clock
}

async fn run_started(app: &Router, workflow: &str, run: &Value) -> Value {
    let (status, body) = request(app, "GET", &format!("/api/workflows/{workflow}/runs/{}", run.as_str().unwrap()), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body["started_at"].clone()
}

async fn register(app: &Router) {
    let registered = request(app, "POST", "/api/worker/register", json!({"worker_id":"worker","task_queue":"test",
        "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":["a","b"],
        "workflow_definition_fingerprints":{"echo":FINGERPRINT}})).await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
}

#[tokio::test]
async fn ordinary_and_continued_runs_carry_their_own_start() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app).await;
    let started = start(&app, "clocked").await;
    let first = poll(&app, "worker", "workflow").await;
    let entry = clock(&first, "run_started_at");
    assert_eq!(seconds(&entry["started_at"]), seconds(&run_started(&app, "clocked", &started["run_id"]).await));

    // A later lease of the same run keeps the same instant.
    finish_task(&app, &first, json!([{"type":"schedule_activity","activity_type":"a","arguments":envelope(Payload::Array(vec![]))}])).await;
    complete_activity(&app, &poll(&app, "worker", "activity").await).await;
    let second = poll(&app, "worker", "workflow").await;
    assert_ne!(second["task_id"], first["task_id"]);
    assert_eq!(clock(&second, "run_started_at"), entry);

    // A continued run binds its own start.
    let (status, body) = finish_task(&app, &second, json!([{"type":"continue_as_new"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let continued = poll(&app, "worker", "workflow").await;
    assert_ne!(continued["run_id"], started["run_id"]);
    let successor = clock(&continued, "run_started_at");
    assert_eq!(seconds(&successor["started_at"]), seconds(&run_started(&app, "clocked", &continued["run_id"]).await));
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_redriven_run_carries_its_correlated_replay_anchor() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app).await;
    let source = failed_at_second_activity(&app, "redriven-clock").await;
    let source_run = source["run_id"].as_str().unwrap();
    let (status, redriven) = request(&app, "POST", &format!("/api/workflows/redriven-clock/runs/{source_run}/redrive"), json!({})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{redriven}");
    let task = poll(&app, "worker", "workflow").await;
    assert_eq!(task["run_id"], redriven["run_id"]);
    let anchor = clock(&task, "redrive_replayed_started_at");
    // The source run's start, not the successor's fresh one.
    assert_eq!(seconds(&anchor["started_at"]), seconds(&run_started(&app, "redriven-clock", &source["run_id"]).await));
    let history = run_history(&app, &json!("redriven-clock"), &redriven["run_id"]).await;
    assert_eq!(seconds(&anchor["started_at"]), seconds(&history[1]["payload"]["replayed_started_at"]));

    // A redrive whose link no longer agrees proves no anchor; the fresh start
    // never stands in for it.
    finish_task(&app, &task, json!([{"type":"schedule_activity","activity_type":"b","arguments":envelope(Payload::Array(vec![]))}])).await;
    complete_activity(&app, &poll(&app, "worker", "activity").await).await;
    database.execute("UPDATE workflow_links SET sequence=99 WHERE link_type='redrive'").await;
    let unproved = poll(&app, "worker", "workflow").await;
    assert_eq!(unproved["run_id"], redriven["run_id"]);
    assert!(unproved.get("replay_clock").is_none(), "{unproved}");
    runtime.close().await;
    database.remove().await;
}

/// A query request for `workflow`, answered or abandoned by the caller.
fn send_query(app: &Router, workflow: &str) -> tokio::task::JoinHandle<(StatusCode, Value)> {
    let app = app.clone();
    let path = format!("/api/workflows/{workflow}/query/state");
    tokio::spawn(async move { request(&app, "POST", &path, json!({"input":envelope(Payload::Array(vec![Payload::Long(7)]))})).await })
}

#[tokio::test]
async fn query_tasks_carry_their_runs_entry_instant() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    query_worker(&app, "clock-query-worker").await;
    let started = start(&app, "clocked-query").await;
    let task = poll(&app, "clock-query-worker", "workflow").await;
    let entry = clock(&task, "run_started_at");
    let (status, body) = finish_task(&app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Long(7))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");

    // A query rebuilds the body from the same entry instant the workflow task gave it.
    let waiting = send_query(&app, "clocked-query");
    let claim = await_query(&app, "clock-query-worker").await;
    assert_eq!(claim["run_id"], started["run_id"], "{claim}");
    assert_eq!(clock(&claim, "run_started_at"), entry);
    let answered = request(&app, "POST", &format!("/api/worker/query-tasks/{}/complete", claim["query_task_id"].as_str().unwrap()),
        json!({"lease_owner":"clock-query-worker","query_task_attempt":claim["query_task_attempt"],
            "result_envelope":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(answered.0, StatusCode::OK, "{}", answered.1);
    assert_eq!(waiting.await.unwrap().0, StatusCode::OK);

    // A run whose start is not proved has no carrier, as its workflow tasks would.
    let run = started["run_id"].as_str().unwrap();
    database.execute(sqlx::AssertSqlSafe(format!("UPDATE workflow_runs SET started_at=NULL WHERE id='{run}'"))).await;
    let waiting = send_query(&app, "clocked-query");
    let unproved = await_query(&app, "clock-query-worker").await;
    assert_eq!(unproved["run_id"], started["run_id"], "{unproved}");
    // The key stays, as an explicit null an older Server's absence can't be mistaken for.
    assert_eq!(unproved.get("replay_clock"), Some(&Value::Null), "{unproved}");
    waiting.abort();
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn update_validation_tasks_carry_their_runs_entry_instant() {
    use super::update_validation::{complete, poll_validation, send_update, update_body, validating_worker};

    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    validating_worker(&app, "clock-validator", "clock-vq", Some(json!(["approve_me"])), true).await;
    let (status, started) = request(&app, "POST", "/api/workflows", json!({"workflow_id":"clocked-validation",
        "workflow_type":"echo","task_queue":"clock-vq","input":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    let (status, polled) = request(&app, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"clock-validator","task_queue":"clock-vq","timeout_seconds":0})).await;
    assert_eq!(status, StatusCode::OK, "{polled}");
    let task = polled["task"].clone();
    let entry = clock(&task, "run_started_at");
    assert_eq!(finish_task(&app, &task, json!([{"type":"open_signal_wait","signal_name":"finish"}])).await.0, StatusCode::OK);

    let pending = send_update(&app, "clocked-validation", "approve_me", update_body(1, Some("clock-1")));
    let validation = poll_validation(&app, "clock-validator", "clock-vq").await;
    assert_eq!(validation["run_id"], started["run_id"], "{validation}");
    assert_eq!(clock(&validation, "run_started_at"), entry);
    let body = json!({"lease_owner":"clock-validator","update_validation_attempt":validation["update_validation_attempt"]});
    let (status, decided) = complete(&app, validation["update_validation_task_id"].as_str().unwrap(), "approve", body).await;
    assert_eq!(status, StatusCode::OK, "{decided}");
    pending.abort();

    let run = started["run_id"].as_str().unwrap();
    database.execute(sqlx::AssertSqlSafe(format!("UPDATE workflow_runs SET started_at=NULL WHERE id='{run}'"))).await;
    let pending = send_update(&app, "clocked-validation", "approve_me", update_body(2, Some("clock-2")));
    let unproved = poll_validation(&app, "clock-validator", "clock-vq").await;
    assert_eq!(unproved["run_id"], started["run_id"], "{unproved}");
    // The key stays, as an explicit null an older Server's absence can't be mistaken for.
    assert_eq!(unproved.get("replay_clock"), Some(&Value::Null), "{unproved}");
    pending.abort();
    runtime.close().await;
    database.remove().await;
}
