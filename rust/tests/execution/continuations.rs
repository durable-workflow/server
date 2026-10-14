use super::*;

#[tokio::test]
async fn continuation_refuses_pending_query_beyond_the_cache_scan_prefix() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "continuation-worker", json!(["echo"]), json!([])).await;
    let original = start(&app, "continuation-query-boundary").await;
    let task = poll(&app, "continuation-worker", "workflow").await;
    let run = original["run_id"].as_str().unwrap();
    let column = match &database {
        TestDatabase::MySql { .. } => "`key`",
        _ => "\"key\"",
    };
    let expires = chrono::Utc::now().timestamp() + 600;
    // Construct the storage boundary explicitly. The predecessor query sorts
    // after all 65 unrelated entries and cannot appear in a prefix scan.
    // SQL interpolation here uses only this test's generated ULID, numeric
    // counters and fixed keys/statuses, never application-supplied input.
    for index in 0..65 {
        database.execute(sqlx::AssertSqlSafe(format!(
            "INSERT INTO dw_query_cache({column},value,expiration) VALUES ('dw-native-query:000{index:03}','{{\"run_id\":\"other\",\"status\":\"completed\"}}',{expires})"
        ))).await;
    }
    database.execute(sqlx::AssertSqlSafe(format!(
        "INSERT INTO dw_query_cache({column},value,expiration) VALUES ('dw-native-query:zzz','{{\"run_id\":\"{run}\",\"status\":\"pending\"}}',{expires})"
    ))).await;
    let path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        task["task_id"].as_str().unwrap()
    );
    let history_path = format!("/api/workflows/continuation-query-boundary/runs/{run}/history");
    let before = request(&app, "GET", &history_path, Value::Null).await.1;
    let body = completion(&task, json!([{"type":"continue_as_new"}]));
    for status in ["pending", "leased"] {
        database.execute(sqlx::AssertSqlSafe(format!(
            "UPDATE dw_query_cache SET value='{{\"run_id\":\"{run}\",\"status\":\"{status}\"}}' WHERE {column}='dw-native-query:zzz'"
        ))).await;
        let refused = request(&app, "POST", &path, body.clone()).await;
        assert_eq!(refused.0, StatusCode::CONFLICT);
        assert_eq!(refused.1["reason"], "continue_as_new_transfer_unqualified");
        assert_eq!(
            request(&app, "GET", &history_path, Value::Null).await.1,
            before
        );
        assert_eq!(
            request(
                &app,
                "GET",
                "/api/workflows/continuation-query-boundary/runs",
                Value::Null
            )
            .await
            .1["runs"]
                .as_array()
                .unwrap()
                .len(),
            1
        );
    }
    database.execute(sqlx::AssertSqlSafe(format!(
        "UPDATE dw_query_cache SET value='{{\"run_id\":\"{run}\",\"status\":\"completed\"}}' WHERE {column}='dw-native-query:zzz'"
    ))).await;
    database.execute(sqlx::AssertSqlSafe(format!(
        "INSERT INTO dw_query_cache({column},value,expiration) VALUES ('foreign-query:pending','{{\"run_id\":\"{run}\",\"status\":\"pending\"}}',{expires})"
    ))).await;
    database.execute(sqlx::AssertSqlSafe(format!(
        "INSERT INTO dw_query_cache({column},value,expiration) VALUES ('dw-native-query:expired','{{\"run_id\":\"{run}\",\"status\":\"pending\"}}',0)"
    ))).await;
    let committed = request(&app, "POST", &path, body).await;
    assert_eq!(committed.0, StatusCode::OK, "{}", committed.1);
    assert_eq!(
        request(
            &app,
            "GET",
            "/api/workflows/continuation-query-boundary/runs",
            Value::Null
        )
        .await
        .1["runs"]
            .as_array()
            .unwrap()
            .len(),
        2
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn malformed_or_unsupported_continuation_refuses_without_a_successor() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "continuation-worker", json!(["echo"]), json!([])).await;
    let original = start(&app, "continuation-refusals").await;
    let task = poll(&app, "continuation-worker", "workflow").await;
    let path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        task["task_id"].as_str().unwrap()
    );
    let history_path = format!(
        "/api/workflows/continuation-refusals/runs/{}/history",
        original["run_id"].as_str().unwrap()
    );
    let before = request(&app, "GET", &history_path, Value::Null).await.1;
    for commands in [
        json!([{"type":"continue_as_new","arguments":envelope(Payload::Long(7))}]),
        json!([{"type":"continue_as_new","workflow_type":"different"}]),
        json!([{"type":"continue_as_new","queue":"different"}]),
        json!([{"type":"continue_as_new"},{"type":"complete_workflow","result":envelope(Payload::Null)}]),
    ] {
        assert_eq!(
            request(&app, "POST", &path, completion(&task, commands))
                .await
                .0,
            StatusCode::UNPROCESSABLE_ENTITY
        );
        assert_eq!(
            request(&app, "GET", &history_path, Value::Null).await.1,
            before
        );
        assert_eq!(
            request(
                &app,
                "GET",
                "/api/workflows/continuation-refusals/runs",
                Value::Null
            )
            .await
            .1["runs"]
                .as_array()
                .unwrap()
                .len(),
            1
        );
    }
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn failed_continuation_history_append_rolls_back_run_link_pointer_and_memo() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "continuation-worker", json!(["echo"]), json!([])).await;
    let original = request(
        &app,
        "POST",
        "/api/workflows",
        json!({"workflow_id":"continuation-rollback","workflow_type":"echo",
        "task_queue":"test","input":envelope(Payload::Array(vec![])),"memo":{"keep":7}}),
    )
    .await;
    assert_eq!(original.0, StatusCode::CREATED);
    let task = poll(&app, "continuation-worker", "workflow").await;
    let before = request(
        &app,
        "GET",
        "/api/workflows/continuation-rollback",
        Value::Null,
    )
    .await
    .1;
    assert_eq!(before["memo"], json!({"keep":7}));
    match &database {
        TestDatabase::Sqlite(_)=>database.execute("CREATE TRIGGER refuse_continuation BEFORE INSERT ON workflow_history_events WHEN NEW.event_type='WorkflowContinuedAsNew' BEGIN SELECT RAISE(ABORT,'synthetic continuation history refusal'); END").await,
        TestDatabase::MySql{..}=>database.execute("CREATE TRIGGER refuse_continuation BEFORE INSERT ON workflow_history_events FOR EACH ROW BEGIN IF NEW.event_type='WorkflowContinuedAsNew' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic continuation history refusal'; END IF; END").await,
        TestDatabase::Postgres{..}=> {
            database.execute("CREATE FUNCTION refuse_continuation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.event_type='WorkflowContinuedAsNew' THEN RAISE EXCEPTION 'synthetic continuation history refusal'; END IF; RETURN NEW; END; $$").await;
            database.execute("CREATE TRIGGER refuse_continuation BEFORE INSERT ON workflow_history_events FOR EACH ROW EXECUTE FUNCTION refuse_continuation()").await;
        }
    }
    let path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        task["task_id"].as_str().unwrap()
    );
    let refused = request(
        &app,
        "POST",
        &path,
        completion(&task, json!([{"type":"continue_as_new"}])),
    )
    .await;
    assert_eq!(refused.0, StatusCode::SERVICE_UNAVAILABLE);
    assert_eq!(
        request(
            &app,
            "GET",
            "/api/workflows/continuation-rollback",
            Value::Null
        )
        .await
        .1,
        before
    );
    assert_eq!(
        request(
            &app,
            "GET",
            "/api/workflows/continuation-rollback/runs",
            Value::Null
        )
        .await
        .1["runs"]
            .as_array()
            .unwrap()
            .len(),
        1
    );
    match &database {
        TestDatabase::Postgres { .. } => {
            database
                .execute("DROP TRIGGER refuse_continuation ON workflow_history_events")
                .await;
            database
                .execute("DROP FUNCTION refuse_continuation()")
                .await;
        }
        _ => database.execute("DROP TRIGGER refuse_continuation").await,
    }
    assert_eq!(
        request(
            &app,
            "POST",
            &path,
            completion(&task, json!([{"type":"continue_as_new"}]))
        )
        .await
        .0,
        StatusCode::OK
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn continuation_commits_one_successor_and_preserves_original_receipts_after_cold_open() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "continuation-worker", json!(["echo"]), json!([])).await;
    let initial=request(&app,"POST","/api/workflows",json!({"workflow_id":"continuation-original","workflow_type":"echo",
        "task_queue":"test","input":envelope(Payload::Array(vec![Payload::Long(9007199254740993)])),
        "execution_timeout_seconds":1200,"run_timeout_seconds":600,"memo":{"keep":{"integer":9007199254740993_i64,"optional":null,"unicode":"continue λ"},"empty":[]}})).await;
    assert_eq!(initial.0, StatusCode::CREATED, "{}", initial.1);
    let old = initial.1["run_id"].as_str().unwrap();
    let before = request(
        &app,
        "GET",
        "/api/workflows/continuation-original",
        Value::Null,
    )
    .await
    .1;
    let task = poll(&app, "continuation-worker", "workflow").await;
    let path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        task["task_id"].as_str().unwrap()
    );
    let mut body = completion(
        &task,
        json!([{"type":"continue_as_new","arguments":envelope(Payload::Array(vec![Payload::Long(7)])),"workflow_type":null,"queue":"test"}]),
    );
    body["sticky_cache"] = json!({"worker_id":"continuation-worker","ttl_seconds":300});
    let committed = request(&app, "POST", &path, body.clone()).await;
    assert_eq!(committed.0, StatusCode::OK, "{}", committed.1);
    assert_eq!(committed.1["recorded"], true);
    let current = request(
        &app,
        "GET",
        "/api/workflows/continuation-original",
        Value::Null,
    )
    .await
    .1;
    let next = current["run_id"].as_str().unwrap().to_owned();
    assert_ne!(next, old);
    assert_eq!(current["run_number"], 2);
    assert_eq!(current["memo"], before["memo"]);
    assert_eq!(
        current["execution_deadline_at"],
        before["execution_deadline_at"]
    );
    assert_eq!(current["continued_from_run_id"], old);
    let start =
        chrono::DateTime::parse_from_rfc3339(current["started_at"].as_str().unwrap()).unwrap();
    let deadline =
        chrono::DateTime::parse_from_rfc3339(current["run_deadline_at"].as_str().unwrap()).unwrap();
    assert_eq!((deadline - start).num_seconds(), 600);
    runtime.close().await;
    let cold = database.open().await.unwrap();
    let app = router(cold.clone());
    let duplicate = request(&app, "POST", &path, body.clone()).await;
    assert_eq!(duplicate.0, StatusCode::OK);
    assert_eq!(duplicate.1["recorded"], false);
    let mut conflicting = body.clone();
    conflicting["commands"][0]["arguments"] = envelope(Payload::Array(vec![Payload::Long(8)]));
    let refused = request(&app, "POST", &path, conflicting).await;
    assert_eq!(refused.0, StatusCode::CONFLICT);
    assert_eq!(refused.1["reason"], "conflicting_completion");
    let runs = request(
        &app,
        "GET",
        "/api/workflows/continuation-original/runs",
        Value::Null,
    )
    .await;
    assert_eq!(runs.0, StatusCode::OK);
    assert_eq!(runs.1["runs"].as_array().unwrap().len(), 2);
    assert_eq!(
        runs.1["runs"]
            .as_array()
            .unwrap()
            .iter()
            .filter(|run| run["is_current_run"] == true)
            .count(),
        1
    );
    let selected = request(
        &app,
        "GET",
        &format!("/api/workflows/continuation-original/runs/{old}"),
        Value::Null,
    )
    .await
    .1;
    assert_eq!(selected["closed_reason"], "continued");
    let successor = poll(&app, "continuation-worker", "workflow").await;
    assert_eq!(successor["run_id"], next);
    let done = request(
        &app,
        "POST",
        &format!(
            "/api/worker/workflow-tasks/{}/complete",
            successor["task_id"].as_str().unwrap()
        ),
        completion(
            &successor,
            json!([{"type":"complete_workflow","result":envelope(Payload::Long(7))}]),
        ),
    )
    .await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    assert_eq!(
        request(&app, "POST", &path, body).await.1["recorded"],
        false
    );
    assert_eq!(
        request(
            &app,
            "GET",
            "/api/workflows/continuation-original",
            Value::Null
        )
        .await
        .1["output"],
        7
    );
    cold.close().await;
    database.remove().await;
}
