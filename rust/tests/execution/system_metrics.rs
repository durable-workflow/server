use super::*;

const METRICS: &str = "/api/system/metrics";

async fn system_metrics(app: &Router, method: &str) -> Value {
    let (status, body) = request(app, method, METRICS, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["namespace"], "default");
    let families: Vec<&String> = body["metrics"].as_object().unwrap().keys().collect();
    assert_eq!(families.len(), 5, "{body}");
    for (name, family) in body["metrics"].as_object().unwrap() {
        assert_eq!(
            body["cardinality"]["metric_label_sets"][name], family["label_cardinality_policy"],
            "{name}"
        );
    }
    body["metrics"].clone()
}

async fn start_type(app: &Router, workflow: &str, workflow_type: &str) {
    let (status, body) = request(app, "POST", "/api/workflows", json!({"workflow_id": workflow,
        "workflow_type": workflow_type, "task_queue": "test",
        "input": envelope(Payload::Array(vec![]))})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

#[tokio::test]
async fn system_metrics_count_a_failed_workflow_task_and_the_namespace_state_like_published_php() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let empty = system_metrics(&app, "GET").await;
    assert_eq!(
        empty["dw_workflow_task_consecutive_failures"],
        json!({"max_consecutive_failures": 0, "failed_task_count": 0, "workflow_type_count": 0,
            "workflow_type_limit": 20, "workflow_types_truncated": false, "suppressed_workflow_type_count": 0,
            "suppressed_failed_task_count": 0, "label_cardinality_policy": {"namespace": "request_scope_not_label",
                "workflow_type": {"cardinality_class": "bounded_series", "limit": 20,
                    "selection": "top_by_max_consecutive_failures_then_name"}},
            "by_workflow_type": []})
    );
    assert_eq!(empty["dw_projection_drift_total"]["total"], 0);
    let usage = &empty["dw_namespace_durable_state_usage"];
    assert_eq!(usage["usage"].as_object().unwrap().len(), 16);
    assert!(usage["usage"].as_object().unwrap().values().all(|count| count == 0), "{usage}");
    assert_eq!(usage["rejections_by_reason"].as_object().unwrap().len(), 17);
    assert_eq!(usage["rejections_by_reason"]["namespace_durable_state_quota_unavailable"], 0);
    assert!(usage["limits"].as_object().unwrap().values().all(Value::is_null));
    assert_eq!(empty["dw_runtime_external_payload_namespace_usage"]["used_objects"], 0);
    assert_eq!(empty["dw_namespace_request_admission_rejections"]["max_requests_per_minute"], Value::Null);

    register(&app, "metrics-worker", json!(["echo", "other"]), json!([])).await;
    start_type(&app, "metrics-a", "echo").await;
    start_type(&app, "metrics-b", "other").await;
    let task = poll(&app, "metrics-worker", "workflow").await;
    assert_eq!(task["workflow_type"], "echo", "{task}");
    let (status, body) = request(&app, "POST",
        &format!("/api/worker/workflow-tasks/{}/fail", task["task_id"].as_str().unwrap()),
        json!({"lease_owner": task["lease_owner"], "workflow_task_attempt": task["workflow_task_attempt"],
            "failure": {"message": "metrics failure", "type": "RuntimeException"}})).await;
    assert_eq!(status, StatusCode::OK, "{body}");

    let read = system_metrics(&app, "GET").await;
    assert_eq!(read, system_metrics(&app, "POST").await);
    let failures = &read["dw_workflow_task_consecutive_failures"];
    assert_eq!(
        [&failures["max_consecutive_failures"], &failures["failed_task_count"], &failures["workflow_type_count"]],
        [&json!(1), &json!(1), &json!(1)]
    );
    assert_eq!(
        failures["by_workflow_type"],
        json!([{"workflow_type": "echo", "max_consecutive_failures": 1, "failed_task_count": 1}])
    );
    let usage = &read["dw_namespace_durable_state_usage"]["usage"];
    for (resource, count) in [
        ("workflow_instances", 2),
        ("workflow_runs", 2),
        ("open_workflow_runs", 2),
        ("worker_registrations", 1),
        ("workflow_tasks", 3),
        ("pending_workflow_tasks", 2),
        ("workflow_commands", 2),
        ("schedules", 0),
        ("workflow_timers", 0),
        ("workflow_streams", 0),
    ] {
        assert_eq!(usage[resource], count, "{resource}: {usage}");
    }
}

#[tokio::test]
async fn system_metrics_bound_the_failure_series_and_report_server_wide_summary_drift() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    for index in 0..21 {
        start_type(&app, &format!("bounded-{index:02}"), &format!("type-{index:02}")).await;
    }
    database.execute("UPDATE workflow_tasks SET status='failed', attempt_count=1 WHERE task_type='workflow'").await;
    database.execute("UPDATE workflow_tasks SET attempt_count=3 WHERE workflow_run_id IN (SELECT id FROM workflow_runs WHERE workflow_type='type-20')").await;
    database.execute("DELETE FROM workflow_run_summaries WHERE id IN (SELECT id FROM workflow_runs WHERE workflow_type='type-05')").await;

    let metrics = system_metrics(&app, "GET").await;
    let failures = &metrics["dw_workflow_task_consecutive_failures"];
    assert_eq!(
        [
            &failures["max_consecutive_failures"],
            &failures["failed_task_count"],
            &failures["workflow_type_count"],
            &failures["workflow_types_truncated"],
            &failures["suppressed_workflow_type_count"],
            &failures["suppressed_failed_task_count"],
        ],
        [&json!(3), &json!(21), &json!(21), &json!(true), &json!(1), &json!(1)]
    );
    let shown: Vec<&str> = failures["by_workflow_type"]
        .as_array()
        .unwrap()
        .iter()
        .map(|row| row["workflow_type"].as_str().unwrap())
        .collect();
    let mut expected = vec!["type-20".to_owned()];
    expected.extend((0..19).map(|index| format!("type-{index:02}")));
    assert_eq!(shown, expected);
    assert_eq!(failures["by_workflow_type"][0]["max_consecutive_failures"], 3);

    let drift = &metrics["dw_projection_drift_total"];
    assert_eq!(
        [&drift["total"], &drift["tables_with_drift"], &drift["table_count"], &drift["scope"]],
        [&json!(1), &json!(1), &json!(5), &json!("server")]
    );
    assert_eq!(
        drift["by_table"],
        json!([{"table": "run_summaries", "needs_rebuild": 1}, {"table": "run_waits", "needs_rebuild": 0},
            {"table": "run_timeline_entries", "needs_rebuild": 0}, {"table": "run_timer_entries", "needs_rebuild": 0},
            {"table": "run_lineage_entries", "needs_rebuild": 0}])
    );
}
