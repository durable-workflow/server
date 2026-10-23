use super::*;
use sqlx::AssertSqlSafe;

const PATH: &str = "/api/task-queues/test/build-ids";

async fn call(app: &Router, token: &str, namespace: &str, method: &str, path: &str, body: Value) -> (StatusCode, Value) {
    let (status, _, body) =
        admission::admission_http(app, method, path, false, Some(token), Some("2"), Some(namespace), body).await;
    (status, body)
}

async fn register_worker(app: &Router, namespace: &str, worker: &str, facts: Value) {
    let mut body = json!({"worker_id":worker,"task_queue":"test","runtime":"php",
        "supported_workflow_types":["echo"],"supported_activity_types":[]});
    for (key, value) in facts.as_object().unwrap() {
        body[key] = value.clone();
    }
    let (status, receipt) = namespaces::scoped(app, namespace, "POST", "/api/worker/register", body).await;
    assert_eq!(status, StatusCode::CREATED, "{receipt}");
}

async fn set_worker(database: &TestDatabase, worker: &str, heartbeat: Option<&str>, created: &str, status: &str) {
    let heartbeat = heartbeat.map_or_else(|| "NULL".to_owned(), |heartbeat| format!("'{heartbeat}'"));
    database
        .execute(AssertSqlSafe(format!(
            "UPDATE workflow_worker_registrations SET last_heartbeat_at={heartbeat},created_at='{created} 00:00:00',status='{status}' WHERE worker_id='{worker}'"
        )))
        .await;
}

async fn set_task(database: &TestDatabase, run: &Value, assignments: &str) {
    let run = run["run_id"].as_str().unwrap();
    database
        .execute(AssertSqlSafe(format!(
            "UPDATE workflow_tasks SET {assignments} WHERE workflow_run_id='{run}' AND task_type='workflow'"
        )))
        .await;
}

async fn set_run_build(database: &TestDatabase, run: &Value, build: &str) {
    let run = run["run_id"].as_str().unwrap();
    database
        .execute(AssertSqlSafe(format!("UPDATE workflow_runs SET compatibility='{build}' WHERE id='{run}'")))
        .await;
}

fn idle() -> Value {
    json!({"status":"idle","operator_visible_signal":null,"message":null,"total_count":0,"ready_count":0,"leased_count":0})
}

#[allow(clippy::too_many_arguments)]
fn entry(
    build_id: Option<&str>,
    status: &str,
    counts: [i64; 4],
    runtimes: Value,
    sdk_versions: Value,
    fingerprints: (i64, Value),
    seen: (Option<&String>, Option<&String>),
    pending: Value,
) -> Value {
    let [active, draining, stale, total] = counts;
    json!({"build_id":build_id,"rollout_status":status,"drain_intent":"active","drained_at":null,
        "promoted_at":null,"rolled_back_at":null,"new_start_selected":false,"active_worker_count":active,
        "draining_worker_count":draining,"stale_worker_count":stale,"total_worker_count":total,
        "runtimes":runtimes,"sdk_versions":sdk_versions,"workflow_definition_fingerprint_count":fingerprints.0,
        "workflow_definition_fingerprint_conflicts":fingerprints.1,"last_heartbeat_at":seen.0,
        "first_seen_at":seen.1,"pending_workflow_tasks":pending})
}

// Published PHP 2.10.0's TaskQueueBuildIdRolloutSnapshot: registrations
// grouped by trimmed build id with their pending workflow tasks, ordered by
// rollout status with the unversioned cohort after a build id, then by the
// latest heartbeat, then by first appearance.
#[tokio::test]
async fn build_ids_group_workers_and_pending_tasks_as_php_does() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap().with_query_task_config(
        durable_workflow_server::runtime::QueryTaskConfig {
            worker_stale_after_seconds: 600,
            ..Default::default()
        },
    );
    let app = router(runtime.clone());
    // Native starts every run unpinned; build ids are stamped as PHP would.
    let mut runs = Vec::new();
    for workflow in ["pinned-v1", "run-v9", "leased-v9", "run-v8", "blank-v2", "unversioned", "completed-v7", "elsewhere-v6"] {
        runs.push(start(&app, workflow).await);
    }
    set_task(&database, &runs[0], "compatibility='v1'").await;
    set_run_build(&database, &runs[1], "v9").await;
    set_task(&database, &runs[2], "compatibility=' v9 ',status='leased',lease_owner='w-gone',leased_at='2026-01-01 00:00:00',lease_expires_at='2037-01-01 00:00:00'").await;
    set_run_build(&database, &runs[3], "v8").await;
    set_task(&database, &runs[4], "compatibility='  '").await;
    set_run_build(&database, &runs[4], "v2").await;
    set_task(&database, &runs[6], "compatibility='v7',status='completed'").await;
    set_task(&database, &runs[7], "compatibility='v6',queue='elsewhere'").await;

    let order = json!({"Order":"fp-a","Ship":"fp-s"});
    register_worker(&app, "default", "w-v2-a", json!({"build_id":" v2 ","runtime":"python","sdk_version":"1.10.0","workflow_definition_fingerprints":order})).await;
    register_worker(&app, "default", "w-v2-b", json!({"build_id":"v2","sdk_version":"1.9.0","workflow_definition_fingerprints":{"Order":"fp-b","Ship":"fp-s"}})).await;
    register_worker(&app, "default", "w-v2-c", json!({"build_id":"v2","sdk_version":"1.9.0"})).await;
    register_worker(&app, "default", "w-v3", json!({"build_id":"v3","sdk_version":"2.0.0"})).await;
    // Fingerprints keyed by an integer-like workflow type fail PHP's string check.
    register_worker(&app, "default", "w-z0", json!({"build_id":"v0","sdk_version":"2.0.0","workflow_definition_fingerprints":{"7":"fp-7"}})).await;
    register_worker(&app, "default", "w-unv-a", json!({"sdk_version":"10"})).await;
    register_worker(&app, "default", "w-unv-b", json!({"build_id":"   ","sdk_version":"9"})).await;
    register_worker(&app, "default", "w-v1", json!({"build_id":"v1","sdk_version":"1.0.0"})).await;
    register_worker(&app, "default", "w-v1-declared", json!({"build_id":"v1","sdk_version":"1.0.0"})).await;
    let newest = chrono::DateTime::from_timestamp(chrono::Utc::now().timestamp() - 60, 0).unwrap();
    let older = newest - chrono::Duration::seconds(10);
    let sql = |time: chrono::DateTime<chrono::Utc>| time.format("%Y-%m-%d %H:%M:%S").to_string();
    let (t1, t2) = (sql(newest), sql(older));
    for (worker, heartbeat, created, status) in [
        ("w-v2-a", Some(t1.as_str()), "2026-01-02", "active"),
        ("w-v2-b", Some(t2.as_str()), "2026-01-01", "active"),
        ("w-v2-c", Some(t2.as_str()), "2026-01-03", "draining"),
        ("w-v3", Some(t1.as_str()), "2026-02-01", "active"),
        ("w-z0", Some(t1.as_str()), "2026-02-02", "active"),
        ("w-unv-a", Some(t2.as_str()), "2026-03-02", "active"),
        // A worker that never heartbeated is not stale.
        ("w-unv-b", None, "2026-03-01", "active"),
        ("w-v1", Some("2020-01-01 00:00:00"), "2026-04-01", "active"),
        // A declared stale status counts as stale.
        ("w-v1-declared", Some(t1.as_str()), "2026-04-02", "stale"),
    ] {
        set_worker(&database, worker, heartbeat, created, status).await;
    }
    let json_time = |time: chrono::DateTime<chrono::Utc>| time.format("%Y-%m-%dT%H:%M:%S.000000Z").to_string();
    let (j1, j2) = (json_time(newest), json_time(older));
    let first = |day: &str| format!("{day}T00:00:00.000000Z");
    let (v2, v3, v0, unversioned, v1) =
        (first("2026-01-01"), first("2026-02-01"), first("2026-02-02"), first("2026-03-01"), first("2026-04-01"));
    let pending = |status: &str, total: i64, ready: i64, leased: i64| {
        json!({"status":status,"operator_visible_signal":null,"message":null,"total_count":total,"ready_count":ready,"leased_count":leased})
    };
    let missing = |total: i64, ready: i64, leased: i64, message: &str| {
        json!({"status":"no_compatible_worker","operator_visible_signal":"no_compatible_worker","message":message,
            "total_count":total,"ready_count":ready,"leased_count":leased})
    };
    let nobody = |build: &str, pending: Value| {
        entry(Some(build), "no_workers", [0, 0, 0, 0], json!([]), json!([]), (0, json!([])), (None, None), pending)
    };
    let expected = json!({"namespace":"default","task_queue":"test","stale_after_seconds":600,"build_ids":[
        entry(Some("v3"), "active", [1, 0, 0, 1], json!(["php"]), json!(["2.0.0"]), (0, json!([])),
            (Some(&j1), Some(&v3)), idle()),
        entry(Some("v0"), "active", [1, 0, 0, 1], json!(["php"]), json!(["2.0.0"]), (0, json!([])),
            (Some(&j1), Some(&v0)), idle()),
        entry(None, "active", [2, 0, 0, 2], json!(["php"]), json!([9, 10]), (0, json!([])),
            (Some(&j2), Some(&unversioned)), pending("pending", 1, 1, 0)),
        entry(Some("v2"), "active_with_draining", [2, 1, 0, 3], json!(["php", "python"]), json!(["1.10.0", "1.9.0"]),
            (3, json!([{"workflow_type":"Order","fingerprint_count":2}])), (Some(&j1), Some(&v2)),
            pending("pending", 1, 1, 0)),
        entry(Some("v1"), "stale_only", [0, 0, 2, 2], json!(["php"]), json!(["1.0.0"]), (0, json!([])),
            (Some(&j1), Some(&v1)),
            missing(1, 1, 0, "This build id has 1 pending workflow task but no active compatible worker.")),
        nobody("v9", missing(2, 1, 1, "This build id has 2 pending workflow tasks but no active compatible worker.")),
        nobody("v8", missing(1, 1, 0, "This build id has 1 pending workflow task but no active compatible worker.")),
    ]});
    let (status, body) = request(&app, "GET", PATH, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body, expected);

    // Promote, drain and resume change nothing: native keeps no rollouts.
    for action in ["promote", "drain", "resume"] {
        let (status, refused) = request(&app, "POST", &format!("{PATH}/{action}"), json!({"build_id":"v2"})).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{refused}");
        assert_eq!(
            refused,
            json!({"reason":"task_queue_build_id_rollout_unqualified","message":"task_queue_build_id_rollout_unqualified"})
        );
    }
    assert_eq!(request(&app, "GET", PATH, Value::Null).await.1, expected);
    runtime.close().await;
    database.remove().await;
}

// A queue without workers or pending workflow tasks reports no build ids.
#[tokio::test]
async fn an_empty_queue_has_no_build_ids() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "other-queue-worker", json!(["echo"]), json!([])).await;
    let (status, body) = request(&app, "GET", "/api/task-queues/empty/build-ids", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body, json!({"namespace":"default","task_queue":"empty","stale_after_seconds":30,"build_ids":[]}));
    runtime.close().await;
    database.remove().await;
}

// The route is namespace-scoped and needs an operator or administrator, as
// the other task queue routes do; rollout changes are refused in every
// namespace.
#[tokio::test]
async fn build_ids_follow_task_queue_roles_and_namespaces() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap().with_role_tokens(
        Some("worker-token".into()),
        Some("operator-token".into()),
        Some("admin-token".into()),
    );
    let app = router(runtime.clone());
    namespaces::create(&app, "builds").await;
    register_worker(&app, "builds", "w-named", json!({"build_id":"nv1","sdk_version":"2.0.0"})).await;
    register_worker(&app, "default", "w-default", json!({"build_id":"dv1"})).await;
    namespaces::start_scoped(&app, "builds", "named-start").await;

    let (status, named) = call(&app, "operator-token", "builds", "GET", PATH, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{named}");
    assert_eq!(named["namespace"], "builds");
    let cohorts: Vec<_> = named["build_ids"]
        .as_array()
        .unwrap()
        .iter()
        .map(|entry| (entry["build_id"].clone(), entry["rollout_status"].clone(), entry["pending_workflow_tasks"]["status"].clone()))
        .collect();
    assert_eq!(
        cohorts,
        // The start is pinned to the queue's one build, as on published 2.11.1.
        vec![(json!("nv1"), json!("active"), json!("pending"))],
        "{named}"
    );
    let (status, default) = call(&app, "admin-token", "default", "GET", PATH, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{default}");
    assert_eq!(default["build_ids"].as_array().unwrap().len(), 1, "{default}");
    assert_eq!(default["build_ids"][0]["build_id"], "dv1");

    for namespace in ["default", "builds", "missing"] {
        let listing = call(&app, "worker-token", namespace, "GET", "/api/task-queues/test", Value::Null).await;
        assert_eq!(listing.0, StatusCode::FORBIDDEN, "{}", listing.1);
        assert_eq!(call(&app, "worker-token", namespace, "GET", PATH, Value::Null).await, listing);
        let action = call(&app, "worker-token", namespace, "POST", &format!("{PATH}/drain"), json!({"build_id":null})).await;
        assert_eq!(action, listing);
    }
    let unknown = call(&app, "operator-token", "missing", "GET", "/api/task-queues/test", Value::Null).await;
    assert_eq!(unknown.0, StatusCode::NOT_FOUND, "{}", unknown.1);
    assert_eq!(unknown.1["reason"], "namespace_not_found");
    assert_eq!(call(&app, "operator-token", "missing", "GET", PATH, Value::Null).await, unknown);
    for namespace in ["default", "builds"] {
        for action in ["promote", "drain", "resume"] {
            let (status, refused) =
                call(&app, "operator-token", namespace, "POST", &format!("{PATH}/{action}"), json!({"build_id":"nv1"})).await;
            assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{refused}");
            assert_eq!(refused["reason"], "task_queue_build_id_rollout_unqualified");
        }
    }
    runtime.close().await;
    database.remove().await;
}
