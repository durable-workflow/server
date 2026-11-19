//! Server #1134 (.github#223): workflow code signals another workflow with
//! `signal_external_workflow`. The completion records the request, delivers
//! the signal on the target as an HTTP signal is admitted, records the
//! outcome at the same sequence and gives the sender one turn to read it.
use super::*;

fn signal_external(target: &str, name: &str, value: i64) -> Value {
    json!({"type":"signal_external_workflow","workflow_id":target,"signal_name":name,
        "arguments":envelope(Payload::Array(vec![Payload::Long(value)]))})
}

fn events<'a>(history: &'a Value, kind: &str) -> Vec<&'a Value> {
    history.as_array().unwrap().iter().filter(|event| event["event_type"] == kind).collect()
}

fn types(history: &Value) -> Vec<String> {
    history.as_array().unwrap().iter().map(|event| event["event_type"].as_str().unwrap().to_owned()).collect()
}

async fn count(database: &TestDatabase, sql: String) -> i64 {
    task_retry::rows(database, Box::leak(sql.into_boxed_str())).await
}

async fn workflow_tasks(database: &TestDatabase, run: &Value) -> i64 {
    let run = run.as_str().unwrap();
    count(database, format!("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id='{run}' AND task_type='workflow'")).await
}

async fn signal_records(database: &TestDatabase, run: &Value) -> i64 {
    let run = run.as_str().unwrap();
    count(database, format!("SELECT COUNT(*) FROM workflow_signal_records WHERE workflow_run_id='{run}'")).await
}

/// Starts `workflow` and completes its first task with `commands`.
async fn started_with(app: &Router, workflow: &str, commands: Value) -> Value {
    let started = start(app, workflow).await;
    let task = poll(app, "worker", "workflow").await;
    assert_eq!(task["workflow_id"], workflow, "{task}");
    let (status, body) = finish_task(app, &task, commands).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    started
}

/// A target waiting for `finish`, the signal the worker's contract declares.
async fn waiting_target(app: &Router, workflow: &str) -> Value {
    started_with(app, workflow, json!([{"type":"open_signal_wait","signal_name":"finish"}])).await
}

/// The sender's first task, completed with `commands`: its task and answer.
async fn send(app: &Router, workflow: &str, commands: Value) -> (Value, Value, Value) {
    let started = start(app, workflow).await;
    let task = poll(app, "worker", "workflow").await;
    assert_eq!(task["workflow_id"], workflow, "{task}");
    let (status, body) = finish_task(app, &task, commands).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    (started, task, body)
}

/// Polls the ready workflow tasks and returns the one for `workflow`; the
/// others stay leased to the test worker.
async fn turn_for(app: &Router, workflow: &str) -> Value {
    for _ in 0..4 {
        let task = poll(app, "worker", "workflow").await;
        assert!(!task.is_null(), "no turn for {workflow}");
        if task["workflow_id"] == workflow {
            return task;
        }
    }
    panic!("no turn for {workflow}");
}

#[tokio::test]
async fn a_signal_reaches_another_workflow_once_and_the_sender_reads_the_outcome() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let capabilities = update_worker(&app, "worker").await;
    assert_eq!(capabilities["signal_external_workflow"], true, "{capabilities}");
    assert!(capabilities["supported_workflow_task_commands"].as_array().unwrap().contains(&json!("signal_external_workflow")));
    let target = waiting_target(&app, "receiver").await;
    let (sender, first, body) = send(&app, "sender", json!([signal_external("receiver", "finish", 7)])).await;
    assert_eq!(body["run_status"], "waiting", "{body}");
    // The target's message task and the sender's turn.
    assert_eq!(body["created_task_ids"].as_array().unwrap().len(), 1, "the sender's turn: {body}");
    assert_eq!(workflow_tasks(&database, &sender["run_id"]).await, 2);

    // Both ready tasks: the sender's turn and the target's message task.
    let mut ready = vec![poll(&app, "worker", "workflow").await, poll(&app, "worker", "workflow").await];
    ready.sort_by_key(|task| task["workflow_id"].as_str().unwrap_or_default().to_owned());
    let [target_turn, turn] = <[Value; 2]>::try_from(ready).unwrap();
    assert_eq!((target_turn["workflow_id"].clone(), turn["workflow_id"].clone()), (json!("receiver"), json!("sender")));
    assert!(poll(&app, "worker", "workflow").await.is_null());
    assert_ne!(turn["task_id"], first["task_id"]);
    assert_eq!((turn["workflow_wait_kind"].clone(), turn["workflow_sequence"].clone()),
        (json!("external_signal"), json!(1)), "{turn}");
    let history = &turn["history_events"];
    assert_eq!(types(history), ["StartAccepted", "WorkflowStarted", "ExternalSignalRequested", "ExternalSignalDelivered"], "{history}");
    let key = format!("workflow-signal:{}:1", sender["run_id"].as_str().unwrap());
    let requested = &events(history, "ExternalSignalRequested")[0]["payload"];
    assert_eq!((requested["sequence"].clone(), requested["target_workflow_instance_id"].clone(), requested["target_workflow_run_id"].clone(),
        requested["signal_name"].clone(), requested["request_id"].clone(), requested["payload_codec"].clone()),
        (json!(1), json!("receiver"), Value::Null, json!("finish"), json!(key), json!("avro")), "{requested}");
    assert_eq!(requested["arguments"], envelope(Payload::Array(vec![Payload::Long(7)])), "{requested}");
    assert_eq!(requested["task"]["id"], first["task_id"], "{requested}");
    let delivered = &events(history, "ExternalSignalDelivered")[0]["payload"];
    assert_eq!((delivered["sequence"].clone(), delivered["outcome"].clone(), delivered["target_workflow_run_id"].clone(),
        delivered["retained"].clone()), (json!(1), json!("delivered"), target["run_id"].clone(), json!(false)), "{delivered}");

    // The target received an ordinary signal, attributed to the sender.
    let target_history = run_history(&app, &json!("receiver"), &target["run_id"]).await;
    let received = events(&target_history, "SignalReceived");
    assert_eq!(received.len(), 1, "{target_history}");
    let received = &received[0]["payload"];
    assert_eq!((received["workflow_command_id"].clone(), received["signal_id"].clone(), received["signal_name"].clone()),
        (delivered["target_command_id"].clone(), delivered["target_signal_id"].clone(), json!("finish")), "{received}");
    assert_eq!(received["arguments"], requested["arguments"]);
    let command = &received["command"];
    assert_eq!((command["source"].clone(), command["caller_label"].clone(), command["status"].clone()),
        (json!("workflow"), json!("Workflow"), json!("accepted")), "{command}");
    assert_eq!(command["context"]["workflow"], json!({"parent_instance_id":"sender","parent_run_id":sender["run_id"],"sequence":1}),
        "{command}");
    let target_run = target["run_id"].as_str().unwrap();
    let token = count(&database, format!("SELECT COUNT(*) FROM workflow_commands WHERE workflow_run_id='{target_run}' AND request_id LIKE 'signal-v1:%'")).await;
    assert_eq!(token, 1, "the delivery is keyed on the target");
    assert_eq!(count(&database, format!("SELECT COUNT(*) FROM dw_command_intents WHERE command_id='{}'",
        delivered["target_command_id"].as_str().unwrap())).await, 1);

    // A duplicate of the sender's completion sends nothing again.
    let (status, duplicate) = finish_task(&app, &first, json!([signal_external("receiver", "finish", 7)])).await;
    assert_eq!((status, duplicate["recorded"].clone(), duplicate["reason"].clone()),
        (StatusCode::OK, json!(false), json!("already_completed")), "{duplicate}");
    assert_eq!(signal_records(&database, &target["run_id"]).await, 1);
    assert_eq!(workflow_tasks(&database, &sender["run_id"]).await, 2);

    // The target applies it; the sender completes after reading the outcome.
    assert_eq!(events(&target_turn["history_events"], "SignalReceived").len(), 1, "{target_turn}");
    let (status, body) = finish_task(&app, &target_turn, json!([{"type":"complete_workflow","result":envelope(Payload::Long(7))}])).await;
    assert_eq!((status, body["run_status"].clone()), (StatusCode::OK, json!("completed")), "{body}");
    let (status, body) = finish_task(&app, &turn, json!([{"type":"complete_workflow","result":envelope(Payload::Long(1))}])).await;
    assert_eq!((status, body["run_status"].clone()), (StatusCode::OK, json!("completed")), "{body}");
    let before = run_history(&app, &sender["workflow_id"], &sender["run_id"]).await;
    runtime.close().await;

    // A fresh runtime reads the same sender history and sends nothing.
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    assert_eq!(run_history(&app, &sender["workflow_id"], &sender["run_id"]).await, before);
    assert_eq!(signal_records(&database, &target["run_id"]).await, 1);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn missing_closed_and_refused_targets_are_recorded_outcomes() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "worker").await;
    let closed = started_with(&app, "closed-target", json!([{"type":"complete_workflow","result":envelope(Payload::Long(1))}])).await;
    let continued = started_with(&app, "continued-target", json!([{"type":"continue_as_new"}])).await;
    let next = poll(&app, "worker", "workflow").await;
    assert_eq!(next["workflow_id"], "continued-target", "{next}");
    assert_ne!(next["run_id"], continued["run_id"]);
    let (status, body) = finish_task(&app, &next, json!([{"type":"open_signal_wait","signal_name":"finish"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let open = waiting_target(&app, "open-target").await;

    let mut historical = signal_external("continued-target", "finish", 1);
    historical["run_id"] = continued["run_id"].clone();
    let mut unknown_run = signal_external("open-target", "finish", 1);
    unknown_run["run_id"] = json!("no-such-run");
    let commands = json!([signal_external("nobody", "finish", 1), unknown_run, signal_external("closed-target", "finish", 1),
        historical, signal_external("open-target", "undeclared", 1), signal_external("refusals", "finish", 1)]);
    let (sender, _, body) = send(&app, "refusals", commands).await;
    assert_eq!(body["created_task_ids"].as_array().unwrap().len(), 1, "one turn for the batch: {body}");
    let turn = turn_for(&app, "refusals").await;
    let failed = events(&turn["history_events"], "ExternalSignalFailed");
    let outcomes: Vec<(Value, Value, Value)> = failed.iter()
        .map(|event| (event["payload"]["sequence"].clone(), event["payload"]["outcome"].clone(), event["payload"]["reason"].clone()))
        .collect();
    assert_eq!(outcomes, [
        (json!(1), json!("target_not_found"), json!("instance_not_found")),
        (json!(2), json!("target_not_found"), json!("run_not_found")),
        (json!(3), json!("target_closed"), json!("run_not_active")),
        (json!(4), json!("target_closed"), json!("historical_run")),
        (json!(5), json!("rejected"), json!("unknown_signal")),
        (json!(6), json!("rejected"), json!("self_target")),
    ], "{turn}");
    assert_eq!(events(&turn["history_events"], "ExternalSignalRequested").len(), 6);
    assert!(events(&turn["history_events"], "ExternalSignalDelivered").is_empty());
    for event in &failed {
        assert!(event["payload"]["message"].as_str().is_some_and(|message| !message.is_empty()), "{event}");
    }
    assert_eq!(failed[3]["payload"]["target_workflow_run_id"], continued["run_id"]);
    // The closed and the refused targets record a rejected signal command, as
    // for an HTTP signal; nothing else was written on any target.
    assert_eq!(failed[2]["payload"]["target_workflow_run_id"], closed["run_id"]);
    assert!(failed[2]["payload"]["target_command_id"].is_string(), "{}", failed[2]);
    assert!(failed[4]["payload"]["target_command_id"].is_string(), "{}", failed[4]);
    assert!(failed[0]["payload"]["target_command_id"].is_null());
    let open_history = run_history(&app, &json!("open-target"), &open["run_id"]).await;
    assert!(events(&open_history, "SignalReceived").is_empty(), "{open_history}");
    let closed_history = run_history(&app, &json!("closed-target"), &closed["run_id"]).await;
    assert!(events(&closed_history, "SignalReceived").is_empty(), "{closed_history}");
    assert_eq!(signal_records(&database, &closed["run_id"]).await, 1, "the rejected admission record");
    assert_eq!(signal_records(&database, &open["run_id"]).await, 1, "the rejected admission record");
    assert_eq!(signal_records(&database, &sender["run_id"]).await, 0);
    runtime.close().await;
    database.remove().await;
}

// A delivery's command key is reserved: no HTTP signal or signal-with-start
// can take it, so none can occupy or answer for a workflow's delivery.
#[tokio::test]
async fn http_callers_cannot_take_the_delivery_key_space() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "worker").await;
    let target = waiting_target(&app, "keyed-target").await;
    let input = envelope(Payload::Array(vec![Payload::Long(7)]));
    let (status, body) = request(&app, "POST", "/api/workflows/keyed-target/signal/finish",
        json!({"input":input,"request_id":"workflow-signal:01RUN:1"})).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::UNPROCESSABLE_ENTITY, json!("request_id_reserved")), "{body}");
    let (status, body) = request(&app, "POST", "/api/workflows/keyed-intake/signal-with-start/finish",
        json!({"workflow_type":"echo","task_queue":"test","input":input,"signal_input":input,"request_id":"workflow-signal:01RUN:1"})).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::UNPROCESSABLE_ENTITY, json!("request_id_reserved")), "{body}");
    assert_eq!(signal_records(&database, &target["run_id"]).await, 0, "nothing was written");
    assert_eq!(count(&database, "SELECT COUNT(*) FROM workflow_instances WHERE id='keyed-intake'".into()).await, 0);
    // Other keys, including ones merely containing the prefix, still work.
    let (status, body) = request(&app, "POST", "/api/workflows/keyed-target/signal/finish",
        json!({"input":input,"request_id":"my-workflow-signal:1"})).await;
    assert!(status.is_success(), "{body}");
    runtime.close().await;
    database.remove().await;
}

// Two workflows signal each other in their own completions: each delivery
// is one ordinary signal on the other, and both senders read delivered.
#[tokio::test]
async fn two_workflows_signal_each_other() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "worker").await;
    let alpha = start(&app, "alpha").await;
    let beta = start(&app, "beta").await;
    let mut first = vec![poll(&app, "worker", "workflow").await, poll(&app, "worker", "workflow").await];
    first.sort_by_key(|task| task["workflow_id"].as_str().unwrap_or_default().to_owned());
    for (task, other) in first.iter().zip(["beta", "alpha"]) {
        let (status, body) = finish_task(&app, task,
            json!([signal_external(other, "finish", 1), {"type":"open_signal_wait","signal_name":"finish"}])).await;
        assert_eq!(status, StatusCode::OK, "{body}");
    }
    for (workflow, started) in [("alpha", &alpha), ("beta", &beta)] {
        let history = run_history(&app, &json!(workflow), &started["run_id"]).await;
        let delivered = events(&history, "ExternalSignalDelivered");
        assert_eq!(delivered.len(), 1, "{workflow}: {history}");
        assert_eq!(events(&history, "SignalReceived").len(), 1, "{workflow}: {history}");
        assert_eq!(events(&history, "SignalReceived")[0]["payload"]["command"]["source"], "workflow");
        assert_eq!(signal_records(&database, &started["run_id"]).await, 1);
    }
    runtime.close().await;
    database.remove().await;
}

// A later command the completion refuses rolls the whole completion back,
// including an earlier command's delivery on its target.
#[tokio::test]
async fn a_refused_later_command_rolls_back_an_earlier_delivery() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "worker").await;
    let target = waiting_target(&app, "rollback-target").await;
    let sender = start(&app, "rollback-sender").await;
    let task = poll(&app, "worker", "workflow").await;
    // Arguments that decode as one Avro value but not as a list are refused
    // only when the second command applies.
    let mut scalar = signal_external("rollback-target", "finish", 2);
    scalar["arguments"] = envelope(Payload::Long(2));
    let (status, body) = finish_task(&app, &task, json!([signal_external("rollback-target", "finish", 1), scalar])).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    assert_eq!(signal_records(&database, &target["run_id"]).await, 0, "the first delivery rolled back");
    let history = run_history(&app, &sender["workflow_id"], &sender["run_id"]).await;
    assert!(events(&history, "ExternalSignalRequested").is_empty(), "{history}");
    assert!(events(&run_history(&app, &json!("rollback-target"), &target["run_id"]).await, "SignalReceived").is_empty());
    // The same task then completes and delivers once.
    let (status, body) = finish_task(&app, &task, json!([signal_external("rollback-target", "finish", 1)])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(signal_records(&database, &target["run_id"]).await, 1);
    runtime.close().await;
    database.remove().await;
}

// A signal name follows an HTTP signal's name rules: a control character is
// refused before anything is written.
#[tokio::test]
async fn a_control_character_name_is_refused_before_anything_is_written() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "worker").await;
    let target = waiting_target(&app, "named-target").await;
    let sender = start(&app, "named-sender").await;
    let task = poll(&app, "worker", "workflow").await;
    for name in ["fin\u{7}ish", "fin\nish", "fin\u{7f}ish"] {
        let (status, body) = finish_task(&app, &task, json!([signal_external("named-target", name, 1)])).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{name:?}: {body}");
    }
    assert_eq!(signal_records(&database, &target["run_id"]).await, 0);
    let history = run_history(&app, &sender["workflow_id"], &sender["run_id"]).await;
    assert!(events(&history, "ExternalSignalRequested").is_empty(), "{history}");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn the_command_is_validated_and_a_closing_completion_takes_no_turn() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "worker").await;
    let target = waiting_target(&app, "late-target").await;
    let sender = start(&app, "closing-sender").await;
    let task = poll(&app, "worker", "workflow").await;
    let mut extra = signal_external("late-target", "finish", 1);
    extra["queue"] = json!("q");
    let mut unnamed = signal_external("late-target", "finish", 1);
    unnamed["signal_name"] = json!(" ");
    let mut untargeted = signal_external("late-target", "finish", 1);
    untargeted["workflow_id"] = json!("");
    let mut uncoded = signal_external("late-target", "finish", 1);
    uncoded["arguments"] = json!({"codec":"json","blob":"[1]"});
    for command in [extra, unnamed, untargeted, uncoded] {
        let (status, body) = finish_task(&app, &task, json!([command])).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{command}: {body}");
    }
    assert_eq!(signal_records(&database, &target["run_id"]).await, 0, "nothing was delivered");
    // The signal and the close commit together; the closed sender gets no turn.
    let (status, body) = finish_task(&app, &task,
        json!([signal_external("late-target", "finish", 1), {"type":"complete_workflow","result":envelope(Payload::Long(1))}])).await;
    assert_eq!((status, body["run_status"].clone()), (StatusCode::OK, json!("completed")), "{body}");
    assert_eq!(workflow_tasks(&database, &sender["run_id"]).await, 1, "no turn for a closed sender");
    let history = run_history(&app, &sender["workflow_id"], &sender["run_id"]).await;
    assert_eq!(types(&history), ["StartAccepted", "WorkflowStarted", "ExternalSignalRequested", "ExternalSignalDelivered", "WorkflowCompleted"]);
    assert_eq!(signal_records(&database, &target["run_id"]).await, 1);
    let (status, info) = request(&app, "GET", "/api/cluster/info", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{info}");
    assert_eq!(info["capabilities"]["signal_external_workflow"], true, "{info}");
    runtime.close().await;
    database.remove().await;
}

// Each signal-external command consumes one authored sequence, so the next
// completion's commands follow it, whatever their kind.
#[tokio::test]
async fn later_commands_follow_the_signal_sequence() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "worker").await;
    let (sender, _, _) = send(&app, "sequenced", json!([signal_external("nobody", "finish", 1)])).await;
    let turn = turn_for(&app, "sequenced").await;
    let (status, body) = finish_task(&app, &turn, json!([signal_external("nobody", "finish", 2), {"type":"start_timer","delay_seconds":3600}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let turn = turn_for(&app, "sequenced").await;
    let (status, body) = finish_task(&app, &turn,
        json!([{"type":"record_side_effect","result":envelope(Payload::Long(5))}, signal_external("nobody", "finish", 3)])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &sender["workflow_id"], &sender["run_id"]).await;
    let sequences: Vec<(String, Value)> = history.as_array().unwrap().iter()
        .filter(|event| ["ExternalSignalRequested", "TimerScheduled", "SideEffectRecorded"].contains(&event["event_type"].as_str().unwrap()))
        .map(|event| (event["event_type"].as_str().unwrap().to_owned(), event["payload"]["sequence"].clone())).collect();
    assert_eq!(sequences, [("ExternalSignalRequested".to_owned(), json!(1)), ("ExternalSignalRequested".to_owned(), json!(2)),
        ("TimerScheduled".to_owned(), json!(3)), ("SideEffectRecorded".to_owned(), json!(4)),
        ("ExternalSignalRequested".to_owned(), json!(5))], "{history}");
    runtime.close().await;
    database.remove().await;
}
