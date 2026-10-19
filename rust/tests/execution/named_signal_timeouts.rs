use super::{run_observation_signals as signals, *};

fn wait(seconds: Value) -> Value {
    json!([{"type":"open_signal_wait","signal_name":"payload","timeout_seconds":seconds}])
}

fn close() -> Value {
    json!([{"type":"complete_workflow","result":envelope(Payload::Boolean(false))}])
}

async fn capture(database: &TestDatabase, app: &Router, namespace: &str, run: &Value) -> Value {
    let observed = signals::read(app, namespace, run, "observation").await;
    run_observation_summaries::export_reference_for(
        database,
        &observed,
        &observed["history"],
        "DW_TEST_NAMED_SIGNAL_REFERENCE",
    )
    .await;
    observed
}

fn payload<'a>(history: &'a Value, kind: &str) -> &'a Value {
    &history["events"]
        .as_array()
        .unwrap()
        .iter()
        .find(|event| event["event_type"] == kind)
        .unwrap()["payload"]
}

async fn expiry(seconds: i64) {
    let namespace = "named-signal-expiry";
    let database = TestDatabase::new().await;
    let first = database.open().await.unwrap();
    let app = router(first.clone());
    for name in [namespace, "named-signal-other"] {
        namespaces::create(&app, name).await;
        signals::register_signal_worker(&app, name).await;
    }
    let run = namespaces::start_scoped(&app, namespace, &format!("named-expiry-{seconds}")).await;
    let original = signals::claim(&app, namespace).await;
    let command = wait(json!(seconds));
    let opened = signals::finish(&app, namespace, &original, command.clone()).await;
    assert_eq!(opened.0, StatusCode::OK, "{}", opened.1);
    let prefix = signals::read(&app, namespace, &run, "history").await;
    assert_eq!(
        signals::finish(&app, namespace, &original, command).await.1["recorded"],
        false
    );
    assert_eq!(
        signals::read(&app, namespace, &run, "history").await,
        prefix
    );
    drop(app);
    first.close().await;

    let (a, b) = tokio::join!(database.open(), database.open());
    let (a, b) = (a.unwrap(), b.unwrap());
    let (app_a, app_b) = (router(a.clone()), router(b.clone()));
    assert!(signals::claim(&app_b, "named-signal-other").await.is_null());
    let resumed = signals::claim_with_timeout(&app_a, namespace, 3).await;
    assert!(
        !resumed.is_null(),
        "named signal timeout did not resume within its original three-second poll"
    );
    assert_eq!(resumed["run_id"], run["run_id"]);
    assert!(signals::claim(&app_b, namespace).await.is_null());
    let history = signals::read(&app_b, namespace, &run, "history").await;
    let events = history["events"].as_array().unwrap();
    assert_eq!(
        events
            .iter()
            .map(|event| event["event_type"].as_str().unwrap())
            .collect::<Vec<_>>(),
        [
            "StartAccepted",
            "WorkflowStarted",
            "SignalWaitOpened",
            "TimerScheduled",
            "TimerFired"
        ]
    );
    let opened = payload(&history, "SignalWaitOpened");
    let scheduled = payload(&history, "TimerScheduled");
    let fired = payload(&history, "TimerFired");
    for timer in [scheduled, fired] {
        assert_eq!(timer["timer_kind"], "signal_timeout");
        assert_eq!(timer["signal_wait_id"], opened["signal_wait_id"]);
        assert_eq!(timer["signal_name"], "payload");
        assert_eq!(timer["sequence"], opened["sequence"]);
        assert_eq!(timer["delay_seconds"], seconds);
    }
    assert_eq!(fired["timer_id"], scheduled["timer_id"]);
    let planned =
        chrono::DateTime::parse_from_rfc3339(scheduled["fire_at"].as_str().unwrap()).unwrap();
    let fired_at =
        chrono::DateTime::parse_from_rfc3339(fired["fired_at"].as_str().unwrap()).unwrap();
    assert!(
        fired_at >= planned,
        "timer fired before its authored deadline"
    );
    assert_eq!(resumed["workflow_wait_kind"], "signal");
    assert_eq!(resumed["open_wait_id"], opened["signal_wait_id"]);
    assert_eq!(resumed["resume_source_kind"], "timer");
    assert_eq!(resumed["resume_source_id"], scheduled["timer_id"]);
    assert_eq!(resumed["timer_id"], scheduled["timer_id"]);
    assert_eq!(resumed["timer_kind"], "signal_timeout");
    let observed = capture(&database, &app_b, namespace, &run).await;
    assert_eq!(observed["summary_state"], "observed", "{observed}");
    assert_eq!(observed["liveness_state"], "workflow_task_leased");
    assert_eq!(observed["current_waits"]["returned_count"], 0);
    assert_eq!(
        signals::finish(&app_b, "named-signal-other", &resumed, close())
            .await
            .0,
        StatusCode::NOT_FOUND
    );
    assert_eq!(
        signals::read(&app_a, namespace, &run, "history").await,
        history
    );
    let completed = signals::finish(&app_b, namespace, &resumed, close()).await;
    assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
    assert_eq!(completed.1["run_status"], "completed");
    assert_eq!(
        signals::finish(&app_a, namespace, &resumed, close())
            .await
            .1["recorded"],
        false
    );
    assert_eq!(
        capture(&database, &app_a, namespace, &run).await["liveness_state"],
        "closed"
    );
    assert!(signals::claim(&app_a, namespace).await.is_null());
    a.close().await;
    b.close().await;
    database.remove().await;
}

#[tokio::test]
async fn named_immediate_signal_timeout_preserves_one_original_across_cold_pools() {
    expiry(0).await;
}

#[tokio::test]
async fn named_durable_signal_timeout_preserves_original_deadline_across_cold_pools() {
    expiry(1).await;
}

#[tokio::test]
async fn named_signal_input_wins_with_original_timer_and_typed_value() {
    for buffered in [false, true] {
        let namespace = "named-signal-input";
        let database = TestDatabase::new().await;
        let first = database.open().await.unwrap();
        let app = router(first.clone());
        namespaces::create(&app, namespace).await;
        signals::register_signal_worker(&app, namespace).await;
        let run =
            namespaces::start_scoped(&app, namespace, &format!("named-input-{buffered}")).await;
        let original = signals::claim(&app, namespace).await;
        if buffered {
            assert_eq!(
                signals::signal(&app, namespace, &run).await.0,
                StatusCode::ACCEPTED
            );
        }
        let opened = signals::finish(
            &app,
            namespace,
            &original,
            wait(json!(if buffered { 0 } else { 60 })),
        )
        .await;
        assert_eq!(opened.0, StatusCode::OK, "{}", opened.1);
        let history = signals::read(&app, namespace, &run, "history").await;
        let timers: Vec<_> = history["events"]
            .as_array()
            .unwrap()
            .iter()
            .filter(|event| event["event_type"] == "TimerScheduled")
            .collect();
        assert_eq!(timers.len(), usize::from(!buffered));
        let scheduled = timers.first().map(|event| event["payload"].clone());
        assert_eq!(
            capture(&database, &app, namespace, &run).await["current_waits"]["returned_count"],
            1
        );
        drop(app);
        first.close().await;

        let cold = database.open().await.unwrap();
        let app = router(cold.clone());
        if !buffered {
            assert_eq!(
                signals::signal(&app, namespace, &run).await.0,
                StatusCode::ACCEPTED
            );
        }
        let before = signals::read(&app, namespace, &run, "history").await;
        assert!(
            before["events"]
                .as_array()
                .unwrap()
                .iter()
                .all(|event| event["event_type"] != "TimerCancelled")
        );
        let resumed = signals::claim(&app, namespace).await;
        assert_eq!(resumed["run_id"], run["run_id"]);
        capture(&database, &app, namespace, &run).await;
        let done = signals::finish(&app, namespace, &resumed, close()).await;
        assert_eq!(done.0, StatusCode::OK, "{}", done.1);
        let history = signals::read(&app, namespace, &run, "history").await;
        let received = payload(&history, "SignalReceived");
        let applied = payload(&history, "SignalApplied");
        assert_eq!(applied["signal_id"], received["signal_id"]);
        assert_eq!(
            applied["workflow_command_id"],
            received["workflow_command_id"]
        );
        assert_eq!(
            applied["signal_wait_id"],
            payload(&history, "SignalWaitOpened")["signal_wait_id"]
        );
        assert_eq!(
            ValueCodec::new()
                .unwrap()
                .decode(applied["value"]["blob"].as_str().unwrap())
                .unwrap(),
            signals::signal_value()
        );
        let cancelled: Vec<_> = history["events"]
            .as_array()
            .unwrap()
            .iter()
            .filter(|event| event["event_type"] == "TimerCancelled")
            .collect();
        assert_eq!(cancelled.len(), usize::from(!buffered));
        if let Some(scheduled) = scheduled {
            for field in [
                "timer_id",
                "timer_kind",
                "signal_wait_id",
                "signal_name",
                "sequence",
                "delay_seconds",
                "fire_at",
            ] {
                assert_eq!(cancelled[0]["payload"][field], scheduled[field], "{field}");
            }
        }
        assert!(
            history["events"]
                .as_array()
                .unwrap()
                .iter()
                .all(|event| event["event_type"] != "TimerFired")
        );
        assert_eq!(
            signals::finish(&app, namespace, &resumed, close()).await.1["recorded"],
            false
        );
        assert_eq!(
            signals::read(&app, namespace, &run, "history").await,
            history
        );
        assert_eq!(
            capture(&database, &app, namespace, &run).await["liveness_state"],
            "closed"
        );
        cold.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn named_invalid_signal_timeouts_leave_original_attempt_usable() {
    let namespace = "named-signal-invalid";
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    namespaces::create(&app, namespace).await;
    signals::register_signal_worker(&app, namespace).await;
    let run = namespaces::start_scoped(&app, namespace, "named-invalid-timeout").await;
    let task = signals::claim(&app, namespace).await;
    let before = signals::read(&app, namespace, &run, "history").await;
    for timeout in [json!(-1), json!(1.5), json!("1"), json!(i64::MAX)] {
        let refused = signals::finish(&app, namespace, &task, wait(timeout)).await;
        assert_eq!(refused.0, StatusCode::UNPROCESSABLE_ENTITY);
        assert_eq!(refused.1["reason"], "invalid_signal_timeout");
        assert_eq!(
            signals::read(&app, namespace, &run, "history").await,
            before
        );
    }
    assert_eq!(
        task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_run_timers").await,
        0
    );
    assert_eq!(
        task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_run_waits").await,
        0
    );
    assert_eq!(
        signals::finish(&app, namespace, &task, close()).await.0,
        StatusCode::OK
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn named_signal_timeout_projection_failure_rolls_back_original_transition() {
    let namespace = "named-signal-rollback";
    let database = TestDatabase::Sqlite(tempfile::tempdir().unwrap());
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    namespaces::create(&app, namespace).await;
    signals::register_signal_worker(&app, namespace).await;
    let run = namespaces::start_scoped(&app, namespace, "named-timeout-rollback").await;
    let task = signals::claim(&app, namespace).await;
    let before = signals::read(&app, namespace, &run, "history").await;
    database.execute("CREATE TRIGGER reject_named_signal_timer BEFORE INSERT ON workflow_run_timers BEGIN SELECT RAISE(ABORT, 'named signal timer refused'); END").await;
    assert_eq!(
        signals::finish(&app, namespace, &task, wait(json!(60)))
            .await
            .0,
        StatusCode::SERVICE_UNAVAILABLE
    );
    assert_eq!(
        signals::read(&app, namespace, &run, "history").await,
        before
    );
    assert_eq!(
        task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_run_timers").await,
        0
    );
    assert_eq!(
        task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_run_waits").await,
        0
    );
    database
        .execute("DROP TRIGGER reject_named_signal_timer")
        .await;
    assert_eq!(
        signals::finish(&app, namespace, &task, wait(json!(60)))
            .await
            .0,
        StatusCode::OK
    );
    capture(&database, &app, namespace, &run).await;
    let cancelled = namespaces::scoped(
        &app,
        namespace,
        "POST",
        &format!(
            "/api/workflows/{}/runs/{}/cancel",
            run["workflow_id"].as_str().unwrap(),
            run["run_id"].as_str().unwrap()
        ),
        json!({}),
    )
    .await;
    assert_eq!(cancelled.0, StatusCode::OK, "{}", cancelled.1);
    assert_eq!(
        capture(&database, &app, namespace, &run).await["liveness_state"],
        "closed"
    );
    runtime.close().await;
    database.remove().await;
}
