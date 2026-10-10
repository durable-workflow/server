//! Schedule HTTP controls and audit provenance derived after the shared guard.
use super::{Result, Runtime, refuse, store::id, text};
use axum::{
    Json,
    body::{Body, to_bytes},
    extract::{ConnectInfo, Path, Query, Request, State},
    http::{StatusCode, request::Parts},
};
use serde::{Deserialize, Serialize};
use serde_json::{Value, json};
use sha2::{Digest, Sha256};
use std::net::SocketAddr;

#[derive(Serialize)]
struct Fingerprint<'a> {
    method: &'a str,
    path: &'a str,
    payload: &'a Value,
    headers: Value,
}

fn normalized(value: &Value) -> Value {
    match value {
        Value::Object(map) if map.is_empty() => json!([]),
        Value::Object(map) => Value::Object(
            map.iter()
                .map(|(key, value)| (key.clone(), normalized(value)))
                .collect(),
        ),
        Value::Array(values) => Value::Array(values.iter().map(normalized).collect()),
        other => other.clone(),
    }
}

fn context(request: &Parts, payload: &Value, schedule: &str, operation: &str) -> Value {
    let header = |name: &str| {
        request
            .headers
            .get(name)
            .and_then(|value| value.to_str().ok())
            .filter(|value| !value.is_empty())
            .map(str::to_owned)
    };
    let method = request.method.as_str();
    let path = request.uri.path();
    let mut fingerprint_headers = serde_json::Map::new();
    for (header_name, key) in [
        ("x-request-id", "x_request_id"),
        ("x-correlation-id", "x_correlation_id"),
    ] {
        if let Some(value) = header(header_name) {
            fingerprint_headers.insert(key.to_owned(), json!(value));
        }
    }
    let encoded = serde_json::to_vec(&Fingerprint {
        method,
        path,
        payload: &normalized(payload),
        headers: if fingerprint_headers.is_empty() {
            json!([])
        } else {
            Value::Object(fingerprint_headers)
        },
    })
    .expect("JSON request fingerprint");
    let digest = Sha256::digest(encoded);
    let fingerprint = digest
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect::<String>();
    let ip = request
        .extensions
        .get::<ConnectInfo<SocketAddr>>()
        .map(|peer| peer.0.ip().to_string())
        .unwrap_or_else(|| "unknown".to_owned());
    let mut metadata = json!({"method":method,"path":path,"route_name":format!("api.schedules.{operation}"),
        "ip":ip,"user_agent":header("user-agent"),"fingerprint":format!("sha256:{fingerprint}")});
    for (name, field) in [
        ("x-request-id", "request_id"),
        ("x-correlation-id", "correlation_id"),
    ] {
        if let Some(value) = header(name).or_else(|| payload[field].as_str().map(str::to_owned)) {
            metadata[field] = json!(value);
        }
    }
    json!({"source":"control_plane","context":{
        "caller":{"type":"server","label":"Standalone Server"},
        "auth":{"status":"authorized","method":"token","role":"admin","roles":["admin"],
            "principal":{"subject":"legacy-token","roles":["admin"],"claims":{"legacy_credential":true},"legacy_full_access":true}},
        "principal":{"type":"auth:token","id":"legacy-token","label":"Admin"},
        "request":metadata,"server":{"operation_id":id(),"namespace":"default","workflow_id":schedule,
            "command":format!("schedule.{operation}"),"metadata":[]}}})
}

async fn body(request: Body) -> Result<Value> {
    let bytes = to_bytes(request, 3 * 1024 * 1024)
        .await
        .map_err(|_| refuse(StatusCode::PAYLOAD_TOO_LARGE, "schedule_request_too_large"))?;
    if bytes.is_empty() {
        return Ok(json!([]));
    }
    let value: Value = serde_json::from_slice(&bytes)
        .map_err(|_| refuse(StatusCode::BAD_REQUEST, "invalid_json"))?;
    Ok(if value.is_null() { json!([]) } else { value })
}

pub(super) async fn create(
    State(runtime): State<Runtime>,
    request: Request,
) -> Result<(StatusCode, Json<Value>)> {
    let (parts, bytes) = request.into_parts();
    let payload = body(bytes).await?;
    let audit = context(&parts, &payload, text(&payload, "schedule_id")?, "create");
    Ok((
        StatusCode::CREATED,
        Json(runtime.create_schedule(payload, audit).await?),
    ))
}

pub(super) async fn describe(
    State(runtime): State<Runtime>,
    Path(schedule): Path<String>,
) -> Result<Json<Value>> {
    runtime.describe_schedule(&schedule).await.map(Json)
}

#[derive(Deserialize)]
pub(super) struct Page {
    limit: Option<i64>,
    after_sequence: Option<i64>,
}

pub(super) async fn history(
    State(runtime): State<Runtime>,
    Path(schedule): Path<String>,
    Query(page): Query<Page>,
) -> Result<Json<Value>> {
    runtime
        .schedule_history(
            &schedule,
            page.after_sequence.unwrap_or(0),
            page.limit.unwrap_or(100),
        )
        .await
        .map(Json)
}

async fn control(
    runtime: Runtime,
    schedule: String,
    operation: &str,
    request: Request,
) -> Result<Json<Value>> {
    let (parts, bytes) = request.into_parts();
    let payload = body(bytes).await?;
    let empty = payload.as_object().is_some_and(|value| value.is_empty())
        || payload.as_array().is_some_and(|value| value.is_empty());
    if !empty {
        return Err(refuse(
            StatusCode::UNPROCESSABLE_ENTITY,
            "unsupported_schedule_control_field",
        ));
    }
    let audit = context(&parts, &payload, &schedule, operation);
    if operation == "trigger" {
        runtime.trigger_schedule(&schedule, audit).await.map(Json)
    } else {
        runtime
            .edit_schedule(&schedule, operation, audit)
            .await
            .map(Json)
    }
}

pub(super) async fn pause(
    State(runtime): State<Runtime>,
    Path(schedule): Path<String>,
    request: Request,
) -> Result<Json<Value>> {
    control(runtime, schedule, "pause", request).await
}
pub(super) async fn resume(
    State(runtime): State<Runtime>,
    Path(schedule): Path<String>,
    request: Request,
) -> Result<Json<Value>> {
    control(runtime, schedule, "resume", request).await
}
pub(super) async fn trigger(
    State(runtime): State<Runtime>,
    Path(schedule): Path<String>,
    request: Request,
) -> Result<Json<Value>> {
    control(runtime, schedule, "trigger", request).await
}
pub(super) async fn remove(
    State(runtime): State<Runtime>,
    Path(schedule): Path<String>,
    request: Request,
) -> Result<Json<Value>> {
    control(runtime, schedule, "delete", request).await
}
