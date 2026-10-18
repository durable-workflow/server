use super::*;

async fn observe(app: &Router, run: &Value) -> Value {
    run_observation_active_summaries::observe(app, "default", run).await
}

async fn export(database: &TestDatabase, app: &Router, run: &Value) -> Value {
    let summary = observe(app, run).await;
    let history = json!({"events":run_history(app, &run["workflow_id"], &run["run_id"]).await});
    run_observation_summaries::export_reference(database, &summary, &history).await;
    summary
}

fn input(request_id: &str) -> Value {
    json!({"input":envelope(Payload::Array(vec![Payload::Long(9007199254740993)])),
        "request_id":request_id,"wait_for":"accepted"})
}

fn complete(update: &Value, failed: bool) -> Value {
    if failed {
        json!([{"type":"fail_update","update_id":update["update_id"],"message":"retained failure λ",
            "exception_type":"ValueError","exception_class":"builtins.ValueError","non_retryable":true}])
    } else {
        json!([{"type":"complete_update","update_id":update["update_id"],"result":envelope(Payload::Long(9007199254740995))}])
    }
}

fn update_wait(summary: &Value) -> &Value {
    summary["current_waits"]["waits"]
        .as_array()
        .unwrap()
        .iter()
        .find(|wait| wait["kind"] == "update")
        .expect("accepted update has its retained wait")
}

#[tokio::test]
async fn run_observation_updates_preserve_ready_leased_and_resolved_identity_across_reopen() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "update-observer").await;
    let run = start(&app, "observed-updates").await;
    open_update_wait(&app, "update-observer").await;
    let path = "/api/workflows/observed-updates/update/set";
    assert_eq!(
        export(&database, &app, &run).await["liveness_state"],
        "waiting_for_signal"
    );
    runtime.close().await;
    for failed in [false, true] {
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        let original = input(&format!("update-{failed}"));
        let accepted = request(&app, "POST", path, original.clone()).await;
        assert_eq!(accepted.0, StatusCode::ACCEPTED, "{}", accepted.1);
        let ready = export(&database, &app, &run).await;
        assert_eq!(ready["summary_state"], "observed", "{ready}");
        assert_eq!(ready["liveness_state"], "workflow_task_ready");
        let wait = update_wait(&ready);
        assert_eq!(
            wait["id"],
            format!("update:{}", accepted.1["update_id"].as_str().unwrap())
        );
        assert_eq!(wait["dependency_id"], accepted.1["update_id"]);
        assert_eq!(wait["dependency_type"], "update");
        assert_eq!(wait["target_name"], "set");
        assert_eq!(wait["reason"], "Waiting for update set.");
        assert_eq!(wait["task_status"], "ready");
        assert_eq!(wait["task_metadata_state"], "available");
        assert_eq!(wait["state"], "eligible");
        assert!(wait["deadline_at"].is_null());
        assert!(wait["attempt_id"].is_null());
        let task_id = wait["task_id"].clone();
        assert_eq!(
            ready["liveness_reason"],
            format!("Update task {} is ready to run.", task_id.as_str().unwrap())
        );
        let before = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
        assert_eq!(
            request(&app, "POST", path, original.clone()).await.1["update_id"],
            accepted.1["update_id"]
        );
        assert_eq!(
            run_history(&app, &run["workflow_id"], &run["run_id"]).await,
            before
        );
        runtime.close().await;
        let cold = database.open().await.unwrap();
        let app = router(cold.clone());
        assert_eq!(
            observe(&app, &run).await["current_waits"]["waits"],
            ready["current_waits"]["waits"]
        );
        let task = poll(&app, "update-observer", "workflow").await;
        assert_eq!(task["task_id"], task_id);
        assert_eq!(task["workflow_update_id"], accepted.1["update_id"]);
        let leased = export(&database, &app, &run).await;
        assert_eq!(leased["liveness_state"], "workflow_task_leased");
        assert_eq!(
            leased["liveness_reason"],
            format!(
                "Update task {} is leased to a worker.",
                task_id.as_str().unwrap()
            )
        );
        assert_eq!(update_wait(&leased)["task_status"], "leased");
        assert_eq!(update_wait(&leased)["id"], wait["id"]);
        assert_eq!(
            run_history(&app, &run["workflow_id"], &run["run_id"]).await,
            before
        );
        let commands = complete(&accepted.1, failed);
        let completed = finish_task(&app, &task, commands.clone()).await;
        assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
        assert_eq!(
            finish_task(&app, &task, commands).await.1["recorded"],
            false
        );
        let resolved = export(&database, &app, &run).await;
        assert_eq!(resolved["summary_state"], "observed", "{resolved}");
        assert_eq!(resolved["liveness_state"], "waiting_for_signal");
        assert_eq!(resolved["current_waits"]["returned_count"], 1);
        assert_eq!(resolved["exception_count"], u64::from(failed));
        let receipt = request(&app, "POST", path, original).await;
        assert_eq!(receipt.1["update_id"], accepted.1["update_id"]);
        assert_eq!(
            receipt.1["update_status"],
            if failed { "failed" } else { "completed" }
        );
        cold.close().await;
    }
    assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_run_waits WHERE kind='update' AND status='resolved' AND task_id IS NULL AND task_type IS NULL AND task_status IS NULL AND task_backed=FALSE").await, 2);
    database.remove().await;
}

#[tokio::test]
async fn run_observation_updates_missing_wait_is_not_rebuilt_by_reads_claim_or_resolution() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "missing-update-observer").await;
    let run = start(&app, "missing-update-wait").await;
    open_update_wait(&app, "missing-update-observer").await;
    let accepted = request(
        &app,
        "POST",
        "/api/workflows/missing-update-wait/update/set",
        input("missing"),
    )
    .await;
    assert_eq!(accepted.0, StatusCode::ACCEPTED);
    assert_eq!(observe(&app, &run).await["summary_state"], "observed");
    database
        .execute("DELETE FROM workflow_run_waits WHERE kind='update'")
        .await;
    let before = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    for _ in 0..2 {
        observe(&app, &run).await;
    }
    assert_eq!(
        task_retry::rows(
            &database,
            "SELECT COUNT(*) FROM workflow_run_waits WHERE kind='update'"
        )
        .await,
        0
    );
    let task = poll(&app, "missing-update-observer", "workflow").await;
    assert_eq!(observe(&app, &run).await["summary_state"], "unavailable");
    assert_eq!(
        run_history(&app, &run["workflow_id"], &run["run_id"]).await,
        before
    );
    assert_eq!(
        finish_task(&app, &task, complete(&accepted.1, false))
            .await
            .0,
        StatusCode::OK
    );
    assert_eq!(observe(&app, &run).await["summary_state"], "unavailable");
    assert_eq!(
        task_retry::rows(
            &database,
            "SELECT COUNT(*) FROM workflow_run_waits WHERE kind='update'"
        )
        .await,
        0
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_updates_projection_failures_rollback_admission_and_original_completion() {
    let database = TestDatabase::Sqlite(tempfile::tempdir().unwrap());
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "atomic-update-observer").await;
    let run = start(&app, "atomic-update-wait").await;
    open_update_wait(&app, "atomic-update-observer").await;
    let before = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    database.execute("CREATE TRIGGER reject_update_wait BEFORE INSERT ON workflow_run_waits WHEN NEW.kind='update' BEGIN SELECT RAISE(ABORT, 'update wait rejected'); END").await;
    let path = "/api/workflows/atomic-update-wait/update/set";
    assert_eq!(
        request(&app, "POST", path, input("atomic")).await.0,
        StatusCode::SERVICE_UNAVAILABLE
    );
    assert_eq!(
        task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_updates").await,
        0
    );
    assert_eq!(
        run_history(&app, &run["workflow_id"], &run["run_id"]).await,
        before
    );
    database.execute("DROP TRIGGER reject_update_wait").await;
    let accepted = request(&app, "POST", path, input("atomic")).await;
    assert_eq!(accepted.0, StatusCode::ACCEPTED);
    let task = poll(&app, "atomic-update-observer", "workflow").await;
    let before = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let observed = observe(&app, &run).await;
    database.execute("CREATE TRIGGER reject_resolved_summary BEFORE INSERT ON workflow_run_summaries WHEN NEW.liveness_state='waiting_for_signal' BEGIN SELECT RAISE(ABORT, 'update summary rejected'); END").await;
    assert_eq!(
        finish_task(&app, &task, complete(&accepted.1, false))
            .await
            .0,
        StatusCode::SERVICE_UNAVAILABLE
    );
    assert_eq!(
        run_history(&app, &run["workflow_id"], &run["run_id"]).await,
        before
    );
    assert_eq!(
        observe(&app, &run).await["current_waits"]["waits"],
        observed["current_waits"]["waits"]
    );
    assert_eq!(
        task_retry::rows(
            &database,
            "SELECT COUNT(*) FROM workflow_updates WHERE status='accepted'"
        )
        .await,
        1
    );
    database
        .execute("DROP TRIGGER reject_resolved_summary")
        .await;
    assert_eq!(
        finish_task(&app, &task, complete(&accepted.1, false))
            .await
            .0,
        StatusCode::OK
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_updates_validate_retained_command_and_task_identity() {
    for mutation in [
        "UPDATE workflow_run_waits SET command_id='unrelated-command' WHERE kind='update'",
        "UPDATE workflow_run_waits SET task_id=(SELECT MIN(id) FROM workflow_tasks WHERE status='completed') WHERE kind='update'",
    ] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        update_worker(&app, "update-identity").await;
        let run = start(&app, "update-identity").await;
        open_update_wait(&app, "update-identity").await;
        let accepted = request(
            &app,
            "POST",
            "/api/workflows/update-identity/update/set",
            input("identity"),
        )
        .await;
        assert_eq!(accepted.0, StatusCode::ACCEPTED);
        assert_eq!(observe(&app, &run).await["summary_state"], "observed");
        database.execute(mutation).await;
        let task = poll(&app, "update-identity", "workflow").await;
        assert_eq!(task["workflow_update_id"], accepted.1["update_id"]);
        let observed = observe(&app, &run).await;
        assert_eq!(
            observed["summary_state"], "unavailable",
            "{mutation}: {observed}"
        );
        assert_eq!(
            finish_task(&app, &task, complete(&accepted.1, false))
                .await
                .0,
            StatusCode::OK
        );
        assert_eq!(
            task_retry::rows(
                &database,
                "SELECT COUNT(*) FROM workflow_updates WHERE status='completed'"
            )
            .await,
            1
        );
        runtime.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn run_observation_updates_take_precedence_over_condition_and_restore_original_wait() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "condition-update").await;
    let run = start(&app, "condition-update").await;
    let task = poll(&app, "condition-update", "workflow").await;
    let opened = finish_task(
        &app,
        &task,
        json!([{"type":"open_condition_wait","condition_key":"ready 日本語",
        "condition_definition_fingerprint":format!("sha256:{}", "a".repeat(64)),
        "condition_wait_occurrence_id":"original-condition"}]),
    )
    .await;
    assert_eq!(opened.0, StatusCode::OK, "{}", opened.1);
    let original = export(&database, &app, &run).await;
    assert_eq!(original["liveness_state"], "waiting_for_condition");
    let accepted = request(
        &app,
        "POST",
        "/api/workflows/condition-update/update/set",
        input("condition"),
    )
    .await;
    assert_eq!(accepted.0, StatusCode::ACCEPTED);
    let ready = export(&database, &app, &run).await;
    assert_eq!(ready["summary_state"], "observed", "{ready}");
    assert_eq!(ready["liveness_state"], "workflow_task_ready");
    assert_eq!(ready["current_waits"]["returned_count"], 2);
    let task = poll(&app, "condition-update", "workflow").await;
    let leased = export(&database, &app, &run).await;
    assert_eq!(leased["liveness_state"], "workflow_task_leased");
    assert_eq!(update_wait(&leased)["task_id"], task["task_id"]);
    assert_eq!(
        finish_task(&app, &task, complete(&accepted.1, false))
            .await
            .0,
        StatusCode::OK
    );
    let restored = export(&database, &app, &run).await;
    assert_eq!(restored["liveness_state"], "waiting_for_condition");
    assert_eq!(
        restored["current_waits"]["waits"],
        original["current_waits"]["waits"]
    );
    runtime.close().await;
    database.remove().await;
}
