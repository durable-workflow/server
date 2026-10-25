//! Runtime external references on signal, update and query input, as
//! published PHP 2.11.1 resolves them: a signal keeps the reference, an update
//! and a query carry the referenced bytes inline, and each claims the object.
use super::*;

async fn upload(router: &Router, blob: &str) -> Value {
    let (status, _, body) = request(router, "POST", "/api/external-payloads/v1", "worker-token", "default",
        Some(&metadata(blob.as_bytes())), Body::from(blob.to_owned())).await;
    assert_eq!(status, StatusCode::CREATED, "{}", String::from_utf8_lossy(&body));
    serde_json::from_slice::<Value>(&body).unwrap()["reference"].clone()
}

fn argument(value: &str) -> String {
    durable_workflow_server::codec::ValueCodec::new()
        .unwrap()
        .encode(&Payload::Array(vec![Payload::String(value.into())]))
        .unwrap()
}

fn string_parameter() -> Value {
    json!([{"name":"value","type":"string","required":true,"variadic":false,"default_available":false,"allows_null":false}])
}

/// Starts `workflow_id` on its own queue and leaves it waiting on a timer.
async fn waiting_run(app: &Router, workflow_id: &str, worker: Value) {
    let queue = worker["task_queue"].clone();
    let worker_id = worker["worker_id"].clone();
    let (status, body) = json_request(app, "POST", "/api/worker/register", "worker-token", worker).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    let (status, body) = json_request(app, "POST", "/api/workflows", "operator-token",
        json!({"workflow_id":workflow_id,"workflow_type":"echo","task_queue":queue,"input":[]})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    let (status, polled) = json_request(app, "POST", "/api/worker/workflow-tasks/poll", "worker-token",
        json!({"worker_id":worker_id,"task_queue":queue,"timeout_seconds":0})).await;
    assert_eq!(status, StatusCode::OK, "{polled}");
    let task = &polled["task"];
    let (status, body) = json_request(app, "POST", &format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap()),
        "worker-token", json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],
            "commands":[{"type":"start_timer","delay_seconds":3600}]})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
}

fn typed(body: &Value, status: u16, reason: &str, message: &str, workflow_id: &str) {
    assert_eq!(body["schema"], "durable-workflow.v2.runtime-external-payload-error.v1", "{body}");
    assert_eq!((body["status"].as_u64(), body["reason"].as_str(), body["message"].as_str()),
        (Some(u64::from(status)), Some(reason), Some(message)), "{body}");
    assert_eq!(body["retryable"], false, "{body}");
    assert_eq!(body["workflow_id"], workflow_id, "{body}");
}

#[tokio::test]
async fn signal_update_and_query_input_resolve_runtime_references_like_php() {
    let temp = tempfile::tempdir().unwrap();
    let (database, runtime, app) = setup(ExternalPayloadConfig::default(), &temp.path().join("objects")).await;

    // A signal keeps the reference; its history shows the external envelope.
    waiting_run(&app, "external-signal", json!({"worker_id":"signal-worker","task_queue":"external-signal",
        "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":[]})).await;
    let signal = upload(&app, &argument("signal")).await;
    assert_eq!(unclaimed(&database).await, 1);
    let (status, body) = json_request(&app, "POST", "/api/workflows/external-signal/signal/s", "operator-token",
        json!({"input":{"codec":"avro","external_payload":signal}})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    assert_eq!(unclaimed(&database).await, 0);
    let (_, history) = json_request(&app, "GET",
        &format!("/api/workflows/external-signal/runs/{}/history", body["run_id"].as_str().unwrap()), "operator-token", Value::Null).await;
    let received = history["events"].as_array().unwrap().iter().find(|event| event["event_type"] == "SignalReceived").expect("signal event");
    assert_eq!(received["payload"]["arguments"], json!({"codec":"avro","external_payload":signal}), "{received}");

    // An update resolves the reference to its bytes and keeps them inline.
    waiting_run(&app, "external-update", json!({"worker_id":"update-worker","task_queue":"external-update",
        "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":[],"capabilities":["workflow_updates"],
        "workflow_command_contracts":{"echo":{"updates":["u"],"update_contracts":[{"name":"u","parameters":string_parameter()}]}}})).await;
    let blob = argument("update");
    let update = upload(&app, &blob).await;
    let (status, body) = json_request(&app, "POST", "/api/workflows/external-update/update/u", "operator-token",
        json!({"input":{"codec":"avro","external_payload":update},"wait_for":"accepted"})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    assert_eq!(body["update_status"], "accepted", "{body}");
    assert_eq!(unclaimed(&database).await, 0);
    let (status, polled) = json_request(&app, "POST", "/api/worker/workflow-tasks/poll", "worker-token",
        json!({"worker_id":"update-worker","task_queue":"external-update","timeout_seconds":0})).await;
    assert_eq!(status, StatusCode::OK, "{polled}");
    let accepted = polled["task"]["history_events"].as_array().unwrap().iter()
        .find(|event| event["event_type"] == "UpdateAccepted").expect("update event");
    assert_eq!(accepted["payload"]["arguments"], json!({"codec":"avro","blob":blob}), "{accepted}");

    // A query carries the bytes to its worker.
    waiting_run(&app, "external-query", json!({"worker_id":"query-worker","task_queue":"external-query",
        "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":[],"capabilities":["query_tasks"],
        "workflow_command_contracts":{"echo":{"queries":["look"],"query_contracts":[{"name":"look","parameters":string_parameter()}]}}})).await;
    let blob = argument("query");
    let query = upload(&app, &blob).await;
    let asked = {
        let app = app.clone();
        let query = query.clone();
        tokio::spawn(async move {
            json_request(&app, "POST", "/api/workflows/external-query/query/look", "operator-token",
                json!({"input":{"codec":"avro","external_payload":query}})).await
        })
    };
    let task = tokio::time::timeout(Duration::from_secs(5), async {
        loop {
            let (status, body) = json_request(&app, "POST", "/api/worker/query-tasks/poll", "worker-token",
                json!({"worker_id":"query-worker","task_queue":"external-query","timeout_seconds":0})).await;
            assert_eq!(status, StatusCode::OK, "{body}");
            if !body["task"].is_null() {
                break body["task"].clone();
            }
            tokio::time::sleep(Duration::from_millis(10)).await;
        }
    })
    .await
    .expect("query task");
    assert_eq!(task["query_arguments"], json!({"codec":"avro","blob":blob}), "{task}");
    let (status, body) = json_request(&app, "POST", &format!("/api/worker/query-tasks/{}/complete", task["query_task_id"].as_str().unwrap()),
        "worker-token", json!({"lease_owner":"query-worker","query_task_attempt":task["query_task_attempt"],
            "result_envelope":{"codec":"avro","blob":blob}})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (status, answer) = asked.await.unwrap();
    assert_eq!(status, StatusCode::OK, "{answer}");
    assert_eq!(answer["result_envelope"], json!({"codec":"avro","blob":blob}), "{answer}");
    assert_eq!(unclaimed(&database).await, 0);

    // Refusals are PHP's typed external payload errors, named for the
    // workflow, and claim nothing.
    let spare = upload(&app, &argument("spare")).await;
    assert_eq!(unclaimed(&database).await, 1);
    let mut missing = spare.clone();
    missing["reference_id"] = json!("ep_01M5KMWZFKJPWN8PZTTXMC4VH0");
    for (path, input, status, reason, message) in [
        ("/api/workflows/external-signal/signal/s", json!({"codec":"avro","external_payload":spare,"extra":1}), 422,
            "external_payload_unsupported", "External payload envelopes must contain exactly codec and external_payload."),
        ("/api/workflows/external-update/update/u", json!({"codec":"json","external_payload":spare}), 422,
            "external_payload_integrity_mismatch", "External payload envelope codec does not match the runtime reference."),
        // The lookup comes before the codec comparison.
        ("/api/workflows/external-query/query/look", json!({"codec":"json","external_payload":missing}), 404,
            "external_payload_not_found", "External payload reference was not found in this namespace."),
        ("/api/workflows/external-signal/signal/s", json!({"codec":"avro","external_storage":{"uri":"file:///elsewhere"}}), 422,
            "external_payload_unsupported", "Provider-specific external_storage references are not accepted by the runtime transport."),
        ("/api/workflows/external-update/update/u", json!({"codec":"avro","external_payload":{"reference_id":"ep_1"}}), 422,
            "external_payload_unsupported",
            "Runtime external payload references must contain exactly schema, reference_id, codec, size_bytes, and sha256."),
    ] {
        let (code, body) = json_request(&app, "POST", path, "operator-token", json!({"input":input})).await;
        assert_eq!(code.as_u16(), status, "{path} {body}");
        typed(&body, status, reason, message, path.split('/').nth(3).unwrap());
        assert_eq!(body["control_plane"]["reason"], reason, "{body}");
    }
    assert_eq!(unclaimed(&database).await, 1);
    runtime.close().await;
    database.remove().await;
}

/// One described command of `kind`, which must show only metadata.
async fn described(app: &Router, workflow_id: &str, kind: &str) -> Value {
    let (status, body) = json_request(app, "GET", &format!("/api/workflows/{workflow_id}"), "operator-token", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let command = body["commands"].as_array().unwrap().iter().find(|command| command["type"] == kind).unwrap().clone();
    for field in ["payload", "external_payload", "external_storage", "reference_id", "sha256", "size_bytes", "arguments"] {
        assert!(command.get(field).is_none(), "{field}: {command}");
    }
    assert!(!command.to_string().contains("ep_"), "no reference identity: {command}");
    command
}

// `WorkflowController::oversizedPayloadPreview` over each command's stored
// envelope: only a stored external reference larger than the payload limit
// (2 MiB by default) omits the preview, and then the target name too. Native
// keeps a signal's stored reference in its signal record and a start's in the
// command row.
#[tokio::test]
async fn described_commands_omit_only_an_oversized_stored_reference() {
    let temp = tempfile::tempdir().unwrap();
    let (database, runtime, app) = setup(ExternalPayloadConfig::default(), &temp.path().join("objects")).await;
    waiting_run(&app, "described-reference", json!({"worker_id":"describe-worker","task_queue":"described-reference",
        "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":[]})).await;
    let reference = upload(&app, &argument("signal")).await;
    let (status, body) = json_request(&app, "POST", "/api/workflows/described-reference/signal/s", "operator-token",
        json!({"input":{"codec":"avro","external_payload":reference}})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    let command = described(&app, "described-reference", "signal").await;
    assert_eq!((command["payload_preview_omitted"].clone(), command["target_name"].clone()), (json!(false), json!("s")), "{command}");
    let signal = command["id"].as_str().unwrap().to_owned();
    // The real stored reference is in the signal record, not the command row.
    let identity = reference["reference_id"].as_str().unwrap();
    assert_eq!(count(&database, sqlx::AssertSqlSafe(format!(
        "SELECT COUNT(*) FROM workflow_signal_records WHERE workflow_command_id='{signal}' AND arguments LIKE 'dw-native-external-payload-v1:%{identity}%'"))).await, 1);
    assert_eq!(count(&database, sqlx::AssertSqlSafe(format!(
        "SELECT COUNT(*) FROM workflow_commands WHERE id='{signal}' AND payload LIKE 'dw-native%'"))).await, 0);

    // The same stored reference, as native writes it, at sizes around the
    // limit, and counterfactuals that are not native stored references.
    let limit = 2 * 1024 * 1024_i64;
    let stored = |size: i64| {
        let mut reference = reference.clone();
        reference["size_bytes"] = json!(size);
        format!("dw-native-external-payload-v1:{reference}")
    };
    let cases = [
        (stored(limit), false),
        (stored(limit + 1), true),
        // Not native's exact prefix, so never read.
        (stored(limit + 1).replace("dw-native-external-payload-v1:", "DW-NATIVE-EXTERNAL-PAYLOAD-V1:"), false),
        (stored(limit + 1).replace("dw-native-external-payload-v1:", "dw-native-external-payload-v2:"), false),
        // Native's prefix with no valid reference after it.
        (format!("dw-native-external-payload-v1:{{\"size_bytes\":{}}}", limit + 1), false),
        // A large inline payload.
        (format!("dw-{}", "A".repeat(limit as usize + 1)), false),
    ];
    for (arguments, omitted) in &cases {
        database.execute(sqlx::AssertSqlSafe(format!(
            "UPDATE workflow_signal_records SET arguments='{arguments}' WHERE workflow_command_id='{signal}'"))).await;
        let command = described(&app, "described-reference", "signal").await;
        assert_eq!(command["payload_preview_omitted"], json!(omitted), "{}: {command}", &arguments[..arguments.len().min(48)]);
        assert_eq!(command.get("target_name").is_none(), *omitted, "{command}");
    }
    // An oversized reference in the signal's command row is not its input.
    database.execute(sqlx::AssertSqlSafe(format!(
        "UPDATE workflow_signal_records SET arguments='{}' WHERE workflow_command_id='{signal}'", stored(limit)))).await;
    database.execute(sqlx::AssertSqlSafe(format!(
        "UPDATE workflow_commands SET payload='{}' WHERE id='{signal}'", stored(limit + 1)))).await;
    let command = described(&app, "described-reference", "signal").await;
    assert_eq!((command["payload_preview_omitted"].clone(), command["target_name"].clone()), (json!(false), json!("s")), "{command}");

    // A start keeps its stored input reference in the command row.
    let start = described(&app, "described-reference", "start").await["id"].as_str().unwrap().to_owned();
    for (payload, omitted) in &cases {
        database.execute(sqlx::AssertSqlSafe(format!(
            "UPDATE workflow_commands SET payload='{payload}' WHERE id='{start}'"))).await;
        let command = described(&app, "described-reference", "start").await;
        assert_eq!(command["payload_preview_omitted"], json!(omitted), "start {}: {command}", &payload[..payload.len().min(48)]);
    }
    runtime.close().await;
    database.remove().await;
}
