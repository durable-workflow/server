use super::*;

async fn rows(database: &TestDatabase, sql: &'static str) -> i64 {
    task_retry::rows(database, sql).await
}

async fn global_deadline(database: &TestDatabase) -> String {
    match database {
        TestDatabase::Sqlite(dir) => {
            let pool = sqlx::SqlitePool::connect(&format!(
                "sqlite://{}",
                dir.path().join("runtime.sqlite").display()
            ))
            .await
            .unwrap();
            let value: String =
                sqlx::query_scalar("SELECT schedule_to_close_deadline_at FROM activity_executions")
                    .fetch_one(&pool)
                    .await
                    .unwrap();
            pool.close().await;
            value
        }
        TestDatabase::Postgres { options, .. } => {
            let pool = sqlx::postgres::PgPoolOptions::new()
                .connect_with(options.as_ref().clone())
                .await
                .unwrap();
            let value: String = sqlx::query_scalar(
                "SELECT schedule_to_close_deadline_at::text FROM activity_executions",
            )
            .fetch_one(&pool)
            .await
            .unwrap();
            pool.close().await;
            value
        }
        TestDatabase::MySql { options, .. } => {
            let pool = sqlx::mysql::MySqlPoolOptions::new()
                .connect_with(options.as_ref().clone())
                .await
                .unwrap();
            let value: String = sqlx::query_scalar(
                "SELECT CAST(schedule_to_close_deadline_at AS CHAR) FROM activity_executions",
            )
            .fetch_one(&pool)
            .await
            .unwrap();
            pool.close().await;
            value
        }
    }
}

async fn running_deadlines(database: &TestDatabase) -> (String, String, String) {
    match database {
        TestDatabase::Sqlite(dir) => {
            let pool = sqlx::SqlitePool::connect(&format!(
                "sqlite://{}",
                dir.path().join("runtime.sqlite").display()
            ))
            .await
            .unwrap();
            let values = sqlx::query_as("SELECT close_deadline_at,heartbeat_deadline_at,schedule_to_close_deadline_at FROM activity_executions").fetch_one(&pool).await.unwrap();
            pool.close().await;
            values
        }
        TestDatabase::Postgres { options, .. } => {
            let pool = sqlx::postgres::PgPoolOptions::new()
                .connect_with(options.as_ref().clone())
                .await
                .unwrap();
            let values = sqlx::query_as("SELECT close_deadline_at::text,heartbeat_deadline_at::text,schedule_to_close_deadline_at::text FROM activity_executions").fetch_one(&pool).await.unwrap();
            pool.close().await;
            values
        }
        TestDatabase::MySql { options, .. } => {
            let pool = sqlx::mysql::MySqlPoolOptions::new()
                .connect_with(options.as_ref().clone())
                .await
                .unwrap();
            let values = sqlx::query_as("SELECT CAST(close_deadline_at AS CHAR),CAST(heartbeat_deadline_at AS CHAR),CAST(schedule_to_close_deadline_at AS CHAR) FROM activity_executions").fetch_one(&pool).await.unwrap();
            pool.close().await;
            values
        }
    }
}

fn specification() -> Value {
    serde_json::from_str(include_str!(
        "../../../tests/Fixtures/ServerParityPending/activity-deadline-authority.json"
    ))
    .unwrap()
}

fn command(field: &str, max: i64) -> Value {
    let mut command = json!({"type":"schedule_activity","activity_type":"deadline_activity", "arguments":envelope(Payload::Array(vec![Payload::Long(9007199254740993)])),
        "retry_policy":{"max_attempts":max,"backoff_seconds":specification()["activity_deadline_authority"]["retry_backoff_seconds"]}});
    command[field] = json!(1);
    command
}

async fn resolution(app: &Router, started: &Value, kind: &str) -> Value {
    let deadline = tokio::time::Instant::now() + std::time::Duration::from_secs(3);
    loop {
        let history = run_history(app, &started["workflow_id"], &started["run_id"]).await;
        if history
            .as_array()
            .unwrap()
            .iter()
            .any(|event| event["event_type"] == kind)
        {
            return history;
        }
        assert!(
            tokio::time::Instant::now() < deadline,
            "Original timeout observer expired: {history}"
        );
        tokio::time::sleep(std::time::Duration::from_millis(25)).await;
    }
}

#[tokio::test]
async fn every_authored_deadline_fences_outcomes_and_resolves_once() {
    for field in specification()["activity_deadline_authority"]["fields"]
        .as_array()
        .unwrap()
    {
        let field = field.as_str().unwrap();
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        register(
            &app,
            "deadline-worker",
            json!(["echo"]),
            json!(["deadline_activity"]),
        )
        .await;
        let started = start(&app, &format!("deadline-{field}")).await;
        let workflow = poll(&app, "deadline-worker", "workflow").await;
        assert_eq!(
            finish_task(&app, &workflow, json!([command(field, 1)]))
                .await
                .0,
            StatusCode::OK
        );
        let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
        let snapshot = &history
            .as_array()
            .unwrap()
            .iter()
            .find(|event| event["event_type"] == "ActivityScheduled")
            .unwrap()["payload"]["activity"];
        assert_eq!(snapshot["sequence"], 1);
        assert_eq!(snapshot["type"], "deadline_activity");
        assert_eq!(snapshot["class"], "deadline_activity");
        assert_eq!(snapshot["local_activity"], false);
        let activity = if field == "schedule_to_start_timeout" {
            Value::Null
        } else {
            poll(&app, "deadline-worker", "activity").await
        };
        if !activity.is_null() {
            let expires = chrono::DateTime::parse_from_rfc3339(
                activity["lease_expires_at"].as_str().unwrap(),
            )
            .unwrap();
            let remaining =
                (expires.with_timezone(&chrono::Utc) - chrono::Utc::now()).num_seconds();
            assert!(
                (295..=300).contains(&remaining),
                "Published ordinary five-minute lease: {remaining}"
            );
        }
        let expire_sql = match field {
            "schedule_to_start_timeout" => {
                "UPDATE activity_executions SET schedule_deadline_at='2020-01-02 03:04:05.123456'"
            }
            "schedule_to_close_timeout" => {
                "UPDATE activity_executions SET schedule_to_close_deadline_at='2020-01-02 03:04:05.123456'"
            }
            "heartbeat_timeout" => {
                "UPDATE activity_executions SET heartbeat_deadline_at='2020-01-02 03:04:05.123456'"
            }
            _ => "UPDATE activity_executions SET close_deadline_at='2020-01-02 03:04:05.123456'",
        };
        database.execute(expire_sql).await;
        if !activity.is_null() {
            let base = json!({"lease_owner":activity["lease_owner"],"activity_attempt_id":activity["activity_attempt_id"]});
            for outcome in ["complete", "fail", "heartbeat"] {
                let mut body = base.clone();
                if outcome == "complete" {
                    body["result"] = envelope(Payload::Long(-1));
                }
                if outcome == "fail" {
                    body["failure"] = json!({"type":"RuntimeException","message":"late","code":0});
                }
                let status = request(
                    &app,
                    "POST",
                    &format!(
                        "/api/worker/activity-tasks/{}/{outcome}",
                        activity["task_id"].as_str().unwrap()
                    ),
                    body,
                )
                .await;
                assert_eq!(
                    status.0,
                    if outcome == "heartbeat" {
                        StatusCode::OK
                    } else {
                        StatusCode::CONFLICT
                    },
                    "{field}/{outcome}: {}",
                    status.1
                );
                if outcome == "heartbeat" {
                    assert_eq!(status.1["can_continue"], false);
                    assert_eq!(status.1["heartbeat_recorded"], false);
                    assert_eq!(status.1["protocol_version"], "1.19");
                }
            }
        }
        let history = resolution(&app, &started, "ActivityTimedOut").await;
        if !activity.is_null() {
            // Once the timeout closed the attempt, a late result gets PHP's
            // stale attempt receipt, which the SDKs recognize and discard.
            let path = |outcome: &str| {
                format!("/api/worker/activity-tasks/{}/{outcome}", activity["task_id"].as_str().unwrap())
            };
            let late = request(&app, "POST", &path("complete"),
                json!({"lease_owner":activity["lease_owner"],"activity_attempt_id":activity["activity_attempt_id"],
                    "result":envelope(Payload::Long(-1))})).await;
            assert_eq!(late.0, StatusCode::CONFLICT, "{field}: {}", late.1);
            assert_eq!(
                late.1,
                json!({"task_id":activity["task_id"],"activity_attempt_id":activity["activity_attempt_id"],
                    "outcome":"completed","recorded":false,"reason":"stale_attempt","next_task_id":null,
                    "lease_owner":activity["lease_owner"],"activity_status":"failed","attempt_status":"failed",
                    "task_status":"cancelled"}),
                "{field}"
            );
            let late = request(&app, "POST", &path("fail"),
                json!({"lease_owner":activity["lease_owner"],"activity_attempt_id":activity["activity_attempt_id"],
                    "failure":{"type":"RuntimeException","message":"late","code":0}})).await;
            assert_eq!(late.0, StatusCode::CONFLICT, "{field}: {}", late.1);
            assert_eq!(
                late.1,
                json!({"task_id":activity["task_id"],"activity_attempt_id":activity["activity_attempt_id"],
                    "outcome":"failed","recorded":false,"reason":"stale_attempt","next_task_id":null}),
                "{field}"
            );
        }
        let timed = history
            .as_array()
            .unwrap()
            .iter()
            .filter(|event| event["event_type"] == "ActivityTimedOut")
            .collect::<Vec<_>>();
        assert_eq!(timed.len(), 1);
        assert_eq!(
            timed[0]["payload"]["timeout_kind"],
            field.trim_end_matches("_timeout")
        );
        assert_eq!(timed[0]["payload"]["failure_category"], "timeout");
        assert_eq!(
            timed[0]["payload"]["exception_class"],
            specification()["activity_deadline_authority"]["exception_class"]
        );
        assert_eq!(rows(&database,"SELECT COUNT(*) FROM workflow_history_events WHERE event_type IN ('ActivityCompleted','ActivityHeartbeatRecorded','ActivityRetryScheduled')").await,0);
        assert_eq!(rows(&database,"SELECT COUNT(*) FROM workflow_failures WHERE failure_category='timeout' AND non_retryable=FALSE").await,1);
        assert_eq!(
            rows(
                &database,
                "SELECT COUNT(*) FROM activity_executions WHERE status='failed' AND result IS NULL"
            )
            .await,
            1
        );
        let resumed = poll(&app, "deadline-worker", "workflow").await;
        assert_ne!(resumed["task_id"], workflow["task_id"]);
        assert_eq!(finish_task(&app,&resumed,json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}])).await.0,StatusCode::OK);
        assert_eq!(
            run_history(&app, &started["workflow_id"], &started["run_id"])
                .await
                .as_array()
                .unwrap()
                .iter()
                .filter(|event| event["event_type"] == "ActivityTimedOut")
                .count(),
            1
        );
        runtime.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn timeout_retry_preserves_full_snapshot_and_global_deadline_across_restart() {
    let database = TestDatabase::new().await;
    let first = database.open().await.unwrap();
    let app = router(first.clone());
    register(
        &app,
        "retry-deadline-worker",
        json!(["echo"]),
        json!(["deadline_activity"]),
    )
    .await;
    let started = start(&app, "deadline-retry").await;
    let workflow = poll(&app, "retry-deadline-worker", "workflow").await;
    let mut scheduled = command("start_to_close_timeout", 2);
    scheduled["schedule_to_close_timeout"] = json!(30);
    scheduled["schedule_to_start_timeout"] = json!(10);
    // PHP refuses a heartbeat timeout longer than start_to_close.
    scheduled["heartbeat_timeout"] = json!(1);
    assert_eq!(
        finish_task(&app, &workflow, json!([scheduled])).await.0,
        StatusCode::OK
    );
    let activity = poll(&app, "retry-deadline-worker", "activity").await;
    let original_deadline = global_deadline(&database).await;
    database
        .execute("UPDATE activity_executions SET close_deadline_at='2020-01-02 03:04:05.123456'")
        .await;
    let prefix = resolution(&app, &started, "ActivityRetryScheduled").await;
    assert_eq!(
        global_deadline(&database).await,
        original_deadline,
        "Retry retains original lifecycle deadline"
    );
    let retry = &prefix.as_array().unwrap().last().unwrap()["payload"];
    assert_eq!(retry["timeout_kind"], "start_to_close");
    assert_eq!(
        retry["retry_policy"]["backoff_seconds"],
        specification()["activity_deadline_authority"]["retry_backoff_seconds"]
    );
    assert_eq!(retry["retry_policy"]["schedule_to_close_timeout"], 30);
    assert!(
        poll(&app, "retry-deadline-worker", "workflow")
            .await
            .is_null()
    );
    assert!(
        poll(&app, "retry-deadline-worker", "activity")
            .await
            .is_null()
    );
    drop(app);
    first.close().await;
    let second = database.open().await.unwrap();
    let app = router(second.clone());
    let available =
        chrono::DateTime::parse_from_rfc3339(retry["retry_available_at"].as_str().unwrap())
            .unwrap();
    let wait = (available.with_timezone(&chrono::Utc) - chrono::Utc::now())
        .to_std()
        .unwrap_or_default();
    tokio::time::sleep(wait + std::time::Duration::from_millis(10)).await;
    let recovered = poll(&app, "retry-deadline-worker", "activity").await;
    assert_eq!(
        global_deadline(&database).await,
        original_deadline,
        "Cold replacement retains original lifecycle deadline"
    );
    assert_eq!(
        recovered["activity_execution_id"],
        activity["activity_execution_id"]
    );
    assert_ne!(recovered["task_id"], activity["task_id"]);
    assert_ne!(
        recovered["activity_attempt_id"],
        activity["activity_attempt_id"]
    );
    assert_eq!(recovered["attempt_number"], 2);
    assert_eq!(recovered["retry_policy"], activity["retry_policy"]);
    let path = format!(
        "/api/worker/activity-tasks/{}/complete",
        recovered["task_id"].as_str().unwrap()
    );
    let completion = json!({"activity_attempt_id":recovered["activity_attempt_id"],"lease_owner":recovered["lease_owner"],"result":envelope(Payload::Long(9007199254740993))});
    assert_eq!(
        request(&app, "POST", &path, completion).await.0,
        StatusCode::OK
    );
    let done = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(
        &done.as_array().unwrap()[..prefix.as_array().unwrap().len()],
        prefix.as_array().unwrap()
    );
    assert_eq!(rows(&database,"SELECT COUNT(*) FROM activity_executions WHERE status='completed' AND attempt_count=2 AND schedule_to_close_deadline_at IS NOT NULL").await,1);
    second.close().await;
    database.remove().await;
}

#[tokio::test]
async fn heartbeat_renews_only_progress_deadline_and_cannot_resurrect_elapsed_authority() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(
        &app,
        "heartbeat-deadline-worker",
        json!(["echo"]),
        json!(["deadline_activity"]),
    )
    .await;
    let started = start(&app, "heartbeat-deadline").await;
    let workflow = poll(&app, "heartbeat-deadline-worker", "workflow").await;
    let mut scheduled = command("heartbeat_timeout", 1);
    scheduled["heartbeat_timeout"] = json!(10);
    scheduled["start_to_close_timeout"] = json!(30);
    scheduled["schedule_to_close_timeout"] = json!(60);
    assert_eq!(
        finish_task(&app, &workflow, json!([scheduled])).await.0,
        StatusCode::OK
    );
    if let TestDatabase::MySql { options, .. } = &database {
        let pool = sqlx::mysql::MySqlPoolOptions::new()
            .connect_with(options.as_ref().clone())
            .await
            .unwrap();
        // Force storage latency after the attempt clock is captured. The
        // durable event must use that clock, rather than its publication time.
        sqlx::raw_sql("CREATE TRIGGER delay_activity_attempt BEFORE INSERT ON activity_attempts FOR EACH ROW SET @activity_clock_delay = SLEEP(0.025)")
            .execute(&pool)
            .await
            .unwrap();
        pool.close().await;
    }
    let activity = poll(&app, "heartbeat-deadline-worker", "activity").await;
    let initial = running_deadlines(&database).await;
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let start = history
        .as_array()
        .unwrap()
        .iter()
        .find(|event| event["event_type"] == "ActivityStarted")
        .unwrap();
    let at = chrono::DateTime::parse_from_rfc3339(start["timestamp"].as_str().unwrap()).unwrap();
    // All three qualification backends use UTC; SQL text omits the RFC3339 Z.
    let sql_deadline = format!("{}Z", initial.0.trim_end_matches("+00").replace(' ', "T"));
    let close = chrono::DateTime::parse_from_rfc3339(&sql_deadline).unwrap();
    assert_eq!(
        close,
        at + chrono::Duration::seconds(30),
        "The immutable deadline and durable start event share the original attempt clock"
    );
    let base = json!({"activity_attempt_id":activity["activity_attempt_id"],"lease_owner":activity["lease_owner"]});
    let status_path = format!(
        "/api/worker/activity-tasks/{}/status",
        activity["task_id"].as_str().unwrap()
    );
    // Claims and status reads carry PHP's deadlines map in UTC microseconds.
    let micros = |at: chrono::DateTime<chrono::FixedOffset>| {
        json!(at.with_timezone(&chrono::Utc).to_rfc3339_opts(chrono::SecondsFormat::Micros, true))
    };
    let deadlines = &activity["deadlines"];
    assert_eq!(deadlines["start_to_close"], micros(close), "{activity}");
    assert_eq!(deadlines["heartbeat"], micros(at + chrono::Duration::seconds(10)));
    let lifecycle = deadlines["schedule_to_close"].as_str().unwrap();
    assert!(lifecycle.ends_with('Z') && lifecycle.len() == 27, "{lifecycle}");
    assert!(deadlines.get("schedule_to_start").is_none(), "{deadlines}");
    let status = request_protocol(&app, "POST", &status_path, base.clone(), "1.20").await;
    assert_eq!(status.1["can_continue"], true, "{}", status.1);
    assert_eq!(&status.1["deadlines"], deadlines);
    assert_eq!(
        running_deadlines(&database).await,
        initial,
        "Status never renews authority"
    );
    tokio::time::sleep(std::time::Duration::from_millis(20)).await;
    let heartbeat_path = format!(
        "/api/worker/activity-tasks/{}/heartbeat",
        activity["task_id"].as_str().unwrap()
    );
    let renewed = request(&app, "POST", &heartbeat_path, base.clone()).await;
    assert_eq!(renewed.0, StatusCode::OK, "{}", renewed.1);
    assert_eq!(renewed.1["heartbeat_recorded"], true);
    let after = running_deadlines(&database).await;
    assert_eq!(after.0, initial.0, "Start-to-close deadline remains fixed");
    assert!(after.1 > initial.1, "Heartbeat deadline advances");
    assert_eq!(after.2, initial.2, "Lifecycle deadline remains fixed");
    assert!(
        renewed.1["lease_expires_at"].as_str().unwrap()
            > activity["lease_expires_at"].as_str().unwrap()
    );
    let prefix = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    database
        .execute(
            "UPDATE activity_executions SET heartbeat_deadline_at='2020-01-02 03:04:05.123456'",
        )
        .await;
    let expired = running_deadlines(&database).await;
    let refusal = request(&app, "POST", &heartbeat_path, base).await;
    assert_eq!(refusal.0, StatusCode::OK);
    assert_eq!(refusal.1["can_continue"], false);
    assert_eq!(refusal.1["heartbeat_recorded"], false);
    assert_eq!(
        running_deadlines(&database).await,
        expired,
        "Late heartbeat cannot restore authority"
    );
    let after_refusal = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(
        &after_refusal.as_array().unwrap()[..prefix.as_array().unwrap().len()],
        prefix.as_array().unwrap(),
        "The original heartbeat history remains unchanged"
    );
    // The independent timeout sweep may already have appended its canonical
    // expiry event. The refused request must never record another heartbeat.
    assert!(after_refusal.as_array().unwrap().len() <= prefix.as_array().unwrap().len() + 1);
    assert_eq!(
        after_refusal
            .as_array()
            .unwrap()
            .iter()
            .filter(|event| event["event_type"] == "ActivityHeartbeatRecorded")
            .count(),
        1
    );
    assert!(
        after_refusal.as_array().unwrap()[prefix.as_array().unwrap().len()..]
            .iter()
            .all(|event| event["event_type"] == "ActivityTimedOut")
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn committed_activity_receipt_survives_elapsed_deadlines_and_conflicting_outcome_refuses() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(
        &app,
        "receipt-worker",
        json!(["echo"]),
        json!(["deadline_activity"]),
    )
    .await;
    let started = start(&app, "deadline-receipt").await;
    let workflow = poll(&app, "receipt-worker", "workflow").await;
    let mut scheduled = command("start_to_close_timeout", 1);
    scheduled["start_to_close_timeout"] = json!(60);
    assert_eq!(
        finish_task(&app, &workflow, json!([scheduled])).await.0,
        StatusCode::OK
    );
    let activity = poll(&app, "receipt-worker", "activity").await;
    let path = format!(
        "/api/worker/activity-tasks/{}/complete",
        activity["task_id"].as_str().unwrap()
    );
    let completion = json!({"activity_attempt_id":activity["activity_attempt_id"],"lease_owner":activity["lease_owner"],"result":envelope(Payload::Long(9007199254740993))});
    assert_eq!(
        request(&app, "POST", &path, completion.clone()).await.1["recorded"],
        true
    );
    let prefix = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    database
        .execute("UPDATE activity_executions SET close_deadline_at='2020-01-02 03:04:05.123456'")
        .await;
    database.execute("UPDATE workflow_tasks SET lease_expires_at='2020-01-02 03:04:05.123456' WHERE task_type='activity'").await;
    assert_eq!(
        request(&app, "POST", &path, completion.clone()).await.1["recorded"],
        false
    );
    let mut conflicting = completion;
    conflicting["result"] = envelope(Payload::Long(-1));
    // A different result for the finished attempt is stale, as in PHP.
    let refused = request(&app, "POST", &path, conflicting).await;
    assert_eq!(refused.0, StatusCode::CONFLICT, "{}", refused.1);
    assert_eq!(refused.1["reason"], "stale_attempt");
    assert_eq!(refused.1["recorded"], false);
    assert_eq!(
        run_history(&app, &started["workflow_id"], &started["run_id"]).await,
        prefix
    );
    runtime.close().await;
    database.remove().await;
}
