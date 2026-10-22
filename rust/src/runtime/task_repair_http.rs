//! Repair request and audit values derive from the authenticated HTTP request.
use super::{Result, command_context::CommandRequest, refuse};
use axum::{body::to_bytes, extract::Request, http::StatusCode};
use serde_json::{Value, json};

pub(super) async fn request(request: Request, workflow_id: &str) -> Result<(Value, Value)> {
    let (parts, body) = request.into_parts();
    let bytes = to_bytes(body, 3 * 1024 * 1024)
        .await
        .map_err(|_| refuse(StatusCode::PAYLOAD_TOO_LARGE, "repair_request_too_large"))?;
    // SDK 2.2.7 sends JSON content type with no bytes for repair.
    let payload = if bytes.is_empty() {
        json!({})
    } else {
        serde_json::from_slice(&bytes)
            .map_err(|_| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_request"))?
    };
    let metadata = payload["request_id"]
        .as_str()
        .map_or(json!([]), |value| json!({"request_id":value}));
    let context = CommandRequest::from_parts(&parts)?.context(&payload, workflow_id, "repair", metadata);
    Ok((payload, context))
}
