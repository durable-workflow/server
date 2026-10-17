use super::*;

fn input(request: &str) -> Value {
    json!({"input":envelope(Payload::Array(vec![Payload::Long(9007199254740993)])),
        "request_id":request,"wait_for":"accepted"})
}

async fn accepted(app: &Router, name: &str) -> (Value, Value) {
    update_worker(app, "terminal-update-worker").await;
    let run = start(app, name).await;
    open_update_wait(app, "terminal-update-worker").await;
    let update = request(app,"POST",&format!("/api/workflows/{name}/update/set"),input("original")).await;
    assert_eq!(update.0,StatusCode::ACCEPTED,"{}",update.1);
    (run,update.1)
}

async fn closed_receipt(app: &Router, run: &Value, original: &Value, reason: &str) -> Value {
    let path=format!("/api/workflows/{}/update/set",run["workflow_id"].as_str().unwrap());
    let result=request(app,"POST",&path,input("original")).await;
    assert_eq!(result.0,StatusCode::UNPROCESSABLE_ENTITY,"{}",result.1);
    assert_eq!(result.1["update_id"],original["update_id"]);
    assert_eq!(result.1["command_id"],original["command_id"]);
    assert_eq!(result.1["run_id"],original["run_id"]);
    assert_eq!(result.1["update_status"],"failed");
    assert_eq!(result.1["outcome"],"update_failed");
    assert_eq!(result.1["failure_message"],format!("Workflow run closed as {reason} before accepted update set could be applied."));
    assert!(result.1["failure_id"].as_str().is_some_and(|id| !id.is_empty()));
    assert!(result.1["workflow_sequence"].is_null());
    assert!(result.1["applied_at"].is_null());
    assert!(result.1["closed_at"].is_string());
    assert!(result.1["result_envelope"].is_null());
    let history=run_history(app,&run["workflow_id"],&run["run_id"]).await;
    let completions:Vec<_>=history.as_array().unwrap().iter().filter(|e| e["event_type"]=="UpdateCompleted").collect();
    assert_eq!(completions.len(),1);
    let payload=&completions[0]["payload"];
    assert_eq!(payload["update_id"],original["update_id"]);
    assert_eq!(payload["workflow_command_id"],original["command_id"]);
    assert_eq!(payload["failure_id"],result.1["failure_id"]);
    assert_eq!(payload["terminal_reason"],reason);
    assert_eq!(payload["failure_category"],"application");
    assert_eq!(payload["non_retryable"],true);
    assert_eq!(payload["exception_class"],"RuntimeException");
    assert_eq!(payload["message"],result.1["failure_message"]);
    assert_eq!(payload["code"],0);
    assert_eq!(payload["exception"],json!({"class":"RuntimeException","message":result.1["failure_message"],
        "code":0,"file":"","line":0,"trace":[],"properties":[]}));
    assert!(payload.get("sequence").is_none());
    assert!(!history.as_array().unwrap().iter().any(|e|e["event_type"]=="UpdateApplied"));
    let cursor:Vec<_>=history.as_array().unwrap().iter().filter(|e|e["event_type"]=="MessageCursorAdvanced").collect();
    assert_eq!(cursor.len(),1);
    assert_eq!(cursor[0]["payload"],json!({"stream_key":format!("instance:{}",run["workflow_id"].as_str().unwrap()),"previous_position":0,"new_position":1}));
    result.1
}

#[tokio::test]
async fn terminal_updates_direct_cancel_closes_original_ready_update_after_reopen() {
    direct_cancel(false).await;
}

#[tokio::test]
async fn terminal_updates_direct_cancel_fences_original_leased_update_after_reopen() {
    direct_cancel(true).await;
}

async fn direct_cancel(leased: bool) {
    let database=TestDatabase::new().await;
    let runtime=database.open().await.unwrap();
    let app=router(runtime.clone());
    let (run,update)=accepted(&app,"terminal-update-cancel").await;
    let task=if leased {poll(&app,"terminal-update-worker","workflow").await}else{Value::Null};
    if leased {assert_eq!(task["workflow_update_id"],update["update_id"]);}
    let cancel=request(&app,"POST","/api/workflows/terminal-update-cancel/cancel",json!({"reason":"ordinary terminal update control"})).await;
    assert_eq!(cancel.0,StatusCode::OK,"{}",cancel.1);
    let receipt=closed_receipt(&app,&run,&update,"cancelled").await;
    assert_eq!(database.update_failure_count(update["command_id"].as_str().unwrap(),receipt["failure_id"].as_str().unwrap()).await,1);
    assert_eq!(task_retry::rows(&database,"SELECT COUNT(*) FROM workflow_commands WHERE command_type='update' AND status='accepted' AND outcome='update_failed' AND applied_at IS NOT NULL").await,1);
    runtime.close().await;
    let cold=database.open().await.unwrap();
    let app=router(cold.clone());
    let history=run_history(&app,&run["workflow_id"],&run["run_id"]).await;
    assert_eq!(closed_receipt(&app,&run,&update,"cancelled").await,receipt);
    if leased {
        let stale=finish_task(&app,&task,json!([{"type":"complete_update","update_id":update["update_id"],"result":envelope(Payload::Long(42))}])).await;
        assert_eq!(stale.0,StatusCode::CONFLICT,"{}",stale.1);
    }
    assert_eq!(run_history(&app,&run["workflow_id"],&run["run_id"]).await,history);
    assert!(poll(&app,"terminal-update-worker","workflow").await.is_null());
    cold.close().await;
    database.remove().await;
}
