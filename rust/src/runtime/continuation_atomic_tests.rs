//! A writer arriving after the result write must wait until the commands
//! and receipt commit. Reads must see neither part while that lock is held.
use super::{Runtime, Storage};
use crate::codec::{Value as Payload, ValueCodec};
use axum::{Router, body::{Body, to_bytes}, http::{Request, StatusCode}};
use serde_json::{Value, json};
use std::{sync::Arc, time::Duration};
use tower::ServiceExt;

fn envelope(value: Payload) -> Value {
    json!({"codec":"avro","blob":ValueCodec::new().unwrap().encode(&value).unwrap()})
}

async fn request(app: &Router, method: &str, path: &str, body: Value) -> (StatusCode, Value) {
    let response = app.clone().oneshot(Request::builder().method(method).uri(path)
        .header("authorization", "Bearer test-token")
        .header("x-namespace", "default")
        .header("x-durable-workflow-control-plane-version", "2")
        .header("x-durable-workflow-protocol-version", "1.20")
        .header("content-type", "application/json")
        .body(Body::from(body.to_string())).unwrap()).await.unwrap();
    let status = response.status();
    let bytes = to_bytes(response.into_body(), 4 * 1024 * 1024).await.unwrap();
    (status, serde_json::from_slice(&bytes).unwrap())
}

#[tokio::test]
async fn a_signal_waits_for_the_result_commands_and_receipt_to_commit_together() {
    let directory = tempfile::tempdir().unwrap();
    let runtime = Runtime::open(directory.path().join("runtime.sqlite").to_str().unwrap(), "test-token".into()).await.unwrap();
    runtime.register_unversioned(json!({"worker_id":"atomic-worker","task_queue":"test","runtime":"php",
        "supported_workflow_types":["echo"],"supported_activity_types":["activity"]})).await.unwrap();
    let started = runtime.start(json!({"workflow_id":"atomic-race","workflow_type":"echo","task_queue":"test",
        "input":envelope(Payload::Array(vec![]))}), None).await.unwrap();
    let workflow = runtime.poll(json!({"worker_id":"atomic-worker","task_queue":"test","timeout_seconds":0}), "workflow", "1.20").await.unwrap()["task"].clone();
    runtime.complete_workflow(workflow["task_id"].as_str().unwrap(), json!({"lease_owner":"atomic-worker",
        "workflow_task_attempt":workflow["workflow_task_attempt"],"commands":[{"type":"schedule_activity",
            "activity_type":"activity","arguments":envelope(Payload::Array(vec![]))}]}), None, Some("1.20")).await.unwrap();
    let activity = runtime.poll(json!({"worker_id":"atomic-worker","task_queue":"test","timeout_seconds":0}), "activity", "1.20").await.unwrap()["task"].clone();
    let history = runtime.continuation_history(activity["task_id"].as_str().unwrap(), json!({"lease_owner":"atomic-worker",
        "activity_attempt_id":activity["activity_attempt_id"]})).await.unwrap();
    let gate = Arc::new(crate::runtime::commit_handoff_tests::CommitGate::default());
    let Storage::Sqlite(store) = runtime.storage.as_ref() else { unreachable!() };
    *store.test_continuation_gate.lock().unwrap() = Some(gate.clone());
    let app = crate::runtime::router(runtime.clone());
    let path = format!("/api/worker/activity-tasks/{}/continuation/complete", activity["task_id"].as_str().unwrap());
    let body = json!({"lease_owner":"atomic-worker","activity_attempt_id":activity["activity_attempt_id"],
        "result":envelope(Payload::Long(7)),"workflow_poll":{"worker_id":"atomic-worker","task_queue":"test",
            "poll_request_id":"atomic-race-poll","timeout_seconds":0},
        "continuation":{"version":1,"history_through_sequence":history["history_through_sequence"],
            "history_last_event_id":history["history_last_event_id"],"commands":[
                {"type":"record_version_marker","change_id":"atomic-race","version":1,"min_supported":1,"max_supported":1},
                {"type":"start_timer","delay_seconds":30}]}});
    let mut completion = Box::pin(request(&app, "POST", &path, body));
    tokio::select! {
        _ = gate.entered.notified() => {},
        answer = &mut completion => panic!("completion passed its test gate: {}", answer.0),
    }
    let history_path = format!("/api/workflows/atomic-race/runs/{}/history", started["run_id"].as_str().unwrap());
    let (status, read) = request(&app, "GET", &history_path, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{}", read["reason"]);
    assert!(!read["events"].as_array().unwrap().iter().any(|event| event["event_type"] == "ActivityCompleted"),
        "a separate reader must not see a partial result");
    let mut signal = Box::pin(request(&app, "POST", "/api/workflows/atomic-race/signal/note", json!([1])));
    assert!(tokio::time::timeout(Duration::from_millis(100), signal.as_mut()).await.is_err(),
        "a signal cannot interleave between the result and speculative commands");
    gate.resume.notify_one();
    let (done, signalled) = tokio::join!(completion, signal);
    assert_eq!(done.0, StatusCode::OK, "{}", done.1["reason"]);
    assert_eq!(done.1["continuation"]["outcome"], "accepted");
    assert_eq!(signalled.0, StatusCode::ACCEPTED, "{}", signalled.1["reason"]);
    let (status, read) = request(&app, "GET", &history_path, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{}", read["reason"]);
    let events = read["events"].as_array().unwrap();
    let position = |kind| events.iter().position(|event| event["event_type"] == kind).unwrap();
    assert!(position("ActivityCompleted") < position("VersionMarkerRecorded"));
    assert!(position("VersionMarkerRecorded") < position("TimerScheduled"));
    assert!(position("TimerScheduled") < position("SignalReceived"));
    runtime.close().await;
}
