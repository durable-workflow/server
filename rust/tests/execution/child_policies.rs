use super::*;

async fn cooperative_parent(app: &Router) -> Value {
    let registered = request_protocol(app, "POST", "/api/worker/register",
        json!({"worker_id":"policy-parent","task_queue":"test","runtime":"php","supported_workflow_types":["echo"],
            "supported_activity_types":[],"capabilities":["cooperative_cancellation"]}), "1.20").await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    let polled = request_protocol(app, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"policy-parent","task_queue":"test","timeout_seconds":2}), "1.20").await;
    assert_eq!(polled.0, StatusCode::OK, "{}", polled.1);
    polled.1["task"].clone()
}

async fn finish_cooperative(app: &Router, task: &Value, commands: Value) -> (StatusCode, Value) {
    request_protocol(app, "POST", &format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap()),
        json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],"commands":commands}), "1.20").await
}

fn with(policies: Value) -> Value {
    let mut command = child_command(1);
    for (field, value) in policies.as_object().unwrap() {
        command[field] = value.clone();
    }
    command
}

// Published PHP validates a child's policies (the request rules, then the
// command normalizer) and records them on the child call, its link and the
// parent's child events, where SDK replay compares them with its command.
#[tokio::test]
async fn child_policies_are_validated_and_recorded_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let run = start(&app, "policy-parent").await;
    let task = cooperative_parent(&app).await;
    for (field, value, message) in [
        ("cancellation_policy", json!("never"), "The selected commands.0.cancellation_policy is invalid."),
        ("cancellation_policy", json!(1), "The commands.0.cancellation_policy field must be a string."),
        ("parent_close_policy", json!("orphan"),
            "The parent_close_policy must be one of: abandon, request_cancel, request_cancellation, terminate."),
    ] {
        let (status, refused) = finish_cooperative(&app, &task, json!([with(json!({field: value}))])).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{refused}");
        assert_eq!(refused["reason"], "validation_failed", "{refused}");
        assert_eq!(refused["errors"][format!("commands.0.{field}")], json!([message]), "{refused}");
    }

    // Laravel trims the request, so padded values are accepted.
    let command = with(json!({"parent_close_policy":" request_cancellation ","cancellation_policy":"wait_cancellation_completed"}));
    let (status, applied) = finish_cooperative(&app, &task, json!([command])).await;
    assert_eq!(status, StatusCode::OK, "{applied}");
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    for kind in ["ChildWorkflowScheduled", "ChildRunStarted"] {
        let event = history.as_array().unwrap().iter().find(|event| event["event_type"] == kind).unwrap();
        assert_eq!(event["payload"]["parent_close_policy"], "request_cancellation", "{event}");
        assert_eq!(event["payload"]["cancellation_policy"], "wait_cancellation_completed", "{event}");
    }
    let rows = |sql: &'static str| task_retry::rows(&database, sql);
    assert_eq!(rows("SELECT COUNT(*) FROM workflow_child_calls WHERE parent_close_policy='request_cancellation' AND cancellation_propagation").await, 1);
    assert_eq!(rows("SELECT COUNT(*) FROM workflow_links WHERE link_type='child_workflow' AND parent_close_policy='request_cancellation'").await, 1);

    // The cancellation cascade is not native yet: a parent whose child would
    // receive the parent's cancellation keeps the explicit refusal.
    let (status, refused) = request(&app, "POST", "/api/workflows/policy-parent/request-cancellation",
        json!({"reason":"not yet","cleanup_timeout_seconds":30})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{refused}");
    assert_eq!(refused["reason"], "cooperative_cancellation_propagation_not_available");
}

// `childCancellationPolicyGuard`: a request-cancellation parent-close policy
// or a propagating cancellation policy needs a cooperative claim and a
// protocol 1.20 request.
#[tokio::test]
async fn cooperative_child_policies_need_a_cooperative_claim_on_protocol_1_20() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "plain-parent", json!(["echo"]), json!([])).await;
    start(&app, "plain-parent").await;
    let task = poll(&app, "plain-parent", "workflow").await;
    for policies in [json!({"parent_close_policy":"request_cancellation"}), json!({"cancellation_policy":"try_cancel"})] {
        let (status, refused) = finish_task(&app, &task, json!([with(policies)])).await;
        assert_eq!(status, StatusCode::CONFLICT, "{refused}");
        assert_eq!(refused["reason"], "child_cancellation_policy_not_supported", "{refused}");
        assert_eq!(refused["unavailable"], json!(["request_protocol", "worker_claim_capability"]), "{refused}");
        assert_eq!((refused["requested_version"].clone(), refused["minimum_protocol_version"].clone()), (json!("1.19"), json!("1.20")));
        assert_eq!(refused["recorded"], false);
    }
    // Other policies need nothing more.
    let (status, applied) = finish_task(&app, &task, json!([with(json!({"parent_close_policy":"terminate"}))])).await;
    assert_eq!(status, StatusCode::OK, "{applied}");
}
