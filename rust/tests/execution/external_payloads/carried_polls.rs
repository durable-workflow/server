use super::*;

#[tokio::test]
async fn carried_activity_answer_keeps_the_normal_external_payload_reference() {
    let temp = tempfile::tempdir().unwrap();
    let (database, runtime, app) = setup(ExternalPayloadConfig::default(), &temp.path().join("objects")).await;
    let registered = json_request(&app, "POST", "/api/worker/register", "worker-token", json!({
        "worker_id":"external-carrier","task_queue":"test","runtime":"php",
        "supported_workflow_types":["echo"],"supported_activity_types":["activity"]})).await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    let created = json_request(&app, "POST", "/api/workflows", "operator-token", json!({
        "workflow_id":"external-carrier","workflow_type":"echo","task_queue":"test","input":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(created.0, StatusCode::CREATED, "{}", created.1);
    let task = json_request(&app, "POST", "/api/worker/workflow-tasks/poll", "worker-token",
        json!({"worker_id":"external-carrier","task_queue":"test","timeout_seconds":0})).await.1["task"].clone();
    let blob = ValueCodec::new().unwrap().encode(&Payload::Array(vec![Payload::String("original external argument".into())])).unwrap();
    let uploaded = request(&app, "POST", "/api/external-payloads/v1", "worker-token", "default",
        Some(&metadata(blob.as_bytes())), Body::from(blob)).await;
    assert_eq!(uploaded.0, StatusCode::CREATED, "{}", String::from_utf8_lossy(&uploaded.2));
    let reference = serde_json::from_slice::<Value>(&uploaded.2).unwrap()["reference"].clone();
    let arguments = json!({"codec":"avro","external_payload":reference});
    let mut body = completion(&task, json!([{"type":"schedule_activity","activity_type":"activity","arguments":arguments}]));
    let carried = json!({"worker_id":"external-carrier","task_queue":"test","timeout_seconds":0,"poll_request_id":"external-original"});
    body["activity_poll"] = carried.clone();
    let done = json_request(&app, "POST", &format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap()), "worker-token", body).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    assert_eq!(done.1["activity_poll"]["leased"], true);
    let answer = &done.1["activity_poll"]["answer"];
    assert_eq!(answer["task"]["arguments"], arguments);
    let ordinary = json_request(&app, "POST", "/api/worker/activity-tasks/poll", "worker-token", carried).await;
    assert_eq!(ordinary.0, StatusCode::OK, "{}", ordinary.1);
    assert_poll_replay(answer, &ordinary.1, "eager dispatch retains the exact normal external reference and original lease");
    assert_eq!(unclaimed(&database).await, 0);
    runtime.close().await;
    drop(app);
    database.remove().await;
}
