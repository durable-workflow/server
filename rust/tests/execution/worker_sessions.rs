//! Worker sessions as published 2.11.1 serves them (worker-sessions fixture):
//! create, reuse, heartbeat and close on the worker plane, lease expiry and a
//! holder's loss applied on read, and the namespace's list on the control
//! plane.
use super::*;
use std::time::Duration;

async fn worker(app: &Router, worker: &str, capabilities: Value, sessions: Option<i64>) {
    let mut body = json!({"worker_id":worker,"task_queue":"sessions","runtime":"php","supported_workflow_types":["echo"],
        "supported_activity_types":["act"],"capabilities":capabilities});
    if let Some(sessions) = sessions {
        body["max_concurrent_worker_sessions"] = json!(sessions);
    }
    let (status, answer) = request(app, "POST", "/api/worker/register", body).await;
    assert_eq!(status, StatusCode::CREATED, "{answer}");
}

async fn create(app: &Router, body: Value) -> (StatusCode, Value) {
    request(app, "POST", "/api/worker/sessions", body).await
}

#[tokio::test]
async fn worker_sessions_lease_renew_expire_and_close_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    worker(&app, "holder", json!(["gpu"]), Some(2)).await;
    worker(&app, "other", json!([]), None).await;

    // Create: the ID trimmed, requirements de-duplicated, a string lease read.
    let (status, created) = create(&app, json!({"worker_id":"holder","session_id":" s1 ","requirements":["gpu"," gpu "],
        "lease_seconds":"60","connection":"c1"})).await;
    assert_eq!(status, StatusCode::CREATED, "{created}");
    let session = &created["session"];
    assert_eq!((created["outcome"].clone(), created["status"].clone()), (json!("created"), json!(201)));
    assert_eq!((session["session_id"].clone(), session["requirements"].clone(), session["lease_seconds"].clone()),
        (json!("s1"), json!(["gpu"]), json!(60)), "{session}");
    assert_eq!((session["create_if_missing"].clone(), session["allow_reacquire_after_failure"].clone()), (json!(true), json!(true)));
    assert!(session["created_at"].as_str().unwrap().ends_with(".000000Z"), "whole seconds: {session}");
    // Requirements are checked before the lookup.
    let (status, unmet) = create(&app, json!({"worker_id":"holder","session_id":"s1","requirements":["tpu"]})).await;
    assert_eq!((status, unmet["missing_requirements"].clone()), (StatusCode::CONFLICT, json!(["tpu"])), "{unmet}");

    // Reuse resets omitted options and keeps the TTL deadline.
    let (status, reused) = create(&app, json!({"worker_id":"holder","session_id":"s1","ttl_seconds":9999,"create_if_missing":false})).await;
    assert_eq!((status, reused["outcome"].clone()), (StatusCode::OK, json!("reused")), "{reused}");
    assert_eq!((reused["session"]["requirements"].clone(), reused["session"]["lease_seconds"].clone(), reused["session"]["create_if_missing"].clone(),
        reused["session"]["connection"].clone(), reused["session"]["ttl_expires_at"].clone()),
        (json!([]), json!(120), json!(false), json!("c1"), session["ttl_expires_at"].clone()));
    let (status, foreign) = create(&app, json!({"worker_id":"other","session_id":"s1"})).await;
    assert_eq!((status, foreign["reason"].clone()), (StatusCode::CONFLICT, json!("session_owned_by_another_worker")));

    // The per-worker limit counts live leases.
    let (status, _) = create(&app, json!({"worker_id":"holder","session_id":"s2","lease_seconds":2})).await;
    assert_eq!(status, StatusCode::CREATED);
    let (status, limited) = create(&app, json!({"worker_id":"holder","session_id":"s3"})).await;
    assert_eq!((status, limited["max_concurrent_worker_sessions"].clone()), (StatusCode::CONFLICT, json!(2)), "{limited}");

    // A lease expires on read; another worker reacquires with the failure
    // reason cleared (published PHP keeps it: Server #737).
    tokio::time::sleep(Duration::from_millis(3200)).await;
    let (status, shown) = request(&app, "GET", "/api/worker-sessions/s2", Value::Null).await;
    assert_eq!((status, shown["session"]["status"].clone(), shown["session"]["failure_reason"].clone()),
        (StatusCode::OK, json!("expired"), json!("lease_expired")), "{shown}");
    let (status, reacquired) = create(&app, json!({"worker_id":"other","session_id":"s2"})).await;
    assert_eq!((status, reacquired["outcome"].clone(), reacquired["session"]["failure_reason"].clone()),
        (StatusCode::OK, json!("reacquired"), Value::Null), "{reacquired}");

    // Heartbeat renews from now without changing the stored lease.
    let (status, beat) = request(&app, "POST", "/api/worker/sessions/s1/heartbeat", json!({"worker_id":"holder","lease_seconds":5})).await;
    assert_eq!((status, beat["outcome"].clone(), beat["session"]["lease_seconds"].clone()), (StatusCode::OK, json!("heartbeat_recorded"), json!(120)));
    let (status, mismatch) = request(&app, "POST", "/api/worker/sessions/s1/heartbeat", json!({"worker_id":"other"})).await;
    assert_eq!((status, mismatch["reason"].clone()), (StatusCode::CONFLICT, json!("session_owner_mismatch")));

    // Close, close again, and a closed ID stays unusable.
    let (status, closed) = request(&app, "DELETE", "/api/worker/sessions/s1?worker_id=holder", Value::Null).await;
    assert_eq!((status, closed["outcome"].clone()), (StatusCode::OK, json!("closed")), "{closed}");
    let (status, again) = request(&app, "DELETE", "/api/worker/sessions/s1", json!({"worker_id":"holder"})).await;
    assert_eq!((status, again["outcome"].clone(), again["session"]["closed_at"].clone()), (StatusCode::OK, json!("already_closed"), closed["session"]["closed_at"].clone()));
    let (status, refused) = create(&app, json!({"worker_id":"holder","session_id":"s1"})).await;
    assert_eq!((status, refused["reason"].clone()), (StatusCode::CONFLICT, json!("session_closed")));

    // A deregistered holder leaves its session orphaned on read.
    let (status, _) = request(&app, "DELETE", "/api/worker/registrations/other", Value::Null).await;
    assert_eq!(status, StatusCode::OK);
    let (status, listed) = request(&app, "GET", "/api/worker-sessions", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{listed}");
    assert_eq!(listed["metrics"], json!({"total":2,"active":0,"closed":1,"expired":0,"failed":0,"orphaned":1}), "{listed}");
    let orphan = listed["sessions"].as_array().unwrap().iter().find(|session| session["session_id"] == "s2").unwrap();
    assert_eq!((orphan["status"].clone(), orphan["holder"]["task_queue"].clone(), orphan["holder"]["is_stale"].clone()),
        (json!("orphaned"), Value::Null, json!(true)), "{orphan}");
}

#[tokio::test]
async fn worker_sessions_refuse_old_protocols_and_bad_requests() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    worker(&app, "gate", json!([]), None).await;
    let (status, old) = request_protocol(&app, "POST", "/api/worker/sessions", json!({"worker_id":"gate","session_id":"g"}), "1.7").await;
    assert_eq!((status, old["reason"].clone(), old["minimum_protocol_version"].clone()), (StatusCode::CONFLICT, json!("worker_sessions_unavailable"), json!("1.8")), "{old}");
    let (status, invalid) = request_protocol(&app, "POST", "/api/worker/sessions", json!({}), "1.8").await;
    assert_eq!((status, invalid["errors"].as_object().unwrap().len()), (StatusCode::UNPROCESSABLE_ENTITY, 2), "{invalid}");
    let (status, unknown) = create(&app, json!({"worker_id":"nobody","session_id":"g"})).await;
    assert_eq!((status, unknown["reason"].clone()), (StatusCode::PRECONDITION_FAILED, json!("worker_not_registered")));
    let (status, missing) = request(&app, "GET", "/api/worker-sessions/none", Value::Null).await;
    assert_eq!((status, missing["reason"].clone()), (StatusCode::NOT_FOUND, json!("worker_session_not_found")));
}

async fn session_worker(app: &Router, worker: &str, capabilities: Value) {
    let manifest = json!({"local_activities":{"supported":false,"minimum_protocol_version":"1.18"},
        "sticky_execution":{"supported":false,"minimum_protocol_version":"1.18"},
        "worker_sessions":{"supported":capabilities.as_array().unwrap().iter().any(|item| item == "worker_sessions"),"minimum_protocol_version":"1.18"}});
    let (status, answer) = request(app, "POST", "/api/worker/register", json!({"worker_id":worker,"task_queue":"test","runtime":"php",
        "supported_workflow_types":["echo"],"supported_activity_types":["act"],"capabilities":capabilities,"capability_manifest":manifest})).await;
    assert_eq!(status, StatusCode::CREATED, "{answer}");
}

fn bound(session: Value) -> Value {
    json!({"type":"schedule_activity","activity_type":"act","arguments":envelope(Payload::Array(vec![])),"worker_session":session})
}

// Session-bound activities (worker-session-activities fixture): lazily
// created on the first admitted claim, held against other workers, bounded
// by the session's activity limit, and renewed by the activity heartbeat.
#[tokio::test]
async fn session_bound_activities_are_admitted_held_limited_and_renewed() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    session_worker(&app, "author", json!(["worker_sessions"])).await;
    session_worker(&app, "gpu-a", json!(["worker_sessions", "gpu"])).await;
    session_worker(&app, "gpu-b", json!(["worker_sessions", "gpu"])).await;
    start(&app, "session-run").await;
    let task = poll(&app, "author", "workflow").await;
    let session = json!({"session_id":"lazy","requirements":["gpu"],"lease_seconds":90,"max_concurrent_activities":1});
    let (status, done) = finish_task(&app, &task, json!([bound(session.clone()), bound(session)])).await;
    assert_eq!(status, StatusCode::OK, "{done}");
    let (status, _) = request(&app, "GET", "/api/worker-sessions/lazy", Value::Null).await;
    assert_eq!(status, StatusCode::NOT_FOUND, "no session before the first claim");

    let first = poll(&app, "gpu-a", "activity").await;
    assert_eq!((first["worker_session"]["session_id"].clone(), first["worker_session"]["lease_owner"].clone()),
        (json!("lazy"), json!("gpu-a")), "{first}");
    // The session holds one activity: neither worker gets the second.
    assert!(poll(&app, "gpu-a", "activity").await.is_null());
    assert!(poll(&app, "gpu-b", "activity").await.is_null());
    let (status, shown) = request(&app, "GET", "/api/worker-sessions/lazy", Value::Null).await;
    assert_eq!((status, shown["session"]["active_activity_count"].clone()), (StatusCode::OK, json!(1)), "{shown}");

    // The heartbeat renews the session by the command's lease.
    let own = json!({"activity_attempt_id":first["activity_attempt_id"],"lease_owner":"gpu-a"});
    let path = format!("/api/worker/activity-tasks/{}", first["task_id"].as_str().unwrap());
    let (status, beat) = request(&app, "POST", &format!("{path}/heartbeat"), own.clone()).await;
    assert_eq!(status, StatusCode::OK, "{beat}");
    let lease = |value: &Value| chrono::DateTime::parse_from_rfc3339(value.as_str().unwrap()).unwrap().timestamp();
    assert_eq!(lease(&beat["worker_session"]["lease_expires_at"]) - lease(&beat["worker_session"]["last_heartbeat_at"]), 90, "{beat}");

    // Once the holder closes the session, the heartbeat is refused and the
    // attempt still completes; the second activity is then claimable again.
    let (status, _) = request(&app, "DELETE", "/api/worker/sessions/lazy", json!({"worker_id":"gpu-a"})).await;
    assert_eq!(status, StatusCode::OK);
    let (status, refused) = request(&app, "POST", &format!("{path}/heartbeat"), own.clone()).await;
    assert_eq!((status, refused["worker_session_renewal"]["reason"].clone()), (StatusCode::CONFLICT, json!("session_not_active")), "{refused}");
    let (status, observed) = request_protocol(&app, "POST", &format!("{path}/status"), own.clone(), "1.20").await;
    assert_eq!((status, observed["reason"].clone()), (StatusCode::OK, json!("worker_session_unavailable")), "{observed}");
    let mut completion = own.clone();
    completion["result"] = envelope(Payload::Null);
    assert_eq!(request(&app, "POST", &format!("{path}/complete"), completion).await.0, StatusCode::OK);
}

// Session numbers have portable maxima, the same in PHP (Server #740). One
// above a maximum, and i64::MAX, are refused with 422 before any arithmetic
// or write, on the create and heartbeat routes and in a schedule_activity
// command; the maxima themselves are accepted.
#[tokio::test]
async fn session_numbers_are_bounded_on_every_path() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    worker(&app, "bounded", json!(["gpu"]), None).await;
    let maxima = [("lease_seconds", 86_400_i64), ("ttl_seconds", 2_592_000), ("max_concurrent_activities", 1_000)];
    let message = |field: &str, maximum: i64| json!(format!("The {} field must not be greater than {maximum}.", field.replace('_', " ")));
    for (field, maximum) in maxima {
        for value in [maximum + 1, i64::MAX] {
            let mut body = json!({"worker_id":"bounded","session_id":format!("too-large-{field}")});
            body[field] = json!(value);
            let (status, refused) = create(&app, body).await;
            assert_eq!((status, refused["errors"][field][0].clone()), (StatusCode::UNPROCESSABLE_ENTITY, message(field, maximum)), "{refused}");
        }
    }
    assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_worker_sessions").await, 0, "refused creates record nothing");

    let mut at_maxima = json!({"worker_id":"bounded","session_id":"at-maxima"});
    for (field, maximum) in maxima {
        at_maxima[field] = json!(maximum);
    }
    let (status, created) = create(&app, at_maxima).await;
    assert_eq!(status, StatusCode::CREATED, "{created}");
    assert_eq!((created["session"]["lease_seconds"].clone(), created["session"]["ttl_seconds"].clone(),
        created["session"]["max_concurrent_activities"].clone()), (json!(86_400), json!(2_592_000), json!(1_000)), "{created}");
    let lease = || async {
        request(&app, "GET", "/api/worker-sessions/at-maxima", Value::Null).await.1["session"]["lease_expires_at"].clone()
    };
    let before = lease().await;
    for value in [86_401, i64::MAX] {
        let (status, refused) = request(&app, "POST", "/api/worker/sessions/at-maxima/heartbeat",
            json!({"worker_id":"bounded","lease_seconds":value})).await;
        assert_eq!((status, refused["errors"]["lease_seconds"][0].clone()), (StatusCode::UNPROCESSABLE_ENTITY,
            message("lease_seconds", 86_400)), "{refused}");
    }
    assert_eq!(lease().await, before, "a refused heartbeat renews nothing");
    let (status, beat) = request(&app, "POST", "/api/worker/sessions/at-maxima/heartbeat",
        json!({"worker_id":"bounded","lease_seconds":86_400})).await;
    assert_eq!((status, beat["outcome"].clone()), (StatusCode::OK, json!("heartbeat_recorded")), "{beat}");

    session_worker(&app, "author", json!(["worker_sessions"])).await;
    start(&app, "bounded-run").await;
    let task = poll(&app, "author", "workflow").await;
    for (field, maximum) in maxima {
        let mut session = json!({"session_id":"command-maxima"});
        session[field] = json!(i64::MAX);
        let (status, refused) = finish_task(&app, &task, json!([bound(session)])).await;
        let key = format!("commands.0.worker_session.{field}");
        assert_eq!((status, refused["errors"][&key][0].clone()), (StatusCode::UNPROCESSABLE_ENTITY,
            json!(format!("The {key} field must not be greater than {maximum}."))), "{refused}");
    }
    assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM activity_executions").await, 0, "refused commands schedule nothing");
}

async fn register_holder(app: &Router, worker: &str, queue: &str) -> String {
    register_with(app, worker, queue, json!(["worker_sessions", "gpu"])).await
}

/// A holder that declares it sends its registration token on every
/// explicit session request, so the server requires it.
async fn register_token_holder(app: &Router, worker: &str, queue: &str) -> String {
    register_with(app, worker, queue, json!(["worker_sessions", "gpu", "worker_session_registration_token"])).await
}

async fn register_with(app: &Router, worker: &str, queue: &str, capabilities: Value) -> String {
    let manifest = json!({"local_activities":{"supported":false,"minimum_protocol_version":"1.18"},
        "sticky_execution":{"supported":false,"minimum_protocol_version":"1.18"},
        "worker_sessions":{"supported":true,"minimum_protocol_version":"1.18"}});
    let (status, answer) = request(app, "POST", "/api/worker/register", json!({"worker_id":worker,"task_queue":queue,"runtime":"php",
        "supported_workflow_types":["echo"],"supported_activity_types":["act"],"capabilities":capabilities,
        "capability_manifest":manifest})).await;
    assert_eq!(status, StatusCode::CREATED, "{answer}");
    answer["registration_token"].as_str().expect("a registration token").to_owned()
}

// A same-ID re-registration orphans the sessions the previous registration
// held (Server #740): the replacement reacquires, never reuses, and a request
// from the replaced process, with its old token or with none, is refused
// without changing anything. A worker heartbeat keeps the registration, so
// its holder reuses. The worker declared that it sends its token.
#[tokio::test]
async fn a_same_id_replacement_reacquires_the_session_and_fences_the_old_process() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let with_token = |mut body: Value, token: Option<&str>| {
        if let Some(token) = token {
            body["registration_token"] = json!(token);
        }
        body
    };
    let create = |token: Option<&str>, options: Value| {
        let mut body = json!({"worker_id":"gpu-worker","session_id":"gpu-state","requirements":["gpu"]});
        for (key, value) in options.as_object().into_iter().flatten() {
            body[key] = value.clone();
        }
        create(&app, with_token(body, token))
    };
    let heartbeat = |token: Option<&str>| request(&app, "POST", "/api/worker/sessions/gpu-state/heartbeat",
        with_token(json!({"worker_id":"gpu-worker","lease_seconds":900}), token));
    let close = |token: Option<&str>| request(&app, "DELETE", "/api/worker/sessions/gpu-state",
        with_token(json!({"worker_id":"gpu-worker"}), token));
    let shown = || request(&app, "GET", "/api/worker-sessions/gpu-state", Value::Null);

    let first = register_token_holder(&app, "gpu-worker", "sessions").await;
    let (status, created) = create(Some(&first), json!({})).await;
    assert_eq!((status, created["outcome"].clone()), (StatusCode::CREATED, json!("created")), "{created}");
    let (status, beat) = request(&app, "POST", "/api/worker/heartbeat", json!({"worker_id":"gpu-worker"})).await;
    assert_eq!(status, StatusCode::OK, "{beat}");
    let (status, reused) = create(Some(&first), json!({})).await;
    assert_eq!((status, reused["outcome"].clone()), (StatusCode::OK, json!("reused")), "{reused}");

    let second = register_token_holder(&app, "gpu-worker", "sessions").await;
    assert_ne!(first, second);
    let (status, orphaned) = heartbeat(Some(&second)).await;
    assert_eq!((status, orphaned["reason"].clone(), orphaned["session"]["status"].clone(), orphaned["session"]["failure_reason"].clone()),
        (StatusCode::CONFLICT, json!("session_not_active"), json!("orphaned"), json!("worker_registration_replaced")), "{orphaned}");
    for refused in [heartbeat(Some(&first)).await, create(Some(&first), json!({})).await] {
        assert_eq!((refused.0, refused.1["reason"].clone()), (StatusCode::CONFLICT, json!("stale_worker_registration")), "{}", refused.1);
    }
    let (status, reacquired) = create(Some(&second), json!({})).await;
    assert_eq!((status, reacquired["outcome"].clone(), reacquired["session"]["status"].clone()),
        (StatusCode::OK, json!("reacquired"), json!("active")), "{reacquired}");
    let (status, current) = heartbeat(Some(&second)).await;
    assert_eq!((status, current["outcome"].clone()), (StatusCode::OK, json!("heartbeat_recorded")), "{current}");

    // The replaced process can neither reuse, renew nor close the session,
    // with its old token or with none, and nothing changes.
    let before = shown().await;
    assert_eq!(before.0, StatusCode::OK, "{}", before.1);
    for stale in [Some(first.as_str()), None] {
        for refused in [create(stale, json!({"lease_seconds":5,"max_concurrent_activities":9})).await, heartbeat(stale).await, close(stale).await] {
            assert_eq!((refused.0, refused.1["reason"].clone()), (StatusCode::CONFLICT, json!("stale_worker_registration")), "{}", refused.1);
        }
        assert_eq!(shown().await, before, "a refused request changes nothing");
    }
    let (status, invalid) = close(Some("not-a-token")).await;
    assert_eq!((status, invalid["errors"]["registration_token"][0].clone()),
        (StatusCode::UNPROCESSABLE_ENTITY, json!("The registration token field format is invalid.")), "{invalid}");
    let (status, closed) = close(Some(&second)).await;
    assert_eq!((status, closed["outcome"].clone()), (StatusCode::OK, json!("closed")), "{closed}");
}

// Deregistration deletes the registration and leaves the session for
// holder-loss handling. The former process, with its token or without, can
// neither renew nor close the session, and nothing changes: a token worker
// deregistered through the fenced route, a published one through the plain one.
#[tokio::test]
async fn a_deregistered_worker_can_neither_renew_nor_close_its_session() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    for sends_token in [true, false] {
        let worker = if sends_token { "gpu-token-worker" } else { "gpu-published-worker" };
        let session = format!("{worker}-state");
        let token = if sends_token {
            register_token_holder(&app, worker, "sessions").await
        } else {
            register_holder(&app, worker, "sessions").await
        };
        let mut body = json!({"worker_id":worker,"session_id":session,"requirements":["gpu"]});
        if sends_token {
            body["registration_token"] = json!(token);
        }
        let (status, created) = create(&app, body).await;
        assert_eq!(status, StatusCode::CREATED, "{created}");
        let shown_path = format!("/api/worker-sessions/{session}");
        let shown = || request(&app, "GET", &shown_path, Value::Null);
        let (status, deregistered) = if sends_token {
            request(&app, "POST", &format!("/api/worker/registrations/{worker}/deregister"), json!({"registration_token":token})).await
        } else {
            request(&app, "DELETE", &format!("/api/worker/registrations/{worker}"), json!({})).await
        };
        assert_eq!(status, StatusCode::OK, "{deregistered}");
        // The session shows its holder lost on read, as PHP's does; that read
        // settles it before the refused requests are compared with it.
        let before = shown().await;
        assert_eq!((before.0, before.1["session"]["status"].clone()), (StatusCode::OK, json!("orphaned")), "{}", before.1);
        for sent in [None, Some(token.as_str())] {
            let with_token = |mut body: Value| {
                if let Some(sent) = sent {
                    body["registration_token"] = json!(sent);
                }
                body
            };
            let (status, renewed) = request(&app, "POST", &format!("/api/worker/sessions/{session}/heartbeat"),
                with_token(json!({"worker_id":worker,"lease_seconds":900}))).await;
            assert_eq!((status, renewed["reason"].clone(), renewed["error"].clone()), (StatusCode::PRECONDITION_FAILED,
                json!("worker_not_registered"), json!("Worker must be registered before renewing a worker session.")), "{renewed}");
            let (status, closed) = request(&app, "DELETE", &format!("/api/worker/sessions/{session}"),
                with_token(json!({"worker_id":worker}))).await;
            assert_eq!((status, closed["reason"].clone(), closed["error"].clone()), (StatusCode::PRECONDITION_FAILED,
                json!("worker_not_registered"), json!("Worker must be registered before closing a worker session.")), "{closed}");
            assert_eq!(shown().await, before, "a refused request changes nothing");
        }
    }
}

// Published workers send no registration token. Without the token
// capability, a request without one acts for the worker ID's current
// registration, as published PHP's does; a token that is sent must be current.
#[tokio::test]
async fn without_the_token_capability_a_tokenless_request_acts_for_the_current_registration() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let body = json!({"worker_id":"gpu-worker","session_id":"gpu-legacy","requirements":["gpu"]});
    let first = register_holder(&app, "gpu-worker", "sessions").await;
    let (status, created) = create(&app, body.clone()).await;
    assert_eq!((status, created["outcome"].clone()), (StatusCode::CREATED, json!("created")), "{created}");
    register_holder(&app, "gpu-worker", "sessions").await;
    let heartbeat = |token: Option<&str>| {
        let mut body = json!({"worker_id":"gpu-worker"});
        if let Some(token) = token {
            body["registration_token"] = json!(token);
        }
        request(&app, "POST", "/api/worker/sessions/gpu-legacy/heartbeat", body)
    };
    let (status, orphaned) = heartbeat(None).await;
    assert_eq!((status, orphaned["session"]["failure_reason"].clone()), (StatusCode::CONFLICT, json!("worker_registration_replaced")), "{orphaned}");
    let (status, reacquired) = create(&app, body).await;
    assert_eq!((status, reacquired["outcome"].clone()), (StatusCode::OK, json!("reacquired")), "{reacquired}");
    let (status, beat) = heartbeat(None).await;
    assert_eq!((status, beat["outcome"].clone()), (StatusCode::OK, json!("heartbeat_recorded")), "{beat}");
    let (status, stale) = heartbeat(Some(&first)).await;
    assert_eq!((status, stale["reason"].clone()), (StatusCode::CONFLICT, json!("stale_worker_registration")), "{stale}");
    let (status, closed) = request(&app, "DELETE", "/api/worker/sessions/gpu-legacy", json!({"worker_id":"gpu-worker"})).await;
    assert_eq!((status, closed["outcome"].clone()), (StatusCode::OK, json!("closed")), "{closed}");
}

// A session-bound activity claim by the replacement takes the orphaned
// session through reacquisition, so the claim names the replacement's
// registration as the holder.
#[tokio::test]
async fn a_same_id_replacement_reacquires_a_session_through_an_activity_claim() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register_holder(&app, "gpu-worker", "test").await;
    let (status, created) = create(&app, json!({"worker_id":"gpu-worker","session_id":"gpu-claim","requirements":["gpu"]})).await;
    assert_eq!(status, StatusCode::CREATED, "{created}");
    start(&app, "replacement-run").await;
    let task = poll(&app, "gpu-worker", "workflow").await;
    let (status, done) = finish_task(&app, &task, json!([bound(json!({"session_id":"gpu-claim","requirements":["gpu"]}))])).await;
    assert_eq!(status, StatusCode::OK, "{done}");

    register_holder(&app, "gpu-worker", "test").await;
    let (status, shown) = request(&app, "GET", "/api/worker-sessions/gpu-claim", Value::Null).await;
    assert_eq!((status, shown["session"]["status"].clone(), shown["session"]["failure_reason"].clone()),
        (StatusCode::OK, json!("orphaned"), json!("worker_registration_replaced")), "{shown}");
    let claimed = poll(&app, "gpu-worker", "activity").await;
    assert_eq!((claimed["worker_session"]["session_id"].clone(), claimed["worker_session"]["lease_owner"].clone()),
        (json!("gpu-claim"), json!("gpu-worker")), "{claimed}");
    let (status, shown) = request(&app, "GET", "/api/worker-sessions/gpu-claim", Value::Null).await;
    assert_eq!((status, shown["session"]["status"].clone(), shown["session"]["failure_reason"].clone()),
        (StatusCode::OK, json!("active"), Value::Null), "{shown}");
}
