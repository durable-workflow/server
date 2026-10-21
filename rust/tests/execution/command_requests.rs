use super::*;

// The CLI's command shapes: plain JSON input, and `[]` for a command without
// fields. Published PHP reads `[]` as empty input and ignores fields outside
// its rules.
#[tokio::test]
async fn signals_take_plain_input_and_empty_list_bodies() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!([])).await;
    let started = start(&app, "commands").await;
    let (status, signal) = request(&app, "POST", "/api/workflows/commands/signal/note",
        json!({"input":[1,"two"],"request_id":" note-1 ","unknown":true})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{signal}");
    let (status, empty) = request(&app, "POST", "/api/workflows/commands/signal/note", json!([])).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{empty}");
    let (status, refused) = request(&app, "POST", "/api/workflows/commands/signal/note", json!({"input":"x"})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{refused}");
    assert_eq!(refused["errors"]["input"], json!(["The input field must be an array."]));
    let (status, refused) = request(&app, "POST", "/api/workflows/commands/signal/note", json!({"dry_run":true})).await;
    assert_eq!(status, StatusCode::NOT_IMPLEMENTED, "{refused}");
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let received: Vec<_> = history.as_array().unwrap().iter().filter(|event| event["event_type"] == "SignalReceived").collect();
    assert_eq!(received.len(), 2, "{history}");
    // The CLI's repair sends `[]`; it reaches the repair rules instead of
    // being refused as a malformed body.
    let (_, repaired) = request(&app, "POST", "/api/workflows/commands/repair", json!([])).await;
    assert_ne!(repaired["reason"], "invalid_request", "{repaired}");
}

// Without `wait_for`, PHP returns an update once it is accepted.
#[tokio::test]
async fn updates_take_plain_input_and_return_at_acceptance() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    update_worker(&app, "update-worker").await;
    start(&app, "updates").await;
    open_update_wait(&app, "update-worker").await;
    let (status, update) = request(&app, "POST", "/api/workflows/updates/update/set", json!({"input":[5],"unknown":1})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{update}");
    assert_eq!(update["wait_for"], "accepted");
    assert_eq!(update["update_status"], "accepted");
    let (status, refused) = request(&app, "POST", "/api/workflows/updates/update/set",
        json!({"input":[5],"wait_policy":"accepted"})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{refused}");
    assert_eq!(refused["errors"]["wait_policy"], json!(["The wait_policy field is no longer supported. Use wait_for."]));
}
