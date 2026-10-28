//! Several waits open at once (the `concurrent-waits` shared fixture):
//! published PHP lets a run hold any number of signal and condition waits,
//! each resolved by its own signal.
use super::*;

fn kinds(history: &Value) -> Vec<String> {
    history.as_array().unwrap().iter().map(|event| event["event_type"].as_str().unwrap().to_owned()).collect()
}

fn events<'a>(history: &'a Value, kind: &str) -> Vec<&'a Value> {
    history.as_array().unwrap().iter().filter(|event| event["event_type"] == kind).collect()
}

async fn signal(app: &Router, workflow: &str, name: &str) {
    let (status, body) = request(app, "POST", &format!("/api/workflows/{workflow}/signal/{name}"),
        json!({"input":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
}

#[tokio::test]
async fn a_run_holds_several_open_waits_each_resolved_by_its_own_signal() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "waits-worker", json!(["echo"]), json!([])).await;
    let run = start(&app, "several-waits").await;
    let task = poll(&app, "waits-worker", "workflow").await;
    let (status, opened) = finish_task(&app, &task, json!([
        {"type":"open_signal_wait","signal_name":"first"},
        {"type":"open_signal_wait","signal_name":"second","timeout_seconds":600},
        {"type":"open_condition_wait","condition_key":"ready","timeout_seconds":600},
    ])).await;
    assert_eq!(status, StatusCode::OK, "{opened}");
    // Each timed wait's timeout task is reported, as PHP reports it.
    assert_eq!(opened["created_task_ids"].as_array().unwrap().len(), 2, "{opened}");
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let opened_waits: Vec<_> = history.as_array().unwrap().iter()
        .filter(|event| matches!(event["event_type"].as_str(), Some("SignalWaitOpened" | "ConditionWaitOpened")))
        .map(|event| event["payload"]["sequence"].as_i64().unwrap()).collect();
    assert_eq!(opened_waits, [1, 2, 3]);
    let first_wait = events(&history, "SignalWaitOpened")[0]["payload"]["signal_wait_id"].clone();
    let second_wait = events(&history, "SignalWaitOpened")[1]["payload"]["signal_wait_id"].clone();

    // `second` reserves its own wait; the resumed task applies it there,
    // cancels its timeout and satisfies the open condition wait.
    signal(&app, "several-waits", "second").await;
    let resumed = poll(&app, "waits-worker", "workflow").await;
    assert_eq!((resumed["workflow_wait_kind"].clone(), resumed["signal_wait_id"].clone()), (json!("signal"), second_wait.clone()), "{resumed}");
    assert_eq!(resumed["open_wait_id"], json!(format!("signal-application:{}", resumed["workflow_signal_id"].as_str().unwrap())));
    let (status, body) = finish_task(&app, &resumed, json!([{"type":"start_timer","delay_seconds":600}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let applied = events(&history, "SignalApplied");
    assert_eq!((applied.len(), applied[0]["payload"]["sequence"].clone()), (1, json!(2)));
    let satisfied = events(&history, "ConditionWaitSatisfied");
    assert_eq!((satisfied.len(), satisfied[0]["payload"]["sequence"].clone(), satisfied[0]["payload"]["signal_wait_id"].clone()),
        (1, json!(3), second_wait));
    assert_eq!(events(&history, "TimerCancelled").len(), 2, "both timeouts cancelled: {:?}", kinds(&history));

    // `first` then resumes its own wait at its own sequence.
    signal(&app, "several-waits", "first").await;
    let resumed = poll(&app, "waits-worker", "workflow").await;
    assert_eq!(resumed["signal_wait_id"], first_wait, "{resumed}");
    let (status, body) = finish_task(&app, &resumed, json!([{"type":"complete_workflow","result":envelope(Payload::Array(vec![]))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let applied = events(&history, "SignalApplied");
    assert_eq!(applied.iter().map(|event| event["payload"]["sequence"].as_i64().unwrap()).collect::<Vec<_>>(), [2, 1]);
    assert_eq!(kinds(&history).last().unwrap(), "WorkflowCompleted");
}

#[tokio::test]
async fn a_second_signal_for_one_name_is_buffered_behind_the_first() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "dup-worker", json!(["echo"]), json!([])).await;
    let run = start(&app, "same-name-waits").await;
    let task = poll(&app, "dup-worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([
        {"type":"open_signal_wait","signal_name":"dup"},
        {"type":"open_signal_wait","signal_name":"dup"},
    ])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    signal(&app, "same-name-waits", "dup").await;
    signal(&app, "same-name-waits", "dup").await;
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let later = events(&history, "SignalWaitOpened")[1]["payload"]["signal_wait_id"].clone();
    let received: Vec<_> = events(&history, "SignalReceived").iter().map(|event| event["payload"]["signal_wait_id"].clone()).collect();
    // `signalWaitIdForAcceptedCommand`: the first reserves the later wait;
    // the second waits behind it with a buffered identity.
    assert_eq!(received[0], later);
    assert!(received[1].as_str().unwrap().starts_with("signal-command:"), "{received:?}");
    let resumed = poll(&app, "dup-worker", "workflow").await;
    assert_eq!(resumed["signal_wait_id"], later);
    let (status, body) = finish_task(&app, &resumed, json!([{"type":"start_timer","delay_seconds":600}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let applied = events(&history, "SignalApplied");
    assert_eq!((applied.len(), applied[0]["payload"]["sequence"].clone()), (1, json!(2)));
}
