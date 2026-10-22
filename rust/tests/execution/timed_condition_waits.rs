use super::*;

fn condition(seconds: Value, occurrence: &str) -> Value {
    json!({"type":"open_condition_wait","condition_key":"ready",
        "condition_definition_fingerprint":format!("sha256:{}","a".repeat(64)),
        "condition_wait_occurrence_id":occurrence,"timeout_seconds":seconds})
}

async fn author(app: &Router, namespace: &str, worker: &str) {
    let response=namespaces::scoped(app,namespace,"POST","/api/worker/register",json!({
        "worker_id":worker,"task_queue":"test","runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":[],
        "workflow_command_contracts":{"echo":{"signals":["payload"],"signal_contracts":[{"name":"payload","parameters":[{
            "name":"value","position":0,"type":"int","required":true,"variadic":false,"default_available":false,"allows_null":false}]}]}}
    })).await;
    assert_eq!(response.0, StatusCode::CREATED, "{}", response.1);
}

async fn claim(app: &Router, namespace: &str, worker: &str, timeout: i64) -> Value {
    let response = namespaces::scoped(
        app,
        namespace,
        "POST",
        "/api/worker/workflow-tasks/poll",
        json!({"worker_id":worker,"task_queue":"test","timeout_seconds":timeout}),
    )
    .await;
    assert_eq!(response.0, StatusCode::OK, "{}", response.1);
    response.1["task"].clone()
}

async fn finish(
    app: &Router,
    namespace: &str,
    task: &Value,
    commands: Value,
) -> (StatusCode, Value) {
    namespaces::scoped(
        app,
        namespace,
        "POST",
        &format!(
            "/api/worker/workflow-tasks/{}/complete",
            task["task_id"].as_str().unwrap()
        ),
        completion(task, commands),
    )
    .await
}

async fn read(app: &Router, namespace: &str, run: &Value, suffix: &str) -> Value {
    let response = namespaces::scoped(
        app,
        namespace,
        "GET",
        &format!(
            "/api/workflows/{}/runs/{}/{suffix}",
            run["workflow_id"].as_str().unwrap(),
            run["run_id"].as_str().unwrap()
        ),
        Value::Null,
    )
    .await;
    assert_eq!(response.0, StatusCode::OK, "{}", response.1);
    response.1
}

async fn expiry(seconds: i64) {
    for namespace in ["default", "timed-conditions"] {
        let database = TestDatabase::new().await;
        let first = database.open().await.unwrap();
        let app = router(first.clone());
        if namespace != "default" {
            namespaces::create(&app, namespace).await;
        }
        author(&app, namespace, "condition-author").await;
        let run =
            namespaces::start_scoped(&app, namespace, &format!("condition-{namespace}-{seconds}"))
                .await;
        let original = claim(&app, namespace, "condition-author", 0).await;
        let commands = json!([condition(json!(seconds), "condition:logical:0")]);
        let result = finish(&app, namespace, &original, commands.clone()).await;
        assert_eq!(result.0, StatusCode::OK, "{}", result.1);
        let prefix = read(&app, namespace, &run, "history").await;
        assert_eq!(
            finish(&app, namespace, &original, commands).await.1["recorded"],
            false
        );
        assert_eq!(read(&app, namespace, &run, "history").await, prefix);
        drop(app);
        first.close().await;
        let cold = database.open().await.unwrap();
        let app = router(cold.clone());
        author(&app, namespace, "condition-cold").await;
        let resumed = claim(&app, namespace, "condition-cold", 3).await;
        assert!(
            !resumed.is_null(),
            "original condition timer did not resume within three seconds"
        );
        let history = read(&app, namespace, &run, "history").await;
        let events = history["events"].as_array().unwrap();
        assert_eq!(
            events
                .iter()
                .map(|e| e["event_type"].as_str().unwrap())
                .collect::<Vec<_>>(),
            [
                "StartAccepted",
                "WorkflowStarted",
                "ConditionWaitOpened",
                "TimerScheduled",
                "TimerFired"
            ]
        );
        let opened = &events[2]["payload"];
        let scheduled = &events[3]["payload"];
        let fired = &events[4]["payload"];
        for value in [scheduled, fired] {
            for key in [
                "condition_wait_id",
                "condition_wait_occurrence_id",
                "condition_key",
                "condition_definition_fingerprint",
                "sequence",
            ] {
                assert_eq!(value[key], opened[key], "{key}");
            }
            assert_eq!(value["timer_kind"], "condition_timeout");
            assert_eq!(value["delay_seconds"], seconds);
        }
        assert_eq!(fired["timer_id"], scheduled["timer_id"]);
        assert!(
            chrono::DateTime::parse_from_rfc3339(fired["fired_at"].as_str().unwrap()).unwrap()
                >= chrono::DateTime::parse_from_rfc3339(scheduled["fire_at"].as_str().unwrap())
                    .unwrap()
        );
        assert_eq!(resumed["workflow_wait_kind"], "condition");
        assert_eq!(resumed["open_wait_id"], opened["condition_wait_id"]);
        assert_eq!(resumed["resume_source_kind"], "timer");
        assert_eq!(resumed["resume_source_id"], scheduled["timer_id"]);
        for key in [
            "condition_wait_id",
            "condition_wait_occurrence_id",
            "condition_key",
            "condition_definition_fingerprint",
        ] {
            assert_eq!(resumed[key], opened[key], "{key}");
        }
        let observed = observed(&database, &app, namespace, &run).await;
        assert_eq!(observed["summary_state"], "observed", "{observed}");
        assert_eq!(observed["liveness_state"], "workflow_task_leased");
        assert_eq!(
            observed["liveness_reason"],
            format!(
                "Condition timeout task {} is leased to a worker.",
                resumed["task_id"].as_str().unwrap()
            )
        );
        assert_eq!(observed["current_waits"]["returned_count"], 1);
        let wait = &observed["current_waits"]["waits"][0];
        assert_eq!(wait["kind"], "condition");
        assert_eq!(wait["id"], opened["condition_wait_id"]);
        assert_eq!(wait["reason"], "Waiting to apply condition timeout.");
        assert_eq!(wait["state"], "deadline_elapsed");
        let done = finish(
            &app,
            namespace,
            &resumed,
            json!([{"type":"complete_workflow","result":envelope(Payload::Boolean(false))}]),
        )
        .await;
        assert_eq!(done.0, StatusCode::OK, "{}", done.1);
        assert_eq!(done.1["run_status"], "completed");
        let after = read(&app, namespace, &run, "history").await;
        assert_eq!(after["events"].as_array().unwrap().len(), 6);
        assert_eq!(after["events"][5]["event_type"], "WorkflowCompleted");
        assert_eq!(
            read(&app, namespace, &run, "observation").await["current_waits"]["returned_count"],
            0
        );
        cold.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn immediate_condition_timeout_keeps_open_wait_until_worker_completion() {
    expiry(0).await;
}

#[tokio::test]
async fn positive_condition_timeout_keeps_original_metadata_across_reopen() {
    expiry(1).await;
}

async fn setup(namespace: &str) -> (TestDatabase, Runtime, Router) {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    if namespace != "default" {
        namespaces::create(&app, namespace).await;
    }
    author(&app, namespace, "condition-worker").await;
    (database, runtime, app)
}

async fn open(app: &Router, namespace: &str, name: &str, seconds: i64) -> Value {
    let run = namespaces::start_scoped(app, namespace, name).await;
    let task = claim(app, namespace, "condition-worker", 0).await;
    let done = finish(
        app,
        namespace,
        &task,
        json!([condition(json!(seconds), "logical:0")]),
    )
    .await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    run
}

async fn receive(app: &Router, namespace: &str, run: &Value) {
    let response = namespaces::scoped(
        app,
        namespace,
        "POST",
        &format!(
            "/api/workflows/{}/signal/payload",
            run["workflow_id"].as_str().unwrap()
        ),
        json!({"input":envelope(Payload::Array(vec![Payload::Long(9007199254740993)]))}),
    )
    .await;
    assert_eq!(response.0, StatusCode::ACCEPTED, "{}", response.1);
}

fn close(value: bool) -> Value {
    json!([{"type":"complete_workflow","result":envelope(Payload::Boolean(value))}])
}

async fn observed(database: &TestDatabase, app: &Router, namespace: &str, run: &Value) -> Value {
    let before = chrono::Utc::now().to_rfc3339();
    let result = read(app, namespace, run, "observation").await;
    let after = chrono::Utc::now().to_rfc3339();
    assert_eq!(result["summary_state"], "observed", "{result}");
    let mut snapshot = result.clone();
    snapshot["reference_clock_start"] = json!(before);
    snapshot["reference_clock_end"] = json!(after);
    run_observation_summaries::export_reference_for(
        database,
        &snapshot,
        &result["history"],
        "DW_TEST_TIMED_CONDITION_REFERENCE",
    )
    .await;
    result
}

async fn wait_for_fire(app: &Router, namespace: &str, run: &Value) {
    let deadline = tokio::time::Instant::now() + std::time::Duration::from_secs(3);
    loop {
        let history = read(app, namespace, run, "history").await;
        if history["events"]
            .as_array()
            .unwrap()
            .iter()
            .any(|event| event["event_type"] == "TimerFired")
        {
            return;
        }
        assert!(
            tokio::time::Instant::now() < deadline,
            "original condition timer did not fire"
        );
        tokio::time::sleep(std::time::Duration::from_millis(20)).await;
    }
}

#[tokio::test]
async fn signal_application_satisfies_then_cancels_the_original_physical_timeout() {
    for namespace in ["default", "condition-input"] {
        let (database, runtime, app) = setup(namespace).await;
        let run = open(&app, namespace, &format!("condition-input-{namespace}"), 60).await;
        let prefix = read(&app, namespace, &run, "history").await;
        let original = &prefix["events"][2]["payload"];
        let timer = &prefix["events"][3]["payload"];
        let initial = observed(&database, &app, namespace, &run).await;
        assert_eq!(initial["liveness_state"], "waiting_for_condition");
        assert_eq!(initial["current_waits"]["returned_count"], 1);
        assert_eq!(
            initial["current_waits"]["waits"][0]["reason"],
            "Waiting for condition or timeout after 60 seconds."
        );
        receive(&app, namespace, &run).await;
        assert_eq!(
            observed(&database, &app, namespace, &run).await["current_waits"]["returned_count"],
            1
        );
        assert_eq!(
            task_retry::rows(
                &database,
                "SELECT COUNT(*) FROM workflow_run_timers WHERE status='pending'"
            )
            .await,
            1
        );
        let task = claim(&app, namespace, "condition-worker", 0).await;
        observed(&database, &app, namespace, &run).await;
        let mut stale = completion(&task, close(true));
        stale["lease_owner"] = json!("stale-condition-worker");
        let path = format!(
            "/api/worker/workflow-tasks/{}/complete",
            task["task_id"].as_str().unwrap()
        );
        assert_eq!(
            namespaces::scoped(&app, namespace, "POST", &path, stale)
                .await
                .0,
            StatusCode::CONFLICT
        );
        let done = finish(&app, namespace, &task, close(true)).await;
        assert_eq!(done.0, StatusCode::OK, "{}", done.1);
        assert_eq!(
            finish(&app, namespace, &task, close(true)).await.1["recorded"],
            false
        );
        let history = read(&app, namespace, &run, "history").await;
        let events = history["events"].as_array().unwrap();
        let kinds: Vec<_> = events
            .iter()
            .map(|event| event["event_type"].as_str().unwrap())
            .collect();
        assert_eq!(
            kinds,
            [
                "StartAccepted",
                "WorkflowStarted",
                "ConditionWaitOpened",
                "TimerScheduled",
                "SignalReceived",
                "MessageCursorAdvanced",
                "SignalApplied",
                "ConditionWaitSatisfied",
                "TimerCancelled",
                "WorkflowCompleted"
            ]
        );
        for key in [
            "condition_wait_id",
            "condition_wait_occurrence_id",
            "condition_key",
            "condition_definition_fingerprint",
            "sequence",
            "timeout_seconds",
        ] {
            assert_eq!(events[7]["payload"][key], original[key], "{key}");
        }
        assert_eq!(events[7]["payload"]["timer_id"], timer["timer_id"]);
        for key in [
            "condition_wait_id",
            "condition_wait_occurrence_id",
            "condition_key",
            "condition_definition_fingerprint",
            "sequence",
            "delay_seconds",
            "fire_at",
            "timer_id",
            "timer_kind",
        ] {
            assert_eq!(events[8]["payload"][key], timer[key], "{key}");
        }
        assert!(
            database
                .timer_cancellation_belongs_to(task["task_id"].as_str().unwrap())
                .await
        );
        assert_eq!(task_retry::rows(&database,"SELECT COUNT(*) FROM workflow_run_waits WHERE kind='condition' AND source_status='satisfied' AND task_status='cancelled' AND resume_source_kind='external_input' AND resume_source_id IS NULL").await,1);
        observed(&database, &app, namespace, &run).await;
        runtime.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn buffered_condition_input_keeps_zero_and_positive_timer_semantics_distinct() {
    for namespace in ["default", "condition-buffer"] {
        for seconds in [0, 60] {
            let (database, runtime, app) = setup(namespace).await;
            let run = namespaces::start_scoped(
                &app,
                namespace,
                &format!("condition-buffer-{namespace}-{seconds}"),
            )
            .await;
            let task = claim(&app, namespace, "condition-worker", 0).await;
            receive(&app, namespace, &run).await;
            assert_eq!(
                finish(
                    &app,
                    namespace,
                    &task,
                    json!([condition(json!(seconds), "logical:0")])
                )
                .await
                .0,
                StatusCode::OK
            );
            let history = read(&app, namespace, &run, "history").await;
            assert_eq!(
                history["events"]
                    .as_array()
                    .unwrap()
                    .iter()
                    .filter(|e| e["event_type"] == "TimerScheduled")
                    .count(),
                1
            );
            observed(&database, &app, namespace, &run).await;
            let task = claim(&app, namespace, "condition-worker", 0).await;
            assert_eq!(
                task["resume_source_kind"],
                if seconds == 0 {
                    "timer"
                } else {
                    "workflow_signal"
                }
            );
            assert_eq!(
                finish(&app, namespace, &task, close(seconds > 0)).await.0,
                StatusCode::OK
            );
            // The zero timeout fired before the condition took the buffered
            // signal. Closing the run rejects that unapplied signal, as
            // Server 2.10.0's PendingSignalCloser does.
            assert_eq!(
                task_retry::rows(
                    &database,
                    "SELECT COUNT(*) FROM workflow_signal_records WHERE status='received'"
                )
                .await,
                0
            );
            assert_eq!(
                task_retry::rows(
                    &database,
                    "SELECT COUNT(*) FROM workflow_signal_records WHERE status='rejected' AND rejection_reason='run_closed_before_signal_applied'"
                )
                .await,
                if seconds == 0 { 1 } else { 0 }
            );
            runtime.close().await;
            database.remove().await;
        }
    }
}

#[tokio::test]
async fn signal_reopen_keeps_occurrence_but_authors_new_physical_timer_and_deadline() {
    for namespace in ["default", "condition-reopen"] {
        let (database, runtime, app) = setup(namespace).await;
        let run = open(
            &app,
            namespace,
            &format!("condition-reopen-{namespace}"),
            60,
        )
        .await;
        let prefix = read(&app, namespace, &run, "history").await;
        receive(&app, namespace, &run).await;
        let task = claim(&app, namespace, "condition-worker", 0).await;
        let done = finish(
            &app,
            namespace,
            &task,
            json!([condition(json!(60), "logical:0")]),
        )
        .await;
        assert_eq!(done.0, StatusCode::OK, "{}", done.1);
        let reopened = read(&app, namespace, &run, "history").await;
        let events = reopened["events"].as_array().unwrap();
        assert_eq!(&events[..4], prefix["events"].as_array().unwrap());
        assert_eq!(events.len(), 11);
        assert_eq!(events[7]["event_type"], "ConditionWaitSatisfied");
        assert_eq!(events[8]["event_type"], "TimerCancelled");
        let opens: Vec<_> = events
            .iter()
            .filter(|e| e["event_type"] == "ConditionWaitOpened")
            .collect();
        let timers: Vec<_> = events
            .iter()
            .filter(|e| e["event_type"] == "TimerScheduled")
            .collect();
        assert_ne!(
            opens[0]["payload"]["condition_wait_id"],
            opens[1]["payload"]["condition_wait_id"]
        );
        assert_eq!(
            opens[0]["payload"]["condition_wait_occurrence_id"],
            opens[1]["payload"]["condition_wait_occurrence_id"]
        );
        assert_eq!(opens[1]["payload"]["sequence"], 2);
        assert_ne!(
            timers[0]["payload"]["timer_id"],
            timers[1]["payload"]["timer_id"]
        );
        assert_eq!(
            events[8]["payload"]["fire_at"],
            timers[0]["payload"]["fire_at"]
        );
        assert_eq!(
            task_retry::rows(
                &database,
                "SELECT COUNT(*) FROM workflow_run_timers WHERE sequence=1 AND status='cancelled'"
            )
            .await,
            1
        );
        assert_eq!(
            task_retry::rows(
                &database,
                "SELECT COUNT(*) FROM workflow_run_timers WHERE sequence=2 AND status='pending'"
            )
            .await,
            1
        );
        let current = observed(&database, &app, namespace, &run).await;
        assert_eq!(current["current_waits"]["returned_count"], 1);
        assert_eq!(
            current["current_waits"]["waits"][0]["id"],
            opens[1]["payload"]["condition_wait_id"]
        );
        receive(&app, namespace, &run).await;
        let task = claim(&app, namespace, "condition-worker", 0).await;
        assert_eq!(
            finish(&app, namespace, &task, close(true)).await.0,
            StatusCode::OK
        );
        runtime.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn input_after_fire_advances_a_later_wait_and_preserves_the_expired_occurrence() {
    for namespace in ["default", "condition-late"] {
        let (database, runtime, app) = setup(namespace).await;
        let run = open(&app, namespace, &format!("condition-late-{namespace}"), 0).await;
        let prefix = read(&app, namespace, &run, "history").await;
        receive(&app, namespace, &run).await;
        let task = claim(&app, namespace, "condition-worker", 0).await;
        assert_eq!(task["resume_source_kind"], "timer");
        let done = finish(
            &app,
            namespace,
            &task,
            json!([condition(json!(60), "logical:1")]),
        )
        .await;
        assert_eq!(done.0, StatusCode::OK, "{}", done.1);
        let history = read(&app, namespace, &run, "history").await;
        let events = history["events"].as_array().unwrap();
        assert_eq!(&events[..5], prefix["events"].as_array().unwrap());
        let opens: Vec<_> = events
            .iter()
            .filter(|e| e["event_type"] == "ConditionWaitOpened")
            .collect();
        assert_eq!(opens.len(), 2);
        assert_ne!(
            opens[0]["payload"]["condition_wait_id"],
            opens[1]["payload"]["condition_wait_id"]
        );
        assert_eq!(
            observed(&database, &app, namespace, &run).await["current_waits"]["returned_count"],
            2
        );
        let task = claim(&app, namespace, "condition-worker", 0).await;
        assert_eq!(task["resume_source_kind"], "workflow_signal");
        assert_eq!(
            finish(&app, namespace, &task, close(true)).await.0,
            StatusCode::OK
        );
        let final_history = read(&app, namespace, &run, "history").await;
        let satisfied: Vec<_> = final_history["events"]
            .as_array()
            .unwrap()
            .iter()
            .filter(|e| e["event_type"] == "ConditionWaitSatisfied")
            .collect();
        assert_eq!(satisfied.len(), 1);
        assert_eq!(
            satisfied[0]["payload"]["condition_wait_id"],
            opens[1]["payload"]["condition_wait_id"]
        );
        runtime.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn claimed_input_after_timeout_fire_does_not_satisfy_or_cancel_that_wait() {
    for namespace in ["default", "condition-claimed"] {
        let (database, runtime, app) = setup(namespace).await;
        let run = open(
            &app,
            namespace,
            &format!("condition-claimed-{namespace}"),
            1,
        )
        .await;
        receive(&app, namespace, &run).await;
        let task = claim(&app, namespace, "condition-worker", 0).await;
        assert_eq!(task["resume_source_kind"], "workflow_signal");
        wait_for_fire(&app, namespace, &run).await;
        observed(&database, &app, namespace, &run).await;
        let done = finish(&app, namespace, &task, close(false)).await;
        assert_eq!(done.0, StatusCode::OK, "{}", done.1);
        let history = read(&app, namespace, &run, "history").await;
        let events = history["events"].as_array().unwrap();
        assert!(events.iter().any(|e| e["event_type"] == "SignalApplied"));
        assert!(!events.iter().any(|e| matches!(
            e["event_type"].as_str(),
            Some("ConditionWaitSatisfied" | "TimerCancelled")
        )));
        runtime.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn competing_cold_nodes_fire_and_resume_each_namespace_once() {
    for namespace in ["default", "condition-race"] {
        let (database, runtime, app) = setup(namespace).await;
        let run = open(&app, namespace, &format!("condition-race-{namespace}"), 1).await;
        drop(app);
        runtime.close().await;
        let (a, b) = tokio::join!(database.open(), database.open());
        let (a, b) = (a.unwrap(), b.unwrap());
        let (app_a, app_b) = (router(a.clone()), router(b.clone()));
        author(&app_a, namespace, "condition-a").await;
        author(&app_b, namespace, "condition-b").await;
        let (left, right) = tokio::join!(
            claim(&app_a, namespace, "condition-a", 2),
            claim(&app_b, namespace, "condition-b", 2)
        );
        let tasks: Vec<_> = [&left, &right]
            .into_iter()
            .filter(|task| !task.is_null())
            .collect();
        assert_eq!(tasks.len(), 1);
        let history = read(&app_a, namespace, &run, "history").await;
        assert_eq!(
            history["events"]
                .as_array()
                .unwrap()
                .iter()
                .filter(|e| e["event_type"] == "TimerFired")
                .count(),
            1
        );
        assert_eq!(
            finish(&app_b, namespace, tasks[0], close(false)).await.0,
            StatusCode::OK
        );
        a.close().await;
        b.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn invalid_condition_timeouts_preserve_the_original_batch_and_lease() {
    let (database, runtime, app) = setup("default").await;
    let run = namespaces::start_scoped(&app, "default", "invalid-condition-timeout").await;
    let task = claim(&app, "default", "condition-worker", 0).await;
    let prefix = read(&app, "default", &run, "history").await;
    for seconds in [
        json!(-1),
        json!(1.5),
        json!(true),
        json!("1"),
        json!([]),
        json!(i64::MAX),
    ] {
        let result=finish(&app,"default",&task,json!([{"type":"record_version_marker","change_id":"condition-atomic","version":1,"min_supported":1,"max_supported":1},condition(seconds,"logical:0")])).await;
        assert_eq!(result.0, StatusCode::UNPROCESSABLE_ENTITY, "{}", result.1);
        assert_eq!(result.1["reason"], "invalid_condition_timeout");
        assert_eq!(read(&app, "default", &run, "history").await, prefix);
    }
    let mut untimed = condition(Value::Null, "logical:0");
    untimed.as_object_mut().unwrap().remove("timeout_seconds");
    assert_eq!(
        finish(&app, "default", &task, json!([untimed])).await.0,
        StatusCode::OK
    );
    assert_eq!(
        task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_run_timers").await,
        0
    );
    receive(&app, "default", &run).await;
    let task = claim(&app, "default", "condition-worker", 0).await;
    assert_eq!(
        finish(&app, "default", &task, close(true)).await.0,
        StatusCode::OK
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn root_cancellation_keeps_the_original_condition_identity_and_deadline() {
    for namespace in ["default", "condition-cancel"] {
        let (database, runtime, app) = setup(namespace).await;
        let run = open(
            &app,
            namespace,
            &format!("condition-cancel-{namespace}"),
            60,
        )
        .await;
        let before = read(&app, namespace, &run, "history").await;
        let path = format!(
            "/api/workflows/{}/cancel",
            run["workflow_id"].as_str().unwrap()
        );
        assert_eq!(
            namespaces::scoped(
                &app,
                namespace,
                "POST",
                &path,
                json!({"reason":"cancel physical condition"})
            )
            .await
            .0,
            StatusCode::OK
        );
        assert_eq!(
            namespaces::scoped(
                &app,
                namespace,
                "POST",
                &path,
                json!({"reason":"cancel physical condition"})
            )
            .await
            .0,
            StatusCode::CONFLICT
        );
        let history = read(&app, namespace, &run, "history").await;
        let cancelled: Vec<_> = history["events"]
            .as_array()
            .unwrap()
            .iter()
            .filter(|e| e["event_type"] == "TimerCancelled")
            .collect();
        assert_eq!(cancelled.len(), 1);
        for key in [
            "condition_wait_id",
            "condition_wait_occurrence_id",
            "condition_key",
            "condition_definition_fingerprint",
            "sequence",
            "delay_seconds",
            "fire_at",
            "timer_id",
            "timer_kind",
        ] {
            assert_eq!(
                cancelled[0]["payload"][key], before["events"][3]["payload"][key],
                "{key}"
            );
        }
        assert_eq!(task_retry::rows(&database,"SELECT COUNT(*) FROM workflow_run_waits WHERE kind='condition' AND status='cancelled'").await,1);
        runtime.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn missing_or_changed_condition_projection_stays_unavailable_without_read_repair() {
    for missing in [false, true] {
        let (database, runtime, app) = setup("default").await;
        let run = open(&app, "default", "condition-authority", 60).await;
        database
            .execute(if missing {
                "DELETE FROM workflow_run_waits"
            } else {
                "UPDATE workflow_run_waits SET deadline_at=NULL"
            })
            .await;
        receive(&app, "default", &run).await;
        let prefix = read(&app, "default", &run, "history").await;
        for _ in 0..2 {
            let observation = read(&app, "default", &run, "observation").await;
            assert_eq!(observation["summary_state"], "unavailable", "{observation}");
            assert!(observation["liveness_state"].is_null());
        }
        assert_eq!(read(&app, "default", &run, "history").await, prefix);
        runtime.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn sqlite_condition_summary_failure_rolls_back_zero_and_positive_scheduling() {
    let database = TestDatabase::Sqlite(tempfile::tempdir().unwrap());
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    author(&app, "default", "condition-worker").await;
    for seconds in [0, 60] {
        let run =
            namespaces::start_scoped(&app, "default", &format!("condition-atomic-{seconds}")).await;
        let task = claim(&app, "default", "condition-worker", 0).await;
        let prefix = read(&app, "default", &run, "history").await;
        database.execute("CREATE TRIGGER reject_condition_summary BEFORE INSERT ON workflow_run_summaries BEGIN SELECT RAISE(ABORT, 'condition summary rejected'); END").await;
        let commands = json!([condition(json!(seconds), "logical:0")]);
        assert_eq!(
            finish(&app, "default", &task, commands.clone()).await.0,
            StatusCode::SERVICE_UNAVAILABLE
        );
        assert_eq!(read(&app, "default", &run, "history").await, prefix);
        assert_eq!(
            task_retry::rows(
                &database,
                "SELECT COUNT(*) FROM workflow_run_timers WHERE status='pending'"
            )
            .await,
            0
        );
        database
            .execute("DROP TRIGGER reject_condition_summary")
            .await;
        assert_eq!(
            finish(&app, "default", &task, commands).await.0,
            StatusCode::OK
        );
        if seconds == 0 {
            let resumed = claim(&app, "default", "condition-worker", 0).await;
            assert_eq!(
                finish(&app, "default", &resumed, close(false)).await.0,
                StatusCode::OK
            );
        }
    }
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn sqlite_condition_application_failure_keeps_signal_and_pending_timer_original() {
    let database = TestDatabase::Sqlite(tempfile::tempdir().unwrap());
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    author(&app, "default", "condition-worker").await;
    let run = open(&app, "default", "condition-apply-atomic", 60).await;
    receive(&app, "default", &run).await;
    let task = claim(&app, "default", "condition-worker", 0).await;
    let prefix = read(&app, "default", &run, "history").await;
    database.execute("CREATE TRIGGER reject_condition_resolution BEFORE UPDATE ON workflow_run_waits WHEN NEW.source_status='satisfied' BEGIN SELECT RAISE(ABORT, 'condition projection rejected'); END").await;
    assert_eq!(
        finish(&app, "default", &task, close(true)).await.0,
        StatusCode::SERVICE_UNAVAILABLE
    );
    assert_eq!(read(&app, "default", &run, "history").await, prefix);
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
        .execute("DROP TRIGGER reject_condition_resolution")
        .await;
    assert_eq!(
        finish(&app, "default", &task, close(true)).await.0,
        StatusCode::OK
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn sqlite_firing_failure_cannot_commit_a_partial_timeout_or_resume() {
    let database = TestDatabase::Sqlite(tempfile::tempdir().unwrap());
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    author(&app, "default", "condition-worker").await;
    let run = open(&app, "default", "condition-fire-atomic", 1).await;
    let prefix = read(&app, "default", &run, "history").await;
    database.execute("CREATE TRIGGER reject_condition_fire BEFORE INSERT ON workflow_history_events WHEN NEW.event_type='TimerFired' BEGIN SELECT RAISE(ABORT, 'condition firing rejected'); END").await;
    assert!(
        claim(&app, "default", "condition-worker", 2)
            .await
            .is_null()
    );
    assert_eq!(read(&app, "default", &run, "history").await, prefix);
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
            "SELECT COUNT(*) FROM workflow_tasks WHERE task_type='workflow' AND status='ready'"
        )
        .await,
        0
    );
    database.execute("DROP TRIGGER reject_condition_fire").await;
    drop(app);
    runtime.close().await;
    let cold = database.open().await.unwrap();
    let app = router(cold.clone());
    author(&app, "default", "condition-worker").await;
    let resumed = claim(&app, "default", "condition-worker", 3).await;
    assert!(!resumed.is_null());
    assert_eq!(
        resumed["open_wait_id"],
        prefix["events"][2]["payload"]["condition_wait_id"]
    );
    assert_eq!(
        resumed["timer_id"],
        prefix["events"][3]["payload"]["timer_id"]
    );
    assert_eq!(
        finish(&app, "default", &resumed, close(false)).await.0,
        StatusCode::OK
    );
    cold.close().await;
    database.remove().await;
}
