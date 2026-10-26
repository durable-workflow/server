use super::*;
use durable_workflow_server::runtime::StructuralLimits;

/// Counts rows with a query built for this test.
async fn count(database: &TestDatabase, sql: String) -> i64 {
    task_retry::rows(database, Box::leak(sql.into_boxed_str())).await
}

async fn limited(database: &TestDatabase, signals: usize, updates: usize, payload: usize) -> (Runtime, Router) {
    let limits = StructuralLimits {
        pending_signals: Some(signals),
        pending_updates: Some(updates),
        payload_bytes: Some(payload),
        ..StructuralLimits::default()
    };
    let runtime = database.open().await.unwrap().with_structural_limits(limits);
    (runtime.clone(), router(runtime))
}

/// A worker whose `note` signal and update take one string.
async fn note_worker(app: &Router) {
    let note = json!([{"name":"note","parameters":[{"name":"value","type":"string","required":true,"variadic":false,"default_available":false,"allows_null":false}]}]);
    let (status, body) = request(app, "POST", "/api/worker/register", json!({
        "worker_id":"worker","task_queue":"test","runtime":"php","supported_workflow_types":["echo"],
        "supported_activity_types":[],"capabilities":["workflow_updates"],"workflow_command_contracts":{"echo":{
            "signals":["note"],"signal_contracts":note,"updates":["note"],"update_validators":[],"update_contracts":note
        }}})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

fn note(value: &str) -> Value {
    arguments(vec![Payload::String(value.into())])
}

fn arguments(values: Vec<Payload>) -> Value {
    envelope(Payload::Array(values))
}

/// A string argument whose serialized blob exceeds a 300-byte ceiling.
fn oversized() -> Value {
    arguments(vec![Payload::String("x".repeat(400))])
}

fn events_of(history: &Value, kind: &str) -> Vec<Value> {
    history.as_array().unwrap().iter().filter(|event| event["event_type"] == kind).cloned().collect()
}

// Published PHP 2.11.1, `DW_V2_LIMIT_PENDING_SIGNALS=2` and
// `DW_V2_LIMIT_PAYLOAD_SIZE_BYTES=300`: a signal past either ceiling answers
// 409 `structural_limit_exceeded` with no outcome and records a rejected
// command and signal, but no history. An oversized signal keeps no arguments.
#[tokio::test]
async fn signals_over_a_structural_limit_are_rejected_durably_like_php() {
    let database = TestDatabase::new().await;
    let (runtime, app) = limited(&database, 2, 500, 300).await;
    note_worker(&app).await;
    let started = start(&app, "limited-signals").await;
    let path = "/api/workflows/limited-signals/signal/note";

    let (status, big) = request(&app, "POST", path, json!({"input":oversized()})).await;
    assert_eq!(status, StatusCode::CONFLICT, "{big}");
    for (field, expected) in [
        ("outcome", Value::Null),
        ("command_status", json!("rejected")),
        ("reason", json!("structural_limit_exceeded")),
        ("rejection_reason", json!("structural_limit_exceeded")),
        ("validation_errors", json!([])),
        ("accepted", json!(false)),
    ] {
        assert_eq!(big[field], expected, "{field}: {big}");
    }
    let empty = arguments(vec![])["blob"].as_str().unwrap().to_owned();
    let command = big["command_id"].as_str().unwrap();
    assert_eq!(
        count(&database, format!("SELECT COUNT(*) FROM workflow_signal_records WHERE workflow_command_id='{command}' AND status='rejected' AND outcome IS NULL AND rejection_reason='structural_limit_exceeded' AND arguments='{empty}'")).await,
        1
    );
    assert_eq!(
        count(&database, format!("SELECT COUNT(*) FROM workflow_commands WHERE id='{command}' AND status='rejected' AND outcome IS NULL")).await,
        1
    );

    for value in ["a", "b"] {
        let (status, accepted) = request(&app, "POST", path, json!({"input":note(value)})).await;
        assert!(status.is_success(), "{accepted}");
        assert_eq!(accepted["outcome"], "signal_received", "{accepted}");
    }
    let (status, pending) = request(&app, "POST", path, json!({"input":note("c")})).await;
    assert_eq!(status, StatusCode::CONFLICT, "{pending}");
    assert_eq!((pending["reason"].clone(), pending["outcome"].clone()), (json!("structural_limit_exceeded"), Value::Null), "{pending}");
    assert_eq!(pending["validation_errors"], json!([]), "{pending}");
    let command = pending["command_id"].as_str().unwrap();
    // A count refusal keeps the signal's arguments.
    let three = note("c")["blob"].as_str().unwrap().to_owned();
    assert_eq!(
        count(&database, format!("SELECT COUNT(*) FROM workflow_signal_records WHERE workflow_command_id='{command}' AND status='rejected' AND arguments='{three}'")).await,
        1
    );

    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(events_of(&history, "SignalReceived").len(), 2, "{history}");
    assert!(events_of(&history, "SignalRejected").is_empty(), "{history}");
    runtime.close().await;
    database.remove().await;
}

// The same reference with `DW_V2_LIMIT_PENDING_UPDATES=1`: an oversized
// update and one past the accepted-update ceiling are rejected durably, with
// the limit's message, no arguments, and the limit fields in the answer.
#[tokio::test]
async fn updates_over_a_structural_limit_are_rejected_durably_like_php() {
    let database = TestDatabase::new().await;
    let (runtime, app) = limited(&database, 5000, 1, 300).await;
    note_worker(&app).await;
    let started = start(&app, "limited-updates").await;
    let task = poll(&app, "worker", "workflow").await;
    let timer = json!([{"type":"start_timer","delay_seconds":3600}]);
    assert_eq!(finish_task(&app, &task, timer).await.0, StatusCode::OK);
    let path = "/api/workflows/limited-updates/update/note";

    let blob = oversized()["blob"].as_str().unwrap().len();
    let (status, big) = request(&app, "POST", path, json!({"input":oversized(),"request_id":"big"})).await;
    assert_eq!(status, StatusCode::CONFLICT, "{big}");
    let message = format!("Structural limit exceeded: payload size {blob} bytes (limit 300 bytes).");
    for (field, expected) in [
        ("outcome", Value::Null),
        ("command_status", json!("rejected")),
        ("update_status", json!("rejected")),
        ("reason", json!("structural_limit_exceeded")),
        ("validation_errors", json!({"arguments":[message]})),
        ("structural_limit_kind", json!("payload_size")),
        ("structural_limit_value", json!(blob)),
        ("structural_limit_configured", json!(300)),
        ("accepted", json!(false)),
    ] {
        assert_eq!(big[field], expected, "{field}: {big}");
    }
    // Like PHP, the answer names the requester and when it was rejected.
    assert_eq!(big["principal"]["type"], "auth:token", "{big}");
    assert!(big["principal"]["id"].is_string(), "{big}");
    assert_eq!(big["rejected_at"], big["closed_at"], "{big}");
    // A retried request returns the original rejection with its limit.
    let (status, retried) = request(&app, "POST", path, json!({"input":oversized(),"request_id":"big"})).await;
    assert_eq!(status, StatusCode::CONFLICT, "{retried}");
    assert_eq!(retried, big);

    let (status, accepted) = request(&app, "POST", path, json!({"input":note("a"),"wait_for":"accepted"})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{accepted}");
    let (status, pending) = request(&app, "POST", path, json!({"input":note("b")})).await;
    assert_eq!(status, StatusCode::CONFLICT, "{pending}");
    assert_eq!(pending["validation_errors"], json!({"arguments":["Structural limit exceeded: 1 pending updates (limit 1)."]}), "{pending}");
    assert_eq!(
        (pending["structural_limit_kind"].clone(), pending["structural_limit_value"].clone(), pending["structural_limit_configured"].clone()),
        (json!("pending_update_count"), json!(1), json!(1)),
        "{pending}"
    );

    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let rejected = events_of(&history, "UpdateRejected");
    assert_eq!(rejected.len(), 2, "{history}");
    assert_eq!(rejected[0]["payload"]["arguments"], arguments(vec![]), "{history}");
    assert_eq!(rejected[0]["payload"]["validation_errors"], big["validation_errors"]);
    assert_eq!(rejected[1]["payload"]["arguments"], arguments(vec![]), "{history}");
    let accepted = events_of(&history, "UpdateAccepted");
    assert_eq!(accepted.len(), 1, "{history}");
    // PHP records a request id only when the request named one.
    assert!(accepted[0]["payload"].get("request_id").is_none(), "{history}");
    runtime.close().await;
    database.remove().await;
}

// `ActivityOutcomeRecorder` measures the result it would store. Over the
// payload ceiling the activity fails, without a retry, with PHP's
// structural-limit exception, and the worker is told it completed.
#[tokio::test]
async fn an_oversized_activity_result_fails_the_activity_like_php() {
    let database = TestDatabase::new().await;
    let (runtime, app) = limited(&database, 5000, 500, 300).await;
    register(&app, "worker", json!(["echo"]), json!(["act"])).await;
    let started = start(&app, "limited-result").await;
    let task = poll(&app, "worker", "workflow").await;
    let command = json!({"type":"schedule_activity","activity_type":"act","arguments":arguments(vec![]),
        "retry_policy":{"max_attempts":3,"backoff_seconds":[0]}});
    assert_eq!(finish_task(&app, &task, json!([command])).await.0, StatusCode::OK);
    let activity = poll(&app, "worker", "activity").await;
    let (status, answer) = request(&app, "POST",
        &format!("/api/worker/activity-tasks/{}/complete", activity["task_id"].as_str().unwrap()),
        json!({"lease_owner":activity["lease_owner"],"activity_attempt_id":activity["activity_attempt_id"],"result":oversized()})).await;
    assert_eq!(status, StatusCode::OK, "{answer}");
    assert_eq!((answer["outcome"].clone(), answer["recorded"].clone()), (json!("completed"), json!(true)), "{answer}");
    assert!(answer["next_task_id"].is_string(), "{answer}");

    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert!(events_of(&history, "ActivityCompleted").is_empty(), "{history}");
    assert!(events_of(&history, "ActivityRetryScheduled").is_empty(), "{history}");
    let failed = &events_of(&history, "ActivityFailed")[0]["payload"];
    let blob = oversized()["blob"].as_str().unwrap().len();
    let message = format!("Structural limit exceeded: payload size {blob} bytes (limit 300 bytes).");
    for (field, expected) in [
        ("failure_category", json!("structural_limit")),
        ("non_retryable", json!(true)),
        ("exception_type", Value::Null),
        ("exception_class", json!("Workflow\\V2\\Exceptions\\StructuralLimitExceededException")),
        ("message", json!(message)),
        ("code", json!(0)),
        ("exception", json!({"message":message,"code":0})),
        ("structural_limit_kind", json!("payload_size")),
        ("structural_limit_value", json!(blob)),
        ("structural_limit_configured", json!(300)),
    ] {
        assert_eq!(failed[field], expected, "{field}: {failed}");
    }
    let run = started["run_id"].as_str().unwrap();
    assert_eq!(
        count(&database, format!("SELECT COUNT(*) FROM workflow_failures WHERE workflow_run_id='{run}' AND failure_category='structural_limit' AND non_retryable=TRUE")).await,
        1
    );
    assert_eq!(
        count(&database, format!("SELECT COUNT(*) FROM activity_executions WHERE workflow_run_id='{run}' AND status='failed' AND result IS NULL")).await,
        1
    );
    runtime.close().await;
    database.remove().await;
}

/// The history transaction ceilings as the operator metrics, the health
/// snapshot and the nested backend capabilities report them.
async fn published_history_limits(app: &Router) -> Vec<(String, Value, Value, Value)> {
    let (status, metrics) = request(app, "GET", "/api/system/operator-metrics", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{metrics}");
    let (status, health) = request(app, "GET", "/api/system/health", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{health}");
    let metrics = &metrics["operator_metrics"];
    let surfaces = [
        ("metrics configured", &metrics["structural_limits"]["configured"]),
        ("metrics effective", &metrics["structural_limits"]["effective"]),
        ("backend configured", &metrics["backend"]["structural_limits"]["configured"]),
        ("backend effective", &metrics["backend"]["structural_limits"]["effective"]),
        ("health configured", &health["health"]["structural_limits"]["configured"]),
        ("health effective", &health["health"]["structural_limits"]["effective"]),
    ];
    surfaces.into_iter().map(|(surface, limits)| {
        (surface.to_owned(), limits["history_transaction_size"].clone(), limits["history_transaction_bytes"].clone(),
            limits["history_transaction_rows"].clone())
    }).collect()
}

#[tokio::test]
async fn every_surface_reports_the_active_history_transaction_limits() {
    let database = TestDatabase::new().await;
    for (events, bytes, rows, shown) in [
        (Some(4321), Some(987_654), Some(13_579), (json!(4321), json!(987_654), json!(13_579))),
        (Some(0), None, Some(0), (json!(0), json!(0), json!(0))),
    ] {
        let limits = StructuralLimits { history_events: events, history_bytes: bytes, history_rows: rows, ..StructuralLimits::default() };
        let app = router(database.open().await.unwrap().with_structural_limits(limits));
        for (surface, size, published_bytes, published_rows) in published_history_limits(&app).await {
            assert_eq!((&size, &published_bytes, &published_rows), (&shown.0, &shown.1, &shown.2), "{surface}");
        }
    }
    // The defaults, from no configuration.
    let app = router(database.open().await.unwrap());
    for (surface, size, bytes, rows) in published_history_limits(&app).await {
        assert_eq!((size, bytes, rows), (json!(5000), json!(16_777_216), json!(20_000)), "{surface}");
    }
}
