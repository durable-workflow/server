use super::*;

async fn scheduled(app: &Router, namespace: &str, name: &str) -> (Value, String) {
    let registered = namespaces::scoped(
        app,
        namespace,
        "POST",
        "/api/worker/register",
        json!({
            "worker_id":"metadata-worker","task_queue":"test","runtime":"php",
            "supported_workflow_types":["echo"],"supported_activity_types":["metadata-activity"]
        }),
    )
    .await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    let started = namespaces::start_scoped(app, namespace, name).await;
    let task = namespaces::scoped(
        app,
        namespace,
        "POST",
        "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"metadata-worker","task_queue":"test","timeout_seconds":0}),
    )
    .await
    .1["task"]
        .clone();
    let done = namespaces::scoped(
        app,
        namespace,
        "POST",
        &format!(
            "/api/worker/workflow-tasks/{}/complete",
            task["task_id"].as_str().unwrap()
        ),
        completion(
            &task,
            json!([{"type":"schedule_activity","activity_type":"metadata-activity",
            "arguments":envelope(Payload::Array(vec![Payload::Long(9007199254740993)])),
            "retry_policy":{"max_attempts":2,"backoff_seconds":[]},
            "start_to_close_timeout":120,"schedule_to_close_timeout":600}]),
        ),
    )
    .await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    let path = format!(
        "/api/workflows/{name}/runs/{}/activities",
        started["run_id"].as_str().unwrap()
    );
    (started, path)
}

async fn listed(app: &Router, path: &str) -> Value {
    let response = request(app, "GET", path, Value::Null).await;
    assert_eq!(response.0, StatusCode::OK, "{}", response.1);
    for activity in response.1["activities"].as_array().unwrap() {
        for field in ["arguments", "result", "exception"] {
            assert!(activity.get(field).is_none(), "{activity}");
        }
    }
    response.1["activities"].clone()
}

#[tokio::test]
async fn activity_metadata_tracks_retry_attempts_and_closed_history_without_payloads() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (started, path) = scheduled(&app, "default", "metadata-retry").await;
    let pending = listed(&app, &path).await;
    assert_eq!(pending.as_array().unwrap().len(), 1);
    assert_eq!(pending[0]["status"], "pending");
    assert_eq!(pending[0]["attempt_count"], 0);
    assert_eq!(pending[0]["history_authority"], "typed_history");
    assert_eq!(pending[0]["attempts"], json!([]));
    let first = poll(&app, "metadata-worker", "activity").await;
    let running = listed(&app, &path).await;
    assert_eq!(running[0]["id"], first["activity_execution_id"]);
    assert_eq!(running[0]["attempt_id"], first["activity_attempt_id"]);
    assert_eq!(running[0]["attempts"][0]["can_continue"], true);
    let heartbeat = request_protocol(&app,"POST",
        &format!("/api/worker/activity-tasks/{}/heartbeat",first["task_id"].as_str().unwrap()),
        json!({"lease_owner":first["lease_owner"],"activity_attempt_id":first["activity_attempt_id"],
            "message":" 日本語λ ","current":9007199254740993_i64,"total":9007199254740994_i64,"unit":"items"}),"1.20").await;
    assert_eq!(heartbeat.0, StatusCode::OK, "{}", heartbeat.1);
    let beat = listed(&app, &path).await;
    assert_eq!(
        beat[0]["last_heartbeat_progress"]["current"],
        9007199254740993_i64
    );
    assert_eq!(beat[0]["last_heartbeat_progress"]["message"], "日本語λ");
    let failed = request(&app,"POST",
        &format!("/api/worker/activity-tasks/{}/fail",first["task_id"].as_str().unwrap()),
        json!({"lease_owner":first["lease_owner"],"activity_attempt_id":first["activity_attempt_id"],
            "failure":{"type":"RuntimeException","message":"retry λ","non_retryable":false}})).await;
    assert_eq!(failed.0, StatusCode::OK, "{}", failed.1);
    let retry = listed(&app, &path).await;
    assert_eq!(retry[0]["status"], "pending");
    assert_eq!(retry[0]["attempt_count"], 1);
    assert_eq!(retry[0]["attempts"][0]["status"], "failed");
    assert_eq!(retry[0]["attempts"][0]["lease_expires_at"], Value::Null);
    assert_eq!(retry[0]["attempts"][0]["stop_reason"], "attempt_closed");
    let second = poll(&app, "metadata-worker", "activity").await;
    assert_ne!(second["activity_attempt_id"], first["activity_attempt_id"]);
    let resumed = listed(&app, &path).await;
    assert_eq!(resumed[0]["attempt_count"], 2);
    assert_eq!(
        resumed[0]["last_heartbeat_at"],
        beat[0]["last_heartbeat_at"]
    );
    let complete = request(&app,"POST",
        &format!("/api/worker/activity-tasks/{}/complete",second["task_id"].as_str().unwrap()),
        json!({"lease_owner":second["lease_owner"],"activity_attempt_id":second["activity_attempt_id"],
            "result":envelope(Payload::String("unrelated result 日本語".into()))})).await;
    assert_eq!(complete.0, StatusCode::OK, "{}", complete.1);
    let parent = poll(&app, "metadata-worker", "workflow").await;
    let done = finish_task(
        &app,
        &parent,
        json!([{"type":"complete_workflow","result":envelope(Payload::Null)}]),
    )
    .await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    let before = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let final_list = listed(&app, &path).await;
    assert_eq!(final_list[0]["status"], "completed");
    assert_eq!(final_list[0]["attempt_count"], 2);
    assert_eq!(final_list[0]["attempts"].as_array().unwrap().len(), 2);
    assert_eq!(
        final_list[0]["attempts"][1]["id"],
        second["activity_attempt_id"]
    );
    assert_eq!(final_list[0]["attempts"][1]["can_continue"], false);
    assert_eq!(
        final_list[0]["attempts"][1]["lease_expires_at"],
        Value::Null
    );
    assert_eq!(
        run_history(&app, &started["workflow_id"], &started["run_id"]).await,
        before
    );
    // Construct a history-only reader control after genuine terminal execution.
    database.execute("DELETE FROM activity_attempts").await;
    database.execute("DELETE FROM activity_executions").await;
    assert_eq!(listed(&app, &path).await, final_list);
    assert_eq!(
        run_history(&app, &started["workflow_id"], &started["run_id"]).await,
        before
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn activity_metadata_binds_literal_run_namespace_and_operator_role() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    namespaces::create(&app, "metadata-alpha").await;
    namespaces::create(&app, "metadata-beta").await;
    let (alpha_start, alpha) = scheduled(&app, "metadata-alpha", "metadata-alpha-run").await;
    let (beta_start, beta) = scheduled(&app, "metadata-beta", "metadata-beta-run").await;
    for (namespace, path, started) in [
        ("metadata-alpha", &alpha, &alpha_start),
        ("metadata-beta", &beta, &beta_start),
    ] {
        let own = namespaces::scoped(&app, namespace, "GET", path, Value::Null).await;
        assert_eq!(own.0, StatusCode::OK, "{}", own.1);
        assert_eq!(own.1["activities"].as_array().unwrap().len(), 1);
        let foreign = if namespace == "metadata-alpha" {
            &beta
        } else {
            &alpha
        };
        assert_eq!(
            namespaces::scoped(&app, namespace, "GET", foreign, Value::Null)
                .await
                .0,
            StatusCode::NOT_FOUND
        );
        let alias = path
            .replace("metadata-alpha-run", "METADATA-ALPHA-RUN")
            .replace("metadata-beta-run", "METADATA-BETA-RUN");
        assert_eq!(
            namespaces::scoped(&app, namespace, "GET", &alias, Value::Null)
                .await
                .0,
            StatusCode::NOT_FOUND
        );
        let run_id = started["run_id"].as_str().unwrap();
        let alias = path.replace(run_id, &run_id.to_ascii_lowercase());
        assert_ne!(&alias, path);
        assert_eq!(
            namespaces::scoped(&app, namespace, "GET", &alias, Value::Null)
                .await
                .0,
            StatusCode::NOT_FOUND
        );
    }
    let (_, path) = scheduled(&app, "default", "metadata-default-run").await;
    let app = router(runtime.clone().with_role_tokens(
        Some("worker-token".into()),
        Some("operator-token".into()),
        Some("admin-token".into()),
    ));
    for (token, expected) in [
        (None, StatusCode::UNAUTHORIZED),
        (Some("worker-token"), StatusCode::FORBIDDEN),
        (Some("operator-token"), StatusCode::OK),
        (Some("admin-token"), StatusCode::OK),
    ] {
        let response = admission::admission_http(
            &app,
            "GET",
            &path,
            false,
            token,
            Some("2"),
            Some("default"),
            Value::Null,
        )
        .await;
        assert_eq!(response.0, expected, "{}", response.2);
    }
    let invalid = admission::admission_http(
        &app,
        "GET",
        &path,
        false,
        Some("operator-token"),
        Some("999"),
        Some("ghost"),
        Value::Null,
    )
    .await;
    assert_eq!(invalid.0, StatusCode::BAD_REQUEST, "{}", invalid.2);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn activity_metadata_marks_missing_typed_history_as_diagnostic() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (_, path) = scheduled(&app, "default", "metadata-fallback").await;
    let task = poll(&app, "metadata-worker", "activity").await;
    // Deliberately constructed missing-history reader controls, not normal pruning.
    database.execute("DELETE FROM workflow_history_events WHERE event_type IN ('ActivityScheduled','ActivityStarted')").await;
    let fallback = listed(&app, &path).await;
    assert_eq!(fallback[0]["history_authority"], "mutable_open_fallback");
    assert_eq!(fallback[0]["diagnostic_only"], true);
    assert_eq!(fallback[0]["attempt_id"], task["activity_attempt_id"]);
    database.execute("UPDATE activity_executions SET status='completed',closed_at='2026-10-15 10:00:01.123456'").await;
    let unsupported = listed(&app, &path).await;
    assert_eq!(unsupported[0]["status"], "unsupported");
    assert_eq!(unsupported[0]["row_status"], "completed");
    assert_eq!(unsupported[0]["closed_at"], Value::Null);
    assert_eq!(
        unsupported[0]["history_unsupported_reason"],
        "terminal_activity_row_without_typed_history"
    );
    assert_eq!(unsupported[0]["attempts"][0]["can_continue"], false);
    assert_eq!(
        unsupported[0]["attempts"][0]["stop_reason"],
        "activity_closed"
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn activity_metadata_refuses_over_budget_activity_history_without_loading_unrelated_payloads()
{
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (_, path) = scheduled(&app, "default", "metadata-budget").await;
    let before = listed(&app, &path).await;
    match &database {
        TestDatabase::Sqlite(_) => database.execute("UPDATE workflow_history_events SET payload=json_set(payload,'$.fixture_padding',hex(zeroblob(4194304))) WHERE event_type='WorkflowStarted'").await,
        TestDatabase::Postgres {..} => database.execute("UPDATE workflow_history_events SET payload=jsonb_set(payload::jsonb,'{fixture_padding}',to_jsonb(repeat('x',8388608))) WHERE event_type='WorkflowStarted'").await,
        TestDatabase::MySql {..} => database.execute("UPDATE workflow_history_events SET payload=JSON_SET(payload,'$.fixture_padding',REPEAT('x',8388608)) WHERE event_type='WorkflowStarted'").await,
    }
    assert_eq!(listed(&app, &path).await, before);
    match &database {
        TestDatabase::Sqlite(_) => database.execute("UPDATE workflow_history_events SET payload=json_set(payload,'$.fixture_padding',hex(zeroblob(4194304))) WHERE event_type='ActivityScheduled'").await,
        TestDatabase::Postgres {..} => database.execute("UPDATE workflow_history_events SET payload=jsonb_set(payload::jsonb,'{fixture_padding}',to_jsonb(repeat('x',8388608))) WHERE event_type='ActivityScheduled'").await,
        TestDatabase::MySql {..} => database.execute("UPDATE workflow_history_events SET payload=JSON_SET(payload,'$.fixture_padding',REPEAT('x',8388608)) WHERE event_type='ActivityScheduled'").await,
    }
    let refused = request(&app, "GET", &path, Value::Null).await;
    assert_eq!(refused.0, StatusCode::PAYLOAD_TOO_LARGE, "{}", refused.1);
    assert_eq!(refused.1["reason"], "activity_metadata_exceeds_read_budget");
    assert!(refused.1.get("activities").is_none());
    match &database {
        TestDatabase::Sqlite(_) => database.execute("UPDATE workflow_history_events SET payload=json_remove(payload,'$.fixture_padding')").await,
        TestDatabase::Postgres {..} => database.execute("UPDATE workflow_history_events SET payload=payload::jsonb-'fixture_padding'").await,
        TestDatabase::MySql {..} => database.execute("UPDATE workflow_history_events SET payload=JSON_REMOVE(payload,'$.fixture_padding')").await,
    }
    assert_eq!(listed(&app, &path).await, before);
    runtime.close().await;
    database.remove().await;
}
