use super::*;

const LONG_AGO: &str = "'2020-01-02 03:04:05.000000'";

/// Counts rows with a query built for this test.
async fn count(database: &TestDatabase, sql: String) -> i64 {
    task_retry::rows(database, Box::leak(sql.into_boxed_str())).await
}

/// Starts a run whose workflow task schedules `act` with `retry_policy`, and
/// claims the activity. Returns the start response and the claimed task.
async fn claimed(app: &Router, workflow: &str, retry_policy: Value) -> (Value, Value) {
    let started = start(app, workflow).await;
    let task = poll(app, "worker", "workflow").await;
    let mut command = json!({"type":"schedule_activity","activity_type":"act","arguments":envelope(Payload::Array(vec![]))});
    if !retry_policy.is_null() {
        command["retry_policy"] = retry_policy;
    }
    assert_eq!(finish_task(app, &task, json!([command])).await.0, StatusCode::OK);
    let activity = poll(app, "worker", "activity").await;
    assert!(activity["task_id"].is_string(), "{activity}");
    (started, activity)
}

/// The run's history once an event of `kind` is recorded. Native's scheduler
/// settles an expired lease within a few of its ticks.
async fn history_with(app: &Router, started: &Value, kind: &str) -> Vec<Value> {
    let deadline = tokio::time::Instant::now() + std::time::Duration::from_secs(5);
    loop {
        let history = run_history(app, &started["workflow_id"], &started["run_id"]).await;
        let events = history.as_array().unwrap().clone();
        if events.iter().any(|event| event["event_type"] == kind) {
            return events;
        }
        assert!(tokio::time::Instant::now() < deadline, "no {kind}: {history}");
        tokio::time::sleep(std::time::Duration::from_millis(25)).await;
    }
}

async fn expire(database: &TestDatabase, task: &Value) {
    let task_id = task["task_id"].as_str().unwrap();
    database
        .execute(sqlx::AssertSqlSafe(format!("UPDATE workflow_tasks SET lease_expires_at={LONG_AGO} WHERE id='{task_id}'")))
        .await;
}

// Published PHP never hands out an activity task again after its lease
// expires. The repair loop closes the attempt as expired, and the activity's
// retry policy schedules the next attempt with the lease as its reason.
#[tokio::test]
async fn an_expired_activity_lease_consumes_the_retry_policy_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!(["act"])).await;
    let (started, first) = claimed(&app, "lease-retry", json!({"max_attempts":2,"backoff_seconds":[0]})).await;
    let first_id = first["task_id"].as_str().unwrap();
    expire(&database, &first).await;
    // A poll racing the repair loop never re-claims the expired task.
    let racing = poll(&app, "worker", "activity").await;
    assert_ne!(racing["task_id"].as_str(), Some(first_id), "{racing}");

    let events = history_with(&app, &started, "ActivityRetryScheduled").await;
    let retry = events.iter().find(|event| event["event_type"] == "ActivityRetryScheduled").unwrap();
    let payload = &retry["payload"];
    assert_eq!(
        (payload["retry_reason"].clone(), payload["failure_reason"].clone(), payload["lease_owner"].clone()),
        (json!("lease_expired"), json!("lease_expired"), json!("worker")),
        "{retry}"
    );
    assert_eq!(payload["lease_expires_at"], "2020-01-02T03:04:05.000000Z", "{retry}");
    assert!(payload.get("timeout_kind").is_none(), "{retry}");
    assert_eq!(payload["message"], "Activity act lost its original task lease before the attempt settled.");
    assert_eq!(payload["retry_after_attempt"], 1);
    assert_eq!(
        count(&database, format!("SELECT COUNT(*) FROM workflow_tasks WHERE id='{first_id}' AND status='cancelled' AND last_error='lease_expired'")).await,
        1
    );
    assert_eq!(
        count(&database, format!("SELECT COUNT(*) FROM activity_attempts WHERE workflow_task_id='{first_id}' AND status='expired' AND closed_at IS NOT NULL")).await,
        1
    );

    // The retry is a new task and the second attempt.
    let second = if racing["task_id"].is_string() { racing } else { poll(&app, "worker", "activity").await };
    assert!(second["task_id"].is_string(), "{second}");
    assert_ne!(second["task_id"].as_str(), Some(first_id));
    assert_eq!(second["attempt_number"], 2, "{second}");
    let started_events = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let kinds: Vec<_> = started_events.as_array().unwrap().iter().map(|event| event["event_type"].as_str().unwrap().to_owned()).collect();
    let retried_at = kinds.iter().position(|kind| kind == "ActivityRetryScheduled").unwrap();
    assert_eq!(kinds.iter().filter(|kind| *kind == "ActivityStarted").count(), 2, "{kinds:?}");
    assert!(kinds.iter().rposition(|kind| kind == "ActivityStarted").unwrap() > retried_at, "{kinds:?}");
}

// With no attempt left, or when the policy names the lease exception as
// non-retryable, the lost lease fails the activity: an internal failure, not
// a timeout, and the workflow runs again to see it.
#[tokio::test]
async fn an_expired_activity_lease_without_retries_fails_the_activity_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!(["act"])).await;
    let non_retryable = json!({"max_attempts":3,"non_retryable_error_types":["Workflow\\V2\\Exceptions\\ActivityLeaseExpiredException"]});
    for (workflow, policy, flag) in [("lease-once", Value::Null, false), ("lease-non-retryable", non_retryable, true)] {
        let (started, task) = claimed(&app, workflow, policy).await;
        expire(&database, &task).await;
        let events = history_with(&app, &started, "ActivityFailed").await;
        assert!(!events.iter().any(|event| event["event_type"] == "ActivityTimedOut" || event["event_type"] == "ActivityRetryScheduled"));
        let failed = events.iter().find(|event| event["event_type"] == "ActivityFailed").unwrap();
        let payload = &failed["payload"];
        assert_eq!(
            [&payload["exception_class"], &payload["failure_category"], &payload["non_retryable"], &payload["failure_reason"]],
            [&json!("Workflow\\V2\\Exceptions\\ActivityLeaseExpiredException"), &json!("internal"), &json!(flag), &json!("lease_expired")],
            "{failed}"
        );
        assert!(payload.get("timeout_kind").is_none() && payload.get("close_deadline_at").is_none(), "{failed}");
        let run = started["run_id"].as_str().unwrap();
        let expected = if flag { "TRUE" } else { "FALSE" };
        assert_eq!(
            count(&database, format!("SELECT COUNT(*) FROM workflow_failures WHERE workflow_run_id='{run}' AND propagation_kind='activity' AND failure_category='internal' AND non_retryable={expected}")).await,
            1
        );
        // The workflow sees the failure and finishes.
        let resumed = poll(&app, "worker", "workflow").await;
        assert!(resumed["task_id"].is_string(), "{resumed}");
        let complete = json!({"type":"complete_workflow","result":envelope(Payload::Long(1))});
        assert_eq!(finish_task(&app, &resumed, json!([complete])).await.0, StatusCode::OK);
    }
}
