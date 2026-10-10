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
