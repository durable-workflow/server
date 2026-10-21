use super::*;

fn events_of<'a>(history: &'a Value, kind: &str) -> Vec<&'a Value> {
    history.as_array().unwrap().iter().filter(|event| event["event_type"] == kind).collect()
}

// Published PHP applies an explicit deadline when a workflow task completes
// after it: the run fails as timed out, its open activities, tasks and
// timers are cancelled, and the worker's completion is not recorded. Work
// reported before that point is still accepted.
#[tokio::test]
async fn workflow_task_after_the_run_deadline_times_out_the_run() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!(["activity-echo"])).await;
    let (status, started) = request(&app, "POST", "/api/workflows", json!({"workflow_id":"deadline","workflow_type":"echo",
        "task_queue":"test","input":envelope(Payload::Array(vec![])),"run_timeout_seconds":600})).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    let first = poll(&app, "worker", "workflow").await;
    let activity = json!({"type":"schedule_activity","activity_type":"activity-echo","arguments":envelope(Payload::Array(vec![]))});
    let (status, body) = finish_task(&app, &first, json!([activity, activity, {"type":"start_timer","delay_seconds":3600}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    // The deadline passes while the run waits on its activities.
    database.execute("UPDATE workflow_runs SET run_deadline_at='2000-01-01 00:00:00'").await;
    let claimed = poll(&app, "worker", "activity").await;
    let (status, body) = request(&app, "POST",
        &format!("/api/worker/activity-tasks/{}/complete", claimed["task_id"].as_str().unwrap()),
        json!({"lease_owner":"worker","activity_attempt_id":claimed["activity_attempt_id"],"result":envelope(Payload::Long(1))})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["recorded"], true, "{body}");
    let resumed = poll(&app, "worker", "workflow").await;
    assert!(!resumed.is_null(), "a past deadline must not block the resume claim");
    let (status, body) = finish_task(&app, &resumed, json!([{"type":"complete_workflow","result":envelope(Payload::Long(2))}])).await;
    assert_eq!(status, StatusCode::CONFLICT, "{body}");
    assert_eq!(body["reason"], "run_timed_out");
    assert_eq!(body["recorded"], false);
    assert_eq!(body["outcome"], "completed");
    assert_eq!(body["run_status"], "failed");
    assert_eq!(body["created_task_ids"], json!([]));
    let (status, described) = request(&app, "GET", "/api/workflows/deadline", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{described}");
    assert_eq!(described["status"], "failed");
    assert_eq!(described["closed_reason"], "timed_out");
    let history = run_history(&app, &json!("deadline"), &started["run_id"]).await;
    let timed_out = events_of(&history, "WorkflowTimedOut");
    assert_eq!(timed_out.len(), 1, "{history}");
    let payload = &timed_out[0]["payload"];
    assert_eq!(payload["timeout_kind"], "run_timeout");
    assert_eq!(payload["failure_category"], "timeout");
    assert_eq!(payload["message"], "Workflow run deadline expired at 2000-01-01T00:00:00+00:00.");
    assert_eq!(payload["exception_class"], "Workflow\\V2\\Exceptions\\WorkflowTimeoutException");
    assert_eq!(payload["run_deadline_at"], "2000-01-01T00:00:00+00:00");
    assert!(payload["execution_deadline_at"].is_null());
    assert!(payload["failure_id"].is_string());
    let cancelled = events_of(&history, "ActivityCancelled");
    assert_eq!(cancelled.len(), 1, "{history}");
    assert!(cancelled[0]["payload"]["workflow_command_id"].is_null());
    assert_eq!(events_of(&history, "TimerCancelled").len(), 1, "{history}");
    assert!(events_of(&history, "WorkflowCompleted").is_empty());
    assert!(poll(&app, "worker", "activity").await.is_null(), "the open activity's task is cancelled");
    assert!(poll(&app, "worker", "workflow").await.is_null());
}

// Without an expired deadline the same completion is recorded as usual.
#[tokio::test]
async fn workflow_task_before_the_deadline_completes_normally() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!([])).await;
    let (status, started) = request(&app, "POST", "/api/workflows", json!({"workflow_id":"in-time","workflow_type":"echo",
        "task_queue":"test","input":envelope(Payload::Array(vec![])),"execution_timeout_seconds":600})).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    let task = poll(&app, "worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Long(2))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &json!("in-time"), &started["run_id"]).await;
    assert!(events_of(&history, "WorkflowTimedOut").is_empty());
    assert_eq!(events_of(&history, "WorkflowCompleted").len(), 1);
}
