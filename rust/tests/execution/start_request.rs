use super::*;

fn decoded(envelope: &Value) -> Payload {
    ValueCodec::new()
        .unwrap()
        .decode(envelope["blob"].as_str().unwrap())
        .unwrap()
}

async fn events(app: &Router, workflow: &Value) -> Vec<Value> {
    run_history(app, &workflow["workflow_id"], &workflow["run_id"])
        .await
        .as_array()
        .unwrap()
        .clone()
}

// Published PHP names the instance, picks the default queue and starts with
// no arguments when the request leaves them out; plain JSON input is its
// values, encoded by the server.
#[tokio::test]
async fn start_takes_php_defaults_and_plain_input() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let (status, bare) = request(&app, "POST", "/api/workflows", json!({"workflow_type":"echo"})).await;
    assert_eq!(status, StatusCode::CREATED, "{bare}");
    assert!(!bare["workflow_id"].as_str().unwrap().is_empty());
    assert_eq!(bare["business_key"], Value::Null);
    let (status, described) =
        request(&app, "GET", &format!("/api/workflows/{}", bare["workflow_id"].as_str().unwrap()), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{described}");
    assert_eq!(described["task_queue"], "default");
    assert_eq!(decoded(&described["input_envelope"]), Payload::Array(vec![]));
    let (status, plain) = request(&app, "POST", "/api/workflows",
        json!({"workflow_id":"plain","workflow_type":"echo","task_queue":" test ","input":[1,"a",{"k":true}],
            "business_key":" order-1 ","unknown_field":1})).await;
    assert_eq!(status, StatusCode::CREATED, "{plain}");
    assert_eq!(plain["business_key"], "order-1");
    let (_, described) = request(&app, "GET", "/api/workflows/plain", Value::Null).await;
    assert_eq!(described["task_queue"], "test");
    assert_eq!(
        decoded(&described["input_envelope"]),
        Payload::Array(vec![
            Payload::Long(1),
            Payload::String("a".into()),
            Payload::Map([("k".to_owned(), Payload::Boolean(true))].into()),
        ])
    );
    // StartAccepted keeps PHP's null visibility fields; WorkflowStarted drops
    // null fields.
    let history = events(&app, &plain).await;
    let accepted = &history[0]["payload"];
    assert_eq!(accepted["business_key"], "order-1");
    assert!(accepted.get("visibility_labels").is_some_and(Value::is_null));
    assert!(accepted.get("search_attributes").is_some_and(Value::is_null));
    let started = &history[1]["payload"];
    assert_eq!(started["business_key"], "order-1");
    for field in ["memo", "execution_timeout_seconds", "run_deadline_at"] {
        assert!(started.get(field).is_none(), "{field} in {started}");
    }
    let (_, listed) = request(&app, "GET", "/api/workflows?query=order-1", Value::Null).await;
    assert_eq!(listed["workflows"][0]["business_key"], "order-1", "{listed}");
}

// Laravel's validation runs before anything else, with PHP's messages.
#[tokio::test]
async fn start_validation_reports_php_messages() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let (status, body) = request(&app, "POST", "/api/workflows",
        json!({"workflow_id":"bad id!","memo":"m","priority":10,"request_id":"x"})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    assert_eq!(body["message"], "The workflow id field format is invalid. (and 4 more errors)");
    assert_eq!(body["errors"], json!({
        "workflow_id":["The workflow id field format is invalid."],
        "workflow_type":["The workflow type field is required."],
        "memo":["The memo field must be an array."],
        "priority":["The priority field must be an integer in the range 0..9 (lower runs first)."],
        "request_id":["The request_id field is not supported by the v2 workflow start API."]}));
    assert_eq!(body["control_plane"]["operation"], "start");
    let (status, body) = request(&app, "POST", "/api/workflows",
        json!({"workflow_id":"json","workflow_type":"echo","payload_codec":"json"})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    assert!(body["errors"]["payload_codec"][0].as_str().unwrap().starts_with("unsupported_payload_codec: workflow payload codec \"json\""));
    let (status, _) = request(&app, "GET", "/api/workflows/json", Value::Null).await;
    assert_eq!(status, StatusCode::NOT_FOUND);
}

// A run carries its priority and fairness tags to every task it creates, and
// workers claim lower priority numbers first.
#[tokio::test]
async fn priority_orders_claims_and_tags_follow_the_run() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "worker", json!(["echo"]), json!(["act"])).await;
    for (workflow, priority) in [("later", 7), ("default", 5), ("sooner", 1)] {
        let mut body = json!({"workflow_id":workflow,"workflow_type":"echo","task_queue":"test",
            "fairness_key":"Tenant-A","fairness_weight":3});
        if workflow != "default" {
            body["priority"] = json!(priority);
        }
        let (status, started) = request(&app, "POST", "/api/workflows", body).await;
        assert_eq!(status, StatusCode::CREATED, "{started}");
    }
    let mut claimed = Vec::new();
    for _ in 0..3 {
        let task = poll(&app, "worker", "workflow").await;
        claimed.push(task["workflow_id"].as_str().unwrap().to_owned());
        if claimed.len() == 1 {
            let schedule = json!({"type":"schedule_activity","activity_type":"act","arguments":envelope(Payload::Array(vec![]))});
            let (status, body) = finish_task(&app, &task, json!([schedule])).await;
            assert_eq!(status, StatusCode::OK, "{body}");
        }
    }
    assert_eq!(claimed, ["sooner", "default", "later"]);
    let tagged = super::task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_tasks t JOIN workflow_runs r ON r.id=t.workflow_run_id WHERE r.workflow_instance_id='sooner' AND t.priority=1 AND t.fairness_key='tenant-a' AND t.fairness_weight=3").await;
    assert_eq!(tagged, 2, "the workflow task and the activity task carry the run's tags");
}

// A start for an instance that already has a run is recorded on that run:
// `use-existing` returns an active run, anything else is refused with 409.
#[tokio::test]
async fn repeated_starts_are_recorded_on_the_existing_run() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let first = json!({"workflow_id":"again","workflow_type":"echo","business_key":"bk","memo":{"owner":"ops"}});
    let (status, started) = request(&app, "POST", "/api/workflows", first.clone()).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    let mut reuse = first.clone();
    reuse["duplicate_policy"] = json!("use-existing");
    let (status, returned) = request(&app, "POST", "/api/workflows", reuse).await;
    assert_eq!(status, StatusCode::OK, "{returned}");
    assert_eq!(returned["outcome"], "returned_existing_active");
    assert_eq!(returned["run_id"], started["run_id"]);
    assert_eq!(returned["business_key"], "bk");
    let (status, refused) = request(&app, "POST", "/api/workflows", first).await;
    assert_eq!(status, StatusCode::CONFLICT, "{refused}");
    assert_eq!(refused["outcome"], "rejected_duplicate");
    assert_eq!(refused["reason"], "instance_already_started");
    assert_eq!(refused["command_status"], "rejected");
    assert_eq!(refused["run_id"], started["run_id"]);
    assert_eq!(refused["control_plane"]["operation"], "start");
    let history = events(&app, &started).await;
    let kinds: Vec<_> = history.iter().map(|event| event["event_type"].as_str().unwrap()).collect();
    assert_eq!(kinds, ["StartAccepted", "WorkflowStarted", "StartAccepted", "StartRejected"]);
    let rejected = &history[3]["payload"];
    assert_eq!(rejected["outcome"], "rejected_duplicate");
    assert_eq!(rejected["rejection_reason"], "instance_already_started");
    assert_eq!(rejected["business_key"], "bk");
    assert_eq!(rejected["memo"], json!({"owner":"ops"}));
    assert_eq!(rejected["search_attributes"], json!([]));
    assert_eq!(history[2]["payload"]["outcome"], "returned_existing_active");
    let (_, described) = request(&app, "GET", "/api/workflows/again", Value::Null).await;
    let commands: Vec<_> = described["commands"].as_array().unwrap().iter().map(|c| (c["sequence"].clone(), c["status"].clone(), c["outcome"].clone())).collect();
    assert_eq!(commands, [(json!(1), json!("accepted"), json!("started_new")), (json!(2), json!("accepted"), json!("returned_existing_active")),
        (json!(3), json!("rejected"), json!("rejected_duplicate"))]);
}
