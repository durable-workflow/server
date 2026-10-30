use super::*;

// Published Workflow retries a failed child as a new run of the same child
// instance while its retry policy has attempts left
// (`startChildRetryIfAvailable`). The parent keeps waiting on the same call
// and is resumed once a run of the child resolves it. The child-retries
// fixture records the same cases against published Server 2.11.7.

fn child(options: Value) -> Value {
    let mut command = json!({"type":"start_child_workflow","workflow_type":"child","arguments":envelope(Payload::Array(vec![]))});
    for (field, value) in options.as_object().unwrap() {
        command[field] = value.clone();
    }
    command
}

fn fail(non_retryable: bool) -> Value {
    json!([{"type":"fail_workflow","message":"attempt failed","exception_type":"Boom","exception_class":"Boom","non_retryable":non_retryable}])
}

fn child_events(events: &Value) -> Vec<Value> {
    events.as_array().unwrap().iter().filter(|event| event["event_type"].as_str().unwrap().starts_with("Child")).cloned().collect()
}

async fn parent_with_child(app: &Router, id: &str, options: Value) -> Value {
    register(app, "worker", json!(["echo", "child"]), json!([])).await;
    let parent = start(app, id).await;
    let task = poll(app, "worker", "workflow").await;
    assert_eq!(task["workflow_type"], "echo", "{task}");
    let (status, body) = finish_task(app, &task, json!([child(options)])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    parent
}

async fn child_task(app: &Router) -> Value {
    let task = poll(app, "worker", "workflow").await;
    assert_eq!(task["workflow_type"], "child", "{task}");
    task
}

#[tokio::test]
async fn a_failed_child_is_retried_as_a_new_run_of_the_same_call() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let parent = parent_with_child(&app, "retried", json!({"retry_policy":{"max_attempts":3,"backoff_seconds":[0]},
        "execution_timeout_seconds":600,"run_timeout_seconds":300})).await;
    let first = child_task(&app).await;
    let (status, body) = finish_task(&app, &first, fail(false)).await;
    assert_eq!((status, &body["run_status"]), (StatusCode::OK, &json!("failed")), "{body}");

    let (_, described) = request(&app, "GET", "/api/workflows/retried", Value::Null).await;
    assert_eq!((described["status"].clone(), described["wait_kind"].clone()), (json!("waiting"), json!("child")), "{described}");
    let events = child_events(&run_history(&app, &json!("retried"), &parent["run_id"]).await);
    let kinds: Vec<_> = events.iter().map(|event| event["event_type"].clone()).collect();
    assert_eq!(kinds, [json!("ChildWorkflowScheduled"), json!("ChildRunStarted"), json!("ChildRunStarted")]);
    let (scheduled, retry) = (&events[0]["payload"], &events[2]["payload"]);
    assert_eq!(scheduled["retry_policy"], json!({"snapshot_version":1,"max_attempts":3,"backoff_seconds":[0],"non_retryable_error_types":[]}));
    assert_eq!(scheduled["timeout_policy"], json!({"snapshot_version":1,"execution_timeout_seconds":600,"run_timeout_seconds":300}));
    assert_eq!((retry["child_run_number"].clone(), retry["retry_attempt"].clone(), retry["retry_backoff_seconds"].clone()),
        (json!(2), json!(2), json!(0)), "{retry}");
    assert_eq!(retry["child_call_id"], scheduled["child_call_id"]);
    assert_ne!(retry["workflow_link_id"], scheduled["workflow_link_id"]);
    assert_eq!(retry["retry_of_child_workflow_run_id"], first["run_id"]);
    assert_eq!(retry["execution_deadline_at"], events[1]["payload"]["execution_deadline_at"], "the execution deadline spans attempts");
    assert!(retry.get("cancellation_policy").is_none(), "{retry}");

    let second = child_task(&app).await;
    assert_eq!(second["run_id"], retry["child_workflow_run_id"]);
    let (status, body) = finish_task(&app, &second, json!([{"type":"complete_workflow","result":envelope(Payload::Array(vec![]))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let resumed = poll(&app, "worker", "workflow").await;
    assert_eq!(resumed["workflow_type"], "echo", "{resumed}");
    let events = child_events(&run_history(&app, &json!("retried"), &parent["run_id"]).await);
    let completed = &events.last().unwrap()["payload"];
    assert_eq!(events.last().unwrap()["event_type"], "ChildRunCompleted");
    assert_eq!((completed["child_run_number"].clone(), completed["workflow_link_id"].clone()), (json!(2), retry["workflow_link_id"].clone()));
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_child_fails_its_parent_when_attempts_run_out_or_the_failure_is_not_retryable() {
    for (id, options, attempts, non_retryable) in [
        ("exhausted", json!({"retry_policy":{"max_attempts":2}}), 2, false),
        ("flagged", json!({"retry_policy":{"max_attempts":3}}), 1, true),
        ("named", json!({"retry_policy":{"max_attempts":3,"non_retryable_error_types":["Boom"]}}), 1, false),
        ("empty", json!({"retry_policy":{}}), 1, false),
        ("unknown-keys", json!({"retry_policy":{"maximum_attempts":2}}), 1, false),
    ] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        let parent = parent_with_child(&app, id, options).await;
        for _ in 0..attempts {
            let task = child_task(&app).await;
            let (status, body) = finish_task(&app, &task, fail(non_retryable)).await;
            assert_eq!(status, StatusCode::OK, "{id}: {body}");
        }
        let resumed = poll(&app, "worker", "workflow").await;
        assert_eq!(resumed["workflow_type"], "echo", "{id}: {resumed}");
        let events = child_events(&run_history(&app, &json!(id), &parent["run_id"]).await);
        let last = events.last().unwrap();
        assert_eq!(last["event_type"], "ChildRunFailed", "{id}");
        assert_eq!(last["payload"]["child_run_number"], attempts, "{id}");
        assert_eq!(events.iter().filter(|event| event["event_type"] == "ChildRunStarted").count(), attempts as usize, "{id}");
        runtime.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn a_backoff_delays_the_retried_run() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let parent = parent_with_child(&app, "delayed", json!({"retry_policy":{"max_attempts":2,"backoff_seconds":[3600]}})).await;
    let first = child_task(&app).await;
    let (status, body) = finish_task(&app, &first, fail(false)).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert!(poll(&app, "worker", "workflow").await.is_null(), "the retry waits out its backoff");
    let events = child_events(&run_history(&app, &json!("delayed"), &parent["run_id"]).await);
    let instance = events[0]["payload"]["child_workflow_instance_id"].as_str().unwrap().to_owned();
    let (_, runs) = request(&app, "GET", &format!("/api/workflows/{instance}/runs"), Value::Null).await;
    let statuses: Vec<_> = runs["runs"].as_array().unwrap().iter().map(|run| (run["run_number"].clone(), run["status"].clone())).collect();
    assert!(statuses.contains(&(json!(2), json!("pending"))), "{runs}");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_timed_out_child_is_retried_and_its_last_timeout_fails_the_parent() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let parent = parent_with_child(&app, "timed-out", json!({"retry_policy":{"max_attempts":2},"run_timeout_seconds":1})).await;
    for _ in 0..2 {
        let task = child_task(&app).await;
        tokio::time::sleep(std::time::Duration::from_millis(1200)).await;
        let (status, body) = finish_task(&app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Array(vec![]))}])).await;
        assert_eq!((status, &body["reason"]), (StatusCode::CONFLICT, &json!("run_timed_out")), "{body}");
    }
    let resumed = poll(&app, "worker", "workflow").await;
    assert_eq!(resumed["workflow_type"], "echo", "{resumed}");
    let events = child_events(&run_history(&app, &json!("timed-out"), &parent["run_id"]).await);
    let failed = &events.last().unwrap()["payload"];
    assert_eq!(events.last().unwrap()["event_type"], "ChildRunFailed");
    assert_eq!((failed["child_run_number"].clone(), failed["child_status"].clone(), failed["closed_reason"].clone(), failed["failure_category"].clone()),
        (json!(2), json!("failed"), json!("timed_out"), json!("timeout")), "{failed}");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_child_deadline_or_backoff_too_far_away_is_refused_not_overflowed() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "worker", json!(["echo", "child"]), json!([])).await;
    start(&app, "extreme").await;
    let task = poll(&app, "worker", "workflow").await;
    for (options, reason) in [
        (json!({"execution_timeout_seconds": i64::MAX}), "invalid_child_timeout"),
        (json!({"run_timeout_seconds": i64::MAX}), "invalid_child_timeout"),
        (json!({"retry_policy": {"max_attempts": 2, "backoff_seconds": [1, i64::MAX]}}), "invalid_child_retry_policy"),
        (json!({"retry_policy": {"max_attempts": 2, "backoff_seconds": {"first": i64::MAX}}}), "invalid_child_retry_policy"),
    ] {
        let (status, body) = finish_task(&app, &task, json!([child(options.clone())])).await;
        assert_eq!((status, &body["reason"]), (StatusCode::UNPROCESSABLE_ENTITY, &json!(reason)), "{options}: {body}");
    }
    // The task is still open and the runtime still serves it.
    let (status, body) = finish_task(&app, &task, json!([child(json!({"run_timeout_seconds": 60}))])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_child_and_its_retry_inherit_the_parents_memos_and_search_attributes() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (status, body) = request(&app, "POST", "/api/search-attributes", json!({"name":"Region","type":"keyword"})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    register(&app, "worker", json!(["echo", "child"]), json!([])).await;
    let (status, parent) = request(&app, "POST", "/api/workflows", json!({"workflow_id":"inheriting","workflow_type":"echo",
        "task_queue":"test","input":envelope(Payload::Array(vec![])),"memo":{"team":"ops","count":3},
        "search_attributes":{"Region":"eu"}})).await;
    assert_eq!(status, StatusCode::CREATED, "{parent}");
    let task = poll(&app, "worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([child(json!({"retry_policy":{"max_attempts":2}}))])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let events = child_events(&run_history(&app, &json!("inheriting"), &parent["run_id"]).await);
    let instance = events[0]["payload"]["child_workflow_instance_id"].as_str().unwrap().to_owned();
    let expected = (json!({"count":3,"team":"ops"}), json!({"Region":"eu"}));
    let (_, described) = request(&app, "GET", &format!("/api/workflows/{instance}"), Value::Null).await;
    assert_eq!((described["memo"].clone(), described["search_attributes"].clone()), expected, "{described}");
    let first = child_task(&app).await;
    let (status, body) = finish_task(&app, &first, fail(false)).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (_, described) = request(&app, "GET", &format!("/api/workflows/{instance}"), Value::Null).await;
    assert_eq!(described["run_number"], 2, "{described}");
    assert_eq!((described["memo"].clone(), described["search_attributes"].clone()), expected, "{described}");
    let (_, listed) = request(&app, "GET", &format!("/api/workflows?query={}", "Region%20%3D%20%22eu%22"), Value::Null).await;
    let ids: Vec<_> = listed["workflows"].as_array().unwrap().iter().map(|row| row["workflow_id"].clone()).collect();
    assert!(ids.contains(&json!(instance)), "{listed}");
    runtime.close().await;
    database.remove().await;
}
