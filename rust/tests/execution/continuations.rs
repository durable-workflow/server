use super::*;

#[tokio::test]
async fn continuation_inherits_committed_upserts_before_successor_commands() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let worker = "continuation-memo-worker";
    assert_eq!(
        request(
            &app,
            "POST",
            "/api/worker/register",
            json!({"worker_id":worker,"task_queue":"test","runtime":"php",
            "supported_workflow_types":["echo"],"supported_activity_types":[],
            "capabilities":["memo_upserts"]})
        )
        .await
        .0,
        StatusCode::CREATED
    );
    let original = request(
        &app,
        "POST",
        "/api/workflows",
        json!({"workflow_id":"continuation-authored-memos","workflow_type":"echo",
            "task_queue":"test","input":envelope(Payload::Array(vec![])),
            "memo":{"keep":{"count":9007199254740993_i64,"optional":null,"message":"memo λ"},
                "replace":1,"remove":"old"}}),
    )
    .await;
    assert_eq!(original.0, StatusCode::CREATED);
    let old = original.1["run_id"].as_str().unwrap();
    let task = poll(&app, worker, "workflow").await;
    let path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        task["task_id"].as_str().unwrap()
    );
    let entries = Payload::Map(std::collections::BTreeMap::from([
        ("replace".into(), Payload::Boolean(false)),
        ("remove".into(), Payload::Null),
        (
            "added".into(),
            Payload::Array(vec![Payload::Long(9007199254740993), Payload::Null]),
        ),
    ]));
    let body = completion(
        &task,
        json!([
            {"type":"upsert_memo","entries":envelope(entries)},
            {"type":"continue_as_new"}
        ]),
    );
    // Published PHP applies the memo before the continuation wherever the
    // continuation sits in the batch (`command_batches`).
    let committed = request(&app, "POST", &path, body.clone()).await;
    assert_eq!(committed.0, StatusCode::OK, "{}", committed.1);
    let parent_path = format!("/api/workflows/continuation-authored-memos/runs/{old}");
    let parent = request(&app, "GET", &parent_path, Value::Null).await.1;
    let expected = json!({"keep":{"count":9007199254740993_i64,"optional":null,"message":"memo λ"},
        "replace":false,"added":[9007199254740993_i64,null]});
    assert_eq!(parent["memo"], expected);
    let current_path = "/api/workflows/continuation-authored-memos";
    let current = request(&app, "GET", current_path, Value::Null).await.1;
    let next = current["run_id"].as_str().unwrap().to_owned();
    assert_ne!(next, old);
    assert_eq!(current["memo"], expected);
    let history_path = format!("{parent_path}/history");
    let history = request(&app, "GET", &history_path, Value::Null).await.1;
    assert_eq!(
        history["events"]
            .as_array()
            .unwrap()
            .iter()
            .map(|event| event["event_type"].as_str().unwrap())
            .collect::<Vec<_>>(),
        [
            "StartAccepted",
            "WorkflowStarted",
            "MemoUpserted",
            "WorkflowContinuedAsNew"
        ]
    );
    runtime.close().await;
    let cold = database.open().await.unwrap();
    let app = router(cold.clone());
    assert_eq!(
        request(&app, "GET", current_path, Value::Null).await.1["memo"],
        expected
    );
    let duplicate = request(&app, "POST", &path, body.clone()).await;
    assert_eq!(duplicate.0, StatusCode::OK);
    assert_eq!(duplicate.1["recorded"], false);
    let mut conflict = body;
    conflict["commands"][0]["entries"] = envelope(Payload::Map(std::collections::BTreeMap::from(
        [("replace".into(), Payload::Long(9))],
    )));
    assert_eq!(
        request(&app, "POST", &path, conflict).await.0,
        StatusCode::CONFLICT
    );
    let successor = poll(&app, worker, "workflow").await;
    assert_eq!(successor["run_id"], next);
    let done = request(&app, "POST",
        &format!("/api/worker/workflow-tasks/{}/complete", successor["task_id"].as_str().unwrap()),
        completion(&successor, json!([
            {"type":"upsert_memo","entries":envelope(Payload::Map(std::collections::BTreeMap::from([
                ("replace".into(), Payload::String("successor".into()))
            ])))},
            {"type":"complete_workflow","result":envelope(Payload::Long(7))}
        ]))).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    let finished = request(&app, "GET", current_path, Value::Null).await.1;
    let mut successor_memo = expected;
    successor_memo["replace"] = json!("successor");
    assert_eq!(finished["memo"], successor_memo);
    assert_eq!(finished["output"], 7);
    assert_eq!(
        request(&app, "GET", &parent_path, Value::Null).await.1,
        parent
    );
    assert_eq!(
        request(&app, "GET", &history_path, Value::Null).await.1,
        history
    );
    assert_eq!(
        request(
            &app,
            "GET",
            "/api/workflows/continuation-authored-memos/runs",
            Value::Null
        )
        .await
        .1["runs"]
            .as_array()
            .unwrap()
            .len(),
        2
    );
    cold.close().await;
    database.remove().await;
}

/// Core's continue-as-new touches no query task (continue-transfers
/// fixture): a run continues with pending and leased queries, and their
/// stored claims stay exactly as they were.
#[tokio::test]
async fn continuation_leaves_pending_and_leased_queries_with_the_predecessor() {
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
    // SQL interpolation here uses only this test's generated ULID, a fixed
    // expiry and fixed keys/statuses, never application-supplied input.
    for status in ["pending", "leased"] {
        database.execute(sqlx::AssertSqlSafe(format!(
            "INSERT INTO dw_query_cache({column},value,expiration) VALUES ('dw-native-query:{status}','{{\"run_id\":\"{run}\",\"status\":\"{status}\"}}',{expires})"
        ))).await;
    }
    let path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        task["task_id"].as_str().unwrap()
    );
    let body = completion(&task, json!([{"type":"continue_as_new"}]));
    let committed = request(&app, "POST", &path, body).await;
    assert_eq!(committed.0, StatusCode::OK, "{}", committed.1);
    let runs = request(&app, "GET", "/api/workflows/continuation-query-boundary/runs", Value::Null).await.1;
    assert_eq!(runs["runs"].as_array().unwrap().len(), 2);
    assert_eq!(runs["runs"][0]["run_id"], original["run_id"]);
    let sql = format!("SELECT {column},value FROM dw_query_cache WHERE {column} LIKE 'dw-native-query:%' ORDER BY {column}");
    let stored = text_rows(&database, &sql, &sql, &sql).await;
    assert_eq!(stored, vec![
        ("dw-native-query:leased".to_owned(), format!("{{\"run_id\":\"{run}\",\"status\":\"leased\"}}")),
        ("dw-native-query:pending".to_owned(), format!("{{\"run_id\":\"{run}\",\"status\":\"pending\"}}")),
    ]);
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
    // Two terminal commands are the bridge's 409 `invalid_commands`, as PHP's.
    for (commands, expected) in [
        (json!([{"type":"continue_as_new","arguments":envelope(Payload::Long(7))}]), StatusCode::UNPROCESSABLE_ENTITY),
        (json!([{"type":"continue_as_new"},{"type":"complete_workflow","result":envelope(Payload::Null)}]), StatusCode::CONFLICT),
    ] {
        assert_eq!(
            request(&app, "POST", &path, completion(&task, commands))
                .await
                .0,
            expected
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
async fn explicit_continue_as_new_target_refuses_without_consuming_the_lease() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "continuation-worker", json!(["echo"]), json!([])).await;
    let original = start(&app, "continuation-target-build").await;
    let task = poll(&app, "continuation-worker", "workflow").await;
    let completion_path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        task["task_id"].as_str().unwrap()
    );
    let runs_path = "/api/workflows/continuation-target-build/runs";
    let history_path = format!(
        "/api/workflows/continuation-target-build/runs/{}/history",
        original["run_id"].as_str().unwrap()
    );
    let history_before = request(&app, "GET", &history_path, Value::Null).await.1;

    for (targets, reason, protocol) in [
        (
            vec![Value::Null, json!(true), json!(false), json!(0), json!(1), json!([]), json!({}),
                json!(""), json!("Promoted"), json!("latest"), json!("promoted ")],
            "invalid_target_build",
            WORKER_PROTOCOL_VERSION,
        ),
        (vec![json!("inherited"), json!("promoted")], "unsupported_target_build", "1.20"),
    ] {
        for target in targets {
            let commands = json!([{"type":"continue_as_new","target_build":target}]);
            let (status, response) = request_protocol(
                &app,
                "POST",
                &completion_path,
                completion(&task, commands),
                protocol,
            ).await;
            assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{response}");
            assert_eq!(response["reason"], reason, "{target}");
            assert_eq!(response["retryable"], false);
            assert_eq!(response["request_admitted"], false);
        }
    }

    assert_eq!(request(&app, "GET", &history_path, Value::Null).await.1, history_before);
    let (_, runs) = request(&app, "GET", runs_path, Value::Null).await;
    assert_eq!(runs["runs"].as_array().unwrap().len(), 1);
    assert_eq!(runs["runs"][0]["run_id"], original["run_id"]);
    assert_eq!(runs["runs"][0]["is_current_run"], true);

    let (status, response) = request(
        &app,
        "POST",
        &completion_path,
        completion(&task, json!([{"type":"continue_as_new"}])),
    ).await;
    assert_eq!(status, StatusCode::OK, "{response}");
    let (_, runs) = request(&app, "GET", runs_path, Value::Null).await;
    assert_eq!(runs["runs"].as_array().unwrap().len(), 2);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn continue_as_new_records_the_promoted_build_from_durable_type_evidence() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let build_ids = "/api/task-queues/test/build-ids";
    let register_build = |app: Router, worker: &'static str, build: &'static str, kind: &'static str| async move {
        let (status, body) = request_protocol(&app, "POST", "/api/worker/register", json!({
            "worker_id":worker,"task_queue":"test","runtime":"php","build_id":build,
            "supported_workflow_types":[kind],"supported_activity_types":[],
        }), WORKER_PROTOCOL_VERSION).await;
        assert_eq!(status, StatusCode::CREATED, "{body}");
        body
    };
    register_build(app.clone(), "worker-a", "build-a", "echo").await;
    let original = start(&app, "migrating-chain").await;
    let first = poll(&app, "worker-a", "workflow").await;
    let inherited = start(&app, "inherited-chain").await;
    let inherited_task = poll(&app, "worker-a", "workflow").await;
    assert_eq!(first["workflow_id"], "migrating-chain");
    assert_eq!(inherited_task["workflow_id"], "inherited-chain");
    let original_history = run_history(&app, &original["workflow_id"], &original["run_id"]).await;
    let completion_path = format!("/api/worker/workflow-tasks/{}/complete", first["task_id"].as_str().unwrap());
    let promoted = completion(&first, json!([{"type":"continue_as_new","target_build":"promoted"}]));
    let (status, refused) = request_protocol(&app, "POST", &completion_path, promoted.clone(), WORKER_PROTOCOL_VERSION).await;
    assert_eq!(status, StatusCode::CONFLICT, "{refused}");
    assert_eq!(refused["reason"], "promoted_build_unavailable");
    assert_eq!(refused["retryable"], true);
    assert_eq!(refused["request_admitted"], false);

    let worker_b = register_build(app.clone(), "worker-b", "build-b", "echo").await;
    let (status, promotion) = request(&app, "POST", &format!("{build_ids}/promote"), json!({"build_id":"build-b"})).await;
    assert_eq!(status, StatusCode::OK, "{promotion}");
    assert_eq!(promotion["deployment"]["workflow_types"], json!(["echo"]));
    assert_eq!(super::worker_deregistration::deregister(&app, "worker-b", &worker_b).await.0, StatusCode::OK);

    // The newest promotion is selected for starts but serves a different
    // exact type. A continue-as-new may not silently choose an older cohort.
    register_build(app.clone(), "worker-c", "build-c", "only-c").await;
    let (status, newest) = request(&app, "POST", &format!("{build_ids}/promote"), json!({"build_id":"build-c"})).await;
    assert_eq!(status, StatusCode::OK, "{newest}");
    assert_eq!(newest["new_start_selected"], true);
    assert_eq!(newest["deployment"]["workflow_types"], json!(["only-c"]));
    let (status, refused) = request_protocol(&app, "POST", &completion_path, promoted.clone(), WORKER_PROTOCOL_VERSION).await;
    assert_eq!(status, StatusCode::CONFLICT, "{refused}");
    assert_eq!(refused["reason"], "promoted_build_unavailable");
    assert_eq!(run_history(&app, &original["workflow_id"], &original["run_id"]).await, original_history);
    let (status, runs) = request(&app, "GET", "/api/workflows/migrating-chain/runs", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{runs}");
    assert_eq!(runs["run_count"], 1);
    assert_eq!(runs["runs"][0]["run_id"], original["run_id"]);
    assert_eq!(runs["runs"][0]["is_current_run"], true);
    assert_eq!(request(&app, "POST", &format!("{build_ids}/drain"), json!({"build_id":"build-c"})).await.0, StatusCode::OK);

    runtime.close().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (status, committed) = request_protocol(&app, "POST", &completion_path, promoted.clone(), WORKER_PROTOCOL_VERSION).await;
    assert_eq!(status, StatusCode::OK, "{committed}");
    assert_eq!(committed["recorded"], true);
    let (status, successor) = request(&app, "GET", "/api/workflows/migrating-chain", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{successor}");
    assert_eq!(successor["compatibility"], "build-b");
    let closed = run_history(&app, &original["workflow_id"], &original["run_id"]).await;
    let started = run_history(&app, &original["workflow_id"], &successor["run_id"]).await;
    let transition = json!({"from_compatibility":"build-a","to_compatibility":"build-b","resolution":"promoted"});
    assert_eq!(closed.as_array().unwrap().last().unwrap()["payload"]["build_transition"], transition);
    assert_eq!(started[0]["event_type"], "WorkflowStarted");
    assert_eq!(started[0]["payload"]["build_transition"], transition);

    let inherited_path = format!("/api/worker/workflow-tasks/{}/complete", inherited_task["task_id"].as_str().unwrap());
    let (status, body) = request_protocol(&app, "POST", &inherited_path,
        completion(&inherited_task, json!([{"type":"continue_as_new"}])), WORKER_PROTOCOL_VERSION).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let next_a = poll(&app, "worker-a", "workflow").await;
    assert_eq!(next_a["workflow_id"], inherited["workflow_id"]);
    assert_eq!(next_a["compatibility"], "build-a");
    let inherited_history = run_history(&app, &inherited["workflow_id"], &next_a["run_id"]).await;
    assert_eq!(inherited_history[0]["payload"]["build_transition"],
        json!({"from_compatibility":"build-a","to_compatibility":"build-a","resolution":"inherited"}));
    assert!(poll(&app, "worker-a", "workflow").await.is_null());

    let (status, resumed) = request(&app, "POST", &format!("{build_ids}/resume"), json!({"build_id":"build-c"})).await;
    assert_eq!(status, StatusCode::OK, "{resumed}");
    assert_eq!(resumed["new_start_selected"], true);
    let (status, duplicate) = request_protocol(&app, "POST", &completion_path, promoted, WORKER_PROTOCOL_VERSION).await;
    assert_eq!(status, StatusCode::OK, "{duplicate}");
    assert_eq!(duplicate["reason"], "already_completed");
    assert_eq!(run_history(&app, &original["workflow_id"], &original["run_id"]).await, closed);
    assert_eq!(run_history(&app, &original["workflow_id"], &successor["run_id"]).await, started);
    register_build(app.clone(), "worker-b-again", "build-b", "echo").await;
    let claimed = poll(&app, "worker-b-again", "workflow").await;
    assert_eq!(claimed["run_id"], successor["run_id"]);
    assert_eq!(claimed["compatibility"], "build-b");
    assert_eq!(claimed["task_id"], committed["created_task_ids"][0]);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn promoted_build_refusal_is_observed_only_until_the_fenced_retry() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "old-worker", json!(["echo"]), json!([])).await;
    let original = start(&app, "retrying-continuation").await;
    let first = poll(&app, "old-worker", "workflow").await;
    let observe = |app: Router, run: Value| async move {
        let path = format!("/api/workflows/retrying-continuation/runs/{}/observation", run.as_str().unwrap());
        let (status, body) = request(&app, "GET", &path, Value::Null).await;
        assert_eq!(status, StatusCode::OK, "{body}");
        assert!(body.get("continue_as_new_refusal").is_some(), "{body}");
        body
    };
    let original_run = original["run_id"].clone();
    let completion_path = |task: &Value| format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap());
    let command = json!([{"type":"continue_as_new","target_build":"promoted"}]);
    let (status, refusal) = request_protocol(&app, "POST", &completion_path(&first),
        completion(&first, command.clone()), WORKER_PROTOCOL_VERSION).await;
    assert_eq!(status, StatusCode::CONFLICT, "{refusal}");
    assert_eq!(refusal["reason"], "promoted_build_unavailable");
    assert!(observe(app.clone(), original_run.clone()).await["continue_as_new_refusal"].is_null());
    let failure_path = format!("/api/worker/workflow-tasks/{}/fail", first["task_id"].as_str().unwrap());
    let failure = json!({"lease_owner":first["lease_owner"],"workflow_task_attempt":first["workflow_task_attempt"],
        "failure":{"reason":"promoted_build_unavailable","message":"The promoted build does not serve this type."}});

    let (status, unsupported) = request_protocol(&app, "POST", &failure_path, failure.clone(), "1.20").await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{unsupported}");
    assert_eq!(unsupported["reason"], "unsupported_target_build");
    let (status, accepted) = request_protocol(&app, "POST", &failure_path, failure.clone(), WORKER_PROTOCOL_VERSION).await;
    assert_eq!(status, StatusCode::OK, "{accepted}");
    let waiting = observe(app.clone(), original_run.clone()).await;
    let retry = &waiting["continue_as_new_refusal"];
    assert_eq!(retry["reason"], "promoted_build_unavailable");
    assert_eq!(retry["task_id"], accepted["next_task_id"]);
    assert_eq!(retry["status"], "ready");
    assert_eq!(retry["workflow_task_attempt"], first["workflow_task_attempt"]);
    assert!(retry["recorded_at"].is_string() && retry["available_at"].is_string(), "{retry}");
    assert_eq!(waiting["is_terminal"], false);

    let current = poll(&app, "old-worker", "workflow").await;
    assert_eq!(current["task_id"], accepted["next_task_id"]);
    assert_eq!(current["workflow_task_attempt"], first["workflow_task_attempt"].as_i64().unwrap() + 1);
    let (status, stale) = request_protocol(&app, "POST", &failure_path, failure, WORKER_PROTOCOL_VERSION).await;
    assert_eq!(status, StatusCode::CONFLICT, "{stale}");
    assert!(observe(app.clone(), original_run.clone()).await["continue_as_new_refusal"].is_null());

    let (status, registered) = request(&app, "POST", "/api/worker/register", json!({
        "worker_id":"new-worker","task_queue":"test","runtime":"php","build_id":"new-build",
        "supported_workflow_types":["echo"],"supported_activity_types":[],
    })).await;
    assert_eq!(status, StatusCode::CREATED, "{registered}");
    let (status, promoted) = request(&app, "POST", "/api/task-queues/test/build-ids/promote", json!({"build_id":"new-build"})).await;
    assert_eq!(status, StatusCode::OK, "{promoted}");
    let (status, committed) = request_protocol(&app, "POST", &completion_path(&current),
        completion(&current, command), WORKER_PROTOCOL_VERSION).await;
    assert_eq!(status, StatusCode::OK, "{committed}");
    let (_, runs) = request(&app, "GET", "/api/workflows/retrying-continuation/runs", Value::Null).await;
    let successor = runs["runs"][1]["run_id"].clone();
    let closed = observe(app.clone(), original_run.clone()).await;
    let started = observe(app.clone(), successor.clone()).await;
    assert_eq!(closed["is_terminal"], true);
    assert!(closed["continue_as_new_refusal"].is_null());
    assert_eq!(closed["continue_as_new_build_transitions"]["to_successor"],
        json!({"run_id":successor,"from_compatibility":null,"to_compatibility":"new-build","resolution":"promoted"}));
    assert_eq!(started["continue_as_new_build_transitions"]["from_predecessor"],
        json!({"run_id":original_run,"from_compatibility":null,"to_compatibility":"new-build","resolution":"promoted"}));
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn promoted_continuation_serializes_with_retirement_of_the_old_build() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    for (worker, build) in [("old-worker", "old-build"), ("new-worker", "new-build")] {
        let (status, registered) = request(&app, "POST", "/api/worker/register", json!({
            "worker_id":worker,"task_queue":"test","runtime":"php","build_id":build,
            "supported_workflow_types":["echo"],"supported_activity_types":[],
        })).await;
        assert_eq!(status, StatusCode::CREATED, "{registered}");
    }
    for build in ["old-build", "new-build"] {
        let (status, body) = request(&app, "POST", "/api/task-queues/test/build-ids/promote", json!({"build_id":build})).await;
        assert_eq!(status, StatusCode::OK, "{body}");
        if build == "old-build" {
            start(&app, "retiring-chain").await;
        }
    }
    let task = poll(&app, "old-worker", "workflow").await;
    assert_eq!(task["compatibility"], "old-build");
    let other = database.open().await.unwrap();
    let retirement = router(other.clone());
    let retire_path = "/api/deployments/default/test@old-build/retire";
    let complete_path = format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap());
    let (continued, retired) = tokio::join!(
        request_protocol(&app, "POST", &complete_path,
            completion(&task, json!([{"type":"continue_as_new","target_build":"promoted"}])), WORKER_PROTOCOL_VERSION),
        request(&retirement, "POST", retire_path, Value::Null),
    );
    assert_eq!(continued.0, StatusCode::OK, "{}", continued.1);
    let retired = if retired.0 == StatusCode::CONFLICT {
        assert_eq!(retired.1["reason"], "live_dependencies");
        assert_eq!(retired.1["counts"]["open_runs"], 1);
        request(&retirement, "POST", retire_path, Value::Null).await
    } else {
        retired
    };
    assert_eq!(retired.0, StatusCode::OK, "{}", retired.1);
    assert_eq!(retired.1["outcome"], "retired");
    let next = poll(&app, "new-worker", "workflow").await;
    assert_eq!(next["compatibility"], "new-build");
    assert_eq!(next["task_id"], continued.1["created_task_ids"][0]);
    other.close().await;
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
    // A sticky claim needs a registration that supports it (Server #1017).
    let (status, registered) = request(&app, "POST", "/api/worker/register", json!({"worker_id":"continuation-worker",
        "task_queue":"test","runtime":"php","build_id":"build-1","supported_workflow_types":["echo"],
        "supported_activity_types":[],"capabilities":["sticky_execution"]})).await;
    assert_eq!(status, StatusCode::CREATED, "{registered}");
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
    // PHP requires every sticky cache field once one is sent.
    body["sticky_cache"] = json!({"worker_id":"continuation-worker","workflow_id":"continuation-original",
        "run_id":task["run_id"],"build_id":"build-1","ttl_seconds":300});
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
    // Describe reports deadlines in whole seconds, as PHP's toIso8601String does.
    assert_eq!(deadline.timestamp(), (start + chrono::Duration::seconds(600)).timestamp());
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
    // PHP's ownership guard: the original run closed when it continued.
    assert_eq!(refused.1["reason"], "run_closed", "{}", refused.1);
    assert_eq!(refused.1["can_continue"], false);
    assert_eq!(refused.1["task_status"], "completed");
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
