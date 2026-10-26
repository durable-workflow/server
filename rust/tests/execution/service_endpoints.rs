use super::*;

// Service catalog administration (`ServiceCatalogController`'s endpoint,
// service and operation routes) and the catalog reads over the rows it
// writes. The shared `service-endpoints` fixture records the same routes
// against published 2.11.1.

const OPERATION: &str = "/api/service-endpoints/billing/services/invoices/operations";

async fn stored_updated_at(database: &TestDatabase, table: &str) -> Vec<(String, String)> {
    // Test-owned table names only.
    text_rows(
        database,
        &format!("SELECT id, updated_at FROM {table} ORDER BY id"),
        &format!("SELECT id, updated_at::text FROM {table} ORDER BY id"),
        &format!("SELECT id, CAST(updated_at AS CHAR) FROM {table} ORDER BY id"),
    )
    .await
}

fn is_ulid(value: &Value) -> bool {
    value.as_str().is_some_and(|id| id.len() == 26 && id.bytes().all(|byte| byte.is_ascii_digit() || byte.is_ascii_lowercase()))
}

#[tokio::test]
async fn catalog_administration_follows_the_published_controller() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());

    // Endpoints: lowercased names, PHP's array documents, 409 with the id.
    let (status, billing) = request(&app, "POST", "/api/service-endpoints",
        json!({"endpoint_name": " Billing ", "description": "Payments", "metadata": {"team": "core", "empty": {}}})).await;
    assert_eq!(status, StatusCode::CREATED, "{billing}");
    assert!(is_ulid(&billing["id"]), "{billing}");
    assert_eq!((billing["namespace"].clone(), billing["endpoint_name"].clone(), billing["metadata"].clone()),
        (json!("default"), json!("billing"), json!({"team": "core", "empty": []})));
    assert_eq!(billing["created_at"], billing["updated_at"]);
    let (status, duplicate) = request(&app, "POST", "/api/service-endpoints", json!({"endpoint_name": "BILLING"})).await;
    assert_eq!(status, StatusCode::CONFLICT);
    assert_eq!(duplicate, json!({"message": "A service endpoint [billing] already exists in namespace [default].",
        "reason": "endpoint_already_exists", "namespace": "default", "endpoint_name": "billing", "endpoint_id": billing["id"]}));
    let (status, invalid) = request(&app, "POST", "/api/service-endpoints", json!({"endpoint_name": "bad name!", "metadata": "x"})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY);
    assert_eq!(invalid["errors"], json!({"endpoint_name": ["The endpoint name field format is invalid."],
        "metadata": ["The metadata field must be an array."]}));
    let (status, alpha) = request(&app, "POST", "/api/service-endpoints", json!({"endpoint_name": "alpha"})).await;
    assert_eq!((status, alpha["metadata"].clone(), alpha["description"].clone()), (StatusCode::CREATED, Value::Null, Value::Null));
    let (_, listed) = request(&app, "GET", "/api/service-endpoints", Value::Null).await;
    let names: Vec<_> = listed["service_endpoints"].as_array().unwrap().iter().map(|endpoint| endpoint["endpoint_name"].clone()).collect();
    assert_eq!(names, [json!("alpha"), json!("billing")], "endpoints list by name");
    let (status, shown) = request(&app, "GET", "/api/service-endpoints/BILLING", Value::Null).await;
    assert_eq!((status, shown), (StatusCode::OK, billing.clone()));
    let (status, missing) = request(&app, "GET", "/api/service-endpoints/Missing", Value::Null).await;
    assert_eq!((status, missing), (StatusCode::NOT_FOUND, json!({"accepted": false,
        "message": "Service endpoint [missing] not found in namespace [default].", "reason": "endpoint_not_found",
        "namespace": "default", "endpoint_name": "missing"})));

    // An unchanged update writes nothing; a change keeps the other fields.
    let before = stored_updated_at(&database, "workflow_service_endpoints").await;
    let (status, same) = request(&app, "PUT", "/api/service-endpoints/billing",
        json!({"description": "Payments", "metadata": {"team": "core", "empty": {}}})).await;
    assert_eq!((status, same), (StatusCode::OK, billing.clone()));
    assert_eq!(stored_updated_at(&database, "workflow_service_endpoints").await, before, "an unchanged update keeps updated_at");
    let (status, cleared) = request(&app, "PUT", "/api/service-endpoints/billing", json!({"description": "  "})).await;
    assert_eq!((status, cleared["description"].clone(), cleared["metadata"].clone()),
        (StatusCode::OK, Value::Null, json!({"team": "core", "empty": []})));
    assert_ne!(stored_updated_at(&database, "workflow_service_endpoints").await, before, "a change moves updated_at");

    // Services list in creation order, not by name.
    let (status, invoices) = request(&app, "POST", "/api/service-endpoints/billing/services",
        json!({"service_name": "Invoices", "metadata": {"tier": 1}})).await;
    assert_eq!(status, StatusCode::CREATED, "{invoices}");
    assert_eq!((invoices["endpoint_id"].clone(), invoices["endpoint_name"].clone(), invoices["service_name"].clone()),
        (billing["id"].clone(), json!("billing"), json!("invoices")));
    let (status, _) = request(&app, "POST", "/api/service-endpoints/billing/services", json!({"service_name": "accounts"})).await;
    assert_eq!(status, StatusCode::CREATED);
    let (_, services) = request(&app, "GET", "/api/service-endpoints/billing/services", Value::Null).await;
    let names: Vec<_> = services["services"].as_array().unwrap().iter().map(|service| service["service_name"].clone()).collect();
    assert_eq!(names, [json!("invoices"), json!("accounts")], "services list in creation order");
    let (status, duplicate) = request(&app, "POST", "/api/service-endpoints/billing/services", json!({"service_name": "INVOICES"})).await;
    assert_eq!((status, duplicate["reason"].clone(), duplicate["service_id"].clone()),
        (StatusCode::CONFLICT, json!("service_already_exists"), invoices["id"].clone()));
    let (status, missing) = request(&app, "GET", "/api/service-endpoints/billing/services/nope", Value::Null).await;
    assert_eq!((status, missing["message"].clone()),
        (StatusCode::NOT_FOUND, json!("Service [nope] not found under endpoint [billing] in namespace [default].")));
    let (status, missing) = request(&app, "POST", "/api/service-endpoints/nope/services", json!({})).await;
    assert_eq!((status, missing["reason"].clone()), (StatusCode::NOT_FOUND, json!("endpoint_not_found")), "the endpoint before the input");

    // Operations: required mode, kind and binding; a deadline policy; no rename.
    let (status, charge) = request(&app, "POST", OPERATION, json!({"operation_name": "Charge", "operation_mode": "sync",
        "handler_binding_kind": "start_workflow", "handler_target_reference": "billing.charge",
        "deadline_policy": {"timeout_seconds": 30}})).await;
    assert_eq!(status, StatusCode::CREATED, "{charge}");
    assert_eq!((charge["operation_name"].clone(), charge["service_id"].clone(), charge["deadline_policy"].clone(),
        charge["handler_binding"].clone()), (json!("charge"), invoices["id"].clone(), json!({"timeout_seconds": 30}), Value::Null));
    let (status, unbound) = request(&app, "POST", OPERATION, json!({"operation_name": "refund", "operation_mode": "async",
        "handler_binding_kind": "signal_workflow"})).await;
    assert_eq!((status, unbound["errors"].clone()), (StatusCode::UNPROCESSABLE_ENTITY,
        json!({"handler_target_reference": ["Provide handler_target_reference or a non-empty handler_binding."]})));
    let (status, deadline) = request(&app, "POST", OPERATION, json!({"operation_name": "refund", "operation_mode": "async",
        "handler_binding_kind": "signal_workflow", "handler_target_reference": "x", "deadline_policy": {"deadline_at": "2030-02-30T00:00:00Z"}})).await;
    assert_eq!((status, deadline["message"].clone()),
        (StatusCode::UNPROCESSABLE_ENTITY, json!("Service deadline_at must be a valid calendar instant.")));
    let charge_path = format!("{OPERATION}/CHARGE");
    let (status, renamed) = request(&app, "PUT", &charge_path, json!({"operation_name": "other"})).await;
    assert_eq!((status, renamed["message"].clone()), (StatusCode::UNPROCESSABLE_ENTITY, json!("The operation name field is prohibited.")));
    let (status, rebound) = request(&app, "PUT", &charge_path,
        json!({"handler_target_reference": null, "handler_binding": {"workflow_type": "billing.charge"}, "operation_mode": "async"})).await;
    assert_eq!(status, StatusCode::OK, "{rebound}");
    assert_eq!((rebound["handler_target_reference"].clone(), rebound["handler_binding"].clone(), rebound["operation_mode"].clone()),
        (Value::Null, json!({"workflow_type": "billing.charge"}), json!("async")));
    let (status, emptied) = request(&app, "PUT", &charge_path, json!({"handler_binding": {}})).await;
    assert_eq!((status, emptied["errors"]["handler_target_reference"].clone()), (StatusCode::UNPROCESSABLE_ENTITY,
        json!(["Provide handler_target_reference or a non-empty handler_binding."])));
    let (_, operations) = request(&app, "GET", OPERATION, Value::Null).await;
    assert_eq!(operations["operations"].as_array().unwrap().len(), 1, "{operations}");

    // Deletes refuse what still depends on the row.
    let (status, refused) = request(&app, "DELETE", "/api/service-endpoints/billing", Value::Null).await;
    assert_eq!((status, refused), (StatusCode::CONFLICT, json!({
        "message": "Service endpoint [billing] in namespace [default] still has registered services.",
        "reason": "endpoint_has_services", "namespace": "default", "endpoint_name": "billing"})));
    let (status, refused) = request(&app, "DELETE", "/api/service-endpoints/billing/services/invoices", Value::Null).await;
    assert_eq!((status, refused["reason"].clone()), (StatusCode::CONFLICT, json!("service_has_operations")));
    // Test-owned literals only.
    database.execute(sqlx::AssertSqlSafe(format!(
        "INSERT INTO workflow_service_calls(id,workflow_service_endpoint_id,workflow_service_id,workflow_service_operation_id,endpoint_name,service_name,operation_name,namespace,status,operation_mode,created_at,updated_at) \
         VALUES ('call-1','{}','{}','{}','billing','invoices','charge','default','completed','sync','2026-10-26 00:00:00','2026-10-26 00:00:00')",
        billing["id"].as_str().unwrap(), invoices["id"].as_str().unwrap(), charge["id"].as_str().unwrap()
    ))).await;
    let (status, refused) = request(&app, "DELETE", &charge_path, Value::Null).await;
    assert_eq!((status, refused["message"].clone()), (StatusCode::CONFLICT,
        json!("Operation [charge] under service [invoices] at endpoint [billing] in namespace [default] still has recorded service calls.")));
    database.execute(sqlx::AssertSqlSafe("DELETE FROM workflow_service_calls WHERE id='call-1'".to_owned())).await;
    let (status, deleted) = request(&app, "DELETE", &charge_path, Value::Null).await;
    assert_eq!((status, deleted), (StatusCode::OK, json!({"namespace": "default", "endpoint_name": "billing",
        "service_name": "invoices", "operation_name": "charge", "outcome": "deleted"})));
    for path in ["/api/service-endpoints/billing/services/invoices", "/api/service-endpoints/billing/services/accounts"] {
        let (status, _) = request(&app, "DELETE", path, Value::Null).await;
        assert_eq!(status, StatusCode::OK, "{path}");
    }
    let (status, deleted) = request(&app, "DELETE", "/api/service-endpoints/billing", Value::Null).await;
    assert_eq!((status, deleted), (StatusCode::OK, json!({"namespace": "default", "endpoint_name": "billing", "outcome": "deleted"})));
}

#[tokio::test]
async fn catalog_reads_render_the_namespace_rows() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    namespaces::create(&app, "other").await;
    let (_, billing) = request(&app, "POST", "/api/service-endpoints", json!({"endpoint_name": "billing"})).await;
    let (_, alpha) = request(&app, "POST", "/api/service-endpoints", json!({"endpoint_name": "alpha", "metadata": {"a": 1}})).await;
    let (_, invoices) = request(&app, "POST", "/api/service-endpoints/billing/services", json!({"service_name": "invoices"})).await;
    let (_, charge) = request(&app, "POST", "/api/service-endpoints/billing/services/invoices/operations",
        json!({"operation_name": "charge", "operation_mode": "sync", "handler_binding_kind": "start_workflow",
            "handler_target_reference": "billing.charge"})).await;
    let (status, _) = namespaces::scoped(&app, "other", "POST", "/api/service-endpoints", json!({"endpoint_name": "elsewhere"})).await;
    assert_eq!(status, StatusCode::CREATED, "a named namespace administers its own catalog");

    let (status, page) = request(&app, "GET", "/api/service-catalog/endpoints?per_page=1&page=2", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{page}");
    assert_eq!((page["total"].clone(), page["last_page"].clone(), page["current_page"].clone()), (json!(2), json!(2), json!(2)));
    assert_eq!(page["data"], json!([{"id": billing["id"], "namespace": "default", "endpoint_name": "billing", "description": null,
        "metadata": [], "created_at": billing["created_at"], "updated_at": billing["updated_at"]}]), "the read view defaults metadata to []");
    let (_, first) = request(&app, "GET", "/api/service-catalog/endpoints?per_page=1", Value::Null).await;
    assert_eq!(first["data"][0]["metadata"], alpha["metadata"]);
    let (_, services) = request(&app, "GET", &format!("/api/service-catalog/services?endpoint_id={}", billing["id"].as_str().unwrap()), Value::Null).await;
    assert_eq!((services["total"].clone(), services["data"][0]["workflow_service_endpoint_id"].clone()), (json!(1), billing["id"].clone()));
    let (_, none) = request(&app, "GET", &format!("/api/service-catalog/services?endpoint_id={}", alpha["id"].as_str().unwrap()), Value::Null).await;
    assert_eq!(none["total"], 0);
    let (_, operations) = request(&app, "GET", &format!("/api/service-catalog/operations?service_id={}", invoices["id"].as_str().unwrap()), Value::Null).await;
    assert_eq!((operations["total"].clone(), operations["data"][0]["handler_binding"].clone()), (json!(1), json!([])));

    let (status, endpoint) = request(&app, "GET", &format!("/api/service-catalog/endpoints/{}", billing["id"].as_str().unwrap()), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{endpoint}");
    assert_eq!((endpoint["services"][0]["id"].clone(), endpoint["operations"][0]["id"].clone(), endpoint["related_has_more"].clone()),
        (invoices["id"].clone(), charge["id"].clone(), json!({"services": false, "operations": false})));
    let (_, service) = request(&app, "GET", &format!("/api/service-catalog/services/{}", invoices["id"].as_str().unwrap()), Value::Null).await;
    assert_eq!((service["endpoint"]["id"].clone(), service["operations"][0]["operation_name"].clone(), service["related_has_more"].clone()),
        (billing["id"].clone(), json!("charge"), json!({"operations": false})));
    let (_, operation) = request(&app, "GET", &format!("/api/service-catalog/operations/{}", charge["id"].as_str().unwrap()), Value::Null).await;
    assert_eq!((operation["endpoint"]["endpoint_name"].clone(), operation["service"]["service_name"].clone(),
        operation["handler_target_reference"].clone()), (json!("billing"), json!("invoices"), json!("billing.charge")));
    let (status, foreign) = namespaces::scoped(&app, "other", "GET",
        &format!("/api/service-catalog/endpoints/{}", billing["id"].as_str().unwrap()), Value::Null).await;
    assert_eq!((status, foreign["reason"].clone()), (StatusCode::NOT_FOUND, json!("catalog_object_not_found")));
    let (_, other) = namespaces::scoped(&app, "other", "GET", "/api/service-catalog/endpoints", Value::Null).await;
    assert_eq!((other["total"].clone(), other["data"][0]["endpoint_name"].clone()), (json!(1), json!("elsewhere")));
}

#[tokio::test]
async fn catalog_administration_needs_an_admin() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap().with_role_tokens(
        Some("worker-only".into()),
        Some("operator-only".into()),
        Some("admin-only".into()),
    ));
    for (token, expected) in [("operator-only", StatusCode::FORBIDDEN), ("admin-only", StatusCode::OK)] {
        let (status, _, body) =
            admission::admission_http(&app, "GET", "/api/service-endpoints", false, Some(token), Some("2"), Some("default"), Value::Null).await;
        assert_eq!(status, expected, "{token}: {body}");
    }
    // The catalog reads stay operator routes.
    let (status, _, body) = admission::admission_http(&app, "GET", "/api/service-catalog/endpoints", false, Some("operator-only"),
        Some("2"), Some("default"), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
}
