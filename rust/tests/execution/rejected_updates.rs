use super::*;

fn events_of<'a>(history: &'a Value, kind: &str) -> Vec<&'a Value> {
    history.as_array().unwrap().iter().filter(|event| event["event_type"] == kind).collect()
}

async fn update(app: &Router, workflow: &str, name: &str, body: Value) -> (StatusCode, Value) {
    request(app, "POST", &format!("/api/workflows/{workflow}/update/{name}"), body).await
}

fn input(values: Vec<Payload>) -> Value {
    json!({"input":envelope(Payload::Array(values))})
}

// Workflow 2.9.0 `WorkflowStub::rejectUpdateCommand`: an unknown update or
// invalid arguments leave a rejected command, a rejected update and an
// `UpdateRejected` event. The status follows `updateStatus`.
#[tokio::test]
async fn refused_updates_are_recorded_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    update_worker(&app, "update-a").await;
    let started = start(&app, "rejected-update").await;
    open_update_wait(&app, "update-a").await;

    let mut unknown_request = input(vec![]);
    unknown_request["request_id"] = json!("unknown-1");
    let (status, unknown) = update(&app, "rejected-update", "missing", unknown_request.clone()).await;
    assert_eq!(status, StatusCode::NOT_FOUND, "{unknown}");
    for (field, expected) in [
        ("accepted", json!(false)),
        ("command_status", json!("rejected")),
        ("update_status", json!("rejected")),
        ("outcome", json!("rejected_unknown_update")),
        ("reason", json!("unknown_update")),
        ("rejection_reason", json!("unknown_update")),
        ("validation_errors", json!([])),
        ("update_name", json!("missing")),
        ("run_id", started["run_id"].clone()),
        ("accepted_at", Value::Null),
    ] {
        assert_eq!(unknown[field], expected, "{field}: {unknown}");
    }
    assert!(unknown["update_id"].is_string() && unknown["command_id"].is_string(), "{unknown}");
    assert!(unknown["closed_at"].is_string(), "{unknown}");

    // The same request returns its original rejection and records nothing.
    let before = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let (status, retried) = update(&app, "rejected-update", "missing", unknown_request).await;
    assert_eq!(status, StatusCode::NOT_FOUND, "{retried}");
    assert_eq!(retried["update_id"], unknown["update_id"]);
    assert_eq!(run_history(&app, &started["workflow_id"], &started["run_id"]).await, before);

    let cases = [
        (input(vec![Payload::String("wrong".into())]), json!({"value":["The value argument must be of type int."]})),
        (input(vec![Payload::Null]), json!({"value":["The value argument cannot be null."]})),
        (input(vec![]), json!({"value":["The value argument is required."]})),
        (input(vec![Payload::Long(1), Payload::Long(2)]), json!({"arguments":["Too many arguments were provided for update [set]."]})),
    ];
    for (body, errors) in &cases {
        let (status, invalid) = update(&app, "rejected-update", "set", body.clone()).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{invalid}");
        assert_eq!(invalid["outcome"], "rejected_invalid_arguments", "{invalid}");
        assert_eq!(invalid["reason"], "invalid_update_arguments", "{invalid}");
        assert_eq!(&invalid["validation_errors"], errors, "{invalid}");
        assert_eq!(invalid["accepted"], false, "{invalid}");
    }

    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let rejected = events_of(&history, "UpdateRejected");
    assert_eq!(rejected.len(), 1 + cases.len(), "{history}");
    assert_eq!(rejected[0]["payload"]["update_name"], "missing");
    assert_eq!(rejected[0]["payload"]["update_id"], unknown["update_id"]);
    assert_eq!(rejected[0]["payload"]["workflow_command_id"], unknown["command_id"]);
    assert!(rejected[0]["payload"].get("validation_errors").is_none(), "PHP drops empty errors");
    assert_eq!(rejected[1]["payload"]["validation_errors"], cases[0].1);
    assert!(events_of(&history, "UpdateAccepted").is_empty());

    // A rejection opens no work: a valid update is still admitted and
    // delivered to the waiting run.
    let (status, admitted) = update(&app, "rejected-update", "set", input(vec![Payload::Long(5)])).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{admitted}");
    // PHP's result mapper sends `accepted` only when it is false.
    assert!(admitted.get("accepted").is_none(), "{admitted}");
    assert!(admitted["command_sequence"].as_i64().unwrap() > unknown["command_sequence"].as_i64().unwrap());
    let task = poll(&app, "update-a", "workflow").await;
    assert!(task["task_id"].is_string(), "{task}");
}

// An update to a closed run is rejected durably on that run
// (`run_not_active`, 409), as published PHP does.
#[tokio::test]
async fn an_update_to_a_closed_run_is_rejected_on_that_run() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    update_worker(&app, "update-a").await;
    let started = start(&app, "closed-update").await;
    let task = poll(&app, "update-a", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Long(1))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");

    let (status, closed) = update(&app, "closed-update", "set", input(vec![Payload::Long(5)])).await;
    assert_eq!(status, StatusCode::CONFLICT, "{closed}");
    assert_eq!(closed["outcome"], "rejected_not_active", "{closed}");
    assert_eq!(closed["reason"], "run_not_active", "{closed}");
    assert_eq!(closed["update_status"], "rejected", "{closed}");
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let kinds: Vec<_> = history.as_array().unwrap().iter().map(|event| event["event_type"].as_str().unwrap()).collect();
    assert_eq!(kinds.last(), Some(&"UpdateRejected"), "{kinds:?}");
    let (_, described) = request(&app, "GET", "/api/workflows/closed-update", Value::Null).await;
    assert_eq!(described["status"], "completed", "{described}");
}
