use super::*;

// Bridge adapter webhooks (Server #776, #777): one effect and one receipt per
// bridge command, even under contention, and a command identity that keeps
// apart the same raw key from another adapter or for another target.

async fn bridge(app: &Router, adapter: &str, body: Value) -> (StatusCode, Value) {
    request(app, "POST", &format!("/api/bridge-adapters/webhook/{adapter}"), body).await
}

fn events(history: &Value, event_type: &str) -> usize {
    history.as_array().unwrap().iter().filter(|event| event["event_type"] == event_type).count()
}

fn signal_event(key: &str, workflow_id: &str, name: &str) -> Value {
    json!({"action":"signal_workflow","idempotency_key":key,"target":{"workflow_id":workflow_id,"signal_name":name},"input":[1]})
}

fn update_event(key: &str, workflow_id: &str, name: &str) -> Value {
    json!({"action":"update_workflow","idempotency_key":key,"target":{"workflow_id":workflow_id,"update_name":name},"input":[1]})
}

/// A worker whose workflow declares the updates `set` and `add`.
async fn two_update_worker(app: &Router) {
    let parameters = json!([{"name":"value","type":"int","required":true,"variadic":false,"default_available":false,"allows_null":false}]);
    let (status, body) = request(app, "POST", "/api/worker/register", json!({
        "worker_id":"bridge-updates","task_queue":"test","runtime":"php","supported_workflow_types":["echo"],
        "supported_activity_types":[],"capabilities":["workflow_updates"],"workflow_command_contracts":{"echo":{
            "signals":["finish"],"signal_contracts":[{"name":"finish","parameters":parameters}],
            "updates":["set","add"],"update_validators":[],
            "update_contracts":[{"name":"set","parameters":parameters},{"name":"add","parameters":parameters}]
        }}})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

/// The same event sent eight times at once, through two pools.
async fn contend(apps: &[Router; 2], adapter: &'static str, event: Value) -> Vec<(StatusCode, Value)> {
    let mut tasks = tokio::task::JoinSet::new();
    for index in 0..8 {
        let app = apps[index % 2].clone();
        let event = event.clone();
        tasks.spawn(async move { bridge(&app, adapter, event).await });
    }
    let mut answers = Vec::new();
    while let Some(answer) = tasks.join_next().await {
        answers.push(answer.unwrap());
    }
    answers
}

/// One acceptance and seven duplicates, all naming the same run.
fn one_receipt(answers: &[(StatusCode, Value)], run_id: &Value) {
    let accepted = answers.iter().filter(|(status, answer)| *status == StatusCode::ACCEPTED && answer["outcome"] == "accepted").count();
    let duplicates: Vec<_> = answers.iter().filter(|(status, answer)| *status == StatusCode::OK && answer["outcome"] == "duplicate").collect();
    assert_eq!((accepted, duplicates.len()), (1, 7), "{answers:?}");
    for (_, duplicate) in duplicates {
        assert_eq!(duplicate["control_plane_outcome"], "deduped_existing_command", "{duplicate}");
        assert_eq!(&duplicate["run_id"], run_id, "{duplicate}");
    }
}

#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
async fn concurrent_identical_bridge_signals_apply_once() {
    let database = TestDatabase::new().await;
    let apps = [router(database.open().await.unwrap()), router(database.open().await.unwrap())];
    register(&apps[0], "worker", json!(["echo"]), json!([])).await;
    let started = start(&apps[0], "bridge-signal-race").await;
    let answers = contend(&apps, "acme", signal_event("evt-1", "bridge-signal-race", "note")).await;
    one_receipt(&answers, &started["run_id"]);
    let history = run_history(&apps[0], &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(events(&history, "SignalReceived"), 1, "{history}");
}

#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
async fn concurrent_identical_bridge_updates_apply_once() {
    let database = TestDatabase::new().await;
    let apps = [router(database.open().await.unwrap()), router(database.open().await.unwrap())];
    two_update_worker(&apps[0]).await;
    let started = start(&apps[0], "bridge-update-race").await;
    open_update_wait(&apps[0], "bridge-updates").await;
    let answers = contend(&apps, "acme", update_event("evt-1", "bridge-update-race", "set")).await;
    one_receipt(&answers, &started["run_id"]);
    let history = run_history(&apps[0], &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(events(&history, "UpdateAccepted"), 1, "{history}");
}

// The same raw key is another command from another adapter or for another
// target name: each has its own effect and receipt.
#[tokio::test]
async fn a_raw_key_is_scoped_by_adapter_and_target_name() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!([])).await;
    let signalled = start(&app, "bridge-signal-scope").await;
    for (adapter, name) in [("acme", "note"), ("other", "note"), ("acme", "memo")] {
        let (status, answer) = bridge(&app, adapter, signal_event("shared-key", "bridge-signal-scope", name)).await;
        assert_eq!((status, &answer["outcome"]), (StatusCode::ACCEPTED, &json!("accepted")), "{adapter} {name}: {answer}");
    }
    let (status, again) = bridge(&app, "other", signal_event("shared-key", "bridge-signal-scope", "note")).await;
    assert_eq!((status, &again["outcome"]), (StatusCode::OK, &json!("duplicate")), "{again}");
    let history = run_history(&app, &signalled["workflow_id"], &signalled["run_id"]).await;
    assert_eq!(events(&history, "SignalReceived"), 3, "{history}");

    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    two_update_worker(&app).await;
    let updated = start(&app, "bridge-update-scope").await;
    open_update_wait(&app, "bridge-updates").await;
    // With the raw key as the request id, the second adapter's update would
    // reuse the first one's receipt, and the second name would be refused as
    // a retargeted request.
    for (adapter, name) in [("acme", "set"), ("other", "set"), ("acme", "add")] {
        let (status, answer) = bridge(&app, adapter, update_event("shared-key", "bridge-update-scope", name)).await;
        assert_eq!((status, &answer["outcome"]), (StatusCode::ACCEPTED, &json!("accepted")), "{adapter} {name}: {answer}");
    }
    let (status, again) = bridge(&app, "acme", update_event("shared-key", "bridge-update-scope", "add")).await;
    assert_eq!((status, &again["outcome"]), (StatusCode::OK, &json!("duplicate")), "{again}");
    let history = run_history(&app, &updated["workflow_id"], &updated["run_id"]).await;
    assert_eq!(events(&history, "UpdateAccepted"), 3, "{history}");
    // The raw key stays in the command metadata; the request id is the
    // fixed-length bridge identity.
    let accepted: Vec<_> = history.as_array().unwrap().iter().filter(|event| event["event_type"] == "UpdateAccepted").collect();
    for event in accepted {
        let request_id = event["payload"]["request_id"].as_str().unwrap_or_default();
        assert!(request_id.starts_with("dw-bridge:v1:") && request_id.len() == 77, "{event}");
    }
}

// A derived workflow id is `bridge-<adapter>-<32 hex>`, so the adapter is at
// most 64 bytes and the id fits 128. A longer adapter persists nothing.
#[tokio::test]
async fn the_adapter_name_is_bounded_before_anything_is_persisted() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let start_event = json!({"action":"start_workflow","idempotency_key":"evt-long","target":{"workflow_type":"echo","task_queue":"test"}});
    let longest = "a".repeat(64);
    let (status, accepted) = bridge(&app, &longest, start_event.clone()).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{accepted}");
    assert!(accepted["workflow_id"].as_str().unwrap().len() <= 128, "{accepted}");
    let too_long = "a".repeat(65);
    let (status, refused) = bridge(&app, &too_long, start_event).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{refused}");
    assert_eq!(refused["reason"], "unsupported_routing", "{refused}");
    let (status, list) = request(&app, "GET", "/api/workflows", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{list}");
    let ids: Vec<_> = list["workflows"].as_array().unwrap().iter().map(|workflow| workflow["workflow_id"].clone()).collect();
    assert_eq!(ids, vec![accepted["workflow_id"].clone()], "{list}");
}

// A caller cannot send a request id in the bridge identities' namespace, so
// no regular signal or update can claim a bridge command's identity first.
#[tokio::test]
async fn regular_routes_refuse_the_bridge_request_id_namespace() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    two_update_worker(&app).await;
    let started = start(&app, "bridge-preclaim").await;
    open_update_wait(&app, "bridge-updates").await;
    let run = started["run_id"].as_str().unwrap();
    for (path, body) in [
        ("/api/workflows/bridge-preclaim/update/set".to_owned(), json!({"input":[1],"request_id":"dw-bridge:v1:0000"})),
        (format!("/api/workflows/bridge-preclaim/runs/{run}/update/set"), json!({"input":[1],"request_id":"DW-BRIDGE:v1:0000"})),
        ("/api/workflows/bridge-preclaim/signal/finish".to_owned(), json!({"input":[1],"request_id":"dw-bridge:v1:0000"})),
        (format!("/api/workflows/bridge-preclaim/runs/{run}/signal/finish"), json!({"input":[1],"request_id":"dw-bridge:"})),
    ] {
        let (status, refused) = request(&app, "POST", &path, body).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{path}: {refused}");
        assert_eq!(refused["errors"]["request_id"], json!(["The request id field format is invalid."]), "{path}: {refused}");
    }
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!((events(&history, "UpdateAccepted"), events(&history, "SignalReceived")), (0, 0), "{history}");
    // The bridge's own update is admitted under its identity.
    let (status, answer) = bridge(&app, "acme", update_event("evt-1", "bridge-preclaim", "set")).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{answer}");
}
