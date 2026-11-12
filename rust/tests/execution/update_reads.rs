use super::*;
use super::namespaces::{create as create_namespace, scoped};

// A caller reads an accepted update's receipt later by its id, optionally
// waiting for the outcome, without admitting another update (Server #1131).
#[tokio::test]
async fn an_update_is_read_later_by_id_without_admitting_another() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let contract = json!([{"name":"u","parameters":[]}]);
    let (status, body) = request(&app, "POST", "/api/worker/register", json!({"worker_id":"reader-worker","task_queue":"test",
        "runtime":"python","supported_workflow_types":["echo"],"supported_activity_types":[],"capabilities":["workflow_updates"],
        "workflow_command_contracts":{"echo":{"signals":[],"queries":[],"updates":["u"],"update_validators":[],
            "update_contracts":contract}}})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    let (status, started) = request(&app, "POST", "/api/workflows", json!({"workflow_id":"reader","workflow_type":"echo",
        "task_queue":"test","input":envelope(Payload::Array(vec![]))})).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    let task = poll(&app, "reader-worker", "workflow").await;
    let (status, body) = finish_task(&app, &task, json!([{"type":"start_timer","delay_seconds":3600}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (status, update) = request(&app, "POST", "/api/workflows/reader/update/u",
        json!({"input":envelope(Payload::Array(vec![])),"wait_for":"accepted"})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{update}");
    let id = update["update_id"].as_str().unwrap().to_owned();
    let path = format!("/api/workflows/reader/updates/{id}");

    // Still open: an explicit pending receipt, never a null result.
    let (status, pending) = request(&app, "GET", &path, Value::Null).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{pending}");
    assert_eq!((pending["update_id"].as_str(), pending["update_status"].as_str()), (Some(id.as_str()), Some("accepted")));
    assert_eq!((pending["result_envelope"].clone(), pending["wait_timed_out"].clone()), (Value::Null, json!(false)));
    let (status, timed_out) = request(&app, "GET", &format!("{path}?wait_for=completed&wait_timeout_seconds=1"), Value::Null).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{timed_out}");
    assert_eq!((timed_out["wait_timed_out"].clone(), timed_out["wait_timeout_seconds"].clone()), (json!(true), json!(1)));

    // A waiting read sees the outcome when the worker completes it, even a
    // null result, which stays distinct from pending.
    let waiting = {
        let app = app.clone();
        let path = format!("{path}?wait_for=completed&wait_timeout_seconds=30");
        tokio::spawn(async move { request(&app, "GET", &path, Value::Null).await })
    };
    let task = poll(&app, "reader-worker", "workflow").await;
    assert_eq!(task["workflow_update_id"], json!(id), "{task}");
    let (status, body) = finish_task(&app, &task,
        json!([{"type":"complete_update","update_id":id,"result":envelope(Payload::Null)}])).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    let (status, completed) = waiting.await.unwrap();
    assert_eq!(status, StatusCode::OK, "{completed}");
    assert_eq!(completed["update_status"], "completed");
    assert_eq!(completed["result_envelope"], envelope(Payload::Null));
    let (status, read) = request(&app, "GET", &path, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{read}");
    assert_eq!((read["update_status"].clone(), read["result_envelope"].clone()), (json!("completed"), envelope(Payload::Null)));

    // Reads never admitted another update.
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    let accepted = history.as_array().unwrap().iter().filter(|event| event["event_type"] == "UpdateAccepted").count();
    assert_eq!(accepted, 1, "{history}");

    // Unknown, another workflow's and another namespace's reads are not found.
    for missing in ["/api/workflows/reader/updates/01UNKNOWNUPDATE0000000000".to_owned(),
        format!("/api/workflows/other/updates/{id}")] {
        let (status, body) = request(&app, "GET", &missing, Value::Null).await;
        assert_eq!((status, body["reason"].clone()), (StatusCode::NOT_FOUND, json!("update_not_found")), "{body}");
    }
    create_namespace(&app, "elsewhere").await;
    let (status, body) = scoped(&app, "elsewhere", "GET", &path, Value::Null).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::NOT_FOUND, json!("update_not_found")), "{body}");
    let (status, body) = request(&app, "GET", &format!("{path}?wait_for=later"), Value::Null).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    let (status, body) = request(&app, "GET", &format!("{path}?wait_timeout_seconds=0"), Value::Null).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");

    let (status, info) = request(&app, "GET", "/api/cluster/info", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{info}");
    assert_eq!(info["capabilities"]["update_outcome_reads"], true, "{info}");
    runtime.close().await;
    database.remove().await;
}
