use super::*;

fn marker(change_id: &str) -> Value {
    json!({"type":"record_version_marker","change_id":change_id,"version":1,"min_supported":-1,"max_supported":1})
}

fn activity_command() -> Value {
    json!({"type":"schedule_activity","activity_type":"echo_activity","arguments":envelope(Payload::Array(vec![Payload::Long(9007199254740993)]))})
}

async fn finish_activity(app: &Router, activity: &Value) {
    let (status, result) = request(app, "POST", &format!("/api/worker/activity-tasks/{}/complete", activity["task_id"].as_str().unwrap()),
        json!({"lease_owner":activity["lease_owner"],"activity_attempt_id":activity["activity_attempt_id"],"result":envelope(Payload::Long(9007199254740993))})).await;
    assert_eq!(status, StatusCode::OK, "{result}");
}

#[tokio::test]
async fn markers_survive_restart_and_advance_later_authored_turns_once() {
    let database = TestDatabase::new().await;
    let first = database.open().await.unwrap();
    let app = router(first.clone());
    register(
        &app,
        "marker-worker",
        json!(["echo"]),
        json!(["echo_activity"]),
    )
    .await;
    let started = start(&app, "version-markers").await;
    let task = poll(&app, "marker-worker", "workflow").await;
    let mut before = marker(" \tfirst λ\n");
    before["payload_codec"] = json!("newer-ignored-codec");
    before["future_metadata"] = json!({"ignored":true});
    let commands = json!([before, activity_command(), marker("after-activity")]);
    assert_eq!(
        finish_task(&app, &task, commands.clone()).await.0,
        StatusCode::OK
    );
    let prefix = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let mut marker_payload = prefix[2]["payload"].clone();
    let original_task = marker_payload
        .as_object_mut()
        .unwrap()
        .remove("task")
        .unwrap();
    assert_eq!(original_task["id"], task["task_id"]);
    assert_eq!(original_task["lease_owner"], task["lease_owner"]);
    assert_eq!(
        marker_payload,
        json!({"sequence":1,"change_id":"first λ","version":1,"min_supported":-1,"max_supported":1})
    );
    assert_eq!(prefix[3]["payload"]["sequence"], 2);
    assert_eq!(prefix[4]["payload"]["sequence"], 3);
    assert_eq!(prefix[4]["event_type"], "VersionMarkerRecorded");
    assert_eq!(
        finish_task(&app, &task, commands.clone()).await.1["recorded"],
        false
    );
    let mut stale = task.clone();
    stale["workflow_task_attempt"] = json!(99);
    assert_eq!(
        finish_task(&app, &stale, commands).await.0,
        StatusCode::CONFLICT
    );
    assert_eq!(
        run_history(&app, &started["workflow_id"], &started["run_id"]).await,
        prefix
    );
    drop(app);
    first.close().await;

    let second = database.open().await.unwrap();
    let app = router(second.clone());
    register(
        &app,
        "marker-worker",
        json!(["echo"]),
        json!(["echo_activity"]),
    )
    .await;
    let original_activity = poll(&app, "marker-worker", "activity").await;
    assert_eq!(
        original_activity["activity_execution_id"],
        prefix[3]["payload"]["activity_execution_id"]
    );
    finish_activity(&app, &original_activity).await;
    let resumed = poll(&app, "marker-worker", "workflow").await;
    let commands = json!([marker("next-turn"), activity_command()]);
    assert_eq!(
        finish_task(&app, &resumed, commands.clone()).await.0,
        StatusCode::OK
    );
    assert_eq!(
        finish_task(&app, &resumed, commands).await.1["recorded"],
        false
    );
    let activity = poll(&app, "marker-worker", "activity").await;
    let scheduled = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(
        scheduled.as_array().unwrap().last().unwrap()["payload"]["sequence"],
        5,
        "marker at sequence 3 must advance the next worker turn"
    );
    assert_eq!(
        scheduled.as_array().unwrap().last().unwrap()["payload"]["activity_execution_id"],
        activity["activity_execution_id"]
    );
    finish_activity(&app, &activity).await;
    let completed = poll(&app, "marker-worker", "workflow").await;
    assert_eq!(
        finish_task(
            &app,
            &completed,
            json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}])
        )
        .await
        .0,
        StatusCode::OK
    );
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let events = history.as_array().unwrap();
    assert_eq!(&events[..5], prefix.as_array().unwrap());
    assert_eq!(events.len(), 12);
    let markers: Vec<_> = events
        .iter()
        .filter(|event| event["event_type"] == "VersionMarkerRecorded")
        .map(|event| event["payload"]["sequence"].as_i64().unwrap())
        .collect();
    assert_eq!(markers, vec![1, 3, 4]);
    assert_eq!(events[11]["event_type"], "WorkflowCompleted");
    second.close().await;
    database.remove().await;
}

#[tokio::test]
async fn invalid_marker_batches_preserve_original_lease_and_full_history() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "marker-worker", json!(["echo"]), json!([])).await;
    let started = start(&app, "invalid-version-markers").await;
    let task = poll(&app, "marker-worker", "workflow").await;
    let prefix = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    for (field, value) in [
        ("change_id", json!(" \t\n")),
        ("change_id", json!(1)),
        ("version", json!(1.0)),
        ("version", json!("1")),
        ("version", Value::Null),
        ("version", json!(2)),
        ("version", json!(-2)),
        ("min_supported", json!(2)),
        ("max_supported", json!(-2)),
        ("min_supported", json!("-1")),
        ("max_supported", Value::Null),
        ("retry_policy", json!({"max_attempts":2})),
        ("delay_seconds", json!(1)),
    ] {
        let mut bad = marker("invalid");
        bad[field] = value;
        let result = finish_task(&app, &task, json!([marker("must-not-commit"), bad])).await;
        assert_eq!(
            result.0,
            StatusCode::UNPROCESSABLE_ENTITY,
            "{field}: {}",
            result.1
        );
        assert_eq!(
            run_history(&app, &started["workflow_id"], &started["run_id"]).await,
            prefix,
            "whole batch remains unchanged"
        );
    }
    // The original attempt remains usable. VersionCall permits any ordered
    // integer range, rather than imposing a new native-only -1 lower bound.
    let command = json!({"type":"record_version_marker","change_id":"λ".repeat(256),"version":-3,"min_supported":-4,"max_supported":-2});
    assert_eq!(
        finish_task(
            &app,
            &task,
            json!([command, {"type":"complete_workflow","result":envelope(Payload::Null)}])
        )
        .await
        .0,
        StatusCode::OK
    );
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(history[2]["payload"]["version"], -3);
    assert_eq!(history[2]["payload"].as_object().unwrap().len(), 6);
    assert_eq!(history[2]["payload"]["task"]["id"], task["task_id"]);
    assert_eq!(history.as_array().unwrap().len(), 4);
    runtime.close().await;
    database.remove().await;
}
