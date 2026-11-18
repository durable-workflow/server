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
        json!({"process_uptime_seconds": 0, "process_id": 17, "_worker_protocol_version": "1.19"})
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
    // Server 2.10.0 records each registration's protocol version with the
    // process metrics (`WorkflowReplayEligibility::PROTOCOL_METRIC`).
    assert_eq!(defaults["process_metrics"], json!({"_worker_protocol_version": "1.19"}));
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
    assert_eq!(after["process_metrics"], json!({"_worker_protocol_version": "1.19"}));
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
    let mut stale = stale;
    assert!(stale.as_object_mut().unwrap().remove("server_capabilities").is_some(), "worker protocol fields");
    assert_eq!(
        stale,
        json!({"task_id":original["task_id"],"activity_attempt_id":original["activity_attempt_id"],
        "outcome":"completed","recorded":false,"reason":"stale_attempt","next_task_id":null,
        "lease_owner":"replaced","activity_status":"running","attempt_status":"expired","task_status":"leased",
        "protocol_version":"1.20"})
    );
    let (status, done) = request(&app, "POST", &path, complete(&replacement)).await;
    assert_eq!(status, StatusCode::OK, "{done}");
    assert_eq!(done["recorded"], true);
    // After the replacement finished the task, the original attempt's late
    // result is still stale, as in PHP, not a conflicting duplicate.
    let (status, late) = request(&app, "POST", &path, complete(&original)).await;
    assert_eq!(status, StatusCode::CONFLICT, "{late}");
    let mut late = late;
    assert!(late.as_object_mut().unwrap().remove("server_capabilities").is_some(), "worker protocol fields");
    assert_eq!(
        late,
        json!({"task_id":original["task_id"],"activity_attempt_id":original["activity_attempt_id"],
        "outcome":"completed","recorded":false,"reason":"stale_attempt","next_task_id":null,
        "lease_owner":"replaced","activity_status":"completed","attempt_status":"expired","task_status":"completed",
        "protocol_version":"1.20"})
    );
    let fail_path = format!(
        "/api/worker/activity-tasks/{}/fail",
        original["task_id"].as_str().unwrap()
    );
    let (status, late_failure) = request(&app, "POST", &fail_path,
        json!({"activity_attempt_id":original["activity_attempt_id"],"lease_owner":"replaced",
            "failure":{"message":"late","type":"RuntimeException"}})).await;
    assert_eq!(status, StatusCode::CONFLICT, "{late_failure}");
    let mut late_failure = late_failure;
    assert!(late_failure.as_object_mut().unwrap().remove("server_capabilities").is_some(), "worker protocol fields");
    assert_eq!(
        late_failure,
        json!({"task_id":original["task_id"],"activity_attempt_id":original["activity_attempt_id"],
        "outcome":"failed","recorded":false,"reason":"stale_attempt","next_task_id":null,"protocol_version":"1.20"})
    );
    // The replacement's own repeated result is stale too, as in published
    // PHP; the receipt names its owner and the closed statuses.
    let (status, again) = request(&app, "POST", &path, complete(&replacement)).await;
    assert_eq!(status, StatusCode::CONFLICT, "{again}");
    assert_eq!((again["reason"].clone(), again["recorded"].clone(), again["attempt_status"].clone()),
        (json!("stale_attempt"), json!(false), json!("completed")), "{again}");
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

/// Server #553: the worker list is paged at 100 in worker id order, with a
/// continuation token, and the status filters apply before the page is cut.
#[tokio::test]
async fn the_worker_list_is_bounded_and_paged() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    for index in 0..101 {
        let body = registration(&format!("page-{index:03}"), "queue-a", json!({}));
        assert_eq!(worker_request(&app, "POST", "/api/worker/register", body).await.0, StatusCode::CREATED);
    }
    let ids = |body: &Value| -> Vec<String> {
        body["workers"].as_array().unwrap().iter().map(|worker| worker["worker_id"].as_str().unwrap().to_owned()).collect()
    };
    let (status, first) = request(&app, "GET", "/api/workers", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{first}");
    assert_eq!(ids(&first), (0..100).map(|index| format!("page-{index:03}")).collect::<Vec<_>>());
    assert_eq!(first["registration_retention_seconds"], 86_400);
    let token = first["next_page_token"].as_str().unwrap().to_owned();
    let (status, last) = request(&app, "GET", &format!("/api/workers?next_page_token={token}"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{last}");
    assert_eq!(ids(&last), ["page-100"]);
    assert_eq!(last["next_page_token"], Value::Null);

    // Smaller pages walk the same order without gaps or repeats.
    let mut walked = Vec::new();
    let mut token: Option<String> = None;
    loop {
        let path = match &token {
            Some(token) => format!("/api/workers?task_queue=queue-a&page_size=40&next_page_token={token}"),
            None => "/api/workers?task_queue=queue-a&page_size=40".to_owned(),
        };
        let (status, page) = request(&app, "GET", &path, Value::Null).await;
        assert_eq!(status, StatusCode::OK, "{page}");
        assert!(page["workers"].as_array().unwrap().len() <= 40);
        walked.extend(ids(&page));
        match page["next_page_token"].as_str() {
            Some(next) => token = Some(next.to_owned()),
            None => break,
        }
    }
    assert_eq!(walked, (0..101).map(|index| format!("page-{index:03}")).collect::<Vec<_>>());

    // Stale registrations leave the live pages; status=stale pages them alone.
    database.execute("UPDATE workflow_worker_registrations SET last_heartbeat_at='2000-01-01 00:00:00' WHERE worker_id IN ('page-001','page-002','page-003')").await;
    let (_, live) = request(&app, "GET", "/api/workers?status=active&page_size=2", Value::Null).await;
    assert_eq!(ids(&live), ["page-000", "page-004"]);
    let (_, stale) = request(&app, "GET", "/api/workers?status=stale&page_size=2", Value::Null).await;
    assert_eq!(ids(&stale), ["page-001", "page-002"]);
    let token = stale["next_page_token"].as_str().unwrap();
    let (_, stale) = request(&app, "GET", &format!("/api/workers?status=stale&page_size=2&next_page_token={token}"), Value::Null).await;
    assert_eq!(ids(&stale), ["page-003"]);
    assert_eq!(stale["next_page_token"], Value::Null);

    for size in ["0", "101", "-1", "ten"] {
        let (status, body) = request(&app, "GET", &format!("/api/workers?page_size={size}"), Value::Null).await;
        assert_eq!((status, body["reason"].clone()), (StatusCode::UNPROCESSABLE_ENTITY, json!("invalid_page_size")), "{size}");
    }
    let (status, body) = request(&app, "GET", "/api/workers?next_page_token=%25%25", Value::Null).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::BAD_REQUEST, json!("invalid_page_token")));
    runtime.close().await;
    database.remove().await;
}

/// Server #553: registrations with no heartbeat for the retention (24 hours
/// by default) are deleted by the maintenance pass and by later
/// registrations. Live, draining and recently stale workers stay, as does a
/// silent worker that still holds an unexpired lease. A pinned build whose
/// workers all expired still reports its pending task with no compatible
/// worker.
#[tokio::test]
async fn silent_registrations_expire_after_retention_and_pinned_builds_stay_visible() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let pinned = start(&app, "expiry-pinned-v1").await;
    let leased = start(&app, "expiry-leased").await;
    for (worker, build) in [("expiry-live", "v2"), ("expiry-draining", "v2"), ("expiry-recent", "v2"),
        ("expiry-crashed-a", "v1"), ("expiry-crashed-b", "v1"), ("expiry-leaseholder", "v2"), ("expiry-inline", "v1")] {
        let body = json!({"worker_id": worker, "task_queue": "test", "runtime": "php", "build_id": build,
            "supported_workflow_types": ["echo"], "supported_activity_types": []});
        assert_eq!(worker_request(&app, "POST", "/api/worker/register", body).await.0, StatusCode::CREATED);
    }
    let hour_ago = (chrono::Utc::now() - chrono::Duration::hours(1)).format("%Y-%m-%d %H:%M:%S").to_string();
    database.execute("UPDATE workflow_worker_registrations SET status='draining' WHERE worker_id='expiry-draining'").await;
    database.execute(sqlx::AssertSqlSafe(format!(
        "UPDATE workflow_worker_registrations SET last_heartbeat_at='{hour_ago}' WHERE worker_id='expiry-recent'"))).await;
    database.execute("UPDATE workflow_worker_registrations SET last_heartbeat_at='2000-01-01 00:00:00',status='draining' WHERE worker_id IN ('expiry-crashed-a','expiry-leaseholder')").await;
    database.execute("UPDATE workflow_worker_registrations SET last_heartbeat_at='2000-01-01 00:00:00' WHERE worker_id='expiry-crashed-b'").await;
    let pinned_run = pinned["run_id"].as_str().unwrap();
    database.execute(sqlx::AssertSqlSafe(format!(
        "UPDATE workflow_tasks SET compatibility='v1' WHERE workflow_run_id='{pinned_run}' AND task_type='workflow'"))).await;
    let leased_run = leased["run_id"].as_str().unwrap();
    database.execute(sqlx::AssertSqlSafe(format!(
        "UPDATE workflow_tasks SET status='leased',lease_owner='expiry-leaseholder',leased_at='2026-01-01 00:00:00',lease_expires_at='2037-01-01 00:00:00' WHERE workflow_run_id='{leased_run}' AND task_type='workflow'"))).await;

    runtime.retention_maintenance().await.unwrap();
    let remaining = |body: Value| -> Vec<String> {
        let mut ids: Vec<String> = body["workers"].as_array().unwrap().iter()
            .map(|worker| worker["worker_id"].as_str().unwrap().to_owned()).collect();
        ids.sort();
        ids
    };
    assert_eq!(remaining(request(&app, "GET", "/api/workers", Value::Null).await.1),
        ["expiry-draining", "expiry-inline", "expiry-live"]);
    assert_eq!(remaining(request(&app, "GET", "/api/workers?status=stale", Value::Null).await.1),
        ["expiry-leaseholder", "expiry-recent"]);
    for worker in ["expiry-crashed-a", "expiry-crashed-b"] {
        let (status, body) = request(&app, "GET", &format!("/api/workers/{worker}"), Value::Null).await;
        assert_eq!((status, body["reason"].clone()), (StatusCode::NOT_FOUND, json!("worker_not_found")), "{worker}");
        let (status, body) = request(&app, "POST", "/api/worker/heartbeat", json!({"worker_id": worker})).await;
        assert_eq!((status, body["reason"].clone()), (StatusCode::NOT_FOUND, json!("worker_not_registered")), "{worker}");
    }
    // Expiry retires the registration's incarnation, as deregistration does.
    assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM dw_worker_registration_incarnations WHERE worker_id IN ('expiry-crashed-a','expiry-crashed-b') AND status='active'").await, 0);
    assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM dw_worker_registration_incarnations WHERE worker_id='expiry-leaseholder' AND status='active'").await, 1);

    // Build v1 lost its workers: its pinned task still shows, with no
    // compatible worker, and v1 is not reported as having stale workers.
    database.execute("UPDATE workflow_worker_registrations SET last_heartbeat_at='2000-01-01 00:00:00' WHERE worker_id='expiry-inline'").await;
    let (status, builds) = request(&app, "GET", "/api/task-queues/test/build-ids", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{builds}");
    let v1 = builds["build_ids"].as_array().unwrap().iter().find(|entry| entry["build_id"] == "v1").unwrap().clone();
    assert_eq!(v1["stale_worker_count"], 1, "{v1}");

    // A later registration removes registrations past retention inline.
    let body = json!({"worker_id": "expiry-new", "task_queue": "test", "runtime": "php", "build_id": "v3",
        "supported_workflow_types": ["echo"], "supported_activity_types": []});
    assert_eq!(worker_request(&app, "POST", "/api/worker/register", body).await.0, StatusCode::CREATED);
    let (_, builds) = request(&app, "GET", "/api/task-queues/test/build-ids", Value::Null).await;
    let v1 = builds["build_ids"].as_array().unwrap().iter().find(|entry| entry["build_id"] == "v1").unwrap().clone();
    assert_eq!((v1["rollout_status"].clone(), v1["total_worker_count"].clone(), v1["active_worker_count"].clone()),
        (json!("no_workers"), json!(0), json!(0)), "{v1}");
    assert_eq!(v1["pending_workflow_tasks"], json!({"status": "no_compatible_worker",
        "operator_visible_signal": "no_compatible_worker",
        "message": "This build id has 1 pending workflow task but no active compatible worker.",
        "total_count": 1, "ready_count": 1, "leased_count": 0}));
    assert_eq!(remaining(request(&app, "GET", "/api/workers?status=stale", Value::Null).await.1),
        ["expiry-leaseholder", "expiry-recent"]);

    // The expired worker id registers again as a new worker.
    let body = json!({"worker_id": "expiry-crashed-a", "task_queue": "test", "runtime": "php", "build_id": "v1",
        "supported_workflow_types": ["echo"], "supported_activity_types": []});
    assert_eq!(worker_request(&app, "POST", "/api/worker/register", body).await.0, StatusCode::CREATED);
    assert_eq!(request(&app, "GET", "/api/workers/expiry-crashed-a", Value::Null).await.1["status"], "active");
    runtime.close().await;
    database.remove().await;
}
