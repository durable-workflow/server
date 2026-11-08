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

async fn finish(app: &Router, namespace: &str, task: &Value, commands: Value) -> (StatusCode, Value) {
    namespaces::scoped(app, namespace, "POST",
        &format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap()),
        completion(task, commands)).await
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
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    if namespace != "default" {
        namespaces::create(&app, namespace).await;
    }
    let registered = author(&app, namespace, "deadline-worker").await;
    assert_eq!(registered["server_capabilities"]["condition_wait_occurrence_deadline"], true);
    (database, runtime, app)
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
/// no fresh positive budget is invented.
#[tokio::test]
async fn a_reopen_after_the_anchor_is_due_immediately_at_the_anchor() {
    let (database, runtime, app) = setup("default").await;
    let (run, _) = open(&app, "default", "deadline-expired", "php:condition-wait:0", 60).await;
    // Move A into the past on the first wait's durable timer only; its timer
    // task stays far in the future, so the engine does not fire it first.
    database.execute("UPDATE workflow_run_timers SET fire_at=(SELECT started_at FROM workflow_runs WHERE workflow_runs.id=workflow_run_timers.workflow_run_id) WHERE sequence=1").await;
    let done = false_wake(&app, "default", &run, "php:condition-wait:0", 59, 60, std::time::Duration::ZERO).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
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
    // Another run with the same occurrence ID, and the same in another
    // namespace, each set their own A.
    let (_, other) = open(&app, "default", "deadline-other-run", "php:condition-wait:0", 60).await;
    assert_ne!(other, first);
    namespaces::create(&app, "deadline-tenant").await;
    author(&app, "deadline-tenant", "deadline-worker").await;
    let (_, tenant) = open(&app, "deadline-tenant", "deadline-calls", "php:condition-wait:0", 60).await;
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
