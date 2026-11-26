//! Server #1181: activity workflow continuation v2. The worker speculates
//! from its sticky cache; one write transaction proves the prefix in O(1),
//! records the result, completes the resumed workflow task, carries the next
//! activity and returns the history tail.
use super::*;

/// Each worker polls its own task queue, so one test's cases never meet.
fn registration(worker: &str) -> Value {
    let entry = json!({"supported":true,"minimum_protocol_version":"1.18"});
    json!({"worker_id":worker,"task_queue":worker,"runtime":"php","build_id":"build-1",
        "supported_workflow_types":["echo"],"supported_activity_types":["activity"],
        "capabilities":["sticky_execution","sticky_history_suffix"],
        "capability_manifest":{"local_activities":{"supported":false,"minimum_protocol_version":"1.18"},
            "worker_sessions":{"supported":false,"minimum_protocol_version":"1.18"},"sticky_execution":entry,
            "sticky_history_suffix":{"supported":true,"minimum_protocol_version":"1.18","version":1}}})
}

async fn v2_worker(app: &Router, body: Value) {
    let (status, registered) = request(app, "POST", "/api/worker/register", body).await;
    assert_eq!(status, StatusCode::CREATED, "{registered}");
}

/// Keeps the worker's registration inside its 30 second freshness window.
/// Only registration and heartbeats refresh it, and a slow backend's table
/// counts and history reads between requests can outlast it. A stale owner
/// would be refused, or its carried polls not admitted, before the
/// behaviour under test.
async fn heartbeat(app: &Router, worker: &Value) {
    let (status, beat) = request(app, "POST", "/api/worker/heartbeat", json!({"worker_id":worker})).await;
    assert_eq!(status, StatusCode::OK, "{beat}");
}

fn schedule() -> Value {
    json!([{"type":"schedule_activity","activity_type":"activity","arguments":envelope(Payload::Array(vec![]))}])
}

fn timer() -> Value {
    json!([{"type":"start_timer","delay_seconds":3600}])
}

fn complete_workflow() -> Value {
    json!([{"type":"complete_workflow","result":envelope(Payload::Long(7))}])
}

fn sticky(task: &Value, through: &Value) -> Value {
    json!({"worker_id":task["lease_owner"],"workflow_id":task["workflow_id"],"run_id":task["run_id"],
        "build_id":"build-1","ttl_seconds":300,"history_last_sequence":through})
}

fn poll_body(worker: &Value, id: &str) -> Value {
    json!({"worker_id":worker,"task_queue":worker,"poll_request_id":id,"timeout_seconds":0})
}

async fn start_on(app: &Router, workflow: &str, queue: &str) {
    let (status, body) = request(app, "POST", "/api/workflows", json!({"workflow_id":workflow,"workflow_type":"echo",
        "task_queue":queue,"input":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

async fn poll_on(app: &Router, worker: &str, kind: &str) -> Value {
    let (status, body) = request(app, "POST", &format!("/api/worker/{kind}-tasks/poll"),
        json!({"worker_id":worker,"task_queue":worker,"timeout_seconds":0})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body["task"].clone()
}

/// A leased activity and the cache tail that ends in its `ActivityStarted`.
struct Seed {
    workflow: String,
    activity: Value,
    tail: Value,
}

/// Starts `workflow` and completes its first workflow task with `commands`,
/// asking for a tail and carrying the activity it schedules.
async fn seed(app: &Router, worker: &str, workflow: &str, commands: Value) -> Seed {
    start_on(app, workflow, worker).await;
    heartbeat(app, &json!(worker)).await;
    let task = poll_on(app, worker, "workflow").await;
    assert_eq!(task["workflow_id"], workflow, "{task}");
    let mut body = completion(&task, commands);
    body["sticky_cache"] = sticky(&task, &task["history_last_sequence"]);
    body["activity_poll"] = poll_body(&task["lease_owner"], &format!("{workflow}-seed"));
    body["continuation_tail"] = json!({"version":2});
    let (status, done) = request(app, "POST", &format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap()), body).await;
    assert_eq!(status, StatusCode::OK, "{done}");
    assert_eq!(done["activity_poll"]["leased"], true, "{done}");
    Seed { workflow: workflow.to_owned(), activity: done["activity_poll"]["answer"]["task"].clone(), tail: done["continuation_tail"].clone() }
}

fn last_event_id(tail: &Value) -> Value {
    tail["history_events"].as_array().unwrap().last().unwrap()["id"].clone()
}

/// A v2 continuation from the seed's cache, carrying both polls.
fn v2_body(seed: &Seed, commands: Value, poll: &str) -> Value {
    let activity = &seed.activity;
    let through = &seed.tail["last_sequence"];
    json!({"lease_owner":activity["lease_owner"],"activity_attempt_id":activity["activity_attempt_id"],"result":envelope(Payload::Long(7)),
        "workflow_poll":poll_body(&activity["lease_owner"], &format!("{poll}-workflow")),
        "activity_poll":poll_body(&activity["lease_owner"], &format!("{poll}-activity")),
        "continuation":{"version":2,"history_through_sequence":through,"history_last_event_id":last_event_id(&seed.tail),
            "sticky_cache":sticky(activity, through),"commands":commands}})
}

async fn continue_v2(app: &Router, seed: &Seed, body: Value) -> (StatusCode, Value) {
    heartbeat(app, &seed.activity["lease_owner"]).await;
    request(app, "POST", &format!("/api/worker/activity-tasks/{}/continuation/complete", seed.activity["task_id"].as_str().unwrap()), body).await
}

async fn history(app: &Router, seed: &Seed) -> Vec<Value> {
    run_history(app, &json!(seed.workflow), &seed.activity["run_id"]).await.as_array().unwrap().clone()
}

async fn event_types(app: &Router, seed: &Seed) -> Vec<String> {
    history(app, seed).await.iter().map(|event| event["event_type"].as_str().unwrap().to_owned()).collect()
}

fn sequences(events: &Value) -> Vec<i64> {
    events.as_array().unwrap().iter().map(|event| event["sequence"].as_i64().unwrap()).collect()
}

fn tail_types(tail: &Value) -> Vec<&str> {
    tail["history_events"].as_array().unwrap().iter().map(|event| event["event_type"].as_str().unwrap()).collect()
}

/// The tail runs exactly from the boundary through the run's last event.
fn assert_contiguous(tail: &Value, through: &Value) {
    assert_eq!(tail["version"], 2, "{tail}");
    assert_eq!(tail["after_sequence"], *through, "{tail}");
    let expected: Vec<i64> = (through.as_i64().unwrap() + 1..=tail["last_sequence"].as_i64().unwrap()).collect();
    assert_eq!(sequences(&tail["history_events"]), expected, "{tail}");
}

/// A decline keeps exactly the ordinary completion: the result, no command
/// events, no tail and no activity. An exact retry answers the stored reason.
async fn assert_declined(app: &Router, seed: &Seed, body: Value, reason: &str) -> Value {
    let before = event_types(app, seed).await;
    let (status, done) = continue_v2(app, seed, body.clone()).await;
    assert_eq!(status, StatusCode::OK, "{reason}: {done}");
    assert_eq!(done["recorded"], true, "{reason}: {done}");
    assert_eq!(done["continuation"], json!({"version":2,"outcome":"declined","reason":reason}), "{done}");
    assert!(done.get("continuation_tail").is_none() && done.get("activity_poll").is_none(), "{reason}: {done}");
    let after = event_types(app, seed).await;
    assert_eq!(&after[..before.len()], &before[..], "{reason}");
    let added = &after[before.len()..];
    assert_eq!(added.iter().filter(|kind| *kind == "ActivityCompleted").count(), 1, "{reason}: {added:?}");
    assert!(!added.iter().any(|kind| kind == "TimerScheduled"), "{reason}: no speculative command: {added:?}");
    let (status, retry) = continue_v2(app, seed, body).await;
    assert_eq!(status, StatusCode::OK, "{reason}: {retry}");
    assert_eq!(retry["recorded"], false, "{retry}");
    assert_eq!(retry["reason"], "already_completed", "{retry}");
    assert_eq!(retry["continuation"], done["continuation"], "the stored reason");
    done
}

#[tokio::test]
async fn an_accepted_continuation_returns_its_tail_and_the_next_one_continues_from_it() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    v2_worker(&app, registration("v2-a")).await;
    let first = seed(&app, "v2-a", "v2-accepted", schedule()).await;
    let through = first.tail["last_sequence"].clone();
    let (status, done) = continue_v2(&app, &first, v2_body(&first, schedule(), "v2-accepted-1")).await;
    assert_eq!(status, StatusCode::OK, "{done}");
    let keys: std::collections::BTreeSet<&str> = done.as_object().unwrap().keys().map(String::as_str).collect();
    assert_eq!(keys, ["activity_attempt_id", "activity_poll", "continuation", "continuation_tail", "next_task_id", "outcome",
        "protocol_version", "reason", "recorded", "server_capabilities", "task_id"].into_iter().collect());
    assert_eq!(done["recorded"], true, "{done}");
    // The v1 descriptor is unchanged for released SDKs; v2 is its own member.
    let commands = json!(["schedule_activity", "start_timer", "complete_workflow", "record_version_marker"]);
    assert_eq!(done["server_capabilities"]["activity_workflow_continuation"], json!({"version":1,
        "history_endpoint":"/worker/activity-tasks/{taskId}/continuation/history",
        "complete_endpoint":"/worker/activity-tasks/{taskId}/continuation/complete",
        "supported_commands":commands,"max_history_events":256,"max_commands":16,"supported_versions":[1,2],
        "v2":{"version":2,"complete_endpoint":"/worker/activity-tasks/{taskId}/continuation/complete",
            "supported_commands":commands,"max_commands":16,
            "requires_worker_capabilities":["sticky_execution","sticky_history_suffix"],
            "tail_max_events":64,"tail_max_bytes":262144}}));
    let decision = &done["continuation"];
    assert_eq!(decision["version"], 2, "{done}");
    assert_eq!(decision["outcome"], "accepted", "{done}");
    assert_eq!(decision["workflow_task_id"], done["next_task_id"], "{done}");
    assert_eq!(decision["completion"]["recorded"], true, "{done}");
    assert_eq!(decision["completion"]["run_status"], "waiting", "{done}");
    let leased = &done["activity_poll"]["answer"]["task"];
    assert_eq!(leased["task_id"], decision["completion"]["created_task_ids"][0], "{done}");
    let tail = &done["continuation_tail"];
    assert_contiguous(tail, &through);
    assert_eq!(tail_types(tail), ["ActivityCompleted", "ActivityScheduled", "ActivityStarted"], "{tail}");
    let events = tail["history_events"].as_array().unwrap();
    assert_eq!(events[0]["payload"]["activity_attempt_id"], first.activity["activity_attempt_id"], "{tail}");
    assert_eq!(events[2]["payload"]["activity_attempt_id"], leased["activity_attempt_id"], "{tail}");

    // The next continuation is speculated from the tail alone.
    let second = Seed { workflow: first.workflow.clone(), activity: leased.clone(), tail: tail.clone() };
    let (status, closed) = continue_v2(&app, &second, v2_body(&second, complete_workflow(), "v2-accepted-2")).await;
    assert_eq!(status, StatusCode::OK, "{closed}");
    assert_eq!(closed["continuation"]["outcome"], "accepted", "{closed}");
    assert_eq!(closed["continuation"]["completion"]["run_status"], "completed", "{closed}");
    assert_contiguous(&closed["continuation_tail"], &tail["last_sequence"]);
    assert_eq!(tail_types(&closed["continuation_tail"]), ["ActivityCompleted", "WorkflowCompleted"]);
    assert_eq!(closed["activity_poll"], json!({"leased":false}), "nothing was created to carry");
    let types = event_types(&app, &first).await;
    assert_eq!(types.iter().filter(|kind| *kind == "ActivityCompleted").count(), 2, "{types:?}");
    assert_eq!(types.iter().filter(|kind| *kind == "WorkflowCompleted").count(), 1, "{types:?}");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn malformed_v2_requests_are_refused_before_any_write() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    v2_worker(&app, registration("v2-malformed")).await;
    let seeded = seed(&app, "v2-malformed", "v2-malformed", schedule()).await;
    let valid = v2_body(&seeded, timer(), "v2-malformed");
    let mut cases = Vec::new();
    let mut unknown = valid.clone();
    unknown["continuation"]["max_events"] = json!(64);
    cases.push((unknown, "invalid_continuation"));
    let mut boundary = valid.clone();
    boundary["continuation"]["sticky_cache"]["history_last_sequence"] = json!(1);
    cases.push((boundary, "invalid_continuation"));
    let mut fractional = valid.clone();
    fractional["continuation"]["history_through_sequence"] = json!(2.5);
    cases.push((fractional, "invalid_continuation"));
    let mut child = valid.clone();
    child["continuation"]["commands"] = json!([{"type":"open_signal_wait","signal_name":"go"}]);
    cases.push((child, "unsupported_continuation_command"));
    let mut without_poll = valid.clone();
    without_poll.as_object_mut().unwrap().remove("workflow_poll");
    cases.push((without_poll, "continuation_requires_workflow_poll"));
    let before = table_counts(&database).await;
    for (body, reason) in cases {
        let (status, refused) = continue_v2(&app, &seeded, body).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{reason}: {refused}");
        assert_eq!(refused["reason"], reason, "{refused}");
        assert_eq!(table_counts(&database).await, before, "{reason} wrote nothing");
    }
    let (status, done) = continue_v2(&app, &seeded, valid).await;
    assert_eq!(status, StatusCode::OK, "{done}");
    assert_eq!(done["continuation"]["outcome"], "accepted", "{done}");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn proof_declines_keep_the_ordinary_completion_in_order() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    for worker in ["v2-identity", "v2-expired", "v2-cancelled", "v2-parallel", "v2-signalled", "v2-behind", "v2-other-event"] {
        v2_worker(&app, registration(worker)).await;
    }

    let identity = seed(&app, "v2-identity", "v2-identity", schedule()).await;
    let mut body = v2_body(&identity, timer(), "v2-identity");
    body["continuation"]["sticky_cache"]["build_id"] = json!("build-2");
    assert_declined(&app, &identity, body, "cache_identity_mismatch").await;

    let expired = seed(&app, "v2-expired", "v2-expired", schedule()).await;
    database.execute(sqlx::AssertSqlSafe(format!("UPDATE workflow_runs SET run_deadline_at='2000-01-01 00:00:00.000000' WHERE id='{}'",
        expired.activity["run_id"].as_str().unwrap()))).await;
    let mut body = v2_body(&expired, timer(), "v2-expired");
    // The ordinary carried claim of an expired run is not under test here.
    body["workflow_poll"]["worker_id"] = json!("someone-else");
    assert_declined(&app, &expired, body, "run_not_open").await;

    let cancelled = seed(&app, "v2-cancelled", "v2-cancelled", schedule()).await;
    database.execute(sqlx::AssertSqlSafe(format!("UPDATE workflow_runs SET cancellation_request_command_id='v2-cancel',cancellation_deadline_at='2037-01-01 00:00:00.000000' WHERE id='{}'",
        cancelled.activity["run_id"].as_str().unwrap()))).await;
    let mut body = v2_body(&cancelled, timer(), "v2-cancelled");
    body["workflow_poll"]["worker_id"] = json!("someone-else");
    assert_declined(&app, &cancelled, body, "cancellation_requested").await;

    let group = |index: usize| json!({"parallel_group_id":"parallel-activities:1:2","parallel_group_kind":"activity",
        "parallel_group_base_sequence":1,"parallel_group_size":2,"parallel_group_index":index});
    let members: Vec<Value> = (0..2).map(|index| {
        let mut command = schedule()[0].clone();
        command.as_object_mut().unwrap().extend(group(index).as_object().unwrap().clone());
        command["parallel_group_path"] = json!([group(index)]);
        command
    }).collect();
    let parallel = seed(&app, "v2-parallel", "v2-parallel", Value::Array(members)).await;
    assert_declined(&app, &parallel, v2_body(&parallel, timer(), "v2-parallel"), "parallel_group").await;

    // A signal committed first waits for the next workflow task; its event
    // moved the history past the worker's cache.
    let signalled = seed(&app, "v2-signalled", "v2-signalled", schedule()).await;
    let (status, sent) = request(&app, "POST", "/api/workflows/v2-signalled/signal/note", json!([1])).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{sent}");
    assert_declined(&app, &signalled, v2_body(&signalled, timer(), "v2-signalled"), "history_changed").await;

    // A cache behind the Server, and the right boundary with another event.
    let behind = seed(&app, "v2-behind", "v2-behind", schedule()).await;
    let mut body = v2_body(&behind, timer(), "v2-behind");
    let previous = &behind.tail["history_events"][0];
    let stale = behind.tail["last_sequence"].as_i64().unwrap() - 1;
    assert_eq!(previous["sequence"], stale, "{}", behind.tail);
    body["continuation"]["history_through_sequence"] = json!(stale);
    body["continuation"]["history_last_event_id"] = previous["id"].clone();
    body["continuation"]["sticky_cache"]["history_last_sequence"] = json!(stale);
    assert_declined(&app, &behind, body, "history_changed").await;
    let other = seed(&app, "v2-other-event", "v2-other-event", schedule()).await;
    let mut body = v2_body(&other, timer(), "v2-other-event");
    body["continuation"]["history_last_event_id"] = json!("01JZZZZZZZZZZZZZZZZZZZZZZZ");
    assert_declined(&app, &other, body, "history_changed").await;
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_standalone_activity_declines() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    v2_worker(&app, registration("v2-standalone")).await;
    let (status, started) = request(&app, "POST", "/api/activities", json!({"activity_id":"v2-standalone","activity_type":"activity",
        "task_queue":"v2-standalone","input":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    heartbeat(&app, &json!("v2-standalone")).await;
    let activity = poll_on(&app, "v2-standalone", "activity").await;
    // The host run declines before any history proof, so any boundary does.
    let tail = json!({"last_sequence":1,"history_events":[{"id":"01JSTANDALONEHOSTEVENT0000"}]});
    let standalone = Seed { workflow: "v2-standalone".into(), activity, tail };
    let mut body = v2_body(&standalone, timer(), "v2-standalone");
    body["workflow_poll"]["worker_id"] = json!("someone-else");
    assert_declined(&app, &standalone, body, "standalone_activity").await;
    runtime.close().await;
    database.remove().await;
}

async fn update_worker(app: &Router, worker: &str) {
    let mut body = registration(worker);
    body["capabilities"].as_array_mut().unwrap().push(json!("workflow_updates"));
    body["workflow_command_contracts"] = json!({"echo":{"signals":[],"signal_contracts":[],"updates":["set"],"update_validators":[],
        "update_contracts":[{"name":"set","parameters":[{"name":"value","type":"int","required":true,"variadic":false,
            "default_available":false,"allows_null":false}]}]}});
    v2_worker(app, body).await;
}

async fn accept_update(app: &Router, workflow: &str) {
    let (status, accepted) = request(app, "POST", &format!("/api/workflows/{workflow}/update/set"),
        json!({"input":envelope(Payload::Array(vec![Payload::Long(5)])),"wait_for":"accepted"})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{accepted}");
}

#[tokio::test]
async fn a_pending_workflow_task_or_update_declines() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "v2-pending").await;
    update_worker(&app, "v2-update").await;
    // An accepted update is delivered by a workflow task the run now waits on.
    let pending = seed(&app, "v2-pending", "v2-pending", schedule()).await;
    accept_update(&app, "v2-pending").await;
    let mut body = v2_body(&pending, timer(), "v2-pending");
    body["workflow_poll"]["worker_id"] = json!("someone-else");
    assert_declined(&app, &pending, body, "pending_workflow_task").await;

    let updated = seed(&app, "v2-update", "v2-update", schedule()).await;
    accept_update(&app, "v2-update").await;
    // Leave the accepted update without its workflow task, so the update is
    // the first reason.
    database.execute(sqlx::AssertSqlSafe(format!("UPDATE workflow_tasks SET status='completed' WHERE workflow_run_id='{}' AND task_type='workflow' AND status='ready'",
        updated.activity["run_id"].as_str().unwrap()))).await;
    let mut body = v2_body(&updated, timer(), "v2-update");
    body["workflow_poll"]["worker_id"] = json!("someone-else");
    assert_declined(&app, &updated, body, "pending_update").await;
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn carried_workflow_poll_declines_keep_the_ordinary_completion() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    for worker in ["v2-unadmitted", "v2-pinned", "v2-replayed"] {
        v2_worker(&app, registration(worker)).await;
    }

    let unadmitted = seed(&app, "v2-unadmitted", "v2-unadmitted", schedule()).await;
    let mut body = v2_body(&unadmitted, timer(), "v2-unadmitted");
    body["workflow_poll"]["worker_id"] = json!("someone-else");
    let done = assert_declined(&app, &unadmitted, body, "workflow_slot_unavailable").await;
    assert!(done.get("workflow_poll").is_none(), "{done}");

    // The worker's build cannot take the resumed task.
    let pinned = seed(&app, "v2-pinned", "v2-pinned", schedule()).await;
    database.execute(sqlx::AssertSqlSafe(format!("UPDATE workflow_runs SET compatibility='build-other' WHERE id='{}'",
        pinned.activity["run_id"].as_str().unwrap()))).await;
    let done = assert_declined(&app, &pinned, v2_body(&pinned, timer(), "v2-pinned"), "no_workflow_task").await;
    assert_eq!(done["workflow_poll"], json!({"leased":false}), "{done}");

    // The carried poll's ID already leased another run's task.
    let replayed = seed(&app, "v2-replayed", "v2-replayed", schedule()).await;
    start_on(&app, "v2-elsewhere", "v2-replayed").await;
    heartbeat(&app, &json!("v2-replayed")).await;
    let (status, elsewhere) = request(&app, "POST", "/api/worker/workflow-tasks/poll",
        poll_body(&json!("v2-replayed"), "v2-replayed-shared")).await;
    assert_eq!(status, StatusCode::OK, "{elsewhere}");
    assert_eq!(elsewhere["task"]["workflow_id"], "v2-elsewhere", "{elsewhere}");
    let mut body = v2_body(&replayed, timer(), "v2-replayed");
    body["workflow_poll"]["poll_request_id"] = json!("v2-replayed-shared");
    let done = assert_declined(&app, &replayed, body, "dispatch_selected_other_task").await;
    assert_eq!(done["workflow_poll"]["answer"]["task"]["task_id"], elsewhere["task"]["task_id"], "{done}");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn an_exact_retry_rereads_the_tail_and_replays_only_a_live_lease() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    v2_worker(&app, registration("v2-retry")).await;
    let seeded = seed(&app, "v2-retry", "v2-retry", schedule()).await;
    let body = v2_body(&seeded, schedule(), "v2-retry");
    let (status, done) = continue_v2(&app, &seeded, body.clone()).await;
    assert_eq!(status, StatusCode::OK, "{done}");
    assert_eq!(done["continuation"]["outcome"], "accepted", "{done}");

    let before = table_counts(&database).await;
    let (status, retry) = continue_v2(&app, &seeded, body.clone()).await;
    assert_eq!(status, StatusCode::OK, "{retry}");
    assert_eq!((&retry["recorded"], &retry["reason"]), (&json!(false), &json!("already_completed")), "{retry}");
    assert_eq!(retry["continuation"], done["continuation"]);
    assert_eq!(retry["continuation_tail"], done["continuation_tail"], "committed events read again by range");
    let (original, replayed) = (&done["activity_poll"]["answer"]["task"], &retry["activity_poll"]["answer"]["task"]);
    assert_eq!(replayed["task_id"], original["task_id"], "{retry}");
    assert_eq!(replayed["lease_expires_at"], original["lease_expires_at"], "a retry renews nothing");
    assert_eq!(table_counts(&database).await, before, "a retry writes nothing");

    let mut changed = body.clone();
    changed["result"] = envelope(Payload::Long(8));
    let (status, conflict) = continue_v2(&app, &seeded, changed).await;
    assert_eq!(status, StatusCode::CONFLICT, "{conflict}");
    assert_eq!(conflict["reason"], "conflicting_completion", "{conflict}");

    // An expired carried lease is not replayed, and nothing claims again.
    database.execute(sqlx::AssertSqlSafe(format!("UPDATE workflow_tasks SET lease_expires_at='2000-01-01 00:00:00.000000' WHERE id='{}'",
        original["task_id"].as_str().unwrap()))).await;
    // The runtime's scheduler settles an expired activity lease within a
    // tick or two, writing its own history and failure rows. Let it finish
    // before the snapshot, so the comparison sees only what the retry wrote.
    let leased: &'static str = Box::leak(format!("SELECT COUNT(*) FROM workflow_tasks WHERE id='{}' AND status='leased'",
        original["task_id"].as_str().unwrap()).into_boxed_str());
    let settled_by = tokio::time::Instant::now() + std::time::Duration::from_secs(30);
    while task_retry::rows(&database, leased).await > 0 {
        assert!(tokio::time::Instant::now() < settled_by, "the scheduler settles the expired carried lease");
        tokio::time::sleep(std::time::Duration::from_millis(50)).await;
    }
    let before = table_counts(&database).await;
    let (status, expired) = continue_v2(&app, &seeded, body.clone()).await;
    assert_eq!(status, StatusCode::OK, "{expired}");
    assert!(expired.get("activity_poll").is_none(), "{expired}");
    assert_eq!(expired["continuation"], done["continuation"]);
    assert_eq!(table_counts(&database).await, before, "nothing claims again");

    // A tail range that is gone is unavailable.
    let after = done["continuation_tail"]["after_sequence"].as_i64().unwrap();
    database.execute(sqlx::AssertSqlSafe(format!("DELETE FROM workflow_history_events WHERE workflow_run_id='{}' AND sequence>{after}",
        seeded.activity["run_id"].as_str().unwrap()))).await;
    let (status, pruned) = continue_v2(&app, &seeded, body.clone()).await;
    assert_eq!(status, StatusCode::OK, "{pruned}");
    assert_eq!(pruned["continuation_tail"], json!({"version":2,"after_sequence":after,"omitted":"tail_unavailable"}));

    let path = "$.activity_workflow_continuation_v2.answer.continuation.workflow_task_id";
    match &database {
        TestDatabase::Sqlite(_) => database.execute(sqlx::AssertSqlSafe(format!("UPDATE dw_task_completions SET receipt=json_set(receipt,'{path}','foreign-task') WHERE task_id='{}'", seeded.activity["task_id"].as_str().unwrap()))).await,
        TestDatabase::MySql { .. } => database.execute(sqlx::AssertSqlSafe(format!("UPDATE dw_task_completions SET receipt=JSON_SET(receipt,'{path}','foreign-task') WHERE task_id='{}'", seeded.activity["task_id"].as_str().unwrap()))).await,
        TestDatabase::Postgres { .. } => database.execute(sqlx::AssertSqlSafe(format!("UPDATE dw_task_completions SET receipt=jsonb_set(receipt,'{{activity_workflow_continuation_v2,answer,continuation,workflow_task_id}}','\"foreign-task\"') WHERE task_id='{}'", seeded.activity["task_id"].as_str().unwrap()))).await,
    }
    let (status, corrupt) = continue_v2(&app, &seeded, body).await;
    assert_eq!(status, StatusCode::INTERNAL_SERVER_ERROR, "{corrupt}");
    assert_eq!(corrupt["reason"], "invalid_continuation_receipt", "{corrupt}");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn an_over_budget_tail_is_omitted_and_the_next_suffix_starts_at_the_boundary() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    v2_worker(&app, registration("v2-budget")).await;
    let seeded = seed(&app, "v2-budget", "v2-budget", schedule()).await;
    let through = seeded.tail["last_sequence"].clone();
    let mut body = v2_body(&seeded, schedule(), "v2-budget");
    body["result"] = envelope(Payload::String("x".repeat(300_000)));
    let (status, done) = continue_v2(&app, &seeded, body).await;
    assert_eq!(status, StatusCode::OK, "{done}");
    assert_eq!(done["continuation"]["outcome"], "accepted", "{done}");
    assert_eq!(done["continuation_tail"], json!({"version":2,"after_sequence":through,"omitted":"tail_byte_budget_exceeded"}));
    let leased = done["activity_poll"]["answer"]["task"].clone();
    assert_eq!(leased["task_id"], done["continuation"]["completion"]["created_task_ids"][0], "{done}");
    let (status, finished) = request(&app, "POST", &format!("/api/worker/activity-tasks/{}/complete", leased["task_id"].as_str().unwrap()),
        json!({"lease_owner":"v2-budget","activity_attempt_id":leased["activity_attempt_id"],"result":envelope(Payload::Long(1))})).await;
    assert_eq!(status, StatusCode::OK, "{finished}");
    heartbeat(&app, &json!("v2-budget")).await;
    let next = poll_on(&app, "v2-budget", "workflow").await;
    assert_eq!(next["sticky_replay_mode"], "sticky_hit_expected", "{next}");
    assert_eq!(next["history_after_sequence"], through, "{next}");
    assert_eq!(sequences(&next["history_events"]).first(), Some(&(through.as_i64().unwrap() + 1)), "{next}");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_fault_after_the_speculative_writes_rolls_back_every_effect() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    v2_worker(&app, registration("v2-fault")).await;
    let seeded = seed(&app, "v2-fault", "v2-fault", schedule()).await;
    match &database {
        TestDatabase::Sqlite(_) => database.execute("CREATE TRIGGER continuation_fault BEFORE INSERT ON workflow_history_events WHEN NEW.event_type='WorkflowCompleted' BEGIN SELECT RAISE(ABORT,'continuation fault fixture'); END").await,
        TestDatabase::MySql { .. } => database.execute("CREATE TRIGGER continuation_fault BEFORE INSERT ON workflow_history_events FOR EACH ROW BEGIN IF NEW.event_type='WorkflowCompleted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='continuation fault fixture'; END IF; END").await,
        TestDatabase::Postgres { .. } => {
            database.execute("CREATE FUNCTION continuation_fault_fn() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.event_type='WorkflowCompleted' THEN RAISE EXCEPTION 'continuation fault fixture'; END IF; RETURN NEW; END $$").await;
            database.execute("CREATE TRIGGER continuation_fault BEFORE INSERT ON workflow_history_events FOR EACH ROW EXECUTE FUNCTION continuation_fault_fn()").await;
        }
    }
    let before = table_counts(&database).await;
    let events = event_types(&app, &seeded).await;
    let commands = json!([{"type":"record_version_marker","change_id":"v2-marker","version":1,"min_supported":1,"max_supported":1},
        {"type":"complete_workflow","result":envelope(Payload::Long(7))}]);
    let body = v2_body(&seeded, commands, "v2-fault");
    let (status, refused) = continue_v2(&app, &seeded, body.clone()).await;
    assert_eq!(status, StatusCode::SERVICE_UNAVAILABLE, "{refused}");
    assert_eq!(table_counts(&database).await, before, "no result, marker, task, receipt or poll binding survives");
    assert_eq!(event_types(&app, &seeded).await, events);
    match &database {
        TestDatabase::Postgres { .. } => database.execute("DROP TRIGGER continuation_fault ON workflow_history_events").await,
        _ => database.execute("DROP TRIGGER continuation_fault").await,
    }
    let (status, accepted) = continue_v2(&app, &seeded, body).await;
    assert_eq!(status, StatusCode::OK, "{accepted}");
    assert_eq!(accepted["continuation"]["outcome"], "accepted", "{accepted}");
    let events = event_types(&app, &seeded).await;
    assert_eq!(events.iter().filter(|kind| *kind == "VersionMarkerRecorded").count(), 1, "{events:?}");
    runtime.close().await;
    database.remove().await;
}

// Control 3 of the contract's bypass guard: a history row removed behind the
// allocator, with the worker's boundary and event ID still matching, is an
// invariant fault, not an accepted continuation.
#[tokio::test]
async fn a_writer_that_bypasses_the_allocator_is_caught() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    v2_worker(&app, registration("v2-bypass")).await;
    let seeded = seed(&app, "v2-bypass", "v2-bypass", schedule()).await;
    database.execute(sqlx::AssertSqlSafe(format!("DELETE FROM workflow_history_events WHERE workflow_run_id='{}' AND sequence=1",
        seeded.activity["run_id"].as_str().unwrap()))).await;
    let events = history(&app, &seeded).await;
    let before = table_counts(&database).await;
    let (status, refused) = continue_v2(&app, &seeded, v2_body(&seeded, complete_workflow(), "v2-bypass")).await;
    assert_eq!(status, StatusCode::INTERNAL_SERVER_ERROR, "{refused}");
    assert_eq!(refused["reason"], "history_invariant_violated", "{refused}");
    assert_eq!(history(&app, &seeded).await, events, "no new events");
    assert_eq!(table_counts(&database).await, before);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_version_one_continuation_is_unchanged() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    v2_worker(&app, registration("v2-v1")).await;
    let seeded = seed(&app, "v2-v1", "v2-v1", schedule()).await;
    // A version-2 request without its cache claim is a malformed v2
    // request; a version the Server does not implement keeps v1's refusal.
    let mut claimless = v2_body(&seeded, timer(), "v2-v1");
    claimless["continuation"].as_object_mut().unwrap().remove("sticky_cache");
    let mut unknown = claimless.clone();
    unknown["continuation"]["version"] = json!(3);
    for (body, reason) in [(claimless, "invalid_continuation"), (unknown, "unsupported_continuation_version")] {
        let (status, refused) = continue_v2(&app, &seeded, body).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{refused}");
        assert_eq!(refused["reason"], reason, "{refused}");
    }
    let (status, history) = request(&app, "POST", &format!("/api/worker/activity-tasks/{}/continuation/history",
        seeded.activity["task_id"].as_str().unwrap()), json!({"lease_owner":"v2-v1","activity_attempt_id":seeded.activity["activity_attempt_id"]})).await;
    assert_eq!(status, StatusCode::OK, "{history}");
    let body = json!({"lease_owner":"v2-v1","activity_attempt_id":seeded.activity["activity_attempt_id"],"result":envelope(Payload::Long(7)),
        "workflow_poll":poll_body(&json!("v2-v1"), "v2-v1-poll"),
        "continuation":{"version":1,"history_through_sequence":history["history_through_sequence"],
            "history_last_event_id":history["history_last_event_id"],"commands":complete_workflow()}});
    let (status, done) = continue_v2(&app, &seeded, body).await;
    assert_eq!(status, StatusCode::OK, "{done}");
    assert_eq!(done["continuation"]["version"], 1, "{done}");
    assert_eq!(done["continuation"]["outcome"], "accepted", "{done}");
    assert!(done.get("continuation_tail").is_none(), "{done}");
    runtime.close().await;
    database.remove().await;
}

// The other half of control 2 (#1079): a writer that bypasses the allocator
// after the proof, inside the transaction, breaks the sequence the
// transaction remembers. The commit-time independent read catches it. The
// trigger is on activity_attempts, which the result write updates after the
// proof, because MySQL forbids a trigger that changes its own table.
#[tokio::test]
async fn a_writer_that_bypasses_the_allocator_after_the_proof_is_caught() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    v2_worker(&app, registration("v2-late-bypass")).await;
    let seeded = seed(&app, "v2-late-bypass", "v2-late-bypass", schedule()).await;
    let delete = "DELETE FROM workflow_history_events WHERE workflow_run_id=NEW.workflow_run_id AND sequence=1";
    match &database {
        TestDatabase::Sqlite(_) => database.execute(sqlx::AssertSqlSafe(format!("CREATE TRIGGER continuation_bypass AFTER UPDATE ON activity_attempts WHEN OLD.status='running' AND NEW.status='completed' BEGIN {delete}; END"))).await,
        TestDatabase::MySql { .. } => database.execute(sqlx::AssertSqlSafe(format!("CREATE TRIGGER continuation_bypass AFTER UPDATE ON activity_attempts FOR EACH ROW BEGIN IF OLD.status='running' AND NEW.status='completed' THEN {delete}; END IF; END"))).await,
        TestDatabase::Postgres { .. } => {
            database.execute(sqlx::AssertSqlSafe(format!("CREATE FUNCTION continuation_bypass_fn() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF OLD.status='running' AND NEW.status='completed' THEN {delete}; END IF; RETURN NEW; END $$"))).await;
            database.execute("CREATE TRIGGER continuation_bypass AFTER UPDATE ON activity_attempts FOR EACH ROW EXECUTE FUNCTION continuation_bypass_fn()").await;
        }
    }
    let events = history(&app, &seeded).await;
    let before = table_counts(&database).await;
    let (status, refused) = continue_v2(&app, &seeded, v2_body(&seeded, timer(), "v2-late-bypass")).await;
    assert_eq!(status, StatusCode::INTERNAL_SERVER_ERROR, "{refused}");
    assert_eq!(refused["reason"], "history_invariant_violated", "{refused}");
    assert_eq!(history(&app, &seeded).await, events, "everything rolled back");
    assert_eq!(table_counts(&database).await, before);
    runtime.close().await;
    database.remove().await;
}

// #1079 control: a fault after the nested completion, at the receipt write,
// rolls back every effect, and the ordinary completion then succeeds.
#[tokio::test]
async fn a_fault_after_the_nested_completion_leaves_the_ordinary_completion() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    v2_worker(&app, registration("v2-receipt-fault")).await;
    let seeded = seed(&app, "v2-receipt-fault", "v2-receipt-fault", schedule()).await;
    match &database {
        TestDatabase::Sqlite(_) => database.execute("CREATE TRIGGER continuation_receipt_fault BEFORE UPDATE ON dw_task_completions WHEN NEW.receipt LIKE '%activity_workflow_continuation_v2%' BEGIN SELECT RAISE(ABORT,'continuation receipt fault fixture'); END").await,
        TestDatabase::MySql { .. } => database.execute("CREATE TRIGGER continuation_receipt_fault BEFORE UPDATE ON dw_task_completions FOR EACH ROW BEGIN IF CAST(NEW.receipt AS CHAR) LIKE '%activity_workflow_continuation_v2%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='continuation receipt fault fixture'; END IF; END").await,
        TestDatabase::Postgres { .. } => {
            database.execute("CREATE FUNCTION continuation_receipt_fault_fn() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.receipt::text LIKE '%activity_workflow_continuation_v2%' THEN RAISE EXCEPTION 'continuation receipt fault fixture'; END IF; RETURN NEW; END $$").await;
            database.execute("CREATE TRIGGER continuation_receipt_fault BEFORE UPDATE ON dw_task_completions FOR EACH ROW EXECUTE FUNCTION continuation_receipt_fault_fn()").await;
        }
    }
    let events = history(&app, &seeded).await;
    let before = table_counts(&database).await;
    let (status, refused) = continue_v2(&app, &seeded, v2_body(&seeded, schedule(), "v2-receipt-fault")).await;
    assert_eq!(status, StatusCode::SERVICE_UNAVAILABLE, "{refused}");
    assert_eq!(history(&app, &seeded).await, events, "no result, command, claim or tail survives");
    assert_eq!(table_counts(&database).await, before);
    heartbeat(&app, &seeded.activity["lease_owner"]).await;
    let (status, ordinary) = request(&app, "POST", &format!("/api/worker/activity-tasks/{}/complete", seeded.activity["task_id"].as_str().unwrap()),
        json!({"lease_owner":seeded.activity["lease_owner"],"activity_attempt_id":seeded.activity["activity_attempt_id"],
            "result":envelope(Payload::Long(7))})).await;
    assert_eq!(status, StatusCode::OK, "{ordinary}");
    assert_eq!(ordinary["recorded"], true, "{ordinary}");
    runtime.close().await;
    database.remove().await;
}
