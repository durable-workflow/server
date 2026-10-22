//! Queries admitted while their run has an open workflow task, as in published
//! PHP's `WorkflowQueryTaskBroker`: the query waits for its claim instead of
//! being refused, and a task leased at admission fixes its history cutoff.
use super::*;

async fn register_query_worker(app: &Router, worker: &str) {
    let (status, body) = request(app, "POST", "/api/worker/register", json!({
        "worker_id":worker,"task_queue":"test","runtime":"php","supported_workflow_types":["echo"],
        "supported_activity_types":[],"capabilities":["query_tasks"],
        "workflow_command_contracts":{"echo":{"queries":["state"],"signals":["go"],
            "signal_contracts":[{"name":"go","parameters":[]}]}}})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

async fn query_poll(app: &Router, worker: &str, timeout: u64) -> Value {
    let (status, body) = request(app, "POST", "/api/worker/query-tasks/poll",
        json!({"worker_id":worker,"task_queue":"test","timeout_seconds":timeout})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body
}

fn query_request(app: &Router, workflow: &str, run: &Value) -> tokio::task::JoinHandle<(StatusCode, Value)> {
    let app = app.clone();
    let path = format!("/api/workflows/{workflow}/runs/{}/query/state", run.as_str().unwrap());
    tokio::spawn(async move {
        request(&app, "POST", &path, json!({"input":envelope(Payload::Array(vec![]))})).await
    })
}

/// Polls until the query is admitted and the poll reports something other
/// than an empty queue.
async fn admitted_poll(app: &Router, worker: &str, timeout: u64) -> Value {
    tokio::time::timeout(std::time::Duration::from_secs(10), async {
        loop {
            let body = query_poll(app, worker, timeout).await;
            if body["poll_status"] != "empty" {
                break body;
            }
            tokio::time::sleep(std::time::Duration::from_millis(20)).await;
        }
    })
    .await
    .expect("the query is admitted")
}

async fn answer(app: &Router, claim: &Value, value: i64) {
    let (status, body) = request(app, "POST",
        &format!("/api/worker/query-tasks/{}/complete", claim["query_task_id"].as_str().unwrap()),
        json!({"lease_owner":claim["lease_owner"],"query_task_attempt":claim["query_task_attempt"],
            "result_envelope":envelope(Payload::Long(value))})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
}

fn sequences(claim: &Value) -> Vec<i64> {
    claim["history_events"]
        .as_array()
        .unwrap()
        .iter()
        .map(|event| event["sequence"].as_i64().unwrap())
        .collect()
}

#[tokio::test]
async fn a_query_during_a_leased_workflow_task_waits_and_reads_history_to_its_cutoff() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register_query_worker(&app, "query-worker").await;
    let started = start(&app, "leased-query").await;
    let task = poll(&app, "query-worker", "workflow").await;
    let before = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let cutoff = before.as_array().unwrap().len() as i64;
    let query = query_request(&app, "leased-query", &started["run_id"]);
    // While the task stays leased the query waits, and a poll that finds
    // only held queries ends as PHP's `workflow_task_leased`.
    let held = admitted_poll(&app, "query-worker", 1).await;
    assert_eq!(held["task"], Value::Null, "{held}");
    assert_eq!(held["poll_status"], "workflow_task_leased");
    assert!(!query.is_finished());
    assert_eq!(
        finish_task(&app, &task, json!([{"type":"start_timer","delay_seconds":60}])).await.0,
        StatusCode::OK
    );
    let claim = query_poll(&app, "query-worker", 5).await;
    assert_eq!(claim["poll_status"], "leased", "{claim}");
    let claim = &claim["task"];
    // The task leased at admission fixed the history the query reads.
    assert_eq!(claim["history_cutoff_sequence"], cutoff);
    assert_eq!(claim["last_history_sequence"], cutoff);
    assert_eq!(sequences(claim), (1..=cutoff).collect::<Vec<_>>());
    let after = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert!(after.as_array().unwrap().len() as i64 > cutoff);
    answer(&app, claim, 7).await;
    let (status, body) = query.await.unwrap();
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["result_envelope"], envelope(Payload::Long(7)));
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_ready_resume_task_holds_the_query_until_the_worker_applies_it() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register_query_worker(&app, "query-worker").await;
    let started = start(&app, "resumed-query").await;
    let task = poll(&app, "query-worker", "workflow").await;
    assert_eq!(
        finish_task(&app, &task, json!([{"type":"start_timer","delay_seconds":1}])).await.0,
        StatusCode::OK
    );
    // The fired timer's resume task is ready but its effect is not in history.
    let resumed = tokio::time::timeout(std::time::Duration::from_secs(10), async {
        loop {
            let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
            if history.as_array().unwrap().iter().any(|event| event["event_type"] == "TimerFired") {
                break;
            }
            tokio::time::sleep(std::time::Duration::from_millis(50)).await;
        }
    });
    resumed.await.expect("the timer fires");
    let query = query_request(&app, "resumed-query", &started["run_id"]);
    // PHP ends such a poll at once with `workflow_task_pending`.
    let pending = admitted_poll(&app, "query-worker", 5).await;
    assert_eq!(pending["task"], Value::Null, "{pending}");
    assert_eq!(pending["poll_status"], "workflow_task_pending");
    let resume = poll(&app, "query-worker", "workflow").await;
    assert!(!resume.is_null());
    // Leased, the resume task still holds the query.
    assert_eq!(query_poll(&app, "query-worker", 1).await["poll_status"], "workflow_task_leased");
    assert_eq!(
        finish_task(&app, &resume, json!([{"type":"start_timer","delay_seconds":60}])).await.0,
        StatusCode::OK
    );
    let claim = query_poll(&app, "query-worker", 5).await;
    assert_eq!(claim["poll_status"], "leased", "{claim}");
    let claim = &claim["task"];
    // Admitted without a leased task, the query reads the history at its claim.
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let last = history.as_array().unwrap().len() as i64;
    assert_eq!(claim["history_cutoff_sequence"], Value::Null);
    assert_eq!(claim["last_history_sequence"], last);
    assert_eq!(sequences(claim), (1..=last).collect::<Vec<_>>());
    answer(&app, claim, 8).await;
    assert_eq!(query.await.unwrap().0, StatusCode::OK);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_runs_first_task_and_an_observed_signal_do_not_hold_the_query() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register_query_worker(&app, "query-worker").await;
    let started = start(&app, "first-task-query").await;
    // A ready first task is not a resume: the query is claimed at once.
    let query = query_request(&app, "first-task-query", &started["run_id"]);
    let claim = admitted_poll(&app, "query-worker", 5).await;
    assert_eq!(claim["poll_status"], "leased", "{claim}");
    assert_eq!(claim["task"]["history_cutoff_sequence"], Value::Null);
    answer(&app, &claim["task"], 1).await;
    assert_eq!(query.await.unwrap().0, StatusCode::OK);
    let task = poll(&app, "query-worker", "workflow").await;
    assert_eq!(
        finish_task(&app, &task, json!([{"type":"open_signal_wait","signal_name":"go"}])).await.0,
        StatusCode::OK
    );
    let signal = request(&app, "POST",
        &format!("/api/workflows/first-task-query/runs/{}/signal/go", started["run_id"].as_str().unwrap()),
        json!({"input":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(signal.0, StatusCode::ACCEPTED, "{}", signal.1);
    // The signal's resume task is ready, but the query already observes its
    // `SignalReceived`, so it does not wait for the worker.
    let query = query_request(&app, "first-task-query", &started["run_id"]);
    let claim = admitted_poll(&app, "query-worker", 5).await;
    assert_eq!(claim["poll_status"], "leased", "{claim}");
    let events = &claim["task"]["history_events"];
    assert_eq!(events.as_array().unwrap().last().unwrap()["event_type"], "SignalReceived");
    answer(&app, &claim["task"], 2).await;
    assert_eq!(query.await.unwrap().0, StatusCode::OK);
    runtime.close().await;
    database.remove().await;
}
