use super::*;

const STATUS: &str = "/api/system/activity-timeouts";
const PASS: &str = "/api/system/activity-timeouts/pass";

/// Starts a run that schedules one activity with a minute to start, and
/// returns the run and the activity's execution id.
async fn scheduled(app: &Router, workflow: &str) -> (Value, String) {
    let started = start(app, workflow).await;
    let task = poll(app, "worker", "workflow").await;
    let command = json!({"type":"schedule_activity","activity_type":"act","arguments":envelope(Payload::Array(vec![])),
        "schedule_to_start_timeout":60,"retry_policy":{"max_attempts":1}});
    assert_eq!(finish_task(app, &task, json!([command])).await.0, StatusCode::OK);
    let history = run_history(app, &started["workflow_id"], &started["run_id"]).await;
    let scheduled = history.as_array().unwrap().iter().find(|event| event["event_type"] == "ActivityScheduled");
    let scheduled = scheduled.unwrap_or_else(|| panic!("{history}"));
    let id = scheduled["payload"]["activity"]["id"].as_str().unwrap().to_owned();
    (started, id)
}

async fn timed_out(app: &Router, started: &Value) -> bool {
    let history = run_history(app, &started["workflow_id"], &started["run_id"]).await;
    history.as_array().unwrap().iter().any(|event| event["event_type"] == "ActivityTimedOut")
}

// Published PHP enforces the named or expired executions one by one and
// reports why it left the others.
#[tokio::test]
async fn activity_timeout_pass_reports_each_execution_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!(["act"])).await;
    let (first, waiting) = scheduled(&app, "timeouts-first").await;

    let (status, invalid) = request(&app, "POST", PASS, json!({"execution_ids":[1]})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{invalid}");
    assert_eq!(invalid["errors"]["execution_ids.0"], json!(["The execution_ids.0 field must be a string."]));
    let (status, empty) = request(&app, "POST", PASS, json!([])).await;
    assert_eq!(status, StatusCode::OK, "{empty}");
    assert_eq!(empty, json!({"processed":0,"enforced":0,"skipped":0,"failed":0,"results":[]}));
    // Like PHP, an empty body is empty input and malformed JSON is refused.
    for (raw, expected) in [("", StatusCode::OK), ("{", StatusCode::BAD_REQUEST)] {
        let request = Request::builder().method("POST").uri(PASS).header("authorization", "Bearer test-token")
            .header("content-type", "application/json").header("x-durable-workflow-control-plane-version", "2")
            .body(Body::from(raw)).unwrap();
        let response = app.clone().oneshot(request).await.unwrap();
        assert_eq!(response.status(), expected, "{raw:?}");
        let body: Value = serde_json::from_slice(&to_bytes(response.into_body(), 65536).await.unwrap()).unwrap();
        if expected == StatusCode::BAD_REQUEST {
            assert_eq!(body["reason"], "malformed_json", "{body}");
        }
    }

    let (status, skipped) = request(&app, "POST", PASS, json!({"execution_ids":[" missing ", waiting]})).await;
    assert_eq!(status, StatusCode::OK, "{skipped}");
    assert_eq!((skipped["processed"].clone(), skipped["skipped"].clone()), (json!(2), json!(2)));
    assert_eq!(skipped["results"][0], json!({"execution_id":"missing","outcome":"skipped","reason":"execution_not_found"}));
    assert_eq!(skipped["results"][1]["reason"], "no_deadline_expired");

    // Native's own deadline loop times the activity out once its deadline passes.
    database.execute("UPDATE activity_executions SET schedule_deadline_at='2020-01-02 03:04:05.123456'").await;
    let deadline = tokio::time::Instant::now() + std::time::Duration::from_secs(3);
    while !timed_out(&app, &first).await {
        assert!(tokio::time::Instant::now() < deadline, "the deadline loop did not time the activity out");
        tokio::time::sleep(std::time::Duration::from_millis(25)).await;
    }
    let (_, terminal) = request(&app, "POST", PASS, json!({"execution_ids":[waiting]})).await;
    assert_eq!(terminal["results"][0]["reason"], "execution_already_terminal", "{terminal}");
    let resumed = poll(&app, "worker", "workflow").await;
    let complete = json!({"type":"complete_workflow","result":envelope(Payload::Long(1))});
    assert_eq!(finish_task(&app, &resumed, json!([complete])).await.0, StatusCode::OK);

    // PHP's scan lists an expired execution whatever its run's state, and the
    // pass leaves one whose run already closed.
    let (second, stranded) = scheduled(&app, "timeouts-second").await;
    let run = second["run_id"].as_str().unwrap();
    database.execute(sqlx::AssertSqlSafe(format!("UPDATE workflow_runs SET status='completed' WHERE id='{run}'"))).await;
    database.execute(sqlx::AssertSqlSafe(format!(
        "UPDATE activity_executions SET schedule_deadline_at='2020-01-02 03:04:05.123456' WHERE id='{stranded}'"
    ))).await;
    let (status, listed) = request(&app, "GET", STATUS, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{listed}");
    assert_eq!(listed["expired_execution_ids"], json!([stranded]), "{listed}");
    let (status, scanned) = request(&app, "POST", PASS, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{scanned}");
    assert_eq!(scanned["results"], json!([{"execution_id":stranded,"outcome":"skipped","reason":"run_already_terminal"}]));
}
