use super::*;
use sha2::{Digest, Sha256};

/// `json-recursive-ksort-v1` over the bundle without its integrity block.
fn checksum(bundle: &Value) -> String {
    let mut unsigned = bundle.clone();
    unsigned.as_object_mut().unwrap().remove("integrity");
    let digest = Sha256::digest(serde_json::to_string(&unsigned).unwrap().as_bytes());
    digest.iter().map(|byte| format!("{byte:02x}")).collect()
}

// Published PHP's replay bundle: the run, its history and rows, a payload
// manifest of every Avro frame and an integrity checksum.
#[tokio::test]
async fn history_export_bundles_a_completed_run_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!(["act"])).await;
    let (status, started) = request(&app, "POST", "/api/workflows", json!({"workflow_id":"exported","workflow_type":"echo",
        "task_queue":"test","input":[1],"memo":{"owner":"ops"},"business_key":"order-1"})).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    let (status, _) = request(&app, "POST", "/api/workflows/exported/signal/approve", json!({"input":envelope(Payload::Array(vec![Payload::Long(2)]))})).await;
    assert_eq!(status, StatusCode::ACCEPTED);
    let task = poll(&app, "worker", "workflow").await;
    let schedule = json!({"type":"schedule_activity","activity_type":"act","arguments":envelope(Payload::Array(vec![]))});
    assert_eq!(finish_task(&app, &task, json!([schedule])).await.0, StatusCode::OK);
    let activity = poll(&app, "worker", "activity").await;
    let path = format!("/api/worker/activity-tasks/{}/complete", activity["task_id"].as_str().unwrap());
    let body = json!({"lease_owner":"worker","activity_attempt_id":activity["activity_attempt_id"],"result":envelope(Payload::Long(7))});
    assert_eq!(request(&app, "POST", &path, body).await.0, StatusCode::OK);
    let task = poll(&app, "worker", "workflow").await;
    let complete = json!({"type":"complete_workflow","result":envelope(Payload::Long(9))});
    assert_eq!(finish_task(&app, &task, json!([complete])).await.0, StatusCode::OK);

    let run = started["run_id"].as_str().unwrap();
    let (status, bundle) = request(&app, "GET", &format!("/api/workflows/exported/runs/{run}/history/export"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{bundle}");
    assert_eq!((bundle["schema"].clone(), bundle["schema_version"].clone()), (json!("durable-workflow.v2.history-export"), json!(2)));
    assert_eq!(bundle["history_complete"], true);
    let workflow = &bundle["workflow"];
    assert_eq!((workflow["instance_id"].clone(), workflow["run_id"].clone()), (json!("exported"), json!(run)));
    assert_eq!(workflow["status"], "completed");
    assert_eq!(workflow["business_key"], "order-1");
    assert_eq!(workflow["memo"], json!({"owner":"ops"}));
    assert_eq!(workflow["memo_payload"]["codec"], "avro");
    assert_eq!(bundle["payloads"]["output"], json!({"available":true,"codec":"avro","data":envelope(Payload::Long(9))["blob"]}));
    let events = bundle["history_events"].as_array().unwrap();
    assert_eq!(workflow["last_history_sequence"], json!(events.len()));
    assert!(events.windows(2).all(|pair| pair[0]["sequence"].as_i64() < pair[1]["sequence"].as_i64()));
    assert_eq!(events[0]["type"], "StartAccepted");
    assert_eq!(bundle["commands"][0]["type"], "start");
    assert_eq!(bundle["signals"][0]["name"], "approve");
    let activity = &bundle["activities"][0];
    assert_eq!(activity["history_authority"], "typed_history");
    assert_eq!(activity["status"], "completed");
    assert_eq!(activity["result"], envelope(Payload::Long(7))["blob"]);
    assert_eq!(activity["attempts"].as_array().unwrap().len(), 1);
    assert_eq!(bundle["summary"]["status"], "completed");
    // Every available Avro payload is a single-object frame of the Value schema.
    let entries = bundle["payload_manifest"]["entries"].as_array().unwrap();
    assert!(entries.iter().any(|entry| entry["path"] == "payloads.output.data"));
    for entry in entries.iter().filter(|entry| entry["available"] == true) {
        assert_eq!(entry["avro_framing"], "single_object", "{entry}");
        assert_eq!(entry["writer_schema_fingerprint"], "crc64-avro:e2a33dff55802237", "{entry}");
    }
    assert_eq!(bundle["integrity"]["canonicalization"], "json-recursive-ksort-v1");
    assert_eq!(bundle["integrity"]["checksum"], checksum(&bundle));
    assert!(bundle["integrity"]["signature"].is_null());

    let (status, missing) = request(&app, "GET", "/api/workflows/exported/runs/missing/history/export", Value::Null).await;
    assert_eq!(status, StatusCode::NOT_FOUND, "{missing}");
    assert_eq!(missing["reason"], "run_not_found");
    assert_eq!(missing["run_id"], "missing");
}
