use super::*;

const PASS: &str = "/api/system/repair/pass";
const LONG_AGO: &str = "'2020-01-02 03:04:05.000000'";

async fn started_on(app: &Router, workflow: &str, queue: &str) -> Value {
    let (status, body) = request(app, "POST", "/api/workflows", json!({"workflow_id":workflow,"workflow_type":"echo","task_queue":queue,"input":[]})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    body
}

/// Counts rows with a query built for this test.
async fn count(database: &TestDatabase, sql: String) -> i64 {
    task_retry::rows(database, Box::leak(sql.into_boxed_str())).await
}

async fn execute(database: &TestDatabase, sql: String) {
    database.execute(sqlx::AssertSqlSafe(sql)).await;
}

fn counts(report: &Value, fields: &[&str]) -> Vec<Value> {
    fields.iter().map(|field| report[*field].clone()).collect()
}

// Published PHP's repair pass redispatches overdue ready tasks, returns tasks
// whose lease expired, and creates a lost workflow task. Its filters and loop
// throttle shape what one pass selects.
#[tokio::test]
async fn repair_pass_recovers_tasks_and_runs_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let (status, empty) = request(&app, "POST", PASS, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{empty}");
    assert_eq!(
        counts(&empty, &["throttled", "selected_total_candidates", "dispatched_tasks", "service_call_timeout_candidates"]),
        [json!(false), json!(0), json!(0), json!(0)]
    );
    assert_eq!(empty["command_contract_failures"], json!([]));

    let overdue = started_on(&app, "overdue", "repair-a").await;
    let leased = started_on(&app, "leased", "repair-b").await;
    let stalled = started_on(&app, "stalled", "repair-c").await;
    let (overdue, leased, stalled) =
        (overdue["run_id"].as_str().unwrap(), leased["run_id"].as_str().unwrap(), stalled["run_id"].as_str().unwrap());
    // Each task was created and dispatched long before the redispatch cutoff.
    execute(&database, format!("UPDATE workflow_tasks SET created_at={LONG_AGO},available_at={LONG_AGO},last_dispatched_at={LONG_AGO}")).await;
    execute(&database, format!("UPDATE workflow_tasks SET status='leased',lease_owner='gone',lease_expires_at={LONG_AGO} WHERE workflow_run_id='{leased}'")).await;
    // The stalled run lost its task.
    execute(&database, format!("DELETE FROM workflow_tasks WHERE workflow_run_id='{stalled}'")).await;
    execute(&database, format!("UPDATE workflow_run_summaries SET liveness_state='repair_needed',next_task_id=NULL WHERE id='{stalled}'")).await;

    // A pass scoped to one queue selects only its candidates.
    let (status, scoped) = request(&app, "POST", PASS, json!({"queue": " repair-a "})).await;
    assert_eq!(status, StatusCode::OK, "{scoped}");
    assert_eq!(scoped["queue"], "repair-a");
    assert_eq!(
        counts(&scoped, &["selected_existing_task_candidates", "selected_missing_task_candidates", "repaired_existing_tasks", "dispatched_tasks"]),
        [json!(1), json!(0), json!(1), json!(1)],
        "{scoped}"
    );
    assert_eq!(
        count(&database, format!("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id='{overdue}' AND status='ready' AND repair_count=1 AND last_dispatched_at>'2021-01-01 00:00:00'")).await,
        1
    );

    // A pass that respects the loop throttle runs nothing right after another.
    let (status, throttled) = request(&app, "POST", PASS, json!({"respect_throttle": true})).await;
    assert_eq!(status, StatusCode::OK, "{throttled}");
    assert_eq!(counts(&throttled, &["throttled", "selected_total_candidates"]), [json!(true), json!(0)]);

    // An unthrottled pass returns the expired lease and creates the lost task.
    let (status, full) = request(&app, "POST", PASS, json!({"respect_throttle": "0"})).await;
    assert_eq!(status, StatusCode::OK, "{full}");
    assert_eq!(
        counts(&full, &["throttled", "selected_existing_task_candidates", "selected_missing_task_candidates", "repaired_existing_tasks", "repaired_missing_tasks", "dispatched_tasks"]),
        [json!(false), json!(1), json!(1), json!(1), json!(1), json!(2)],
        "{full}"
    );
    assert_eq!(
        count(&database, format!("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id='{leased}' AND status='ready' AND lease_owner IS NULL AND lease_expires_at IS NULL AND repair_count=1")).await,
        1
    );
    assert_eq!(
        count(&database, format!("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id='{stalled}' AND task_type='workflow' AND status='ready' AND repair_count=1 AND last_dispatched_at IS NOT NULL")).await,
        1
    );
    assert_eq!(
        count(&database, format!("SELECT COUNT(*) FROM workflow_run_summaries WHERE id='{stalled}' AND next_task_id IS NOT NULL")).await,
        1
    );

    // Nothing is left to repair.
    let (status, quiet) = request(&app, "POST", PASS, json!({})).await;
    assert_eq!(status, StatusCode::OK, "{quiet}");
    assert_eq!(quiet["selected_total_candidates"], 0, "{quiet}");
}

// A run past its deadline gets the task that closes it. An expired lease on
// a running activity is settled by its retry policy, by the scheduler or the
// pass, whichever runs first. A candidate whose recovery fails is reported
// with 207 and keeps the failure as its last error.
#[tokio::test]
async fn repair_pass_creates_deadline_tasks_settles_leases_and_reports_failures() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    // This test counts what one requested pass repairs.
    runtime.set_maintenance_repair(false);
    let app = router(runtime);
    register(&app, "worker", json!(["echo"]), json!(["act"])).await;

    let deadline = started_on(&app, "deadline", "repair-d").await;
    let deadline = deadline["run_id"].as_str().unwrap();
    execute(&database, format!("UPDATE workflow_tasks SET status='completed' WHERE workflow_run_id='{deadline}'")).await;
    execute(&database, format!("UPDATE workflow_runs SET execution_deadline_at={LONG_AGO} WHERE id='{deadline}'")).await;

    // A running activity whose lease expired.
    let started = start(&app, "activity").await;
    let task = poll(&app, "worker", "workflow").await;
    let command = json!({"type":"schedule_activity","activity_type":"act","arguments":envelope(Payload::Array(vec![]))});
    assert_eq!(finish_task(&app, &task, json!([command])).await.0, StatusCode::OK);
    let activity = poll(&app, "worker", "activity").await;
    assert!(activity["task_id"].is_string(), "{activity}");
    let run = started["run_id"].as_str().unwrap();
    execute(&database, format!("UPDATE workflow_tasks SET lease_expires_at={LONG_AGO} WHERE workflow_run_id='{run}' AND task_type='activity'")).await;

    // An overdue ready task whose repair counter cannot count another repair.
    let exhausted = started_on(&app, "exhausted", "repair-e").await;
    let exhausted = exhausted["run_id"].as_str().unwrap();
    let most = match &database {
        TestDatabase::Sqlite(_) => i64::MAX,
        TestDatabase::Postgres { .. } => i64::from(i32::MAX),
        TestDatabase::MySql { .. } => i64::from(u32::MAX),
    };
    execute(&database, format!("UPDATE workflow_tasks SET created_at={LONG_AGO},available_at={LONG_AGO},last_dispatched_at={LONG_AGO},repair_count={most} WHERE workflow_run_id='{exhausted}'")).await;

    let (status, report) = request(&app, "POST", PASS, json!({})).await;
    assert_eq!(status, StatusCode::MULTI_STATUS, "{report}");
    assert_eq!(
        counts(&report, &["deadline_expired_candidates", "deadline_expired_tasks_created"]),
        [json!(1), json!(1)],
        "{report}"
    );
    let failures = report["existing_task_failures"].as_array().unwrap();
    assert_eq!(failures.len(), 1, "{report}");
    assert_eq!(failures[0]["message"], "repair_count_exhausted", "{report}");
    assert_eq!(
        count(&database, format!("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id='{exhausted}' AND status='ready' AND last_error='repair_count_exhausted'")).await,
        1
    );
    assert_eq!(
        count(&database, format!("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id='{deadline}' AND task_type='workflow' AND status='ready' AND repair_count=1")).await,
        1
    );
    // The lost lease failed the activity once, as its policy allows no retry.
    let settled = tokio::time::Instant::now() + std::time::Duration::from_secs(5);
    loop {
        let failed = count(&database, format!("SELECT COUNT(*) FROM workflow_history_events WHERE workflow_run_id='{run}' AND event_type='ActivityFailed'")).await;
        if failed == 1 {
            break;
        }
        assert!(failed == 0 && tokio::time::Instant::now() < settled, "{failed} lease failures");
        tokio::time::sleep(std::time::Duration::from_millis(25)).await;
    }
    let task_id = activity["task_id"].as_str().unwrap();
    assert_eq!(
        count(&database, format!("SELECT COUNT(*) FROM workflow_tasks WHERE id='{task_id}' AND status='cancelled' AND last_error='lease_expired'")).await,
        1
    );

    // The deadline task is open now, so the run is no longer a candidate.
    let (_, again) = request(&app, "POST", PASS, json!({"run_ids": [deadline]})).await;
    assert_eq!(again["deadline_expired_candidates"], 0, "{again}");

    let (status, invalid) = request(&app, "POST", PASS, json!({"run_ids": [""], "queue": "q".repeat(129)})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{invalid}");
    assert_eq!(invalid["errors"]["run_ids.0"], json!(["The run_ids.0 field must be a string.", "The run_ids.0 field must be at least 1 characters."]));
    assert_eq!(invalid["errors"]["queue"], json!(["The queue field must not be greater than 128 characters."]));
}

// PHP's maintenance loop runs the repair pass every few seconds; native's
// ticker does the same unless the pass is turned off. An idle run past its
// execution deadline gets the workflow task that closes it without anyone
// asking for a pass.
#[tokio::test]
async fn the_maintenance_loop_gives_an_overdue_idle_run_its_deadline_task() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let deadline = started_on(&app, "maintained", "repair-m").await;
    let deadline = deadline["run_id"].as_str().unwrap();
    execute(&database, format!("UPDATE workflow_tasks SET status='completed' WHERE workflow_run_id='{deadline}'")).await;
    execute(&database, format!("UPDATE workflow_runs SET execution_deadline_at={LONG_AGO} WHERE id='{deadline}'")).await;
    let ready = format!("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id='{deadline}' AND status='ready'");
    // The loop's throttle is five seconds; allow two turns.
    for _ in 0..48 {
        if count(&database, ready.clone()).await == 1 {
            return;
        }
        tokio::time::sleep(std::time::Duration::from_millis(250)).await;
    }
    panic!("no deadline task after twelve seconds of maintenance");
}
