//! Server #1282: one run never has two live workflow task leases. A signal
//! that arrives while an activity is held and that activity's result each
//! create a workflow task for the same run. Both replay the same history, so
//! two leases would author the same next command twice. The second task stays
//! ready until the first lease completes or expires, on one node or several
//! nodes sharing the database, and is then delivered with the first task's
//! commands in its history.
use super::*;
use std::time::Duration;

const WORKER: &str = "same-run-worker";

async fn register_signal_worker(app: &Router) {
    let (status, body) = request(app, "POST", "/api/worker/register", json!({"worker_id":WORKER,"task_queue":"test",
        "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":["act"],
        "workflow_command_contracts":{"echo":{"signals":["go"],"signal_contracts":[{"name":"go","parameters":[]}]}}})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

async fn poll_as(app: &Router, kind: &str, timeout: u64) -> Value {
    let (status, body) = request(app, "POST", &format!("/api/worker/{kind}-tasks/poll"),
        json!({"worker_id":WORKER,"task_queue":"test","timeout_seconds":timeout})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body["task"].clone()
}

/// Starts the run, schedules its one activity, then lets a signal and the
/// activity's result each create a workflow task while the activity is held.
async fn two_ready_workflow_tasks(app: &Router, workflow: &str) -> Value {
    let started = start(app, workflow).await;
    let first = poll_as(app, "workflow", 0).await;
    let done = finish_task(app, &first, json!([{"type":"schedule_activity","activity_type":"act",
        "arguments":envelope(Payload::Array(vec![]))}])).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    let activity = poll_as(app, "activity", 0).await;
    assert!(activity.is_object(), "the activity is held");
    let signal = format!("/api/workflows/{workflow}/runs/{}/signal/go", started["run_id"].as_str().unwrap());
    let (status, body) = request(app, "POST", &signal, json!({"input":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    let (status, body) = request(app, "POST", &format!("/api/worker/activity-tasks/{}/complete", activity["task_id"].as_str().unwrap()),
        json!({"lease_owner":WORKER,"activity_attempt_id":activity["activity_attempt_id"],"result":envelope(Payload::Long(7))})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    started
}

fn timer() -> Value {
    json!([{"type":"start_timer","delay_seconds":3600}])
}

fn event_types(task: &Value) -> Vec<String> {
    task["history_events"].as_array().unwrap().iter()
        .map(|event| event["event_type"].as_str().unwrap().to_owned()).collect()
}

async fn scheduled_timers(app: &Router, workflow: &str, started: &Value) -> usize {
    let events = run_history(app, &json!(workflow), &started["run_id"]).await;
    events.as_array().unwrap().iter().filter(|event| event["event_type"] == "TimerScheduled").count()
}

/// Two concurrent polls on two nodes over one database: one leases, the
/// other finds nothing, though the run holds a second ready workflow task.
/// The second task is delivered after the first completes, with the first
/// task's timer in its history, and the run records one timer.
#[tokio::test]
async fn concurrent_same_run_polls_lease_one_workflow_task_across_nodes() {
    let database = TestDatabase::new().await;
    let node_a = database.open().await.unwrap();
    let node_b = database.open().await.unwrap();
    let (app_a, app_b) = (router(node_a.clone()), router(node_b.clone()));
    register_signal_worker(&app_a).await;
    let started = two_ready_workflow_tasks(&app_a, "same-run-nodes").await;
    let ready = text_rows(&database,
        "SELECT id, status FROM workflow_tasks WHERE task_type='workflow' AND status='ready'",
        "SELECT id, status FROM workflow_tasks WHERE task_type='workflow' AND status='ready'",
        "SELECT id, status FROM workflow_tasks WHERE task_type='workflow' AND status='ready'").await;
    assert_eq!(ready.len(), 2, "the signal and the activity result each wait in a ready task");

    let (a, b) = tokio::join!(poll_as(&app_a, "workflow", 0), poll_as(&app_b, "workflow", 0));
    assert_ne!(a.is_null(), b.is_null(), "exactly one workflow task of the run is leased: {} {}", a["task_id"], b["task_id"]);
    let leased = if a.is_null() { b } else { a };
    let history = event_types(&leased);
    assert!(history.contains(&"ActivityCompleted".to_owned()) && history.contains(&"SignalReceived".to_owned()), "{history:?}");
    // Another poll, on either node, still leases nothing for this run.
    assert!(poll_as(&app_a, "workflow", 0).await.is_null());
    assert!(poll_as(&app_b, "workflow", 0).await.is_null());

    let done = finish_task(&app_b, &leased, timer()).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    let next = poll_as(&app_a, "workflow", 0).await;
    assert!(next.is_object(), "the second task is delivered after the first completes");
    assert_ne!(next["task_id"], leased["task_id"]);
    assert!(event_types(&next).contains(&"TimerScheduled".to_owned()), "{:?}", event_types(&next));
    // The worker replays the timer it already scheduled and authors nothing new.
    let done = finish_task(&app_a, &next, json!([{"type":"open_signal_wait","signal_name":"never"}])).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    assert_eq!(scheduled_timers(&app_a, "same-run-nodes", &started).await, 1);
    node_a.close().await;
    node_b.close().await;
    database.remove().await;
}

/// A held poll waits while the run's other workflow task is leased, and
/// leases the waiting task as soon as that lease completes.
#[tokio::test]
async fn a_held_poll_leases_the_waiting_same_run_task_after_the_first_completes() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register_signal_worker(&app).await;
    let started = two_ready_workflow_tasks(&app, "same-run-held").await;
    let first = poll_as(&app, "workflow", 0).await;
    assert!(first.is_object());
    let held = {
        let app = app.clone();
        tokio::spawn(async move { poll_as(&app, "workflow", 5).await })
    };
    tokio::time::sleep(Duration::from_millis(700)).await;
    assert!(!held.is_finished(), "the held poll does not lease the run's second task");
    let done = finish_task(&app, &first, timer()).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    let second = tokio::time::timeout(Duration::from_secs(4), held).await.expect("held poll answers").unwrap();
    assert!(second.is_object(), "the held poll leases the second task");
    assert_ne!(second["task_id"], first["task_id"]);
    assert!(event_types(&second).contains(&"TimerScheduled".to_owned()));
    assert_eq!(scheduled_timers(&app, "same-run-held", &started).await, 1);
    runtime.close().await;
    database.remove().await;
}

/// An expired lease no longer fences the run: the next poll recovers work,
/// and the expired lease's late completion is refused, so the run still
/// records one timer.
#[tokio::test]
async fn an_expired_same_run_lease_releases_the_fence_and_its_late_completion_is_refused() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register_signal_worker(&app).await;
    let started = two_ready_workflow_tasks(&app, "same-run-expired").await;
    let first = poll_as(&app, "workflow", 0).await;
    assert!(poll_as(&app, "workflow", 0).await.is_null());
    database.execute(sqlx::AssertSqlSafe(format!("UPDATE workflow_tasks SET lease_expires_at='2000-01-01 00:00:00' WHERE id='{}'",
        first["task_id"].as_str().unwrap()))).await;
    let recovered = poll_as(&app, "workflow", 0).await;
    assert!(recovered.is_object(), "an expired lease does not strand the run");
    let late = finish_task(&app, &first, timer()).await;
    assert_eq!(late.0, StatusCode::CONFLICT, "{}", late.1);
    let done = finish_task(&app, &recovered, timer()).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    assert_eq!(scheduled_timers(&app, "same-run-expired", &started).await, 1);
    runtime.close().await;
    database.remove().await;
}

/// Different runs on one queue still lease in parallel.
#[tokio::test]
async fn workflow_tasks_of_different_runs_still_lease_in_parallel() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register_signal_worker(&app).await;
    start(&app, "parallel-run-a").await;
    start(&app, "parallel-run-b").await;
    let (a, b) = tokio::join!(poll_as(&app, "workflow", 0), poll_as(&app, "workflow", 0));
    assert!(a.is_object() && b.is_object(), "{} {}", a["task_id"], b["task_id"]);
    assert_ne!(a["run_id"], b["run_id"]);
    runtime.close().await;
    database.remove().await;
}
