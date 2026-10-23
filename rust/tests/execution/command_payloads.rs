use super::*;

const UNDECODABLE: &str = "The payload must contain one complete Avro Value encoded with the official codec. JSON is only the HTTP document transport.";

// Published PHP 2.11.1 refuses a workflow task completion whose command
// payloads are not complete Avro values, naming the field, before it applies
// any command; the task stays leased to its worker.
#[tokio::test]
async fn completion_payloads_must_decode_like_php() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    register(&app, "payload-worker", json!(["echo"]), json!(["a"])).await;
    let started = start(&app, "payloads").await;
    let task = poll(&app, "payload-worker", "workflow").await;
    let cases = [
        (json!([{"type":"complete_workflow","result":{"codec":"avro","blob":"AAAA"}}]), "commands.0.result.blob", UNDECODABLE),
        (json!([{"type":"record_side_effect","result":"AAAA"}]), "commands.0.result", UNDECODABLE),
        (json!([{"type":"schedule_activity","activity_type":"a","arguments":envelope(Payload::Array(vec![]))},
            {"type":"schedule_activity","activity_type":"a","arguments":{"codec":"avro","blob":"AAAA"}}]), "commands.1.arguments.blob", UNDECODABLE),
        (json!([{"type":"complete_workflow","result":[1, 2]}]), "commands.0.result",
            "Workflow task command field [result] must be a string or a payload envelope when provided."),
        (json!([{"type":"complete_workflow","result":{"codec":"","blob":"AAAA"}}]), "commands.0.result.codec",
            "The payload envelope codec must be a non-empty string."),
        (json!([{"type":"complete_workflow","result":{"codec":"avro","blob":5}}]), "commands.0.result.blob",
            "The payload envelope blob must be a string."),
    ];
    for (commands, field, message) in cases {
        let (status, refused) = finish_task(&app, &task, commands.clone()).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{commands}: {refused}");
        assert_eq!((refused["reason"].clone(), refused["errors"][field].clone()), (json!("validation_failed"), json!([message])), "{commands}: {refused}");
    }
    let (status, refused) = finish_task(&app, &task, json!([{"type":"complete_workflow","result":{"codec":"json","blob":"[]"}}])).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY);
    assert!(refused["errors"]["commands.0.result.codec"][0].as_str().unwrap().starts_with("unsupported_payload_codec:"), "{refused}");
    // Nothing was recorded; the same task still completes.
    let history = run_history(&app, &started["workflow_id"], &started["run_id"]).await;
    assert_eq!(history.as_array().unwrap().len(), 2, "{history}");
    let (status, done) = finish_task(&app, &task, json!([{"type":"complete_workflow","result":envelope(Payload::Long(1))}])).await;
    assert_eq!((status, done["run_status"].clone()), (StatusCode::OK, json!("completed")), "{done}");
    runtime.close().await;
    database.remove().await;
}
