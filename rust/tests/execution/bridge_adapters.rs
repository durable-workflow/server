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

// Security holds on 867a2d53 (#776): a command already stored under a bridge
// identity, accepted or rejected, answers the bridge only when it records the
// same adapter, action, raw key and target. Before the prefix reservation a
// caller could choose the predictable identity, so these rows are seeded by
// rewriting a regular command's request id.

fn identity(adapter: &str, action: &str, key: &str, field: &str, name: &str) -> String {
    use sha2::{Digest, Sha256};
    let mut digest = Sha256::new();
    for part in [adapter, action, key, field, name] {
        digest.update((part.len() as u64).to_be_bytes());
        digest.update(part.as_bytes());
    }
    let hex: String = digest.finalize().iter().map(|byte| format!("{byte:02x}")).collect();
    format!("dw-bridge:v1:{hex}")
}

/// Gives the regular command `filter` selects a bridge identity as its
/// request id, optionally recording it as rejected.
async fn preclaim(database: &TestDatabase, filter: &str, identity: &str, rejected: bool) {
    let status = if rejected { ",status='rejected'" } else { "" };
    database.execute(sqlx::AssertSqlSafe(format!(
        "UPDATE workflow_commands SET request_id='{identity}'{status} WHERE {filter}"))).await;
}

async fn count(database: &TestDatabase, sql: String) -> i64 {
    task_retry::rows(database, Box::leak(sql.into_boxed_str())).await
}

/// The bridge's 409 for an identity recorded for another command: its own
/// envelope with the caller's fields only, nothing stored and no error text.
fn identity_conflict(answer: &Value, action: &str, key: &str, target: Value) {
    assert_eq!(answer, &json!({"schema":"durable-workflow.v2.bridge-adapter-outcome.contract","version":1,
        "adapter":"acme","action":action,"accepted":false,"outcome":"rejected","reason":"bridge_identity_conflict",
        "idempotency_key":key,"message":"The bridge command's identity is recorded for another command.","target":target}));
}

#[tokio::test]
async fn a_rejected_update_under_the_identity_is_not_replayed_for_another_tuple() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    two_update_worker(&app).await;
    let started = start(&app, "bridge-rejected-claim").await;
    let (status, terminated) = request(&app, "POST", "/api/workflows/bridge-rejected-claim/terminate", json!({})).await;
    assert!(status.is_success(), "{terminated}");
    // A regular update to the closed run is rejected durably under its
    // request id, which then takes the bridge command's identity.
    let (status, rejected) = request(&app, "POST", "/api/workflows/bridge-rejected-claim/update/set",
        json!({"input":[1],"request_id":"seed-rejected"})).await;
    assert!(status.is_client_error(), "{rejected}");
    let claimed = identity("acme", "update_workflow", "evt-r", "update_name", "set");
    preclaim(&database, "request_id='seed-rejected'", &claimed, false).await;
    let updates = "SELECT COUNT(*) FROM workflow_updates WHERE workflow_instance_id='bridge-rejected-claim'".to_owned();
    let commands = "SELECT COUNT(*) FROM workflow_commands WHERE workflow_instance_id='bridge-rejected-claim'".to_owned();
    let before = (count(&database, updates.clone()).await, count(&database, commands.clone()).await);
    assert_eq!(count(&database, format!("SELECT COUNT(*) FROM workflow_commands WHERE request_id='{claimed}' AND status='rejected'")).await, 1);

    let (status, answer) = bridge(&app, "acme", update_event("evt-r", "bridge-rejected-claim", "set")).await;
    assert_eq!(status, StatusCode::CONFLICT, "{answer}");
    identity_conflict(&answer, "update_workflow", "evt-r", json!({"workflow_id":"bridge-rejected-claim","update_name":"set"}));
    assert_eq!((count(&database, updates).await, count(&database, commands).await), before);
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(events(&history, "UpdateAccepted"), 0, "{history}");
}

#[tokio::test]
async fn a_signal_under_the_identity_answers_only_its_own_tuple() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!([])).await;
    let started = start(&app, "bridge-signal-claim").await;
    // An accepted regular signal, and one recorded as rejected, each under
    // a bridge command's identity. Native keeps a signal's request id in its
    // context, so the stored command, as PHP could have left it, is
    // selected as the instance's one signal without a request id.
    for (key, rejected) in [("evt-a", false), ("evt-b", true)] {
        let (status, body) = request(&app, "POST", "/api/workflows/bridge-signal-claim/signal/note", json!({"input":[1]})).await;
        assert_eq!(status, StatusCode::ACCEPTED, "{body}");
        preclaim(&database, "workflow_instance_id='bridge-signal-claim' AND command_type='signal' AND request_id IS NULL",
            &identity("acme", "signal_workflow", key, "signal_name", "note"), rejected).await;
    }
    let commands = "SELECT COUNT(*) FROM workflow_commands WHERE workflow_instance_id='bridge-signal-claim'".to_owned();
    let before = count(&database, commands.clone()).await;
    for key in ["evt-a", "evt-b"] {
        let (status, answer) = bridge(&app, "acme", signal_event(key, "bridge-signal-claim", "note")).await;
        assert_eq!(status, StatusCode::CONFLICT, "{key}: {answer}");
        identity_conflict(&answer, "signal_workflow", key, json!({"workflow_id":"bridge-signal-claim","signal_name":"note"}));
    }
    assert_eq!(count(&database, commands).await, before);
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(events(&history, "SignalReceived"), 2, "{history}");
    // The same tuple's own identity is unaffected: a fresh key is accepted.
    let (status, answer) = bridge(&app, "acme", signal_event("evt-c", "bridge-signal-claim", "note")).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{answer}");
}

// A bridge start is decided under the transition lock before anything is
// written: a draining queue, then an instance another namespace holds, then
// one reserved for another type. No read outside the lock decides it, so a
// state change before the start cannot turn into another answer.

fn start_event(key: &str, target: Value) -> Value {
    json!({"action":"start_workflow","idempotency_key":key,"target":target})
}

async fn register_on(app: &Router, worker: &str, queue: &str, build: &str) {
    let (status, body) = request(app, "POST", "/api/worker/register", json!({"worker_id":worker,"task_queue":queue,
        "runtime":"php","build_id":build,"supported_workflow_types":["echo"],"supported_activity_types":[]})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

#[tokio::test]
async fn a_bridge_start_refuses_under_the_transition_lock_with_its_own_envelope() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    namespaces::create(&app, "other").await;
    namespaces::start_scoped(&app, "other", "bridge-foreign").await;
    let (status, body) = request(&app, "POST", "/api/workflows", json!({"workflow_id":"bridge-typed","workflow_type":"other.type",
        "task_queue":"test","input":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    let commands = "SELECT COUNT(*) FROM workflow_commands".to_owned();
    let instances = "SELECT COUNT(*) FROM workflow_instances".to_owned();
    let before = (count(&database, commands.clone()).await, count(&database, instances.clone()).await);

    let target = json!({"workflow_id":"bridge-foreign","workflow_type":"echo","task_queue":"test"});
    let (status, answer) = bridge(&app, "acme", start_event("evt-foreign", target.clone())).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{answer}");
    assert_eq!(answer, json!({"schema":"durable-workflow.v2.bridge-adapter-outcome.contract","version":1,"adapter":"acme",
        "action":"start_workflow","accepted":false,"outcome":"rejected","reason":"unknown_target","idempotency_key":"evt-foreign",
        "message":"Workflow instance [bridge-foreign] is unavailable.","target":target}));

    let target = json!({"workflow_id":"bridge-typed","workflow_type":"echo","task_queue":"test"});
    let (status, answer) = bridge(&app, "acme", start_event("evt-typed", target.clone())).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{answer}");
    assert_eq!(answer, json!({"schema":"durable-workflow.v2.bridge-adapter-outcome.contract","version":1,"adapter":"acme",
        "action":"start_workflow","accepted":false,"outcome":"rejected","reason":"unknown_target","idempotency_key":"evt-typed",
        "message":"Workflow instance [bridge-typed] is reserved for durable type [other.type] and cannot be reused for [echo].","target":target}));

    register_on(&app, "drain-worker", "drain-q", "d1").await;
    let (status, body) = request(&app, "POST", "/api/task-queues/drain-q/build-ids/drain", json!({"build_id":"d1"})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (status, answer) = bridge(&app, "acme", start_event("evt-drain", json!({"workflow_type":"echo","task_queue":"drain-q"}))).await;
    assert_eq!((status, &answer["reason"], &answer["routing_status"]), (StatusCode::UNPROCESSABLE_ENTITY, &json!("task_queue_draining"), &json!("draining")), "{answer}");
    assert_eq!((&answer["control_plane_outcome"], &answer["draining_worker_count"]), (&json!("rejected_task_queue_draining"), &json!(1)), "{answer}");
    assert_eq!(answer["message"], "Task queue [drain-q] is draining and cannot accept new workflow starts until an active worker cohort is available.");
    assert_eq!((count(&database, commands).await, count(&database, instances).await), before);
}

// A controlled interleaving with the real transition lock (Security,
// server-325 2219). The test holds the lock; a bridge start queues on it,
// then a second test-held lock, then a competitor: a start of the same id in
// another namespace, a start of it for another type, or a drain of the
// bridge's queue. Released, the bridge is granted first; the second hold
// then keeps the competitor out until a read the bridge made outside the
// lock has finished, so a start decided outside the lock (867a2d53) meets
// the competitor's state at its write. Decided in one serialized
// transaction, the bridge has started its own instance before the
// competitor runs. PostgreSQL's advisory lock and InnoDB's row lock grant
// waiters in order; SQLite's busy retry has no queue, so there the order is
// not forced and the bridge may meet either winner, but never with a raw
// answer or another type's instance.
#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
async fn a_foreign_start_queued_behind_a_bridge_start_cannot_change_its_answer() {
    change_queued_behind_a_bridge_start("namespace").await;
}

#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
async fn another_types_start_queued_behind_a_bridge_start_cannot_change_its_answer() {
    change_queued_behind_a_bridge_start("type").await;
}

#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
async fn a_drain_queued_behind_a_bridge_start_cannot_change_its_answer() {
    change_queued_behind_a_bridge_start("drain").await;
}

async fn change_queued_behind_a_bridge_start(case: &'static str) {
    use std::time::Duration;
    let pause = || tokio::time::sleep(Duration::from_millis(200));
    {
        let database = TestDatabase::new().await;
        let ordered = !matches!(database, TestDatabase::Sqlite(_));
        let app = router(database.open().await.unwrap());
        namespaces::create(&app, "other").await;
        register_on(&app, "queued-worker", "queued-q", "q1").await;
        let workflow_id = format!("bridge-queued-{case}");
        let queue = if case == "drain" { "queued-q" } else { "test" };
        let target = json!({"workflow_id":workflow_id,"workflow_type":"echo","task_queue":queue});

        let first = database.transition_lock().await;
        let bridged = {
            let (app, event) = (app.clone(), start_event(&format!("evt-queued-{case}"), target.clone()));
            tokio::spawn(async move { bridge(&app, "acme", event).await })
        };
        pause().await;
        assert!(!bridged.is_finished(), "{case}: the bridge start waits for the transition lock");
        let second = tokio::spawn(database.transition_lock());
        pause().await;
        assert!(!second.is_finished(), "{case}: the second hold queues behind the bridge");
        let competitor = {
            let (app, workflow_id) = (app.clone(), workflow_id.clone());
            tokio::spawn(async move {
                let input = envelope(Payload::Array(vec![]));
                match case {
                    "namespace" => namespaces::scoped(&app, "other", "POST", "/api/workflows",
                        json!({"workflow_id":workflow_id,"workflow_type":"echo","task_queue":"test","input":input})).await,
                    "type" => request(&app, "POST", "/api/workflows",
                        json!({"workflow_id":workflow_id,"workflow_type":"other.type","task_queue":"test","input":input})).await,
                    _ => request(&app, "POST", "/api/task-queues/queued-q/build-ids/drain", json!({"build_id":"q1"})).await,
                }
            })
        };
        pause().await;
        assert!(!competitor.is_finished(), "{case}: the competitor queues behind the second hold");
        first.release().await;
        let second = second.await.unwrap();
        tokio::time::sleep(Duration::from_millis(300)).await;
        second.release().await;
        let (status, answer) = bridged.await.unwrap();
        let (competitor_status, competitor_answer) = competitor.await.unwrap();

        let started = status == StatusCode::ACCEPTED;
        if started {
            assert_eq!((&answer["outcome"], &answer["workflow_id"], &answer["workflow_type"]),
                (&json!("accepted"), &json!(workflow_id), &json!("echo")), "{case}: {answer}");
            let (status, shown) = request(&app, "GET", &format!("/api/workflows/{workflow_id}"), Value::Null).await;
            assert_eq!((status, &shown["workflow_type"]), (StatusCode::OK, &json!("echo")), "{case}: {shown}");
            match case {
                "namespace" => assert_eq!((competitor_status, &competitor_answer["reason"]),
                    (StatusCode::CONFLICT, &json!("workflow_id_reserved_in_namespace")), "{case}: {competitor_answer}"),
                "type" => assert_eq!((competitor_status, &competitor_answer["reason"]),
                    (StatusCode::CONFLICT, &json!("instance_already_started")), "{case}: {competitor_answer}"),
                _ => assert_eq!(competitor_status, StatusCode::OK, "{case}: {competitor_answer}"),
            }
        } else {
            // Only where waiters are not granted in order may the competitor
            // win, and then the bridge answers that state in its envelope.
            assert!(!ordered, "{case}: the bridge was granted first but answered {status} {answer}");
            let message = match case {
                "namespace" => format!("Workflow instance [{workflow_id}] is unavailable."),
                "type" => format!("Workflow instance [{workflow_id}] is reserved for durable type [other.type] and cannot be reused for [echo]."),
                _ => "Task queue [queued-q] is draining and cannot accept new workflow starts until an active worker cohort is available.".to_owned(),
            };
            assert_eq!((status, &answer["schema"], &answer["outcome"], &answer["message"]),
                (StatusCode::UNPROCESSABLE_ENTITY, &json!("durable-workflow.v2.bridge-adapter-outcome.contract"), &json!("rejected"), &json!(message)),
                "{case}: {answer}");
        }
        eprintln!("queued {case}: bridge {status}, competitor {competitor_status}");
    }
}
