use super::{cooperative_child_resume::*, cooperative_children::*, *};
use std::time::Duration;

async fn setup(app: &Router) -> (Value, Value) {
    let registered = request_protocol(
        app,
        "POST",
        "/api/worker/register",
        json!({
        "worker_id":"cleanup-worker","task_queue":"test","runtime":"php",
        "supported_workflow_types":["echo","child"],"supported_activity_types":[],
        "capabilities":["cooperative_cancellation"]}),
        "1.20",
    )
    .await;
    assert_eq!(registered.0, StatusCode::CREATED, "{}", registered.1);
    let parent = start(app, "abandon-parent").await;
    let task = cooperative_poll(app, "workflow").await;
    let scheduled = finish_task(app, &task, json!([child_command(9007199254740993)])).await;
    assert_eq!(scheduled.0, StatusCode::OK, "{}", scheduled.1);
    let child = cooperative_poll(app, "workflow").await;
    assert!(!child.is_null());
    assert_ne!(child["run_id"], parent["run_id"]);
    (parent, child)
}

async fn request_child(app: &Router, child: &Value, seconds: i64) -> (StatusCode, Value) {
    request(
        app,
        "POST",
        &format!(
            "/api/workflows/{}/runs/{}/request-cancellation",
            child["workflow_id"].as_str().unwrap(),
            child["run_id"].as_str().unwrap()
        ),
        json!({"reason":"independent child λ","cleanup_timeout_seconds":seconds}),
    )
    .await
}

async fn finish(app: &Router, child: &Value, failure: bool) -> (StatusCode, Value) {
    let commands = if failure {
        json!([{"type":"fail_workflow","message":"cleanup failure retains cancellation"}])
    } else {
        json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}])
    };
    finish_task(app, child, commands).await
}

async fn independent(parent_state: &str, terminal: &str, missing: bool, rollback: bool) {
    let database = if rollback {
        TestDatabase::Sqlite(tempfile::tempdir().unwrap())
    } else {
        TestDatabase::new().await
    };
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (parent, child) = setup(&app).await;
    let parent_request = if parent_state == "waiting" {
        Value::Null
    } else {
        root_request(&app, 60).await
    };
    let root = if parent_state == "leased" || parent_state == "closed" {
        let task = cooperative_poll(&app, "workflow").await;
        assert_eq!(task["run_id"], parent["run_id"]);
        if parent_state == "closed" {
            let delivery = deliver(&app, &task, &parent_request, 1, "child").await;
            assert_eq!(delivery.0, StatusCode::OK, "{}", delivery.1);
            let completed = finish_task(
                &app,
                &task,
                json!([{"type":"complete_workflow","result":envelope(Payload::Null)}]),
            )
            .await;
            assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
            Value::Null
        } else {
            task
        }
    } else if parent_state == "ready" {
        parent_task(&database, &parent).await
    } else {
        Value::Null
    };
    let original_root = if root.is_null() {
        Value::Null
    } else {
        child_claim(&database, &root).await
    };
    let requested = request_child(&app, &child, if terminal == "deadline" { 1 } else { 30 }).await;
    assert_eq!(requested.0, StatusCode::ACCEPTED, "{}", requested.1);
    let pending = requested.1["cancellation_request"].clone();
    assert_ne!(pending["request_id"], parent_request["request_id"]);
    let requested_history = run_history(&app, &child["workflow_id"], &child["run_id"]).await;
    let context = requested_history
        .as_array()
        .unwrap()
        .iter()
        .find(|event| event["event_type"] == "CooperativeCancellationRequested")
        .unwrap()["payload"]["cancellation"]
        .clone();
    assert_eq!(context["request_id"], pending["request_id"]);
    assert_eq!(context["root_request_id"], pending["request_id"]);
    assert_eq!(context["root_workflow_run_id"], child["run_id"]);
    assert_eq!(context["root_workflow_instance_id"], child["workflow_id"]);
    assert!(context["parent_request_id"].is_null());
    assert_eq!(context["lineage"].as_array().unwrap().len(), 1);
    assert_eq!(
        context["cleanup_deadline_at"],
        pending["cleanup_deadline_at"]
    );
    if terminal != "deadline" {
        let delivery = deliver(&app, &child, &pending, 1, "timer").await;
        assert_eq!(delivery.0, StatusCode::OK, "{}", delivery.1);
    }
    if missing {
        database
            .execute("DELETE FROM workflow_run_waits WHERE kind='child'")
            .await;
    }
    let parent_prefix = run_history(&app, &parent["workflow_id"], &parent["run_id"]).await;
    let child_prefix = run_history(&app, &child["workflow_id"], &child["run_id"]).await;
    let child_claim_before = child_claim(&database, &child).await;
    let wait_before = wait(&database, &parent).await;
    if rollback {
        database.execute("CREATE TRIGGER reject_independent_child_parent BEFORE INSERT ON workflow_history_events WHEN NEW.event_type='ChildRunCancelled' BEGIN SELECT RAISE(ABORT, 'parent resolution rejected'); END").await;
        let rejected = finish(&app, &child, false).await;
        assert_eq!(
            rejected.0,
            StatusCode::SERVICE_UNAVAILABLE,
            "{}",
            rejected.1
        );
        assert_eq!(
            run_history(&app, &child["workflow_id"], &child["run_id"]).await,
            child_prefix
        );
        assert_eq!(
            run_history(&app, &parent["workflow_id"], &parent["run_id"]).await,
            parent_prefix
        );
        assert_eq!(child_claim(&database, &child).await, child_claim_before);
        assert_eq!(wait(&database, &parent).await, wait_before);
        assert_eq!(
            task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_failures").await,
            0
        );
        database
            .execute("DROP TRIGGER reject_independent_child_parent")
            .await;
    }
    drop(app);
    runtime.close().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    if terminal == "deadline" {
        tokio::time::sleep(Duration::from_millis(1250)).await;
    } else {
        let completed = finish(&app, &child, terminal == "failure").await;
        assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
    }
    let detail = request(
        &app,
        "GET",
        &format!("/api/workflows/{}", child["workflow_id"].as_str().unwrap()),
        Value::Null,
    )
    .await;
    assert_eq!(detail.0, StatusCode::OK, "{}", detail.1);
    assert_eq!(detail.1["status"], "cancelled");
    let history = run_history(&app, &child["workflow_id"], &child["run_id"]).await;
    let terminals: Vec<_> = history
        .as_array()
        .unwrap()
        .iter()
        .filter(|event| event["event_type"] == "WorkflowCancelled")
        .collect();
    assert_eq!(terminals.len(), 1);
    let cancelled = &terminals[0]["payload"];
    assert_eq!(cancelled["workflow_command_id"], pending["request_id"]);
    assert_eq!(
        cancelled["cancellation_cleanup"]["cleanup_deadline_at"],
        pending["cleanup_deadline_at"]
    );
    assert_eq!(
        cancelled["cancellation_cleanup"]["outcome"],
        if terminal == "deadline" {
            "deadline_expired"
        } else {
            "completed"
        }
    );
    assert!(!history.as_array().unwrap().iter().any(|event| matches!(
        event["event_type"].as_str(),
        Some("WorkflowCompleted" | "WorkflowFailed")
    )));
    let parent_history = run_history(&app, &parent["workflow_id"], &parent["run_id"]).await;
    if parent_state == "closed" {
        assert_eq!(parent_history, parent_prefix);
        assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id=(SELECT id FROM workflow_runs WHERE workflow_instance_id='abandon-parent') AND status IN ('ready','leased')").await, 0);
    } else {
        let resolutions: Vec<_> = parent_history
            .as_array()
            .unwrap()
            .iter()
            .filter(|event| event["event_type"] == "ChildRunCancelled")
            .collect();
        assert_eq!(resolutions.len(), 1, "{parent_history}");
        assert_eq!(
            resolutions[0]["payload"]["child_workflow_run_id"],
            child["run_id"]
        );
        assert_eq!(
            resolutions[0]["payload"]["failure_id"],
            cancelled["failure_id"]
        );
        assert_eq!(resolutions[0]["payload"]["failure_category"], "cancelled");
        if !root.is_null() {
            assert_eq!(child_claim(&database, &root).await, original_root);
        }
        let resume = parent_task(&database, &parent).await;
        let projected = wait(&database, &parent).await;
        if missing {
            assert!(projected.is_null());
        } else {
            assert_eq!(projected["status"], "cancelled");
            assert_eq!(projected["source_status"], "cancelled");
            assert_eq!(projected["task_id"], resume["task_id"]);
            assert_eq!(projected["task_backed"], true);
        }
        export(
            &database,
            &app,
            &parent,
            &resume,
            if parent_state == "leased" {
                "leased"
            } else {
                "ready"
            },
            &projected,
        )
        .await;
    }
    assert_eq!(
        task_retry::rows(
            &database,
            "SELECT COUNT(*) FROM workflow_child_calls WHERE status='cancelled'"
        )
        .await,
        1
    );
    let duplicate = request_child(&app, &child, 3600).await;
    assert_eq!(duplicate.0, StatusCode::OK, "{}", duplicate.1);
    assert_eq!(duplicate.1["duplicate"], true);
    for field in ["request_id", "requested_at", "cleanup_deadline_at"] {
        assert_eq!(duplicate.1["cancellation_request"][field], pending[field]);
    }
    assert_eq!(
        run_history(&app, &child["workflow_id"], &child["run_id"]).await,
        history
    );
    assert_eq!(
        run_history(&app, &parent["workflow_id"], &parent["run_id"]).await,
        parent_history
    );
    if terminal == "deadline" {
        let stale = finish(&app, &child, false).await;
        assert_eq!(stale.0, StatusCode::CONFLICT, "{}", stale.1);
    }
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn cooperative_child_independent_completion_resumes_waiting_parent() {
    independent("waiting", "complete", false, false).await;
}
#[tokio::test]
async fn cooperative_child_independent_completion_reuses_ready_parent() {
    independent("ready", "complete", false, false).await;
}
#[tokio::test]
async fn cooperative_child_independent_completion_preserves_leased_parent() {
    independent("leased", "complete", false, false).await;
}
#[tokio::test]
async fn cooperative_child_independent_deadline_resumes_waiting_parent() {
    independent("waiting", "deadline", false, false).await;
}
#[tokio::test]
async fn cooperative_child_independent_deadline_reuses_ready_parent() {
    independent("ready", "deadline", false, false).await;
}
#[tokio::test]
async fn cooperative_child_independent_deadline_preserves_leased_parent() {
    independent("leased", "deadline", false, false).await;
}
#[tokio::test]
async fn cooperative_child_independent_failure_keeps_cancellation_precedence() {
    independent("waiting", "failure", false, false).await;
}
#[tokio::test]
async fn cooperative_child_independent_missing_wait_stays_missing() {
    independent("waiting", "complete", true, false).await;
}
#[tokio::test]
async fn cooperative_child_independent_closed_parent_is_not_resumed() {
    independent("closed", "complete", false, false).await;
}
#[tokio::test]
async fn cooperative_child_independent_sqlite_parent_write_failure_rolls_back() {
    independent("waiting", "complete", false, true).await;
}

#[tokio::test]
async fn cooperative_child_independent_other_parent_policy_stays_refused() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (parent, child) = setup(&app).await;
    database
        .execute("UPDATE workflow_links SET parent_close_policy='terminate'")
        .await;
    let parent_before = run_history(&app, &parent["workflow_id"], &parent["run_id"]).await;
    let child_before = run_history(&app, &child["workflow_id"], &child["run_id"]).await;
    let rejected = request_child(&app, &child, 30).await;
    assert_eq!(
        rejected.0,
        StatusCode::UNPROCESSABLE_ENTITY,
        "{}",
        rejected.1
    );
    assert_eq!(
        rejected.1["reason"],
        "cooperative_cancellation_propagation_not_available"
    );
    assert_eq!(
        run_history(&app, &parent["workflow_id"], &parent["run_id"]).await,
        parent_before
    );
    assert_eq!(
        run_history(&app, &child["workflow_id"], &child["run_id"]).await,
        child_before
    );
    runtime.close().await;
    database.remove().await;
}
