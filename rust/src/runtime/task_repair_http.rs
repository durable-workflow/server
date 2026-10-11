//! Repair request and audit values derive from the authenticated HTTP request.
use super::{Result, http::AuthenticatedPrincipal, refuse, store::id};
use axum::{
    body::to_bytes,
    extract::{ConnectInfo, Request},
    http::StatusCode,
};
use serde::Serialize;
use serde_json::{Value, json};
use sha2::{Digest, Sha256};
use std::net::SocketAddr;

#[derive(Serialize)]
struct Fingerprint<'a> {
    method: &'a str,
    path: &'a str,
    payload: &'a Value,
    headers: &'a Value,
}

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
    let principal = parts
        .extensions
        .get::<AuthenticatedPrincipal>()
        .ok_or_else(|| refuse(StatusCode::UNAUTHORIZED, "unauthorized"))?;
    let header = |name: &str| {
        parts
            .headers
            .get(name)
            .and_then(|v| v.to_str().ok())
            .filter(|v| !v.is_empty())
            .map(str::to_owned)
    };
    let mut headers = serde_json::Map::new();
    for (name, key) in [
        ("x-request-id", "x_request_id"),
        ("x-correlation-id", "x_correlation_id"),
    ] {
        if let Some(value) = header(name) {
            headers.insert(key.into(), json!(value));
        }
    }
    let headers = if headers.is_empty() {
        json!([])
    } else {
        Value::Object(headers)
    };
    let normalized = if payload.as_object().is_some_and(|map| map.is_empty()) {
        json!([])
    } else {
        payload.clone()
    };
    let fingerprint = Sha256::digest(serde_json::to_vec(&Fingerprint {
        method: parts.method.as_str(),
        path: parts.uri.path(),
        payload: &normalized,
        headers: &headers,
    })?)
    .iter()
    .map(|byte| format!("{byte:02x}"))
    .collect::<String>();
    let mut request = json!({"method":parts.method.as_str(),"path":parts.uri.path(),
        "fingerprint":format!("sha256:{fingerprint}")});
    for (name, value) in [
        (
            "request_id",
            header("x-request-id").or_else(|| payload["request_id"].as_str().map(str::to_owned)),
        ),
        ("correlation_id", header("x-correlation-id")),
        ("user_agent", header("user-agent")),
        (
            "ip",
            parts
                .extensions
                .get::<ConnectInfo<SocketAddr>>()
                .map(|peer| peer.0.ip().to_string()),
        ),
    ] {
        if let Some(value) = value {
            request[name] = json!(value);
        }
    }
    if !headers.is_array() {
        request["headers"] = headers;
    }
    let subject = if principal.legacy_credential {
        "legacy-token".into()
    } else {
        format!("role:{}", principal.role)
    };
    let claims = if principal.legacy_credential {
        json!({"legacy_credential":true})
    } else {
        json!([])
    };
    let label = match principal.role {
        "admin" => "Admin",
        "operator" => "Operator",
        _ => "Worker",
    };
    let metadata = payload["request_id"]
        .as_str()
        .map_or(json!([]), |value| json!({"request_id":value}));
    let context = json!({
        "caller":{"type":"server","label":"Standalone Server"},
        "auth":{"status":"authorized","method":"token","role":principal.role,"roles":[principal.role],
            "principal":{"subject":subject,"roles":[principal.role],"claims":claims,"legacy_full_access":principal.legacy_full_access}},
        "principal":{"type":"auth:token","id":subject,"label":label},
        "request":request,"server":{"operation_id":id(),"namespace":"default","workflow_id":workflow_id,
            "command":"repair","metadata":metadata}});
    Ok((payload, context))
}
