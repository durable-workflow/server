use super::*;

// Workflow 2.9.0 `PendingMessageTask` and `UnprojectedSignalDelivery`: the
// server cannot load an external worker's workflow class, so a run delivers
// a received signal its durable command contract declares even when no wait
// for it is open. The worker gets a signal task and the signal is applied
// without a workflow sequence. An undeclared signal, or any signal of a run
// without a complete durable contract, waits for a wait that takes it.

fn declaring_worker(worker: &str) -> Value {
    json!({"worker_id":worker,"task_queue":"test","runtime":"php","supported_workflow_types":["echo"],
        "supported_activity_types":[],"workflow_command_contracts":{"echo":{"signals":["note"],
            "signal_contracts":[{"name":"note","parameters":[]}]}}})
}

async fn signal(app: &Router, workflow: &str, name: &str) -> Value {
    let (status, body) = request(app, "POST", &format!("/api/workflows/{workflow}/signal/{name}"), json!([])).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    body
}

fn events<'a>(history: &'a Value, kind: &str) -> Vec<&'a Value> {
    history.as_array().unwrap().iter().filter(|event| event["event_type"] == kind).collect()
}

/// A started run of the declaring worker, waiting on a long timer.
async fn waiting_on_a_timer(app: &Router, workflow: &str) -> Value {
    let started = start(app, workflow).await;
    let task = poll(app, "declaring", "workflow").await;
    let (status, body) = finish_task(app, &task, json!([{"type":"start_timer","delay_seconds":3600}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    started
}

#[tokio::test]
async fn a_declared_signal_without_an_open_wait_gets_its_own_task() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    assert_eq!(request(&app, "POST", "/api/worker/register", declaring_worker("declaring")).await.0, StatusCode::CREATED);
    let started = waiting_on_a_timer(&app, "declared-delivery").await;
    signal(&app, "declared-delivery", "note").await;
    let task = poll(&app, "declaring", "workflow").await;
    assert_eq!(task["run_id"], started["run_id"], "the declared signal gets a task: {task}");
    // The SDKs acknowledge a task without new commands as waiting for history.
    let (status, body) = request(&app, "POST", &format!("/api/worker/workflow-tasks/{}/fail", task["task_id"].as_str().unwrap()),
        json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],
            "failure":{"type":"WorkflowTaskWaitingForHistory","message":"Workflow task waiting for scheduled history."}})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let applied = events(&history, "SignalApplied");
    assert_eq!(applied.len(), 1, "{history}");
    assert_eq!(applied[0]["payload"]["signal_name"], "note");
    assert!(applied[0]["payload"].get("sequence").is_none(), "no wait, no sequence: {history}");
    assert_eq!(events(&history, "MessageCursorAdvanced").len(), 1, "{history}");
    assert!(events(&history, "TimerCancelled").is_empty(), "the timer wait stays open");
    assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_signal_records WHERE status='applied' AND workflow_sequence IS NULL AND applied_at IS NOT NULL").await, 1);
    assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_commands WHERE command_type='signal' AND applied_at IS NOT NULL").await, 1);
    assert!(poll(&app, "declaring", "workflow").await.is_null(), "one delivery");
}

#[tokio::test]
async fn an_undeclared_signal_waits_for_a_wait_that_takes_it() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    // A run started before any worker declared a contract has no durable
    // contract, so nothing is delivered to it without a wait either.
    start(&app, "uncontracted-delivery").await;
    assert_eq!(request(&app, "POST", "/api/worker/register", declaring_worker("declaring")).await.0, StatusCode::CREATED);
    start(&app, "undeclared-delivery").await;
    for _ in 0..2 {
        let task = poll(&app, "declaring", "workflow").await;
        assert_eq!(finish_task(&app, &task, json!([{"type":"start_timer","delay_seconds":3600}])).await.0, StatusCode::OK);
    }
    // The durable contract makes `other` unknown, so it is refused outright;
    // a contract-less run admits it, and both runs keep their signals received.
    let (status, refused) = request(&app, "POST", "/api/workflows/undeclared-delivery/signal/other", json!([])).await;
    assert_eq!(status, StatusCode::NOT_FOUND, "{refused}");
    signal(&app, "uncontracted-delivery", "note").await;
    assert!(poll(&app, "declaring", "workflow").await.is_null(), "no task for a signal nothing can take");
    assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_signal_records WHERE status='received'").await, 1);
}

// Server 2.10.0 holds a worker's close when a deliverable signal committed
// after the worker read history. A declared signal is deliverable without a
// wait, so it holds the close too.
#[tokio::test]
async fn a_declared_signal_received_during_the_closing_task_defers_the_completion() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    assert_eq!(request(&app, "POST", "/api/worker/register", declaring_worker("declaring")).await.0, StatusCode::CREATED);
    let started = start(&app, "declared-deferral").await;
    let closing = poll(&app, "declaring", "workflow").await;
    signal(&app, "declared-deferral", "note").await;
    let (status, body) =
        finish_task(&app, &closing, json!([{"type":"complete_workflow","result":envelope(Payload::Long(1))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (_, described) = request(&app, "GET", "/api/workflows/declared-deferral", Value::Null).await;
    assert_eq!(described["status"], "waiting", "{described}");
    let delivery = poll(&app, "declaring", "workflow").await;
    assert_eq!(delivery["run_id"], started["run_id"], "{delivery}");
    let (status, body) =
        finish_task(&app, &delivery, json!([{"type":"complete_workflow","result":envelope(Payload::Long(2))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(events(&history, "SignalApplied").len(), 1, "{history}");
    assert_eq!(events(&history, "WorkflowCompleted").len(), 1, "{history}");
    assert!(events(&history, "SignalRejected").is_empty(), "{history}");
}
