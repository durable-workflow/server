use super::*;
use futures_util::{StreamExt, stream};
use std::sync::{Arc, atomic::{AtomicUsize, Ordering}};
use std::time::Duration;
use tokio::io::{AsyncReadExt, AsyncWriteExt};

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

// A router-only response test cannot see a TCP reset while the caller is
// still writing. Both common request framings must deliver the actual 413.
#[tokio::test]
async fn oversized_completions_finish_the_upload_and_deliver_the_typed_tcp_refusal() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
    let address = listener.local_addr().unwrap();
    let (stop, stopped) = tokio::sync::oneshot::channel();
    let server = tokio::spawn(async move {
        axum::serve(listener, app).with_graceful_shutdown(async { let _ = stopped.await; }).await.unwrap();
    });
    let before = table_counts(&database).await;
    for chunked in [false, true] {
        let wire = tokio::time::timeout(Duration::from_secs(10), async {
            let mut client = tokio::net::TcpStream::connect(address).await.unwrap();
            let length = 36 * 1024 * 1024;
            let framing = if chunked { "Transfer-Encoding: chunked\r\n".to_owned() }
                else { format!("Content-Length: {length}\r\n") };
            client.write_all(format!("POST /api/worker/workflow-tasks/fixture-task/complete HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\n{framing}Connection: close\r\n\r\n").as_bytes()).await.unwrap();
            let chunk = vec![b'x'; 64 * 1024];
            for _ in 0..length/chunk.len() {
                if chunked { client.write_all(format!("{:X}\r\n", chunk.len()).as_bytes()).await.unwrap(); }
                client.write_all(&chunk).await.expect("the refusal must not reset an ordinary in-flight upload");
                if chunked { client.write_all(b"\r\n").await.unwrap(); }
            }
            if chunked { client.write_all(b"0\r\n\r\n").await.unwrap(); }
            let mut response = Vec::new();
            client.take(1024 * 1024).read_to_end(&mut response).await.expect("the typed refusal must be readable");
            String::from_utf8(response).unwrap()
        }).await.expect("bounded upload/refusal round trip");
        assert!(wire.starts_with("HTTP/1.1 413"), "{}", wire.chars().take(300).collect::<String>());
        for field in ["\"reason\":\"payload_too_large\"", "\"limit_kind\":\"workflow_task_completion_bytes\"", "\"limit\":33554432"] {
            assert!(wire.contains(field), "missing {field}");
        }
    }
    assert_eq!(table_counts(&database).await, before, "size refusals admit no durable work");
    stop.send(()).unwrap();
    tokio::time::timeout(Duration::from_secs(3), server).await.unwrap().unwrap();
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn oversized_body_discard_has_a_byte_budget_and_does_not_collect_the_upload() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let observed = Arc::new(AtomicUsize::new(0));
    let count = observed.clone();
    let chunk = axum::body::Bytes::from(vec![b'x'; 1024 * 1024]);
    let incoming = stream::repeat_with(move || {
        count.fetch_add(1, Ordering::SeqCst);
        Ok::<_, std::io::Error>(chunk.clone())
    }).take(200);
    let response = app.oneshot(Request::builder().method("POST").uri("/api/workflows")
        .header("content-length", 200 * 1024 * 1024).body(Body::from_stream(incoming)).unwrap()).await.unwrap();
    assert_eq!(response.status(), StatusCode::PAYLOAD_TOO_LARGE);
    let answer = to_bytes(response.into_body(), 1024 * 1024).await.unwrap();
    assert_eq!(serde_json::from_slice::<Value>(&answer).unwrap()["reason"], "payload_too_large");
    let consumed = observed.load(Ordering::SeqCst);
    assert!(consumed > 0 && consumed <= 65, "unbounded refused body consumption: {consumed} MiB");
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn a_stalled_oversized_body_cannot_hold_the_refusal_open_indefinitely() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let incoming = stream::pending::<Result<axum::body::Bytes, std::io::Error>>();
    let response = app.oneshot(Request::builder().method("POST").uri("/api/workflows")
        .header("content-length", 200 * 1024 * 1024).body(Body::from_stream(incoming)).unwrap()).await.unwrap();
    assert_eq!(response.status(), StatusCode::PAYLOAD_TOO_LARGE);
    let bytes = tokio::time::timeout(Duration::from_secs(5), to_bytes(response.into_body(), 1024 * 1024))
        .await.expect("refusal discard deadline").unwrap();
    assert_eq!(serde_json::from_slice::<Value>(&bytes).unwrap()["reason"], "payload_too_large");
    runtime.close().await;
    database.remove().await;
}

// Server #1136 (.github#228): a fan-out whose commands are each within the
// payload limit completes even when the whole completion is over it; a
// completion over the aggregate limit is refused with a typed 413.
#[tokio::test]
async fn a_large_fan_out_completion_is_not_refused_as_one_body() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    register(&app, "fan-out", json!(["echo"]), json!(["act"])).await;
    start(&app, "fan-out").await;
    let task = poll(&app, "fan-out", "workflow").await;
    let argument = envelope(Payload::Array(vec![Payload::String("x".repeat(10 * 1024))]));
    let commands: Vec<Value> = (0..300).map(|_| json!({"type":"schedule_activity","activity_type":"act","arguments":argument})).collect();
    let body = completion(&task, Value::Array(commands));
    assert!(body.to_string().len() > 2 * 1024 * 1024, "the completion exceeds one payload limit");
    let (status, answer) = finish_task(&app, &task, body["commands"].clone()).await;
    assert_eq!(status, StatusCode::OK, "{}", answer.to_string().chars().take(400).collect::<String>());

    let huge = format!("{{\"lease_owner\":\"fan-out\",\"workflow_task_attempt\":1,\"commands\":[],\"pad\":\"{}\"}}", "x".repeat(33 * 1024 * 1024));
    let (status, _, answer) = raw(&app, &format!("/api/worker/workflow-tasks/{}/complete", task["task_id"].as_str().unwrap()),
        Some("application/json"), huge.into_bytes(), true).await;
    assert_eq!(status, StatusCode::PAYLOAD_TOO_LARGE, "{answer}");
    assert_eq!(answer["reason"], "payload_too_large", "{answer}");
    assert_eq!(answer["limit_kind"], "workflow_task_completion_bytes", "{answer}");
    assert_eq!(answer["limit"], 32 * 1024 * 1024, "{answer}");
}

// Portable wire-size vectors are shared with SDK planners. A valid JSON body
// at the exact cap reaches authentication; the next byte is a typed refusal.
#[tokio::test]
async fn completion_wire_budget_matches_the_shared_discovery_vectors() {
    let vectors: Value = serde_json::from_str(include_str!("../../../resources/platform-protocol-specs/workflow-task-completion-limits-v1-vectors.json")).unwrap();
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap();
    let app = router(runtime.clone());
    let (_, info) = request(&app, "GET", "/api/cluster/info", Value::Null).await;
    for (field, expected) in vectors["default_limits"].as_object().unwrap() {
        assert_eq!(&info["limits"][field], expected, "discovery {field}");
    }
    let before = table_counts(&database).await;
    for case in vectors["wire_cases"].as_array().unwrap() {
        let routes: Vec<&str> = if case["route_kind"] == "completion" {
            vectors["completion_routes"].as_array().unwrap().iter().map(|route| route.as_str().unwrap()).collect()
        } else { vec![vectors["ordinary_route"].as_str().unwrap()] };
        let length = case["wire_bytes"].as_u64().unwrap() as usize;
        for route in routes {
            let mut body = vec![b'x'; length];
            body[0] = b'"';
            body[length - 1] = b'"';
            let (status, _, response) = raw(&app, route, Some("application/json"), body, false).await;
            if case["size_admitted"] == true {
                assert_eq!(status, StatusCode::UNAUTHORIZED, "{} {route}: {response}", case["id"]);
            } else {
                assert_eq!(status, StatusCode::PAYLOAD_TOO_LARGE, "{} {route}: {response}", case["id"]);
                assert_eq!(response["reason"], vectors["oversized_response"]["reason"]);
                if case["route_kind"] == "completion" {
                    for field in ["limit_kind", "limit"] {
                        assert_eq!(response[field], vectors["oversized_response"][field], "{field}");
                    }
                }
            }
        }
    }
    assert_eq!(table_counts(&database).await, before);
    runtime.close().await;
    database.remove().await;
}
