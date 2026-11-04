use super::*;

fn group(size: usize, index: usize) -> Value {
    json!({"parallel_group_id":format!("parallel-children:1:{size}"),"parallel_group_kind":"child",
        "parallel_group_base_sequence":1,"parallel_group_size":size,"parallel_group_index":index})
}

fn children(size: usize) -> Value {
    json!(
        (0..size)
            .map(|index| {
                let mut command = child_command(9007199254740993 + index as i64);
                command["queue"] = json!("children");
                command
                    .as_object_mut()
                    .unwrap()
                    .extend(group(size, index).as_object().unwrap().clone());
                command["parallel_group_path"] = json!([group(size, index)]);
                command
            })
            .collect::<Vec<_>>()
    )
}

async fn workers(app: &Router) {
    register(app, "parent-worker", json!(["echo"]), json!([])).await;
    let (status, body) = request(app, "POST", "/api/worker/register", json!({"worker_id":"child-worker",
        "task_queue":"children","runtime":"php","supported_workflow_types":["child"],"supported_activity_types":[]})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

async fn child_poll(app: &Router) -> Value {
    let (status, body) = request(
        app,
        "POST",
        "/api/worker/workflow-tasks/poll",
        json!({
        "worker_id":"child-worker","task_queue":"children","timeout_seconds":0}),
    )
    .await;
    assert_eq!(status, StatusCode::OK, "{body}");
    body["task"].clone()
}

fn done(value: i64) -> Value {
    json!([{"type":"complete_workflow","result":envelope(Payload::Long(value))}])
}

async fn count(database: &TestDatabase, sql: String) -> i64 {
    task_retry::rows(database, Box::leak(sql.into_boxed_str())).await
}

async fn parent_tasks(database: &TestDatabase, run: &Value, status: &str) -> i64 {
    count(database, format!("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id='{}' AND task_type='workflow' AND status='{status}'", run["run_id"].as_str().unwrap())).await
}

async fn observation(app: &Router, run: &Value) -> Value {
    let (status, value) = request(
        app,
        "GET",
        &format!(
            "/api/workflows/{}/runs/{}/observation",
            run["workflow_id"].as_str().unwrap(),
            run["run_id"].as_str().unwrap()
        ),
        Value::Null,
    )
    .await;
    assert_eq!(status, StatusCode::OK, "{value}");
    value
}

async fn observed(app: &Router, run: &Value, open: usize) {
    let value = observation(app, run).await;
    assert_eq!(value["summary_state"], "observed", "{value}");
    assert_eq!(
        value["current_waits"]["returned_count"],
        open.min(50),
        "{value}"
    );
}

fn scheduled(history: &Value) -> Vec<&Value> {
    history
        .as_array()
        .unwrap()
        .iter()
        .filter(|event| event["event_type"] == "ChildWorkflowScheduled")
        .map(|event| &event["payload"])
        .collect()
}

fn check_paths(history: &Value, commands: &Value) {
    for event in history
        .as_array()
        .unwrap()
        .iter()
        .filter(|event| event["event_type"].as_str().unwrap().starts_with("Child"))
    {
        let payload = &event["payload"];
        let index = payload["sequence"].as_u64().unwrap() as usize - 1;
        assert_eq!(
            payload["parallel_group_path"], commands[index]["parallel_group_path"],
            "{event}"
        );
        assert_eq!(
            payload["parallel_group_id"],
            commands[index]["parallel_group_id"]
        );
    }
}

#[tokio::test]
async fn flat_children_keep_original_ids_out_of_order_results_and_one_cold_barrier_wakeup() {
    let database = TestDatabase::new().await;
    let first = database.open().await.unwrap();
    let app = router(first.clone());
    workers(&app).await;
    let run = start(&app, "child-all-cold").await;
    let initial = poll(&app, "parent-worker", "workflow").await;
    let commands = children(3);
    let admitted = finish_task(&app, &initial, commands.clone()).await;
    assert_eq!(admitted.0, StatusCode::OK, "{}", admitted.1);
    assert_eq!(admitted.1["created_task_ids"].as_array().unwrap().len(), 3);
    let prefix = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    assert_eq!(prefix.as_array().unwrap().len(), 8);
    check_paths(&prefix, &commands);
    observed(&app, &run, 3).await;
    assert_eq!(
        finish_task(&app, &initial, commands.clone()).await.1["recorded"],
        false
    );
    assert_eq!(
        run_history(&app, &run["workflow_id"], &run["run_id"]).await,
        prefix
    );
    drop(app);
    first.close().await;

    let (one, two) = tokio::join!(database.open(), database.open());
    let (one, two) = (one.unwrap(), two.unwrap());
    let (app_a, app_b) = (router(one.clone()), router(two.clone()));
    let mut tasks = Vec::new();
    for _ in 0..3 {
        tasks.push(child_poll(&app_a).await);
    }
    tasks.sort_by_key(|task| {
        scheduled(&prefix)
            .iter()
            .position(|payload| payload["child_workflow_run_id"] == task["run_id"])
            .unwrap()
    });
    for (index, task) in tasks.iter().enumerate() {
        assert_eq!(
            task["workflow_id"],
            scheduled(&prefix)[index]["child_workflow_instance_id"]
        );
        assert_eq!(task["arguments"], commands[index]["arguments"]);
    }
    assert_eq!(
        finish_task(&app_b, &tasks[2], done(9007199254740995))
            .await
            .0,
        StatusCode::OK
    );
    assert!(poll(&app_a, "parent-worker", "workflow").await.is_null());
    assert_eq!(parent_tasks(&database, &run, "ready").await, 0);
    observed(&app_a, &run, 2).await;
    // Competing nodes close the remaining members. The parent transition lock
    // must make exactly one ready resume observe the complete barrier.
    let (a, b) = tokio::join!(
        finish_task(&app_a, &tasks[0], done(9007199254740993)),
        finish_task(&app_b, &tasks[1], done(9007199254740994))
    );
    assert_eq!(a.0, StatusCode::OK, "{}", a.1);
    assert_eq!(b.0, StatusCode::OK, "{}", b.1);
    assert_eq!(parent_tasks(&database, &run, "ready").await, 1);
    let history = run_history(&app_a, &run["workflow_id"], &run["run_id"]).await;
    assert_eq!(
        &history.as_array().unwrap()[..8],
        prefix.as_array().unwrap()
    );
    check_paths(&history, &commands);
    for event in history
        .as_array()
        .unwrap()
        .iter()
        .filter(|event| event["event_type"] == "ChildRunCompleted")
    {
        let index = event["payload"]["sequence"].as_u64().unwrap() as usize - 1;
        let original = scheduled(&prefix)[index];
        for key in [
            "child_call_id",
            "workflow_link_id",
            "child_workflow_instance_id",
            "child_workflow_run_id",
        ] {
            assert_eq!(event["payload"][key], original[key]);
        }
        assert_eq!(
            event["payload"]["result"],
            done(9007199254740993 + index as i64)[0]["result"]
        );
    }
    assert_eq!(
        finish_task(&app_a, &initial, commands).await.1["recorded"],
        false
    );
    assert_eq!(
        finish_task(&app_a, &tasks[1], done(9007199254740994))
            .await
            .1["recorded"],
        false
    );
    assert_eq!(
        run_history(&app_a, &run["workflow_id"], &run["run_id"]).await,
        history
    );
    observed(&app_a, &run, 0).await;
    let resumed = poll(&app_a, "parent-worker", "workflow").await;
    assert_eq!(resumed["run_id"], run["run_id"]);
    let result = envelope(Payload::Array(
        (0..3)
            .map(|index| Payload::Long(9007199254740993 + index))
            .collect(),
    ));
    assert_eq!(
        finish_task(
            &app_b,
            &resumed,
            json!([{"type":"complete_workflow","result":result}])
        )
        .await
        .0,
        StatusCode::OK
    );
    assert!(poll(&app_a, "parent-worker", "workflow").await.is_null());
    let final_history = run_history(&app_a, &run["workflow_id"], &run["run_id"]).await;
    assert_eq!(
        final_history.as_array().unwrap().last().unwrap()["payload"]["output"],
        result
    );
    one.close().await;
    two.close().await;
    database.remove().await;
}

#[tokio::test]
async fn failed_cancelled_and_terminated_members_wake_immediately_without_reopening_on_success() {
    for close in ["fail", "cancel", "terminate"] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        workers(&app).await;
        let run = start(&app, &format!("child-all-{close}")).await;
        let initial = poll(&app, "parent-worker", "workflow").await;
        let commands = children(2);
        assert_eq!(
            finish_task(&app, &initial, commands.clone()).await.0,
            StatusCode::OK
        );
        let first = child_poll(&app).await;
        let second = child_poll(&app).await;
        let closed = if close == "fail" {
            finish_task(&app, &first, json!([{"type":"fail_workflow","message":"child failed λ","exception_type":"Boom","exception_class":"Boom"}])).await
        } else {
            request(
                &app,
                "POST",
                &format!(
                    "/api/workflows/{}/{close}",
                    first["workflow_id"].as_str().unwrap()
                ),
                json!({"reason":"child closed λ"}),
            )
            .await
        };
        assert_eq!(closed.0, StatusCode::OK, "{}", closed.1);
        assert_eq!(parent_tasks(&database, &run, "ready").await, 1);
        let resumed = poll(&app, "parent-worker", "workflow").await;
        assert_eq!(resumed["run_id"], run["run_id"]);
        let event = resumed["history_events"]
            .as_array()
            .unwrap()
            .last()
            .unwrap();
        assert_eq!(
            event["payload"]["child_call_id"],
            scheduled(&resumed["history_events"])[0]["child_call_id"]
        );
        assert!(event["payload"]["failure_id"].is_string());
        // A waiting acknowledgment lets the successful sibling test the closed
        // barrier without a ready task accidentally coalescing a second wakeup.
        let waiting = request(&app, "POST", &format!("/api/worker/workflow-tasks/{}/fail", resumed["task_id"].as_str().unwrap()),
            json!({"lease_owner":resumed["lease_owner"],"workflow_task_attempt":resumed["workflow_task_attempt"],
                "failure":{"type":"WorkflowTaskWaitingForHistory","message":"retaining failed barrier"}})).await;
        assert_eq!(waiting.0, StatusCode::OK, "{}", waiting.1);
        assert_eq!(finish_task(&app, &second, done(42)).await.0, StatusCode::OK);
        assert!(poll(&app, "parent-worker", "workflow").await.is_null());
        let history = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
        check_paths(&history, &commands);
        let summary = observation(&app, &run).await;
        assert_eq!(summary["summary_state"], "observed", "{close}: {summary}");
        assert_eq!(
            summary["liveness_state"], "repair_needed",
            "{close}: {summary}"
        );
        runtime.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn incomplete_nested_mixed_selected_and_started_groups_leave_the_original_lease_unchanged() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    workers(&app).await;
    let run = start(&app, "refused-child-all").await;
    let initial = poll(&app, "parent-worker", "workflow").await;
    let before = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    let mut cases = vec![json!([children(2)[0]])];
    for shape in [
        "duplicate",
        "nested",
        "mixed",
        "select",
        "started",
        "scope",
        "sequence",
    ] {
        let mut commands = children(2);
        match shape {
            "duplicate" => commands[1] = commands[0].clone(),
            "nested" => {
                let group = commands[0]["parallel_group_path"][0].clone();
                commands[0]["parallel_group_path"]
                    .as_array_mut()
                    .unwrap()
                    .push(group);
            }
            "mixed" => {
                for command in commands.as_array_mut().unwrap() {
                    command["parallel_group_kind"] = json!("mixed");
                    command["parallel_group_id"] = json!("parallel-calls:1:2");
                    let mut entry = command.clone();
                    entry.as_object_mut().unwrap().retain(|key, _| {
                        key.starts_with("parallel_group_") && key != "parallel_group_path"
                    });
                    command["parallel_group_path"] = json!([entry]);
                }
            }
            "select" => commands[0]["parallel_group_mode"] = json!("select"),
            "started" => commands[0]["await"] = json!("started"),
            "scope" => commands[0]["cancellation_scope_id"] = json!("unqualified-child-scope"),
            "sequence" => {
                for command in commands.as_array_mut().unwrap() {
                    command["parallel_group_base_sequence"] = json!(2);
                    command["parallel_group_id"] = json!("parallel-children:2:2");
                    let mut entry = command.clone();
                    entry.as_object_mut().unwrap().retain(|key, _| {
                        key.starts_with("parallel_group_") && key != "parallel_group_path"
                    });
                    command["parallel_group_path"] = json!([entry]);
                }
            }
            _ => unreachable!(),
        }
        cases.push(commands);
    }
    for commands in cases {
        let denied = finish_task(&app, &initial, commands.clone()).await;
        assert!(
            matches!(
                denied.0,
                StatusCode::UNPROCESSABLE_ENTITY | StatusCode::CONFLICT
            ),
            "{commands}: {}",
            denied.1
        );
        assert_eq!(
            run_history(&app, &run["workflow_id"], &run["run_id"]).await,
            before
        );
        assert_eq!(
            count(
                &database,
                "SELECT COUNT(*) FROM workflow_child_calls".into()
            )
            .await,
            0
        );
        assert_eq!(
            count(&database, "SELECT COUNT(*) FROM workflow_links".into()).await,
            0
        );
        assert_eq!(parent_tasks(&database, &run, "leased").await, 1);
        assert!(child_poll(&app).await.is_null());
    }
    assert_eq!(
        finish_task(&app, &initial, children(2)).await.0,
        StatusCode::OK
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn retried_and_continued_members_keep_the_original_call_path_and_barrier() {
    for transition in ["retry", "continue"] {
        let database = TestDatabase::new().await;
        let first = database.open().await.unwrap();
        let app = router(first.clone());
        workers(&app).await;
        let run = start(&app, &format!("child-all-{transition}")).await;
        let initial = poll(&app, "parent-worker", "workflow").await;
        let mut commands = children(2);
        if transition == "retry" {
            commands[0]["retry_policy"] = json!({"max_attempts":2,"backoff_seconds":[0]});
        }
        assert_eq!(
            finish_task(&app, &initial, commands.clone()).await.0,
            StatusCode::OK
        );
        let prefix = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
        let a = child_poll(&app).await;
        let b = child_poll(&app).await;
        let (member, sibling) = if a["run_id"] == scheduled(&prefix)[0]["child_workflow_run_id"] {
            (a, b)
        } else {
            (b, a)
        };
        assert_eq!(
            finish_task(&app, &sibling, done(42)).await.0,
            StatusCode::OK
        );
        let next = if transition == "retry" {
            json!([{"type":"fail_workflow","message":"retry group member"}])
        } else {
            json!([{"type":"continue_as_new"}])
        };
        assert_eq!(finish_task(&app, &member, next).await.0, StatusCode::OK);
        assert!(poll(&app, "parent-worker", "workflow").await.is_null());
        check_paths(
            &run_history(&app, &run["workflow_id"], &run["run_id"]).await,
            &commands,
        );
        observed(&app, &run, 1).await;
        drop(app);
        first.close().await;
        let cold = database.open().await.unwrap();
        let app = router(cold.clone());
        let replacement = child_poll(&app).await;
        assert_eq!(replacement["workflow_id"], member["workflow_id"]);
        assert_ne!(replacement["run_id"], member["run_id"]);
        assert_eq!(
            finish_task(&app, &replacement, done(9007199254740993))
                .await
                .0,
            StatusCode::OK
        );
        let resumed = poll(&app, "parent-worker", "workflow").await;
        assert_eq!(resumed["run_id"], run["run_id"]);
        check_paths(&resumed["history_events"], &commands);
        let last = resumed["history_events"]
            .as_array()
            .unwrap()
            .last()
            .unwrap();
        assert_eq!(
            last["payload"]["child_call_id"],
            scheduled(&prefix)[0]["child_call_id"]
        );
        assert_eq!(
            last["payload"]["child_workflow_run_id"],
            replacement["run_id"]
        );
        observed(&app, &run, 0).await;
        assert_eq!(
            finish_task(&app, &resumed, done(42)).await.0,
            StatusCode::OK
        );
        cold.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn one_and_full_batch_child_barriers_have_bounded_wait_inventory() {
    for size in [1, 100] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        workers(&app).await;
        let run = start(&app, &format!("bounded-children-{size}")).await;
        let initial = poll(&app, "parent-worker", "workflow").await;
        let admitted = finish_task(&app, &initial, children(size)).await;
        assert_eq!(admitted.0, StatusCode::OK, "{}", admitted.1);
        assert_eq!(
            admitted.1["created_task_ids"].as_array().unwrap().len(),
            size
        );
        let summary = observation(&app, &run).await;
        assert_eq!(summary["summary_state"], "observed", "{summary}");
        assert_eq!(summary["history_fan_out"], size);
        assert_eq!(summary["current_waits"]["returned_count"], size.min(50));
        assert_eq!(summary["current_waits"]["has_more"], size > 50);
        assert_eq!(parent_tasks(&database, &run, "ready").await, 0);
        runtime.close().await;
        database.remove().await;
    }
}

#[tokio::test]
async fn child_group_projection_failure_rolls_back_all_starts_and_original_lease() {
    let database = TestDatabase::new().await;
    let TestDatabase::Sqlite(_) = &database else {
        return;
    };
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    workers(&app).await;
    let run = start(&app, "atomic-child-group").await;
    let initial = poll(&app, "parent-worker", "workflow").await;
    let before = run_history(&app, &run["workflow_id"], &run["run_id"]).await;
    database.execute("CREATE TRIGGER reject_second_child_wait BEFORE INSERT ON workflow_run_waits WHEN NEW.kind='child' AND NEW.sequence=2 BEGIN SELECT RAISE(ABORT,'child group fault'); END").await;
    assert_eq!(
        finish_task(&app, &initial, children(2)).await.0,
        StatusCode::SERVICE_UNAVAILABLE
    );
    assert_eq!(
        run_history(&app, &run["workflow_id"], &run["run_id"]).await,
        before
    );
    assert_eq!(
        count(
            &database,
            "SELECT COUNT(*) FROM workflow_child_calls".into()
        )
        .await,
        0
    );
    assert_eq!(
        count(&database, "SELECT COUNT(*) FROM workflow_links".into()).await,
        0
    );
    assert_eq!(
        count(&database, "SELECT COUNT(*) FROM workflow_instances".into()).await,
        1
    );
    assert_eq!(parent_tasks(&database, &run, "leased").await, 1);
    assert!(child_poll(&app).await.is_null());
    database
        .execute("DROP TRIGGER reject_second_child_wait")
        .await;
    assert_eq!(
        finish_task(&app, &initial, children(2)).await.0,
        StatusCode::OK
    );
    observed(&app, &run, 2).await;
    runtime.close().await;
    database.remove().await;
}
