use super::*;

// Published Server 2.11.5 accepts a workflow task completion with any
// non-empty number of commands from an external worker; each command meets
// the run's structural limits (pending activities and timers) instead. The
// large-command-batches fixture records 150 timers and 150 activities in one
// completion, a 150-member parallel group and a refused 1,001-member group.

#[tokio::test]
async fn a_completion_may_carry_more_than_a_hundred_commands() {
    for (workflow, command, event) in [
        ("many-timers", json!({"type":"start_timer","delay_seconds":3600}), "TimerScheduled"),
        ("many-activities", json!({"type":"schedule_activity","activity_type":"activity-echo","arguments":envelope(Payload::Array(vec![]))}),
            "ActivityScheduled"),
    ] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        register(&app, "worker", json!(["echo"]), json!(["activity-echo"])).await;
        let started = start(&app, workflow).await;
        let task = poll(&app, "worker", "workflow").await;
        let (status, body) = finish_task(&app, &task, json!(vec![command; 150])).await;
        assert_eq!(status, StatusCode::OK, "{workflow}: {body}");
        assert_eq!((body["outcome"].clone(), body["run_status"].clone()), (json!("completed"), json!("waiting")), "{workflow}");
        assert_eq!(body["created_task_ids"].as_array().map(Vec::len), Some(150), "{workflow}");
        // The run is described as waiting on its first open wait, as PHP
        // summarizes every open wait.
        let (_, described) = request(&app, "GET", &format!("/api/workflows/{workflow}"), Value::Null).await;
        let kind = if event == "TimerScheduled" { "timer" } else { "activity" };
        assert_eq!((described["status"].clone(), described["wait_kind"].clone()), (json!("waiting"), json!(kind)), "{workflow}: {described}");
        let run = started["run_id"].as_str().unwrap();
        assert_eq!(task_retry::rows(&database, Box::leak(format!(
            "SELECT COUNT(*) FROM workflow_history_events WHERE workflow_run_id='{run}' AND event_type='{event}'").into_boxed_str())).await, 150, "{workflow}");
        runtime.close().await;
        database.remove().await;
    }
}

fn group_member(size: usize, index: usize) -> Value {
    let entry = json!({"parallel_group_id":format!("parallel-activities:1:{size}"),"parallel_group_kind":"activity",
        "parallel_group_base_sequence":1,"parallel_group_size":size,"parallel_group_index":index});
    let mut command = json!({"type":"schedule_activity","activity_type":"activity-echo","arguments":envelope(Payload::Array(vec![]))});
    command.as_object_mut().unwrap().extend(entry.as_object().unwrap().clone());
    command["parallel_group_path"] = json!([entry]);
    command
}

// A parallel group's declared size is bounded by the command batch size
// (1,000 by default), not by 100. PHP's preflight refuses a larger group on
// every command before any guard, and records nothing.
#[tokio::test]
async fn a_parallel_group_may_hold_up_to_the_command_batch_size() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "worker", json!(["echo"]), json!(["activity-echo"])).await;
    let over = start(&app, "group-over-limit").await;
    let task = poll(&app, "worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([group_member(1001, 0), group_member(1001, 1)])).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::UNPROCESSABLE_ENTITY, json!("validation_failed")), "{body}");
    let message = "Parallel workflow commands require complete top-level metadata and a non-empty group path.";
    assert_eq!(body["message"], format!("{message} (and 1 more error)"));
    assert_eq!(body["errors"], json!({"commands.0.parallel_group_path":[message],"commands.1.parallel_group_path":[message]}));
    let run = over["run_id"].as_str().unwrap();
    assert_eq!(task_retry::rows(&database, Box::leak(format!(
        "SELECT COUNT(*) FROM workflow_history_events WHERE workflow_run_id='{run}' AND event_type='ActivityScheduled'").into_boxed_str())).await, 0);

    let started = start(&app, "group-of-150").await;
    let task = poll(&app, "worker", "workflow").await;
    assert_eq!(task["workflow_id"], "group-of-150", "{task}");
    let commands: Vec<_> = (0..150).map(|index| group_member(150, index)).collect();
    let (status, body) = finish_task(&app, &task, json!(commands)).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["created_task_ids"].as_array().map(Vec::len), Some(150));
    let (_, described) = request(&app, "GET", "/api/workflows/group-of-150", Value::Null).await;
    assert_eq!((described["status"].clone(), described["wait_kind"].clone()), (json!("waiting"), json!("activity")), "{described}");
    // Every member records the group, read through the history API so the
    // check is the same on each database.
    let run = started["run_id"].as_str().unwrap();
    let (_, history) = request(&app, "GET", &format!("/api/workflows/group-of-150/runs/{run}/history?page_size=1000"), Value::Null).await;
    assert_eq!(history["events"].as_array().unwrap().iter().filter(|event| event["event_type"] == "ActivityScheduled"
        && event["payload"]["parallel_group_id"] == "parallel-activities:1:150").count(), 150, "{history}");
    runtime.close().await;
    database.remove().await;
}

fn select_member(base: i64, member_size: i64, key: Value) -> Value {
    let entry = json!({"parallel_group_id":format!("select-calls:{base}:2"),"parallel_group_kind":"activity",
        "parallel_group_mode":"select","parallel_group_base_sequence":base,"parallel_group_size":2,"parallel_group_index":0,
        "selection_member_key":key,"selection_member_index":0,"selection_member_base_sequence":base,
        "selection_member_size":member_size,"selection_member_kind":"activity"});
    let mut command = json!({"type":"schedule_activity","activity_type":"activity-echo","arguments":envelope(Payload::Array(vec![]))});
    command.as_object_mut().unwrap().extend(entry.as_object().unwrap().clone());
    command["parallel_group_path"] = json!([entry]);
    command
}

// PHP's preflight refuses a selection member whose range passes the integer
// range (its sums become floats) and a numeric member key above it (a float
// after JSON decoding), with the group path message.
#[tokio::test]
async fn select_metadata_past_the_integer_range_is_refused_by_the_preflight() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "worker", json!(["echo"]), json!(["activity-echo"])).await;
    start(&app, "select-extremes").await;
    let task = poll(&app, "worker", "workflow").await;
    let message = "Parallel workflow commands require complete top-level metadata and a non-empty group path.";
    for command in [
        select_member(1, i64::MAX, json!("a")),
        select_member(i64::MAX, 1, json!("a")),
        select_member(1, 1, json!(9_223_372_036_854_775_808_u64)),
    ] {
        let (status, body) = finish_task(&app, &task, json!([command])).await;
        assert_eq!((status, body["reason"].clone()), (StatusCode::UNPROCESSABLE_ENTITY, json!("validation_failed")), "{body}");
        assert_eq!(body["errors"], json!({"commands.0.parallel_group_path":[message]}), "{body}");
    }
    // The same member with an ordinary range and key passes the preflight.
    let (_, body) = finish_task(&app, &task, json!([select_member(1, 1, json!(9_223_372_036_854_775_807_i64))])).await;
    assert_ne!(body["reason"], "validation_failed", "{body}");
    runtime.close().await;
    database.remove().await;
}
