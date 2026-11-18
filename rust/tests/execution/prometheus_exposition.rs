//! Server #534: `GET /metrics` exposes each namespace's task queue backlog,
//! admission and run series in Prometheus text format, readable with a
//! scrape-only credential, so an autoscaler can wake workers from zero and
//! leave a throttled queue alone.
use super::*;
use admission::admission_http;
use axum::http::HeaderMap;
use std::collections::BTreeSet;

const METRICS_TOKEN: &str = "metrics-scrape-token-0123456789abcdef";
const TENANT_METRICS_TOKEN: &str = "dwr_metrics_team_b_0123456789abcdefgh";

/// One scrape, as Prometheus sends it: a bearer token and no control-plane
/// version header.
async fn scrape(app: &Router, token: Option<&str>, namespace: Option<&str>) -> (StatusCode, HeaderMap, String) {
    scrape_with(app, token, namespace, None).await
}

async fn scrape_with(app: &Router, token: Option<&str>, namespace: Option<&str>, version: Option<&str>) -> (StatusCode, HeaderMap, String) {
    let mut request = Request::builder().method("GET").uri("/metrics");
    if let Some(version) = version {
        request = request.header("x-durable-workflow-control-plane-version", version);
    }
    if let Some(token) = token {
        request = request.header("authorization", format!("Bearer {token}"));
    }
    if let Some(namespace) = namespace {
        request = request.header("x-namespace", namespace);
    }
    let response = app.clone().oneshot(request.body(Body::empty()).unwrap()).await.unwrap();
    let status = response.status();
    let headers = response.headers().clone();
    let body = to_bytes(response.into_body(), 4 * 1024 * 1024).await.unwrap();
    (status, headers, String::from_utf8(body.to_vec()).unwrap())
}

async fn scraped(app: &Router) -> String {
    let (status, headers, text) = scrape(app, Some(METRICS_TOKEN), None).await;
    assert_eq!(status, StatusCode::OK, "{text}");
    assert_eq!(headers["content-type"], "text/plain; version=0.0.4; charset=utf-8");
    well_formed(&text);
    text
}

/// Every line is a HELP, a TYPE or a sample of the family declared last;
/// each family is declared once and no sample repeats.
fn well_formed(text: &str) {
    let mut declared = BTreeSet::new();
    let mut samples = BTreeSet::new();
    let mut current = String::new();
    for line in text.lines() {
        if let Some(rest) = line.strip_prefix("# HELP ") {
            let name = rest.split(' ').next().unwrap();
            assert!(declared.insert(name.to_owned()), "{name} is declared twice");
            current = name.to_owned();
        } else if let Some(rest) = line.strip_prefix("# TYPE ") {
            let (name, kind) = rest.split_once(' ').unwrap();
            assert_eq!(name, current, "TYPE follows its HELP");
            assert!(matches!(kind, "gauge" | "histogram"), "{line}");
        } else {
            let (series, value) = line.rsplit_once(' ').unwrap_or_else(|| panic!("sample line: {line}"));
            assert!(value == "+Inf" || value.parse::<f64>().is_ok(), "{line}");
            let name = series.split('{').next().unwrap();
            let family = ["_bucket", "_sum", "_count"]
                .iter()
                .find_map(|suffix| name.strip_suffix(suffix).filter(|family| *family == current))
                .unwrap_or(name);
            assert_eq!(family, current, "{line} follows its own family");
            assert!(series.contains("{namespace=\""), "{line}");
            for id in ["workflow_id", "run_id", "task_id"] {
                assert!(!series.contains(&format!("{id}=")), "{line}");
            }
            assert!(samples.insert(series.to_owned()), "{series} repeats");
        }
    }
}

/// The value of one sample, by its exact label set after the namespace.
fn sample(text: &str, name: &str, namespace: &str, labels: &[(&str, &str)]) -> Option<f64> {
    let mut series = format!("{name}{{namespace=\"{namespace}\"");
    for (label, value) in labels {
        series.push_str(&format!(",{label}=\"{value}\""));
    }
    series.push_str("} ");
    text.lines()
        .find_map(|line| line.strip_prefix(series.as_str()))
        .map(|value| value.parse().unwrap())
}

fn backlog(text: &str, name: &str, kind: &str) -> Option<f64> {
    sample(text, name, "default", &[("task_queue", "test"), ("task_kind", kind), ("build_id", "")])
}

/// What a KEDA trigger on `dispatchable + leased` asks for: one replica per
/// `per_replica` tasks, rounded up.
fn desired_replicas(text: &str, per_replica: f64) -> f64 {
    let total: f64 = text
        .lines()
        .filter(|line| {
            (line.starts_with("dw_task_queue_dispatchable_tasks{") || line.starts_with("dw_task_queue_leased_tasks{"))
                && line.contains("task_queue=\"test\"")
        })
        .map(|line| line.rsplit_once(' ').unwrap().1.parse::<f64>().unwrap())
        .sum();
    (total / per_replica).ceil()
}

async fn complete_activity(app: &Router, activity: &Value) {
    let (status, body) = request(app, "POST",
        &format!("/api/worker/activity-tasks/{}/complete", activity["task_id"].as_str().unwrap()),
        json!({"lease_owner":activity["lease_owner"],"activity_attempt_id":activity["activity_attempt_id"],
            "result":envelope(Payload::Null)})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
}

fn schedule(activity: &str) -> Value {
    json!([{"type":"schedule_activity","activity_type":activity,"arguments":envelope(Payload::Array(vec![]))}])
}

#[tokio::test]
async fn backlog_wakes_a_worker_from_zero_and_drains_to_zero_with_every_task_completed() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone().with_metrics_token(Some(METRICS_TOKEN.into())));

    // Nothing waits: no queue series, and the KEDA query reads zero.
    let idle = scraped(&app).await;
    assert_eq!(backlog(&idle, "dw_task_queue_due_tasks", "workflow"), None, "{idle}");
    assert_eq!(desired_replicas(&idle, 10.0), 0.0);
    assert_eq!(sample(&idle, "dw_schedules", "default", &[("state", "active")]), Some(0.0));

    // A start with no worker anywhere: the due task is visible, no poller is.
    let started = start(&app, "scale-from-zero").await;
    let woken = scraped(&app).await;
    for (name, value) in [
        ("dw_task_queue_ready_tasks", 1.0),
        ("dw_task_queue_due_tasks", 1.0),
        ("dw_task_queue_delayed_tasks", 0.0),
        ("dw_task_queue_leased_tasks", 0.0),
        ("dw_task_queue_dispatchable_tasks", 1.0),
    ] {
        assert_eq!(backlog(&woken, name, "workflow"), Some(value), "{name}: {woken}");
    }
    assert!(backlog(&woken, "dw_task_queue_oldest_due_task_age_seconds", "workflow").is_some_and(|age| age >= 0.0), "{woken}");
    assert_eq!(sample(&woken, "dw_task_queue_active_pollers", "default", &[("task_queue", "test"), ("build_id", "")]), Some(0.0));
    assert_eq!(sample(&woken, "dw_task_queue_admission_state", "default",
        &[("task_queue", "test"), ("task_kind", "workflow"), ("state", "unlimited")]), Some(1.0));
    assert_eq!(sample(&woken, "dw_task_queue_admission_remaining_capacity", "default",
        &[("task_queue", "test"), ("task_kind", "workflow")]), None, "unlimited admission has no capacity sample");
    assert_eq!(desired_replicas(&woken, 10.0), 1.0, "0 -> 1");

    // The worker the autoscaler started registers and leases the task.
    register(&app, "scaled-worker", json!(["echo"]), json!(["scaled_activity"])).await;
    let task = poll(&app, "scaled-worker", "workflow").await;
    assert_eq!(task["workflow_id"], "scale-from-zero", "{task}");
    let running = scraped(&app).await;
    assert_eq!(sample(&running, "dw_task_queue_active_pollers", "default", &[("task_queue", "test"), ("build_id", "")]), Some(1.0));
    assert_eq!(sample(&running, "dw_task_queue_worker_slots", "default", &[("task_queue", "test"), ("task_kind", "workflow")]), Some(100.0));
    assert_eq!(backlog(&running, "dw_task_queue_due_tasks", "workflow"), Some(0.0));
    assert_eq!(backlog(&running, "dw_task_queue_leased_tasks", "workflow"), Some(1.0));
    assert_eq!(desired_replicas(&running, 10.0), 1.0, "leased work keeps the worker");

    // The activity it schedules is activity backlog of the same queue.
    assert_eq!(finish_task(&app, &task, schedule("scaled_activity")).await.0, StatusCode::OK);
    let activity_due = scraped(&app).await;
    assert_eq!(backlog(&activity_due, "dw_task_queue_due_tasks", "activity"), Some(1.0), "{activity_due}");
    assert_eq!(backlog(&activity_due, "dw_task_queue_due_tasks", "workflow"), None);
    let activity = poll(&app, "scaled-worker", "activity").await;
    complete_activity(&app, &activity).await;
    let resumed = poll(&app, "scaled-worker", "workflow").await;
    assert_eq!(finish_task(&app, &resumed, json!([{"type":"complete_workflow","result":envelope(Payload::Long(1))}])).await.0, StatusCode::OK);

    // Every task completed: nothing due or leased, so the query reads zero.
    let drained = scraped(&app).await;
    for kind in ["workflow", "activity"] {
        for name in ["dw_task_queue_due_tasks", "dw_task_queue_leased_tasks", "dw_task_queue_ready_tasks"] {
            assert_eq!(backlog(&drained, name, kind), None, "{name} {kind}: {drained}");
        }
    }
    assert_eq!(desired_replicas(&drained, 10.0), 0.0, "1 -> 0");
    let run = [("task_queue", "test"), ("workflow_type", "echo")];
    assert_eq!(sample(&drained, "dw_workflow_runs_retained", "default", &run), Some(1.0), "{drained}");
    assert_eq!(sample(&drained, "dw_workflow_runs", "default", &[run[0], run[1], ("status", "completed")]), Some(1.0));
    assert_eq!(sample(&drained, "dw_workflow_runs", "default", &[run[0], run[1], ("status", "running")]), Some(0.0));
    assert_eq!(sample(&drained, "dw_activity_executions", "default",
        &[("task_queue", "test"), ("workflow_type", "echo"), ("activity_type", "scaled_activity"), ("status", "completed")]), Some(1.0));
    assert_eq!(sample(&drained, "dw_workflow_run_duration_seconds_count", "default", &run), Some(1.0));
    assert_eq!(sample(&drained, "dw_workflow_run_duration_seconds_bucket", "default", &[run[0], run[1], ("le", "+Inf")]), Some(1.0));
    let described = request(&app, "GET", &format!("/api/workflows/scale-from-zero/runs/{}", started["run_id"].as_str().unwrap()), Value::Null).await;
    assert_eq!(described.1["status"], "completed", "{}", described.1);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_throttled_queue_keeps_its_growing_backlog_out_of_the_scale_signal_until_it_resumes() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone().with_metrics_token(Some(METRICS_TOKEN.into())));
    register(&app, "capped-worker", json!(["echo"]), json!(["capped_activity"])).await;
    let (status, paused) = request(&app, "POST", "/api/task-queues/test/activity-dispatch/pause", json!({"reason":"vendor cap"})).await;
    assert_eq!(status, StatusCode::OK, "{paused}");
    let mut previous = 0.0;
    for index in 0..3 {
        start(&app, &format!("capped-{index}")).await;
        let task = poll(&app, "capped-worker", "workflow").await;
        assert_eq!(finish_task(&app, &task, schedule("capped_activity")).await.0, StatusCode::OK);
        let held = scraped(&app).await;
        let due = backlog(&held, "dw_task_queue_due_tasks", "activity").unwrap();
        assert!(due > previous, "the backlog grows: {held}");
        previous = due;
        assert_eq!(backlog(&held, "dw_task_queue_dispatchable_tasks", "activity"), Some(0.0), "{held}");
        assert_eq!(sample(&held, "dw_task_queue_admission_state", "default",
            &[("task_queue", "test"), ("task_kind", "activity"), ("state", "throttled")]), Some(1.0));
        assert_eq!(sample(&held, "dw_task_queue_admission_state", "default",
            &[("task_queue", "test"), ("task_kind", "activity"), ("state", "unlimited")]), Some(0.0));
        assert_eq!(sample(&held, "dw_task_queue_admission_remaining_capacity", "default",
            &[("task_queue", "test"), ("task_kind", "activity")]), Some(0.0));
        assert_eq!(sample(&held, "dw_task_queue_activity_dispatch_paused", "default", &[("task_queue", "test")]), Some(1.0));
        // Workflow dispatch is not held.
        assert_eq!(sample(&held, "dw_task_queue_admission_state", "default",
            &[("task_queue", "test"), ("task_kind", "workflow"), ("state", "unlimited")]), Some(1.0));
        // The cap can use no worker: the scale signal stays at zero.
        assert_eq!(desired_replicas(&held, 1.0), 0.0, "{held}");
    }
    assert_eq!(previous, 3.0);

    let (status, resumed) = request(&app, "POST", "/api/task-queues/test/activity-dispatch/resume", json!({})).await;
    assert_eq!(status, StatusCode::OK, "{resumed}");
    let open = scraped(&app).await;
    assert_eq!(backlog(&open, "dw_task_queue_dispatchable_tasks", "activity"), Some(3.0), "{open}");
    assert_eq!(sample(&open, "dw_task_queue_admission_state", "default",
        &[("task_queue", "test"), ("task_kind", "activity"), ("state", "unlimited")]), Some(1.0));
    assert_eq!(sample(&open, "dw_task_queue_admission_remaining_capacity", "default",
        &[("task_queue", "test"), ("task_kind", "activity")]), None);
    assert_eq!(desired_replicas(&open, 1.0), 3.0);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn delayed_retries_are_distinct_from_due_work_and_report_retry_pressure() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone().with_metrics_token(Some(METRICS_TOKEN.into())));
    start(&app, "retry-pressure").await;
    register(&app, "retry-worker", json!(["echo"]), json!(["flaky_activity"])).await;
    let workflow = poll(&app, "retry-worker", "workflow").await;
    let policy = json!({"max_attempts":3,"backoff_seconds":[600],"non_retryable_error_types":[]});
    let (status, body) = finish_task(&app, &workflow, json!([{"type":"schedule_activity","activity_type":"flaky_activity",
        "arguments":envelope(Payload::Array(vec![])),"retry_policy":policy}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let attempt = poll(&app, "retry-worker", "activity").await;
    let (status, body) = request(&app, "POST", &format!("/api/worker/activity-tasks/{}/fail", attempt["task_id"].as_str().unwrap()),
        json!({"lease_owner":"retry-worker","activity_attempt_id":attempt["activity_attempt_id"],
            "failure":{"type":"RuntimeException","message":"flaky","non_retryable":false}})).await;
    assert_eq!(status, StatusCode::OK, "{body}");

    let text = scraped(&app).await;
    // The retry waits for its backoff: ready, not due, not a reason to scale.
    assert_eq!(backlog(&text, "dw_task_queue_ready_tasks", "activity"), Some(1.0), "{text}");
    assert_eq!(backlog(&text, "dw_task_queue_delayed_tasks", "activity"), Some(1.0));
    assert_eq!(backlog(&text, "dw_task_queue_due_tasks", "activity"), Some(0.0));
    assert_eq!(backlog(&text, "dw_task_queue_dispatchable_tasks", "activity"), Some(0.0));
    assert_eq!(backlog(&text, "dw_task_queue_oldest_due_task_age_seconds", "activity"), Some(0.0));
    assert_eq!(sample(&text, "dw_activity_retrying", "default", &[("task_queue", "test")]), Some(1.0));
    assert_eq!(sample(&text, "dw_activity_failed_attempts", "default", &[("task_queue", "test")]), Some(1.0));
    assert!(sample(&text, "dw_activity_oldest_retrying_age_seconds", "default", &[("task_queue", "test")]).is_some_and(|age| age >= 0.0));
    for family in ["task_queues", "activity_retries", "workflows", "activities"] {
        assert_eq!(sample(&text, "dw_metrics_series_truncated", "default", &[("family", family)]), Some(0.0), "{family}");
        assert_eq!(sample(&text, "dw_metrics_series_limit", "default", &[("family", family)]), Some(100.0), "{family}");
    }
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn the_metrics_credential_reads_only_the_scrape_of_its_own_namespace() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone().with_metrics_token(Some(METRICS_TOKEN.into())).with_auth_options(false, true));
    namespaces::create(&app, "team-b").await;
    let (status, _, created) = admission_http(&app, "PUT", "/api/runtime-credentials/metrics-b", false, Some("test-token"), Some("2"), None,
        json!({"token":TENANT_METRICS_TOKEN,"subject":"svc:keda","roles":["metrics"],"tenant":"team-b"})).await;
    assert_eq!(status, StatusCode::CREATED, "{created}");
    start(&app, "default-backlog").await;
    namespaces::start_scoped(&app, "team-b", "team-b-backlog").await;

    // No credential, or a wrong one, is refused before any read.
    assert_eq!(scrape(&app, None, None).await.0, StatusCode::UNAUTHORIZED);
    assert_eq!(scrape(&app, Some("not-a-token"), None).await.0, StatusCode::UNAUTHORIZED);
    // The administrator may scrape. The control-plane header is optional,
    // but a version the server does not serve is still refused.
    assert_eq!(scrape(&app, Some("test-token"), None).await.0, StatusCode::OK);
    assert_eq!(scrape_with(&app, Some(METRICS_TOKEN), None, Some("2")).await.0, StatusCode::OK);
    assert_eq!(scrape_with(&app, Some(METRICS_TOKEN), None, Some("3")).await.0, StatusCode::BAD_REQUEST);

    // The server-wide metrics token reads either namespace, labeled by it.
    let default_text = scraped(&app).await;
    assert_eq!(backlog(&default_text, "dw_task_queue_due_tasks", "workflow"), Some(1.0));
    assert!(!default_text.contains("namespace=\"team-b\""), "{default_text}");
    let (status, _, team_text) = scrape(&app, Some(METRICS_TOKEN), Some("team-b")).await;
    assert_eq!(status, StatusCode::OK, "{team_text}");
    well_formed(&team_text);
    assert_eq!(sample(&team_text, "dw_task_queue_due_tasks", "team-b",
        &[("task_queue", "test"), ("task_kind", "workflow"), ("build_id", "")]), Some(1.0), "{team_text}");
    assert!(!team_text.contains("namespace=\"default\""), "{team_text}");

    // The tenant's metrics credential reads its own namespace only.
    let (status, _, tenant_text) = scrape(&app, Some(TENANT_METRICS_TOKEN), Some("team-b")).await;
    assert_eq!(status, StatusCode::OK, "{tenant_text}");
    assert_eq!(tenant_text.lines().filter(|line| !line.starts_with('#')).count(),
        team_text.lines().filter(|line| !line.starts_with('#')).count());
    assert_eq!(scrape(&app, Some(TENANT_METRICS_TOKEN), None).await.0, StatusCode::FORBIDDEN);
    assert_eq!(scrape(&app, Some(TENANT_METRICS_TOKEN), Some("default")).await.0, StatusCode::FORBIDDEN);

    // Neither metrics credential reaches any other route, read or write.
    for (token, namespace) in [(METRICS_TOKEN, "default"), (TENANT_METRICS_TOKEN, "team-b")] {
        for (method, path, worker) in [
            ("GET", "/api/system/prometheus-metrics", false),
            ("GET", "/api/system/operator-metrics", false),
            ("GET", "/api/system/metrics", false),
            ("GET", "/api/workflows", false),
            ("GET", "/api/task-queues", false),
            ("GET", "/api/cluster/info", false),
            ("GET", "/api/namespaces", false),
            ("GET", "/api/runtime-credentials", false),
            ("POST", "/api/workflows", false),
            ("POST", "/api/task-queues/test/activity-dispatch/pause", false),
            ("POST", "/api/system/repair/pass", false),
            ("POST", "/api/worker/register", true),
            ("POST", "/api/worker/workflow-tasks/poll", true),
        ] {
            let version = if worker { "1.20" } else { "2" };
            let (status, _, body) = admission_http(&app, method, path, worker, Some(token), Some(version), Some(namespace), json!({})).await;
            assert_eq!(status, StatusCode::FORBIDDEN, "{method} {path} as {namespace} metrics: {body}");
        }
    }
    // Nothing was paused or started by those refusals.
    let after = scraped(&app).await;
    assert_eq!(sample(&after, "dw_task_queue_activity_dispatch_paused", "default", &[("task_queue", "test")]), Some(0.0));
    assert_eq!(backlog(&after, "dw_task_queue_due_tasks", "workflow"), Some(1.0));
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_failed_read_is_unavailable_rather_than_an_empty_queue() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone().with_metrics_token(Some(METRICS_TOKEN.into())));
    start(&app, "unavailable-backlog").await;
    assert_eq!(backlog(&scraped(&app).await, "dw_task_queue_due_tasks", "workflow"), Some(1.0));
    // The admission state cannot be read: no sample, no zero, no unlimited.
    database.execute("DROP TABLE dw_task_queue_pauses").await;
    let (status, headers, text) = scrape(&app, Some(METRICS_TOKEN), None).await;
    assert_eq!(status, StatusCode::SERVICE_UNAVAILABLE, "{text}");
    assert_eq!(headers["retry-after"], "5");
    assert!(text.lines().all(|line| line.starts_with('#')), "{text}");
    runtime.close().await;
    database.remove().await;
}
