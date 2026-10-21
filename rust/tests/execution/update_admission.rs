//! Updates are admitted whenever the run is active, as published PHP admits
//! them. An update waits behind an earlier signal that is not applied yet,
//! and an open workflow task delivers it after its own completion.
use super::*;

async fn update(app: &Router, workflow: &str, value: i64) -> (StatusCode, Value) {
    request(app, "POST", &format!("/api/workflows/{workflow}/update/set"),
        json!({"input":envelope(Payload::Array(vec![Payload::Long(value)])),"wait_for":"accepted"})).await
}

async fn finish_signal(app: &Router, workflow: &str) -> (StatusCode, Value) {
    request(app, "POST", &format!("/api/workflows/{workflow}/signal/finish"),
        json!({"input":envelope(Payload::Array(vec![Payload::Long(1)]))})).await
}

async fn complete_update(app: &Router, task: &Value, update_id: &Value, value: i64) {
    let completed = finish_task(app, task, json!([{"type":"complete_update","update_id":update_id,
        "result":envelope(Payload::Long(value))}])).await;
    assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
}

fn accepted_event<'a>(history: &'a Value, update_id: &Value) -> &'a Value {
    history.as_array().unwrap().iter()
        .find(|event| event["event_type"] == "UpdateAccepted" && event["payload"]["update_id"] == *update_id)
        .unwrap_or_else(|| panic!("no UpdateAccepted for {update_id}: {history}"))
}

fn event_types(history: &Value) -> Vec<String> {
    history.as_array().unwrap().iter()
        .map(|event| event["event_type"].as_str().unwrap().to_owned()).collect()
}

#[tokio::test]
async fn updates_admitted_during_open_tasks_follow_their_completion_in_order() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "update-worker").await;
    let started = start(&app, "open-task-updates").await;
    // The first workflow task is ready, then leased, when each update arrives.
    let first = update(&app, "open-task-updates", 1).await;
    assert_eq!(first.0, StatusCode::ACCEPTED, "{}", first.1);
    assert_eq!(first.1["update_status"], "accepted", "{}", first.1);
    let initial = poll(&app, "update-worker", "workflow").await;
    assert!(initial["workflow_update_id"].is_null(), "{initial}");
    let second = update(&app, "open-task-updates", 2).await;
    assert_eq!(second.0, StatusCode::ACCEPTED, "{}", second.1);
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    for receipt in [&first, &second] {
        let accepted = &accepted_event(&history, &receipt.1["update_id"])["payload"];
        assert_eq!(accepted["ordering_state"], "ready", "{accepted}");
        assert!(accepted["queued_behind_command_id"].is_null(), "{accepted}");
    }
    let finished = finish_task(&app, &initial,
        json!([{"type":"open_signal_wait","signal_name":"finish"}])).await;
    assert_eq!(finished.0, StatusCode::OK, "{}", finished.1);
    // Each update gets its own task, in admission order, after the open one.
    let delivered = poll(&app, "update-worker", "workflow").await;
    assert_eq!(delivered["workflow_update_id"], first.1["update_id"], "{delivered}");
    assert_eq!(poll(&app, "update-worker", "workflow").await, Value::Null);
    complete_update(&app, &delivered, &first.1["update_id"], 1).await;
    let next = poll(&app, "update-worker", "workflow").await;
    assert_eq!(next["workflow_update_id"], second.1["update_id"], "{next}");
    complete_update(&app, &next, &second.1["update_id"], 2).await;
    assert_eq!(poll(&app, "update-worker", "workflow").await, Value::Null);
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let completed = history.as_array().unwrap().iter()
        .filter(|event| event["event_type"] == "UpdateCompleted")
        .map(|event| event["payload"]["update_id"].clone()).collect::<Vec<_>>();
    assert_eq!(completed, [first.1["update_id"].clone(), second.1["update_id"].clone()]);
    // The run is still waiting for its signal.
    assert_eq!(finish_signal(&app, "open-task-updates").await.0, StatusCode::ACCEPTED);
    let signalled = poll(&app, "update-worker", "workflow").await;
    assert_eq!(signalled["resume_source_kind"], "workflow_signal", "{signalled}");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn an_update_queues_behind_an_unapplied_signal() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "update-worker").await;
    let started = start(&app, "queued-update").await;
    let initial = poll(&app, "update-worker", "workflow").await;
    let signal = finish_signal(&app, "queued-update").await;
    assert_eq!(signal.0, StatusCode::ACCEPTED, "{}", signal.1);
    let queued = update(&app, "queued-update", 5).await;
    assert_eq!(queued.0, StatusCode::ACCEPTED, "{}", queued.1);
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let accepted = &accepted_event(&history, &queued.1["update_id"])["payload"];
    assert_eq!(
        (accepted["ordering_state"].clone(), accepted["queued_behind_command_id"].clone(),
            accepted["queued_behind_command_sequence"].clone(), accepted["queued_behind_command_type"].clone()),
        (json!("queued"), signal.1["command_id"].clone(), signal.1["command_sequence"].clone(), json!("signal")),
        "{accepted}"
    );
    let finished = finish_task(&app, &initial,
        json!([{"type":"open_signal_wait","signal_name":"finish"}])).await;
    assert_eq!(finished.0, StatusCode::OK, "{}", finished.1);
    // The earlier signal is delivered first; the update follows it.
    let signalled = poll(&app, "update-worker", "workflow").await;
    assert_eq!(signalled["resume_source_kind"], "workflow_signal", "{signalled}");
    assert!(signalled["workflow_update_id"].is_null(), "{signalled}");
    let applied = finish_task(&app, &signalled,
        json!([{"type":"open_signal_wait","signal_name":"finish"}])).await;
    assert_eq!(applied.0, StatusCode::OK, "{}", applied.1);
    let delivered = poll(&app, "update-worker", "workflow").await;
    assert_eq!(delivered["workflow_update_id"], queued.1["update_id"], "{delivered}");
    complete_update(&app, &delivered, &queued.1["update_id"], 5).await;
    let types = event_types(&run_history(&app, &started["workflow_id"], &started["run_id"]).await);
    let position = |name: &str| types.iter().position(|event| event == name)
        .unwrap_or_else(|| panic!("{name} missing: {types:?}"));
    assert!(position("SignalApplied") < position("UpdateApplied"), "{types:?}");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_rejected_signal_does_not_hold_back_later_updates() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "update-worker").await;
    let started = start(&app, "rejected-signal-update").await;
    open_update_wait(&app, "update-worker").await;
    // A rejected signal still takes a message sequence, as in PHP.
    let rejected = request(&app, "POST", "/api/workflows/rejected-signal-update/signal/unknown",
        json!({"input":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(rejected.1["reason"], "unknown_signal", "{}", rejected.1);
    let accepted = update(&app, "rejected-signal-update", 3).await;
    assert_eq!(accepted.0, StatusCode::ACCEPTED, "{}", accepted.1);
    let delivered = poll(&app, "update-worker", "workflow").await;
    assert_eq!(delivered["workflow_update_id"], accepted.1["update_id"], "{delivered}");
    complete_update(&app, &delivered, &accepted.1["update_id"], 3).await;
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let advanced = history.as_array().unwrap().iter()
        .rfind(|event| event["event_type"] == "MessageCursorAdvanced").unwrap();
    assert_eq!(advanced["payload"]["previous_position"], 0, "{advanced}");
    assert_eq!(advanced["payload"]["new_position"], 2, "{advanced}");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_waiting_acknowledgement_delivers_a_pending_update() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "update-worker").await;
    start(&app, "waiting-update").await;
    let initial = poll(&app, "update-worker", "workflow").await;
    let accepted = update(&app, "waiting-update", 4).await;
    assert_eq!(accepted.0, StatusCode::ACCEPTED, "{}", accepted.1);
    let acknowledged = request(&app, "POST",
        &format!("/api/worker/workflow-tasks/{}/fail", initial["task_id"].as_str().unwrap()),
        json!({"lease_owner":initial["lease_owner"],"workflow_task_attempt":initial["workflow_task_attempt"],
            "failure":{"type":"WorkflowTaskWaitingForHistory","message":"history is not ready yet"}})).await;
    assert_eq!(acknowledged.0, StatusCode::OK, "{}", acknowledged.1);
    assert_eq!(acknowledged.1["outcome"], "waiting_for_history", "{}", acknowledged.1);
    let next = acknowledged.1["next_task_id"].clone();
    assert!(next.is_string(), "{}", acknowledged.1);
    let delivered = poll(&app, "update-worker", "workflow").await;
    assert_eq!(delivered["task_id"], next);
    assert_eq!(delivered["workflow_update_id"], accepted.1["update_id"], "{delivered}");
    complete_update(&app, &delivered, &accepted.1["update_id"], 4).await;
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_child_resolves_while_its_parent_has_an_update_task() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "update-worker").await;
    register(&app, "child-worker", json!(["child"]), json!([])).await;
    let parent = start(&app, "update-parent").await;
    let initial = poll(&app, "update-worker", "workflow").await;
    let scheduled = finish_task(&app, &initial, json!([child_command(1)])).await;
    assert_eq!(scheduled.0, StatusCode::OK, "{}", scheduled.1);
    let child = poll(&app, "child-worker", "workflow").await;
    // The parent waits on its child; the update gets its own task.
    let accepted = update(&app, "update-parent", 6).await;
    assert_eq!(accepted.0, StatusCode::ACCEPTED, "{}", accepted.1);
    let finished = finish_task(&app, &child,
        json!([{"type":"complete_workflow","result":envelope(Payload::Long(1))}])).await;
    assert_eq!(finished.0, StatusCode::OK, "{}", finished.1);
    let mut tasks = [poll(&app, "update-worker", "workflow").await, poll(&app, "update-worker", "workflow").await];
    tasks.sort_by_key(|task| task["workflow_update_id"].is_null());
    let [update_task, resume] = tasks;
    assert_eq!(update_task["workflow_update_id"], accepted.1["update_id"], "{update_task}");
    assert_eq!(resume["resume_source_kind"], "child_workflow_run", "{resume}");
    complete_update(&app, &update_task, &accepted.1["update_id"], 6).await;
    let completed = finish_task(&app, &resume,
        json!([{"type":"complete_workflow","result":envelope(Payload::Long(1))}])).await;
    assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
    let types = event_types(&run_history(&app, &parent["workflow_id"], &parent["run_id"]).await);
    assert!(types.iter().any(|event| event == "ChildRunCompleted"), "{types:?}");
    assert_eq!(types.last().map(String::as_str), Some("WorkflowCompleted"), "{types:?}");
    runtime.close().await;
    database.remove().await;
}
