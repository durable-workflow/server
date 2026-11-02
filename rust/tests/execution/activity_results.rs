use super::*;

async fn scheduled(app: &Router, namespace: &str, name: &str) -> (Value, Value, String) {
    scheduled_with_attempts(app, namespace, name, 1).await
}

async fn scheduled_with_attempts(
    app: &Router,
    namespace: &str,
    name: &str,
    attempts: i64,
) -> (Value, Value, String) {
    let call = |method, path: String, body| async move {
        namespaces::scoped(app, namespace, method, &path, body).await
    };
    let registered = call(
        "POST",
        "/api/worker/register".into(),
        json!({
            "worker_id":"result-worker","task_queue":"test","runtime":"php",
            "supported_workflow_types":["echo"],"supported_activity_types":["result-activity"]
        }),
    )
    .await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    let started = namespaces::start_scoped(app, namespace, name).await;
    let workflow = call(
        "POST",
        "/api/worker/workflow-tasks/poll".into(),
        json!({
            "worker_id":"result-worker","task_queue":"test","timeout_seconds":0
        }),
    )
    .await
    .1["task"]
        .clone();
    let done = call("POST", format!("/api/worker/workflow-tasks/{}/complete", workflow["task_id"].as_str().unwrap()),
        completion(&workflow, json!([{"type":"schedule_activity","activity_type":"result-activity",
            "arguments":envelope(Payload::Array(vec![])),"retry_policy":{"max_attempts":attempts,"backoff_seconds":[]},
            "start_to_close_timeout":120,"schedule_to_close_timeout":600}]))).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    let activity = call(
        "POST",
        "/api/worker/activity-tasks/poll".into(),
        json!({
            "worker_id":"result-worker","task_queue":"test","timeout_seconds":0
        }),
    )
    .await
    .1["task"]
        .clone();
    let path = format!(
        "/api/workflows/{name}/runs/{}/activities/{}/result",
        started["run_id"].as_str().unwrap(),
        activity["activity_execution_id"].as_str().unwrap()
    );
    (started, activity, path)
}

async fn finish_activity(app: &Router, namespace: &str, task: &Value, result: Value) {
    let response = namespaces::scoped(app, namespace, "POST",
        &format!("/api/worker/activity-tasks/{}/complete", task["task_id"].as_str().unwrap()),
        json!({"lease_owner":task["lease_owner"],"activity_attempt_id":task["activity_attempt_id"],"result":result})).await;
    assert_eq!(response.0, StatusCode::OK, "{}", response.1);
}

#[tokio::test]
async fn activity_result_read_preserves_encoded_values_and_closed_parent_history() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    for (index, value) in [
        Payload::Null,
        Payload::Boolean(false),
        Payload::Long(9007199254740993),
        Payload::String("日本語 λ".into()),
    ]
    .into_iter()
    .enumerate()
    {
        let name = format!("activity-result-{index}");
        let (started, task, path) = scheduled(&app, "default", &name).await;
        let pending = request(&app, "GET", &path, Value::Null).await;
        assert_eq!(pending.0, StatusCode::OK, "{}", pending.1);
        assert_eq!(pending.1["state"], "pending");
        assert_eq!(pending.1["available"], false);
        assert!(pending.1.get("result_envelope").is_none());
        let result = envelope(value);
        finish_activity(&app, "default", &task, result.clone()).await;
        let parent = poll(&app, "result-worker", "workflow").await;
        assert_eq!(
            finish_task(
                &app,
                &parent,
                json!([{"type":"complete_workflow",
            "result":envelope(Payload::String("separate parent output".into()))}])
            )
            .await
            .0,
            StatusCode::OK
        );
        let before = run_history(&app, &json!(name), &started["run_id"]).await;
        let read = request(&app, "GET", &path, Value::Null).await;
        assert_eq!(read.0, StatusCode::OK, "{}", read.1);
        assert_eq!(read.1["workflow_id"], name);
        assert_eq!(read.1["run_id"], started["run_id"]);
        assert_eq!(read.1["activity_id"], task["activity_execution_id"]);
        assert_eq!(read.1["history_sequence"], 5);
        assert_eq!(read.1["state"], "available");
        assert_eq!(read.1["available"], true);
        assert_eq!(read.1["reason"], Value::Null);
        assert_eq!(read.1["result_envelope"], result);
        database
            .execute("UPDATE activity_executions SET result='mutable-row-is-not-authority'")
            .await;
        assert_eq!(request(&app, "GET", &path, Value::Null).await, read);
        assert_eq!(
            run_history(&app, &json!(name), &started["run_id"]).await,
            before
        );
    }
    runtime.close().await;
    let reopened = database.open().await.unwrap();
    // The read matches published PHP (cluster-capabilities fixture), so the
    // capability Waterline waits on is advertised.
    let info = request(&router(reopened.clone()), "GET", "/api/cluster/info", Value::Null).await.1;
    assert_eq!(info["capabilities"]["workflow_activity_result_inspection"], json!(true), "{info}");
    assert_eq!(info["capabilities"]["response_compression"], json!([]), "{info}");
    assert_eq!(info["structural_limits"]["pending_signal_count"], json!(5000), "{info}");
    assert_eq!(info["discovery"]["task_repair_path"], json!("/api/system/repair"), "{info}");
    assert!(info["native_unqualified"]["topology"].is_string(), "{info}");
    assert!(info.get("supported_sdk_versions").is_none(), "{info}");
    reopened.close().await;
    database.remove().await;
}

#[tokio::test]
async fn activity_result_read_fences_exact_namespace_workflow_run_activity_and_role() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    namespaces::create(&app, "result-alpha").await;
    namespaces::create(&app, "result-beta").await;
    let (started, task, path) = scheduled(&app, "result-alpha", "same-result-workflow").await;
    finish_activity(
        &app,
        "result-alpha",
        &task,
        envelope(Payload::Long(9007199254740993)),
    )
    .await;
    let other = scheduled(&app, "result-beta", "other-result-workflow").await;
    for (namespace, candidate, reason) in [
        ("default", path.clone(), "run_not_found"),
        ("result-beta", path.clone(), "run_not_found"),
        (
            "result-alpha",
            path.replace("same-result-workflow", "Same-result-workflow"),
            "run_not_found",
        ),
        (
            "result-alpha",
            path.replace(
                started["run_id"].as_str().unwrap(),
                other.0["run_id"].as_str().unwrap(),
            ),
            "run_not_found",
        ),
        (
            "result-alpha",
            path.replace(
                task["activity_execution_id"].as_str().unwrap(),
                other.1["activity_execution_id"].as_str().unwrap(),
            ),
            "activity_not_found",
        ),
        (
            "result-alpha",
            path.replace(
                task["activity_execution_id"].as_str().unwrap(),
                &task["activity_execution_id"]
                    .as_str()
                    .unwrap()
                    .to_lowercase(),
            ),
            "activity_not_found",
        ),
    ] {
        let read = namespaces::scoped(&app, namespace, "GET", &candidate, Value::Null).await;
        assert_eq!(read.0, StatusCode::NOT_FOUND, "{read:?}");
        assert_eq!(read.1["reason"], reason);
    }
    let configured = router(runtime.clone().with_role_tokens(
        Some("worker-only".into()),
        Some("operator-only".into()),
        Some("admin-only".into()),
    ));
    for token in ["worker-only", "operator-only", "admin-only"] {
        let read = admission::admission_http(
            &configured,
            "GET",
            &path,
            false,
            Some(token),
            Some(if token == "worker-only" {
                "bad-version"
            } else {
                "2"
            }),
            Some(if token == "worker-only" {
                "unknown-namespace"
            } else {
                "result-alpha"
            }),
            Value::Null,
        )
        .await;
        assert_eq!(
            read.0,
            if token == "worker-only" {
                StatusCode::FORBIDDEN
            } else {
                StatusCode::OK
            },
            "{}",
            read.2
        );
        if token == "worker-only" {
            assert_eq!(read.2["reason"], "forbidden");
            assert!(read.2.get("namespace").is_none());
        } else {
            assert_eq!(read.2["state"], "available");
        }
    }
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn activity_result_read_refuses_mutable_row_fallback_and_marks_pruned_details() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (_, task, path) = scheduled(&app, "default", "pruned-result").await;
    finish_activity(&app, "default", &task, envelope(Payload::Null)).await;
    database
        .execute("DELETE FROM workflow_history_events WHERE event_type LIKE 'Activity%'")
        .await;
    let read = request(&app, "GET", &path, Value::Null).await;
    assert_eq!(read.0, StatusCode::OK, "{}", read.1);
    assert_eq!(read.1["state"], "unsupported");
    assert_eq!(read.1["reason"], "activity_result_history_unavailable");
    assert!(read.1.get("result_envelope").is_none());
    database
        .execute("UPDATE workflow_runs SET details_pruned_at='2026-10-15 00:00:00.000000'")
        .await;
    let read = request(&app, "GET", &path, Value::Null).await;
    assert_eq!(read.0, StatusCode::OK, "{}", read.1);
    assert_eq!(read.1["state"], "pruned");
    assert!(read.1.get("result_envelope").is_none());
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn activity_result_read_uses_canonical_retry_failure_and_cancellation_outcomes() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (started, first, path) = scheduled_with_attempts(&app, "default", "retry-result", 2).await;
    let failure =
        json!({"type":"RuntimeException","message":"retry result λ","non_retryable":false});
    let fail = |task: Value| {
        let app = app.clone();
        let failure = failure.clone();
        async move {
            request(&app, "POST", &format!("/api/worker/activity-tasks/{}/fail", task["task_id"].as_str().unwrap()),
                json!({"lease_owner":task["lease_owner"],"activity_attempt_id":task["activity_attempt_id"],"failure":failure})).await
        }
    };
    assert_eq!(fail(first.clone()).await.0, StatusCode::OK);
    let pending = request(&app, "GET", &path, Value::Null).await;
    assert_eq!(pending.0, StatusCode::OK, "{}", pending.1);
    assert_eq!(pending.1["state"], "pending");
    assert_eq!(pending.1["available"], false);
    let second = poll(&app, "result-worker", "activity").await;
    assert_eq!(
        second["activity_execution_id"],
        first["activity_execution_id"]
    );
    assert_eq!(second["attempt_number"], 2);
    assert_ne!(second["activity_attempt_id"], first["activity_attempt_id"]);
    let result = envelope(Payload::Long(9007199254740993));
    finish_activity(&app, "default", &second, result.clone()).await;
    let read = request(&app, "GET", &path, Value::Null).await;
    assert_eq!(read.0, StatusCode::OK, "{}", read.1);
    assert_eq!(read.1["result_envelope"], result);
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(
        history
            .as_array()
            .unwrap()
            .iter()
            .filter(|event| event["event_type"] == "ActivityCompleted")
            .count(),
        1
    );
    let parent = poll(&app, "result-worker", "workflow").await;
    assert_eq!(
        finish_task(
            &app,
            &parent,
            json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])
        )
        .await
        .0,
        StatusCode::OK
    );

    let (_, task, path) = scheduled(&app, "default", "failed-result").await;
    assert_eq!(fail(task).await.0, StatusCode::OK);
    let read = request(&app, "GET", &path, Value::Null).await;
    assert_eq!(read.0, StatusCode::OK, "{}", read.1);
    assert_eq!(read.1["state"], "failed");
    assert!(read.1.get("result_envelope").is_none());
    let parent = poll(&app, "result-worker", "workflow").await;
    assert_eq!(
        finish_task(
            &app,
            &parent,
            json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])
        )
        .await
        .0,
        StatusCode::OK
    );

    let (_, _, path) = scheduled(&app, "default", "cancelled-result").await;
    assert_eq!(
        request(
            &app,
            "POST",
            "/api/workflows/cancelled-result/cancel",
            json!({"reason":"inspect cancellation"})
        )
        .await
        .0,
        StatusCode::OK
    );
    let read = request(&app, "GET", &path, Value::Null).await;
    assert_eq!(read.0, StatusCode::OK, "{}", read.1);
    assert_eq!(read.1["state"], "cancelled");
    assert!(read.1.get("result_envelope").is_none());
    runtime.close().await;
    database.remove().await;
}

/// PHP's activity poll answer carries `server_time` (since 2.11.0), leased
/// or empty. Published SDKs measure the lease against it and refuse a claim
/// without it: Python SDK 2.12.2 raised `ActivityLeaseAuthorityLost`
/// (`invalid_activity_claim`) for every activity native delivered.
#[tokio::test]
async fn activity_poll_answers_carry_the_server_clock() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let micros = |value: &Value| {
        let text = value.as_str().unwrap_or_default();
        chrono::DateTime::parse_from_rfc3339(text).is_ok()
            && text.ends_with('Z')
            && text.rsplit_once('.').is_some_and(|(_, fraction)| fraction.len() == 7)
    };
    let app = &app;
    let call = |method, path: String, body| async move {
        namespaces::scoped(app, "default", method, &path, body).await
    };
    let poll = || call("POST", "/api/worker/activity-tasks/poll".into(),
        json!({"worker_id":"clock-worker","task_queue":"test","timeout_seconds":0}));
    let registered = call("POST", "/api/worker/register".into(), json!({
        "worker_id":"clock-worker","task_queue":"test","runtime":"python",
        "supported_workflow_types":["echo"],"supported_activity_types":["clock-activity"]})).await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    namespaces::start_scoped(&app, "default", "clock-run").await;
    let workflow = call("POST", "/api/worker/workflow-tasks/poll".into(),
        json!({"worker_id":"clock-worker","task_queue":"test","timeout_seconds":0})).await.1["task"].clone();
    let done = call("POST", format!("/api/worker/workflow-tasks/{}/complete", workflow["task_id"].as_str().unwrap()),
        completion(&workflow, json!([{"type":"schedule_activity","activity_type":"clock-activity",
            "arguments":envelope(Payload::Array(vec![])),"start_to_close_timeout":120}]))).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    let leased = poll().await;
    assert_eq!(leased.0, StatusCode::OK, "{}", leased.1);
    assert_eq!(leased.1["poll_status"], "leased", "{}", leased.1);
    assert!(micros(&leased.1["server_time"]), "{}", leased.1);
    let expiry = chrono::DateTime::parse_from_rfc3339(leased.1["task"]["lease_expires_at"].as_str().unwrap()).unwrap();
    let clock = chrono::DateTime::parse_from_rfc3339(leased.1["server_time"].as_str().unwrap()).unwrap();
    assert!(expiry > clock, "{}", leased.1);
    let empty = poll().await;
    assert_eq!(empty.0, StatusCode::OK, "{}", empty.1);
    assert_eq!(empty.1["poll_status"], "empty", "{}", empty.1);
    assert!(micros(&empty.1["server_time"]), "{}", empty.1);
}
