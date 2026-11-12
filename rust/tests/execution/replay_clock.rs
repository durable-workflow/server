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
