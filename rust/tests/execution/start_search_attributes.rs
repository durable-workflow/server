use super::*;

// Published Server 2.11.5 (`WorkflowController::start`,
// `SearchAttributeValueValidator::validateForNamespace`) with Workflow
// 2.15.1 (`DefaultWorkflowControlPlane::seedTypedVisibilityMetadata`): a
// start's search attributes are validated against the namespace's registry,
// stored as the run's first typed rows and recorded on the start events.
// The start-search-attributes fixture records the same cases.

fn types(history: &Value) -> Vec<String> {
    history.as_array().unwrap().iter().map(|event| event["event_type"].as_str().unwrap().to_owned()).collect()
}

async fn define(app: &Router, name: &str, kind: &str) {
    let (status, body) = request(app, "POST", "/api/search-attributes", json!({"name": name, "type": kind})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
}

async fn start_with(app: &Router, id: &str, attributes: Value) -> (StatusCode, Value) {
    request(app, "POST", "/api/workflows", json!({"workflow_id": id, "workflow_type": "echo", "task_queue": "test",
        "input": envelope(Payload::Array(vec![])), "search_attributes": attributes})).await
}

fn start_payload(history: &Value, kind: &str) -> Value {
    let event = history.as_array().unwrap().iter().find(|event| event["event_type"] == kind).unwrap();
    event["payload"].get("search_attributes").cloned().unwrap_or(json!("absent"))
}

#[tokio::test]
async fn registered_start_search_attributes_are_typed_stored_and_recorded() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    for (name, kind) in [("CustomerTier", "keyword"), ("Note", "text"), ("Amount", "double"), ("Count", "int"),
        ("Flag", "bool"), ("When", "datetime"), ("Tags", "keyword_list")] {
        define(&app, name, kind).await;
    }
    let attributes = json!({"CustomerTier":"gold","Note":"café note λ","Amount":12.5,"Count":3,"Flag":true,
        "When":"2026-10-28T10:00:00Z","Tags":["a","b"]});
    let (status, started) = start_with(&app, "typed", attributes.clone()).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    let (_, described) = request(&app, "GET", "/api/workflows/typed", Value::Null).await;
    assert_eq!(described["search_attributes"], json!({"Amount":12.5,"Count":3,"CustomerTier":"gold","Flag":true,
        "Note":"café note λ","Tags":["a","b"],"When":"2026-10-28T10:00:00.000000Z"}), "{described}");
    let history = run_history(&app, &json!("typed"), &started["run_id"]).await;
    assert_eq!(start_payload(&history, "StartAccepted"), attributes);
    assert_eq!(start_payload(&history, "WorkflowStarted"), attributes);
    assert!(!types(&history).contains(&"SearchAttributesUpserted".to_owned()), "{history}");
    let (status, listed) = request(&app, "GET", "/api/workflows?query=CustomerTier%20%3D%20%22gold%22", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{listed}");
    assert_eq!(listed["workflows"].as_array().unwrap().iter().map(|row| row["workflow_id"].clone()).collect::<Vec<_>>(), [json!("typed")]);

    // A null value is recorded but not stored.
    let (status, started) = start_with(&app, "nulls", json!({"CustomerTier": null, "Count": 5})).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    let (_, described) = request(&app, "GET", "/api/workflows/nulls", Value::Null).await;
    assert_eq!(described["search_attributes"], json!({"Count": 5}));
    let history = run_history(&app, &json!("nulls"), &started["run_id"]).await;
    assert_eq!(start_payload(&history, "WorkflowStarted"), json!({"CustomerTier": null, "Count": 5}));

    // An empty map stores nothing: StartAccepted records null and
    // WorkflowStarted leaves the field out.
    let (status, started) = start_with(&app, "empty", json!({})).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    let history = run_history(&app, &json!("empty"), &started["run_id"]).await;
    assert_eq!(start_payload(&history, "StartAccepted"), Value::Null);
    assert_eq!(start_payload(&history, "WorkflowStarted"), json!("absent"));
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn invalid_start_search_attributes_are_refused_before_anything_is_written() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    define(&app, "Count", "int").await;
    define(&app, "Amount", "double").await;
    for (id, attributes, messages) in [
        ("unknown", json!({"Unregistered":"x"}), json!(["Search attribute [Unregistered] is not registered for this namespace."])),
        ("wrong-type", json!({"Count":"three"}), json!(["Search attribute [Count] is registered as int and must be an integer."])),
        // Native reports several errors in sorted key order (the port's
        // key-order gap); these keys are in that order already.
        ("numeric-string", json!({"Amount":"1.5","Count":"7"}), json!(["Search attribute [Amount] is registered as float and must be a number.",
            "Search attribute [Count] is registered as int and must be an integer."])),
        ("list", json!(["gold"]), json!(["Search attribute keys must be non-empty strings."])),
    ] {
        let (status, body) = start_with(&app, id, attributes).await;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{id}: {body}");
        assert_eq!(body["reason"], "validation_failed", "{id}");
        assert_eq!(body["errors"]["search_attributes"], messages, "{id}: {body}");
        assert_eq!(request(&app, "GET", &format!("/api/workflows/{id}"), Value::Null).await.0, StatusCode::NOT_FOUND, "{id}");
    }
    assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_search_attributes").await, 0);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn unregistered_start_search_attributes_infer_their_types() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (status, started) = start_with(&app, "inferred",
        json!({"Region":"eu","Size":4,"Ratio":0.25,"Enabled":false,"Seen":"2026-10-28T10:00:00Z"})).await;
    assert_eq!(status, StatusCode::CREATED, "{started}");
    let (_, described) = request(&app, "GET", "/api/workflows/inferred", Value::Null).await;
    // An unregistered datetime-looking value is a string.
    assert_eq!(described["search_attributes"], json!({"Enabled":false,"Ratio":0.25,"Region":"eu","Seen":"2026-10-28T10:00:00Z","Size":4}));
    let (status, body) = start_with(&app, "list-value", json!({"Labels":["x","y"]})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    assert_eq!(body["errors"]["search_attributes"],
        json!(["Search attribute [Labels] must be a scalar value or null unless it is registered as keyword_list."]));
    runtime.close().await;
    database.remove().await;
}

// Core normalizes the values while seeding the new run, after the
// controller's registry validation and its reservation and routing checks.
// The controller reports Core's LogicException under `workflow_type`: a key
// over 64 characters passes the registry's 128-byte limit but not Core's,
// and more than 100 attributes exceed Core's per-run count. A workflow id
// reserved in another namespace is refused first.
#[tokio::test]
async fn core_normalization_refusals_name_workflow_type_after_the_reservation_check() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let long_key = "A".repeat(65);
    let (status, body) = start_with(&app, "long-key", json!({long_key.clone(): "x"})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    let message = "Workflow v2 search attribute keys must be 1-64 URL-safe characters using letters, numbers, \".\", \"_\", \"-\", and \":\".";
    assert_eq!((body["reason"].clone(), body["message"].clone()), (json!("validation_failed"), json!(message)), "{body}");
    assert_eq!(body["errors"], json!({"workflow_type": [message]}), "{body}");
    let many: serde_json::Map<String, Value> = (0..101).map(|index| (format!("Attr{index:03}"), json!(index))).collect();
    let (status, body) = start_with(&app, "too-many", Value::Object(many)).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    assert_eq!(body["errors"], json!({"workflow_type": ["Search attributes count exceeds maximum (101 > 100)"]}), "{body}");
    for id in ["long-key", "too-many"] {
        assert_eq!(request(&app, "GET", &format!("/api/workflows/{id}"), Value::Null).await.0, StatusCode::NOT_FOUND, "{id}");
    }
    // An instance another namespace holds is refused before Core's seeding.
    let (status, body) = request(&app, "POST", "/api/namespaces", json!({"name": "other", "retention_days": 30})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    let (status, body) = super::worker_deregistration::named(&app, "other", "POST", "/api/workflows", json!({"workflow_id": "held", "workflow_type": "echo",
        "task_queue": "test", "input": envelope(Payload::Array(vec![]))})).await;
    assert_eq!(status, StatusCode::CREATED, "{body}");
    let (status, body) = start_with(&app, "held", json!({long_key: "x"})).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::CONFLICT, json!("workflow_id_reserved_in_namespace")), "{body}");
    assert_eq!(task_retry::rows(&database, "SELECT COUNT(*) FROM workflow_search_attributes").await, 0);
    runtime.close().await;
    database.remove().await;
}
