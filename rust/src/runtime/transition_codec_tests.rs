//! Transitions that encode or decode while holding the transition lock use
//! their own codec permit. Each test holds the read codec permit, as a long
//! describe decode would, and requires the transition to finish anyway.
use super::{Result, Runtime};
use crate::codec::{Value as Payload, ValueCodec};
use axum::http::StatusCode;
use serde_json::{Value, json};
use std::time::Duration;

fn envelope(value: Payload) -> Value {
    json!({"codec":"avro","blob":ValueCodec::new().unwrap().encode(&value).unwrap()})
}

const DECISIVE: Duration = Duration::from_secs(1);

async fn runtime(directory: &tempfile::TempDir) -> Runtime {
    let runtime = Runtime::open(
        directory.path().join("runtime.sqlite").to_str().unwrap(),
        "test-token".into(),
    )
    .await
    .unwrap();
    runtime
        .register(json!({"worker_id":"codec-worker","task_queue":"test","runtime":"php",
            "supported_workflow_types":["echo"],"supported_activity_types":[],
            "workflow_command_contracts":{"echo":{"signals":["payload"],"signal_contracts":[{"name":"payload",
                "parameters":[{"name":"value","position":0,"type":"array","required":true,"variadic":false,
                    "default_available":false,"allows_null":false}]}]}}}))
        .await
        .unwrap();
    runtime
}

async fn start(runtime: &Runtime, workflow_id: &str) {
    runtime
        .start(json!({"workflow_id":workflow_id,"workflow_type":"echo","task_queue":"test",
            "input":envelope(Payload::Array(vec![]))}), None)
        .await
        .unwrap();
}

async fn poll(runtime: &Runtime) -> Value {
    let task = runtime
        .poll(
            json!({"worker_id":"codec-worker","task_queue":"test","timeout_seconds":0}),
            "workflow",
            "1.20",
        )
        .await
        .unwrap()["task"]
        .clone();
    assert!(!task.is_null(), "workflow task is ready");
    task
}

async fn complete(runtime: &Runtime, task: &Value, commands: Value) -> Result<Value> {
    runtime
        .complete_workflow(
            task["task_id"].as_str().unwrap(),
            json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],
                "commands":commands}), None,
        )
        .await
}

#[tokio::test]
async fn cancellation_request_does_not_wait_for_read_codec_capacity() {
    let directory = tempfile::tempdir().unwrap();
    let runtime = runtime(&directory).await;
    start(&runtime, "codec-cancel").await;
    let _held = runtime.signal_codec.clone().acquire_owned().await.unwrap();
    let (status, accepted) = tokio::time::timeout(
        DECISIVE,
        runtime.request_cancellation(
            "codec-cancel",
            None,
            json!({"reason":"held read permit","cleanup_timeout_seconds":30}), None,
        ),
    )
    .await
    .expect("the cancellation encode must not wait for read codec capacity")
    .unwrap();
    assert_eq!(status, StatusCode::ACCEPTED, "{accepted}");
    runtime.close().await;
}

#[tokio::test]
async fn signal_resumed_completion_does_not_wait_for_read_codec_capacity() {
    let directory = tempfile::tempdir().unwrap();
    let runtime = runtime(&directory).await;
    start(&runtime, "codec-signal").await;
    let waiting = poll(&runtime).await;
    complete(
        &runtime,
        &waiting,
        json!([{"type":"open_signal_wait","signal_name":"payload"}]),
    )
    .await
    .unwrap();
    runtime
        .signal(
            "codec-signal",
            None,
            "payload",
            json!({"input":envelope(Payload::Array(vec![Payload::Map(
                std::collections::BTreeMap::from([("count".into(), Payload::Long(7))]),
            )]))}), None,
        )
        .await
        .unwrap();
    let resumed = poll(&runtime).await;
    let _held = runtime.signal_codec.clone().acquire_owned().await.unwrap();
    let completed = tokio::time::timeout(
        DECISIVE,
        complete(
            &runtime,
            &resumed,
            json!([{"type":"complete_workflow","result":envelope(Payload::Long(7))}]),
        ),
    )
    .await
    .expect("applying the signal must not wait for read codec capacity")
    .unwrap();
    assert_eq!(completed["recorded"], true, "{completed}");
    runtime.close().await;
}

#[tokio::test]
async fn fenced_deregistration_repair_does_not_wait_for_read_codec_capacity() {
    let directory = tempfile::tempdir().unwrap();
    let runtime = Runtime::open(
        directory.path().join("runtime.sqlite").to_str().unwrap(),
        "test-token".into(),
    )
    .await
    .unwrap();
    let registration = runtime
        .register(json!({"worker_id":"codec-worker","task_queue":"test","runtime":"php",
            "supported_workflow_types":["echo"],"supported_activity_types":[]}))
        .await
        .unwrap();
    start(&runtime, "codec-repair").await;
    poll(&runtime).await;
    let _held = runtime.signal_codec.clone().acquire_owned().await.unwrap();
    let deregistered = tokio::time::timeout(
        DECISIVE,
        runtime.deregister_fenced(
            "codec-worker",
            json!({"registration_token":registration["registration_token"]}),
        ),
    )
    .await
    .expect("repairing the leased task must not wait for read codec capacity")
    .unwrap();
    assert_eq!(deregistered["recovered_workflow_task_count"], 1, "{deregistered}");
    runtime.close().await;
}

#[tokio::test]
async fn range_repair_does_not_wait_for_read_codec_capacity() {
    use axum::{
        body::{Body, to_bytes},
        http::Request,
    };
    use tower::ServiceExt;
    let directory = tempfile::tempdir().unwrap();
    let runtime = runtime(&directory).await;
    start(&runtime, "codec-range").await;
    let task = poll(&runtime).await;
    let reported = runtime
        .fail_workflow_task(
            task["task_id"].as_str().unwrap(),
            json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],
                "failure":{"message":"Recorded version 1 is outside supported range 2..2.",
                    "type":"DurableWorkflow\\Exception\\NonDeterministicWorkflow",
                    "reason":"version_marker_incompatible_range","sequence":1}}),
        )
        .await
        .unwrap();
    assert_eq!(reported["recorded"], true, "{reported}");
    // The repair context comes from the authenticated request, so use the route.
    let request = Request::builder()
        .method("POST")
        .uri("/api/workflows/codec-range/repair")
        .header("authorization", "Bearer test-token")
        .header("content-type", "application/json")
        .header("x-namespace", "default")
        .header("x-durable-workflow-control-plane-version", "2")
        .body(Body::empty())
        .unwrap();
    let _held = runtime.signal_codec.clone().acquire_owned().await.unwrap();
    let response = tokio::time::timeout(
        DECISIVE,
        super::super::router(runtime.clone()).oneshot(request),
    )
    .await
    .expect("the repair payload encode must not wait for read codec capacity")
    .unwrap();
    assert_eq!(response.status(), StatusCode::OK);
    let repaired: Value =
        serde_json::from_slice(&to_bytes(response.into_body(), 1 << 20).await.unwrap()).unwrap();
    assert_eq!(repaired["outcome"], "repair_dispatched", "{repaired}");
    runtime.close().await;
}
