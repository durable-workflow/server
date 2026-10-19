use super::*;

const SUFFIXES: [&str; 6] = [
    "CONTINUE_AS_NEW_EVENT_THRESHOLD",
    "CONTINUE_AS_NEW_SIZE_BYTES_THRESHOLD",
    "CONTINUE_AS_NEW_FAN_OUT_THRESHOLD",
    "HISTORY_EVENT_WARNING_THRESHOLD",
    "HISTORY_SIZE_BYTES_WARNING_THRESHOLD",
    "HISTORY_FAN_OUT_WARNING_THRESHOLD",
];

#[test]
fn history_budget_profiles_preserve_claim_and_cold_summary_contracts() {
    // Separate processes preserve immutable startup configuration without
    // racing the other integration tests over process-wide environment.
    for profile in ["events", "clamp", "disabled", "bytes", "legacy", "primary"] {
        let mut command = std::process::Command::new(std::env::current_exe().unwrap());
        command.args([
            "--ignored",
            "--exact",
            "history_budget::history_budget_profile_child",
            "--nocapture",
        ]);
        for suffix in SUFFIXES {
            command
                .env_remove(format!("DW_V2_{suffix}"))
                .env_remove(format!("WORKFLOW_V2_{suffix}"));
        }
        command
            .env("DW_TEST_HISTORY_BUDGET_PROFILE", profile)
            .env("DW_V2_CONTINUE_AS_NEW_EVENT_THRESHOLD", "0")
            .env("DW_V2_CONTINUE_AS_NEW_SIZE_BYTES_THRESHOLD", "0")
            .env("DW_V2_CONTINUE_AS_NEW_FAN_OUT_THRESHOLD", "0");
        match profile {
            "events" | "clamp" => {
                command
                    .env("DW_V2_CONTINUE_AS_NEW_EVENT_THRESHOLD", "3")
                    .env(
                        "DW_V2_HISTORY_EVENT_WARNING_THRESHOLD",
                        if profile == "events" { "2" } else { "30" },
                    );
            }
            "bytes" => {
                command.env("DW_V2_CONTINUE_AS_NEW_SIZE_BYTES_THRESHOLD", "1");
            }
            "legacy" => {
                command
                    .env("DW_V2_CONTINUE_AS_NEW_EVENT_THRESHOLD", "(null)")
                    .env("WORKFLOW_V2_CONTINUE_AS_NEW_EVENT_THRESHOLD", "3")
                    .env("WORKFLOW_V2_HISTORY_EVENT_WARNING_THRESHOLD", "2");
            }
            "primary" => {
                command.env("WORKFLOW_V2_CONTINUE_AS_NEW_EVENT_THRESHOLD", "1");
            }
            _ => {}
        }
        let output = command.output().unwrap();
        assert!(
            output.status.success(),
            "{profile}\n{}\n{}",
            String::from_utf8_lossy(&output.stdout),
            String::from_utf8_lossy(&output.stderr)
        );
        assert!(
            String::from_utf8_lossy(&output.stdout).contains("1 passed"),
            "child must execute its actual control"
        );
    }
}

async fn stored(database: &TestDatabase, run: &Value) -> Value {
    let query = "SELECT CAST(history_event_count AS BIGINT),CAST(history_size_bytes AS BIGINT),CAST(history_fan_out AS BIGINT),CAST(CASE WHEN continue_as_new_recommended THEN 1 ELSE 0 END AS BIGINT),history_budget_pressure FROM workflow_run_summaries WHERE id=$1";
    macro_rules! read {
        ($pool:expr, $query:expr) => {{
            let pool = $pool;
            let row: (i64,i64,i64,i64,String) = sqlx::query_as($query).bind(run["run_id"].as_str().unwrap()).fetch_one(&pool).await.unwrap();
            pool.close().await;
            json!({"run_id":run["run_id"],"history_event_count":row.0,"history_size_bytes":row.1,"history_fan_out":row.2,"continue_as_new_recommended":row.3 != 0,"history_budget_pressure":row.4})
        }};
    }
    match database {
        TestDatabase::Sqlite(dir) => read!(
            sqlx::SqlitePool::connect(&format!(
                "sqlite://{}",
                dir.path().join("runtime.sqlite").display()
            ))
            .await
            .unwrap(),
            query
        ),
        TestDatabase::Postgres { options, .. } => read!(
            sqlx::postgres::PgPoolOptions::new()
                .connect_with(options.as_ref().clone())
                .await
                .unwrap(),
            query
        ),
        TestDatabase::MySql { options, .. } => read!(
            sqlx::mysql::MySqlPoolOptions::new()
                .connect_with(options.as_ref().clone())
                .await
                .unwrap(),
            "SELECT CAST(history_event_count AS SIGNED),CAST(history_size_bytes AS SIGNED),CAST(history_fan_out AS SIGNED),CAST(CASE WHEN continue_as_new_recommended THEN 1 ELSE 0 END AS SIGNED),history_budget_pressure FROM workflow_run_summaries WHERE id=?"
        ),
    }
}

async fn export(database: &TestDatabase, summary: &Value, history: &Value) {
    let mut summary = summary.clone();
    let mut env = serde_json::Map::new();
    for suffix in SUFFIXES {
        for prefix in ["DW_V2_", "WORKFLOW_V2_"] {
            let name = format!("{prefix}{suffix}");
            if let Ok(value) = std::env::var(&name) {
                env.insert(name, json!(value));
            }
        }
    }
    summary["budget_env"] = json!(env);
    run_observation_summaries::export_reference(database, &summary, history).await;
}

#[tokio::test]
#[ignore = "runs in an isolated configuration process from the profile control"]
async fn history_budget_profile_child() {
    let profile = std::env::var("DW_TEST_HISTORY_BUDGET_PROFILE").expect("configuration profile");
    let initial_pressure = match profile.as_str() {
        "events" | "legacy" => "approaching",
        "bytes" => "continue_as_new_recommended",
        _ => "ok",
    };
    let final_pressure = match profile.as_str() {
        "disabled" | "primary" => "ok",
        _ => "continue_as_new_recommended",
    };
    let initial_dimensions = match profile.as_str() {
        "events" | "legacy" => json!(["event_count"]),
        "bytes" => json!(["size_bytes"]),
        _ => json!([]),
    };
    let database = TestDatabase::new().await;
    let first = database.open().await.unwrap();
    let app = router(first.clone());
    namespaces::create(&app, "budget-named").await;
    let mut originals = Vec::new();
    for namespace in ["default", "budget-named"] {
        let registration = namespaces::scoped(&app,namespace,"POST","/api/worker/register",json!({"worker_id":"budget-worker","task_queue":"test","runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":[]})).await;
        assert_eq!(registration.0, StatusCode::CREATED, "{}", registration.1);
        let run = namespaces::start_scoped(&app, namespace, &format!("budget-{namespace}")).await;
        let observed = run_observation_active_summaries::observe(&app, namespace, &run).await;
        let before = stored(&database, &run).await;
        assert_eq!(before["history_event_count"], 2);
        assert_eq!(before["history_budget_pressure"], initial_pressure);
        assert_eq!(
            before["continue_as_new_recommended"],
            initial_pressure == "continue_as_new_recommended"
        );
        export(&database, &before, &observed["history"]).await;
        // The claim must calculate its complete budget despite a missing
        // summary, while the preceding observation performs no read repair.
        if namespace == "budget-named" {
            if matches!(profile.as_str(), "events" | "disabled" | "bytes") {
                database
                    .execute("DELETE FROM workflow_run_summaries WHERE namespace='budget-named'")
                    .await;
                assert_eq!(
                    run_observation_active_summaries::observe(&app, namespace, &run).await["summary_state"],
                    "unavailable"
                );
            } else {
                database.execute("UPDATE workflow_run_summaries SET history_event_count=99,history_size_bytes=0 WHERE namespace='budget-named'").await;
                assert_eq!(
                    run_observation_active_summaries::observe(&app, namespace, &run).await["history_event_count"],
                    99
                );
            }
        }
        let body = json!({"worker_id":"budget-worker","task_queue":"test","timeout_seconds":0,"history_page_size":1,"poll_request_id":"budget-original"});
        let claim = namespaces::scoped(
            &app,
            namespace,
            "POST",
            "/api/worker/workflow-tasks/poll",
            body.clone(),
        )
        .await;
        assert_eq!(claim.0, StatusCode::OK, "{}", claim.1);
        let task = &claim.1["task"];
        assert_eq!(task["run_id"], run["run_id"]);
        assert_eq!(task["history_events"].as_array().unwrap().len(), 1);
        assert_eq!(task["total_history_events"], 2);
        assert_eq!(task["last_history_sequence"], 2);
        assert_eq!(task["history_size_bytes"], before["history_size_bytes"]);
        assert_eq!(task["history_fan_out"], 0);
        assert_eq!(task["history_budget_pressure"], initial_pressure);
        assert_eq!(
            task["history_budget_pressure_dimensions"],
            initial_dimensions
        );
        assert_eq!(
            namespaces::scoped(
                &app,
                namespace,
                "POST",
                "/api/worker/workflow-tasks/poll",
                body
            )
            .await,
            claim
        );
        let next_page = namespaces::scoped(&app,namespace,"POST",&format!("/api/worker/workflow-tasks/{}/history",task["task_id"].as_str().unwrap()),json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],"next_history_page_token":task["next_history_page_token"]})).await;
        assert_eq!(next_page.0, StatusCode::OK, "{}", next_page.1);
        assert_eq!(
            next_page.1["history_events"][0]["event_type"],
            "WorkflowStarted"
        );
        for field in [
            "last_history_sequence",
            "total_history_events",
            "history_size_bytes",
            "history_fan_out",
            "continue_as_new_recommended",
            "history_budget_pressure",
            "history_budget_pressure_dimensions",
        ] {
            assert_eq!(next_page.1[field], task[field], "{field}");
        }
        let mut task_budget = task.clone();
        task_budget["history_event_count"] = task["total_history_events"].clone();
        export(&database, &task_budget, &observed["history"]).await;
        let mut commands: Vec<Value> = if namespace == "default" {
            (0..20).map(|n| json!({"type":"record_version_marker","change_id":format!("budget-{n}"),"version":1,"min_supported":-1,"max_supported":1})).collect()
        } else {
            Vec::new()
        };
        commands.push(json!({"type":"start_timer","delay_seconds":0}));
        let opened = namespaces::scoped(
            &app,
            namespace,
            "POST",
            &format!(
                "/api/worker/workflow-tasks/{}/complete",
                task["task_id"].as_str().unwrap()
            ),
            completion(task, json!(commands)),
        )
        .await;
        assert_eq!(opened.0, StatusCode::OK, "{}", opened.1);
        originals.push((namespace, run));
    }
    drop(app);
    first.close().await;
    let second = database.open().await.unwrap();
    let app = router(second.clone());
    for (namespace, run) in &originals {
        let claim = namespaces::scoped(&app,namespace,"POST","/api/worker/workflow-tasks/poll",json!({"worker_id":"budget-worker","task_queue":"test","timeout_seconds":2,"history_page_size":1})).await;
        assert_eq!(claim.0, StatusCode::OK, "{}", claim.1);
        let task = &claim.1["task"];
        assert_eq!(task["run_id"], run["run_id"]);
        assert_eq!(
            task["total_history_events"],
            if *namespace == "default" { 24 } else { 4 }
        );
        assert_eq!(task["history_events"].as_array().unwrap().len(), 1);
        assert_eq!(task["history_budget_pressure"], final_pressure);
        let observation = run_observation_active_summaries::observe(&app, namespace, run).await;
        let mut task_budget = task.clone();
        task_budget["history_event_count"] = task["total_history_events"].clone();
        export(&database, &task_budget, &observation["history"]).await;
        let path = format!(
            "/api/worker/workflow-tasks/{}/complete",
            task["task_id"].as_str().unwrap()
        );
        let body = completion(
            task,
            json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}]),
        );
        let completed = namespaces::scoped(&app, namespace, "POST", &path, body.clone()).await;
        assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
        let mut duplicate = completed.clone();
        duplicate.1["recorded"] = json!(false);
        duplicate.1["reason"] = json!("already_completed");
        assert_eq!(
            namespaces::scoped(&app, namespace, "POST", &path, body).await,
            duplicate
        );
        let observation = run_observation_active_summaries::observe(&app, namespace, run).await;
        let budget = stored(&database, run).await;
        assert_eq!(
            budget["history_event_count"],
            if *namespace == "default" { 25 } else { 5 }
        );
        assert_eq!(budget["history_budget_pressure"], final_pressure);
        assert_eq!(
            budget["continue_as_new_recommended"],
            final_pressure == "continue_as_new_recommended"
        );
        export(&database, &budget, &observation["history"]).await;
    }
    drop(app);
    second.close().await;
    let third = database.open().await.unwrap();
    let app = router(third.clone());
    for (namespace, run) in &originals {
        let observation = run_observation_active_summaries::observe(&app, namespace, run).await;
        assert_eq!(
            observation["history_event_count"],
            if *namespace == "default" { 25 } else { 5 }
        );
        assert_eq!(
            stored(&database, run).await["history_budget_pressure"],
            final_pressure
        );
    }
    third.close().await;
    database.remove().await;
}

#[tokio::test]
async fn history_budget_missing_history_rolls_back_original_claim_and_receipt() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "budget-gap-worker", json!(["echo"]), json!([])).await;
    let run = start(&app, "budget-gap").await;
    database.execute("CREATE TABLE budget_saved_event AS SELECT * FROM workflow_history_events WHERE sequence=1").await;
    database
        .execute("DELETE FROM workflow_history_events WHERE sequence=1")
        .await;
    database.execute("DELETE FROM workflow_run_summaries").await;
    let body = json!({"worker_id":"budget-gap-worker","task_queue":"test","timeout_seconds":0,"poll_request_id":"gap-original"});
    let failed = request(
        &app,
        "POST",
        "/api/worker/workflow-tasks/poll",
        body.clone(),
    )
    .await;
    assert_eq!(failed.0, StatusCode::CONFLICT, "{}", failed.1);
    assert_eq!(failed.1["reason"], "history_budget_unavailable");
    assert_eq!(
        task_retry::rows(
            &database,
            "SELECT COUNT(*) FROM workflow_tasks WHERE status='ready' AND attempt_count=0"
        )
        .await,
        1
    );
    assert_eq!(
        task_retry::rows(&database, "SELECT COUNT(*) FROM dw_poll_receipts").await,
        0
    );
    assert_eq!(
        task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_run_summaries").await,
        0
    );
    database
        .execute("INSERT INTO workflow_history_events SELECT * FROM budget_saved_event")
        .await;
    let accepted = request(&app, "POST", "/api/worker/workflow-tasks/poll", body).await;
    assert_eq!(accepted.0, StatusCode::OK, "{}", accepted.1);
    assert_eq!(accepted.1["task"]["run_id"], run["run_id"]);
    assert_eq!(accepted.1["task"]["workflow_task_attempt"], 1);
    assert_eq!(accepted.1["task"]["total_history_events"], 2);
    runtime.close().await;
    database.remove().await;
}
