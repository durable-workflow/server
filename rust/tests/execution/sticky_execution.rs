//! Server #1017: sticky execution as published PHP Server 2.11.8 runs it.
//! A completion's `sticky_cache` claims the run for its worker; each later
//! workflow claim reports the replay that worker should expect. History is
//! still delivered in full.
use super::*;

async fn sticky_worker(app: &Router, worker: &str) {
    let (status, body) = request(app, "POST", "/api/worker/register", json!({"worker_id":worker,"task_queue":"test",
        "runtime":"php","build_id":"build-1","supported_workflow_types":["echo"],"supported_activity_types":[],
        "capabilities":["sticky_execution"]})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

fn claim(task: &Value, worker: &str, ttl: i64) -> Value {
    json!({"worker_id":worker,"workflow_id":task["workflow_id"],"run_id":task["run_id"],"build_id":"build-1",
        "ttl_seconds":ttl,"metrics":{"hit":0,"miss":1,"eviction":0,"forced_cold_replay":0}})
}

async fn complete_sticky(app: &Router, task: &Value, sticky: Value) -> (StatusCode, Value) {
    let mut body = completion(task, json!([{"type":"open_signal_wait","signal_name":"finish"}]));
    body["sticky_cache"] = sticky;
    request(app, "POST", &format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap()), body).await
}

async fn signal(app: &Router, workflow: &str) {
    let (status, body) = request(app, "POST", &format!("/api/workflows/{workflow}/signal/finish"),
        json!({"input":envelope(Payload::Array(vec![Payload::Long(1)]))})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
}

#[tokio::test]
async fn a_sticky_claim_tells_each_later_claim_which_replay_to_expect() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let (_, cluster) = request(&app, "GET", "/api/cluster/info", Value::Null).await;
    assert_eq!(cluster["worker_protocol"]["server_capabilities"]["sticky_execution"]["supported"], true, "{cluster}");
    sticky_worker(&app, "sticky-a").await;
    sticky_worker(&app, "sticky-b").await;
    start(&app, "sticky").await;

    let first = poll(&app, "sticky-a", "workflow").await;
    assert_eq!(first["sticky_replay_mode"], "cold_replay", "{first}");
    assert_eq!(first["sticky_worker_id"], Value::Null, "{first}");
    // A claim naming another build is refused whole.
    let mut wrong = claim(&first, "sticky-a", 300);
    wrong["build_id"] = json!("build-2");
    let (status, body) = complete_sticky(&app, &first, wrong).await;
    assert_eq!(status, StatusCode::CONFLICT, "{body}");
    assert_eq!(body["reason"], "sticky_cache_identity_mismatch", "{body}");
    assert_eq!(body["capability"], "sticky_execution", "{body}");
    let (status, body) = complete_sticky(&app, &first, claim(&first, "sticky-a", 300)).await;
    assert_eq!(status, StatusCode::OK, "{body}");

    // The sticky worker expects a hit; the full history still arrives.
    signal(&app, "sticky").await;
    let second = poll(&app, "sticky-a", "workflow").await;
    assert_eq!(second["sticky_replay_mode"], "sticky_hit_expected", "{second}");
    assert_eq!(second["sticky_worker_id"], "sticky-a", "{second}");
    assert!(second["sticky_until"].is_string(), "{second}");
    let events = second["history_events"].as_array().unwrap();
    assert!(matches!(events[0]["event_type"].as_str(), Some("StartAccepted" | "WorkflowStarted")), "{second}");
    let (status, body) = complete_sticky(&app, &second, claim(&second, "sticky-a", 300)).await;
    assert_eq!(status, StatusCode::OK, "{body}");

    // Another worker may claim, with a forced cold replay.
    signal(&app, "sticky").await;
    let third = poll(&app, "sticky-b", "workflow").await;
    assert_eq!(third["sticky_replay_mode"], "forced_cold_replay", "{third}");
    let (status, body) = complete_sticky(&app, &third, claim(&third, "sticky-b", 300)).await;
    assert_eq!(status, StatusCode::OK, "{body}");

    let (_, metrics) = request(&app, "GET", "/api/system/operator-metrics", Value::Null).await;
    let sticky = &metrics["operator_metrics"]["sticky_execution"];
    assert_eq!(sticky["enabled"], true, "{sticky}");
    assert_eq!(sticky["active_sticky_runs"], 1, "{sticky}");
    assert_eq!(sticky["hit_expected_last_minute"], 1, "{sticky}");
    assert_eq!(sticky["forced_cold_replay_last_minute"], 1, "{sticky}");
    assert_eq!(sticky["cold_replay_last_minute"], 1, "{sticky}");

    // Re-registering the sticky worker's process drops its affinity.
    sticky_worker(&app, "sticky-b").await;
    signal(&app, "sticky").await;
    let fourth = poll(&app, "sticky-b", "workflow").await;
    assert_eq!(fourth["sticky_replay_mode"], "cold_replay", "{fourth}");
}

#[tokio::test]
async fn an_expired_claim_forces_a_cold_replay() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    sticky_worker(&app, "sticky-a").await;
    start(&app, "expired").await;
    let first = poll(&app, "sticky-a", "workflow").await;
    let (status, body) = complete_sticky(&app, &first, claim(&first, "sticky-a", 1)).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    tokio::time::sleep(std::time::Duration::from_millis(1100)).await;
    signal(&app, "expired").await;
    let second = poll(&app, "sticky-a", "workflow").await;
    assert_eq!(second["sticky_replay_mode"], "forced_cold_replay", "{second}");
}

async fn suffix_worker(app: &Router, worker: &str) {
    let entry = json!({"supported":true,"minimum_protocol_version":"1.18"});
    let (status, body) = request(app, "POST", "/api/worker/register", json!({"worker_id":worker,"task_queue":"test",
        "runtime":"php","build_id":"build-1","supported_workflow_types":["echo"],"supported_activity_types":[],
        "capabilities":["sticky_execution","sticky_history_suffix"],
        "capability_manifest":{"local_activities":{"supported":false,"minimum_protocol_version":"1.18"},
            "worker_sessions":{"supported":false,"minimum_protocol_version":"1.18"},"sticky_execution":entry,
            "sticky_history_suffix":{"supported":true,"minimum_protocol_version":"1.18","version":1}}})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

fn sequences(task: &Value) -> Vec<i64> {
    task["history_events"].as_array().unwrap().iter().map(|event| event["sequence"].as_i64().unwrap()).collect()
}

// Server #1017 part 2: a worker that takes suffixes gets only the events
// after the boundary it reported with its previous completion.
#[tokio::test]
async fn a_suffix_worker_gets_only_the_events_after_its_cached_boundary() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let (_, cluster) = request(&app, "GET", "/api/cluster/info", Value::Null).await;
    assert_eq!(cluster["worker_protocol"]["server_capabilities"]["sticky_history_suffix"], json!({"supported":true,"version":1}));
    suffix_worker(&app, "suffix-a").await;
    start(&app, "suffix").await;

    let first = poll(&app, "suffix-a", "workflow").await;
    assert_eq!(first["history_after_sequence"], 0, "{first}");
    let delivered = sequences(&first);
    assert_eq!(delivered.first(), Some(&1), "{first}");
    assert_eq!(first["history_last_sequence"], json!(delivered.last().unwrap()), "{first}");
    let mut sticky = claim(&first, "suffix-a", 300);
    sticky["history_last_sequence"] = json!(delivered.last().unwrap());
    let (status, body) = complete_sticky(&app, &first, sticky).await;
    assert_eq!(status, StatusCode::OK, "{body}");

    signal(&app, "suffix").await;
    let second = poll(&app, "suffix-a", "workflow").await;
    assert_eq!(second["sticky_replay_mode"], "sticky_hit_expected", "{second}");
    let after = *delivered.last().unwrap();
    assert_eq!(second["history_after_sequence"], after, "{second}");
    let suffix = sequences(&second);
    assert_eq!(suffix.first(), Some(&(after + 1)), "{second}");
    assert!(suffix.windows(2).all(|pair| pair[1] == pair[0] + 1), "{suffix:?}");
    assert_eq!(second["history_last_sequence"], json!(suffix.last().unwrap()), "{second}");
}

#[tokio::test]
async fn only_suffix_workers_report_or_receive_a_boundary() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    sticky_worker(&app, "plain").await;
    start(&app, "plain-suffix").await;
    let first = poll(&app, "plain", "workflow").await;
    assert!(first.get("history_after_sequence").is_none(), "{first}");
    let mut sticky = claim(&first, "plain", 300);
    sticky["history_last_sequence"] = json!(3);
    let (status, body) = complete_sticky(&app, &first, sticky).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    assert_eq!(body["reason"], "sticky_history_suffix_not_supported", "{body}");
}
