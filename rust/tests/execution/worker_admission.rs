use super::*;

// Server 2.10.0 `WorkerController::register`: Laravel's request rules, with
// `capability_manifest` required from protocol 1.18, then the capability
// guards in PHP's order. These tests use the server's own router, without
// the SDK-style manifest the other tests' router adds.

async fn app() -> (TestDatabase, Router) {
    let database = TestDatabase::new().await;
    let app = durable_workflow_server::runtime::router(database.open().await.unwrap());
    (database, app)
}

fn affinity(local: bool, sessions: bool, sticky: bool) -> Value {
    let entry = |supported: bool| json!({"supported": supported, "minimum_protocol_version": "1.18"});
    json!({"local_activities": entry(local), "worker_sessions": entry(sessions), "sticky_execution": entry(sticky)})
}

async fn register_at(app: &Router, version: &str, fields: Value) -> (StatusCode, Value) {
    let mut body = json!({"worker_id": "admitted", "task_queue": "test", "runtime": "php"});
    for (field, value) in fields.as_object().unwrap() {
        body[field] = value.clone();
    }
    request_protocol(app, "POST", "/api/worker/register", body, version).await
}

#[tokio::test]
async fn the_manifest_is_required_from_protocol_1_18() {
    let (_database, app) = app().await;
    let (status, body) = register_at(&app, "1.19", json!({})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    assert_eq!(body["reason"], "validation_failed");
    assert_eq!(body["errors"]["capability_manifest"], json!(["The capability manifest field is required."]), "{body}");
    // `WorkerProtocol::json` names the protocol on refusals too.
    assert_eq!(body["protocol_version"], "1.20");
    assert!(body["server_capabilities"].is_object(), "{body}");

    let (status, body) = register_at(&app, "1.17", json!({})).await;
    assert_eq!(status, StatusCode::CREATED, "an older worker needs no manifest: {body}");

    let (status, body) = register_at(&app, "1.19", json!({"capability_manifest": {"local_activities": {}}})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    assert_eq!(
        body["errors"],
        json!({
            "capability_manifest": ["The capability manifest field must contain entries for: local_activities, worker_sessions, sticky_execution."],
            "capability_manifest.local_activities.supported": ["The capability_manifest.local_activities.supported field is required."],
            "capability_manifest.local_activities.minimum_protocol_version": ["The capability_manifest.local_activities.minimum_protocol_version field is required."],
        }),
        "{body}"
    );

    // A scalar entry is not an array and has no fields to require.
    let mut manifest = affinity(false, false, false);
    manifest["worker_sessions"] = json!("no");
    let (status, body) = register_at(&app, "1.19", json!({"capability_manifest": manifest})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    assert_eq!(body["errors"], json!({"capability_manifest.worker_sessions": ["The capability_manifest.worker_sessions field must be an array."]}), "{body}");
}

#[tokio::test]
async fn registration_fields_follow_php_rules() {
    let (_database, app) = app().await;
    let manifest = affinity(false, false, false);
    let (status, body) = register_at(&app, "1.19", json!({"capability_manifest": manifest, "runtime": "cobol",
        "process_metrics": {"cpu_percent": "high"}, "heartbeat_interval_seconds": 0})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    assert_eq!(
        body["errors"],
        json!({
            "runtime": ["The selected runtime is invalid."],
            "process_metrics.cpu_percent": ["The process metrics.cpu percent field must be a number."],
            "heartbeat_interval_seconds": ["The heartbeat interval seconds field must be at least 1."],
        }),
        "{body}"
    );

    // An unnamed worker gets a ULID; strings are trimmed and the manifest is
    // stored normalized.
    let mut manifest = affinity(false, false, false);
    manifest["local_activities"]["implementation"] = json!("  sdk  ");
    manifest["local_activities"]["extra"] = json!("dropped");
    let (status, body) = register_at(&app, "1.19", json!({"worker_id": "", "task_queue": " test ", "capability_manifest": manifest})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    let worker_id = body["worker_id"].as_str().unwrap();
    assert_eq!(worker_id.len(), 26, "{body}");
    let (status, described) = request(&app, "GET", &format!("/api/workers/{worker_id}"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{described}");
    assert_eq!(described["task_queue"], "test", "{described}");
    assert_eq!(
        described["capability_manifest"]["local_activities"],
        json!({"supported": false, "minimum_protocol_version": "1.18", "implementation": "sdk"}),
        "{described}"
    );
}

#[tokio::test]
async fn capability_guards_refuse_like_php() {
    let (_database, app) = app().await;
    let manifest = affinity(false, false, false);
    let refused = |capabilities: Value, version: &'static str, manifest: Value| {
        let app = app.clone();
        async move {
            let (status, body) =
                register_at(&app, version, json!({"capabilities": capabilities, "capability_manifest": manifest})).await;
            assert_eq!(status, StatusCode::CONFLICT, "{body}");
            assert_eq!(body["registered"], false, "{body}");
            assert_eq!(body["protocol_version"], "1.20", "{body}");
            body
        }
    };

    // The flat capability and the structured flag must agree.
    let body = refused(json!(["worker_sessions"]), "1.19", manifest.clone()).await;
    assert_eq!((body["reason"].as_str(), body["capability"].as_str()), (Some("worker_capability_manifest_mismatch"), Some("worker_sessions")), "{body}");
    let body = refused(json!([]), "1.19", affinity(false, false, true)).await;
    assert_eq!(body["capability"], "sticky_execution", "{body}");

    // An advertised capability needs its floor at 1.18 or later and met.
    let mut low = affinity(true, false, false);
    low["local_activities"]["minimum_protocol_version"] = json!("1.17");
    let body = refused(json!(["local_activities"]), "1.19", low).await;
    assert_eq!(body["reason"], "worker_capability_protocol_floor_mismatch", "{body}");
    let mut high = affinity(true, false, false);
    high["local_activities"]["minimum_protocol_version"] = json!("1.21");
    let body = refused(json!(["local_activities"]), "1.20", high).await;
    assert_eq!(body["reason"], "worker_capability_protocol_floor_mismatch", "{body}");
    assert_eq!(body["remediation"], "Advertise local_activities only from a worker targeting protocol 1.18 or newer.");

    // Cooperative cancellation and what builds on it need protocol 1.20.
    let body = refused(json!(["cooperative_cancellation"]), "1.19", manifest.clone()).await;
    assert_eq!(body["reason"], "cooperative_cancellation_protocol_mismatch", "{body}");
    assert_eq!(body["requested_version"], "1.19");
    let body = refused(json!(["prepared_local_activities"]), "1.20", manifest.clone()).await;
    assert_eq!(body["reason"], "prepared_local_activity_capability_mismatch", "{body}");
    assert_eq!(body["required_capability"], "cooperative_cancellation");
    let body = refused(json!(["cooperative_cancellation", "prepared_local_activity_groups"]), "1.20", manifest.clone()).await;
    assert_eq!(body["reason"], "prepared_local_activity_group_capability_mismatch", "{body}");
    let body = refused(json!(["prepared_local_activity_cancellation_policies"]), "1.20", manifest.clone()).await;
    assert_eq!(body["reason"], "prepared_local_activity_cancellation_policy_capability_mismatch", "{body}");
    let body = refused(json!(["nexus_cooperative_cancellation"]), "1.20", manifest.clone()).await;
    assert_eq!(body["reason"], "nexus_cooperative_cancellation_capability_mismatch", "{body}");

    // Workflow metadata and message streams have their own floors.
    let body = refused(json!(["memo_upserts"]), "1.13", Value::Null).await;
    assert_eq!(
        (body["reason"].as_str(), body["capability"].as_str(), body["minimum_protocol_version"].as_str()),
        (Some("workflow_metadata_capability_protocol_mismatch"), Some("memo_upserts"), Some("1.14")),
        "{body}"
    );
    assert_eq!(body["remediation"], "Advertise memo_upserts only while sending worker protocol 1.14 or newer.");
    let body = refused(json!(["typed_search_attributes"]), "1.15", Value::Null).await;
    assert_eq!(body["capability"], "typed_search_attributes", "{body}");
    let body = refused(json!(["message_streams"]), "1.14", Value::Null).await;
    assert_eq!(body["reason"], "message_streams_unavailable", "{body}");

    // At 1.20 a cooperative worker with matching flags registers.
    let (status, body) = register_at(&app, "1.20", json!({"capabilities": ["cooperative_cancellation", "local_activities"],
        "capability_manifest": affinity(true, false, false)})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}
