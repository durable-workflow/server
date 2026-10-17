use super::*;

fn entry(base: i64, size: usize, index: usize, kind: &str) -> Value {
    let prefix = match kind {
        "activity" => "parallel-activities",
        "timer" => "parallel-timers",
        _ => "parallel-calls",
    };
    json!({"parallel_group_id":format!("{prefix}:{base}:{size}"),"parallel_group_kind":kind,
        "parallel_group_base_sequence":base,"parallel_group_size":size,"parallel_group_index":index})
}

fn with_path(mut command: Value, path: Vec<Value>) -> Value {
    command.as_object_mut().unwrap().extend(path.last().unwrap().as_object().unwrap().clone());
    command["parallel_group_path"] = json!(path);
    command
}

fn activity(index: i64) -> Value {
    json!({"type":"schedule_activity","activity_type":"echo_activity",
        "arguments":envelope(Payload::Array(vec![Payload::Long(9007199254740993 + index)]))})
}

fn all_activities(size: usize) -> Value {
    json!((0..size).map(|index| with_path(activity(index as i64),
        vec![entry(1,size,index,"activity")])).collect::<Vec<_>>())
}

async fn observe(database: &TestDatabase, app: &Router, run: &Value) -> Value {
    let summary = run_observation_active_summaries::observe(app, "default", run).await;
    assert_eq!(summary["summary_state"], "observed", "{summary}");
    run_observation_summaries::export_reference_for(database,&summary,&summary["history"],"DW_TEST_PARALLEL_ALL_REFERENCE").await;
    summary
}

async fn complete_activity(app: &Router, task: &Value, value: i64) -> (StatusCode,Value) {
    request(app,"POST",&format!("/api/worker/activity-tasks/{}/complete",task["task_id"].as_str().unwrap()),
        json!({"lease_owner":task["lease_owner"],"activity_attempt_id":task["activity_attempt_id"],
            "result":envelope(Payload::Long(value))})).await
}

async fn wait_for_timer(app: &Router, run: &Value) -> Value {
    let deadline=tokio::time::Instant::now()+std::time::Duration::from_secs(3);
    loop {
        let history=run_history(app,&run["workflow_id"],&run["run_id"]).await;
        if history.as_array().unwrap().iter().any(|event| event["event_type"]=="TimerFired") {return history;}
        assert!(tokio::time::Instant::now()<deadline,"original all-group timer did not fire");
        tokio::time::sleep(std::time::Duration::from_millis(20)).await;
    }
}

fn no_selection(history: &Value) {
    assert!(!history.as_array().unwrap().iter().any(|event| event["event_type"]=="SelectionResolved"));
}

#[tokio::test]
async fn activity_barrier_keeps_out_of_order_results_and_later_wakeup_across_cold_nodes() {
    let database=TestDatabase::new().await;
    let first=database.open().await.unwrap();
    let app=router(first.clone());
    register(&app,"all-worker",json!(["echo"]),json!(["echo_activity"])).await;
    let run=start(&app,"all-activities").await;
    let initial=poll(&app,"all-worker","workflow").await;
    let commands=all_activities(3);
    let admitted=finish_task(&app,&initial,commands.clone()).await;
    assert_eq!(admitted.0,StatusCode::OK,"{}",admitted.1);
    assert_eq!(finish_task(&app,&initial,commands).await.1["recorded"],false);
    let original=run_history(&app,&run["workflow_id"],&run["run_id"]).await;
    assert_eq!(original.as_array().unwrap().len(),5);
    assert_eq!(observe(&database,&app,&run).await["current_waits"]["returned_count"],3);
    drop(app);
    first.close().await;

    let (one,two)=tokio::join!(database.open(),database.open());
    let (one,two)=(one.unwrap(),two.unwrap());
    let (app_a,app_b)=(router(one.clone()),router(two.clone()));
    let mut tasks=Vec::new();
    for _ in 0..3 {tasks.push(poll(&app_a,"all-worker","activity").await);}
    tasks.sort_by_key(|task|task["sequence"].as_i64().unwrap());
    for (index,task) in tasks.iter().enumerate() {
        assert_eq!(task["activity_execution_id"],original[index+2]["payload"]["activity_execution_id"]);
        assert_eq!(task["arguments"],activity(index as i64)["arguments"]);
    }
    let done=complete_activity(&app_b,&tasks[2],9007199254740995).await;
    assert_eq!(done.0,StatusCode::OK,"{}",done.1);
    let leased=poll(&app_a,"all-worker","workflow").await;
    assert_eq!(leased["task_id"],done.1["next_task_id"]);
    assert_eq!(finish_task(&app_a,&leased,json!([{"type":"complete_workflow","result":envelope(Payload::Null)}])).await.0,StatusCode::CONFLICT);
    observe(&database,&app_b,&run).await;
    let later=complete_activity(&app_b,&tasks[0],9007199254740993).await;
    assert_eq!(later.0,StatusCode::OK,"{}",later.1);
    assert_ne!(later.1["next_task_id"],leased["task_id"]);
    let waiting=request(&app_a,"POST",&format!("/api/worker/workflow-tasks/{}/fail",leased["task_id"].as_str().unwrap()),
        json!({"lease_owner":leased["lease_owner"],"workflow_task_attempt":leased["workflow_task_attempt"],
        "failure":{"type":"WorkflowTaskWaitingForHistory","message":"all barrier still has one original member"}})).await;
    assert_eq!(waiting.0,StatusCode::OK,"{}",waiting.1);
    let last=complete_activity(&app_b,&tasks[1],9007199254740994).await;
    assert_eq!(last.0,StatusCode::OK,"{}",last.1);
    assert_eq!(last.1["next_task_id"],later.1["next_task_id"]);
    let before_duplicate=run_history(&app_a,&run["workflow_id"],&run["run_id"]).await;
    assert_eq!(complete_activity(&app_a,&tasks[1],9007199254740994).await.1["recorded"],false);
    assert_eq!(run_history(&app_a,&run["workflow_id"],&run["run_id"]).await,before_duplicate);
    let resumed=poll(&app_a,"all-worker","workflow").await;
    assert_eq!(resumed["task_id"],later.1["next_task_id"]);
    let result=envelope(Payload::Array(vec![Payload::Long(9007199254740993),Payload::Long(9007199254740994),Payload::Long(9007199254740995)]));
    assert_eq!(finish_task(&app_b,&resumed,json!([{"type":"complete_workflow","result":result}])).await.0,StatusCode::OK);
    let history=run_history(&app_a,&run["workflow_id"],&run["run_id"]).await;
    assert_eq!(&history.as_array().unwrap()[..5],original.as_array().unwrap());
    no_selection(&history);
    assert_eq!(history.as_array().unwrap().last().unwrap()["payload"]["result"],result);
    observe(&database,&app_a,&run).await;
    assert!(poll(&app_b,"all-worker","workflow").await.is_null());
    one.close().await;
    two.close().await;
    database.remove().await;
}

#[tokio::test]
async fn nested_mixed_barrier_retains_all_paths_and_original_timer_after_cold_reopen() {
    let database=TestDatabase::new().await;
    let first=database.open().await.unwrap();
    let app=router(first.clone());
    register(&app,"nested-worker",json!(["echo"]),json!(["echo_activity"])).await;
    let run=start(&app,"nested-all").await;
    let initial=poll(&app,"nested-worker","workflow").await;
    let commands=json!([
        with_path(activity(0),vec![entry(1,4,0,"mixed"),entry(1,2,0,"activity")]),
        with_path(activity(1),vec![entry(1,4,1,"mixed"),entry(1,2,1,"activity")]),
        with_path(json!({"type":"start_timer","delay_seconds":1}),vec![entry(1,4,2,"mixed"),entry(3,2,0,"mixed")]),
        with_path(activity(2),vec![entry(1,4,3,"mixed"),entry(3,2,1,"mixed")])
    ]);
    let admitted=finish_task(&app,&initial,commands.clone()).await;
    assert_eq!(admitted.0,StatusCode::OK,"{}",admitted.1);
    let prefix=run_history(&app,&run["workflow_id"],&run["run_id"]).await;
    for index in 0..4 {
        assert_eq!(prefix[index+2]["payload"]["parallel_group_path"],commands[index]["parallel_group_path"]);
    }
    assert_eq!(observe(&database,&app,&run).await["current_waits"]["returned_count"],4);
    drop(app);
    first.close().await;
    let second=database.open().await.unwrap();
    let app=router(second.clone());
    let fired=wait_for_timer(&app,&run).await;
    assert_eq!(&fired.as_array().unwrap()[..6],prefix.as_array().unwrap());
    let timer=fired.as_array().unwrap().iter().find(|event|event["event_type"]=="TimerFired").unwrap();
    assert_eq!(timer["payload"]["parallel_group_path"],commands[2]["parallel_group_path"]);
    assert_eq!(timer["payload"]["timer_id"],prefix[4]["payload"]["timer_id"]);
    assert_eq!(observe(&database,&app,&run).await["current_waits"]["returned_count"],3);
    for _ in 0..3 {
        let activity=poll(&app,"nested-worker","activity").await;
        assert_eq!(complete_activity(&app,&activity,42).await.0,StatusCode::OK);
    }
    let resumed=poll(&app,"nested-worker","workflow").await;
    assert_eq!(finish_task(&app,&resumed,json!([{"type":"complete_workflow","result":envelope(Payload::Long(42))}])).await.0,StatusCode::OK);
    let history=run_history(&app,&run["workflow_id"],&run["run_id"]).await;
    no_selection(&history);
    observe(&database,&app,&run).await;
    second.close().await;
    database.remove().await;
}
