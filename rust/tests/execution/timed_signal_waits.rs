use super::*;

async fn author(app: &Router, worker: &str) {
    let registered = request(app, "POST", "/api/worker/register", json!({
        "worker_id":worker,"task_queue":"test","runtime":"php",
        "supported_workflow_types":["echo"],"supported_activity_types":[],
        "workflow_command_contracts":{"echo":{"signals":["payload"],"signal_contracts":[{
            "name":"payload","parameters":[{"name":"value","position":0,"type":"int",
                "required":true,"variadic":false,"default_available":false,"allows_null":false}]}]}}
    })).await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
}

async fn expiry(seconds: i64) {
    let database = TestDatabase::new().await;
    let first = database.open().await.unwrap();
    let app = router(first.clone());
    author(&app, "timed-signal-author").await;
    let started = start(&app, &format!("timed-signal-{seconds}")).await;
    let original = poll(&app, "timed-signal-author", "workflow").await;
    let commands =
        json!([{"type":"open_signal_wait","signal_name":"payload","timeout_seconds":seconds}]);
    let opened = finish_task(&app, &original, commands.clone()).await;
    assert_eq!(opened.0, StatusCode::OK, "{}", opened.1);
    let prefix = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(
        finish_task(&app, &original, commands).await.1["recorded"],
        false
    );
    assert_eq!(
        run_history(&app, &started["workflow_id"], &started["run_id"]).await,
        prefix
    );
    drop(app);
    first.close().await;

    let second = database.open().await.unwrap();
    let app = router(second.clone());
    author(&app, "timed-signal-resumed").await;
    let response = request(
        &app,
        "POST",
        "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"timed-signal-resumed","task_queue":"test","timeout_seconds":3}),
    )
    .await;
    assert_eq!(response.0, StatusCode::OK, "{}", response.1);
    let resumed = &response.1["task"];
    assert!(
        !resumed.is_null(),
        "original timeout did not resume within three seconds"
    );
    let events = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(
        events
            .as_array()
            .unwrap()
            .iter()
            .map(|e| e["event_type"].as_str().unwrap())
            .collect::<Vec<_>>(),
        [
            "StartAccepted",
            "WorkflowStarted",
            "SignalWaitOpened",
            "TimerScheduled",
            "TimerFired"
        ]
    );
    let opened = &events[2]["payload"];
    let scheduled = &events[3]["payload"];
    let fired = &events[4]["payload"];
    for payload in [scheduled, fired] {
        assert_eq!(payload["timer_kind"], "signal_timeout");
        assert_eq!(payload["signal_wait_id"], opened["signal_wait_id"]);
        assert_eq!(payload["signal_name"], "payload");
        assert_eq!(payload["sequence"], opened["sequence"]);
        assert_eq!(payload["delay_seconds"], seconds);
    }
    assert_eq!(fired["timer_id"], scheduled["timer_id"]);
    let planned =
        chrono::DateTime::parse_from_rfc3339(scheduled["fire_at"].as_str().unwrap()).unwrap();
    let actual = chrono::DateTime::parse_from_rfc3339(fired["fired_at"].as_str().unwrap()).unwrap();
    assert!(
        actual >= planned,
        "timeout fired before its original deadline"
    );
    assert_eq!(resumed["workflow_wait_kind"], "signal");
    assert_eq!(resumed["open_wait_id"], opened["signal_wait_id"]);
    assert_eq!(resumed["resume_source_kind"], "timer");
    assert_eq!(resumed["resume_source_id"], scheduled["timer_id"]);
    assert_eq!(resumed["timer_id"], scheduled["timer_id"]);
    assert_eq!(resumed["timer_kind"], "signal_timeout");
    let observed = observation(&app, &started).await;
    assert_eq!(observed["summary_state"], "observed", "{observed}");
    assert_eq!(observed["liveness_state"], "workflow_task_leased");
    assert_eq!(
        observed["liveness_reason"],
        format!(
            "Signal timeout task {} is leased to a worker.",
            resumed["task_id"].as_str().unwrap()
        )
    );
    assert_eq!(observed["current_waits"]["returned_count"], 0);
    assert_eq!(task_retry::rows(&database,"SELECT COUNT(*) FROM workflow_run_summaries s JOIN workflow_run_waits w ON w.workflow_run_id=s.id AND w.wait_id=s.open_wait_id WHERE s.wait_kind='signal' AND s.resume_source_kind='timer' AND s.resume_source_id=w.resume_source_id AND w.source_status='timed_out'").await,1);
    run_observation_summaries::export_reference_for(
        &database,
        &observed,
        &observed["history"],
        "DW_TEST_TIMED_SIGNAL_REFERENCE",
    )
    .await;
    let completed = finish_task(
        &app,
        resumed,
        json!([{"type":"complete_workflow","result":envelope(Payload::Boolean(false))}]),
    )
    .await;
    assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
    assert_eq!(completed.1["run_status"], "completed");
    second.close().await;
    database.remove().await;
}

#[tokio::test]
async fn immediate_timeout_retains_original_identity_across_reopen() {
    expiry(0).await;
}

#[tokio::test]
async fn positive_timeout_retains_original_deadline_across_reopen() {
    expiry(1).await;
}

async fn observation(app: &Router, run: &Value) -> Value {
    let response = request(
        app,
        "GET",
        &format!(
            "/api/workflows/{}/runs/{}/observation",
            run["workflow_id"].as_str().unwrap(),
            run["run_id"].as_str().unwrap()
        ),
        Value::Null,
    )
    .await;
    assert_eq!(response.0, StatusCode::OK, "{}", response.1);
    response.1
}

async fn receive(app: &Router, run: &Value) -> Value {
    let response = request(
        app,
        "POST",
        &format!(
            "/api/workflows/{}/signal/payload",
            run["workflow_id"].as_str().unwrap()
        ),
        json!({"input":envelope(Payload::Array(vec![Payload::Long(7)]))}),
    )
    .await;
    assert_eq!(response.0, StatusCode::ACCEPTED, "{}", response.1);
    response.1
}

fn close() -> Value {
    json!([{"type":"complete_workflow","result":envelope(Payload::Boolean(true))}])
}

#[tokio::test]
async fn received_input_cancels_only_on_application_with_original_metadata() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    author(&app, "signal-cancel").await;
    let run = start(&app, "signal-cancel").await;
    let task = poll(&app, "signal-cancel", "workflow").await;
    let done = finish_task(
        &app,
        &task,
        json!([{"type":"open_signal_wait","signal_name":"payload","timeout_seconds":60}]),
    )
    .await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    let before = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let scheduled = before[3]["payload"].clone();
    let observed = observation(&app, &run).await;
    assert_eq!(observed["summary_state"], "observed", "{observed}");
    assert_eq!(observed["liveness_state"], "waiting_for_signal");
    assert_eq!(observed["current_waits"]["returned_count"], 1);
    assert_eq!(observed["current_waits"]["waits"][0]["kind"], "signal");
    assert_eq!(
        observed["current_waits"]["waits"][0]["dependency_id"],
        scheduled["timer_id"]
    );
    assert_eq!(
        observed["current_waits"]["waits"][0]["reason"],
        "Waiting for signal payload or timeout after 60 seconds."
    );
    run_observation_summaries::export_reference_for(
        &database,
        &observed,
        &observed["history"],
        "DW_TEST_TIMED_SIGNAL_REFERENCE",
    )
    .await;
    receive(&app, &run).await;
    let received = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    assert_eq!(
        received
            .as_array()
            .unwrap()
            .iter()
            .filter(|event| event["event_type"] == "TimerCancelled")
            .count(),
        0
    );
    drop(app);
    runtime.close().await;
    let cold = database.open().await.unwrap();
    let app = router(cold.clone());
    author(&app, "signal-cold").await;
    let task = poll(&app, "signal-cold", "workflow").await;
    let mut stale = completion(&task, close());
    stale["lease_owner"] = json!("stale-worker");
    let rejected = request(
        &app,
        "POST",
        &format!(
            "/api/worker/workflow-tasks/{}/complete",
            task["task_id"].as_str().unwrap()
        ),
        stale,
    )
    .await;
    assert_eq!(rejected.0, StatusCode::CONFLICT);
    assert_eq!(
        task_retry::rows(
            &database,
            "SELECT COUNT(*) FROM workflow_run_timers WHERE status='pending'"
        )
        .await,
        1
    );
    let done = finish_task(&app, &task, close()).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    assert_eq!(finish_task(&app, &task, close()).await.1["recorded"], false);
    let events = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let kinds: Vec<_> = events
        .as_array()
        .unwrap()
        .iter()
        .map(|e| e["event_type"].as_str().unwrap())
        .collect();
    let cancelled = kinds
        .iter()
        .position(|kind| *kind == "TimerCancelled")
        .unwrap();
    assert_eq!(kinds[cancelled - 1], "MessageCursorAdvanced");
    assert_eq!(kinds[cancelled + 1], "SignalApplied");
    assert!(
        database
            .timer_cancellation_belongs_to(task["task_id"].as_str().unwrap())
            .await,
        "signal timeout cancellation belongs to its worker task without a command relationship"
    );
    for key in [
        "timer_id",
        "sequence",
        "delay_seconds",
        "fire_at",
        "timer_kind",
        "signal_wait_id",
        "signal_name",
    ] {
        assert_eq!(events[cancelled]["payload"][key], scheduled[key], "{key}");
    }
    assert_eq!(
        kinds
            .iter()
            .filter(|kind| **kind == "TimerCancelled")
            .count(),
        1
    );
    assert!(!kinds.contains(&"TimerFired"));
    assert_eq!(task_retry::rows(&database,"SELECT COUNT(*) FROM workflow_run_waits WHERE kind='signal' AND source_status='applied' AND task_status='cancelled' AND task_backed=FALSE").await,1);
    cold.close().await;
    database.remove().await;
}

#[tokio::test]
async fn buffered_input_wins_over_zero_positive_and_null_timeouts() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    author(&app, "signal-buffer").await;
    for timeout in [json!(0), json!(60), Value::Null] {
        let run = start(&app, &format!("buffer-{timeout}")).await;
        let task = poll(&app, "signal-buffer", "workflow").await;
        let received = receive(&app, &run).await;
        let result = finish_task(
            &app,
            &task,
            json!([{"type":"open_signal_wait","signal_name":"payload","timeout_seconds":timeout}]),
        )
        .await;
        assert_eq!(result.0, StatusCode::OK, "{}", result.1);
        let observed = observation(&app, &run).await;
        assert_eq!(observed["current_waits"]["returned_count"], 1);
        assert_eq!(
            observed["current_waits"]["waits"][0]["reason"],
            if timeout.is_null() {
                "Waiting for signal payload.".into()
            } else {
                format!(
                    "Waiting for signal payload or timeout after {} second{}.",
                    timeout.as_i64().unwrap(),
                    if timeout == 1 { "" } else { "s" }
                )
            }
        );
        let task = poll(&app, "signal-buffer", "workflow").await;
        let done = finish_task(&app, &task, close()).await;
        assert_eq!(done.0, StatusCode::OK, "{}", done.1);
        let events = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
        assert!(
            !events
                .as_array()
                .unwrap()
                .iter()
                .any(|e| e["event_type"].as_str().unwrap().starts_with("Timer"))
        );
        let input = events
            .as_array()
            .unwrap()
            .iter()
            .find(|e| e["event_type"] == "SignalReceived")
            .unwrap();
        let opened = events
            .as_array()
            .unwrap()
            .iter()
            .find(|e| e["event_type"] == "SignalWaitOpened")
            .unwrap();
        assert_eq!(
            opened["payload"]["signal_wait_id"],
            input["payload"]["signal_wait_id"]
        );
        assert!(!received.is_null());
    }
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn late_input_buffers_a_new_occurrence_without_rewriting_expired_wait() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    author(&app, "late-signal").await;
    let run = start(&app, "late-signal").await;
    let task = poll(&app, "late-signal", "workflow").await;
    assert_eq!(
        finish_task(
            &app,
            &task,
            json!([{"type":"open_signal_wait","signal_name":"payload","timeout_seconds":0}])
        )
        .await
        .0,
        StatusCode::OK
    );
    let prefix = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    receive(&app, &run).await;
    let task = poll(&app, "late-signal", "workflow").await;
    assert_eq!(
        finish_task(
            &app,
            &task,
            json!([{"type":"open_signal_wait","signal_name":"payload","timeout_seconds":60}])
        )
        .await
        .0,
        StatusCode::OK
    );
    let events = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    assert_eq!(
        &events.as_array().unwrap()[..prefix.as_array().unwrap().len()],
        prefix.as_array().unwrap()
    );
    let waits: Vec<_> = events
        .as_array()
        .unwrap()
        .iter()
        .filter(|e| e["event_type"] == "SignalWaitOpened")
        .collect();
    assert_ne!(
        waits[0]["payload"]["signal_wait_id"],
        waits[1]["payload"]["signal_wait_id"]
    );
    assert_eq!(
        events
            .as_array()
            .unwrap()
            .iter()
            .filter(|e| e["event_type"] == "TimerScheduled")
            .count(),
        1
    );
    let task = poll(&app, "late-signal", "workflow").await;
    let done = finish_task(&app, &task, close()).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn invalid_timeouts_refuse_the_whole_batch_and_preserve_the_lease() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    author(&app, "invalid-signal-timeout").await;
    let run = start(&app, "invalid-signal-timeout").await;
    let task = poll(&app, "invalid-signal-timeout", "workflow").await;
    let prefix = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    for timeout in [
        json!(-1),
        json!(1.5),
        json!(true),
        json!("1"),
        json!([]),
        json!(i64::MAX),
    ] {
        let response=finish_task(&app,&task,json!([{"type":"record_version_marker","change_id":"atomic-timeout","version":1,"min_supported":1,"max_supported":1},{"type":"open_signal_wait","signal_name":"payload","timeout_seconds":timeout}])).await;
        assert_eq!(
            response.0,
            StatusCode::UNPROCESSABLE_ENTITY,
            "{}",
            response.1
        );
        assert_eq!(response.1["reason"], "invalid_signal_timeout");
        assert_eq!(
            run_history(&app, &run["workflow_id"], &run["run_id"]).await,
            prefix
        );
    }
    assert_eq!(finish_task(&app, &task, close()).await.0, StatusCode::OK);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn two_cold_timer_drivers_commit_one_fire_and_one_resume() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    author(&app, "signal-race-author").await;
    let run = start(&app, "signal-race").await;
    let task = poll(&app, "signal-race-author", "workflow").await;
    assert_eq!(
        finish_task(
            &app,
            &task,
            json!([{"type":"open_signal_wait","signal_name":"payload","timeout_seconds":1}])
        )
        .await
        .0,
        StatusCode::OK
    );
    drop(app);
    runtime.close().await;
    let (a, b) = tokio::join!(database.open(), database.open());
    let (a, b) = (a.unwrap(), b.unwrap());
    let (app_a, app_b) = (router(a.clone()), router(b.clone()));
    author(&app_a, "signal-driver-a").await;
    author(&app_b, "signal-driver-b").await;
    let path = "/api/worker/workflow-tasks/poll";
    let (left, right) = tokio::join!(
        request(
            &app_a,
            "POST",
            path,
            json!({"worker_id":"signal-driver-a","task_queue":"test","timeout_seconds":2})
        ),
        request(
            &app_b,
            "POST",
            path,
            json!({"worker_id":"signal-driver-b","task_queue":"test","timeout_seconds":2})
        )
    );
    assert_eq!(left.0, StatusCode::OK, "{}", left.1);
    assert_eq!(right.0, StatusCode::OK, "{}", right.1);
    let tasks: Vec<_> = [&left.1["task"], &right.1["task"]]
        .into_iter()
        .filter(|task| !task.is_null())
        .collect();
    assert_eq!(tasks.len(), 1);
    let events = run_history(&app_a, &run["workflow_id"], &run["run_id"]).await;
    assert_eq!(
        events
            .as_array()
            .unwrap()
            .iter()
            .filter(|e| e["event_type"] == "TimerFired")
            .count(),
        1
    );
    assert_eq!(task_retry::rows(&database,"SELECT COUNT(*) FROM workflow_tasks WHERE task_type='workflow' AND status IN ('ready','leased')").await,1);
    assert_eq!(
        finish_task(&app_b, tasks[0], close()).await.0,
        StatusCode::OK
    );
    a.close().await;
    b.close().await;
    database.remove().await;
}

#[tokio::test]
async fn receipt_before_fire_stays_received_when_application_is_later() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    author(&app, "receipt-before-fire").await;
    let run = start(&app, "receipt-before-fire").await;
    let task = poll(&app, "receipt-before-fire", "workflow").await;
    assert_eq!(
        finish_task(
            &app,
            &task,
            json!([{"type":"open_signal_wait","signal_name":"payload","timeout_seconds":1}])
        )
        .await
        .0,
        StatusCode::OK
    );
    receive(&app, &run).await;
    let input_task = poll(&app, "receipt-before-fire", "workflow").await;
    let deadline = tokio::time::Instant::now() + std::time::Duration::from_secs(3);
    loop {
        let events = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
        if events
            .as_array()
            .unwrap()
            .iter()
            .any(|e| e["event_type"] == "TimerFired")
        {
            break;
        }
        assert!(
            tokio::time::Instant::now() < deadline,
            "original timeout failed to fire"
        );
        tokio::time::sleep(std::time::Duration::from_millis(20)).await;
    }
    assert_eq!(task_retry::rows(&database,"SELECT COUNT(*) FROM workflow_run_waits WHERE kind='signal' AND source_status='received' AND task_status='completed' AND task_backed=FALSE").await,1);
    let done = finish_task(&app, &input_task, close()).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    let events = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    assert_eq!(
        events
            .as_array()
            .unwrap()
            .iter()
            .filter(|e| e["event_type"] == "SignalApplied")
            .count(),
        1
    );
    assert!(
        !events
            .as_array()
            .unwrap()
            .iter()
            .any(|e| e["event_type"] == "TimerCancelled")
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn signal_application_leaves_an_unrelated_timer_pending() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    author(&app, "unrelated-timer").await;
    let run = start(&app, "unrelated-timer").await;
    let task = poll(&app, "unrelated-timer", "workflow").await;
    assert_eq!(finish_task(&app,&task,json!([{"type":"start_timer","delay_seconds":120},{"type":"open_signal_wait","signal_name":"payload","timeout_seconds":60}])).await.0,StatusCode::OK);
    receive(&app, &run).await;
    let task = poll(&app, "unrelated-timer", "workflow").await;
    let done=finish_task(&app,&task,json!([{"type":"record_version_marker","change_id":"signal-applied","version":1,"min_supported":1,"max_supported":1}])).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    assert_eq!(task_retry::rows(&database,"SELECT COUNT(*) FROM workflow_run_timers WHERE status='pending' AND sequence=1 AND delay_seconds=120").await,1);
    assert_eq!(task_retry::rows(&database,"SELECT COUNT(*) FROM workflow_run_timers WHERE status='cancelled' AND sequence=2 AND delay_seconds=60").await,1);
    let events = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    assert_eq!(
        events
            .as_array()
            .unwrap()
            .iter()
            .filter(|e| e["event_type"] == "TimerCancelled")
            .count(),
        1
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn root_cancellation_preserves_original_signal_timeout_metadata() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    author(&app, "cancel-timed-wait").await;
    let run = start(&app, "cancel-timed-wait").await;
    let task = poll(&app, "cancel-timed-wait", "workflow").await;
    assert_eq!(
        finish_task(
            &app,
            &task,
            json!([{"type":"open_signal_wait","signal_name":"payload","timeout_seconds":60}])
        )
        .await
        .0,
        StatusCode::OK
    );
    let before = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let path = "/api/workflows/cancel-timed-wait/cancel";
    assert_eq!(
        request(
            &app,
            "POST",
            path,
            json!({"reason":"cancel original occurrence"})
        )
        .await
        .0,
        StatusCode::OK
    );
    assert_eq!(
        request(
            &app,
            "POST",
            path,
            json!({"reason":"cancel original occurrence"})
        )
        .await
        .0,
        StatusCode::CONFLICT
    );
    let events = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let cancellations: Vec<_> = events
        .as_array()
        .unwrap()
        .iter()
        .filter(|e| e["event_type"] == "TimerCancelled")
        .collect();
    assert_eq!(cancellations.len(), 1);
    for key in [
        "timer_id",
        "sequence",
        "delay_seconds",
        "fire_at",
        "timer_kind",
        "signal_wait_id",
        "signal_name",
    ] {
        assert_eq!(
            cancellations[0]["payload"][key], before[3]["payload"][key],
            "{key}"
        );
    }
    assert_eq!(
        task_retry::rows(
            &database,
            "SELECT COUNT(*) FROM workflow_run_waits WHERE kind='signal' AND status='cancelled'"
        )
        .await,
        1
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn sqlite_projection_failure_rolls_back_immediate_and_positive_scheduling() {
    let database = TestDatabase::Sqlite(tempfile::tempdir().unwrap());
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    author(&app, "atomic-timed-wait").await;
    for seconds in [0, 60] {
        let run = start(&app, &format!("atomic-timed-{seconds}")).await;
        let task = poll(&app, "atomic-timed-wait", "workflow").await;
        let prefix = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
        database.execute("CREATE TRIGGER reject_timed_summary BEFORE INSERT ON workflow_run_summaries BEGIN SELECT RAISE(ABORT, 'timed summary rejected'); END").await;
        let command =
            json!([{"type":"open_signal_wait","signal_name":"payload","timeout_seconds":seconds}]);
        let refused = finish_task(&app, &task, command.clone()).await;
        assert_eq!(refused.0, StatusCode::SERVICE_UNAVAILABLE, "{}", refused.1);
        assert_eq!(
            run_history(&app, &run["workflow_id"], &run["run_id"]).await,
            prefix
        );
        assert_eq!(
            task_retry::rows(
                &database,
                "SELECT COUNT(*) FROM workflow_run_timers WHERE status='pending'"
            )
            .await,
            0
        );
        database.execute("DROP TRIGGER reject_timed_summary").await;
        assert_eq!(finish_task(&app, &task, command).await.0, StatusCode::OK);
        if seconds == 0 {
            let resumed = poll(&app, "atomic-timed-wait", "workflow").await;
            assert_eq!(finish_task(&app, &resumed, close()).await.0, StatusCode::OK);
        }
    }
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn sqlite_projection_failure_rolls_back_signal_application_and_timer_cancellation() {
    let database = TestDatabase::Sqlite(tempfile::tempdir().unwrap());
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    author(&app, "atomic-signal-apply").await;
    let run = start(&app, "atomic-signal-apply").await;
    let task = poll(&app, "atomic-signal-apply", "workflow").await;
    assert_eq!(
        finish_task(
            &app,
            &task,
            json!([{"type":"open_signal_wait","signal_name":"payload","timeout_seconds":60}])
        )
        .await
        .0,
        StatusCode::OK
    );
    receive(&app, &run).await;
    let task = poll(&app, "atomic-signal-apply", "workflow").await;
    let prefix = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    database.execute("CREATE TRIGGER reject_signal_application BEFORE UPDATE ON workflow_run_waits WHEN NEW.source_status='applied' BEGIN SELECT RAISE(ABORT, 'signal projection rejected'); END").await;
    let result = finish_task(&app, &task, close()).await;
    assert_eq!(result.0, StatusCode::SERVICE_UNAVAILABLE, "{}", result.1);
    assert_eq!(
        run_history(&app, &run["workflow_id"], &run["run_id"]).await,
        prefix
    );
    assert_eq!(
        task_retry::rows(
            &database,
            "SELECT COUNT(*) FROM workflow_run_timers WHERE status='pending'"
        )
        .await,
        1
    );
    assert_eq!(
        task_retry::rows(
            &database,
            "SELECT COUNT(*) FROM workflow_signal_records WHERE status='received'"
        )
        .await,
        1
    );
    database
        .execute("DROP TRIGGER reject_signal_application")
        .await;
    assert_eq!(finish_task(&app, &task, close()).await.0, StatusCode::OK);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn missing_or_changed_wait_projection_cannot_gain_summary_authority() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let result=request(&app,"POST","/api/worker/register",json!({"worker_id":"signal-authority","task_queue":"test","runtime":"php",
        "supported_workflow_types":["echo"],"supported_activity_types":[],"workflow_command_contracts":{"echo":{"signals":["payload","unrelated"],
        "signal_contracts":[{"name":"unrelated","parameters":[]},{"name":"payload","parameters":[]}]}}})).await;
    assert_eq!(result.0, StatusCode::CREATED, "{}", result.1);
    for missing in [false, true] {
        let run = start(&app, &format!("timed-authority-{missing}")).await;
        let task = poll(&app, "signal-authority", "workflow").await;
        assert_eq!(
            finish_task(
                &app,
                &task,
                json!([{"type":"open_signal_wait","signal_name":"payload","timeout_seconds":60}])
            )
            .await
            .0,
            StatusCode::OK
        );
        assert_eq!(observation(&app, &run).await["summary_state"], "observed");
        database.execute(if missing {"DELETE FROM workflow_run_waits WHERE workflow_run_id IN (SELECT id FROM workflow_runs WHERE workflow_instance_id='timed-authority-true')"}
            else {"UPDATE workflow_run_waits SET deadline_at=NULL WHERE workflow_run_id IN (SELECT id FROM workflow_runs WHERE workflow_instance_id='timed-authority-false')"}).await;
        let input = request(
            &app,
            "POST",
            &format!(
                "/api/workflows/{}/signal/unrelated",
                run["workflow_id"].as_str().unwrap()
            ),
            json!({"input":envelope(Payload::Array(vec![]))}),
        )
        .await;
        assert_eq!(input.0, StatusCode::ACCEPTED, "{}", input.1);
        let prefix = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
        for _ in 0..2 {
            let observed = observation(&app, &run).await;
            assert_eq!(observed["summary_state"], "unavailable", "{observed}");
            assert!(observed["liveness_state"].is_null());
        }
        assert_eq!(
            run_history(&app, &run["workflow_id"], &run["run_id"]).await,
            prefix
        );
    }
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn named_timeout_wait_keeps_its_existing_admission_gate() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    namespaces::create(&app, "timed-signals").await;
    let result=namespaces::scoped(&app,"timed-signals","POST","/api/worker/register",json!({"worker_id":"named-timer","task_queue":"test","runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":[]})).await;
    assert_eq!(result.0, StatusCode::CREATED, "{}", result.1);
    let run = namespaces::start_scoped(&app, "timed-signals", "named-timer").await;
    let task = namespaces::scoped(
        &app,
        "timed-signals",
        "POST",
        "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"named-timer","task_queue":"test","timeout_seconds":0}),
    )
    .await
    .1["task"]
        .clone();
    let path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        task["task_id"].as_str().unwrap()
    );
    let command = completion(
        &task,
        json!([{"type":"open_signal_wait","signal_name":"payload","timeout_seconds":0}]),
    );
    let refused = namespaces::scoped(&app, "timed-signals", "POST", &path, command).await;
    assert_eq!(refused.0, StatusCode::UNPROCESSABLE_ENTITY);
    assert_eq!(
        refused.1["reason"],
        "development_namespace_command_unqualified"
    );
    assert_eq!(
        task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_run_timers").await,
        0
    );
    assert_eq!(
        task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_run_waits").await,
        0
    );
    assert_eq!(task["run_id"], run["run_id"]);
    runtime.close().await;
    database.remove().await;
}
