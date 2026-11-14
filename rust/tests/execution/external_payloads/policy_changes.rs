use super::*;
use super::retention::{closed_run, ns_request, object, registered, upload};

async fn fetch(router: &Router, namespace: &str, reference: &Value) -> (StatusCode, Value) {
    let (status, _, bytes) = request(router, "GET",
        &format!("/api/external-payloads/v1/{}", reference["reference_id"].as_str().unwrap()),
        "worker-token", namespace, Some(reference), Body::empty()).await;
    if status == StatusCode::OK {
        assert_eq!(digest(&bytes), reference["sha256"].as_str().unwrap());
        assert_eq!(bytes.len() as i64, reference["size_bytes"].as_i64().unwrap());
        (status, Value::Null)
    } else {
        (status, serde_json::from_slice(&bytes).unwrap())
    }
}

#[tokio::test]
async fn disabling_new_offloads_keeps_retained_reads_and_pruning_available() {
    let temporary = tempfile::tempdir().unwrap();
    let root = temporary.path().join("objects");
    let (database, runtime, router) = setup(ExternalPayloadConfig::default(), &root).await;
    let reference = upload(&router, "default", "retained after disabling").await;
    let run = closed_run(&router, "default", "disabled-retained", &reference).await;
    // A disable request need not repeat the backing location.
    let (status, body) = ns_request(&router, "PUT", "/api/namespaces/default/external-storage", "default",
        json!({"driver":"local", "enabled":false})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["external_payload_storage"]["config"]["uri"], format!("file://{}", root.display()));
    assert_eq!(fetch(&router, "default", &reference).await.0, StatusCode::OK);
    let new_bytes = b"a new upload must remain disabled";
    let (status, _, _) = request(&router, "POST", "/api/external-payloads/v1", "worker-token", "default",
        Some(&metadata(new_bytes)), Body::from(new_bytes.as_slice())).await;
    assert_eq!(status, StatusCode::SERVICE_UNAVAILABLE);
    let (status, body) = ns_request(&router, "POST", "/api/system/retention/pass", "default", json!({"run_ids":[run]})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["results"][0]["outcome"], "pruned", "{body}");
    assert_eq!(body["results"][0]["external_payloads_deleted"], 1);
    assert!(!object(&root, &reference).exists());
    assert_eq!(registered(&database, &reference).await, 0);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_location_change_is_refused_until_registered_objects_are_released() {
    let temporary = tempfile::tempdir().unwrap();
    let root = temporary.path().join("original");
    let next = temporary.path().join("replacement");
    let (database, runtime, router) = setup(ExternalPayloadConfig::default(), &root).await;
    let reference = upload(&router, "default", "keep the original location").await;
    let run = closed_run(&router, "default", "policy-location", &reference).await;
    let (status, body) = ns_request(&router, "PUT", "/api/namespaces/default/external-storage", "default",
        json!({"driver":"local","enabled":true,"config":{"uri":format!("file://{}", next.display())}})).await;
    assert_eq!(status, StatusCode::CONFLICT, "{body}");
    assert_eq!(body["reason"], "external_payload_storage_in_use");
    assert!(!next.exists(), "a refused configuration creates no backing directory");
    assert_eq!(fetch(&router, "default", &reference).await.0, StatusCode::OK);
    let (status, body) = ns_request(&router, "POST", "/api/system/retention/pass", "default", json!({"run_ids":[run]})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["results"][0]["outcome"], "pruned", "{body}");
    assert_eq!(policy(&router, "default", &next, true).await, StatusCode::OK);
    let replacement = upload(&router, "default", "the next location").await;
    assert!(object(&next, &replacement).exists());
    assert!(!object(&root, &replacement).exists());
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn disabled_storage_still_allows_namespace_deletion() {
    let temporary = tempfile::tempdir().unwrap();
    let root = temporary.path().join("objects");
    let (database, runtime, router) = setup(ExternalPayloadConfig::default(), &root).await;
    assert_eq!(ns_request(&router, "POST", "/api/namespaces", "default", json!({"name":"disabled-delete"})).await.0, StatusCode::CREATED);
    assert_eq!(policy(&router, "disabled-delete", &root, true).await, StatusCode::OK);
    let reference = upload(&router, "disabled-delete", "delete while disabled").await;
    closed_run(&router, "disabled-delete", "disabled-delete-run", &reference).await;
    assert_eq!(policy(&router, "disabled-delete", &root, false).await, StatusCode::OK);
    let (status, body) = ns_request(&router, "DELETE", "/api/namespaces/disabled-delete", "default", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert!(!object(&root, &reference).exists());
    assert_eq!(registered(&database, &reference).await, 0);
    runtime.close().await;
    database.remove().await;
}
