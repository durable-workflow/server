use super::*;

pub(super) async fn register_signal_worker(app: &Router, namespace: &str) {
    let result = namespaces::scoped(app, namespace, "POST", "/api/worker/register", json!({
        "worker_id":"signal-observer","task_queue":"test","runtime":"php",
        "supported_workflow_types":["echo"],"supported_activity_types":[],
        "workflow_command_contracts":{"echo":{"signals":["payload"],"signal_contracts":[{
            "name":"payload","parameters":[{"name":"value","position":0,"type":"array",
                "required":true,"variadic":false,"default_available":false,"allows_null":false}]}]}}
    })).await;
    assert_eq!(result.0, StatusCode::CREATED, "{}", result.1);
}

pub(super) async fn claim(app: &Router, namespace: &str) -> Value {
    claim_with_timeout(app, namespace, 0).await
}

pub(super) async fn claim_with_timeout(app: &Router, namespace: &str, timeout: i64) -> Value {
    let result = namespaces::scoped(
        app,
        namespace,
        "POST",
        "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"signal-observer","task_queue":"test","timeout_seconds":timeout}),
    )
    .await;
    assert_eq!(result.0, StatusCode::OK, "{}", result.1);
    result.1["task"].clone()
}

pub(super) async fn finish(
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

pub(super) async fn read(app: &Router, namespace: &str, run: &Value, suffix: &str) -> Value {
    let result = namespaces::scoped(
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
    assert_eq!(result.0, StatusCode::OK, "{}", result.1);
    result.1
}

pub(super) async fn signal(app: &Router, namespace: &str, run: &Value) -> (StatusCode, Value) {
    namespaces::scoped(
        app,
        namespace,
        "POST",
        &format!(
            "/api/workflows/{}/runs/{}/signal/payload",
            run["workflow_id"].as_str().unwrap(),
            run["run_id"].as_str().unwrap()
        ),
        json!({"input":envelope(Payload::Array(vec![signal_value()]))}),
    )
    .await
}

pub(super) fn signal_value() -> Payload {
    Payload::Map(std::collections::BTreeMap::from([
        ("count".into(), Payload::Long(9007199254740993)),
        ("message".into(), Payload::String("signal 日本語 λ".into())),
        ("empty".into(), Payload::Null),
        ("enabled".into(), Payload::Boolean(false)),
    ]))
}

fn open_commands() -> Value {
    json!([{"type":"open_signal_wait","signal_name":"payload"}])
}

fn close_commands() -> Value {
    json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])
}

async fn stored_wait_count(database: &TestDatabase) -> i64 {
    let query = "SELECT COUNT(*) FROM workflow_run_waits";
    match database {
        TestDatabase::Sqlite(dir) => {
            let pool = sqlx::SqlitePool::connect(&format!(
                "sqlite://{}",
                dir.path().join("runtime.sqlite").display()
            ))
            .await
            .unwrap();
            let count = sqlx::query_scalar(query).fetch_one(&pool).await.unwrap();
            pool.close().await;
            count
        }
        TestDatabase::Postgres { options, .. } => {
            let pool = sqlx::postgres::PgPoolOptions::new()
                .connect_with(options.as_ref().clone())
                .await
                .unwrap();
            let count = sqlx::query_scalar(query).fetch_one(&pool).await.unwrap();
            pool.close().await;
            count
        }
        TestDatabase::MySql { options, .. } => {
            let pool = sqlx::mysql::MySqlPoolOptions::new()
                .connect_with(options.as_ref().clone())
                .await
                .unwrap();
            let count = sqlx::query_scalar(query).fetch_one(&pool).await.unwrap();
            pool.close().await;
            count
        }
    }
}

#[tokio::test]
async fn run_observation_signals_preserve_receipt_and_application_across_cold_nodes() {
    signals_across_cold_nodes("default").await;
}

#[tokio::test]
async fn run_observation_signals_named_namespace_recovers_original_waits() {
    signals_across_cold_nodes("signal-observation").await;
}

async fn signals_across_cold_nodes(namespace: &str) {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let mut originals = Vec::new();
    if namespace != "default" {
        namespaces::create(&app, namespace).await;
    }
    register_signal_worker(&app, namespace).await;
    for buffered in [false, true] {
        let run =
            namespaces::start_scoped(&app, namespace, &format!("signal-{namespace}-{buffered}"))
                .await;
        let task = claim(&app, namespace).await;
        if buffered {
            assert_eq!(signal(&app, namespace, &run).await.0, StatusCode::ACCEPTED);
        }
        let mut commands = open_commands();
        if buffered {
            commands[0]["timeout_seconds"] = Value::Null;
        }
        let opened = finish(&app, namespace, &task, commands.clone()).await;
        assert_eq!(opened.0, StatusCode::OK, "{}", opened.1);
        let duplicate = finish(&app, namespace, &task, commands).await;
        assert_eq!(duplicate.0, StatusCode::OK);
        assert_eq!(duplicate.1["recorded"], false);
        let history = read(&app, namespace, &run, "history").await;
        let observation = read(&app, namespace, &run, "observation").await;
        assert_eq!(observation["summary_state"], "observed", "{observation}");
        assert_eq!(
            observation["liveness_state"],
            if buffered {
                "workflow_task_ready"
            } else {
                "waiting_for_signal"
            }
        );
        if !buffered {
            assert_eq!(
                observation["liveness_reason"],
                "Waiting for signal payload."
            );
        }
        run_observation_summaries::export_reference(&database, &observation, &history).await;
        assert_eq!(
            observation["current_waits"]["returned_count"], 1,
            "{observation}"
        );
        let wait = observation["current_waits"]["waits"][0].clone();
        let event = history["events"]
            .as_array()
            .unwrap()
            .iter()
            .find(|event| event["event_type"] == "SignalWaitOpened")
            .unwrap();
        assert_eq!(wait["id"], event["payload"]["signal_wait_id"]);
        assert_eq!(wait["kind"], "signal");
        assert_eq!(wait["target_name"], "payload");
        assert_eq!(wait["reason"], "Waiting for signal payload.");
        assert_eq!(wait["state"], "resume_time_unknown");
        assert_eq!(wait["task_metadata_state"], "unavailable");
        for field in [
            "dependency_id",
            "dependency_type",
            "task_id",
            "next_scheduled_resume_at",
            "deadline_at",
            "unavailable_reason",
        ] {
            assert!(wait[field].is_null(), "{field}: {wait}");
        }
        assert_eq!(read(&app, namespace, &run, "history").await, history);
        originals.push((namespace, buffered, run, wait));
    }
    runtime.close().await;
    let (a, b) = tokio::join!(database.open(), database.open());
    let (a, b) = (a.unwrap(), b.unwrap());
    let (app_a, app_b) = (router(a.clone()), router(b.clone()));
    // Drain each namespace's already buffered task before receiving the other signal.
    for (namespace, buffered, run, wait) in originals.into_iter().rev() {
        assert_eq!(
            read(&app_b, namespace, &run, "observation").await["current_waits"]["waits"][0],
            wait
        );
        if !buffered {
            let received = signal(&app_a, namespace, &run).await;
            assert_eq!(received.0, StatusCode::ACCEPTED, "{}", received.1);
            let summary = read(&app_b, namespace, &run, "observation").await;
            assert_eq!(summary["liveness_state"], "workflow_task_ready");
            run_observation_summaries::export_reference(&database, &summary, &summary["history"])
                .await;
            // Receipt resolves the visible signal wait before the worker applies it.
            assert_eq!(
                read(&app_b, namespace, &run, "observation").await["current_waits"]["returned_count"],
                0
            );
        }
        let task = claim(&app_b, namespace).await;
        assert_eq!(task["run_id"], run["run_id"]);
        assert_eq!(
            read(&app_a, namespace, &run, "observation").await["liveness_state"],
            "workflow_task_leased"
        );
        let done = finish(&app_a, namespace, &task, close_commands()).await;
        assert_eq!(done.0, StatusCode::OK, "{}", done.1);
        let duplicate = finish(&app_b, namespace, &task, close_commands()).await;
        assert_eq!(duplicate.0, StatusCode::OK);
        assert_eq!(duplicate.1["recorded"], false);
        let history = read(&app_a, namespace, &run, "history").await;
        assert_eq!(
            history["events"]
                .as_array()
                .unwrap()
                .iter()
                .filter(|e| e["event_type"] == "SignalApplied")
                .count(),
            1
        );
        let events = history["events"].as_array().unwrap();
        let received = &events
            .iter()
            .find(|event| event["event_type"] == "SignalReceived")
            .unwrap()["payload"];
        let applied = &events
            .iter()
            .find(|event| event["event_type"] == "SignalApplied")
            .unwrap()["payload"];
        for payload in [received, applied] {
            assert_eq!(payload["signal_wait_id"], wait["id"]);
        }
        for field in ["signal_id", "workflow_command_id"] {
            assert_eq!(applied[field], received[field]);
        }
        assert_eq!(
            ValueCodec::new()
                .unwrap()
                .decode(applied["value"]["blob"].as_str().unwrap())
                .unwrap(),
            signal_value()
        );
        assert_eq!(
            read(&app_b, namespace, &run, "observation").await["current_waits"]["returned_count"],
            0
        );
        assert_eq!(read(&app_b, namespace, &run, "history").await, history);
        run_observation_summaries::check_completed(&database, &app_b, namespace, &run, 0, 0).await;
    }
    a.close().await;
    b.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_signals_named_namespace_retains_batched_admission_gate() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    namespaces::create(&app, "signal-observation").await;
    register_signal_worker(&app, "signal-observation").await;
    let run = namespaces::start_scoped(&app, "signal-observation", "signal-named-refusal").await;
    let task = claim(&app, "signal-observation").await;
    let before = read(&app, "signal-observation", &run, "history").await;
    let refused = finish(
        &app,
        "signal-observation",
        &task,
        json!([
            {"type":"open_signal_wait","signal_name":"payload"},
            {"type":"open_signal_wait","signal_name":"payload"}
        ]),
    )
    .await;
    assert_eq!(refused.0, StatusCode::UNPROCESSABLE_ENTITY);
    assert_eq!(
        refused.1["reason"],
        "development_namespace_command_unqualified"
    );
    assert_eq!(
        read(&app, "signal-observation", &run, "history").await,
        before
    );
    assert_eq!(stored_wait_count(&database).await, 0);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_signals_named_namespace_cannot_consume_another_namespace() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    for namespace in ["signal-owner", "signal-other"] {
        namespaces::create(&app, namespace).await;
        register_signal_worker(&app, namespace).await;
    }
    let run = namespaces::start_scoped(&app, "signal-owner", "isolated-signal-wait").await;
    let task = claim(&app, "signal-owner").await;
    assert_eq!(
        finish(&app, "signal-owner", &task, open_commands()).await.0,
        StatusCode::OK
    );
    let history = read(&app, "signal-owner", &run, "history").await;
    let wait =
        read(&app, "signal-owner", &run, "observation").await["current_waits"]["waits"][0].clone();

    assert_eq!(
        signal(&app, "signal-other", &run).await.0,
        StatusCode::NOT_FOUND
    );
    assert_eq!(
        finish(&app, "signal-other", &task, open_commands()).await.0,
        StatusCode::NOT_FOUND
    );
    let wrong_read = namespaces::scoped(
        &app,
        "signal-other",
        "GET",
        &format!(
            "/api/workflows/{}/runs/{}/observation",
            run["workflow_id"].as_str().unwrap(),
            run["run_id"].as_str().unwrap()
        ),
        Value::Null,
    )
    .await;
    assert_eq!(wrong_read.0, StatusCode::NOT_FOUND);
    assert!(claim(&app, "signal-other").await.is_null());
    assert_eq!(read(&app, "signal-owner", &run, "history").await, history);
    assert_eq!(
        read(&app, "signal-owner", &run, "observation").await["current_waits"]["waits"][0],
        wait
    );

    assert_eq!(
        signal(&app, "signal-owner", &run).await.0,
        StatusCode::ACCEPTED
    );
    let resumed = claim(&app, "signal-owner").await;
    assert_eq!(resumed["run_id"], run["run_id"]);
    assert_eq!(
        finish(&app, "signal-owner", &resumed, close_commands())
            .await
            .0,
        StatusCode::OK
    );
    run_observation_summaries::check_completed(&database, &app, "signal-owner", &run, 0, 0).await;
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_signals_projection_failure_rolls_back_open_and_receipt() {
    let database = TestDatabase::Sqlite(tempfile::tempdir().unwrap());
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register_signal_worker(&app, "default").await;
    let run = start(&app, "signal-rollback").await;
    let task = claim(&app, "default").await;
    let before = read(&app, "default", &run, "history").await;
    database.execute("CREATE TRIGGER reject_signal_wait_insert BEFORE INSERT ON workflow_run_waits BEGIN SELECT RAISE(ABORT, 'signal projection insert failed'); END").await;
    assert_eq!(
        finish(&app, "default", &task, open_commands()).await.0,
        StatusCode::SERVICE_UNAVAILABLE
    );
    assert_eq!(read(&app, "default", &run, "history").await, before);
    database
        .execute("DROP TRIGGER reject_signal_wait_insert")
        .await;
    assert_eq!(
        finish(&app, "default", &task, open_commands()).await.0,
        StatusCode::OK
    );
    let before = read(&app, "default", &run, "history").await;
    database.execute("CREATE TRIGGER reject_signal_wait_update BEFORE UPDATE ON workflow_run_waits BEGIN SELECT RAISE(ABORT, 'signal projection update failed'); END").await;
    assert_eq!(
        signal(&app, "default", &run).await.0,
        StatusCode::SERVICE_UNAVAILABLE
    );
    assert_eq!(read(&app, "default", &run, "history").await, before);
    assert_eq!(
        read(&app, "default", &run, "observation").await["current_waits"]["returned_count"],
        1
    );
    database
        .execute("DROP TRIGGER reject_signal_wait_update")
        .await;
    assert_eq!(signal(&app, "default", &run).await.0, StatusCode::ACCEPTED);
    let task = claim(&app, "default").await;
    assert_eq!(
        finish(&app, "default", &task, close_commands()).await.0,
        StatusCode::OK
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_signals_missing_projection_is_not_recreated() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register_signal_worker(&app, "default").await;
    let run = start(&app, "signal-missing").await;
    let task = claim(&app, "default").await;
    assert_eq!(
        finish(&app, "default", &task, open_commands()).await.0,
        StatusCode::OK
    );
    assert_eq!(
        read(&app, "default", &run, "observation").await["current_waits"]["returned_count"],
        1
    );
    database.execute("DELETE FROM workflow_run_waits").await;
    assert_eq!(signal(&app, "default", &run).await.0, StatusCode::ACCEPTED);
    assert_eq!(stored_wait_count(&database).await, 0);
    let task = claim(&app, "default").await;
    // Open another ordinary wait while applying the signal to the missing one.
    assert_eq!(
        finish(&app, "default", &task, open_commands()).await.0,
        StatusCode::OK
    );
    assert_eq!(
        read(&app, "default", &run, "observation").await["current_waits"]["returned_count"],
        1
    );
    assert_eq!(stored_wait_count(&database).await, 1);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_signals_cancellation_closes_the_stored_wait() {
    let database = TestDatabase::Sqlite(tempfile::tempdir().unwrap());
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register_signal_worker(&app, "default").await;
    let run = start(&app, "signal-cancel").await;
    let task = claim(&app, "default").await;
    assert_eq!(
        finish(&app, "default", &task, open_commands()).await.0,
        StatusCode::OK
    );
    let cancelled = request(
        &app,
        "POST",
        &format!(
            "/api/workflows/signal-cancel/runs/{}/cancel",
            run["run_id"].as_str().unwrap()
        ),
        json!({"reason":"stop signal"}),
    )
    .await;
    assert_eq!(cancelled.0, StatusCode::OK, "{}", cancelled.1);
    if let TestDatabase::Sqlite(dir) = &database {
        let pool = sqlx::SqlitePool::connect(&format!(
            "sqlite://{}",
            dir.path().join("runtime.sqlite").display()
        ))
        .await
        .unwrap();
        let row: (String, String, String) = sqlx::query_as(
            "SELECT status,source_status,summary FROM workflow_run_waits WHERE kind='signal'",
        )
        .fetch_one(&pool)
        .await
        .unwrap();
        assert_eq!(
            row,
            (
                "cancelled".into(),
                "cancelled".into(),
                "Signal wait ended when the run was cancelled.".into()
            )
        );
        pool.close().await;
    }
    assert_eq!(
        read(&app, "default", &run, "observation").await["current_waits"]["returned_count"],
        0
    );
    runtime.close().await;
    database.remove().await;
}
