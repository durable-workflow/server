use super::*;

fn registration(owner: &str, queue: &str) -> Value {
    json!({"worker_id":owner,"task_queue":queue,"runtime":"php",
        "supported_workflow_types":["echo"],"supported_activity_types":["activity"]})
}

fn assert_granted(result: &Value) -> &Value {
    assert_eq!(result["leased"], true);
    assert_eq!(result.as_object().unwrap().len(), 2);
    let answer = &result["answer"];
    let vectors: Value = serde_json::from_str(include_str!("../../../resources/platform-protocol-specs/carried-poll-v1-vectors.json")).unwrap();
    let keys: std::collections::BTreeSet<_> = answer.as_object().unwrap().keys().map(String::as_str).collect();
    let expected: std::collections::BTreeSet<_> = vectors["answer_contract"]["keys"].as_array().unwrap().iter().map(|key| key.as_str().unwrap()).collect();
    assert_eq!(keys, expected);
    assert_eq!(answer["poll_status"], "leased");
    assert_eq!(answer["protocol_version"], "1.20");
    assert!(answer["server_capabilities"].is_object());
    assert!(chrono::DateTime::parse_from_rfc3339(answer["server_time"].as_str().unwrap()).is_ok());
    answer
}

#[tokio::test]
async fn normalization_pending_http_refusal_has_the_closed_worker_envelope() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let registered = request(&app, "POST", "/api/worker/register", registration("owner", "normalization-http")).await;
    assert_eq!(registered.0, StatusCode::CREATED);
    let started = request(&app, "POST", "/api/workflows", json!({"workflow_id":"normalization-http-ready",
        "workflow_type":"echo","task_queue":"normalization-http","input":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(started.0, StatusCode::CREATED);
    // A still-live opaque predecessor binding forces the real ordinary
    // route through its pre-claim refusal and worker-response wrapper.
    let expires = chrono::Utc::now().timestamp() + 60;
    let key_column = if matches!(&database, TestDatabase::MySql { .. }) { "`key`" } else { "\"key\"" };
    database.execute(sqlx::AssertSqlSafe(format!(
        "INSERT INTO dw_query_cache ({key_column},value,expiration) VALUES ('dw-native-workflow-poll:http-predecessor','{{\"task_kinds\":[\"workflow\"]}}',{expires})"
    ))).await;
    let poll = json!({"worker_id":"owner","task_queue":"normalization-http","poll_request_id":"original-id","timeout_seconds":0});
    let refused = request(&app, "POST", "/api/worker/workflow-tasks/poll", poll.clone()).await;
    let vectors: Value = serde_json::from_str(include_str!("../../../resources/platform-protocol-specs/carried-poll-v1-vectors.json")).unwrap();
    assert_eq!(refused.0, StatusCode::SERVICE_UNAVAILABLE);
    assert_eq!(u64::from(refused.0.as_u16()), vectors["normalization_refusal_contract"]["http_status"].as_u64().unwrap());
    let expected_keys: std::collections::BTreeSet<_> = vectors["normalization_refusal_contract"]["http_keys"].as_array().unwrap()
        .iter().map(|value| value.as_str().unwrap()).collect();
    let actual_keys: std::collections::BTreeSet<_> = refused.1.as_object().unwrap().keys().map(String::as_str).collect();
    assert_eq!(actual_keys, expected_keys, "test the actual route envelope, not a naked runtime error");
    let mut expected = vectors["normalization_refusal_contract"]["http_example"].clone();
    assert!(refused.1["server_capabilities"].is_object());
    for (capability, value) in expected["server_capabilities"].as_object().unwrap() {
        assert_eq!(refused.1["server_capabilities"][capability], *value);
    }
    // The schema permits unrelated capability members. Bind their actual
    // map to the normal registration while comparing every closed field to
    // the same wire example the PHP schema validator consumes.
    expected["server_capabilities"] = registered.1["server_capabilities"].clone();
    assert_eq!(refused.1, expected);
    database.execute(sqlx::AssertSqlSafe(format!("DELETE FROM dw_query_cache WHERE {key_column}='dw-native-workflow-poll:http-predecessor'"))).await;
    let claimed = request(&app, "POST", "/api/worker/workflow-tasks/poll", poll).await;
    assert_eq!(claimed.0, StatusCode::OK);
    assert_eq!(claimed.1["task"]["run_id"], started.1["run_id"]);
    assert_eq!(claimed.1["task"]["workflow_task_attempt"], 1, "the refused route did not take an earlier lease");
    runtime.close().await;
    database.remove().await;
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
                assert_granted(&done.1["activity_poll"]);
                // Treat the initial answer as lost. Repeating the completion
                // cannot allocate a second lease; recover with the original ID.
                let repeated = request(&app, "POST", &path, body).await;
                assert_eq!(repeated.0, StatusCode::OK, "{}", repeated.1);
                assert_eq!(repeated.1["recorded"], false);
                assert_eq!(repeated.1["reason"], "already_completed");
                assert!(repeated.1.get("activity_poll").is_none());
                let first = request(&app, "POST", "/api/worker/activity-tasks/poll", carried.clone()).await;
                assert_eq!(first.0, StatusCode::OK, "{}", first.1);
                assert_poll_replay(assert_granted(&done.1["activity_poll"]), &first.1, "embedded activity answer is the normal poll envelope");
                assert_eq!(first.1["task"]["lease_owner"], owner);
                assert_eq!(first.1["task"]["attempt_number"], 1);
                assert!(first.1["task"]["ready_age_ms"].is_u64());
                let replay = request(&app, "POST", "/api/worker/activity-tasks/poll", carried.clone()).await;
                assert_eq!(replay.0, StatusCode::OK, "{}", replay.1);
                assert_poll_replay(&first.1, &replay.1, "the raw original ID recovers every immutable lease field");
                if id.starts_with("raw-custom-trim-") {
                    let mut distinct = carried.clone();
                    distinct["poll_request_id"] = json!("raw-original");
                    let empty = request(&app, "POST", "/api/worker/activity-tasks/poll", distinct).await;
                    assert_eq!(empty.0, StatusCode::OK, "{}", empty.1);
                    assert!(empty.1["task"].is_null(), "activity identities remain opaque, unlike workflow IDs");
                    let recovered = request(&app, "POST", "/api/worker/activity-tasks/poll", carried.clone()).await;
                    assert_eq!(recovered.0, StatusCode::OK, "{}", recovered.1);
                    assert_poll_replay(&first.1, &recovered.1, "the unchanged raw activity ID still recovers its original receipt");
                }
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
    assert_eq!(seen.len(), 13);
    runtime.close().await;
    drop(app);
    database.remove().await;
}

#[tokio::test]
async fn carried_workflow_vectors_preserve_activity_completion_and_normal_poll_identity() {
    let vectors: Value = serde_json::from_str(include_str!(
        "../../../resources/platform-protocol-specs/carried-poll-v1-vectors.json"
    )).unwrap();
    assert_eq!(vectors["workflow_capability"], "activity_completion_workflow_poll");
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let mut seen = std::collections::BTreeSet::new();
    for vector in vectors["workflow_cases"].as_array().unwrap() {
        let id = vector["id"].as_str().unwrap();
        assert!(seen.insert(id));
        let owner = format!("carrier-{id}");
        let queue = format!("carrier-{id}");
        for worker in [&owner, &format!("other-{id}")] {
            assert_eq!(request(&app, "POST", "/api/worker/register", registration(worker, &queue)).await.0, StatusCode::CREATED);
        }
        let ordinary = json!({"worker_id":owner,"task_queue":queue,"timeout_seconds":0});
        if vector["standalone_activity"] == true {
            let created = request(&app, "POST", "/api/activities", json!({"activity_id":format!("carried-{id}"),
                "activity_type":"activity","task_queue":queue,"input":envelope(Payload::Array(vec![]))})).await;
            assert_eq!(created.0, StatusCode::CREATED, "{}", created.1);
        } else {
            let created = request(&app, "POST", "/api/workflows", json!({"workflow_id":format!("carried-{id}"),
                "workflow_type":"echo","task_queue":queue,"input":envelope(Payload::Array(vec![]))})).await;
            assert_eq!(created.0, StatusCode::CREATED, "{}", created.1);
            let task = request(&app, "POST", "/api/worker/workflow-tasks/poll", ordinary.clone()).await.1["task"].clone();
            let done = finish_task(&app, &task, json!([{"type":"schedule_activity","activity_type":"activity","arguments":envelope(Payload::Array(vec![]))}])).await;
            assert_eq!(done.0, StatusCode::OK, "{}", done.1);
        }
        let task = request(&app, "POST", "/api/worker/activity-tasks/poll", ordinary).await.1["task"].clone();
        assert!(task.is_object());
        let mut body = json!({"lease_owner":owner,"activity_attempt_id":task["activity_attempt_id"],"result":envelope(Payload::Null)});
        let mut carried = vector["workflow_poll"].clone();
        if let Some(fields) = carried.as_object_mut() {
            fields.insert("worker_id".into(), if fields["worker_id"] == "owner" { json!(owner) } else { json!(format!("other-{id}")) });
            fields.insert("task_queue".into(), if fields["task_queue"] == "queue" { json!(queue) } else { json!("different") });
            body["workflow_poll"] = carried.clone();
        }
        let path = format!("/api/worker/activity-tasks/{}/complete", task["task_id"].as_str().unwrap());
        let done = request(&app, "POST", &path, body.clone()).await;
        assert_eq!(done.0, StatusCode::OK, "{id}: {}", done.1);
        assert_eq!(done.1["recorded"], true);
        assert_eq!(done.1["server_capabilities"]["activity_completion_workflow_poll"], true);
        match vector["expected_result"].as_str().unwrap() {
            "omitted" => assert!(done.1.get("workflow_poll").is_none(), "{id}: {}", done.1),
            "empty" => assert_eq!(done.1["workflow_poll"], json!({"leased":false})),
            "leased" => {
                assert_granted(&done.1["workflow_poll"]);
                let retry = request(&app, "POST", &path, body).await;
                assert_eq!(retry.0, StatusCode::CONFLICT, "{}", retry.1);
                assert_eq!(retry.1["reason"], "stale_attempt");
                assert_eq!(retry.1["recorded"], false);
                assert!(retry.1.get("workflow_poll").is_none());
                let first = request(&app, "POST", "/api/worker/workflow-tasks/poll", carried.clone()).await;
                assert_eq!(first.0, StatusCode::OK, "{}", first.1);
                assert_poll_replay(assert_granted(&done.1["workflow_poll"]), &first.1, "embedded workflow answer is the normal poll envelope");
                assert_eq!(first.1["task"]["task_id"], done.1["next_task_id"]);
                assert_eq!(first.1["task"]["task_kind"], "workflow");
                assert_eq!(first.1["task"]["workflow_task_attempt"], 1);
                if id == "workflow-paged" {
                    assert_eq!(first.1["task"]["history_events"].as_array().unwrap().len(), 1);
                    assert!(first.1["task"]["next_history_page_token"].is_string());
                }
                let replay = request(&app, "POST", "/api/worker/workflow-tasks/poll", carried).await;
                assert_eq!(replay.0, StatusCode::OK, "{}", replay.1);
                assert_poll_replay(&first.1, &replay.1, "workflow retry retains the original claim, history and lease fields");
            }
            unexpected => panic!("unknown vector result: {unexpected}"),
        }
    }
    assert_eq!(seen.len(), 11);
    runtime.close().await;
    drop(app);
    database.remove().await;
}

#[tokio::test]
async fn workflow_completion_vectors_deliver_at_most_one_kind_and_pin_omission() {
    let vectors: Value = serde_json::from_str(include_str!(
        "../../../resources/platform-protocol-specs/carried-poll-v1-vectors.json"
    )).unwrap();
    assert_eq!(vectors["workflow_completion_capability"], "workflow_task_completion_workflow_poll");
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let mut seen = std::collections::BTreeSet::new();
    for vector in vectors["paired_cases"].as_array().unwrap() {
        let id = vector["id"].as_str().unwrap();
        assert!(seen.insert(id));
        let owner = format!("paired-{id}");
        let queue = format!("paired-{id}");
        for worker in [&owner, &format!("other-{id}")] {
            assert_eq!(request(&app, "POST", "/api/worker/register", registration(worker, &queue)).await.0, StatusCode::CREATED);
        }
        let start_on_queue = |name: &str| json!({"workflow_id":format!("{id}-{name}"),"workflow_type":"echo","task_queue":queue,"input":envelope(Payload::Array(vec![]))});
        assert_eq!(request(&app, "POST", "/api/workflows", start_on_queue("parent")).await.0, StatusCode::CREATED);
        let ordinary = json!({"worker_id":owner,"task_queue":queue,"timeout_seconds":0});
        let parent = request(&app, "POST", "/api/worker/workflow-tasks/poll", ordinary.clone()).await.1["task"].clone();
        let next = request(&app, "POST", "/api/workflows", start_on_queue("next")).await;
        assert_eq!(next.0, StatusCode::CREATED, "{}", next.1);
        let commands = if vector["schedule_activity"] == true {
            json!([{"type":"schedule_activity","activity_type":"activity","arguments":envelope(Payload::Array(vec![]))}])
        } else { json!([{"type":"record_side_effect","result":envelope(Payload::Null)}]) };
        let mut body = completion(&parent, commands);
        let mut activity = ordinary.clone();
        activity["poll_request_id"] = json!("original-paired-activity");
        let mut workflow = ordinary;
        workflow["poll_request_id"] = json!("original-paired-workflow");
        if vector["foreign"] == "activity" { activity["worker_id"] = json!(format!("other-{id}")); }
        if vector["foreign"] == "workflow" { workflow["worker_id"] = json!(format!("other-{id}")); }
        if vector["activity"] == true { body["activity_poll"] = activity.clone(); }
        body["workflow_poll"] = workflow.clone();
        let path = format!("/api/worker/workflow-tasks/{}/complete", parent["task_id"].as_str().unwrap());
        let done = request(&app, "POST", &path, body.clone()).await;
        assert_eq!(done.0, StatusCode::OK, "{id}: {}", done.1);
        assert_eq!(done.1["recorded"], true);
        assert_eq!(done.1["server_capabilities"]["workflow_task_completion_workflow_poll"], true);
        for (member, expected, poll) in [("activity_poll", &vector["expected_activity"], activity), ("workflow_poll", &vector["expected_workflow"], workflow)] {
            match expected.as_str().unwrap() {
                "omitted" => assert!(done.1.get(member).is_none(), "{id}: {member}: {}", done.1),
                "empty" => assert_eq!(done.1[member], json!({"leased":false})),
                "leased" => {
                    assert_granted(&done.1[member]);
                    let kind = if member == "activity_poll" { "activity" } else { "workflow" };
                    let replay = request(&app, "POST", &format!("/api/worker/{kind}-tasks/poll"), poll).await;
                    assert_eq!(replay.0, StatusCode::OK, "{}", replay.1);
                    assert_poll_replay(assert_granted(&done.1[member]), &replay.1, "paired carrier answer preserves every normal poll field");
                    assert_eq!(replay.1["task"]["lease_owner"], owner);
                    if kind == "workflow" { assert_eq!(replay.1["task"]["run_id"], next.1["run_id"]); }
                }
                unexpected => panic!("unknown paired vector result: {unexpected}"),
            }
        }
        let duplicate = request(&app, "POST", &path, body).await;
        assert_eq!(duplicate.0, StatusCode::OK, "{}", duplicate.1);
        assert_eq!(duplicate.1["recorded"], false);
        assert!(duplicate.1.get("activity_poll").is_none());
        assert!(duplicate.1.get("workflow_poll").is_none());
    }
    assert_eq!(seen.len(), 5);
    runtime.close().await;
    drop(app);
    database.remove().await;
}

#[tokio::test]
async fn workflow_carrier_and_ordinary_polls_share_laravel_id_normalization_in_both_orders() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let vectors: Value = serde_json::from_str(include_str!("../../../resources/platform-protocol-specs/carried-poll-v1-vectors.json")).unwrap();
    assert_eq!(vectors["workflow_request_id_padding"].as_array().unwrap().len(), 3);
    for (index, padding) in vectors["workflow_request_id_padding"].as_array().unwrap().iter().enumerate() {
        let padding = padding.as_str().unwrap();
        for ordinary_first in [false, true] {
            let name = format!("normalized-carrier-{index}-{ordinary_first}");
            let queue = name.clone();
            let owner = name.clone();
            assert_eq!(request(&app, "POST", "/api/worker/register", registration(&owner, &queue)).await.0, StatusCode::CREATED);
            assert_eq!(request(&app, "POST", "/api/workflows", json!({"workflow_id":format!("{name}-parent"),
                "workflow_type":"echo","task_queue":queue,"input":envelope(Payload::Array(vec![]))})).await.0, StatusCode::CREATED);
            let ordinary = json!({"worker_id":owner,"task_queue":queue,"timeout_seconds":0});
            let parent = request(&app, "POST", "/api/worker/workflow-tasks/poll", ordinary.clone()).await.1["task"].clone();
            let next = request(&app, "POST", "/api/workflows", json!({"workflow_id":name,
                "workflow_type":"echo","task_queue":queue,"input":envelope(Payload::Array(vec![]))})).await;
            assert_eq!(next.0, StatusCode::CREATED, "{}", next.1);
            let mut padded = ordinary.clone();
            padded["poll_request_id"] = json!(format!(" \t{padding}logical-id{padding} \t"));
            let mut plain = ordinary;
            plain["poll_request_id"] = json!("logical-id");
            let prior = if ordinary_first {
                let first = request(&app, "POST", "/api/worker/workflow-tasks/poll", padded.clone()).await;
                assert_eq!(first.0, StatusCode::OK, "{}", first.1);
                Some(first.1)
            } else { None };
            let carried = if ordinary_first { plain.clone() } else { padded.clone() };
            let mut body = completion(&parent, json!([{"type":"record_side_effect","result":envelope(Payload::Null)}]));
            body["workflow_poll"] = carried;
            let complete = request(&app, "POST", &format!("/api/worker/workflow-tasks/{}/complete", parent["task_id"].as_str().unwrap()), body).await;
            assert_eq!(complete.0, StatusCode::OK, "{}", complete.1);
            let answer = assert_granted(&complete.1["workflow_poll"]);
            assert_eq!(answer["task"]["run_id"], next.1["run_id"]);
            assert_eq!(answer["task"]["workflow_task_attempt"], 1);
            if let Some(first) = prior { assert_poll_replay(&first, answer, "ordinary to carrier shares one original lease"); }
            for id in [plain, padded] {
                let replay = request(&app, "POST", "/api/worker/workflow-tasks/poll", id).await;
                assert_eq!(replay.0, StatusCode::OK, "{}", replay.1);
                assert_poll_replay(answer, &replay.1, "both workflow spellings recover the same original lease");
            }
        }
    }
    runtime.close().await;
    drop(app);
    database.remove().await;
}

#[tokio::test]
async fn embedded_workflow_history_uses_normal_compression_and_survives_cold_replay() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "compressed-carrier", json!(["echo"]), json!(["activity"])).await;
    start(&app, "compressed-carrier").await;
    let task = poll(&app, "compressed-carrier", "workflow").await;
    assert_eq!(finish_task(&app, &task, json!([{"type":"schedule_activity","activity_type":"activity","arguments":envelope(Payload::Array(vec![]))}])).await.0, StatusCode::OK);
    for index in 0..55 {
        let signal = request(&app, "POST", "/api/workflows/compressed-carrier/signal/note", json!([index])).await;
        assert_eq!(signal.0, StatusCode::ACCEPTED, "{}", signal.1);
    }
    assert_eq!(request(&app, "POST", "/api/worker/heartbeat", json!({"worker_id":"compressed-carrier"})).await.0, StatusCode::OK);
    let activity = poll(&app, "compressed-carrier", "activity").await;
    let carried = json!({"worker_id":"compressed-carrier","task_queue":"test","timeout_seconds":0,
        "poll_request_id":"compressed-original","history_page_size":100,"accept_history_encoding":"gzip,deflate"});
    let before = chrono::Utc::now().timestamp_millis();
    let done = request(&app, "POST", &format!("/api/worker/activity-tasks/{}/complete", activity["task_id"].as_str().unwrap()),
        json!({"lease_owner":"compressed-carrier","activity_attempt_id":activity["activity_attempt_id"],"result":envelope(Payload::Null),"workflow_poll":carried})).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    let answer = assert_granted(&done.1["workflow_poll"]).clone();
    let clock = chrono::DateTime::parse_from_rfc3339(answer["server_time"].as_str().unwrap()).unwrap();
    assert!((before..=chrono::Utc::now().timestamp_millis()).contains(&clock.timestamp_millis()));
    assert!(answer["task"]["history_events"].as_array().unwrap().is_empty());
    assert!(answer["task"]["history_events_compressed"].is_string());
    assert_eq!(answer["task"]["history_events_encoding"], "gzip");
    let normal = request(&app, "POST", "/api/worker/workflow-tasks/poll", carried.clone()).await;
    assert_eq!(normal.0, StatusCode::OK, "{}", normal.1);
    assert_poll_replay(&answer, &normal.1, "compressed answer is the normal page and lease");
    runtime.close().await;
    drop(app);
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let cold = request(&app, "POST", "/api/worker/workflow-tasks/poll", carried).await;
    assert_eq!(cold.0, StatusCode::OK, "{}", cold.1);
    assert_poll_replay(&answer, &cold.1, "cold normal recovery retains the original compressed page, attempt and deadline");
    runtime.close().await;
    drop(app);
    database.remove().await;
}
