use super::*;

async fn author(app: &Router, worker: &str) {
    let registered = request(app, "POST", "/api/worker/register", json!({
        "worker_id":worker,"task_queue":"test","runtime":"php",
        "supported_workflow_types":["echo"],"supported_activity_types":[],
        "workflow_command_contracts":{"echo":{"signals":["payload"],"signal_contracts":[{
            "name":"payload","parameters":[{"name":"value","position":0,"type":"int",
                "required":true,"variadic":false,"default_available":false,"allows_null":false}]}]}}
    })).await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
}

async fn expiry(seconds: i64) {
    let database = TestDatabase::new().await;
    let first = database.open().await.unwrap();
    let app = router(first.clone());
    author(&app, "timed-signal-author").await;
    let started = start(&app, &format!("timed-signal-{seconds}")).await;
    let original = poll(&app, "timed-signal-author", "workflow").await;
    let commands = json!([{"type":"open_signal_wait","signal_name":"payload","timeout_seconds":seconds}]);
    let opened = finish_task(&app, &original, commands.clone()).await;
    assert_eq!(opened.0, StatusCode::OK, "{}", opened.1);
    let prefix = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(finish_task(&app, &original, commands).await.1["recorded"], false);
    assert_eq!(run_history(&app, &started["workflow_id"], &started["run_id"]).await, prefix);
    drop(app);
    first.close().await;

    let second = database.open().await.unwrap();
    let app = router(second.clone());
    author(&app, "timed-signal-resumed").await;
    let response = request(&app, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"timed-signal-resumed","task_queue":"test","timeout_seconds":3})).await;
    assert_eq!(response.0, StatusCode::OK, "{}", response.1);
    let resumed = &response.1["task"];
    assert!(!resumed.is_null(), "original timeout did not resume within three seconds");
    let events = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(events.as_array().unwrap().iter().map(|e| e["event_type"].as_str().unwrap()).collect::<Vec<_>>(),
        ["StartAccepted", "WorkflowStarted", "SignalWaitOpened", "TimerScheduled", "TimerFired"]);
    let opened = &events[2]["payload"];
    let scheduled = &events[3]["payload"];
    let fired = &events[4]["payload"];
    for payload in [scheduled, fired] {
        assert_eq!(payload["timer_kind"], "signal_timeout");
        assert_eq!(payload["signal_wait_id"], opened["signal_wait_id"]);
        assert_eq!(payload["signal_name"], "payload");
        assert_eq!(payload["sequence"], opened["sequence"]);
        assert_eq!(payload["delay_seconds"], seconds);
    }
    assert_eq!(fired["timer_id"], scheduled["timer_id"]);
    let planned = chrono::DateTime::parse_from_rfc3339(scheduled["fire_at"].as_str().unwrap()).unwrap();
    let actual = chrono::DateTime::parse_from_rfc3339(fired["fired_at"].as_str().unwrap()).unwrap();
    assert!(actual >= planned, "timeout fired before its original deadline");
    assert_eq!(resumed["workflow_wait_kind"], "signal");
    assert_eq!(resumed["open_wait_id"], opened["signal_wait_id"]);
    assert_eq!(resumed["resume_source_kind"], "timer");
    assert_eq!(resumed["resume_source_id"], scheduled["timer_id"]);
    assert_eq!(resumed["timer_id"], scheduled["timer_id"]);
    assert_eq!(resumed["timer_kind"], "signal_timeout");
    let completed = finish_task(&app, resumed,
        json!([{"type":"complete_workflow","result":envelope(Payload::Boolean(false))}])).await;
    assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
    assert_eq!(completed.1["run_status"], "completed");
    second.close().await;
    database.remove().await;
}

#[tokio::test]
async fn immediate_timeout_retains_original_identity_across_reopen() {
    expiry(0).await;
}

#[tokio::test]
async fn positive_timeout_retains_original_deadline_across_reopen() {
    expiry(1).await;
}
