use super::*;

// `record_local_activity` as published Server 2.11.1 records it (the
// record-local-activity parity fixture holds the PHP recordings).

const EMPTY: &str = "wwHioz3/VYAiNwwA";
const DONE: &str = "wwHioz3/VYAiNwoIZG9uZQ==";

async fn local_worker(app: &Router, worker: &str, capabilities: Value) {
    let (status, body) = request_protocol(app, "POST", "/api/worker/register", json!({"worker_id": worker,
        "task_queue": "test", "runtime": "python", "supported_workflow_types": ["echo"], "supported_activity_types": [],
        "capabilities": capabilities}), "1.20").await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

async fn complete(app: &Router, task: &Value, commands: Value, protocol: &str) -> (StatusCode, Value) {
    let path = format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap());
    request_protocol(app, "POST", &path, completion(task, commands), protocol).await
}

fn record(fields: Value) -> Value {
    let mut command = json!({"type": "record_local_activity", "activity_type": "local.echo",
        "arguments": {"codec": "avro", "blob": EMPTY}, "result": {"codec": "avro", "blob": DONE}, "outcome": "completed",
        "attempts": [{"attempt_id": "w-1", "attempt_number": 1, "outcome": "completed", "duration_ms": 25}]});
    for (key, value) in fields.as_object().unwrap() {
        command[key] = value.clone();
    }
    command
}

fn wait() -> Value {
    json!({"type": "open_signal_wait", "signal_name": "resume"})
}

fn instant(value: &Value) -> chrono::DateTime<chrono::FixedOffset> {
    chrono::DateTime::parse_from_rfc3339(value.as_str().unwrap_or_else(|| panic!("a time: {value}"))).unwrap()
}

#[tokio::test]
async fn advertised_contract_and_capability_gates() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let (status, cluster) = request(&app, "GET", "/api/cluster/info", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{cluster}");
    let capabilities = &cluster["worker_protocol"]["server_capabilities"];
    assert_eq!(capabilities["local_activities"]["supported"], true);
    assert_eq!(capabilities["local_activities"]["command"]["attempt_reports"]["required_from_protocol_version"], "1.19");
    assert!(capabilities["supported_workflow_task_commands"].as_array().unwrap().contains(&json!("record_local_activity")));
    // Prepared local activities are not served: no groups and no policies.
    assert_eq!((&capabilities["prepared_local_activities"], &capabilities["prepared_local_activity_groups"],
        &capabilities["prepared_local_activity_cancellation_policies"]), (&json!(false), &json!(false), &json!([])));

    local_worker(&app, "plain", json!([])).await;
    start(&app, "local-gates").await;
    let task = poll(&app, "plain", "workflow").await;
    // Ownership first, then the protocol floor, then the capability.
    let mut foreign = task.clone();
    foreign["lease_owner"] = json!("someone-else");
    let invalid = record(json!({"non_retryable": false}));
    let (status, body) = complete(&app, &foreign, json!([invalid]), "1.17").await;
    assert_eq!((status, &body["reason"]), (StatusCode::CONFLICT, &json!("lease_owner_mismatch")), "{body}");
    let (status, body) = complete(&app, &task, json!([record(json!({})), wait()]), "1.17").await;
    assert_eq!(status, StatusCode::CONFLICT, "{body}");
    assert_eq!(body["reason"], "portable_worker_affinity_protocol_floor_unavailable");
    assert_eq!((&body["capability"], &body["requested_version"], &body["minimum_protocol_version"]),
        (&json!("local_activities"), &json!("1.17"), &json!("1.18")));
    let (status, body) = complete(&app, &task, json!([record(json!({})), wait()]), "1.20").await;
    assert_eq!((status, &body["reason"]), (StatusCode::CONFLICT, &json!("worker_capability_not_supported")), "{body}");
    assert_eq!((&body["outcome"], &body["recorded"]), (&json!("rejected"), &json!(false)));
    // The claim survives every refusal.
    let (status, body) = complete(&app, &task, json!([wait()]), "1.20").await;
    assert_eq!(status, StatusCode::OK, "{body}");
}

#[tokio::test]
async fn normalizer_refusals_follow_ownership_and_name_php_fields() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    local_worker(&app, "local", json!(["local_activities"])).await;
    let run = start(&app, "local-refusals").await;
    let task = poll(&app, "local", "workflow").await;
    let report = record(json!({"non_retryable": false, "result": null, "schedule_to_start_timeout": 5,
        "attempts": [{"attempt_number": 2, "outcome": "failed", "extra": 1}]}));
    let (status, body) = complete(&app, &task, json!([report]), "1.20").await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    assert_eq!(body["message"], "Workflow task command field [schedule_to_start_timeout] is not valid on a record_local_activity command. schedule_to_start_timeout limits queue wait before an activity attempt starts and only applies to a schedule_activity command. (and 6 more errors)");
    assert_eq!(body["errors"]["commands.0.attempts.0.extra"], json!(["Field [extra] is not supported in a local activity attempt report."]));
    let mut missing = record(json!({}));
    missing.as_object_mut().unwrap().remove("attempts");
    let (status, body) = complete(&app, &task, json!([missing.clone(), wait()]), "1.19").await;
    assert_eq!((status, &body["message"]), (StatusCode::UNPROCESSABLE_ENTITY, &json!("Local activity attempts must be a non-empty ordered list.")));
    // Protocol 1.18 makes the one terminal attempt itself.
    let (status, body) = complete(&app, &task, json!([missing, wait()]), "1.18").await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let events = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let started = events.as_array().unwrap().iter().find(|event| event["event_type"] == "ActivityStarted").unwrap();
    assert_eq!(started["payload"]["worker_attempt_id"], Value::Null);
    assert!(started["payload"]["activity_attempt"].get("worker_attempt_id").is_none(), "{started}");
}

#[tokio::test]
async fn a_recorded_local_activity_writes_its_attempts_history_and_failure() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    local_worker(&app, "local", json!(["local_activities"])).await;
    let run = start(&app, "local-recorded").await;
    let task = poll(&app, "local", "workflow").await;
    let retried = record(json!({"arguments": EMPTY, "payload_codec": "avro", "queue": "ignored", "connection": "ignored",
        "retry_policy": {"max_attempts": 2, "backoff_seconds": [1]}, "start_to_close_timeout": 300,
        "attempts": [
            {"attempt_id": "w-1", "attempt_number": 1, "outcome": "failed", "duration_ms": 1500, "message": " first ",
                "exception_type": "RuntimeException", "retry_reason": "failure", "backoff_seconds": 1,
                "heartbeats": [{"elapsed_ms": 500, "details": {}}]},
            {"attempt_id": "w-2", "attempt_number": 2, "outcome": "completed", "duration_ms": 200}]}));
    let failed = record(json!({"activity_type": "local.fatal", "outcome": "failed", "result": null, "message": "gave up",
        "exception_type": "App\\Fatal", "non_retryable": true,
        "attempts": [{"attempt_number": 1, "outcome": "failed", "message": "gave up", "non_retryable": true}]}));
    let timed_out = record(json!({"activity_type": "local.slow", "outcome": "timed_out", "result": null, "message": "late",
        "timeout_kind": "heartbeat", "attempts": [{"attempt_number": 1, "outcome": "timed_out", "message": "late",
            "timeout_kind": "heartbeat", "duration_ms": 10}]}));
    let (status, body) = complete(&app, &task, json!([retried, failed, timed_out, wait()]), "1.20").await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!((&body["recorded"], &body["run_status"], &body["created_task_ids"]), (&json!(true), &json!("waiting"), &json!([])));

    let events = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let events = events.as_array().unwrap();
    let types: Vec<_> = events.iter().map(|event| event["event_type"].as_str().unwrap()).collect();
    assert_eq!(types[2..], ["ActivityScheduled", "ActivityStarted", "ActivityHeartbeatRecorded", "ActivityRetryScheduled",
        "ActivityStarted", "ActivityCompleted", "ActivityScheduled", "ActivityStarted", "ActivityFailed", "ActivityScheduled",
        "ActivityStarted", "ActivityTimedOut", "SignalWaitOpened"]);
    let payload = |index: usize| &events[index]["payload"];
    for index in 2..14 {
        assert_eq!((&payload(index)["execution_mode"], &payload(index)["local_activity"]), (&json!("local"), &json!(true)));
        assert_eq!(payload(index)["workflow_task_id"], task["task_id"]);
    }
    // The run's queue, not the command's; the sequences the commands consume.
    assert_eq!(payload(2)["activity"]["queue"], "test");
    assert_eq!((&payload(2)["sequence"], &payload(8)["sequence"], &payload(11)["sequence"]), (&json!(1), &json!(2), &json!(3)));
    // Attempt identities are uppercase and made last attempt first.
    let first = payload(3)["activity_attempt_id"].as_str().unwrap();
    let second = payload(6)["activity_attempt_id"].as_str().unwrap();
    assert!(first.len() == 26 && first == first.to_uppercase() && first > second, "{first} {second}");
    // Times walk back from the close by durations and the backoff.
    let close = instant(&payload(7)["activity"]["closed_at"]);
    let offset = |value: &Value| (instant(value) - close).num_milliseconds();
    assert_eq!(offset(&payload(6)["activity_attempt"]["started_at"]), -200);
    assert_eq!(offset(&payload(5)["activity_attempt"]["closed_at"]), -1200);
    assert_eq!(offset(&payload(3)["activity_attempt"]["started_at"]), -2700);
    assert_eq!(offset(&payload(4)["heartbeat_at"]), -2200);
    assert_eq!(payload(4)["progress"], json!([]), "empty details are PHP's empty array");
    assert_eq!((&payload(5)["message"], &payload(5)["retry_backoff_seconds"]), (&json!("first"), &json!(1)));
    assert_eq!(payload(7)["result"], json!({"codec": "avro", "blob": DONE}));
    assert_eq!(payload(7)["activity"]["retry_policy"], json!({"max_attempts": 2, "backoff_seconds": [1]}));
    assert!(payload(10)["failure_id"].is_string(), "{}", payload(10));
    assert_eq!((&payload(10)["failure_category"], &payload(10)["exception_type"], &payload(10)["non_retryable"]),
        (&json!("application"), &json!("App\\Fatal"), &json!(true)));
    assert!(payload(10)["activity"]["exception"].is_string(), "the serialized message: {}", payload(10));
    assert_eq!((&payload(13)["failure_category"], &payload(13)["timeout_kind"], &payload(13)["exception_class"]),
        (&json!("timeout"), &json!("heartbeat"), &Value::Null));

    let (status, view) = request(&app, "GET", &format!("/api/workflows/local-recorded/runs/{}/activities",
        run["run_id"].as_str().unwrap()), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{view}");
    let activities = view["activities"].as_array().unwrap();
    assert_eq!(activities.len(), 3);
    assert_eq!((&activities[0]["status"], &activities[0]["local_activity"], &activities[0]["attempt_count"]),
        (&json!("completed"), &json!(true), &json!(2)));
    assert_eq!(activities[0]["attempts"][0]["worker_attempt_id"], "w-1");
    assert_eq!(activities[0]["attempts"][0]["failure_reason"], Value::Null);
    assert_eq!(activities[2]["status"], "failed");
    // Closed local activities leave the run waiting on its signal.
    let (status, described) = request(&app, "GET", &format!("/api/workflows/local-recorded/runs/{}",
        run["run_id"].as_str().unwrap()), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{described}");
    assert_eq!((&described["status"], &described["wait_kind"]), (&json!("waiting"), &json!("signal")), "{described}");
}
