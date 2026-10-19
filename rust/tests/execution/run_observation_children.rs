use super::*;

async fn observe(app: &Router, run: &Value) -> Value {
    let response = request(
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
    assert_eq!(response.0, StatusCode::OK, "{}", response.1);
    response.1
}

async fn history(app: &Router, run: &Value) -> Value {
    json!({"events":run_history(app, &run["workflow_id"], &run["run_id"]).await})
}

async fn parent(app: &Router, name: &str) -> (Value, Value) {
    register(app, "parent-observer", json!(["echo"]), json!([])).await;
    register(app, "child-observer", json!(["child"]), json!([])).await;
    let run = start(app, name).await;
    let task = poll(app, "parent-observer", "workflow").await;
    (run, task)
}

fn completed() -> Value {
    json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}])
}

async fn export(database: &TestDatabase, app: &Router, run: &Value) -> Value {
    let summary = observe(app, run).await;
    run_observation_summaries::export_reference(database, &summary, &history(app, run).await).await;
    summary
}

#[tokio::test]
async fn run_observation_children_preserve_identity_through_cold_completion_and_cancellation() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let mut originals = Vec::new();
    for cancelled in [false, true] {
        let (run, task) = parent(&app, &format!("child-observation-{cancelled}")).await;
        let command = json!([child_command(9007199254740993)]);
        let applied = finish_task(&app, &task, command.clone()).await;
        assert_eq!(applied.0, StatusCode::OK, "{}", applied.1);
        assert_eq!(finish_task(&app, &task, command).await.1["recorded"], false);
        let before = history(&app, &run).await;
        let scheduled = &before["events"][2]["payload"];
        let observed = export(&database, &app, &run).await;
        assert_eq!(observed["summary_state"], "observed", "{observed}");
        assert_eq!(observed["liveness_state"], "waiting_for_child");
        assert_eq!(
            observed["liveness_reason"],
            "Waiting for child workflow child."
        );
        let wait = &observed["current_waits"]["waits"][0];
        assert_eq!(observed["current_waits"]["returned_count"], 1);
        assert_eq!(
            wait["id"],
            format!("child:{}", scheduled["child_call_id"].as_str().unwrap())
        );
        assert_eq!(wait["dependency_id"], scheduled["child_workflow_run_id"]);
        assert_eq!(wait["dependency_type"], "child");
        assert_eq!(wait["kind"], "child");
        assert_eq!(wait["reason"], "Waiting for child workflow child.");
        assert_eq!(wait["state"], "resume_time_unknown");
        for field in [
            "task_id",
            "deadline_at",
            "next_scheduled_resume_at",
            "attempt_id",
            "attempt_number",
            "unavailable_reason",
        ] {
            assert!(wait[field].is_null(), "{field}: {wait}");
        }
        assert_eq!(history(&app, &run).await, before);
        originals.push((
            cancelled,
            run,
            observed["current_waits"]["waits"].clone(),
            before,
        ));
    }
    runtime.close().await;
    let a = database.open().await.unwrap();
    let b = database.open().await.unwrap();
    let (app_a, app_b) = (router(a.clone()), router(b.clone()));
    for (cancelled, run, wait, before) in originals {
        assert_eq!(observe(&app_b, &run).await["current_waits"]["waits"], wait);
        let child = poll(&app_a, "child-observer", "workflow").await;
        assert_eq!(
            child["run_id"],
            before["events"][2]["payload"]["child_workflow_run_id"]
        );
        assert_eq!(observe(&app_b, &run).await["current_waits"]["waits"], wait);
        let resolved = if cancelled {
            request(
                &app_a,
                "POST",
                &format!(
                    "/api/workflows/{}/runs/{}/cancel",
                    child["workflow_id"].as_str().unwrap(),
                    child["run_id"].as_str().unwrap()
                ),
                json!({"reason":"child observation cancellation λ"}),
            )
            .await
        } else {
            finish_task(&app_a, &child, completed()).await
        };
        assert_eq!(resolved.0, StatusCode::OK, "{}", resolved.1);
        let ready = export(&database, &app_b, &run).await;
        assert_eq!(ready["summary_state"], "observed", "{ready}");
        assert_eq!(ready["liveness_state"], "workflow_task_ready");
        assert_eq!(ready["current_waits"]["returned_count"], 0);
        assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_run_waits w JOIN workflow_tasks t ON t.id=w.task_id AND t.workflow_run_id=w.workflow_run_id WHERE w.kind='child' AND w.status IN ('resolved','cancelled') AND w.task_backed=TRUE AND w.task_type='workflow' AND w.task_status=t.status AND t.status='ready'").await, 1);
        let resumed = poll(&app_a, "parent-observer", "workflow").await;
        assert_eq!(resumed["run_id"], run["run_id"]);
        assert_eq!(resumed["child_workflow_run_id"], child["run_id"]);
        assert_eq!(
            export(&database, &app_b, &run).await["liveness_state"],
            "workflow_task_leased"
        );
        assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_run_waits w JOIN workflow_tasks t ON t.id=w.task_id AND t.workflow_run_id=w.workflow_run_id WHERE w.kind='child' AND w.status IN ('resolved','cancelled') AND w.task_backed=TRUE AND w.task_type='workflow' AND w.task_status=t.status AND t.status='leased'").await, 1);
        assert!(poll(&app_b, "parent-observer", "workflow").await.is_null());
        assert_eq!(
            finish_task(&app_a, &resumed, completed()).await.0,
            StatusCode::OK
        );
        assert_eq!(
            export(&database, &app_b, &run).await["liveness_state"],
            "closed"
        );
    }
    a.close().await;
    b.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_children_missing_wait_is_not_repaired_by_reads_or_resolution() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (run, task) = parent(&app, "missing-child-wait").await;
    assert_eq!(
        finish_task(&app, &task, json!([child_command(1)])).await.0,
        StatusCode::OK
    );
    let original = observe(&app, &run).await;
    assert_eq!(original["summary_state"], "observed");
    database
        .execute("DELETE FROM workflow_run_waits WHERE kind='child'")
        .await;
    let before = history(&app, &run).await;
    for _ in 0..2 {
        let read = observe(&app, &run).await;
        assert_eq!(read["current_waits"]["returned_count"], 0);
        assert_eq!(read["summary_observed_at"], original["summary_observed_at"]);
    }
    assert_eq!(history(&app, &run).await, before);
    let child = poll(&app, "child-observer", "workflow").await;
    assert_eq!(
        finish_task(&app, &child, completed()).await.0,
        StatusCode::OK
    );
    let after = observe(&app, &run).await;
    assert_eq!(after["summary_state"], "unavailable", "{after}");
    assert_eq!(after["current_waits"]["returned_count"], 0);
    let resumed = poll(&app, "parent-observer", "workflow").await;
    assert_eq!(
        resumed["run_id"], run["run_id"],
        "observation loss does not suppress business resumption"
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_children_projection_insert_failure_preserves_original_parent_claim() {
    let database = TestDatabase::Sqlite(tempfile::tempdir().unwrap());
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (run, task) = parent(&app, "child-projection-rollback").await;
    let before = history(&app, &run).await;
    database.execute("CREATE TRIGGER reject_child_wait BEFORE INSERT ON workflow_run_waits WHEN NEW.kind='child' BEGIN SELECT RAISE(ABORT,'child wait insert failed'); END").await;
    let commands = json!([child_command(1)]);
    let failed = finish_task(&app, &task, commands.clone()).await;
    assert_eq!(failed.0, StatusCode::SERVICE_UNAVAILABLE, "{}", failed.1);
    assert_eq!(history(&app, &run).await, before);
    assert!(poll(&app, "child-observer", "workflow").await.is_null());
    database.execute("DROP TRIGGER reject_child_wait").await;
    assert_eq!(finish_task(&app, &task, commands).await.0, StatusCode::OK);
    assert_eq!(
        observe(&app, &run).await["liveness_state"],
        "waiting_for_child"
    );
    runtime.close().await;
}

#[tokio::test]
async fn run_observation_children_parent_summary_failure_rolls_back_child_resolution() {
    let database = TestDatabase::Sqlite(tempfile::tempdir().unwrap());
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (run, task) = parent(&app, "child-summary-rollback").await;
    assert_eq!(
        finish_task(&app, &task, json!([child_command(1)])).await.0,
        StatusCode::OK
    );
    let child = poll(&app, "child-observer", "workflow").await;
    let before = history(&app, &run).await;
    let child_before = history(&app, &child).await;
    database.execute("CREATE TRIGGER reject_child_parent_summary BEFORE INSERT ON workflow_run_summaries WHEN NEW.workflow_type='echo' AND NEW.liveness_state='workflow_task_ready' BEGIN SELECT RAISE(ABORT,'parent summary insert failed'); END").await;
    let failed = finish_task(&app, &child, completed()).await;
    assert_eq!(failed.0, StatusCode::SERVICE_UNAVAILABLE, "{}", failed.1);
    assert_eq!(history(&app, &run).await, before);
    assert_eq!(history(&app, &child).await, child_before);
    assert!(poll(&app, "parent-observer", "workflow").await.is_null());
    database
        .execute("DROP TRIGGER reject_child_parent_summary")
        .await;
    assert_eq!(
        finish_task(&app, &child, completed()).await.0,
        StatusCode::OK
    );
    assert_eq!(
        observe(&app, &run).await["liveness_state"],
        "workflow_task_ready"
    );
    runtime.close().await;
}

#[tokio::test]
async fn run_observation_children_sequential_outcomes_keep_published_precedence() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    for cancelled in [false, true] {
        let (run, task) = parent(&app, &format!("sequential-observation-{cancelled}")).await;
        assert_eq!(
            finish_task(&app, &task, json!([child_command(1)])).await.0,
            StatusCode::OK
        );
        let child = poll(&app, "child-observer", "workflow").await;
        if cancelled {
            let result = request(
                &app,
                "POST",
                &format!(
                    "/api/workflows/{}/runs/{}/cancel",
                    child["workflow_id"].as_str().unwrap(),
                    child["run_id"].as_str().unwrap()
                ),
                json!({"reason":"first child cancelled"}),
            )
            .await;
            assert_eq!(result.0, StatusCode::OK, "{}", result.1);
        } else {
            assert_eq!(
                finish_task(&app, &child, completed()).await.0,
                StatusCode::OK
            );
        }
        let resumed = poll(&app, "parent-observer", "workflow").await;
        assert_eq!(
            finish_task(&app, &resumed, json!([child_command(2)]))
                .await
                .0,
            StatusCode::OK
        );
        let second = export(&database, &app, &run).await;
        assert_eq!(second["summary_state"], "observed", "{second}");
        assert_eq!(
            second["liveness_state"],
            if cancelled {
                "repair_needed"
            } else {
                "waiting_for_child"
            }
        );
        assert_eq!(second["current_waits"]["returned_count"], 1);
        assert_ne!(
            second["current_waits"]["waits"][0]["dependency_id"],
            child["run_id"]
        );
        let next = poll(&app, "child-observer", "workflow").await;
        assert_eq!(
            finish_task(&app, &next, completed()).await.0,
            StatusCode::OK
        );
        assert_eq!(
            export(&database, &app, &run).await["liveness_state"],
            "workflow_task_ready"
        );
        let final_task = poll(&app, "parent-observer", "workflow").await;
        assert_eq!(
            finish_task(&app, &final_task, completed()).await.0,
            StatusCode::OK
        );
    }
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn run_observation_children_mismatched_projection_loses_summary_without_affecting_resume() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (run, task) = parent(&app, "mismatched-child-observation").await;
    assert_eq!(
        finish_task(&app, &task, json!([child_command(1)])).await.0,
        StatusCode::OK
    );
    database
        .execute("UPDATE workflow_run_waits SET resume_source_id='wrong-child' WHERE kind='child'")
        .await;
    let before = history(&app, &run).await;
    assert_eq!(
        observe(&app, &run).await["current_waits"]["waits"][0]["dependency_id"],
        "wrong-child"
    );
    assert_eq!(history(&app, &run).await, before);
    let child = poll(&app, "child-observer", "workflow").await;
    assert_eq!(
        finish_task(&app, &child, completed()).await.0,
        StatusCode::OK
    );
    assert_eq!(observe(&app, &run).await["summary_state"], "unavailable");
    assert_eq!(
        poll(&app, "parent-observer", "workflow").await["run_id"],
        run["run_id"]
    );
    runtime.close().await;
    database.remove().await;
}
