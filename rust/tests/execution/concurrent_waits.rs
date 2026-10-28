//! Several waits open at once (the `concurrent-waits` shared fixture):
//! published PHP lets a run hold any number of signal and condition waits,
//! each resolved by its own signal.
use super::*;

fn kinds(history: &Value) -> Vec<String> {
    history.as_array().unwrap().iter().map(|event| event["event_type"].as_str().unwrap().to_owned()).collect()
}

fn events<'a>(history: &'a Value, kind: &str) -> Vec<&'a Value> {
    history.as_array().unwrap().iter().filter(|event| event["event_type"] == kind).collect()
}

async fn signal(app: &Router, workflow: &str, name: &str) {
    let (status, body) = request(app, "POST", &format!("/api/workflows/{workflow}/signal/{name}"),
        json!({"input":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
}

#[tokio::test]
async fn a_run_holds_several_open_waits_each_resolved_by_its_own_signal() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "waits-worker", json!(["echo"]), json!([])).await;
    let run = start(&app, "several-waits").await;
    let task = poll(&app, "waits-worker", "workflow").await;
    let (status, opened) = finish_task(&app, &task, json!([
        {"type":"open_signal_wait","signal_name":"first"},
        {"type":"open_signal_wait","signal_name":"second","timeout_seconds":600},
        {"type":"open_condition_wait","condition_key":"ready","timeout_seconds":600},
    ])).await;
    assert_eq!(status, StatusCode::OK, "{opened}");
    // Each timed wait's timeout task is reported, as PHP reports it.
    assert_eq!(opened["created_task_ids"].as_array().unwrap().len(), 2, "{opened}");
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let opened_waits: Vec<_> = history.as_array().unwrap().iter()
        .filter(|event| matches!(event["event_type"].as_str(), Some("SignalWaitOpened" | "ConditionWaitOpened")))
        .map(|event| event["payload"]["sequence"].as_i64().unwrap()).collect();
    assert_eq!(opened_waits, [1, 2, 3]);
    let first_wait = events(&history, "SignalWaitOpened")[0]["payload"]["signal_wait_id"].clone();
    let second_wait = events(&history, "SignalWaitOpened")[1]["payload"]["signal_wait_id"].clone();

    // `second` reserves its own wait; the resumed task applies it there,
    // cancels its timeout and satisfies the open condition wait.
    signal(&app, "several-waits", "second").await;
    let resumed = poll(&app, "waits-worker", "workflow").await;
    assert_eq!((resumed["workflow_wait_kind"].clone(), resumed["signal_wait_id"].clone()), (json!("signal"), second_wait.clone()), "{resumed}");
    assert_eq!(resumed["open_wait_id"], json!(format!("signal-application:{}", resumed["workflow_signal_id"].as_str().unwrap())));
    let (status, body) = finish_task(&app, &resumed, json!([{"type":"start_timer","delay_seconds":600}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let applied = events(&history, "SignalApplied");
    assert_eq!((applied.len(), applied[0]["payload"]["sequence"].clone()), (1, json!(2)));
    let satisfied = events(&history, "ConditionWaitSatisfied");
    assert_eq!((satisfied.len(), satisfied[0]["payload"]["sequence"].clone(), satisfied[0]["payload"]["signal_wait_id"].clone()),
        (1, json!(3), second_wait));
    assert_eq!(events(&history, "TimerCancelled").len(), 2, "both timeouts cancelled: {:?}", kinds(&history));

    // `first` then resumes its own wait at its own sequence.
    signal(&app, "several-waits", "first").await;
    let resumed = poll(&app, "waits-worker", "workflow").await;
    assert_eq!(resumed["signal_wait_id"], first_wait, "{resumed}");
    let (status, body) = finish_task(&app, &resumed, json!([{"type":"complete_workflow","result":envelope(Payload::Array(vec![]))}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let applied = events(&history, "SignalApplied");
    assert_eq!(applied.iter().map(|event| event["payload"]["sequence"].as_i64().unwrap()).collect::<Vec<_>>(), [2, 1]);
    assert_eq!(kinds(&history).last().unwrap(), "WorkflowCompleted");
}

#[tokio::test]
async fn a_second_signal_for_one_name_is_buffered_behind_the_first() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "dup-worker", json!(["echo"]), json!([])).await;
    let run = start(&app, "same-name-waits").await;
    let task = poll(&app, "dup-worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([
        {"type":"open_signal_wait","signal_name":"dup"},
        {"type":"open_signal_wait","signal_name":"dup"},
    ])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    signal(&app, "same-name-waits", "dup").await;
    signal(&app, "same-name-waits", "dup").await;
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let later = events(&history, "SignalWaitOpened")[1]["payload"]["signal_wait_id"].clone();
    let received: Vec<_> = events(&history, "SignalReceived").iter().map(|event| event["payload"]["signal_wait_id"].clone()).collect();
    // `signalWaitIdForAcceptedCommand`: the first reserves the later wait;
    // the second waits behind it with a buffered identity.
    assert_eq!(received[0], later);
    assert!(received[1].as_str().unwrap().starts_with("signal-command:"), "{received:?}");
    let resumed = poll(&app, "dup-worker", "workflow").await;
    assert_eq!(resumed["signal_wait_id"], later);
    let (status, body) = finish_task(&app, &resumed, json!([{"type":"start_timer","delay_seconds":600}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let applied = events(&history, "SignalApplied");
    assert_eq!((applied.len(), applied[0]["payload"]["sequence"].clone()), (1, json!(2)));
}

fn ids(history: &Value, kind: &str) -> Vec<Value> {
    events(history, kind).iter().map(|event| event["payload"]["signal_wait_id"].clone()).collect()
}

fn declared_worker(worker: &str, names: &[&str]) -> Value {
    let contracts: Vec<_> = names.iter().map(|name| json!({"name":name,"parameters":[]})).collect();
    json!({"worker_id":worker,"task_queue":"test","runtime":"php","supported_workflow_types":["echo"],
        "supported_activity_types":[],"workflow_command_contracts":{"echo":{"signals":names,"signal_contracts":contracts}}})
}

async fn register_declared(app: &Router, worker: &str, names: &[&str]) {
    let (status, body) = request(app, "POST", "/api/worker/register", declared_worker(worker, names)).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

/// The next workflow task, waiting up to ten seconds for a timer to fire.
async fn next_task(app: &Router, worker: &str) -> Value {
    tokio::time::timeout(std::time::Duration::from_secs(10), async {
        loop {
            let task = poll(app, worker, "workflow").await;
            if !task.is_null() {
                return task;
            }
            tokio::time::sleep(std::time::Duration::from_millis(50)).await;
        }
    })
    .await
    .expect("a workflow task")
}

fn timer() -> Value {
    json!({"type":"start_timer","delay_seconds":600})
}

// Published 2.11.5, recorded: a signal buffered before any wait gives its
// identity to the first wait of its name a task opens
// (`pendingSignalWaitIdForOpenSignalWait`). A second wait of that name in
// the same completion finds the identity taken and gets a fresh one, and
// the signal then resumes the first wait.
#[tokio::test]
async fn a_buffered_signal_gives_its_identity_to_one_new_wait_only() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "buffered-worker", json!(["echo"]), json!([])).await;
    let run = start(&app, "buffered-identity").await;
    let task = poll(&app, "buffered-worker", "workflow").await;
    signal(&app, "buffered-identity", "dup").await;
    let (status, body) = finish_task(&app, &task, json!([
        {"type":"open_signal_wait","signal_name":"dup"},
        {"type":"open_signal_wait","signal_name":"dup"},
    ])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let buffered = ids(&history, "SignalReceived")[0].clone();
    let opened = ids(&history, "SignalWaitOpened");
    assert!(buffered.as_str().unwrap().starts_with("signal-command:"), "{buffered}");
    assert_eq!(opened[0], buffered);
    assert_ne!(opened[1], buffered, "two waits never share an identity");
    let resumed = poll(&app, "buffered-worker", "workflow").await;
    assert_eq!(resumed["signal_wait_id"], buffered, "{resumed}");
    let (status, body) = finish_task(&app, &resumed, json!([timer()])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let applied = events(&history, "SignalApplied");
    assert_eq!((applied.len(), applied[0]["payload"]["sequence"].clone(), applied[0]["payload"]["signal_wait_id"].clone()),
        (1, json!(1), buffered));
}

// Published 2.11.5, recorded in a lab: a signal that reserved an open wait
// does not lend that identity to a wait a later (timer-resumed) task
// opens; the new wait gets a fresh identity and the completion records.
#[tokio::test]
async fn a_reserved_wait_identity_is_not_taken_by_a_later_wait() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "reserved-worker", json!(["echo"]), json!([])).await;
    let run = start(&app, "reserved-identity").await;
    let task = poll(&app, "reserved-worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([
        {"type":"open_signal_wait","signal_name":"dup"},
        {"type":"start_timer","delay_seconds":1},
    ])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let resumed = next_task(&app, "reserved-worker").await;
    signal(&app, "reserved-identity", "dup").await;
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let first = ids(&history, "SignalWaitOpened")[0].clone();
    assert_eq!(ids(&history, "SignalReceived"), [first.clone()], "the signal reserves the open wait");
    let (status, body) = finish_task(&app, &resumed, json!([{"type":"open_signal_wait","signal_name":"dup"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let opened = ids(&history, "SignalWaitOpened");
    assert_eq!(opened.len(), 2, "{history}");
    assert_ne!(opened[1], first, "two waits never share an identity");
    assert!(!opened[1].as_str().unwrap().starts_with("signal-command:"), "{opened:?}");
    // The signal arrived while a task was leased, so none was created for
    // it then. After a completion Core does not count a wait a received
    // signal holds (`includeReceivedProjectedSignalWait: false`), so no
    // task follows either.
    assert_eq!(body["created_task_ids"], json!([]), "{body}");
    assert!(poll(&app, "reserved-worker", "workflow").await.is_null());
}

// Published 2.11.5, recorded: two waits for `dup`; the first signal
// reserves the later one, the second is buffered. A declared signal is
// delivered without a matching wait, and its application rebinds the
// buffered identity to the open wait of its name
// (`recordAppliedSignalForSignalResume`, `openWaitIdForName`).
#[tokio::test]
async fn a_declared_buffered_signal_resumes_the_open_wait_of_its_name() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register_declared(&app, "declared-worker", &["dup"]).await;
    let run = start(&app, "declared-rebind").await;
    let task = poll(&app, "declared-worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([
        {"type":"open_signal_wait","signal_name":"dup"},
        {"type":"open_signal_wait","signal_name":"dup"},
    ])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    signal(&app, "declared-rebind", "dup").await;
    signal(&app, "declared-rebind", "dup").await;
    let resumed = poll(&app, "declared-worker", "workflow").await;
    let (status, body) = finish_task(&app, &resumed, json!([timer()])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let opened = ids(&history, "SignalWaitOpened");
    let buffered = ids(&history, "SignalReceived")[1].clone();
    let delivered = poll(&app, "declared-worker", "workflow").await;
    assert_eq!((delivered["resume_source_kind"].clone(), delivered["signal_wait_id"].clone()),
        (json!("workflow_signal"), buffered), "{delivered}");
    let (status, body) = finish_task(&app, &delivered, json!([timer()])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let applied: Vec<_> = events(&history, "SignalApplied").iter()
        .map(|event| (event["payload"]["sequence"].clone(), event["payload"]["signal_wait_id"].clone())).collect();
    assert_eq!(applied, [(json!(2), opened[1].clone()), (json!(1), opened[0].clone())]);
    assert!(poll(&app, "declared-worker", "workflow").await.is_null());
}

// Published 2.11.5, recorded: the same, undeclared. Nothing delivers the
// buffered signal (its identity names no open wait), so it stays received.
// A task that opens another wait of its name gives that wait the buffered
// identity, and the signal then resumes it.
#[tokio::test]
async fn an_undeclared_buffered_signal_waits_for_a_wait_that_takes_its_identity() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "undeclared-worker", json!(["echo"]), json!([])).await;
    for (workflow, reopen) in [("undeclared-stays", false), ("undeclared-reopens", true)] {
        let run = start(&app, workflow).await;
        let task = poll(&app, "undeclared-worker", "workflow").await;
        let (status, body) = finish_task(&app, &task, json!([
            {"type":"open_signal_wait","signal_name":"dup"},
            {"type":"open_signal_wait","signal_name":"dup"},
        ])).await;
        assert_eq!(status, StatusCode::OK, "{body}");
        signal(&app, workflow, "dup").await;
        signal(&app, workflow, "dup").await;
        let resumed = poll(&app, "undeclared-worker", "workflow").await;
        let next = if reopen { json!([{"type":"open_signal_wait","signal_name":"dup"}]) } else { json!([timer()]) };
        let (status, body) = finish_task(&app, &resumed, next).await;
        assert_eq!(status, StatusCode::OK, "{body}");
        let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
        let buffered = ids(&history, "SignalReceived")[1].clone();
        let delivered = poll(&app, "undeclared-worker", "workflow").await;
        if !reopen {
            assert!(delivered.is_null(), "{delivered}");
            assert_eq!(events(&history, "SignalApplied").len(), 1);
            continue;
        }
        assert_eq!(ids(&history, "SignalWaitOpened")[2], buffered);
        assert_eq!(delivered["signal_wait_id"], buffered, "{delivered}");
        let (status, body) = finish_task(&app, &delivered, json!([timer()])).await;
        assert_eq!(status, StatusCode::OK, "{body}");
        let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
        let applied = events(&history, "SignalApplied");
        assert_eq!((applied[1]["payload"]["sequence"].clone(), applied[1]["payload"]["signal_wait_id"].clone()), (json!(3), buffered));
    }
}

// Published 2.11.5, recorded: once a condition wait's timeout has fired, a
// signal neither satisfies it (`latestOpenConditionWait` skips a fired
// timeout) nor, undeclared and without a wait of its own, is delivered.
#[tokio::test]
async fn a_fired_condition_timeout_is_not_satisfied_by_a_later_signal() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "fired-worker", json!(["echo"]), json!([])).await;
    for (workflow, with_signal_wait) in [("fired-with-wait", true), ("fired-alone", false)] {
        let run = start(&app, workflow).await;
        let task = poll(&app, "fired-worker", "workflow").await;
        let mut commands = vec![json!({"type":"open_condition_wait","condition_key":"ready","timeout_seconds":1})];
        if with_signal_wait {
            commands.push(json!({"type":"open_signal_wait","signal_name":"go"}));
        }
        let (status, body) = finish_task(&app, &task, Value::Array(commands)).await;
        assert_eq!(status, StatusCode::OK, "{body}");
        let fired = next_task(&app, "fired-worker").await;
        assert_eq!((fired["resume_source_kind"].clone(), fired["workflow_wait_kind"].clone()), (json!("timer"), json!("condition")), "{fired}");
        let (status, body) = finish_task(&app, &fired, json!([timer()])).await;
        assert_eq!(status, StatusCode::OK, "{body}");
        signal(&app, workflow, if with_signal_wait { "go" } else { "x" }).await;
        let delivered = poll(&app, "fired-worker", "workflow").await;
        if with_signal_wait {
            let (status, body) = finish_task(&app, &delivered, json!([timer()])).await;
            assert_eq!(status, StatusCode::OK, "{body}");
        } else {
            assert!(delivered.is_null(), "{delivered}");
        }
        let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
        assert!(events(&history, "ConditionWaitSatisfied").is_empty(), "{:?}", kinds(&history));
        assert_eq!(events(&history, "SignalApplied").len(), usize::from(with_signal_wait));
    }
}

// Published 2.11.5, recorded: an undeclared signal delivered while a
// condition wait could take it, whose timeout fires before the task
// applies it, is not consumed by that fired wait. It stays received and
// satisfies the next condition wait the run opens.
#[tokio::test]
async fn a_signal_outlives_a_condition_wait_whose_timeout_fired_first() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "outlive-worker", json!(["echo"]), json!([])).await;
    let run = start(&app, "outlive").await;
    let task = poll(&app, "outlive-worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([
        {"type":"open_condition_wait","condition_key":"ready","timeout_seconds":1},
    ])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    signal(&app, "outlive", "x").await;
    tokio::time::timeout(std::time::Duration::from_secs(10), async {
        while events(&run_history(&app, &run["workflow_id"], &run["run_id"]).await, "TimerFired").is_empty() {
            tokio::time::sleep(std::time::Duration::from_millis(50)).await;
        }
    })
    .await
    .expect("the condition timeout fires");
    let delivered = poll(&app, "outlive-worker", "workflow").await;
    assert_eq!(delivered["resume_source_kind"], "workflow_signal", "{delivered}");
    let (status, body) = finish_task(&app, &delivered, json!([timer()])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let fired = next_task(&app, "outlive-worker").await;
    assert_eq!(fired["resume_source_kind"], "timer", "{fired}");
    let (status, body) = finish_task(&app, &fired, json!([{"type":"open_condition_wait","condition_key":"again"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["created_task_ids"].as_array().map(Vec::len), Some(1), "the received signal is delivered again: {body}");
    let again = poll(&app, "outlive-worker", "workflow").await;
    assert_eq!(again["signal_name"], "x", "{again}");
    let (status, body) = finish_task(&app, &again, json!([timer()])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let satisfied = events(&history, "ConditionWaitSatisfied");
    assert_eq!((satisfied.len(), satisfied[0]["payload"]["sequence"].clone(), satisfied[0]["payload"]["signal_name"].clone()),
        (1, json!(3), json!("x")), "{:?}", kinds(&history));
}

/// Appends a cancelled selection over `size` workflow sequences from `base`,
/// as an imported or PHP-written history records it.
async fn cancel_selection(database: &TestDatabase, run: &Value, base: i64, size: i64) {
    let run = run["run_id"].as_str().unwrap();
    let payload = json!({"selection_group_id":"selection-1","member_base_sequence":base,"member_size":size}).to_string();
    let id = ulid::Ulid::new().to_string();
    database.execute(sqlx::AssertSqlSafe(format!(
        "INSERT INTO workflow_history_events(id,workflow_run_id,sequence,event_type,payload,recorded_at,created_at,updated_at) SELECT '{id}',id,last_history_sequence+1,'SelectionOperationCancelled','{payload}','2026-10-28 00:00:00.000000','2026-10-28 00:00:00.000000','2026-10-28 00:00:00.000000' FROM workflow_runs WHERE id='{run}'"
    ))).await;
    database.execute(sqlx::AssertSqlSafe(format!("UPDATE workflow_runs SET last_history_sequence=last_history_sequence+1 WHERE id='{run}'"))).await;
}

// Core 2.15.1 `closeCancelledSelectionWaits`: a cancelled selection closes
// the open waits in its member range at that point of history. A wait
// opened later in that range stays open, and a wait a received signal
// already holds is not open, so the signal still resumes it.
#[tokio::test]
async fn a_cancelled_selection_closes_only_the_waits_open_at_that_point() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "selection-worker", json!(["echo"]), json!([])).await;

    // A wait opened after the cancellation, inside its range, stays open.
    let run = start(&app, "selection-later").await;
    let task = poll(&app, "selection-worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([
        {"type":"open_signal_wait","signal_name":"a"},
        {"type":"start_timer","delay_seconds":1},
    ])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    cancel_selection(&database, &run, 1, 3).await;
    let resumed = next_task(&app, "selection-worker").await;
    let (status, body) = finish_task(&app, &resumed, json!([{"type":"open_signal_wait","signal_name":"b"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let later = events(&history, "SignalWaitOpened")[1]["payload"].clone();
    assert_eq!(later["sequence"], 3, "{later}");
    signal(&app, "selection-later", "a").await;
    signal(&app, "selection-later", "b").await;
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let received = ids(&history, "SignalReceived");
    assert!(received[0].as_str().unwrap().starts_with("signal-command:"), "`a` was closed: {received:?}");
    assert_eq!(received[1], later["signal_wait_id"], "`b` is open");
    let delivered = poll(&app, "selection-worker", "workflow").await;
    assert_eq!(delivered["signal_wait_id"], later["signal_wait_id"], "{delivered}");
    let (status, body) = finish_task(&app, &delivered, json!([timer()])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert!(poll(&app, "selection-worker", "workflow").await.is_null());

    // A wait a received signal holds survives the cancellation; an open
    // one does not.
    let run = start(&app, "selection-held").await;
    let task = poll(&app, "selection-worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([
        {"type":"open_signal_wait","signal_name":"a"},
        {"type":"open_signal_wait","signal_name":"b"},
    ])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    signal(&app, "selection-held", "a").await;
    cancel_selection(&database, &run, 1, 2).await;
    let delivered = poll(&app, "selection-worker", "workflow").await;
    let (status, body) = finish_task(&app, &delivered, json!([timer()])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    signal(&app, "selection-held", "b").await;
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let applied = events(&history, "SignalApplied");
    assert_eq!((applied.len(), applied[0]["payload"]["sequence"].clone()), (1, json!(1)), "{:?}", kinds(&history));
    assert!(ids(&history, "SignalReceived")[1].as_str().unwrap().starts_with("signal-command:"), "`b` was closed");
    assert!(poll(&app, "selection-worker", "workflow").await.is_null());
    runtime.close().await;
    database.remove().await;
}

// Core 2.15.1 `SignalWaits::forRun`: a wait recorded without its id (an
// older or imported history) is known as `signal:{sequence}:{name}`, and a
// signal reserves it and is delivered under that identity. Applying looks
// for a `SignalWaitOpened` that names the identity
// (`recordAppliedSignalForSignalResume`), which a derived one never is, so
// Core completes the task and leaves the signal received.
#[tokio::test]
async fn a_wait_recorded_without_an_id_is_reserved_under_its_derived_identity() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "legacy-worker", json!(["echo"]), json!([])).await;
    let run = start(&app, "legacy-wait").await;
    let task = poll(&app, "legacy-worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"open_signal_wait","signal_name":"go"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let strip = match &database {
        TestDatabase::Sqlite(_) => "json_remove(payload,'$.signal_wait_id')",
        TestDatabase::Postgres { .. } => "(payload::jsonb - 'signal_wait_id')::json",
        TestDatabase::MySql { .. } => "JSON_REMOVE(payload,'$.signal_wait_id')",
    };
    database.execute(sqlx::AssertSqlSafe(format!("UPDATE workflow_history_events SET payload={strip} WHERE event_type='SignalWaitOpened'"))).await;
    signal(&app, "legacy-wait", "go").await;
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    assert_eq!(ids(&history, "SignalReceived"), [json!("signal:1:go")]);
    let resumed = poll(&app, "legacy-worker", "workflow").await;
    assert_eq!(resumed["signal_wait_id"], "signal:1:go", "{resumed}");
    let (status, body) = finish_task(&app, &resumed, json!([timer()])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    assert!(events(&history, "SignalApplied").is_empty(), "{:?}", kinds(&history));
    assert!(poll(&app, "legacy-worker", "workflow").await.is_null(), "the held wait is not delivered again");
}

// Core 2.15.1 `WorkflowTaskPayload::forSignal`: a signal stored without a
// wait id (an imported one) is delivered without that field, and its
// application records none.
#[tokio::test]
async fn a_signal_stored_without_a_wait_id_is_delivered_and_applied() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register_declared(&app, "null-wait-worker", &["dup"]).await;
    let run = start(&app, "null-wait").await;
    let task = poll(&app, "null-wait-worker", "workflow").await;
    signal(&app, "null-wait", "dup").await;
    database.execute("UPDATE workflow_signal_records SET signal_wait_id=NULL").await;
    let (status, body) = finish_task(&app, &task, json!([timer()])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let delivered = poll(&app, "null-wait-worker", "workflow").await;
    assert_eq!(delivered["resume_source_kind"], "workflow_signal", "{delivered}");
    assert!(delivered.get("signal_wait_id").is_none(), "{delivered}");
    let (status, body) = finish_task(&app, &delivered, json!([timer()])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let applied = &events(&history, "SignalApplied")[0]["payload"];
    assert!(applied.get("signal_wait_id").is_none() && applied.get("sequence").is_none(), "{applied}");
}

/// Appends one history event as PHP or an import would have written it.
async fn append_event(database: &TestDatabase, run: &Value, event_type: &str, payload: Value) {
    let run = run["run_id"].as_str().unwrap();
    let payload = payload.to_string();
    let id = ulid::Ulid::new().to_string();
    database.execute(sqlx::AssertSqlSafe(format!(
        "INSERT INTO workflow_history_events(id,workflow_run_id,sequence,event_type,payload,recorded_at,created_at,updated_at) SELECT '{id}',id,last_history_sequence+1,'{event_type}','{payload}','2026-10-28 00:00:00.000000','2026-10-28 00:00:00.000000','2026-10-28 00:00:00.000000' FROM workflow_runs WHERE id='{run}'"
    ))).await;
    database.execute(sqlx::AssertSqlSafe(format!("UPDATE workflow_runs SET last_history_sequence=last_history_sequence+1 WHERE id='{run}'"))).await;
}

async fn timer_fired(app: &Router, run: &Value) {
    tokio::time::timeout(std::time::Duration::from_secs(10), async {
        while events(&run_history(app, &run["workflow_id"], &run["run_id"]).await, "TimerFired").is_empty() {
            tokio::time::sleep(std::time::Duration::from_millis(50)).await;
        }
    })
    .await
    .expect("the timeout fires");
}

// Over the worker protocol Core records no `ConditionWaitTimedOut` (only
// its in-process executor does): a fired condition timeout leaves the wait
// open and unable to take a signal. Such a stale wait does not excuse a
// signal task whose signal nothing can take; native still refuses that
// completion. Only a timeout that fired after the signal arrived does.
#[tokio::test]
async fn a_stale_fired_condition_wait_does_not_excuse_an_unapplied_signal() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "stale-worker", json!(["echo"]), json!([])).await;
    let run = start(&app, "stale-condition").await;
    let task = poll(&app, "stale-worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([
        {"type":"open_condition_wait","condition_key":"stale","timeout_seconds":1},
    ])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    timer_fired(&app, &run).await;
    let fired = next_task(&app, "stale-worker").await;
    let (status, body) = finish_task(&app, &fired, json!([{"type":"open_condition_wait","condition_key":"live"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    assert!(kinds(&history).iter().all(|kind| kind != "ConditionWaitTimedOut"), "{:?}", kinds(&history));
    signal(&app, "stale-condition", "x").await;
    // The live condition wait that made the signal deliverable goes away
    // before the task applies it.
    append_event(&database, &run, "SelectionOperationCancelled", json!({"member_base_sequence":2,"member_size":1})).await;
    let delivered = poll(&app, "stale-worker", "workflow").await;
    assert_eq!(delivered["signal_name"], "x", "{delivered}");
    let (status, body) = finish_task(&app, &delivered, json!([timer()])).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::CONFLICT, json!("signal_wait_mismatch")), "{body}");
}

// The open-wait view and the "already opened" check read the same
// history, so a wait opened under an identity stays taken even when its
// projection row is gone.
#[tokio::test]
async fn an_opened_identity_stays_taken_without_its_projection_row() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "taken-worker", json!(["echo"]), json!([])).await;
    let run = start(&app, "taken-identity").await;
    let task = poll(&app, "taken-worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([
        {"type":"open_signal_wait","signal_name":"dup"},
        {"type":"start_timer","delay_seconds":1},
    ])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let resumed = next_task(&app, "taken-worker").await;
    signal(&app, "taken-identity", "dup").await;
    database.execute("DELETE FROM workflow_run_waits").await;
    let (status, body) = finish_task(&app, &resumed, json!([{"type":"open_signal_wait","signal_name":"dup"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let opened = ids(&history, "SignalWaitOpened");
    assert_eq!(opened.len(), 2, "{history}");
    assert_ne!(opened[0], opened[1], "two waits never share an identity");
}

// Core resolves `SignalWaitCancelled` only by the id it names: one written
// without an id (PHP-written or imported) cancels nothing, and the wait
// still takes its signal.
#[tokio::test]
async fn a_wait_cancellation_without_an_id_cancels_nothing() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "idless-worker", json!(["echo"]), json!([])).await;
    let run = start(&app, "idless-cancel").await;
    let task = poll(&app, "idless-worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"open_signal_wait","signal_name":"go"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    append_event(&database, &run, "SignalWaitCancelled", json!({"signal_name":"go","sequence":1})).await;
    database.execute("UPDATE workflow_run_waits SET payload=NULL").await;
    signal(&app, "idless-cancel", "go").await;
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let opened = ids(&history, "SignalWaitOpened")[0].clone();
    assert_eq!(ids(&history, "SignalReceived"), [opened.clone()]);
    let delivered = poll(&app, "idless-worker", "workflow").await;
    assert_eq!(delivered["signal_wait_id"], opened, "{delivered}");
    let (status, body) = finish_task(&app, &delivered, json!([timer()])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    assert_eq!(events(&history, "SignalApplied")[0]["payload"]["sequence"], 1);
}

// Published 2.11.5, recorded in a lab: `ConditionWaitSatisfied` carries the
// wait's identity, key, sequence, timeout timer and seconds, the resuming
// signal task's signal fields and that task's snapshot; nothing of the
// opening event (its task, or fields only an opening has).
#[tokio::test]
async fn condition_satisfaction_is_built_from_the_wait_and_the_resuming_task() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "satisfied-worker", json!(["echo"]), json!([])).await;
    let run = start(&app, "satisfied-shape").await;
    let task = poll(&app, "satisfied-worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([
        {"type":"open_condition_wait","condition_key":"ready","timeout_seconds":600},
    ])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    signal(&app, "satisfied-shape", "x").await;
    let resumed = poll(&app, "satisfied-worker", "workflow").await;
    let (status, body) = finish_task(&app, &resumed, json!([timer()])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let satisfied = &events(&history, "ConditionWaitSatisfied")[0]["payload"];
    let mut keys: Vec<_> = satisfied.as_object().unwrap().keys().cloned().collect();
    keys.sort();
    assert_eq!(keys, ["condition_key", "condition_wait_id", "sequence", "signal_name", "signal_wait_id", "task",
        "timeout_seconds", "timer_id", "workflow_signal_id"], "{satisfied}");
    assert_eq!(satisfied["task"]["id"], resumed["task_id"], "{satisfied}");
    assert_eq!(satisfied["signal_wait_id"], resumed["signal_wait_id"]);
    let scheduled = &events(&history, "TimerScheduled")[0]["payload"];
    assert_eq!(satisfied["timer_id"], scheduled["timer_id"]);
}

// Acceptance counts a pending signal with the same predicate delivery and
// wait opening use (received and not closed), so a closed record (as PHP
// or an import may leave one) does not buffer the next signal.
#[tokio::test]
async fn a_closed_received_signal_does_not_buffer_the_next_one() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "closed-worker", json!(["echo"]), json!([])).await;
    let run = start(&app, "closed-pending").await;
    let task = poll(&app, "closed-worker", "workflow").await;
    signal(&app, "closed-pending", "go").await;
    database.execute("UPDATE workflow_signal_records SET closed_at='2026-10-28 00:00:00.000000'").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"open_signal_wait","signal_name":"go"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    signal(&app, "closed-pending", "go").await;
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let opened = ids(&history, "SignalWaitOpened")[0].clone();
    assert!(!opened.as_str().unwrap().starts_with("signal-command:"), "the closed record lends no identity: {opened}");
    assert_eq!(ids(&history, "SignalReceived")[1], opened, "the second signal reserves the open wait");
}

/// One text column of one stored row, read straight from the database.
async fn stored_text(database: &TestDatabase, query: &str) -> Option<String> {
    let query = sqlx::AssertSqlSafe(query.to_owned());
    match database {
        TestDatabase::Sqlite(dir) => {
            let pool = sqlx::SqlitePool::connect(&format!("sqlite://{}", dir.path().join("runtime.sqlite").display())).await.unwrap();
            let value = sqlx::query_scalar(query).fetch_one(&pool).await.unwrap();
            pool.close().await;
            value
        }
        TestDatabase::Postgres { options, .. } => {
            let pool = sqlx::postgres::PgPoolOptions::new().connect_with(options.as_ref().clone()).await.unwrap();
            let value = sqlx::query_scalar(query).fetch_one(&pool).await.unwrap();
            pool.close().await;
            value
        }
        TestDatabase::MySql { options, .. } => {
            let pool = sqlx::mysql::MySqlPoolOptions::new().connect_with(options.as_ref().clone()).await.unwrap();
            let value = sqlx::query_scalar(query).fetch_one(&pool).await.unwrap();
            pool.close().await;
            value
        }
    }
}

// Core's completion offers the next message after the signal the task just
// attempted (`createForRun($run, workflowSignalIdForTask($task), false)`).
// A declared signal whose wait a cancellation scope cancelled stays
// received when its task completes; it is not offered again, so the run
// does not loop on it.
#[tokio::test]
async fn a_completed_signal_task_does_not_reoffer_its_own_signal() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register_declared(&app, "reoffer-worker", &["go"]).await;
    let run = start(&app, "reoffer").await;
    let task = poll(&app, "reoffer-worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"open_signal_wait","signal_name":"go"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    signal(&app, "reoffer", "go").await;
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let wait = ids(&history, "SignalWaitOpened")[0].clone();
    append_event(&database, &run, "SignalWaitCancelled", json!({"signal_name":"go","signal_wait_id":wait,"sequence":1})).await;
    let delivered = poll(&app, "reoffer-worker", "workflow").await;
    assert_eq!(delivered["signal_wait_id"], wait, "{delivered}");
    let (status, body) = finish_task(&app, &delivered, json!([{"type":"open_signal_wait","signal_name":"other"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["created_task_ids"], json!([]), "{body}");
    assert!(poll(&app, "reoffer-worker", "workflow").await.is_null());
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    assert!(events(&history, "SignalApplied").is_empty(), "the cancelled wait keeps its signal received");
    runtime.close().await;
    database.remove().await;
}

// The same rule when the completion itself opens a condition wait the
// unapplied signal could satisfy: Core does not offer that signal back to
// the completion that left it (an undeclared one, held by a wait a
// cancellation scope cancelled).
#[tokio::test]
async fn a_signal_task_that_opens_a_condition_wait_is_not_handed_its_signal_again() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "again-worker", json!(["echo"]), json!([])).await;
    let run = start(&app, "again").await;
    let task = poll(&app, "again-worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"open_signal_wait","signal_name":"x"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    signal(&app, "again", "x").await;
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let wait = ids(&history, "SignalWaitOpened")[0].clone();
    append_event(&database, &run, "SignalWaitCancelled", json!({"signal_name":"x","signal_wait_id":wait,"sequence":1})).await;
    let delivered = poll(&app, "again-worker", "workflow").await;
    assert_eq!(delivered["signal_name"], "x", "{delivered}");
    let (status, body) = finish_task(&app, &delivered, json!([{"type":"open_condition_wait","condition_key":"again"}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["created_task_ids"], json!([]), "{body}");
    assert!(poll(&app, "again-worker", "workflow").await.is_null());
    runtime.close().await;
    database.remove().await;
}

fn select_member(base: i64, index: i64, key: &str, kind: &str) -> Value {
    json!({"parallel_group_id":format!("select-calls:{base}:2"),"parallel_group_kind":"mixed","parallel_group_mode":"select",
        "parallel_group_base_sequence":base,"parallel_group_size":2,"parallel_group_index":index,"selection_member_key":key,
        "selection_member_index":index,"selection_member_base_sequence":base + index,"selection_member_size":1,
        "selection_member_kind":kind})
}

// Native does not open waits as members of a parallel group: it refuses
// the batch with its explicit unqualified-shape answer, where published
// 2.11.5 accepts it.
#[tokio::test]
async fn opening_waits_as_selection_members_is_explicitly_unqualified() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "select-open-worker", json!(["echo"]), json!([])).await;
    start(&app, "select-open").await;
    let task = poll(&app, "select-open-worker", "workflow").await;
    let member = |index: i64, key: &str| {
        let mut command = select_member(1, index, key, "signal");
        command["type"] = json!("open_signal_wait");
        command["signal_name"] = json!(key);
        command["parallel_group_path"] = json!([select_member(1, index, key, "signal")]);
        command
    };
    let (status, body) = finish_task(&app, &task, json!([member(0, "a"), member(1, "b")])).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::UNPROCESSABLE_ENTITY, json!("development_selection_shape_unqualified")), "{body}");
    runtime.close().await;
    database.remove().await;
}

// Published 2.11.5, recorded in a lab: a signal applied to a wait that is a
// selection member carries the member's parallel group, and the first
// member to resolve wins the selection (`SelectionResolved`, identified by
// its wait). The losing wait stays open and takes its own signal. Native
// serves this for waits a taken-over PHP history opened.
#[tokio::test]
async fn a_signal_applied_to_a_selection_member_wins_the_selection() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "select-worker", json!(["echo"]), json!([])).await;
    let run = start(&app, "select-waits").await;
    let task = poll(&app, "select-worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([timer()])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    for (index, key) in [(0, "a"), (1, "b")] {
        let mut opened = select_member(2, index, key, "signal");
        opened["parallel_group_path"] = json!([select_member(2, index, key, "signal")]);
        opened["signal_name"] = json!(key);
        opened["signal_wait_id"] = json!(format!("wait-{key}"));
        opened["sequence"] = json!(2 + index);
        append_event(&database, &run, "SignalWaitOpened", opened).await;
    }
    signal(&app, "select-waits", "b").await;
    let delivered = poll(&app, "select-worker", "workflow").await;
    assert_eq!(delivered["signal_wait_id"], "wait-b", "{delivered}");
    let (status, body) = finish_task(&app, &delivered, json!([timer()])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let order = kinds(&history);
    let applied_at = order.iter().position(|kind| kind == "SignalApplied").unwrap();
    assert_eq!(order[applied_at + 1], "SelectionResolved", "{order:?}");
    let applied = &events(&history, "SignalApplied")[0]["payload"];
    let mut leaf = select_member(2, 1, "b", "signal");
    for (key, value) in leaf.as_object().unwrap() {
        assert_eq!(&applied[key], value, "{key}: {applied}");
    }
    leaf = select_member(2, 1, "b", "signal");
    assert_eq!(applied["parallel_group_path"], json!([leaf]), "{applied}");
    let resolved = &events(&history, "SelectionResolved")[0]["payload"];
    for (key, value) in [("selection_group_id", json!("select-calls:2:2")), ("selection_group_base_sequence", json!(2)),
        ("selection_group_size", json!(2)), ("member_key", json!("b")), ("member_index", json!(1)),
        ("member_base_sequence", json!(3)), ("member_size", json!(1)), ("operation_kind", json!("signal")),
        ("operation_identity", json!("wait-b")), ("outcome", json!("completed")), ("resolution_event_type", json!("SignalApplied"))] {
        assert_eq!(resolved[key], value, "{key}: {resolved}");
    }
    signal(&app, "select-waits", "a").await;
    let loser = poll(&app, "select-worker", "workflow").await;
    assert_eq!(loser["signal_wait_id"], "wait-a", "the losing wait stays open: {loser}");
    runtime.close().await;
    database.remove().await;
}

// `markConditionWaitSignalConsumed` leaves the signal's identity as it
// was: only an application persists an identity the signal was rebound to.
#[tokio::test]
async fn a_condition_consuming_a_signal_keeps_its_recorded_identity() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "consume-worker", json!(["echo"]), json!([])).await;
    let run = start(&app, "consume-identity").await;
    let task = poll(&app, "consume-worker", "workflow").await;
    signal(&app, "consume-identity", "go").await;
    let (status, body) = finish_task(&app, &task, json!([
        {"type":"open_signal_wait","signal_name":"go"},
        {"type":"open_condition_wait","condition_key":"ready"},
    ])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let buffered = ids(&run_history(&app, &run["workflow_id"], &run["run_id"]).await, "SignalReceived")[0].clone();
    // The wait reads as an older history wrote it, with no id: Core knows it
    // as `signal:1:go`, so applying rebinds the buffered signal to that
    // identity, finds no opening that names it and leaves the condition
    // wait to consume the signal.
    let strip = match &database {
        TestDatabase::Sqlite(_) => "json_remove(payload,'$.signal_wait_id')",
        TestDatabase::Postgres { .. } => "(payload::jsonb - 'signal_wait_id')::json",
        TestDatabase::MySql { .. } => "JSON_REMOVE(payload,'$.signal_wait_id')",
    };
    database.execute(sqlx::AssertSqlSafe(format!("UPDATE workflow_history_events SET payload={strip} WHERE event_type='SignalWaitOpened'"))).await;
    let delivered = poll(&app, "consume-worker", "workflow").await;
    assert_eq!(delivered["signal_wait_id"], buffered, "{delivered}");
    let (status, body) = finish_task(&app, &delivered, json!([timer()])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    assert!(events(&history, "SignalApplied").is_empty(), "{:?}", kinds(&history));
    assert_eq!(events(&history, "ConditionWaitSatisfied")[0]["payload"]["signal_wait_id"], buffered);
    let stored = stored_text(&database, "SELECT signal_wait_id FROM workflow_signal_records").await;
    assert_eq!(stored.as_deref(), buffered.as_str());
    runtime.close().await;
    database.remove().await;
}
