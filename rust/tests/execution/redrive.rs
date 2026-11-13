use super::*;

pub(super) const FINGERPRINT: &str = "sha256:abababababababababababababababababababababababababababababababab";

fn kinds(history: &Value) -> Vec<String> {
    history.as_array().unwrap().iter().map(|event| event["event_type"].as_str().unwrap().to_owned()).collect()
}

pub(super) async fn complete_activity(app: &Router, task: &Value) {
    let path = format!("/api/worker/activity-tasks/{}/complete", task["task_id"].as_str().unwrap());
    let done = request(app, "POST", &path, json!({"lease_owner":task["lease_owner"],
        "activity_attempt_id":task["activity_attempt_id"],"result":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
}

/// A run whose second activity failed and whose workflow then failed at
/// that step, as the PHP SDK reports it.
pub(super) async fn failed_at_second_activity(app: &Router, workflow: &str) -> Value {
    failed_with(app, workflow, json!({"input":envelope(Payload::Array(vec![Payload::Long(9007199254740993)]))}), vec![]).await
}

// Core workflow#992: a fired plain timer is replayed as a completed prefix
// step. The successor must neither wait again nor borrow the redrive's clock.
#[tokio::test]
async fn redrive_reuses_fired_timer_identity_and_original_clock_without_timer_work() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let registered = request(&app, "POST", "/api/worker/register", json!({"worker_id":"worker","task_queue":"test",
        "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":["a","b"],
        "workflow_definition_fingerprints":{"echo":FINGERPRINT}})).await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    let source = start(&app, "rd-timer").await;
    let source_run = source["run_id"].as_str().unwrap();
    let task = poll(&app, "worker", "workflow").await;
    assert_eq!(finish_task(&app, &task, json!([{"type":"schedule_activity","activity_type":"a",
        "arguments":envelope(Payload::Array(vec![]))}])).await.0, StatusCode::OK);
    complete_activity(&app, &poll(&app, "worker", "activity").await).await;
    let task = poll(&app, "worker", "workflow").await;
    assert_eq!(finish_task(&app, &task, json!([{"type":"start_timer","delay_seconds":1}])).await.0, StatusCode::OK);
    let deadline = tokio::time::Instant::now() + std::time::Duration::from_secs(15);
    loop {
        let history = run_history(&app, &source["workflow_id"], &source["run_id"]).await;
        if kinds(&history).iter().any(|kind| kind == "TimerFired") {
            break;
        }
        assert!(tokio::time::Instant::now() < deadline, "timer did not fire: {history}");
        tokio::time::sleep(std::time::Duration::from_millis(20)).await;
    }
    let task = poll(&app, "worker", "workflow").await;
    assert_eq!(finish_task(&app, &task, json!([{"type":"schedule_activity","activity_type":"b",
        "arguments":envelope(Payload::Array(vec![]))}])).await.0, StatusCode::OK);
    fail_redrive_activity(&app, 3).await;
    // Consumed clocks use full event precision; the initial anchor uses the
    // persisted run start. Pin distinct ordered times after executing the
    // real timer and activity lifecycle so those authorities cannot alias.
    let recorded = run_history(&app, &source["workflow_id"], &source["run_id"]).await;
    let clock_base = chrono::DateTime::parse_from_rfc3339("2026-11-03T00:00:00.123456+00:00").unwrap();
    for event in recorded.as_array().unwrap() {
        let sequence = event["sequence"].as_i64().unwrap();
        let at = clock_base + chrono::Duration::seconds(sequence);
        database.execute(sqlx::AssertSqlSafe(format!("UPDATE workflow_history_events SET recorded_at='{}' WHERE workflow_run_id='{source_run}' AND sequence={sequence}",
            at.format("%Y-%m-%d %H:%M:%S%.6f")))).await;
    }
    let original_start = clock_base - chrono::Duration::seconds(1);
    database.execute(sqlx::AssertSqlSafe(format!("UPDATE workflow_runs SET started_at='{}' WHERE id='{source_run}'",
        original_start.format("%Y-%m-%d %H:%M:%S%.6f")))).await;
    runtime.close().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let original = run_history(&app, &source["workflow_id"], &source["run_id"]).await;
    let timer = |history: &Value, kind: &str| history.as_array().unwrap().iter()
        .find(|event| event["event_type"] == kind).unwrap().clone();
    let original_fired = timer(&original, "TimerFired");
    let clock = |value: &Value| chrono::DateTime::parse_from_rfc3339(value.as_str().expect("public replay clock timestamp")).unwrap();
    let original_clock = clock(&original_fired["timestamp"]);
    assert_eq!(original_clock.timestamp_subsec_micros(), 123456);
    let counts = table_counts(&database).await;
    let mut predecessor = source_run.to_owned();
    let mut previous_timer = original_fired["payload"]["timer_id"].clone();

    // A second redrive changes the immediate predecessor, while retaining the
    // first execution's time. Each new run gets one fresh shared timer ID.
    for _ in 0..2 {
        let (_, described) = request(&app, "GET", &format!("/api/workflows/rd-timer/runs/{predecessor}"), Value::Null).await;
        assert_eq!(redrive_report(&described), (json!(true), Value::Null));
        let (status, accepted) = request(&app, "POST", &format!("/api/workflows/rd-timer/runs/{predecessor}/redrive"), json!({})).await;
        assert_eq!(status, StatusCode::ACCEPTED, "{accepted}");
        assert_eq!(accepted["resume_step_sequence"], 3);
        let run = accepted["run_id"].as_str().unwrap();
        let history = run_history(&app, &source["workflow_id"], &accepted["run_id"]).await;
        assert_eq!(kinds(&history), ["StartAccepted", "WorkflowStarted", "ActivityCompleted", "TimerScheduled", "TimerFired"]);
        let scheduled = timer(&history, "TimerScheduled")["payload"].clone();
        let fired = timer(&history, "TimerFired")["payload"].clone();
        assert!(scheduled["timer_id"].is_string());
        assert_ne!(scheduled["timer_id"], previous_timer);
        assert_eq!(scheduled["timer_id"], fired["timer_id"]);
        assert_eq!(fired["reused_from_run_id"], predecessor);
        assert_eq!(clock(&fired["reused_recorded_at"]), original_clock);
        assert_eq!(clock(&history[1]["payload"]["replayed_started_at"]), original_start);
        assert_ne!(original_start, clock(&timer(&original, "WorkflowStarted")["timestamp"]));
        assert_eq!(clock(&history[2]["payload"]["reused_recorded_at"]), clock(&timer(&original, "ActivityCompleted")["timestamp"]));
        for (kind, actual) in [("TimerScheduled", &scheduled), ("TimerFired", &fired)] {
            let mut expected = timer(&original, kind)["payload"].clone();
            for field in ["task", "command", "timer_id"] {
                expected.as_object_mut().unwrap().remove(field);
                if field != "timer_id" {
                    assert!(actual.get(field).is_none(), "{kind}: {actual}");
                }
            }
            let mut actual = actual.clone();
            for field in ["timer_id", "reused_from_run_id", "reused_recorded_at"] {
                actual.as_object_mut().unwrap().remove(field);
            }
            assert_eq!(actual, expected, "{kind}");
        }
        // Physical timer inventories stay unchanged by redrive, on every backend.
        let after = table_counts(&database).await;
        let timer_count = |rows: &[(String, i64)]| rows.iter().find(|(name, _)| name == "workflow_run_timers")
            .expect("the source catalog contains workflow_run_timers").1;
        assert_eq!(timer_count(&after), timer_count(&counts));
        for sql in [format!("SELECT COUNT(*) FROM workflow_run_timers WHERE workflow_run_id='{run}'"),
            format!("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id='{run}' AND task_type='timer'")] {
            assert_eq!(task_retry::rows(&database, Box::leak(sql.into_boxed_str())).await, 0);
        }
        assert_eq!(run_history(&app, &source["workflow_id"], &source["run_id"]).await, original);
        let task = poll(&app, "worker", "workflow").await;
        assert_eq!(task["run_id"], accepted["run_id"]);
        assert_eq!(clock(&task["replay_clock"]["started_at"]), original_start);
        assert_eq!(finish_task(&app, &task, json!([{"type":"schedule_activity","activity_type":"b",
            "arguments":envelope(Payload::Array(vec![]))}])).await.0, StatusCode::OK);
        previous_timer = fired["timer_id"].clone();
        predecessor = run.to_owned();
        fail_redrive_activity(&app, 3).await;
    }
    runtime.close().await;
    database.remove().await;
}

async fn fail_redrive_activity(app: &Router, sequence: i64) {
    let activity = poll(app, "worker", "activity").await;
    assert_eq!(activity["activity_type"], "b", "{activity}");
    let failed = request(app, "POST", &format!("/api/worker/activity-tasks/{}/fail", activity["task_id"].as_str().unwrap()),
        json!({"lease_owner":activity["lease_owner"],"activity_attempt_id":activity["activity_attempt_id"],
            "failure":{"class":"App\\Boom","message":"boom"}})).await;
    assert_eq!(failed.0, StatusCode::OK, "{}", failed.1);
    let task = poll(app, "worker", "workflow").await;
    let done = finish_task(app, &task, json!([{"type":"fail_workflow","message":"gave up","failed_step_sequence":sequence,
        "failed_activity_execution_id":activity["activity_execution_id"]}])).await;
    assert_eq!(done.1["run_status"], "failed", "{}", done.1);
}

// Published PHP 2.11.1's redrive continues a run that failed at an activity
// step in a new run: its history reuses the completed activities before the
// step, so the worker reschedules only the failed one.
#[tokio::test]
async fn redrive_resumes_a_failed_run_at_its_failed_activity_like_php() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let registered = request(&app, "POST", "/api/worker/register", json!({"worker_id":"worker","task_queue":"test",
        "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":["a","b"],
        "workflow_definition_fingerprints":{"echo":FINGERPRINT}})).await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    let source = failed_at_second_activity(&app, "rd").await;
    let source_run = source["run_id"].as_str().unwrap();
    let path = format!("/api/workflows/rd/runs/{source_run}/redrive");

    let (status, invalid) = request(&app, "POST", &path, json!({"request_id":"x".repeat(192)})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{invalid}");
    assert_eq!(invalid["errors"]["request_id"], json!(["The request id field must not be greater than 191 characters."]));
    let (status, missing) = request(&app, "POST", "/api/workflows/rd/runs/nope/redrive", json!({})).await;
    assert_eq!((status, missing["reason"].clone()), (StatusCode::NOT_FOUND, json!("run_not_found")), "{missing}");

    let (status, redriven) = request(&app, "POST", &path, json!({"request_id":"rq-1"})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{redriven}");
    for (field, expected) in [("continued_from_run_id", json!(source_run)), ("resume_step_sequence", json!(2)),
        ("command_status", json!("accepted")), ("outcome", json!("redriven")), ("reason", Value::Null)] {
        assert_eq!(redriven[field], expected, "{field}: {redriven}");
    }
    let run = redriven["run_id"].as_str().unwrap().to_owned();
    assert_eq!(redriven["workflow_run_id"], json!(run));
    let (status, again) = request(&app, "POST", &path, json!({"request_id":"rq-1"})).await;
    assert_eq!((status, again["run_id"].clone(), again["task_id"].clone()), (StatusCode::OK, json!(run), Value::Null), "{again}");
    let (status, current) = request(&app, "POST", &format!("/api/workflows/rd/runs/{run}/redrive"), json!({})).await;
    assert_eq!((status, current["reason"].clone()), (StatusCode::CONFLICT, json!("run_not_failed")), "{current}");

    let history = run_history(&app, &json!("rd"), &json!(run)).await;
    assert_eq!(kinds(&history), ["StartAccepted", "WorkflowStarted", "ActivityCompleted"]);
    let started = &history[1]["payload"];
    for (field, expected) in [("continued_from_run_id", json!(source_run)), ("resume_step_sequence", json!(2)),
        ("recovery_kind", json!("redrive")), ("workflow_definition_fingerprint", json!(FINGERPRINT))] {
        assert_eq!(started[field], expected, "{field}: {started}");
    }
    let reused = &history[2]["payload"];
    assert_eq!((reused["sequence"].clone(), reused["activity_type"].clone(), reused["reused_from_run_id"].clone()),
        (json!(1), json!("a"), json!(source_run)), "{reused}");

    // The worker replays the reused step and reschedules only the failed one.
    let task = poll(&app, "worker", "workflow").await;
    assert_eq!(task["run_id"], json!(run), "{task}");
    let resumed = finish_task(&app, &task, json!([{"type":"schedule_activity","activity_type":"b","arguments":envelope(Payload::Array(vec![]))}])).await;
    assert_eq!(resumed.0, StatusCode::OK, "{}", resumed.1);
    let activity = poll(&app, "worker", "activity").await;
    assert_eq!(activity["activity_type"], "b", "{activity}");
    complete_activity(&app, &activity).await;
    let task = poll(&app, "worker", "workflow").await;
    let done = finish_task(&app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Array(vec![]))}])).await;
    assert_eq!(done.1["run_status"], "completed", "{}", done.1);
    let history = run_history(&app, &json!("rd"), &json!(run)).await;
    let scheduled = history.as_array().unwrap().iter().find(|event| event["event_type"] == "ActivityScheduled").unwrap();
    assert_eq!(scheduled["payload"]["sequence"], 2, "{scheduled}");
    let (_, described) = request(&app, "GET", "/api/workflows/rd", Value::Null).await;
    assert_eq!((described["run_number"].clone(), described["continued_from_run_id"].clone(), described["recovery_kind"].clone()),
        (json!(2), json!(source_run), json!("redrive")), "{described}");

    // A run that failed without an activity boundary cannot be redriven.
    let plain = start(&app, "rd2").await;
    let task = poll(&app, "worker", "workflow").await;
    finish_task(&app, &task, json!([{"type":"fail_workflow","message":"x"}])).await;
    let (status, refused) = request(&app, "POST", &format!("/api/workflows/rd2/runs/{}/redrive", plain["run_id"].as_str().unwrap()), json!({})).await;
    assert_eq!(status, StatusCode::CONFLICT, "{refused}");
    for (field, expected) in [("reason", json!("unrecorded_failure_boundary")), ("command_status", json!("rejected")),
        ("outcome", json!("rejected")), ("accepted", json!(false)), ("workflow_run_id", Value::Null)] {
        assert_eq!(refused[field], expected, "{field}: {refused}");
    }
    runtime.close().await;
    database.remove().await;
}

// A redriven run closes as any other run. Its `redrive` link names the
// failed run it came from, and PHP's close follows only `child_workflow`
// links (`WorkflowStub::attemptCancel`, `attemptTerminate`).
#[tokio::test]
async fn a_redriven_run_cancels_and_terminates_like_php() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let registered = request(&app, "POST", "/api/worker/register", json!({"worker_id":"worker","task_queue":"test",
        "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":["a","b"],
        "workflow_definition_fingerprints":{"echo":FINGERPRINT}})).await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    for (workflow, command) in [("rd-cancel", "cancel"), ("rd-terminate", "terminate")] {
        let source = failed_at_second_activity(&app, workflow).await;
        let path = format!("/api/workflows/{workflow}/runs/{}/redrive", source["run_id"].as_str().unwrap());
        let (status, redriven) = request(&app, "POST", &path, json!({})).await;
        assert_eq!(status, StatusCode::ACCEPTED, "{redriven}");
        let (status, closed) = request(&app, "POST", &format!("/api/workflows/{workflow}/{command}"),
            json!({"reason":"redriven run"})).await;
        assert_eq!(status, StatusCode::OK, "{command}: {closed}");
        let (_, described) = request(&app, "GET", &format!("/api/workflows/{workflow}"), Value::Null).await;
        assert_eq!((described["run_id"].clone(), described["status"].clone()),
            (redriven["run_id"].clone(), json!(if command == "cancel" { "cancelled" } else { "terminated" })), "{described}");
    }
    runtime.close().await;
    database.remove().await;
}

/// A run like `failed_at_second_activity`, started with `start` fields and
/// with `extra` commands, one step each, in its first workflow task.
async fn failed_with(app: &Router, workflow: &str, start: Value, extra: Vec<Value>) -> Value {
    let failed_step = 2 + extra.len();
    let mut body = json!({"workflow_id":workflow,"workflow_type":"echo","task_queue":"test","input":envelope(Payload::Array(vec![]))});
    for (key, value) in start.as_object().unwrap() {
        body[key] = value.clone();
    }
    let (status, started) = request(app, "POST", "/api/workflows", body).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    let task = poll(app, "worker", "workflow").await;
    let mut commands = extra;
    commands.push(json!({"type":"schedule_activity","activity_type":"a","arguments":envelope(Payload::Array(vec![]))}));
    let (status, body) = finish_task(app, &task, json!(commands)).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    complete_activity(app, &poll(app, "worker", "activity").await).await;
    let task = poll(app, "worker", "workflow").await;
    finish_task(app, &task, json!([{"type":"schedule_activity","activity_type":"b","arguments":envelope(Payload::Array(vec![]))}])).await;
    let activity = poll(app, "worker", "activity").await;
    let path = format!("/api/worker/activity-tasks/{}/fail", activity["task_id"].as_str().unwrap());
    let failed = request(app, "POST", &path, json!({"lease_owner":activity["lease_owner"],
        "activity_attempt_id":activity["activity_attempt_id"],"failure":{"class":"App\\Boom","message":"boom"}})).await;
    assert_eq!(failed.0, StatusCode::OK, "{}", failed.1);
    let task = poll(app, "worker", "workflow").await;
    let done = finish_task(app, &task, json!([{"type":"fail_workflow","message":"gave up","failed_step_sequence":failed_step,
        "failed_activity_execution_id":activity["activity_execution_id"]}])).await;
    assert_eq!(done.1["run_status"], "failed", "{}", done.1);
    started
}

// Server #980 (Core workflow#985): start-time memos and search attributes
// carry to the redriven successor. A run whose workflow code upserted one
// refuses as Core refuses a service worker's run
// (`FailedRunRedriveEligibility`, fixture redrive-service-unqualified-history):
// the SDKs are not qualified to replay a reused upsert yet.
#[tokio::test]
async fn redrive_carries_start_time_memo_and_search_attributes() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let registered = request(&app, "POST", "/api/worker/register", json!({"worker_id":"worker","task_queue":"test",
        "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":["a","b"],"capabilities":["memo_upserts"],
        "workflow_definition_fingerprints":{"echo":FINGERPRINT}})).await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    let (status, body) = request(&app, "POST", "/api/search-attributes", json!({"name":"Region","type":"keyword"})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    let visibility = json!({"memo":{"customer":"Taylor","attempt_note":"first deploy"},"search_attributes":{"Region":"us-east"}});
    let source = failed_with(&app, "rd-visible", visibility, vec![]).await;
    let source_run = source["run_id"].as_str().unwrap();
    let (status, redriven) = request(&app, "POST", &format!("/api/workflows/rd-visible/runs/{source_run}/redrive"), json!({})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{redriven}");
    let run = redriven["run_id"].as_str().unwrap();
    let (status, described) = request(&app, "GET", &format!("/api/workflows/rd-visible/runs/{run}"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{described}");
    assert_eq!(described["memo"], json!({"customer":"Taylor","attempt_note":"first deploy"}), "{described}");
    assert_eq!(described["search_attributes"]["Region"], "us-east", "{described}");
    let history = run_history(&app, &json!("rd-visible"), &json!(run)).await;
    assert_eq!(history[1]["payload"]["memo"], json!({"customer":"Taylor","attempt_note":"first deploy"}));
    let listed = request(&app, "GET", "/api/workflows?query=Region%20%3D%20%22us-east%22", Value::Null).await.1;
    assert_eq!(listed["workflows"].as_array().unwrap().iter().filter(|w| w["run_id"] == json!(run)).count(), 1, "{listed}");

    let (status, body) = request(&app, "POST", "/api/workflows/rd-visible/terminate", json!({"reason":"checked"})).await;
    assert_eq!(status, StatusCode::OK, "{body}");

    // An upsert by workflow code is refused before anything is written.
    let upserted = failed_with(&app, "rd-upserted", json!({}), vec![json!({"type":"upsert_memo",
        "entries":envelope(Payload::Map(std::collections::BTreeMap::from([("note".into(), Payload::String("by code".into()))])))})]).await;
    let source = upserted["run_id"].as_str().unwrap();
    let path = format!("/api/workflows/rd-upserted/runs/{source}");
    let refused = request(&app, "GET", &path, Value::Null).await.1;
    assert_eq!(redrive_report(&refused), (json!(false), json!("service_redrive_history_not_qualified")), "{refused}");
    let (status, refused) = request(&app, "POST", &format!("{path}/redrive"), json!({})).await;
    assert_eq!((status, refused["reason"].clone()), (StatusCode::CONFLICT, json!("service_redrive_history_not_qualified")), "{refused}");
    let (_, described) = request(&app, "GET", "/api/workflows/rd-upserted", Value::Null).await;
    assert_eq!(described["run_id"], json!(source), "{described}");
    runtime.close().await;
    database.remove().await;
}

fn redrive_report(described: &Value) -> (Value, Value) {
    (described["actions"]["can_redrive"].clone(), described["actions"]["redrive_blocked_reason"].clone())
}

// Server #980 (Core workflow#990, fixture redrive-eligibility-report):
// describe reports the decision redrive applies.
#[tokio::test]
async fn describe_reports_the_redrive_decision() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let registered = request(&app, "POST", "/api/worker/register", json!({"worker_id":"worker","task_queue":"test",
        "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":["a","b"],
        "workflow_definition_fingerprints":{"echo":FINGERPRINT}})).await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    let source = failed_at_second_activity(&app, "rd-report").await;
    let source_run = source["run_id"].as_str().unwrap();
    let path = format!("/api/workflows/rd-report/runs/{source_run}");
    assert_eq!(redrive_report(&request(&app, "GET", &path, Value::Null).await.1), (json!(true), Value::Null));
    let (status, redriven) = request(&app, "POST", &format!("{path}/redrive"), json!({})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{redriven}");
    assert_eq!(redrive_report(&request(&app, "GET", &path, Value::Null).await.1), (json!(false), json!("already_redriven")));
    let successor = request(&app, "GET", "/api/workflows/rd-report", Value::Null).await.1;
    assert_eq!(redrive_report(&successor), (json!(false), json!("run_not_failed")), "{successor}");
    let (status, body) = request(&app, "POST", "/api/workflows/rd-report/terminate", json!({"reason":"checked"})).await;
    assert_eq!(status, StatusCode::OK, "{body}");

    // A failure without a recorded boundary reports redrive's own refusal.
    start(&app, "rd-refused").await;
    let task = poll(&app, "worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"fail_workflow","message":"gave up"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let refused = request(&app, "GET", "/api/workflows/rd-refused", Value::Null).await.1;
    assert_eq!(redrive_report(&refused), (json!(false), json!("unrecorded_failure_boundary")), "{refused}");
    let run = refused["run_id"].as_str().unwrap();
    let (status, body) = request(&app, "POST", &format!("/api/workflows/rd-refused/runs/{run}/redrive"), json!({})).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::CONFLICT, json!("unrecorded_failure_boundary")), "{body}");
    runtime.close().await;
    database.remove().await;
}

// Server #980 (Core workflow#991, fixture redrive-reused-markers-clock): side
// effects and version markers before the failed step are reused in step
// order, with the source payload plus `reused_from_run_id` and the time the
// step first ran as `reused_recorded_at`, so the successor's workflow time
// after each step is the source's. A second redrive keeps that first time.
#[tokio::test]
async fn redrive_reuses_side_effects_and_version_markers_at_their_recorded_time() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let registered = request(&app, "POST", "/api/worker/register", json!({"worker_id":"worker","task_queue":"test",
        "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":["a","b"],
        "workflow_definition_fingerprints":{"echo":FINGERPRINT}})).await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    let side_effect = envelope(Payload::String("0123456789abcdef".into()));
    let markers = vec![json!({"type":"record_side_effect","result":side_effect}),
        json!({"type":"record_version_marker","change_id":"redrive-markers","version":1,"min_supported":-1,"max_supported":1})];
    let source = failed_with(&app, "rd-markers", json!({}), markers).await;
    let source_run = source["run_id"].as_str().unwrap().to_owned();
    // The source's steps ran earlier than the redrive.
    database.execute(sqlx::AssertSqlSafe(format!("UPDATE workflow_history_events SET recorded_at='2026-11-03 00:00:00.654321' WHERE workflow_run_id='{source_run}' AND event_type IN ('SideEffectRecorded','VersionMarkerRecorded')"))).await;
    let source_history = run_history(&app, &json!("rd-markers"), &json!(source_run)).await;
    let recorded = |history: &Value, kind: &str| history.as_array().unwrap().iter().find(|event| event["event_type"] == kind).unwrap()["payload"].clone();
    let path = format!("/api/workflows/rd-markers/runs/{source_run}");
    assert_eq!(redrive_report(&request(&app, "GET", &path, Value::Null).await.1), (json!(true), Value::Null));

    let (status, redriven) = request(&app, "POST", &format!("{path}/redrive"), json!({})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{redriven}");
    assert_eq!(redriven["resume_step_sequence"], json!(4), "{redriven}");
    let run = redriven["run_id"].as_str().unwrap().to_owned();
    let history = run_history(&app, &json!("rd-markers"), &json!(run)).await;
    assert_eq!(kinds(&history), ["StartAccepted", "WorkflowStarted", "SideEffectRecorded", "VersionMarkerRecorded", "ActivityCompleted"]);
    let first_ran = json!("2026-11-03T00:00:00.654321+00:00");
    for (kind, sequence) in [("SideEffectRecorded", 1), ("VersionMarkerRecorded", 2)] {
        let mut reused = recorded(&history, kind);
        assert_eq!((reused["sequence"].clone(), reused["reused_from_run_id"].clone(), reused["reused_recorded_at"].clone()),
            (json!(sequence), json!(source_run), first_ran.clone()), "{kind}: {reused}");
        // The source payload, without its task and command.
        let mut original = recorded(&source_history, kind);
        let fields = original.as_object_mut().unwrap();
        assert!(fields.remove("task").is_some(), "{kind}: the source records its task");
        fields.remove("command");
        for key in ["reused_from_run_id", "reused_recorded_at"] {
            reused.as_object_mut().unwrap().remove(key);
        }
        assert_eq!(reused, original, "{kind}");
    }
    assert_eq!(recorded(&history, "ActivityCompleted")["sequence"], json!(3));

    // The worker replays the reused steps and reschedules only the failed one.
    let task = poll(&app, "worker", "workflow").await;
    assert_eq!(task["run_id"], json!(run), "{task}");
    let replayed: Vec<&str> = task["history_events"].as_array().unwrap().iter().map(|event| event["event_type"].as_str().unwrap()).collect();
    assert_eq!(replayed, ["StartAccepted", "WorkflowStarted", "SideEffectRecorded", "VersionMarkerRecorded", "ActivityCompleted"]);
    let resumed = finish_task(&app, &task, json!([{"type":"schedule_activity","activity_type":"b","arguments":envelope(Payload::Array(vec![]))}])).await;
    assert_eq!(resumed.0, StatusCode::OK, "{}", resumed.1);
    let activity = poll(&app, "worker", "activity").await;
    assert_eq!(activity["activity_type"], "b", "{activity}");
    let history = run_history(&app, &json!("rd-markers"), &json!(run)).await;
    assert_eq!(recorded(&history, "ActivityScheduled")["sequence"], json!(4));

    // The successor fails at the same step again. Its redrive reuses the
    // markers from it, keeping the time they first ran.
    let failed = request(&app, "POST", &format!("/api/worker/activity-tasks/{}/fail", activity["task_id"].as_str().unwrap()),
        json!({"lease_owner":activity["lease_owner"],"activity_attempt_id":activity["activity_attempt_id"],
        "failure":{"class":"App\\Boom","message":"boom"}})).await;
    assert_eq!(failed.0, StatusCode::OK, "{}", failed.1);
    let task = poll(&app, "worker", "workflow").await;
    let done = finish_task(&app, &task, json!([{"type":"fail_workflow","message":"gave up","failed_step_sequence":4,
        "failed_activity_execution_id":activity["activity_execution_id"]}])).await;
    assert_eq!(done.1["run_status"], "failed", "{}", done.1);
    let (status, again) = request(&app, "POST", &format!("/api/workflows/rd-markers/runs/{run}/redrive"), json!({})).await;
    assert_eq!((status, again["resume_step_sequence"].clone()), (StatusCode::ACCEPTED, json!(4)), "{again}");
    let history = run_history(&app, &json!("rd-markers"), &again["run_id"]).await;
    assert_eq!(kinds(&history), ["StartAccepted", "WorkflowStarted", "SideEffectRecorded", "VersionMarkerRecorded", "ActivityCompleted"]);
    for kind in ["SideEffectRecorded", "VersionMarkerRecorded", "ActivityCompleted"] {
        let reused = recorded(&history, kind);
        assert_eq!(reused["reused_from_run_id"], json!(run), "{kind}: {reused}");
    }
    for kind in ["SideEffectRecorded", "VersionMarkerRecorded"] {
        assert_eq!(recorded(&history, kind)["reused_recorded_at"], first_ran, "{kind}");
    }
    let (status, body) = request(&app, "POST", "/api/workflows/rd-markers/terminate", json!({"reason":"checked"})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    runtime.close().await;
    database.remove().await;
}
