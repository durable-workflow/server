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

async fn heartbeat(app: &Router, activity: &Value, details: Option<Value>) -> Value {
    let path = format!(
        "/api/worker/activity-tasks/{}/heartbeat",
        activity["task_id"].as_str().unwrap()
    );
    let mut request = json!({
        "lease_owner": activity["lease_owner"],
        "activity_attempt_id": activity["activity_attempt_id"],
    });
    if let Some(details) = details {
        request["details"] = details;
    }
    let (status, body) = request_protocol(app, "POST", &path, request, "1.20").await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["heartbeat_recorded"], true, "{body}");
    body
}

async fn fail_attempt(app: &Router, activity: &Value) {
    let path = format!(
        "/api/worker/activity-tasks/{}/fail",
        activity["task_id"].as_str().unwrap()
    );
    let (status, body) = request(
        app,
        "POST",
        &path,
        json!({
            "lease_owner": activity["lease_owner"],
            "activity_attempt_id": activity["activity_attempt_id"],
            "failure": {"type": "RuntimeException", "message": "retry", "non_retryable": false},
        }),
    )
    .await;
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
    heartbeat(&app, &first, Some(json!({"cursor":42}))).await;
    fail_attempt(&app, &first).await;
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
    heartbeat(&app, &first, Some(json!({"cursor":7}))).await;
    fail_attempt(&app, &first).await;
    let retry = poll(&app, "plain", "activity").await;
    assert_eq!(retry["attempt_number"], 2, "{retry}");
    assert!(retry.get("last_heartbeat_details").is_none(), "{retry}");
}

#[tokio::test]
async fn a_busy_run_does_not_hide_an_activity_checkpoint_from_its_retry() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    worker(&app, "resumer", true).await;
    let first = first_attempt(&app, "resumer", "busy-resume").await;
    let run_id: ulid::Ulid = first["run_id"].as_str().unwrap().parse().unwrap();

    heartbeat(&app, &first, Some(json!({"cursor": 41}))).await;
    let details = json!({"cursor": 42, "items": [true, null, "42"]});
    let acknowledged = heartbeat(&app, &first, Some(details.clone())).await;
    heartbeat(&app, &first, None).await;
    fail_attempt(&app, &first).await;

    // Seed unrelated heartbeat events in one statement. The target checkpoint
    // and retry use the public worker endpoints and normal claim transaction.
    let later_heartbeats = 1_000;
    let payload = json!({
        "activity_execution_id": "sibling-activity",
        "progress": {"details": {"cursor": 99}},
        "heartbeat_at": "2026-01-01T00:00:00Z"
    });
    let values = (1..=later_heartbeats)
        .map(|offset| {
            format!(
                "('checkpoint-sibling-{offset:04}','{run_id}',(SELECT last_history_sequence \
                 FROM workflow_runs WHERE id='{run_id}')+{offset},'ActivityHeartbeatRecorded','{payload}')"
            )
        })
        .collect::<Vec<_>>()
        .join(",");
    database
        .execute(sqlx::AssertSqlSafe(format!(
            "INSERT INTO workflow_history_events(id,workflow_run_id,sequence,event_type,payload) VALUES {values}"
        )))
        .await;
    database
        .execute(sqlx::AssertSqlSafe(format!(
            "UPDATE workflow_runs SET last_history_sequence=last_history_sequence+{later_heartbeats} WHERE id='{run_id}'"
        )))
        .await;

    let retry = poll(&app, "resumer", "activity").await;
    assert_eq!(retry["attempt_number"], 2, "{retry}");
    assert_eq!(retry["activity_execution_id"], first["activity_execution_id"]);
    assert_ne!(retry["activity_attempt_id"], first["activity_attempt_id"]);
    assert_eq!(retry["last_heartbeat_details"], details, "{retry}");
    let instant =
        |value: &Value| chrono::DateTime::parse_from_rfc3339(value.as_str().unwrap()).unwrap();
    assert_eq!(
        instant(&retry["last_heartbeat_at"]),
        instant(&acknowledged["last_heartbeat_at"])
    );
}
