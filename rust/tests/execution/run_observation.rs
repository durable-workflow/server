use super::*;

#[tokio::test]
async fn run_observation_projects_actual_activity_retry_and_terminal_transitions() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    namespaces::create(&app, "projected").await;
    for namespace in ["default", "projected"] {
        let workflow = format!("projected-{namespace}");
        let registered = namespaces::scoped(&app, namespace, "POST", "/api/worker/register",
            json!({"worker_id":"projection-worker","task_queue":"test","runtime":"php",
                "supported_workflow_types":["echo"],"supported_activity_types":["observed-activity"]})).await;
        assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
        let started = namespaces::start_scoped(&app, namespace, &workflow).await;
        let path = format!(
            "/api/workflows/{workflow}/runs/{}/observation",
            started["run_id"].as_str().unwrap()
        );
        let task = projection_poll(&app, namespace, "workflow").await;
        let completion_path = format!(
            "/api/worker/workflow-tasks/{}/complete",
            task["task_id"].as_str().unwrap()
        );
        let body = completion(
            &task,
            json!([{"type":"schedule_activity","activity_type":"observed-activity",
            "arguments":envelope(Payload::Array(vec![Payload::Long(9007199254740993)])),
            "retry_policy":{"max_attempts":2,"backoff_seconds":[]},
            "start_to_close_timeout":120,"schedule_to_close_timeout":600}]),
        );
        let scheduled =
            namespaces::scoped(&app, namespace, "POST", &completion_path, body.clone()).await;
        assert_eq!(scheduled.0, StatusCode::OK, "{}", scheduled.1);
        let read = observe(&app, namespace, &path).await;
        assert_eq!(read.0, StatusCode::OK, "{}", read.1);
        assert_eq!(read.1["current_waits"]["returned_count"], 1, "{}", read.1);
        assert_eq!(read.1["summary_state"], "observed");
        assert_eq!(read.1["liveness_state"], "activity_task_ready");
        run_observation_summaries::export_reference(&database, &read.1, &read.1["history"]).await;
        let wait = read.1["current_waits"]["waits"][0].clone();
        assert_eq!(wait["kind"], "activity");
        assert_eq!(wait["task_status"], "ready");
        assert_eq!(wait["task_metadata_state"], "available");
        let duplicate = namespaces::scoped(&app, namespace, "POST", &completion_path, body).await;
        assert_eq!(duplicate.0, StatusCode::OK, "{}", duplicate.1);
        assert_eq!(
            observe(&app, namespace, &path).await.1["current_waits"]["returned_count"],
            1
        );
        let first = projection_poll(&app, namespace, "activity").await;
        assert_eq!(wait["task_id"], first["task_id"]);
        let heartbeat = namespaces::scoped(&app, namespace, "POST",
            &format!("/api/worker/activity-tasks/{}/heartbeat", first["task_id"].as_str().unwrap()),
            json!({"lease_owner":first["lease_owner"],"activity_attempt_id":first["activity_attempt_id"],
                "message":"日本語 λ","current":1,"total":2,"unit":"items"})).await;
        assert_eq!(heartbeat.0, StatusCode::OK, "{}", heartbeat.1);
        let claimed = observe(&app, namespace, &path).await;
        assert_eq!(claimed.1["liveness_state"], "activity_task_leased");
        run_observation_summaries::export_reference(&database, &claimed.1, &claimed.1["history"])
            .await;
        let claimed_wait = &claimed.1["current_waits"]["waits"][0];
        assert_eq!(claimed_wait["id"], wait["id"]);
        assert_eq!(claimed_wait["attempt_id"], first["activity_attempt_id"]);
        assert_eq!(claimed_wait["task_status"], "leased");
        let history_path = format!(
            "/api/workflows/{workflow}/runs/{}/history",
            started["run_id"].as_str().unwrap()
        );
        let before_read =
            namespaces::scoped(&app, namespace, "GET", &history_path, Value::Null).await;
        assert_eq!(observe(&app, namespace, &path).await.0, StatusCode::OK);
        assert_eq!(
            namespaces::scoped(&app, namespace, "GET", &history_path, Value::Null).await,
            before_read
        );
        let failed = namespaces::scoped(&app, namespace, "POST",
            &format!("/api/worker/activity-tasks/{}/fail", first["task_id"].as_str().unwrap()),
            json!({"lease_owner":first["lease_owner"],"activity_attempt_id":first["activity_attempt_id"],
                "failure":{"type":"RuntimeException","message":"retry 日本語 λ","non_retryable":false}})).await;
        assert_eq!(failed.0, StatusCode::OK, "{}", failed.1);
        let retry_read = observe(&app, namespace, &path).await;
        assert_eq!(retry_read.1["liveness_state"], "activity_task_ready");
        run_observation_summaries::export_reference(
            &database,
            &retry_read.1,
            &retry_read.1["history"],
        )
        .await;
        assert_eq!(retry_read.1["current_waits"]["returned_count"], 1);
        let retry_wait = &retry_read.1["current_waits"]["waits"][0];
        assert_eq!(retry_wait["id"], wait["id"]);
        assert_eq!(retry_wait["kind"], "activity_retry");
        assert_eq!(retry_wait["attempt_number"], 2);
        assert_eq!(retry_wait["task_id"], failed.1["next_task_id"]);
        assert_ne!(retry_wait["task_id"], wait["task_id"]);
        let cold = database.open().await.unwrap();
        let cold_read = observe(&router(cold.clone()), namespace, &path).await;
        assert_eq!(cold_read.1["liveness_state"], "activity_task_ready");
        assert_eq!(
            cold_read.1["current_waits"]["waits"],
            retry_read.1["current_waits"]["waits"]
        );
        cold.close().await;
        let retry = projection_poll(&app, namespace, "activity").await;
        let action = if namespace == "default" {
            "complete"
        } else {
            "fail"
        };
        let mut terminal = json!({"lease_owner":retry["lease_owner"],"activity_attempt_id":retry["activity_attempt_id"]});
        if action == "complete" {
            terminal["result"] = envelope(Payload::Null);
        } else {
            terminal["failure"] =
                json!({"type":"RuntimeException","message":"terminal λ","non_retryable":true});
        }
        let terminal_path = format!(
            "/api/worker/activity-tasks/{}/{action}",
            retry["task_id"].as_str().unwrap()
        );
        let closed =
            namespaces::scoped(&app, namespace, "POST", &terminal_path, terminal.clone()).await;
        assert_eq!(closed.0, StatusCode::OK, "{}", closed.1);
        let resumed = observe(&app, namespace, &path).await;
        assert_eq!(resumed.1["liveness_state"], "workflow_task_ready");
        run_observation_summaries::export_reference(&database, &resumed.1, &resumed.1["history"])
            .await;
        assert_eq!(
            observe(&app, namespace, &path).await.1["current_waits"]["returned_count"],
            0
        );
        let duplicate = namespaces::scoped(&app, namespace, "POST", &terminal_path, terminal).await;
        assert_eq!(duplicate.0, StatusCode::OK, "{}", duplicate.1);
        assert_eq!(
            observe(&app, namespace, &path).await.1["current_waits"]["returned_count"],
            0
        );
        let other = if namespace == "default" {
            "projected"
        } else {
            "default"
        };
        assert_eq!(observe(&app, other, &path).await.0, StatusCode::NOT_FOUND);
        let task = projection_poll(&app, namespace, "workflow").await;
        let closed = namespaces::scoped(
            &app,
            namespace,
            "POST",
            &format!(
                "/api/worker/workflow-tasks/{}/complete",
                task["task_id"].as_str().unwrap()
            ),
            completion(
                &task,
                json!([{"type":"complete_workflow","result":envelope(Payload::Null)}]),
            ),
        )
        .await;
        assert_eq!(closed.0, StatusCode::OK, "{}", closed.1);
        run_observation_summaries::check_completed(
            &database,
            &app,
            namespace,
            &started,
            u64::from(namespace != "default"),
            0,
        )
        .await;
    }
    runtime.close().await;
    database.remove().await;
}

async fn projection_poll(app: &Router, namespace: &str, kind: &str) -> Value {
    let polled = namespaces::scoped(
        app,
        namespace,
        "POST",
        &format!("/api/worker/{kind}-tasks/poll"),
        json!({"worker_id":"projection-worker","task_queue":"test","timeout_seconds":0}),
    )
    .await;
    assert_eq!(polled.0, StatusCode::OK, "{}", polled.1);
    assert!(polled.1["task"].is_object(), "{}", polled.1);
    polled.1["task"].clone()
}

#[tokio::test]
async fn run_observation_activity_projection_failure_rolls_back_sqlite_transition() {
    let database = TestDatabase::Sqlite(tempfile::tempdir().unwrap());
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(
        &app,
        "projection-rollback",
        json!(["echo"]),
        json!(["observed-activity"]),
    )
    .await;
    let started = start(&app, "projection-rollback").await;
    let task = poll(&app, "projection-rollback", "workflow").await;
    let scheduled = finish_task(
        &app,
        &task,
        json!([{"type":"schedule_activity","activity_type":"observed-activity",
        "arguments":envelope(Payload::Array(vec![]))}]),
    )
    .await;
    assert_eq!(scheduled.0, StatusCode::OK, "{}", scheduled.1);
    let activity = poll(&app, "projection-rollback", "activity").await;
    let path = format!(
        "/api/workflows/projection-rollback/runs/{}/observation",
        started["run_id"].as_str().unwrap()
    );
    let before = observe(&app, "default", &path).await;
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    database.execute("CREATE TRIGGER reject_wait_update BEFORE UPDATE ON workflow_run_waits BEGIN SELECT RAISE(ABORT, 'projection write failed'); END").await;
    let body = json!({"lease_owner":activity["lease_owner"],"activity_attempt_id":activity["activity_attempt_id"],"result":envelope(Payload::Null)});
    let terminal_path = format!(
        "/api/worker/activity-tasks/{}/complete",
        activity["task_id"].as_str().unwrap()
    );
    let refused = request(&app, "POST", &terminal_path, body.clone()).await;
    assert_eq!(refused.0, StatusCode::SERVICE_UNAVAILABLE, "{}", refused.1);
    assert_eq!(
        run_history(&app, &started["workflow_id"], &started["run_id"]).await,
        history
    );
    assert_eq!(
        observe(&app, "default", &path).await.1["current_waits"]["waits"],
        before.1["current_waits"]["waits"]
    );
    database.execute("DROP TRIGGER reject_wait_update").await;
    let completed = request(&app, "POST", &terminal_path, body).await;
    assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
    assert_eq!(completed.1["recorded"], true);
    assert_eq!(
        observe(&app, "default", &path).await.1["current_waits"]["returned_count"],
        0
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_activity_events_do_not_repair_a_missing_wait() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(
        &app,
        "projection-missing",
        json!(["echo"]),
        json!(["observed-activity"]),
    )
    .await;
    let started = start(&app, "projection-missing").await;
    let task = poll(&app, "projection-missing", "workflow").await;
    let scheduled = finish_task(&app, &task, json!([{"type":"schedule_activity","activity_type":"observed-activity",
        "arguments":envelope(Payload::Array(vec![])),"retry_policy":{"max_attempts":2,"backoff_seconds":[]}}])).await;
    assert_eq!(scheduled.0, StatusCode::OK, "{}", scheduled.1);
    let path = format!(
        "/api/workflows/projection-missing/runs/{}/observation",
        started["run_id"].as_str().unwrap()
    );
    assert_eq!(
        observe(&app, "default", &path).await.1["current_waits"]["returned_count"],
        1
    );
    database.execute("DELETE FROM workflow_run_waits").await;
    let activity = poll(&app, "projection-missing", "activity").await;
    let failed = request(&app, "POST", &format!("/api/worker/activity-tasks/{}/fail", activity["task_id"].as_str().unwrap()),
        json!({"lease_owner":activity["lease_owner"],"activity_attempt_id":activity["activity_attempt_id"],
            "failure":{"type":"RuntimeException","message":"retry without projection","non_retryable":false}})).await;
    assert_eq!(failed.0, StatusCode::OK, "{}", failed.1);
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let read = observe(&app, "default", &path).await;
    assert_eq!(read.0, StatusCode::OK, "{}", read.1);
    assert_eq!(read.1["summary_state"], "unavailable");
    assert!(read.1["liveness_state"].is_null());
    assert_eq!(read.1["current_waits"]["state"], "unavailable");
    assert_eq!(read.1["current_waits"]["returned_count"], 0);
    assert_eq!(
        run_history(&app, &started["workflow_id"], &started["run_id"]).await,
        history
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_activity_summary_failure_rolls_back_original_claim() {
    let database = TestDatabase::Sqlite(tempfile::tempdir().unwrap());
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(
        &app,
        "activity-summary-rollback",
        json!(["echo"]),
        json!(["observed-activity"]),
    )
    .await;
    let run = start(&app, "activity-summary-rollback").await;
    let task = poll(&app, "activity-summary-rollback", "workflow").await;
    assert_eq!(
        finish_task(
            &app,
            &task,
            json!([{"type":"schedule_activity",
        "activity_type":"observed-activity","arguments":envelope(Payload::Array(vec![]))}])
        )
        .await
        .0,
        StatusCode::OK
    );
    let path = format!(
        "/api/workflows/activity-summary-rollback/runs/{}/observation",
        run["run_id"].as_str().unwrap()
    );
    let before = observe(&app, "default", &path).await.1;
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    database.execute("CREATE TRIGGER reject_activity_summary BEFORE INSERT ON workflow_run_summaries WHEN NEW.liveness_state='activity_task_leased' BEGIN SELECT RAISE(ABORT, 'summary write failed'); END").await;
    let input =
        json!({"worker_id":"activity-summary-rollback","task_queue":"test","timeout_seconds":0});
    let refused = request(
        &app,
        "POST",
        "/api/worker/activity-tasks/poll",
        input.clone(),
    )
    .await;
    assert_eq!(refused.0, StatusCode::SERVICE_UNAVAILABLE, "{}", refused.1);
    assert_eq!(
        run_history(&app, &run["workflow_id"], &run["run_id"]).await,
        history
    );
    assert_eq!(
        task_retry::rows(&database, "SELECT COUNT(*) FROM activity_attempts").await,
        0
    );
    let unchanged = observe(&app, "default", &path).await.1;
    assert_eq!(unchanged["liveness_state"], "activity_task_ready");
    assert_eq!(unchanged["current_waits"], before["current_waits"]);
    database
        .execute("DROP TRIGGER reject_activity_summary")
        .await;
    let claimed = request(&app, "POST", "/api/worker/activity-tasks/poll", input).await;
    assert_eq!(claimed.0, StatusCode::OK);
    assert_eq!(
        claimed.1["task"]["task_id"],
        before["current_waits"]["waits"][0]["task_id"]
    );
    assert_eq!(claimed.1["task"]["attempt_number"], 1);
    assert_eq!(
        observe(&app, "default", &path).await.1["liveness_state"],
        "activity_task_leased"
    );
    runtime.close().await;
    database.remove().await;
}

async fn observe(app: &Router, namespace: &str, path: &str) -> (StatusCode, Value) {
    let response = app
        .clone()
        .oneshot(
            Request::builder()
                .uri(path)
                .header("authorization", "Bearer test-token")
                .header("x-namespace", namespace)
                .header("x-durable-workflow-control-plane-version", "2")
                .body(Body::empty())
                .unwrap(),
        )
        .await
        .unwrap();
    let status = response.status();
    let bytes = to_bytes(response.into_body(), 4 * 1024 * 1024)
        .await
        .unwrap();
    let body = if bytes.is_empty() {
        Value::Null
    } else {
        serde_json::from_slice(&bytes).unwrap()
    };
    (status, body)
}

#[tokio::test]
async fn run_observation_reads_current_and_selected_metadata_without_hydration_or_repair() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    namespaces::create(&app, "observation-named").await;
    let mut default_run = String::new();
    for (namespace, name) in [
        ("default", "observation-default"),
        ("observation-named", "observation-named-run"),
    ] {
        let original = namespaces::start_scoped(&app, namespace, name).await;
        database.execute("DELETE FROM workflow_run_summaries").await;
        let run = original["run_id"].as_str().unwrap();
        if namespace == "default" {
            default_run = run.into();
        }
        let history_path = format!("/api/workflows/{name}/runs/{run}/history");
        let before = namespaces::scoped(&app, namespace, "GET", &history_path, Value::Null).await;
        for path in [
            format!("/api/workflows/{name}/observation"),
            format!("/api/workflows/{name}/runs/{run}/observation"),
        ] {
            let (status, body) = observe(&app, namespace, &path).await;
            assert_eq!(status, StatusCode::OK, "{body}");
            assert_eq!(body["control_plane"]["operation"], "observe_workflow");
            assert_eq!(body["workflow_id"], name);
            assert_eq!(body["run_id"], run);
            assert_eq!(body["selected_run_id"], run);
            assert_eq!(body["current_run_id"], run);
            assert_eq!(body["is_current_run"], true);
            assert_eq!(body["namespace"], namespace);
            assert_eq!(body["read_mode"], "bounded");
            assert_eq!(body["history_audit"], "not_evaluated");
            assert_eq!(body["current_run_audit"], "not_evaluated");
            assert_eq!(body["summary_state"], "unavailable");
            assert!(body["history_event_count"].is_null());
            assert_eq!(body["current_waits"]["state"], "unavailable");
            assert_eq!(
                body["current_waits"]["unavailable_reason"],
                "current_wait_projection_unavailable"
            );
            let events = body["history"]["events"].as_array().unwrap();
            let previous = before.1["events"].as_array().unwrap();
            assert_eq!(events.len(), previous.len());
            for (event, previous) in events.iter().zip(previous) {
                for field in ["sequence", "event_type", "payload"] {
                    assert_eq!(event[field], previous[field]);
                }
                assert!(event["id"].as_str().is_some_and(|id| !id.is_empty()));
                assert_eq!(
                    chrono::DateTime::parse_from_rfc3339(event["recorded_at"].as_str().unwrap())
                        .unwrap()
                        .timestamp(),
                    chrono::DateTime::parse_from_rfc3339(previous["timestamp"].as_str().unwrap())
                        .unwrap()
                        .timestamp()
                );
            }
            assert_eq!(body["history"]["has_more"], false);
            assert!(body["history"]["next_page_token"].is_null());
            assert_eq!(body["search_attributes"], json!([]));
            for field in [
                "arguments",
                "input",
                "output",
                "memo",
                "cancellation_cascade",
            ] {
                assert!(
                    body.get(field).is_none(),
                    "Unexpected hydrated field {field}"
                );
            }
        }
        assert_eq!(
            namespaces::scoped(&app, namespace, "GET", &history_path, Value::Null).await,
            before
        );
    }
    database.execute("UPDATE workflow_instances SET current_run_id=(SELECT id FROM workflow_runs WHERE workflow_instance_id='observation-named-run') WHERE id='observation-default'").await;
    let current = observe(
        &app,
        "default",
        "/api/workflows/observation-default/observation",
    )
    .await;
    assert_eq!(current.0, StatusCode::NOT_FOUND);
    assert_eq!(current.1["reason"], "current_run_observation_unavailable");
    // The explicit historical selection remains readable even when the current
    // pointer names a run in a different workflow and namespace.
    {
        let selected = observe(
            &app,
            "default",
            &format!("/api/workflows/observation-default/runs/{default_run}/observation"),
        )
        .await;
        assert_eq!(selected.0, StatusCode::OK, "{}", selected.1);
        for field in ["current_run_id", "current_run_status", "is_current_run"] {
            assert!(selected.1[field].is_null());
        }
        assert_eq!(selected.1["current_run_state"], "unavailable");
    }
    let original = request(
        &app,
        "GET",
        "/api/workflows/observation-named-run",
        Value::Null,
    )
    .await;
    assert_eq!(original.0, StatusCode::NOT_FOUND);
    let configured = router(runtime.clone().with_role_tokens(
        Some("worker-only".into()),
        Some("operator-only".into()),
        Some("admin-only".into()),
    ));
    let path = format!("/api/workflows/observation-default/runs/{default_run}/observation");
    for (token, version, namespace, expected) in [
        (None, Some("2"), "default", StatusCode::UNAUTHORIZED),
        (
            Some("worker-only"),
            Some("invalid"),
            "unknown",
            StatusCode::FORBIDDEN,
        ),
        (
            Some("operator-only"),
            None,
            "default",
            StatusCode::BAD_REQUEST,
        ),
        (Some("operator-only"), Some("2"), "default", StatusCode::OK),
        (Some("admin-only"), Some("2"), "default", StatusCode::OK),
    ] {
        let (status, _, body) = admission::admission_http(
            &configured,
            "GET",
            &path,
            false,
            token,
            version,
            Some(namespace),
            Value::Null,
        )
        .await;
        assert_eq!(status, expected, "{body}");
        assert_eq!(body["control_plane"]["operation"], "observe_workflow");
        assert_eq!(body["control_plane"]["run_id"], default_run);
    }
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_selects_typed_context_without_decoding_application_payloads() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let started = start(&app, "observation-context").await;
    let run = started["run_id"].as_str().unwrap();
    let path = format!("/api/workflows/observation-context/runs/{run}/observation");
    database.execute("UPDATE workflow_runs SET arguments='not-an-application-envelope',output='also-not-an-envelope' WHERE workflow_instance_id='observation-context'").await;
    database
        .execute(
            "DELETE FROM workflow_run_summaries WHERE workflow_instance_id='observation-context'",
        )
        .await;
    database.execute("INSERT INTO workflow_run_summaries(id,workflow_instance_id,run_number,class,workflow_type,namespace,status,status_bucket,duration_ms,exception_count,history_event_count,history_size_bytes,history_fan_out,liveness_state,liveness_reason,updated_at) SELECT id,workflow_instance_id,run_number,workflow_class,workflow_type,namespace,status,'running',321,2,17,999,4,'waiting','retained reason','2026-10-15 01:02:03' FROM workflow_runs WHERE workflow_instance_id='observation-context'").await;
    let key_column = if matches!(database, TestDatabase::MySql { .. }) {
        "`key`"
    } else {
        "\"key\""
    };
    for (key, kind, column, value) in [
        ("a", "string", "value_string", "'日本語 λ'"),
        ("b", "keyword", "value_keyword", "'blue'"),
        (
            "c",
            "keyword_list",
            "value_keyword_list",
            "'[\"first\",\"second\"]'",
        ),
        ("d", "int", "value_int", "9007199254740993"),
        ("e", "float", "value_float", "1.25"),
        ("f", "bool", "value_bool", "FALSE"),
        (
            "g",
            "datetime",
            "value_datetime",
            "'2026-10-15 01:02:03.123456'",
        ),
        ("hidden", "string", "value_string", "'not selected'"),
    ] {
        database.execute(sqlx::AssertSqlSafe(format!("INSERT INTO workflow_search_attributes(workflow_run_id,workflow_instance_id,{key_column},type,{column},upserted_at_sequence) SELECT id,workflow_instance_id,'{key}','{kind}',{value},1 FROM workflow_runs WHERE workflow_instance_id='observation-context'"))).await;
    }
    let selected = observe(&app,"default",&format!("{path}?search_attribute_keys[]=a&search_attribute_keys[]=b&search_attribute_keys[]=c&search_attribute_keys[]=d&search_attribute_keys[]=e&search_attribute_keys[]=f&search_attribute_keys[]=g")).await;
    assert_eq!(selected.0, StatusCode::OK, "{}", selected.1);
    assert_eq!(
        selected.1["search_attributes"],
        json!({"a":"日本語 λ","b":"blue","c":["first","second"],"d":9007199254740993_i64,"e":1.25,"f":false,"g":"2026-10-15T01:02:03.123456Z"})
    );
    assert_eq!(selected.1["summary_state"], "observed");
    assert_eq!(
        selected.1["summary_observed_at"],
        "2026-10-15T01:02:03+00:00"
    );
    for (field, value) in [
        ("duration_ms", 321),
        ("exception_count", 2),
        ("history_event_count", 17),
        ("history_size_bytes", 999),
        ("history_fan_out", 4),
    ] {
        assert_eq!(selected.1[field], value);
    }
    assert_eq!(selected.1["liveness_state"], "waiting");
    assert_eq!(selected.1["liveness_reason"], "retained reason");
    assert_eq!(
        observe(&app, "default", &path).await.1["search_attributes"],
        json!([])
    );
    for query in [
        "search_attribute_keys=a",
        "search_attribute_keys[]=a&search_attribute_keys[]=a",
        "search_attribute_keys[]=",
        "search_attribute_keys[x][y]=a",
        "history_page_size[]=1",
        "history_page_token[]=x",
    ] {
        assert_eq!(
            observe(&app, "default", &format!("{path}?{query}")).await.0,
            StatusCode::UNPROCESSABLE_ENTITY,
            "{query}"
        );
    }
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_bounds_projected_collections_and_retains_failure_event_links() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let original = start(&app, "observation-bounds").await;
    start(&app, "observation-related").await;
    let path = format!(
        "/api/workflows/observation-bounds/runs/{}/observation",
        original["run_id"].as_str().unwrap()
    );
    // Constructed retained projections exercise cardinality and read semantics;
    // they are deliberately not evidence of native projection production.
    for index in 0..55 {
        start(&app, &format!("observation-child-{index:03}")).await;
        database.execute(sqlx::AssertSqlSafe(format!("INSERT INTO workflow_links(id,link_type,sequence,parent_workflow_instance_id,parent_workflow_run_id,child_workflow_instance_id,child_workflow_run_id) SELECT 'link-{index:03}','child_workflow',{index},p.workflow_instance_id,p.id,c.workflow_instance_id,c.id FROM workflow_runs p CROSS JOIN workflow_runs c WHERE p.workflow_instance_id='observation-bounds' AND c.workflow_instance_id='observation-child-{index:03}'"))).await;
        database.execute(sqlx::AssertSqlSafe(format!("INSERT INTO workflow_links(id,link_type,sequence,parent_workflow_instance_id,parent_workflow_run_id,child_workflow_instance_id,child_workflow_run_id) SELECT 'parent-{index:03}','child_workflow',{index},p.workflow_instance_id,p.id,c.workflow_instance_id,c.id FROM workflow_runs p CROSS JOIN workflow_runs c WHERE p.workflow_instance_id='observation-child-{index:03}' AND c.workflow_instance_id='observation-related'"))).await;
        database.execute(sqlx::AssertSqlSafe(format!("INSERT INTO workflow_run_waits(id,workflow_run_id,workflow_instance_id,wait_id,position,kind,status,summary,resume_source_kind,resume_source_id,deadline_at,history_authority) SELECT 'wait-{index:03}',id,workflow_instance_id,'timer-{index:03}',{index},'timer','open','retained timer','timer','timer-{index:03}','2026-01-01 00:00:00','typed_history' FROM workflow_runs WHERE workflow_instance_id='observation-bounds'"))).await;
    }
    for index in 0..12 {
        database.execute(sqlx::AssertSqlSafe(format!("INSERT INTO workflow_failures(id,workflow_run_id,source_kind,source_id,propagation_kind,exception_class,message,file,line,trace_preview,created_at) SELECT 'failure-{index:03}',id,'workflow_run',id,'failure','ObservedFailure','retained failure','',7,'preview only','2026-01-01 00:00:00' FROM workflow_runs WHERE workflow_instance_id='observation-bounds'"))).await;
    }
    database.execute("INSERT INTO workflow_history_events(id,workflow_run_id,sequence,event_type,payload,recorded_at) SELECT 'observation-failure-event',id,101,'WorkflowFailed','{\"failure_id\":\"failure-011\"}','2026-01-01 00:00:00' FROM workflow_runs WHERE workflow_instance_id='observation-bounds'").await;
    database.execute("INSERT INTO workflow_history_events(id,workflow_run_id,sequence,event_type,payload,recorded_at) SELECT 'observation-handled-event',id,102,'FailureHandled','{\"failure_id\":\"failure-011\"}','2026-01-01 00:00:00' FROM workflow_runs WHERE workflow_instance_id='observation-bounds'").await;
    let read = observe(&app, "default", &path).await;
    assert_eq!(read.0, StatusCode::OK, "{}", read.1);
    for (field, limit) in [
        ("current_waits", 50),
        ("children", 50),
        ("recent_failures", 10),
    ] {
        assert_eq!(read.1[field]["returned_count"], limit);
        assert_eq!(read.1[field]["limit"], limit);
        assert_eq!(read.1[field]["has_more"], true);
    }
    assert_eq!(read.1["parents"]["returned_count"], 0);
    assert_eq!(read.1["children"]["next_link_id"], "link-049");
    assert_eq!(read.1["children"]["through_link_id"], "link-054");
    for item in read.1["children"]["relationships"].as_array().unwrap() {
        assert_eq!(item["metadata_state"], "available");
        assert!(
            item["instance_id"]
                .as_str()
                .unwrap()
                .starts_with("observation-child-")
        );
        assert_eq!(item["status"], "pending");
    }
    for wait in read.1["current_waits"]["waits"].as_array().unwrap() {
        assert_eq!(wait["state"], "eligible");
        assert_eq!(wait["task_metadata_state"], "unavailable");
        assert!(wait["deadline_at"].is_null());
        assert_eq!(
            wait["next_scheduled_resume_at"],
            "2026-01-01T00:00:00+00:00"
        );
    }
    let failures = read.1["recent_failures"]["failures"].as_array().unwrap();
    assert_eq!(failures[0]["id"], "failure-011");
    assert_eq!(failures[0]["event_sequence"], 101);
    assert_eq!(failures[1]["supporting_event"]["state"], "unavailable");
    let token = failures[0]["supporting_event"]["next_page_token"]
        .as_str()
        .unwrap();
    let linked = observe(
        &app,
        "default",
        &format!("{path}?history_page_token={token}"),
    )
    .await;
    assert_eq!(linked.0, StatusCode::OK, "{}", linked.1);
    assert_eq!(linked.1["history"]["returned_count"], 1);
    assert_eq!(
        linked.1["history"]["events"][0]["id"],
        "observation-failure-event"
    );
    assert_eq!(linked.1["history"]["through_sequence"], 101);
    assert_eq!(
        observe(
            &app,
            "default",
            &format!("/api/workflows/observation-related/observation?history_page_token={token}")
        )
        .await
        .0,
        StatusCode::UNPROCESSABLE_ENTITY
    );
    let related = observe(
        &app,
        "default",
        "/api/workflows/observation-related/observation",
    )
    .await;
    assert_eq!(related.1["parents"]["returned_count"], 50);
    assert_eq!(related.1["parents"]["has_more"], true);
    assert_eq!(
        task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_run_waits").await,
        55
    );
    assert_eq!(
        task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_failures").await,
        12
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_history_retains_the_original_ceiling_and_pruned_state() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "observation-worker", json!(["echo"]), json!([])).await;
    let original = start(&app, "observation-window").await;
    let task = poll(&app, "observation-worker", "workflow").await;
    assert_eq!(
        finish_task(
            &app,
            &task,
            json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])
        )
        .await
        .0,
        StatusCode::OK
    );
    let run = original["run_id"].as_str().unwrap();
    let path = format!("/api/workflows/observation-window/runs/{run}/observation");
    let first = observe(&app, "default", &format!("{path}?history_page_size=1")).await;
    assert_eq!(first.0, StatusCode::OK, "{}", first.1);
    assert_eq!(first.1["history"]["returned_count"], 1);
    assert_eq!(first.1["current_waits"]["state"], "available");
    assert_eq!(first.1["current_waits"]["total_count"], 0);
    let ceiling = first.1["history"]["through_sequence"].as_i64().unwrap();
    let token = first.1["history"]["next_page_token"].as_str().unwrap();
    // A constructed later row checks the page boundary, not post-close admission.
    database.execute("INSERT INTO workflow_history_events(id,workflow_run_id,sequence,event_type,payload,recorded_at) SELECT 'observation-later',id,last_history_sequence+1,'SignalReceived','{}','2026-10-15 00:00:00' FROM workflow_runs WHERE workflow_instance_id='observation-window'").await;
    let encoded: String = token.bytes().map(|byte| format!("%{byte:02X}")).collect();
    let second_runtime = database.open().await.unwrap();
    let second_app = router(second_runtime.clone());
    let next = observe(
        &second_app,
        "default",
        &format!("{path}?history_page_token={encoded}"),
    )
    .await;
    assert_eq!(next.0, StatusCode::OK, "{}", next.1);
    assert_eq!(next.1["history"]["through_sequence"], ceiling);
    assert!(
        next.1["history"]["events"]
            .as_array()
            .unwrap()
            .iter()
            .all(|event| event["sequence"].as_i64().unwrap() <= ceiling)
    );
    assert!(next.1["history"]["next_page_token"].is_null());
    for suffix in [
        "history_page_size=0",
        "history_page_size=1001",
        "history_page_token=invalid",
    ] {
        assert_eq!(
            observe(&app, "default", &format!("{path}?{suffix}"))
                .await
                .0,
            StatusCode::UNPROCESSABLE_ENTITY
        );
    }
    database.execute("UPDATE workflow_runs SET details_pruned_at='2026-10-15 00:00:00' WHERE workflow_instance_id='observation-window'").await;
    database.execute("DELETE FROM workflow_history_events WHERE workflow_run_id IN (SELECT id FROM workflow_runs WHERE workflow_instance_id='observation-window')").await;
    let pruned = observe(&app, "default", &path).await;
    assert_eq!(pruned.0, StatusCode::OK);
    assert_eq!(pruned.1["history"]["details_state"], "pruned");
    assert_eq!(pruned.1["history"]["returned_count"], 0);
    assert_eq!(pruned.1["history"]["through_sequence"], 0);
    assert_eq!(pruned.1["history"]["has_more"], false);
    assert!(pruned.1["history"]["next_page_token"].is_null());
    assert_eq!(pruned.1["current_waits"]["state"], "pruned");
    second_runtime.close().await;
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_waits_use_matching_live_retry_metadata_and_preserve_failure_history() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(
        &app,
        "observation-retry-worker",
        json!(["echo"]),
        json!(["observed-activity"]),
    )
    .await;
    let started = start(&app, "observation-retry").await;
    let task = poll(&app, "observation-retry-worker", "workflow").await;
    let scheduled = finish_task(&app,&task,json!([{"type":"schedule_activity","activity_type":"observed-activity",
        "arguments":envelope(Payload::Array(vec![])),"retry_policy":{"max_attempts":3,"backoff_seconds":[0]},
        "start_to_close_timeout":120,"schedule_to_close_timeout":600}])).await;
    assert_eq!(scheduled.0, StatusCode::OK, "{}", scheduled.1);
    let activity = poll(&app, "observation-retry-worker", "activity").await;
    let failed = request(&app,"POST",&format!("/api/worker/activity-tasks/{}/fail",activity["task_id"].as_str().unwrap()),
        json!({"lease_owner":activity["lease_owner"],"activity_attempt_id":activity["activity_attempt_id"],
            "failure":{"type":"RuntimeException","message":"observe retained retry","non_retryable":false}})).await;
    assert_eq!(failed.0, StatusCode::OK, "{}", failed.1);
    // Replace the generated row with an older, independently retained identity.
    // This control deliberately exercises a mismatched/stale projection.
    database.execute("DELETE FROM workflow_run_waits").await;
    database.execute("INSERT INTO workflow_run_waits(id,workflow_run_id,workflow_instance_id,wait_id,position,kind,status,summary,target_type,resume_source_kind,resume_source_id,task_id,history_authority) SELECT 'observed-retry-wait',a.workflow_run_id,r.workflow_instance_id,'observed-retry',0,'activity','open','activity retry',a.activity_type,'activity',a.id,t.id,'typed_history' FROM activity_executions a JOIN workflow_runs r ON r.id=a.workflow_run_id JOIN workflow_tasks t ON t.workflow_run_id=a.workflow_run_id AND t.task_type='activity' AND t.status='ready'").await;
    let path = format!(
        "/api/workflows/observation-retry/runs/{}/observation",
        started["run_id"].as_str().unwrap()
    );
    let before = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let read = observe(&app, "default", &path).await;
    assert_eq!(read.0, StatusCode::OK, "{}", read.1);
    let wait = &read.1["current_waits"]["waits"][0];
    assert_eq!(wait["kind"], "activity_retry");
    assert_eq!(wait["state"], "eligible");
    assert_eq!(wait["task_status"], "ready");
    assert_eq!(wait["task_metadata_state"], "available");
    assert_eq!(wait["attempt_number"], 2);
    assert_eq!(wait["attempt_limit"], 3);
    assert!(wait["attempt_id"].is_null());
    assert!(wait["deadline_at"].is_string());
    assert_eq!(read.1["recent_failures"]["returned_count"], 0);
    assert_eq!(
        run_history(&app, &started["workflow_id"], &started["run_id"]).await,
        before
    );
    database.execute("UPDATE workflow_run_waits SET task_id=(SELECT id FROM workflow_tasks WHERE task_type='workflow' LIMIT 1)").await;
    let mismatch = observe(&app, "default", &path).await;
    let wait = &mismatch.1["current_waits"]["waits"][0];
    assert_eq!(wait["kind"], "activity");
    assert_eq!(wait["state"], "resume_time_unknown");
    assert_eq!(wait["task_metadata_state"], "unavailable");
    assert!(wait["next_scheduled_resume_at"].is_null());
    assert!(wait["task_id"].is_null());
    let retry = poll(&app, "observation-retry-worker", "activity").await;
    let failed = request(&app,"POST",&format!("/api/worker/activity-tasks/{}/fail",retry["task_id"].as_str().unwrap()),
        json!({"lease_owner":retry["lease_owner"],"activity_attempt_id":retry["activity_attempt_id"],
            "failure":{"type":"RuntimeException","message":"terminal observed failure","non_retryable":true}})).await;
    assert_eq!(failed.0, StatusCode::OK, "{}", failed.1);
    let before = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let final_read = observe(&app, "default", &path).await;
    assert_eq!(final_read.0, StatusCode::OK, "{}", final_read.1);
    assert_eq!(final_read.1["recent_failures"]["returned_count"], 1);
    assert_eq!(
        final_read.1["recent_failures"]["failures"][0]["supporting_event"]["event_type"],
        "ActivityFailed"
    );
    assert_eq!(
        final_read.1["current_waits"]["waits"][0]["unavailable_reason"],
        "activity_no_longer_open"
    );
    assert_eq!(
        run_history(&app, &started["workflow_id"], &started["run_id"]).await,
        before
    );
    runtime.close().await;
    database.remove().await;
}
