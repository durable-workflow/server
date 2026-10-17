use super::*;

fn entry(base: i64, size: usize, index: usize, kind: &str) -> Value {
    let prefix = match kind {
        "activity" => "parallel-activities",
        "timer" => "parallel-timers",
        _ => "parallel-calls",
    };
    json!({"parallel_group_id":format!("{prefix}:{base}:{size}"),"parallel_group_kind":kind,
        "parallel_group_base_sequence":base,"parallel_group_size":size,"parallel_group_index":index})
}

fn with_path(mut command: Value, path: Vec<Value>) -> Value {
    command
        .as_object_mut()
        .unwrap()
        .extend(path.last().unwrap().as_object().unwrap().clone());
    command["parallel_group_path"] = json!(path);
    command
}

fn activity(index: i64) -> Value {
    json!({"type":"schedule_activity","activity_type":"echo_activity",
        "arguments":envelope(Payload::Array(vec![Payload::Long(9007199254740993 + index)]))})
}

fn all_activities(size: usize) -> Value {
    json!(
        (0..size)
            .map(|index| with_path(
                activity(index as i64),
                vec![entry(1, size, index, "activity")]
            ))
            .collect::<Vec<_>>()
    )
}

async fn observe(database: &TestDatabase, app: &Router, run: &Value) -> Value {
    let before = chrono::Utc::now().to_rfc3339();
    let summary = run_observation_active_summaries::observe(app, "default", run).await;
    let after = chrono::Utc::now().to_rfc3339();
    assert_eq!(summary["summary_state"], "observed", "{summary}");
    let mut snapshot = summary.clone();
    snapshot["reference_clock_start"] = json!(before);
    snapshot["reference_clock_end"] = json!(after);
    snapshot["reference_activities"] =
        reference_activities(database, run["run_id"].as_str().unwrap()).await;
    assert_opening_clocks(database,run["run_id"].as_str().unwrap()).await;
    run_observation_summaries::export_reference_for(
        database,
        &snapshot,
        &summary["history"],
        "DW_TEST_PARALLEL_ALL_REFERENCE",
    )
    .await;
    summary
}

async fn assert_opening_clocks(database:&TestDatabase,run:&str) {
    // Compare original stored precision, not the observation wire's seconds dates.
    macro_rules! check {
        ($pool:expr,$query:expr)=>{{
            let pool=$pool;
            let wrong:i64=sqlx::query_scalar($query).bind(run).fetch_one(&pool).await.unwrap();
            pool.close().await;
            assert_eq!(wrong,0,"retained wait must use its canonical scheduled/started history clock");
        }};
    }
    match database {
        TestDatabase::Sqlite(dir)=>check!(sqlx::SqlitePool::connect(&format!("sqlite://{}",dir.path().join("runtime.sqlite").display())).await.unwrap(),
            "SELECT COUNT(*) FROM workflow_run_waits w WHERE w.workflow_run_id=$1 AND w.kind='activity' AND (w.opened_at IS NULL OR w.opened_at IS NOT (SELECT h.recorded_at FROM workflow_history_events h WHERE h.workflow_run_id=w.workflow_run_id AND h.event_type IN ('ActivityScheduled','ActivityStarted') AND json_extract(h.payload,'$.activity_execution_id')=w.resume_source_id ORDER BY h.sequence DESC LIMIT 1))"),
        TestDatabase::Postgres{options,..}=>check!(sqlx::postgres::PgPoolOptions::new().connect_with(options.as_ref().clone()).await.unwrap(),
            "SELECT COUNT(*) FROM workflow_run_waits w WHERE w.workflow_run_id=$1 AND w.kind='activity' AND (w.opened_at IS NULL OR w.opened_at IS DISTINCT FROM (SELECT h.recorded_at FROM workflow_history_events h WHERE h.workflow_run_id=w.workflow_run_id AND h.event_type IN ('ActivityScheduled','ActivityStarted') AND h.payload->>'activity_execution_id'=w.resume_source_id ORDER BY h.sequence DESC LIMIT 1))"),
        TestDatabase::MySql{options,..}=>check!(sqlx::mysql::MySqlPoolOptions::new().connect_with(options.as_ref().clone()).await.unwrap(),
            "SELECT COUNT(*) FROM workflow_run_waits w WHERE w.workflow_run_id=? AND w.kind='activity' AND (w.opened_at IS NULL OR NOT(w.opened_at <=> (SELECT h.recorded_at FROM workflow_history_events h WHERE h.workflow_run_id=w.workflow_run_id AND h.event_type IN ('ActivityScheduled','ActivityStarted') AND JSON_UNQUOTE(JSON_EXTRACT(h.payload,'$.activity_execution_id'))=w.resume_source_id ORDER BY h.sequence DESC LIMIT 1)))"),
    }
}

async fn reference_activities(database: &TestDatabase, run: &str) -> Value {
    const QUERY: &str = "SELECT a.id,a.activity_type,a.status,a.current_attempt_id,CAST(a.retry_policy AS TEXT),CAST(a.schedule_deadline_at AS TEXT),CAST(a.close_deadline_at AS TEXT),CAST(a.heartbeat_deadline_at AS TEXT),CAST(a.schedule_to_close_deadline_at AS TEXT),CAST(p.attempt_number AS TEXT),CAST((SELECT opened_at FROM workflow_run_waits w WHERE w.workflow_run_id=a.workflow_run_id AND w.resume_source_id=a.id AND w.kind='activity') AS TEXT) FROM activity_executions a LEFT JOIN activity_attempts p ON p.id=a.current_attempt_id AND p.activity_execution_id=a.id WHERE a.workflow_run_id=$1 ORDER BY a.sequence,a.id";
    macro_rules! rows {
        ($pool:expr,$query:expr)=>{{
            let pool=$pool;
            let rows:Vec<(String,String,String,Option<String>,Option<String>,Option<String>,Option<String>,Option<String>,Option<String>,Option<String>,Option<String>)>
                =sqlx::query_as($query).bind(run).fetch_all(&pool).await.unwrap();
            pool.close().await;
            rows.into_iter().map(|(id,kind,status,attempt,policy,schedule,close,heartbeat,lifecycle,number,opened)|
                json!({"id":id,"activity_type":kind,"status":status,"current_attempt_id":attempt,
                "retry_policy":policy,"schedule_deadline_at":schedule,"close_deadline_at":close,
                "heartbeat_deadline_at":heartbeat,"schedule_to_close_deadline_at":lifecycle,"attempt_number":number.map(|value|value.parse::<i64>().unwrap()),"reference_opened_at":opened})).collect::<Vec<_>>()
        }};
    }
    json!(match database {
        TestDatabase::Sqlite(dir) => rows!(
            sqlx::SqlitePool::connect(&format!(
                "sqlite://{}",
                dir.path().join("runtime.sqlite").display()
            ))
            .await
            .unwrap(),
            QUERY
        ),
        TestDatabase::Postgres { options, .. } => rows!(
            sqlx::postgres::PgPoolOptions::new()
                .connect_with(options.as_ref().clone())
                .await
                .unwrap(),
            QUERY
        ),
        TestDatabase::MySql { options, .. } => rows!(
            sqlx::mysql::MySqlPoolOptions::new()
                .connect_with(options.as_ref().clone())
                .await
                .unwrap(),
            "SELECT a.id,a.activity_type,a.status,a.current_attempt_id,CAST(a.retry_policy AS CHAR),CAST(a.schedule_deadline_at AS CHAR),CAST(a.close_deadline_at AS CHAR),CAST(a.heartbeat_deadline_at AS CHAR),CAST(a.schedule_to_close_deadline_at AS CHAR),CAST(p.attempt_number AS CHAR),CAST((SELECT opened_at FROM workflow_run_waits w WHERE w.workflow_run_id=a.workflow_run_id AND w.resume_source_id=a.id AND w.kind='activity') AS CHAR) FROM activity_executions a LEFT JOIN activity_attempts p ON p.id=a.current_attempt_id AND p.activity_execution_id=a.id WHERE a.workflow_run_id=? ORDER BY a.sequence,a.id"
        ),
    })
}

async fn complete_activity(app: &Router, task: &Value, value: i64) -> (StatusCode, Value) {
    request(
        app,
        "POST",
        &format!(
            "/api/worker/activity-tasks/{}/complete",
            task["task_id"].as_str().unwrap()
        ),
        json!({"lease_owner":task["lease_owner"],"activity_attempt_id":task["activity_attempt_id"],
            "result":envelope(Payload::Long(value))}),
    )
    .await
}

async fn wait_for_timer(app: &Router, run: &Value) -> Value {
    let deadline = tokio::time::Instant::now() + std::time::Duration::from_secs(3);
    loop {
        let history = run_history(app, &run["workflow_id"], &run["run_id"]).await;
        if history
            .as_array()
            .unwrap()
            .iter()
            .any(|event| event["event_type"] == "TimerFired")
        {
            return history;
        }
        assert!(
            tokio::time::Instant::now() < deadline,
            "original all-group timer did not fire"
        );
        tokio::time::sleep(std::time::Duration::from_millis(20)).await;
    }
}

fn no_selection(history: &Value) {
    assert!(
        !history
            .as_array()
            .unwrap()
            .iter()
            .any(|event| event["event_type"] == "SelectionResolved")
    );
}

async fn complete_history(app: &Router, run: &Value) -> Value {
    let mut events = Vec::new();
    let mut cursor = None::<String>;
    loop {
        let mut path = format!(
            "/api/workflows/{}/runs/{}/history?page_size=100",
            run["workflow_id"].as_str().unwrap(),
            run["run_id"].as_str().unwrap()
        );
        if let Some(cursor) = &cursor {
            path.push_str(&format!("&next_page_token={cursor}"));
        }
        let (status, page) = request(app, "GET", &path, Value::Null).await;
        assert_eq!(status, StatusCode::OK, "{page}");
        for event in page["events"].as_array().unwrap() {
            assert_eq!(event["sequence"], events.len() + 1);
            events.push(event.clone());
        }
        assert!(events.len() < 1000, "unexpected fixture history growth");
        let Some(next) = page["next_page_token"].as_str() else {
            return json!(events);
        };
        assert_ne!(cursor.as_deref(), Some(next));
        cursor = Some(next.to_owned());
    }
}

async fn seed_wait_clock(
    database: &TestDatabase,
    clocks: &[(i64, chrono::DateTime<chrono::Utc>)],
    task: &Value,
    started_sequence: i64,
) {
    // Isolated source fixture: retain the same physical IDs and authored order,
    // while controlling recorded/projected opening times down to a half-ms tie.
    macro_rules! seed {
        ($pool:expr,$wait:expr,$history:expr,$started:expr,$encode:expr) => {{
            let pool = $pool;
            for (sequence, at) in clocks {
                sqlx::query($wait)
                    .bind(($encode)(*at))
                    .bind(*sequence)
                    .execute(&pool)
                    .await
                    .unwrap();
                sqlx::query($history)
                    .bind(($encode)(*at))
                    .bind(*sequence + 2)
                    .execute(&pool)
                    .await
                    .unwrap();
            }
            let at = clocks
                .iter()
                .find(|(sequence, _)| *sequence == started_sequence)
                .unwrap()
                .1;
            sqlx::query($started)
                .bind(($encode)(at))
                .bind(task["task_id"].as_str().unwrap())
                .execute(&pool)
                .await
                .unwrap();
            pool.close().await;
        }};
    }
    match database {
        TestDatabase::Sqlite(dir) => seed!(
            sqlx::SqlitePool::connect(&format!(
                "sqlite://{}",
                dir.path().join("runtime.sqlite").display()
            ))
            .await
            .unwrap(),
            "UPDATE workflow_run_waits SET opened_at=$1 WHERE kind='activity' AND sequence=$2",
            "UPDATE workflow_history_events SET recorded_at=$1 WHERE event_type='ActivityScheduled' AND sequence=$2",
            "UPDATE workflow_history_events SET recorded_at=$1 WHERE event_type='ActivityStarted' AND workflow_task_id=$2",
            |at: chrono::DateTime<chrono::Utc>| at
                .to_rfc3339_opts(chrono::SecondsFormat::Micros, true)
        ),
        TestDatabase::Postgres { options, .. } => seed!(
            sqlx::postgres::PgPoolOptions::new()
                .connect_with(options.as_ref().clone())
                .await
                .unwrap(),
            "UPDATE workflow_run_waits SET opened_at=$1 WHERE kind='activity' AND sequence=$2",
            "UPDATE workflow_history_events SET recorded_at=$1 WHERE event_type='ActivityScheduled' AND sequence=$2",
            "UPDATE workflow_history_events SET recorded_at=$1 WHERE event_type='ActivityStarted' AND workflow_task_id=$2",
            |at: chrono::DateTime<chrono::Utc>| at.naive_utc()
        ),
        TestDatabase::MySql { options, .. } => seed!(
            sqlx::mysql::MySqlPoolOptions::new()
                .connect_with(options.as_ref().clone())
                .await
                .unwrap(),
            "UPDATE workflow_run_waits SET opened_at=? WHERE kind='activity' AND sequence=?",
            "UPDATE workflow_history_events SET recorded_at=? WHERE event_type='ActivityScheduled' AND sequence=?",
            "UPDATE workflow_history_events SET recorded_at=? WHERE event_type='ActivityStarted' AND workflow_task_id=?",
            |at: chrono::DateTime<chrono::Utc>| at
        ),
    }
}

#[tokio::test]
async fn current_wait_order_uses_published_millisecond_ties_and_original_ids() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "clock-all", json!(["echo"]), json!(["echo_activity"])).await;
    let run = start(&app, "clock-all").await;
    let task = poll(&app, "clock-all", "workflow").await;
    assert_eq!(
        finish_task(&app, &task, all_activities(4)).await.0,
        StatusCode::OK
    );
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let mut members = history
        .as_array()
        .unwrap()
        .iter()
        .filter(|event| event["event_type"] == "ActivityScheduled")
        .map(|event| {
            (
                event["payload"]["activity_execution_id"]
                    .as_str()
                    .unwrap()
                    .to_owned(),
                event["payload"]["sequence"].as_i64().unwrap(),
            )
        })
        .collect::<Vec<_>>();
    members.sort();
    let dates = [
        "2026-01-02T03:04:05.123400Z",
        "2026-01-02T03:04:05.123100Z",
        "2026-01-02T03:04:05.123900Z",
        "2026-01-02T03:04:05.123500Z",
    ];
    let clocks = members
        .iter()
        .zip(dates)
        .map(|((_, sequence), at)| {
            (
                *sequence,
                chrono::DateTime::parse_from_rfc3339(at)
                    .unwrap()
                    .with_timezone(&chrono::Utc),
            )
        })
        .collect::<Vec<_>>();
    let activity = poll(&app, "clock-all", "activity").await;
    assert!(activity.is_object());
    let sequence = members
        .iter()
        .find(|(id, _)| Some(id.as_str()) == activity["activity_execution_id"].as_str())
        .unwrap()
        .1;
    seed_wait_clock(&database, &clocks, &activity, sequence).await;
    let heartbeat=request(&app,"POST",&format!("/api/worker/activity-tasks/{}/heartbeat",activity["task_id"].as_str().unwrap()),
        json!({"lease_owner":activity["lease_owner"],"activity_attempt_id":activity["activity_attempt_id"]})).await;
    assert_eq!(heartbeat.0, StatusCode::OK, "{}", heartbeat.1);
    let summary = observe(&database, &app, &run).await;
    let actual = summary["current_waits"]["waits"]
        .as_array()
        .unwrap()
        .iter()
        .map(|wait| wait["dependency_id"].as_str().unwrap())
        .collect::<Vec<_>>();
    assert_eq!(
        actual,
        members
            .iter()
            .map(|(id, _)| id.as_str())
            .collect::<Vec<_>>()
    );
    let repeated = run_observation_active_summaries::observe(&app, "default", &run).await;
    assert_eq!(
        repeated["current_waits"]["waits"],
        summary["current_waits"]["waits"]
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn activity_barrier_keeps_out_of_order_results_and_later_wakeup_across_cold_nodes() {
    let database = TestDatabase::new().await;
    let first = database.open().await.unwrap();
    let app = router(first.clone());
    register(
        &app,
        "all-worker",
        json!(["echo"]),
        json!(["echo_activity"]),
    )
    .await;
    let run = start(&app, "all-activities").await;
    let initial = poll(&app, "all-worker", "workflow").await;
    let commands = all_activities(3);
    let admitted = finish_task(&app, &initial, commands.clone()).await;
    assert_eq!(admitted.0, StatusCode::OK, "{}", admitted.1);
    assert_eq!(
        finish_task(&app, &initial, commands).await.1["recorded"],
        false
    );
    let original = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    assert_eq!(original.as_array().unwrap().len(), 5);
    assert_eq!(
        observe(&database, &app, &run).await["current_waits"]["returned_count"],
        3
    );
    drop(app);
    first.close().await;

    let (one, two) = tokio::join!(database.open(), database.open());
    let (one, two) = (one.unwrap(), two.unwrap());
    let (app_a, app_b) = (router(one.clone()), router(two.clone()));
    let mut tasks = Vec::new();
    for _ in 0..3 {
        tasks.push(poll(&app_a, "all-worker", "activity").await);
    }
    tasks.sort_by_key(|task| {
        original
            .as_array()
            .unwrap()
            .iter()
            .position(|event| {
                event["event_type"] == "ActivityScheduled"
                    && event["payload"]["activity_execution_id"] == task["activity_execution_id"]
            })
            .unwrap()
    });
    for (index, task) in tasks.iter().enumerate() {
        assert_eq!(
            task["activity_execution_id"],
            original[index + 2]["payload"]["activity_execution_id"]
        );
        assert_eq!(task["arguments"], activity(index as i64)["arguments"]);
    }
    let done = complete_activity(&app_b, &tasks[2], 9007199254740995).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);
    let leased = poll(&app_a, "all-worker", "workflow").await;
    assert_eq!(leased["task_id"], done.1["next_task_id"]);
    assert_eq!(
        finish_task(
            &app_a,
            &leased,
            json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])
        )
        .await
        .0,
        StatusCode::CONFLICT
    );
    observe(&database, &app_b, &run).await;
    let later = complete_activity(&app_b, &tasks[0], 9007199254740993).await;
    assert_eq!(later.0, StatusCode::OK, "{}", later.1);
    assert_ne!(later.1["next_task_id"], leased["task_id"]);
    let waiting=request(&app_a,"POST",&format!("/api/worker/workflow-tasks/{}/fail",leased["task_id"].as_str().unwrap()),
        json!({"lease_owner":leased["lease_owner"],"workflow_task_attempt":leased["workflow_task_attempt"],
        "failure":{"type":"WorkflowTaskWaitingForHistory","message":"all barrier still has one original member"}})).await;
    assert_eq!(waiting.0, StatusCode::OK, "{}", waiting.1);
    let last = complete_activity(&app_b, &tasks[1], 9007199254740994).await;
    assert_eq!(last.0, StatusCode::OK, "{}", last.1);
    assert_eq!(last.1["next_task_id"], later.1["next_task_id"]);
    let before_duplicate = run_history(&app_a, &run["workflow_id"], &run["run_id"]).await;
    assert_eq!(
        complete_activity(&app_a, &tasks[1], 9007199254740994)
            .await
            .1["recorded"],
        false
    );
    assert_eq!(
        run_history(&app_a, &run["workflow_id"], &run["run_id"]).await,
        before_duplicate
    );
    let resumed = poll(&app_a, "all-worker", "workflow").await;
    assert_eq!(resumed["task_id"], later.1["next_task_id"]);
    let result = envelope(Payload::Array(vec![
        Payload::Long(9007199254740993),
        Payload::Long(9007199254740994),
        Payload::Long(9007199254740995),
    ]));
    assert_eq!(
        finish_task(
            &app_b,
            &resumed,
            json!([{"type":"complete_workflow","result":result}])
        )
        .await
        .0,
        StatusCode::OK
    );
    let history = run_history(&app_a, &run["workflow_id"], &run["run_id"]).await;
    assert_eq!(
        &history.as_array().unwrap()[..5],
        original.as_array().unwrap()
    );
    no_selection(&history);
    assert_eq!(
        history.as_array().unwrap().last().unwrap()["payload"]["output"],
        result
    );
    observe(&database, &app_a, &run).await;
    assert!(poll(&app_b, "all-worker", "workflow").await.is_null());
    one.close().await;
    two.close().await;
    database.remove().await;
}

#[tokio::test]
async fn incomplete_or_malformed_groups_preserve_the_original_batch_and_lease() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(
        &app,
        "invalid-all",
        json!(["echo"]),
        json!(["echo_activity"]),
    )
    .await;
    let run = start(&app, "invalid-all").await;
    let initial = poll(&app, "invalid-all", "workflow").await;
    let before = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let valid = all_activities(2);
    let mut partial = valid.clone();
    partial.as_array_mut().unwrap().pop();
    let mut duplicate = valid.clone();
    duplicate[1] = duplicate[0].clone();
    let mut mismatch = valid.clone();
    mismatch[1]["parallel_group_path"][0]["parallel_group_size"] = json!(3);
    let mut missing = valid.clone();
    missing[0]
        .as_object_mut()
        .unwrap()
        .remove("parallel_group_path");
    let mut overflow = valid.clone();
    overflow[0]["parallel_group_base_sequence"] = json!(i64::MAX);
    let mut wrong_kind = valid.clone();
    wrong_kind[0]["parallel_group_kind"] = json!("timer");
    let mut path = valid.clone();
    path[0]["parallel_group_path"] = json!([]);
    let mut crossed = valid.clone();
    crossed[0]["parallel_group_path"]
        .as_array_mut()
        .unwrap()
        .insert(0, entry(2, 2, 0, "activity"));
    let mut mode = valid.clone();
    mode[0]["parallel_group_mode"] = json!("select");
    let mut incomplete_nested = all_activities(4);
    for index in 0..2 {
        incomplete_nested[index] = with_path(
            activity(index as i64),
            vec![
                entry(1, 4, index, "activity"),
                entry(1, 3, index, "activity"),
            ],
        );
    }
    for commands in [
        partial,
        duplicate,
        mismatch,
        missing,
        overflow,
        wrong_kind,
        path,
        crossed,
        mode,
        incomplete_nested,
    ] {
        let refused = finish_task(&app, &initial, commands).await;
        assert_eq!(refused.0, StatusCode::UNPROCESSABLE_ENTITY, "{}", refused.1);
        assert_eq!(
            run_history(&app, &run["workflow_id"], &run["run_id"]).await,
            before
        );
        assert!(poll(&app, "invalid-all", "activity").await.is_null());
    }
    let shifted = json!(
        (0..2)
            .map(|index| with_path(
                activity(index),
                vec![entry(7, 2, index as usize, "activity")]
            ))
            .collect::<Vec<_>>()
    );
    assert_eq!(
        finish_task(&app, &initial, shifted).await.0,
        StatusCode::CONFLICT
    );
    assert_eq!(
        run_history(&app, &run["workflow_id"], &run["run_id"]).await,
        before
    );
    let mut explicit = valid;
    for command in explicit.as_array_mut().unwrap() {
        command["parallel_group_mode"] = json!("all");
        command["parallel_group_path"][0]["parallel_group_mode"] = json!("all");
    }
    assert_eq!(
        finish_task(&app, &initial, explicit).await.0,
        StatusCode::OK
    );
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    for event in history.as_array().unwrap().iter().skip(2) {
        assert!(event["payload"].get("parallel_group_mode").is_none());
        assert!(
            event["payload"]["parallel_group_path"][0]
                .get("parallel_group_mode")
                .is_none()
        );
    }
    observe(&database, &app, &run).await;
    runtime.close().await;
    database.remove().await;
}

async fn fail_activity(app: &Router, task: &Value) -> (StatusCode, Value) {
    request(app,"POST",&format!("/api/worker/activity-tasks/{}/fail",task["task_id"].as_str().unwrap()),
        json!({"lease_owner":task["lease_owner"],"activity_attempt_id":task["activity_attempt_id"],
        "failure":{"type":"RuntimeException","message":"original all member λ","non_retryable":false}})).await
}

#[tokio::test]
async fn retry_and_terminal_failure_preserve_member_identity_and_sibling_completion() {
    let database = TestDatabase::new().await;
    let first = database.open().await.unwrap();
    let app = router(first.clone());
    register(&app, "retry-all", json!(["echo"]), json!(["echo_activity"])).await;
    let run = start(&app, "retry-all").await;
    let initial = poll(&app, "retry-all", "workflow").await;
    let mut commands = all_activities(2);
    commands[0]["retry_policy"] = json!({"max_attempts":2,"backoff_seconds":[1]});
    assert_eq!(
        finish_task(&app, &initial, commands).await.0,
        StatusCode::OK
    );
    let member = poll(&app, "retry-all", "activity").await;
    let sibling = poll(&app, "retry-all", "activity").await;
    // Poll order is durable authored availability, not the returned result order.
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    assert_eq!(
        member["activity_execution_id"],
        history[2]["payload"]["activity_execution_id"]
    );
    let failed = fail_activity(&app, &member).await;
    assert_eq!(failed.0, StatusCode::OK, "{}", failed.1);
    assert!(poll(&app, "retry-all", "workflow").await.is_null());
    let retry_history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let retry = retry_history
        .as_array()
        .unwrap()
        .iter()
        .find(|event| event["event_type"] == "ActivityRetryScheduled")
        .unwrap();
    assert_eq!(retry["payload"]["retry_backoff_seconds"], 1);
    assert_eq!(
        retry["payload"]["activity_execution_id"],
        member["activity_execution_id"]
    );
    assert_eq!(
        retry["payload"]["parallel_group_path"],
        history[2]["payload"]["parallel_group_path"]
    );
    observe(&database, &app, &run).await;
    assert_eq!(
        complete_activity(&app, &sibling, 9007199254740994).await.0,
        StatusCode::OK
    );
    let replay = poll(&app, "retry-all", "workflow").await;
    let waiting=request(&app,"POST",&format!("/api/worker/workflow-tasks/{}/fail",replay["task_id"].as_str().unwrap()),
        json!({"lease_owner":replay["lease_owner"],"workflow_task_attempt":replay["workflow_task_attempt"],
        "failure":{"type":"WorkflowTaskWaitingForHistory","message":"one original retry is pending"}})).await;
    assert_eq!(waiting.0, StatusCode::OK, "{}", waiting.1);
    drop(app);
    first.close().await;
    let second = database.open().await.unwrap();
    let app = router(second.clone());
    assert_eq!(fail_activity(&app, &member).await.1["recorded"], false);
    let claimed = request(
        &app,
        "POST",
        "/api/worker/activity-tasks/poll",
        json!({"worker_id":"retry-all","task_queue":"test","timeout_seconds":2}),
    )
    .await;
    assert_eq!(claimed.0, StatusCode::OK, "{}", claimed.1);
    let next = &claimed.1["task"];
    assert!(next.is_object(), "{}", claimed.1);
    assert_eq!(
        next["activity_execution_id"],
        member["activity_execution_id"]
    );
    assert_ne!(next["task_id"], member["task_id"]);
    assert_ne!(next["activity_attempt_id"], member["activity_attempt_id"]);
    assert_eq!(next["attempt_number"], 2);
    assert_eq!(
        complete_activity(&app, &member, 7).await.0,
        StatusCode::CONFLICT
    );
    let terminal = fail_activity(&app, next).await;
    assert_eq!(terminal.0, StatusCode::OK, "{}", terminal.1);
    assert_eq!(fail_activity(&app, next).await.1["recorded"], false);
    observe(&database, &app, &run).await;
    let resumed = poll(&app, "retry-all", "workflow").await;
    assert_eq!(
        finish_task(
            &app,
            &resumed,
            json!([{"type":"complete_workflow","result":envelope(Payload::Boolean(false))}])
        )
        .await
        .0,
        StatusCode::OK
    );
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    no_selection(&history);
    assert_eq!(
        history
            .as_array()
            .unwrap()
            .iter()
            .filter(|event| event["event_type"] == "ActivityFailed")
            .count(),
        1
    );
    observe(&database, &app, &run).await;
    second.close().await;
    database.remove().await;
}

#[tokio::test]
async fn timer_barrier_fires_zero_and_positive_members_once_on_competing_cold_nodes() {
    let database = TestDatabase::new().await;
    let first = database.open().await.unwrap();
    let app = router(first.clone());
    register(&app, "all-timers", json!(["echo"]), json!([])).await;
    let run = start(&app, "all-timers").await;
    let task = poll(&app, "all-timers", "workflow").await;
    let commands = json!(
        [0, 1, 1]
            .into_iter()
            .enumerate()
            .map(|(index, delay)| with_path(
                json!({"type":"start_timer","delay_seconds":delay}),
                vec![entry(1, 3, index, "timer")]
            ))
            .collect::<Vec<_>>()
    );
    assert_eq!(finish_task(&app, &task, commands).await.0, StatusCode::OK);
    let prefix = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let scheduled = prefix
        .as_array()
        .unwrap()
        .iter()
        .filter(|event| event["event_type"] == "TimerScheduled")
        .map(|event| event["payload"].clone())
        .collect::<Vec<_>>();
    assert_eq!(scheduled.len(), 3);
    drop(app);
    first.close().await;
    let (one, two) = tokio::join!(database.open(), database.open());
    let (one, two) = (one.unwrap(), two.unwrap());
    let (app_a, app_b) = (router(one.clone()), router(two.clone()));
    let deadline = tokio::time::Instant::now() + std::time::Duration::from_secs(3);
    let history = loop {
        let history = run_history(&app_b, &run["workflow_id"], &run["run_id"]).await;
        if history
            .as_array()
            .unwrap()
            .iter()
            .filter(|event| event["event_type"] == "TimerFired")
            .count()
            == 3
        {
            break history;
        }
        assert!(
            tokio::time::Instant::now() < deadline,
            "original all timers did not fire"
        );
        tokio::time::sleep(std::time::Duration::from_millis(20)).await;
    };
    for scheduled in &scheduled {
        let events = history
            .as_array()
            .unwrap()
            .iter()
            .filter(|event| {
                event["event_type"] == "TimerFired"
                    && event["payload"]["timer_id"] == scheduled["timer_id"]
            })
            .collect::<Vec<_>>();
        assert_eq!(events.len(), 1);
        assert_eq!(
            events[0]["payload"]["parallel_group_path"],
            scheduled["parallel_group_path"]
        );
        assert_eq!(events[0]["payload"]["fire_at"], scheduled["fire_at"]);
    }
    no_selection(&history);
    observe(&database, &app_b, &run).await;
    let resumed = poll(&app_a, "all-timers", "workflow").await;
    assert_eq!(
        finish_task(
            &app_b,
            &resumed,
            json!([{"type":"complete_workflow","result":envelope(Payload::Boolean(true))}])
        )
        .await
        .0,
        StatusCode::OK
    );
    assert!(poll(&app_b, "all-timers", "workflow").await.is_null());
    one.close().await;
    two.close().await;
    database.remove().await;
}

#[tokio::test]
async fn immediate_root_cancellation_retains_each_nested_member_and_original_timer() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(
        &app,
        "cancel-all",
        json!(["echo"]),
        json!(["echo_activity"]),
    )
    .await;
    let run = start(&app, "cancel-all").await;
    let task = poll(&app, "cancel-all", "workflow").await;
    let commands = json!([
        with_path(
            activity(0),
            vec![entry(1, 3, 0, "mixed"), entry(1, 2, 0, "activity")]
        ),
        with_path(
            activity(1),
            vec![entry(1, 3, 1, "mixed"), entry(1, 2, 1, "activity")]
        ),
        with_path(
            json!({"type":"start_timer","delay_seconds":60}),
            vec![entry(1, 3, 2, "mixed")]
        )
    ]);
    assert_eq!(finish_task(&app, &task, commands).await.0, StatusCode::OK);
    let leased = poll(&app, "cancel-all", "activity").await;
    let prefix = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let cancelled = request(
        &app,
        "POST",
        "/api/workflows/cancel-all/cancel",
        json!({"reason":"cancel original all barrier"}),
    )
    .await;
    assert!(cancelled.0.is_success(), "{}", cancelled.1);
    assert_eq!(
        complete_activity(&app, &leased, 42).await.0,
        StatusCode::CONFLICT
    );
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    for scheduled in prefix.as_array().unwrap().iter().filter(|event| {
        matches!(
            event["event_type"].as_str(),
            Some("ActivityScheduled" | "TimerScheduled")
        )
    }) {
        let (kind, key) = if scheduled["event_type"] == "ActivityScheduled" {
            ("ActivityCancelled", "activity_execution_id")
        } else {
            ("TimerCancelled", "timer_id")
        };
        let event = history
            .as_array()
            .unwrap()
            .iter()
            .find(|event| {
                event["event_type"] == kind && event["payload"][key] == scheduled["payload"][key]
            })
            .unwrap();
        assert_eq!(
            event["payload"]["parallel_group_path"],
            scheduled["payload"]["parallel_group_path"]
        );
        if kind == "TimerCancelled" {
            assert_eq!(event["payload"]["fire_at"], scheduled["payload"]["fire_at"]);
        }
    }
    no_selection(&history);
    observe(&database, &app, &run).await;
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn member_timeout_preserves_the_other_original_timer_and_resumes_after_all_outcomes() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(
        &app,
        "expiry-all",
        json!(["echo"]),
        json!(["echo_activity"]),
    )
    .await;
    let run = start(&app, "expiry-all").await;
    let task = poll(&app, "expiry-all", "workflow").await;
    let mut member = activity(0);
    member["schedule_to_start_timeout"] = json!(1);
    let commands = json!([
        with_path(member, vec![entry(1, 2, 0, "mixed")]),
        with_path(
            json!({"type":"start_timer","delay_seconds":3}),
            vec![entry(1, 2, 1, "mixed")]
        )
    ]);
    assert_eq!(finish_task(&app, &task, commands).await.0, StatusCode::OK);
    let initial = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    observe(&database, &app, &run).await;
    let expired = request(
        &app,
        "POST",
        "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"expiry-all","task_queue":"test","timeout_seconds":2}),
    )
    .await;
    assert_eq!(expired.0, StatusCode::OK, "{}", expired.1);
    let replay = &expired.1["task"];
    assert!(replay.is_object(), "{}", expired.1);
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    assert_eq!(history[4]["event_type"], "ActivityTimedOut");
    assert_eq!(
        history[4]["payload"]["activity_execution_id"],
        initial[2]["payload"]["activity_execution_id"]
    );
    assert_eq!(
        history[4]["payload"]["parallel_group_path"],
        initial[2]["payload"]["parallel_group_path"]
    );
    let observation = observe(&database, &app, &run).await;
    assert_eq!(observation["current_waits"]["returned_count"], 1);
    assert_eq!(
        finish_task(
            &app,
            replay,
            json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])
        )
        .await
        .0,
        StatusCode::CONFLICT
    );
    let waiting=request(&app,"POST",&format!("/api/worker/workflow-tasks/{}/fail",replay["task_id"].as_str().unwrap()),
        json!({"lease_owner":replay["lease_owner"],"workflow_task_attempt":replay["workflow_task_attempt"],
            "failure":{"type":"WorkflowTaskWaitingForHistory","message":"original all timer is still pending"}})).await;
    assert_eq!(waiting.0, StatusCode::OK, "{}", waiting.1);
    wait_for_timer(&app, &run).await;
    let resumed = poll(&app, "expiry-all", "workflow").await;
    assert_eq!(
        finish_task(
            &app,
            &resumed,
            json!([{"type":"complete_workflow","result":envelope(Payload::Boolean(false))}])
        )
        .await
        .0,
        StatusCode::OK
    );
    no_selection(&run_history(&app, &run["workflow_id"], &run["run_id"]).await);
    observe(&database, &app, &run).await;
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn full_batch_boundary_keeps_bounded_wait_reads_and_every_original_member() {
    for size in [1, 100] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        register(
            &app,
            "bounded-all",
            json!(["echo"]),
            json!(["echo_activity"]),
        )
        .await;
        let run = start(&app, "bounded-all").await;
        let task = poll(&app, "bounded-all", "workflow").await;
        let mut commands = all_activities(size);
        if size == 1 {
            let only = commands[0]["parallel_group_path"][0].clone();
            commands[0]["parallel_group_path"]
                .as_array_mut()
                .unwrap()
                .push(only);
        }
        assert_eq!(finish_task(&app, &task, commands).await.0, StatusCode::OK);
        let summary = observe(&database, &app, &run).await;
        assert_eq!(summary["current_waits"]["returned_count"], size.min(50));
        assert_eq!(summary["current_waits"]["has_more"], size > 50);
        assert_eq!(summary["history_fan_out"], size);
        let cancelled = request(
            &app,
            "POST",
            "/api/workflows/bounded-all/cancel",
            json!({"reason":"retire original bounded barrier"}),
        )
        .await;
        assert!(cancelled.0.is_success(), "{}", cancelled.1);
        let history = complete_history(&app, &run).await;
        assert_eq!(
            history
                .as_array()
                .unwrap()
                .iter()
                .filter(|event| event["event_type"] == "ActivityCancelled")
                .count(),
            size
        );
        no_selection(&history);
        observe(&database, &app, &run).await;
        runtime.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn sqlite_projection_failure_rolls_back_the_entire_group_and_original_lease() {
    let database = TestDatabase::new().await;
    let TestDatabase::Sqlite(_) = &database else {
        return;
    };
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(
        &app,
        "rollback-all",
        json!(["echo"]),
        json!(["echo_activity"]),
    )
    .await;
    let run = start(&app, "rollback-all").await;
    let task = poll(&app, "rollback-all", "workflow").await;
    let before = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    database.execute("CREATE TRIGGER all_wait_fault BEFORE INSERT ON workflow_run_waits WHEN NEW.kind='activity' AND NEW.position=1 BEGIN SELECT RAISE(ABORT,'all wait projection fault'); END").await;
    assert_eq!(
        finish_task(&app, &task, all_activities(3)).await.0,
        StatusCode::SERVICE_UNAVAILABLE
    );
    assert_eq!(
        run_history(&app, &run["workflow_id"], &run["run_id"]).await,
        before
    );
    assert!(poll(&app, "rollback-all", "activity").await.is_null());
    database.execute("DROP TRIGGER all_wait_fault").await;
    assert_eq!(
        finish_task(&app, &task, all_activities(3)).await.0,
        StatusCode::OK
    );
    let member = poll(&app, "rollback-all", "activity").await;
    let before = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    database.execute("CREATE TRIGGER all_resolution_fault BEFORE UPDATE ON workflow_run_waits WHEN NEW.kind='activity' AND NEW.status='resolved' BEGIN SELECT RAISE(ABORT,'all resolution projection fault'); END").await;
    assert_eq!(
        complete_activity(&app, &member, 42).await.0,
        StatusCode::SERVICE_UNAVAILABLE
    );
    assert_eq!(
        run_history(&app, &run["workflow_id"], &run["run_id"]).await,
        before
    );
    assert!(poll(&app, "rollback-all", "workflow").await.is_null());
    database.execute("DROP TRIGGER all_resolution_fault").await;
    assert_eq!(complete_activity(&app, &member, 42).await.0, StatusCode::OK);
    observe(&database, &app, &run).await;
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn nested_mixed_barrier_retains_all_paths_and_original_timer_after_cold_reopen() {
    let database = TestDatabase::new().await;
    let first = database.open().await.unwrap();
    let app = router(first.clone());
    register(
        &app,
        "nested-worker",
        json!(["echo"]),
        json!(["echo_activity"]),
    )
    .await;
    let run = start(&app, "nested-all").await;
    let initial = poll(&app, "nested-worker", "workflow").await;
    let commands = json!([
        with_path(
            activity(0),
            vec![entry(1, 4, 0, "mixed"), entry(1, 2, 0, "activity")]
        ),
        with_path(
            activity(1),
            vec![entry(1, 4, 1, "mixed"), entry(1, 2, 1, "activity")]
        ),
        with_path(
            json!({"type":"start_timer","delay_seconds":1}),
            vec![entry(1, 4, 2, "mixed"), entry(3, 2, 0, "mixed")]
        ),
        with_path(
            activity(2),
            vec![entry(1, 4, 3, "mixed"), entry(3, 2, 1, "mixed")]
        )
    ]);
    let admitted = finish_task(&app, &initial, commands.clone()).await;
    assert_eq!(admitted.0, StatusCode::OK, "{}", admitted.1);
    let prefix = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    for index in 0..4 {
        assert_eq!(
            prefix[index + 2]["payload"]["parallel_group_path"],
            commands[index]["parallel_group_path"]
        );
    }
    assert_eq!(
        observe(&database, &app, &run).await["current_waits"]["returned_count"],
        4
    );
    drop(app);
    first.close().await;
    let second = database.open().await.unwrap();
    let app = router(second.clone());
    let fired = wait_for_timer(&app, &run).await;
    assert_eq!(&fired.as_array().unwrap()[..6], prefix.as_array().unwrap());
    let timer = fired
        .as_array()
        .unwrap()
        .iter()
        .find(|event| event["event_type"] == "TimerFired")
        .unwrap();
    assert_eq!(
        timer["payload"]["parallel_group_path"],
        commands[2]["parallel_group_path"]
    );
    assert_eq!(
        timer["payload"]["timer_id"],
        prefix[4]["payload"]["timer_id"]
    );
    assert_eq!(
        observe(&database, &app, &run).await["current_waits"]["returned_count"],
        3
    );
    for _ in 0..3 {
        let activity = poll(&app, "nested-worker", "activity").await;
        assert_eq!(
            complete_activity(&app, &activity, 42).await.0,
            StatusCode::OK
        );
    }
    let resumed = poll(&app, "nested-worker", "workflow").await;
    assert_eq!(
        finish_task(
            &app,
            &resumed,
            json!([{"type":"complete_workflow","result":envelope(Payload::Long(42))}])
        )
        .await
        .0,
        StatusCode::OK
    );
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    no_selection(&history);
    observe(&database, &app, &run).await;
    second.close().await;
    database.remove().await;
}
