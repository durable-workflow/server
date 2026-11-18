use super::*;

// Server #587: a retained run answers queries read-only whatever its status,
// as embedded `WorkflowStub::queryWithArguments` replays any run. Published
// PHP refused failed, cancelled and terminated runs with `run_not_active`.

async fn run_status(app: &Router, workflow: &str) -> (Value, Value) {
    let (status, body) = request(app, "GET", &format!("/api/workflows/{workflow}"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    (body["run_id"].clone(), body["status"].clone())
}

/// Closes `workflow` with `close`: a workflow task command, or a
/// control-plane route.
async fn closed(app: &Router, workflow: &str, close: &str) -> Value {
    let started = start(app, workflow).await;
    match close {
        "complete" | "fail" => {
            let task = poll(app, "answering", "workflow").await;
            assert_eq!(task["workflow_id"], workflow, "{task}");
            let command = if close == "complete" {
                json!({"type":"complete_workflow","result":envelope(Payload::Long(1))})
            } else {
                json!({"type":"fail_workflow","message":"boom"})
            };
            let (status, body) = finish_task(app, &task, json!([command])).await;
            assert_eq!(status, StatusCode::OK, "{body}");
        }
        route => {
            let (status, body) = request(app, "POST", &format!("/api/workflows/{workflow}/{route}"),
                json!({"reason":"close for query"})).await;
            assert_eq!(status, StatusCode::OK, "{body}");
        }
    }
    started
}

async fn answer_query(app: &Router, path: String, run: &Value, status: &str, result: i64) -> Value {
    let waiting = {
        let app = app.clone();
        tokio::spawn(async move { request(&app, "POST", &path, json!({"input":[1]})).await })
    };
    let claim = await_query(app, "answering").await;
    // The claim names the closed run it was admitted for and delivers its
    // complete history, ending in the terminal event.
    assert_eq!(&claim["run_id"], run, "{claim}");
    assert_eq!(claim["run_status"], status, "{claim}");
    let history = claim["history_events"].as_array().expect("query history");
    assert!(history.iter().any(|event| event["event_type"] == "WorkflowStarted"), "{claim}");
    let receipt = request(app, "POST",
        &format!("/api/worker/query-tasks/{}/complete", claim["query_task_id"].as_str().unwrap()),
        json!({"lease_owner":claim["lease_owner"],"query_task_attempt":claim["query_task_attempt"],
            "result_envelope":envelope(Payload::Long(result))})).await;
    assert_eq!(receipt.0, StatusCode::OK, "{}", receipt.1);
    let answer = waiting.await.unwrap();
    assert_eq!(answer.0, StatusCode::OK, "{}", answer.1);
    assert_eq!(&answer.1["run_id"], run);
    assert_eq!(answer.1["result_envelope"], envelope(Payload::Long(result)));
    assert_eq!(answer.1["control_plane"]["operation"], "query");
    claim
}

#[tokio::test]
async fn closed_runs_answer_queries_read_only_on_their_original_run() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    query_worker(&app, "answering").await;
    for (index, (workflow, close, status)) in [
        ("closed-completed", "complete", "completed"),
        ("closed-failed", "fail", "failed"),
        ("closed-cancelled", "cancel", "cancelled"),
        ("closed-terminated", "terminate", "terminated"),
    ]
    .into_iter()
    .enumerate()
    {
        let started = closed(&app, workflow, close).await;
        let run = started["run_id"].clone();
        assert_eq!(run_status(&app, workflow).await, (run.clone(), json!(status)));
        let before = run_history(&app, &json!(workflow), &run).await;
        let last = before.as_array().unwrap().last().unwrap().clone();
        // Instance-targeted and run-targeted queries both bind the closed run.
        let claim = answer_query(&app, format!("/api/workflows/{workflow}/query/state"), &run, status, index as i64).await;
        assert_eq!(claim["history_events"].as_array().unwrap().last().unwrap()["sequence"], last["sequence"], "{claim}");
        answer_query(&app, format!("/api/workflows/{workflow}/runs/{}/query/state", run.as_str().unwrap()),
            &run, status, 10 + index as i64).await;
        // Read-only: the run, its status and its history are unchanged.
        assert_eq!(run_history(&app, &json!(workflow), &run).await, before, "{workflow} history changed");
        assert_eq!(run_status(&app, workflow).await, (run.clone(), json!(status)));
        assert!(poll(&app, "answering", "workflow").await.is_null(), "{workflow}: a query created workflow work");
    }
    // A closed run is still bound to its own route: with no compatible
    // worker the query is refused as for an open run, not run_not_active.
    let (status, body) = request(&app, "POST", "/api/worker/register", json!({"worker_id":"answering",
        "task_queue":"test","runtime":"php","supported_workflow_types":["other"],"supported_activity_types":[],
        "capabilities":["query_tasks"]})).await;
    assert!(status.is_success(), "{body}");
    let refused = request(&app, "POST", "/api/workflows/closed-failed/query/state", json!({"input":[1]})).await;
    assert_eq!(refused.0, StatusCode::CONFLICT, "{}", refused.1);
    assert_eq!(refused.1["reason"], "query_worker_incompatible", "{}", refused.1);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn pruned_or_missing_history_refuses_queries_explicitly() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    query_worker(&app, "answering").await;
    // Retention prunes a closed run's details; the run row stays.
    let pruned = closed(&app, "pruned-query", "terminate").await;
    let (status, report) = request(&app, "POST", "/api/system/retention/pass",
        json!({"run_ids":[pruned["run_id"]]})).await;
    assert_eq!(status, StatusCode::OK, "{report}");
    assert_eq!(report["pruned"], 1, "{report}");
    for path in [
        "/api/workflows/pruned-query/query/state".to_owned(),
        format!("/api/workflows/pruned-query/runs/{}/query/state", pruned["run_id"].as_str().unwrap()),
    ] {
        let refused = request(&app, "POST", &path, json!({"input":[1]})).await;
        assert_eq!(refused.0, StatusCode::CONFLICT, "{}", refused.1);
        assert_eq!(refused.1["reason"], "query_history_unavailable", "{}", refused.1);
        assert_eq!(refused.1["history_state"], "pruned");
        assert_eq!(refused.1["run_status"], "terminated");
        assert_eq!(refused.1["is_terminal"], true);
        assert_eq!(refused.1["run_id"], pruned["run_id"]);
        assert_eq!(refused.1["control_plane"]["reason"], "query_history_unavailable");
        assert!(refused.1["message"].as_str().unwrap().contains("was pruned by retention"), "{}", refused.1);
    }
    // History lost without a prune record is missing, not an empty replay.
    let missing = start(&app, "missing-history").await;
    database.execute(sqlx::AssertSqlSafe(format!(
        "DELETE FROM workflow_history_events WHERE workflow_run_id='{}'", missing["run_id"].as_str().unwrap()))).await;
    let refused = request(&app, "POST", "/api/workflows/missing-history/query/state", json!({"input":[1]})).await;
    assert_eq!(refused.0, StatusCode::CONFLICT, "{}", refused.1);
    assert_eq!(refused.1["reason"], "query_history_unavailable", "{}", refused.1);
    assert_eq!(refused.1["history_state"], "missing");
    assert_eq!(refused.1["is_terminal"], false);
    // Neither refusal admitted a query task.
    assert!(poll(&app, "answering", "query").await.is_null());
    runtime.close().await;
    database.remove().await;
}
