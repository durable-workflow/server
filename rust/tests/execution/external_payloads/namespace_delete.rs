use super::retention::{
    allow_registration_deletes, cleanup_pass, closed_run, ns_request, object, pending_deletions, refuse_registration_deletes,
    registered, upload,
};
use super::*;

// Published PHP 2.11.8 releases a namespace's external payloads with it
// (`ExternalPayloadRetentionCleanup::deleteForNamespaceCleanup`, then
// `RuntimeExternalPayloadRegistry::deleteForNamespace`) and deletes their
// objects only after the delete commits, answering 207 while any is pending.

async fn create(router: &Router, name: &str) {
    let (status, body) = ns_request(router, "POST", "/api/namespaces", "default", json!({"name": name})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

async fn delete(router: &Router, name: &str) -> (StatusCode, Value) {
    ns_request(router, "DELETE", &format!("/api/namespaces/{name}"), "default", Value::Null).await
}

#[tokio::test]
async fn namespace_delete_settles_its_external_payloads() {
    let temp = tempfile::tempdir().unwrap();
    let root = temp.path().join("objects");
    let (database, _runtime, router) = setup(ExternalPayloadConfig::default(), &root).await;
    create(&router, "doomed").await;
    create(&router, "peer").await;
    assert_eq!(policy(&router, "doomed", &root, true).await, StatusCode::OK);
    assert_eq!(policy(&router, "peer", &root, true).await, StatusCode::OK);
    // A payload only the namespace's run holds.
    let sole = upload(&router, "doomed", "namespace sole").await;
    closed_run(&router, "doomed", "doomed-sole", &sole).await;
    // The same bytes registered in both namespaces, each held by a run.
    let mine = upload(&router, "doomed", "namespace shared").await;
    let theirs = upload(&router, "peer", "namespace shared").await;
    closed_run(&router, "doomed", "doomed-shared", &mine).await;
    closed_run(&router, "peer", "peer-shared", &theirs).await;
    // Uploaded, never referenced.
    let unused = upload(&router, "doomed", "namespace unused").await;

    let (status, body) = delete(&router, "doomed").await;
    assert_eq!(status, StatusCode::OK, "{body}");
    // The sole object goes with its reference. The shared one is held from
    // outside, so its registration goes in the registry's pass with the
    // unreferenced upload, whose object goes too.
    assert_eq!(body["deleted"]["external_payloads_deleted"], 1, "{body}");
    assert_eq!(body["deleted"]["runtime_external_payload_references"], 2, "{body}");
    assert_eq!(body["external_payload_cleanup"], json!({"pending": 0, "failed": 0, "blocked": 0}), "{body}");
    assert!(!object(&root, &sole).exists());
    assert!(!object(&root, &unused).exists());
    assert!(object(&root, &theirs).exists());
    for reference in [&sole, &mine, &unused] {
        assert_eq!(registered(&database, reference).await, 0);
    }
    assert_eq!(registered(&database, &theirs).await, 1);
    let (status, _, bytes) = request(&router, "GET", &format!("/api/external-payloads/v1/{}", theirs["reference_id"].as_str().unwrap()),
        "worker-token", "peer", Some(&theirs), Body::empty()).await;
    assert_eq!(status, StatusCode::OK, "the peer still reads its payload");
    assert_eq!(digest(&bytes), theirs["sha256"].as_str().unwrap());
}

#[tokio::test]
async fn namespace_delete_without_storage_refuses_and_changes_nothing() {
    let temp = tempfile::tempdir().unwrap();
    let root = temp.path().join("objects");
    let (database, _runtime, router) = setup(ExternalPayloadConfig::default(), &root).await;

    // A referenced object it alone holds needs the namespace's storage.
    create(&router, "held").await;
    assert_eq!(policy(&router, "held", &root, true).await, StatusCode::OK);
    let sole = upload(&router, "held", "held sole").await;
    closed_run(&router, "held", "held-sole", &sole).await;
    assert_eq!(policy(&router, "held", &root, false).await, StatusCode::OK);
    let (status, body) = delete(&router, "held").await;
    assert_eq!(status, StatusCode::SERVICE_UNAVAILABLE, "{body}");
    assert_eq!(body, json!({
        "message": "Namespace cleanup requires deleting external payloads, but external payload storage is unavailable.",
        "reason": "external_payload_storage_driver_unavailable", "namespace": "held",
        "error": "External payload storage driver is unavailable for namespace cleanup."}));
    assert!(object(&root, &sole).exists());
    assert_eq!(registered(&database, &sole).await, 1);
    assert_eq!(ns_request(&router, "GET", "/api/namespaces/held", "default", Value::Null).await.0, StatusCode::OK);
    assert_eq!(policy(&router, "held", &root, true).await, StatusCode::OK);
    let (status, body) = delete(&router, "held").await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["deleted"]["external_payloads_deleted"], 1, "{body}");
    assert!(!object(&root, &sole).exists());

    // An unreferenced upload fails in the registry's own pass, PHP's typed
    // external payload error.
    create(&router, "idle").await;
    assert_eq!(policy(&router, "idle", &root, true).await, StatusCode::OK);
    let unused = upload(&router, "idle", "idle unused").await;
    assert_eq!(policy(&router, "idle", &root, false).await, StatusCode::OK);
    let (status, body) = delete(&router, "idle").await;
    assert_eq!(status, StatusCode::SERVICE_UNAVAILABLE, "{body}");
    assert_eq!(body["schema"], "durable-workflow.v2.runtime-external-payload-error.v1", "{body}");
    assert_eq!(body["reason"], "external_payload_unavailable");
    assert_eq!(body["message"], "External payload storage is unavailable for namespace cleanup.");
    assert_eq!(body["retryable"], true);
    assert!(object(&root, &unused).exists());
    assert_eq!(registered(&database, &unused).await, 1);
}

#[tokio::test]
async fn a_namespace_delete_that_fails_after_its_provider_step_keeps_everything() {
    let temp = tempfile::tempdir().unwrap();
    let root = temp.path().join("objects");
    let (database, _runtime, router) = setup(ExternalPayloadConfig::default(), &root).await;
    create(&router, "rollback").await;
    assert_eq!(policy(&router, "rollback", &root, true).await, StatusCode::OK);
    let sole = upload(&router, "rollback", "namespace rollback").await;
    closed_run(&router, "rollback", "rollback-sole", &sole).await;

    refuse_registration_deletes(&database).await;
    let (status, body) = delete(&router, "rollback").await;
    assert!(status.is_server_error(), "{status} {body}");
    assert!(object(&root, &sole).exists(), "a rolled-back namespace delete must not lose provider bytes");
    assert_eq!(registered(&database, &sole).await, 1);
    assert_eq!(pending_deletions(&database).await, 0);
    assert_eq!(ns_request(&router, "GET", "/api/namespaces/rollback", "default", Value::Null).await.0, StatusCode::OK);

    allow_registration_deletes(&database).await;
    let (status, body) = delete(&router, "rollback").await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert!(!object(&root, &sole).exists());
}

#[tokio::test]
async fn a_pending_provider_deletion_answers_207_and_outlives_its_namespace() {
    let temp = tempfile::tempdir().unwrap();
    let root = temp.path().join("objects");
    let (database, _runtime, router) = setup(ExternalPayloadConfig::default(), &root).await;
    create(&router, "gone").await;
    assert_eq!(policy(&router, "gone", &root, true).await, StatusCode::OK);
    let sole = upload(&router, "gone", "namespace pending").await;
    closed_run(&router, "gone", "gone-sole", &sole).await;

    use std::os::unix::fs::PermissionsExt;
    let directory = object(&root, &sole).parent().unwrap().to_owned();
    std::fs::set_permissions(&directory, std::fs::Permissions::from_mode(0o555)).unwrap();
    if std::fs::File::create(directory.join("probe")).is_ok() {
        // A superuser ignores the mode; nothing here can be asserted.
        std::fs::remove_file(directory.join("probe")).unwrap();
        return;
    }
    // The namespace and its state are deleted; the provider deletion is not.
    let (status, body) = delete(&router, "gone").await;
    assert_eq!(status, StatusCode::MULTI_STATUS, "{body}");
    assert_eq!(body["status"], "deleted");
    assert_eq!(body["external_payload_cleanup"], json!({"pending": 1, "failed": 1, "blocked": 0}), "{body}");
    assert_eq!(ns_request(&router, "GET", "/api/namespaces/gone", "default", Value::Null).await.0, StatusCode::NOT_FOUND);
    assert!(object(&root, &sole).exists());
    assert_eq!(registered(&database, &sole).await, 0);

    // A cleanup pass in any namespace reaches a deleted namespace's
    // deletion, with the storage policy saved when it was recorded.
    std::fs::set_permissions(&directory, std::fs::Permissions::from_mode(0o755)).unwrap();
    let (status, body) = cleanup_pass(&router).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert!(!object(&root, &sole).exists());
    assert_eq!(pending_deletions(&database).await, 0);
}
