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
    assert_eq!(health["health"]["checks"].as_array().unwrap().len(), 12);
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
