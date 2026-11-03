use super::*;

// Server #966: `start_child_workflow.await: "started"` gives the parent one
// fresh turn in the transaction that commits the child start, while the
// child is still open. The default, `result`, keeps today's task cost.

async fn count(database: &TestDatabase, sql: String) -> i64 {
    task_retry::rows(database, Box::leak(sql.into_boxed_str())).await
}

async fn parent_tasks(database: &TestDatabase, run: &Value) -> i64 {
    let run = run.as_str().unwrap();
    count(database, format!("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id='{run}' AND task_type='workflow'")).await
}

async fn child_calls(database: &TestDatabase, run: &Value) -> i64 {
    let run = run.as_str().unwrap();
    count(database, format!("SELECT COUNT(*) FROM workflow_child_calls WHERE parent_workflow_run_id='{run}'")).await
}

fn child(mode: Option<&str>) -> Value {
    let mut command = json!({"type":"start_child_workflow","workflow_type":"child","queue":"children",
        "arguments":envelope(Payload::Array(vec![]))});
    if let Some(mode) = mode {
        command["await"] = json!(mode);
    }
    command
}

/// `worker` takes parents on the default queue and `child-worker` children
/// on their own, so a parent poll never claims a child.
async fn workers(app: &Router) {
    register(app, "worker", json!(["echo"]), json!([])).await;
    let (status, body) = request(app, "POST", "/api/worker/register", json!({"worker_id":"child-worker","task_queue":"children",
        "runtime":"php","supported_workflow_types":["child"],"supported_activity_types":[]})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

async fn child_poll(app: &Router) -> Value {
    let (status, body) = request(app, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"child-worker","task_queue":"children","timeout_seconds":0})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body["task"].clone()
}

fn events<'a>(task: &'a Value, kind: &str) -> Vec<&'a Value> {
    task["history_events"].as_array().unwrap().iter().filter(|event| event["event_type"] == kind).collect()
}

#[tokio::test]
async fn a_started_child_gives_the_parent_a_fresh_turn_while_it_runs() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    workers(&app).await;
    let started = start(&app, "turning").await;
    let first = poll(&app, "worker", "workflow").await;
    let (status, body) = finish_task(&app, &first, json!([child(Some("started"))])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["created_task_ids"].as_array().unwrap().len(), 2, "the child's task and the parent's turn: {body}");
    assert_eq!(body["server_capabilities"]["child_workflow_start_turn"], true, "{body}");

    // The turn is a fresh claim on the parent, with the committed identity in
    // its history; the child has not run yet.
    let turn = poll(&app, "worker", "workflow").await;
    assert_eq!((turn["workflow_id"].clone(), turn["run_id"].clone()), (json!("turning"), started["run_id"].clone()), "{turn}");
    assert_ne!(turn["task_id"], first["task_id"]);
    let scheduled = events(&turn, "ChildWorkflowScheduled");
    let run_started = events(&turn, "ChildRunStarted");
    assert_eq!((scheduled.len(), run_started.len()), (1, 1), "{turn}");
    let call = run_started[0]["payload"]["child_call_id"].clone();
    let initial_run = run_started[0]["payload"]["child_workflow_run_id"].clone();
    assert_eq!(scheduled[0]["payload"]["child_call_id"], call);
    assert!(events(&turn, "ChildRunCompleted").is_empty());

    // A duplicate of the original completion starts no second child and
    // creates no second turn.
    let (status, duplicate) = finish_task(&app, &first, json!([child(Some("started"))])).await;
    assert_eq!((status, duplicate["recorded"].clone(), duplicate["reason"].clone()),
        (StatusCode::OK, json!(false), json!("already_completed")), "{duplicate}");
    assert_eq!((child_calls(&database, &started["run_id"]).await, parent_tasks(&database, &started["run_id"]).await), (1, 2));

    // The turn does other work; the parent then waits on the child.
    let (status, body) = finish_task(&app, &turn, json!([{"type":"record_side_effect","result":envelope(Payload::Long(5))}])).await;
    assert_eq!((status, body["run_status"].clone()), (StatusCode::OK, json!("waiting")), "{body}");
    assert!(poll(&app, "worker", "workflow").await.is_null(), "no further parent turn before the child resolves");

    // The child continues as new and then completes: the call keeps its
    // identity, and the parent's later turn carries the result.
    let child_first = child_poll(&app).await;
    assert_eq!(child_first["run_id"], initial_run, "{child_first}");
    let (status, body) = finish_task(&app, &child_first, json!([{"type":"continue_as_new"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let child_next = child_poll(&app).await;
    assert_ne!(child_next["run_id"], initial_run);
    let (status, body) = finish_task(&app, &child_next, json!([{"type":"complete_workflow","result":envelope(Payload::Long(7))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let resumed = poll(&app, "worker", "workflow").await;
    assert_eq!(resumed["workflow_id"], "turning", "{resumed}");
    let completed = events(&resumed, "ChildRunCompleted");
    assert_eq!(completed.len(), 1, "{resumed}");
    assert_eq!(completed[0]["payload"]["child_call_id"], call);
    assert_eq!(events(&resumed, "ChildRunStarted")[0]["payload"]["child_workflow_run_id"], initial_run, "the initial run id is frozen");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn the_default_start_adds_no_parent_task() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    workers(&app).await;
    for (workflow, mode) in [("unflagged", None), ("result", Some("result"))] {
        let started = start(&app, workflow).await;
        let first = poll(&app, "worker", "workflow").await;
        assert_eq!(first["workflow_id"], workflow);
        let (status, body) = finish_task(&app, &first, json!([child(mode)])).await;
        assert_eq!(status, StatusCode::OK, "{body}");
        assert_eq!(body["created_task_ids"].as_array().unwrap().len(), 1, "{workflow}: only the child's task: {body}");
        assert_eq!(parent_tasks(&database, &started["run_id"]).await, 1, "{workflow}");
        assert!(poll(&app, "worker", "workflow").await.is_null(), "{workflow}: no parent turn");
    }
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn one_turn_for_several_started_children_and_none_when_the_parent_closes() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    workers(&app).await;

    let several = start(&app, "several").await;
    let first = poll(&app, "worker", "workflow").await;
    let (status, body) = finish_task(&app, &first, json!([child(Some("started")), child(Some("started"))])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["created_task_ids"].as_array().unwrap().len(), 3, "two children and one turn: {body}");
    assert_eq!((child_calls(&database, &several["run_id"]).await, parent_tasks(&database, &several["run_id"]).await), (2, 2));
    let turn = poll(&app, "worker", "workflow").await;
    assert_eq!(events(&turn, "ChildRunStarted").len(), 2, "{turn}");
    assert_eq!(turn["workflow_id"], "several");
    let (status, _) = finish_task(&app, &turn, json!([{"type":"complete_workflow","result":envelope(Payload::Long(1))}])).await;
    assert_eq!(status, StatusCode::OK);

    let closing = start(&app, "closing").await;
    let first = poll(&app, "worker", "workflow").await;
    assert_eq!(first["workflow_id"], "closing");
    let (status, body) = finish_task(&app, &first,
        json!([child(Some("started")), {"type":"complete_workflow","result":envelope(Payload::Long(1))}])).await;
    assert_eq!((status, body["run_status"].clone()), (StatusCode::OK, json!("completed")), "{body}");
    assert_eq!(parent_tasks(&database, &closing["run_id"]).await, 1, "no turn for a closed parent");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn an_unknown_await_is_refused_before_any_child_and_the_capability_is_advertised() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    workers(&app).await;
    let started = start(&app, "refused").await;
    let first = poll(&app, "worker", "workflow").await;
    for command in [child(Some("eventually")), json!({"type":"start_timer","delay_seconds":5,"await":"started"})] {
        let (status, body) = finish_task(&app, &first, json!([command])).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
        assert!(body.to_string().contains("commands.0.await"), "{body}");
    }
    assert_eq!(child_calls(&database, &started["run_id"]).await, 0);
    let (status, info) = request(&app, "GET", "/api/cluster/info", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{info}");
    assert_eq!(info["capabilities"]["child_workflow_start_turn"], true, "{info}");
    runtime.close().await;
    database.remove().await;
}
