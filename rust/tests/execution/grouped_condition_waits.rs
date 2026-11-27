//! Server #1142: a condition wait is an all() member, as Core's grouped
//! condition wait. The opening, its timeout timer and its satisfaction carry
//! the group; the member closes when satisfied or timed out; the barrier
//! wakes the workflow only when every member has closed. A false signal wake
//! replaces the physical wait at its original authored group position.
use super::*;

fn group(id: &str, kind: &str, base: i64, size: i64, index: i64) -> Value {
    json!({"parallel_group_id":id,"parallel_group_kind":kind,"parallel_group_base_sequence":base,
        "parallel_group_size":size,"parallel_group_index":index})
}

/// A member command: its group fields at the top level plus a one-group path.
fn member(mut command: Value, entry: Value) -> Value {
    command.as_object_mut().unwrap().extend(entry.as_object().unwrap().clone());
    command["parallel_group_path"] = json!([entry]);
    command
}

fn events<'a>(history: &'a Value, kind: &str) -> Vec<&'a Value> {
    history.as_array().unwrap().iter().filter(|event| event["event_type"] == kind).collect()
}

fn group_of(payload: &Value) -> Value {
    json!({"parallel_group_id":payload["parallel_group_id"],"parallel_group_kind":payload["parallel_group_kind"],
        "parallel_group_base_sequence":payload["parallel_group_base_sequence"],
        "parallel_group_size":payload["parallel_group_size"],"parallel_group_index":payload["parallel_group_index"]})
}

async fn wait_for(app: &Router, started: &Value, done: impl Fn(&Value) -> bool) -> Value {
    tokio::time::timeout(std::time::Duration::from_secs(15), async {
        loop {
            let history = run_history(app, &started["workflow_id"], &started["run_id"]).await;
            if done(&history) {
                return history;
            }
            tokio::time::sleep(std::time::Duration::from_millis(50)).await;
        }
    })
    .await
    .expect("history reaches the expected state")
}

#[tokio::test]
async fn only_replay_capable_workers_receive_grouped_condition_support() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    for (worker, capabilities, supported) in [
        ("older-worker", json!([]), Value::Null),
        ("replay-worker", json!(["grouped_condition_wait_replay"]), json!({"supported":true,"version":1})),
    ] {
        let (status, response) = request(&app, "POST", "/api/worker/register", json!({
            "worker_id":worker,"runtime":"php","task_queue":"default","supported_workflow_types":["echo"],"capabilities":capabilities
        })).await;
        assert_eq!(status, StatusCode::CREATED, "{}", response["reason"]);
        assert_eq!(response["server_capabilities"]["grouped_condition_waits"], supported);
    }
    let (status, info) = request(&app, "GET", "/api/cluster/info", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{info}");
    assert!(info["worker_protocol"]["server_capabilities"]["grouped_condition_waits"].is_null(), "{info}");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_mixed_group_records_the_condition_member_and_waits_for_it() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "grouped", json!(["echo"]), json!([])).await;
    let started = start(&app, "grouped-mixed").await;
    let task = poll(&app, "grouped", "workflow").await;
    let condition = member(json!({"type":"open_condition_wait","condition_key":"approval",
        "condition_wait_occurrence_id":"php:condition-wait:0","timeout_seconds":600}),
        group("parallel-calls:1:2", "mixed", 1, 2, 0));
    let timer = member(json!({"type":"start_timer","delay_seconds":1}), group("parallel-calls:1:2", "mixed", 1, 2, 1));
    let (status, body) = finish_task(&app, &task, json!([condition, timer])).await;
    assert_eq!(status, StatusCode::OK, "{body}");

    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let opened = &events(&history, "ConditionWaitOpened")[0]["payload"];
    assert_eq!(group_of(opened), group("parallel-calls:1:2", "mixed", 1, 2, 0), "{opened}");
    assert_eq!(opened["parallel_group_path"], json!([group("parallel-calls:1:2", "mixed", 1, 2, 0)]), "{opened}");
    assert_eq!(opened["sequence"], 1, "{opened}");
    let scheduled = events(&history, "TimerScheduled");
    let timeout = scheduled.iter().find(|event| event["payload"]["timer_kind"] == "condition_timeout").unwrap();
    assert_eq!(group_of(&timeout["payload"]), group_of(opened), "the timeout carries its condition's group: {timeout}");
    let plain = scheduled.iter().find(|event| event["payload"].get("timer_kind").is_none()).unwrap();
    assert_eq!(group_of(&plain["payload"]), group("parallel-calls:1:2", "mixed", 1, 2, 1), "{plain}");

    // The timer member fires; the open condition member holds the barrier.
    wait_for(&app, &started, |history| events(history, "TimerFired").iter()
        .any(|event| event["payload"].get("timer_kind").is_none())).await;
    assert!(poll(&app, "grouped", "workflow").await.is_null(), "no wake while the condition member is open");

    // A signal wakes the condition, which the next completion records as
    // satisfied with its group.
    let (status, body) = request(&app, "POST", "/api/workflows/grouped-mixed/signal/approve", json!([])).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    let resumed = poll(&app, "grouped", "workflow").await;
    assert!(resumed["task_id"].is_string(), "{resumed}");
    let (status, body) = finish_task(&app, &resumed, json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let satisfied = &events(&history, "ConditionWaitSatisfied")[0]["payload"];
    assert_eq!(group_of(satisfied), group_of(opened), "{satisfied}");
}

#[tokio::test]
async fn a_condition_group_wakes_only_after_every_member_times_out() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "grouped", json!(["echo"]), json!([])).await;
    let started = start(&app, "grouped-conditions").await;
    let task = poll(&app, "grouped", "workflow").await;
    let first = member(json!({"type":"open_condition_wait","condition_key":"a","timeout_seconds":1}),
        group("parallel-calls:1:2", "condition", 1, 2, 0));
    let second = member(json!({"type":"open_condition_wait","condition_key":"b","timeout_seconds":3}),
        group("parallel-calls:1:2", "condition", 1, 2, 1));
    let (status, body) = finish_task(&app, &task, json!([first, second])).await;
    assert_eq!(status, StatusCode::OK, "{body}");

    let fired = |history: &Value| events(history, "TimerFired").len();
    wait_for(&app, &started, |history| fired(history) == 1).await;
    assert!(poll(&app, "grouped", "workflow").await.is_null(), "one member is still open");
    let history = wait_for(&app, &started, |history| fired(history) == 2).await;
    for event in events(&history, "TimerFired") {
        assert_eq!(event["payload"]["parallel_group_kind"], "condition", "{event}");
    }
    let resumed = poll(&app, "grouped", "workflow").await;
    assert!(resumed["task_id"].is_string(), "the group wakes once every member closed: {resumed}");
}

#[tokio::test]
async fn unqualified_grouped_condition_shapes_are_refused_unwritten() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "grouped", json!(["echo"]), json!([])).await;
    start(&app, "grouped-refusals").await;
    let task = poll(&app, "grouped", "workflow").await;
    let condition = |entry: Value| member(json!({"type":"open_condition_wait","condition_key":"x",
        "condition_wait_occurrence_id":"php:condition-wait:0","timeout_seconds":600}), entry);
    let timer = member(json!({"type":"start_timer","delay_seconds":600}), group("parallel-calls:1:2", "mixed", 1, 2, 1));
    let before = table_counts(&database).await;
    for (refused, reason) in [
        // A condition member's group id must be `parallel-calls`.
        (json!([condition(group("parallel-timers:1:2", "mixed", 1, 2, 0)), timer.clone()]), "validation_failed"),
        // Every member of the group must arrive in the batch.
        (json!([condition(group("parallel-calls:1:2", "mixed", 1, 2, 0))]), "development_parallel_group_unqualified"),
    ] {
        let (status, body) = finish_task(&app, &task, refused).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
        assert_eq!(body["reason"], reason, "{body}");
    }
    assert_eq!(table_counts(&database).await, before, "a refusal writes nothing");

}

#[tokio::test]
async fn a_false_wake_reopens_only_the_original_group_member() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "grouped", json!(["echo"]), json!([])).await;
    let started = start(&app, "grouped-reopen").await;
    let task = poll(&app, "grouped", "workflow").await;
    let slot = group("parallel-calls:1:2", "mixed", 1, 2, 0);
    let occurrence = "php:condition-wait:0";
    let condition = member(json!({"type":"open_condition_wait","condition_key":"approval",
        "condition_definition_fingerprint":"approval-v1","condition_wait_occurrence_id":occurrence,
        "timeout_seconds":600}), slot.clone());
    let timer = member(json!({"type":"start_timer","delay_seconds":5}), group("parallel-calls:1:2", "mixed", 1, 2, 1));
    let (status, body) = finish_task(&app, &task, json!([condition.clone(), timer])).await;
    assert_eq!(status, StatusCode::OK, "{body}");

    let (status, body) = request(&app, "POST", "/api/workflows/grouped-reopen/signal/false-wake", json!([])).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    let resumed = poll(&app, "grouped", "workflow").await;
    let mut different = condition.clone();
    different["condition_key"] = json!("different");
    let before = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let (status, body) = finish_task(&app, &resumed, json!([different])).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    let refused = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    for kind in ["ConditionWaitOpened", "ConditionWaitSatisfied", "ConditionWaitCancelled"] {
        assert_eq!(events(&refused, kind), events(&before, kind), "the refused completion changed {kind}");
    }

    let (status, body) = finish_task(&app, &resumed, json!([condition])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let opened = events(&history, "ConditionWaitOpened");
    assert_eq!(opened.len(), 2, "{history}");
    let original = &opened[0]["payload"];
    let reopened = &opened[1]["payload"];
    assert_ne!(original["condition_wait_id"], reopened["condition_wait_id"]);
    assert_eq!(reopened["condition_wait_occurrence_id"], occurrence);
    assert!(reopened["sequence"].as_i64().unwrap() > original["sequence"].as_i64().unwrap());
    assert_eq!(group_of(reopened), slot);
    assert_eq!(reopened["parallel_group_path"], original["parallel_group_path"]);
    assert!(events(&history, "TimerFired").is_empty(), "observe the sibling firing after the wait reopens");

    wait_for(&app, &started, |history| events(history, "TimerFired").iter()
        .any(|event| event["payload"].get("timer_kind").is_none())).await;
    assert!(poll(&app, "grouped", "workflow").await.is_null(), "the still-open replacement holds the barrier");
    let (status, body) = request(&app, "POST", "/api/workflows/grouped-reopen/signal/approve", json!([])).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    let completion = poll(&app, "grouped", "workflow").await;
    let (status, body) = finish_task(&app, &completion, json!([{"type":"complete_workflow","result":envelope(Payload::Boolean(true))}])).await;
    assert_eq!((status, &body["run_status"]), (StatusCode::OK, &json!("completed")), "{body}");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_group_signal_settles_the_approved_member_without_leaving_its_timer_open() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "grouped", json!(["echo"]), json!([])).await;
    let started = start(&app, "grouped-approvals").await;
    let task = poll(&app, "grouped", "workflow").await;
    let condition = |key: &str, index| member(json!({"type":"open_condition_wait","condition_key":key,
        "condition_wait_occurrence_id":format!("php:condition-wait:{index}"), "timeout_seconds":600}),
        group("parallel-calls:1:3", "mixed", 1, 3, index));
    let right = condition("right", 1);
    let timer = member(json!({"type":"start_timer","delay_seconds":600}), group("parallel-calls:1:3", "mixed", 1, 3, 2));
    let (status, body) = finish_task(&app, &task, json!([condition("left", 0), right.clone(), timer])).await;
    assert_eq!(status, StatusCode::OK, "{body}");

    let (status, body) = request(&app, "POST", "/api/workflows/grouped-approvals/signal/approve-left", json!([])).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    let turn = poll(&app, "grouped", "workflow").await;
    let (status, body) = finish_task(&app, &turn, json!([right])).await;
    assert_eq!(status, StatusCode::OK, "{body}");

    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let opens = events(&history, "ConditionWaitOpened");
    assert_eq!(opens.len(), 3);
    assert_eq!(opens[2]["payload"]["condition_key"], "right");
    assert_ne!(opens[1]["payload"]["condition_wait_id"], opens[2]["payload"]["condition_wait_id"]);
    assert_eq!(opens[1]["payload"]["parallel_group_path"], opens[2]["payload"]["parallel_group_path"]);
    let settled = events(&history, "ConditionWaitSatisfied");
    assert_eq!(settled.len(), 2);
    assert_eq!(settled[0]["payload"]["condition_wait_id"], opens[0]["payload"]["condition_wait_id"]);
    let cancelled = events(&history, "TimerCancelled");
    assert_eq!(cancelled.iter().filter(|event| event["payload"]["timer_kind"] == "condition_timeout").count(), 2);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_receipt_ordered_condition_group_wakes_when_its_timeout_timers_fire() {
    for policy in [None, Some("propagate"), Some("settle")] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap().with_ordered_condition_wait_authoring(true);
        let app = router(runtime.clone());
        let mut reg = json!({
            "worker_id":"grouped-ordered",
            "runtime":"php",
            "task_queue":"test",
            "supported_workflow_types":["echo"],
            "capabilities":["grouped_condition_wait_replay", "condition_wait_input_order"]
        });
        if policy.is_some() {
            reg["capabilities"].as_array_mut().unwrap().push(json!("parallel_group_policies"));
            reg["capability_manifest"] = json!({
                "local_activities":{"supported":false,"minimum_protocol_version":"1.18"},
                "worker_sessions":{"supported":false,"minimum_protocol_version":"1.18"},
                "sticky_execution":{"supported":false,"minimum_protocol_version":"1.18"},
                "parallel_group_policies":{"supported":true,"version":1,"policies":["propagate","settle"]}
            });
        }
        let (status, body) = request_protocol(&app, "POST", "/api/worker/register", reg, "1.20").await;
        assert_eq!(status, StatusCode::CREATED, "{body}");
        let started = start(&app, &format!("grouped-ordered-{}", policy.unwrap_or("legacy"))).await;
        let task = poll(&app, "grouped-ordered", "workflow").await;
        let make_entry = |index: i64| {
            let mut entry = group("parallel-calls:1:2", "condition", 1, 2, index);
            if let Some(p) = policy {
                entry["parallel_group_policy"] = json!(p);
            }
            entry
        };
        let first = member(json!({
            "type":"open_condition_wait",
            "condition_key":"a",
            "condition_wait_occurrence_id":"php:condition-wait:0",
            "deadline_policy":"occurrence",
            "condition_wait_timeout_budget_seconds":1,
            "timeout_seconds":1,
            "condition_wait_input_order":"durable_receipt_order"
        }), make_entry(0));
        let second = member(json!({
            "type":"open_condition_wait",
            "condition_key":"b",
            "condition_wait_occurrence_id":"php:condition-wait:1",
            "deadline_policy":"occurrence",
            "condition_wait_timeout_budget_seconds":3,
            "timeout_seconds":3,
            "condition_wait_input_order":"durable_receipt_order"
        }), make_entry(1));
        let (status, body) = finish_task(&app, &task, json!([first, second])).await;
        assert_eq!(status, StatusCode::OK, "{body}");

        let fired = |history: &Value| events(history, "TimerFired").len();
        // First timeout fires while the sibling is still pending: the workflow must wake so the worker can resolve it.
        let history_after_first = wait_for(&app, &started, |history| fired(history) == 1).await;
        let opens = events(&history_after_first, "ConditionWaitOpened");
        let first_wake = poll(&app, "grouped-ordered", "workflow").await;
        assert!(first_wake["task_id"].is_string(), "policy={policy:?}: expected wake after first receipt-ordered timeout; resumed={first_wake}");
        let (status, body) = finish_task(&app, &first_wake, json!([
            {"type":"resolve_condition_wait","condition_wait_id":opens[0]["payload"]["condition_wait_id"],"satisfied":false}
        ])).await;
        assert_eq!(status, StatusCode::OK, "policy={policy:?}: {body}");

        // Second timeout fires: the workflow wakes again, resolves it, and completes.
        wait_for(&app, &started, |history| fired(history) == 2).await;
        let second_wake = poll(&app, "grouped-ordered", "workflow").await;
        assert!(second_wake["task_id"].is_string(), "policy={policy:?}: expected wake after second receipt-ordered timeout; resumed={second_wake}");
        let (status, body) = finish_task(&app, &second_wake, json!([
            {"type":"resolve_condition_wait","condition_wait_id":opens[1]["payload"]["condition_wait_id"],"satisfied":false},
            {"type":"complete_workflow","result":envelope(Payload::String("timed-out".into()))}
        ])).await;
        assert_eq!(status, StatusCode::OK, "policy={policy:?}: {body}");
        let final_history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
        assert_eq!(events(&final_history, "ConditionWaitTimedOut").len(), 2, "policy={policy:?}: {final_history}");
        runtime.close().await;
        database.remove().await;
    }
}
