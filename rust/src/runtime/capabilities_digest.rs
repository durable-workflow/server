//! Worker answers without repeated server capabilities (opt-in).
//!
//! Every worker-plane JSON answer carries `server_capabilities` (PHP's
//! `WorkerProtocol::json`), and a completion that carries a poll carries a
//! second copy in its `answer`. The object is about 17 KB, so on a small
//! deployment it is most of a worker's response bytes. A worker that sends
//! `X-Durable-Workflow-Server-Capabilities-Digest` opts in:
//!
//! - the header names the digest of the capabilities the worker holds, or
//!   `none`;
//! - when it names the current digest, each copy of the current capabilities
//!   in the answer (top level, or one or two objects deep, as in a carried
//!   poll's `answer`) is replaced by the digest string;
//! - otherwise each copy is sent in full with a `digest` member added, which
//!   the worker keeps for its next request;
//! - every opted-in answer also has the current digest in the same header.
//!
//! A request without the header gets exactly the bytes it got before. The
//! digest is `sha256:` and the lowercase hex SHA-256 of the capabilities'
//! compact JSON. A copy that differs from the current capabilities is never
//! replaced.
use axum::{
    body::{Body, HttpBody as _},
    extract::Request,
    http::{HeaderValue, header},
    middleware::Next,
    response::Response,
};
use serde_json::{json, value::RawValue};
use sha2::{Digest, Sha256};

pub(super) const HEADER: &str = "x-durable-workflow-server-capabilities-digest";
/// The largest answer that is rewritten; bigger ones pass through.
const MAX_BYTES: u64 = 64 * 1024 * 1024;
/// Top level, a carried poll, and its `answer`.
const MAX_DEPTH: usize = 3;

pub(super) fn digest(text: &str) -> String {
    let hash = Sha256::digest(text.as_bytes());
    let mut digest = String::with_capacity(7 + 64);
    digest.push_str("sha256:");
    for byte in hash {
        digest.push_str(&format!("{byte:02x}"));
    }
    digest
}

pub(super) async fn elide(request: Request, next: Next) -> Response {
    let path = request.uri().path();
    let worker = path == "/api/worker" || path.starts_with("/api/worker/");
    let held = worker
        .then(|| request.headers().get(HEADER).and_then(|value| value.to_str().ok()).map(|value| value.trim().to_owned()))
        .flatten();
    let response = next.run(request).await;
    match held {
        Some(held) => rewrite(response, &held).await,
        None => response,
    }
}

async fn rewrite(response: Response, held: &str) -> Response {
    let current = super::store::capabilities().to_string();
    let current_digest = digest(&current);
    let json = response
        .headers()
        .get(header::CONTENT_TYPE)
        .and_then(|value| value.to_str().ok())
        .is_some_and(|value| value.starts_with("application/json"));
    let size = response.body().size_hint().exact();
    let (mut parts, body) = response.into_parts();
    if let Ok(value) = HeaderValue::from_str(&current_digest) {
        parts.headers.insert(HEADER, value);
    }
    if !json || parts.headers.contains_key(header::CONTENT_ENCODING) || !size.is_some_and(|size| size <= MAX_BYTES) {
        return Response::from_parts(parts, body);
    }
    let Ok(bytes) = axum::body::to_bytes(body, MAX_BYTES as usize).await else {
        // An exact-sized in-memory body cannot fail to collect.
        return Response::from_parts(parts, Body::empty());
    };
    let replacement = if held == current_digest {
        json!(current_digest).to_string()
    } else {
        // `{...}` plus the digest member before the closing brace.
        let mut tagged = current[..current.len() - 1].to_owned();
        if tagged.len() > 1 {
            tagged.push(',');
        }
        tagged.push_str(&format!("\"digest\":{}}}", json!(current_digest)));
        tagged
    };
    match std::str::from_utf8(&bytes).ok().and_then(|text| replace(text, &current, &replacement, 1)) {
        Some(rewritten) => {
            parts.headers.remove(header::CONTENT_LENGTH);
            Response::from_parts(parts, Body::from(rewritten))
        }
        None => Response::from_parts(parts, Body::from(bytes)),
    }
}

/// The object with each `server_capabilities` member whose raw value is
/// `current` replaced, or `None` when nothing changed or it is not an object.
/// Members are rewritten from their raw text, so only whitespace between
/// them changes.
fn replace(text: &str, current: &str, replacement: &str, depth: usize) -> Option<String> {
    if !text.trim_start().starts_with('{') {
        return None;
    }
    let super::http::ObjectMembers(members) = serde_json::from_str(text).ok()?;
    let mut changed = false;
    let rendered: Vec<String> = members
        .iter()
        .map(|(key, raw): &(String, &RawValue)| {
            let raw = raw.get();
            let value = if key == "server_capabilities" && raw == current {
                changed = true;
                replacement.to_owned()
            } else if depth < MAX_DEPTH {
                match replace(raw, current, replacement, depth + 1) {
                    Some(nested) => {
                        changed = true;
                        nested
                    }
                    None => raw.to_owned(),
                }
            } else {
                raw.to_owned()
            };
            format!("{}:{value}", json!(key))
        })
        .collect();
    changed.then(|| format!("{{{}}}", rendered.join(",")))
}

#[cfg(test)]
mod tests {
    use super::*;
    use axum::{Router, middleware, routing::post};
    use serde_json::Value;
    use tower::ServiceExt as _;

    fn caps() -> Value {
        super::super::store::capabilities()
    }

    fn app() -> Router {
        let carried = || async {
            axum::Json(json!({"outcome": "completed", "server_capabilities": caps(),
                "activity_poll": {"leased": true, "answer": {"task": {"id": 1}, "poll_status": "leased", "server_capabilities": caps()}},
                "protocol_version": "1.20"}))
        };
        Router::new()
            .route("/api/worker/activity-tasks/poll", post(|| async { axum::Json(json!({"task": null, "server_capabilities": caps()})) }))
            .route("/api/worker/workflow-tasks/t/complete", post(carried))
            .route("/api/worker/other", post(|| async { axum::Json(json!({"server_capabilities": {"different": true}})) }))
            .route("/api/workflows", post(|| async { axum::Json(json!({"server_capabilities": caps()})) }))
            .layer(middleware::from_fn(elide))
    }

    async fn call(path: &str, held: Option<&str>) -> (Option<String>, Value, Vec<u8>) {
        let mut request = axum::http::Request::builder().method("POST").uri(path);
        if let Some(held) = held {
            request = request.header(HEADER, held);
        }
        let response = app().oneshot(request.body(Body::empty()).unwrap()).await.unwrap();
        let header = response.headers().get(HEADER).map(|value| value.to_str().unwrap().to_owned());
        let bytes = axum::body::to_bytes(response.into_body(), usize::MAX).await.unwrap().to_vec();
        (header, serde_json::from_slice(&bytes).unwrap(), bytes)
    }

    #[tokio::test]
    async fn answers_without_the_header_are_unchanged() {
        for path in ["/api/worker/activity-tasks/poll", "/api/worker/workflow-tasks/t/complete"] {
            let (header, body, _) = call(path, None).await;
            assert_eq!(header, None);
            assert_eq!(body["server_capabilities"], caps());
        }
        let (_, body, _) = call("/api/worker/workflow-tasks/t/complete", None).await;
        assert_eq!(body["activity_poll"]["answer"]["server_capabilities"], caps());
    }

    #[tokio::test]
    async fn a_worker_without_the_digest_gets_tagged_capabilities() {
        let current = digest(&caps().to_string());
        for held in ["none", "sha256:0000"] {
            let (header, body, _) = call("/api/worker/workflow-tasks/t/complete", Some(held)).await;
            assert_eq!(header.as_deref(), Some(current.as_str()));
            let mut tagged = caps();
            tagged["digest"] = json!(current);
            assert_eq!(body["server_capabilities"], tagged);
            assert_eq!(body["activity_poll"]["answer"]["server_capabilities"], tagged);
            // The digest covers the capabilities without the digest member.
            let mut untagged = body["server_capabilities"].clone();
            untagged.as_object_mut().unwrap().remove("digest");
            assert_eq!(digest(&untagged.to_string()), current);
        }
    }

    #[tokio::test]
    async fn a_worker_with_the_current_digest_gets_the_digest_in_place_of_each_copy() {
        let current = digest(&caps().to_string());
        let (header, body, bytes) = call("/api/worker/workflow-tasks/t/complete", Some(&current)).await;
        assert_eq!(header.as_deref(), Some(current.as_str()));
        assert_eq!(body["server_capabilities"], json!(current));
        assert_eq!(body["activity_poll"]["answer"]["server_capabilities"], json!(current));
        assert_eq!(body["activity_poll"]["answer"]["task"], json!({"id": 1}));
        assert_eq!(body["outcome"], json!("completed"));
        assert!(bytes.len() < 1024, "{}", bytes.len());
        let (_, poll, _) = call("/api/worker/activity-tasks/poll", Some(&current)).await;
        assert_eq!(poll, json!({"task": null, "server_capabilities": current}));
    }

    #[tokio::test]
    async fn other_values_and_the_control_plane_are_never_replaced() {
        let current = digest(&caps().to_string());
        let (_, body, _) = call("/api/worker/other", Some(&current)).await;
        assert_eq!(body["server_capabilities"], json!({"different": true}));
        let (header, body, _) = call("/api/workflows", Some(&current)).await;
        assert_eq!(header, None);
        assert_eq!(body["server_capabilities"], caps());
    }
}
