//! Server #1181: a workflow task completion that asks for
//! `continuation_tail` returns the history after its sticky cache boundary,
//! read after its last write, and carries only an activity it created.
use super::*;

async fn tail_worker(app: &Router, worker: &str) {
    let entry = json!({"supported":true,"minimum_protocol_version":"1.18"});
    let (status, body) = request(app, "POST", "/api/worker/register", json!({"worker_id":worker,"task_queue":"test",
        "runtime":"php","build_id":"build-1","supported_workflow_types":["echo"],"supported_activity_types":["activity"],
        "capabilities":["sticky_execution","sticky_history_suffix"],
        "capability_manifest":{"local_activities":{"supported":false,"minimum_protocol_version":"1.18"},
            "worker_sessions":{"supported":false,"minimum_protocol_version":"1.18"},"sticky_execution":entry,
            "sticky_history_suffix":{"supported":true,"minimum_protocol_version":"1.18","version":1}}})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

fn schedule(count: usize, arguments: Payload) -> Value {
    let command = json!({"type":"schedule_activity","activity_type":"activity","arguments":envelope(arguments)});
    Value::Array(vec![command; count])
}

/// A sticky completion of `task` from its own boundary, carrying an
/// activity poll, that asks for the tail.
fn tail_body(task: &Value, commands: Value, poll_id: &str) -> Value {
    let mut body = completion(task, commands);
    body["sticky_cache"] = json!({"worker_id":task["lease_owner"],"workflow_id":task["workflow_id"],"run_id":task["run_id"],
        "build_id":"build-1","ttl_seconds":300,"history_last_sequence":task["history_last_sequence"]});
    body["activity_poll"] = json!({"worker_id":task["lease_owner"],"task_queue":"test","poll_request_id":poll_id,"timeout_seconds":0});
    body["continuation_tail"] = json!({"version":2});
    body
}

async fn complete(app: &Router, task: &Value, body: Value) -> (StatusCode, Value) {
    request(app, "POST", &format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap()), body).await
}

fn sequences(events: &Value) -> Vec<i64> {
    events.as_array().unwrap().iter().map(|event| event["sequence"].as_i64().unwrap()).collect()
}

/// The run's history as recorded.
async fn recorded_history(app: &Router, workflow: &str, task: &Value) -> Value {
    run_history(app, &json!(workflow), &task["run_id"]).await
}

/// Completes the leased activity, so the run's next workflow task is ready.
async fn finish_activity(app: &Router, activity: &Value) {
    let (status, body) = request(app, "POST", &format!("/api/worker/activity-tasks/{}/complete", activity["task_id"].as_str().unwrap()),
        json!({"lease_owner":activity["lease_owner"],"activity_attempt_id":activity["activity_attempt_id"],
            "result":envelope(Payload::Long(7))})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
}

/// A workflow run whose scheduled activity stays queued.
async fn queued_activity(app: &Router, worker: &str, workflow: &str) -> String {
    start(app, workflow).await;
    let task = poll(app, worker, "workflow").await;
    assert_eq!(task["workflow_id"], workflow, "{task}");
    let (status, body) = finish_task(app, &task, schedule(1, Payload::Array(vec![]))).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body["created_task_ids"][0].as_str().unwrap().to_owned()
}

#[tokio::test]
async fn the_tail_ends_in_the_carried_activity_and_the_next_suffix_follows_it() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    tail_worker(&app, "tail-a").await;
    start(&app, "tail-accepted").await;
    let task = poll(&app, "tail-a", "workflow").await;
    let through = task["history_last_sequence"].as_i64().unwrap();
    assert_eq!(sequences(&task["history_events"]).last(), Some(&through), "{task}");

    let (status, done) = complete(&app, &task, tail_body(&task, schedule(1, Payload::Array(vec![])), "tail-accepted-poll")).await;
    assert_eq!(status, StatusCode::OK, "{done}");
    assert_eq!(done["recorded"], true, "{done}");
    let leased = &done["activity_poll"]["answer"]["task"];
    assert_eq!(done["activity_poll"]["leased"], true, "{done}");
    assert_eq!(leased["task_id"], done["created_task_ids"][0], "{done}");
    let tail = &done["continuation_tail"];
    assert_eq!(tail.as_object().unwrap().len(), 4, "{tail}");
    assert_eq!(tail["version"], 2, "{tail}");
    assert_eq!(tail["after_sequence"], through, "{tail}");
    let delivered = sequences(&tail["history_events"]);
    let expected: Vec<i64> = (through + 1..=tail["last_sequence"].as_i64().unwrap()).collect();
    assert_eq!(delivered, expected, "contiguous after the boundary through the run's last event");
    let events = tail["history_events"].as_array().unwrap();
    let types: Vec<&str> = events.iter().map(|event| event["event_type"].as_str().unwrap()).collect();
    assert_eq!(types, ["ActivityScheduled", "ActivityStarted"], "{tail}");
    assert_eq!(events[1]["payload"]["activity_attempt_id"], leased["activity_attempt_id"], "{tail}");

    // The next sticky claim's suffix starts after the tail.
    finish_activity(&app, leased).await;
    let next = poll(&app, "tail-a", "workflow").await;
    assert_eq!(next["sticky_replay_mode"], "sticky_hit_expected", "{next}");
    assert_eq!(next["history_after_sequence"], tail["last_sequence"], "{next}");
    let suffix = sequences(&next["history_events"]);
    assert_eq!(suffix.first().copied(), Some(tail["last_sequence"].as_i64().unwrap() + 1), "{next}");
    assert_eq!(next["history_events"][0]["event_type"], "ActivityCompleted", "{next}");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn tail_events_are_presented_as_a_workflow_poll_presents_history() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    tail_worker(&app, "presented-a").await;
    tail_worker(&app, "presented-cold").await;
    start(&app, "tail-presented").await;
    let task = poll(&app, "presented-a", "workflow").await;
    let (status, done) = complete(&app, &task, tail_body(&task, schedule(1, Payload::Long(3)), "tail-presented-poll")).await;
    assert_eq!(status, StatusCode::OK, "{done}");
    finish_activity(&app, &done["activity_poll"]["answer"]["task"]).await;
    // Another worker's cold claim receives the run's whole history.
    let cold = poll(&app, "presented-cold", "workflow").await;
    assert_eq!(cold["history_after_sequence"], 0, "{cold}");
    let tail = &done["continuation_tail"];
    let (first, last) = (tail["after_sequence"].as_i64().unwrap(), tail["last_sequence"].as_i64().unwrap());
    let polled: Vec<&Value> = cold["history_events"].as_array().unwrap().iter()
        .filter(|event| (first + 1..=last).contains(&event["sequence"].as_i64().unwrap())).collect();
    let tailed: Vec<&Value> = tail["history_events"].as_array().unwrap().iter().collect();
    assert_eq!(tailed, polled);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_malformed_tail_request_is_refused_before_any_write() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    tail_worker(&app, "refused-a").await;
    start(&app, "tail-refused").await;
    let task = poll(&app, "refused-a", "workflow").await;
    let valid = tail_body(&task, schedule(1, Payload::Array(vec![])), "tail-refused-poll");
    let mut refused = Vec::new();
    for member in [json!(null), json!(2), json!("2"), json!([2]), json!({}), json!({"version":1}), json!({"version":"2"}),
        json!({"version":2.5}), json!({"version":2,"max_events":64})]
    {
        let mut body = valid.clone();
        body["continuation_tail"] = member;
        refused.push(body);
    }
    let mut no_claim = valid.clone();
    no_claim.as_object_mut().unwrap().remove("sticky_cache");
    refused.push(no_claim);
    let mut no_boundary = valid.clone();
    no_boundary["sticky_cache"].as_object_mut().unwrap().remove("history_last_sequence");
    refused.push(no_boundary);
    let mut null_boundary = valid.clone();
    null_boundary["sticky_cache"]["history_last_sequence"] = Value::Null;
    refused.push(null_boundary);
    // A boundary past the run's history has nothing to continue.
    let mut ahead = valid.clone();
    ahead["sticky_cache"]["history_last_sequence"] = json!(task["history_last_sequence"].as_i64().unwrap() + 1);
    refused.push(ahead);

    // Each case counts every table, which is slow on a loaded PostgreSQL or
    // MariaDB runner. Keep the worker's registration fresh so every case
    // reaches the tail check rather than the registration fence.
    let heartbeat = || async {
        let (status, beat) = request(&app, "POST", "/api/worker/heartbeat", json!({"worker_id":"refused-a"})).await;
        assert_eq!(status, StatusCode::OK, "{beat}");
    };
    heartbeat().await;
    let before = table_counts(&database).await;
    let history = sequences(&recorded_history(&app, "tail-refused", &task).await);
    for body in refused {
        heartbeat().await;
        let (status, answer) = complete(&app, &task, body.clone()).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}: {answer}");
        assert_eq!(answer["reason"], "invalid_continuation_tail", "{body}: {answer}");
        assert_eq!(table_counts(&database).await, before, "{body} wrote nothing");
    }
    assert_eq!(sequences(&recorded_history(&app, "tail-refused", &task).await), history);
    heartbeat().await;
    let (status, done) = complete(&app, &task, valid).await;
    assert_eq!(status, StatusCode::OK, "the task is still leased: {done}");
    assert_eq!(done["recorded"], true, "{done}");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn an_over_budget_tail_is_omitted_and_the_completion_still_commits() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    tail_worker(&app, "budget-a").await;
    // 65 scheduled activities are more events than a tail carries; one
    // activity whose arguments outweigh the byte budget is too many bytes.
    let cases = [
        ("tail-event-budget", schedule(65, Payload::Array(vec![])), "tail_event_budget_exceeded"),
        ("tail-byte-budget", schedule(1, Payload::String("x".repeat(300_000))), "tail_byte_budget_exceeded"),
    ];
    for (workflow, commands, reason) in cases {
        start(&app, workflow).await;
        let task = poll(&app, "budget-a", "workflow").await;
        let through = task["history_last_sequence"].as_i64().unwrap();
        let scheduled = commands.as_array().unwrap().len();
        let (status, done) = complete(&app, &task, tail_body(&task, commands, &format!("{workflow}-poll"))).await;
        assert_eq!(status, StatusCode::OK, "{done}");
        assert_eq!(done["recorded"], true, "{done}");
        assert_eq!(done["continuation_tail"], json!({"version":2,"after_sequence":through,"omitted":reason}), "{workflow}");
        assert_eq!(done["created_task_ids"].as_array().unwrap().len(), scheduled, "{workflow}");
        let leased = done["activity_poll"]["answer"]["task"].clone();
        assert_eq!(leased["task_id"], done["created_task_ids"][0], "{done}");
        let history = sequences(&recorded_history(&app, workflow, &task).await);
        assert!(history.len() as i64 > through + scheduled as i64, "the commands' events committed: {history:?}");

        // The sticky boundary stays at the claim's own.
        finish_activity(&app, &leased).await;
        let next = poll(&app, "budget-a", "workflow").await;
        assert_eq!(next["sticky_replay_mode"], "sticky_hit_expected", "{next}");
        assert_eq!(next["history_after_sequence"], through, "{workflow}: {next}");
        assert_eq!(sequences(&next["history_events"]).first(), Some(&(through + 1)), "{workflow}");
        let (status, body) = finish_task(&app, &next, json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await;
        assert_eq!(status, StatusCode::OK, "{body}");
    }
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_tail_request_carries_only_an_activity_its_completion_created() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    tail_worker(&app, "created-a").await;
    let queued = queued_activity(&app, "created-a", "tail-queued").await;

    // The older queued activity is not claimed; the created one is.
    start(&app, "tail-created").await;
    let task = poll(&app, "created-a", "workflow").await;
    assert_eq!(task["workflow_id"], "tail-created", "{task}");
    let (status, done) = complete(&app, &task, tail_body(&task, schedule(1, Payload::Array(vec![])), "tail-created-poll")).await;
    assert_eq!(status, StatusCode::OK, "{done}");
    let leased = done["activity_poll"]["answer"]["task"].clone();
    assert_eq!(leased["task_id"], done["created_task_ids"][0], "{done}");
    assert_ne!(leased["task_id"], json!(queued));
    finish_activity(&app, &leased).await;

    // A completion that created no activity leases nothing.
    let next = poll(&app, "created-a", "workflow").await;
    assert_eq!(next["workflow_id"], "tail-created", "{next}");
    let (status, done) = complete(&app, &next, tail_body(&next, json!([{"type":"start_timer","delay_seconds":3600}]), "tail-none-poll")).await;
    assert_eq!(status, StatusCode::OK, "{done}");
    assert_eq!(done["activity_poll"], json!({"leased":false}), "{done}");
    let tail = &done["continuation_tail"];
    assert_eq!(tail["after_sequence"], next["history_last_sequence"], "{tail}");
    assert_eq!(tail["history_events"].as_array().unwrap().last().unwrap()["event_type"], "TimerScheduled", "{tail}");

    // The queued activity is still there for an ordinary poll.
    let polled = poll(&app, "created-a", "activity").await;
    assert_eq!(polled["task_id"], json!(queued), "{polled}");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn an_exact_retry_answers_its_receipt_with_an_unavailable_tail() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    tail_worker(&app, "retry-a").await;
    start(&app, "tail-retry").await;
    let task = poll(&app, "retry-a", "workflow").await;
    let body = tail_body(&task, schedule(1, Payload::Array(vec![])), "tail-retry-poll");
    let (status, done) = complete(&app, &task, body.clone()).await;
    assert_eq!(status, StatusCode::OK, "{done}");
    assert!(done["continuation_tail"]["history_events"].is_array(), "{done}");

    let before = table_counts(&database).await;
    let (status, retry) = complete(&app, &task, body).await;
    assert_eq!(status, StatusCode::OK, "{retry}");
    assert_eq!(retry["recorded"], false, "{retry}");
    assert_eq!(retry["reason"], "already_completed", "{retry}");
    assert_eq!(retry["created_task_ids"], done["created_task_ids"], "{retry}");
    assert_eq!(retry["continuation_tail"],
        json!({"version":2,"after_sequence":task["history_last_sequence"],"omitted":"tail_unavailable"}), "{retry}");
    assert!(retry.get("activity_poll").is_none(), "{retry}");
    assert_eq!(table_counts(&database).await, before, "a retry claims nothing");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn without_the_member_the_completion_answers_as_before() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    tail_worker(&app, "plain-a").await;
    let queued = queued_activity(&app, "plain-a", "plain-queued").await;
    start(&app, "plain-completion").await;
    let task = poll(&app, "plain-a", "workflow").await;
    let through = task["history_last_sequence"].clone();
    let mut body = tail_body(&task, schedule(1, Payload::Array(vec![])), "plain-poll");
    body.as_object_mut().unwrap().remove("continuation_tail");
    let (status, done) = complete(&app, &task, body).await;
    assert_eq!(status, StatusCode::OK, "{done}");
    let keys: std::collections::BTreeSet<&str> = done.as_object().unwrap().keys().map(String::as_str).collect();
    assert_eq!(keys, ["activity_poll", "created_task_ids", "outcome", "protocol_version", "reason", "recorded", "run_id",
        "run_status", "server_capabilities", "task_id", "workflow_task_attempt"].into_iter().collect());
    // The carrier claims from the queue as before: the older activity.
    let leased = done["activity_poll"]["answer"]["task"].clone();
    assert_eq!(leased["task_id"], json!(queued), "{done}");
    finish_activity(&app, &leased).await;
    // That run resumes; its own completion has no sticky claim.
    let resumed = poll(&app, "plain-a", "workflow").await;
    assert_eq!(resumed["workflow_id"], "plain-queued", "{resumed}");
    let (status, body) = finish_task(&app, &resumed, json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    // The sticky boundary is the claim's own.
    let activity = poll(&app, "plain-a", "activity").await;
    assert_eq!(activity["task_id"], done["created_task_ids"][0], "{activity}");
    finish_activity(&app, &activity).await;
    let next = poll(&app, "plain-a", "workflow").await;
    assert_eq!(next["workflow_id"], "plain-completion", "{next}");
    assert_eq!(next["history_after_sequence"], through, "{next}");
    runtime.close().await;
    database.remove().await;
}
