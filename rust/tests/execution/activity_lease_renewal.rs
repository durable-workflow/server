use super::*;

// Expectations measured against published Server 2.11.1 (Workflow 2.10.2):
// POST /api/worker/activity-tasks/{task}/renew-lease.

fn lease_activity() -> Value {
    json!({"type":"schedule_activity","activity_type":"lease_activity",
        "arguments":envelope(Payload::Array(vec![])),
        "retry_policy":{"max_attempts":1,"backoff_seconds":[]},
        "heartbeat_timeout":60,"start_to_close_timeout":300})
}

/// Starts a run and claims its single activity.
async fn claim(app: &Router, workflow_id: &str) -> (Value, Value, Value) {
    let started = start(app, workflow_id).await;
    let workflow = poll(app, "lease-worker", "workflow").await;
    assert_eq!(workflow["run_id"], started["run_id"], "{workflow}");
    assert_eq!(finish_task(app, &workflow, json!([lease_activity()])).await.0, StatusCode::OK);
    let activity = poll(app, "lease-worker", "activity").await;
    assert_eq!(activity["run_id"], started["run_id"], "{activity}");
    (started, workflow, activity)
}

fn own(activity: &Value) -> Value {
    json!({"activity_attempt_id":activity["activity_attempt_id"],"lease_owner":activity["lease_owner"]})
}

fn path(activity: &Value, verb: &str) -> String {
    format!("/api/worker/activity-tasks/{}/{verb}", activity["task_id"].as_str().unwrap())
}

async fn renew(app: &Router, activity: &Value, body: Value) -> (StatusCode, Value) {
    request_protocol(app, "POST", &path(activity, "renew-lease"), body, "1.20").await
}

fn without_protocol(mut body: Value) -> Value {
    let map = body.as_object_mut().unwrap();
    assert_eq!(map.remove("protocol_version"), Some(json!("1.20")));
    assert_eq!(map.remove("server_capabilities").unwrap()["activity_lease_renewal"], true);
    body
}

#[tokio::test]
async fn renewal_extends_only_the_live_lease() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "lease-worker", json!(["echo"]), json!(["lease_activity"])).await;
    let (started, workflow, activity) = claim(&app, "lease-live").await;
    let status_before = request_protocol(&app, "POST", &path(&activity, "status"), own(&activity), "1.20").await;
    assert_eq!(status_before.0, StatusCode::OK, "{}", status_before.1);
    let history_before = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    tokio::time::sleep(std::time::Duration::from_millis(20)).await;

    let (code, renewed) = renew(&app, &activity, own(&activity)).await;
    assert_eq!(code, StatusCode::OK, "{renewed}");
    let mut renewed = without_protocol(renewed);
    let lease = renewed.as_object_mut().unwrap().remove("lease_expires_at").unwrap();
    let server_time = renewed.as_object_mut().unwrap().remove("server_time").unwrap();
    let heartbeat_deadline = renewed.as_object_mut().unwrap().remove("heartbeat_deadline_at").unwrap();
    assert_eq!(renewed, json!({
        "can_continue":true,"cancel_requested":false,"reason":null,"heartbeat_recorded":false,
        "workflow_instance_id":"lease-live","workflow_run_id":started["run_id"],"workflow_task_id":activity["task_id"],
        "activity_execution_id":activity["activity_execution_id"],"activity_attempt_id":activity["activity_attempt_id"],
        "attempt_number":1,"run_status":"waiting","run_closed_reason":null,"run_closed_at":null,
        "activity_status":"running","attempt_status":"running","task_status":"leased",
        "lease_owner":activity["lease_owner"],"last_heartbeat_at":null,
        "lease_renewed":true,"task_id":activity["task_id"],"worker_session":null}));
    let micros = |value: &Value| {
        let text = value.as_str().unwrap();
        assert!(text.ends_with('Z') && text.len() == 27, "{text}");
        chrono::DateTime::parse_from_rfc3339(text).unwrap()
    };
    let time = micros(&server_time);
    assert_eq!(micros(&lease), time + chrono::Duration::seconds(300), "the lease runs the configured 300 s from now");
    assert!(lease.as_str().unwrap() > status_before.1["lease_expires_at"].as_str().unwrap());
    assert_eq!(heartbeat_deadline, status_before.1["deadlines"]["heartbeat"], "progress deadlines never move");

    let status_after = request_protocol(&app, "POST", &path(&activity, "status"), own(&activity), "1.20").await;
    let instant = |value: &Value| chrono::DateTime::parse_from_rfc3339(value.as_str().unwrap()).unwrap();
    assert_eq!(instant(&status_after.1["lease_expires_at"]), instant(&lease), "status reads the renewed lease");
    assert_eq!(status_after.1["last_heartbeat_at"], Value::Null);
    assert_eq!(status_after.1["deadlines"], status_before.1["deadlines"]);
    assert_eq!(run_history(&app, &started["workflow_id"], &started["run_id"]).await, history_before,
        "renewal adds no history");

    // Like PHP, a 1.19 request and an unruled field are accepted.
    assert_eq!(request(&app, "POST", &path(&activity, "renew-lease"), own(&activity)).await.0, StatusCode::OK);
    let mut extra = own(&activity);
    extra["message"] = json!("progress");
    assert_eq!(renew(&app, &activity, extra).await.0, StatusCode::OK);

    // Ownership refusals, in PHP's order and shape.
    let refusal = |task: &Value, attempt: &Value, error: &str, reason: &str| {
        json!({"task_id":task,"activity_attempt_id":attempt,"error":error,"reason":reason})
    };
    let (code, body) = renew(&app, &activity, json!({"activity_attempt_id":activity["activity_attempt_id"],"lease_owner":"someone-else"})).await;
    let mut expected = refusal(&activity["task_id"], &activity["activity_attempt_id"],
        "Activity attempt lease is owned by another worker.", "lease_owner_mismatch");
    expected["lease_owner"] = activity["lease_owner"].clone();
    assert_eq!((code, without_protocol(body)), (StatusCode::CONFLICT, expected));
    let (code, body) = renew(&app, &activity, json!({"activity_attempt_id":"no-such-attempt","lease_owner":activity["lease_owner"]})).await;
    assert_eq!((code, without_protocol(body)), (StatusCode::NOT_FOUND,
        refusal(&activity["task_id"], &json!("no-such-attempt"), "Activity attempt not found.", "attempt_not_found")));
    let (code, body) = request_protocol(&app, "POST", "/api/worker/activity-tasks/no-such-task/renew-lease", own(&activity), "1.20").await;
    assert_eq!((code, without_protocol(body)), (StatusCode::NOT_FOUND,
        refusal(&json!("no-such-task"), &activity["activity_attempt_id"], "Activity task not found.", "task_not_found")));
    // Another task of this namespace, here the run's workflow task.
    let (code, body) = request_protocol(&app, "POST", &path(&workflow, "renew-lease"), own(&activity), "1.20").await;
    assert_eq!((code, without_protocol(body)), (StatusCode::CONFLICT,
        refusal(&workflow["task_id"], &activity["activity_attempt_id"], "Activity attempt is not leased for this task.", "task_mismatch")));

    // Laravel's validation.
    let (code, body) = renew(&app, &activity, json!({})).await;
    assert_eq!((code, without_protocol(body)), (StatusCode::UNPROCESSABLE_ENTITY, json!({
        "message":"The activity attempt id field is required. (and 1 more error)","reason":"validation_failed",
        "errors":{"activity_attempt_id":["The activity attempt id field is required."],"lease_owner":["The lease owner field is required."]},
        "validation_errors":{"activity_attempt_id":["The activity attempt id field is required."],"lease_owner":["The lease owner field is required."]}})));
    let (code, body) = renew(&app, &activity, json!({"activity_attempt_id":5,"lease_owner":["x"]})).await;
    assert_eq!(code, StatusCode::UNPROCESSABLE_ENTITY);
    assert_eq!(body["errors"], json!({"activity_attempt_id":["The activity attempt id field must be a string."],
        "lease_owner":["The lease owner field must be a string."]}));

    // A recorded heartbeat is reported; renewal still records none.
    let beat = request_protocol(&app, "POST", &path(&activity, "heartbeat"), own(&activity), "1.20").await;
    assert_eq!(beat.1["heartbeat_recorded"], true, "{}", beat.1);
    let (_, after_beat) = renew(&app, &activity, own(&activity)).await;
    assert_eq!(instant(&after_beat["last_heartbeat_at"]), instant(&beat.1["last_heartbeat_at"]));
    assert_ne!(after_beat["heartbeat_deadline_at"], heartbeat_deadline, "the heartbeat moved its own deadline");

    // A closed attempt reports its status and cannot be renewed.
    let completed = request_protocol(&app, "POST", &path(&activity, "complete"),
        json!({"activity_attempt_id":activity["activity_attempt_id"],"lease_owner":activity["lease_owner"],
            "result":envelope(Payload::String("done".into()))}), "1.20").await;
    assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
    let (code, closed) = renew(&app, &activity, own(&activity)).await;
    assert_eq!(code, StatusCode::CONFLICT, "{closed}");
    let closed = without_protocol(closed);
    assert_eq!([&closed["can_continue"], &closed["cancel_requested"], &closed["reason"], &closed["lease_renewed"],
        &closed["activity_status"], &closed["attempt_status"], &closed["task_status"], &closed["lease_expires_at"]],
        [&json!(false), &json!(false), &json!("attempt_closed"), &json!(false), &json!("completed"),
            &json!("completed"), &json!("completed"), &Value::Null]);
    for absent in ["task_id", "server_time", "worker_session"] {
        assert!(closed.get(absent).is_none(), "a refused status carries no {absent}: {closed}");
    }
}

#[tokio::test]
async fn renewal_cannot_revive_an_expired_or_closed_run() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "lease-worker", json!(["echo"]), json!(["lease_activity"])).await;
    let (started, _, live) = claim(&app, "lease-terminated").await;
    let terminated = request(&app, "POST", &format!("/api/workflows/{}/terminate", started["workflow_id"].as_str().unwrap()),
        json!({"reason":"measure"})).await;
    assert_eq!(terminated.0, StatusCode::OK, "{}", terminated.1);
    let (code, body) = renew(&app, &live, own(&live)).await;
    assert_eq!(code, StatusCode::CONFLICT, "{body}");
    assert_eq!([&body["can_continue"], &body["cancel_requested"], &body["reason"], &body["run_status"],
        &body["run_closed_reason"], &body["attempt_status"], &body["lease_renewed"]],
        [&json!(false), &json!(true), &json!("run_terminated"), &json!("terminated"), &json!("terminated"),
            &json!("cancelled"), &json!(false)]);
    // Last: an expired lease cannot be renewed. The scheduler may already
    // have settled it, as PHP's repair loop does, closing the attempt.
    let (_, _, activity) = claim(&app, "lease-expired").await;
    database.execute("UPDATE workflow_tasks SET lease_expires_at='2020-01-02 03:04:05.123456' WHERE task_type='activity'").await;
    let (code, expired) = renew(&app, &activity, own(&activity)).await;
    assert_eq!(code, StatusCode::CONFLICT, "{expired}");
    assert!(matches!(expired["reason"].as_str(), Some("lease_expired" | "attempt_closed")), "{expired}");
    assert_eq!([&expired["lease_renewed"], &expired["can_continue"]], [&json!(false), &json!(false)]);
    let status = request_protocol(&app, "POST", &path(&activity, "status"), own(&activity), "1.20").await;
    assert_eq!(status.1["can_continue"], false, "an expired lease stays expired: {}", status.1);
}
