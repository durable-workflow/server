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
        let run = namespaces::start_scoped(&app, namespace, &format!("summary-{namespace}")).await;
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
async fn run_observation_active_summary_classifies_task_transport_as_published_php_does() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    // The started run declares a signal, so a signal can project it again.
    let registered = namespaces::scoped(
        &app,
        "default",
        "POST",
        "/api/worker/register",
        json!({
        "worker_id":"active-summary-worker", "task_queue":"test", "runtime":"php",
        "supported_workflow_types":["echo"], "supported_activity_types":[],
        "workflow_command_contracts":{"echo":{"signals":["payload"],"signal_contracts":[{"name":"payload","parameters":[{"name":"value","position":0,"type":"array","required":true,"variadic":false,"default_available":false,"allows_null":false}]}]}}}),
    )
    .await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    let run = namespaces::start_scoped(&app, "default", "summary-transport").await;
    assert_eq!(
        observe(&app, "default", &run).await["liveness_state"],
        "workflow_task_ready"
    );
    // A signal projects the run again while its workflow task stays ready.
    let signal = || async {
        let response = namespaces::scoped(
            &app,
            "default",
            "POST",
            "/api/workflows/summary-transport/signal/payload",
            json!({"input":envelope(Payload::Array(vec![Payload::Array(vec![])]))}),
        )
        .await;
        assert!(response.0.is_success(), "{}", response.1);
    };
    // A dispatch attempt after the last dispatch failed: the run needs repair.
    database.execute("UPDATE workflow_tasks SET last_dispatched_at=NULL,last_dispatch_error=' rejected by the queue '").await;
    signal().await;
    let failed = observe(&app, "default", &run).await;
    assert_eq!(failed["liveness_state"], "repair_needed", "{failed}");
    let reason = failed["liveness_reason"].as_str().unwrap();
    assert!(
        reason.starts_with("Workflow task ")
            && reason.contains(" could not be dispatched at 20")
            && reason.ends_with(". rejected by the queue"),
        "{reason}"
    );
    // A worker that could not claim the task names the task type.
    database.execute("UPDATE workflow_tasks SET last_dispatched_at=last_dispatch_attempt_at,last_dispatch_error=NULL,last_claim_failed_at=last_dispatch_attempt_at,last_claim_error='backend rejected the claim'").await;
    signal().await;
    let unclaimed = observe(&app, "default", &run).await;
    assert_eq!(
        unclaimed["liveness_state"], "workflow_task_claim_failed",
        "{unclaimed}"
    );
    assert!(
        unclaimed["liveness_reason"]
            .as_str()
            .unwrap()
            .ends_with(". backend rejected the claim")
    );
    // A leased task carries no ready-task transport problem.
    let claimed = claim(&app, "default").await;
    assert_eq!(claimed.0, StatusCode::OK, "{}", claimed.1);
    assert!(claimed.1["task"].is_object());
    let leased = observe(&app, "default", &run).await;
    assert_eq!(leased["liveness_state"], "workflow_task_leased", "{leased}");
    runtime.close().await;
    database.remove().await;
}
