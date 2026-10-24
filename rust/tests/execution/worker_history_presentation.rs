use super::*;

// Server 2.11.1 `WorkflowTaskPoller::historyEventsWithSignalArgumentEnvelopes`
// and `WorkflowHistoryEvent::taskSnapshot`: the history a worker reads shows a
// consumed signal's workflow sequence, and task snapshots keep their dispatch
// stamps.

fn wait(key: &str) -> Value {
    json!([{"type":"open_condition_wait","condition_key":key,
        "condition_definition_fingerprint":format!("sha256:{}", "b".repeat(64))}])
}

fn received(history: &Value) -> Vec<Value> {
    history.as_array().unwrap().iter().filter(|event| event["event_type"] == "SignalReceived")
        .map(|event| event["payload"].clone()).collect()
}

#[tokio::test]
async fn a_worker_reads_the_sequence_that_consumed_a_signal() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    // A run started before any worker declared a contract admits any signal.
    let started = start(&app, "presented-signals").await;
    let worker = json!({"worker_id":"presenter","task_queue":"test","runtime":"php","supported_workflow_types":["echo"],
        "supported_activity_types":[]});
    assert_eq!(request(&app, "POST", "/api/worker/register", worker).await.0, StatusCode::CREATED);
    let task = poll(&app, "presenter", "workflow").await;
    assert_eq!(finish_task(&app, &task, wait("first")).await.0, StatusCode::OK);
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let opened = history.as_array().unwrap().iter().find(|event| event["event_type"] == "ConditionWaitOpened").unwrap();
    let sequence = opened["payload"]["sequence"].clone();

    let (status, body) = request(&app, "POST", "/api/workflows/presented-signals/signal/go", json!([])).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    let task = poll(&app, "presenter", "workflow").await;
    assert!(received(&task["history_events"])[0].get("workflow_sequence").is_none(), "not consumed yet: {task}");
    assert_eq!(finish_task(&app, &task, wait("second")).await.0, StatusCode::OK);

    let (status, body) = request(&app, "POST", "/api/workflows/presented-signals/signal/again", json!([])).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    let task = poll(&app, "presenter", "workflow").await;
    let signals = received(&task["history_events"]);
    assert_eq!(signals[0]["workflow_sequence"], sequence, "the consuming wait's sequence: {signals:?}");
    assert!(signals[1].get("workflow_sequence").is_none(), "{signals:?}");
    // A history page reads the same.
    let (status, page) = request(&app, "POST", &format!("/api/worker/workflow-tasks/{}/history", task["task_id"].as_str().unwrap()),
        json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"]})).await;
    assert_eq!(status, StatusCode::OK, "{page}");
    assert_eq!(received(&page["history_events"])[0]["workflow_sequence"], sequence, "{page}");
    // The run's own history shows the event as recorded.
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert!(received(&history)[0].get("workflow_sequence").is_none(), "{history}");

    // The completing task was dispatched when the signal created it, and its
    // snapshot keeps the dispatch stamps.
    let done = json!([{"type":"complete_workflow","result":envelope(Payload::Null)}]);
    assert_eq!(finish_task(&app, &task, done).await.0, StatusCode::OK);
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let completed = history.as_array().unwrap().iter().find(|event| event["event_type"] == "WorkflowCompleted").unwrap();
    for stamp in ["last_dispatch_attempt_at", "last_dispatched_at"] {
        assert!(completed["payload"]["task"][stamp].is_string(), "{stamp}: {completed}");
    }
    assert!(completed["payload"]["task"].get("last_claim_error").is_none(), "unset fields stay out: {completed}");
}

// `WorkflowHistoryEvent::record` with the task model: each event PHP records
// while handling a task carries that task's snapshot, and a fired timer
// carries its timer task, leased to itself.
#[tokio::test]
async fn events_recorded_with_a_task_carry_its_snapshot() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let started = start(&app, "snapshot-events").await;
    register(&app, "snapshotter", json!(["echo", "child"]), json!([])).await;
    let events = || run_history(&app, &started["workflow_id"], &started["run_id"]);
    let snapshot_of = |history: &Value, kind: &str| -> Value {
        history.as_array().unwrap().iter().rev().find(|event| event["event_type"] == kind)
            .map(|event| event["payload"]["task"].clone()).unwrap_or(Value::Null)
    };

    let task = poll(&app, "snapshotter", "workflow").await;
    assert_eq!(finish_task(&app, &task, json!([{"type":"start_timer","delay_seconds":1}])).await.0, StatusCode::OK);
    let scheduled = snapshot_of(&events().await, "TimerScheduled");
    assert_eq!((scheduled["id"].clone(), scheduled["type"].clone()), (task["task_id"].clone(), json!("workflow")), "{scheduled}");
    for field in ["queue", "connection", "available_at", "leased_at", "repair_count"] {
        assert!(scheduled.get(field).is_some(), "{field}: {scheduled}");
    }
    let deadline = tokio::time::Instant::now() + std::time::Duration::from_secs(15);
    let fired = loop {
        let fired = snapshot_of(&events().await, "TimerFired");
        if !fired.is_null() {
            break fired;
        }
        assert!(tokio::time::Instant::now() < deadline, "the timer never fired");
        tokio::time::sleep(std::time::Duration::from_millis(100)).await;
    };
    assert_eq!((fired["type"].clone(), fired["status"].clone(), fired["attempt_count"].clone()),
        (json!("timer"), json!("leased"), json!(1)), "{fired}");
    assert_eq!(fired["lease_owner"], fired["id"], "{fired}");
    assert!(fired["lease_expires_at"].is_string() && fired["leased_at"].is_string(), "{fired}");

    let task = poll(&app, "snapshotter", "workflow").await;
    assert_eq!(finish_task(&app, &task, json!([
        {"type":"start_child_workflow","workflow_type":"child","arguments":envelope(Payload::Array(vec![]))},
        {"type":"open_signal_wait","signal_name":"go"}])).await.0, StatusCode::OK);
    let history = events().await;
    for kind in ["ChildWorkflowScheduled", "ChildRunStarted", "SignalWaitOpened"] {
        assert_eq!(snapshot_of(&history, kind)["id"], task["task_id"], "{kind}: {history}");
    }
    let (status, body) = request(&app, "POST", "/api/workflows/snapshot-events/signal/go", json!([])).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    let mut task = poll(&app, "snapshotter", "workflow").await;
    // The child's first task may come first on the shared queue.
    if task["workflow_type"] == "child" {
        assert_eq!(finish_task(&app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await.0, StatusCode::OK);
        task = poll(&app, "snapshotter", "workflow").await;
    }
    assert_eq!(finish_task(&app, &task, json!([{"type":"open_condition_wait","condition_key":"k",
        "condition_definition_fingerprint":format!("sha256:{}", "c".repeat(64))}])).await.0, StatusCode::OK);
    let history = events().await;
    assert_eq!(snapshot_of(&history, "SignalApplied")["id"], task["task_id"], "{history}");
    assert_eq!(snapshot_of(&history, "ConditionWaitOpened")["id"], task["task_id"], "{history}");
}

// Workflow 2.10.2 `DefaultWorkflowTaskBridge::applySignal` takes a workflow
// sequence only from the signal's own wait. A signal the run's contract
// declares is applied without one even while a condition wait is open, so
// `markConditionWaitSignalConsumed` finds it applied and its record keeps no
// sequence: the history a worker reads matches the run's history.
#[tokio::test]
async fn a_declared_signal_consumed_by_a_condition_wait_keeps_no_sequence() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    update_worker(&app, "declared-presenter").await;
    let started = start(&app, "declared-signals").await;
    let task = poll(&app, "declared-presenter", "workflow").await;
    assert_eq!(finish_task(&app, &task, wait("first")).await.0, StatusCode::OK);

    let finish = json!({"input":envelope(Payload::Array(vec![Payload::Long(7)]))});
    let (status, body) = request(&app, "POST", "/api/workflows/declared-signals/signal/finish", finish.clone()).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    let task = poll(&app, "declared-presenter", "workflow").await;
    assert_eq!(finish_task(&app, &task, wait("second")).await.0, StatusCode::OK);
    let (status, body) = request(&app, "POST", "/api/workflows/declared-signals/signal/finish", finish).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    let task = poll(&app, "declared-presenter", "workflow").await;

    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let polled = received(&task["history_events"]);
    assert_eq!(polled.len(), 2, "{task}");
    assert!(polled.iter().all(|signal| signal.get("workflow_sequence").is_none()), "{polled:?}");
    let recorded = received(&history);
    assert_eq!(polled[0]["signal_id"], recorded[0]["signal_id"]);
    let (_, described) = request(&app, "GET", "/api/workflows/declared-signals", Value::Null).await;
    assert_eq!(described["status"], "waiting", "{described}");
}

// `historyEventsWithSignalArgumentEnvelopes` looks up only the current
// page's signal IDs (`whereIn('id', $signalIds)`), fenced to the run. A run
// with many consumed signals, walked one event per page, hydrates exactly
// each page's own signal, and a record moved to another run is not used.
#[tokio::test]
async fn small_history_pages_hydrate_only_their_own_signals() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let started = start(&app, "paged-signals").await;
    let other = start(&app, "paged-signals-other").await;
    let worker = json!({"worker_id":"pager","task_queue":"test","runtime":"php","supported_workflow_types":["echo"],
        "supported_activity_types":[]});
    assert_eq!(request(&app, "POST", "/api/worker/register", worker).await.0, StatusCode::CREATED);
    // Both runs' first tasks open a wait, so only the signals leave work.
    for _ in 0..2 {
        let task = poll(&app, "pager", "workflow").await;
        assert_eq!(finish_task(&app, &task, wait("many")).await.0, StatusCode::OK);
    }
    for index in 0..40 {
        let (status, body) = request(&app, "POST", &format!("/api/workflows/paged-signals/signal/s{index}"), json!([])).await;
        assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    }
    // Give every recorded signal its own consuming sequence.
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let ids: Vec<String> = received(&history).iter().map(|payload| payload["signal_id"].as_str().unwrap().to_owned()).collect();
    assert_eq!(ids.len(), 40);
    for (index, id) in ids.iter().enumerate() {
        database.execute(sqlx::AssertSqlSafe(format!("UPDATE workflow_signal_records SET workflow_sequence={} WHERE id='{id}'", 1000 + index))).await;
    }
    // One record moves to another run; the page fence must not use it.
    let moved = &ids[7];
    database.execute(sqlx::AssertSqlSafe(format!("UPDATE workflow_signal_records SET workflow_run_id='{}' WHERE id='{moved}'",
        other["run_id"].as_str().unwrap()))).await;

    let (status, first) = request(&app, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"pager","task_queue":"test","timeout_seconds":0,"history_page_size":1})).await;
    assert_eq!(status, StatusCode::OK, "{first}");
    let task = first["task"].clone();
    assert_eq!(task["run_id"], started["run_id"], "{task}");
    let mut pages = vec![task["history_events"].clone()];
    let mut token = task["next_history_page_token"].clone();
    while let Some(next) = token.as_str().map(str::to_owned) {
        let (status, page) = request(&app, "POST", &format!("/api/worker/workflow-tasks/{}/history", task["task_id"].as_str().unwrap()),
            json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],
                "next_history_page_token":next,"history_page_size":1})).await;
        assert_eq!(status, StatusCode::OK, "{page}");
        assert!(page["history_events"].as_array().unwrap().len() <= 1, "{page}");
        pages.push(page["history_events"].clone());
        token = page["next_history_page_token"].clone();
    }
    let mut seen = 0;
    for page in &pages {
        for payload in received(page) {
            let id = payload["signal_id"].as_str().unwrap();
            let index = ids.iter().position(|known| known == id).unwrap();
            if id == moved {
                assert!(payload.get("workflow_sequence").is_none(), "another run's record: {payload}");
            } else {
                assert_eq!(payload["workflow_sequence"], json!(1000 + index), "{payload}");
            }
            seen += 1;
        }
    }
    assert_eq!(seen, 40, "every signal appears on its own page");
}
