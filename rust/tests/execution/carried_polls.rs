use super::*;

fn registration(owner: &str, queue: &str) -> Value {
    json!({"worker_id":owner,"task_queue":queue,"runtime":"php",
        "supported_workflow_types":["echo"],"supported_activity_types":["activity"]})
}

#[tokio::test]
async fn carried_activity_vectors_and_lost_completion_ack_preserve_the_original_lease() {
    let vectors: Value = serde_json::from_str(include_str!(
        "../../../resources/platform-protocol-specs/carried-poll-v1-vectors.json"
    )).unwrap();
    assert_eq!(vectors["schema"], "durable-workflow.carried-poll-v1");
    assert_eq!(vectors["capability"], "workflow_task_completion_activity_poll");
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let mut seen = std::collections::BTreeSet::new();
    for vector in vectors["cases"].as_array().unwrap() {
        let id = vector["id"].as_str().unwrap();
        assert!(seen.insert(id));
        let owner = format!("carrier-{id}");
        let queue = format!("carrier-{id}");
        for worker in [&owner, &format!("other-{id}")] {
            assert_eq!(request(&app, "POST", "/api/worker/register", registration(worker, &queue)).await.0, StatusCode::CREATED);
        }
        let run = request(&app, "POST", "/api/workflows", json!({"workflow_id":format!("carried-{id}"),
            "workflow_type":"echo","task_queue":queue,"input":envelope(Payload::Array(vec![]))})).await;
        assert_eq!(run.0, StatusCode::CREATED, "{}", run.1);
        let poll = json!({"worker_id":owner,"task_queue":queue,"timeout_seconds":0});
        let task = request(&app, "POST", "/api/worker/workflow-tasks/poll", poll.clone()).await.1["task"].clone();
        assert!(task.is_object());
        let commands = if vector["schedule_activity"] == true {
            json!([{"type":"schedule_activity","activity_type":"activity","arguments":envelope(Payload::Array(vec![]))}])
        } else {
            json!([{"type":"record_side_effect","result":envelope(Payload::Null)}])
        };
        let mut body = completion(&task, commands);
        let mut carried = vector["activity_poll"].clone();
        if let Some(fields) = carried.as_object_mut() {
            fields.insert("worker_id".into(), if fields["worker_id"] == "owner" { json!(owner) } else { json!(format!("other-{id}")) });
            fields.insert("task_queue".into(), if fields["task_queue"] == "queue" { json!(queue) } else { json!("different") });
            body["activity_poll"] = carried.clone();
        }
        let path = format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap());
        let done = request(&app, "POST", &path, body.clone()).await;
        assert_eq!(done.0, StatusCode::OK, "{id}: {}", done.1);
        assert_eq!(done.1["recorded"], true, "{id}: {}", done.1);
        assert_eq!(done.1["server_capabilities"]["workflow_task_completion_activity_poll"], true);
        match vector["expected_result"].as_str().unwrap() {
            "omitted" => assert!(done.1.get("activity_poll").is_none(), "{id}: {}", done.1),
            "empty" => assert_eq!(done.1["activity_poll"], json!({"leased":false}), "{id}"),
            "leased" => {
                assert_eq!(done.1["activity_poll"], json!({"leased":true}), "{id}");
                // Treat the initial answer as lost. Repeating the completion
                // cannot allocate a second lease; recover with the original ID.
                let repeated = request(&app, "POST", &path, body).await;
                assert_eq!(repeated.0, StatusCode::OK, "{}", repeated.1);
                assert_eq!(repeated.1["recorded"], false);
                assert_eq!(repeated.1["reason"], "already_completed");
                assert!(repeated.1.get("activity_poll").is_none());
                let first = request(&app, "POST", "/api/worker/activity-tasks/poll", carried.clone()).await;
                assert_eq!(first.0, StatusCode::OK, "{}", first.1);
                assert_eq!(first.1["task"]["lease_owner"], owner);
                assert_eq!(first.1["task"]["attempt_number"], 1);
                assert!(first.1["task"]["ready_age_ms"].is_u64());
                let replay = request(&app, "POST", "/api/worker/activity-tasks/poll", carried.clone()).await;
                assert_eq!(replay.0, StatusCode::OK, "{}", replay.1);
                assert_poll_replay(&first.1, &replay.1, "the raw original ID recovers every immutable lease field");
                if id == "leased" {
                    database.execute("UPDATE workflow_tasks SET lease_expires_at='2000-01-01 00:00:00.000000' WHERE task_type='activity' AND queue='carrier-leased'").await;
                    let expired = request(&app, "POST", "/api/worker/activity-tasks/poll", carried.clone()).await;
                    assert_eq!(expired.0, StatusCode::CONFLICT, "{}", expired.1);
                    assert_eq!(expired.1["reason"], vectors["recovery"]["expiry"]);
                }
                // A worker re-registration revokes its original activity
                // authority. The old receipt cannot renew it for the new one.
                assert_eq!(request(&app, "POST", "/api/worker/register", registration(&owner, &queue)).await.0, StatusCode::CREATED);
                let replaced = request(&app, "POST", "/api/worker/activity-tasks/poll", carried).await;
                assert_eq!(replaced.0, StatusCode::CONFLICT, "{}", replaced.1);
                assert_eq!(replaced.1["reason"], "poll_cached_task_expired");
            }
            unexpected => panic!("unknown vector result: {unexpected}"),
        }
    }
    assert_eq!(seen.len(), 10);
    runtime.close().await;
    drop(app);
    database.remove().await;
}
