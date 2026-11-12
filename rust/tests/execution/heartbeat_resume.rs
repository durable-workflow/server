//! Server #1139 (.github#218): a retry attempt claimed by a worker that
//! resumes from heartbeats carries the execution's last heartbeat details.
use super::*;

async fn worker(app: &Router, id: &str, resumes: bool) {
    let capabilities = if resumes { json!(["activity_heartbeat_resume"]) } else { json!([]) };
    let (status, body) = request(app, "POST", "/api/worker/register", json!({"worker_id":id,"task_queue":"test",
        "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":["act"],
        "capabilities":capabilities})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

async fn first_attempt(app: &Router, id: &str, workflow: &str) -> Value {
    start(app, workflow).await;
    let task = poll(app, id, "workflow").await;
    let (status, body) = finish_task(app, &task, json!([{"type":"schedule_activity","activity_type":"act",
        "arguments":envelope(Payload::Array(vec![])),"retry_policy":{"max_attempts":2,"backoff_seconds":[0]}}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    poll(app, id, "activity").await
}

async fn heartbeat_then_fail(app: &Router, activity: &Value, details: Value) {
    let path = |verb: &str| format!("/api/worker/activity-tasks/{}/{verb}", activity["task_id"].as_str().unwrap());
    let (status, body) = request_protocol(app, "POST", &path("heartbeat"), json!({"lease_owner":activity["lease_owner"],
        "activity_attempt_id":activity["activity_attempt_id"],"details":details}), "1.20").await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (status, body) = request(app, "POST", &path("fail"), json!({"lease_owner":activity["lease_owner"],
        "activity_attempt_id":activity["activity_attempt_id"],
        "failure":{"type":"RuntimeException","message":"retry","non_retryable":false}})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
}

#[tokio::test]
async fn a_retry_attempt_resumes_from_the_last_heartbeat() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let (_, cluster) = request(&app, "GET", "/api/cluster/info", Value::Null).await;
    assert_eq!(cluster["worker_protocol"]["server_capabilities"]["activity_heartbeat_resume"], json!({"supported":true,"version":1}));
    worker(&app, "resumer", true).await;
    let first = first_attempt(&app, "resumer", "resume").await;
    assert_eq!(first["last_heartbeat_details"], Value::Null, "{first}");
    heartbeat_then_fail(&app, &first, json!({"cursor":42})).await;
    let retry = poll(&app, "resumer", "activity").await;
    assert_eq!(retry["attempt_number"], 2, "{retry}");
    assert_eq!(retry["last_heartbeat_details"], json!({"cursor":42}), "{retry}");
    assert!(retry["last_heartbeat_at"].is_string(), "{retry}");
}

#[tokio::test]
async fn a_worker_without_the_capability_sees_no_heartbeat_fields() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    worker(&app, "plain", false).await;
    let first = first_attempt(&app, "plain", "plain-resume").await;
    heartbeat_then_fail(&app, &first, json!({"cursor":7})).await;
    let retry = poll(&app, "plain", "activity").await;
    assert_eq!(retry["attempt_number"], 2, "{retry}");
    assert!(retry.get("last_heartbeat_details").is_none(), "{retry}");
}
