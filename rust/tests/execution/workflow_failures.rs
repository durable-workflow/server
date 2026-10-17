use super::*;

fn command() -> Value {
    json!({"type":"fail_workflow","message":" outer failure λ ",
        "exception_class":"OuterException","exception_type":"outer-v1",
        "exception":{"class":"DomainException","type":"domain-v1","message":" inner failure λ ",
            "code":9007199254740993_i64,"file":"workflow.php","line":17,"trace":[],
            "properties":[{"declaring_class":"DomainException","name":"original","value":9007199254740993_i64}]},
        "non_retryable":true})
}

async fn history(app: &Router, run: &Value) -> Value {
    run_history(app, &run["workflow_id"], &run["run_id"]).await
}

#[tokio::test]
async fn workflow_failure_is_terminal_atomic_and_retains_one_receipt_after_cold_reopen() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app,"failure-worker",json!(["echo"]),json!([])).await;
    let run = start(&app,"workflow-failure-cold").await;
    let task = poll(&app,"failure-worker","workflow").await;
    let commands = json!([command()]);
    let accepted = finish_task(&app,&task,commands.clone()).await;
    assert_eq!(accepted.0,StatusCode::OK,"{}",accepted.1);
    assert_eq!(accepted.1["run_status"],"failed");
    let original = history(&app,&run).await;
    let terminal = original.as_array().unwrap().last().unwrap();
    assert_eq!(terminal["event_type"],"WorkflowFailed");
    assert_eq!(terminal["workflow_task_id"],task["task_id"]);
    let payload = &terminal["payload"];
    assert_eq!(payload["source_kind"],"workflow_run");
    assert_eq!(payload["source_id"],run["run_id"]);
    assert_eq!(payload["message"],"inner failure λ");
    assert_eq!(payload["exception_class"],"DomainException");
    assert_eq!(payload["exception_type"],"domain-v1");
    assert_eq!(payload["non_retryable"],true);
    assert_eq!(payload["exception"]["message"]," inner failure λ ");
    assert_eq!(payload["exception"]["code"],9007199254740993_i64);
    assert_eq!(payload["exception"]["properties"],command()["exception"]["properties"]);
    assert_eq!(task_retry::rows(&database,"SELECT COUNT(*) FROM workflow_failures WHERE source_kind='workflow_run' AND propagation_kind='terminal' AND failure_category='application' AND non_retryable=TRUE AND handled=FALSE AND file='' AND line=0 AND trace_preview=''").await,1);
    assert_eq!(task_retry::rows(&database,"SELECT COUNT(*) FROM workflow_tasks WHERE task_type='workflow' AND status='failed' AND lease_expires_at IS NULL").await,1);
    runtime.close().await;
    let cold = database.open().await.unwrap();
    let cold_app = router(cold.clone());
    let duplicate = finish_task(&cold_app,&task,commands).await;
    assert_eq!(duplicate.0,StatusCode::OK,"{}",duplicate.1);
    assert_eq!(duplicate.1["recorded"],false);
    let changed = finish_task(&cold_app,&task,json!([{"type":"fail_workflow","message":"different"}])).await;
    assert_eq!(changed.0,StatusCode::CONFLICT);
    assert_eq!(changed.1["reason"],"conflicting_completion");
    assert_eq!(history(&cold_app,&run).await,original);
    assert!(poll(&cold_app,"failure-worker","workflow").await.is_null());
    cold.close().await;
    database.remove().await;
}

#[tokio::test]
async fn workflow_failure_resolves_original_child_failure_and_one_parent_wakeup() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app,"failure-parent",json!(["echo"]),json!([])).await;
    register(&app,"failure-child",json!(["child"]),json!([])).await;
    let parent = start(&app,"failed-child-parent").await;
    let initial = poll(&app,"failure-parent","workflow").await;
    assert_eq!(finish_task(&app,&initial,json!([child_command(9007199254740993_i64)])).await.0,StatusCode::OK);
    let child = poll(&app,"failure-child","workflow").await;
    let accepted = finish_task(&app,&child,json!([command()])).await;
    assert_eq!(accepted.0,StatusCode::OK,"{}",accepted.1);
    let child_history = run_history(&app,&child["workflow_id"],&child["run_id"]).await;
    let failure = &child_history.as_array().unwrap().last().unwrap()["payload"];
    let parent_history = history(&app,&parent).await;
    let resolved = parent_history.as_array().unwrap().last().unwrap();
    assert_eq!(resolved["event_type"],"ChildRunFailed");
    assert_eq!(resolved["payload"]["failure_id"],failure["failure_id"]);
    assert_eq!(resolved["payload"]["child_workflow_run_id"],child["run_id"]);
    assert_eq!(resolved["payload"]["message"],"inner failure λ");
    let resumed = poll(&app,"failure-parent","workflow").await;
    assert_eq!(resumed["run_id"],parent["run_id"]);
    assert_eq!(resumed["child_workflow_run_id"],child["run_id"]);
    assert!(poll(&app,"failure-parent","workflow").await.is_null());
    assert_eq!(finish_task(&app,&resumed,json!([{"type":"complete_workflow","result":envelope(Payload::Long(9007199254740993))}])).await.0,StatusCode::OK);
    runtime.close().await;
    database.remove().await;
}
