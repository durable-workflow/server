//! Cooperative cancellation delivered to a parallel group of timers, as
//! published PHP's `CooperativeCancellationDelivery` accepts a `parallel`
//! boundary and cancels each member's open timer.
use super::*;

fn parallel_timer(index: i64) -> Value {
    let group = json!({"parallel_group_id":"parallel-timers:1:3","parallel_group_kind":"timer",
        "parallel_group_base_sequence":1,"parallel_group_size":3,"parallel_group_index":index});
    let mut command = group.clone();
    command["type"] = json!("start_timer");
    command["delay_seconds"] = json!(60);
    command["parallel_group_path"] = json!([group]);
    command
}

async fn deliver_parallel(app: &Router, task: &Value, request_id: &Value, span: i64) -> (StatusCode, Value) {
    request_protocol(app, "POST",
        &format!("/api/worker/workflow-tasks/{}/deliver-cancellation", task["task_id"].as_str().unwrap()),
        json!({"lease_owner":task["lease_owner"],"workflow_task_attempt":task["workflow_task_attempt"],
            "request_id":request_id,"sequence":1,"call_kind":"parallel","sequence_span":span}), "1.20").await
}

#[tokio::test]
async fn a_parallel_timer_group_is_cancelled_by_one_delivery() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let registered = request_protocol(&app, "POST", "/api/worker/register",
        json!({"worker_id":"cleanup-worker","task_queue":"test","runtime":"python",
            "supported_workflow_types":["echo"],"supported_activity_types":[],"capabilities":["cooperative_cancellation"]}), "1.20").await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    let started = start(&app, "parallel-cancel").await;
    let task = cooperative_poll(&app, "workflow").await;
    let scheduled = finish_task(&app, &task, json!([parallel_timer(0), parallel_timer(1), parallel_timer(2)])).await;
    assert_eq!(scheduled.0, StatusCode::OK, "{}", scheduled.1);
    let requested = request(&app, "POST", "/api/workflows/parallel-cancel/request-cancellation",
        json!({"reason":"stop the group","cleanup_timeout_seconds":30})).await;
    assert_eq!(requested.0, StatusCode::ACCEPTED, "{}", requested.1);
    let request_id = requested.1["cancellation_request"]["request_id"].clone();
    let resumed = cooperative_poll(&app, "workflow").await;
    assert_eq!(resumed["cancellation_request"]["request_id"], request_id, "{resumed}");
    // The span must name the group's actual size.
    let wrong = deliver_parallel(&app, &resumed, &request_id, 2).await;
    assert_eq!((wrong.0, wrong.1["reason"].clone()), (StatusCode::CONFLICT, json!("cancellation_delivery_shape_mismatch")));
    let delivered = deliver_parallel(&app, &resumed, &request_id, 3).await;
    assert_eq!(delivered.0, StatusCode::OK, "{}", delivered.1);
    assert_eq!((delivered.1["delivered"].clone(), delivered.1["sequence_span"].clone()), (json!(true), json!(3)));
    // A repeated delivery returns the same receipt.
    assert_eq!(deliver_parallel(&app, &resumed, &request_id, 3).await.1, delivered.1);
    let finished = finish_task(&app, &resumed, json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await;
    assert_eq!(finished.0, StatusCode::OK, "{}", finished.1);
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let types: Vec<_> = history.as_array().unwrap().iter().map(|event| event["event_type"].as_str().unwrap()).collect();
    assert_eq!(types, ["StartAccepted", "WorkflowStarted", "TimerScheduled", "TimerScheduled", "TimerScheduled",
        "CooperativeCancellationRequested", "TimerCancelled", "TimerCancelled", "TimerCancelled",
        "CooperativeCancellationDelivered", "WorkflowCancelled"]);
    let delivery = history.as_array().unwrap().iter().find(|event| event["event_type"] == "CooperativeCancellationDelivered").unwrap();
    assert_eq!((delivery["payload"]["call_kind"].clone(), delivery["payload"]["sequence_span"].clone()), (json!("parallel"), json!(3)));
    runtime.close().await;
    database.remove().await;
}

fn member(kind: &str, group: Value) -> Value {
    let mut command = if kind == "activity" {
        json!({"type":"schedule_activity","activity_type":"echo_activity","arguments":envelope(Payload::Array(vec![]))})
    } else {
        json!({"type":"start_timer","delay_seconds":3600})
    };
    for (field, value) in group.as_object().unwrap() {
        command[field] = value.clone();
    }
    command["parallel_group_path"] = json!([group]);
    command
}

fn group(id: &str, kind: &str, index: i64, select: Option<&str>) -> Value {
    let mut entry = json!({"parallel_group_id":id,"parallel_group_kind":kind,"parallel_group_base_sequence":1,
        "parallel_group_size":2,"parallel_group_index":index});
    if let Some(member) = select {
        for (field, value) in [("parallel_group_mode", json!("select")), ("selection_member_key", json!(member)),
            ("selection_member_index", json!(index)), ("selection_member_base_sequence", json!(1 + index)),
            ("selection_member_size", json!(1)), ("selection_member_kind", json!(member))] {
            entry[field] = value;
        }
    }
    entry
}

// Published PHP delivers a run's cancellation at a parallel group with
// activity members, and at a selection handle over an operation range, and
// cancels each member by its kind in sequence order.
#[tokio::test]
async fn activity_groups_and_selection_handles_take_one_delivery() {
    let activities = json!([member("activity", group("parallel-activities:1:2", "activity", 0, None)),
        member("activity", group("parallel-activities:1:2", "activity", 1, None))]);
    let mixed = json!([member("activity", group("parallel-calls:1:2", "mixed", 0, None)),
        member("timer", group("parallel-calls:1:2", "mixed", 1, None))]);
    let selection = json!([member("activity", group("select-calls:1:2", "mixed", 0, Some("activity"))),
        member("timer", group("select-calls:1:2", "mixed", 1, Some("timer")))]);
    let parallel = json!({"sequence":1,"call_kind":"parallel","sequence_span":2});
    let handle = json!({"sequence":3,"call_kind":"selection_handle","operation_sequence":1,"operation_sequence_span":2});
    for (name, commands, boundary, cancelled) in [
        ("activities", activities, parallel.clone(), ["ActivityCancelled", "ActivityCancelled"]),
        ("mixed", mixed, parallel, ["ActivityCancelled", "TimerCancelled"]),
        ("selection", selection, handle, ["ActivityCancelled", "TimerCancelled"]),
    ] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        let registered = request_protocol(&app, "POST", "/api/worker/register",
            json!({"worker_id":"cleanup-worker","task_queue":"test","runtime":"php","supported_workflow_types":["echo"],
                "supported_activity_types":["echo_activity"],"capabilities":["cooperative_cancellation"]}), "1.20").await;
        assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
        let started = start(&app, "group-cancel").await;
        let task = cooperative_poll(&app, "workflow").await;
        let scheduled = finish_task(&app, &task, commands).await;
        assert_eq!(scheduled.0, StatusCode::OK, "{name}: {}", scheduled.1);
        let requested = request(&app, "POST", "/api/workflows/group-cancel/request-cancellation",
            json!({"reason":"stop the group","cleanup_timeout_seconds":30})).await;
        assert_eq!(requested.0, StatusCode::ACCEPTED, "{name}: {}", requested.1);
        let request_id = requested.1["cancellation_request"]["request_id"].clone();
        let resumed = cooperative_poll(&app, "workflow").await;
        let deliver = |boundary: Value| {
            let mut body = json!({"lease_owner":resumed["lease_owner"],"workflow_task_attempt":resumed["workflow_task_attempt"],
                "request_id":request_id});
            for (field, value) in boundary.as_object().unwrap() {
                body[field] = value.clone();
            }
            let path = format!("/api/worker/workflow-tasks/{}/deliver-cancellation", resumed["task_id"].as_str().unwrap());
            let app = app.clone();
            async move { request_protocol(&app, "POST", &path, body, "1.20").await }
        };
        if name == "mixed" {
            let wrong = deliver(json!({"sequence":1,"call_kind":"parallel","sequence_span":1})).await;
            assert_eq!((wrong.0, wrong.1["reason"].clone()), (StatusCode::CONFLICT, json!("cancellation_delivery_shape_mismatch")), "{name}");
        }
        let delivered = deliver(boundary.clone()).await;
        assert_eq!(delivered.0, StatusCode::OK, "{name}: {}", delivered.1);
        assert_eq!(delivered.1["delivered"], true, "{name}");
        assert_eq!(deliver(boundary.clone()).await.1, delivered.1, "{name}: a repeat returns the same receipt");
        let finished = finish_task(&app, &resumed, json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await;
        assert_eq!((finished.0, finished.1["run_status"].clone()), (StatusCode::OK, json!("cancelled")), "{name}: {}", finished.1);
        let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
        let types: Vec<_> = history.as_array().unwrap().iter().map(|event| event["event_type"].as_str().unwrap())
            .filter(|kind| kind.ends_with("Cancelled") || kind.starts_with("Cooperative")).collect();
        assert_eq!(types, ["CooperativeCancellationRequested", cancelled[0], cancelled[1], "CooperativeCancellationDelivered", "WorkflowCancelled"], "{name}");
        let delivery = history.as_array().unwrap().iter().find(|event| event["event_type"] == "CooperativeCancellationDelivered").unwrap();
        for field in ["call_kind", "operation_sequence", "operation_sequence_span"] {
            if let Some(value) = boundary.get(field) {
                assert_eq!(&delivery["payload"][field], value, "{name}: {field}");
            }
        }
        runtime.close().await;
        database.remove().await;
    }
}
