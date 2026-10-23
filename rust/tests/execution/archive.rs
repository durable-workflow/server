use super::*;

fn kinds(history: &Value) -> Vec<String> {
    history.as_array().unwrap().iter().map(|event| event["event_type"].as_str().unwrap().to_owned()).collect()
}

// Published PHP 2.11.1's archive: a closed run records an archive command,
// ArchiveRequested and, the first time, WorkflowArchived. An open run, a
// historical run, an unknown workflow and invalid fields are refused.
#[tokio::test]
async fn archive_records_php_s_command_and_events() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "worker", json!(["echo"]), json!([])).await;
    let started = start(&app, "arc").await;
    let (status, open) = request(&app, "POST", "/api/workflows/arc/archive", json!({"reason":"early"})).await;
    assert_eq!(status, StatusCode::CONFLICT, "{open}");
    for (field, expected) in [("outcome", json!("rejected_run_not_closed")), ("command_status", json!("rejected")),
        ("reason", json!("run_not_closed")), ("rejection_reason", json!("run_not_closed")), ("accepted", json!(false))] {
        assert_eq!(open[field], expected, "{field}: {open}");
    }

    let task = poll(&app, "worker", "workflow").await;
    let done = finish_task(&app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Array(vec![]))}])).await;
    assert_eq!(done.0, StatusCode::OK, "{}", done.1);

    let (status, invalid) = request(&app, "POST", "/api/workflows/arc/archive", json!({"reason":"x".repeat(1001),"request_id":5})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{invalid}");
    assert_eq!(invalid["errors"], json!({"reason":["The reason field must not be greater than 1000 characters."],
        "request_id":["The request id field must be a string."]}));
    assert_eq!(invalid["workflow_id"], "arc");

    let (status, archived) = request(&app, "POST", "/api/workflows/arc/archive", json!({"reason":"  cleanup "})).await;
    assert_eq!(status, StatusCode::OK, "{archived}");
    assert_eq!((archived["outcome"].clone(), archived["reason"].clone(), archived["command_status"].clone()),
        (json!("archived"), json!("cleanup"), json!("accepted")), "{archived}");
    assert!(archived.get("accepted").is_none(), "{archived}");
    let path = format!("/api/workflows/arc/runs/{}/archive", started["run_id"].as_str().unwrap());
    let (status, again) = request(&app, "POST", &path, json!({})).await;
    assert_eq!(status, StatusCode::OK, "{again}");
    assert_eq!((again["outcome"].clone(), again["reason"].clone()), (json!("archive_not_needed"), Value::Null), "{again}");

    let history = run_history(&app, &json!("arc"), &started["run_id"]).await;
    let tail: Vec<_> = kinds(&history).into_iter().filter(|kind| kind.contains("Archive")).collect();
    assert_eq!(tail, ["ArchiveRequested", "WorkflowArchived", "ArchiveRequested"]);
    let archived_event = history.as_array().unwrap().iter().find(|event| event["event_type"] == "WorkflowArchived").unwrap();
    assert_eq!(archived_event["payload"]["archive_command_id"], archived["command_id"]);
    assert_eq!(archived_event["payload"]["reason"], "cleanup");
    let last = history.as_array().unwrap().last().unwrap();
    assert!(last["payload"].get("reason").is_none(), "{last}");
    let (_, described) = request(&app, "GET", "/api/workflows/arc", Value::Null).await;
    assert_eq!(described["actions"]["can_archive"], false, "{described}");

    let (status, historical) = request(&app, "POST", "/api/workflows/arc/runs/nope/archive", json!({})).await;
    assert_eq!(status, StatusCode::CONFLICT, "{historical}");
    assert_eq!(historical["reason"], "historical_run_command_rejected");
    let (status, missing) = request(&app, "POST", "/api/workflows/nope/archive", json!({})).await;
    assert_eq!(status, StatusCode::NOT_FOUND, "{missing}");
    assert_eq!(missing["reason"], "instance_not_found");
    runtime.close().await;
    database.remove().await;
}
