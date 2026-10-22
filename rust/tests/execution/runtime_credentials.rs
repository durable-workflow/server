//! Database runtime credentials, as published PHP Server manages them
//! (`RuntimeCredentialController`) and authenticates their tokens for one
//! tenant namespace (`ConfiguredAuthProvider`).
use super::*;
use admission::admission_http;

const WORKER_TOKEN: &str = "dwr_fixture_worker_default_0123456789";
const OPERATOR_TOKEN: &str = "dwr_fixture_operator_team_a_0123456789";

async fn admin(app: &Router, method: &str, path: &str, body: Value) -> (StatusCode, Value) {
    let (status, _, body) = admission_http(app, method, path, false, Some("test-token"), Some("2"), None, body).await;
    (status, body)
}

async fn as_token(app: &Router, token: &str, method: &str, path: &str, worker: bool, namespace: Option<&str>) -> StatusCode {
    let version = if worker { "1.20" } else { "2" };
    admission_http(app, method, path, worker, Some(token), Some(version), namespace, json!({})).await.0
}

fn credential(token: &str, roles: Value, tenant: &str) -> Value {
    json!({"token":token,"subject":"svc:sample","roles":roles,"tenant":tenant,"claims":{"team":"core"}})
}

#[tokio::test]
async fn runtime_credentials_are_managed_like_php() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone().with_auth_options(false, true));
    let (status, created) = admin(&app, "PUT", "/api/runtime-credentials/worker-a",
        credential(WORKER_TOKEN, json!(["worker", "worker"]), "Default")).await;
    assert_eq!(status, StatusCode::CREATED, "{created}");
    assert_eq!((created["created"].clone(), created["roles"].clone(), created["tenant"].clone(), created["token_prefix"].clone()),
        (json!(true), json!(["worker"]), json!("default"), json!("dwr_fixture_")));
    assert!(created.get("token_hash").is_none() && created.get("token").is_none(), "{created}");
    // The same attributes confirm the credential; different ones conflict.
    let (status, again) = admin(&app, "PUT", "/api/runtime-credentials/worker-a",
        credential(WORKER_TOKEN, json!(["worker"]), "default")).await;
    assert_eq!((status, again["created"].clone()), (StatusCode::OK, json!(false)), "{again}");
    let (status, conflict) = admin(&app, "PUT", "/api/runtime-credentials/worker-a",
        credential(WORKER_TOKEN, json!(["operator"]), "default")).await;
    assert_eq!((status, conflict["reason"].clone()), (StatusCode::CONFLICT, json!("runtime_credential_conflict")));
    let (status, reused) = admin(&app, "PUT", "/api/runtime-credentials/worker-b",
        credential(WORKER_TOKEN, json!(["worker"]), "default")).await;
    assert_eq!((status, reused["reason"].clone()), (StatusCode::CONFLICT, json!("runtime_credential_token_conflict")));
    // Validation follows the controller's rules and messages.
    let (status, invalid) = admin(&app, "PUT", "/api/runtime-credentials/-bad", credential(WORKER_TOKEN, json!(["worker"]), "default")).await;
    assert_eq!((status, invalid["errors"]["credential_id"].clone()), (StatusCode::UNPROCESSABLE_ENTITY, json!(["The credential id format is invalid."])));
    let (status, invalid) = admin(&app, "PUT", "/api/runtime-credentials/short",
        json!({"token":"short","subject":"s","roles":["admin"],"tenant":"bad tenant","claims":["x"]})).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{invalid}");
    assert_eq!(invalid["errors"]["token"], json!(["The token field must be at least 32 characters."]));
    assert_eq!(invalid["errors"]["tenant"], json!(["The tenant field format is invalid."]));
    assert_eq!(invalid["errors"]["roles.0"], json!(["The selected roles.0 is invalid."]));
    let (status, listed) = admin(&app, "GET", "/api/runtime-credentials", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{listed}");
    assert_eq!(listed["credentials"].as_array().unwrap().len(), 1);
    assert_eq!(listed["credentials"][0]["claims"], json!({"team":"core"}));
    let (status, missing) = admin(&app, "GET", "/api/runtime-credentials/nobody", Value::Null).await;
    assert_eq!((status, missing["reason"].clone()), (StatusCode::NOT_FOUND, json!("runtime_credential_not_found")));
    // Only an administrator manages credentials.
    assert_eq!(as_token(&app, WORKER_TOKEN, "GET", "/api/runtime-credentials", false, None).await, StatusCode::FORBIDDEN);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn runtime_credentials_authenticate_within_their_tenant() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone().with_auth_options(false, true));
    assert_eq!(admin(&app, "PUT", "/api/runtime-credentials/worker-a", credential(WORKER_TOKEN, json!(["worker"]), "default")).await.0, StatusCode::CREATED);
    assert_eq!(admin(&app, "PUT", "/api/runtime-credentials/operator-a", credential(OPERATOR_TOKEN, json!(["operator"]), "team-a")).await.0, StatusCode::CREATED);
    // A worker credential reaches worker routes of its own namespace only.
    assert_eq!(as_token(&app, WORKER_TOKEN, "GET", "/api/cluster/info", false, None).await, StatusCode::OK);
    assert_eq!(as_token(&app, WORKER_TOKEN, "GET", "/api/workflows", false, None).await, StatusCode::FORBIDDEN);
    // An operator credential for another tenant cannot read the default namespace.
    assert_eq!(as_token(&app, OPERATOR_TOKEN, "GET", "/api/workflows", false, None).await, StatusCode::FORBIDDEN);
    assert_eq!(as_token(&app, OPERATOR_TOKEN, "GET", "/api/namespaces", false, Some("team-a")).await, StatusCode::FORBIDDEN);
    // A namespace route without a path target takes it from `name`.
    assert_eq!(as_token(&app, OPERATOR_TOKEN, "GET", "/api/namespaces?name=Team-A", false, Some("team-a")).await, StatusCode::OK);
    assert_eq!(as_token(&app, OPERATOR_TOKEN, "GET", "/api/namespaces?name=default", false, Some("team-a")).await, StatusCode::FORBIDDEN);
    assert_eq!(as_token(&app, "dwr_unknown_token_0123456789_0123456789", "GET", "/api/cluster/info", false, None).await, StatusCode::UNAUTHORIZED);
    // Rotation replaces the token; revocation ends it; rotation restores it.
    let rotated = "dwr_fixture_worker_default_0123456789_rotated";
    let (status, body) = admin(&app, "POST", "/api/runtime-credentials/worker-a/rotate", json!({"token":rotated})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert!(body["rotated_at"].is_string(), "{body}");
    assert_eq!(as_token(&app, WORKER_TOKEN, "GET", "/api/cluster/info", false, None).await, StatusCode::UNAUTHORIZED);
    assert_eq!(as_token(&app, rotated, "GET", "/api/cluster/info", false, None).await, StatusCode::OK);
    let (status, body) = admin(&app, "DELETE", "/api/runtime-credentials/worker-a", Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert!(body["revoked_at"].is_string(), "{body}");
    assert_eq!(as_token(&app, rotated, "GET", "/api/cluster/info", false, None).await, StatusCode::UNAUTHORIZED);
    assert_eq!(admin(&app, "POST", "/api/runtime-credentials/worker-a/rotate", json!({"token":rotated})).await.0, StatusCode::OK);
    assert_eq!(as_token(&app, rotated, "GET", "/api/cluster/info", false, None).await, StatusCode::OK);
    // With database credentials disabled, the token is unknown.
    let disabled = router(runtime.clone());
    assert_eq!(as_token(&disabled, rotated, "GET", "/api/cluster/info", false, None).await, StatusCode::UNAUTHORIZED);
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn the_none_driver_admits_every_request() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone().with_auth_options(true, false));
    let (status, _, body) = admission_http(&app, "GET", "/api/workflows", false, None, Some("2"), None, json!({})).await;
    assert_eq!(status, StatusCode::OK, "{body}");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_failed_credential_lookup_refuses_without_falling_back() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone().with_auth_options(false, true));
    assert_eq!(admin(&app, "PUT", "/api/runtime-credentials/worker-a", credential(WORKER_TOKEN, json!(["worker"]), "default")).await.0, StatusCode::CREATED);
    // The lookup's table is gone, so the database refuses every lookup.
    database.execute("ALTER TABLE runtime_credentials RENAME TO runtime_credentials_moved").await;
    let (status, _, body) = admission_http(&app, "GET", "/api/cluster/info", false, Some(WORKER_TOKEN), Some("2"), None, json!({})).await;
    assert_eq!((status, body["reason"].clone()), (StatusCode::INTERNAL_SERVER_ERROR, json!("server_error")), "{body}");
    assert!(!body.to_string().contains("runtime_credentials") && !body.to_string().contains(WORKER_TOKEN), "{body}");
    // Configured tokens are matched first and still authenticate; a request
    // without a token is still refused.
    assert_eq!(admin(&app, "GET", "/api/cluster/info", Value::Null).await.0, StatusCode::OK);
    let (status, _, _) = admission_http(&app, "GET", "/api/cluster/info", false, None, Some("2"), None, json!({})).await;
    assert_eq!(status, StatusCode::UNAUTHORIZED);
    runtime.close().await;
    database.remove().await;
}
