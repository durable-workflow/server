use super::*;

// Server's POST …/cancellation-scopes/checkpoint over Core's
// checkpointCancellationScopePrefix: a cooperative worker commits the
// commands it recorded earlier in its turn, under the same claim, before
// opening a cancellation scope.

async fn register(app: &Router, worker: &str, capabilities: Value) {
    let registered = request_protocol(app, "POST", "/api/worker/register",
        json!({"worker_id":worker,"task_queue":"test","runtime":"php","supported_workflow_types":["echo"],
            "supported_activity_types":[],"capabilities":capabilities}), "1.20").await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
}

async fn claim(app: &Router, worker: &str) -> Value {
    let response = request_protocol(app, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":worker,"task_queue":"test","timeout_seconds":2}), "1.20").await;
    assert_eq!(response.0, StatusCode::OK, "{}", response.1);
    response.1["task"].clone()
}

async fn checkpoint(app: &Router, task: &Value, fields: Value, protocol: &str) -> (StatusCode, Value) {
    let mut body = json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"]});
    for (key, value) in fields.as_object().unwrap() {
        body[key] = value.clone();
    }
    request_protocol(app, "POST", &format!("/api/worker/workflow-tasks/{}/cancellation-scopes/checkpoint",
        task["task_id"].as_str().unwrap()), body, protocol).await
}

fn marker(change: &str) -> Value {
    json!({"type":"record_version_marker","change_id":change,"version":1,"min_supported":1,"max_supported":1})
}

fn prefix() -> Value {
    json!([{"type":"record_side_effect","result":envelope(Payload::Long(7))}, marker("before-scope")])
}

fn history_types(history: &Value) -> Vec<String> {
    history.as_array().unwrap().iter().map(|event| event["event_type"].as_str().unwrap().to_owned()).collect()
}

#[tokio::test]
async fn a_scope_prefix_commits_under_the_claim_and_retries_from_its_receipt() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "scope-worker", json!(["cooperative_cancellation"])).await;
    let run = start(&app, "scope-prefix").await;
    let task = claim(&app, "scope-worker").await;

    let (status, receipt) = checkpoint(&app, &task, json!({"checkpoint_id":"prefix-1","start_sequence":1,"commands":prefix()}), "1.20").await;
    assert_eq!(status, StatusCode::OK, "{receipt}");
    assert_eq!((receipt["checkpointed"].clone(), receipt["duplicate"].clone(), receipt["reason"].clone()),
        (json!(true), json!(false), Value::Null), "{receipt}");
    assert_eq!((receipt["checkpoint_id"].clone(), receipt["task_id"].clone(), receipt["workflow_run_id"].clone(),
        receipt["workflow_task_attempt"].clone(), receipt["lease_owner"].clone(), receipt["start_sequence"].clone(),
        receipt["next_sequence"].clone(), receipt["created_task_ids"].clone()),
        (json!("prefix-1"), task["task_id"].clone(), run["run_id"].clone(), task["workflow_task_attempt"].clone(),
            task["lease_owner"].clone(), json!(1), json!(3), json!([])));
    let fingerprint = receipt["fingerprint"].as_str().unwrap();
    assert!(fingerprint.len() == 64 && fingerprint.bytes().all(|byte| byte.is_ascii_hexdigit()), "{receipt}");
    assert!(receipt["lease_expires_at"].is_string() && receipt["recorded_at"].is_string(), "{receipt}");
    assert_eq!(receipt["history_refresh_page_token"], "MA==");
    assert!(receipt["protocol_version"].is_string() && receipt["server_capabilities"].is_object(), "{receipt}");
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let recorded = history_types(&history);
    assert!(recorded.ends_with(&["SideEffectRecorded".to_owned(), "VersionMarkerRecorded".to_owned()]), "{recorded:?}");
    let events = recorded.len();

    // An identical retry answers the retained receipt and writes nothing.
    let (status, again) = checkpoint(&app, &task, json!({"checkpoint_id":"prefix-1","start_sequence":1,"commands":prefix()}), "1.20").await;
    assert_eq!((status, again["duplicate"].clone()), (StatusCode::OK, json!(true)), "{again}");
    for field in ["fingerprint", "next_sequence", "recorded_at", "lease_expires_at", "created_task_ids"] {
        assert_eq!(again[field], receipt[field], "{field}");
    }
    // The same id with other commands, or a new id at a used sequence.
    let (status, mismatch) = checkpoint(&app, &task,
        json!({"checkpoint_id":"prefix-1","start_sequence":1,"commands":[marker("other")]}), "1.20").await;
    assert_eq!((status, mismatch["reason"].clone()), (StatusCode::CONFLICT, json!("cancellation_scope_checkpoint_mismatch")));
    assert_eq!((mismatch["checkpointed"].clone(), mismatch["duplicate"].clone(), mismatch["task_id"].clone()),
        (json!(false), json!(false), task["task_id"].clone()), "{mismatch}");
    let (status, stale) = checkpoint(&app, &task,
        json!({"checkpoint_id":"prefix-2","start_sequence":1,"commands":[marker("late")]}), "1.20").await;
    assert_eq!((status, stale["reason"].clone()), (StatusCode::CONFLICT, json!("cancellation_scope_checkpoint_sequence_mismatch")));
    assert_eq!(history_types(&run_history(&app, &run["workflow_id"], &run["run_id"]).await).len(), events);

    // The claim is unchanged: the worker opens its scope at the next
    // sequence and completes the turn.
    let (status, opened) = request_protocol(&app, "POST",
        &format!("/api/worker/workflow-tasks/{}/cancellation-scopes/open", task["task_id"].as_str().unwrap()),
        json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],"sequence":3}), "1.20").await;
    assert_eq!((status, opened["opened"].clone()), (StatusCode::OK, json!(true)), "{opened}");
    let finished = finish_task(&app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await;
    assert_eq!(finished.0, StatusCode::OK, "{}", finished.1);
    assert_eq!(finished.1["run_status"], "completed", "{}", finished.1);
}

#[tokio::test]
async fn a_prefix_schedules_operations_and_refuses_what_closes_a_turn() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "scope-worker", json!(["cooperative_cancellation"])).await;
    let run = start(&app, "scope-prefix-ops").await;
    let task = claim(&app, "scope-worker").await;
    for (commands, reason) in [
        (json!([{"type":"open_signal_wait","signal_name":"go"}]), "invalid_cancellation_scope_checkpoint_commands"),
        (json!([{"type":"open_condition_wait","condition_key":"ready"}]), "invalid_cancellation_scope_checkpoint_commands"),
        (json!([marker("a"), {"type":"complete_workflow","result":envelope(Payload::Null)}]), "invalid_cancellation_scope_checkpoint_commands"),
    ] {
        let (status, refused) = checkpoint(&app, &task, json!({"checkpoint_id":"ops","start_sequence":1,"commands":commands}), "1.20").await;
        assert_eq!((status, refused["reason"].clone()), (StatusCode::CONFLICT, json!(reason)), "{refused}");
        assert_eq!(refused["history_refresh_page_token"], "MA==", "{refused}");
    }
    assert!(!history_types(&run_history(&app, &run["workflow_id"], &run["run_id"]).await).contains(&"VersionMarkerRecorded".to_owned()));

    // A timer in a prefix creates its task, as a completion would.
    let (status, receipt) = checkpoint(&app, &task,
        json!({"checkpoint_id":"ops","start_sequence":1,"commands":[{"type":"start_timer","delay_seconds":3600}]}), "1.20").await;
    assert_eq!(status, StatusCode::OK, "{receipt}");
    assert_eq!((receipt["next_sequence"].clone(), receipt["created_task_ids"].as_array().map(Vec::len)), (json!(2), Some(1)), "{receipt}");
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    assert!(history_types(&history).contains(&"TimerScheduled".to_owned()));
}

#[tokio::test]
async fn the_route_checks_protocol_fields_and_claim_before_core() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "scope-worker", json!(["cooperative_cancellation"])).await;
    register(&app, "plain-worker", json!([])).await;
    start(&app, "scope-gates").await;
    let task = claim(&app, "scope-worker").await;

    let (status, old) = checkpoint(&app, &task, json!({"checkpoint_id":"g","start_sequence":1,"commands":prefix()}), "1.19").await;
    assert_eq!((status, old["checkpointed"].clone(), old["reason"].clone()),
        (StatusCode::CONFLICT, json!(false), json!("cancellation_scope_checkpoint_requires_protocol_1_20")), "{old}");
    assert!(old["server_capabilities"].is_object(), "{old}");

    let (status, invalid) = checkpoint(&app, &task, json!({"start_sequence":0,"sticky_cache":{"ttl_seconds":1},"commands":prefix()}), "1.20").await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{invalid}");
    assert_eq!(invalid["errors"], json!({"checkpoint_id":["The checkpoint id field is required."],
        "start_sequence":["The start sequence field must be at least 1."],
        "sticky_cache":["The sticky cache field is prohibited."]}), "{invalid}");

    let mut other = task.clone();
    other["lease_owner"] = json!("someone-else");
    let (status, foreign) = checkpoint(&app, &other, json!({"checkpoint_id":"g","start_sequence":1,"commands":prefix()}), "1.20").await;
    assert_eq!((status, foreign["reason"].clone()), (StatusCode::CONFLICT, json!("lease_owner_mismatch")), "{foreign}");

    // A claim issued to a worker without cooperative cancellation.
    let finished = finish_task(&app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await;
    assert_eq!(finished.0, StatusCode::OK, "{}", finished.1);
    start(&app, "scope-gates-plain").await;
    let plain = claim(&app, "plain-worker").await;
    let (status, refused) = checkpoint(&app, &plain, json!({"checkpoint_id":"g","start_sequence":1,"commands":prefix()}), "1.20").await;
    assert_eq!((status, refused.get("task_id").cloned(), refused["reason"].clone()),
        (StatusCode::CONFLICT, None, json!("active_claim_cancellation_not_supported")), "{refused}");
    assert!(refused.get("history_refresh_page_token").is_none(), "{refused}");
}

#[tokio::test]
async fn a_prefix_waits_for_a_root_cancellation_to_be_delivered() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "scope-worker", json!(["cooperative_cancellation"])).await;
    start(&app, "scope-cancelled").await;
    let response = request(&app, "POST", "/api/workflows/scope-cancelled/request-cancellation",
        json!({"reason":"stop","cleanup_timeout_seconds":30})).await;
    assert_eq!(response.0, StatusCode::ACCEPTED, "{}", response.1);
    let pending = response.1["cancellation_request"].clone();
    let task = claim(&app, "scope-worker").await;
    let (status, refused) = checkpoint(&app, &task, json!({"checkpoint_id":"c","start_sequence":1,"commands":prefix()}), "1.20").await;
    assert_eq!((status, refused["reason"].clone()), (StatusCode::CONFLICT, json!("cancellation_requested")), "{refused}");

    // Delivered at sequence 1, a prefix after the boundary is cleanup.
    let delivered = super::cooperative_children::deliver(&app, &task, &pending, 1, "timer").await;
    assert_eq!(delivered.1["delivered"], true, "{}", delivered.1);
    let (status, early) = checkpoint(&app, &task, json!({"checkpoint_id":"c","start_sequence":1,"commands":prefix()}), "1.20").await;
    assert_eq!((status, early["reason"].clone()), (StatusCode::CONFLICT, json!("cancellation_requested")), "{early}");
    let (status, receipt) = checkpoint(&app, &task, json!({"checkpoint_id":"c","start_sequence":2,"commands":prefix()}), "1.20").await;
    assert_eq!((status, receipt["next_sequence"].clone()), (StatusCode::OK, json!(4)), "{receipt}");
}

/// A cooperative worker that declares the `finish` signal and the `set`
/// update, as `update_worker` does.
async fn contract_worker(app: &Router, worker: &str) {
    let registered = request_protocol(app, "POST", "/api/worker/register", json!({
        "worker_id":worker,"task_queue":"test","runtime":"php","supported_workflow_types":["echo"],
        "supported_activity_types":[],"capabilities":["cooperative_cancellation","workflow_updates"],"workflow_command_contracts":{"echo":{
            "signals":["finish"],"signal_contracts":[{"name":"finish","parameters":[{"name":"value","type":"int","required":true,"variadic":false,"default_available":false,"allows_null":false}]}],
            "updates":["set"],"update_validators":[],"update_contracts":[{"name":"set","parameters":[{"name":"value","type":"int","required":true,"variadic":false,"default_available":false,"allows_null":false}]}]
        }}}), "1.20").await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
}

fn events_of<'a>(history: &'a Value, kind: &str) -> Vec<&'a Value> {
    history.as_array().unwrap().iter().filter(|event| event["event_type"] == kind).collect()
}

// Core's recordAppliedSignalForSignalResume applies a resumed task's signal
// once: a prefix checkpoint applies it, and the second checkpoint and the
// completion on the same claim leave it applied.
#[tokio::test]
async fn a_signal_resumed_claim_checkpoints_then_completes() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    contract_worker(&app, "signal-worker").await;
    let run = start(&app, "scope-signal").await;
    let first = claim(&app, "signal-worker").await;
    let parked = finish_task(&app, &first, json!([{"type":"open_signal_wait","signal_name":"finish"}])).await;
    assert_eq!(parked.0, StatusCode::OK, "{}", parked.1);
    let signalled = request(&app, "POST", "/api/workflows/scope-signal/signal/finish",
        json!({"input":envelope(Payload::Array(vec![Payload::Long(1)]))})).await;
    assert_eq!(signalled.0, StatusCode::ACCEPTED, "{}", signalled.1);
    let task = claim(&app, "signal-worker").await;
    assert_eq!(task["resume_source_kind"], "workflow_signal", "{task}");

    let (status, receipt) = checkpoint(&app, &task,
        json!({"checkpoint_id":"after-signal","start_sequence":2,"commands":[marker("after-signal")]}), "1.20").await;
    assert_eq!((status, receipt["next_sequence"].clone()), (StatusCode::OK, json!(3)), "{receipt}");
    let (status, second) = checkpoint(&app, &task,
        json!({"checkpoint_id":"second","start_sequence":3,"commands":[marker("second")]}), "1.20").await;
    assert_eq!((status, second["next_sequence"].clone()), (StatusCode::OK, json!(4)), "{second}");
    let finished = finish_task(&app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await;
    assert_eq!((finished.0, finished.1["run_status"].clone()), (StatusCode::OK, json!("completed")), "{}", finished.1);
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    assert_eq!(events_of(&history, "SignalApplied").len(), 1, "{history}");
}

// Core's nextDurableCommandSequence counts SearchAttributesUpserted, so a
// prefix that ends in an upsert is followed at its receipt's next sequence.
#[tokio::test]
async fn a_prefix_ending_in_a_search_attribute_upsert_is_followed_at_its_next_sequence() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let defined = request(&app, "POST", "/api/search-attributes", json!({"name":"ScopeCount","type":"int"})).await;
    assert_eq!(defined.0, StatusCode::CREATED, "{}", defined.1);
    register(&app, "scope-worker", json!(["cooperative_cancellation", "typed_search_attributes"])).await;
    start(&app, "scope-upsert").await;
    let task = claim(&app, "scope-worker").await;
    let (status, receipt) = checkpoint(&app, &task, json!({"checkpoint_id":"upsert","start_sequence":1,
        "commands":[{"type":"upsert_search_attributes","attributes":{"ScopeCount":1}}]}), "1.20").await;
    assert_eq!((status, receipt["next_sequence"].clone()), (StatusCode::OK, json!(2)), "{receipt}");
    let (status, opened) = request_protocol(&app, "POST",
        &format!("/api/worker/workflow-tasks/{}/cancellation-scopes/open", task["task_id"].as_str().unwrap()),
        json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],"sequence":2}), "1.20").await;
    assert_eq!((status, opened["opened"].clone()), (StatusCode::OK, json!(true)), "{opened}");
}

// Core changes a run's status at completion (markRunWaiting), not at a
// prefix checkpoint: a timer in a prefix leaves the run as the claim found it.
#[tokio::test]
async fn a_prefix_leaves_the_run_status_until_completion() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "scope-worker", json!(["cooperative_cancellation"])).await;
    start(&app, "scope-status").await;
    let task = claim(&app, "scope-worker").await;
    let status_of = || async { request(&app, "GET", "/api/workflows/scope-status", Value::Null).await.1["status"].clone() };
    let claimed = status_of().await;
    assert_ne!(claimed, "waiting");
    let (status, receipt) = checkpoint(&app, &task,
        json!({"checkpoint_id":"timer","start_sequence":1,"commands":[{"type":"start_timer","delay_seconds":3600}]}), "1.20").await;
    assert_eq!(status, StatusCode::OK, "{receipt}");
    assert_eq!(status_of().await, claimed);
    let finished = finish_task(&app, &task, json!([marker("after-timer")])).await;
    assert_eq!(finished.0, StatusCode::OK, "{}", finished.1);
    assert_eq!(status_of().await, "waiting");
}

// Core's applyCompleteUpdate records the update at the batch's next sequence
// and returns the one after it: a command after the update takes the
// following sequence, in a completion as in a prefix checkpoint.
#[tokio::test]
async fn a_command_after_an_update_completion_takes_the_following_sequence() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    contract_worker(&app, "update-worker").await;
    let run = start(&app, "scope-updates").await;
    let first = claim(&app, "update-worker").await;
    let parked = finish_task(&app, &first, json!([{"type":"open_signal_wait","signal_name":"finish"}])).await;
    assert_eq!(parked.0, StatusCode::OK, "{}", parked.1);
    for (round, through) in [(1, "complete"), (2, "checkpoint")] {
        let accepted = request(&app, "POST", "/api/workflows/scope-updates/update/set",
            json!({"input":envelope(Payload::Array(vec![Payload::Long(round)])),"request_id":format!("set-{round}"),"wait_for":"accepted"})).await;
        assert_eq!(accepted.0, StatusCode::ACCEPTED, "{}", accepted.1);
        let task = claim(&app, "update-worker").await;
        assert_eq!(task["workflow_update_id"], accepted.1["update_id"], "{task}");
        let commands = json!([{"type":"complete_update","update_id":accepted.1["update_id"],"result":envelope(Payload::Long(round))},
            marker(&format!("after-update-{round}"))]);
        if through == "complete" {
            let finished = finish_task(&app, &task, commands).await;
            assert_eq!(finished.0, StatusCode::OK, "{}", finished.1);
        } else {
            let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
            let next = events_of(&history, "VersionMarkerRecorded").iter().filter_map(|event| event["payload"]["sequence"].as_i64()).max().unwrap() + 1;
            let (status, receipt) = checkpoint(&app, &task, json!({"checkpoint_id":"update","start_sequence":next,"commands":commands}), "1.20").await;
            assert_eq!((status, receipt["next_sequence"].clone()), (StatusCode::OK, json!(next + 2)), "{receipt}");
        }
        let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
        let update = *events_of(&history, "UpdateCompleted").last().unwrap();
        let marker_event = events_of(&history, "VersionMarkerRecorded").into_iter()
            .find(|event| event["payload"]["change_id"] == format!("after-update-{round}")).unwrap();
        assert_eq!(marker_event["payload"]["sequence"].as_i64().unwrap(), update["payload"]["sequence"].as_i64().unwrap() + 1,
            "{update} {marker_event}");
    }
}
