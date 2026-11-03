//! Applying a signal stored by reference (Server #979) on every path that
//! applies one: completion, scope checkpoint and waiting acknowledgement.
//! Nothing is read or written for a request without the task's live claim,
//! a retried request reuses its value object, storage faults refuse without
//! applying, and a run 3.0.1 left waiting recovers through repair.
use super::*;
use sqlx::AssertSqlSafe;

const QUEUE: &str = "signal-apply";
const WORKER: &str = "signal-apply-worker";

fn argument(value: &str) -> String {
    durable_workflow_server::codec::ValueCodec::new().unwrap().encode(&Payload::Array(vec![Payload::String(value.into())])).unwrap()
}

async fn upload(app: &Router, blob: &str) -> Value {
    let (status, _, body) = request(app, "POST", "/api/external-payloads/v1", "worker-token", "default",
        Some(&metadata(blob.as_bytes())), Body::from(blob.to_owned())).await;
    assert_eq!(status, StatusCode::CREATED, "{}", String::from_utf8_lossy(&body));
    serde_json::from_slice::<Value>(&body).unwrap()["reference"].clone()
}

async fn rows(database: &TestDatabase) -> i64 {
    count(database, "SELECT COUNT(*) FROM runtime_external_payloads").await
}

async fn poll_task(app: &Router) -> Value {
    let (status, polled) = json_request(app, "POST", "/api/worker/workflow-tasks/poll", "worker-token",
        json!({"worker_id":WORKER,"task_queue":QUEUE,"timeout_seconds":0})).await;
    assert_eq!(status, StatusCode::OK, "{polled}");
    polled["task"].clone()
}

async fn complete(app: &Router, task: &Value, owner: &Value, attempt: &Value, commands: Value) -> (StatusCode, Value) {
    json_request(app, "POST", &format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap()), "worker-token",
        json!({"lease_owner":owner,"workflow_task_attempt":attempt,"commands":commands})).await
}

fn done() -> Value {
    json!([{"type":"complete_workflow","result":{"codec":"avro","blob":argument("done")}}])
}

async fn register(app: &Router) {
    let (status, body) = json_request(app, "POST", "/api/worker/register", "worker-token", json!({"worker_id":WORKER,
        "task_queue":QUEUE,"runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":[],
        "capabilities":["cooperative_cancellation"]})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

/// A run waiting on `approve`, signalled with arguments stored by
/// reference, and the claimed task that resumes it: (start, task, signal
/// reference).
async fn signal_task(app: &Router, workflow: &str, fill: &str) -> (Value, Value, Value) {
    let (status, started) = json_request(app, "POST", "/api/workflows", "operator-token",
        json!({"workflow_id":workflow,"workflow_type":"echo","task_queue":QUEUE,"input":[]})).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    let first = poll_task(app).await;
    let (status, body) = complete(app, &first, &first["lease_owner"], &first["workflow_task_attempt"],
        json!([{"type":"open_signal_wait","signal_name":"approve"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let signal = upload(app, &argument(&fill.repeat(70_000))).await;
    let (status, body) = json_request(app, "POST", &format!("/api/workflows/{workflow}/signal/approve"), "operator-token",
        json!({"input":{"codec":"avro","external_payload":signal}})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    let task = poll_task(app).await;
    assert_eq!(task["run_id"], started["run_id"], "{task}");
    (started, task, signal)
}

async fn history(app: &Router, workflow: &str, run: &Value) -> Value {
    let (status, body) = json_request(app, "GET", &format!("/api/workflows/{workflow}/runs/{}/history", run.as_str().unwrap()),
        "operator-token", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body
}

async fn applied(app: &Router, workflow: &str, run: &Value) -> Vec<Value> {
    history(app, workflow, run).await["events"].as_array().unwrap().iter()
        .filter(|event| event["event_type"] == "SignalApplied").cloned().collect()
}

/// The value the signal applies, by reference: one `approve` argument.
fn applied_by_reference(applied: &[Value], fill: &str) {
    assert_eq!(applied.len(), 1, "{applied:?}");
    let value = durable_workflow_server::codec::ValueCodec::new().unwrap().encode(&Payload::String(fill.repeat(70_000))).unwrap();
    let reference = &applied[0]["payload"]["value"]["external_payload"];
    assert_eq!((reference["sha256"].as_str(), reference["size_bytes"].as_i64()),
        (Some(digest(value.as_bytes()).as_str()), Some(value.len() as i64)), "{applied:?}");
}

#[tokio::test]
async fn requests_without_the_claim_write_nothing_and_a_retry_reuses_its_value() {
    let temp = tempfile::tempdir().unwrap();
    let (database, runtime, app) = setup(ExternalPayloadConfig::default(), &temp.path().join("objects")).await;
    register(&app).await;
    let (started, task, _) = signal_task(&app, "claim-first", "c").await;
    let before = rows(&database).await;

    // A wrong owner or attempt, and a failure the store refuses, read and
    // write nothing.
    let attempt = task["workflow_task_attempt"].clone();
    for (owner, attempt) in [(json!("someone-else"), attempt.clone()), (task["lease_owner"].clone(), json!(attempt.as_i64().unwrap() + 1))] {
        let (status, body) = complete(&app, &task, &owner, &attempt, done()).await;
        assert_ne!(status, StatusCode::OK, "{body}");
    }
    let path = format!("/api/worker/workflow-tasks/{}/fail", task["task_id"].as_str().unwrap());
    // Every field the store checks: a failure it refuses writes nothing,
    // even with the live claim and a waiting message.
    let waiting = |extra: Value| {
        let mut failure = json!({"type":"WorkflowTaskWaitingForHistory","message":"Workflow task waiting for scheduled history."});
        for (key, value) in extra.as_object().unwrap() {
            failure[key] = value.clone();
        }
        failure
    };
    for failure in [json!({"type":"WorkflowTaskWaitingForHistory"}), json!({"type":"WorkflowTaskWaitingForHistory","message":" "}),
        waiting(json!({"stack_trace":7})), waiting(json!({"reason":"r".repeat(192)})), waiting(json!({"sequence":0})),
        waiting(json!({"sequence":"1"})), json!({"type":["WorkflowTaskWaitingForHistory"],"message":"Workflow task waiting for scheduled history."})] {
        let (status, body) = json_request(&app, "POST", &path, "worker-token",
            json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],"failure":failure})).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    }
    let (status, body) = json_request(&app, "POST", &path, "worker-token", json!({"lease_owner":"someone-else",
        "workflow_task_attempt":task["workflow_task_attempt"],"failure":{"type":"WorkflowTaskWaitingForHistory","message":"waiting"}})).await;
    assert_ne!(status, StatusCode::OK, "{body}");
    assert_eq!(rows(&database).await, before, "no object for a request without the claim");

    // A request refused after its signal was resolved leaves one unclaimed
    // value object; the corrected retry reuses it.
    // Two terminal commands: only the store's transaction refuses them.
    let (status, body) = complete(&app, &task, &task["lease_owner"], &task["workflow_task_attempt"],
        json!([{"type":"complete_workflow","result":{"codec":"avro","blob":argument("a")}},
            {"type":"complete_workflow","result":{"codec":"avro","blob":argument("b")}}])).await;
    assert_ne!(status, StatusCode::OK, "{body}");
    assert!(applied(&app, "claim-first", &started["run_id"]).await.is_empty());
    assert_eq!((rows(&database).await, unclaimed(&database).await), (before + 1, 1));
    let (status, body) = complete(&app, &task, &task["lease_owner"], &task["workflow_task_attempt"], done()).await;
    assert_eq!((status, body["run_status"].clone()), (StatusCode::OK, json!("completed")), "{body}");
    assert_eq!((rows(&database).await, unclaimed(&database).await), (before + 1, 0));
    applied_by_reference(&applied(&app, "claim-first", &started["run_id"]).await, "c");
    // The identical request again is the recorded receipt, with no new object.
    let (status, duplicate) = complete(&app, &task, &task["lease_owner"], &task["workflow_task_attempt"], done()).await;
    assert_eq!((status, duplicate["recorded"].clone()), (StatusCode::OK, json!(false)), "{duplicate}");
    assert_eq!(rows(&database).await, before + 1);

    // An expired claim writes nothing either.
    let (_, expired, _) = signal_task(&app, "claim-expired", "e").await;
    let before = rows(&database).await;
    database.execute(AssertSqlSafe(format!("UPDATE workflow_tasks SET lease_expires_at='2000-01-01 00:00:00' WHERE id='{}'",
        expired["task_id"].as_str().unwrap()))).await;
    let (status, body) = complete(&app, &expired, &expired["lease_owner"], &expired["workflow_task_attempt"], done()).await;
    assert_ne!(status, StatusCode::OK, "{body}");
    assert_eq!(rows(&database).await, before);
    runtime.close().await;
}

#[tokio::test]
async fn the_waiting_acknowledgement_and_a_checkpoint_apply_a_signal_stored_by_reference() {
    let temp = tempfile::tempdir().unwrap();
    let (database, runtime, app) = setup(ExternalPayloadConfig::default(), &temp.path().join("objects")).await;
    register(&app).await;

    let (started, task, _) = signal_task(&app, "acknowledged", "a").await;
    let (status, body) = json_request(&app, "POST", &format!("/api/worker/workflow-tasks/{}/fail", task["task_id"].as_str().unwrap()),
        "worker-token", json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],
            "failure":{"type":"WorkflowTaskWaitingForHistory","message":"Workflow task waiting for scheduled history."}})).await;
    assert_eq!((status, body["outcome"].clone()), (StatusCode::OK, json!("waiting_for_history")), "{body}");
    applied_by_reference(&applied(&app, "acknowledged", &started["run_id"]).await, "a");

    let (started, task, _) = signal_task(&app, "checkpointed", "k").await;
    let (status, body) = json_request(&app, "POST",
        &format!("/api/worker/workflow-tasks/{}/cancellation-scopes/checkpoint", task["task_id"].as_str().unwrap()), "worker-token",
        json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],"checkpoint_id":"apply",
            // After the signal wait, the next authored sequence.
            "start_sequence":2,
            "commands":[{"type":"record_side_effect","result":{"codec":"avro","blob":argument("x")}}]})).await;
    assert_eq!((status, body["checkpointed"].clone()), (StatusCode::OK, json!(true)), "{body}");
    applied_by_reference(&applied(&app, "checkpointed", &started["run_id"]).await, "k");
    assert_eq!(unclaimed(&database).await, 0);
    runtime.close().await;
}

/// The local file a registered reference names.
async fn object(database: &TestDatabase, reference: &Value) -> std::path::PathBuf {
    let sql = format!("SELECT storage_uri, storage_uri FROM runtime_external_payloads WHERE id='{}'", reference["reference_id"].as_str().unwrap());
    let uris = text_rows(database, &sql, &sql, &sql).await;
    std::path::PathBuf::from(uris[0].0.strip_prefix("file://").unwrap())
}

#[tokio::test]
async fn storage_faults_refuse_without_applying_the_signal() {
    let temp = tempfile::tempdir().unwrap();
    let (database, runtime, app) = setup(ExternalPayloadConfig::default(), &temp.path().join("objects")).await;
    register(&app).await;
    for (index, fault) in ["missing", "corrupt", "tampered", "foreign"].into_iter().enumerate() {
        let workflow = format!("fault-{fault}");
        let (started, task, signal) = signal_task(&app, &workflow, &index.to_string()).await;
        let id = signal["reference_id"].as_str().unwrap();
        match fault {
            "missing" => std::fs::remove_file(object(&database, &signal).await).unwrap(),
            "corrupt" => std::fs::write(object(&database, &signal).await, b"not the bytes").unwrap(),
            "tampered" => database.execute(AssertSqlSafe(format!(
                "UPDATE runtime_external_payloads SET sha256='{}' WHERE id='{id}'", "0".repeat(64)))).await,
            _ => database.execute(AssertSqlSafe(format!("UPDATE runtime_external_payloads SET namespace='other' WHERE id='{id}'"))).await,
        }
        let before = rows(&database).await;
        let (status, body) = complete(&app, &task, &task["lease_owner"], &task["workflow_task_attempt"], done()).await;
        assert_ne!(status, StatusCode::OK, "{fault}: {body}");
        assert!(applied(&app, &workflow, &started["run_id"]).await.is_empty(), "{fault}: nothing applied");
        assert_eq!(rows(&database).await, before, "{fault}: no object written");
        assert_eq!(count(&database, AssertSqlSafe(format!(
            "SELECT COUNT(*) FROM workflow_signal_records WHERE workflow_run_id='{}' AND status='received'",
            started["run_id"].as_str().unwrap()))).await, 1, "{fault}: the signal stays received");
    }
    runtime.close().await;
}

/// 3.0.1 refused the completion of such a signal task and left the run
/// waiting. A repair gives it a new task, which now applies the signal it
/// already admitted, without admitting it again.
#[tokio::test]
async fn a_run_left_waiting_by_3_0_1_applies_its_signal_after_repair() {
    let temp = tempfile::tempdir().unwrap();
    let (database, runtime, app) = setup(ExternalPayloadConfig::default(), &temp.path().join("objects")).await;
    register(&app).await;
    let (started, task, _) = signal_task(&app, "stranded", "r").await;
    // The state 3.0.1 left: the signal task failed, the signal received.
    database.execute(AssertSqlSafe(format!("UPDATE workflow_tasks SET status='failed',lease_expires_at=NULL WHERE id='{}'",
        task["task_id"].as_str().unwrap()))).await;
    assert!(poll_task(&app).await.is_null());
    let (status, repaired) = json_request(&app, "POST", "/api/workflows/stranded/repair", "operator-token", json!({})).await;
    assert!(status.is_success(), "{repaired}");
    let task = poll_task(&app).await;
    assert_eq!(task["run_id"], started["run_id"], "repair gives the run a task: {repaired}");
    let (status, body) = complete(&app, &task, &task["lease_owner"], &task["workflow_task_attempt"], done()).await;
    assert_eq!((status, body["run_status"].clone()), (StatusCode::OK, json!("completed")), "{body}");
    applied_by_reference(&applied(&app, "stranded", &started["run_id"]).await, "r");
    let events = history(&app, "stranded", &started["run_id"]).await;
    let received = events["events"].as_array().unwrap().iter().filter(|event| event["event_type"] == "SignalReceived").count();
    assert_eq!(received, 1, "admitted once");
    runtime.close().await;
}
