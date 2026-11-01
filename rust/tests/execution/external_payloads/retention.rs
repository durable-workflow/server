use super::*;
use sqlx::AssertSqlSafe;

// Published PHP 2.11.7 deletes a pruned run's external payloads in the
// prune's transaction (`ExternalPayloadRetentionCleanup::deleteForRun`).

const PASS: &str = "/api/system/retention/pass";

/// A JSON control-plane request in `namespace`.
async fn ns_request(router: &Router, method: &str, path: &str, namespace: &str, value: Value) -> (StatusCode, Value) {
    let response = router
        .clone()
        .oneshot(
            Request::builder()
                .method(method)
                .uri(path)
                .header("authorization", "Bearer admin-token")
                .header("x-durable-workflow-control-plane-version", "2")
                .header("x-namespace", namespace)
                .header("content-type", "application/json")
                .body(Body::from(value.to_string()))
                .unwrap(),
        )
        .await
        .unwrap();
    let status = response.status();
    let bytes = to_bytes(response.into_body(), 4 * 1024 * 1024).await.unwrap();
    (status, serde_json::from_slice(&bytes).unwrap())
}

/// An uploaded Avro payload's reference in `namespace`.
async fn upload(router: &Router, namespace: &str, text: &str) -> Value {
    let blob = durable_workflow_server::codec::ValueCodec::new()
        .unwrap()
        .encode(&Payload::Array(vec![Payload::String(text.into())]))
        .unwrap();
    let (status, _, body) = request(router, "POST", "/api/external-payloads/v1", "worker-token", namespace,
        Some(&metadata(blob.as_bytes())), Body::from(blob)).await;
    assert_eq!(status, StatusCode::CREATED, "{}", String::from_utf8_lossy(&body));
    serde_json::from_slice::<Value>(&body).unwrap()["reference"].clone()
}

/// A terminated run whose input is `reference`.
async fn closed_run(router: &Router, namespace: &str, workflow: &str, reference: &Value) -> String {
    let (status, started) = ns_request(router, "POST", "/api/workflows", namespace, json!({"workflow_id": workflow,
        "workflow_type": "echo", "task_queue": workflow, "input": {"codec": "avro", "external_payload": reference}})).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    let (status, body) = ns_request(router, "POST", &format!("/api/workflows/{workflow}/terminate"), namespace,
        json!({"reason": "retention"})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    started["run_id"].as_str().unwrap().to_owned()
}

/// The one result of a retention pass over `run`.
async fn prune(router: &Router, namespace: &str, run: &str) -> (StatusCode, Value) {
    let (status, body) = ns_request(router, "POST", PASS, namespace, json!({"run_ids": [run]})).await;
    assert_eq!(body["processed"], 1, "{body}");
    (status, body["results"][0].clone())
}

fn pruned(result: &Value, external_payloads: i64) {
    assert_eq!(result["outcome"], "pruned", "{result}");
    assert_eq!(result["external_payloads_deleted"], external_payloads, "{result}");
}

/// The local driver's content-addressed object path.
fn object(root: &std::path::Path, reference: &Value) -> std::path::PathBuf {
    let sha = reference["sha256"].as_str().unwrap();
    root.join("avro").join(&sha[..2]).join(sha)
}

async fn registered(database: &TestDatabase, reference: &Value) -> i64 {
    count(database, AssertSqlSafe(format!("SELECT COUNT(*) FROM runtime_external_payloads WHERE id='{}'",
        reference["reference_id"].as_str().unwrap()))).await
}

async fn kept_details(database: &TestDatabase, run: &str) -> bool {
    count(database, AssertSqlSafe(format!("SELECT COUNT(*) FROM workflow_run_summaries WHERE id='{run}'"))).await == 1
        && count(database, AssertSqlSafe(format!("SELECT COUNT(*) FROM workflow_runs WHERE id='{run}' AND details_pruned_at IS NULL"))).await == 1
}

#[tokio::test]
async fn retention_deletes_objects_no_retained_run_holds() {
    let temp = tempfile::tempdir().unwrap();
    let root = temp.path().join("objects");
    let (database, _runtime, router) = setup(ExternalPayloadConfig::default(), &root).await;

    // A reference only the pruned run holds loses its object and registration.
    let sole = upload(&router, "default", "sole").await;
    let run = closed_run(&router, "default", "sole", &sole).await;
    assert!(object(&root, &sole).exists());
    let (status, result) = prune(&router, "default", &run).await;
    assert_eq!(status, StatusCode::OK);
    pruned(&result, 1);
    assert!(!object(&root, &sole).exists());
    assert_eq!(registered(&database, &sole).await, 0);
    let (status, _, _) = request(&router, "GET", &format!("/api/external-payloads/v1/{}", sole["reference_id"].as_str().unwrap()),
        "worker-token", "default", Some(&sole), Body::empty()).await;
    assert_eq!(status, StatusCode::NOT_FOUND);

    // A reference another retained run holds stays until that run goes.
    let shared = upload(&router, "default", "shared").await;
    let first = closed_run(&router, "default", "shared-1", &shared).await;
    let second = closed_run(&router, "default", "shared-2", &shared).await;
    pruned(&prune(&router, "default", &first).await.1, 0);
    assert!(object(&root, &shared).exists());
    assert_eq!(registered(&database, &shared).await, 1);
    pruned(&prune(&router, "default", &second).await.1, 1);
    assert!(!object(&root, &shared).exists());
    assert_eq!(registered(&database, &shared).await, 0);

    // Another namespace registering the same object over the same root: its
    // retained run holds the object, and its registration keeps it after
    // this namespace's goes.
    assert_eq!(ns_request(&router, "POST", "/api/namespaces", "default", json!({"name": "other"})).await.0, StatusCode::CREATED);
    assert_eq!(policy(&router, "other", &root, true).await, StatusCode::OK);
    let mine = upload(&router, "default", "cross namespace").await;
    let theirs = upload(&router, "other", "cross namespace").await;
    assert_ne!(mine["reference_id"], theirs["reference_id"]);
    let my_run = closed_run(&router, "default", "cross", &mine).await;
    let their_run = closed_run(&router, "other", "cross-theirs", &theirs).await;
    pruned(&prune(&router, "default", &my_run).await.1, 0);
    assert_eq!((registered(&database, &mine).await, registered(&database, &theirs).await), (1, 1));
    pruned(&prune(&router, "other", &their_run).await.1, 1);
    assert!(object(&root, &mine).exists());
    assert_eq!((registered(&database, &mine).await, registered(&database, &theirs).await), (1, 0));
}

#[tokio::test]
async fn retention_without_storage_or_after_a_failed_delete_keeps_the_run() {
    let temp = tempfile::tempdir().unwrap();
    let root = temp.path().join("objects");
    let (database, _runtime, router) = setup(ExternalPayloadConfig::default(), &root).await;
    let sole = upload(&router, "default", "sole").await;
    let run = closed_run(&router, "default", "sole", &sole).await;
    let shared = upload(&router, "default", "shared").await;
    let first = closed_run(&router, "default", "shared-1", &shared).await;
    let second = closed_run(&router, "default", "shared-2", &shared).await;

    inbound_stream(&database, &run).await;

    // Without storage, a run with an object to delete is skipped; one whose
    // objects a retained run holds needs none. The skip marks the instance's
    // inbound streams blocked, as PHP's `markBlocked` does.
    assert_eq!(policy(&router, "default", &root, false).await, StatusCode::OK);
    let (status, result) = prune(&router, "default", &run).await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(result, json!({"run_id": run, "outcome": "skipped", "reason": "external_payload_storage_driver_unavailable"}));
    assert!(kept_details(&database, &run).await);
    assert_eq!(blocked(&database, &run, "external_payload_storage_driver_unavailable").await, 1);
    pruned(&prune(&router, "default", &first).await.1, 0);
    assert_eq!(prune(&router, "default", &second).await.1["reason"], "external_payload_storage_driver_unavailable");
    assert_eq!(policy(&router, "default", &root, true).await, StatusCode::OK);

    // An online-backup hold fails the prune: 207, and everything stays.
    database.execute("INSERT INTO runtime_external_payload_backup_hold(id,owner,acquired_at,expires_at) VALUES (1,'7f1c3a1e-0000-4000-8000-000000000001','2026-01-01 00:00:00','2037-01-01 00:00:00')").await;
    let (status, result) = prune(&router, "default", &run).await;
    assert_eq!(status, StatusCode::MULTI_STATUS);
    assert_eq!(result, json!({"run_id": run, "outcome": "error",
        "reason": "Unable to delete external payload reference during retention cleanup."}));
    assert!(object(&root, &sole).exists());
    assert_eq!(registered(&database, &sole).await, 1);
    assert!(kept_details(&database, &run).await);
    assert_eq!(blocked(&database, &run, "external_payload_cleanup_failed").await, 1, "a failed prune marks its reason");
    database.execute("UPDATE runtime_external_payload_backup_hold SET expires_at='2000-01-01 00:00:00'").await;

    // A storage failure does the same; the next pass deletes the object.
    let directory = object(&root, &sole).parent().unwrap().to_owned();
    use std::os::unix::fs::PermissionsExt;
    std::fs::set_permissions(&directory, std::fs::Permissions::from_mode(0o555)).unwrap();
    // A superuser ignores the mode, so only an effective one is asserted.
    if std::fs::File::create(directory.join("probe")).is_err() {
        let (status, result) = prune(&router, "default", &run).await;
        assert_eq!(status, StatusCode::MULTI_STATUS);
        assert_eq!(result["reason"], "Unable to delete external payload reference during retention cleanup.");
        assert!(object(&root, &sole).exists());
        assert_eq!(registered(&database, &sole).await, 1);
        assert!(kept_details(&database, &run).await);
    } else {
        std::fs::remove_file(directory.join("probe")).unwrap();
    }
    std::fs::set_permissions(&directory, std::fs::Permissions::from_mode(0o755)).unwrap();
    pruned(&prune(&router, "default", &run).await.1, 1);
    assert!(!object(&root, &sole).exists());
    // The terminal instance's last retained run takes its streams with it.
    assert_eq!(count(&database, "SELECT COUNT(*) FROM workflow_inbound_streams").await, 0);
    pruned(&prune(&router, "default", &second).await.1, 1);
}

/// An inbound message stream on the run's instance.
async fn inbound_stream(database: &TestDatabase, run: &str) {
    database.execute(AssertSqlSafe(format!(
        "INSERT INTO workflow_inbound_streams(namespace,workflow_instance_id,stream_name) SELECT namespace,workflow_instance_id,'input' FROM workflow_runs WHERE id='{run}'"))).await;
}

/// The instance's inbound streams marked blocked by `run` for `reason`.
async fn blocked(database: &TestDatabase, run: &str, reason: &str) -> i64 {
    count(database, AssertSqlSafe(format!(
        "SELECT COUNT(*) FROM workflow_inbound_streams WHERE cleanup_blocked_at IS NOT NULL AND cleanup_blocked_run_id='{run}' AND cleanup_blocked_reason='{reason}'"))).await
}

// A continued instance keeps its streams. A prune that cannot settle the
// run's references marks them blocked; a later prune of the same run clears
// the mark, and an open abandoned activity's hold marks nothing.
#[tokio::test]
async fn a_blocked_prune_marks_its_instance_streams_until_it_completes() {
    let temp = tempfile::tempdir().unwrap();
    let root = temp.path().join("objects");
    let (database, _runtime, router) = setup(ExternalPayloadConfig::default(), &root).await;
    let sole = upload(&router, "default", "marked").await;
    let run = closed_run(&router, "default", "marked", &sole).await;
    inbound_stream(&database, &run).await;
    // A retained later run of the instance keeps the streams in place.
    database.execute(AssertSqlSafe(format!(
        "INSERT INTO workflow_run_summaries(id,workflow_instance_id,run_number,class,workflow_type,namespace,status,status_bucket) SELECT 'later-run',workflow_instance_id,2,'echo','echo','default','running','running' FROM workflow_runs WHERE id='{run}'"))).await;
    assert_eq!(policy(&router, "default", &root, false).await, StatusCode::OK);
    assert_eq!(prune(&router, "default", &run).await.1["reason"], "external_payload_storage_driver_unavailable");
    assert_eq!(blocked(&database, &run, "external_payload_storage_driver_unavailable").await, 1);
    assert_eq!(policy(&router, "default", &root, true).await, StatusCode::OK);
    pruned(&prune(&router, "default", &run).await.1, 1);
    assert_eq!(count(&database, "SELECT COUNT(*) FROM workflow_inbound_streams WHERE cleanup_blocked_at IS NULL AND cleanup_blocked_reason IS NULL AND cleanup_blocked_run_id IS NULL").await, 1,
        "the completed prune clears its mark and keeps the stream");
}

#[tokio::test]
async fn retention_keeps_a_run_holding_a_reference_native_cannot_settle() {
    let temp = tempfile::tempdir().unwrap();
    let root = temp.path().join("objects");
    let (database, _runtime, router) = setup(ExternalPayloadConfig::default(), &root).await;
    let sole = upload(&router, "default", "sole").await;
    let run = closed_run(&router, "default", "imported", &sole).await;
    // A reference in PHP's stored format, as a workflow import writes it.
    database.execute(AssertSqlSafe(format!(
        "UPDATE workflow_runs SET output='dw-external-payload:v1:e30=' WHERE id='{run}'"))).await;
    let (status, result) = prune(&router, "default", &run).await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(result["reason"], "external_payload_cleanup_unavailable", "{result}");
    assert!(object(&root, &sole).exists());
    assert!(kept_details(&database, &run).await);
}

// The skip for a reference native cannot settle marks the streams too.
#[tokio::test]
async fn an_unsettled_reference_marks_the_instance_streams_blocked() {
    let temp = tempfile::tempdir().unwrap();
    let root = temp.path().join("objects");
    let (database, _runtime, router) = setup(ExternalPayloadConfig::default(), &root).await;
    let sole = upload(&router, "default", "sole").await;
    let run = closed_run(&router, "default", "imported-streams", &sole).await;
    inbound_stream(&database, &run).await;
    database.execute(AssertSqlSafe(format!(
        "UPDATE workflow_runs SET output='dw-external-payload:v1:e30=' WHERE id='{run}'"))).await;
    assert_eq!(prune(&router, "default", &run).await.1["reason"], "external_payload_cleanup_unavailable");
    assert_eq!(blocked(&database, &run, "external_payload_cleanup_unavailable").await, 1);
}

// `DetachedActivityRetentionHold` keeps a run before PHP plans its streams,
// outside the prune's guarded transaction, so it marks nothing.
#[tokio::test]
async fn an_open_abandoned_activity_hold_marks_no_stream() {
    let temp = tempfile::tempdir().unwrap();
    let root = temp.path().join("objects");
    let (database, _runtime, router) = setup(ExternalPayloadConfig::default(), &root).await;
    let sole = upload(&router, "default", "detached").await;
    let run = closed_run(&router, "default", "detached-streams", &sole).await;
    inbound_stream(&database, &run).await;
    // An activity scheduled under the abandon policy with no terminal event.
    database.execute(AssertSqlSafe(format!(
        "INSERT INTO workflow_history_events(id,workflow_run_id,sequence,event_type,payload) VALUES ('01JDETACHEDHOLD0000000000A','{run}',1000,'ActivityScheduled','{{\"sequence\":1000,\"activity_execution_id\":\"detached-activity\",\"activity\":{{\"id\":\"detached-activity\",\"cancellation_policy\":\"abandon\"}}}}')"))).await;
    let (status, result) = prune(&router, "default", &run).await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(result["reason"], "detached_activity_still_open", "{result}");
    assert_eq!(count(&database, "SELECT COUNT(*) FROM workflow_inbound_streams WHERE cleanup_blocked_at IS NOT NULL OR cleanup_blocked_reason IS NOT NULL OR cleanup_blocked_run_id IS NOT NULL").await, 0);
}

// PHP's `HistoryRetentionEnforcer::pruneRun` catches every throwable inside
// the prune's transaction, including `WorkflowRunRetentionCleanup::pruneRun`
// refusing a run that is not closed, and marks the streams
// `external_payload_cleanup_failed` before rethrowing.
#[tokio::test]
async fn an_inner_prunability_error_marks_the_streams_failed_as_php_does() {
    let temp = tempfile::tempdir().unwrap();
    let root = temp.path().join("objects");
    let (database, _runtime, router) = setup(ExternalPayloadConfig::default(), &root).await;
    let sole = upload(&router, "default", "unclosed").await;
    let run = closed_run(&router, "default", "unclosed-streams", &sole).await;
    inbound_stream(&database, &run).await;
    database.execute(AssertSqlSafe(format!("UPDATE workflow_runs SET closed_at=NULL WHERE id='{run}'"))).await;
    let (status, result) = prune(&router, "default", &run).await;
    assert_eq!(status, StatusCode::MULTI_STATUS);
    assert_eq!(result["outcome"], "error", "{result}");
    assert_eq!(result["reason"], format!("Workflow run [{run}] has not been closed."));
    assert_eq!(blocked(&database, &run, "external_payload_cleanup_failed").await, 1);
    assert!(object(&root, &sole).exists());
}
