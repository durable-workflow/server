use super::*;

// Server #1181: a v2 continuation whose result is stored externally declines
// first, before any proof, and keeps the ordinary completion.
#[tokio::test]
async fn an_external_result_declines_a_v2_continuation() {
    let temp = tempfile::tempdir().unwrap();
    let (database, runtime, app) = setup(ExternalPayloadConfig::default(), &temp.path().join("objects")).await;
    let entry = json!({"supported":true,"minimum_protocol_version":"1.18"});
    let registered = json_request(&app, "POST", "/api/worker/register", "worker-token", json!({
        "worker_id":"external-v2","task_queue":"test","runtime":"php","build_id":"build-1",
        "supported_workflow_types":["echo"],"supported_activity_types":["activity"],
        "capabilities":["sticky_execution","sticky_history_suffix"],
        "capability_manifest":{"local_activities":{"supported":false,"minimum_protocol_version":"1.18"},
            "worker_sessions":{"supported":false,"minimum_protocol_version":"1.18"},"sticky_execution":entry,
            "sticky_history_suffix":{"supported":true,"minimum_protocol_version":"1.18","version":1}}})).await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    let created = json_request(&app, "POST", "/api/workflows", "operator-token", json!({
        "workflow_id":"external-v2","workflow_type":"echo","task_queue":"test","input":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(created.0, StatusCode::CREATED, "{}", created.1);
    let task = json_request(&app, "POST", "/api/worker/workflow-tasks/poll", "worker-token",
        json!({"worker_id":"external-v2","task_queue":"test","timeout_seconds":0})).await.1["task"].clone();
    let sticky = |task: &Value, through: &Value| json!({"worker_id":"external-v2","workflow_id":task["workflow_id"],
        "run_id":task["run_id"],"build_id":"build-1","ttl_seconds":300,"history_last_sequence":through});
    let mut body = completion(&task, json!([{"type":"schedule_activity","activity_type":"activity","arguments":envelope(Payload::Array(vec![]))}]));
    body["sticky_cache"] = sticky(&task, &task["history_last_sequence"]);
    body["activity_poll"] = json!({"worker_id":"external-v2","task_queue":"test","poll_request_id":"external-v2-seed","timeout_seconds":0});
    body["continuation_tail"] = json!({"version":2});
    let done = json_request(&app, "POST", &format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap()), "worker-token", body).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    let activity = done.1["activity_poll"]["answer"]["task"].clone();
    let tail = &done.1["continuation_tail"];
    let through = &tail["last_sequence"];
    let blob = ValueCodec::new().unwrap().encode(&Payload::String("external result".into())).unwrap();
    let uploaded = request(&app, "POST", "/api/external-payloads/v1", "worker-token", "default",
        Some(&metadata(blob.as_bytes())), Body::from(blob)).await;
    assert_eq!(uploaded.0, StatusCode::CREATED, "{}", String::from_utf8_lossy(&uploaded.2));
    let reference = serde_json::from_slice::<Value>(&uploaded.2).unwrap()["reference"].clone();
    let continuation = json!({"lease_owner":"external-v2","activity_attempt_id":activity["activity_attempt_id"],
        "result":{"codec":"avro","external_payload":reference},
        "workflow_poll":{"worker_id":"external-v2","task_queue":"test","poll_request_id":"external-v2-workflow","timeout_seconds":0},
        "continuation":{"version":2,"history_through_sequence":through,
            "history_last_event_id":tail["history_events"].as_array().unwrap().last().unwrap()["id"],
            "sticky_cache":sticky(&activity, through),"commands":[{"type":"start_timer","delay_seconds":60}]}});
    let path = format!("/api/worker/activity-tasks/{}/continuation/complete", activity["task_id"].as_str().unwrap());
    let declined = json_request(&app, "POST", &path, "worker-token", continuation.clone()).await;
    assert_eq!(declined.0, StatusCode::OK, "{}", declined.1);
    assert_eq!(declined.1["recorded"], true, "{}", declined.1);
    assert_eq!(declined.1["continuation"], json!({"version":2,"outcome":"declined","reason":"external_payload"}));
    assert_eq!(declined.1["workflow_poll"]["leased"], true, "the ordinary carried poll: {}", declined.1);
    assert!(declined.1.get("continuation_tail").is_none());
    let retry = json_request(&app, "POST", &path, "worker-token", continuation).await;
    assert_eq!(retry.0, StatusCode::OK, "{}", retry.1);
    assert_eq!(retry.1["recorded"], false);
    assert_eq!(retry.1["continuation"], declined.1["continuation"]);
    runtime.close().await;
    drop(app);
    database.remove().await;
}
