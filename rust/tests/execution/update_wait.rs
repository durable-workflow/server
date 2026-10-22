use super::*;

// Workflow 2.9.0 `UpdateWaitPolicy` and `WorkflowStub::attemptUpdate`: a
// completion wait without its own timeout lasts the configured default (10
// seconds unless DW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS says otherwise)
// and reports it. An acceptance wait reports no timeout, even a requested one.
#[tokio::test]
async fn update_waits_report_php_timeouts() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    update_worker(&app, "update-a").await;
    start(&app, "wait-default").await;
    open_update_wait(&app, "update-a").await;
    let input = envelope(Payload::Array(vec![Payload::Long(5)]));

    let (status, accepted) = request(&app, "POST", "/api/workflows/wait-default/update/set",
        json!({"input":input,"wait_for":"accepted","wait_timeout_seconds":5})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{accepted}");
    assert!(accepted["wait_timeout_seconds"].is_null(), "{accepted}");

    let task = poll(&app, "update-a", "workflow").await;
    assert_eq!(task["workflow_update_id"], accepted["update_id"], "{task}");
    let waiting = {
        let app = app.clone();
        tokio::spawn(async move {
            request(&app, "POST", "/api/workflows/wait-default/update/set",
                json!({"input":envelope(Payload::Array(vec![Payload::Long(6)])),"wait_for":"completed"})).await
        })
    };
    let (status, body) = finish_task(&app, &task, json!([{"type":"complete_update","update_id":accepted["update_id"],
        "result":envelope(Payload::Long(1))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    // The spawned request is admitted concurrently; poll until its task.
    let task = tokio::time::timeout(std::time::Duration::from_secs(5), async {
        loop {
            let task = poll(&app, "update-a", "workflow").await;
            if !task.is_null() {
                break task;
            }
            tokio::time::sleep(std::time::Duration::from_millis(20)).await;
        }
    })
    .await
    .expect("the second update is delivered");
    let second = task["workflow_update_id"].clone();
    assert!(second.is_string(), "{task}");
    let (status, body) = finish_task(&app, &task, json!([{"type":"complete_update","update_id":second,
        "result":envelope(Payload::Long(2))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (status, completed) = tokio::time::timeout(std::time::Duration::from_secs(9), waiting)
        .await
        .expect("the completion wait returns once the update completes")
        .unwrap();
    assert_eq!(status, StatusCode::OK, "{completed}");
    assert_eq!(completed["wait_timeout_seconds"], 10, "{completed}");
    assert_eq!(completed["wait_timed_out"], false, "{completed}");
}
