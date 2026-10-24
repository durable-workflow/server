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
