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

async fn rows(database: &TestDatabase, sql: &'static str) -> i64 {
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
