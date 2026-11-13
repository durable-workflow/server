//! Server #1142: a condition wait is an all() member, as Core's grouped
//! condition wait. The opening, its timeout timer and its satisfaction carry
//! the group; the member closes when satisfied or timed out; the barrier
//! wakes the workflow only when every member has closed. A grouped physical
//! reopen is not qualified yet and is refused.
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
    let started = start(&app, "grouped-refusals").await;
    let task = poll(&app, "grouped", "workflow").await;
    let condition = |entry: Value| member(json!({"type":"open_condition_wait","condition_key":"x",
        "condition_wait_occurrence_id":"php:condition-wait:0","timeout_seconds":600}), entry);
    let timer = member(json!({"type":"start_timer","delay_seconds":600}), group("parallel-calls:1:2", "mixed", 1, 2, 1));
    let before = table_counts(&database).await;
    for refused in [
        // A condition member's group id must be `parallel-calls`.
        json!([condition(group("parallel-timers:1:2", "mixed", 1, 2, 0)), timer.clone()]),
        // Every member of the group must arrive in the batch.
        json!([condition(group("parallel-calls:1:2", "mixed", 1, 2, 0))]),
    ] {
        let (status, body) = finish_task(&app, &task, refused).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
        assert_eq!(body["reason"], "development_parallel_group_unqualified", "{body}");
    }
    assert_eq!(table_counts(&database).await, before, "a refusal writes nothing");

    // A grouped physical reopen of the occurrence is refused for now.
    let (status, body) = finish_task(&app, &task, json!([condition(group("parallel-calls:1:2", "mixed", 1, 2, 0)), timer])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (status, body) = request(&app, "POST", "/api/workflows/grouped-refusals/signal/wake", json!([])).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    let resumed = poll(&app, "grouped", "workflow").await;
    let before = table_counts(&database).await;
    let (status, body) = finish_task(&app, &resumed, json!([condition(group("parallel-calls:1:2", "mixed", 1, 2, 0))])).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    assert_eq!(body["reason"], "development_parallel_group_unqualified", "{body}");
    assert_eq!(table_counts(&database).await, before, "the refused reopen writes nothing");
    let _ = started;
}
