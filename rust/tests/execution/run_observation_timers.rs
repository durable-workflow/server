use super::*;

async fn schedule(app: &Router, namespace: &str, workflow: &str, delay: i64) -> (Value, Value) {
    let registered = namespaces::scoped(
        app,
        namespace,
        "POST",
        "/api/worker/register",
        json!({"worker_id":"timer-observer","task_queue":"test","runtime":"php",
        "supported_workflow_types":["echo"],"supported_activity_types":[]}),
    )
    .await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    let run = namespaces::start_scoped(app, namespace, workflow).await;
    let task = claim(app, namespace).await;
    let path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        task["task_id"].as_str().unwrap()
    );
    let body = completion(&task, json!([{"type":"start_timer","delay_seconds":delay}]));
    let scheduled = namespaces::scoped(app, namespace, "POST", &path, body.clone()).await;
    assert_eq!(scheduled.0, StatusCode::OK, "{}", scheduled.1);
    let duplicate = namespaces::scoped(app, namespace, "POST", &path, body).await;
    assert_eq!(duplicate.0, StatusCode::OK, "{}", duplicate.1);
    assert_eq!(duplicate.1["recorded"], false);
    let observation = observe(app, namespace, &run).await;
    assert_eq!(observation["summary_state"], "observed", "{observation}");
    assert_eq!(observation["liveness_state"], "timer_scheduled");
    assert_eq!(observation["history_event_count"], 3);
    assert_eq!(
        observation["current_waits"]["returned_count"], 1,
        "{observation}"
    );
    let wait = observation["current_waits"]["waits"][0].clone();
    assert_eq!(wait["kind"], "timer");
    assert_eq!(wait["state"], "planned");
    assert_eq!(wait["reason"], "Waiting for timer.");
    assert_eq!(wait["task_metadata_state"], "available");
    assert_eq!(wait["task_status"], "ready");
    assert_eq!(wait["dependency_type"], "timer");
    assert_eq!(
        wait["id"],
        format!("timer:{}", wait["dependency_id"].as_str().unwrap())
    );
    assert!(wait["next_scheduled_resume_at"].is_string());
    for field in [
        "deadline_at",
        "attempt_id",
        "attempt_number",
        "attempt_limit",
        "unavailable_reason",
    ] {
        assert!(wait[field].is_null(), "{field}: {wait}");
    }
    (run, wait)
}

async fn claim(app: &Router, namespace: &str) -> Value {
    let polled = namespaces::scoped(
        app,
        namespace,
        "POST",
        "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"timer-observer","task_queue":"test","timeout_seconds":0}),
    )
    .await;
    assert_eq!(polled.0, StatusCode::OK, "{}", polled.1);
    polled.1["task"].clone()
}

async fn history(app: &Router, namespace: &str, run: &Value) -> Value {
    let path = format!(
        "/api/workflows/{}/runs/{}/history",
        run["workflow_id"].as_str().unwrap(),
        run["run_id"].as_str().unwrap()
    );
    let read = namespaces::scoped(app, namespace, "GET", &path, Value::Null).await;
    assert_eq!(read.0, StatusCode::OK, "{}", read.1);
    read.1
}

async fn observe(app: &Router, namespace: &str, run: &Value) -> Value {
    let path = format!(
        "/api/workflows/{}/runs/{}/observation",
        run["workflow_id"].as_str().unwrap(),
        run["run_id"].as_str().unwrap()
    );
    let read = namespaces::scoped(app, namespace, "GET", &path, Value::Null).await;
    assert_eq!(read.0, StatusCode::OK, "{}", read.1);
    read.1
}

async fn wait_for_fire(
    app: &Router,
    namespace: &str,
    run: &Value,
    deadline: tokio::time::Instant,
) -> Value {
    loop {
        let read = history(app, namespace, run).await;
        if read["events"]
            .as_array()
            .unwrap()
            .iter()
            .any(|event| event["event_type"] == "TimerFired")
        {
            return read;
        }
        assert!(
            tokio::time::Instant::now() < deadline,
            "timer did not fire: {read}"
        );
        tokio::time::sleep(std::time::Duration::from_millis(20)).await;
    }
}

#[tokio::test]
async fn run_observation_timers_follow_real_fire_across_cold_nodes_and_namespaces() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    // Initial schema setup is outside the execution budget. Admission and
    // every cold restart below retain this one original deadline.
    let deadline = tokio::time::Instant::now() + std::time::Duration::from_secs(35);
    namespaces::create(&app, "timer-observation").await;
    let mut originals = Vec::new();
    for namespace in ["default", "timer-observation"] {
        let (run, wait) = schedule(&app, namespace, &format!("timer-{namespace}"), 10).await;
        let before = history(&app, namespace, &run).await;
        let scheduled = observe(&app, namespace, &run).await;
        run_observation_summaries::export_reference(&database, &scheduled, &before).await;
        assert_eq!(
            observe(&app, namespace, &run).await["current_waits"]["waits"][0],
            wait
        );
        assert_eq!(history(&app, namespace, &run).await, before);
        let foreign = if namespace == "default" {
            "timer-observation"
        } else {
            "default"
        };
        let path = format!(
            "/api/workflows/{}/runs/{}/observation",
            run["workflow_id"].as_str().unwrap(),
            run["run_id"].as_str().unwrap()
        );
        assert_eq!(
            namespaces::scoped(&app, foreign, "GET", &path, Value::Null)
                .await
                .0,
            StatusCode::NOT_FOUND
        );
        originals.push((namespace, run, wait, before));
    }
    runtime.close().await;
    let (a, b) = tokio::join!(database.open(), database.open());
    let (a, b) = (a.unwrap(), b.unwrap());
    let (app_a, app_b) = (router(a.clone()), router(b.clone()));
    for (namespace, run, wait, _) in &originals {
        assert_eq!(
            observe(&app_b, namespace, run).await["liveness_state"],
            "timer_scheduled"
        );
        assert_eq!(
            observe(&app_b, namespace, run).await["current_waits"]["waits"][0],
            wait.clone()
        );
    }
    for (namespace, run, wait, before) in originals {
        let fired = wait_for_fire(&app_a, namespace, &run, deadline).await;
        let resumed_summary = observe(&app_b, namespace, &run).await;
        assert_eq!(resumed_summary["liveness_state"], "workflow_task_ready");
        assert_eq!(resumed_summary["history_event_count"], 4);
        run_observation_summaries::export_reference(&database, &resumed_summary, &fired).await;
        assert_eq!(
            &fired["events"].as_array().unwrap()[..3],
            before["events"].as_array().unwrap().as_slice()
        );
        assert_eq!(
            fired["events"][3]["payload"]["timer_id"],
            wait["dependency_id"]
        );
        assert_eq!(
            observe(&app_b, namespace, &run).await["current_waits"]["returned_count"],
            0
        );
        assert_eq!(history(&app_b, namespace, &run).await, fired);
        let resumed = claim(&app_a, namespace).await;
        assert_eq!(resumed["run_id"], run["run_id"]);
        assert_eq!(
            observe(&app_b, namespace, &run).await["liveness_state"],
            "workflow_task_leased"
        );
        assert!(claim(&app_b, namespace).await.is_null());
        let path = format!(
            "/api/worker/workflow-tasks/{}/complete",
            resumed["task_id"].as_str().unwrap()
        );
        let completed = namespaces::scoped(
            &app_a,
            namespace,
            "POST",
            &path,
            completion(
                &resumed,
                json!([{"type":"complete_workflow","result":envelope(Payload::Null)}]),
            ),
        )
        .await;
        assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
        assert_eq!(
            observe(&app_b, namespace, &run).await["current_waits"]["returned_count"],
            0
        );
    }
    a.close().await;
    b.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_timers_schedule_and_projection_commit_together() {
    let database = TestDatabase::Sqlite(tempfile::tempdir().unwrap());
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "timer-insert-rollback", json!(["echo"]), json!([])).await;
    let run = start(&app, "timer-insert-rollback").await;
    let task = poll(&app, "timer-insert-rollback", "workflow").await;
    let before = history(&app, "default", &run).await;
    database.execute("CREATE TRIGGER reject_timer_wait_insert BEFORE INSERT ON workflow_run_waits BEGIN SELECT RAISE(ABORT, 'timer projection insert failed'); END").await;
    let commands = json!([{"type":"start_timer","delay_seconds":120}]);
    let refused = finish_task(&app, &task, commands.clone()).await;
    assert_eq!(refused.0, StatusCode::SERVICE_UNAVAILABLE, "{}", refused.1);
    assert_eq!(history(&app, "default", &run).await, before);
    assert_eq!(
        observe(&app, "default", &run).await["current_waits"]["returned_count"],
        0
    );
    database
        .execute("DROP TRIGGER reject_timer_wait_insert")
        .await;
    assert_eq!(finish_task(&app, &task, commands).await.0, StatusCode::OK);
    assert_eq!(
        history(&app, "default", &run).await["events"]
            .as_array()
            .unwrap()
            .len(),
        3
    );
    assert_eq!(
        observe(&app, "default", &run).await["current_waits"]["returned_count"],
        1
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_timer_summary_failure_rolls_back_schedule() {
    let database = TestDatabase::Sqlite(tempfile::tempdir().unwrap());
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "timer-summary-rollback", json!(["echo"]), json!([])).await;
    let run = start(&app, "timer-summary-rollback").await;
    let task = poll(&app, "timer-summary-rollback", "workflow").await;
    let before = history(&app, "default", &run).await;
    database.execute("CREATE TRIGGER reject_timer_summary BEFORE INSERT ON workflow_run_summaries BEGIN SELECT RAISE(ABORT, 'timer summary rejected'); END").await;
    let commands = json!([{"type":"start_timer","delay_seconds":120}]);
    assert_eq!(
        finish_task(&app, &task, commands.clone()).await.0,
        StatusCode::SERVICE_UNAVAILABLE
    );
    assert_eq!(history(&app, "default", &run).await, before);
    assert_eq!(
        task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_run_timers").await,
        0
    );
    assert_eq!(
        task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_run_waits").await,
        0
    );
    assert_eq!(
        observe(&app, "default", &run).await["liveness_state"],
        "workflow_task_leased"
    );
    database.execute("DROP TRIGGER reject_timer_summary").await;
    assert_eq!(finish_task(&app, &task, commands).await.0, StatusCode::OK);
    assert_eq!(
        observe(&app, "default", &run).await["liveness_state"],
        "timer_scheduled"
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_timers_do_not_recreate_a_missing_projection() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let deadline = tokio::time::Instant::now() + std::time::Duration::from_secs(15);
    let (run, _) = schedule(&app, "default", "timer-missing", 2).await;
    database.execute("DELETE FROM workflow_run_waits").await;
    let before = history(&app, "default", &run).await;
    let read = observe(&app, "default", &run).await;
    assert_eq!(read["current_waits"]["state"], "unavailable");
    assert_eq!(history(&app, "default", &run).await, before);
    wait_for_fire(&app, "default", &run, deadline).await;
    let read = observe(&app, "default", &run).await;
    assert_eq!(read["current_waits"]["returned_count"], 0);
    assert_eq!(read["current_waits"]["state"], "unavailable");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_timer_summary_refresh_requires_retained_matching_wait() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    // Runs started before any worker declared a contract: their signals stay
    // pending behind the timer without a task of their own. (A declared
    // signal of a durable-contract run gets one.)
    let runs = [
        start(&app, "timer-summary-authority-false").await,
        start(&app, "timer-summary-authority-true").await,
    ];
    let registered = request(
        &app,
        "POST",
        "/api/worker/register",
        json!({
            "worker_id":"timer-summary-authority","task_queue":"test","runtime":"php",
            "supported_workflow_types":["echo"],"supported_activity_types":[],
            "workflow_command_contracts":{"echo":{"signals":["payload"],"signal_contracts":[{
                "name":"payload","parameters":[{"name":"value","position":0,"type":"array",
                "required":true,"variadic":false,"default_available":false,"allows_null":false}]}]}}
        }),
    )
    .await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    for (missing, run) in [false, true].into_iter().zip(runs) {
        let workflow = format!("timer-summary-authority-{missing}");
        let task = poll(&app, "timer-summary-authority", "workflow").await;
        assert_eq!(
            finish_task(
                &app,
                &task,
                json!([{"type":"start_timer","delay_seconds":120}])
            )
            .await
            .0,
            StatusCode::OK
        );
        let path = format!(
            "/api/workflows/{workflow}/runs/{}/signal/payload",
            run["run_id"].as_str().unwrap()
        );
        let input = json!({"input":envelope(Payload::Array(vec![Payload::Array(vec![])]))});
        assert_eq!(
            request(&app, "POST", &path, input.clone()).await.0,
            StatusCode::ACCEPTED
        );
        let valid = observe(&app, "default", &run).await;
        assert_eq!(valid["liveness_state"], "timer_scheduled", "{valid}");
        run_observation_summaries::export_reference(&database, &valid, &valid["history"]).await;
        database.execute(if missing {
            "DELETE FROM workflow_run_waits WHERE workflow_run_id IN (SELECT id FROM workflow_runs WHERE workflow_instance_id='timer-summary-authority-true')"
        } else {
            "UPDATE workflow_run_waits SET deadline_at=NULL WHERE workflow_run_id IN (SELECT id FROM workflow_runs WHERE workflow_instance_id='timer-summary-authority-false')"
        }).await;
        assert_eq!(
            request(&app, "POST", &path, input).await.0,
            StatusCode::ACCEPTED
        );
        let before = history(&app, "default", &run).await;
        for _ in 0..2 {
            let unknown = observe(&app, "default", &run).await;
            assert_eq!(unknown["summary_state"], "unavailable");
            assert!(unknown["liveness_state"].is_null());
            assert!(unknown["history_event_count"].is_null());
        }
        assert_eq!(history(&app, "default", &run).await, before);
    }
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_timers_cancel_atomically_with_projection_failure() {
    let database = TestDatabase::Sqlite(tempfile::tempdir().unwrap());
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (run, wait) = schedule(&app, "default", "timer-cancel", 120).await;
    let before = history(&app, "default", &run).await;
    database.execute("CREATE TRIGGER reject_timer_wait BEFORE UPDATE ON workflow_run_waits BEGIN SELECT RAISE(ABORT, 'timer projection write failed'); END").await;
    let path = format!(
        "/api/workflows/timer-cancel/runs/{}/cancel",
        run["run_id"].as_str().unwrap()
    );
    let body = json!({"reason":"stop timer λ"});
    let failed = request(&app, "POST", &path, body.clone()).await;
    assert_eq!(failed.0, StatusCode::SERVICE_UNAVAILABLE, "{}", failed.1);
    assert_eq!(history(&app, "default", &run).await, before);
    assert_eq!(
        observe(&app, "default", &run).await["current_waits"]["waits"][0],
        wait
    );
    database.execute("DROP TRIGGER reject_timer_wait").await;
    let cancelled = request(&app, "POST", &path, body.clone()).await;
    assert_eq!(cancelled.0, StatusCode::OK, "{}", cancelled.1);
    let repeated = request(&app, "POST", &path, body).await;
    assert_eq!(repeated.0, StatusCode::CONFLICT, "{}", repeated.1);
    assert_eq!(repeated.1["reason"], "run_not_active");
    let after = history(&app, "default", &run).await;
    assert_eq!(
        after["events"]
            .as_array()
            .unwrap()
            .iter()
            .filter(|e| e["event_type"] == "TimerCancelled")
            .count(),
        1
    );
    assert_eq!(
        observe(&app, "default", &run).await["current_waits"]["returned_count"],
        0
    );
    if let TestDatabase::Sqlite(dir) = &database {
        let pool = sqlx::SqlitePool::connect(&format!(
            "sqlite://{}",
            dir.path().join("runtime.sqlite").display()
        ))
        .await
        .unwrap();
        let row: (String, String, String) = sqlx::query_as(
            "SELECT status,source_status,task_status FROM workflow_run_waits WHERE kind='timer'",
        )
        .fetch_one(&pool)
        .await
        .unwrap();
        assert_eq!(
            row,
            ("cancelled".into(), "cancelled".into(), "cancelled".into())
        );
        pool.close().await;
    }
    runtime.close().await;
    database.remove().await;
}

/// The workflow task a fired timer resumes carries
/// `WorkflowTaskPayload::forTimerResolution`'s fields, as published PHP's does.
#[tokio::test]
async fn a_fired_timer_resumes_with_the_timer_resolution_payload() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let deadline = tokio::time::Instant::now() + std::time::Duration::from_secs(20);
    let (run, wait) = schedule(&app, "default", "timer-resume-payload", 1).await;
    let fired = wait_for_fire(&app, "default", &run, deadline).await;
    let event = fired["events"].as_array().unwrap().iter().find(|event| event["event_type"] == "TimerFired").unwrap().clone();
    let timer_id = wait["dependency_id"].as_str().unwrap().to_owned();
    let run_id = run["run_id"].as_str().unwrap();
    let sql = |cast: &str| format!("SELECT id,{cast} FROM workflow_tasks WHERE workflow_run_id='{run_id}' AND task_type='workflow' AND status='ready'");
    let rows = text_rows(&database, &sql("payload"), &sql("payload::text"), &sql("CAST(payload AS CHAR)")).await;
    assert_eq!(rows.len(), 1, "{rows:?}");
    let payload: Value = serde_json::from_str(&rows[0].1).unwrap();
    assert_eq!(payload, json!({"workflow_wait_kind":"timer","open_wait_id":format!("timer:{timer_id}"),
        "resume_source_kind":"timer","resume_source_id":timer_id,"timer_id":timer_id,
        "workflow_sequence":event["payload"]["sequence"],"workflow_event_type":"TimerFired"}));
    runtime.close().await;
    database.remove().await;
}
