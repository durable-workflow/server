use super::*;

async fn call(
    app: &Router,
    token: &str,
    namespace: &str,
    method: &str,
    path: &str,
    body: Value,
) -> (StatusCode, Value) {
    let (status, _, body) = admission::admission_http(
        app,
        method,
        path,
        false,
        Some(token),
        Some("2"),
        Some(namespace),
        body,
    )
    .await;
    (status, body)
}

#[tokio::test]
async fn search_attribute_definitions_follow_the_published_registry_rules() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    namespaces::create(&app, "attributes").await;
    let scoped = |method: &'static str, path: &'static str, body: Value| {
        let app = app.clone();
        async move { namespaces::scoped(&app, "attributes", method, path, body).await }
    };

    let (status, empty) = scoped("GET", "/api/search-attributes", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{empty}");
    assert_eq!(empty["custom_attributes"], json!([]));
    assert_eq!(empty["system_attributes"]["WorkflowType"], "keyword");
    assert_eq!(empty["system_attributes"]["StartTime"], "datetime");
    assert_eq!(empty["system_attributes"].as_object().unwrap().len(), 11);

    let (status, created) = scoped(
        "POST",
        "/api/search-attributes",
        json!({"name":" OrderTotal ","type":"double"}),
    )
    .await;
    assert_eq!(status, StatusCode::CREATED, "{created}");
    assert_eq!(
        created,
        json!({"name":"OrderTotal","type":"double","outcome":"created"})
    );
    scoped(
        "POST",
        "/api/search-attributes",
        json!({"name":"Customer","type":"keyword"}),
    )
    .await;

    let listed = scoped("GET", "/api/search-attributes", Value::Null).await.1;
    assert_eq!(
        listed["custom_attributes"],
        json!({"Customer":"keyword","OrderTotal":"double"})
    );
    // Definitions belong to one namespace.
    let default = request(&app, "GET", "/api/search-attributes", Value::Null).await;
    assert_eq!(default.1["custom_attributes"], json!([]));

    let (status, duplicate) = scoped(
        "POST",
        "/api/search-attributes",
        json!({"name":"OrderTotal","type":"int"}),
    )
    .await;
    assert_eq!(status, StatusCode::CONFLICT);
    assert_eq!(
        duplicate,
        json!({"message":"A custom search attribute [OrderTotal] already exists in namespace [attributes].",
            "reason":"attribute_already_exists","name":"OrderTotal","type":"double"})
    );
    for name in ["WorkflowId", "task_queue", "Execution_Status"] {
        let (status, reserved) = scoped(
            "POST",
            "/api/search-attributes",
            json!({"name":name,"type":"keyword"}),
        )
        .await;
        assert_eq!(status, StatusCode::CONFLICT, "{name}");
        assert_eq!(reserved["reason"], "name_reserved");
        assert_eq!(
            reserved["message"],
            format!("The name [{name}] is reserved for system search attributes.")
        );
    }
    let (status, invalid) = scoped(
        "POST",
        "/api/search-attributes",
        json!({"name":"1st","type":"uuid"}),
    )
    .await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY);
    assert_eq!(
        invalid,
        json!({"message":"The name field format is invalid. (and 1 more error)","reason":"validation_failed",
            "errors":{"name":["The name field format is invalid."],"type":["The selected type is invalid."]},
            "validation_errors":{"name":["The name field format is invalid."],"type":["The selected type is invalid."]}})
    );

    let (status, system) =
        scoped("DELETE", "/api/search-attributes/WorkflowType", Value::Null).await;
    assert_eq!(status, StatusCode::CONFLICT);
    assert_eq!(
        system,
        json!({"message":"The system search attribute [WorkflowType] cannot be removed.","reason":"system_attribute"})
    );
    let (status, missing) = scoped("DELETE", "/api/search-attributes/Absent", Value::Null).await;
    assert_eq!(status, StatusCode::NOT_FOUND);
    assert_eq!(
        missing,
        json!({"message":"Custom search attribute [Absent] not found in namespace [attributes].","reason":"attribute_not_found"})
    );
    let (status, deleted) = scoped("DELETE", "/api/search-attributes/Customer", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{deleted}");
    assert_eq!(deleted, json!({"name":"Customer","outcome":"deleted"}));
    assert_eq!(
        scoped("GET", "/api/search-attributes", Value::Null).await.1["custom_attributes"],
        json!({"OrderTotal":"double"})
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_namespace_holds_at_most_the_configured_number_of_definitions() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    namespaces::create(&app, "attribute-limit").await;
    for index in 0..100 {
        let (status, body) = namespaces::scoped(
            &app,
            "attribute-limit",
            "POST",
            "/api/search-attributes",
            json!({"name":format!("Attribute{index}"),"type":"int"}),
        )
        .await;
        assert_eq!(status, StatusCode::CREATED, "{body}");
    }
    let (status, refused) = namespaces::scoped(
        &app,
        "attribute-limit",
        "POST",
        "/api/search-attributes",
        json!({"name":"OneTooMany","type":"int"}),
    )
    .await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY);
    assert_eq!(
        refused,
        json!({"message":"Namespace [attribute-limit] has reached the maximum of 100 custom search attributes.",
            "reason":"search_attribute_limit_reached","limit":100})
    );
    // The limit is per namespace.
    let (status, _) = request(
        &app,
        "POST",
        "/api/search-attributes",
        json!({"name":"OneTooMany","type":"int"}),
    )
    .await;
    assert_eq!(status, StatusCode::CREATED);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn operators_manage_definitions_and_workers_cannot() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap().with_role_tokens(
        Some("worker-token".into()),
        Some("operator-token".into()),
        Some("admin-token".into()),
    );
    let app = router(runtime.clone());
    let body = json!({"name":"Region","type":"keyword"});
    let (status, refused) = call(
        &app,
        "worker-token",
        "default",
        "POST",
        "/api/search-attributes",
        body.clone(),
    )
    .await;
    assert_eq!(status, StatusCode::FORBIDDEN, "{refused}");
    let (status, created) = call(
        &app,
        "operator-token",
        "default",
        "POST",
        "/api/search-attributes",
        body,
    )
    .await;
    assert_eq!(status, StatusCode::CREATED, "{created}");
    let (status, deleted) = call(
        &app,
        "operator-token",
        "default",
        "DELETE",
        "/api/search-attributes/Region",
        Value::Null,
    )
    .await;
    assert_eq!(status, StatusCode::OK, "{deleted}");
    runtime.close().await;
    database.remove().await;
}
