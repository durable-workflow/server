use super::*;
use durable_workflow_server::runtime::poll_capacity::Settings;
use std::time::{Duration, Instant};

// Server #982: native honors PHP 2.x's node-wide long-poll caps
// (`LongPollWaitSlotStore`). A poll that found nothing and would wait takes a
// slot from its pool: DW_WORKER_LONG_POLL_MAX_CONCURRENT for workflow and
// activity polls, DW_QUERY_TASK_POLL_MAX_CONCURRENT for query-task polls. A
// poll that finds the pool full answers at once with
// `WorkerPollBackpressure`'s typed 429 and `Retry-After: 1`, holding nothing.
// DW_WORKER_LONG_POLL_RESERVED_HTTP_WORKERS keeps database connections free of
// poll probes, so health, readiness and admin stay served.

const WORKFLOW: &str = "/api/worker/workflow-tasks/poll";
const ACTIVITY: &str = "/api/worker/activity-tasks/poll";
const QUERY: &str = "/api/worker/query-tasks/poll";

struct Answer {
    status: StatusCode,
    retry_after: Option<String>,
    body: Value,
    took: Duration,
}

async fn send(app: &Router, namespace: &str, method: &str, path: &str, body: Value) -> Answer {
    let worker = path.starts_with("/api/worker/");
    let request = Request::builder()
        .method(method)
        .uri(path)
        .header("authorization", "Bearer test-token")
        .header("content-type", "application/json")
        .header("x-namespace", namespace)
        .header(
            if worker { "x-durable-workflow-protocol-version" } else { "x-durable-workflow-control-plane-version" },
            if worker { "1.19" } else { "2" },
        )
        .body(if method == "GET" { Body::empty() } else { Body::from(body.to_string()) })
        .unwrap();
    let began = Instant::now();
    let response = app.clone().oneshot(request).await.unwrap();
    let took = began.elapsed();
    let status = response.status();
    let retry_after = response.headers().get("retry-after").and_then(|value| value.to_str().ok()).map(str::to_owned);
    let bytes = to_bytes(response.into_body(), 4 * 1024 * 1024).await.unwrap();
    let body = serde_json::from_slice(&bytes).unwrap_or(Value::Null);
    Answer { status, retry_after, body, took }
}

fn poll_body(worker: &str, timeout: u64) -> Value {
    json!({"worker_id": worker, "task_queue": "test", "timeout_seconds": timeout})
}

fn spawn_poll(app: &Router, namespace: &'static str, path: &'static str, worker: &'static str, timeout: u64)
    -> tokio::task::JoinHandle<Answer> {
    let app = app.clone();
    tokio::spawn(async move { send(&app, namespace, "POST", path, poll_body(worker, timeout)).await })
}

async fn workers(app: &Router) {
    register(app, "worker", json!(["echo"]), json!(["greet"])).await;
    query_worker(app, "querier").await;
}

fn assert_refused(answer: &Answer, kind: &str, pool: &str) {
    assert_eq!(answer.status, StatusCode::TOO_MANY_REQUESTS, "{}", answer.body);
    assert_eq!(answer.retry_after.as_deref(), Some("1"), "{}", answer.body);
    let body = &answer.body;
    assert!(body["task"].is_null(), "{body}");
    assert_eq!(body["poll_status"], "long_poll_capacity_exhausted", "{body}");
    assert_eq!(body["reason"], "long_poll_capacity_exhausted", "{body}");
    assert_eq!(body["task_kind"], kind, "{body}");
    assert_eq!(body["wait_pool"], pool, "{body}");
    assert_eq!(body["task_queue"], "test", "{body}");
    assert_eq!(body["retry_after_seconds"], 1, "{body}");
    assert_eq!(body["retryable"], true, "{body}");
    assert!(body["protocol_version"].is_string() && body["server_capabilities"].is_object(), "{body}");
    assert!(answer.took < Duration::from_millis(1500), "a refused poll answers at once, took {:?}", answer.took);
}

fn assert_held_empty(answer: &Answer, timeout: u64) {
    assert_eq!(answer.status, StatusCode::OK, "{}", answer.body);
    assert!(answer.body["task"].is_null(), "{}", answer.body);
    assert!(answer.took >= Duration::from_millis(timeout * 1000 - 200), "a held poll waits, took {:?}", answer.took);
}

#[tokio::test]
async fn polls_beyond_each_wait_cap_get_the_typed_429_without_holding() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap().with_poll_capacity(Settings {
        worker_waits: Some(2),
        query_task_waits: Some(1),
        reserved: None,
    });
    let app = router(runtime);
    workers(&app).await;

    let mut worker_polls = Vec::new();
    for _ in 0..6 {
        worker_polls.push((ACTIVITY, spawn_poll(&app, "default", ACTIVITY, "worker", 2)));
        worker_polls.push((WORKFLOW, spawn_poll(&app, "default", WORKFLOW, "worker", 2)));
    }
    let query_polls: Vec<_> = (0..4).map(|_| spawn_poll(&app, "default", QUERY, "querier", 2)).collect();

    let (mut held, mut refused) = (0, 0);
    for (path, poll) in worker_polls {
        let answer = poll.await.unwrap();
        if answer.status == StatusCode::OK {
            assert_held_empty(&answer, 2);
            held += 1;
        } else {
            assert_refused(&answer, if path == WORKFLOW { "workflow_task" } else { "activity_task" }, "worker");
            refused += 1;
        }
    }
    // Workflow and activity polls share one budget.
    assert_eq!((held, refused), (2, 10));
    let (mut held, mut refused) = (0, 0);
    for poll in query_polls {
        let answer = poll.await.unwrap();
        if answer.status == StatusCode::OK {
            assert_held_empty(&answer, 2);
            held += 1;
        } else {
            assert_refused(&answer, "query_task", "query-task");
            refused += 1;
        }
    }
    // Query-task polls have their own budget: idle workflow and activity
    // polls cannot take it.
    assert_eq!((held, refused), (1, 3));

    // A poll that asks not to wait never needs a slot.
    let first = spawn_poll(&app, "default", WORKFLOW, "worker", 3);
    let second = spawn_poll(&app, "default", ACTIVITY, "worker", 3);
    tokio::time::sleep(Duration::from_millis(500)).await;
    let immediate = send(&app, "default", "POST", WORKFLOW, poll_body("worker", 0)).await;
    assert_eq!(immediate.status, StatusCode::OK, "{}", immediate.body);
    // Work that is ready is claimed by the first probe, before any slot.
    start(&app, "ready-under-full-cap").await;
    let leased = send(&app, "default", "POST", WORKFLOW, poll_body("worker", 2)).await;
    // Either the fresh poll or the held one leases the task; nothing is
    // lost. A fresh poll that finds nothing is refused, as both slots are held.
    if leased.status != StatusCode::OK {
        assert_refused(&leased, "workflow_task", "worker");
    }
    let others = [first.await.unwrap(), second.await.unwrap()];
    let leases = std::iter::once(&leased).chain(others.iter()).filter(|answer| answer.body["task"].is_object()).count();
    assert_eq!(leases, 1, "{} {} {}", leased.body, others[0].body, others[1].body);
}

#[tokio::test]
async fn a_dropped_or_finished_poll_returns_its_slot() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap().with_poll_capacity(Settings {
        worker_waits: Some(1),
        query_task_waits: Some(1),
        reserved: None,
    });
    let app = router(runtime);
    workers(&app).await;

    // A client that goes away mid-wait releases the slot.
    for path in [WORKFLOW, QUERY] {
        let waiting = spawn_poll(&app, "default", path, if path == QUERY { "querier" } else { "worker" }, 30);
        tokio::time::sleep(Duration::from_millis(500)).await;
        let worker = if path == QUERY { "querier" } else { "worker" };
        let full = send(&app, "default", "POST", path, poll_body(worker, 1)).await;
        assert_eq!(full.status, StatusCode::TOO_MANY_REQUESTS, "{path}: {}", full.body);
        waiting.abort();
        let _ = waiting.await;
        tokio::time::sleep(Duration::from_millis(100)).await;
        let after = send(&app, "default", "POST", path, poll_body(worker, 1)).await;
        assert_held_empty(&after, 1);
        // And the finished poll returned it too.
        let again = send(&app, "default", "POST", path, poll_body(worker, 1)).await;
        assert_held_empty(&again, 1);
    }
}

#[tokio::test]
async fn the_cap_is_node_wide_across_namespaces() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap().with_poll_capacity(Settings {
        worker_waits: Some(1),
        ..Default::default()
    });
    let app = router(runtime);
    register(&app, "worker", json!(["echo"]), json!(["greet"])).await;
    let created = send(&app, "default", "POST", "/api/namespaces", json!({"name": "other", "description": "other", "retention_days": 30})).await;
    assert_eq!(created.status, StatusCode::CREATED, "{}", created.body);
    let registered = send(&app, "other", "POST", "/api/worker/register", json!({"worker_id": "other-worker", "task_queue": "test",
        "runtime": "php", "supported_workflow_types": ["echo"], "supported_activity_types": ["greet"]})).await;
    assert_eq!(registered.status, StatusCode::CREATED, "{}", registered.body);

    // PHP's per-namespace variants are not implemented: one node budget
    // covers every namespace, as PHP's global cap does.
    let waiting = spawn_poll(&app, "default", ACTIVITY, "worker", 2);
    tokio::time::sleep(Duration::from_millis(500)).await;
    let other = send(&app, "other", "POST", ACTIVITY, poll_body("other-worker", 1)).await;
    assert_refused(&other, "activity_task", "worker");
    assert_eq!(other.body["namespace"], "other", "{}", other.body);
    assert_held_empty(&waiting.await.unwrap(), 2);
}

#[tokio::test]
async fn health_readiness_and_admin_stay_fast_while_polls_saturate() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap().with_poll_capacity(Settings {
        worker_waits: Some(4),
        query_task_waits: Some(1),
        reserved: Some(2),
    });
    let app = router(runtime);
    workers(&app).await;

    // Idle workers hammer the poll routes for three seconds: each holds a
    // slot or is refused and polls again at once (ignoring Retry-After).
    let until = Instant::now() + Duration::from_secs(3);
    let mut hammers = Vec::new();
    for index in 0..48 {
        let app = app.clone();
        let (path, worker) = match index % 3 {
            0 => (WORKFLOW, "worker"),
            1 => (ACTIVITY, "worker"),
            _ => (QUERY, "querier"),
        };
        hammers.push(tokio::spawn(async move {
            let (mut held, mut refused) = (0, 0);
            while Instant::now() < until {
                let answer = send(&app, "default", "POST", path, poll_body(worker, 1)).await;
                match answer.status {
                    StatusCode::OK => held += 1,
                    StatusCode::TOO_MANY_REQUESTS => refused += 1,
                    other => panic!("{path}: {other} {}", answer.body),
                }
            }
            (held, refused)
        }));
    }
    tokio::time::sleep(Duration::from_millis(300)).await;
    let mut slowest = Duration::ZERO;
    let mut round = 0;
    while Instant::now() + Duration::from_millis(300) < until {
        round += 1;
        for (method, path) in [("GET", "/api/health"), ("GET", "/api/ready"), ("GET", "/api/cluster/info"), ("GET", "/api/namespaces")] {
            let answer = send(&app, "default", method, path, Value::Null).await;
            assert_eq!(answer.status, StatusCode::OK, "{path}: {}", answer.body);
            slowest = slowest.max(answer.took);
        }
        // A control-plane write is served too.
        let started = send(&app, "default", "POST", "/api/workflows", json!({"workflow_id": format!("served-{round}"), "workflow_type": "echo", "task_queue": "elsewhere"})).await;
        assert_eq!(started.status, StatusCode::CREATED, "{}", started.body);
        slowest = slowest.max(started.took);
    }
    let (mut held, mut refused) = (0, 0);
    for hammer in hammers {
        let (h, r) = hammer.await.unwrap();
        held += h;
        refused += r;
    }
    assert!(held > 0 && refused > 0, "held {held}, refused {refused}");
    assert!(slowest < Duration::from_secs(1), "slowest health/admin answer took {slowest:?}");
}

#[tokio::test]
async fn reserved_connections_keep_readiness_fast_under_unbounded_waits() {
    let database = TestDatabase::new().await;
    // No wait cap: every poll holds, and every same-node commit wakes them
    // all to probe. Probes share the unreserved connections.
    let runtime = database.open().await.unwrap().with_poll_capacity(Settings { reserved: Some(3), ..Default::default() });
    let app = router(runtime);
    workers(&app).await;
    let polls: Vec<_> = (0..40).map(|_| spawn_poll(&app, "default", ACTIVITY, "worker", 3)).collect();
    tokio::time::sleep(Duration::from_millis(300)).await;
    let mut slowest = Duration::ZERO;
    for round in 0..10 {
        // Each start on another queue wakes every held poll.
        let started = send(&app, "default", "POST", "/api/workflows",
            json!({"workflow_id": format!("wake-{round}"), "workflow_type": "echo", "task_queue": "elsewhere"})).await;
        assert_eq!(started.status, StatusCode::CREATED, "{}", started.body);
        let ready = send(&app, "default", "GET", "/api/ready", Value::Null).await;
        assert_eq!(ready.status, StatusCode::OK, "{}", ready.body);
        slowest = slowest.max(ready.took).max(started.took);
        tokio::time::sleep(Duration::from_millis(100)).await;
    }
    for poll in polls {
        let answer = poll.await.unwrap();
        assert_held_empty(&answer, 3);
        assert!(answer.took < Duration::from_secs(5), "probes still run: {:?}", answer.took);
    }
    assert!(slowest < Duration::from_secs(1), "slowest readiness or start took {slowest:?}");
}

#[tokio::test]
async fn unset_settings_leave_every_wait_unbounded() {
    let database = TestDatabase::new().await;
    // Unset in the environment, as PHP outside its CLI server.
    assert_eq!(Settings::from_lookup(|_| None), Settings::default());
    let app = router(database.open().await.unwrap());
    workers(&app).await;
    let mut polls: Vec<_> = (0..12).map(|_| spawn_poll(&app, "default", WORKFLOW, "worker", 1)).collect();
    polls.extend((0..12).map(|_| spawn_poll(&app, "default", ACTIVITY, "worker", 1)));
    polls.extend((0..6).map(|_| spawn_poll(&app, "default", QUERY, "querier", 1)));
    for poll in polls {
        assert_held_empty(&poll.await.unwrap(), 1);
    }
}
