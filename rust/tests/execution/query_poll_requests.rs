//! A live query lease belongs to the poll request that claimed it. As in
//! published PHP, a retry of that same request receives it again; any other
//! poll, from the same worker or another, is an independent slot.
use super::*;

async fn poll_with(app: &Router, worker: &str, request_id: Option<&str>) -> Value {
    let mut body = json!({"worker_id":worker,"task_queue":"test","timeout_seconds":0});
    if let Some(id) = request_id {
        body["poll_request_id"] = json!(id);
    }
    let (status, response) = request(app, "POST", "/api/worker/query-tasks/poll", body).await;
    assert_eq!(status, StatusCode::OK, "{response}");
    response["task"].clone()
}

fn assert_replay(first: &Value, repeated: &Value) {
    let initial =
        chrono::DateTime::parse_from_rfc3339(first["server_time"].as_str().unwrap()).unwrap();
    let current =
        chrono::DateTime::parse_from_rfc3339(repeated["server_time"].as_str().unwrap()).unwrap();
    assert!(current >= initial);
    let mut replay = repeated.clone();
    // Only the observation clock may differ. The claim, its attempt, lease,
    // timing and every payload field stay exactly as first delivered.
    replay["server_time"] = first["server_time"].clone();
    assert_eq!(replay, *first);
}

#[tokio::test]
async fn query_lease_replays_only_to_the_poll_request_that_claimed_it() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    query_worker(&app, "request-worker").await;
    query_worker(&app, "other-worker").await;
    let started = start(&app, "poll-request-query").await;
    let workflow_task = poll(&app, "request-worker", "workflow").await;
    let completed = finish_task(
        &app,
        &workflow_task,
        json!([{"type":"complete_workflow","result":envelope(Payload::Long(7))}]),
    )
    .await;
    assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
    let query_path = format!(
        "/api/workflows/poll-request-query/runs/{}/query/state",
        started["run_id"].as_str().unwrap()
    );
    let waiting = {
        let app = app.clone();
        tokio::spawn(async move {
            request(&app, "POST", &query_path,
                json!({"input":envelope(Payload::Array(vec![Payload::Long(1)]))})).await
        })
    };
    let claim = tokio::time::timeout(std::time::Duration::from_secs(5), async {
        loop {
            let task = poll_with(&app, "request-worker", Some("first")).await;
            if !task.is_null() {
                break task;
            }
            tokio::time::sleep(std::time::Duration::from_millis(10)).await;
        }
    })
    .await
    .expect("query is admitted");
    assert!(claim.get("poll_request_id").is_none(), "{claim}");

    assert_replay(&claim, &poll_with(&app, "request-worker", Some("first")).await);
    assert!(poll_with(&app, "request-worker", Some("second")).await.is_null());
    assert!(poll_with(&app, "request-worker", None).await.is_null());
    assert!(poll_with(&app, "other-worker", Some("first")).await.is_null());
    // A newer request does not take the lease away from the original one.
    assert_replay(&claim, &poll_with(&app, "request-worker", Some("first")).await);

    let invalid = request(&app, "POST", "/api/worker/query-tasks/poll",
        json!({"worker_id":"request-worker","task_queue":"test","timeout_seconds":0,"poll_request_id":7})).await;
    assert_eq!(invalid.0, StatusCode::UNPROCESSABLE_ENTITY, "{}", invalid.1);

    let finish = request(
        &app,
        "POST",
        &format!("/api/worker/query-tasks/{}/complete", claim["query_task_id"].as_str().unwrap()),
        json!({"lease_owner":"request-worker","query_task_attempt":claim["query_task_attempt"],
            "result_envelope":envelope(Payload::Long(7))}),
    )
    .await;
    assert_eq!(finish.0, StatusCode::OK, "{}", finish.1);
    assert_eq!(waiting.await.unwrap().0, StatusCode::OK);
    assert!(poll_with(&app, "request-worker", Some("first")).await.is_null());
    runtime.close().await;
    database.remove().await;
}
