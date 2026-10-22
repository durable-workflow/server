use super::*;

// Workflow 2.9.0 `ParentClosePolicyEnforcer`: whenever a parent run closes
// (completed, failed, cancelled, terminated or timed out), each child still
// open under `request_cancel` or `terminate` is cancelled or terminated
// through `WorkflowStub::attemptCancel` / `attemptTerminate`, and the parent
// records `ParentClosePolicyApplied`. An `abandon` child keeps running.

fn types(history: &Value) -> Vec<String> {
    history
        .as_array()
        .unwrap()
        .iter()
        .map(|event| event["event_type"].as_str().unwrap().to_owned())
        .collect()
}

/// Counts rows with a query built for this test.
async fn count(database: &TestDatabase, sql: String) -> i64 {
    task_retry::rows(database, Box::leak(sql.into_boxed_str())).await
}

fn child_with(policy: &str) -> Value {
    let mut command = child_command(1);
    command["parent_close_policy"] = json!(policy);
    command
}

/// A parent with one open child under `policy`; returns the parent's start
/// response and the child's instance and run.
async fn parent_with_child(app: &Router, parent: &str, policy: &str, close: &str) -> (Value, Value, Value) {
    let started = if close == "timeout" {
        let (status, body) = request(app, "POST", "/api/workflows", json!({"workflow_id":parent,"workflow_type":"echo",
            "task_queue":"test","input":envelope(Payload::Array(vec![])),"run_timeout_seconds":600})).await;
        assert_eq!(status, StatusCode::CREATED, "{body}");
        body
    } else {
        start(app, parent).await
    };
    let task = poll(app, "parent-worker", "workflow").await;
    let (status, body) = finish_task(app, &task, json!([child_with(policy)])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(app, &json!(parent), &started["run_id"]).await;
    let scheduled = history
        .as_array()
        .unwrap()
        .iter()
        .find(|event| event["event_type"] == "ChildWorkflowScheduled")
        .unwrap_or_else(|| panic!("{history}"));
    (
        started,
        scheduled["payload"]["child_workflow_instance_id"].clone(),
        scheduled["payload"]["child_workflow_run_id"].clone(),
    )
}

/// Closes the parent. Native admits a child start only as a task's sole
/// command, and a signal gets no task while the parent waits on its child,
/// so a worker reaches its deadline in the task an accepted update gives
/// it. A PHP parent can also complete or fail while its child runs (PHP
/// starts children alongside other commands); a run taken over from PHP may
/// be there, so the test gives the parent that next task directly.
async fn close_parent(app: &Router, database: &TestDatabase, parent: &str, close: &str) {
    if matches!(close, "cancel" | "terminate") {
        let (status, body) =
            request(app, "POST", &format!("/api/workflows/{parent}/{close}"), json!({"reason":"parent closes"})).await;
        assert_eq!(status, StatusCode::OK, "{body}");
        return;
    }
    if close == "timeout" {
        let (status, body) = request(app, "POST", &format!("/api/workflows/{parent}/update/set"),
            json!({"input":envelope(Payload::Array(vec![Payload::Long(1)])),"wait_for":"accepted"})).await;
        assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    } else {
        database.execute(sqlx::AssertSqlSafe(format!("INSERT INTO workflow_tasks(id,workflow_run_id,task_type,status,payload,queue,available_at,namespace,created_at,updated_at,priority,fairness_key,fairness_weight) SELECT '01ZZZZZZZZZZZZZZZZZZZZZZZZ',id,'workflow','ready','{{}}',queue,created_at,namespace,created_at,created_at,priority,fairness_key,fairness_weight FROM workflow_runs WHERE workflow_instance_id='{parent}'"))).await;
    }
    let task = poll(app, "parent-worker", "workflow").await;
    assert!(!task.is_null(), "the update gives the parent a workflow task");
    if close == "timeout" {
        database
            .execute(sqlx::AssertSqlSafe(format!("UPDATE workflow_runs SET run_deadline_at='2000-01-01 00:00:00' WHERE workflow_instance_id='{parent}'")))
            .await;
    }
    let command = if close == "fail" {
        json!({"type":"fail_workflow","message":"parent failed"})
    } else {
        json!({"type":"complete_workflow","result":envelope(Payload::Long(7))})
    };
    let (status, body) = finish_task(app, &task, json!([command])).await;
    if close == "timeout" {
        assert_eq!(status, StatusCode::CONFLICT, "{body}");
        assert_eq!(body["reason"], "run_timed_out", "{body}");
    } else {
        assert_eq!(status, StatusCode::OK, "{body}");
    }
}

#[tokio::test]
async fn every_parent_close_applies_its_open_childs_parent_close_policy() {
    for (close, closed_reason, policy) in [
        ("complete", "completed", "request_cancel"),
        ("complete", "completed", "terminate"),
        ("complete", "completed", "abandon"),
        ("fail", "failed", "terminate"),
        ("cancel", "cancelled", "terminate"),
        ("terminate", "terminated", "request_cancel"),
        ("timeout", "timed_out", "terminate"),
    ] {
        let label = format!("{close}/{policy}");
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        update_worker(&app, "parent-worker").await;
        register(&app, "child-worker", json!(["child"]), json!([])).await;
        let (parent, child_id, child_run) = parent_with_child(&app, "policy-parent", policy, close).await;
        close_parent(&app, &database, "policy-parent", close).await;

        let parent_history = run_history(&app, &json!("policy-parent"), &parent["run_id"]).await;
        let parent_types = types(&parent_history);
        let child_history = run_history(&app, &child_id, &child_run).await;
        let applied: Vec<_> = parent_history
            .as_array()
            .unwrap()
            .iter()
            .filter(|event| event["event_type"] == "ParentClosePolicyApplied")
            .collect();
        if policy == "abandon" {
            assert!(applied.is_empty(), "{label}: {parent_history}");
            assert_eq!(types(&child_history), ["WorkflowStarted"], "{label}");
            assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_child_calls WHERE status='started'").await, 1, "{label}");
            assert!(!poll(&app, "child-worker", "workflow").await.is_null(), "{label}: the abandoned child keeps its task");
            runtime.close().await;
            database.remove().await;
            continue;
        }
        let (verb, requested, closed, call_status) = if policy == "terminate" {
            ("terminate", "TerminateRequested", "WorkflowTerminated", "terminated")
        } else {
            ("cancel", "CancelRequested", "WorkflowCancelled", "cancelled")
        };
        let reason = format!("Parent workflow closed ({closed_reason}); parent-close policy: {policy}.");

        // The parent records the policy after its own terminal event and
        // receives no child resolution, as it is already closed.
        assert_eq!(applied.len(), 1, "{label}: {parent_history}");
        assert_eq!(parent_types.last().unwrap(), "ParentClosePolicyApplied", "{label}: {parent_history}");
        assert_eq!(
            applied[0]["payload"],
            json!({"child_instance_id": child_id, "child_run_id": child_run, "policy": policy, "reason": reason}),
            "{label}"
        );
        assert!(!parent_types.iter().any(|kind| kind.starts_with("ChildRun") && kind != "ChildRunStarted"), "{label}: {parent_history}");

        // The child is closed like a PHP API cancel or terminate with that reason.
        assert_eq!(types(&child_history), ["WorkflowStarted", requested, closed], "{label}");
        assert_eq!(child_history[1]["payload"]["reason"], reason, "{label}");
        assert_eq!(child_history[1]["payload"]["command_type"], verb, "{label}");
        assert_eq!(child_history[2]["payload"]["closed_reason"], call_status, "{label}");
        assert_eq!(
            child_history[2]["payload"]["message"],
            format!("Workflow {call_status}: {reason}"),
            "{label}"
        );
        let command = child_history[1]["payload"]["workflow_command_id"].as_str().unwrap().to_owned();
        assert_eq!(
            count(&database, format!(
                "SELECT COUNT(*) FROM workflow_commands WHERE id='{command}' AND command_type='{verb}' AND source='php' AND target_scope='instance' AND status='accepted' AND outcome='{call_status}' AND command_sequence=1 AND applied_at IS NOT NULL")).await,
            1,
            "{label}"
        );
        let (status, described) = request(&app, "GET", &format!("/api/workflows/{}", child_id.as_str().unwrap()), Value::Null).await;
        assert_eq!(status, StatusCode::OK, "{described}");
        assert_eq!(described["status"], call_status, "{label}");
        assert_eq!(
            count(&database, format!(
                "SELECT COUNT(*) FROM workflow_child_calls WHERE status='{call_status}' AND closed_reason='{call_status}'")).await,
            1,
            "{label}"
        );
        assert!(poll(&app, "child-worker", "workflow").await.is_null(), "{label}: the child's task is cancelled");
        runtime.close().await;
        database.remove().await;
    }
}

// The child's own policies apply when the parent's policy closes it.
#[tokio::test]
async fn a_policy_close_applies_the_childs_own_policies_in_turn() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "parent-worker").await;
    register(&app, "child-worker", json!(["child"]), json!([])).await;
    let (parent, child_id, child_run) = parent_with_child(&app, "nested-parent", "request_cancel", "terminate").await;
    let child_task = poll(&app, "child-worker", "workflow").await;
    assert_eq!(child_task["run_id"], child_run);
    let (status, body) = finish_task(&app, &child_task, json!([child_with("terminate")])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let child_before = run_history(&app, &child_id, &child_run).await;
    let scheduled = child_before
        .as_array()
        .unwrap()
        .iter()
        .find(|event| event["event_type"] == "ChildWorkflowScheduled")
        .unwrap();
    let (grandchild_id, grandchild_run) = (
        scheduled["payload"]["child_workflow_instance_id"].clone(),
        scheduled["payload"]["child_workflow_run_id"].clone(),
    );
    close_parent(&app, &database, "nested-parent", "terminate").await;

    let parent_history = run_history(&app, &json!("nested-parent"), &parent["run_id"]).await;
    assert_eq!(types(&parent_history).last().unwrap(), "ParentClosePolicyApplied", "{parent_history}");
    let child_history = run_history(&app, &child_id, &child_run).await;
    let child_types = types(&child_history);
    let tail = &child_types[child_types.len() - 3..];
    assert_eq!(tail, ["CancelRequested", "WorkflowCancelled", "ParentClosePolicyApplied"], "{child_history}");
    let applied = child_history.as_array().unwrap().last().unwrap();
    assert_eq!(
        applied["payload"],
        json!({"child_instance_id": grandchild_id, "child_run_id": grandchild_run, "policy": "terminate",
            "reason": "Parent workflow closed (cancelled); parent-close policy: terminate."})
    );
    let grandchild = run_history(&app, &grandchild_id, &grandchild_run).await;
    assert_eq!(types(&grandchild), ["WorkflowStarted", "TerminateRequested", "WorkflowTerminated"]);
    assert_eq!(
        grandchild[1]["payload"]["reason"],
        "Parent workflow closed (cancelled); parent-close policy: terminate."
    );
    assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_child_calls WHERE status='cancelled'").await, 1);
    assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_child_calls WHERE status='terminated'").await, 1);
    assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_runs WHERE status IN ('pending','running','waiting')").await, 0);
    runtime.close().await;
    database.remove().await;
}

// A child native cannot close yet (here: one with an open
// `request_cancellation` grandchild, which needs PHP's new cancellation
// origin) stays open; the parent still closes and records
// `ParentClosePolicyFailed` with the reason, as PHP does for a child it
// cannot close.
#[tokio::test]
async fn a_child_native_cannot_close_records_the_policy_failure() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "parent-worker").await;
    let registered = request_protocol(&app, "POST", "/api/worker/register",
        json!({"worker_id":"child-worker","task_queue":"test","runtime":"php","supported_workflow_types":["child"],
            "supported_activity_types":[],"capabilities":["cooperative_cancellation"]}), "1.20").await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    let (parent, child_id, child_run) = parent_with_child(&app, "blocked-parent", "terminate", "terminate").await;
    let polled = request_protocol(&app, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"child-worker","task_queue":"test","timeout_seconds":2}), "1.20").await;
    let child_task = polled.1["task"].clone();
    assert_eq!(child_task["run_id"], child_run, "{}", polled.1);
    let (status, body) = request_protocol(&app, "POST",
        &format!("/api/worker/workflow-tasks/{}/complete", child_task["task_id"].as_str().unwrap()),
        completion(&child_task, json!([child_with("request_cancellation")])), "1.20").await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let child_before = run_history(&app, &child_id, &child_run).await;
    close_parent(&app, &database, "blocked-parent", "terminate").await;

    let parent_history = run_history(&app, &json!("blocked-parent"), &parent["run_id"]).await;
    let failed = parent_history.as_array().unwrap().last().unwrap();
    assert_eq!(failed["event_type"], "ParentClosePolicyFailed", "{parent_history}");
    assert_eq!(
        failed["payload"],
        json!({"child_instance_id": child_id, "child_run_id": child_run, "policy": "terminate",
            "reason": "Parent workflow closed (terminated); parent-close policy: terminate.",
            "error": "termination_propagation_not_available"})
    );
    assert_eq!(run_history(&app, &child_id, &child_run).await, child_before, "nothing was written to the child");
    assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_commands WHERE source='php'").await, 0);
    let (status, described) = request(&app, "GET", "/api/workflows/blocked-parent", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{described}");
    assert_eq!(described["status"], "terminated");
    runtime.close().await;
    database.remove().await;
}
