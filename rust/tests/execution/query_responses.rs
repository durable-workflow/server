use super::*;

fn quick_queries(runtime: Runtime) -> Runtime {
    runtime.with_query_task_config(durable_workflow_server::runtime::QueryTaskConfig {
        timeout_seconds: 1,
        ..Default::default()
    })
}

async fn query(app: &Router, path: &str, body: Value) -> (StatusCode, Value) {
    request(app, "POST", path, body).await
}

fn assert_refusal(response: &(StatusCode, Value), status: StatusCode, reason: &str, message: &str) {
    assert_eq!(response.0, status, "{}", response.1);
    assert_eq!(response.1["reason"], reason, "{}", response.1);
    assert_eq!(response.1["message"], message, "{}", response.1);
    assert_eq!(response.1["control_plane"]["operation"], "query");
    assert_eq!(response.1["control_plane"]["operation_name_field"], "query_name");
    assert_eq!(response.1["control_plane"]["reason"], reason);
}

// Published PHP refuses a query no worker can take with the first failing
// route check, after waiting up to the query timeout for a worker to
// register when none is available.
#[tokio::test]
async fn unroutable_queries_wait_then_refuse_like_php() {
    let database = TestDatabase::new().await;
    let app = router(quick_queries(database.open().await.unwrap()));
    start(&app, "unrouted").await;
    let started = std::time::Instant::now();
    let refused = query(&app, "/api/workflows/unrouted/query/state", json!({})).await;
    assert!(started.elapsed() >= std::time::Duration::from_millis(900), "{:?}", started.elapsed());
    assert_refusal(&refused, StatusCode::CONFLICT, "query_worker_unavailable",
        "No active worker is registered on task queue [test].");
    assert_eq!(refused.1["target_scope"], "instance");
    assert_eq!(refused.1["query_name"], "state");
    assert!(refused.1["result"].is_null() && refused.1.get("result").is_some());
    register(&app, "plain", json!(["echo"]), json!([])).await;
    assert_refusal(&query(&app, "/api/workflows/unrouted/query/state", json!({})).await, StatusCode::CONFLICT,
        "query_worker_unavailable",
        "Active workers are registered on task queue [test], but none are accepting workflow query tasks.");
    let (status, body) = request(&app, "POST", "/api/worker/register", json!({"worker_id":"other","task_queue":"test",
        "runtime":"php","supported_workflow_types":["other"],"supported_activity_types":[],"capabilities":["query_tasks"]})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    // An incompatible worker refuses at once: only an unavailable route waits.
    let started = std::time::Instant::now();
    assert_refusal(&query(&app, "/api/workflows/unrouted/query/state", json!({})).await, StatusCode::CONFLICT,
        "query_worker_incompatible",
        "Query-capable workers on task queue [test] do not advertise workflow type [echo].");
    assert!(started.elapsed() < std::time::Duration::from_millis(900), "{:?}", started.elapsed());
}

// Admission checks run in PHP's order with PHP's bodies, and a run that
// declares no queries leaves the query name to the worker.
#[tokio::test]
async fn query_admission_follows_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let missing = query(&app, "/api/workflows/missing/query/state", json!({})).await;
    assert_refusal(&missing, StatusCode::NOT_FOUND, "instance_not_found", "Workflow not found.");
    // Started before any worker registers, so the run records no contract.
    let started = start(&app, "contractless").await;
    query_worker(&app, "answering").await;
    let task = poll(&app, "answering", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Long(1))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let historical = query(&app, "/api/workflows/contractless/runs/not-current/query/state", json!({})).await;
    assert_eq!(historical.0, StatusCode::CONFLICT, "{}", historical.1);
    assert_eq!(historical.1["reason"], "historical_run_command_rejected");
    assert_eq!(historical.1["control_plane"]["operation"], "query");
    let invalid = query(&app, "/api/workflows/contractless/query/state", json!({"input":"x"})).await;
    assert_refusal(&invalid, StatusCode::UNPROCESSABLE_ENTITY, "validation_failed", "The input field must be an array.");
    assert_eq!(invalid.1["validation_errors"], json!({"input":["The input field must be an array."]}));
    let waiting = {
        let app = app.clone();
        tokio::spawn(async move { query(&app, "/api/workflows/contractless/query/undeclared", json!({})).await })
    };
    let claim = await_query(&app, "answering").await;
    assert_eq!(claim["run_id"], started["run_id"]);
    assert_eq!(claim["query_name"], "undeclared");
    let result = envelope(Payload::Long(7));
    let receipt = request(&app, "POST",
        &format!("/api/worker/query-tasks/{}/complete", claim["query_task_id"].as_str().unwrap()),
        json!({"lease_owner":claim["lease_owner"],"query_task_attempt":claim["query_task_attempt"],"result_envelope":result})).await;
    assert_eq!(receipt.0, StatusCode::OK, "{}", receipt.1);
    let answer = waiting.await.unwrap();
    assert_eq!(answer.0, StatusCode::OK, "{}", answer.1);
    assert_eq!(answer.1["target_scope"], "instance");
    assert_eq!(answer.1["result_envelope"], result);
    assert_eq!(answer.1["control_plane"]["operation"], "query");
    assert_eq!(answer.1["control_plane"]["result_envelope"], result);
    // Terminal runs other than completed ones refuse queries.
    start(&app, "ended").await;
    let (status, body) = request(&app, "POST", "/api/workflows/ended/terminate", json!({})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let ended = query(&app, "/api/workflows/ended/query/state", json!({})).await;
    let run = ended.1["run_id"].as_str().unwrap().to_owned();
    assert_refusal(&ended, StatusCode::CONFLICT, "run_not_active",
        &format!("Workflow query [state] cannot execute because run [{run}] is terminal with status [terminated]."));
    assert_eq!(ended.1["run_status"], "terminated");
    assert_eq!(ended.1["is_terminal"], true);
}
