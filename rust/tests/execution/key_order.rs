use super::*;

// Plain JSON input keeps document order, as published PHP's
// PayloadEnvelopeResolver and Avro::toDatum encode it, and several invalid
// search attributes on a start are reported in request order. The key-order
// fixture records the same answers against published 2.11.7.

async fn started(app: &Router, id: &str, input: Value) -> (StatusCode, Value) {
    request(app, "POST", "/api/workflows",
        json!({"workflow_id": id, "workflow_type": "echo", "task_queue": "test", "input": input})).await
}

async fn stored_input(app: &Router, id: &str) -> (Value, Value) {
    let (status, body) = request(app, "GET", &format!("/api/workflows/{id}"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    (body["input"].clone(), body["input_envelope"]["blob"].clone())
}

#[tokio::test]
async fn plain_input_keeps_document_order_like_php() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());

    // A nested map is encoded in document order: PHP's exact blob.
    let (status, body) = started(&app, "nested-map", json!([{"zeta": 1, "alpha": {"yak": 2, "bee": 3}}, "x"])).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    assert_eq!(stored_input(&app, "nested-map").await.1,
        json!("wwHioz3/VYAiNwwEDgQIemV0YQQCCmFscGhhDgQGeWFrBAQGYmVlBAYAAAoCeAA="));

    // An object input is its values in document order (array_values).
    for (id, input, values) in [
        ("object-integer-keys", json!({"1": "a", "0": "b"}), json!(["a", "b"])),
        ("object-named-keys", json!({"second": "b", "first": "a"}), json!(["b", "a"])),
    ] {
        let (status, body) = started(&app, id, input).await;
        assert_eq!(status, StatusCode::CREATED, "{body}");
        assert_eq!(stored_input(&app, id).await.0, values, "{id}");
    }

    // A nested object with an integer key is not a list unless its keys are
    // 0 to n-1 in document order; PHP's serializer refuses it.
    for (id, input) in [("nested-integer-keys", json!([{"1": "a", "0": "b"}])), ("nested-integer-key", json!([{"1": "a"}]))] {
        let (status, body) = started(&app, id, input).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
        assert_eq!(body["reason"], "validation_failed", "{body}");
        assert_eq!(body["message"], "invalid_map_key: Avro Value maps require string keys; keys are never stringified.");
        assert_eq!(body["errors"], json!({"workflow_type":
            ["invalid_map_key: Avro Value maps require string keys; keys are never stringified."]}));
        assert_eq!(request(&app, "GET", &format!("/api/workflows/{id}"), Value::Null).await.0, StatusCode::NOT_FOUND);
    }

    // A signal's plain arguments are stored the same way.
    let run = body_run(&app, "nested-map").await;
    let (status, body) = request(&app, "POST", "/api/workflows/nested-map/signal/go",
        json!({"input": [{"zeta": 1, "alpha": 2}]})).await;
    assert_eq!(status, StatusCode::ACCEPTED, "{body}");
    let history = run_history(&app, &json!("nested-map"), &run).await;
    let received: Vec<_> = history.as_array().unwrap().iter().filter(|event| event["event_type"] == "SignalReceived").collect();
    assert_eq!(received[0]["payload"]["arguments"]["blob"], json!("wwHioz3/VYAiNwwCDgQIemV0YQQCCmFscGhhBAQAAA=="));

    runtime.close().await;
    database.remove().await;
}

async fn body_run(app: &Router, id: &str) -> Value {
    request(app, "GET", &format!("/api/workflows/{id}"), Value::Null).await.1["run_id"].clone()
}

#[tokio::test]
async fn several_invalid_start_search_attributes_keep_request_order() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (status, body) = request(&app, "POST", "/api/workflows", json!({"workflow_id": "attribute-errors",
        "workflow_type": "echo", "task_queue": "test", "input": ["x"],
        "search_attributes": {"Zz": ["l"], "9bad": "x", "Aa": {"m": 1}}})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    assert_eq!(body["errors"]["search_attributes"], json!([
        "Search attribute [Zz] must be a scalar value or null unless it is registered as keyword_list.",
        "Search attribute key [9bad] must start with a letter and contain only letters, numbers, and underscores.",
        "Search attribute [Aa] must be a scalar value or null unless it is registered as keyword_list.",
    ]));
    runtime.close().await;
    database.remove().await;
}
