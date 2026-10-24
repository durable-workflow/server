//! A held workflow or activity poll belongs to the registration it started
//! under (`WorkerPollFence`), as recorded against published 2.11.1: a
//! re-registration or a heartbeat ends it with `stale_worker_registration`,
//! leasing nothing, and the worker's next poll leases the work. A worker that
//! reports its process keeps its poll across a heartbeat from the same
//! process.
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
