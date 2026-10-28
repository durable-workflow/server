//! A held workflow or activity poll belongs to the registration it started
//! under (`WorkerPollFence`), as recorded against published 2.11.1: a
//! re-registration or a heartbeat ends it with `stale_worker_registration`,
//! leasing nothing, and the worker's next poll leases the work. A worker that
//! reports its process keeps its poll across a heartbeat from the same
//! process. A workflow task claim whose owner has gone stale is refused
//! `stale_worker_registration` and recovered by the next poll on its queue.
use super::*;
use std::time::Duration;

fn registration(worker: &str, process: Option<&str>) -> Value {
    let mut body = json!({"worker_id":worker,"task_queue":worker,"runtime":"php","supported_workflow_types":["echo"],
        "supported_activity_types":["act"]});
    if let Some(started) = process {
        body["process_metrics"] = json!({"host":"fence-host","process_id":4242,"process_started_at":started});
    }
    body
}

/// Registers the worker on its own queue. PHP keeps registration times in
/// whole seconds, so the next registration change comes a second later.
async fn register_on_own_queue(app: &Router, worker: &str, process: Option<&str>) {
    let (status, body) = request(app, "POST", "/api/worker/register", registration(worker, process)).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    tokio::time::sleep(Duration::from_millis(1100)).await;
}

async fn heartbeat(app: &Router, worker: &str, process: Option<&str>) {
    let mut body = json!({"worker_id":worker});
    if let Some(started) = process {
        body["process_metrics"] = json!({"host":"fence-host","process_id":4242,"process_started_at":started});
    }
    let (status, answer) = request(app, "POST", "/api/worker/heartbeat", body).await;
    assert_eq!(status, StatusCode::OK, "{answer}");
}

async fn start_on(app: &Router, worker: &str) {
    let (status, body) = request(app, "POST", "/api/workflows", json!({"workflow_id":format!("{worker}-run"),"workflow_type":"echo",
        "task_queue":worker,"input":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

/// A poll held for up to four seconds, answered later.
async fn held(app: &Router, worker: &str, kind: &str) -> tokio::task::JoinHandle<(StatusCode, Value)> {
    let app = app.clone();
    let (path, body) = (format!("/api/worker/{kind}-tasks/poll"), json!({"worker_id":worker,"task_queue":worker,"timeout_seconds":4}));
    let poll = tokio::spawn(async move { request(&app, "POST", &path, body).await });
    tokio::time::sleep(Duration::from_millis(500)).await;
    poll
}

async fn poll_now(app: &Router, worker: &str, kind: &str) -> Value {
    let (status, body) = request(app, "POST", &format!("/api/worker/{kind}-tasks/poll"),
        json!({"worker_id":worker,"task_queue":worker,"timeout_seconds":0})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body
}

/// The poll's status, and whether its task is the worker's own run.
fn outcome(body: &Value, worker: &str) -> (String, bool) {
    (body["poll_status"].as_str().unwrap_or_default().to_owned(), body["task"]["workflow_id"] == format!("{worker}-run"))
}

fn stale() -> (String, bool) {
    ("stale_worker_registration".into(), false)
}

fn leased() -> (String, bool) {
    ("leased".into(), true)
}

/// The run's first workflow task, completed by scheduling one activity.
async fn schedule_activity(app: &Router, worker: &str) {
    start_on(app, worker).await;
    let task = poll_now(app, worker, "workflow").await["task"].clone();
    let done = finish_task(app, &task, json!([{"type":"schedule_activity","activity_type":"act","arguments":envelope(Payload::Array(vec![]))}])).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
}

async fn answer(poll: tokio::task::JoinHandle<(StatusCode, Value)>) -> Value {
    let (status, body) = poll.await.unwrap();
    assert_eq!(status, StatusCode::OK, "{body}");
    assert!(body["task"].is_object() || body["task"].is_null(), "{body}");
    body
}

#[tokio::test]
async fn held_workflow_polls_end_when_the_registration_changes() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());

    // Unchanged registration: the held poll leases the work.
    register_on_own_queue(&app, "fence-control", None).await;
    let poll = held(&app, "fence-control", "workflow").await;
    start_on(&app, "fence-control").await;
    assert_eq!(outcome(&answer(poll).await, "fence-control"), leased());

    // A heartbeat, or a same-ID re-registration with the same queue and
    // types: stale, and the next poll leases the work.
    for (worker, change) in [("fence-heartbeat", "heartbeat"), ("fence-replaced", "register")] {
        register_on_own_queue(&app, worker, None).await;
        let poll = held(&app, worker, "workflow").await;
        if change == "heartbeat" {
            heartbeat(&app, worker, None).await;
        } else {
            let (status, body) = request(&app, "POST", "/api/worker/register", registration(worker, None)).await;
            assert_eq!(status, StatusCode::CREATED, "{body}");
        }
        start_on(&app, worker).await;
        assert_eq!(outcome(&answer(poll).await, worker), stale(), "{change}");
        assert_eq!(outcome(&poll_now(&app, worker, "workflow").await, worker), leased(), "{change}");
    }

    // A worker that reports its process keeps the poll across its own
    // heartbeat and loses it to a new process.
    register_on_own_queue(&app, "fence-process", Some("t0")).await;
    let poll = held(&app, "fence-process", "workflow").await;
    heartbeat(&app, "fence-process", Some("t0")).await;
    start_on(&app, "fence-process").await;
    assert_eq!(outcome(&answer(poll).await, "fence-process"), leased(), "same process");
    register_on_own_queue(&app, "fence-new-process", Some("t0")).await;
    let poll = held(&app, "fence-new-process", "workflow").await;
    let (status, body) = request(&app, "POST", "/api/worker/register", registration("fence-new-process", Some("t1"))).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    start_on(&app, "fence-new-process").await;
    assert_eq!(outcome(&answer(poll).await, "fence-new-process"), stale(), "new process");
}

#[tokio::test]
async fn held_activity_polls_end_when_the_registration_changes() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register_on_own_queue(&app, "act-control", None).await;
    let poll = held(&app, "act-control", "activity").await;
    schedule_activity(&app, "act-control").await;
    assert_eq!(outcome(&answer(poll).await, "act-control"), leased());

    register_on_own_queue(&app, "act-replaced", None).await;
    let poll = held(&app, "act-replaced", "activity").await;
    let (status, body) = request(&app, "POST", "/api/worker/register", registration("act-replaced", None)).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    schedule_activity(&app, "act-replaced").await;
    assert_eq!(outcome(&answer(poll).await, "act-replaced"), stale());
    assert_eq!(outcome(&poll_now(&app, "act-replaced", "activity").await, "act-replaced"), leased());
}

// A registration that has not heartbeated within the stale window is
// answered at once, before anything is claimed or replayed.
#[tokio::test]
async fn a_silent_registration_is_answered_stale_at_admission() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let (status, body) = request(&app, "POST", "/api/worker/register", registration("silent", None)).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    start_on(&app, "silent").await;
    database.execute("UPDATE workflow_worker_registrations SET last_heartbeat_at='2000-01-01 00:00:00' WHERE worker_id='silent'").await;
    for kind in ["workflow", "activity"] {
        let started = std::time::Instant::now();
        let (status, body) = request(&app, "POST", &format!("/api/worker/{kind}-tasks/poll"),
            json!({"worker_id":"silent","task_queue":"silent","timeout_seconds":3,"poll_request_id":"silent-id"})).await;
        assert_eq!((status, outcome(&body, "silent")), (StatusCode::OK, stale()), "{kind}: {body}");
        assert!(started.elapsed() < Duration::from_secs(2), "{kind} answered without waiting");
    }
    // A fresh heartbeat admits the poll again; the stale answer was not kept.
    heartbeat(&app, "silent", None).await;
    let (status, body) = request(&app, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"silent","task_queue":"silent","timeout_seconds":0,"poll_request_id":"silent-id"})).await;
    assert_eq!((status, outcome(&body, "silent")), (StatusCode::OK, leased()), "{body}");
}

// A held query poll fenced off by a heartbeat ends empty, as published
// 2.11.1's `performPoll` answers, and the next poll leases the query.
#[tokio::test]
async fn a_held_query_poll_ends_empty_when_the_worker_heartbeats() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    query_worker(&app, "query-fence").await;
    let started = start(&app, "query-fence-run").await;
    let task = poll(&app, "query-fence", "workflow").await;
    let done = finish_task(&app, &task, json!([{"type":"open_signal_wait","signal_name":"finish"}])).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    tokio::time::sleep(Duration::from_millis(1100)).await;
    let held = {
        let app = app.clone();
        tokio::spawn(async move {
            request(&app, "POST", "/api/worker/query-tasks/poll", json!({"worker_id":"query-fence","task_queue":"test","timeout_seconds":4})).await
        })
    };
    tokio::time::sleep(Duration::from_millis(300)).await;
    heartbeat(&app, "query-fence", None).await;
    let query = {
        let app = app.clone();
        let path = format!("/api/workflows/query-fence-run/runs/{}/query/state", started["run_id"].as_str().unwrap());
        tokio::spawn(async move { request(&app, "POST", &path, json!({"input":envelope(Payload::Array(vec![Payload::Long(1)]))})).await })
    };
    let (status, ended) = held.await.unwrap();
    assert_eq!((status, ended["poll_status"].clone(), ended["task"].clone()), (StatusCode::OK, json!("empty"), Value::Null), "{ended}");
    let (status, next) = request(&app, "POST", "/api/worker/query-tasks/poll",
        json!({"worker_id":"query-fence","task_queue":"test","timeout_seconds":2})).await;
    assert_eq!((status, next["task"]["query_name"].clone()), (StatusCode::OK, json!("state")), "{next}");
    query.abort();
}

/// Waits for the start of a wall-clock second, so that registrations made
/// within the next few hundred milliseconds share PHP's whole-second times.
async fn at_second_start() {
    loop {
        let millis = chrono::Utc::now().timestamp_subsec_millis();
        if millis < 50 {
            break;
        }
        tokio::time::sleep(Duration::from_millis(u64::from(1000 - millis) + 5)).await;
    }
}

/// A held poll started without the one-second pause `held` takes.
async fn held_now(app: &Router, worker: &str, queue: &str, kind: &str) -> tokio::task::JoinHandle<(StatusCode, Value)> {
    let app = app.clone();
    let (path, body) = (format!("/api/worker/{kind}-tasks/poll"), json!({"worker_id":worker,"task_queue":queue,"timeout_seconds":4}));
    let poll = tokio::spawn(async move { request(&app, "POST", &path, body).await });
    tokio::time::sleep(Duration::from_millis(100)).await;
    poll
}

// Native is stricter than published PHP here (Server #749): every native
// registration rotates its incarnation token, so a re-registration ends the
// held poll even when PHP's heuristic would keep it, whether it repeats the
// same process metadata or lands in the same stored second without any.
#[tokio::test]
async fn a_reregistration_ends_held_polls_whatever_its_metadata_and_timing() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    for (case, process) in [("same-process", Some("t0")), ("same-second", None)] {
        for kind in ["workflow", "activity"] {
            let worker = format!("reg-{case}-{kind}");
            if process.is_none() {
                at_second_start().await;
            }
            let (status, body) = request(&app, "POST", "/api/worker/register", registration(&worker, process)).await;
            assert_eq!(status, StatusCode::CREATED, "{body}");
            if kind == "activity" {
                // Another worker on the same queue takes the run's first
                // workflow task and schedules the activity, so the
                // re-registration below, which releases the replaced
                // registration's leases, cannot touch that task.
                let author = format!("{worker}-author");
                let (status, body) = request(&app, "POST", "/api/worker/register", json!({"worker_id":author,"task_queue":worker,
                    "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":[]})).await;
                assert_eq!(status, StatusCode::CREATED, "{body}");
                start_on(&app, &worker).await;
                let (status, polled) = request(&app, "POST", "/api/worker/workflow-tasks/poll",
                    json!({"worker_id":author,"task_queue":worker,"timeout_seconds":0})).await;
                assert_eq!((status, polled["poll_status"].clone()), (StatusCode::OK, json!("leased")), "{polled}");
                let task = polled["task"].clone();
                let poll = held_now(&app, &worker, &worker, "activity").await;
                let (status, body) = request(&app, "POST", "/api/worker/register", registration(&worker, process)).await;
                assert_eq!(status, StatusCode::CREATED, "{body}");
                let done = finish_task(&app, &task, json!([{"type":"schedule_activity","activity_type":"act","arguments":envelope(Payload::Array(vec![]))}])).await;
                assert_eq!(done.0, StatusCode::OK, "{}", done.1);
                assert_eq!(outcome(&answer(poll).await, &worker), stale(), "{case} {kind}");
                assert_eq!(outcome(&poll_now(&app, &worker, "activity").await, &worker), leased(), "{case} {kind} next poll");
            } else {
                let poll = held_now(&app, &worker, &worker, "workflow").await;
                let (status, body) = request(&app, "POST", "/api/worker/register", registration(&worker, process)).await;
                assert_eq!(status, StatusCode::CREATED, "{body}");
                start_on(&app, &worker).await;
                assert_eq!(outcome(&answer(poll).await, &worker), stale(), "{case} {kind}");
                assert_eq!(outcome(&poll_now(&app, &worker, "workflow").await, &worker), leased(), "{case} {kind} next poll");
            }
        }
    }
}

/// `query_worker`'s registration, optionally reporting a process.
async fn register_query_worker(app: &Router, worker: &str, process: Option<&str>) {
    let mut body = json!({"worker_id":worker,"task_queue":"test","runtime":"php","supported_workflow_types":["echo"],
        "supported_activity_types":[],"capabilities":["query_tasks"],"workflow_command_contracts":{"echo":{
        "queries":["state"],"query_contracts":[{"name":"state","parameters":[{"name":"request","position":0,
        "type":"int","required":true,"variadic":false,"default_available":false,"allows_null":false}]}]}}});
    if let Some(started) = process {
        body["process_metrics"] = json!({"host":"fence-host","process_id":4242,"process_started_at":started});
    }
    let (status, answer) = request(app, "POST", "/api/worker/register", body).await;
    assert_eq!(status, StatusCode::CREATED, "{answer}");
}

// A held query poll ends empty when its worker re-registers, whether the
// replacement repeats the same process metadata or lands in the same stored
// second without any, and the next poll leases the query. Each case has its
// own database, so no query from another case can answer its next poll.
#[tokio::test]
async fn a_reregistration_ends_a_held_query_poll_whatever_its_metadata_and_timing() {
    for (case, process) in [("same-second", None), ("same-process", Some("t0"))] {
        let database = TestDatabase::new().await;
        let app = router(database.open().await.unwrap());
        let worker = format!("query-rereg-{case}");
        let run = format!("{worker}-run");
        register_query_worker(&app, &worker, process).await;
        let started = start(&app, &run).await;
        let task = poll(&app, &worker, "workflow").await;
        let done = finish_task(&app, &task, json!([{"type":"open_signal_wait","signal_name":"finish"}])).await;
        assert_eq!(done.0, StatusCode::OK, "{case}: {}", done.1);
        at_second_start().await;
        register_query_worker(&app, &worker, process).await;
        let held = held_now(&app, &worker, "test", "query").await;
        register_query_worker(&app, &worker, process).await;
        let query = {
            let app = app.clone();
            let path = format!("/api/workflows/{run}/runs/{}/query/state", started["run_id"].as_str().unwrap());
            tokio::spawn(async move { request(&app, "POST", &path, json!({"input":envelope(Payload::Array(vec![Payload::Long(1)]))})).await })
        };
        let (status, ended) = held.await.unwrap();
        assert_eq!((status, ended["poll_status"].clone(), ended["task"].clone()), (StatusCode::OK, json!("empty"), Value::Null),
            "{case}: {ended}");
        let (status, next) = request(&app, "POST", "/api/worker/query-tasks/poll",
            json!({"worker_id":worker,"task_queue":"test","timeout_seconds":2})).await;
        assert_eq!((status, next["task"]["query_name"].clone()), (StatusCode::OK, json!("state")), "{case}: {next}");
        query.abort();
    }
}

/// A workflow task claim whose owner's registration has gone stale is
/// refused on every workflow-task route that checks the claim, as published
/// 2.11.5's ownership guard refuses it; another worker's poll on the queue
/// recovers the task at the next attempt, fencing the stale owner as
/// superseded, and the old claim then belongs to another worker.
#[tokio::test]
async fn a_stale_owners_workflow_task_claim_is_refused_and_recovered() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register_on_own_queue(&app, "stale-owner", None).await;
    start_on(&app, "stale-owner").await;
    let task = poll_now(&app, "stale-owner", "workflow").await["task"].clone();
    let id = task["task_id"].as_str().unwrap().to_owned();
    let claim = json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"]});
    let with = |extra: Value| {
        let mut body = claim.clone();
        for (key, value) in extra.as_object().unwrap() {
            body[key] = value.clone();
        }
        body
    };
    // A fresh owner's claim is valid.
    let (status, body) = request(&app, "POST", &format!("/api/worker/workflow-tasks/{id}/heartbeat"), claim.clone()).await;
    assert_eq!(status, StatusCode::OK, "{body}");

    database.execute("UPDATE workflow_worker_registrations SET last_heartbeat_at='2000-01-01 00:00:00' WHERE worker_id='stale-owner'").await;
    let token = task["next_history_page_token"].as_str().unwrap_or("MQ==").to_owned();
    for (route, extra) in [
        ("heartbeat", json!({})),
        ("history", json!({"next_history_page_token":token})),
        ("cancellation-scopes/checkpoint", json!({"checkpoint_id":"stale-owner","start_sequence":1,
            "commands":[{"type":"start_timer","delay_seconds":60}]})),
        ("fail", json!({"failure":{"message":"stale owner","type":"StaleOwner"}})),
        ("complete", json!({"commands":[{"type":"complete_workflow","result":envelope(Payload::Array(vec![]))}]})),
    ] {
        // As the shared fixture's probe sends them, at protocol 1.20.
        let (status, body) = request_protocol(&app, "POST", &format!("/api/worker/workflow-tasks/{id}/{route}"), with(extra), "1.20").await;
        assert_eq!(status, StatusCode::CONFLICT, "{route}: {body}");
        assert_eq!(
            (body["reason"].clone(), body["error"].clone(), body["task_status"].clone(), body["task_id"].clone(),
                body["workflow_task_attempt"].clone(), body["lease_owner"].clone(), body["lease_expires_at"].is_string()),
            (json!("stale_worker_registration"), json!("Workflow task lease owner is no longer an active worker."),
                json!("leased"), json!(id), task["workflow_task_attempt"].clone(), task["lease_owner"].clone(), true),
            "{route}: {body}"
        );
    }

    // Another worker's poll on the queue recovers the task at the next attempt.
    let (status, body) = request(&app, "POST", "/api/worker/register", json!({"worker_id":"stale-replacement",
        "task_queue":"stale-owner","runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":["act"]})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    let (status, recovered) = request(&app, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"stale-replacement","task_queue":"stale-owner","timeout_seconds":0})).await;
    assert_eq!(status, StatusCode::OK, "{recovered}");
    assert_eq!(
        (recovered["poll_status"].clone(), recovered["task"]["workflow_id"].clone(), recovered["task"]["workflow_task_attempt"].clone()),
        (json!("leased"), json!("stale-owner-run"), json!(2)),
        "{recovered}"
    );
    let (status, body) = request(&app, "POST", &format!("/api/worker/workflow-tasks/{id}/complete"),
        with(json!({"commands":[{"type":"complete_workflow","result":envelope(Payload::Array(vec![]))}]}))).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::CONFLICT, json!("lease_owner_mismatch")), "{body}");
    // The stale owner was fenced as superseded: its heartbeat no longer
    // revives the old registration.
    let (_, heartbeat) = request(&app, "POST", "/api/worker/heartbeat", json!({"worker_id":"stale-owner"})).await;
    assert_eq!(heartbeat["reason"], "worker_registration_superseded", "{heartbeat}");
}

async fn register_for(app: &Router, worker: &str, queue: &str) {
    let (status, body) = request(app, "POST", "/api/worker/register", json!({"worker_id":worker,"task_queue":queue,
        "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":["act"]})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

async fn start_run(app: &Router, queue: &str, workflow_id: &str) {
    let (status, body) = request(app, "POST", "/api/workflows", json!({"workflow_id":workflow_id,"workflow_type":"echo",
        "task_queue":queue,"input":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

async fn poll_for(app: &Router, worker: &str, queue: &str, timeout: i64) -> Value {
    let (status, body) = request(app, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":worker,"task_queue":queue,"timeout_seconds":timeout})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body
}

async fn registration_status(database: &TestDatabase, worker: &str) -> String {
    let sql = format!("SELECT worker_id,status FROM workflow_worker_registrations WHERE worker_id='{worker}'");
    text_rows(database, &sql, &sql, &sql).await.pop().map(|(_, status)| status).unwrap_or_default()
}

async fn silence(database: &TestDatabase, worker: &str) {
    database.execute(sqlx::AssertSqlSafe(format!(
        "UPDATE workflow_worker_registrations SET last_heartbeat_at='2000-01-01 00:00:00' WHERE worker_id='{worker}'"))).await;
}

/// An owner whose lease has already expired is not fenced: the expired lease
/// is leased again (`recoverExpiredTaskLease` fences no one). Only an
/// unexpired lease whose owner is stale is abandoned and fenced.
#[tokio::test]
async fn only_an_unexpired_abandoned_lease_fences_its_owner() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register_for(&app, "expired-owner", "expired-queue").await;
    start_run(&app, "expired-queue", "expired-run").await;
    assert_eq!(poll_for(&app, "expired-owner", "expired-queue", 0).await["poll_status"], "leased");
    silence(&database, "expired-owner").await;
    database.execute("UPDATE workflow_tasks SET lease_expires_at='2000-01-01 00:00:00' WHERE lease_owner='expired-owner'").await;
    register_for(&app, "expired-replacement", "expired-queue").await;
    let polled = poll_for(&app, "expired-replacement", "expired-queue", 0).await;
    assert_eq!((polled["poll_status"].clone(), polled["task"]["workflow_task_attempt"].clone()), (json!("leased"), json!(2)), "{polled}");
    assert_eq!(registration_status(&database, "expired-owner").await, "active");
}

/// Ready work is claimed before abandoned leases are recovered, as
/// `WorkflowTaskPoller::nextTask` recovers only after claiming nothing: the
/// stale owner stays unfenced until a probe finds nothing else.
#[tokio::test]
async fn ready_work_is_claimed_before_abandoned_leases_are_recovered() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register_for(&app, "order-owner", "order-queue").await;
    start_run(&app, "order-queue", "order-abandoned").await;
    assert_eq!(poll_for(&app, "order-owner", "order-queue", 0).await["task"]["workflow_id"], "order-abandoned");
    silence(&database, "order-owner").await;
    start_run(&app, "order-queue", "order-ready").await;
    register_for(&app, "order-replacement", "order-queue").await;
    let first = poll_for(&app, "order-replacement", "order-queue", 0).await;
    assert_eq!(first["task"]["workflow_id"], "order-ready", "{first}");
    assert_eq!(registration_status(&database, "order-owner").await, "active");
    let second = poll_for(&app, "order-replacement", "order-queue", 0).await;
    assert_eq!((second["task"]["workflow_id"].clone(), second["task"]["workflow_task_attempt"].clone()),
        (json!("order-abandoned"), json!(2)), "{second}");
    assert_eq!(registration_status(&database, "order-owner").await, "superseded");
}

/// A held poll's later probes recover a lease abandoned while it waits.
#[tokio::test]
async fn a_held_poll_recovers_a_lease_abandoned_while_it_waits() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register_for(&app, "held-owner", "held-queue").await;
    start_run(&app, "held-queue", "held-run").await;
    assert_eq!(poll_for(&app, "held-owner", "held-queue", 0).await["poll_status"], "leased");
    register_for(&app, "held-replacement", "held-queue").await;
    let held = {
        let app = app.clone();
        tokio::spawn(async move { poll_for(&app, "held-replacement", "held-queue", 5).await })
    };
    tokio::time::sleep(Duration::from_millis(700)).await;
    silence(&database, "held-owner").await;
    let polled = held.await.unwrap();
    assert_eq!((polled["poll_status"].clone(), polled["task"]["workflow_id"].clone(), polled["task"]["workflow_task_attempt"].clone()),
        (json!("leased"), json!("held-run"), json!(2)), "{polled}");
}

/// Recovery repairs the task as `recoverExpiredTaskLease` does: a repair
/// command triggered by `expired_workflow_task_lease` with the old lease
/// owner and attempt, and the task's repair count.
#[tokio::test]
async fn recovery_repairs_the_abandoned_task_as_php_does() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register_for(&app, "repair-owner", "repair-queue").await;
    start_run(&app, "repair-queue", "repair-run").await;
    let task = poll_for(&app, "repair-owner", "repair-queue", 0).await["task"].clone();
    silence(&database, "repair-owner").await;
    register_for(&app, "repair-replacement", "repair-queue").await;
    assert_eq!(poll_for(&app, "repair-replacement", "repair-queue", 0).await["task"]["workflow_task_attempt"], 2);
    let sql = "SELECT command_type,context FROM workflow_commands WHERE command_type='repair'";
    let repairs = text_rows(&database, sql, "SELECT command_type,context::text FROM workflow_commands WHERE command_type='repair'",
        "SELECT command_type,CAST(context AS CHAR) FROM workflow_commands WHERE command_type='repair'").await;
    assert_eq!(repairs.len(), 1, "{repairs:?}");
    let context: Value = serde_json::from_str(&repairs[0].1).unwrap();
    assert_eq!(context["metadata"], json!({"trigger":"expired_workflow_task_lease","task_id":task["task_id"],
        "lease_owner":"repair-owner","workflow_task_attempt":1}));
    let sql = format!("SELECT id,CAST(repair_count AS TEXT) FROM workflow_tasks WHERE id='{}'", task["task_id"].as_str().unwrap());
    let mysql = sql.replace("AS TEXT", "AS CHAR");
    assert_eq!(text_rows(&database, &sql, &sql, &mysql).await[0].1, "1");
}

/// Abandoned leases are found past a hundred fresh owners' leases on the
/// queue, and a probe recovers at most the scan limit (five by default).
#[tokio::test]
async fn recovery_finds_abandoned_leases_anywhere_and_honors_the_scan_limit() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register_for(&app, "busy-owner", "busy-queue").await;
    for index in 0..101 {
        start_run(&app, "busy-queue", &format!("busy-{index:03}")).await;
        assert_eq!(poll_for(&app, "busy-owner", "busy-queue", 0).await["poll_status"], "leased");
    }
    register_for(&app, "quiet-owner", "busy-queue").await;
    for index in 0..7 {
        start_run(&app, "busy-queue", &format!("quiet-{index}")).await;
        assert_eq!(poll_for(&app, "quiet-owner", "busy-queue", 0).await["poll_status"], "leased");
    }
    silence(&database, "quiet-owner").await;
    register_for(&app, "busy-replacement", "busy-queue").await;
    let polled = poll_for(&app, "busy-replacement", "busy-queue", 0).await;
    assert!(polled["task"]["workflow_id"].as_str().unwrap().starts_with("quiet-"), "{polled}");
    let sql = "SELECT COALESCE(lease_owner,'none'),status FROM workflow_tasks WHERE workflow_run_id IN (SELECT id FROM workflow_runs WHERE workflow_instance_id LIKE 'quiet-%')";
    let rows = text_rows(&database, sql, sql, sql).await;
    let still_abandoned = rows.iter().filter(|(owner, status)| owner == "quiet-owner" && status == "leased").count();
    // Five recovered (one of them leased by this poll), two left for the next probe.
    assert_eq!(still_abandoned, 2, "{rows:?}");
}
