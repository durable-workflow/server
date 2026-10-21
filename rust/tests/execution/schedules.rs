use super::*;
use std::time::Duration;

fn action() -> Value {
    json!({"workflow_type":"echo","task_queue":"test",
        "input":envelope(Payload::Array(vec![Payload::Long(9007199254740993)])),
        "execution_timeout_seconds":3600,"run_timeout_seconds":600})
}

async fn create(app: &Router, schedule: &str, every: &str, max_runs: Option<i64>) {
    let mut body = json!({"schedule_id":schedule,"spec":{"intervals":[{"every":every}],"timezone":"UTC"},
        "action":action(),"overlap_policy":"skip","jitter_seconds":0});
    if let Some(max) = max_runs {
        body["max_runs"] = json!(max);
    }
    let created = request(app, "POST", "/api/schedules", body).await;
    assert_eq!(created.0, StatusCode::CREATED, "{}", created.1);
    assert_eq!(
        created.1,
        json!({"schedule_id":schedule,"outcome":"created"})
    );
}

async fn audit(app: &Router, schedule: &str) -> Vec<Value> {
    let mut events = Vec::new();
    let mut after = None;
    loop {
        let mut path = format!("/api/schedules/{schedule}/history?limit=2");
        if let Some(cursor) = after {
            path.push_str(&format!("&after_sequence={cursor}"));
        }
        let page = request(app, "GET", &path, Value::Null).await;
        assert_eq!(page.0, StatusCode::OK, "{}", page.1);
        let rows = page.1["events"].as_array().unwrap();
        assert!(rows.len() <= 2, "requested schedule history page size");
        events.extend(rows.iter().cloned());
        match page.1["next_cursor"].as_i64() {
            Some(next) => {
                assert!(!rows.is_empty());
                assert!(after.is_none_or(|previous| next > previous));
                after = Some(next);
            }
            None => break,
        }
    }
    for (index, event) in events.iter().enumerate() {
        assert_eq!(event["sequence"], json!(index + 1));
    }
    events
}

async fn complete_original(app: &Router, worker: &str, schedule: &str, trigger: &Value) {
    let workflow = &trigger["workflow_id"];
    let run = &trigger["run_id"];
    assert!(!workflow.as_str().unwrap().is_empty());
    assert!(!run.as_str().unwrap().is_empty());
    assert_ne!(workflow, schedule);
    let task = poll(app, worker, "workflow").await;
    assert_eq!(&task["workflow_id"], workflow);
    assert_eq!(&task["run_id"], run);
    assert_eq!(task["workflow_type"], "echo");
    assert_eq!(task["workflow_task_attempt"], 1);
    let history = run_history(app, workflow, run).await;
    assert_eq!(history.as_array().unwrap().len(), 3);
    assert_eq!(history[2]["event_type"], "ScheduleTriggered");
    assert_eq!(history[2]["payload"]["schedule_id"], schedule);
    assert_eq!(history[2]["payload"]["trigger_number"], 1);
    let completed = finish_task(
        app,
        &task,
        json!([{"type":"complete_workflow",
        "result":envelope(Payload::Long(9007199254740993))}]),
    )
    .await;
    assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
    assert_eq!(completed.1["recorded"], true);
    assert_eq!(completed.1["run_status"], "completed");
    let duplicate = finish_task(
        app,
        &task,
        json!([{"type":"complete_workflow",
        "result":envelope(Payload::Long(9007199254740993))}]),
    )
    .await;
    assert_eq!(duplicate.0, StatusCode::OK, "{}", duplicate.1);
    assert_eq!(duplicate.1["recorded"], false);
    assert_eq!(duplicate.1["run_status"], "completed");
    let description = request(
        app,
        "GET",
        &format!(
            "/api/workflows/{}/runs/{}",
            workflow.as_str().unwrap(),
            run.as_str().unwrap()
        ),
        Value::Null,
    )
    .await;
    assert_eq!(description.0, StatusCode::OK);
    assert_eq!(description.1["status"], "completed");
    assert_eq!(description.1["input"], json!([9007199254740993i64]));
    assert_eq!(description.1["output"], json!(9007199254740993i64));
    let codec = ValueCodec::new().unwrap();
    assert_eq!(
        codec
            .decode(description.1["input_envelope"]["blob"].as_str().unwrap())
            .unwrap(),
        Payload::Array(vec![Payload::Long(9007199254740993)])
    );
    assert_eq!(
        codec
            .decode(description.1["output_envelope"]["blob"].as_str().unwrap())
            .unwrap(),
        Payload::Long(9007199254740993)
    );
    assert_eq!(
        run_history(app, workflow, run)
            .await
            .as_array()
            .unwrap()
            .len(),
        4
    );
    assert!(poll(app, worker, "workflow").await.is_null());
    let cleanup_worker = format!("{worker}-idle-control");
    register(app, &cleanup_worker, json!(["echo"]), json!([])).await;
    assert!(poll(app, &cleanup_worker, "workflow").await.is_null());
    let removed = request(
        app,
        "DELETE",
        &format!("/api/workers/{cleanup_worker}"),
        Value::Null,
    )
    .await;
    assert_eq!(removed.0, StatusCode::OK, "{}", removed.1);
    assert_eq!(
        removed.1,
        json!({"worker_id":cleanup_worker,"outcome":"deregistered","recovered_workflow_task_count":0})
    );
    let absent = request(
        app,
        "DELETE",
        &format!("/api/worker/registrations/{cleanup_worker}"),
        Value::Null,
    )
    .await;
    assert_eq!(absent.0, StatusCode::NOT_FOUND);
    assert_eq!(absent.1["reason"], "worker_not_found");
}

#[tokio::test]
async fn manual_schedule_preserves_paused_skip_original_authored_run_and_deleted_audit() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "schedule-worker", json!(["echo"]), json!([])).await;
    let schedule = "manual-original";
    create(&app, schedule, "P1D", None).await;
    let original = audit(&app, schedule).await;
    assert_eq!(original.len(), 1);
    assert_eq!(original[0]["event_type"], "ScheduleCreated");
    assert_eq!(original[0]["payload"]["action"], action());
    let path = format!("/api/schedules/{schedule}");
    let paused = request(&app, "POST", &format!("{path}/pause"), json!({})).await;
    assert_eq!(
        paused,
        (
            StatusCode::OK,
            json!({"schedule_id":schedule,"outcome":"paused"})
        )
    );
    let skipped = request(&app, "POST", &format!("{path}/trigger"), json!({})).await;
    assert_eq!(
        skipped,
        (
            StatusCode::OK,
            json!({"schedule_id":schedule,"outcome":"skipped","reason":"status_not_triggerable"})
        )
    );
    assert!(poll(&app, "schedule-worker", "workflow").await.is_null());
    let before = audit(&app, schedule).await;
    assert_eq!(before.len(), 3);
    assert_eq!(before[2]["event_type"], "ScheduleTriggerSkipped");
    assert_eq!(before[2]["payload"]["skipped_trigger_count"], 1);
    let resumed = request(&app, "POST", &format!("{path}/resume"), json!({})).await;
    assert_eq!(
        resumed,
        (
            StatusCode::OK,
            json!({"schedule_id":schedule,"outcome":"resumed"})
        )
    );
    let triggered = request(&app, "POST", &format!("{path}/trigger"), json!({})).await;
    assert_eq!(triggered.0, StatusCode::OK, "{}", triggered.1);
    assert_eq!(triggered.1["outcome"], "triggered");
    assert_eq!(triggered.1["schedule_id"], schedule);
    complete_original(&app, "schedule-worker", schedule, &triggered.1).await;
    let active = request(&app, "GET", &path, Value::Null).await;
    assert_eq!(active.0, StatusCode::OK);
    assert_eq!(active.1["status"], "active");
    assert_eq!(active.1["fires_count"], 1);
    assert_eq!(active.1["info"]["skipped_trigger_count"], 1);
    let deleted = request(&app, "DELETE", &path, Value::Null).await;
    assert_eq!(
        deleted,
        (
            StatusCode::OK,
            json!({"schedule_id":schedule,"outcome":"deleted"})
        )
    );
    for (method, endpoint) in [("GET", path.clone()), ("POST", format!("{path}/trigger"))] {
        let refusal = request(&app, method, &endpoint, json!({})).await;
        assert_eq!(refusal.0, StatusCode::NOT_FOUND);
        assert_eq!(refusal.1["reason"], "schedule_not_found");
    }
    let fresh = audit(&app, schedule).await;
    assert_eq!(
        fresh
            .iter()
            .map(|event| event["event_type"].as_str().unwrap())
            .collect::<Vec<_>>(),
        [
            "ScheduleCreated",
            "SchedulePaused",
            "ScheduleTriggerSkipped",
            "ScheduleResumed",
            "ScheduleTriggered",
            "ScheduleDeleted"
        ]
    );
    assert_eq!(&fresh[..3], before.as_slice());
    assert_eq!(fresh[4]["workflow_run_id"], triggered.1["run_id"]);
    assert_eq!(fresh[5]["payload"]["reason"], "deleted");
    assert_eq!(fresh[5]["payload"]["schedule"]["fires_count"], 1);
    assert!(poll(&app, "schedule-worker", "workflow").await.is_null());
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn fixed_rate_original_occurrence_exhausts_quota_before_authored_completion() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "schedule-worker", json!(["echo"]), json!([])).await;
    let schedule = "fixed-rate-original";
    create(&app, schedule, "PT2S", Some(1)).await;
    let original = audit(&app, schedule).await;
    let occurrence = chrono::DateTime::parse_from_rfc3339(
        original[0]["payload"]["next_fire_at"].as_str().unwrap(),
    )
    .unwrap();
    let deadline = tokio::time::Instant::now() + Duration::from_secs(8);
    let fired = loop {
        let events = audit(&app, schedule).await;
        if let Some(trigger) = events
            .iter()
            .find(|event| event["event_type"] == "ScheduleTriggered")
        {
            break trigger.clone();
        }
        assert!(
            tokio::time::Instant::now() < deadline,
            "normal scheduler did not fire original occurrence"
        );
        tokio::time::sleep(Duration::from_millis(50)).await;
    };
    assert_eq!(
        chrono::DateTime::parse_from_rfc3339(fired["payload"]["occurrence_time"].as_str().unwrap())
            .unwrap(),
        occurrence
    );
    assert!(
        chrono::DateTime::parse_from_rfc3339(fired["recorded_at"].as_str().unwrap()).unwrap()
            >= occurrence
    );
    let exhausted = audit(&app, schedule).await;
    assert_eq!(exhausted.len(), 3);
    assert_eq!(exhausted[2]["event_type"], "ScheduleDeleted");
    assert_eq!(exhausted[2]["payload"]["reason"], "max_runs_exhausted");
    assert_eq!(exhausted[2]["payload"]["schedule"]["status"], "deleted");
    assert_eq!(exhausted[2]["payload"]["schedule"]["fires_count"], 1);
    let trigger =
        json!({"workflow_id":fired["workflow_instance_id"],"run_id":fired["workflow_run_id"]});
    complete_original(&app, "schedule-worker", schedule, &trigger).await;
    tokio::time::sleep(Duration::from_secs(4)).await;
    assert_eq!(audit(&app, schedule).await, exhausted);
    assert!(poll(&app, "schedule-worker", "workflow").await.is_null());
    let deleted = request(
        &app,
        "POST",
        &format!("/api/schedules/{schedule}/trigger"),
        json!({}),
    )
    .await;
    assert_eq!(deleted.0, StatusCode::NOT_FOUND);
    assert_eq!(deleted.1["reason"], "schedule_not_found");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn scheduled_occurrence_survives_restart_and_competing_nodes_admit_one_original_run() {
    let database = TestDatabase::new().await;
    let initial = database.open().await.unwrap();
    let app = router(initial.clone());
    register(&app, "schedule-recovery-worker", json!(["echo"]), json!([])).await;
    create(&app, "schedule-recovery", "PT2S", Some(1)).await;
    let original = audit(&app, "schedule-recovery").await;
    initial.close().await;
    let (a, b) = tokio::join!(database.open(), database.open());
    let (a, b) = (a.unwrap(), b.unwrap());
    let app_a = router(a.clone());
    let app_b = router(b.clone());
    let deadline = tokio::time::Instant::now() + Duration::from_secs(8);
    let fired = loop {
        let events = audit(&app_a, "schedule-recovery").await;
        if events.len() == 3 {
            break events;
        }
        assert!(
            tokio::time::Instant::now() < deadline,
            "reopened schedulers did not fire: {events:?}"
        );
        tokio::time::sleep(Duration::from_millis(50)).await;
    };
    assert_eq!(fired[0], original[0]);
    assert_eq!(fired[1]["event_type"], "ScheduleTriggered");
    assert_eq!(
        chrono::DateTime::parse_from_rfc3339(
            fired[1]["payload"]["occurrence_time"].as_str().unwrap()
        )
        .unwrap(),
        chrono::DateTime::parse_from_rfc3339(
            original[0]["payload"]["next_fire_at"].as_str().unwrap()
        )
        .unwrap()
    );
    let trigger = json!({"workflow_id":fired[1]["workflow_instance_id"],"run_id":fired[1]["workflow_run_id"]});
    complete_original(
        &app_b,
        "schedule-recovery-worker",
        "schedule-recovery",
        &trigger,
    )
    .await;
    tokio::time::sleep(Duration::from_millis(600)).await;
    assert_eq!(audit(&app_a, "schedule-recovery").await, fired);
    assert!(
        poll(&app_a, "schedule-recovery-worker", "workflow")
            .await
            .is_null()
    );
    a.close().await;
    b.close().await;
    database.remove().await;
}

#[tokio::test]
async fn schedules_are_namespace_scoped_and_fire_into_their_own_namespace() {
    use super::namespaces::{create as create_namespace, scoped};
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    create_namespace(&app, "alpha").await;
    let body = json!({"schedule_id":"shared","spec":{"cron_expressions":["0 3 1 1 *"],"timezone":"Europe/Budapest"},
        "action":action(),"overlap_policy":"allow_all"});
    for namespace in ["alpha", "default"] {
        let created = scoped(&app, namespace, "POST", "/api/schedules", body.clone()).await;
        assert_eq!(created.0, StatusCode::CREATED, "{}", created.1);
    }
    let described = scoped(&app, "alpha", "GET", "/api/schedules/shared", Value::Null).await;
    assert_eq!(described.0, StatusCode::OK, "{}", described.1);
    assert_eq!(described.1["namespace"], "alpha");
    // 03:00 in Budapest on January 1 is 02:00 UTC.
    assert!(described.1["next_fire_at"].as_str().unwrap().ends_with("-01-01T02:00:00+00:00"));
    // Deleting one namespace's schedule leaves the other's.
    let deleted = scoped(&app, "alpha", "DELETE", "/api/schedules/shared", Value::Null).await;
    assert_eq!(deleted.0, StatusCode::OK, "{}", deleted.1);
    let gone = scoped(&app, "alpha", "GET", "/api/schedules/shared", Value::Null).await;
    assert_eq!(gone.0, StatusCode::NOT_FOUND);
    assert_eq!(gone.1["message"], "Schedule [shared] not found in namespace [alpha].");
    let kept = request(&app, "GET", "/api/schedules/shared", Value::Null).await;
    assert_eq!((kept.0, kept.1["status"].clone()), (StatusCode::OK, json!("active")));
    // A trigger starts its run in the schedule's namespace only.
    let other = scoped(&app, "alpha", "POST", "/api/schedules",
        json!({"schedule_id":"alpha-only","spec":{"intervals":[{"every":"P1D"}]},"action":action()})).await;
    assert_eq!(other.0, StatusCode::CREATED, "{}", other.1);
    let triggered = scoped(&app, "alpha", "POST", "/api/schedules/alpha-only/trigger", json!({})).await;
    assert_eq!(triggered.1["outcome"], "triggered", "{}", triggered.1);
    let workflow = triggered.1["workflow_id"].as_str().unwrap();
    let path = format!("/api/workflows/{workflow}");
    assert_eq!(scoped(&app, "alpha", "GET", &path, Value::Null).await.0, StatusCode::OK);
    assert_eq!(request(&app, "GET", &path, Value::Null).await.0, StatusCode::NOT_FOUND);
    let missing = request(&app, "POST", "/api/schedules/alpha-only/trigger", json!({})).await;
    assert_eq!(missing.0, StatusCode::NOT_FOUND);
    runtime.close().await;
    database.remove().await;
}

fn ids(page: &Value) -> Vec<&str> {
    page["schedules"]
        .as_array()
        .unwrap()
        .iter()
        .map(|item| item["schedule_id"].as_str().unwrap())
        .collect()
}

async fn list(app: &Router, query: &str) -> (StatusCode, Value) {
    request(app, "GET", &format!("/api/schedules?{query}"), Value::Null).await
}

fn encoded(text: &str) -> String {
    form_urlencoded::byte_serialize(text.as_bytes()).collect()
}

#[tokio::test]
async fn schedules_list_update_and_backfill_as_published_php() {
    use super::namespaces::{create as create_namespace, scoped};
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    for (schedule, workflow_type, queue, extra) in [
        ("alpha", "echo", "q-a", json!({"note":"first"})),
        ("beta", "other", "q-b", json!({"paused":true,"spec":{"cron_expressions":["0 3 * * *"],"timezone":"Europe/Budapest"}})),
        ("gamma", "echo", "q-b", json!({"note":"x AND y"})),
        ("delta", "echo", "q-d", json!({})),
    ] {
        let mut body = json!({"schedule_id":schedule,"spec":{"intervals":[{"every":"P1D"}]},
            "action":{"workflow_type":workflow_type,"task_queue":queue,"input":action()["input"]}});
        for (field, value) in extra.as_object().unwrap() {
            body[field] = value.clone();
        }
        let created = request(&app, "POST", "/api/schedules", body).await;
        assert_eq!(created.0, StatusCode::CREATED, "{}", created.1);
    }

    // Newest first, with PHP's filters and visibility predicates.
    let all = list(&app, "").await;
    assert_eq!((all.0, ids(&all.1)), (StatusCode::OK, vec!["delta", "gamma", "beta", "alpha"]));
    assert_eq!((all.1["schedule_count"].clone(), all.1["next_page_token"].clone()), (json!(4), Value::Null));
    let beta = &all.1["schedules"][2];
    assert_eq!((beta["paused"].clone(), beta["workflow_type"].clone()), (json!(true), json!("other")));
    assert_eq!(ids(&list(&app, "status=paused").await.1), ["beta"]);
    assert_eq!(ids(&list(&app, "workflow_type=echo").await.1), ["delta", "gamma", "alpha"]);
    for (query, expected) in [
        ("WorkflowType = \"echo\" AND TaskQueue = 'q-b'", vec!["gamma"]),
        ("Note = 'x AND y'", vec!["gamma"]),
        ("ScheduleId = 'alpha' and Status = \"active\"", vec!["alpha"]),
        ("Status = 'active'", vec!["delta", "gamma", "alpha"]),
    ] {
        let page = list(&app, &format!("query={}", encoded(query))).await;
        assert_eq!((page.0, ids(&page.1)), (StatusCode::OK, expected), "{query}: {}", page.1);
    }
    let refused = list(&app, &format!("query={}", encoded("Status != 'active'"))).await;
    assert_eq!((refused.0, refused.1["reason"].clone()), (StatusCode::UNPROCESSABLE_ENTITY, json!("unsupported_schedule_visibility_predicate")));
    let registered = request(&app, "POST", "/api/search-attributes", json!({"name":"Team","type":"keyword"})).await;
    assert!(registered.0.is_success(), "{}", registered.1);
    assert!(ids(&list(&app, &format!("query={}", encoded("Team = 'x'"))).await.1).is_empty());
    let typed = list(&app, &format!("query={}", encoded("Team = 5"))).await;
    assert_eq!(typed.1["message"], "Schedule visibility field [Team] requires a quoted string literal.");
    let invalid = list(&app, "page_size=0&bogus=1").await;
    assert_eq!((invalid.0, invalid.1["reason"].clone()), (StatusCode::UNPROCESSABLE_ENTITY, json!("unsupported_schedule_list_filter")));
    let invalid = list(&app, "page_size=x").await;
    assert_eq!(invalid.1["errors"], json!({"page_size":["The page size field must be an integer."]}));

    // Signed continuation tokens bound to the filters and namespace.
    let first = list(&app, "page_size=2").await;
    assert_eq!(ids(&first.1), ["delta", "gamma"]);
    let token = encoded(first.1["next_page_token"].as_str().unwrap());
    let second = list(&app, &format!("page_size=2&next_page_token={token}")).await;
    assert_eq!((ids(&second.1), second.1["next_page_token"].clone()), (vec!["beta", "alpha"], Value::Null));
    let mismatch = list(&app, &format!("page_size=2&status=active&next_page_token={token}")).await;
    assert_eq!((mismatch.0, mismatch.1["reason"].clone()), (StatusCode::CONFLICT, json!("schedule_page_token_filter_mismatch")));
    assert_eq!(mismatch.1["last_safe_cursor"]["schedule_id"], "gamma");
    let tampered = list(&app, &format!("next_page_token={}", encoded(&format!("{}0", &first.1["next_page_token"].as_str().unwrap()[..20])))).await;
    assert_eq!((tampered.0, tampered.1["reason"].clone()), (StatusCode::BAD_REQUEST, json!("malformed_schedule_page_token")));
    create_namespace(&app, "other").await;
    let foreign = scoped(&app, "other", "GET", &format!("/api/schedules?page_size=2&next_page_token={token}"), Value::Null).await;
    assert_eq!((foreign.0, foreign.1["reason"].clone()), (StatusCode::FORBIDDEN, json!("schedule_page_token_namespace_mismatch")));
    let newest = list(&app, "page_size=1").await;
    let stale = encoded(newest.1["next_page_token"].as_str().unwrap());
    assert_eq!(request(&app, "DELETE", "/api/schedules/delta", Value::Null).await.0, StatusCode::OK);
    let gone = list(&app, &format!("page_size=1&next_page_token={stale}")).await;
    assert_eq!((gone.0, gone.1["reason"].clone()), (StatusCode::CONFLICT, json!("stale_schedule_page_token")));

    // Updates merge the action and record PHP's changed fields.
    let updated = request(&app, "PUT", "/api/schedules/alpha",
        json!({"spec":{"cron_expressions":["0 4 * * *"],"timezone":"America/New_York"},
            "action":{"task_queue":"q-z","workflow_execution_timeout":60},"memo":{"k":"v"},"max_runs":5})).await;
    assert_eq!(updated, (StatusCode::OK, json!({"schedule_id":"alpha","outcome":"updated"})));
    let alpha = request(&app, "GET", "/api/schedules/alpha", Value::Null).await.1;
    assert_eq!((alpha["action"]["workflow_type"].clone(), alpha["action"]["task_queue"].clone()), (json!("echo"), json!("q-z")));
    assert_eq!(alpha["action"]["execution_timeout_seconds"], 60);
    assert_eq!((alpha["max_runs"].clone(), alpha["remaining_actions"].clone()), (json!(5), json!(5)));
    assert!(alpha["next_fire_at"].as_str().unwrap().contains("T08:00:00+00:00") || alpha["next_fire_at"].as_str().unwrap().contains("T09:00:00+00:00"));
    let events = audit(&app, "alpha").await;
    assert_eq!(events.last().unwrap()["payload"]["changed_fields"], json!(["spec","action","memo","max_runs","remaining_actions"]));
    assert_eq!(ids(&list(&app, &format!("query={}", encoded("TaskQueue = 'q-z'"))).await.1), ["alpha"]);
    let invalid = request(&app, "PUT", "/api/schedules/alpha", json!({"overlap_policy":"bad","jitter_seconds":4000})).await;
    assert_eq!(invalid.0, StatusCode::UNPROCESSABLE_ENTITY);
    assert_eq!(request(&app, "PUT", "/api/schedules/missing", json!({})).await.0, StatusCode::NOT_FOUND);

    // Backfill fires each past occurrence once, in PHP's formats.
    let created = request(&app, "POST", "/api/schedules", json!({"schedule_id":"bf",
        "spec":{"cron_expressions":["0 3 * * *"],"intervals":[{"every":"P1D","offset":"PT6H"}],"timezone":"Europe/Budapest"},
        "action":action(),"overlap_policy":"allow_all"})).await;
    assert_eq!(created.0, StatusCode::CREATED, "{}", created.1);
    let range = json!({"start_time":"2026-03-27T00:00:00Z","end_time":"2026-03-30 06:00:00+02:00"});
    let backfill = request(&app, "POST", "/api/schedules/bf/backfill", range.clone()).await;
    assert_eq!(backfill.0, StatusCode::OK, "{}", backfill.1);
    let times: Vec<&str> = backfill.1["results"].as_array().unwrap().iter().map(|result| {
        assert_eq!(result["outcome"], "started");
        result["fire_time"].as_str().unwrap()
    }).collect();
    assert_eq!(times, ["2026-03-27T03:00:00+01:00", "2026-03-27T06:00:00+00:00", "2026-03-28T03:00:00+01:00",
        "2026-03-28T06:00:00+00:00", "2026-03-29T03:00:00+02:00", "2026-03-29T06:00:00+00:00", "2026-03-30T03:00:00+02:00"]);
    let again = request(&app, "POST", "/api/schedules/bf/backfill", range).await;
    assert!(again.1["results"].as_array().unwrap().iter().all(|result| result == &json!({"fire_time":result["fire_time"],"outcome":"skipped"})));
    let refused = request(&app, "POST", "/api/schedules/bf/backfill", json!({"start_time":"2026-04-01 +1 day","end_time":"2026-03-01"})).await;
    assert_eq!(refused.1["errors"], json!({"start_time":["The start time field must be a valid date."]}));
    let reversed = request(&app, "POST", "/api/schedules/bf/backfill", json!({"start_time":"2026-03-02","end_time":"2026-03-01"})).await;
    assert_eq!(reversed, (StatusCode::UNPROCESSABLE_ENTITY, json!({"message":"end_time must be after start_time.","reason":"invalid_time_range"})));
    let created = request(&app, "POST", "/api/schedules", json!({"schedule_id":"bf-quota",
        "spec":{"intervals":[{"every":"P1D"}]},"action":action(),"overlap_policy":"buffer_one","max_runs":2})).await;
    assert_eq!(created.0, StatusCode::CREATED, "{}", created.1);
    let quota = request(&app, "POST", "/api/schedules/bf-quota/backfill",
        json!({"start_time":"2026-01-01T00:00:00Z","end_time":"2026-01-06T00:00:00Z"})).await;
    assert_eq!(quota.1["fires_attempted"], 2, "{}", quota.1);
    assert_eq!(request(&app, "GET", "/api/schedules/bf-quota", Value::Null).await.0, StatusCode::NOT_FOUND);
    runtime.close().await;
    database.remove().await;
}
