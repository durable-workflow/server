use super::*;

// Server 2.10.0 `WorkflowDefinitionRouting`: a run whose start recorded its
// worker cohort's definition fingerprint goes to a worker advertising that
// definition while one is live. A worker with another definition gets it only
// when none is, and only if it validates entry arguments.

async fn worker(app: &Router, id: &str, fingerprint: Option<&str>, capabilities: Value) {
    let mut body = json!({"worker_id":id,"task_queue":"test","runtime":"php","supported_workflow_types":["echo"],
        "supported_activity_types":[],"capabilities":capabilities});
    if let Some(fingerprint) = fingerprint {
        body["workflow_definition_fingerprints"] = json!({"echo": fingerprint});
    }
    let (status, registered) = request(app, "POST", "/api/worker/register", body).await;
    assert_eq!(status, StatusCode::CREATED, "{registered}");
}

async fn deregister(app: &Router, id: &str) {
    let (status, body) = request(app, "DELETE", &format!("/api/workers/{id}"), Value::Null).await;
    assert!(status.is_success(), "{status} {body}");
}

#[tokio::test]
async fn a_pinned_run_goes_to_a_worker_with_its_recorded_definition() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    worker(&app, "original", Some("sha256:original"), json!([])).await;
    let started = start(&app, "pinned").await;
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let recorded = history.as_array().unwrap().iter().find(|event| event["event_type"] == "WorkflowStarted").unwrap();
    assert_eq!(recorded["payload"]["workflow_definition_fingerprint"], "sha256:original");
    assert_eq!(recorded["payload"]["workflow_definition_fingerprint_source"], "worker");

    // A changed definition waits while the recorded one is live, even if it
    // could validate entry arguments; so does a worker without a fingerprint.
    worker(&app, "changed", Some("sha256:changed"), json!([super::ENTRY_VALIDATION])).await;
    worker(&app, "unfingerprinted", None, json!([super::ENTRY_VALIDATION])).await;
    assert!(poll(&app, "changed", "workflow").await.is_null(), "matching_definition_preferred");
    assert!(poll(&app, "unfingerprinted", "workflow").await.is_null(), "worker_definition_missing");
    let task = poll(&app, "original", "workflow").await;
    assert_eq!(task["run_id"], started["run_id"], "{task}");
}

#[tokio::test]
async fn without_the_recorded_definition_only_an_entry_validating_worker_replays() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    worker(&app, "original", Some("sha256:original"), json!([])).await;
    let started = start(&app, "replacement").await;
    deregister(&app, "original").await;
    worker(&app, "unvalidated", Some("sha256:changed"), json!([])).await;
    assert!(poll(&app, "unvalidated", "workflow").await.is_null(), "entry_validation_unavailable");
    worker(&app, "validating", Some("sha256:changed"), json!([super::ENTRY_VALIDATION])).await;
    let task = poll(&app, "validating", "workflow").await;
    assert_eq!(task["run_id"], started["run_id"], "replay_required: {task}");
}

#[tokio::test]
async fn an_unpinned_run_goes_to_any_worker_and_workers_record_their_protocol() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    // No fingerprint when the run starts, so it is not worker-pinned.
    worker(&app, "plain", None, json!([])).await;
    let started = start(&app, "unpinned").await;
    worker(&app, "fingerprinted", Some("sha256:any"), json!([])).await;
    let task = poll(&app, "fingerprinted", "workflow").await;
    assert_eq!(task["run_id"], started["run_id"], "not_worker_pinned: {task}");

    // `WorkflowReplayEligibility::PROTOCOL_METRIC`: each registration and
    // heartbeat records its protocol version with the process metrics.
    let (status, described) = request(&app, "GET", "/api/workers/plain", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{described}");
    assert_eq!(described["process_metrics"], json!({"_worker_protocol_version": "1.19"}), "{described}");
    let (status, beat) = request(&app, "POST", "/api/worker/heartbeat",
        json!({"worker_id":"plain","process_metrics":{"host":"worker-host"}})).await;
    assert_eq!(status, StatusCode::OK, "{beat}");
    let (_, described) = request(&app, "GET", "/api/workers/plain", Value::Null).await;
    assert_eq!(described["process_metrics"]["host"], "worker-host", "{described}");
    assert_eq!(described["process_metrics"]["_worker_protocol_version"], "1.19", "{described}");
}

// Describe, repair, debug and the task queue report the route of a pinned
// run's next ready task as `definition_routing`. A blocked route is a debug
// finding and makes the queue need attention.
#[tokio::test]
async fn reads_report_a_blocked_definition_route() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    worker(&app, "original", Some("sha256:original"), json!([])).await;
    let started = start(&app, "reported").await;
    deregister(&app, "original").await;
    worker(&app, "unvalidated", Some("sha256:changed"), json!([])).await;

    let (status, described) = request(&app, "GET", "/api/workflows/reported", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{described}");
    let routing = &described["definition_routing"];
    assert_eq!(routing["status"], "entry_validation_unavailable", "{routing}");
    assert_eq!(routing["blocked"], true);
    assert_eq!(routing["runnable_task"], true);
    assert_eq!(routing["worker_pinned"], true);
    assert_eq!(routing["pinning_enabled"], true);
    assert_eq!(routing["recorded_fingerprint"], "sha256:original");
    assert_eq!(routing["task_queue"], "test");
    assert_eq!((routing["eligible_worker_count"].clone(), routing["changed_worker_count"].clone(),
        routing["entry_validation_unavailable_count"].clone()), (json!(1), json!(1), json!(1)));
    assert_eq!(routing["workers"], json!([{"worker_id": "unvalidated", "fingerprint": "sha256:changed"}]));
    assert!(routing["resolution"].as_str().unwrap().starts_with("Upgrade the replacement worker SDK"));

    let (status, debug) = request(&app, "GET", "/api/workflows/reported/debug", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{debug}");
    assert_eq!(debug["definition_routing"], *routing);
    assert_eq!(debug["diagnostic_status"], "needs_attention");
    let finding = debug["findings"].as_array().unwrap().iter()
        .find(|finding| finding["code"] == "workflow_definition_routing_blocked").unwrap_or_else(|| panic!("{debug}"));
    assert_eq!(finding["routing_status"], "entry_validation_unavailable");

    let (status, queue) = request(&app, "GET", "/api/task-queues/test", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{queue}");
    assert_eq!(queue["definition_routing"]["blocked_sample_count"], 1, "{queue}");
    assert_eq!(queue["definition_routing"]["runs"][0]["run_id"], started["run_id"]);
    assert_eq!(queue["definition_routing"]["runs"][0]["workflow_id"], "reported");
    assert_eq!(queue["repair"]["needs_attention"], true, "{queue}");

    let (status, repaired) = request(&app, "POST", "/api/workflows/reported/repair", json!({})).await;
    assert!(status.is_success(), "{status} {repaired}");
    assert_eq!(repaired["definition_routing"]["status"], "entry_validation_unavailable", "{repaired}");

    // An entry-validating replacement unblocks the route.
    worker(&app, "validating", Some("sha256:changed"), json!([super::ENTRY_VALIDATION])).await;
    let (_, described) = request(&app, "GET", "/api/workflows/reported", Value::Null).await;
    assert_eq!(described["definition_routing"]["status"], "replay_required", "{described}");
    assert_eq!(described["definition_routing"]["blocked"], false);
}
