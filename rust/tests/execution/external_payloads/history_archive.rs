//! Self-contained verified history archives (Server #520): a run's export
//! and the exact bytes of every external payload it references, copied under
//! the backup hold through the HTTP API as `dw workflow:history-archive`
//! does, then verified from the archive's files alone.
use super::retention::{ns_request, object, registered, upload};
use super::*;
use durable_workflow_server::history_archive::{self as archive, HISTORY_FILE, MANIFEST_FILE, OBJECTS_DIR, RECEIPT_FILE};
use std::path::Path;

const HOLD: &str = "/api/system/external-payload-backup-hold";
const OWNER: &str = "5f0c2a1e-0000-4000-8000-0000000005a1";
const OTHER: &str = "5f0c2a1e-0000-4000-8000-0000000005b2";

async fn hold(router: &Router, action: &str, owner: &str) -> (StatusCode, Value) {
    json_request(router, "POST", &format!("{HOLD}/{action}"), "admin-token", json!({"owner": owner, "ttl": 600})).await
}

/// One control-plane GET in the default namespace, answering the raw body.
async fn get(router: &Router, path: &str, headers: &[(&str, String)]) -> (StatusCode, Vec<u8>) {
    let mut request = Request::builder()
        .method("GET")
        .uri(path)
        .header("authorization", "Bearer admin-token")
        .header("x-durable-workflow-control-plane-version", "2")
        .header("x-namespace", "default");
    for (name, value) in headers {
        request = request.header(*name, value);
    }
    let response = router.clone().oneshot(request.body(Body::empty()).unwrap()).await.unwrap();
    let status = response.status();
    (status, to_bytes(response.into_body(), 16 * 1024 * 1024).await.unwrap().to_vec())
}

/// An archive copy in progress: the hold it runs under and the export it
/// copies.
struct Copy {
    owner: &'static str,
    hold: Value,
    export_bytes: Vec<u8>,
    plan: archive::Plan,
    export: Value,
}

/// Take the hold, then read the export under it. A refused export releases
/// the hold and answers its blockers.
async fn begin(router: &Router, owner: &'static str, workflow: &str, run: &str) -> Result<Copy, Value> {
    let (status, held) = hold(router, "acquire", owner).await;
    if status != StatusCode::OK {
        return Err(held);
    }
    let (status, export_bytes) = get(router, &format!("/api/workflows/{workflow}/runs/{run}/history/export"), &[]).await;
    assert_eq!(status, StatusCode::OK, "{}", String::from_utf8_lossy(&export_bytes));
    let export: Value = serde_json::from_slice(&export_bytes).unwrap();
    let plan = archive::plan(&export);
    if !plan.blockers.is_empty() {
        assert_eq!(hold(router, "release", owner).await.0, StatusCode::OK);
        return Err(json!(plan.blockers.iter().map(|blocker| blocker.code).collect::<Vec<_>>()));
    }
    let hold = json!({"kind": archive::HOLD_KIND, "owner": owner, "acquired_at": held["acquired_at"], "expires_at": held["expires_at"]});
    Ok(Copy { owner, hold, export_bytes, plan, export })
}

/// Copy the export and every object it references into `dir`, then write
/// the manifest. Answers the objects that could not be copied.
async fn copy(router: &Router, copy: &Copy, dir: &Path) -> Vec<String> {
    std::fs::create_dir_all(dir.join(OBJECTS_DIR)).unwrap();
    std::fs::write(dir.join(HISTORY_FILE), &copy.export_bytes).unwrap();
    let mut failed = Vec::new();
    for object in &copy.plan.objects {
        let reference = &object.reference;
        let (status, bytes) = get(router, &format!("/api/external-payloads/v1/{}", reference.reference_id), &[
            ("x-durable-workflow-payload-codec", reference.codec.to_owned()),
            ("x-durable-workflow-payload-size", reference.size_bytes.to_string()),
            ("x-durable-workflow-payload-sha256", reference.sha256.clone()),
        ]).await;
        if status == StatusCode::OK && archive::sha256_hex(&bytes) == reference.sha256 {
            std::fs::write(dir.join(object.path()), bytes).unwrap();
        } else {
            failed.push(format!("{}:{status}", reference.reference_id));
        }
    }
    let manifest = archive::manifest(&copy.export, &copy.export_bytes, &copy.plan.objects, "default",
        "2026-11-25T00:00:00Z", "server #520 test", copy.hold.clone());
    std::fs::write(dir.join(MANIFEST_FILE), serde_json::to_vec_pretty(&manifest).unwrap()).unwrap();
    failed
}

/// Cold-verify `dir`, confirm the hold is still this copy's, write the
/// receipt and release the hold. Without a verified copy and a confirmed
/// hold no receipt is written; the hold is released either way.
async fn finish(router: &Router, copy: &Copy, dir: &Path) -> Result<Value, Value> {
    let report = archive::verify_contents(dir);
    let confirmed = json_request(router, "GET", &format!("{HOLD}?owner={}", copy.owner), "admin-token", Value::Null).await;
    let result = if report["outcome"] != "verified" {
        Err(report)
    } else if confirmed.0 != StatusCode::OK || confirmed.1["active"] != true
        // The same acquisition, not a later re-acquire by the same owner.
        || confirmed.1["acquired_at"] != copy.hold["acquired_at"]
    {
        Err(confirmed.1)
    } else {
        let mut hold = copy.hold.clone();
        hold["confirmed_at"] = confirmed.1["database_time"].clone();
        hold["expires_at"] = confirmed.1["expires_at"].clone();
        let receipt = archive::receipt(&report, "2026-11-25T00:00:01Z", hold).unwrap();
        std::fs::write(dir.join(RECEIPT_FILE), serde_json::to_vec_pretty(&receipt).unwrap()).unwrap();
        Ok(receipt)
    };
    let (status, released) = hold(router, "release", copy.owner).await;
    assert_eq!(status, StatusCode::OK, "{released}");
    result
}

/// `durable-workflow-server history-archive verify <dir>`, as its own
/// process with nothing but the directory: (succeeded, report).
async fn console_verify(dir: &Path) -> (bool, Value) {
    let mut command = std::process::Command::new(env!("CARGO_BIN_EXE_durable-workflow-server"));
    command.env_clear().env("DW_RUST_EXPERIMENTAL", "1").arg("history-archive").arg("verify").arg(dir);
    let output = tokio::task::spawn_blocking(move || command.output().unwrap()).await.unwrap();
    (output.status.success(), serde_json::from_slice(&output.stdout).unwrap_or(Value::Null))
}

fn codes(report: &Value) -> Vec<String> {
    report["findings"].as_array().unwrap().iter().map(|finding| finding["code"].as_str().unwrap().to_owned()).collect()
}

/// A terminated run whose input and signal are stored by reference:
/// (run id, input reference, signal reference).
async fn archived_run(router: &Router, workflow: &str) -> (String, Value, Value) {
    let input = upload(router, "default", &format!("{workflow} input")).await;
    let signal = upload(router, "default", &format!("{workflow} signal")).await;
    let (status, started) = ns_request(router, "POST", "/api/workflows", "default", json!({"workflow_id": workflow,
        "workflow_type": "echo", "task_queue": workflow, "input": {"codec": "avro", "external_payload": input}})).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    let (status, body) = ns_request(router, "POST", &format!("/api/workflows/{workflow}/signal/approve"), "default",
        json!({"input": {"codec": "avro", "external_payload": signal}})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    let (status, body) = ns_request(router, "POST", &format!("/api/workflows/{workflow}/terminate"), "default",
        json!({"reason": "archive"})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    (started["run_id"].as_str().unwrap().to_owned(), input, signal)
}

#[tokio::test]
async fn a_held_archive_verifies_after_retention_cleanup_and_without_its_payload_store() {
    let temp = tempfile::tempdir().unwrap();
    let root = temp.path().join("objects");
    let (database, runtime, router) = setup(ExternalPayloadConfig::default(), &root).await;
    let (run, input, signal) = archived_run(&router, "archived").await;
    let dir = temp.path().join("archive");

    let copying = begin(&router, OWNER, "archived", &run).await.unwrap();
    // Each object once, with every export path that names it.
    let ids: Vec<&str> = copying.plan.objects.iter().map(|object| object.reference.reference_id.as_str()).collect();
    assert_eq!(ids, [input["reference_id"].as_str().unwrap(), signal["reference_id"].as_str().unwrap()]);
    assert!(copying.plan.objects[0].paths.contains(&"payloads.arguments.data".to_owned()), "{:?}", copying.plan.objects[0].paths);
    assert!(copying.plan.objects[1].paths.iter().any(|path| path.starts_with("signals.0.")), "{:?}", copying.plan.objects[1].paths);
    assert_eq!(copy(&router, &copying, &dir).await, Vec::<String>::new());
    let receipt = finish(&router, &copying, &dir).await.unwrap();
    assert_eq!(receipt["schema"], archive::RECEIPT_SCHEMA);
    assert_eq!(receipt["object_count"], 2);
    assert_eq!(receipt["hold"]["owner"], OWNER);
    assert!(receipt["hold"]["confirmed_at"].is_string(), "{receipt}");
    let manifest: Value = serde_json::from_slice(&std::fs::read(dir.join(MANIFEST_FILE)).unwrap()).unwrap();
    assert_eq!(manifest["identity"]["workflow_id"], "archived");
    assert_eq!(manifest["identity"]["run_id"], json!(run));
    assert_eq!(manifest["scope"]["complete_backup"], false);
    assert_eq!(manifest["objects"][0]["size_bytes"], input["size_bytes"]);
    assert_eq!(manifest["objects"][0]["sha256"], input["sha256"]);
    // The copy released its hold.
    let (status, body) = json_request(&router, "GET", HOLD, "admin-token", Value::Null).await;
    assert_eq!((status, &body["active"]), (StatusCode::OK, &json!(false)), "{body}");

    // Retention prunes the run and reclaims both objects at the source.
    let (status, body) = ns_request(&router, "POST", "/api/system/retention/pass", "default", json!({"run_ids": [run]})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["results"][0]["outcome"], "pruned", "{body}");
    for reference in [&input, &signal] {
        assert!(!object(&root, reference).exists());
        assert_eq!(registered(&database, reference).await, 0);
    }
    // A post-prune export is refused, not archived as complete.
    match begin(&router, OTHER, "archived", &run).await {
        Err(blockers) => assert!(blockers.as_array().unwrap().contains(&json!("history_pruned")), "{blockers}"),
        Ok(_) => panic!("a pruned run's export was archived"),
    }

    // With the Server closed and its payload store gone, the archive verifies
    // from its own files, in process and through the offline command.
    drop(router);
    runtime.close().await;
    std::fs::remove_dir_all(&root).unwrap();
    let report = archive::verify(&dir);
    assert_eq!(report["outcome"], "verified", "{report}");
    assert_eq!((report["verified_objects"].clone(), report["object_bytes"].clone()),
        (json!(2), json!(input["size_bytes"].as_i64().unwrap() + signal["size_bytes"].as_i64().unwrap())));
    let (ok, report) = console_verify(&dir).await;
    assert!(ok, "{report}");
    assert_eq!(report["outcome"], "verified");
    // The archived bytes are the exact Avro frames the run recorded.
    let bytes = std::fs::read(dir.join(OBJECTS_DIR).join(input["reference_id"].as_str().unwrap())).unwrap();
    let decoded = durable_workflow_server::codec::ValueCodec::new().unwrap().decode(std::str::from_utf8(&bytes).unwrap()).unwrap();
    assert!(matches!(&decoded, Payload::Array(items) if matches!(items.as_slice(), [Payload::String(text)] if text == "archived input")), "{decoded:?}");

    // A damaged object is reported, and the command fails.
    let damaged = dir.join(OBJECTS_DIR).join(signal["reference_id"].as_str().unwrap());
    let mut bytes = std::fs::read(&damaged).unwrap();
    bytes[0] ^= 1;
    std::fs::write(&damaged, bytes).unwrap();
    let (ok, report) = console_verify(&dir).await;
    assert!(!ok);
    assert_eq!(report["outcome"], "incomplete", "{report}");
    assert_eq!(codes(&report), ["object_digest_mismatch"]);
    database.remove().await;
}

#[tokio::test]
async fn retention_cannot_prune_a_run_while_its_archive_holds_the_hold() {
    let temp = tempfile::tempdir().unwrap();
    let root = temp.path().join("objects");
    let (database, runtime, router) = setup(ExternalPayloadConfig::default(), &root).await;
    let (run, input, _) = archived_run(&router, "held").await;
    let dir = temp.path().join("archive");

    let copying = begin(&router, OWNER, "held", &run).await.unwrap();
    // A prune between the export and the copy fails and keeps everything.
    let (status, body) = ns_request(&router, "POST", "/api/system/retention/pass", "default", json!({"run_ids": [run]})).await;
    assert_eq!(status, StatusCode::MULTI_STATUS, "{body}");
    assert_eq!(body["results"][0]["outcome"], "error", "{body}");
    assert!(object(&root, &input).exists());
    assert_eq!(registered(&database, &input).await, 1);
    // Another backup or archive cannot take the hold meanwhile.
    match begin(&router, OTHER, "held", &run).await {
        Err(body) => assert_eq!(body["reason"], "backup_hold_owned_elsewhere", "{body}"),
        Ok(_) => panic!("a second copy took an active hold"),
    }
    assert_eq!(copy(&router, &copying, &dir).await, Vec::<String>::new());
    finish(&router, &copying, &dir).await.unwrap();
    assert_eq!(archive::verify(&dir)["outcome"], "verified");

    // Once the hold is released retention proceeds.
    let (status, body) = ns_request(&router, "POST", "/api/system/retention/pass", "default", json!({"run_ids": [run]})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["results"][0]["outcome"], "pruned", "{body}");
    assert_eq!(archive::verify(&dir)["outcome"], "verified");
    drop(router);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_lapsed_hold_or_a_lost_object_never_yields_a_receipt() {
    let temp = tempfile::tempdir().unwrap();
    let root = temp.path().join("objects");
    let (database, runtime, router) = setup(ExternalPayloadConfig::default(), &root).await;
    let (run, input, signal) = archived_run(&router, "lapsed").await;

    // The hold lapses during the copy: the bytes verify, but no receipt.
    let dir = temp.path().join("lapsed");
    let copying = begin(&router, OWNER, "lapsed", &run).await.unwrap();
    assert_eq!(copy(&router, &copying, &dir).await, Vec::<String>::new());
    database.execute("UPDATE runtime_external_payload_backup_hold SET expires_at='2000-01-01 00:00:00'").await;
    let refusal = finish(&router, &copying, &dir).await.unwrap_err();
    assert_eq!(refusal["reason"], "backup_hold_not_owned", "{refusal}");
    assert!(!dir.join(RECEIPT_FILE).exists());
    assert_eq!(archive::verify_contents(&dir)["outcome"], "verified");
    let report = archive::verify(&dir);
    assert_eq!(report["outcome"], "incomplete", "{report}");
    assert_eq!(codes(&report), ["receipt_missing"]);

    // The provider loses an object: the copy is incomplete and says which.
    let dir = temp.path().join("lost");
    std::fs::remove_file(object(&root, &signal)).unwrap();
    let copying = begin(&router, OTHER, "lapsed", &run).await.unwrap();
    let failed = copy(&router, &copying, &dir).await;
    assert_eq!(failed.len(), 1, "{failed:?}");
    assert!(failed[0].starts_with(signal["reference_id"].as_str().unwrap()), "{failed:?}");
    let report = finish(&router, &copying, &dir).await.unwrap_err();
    assert_eq!(report["outcome"], "incomplete", "{report}");
    assert_eq!(codes(&report), ["object_missing"]);
    assert!(!dir.join(RECEIPT_FILE).exists());
    assert!(dir.join(OBJECTS_DIR).join(input["reference_id"].as_str().unwrap()).exists());

    // An open run is refused before anything is copied.
    let (status, body) = ns_request(&router, "POST", "/api/workflows", "default",
        json!({"workflow_id": "open", "workflow_type": "echo", "task_queue": "open",
            "input": {"codec": "avro", "external_payload": input}})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    let refused = begin(&router, "5f0c2a1e-0000-4000-8000-0000000005c3", "open", body["run_id"].as_str().unwrap()).await.err().unwrap();
    assert_eq!(refused, json!(["history_incomplete"]));
    drop(router);
    runtime.close().await;
    database.remove().await;
}
