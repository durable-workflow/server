use super::*;

pub(super) async fn observe(app: &Router, namespace: &str, run: &Value) -> Value {
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

async fn register(app: &Router, namespace: &str) {
    let response = namespaces::scoped(
        app,
        namespace,
        "POST",
        "/api/worker/register",
        json!({
        "worker_id":"active-summary-worker", "task_queue":"test", "runtime":"php",
        "supported_workflow_types":["echo"], "supported_activity_types":[]}),
    )
    .await;
    assert_eq!(response.0, StatusCode::CREATED, "{}", response.1);
}

async fn claim(app: &Router, namespace: &str) -> (StatusCode, Value) {
    namespaces::scoped(
        app,
        namespace,
        "POST",
        "/api/worker/workflow-tasks/poll",
        json!({
        "worker_id":"active-summary-worker", "task_queue":"test", "timeout_seconds":0}),
    )
    .await
}

#[tokio::test]
async fn run_observation_active_summary_tracks_claim_without_history_and_cold_reads() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    namespaces::create(&app, "active-summary").await;
    let mut originals = Vec::new();
    for namespace in ["default", "active-summary"] {
        register(&app, namespace).await;
        // An explicit run timeout gives the run a deadline the lease below can borrow.
        let (status, run) = namespaces::scoped(&app, namespace, "POST", "/api/workflows",
            json!({"workflow_id":format!("summary-{namespace}"),"workflow_type":"echo","task_queue":"test",
                "input":envelope(Payload::Array(vec![Payload::Long(9007199254740993)])),"run_timeout_seconds":600})).await;
        assert_eq!(status, StatusCode::CREATED, "{run}");
        let ready = observe(&app, namespace, &run).await;
        assert_eq!(ready["summary_state"], "observed", "{ready}");
        assert_eq!(ready["liveness_state"], "workflow_task_ready");
        assert_eq!(ready["history_event_count"], 2);
        assert!(ready["history_size_bytes"].as_u64().unwrap() > 0);
        assert!(ready["duration_ms"].is_null());
        run_observation_summaries::export_reference(&database, &ready, &ready["history"]).await;
        let polled = claim(&app, namespace).await;
        assert_eq!(polled.0, StatusCode::OK, "{}", polled.1);
        let task = polled.1["task"].clone();
        let leased = observe(&app, namespace, &run).await;
        assert_eq!(leased["liveness_state"], "workflow_task_leased");
        assert_eq!(
            leased["liveness_reason"],
            format!(
                "Workflow task {} is leased to a worker.",
                task["task_id"].as_str().unwrap()
            )
        );
        for field in [
            "history_event_count",
            "history_size_bytes",
            "exception_count",
            "status",
        ] {
            assert_eq!(leased[field], ready[field], "{field}");
        }
        // A distinct stored expiry makes the renewal projection observable
        // without a wall-clock sleep or a timing-sensitive assertion.
        database.execute(if namespace == "default" {
            "UPDATE workflow_tasks SET lease_expires_at=(SELECT run_deadline_at FROM workflow_runs WHERE id=workflow_tasks.workflow_run_id) WHERE status='leased' AND namespace='default'"
        } else {
            "UPDATE workflow_tasks SET lease_expires_at=(SELECT run_deadline_at FROM workflow_runs WHERE id=workflow_tasks.workflow_run_id) WHERE status='leased' AND namespace='active-summary'"
        }).await;
        let heartbeat = namespaces::scoped(&app, namespace, "POST", &format!("/api/worker/workflow-tasks/{}/heartbeat", task["task_id"].as_str().unwrap()), json!({
            "lease_owner":task["lease_owner"], "workflow_task_attempt":task["workflow_task_attempt"]})).await;
        assert_eq!(heartbeat.0, StatusCode::OK, "{}", heartbeat.1);
        assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_run_summaries s JOIN workflow_tasks t ON t.id=s.next_task_id WHERE t.status='leased' AND s.next_task_lease_expires_at<>t.lease_expires_at").await, 0);
        assert_eq!(
            observe(&app, namespace, &run).await["liveness_state"],
            "workflow_task_leased"
        );
        let renewed = observe(&app, namespace, &run).await;
        run_observation_summaries::export_reference(&database, &renewed, &renewed["history"]).await;
        originals.push((namespace, run, leased));
    }
    runtime.close().await;
    let cold = database.open().await.unwrap();
    let cold_app = router(cold.clone());
    for (namespace, run, previous) in originals {
        let read = observe(&cold_app, namespace, &run).await;
        for field in [
            "liveness_state",
            "liveness_reason",
            "history_event_count",
            "history_size_bytes",
            "summary_observed_at",
        ] {
            assert_eq!(read[field], previous[field], "{field}");
        }
    }
    cold.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_active_summary_failure_rolls_back_claim_and_missing_reads_stay_missing() {
    let database = TestDatabase::Sqlite(tempfile::tempdir().unwrap());
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "default").await;
    let run = namespaces::start_scoped(&app, "default", "summary-atomic-claim").await;
    let before = observe(&app, "default", &run).await;
    database.execute("CREATE TRIGGER fail_summary_claim BEFORE INSERT ON workflow_run_summaries BEGIN SELECT RAISE(ABORT, 'summary write rejected'); END").await;
    let refused = claim(&app, "default").await;
    assert_eq!(refused.0, StatusCode::SERVICE_UNAVAILABLE, "{}", refused.1);
    assert_eq!(
        observe(&app, "default", &run).await["liveness_state"],
        before["liveness_state"]
    );
    database.execute("DROP TRIGGER fail_summary_claim").await;
    let polled = claim(&app, "default").await;
    assert_eq!(polled.0, StatusCode::OK, "{}", polled.1);
    assert_eq!(polled.1["task"]["workflow_task_attempt"], 1);
    database.execute("DELETE FROM workflow_run_summaries").await;
    for _ in 0..2 {
        let missing = observe(&app, "default", &run).await;
        assert_eq!(missing["summary_state"], "unavailable");
        assert!(missing["history_event_count"].is_null());
        assert!(missing["liveness_state"].is_null());
    }
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_active_summary_keeps_unqualified_transport_state_unavailable() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "default").await;
    let run = namespaces::start_scoped(&app, "default", "summary-transport").await;
    assert_eq!(
        observe(&app, "default", &run).await["liveness_state"],
        "workflow_task_ready"
    );
    database.execute("UPDATE workflow_tasks SET created_at=available_at,last_dispatch_attempt_at=available_at,last_dispatch_error='retained transport failure'").await;
    let claimed = claim(&app, "default").await;
    assert_eq!(claimed.0, StatusCode::OK, "{}", claimed.1);
    assert!(claimed.1["task"].is_object());
    let unknown = observe(&app, "default", &run).await;
    assert_eq!(unknown["summary_state"], "unavailable");
    assert!(unknown["liveness_state"].is_null());
    assert!(unknown["history_event_count"].is_null());
    runtime.close().await;
    database.remove().await;
}
