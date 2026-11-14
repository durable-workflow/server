use super::*;
use base64::{Engine, engine::general_purpose::URL_SAFE_NO_PAD};


// Fixture statements are compiled templates. All run identities remain bound,
// including after MySQL's placeholder-only dialect conversion.
async fn fixture(database: &TestDatabase, run: &Value, statement: &'static str) {
    let run_id = run["run_id"].as_str().unwrap();
    match database {
        TestDatabase::Sqlite(directory) => {
            let pool = sqlx::SqlitePool::connect(&format!("sqlite://{}", directory.path().join("runtime.sqlite").display())).await.unwrap();
            sqlx::query(statement).bind(run_id).execute(&pool).await.unwrap();
            pool.close().await;
        }
        TestDatabase::Postgres { options, .. } => {
            let pool = sqlx::postgres::PgPoolOptions::new().connect_with(options.as_ref().clone()).await.unwrap();
            sqlx::query(statement).bind(run_id).execute(&pool).await.unwrap();
            pool.close().await;
        }
        TestDatabase::MySql { options, .. } => {
            let pool = sqlx::mysql::MySqlPoolOptions::new().connect_with(options.as_ref().clone()).await.unwrap();
            sqlx::query(sqlx::AssertSqlSafe(statement.replace("$1", "?"))).bind(run_id).execute(&pool).await.unwrap();
            pool.close().await;
        }
    }
}

const QUERY: &str = "workflow_type=echo&change_id=pricing-v2&min_version=1";

#[tokio::test]
async fn version_usage_does_not_reserve_an_existing_workflow_id() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let started = start(&app, "version-usage").await;
    let (status, described) = request(&app, "GET", "/api/workflows/version-usage", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{described}");
    assert_eq!(described["workflow_id"], "version-usage");
    assert_eq!(described["run_id"], started["run_id"]);
    assert_eq!(usage(&app, QUERY).await["counts"]["total"], 1);
    runtime.close().await;
    database.remove().await;
}

async fn usage(app: &Router, query: &str) -> Value {
    let (status, body) = request(app, "GET", &format!("/api/workflow-version-usage?{query}"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body
}

async fn recorded(app: &Router, workflow: &str, version: i32) -> Value {
    let run = start(app, workflow).await;
    let task = poll(app, "version-reader-worker", "workflow").await;
    assert_eq!(task["run_id"], run["run_id"]);
    let (status, body) = finish_task(app, &task,
        json!([{"type":"record_version_marker", "change_id":"pricing-v2",
            "version":version,"min_supported":-1,"max_supported":2}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    run
}

#[tokio::test]
async fn repeated_decisions_with_a_wider_valid_range_keep_the_recorded_version() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "version-reader-worker", json!(["echo"]), json!([])).await;
    let run = start(&app, "version-expanding-range").await;
    let task = poll(&app, "version-reader-worker", "workflow").await;
    assert_eq!(task["run_id"], run["run_id"]);
    let (status, body) = finish_task(&app, &task, json!([
        {"type":"record_version_marker","change_id":"pricing-v2","version":1,"min_supported":0,"max_supported":1},
        {"type":"record_version_marker","change_id":"pricing-v2","version":1,"min_supported":-1,"max_supported":2}
    ])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let answer = usage(&app, QUERY).await;
    let metadata: Vec<_> = history.as_array().unwrap().iter().map(|event|
        json!({"sequence":event["sequence"],"event_type":event["event_type"],"type":event["type"]})).collect();
    assert_eq!(answer["counts"], json!({"total":1,"legacy":0,"current":1,"unknown":0}),
        "answer={answer}; history={metadata:?}");
    assert_eq!(answer["runs"][0]["recorded_version"], 1);
    assert_eq!(answer["runs"][0]["unknown_reason"], Value::Null);
    assert_eq!(run_history(&app, &run["workflow_id"], &run["run_id"]).await, history);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn open_runs_report_all_counts_before_filter_and_never_upgrade_missing_markers() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "version-reader-worker", json!(["echo"]), json!([])).await;
    let legacy = recorded(&app, "version-usage-old", 0).await;
    let current = recorded(&app, "version-usage-new", 1).await;
    let closed = recorded(&app, "version-usage-closed", -1).await;
    fixture(&database, &closed, "UPDATE workflow_runs SET status='completed' WHERE id=$1").await;
    let absent = start(&app, "version-usage-unreached").await;
    let page = usage(&app, QUERY).await;
    assert_eq!(page["schema_version"], 1);
    assert_eq!(page["namespace"], "default");
    assert_eq!(page["consistency"], "live");
    assert_eq!(page["counts"], json!({"total":3,"legacy":1,"current":1,"unknown":1}));
    assert_eq!(page["returned_count"], 3);
    assert!(page.get("safe_to_remove").is_none());
    let runs = page["runs"].as_array().unwrap();
    for (run, state, version, reason) in [(&legacy,"legacy",json!(0),Value::Null),(&current,"current",json!(1),Value::Null),(&absent,"unknown",Value::Null,json!("marker_absent"))] {
        let actual = runs.iter().find(|value| value["run_id"] == run["run_id"]).unwrap();
        assert_eq!(actual["state"], state);
        assert_eq!(actual["recorded_version"], version);
        assert_eq!(actual["unknown_reason"], reason);
    }
    let filtered = usage(&app, &format!("{QUERY}&state=unknown&limit=1")).await;
    assert_eq!(filtered["counts"], page["counts"]);
    assert_eq!(filtered["runs"][0]["run_id"], absent["run_id"]);
    assert_eq!(filtered["has_more"], false);
    assert_eq!(filtered["next_cursor"], Value::Null);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn pruned_missing_prefix_and_conflicting_history_remain_unknown() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "version-reader-worker", json!(["echo"]), json!([])).await;
    let pruned = recorded(&app, "version-usage-pruned", 2).await;
    let prefix = recorded(&app, "version-usage-prefix", 2).await;
    let conflict = recorded(&app, "version-usage-conflict", 2).await;
    let unsupported = recorded(&app, "version-usage-format", 2).await;
    let late_start = recorded(&app, "version-usage-late-start", 2).await;
    fixture(&database, &pruned, "UPDATE workflow_runs SET details_pruned_at='2026-01-01 00:00:00' WHERE id=$1").await;
    fixture(&database, &prefix, "DELETE FROM workflow_history_events WHERE workflow_run_id=$1 AND sequence=1").await;
    // A retained malformed decision must not become current merely because its
    // version is newer. This is private storage-corruption regression data.
    fixture(&database, &conflict, r#"UPDATE workflow_history_events SET payload='{"change_id":"pricing-v2","version":2,"min_supported":3,"max_supported":4,"sequence":1}' WHERE workflow_run_id=$1 AND event_type='VersionMarkerRecorded'"#).await;
    fixture(&database, &unsupported, "UPDATE workflow_history_events SET payload='[]' WHERE workflow_run_id=$1 AND event_type='WorkflowStarted'").await;
    fixture(&database, &late_start, "INSERT INTO workflow_history_events(id,workflow_run_id,sequence,event_type,payload,recorded_at) SELECT 'version-usage-late-admission',workflow_run_id,MAX(sequence)+1,'StartAccepted','{}','2026-01-01 00:00:00' FROM workflow_history_events WHERE workflow_run_id=$1 GROUP BY workflow_run_id").await;
    fixture(&database, &late_start, "UPDATE workflow_runs SET last_history_sequence=last_history_sequence+1 WHERE id=$1").await;
    let page = usage(&app, QUERY).await;
    assert_eq!(page["counts"], json!({"total":5,"legacy":0,"current":0,"unknown":5}));
    for run in [&pruned, &prefix, &unsupported, &late_start] {
        let record = page["runs"].as_array().unwrap().iter().find(|value| value["run_id"] == run["run_id"]).unwrap();
        assert_eq!(record["unknown_reason"], "history_unavailable");
    }
    let record = page["runs"].as_array().unwrap().iter().find(|value| value["run_id"] == conflict["run_id"]).unwrap();
    assert_eq!(record["unknown_reason"], "history_invalid");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn keyset_cursors_bind_query_identity_and_keep_the_initial_upper_bound() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    for workflow in ["version-page-a", "version-page-b", "version-page-c"] {
        start(&app, workflow).await;
    }
    let first = usage(&app, &format!("{QUERY}&limit=1")).await;
    assert_eq!(first["has_more"], true);
    let cursor = first["next_cursor"].as_str().unwrap();
    let (body, tag) = cursor.split_once('.').unwrap();
    let cursor_body: Value = serde_json::from_slice(&URL_SAFE_NO_PAD.decode(body).unwrap()).unwrap();
    for (field, value) in [("after", "0"), ("through", "ZZZZ")] {
        let mut altered = cursor_body.clone();
        altered[field] = json!(value);
        let altered = format!("{}.{tag}", URL_SAFE_NO_PAD.encode(serde_json::to_vec(&altered).unwrap()));
        let (status, body) = request(&app, "GET", &format!("/api/workflow-version-usage?{QUERY}&cursor={altered}"), Value::Null).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
        assert_eq!(body["reason"], "validation_failed");
    }
    for changed in ["workflow_type=other&change_id=pricing-v2&min_version=1", "workflow_type=echo&change_id=other&min_version=1", "workflow_type=echo&change_id=pricing-v2&min_version=2"] {
        let (status, body) = request(&app, "GET", &format!("/api/workflow-version-usage?{changed}&cursor={cursor}"), Value::Null).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
        assert_eq!(body["reason"], "validation_failed");
    }
    // Cross the ULID millisecond boundary so this admission is above the
    // captured upper ID rather than relying on same-millisecond entropy order.
    tokio::time::sleep(std::time::Duration::from_millis(2)).await;
    let later = start(&app, "version-page-later").await;
    assert!(later["run_id"].as_str().unwrap() > first["through_run_id"].as_str().unwrap());
    fixture(&database, &first["runs"][0], "UPDATE workflow_runs SET status='completed' WHERE id=$1").await;
    let second = usage(&app, &format!("{QUERY}&limit=200&cursor={cursor}")).await;
    assert_eq!(second["through_run_id"], first["through_run_id"]);
    assert_eq!(second["counts"]["total"], 2);
    assert_eq!(second["returned_count"], 2);
    assert_eq!(second["has_more"], false);
    let first_id = first["runs"][0]["run_id"].as_str().unwrap();
    assert!(second["runs"].as_array().unwrap().iter().all(|run| run["run_id"].as_str().unwrap() > first_id));
    assert_eq!(usage(&app, QUERY).await["counts"]["total"], 3);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn named_namespace_and_exact_workflow_type_do_not_leak_other_runs() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    for namespace in ["version-tenant-a", "version-tenant-b"] {
        namespaces::create(&app, namespace).await;
        namespaces::start_scoped(&app, namespace, &format!("{namespace}-run")).await;
    }
    start(&app, "version-default").await;
    start(&app, "version-default-second").await;
    let default_page = usage(&app, &format!("{QUERY}&limit=1")).await;
    let foreign_cursor = default_page["next_cursor"].as_str().unwrap();
    let (status, body) = namespaces::scoped(&app, "version-tenant-a", "GET",
        &format!("/api/workflow-version-usage?{QUERY}&cursor={foreign_cursor}"), Value::Null).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    assert_eq!(body["reason"], "validation_failed");
    let path = format!("/api/workflow-version-usage?{QUERY}&limit=1");
    for namespace in ["version-tenant-a", "version-tenant-b"] {
        let (status, body) = namespaces::scoped(&app, namespace, "GET", &path, Value::Null).await;
        assert_eq!(status, StatusCode::OK, "{body}");
        assert_eq!(body["namespace"], namespace);
        assert_eq!(body["counts"]["total"], 1);
        assert_eq!(body["runs"][0]["instance_id"], format!("{namespace}-run"));
    }
    let wrong_case = usage(&app, "workflow_type=ECHO&change_id=pricing-v2&min_version=1").await;
    assert_eq!(wrong_case["counts"]["total"], 0);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn all_canonical_open_statuses_participate_including_an_archived_open_run() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    for (name, statement) in [
        ("pending", "UPDATE workflow_runs SET status='pending' WHERE id=$1"),
        ("running", "UPDATE workflow_runs SET status='running' WHERE id=$1"),
        ("waiting", "UPDATE workflow_runs SET status='waiting',archived_at='2026-01-01 00:00:00' WHERE id=$1"),
        ("completed", "UPDATE workflow_runs SET status='completed' WHERE id=$1"),
        ("failed", "UPDATE workflow_runs SET status='failed' WHERE id=$1"),
        ("cancelled", "UPDATE workflow_runs SET status='cancelled' WHERE id=$1"),
        ("terminated", "UPDATE workflow_runs SET status='terminated' WHERE id=$1"),
    ] {
        let run = start(&app, &format!("version-status-{name}")).await;
        fixture(&database, &run, statement).await;
    }
    let answer = usage(&app, QUERY).await;
    assert_eq!(answer["counts"], json!({"total":3,"legacy":0,"current":0,"unknown":3}));
    let mut statuses: Vec<_> = answer["runs"].as_array().unwrap().iter().map(|run| run["status"].as_str().unwrap()).collect();
    statuses.sort_unstable();
    assert_eq!(statuses, ["pending", "running", "waiting"]);
    assert_eq!(request(&app,"GET","/api/cluster/info",Value::Null).await.1["capabilities"]["workflow_version_usage"], true);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn invalid_requests_refuse_instead_of_returning_an_empty_success() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    for query in ["", "workflow_type=echo&change_id=pricing-v2", "workflow_type=echo&change_id=pricing-v2&min_version=2147483648", "workflow_type=echo&change_id=pricing-v2&min_version=1&limit=0", "workflow_type=echo&change_id=pricing-v2&min_version=1&limit=201", "workflow_type=echo&change_id=pricing-v2&min_version=1&state=closed", "workflow_type=echo&change_id=pricing-v2&min_version=1&cursor=invalid"] {
        let (status, body) = request(&app, "GET", &format!("/api/workflow-version-usage?{query}"), Value::Null).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{query}: {body}");
        assert_eq!(body["reason"], "validation_failed");
    }
    let empty = usage(&app, QUERY).await;
    assert_eq!(empty["counts"], json!({"total":0,"legacy":0,"current":0,"unknown":0}));
    assert_eq!(empty["through_run_id"], Value::Null);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn parity_missing_or_foreign_instance_metadata_stays_in_unknown_counts() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app,"version-reader-worker",json!(["echo"]),json!([])).await;
    let missing = recorded(&app,"version-missing-owner",1).await;
    let foreign = recorded(&app,"version-foreign-owner",1).await;
    fixture(&database,&json!({"run_id":missing["workflow_id"]}),"DELETE FROM workflow_instances WHERE id=$1").await;
    fixture(&database,&json!({"run_id":foreign["workflow_id"]}),"UPDATE workflow_instances SET namespace='unrelated-owner' WHERE id=$1").await;
    let answer = usage(&app, &format!("{QUERY}&limit=1")).await;
    assert_eq!(answer["counts"],json!({"total":2,"legacy":0,"current":0,"unknown":2}));
    assert_eq!(answer["runs"][0]["unknown_reason"],"history_unavailable");
    assert_eq!(answer["has_more"],true);
    let next = usage(&app,&format!("{QUERY}&limit=1&cursor={}",answer["next_cursor"].as_str().unwrap())).await;
    assert_eq!(next["counts"],answer["counts"]);
    assert_eq!(next["runs"][0]["unknown_reason"],"history_unavailable");
    assert_ne!(next["runs"][0]["run_id"],answer["runs"][0]["run_id"]);
    assert_eq!(next["has_more"],false);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn parity_future_history_formats_do_not_report_current_usage() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app,"version-reader-worker",json!(["echo"]),json!([])).await;
    let future = recorded(&app,"version-future-event",1).await;
    let marker = recorded(&app,"version-future-marker",1).await;
    fixture(&database,&future,"INSERT INTO workflow_history_events(id,workflow_run_id,sequence,event_type,payload,recorded_at) SELECT 'version-usage-future-event',workflow_run_id,MAX(sequence)+1,'FutureHistoryEvent','{}','2026-01-01 00:00:00' FROM workflow_history_events WHERE workflow_run_id=$1 GROUP BY workflow_run_id").await;
    fixture(&database,&future,"UPDATE workflow_runs SET last_history_sequence=last_history_sequence+1 WHERE id=$1").await;
    fixture(&database,&marker,r#"UPDATE workflow_history_events SET payload='{"change_id":"pricing-v2","version":1,"min_supported":0,"max_supported":2,"sequence":1,"future_format":2}' WHERE workflow_run_id=$1 AND event_type='VersionMarkerRecorded'"#).await;
    let answer = usage(&app,QUERY).await;
    assert_eq!(answer["counts"],json!({"total":2,"legacy":0,"current":0,"unknown":2}));
    assert!(answer["runs"].as_array().unwrap().iter().all(|run|run["unknown_reason"]=="history_unavailable"));
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn malformed_reuse_provenance_is_unknown_in_stored_history() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app,"version-reader-worker",json!(["echo"]),json!([])).await;
    let run = recorded(&app,"version-provenance",1).await;
    for statement in [
        r#"UPDATE workflow_history_events SET payload='{"change_id":"pricing-v2","version":1,"min_supported":0,"max_supported":1,"sequence":1,"reused_from_run_id":"source"}' WHERE workflow_run_id=$1 AND event_type='VersionMarkerRecorded'"#,
        r#"UPDATE workflow_history_events SET payload='{"change_id":"pricing-v2","version":1,"min_supported":0,"max_supported":1,"sequence":1,"reused_from_run_id":[],"reused_recorded_at":"2026-01-02T03:04:05Z"}' WHERE workflow_run_id=$1 AND event_type='VersionMarkerRecorded'"#,
        r#"UPDATE workflow_history_events SET payload='{"change_id":"pricing-v2","version":1,"min_supported":0,"max_supported":1,"sequence":1,"reused_from_run_id":"source","reused_recorded_at":""}' WHERE workflow_run_id=$1 AND event_type='VersionMarkerRecorded'"#,
        r#"UPDATE workflow_history_events SET payload='{"change_id":"pricing-v2","version":1,"min_supported":0,"max_supported":1,"sequence":1,"reused_from_run_id":"source","reused_recorded_at":"not-a-clock"}' WHERE workflow_run_id=$1 AND event_type='VersionMarkerRecorded'"#,
    ] {
        fixture(&database,&run,statement).await;
        let answer = usage(&app,QUERY).await;
        assert_eq!(answer["counts"],json!({"total":1,"legacy":0,"current":0,"unknown":1}));
        assert_eq!(answer["runs"][0]["unknown_reason"],"history_invalid");
    }
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn an_actual_redriven_marker_retains_a_queryable_recorded_decision() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let registered = request(&app,"POST","/api/worker/register",json!({"worker_id":"worker","task_queue":"test",
        "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":["a","b"],
        "workflow_definition_fingerprints":{"echo":redrive::FINGERPRINT}})).await;
    assert_eq!(registered.0,StatusCode::CREATED,"{}",registered.1);
    let source = redrive::failed_with(&app,"version-reused-marker",json!({}),vec![
        json!({"type":"record_version_marker","change_id":"pricing-v2","version":1,"min_supported":0,"max_supported":1})
    ]).await;
    let (status, successor) = request(&app,"POST",&format!("/api/workflows/version-reused-marker/runs/{}/redrive",source["run_id"].as_str().unwrap()),json!({})).await;
    assert_eq!(status,StatusCode::ACCEPTED,"{successor}");
    let before = run_history(&app,&source["workflow_id"],&successor["run_id"]).await;
    let marker = before.as_array().unwrap().iter().find(|event|event["event_type"]=="VersionMarkerRecorded").unwrap();
    assert_eq!(marker["payload"]["reused_from_run_id"],source["run_id"]);
    assert!(chrono::DateTime::parse_from_rfc3339(marker["payload"]["reused_recorded_at"].as_str().unwrap()).is_ok());
    let answer = usage(&app,QUERY).await;
    assert_eq!(answer["counts"],json!({"total":1,"legacy":0,"current":1,"unknown":0}),"{answer}");
    assert_eq!(answer["runs"][0]["run_id"],successor["run_id"]);
    assert_eq!(run_history(&app,&source["workflow_id"],&successor["run_id"]).await,before);
    runtime.close().await;
    database.remove().await;
}
