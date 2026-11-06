use super::*;

fn response_clock(answer: &Value, before: i64, after: i64) -> chrono::DateTime<chrono::FixedOffset> {
    let text = answer["server_time"].as_str().expect("leased workflow poll observation clock");
    let clock = chrono::DateTime::parse_from_rfc3339(text).unwrap();
    assert_eq!(text, clock.to_rfc3339_opts(chrono::SecondsFormat::Micros, true));
    // The existing Server clock has millisecond precision, encoded with six
    // fractional digits just like activity poll responses.
    assert!((before..=after).contains(&clock.timestamp_millis()), "{answer}");
    clock
}

#[tokio::test]
async fn plain_and_multiplexed_workflow_polls_refresh_only_the_response_clock() {
    for mixed in [false, true] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        super::update_validation::validating_worker(&app, "clock-worker", "test", None, true).await;
        let run = start(&app, if mixed { "mixed-clock" } else { "plain-clock" }).await;
        let mut body = json!({"worker_id":"clock-worker","task_queue":"test",
            "timeout_seconds":0,"poll_request_id":"clock-request","history_page_size":1});
        if mixed {
            body["task_kinds"] = json!(["workflow", "update_validation"]);
        }
        let before = chrono::Utc::now().timestamp_millis();
        let first = request(&app, "POST", "/api/worker/workflow-tasks/poll", body.clone()).await;
        assert_eq!(first.0, StatusCode::OK, "{}", first.1);
        assert_eq!(first.1["task"]["task_kind"], "workflow");
        let first_clock = response_clock(&first.1, before, chrono::Utc::now().timestamp_millis());
        let expiry = chrono::DateTime::parse_from_rfc3339(first.1["task"]["lease_expires_at"].as_str().unwrap()).unwrap();
        assert!(expiry > first_clock);
        let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
        tokio::time::sleep(std::time::Duration::from_millis(3)).await;
        let before = chrono::Utc::now().timestamp_millis();
        let replay = request(&app, "POST", "/api/worker/workflow-tasks/poll", body.clone()).await;
        assert_eq!(replay.0, first.0);
        assert_poll_replay(&first.1, &replay.1, "same request preserves every claim and history field");
        let replay_clock = response_clock(&replay.1, before, chrono::Utc::now().timestamp_millis());
        assert!(replay_clock > first_clock, "replay observes this response, not the cached clock");
        runtime.close().await;
        drop(app);
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        let before = chrono::Utc::now().timestamp_millis();
        let cold = request(&app, "POST", "/api/worker/workflow-tasks/poll", body).await;
        assert_eq!(cold.0, first.0);
        assert_poll_replay(&first.1, &cold.1, "cold replay preserves the original lease authority");
        assert!(response_clock(&cold.1, before, chrono::Utc::now().timestamp_millis()) >= replay_clock);
        assert_eq!(run_history(&app, &run["workflow_id"], &run["run_id"]).await, history);
        runtime.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn an_empty_workflow_poll_keeps_its_existing_response_shape() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "worker", json!(["echo"]), json!([])).await;
    let (status, answer) = request(&app, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"worker","task_queue":"test","timeout_seconds":0})).await;
    assert_eq!(status, StatusCode::OK, "{answer}");
    assert!(answer["task"].is_null());
    assert!(answer.get("server_time").is_none());
    runtime.close().await;
    database.remove().await;
}
