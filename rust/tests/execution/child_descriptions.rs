use super::*;

// Published Server 2.11.5 describes a parent by its open waits however many
// children it holds: the first open child, or an open timer ahead of open
// children, under any parent-close policy. The child-descriptions fixture
// records the same cases.

fn child(policy: Option<&str>) -> Value {
    let mut command = json!({"type":"start_child_workflow","workflow_type":"echo","arguments":envelope(Payload::Array(vec![]))});
    if let Some(policy) = policy {
        command["parent_close_policy"] = json!(policy);
    }
    command
}

#[tokio::test]
async fn a_parent_is_described_by_its_waits_with_several_children() {
    let timer = json!({"type":"start_timer","delay_seconds":3600});
    let child_wait = (json!("child"), json!("Waiting for child workflow echo"));
    let timer_wait = (json!("timer"), json!("Waiting for timer"));
    for (id, commands, expected) in [
        ("one-child", json!([child(None)]), child_wait.clone()),
        ("two-children", json!([child(None), child(None)]), child_wait.clone()),
        ("child-then-timer", json!([child(None), timer.clone()]), timer_wait.clone()),
        ("timer-then-child", json!([timer.clone(), child(None)]), timer_wait.clone()),
        ("terminating-child", json!([child(Some("terminate"))]), child_wait.clone()),
    ] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap();
        let app = router(runtime.clone());
        register(&app, "worker", json!(["echo"]), json!([])).await;
        start(&app, id).await;
        let task = poll(&app, "worker", "workflow").await;
        let (status, body) = finish_task(&app, &task, commands).await;
        assert_eq!(status, StatusCode::OK, "{id}: {body}");
        let (_, described) = request(&app, "GET", &format!("/api/workflows/{id}"), Value::Null).await;
        assert_eq!(described["status"], "waiting", "{id}: {described}");
        assert_eq!((described["wait_kind"].clone(), described["wait_reason"].clone()), expected, "{id}: {described}");
        runtime.close().await;
        database.remove().await;
    }
}
