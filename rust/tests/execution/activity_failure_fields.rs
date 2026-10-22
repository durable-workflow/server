//! Activity failure reports as published PHP 2.5.14 validates and records
//! them. The SDKs send `class` and `stack_trace` with ordinary failures, and
//! may send `details`, diagnostics and classification flags.
use super::*;

/// Fails one activity attempt with `failure` and returns the response and
/// the run's failure event (`ActivityFailed` or `ActivityRetryScheduled`).
async fn fail_with(id: &str, max_attempts: i64, failure: Value) -> ((StatusCode, Value), Value) {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "failure-worker", json!(["echo"]), json!(["act"])).await;
    let started = start(&app, id).await;
    let task = poll(&app, "failure-worker", "workflow").await;
    let scheduled = finish_task(&app, &task, json!([{"type":"schedule_activity","activity_type":"act",
        "arguments":envelope(Payload::Array(vec![])),
        "retry_policy":{"max_attempts":max_attempts,"backoff_seconds":[0]}}])).await;
    assert_eq!(scheduled.0, StatusCode::OK, "{}", scheduled.1);
    let activity = poll(&app, "failure-worker", "activity").await;
    let response = request(&app, "POST",
        &format!("/api/worker/activity-tasks/{}/fail", activity["task_id"].as_str().unwrap()),
        json!({"activity_attempt_id":activity["activity_attempt_id"],"lease_owner":"failure-worker","failure":failure})).await;
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let event = history
        .as_array()
        .unwrap()
        .iter()
        .find(|event| matches!(event["event_type"].as_str(), Some("ActivityFailed" | "ActivityRetryScheduled")))
        .cloned()
        .unwrap_or(Value::Null);
    runtime.close().await;
    database.remove().await;
    (response, event)
}

fn recorded(event: &Value) -> Value {
    let payload = &event["payload"];
    json!({"event_type":event["event_type"],"exception":payload["exception"],"exception_class":payload["exception_class"],
        "exception_type":payload["exception_type"],"message":payload["message"],"code":payload["code"],
        "non_retryable":payload["non_retryable"],"activity_exception":payload["activity"]["exception"]})
}

#[tokio::test]
async fn python_sdk_failures_are_recorded_like_php() {
    let blob = envelope(Payload::Array(vec![]))["blob"].clone();
    // Each expected record is what published PHP 2.5.14 records for the
    // same report.
    let cases = [
        (
            1,
            json!({"message":"boom","type":"RuntimeError","class":"RuntimeError","code":0,
                "stack_trace":"Traceback (most recent call last):\n  File \"a.py\", line 1\nRuntimeError: boom\n"}),
            json!({"event_type":"ActivityFailed","exception":{"type":"RuntimeError","message":"boom","code":0},
                "exception_class":"RuntimeError","exception_type":"RuntimeError","message":"boom","code":0,
                "non_retryable":false,"activity_exception":{"type":"RuntimeError","message":"boom","code":0}}),
        ),
        (
            2,
            json!({"message":"retry me","type":"ValueError","class":"builtins.ValueError","stack_trace":"tb",
                "kind":"application","details":{"codec":"avro","blob":blob}}),
            json!({"event_type":"ActivityRetryScheduled",
                "exception":{"type":"ValueError","message":"retry me","code":0,"details":{"codec":"avro","blob":blob},"details_payload_codec":"avro"},
                "exception_class":"builtins.ValueError","exception_type":"ValueError","message":"retry me","code":0,
                "non_retryable":null,
                "activity_exception":{"type":"ValueError","message":"retry me","code":0,"details":blob,"details_payload_codec":"avro"}}),
        ),
        (
            1,
            json!({"message":" no type ","code":"12","retryable":true,"cancelled":false,"diagnostics":{"k":"v"}}),
            json!({"event_type":"ActivityFailed","exception":{"type":null,"message":"no type","code":0,"diagnostics":{"k":"v"}},
                "exception_class":"RuntimeException","exception_type":null,"message":"no type","code":0,
                "non_retryable":false,"activity_exception":{"type":null,"message":"no type","code":0,"diagnostics":{"k":"v"}}}),
        ),
        (
            1,
            json!({"message":"php sdk","type":"RuntimeException","non_retryable":true,"details":{}}),
            json!({"event_type":"ActivityFailed","exception":{"type":"RuntimeException","message":"php sdk","code":0,"details":[],"non_retryable":true},
                "exception_class":"RuntimeException","exception_type":"RuntimeException","message":"php sdk","code":0,
                "non_retryable":true,"activity_exception":{"type":"RuntimeException","message":"php sdk","code":0,"details":[],"non_retryable":true}}),
        ),
        (
            1,
            json!({"message":"plain","type":"T","details":"plain text"}),
            json!({"event_type":"ActivityFailed","exception":{"type":"T","message":"plain","code":0,"details":{"codec":"avro","blob":"plain text"}},
                "exception_class":"RuntimeException","exception_type":"T","message":"plain","code":0,
                "non_retryable":false,"activity_exception":{"type":"T","message":"plain","code":0,"details":"plain text"}}),
        ),
    ];
    for (index, (max_attempts, failure, expected)) in cases.into_iter().enumerate() {
        let ((status, body), event) = fail_with(&format!("failure-fields-{index}"), max_attempts, failure).await;
        assert_eq!(status, StatusCode::OK, "{index}: {body}");
        assert_eq!(body["recorded"], true, "{index}: {body}");
        assert_eq!(recorded(&event), expected, "{index}");
    }
}

#[tokio::test]
async fn a_policy_names_the_reported_class_or_type_as_non_retryable() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "failure-worker", json!(["echo"]), json!(["act"])).await;
    for (id, failure) in [
        ("by-class", json!({"message":"m","type":"Other","class":"app.Fatal"})),
        ("by-type", json!({"message":"m","type":"app.Fatal","class":"Other"})),
    ] {
        let started = start(&app, id).await;
        let task = poll(&app, "failure-worker", "workflow").await;
        assert_eq!(finish_task(&app, &task, json!([{"type":"schedule_activity","activity_type":"act",
            "arguments":envelope(Payload::Array(vec![])),
            "retry_policy":{"max_attempts":3,"backoff_seconds":[0],"non_retryable_error_types":["app.Fatal"]}}])).await.0, StatusCode::OK);
        let activity = poll(&app, "failure-worker", "activity").await;
        let response = request(&app, "POST",
            &format!("/api/worker/activity-tasks/{}/fail", activity["task_id"].as_str().unwrap()),
            json!({"activity_attempt_id":activity["activity_attempt_id"],"lease_owner":"failure-worker","failure":failure})).await;
        assert_eq!(response.0, StatusCode::OK, "{}", response.1);
        let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
        let failed = history.as_array().unwrap().iter().find(|event| event["event_type"] == "ActivityFailed");
        assert!(failed.is_some_and(|event| event["payload"]["non_retryable"] == true), "{id}: {history}");
        let resumed = poll(&app, "failure-worker", "workflow").await;
        assert_eq!(finish_task(&app, &resumed, json!([{"type":"complete_workflow","result":envelope(Payload::Long(1))}])).await.0, StatusCode::OK);
    }
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn invalid_failure_reports_get_phps_validation_messages() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let blob = envelope(Payload::Array(vec![]))["blob"].clone();
    // Validation runs before the task lookup, as in PHP.
    for (failure, expected) in [
        (json!({"type":"X"}), json!({"failure.message":["The failure.message field is required."]})),
        (json!({"message":""}), json!({"failure.message":["The failure.message field is required."]})),
        (json!({"message":"m","code":"abc"}), json!({"failure.code":["The failure.code field must be an integer."]})),
        (json!({"message":"m","kind":"weird","timeout_type":"x"}), json!({"failure.kind":["The selected failure.kind is invalid."],
            "failure.timeout_type":["The selected failure.timeout type is invalid."]})),
        (json!({"message":"m","type":5,"class":[],"stack_trace":7}), json!({"failure.type":["The failure.type field must be a string."],
            "failure.class":["The failure.class field must be a string."],"failure.stack_trace":["The failure.stack trace field must be a string."]})),
        (json!({"message":"m","non_retryable":"yes","retryable":2,"cancelled":"1"}), json!({
            "failure.non_retryable":["The failure.non retryable field must be true or false."],
            "failure.retryable":["The failure.retryable field must be true or false."]})),
        (json!({"message":"m","diagnostics":"x"}), json!({"failure.diagnostics":["The failure.diagnostics field must be an array."]})),
        (json!("oops"), json!({"failure":["The failure field must be an array."],"failure.message":["The failure.message field is required."]})),
        (json!({"message":"m","details":{"codec":"json","blob":"e30="}}), json!({"failure.details.codec":[
            "unsupported_payload_codec: workflow payload codec \"json\" is not supported by Durable Workflow 2.0; use codec=\"avro\" with the fixed Avro Value schema and single-object framing. JSON remains the HTTP document transport, not a workflow payload codec."]})),
        (json!({"message":"m","details":{"codec":"avro","blob":"!!!"}}), json!({"failure.details.blob":[
            "The payload must contain one complete Avro Value encoded with the official codec. JSON is only the HTTP document transport."]})),
    ] {
        let (status, body) = request(&app, "POST", "/api/worker/activity-tasks/no-such-task/fail",
            json!({"activity_attempt_id":"a","lease_owner":"w","failure":failure})).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{failure}: {body}");
        assert_eq!(body["reason"], "validation_failed");
        assert_eq!(body["errors"], expected, "{failure}");
    }
    // A valid report, with a field PHP ignores, reaches the task lookup.
    let (status, body) = request(&app, "POST", "/api/worker/activity-tasks/no-such-task/fail",
        json!({"activity_attempt_id":"a","lease_owner":"w","failure":{"message":"m","details":{"codec":"avro","blob":blob}},"extra":1})).await;
    assert_eq!(status, StatusCode::NOT_FOUND, "{body}");
    assert_eq!(body["reason"], "task_not_found");
    runtime.close().await;
    database.remove().await;
}
