use super::*;

async fn register_build(app: &Router, worker: &str, build: Option<&str>, types: Value) {
    let (status, body) = request(app, "POST", "/api/worker/register", json!({"worker_id":worker,"task_queue":"test",
        "runtime":"php","build_id":build,"supported_workflow_types":types,"supported_activity_types":["a"]})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

async fn start_type(app: &Router, workflow: &str, workflow_type: &str) -> Value {
    let (status, body) = request(app, "POST", "/api/workflows", json!({"workflow_id":workflow,"workflow_type":workflow_type,
        "task_queue":"test","input":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    body
}

async fn pin(app: &Router, workflow: &str) -> Value {
    let (status, described) = request(app, "GET", &format!("/api/workflows/{workflow}"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{described}");
    described["compatibility"].clone()
}

fn schedule_activity() -> Value {
    json!([{"type":"schedule_activity","activity_type":"a","arguments":envelope(Payload::Array(vec![]))}])
}

// Published PHP 2.11.1's `WorkflowStartVersionPin` pins a new run to the one
// build among the type's live workers, else the queue's, and its pollers
// offer a pinned task only to that build's workers. Native routes an
// unpinned activity to any worker, as it does an unpinned workflow task;
// Workflow #908 records where PHP strands it instead.
#[tokio::test]
async fn runs_are_pinned_to_their_start_cohort_s_build_like_php() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register_build(&app, "v1-worker", Some("v1"), json!(["echo", "solo"])).await;
    let first = start_type(&app, "pinned", "echo").await;
    assert_eq!(pin(&app, "pinned").await, "v1");
    let history = run_history(&app, &json!("pinned"), &first["run_id"]).await;
    let started = history.as_array().unwrap().iter().find(|event| event["event_type"] == "WorkflowStarted").unwrap();
    assert_eq!(started["payload"]["compatibility"], "v1", "{started}");

    // With two builds serving the type, a start has no cohort and no pin; a
    // type only one build serves is still pinned to it.
    register_build(&app, "v2-worker", Some(" v2 "), json!(["echo"])).await;
    let open = start_type(&app, "open", "echo").await;
    assert_eq!(pin(&app, "open").await, Value::Null);
    let history = run_history(&app, &json!("open"), &open["run_id"]).await;
    assert!(history[1]["payload"].get("compatibility").is_none(), "{}", history[1]);
    start_type(&app, "solo", "solo").await;
    assert_eq!(pin(&app, "solo").await, "v1");

    // The v2 worker skips both v1 runs and takes the unpinned one.
    let task = poll(&app, "v2-worker", "workflow").await;
    assert_eq!((task["workflow_id"].clone(), task["compatibility"].clone()), (json!("open"), Value::Null), "{task}");
    assert_eq!(poll(&app, "v2-worker", "workflow").await, Value::Null);
    let open_task = task;
    let task = poll(&app, "v1-worker", "workflow").await;
    assert_eq!((task["workflow_id"].clone(), task["compatibility"].clone()), (json!("pinned"), json!("v1")), "{task}");
    let (status, body) = finish_task(&app, &task, schedule_activity()).await;
    assert_eq!(status, StatusCode::OK, "{body}");

    // A pinned run's activity goes only to its build.
    assert_eq!(poll(&app, "v2-worker", "activity").await, Value::Null);
    let activity = poll(&app, "v1-worker", "activity").await;
    assert_eq!(activity["workflow_id"], "pinned", "{activity}");

    // An unpinned run's activity goes to any worker, here the one that
    // scheduled it.
    let (status, body) = finish_task(&app, &open_task, schedule_activity()).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let activity = poll(&app, "v2-worker", "activity").await;
    assert_eq!(activity["workflow_id"], "open", "{activity}");

    // An unversioned worker takes only unpinned work.
    register_build(&app, "unversioned", None, json!(["echo", "solo"])).await;
    assert_eq!(poll(&app, "unversioned", "workflow").await, Value::Null);
    start_type(&app, "mixed", "echo").await;
    assert_eq!(pin(&app, "mixed").await, Value::Null);
    let task = poll(&app, "unversioned", "workflow").await;
    assert_eq!(task["workflow_id"], "mixed", "{task}");
    runtime.close().await;
    database.remove().await;
}

// A child keeps its parent's pin, and a child of an unpinned parent is
// unpinned, whichever worker started it, as on published 2.11.1.
#[tokio::test]
async fn children_keep_their_parent_s_build_pin() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register_build(&app, "v1-worker", Some("v1"), json!(["echo", "child"])).await;
    register_build(&app, "v2-worker", Some("v2"), json!(["echo", "child"])).await;
    start_type(&app, "parent", "echo").await;
    assert_eq!(pin(&app, "parent").await, Value::Null);
    let task = poll(&app, "v2-worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"start_child_workflow","workflow_type":"child",
        "arguments":envelope(Payload::Array(vec![]))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &json!("parent"), &task["run_id"]).await;
    let scheduled = history.as_array().unwrap().iter().find(|event| event["event_type"] == "ChildWorkflowScheduled").unwrap();
    let child = scheduled["payload"]["child_workflow_instance_id"].as_str().unwrap().to_owned();
    assert_eq!(pin(&app, &child).await, Value::Null);
    let task = poll(&app, "v1-worker", "workflow").await;
    assert_eq!(task["workflow_id"], json!(child), "{task}");
    let (status, body) = finish_task(&app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Array(vec![]))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    // A parent only v1 serves is pinned to v1, and so is its child.
    register_build(&app, "v1-worker", Some("v1"), json!(["echo", "child", "solo"])).await;
    start_type(&app, "pinned-parent", "solo").await;
    assert_eq!(pin(&app, "pinned-parent").await, "v1");
    let mut task = poll(&app, "v1-worker", "workflow").await;
    while task["workflow_id"] != "pinned-parent" {
        finish_task(&app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Array(vec![]))}])).await;
        task = poll(&app, "v1-worker", "workflow").await;
    }
    let (status, body) = finish_task(&app, &task, json!([{"type":"start_child_workflow","workflow_type":"child",
        "arguments":envelope(Payload::Array(vec![]))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &json!("pinned-parent"), &task["run_id"]).await;
    let scheduled = history.as_array().unwrap().iter().find(|event| event["event_type"] == "ChildWorkflowScheduled").unwrap();
    let child = scheduled["payload"]["child_workflow_instance_id"].as_str().unwrap().to_owned();
    assert_eq!(pin(&app, &child).await, "v1");
    assert_eq!(poll(&app, "v2-worker", "workflow").await, Value::Null);
    runtime.close().await;
    database.remove().await;
}
