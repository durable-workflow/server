//! A remote activity scheduled under the `abandon` cancellation policy keeps
//! its own lifetime after its run's canonical cooperative cancellation closes
//! the run, as Core's `ActivityAbandonment` does (Server #971). Every other
//! activity, and every other close, keeps today's refusals.
use super::*;

async fn count(database: &TestDatabase, sql: String) -> i64 {
    task_retry::rows(database, Box::leak(sql.into_boxed_str())).await
}

/// Registers the cooperative `cleanup-worker` on protocol 1.20.
async fn register_cooperative(app: &Router) {
    let registered = request_protocol(app, "POST", "/api/worker/register",
        json!({"worker_id":"cleanup-worker","task_queue":"test","runtime":"php",
            "supported_workflow_types":["echo"],"supported_activity_types":["stop-activity","plain-activity"],
            "capabilities":["cooperative_cancellation"]}), "1.20").await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
}

async fn activity_call(app: &Router, task: &Value, outcome: &str, body: Value) -> (StatusCode, Value) {
    let mut body = body;
    body["activity_attempt_id"] = task["activity_attempt_id"].clone();
    body["lease_owner"] = task["lease_owner"].clone();
    request_protocol(app, "POST", &format!("/api/worker/activity-tasks/{}/{outcome}", task["task_id"].as_str().unwrap()), body, "1.20").await
}

fn event_types(history: &Value) -> Vec<String> {
    history.as_array().unwrap().iter().map(|event| event["event_type"].as_str().unwrap().to_owned()).collect()
}

fn position(history: &Value, kind: &str) -> usize {
    event_types(history).iter().position(|event| event == kind).unwrap_or_else(|| panic!("no {kind}: {history}"))
}

/// A cancelled run and the activities it left behind.
struct Cancelled {
    started: Value,
    /// The abandoned `stop-activity` claim, when it was leased before the close.
    abandoned: Value,
    /// The `try_cancel` `plain-activity` claim, when scheduled and leased.
    ordinary: Value,
}

/// Starts `workflow`, schedules the abandoned `stop-activity` (with
/// `options` merged into its command) and, if `ordinary`, a default-policy
/// `plain-activity`. With `lease`, both are claimed first. The run's
/// cancellation is then requested, delivered at the abandoned activity and
/// finished by the workflow, the canonical cooperative close.
async fn abandon_then_cancel(app: &Router, workflow: &str, options: Value, ordinary: bool, lease: bool) -> Cancelled {
    let started = start(app, workflow).await;
    let task = cooperative_poll(app, "workflow").await;
    let mut abandoned = json!({"type":"schedule_activity","activity_type":"stop-activity","arguments":envelope(Payload::Array(vec![])),
        "start_to_close_timeout":300,"schedule_to_close_timeout":600,"cancellation_policy":"abandon"});
    for (field, value) in options.as_object().unwrap() {
        abandoned[field] = value.clone();
    }
    let mut commands = vec![abandoned];
    if ordinary {
        commands.push(json!({"type":"schedule_activity","activity_type":"plain-activity","arguments":envelope(Payload::Array(vec![])),
            "start_to_close_timeout":300}));
    }
    let scheduled = request_protocol(app, "POST", &format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap()),
        completion(&task, json!(commands)), "1.20").await;
    assert_eq!(scheduled.0, StatusCode::OK, "{}", scheduled.1);
    let (mut abandoned, mut plain) = (Value::Null, Value::Null);
    if lease {
        for _ in 0..commands.len() {
            let claim = cooperative_poll(app, "activity").await;
            match claim["activity_type"].as_str() {
                Some("stop-activity") => abandoned = claim,
                Some("plain-activity") => plain = claim,
                _ => panic!("unexpected claim {claim}"),
            }
        }
    }
    let requested = request(app, "POST", &format!("/api/workflows/{workflow}/request-cancellation"),
        json!({"reason":"stop","cleanup_timeout_seconds":60})).await;
    assert_eq!(requested.0, StatusCode::ACCEPTED, "{}", requested.1);
    let request_id = requested.1["cancellation_request"]["request_id"].clone();
    let resumed = cooperative_poll(app, "workflow").await;
    let delivered = request_protocol(app, "POST",
        &format!("/api/worker/workflow-tasks/{}/deliver-cancellation", resumed["task_id"].as_str().unwrap()),
        json!({"lease_owner":resumed["lease_owner"],"workflow_task_attempt":resumed["workflow_task_attempt"],
            "request_id":request_id,"sequence":1,"call_kind":"activity"}), "1.20").await;
    assert_eq!((delivered.0, delivered.1["delivered"].clone()), (StatusCode::OK, json!(true)), "{}", delivered.1);
    let closed = finish_task(app, &resumed, json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await;
    assert_eq!(closed.0, StatusCode::OK, "{}", closed.1);
    let history = run_history(app, &started["workflow_id"], &started["run_id"]).await;
    let terminal = &history[position(&history, "WorkflowCancelled")];
    assert_eq!(terminal["payload"]["cancellation_cleanup"]["request_id"], request_id, "{terminal}");
    Cancelled { started, abandoned, ordinary: plain }
}

async fn run_status(database: &TestDatabase, run: &Value) -> i64 {
    count(database, format!("SELECT COUNT(*) FROM workflow_runs WHERE id='{}' AND status='cancelled'", run.as_str().unwrap())).await
}

// Case 1: an unclaimed abandoned activity stays ready after the close and is
// claimed with an ordinary lease. The ordinary activity is cancelled. The
// abandoned result is recorded and resumes nothing.
#[tokio::test]
async fn an_unclaimed_abandoned_activity_stays_pollable_after_the_cancelled_close() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register_cooperative(&app).await;
    let cancelled = abandon_then_cancel(&app, "abandon-unclaimed", json!({}), true, false).await;
    let run = &cancelled.started["run_id"];
    let history = run_history(&app, &cancelled.started["workflow_id"], run).await;
    let ordinary_cancelled: Vec<_> = history.as_array().unwrap().iter()
        .filter(|event| event["event_type"] == "ActivityCancelled").collect();
    assert_eq!(ordinary_cancelled.len(), 1, "{history}");
    assert_eq!(ordinary_cancelled[0]["payload"]["activity_type"], "plain-activity", "{history}");
    assert_eq!(count(&database, "SELECT COUNT(*) FROM activity_executions WHERE status='pending'".into()).await, 1);
    assert_eq!(count(&database, "SELECT COUNT(*) FROM workflow_tasks WHERE task_type='activity' AND status='ready'".into()).await, 1);

    let claim = cooperative_poll(&app, "activity").await;
    assert_eq!(claim["activity_type"], "stop-activity", "{claim}");
    assert!(cooperative_poll(&app, "activity").await.is_null(), "only the abandoned activity is offered");
    let heartbeat = activity_call(&app, &claim, "heartbeat", json!({})).await;
    assert_eq!((heartbeat.0, heartbeat.1["can_continue"].clone(), heartbeat.1["cancel_requested"].clone(), heartbeat.1["heartbeat_recorded"].clone()),
        (StatusCode::OK, json!(true), json!(false), json!(true)), "{}", heartbeat.1);
    let renewed = activity_call(&app, &claim, "renew-lease", json!({})).await;
    assert_eq!((renewed.0, renewed.1["lease_renewed"].clone(), renewed.1["cancel_requested"].clone()),
        (StatusCode::OK, json!(true), json!(false)), "{}", renewed.1);
    let completed = activity_call(&app, &claim, "complete", json!({"result":envelope(Payload::String("late".into()))})).await;
    assert_eq!((completed.0, completed.1["recorded"].clone(), completed.1["next_task_id"].clone()),
        (StatusCode::OK, json!(true), Value::Null), "{}", completed.1);

    let history = run_history(&app, &cancelled.started["workflow_id"], run).await;
    let closed_at = position(&history, "WorkflowCancelled");
    assert!(position(&history, "ActivityStarted") > closed_at, "{history}");
    assert!(position(&history, "ActivityCompleted") > closed_at, "{history}");
    assert_eq!(run_status(&database, run).await, 1, "the run stays cancelled");
    assert!(cooperative_poll(&app, "workflow").await.is_null(), "nothing resumes the closed run");
    runtime.close().await;
    database.remove().await;
}

// Case 2: an abandoned activity leased before the close completes, or fails
// through its own retry policy, after the close. The ordinary activity on the
// same run stays refused.
#[tokio::test]
async fn a_leased_abandoned_activity_completes_or_fails_after_the_cancelled_close() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register_cooperative(&app).await;

    let completing = abandon_then_cancel(&app, "abandon-completes", json!({}), true, true).await;
    let refused = activity_call(&app, &completing.ordinary, "complete", json!({"result":envelope(Payload::Null)})).await;
    assert_eq!((refused.0, refused.1["reason"].clone(), refused.1["recorded"].clone()),
        (StatusCode::CONFLICT, json!("run_cancelled"), json!(false)), "{}", refused.1);
    let heartbeat = activity_call(&app, &completing.ordinary, "heartbeat", json!({})).await;
    assert_eq!((heartbeat.1["can_continue"].clone(), heartbeat.1["cancel_requested"].clone()), (json!(false), json!(true)), "{}", heartbeat.1);
    let heartbeat = activity_call(&app, &completing.abandoned, "heartbeat", json!({})).await;
    assert_eq!((heartbeat.0, heartbeat.1["can_continue"].clone(), heartbeat.1["cancel_requested"].clone()),
        (StatusCode::OK, json!(true), json!(false)), "{}", heartbeat.1);
    let completed = activity_call(&app, &completing.abandoned, "complete", json!({"result":envelope(Payload::String("done".into()))})).await;
    assert_eq!((completed.0, completed.1["recorded"].clone(), completed.1["next_task_id"].clone()),
        (StatusCode::OK, json!(true), Value::Null), "{}", completed.1);
    let history = run_history(&app, &completing.started["workflow_id"], &completing.started["run_id"]).await;
    assert!(position(&history, "ActivityCompleted") > position(&history, "WorkflowCancelled"), "{history}");
    assert_eq!(run_status(&database, &completing.started["run_id"]).await, 1);

    let failing = abandon_then_cancel(&app, "abandon-fails",
        json!({"retry_policy":{"max_attempts":2,"backoff_seconds":[0]}}), false, true).await;
    let failure = json!({"failure":{"message":"try again","type":"parity.retryable","non_retryable":false}});
    let retried = activity_call(&app, &failing.abandoned, "fail", failure.clone()).await;
    assert_eq!((retried.0, retried.1["recorded"].clone()), (StatusCode::OK, json!(true)), "{}", retried.1);
    let retry = cooperative_poll(&app, "activity").await;
    assert_eq!((retry["activity_type"].clone(), retry["attempt_number"].clone()), (json!("stop-activity"), json!(2)), "{retry}");
    let failed = activity_call(&app, &retry, "fail", failure).await;
    assert_eq!((failed.0, failed.1["recorded"].clone(), failed.1["next_task_id"].clone()),
        (StatusCode::OK, json!(true), Value::Null), "{}", failed.1);
    let history = run_history(&app, &failing.started["workflow_id"], &failing.started["run_id"]).await;
    let closed_at = position(&history, "WorkflowCancelled");
    assert!(position(&history, "ActivityRetryScheduled") > closed_at, "{history}");
    assert!(position(&history, "ActivityFailed") > closed_at, "{history}");
    assert_eq!(run_status(&database, &failing.started["run_id"]).await, 1);
    assert!(cooperative_poll(&app, "workflow").await.is_null(), "nothing resumes either closed run");
    runtime.close().await;
    database.remove().await;
}

// Case 3: a run terminated or completed with an abandoned activity open
// cancels it, and an abandoned activity loses its authority when the history
// that grants it is not intact.
#[tokio::test]
async fn an_abandoned_activity_is_refused_after_any_other_close_or_without_its_history() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register_cooperative(&app).await;
    let abandon = json!({"type":"schedule_activity","activity_type":"stop-activity","arguments":envelope(Payload::Array(vec![])),
        "start_to_close_timeout":300,"schedule_to_close_timeout":600,"cancellation_policy":"abandon"});

    // Terminated after the abandoned activity's delivery.
    start(&app, "abandon-terminated").await;
    let task = cooperative_poll(&app, "workflow").await;
    let scheduled = request_protocol(&app, "POST", &format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap()),
        completion(&task, json!([abandon])), "1.20").await;
    assert_eq!(scheduled.0, StatusCode::OK, "{}", scheduled.1);
    let activity = cooperative_poll(&app, "activity").await;
    let requested = request(&app, "POST", "/api/workflows/abandon-terminated/request-cancellation",
        json!({"reason":"stop","cleanup_timeout_seconds":60})).await;
    let resumed = cooperative_poll(&app, "workflow").await;
    let delivered = request_protocol(&app, "POST",
        &format!("/api/worker/workflow-tasks/{}/deliver-cancellation", resumed["task_id"].as_str().unwrap()),
        json!({"lease_owner":resumed["lease_owner"],"workflow_task_attempt":resumed["workflow_task_attempt"],
            "request_id":requested.1["cancellation_request"]["request_id"],"sequence":1,"call_kind":"activity"}), "1.20").await;
    assert_eq!(delivered.1["delivered"], true, "{}", delivered.1);
    let terminated = request(&app, "POST", "/api/workflows/abandon-terminated/terminate", json!({"reason":"stop now"})).await;
    assert_eq!(terminated.0, StatusCode::OK, "{}", terminated.1);
    let refused = activity_call(&app, &activity, "complete", json!({"result":envelope(Payload::Null)})).await;
    assert_eq!((refused.0, refused.1["reason"].clone()), (StatusCode::CONFLICT, json!("run_terminated")), "{}", refused.1);

    // Completed by the workflow, without a cancellation request, once its
    // other activity resolves.
    start(&app, "abandon-completed").await;
    let task = cooperative_poll(&app, "workflow").await;
    let plain = json!({"type":"schedule_activity","activity_type":"plain-activity","arguments":envelope(Payload::Array(vec![])),
        "start_to_close_timeout":300});
    let scheduled = request_protocol(&app, "POST", &format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap()),
        completion(&task, json!([abandon, plain])), "1.20").await;
    assert_eq!(scheduled.0, StatusCode::OK, "{}", scheduled.1);
    let (first, second) = (cooperative_poll(&app, "activity").await, cooperative_poll(&app, "activity").await);
    let (activity, other) = if first["activity_type"] == "stop-activity" { (first, second) } else { (second, first) };
    let resolved = activity_call(&app, &other, "complete", json!({"result":envelope(Payload::Null)})).await;
    assert_eq!(resolved.0, StatusCode::OK, "{}", resolved.1);
    let turn = cooperative_poll(&app, "workflow").await;
    let finished = finish_task(&app, &turn, json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await;
    assert_eq!(finished.0, StatusCode::OK, "{}", finished.1);
    let refused = activity_call(&app, &activity, "complete", json!({"result":envelope(Payload::Null)})).await;
    assert_eq!((refused.0, refused.1["reason"].clone()), (StatusCode::CONFLICT, json!("run_closed")), "{}", refused.1);
    assert_eq!(count(&database, "SELECT COUNT(*) FROM activity_executions WHERE status IN ('pending','running')".into()).await, 0);

    // The canonical close, then the delivery event lost from history.
    let cancelled = abandon_then_cancel(&app, "abandon-corrupt", json!({}), false, false).await;
    database.execute("DELETE FROM workflow_history_events WHERE event_type='CooperativeCancellationDelivered'").await;
    assert!(cooperative_poll(&app, "activity").await.is_null(), "no authority without the delivery");
    assert_eq!(run_status(&database, &cancelled.started["run_id"]).await, 1);
    runtime.close().await;
    database.remove().await;
}

// Case 4: after the close, the abandoned activity still times out at the
// schedule-to-close deadline its snapshot recorded, into the closed run's
// history, and resumes nothing.
#[tokio::test]
async fn an_abandoned_activity_times_out_at_its_original_deadline_after_the_close() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register_cooperative(&app).await;
    let cancelled = abandon_then_cancel(&app, "abandon-expires", json!({"start_to_close_timeout":3,"schedule_to_close_timeout":3}), false, true).await;
    let (workflow, run) = (&cancelled.started["workflow_id"], &cancelled.started["run_id"]);
    let waited_until = tokio::time::Instant::now() + std::time::Duration::from_secs(10);
    let history = loop {
        let history = run_history(&app, workflow, run).await;
        if event_types(&history).iter().any(|event| event == "ActivityTimedOut") {
            break history;
        }
        assert!(tokio::time::Instant::now() < waited_until, "the deadline sweep never closed it: {history}");
        tokio::time::sleep(std::time::Duration::from_millis(50)).await;
    };
    let scheduled = &history[position(&history, "ActivityScheduled")];
    let timed_out = &history[position(&history, "ActivityTimedOut")];
    assert!(position(&history, "ActivityTimedOut") > position(&history, "WorkflowCancelled"), "{history}");
    assert_eq!(timed_out["payload"]["timeout_kind"], "schedule_to_close", "{timed_out}");
    let original = chrono::DateTime::parse_from_rfc3339(scheduled["payload"]["activity"]["schedule_to_close_deadline_at"].as_str().unwrap()).unwrap();
    let recorded = chrono::DateTime::parse_from_rfc3339(timed_out["payload"]["schedule_to_close_deadline_at"].as_str().unwrap()).unwrap();
    assert_eq!(recorded.timestamp(), original.timestamp(), "{timed_out}");
    let late = activity_call(&app, &cancelled.abandoned, "complete", json!({"result":envelope(Payload::Null)})).await;
    assert_eq!(late.0, StatusCode::CONFLICT, "{}", late.1);
    assert_eq!(count(&database, "SELECT COUNT(*) FROM activity_executions WHERE status='failed'".into()).await, 1);
    assert_eq!(run_status(&database, run).await, 1);
    assert!(cooperative_poll(&app, "workflow").await.is_null(), "nothing resumes the closed run");
    runtime.close().await;
    database.remove().await;
}
