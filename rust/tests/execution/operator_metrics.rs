use super::*;

const METRICS: &str = "/api/system/operator-metrics";
const HEALTH: &str = "/api/system/health";

async fn metrics(app: &Router) -> Value {
    let (status, body) = request(app, "GET", METRICS, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["namespace"], "default");
    body["operator_metrics"].clone()
}

fn warnings(health: &Value) -> Vec<String> {
    health["health"]["checks"]
        .as_array()
        .unwrap()
        .iter()
        .filter(|check| check["status"] != "ok")
        .map(|check| check["name"].as_str().unwrap().to_owned())
        .collect()
}

#[tokio::test]
async fn operator_metrics_and_health_count_a_waiting_run_as_published_php_does() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let empty = metrics(&app).await;
    for section in [
        "runs",
        "tasks",
        "activities",
        "backlog",
        "repair",
        "starts",
        "history",
        "command_contracts",
        "projections",
        "schedules",
        "workers",
        "backend",
        "structural_limits",
        "update_wait",
        "repair_policy",
        "matching_role",
        "sticky_execution",
        "worker_sessions",
        "capacity_evidence",
        "runtime_external_payload_cleanup",
    ] {
        assert!(empty[section].is_object(), "{section}");
    }
    assert_eq!(
        [&empty["runs"]["total"], &empty["tasks"]["open"]],
        [&json!(0), &json!(0)]
    );
    let (status, health) = request(&app, "GET", HEALTH, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{health}");
    assert_eq!(
        [
            &health["namespace"],
            &health["retention_mode"],
            &health["retention_days"]
        ],
        [&json!("default"), &json!("bounded"), &json!(30)]
    );
    // PHP 2.x's twelve checks plus Workflow 2.24.0's replay_blocked_runs.
    assert_eq!(health["health"]["checks"].as_array().unwrap().len(), 13);
    assert_eq!(health["health"]["status"], "ok", "{health}");

    // A run started before any worker declared its command contract.
    start(&app, "metrics-run").await;
    let waiting = metrics(&app).await;
    assert_eq!(
        [
            &waiting["runs"]["total"],
            &waiting["runs"]["waiting"],
            &waiting["tasks"]["open"],
            &waiting["tasks"]["ready"],
            &waiting["starts"]["pending_runs"],
            &waiting["starts"]["pending_commands"],
            &waiting["history"]["events"],
            &waiting["command_contracts"]["actionable_backfill_needed_runs"],
            &waiting["backlog"]["tasks_added_last_minute"]
        ],
        [
            &json!(1),
            &json!(1),
            &json!(1),
            &json!(1),
            &json!(1),
            &json!(1),
            &json!(2),
            &json!(1),
            &json!(1)
        ],
        "{waiting}"
    );
    assert_eq!(waiting["tasks"]["dispatch_overdue"], 0);
    assert!(waiting["runs"]["oldest_wait_started_at"].is_string());

    // Past the redispatch window, the unclaimed task is overdue.
    database.execute("UPDATE workflow_tasks SET available_at='2000-01-01 00:00:00',created_at='2000-01-01 00:00:00',last_dispatched_at='2000-01-01 00:00:00',last_dispatch_attempt_at='2000-01-01 00:00:00'").await;
    let overdue = metrics(&app).await;
    assert_eq!(
        [
            &overdue["tasks"]["ready_due"],
            &overdue["tasks"]["dispatch_overdue"],
            &overdue["tasks"]["unhealthy"],
            &overdue["backlog"]["unhealthy_tasks"],
            &overdue["starts"]["ready_tasks"]
        ],
        [&json!(1), &json!(1), &json!(1), &json!(1), &json!(1)],
        "{overdue}"
    );
    assert!(
        overdue["tasks"]["max_dispatch_overdue_age_ms"]
            .as_i64()
            .unwrap()
            > 1_000
    );
    let (status, health) = request(&app, "GET", HEALTH, Value::Null).await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(health["health"]["status"], "warning");
    assert_eq!(
        warnings(&health),
        [
            "command_contract_snapshots",
            "task_transport",
            "routing_health"
        ]
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn operator_routes_are_admin_only_and_namespace_scoped() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let legacy = router(runtime.clone());
    namespaces::create(&legacy, "metrics-scope").await;
    start(&legacy, "metrics-default-run").await;
    let (status, scoped) =
        namespaces::scoped(&legacy, "metrics-scope", "GET", METRICS, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{scoped}");
    assert_eq!(
        [
            &scoped["namespace"],
            &scoped["operator_metrics"]["runs"]["total"]
        ],
        [&json!("metrics-scope"), &json!(0)]
    );
    let app = router(runtime.clone().with_role_tokens(
        Some("worker-token".into()),
        Some("operator-token".into()),
        Some("admin-token".into()),
    ));
    for path in [METRICS, HEALTH] {
        for (token, allowed) in [
            ("operator-token", false),
            ("worker-token", false),
            ("admin-token", true),
        ] {
            let (status, _, body) = admission::admission_http(
                &app,
                "GET",
                path,
                false,
                Some(token),
                Some("2"),
                Some("default"),
                Value::Null,
            )
            .await;
            assert_eq!(status == StatusCode::OK, allowed, "{path} {token} {body}");
        }
    }
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_named_namespace_deregisters_only_its_own_worker() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    namespaces::create(&app, "metrics-workers").await;
    for namespace in ["default", "metrics-workers"] {
        let registered = namespaces::scoped(
            &app,
            namespace,
            "POST",
            "/api/worker/register",
            json!({
            "worker_id":"shared-worker","task_queue":"test","runtime":"php",
            "supported_workflow_types":["echo"],"supported_activity_types":[]}),
        )
        .await;
        assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    }
    let removed = namespaces::scoped(
        &app,
        "metrics-workers",
        "DELETE",
        "/api/workers/shared-worker",
        Value::Null,
    )
    .await;
    assert_eq!(removed.0, StatusCode::OK, "{}", removed.1);
    let gone = namespaces::scoped(
        &app,
        "metrics-workers",
        "GET",
        "/api/workers/shared-worker",
        Value::Null,
    )
    .await;
    assert_eq!(gone.0, StatusCode::NOT_FOUND, "{}", gone.1);
    let kept = namespaces::scoped(
        &app,
        "default",
        "GET",
        "/api/workers/shared-worker",
        Value::Null,
    )
    .await;
    assert_eq!(kept.0, StatusCode::OK, "{}", kept.1);
    runtime.close().await;
    database.remove().await;
}

// Workflow 2.24.0 (#1154): an open run whose workflow task cannot replay is
// counted in operator metrics and warns in health, which stays 200.
#[tokio::test]
async fn a_replay_blocked_run_is_counted_and_warns() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let quiet = metrics(&app).await;
    assert_eq!((quiet["runs"]["replay_blocked"].clone(), quiet["runs"]["oldest_replay_blocked_at"].clone(),
        quiet["runs"]["max_replay_blocked_age_ms"].clone()), (json!(0), Value::Null, json!(0)));
    register(&app, "replay-worker", json!(["echo"]), json!([])).await;
    let started = start(&app, "replay-blocked-metrics").await;
    let task = poll(&app, "replay-worker", "workflow").await;
    let (status, body) = request(&app, "POST", &format!("/api/worker/workflow-tasks/{}/fail", task["task_id"].as_str().unwrap()),
        json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],
            "failure":{"type":"NonDeterminismError","message":"workflow code produced a different command at sequence 1"}})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let summary = run_observation_active_summaries::observe(&app, "default", &started).await;
    assert_eq!(summary["liveness_state"], "workflow_replay_blocked", "{summary}");

    let runs = metrics(&app).await["runs"].clone();
    assert_eq!(runs["replay_blocked"], 1, "{runs}");
    assert!(runs["oldest_replay_blocked_at"].is_string(), "{runs}");
    assert!(runs["max_replay_blocked_age_ms"].as_i64().is_some_and(|age| age >= 0), "{runs}");
    let (status, health) = request(&app, "GET", HEALTH, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{health}");
    assert!(warnings(&health).contains(&"replay_blocked_runs".to_owned()), "{health}");
    let check = health["health"]["checks"].as_array().unwrap().iter()
        .find(|check| check["name"] == "replay_blocked_runs").unwrap().clone();
    assert_eq!((check["category"].clone(), check["data"]["replay_blocked_runs"].clone()), (json!("correctness"), json!(1)), "{check}");
    assert_eq!(check["data"]["oldest_replay_blocked_at"], runs["oldest_replay_blocked_at"]);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn repeated_workflow_lease_loss_is_visible_until_the_task_completes() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let workflow = "repeated-lease-loss";
    register(&app, "lease-worker", json!(["echo"]), json!([])).await;
    let started = start(&app, workflow).await;
    let run_id = started["run_id"].as_str().unwrap();

    let mut task = poll(&app, "lease-worker", "workflow").await;
    let task_id = task["task_id"].as_str().unwrap().to_owned();
    for loss in 1..=3 {
        database.execute("UPDATE workflow_tasks SET lease_expires_at='2020-01-02 03:04:05.000000' WHERE task_type='workflow' AND status='leased'").await;
        if loss == 2 {
            task = poll(&app, "lease-worker", "workflow").await;
            assert_eq!(task["task_id"], task_id);
        } else {
            let (status, body) = request(&app, "POST", "/api/system/repair/pass", json!({"respect_throttle": false, "queue": "test"})).await;
            assert_eq!(status, StatusCode::OK, "{body}");
            assert_eq!(body["repaired_existing_tasks"], 1, "{body}");
            if loss < 3 {
                task = poll(&app, "lease-worker", "workflow").await;
                assert_eq!(task["task_id"], task_id);
            }
        }
    }

    let path = format!("/api/workflows/{workflow}/runs/{run_id}");
    let (status, described) = request(&app, "GET", &path, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{described}");
    let attention = &described["task_attention"];
    assert_eq!(attention["reason"], "workflow_task_lease_lost", "{attention}");
    assert_eq!(attention["consecutive"], 3);
    assert_eq!(attention["threshold"], 3);
    assert_eq!(attention["workflow_task_id"], task_id);
    assert!(chrono::DateTime::parse_from_rfc3339(attention["last_lease_lost_at"].as_str().unwrap()).is_ok());

    let (status, listed) = request(&app, "GET", "/api/workflows?status=running", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{listed}");
    assert_eq!(listed["workflows"][0]["task_attention"], *attention);
    let (status, runs) = request(&app, "GET", &format!("/api/workflows/{workflow}/runs"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{runs}");
    assert_eq!(runs["runs"][0]["task_attention"], *attention);
    let (status, observed) = request(&app, "GET", &format!("{path}/observation"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{observed}");
    assert_eq!(observed["task_attention"], *attention);

    let active = metrics(&app).await;
    assert_eq!(active["runs"]["task_attention"], 1);
    assert_eq!(active["tasks"]["lease_lost_repeatedly"], 1);
    assert_eq!(active["tasks"]["max_lost_lease_count"], 3);

    let (status, unaffected) = request(&app, "POST", "/api/workflows", json!({
        "workflow_id": "no-lease-loss", "workflow_type": "echo", "task_queue": "unpolled",
        "input": envelope(Payload::Array(vec![])),
    })).await;
    assert_eq!(status, StatusCode::CREATED, "{unaffected}");
    for (selection, expected) in [("true", workflow), ("false", "no-lease-loss")] {
        let uri = format!("/api/workflows?status=running&page_size=1&task_attention={selection}");
        let (status, selected) = request(&app, "GET", &uri, Value::Null).await;
        assert_eq!(status, StatusCode::OK, "{selected}");
        assert_eq!(selected["workflows"][0]["workflow_id"], expected);
        assert_eq!(selected["task_attention_filter"], selection == "true");
        assert!(selected["next_page_token"].is_null());
    }
    let (status, filtered) = request(&app, "GET", "/api/workflows?task_attention=true&query=WorkflowId%20%3D%20%22no-lease-loss%22", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{filtered}");
    assert_eq!(filtered["workflows"], json!([]));
    let (status, rejected) = request(&app, "GET", "/api/workflows?task_attention=invalid", Value::Null).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{rejected}");
    assert!(rejected["errors"]["task_attention"].is_array());

    let recovered = poll(&app, "lease-worker", "workflow").await;
    assert_eq!(recovered["task_id"], task_id);
    let (status, completed) = finish_task(&app, &recovered, json!([{
        "type": "complete_workflow",
        "result": envelope(Payload::Long(1)),
    }])).await;
    assert_eq!(status, StatusCode::OK, "{completed}");
    let (status, closed) = request(&app, "GET", &path, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{closed}");
    assert!(closed["task_attention"].is_null());
    let inactive = metrics(&app).await;
    assert_eq!(inactive["runs"]["task_attention"], 0);
    assert_eq!(inactive["tasks"]["lease_lost_repeatedly"], 0);
    assert_eq!(inactive["tasks"]["max_lost_lease_count"], 0);

    runtime.close().await;
    database.remove().await;
}
