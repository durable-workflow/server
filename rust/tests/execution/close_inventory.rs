use super::*;

// Published PHP closes a run whatever it holds open: `attemptTerminalCommand`
// loads and cancels every open activity and timer, and a parent-close
// policy closes its child the same way. A run may hold up to 2,000 pending
// activities and 2,000 pending timers by default (`StructuralLimits`), so a
// close must not stop at 1,000 of either.

/// Eleven batches of 99 (with the wait, a full 100-command batch).
const ROUNDS: usize = 11;
const PER_ROUND: usize = 99;
const OPEN: i64 = (ROUNDS * PER_ROUND) as i64;

#[derive(Clone, Copy)]
enum Open {
    Activities,
    Timers,
}

/// Renews a worker's registration, as a live SDK worker's heartbeat does;
/// building the inventory outlasts the registration's freshness window.
async fn renew(app: &Router, worker: &str, workflows: Value) {
    let (status, body) = request_protocol(app, "POST", "/api/worker/register", json!({"worker_id":worker,"task_queue":"test",
        "runtime":"php","supported_workflow_types":workflows,"supported_activity_types":["activity-echo"],
        "capabilities":["cooperative_cancellation"]}), "1.20").await;
    assert!(status.is_success(), "{body}");
}

/// Every history event of a run, read in pages of 1,000.
async fn full_history(app: &Router, workflow: &str, run: &Value) -> Vec<Value> {
    let mut events = Vec::new();
    let mut token: Option<String> = None;
    loop {
        let path = format!("/api/workflows/{workflow}/runs/{}/history?page_size=1000{}", run.as_str().unwrap(),
            token.as_deref().map(|token| format!("&next_page_token={}", token.replace('=', "%3D"))).unwrap_or_default());
        let (status, page) = request(app, "GET", &path, Value::Null).await;
        assert_eq!(status, StatusCode::OK, "{page}");
        events.extend(page["events"].as_array().unwrap().iter().cloned());
        match page["next_page_token"].as_str() {
            Some(next) if !next.is_empty() => token = Some(next.to_owned()),
            _ => return events,
        }
    }
}

/// Gives the run of `workflow` over 1,000 open activities or timers: each
/// workflow task opens a batch and waits for a signal, which gives it the
/// next task.
async fn fill(app: &Router, worker: &str, types: Value, workflow: &str, run: &Value, open: Open) {
    for round in 0..ROUNDS {
        renew(app, worker, types.clone()).await;
        let task = poll(app, worker, "workflow").await;
        assert_eq!(task["run_id"], *run, "round {round}: {task}");
        let mut commands: Vec<Value> = (0..PER_ROUND)
            .map(|_| match open {
                Open::Activities => json!({"type":"schedule_activity","activity_type":"activity-echo","arguments":envelope(Payload::Array(vec![]))}),
                Open::Timers => json!({"type":"start_timer","delay_seconds":3600}),
            })
            .collect();
        commands.push(json!({"type":"open_signal_wait","signal_name":"more"}));
        let (status, body) = finish_task(app, &task, json!(commands)).await;
        assert_eq!(status, StatusCode::OK, "round {round}: {body}");
        if round + 1 < ROUNDS {
            let (status, body) = request(app, "POST", &format!("/api/workflows/{workflow}/signal/more"),
                json!({"input":envelope(Payload::Array(vec![]))})).await;
            assert_eq!(status, StatusCode::ACCEPTED, "round {round}: {body}");
        }
    }
}

async fn assert_all_cancelled(database: &TestDatabase, run: &Value, open: Open) {
    let run = run.as_str().unwrap();
    let rows = |sql: String| task_retry::rows(database, Box::leak(sql.into_boxed_str()));
    let (event, table, pending) = match open {
        Open::Activities => ("ActivityCancelled", "activity_executions", "status IN ('pending','running')"),
        Open::Timers => ("TimerCancelled", "workflow_run_timers", "status='pending'"),
    };
    assert_eq!(rows(format!("SELECT COUNT(*) FROM workflow_history_events WHERE workflow_run_id='{run}' AND event_type='{event}'")).await, OPEN);
    assert_eq!(rows(format!("SELECT COUNT(*) FROM {table} WHERE workflow_run_id='{run}' AND {pending}")).await, 0);
}

#[tokio::test]
async fn http_terminate_closes_a_run_with_over_a_thousand_open_activities() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let started = start(&app, "crowded").await;
    fill(&app, "worker", json!(["echo"]), "crowded", &started["run_id"], Open::Activities).await;
    let (status, body) = request(&app, "POST", "/api/workflows/crowded/terminate", json!({"reason":"close it all"})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (_, described) = request(&app, "GET", "/api/workflows/crowded", Value::Null).await;
    assert_eq!(described["status"], "terminated", "{described}");
    assert_all_cancelled(&database, &started["run_id"], Open::Activities).await;
    assert!(poll(&app, "worker", "activity").await.is_null(), "no activity task stays ready");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_parent_close_policy_closes_a_child_with_over_a_thousand_open_timers() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    renew(&app, "parent-worker", json!(["echo"])).await;
    let started = start(&app, "crowded-parent").await;
    let task = poll(&app, "parent-worker", "workflow").await;
    let mut child = child_command(1);
    child["parent_close_policy"] = json!("terminate");
    let (status, body) = finish_task(&app, &task, json!([child])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &json!("crowded-parent"), &started["run_id"]).await;
    let scheduled = history.as_array().unwrap().iter().find(|event| event["event_type"] == "ChildWorkflowScheduled").unwrap().clone();
    let (child_id, child_run) = (scheduled["payload"]["child_workflow_instance_id"].clone(), scheduled["payload"]["child_workflow_run_id"].clone());
    fill(&app, "child-worker", json!(["child"]), child_id.as_str().unwrap(), &child_run, Open::Timers).await;
    let (status, body) = request(&app, "POST", "/api/workflows/crowded-parent/terminate", json!({"reason":"close it all"})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &json!("crowded-parent"), &started["run_id"]).await;
    let last = history.as_array().unwrap().last().unwrap();
    assert_eq!(last["event_type"], "ParentClosePolicyApplied", "{last}");
    let (_, described) = request(&app, "GET", &format!("/api/workflows/{}", child_id.as_str().unwrap()), Value::Null).await;
    assert_eq!(described["status"], "terminated", "{described}");
    assert_all_cancelled(&database, &child_run, Open::Timers).await;
    runtime.close().await;
    database.remove().await;
}

// A cooperative cancellation's cancelled close takes every open timer with
// it (`finish_root_cancellation`): the worker parked in a signal wait takes
// the delivery there and completes.
#[tokio::test]
async fn a_cooperative_cancellation_completes_over_a_thousand_open_timers() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let started = start(&app, "crowded-cooperative").await;
    fill(&app, "worker", json!(["echo"]), "crowded-cooperative", &started["run_id"], Open::Timers).await;
    let (status, requested) = request(&app, "POST", "/api/workflows/crowded-cooperative/request-cancellation",
        json!({"reason":"close it all","cleanup_timeout_seconds":60})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{requested}");
    let pending = requested["cancellation_request"].clone();
    renew(&app, "worker", json!(["echo"])).await;
    let (status, polled) = request_protocol(&app, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"worker","task_queue":"test","timeout_seconds":0}), "1.20").await;
    assert_eq!(status, StatusCode::OK, "{polled}");
    let root = polled["task"].clone();
    assert_eq!(root["run_id"], started["run_id"], "{polled}");
    let history = full_history(&app, "crowded-cooperative", &started["run_id"]).await;
    let wait = history.iter().rev().find(|event| event["event_type"] == "SignalWaitOpened").expect("an open signal wait");
    let sequence = wait["payload"]["sequence"].as_i64().unwrap();
    let delivered = super::cooperative_children::deliver(&app, &root, &pending, sequence, "signal").await;
    assert_eq!((delivered.0, delivered.1["delivered"].clone()), (StatusCode::OK, json!(true)), "{}", delivered.1);
    let (status, body) = finish_task(&app, &root, json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (_, described) = request(&app, "GET", "/api/workflows/crowded-cooperative", Value::Null).await;
    assert_eq!(described["status"], "cancelled", "{described}");
    assert_all_cancelled(&database, &started["run_id"], Open::Timers).await;
    runtime.close().await;
    database.remove().await;
}
