use super::*;

async fn register_definition(app: &Router, worker: &str, fingerprints: Value) {
    let body = json!({"worker_id": worker, "task_queue": "test", "runtime": "python",
        "supported_workflow_types": ["echo"], "supported_activity_types": [],
        "workflow_definition_fingerprints": fingerprints});
    let (status, answer) = request(app, "POST", "/api/worker/register", body).await;
    assert_eq!(status, StatusCode::CREATED, "{answer}");
}

async fn started_payload(app: &Router, workflow: &str) -> Value {
    let started = start(app, workflow).await;
    let history = run_history(app, &started["workflow_id"], &started["run_id"]).await;
    let event = history.as_array().unwrap().iter().find(|event| event["event_type"] == "WorkflowStarted");
    event.unwrap_or_else(|| panic!("{history}"))["payload"].clone()
}

// Published PHP's WorkflowStartVersionPin records the definition the start's
// worker cohort agrees on, with source `worker`. Without agreement the
// started event carries neither field.
#[tokio::test]
async fn a_start_records_the_definition_its_workers_agree_on() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());

    register_definition(&app, "original", json!({"echo": " sha256:original "})).await;
    let pinned = started_payload(&app, "definition-pinned").await;
    assert_eq!(pinned["workflow_definition_fingerprint"], "sha256:original", "{pinned}");
    assert_eq!(pinned["workflow_definition_fingerprint_source"], "worker", "{pinned}");

    // A second live worker advertising another definition breaks agreement.
    register_definition(&app, "replacement", json!({"echo": "sha256:replacement"})).await;
    let ambiguous = started_payload(&app, "definition-ambiguous").await;
    assert!(ambiguous.get("workflow_definition_fingerprint").is_none(), "{ambiguous}");
    assert!(ambiguous.get("workflow_definition_fingerprint_source").is_none(), "{ambiguous}");

    // Once the original deregisters, the replacement alone is the cohort.
    let (status, answer) = request(&app, "DELETE", "/api/workers/original", json!({})).await;
    assert_eq!(status, StatusCode::OK, "{answer}");
    let replaced = started_payload(&app, "definition-replaced").await;
    assert_eq!(replaced["workflow_definition_fingerprint"], "sha256:replacement", "{replaced}");

    // A worker that advertises no definition leaves none recorded.
    let (status, answer) = request(&app, "DELETE", "/api/workers/replacement", json!({})).await;
    assert_eq!(status, StatusCode::OK, "{answer}");
    register_definition(&app, "silent", json!({})).await;
    let unadvertised = started_payload(&app, "definition-unadvertised").await;
    assert!(unadvertised.get("workflow_definition_fingerprint").is_none(), "{unadvertised}");
}
