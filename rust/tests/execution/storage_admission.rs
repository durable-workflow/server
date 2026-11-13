//! Server #951: storage admission over HTTP. The operator's observation is
//! re-read on every check; draining keeps existing leases acknowledging and
//! heartbeating while new work is refused, fenced keeps only reads, and
//! untrusted evidence is fenced.
use super::*;
use durable_workflow_server::runtime::storage_admission::Settings;
use std::os::unix::fs::PermissionsExt;

/// The test's freshness bound: long enough that a slow CI database never ages
/// fresh evidence mid-test, and the stale case publishes just past it.
const MAX_AGE_SECONDS: i64 = 300;

struct Observation {
    _directory: tempfile::TempDir,
    path: std::path::PathBuf,
}

impl Observation {
    fn new() -> Self {
        let directory = tempfile::tempdir().unwrap();
        let path = directory.path().join("pressure.json");
        Self { _directory: directory, path }
    }

    fn settings(&self) -> Settings {
        Settings { file: Some(self.path.to_str().unwrap().to_owned()), source: Some("cell-a".into()), max_age_seconds: MAX_AGE_SECONDS }
    }

    /// The producer's atomic replacement.
    fn publish(&self, state: &str, age: i64) {
        let observed_at = chrono::Utc::now().timestamp() - age;
        let staged = self.path.with_extension("tmp");
        std::fs::write(&staged, format!(
            r#"{{"schema":"durable-workflow.storage-pressure.v1","source":"cell-a","observed_at":{observed_at},"state":"{state}","usage":{{}}}}"#)).unwrap();
        std::fs::set_permissions(&staged, std::fs::Permissions::from_mode(0o644)).unwrap();
        std::fs::rename(&staged, &self.path).unwrap();
    }
}

/// A request with its Retry-After header.
async fn raw(app: &Router, method: &str, path: &str, body: Value) -> (StatusCode, Option<String>, Value) {
    let worker = path.starts_with("/api/worker/");
    let request = Request::builder().method(method).uri(path)
        .header("authorization", "Bearer test-token").header("content-type", "application/json").header("x-namespace", "default")
        .header(if worker { "x-durable-workflow-protocol-version" } else { "x-durable-workflow-control-plane-version" },
            if worker { "1.20" } else { "2" })
        .body(Body::from(body.to_string())).unwrap();
    let response = app.clone().oneshot(request).await.unwrap();
    let status = response.status();
    let retry = response.headers().get("retry-after").and_then(|value| value.to_str().ok()).map(str::to_owned);
    let bytes = to_bytes(response.into_body(), 4 * 1024 * 1024).await.unwrap();
    (status, retry, serde_json::from_slice(&bytes).unwrap_or(Value::Null))
}

fn assert_refused(response: &(StatusCode, Option<String>, Value), reason: &str, state: &str) {
    assert_eq!((response.0, response.1.as_deref()), (StatusCode::SERVICE_UNAVAILABLE, Some("5")), "{}", response.2);
    assert_eq!((response.2["reason"].clone(), response.2["storage_state"].clone(), response.2["request_admitted"].clone()),
        (json!(reason), json!(state), json!(false)), "{}", response.2);
    assert!(!response.2.to_string().contains("pressure.json") && !response.2.to_string().contains("cell-a"), "{}", response.2);
}

fn activity_result(task: &Value) -> Value {
    json!({"lease_owner":task["lease_owner"],"activity_attempt_id":task["activity_attempt_id"],"result":envelope(Payload::Long(7))})
}

#[tokio::test]
async fn draining_keeps_leased_work_alive_and_refuses_new_work() {
    let observation = Observation::new();
    observation.publish("normal", 0);
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap().with_storage_admission(observation.settings());
    let app = router(runtime.clone());
    register(&app, "worker", json!(["echo"]), json!(["a"])).await;
    let started = start(&app, "pressured").await;
    let task = poll(&app, "worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"schedule_activity","activity_type":"a",
        "arguments":envelope(Payload::Array(vec![]))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let activity = poll(&app, "worker", "activity").await;
    assert!(activity["task_id"].is_string(), "{activity}");

    observation.publish("draining", 0);
    let before = table_counts(&database).await;
    // New work is refused before its handler, with the poll shape for polls.
    assert_refused(&raw(&app, "POST", "/api/workflows", json!({"workflow_id":"refused","workflow_type":"echo","task_queue":"test"})).await,
        "storage_pressure", "draining");
    assert_refused(&raw(&app, "POST", "/api/workflows/pressured/signal/s", json!({})).await, "storage_pressure", "draining");
    assert_refused(&raw(&app, "POST", "/api/external-payloads/v1", json!({})).await, "storage_pressure", "draining");
    let polled = raw(&app, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"worker","task_queue":"test","timeout_seconds":0,"poll_request_id":"poll-1"})).await;
    assert_refused(&polled, "storage_pressure", "draining");
    assert_eq!((polled.2["task"].clone(), polled.2["claim_admitted"].clone(), polled.2["poll_request_id"].clone(),
        polled.2["retry_same_poll_request_id"].clone()), (Value::Null, json!(false), json!("poll-1"), json!(true)), "{}", polled.2);
    assert_eq!(polled.2["protocol_version"], "1.20", "worker envelope: {}", polled.2);
    assert_eq!(table_counts(&database).await, before, "a refusal writes nothing");
    // Reads stay open.
    assert_eq!(raw(&app, "GET", "/api/workflows/pressured", Value::Null).await.0, StatusCode::OK);
    // The leased activity keeps its lease alive while it holds its result.
    let beat = raw(&app, "POST", &format!("/api/worker/activity-tasks/{}/heartbeat", activity["task_id"].as_str().unwrap()),
        json!({"lease_owner":activity["lease_owner"],"activity_attempt_id":activity["activity_attempt_id"]})).await;
    assert_eq!(beat.0, StatusCode::OK, "{}", beat.2);

    // Pressure clears; the held result completes exactly once.
    observation.publish("normal", 0);
    let path = format!("/api/worker/activity-tasks/{}/complete", activity["task_id"].as_str().unwrap());
    let completed = raw(&app, "POST", &path, activity_result(&activity)).await;
    assert_eq!(completed.0, StatusCode::OK, "{}", completed.2);
    // A retry after the outcome is recorded adds nothing.
    let retried = raw(&app, "POST", &path, activity_result(&activity)).await;
    assert!(retried.0.is_client_error() || retried.0.is_success(), "{}", retried.2);
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(history.as_array().unwrap().iter().filter(|event| event["event_type"] == "ActivityCompleted").count(), 1, "{history}");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn draining_admits_the_completion_itself() {
    let observation = Observation::new();
    observation.publish("normal", 0);
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap().with_storage_admission(observation.settings());
    let app = router(runtime.clone());
    register(&app, "worker", json!(["echo"]), json!(["a"])).await;
    start(&app, "drained-completion").await;
    let task = poll(&app, "worker", "workflow").await;
    observation.publish("draining", 0);
    // An existing workflow task may still complete into the reserve.
    let (status, body) = finish_task(&app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn fenced_and_untrusted_evidence_keep_only_reads() {
    let observation = Observation::new();
    observation.publish("normal", 0);
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap().with_storage_admission(observation.settings());
    let app = router(runtime.clone());
    register(&app, "worker", json!(["echo"]), json!(["a"])).await;
    start(&app, "fenced").await;
    let task = poll(&app, "worker", "workflow").await;

    observation.publish("fenced", 0);
    let path = format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap());
    assert_refused(&raw(&app, "POST", &path, completion(&task, json!([{"type":"complete_workflow"}]))).await, "storage_pressure", "fenced");
    let history = raw(&app, "POST", &format!("/api/worker/workflow-tasks/{}/history", task["task_id"].as_str().unwrap()),
        json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"]})).await;
    assert_ne!(history.0, StatusCode::SERVICE_UNAVAILABLE, "{}", history.2);
    assert_eq!(raw(&app, "GET", "/api/workflows/fenced", Value::Null).await.0, StatusCode::OK);

    // A stale observation is unavailable, never healthy, and readiness says so
    // while health keeps its database meaning.
    observation.publish("normal", MAX_AGE_SECONDS + 1);
    assert_refused(&raw(&app, "POST", &path, completion(&task, json!([{"type":"complete_workflow"}]))).await,
        "storage_admission_unavailable", "fenced");
    let ready = raw(&app, "GET", "/api/ready", Value::Null).await;
    assert_eq!((ready.0, ready.2["status"].clone()), (StatusCode::SERVICE_UNAVAILABLE, json!("not_ready")), "{}", ready.2);
    assert_eq!(ready.2["checks"]["storage_admission"],
        json!({"enabled":true,"state":"fenced","reason":"storage_admission_unavailable","observed_at":null}));
    assert_eq!(raw(&app, "GET", "/api/health", Value::Null).await.0, StatusCode::OK);

    // A fresh normal observation restores admission at once.
    observation.publish("normal", 0);
    assert_eq!(raw(&app, "GET", "/api/ready", Value::Null).await.0, StatusCode::OK);
    let (status, body) = finish_task(&app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_waiting_poll_rechecks_before_its_next_probe() {
    let observation = Observation::new();
    observation.publish("normal", 0);
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap().with_storage_admission(observation.settings());
    let app = router(runtime.clone());
    register(&app, "worker", json!(["echo"]), json!(["a"])).await;
    let waiting = {
        let app = app.clone();
        tokio::spawn(async move {
            raw(&app, "POST", "/api/worker/workflow-tasks/poll",
                json!({"worker_id":"worker","task_queue":"test","timeout_seconds":5,"poll_request_id":"waiting"})).await
        })
    };
    tokio::time::sleep(std::time::Duration::from_millis(300)).await;
    observation.publish("draining", 0);
    let answer = tokio::time::timeout(std::time::Duration::from_secs(3), waiting).await
        .expect("the waiting poll answers within a probe interval").unwrap();
    assert_eq!(answer.0, StatusCode::SERVICE_UNAVAILABLE, "{}", answer.2);
    assert_eq!((answer.2["reason"].clone(), answer.2["task"].clone(), answer.2["claim_admitted"].clone(), answer.2["poll_request_id"].clone()),
        (json!("storage_pressure"), Value::Null, json!(false), json!("waiting")), "{}", answer.2);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn admission_is_off_without_settings() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let ready = raw(&app, "GET", "/api/ready", Value::Null).await;
    assert_eq!(ready.0, StatusCode::OK, "{}", ready.2);
    assert!(ready.2["checks"].get("storage_admission").is_none(), "{}", ready.2);
    start(&app, "unobserved").await;
    runtime.close().await;
    database.remove().await;
}
