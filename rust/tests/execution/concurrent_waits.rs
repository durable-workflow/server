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

// Published 2.11.5, recorded: a signal that reserved an open wait does not
// lend that identity to a wait a later (timer-resumed) task opens; the new
// wait gets a fresh identity and the completion records.
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

/// Forgets each signal and condition wait's native opening record, so the
/// run's waits are read from history as for a run opened before native
/// kept that record.
async fn forget_opening_records(database: &TestDatabase) {
    database.execute("UPDATE workflow_run_waits SET payload=NULL WHERE kind IN ('signal','condition')").await;
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
// already holds is not open, so the signal still resumes it. Read from
// the projection and from history alike.
#[tokio::test]
async fn a_cancelled_selection_closes_only_the_waits_open_at_that_point() {
    for from_history in [false, true] {
        let database = TestDatabase::new().await;
        let app = router(database.open().await.unwrap());
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
        if from_history {
            forget_opening_records(&database).await;
        }
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
        if from_history {
            forget_opening_records(&database).await;
        }
        let delivered = poll(&app, "selection-worker", "workflow").await;
        let (status, body) = finish_task(&app, &delivered, json!([timer()])).await;
        assert_eq!(status, StatusCode::OK, "{body}");
        signal(&app, "selection-held", "b").await;
        let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
        let applied = events(&history, "SignalApplied");
        assert_eq!((applied.len(), applied[0]["payload"]["sequence"].clone()), (1, json!(1)), "{:?}", kinds(&history));
        assert!(ids(&history, "SignalReceived")[1].as_str().unwrap().starts_with("signal-command:"), "`b` was closed");
        assert!(poll(&app, "selection-worker", "workflow").await.is_null());
        database.remove().await;
    }
}

// Core 2.15.1 `SignalWaits::forRun`: a wait recorded without its id (an
// older or imported history) is known as `signal:{sequence}:{name}`, and
// a signal reserves and resumes it under that identity.
#[tokio::test]
async fn a_wait_recorded_without_an_id_takes_its_derived_identity() {
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
    forget_opening_records(&database).await;
    signal(&app, "legacy-wait", "go").await;
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    assert_eq!(ids(&history, "SignalReceived"), [json!("signal:1:go")]);
    let resumed = poll(&app, "legacy-worker", "workflow").await;
    assert_eq!(resumed["signal_wait_id"], "signal:1:go", "{resumed}");
    let (status, body) = finish_task(&app, &resumed, json!([timer()])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let applied = &events(&history, "SignalApplied")[0]["payload"];
    assert_eq!((applied["sequence"].clone(), applied["signal_wait_id"].clone()), (json!(1), json!("signal:1:go")));
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
