use super::*;

async fn dashboard(app: &Router, path: &str) -> Value {
    let (status, body) = request(app, "GET", path, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["namespace"], "default");
    body["dashboard"].clone()
}

// Published PHP's operator dashboard summarizes the namespace's runs from
// their summaries; the bounded variant skips the history audits.
#[tokio::test]
async fn operator_dashboard_summarizes_runs_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!([])).await;
    for workflow in ["done", "broken", "open"] {
        start(&app, workflow).await;
    }
    let done = poll(&app, "worker", "workflow").await;
    let (status, body) = finish_task(&app, &done, json!([{"type":"complete_workflow","result":envelope(Payload::Long(1))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let broken = poll(&app, "worker", "workflow").await;
    let (status, body) = finish_task(&app, &broken, json!([{"type":"fail_workflow","message":"boom","exception_class":"RuntimeException"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let bounded = dashboard(&app, "/api/system/operator-dashboard/bounded").await;
    assert_eq!(bounded["flows"], 3);
    assert_eq!(bounded["flows_past_hour"], 3);
    assert_eq!(bounded["flows_per_minute"], json!(0.05));
    assert_eq!(bounded["failed_flows_past_week"], 1);
    assert_eq!(bounded["fleet_overview"]["current"], json!({"running":1,"failed":1}));
    assert_eq!(bounded["fleet_overview"]["trends"]["hour"], json!({"completed":1,"failed":1}));
    let health = &bounded["workflow_type_health"][0];
    assert_eq!(health["workflow_type"], "echo");
    assert_eq!(health["total_runs"], 3);
    assert_eq!(health["pass_rate"], json!(50.0));
    assert_eq!(health["error_count"], 1);
    assert_eq!(health["status_breakdown"], json!({"completed":1,"failed":1,"cancelled":0,"terminated":0}));
    assert_eq!(bounded["needs_attention"], json!({"alerts":[],"total_alerts":0,"has_critical":false}));
    let series = &bounded["fleet_trends_series"];
    assert_eq!(series["timestamps"].as_array().unwrap().len(), 169);
    assert_eq!(series["completed"].as_array().unwrap().iter().filter_map(Value::as_i64).sum::<i64>(), 1);
    assert_eq!(series["failed"].as_array().unwrap().iter().filter_map(Value::as_i64).sum::<i64>(), 1);
    assert_eq!(bounded["workflow_scope"], json!({"namespace":"default","workflow_types":null,"all_workflow_types":true}));
    assert!(bounded["time_windows"]["recent_hour_from"].as_str().unwrap().ends_with("+00:00"));
    // The longest-running open wait is the run still waiting on its task.
    let waiting = &bounded["max_wait_time_workflow"];
    assert_eq!(waiting["instance_id"], "open");
    assert_eq!(waiting["is_terminal"], false);
    assert_eq!(waiting["exceptions_count"], 0);
    assert!(waiting["is_current_run"].is_boolean());
    let metrics = &bounded["operator_metrics"];
    assert_eq!(metrics["history_audit_evaluation"], "not_requested");
    assert!(metrics["command_contracts"].as_object().unwrap().values().all(Value::is_null));
    assert_eq!(metrics["capacity_evidence"]["available"], false);
    let full = dashboard(&app, "/api/system/operator-dashboard").await;
    assert_eq!(full["flows"], 3);
    assert!(full["operator_metrics"].get("history_audit_evaluation").is_none());
}

// Totals filtered by workflow type need an observer native does not have, so
// native answers as PHP does when its observer cannot filter.
#[tokio::test]
async fn workflow_type_dashboard_validates_then_reports_the_missing_capability() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let path = "/api/system/operator-dashboard/bounded/workflow-types";
    let (status, body) = request(&app, "GET", path, Value::Null).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    assert_eq!(body["errors"]["workflow_types"], json!(["The workflow types field is required."]));
    let (status, body) = request(&app, "GET", &format!("{path}?workflow_types=%5B%22echo%22%5D"), Value::Null).await;
    assert_eq!(status, StatusCode::NOT_IMPLEMENTED, "{body}");
    assert_eq!(body["reason"], "backend_capability_unavailable");
    assert_eq!(body["capability"], "workflow_type_dashboard");
}
