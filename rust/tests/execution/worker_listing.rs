use super::*;

async fn worker_request(
    app: &Router,
    method: &str,
    path: &str,
    body: Value,
) -> (StatusCode, Value) {
    request(app, method, path, body).await
}

fn registration(worker: &str, queue: &str, extra: Value) -> Value {
    let mut body = json!({"worker_id": worker, "task_queue": queue, "runtime": "php",
        "sdk_version": "durable-workflow-php/2.2.6", "supported_workflow_types": ["listing"],
        "supported_activity_types": ["listing-activity"]});
    for (key, value) in extra.as_object().unwrap() {
        body[key] = value.clone();
    }
    body
}

async fn ours(app: &Router, query: &str) -> Vec<Value> {
    let (status, body) = request(app, "GET", &format!("/api/workers{query}"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["stale_after_seconds"], 30);
    let mut workers: Vec<Value> = body["workers"].as_array().unwrap().clone();
    workers.sort_by(|a, b| a["worker_id"].as_str().cmp(&b["worker_id"].as_str()));
    workers
}

#[tokio::test]
async fn workers_are_listed_with_their_registration_and_heartbeat_facts() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let fingerprint = "sha256:".to_owned() + &"1".repeat(64);
    let first = registration(
        "listing-first",
        "queue-a",
        json!({
        "max_concurrent_workflow_tasks": 3, "max_concurrent_activity_tasks": 5,
        "max_concurrent_worker_sessions": 2, "build_id": "build-λ",
        "workflow_definition_fingerprints": {"listing": fingerprint}}),
    );
    let (status, receipt) =
        worker_request(&app, "POST", "/api/worker/register", first.clone()).await;
    assert_eq!(status, StatusCode::CREATED, "{receipt}");
    assert_eq!(receipt["heartbeat_interval_seconds"], 10);
    let second = registration(
        "listing-second",
        "queue-b",
        json!({"supported_activity_types": []}),
    );
    assert_eq!(
        worker_request(&app, "POST", "/api/worker/register", second)
            .await
            .0,
        StatusCode::CREATED
    );
    let (status, heartbeat) = worker_request(&app, "POST", "/api/worker/heartbeat", json!({
        "worker_id": "listing-first", "task_slots": {"workflow_available": 2, "activity_available": 9,
        "session_available": 1}, "process_metrics": {"process_id": 17, "process_uptime_seconds": 0}})).await;
    assert_eq!(status, StatusCode::OK, "{heartbeat}");
    assert_eq!(heartbeat["acknowledged"], true);
    assert_eq!(heartbeat["stale_after_seconds"], 30);
    assert_eq!(heartbeat["heartbeat_interval_seconds"], 10);

    let listed = ours(&app, "").await;
    assert_eq!(listed.len(), 2);
    let worker = &listed[0];
    assert_eq!(worker["worker_id"], "listing-first");
    assert_eq!(
        worker["workflow_definition_fingerprints"],
        json!({"listing": fingerprint})
    );
    assert_eq!(worker["workflow_command_contracts"], json!([]));
    assert_eq!(worker["build_id"], "build-λ");
    assert_eq!(
        worker["task_slots"],
        json!({"workflow_available": 2, "activity_available": 5,
        "session_available": 1, "workflow_capacity": 3, "activity_capacity": 5, "session_capacity": 2})
    );
    assert_eq!(
        worker["process_metrics"],
        json!({"process_uptime_seconds": 0, "process_id": 17})
    );
    assert_eq!(worker["status"], "active");
    assert_eq!(worker["heartbeat_interval_seconds"], Value::Null);
    let defaults = &listed[1];
    assert_eq!(defaults["workflow_definition_fingerprints"], json!([]));
    assert_eq!(defaults["supported_activity_types"], json!([]));
    assert_eq!(
        defaults["task_slots"],
        json!({"workflow_available": null, "activity_available": null,
        "session_available": null, "workflow_capacity": 100, "activity_capacity": 100, "session_capacity": 10})
    );
    assert_eq!(defaults["process_metrics"], Value::Null);
    assert_eq!(ours(&app, "?task_queue=queue-a").await.len(), 1);
    assert_eq!(
        ours(&app, "?task_queue=0").await.len(),
        2,
        "PHP ignores a falsy queue"
    );
    assert_eq!(ours(&app, "?status=%20ACTIVE%20").await.len(), 2);
    assert!(ours(&app, "?status=stale").await.is_empty());
    assert!(ours(&app, "?status=draining").await.is_empty());

    let (status, described) = request(&app, "GET", "/api/workers/listing-first", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{described}");
    assert_eq!(described["stale_after_seconds"], 30);
    assert!(described["updated_at"].as_str().is_some());
    let (status, missing) = request(&app, "GET", "/api/workers/listing-missing", Value::Null).await;
    assert_eq!(status, StatusCode::NOT_FOUND);
    assert_eq!(missing["reason"], "worker_not_found");
    assert_eq!(
        missing["message"],
        "Worker [listing-missing] not found in namespace [default]."
    );

    // A changed or omitted definition is refused and changes nothing.
    for (fingerprints, reason) in [
        (
            json!({"listing": "sha256:changed"}),
            "workflow_definition_changed",
        ),
        (
            json!({"other": "sha256:other"}),
            "workflow_definition_fingerprint_missing",
        ),
    ] {
        let mut changed = first.clone();
        changed["workflow_definition_fingerprints"] = fingerprints;
        let (status, refusal) = worker_request(&app, "POST", "/api/worker/register", changed).await;
        assert_eq!(status, StatusCode::CONFLICT, "{refusal}");
        assert_eq!(refusal["reason"], reason);
        assert_eq!(refusal["workflow_type"], "listing");
        assert_eq!(
            request(&app, "GET", "/api/workers/listing-first", Value::Null)
                .await
                .1,
            described
        );
    }
    // Omitting the fingerprints keeps the advertised one and clears what the
    // registration does not report.
    let mut omitted = first.clone();
    omitted
        .as_object_mut()
        .unwrap()
        .remove("workflow_definition_fingerprints");
    assert_eq!(
        worker_request(&app, "POST", "/api/worker/register", omitted)
            .await
            .0,
        StatusCode::CREATED
    );
    let after = request(&app, "GET", "/api/workers/listing-first", Value::Null)
        .await
        .1;
    assert_eq!(
        after["workflow_definition_fingerprints"],
        json!({"listing": fingerprint})
    );
    assert_eq!(after["task_slots"]["workflow_available"], Value::Null);
    assert_eq!(after["process_metrics"], Value::Null);
    assert_eq!(after["registered_at"], described["registered_at"]);

    // A heartbeat changes only what it reports.
    assert_eq!(
        worker_request(
            &app,
            "POST",
            "/api/worker/heartbeat",
            json!({"worker_id": "listing-first",
        "task_slots": {"session_available": 7}, "heartbeat_interval_seconds": 15})
        )
        .await
        .0,
        StatusCode::OK
    );
    let after = request(&app, "GET", "/api/workers/listing-first", Value::Null)
        .await
        .1;
    assert_eq!(after["task_slots"]["session_available"], 2);
    assert_eq!(after["task_slots"]["workflow_available"], Value::Null);
    assert_eq!(after["heartbeat_interval_seconds"], 15);
    let (status, unregistered) = worker_request(
        &app,
        "POST",
        "/api/worker/heartbeat",
        json!({"worker_id": "listing-missing"}),
    )
    .await;
    assert_eq!(status, StatusCode::NOT_FOUND);
    assert_eq!(unregistered["reason"], "worker_not_registered");
    for invalid in [
        json!({"max_concurrent_workflow_tasks": 0, "max_concurrent_activity_tasks": 0}),
        json!({"task_slots": {"workflow_available": -1}}),
        json!({"heartbeat_interval_seconds": 0}),
    ] {
        let body = registration("listing-invalid", "queue-a", invalid);
        assert_eq!(
            worker_request(&app, "POST", "/api/worker/register", body)
                .await
                .0,
            StatusCode::UNPROCESSABLE_ENTITY
        );
    }
    assert_eq!(
        request(&app, "GET", "/api/workers/listing-invalid", Value::Null)
            .await
            .0,
        StatusCode::NOT_FOUND
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_silent_worker_is_listed_only_as_stale() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap().with_query_task_config(
        durable_workflow_server::runtime::QueryTaskConfig {
            worker_stale_after_seconds: 1,
            ..Default::default()
        },
    );
    let app = router(runtime.clone());
    let body = registration("listing-silent", "queue-a", json!({}));
    assert_eq!(
        worker_request(&app, "POST", "/api/worker/register", body)
            .await
            .0,
        StatusCode::CREATED
    );
    // MySQL rounds these timestamps to whole seconds.
    tokio::time::sleep(std::time::Duration::from_millis(2100)).await;
    let (_, listed) = request(&app, "GET", "/api/workers", Value::Null).await;
    assert_eq!(listed["workers"], json!([]));
    assert_eq!(listed["stale_after_seconds"], 1);
    let (_, stale) = request(&app, "GET", "/api/workers?status=stale", Value::Null).await;
    assert_eq!(stale["workers"][0]["worker_id"], "listing-silent");
    assert_eq!(stale["workers"][0]["status"], "stale");
    assert_eq!(
        request(&app, "GET", "/api/workers/listing-silent", Value::Null)
            .await
            .1["status"],
        "stale"
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn named_namespaces_list_only_their_own_workers() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    for name in ["alpha", "beta"] {
        namespaces::create(&app, name).await;
    }
    let (status, body) = namespaces::scoped(
        &app,
        "alpha",
        "POST",
        "/api/worker/register",
        registration("listing-alpha", "queue-a", json!({})),
    )
    .await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    let (status, alpha) =
        namespaces::scoped(&app, "alpha", "GET", "/api/workers", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{alpha}");
    assert_eq!(alpha["workers"][0]["namespace"], "alpha");
    let (_, beta) = namespaces::scoped(&app, "beta", "GET", "/api/workers", Value::Null).await;
    assert_eq!(beta["workers"], json!([]));
    let (status, missing) = namespaces::scoped(
        &app,
        "beta",
        "GET",
        "/api/workers/listing-alpha",
        Value::Null,
    )
    .await;
    assert_eq!(status, StatusCode::NOT_FOUND);
    assert_eq!(
        missing["message"],
        "Worker [listing-alpha] not found in namespace [beta]."
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_replacement_registration_releases_the_activity_lease_at_once() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "replaced", json!(["echo"]), json!(["echo-activity"])).await;
    let started = start(&app, "replaced-peer").await;
    let task = poll(&app, "replaced", "workflow").await;
    let scheduled = finish_task(
        &app,
        &task,
        json!([{"type":"schedule_activity","activity_type":"echo-activity",
        "arguments":envelope(Payload::Array(vec![Payload::Long(7)])),
        "retry_policy":{"max_attempts":1,"backoff_seconds":[]}}]),
    )
    .await;
    assert_eq!(scheduled.0, StatusCode::OK, "{}", scheduled.1);
    let original = poll(&app, "replaced", "activity").await;
    assert_eq!(original["attempt_number"], 1);
    // The same worker_id registering again replaces a process that may have
    // died holding the lease; the task does not wait out the activity lease.
    register(&app, "replaced", json!(["echo"]), json!(["echo-activity"])).await;
    let replacement = poll(&app, "replaced", "activity").await;
    assert_eq!(replacement["task_id"], original["task_id"]);
    assert_eq!(replacement["attempt_number"], 2);
    assert_ne!(
        replacement["activity_attempt_id"],
        original["activity_attempt_id"]
    );
    let complete = |claim: &Value| {
        json!({"activity_attempt_id":claim["activity_attempt_id"],"lease_owner":"replaced",
            "result":envelope(Payload::Long(7))})
    };
    let path = format!(
        "/api/worker/activity-tasks/{}/complete",
        original["task_id"].as_str().unwrap()
    );
    let (status, stale) = request(&app, "POST", &path, complete(&original)).await;
    assert_eq!(status, StatusCode::CONFLICT, "{stale}");
    assert_eq!(
        stale,
        json!({"task_id":original["task_id"],"activity_attempt_id":original["activity_attempt_id"],
        "outcome":"completed","recorded":false,"reason":"stale_attempt","next_task_id":null,
        "lease_owner":"replaced","activity_status":"running","attempt_status":"expired","task_status":"leased"})
    );
    let (status, done) = request(&app, "POST", &path, complete(&replacement)).await;
    assert_eq!(status, StatusCode::OK, "{done}");
    assert_eq!(done["recorded"], true);
    let history = run_history(&app, &json!("replaced-peer"), &started["run_id"]).await;
    let started_attempts: Vec<_> = history
        .as_array()
        .unwrap()
        .iter()
        .filter(|event| event["event_type"] == "ActivityStarted")
        .map(|event| event["payload"]["attempt_number"].clone())
        .collect();
    assert_eq!(started_attempts, vec![json!(1), json!(2)]);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_workflow_task_lease_lasts_the_published_sixty_seconds() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "lease-worker", json!(["echo"]), json!([])).await;
    start(&app, "lease-sixty").await;
    let remaining = |expires: &Value| {
        let expires = chrono::DateTime::parse_from_rfc3339(expires.as_str().unwrap()).unwrap();
        (expires.with_timezone(&chrono::Utc) - chrono::Utc::now()).num_milliseconds()
    };
    let task = poll(&app, "lease-worker", "workflow").await;
    let claimed = remaining(&task["lease_expires_at"]);
    assert!((58_000..=60_500).contains(&claimed), "{claimed} ms");
    let (status, heartbeat) = request(&app, "POST",
        &format!("/api/worker/workflow-tasks/{}/heartbeat", task["task_id"].as_str().unwrap()),
        json!({"lease_owner": task["lease_owner"], "workflow_task_attempt": task["workflow_task_attempt"]})).await;
    assert_eq!(status, StatusCode::OK, "{heartbeat}");
    let renewed = remaining(&heartbeat["lease_expires_at"]);
    assert!((58_000..=60_500).contains(&renewed), "{renewed} ms");
    runtime.close().await;
    database.remove().await;
}
