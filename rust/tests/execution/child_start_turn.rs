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
    // The parent worker also takes the `set` update and `finish` signal.
    update_worker(app, "worker").await;
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

async fn update_task_count(database: &TestDatabase, run: &Value) -> i64 {
    let run = run.as_str().unwrap();
    count(database, format!("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id='{run}' AND task_type='workflow' AND payload LIKE '%workflow_update_id%'")).await
}

// An update accepted while the parent's task is claimed gets its own task
// at that task's completion. It does not replay the workflow, so it cannot
// stand in for the start turn: the parent gets both.
#[tokio::test]
async fn an_update_task_does_not_replace_the_start_turn() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    workers(&app).await;
    let started = start(&app, "updated-turn").await;
    let first = poll(&app, "worker", "workflow").await;
    let (status, update) = request(&app, "POST", "/api/workflows/updated-turn/update/set", json!({"input":[5]})).await;
    assert!(status.is_success(), "{update}");
    let (status, body) = finish_task(&app, &first, json!([child(Some("started"))])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let mut turns = Vec::new();
    for _ in 0..2 {
        turns.push(poll(&app, "worker", "workflow").await);
    }
    let root: Vec<&Value> = turns.iter().filter(|task| task["workflow_update_id"].is_null()).collect();
    assert_eq!(root.len(), 1, "one root start turn beside the update task: {turns:?}");
    assert_eq!(events(root[0], "ChildRunStarted").len(), 1, "{}", root[0]);
    assert!(turns.iter().any(|task| task["workflow_update_id"].is_string()), "the update task remains: {turns:?}");
    assert!(update_task_count(&database, &started["run_id"]).await >= 1);
    runtime.close().await;
    database.remove().await;
}

// A ready root task, here a declared signal's, replays the parent with the
// committed start in its history, so it carries the turn and none is added.
#[tokio::test]
async fn a_ready_root_task_carries_the_start_turn() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    workers(&app).await;
    let started = start(&app, "signalled-turn").await;
    let first = poll(&app, "worker", "workflow").await;
    let (status, body) = request(&app, "POST", "/api/workflows/signalled-turn/signal/finish", json!({"input":[1]})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    let (status, body) = finish_task(&app, &first, json!([child(Some("started"))])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(parent_tasks(&database, &started["run_id"]).await, 2, "the signal's task carries the turn: {body}");
    let carried = poll(&app, "worker", "workflow").await;
    assert_eq!(carried["run_id"], started["run_id"]);
    assert_eq!(events(&carried, "ChildRunStarted").len(), 1, "{carried}");
    assert!(poll(&app, "worker", "workflow").await.is_null(), "no second root turn");
    runtime.close().await;
    database.remove().await;
}

// A leased task's worker may have read history before the start, so it is
// not taken as the turn.
#[tokio::test]
async fn a_leased_task_does_not_replace_the_start_turn() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    workers(&app).await;
    let started = start(&app, "leased-turn").await;
    let first = poll(&app, "worker", "workflow").await;
    let run = started["run_id"].as_str().unwrap();
    // Another root task of the run, claimed elsewhere before the start.
    database.execute(sqlx::AssertSqlSafe(format!(
        "INSERT INTO workflow_tasks(id,workflow_run_id,namespace,task_type,status,payload,queue,lease_owner,lease_expires_at,attempt_count,available_at,created_at,updated_at) \
         SELECT '01M6FZZZZZZZZZZZZZZZZZZZZZ',workflow_run_id,namespace,'workflow','leased',payload,queue,'elsewhere','2037-01-01 00:00:00',1,available_at,created_at,updated_at \
         FROM workflow_tasks WHERE id='{}'", first["task_id"].as_str().unwrap()))).await;
    let (status, body) = finish_task(&app, &first, json!([child(Some("started"))])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(parent_tasks(&database, &started["run_id"]).await, 3, "the claim, the leased task and a fresh turn: {body}");
    let turn = poll(&app, "worker", "workflow").await;
    assert_eq!(turn["run_id"], json!(run), "a fresh start turn beside the leased task: {body}");
    assert_ne!(turn["task_id"], "01M6FZZZZZZZZZZZZZZZZZZZZZ", "not the leased task");
    assert_eq!(events(&turn, "ChildRunStarted").len(), 1, "{turn}");
    runtime.close().await;
    database.remove().await;
}

// The child finishes before the start turn is claimed: the ready turn
// carries the resolution too, with no second task and no stuck parent.
#[tokio::test]
async fn a_child_finishing_before_the_turn_is_claimed_resolves_in_that_turn() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    workers(&app).await;
    let started = start(&app, "fast-child").await;
    let first = poll(&app, "worker", "workflow").await;
    let (status, body) = finish_task(&app, &first, json!([child(Some("started"))])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let child_task = child_poll(&app).await;
    let (status, body) = finish_task(&app, &child_task, json!([{"type":"complete_workflow","result":envelope(Payload::Long(3))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(parent_tasks(&database, &started["run_id"]).await, 2, "the ready turn is the resume too");
    let turn = poll(&app, "worker", "workflow").await;
    assert_eq!((events(&turn, "ChildRunStarted").len(), events(&turn, "ChildRunCompleted").len()), (1, 1), "{turn}");
    let (status, body) = finish_task(&app, &turn, json!([{"type":"complete_workflow","result":envelope(Payload::Long(3))}])).await;
    assert_eq!((status, body["run_status"].clone()), (StatusCode::OK, json!("completed")), "{body}");
    assert!(poll(&app, "worker", "workflow").await.is_null());
    runtime.close().await;
    database.remove().await;
}
