use super::*;

async fn observation(app: &Router, namespace: &str, run: &Value) -> Value {
    let response = namespaces::scoped(
        app,
        namespace,
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

async fn history(app: &Router, namespace: &str, run: &Value) -> Value {
    let response = namespaces::scoped(
        app,
        namespace,
        "GET",
        &format!(
            "/api/workflows/{}/runs/{}/history",
            run["workflow_id"].as_str().unwrap(),
            run["run_id"].as_str().unwrap()
        ),
        Value::Null,
    )
    .await;
    assert_eq!(response.0, StatusCode::OK, "{}", response.1);
    response.1
}

fn assert_terminal(summary: &Value, history: &Value, status: &str, exceptions: u64) {
    assert_eq!(summary["summary_state"], "observed", "{summary}");
    assert_eq!(summary["status"], status);
    assert_eq!(summary["liveness_state"], "closed");
    assert_eq!(
        summary["liveness_reason"],
        format!(
            "Run closed as {}.",
            summary["closed_reason"].as_str().unwrap()
        )
    );
    assert_eq!(
        summary["history_event_count"],
        history["events"].as_array().unwrap().len()
    );
    assert!(summary["history_size_bytes"].as_u64().unwrap() > 0);
    assert_eq!(summary["history_fan_out"], 0);
    assert_eq!(summary["exception_count"], exceptions);
    assert!(summary["duration_ms"].as_u64().is_some());
    assert_eq!(summary["summary_observed_at"], summary["closed_at"]);
    // Optional local qualification output is synthetic test data. The published
    // PHP counter independently checks these actual native histories.
    if let Ok(path) = std::env::var("DW_TEST_SUMMARY_REFERENCE") {
        use std::io::Write;
        let mut file = std::fs::OpenOptions::new()
            .create(true)
            .append(true)
            .open(path)
            .unwrap();
        writeln!(file, "{}", json!({"summary":summary,"history":history})).unwrap();
    }
}

#[tokio::test]
async fn run_observation_terminal_summaries_survive_cold_nodes_and_duplicate_commands() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    namespaces::create(&app, "summary-observation").await;
    let mut originals = Vec::new();
    for namespace in ["default", "summary-observation"] {
        let result = namespaces::scoped(
            &app,
            namespace,
            "POST",
            "/api/worker/register",
            json!({
            "worker_id":"summary-worker","task_queue":"test","runtime":"php",
            "supported_workflow_types":["echo"],"supported_activity_types":[]}),
        )
        .await;
        assert_eq!(result.0, StatusCode::CREATED);
        for cancelled in [false, true] {
            let run = namespaces::start_scoped(
                &app,
                namespace,
                &format!("summary-{namespace}-{cancelled}"),
            )
            .await;
            assert_eq!(
                observation(&app, namespace, &run).await["summary_state"],
                "unavailable"
            );
            let (path, body) = if cancelled {
                (
                    format!(
                        "/api/workflows/{}/runs/{}/cancel",
                        run["workflow_id"].as_str().unwrap(),
                        run["run_id"].as_str().unwrap()
                    ),
                    json!({"reason":"cancel 日本語 λ\u{2028}"}),
                )
            } else {
                let task = namespaces::scoped(
                    &app,
                    namespace,
                    "POST",
                    "/api/worker/workflow-tasks/poll",
                    json!({"worker_id":"summary-worker","task_queue":"test","timeout_seconds":0}),
                )
                .await
                .1["task"]
                    .clone();
                (
                    format!(
                        "/api/worker/workflow-tasks/{}/complete",
                        task["task_id"].as_str().unwrap()
                    ),
                    completion(
                        &task,
                        json!([{"type":"complete_workflow","result":envelope(Payload::String("result 日本語 λ".into()))}]),
                    ),
                )
            };
            let response = namespaces::scoped(&app, namespace, "POST", &path, body.clone()).await;
            assert_eq!(response.0, StatusCode::OK, "{}", response.1);
            let summary = observation(&app, namespace, &run).await;
            let events = history(&app, namespace, &run).await;
            assert_terminal(
                &summary,
                &events,
                if cancelled { "cancelled" } else { "completed" },
                u64::from(cancelled),
            );
            // Completion replays its receipt. A fresh cancel request against a
            // closed run is explicitly refused; neither may change counters.
            assert_eq!(
                namespaces::scoped(&app, namespace, "POST", &path, body)
                    .await
                    .0,
                if cancelled {
                    StatusCode::CONFLICT
                } else {
                    StatusCode::OK
                }
            );
            assert_eq!(history(&app, namespace, &run).await, events);
            let repeated = observation(&app, namespace, &run).await;
            for field in [
                "history_event_count",
                "history_size_bytes",
                "exception_count",
                "duration_ms",
                "summary_observed_at",
            ] {
                assert_eq!(repeated[field], summary[field], "{field}");
            }
            let other = if namespace == "default" {
                "summary-observation"
            } else {
                "default"
            };
            assert_eq!(
                namespaces::scoped(
                    &app,
                    other,
                    "GET",
                    &format!(
                        "/api/workflows/{}/runs/{}/observation",
                        run["workflow_id"].as_str().unwrap(),
                        run["run_id"].as_str().unwrap()
                    ),
                    Value::Null
                )
                .await
                .0,
                StatusCode::NOT_FOUND
            );
            originals.push((namespace, run, summary, events));
        }
    }
    runtime.close().await;
    let (a, b) = tokio::join!(database.open(), database.open());
    let (a, b) = (a.unwrap(), b.unwrap());
    for (namespace, run, summary, events) in originals {
        let cold = observation(&router(a.clone()), namespace, &run).await;
        for field in [
            "history_event_count",
            "history_size_bytes",
            "exception_count",
            "duration_ms",
            "summary_observed_at",
        ] {
            assert_eq!(cold[field], summary[field]);
        }
        assert_eq!(history(&router(b.clone()), namespace, &run).await, events);
    }
    a.close().await;
    b.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_terminal_summary_continuation_counts_all_history_pages() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "summary-continuation", json!(["echo"]), json!([])).await;
    let original = start(&app, "summary-continuation").await;
    let task = poll(&app, "summary-continuation", "workflow").await;
    let mut commands:Vec<Value> = (0..20).map(|index| json!({"type":"record_version_marker","change_id":format!("summary-{index}"),"version":1,"min_supported":1,"max_supported":1})).collect();
    commands.push(json!({"type":"continue_as_new"}));
    let response = finish_task(&app, &task, json!(commands)).await;
    assert_eq!(response.0, StatusCode::OK, "{}", response.1);
    let summary = observation(&app, "default", &original).await;
    let events = history(&app, "default", &original).await;
    assert_terminal(&summary, &events, "completed", 0);
    assert_eq!(summary["closed_reason"], "continued");
    assert_eq!(summary["history_event_count"], 23);
    assert_eq!(summary["is_current_run"], false);
    let next = poll(&app, "summary-continuation", "workflow").await;
    assert_ne!(next["run_id"], original["run_id"]);
    let successor = json!({"workflow_id":original["workflow_id"],"run_id":next["run_id"]});
    assert_eq!(
        observation(&app, "default", &successor).await["summary_state"],
        "unavailable"
    );
    assert_eq!(
        finish_task(
            &app,
            &next,
            json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])
        )
        .await
        .0,
        StatusCode::OK
    );
    assert_terminal(
        &observation(&app, "default", &successor).await,
        &history(&app, "default", &successor).await,
        "completed",
        0,
    );
    assert_eq!(history(&app, "default", &original).await, events);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_terminal_summary_write_failure_rolls_back_completion() {
    let database = TestDatabase::Sqlite(tempfile::tempdir().unwrap());
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "summary-rollback", json!(["echo"]), json!([])).await;
    let run = start(&app, "summary-rollback").await;
    let task = poll(&app, "summary-rollback", "workflow").await;
    let before = history(&app, "default", &run).await;
    database.execute("CREATE TRIGGER reject_summary BEFORE INSERT ON workflow_run_summaries BEGIN SELECT RAISE(ABORT, 'summary write failed'); END").await;
    let commands = json!([{"type":"complete_workflow","result":envelope(Payload::Null)}]);
    assert_eq!(
        finish_task(&app, &task, commands.clone()).await.0,
        StatusCode::SERVICE_UNAVAILABLE
    );
    assert_eq!(history(&app, "default", &run).await, before);
    assert_eq!(
        observation(&app, "default", &run).await["summary_state"],
        "unavailable"
    );
    database.execute("DROP TRIGGER reject_summary").await;
    assert_eq!(finish_task(&app, &task, commands).await.0, StatusCode::OK);
    assert_terminal(
        &observation(&app, "default", &run).await,
        &history(&app, "default", &run).await,
        "completed",
        0,
    );
    database.execute("DELETE FROM workflow_run_summaries").await;
    let before = history(&app, "default", &run).await;
    assert_eq!(
        observation(&app, "default", &run).await["summary_state"],
        "unavailable"
    );
    assert_eq!(history(&app, "default", &run).await, before);
    runtime.close().await;
    database.remove().await;
}
