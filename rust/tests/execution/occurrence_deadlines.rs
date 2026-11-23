//! Server #269: `deadline_policy: "occurrence"` keeps an authored condition
//! wait's first-open wall deadline A across false-wake reopens, while a new
//! authored call, a continued run, another run or another namespace gets its
//! own. Opted-in conflicts refuse with nothing written.
use super::*;

const FINGERPRINT: &str = "sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";

fn anchored(occurrence: &str, remaining: i64, budget: i64) -> Value {
    json!({"type":"open_condition_wait","condition_key":"ready","condition_definition_fingerprint":FINGERPRINT,
        "condition_wait_occurrence_id":occurrence,"timeout_seconds":remaining,
        "deadline_policy":"occurrence","condition_wait_timeout_budget_seconds":budget})
}

async fn author(app: &Router, namespace: &str, worker: &str) -> Value {
    let response = namespaces::scoped(app, namespace, "POST", "/api/worker/register", json!({
        "worker_id":worker,"task_queue":"test","runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":[],
        "capabilities":["condition_wait_input_order"],
        "workflow_command_contracts":{"echo":{"signals":["payload"],"signal_contracts":[{"name":"payload","parameters":[{
            "name":"value","position":0,"type":"int","required":true,"variadic":false,"default_available":false,"allows_null":false}]}]}}
    })).await;
    assert_eq!(response.0, StatusCode::CREATED, "{}", response.1);
    response.1
}

async fn claim(app: &Router, namespace: &str) -> Value {
    let response = namespaces::scoped(app, namespace, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"deadline-worker","task_queue":"test","timeout_seconds":5})).await;
    assert_eq!(response.0, StatusCode::OK, "{}", response.1);
    assert!(response.1["task"].is_object(), "{}", response.1);
    response.1["task"].clone()
}

async fn claim_with_request_id(app: &Router, request_id: &str) -> Value {
    let (status, result) = request(app, "POST", "/api/worker/workflow-tasks/poll", json!({
        "worker_id":"deadline-worker", "task_queue":"test", "timeout_seconds":5, "poll_request_id":request_id,
    })).await;
    assert_eq!(status, StatusCode::OK, "{result}");
    assert!(result["task"].is_object(), "{result}");
    result["task"].clone()
}

async fn finish(app: &Router, namespace: &str, task: &Value, commands: Value) -> (StatusCode, Value) {
    namespaces::scoped(app, namespace, "POST",
        &format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap()),
        completion(task, commands)).await
}

async fn await_next_input(app: &Router, namespace: &str, task: &Value) {
    let (status, result) = namespaces::scoped(app, namespace, "POST",
        &format!("/api/worker/workflow-tasks/{}/fail", task["task_id"].as_str().unwrap()),
        json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],
            "failure":{"type":"WorkflowTaskWaitingForHistory","message":"Waiting for the next accepted input."}}),
    ).await;
    assert_eq!(status, StatusCode::OK, "{result}");
    assert_eq!(result["outcome"], "waiting_for_history");
}

async fn claim_input(app: &Router, namespace: &str, kind: &str) -> Value {
    let mut task = claim(app, namespace).await;
    if task["resume_source_kind"] == "timer" {
        await_next_input(app, namespace, &task).await;
        task = claim(app, namespace).await;
    }
    assert_eq!(task["resume_source_kind"], kind);
    task
}

async fn history(app: &Router, namespace: &str, run: &Value) -> Vec<Value> {
    let response = namespaces::scoped(app, namespace, "GET",
        &format!("/api/workflows/{}/runs/{}/history", run["workflow_id"].as_str().unwrap(), run["run_id"].as_str().unwrap()),
        Value::Null).await;
    assert_eq!(response.0, StatusCode::OK, "{}", response.1);
    response.1["events"].as_array().unwrap().clone()
}

fn payloads(events: &[Value], kind: &str) -> Vec<Value> {
    events.iter().filter(|event| event["event_type"] == kind).map(|event| event["payload"].clone()).collect()
}

async fn signal(app: &Router, namespace: &str, run: &Value) {
    let response = namespaces::scoped(app, namespace, "POST",
        &format!("/api/workflows/{}/signal/payload", run["workflow_id"].as_str().unwrap()),
        json!({"input":envelope(Payload::Array(vec![Payload::Long(7)]))})).await;
    assert_eq!(response.0, StatusCode::ACCEPTED, "{}", response.1);
}

fn instant(value: &Value) -> chrono::DateTime<chrono::FixedOffset> {
    chrono::DateTime::parse_from_rfc3339(value.as_str().unwrap()).unwrap()
}

async fn setup(namespace: &str) -> (TestDatabase, Runtime, Router) {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap().with_ordered_condition_wait_authoring(true);
    let app = router(runtime.clone());
    if namespace != "default" {
        namespaces::create(&app, namespace).await;
    }
    let registered = author(&app, namespace, "deadline-worker").await;
    assert_eq!(registered["server_capabilities"]["condition_wait_occurrence_deadline"], true);
    (database, runtime, app)
}

#[tokio::test]
async fn authoring_is_bound_to_the_claim_and_can_be_disabled_while_a_wait_replays() {
    let namespace = "default";
    let (database, runtime, enabled) = setup(namespace).await;
    let disabled = router(runtime.clone().with_ordered_condition_wait_authoring(false));
    let legacy = namespaces::start_scoped(&disabled, namespace, "legacy-while-upgrading").await;
    let task = claim_with_request_id(&disabled, "legacy-authoring-claim").await;
    assert!(task.get("condition_wait_input_order_authoring").is_none());
    let repeated = claim_with_request_id(&enabled, "legacy-authoring-claim").await;
    assert_eq!(repeated["task_id"], task["task_id"]);
    assert_eq!(repeated["workflow_task_attempt"], task["workflow_task_attempt"]);
    assert!(repeated.get("condition_wait_input_order_authoring").is_none());
    let mut command = anchored("gate:0", 240, 240);
    command["condition_wait_input_order"] = json!("durable_receipt_order");
    let prefix = history(&disabled, namespace, &legacy).await;
    let (status, result) = finish(&enabled, namespace, &task, json!([command])).await;
    assert_eq!(status, StatusCode::CONFLICT, "{result}");
    assert_eq!(result["reason"], "condition_wait_input_order_authoring_disabled");
    assert_eq!(history(&disabled, namespace, &legacy).await, prefix);
    let (status, result) = finish(&disabled, namespace, &task, json!([anchored("gate:0", 240, 240)])).await;
    assert_eq!(status, StatusCode::OK, "{result}");

    let run = namespaces::start_scoped(&enabled, namespace, "ordered-after-upgrade").await;
    let task = claim_with_request_id(&enabled, "ordered-authoring-claim").await;
    assert_eq!(task["condition_wait_input_order_authoring"], true);
    let repeated = claim_with_request_id(&disabled, "ordered-authoring-claim").await;
    assert_eq!(repeated["task_id"], task["task_id"]);
    assert_eq!(repeated["workflow_task_attempt"], task["workflow_task_attempt"]);
    assert_eq!(repeated["condition_wait_input_order_authoring"], true);
    let mut command = anchored("gate:0", 0, 0);
    command["condition_wait_input_order"] = json!("durable_receipt_order");
    let (status, result) = finish(&disabled, namespace, &task, json!([command])).await;
    assert_eq!(status, StatusCode::OK, "{result}");
    let fired = wait_for_fire(&disabled, namespace, &run, 1).await;
    let replay = claim(&disabled, namespace).await;
    assert!(replay.get("condition_wait_input_order_authoring").is_none());
    assert_eq!(replay.get("condition_wait_pending_input_sequence"), Some(&Value::Null));
    let (status, result) = finish(&disabled, namespace, &replay, json!([{
        "type":"resolve_condition_wait","condition_wait_id":fired[0]["condition_wait_id"],"satisfied":false,
    }])).await;
    assert_eq!(status, StatusCode::OK, "{result}");
    let events = history(&disabled, namespace, &run).await;
    assert_eq!(payloads(&events, "ConditionWaitTimedOut").len(), 1);
    runtime.close().await;
    database.remove().await;
}

/// Open the first anchored wait of `occurrence`, returning the run and A.
async fn open(app: &Router, namespace: &str, name: &str, occurrence: &str, budget: i64) -> (Value, Value) {
    let run = namespaces::start_scoped(app, namespace, name).await;
    let task = claim(app, namespace).await;
    let done = finish(app, namespace, &task, json!([anchored(occurrence, budget, budget)])).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    let events = history(app, namespace, &run).await;
    let opened = payloads(&events, "ConditionWaitOpened");
    let timers = payloads(&events, "TimerScheduled");
    assert_eq!((opened.len(), timers.len()), (1, 1));
    // The first open sets A = its commit + T and records it.
    assert_eq!(opened[0]["occurrence_deadline_at"], timers[0]["fire_at"]);
    assert_eq!(timers[0]["deadline_policy"], "occurrence");
    assert_eq!(timers[0]["delay_seconds"], budget);
    (run, timers[0]["fire_at"].clone())
}

/// A false wake: the signal satisfies the open wait, and the same task
/// reopens the same authored call with `remaining`.
async fn false_wake(app: &Router, namespace: &str, run: &Value, occurrence: &str, remaining: i64, budget: i64,
    delay: std::time::Duration) -> (StatusCode, Value) {
    signal(app, namespace, run).await;
    let task = claim(app, namespace).await;
    tokio::time::sleep(delay).await;
    finish(app, namespace, &task, json!([anchored(occurrence, remaining, budget)])).await
}

async fn wait_for_fire(app: &Router, namespace: &str, run: &Value, count: usize) -> Vec<Value> {
    let deadline = tokio::time::Instant::now() + std::time::Duration::from_secs(10);
    loop {
        let fired = payloads(&history(app, namespace, run).await, "TimerFired");
        if fired.len() >= count {
            return fired;
        }
        assert!(tokio::time::Instant::now() < deadline, "the condition timer did not fire");
        tokio::time::sleep(std::time::Duration::from_millis(20)).await;
    }
}

#[tokio::test]
async fn the_cluster_advertises_the_occurrence_deadline() {
    let (database, runtime, app) = setup("default").await;
    let (status, info) = request(&app, "GET", "/api/cluster/info", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{info}");
    assert_eq!(info["capabilities"]["condition_wait_occurrence_deadline"], true);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_condition_occurrence_cannot_change_its_input_order_policy() {
    for marked in [false, true] {
        let namespace = "default";
        let (database, runtime, app) = setup(namespace).await;
        let run = namespaces::start_scoped(&app, namespace, "per-occurrence-input-order").await;
        let opening = claim(&app, namespace).await;
        let mut command = anchored("receipt-order:0", 60, 60);
        if marked {
            command["condition_wait_input_order"] = json!("durable_receipt_order");
        }
        assert_eq!(
            finish(&app, namespace, &opening, json!([command.clone()])).await.0,
            StatusCode::OK,
        );

        signal(&app, namespace, &run).await;
        let wake = claim(&app, namespace).await;
        if marked {
            command.as_object_mut().unwrap().remove("condition_wait_input_order");
        } else {
            command["condition_wait_input_order"] = json!("durable_receipt_order");
        }
        let before = history(&app, namespace, &run).await;
        let (status, result) = finish(&app, namespace, &wake, json!([command])).await;
        assert_eq!(status, StatusCode::CONFLICT, "{result}");
        assert_eq!(result["reason"], "condition_wait_occurrence_mismatch");
        assert_eq!(history(&app, namespace, &run).await, before);
        runtime.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn applying_a_signal_does_not_settle_a_condition_with_receipt_order() {
    let namespace = "default";
    let (database, runtime, app) = setup(namespace).await;
    let run = namespaces::start_scoped(&app, namespace, "ordered-predicate-wake").await;
    let initial = claim(&app, namespace).await;
    let mut command = anchored("receipt-order:0", 60, 60);
    command["condition_wait_input_order"] = json!("durable_receipt_order");
    let (status, result) = finish(&app, namespace, &initial, json!([command.clone()])).await;
    assert_eq!(status, StatusCode::OK, "{result}");

    signal(&app, namespace, &run).await;
    let wake = claim(&app, namespace).await;
    let (status, result) = finish(&app, namespace, &wake, json!([command])).await;
    assert_eq!(status, StatusCode::OK, "{result}");
    let events = history(&app, namespace, &run).await;
    assert_eq!(payloads(&events, "SignalApplied").len(), 1);
    assert_eq!(payloads(&events, "ConditionWaitOpened").len(), 1);
    assert!(payloads(&events, "ConditionWaitSatisfied").is_empty());
    assert!(payloads(&events, "TimerCancelled").is_empty());
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn acknowledging_the_last_signal_wakes_the_ordered_predicate() {
    let namespace = "default";
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap().with_ordered_condition_wait_authoring(true);
    let app = router(runtime.clone());
    let run = namespaces::start_scoped(&app, namespace, "ordered-signal-recheck").await;
    let events = history(&app, namespace, &run).await;
    let started = &payloads(&events, "WorkflowStarted")[0];
    assert!(started.get("declared_signals").is_none());
    author(&app, namespace, "deadline-worker").await;
    let initial = claim(&app, namespace).await;
    let mut command = anchored("receipt-order:0", 60, 60);
    command["condition_wait_input_order"] = json!("durable_receipt_order");
    assert_eq!(finish(&app, namespace, &initial, json!([command])).await.0, StatusCode::OK);
    signal(&app, namespace, &run).await;
    let task = claim_input(&app, namespace, "workflow_signal").await;
    let events = history(&app, namespace, &run).await;
    let receipt = &events.iter().find(|e| e["event_type"] == "SignalReceived").unwrap()["sequence"];
    assert_eq!(task["condition_wait_pending_input_sequence"], *receipt);
    await_next_input(&app, namespace, &task).await;
    let task = claim(&app, namespace).await;
    assert_eq!(task["resume_source_kind"], "condition_input");
    assert_eq!(task.get("condition_wait_pending_input_sequence"), Some(&Value::Null));
    let (status, result) = finish(&app, namespace, &task, json!([{
        "type":"resolve_condition_wait", "condition_wait_id":task["condition_wait_id"],
        "satisfied":true, "input_receipt_sequence":receipt,
    }])).await;
    assert_eq!(status, StatusCode::OK, "{result}");
    let events = history(&app, namespace, &run).await;
    assert_eq!(payloads(&events, "SignalApplied").len(), 1);
    assert_eq!(payloads(&events, "ConditionWaitSatisfied").len(), 1);
    assert_eq!(payloads(&events, "TimerCancelled").len(), 1);
    assert_eq!(poll(&app, "deadline-worker", "workflow").await, Value::Null);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn an_ordered_timeout_waits_for_early_input_and_excludes_late_receipts() {
    for early in [true, false] {
        let namespace = "default";
        let (database, runtime, app) = setup(namespace).await;
        let run = namespaces::start_scoped(&app, namespace, "ordered-receipt-boundary").await;
        let initial = claim(&app, namespace).await;
        let mut command = anchored("receipt-order:0", 2, 2);
        command["condition_wait_input_order"] = json!("durable_receipt_order");
        let (status, result) = finish(&app, namespace, &initial, json!([command])).await;
        assert_eq!(status, StatusCode::OK, "{result}");
        if early {
            signal(&app, namespace, &run).await;
            signal(&app, namespace, &run).await;
        }
        wait_for_fire(&app, namespace, &run, 1).await;
        if !early {
            signal(&app, namespace, &run).await;
        }

        let prefix = history(&app, namespace, &run).await;
        let event = |kind: &str| prefix.iter().find(|event| event["event_type"] == kind).unwrap();
        let wait = &event("ConditionWaitOpened")["payload"]["condition_wait_id"];
        let receipt = &event("SignalReceived")["sequence"];
        let timer = &event("TimerFired")["sequence"];
        assert_eq!(receipt.as_i64() < timer.as_i64(), early);
        let wake = if early { claim_input(&app, namespace, "workflow_signal").await } else { claim(&app, namespace).await };
        let timeout = json!([{"type":"resolve_condition_wait","condition_wait_id":wait,"satisfied":false}]);
        let decision = json!([{
            "type":"resolve_condition_wait", "condition_wait_id":wait, "satisfied":true,
            "input_receipt_sequence":receipt,
        }]);
        if early {
            let (status, result) = finish(&app, namespace, &wake, timeout).await;
            assert_eq!(status, StatusCode::CONFLICT, "{result}");
            assert_eq!(result["reason"], "condition_wait_input_pending");
            assert_eq!(history(&app, namespace, &run).await, prefix);
            await_next_input(&app, namespace, &wake).await;
            let second = claim_input(&app, namespace, "workflow_signal").await;
            let (status, result) = finish(&app, namespace, &second, decision).await;
            assert_eq!(status, StatusCode::OK, "{result}");
            let history = history(&app, namespace, &run).await;
            assert_eq!(payloads(&history, "SignalApplied").len(), 2);
            let result = payloads(&history, "ConditionWaitSatisfied");
            assert_eq!(result.len(), 1);
            assert_eq!(result[0]["input_receipt_sequence"], *receipt);
        } else {
            let (status, result) = finish(&app, namespace, &wake, decision).await;
            assert_eq!(status, StatusCode::CONFLICT, "{result}");
            assert_eq!(result["reason"], "condition_wait_outcome_mismatch");
            assert_eq!(history(&app, namespace, &run).await, prefix);
            let (status, result) = finish(&app, namespace, &wake, json!([{
                "type":"resolve_condition_wait", "condition_wait_id":wait, "satisfied":true,
            }])).await;
            assert_eq!(status, StatusCode::CONFLICT, "{result}");
            assert_eq!(result["reason"], "condition_wait_outcome_mismatch");
            assert_eq!(history(&app, namespace, &run).await, prefix);
            let (status, result) = finish(&app, namespace, &wake, timeout).await;
            assert_eq!(status, StatusCode::OK, "{result}");
            let result = payloads(&history(&app, namespace, &run).await, "ConditionWaitTimedOut");
            assert_eq!(result.len(), 1);
            assert_eq!(result[0]["timer_fired_sequence"], *timer);
        }
        runtime.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn unhandled_signal_receipts_cannot_strand_an_ordered_timeout() {
    let namespace = "default";
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap().with_ordered_condition_wait_authoring(true);
    let app = router(runtime.clone());
    let (status, registered) = request(&app, "POST", "/api/worker/register", json!({
        "worker_id":"deadline-worker","task_queue":"test","runtime":"php",
        "supported_workflow_types":["echo"],"capabilities":["condition_wait_input_order"],
    })).await;
    assert_eq!(status, StatusCode::CREATED, "{registered}");
    let run = namespaces::start_scoped(&app, namespace, "ordered-receipt-without-handler").await;
    let initial = claim(&app, namespace).await;
    let mut command = anchored("receipt-order:0", 2, 2);
    command["condition_wait_input_order"] = json!("durable_receipt_order");
    assert_eq!(finish(&app, namespace, &initial, json!([command])).await.0, StatusCode::OK);
    signal(&app, namespace, &run).await;
    signal(&app, namespace, &run).await;
    wait_for_fire(&app, namespace, &run, 1).await;
    let first = claim_input(&app, namespace, "workflow_signal").await;
    await_next_input(&app, namespace, &first).await;
    let second = claim_input(&app, namespace, "workflow_signal").await;
    let events = history(&app, namespace, &run).await;
    let wait = &payloads(&events, "ConditionWaitOpened")[0]["condition_wait_id"];
    let (status, result) = finish(&app, namespace, &second, json!([{
        "type":"resolve_condition_wait", "condition_wait_id":wait, "satisfied":false,
    }])).await;
    assert_eq!(status, StatusCode::OK, "{result}");
    let events = history(&app, namespace, &run).await;
    assert!(payloads(&events, "SignalApplied").is_empty());
    assert_eq!(payloads(&events, "ConditionWaitTimedOut").len(), 1);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn after_fire_only_a_successful_update_can_supply_receipt_evidence() {
    for (succeeds, resolves_same_turn) in [(true, true), (true, false), (false, true)] {
        let namespace = "default";
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap().with_ordered_condition_wait_authoring(true);
        let app = router(runtime.clone());
        let (status, registered) = request(&app, "POST", "/api/worker/register", json!({
            "worker_id":"deadline-worker","task_queue":"test","runtime":"php","supported_workflow_types":["echo"],
            "capabilities":["workflow_updates","condition_wait_input_order"],"workflow_command_contracts":{"echo":{
                "signals":[],"updates":["set"],"update_validators":[],"update_contracts":[{"name":"set",
                    "parameters":[{"name":"value","type":"int","required":true,"variadic":false,
                        "default_available":false,"allows_null":false}]}]
            }},
        })).await;
        assert_eq!(status, StatusCode::CREATED, "{registered}");
        let workflow = "ordered-update-evidence";
        let run = namespaces::start_scoped(&app, namespace, workflow).await;
        let initial = claim(&app, namespace).await;
        let mut command = anchored("receipt-order:0", 2, 2);
        command["condition_wait_input_order"] = json!("durable_receipt_order");
        assert_eq!(finish(&app, namespace, &initial, json!([command])).await.0, StatusCode::OK);
        let (status, update) = request(&app, "POST", &format!("/api/workflows/{workflow}/update/set"), json!({
            "input":envelope(Payload::Array(vec![Payload::Long(1)])),"wait_for":"accepted",
        })).await;
        assert_eq!(status, StatusCode::ACCEPTED, "{update}");
        wait_for_fire(&app, namespace, &run, 1).await;
        let task = claim_input(&app, namespace, "workflow_update").await;
        assert_eq!(task["workflow_update_id"], update["update_id"]);
        let prefix = history(&app, namespace, &run).await;
        let event = |kind: &str| prefix.iter().find(|event| event["event_type"] == kind).unwrap();
        let wait = &event("ConditionWaitOpened")["payload"]["condition_wait_id"];
        let receipt = &event("UpdateAccepted")["sequence"];
        let fire = &event("TimerFired")["sequence"];
        assert!(receipt.as_i64() < fire.as_i64());
        assert_eq!(task["condition_wait_pending_input_sequence"], *receipt);
        let chosen = json!({"type":"resolve_condition_wait", "condition_wait_id":wait,
            "satisfied":true, "input_receipt_sequence":receipt});
        if succeeds {
            let completed = json!({"type":"complete_update", "update_id":update["update_id"],
                "result":envelope(Payload::Long(1))});
            let (status, result) = if resolves_same_turn {
                finish(&app, namespace, &task, json!([completed, chosen])).await
            } else {
                let timer = claim(&app, namespace).await;
                assert_eq!(timer["resume_source_kind"], "timer");
                assert_eq!(timer["condition_wait_pending_input_sequence"], *receipt);
                await_next_input(&app, namespace, &timer).await;
                let (status, result) = finish(&app, namespace, &task, json!([completed])).await;
                assert_eq!(status, StatusCode::OK, "{result}");
                let evaluation = claim(&app, namespace).await;
                assert_eq!(evaluation["resume_source_kind"], "condition_input");
                assert_eq!(evaluation.get("condition_wait_pending_input_sequence"), Some(&Value::Null));
                finish(&app, namespace, &evaluation, json!([chosen])).await
            };
            assert_eq!(status, StatusCode::OK, "{result}");
            let events = history(&app, namespace, &run).await;
            let applied = events.iter().find(|event| event["event_type"] == "UpdateApplied").unwrap();
            assert!(fire.as_i64() < applied["sequence"].as_i64());
            let outcome = payloads(&events, "ConditionWaitSatisfied");
            assert_eq!(outcome.len(), 1);
            assert_eq!(outcome[0]["input_receipt_sequence"], *receipt);
        } else {
            let failed = json!({"type":"fail_update", "update_id":update["update_id"], "message":"handler failed"});
            let (status, result) = finish(&app, namespace, &task, json!([failed.clone(), chosen])).await;
            assert_eq!(status, StatusCode::CONFLICT, "{result}");
            assert_eq!(result["reason"], "condition_wait_outcome_mismatch");
            assert_eq!(history(&app, namespace, &run).await, prefix);
            let (status, result) = finish(&app, namespace, &task, json!([failed, {
                "type":"resolve_condition_wait", "condition_wait_id":wait, "satisfied":false,
            }])).await;
            assert_eq!(status, StatusCode::OK, "{result}");
            let events = history(&app, namespace, &run).await;
            let outcomes = payloads(&events, "UpdateCompleted");
            assert_eq!(outcomes.len(), 1);
            assert_eq!(outcomes[0]["update_id"], update["update_id"]);
            assert!(outcomes[0]["failure_id"].is_string());
            assert!(payloads(&events, "UpdateApplied").is_empty());
            assert_eq!(payloads(&events, "ConditionWaitTimedOut").len(), 1);
        }
        runtime.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn ordered_condition_history_stays_with_capable_workers_after_resolution() {
    let namespace = "default";
    let (database, runtime, app) = setup(namespace).await;
    let legacy = "legacy-deadline-worker";
    register(&app, legacy, json!(["echo"]), json!([])).await;
    let mut command = anchored("receipt-order:0", 0, 0);
    command["condition_wait_input_order"] = json!("durable_receipt_order");

    let unmarked = namespaces::start_scoped(&app, namespace, "ordered-condition-claim-guard").await;
    let old = poll(&app, legacy, "workflow").await;
    let before = history(&app, namespace, &unmarked).await;
    let (status, result) = finish(&app, namespace, &old, json!([command.clone()])).await;
    assert_eq!(status, StatusCode::CONFLICT, "{result}");
    assert_eq!(result["reason"], "condition_wait_input_order_not_supported");
    assert_eq!(history(&app, namespace, &unmarked).await, before);
    assert_eq!(finish(&app, namespace, &old, json!([{
        "type":"complete_workflow","result":envelope(Payload::Boolean(true)),
    }])).await.0, StatusCode::OK);

    let marked = namespaces::start_scoped(&app, namespace, "ordered-condition-history-guard").await;
    let initial = claim(&app, namespace).await;
    assert_eq!(finish(&app, namespace, &initial, json!([command])).await.0, StatusCode::OK);
    let fired = wait_for_fire(&app, namespace, &marked, 1).await;
    assert_eq!(poll(&app, legacy, "workflow").await, Value::Null);
    let wake = claim(&app, namespace).await;
    let (status, result) = finish(&app, namespace, &wake, json!([{
        "type":"resolve_condition_wait", "condition_wait_id":fired[0]["condition_wait_id"], "satisfied":false,
    }, {"type":"start_timer","delay_seconds":0}])).await;
    assert_eq!(status, StatusCode::OK, "{result}");
    wait_for_fire(&app, namespace, &marked, 2).await;
    assert_eq!(poll(&app, legacy, "workflow").await, Value::Null);
    let wake = claim(&app, namespace).await;
    assert_eq!(wake["run_id"], marked["run_id"]);
    assert_eq!(finish(&app, namespace, &wake, json!([{
        "type":"complete_workflow","result":envelope(Payload::Boolean(false)),
    }])).await.0, StatusCode::OK);
    runtime.close().await;
    database.remove().await;
}

/// Several false wakes, each reopened after a delay (S→R latency), keep the
/// exact same A for the reopened wait's deadline and timer, while
/// `timeout_seconds`/`delay_seconds` keep the authored remaining budget.
#[tokio::test]
async fn false_wakes_keep_the_first_open_deadline_exactly() {
    for namespace in ["default", "deadline-scope"] {
        let (database, runtime, app) = setup(namespace).await;
        let (run, anchor) = open(&app, namespace, &format!("deadline-{namespace}"), "php:condition-wait:0", 60).await;
        for (index, remaining) in [(1, 59), (2, 58)] {
            let done = false_wake(&app, namespace, &run, "php:condition-wait:0", remaining, 60,
                std::time::Duration::from_millis(300)).await;
            assert_eq!(done.0, StatusCode::OK, "{}", done.1);
            let events = history(&app, namespace, &run).await;
            let opened = payloads(&events, "ConditionWaitOpened");
            let timers = payloads(&events, "TimerScheduled");
            assert_eq!(opened.len(), index + 1);
            assert_eq!(opened[index]["occurrence_deadline_at"], anchor, "the reopen records A");
            assert_eq!(opened[index]["timeout_seconds"], remaining, "the remaining budget is kept as authored");
            assert_eq!(timers[index]["fire_at"], anchor, "the reopened timer fires exactly at A");
            assert_eq!(timers[index]["delay_seconds"], remaining);
            assert_eq!(payloads(&events, "ConditionWaitSatisfied").len(), index);
        }
        // Physically: every timer of the chain carries the same fire_at.
        assert_eq!(task_retry::rows(&database,
            "SELECT COUNT(*) FROM workflow_run_timers a JOIN workflow_run_timers b ON b.workflow_run_id=a.workflow_run_id AND b.sequence>a.sequence WHERE a.fire_at=b.fire_at").await, 3);
        assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_run_timers WHERE status='pending'").await, 1);
        runtime.close().await;
        database.remove().await;
    }
}

/// A already passed when the reopen commits (the chain's own timer had not
/// fired): the reopened timer keeps fire_at = A, is due at once and fires;
/// no fresh positive budget is invented. Moving A into the past makes the
/// first wait's timer due, so a maintenance tick (every 250 ms) inside the
/// wake turn would fire it and the reopen would be refused, as the control
/// below pins (#1105). Debug builds hold that one timer, and the wake turn
/// starts only after a tick has found it due and left it.
#[cfg(debug_assertions)]
#[tokio::test]
async fn a_reopen_after_the_anchor_is_due_immediately_at_the_anchor() {
    use durable_workflow_server::runtime::timer_holds::hold_timer;
    let (database, runtime, app) = setup("default").await;
    let (run, _) = open(&app, "default", "deadline-expired", "php:condition-wait:0", 60).await;
    let first = payloads(&history(&app, "default", &run).await, "TimerScheduled")[0]["timer_id"]
        .as_str().unwrap().to_owned();
    let mut hold = hold_timer(&first);
    // Move A into the past on the first wait's durable timer only. The timer
    // is due from here on; the hold keeps it pending.
    database.execute("UPDATE workflow_run_timers SET fire_at=(SELECT started_at FROM workflow_runs WHERE workflow_runs.id=workflow_run_timers.workflow_run_id) WHERE sequence=1").await;
    tokio::time::timeout(std::time::Duration::from_secs(10), &mut hold.skipped).await
        .expect("a maintenance tick found the moved timer due").unwrap();
    let done = false_wake(&app, "default", &run, "php:condition-wait:0", 59, 60, std::time::Duration::ZERO).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    // The reopen's commit cancelled the first timer unfired, so releasing the
    // hold cannot fire it.
    drop(hold);
    assert_eq!(task_retry::rows(&database,
        "SELECT COUNT(*) FROM workflow_run_timers WHERE sequence=1 AND status='cancelled' AND fired_at IS NULL").await, 1);
    let events = history(&app, "default", &run).await;
    let opened = payloads(&events, "ConditionWaitOpened");
    let timers = payloads(&events, "TimerScheduled");
    let past = &opened[1]["occurrence_deadline_at"];
    assert!(instant(past) < chrono::Utc::now(), "A was moved into the past: {past}");
    assert_eq!(&timers[1]["fire_at"], past, "fire_at stays A, not a fresh budget");
    assert_eq!(timers[1]["delay_seconds"], 59);
    let fired = wait_for_fire(&app, "default", &run, 1).await;
    assert_eq!(fired[0]["timer_id"], timers[1]["timer_id"]);
    runtime.close().await;
    database.remove().await;
}

/// Real latency past A: the chain's timeout fires at A while the wake turn is
/// still running, and that worker's reopen is refused with nothing written.
#[tokio::test]
async fn a_wake_turn_finishing_after_its_fired_deadline_is_refused() {
    let (database, runtime, app) = setup("default").await;
    let (run, anchor) = open(&app, "default", "deadline-late", "php:condition-wait:0", 1).await;
    signal(&app, "default", &run).await;
    let task = claim(&app, "default").await;
    let before = wait_for_fire(&app, "default", &run, 1).await;
    assert!(instant(&before[0]["fired_at"]) >= instant(&anchor), "the deadline held at A");
    let events = history(&app, "default", &run).await;
    let done = finish(&app, "default", &task, json!([anchored("php:condition-wait:0", 0, 1)])).await;
    assert_eq!((done.0, done.1["reason"].clone()), (StatusCode::CONFLICT, json!("condition_wait_occurrence_timed_out")), "{}", done.1);
    assert_eq!(history(&app, "default", &run).await, events, "nothing written");
    runtime.close().await;
    database.remove().await;
}

/// A new authored call, a continued run, another run and another namespace
/// each start their own chain and deadline.
#[tokio::test]
async fn new_calls_continued_runs_and_other_scopes_get_fresh_deadlines() {
    let (database, runtime, app) = setup("default").await;
    let (run, first) = open(&app, "default", "deadline-calls", "php:condition-wait:0", 60).await;
    tokio::time::sleep(std::time::Duration::from_millis(1100)).await;
    // C4/C5 shape: the next authored call (a new occurrence) after a wake.
    signal(&app, "default", &run).await;
    let task = claim(&app, "default").await;
    let done = finish(&app, "default", &task, json!([anchored("php:condition-wait:1", 30, 30)])).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    let events = history(&app, "default", &run).await;
    let opened = payloads(&events, "ConditionWaitOpened");
    let timers = payloads(&events, "TimerScheduled");
    assert_eq!(opened[1]["occurrence_deadline_at"], timers[1]["fire_at"]);
    assert_ne!(timers[1]["fire_at"], first);
    assert_eq!(timers[1]["delay_seconds"], 30);
    // Continue-as-new: the new run's same occurrence ID is a fresh chain.
    signal(&app, "default", &run).await;
    let task = claim(&app, "default").await;
    assert_eq!(finish(&app, "default", &task, json!([{"type":"continue_as_new"}])).await.0, StatusCode::OK);
    let task = claim(&app, "default").await;
    assert_ne!(task["run_id"], run["run_id"]);
    let done = finish(&app, "default", &task, json!([anchored("php:condition-wait:0", 60, 60)])).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    let continued = json!({"workflow_id":run["workflow_id"],"run_id":task["run_id"]});
    let timers = payloads(&history(&app, "default", &continued).await, "TimerScheduled");
    assert_eq!(timers.len(), 1);
    assert!(instant(&timers[0]["fire_at"]) > instant(&first), "a continued run never inherits A");
    // Another run with the same occurrence ID, and a run in another
    // namespace with the same occurrence ID, each set their own A. (A
    // workflow ID is reserved across namespaces, so the tenant run has its
    // own ID; the occurrence ID is what is shared.)
    let (_, other) = open(&app, "default", "deadline-other-run", "php:condition-wait:0", 60).await;
    assert_ne!(other, first);
    namespaces::create(&app, "deadline-tenant").await;
    author(&app, "deadline-tenant", "deadline-worker").await;
    let (_, tenant) = open(&app, "deadline-tenant", "deadline-tenant-run", "php:condition-wait:0", 60).await;
    assert_ne!(tenant, first);
    runtime.close().await;
    database.remove().await;
}

/// Opted-in conflicts refuse with nothing written, and the attempt stays
/// usable; invalid shapes refuse 422; duplicates and stale owners keep their
/// receipts.
#[tokio::test]
async fn opted_in_conflicts_refuse_with_nothing_written() {
    let (database, runtime, app) = setup("default").await;
    let (run, anchor) = open(&app, "default", "deadline-refusals", "php:condition-wait:0", 60).await;
    signal(&app, "default", &run).await;
    let task = claim(&app, "default").await;
    let events = history(&app, "default", &run).await;
    let mut other_key = anchored("php:condition-wait:0", 59, 60);
    other_key["condition_key"] = json!("other");
    let mut other_fingerprint = anchored("php:condition-wait:0", 59, 60);
    other_fingerprint["condition_definition_fingerprint"] = json!(format!("sha256:{}", "b".repeat(64)));
    for (command, status, reason) in [
        (other_key, StatusCode::CONFLICT, "condition_wait_occurrence_mismatch"),
        (other_fingerprint, StatusCode::CONFLICT, "condition_wait_occurrence_mismatch"),
        (anchored("php:condition-wait:0", 59, 61), StatusCode::CONFLICT, "condition_wait_occurrence_mismatch"),
        (anchored("php:condition-wait:0", 61, 60), StatusCode::UNPROCESSABLE_ENTITY, "invalid_condition_deadline_policy"),
        (json!({"type":"open_condition_wait","condition_wait_occurrence_id":"php:condition-wait:0","timeout_seconds":5,
            "deadline_policy":"wall","condition_wait_timeout_budget_seconds":60}), StatusCode::UNPROCESSABLE_ENTITY, "invalid_condition_deadline_policy"),
        (json!({"type":"open_condition_wait","condition_wait_occurrence_id":"php:condition-wait:0","timeout_seconds":5,
            "condition_wait_timeout_budget_seconds":60}), StatusCode::UNPROCESSABLE_ENTITY, "invalid_condition_deadline_policy"),
        (json!({"type":"open_condition_wait","timeout_seconds":5,"deadline_policy":"occurrence",
            "condition_wait_timeout_budget_seconds":60}), StatusCode::UNPROCESSABLE_ENTITY, "invalid_condition_deadline_policy"),
    ] {
        let done = finish(&app, "default", &task, json!([command])).await;
        assert_eq!((done.0, done.1["reason"].clone()), (status, json!(reason)), "{}", done.1);
        assert_eq!(history(&app, "default", &run).await, events, "{reason}: nothing written");
    }
    // The same attempt is still usable for the valid reopen.
    let reopen = json!([anchored("php:condition-wait:0", 59, 60)]);
    assert_eq!(finish(&app, "default", &task, reopen.clone()).await.0, StatusCode::OK);
    let reopened = history(&app, "default", &run).await;
    assert_eq!(payloads(&reopened, "TimerScheduled")[1]["fire_at"], anchor);
    // An exact duplicate is the receipt replay, and a stale owner is fenced.
    let duplicate = finish(&app, "default", &task, reopen.clone()).await;
    assert_eq!((duplicate.0, duplicate.1["recorded"].clone()), (StatusCode::OK, json!(false)), "{}", duplicate.1);
    signal(&app, "default", &run).await;
    let next = claim(&app, "default").await;
    let mut stale = completion(&next, json!([anchored("php:condition-wait:0", 58, 60)]));
    stale["lease_owner"] = json!("someone-else");
    let response = request(&app, "POST", &format!("/api/worker/workflow-tasks/{}/complete", next["task_id"].as_str().unwrap()), stale).await;
    assert_eq!(response.0, StatusCode::CONFLICT, "{}", response.1);
    assert_eq!(history(&app, "default", &run).await.len(), reopened.len() + 1, "only the signal was added");
    // The first open of a chain must author its whole budget (A = O + T).
    let fresh = namespaces::start_scoped(&app, "default", "deadline-first-mismatch").await;
    let task = claim(&app, "default").await;
    let done = finish(&app, "default", &task, json!([anchored("php:condition-wait:0", 30, 60)])).await;
    assert_eq!((done.0, done.1["reason"].clone()), (StatusCode::CONFLICT, json!("condition_wait_occurrence_mismatch")), "{}", done.1);
    assert!(payloads(&history(&app, "default", &fresh).await, "ConditionWaitOpened").is_empty());
    runtime.close().await;
    database.remove().await;
}

/// A chain whose first open did not opt in has no provable anchor: opting in
/// on a reopen refuses rather than starting a fresh budget. A legacy reopen
/// keeps per-open behavior, and still-open idempotence is unchanged.
#[tokio::test]
async fn legacy_chains_keep_per_open_behavior_and_refuse_a_late_opt_in() {
    let (database, runtime, app) = setup("default").await;
    let run = namespaces::start_scoped(&app, "default", "deadline-legacy").await;
    let task = claim(&app, "default").await;
    let mut legacy = anchored("php:condition-wait:0", 60, 60);
    legacy.as_object_mut().unwrap().remove("deadline_policy");
    legacy.as_object_mut().unwrap().remove("condition_wait_timeout_budget_seconds");
    // Still-open idempotence within one batch, with or without the policy.
    let done = finish(&app, "default", &task, json!([legacy.clone(), legacy.clone()])).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    signal(&app, "default", &run).await;
    let task = claim(&app, "default").await;
    let events = history(&app, "default", &run).await;
    assert_eq!(payloads(&events, "ConditionWaitOpened").len(), 1);
    let done = finish(&app, "default", &task, json!([anchored("php:condition-wait:0", 59, 60)])).await;
    assert_eq!((done.0, done.1["reason"].clone()), (StatusCode::CONFLICT, json!("condition_wait_anchor_unavailable")), "{}", done.1);
    assert_eq!(history(&app, "default", &run).await, events, "nothing written");
    let mut legacy_reopen = legacy.clone();
    legacy_reopen["timeout_seconds"] = json!(45);
    assert_eq!(finish(&app, "default", &task, json!([legacy_reopen])).await.0, StatusCode::OK);
    let timers = payloads(&history(&app, "default", &run).await, "TimerScheduled");
    assert_eq!(timers[1]["delay_seconds"], 45);
    assert!(timers[1].get("deadline_policy").is_none() && timers[1].get("occurrence_deadline_at").is_none());
    assert_ne!(timers[1]["fire_at"], timers[0]["fire_at"], "legacy reopens keep per-open deadlines");
    runtime.close().await;
    database.remove().await;
}

/// The two #269 review paths. An opted-in repeat of a still-open wait is
/// checked before #1015 keeps that wait, and an anchored chain never takes a
/// legacy fresh budget when the policy is omitted. Each refusal writes
/// nothing and leaves the attempt usable; a valid duplicate stays idempotent.
#[tokio::test]
async fn anchored_chains_check_still_open_repeats_and_refuse_policy_omission() {
    let (database, runtime, app) = setup("default").await;
    let run = namespaces::start_scoped(&app, "default", "deadline-still-open").await;
    let task = claim(&app, "default").await;
    let valid = anchored("php:condition-wait:0", 60, 60);
    let without_policy = |remaining: i64| {
        let mut command = anchored("php:condition-wait:0", remaining, 60);
        command.as_object_mut().unwrap().remove("deadline_policy");
        command.as_object_mut().unwrap().remove("condition_wait_timeout_budget_seconds");
        command
    };
    let mut wrong_key = valid.clone();
    wrong_key["condition_key"] = json!("other");
    let mut wrong_fingerprint = valid.clone();
    wrong_fingerprint["condition_definition_fingerprint"] = json!(format!("sha256:{}", "b".repeat(64)));
    // Still open: the first command opens the chain, the second repeats the
    // same occurrence in the same transaction, so #1015 would keep it.
    for (repeat, why) in [
        (wrong_key, "key"),
        (wrong_fingerprint, "fingerprint"),
        (anchored("php:condition-wait:0", 60, 61), "budget"),
        (without_policy(60), "policy omitted"),
    ] {
        let done = finish(&app, "default", &task, json!([valid.clone(), repeat])).await;
        assert_eq!((done.0, done.1["reason"].clone()), (StatusCode::CONFLICT, json!("condition_wait_occurrence_mismatch")),
            "{why}: {}", done.1);
        assert!(payloads(&history(&app, "default", &run).await, "ConditionWaitOpened").is_empty(), "{why}: nothing written");
    }
    // The same attempt then commits; a valid duplicate keeps one wait.
    let done = finish(&app, "default", &task, json!([valid.clone(), valid.clone()])).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    let events = history(&app, "default", &run).await;
    assert_eq!((payloads(&events, "ConditionWaitOpened").len(), payloads(&events, "TimerScheduled").len()), (1, 1));
    let anchor = payloads(&events, "TimerScheduled")[0]["fire_at"].clone();
    // Resolved: after a false wake, omitting the policy is a mismatch, not
    // a fresh legacy budget; the attempt still completes the valid reopen.
    signal(&app, "default", &run).await;
    let task = claim(&app, "default").await;
    let before = history(&app, "default", &run).await;
    let done = finish(&app, "default", &task, json!([without_policy(59)])).await;
    assert_eq!((done.0, done.1["reason"].clone()), (StatusCode::CONFLICT, json!("condition_wait_occurrence_mismatch")), "{}", done.1);
    assert_eq!(history(&app, "default", &run).await, before, "nothing written");
    let done = finish(&app, "default", &task, json!([anchored("php:condition-wait:0", 59, 60)])).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    let timers = payloads(&history(&app, "default", &run).await, "TimerScheduled");
    assert_eq!(timers[1]["fire_at"], anchor);
    runtime.close().await;
    database.remove().await;
}
