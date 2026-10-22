use super::*;

async fn claim(app: &Router) -> Value {
    let response = request_protocol(app, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"wait-worker","task_queue":"test","timeout_seconds":2}), "1.20").await;
    assert_eq!(response.0, StatusCode::OK, "{}", response.1);
    response.1["task"].clone()
}

fn event<'a>(history: &'a Value, kind: &str) -> &'a Value {
    let found = history.as_array().unwrap().iter().find(|event| event["event_type"] == kind);
    found.unwrap_or_else(|| panic!("no {kind} in {history}"))
}

// Published PHP admits a root cancellation request whatever the run waits
// on, and a worker parked in a signal or condition wait delivers it at that
// wait's sequence (CooperativeCancellationDelivery::validateCallBoundary).
// The Sample App's children clean up with a side effect. The delivery and
// the terminal event are recorded with the request's id only, so they carry
// no command snapshot or principal.
#[tokio::test]
async fn a_run_parked_in_a_signal_wait_takes_cancellation_there_and_cleans_up() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let registered = request_protocol(&app, "POST", "/api/worker/register",
        json!({"worker_id":"wait-worker","task_queue":"test","runtime":"php","supported_workflow_types":["echo"],
            "supported_activity_types":[],"capabilities":["cooperative_cancellation"]}), "1.20").await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    let run = start(&app, "parked-wait").await;
    let task = claim(&app).await;
    let parked = finish_task(&app, &task, json!([{"type":"open_signal_wait","signal_name":"finish"}])).await;
    assert_eq!(parked.0, StatusCode::OK, "{}", parked.1);

    let response = request(&app, "POST", "/api/workflows/parked-wait/request-cancellation",
        json!({"reason":"stop parked","cleanup_timeout_seconds":30})).await;
    assert_eq!(response.0, StatusCode::ACCEPTED, "{}", response.1);
    let pending = response.1["cancellation_request"].clone();
    let root = claim(&app).await;
    assert!(root["task_id"].is_string(), "{root}");
    let wrong = super::cooperative_children::deliver(&app, &root, &pending, 1, "condition").await;
    assert_eq!(wrong.0, StatusCode::CONFLICT, "{}", wrong.1);
    assert_eq!(wrong.1["reason"], "cancellation_delivery_shape_mismatch");
    let delivered = super::cooperative_children::deliver(&app, &root, &pending, 1, "signal").await;
    assert_eq!(delivered.0, StatusCode::OK, "{}", delivered.1);
    assert_eq!(delivered.1["delivered"], true, "{}", delivered.1);

    // Published SDKs send the encoded result as a string, as the PHP SDK's
    // `$codec->encode()` does, optionally naming its codec. An envelope is
    // accepted too.
    let blob = envelope(Payload::Long(7))["blob"].clone();
    let cleanup = json!([{"type":"record_side_effect","result":blob,"payload_codec":"avro"},
        {"type":"record_side_effect","result":envelope(Payload::Long(7))},
        {"type":"complete_workflow","result":envelope(Payload::Null)}]);
    let finished = finish_task(&app, &root, cleanup).await;
    assert_eq!(finished.0, StatusCode::OK, "{}", finished.1);
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let delivery = event(&history, "CooperativeCancellationDelivered");
    assert_eq!((delivery["payload"]["call_kind"].clone(), delivery["payload"]["sequence"].clone()), (json!("signal"), json!(1)));
    assert_eq!(delivery["principal"], Value::Null, "{delivery}");
    assert!(delivery["payload"].get("command").is_none(), "{delivery}");
    // History reads present the result as an envelope, as PHP does.
    let side_effects: Vec<_> = history.as_array().unwrap().iter().filter(|event| event["event_type"] == "SideEffectRecorded").collect();
    assert_eq!(side_effects.len(), 2, "{history}");
    for (side_effect, sequence) in side_effects.iter().zip([2, 3]) {
        assert_eq!((side_effect["payload"]["sequence"].clone(), side_effect["payload"]["result"].clone()), (json!(sequence), json!({"codec":"avro","blob":blob})), "{side_effect}");
    }
    let cancelled = event(&history, "WorkflowCancelled");
    assert_eq!(cancelled["principal"], Value::Null, "{cancelled}");
    assert_eq!(cancelled["payload"]["cancellation_cleanup"]["outcome"], "completed", "{cancelled}");
}
