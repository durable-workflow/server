use super::*;

// A child's resolution while its parent has an open workflow task
// (`WorkflowExecutor::dispatchParentResumeTasks`, published Server 2.11.7).

async fn count(database: &TestDatabase, sql: String) -> i64 {
    task_retry::rows(database, Box::leak(sql.into_boxed_str())).await
}

async fn open_parent_tasks(database: &TestDatabase, run: &Value) -> (i64, i64) {
    let run = run.as_str().unwrap();
    let ready = count(database, format!("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id='{run}' AND task_type='workflow' AND status='ready'")).await;
    let leased = count(database, format!("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id='{run}' AND task_type='workflow' AND status='leased'")).await;
    (ready, leased)
}

async fn task_for(app: &Router, workflow: &Value) -> Value {
    for _ in 0..20 {
        let task = poll(app, "worker", "workflow").await;
        if task["workflow_id"] == *workflow {
            return task;
        }
    }
    panic!("no workflow task for {workflow}");
}

/// Registers `worker` on the parent's queue and `child-worker` on the
/// children's own queue, so claiming a parent task never claims a child's.
async fn workers(app: &Router) {
    register(app, "worker", json!(["echo"]), json!(["a"])).await;
    let (status, body) = request(app, "POST", "/api/worker/register", json!({"worker_id":"child-worker","task_queue":"children",
        "runtime":"php","supported_workflow_types":["child"],"supported_activity_types":[]})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

/// Starts a parent whose first task starts a child and schedules an
/// activity, completes the activity so the parent has a ready resume task,
/// and returns the start response and the child's workflow id.
async fn parent_with_ready_task(app: &Router, workflow: &str) -> (Value, Value) {
    let started = start(app, workflow).await;
    let task = task_for(app, &started["workflow_id"]).await;
    let mut child = child_command(1);
    child["queue"] = json!("children");
    let (status, body) = finish_task(app, &task, json!([child,
        {"type":"schedule_activity","activity_type":"a","arguments":envelope(Payload::Array(vec![]))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let activity = poll(app, "worker", "activity").await;
    let (status, body) = request(app, "POST", &format!("/api/worker/activity-tasks/{}/complete", activity["task_id"].as_str().unwrap()),
        json!({"lease_owner":activity["lease_owner"],"activity_attempt_id":activity["activity_attempt_id"],"result":envelope(Payload::Null)})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(app, &started["workflow_id"], &started["run_id"]).await;
    let child = history.as_array().unwrap().iter()
        .find(|event| event["event_type"] == "ChildRunStarted")
        .map(|event| event["payload"]["child_workflow_instance_id"].clone())
        .expect("the child started");
    (started, child)
}

async fn complete_child(app: &Router, child: &Value) {
    let (status, body) = request(app, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"child-worker","task_queue":"children","timeout_seconds":0})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let task = body["task"].clone();
    assert_eq!(task["workflow_id"], *child, "{task}");
    let (status, body) = finish_task(app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Long(2))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
}

// With a ready task, the resolution is recorded and that task is the
// parent's resume, as published PHP's `hasOpenWorkflowTask` check leaves it.
// Its payload is unchanged, so every SDK replays it as an ordinary task.
#[tokio::test]
async fn a_child_resolution_reuses_the_parents_ready_task_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    workers(&app).await;
    let (started, child) = parent_with_ready_task(&app, "ready-parent").await;
    assert_eq!(open_parent_tasks(&database, &started["run_id"]).await, (1, 0));

    complete_child(&app, &child).await;
    assert_eq!(open_parent_tasks(&database, &started["run_id"]).await, (1, 0), "no second resume task");
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert!(history.as_array().unwrap().iter().any(|event| event["event_type"] == "ChildRunCompleted"), "{history}");

    // The ready task replays the parent with the child's result in history.
    let resumed = task_for(&app, &started["workflow_id"]).await;
    assert!(resumed["workflow_update_id"].is_null(), "{resumed}");
    let replayed: Vec<&str> = resumed["history_events"].as_array().unwrap().iter()
        .map(|event| event["event_type"].as_str().unwrap()).collect();
    assert!(replayed.contains(&"ChildRunCompleted"), "{replayed:?}");
    let (status, body) = finish_task(&app, &resumed, json!([{"type":"complete_workflow","result":envelope(Payload::Long(3))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["run_status"], "completed", "{body}");
    assert_eq!(open_parent_tasks(&database, &started["run_id"]).await, (0, 0));
}

// With only a leased task, its worker may have read history before the
// resolution. Native gives the resolution its own resume task, so a lease
// that completes without the child's result still leaves the parent a task.
// Published PHP creates none and waits for repair (decision recorded in
// docs/rust-server-port.md).
#[tokio::test]
async fn a_child_resolution_during_a_lease_keeps_its_own_resume_task() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    workers(&app).await;
    let (started, child) = parent_with_ready_task(&app, "leased-parent").await;
    let leased = task_for(&app, &started["workflow_id"]).await;
    assert_eq!(open_parent_tasks(&database, &started["run_id"]).await, (0, 1));

    complete_child(&app, &child).await;
    assert_eq!(open_parent_tasks(&database, &started["run_id"]).await, (1, 1), "the resolution's own resume task");

    // The lease completes from history read before the child's result: it
    // opens a timer and does not consume the resolution.
    let (status, body) = finish_task(&app, &leased, json!([{"type":"start_timer","delay_seconds":3600}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["run_status"], "waiting", "{body}");
    assert_eq!(open_parent_tasks(&database, &started["run_id"]).await, (1, 0), "the parent still has its resume");
    let resumed = task_for(&app, &started["workflow_id"]).await;
    let replayed: Vec<&str> = resumed["history_events"].as_array().unwrap().iter()
        .map(|event| event["event_type"].as_str().unwrap()).collect();
    assert!(replayed.contains(&"ChildRunCompleted"), "{replayed:?}");
}
