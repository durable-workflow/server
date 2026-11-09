//! Server #1078: activity completion that also completes the run's next
//! workflow task with worker-computed commands.
use super::*;

const QUEUE: &str = "test";

/// Starts `workflow`, schedules one activity and leases it to `worker`.
async fn leased_activity(app: &Router, worker: &str, workflow: &str) -> Value {
    let started = start(app, workflow).await;
    RUNS.lock().unwrap().insert(workflow.to_owned(), started["run_id"].clone());
    let task = poll(app, worker, "workflow").await;
    assert!(task.is_object(), "workflow task for {workflow}");
    let done = finish_task(app, &task, json!([{"type":"schedule_activity","activity_type":"activity",
        "arguments":envelope(Payload::Array(vec![]))}])).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    let activity = poll(app, worker, "activity").await;
    assert!(activity.is_object(), "activity task for {workflow}");
    activity
}

async fn continuation_history(app: &Router, activity: &Value, owner: &str) -> (StatusCode, Value) {
    request(app, "POST", &format!("/api/worker/activity-tasks/{}/continuation/history", activity["task_id"].as_str().unwrap()),
        json!({"lease_owner":owner,"activity_attempt_id":activity["activity_attempt_id"]})).await
}

fn continuation_body(activity: &Value, owner: &str, history: &Value, commands: Value, poll_id: &str) -> Value {
    json!({"lease_owner":owner,"activity_attempt_id":activity["activity_attempt_id"],"result":envelope(Payload::Long(7)),
        "workflow_poll":{"worker_id":owner,"task_queue":QUEUE,"poll_request_id":poll_id,"timeout_seconds":0},
        "continuation":{"version":1,"history_through_sequence":history["history_through_sequence"],
            "history_last_event_id":history["history_last_event_id"],"commands":commands}})
}

async fn continue_activity(app: &Router, activity: &Value, body: Value) -> (StatusCode, Value) {
    request(app, "POST", &format!("/api/worker/activity-tasks/{}/continuation/complete", activity["task_id"].as_str().unwrap()), body).await
}

static RUNS: std::sync::LazyLock<std::sync::Mutex<std::collections::HashMap<String, Value>>> =
    std::sync::LazyLock::new(Default::default);

async fn event_types(app: &Router, workflow: &str) -> Vec<String> {
    let run = RUNS.lock().unwrap()[workflow].clone();
    run_history(app, &json!(workflow), &run).await.as_array().unwrap().iter()
        .map(|event| event["event_type"].as_str().unwrap().to_owned()).collect()
}

fn complete_workflow() -> Value {
    json!([{"type":"complete_workflow","result":envelope(Payload::Long(7))}])
}

#[tokio::test]
async fn accepted_continuation_records_the_result_and_the_commands_exactly_once() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "continuing", json!(["echo"]), json!(["activity"])).await;
    let activity = leased_activity(&app, "continuing", "continuation-accepted").await;
    let (status, history) = continuation_history(&app, &activity, "continuing").await;
    assert_eq!(status, StatusCode::OK, "{history}");
    assert_eq!(history["continuation"], json!({"version":1,"eligible":true,"reason":null}));
    assert_eq!(history["server_capabilities"]["activity_workflow_continuation"]["version"], 1);
    let events = history["history_events"].as_array().unwrap();
    assert_eq!(history["history_through_sequence"].as_i64().unwrap(), events.len() as i64);
    assert_eq!(history["history_last_event_id"], events.last().unwrap()["id"]);
    assert_eq!(history["workflow_type"], "echo");
    assert!(history["arguments"].is_object());
    let before = event_types(&app, "continuation-accepted").await;

    let body = continuation_body(&activity, "continuing", &history, complete_workflow(), "continuation-accepted-poll");
    let (status, done) = continue_activity(&app, &activity, body.clone()).await;
    assert_eq!(status, StatusCode::OK, "{done}");
    assert_eq!(done["recorded"], true, "{done}");
    assert_eq!(done["continuation"]["outcome"], "accepted", "{done}");
    assert_eq!(done["continuation"]["workflow_task_id"], done["next_task_id"]);
    assert_eq!(done["continuation"]["workflow_task_attempt"], 1);
    assert_eq!(done["continuation"]["completion"]["recorded"], true, "{done}");
    assert_eq!(done["continuation"]["completion"]["run_status"], "completed", "{done}");
    assert!(done.get("workflow_poll").is_none(), "the carried lease was used by the continuation");

    let after = event_types(&app, "continuation-accepted").await;
    assert_eq!(&after[..before.len()], &before[..], "the worker's prefix is unchanged");
    let added: Vec<&str> = after[before.len()..].iter().map(String::as_str).collect();
    assert_eq!(added.first(), Some(&"ActivityCompleted"), "{added:?}");
    assert_eq!(added.last(), Some(&"WorkflowCompleted"), "{added:?}");
    assert_eq!(added.iter().filter(|kind| **kind == "ActivityCompleted").count(), 1);

    // A lost answer: the retry records nothing more.
    let (status, retry) = continue_activity(&app, &activity, body.clone()).await;
    assert_eq!(status, StatusCode::OK, "exact retry must recover the committed decision: {retry}");
    assert_eq!(retry["continuation"], done["continuation"]);
    assert_eq!(retry["recorded"], false);
    let mut changed = body;
    changed["result"] = envelope(Payload::Long(8));
    let (status, conflict) = continue_activity(&app, &activity, changed).await;
    assert_eq!(status, StatusCode::CONFLICT, "{conflict}");
    assert_eq!(conflict["reason"], "conflicting_completion");
    assert_eq!(event_types(&app, "continuation-accepted").await, after);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_signal_after_the_history_read_declines_and_leaves_ordinary_work() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "signalled", json!(["echo"]), json!(["activity"])).await;
    let activity = leased_activity(&app, "signalled", "continuation-signalled").await;
    let (_, history) = continuation_history(&app, &activity, "signalled").await;
    assert_eq!(history["continuation"]["eligible"], true, "{history}");
    let signal = request(&app, "POST", "/api/workflows/continuation-signalled/signal/note", json!([1])).await;
    assert_eq!(signal.0, StatusCode::ACCEPTED, "{}", signal.1);
    // The worker's next history read sees work it has not replayed.
    let (_, stale) = continuation_history(&app, &activity, "signalled").await;
    assert!(stale["continuation"]["eligible"] == false
        || stale["history_through_sequence"].as_i64() > history["history_through_sequence"].as_i64(), "{stale}");

    let body = continuation_body(&activity, "signalled", &history, complete_workflow(), "continuation-signalled-poll");
    let (status, done) = continue_activity(&app, &activity, body.clone()).await;
    assert_eq!(status, StatusCode::OK, "{done}");
    assert_eq!(done["recorded"], true, "the activity result is still recorded");
    assert_eq!(done["continuation"], json!({"version":1,"outcome":"declined","reason":"history_changed"}));
    let types = event_types(&app, "continuation-signalled").await;
    assert!(!types.iter().any(|kind| kind == "WorkflowCompleted"), "no speculative command applied: {types:?}");
    let before_retry = table_counts(&database).await;
    let (status, retry) = continue_activity(&app, &activity, body).await;
    assert_eq!(status, StatusCode::OK, "{}", retry["reason"]);
    assert_eq!(retry["recorded"], false);
    assert_eq!(retry["continuation"], done["continuation"]);
    assert_eq!(table_counts(&database).await, before_retry, "retry does not claim or renew anything");
    if done["workflow_poll"]["leased"] == true {
        assert_eq!(retry["workflow_poll"]["answer"]["task"]["task_id"], done["workflow_poll"]["answer"]["task"]["task_id"]);
        assert_eq!(retry["workflow_poll"]["answer"]["task"]["lease_expires_at"], done["workflow_poll"]["answer"]["task"]["lease_expires_at"]);
    }
    // The carried lease, if any, is finished the ordinary way.
    if done["workflow_poll"]["leased"] == true {
        let task = done["workflow_poll"]["answer"]["task"].clone();
        let finished = finish_task(&app, &task, complete_workflow()).await;
        assert_eq!(finished.0, StatusCode::OK, "{}", finished.1);
    }
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn the_resumed_task_is_claimed_ahead_of_other_waiting_runs() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "ordered", json!(["echo"]), json!(["activity"])).await;
    let activity = leased_activity(&app, "ordered", "continuation-ordered").await;
    let (_, history) = continuation_history(&app, &activity, "ordered").await;
    assert_eq!(history["continuation"]["eligible"], true, "{history}");
    // Another run's task is already waiting on the queue, ahead of the resume.
    start(&app, "continuation-earlier").await;
    let body = continuation_body(&activity, "ordered", &history, complete_workflow(), "continuation-ordered-poll");
    let (status, done) = continue_activity(&app, &activity, body).await;
    assert_eq!(status, StatusCode::OK, "{done}");
    assert_eq!(done["recorded"], true);
    // Same-worker priority: the continuation completes its own resumed task.
    assert_eq!(done["continuation"]["outcome"], "accepted", "{done}");
    assert_eq!(done["continuation"]["workflow_task_id"], done["next_task_id"]);
    assert!(event_types(&app, "continuation-ordered").await.iter().any(|kind| kind == "WorkflowCompleted"));
    // The other run's task was not touched and is next for ordinary dispatch.
    let waiting = poll(&app, "ordered", "workflow").await;
    assert_eq!(waiting["workflow_id"], "continuation-earlier");
    assert_eq!(waiting["workflow_task_attempt"], 1);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn rejected_commands_preserve_the_original_activity_authority() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "rejected", json!(["echo"]), json!(["activity"])).await;
    let activity = leased_activity(&app, "rejected", "continuation-rejected").await;
    let (_, history) = continuation_history(&app, &activity, "rejected").await;
    // Request checks pass; the ordinary completion refuses two terminal commands.
    let commands = json!([{"type":"complete_workflow","result":envelope(Payload::Long(1))},
        {"type":"complete_workflow","result":envelope(Payload::Long(2))}]);
    let body = continuation_body(&activity, "rejected", &history, commands, "continuation-rejected-poll");
    let (status, done) = continue_activity(&app, &activity, body).await;
    assert_eq!(status, StatusCode::CONFLICT, "{}", done["reason"]);
    assert_eq!(done["reason"], "invalid_commands");
    assert!(!event_types(&app, "continuation-rejected").await.iter().any(|kind| kind == "ActivityCompleted"));
    // The same original activity lease can submit a corrected transaction.
    let body = continuation_body(&activity, "rejected", &history, complete_workflow(), "continuation-rejected-poll");
    let (status, fixed) = continue_activity(&app, &activity, body).await;
    assert_eq!(status, StatusCode::OK, "{}", fixed["reason"]);
    assert_eq!(fixed["continuation"]["outcome"], "accepted");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn malformed_continuations_and_foreign_readers_write_nothing() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "malformed", json!(["echo"]), json!(["activity"])).await;
    let activity = leased_activity(&app, "malformed", "continuation-malformed").await;
    let (status, foreign) = continuation_history(&app, &activity, "someone-else").await;
    assert_eq!(status, StatusCode::CONFLICT, "{foreign}");
    assert_eq!(foreign["reason"], "stale_attempt");
    let (_, history) = continuation_history(&app, &activity, "malformed").await;
    let before = event_types(&app, "continuation-malformed").await;
    let child = json!([{"type":"start_child_workflow","workflow_type":"child","arguments":envelope(Payload::Array(vec![]))}]);
    let mut without_poll = continuation_body(&activity, "malformed", &history, complete_workflow(), "unused");
    without_poll.as_object_mut().unwrap().remove("workflow_poll");
    let mut wrong_version = continuation_body(&activity, "malformed", &history, complete_workflow(), "unused");
    wrong_version["continuation"]["version"] = json!(2);
    for (body, reason) in [
        (continuation_body(&activity, "malformed", &history, child, "unused"), "unsupported_continuation_command"),
        (without_poll, "continuation_requires_workflow_poll"),
        (wrong_version, "unsupported_continuation_version"),
    ] {
        let (status, refused) = continue_activity(&app, &activity, body).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{refused}");
        assert_eq!(refused["reason"], reason, "{refused}");
    }
    // A command the ordinary completion's request checks refuse is refused
    // the same way, before the activity result is written.
    let invalid_timer = continuation_body(&activity, "malformed", &history, json!([{"type":"start_timer","delay_seconds":"soon"}]), "unused");
    let (status, refused) = continue_activity(&app, &activity, invalid_timer).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{refused}");
    assert_eq!(event_types(&app, "continuation-malformed").await, before, "nothing was written");
    // The activity is still running and completes the ordinary way.
    let ordinary = request(&app, "POST", &format!("/api/worker/activity-tasks/{}/complete", activity["task_id"].as_str().unwrap()),
        json!({"lease_owner":"malformed","activity_attempt_id":activity["activity_attempt_id"],"result":envelope(Payload::Null)})).await;
    assert_eq!(ordinary.0, StatusCode::OK, "{}", ordinary.1);
    assert_eq!(ordinary.1["recorded"], true);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_command_validation_fault_rolls_back_the_activity_result() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "atomic-fault", json!(["echo"]), json!(["activity"])).await;
    let activity = leased_activity(&app, "atomic-fault", "continuation-atomic-fault").await;
    let (_, history) = continuation_history(&app, &activity, "atomic-fault").await;
    let before = event_types(&app, "continuation-atomic-fault").await;
    let commands = json!([{"type":"complete_workflow","result":envelope(Payload::Long(1))},
        {"type":"complete_workflow","result":envelope(Payload::Long(2))}]);
    let body = continuation_body(&activity, "atomic-fault", &history, commands, "continuation-atomic-fault-poll");
    let (status, refusal) = continue_activity(&app, &activity, body).await;
    assert!(status.is_client_error(), "a validation fault is an error, not a committed partial result: {status} {refusal}");
    assert_eq!(event_types(&app, "continuation-atomic-fault").await, before,
        "the refused transaction must not record ActivityCompleted or a claim");
    let (status, history) = continuation_history(&app, &activity, "atomic-fault").await;
    assert_eq!(status, StatusCode::OK, "original activity authority remains valid: {history}");
    assert_eq!(history["continuation"]["eligible"], true);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_backend_fault_after_a_speculative_write_rolls_back_every_effect() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "storage-fault", json!(["echo"]), json!(["activity"])).await;
    let activity = leased_activity(&app, "storage-fault", "continuation-storage-fault").await;
    let (_, history) = continuation_history(&app, &activity, "storage-fault").await;
    match &database {
        TestDatabase::Sqlite(_) => database.execute("CREATE TRIGGER continuation_fault BEFORE INSERT ON workflow_history_events WHEN NEW.event_type='WorkflowCompleted' BEGIN SELECT RAISE(ABORT,'continuation fault fixture'); END").await,
        TestDatabase::MySql { .. } => database.execute("CREATE TRIGGER continuation_fault BEFORE INSERT ON workflow_history_events FOR EACH ROW BEGIN IF NEW.event_type='WorkflowCompleted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='continuation fault fixture'; END IF; END").await,
        TestDatabase::Postgres { .. } => {
            database.execute("CREATE FUNCTION continuation_fault_fn() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.event_type='WorkflowCompleted' THEN RAISE EXCEPTION 'continuation fault fixture'; END IF; RETURN NEW; END $$").await;
            database.execute("CREATE TRIGGER continuation_fault BEFORE INSERT ON workflow_history_events FOR EACH ROW EXECUTE FUNCTION continuation_fault_fn()").await;
        }
    }
    let before = table_counts(&database).await;
    let events = event_types(&app, "continuation-storage-fault").await;
    let body = continuation_body(&activity, "storage-fault", &history,
        json!([{"type":"record_version_marker","change_id":"atomic-marker","version":1,"min_supported":1,"max_supported":1},
            {"type":"complete_workflow","result":envelope(Payload::Long(7))}]), "continuation-storage-fault-poll");
    let (status, refused) = continue_activity(&app, &activity, body.clone()).await;
    assert_eq!(status, StatusCode::SERVICE_UNAVAILABLE, "fault must remain visible: {}", refused["reason"]);
    assert_eq!(refused["reason"], "storage_unavailable");
    assert_eq!(table_counts(&database).await, before, "no result, marker, task, receipt or poll binding survives");
    assert_eq!(event_types(&app, "continuation-storage-fault").await, events);
    match &database {
        TestDatabase::Postgres { .. } => database.execute("DROP TRIGGER continuation_fault ON workflow_history_events").await,
        _ => database.execute("DROP TRIGGER continuation_fault").await,
    }
    let (status, accepted) = continue_activity(&app, &activity, body).await;
    assert_eq!(status, StatusCode::OK, "correct recovery uses the original activity authority: {}", accepted["reason"]);
    assert_eq!(accepted["continuation"]["outcome"], "accepted");
    let events = event_types(&app, "continuation-storage-fault").await;
    assert_eq!(events.iter().filter(|event| *event == "ActivityCompleted").count(), 1);
    assert_eq!(events.iter().filter(|event| *event == "VersionMarkerRecorded").count(), 1);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn concurrent_identical_requests_recover_one_atomic_decision() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "concurrent", json!(["echo"]), json!(["activity"])).await;
    let activity = leased_activity(&app, "concurrent", "continuation-concurrent").await;
    let (_, history) = continuation_history(&app, &activity, "concurrent").await;
    let body = continuation_body(&activity, "concurrent", &history, complete_workflow(), "continuation-concurrent-poll");
    let (first, second) = tokio::join!(continue_activity(&app, &activity, body.clone()), continue_activity(&app, &activity, body));
    assert_eq!((first.0, second.0), (StatusCode::OK, StatusCode::OK));
    assert_eq!(first.1["continuation"], second.1["continuation"]);
    assert_eq!(first.1["continuation"]["outcome"], "accepted");
    assert_ne!(first.1["recorded"], second.1["recorded"]);
    let events = event_types(&app, "continuation-concurrent").await;
    assert_eq!(events.iter().filter(|event| *event == "ActivityCompleted").count(), 1);
    assert_eq!(events.iter().filter(|event| *event == "WorkflowCompleted").count(), 1);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn completion_independently_refuses_a_gapped_prefix_with_the_same_last_event() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "gap", json!(["echo"]), json!(["activity"])).await;
    let activity = leased_activity(&app, "gap", "continuation-gap").await;
    let (_, history) = continuation_history(&app, &activity, "gap").await;
    database.execute("DELETE FROM workflow_history_events WHERE sequence=1").await;
    let (_, now) = continuation_history(&app, &activity, "gap").await;
    assert_eq!(now["continuation"]["eligible"], false);
    let before = event_types(&app, "continuation-gap").await;
    let body = continuation_body(&activity, "gap", &history, complete_workflow(), "continuation-gap-poll");
    let (status, declined) = continue_activity(&app, &activity, body).await;
    assert_eq!(status, StatusCode::CONFLICT, "{}", declined["reason"]);
    assert_eq!(declined["reason"], "history_budget_unavailable", "an invariant fault cannot become a soft decline");
    assert_eq!(event_types(&app, "continuation-gap").await, before);
    assert!(!event_types(&app, "continuation-gap").await.iter().any(|kind| kind == "WorkflowCompleted"));
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn legacy_absent_and_corrupt_receipts_never_become_a_new_continuation() {
    for replacement in [0, 1, 2] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        register(&app, "receipts", json!(["echo"]), json!(["activity"])).await;
        let activity = leased_activity(&app, "receipts", "continuation-receipts").await;
        let (_, history) = continuation_history(&app, &activity, "receipts").await;
        let body = continuation_body(&activity, "receipts", &history, complete_workflow(), "continuation-receipts-poll");
        let (status, _) = continue_activity(&app, &activity, body.clone()).await;
        assert_eq!(status, StatusCode::OK);
        let before = event_types(&app, "continuation-receipts").await;
        match replacement {
            0 => database.execute("DELETE FROM dw_task_completions WHERE task_id IN (SELECT id FROM workflow_tasks WHERE task_type='activity')").await,
            1 => database.execute("UPDATE dw_task_completions SET receipt='{}' WHERE task_id IN (SELECT id FROM workflow_tasks WHERE task_type='activity')").await,
            _ => database.execute("UPDATE dw_task_completions SET receipt='{\"activity_workflow_continuation_v1\":{}}' WHERE task_id IN (SELECT id FROM workflow_tasks WHERE task_type='activity')").await,
        }
        let (status, refused) = continue_activity(&app, &activity, body).await;
        if replacement == 2 {
            assert_eq!(status, StatusCode::INTERNAL_SERVER_ERROR);
            assert_eq!(refused["reason"], "invalid_continuation_receipt");
        } else {
            assert_eq!(status, StatusCode::CONFLICT);
            assert_eq!(refused["reason"], "stale_attempt");
        }
        assert_eq!(event_types(&app, "continuation-receipts").await, before);
        runtime.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn the_history_transaction_budget_includes_the_activity_result() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "budget", json!(["echo"]), json!(["activity"])).await;
    let activity = leased_activity(&app, "budget", "continuation-budget").await;
    let (_, history) = continuation_history(&app, &activity, "budget").await;
    let before = table_counts(&database).await;
    let body = continuation_body(&activity, "budget", &history, complete_workflow(), "continuation-budget-poll");
    let bounded = router(runtime.clone().with_structural_limits(durable_workflow_server::runtime::StructuralLimits {
        history_events: Some(1), ..Default::default()
    }));
    let (status, refused) = continue_activity(&bounded, &activity, body.clone()).await;
    assert_eq!(status, StatusCode::CONFLICT, "{}", refused["reason"]);
    assert_eq!(refused["reason"], "history_transaction_limit_exceeded");
    assert_eq!(refused["task_id"], activity["task_id"]);
    assert_eq!(refused["activity_attempt_id"], activity["activity_attempt_id"]);
    assert!(refused.get("workflow_task_attempt").is_none());
    assert_eq!(table_counts(&database).await, before);
    let (status, done) = continue_activity(&app, &activity, body).await;
    assert_eq!(status, StatusCode::OK, "{}", done["reason"]);
    assert_eq!(done["continuation"]["outcome"], "accepted");
    runtime.close().await;
    database.remove().await;
}
