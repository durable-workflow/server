use super::*;

fn condition(seconds: Value, occurrence: &str) -> Value {
    json!({"type":"open_condition_wait","condition_key":"ready",
        "condition_definition_fingerprint":format!("sha256:{}","a".repeat(64)),
        "condition_wait_occurrence_id":occurrence,"timeout_seconds":seconds})
}

async fn author(app: &Router, namespace: &str, worker: &str) {
    let response=namespaces::scoped(app,namespace,"POST","/api/worker/register",json!({
        "worker_id":worker,"task_queue":"test","runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":[],
        "workflow_command_contracts":{"echo":{"signals":["payload"],"signal_contracts":[{"name":"payload","parameters":[{
            "name":"value","position":0,"type":"int","required":true,"variadic":false,"default_available":false,"allows_null":false}]}]}}
    })).await;
    assert_eq!(response.0,StatusCode::CREATED,"{}",response.1);
}

async fn claim(app: &Router, namespace: &str, worker: &str, timeout: i64) -> Value {
    let response=namespaces::scoped(app,namespace,"POST","/api/worker/workflow-tasks/poll",json!({"worker_id":worker,"task_queue":"test","timeout_seconds":timeout})).await;
    assert_eq!(response.0,StatusCode::OK,"{}",response.1);
    response.1["task"].clone()
}

async fn finish(app: &Router, namespace: &str, task: &Value, commands: Value) -> (StatusCode,Value) {
    namespaces::scoped(app,namespace,"POST",&format!("/api/worker/workflow-tasks/{}/complete",task["task_id"].as_str().unwrap()),completion(task,commands)).await
}

async fn read(app: &Router, namespace: &str, run: &Value, suffix: &str) -> Value {
    let response=namespaces::scoped(app,namespace,"GET",&format!("/api/workflows/{}/runs/{}/{suffix}",run["workflow_id"].as_str().unwrap(),run["run_id"].as_str().unwrap()),Value::Null).await;
    assert_eq!(response.0,StatusCode::OK,"{}",response.1);response.1
}

async fn expiry(seconds: i64) {
    for namespace in ["default","timed-conditions"] {
        let database=TestDatabase::new().await;let first=database.open().await.unwrap();let app=router(first.clone());
        if namespace != "default" { namespaces::create(&app,namespace).await; }
        author(&app,namespace,"condition-author").await;
        let run=namespaces::start_scoped(&app,namespace,&format!("condition-{namespace}-{seconds}")).await;
        let original=claim(&app,namespace,"condition-author",0).await;
        let commands=json!([condition(json!(seconds),"condition:logical:0")]);
        let result=finish(&app,namespace,&original,commands.clone()).await;
        assert_eq!(result.0,StatusCode::OK,"{}",result.1);
        let prefix=read(&app,namespace,&run,"history").await;
        assert_eq!(finish(&app,namespace,&original,commands).await.1["recorded"],false);
        assert_eq!(read(&app,namespace,&run,"history").await,prefix);
        drop(app);first.close().await;
        let cold=database.open().await.unwrap();let app=router(cold.clone());author(&app,namespace,"condition-cold").await;
        let resumed=claim(&app,namespace,"condition-cold",3).await;
        assert!(!resumed.is_null(),"original condition timer did not resume within three seconds");
        let history=read(&app,namespace,&run,"history").await;let events=history["events"].as_array().unwrap();
        assert_eq!(events.iter().map(|e| e["event_type"].as_str().unwrap()).collect::<Vec<_>>(),["StartAccepted","WorkflowStarted","ConditionWaitOpened","TimerScheduled","TimerFired"]);
        let opened=&events[2]["payload"];let scheduled=&events[3]["payload"];let fired=&events[4]["payload"];
        for value in [scheduled,fired] {
            for key in ["condition_wait_id","condition_wait_occurrence_id","condition_key","condition_definition_fingerprint","sequence"] {assert_eq!(value[key],opened[key],"{key}");}
            assert_eq!(value["timer_kind"],"condition_timeout");assert_eq!(value["delay_seconds"],seconds);
        }
        assert_eq!(fired["timer_id"],scheduled["timer_id"]);
        assert!(chrono::DateTime::parse_from_rfc3339(fired["fired_at"].as_str().unwrap()).unwrap() >= chrono::DateTime::parse_from_rfc3339(scheduled["fire_at"].as_str().unwrap()).unwrap());
        assert_eq!(resumed["workflow_wait_kind"],"condition");assert_eq!(resumed["open_wait_id"],opened["condition_wait_id"]);
        assert_eq!(resumed["resume_source_kind"],"timer");assert_eq!(resumed["resume_source_id"],scheduled["timer_id"]);
        for key in ["condition_wait_id","condition_wait_occurrence_id","condition_key","condition_definition_fingerprint"] {assert_eq!(resumed[key],opened[key],"{key}");}
        let observed=read(&app,namespace,&run,"observation").await;
        assert_eq!(observed["summary_state"],"observed","{observed}");assert_eq!(observed["liveness_state"],"workflow_task_leased");
        assert_eq!(observed["liveness_reason"],format!("Condition timeout task {} is leased to a worker.",resumed["task_id"].as_str().unwrap()));
        assert_eq!(observed["current_waits"]["returned_count"],1);
        let wait=&observed["current_waits"]["waits"][0];assert_eq!(wait["kind"],"condition");assert_eq!(wait["id"],opened["condition_wait_id"]);
        assert_eq!(wait["reason"],"Waiting to apply condition timeout.");assert_eq!(wait["state"],"deadline_elapsed");
        run_observation_summaries::export_reference_for(&database,&observed,&history,"DW_TEST_TIMED_CONDITION_REFERENCE").await;
        let done=finish(&app,namespace,&resumed,json!([{"type":"complete_workflow","result":envelope(Payload::Boolean(false))}])).await;
        assert_eq!(done.0,StatusCode::OK,"{}",done.1);assert_eq!(done.1["run_status"],"completed");
        let after=read(&app,namespace,&run,"history").await;
        assert_eq!(after["events"].as_array().unwrap().len(),6);
        assert_eq!(after["events"][5]["event_type"],"WorkflowCompleted");
        assert_eq!(read(&app,namespace,&run,"observation").await["current_waits"]["returned_count"],0);
        cold.close().await;database.remove().await;
    }
}

#[tokio::test]
async fn immediate_condition_timeout_keeps_open_wait_until_worker_completion() { expiry(0).await; }

#[tokio::test]
async fn positive_condition_timeout_keeps_original_metadata_across_reopen() { expiry(1).await; }
