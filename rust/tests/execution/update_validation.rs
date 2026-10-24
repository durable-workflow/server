use super::*;
use std::time::Duration;

// Server 2.11.1's synchronous update validation, as observed against the
// published image: a declared validator decides an update before it is
// accepted, through validation tasks a capable worker claims.

fn int_contract(name: &str) -> Value {
    json!({"name":name,"parameters":[{"name":"value","type":"int","required":true,"variadic":false,
        "default_available":false,"allows_null":false}]})
}

async fn validating_worker(app: &Router, worker: &str, queue: &str, validators: Option<Value>, capable: bool) {
    let updates = ["approve_me", "plain", "reject_me"];
    let mut contract = json!({"signals":["finish"],"signal_contracts":[int_contract("finish")],"queries":[],
        "updates":updates,"update_contracts":updates.iter().map(|name| int_contract(name)).collect::<Vec<_>>()});
    if let Some(validators) = validators {
        contract["update_validators"] = validators;
    }
    let mut capabilities = vec!["workflow_updates"];
    if capable {
        capabilities.push("update_validation_tasks");
    }
    let (status, body) = request(app, "POST", "/api/worker/register", json!({"worker_id":worker,"task_queue":queue,
        "runtime":"python","supported_workflow_types":["echo"],"supported_activity_types":[],
        "capabilities":capabilities,"workflow_command_contracts":{"echo":contract}})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

/// A run on `queue` waiting for a signal, so updates find it idle.
async fn waiting_run(app: &Router, workflow: &str, queue: &str, worker: &str) -> Value {
    let (status, started) = request(app, "POST", "/api/workflows", json!({"workflow_id":workflow,"workflow_type":"echo",
        "task_queue":queue,"input":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    let (status, polled) = request(app, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":worker,"task_queue":queue,"timeout_seconds":0})).await;
    assert_eq!(status, StatusCode::OK, "{polled}");
    let task = &polled["task"];
    assert_eq!(task["task_kind"], "workflow", "{polled}");
    assert_eq!(finish_task(app, task, json!([{"type":"open_signal_wait","signal_name":"finish"}])).await.0, StatusCode::OK);
    started
}

fn update_body(value: i64, request_id: Option<&str>) -> Value {
    let mut body = json!({"input":envelope(Payload::Array(vec![Payload::Long(value)]))});
    if let Some(request_id) = request_id {
        body["request_id"] = json!(request_id);
    }
    body
}

/// An update request that waits for its validator, answered later.
fn send_update(app: &Router, workflow: &str, name: &str, body: Value) -> tokio::task::JoinHandle<(StatusCode, Value)> {
    let app = app.clone();
    let path = format!("/api/workflows/{workflow}/update/{name}");
    tokio::spawn(async move { request(&app, "POST", &path, body).await })
}

async fn poll_validation(app: &Router, worker: &str, queue: &str) -> Value {
    let (status, body) = request(app, "POST", "/api/worker/update-validation-tasks/poll",
        json!({"worker_id":worker,"task_queue":queue,"timeout_seconds":5})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["poll_status"], "leased", "{body}");
    body["task"].clone()
}

async fn complete(app: &Router, task: &str, verdict: &str, body: Value) -> (StatusCode, Value) {
    request(app, "POST", &format!("/api/worker/update-validation-tasks/{task}/{verdict}"), body).await
}

fn events<'a>(history: &'a Value, kind: &str) -> Vec<&'a Value> {
    history.as_array().unwrap().iter().filter(|event| event["event_type"] == kind).collect()
}

#[tokio::test]
async fn validators_approve_and_reject_updates_before_acceptance() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    validating_worker(&app, "validator", "vq", Some(json!(["approve_me", "reject_me"])), true).await;
    let started = waiting_run(&app, "validated", "vq", "validator").await;
    let history = || run_history(&app, &started["workflow_id"], &started["run_id"]);
    let before = history().await;
    let started_event = events(&before, "WorkflowStarted")[0];
    assert_eq!(started_event["payload"]["declared_update_validators"], json!(["approve_me", "reject_me"]));

    // Refusals before validation record nothing.
    let (status, body) = request(&app, "POST", "/api/workflows/validated/update/approve_me", update_body(1, None)).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    assert_eq!(body["reason"], "update_validation_request_id_required");
    assert_eq!(body["outcome"], "update_validation_failed");
    assert_eq!(body["retryable"], true);
    assert_eq!(body["wait_for"], "accepted");
    assert!(body.get("update_id").is_none() && body.get("approved").is_none(), "{body}");
    let mut wrong = json!({"input":envelope(Payload::Array(vec![Payload::String("x".into())])),"request_id":"bad"});
    let (status, body) = request(&app, "POST", "/api/workflows/validated/update/approve_me", wrong.take()).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    assert_eq!(body["reason"], "invalid_update_arguments");
    assert_eq!(body["message"], "Workflow update [approve_me] argument validation failed.");
    assert_eq!(body["validation_errors"], json!({"value":["The value argument must be of type int."]}));
    assert_eq!(history().await, before);

    // A rejection through the multiplexed poll.
    let pending = send_update(&app, "validated", "reject_me", update_body(2, Some("r2")));
    let (status, polled) = request(&app, "POST", "/api/worker/workflow-tasks/poll", json!({"worker_id":"validator",
        "task_queue":"vq","timeout_seconds":5,"task_kinds":["workflow","update_validation"]})).await;
    assert_eq!(status, StatusCode::OK, "{polled}");
    let task = polled["task"].clone();
    assert_eq!(task["task_kind"], "update_validation", "{polled}");
    assert_eq!(task["update_name"], "reject_me");
    assert_eq!(task["update_validation_attempt"], 1);
    assert_eq!(task["update_id"], task["update_validation_task_id"]);
    assert_eq!(task["lease_owner"], "validator");
    assert!(task["next_history_page_token"].is_null() && task.get("next_history_page_token").is_some());
    assert_eq!(task["update_arguments"], update_body(2, None)["input"]);
    assert!(task["history_events"].as_array().is_some_and(|events| !events.is_empty()), "{task}");
    assert!(task["history_export"].is_object(), "{task}");
    let id = task["update_validation_task_id"].as_str().unwrap().to_owned();
    let (status, body) = complete(&app, &id, "reject", json!({"lease_owner":"validator","update_validation_attempt":1})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    assert_eq!(body["errors"], json!({"failure":["The failure field is required."],"failure.message":["The failure.message field is required."]}));
    let (status, body) = complete(&app, &id, "reject", json!({"lease_owner":"validator","update_validation_attempt":1,
        "failure":{"message":"too small","validation_errors":{"v":["too small"]}}})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["outcome"], "rejected");
    assert_eq!(body["reason"], "update_validator_rejected");
    let (status, rejected) = pending.await.unwrap();
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{rejected}");
    for (field, expected) in [
        ("approved", json!(false)),
        ("accepted", json!(false)),
        ("outcome", json!("rejected_invalid_arguments")),
        ("reason", json!("update_validator_rejected")),
        ("update_status", json!("rejected")),
        ("command_status", json!("rejected")),
        ("message", json!("too small")),
        ("validation_errors", json!({"v":["too small"]})),
        ("update_validation_attempt", json!(1)),
        ("update_id", json!(id)),
        ("wait_timed_out", json!(false)),
    ] {
        assert_eq!(rejected[field], expected, "{field}: {rejected}");
    }
    let (status, again) = complete(&app, &id, "reject", json!({"lease_owner":"validator","update_validation_attempt":1,
        "failure":{"message":"again"}})).await;
    assert_eq!(status, StatusCode::CONFLICT, "{again}");
    assert_eq!(again["reason"], "duplicate_update_validation_completion");

    // An approval through the dedicated poll.
    let pending = send_update(&app, "validated", "approve_me", update_body(3, Some("r1")));
    let task = poll_validation(&app, "validator", "vq").await;
    let id = task["update_validation_task_id"].as_str().unwrap().to_owned();
    assert!(task.get("next_history_page_token").is_none(), "{task}");
    let (status, body) = complete(&app, &id, "approve", json!({"lease_owner":"someone","update_validation_attempt":1})).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::CONFLICT, json!("update_validation_lease_owner_mismatch")));
    let (status, body) = complete(&app, &id, "approve", json!({"lease_owner":"validator","update_validation_attempt":2})).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::CONFLICT, json!("stale_update_validation_completion")));
    let (status, body) = complete(&app, &id, "approve", json!({"lease_owner":"validator","update_validation_attempt":1})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body, json!({"update_validation_task_id":id,"update_id":id,"update_validation_attempt":1,"outcome":"approved",
        "status":200,"protocol_version":body["protocol_version"],"server_capabilities":body["server_capabilities"]}));
    let (status, approved) = pending.await.unwrap();
    assert_eq!(status, StatusCode::ACCEPTED, "{approved}");
    assert_eq!(approved["update_id"], json!(id));
    assert_eq!(approved["update_status"], "accepted");
    let (status, unknown) = complete(&app, "01ZZZZZZZZZZZZZZZZZZZZZZZZ", "approve",
        json!({"lease_owner":"validator","update_validation_attempt":1})).await;
    assert_eq!(status, StatusCode::NOT_FOUND, "{unknown}");
    assert!(unknown.get("update_validation_attempt").is_none(), "{unknown}");

    // Retries return the recorded receipts; an undeclared validator is not asked.
    let (status, retained) = request(&app, "POST", "/api/workflows/validated/update/approve_me", update_body(99, Some("r1"))).await;
    assert_eq!((status, retained["update_id"].clone()), (StatusCode::ACCEPTED, json!(id)));
    let (status, retained) = request(&app, "POST", "/api/workflows/validated/update/reject_me", update_body(2, Some("r2"))).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{retained}");
    assert_eq!(retained["reason"], "update_validator_rejected");
    assert_eq!(retained["validation_errors"], json!({"v":["too small"]}));
    let (status, plain) = request(&app, "POST", "/api/workflows/validated/update/plain", update_body(4, Some("p1"))).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{plain}");

    let after = history().await;
    let rejected_events = events(&after, "UpdateRejected");
    assert_eq!(rejected_events.len(), 1, "{after}");
    assert_eq!(rejected_events[0]["payload"]["update_id"], rejected["update_id"]);
    assert_eq!(rejected_events[0]["payload"]["validation_errors"], json!({"v":["too small"]}));
    let accepted = events(&after, "UpdateAccepted");
    assert_eq!(accepted.len(), 2, "{after}");
    assert_eq!(accepted[0]["payload"]["update_id"], json!(id));
    assert_eq!(accepted[0]["payload"]["request_id"], "r1");
}

#[tokio::test]
async fn validation_routes_time_out_and_fence_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    // A worker contract without validators makes every remote update refuse.
    validating_worker(&app, "silent", "q-missing", None, true).await;
    waiting_run(&app, "missing", "q-missing", "silent").await;
    let (status, body) = request(&app, "POST", "/api/workflows/missing/update/plain", update_body(1, None)).await;
    assert_eq!(status, StatusCode::CONFLICT, "{body}");
    assert_eq!(body["reason"], "update_validator_contract_missing");
    assert_eq!(body["retryable"], false);

    // No capable worker, or none matching the run.
    validating_worker(&app, "incapable", "q-cap", Some(json!(["approve_me"])), false).await;
    waiting_run(&app, "incapable-run", "q-cap", "incapable").await;
    let (status, body) = request(&app, "POST", "/api/workflows/incapable-run/update/approve_me", update_body(1, Some("c1"))).await;
    assert_eq!(status, StatusCode::CONFLICT, "{body}");
    assert_eq!((body["reason"].clone(), body["retryable"].clone()), (json!("update_validation_capability_unsupported"), json!(false)));
    assert_eq!(body["approved"], false);
    assert!(body["update_id"].is_string(), "{body}");
    let (status, body) = request(&app, "POST", "/api/worker/update-validation-tasks/poll",
        json!({"worker_id":"incapable","task_queue":"q-cap","timeout_seconds":0})).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::CONFLICT, json!("update_validation_capability_not_advertised")));
    let (status, body) = request(&app, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"incapable","task_queue":"q-cap","timeout_seconds":0,"task_kinds":["update_validation"]})).await;
    assert_eq!((status, body["poll_status"].clone()), (StatusCode::CONFLICT, json!("unsupported")));
    validating_worker(&app, "changed", "q-changed", Some(json!(["approve_me"])), true).await;
    waiting_run(&app, "changed-run", "q-changed", "changed").await;
    validating_worker(&app, "changed", "q-changed", Some(json!([])), true).await;
    let (status, body) = request(&app, "POST", "/api/workflows/changed-run/update/approve_me", update_body(1, Some("i1"))).await;
    assert_eq!(status, StatusCode::CONFLICT, "{body}");
    assert_eq!((body["reason"].clone(), body["retryable"].clone()), (json!("update_validator_worker_incompatible"), json!(true)));
    let (status, body) = request(&app, "POST", "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"changed","task_queue":"q-changed","timeout_seconds":0,"task_kinds":["workflow","activity"]})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    assert_eq!(body["errors"], json!({"task_kinds.1":["The selected task_kinds.1 is invalid."]}));

    // Nobody claims: the request waits out the timeout, and retries replay it.
    validating_worker(&app, "idle", "q-idle", Some(json!(["approve_me"])), true).await;
    waiting_run(&app, "idle-run", "q-idle", "idle").await;
    let unclaimed = send_update(&app, "idle-run", "approve_me", update_body(5, Some("r3")));

    // A claimed task whose worker leaves.
    validating_worker(&app, "leaver", "q-lost", Some(json!(["approve_me"])), true).await;
    waiting_run(&app, "lost-run", "q-lost", "leaver").await;
    let lost = send_update(&app, "lost-run", "approve_me", update_body(6, Some("l1")));
    let task = poll_validation(&app, "leaver", "q-lost").await;
    let lost_id = task["update_validation_task_id"].as_str().unwrap().to_owned();
    assert_eq!(request(&app, "DELETE", "/api/workers/leaver", Value::Null).await.0, StatusCode::OK);
    let (status, body) = complete(&app, &lost_id, "approve", json!({"lease_owner":"leaver","update_validation_attempt":1})).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::CONFLICT, json!("update_validator_worker_lost")));

    let (status, body) = unclaimed.await.unwrap();
    assert_eq!(status, StatusCode::GATEWAY_TIMEOUT, "{body}");
    assert_eq!(body["reason"], "update_validation_task_not_claimed");
    assert_eq!(body["update_validation_attempt"], 0);
    let (status, again) = request(&app, "POST", "/api/workflows/idle-run/update/approve_me", update_body(5, Some("r3"))).await;
    assert_eq!((status, again["update_id"].clone()), (StatusCode::GATEWAY_TIMEOUT, body["update_id"].clone()));
    let (status, conflict) = request(&app, "POST", "/api/workflows/idle-run/update/approve_me", update_body(6, Some("r3"))).await;
    assert_eq!((status, conflict["reason"].clone()), (StatusCode::CONFLICT, json!("update_validation_idempotency_conflict")));
    let (status, body) = lost.await.unwrap();
    assert_eq!(status, StatusCode::GATEWAY_TIMEOUT, "{body}");
    assert_eq!(body["reason"], "update_validator_worker_lost");

    // With no worker left on the queue.
    let (status, body) = request(&app, "POST", "/api/workflows/lost-run/update/approve_me", update_body(7, Some("u1"))).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::CONFLICT, json!("update_validator_worker_unavailable")));
}

#[tokio::test]
async fn expired_validation_leases_are_claimed_again() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    validating_worker(&app, "slow", "q-slow", Some(json!(["approve_me", "reject_me"])), true).await;
    waiting_run(&app, "slow-run", "q-slow", "slow").await;
    let pending = send_update(&app, "slow-run", "approve_me", update_body(8, Some("r4")));
    let first = poll_validation(&app, "slow", "q-slow").await;
    let id = first["update_validation_task_id"].as_str().unwrap().to_owned();
    tokio::time::sleep(Duration::from_secs(6)).await;
    let (status, body) = complete(&app, &id, "approve", json!({"lease_owner":"slow","update_validation_attempt":1})).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::CONFLICT, json!("update_validation_lease_expired")));
    let second = poll_validation(&app, "slow", "q-slow").await;
    assert_eq!((second["update_validation_task_id"].clone(), second["update_validation_attempt"].clone()), (json!(id), json!(2)));
    let (status, body) = complete(&app, &id, "approve", json!({"lease_owner":"slow","update_validation_attempt":1})).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::CONFLICT, json!("stale_update_validation_completion")));
    assert_eq!(complete(&app, &id, "approve", json!({"lease_owner":"slow","update_validation_attempt":2})).await.0, StatusCode::OK);
    let (status, body) = pending.await.unwrap();
    assert_eq!((status, body["update_id"].clone()), (StatusCode::ACCEPTED, json!(id)));

    // A failure other than a validator rejection records no update.
    let pending = send_update(&app, "slow-run", "reject_me", update_body(9, Some("r5")));
    let task = poll_validation(&app, "slow", "q-slow").await;
    let id = task["update_validation_task_id"].as_str().unwrap().to_owned();
    let (status, body) = complete(&app, &id, "reject", json!({"lease_owner":"slow","update_validation_attempt":1,
        "failure":{"message":"validator crashed","reason":"validator_error","type":"RuntimeError","stack_trace":"trace"}})).await;
    assert_eq!((status, body["outcome"].clone()), (StatusCode::OK, json!("failed")));
    let (status, failed) = pending.await.unwrap();
    assert_eq!(status, StatusCode::CONFLICT, "{failed}");
    assert_eq!((failed["reason"].clone(), failed["failure_type"].clone()), (json!("validator_error"), json!("RuntimeError")));
    assert!(failed.get("update_status").is_none(), "{failed}");

    // The multiplexed poll alternates kinds after each lease.
    assert_eq!(request(&app, "POST", "/api/workflows/slow-run/update/plain", update_body(10, Some("p2"))).await.0, StatusCode::ACCEPTED);
    let pending = send_update(&app, "slow-run", "approve_me", update_body(11, Some("r6")));
    tokio::time::sleep(Duration::from_millis(300)).await;
    let mut kinds = vec![];
    for _ in 0..2 {
        let (status, body) = request(&app, "POST", "/api/worker/workflow-tasks/poll", json!({"worker_id":"slow",
            "task_queue":"q-slow","timeout_seconds":1,"task_kinds":["update_validation","workflow"]})).await;
        assert_eq!(status, StatusCode::OK, "{body}");
        let task = &body["task"];
        kinds.push(task["task_kind"].clone());
        if task["task_kind"] == "update_validation" {
            let id = task["update_validation_task_id"].as_str().unwrap();
            assert_eq!(complete(&app, id, "approve", json!({"lease_owner":"slow","update_validation_attempt":1})).await.0, StatusCode::OK);
        }
    }
    assert_eq!(kinds, vec![json!("update_validation"), json!("workflow")]);
    assert_eq!(pending.await.unwrap().0, StatusCode::ACCEPTED);
}

// Poll request IDs with validation tasks (`WorkflowTaskPollRequestStore` and
// `WorkflowTaskKindSelector`), as recorded against published 2.11.1.

async fn poll_kinds(app: &Router, worker: &str, queue: &str, request_id: Option<&str>, kinds: Option<Value>) -> (StatusCode, Value) {
    let mut body = json!({"worker_id":worker,"task_queue":queue,"timeout_seconds":1});
    if let Some(id) = request_id {
        body["poll_request_id"] = json!(id);
    }
    if let Some(kinds) = kinds {
        body["task_kinds"] = kinds;
    }
    request(app, "POST", "/api/worker/workflow-tasks/poll", body).await
}

/// A delivery's kind, task and attempt.
fn delivery(body: &Value) -> (Value, Value, Value) {
    let task = &body["task"];
    let validation = task["task_kind"] == "update_validation";
    let (id, attempt) = if validation { ("update_validation_task_id", "update_validation_attempt") } else { ("task_id", "workflow_task_attempt") };
    (task["task_kind"].clone(), task[id].clone(), task[attempt].clone())
}

/// A 409 for an ID bound to another kind set, field by field.
fn assert_kinds_conflict(status: StatusCode, body: &Value, id: &str, requested: Value, bound: Value) {
    assert_eq!(status, StatusCode::CONFLICT, "{body}");
    let expected = json!({"task":null,"poll_status":"conflict","reason":"poll_request_task_kinds_conflict",
        "error":"Poll request ID is already bound to a different task-kind set.","poll_request_id":id,
        "requested_task_kinds":requested,"bound_task_kinds":bound});
    for (key, value) in expected.as_object().unwrap() {
        assert_eq!(&body[key], value, "{key}: {body}");
    }
}

async fn start_on(app: &Router, workflow: &str, queue: &str) {
    let (status, started) = request(app, "POST", "/api/workflows", json!({"workflow_id":workflow,"workflow_type":"echo",
        "task_queue":queue,"input":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
}

#[tokio::test]
async fn poll_request_ids_bind_their_normalized_kind_set() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    validating_worker(&app, "pr", "q-pr", Some(json!(["approve_me"])), true).await;
    waiting_run(&app, "pr-run", "q-pr", "pr").await;
    let pending = send_update(&app, "pr-run", "approve_me", update_body(21, Some("pr1")));
    tokio::time::sleep(Duration::from_millis(300)).await;
    let both = || Some(json!(["workflow", "update_validation"]));

    // A fresh cursor tries validation first.
    let (status, first) = poll_kinds(&app, "pr", "q-pr", Some("pr-x"), both()).await;
    assert_eq!(status, StatusCode::OK, "{first}");
    let leased = delivery(&first);
    assert_eq!((leased.0.clone(), leased.2.clone()), (json!("update_validation"), json!(1)), "{first}");
    let task_id = leased.1.as_str().unwrap().to_owned();
    // Either order, padded or not, is the same ID and set: the same delivery.
    for (id, kinds) in [("pr-x", json!(["update_validation", "workflow"])), (" pr-x ", json!(["workflow", "update_validation"]))] {
        let (status, replay) = poll_kinds(&app, "pr", "q-pr", Some(id), Some(kinds)).await;
        assert_eq!((status, delivery(&replay)), (StatusCode::OK, leased.clone()), "{id}: {replay}");
    }
    // Another set is refused, and the lease stays held.
    for kinds in [json!(["workflow"]), json!(["update_validation"])] {
        let (status, refused) = poll_kinds(&app, "pr", "q-pr", Some("pr-x"), Some(kinds.clone())).await;
        assert_kinds_conflict(status, &refused, "pr-x", kinds, json!(["update_validation", "workflow"]));
    }
    // Once the lease expires the ID polls afresh: the same task, attempt 2,
    // which it then replays.
    tokio::time::sleep(Duration::from_secs(6)).await;
    for _ in 0..2 {
        let (status, again) = poll_kinds(&app, "pr", "q-pr", Some("pr-x"), both()).await;
        assert_eq!((status, delivery(&again)), (StatusCode::OK, (json!("update_validation"), json!(task_id), json!(2))), "{again}");
    }
    let approved = complete(&app, &task_id, "approve", json!({"lease_owner":"pr","update_validation_attempt":2})).await;
    assert_eq!(approved.0, StatusCode::OK, "{}", approved.1);
    assert_eq!(pending.await.unwrap().0, StatusCode::ACCEPTED);

    // A default ID binds to the workflow kind alone.
    let (status, default) = poll_kinds(&app, "pr", "q-pr", Some("pr-z"), None).await;
    assert_eq!(status, StatusCode::OK, "{default}");
    let workflow = delivery(&default);
    assert_eq!((workflow.0.clone(), workflow.2.clone()), (json!("workflow"), json!(1)), "{default}");
    let (status, refused) = poll_kinds(&app, "pr", "q-pr", Some("pr-z"), Some(json!(["update_validation", "workflow"]))).await;
    assert_kinds_conflict(status, &refused, "pr-z", json!(["update_validation", "workflow"]), json!(["workflow"]));
    for kinds in [None, Some(json!(["workflow"]))] {
        let (status, replay) = poll_kinds(&app, "pr", "q-pr", Some("pr-z"), kinds).await;
        assert_eq!((status, delivery(&replay)), (StatusCode::OK, workflow.clone()), "{replay}");
    }
}

#[tokio::test]
async fn a_completed_validation_delivery_polls_afresh_under_its_id() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    validating_worker(&app, "fresh", "q-fresh", Some(json!(["approve_me"])), true).await;
    waiting_run(&app, "fresh-run", "q-fresh", "fresh").await;
    let pending = send_update(&app, "fresh-run", "approve_me", update_body(5, Some("f1")));
    tokio::time::sleep(Duration::from_millis(300)).await;
    let both = || Some(json!(["update_validation", "workflow"]));
    let (_, first) = poll_kinds(&app, "fresh", "q-fresh", Some("fresh-id"), both()).await;
    let (kind, task, attempt) = delivery(&first);
    assert_eq!((kind, attempt), (json!("update_validation"), json!(1)), "{first}");
    let approved = complete(&app, task.as_str().unwrap(), "approve", json!({"lease_owner":"fresh","update_validation_attempt":1})).await;
    assert_eq!(approved.0, StatusCode::OK, "{}", approved.1);
    assert_eq!(pending.await.unwrap().0, StatusCode::ACCEPTED);
    // The approval is no longer deliverable: the same ID leases the update's
    // workflow task (the cursor turned to workflow), then replays it.
    let (status, next) = poll_kinds(&app, "fresh", "q-fresh", Some("fresh-id"), both()).await;
    assert_eq!(status, StatusCode::OK, "{next}");
    let workflow = delivery(&next);
    assert_eq!((workflow.0.clone(), workflow.2.clone()), (json!("workflow"), json!(1)), "{next}");
    let (_, replay) = poll_kinds(&app, "fresh", "q-fresh", Some("fresh-id"), both()).await;
    assert_eq!(delivery(&replay), workflow, "{replay}");
    // With nothing else ready, a fresh ID answers empty and replays empty.
    for _ in 0..2 {
        let (status, empty) = poll_kinds(&app, "fresh", "q-fresh", Some("empty-id"), both()).await;
        assert_eq!((status, empty["poll_status"].clone(), empty["task"].clone()), (StatusCode::OK, json!("empty"), Value::Null), "{empty}");
    }
}

// Concurrent polls with one ID share one delivery; the queue's cursor turns
// once per lease, so a new ID leases the other kind.
#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
async fn concurrent_duplicates_share_one_delivery_and_one_cursor_turn() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    validating_worker(&app, "dup", "q-dup", Some(json!(["approve_me"])), true).await;
    waiting_run(&app, "dup-run", "q-dup", "dup").await;
    let pending = send_update(&app, "dup-run", "approve_me", update_body(3, Some("d1")));
    tokio::time::sleep(Duration::from_millis(300)).await;
    start_on(&app, "dup-other", "q-dup").await;
    let polls: Vec<_> = [json!(["workflow", "update_validation"]), json!(["update_validation", "workflow"]), json!(["workflow", "update_validation"])]
        .into_iter()
        .map(|kinds| {
            let app = app.clone();
            tokio::spawn(async move { poll_kinds(&app, "dup", "q-dup", Some("same"), Some(kinds)).await })
        })
        .collect();
    let mut deliveries = vec![];
    for poll in polls {
        let (status, body) = poll.await.unwrap();
        assert_eq!(status, StatusCode::OK, "{body}");
        deliveries.push(delivery(&body));
    }
    assert!(deliveries.iter().all(|one| *one == deliveries[0]), "{deliveries:?}");
    assert_eq!(deliveries[0].0, json!("update_validation"), "a fresh cursor tries validation first");
    let (_, other) = poll_kinds(&app, "dup", "q-dup", Some("other"), Some(json!(["workflow", "update_validation"]))).await;
    assert_eq!(delivery(&other).0, json!("workflow"), "{other}");
    let (_, empty) = poll_kinds(&app, "dup", "q-dup", Some("last"), Some(json!(["workflow", "update_validation"]))).await;
    assert_eq!(empty["poll_status"], "empty", "{empty}");
    let id = deliveries[0].1.as_str().unwrap();
    assert_eq!(complete(&app, id, "approve", json!({"lease_owner":"dup","update_validation_attempt":1})).await.0, StatusCode::OK);
    assert_eq!(pending.await.unwrap().0, StatusCode::ACCEPTED);
}

// Concurrent probes take turns on the cursor: with two tasks of each kind
// ready, two simultaneous polls lease one of each kind, never two of one.
#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
async fn concurrent_probes_alternate_kinds_without_losing_a_turn() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    for round in 0..4 {
        let (worker, queue) = (format!("alt-{round}"), format!("q-alt-{round}"));
        validating_worker(&app, &worker, &queue, Some(json!(["approve_me"])), true).await;
        let mut pending = vec![];
        for run in 0..2 {
            let workflow = format!("alt-{round}-run-{run}");
            waiting_run(&app, &workflow, &queue, &worker).await;
            pending.push(send_update(&app, &workflow, "approve_me", update_body(run, Some("v"))));
        }
        tokio::time::sleep(Duration::from_millis(300)).await;
        for run in 0..2 {
            start_on(&app, &format!("alt-{round}-ready-{run}"), &queue).await;
        }
        let polls: Vec<_> = ["one", "two"]
            .into_iter()
            .map(|id| {
                let (app, worker, queue) = (app.clone(), worker.clone(), queue.clone());
                tokio::spawn(async move { poll_kinds(&app, &worker, &queue, Some(id), Some(json!(["update_validation", "workflow"]))).await })
            })
            .collect();
        let mut kinds = vec![];
        for poll in polls {
            let (status, body) = poll.await.unwrap();
            assert_eq!(status, StatusCode::OK, "{body}");
            kinds.push(body["task"]["task_kind"].as_str().unwrap().to_owned());
        }
        kinds.sort();
        assert_eq!(kinds, ["update_validation", "workflow"], "round {round}");
        // The cursor turned twice: validation comes first again.
        let (_, next) = poll_kinds(&app, &worker, &queue, None, Some(json!(["workflow", "update_validation"]))).await;
        assert_eq!(next["task"]["task_kind"], "update_validation", "round {round}: {next}");
        for update in pending {
            update.abort();
        }
    }
}
