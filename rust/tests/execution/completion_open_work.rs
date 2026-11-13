use super::*;

fn events_of<'a>(history: &'a Value, kind: &str) -> Vec<&'a Value> {
    history.as_array().unwrap().iter().filter(|event| event["event_type"] == kind).collect()
}

async fn fired(app: &Router, started: &Value) {
    tokio::time::timeout(std::time::Duration::from_secs(10), async {
        loop {
            let history = run_history(app, &started["workflow_id"], &started["run_id"]).await;
            if !events_of(&history, "TimerFired").is_empty() {
                break;
            }
            tokio::time::sleep(std::time::Duration::from_millis(50)).await;
        }
    })
    .await
    .expect("the timer fires");
}

fn signal_worker(worker: &str) -> Value {
    json!({"worker_id":worker,"task_queue":"test","runtime":"php",
        "supported_workflow_types":["echo"],"supported_activity_types":["activity-echo"],
        "workflow_command_contracts":{"echo":{"signals":["go","other"],
            "signal_contracts":[{"name":"go","parameters":[]},{"name":"other","parameters":[]}]}}})
}

async fn signal(app: &Router, workflow: &str, name: &str) {
    let (status, body) = request(app, "POST", &format!("/api/workflows/{workflow}/signal/{name}"),
        json!({"input":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
}

// Server #1193 (Core workflow#1117): a run's own completion settles what it
// left open. The open activity is cancelled with `cancellation_reason:
// run_closed` and the pending timer is cancelled, both before the terminal
// event; nothing is appended after it. The activity's late heartbeat and
// result learn the run closed (`run_closed`) and change nothing.
#[tokio::test]
async fn a_run_completes_over_an_open_activity_timer_and_wait() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!(["activity-echo"])).await;
    let started = start(&app, "open-work").await;
    let first = poll(&app, "worker", "workflow").await;
    let activity = json!({"type":"schedule_activity","activity_type":"activity-echo","arguments":envelope(Payload::Array(vec![]))});
    let (status, body) = finish_task(&app, &first, json!([activity, {"type":"start_timer","delay_seconds":1},
        {"type":"start_timer","delay_seconds":3600}, {"type":"open_signal_wait","signal_name":"go"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let claimed = poll(&app, "worker", "activity").await;
    assert!(claimed["task_id"].is_string(), "{claimed}");
    fired(&app, &started).await;

    let resumed = poll(&app, "worker", "workflow").await;
    let (status, body) = finish_task(&app, &resumed, json!([{"type":"complete_workflow","result":envelope(Payload::Long(7))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (_, described) = request(&app, "GET", "/api/workflows/open-work", Value::Null).await;
    assert_eq!(described["status"], "completed", "{described}");
    // As PHP's RunSummaryProjector, a closed run reports no open wait.
    assert!(described["wait_kind"].is_null(), "{described}");
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let types: Vec<&str> = history.as_array().unwrap().iter().map(|event| event["event_type"].as_str().unwrap()).collect();
    assert_eq!(types.last(), Some(&"WorkflowCompleted"), "nothing is appended after the terminal event: {history}");
    let cancelled = events_of(&history, "ActivityCancelled");
    assert_eq!(cancelled.len(), 1, "{history}");
    assert_eq!(cancelled[0]["payload"]["cancellation_reason"], "run_closed", "{history}");
    assert_eq!(cancelled[0]["payload"]["activity_attempt_id"], claimed["activity_attempt_id"], "{history}");
    // Only the pending hour-long timer is cancelled; the fired one is not.
    let timers = events_of(&history, "TimerCancelled");
    assert_eq!(timers.len(), 1, "{history}");
    assert_eq!(timers[0]["payload"]["delay_seconds"], 3600, "{history}");
    assert!(events_of(&history, "ActivityCompleted").is_empty(), "{history}");

    let attempt = json!({"lease_owner":claimed["lease_owner"],"activity_attempt_id":claimed["activity_attempt_id"]});
    let task_path = format!("/api/worker/activity-tasks/{}", claimed["task_id"].as_str().unwrap());
    let (status, heartbeat) = request(&app, "POST", &format!("{task_path}/heartbeat"), attempt.clone()).await;
    assert_eq!(status, StatusCode::OK, "{heartbeat}");
    assert_eq!((heartbeat["can_continue"].clone(), heartbeat["cancel_requested"].clone(), heartbeat["reason"].clone()),
        (json!(false), json!(true), json!("run_closed")), "{heartbeat}");
    let mut result = attempt.clone();
    result["result"] = envelope(Payload::Long(1));
    let (status, late) = request(&app, "POST", &format!("{task_path}/complete"), result).await;
    assert_eq!(status, StatusCode::CONFLICT, "{late}");
    assert_eq!((late["outcome"].clone(), late["recorded"].clone(), late["reason"].clone(), late["next_task_id"].clone(),
        late["activity_status"].clone(), late["run_status"].clone()),
        (json!("ignored"), json!(false), json!("run_closed"), Value::Null, json!("cancelled"), json!("completed")), "{late}");
    let mut failure = attempt.clone();
    failure["failure"] = json!({"type":"RuntimeException","message":"late","non_retryable":false});
    let (status, late) = request(&app, "POST", &format!("{task_path}/fail"), failure).await;
    assert_eq!(status, StatusCode::CONFLICT, "{late}");
    assert_eq!((late["outcome"].clone(), late["recorded"].clone(), late["reason"].clone()),
        (json!("ignored"), json!(false), json!("run_closed")), "{late}");
    assert_eq!(run_history(&app, &started["workflow_id"], &started["run_id"]).await, history);
    assert!(poll(&app, "worker", "workflow").await.is_null(), "a closed run gets no further task");

    // The settled activity does not hold the run's details from retention.
    for table in ["workflow_runs", "workflow_run_summaries"] {
        database.execute(sqlx::AssertSqlSafe(format!("UPDATE {table} SET closed_at='2020-01-01 00:00:00.000000' WHERE id='{}'",
            started["run_id"].as_str().unwrap()))).await;
    }
    let (status, pruned) = request(&app, "POST", "/api/system/retention/pass", json!({"run_ids":[started["run_id"]]})).await;
    assert_eq!(status, StatusCode::OK, "{pruned}");
    assert_eq!(pruned["results"][0]["outcome"], "pruned", "{pruned}");
}

// Server 2.10.0 holds a worker's completion when a signal committed after
// the worker read history and the open wait can take it. The run waits,
// the signal gets its own task, and the next completion closes the run.
#[tokio::test]
async fn a_signal_received_during_the_closing_task_defers_the_completion() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    assert_eq!(request(&app, "POST", "/api/worker/register", signal_worker("closer")).await.0, StatusCode::CREATED);
    let started = start(&app, "deferred-close").await;
    let first = poll(&app, "closer", "workflow").await;
    let (status, body) = finish_task(&app, &first, json!([{"type":"start_timer","delay_seconds":1},
        {"type":"open_signal_wait","signal_name":"go"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    fired(&app, &started).await;
    let closing = poll(&app, "closer", "workflow").await;
    signal(&app, "deferred-close", "go").await;

    let (status, body) = finish_task(&app, &closing, json!([{"type":"complete_workflow","result":envelope(Payload::Long(1))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["run_status"], "waiting", "{body}");
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert!(events_of(&history, "WorkflowCompleted").is_empty(), "{history}");
    assert!(events_of(&history, "SignalRejected").is_empty(), "{history}");

    let delivered = poll(&app, "closer", "workflow").await;
    assert_eq!(delivered["run_id"], started["run_id"], "{delivered}");
    let (status, body) = finish_task(&app, &delivered, json!([{"type":"complete_workflow","result":envelope(Payload::Long(2))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["run_status"], "completed", "{body}");
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(events_of(&history, "SignalApplied").len(), 1, "{history}");
    assert_eq!(events_of(&history, "WorkflowCompleted").len(), 1, "{history}");
}

// A received signal that cannot advance the run does not hold the close: the
// open wait cannot take it, and a run started before any worker declared a
// contract delivers nothing without a wait. The run completes and rejects
// that signal, as PHP does. (On a run with a durable contract every admitted
// signal is declared, and a declared signal holds the close; see
// declared_signals.rs.)
#[tokio::test]
async fn a_signal_the_open_wait_cannot_take_does_not_defer_the_completion() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let started = start(&app, "undeferred-close").await;
    assert_eq!(request(&app, "POST", "/api/worker/register", signal_worker("closer")).await.0, StatusCode::CREATED);
    let first = poll(&app, "closer", "workflow").await;
    let (status, body) = finish_task(&app, &first, json!([{"type":"start_timer","delay_seconds":1},
        {"type":"open_signal_wait","signal_name":"go"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    fired(&app, &started).await;
    let closing = poll(&app, "closer", "workflow").await;
    signal(&app, "undeferred-close", "other").await;

    let (status, body) = finish_task(&app, &closing, json!([{"type":"complete_workflow","result":envelope(Payload::Long(1))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["run_status"], "completed", "{body}");
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(events_of(&history, "SignalRejected").len(), 1, "{history}");
}

// Server #1193: continuing as new also settles the predecessor's open
// activities and timers before its terminal event. Nothing of it moves to
// the continued run, and the cancelled activity task is never delivered.
#[tokio::test]
async fn continuing_as_new_settles_the_predecessors_open_work() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!(["activity-echo"])).await;
    let started = start(&app, "continued-open-work").await;
    let first = poll(&app, "worker", "workflow").await;
    let activity = json!({"type":"schedule_activity","activity_type":"activity-echo","arguments":envelope(Payload::Array(vec![]))});
    let (status, body) = finish_task(&app, &first, json!([activity, {"type":"start_timer","delay_seconds":3600},
        {"type":"continue_as_new"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let types: Vec<&str> = history.as_array().unwrap().iter().map(|event| event["event_type"].as_str().unwrap()).collect();
    assert_eq!(types[types.len() - 5..], ["ActivityScheduled", "TimerScheduled", "ActivityCancelled", "TimerCancelled",
        "WorkflowContinuedAsNew"], "{history}");
    assert_eq!(events_of(&history, "ActivityCancelled")[0]["payload"]["cancellation_reason"], "run_closed", "{history}");
    assert!(poll(&app, "worker", "activity").await.is_null(), "the settled activity is never delivered");
    let continued = poll(&app, "worker", "workflow").await;
    assert_ne!(continued["run_id"], started["run_id"], "{continued}");
    let successor = run_history(&app, &started["workflow_id"], &continued["run_id"]).await;
    for kind in ["ActivityScheduled", "ActivityCancelled", "TimerScheduled", "TimerCancelled"] {
        assert!(events_of(&successor, kind).is_empty(), "{kind}: {successor}");
    }
}

// Core's "a cancellation recorded at close never replaces the failure the
// workflow handled": a failure attributed to the latest ActivityFailed keeps
// its attribution although the close then cancels a sibling activity.
#[tokio::test]
async fn a_close_cancellation_never_replaces_the_attributed_activity_failure() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!(["activity-echo"])).await;
    let started = start(&app, "attributed-close").await;
    let first = poll(&app, "worker", "workflow").await;
    let activity = json!({"type":"schedule_activity","activity_type":"activity-echo","arguments":envelope(Payload::Array(vec![]))});
    assert_eq!(finish_task(&app, &first, json!([activity.clone(), activity])).await.0, StatusCode::OK);
    let claimed = poll(&app, "worker", "activity").await;
    let (status, body) = request(&app, "POST", &format!("/api/worker/activity-tasks/{}/fail", claimed["task_id"].as_str().unwrap()),
        json!({"lease_owner":claimed["lease_owner"],"activity_attempt_id":claimed["activity_attempt_id"],
            "failure":{"type":"RuntimeException","message":"boom","non_retryable":true}})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let failed = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let failure = events_of(&failed, "ActivityFailed")[0]["payload"].clone();
    let resumed = poll(&app, "worker", "workflow").await;
    let terminal = json!({"type":"fail_workflow","message":"handled","failed_step_sequence":failure["sequence"],
        "failed_activity_execution_id":failure["activity_execution_id"]});
    let (status, body) = finish_task(&app, &resumed, json!([terminal])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let events = history.as_array().unwrap();
    let terminal = &events[events.len() - 1];
    assert_eq!(terminal["event_type"], "WorkflowFailed", "{history}");
    assert_eq!((terminal["payload"]["failed_step_kind"].clone(), terminal["payload"]["failed_step_sequence"].clone()),
        (json!("activity"), failure["sequence"].clone()), "{history}");
    let sibling = &events[events.len() - 2];
    assert_eq!((sibling["event_type"].clone(), sibling["payload"]["cancellation_reason"].clone()),
        (json!("ActivityCancelled"), json!("run_closed")), "{history}");
    assert_ne!(sibling["payload"]["activity_execution_id"], failure["activity_execution_id"], "{history}");
}
