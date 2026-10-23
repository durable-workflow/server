use super::*;

const QUEUE: &str = "/api/task-queues/test/build-ids";

async fn register_build(app: &Router, worker: &str, build: &str) -> Value {
    let (status, body) = request(app, "POST", "/api/worker/register", json!({"worker_id":worker,"task_queue":"test",
        "runtime":"php","build_id":build,"supported_workflow_types":["echo"],"supported_activity_types":[]})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    body
}

async fn pin_of(app: &Router, workflow: &str) -> Value {
    request(app, "GET", &format!("/api/workflows/{workflow}"), Value::Null).await.1["compatibility"].clone()
}

// Published PHP 2.11.1's build-id lifecycle: promotion pins new starts, a
// drained build's workers are refused polls, a queue whose workers all drain
// refuses starts, and each refusal names its blockage.
#[tokio::test]
async fn build_ids_promote_drain_resume_and_roll_back_like_php() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register_build(&app, "v1-worker", "v1").await;
    register_build(&app, "v2-worker", "v2").await;

    let (status, missing) = request(&app, "POST", &format!("{QUEUE}/promote"), json!({})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{missing}");
    assert_eq!(missing["errors"]["build_id"], json!(["The build id field must be present."]));
    let (status, unknown) = request(&app, "POST", &format!("{QUEUE}/promote"), json!({"build_id":"v3"})).await;
    assert_eq!(status, StatusCode::CONFLICT, "{unknown}");
    assert_eq!(unknown["blockages"], json!([{"reason":"unknown_deployment","message":"No deployment is registered for default/test@v3.",
        "scope":{"namespace":"default","task_queue":"test","build_id":"v3"},
        "expected_resolution":"Roll a worker that heartbeats this build id, then retry promotion."}]));
    assert_eq!((unknown["outcome"].clone(), unknown["deployment"].clone()), (json!("rejected_unknown_deployment"), Value::Null));

    // Promotion pins new starts to the build, though two builds are live.
    let (status, promoted) = request(&app, "POST", &format!("{QUEUE}/promote"), json!({"build_id":" v1 "})).await;
    assert_eq!(status, StatusCode::OK, "{promoted}");
    assert_eq!((promoted["build_id"].clone(), promoted["new_start_selected"].clone(), promoted["deployment"]["state"].clone()),
        (json!("v1"), json!(true), json!("promoted")), "{promoted}");
    assert!(promoted["promoted_at"].as_str().unwrap().ends_with('Z'), "{promoted}");
    start(&app, "first").await;
    assert_eq!(pin_of(&app, "first").await, "v1");
    let (_, listed) = request(&app, "GET", "/api/deployments", Value::Null).await;
    let states: Vec<_> = listed["deployments"].as_array().unwrap().iter()
        .map(|deployment| (deployment["name"].clone(), deployment["state"].clone(), deployment["new_start_selected"].clone())).collect();
    assert_eq!(states, vec![(json!("default/test@v1"), json!("promoted"), json!(true)), (json!("default/test@v2"), json!("active"), json!(false))]);
    let (status, shown) = request(&app, "GET", "/api/deployments/default/test@v1", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{shown}");
    assert!(shown["promoted_at"].as_str().unwrap().ends_with("+00:00"), "{shown}");
    assert_eq!(request(&app, "GET", "/api/deployments/default/test@v9", Value::Null).await,
        (StatusCode::NOT_FOUND, json!({"error":"deployment_not_found"})));
    assert_eq!(request(&app, "GET", "/api/deployments/nonsense", Value::Null).await.0, StatusCode::BAD_REQUEST);

    // A draining build's workers claim nothing, and stay draining when they
    // register again; new starts go to the build still active.
    let (status, drained) = request(&app, "POST", &format!("{QUEUE}/drain"), json!({"build_id":"v1"})).await;
    assert_eq!((status, drained["drain_intent"].clone(), drained["new_start_selected"].clone()), (StatusCode::OK, json!("draining"), json!(false)));
    let (status, refused) = request(&app, "POST", "/api/worker/workflow-tasks/poll", json!({"worker_id":"v1-worker","task_queue":"test","timeout_seconds":0})).await;
    assert_eq!((status, refused["reason"].clone(), refused["poll_status"].clone()), (StatusCode::CONFLICT, json!("worker_draining"), json!("draining")), "{refused}");
    assert_eq!(register_build(&app, "v1-worker", "v1").await["status"], "draining");
    start(&app, "second").await;
    assert_eq!(pin_of(&app, "second").await, "v2");

    // With every live worker draining, the queue refuses starts.
    request(&app, "POST", &format!("{QUEUE}/drain"), json!({"build_id":"v2"})).await;
    let (status, closed) = request(&app, "POST", "/api/workflows", json!({"workflow_id":"third","workflow_type":"echo","task_queue":"test",
        "input":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(status, StatusCode::CONFLICT, "{closed}");
    for (field, expected) in [("reason", json!("task_queue_draining")), ("outcome", json!("rejected_task_queue_draining")),
        ("active_worker_count", json!(0)), ("draining_worker_count", json!(2)), ("draining_build_ids", json!(["v1", "v2"]))] {
        assert_eq!(closed[field], expected, "{field}: {closed}");
    }

    // Resuming v1 lets its worker claim its pinned run again.
    let (_, resumed) = request(&app, "POST", &format!("{QUEUE}/resume"), json!({"build_id":"v1"})).await;
    assert_eq!((resumed["drain_intent"].clone(), resumed["new_start_selected"].clone()), (json!("active"), json!(true)), "{resumed}");
    assert_eq!(poll(&app, "v1-worker", "workflow").await["workflow_id"], "first");

    // A draining deployment cannot be promoted until it resumes; a rollback
    // returns new starts to the earlier promotion.
    let (status, blocked) = request(&app, "POST", "/api/deployments/default/test@v2/promote", Value::Null).await;
    assert_eq!(status, StatusCode::CONFLICT, "{blocked}");
    assert_eq!(blocked["blockages"].as_array().unwrap().len(), 1, "{blocked}");
    assert_eq!(blocked["reason"], "fleet_is_draining");
    assert_eq!(request(&app, "POST", "/api/deployments/default/test@v2/resume", Value::Null).await.1["state"], "active");
    let (status, promoted) = request(&app, "POST", "/api/deployments/default/test@v2/promote", Value::Null).await;
    assert_eq!((status, promoted["state"].clone(), promoted["new_start_selected"].clone()), (StatusCode::OK, json!("promoted"), json!(true)), "{promoted}");
    start(&app, "fourth").await;
    assert_eq!(pin_of(&app, "fourth").await, "v2");
    let (_, rolled) = request(&app, "POST", "/api/deployments/default/test@v2/rollback", Value::Null).await;
    assert_eq!((rolled["state"].clone(), rolled["accepts_new_work"].clone(), rolled["promoted_at"].clone()),
        (json!("rolled_back"), json!(false), Value::Null), "{rolled}");
    let (status, again) = request(&app, "POST", "/api/deployments/default/test@v2/rollback", Value::Null).await;
    assert_eq!((status, again["reason"].clone()), (StatusCode::CONFLICT, json!("incompatible_policy")), "{again}");
    start(&app, "fifth").await;
    assert_eq!(pin_of(&app, "fifth").await, "v1");
    let (_, builds) = request(&app, "GET", QUEUE, Value::Null).await;
    let selected: Vec<_> = builds["build_ids"].as_array().unwrap().iter()
        .map(|entry| (entry["build_id"].clone(), entry["new_start_selected"].clone(), entry["rolled_back_at"].is_string())).collect();
    assert_eq!(selected, vec![(json!("v1"), json!(true), false), (json!("v2"), json!(false), true)], "{builds}");
    runtime.close().await;
    database.remove().await;
}
