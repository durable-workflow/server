use super::*;
use sha2::{Digest, Sha256};

/// `json-recursive-ksort-v1` over the bundle without its integrity block.
/// The value with every object's keys sorted, as PHP's recursive `ksort`
/// leaves this ASCII bundle.
fn ksorted(value: &Value) -> Value {
    match value {
        Value::Object(map) => {
            let mut entries: Vec<_> = map.iter().collect();
            entries.sort_by(|left, right| left.0.cmp(right.0));
            Value::Object(entries.into_iter().map(|(key, value)| (key.clone(), ksorted(value))).collect())
        }
        Value::Array(items) => Value::Array(items.iter().map(ksorted).collect()),
        other => other.clone(),
    }
}

fn checksum(bundle: &Value) -> String {
    let mut unsigned = bundle.clone();
    unsigned.as_object_mut().unwrap().remove("integrity");
    let digest = Sha256::digest(serde_json::to_string(&ksorted(&unsigned)).unwrap().as_bytes());
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
    // The stored wait and a timeline point per history event, as
    // `SelectedRunSnapshot` serves them.
    assert_eq!(bundle["selected_run"]["waits_projection_source"], "workflow_run_waits");
    assert_eq!(bundle["selected_run"]["timeline_projection_source"], "workflow_run_timeline_entries_rebuilt");
    let waits = bundle["waits"].as_array().unwrap();
    assert_eq!(waits.len(), 1, "{waits:?}");
    assert_eq!((waits[0]["kind"].clone(), waits[0]["status"].clone(), waits[0]["summary"].clone()),
        (json!("activity"), json!("resolved"), json!("Activity act completed.")));
    assert_eq!((waits[0]["diagnostic_only"].clone(), waits[0]["task_backed"].clone(), waits[0]["timeout_fired_at"].clone()),
        (json!(false), json!(false), Value::Null));
    let timeline = bundle["timeline"].as_array().unwrap();
    assert_eq!(timeline.len(), events.len());
    let point = |kind: &str| timeline.iter().find(|entry| entry["type"] == kind).unwrap_or_else(|| panic!("{kind}"));
    assert_eq!(point("StartAccepted")["summary"], "Start accepted as started_new.");
    assert_eq!(point("SignalReceived")["summary"], "Signal approve received.");
    let scheduled = point("ActivityScheduled");
    assert_eq!((scheduled["kind"].clone(), scheduled["source_kind"].clone(), scheduled["summary"].clone()),
        (json!("activity"), json!("activity_execution"), json!("Scheduled act.")));
    assert_eq!(scheduled["activity"]["status"], "pending");
    assert_eq!(point("ActivityCompleted")["summary"], "Completed act.");
    assert_eq!(point("WorkflowCompleted")["source_id"], json!(run));
    assert_eq!(point("StartAccepted")["command"]["payload"], json!([1]));
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

// A signal wait opened and resumed, and a failed run: the wait's own fields
// and the timeline's summaries follow `RunWaitView` and `HistoryTimeline`.
#[tokio::test]
async fn history_export_serves_signal_waits_and_failures_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!([])).await;
    let (status, started) = request(&app, "POST", "/api/workflows", json!({"workflow_id":"waiting","workflow_type":"echo",
        "task_queue":"test","input":[1]})).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    let run = started["run_id"].as_str().unwrap();
    let task = poll(&app, "worker", "workflow").await;
    let wait = json!({"type":"open_signal_wait","signal_name":"go"});
    assert_eq!(finish_task(&app, &task, json!([wait])).await.0, StatusCode::OK);
    let path = format!("/api/workflows/waiting/runs/{run}/history/export");
    let (_, bundle) = request(&app, "GET", &path, Value::Null).await;
    let open = &bundle["waits"][0];
    assert_eq!((open["kind"].clone(), open["status"].clone(), open["source_status"].clone(), open["summary"].clone()),
        (json!("signal"), json!("open"), json!("waiting"), json!("Waiting for signal go.")));
    assert_eq!(open["signal_wait_id"], open["id"]);
    assert_eq!((open["external_only"].clone(), open["history_authority"].clone(), open["timeout_seconds"].clone()),
        (json!(true), Value::Null, Value::Null));
    assert_eq!(bundle["timeline"][2]["summary"], "Waiting for signal go.");

    let (status, _) = request(&app, "POST", "/api/workflows/waiting/signal/go", json!({"input":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(status, StatusCode::ACCEPTED);
    let task = poll(&app, "worker", "workflow").await;
    let fail = json!({"type":"fail_workflow","message":"run failed","exception_type":"Boom"});
    assert_eq!(finish_task(&app, &task, json!([fail])).await.0, StatusCode::OK);
    let (_, bundle) = request(&app, "GET", &path, Value::Null).await;
    let closed = &bundle["waits"][0];
    assert_eq!((closed["status"].clone(), closed["source_status"].clone(), closed["summary"].clone()),
        (json!("resolved"), json!("applied"), json!("Signal go received.")));
    assert!(closed["signal_id"].is_string(), "{closed}");
    let timeline = bundle["timeline"].as_array().unwrap();
    let failed = timeline.last().unwrap();
    assert_eq!((failed["type"].clone(), failed["summary"].clone()), (json!("WorkflowFailed"), json!("Workflow failed: run failed.")));
    assert_eq!((failed["failure"]["propagation_kind"].clone(), failed["failure"]["handled"].clone()), (json!("terminal"), json!(false)));
    assert_eq!((failed["exception_resolved_class"].clone(), failed["exception_resolution_source"].clone()),
        (json!("RuntimeException"), json!("recorded_class")));
    assert!(timeline.iter().any(|entry| entry["summary"] == "Applied signal go."));
}
