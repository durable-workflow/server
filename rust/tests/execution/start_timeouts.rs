use super::*;

async fn described(app: &Router, workflow: &str) -> Value {
    let (status, body) = request(app, "GET", &format!("/api/workflows/{workflow}"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body
}

fn instant(value: &Value) -> chrono::DateTime<chrono::FixedOffset> {
    chrono::DateTime::parse_from_rfc3339(value.as_str().unwrap()).unwrap()
}

// Published PHP stores no timeout and no deadline when a start names none,
// so a long-running workflow is never cut off by a server default.
#[tokio::test]
async fn starts_without_timeouts_have_no_deadlines() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    start(&app, "no-timeouts").await;
    let (status, body) = request(&app, "POST", "/api/workflows", json!({"workflow_id":"null-timeouts","workflow_type":"echo",
        "task_queue":"test","input":envelope(Payload::Array(vec![])),"execution_timeout_seconds":null,"run_timeout_seconds":null})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    for workflow in ["no-timeouts", "null-timeouts"] {
        let run = described(&app, workflow).await;
        for field in ["execution_timeout_seconds", "run_timeout_seconds", "execution_deadline_at", "run_deadline_at"] {
            assert!(run[field].is_null(), "{workflow} {field}: {run}");
        }
        let history = run_history(&app, &json!(workflow), &run["run_id"]).await;
        let started = history.as_array().unwrap().iter().find(|event| event["event_type"] == "WorkflowStarted").unwrap();
        for field in ["execution_timeout_seconds", "run_timeout_seconds", "execution_deadline_at", "run_deadline_at"] {
            assert!(started["payload"][field].is_null(), "{workflow} {field}: {started}");
        }
    }
    // With no stored deadline, the run cannot time out and still accepts work.
    register(&app, "timeless", json!(["echo"]), json!([])).await;
    let task = poll(&app, "timeless", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Long(1))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
}

// Each deadline follows its own timeout from the start, as in PHP; the run
// deadline is not clipped to the execution deadline.
#[tokio::test]
async fn explicit_timeouts_set_independent_deadlines() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let (status, body) = request(&app, "POST", "/api/workflows", json!({"workflow_id":"timed","workflow_type":"echo",
        "task_queue":"test","input":envelope(Payload::Array(vec![])),"execution_timeout_seconds":10,"run_timeout_seconds":30})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    let run = described(&app, "timed").await;
    assert_eq!(run["execution_timeout_seconds"], 10);
    assert_eq!(run["run_timeout_seconds"], 30);
    let started = instant(&run["started_at"]);
    assert_eq!((instant(&run["execution_deadline_at"]) - started).num_seconds(), 10);
    assert_eq!((instant(&run["run_deadline_at"]) - started).num_seconds(), 30);
    for invalid in [json!(0), json!(-1), json!("ten")] {
        let (status, body) = request(&app, "POST", "/api/workflows", json!({"workflow_id":"invalid","workflow_type":"echo",
            "task_queue":"test","input":envelope(Payload::Array(vec![])),"run_timeout_seconds":invalid})).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    }
}
