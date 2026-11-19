//! Server #1133 (.github#222): one signal-with-start request starts or
//! reuses the instance's run and admits one signal on it in one transaction.
//! Each refusal writes nothing; a keyed retry returns the original receipts.
use super::namespaces::{create, scoped};
use super::*;

fn intake(signal_value: i64, key: Option<&str>) -> Value {
    let mut body = json!({"workflow_type":"echo","task_queue":"test",
        "input":envelope(Payload::Array(vec![Payload::Long(9007199254740993)])),
        "signal_input":envelope(Payload::Array(vec![Payload::Long(signal_value)]))});
    if let Some(key) = key {
        body["request_id"] = json!(key);
    }
    body
}

fn with(mut body: Value, field: &str, value: Value) -> Value {
    body[field] = value;
    body
}

async fn signal_with_start(app: &Router, workflow: &str, name: &str, body: Value) -> (StatusCode, Value) {
    request(app, "POST", &format!("/api/workflows/{workflow}/signal-with-start/{name}"), body).await
}

async fn events(app: &Router, workflow: &str, run: &Value) -> Vec<String> {
    run_history(app, &json!(workflow), run).await.as_array().unwrap().iter()
        .map(|event| event["event_type"].as_str().unwrap().to_owned()).collect()
}

/// Completes the run, applying each buffered signal its task carries.
async fn complete(app: &Router, worker: &str) {
    for _ in 0..10 {
        let task = poll(app, worker, "workflow").await;
        if task.is_null() {
            return;
        }
        let (status, body) = finish_task(app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await;
        assert_eq!(status, StatusCode::OK, "{body}");
    }
}

fn assert_refused(response: &(StatusCode, Value), status: StatusCode, reason: &str) {
    assert_eq!((response.0, response.1["reason"].as_str()), (status, Some(reason)), "{}", response.1);
    assert_eq!(response.1["control_plane"]["operation"], "signal_with_start", "{}", response.1);
}

#[tokio::test]
async fn intake_starts_reuses_and_never_reopens_a_closed_run_silently() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "intake-worker").await;

    // No instance: one run starts and the signal joins it, in one commit.
    let first = signal_with_start(&app, "intake-entity", "finish", intake(1, None)).await;
    assert_eq!(first.0, StatusCode::CREATED, "{}", first.1);
    assert_eq!((first.1["start_outcome"].as_str(), first.1["outcome"].as_str(), first.1["command_status"].as_str()),
        (Some("started_new"), Some("signal_received"), Some("accepted")), "{}", first.1);
    assert_eq!(first.1["control_plane"]["operation"], "signal_with_start");
    assert_eq!(first.1["intake_mode"], "signal_with_start");
    assert!(first.1["start_command_sequence"].as_i64() < first.1["command_sequence"].as_i64(), "{}", first.1);
    let run = first.1["run_id"].clone();
    assert_eq!(events(&app, "intake-entity", &run).await, ["StartAccepted", "WorkflowStarted", "SignalReceived"]);
    let described = request(&app, "GET", "/api/workflows/intake-entity", Value::Null).await.1;
    let original_input = described["input"].clone();
    let original_deadline = described["execution_deadline_at"].clone();

    // An open run is reused: an accepted returned-existing start and one
    // more signal, with the original input, deadlines and run unchanged.
    let second = signal_with_start(&app, "intake-entity", "finish", with(intake(2, None), "input",
        envelope(Payload::Array(vec![Payload::Long(7)])))).await;
    assert_eq!(second.0, StatusCode::ACCEPTED, "{}", second.1);
    assert_eq!((second.1["run_id"].clone(), second.1["start_outcome"].as_str()), (run.clone(), Some("returned_existing_active")));
    assert_ne!(second.1["intake_group_id"], first.1["intake_group_id"]);
    let described = request(&app, "GET", "/api/workflows/intake-entity", Value::Null).await.1;
    assert_eq!((described["input"].clone(), described["execution_deadline_at"].clone()), (original_input, original_deadline));
    assert_eq!(events(&app, "intake-entity", &run).await,
        ["StartAccepted", "WorkflowStarted", "SignalReceived", "StartAccepted", "SignalReceived"]);

    // Refusals write nothing: fail on an open run, another type, another
    // queue, a closed run by default, an undeclared signal or invalid input.
    let before = table_counts(&database).await;
    assert_refused(&signal_with_start(&app, "intake-entity", "finish", with(intake(3, None), "duplicate_policy", json!("fail"))).await,
        StatusCode::CONFLICT, "instance_already_started");
    assert_refused(&signal_with_start(&app, "intake-entity", "finish", with(intake(3, None), "workflow_type", json!("other"))).await,
        StatusCode::CONFLICT, "workflow_type_conflict");
    assert_refused(&signal_with_start(&app, "intake-entity", "finish", with(intake(3, None), "task_queue", json!("elsewhere"))).await,
        StatusCode::CONFLICT, "task_queue_conflict");
    assert_refused(&signal_with_start(&app, "intake-entity", "other", intake(3, None)).await,
        StatusCode::NOT_FOUND, "unknown_signal");
    let invalid = signal_with_start(&app, "intake-entity", "finish", with(intake(3, None), "signal_input", json!(["text"]))).await;
    assert_refused(&invalid, StatusCode::UNPROCESSABLE_ENTITY, "invalid_signal_arguments");
    assert_refused(&signal_with_start(&app, "intake-entity", "finish", with(intake(3, None), "closed_run_policy", json!("reopen"))).await,
        StatusCode::UNPROCESSABLE_ENTITY, "validation_failed");
    assert_refused(&signal_with_start(&app, "intake-entity", "finish", with(intake(3, None), "workflow_id", json!("someone-else"))).await,
        StatusCode::UNPROCESSABLE_ENTITY, "validation_failed");
    assert_eq!(table_counts(&database).await, before, "a refused intake writes nothing");

    // A new instance whose signal is refused is not started either.
    let unknown = signal_with_start(&app, "intake-unknown", "other", intake(1, None)).await;
    assert_refused(&unknown, StatusCode::NOT_FOUND, "unknown_signal");
    assert_eq!(request(&app, "GET", "/api/workflows/intake-unknown", Value::Null).await.0, StatusCode::NOT_FOUND);
    assert_eq!(table_counts(&database).await, before, "a refused new intake starts nothing");

    // A closed run is refused by default and keeps its outcome.
    complete(&app, "intake-worker").await;
    let closed_history = events(&app, "intake-entity", &run).await;
    assert_eq!(closed_history.last().map(String::as_str), Some("WorkflowCompleted"), "{closed_history:?}");
    let before = table_counts(&database).await;
    let closed = signal_with_start(&app, "intake-entity", "finish", intake(4, None)).await;
    assert_refused(&closed, StatusCode::CONFLICT, "run_not_active");
    assert_eq!((closed.1["run_id"].clone(), closed.1["run_status"].as_str()), (run.clone(), Some("completed")));
    assert_eq!(table_counts(&database).await, before);

    // An explicit start_new_run starts a fresh run after the closed one,
    // under its own input; the closed run and its history are unchanged.
    let fresh = signal_with_start(&app, "intake-entity", "finish", with(intake(5, None), "closed_run_policy", json!("start_new_run"))).await;
    assert_eq!(fresh.0, StatusCode::CREATED, "{}", fresh.1);
    assert_eq!((fresh.1["start_outcome"].as_str(), fresh.1["closed_run_id"].clone()), (Some("started_new"), run.clone()));
    assert_ne!(fresh.1["run_id"], run);
    assert_eq!(events(&app, "intake-entity", &run).await, closed_history);
    assert_eq!(events(&app, "intake-entity", &fresh.1["run_id"]).await, ["StartAccepted", "WorkflowStarted", "SignalReceived"]);
    let old = request(&app, "GET", &format!("/api/workflows/intake-entity/runs/{}", run.as_str().unwrap()), Value::Null).await.1;
    assert_eq!((old["status"].as_str(), old["run_number"].as_i64()), (Some("completed"), Some(1)), "{old}");
    let current = request(&app, "GET", "/api/workflows/intake-entity", Value::Null).await.1;
    assert_eq!((current["run_id"].clone(), current["run_number"].as_i64(), current["status"].as_str()),
        (fresh.1["run_id"].clone(), Some(2), Some("pending")), "{current}");
    let runs = request(&app, "GET", "/api/workflows/intake-entity/runs", Value::Null).await;
    assert_eq!(runs.0, StatusCode::OK, "{}", runs.1);
    let current_flags: Vec<(Value, Value)> = runs.1["runs"].as_array().unwrap().iter()
        .map(|listed| (listed["run_id"].clone(), listed["is_current_run"].clone())).collect();
    assert!(current_flags.contains(&(run.clone(), json!(false))), "{}", runs.1);
    assert!(current_flags.contains(&(fresh.1["run_id"].clone(), json!(true))), "{}", runs.1);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn keyed_intake_retries_return_the_original_pair_across_closure_and_races() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "keyed-intake-worker").await;

    // Concurrent retries of a new instance's intake admit one start and one
    // signal and all answer with that receipt.
    let racing: Vec<_> = (0..4).map(|_| signal_with_start(&app, "keyed-entity", "finish", intake(1, Some("lost-response")))).collect();
    let answers = futures_util::future::join_all(racing).await;
    let original = answers.iter().find(|answer| answer.1["retained"] == json!(false)).expect("one fresh admission").clone();
    assert_eq!(original.0, StatusCode::CREATED, "{}", original.1);
    assert_eq!(answers.iter().filter(|answer| answer.1["retained"] == json!(false)).count(), 1);
    for answer in &answers {
        assert_eq!(answer.0, StatusCode::CREATED, "{}", answer.1);
        for field in ["command_id", "run_id", "start_command_id", "start_outcome", "intake_group_id", "request_id"] {
            assert_eq!(answer.1[field], original.1[field], "{field}: {}", answer.1);
        }
    }
    let run = original.1["run_id"].clone();
    assert_eq!(events(&app, "keyed-entity", &run).await, ["StartAccepted", "WorkflowStarted", "SignalReceived"]);

    // The key binds the signal and workflow type only: a retry whose start
    // half differs still returns the original receipts and writes nothing.
    let before = table_counts(&database).await;
    let restarted = with(with(with(intake(1, Some("lost-response")), "input", json!([7])), "task_queue", json!("elsewhere")),
        "duplicate_policy", json!("fail"));
    let restarted = signal_with_start(&app, "keyed-entity", "finish", with(restarted, "closed_run_policy", json!("start_new_run"))).await;
    assert_eq!((restarted.0, restarted.1["command_id"].clone(), restarted.1["retained"].clone()),
        (StatusCode::CREATED, original.1["command_id"].clone(), json!(true)), "{}", restarted.1);
    assert_eq!(table_counts(&database).await, before);

    // A retry with a different intent conflicts and writes nothing; so does
    // a plain signal's key reused for an intake, and an intake's key reused
    // under another workflow type.
    let before = table_counts(&database).await;
    let conflict = signal_with_start(&app, "keyed-entity", "finish", intake(2, Some("lost-response"))).await;
    assert_refused(&conflict, StatusCode::CONFLICT, "request_id_conflict");
    assert_eq!((conflict.1["conflict"].clone(), conflict.1["retained_command_id"].clone()), (json!(["arguments"]), original.1["command_id"].clone()));
    let renamed = signal_with_start(&app, "keyed-entity", "other", intake(1, Some("lost-response"))).await;
    assert_eq!(renamed.1["conflict"], json!(["name"]), "{}", renamed.1);
    let retyped = signal_with_start(&app, "keyed-entity", "finish", with(intake(1, Some("lost-response")), "workflow_type", json!("other"))).await;
    assert_eq!(retyped.1["conflict"], json!(["workflow_type"]), "{}", retyped.1);
    assert_eq!(table_counts(&database).await, before);
    let plain = request(&app, "POST", "/api/workflows/keyed-entity/signal/finish",
        json!({"input":envelope(Payload::Array(vec![Payload::Long(1)])),"request_id":"plain-key"})).await;
    assert_eq!(plain.0, StatusCode::ACCEPTED, "{}", plain.1);
    let before = table_counts(&database).await;
    let reused = signal_with_start(&app, "keyed-entity", "finish", intake(1, Some("plain-key"))).await;
    assert_eq!((reused.0, reused.1["conflict"].clone()), (StatusCode::CONFLICT, json!(["operation"])), "{}", reused.1);
    assert_eq!(table_counts(&database).await, before);

    // After the run closes, even with start_new_run, the retry returns the
    // original pair and starts nothing.
    complete(&app, "keyed-intake-worker").await;
    let before = table_counts(&database).await;
    let closed = signal_with_start(&app, "keyed-entity", "finish",
        with(intake(1, Some("lost-response")), "closed_run_policy", json!("start_new_run"))).await;
    assert_eq!(closed.0, StatusCode::CREATED, "{}", closed.1);
    for field in ["command_id", "run_id", "start_command_id", "start_outcome", "intake_group_id"] {
        assert_eq!(closed.1[field], original.1[field], "{field}: {}", closed.1);
    }
    assert_eq!((closed.1["retained"].clone(), closed.1["intent_verified"].clone()), (json!(true), json!(true)));
    assert_eq!(table_counts(&database).await, before);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn intake_joins_the_continued_run_and_keeps_namespaces_apart() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    update_worker(&app, "continued-intake-worker").await;
    let started = signal_with_start(&app, "continued-entity", "finish", intake(1, Some("before-continue"))).await;
    assert_eq!(started.0, StatusCode::CREATED, "{}", started.1);
    // The signal lands while the run's first task is leased; the
    // continuation carries it to the successor run.
    let task = poll(&app, "continued-intake-worker", "workflow").await;
    let racing = signal_with_start(&app, "continued-entity", "finish", intake(2, None)).await;
    assert_eq!((racing.0, racing.1["run_id"].clone()), (StatusCode::ACCEPTED, started.1["run_id"].clone()), "{}", racing.1);
    // The held continue-as-new first delivers the racing signal, as for any
    // signal that lands during the lease; no accepted input is dropped.
    let mut task = task;
    let mut current = Value::Null;
    for _ in 0..5 {
        let (status, body) = finish_task(&app, &task, json!([{"type":"continue_as_new"}])).await;
        assert_eq!(status, StatusCode::OK, "{body}");
        current = request(&app, "GET", "/api/workflows/continued-entity", Value::Null).await.1;
        if current["run_id"] != started.1["run_id"] {
            break;
        }
        task = poll(&app, "continued-intake-worker", "workflow").await;
        assert_eq!(task["run_id"], started.1["run_id"], "{task}");
    }
    assert_ne!(current["run_id"], started.1["run_id"], "{current}");
    let original = events(&app, "continued-entity", &started.1["run_id"]).await;
    assert_eq!(original.iter().filter(|kind| *kind == "SignalReceived").count(), 2, "{original:?}");
    assert_eq!(original.iter().filter(|kind| *kind == "SignalApplied").count(), 2, "{original:?}");
    let joined = signal_with_start(&app, "continued-entity", "finish", intake(3, None)).await;
    assert_eq!(joined.0, StatusCode::ACCEPTED, "{}", joined.1);
    assert_eq!((joined.1["run_id"].clone(), joined.1["start_outcome"].as_str()), (current["run_id"].clone(), Some("returned_existing_active")));
    let retry = signal_with_start(&app, "continued-entity", "finish", intake(1, Some("before-continue"))).await;
    assert_eq!((retry.1["command_id"].clone(), retry.1["run_id"].clone()), (started.1["command_id"].clone(), started.1["run_id"].clone()));

    // An instance another namespace holds is refused without a write; the
    // foreign namespace cannot see or signal this one.
    create(&app, "intake-beta").await;
    let before = table_counts(&database).await;
    let foreign = scoped(&app, "intake-beta", "POST", "/api/workflows/continued-entity/signal-with-start/finish", intake(4, None)).await;
    assert_eq!((foreign.0, foreign.1["reason"].as_str()), (StatusCode::CONFLICT, Some("workflow_id_reserved_in_namespace")), "{}", foreign.1);
    assert_eq!(table_counts(&database).await, before);
    let capabilities = request(&app, "GET", "/api/cluster/info", Value::Null).await.1;
    assert_eq!(capabilities["capabilities"]["signal_with_start"], json!(true));
    runtime.close().await;
    database.remove().await;
}

fn received(history: &[String]) -> usize {
    history.iter().filter(|kind| *kind == "SignalReceived").count()
}

async fn runs(app: &Router, workflow: &str) -> Vec<Value> {
    request(app, "GET", &format!("/api/workflows/{workflow}/runs"), Value::Null).await.1["runs"].as_array().unwrap().clone()
}

#[tokio::test]
async fn concurrent_intakes_share_one_run_and_keep_every_signal() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());

    // Unkeyed calls on a new instance: one start, twelve signals.
    let racing: Vec<_> = (0..12).map(|value| signal_with_start(&app, "racing-entity", "finish", intake(value, None))).collect();
    let answers = futures_util::future::join_all(racing).await;
    let run = answers[0].1["run_id"].clone();
    assert_eq!(answers.iter().filter(|answer| answer.0 == StatusCode::CREATED).count(), 1);
    for answer in &answers {
        assert!(matches!(answer.0, StatusCode::CREATED | StatusCode::ACCEPTED), "{}", answer.1);
        assert_eq!(answer.1["run_id"], run, "{}", answer.1);
    }
    assert_eq!(runs(&app, "racing-entity").await.len(), 1);
    assert_eq!(received(&events(&app, "racing-entity", &run).await), 12);

    // start_new_run calls racing on a terminated run: one new run, eight signals.
    let (status, body) = request(&app, "POST", "/api/workflows/racing-entity/terminate", json!({"reason":"close"})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let fresh: Vec<_> = (0..8)
        .map(|value| signal_with_start(&app, "racing-entity", "finish", with(intake(value, None), "closed_run_policy", json!("start_new_run"))))
        .collect();
    let answers = futures_util::future::join_all(fresh).await;
    let second = answers.iter().find(|answer| answer.0 == StatusCode::CREATED).expect("one new run").1["run_id"].clone();
    assert_eq!(answers.iter().filter(|answer| answer.0 == StatusCode::CREATED).count(), 1);
    for answer in &answers {
        assert_eq!(answer.1["run_id"], second, "{}", answer.1);
    }
    assert_ne!(second, run);
    assert_eq!(runs(&app, "racing-entity").await.len(), 2);
    assert_eq!(received(&events(&app, "racing-entity", &second).await), 8);
    assert_eq!(received(&events(&app, "racing-entity", &run).await), 12, "the terminated run is unchanged");

    // A call racing termination either joins the open run before it closes
    // or is refused; it never starts a run or loses an accepted signal.
    let (intake_answer, terminated) = tokio::join!(
        signal_with_start(&app, "racing-entity", "finish", intake(99, None)),
        request(&app, "POST", "/api/workflows/racing-entity/terminate", json!({"reason":"race"})),
    );
    assert_eq!(terminated.0, StatusCode::OK, "{}", terminated.1);
    match intake_answer.0 {
        StatusCode::ACCEPTED => {
            assert_eq!(intake_answer.1["run_id"], second, "{}", intake_answer.1);
            assert_eq!(received(&events(&app, "racing-entity", &second).await), 9);
        }
        StatusCode::CONFLICT => {
            assert_eq!(intake_answer.1["reason"], "run_not_active", "{}", intake_answer.1);
            assert_eq!(received(&events(&app, "racing-entity", &second).await), 8);
        }
        other => panic!("{other}: {}", intake_answer.1),
    }
    assert_eq!(runs(&app, "racing-entity").await.len(), 2);

    // A key is scoped to its instance: the same key elsewhere is independent.
    let first = signal_with_start(&app, "keyed-a", "finish", intake(1, Some("shared-key"))).await;
    let other = signal_with_start(&app, "keyed-b", "finish", intake(1, Some("shared-key"))).await;
    assert_eq!((first.0, other.0), (StatusCode::CREATED, StatusCode::CREATED), "{} {}", first.1, other.1);
    assert_eq!((first.1["retained"].clone(), other.1["retained"].clone()), (json!(false), json!(false)));
    assert_ne!(first.1["command_id"], other.1["command_id"]);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn two_nodes_racing_one_new_instance_admit_every_intake_once() {
    let database = TestDatabase::new().await;
    let node_a = database.open().await.unwrap();
    let node_b = database.open().await.unwrap();
    let (app_a, app_b) = (router(node_a.clone()), router(node_b.clone()));
    let racing: Vec<_> = (0..6)
        .map(|value| {
            let app = if value % 2 == 0 { app_a.clone() } else { app_b.clone() };
            async move { signal_with_start(&app, "two-node-entity", "finish", intake(value, None)).await }
        })
        .collect();
    let answers = futures_util::future::join_all(racing).await;
    let run = answers[0].1["run_id"].clone();
    assert_eq!(answers.iter().filter(|answer| answer.0 == StatusCode::CREATED).count(), 1, "{answers:?}");
    for answer in &answers {
        assert!(matches!(answer.0, StatusCode::CREATED | StatusCode::ACCEPTED), "{}", answer.1);
        assert_eq!(answer.1["run_id"], run, "{}", answer.1);
    }
    assert_eq!(runs(&app_a, "two-node-entity").await.len(), 1);
    assert_eq!(received(&events(&app_a, "two-node-entity", &run).await), 6);
    node_a.close().await;
    node_b.close().await;
    database.remove().await;
}

#[tokio::test]
async fn every_closed_status_and_the_payload_ceiling_refuse_without_a_write() {
    use durable_workflow_server::runtime::StructuralLimits;
    let database = TestDatabase::new().await;
    let limits = StructuralLimits { payload_bytes: Some(300), ..StructuralLimits::default() };
    let runtime = database.open().await.unwrap().with_structural_limits(limits);
    let app = router(runtime.clone());
    update_worker(&app, "closed-worker").await;

    // Failed, cancelled and terminated runs are refused like a completed one,
    // and start_new_run starts after each.
    for (workflow, status) in [("closed-failed", "failed"), ("closed-cancelled", "cancelled"), ("closed-terminated", "terminated")] {
        let started = signal_with_start(&app, workflow, "finish", intake(1, None)).await;
        assert_eq!(started.0, StatusCode::CREATED, "{}", started.1);
        match status {
            "failed" => {
                let task = poll(&app, "closed-worker", "workflow").await;
                let (code, body) = finish_task(&app, &task, json!([{"type":"fail_workflow","message":"closed for the intake"}])).await;
                assert_eq!(code, StatusCode::OK, "{body}");
            }
            "cancelled" => assert_eq!(request(&app, "POST", &format!("/api/workflows/{workflow}/cancel"), json!({})).await.0, StatusCode::OK),
            _ => assert_eq!(request(&app, "POST", &format!("/api/workflows/{workflow}/terminate"), json!({})).await.0, StatusCode::OK),
        }
        let before = table_counts(&database).await;
        let refused = signal_with_start(&app, workflow, "finish", intake(2, None)).await;
        assert_refused(&refused, StatusCode::CONFLICT, "run_not_active");
        assert_eq!(refused.1["run_status"], status, "{}", refused.1);
        assert_eq!(table_counts(&database).await, before, "{status}");
        let fresh = signal_with_start(&app, workflow, "finish", with(intake(3, None), "closed_run_policy", json!("start_new_run"))).await;
        assert_eq!((fresh.0, fresh.1["closed_run_id"].clone()), (StatusCode::CREATED, started.1["run_id"].clone()), "{}", fresh.1);
    }

    // A signal over the payload ceiling is refused on a new instance and on
    // an open run alike, with no start, signal or rejection record.
    // A queue without a declared contract, so only the ceiling applies.
    let untyped = |value: Value| with(with(intake(1, None), "task_queue", json!("untyped")), "signal_input", value);
    let oversized = envelope(Payload::Array(vec![Payload::String("x".repeat(400))]));
    let open = signal_with_start(&app, "oversized-open", "finish", untyped(envelope(Payload::Array(vec![Payload::String("ok".into())])))).await;
    assert_eq!(open.0, StatusCode::CREATED, "{}", open.1);
    let before = table_counts(&database).await;
    let new = signal_with_start(&app, "oversized-entity", "finish", untyped(oversized.clone())).await;
    assert_refused(&new, StatusCode::CONFLICT, "structural_limit_exceeded");
    assert_eq!(request(&app, "GET", "/api/workflows/oversized-entity", Value::Null).await.0, StatusCode::NOT_FOUND);
    let open = signal_with_start(&app, "oversized-open", "finish", untyped(oversized)).await;
    assert_refused(&open, StatusCode::CONFLICT, "structural_limit_exceeded");
    assert_eq!(table_counts(&database).await, before);

    // A blank signal name is refused as the signal route refuses it.
    let blank = signal_with_start(&app, "blank-entity", "%20%20", intake(1, None)).await;
    assert_eq!((blank.0, blank.1["reason"].as_str()), (StatusCode::UNPROCESSABLE_ENTITY, Some("invalid_signal_name")), "{}", blank.1);
    let plain = request(&app, "POST", "/api/workflows/closed-failed/signal/%20%20", json!({})).await;
    assert_eq!(plain.1["reason"], blank.1["reason"], "{}", plain.1);
    assert_eq!(table_counts(&database).await, before);
    runtime.close().await;
    database.remove().await;
}
