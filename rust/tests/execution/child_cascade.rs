use super::*;

async fn register_cooperative(app: &Router, worker: &str, queue: &str, kind: &str) {
    let registered = request_protocol(app, "POST", "/api/worker/register",
        json!({"worker_id":worker,"task_queue":queue,"runtime":"php","supported_workflow_types":[kind],
            "supported_activity_types":[],"capabilities":["cooperative_cancellation"]}), "1.20").await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
}

async fn claim(app: &Router, worker: &str, queue: &str) -> Value {
    let polled = request_protocol(app, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":worker,"task_queue":queue,"timeout_seconds":2}), "1.20").await;
    assert_eq!(polled.0, StatusCode::OK, "{}", polled.1);
    assert!(polled.1["task"]["task_id"].is_string(), "{}", polled.1);
    polled.1["task"].clone()
}

async fn finish(app: &Router, task: &Value, commands: Value) -> Value {
    let (status, body) = request_protocol(app, "POST", &format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap()),
        json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],"commands":commands}), "1.20").await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body
}

fn events<'a>(history: &'a Value, kind: &str) -> Vec<&'a Value> {
    history.as_array().unwrap().iter().filter(|event| event["event_type"] == kind).collect()
}

// Published PHP's `wait_cancellation_completed` cascade (Workflow 2.9.0
// ChildCancellation): the parent's delivery at the child asks the child to
// cancel under the root request and budget, and waits with its claim
// released. Once the child closes cancelled, the parent's next delivery
// records the resolution and goes through.
#[tokio::test]
async fn a_waiting_child_policy_cancels_the_child_before_the_parent_resumes() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register_cooperative(&app, "cascade-parent", "test", "echo").await;
    register_cooperative(&app, "cascade-child", "child-queue", "child").await;
    let parent = start(&app, "cascade-parent").await;
    let task = claim(&app, "cascade-parent", "test").await;
    let mut child_start = child_command(1);
    child_start["queue"] = json!("child-queue");
    child_start["cancellation_policy"] = json!("wait_cancellation_completed");
    child_start["parent_close_policy"] = json!("request_cancellation");
    finish(&app, &task, json!([child_start])).await;
    let child_task = claim(&app, "cascade-child", "child-queue").await;
    finish(&app, &child_task, json!([{"type":"open_signal_wait","signal_name":"child-finish"}])).await;
    let child = json!({"workflow_id": child_task["workflow_id"], "run_id": child_task["run_id"]});

    let (status, root) = request(&app, "POST", "/api/workflows/cascade-parent/request-cancellation",
        json!({"reason":"cascade λ","cleanup_timeout_seconds":30})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{root}");
    let root = root["cancellation_request"].clone();
    let parent_task = claim(&app, "cascade-parent", "test").await;
    let (status, waiting) = super::cooperative_children::deliver(&app, &parent_task, &root, 1, "child").await;
    assert_eq!(status, StatusCode::OK, "{waiting}");
    assert_eq!((waiting["delivered"].clone(), waiting["reason"].clone()), (json!(false), json!("cancellation_waiting_for_child")));
    assert_eq!((waiting["claim_released"].clone(), waiting["workflow_run_id"].clone()), (json!(true), parent["run_id"].clone()));
    assert!(waiting["protocol_version"].is_string() && waiting["server_capabilities"].is_object(), "{waiting}");

    let parent_history = run_history(&app, &parent["workflow_id"], &parent["run_id"]).await;
    let root_context = events(&parent_history, "CooperativeCancellationRequested")[0]["payload"]["cancellation"].clone();
    let requested = events(&parent_history, "ChildCancellationRequested");
    assert_eq!(requested.len(), 1, "{parent_history}");
    let marker = &requested[0]["payload"];
    assert_eq!((marker["policy"].clone(), marker["request_outcome"].clone(), marker["rejection_reason"].clone()),
        (json!("wait_cancellation_completed"), json!("accepted"), Value::Null), "{marker}");
    assert_eq!((marker["parent_request_id"].clone(), marker["root_request_id"].clone()), (root["request_id"].clone(), root["request_id"].clone()));
    assert_eq!(marker["cleanup_deadline_at"], root_context["cleanup_deadline_at"]);
    assert!(events(&parent_history, "CooperativeCancellationDelivered").is_empty(), "{parent_history}");

    let child_history = run_history(&app, &child["workflow_id"], &child["run_id"]).await;
    let child_request = events(&child_history, "CooperativeCancellationRequested");
    assert_eq!(child_request.len(), 1, "{child_history}");
    assert_eq!(child_request[0]["principal"], Value::Null, "{}", child_request[0]);
    let context = &child_request[0]["payload"]["cancellation"];
    assert_eq!(marker["child_request_id"], context["request_id"]);
    assert_eq!(context["parent_request_id"], root["request_id"]);
    assert_eq!(context["root_request_id"], root["request_id"]);
    for field in ["requested_at", "cleanup_deadline_at", "reason", "requester", "source"] {
        assert_eq!(context[field], root_context[field], "{field}");
    }
    assert_eq!(context["lineage"].as_array().unwrap().len(), 2, "{context}");

    // The child takes the request at its signal wait and closes cancelled.
    let child_cancellation = claim(&app, "cascade-child", "child-queue").await;
    let child_pending = json!({"request_id": context["request_id"]});
    let (status, delivered) = super::cooperative_children::deliver(&app, &child_cancellation, &child_pending, 1, "signal").await;
    assert_eq!((status, delivered["delivered"].clone()), (StatusCode::OK, json!(true)), "{delivered}");
    finish(&app, &child_cancellation, json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await;
    let child_history = run_history(&app, &child["workflow_id"], &child["run_id"]).await;
    assert_eq!(events(&child_history, "WorkflowCancelled").len(), 1, "{child_history}");

    // The parent resumes and its repeated delivery resolves the child.
    let resumed = claim(&app, "cascade-parent", "test").await;
    let (status, delivered) = super::cooperative_children::deliver(&app, &resumed, &root, 1, "child").await;
    assert_eq!((status, delivered["delivered"].clone()), (StatusCode::OK, json!(true)), "{delivered}");
    finish(&app, &resumed, json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await;
    let parent_history = run_history(&app, &parent["workflow_id"], &parent["run_id"]).await;
    let kinds: Vec<_> = parent_history.as_array().unwrap().iter().map(|event| event["event_type"].as_str().unwrap()).collect();
    let order = ["CooperativeCancellationRequested", "ChildCancellationRequested", "ChildRunCancelled",
        "ChildCancellationResolved", "CooperativeCancellationDelivered", "WorkflowCancelled"];
    let positions: Vec<_> = order.iter().map(|kind| kinds.iter().position(|seen| seen == kind).unwrap_or_else(|| panic!("{kind} in {kinds:?}"))).collect();
    assert!(positions.windows(2).all(|pair| pair[0] < pair[1]), "{kinds:?}");
    let resolution = &events(&parent_history, "ChildCancellationResolved")[0]["payload"];
    assert_eq!((resolution["child_status"].clone(), resolution["child_terminal_event_type"].clone()),
        (json!("cancelled"), json!("WorkflowCancelled")), "{resolution}");
    let cancelled = &events(&parent_history, "WorkflowCancelled")[0]["payload"];
    assert_eq!(cancelled["cancellation_cleanup"]["outcome"], "completed", "{cancelled}");
}

// Under `try_cancel` the parent's delivery asks the child to cancel and goes
// through at once, so the parent can close first. Its `request_cancellation`
// parent-close policy then finds the child already holding the same root
// request, and records that (ParentClosePolicyEnforcer).
#[tokio::test]
async fn a_try_cancel_parent_closes_first_and_applies_its_parent_close_policy() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register_cooperative(&app, "try-parent", "test", "echo").await;
    register_cooperative(&app, "try-child", "child-queue", "child").await;
    let parent = start(&app, "try-parent").await;
    let task = claim(&app, "try-parent", "test").await;
    let mut child_start = child_command(1);
    child_start["queue"] = json!("child-queue");
    child_start["cancellation_policy"] = json!("try_cancel");
    child_start["parent_close_policy"] = json!("request_cancellation");
    finish(&app, &task, json!([child_start])).await;
    let child_task = claim(&app, "try-child", "child-queue").await;
    finish(&app, &child_task, json!([{"type":"open_signal_wait","signal_name":"child-finish"}])).await;

    let (status, root) = request(&app, "POST", "/api/workflows/try-parent/request-cancellation",
        json!({"reason":"try","cleanup_timeout_seconds":30})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{root}");
    let root = root["cancellation_request"].clone();
    let parent_task = claim(&app, "try-parent", "test").await;
    let (status, delivered) = super::cooperative_children::deliver(&app, &parent_task, &root, 1, "child").await;
    assert_eq!((status, delivered["delivered"].clone()), (StatusCode::OK, json!(true)), "{delivered}");
    finish(&app, &parent_task, json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await;

    let parent_history = run_history(&app, &parent["workflow_id"], &parent["run_id"]).await;
    assert_eq!(events(&parent_history, "WorkflowCancelled").len(), 1, "{parent_history}");
    let requested = &events(&parent_history, "ChildCancellationRequested")[0]["payload"];
    assert_eq!(requested["policy"], "try_cancel", "{requested}");
    let applied = events(&parent_history, "ParentClosePolicyApplied");
    assert_eq!(applied.len(), 1, "{parent_history}");
    let applied = &applied[0]["payload"];
    assert_eq!((applied["policy"].clone(), applied["child_run_id"].clone()), (json!("request_cancellation"), child_task["run_id"].clone()));
    assert_eq!(applied["request_id"], requested["child_request_id"], "{applied}");
    assert_eq!(applied["reason"], "Parent workflow closed (cancelled); parent-close policy: request_cancellation.");
    let child_history = run_history(&app, &child_task["workflow_id"], &child_task["run_id"]).await;
    assert_eq!(events(&child_history, "CooperativeCancellationRequested").len(), 1, "{child_history}");
}
