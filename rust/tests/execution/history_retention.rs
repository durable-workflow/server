use super::*;

const STATUS: &str = "/api/system/retention";
const PASS: &str = "/api/system/retention/pass";

async fn completed_run(app: &Router, workflow: &str) -> String {
    let started = start(app, workflow).await;
    let task = poll(app, "worker", "workflow").await;
    let complete = json!({"type":"complete_workflow","result":envelope(Payload::Long(1))});
    assert_eq!(finish_task(app, &task, json!([complete])).await.0, StatusCode::OK);
    started["run_id"].as_str().unwrap().to_owned()
}

fn messages(body: &Value, field: &str) -> Value {
    body["errors"][field].clone()
}

// Published PHP keeps closed runs for the namespace's retention days, then
// prunes their details: one run per heartbeat, or a batch on request.
#[tokio::test]
async fn retention_prunes_only_expired_closed_runs_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!([])).await;
    let expired = completed_run(&app, "expired").await;
    let kept = completed_run(&app, "kept").await;
    let open = start(&app, "open").await["run_id"].as_str().unwrap().to_owned();

    let (status, fresh) = request(&app, "GET", STATUS, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{fresh}");
    assert_eq!(
        [&fresh["namespace"], &fresh["retention_mode"], &fresh["retention_days"], &fresh["expired_run_count"]],
        [&json!("default"), &json!("bounded"), &json!(30), &json!(0)]
    );
    assert!(fresh["cutoff"].as_str().unwrap().ends_with("+00:00"), "{fresh}");
    assert_eq!((fresh["scan_limit"].clone(), fresh["scan_pressure"].clone()), (json!(100), json!(false)));

    // The expired run closed long before the 30-day cutoff.
    for table in ["workflow_runs", "workflow_run_summaries"] {
        database
            .execute(sqlx::AssertSqlSafe(format!(
                "UPDATE {table} SET closed_at='2020-01-01 00:00:00.000000' WHERE id='{expired}'"
            )))
            .await;
    }
    let (status, pressured) = request(&app, "GET", &format!("{STATUS}?limit=1"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{pressured}");
    assert_eq!(pressured["expired_run_ids"], json!([expired]));
    assert_eq!((pressured["scan_limit"].clone(), pressured["scan_pressure"].clone()), (json!(1), json!(true)));

    // Laravel's validation messages.
    let (status, invalid) = request(&app, "GET", &format!("{STATUS}?limit=0"), Value::Null).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{invalid}");
    assert_eq!(messages(&invalid, "limit"), json!(["The limit field must be at least 1."]));
    let (status, invalid) = request(&app, "POST", PASS, json!({"run_ids":[1, "x"]})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{invalid}");
    assert_eq!(messages(&invalid, "run_ids.0"), json!(["The run_ids.0 field must be a string."]));
    let (status, invalid) = request(&app, "POST", PASS, json!({"run_ids":"x"})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{invalid}");
    assert_eq!(messages(&invalid, "run_ids"), json!(["The run ids field must be an array."]));

    // Named runs that are missing or still open are skipped with a reason.
    let (status, skipped) = request(&app, "POST", PASS, json!({"run_ids":[" missing ", open]})).await;
    assert_eq!(status, StatusCode::OK, "{skipped}");
    assert_eq!((skipped["processed"].clone(), skipped["skipped"].clone(), skipped["pruned"].clone()), (json!(2), json!(2), json!(0)));
    assert_eq!(skipped["results"][0], json!({"run_id":"missing","outcome":"skipped","reason":"run_not_found"}));
    assert_eq!(skipped["results"][1]["reason"], "run_not_terminal");

    // Without run ids, the pass prunes the expired run in one transaction.
    let (status, pruned) = request(&app, "POST", PASS, json!([])).await;
    assert_eq!(status, StatusCode::OK, "{pruned}");
    assert_eq!((pruned["processed"].clone(), pruned["pruned"].clone(), pruned["failed"].clone()), (json!(1), json!(1), json!(0)));
    let result = &pruned["results"][0];
    assert_eq!((result["run_id"].clone(), result["outcome"].clone()), (json!(expired), json!("pruned")));
    assert!(result["history_events_deleted"].as_i64().unwrap() > 0, "{result}");
    assert_eq!(result["deleted"]["run_summary_deleted"], 1, "{result}");
    assert_eq!(result["deleted"]["run_deleted"], 0, "{result}");
    assert_eq!(result["deleted"]["message_stream_items_compacted"], 0, "{result}");
    assert_eq!(result["external_payloads_deleted"], 0, "{result}");

    let (_, after) = request(&app, "GET", STATUS, Value::Null).await;
    assert_eq!(after["expired_run_count"], 0, "{after}");
    let (status, again) = request(&app, "POST", PASS, json!({"run_ids":[expired]})).await;
    assert_eq!(status, StatusCode::OK, "{again}");
    assert_eq!(again["results"][0]["reason"], "run_not_found", "{again}");
    // The run that has not expired keeps its history.
    let (status, export) = request(&app, "GET", &format!("/api/workflows/kept/runs/{kept}/history/export"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{export}");
    assert!(!export["history_events"].as_array().unwrap().is_empty());

    // A heartbeat reports its namespace's inline pass, at most once a minute.
    let (status, heartbeat) = request(&app, "POST", "/api/worker/heartbeat", json!({"worker_id":"worker"})).await;
    assert_eq!(status, StatusCode::OK, "{heartbeat}");
    let retention = &heartbeat["retention"];
    assert_eq!(retention["failed"], 0, "{heartbeat}");
    for field in ["throttled", "processed", "pruned", "skipped"] {
        assert!(!retention[field].is_null(), "{field}: {heartbeat}");
    }
}

#[tokio::test]
async fn activity_timeout_status_reports_no_expired_executions_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let (status, body) = request(&app, "GET", "/api/system/activity-timeouts?limit=5", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body, json!({"expired_count":0,"expired_execution_ids":[],"scan_limit":5,"scan_pressure":false}));
    let (status, invalid) = request(&app, "GET", "/api/system/activity-timeouts?limit=abc", Value::Null).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{invalid}");
    assert_eq!(messages(&invalid, "limit"), json!(["The limit field must be an integer."]));
}
