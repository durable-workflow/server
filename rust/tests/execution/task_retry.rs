use super::*;

fn failure(task: &Value) -> Value {
    json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],
        "failure":{"type":"RuntimeException","message":"temporary processor unavailable λ"}})
}

async fn report(app: &Router, task: &Value, body: Value) -> (StatusCode, Value) {
    request(
        app,
        "POST",
        &format!(
            "/api/worker/workflow-tasks/{}/fail",
            task["task_id"].as_str().unwrap()
        ),
        body,
    )
    .await
}

pub(super) async fn rows(database: &TestDatabase, sql: &'static str) -> i64 {
    match database {
        TestDatabase::Sqlite(dir) => {
            let pool = sqlx::SqlitePool::connect(&format!(
                "sqlite://{}",
                dir.path().join("runtime.sqlite").display()
            ))
            .await
            .unwrap();
            let count = sqlx::query_scalar(sql).fetch_one(&pool).await.unwrap();
            pool.close().await;
            count
        }
        TestDatabase::Postgres { options, .. } => {
            let pool = sqlx::postgres::PgPoolOptions::new()
                .connect_with(options.as_ref().clone())
                .await
                .unwrap();
            let count = sqlx::query_scalar(sql).fetch_one(&pool).await.unwrap();
            pool.close().await;
            count
        }
        TestDatabase::MySql { options, .. } => {
            let pool = sqlx::mysql::MySqlPoolOptions::new()
                .connect_with(options.as_ref().clone())
                .await
                .unwrap();
            let count = sqlx::query_scalar(sql).fetch_one(&pool).await.unwrap();
            pool.close().await;
            count
        }
    }
}

#[tokio::test]
async fn ordinary_retry_survives_restarts_with_routing_attempts_and_original_history() {
    let database = TestDatabase::new().await;
    let first = database.open().await.unwrap();
    let app = router(first.clone());
    register(&app, "retry-worker", json!(["echo"]), json!([])).await;
    let started = start(&app, "retry-original").await;
    database.execute("UPDATE workflow_tasks SET connection='retry-connection',priority=3,fairness_key='retry-class',fairness_weight=7").await;
    let original = poll(&app, "retry-worker", "workflow").await;
    let prefix = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let accepted = report(&app, &original, failure(&original)).await;
    assert_eq!(accepted.0, StatusCode::OK, "{}", accepted.1);
    assert_eq!(accepted.1["recorded"], true);
    assert_eq!(rows(&database, "SELECT COUNT(*) FROM workflow_tasks WHERE status='ready' AND attempt_count=1 AND connection='retry-connection' AND queue='test' AND priority=3 AND fairness_key='retry-class' AND fairness_weight=7").await, 1);
    assert_eq!(rows(&database, "SELECT COUNT(*) FROM workflow_tasks WHERE status='failed' AND last_error='temporary processor unavailable λ' AND lease_expires_at IS NULL").await, 1);
    drop(app);
    first.close().await;
    let second = database.open().await.unwrap();
    let app = router(second.clone());
    let next = poll(&app, "retry-worker", "workflow").await;
    assert_eq!(next["task_id"], accepted.1["next_task_id"]);
    assert_ne!(next["task_id"], original["task_id"]);
    assert_eq!(next["run_id"], started["run_id"]);
    assert_eq!(next["workflow_task_attempt"], 2);
    assert_eq!(rows(&database, "SELECT COUNT(*) FROM workflow_tasks WHERE status='leased' AND attempt_count=2 AND connection='retry-connection' AND queue='test' AND priority=3 AND fairness_key='retry-class' AND fairness_weight=7").await, 1);
    assert_eq!(
        report(&app, &original, failure(&original)).await.0,
        StatusCode::CONFLICT
    );
    let mut stale = failure(&next);
    stale["workflow_task_attempt"] = json!(1);
    assert_eq!(report(&app, &next, stale).await.0, StatusCode::CONFLICT);
    let accepted = report(&app, &next, failure(&next)).await;
    assert_eq!(accepted.0, StatusCode::OK, "{}", accepted.1);
    assert_eq!(
        run_history(&app, &started["workflow_id"], &started["run_id"]).await,
        prefix
    );
    drop(app);
    second.close().await;
    let third = database.open().await.unwrap();
    let app = router(third.clone());
    let last = poll(&app, "retry-worker", "workflow").await;
    assert_eq!(last["task_id"], accepted.1["next_task_id"]);
    assert_eq!(last["workflow_task_attempt"], 3);
    assert_eq!(last["run_id"], started["run_id"]);
    assert_eq!(
        finish_task(
            &app,
            &last,
            json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}])
        )
        .await
        .0,
        StatusCode::OK
    );
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(
        &history.as_array().unwrap()[..2],
        prefix.as_array().unwrap()
    );
    assert_eq!(history.as_array().unwrap().len(), 3);
    assert_eq!(history[2]["event_type"], "WorkflowCompleted");
    assert_eq!(poll(&app, "retry-worker", "workflow").await, Value::Null);
    let terminal = report(&app, &last, failure(&last)).await;
    assert_eq!(terminal.0, StatusCode::CONFLICT);
    assert_eq!(terminal.1["reason"], "run_closed");
    third.close().await;
    database.remove().await;
}

#[tokio::test]
async fn duplicate_failures_from_two_runtimes_create_one_retry() {
    let database = TestDatabase::new().await;
    let one = database.open().await.unwrap();
    let two = database.open().await.unwrap();
    let a = router(one.clone());
    let b = router(two.clone());
    register(&a, "retry-worker", json!(["echo"]), json!([])).await;
    let started = start(&a, "retry-race").await;
    let task = poll(&a, "retry-worker", "workflow").await;
    let (left, right) = tokio::join!(
        report(&a, &task, failure(&task)),
        report(&b, &task, failure(&task))
    );
    let (accepted, refused) = if left.0 == StatusCode::OK {
        (left, right)
    } else {
        (right, left)
    };
    assert_eq!(accepted.0, StatusCode::OK, "{}", accepted.1);
    assert_eq!(refused.0, StatusCode::CONFLICT, "{}", refused.1);
    assert_eq!(refused.1["reason"], "task_not_leased");
    assert_eq!(
        rows(
            &database,
            "SELECT COUNT(*) FROM workflow_tasks WHERE task_type='workflow'"
        )
        .await,
        2
    );
    let next = poll(&a, "retry-worker", "workflow").await;
    assert_eq!(next["task_id"], accepted.1["next_task_id"]);
    assert_eq!(next["workflow_task_attempt"], 2);
    assert_eq!(next["run_id"], started["run_id"]);
    assert_eq!(poll(&b, "retry-worker", "workflow").await, Value::Null);
    one.close().await;
    two.close().await;
    database.remove().await;
}

#[tokio::test]
async fn concurrent_failure_and_completion_have_one_committed_outcome() {
    let database = TestDatabase::new().await;
    let one = database.open().await.unwrap();
    let two = database.open().await.unwrap();
    let a = router(one.clone());
    let b = router(two.clone());
    register(&a, "retry-worker", json!(["echo"]), json!([])).await;
    let started = start(&a, "retry-completion-race").await;
    let task = poll(&a, "retry-worker", "workflow").await;
    let commands =
        json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}]);
    let (failed, completed) = tokio::join!(
        report(&a, &task, failure(&task)),
        finish_task(&b, &task, commands.clone())
    );
    if failed.0 == StatusCode::OK {
        assert_eq!(completed.0, StatusCode::CONFLICT, "{}", completed.1);
        let next = poll(&a, "retry-worker", "workflow").await;
        assert_eq!(next["task_id"], failed.1["next_task_id"]);
        assert_eq!(finish_task(&a, &next, commands).await.0, StatusCode::OK);
    } else {
        assert_eq!(failed.0, StatusCode::CONFLICT, "{}", failed.1);
        assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
    }
    let history = run_history(&a, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(history.as_array().unwrap().len(), 3);
    assert_eq!(history[2]["event_type"], "WorkflowCompleted");
    assert_eq!(poll(&a, "retry-worker", "workflow").await, Value::Null);
    one.close().await;
    two.close().await;
    database.remove().await;
}

#[tokio::test]
async fn replay_failures_and_invalid_reports_leave_the_original_lease_usable() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "retry-worker", json!(["echo"]), json!([])).await;
    start(&app, "retry-refusals").await;
    let task = poll(&app, "retry-worker", "workflow").await;
    for value in [
        json!({"message":"non-deterministic replay","type":"WorkerFailure"}),
        json!({"message":"ordinary text","reason":"duplicate_version_marker"}),
        json!({"message":"temporary","sequence":0}),
        json!({"message":"temporary","type":7}),
    ] {
        let mut body = failure(&task);
        body["failure"] = value;
        assert_eq!(
            report(&app, &task, body).await.0,
            StatusCode::UNPROCESSABLE_ENTITY
        );
        assert_eq!(
            rows(
                &database,
                "SELECT COUNT(*) FROM workflow_tasks WHERE status='leased'"
            )
            .await,
            1
        );
        assert_eq!(
            rows(
                &database,
                "SELECT COUNT(*) FROM workflow_tasks WHERE status='failed'"
            )
            .await,
            0
        );
    }
    assert_eq!(
        finish_task(
            &app,
            &task,
            json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}])
        )
        .await
        .0,
        StatusCode::OK
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn published_rust_replay_reports_keep_absent_fields_and_require_original_task_repair() {
    for (index, reason) in [
        "version_marker_incompatible_range",
        "activity_retry_policy_mismatch",
    ]
    .into_iter()
    .enumerate()
    {
        let database = TestDatabase::new().await;
        let first = database.open().await.unwrap();
        let app = router(first.clone());
        register(&app, "rust-replay-worker", json!(["echo"]), json!([])).await;
        let started = start(&app, &format!("rust-replay-{index}")).await;
        let task = poll(&app, "rust-replay-worker", "workflow").await;
        let prefix = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
        let body = json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],
            "failure":{"type":"RustWorkflowTaskFailure",
                "message":format!("non-deterministic workflow replay ({reason}) at sequence Some(1): recorded workflow command differs from current code")}});
        let accepted = report(&app, &task, body.clone()).await;
        assert_eq!(accepted.0, StatusCode::OK, "{}", accepted.1);
        assert_eq!(accepted.1["next_task_id"], Value::Null);
        assert_eq!(report(&app, &task, body).await.0, StatusCode::CONFLICT);
        assert_eq!(
            poll(&app, "rust-replay-worker", "workflow").await,
            Value::Null
        );
        assert_eq!(
            run_history(&app, &started["workflow_id"], &started["run_id"]).await,
            prefix
        );
        drop(app);
        first.close().await;

        let second = database.open().await.unwrap();
        let app = router(second.clone());
        let path = format!(
            "/api/workflows/{}/runs/{}",
            started["workflow_id"].as_str().unwrap(),
            started["run_id"].as_str().unwrap()
        );
        let debug = request(&app, "GET", &format!("{path}/debug"), Value::Null).await;
        assert_eq!(debug.0, StatusCode::OK, "{}", debug.1);
        let failure = &debug.1["latest_workflow_task_failure"];
        assert_eq!(failure["type"], "RustWorkflowTaskFailure");
        assert_eq!(failure["replay_blocked"], true);
        assert!(failure.get("reason").is_none());
        assert!(failure.get("sequence").is_none());
        assert_eq!(
            poll(&app, "rust-replay-worker", "workflow").await,
            Value::Null
        );
        assert_eq!(
            request(&app, "POST", &format!("{path}/repair"), json!({}))
                .await
                .0,
            StatusCode::OK
        );
        let repaired = poll(&app, "rust-replay-worker", "workflow").await;
        assert_eq!(repaired["task_id"], task["task_id"]);
        assert_eq!(repaired["workflow_task_attempt"], 2);
        assert_eq!(finish_task(&app, &repaired, json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}])).await.0, StatusCode::OK);
        let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
        assert_eq!(
            &history.as_array().unwrap()[..2],
            prefix.as_array().unwrap()
        );
        assert_eq!(history[2]["event_type"], "RepairRequested");
        assert_eq!(history[3]["event_type"], "WorkflowCompleted");
        second.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn worker_reported_range_failure_requires_explicit_original_task_repair_across_restarts() {
    let database = TestDatabase::new().await;
    let first = database.open().await.unwrap();
    let app = router(first.clone());
    register(&app, "range-worker", json!(["echo"]), json!([])).await;
    let started = start(&app, "range-blocked").await;
    let task = poll(&app, "range-worker", "workflow").await;
    let prefix = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let mut body = failure(&task);
    body["failure"] = json!({
        "message":"Recorded version 1 is outside supported range 2..2.",
        "type":"DurableWorkflow\\Exception\\NonDeterministicWorkflow",
        "reason":"version_marker_incompatible_range",
        "sequence":1
    });
    let mut stale = body.clone();
    stale["workflow_task_attempt"] = json!(0);
    assert_eq!(
        report(&app, &task, stale).await.0,
        StatusCode::UNPROCESSABLE_ENTITY
    );
    let mut foreign = body.clone();
    foreign["lease_owner"] = json!("another-worker");
    assert_eq!(report(&app, &task, foreign).await.0, StatusCode::CONFLICT);
    let accepted = report(&app, &task, body.clone()).await;
    assert_eq!(accepted.0, StatusCode::OK, "{}", accepted.1);
    assert_eq!(accepted.1["recorded"], true);
    assert_eq!(accepted.1["next_task_id"], Value::Null);
    let blocked_summary =
        run_observation_active_summaries::observe(&app, "default", &started).await;
    assert_eq!(blocked_summary["liveness_state"], "workflow_replay_blocked");
    assert_eq!(
        blocked_summary["liveness_reason"],
        "Recorded version 1 is outside supported range 2..2."
    );
    assert_eq!(
        blocked_summary["history_event_count"],
        prefix.as_array().unwrap().len()
    );
    assert_eq!(rows(&database, "SELECT COUNT(*) FROM workflow_tasks WHERE status='failed' AND attempt_count=1 AND repair_count=0 AND lease_expires_at IS NULL").await, 1);
    assert_eq!(
        rows(
            &database,
            "SELECT COUNT(*) FROM workflow_tasks WHERE status IN ('ready','leased')"
        )
        .await,
        0
    );
    assert_eq!(
        rows(
            &database,
            "SELECT COUNT(*) FROM workflow_runs WHERE status='pending'"
        )
        .await,
        1
    );
    assert_eq!(
        rows(&database, "SELECT COUNT(*) FROM workflow_failures").await,
        0
    );
    assert_eq!(
        report(&app, &task, body.clone()).await.0,
        StatusCode::CONFLICT
    );
    assert_eq!(
        run_history(&app, &started["workflow_id"], &started["run_id"]).await,
        prefix
    );
    drop(app);
    first.close().await;
    let second = database.open().await.unwrap();
    let app = router(second.clone());
    assert_eq!(poll(&app, "range-worker", "workflow").await, Value::Null);
    assert_eq!(
        report(&app, &task, body.clone()).await.0,
        StatusCode::CONFLICT
    );
    assert_eq!(
        rows(
            &database,
            "SELECT COUNT(*) FROM workflow_tasks WHERE status='failed'"
        )
        .await,
        1
    );
    assert_eq!(
        run_history(&app, &started["workflow_id"], &started["run_id"]).await,
        prefix
    );
    let repair_path = format!(
        "/api/workflows/{}/runs/{}/repair",
        started["workflow_id"].as_str().unwrap(),
        started["run_id"].as_str().unwrap()
    );
    let historical = request(
        &app,
        "POST",
        &format!(
            "/api/workflows/{}/runs/another-run/repair",
            started["workflow_id"].as_str().unwrap()
        ),
        json!({}),
    )
    .await;
    assert_eq!(historical.0, StatusCode::CONFLICT);
    assert_eq!(historical.1["reason"], "historical_run_command_rejected");
    assert_eq!(
        request(&app, "POST", &repair_path, json!({"request_id":42}))
            .await
            .0,
        StatusCode::UNPROCESSABLE_ENTITY
    );
    let (exhausted_sequence, exhausted_repairs) = match &database {
        TestDatabase::Sqlite(_) => (
            "UPDATE workflow_runs SET last_command_sequence=9223372036854775807",
            "UPDATE workflow_tasks SET repair_count=9223372036854775807",
        ),
        TestDatabase::Postgres { .. } => (
            "UPDATE workflow_runs SET last_command_sequence=2147483647",
            "UPDATE workflow_tasks SET repair_count=2147483647",
        ),
        TestDatabase::MySql { .. } => (
            "UPDATE workflow_runs SET last_command_sequence=4294967295",
            "UPDATE workflow_tasks SET repair_count=4294967295",
        ),
    };
    database.execute(exhausted_sequence).await;
    assert_eq!(
        request(&app, "POST", &repair_path, json!({})).await.1["reason"],
        "command_sequence_exhausted"
    );
    assert_eq!(
        rows(
            &database,
            "SELECT COUNT(*) FROM workflow_tasks WHERE status='failed' AND repair_count=0"
        )
        .await,
        1
    );
    assert_eq!(
        run_history(&app, &started["workflow_id"], &started["run_id"]).await,
        prefix
    );
    database
        .execute("UPDATE workflow_runs SET last_command_sequence=1")
        .await;
    database.execute(exhausted_repairs).await;
    assert_eq!(
        request(&app, "POST", &repair_path, json!({})).await.1["reason"],
        "repair_count_exhausted"
    );
    assert_eq!(
        rows(
            &database,
            "SELECT COUNT(*) FROM workflow_tasks WHERE status='failed' AND lease_expires_at IS NULL"
        )
        .await,
        1
    );
    assert_eq!(
        run_history(&app, &started["workflow_id"], &started["run_id"]).await,
        prefix
    );
    database
        .execute("UPDATE workflow_tasks SET repair_count=0")
        .await;
    // Match the published SDK: JSON content type, but zero body bytes.
    let req = Request::builder()
        .method("POST")
        .uri(&repair_path)
        .header("authorization", "Bearer test-token")
        .header("content-type", "application/json")
        .header("x-namespace", "default")
        .header("x-durable-workflow-control-plane-version", "2")
        .body(Body::empty())
        .unwrap();
    let response = app.clone().oneshot(req).await.unwrap();
    assert_eq!(response.status(), StatusCode::OK);
    let repaired: Value = serde_json::from_slice(
        &to_bytes(response.into_body(), 4 * 1024 * 1024)
            .await
            .unwrap(),
    )
    .unwrap();
    assert_eq!(repaired["outcome"], "repair_dispatched");
    let repaired_summary =
        run_observation_active_summaries::observe(&app, "default", &started).await;
    assert_eq!(repaired_summary["liveness_state"], "workflow_task_ready");
    assert_eq!(rows(&database, "SELECT COUNT(*) FROM workflow_run_summaries WHERE task_problem=TRUE AND repair_blocked_reason='repair_not_needed' AND next_task_status='ready'").await, 1);
    assert_eq!(repaired["target_scope"], "instance");
    assert_eq!(repaired["requested_run_id"], Value::Null);
    assert_eq!(repaired["resolved_run_id"], started["run_id"]);
    assert_eq!(rows(&database, "SELECT COUNT(*) FROM workflow_tasks WHERE status='ready' AND attempt_count=1 AND repair_count=1 AND lease_owner IS NULL AND lease_expires_at IS NULL AND last_error IS NULL").await, 1);
    assert_eq!(rows(&database, "SELECT COUNT(*) FROM workflow_commands WHERE command_type='repair' AND target_scope='instance' AND requested_workflow_run_id IS NULL").await, 1);
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(
        &history.as_array().unwrap()[..prefix.as_array().unwrap().len()],
        prefix.as_array().unwrap()
    );
    let repair = history.as_array().unwrap().last().unwrap();
    assert_eq!(repair["event_type"], "RepairRequested");
    assert_eq!(repair["payload"]["task_id"], task["task_id"]);
    assert_eq!(
        repair["payload"]["liveness_state"],
        "workflow_replay_blocked"
    );
    assert_eq!(repair["payload"]["wait_kind"], Value::Null);
    let decoded = ValueCodec::new()
        .unwrap()
        .decode(
            repair["payload"]["command"]["payload"]["blob"]
                .as_str()
                .unwrap(),
        )
        .unwrap();
    assert_eq!(
        decoded,
        Payload::Map(std::collections::BTreeMap::from([
            (
                "liveness_state".into(),
                Payload::String("workflow_replay_blocked".into())
            ),
            ("wait_kind".into(), Payload::Null),
            (
                "task_id".into(),
                Payload::String(task["task_id"].as_str().unwrap().into())
            ),
            ("task_type".into(), Payload::String("workflow".into())),
        ]))
    );
    // As in PHP, repairing again records that no repair was needed.
    let again = request(&app, "POST", &repair_path, json!({})).await;
    assert_eq!(again.0, StatusCode::OK, "{}", again.1);
    assert_eq!(again.1["outcome"], "repair_not_needed");
    assert_eq!(
        report(&app, &task, body.clone()).await.0,
        StatusCode::CONFLICT
    );
    drop(app);
    second.close().await;
    let third = database.open().await.unwrap();
    let app = router(third.clone());
    register(&app, "restored-worker", json!(["echo"]), json!([])).await;
    let resumed = poll(&app, "restored-worker", "workflow").await;
    assert_eq!(resumed["task_id"], task["task_id"]);
    assert_eq!(resumed["run_id"], started["run_id"]);
    assert_eq!(resumed["workflow_task_attempt"], 2);
    assert_ne!(resumed["lease_owner"], task["lease_owner"]);
    assert_eq!(report(&app, &task, body).await.0, StatusCode::CONFLICT);
    assert_eq!(
        finish_task(
            &app,
            &resumed,
            json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}])
        )
        .await
        .0,
        StatusCode::OK
    );
    let completed = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(
        &completed.as_array().unwrap()[..history.as_array().unwrap().len()],
        history.as_array().unwrap()
    );
    assert_eq!(
        completed.as_array().unwrap().last().unwrap()["event_type"],
        "WorkflowCompleted"
    );
    assert_eq!(poll(&app, "restored-worker", "workflow").await, Value::Null);
    third.close().await;
    database.remove().await;
}

#[tokio::test]
async fn two_nodes_commit_one_range_repair_with_server_derived_audit_identity() {
    let database = TestDatabase::new().await;
    let first = database.open().await.unwrap();
    let second = database.open().await.unwrap();
    let a = router(first.clone());
    let b = router(second.clone());
    register(&a, "range-worker", json!(["echo"]), json!([])).await;
    let started = start(&a, "range-repair-race").await;
    let task = poll(&a, "range-worker", "workflow").await;
    let mut body = failure(&task);
    body["failure"] = json!({"message":"Recorded version 1 is outside range 2..2.",
        "type":"DurableWorkflow\\Exception\\NonDeterministicWorkflow", "reason":"version_marker_incompatible_range","sequence":1});
    assert_eq!(report(&a, &task, body).await.0, StatusCode::OK);
    let path = format!(
        "/api/workflows/{}/runs/{}/repair",
        started["workflow_id"].as_str().unwrap(),
        started["run_id"].as_str().unwrap()
    );
    let spoof = json!({"request_id":"original repair λ","principal":{"type":"auth:token","id":"forged"},
        "context":{"principal":{"id":"forged"}},"target_scope":"run"});
    let (left, right) = tokio::join!(
        request(&a, "POST", &path, spoof),
        request(&b, "POST", &path, json!({}))
    );
    // One repair dispatches the task. As in PHP, the other then finds
    // nothing to repair and records that.
    assert_eq!((left.0, right.0), (StatusCode::OK, StatusCode::OK), "{} {}", left.1, right.1);
    let mut outcomes = [left.1["outcome"].clone(), right.1["outcome"].clone()];
    outcomes.sort_by_key(|outcome| outcome.to_string());
    assert_eq!(outcomes, [json!("repair_dispatched"), json!("repair_not_needed")]);
    assert_eq!(
        rows(
            &database,
            "SELECT COUNT(*) FROM workflow_commands WHERE command_type='repair'"
        )
        .await,
        2
    );
    assert_eq!(
        rows(
            &database,
            "SELECT COUNT(*) FROM workflow_tasks WHERE status='ready' AND repair_count=1"
        )
        .await,
        1
    );
    let history = run_history(&a, &started["workflow_id"], &started["run_id"]).await;
    let repair = &history.as_array().unwrap().last().unwrap()["payload"]["command"];
    assert_eq!(
        repair["context"]["principal"],
        json!({"type":"auth:token","id":"legacy-token","label":"Admin"})
    );
    assert_eq!(repair["principal_id"], "legacy-token");
    assert_eq!(repair["request_path"], path);
    assert_eq!(repair["target_scope"], "instance");
    first.close().await;
    second.close().await;
    database.remove().await;
}
