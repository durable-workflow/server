//! Published PHP Server's `EnforcePayloadLimits`, which runs before every
//! other middleware, authentication included: a body over the payload limit,
//! a body without a JSON media type and a body that is not valid JSON are each
//! refused with PHP's JSON error, in the worker or control-plane envelope.
//! The external payload upload keeps its own transport rules.
use axum::{
    body::{Body, to_bytes},
    extract::Request,
    http::{Method, StatusCode, header},
    middleware::Next,
    response::Response,
};
use serde_json::{Value, json};

/// `server.limits.max_payload_bytes`.
pub(super) fn max_payload_bytes() -> usize {
    ["DW_MAX_PAYLOAD_BYTES", "WORKFLOW_MAX_PAYLOAD_BYTES"]
        .iter()
        .find_map(|key| std::env::var(key).ok())
        .and_then(|value| value.trim().parse::<usize>().ok())
        .unwrap_or(2 * 1024 * 1024)
}

pub(super) async fn enforce(request: Request, next: Next) -> Response {
    let path = request.uri().path().to_owned();
    if request.method() == Method::POST && path == "/api/external-payloads/v1" {
        return next.run(request).await;
    }
    let max = max_payload_bytes();
    let worker = path == "/api/worker" || path.starts_with("/api/worker/");
    let declared = request
        .headers()
        .get(header::CONTENT_LENGTH)
        .and_then(|value| value.to_str().ok())
        .and_then(|value| value.trim().parse::<u64>().ok());
    let (parts, body) = request.into_parts();
    let refuse = |status: StatusCode, body: Value| {
        let parts = parts.clone();
        async move { super::http::admission_error(Request::from_parts(parts, Body::empty()), worker, status, body).await }
    };
    let too_large = || json!({"message": format!("Request payload exceeds the maximum allowed size of {max} bytes."),
        "reason": "payload_too_large", "limit": max});
    if declared.is_some_and(|declared| declared > max as u64) {
        return refuse(StatusCode::PAYLOAD_TOO_LARGE, too_large()).await;
    }
    let Ok(bytes) = to_bytes(body, max.saturating_add(1)).await else {
        return refuse(StatusCode::PAYLOAD_TOO_LARGE, too_large()).await;
    };
    if bytes.len() > max {
        return refuse(StatusCode::PAYLOAD_TOO_LARGE, too_large()).await;
    }
    let has_body = !bytes.is_empty() || declared.is_some_and(|declared| declared > 0);
    if matches!(parts.method, Method::POST | Method::PUT | Method::PATCH | Method::DELETE) && has_body {
        if !json_media_type(parts.headers.get(header::CONTENT_TYPE).and_then(|value| value.to_str().ok())) {
            return refuse(StatusCode::UNSUPPORTED_MEDIA_TYPE, json!({"message": "Request bodies must use a JSON media type.",
                "reason": "unsupported_media_type", "accepted_content_types": ["application/json", "application/*+json"]})).await;
        }
        if let Some(error) = json_error(&bytes) {
            return refuse(StatusCode::BAD_REQUEST, json!({"message": "Request bodies must contain valid JSON.",
                "reason": "malformed_json", "json_error": error})).await;
        }
    }
    next.run(Request::from_parts(parts, Body::from(bytes))).await
}

/// `usesJsonMediaType`: `application/json` or any `+json` type.
fn json_media_type(content_type: Option<&str>) -> bool {
    let Some(content_type) = content_type.filter(|value| !value.trim().is_empty()) else {
        return false;
    };
    let media = content_type.split(';').next().unwrap_or("").trim().to_ascii_lowercase();
    media == "application/json" || media.ends_with("+json")
}

/// PHP's `json_last_error_msg()` after `json_decode` of the body, or `None`
/// for valid JSON. PHP nests up to 512 levels.
fn json_error(bytes: &[u8]) -> Option<&'static str> {
    // Skipping a value leaves escapes and UTF-8 unchecked, so decode it.
    let error = serde_json::from_slice::<Value>(bytes).err()?;
    let message = error.to_string();
    Some(if message.contains("recursion limit exceeded") {
        if depth(bytes) <= 512 {
            return None;
        }
        "Maximum stack depth exceeded"
    } else if message.contains("control character") {
        "Control character error, possibly incorrectly encoded"
    } else if message.contains("surrogate") || message.contains("unexpected end of hex escape") {
        "Single unpaired UTF-16 surrogate in unicode escape"
    } else if message.contains("invalid unicode code point") {
        "Malformed UTF-8 characters, possibly incorrectly encoded"
    } else {
        "Syntax error"
    })
}

/// The deepest array or object nesting, outside strings.
fn depth(bytes: &[u8]) -> usize {
    let (mut current, mut deepest, mut in_string, mut escaped) = (0usize, 0usize, false, false);
    for &byte in bytes {
        if in_string {
            match (escaped, byte) {
                (true, _) => escaped = false,
                (false, b'\\') => escaped = true,
                (false, b'"') => in_string = false,
                _ => {}
            }
            continue;
        }
        match byte {
            b'"' => in_string = true,
            b'[' | b'{' => {
                current += 1;
                deepest = deepest.max(current);
            }
            b']' | b'}' => current = current.saturating_sub(1),
            _ => {}
        }
    }
    deepest
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn errors_name_php_s_json_failures() {
        assert_eq!(json_error(br#"{"a":1}"#), None);
        assert_eq!(json_error(b"\"text\""), None);
        assert_eq!(json_error(br#"{"a":"#), Some("Syntax error"));
        assert_eq!(json_error(b"   "), Some("Syntax error"));
        assert_eq!(json_error(b"{\"a\":\"\x01\"}"), Some("Control character error, possibly incorrectly encoded"));
        assert_eq!(json_error(br#"{"a":"\ud800"}"#), Some("Single unpaired UTF-16 surrogate in unicode escape"));
        assert_eq!(json_error(br#"{"a":"\udc00"}"#), Some("Single unpaired UTF-16 surrogate in unicode escape"));
        assert_eq!(json_error(br#"{"a":"\ud83d\ude00"}"#), None);
        assert_eq!(json_error(b"{\"a\":\"\xff\"}"), Some("Malformed UTF-8 characters, possibly incorrectly encoded"));
        // PHP accepts nesting serde_json's default limit refuses.
        let nested = |levels: usize| format!("{}{}", "[".repeat(levels), "]".repeat(levels)).into_bytes();
        assert_eq!(json_error(&nested(200)), None);
        assert_eq!(json_error(&nested(600)), Some("Maximum stack depth exceeded"));
    }

    #[test]
    fn media_types_follow_php() {
        assert!(json_media_type(Some("application/json")));
        assert!(json_media_type(Some("Application/JSON; charset=utf-8")));
        assert!(json_media_type(Some("application/vnd.api+json")));
        assert!(!json_media_type(Some("text/plain")));
        assert!(!json_media_type(None));
    }
}
