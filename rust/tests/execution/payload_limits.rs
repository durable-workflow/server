use super::*;

/// Sends `body` raw, with an optional content type and without credentials
/// unless `authorized`, and returns the status, headers and JSON answer.
async fn raw(
    app: &Router,
    uri: &str,
    content_type: Option<&str>,
    body: Vec<u8>,
    authorized: bool,
) -> (StatusCode, axum::http::HeaderMap, Value) {
    let mut request = Request::builder().method("POST").uri(uri).header("content-length", body.len());
    if let Some(content_type) = content_type {
        request = request.header("content-type", content_type);
    }
    if authorized {
        request = request.header("authorization", "Bearer test-token");
    }
    let response = app.clone().oneshot(request.body(Body::from(body)).unwrap()).await.unwrap();
    let (status, headers) = (response.status(), response.headers().clone());
    let body = to_bytes(response.into_body(), 1 << 20).await.unwrap();
    (status, headers, serde_json::from_slice(&body).unwrap_or_else(|_| panic!("{body:?}")))
}

// Published PHP's EnforcePayloadLimits runs before authentication and every
// route: oversized bodies, non-JSON media types and malformed JSON are
// refused in the worker or control-plane envelope.
#[tokio::test]
async fn bodies_are_checked_before_authentication_like_php() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let json = Some("application/json");

    for (body, error) in [
        (b"{".to_vec(), "Syntax error"),
        (b"  ".to_vec(), "Syntax error"),
        (b"{\"a\":\"\xff\"}".to_vec(), "Malformed UTF-8 characters, possibly incorrectly encoded"),
        (b"{\"a\":\"\x01\"}".to_vec(), "Control character error, possibly incorrectly encoded"),
    ] {
        let (status, headers, answer) = raw(&app, "/api/workflows", json, body, false).await;
        assert_eq!(status, StatusCode::BAD_REQUEST, "{answer}");
        assert_eq!(headers["x-durable-workflow-control-plane-version"], "2");
        assert_eq!(answer["message"], "Request bodies must contain valid JSON.");
        assert_eq!((answer["reason"].clone(), answer["json_error"].clone()), (json!("malformed_json"), json!(error)));
    }

    let (status, headers, answer) = raw(&app, "/api/worker/register", Some("text/plain"), b"{}".to_vec(), false).await;
    assert_eq!(status, StatusCode::UNSUPPORTED_MEDIA_TYPE, "{answer}");
    assert!(headers.contains_key("x-durable-workflow-protocol-version"));
    assert_eq!(answer["reason"], "unsupported_media_type");
    assert_eq!(answer["accepted_content_types"], json!(["application/json", "application/*+json"]));
    assert!(answer["protocol_version"].is_string() && answer["server_capabilities"].is_object(), "{answer}");
    let (status, _, answer) = raw(&app, "/api/worker/register", None, b"{}".to_vec(), true).await;
    assert_eq!((status, answer["reason"].clone()), (StatusCode::UNSUPPORTED_MEDIA_TYPE, json!("unsupported_media_type")));

    let limit = 2 * 1024 * 1024;
    let oversized = format!("{{\"input\":\"{}\"}}", "x".repeat(limit)).into_bytes();
    let (status, _, answer) = raw(&app, "/api/workflows", json, oversized, true).await;
    assert_eq!(status, StatusCode::PAYLOAD_TOO_LARGE, "{answer}");
    assert_eq!(answer["message"], format!("Request payload exceeds the maximum allowed size of {limit} bytes."));
    assert_eq!((answer["reason"].clone(), answer["limit"].clone()), (json!("payload_too_large"), json!(limit)));

    // A `+json` media type passes on to the route, which still authenticates.
    let (status, _, answer) = raw(&app, "/api/workflows", Some("application/vnd.api+json"), b"{}".to_vec(), false).await;
    assert_eq!(status, StatusCode::UNAUTHORIZED, "{answer}");
}
