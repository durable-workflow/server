use super::*;
use sqlx::AssertSqlSafe;

async fn upload_bytes(router: &Router, bytes: &[u8]) -> Value {
    let (status, _, body) = request(router, "POST", "/api/external-payloads/v1", "worker-token", "default",
        Some(&metadata(bytes)), Body::from(bytes.to_vec())).await;
    assert_eq!(status, StatusCode::CREATED, "{}", String::from_utf8_lossy(&body));
    serde_json::from_slice::<Value>(&body).unwrap()["reference"].clone()
}

fn id(reference: &Value) -> &str {
    reference["reference_id"].as_str().unwrap()
}

/// The local driver's content-addressed object path.
fn object(root: &std::path::Path, reference: &Value) -> std::path::PathBuf {
    let sha = reference["sha256"].as_str().unwrap();
    root.join("avro").join(&sha[..2]).join(sha)
}

async fn expire(database: &TestDatabase, ids: &[&str]) {
    let list = ids.iter().map(|id| format!("'{id}'")).collect::<Vec<_>>().join(",");
    database.execute(AssertSqlSafe(format!(
        "UPDATE runtime_external_payloads SET expires_at='2000-01-01 00:00:00' WHERE id IN ({list})"))).await;
}

async fn pass(router: &Router, body: Value) -> (StatusCode, Value) {
    json_request(router, "POST", "/api/system/external-payload-cleanup/pass", "admin-token", body).await
}

async fn status(router: &Router) -> Value {
    let (status, body) = json_request(router, "GET", "/api/system/external-payload-cleanup", "admin-token", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["namespace"], "default");
    body["cleanup"].clone()
}

fn report(values: [i64; 7], backlog: i64, limit: i64, pressure: bool) -> Value {
    json!({"processed": values[0], "deleted_references": values[1], "deleted_backing_objects": values[2],
        "shared_objects_preserved": values[3], "skipped": values[4], "blocked": values[5],
        "storage_driver_failures": values[6], "backlog": backlog, "scan_limit": limit, "scan_pressure": pressure})
}

#[tokio::test]
async fn cleanup_reclaims_expired_objects_and_keeps_shared_live_and_retained_ones() {
    let temp = tempfile::tempdir().unwrap();
    let root = temp.path().join("objects");
    let (database, _runtime, router) = setup(ExternalPayloadConfig::default(), &root).await;
    let fresh = status(&router).await;
    assert_eq!([&fresh["totals"]["passes"], &fresh["last_pass"]["status"]], [&json!(0), &Value::Null]);
    let expired = upload_bytes(&router, b"expired object").await;
    let shared = upload_bytes(&router, b"shared object").await;
    let live = upload_bytes(&router, b"live object").await;
    let retained = upload_bytes(&router, b"retained object").await;
    // Another namespace's reference to the shared object.
    database.execute(AssertSqlSafe(format!("INSERT INTO runtime_external_payloads(id,namespace,storage_uri,storage_uri_sha256,codec,sha256,size_bytes,upload_status,retained_at,expires_at,created_at,updated_at) SELECT 'zz-shared-twin','other',storage_uri,storage_uri_sha256,codec,sha256,size_bytes,upload_status,NULL,expires_at,created_at,updated_at FROM runtime_external_payloads WHERE id='{}'", id(&shared)))).await;
    expire(&database, &[id(&expired), id(&shared), "zz-shared-twin", id(&retained)]).await;
    database.execute(AssertSqlSafe(format!(
        "UPDATE runtime_external_payloads SET retained_at='2026-01-01 00:00:00' WHERE id='{}'", id(&retained)))).await;

    // The pass reclaims only this namespace's references. The shared one
    // loses its row and keeps the object the other namespace still holds.
    let (code, body) = pass(&router, json!({})).await;
    assert_eq!(code, StatusCode::OK, "{body}");
    assert_eq!(body, json!({"namespace": "default", "cleanup": report([2, 2, 1, 1, 0, 0, 0], 0, 100, false)}));
    assert!(!object(&root, &expired).exists());
    assert!(object(&root, &shared).exists());
    assert!(object(&root, &live).exists());
    assert!(object(&root, &retained).exists());
    assert_eq!(count(&database, "SELECT COUNT(*) FROM runtime_external_payloads").await, 3);
    assert_eq!(count(&database, "SELECT COUNT(*) FROM runtime_external_payloads WHERE id='zz-shared-twin'").await, 1);

    let after = status(&router).await;
    assert_eq!(
        after["totals"],
        json!({"passes": 1, "deleted_references": 2, "deleted_backing_objects": 1, "shared_objects_preserved": 1,
            "blocked_outcomes": 0, "storage_driver_failures": 0})
    );
    assert_eq!([&after["last_pass"]["status"], &after["last_pass"]["processed"]], [&json!("completed"), &json!(2)]);
    let completed = after["last_pass"]["completed_at"].as_str().unwrap();
    assert!(completed.ends_with(".000000Z"), "{completed}");
    assert_eq!(after["backlog"], json!({"expired_unclaimed": 0, "failed_registration_objects": 0}));
}

#[tokio::test]
async fn cleanup_respects_its_limit_a_backup_hold_and_a_disabled_policy() {
    let temp = tempfile::tempdir().unwrap();
    let root = temp.path().join("objects");
    let (database, _runtime, router) = setup(ExternalPayloadConfig::default(), &root).await;
    let first = upload_bytes(&router, b"first object").await;
    let second = upload_bytes(&router, b"second object").await;
    expire(&database, &[id(&first), id(&second)]).await;
    assert_eq!(status(&router).await["backlog"]["expired_unclaimed"], 2);

    // A one-reference pass reports the rest as backlog under pressure.
    let (code, body) = pass(&router, json!({"limit": "1"})).await;
    assert_eq!(code, StatusCode::OK, "{body}");
    assert_eq!(body["cleanup"], report([1, 1, 1, 0, 0, 0, 0], 1, 1, true));

    // An unexpired online-backup hold pauses deletion: 207, object kept.
    database.execute("INSERT INTO runtime_external_payload_backup_hold(id,owner,acquired_at,expires_at) VALUES (1,'7f1c3a1e-0000-4000-8000-000000000001','2026-01-01 00:00:00','2037-01-01 00:00:00')").await;
    let (code, body) = pass(&router, json!({})).await;
    assert_eq!(code, StatusCode::MULTI_STATUS, "{body}");
    assert_eq!(body["cleanup"], report([1, 0, 0, 0, 0, 1, 0], 1, 100, false));
    let remaining = if object(&root, &first).exists() { &first } else { &second };
    assert!(object(&root, remaining).exists());
    let held = status(&router).await;
    assert_eq!([&held["last_pass"]["status"], &held["totals"]["blocked_outcomes"]], [&json!("blocked"), &json!(1)]);

    // With the policy disabled there is no driver to delete through.
    database.execute("UPDATE runtime_external_payload_backup_hold SET expires_at='2000-01-01 00:00:00'").await;
    assert_eq!(policy(&router, "default", &root, false).await, StatusCode::OK);
    let (code, body) = pass(&router, json!({})).await;
    assert_eq!(code, StatusCode::MULTI_STATUS, "{body}");
    assert_eq!(body["cleanup"]["blocked"], 1);

    // Once the hold has expired and the policy is back, the object goes.
    assert_eq!(policy(&router, "default", &root, true).await, StatusCode::OK);
    let (code, body) = pass(&router, json!({"limit": null})).await;
    assert_eq!(code, StatusCode::OK, "{body}");
    assert_eq!(body["cleanup"], report([1, 1, 1, 0, 0, 0, 0], 0, 100, false));
    assert!(!object(&root, remaining).exists());
    assert_eq!(status(&router).await["totals"]["passes"], 4);

    for (limit, message) in [
        (json!(0), "The limit field must be at least 1."),
        (json!(1001), "The limit field must not be greater than 1000."),
        (json!(2.5), "The limit field must be an integer."),
    ] {
        let (code, body) = pass(&router, json!({"limit": limit})).await;
        assert_eq!(code, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
        assert_eq!(body["errors"]["limit"], json!([message]));
    }
    assert_eq!(status(&router).await["totals"]["passes"], 4);
}
