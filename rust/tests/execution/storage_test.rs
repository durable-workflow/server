use super::*;
use sha2::{Digest, Sha256};

fn digest(bytes: &[u8]) -> String {
    Sha256::digest(bytes)
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect()
}

fn files(root: &std::path::Path) -> usize {
    std::fs::read_dir(root)
        .map(|entries| {
            entries
                .flatten()
                .map(|entry| {
                    let path = entry.path();
                    if path.is_dir() { files(&path) } else { 1 }
                })
                .sum()
        })
        .unwrap_or(0)
}

async fn upload(app: &Router, bytes: &[u8]) -> StatusCode {
    let request = Request::builder()
        .method("POST")
        .uri("/api/external-payloads/v1")
        .header("authorization", "Bearer test-token")
        .header("x-namespace", "default")
        .header("content-type", "application/octet-stream")
        .header("x-durable-workflow-payload-codec", "avro")
        .header("x-durable-workflow-payload-size", bytes.len().to_string())
        .header("x-durable-workflow-payload-sha256", digest(bytes))
        .body(Body::from(bytes.to_vec()))
        .unwrap();
    app.clone().oneshot(request).await.unwrap().status()
}

#[tokio::test]
async fn the_storage_diagnostic_round_trips_through_the_local_driver() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let sizes = json!({"small_payload_bytes":64,"large_payload_bytes":70000});
    let (status, refused) = request(&app, "POST", "/api/storage/test", sizes.clone()).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY);
    assert_eq!(
        refused,
        json!({"status":"failed","reason":"external_storage_not_configured",
            "message":"External payload storage is not configured for this namespace.",
            "namespace":"default","driver":null})
    );
    let root = tempfile::tempdir().unwrap();
    let policy = |enabled: bool| {
        json!({"driver":"local","enabled":enabled,
            "config":{"uri":format!("file://{}", root.path().display())}})
    };
    let path = "/api/namespaces/default/external-storage";
    assert_eq!(
        request(&app, "PUT", path, policy(false)).await.0,
        StatusCode::OK
    );
    let (status, disabled) = request(&app, "POST", "/api/storage/test", sizes.clone()).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY);
    assert_eq!(disabled["reason"], "external_storage_disabled");
    assert_eq!(disabled["driver"], "local");
    assert_eq!(
        request(&app, "PUT", path, policy(true)).await.0,
        StatusCode::OK
    );
    let mut other = sizes.clone();
    other["driver"] = json!("s3");
    let (status, mismatch) = request(&app, "POST", "/api/storage/test", other).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY);
    assert_eq!(
        mismatch,
        json!({"status":"failed","reason":"storage_driver_unavailable",
            "message":"The requested external payload storage driver is not configured for this namespace.",
            "namespace":"default","driver":"s3","supported_diagnostic_drivers":["local"]})
    );

    let (status, passed) = request(&app, "POST", "/api/storage/test", sizes.clone()).await;
    assert_eq!(status, StatusCode::OK, "{passed}");
    assert_eq!(
        [&passed["status"], &passed["namespace"], &passed["driver"]],
        [&json!("passed"), &json!("default"), &json!("local")]
    );
    for (key, fill, bytes) in [("small_payload", b's', 64), ("large_payload", b'l', 70000)] {
        let result = &passed[key];
        assert_eq!(
            [&result["status"], &result["bytes"], &result["sha256"]],
            [
                &json!("passed"),
                &json!(bytes),
                &json!(digest(&vec![fill; bytes]))
            ],
            "{key}"
        );
        let identity = result["reference_identity_sha256"].as_str().unwrap();
        assert!(identity.len() == 64 && identity.bytes().all(|byte| byte.is_ascii_hexdigit()));
    }
    // The diagnostic leaves no object and registers nothing.
    assert_eq!(files(root.path()), 0);
    assert_eq!(
        task_retry::rows(&database, "SELECT COUNT(*) FROM runtime_external_payloads").await,
        0
    );

    // An uploaded payload with the diagnostic's exact bytes keeps its object.
    assert_eq!(upload(&app, &[b's'; 64]).await, StatusCode::CREATED);
    assert_eq!(files(root.path()), 1);
    let (status, _) = request(&app, "POST", "/api/storage/test", sizes).await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(files(root.path()), 1);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn storage_diagnostic_requests_are_validated_as_published_php_does() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (status, invalid) = request(
        &app,
        "POST",
        "/api/storage/test",
        json!({"driver":"ftp","small_payload_bytes":0}),
    )
    .await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY);
    assert_eq!(
        invalid,
        json!({"message":"The selected driver is invalid. (and 2 more errors)","reason":"validation_failed",
            "errors":{"driver":["The selected driver is invalid."],
                "small_payload_bytes":["The small payload bytes field must be at least 1."],
                "large_payload_bytes":["The large payload bytes field is required."]},
            "validation_errors":{"driver":["The selected driver is invalid."],
                "small_payload_bytes":["The small payload bytes field must be at least 1."],
                "large_payload_bytes":["The large payload bytes field is required."]}})
    );
    runtime.close().await;
    database.remove().await;
}

/// Server #589: a storage diagnostic failure names the step and its
/// classified reason. A permission refusal is the non-retryable
/// `external_payload_storage_access_denied` (424); any other storage failure
/// stays the retryable `external_payload_unavailable` (503). Neither shows
/// the storage location.
#[tokio::test]
async fn storage_diagnostic_failures_name_the_step_and_classify_access() {
    use std::os::unix::fs::PermissionsExt;
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let root = tempfile::tempdir().unwrap();
    let policy = json!({"driver":"local","enabled":true,"config":{"uri":format!("file://{}", root.path().display())}});
    assert_eq!(request(&app, "PUT", "/api/namespaces/default/external-storage", policy).await.0, StatusCode::OK);
    let sizes = json!({"small_payload_bytes":64,"large_payload_bytes":70000});
    let failed = |reason: &str, operation: &str, retryable: bool, cleanup: Value| {
        let message = if reason == "external_payload_unavailable" {
            "External payload storage is temporarily unavailable."
        } else {
            "External payload storage refused the server's access. Check the storage permissions for the server's identity; retrying will not succeed until they change."
        };
        json!({"status":"failed","reason":reason,"message":message,"namespace":"default","driver":"local",
            "operation":operation,"payload":"small_payload","retryable":retryable,"cleanup":cleanup})
    };
    let location = root.path().display().to_string();

    // An outage: the object directory cannot be created.
    std::fs::write(root.path().join("avro"), b"not a directory").unwrap();
    let (status, outage) = request(&app, "POST", "/api/storage/test", sizes.clone()).await;
    assert_eq!((status, outage.clone()), (StatusCode::SERVICE_UNAVAILABLE,
        failed("external_payload_unavailable", "put", true, Value::Null)));
    assert!(!outage.to_string().contains(&location));
    std::fs::remove_file(root.path().join("avro")).unwrap();

    // A superuser ignores modes, so the refusals are asserted only when the
    // mode is effective.
    std::fs::set_permissions(root.path(), std::fs::Permissions::from_mode(0o555)).unwrap();
    let effective = std::fs::File::create(root.path().join("probe")).is_err();
    if effective {
        let (status, denied) = request(&app, "POST", "/api/storage/test", sizes.clone()).await;
        assert_eq!((status, denied.clone()), (StatusCode::FAILED_DEPENDENCY,
            failed("external_payload_storage_access_denied", "put", false, Value::Null)));
        assert!(!denied.to_string().contains(&location));
    } else {
        std::fs::remove_file(root.path().join("probe")).unwrap();
    }
    std::fs::set_permissions(root.path(), std::fs::Permissions::from_mode(0o755)).unwrap();

    // The write and read succeed, and the delete is refused.
    let (status, passed) = request(&app, "POST", "/api/storage/test", sizes.clone()).await;
    assert_eq!(status, StatusCode::OK, "{passed}");
    let small = vec![b's'; 64];
    let sha = digest(&small);
    let prefix = root.path().join("avro").join(&sha[..2]);
    std::fs::write(prefix.join(&sha), &small).unwrap();
    std::fs::set_permissions(&prefix, std::fs::Permissions::from_mode(0o555)).unwrap();
    if effective {
        let (status, denied) = request(&app, "POST", "/api/storage/test", sizes.clone()).await;
        assert_eq!((status, denied), (StatusCode::FAILED_DEPENDENCY,
            failed("external_payload_storage_access_denied", "delete", false, Value::Null)));
        assert!(prefix.join(&sha).exists());
    }
    std::fs::set_permissions(&prefix, std::fs::Permissions::from_mode(0o755)).unwrap();
    assert_eq!(request(&app, "POST", "/api/storage/test", sizes).await.0, StatusCode::OK);
    assert!(!prefix.join(&sha).exists());
    runtime.close().await;
    database.remove().await;
}
