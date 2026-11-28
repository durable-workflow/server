use super::*;

fn kinds(history: &Value) -> Vec<String> {
    history.as_array().unwrap().iter().map(|event| event["event_type"].as_str().unwrap().to_owned()).collect()
}

fn event<'a>(history: &'a Value, kind: &str) -> &'a Value {
    history.as_array().unwrap().iter().find(|event| event["event_type"] == kind).unwrap_or_else(|| panic!("{kind} in {history}"))
}

// Workflow 2.9.0 `ContinuedRunMessageHandoff` moves received declared
// signals and accepted updates not yet applied to the continued run. A
// declared signal can advance an external worker's run without a wait, so
// Server 2.10.0 first holds the continue-as-new and delivers that signal.
// The continue-as-new from the signal's task then carries only the update,
// recorded again on the continued run.
#[tokio::test]
async fn continue_as_new_delivers_declared_signals_first_and_carries_pending_updates_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    update_worker(&app, "carry-worker").await;
    let started = start(&app, "carry").await;
    let task = poll(&app, "carry-worker", "workflow").await;

    let (status, signal) = request(&app, "POST", "/api/workflows/carry/signal/finish",
        json!({"input":envelope(Payload::Array(vec![Payload::Long(7)]))})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{signal}");
    let (status, update) = request(&app, "POST", "/api/workflows/carry/update/set",
        json!({"input":envelope(Payload::Array(vec![Payload::Long(5)])),"wait_for":"accepted"})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{update}");

    // The worker read history before the signal: its continue-as-new waits.
    let (status, body) = finish_task(&app, &task, json!([{"type":"continue_as_new"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (_, described) = request(&app, "GET", "/api/workflows/carry", Value::Null).await;
    assert_eq!(described["run_id"], started["run_id"], "{described}");
    let delivery = poll(&app, "carry-worker", "workflow").await;
    assert_eq!(delivery["run_id"], started["run_id"], "{delivery}");
    assert_eq!(delivery["workflow_command_id"], signal["command_id"], "the signal goes before the later update");
    let (status, body) = finish_task(&app, &delivery, json!([{"type":"continue_as_new"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (_, described) = request(&app, "GET", "/api/workflows/carry", Value::Null).await;
    let next = described["run_id"].clone();
    assert_ne!(next, started["run_id"], "{described}");

    let old = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(kinds(&old).last().map(String::as_str), Some("WorkflowContinuedAsNew"), "{old}");
    assert!(!kinds(&old).iter().any(|kind| kind == "SignalRejected" || kind == "UpdateCompleted"), "{old}");
    let applied = &event(&old, "SignalApplied")["payload"];
    assert_eq!(applied["workflow_command_id"], signal["command_id"], "{applied}");
    assert!(applied.get("sequence").is_none(), "applied without a wait: {applied}");

    let continued = run_history(&app, &started["workflow_id"], &next).await;
    let order: Vec<_> = kinds(&continued).into_iter().filter(|kind| kind != "StartAccepted").collect();
    assert_eq!(order, ["WorkflowStarted", "UpdateAccepted"], "{continued}");
    let accepted = &event(&continued, "UpdateAccepted")["payload"];
    assert_eq!(accepted["update_id"], update["update_id"], "{accepted}");
    assert_eq!(accepted["workflow_command_id"], update["command_id"]);
    assert_eq!(accepted["workflow_run_id"], next);
    assert!(accepted.get("ordering_state").is_none(), "PHP's handoff records no ordering state");

    // The continued run's first task replays the moved update, and the next
    // task delivers it on the continued run.
    let task = poll(&app, "carry-worker", "workflow").await;
    assert_eq!(task["run_id"], next, "{task}");
    let events: Vec<_> = task["history_events"].as_array().unwrap().iter().map(|event| event["event_type"].clone()).collect();
    assert!(events.contains(&json!("UpdateAccepted")), "{events:?}");
    let (status, body) = finish_task(&app, &task, json!([{"type":"open_signal_wait","signal_name":"finish"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let delivery = poll(&app, "carry-worker", "workflow").await;
    assert_eq!(delivery["run_id"], next, "{delivery}");
    assert_eq!(delivery["workflow_command_id"], update["command_id"], "{delivery}");
}

async fn poll_signal_successor(app: &Router) -> Value {
    let (status, body) = request(app, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"signal-successor","task_queue":"successor","timeout_seconds":0})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body["task"].clone()
}

async fn signal_continued_run(app: &Router, run: &Value, name: &str) -> Value {
    let (status, body) = request(app, "POST",
        &format!("/api/workflows/carry-signal/runs/{}/signal/{name}", run.as_str().unwrap()),
        json!({"input":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    body
}

async fn acknowledge_continued_signal(app: &Router, run: &Value, receipt: &Value) {
    let delivery = poll_signal_successor(app).await;
    assert_eq!(delivery["run_id"], *run, "{delivery}");
    assert_eq!(delivery["workflow_command_id"], receipt["command_id"], "{delivery}");
    let (status, body) = request(app, "POST",
        &format!("/api/worker/workflow-tasks/{}/fail", delivery["task_id"].as_str().unwrap()),
        json!({"lease_owner":delivery["lease_owner"],"workflow_task_attempt":delivery["workflow_task_attempt"],
            "failure":{"type":"WorkflowTaskWaitingForHistory","message":"the original timer is still pending"}})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert!(poll_signal_successor(app).await.is_null());
}

#[tokio::test]
async fn continued_run_delivers_only_its_bound_declared_signals_while_waiting_on_a_timer() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "signal-predecessor").await;
    let started = start(&app, "carry-signal").await;
    let task = poll(&app, "signal-predecessor", "workflow").await;
    let (status, body) = finish_task(&app, &task,
        json!([{"type":"continue_as_new","workflow_type":"successor-type","queue":"successor"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");

    let (status, body) = request(&app, "POST", "/api/worker/register", json!({
        "worker_id":"signal-successor","task_queue":"successor","runtime":"python",
        "supported_workflow_types":["successor-type"],"supported_activity_types":[],
        "workflow_command_contracts":{"successor-type":{"signals":["wake"]}}})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    let first = poll_signal_successor(&app).await;
    let run = &first["run_id"];
    assert!(run.is_string() && *run != started["run_id"], "{first}");
    let early = signal_continued_run(&app, run, "wake").await;
    let before = run_history(&app, &started["workflow_id"], run).await;
    assert_eq!(event(&before,"WorkflowStarted")["payload"]["continued_from_run_id"], started["run_id"]);
    assert_eq!(kinds(&before), ["WorkflowStarted", "SignalReceived"], "{before}");
    let (status, body) = finish_task(&app, &first, json!([{"type":"start_timer","delay_seconds":600}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    acknowledge_continued_signal(&app, run, &early).await;

    // The old type declared `finish`. Its declaration must not authorize a
    // standalone delivery on the successor, even after that run has bound.
    let undeclared = signal_continued_run(&app, run, "finish").await;
    assert!(poll_signal_successor(&app).await.is_null());
    let late = signal_continued_run(&app, run, "wake").await;
    acknowledge_continued_signal(&app, run, &late).await;

    let history = run_history(&app, &started["workflow_id"], run).await;
    let history_events = history.as_array().unwrap();
    let applied = history_events.iter().filter(|event| event["event_type"] == "SignalApplied")
        .map(|event| (&event["payload"]["workflow_command_id"], &event["payload"]["signal_id"]))
        .collect::<Vec<_>>();
    assert_eq!(applied.len(), 2, "{history}");
    for ((command, signal), receipt) in applied.into_iter().zip([&early, &late]) {
        assert_eq!(*command, receipt["command_id"], "{history}");
        assert!(history_events.iter().any(|event| event["event_type"] == "SignalReceived"
            && event["payload"]["signal_id"] == *signal), "{history}");
    }
    assert!(history_events.iter().any(|event| event["event_type"] == "SignalReceived"
        && event["payload"]["workflow_command_id"] == undeclared["command_id"]), "{history}");
    let kinds = kinds(&history);
    assert_eq!(kinds.iter().filter(|kind| *kind == "TimerScheduled").count(), 1, "{history}");
    assert!(!kinds.iter().any(|kind| matches!(kind.as_str(), "TimerFired" | "TimerCancelled" | "ConditionWaitOpened" | "SignalWaitOpened")), "{history}");
    runtime.close().await;
    database.remove().await;
}
