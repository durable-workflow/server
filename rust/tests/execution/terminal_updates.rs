use super::*;

pub(super) fn input(request: &str) -> Value {
    json!({"input":envelope(Payload::Array(vec![Payload::Long(9007199254740993)])),
        "request_id":request,"wait_for":"accepted"})
}

async fn accepted(app: &Router, name: &str) -> (Value, Value) {
    update_worker(app, "terminal-update-worker").await;
    let run = start(app, name).await;
    open_update_wait(app, "terminal-update-worker").await;
    let update = request(
        app,
        "POST",
        &format!("/api/workflows/{name}/update/set"),
        input("original"),
    )
    .await;
    assert_eq!(update.0, StatusCode::ACCEPTED, "{}", update.1);
    (run, update.1)
}

pub(super) async fn reference_before(
    database: &TestDatabase,
    app: &Router,
    run: &Value,
    update: &Value,
) -> Option<Value> {
    if std::env::var_os("DW_TEST_TERMINAL_UPDATES_REFERENCE").is_none() {
        return None;
    }
    macro_rules! command {
        ($pool:expr,$sql:expr) => {{
            let pool = $pool;
            let row: (String, Option<String>) = sqlx::query_as($sql)
                .bind(update["command_id"].as_str().unwrap())
                .fetch_one(&pool)
                .await
                .unwrap();
            pool.close().await;
            row
        }};
    }
    let (payload, context) = match database {
        TestDatabase::Sqlite(dir) => command!(
            sqlx::SqlitePool::connect(&format!(
                "sqlite://{}",
                dir.path().join("runtime.sqlite").display()
            ))
            .await
            .unwrap(),
            "SELECT payload,CAST(context AS TEXT) FROM workflow_commands WHERE id=$1"
        ),
        TestDatabase::Postgres { options, .. } => command!(
            sqlx::postgres::PgPoolOptions::new()
                .connect_with(options.as_ref().clone())
                .await
                .unwrap(),
            "SELECT payload,CAST(context AS TEXT) FROM workflow_commands WHERE id=$1"
        ),
        TestDatabase::MySql { options, .. } => command!(
            sqlx::mysql::MySqlPoolOptions::new()
                .connect_with(options.as_ref().clone())
                .await
                .unwrap(),
            "SELECT payload,CAST(context AS CHAR) FROM workflow_commands WHERE id=?"
        ),
    };
    let history = run_history(app, &run["workflow_id"], &run["run_id"]).await;
    let event = history
        .as_array()
        .unwrap()
        .iter()
        .find(|event| {
            event["event_type"] == "UpdateAccepted"
                && event["payload"]["update_id"] == update["update_id"]
        })
        .unwrap();
    let connection = match database {
        TestDatabase::Sqlite(_) => "sqlite",
        TestDatabase::Postgres { .. } => "pgsql",
        TestDatabase::MySql { .. } => "mysql",
    };
    Some(
        json!({"original":update,"storage_connection":connection,"command_payload":payload,"command_context":context.map(|s|serde_json::from_str::<Value>(&s).unwrap()),
        "message_sequence":event["payload"]["command"]["message_sequence"],"arguments":input("original")["input"]["blob"]}),
    )
}

pub(super) async fn reference_after(
    database: &TestDatabase,
    app: &Router,
    run: &Value,
    before: Option<Value>,
) {
    if let Some(before) = before {
        let mut summary = run_observation_active_summaries::observe(app, "default", run).await;
        assert_eq!(summary["summary_state"], "observed", "{summary}");
        summary["terminal_update_reference"] = before;
        let history = summary["history"].clone();
        run_observation_summaries::export_reference_for(
            database,
            &summary,
            &history,
            "DW_TEST_TERMINAL_UPDATES_REFERENCE",
        )
        .await;
    }
}

pub(super) async fn closed_receipt(
    app: &Router,
    run: &Value,
    original: &Value,
    reason: &str,
) -> Value {
    let path = format!(
        "/api/workflows/{}/update/set",
        run["workflow_id"].as_str().unwrap()
    );
    let result = request(app, "POST", &path, input("original")).await;
    assert_eq!(result.0, StatusCode::UNPROCESSABLE_ENTITY, "{}", result.1);
    assert_eq!(result.1["update_id"], original["update_id"]);
    assert_eq!(result.1["command_id"], original["command_id"]);
    assert_eq!(result.1["run_id"], original["run_id"]);
    assert_eq!(result.1["update_status"], "failed");
    assert_eq!(result.1["outcome"], "update_failed");
    assert_eq!(
        result.1["failure_message"],
        format!("Workflow run closed as {reason} before accepted update set could be applied.")
    );
    assert!(
        result.1["failure_id"]
            .as_str()
            .is_some_and(|id| !id.is_empty())
    );
    assert!(result.1["workflow_sequence"].is_null());
    assert!(result.1["applied_at"].is_null());
    assert!(result.1["closed_at"].is_string());
    assert!(result.1["result_envelope"].is_null());
    let history = run_history(app, &run["workflow_id"], &run["run_id"]).await;
    let completions: Vec<_> = history
        .as_array()
        .unwrap()
        .iter()
        .filter(|e| e["event_type"] == "UpdateCompleted")
        .collect();
    assert_eq!(completions.len(), 1);
    let payload = &completions[0]["payload"];
    assert_eq!(payload["update_id"], original["update_id"]);
    assert_eq!(payload["workflow_command_id"], original["command_id"]);
    assert_eq!(payload["failure_id"], result.1["failure_id"]);
    assert_eq!(payload["terminal_reason"], reason);
    assert_eq!(payload["failure_category"], "application");
    assert_eq!(payload["non_retryable"], true);
    assert_eq!(payload["exception_class"], "RuntimeException");
    assert_eq!(payload["message"], result.1["failure_message"]);
    assert_eq!(payload["code"], 0);
    assert_eq!(
        payload["exception"],
        json!({"class":"RuntimeException","message":result.1["failure_message"],
        "code":0,"file":"","line":0,"trace":[],"properties":[]})
    );
    assert!(payload.get("sequence").is_none());
    assert!(
        !history
            .as_array()
            .unwrap()
            .iter()
            .any(|e| e["event_type"] == "UpdateApplied")
    );
    let cursor: Vec<_> = history
        .as_array()
        .unwrap()
        .iter()
        .filter(|e| e["event_type"] == "MessageCursorAdvanced")
        .collect();
    assert_eq!(cursor.len(), 1);
    let mut cursor_payload = cursor[0]["payload"].clone();
    cursor_payload.as_object_mut().unwrap().remove("task");
    assert_eq!(
        cursor_payload,
        json!({"stream_key":format!("instance:{}",run["workflow_id"].as_str().unwrap()),"previous_position":0,"new_position":1})
    );
    result.1
}

#[tokio::test]
async fn terminal_updates_direct_cancel_closes_original_ready_update_after_reopen() {
    direct_cancel(false).await;
}

#[tokio::test]
async fn terminal_updates_direct_cancel_fences_original_leased_update_after_reopen() {
    direct_cancel(true).await;
}

async fn direct_cancel(leased: bool) {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (run, update) = accepted(&app, "terminal-update-cancel").await;
    let before = reference_before(&database, &app, &run, &update).await;
    let task = if leased {
        poll(&app, "terminal-update-worker", "workflow").await
    } else {
        Value::Null
    };
    if leased {
        assert_eq!(task["workflow_update_id"], update["update_id"]);
    }
    let cancel = request(
        &app,
        "POST",
        "/api/workflows/terminal-update-cancel/cancel",
        json!({"reason":"ordinary terminal update control"}),
    )
    .await;
    assert_eq!(cancel.0, StatusCode::OK, "{}", cancel.1);
    let receipt = closed_receipt(&app, &run, &update, "cancelled").await;
    reference_after(&database, &app, &run, before).await;
    assert_eq!(task_retry::rows(&database,"SELECT COUNT(*) FROM workflow_run_waits WHERE kind='update' AND status='resolved' AND sequence IS NULL AND source_status='failed' AND command_outcome='update_failed' AND task_backed=FALSE AND task_id IS NULL AND task_status IS NULL").await,1);
    assert_eq!(
        database
            .update_failure_count(
                update["command_id"].as_str().unwrap(),
                receipt["failure_id"].as_str().unwrap()
            )
            .await,
        1
    );
    assert_eq!(task_retry::rows(&database,"SELECT COUNT(*) FROM workflow_commands WHERE command_type='update' AND status='accepted' AND outcome='update_failed' AND applied_at IS NOT NULL").await,1);
    runtime.close().await;
    let cold = database.open().await.unwrap();
    let app = router(cold.clone());
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    assert_eq!(
        closed_receipt(&app, &run, &update, "cancelled").await,
        receipt
    );
    if leased {
        let stale=finish_task(&app,&task,json!([{"type":"complete_update","update_id":update["update_id"],"result":envelope(Payload::Long(42))}])).await;
        assert_eq!(stale.0, StatusCode::CONFLICT, "{}", stale.1);
    }
    assert_eq!(
        run_history(&app, &run["workflow_id"], &run["run_id"]).await,
        history
    );
    assert!(
        poll(&app, "terminal-update-worker", "workflow")
            .await
            .is_null()
    );
    cold.close().await;
    database.remove().await;
}

#[tokio::test]
async fn terminal_updates_workflow_failure_closes_original_update_with_terminal_task_snapshot() {
    worker_terminal(true).await;
}

#[tokio::test]
async fn terminal_updates_workflow_completion_closes_original_update_with_terminal_task_snapshot() {
    worker_terminal(false).await;
}

async fn worker_terminal(failed: bool) {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "update-owner").await;
    update_worker(&app, "terminal-owner").await;
    let run = start(&app, "timed-terminal-update").await;
    let initial = poll(&app, "update-owner", "workflow").await;
    let opened = finish_task(
        &app,
        &initial,
        json!([{"type":"open_condition_wait","condition_key":"finish",
        "condition_definition_fingerprint":format!("sha256:{}","a".repeat(64)),
        "condition_wait_occurrence_id":"terminal-condition","timeout_seconds":10}]),
    )
    .await;
    assert_eq!(opened.0, StatusCode::OK, "{}", opened.1);
    let update = request(
        &app,
        "POST",
        "/api/workflows/timed-terminal-update/update/set",
        input("original"),
    )
    .await;
    assert_eq!(update.0, StatusCode::ACCEPTED, "{}", update.1);
    let before = reference_before(&database, &app, &run, &update.1).await;
    let owned = poll(&app, "update-owner", "workflow").await;
    assert_eq!(owned["workflow_update_id"], update.1["update_id"]);
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let scheduled = history
        .as_array()
        .unwrap()
        .iter()
        .find(|event| event["event_type"] == "TimerScheduled")
        .unwrap();
    let fire_at =
        chrono::DateTime::parse_from_rfc3339(scheduled["payload"]["fire_at"].as_str().unwrap())
            .unwrap();
    let remaining = (fire_at.with_timezone(&chrono::Utc) - chrono::Utc::now())
        .to_std()
        .unwrap_or_default();
    assert!(remaining <= std::time::Duration::from_secs(10));
    tokio::time::sleep(remaining + std::time::Duration::from_millis(30)).await;
    let polled = request(
        &app,
        "POST",
        "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"terminal-owner","task_queue":"test","timeout_seconds":2}),
    )
    .await;
    assert_eq!(polled.0, StatusCode::OK, "{}", polled.1);
    let terminal = polled.1["task"].clone();
    assert!(terminal["task_id"].is_string(), "{terminal}");
    assert!(terminal["workflow_update_id"].is_null(), "{terminal}");
    let commands = if failed {
        json!([{"type":"fail_workflow","message":"ordinary terminal failure"}])
    } else {
        json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])
    };
    let done = finish_task(&app, &terminal, commands.clone()).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    let reason = if failed { "failed" } else { "completed" };
    assert_eq!(done.1["run_status"], reason);
    let receipt = closed_receipt(&app, &run, &update.1, reason).await;
    reference_after(&database, &app, &run, before).await;
    assert_eq!(
        database
            .update_failure_count(
                update.1["command_id"].as_str().unwrap(),
                receipt["failure_id"].as_str().unwrap()
            )
            .await,
        1
    );
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let event = history
        .as_array()
        .unwrap()
        .iter()
        .find(|event| event["event_type"] == "UpdateCompleted")
        .unwrap();
    assert_eq!(event["payload"]["task"]["id"], terminal["task_id"]);
    assert_eq!(event["payload"]["task"]["status"], "leased");
    assert_eq!(
        event["payload"]["task"]["lease_owner"],
        terminal["lease_owner"]
    );
    assert_eq!(
        event["payload"]["task"]["connection"],
        match &database {
            TestDatabase::Sqlite(_) => "sqlite",
            TestDatabase::Postgres { .. } => "pgsql",
            TestDatabase::MySql { .. } => "mysql",
        }
    );
    assert_eq!(
        finish_task(&app, &terminal, commands).await.1["recorded"],
        false
    );
    assert_eq!(finish_task(&app,&owned,json!([{"type":"complete_update","update_id":update.1["update_id"],"result":envelope(Payload::Long(42))}])).await.0,StatusCode::CONFLICT);
    assert_eq!(
        run_history(&app, &run["workflow_id"], &run["run_id"]).await,
        history
    );
    runtime.close().await;
    let cold = database.open().await.unwrap();
    assert_eq!(
        closed_receipt(&router(cold.clone()), &run, &update.1, reason).await,
        receipt
    );
    cold.close().await;
    database.remove().await;
}

#[tokio::test]
async fn terminal_updates_sqlite_failure_rolls_back_run_tasks_history_and_original_update() {
    let database = TestDatabase::Sqlite(tempfile::tempdir().unwrap());
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (run, update) = accepted(&app, "atomic-terminal-update").await;
    let task = poll(&app, "terminal-update-worker", "workflow").await;
    let original = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    database.execute("CREATE TRIGGER reject_terminal_update BEFORE INSERT ON workflow_history_events WHEN NEW.event_type='UpdateCompleted' BEGIN SELECT RAISE(ABORT, 'terminal update rejected'); END").await;
    let cancelled = request(
        &app,
        "POST",
        "/api/workflows/atomic-terminal-update/cancel",
        json!({}),
    )
    .await;
    assert_eq!(
        cancelled.0,
        StatusCode::SERVICE_UNAVAILABLE,
        "{}",
        cancelled.1
    );
    assert_eq!(
        run_history(&app, &run["workflow_id"], &run["run_id"]).await,
        original
    );
    assert_eq!(
        task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_failures").await,
        0
    );
    assert_eq!(
        task_retry::rows(
            &database,
            "SELECT COUNT(*) FROM workflow_updates WHERE status='accepted' AND closed_at IS NULL"
        )
        .await,
        1
    );
    assert_eq!(task_retry::rows(&database,"SELECT COUNT(*) FROM workflow_tasks WHERE status='leased' AND lease_expires_at IS NOT NULL").await,1);
    assert_eq!(task_retry::rows(&database,"SELECT COUNT(*) FROM workflow_runs WHERE message_cursor_position=0 AND status='waiting'").await,1);
    database
        .execute("DROP TRIGGER reject_terminal_update")
        .await;
    assert_eq!(
        request(
            &app,
            "POST",
            "/api/workflows/atomic-terminal-update/cancel",
            json!({})
        )
        .await
        .0,
        StatusCode::OK
    );
    closed_receipt(&app, &run, &update, "cancelled").await;
    assert_eq!(
        finish_task(
            &app,
            &task,
            json!([{"type":"fail_update","update_id":update["update_id"],"message":"too late"}])
        )
        .await
        .0,
        StatusCode::CONFLICT
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn terminal_updates_preserve_completed_receipts_and_another_active_run() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "terminal-update-worker").await;
    let first = start(&app, "closed-update-owner").await;
    open_update_wait(&app, "terminal-update-worker").await;
    let first_path = "/api/workflows/closed-update-owner/update/set";
    let resolved = request(&app, "POST", first_path, input("resolved")).await;
    assert_eq!(resolved.0, StatusCode::ACCEPTED);
    let task = poll(&app, "terminal-update-worker", "workflow").await;
    assert_eq!(finish_task(&app,&task,json!([{"type":"complete_update","update_id":resolved.1["update_id"],"result":envelope(Payload::Long(9007199254740995))}])).await.0,StatusCode::OK);
    let original_receipt = request(&app, "POST", first_path, input("resolved")).await;
    assert_eq!(original_receipt.0, StatusCode::OK);
    let other = start(&app, "active-update-owner").await;
    open_update_wait(&app, "terminal-update-worker").await;
    let other_update = request(
        &app,
        "POST",
        "/api/workflows/active-update-owner/update/set",
        input("other"),
    )
    .await;
    assert_eq!(other_update.0, StatusCode::ACCEPTED);
    let other_task = poll(&app, "terminal-update-worker", "workflow").await;
    assert_eq!(
        other_task["workflow_update_id"],
        other_update.1["update_id"]
    );
    let pending = request(&app, "POST", first_path, input("pending")).await;
    assert_eq!(pending.0, StatusCode::ACCEPTED);
    assert_eq!(
        request(
            &app,
            "POST",
            "/api/workflows/closed-update-owner/cancel",
            json!({})
        )
        .await
        .0,
        StatusCode::OK
    );
    let failed = request(&app, "POST", first_path, input("pending")).await;
    assert_eq!(failed.0, StatusCode::UNPROCESSABLE_ENTITY);
    assert_eq!(failed.1["update_status"], "failed");
    assert_eq!(
        request(&app, "POST", first_path, input("resolved")).await,
        original_receipt
    );
    assert_eq!(
        request(
            &app,
            "POST",
            "/api/workflows/active-update-owner/update/set",
            input("other")
        )
        .await
        .1,
        other_update.1
    );
    assert_eq!(finish_task(&app,&other_task,json!([{"type":"complete_update","update_id":other_update.1["update_id"],"result":envelope(Payload::Long(17))}])).await.0,StatusCode::OK);
    let history = run_history(&app, &first["workflow_id"], &first["run_id"]).await;
    let cursor: Vec<_> = history
        .as_array()
        .unwrap()
        .iter()
        .filter(|event| event["event_type"] == "MessageCursorAdvanced")
        .collect();
    assert_eq!(cursor.len(), 2);
    assert_eq!(cursor[1]["payload"]["previous_position"], 1);
    assert_eq!(cursor[1]["payload"]["new_position"], 2);
    assert_eq!(
        request(
            &app,
            "GET",
            "/api/workflows/active-update-owner",
            Value::Null
        )
        .await
        .1["run_id"],
        other["run_id"]
    );
    assert_eq!(
        task_retry::rows(
            &database,
            "SELECT COUNT(*) FROM workflow_updates WHERE status='completed'"
        )
        .await,
        2
    );
    assert_eq!(
        task_retry::rows(
            &database,
            "SELECT COUNT(*) FROM workflow_updates WHERE status='failed'"
        )
        .await,
        1
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn terminal_updates_order_linked_then_legacy_unlinked_rows_without_inventing_cursors() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (run, original) = accepted(&app, "legacy-terminal-updates").await;
    // Represent absent command links using separate fixture rows. This does
    // not qualify PHP data takeover or widen the one-at-a-time HTTP admission.
    database.execute("INSERT INTO workflow_updates(id,workflow_command_id,workflow_instance_id,workflow_run_id,resolved_workflow_run_id,update_name,status,command_sequence,payload_codec,arguments,accepted_at) SELECT '00000000000000000000000002','00000000000000000000000082',workflow_instance_id,workflow_run_id,resolved_workflow_run_id,update_name,'accepted',NULL,payload_codec,arguments,accepted_at FROM workflow_updates WHERE command_sequence IS NOT NULL AND status='accepted'").await;
    database.execute("INSERT INTO workflow_updates(id,workflow_command_id,workflow_instance_id,workflow_run_id,resolved_workflow_run_id,update_name,status,command_sequence,payload_codec,arguments,accepted_at) SELECT '00000000000000000000000001','00000000000000000000000081',workflow_instance_id,workflow_run_id,resolved_workflow_run_id,update_name,'accepted',NULL,payload_codec,arguments,accepted_at FROM workflow_updates WHERE command_sequence IS NOT NULL AND status='accepted'").await;
    assert_eq!(
        request(
            &app,
            "POST",
            "/api/workflows/legacy-terminal-updates/cancel",
            json!({})
        )
        .await
        .0,
        StatusCode::OK
    );
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let ids: Vec<_> = history
        .as_array()
        .unwrap()
        .iter()
        .filter(|e| e["event_type"] == "UpdateCompleted")
        .map(|e| e["payload"]["update_id"].clone())
        .collect();
    assert_eq!(
        ids,
        vec![
            original["update_id"].clone(),
            json!("00000000000000000000000001"),
            json!("00000000000000000000000002")
        ]
    );
    assert_eq!(
        history
            .as_array()
            .unwrap()
            .iter()
            .filter(|e| e["event_type"] == "MessageCursorAdvanced")
            .count(),
        1
    );
    assert_eq!(task_retry::rows(&database,"SELECT COUNT(*) FROM workflow_failures f JOIN workflow_updates u ON u.id=f.source_id WHERE f.source_kind='workflow_update' AND f.propagation_kind='update' AND NOT EXISTS (SELECT 1 FROM workflow_commands c WHERE c.id=u.workflow_command_id) AND u.failure_id=f.id AND u.status='failed' AND u.workflow_sequence IS NULL").await,2);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn terminal_updates_competing_cancellation_pools_commit_one_original_outcome() {
    let database = TestDatabase::new().await;
    let one = database.open().await.unwrap();
    let two = database.open().await.unwrap();
    let a = router(one.clone());
    let b = router(two.clone());
    let (run, update) = accepted(&a, "competing-terminal-updates").await;
    let path = "/api/workflows/competing-terminal-updates/cancel";
    let (left, right) = tokio::join!(
        request(&a, "POST", path, json!({})),
        request(&b, "POST", path, json!({}))
    );
    assert!(
        matches!(
            (left.0, right.0),
            (StatusCode::OK, StatusCode::CONFLICT) | (StatusCode::CONFLICT, StatusCode::OK)
        ),
        "{left:?} {right:?}"
    );
    let receipt = closed_receipt(&a, &run, &update, "cancelled").await;
    assert_eq!(
        closed_receipt(&b, &run, &update, "cancelled").await,
        receipt
    );
    assert_eq!(
        task_retry::rows(
            &database,
            "SELECT COUNT(*) FROM workflow_failures WHERE propagation_kind='update'"
        )
        .await,
        1
    );
    one.close().await;
    two.close().await;
    database.remove().await;
}
