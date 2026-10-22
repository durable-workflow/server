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

// Published PHP completes a run whatever is still open (Workflow 2.9.0
// `applyWorkflowCompletion`). Its activity, timer and wait stay as they
// are. The activity's late heartbeat and result learn the run closed
// (`run_closed`), and nothing more is recorded.
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
    assert_eq!(events_of(&history, "WorkflowCompleted").len(), 1, "{history}");
    for kind in ["ActivityCancelled", "TimerCancelled", "ActivityCompleted"] {
        assert!(events_of(&history, kind).is_empty(), "{kind}: {history}");
    }

    let attempt = json!({"lease_owner":claimed["lease_owner"],"activity_attempt_id":claimed["activity_attempt_id"]});
    let task_path = format!("/api/worker/activity-tasks/{}", claimed["task_id"].as_str().unwrap());
    let (status, heartbeat) = request(&app, "POST", &format!("{task_path}/heartbeat"), attempt.clone()).await;
    assert_eq!(status, StatusCode::OK, "{heartbeat}");
    assert_eq!((heartbeat["can_continue"].clone(), heartbeat["reason"].clone()), (json!(false), json!("run_closed")), "{heartbeat}");
    let mut result = attempt.clone();
    result["result"] = envelope(Payload::Long(1));
    let (status, late) = request(&app, "POST", &format!("{task_path}/complete"), result).await;
    assert_eq!(status, StatusCode::CONFLICT, "{late}");
    assert_eq!((late["outcome"].clone(), late["recorded"].clone(), late["reason"].clone(), late["next_task_id"].clone()),
        (json!("completed"), json!(false), json!("run_closed"), Value::Null), "{late}");
    let mut failure = attempt.clone();
    failure["failure"] = json!({"type":"RuntimeException","message":"late","non_retryable":false});
    let (status, late) = request(&app, "POST", &format!("{task_path}/fail"), failure).await;
    assert_eq!(status, StatusCode::CONFLICT, "{late}");
    assert_eq!((late["outcome"].clone(), late["reason"].clone()), (json!("failed"), json!("run_closed")), "{late}");
    assert_eq!(run_history(&app, &started["workflow_id"], &started["run_id"]).await, history);
    assert!(poll(&app, "worker", "workflow").await.is_null(), "a closed run gets no further task");

    // An open ordinary activity does not hold the run's details from
    // retention, as in PHP; only an abandoned one would.
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
