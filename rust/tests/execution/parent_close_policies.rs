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

/// A worker that takes cooperative cancellation on protocol 1.20, which a
/// `request_cancellation` child start needs.
async fn cooperative_worker(app: &Router, worker: &str) {
    let registered = request_protocol(app, "POST", "/api/worker/register",
        json!({"worker_id":worker,"task_queue":"test","runtime":"php","supported_workflow_types":["echo","child"],
            "supported_activity_types":["activity-echo"],"capabilities":["cooperative_cancellation"]}), "1.20").await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
}

async fn cooperative_poll(app: &Router, worker: &str) -> Value {
    let (status, body) = request_protocol(app, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":worker,"task_queue":"test","timeout_seconds":0}), "1.20").await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body["task"].clone()
}

async fn cooperative_finish(app: &Router, task: &Value, commands: Value) -> (StatusCode, Value) {
    request_protocol(app, "POST", &format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap()),
        completion(task, commands), "1.20").await
}

fn instant(value: &Value) -> chrono::DateTime<chrono::Utc> {
    chrono::DateTime::parse_from_rfc3339(value.as_str().unwrap_or_else(|| panic!("an instant: {value}")))
        .unwrap()
        .with_timezone(&chrono::Utc)
}

/// Checks a run's `ParentCloseCancellationRequested` origin after its
/// terminal event and each child's descendant request under it, as
/// published Server 2.11.5 records them (parent-close-cancellation fixture).
/// Returns the origin's context.
async fn assert_parent_close_origin(app: &Router, database: &TestDatabase, instance: &str, run: &Value,
    terminal: &str, closed_reason: &str, children: &[(Value, Value)]) -> Value {
    let label = format!("{instance}/{terminal}");
    let history = run_history(app, &json!(instance), run).await;
    let kinds = types(&history);
    let at = kinds.iter().position(|kind| kind == terminal).unwrap_or_else(|| panic!("{label}: {history}"));
    let mut tail = vec!["ParentCloseCancellationRequested".to_owned()];
    tail.extend(children.iter().map(|_| "ParentClosePolicyApplied".to_owned()));
    assert_eq!(kinds[at + 1..], tail[..], "{label}: {history}");
    let events = history.as_array().unwrap();
    let origin = &events[at + 1]["payload"];
    assert_eq!(
        count(database, format!("SELECT COUNT(*) FROM workflow_history_events WHERE id='{}' AND workflow_run_id='{}' AND event_type='{terminal}'",
            origin["parent_terminal_history_event_id"].as_str().unwrap(), run.as_str().unwrap())).await,
        1,
        "{label}: the origin names the terminal event"
    );
    assert_eq!(origin["parent_terminal_event_type"], terminal, "{label}");
    let context = origin["cancellation"].clone();
    let reason = format!("Parent workflow closed ({closed_reason}); parent-close policy: request_cancellation.");
    let request = context["request_id"].clone();
    assert!(request.is_string(), "{label}: {context}");
    assert_eq!(context, json!({"schema": "durable-workflow.cancellation-context/v1", "request_id": request,
        "root_request_id": request, "root_workflow_instance_id": instance, "root_workflow_run_id": run,
        "parent_request_id": null, "reason": reason,
        "requester": {"type": "workflow", "id": instance, "label": "Parent-close policy"},
        "source": "parent_close_policy", "requested_at": context["requested_at"],
        "cleanup_deadline_at": context["cleanup_deadline_at"],
        "lineage": [{"request_id": request, "workflow_instance_id": instance, "workflow_run_id": run}]}), "{label}");
    // Requested at the terminal event's time, with a 600-second budget.
    assert_eq!(context["requested_at"], events[at]["timestamp"], "{label}");
    assert_eq!(instant(&context["cleanup_deadline_at"]) - instant(&context["requested_at"]), chrono::Duration::seconds(600), "{label}");
    for (index, (child_instance, child_run)) in children.iter().enumerate() {
        let applied = &events[at + 2 + index]["payload"];
        let child_history = run_history(app, child_instance, child_run).await;
        assert_eq!(types(&child_history), ["WorkflowStarted", "CooperativeCancellationRequested"], "{label}");
        let requested = &child_history[1]["payload"];
        let command = requested["workflow_command_id"].clone();
        let mut lineage = context["lineage"].as_array().unwrap().clone();
        lineage.push(json!({"request_id": command, "workflow_instance_id": child_instance, "workflow_run_id": child_run}));
        let descendant = json!({"schema": "durable-workflow.cancellation-context/v1", "request_id": command,
            "root_request_id": request, "root_workflow_instance_id": instance, "root_workflow_run_id": run,
            "parent_request_id": request, "reason": reason, "requester": context["requester"],
            "source": "parent_close_policy", "requested_at": context["requested_at"],
            "cleanup_deadline_at": context["cleanup_deadline_at"], "lineage": lineage});
        assert_eq!(requested["cancellation"], descendant, "{label}");
        assert_eq!(requested["cleanup_deadline_at"], context["cleanup_deadline_at"], "{label}");
        assert_eq!(requested["reason"], reason, "{label}");
        // `CommandContext::workflow`: the parent asks, naming the child's link.
        let snapshot = &requested["command"];
        assert_eq!((snapshot["source"].clone(), snapshot["caller_label"].clone(), snapshot["target_scope"].clone()),
            (json!("workflow"), json!("Workflow"), json!("instance")), "{label}: {snapshot}");
        assert_eq!(snapshot["context"]["workflow"]["parent_instance_id"], instance, "{label}: {snapshot}");
        assert_eq!(snapshot["context"]["workflow"]["parent_run_id"], *run, "{label}: {snapshot}");
        assert_eq!(snapshot["context"]["workflow"]["sequence"], index as i64 + 1, "{label}: {snapshot}");
        assert_eq!(*applied, json!({"child_instance_id": child_instance, "child_run_id": child_run,
            "policy": "request_cancellation", "reason": reason, "request_id": command, "cancellation": descendant}), "{label}");
        assert_eq!(
            count(database, format!("SELECT COUNT(*) FROM workflow_runs WHERE id='{}' AND status='pending' AND cancellation_request_command_id='{}'",
                child_run.as_str().unwrap(), command.as_str().unwrap())).await,
            1,
            "{label}"
        );
    }
    context
}

/// The children a run's history scheduled, in order.
fn scheduled_children(history: &Value) -> Vec<(Value, Value)> {
    history
        .as_array()
        .unwrap()
        .iter()
        .filter(|event| event["event_type"] == "ChildWorkflowScheduled")
        .map(|event| (event["payload"]["child_workflow_instance_id"].clone(), event["payload"]["child_workflow_run_id"].clone()))
        .collect()
}

// Published PHP (`ParentCloseCancellation::ensureOrigin`): a parent without
// a cooperative request of its own closes whatever `request_cancellation`
// children are open. It records one policy-owned root after its terminal
// event, and each child takes a descendant request under it, which its next
// workflow task carries. Every close applies it: complete and fail in the
// batch that starts the child, terminate (here with two children) and
// cancel over HTTP, and a run deadline.
#[tokio::test]
async fn a_closed_parent_gives_its_request_cancellation_children_its_own_root() {
    for (close, terminal, closed_reason, count_children) in [
        ("complete", "WorkflowCompleted", "completed", 1),
        ("fail", "WorkflowFailed", "failed", 1),
        ("terminate", "WorkflowTerminated", "terminated", 2),
        ("cancel", "WorkflowCancelled", "cancelled", 1),
        ("timeout", "WorkflowTimedOut", "timed_out", 1),
    ] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        cooperative_worker(&app, "worker").await;
        let (status, started) = request(&app, "POST", "/api/workflows", json!({"workflow_id":"origin-parent","workflow_type":"echo",
            "task_queue":"test","input":envelope(Payload::Array(vec![])),"run_timeout_seconds":600})).await;
        assert_eq!(status, StatusCode::CREATED, "{started}");
        let task = cooperative_poll(&app, "worker").await;
        let mut commands: Vec<Value> = (0..count_children).map(|_| child_with("request_cancellation")).collect();
        match close {
            "complete" => commands.push(json!({"type":"complete_workflow","result":envelope(Payload::Long(7))})),
            "fail" => commands.push(json!({"type":"fail_workflow","message":"parent failed"})),
            "timeout" => commands.push(json!({"type":"schedule_activity","activity_type":"activity-echo","arguments":envelope(Payload::Array(vec![]))})),
            _ => {}
        }
        let (status, body) = cooperative_finish(&app, &task, json!(commands)).await;
        assert_eq!(status, StatusCode::OK, "{close}: {body}");
        match close {
            "terminate" | "cancel" => {
                let (status, body) = request(&app, "POST", &format!("/api/workflows/origin-parent/{close}"),
                    json!({"reason":"parent closes"})).await;
                assert_eq!(status, StatusCode::OK, "{close}: {body}");
            }
            "timeout" => {
                let claimed = poll(&app, "worker", "activity").await;
                let (status, body) = request(&app, "POST",
                    &format!("/api/worker/activity-tasks/{}/complete", claimed["task_id"].as_str().unwrap()),
                    json!({"lease_owner":"worker","activity_attempt_id":claimed["activity_attempt_id"],"result":envelope(Payload::Long(1))})).await;
                assert_eq!(status, StatusCode::OK, "{body}");
                database.execute("UPDATE workflow_runs SET run_deadline_at='2000-01-01 00:00:00' WHERE workflow_instance_id='origin-parent'").await;
                // The children's first tasks are ready too; take the parent's.
                let mut resumed = cooperative_poll(&app, "worker").await;
                let mut skipped = Vec::new();
                while resumed["workflow_id"] != "origin-parent" {
                    skipped.push(resumed["run_id"].clone());
                    resumed = cooperative_poll(&app, "worker").await;
                }
                let (status, body) = cooperative_finish(&app, &resumed,
                    json!([{"type":"complete_workflow","result":envelope(Payload::Long(2))}])).await;
                assert_eq!((status, body["reason"].clone()), (StatusCode::CONFLICT, json!("run_timed_out")), "{body}");
                // Release the skipped child claims so the check below can poll them.
                database.execute("UPDATE workflow_tasks SET status='ready',lease_owner=NULL,lease_expires_at=NULL WHERE status='leased'").await;
                assert_eq!(skipped.len(), 1, "one child task was ahead of the parent's");
            }
            _ => {}
        }
        let (status, described) = request(&app, "GET", "/api/workflows/origin-parent", Value::Null).await;
        assert_eq!(status, StatusCode::OK, "{described}");
        assert_eq!(described["closed_reason"], closed_reason, "{close}: {described}");
        let history = run_history(&app, &json!("origin-parent"), &started["run_id"]).await;
        let children = scheduled_children(&history);
        assert_eq!(children.len(), count_children, "{close}: {history}");
        let context = assert_parent_close_origin(&app, &database, "origin-parent", &started["run_id"], terminal, closed_reason, &children).await;
        // Each child's next task carries its request under that root.
        let mut delivered = Vec::new();
        for _ in 0..count_children {
            let task = cooperative_poll(&app, "worker").await;
            assert!(!task.is_null(), "{close}: a child task");
            delivered.push((task["run_id"].clone(), task["cancellation_request"]["request_id"].clone(),
                task["cancellation_request"]["cleanup_deadline_at"].clone()));
        }
        assert!(cooperative_poll(&app, "worker").await.is_null(), "{close}: nothing else is ready");
        for (child_instance, child_run) in &children {
            let child_history = run_history(&app, child_instance, child_run).await;
            let command = child_history[1]["payload"]["workflow_command_id"].clone();
            assert!(delivered.contains(&(child_run.clone(), command, context["cleanup_deadline_at"].clone())), "{close}: {delivered:?}");
        }
        assert_eq!(
            task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_commands WHERE command_type='request_cancellation' AND source='workflow' AND target_scope='instance' AND requested_workflow_run_id IS NULL AND status='accepted'").await,
            count_children as i64,
            "{close}"
        );
        runtime.close().await;
        database.remove().await;
    }
}

// A child closed by its parent's policy applies its own policies in turn: a
// `request_cancellation` grandchild takes the closed child's own root.
#[tokio::test]
async fn a_policy_close_gives_a_request_cancellation_grandchild_the_childs_own_root() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "parent-worker").await;
    cooperative_worker(&app, "child-worker").await;
    let (parent, child_id, child_run) = parent_with_child(&app, "nested-origin", "terminate", "terminate").await;
    let child_task = cooperative_poll(&app, "child-worker").await;
    assert_eq!(child_task["run_id"], child_run);
    let (status, body) = cooperative_finish(&app, &child_task, json!([child_with("request_cancellation")])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    close_parent(&app, &database, "nested-origin", "terminate").await;

    let parent_history = run_history(&app, &json!("nested-origin"), &parent["run_id"]).await;
    let applied = parent_history.as_array().unwrap().last().unwrap();
    assert_eq!(applied["event_type"], "ParentClosePolicyApplied", "{parent_history}");
    assert_eq!(applied["payload"]["child_run_id"], child_run);
    assert!(!types(&parent_history).contains(&"ParentCloseCancellationRequested".to_owned()), "{parent_history}");
    let child_history = run_history(&app, &child_id, &child_run).await;
    let grandchildren = scheduled_children(&child_history);
    assert_eq!(grandchildren.len(), 1, "{child_history}");
    assert_parent_close_origin(&app, &database, child_id.as_str().unwrap(), &child_run, "WorkflowTerminated", "terminated", &grandchildren).await;
    let (status, described) = request(&app, "GET", "/api/workflows/nested-origin", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{described}");
    assert_eq!(described["status"], "terminated");
    runtime.close().await;
    database.remove().await;
}

// A parent whose accepted request has no readable context keeps it as its
// root, as PHP's `ensureOrigin` does: it records no origin of its own, and
// the child's request is refused with `cancellation_parent_context_unavailable`.
#[tokio::test]
async fn a_parent_request_without_its_context_refuses_the_childs_request() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    cooperative_worker(&app, "worker").await;
    let started = start(&app, "lost-context").await;
    let task = cooperative_poll(&app, "worker").await;
    let (status, body) = cooperative_finish(&app, &task, json!([child_with("request_cancellation")])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    database.execute("UPDATE workflow_runs SET cancellation_request_command_id='01missingrequest0000000000' WHERE workflow_instance_id='lost-context'").await;
    let (status, body) = request(&app, "POST", "/api/workflows/lost-context/terminate", json!({"reason":"parent closes"})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &json!("lost-context"), &started["run_id"]).await;
    let children = scheduled_children(&history);
    let kinds = types(&history);
    assert_eq!(kinds[kinds.len() - 2..], ["WorkflowTerminated", "ParentClosePolicyFailed"], "{history}");
    assert_eq!(
        history.as_array().unwrap().last().unwrap()["payload"],
        json!({"child_instance_id": children[0].0, "child_run_id": children[0].1, "policy": "request_cancellation",
            "reason": "Parent workflow closed (terminated); parent-close policy: request_cancellation.",
            "error": "cancellation_parent_context_unavailable", "request_diagnostics": [], "request_id": null, "cancellation": null})
    );
    assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_commands WHERE command_type='request_cancellation'").await, 0);
    runtime.close().await;
    database.remove().await;
}

// A parent-close policy closes a child that continued as new: the policy
// reaches the child instance's current run, whose `continue_as_new` link from
// its predecessor PHP's close does not follow.
#[tokio::test]
async fn a_policy_close_reaches_a_child_that_continued_as_new() {
    for (policy, verb, status) in [("terminate", "terminate", "terminated"), ("request_cancel", "cancel", "cancelled")] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        update_worker(&app, "parent-worker").await;
        register(&app, "child-worker", json!(["child"]), json!([])).await;
        let (parent, child_id, child_run) = parent_with_child(&app, "continued-parent", policy, "terminate").await;
        let child_task = poll(&app, "child-worker", "workflow").await;
        assert_eq!(child_task["run_id"], child_run, "{child_task}");
        let (code, body) = finish_task(&app, &child_task, json!([{"type":"continue_as_new"}])).await;
        assert_eq!(code, StatusCode::OK, "{body}");
        let child = child_id.as_str().unwrap();
        let (_, continued) = request(&app, "GET", &format!("/api/workflows/{child}"), Value::Null).await;
        assert_ne!(continued["run_id"], child_run, "{continued}");
        close_parent(&app, &database, "continued-parent", "terminate").await;

        let parent_history = run_history(&app, &json!("continued-parent"), &parent["run_id"]).await;
        let last = parent_history.as_array().unwrap().last().unwrap();
        assert_eq!(last["event_type"], "ParentClosePolicyApplied", "{policy}: {parent_history}");
        assert_eq!((last["payload"]["child_instance_id"].clone(), last["payload"]["child_run_id"].clone()),
            (child_id.clone(), continued["run_id"].clone()), "{policy}: {last}");
        let (_, described) = request(&app, "GET", &format!("/api/workflows/{child}"), Value::Null).await;
        assert_eq!((described["run_id"].clone(), described["status"].clone()),
            (continued["run_id"].clone(), json!(status)), "{policy} ({verb}): {described}");
        runtime.close().await;
        database.remove().await;
    }
}

// A live query on the child holds no parent-close policy, as it holds no
// HTTP close: the policy closes the child, and the query admitted before it
// is still leased and answered.
#[tokio::test]
async fn a_policy_close_reaches_a_child_with_a_live_query() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "parent-worker").await;
    let (status, body) = request(&app, "POST", "/api/worker/register", json!({"worker_id":"child-worker",
        "task_queue":"test","runtime":"php","supported_workflow_types":["child"],"supported_activity_types":[],
        "capabilities":["query_tasks"],"workflow_command_contracts":{"child":{"queries":["state"],
        "query_contracts":[{"name":"state","parameters":[]}]}}})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    let (parent, child_id, child_run) = parent_with_child(&app, "queried-parent", "terminate", "terminate").await;
    let child = child_id.as_str().unwrap().to_owned();
    let leased = poll(&app, "child-worker", "workflow").await;
    assert_eq!(leased["run_id"], child_run, "{leased}");
    let waiting = {
        let app = app.clone();
        let path = format!("/api/workflows/{child}/query/state");
        tokio::spawn(async move { request(&app, "POST", &path, json!({"input":envelope(Payload::Array(vec![]))})).await })
    };
    let mut held = false;
    for _ in 0..20 {
        let (status, body) = request(&app, "POST", "/api/worker/query-tasks/poll",
            json!({"worker_id":"child-worker","task_queue":"test","timeout_seconds":1})).await;
        assert_eq!(status, StatusCode::OK, "{body}");
        if body["poll_status"] == "workflow_task_leased" {
            held = true;
            break;
        }
    }
    assert!(held, "the child's query was never admitted behind its leased task");
    close_parent(&app, &database, "queried-parent", "terminate").await;

    let parent_history = run_history(&app, &json!("queried-parent"), &parent["run_id"]).await;
    let last = parent_history.as_array().unwrap().last().unwrap();
    assert_eq!(last["event_type"], "ParentClosePolicyApplied", "{parent_history}");
    let (_, described) = request(&app, "GET", &format!("/api/workflows/{child}"), Value::Null).await;
    assert_eq!(described["status"], "terminated", "{described}");
    let claim = await_query(&app, "child-worker").await;
    assert_eq!(claim["run_id"], child_run, "{claim}");
    let answered = request(&app, "POST", &format!("/api/worker/query-tasks/{}/complete", claim["query_task_id"].as_str().unwrap()),
        json!({"lease_owner":"child-worker","query_task_attempt":claim["query_task_attempt"],
            "result_envelope":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(answered.0, StatusCode::OK, "{}", answered.1);
    let caller = waiting.await.unwrap();
    assert_eq!(caller.0, StatusCode::OK, "{}", caller.1);
    assert_eq!(caller.1["run_id"], child_run);
    runtime.close().await;
    database.remove().await;
}

// A child that continues as new after its parent closed still closes.
// `createParentResumeTasks` resolves only an open parent run, before reading
// the call, and the call still names the child's first run
// (`markChildCallContinued`), so the closed parent takes no resolution and its
// call stays as the parent left it.
#[tokio::test]
async fn a_child_continued_after_its_parent_closed_still_closes() {
    for close in ["terminate", "complete"] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        update_worker(&app, "parent-worker").await;
        register(&app, "child-worker", json!(["child"]), json!([])).await;
        let (parent, child_id, child_run) = parent_with_child(&app, "closed-first-parent", "abandon", "terminate").await;
        close_parent(&app, &database, "closed-first-parent", "terminate").await;
        let child = child_id.as_str().unwrap().to_owned();
        let task = poll(&app, "child-worker", "workflow").await;
        assert_eq!(task["run_id"], child_run, "{task}");
        let (status, body) = finish_task(&app, &task, json!([{"type":"continue_as_new"}])).await;
        assert_eq!(status, StatusCode::OK, "{body}");
        let successor = poll(&app, "child-worker", "workflow").await;
        assert_ne!(successor["run_id"], child_run, "{successor}");
        let parent_before = run_history(&app, &json!("closed-first-parent"), &parent["run_id"]).await;

        if close == "terminate" {
            let (status, body) = request(&app, "POST", &format!("/api/workflows/{child}/terminate"),
                json!({"reason":"after the parent"})).await;
            assert_eq!(status, StatusCode::OK, "{body}");
        } else {
            let (status, body) = finish_task(&app, &successor,
                json!([{"type":"complete_workflow","result":envelope(Payload::Long(2))}])).await;
            assert_eq!(status, StatusCode::OK, "{body}");
        }
        let (_, described) = request(&app, "GET", &format!("/api/workflows/{child}"), Value::Null).await;
        assert_eq!((described["run_id"].clone(), described["status"].clone()),
            (successor["run_id"].clone(), json!(if close == "terminate" { "terminated" } else { "completed" })), "{described}");
        assert_eq!(run_history(&app, &json!("closed-first-parent"), &parent["run_id"]).await, parent_before,
            "{close}: the closed parent takes no resolution");
        assert_eq!(count(&database, "SELECT COUNT(*) FROM workflow_child_calls WHERE status='started'".to_owned()).await, 1,
            "{close}: the call stays as the parent left it");
        runtime.close().await;
        database.remove().await;
    }
}

// A child that already holds a request under another root refuses the
// policy's request (`cancellation_root_conflict`). As PHP's `rejectCommand`
// does, the child records a rejected request at its next command sequence,
// as the parent's workflow command, with the conflict as its payload. The
// parent's `ParentClosePolicyFailed` names that command and its diagnostics.
#[tokio::test]
async fn a_root_conflict_records_the_rejected_request_on_the_child() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    cooperative_worker(&app, "worker").await;
    let started = start(&app, "conflict-parent").await;
    let task = cooperative_poll(&app, "worker").await;
    let (status, body) = cooperative_finish(&app, &task, json!([child_with("request_cancellation")])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &json!("conflict-parent"), &started["run_id"]).await;
    let (child_id, child_run) = scheduled_children(&history).remove(0);
    let (status, body) = request(&app, "POST", &format!("/api/workflows/{}/request-cancellation", child_id.as_str().unwrap()),
        json!({"reason":"child asked first"})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    let (status, body) = request(&app, "POST", "/api/workflows/conflict-parent/terminate", json!({"reason":"parent closes"})).await;
    assert_eq!(status, StatusCode::OK, "{body}");

    let history = run_history(&app, &json!("conflict-parent"), &started["run_id"]).await;
    let kinds = types(&history);
    assert_eq!(kinds[kinds.len() - 3..], ["WorkflowTerminated", "ParentCloseCancellationRequested", "ParentClosePolicyFailed"], "{history}");
    let origin = &history.as_array().unwrap()[kinds.len() - 2]["payload"]["cancellation"];
    let failed = &history.as_array().unwrap().last().unwrap()["payload"];
    let child_history = run_history(&app, &child_id, &child_run).await;
    assert_eq!(types(&child_history), ["WorkflowStarted", "CooperativeCancellationRequested"], "{child_history}");
    let own = &child_history[1]["payload"];
    let rejected = failed["request_id"].as_str().unwrap_or_else(|| panic!("a rejected command: {failed}")).to_owned();
    assert_eq!(*failed, json!({"child_instance_id": child_id, "child_run_id": child_run, "policy": "request_cancellation",
        "reason": "Parent workflow closed (terminated); parent-close policy: request_cancellation.",
        "error": "cancellation_root_conflict",
        "request_diagnostics": {"existing_request_id": own["workflow_command_id"], "existing_root_request_id": own["workflow_command_id"],
            "existing_cleanup_deadline_at": own["cleanup_deadline_at"], "incoming_root_request_id": origin["root_request_id"],
            "incoming_cleanup_deadline_at": origin["cleanup_deadline_at"]},
        "request_id": rejected, "cancellation": null}));
    assert_eq!(
        count(&database, format!("SELECT COUNT(*) FROM workflow_commands WHERE id='{rejected}' AND workflow_run_id='{}' AND command_type='request_cancellation' AND status='rejected' AND outcome IS NULL AND rejection_reason='cancellation_root_conflict' AND source='workflow' AND target_scope='instance' AND requested_workflow_run_id IS NULL AND command_sequence=2 AND payload IS NOT NULL AND rejected_at IS NOT NULL",
            child_run.as_str().unwrap())).await,
        1
    );
    assert_eq!(count(&database, format!("SELECT COUNT(*) FROM workflow_runs WHERE id='{}' AND last_command_sequence=2", child_run.as_str().unwrap())).await, 1);
    // The run description lists it after the child's own request.
    let (status, described) = request(&app, "GET", &format!("/api/workflows/{}", child_id.as_str().unwrap()), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{described}");
    let listed: Vec<_> = described["commands"].as_array().unwrap().iter()
        .map(|command| (command["sequence"].clone(), command["status"].clone(), command["source"].clone(), command["reason"].clone()))
        .collect();
    assert_eq!(listed, [(json!(1), json!("accepted"), json!("control_plane"), json!("child asked first")),
        (json!(2), json!("rejected"), json!("workflow"), json!("cancellation_root_conflict"))], "{described}");
    runtime.close().await;
    database.remove().await;
}
