//! Schedule HTTP controls and audit provenance derived after the shared guard.
use super::{Result, Runtime, refuse, store::id};
use super::laravel_rules::{Rule, Rules, normalize, validate};
use axum::{
    Extension, Json,
    body::{Body, to_bytes},
    extract::{ConnectInfo, Path, Query, Request},
    http::{StatusCode, request::Parts},
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
    headers: AttributionHeaders,
}

#[derive(Serialize)]
#[serde(untagged)]
enum AttributionHeaders {
    Empty([String; 0]),
    Present {
        #[serde(skip_serializing_if = "Option::is_none")]
        x_request_id: Option<String>,
        #[serde(skip_serializing_if = "Option::is_none")]
        x_correlation_id: Option<String>,
    },
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

fn context(request: &Parts, payload: &Value, namespace: &str, schedule: &str, operation: &str) -> Value {
    context_for(request, payload, namespace, schedule, operation, operation)
}

/// The command context of `command` for a request routed as `route`.
fn context_for(
    request: &Parts,
    payload: &Value,
    namespace: &str,
    schedule: &str,
    route: &str,
    command: &str,
) -> Value {
    // PHP fingerprints the request as Laravel normalized it.
    let payload = &normalize(payload);
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
    let request_id = header("x-request-id");
    let correlation_id = header("x-correlation-id");
    let headers = if request_id.is_none() && correlation_id.is_none() {
        AttributionHeaders::Empty([])
    } else {
        AttributionHeaders::Present {
            x_request_id: request_id.clone(),
            x_correlation_id: correlation_id.clone(),
        }
    };
    let recorded_headers = serde_json::to_value(&headers).expect("JSON attribution headers");
    let encoded = serde_json::to_vec(&Fingerprint {
        method,
        path,
        payload: &normalized(payload),
        headers,
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
    let mut metadata = json!({"method":method,"path":path,"route_name":format!("api.schedules.{route}"),
        "ip":ip,"user_agent":header("user-agent"),"fingerprint":format!("sha256:{fingerprint}")});
    if !recorded_headers.is_array() {
        metadata["headers"] = recorded_headers;
    }
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
        "request":metadata,"server":{"operation_id":id(),"namespace":namespace,"workflow_id":schedule,
            "command":format!("schedule.{command}"),"metadata":[]}}})
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

/// `POST /api/schedules`.
pub(super) async fn create(
    Extension(runtime): Extension<Runtime>,
    request: Request,
) -> Result<(StatusCode, Json<Value>)> {
    let (parts, bytes) = request.into_parts();
    let payload = body(bytes).await?;
    let namespace = runtime.namespace().to_owned();
    // The audit names the caller's schedule id, or PHP's generated one.
    let target = payload["schedule_id"]
        .as_str()
        .map(str::trim)
        .filter(|id| !id.is_empty())
        .map_or_else(|| ulid::Ulid::new().to_string(), str::to_owned);
    let created = context(&parts, &payload, &namespace, &target, "create");
    let paused = context_for(&parts, &payload, &namespace, &target, "create", "pause");
    Ok((
        StatusCode::CREATED,
        Json(runtime.create_schedule(payload, target, created, paused).await?),
    ))
}

/// `GET /api/schedules/{id}`.
pub(super) async fn describe(
    Extension(runtime): Extension<Runtime>,
    Path(schedule): Path<String>,
) -> Result<Json<Value>> {
    runtime.describe_schedule(&schedule).await.map(Json)
}

/// `ScheduleController::parseHistoryLimit`: 100 by default, at most 500.
fn history_limit(raw: Option<&str>) -> i64 {
    let value = raw.map_or(0, |text| super::schedule_cron::php_int(text));
    if value <= 0 { 100 } else { value.min(500) }
}

/// `GET /api/schedules/{id}/history`.
pub(super) async fn history(
    Extension(runtime): Extension<Runtime>,
    Path(schedule): Path<String>,
    Query(query): Query<std::collections::HashMap<String, String>>,
) -> Result<Json<Value>> {
    let after = match query.get("after_sequence").map(String::as_str) {
        None | Some("") => 0,
        Some(text) => match text
            .strip_prefix('-')
            .unwrap_or(text)
            .bytes()
            .all(|byte| byte.is_ascii_digit())
            .then(|| text.parse::<i64>().ok())
            .flatten()
            .filter(|value| *value >= 0)
        {
            Some(value) => value,
            None => {
                return Err(super::RuntimeError::Protocol {
                    status: StatusCode::UNPROCESSABLE_ENTITY,
                    response: json!({"message":"after_sequence must be a non-negative integer.",
                        "reason":"invalid_after_sequence"}),
                });
            }
        },
    };
    let limit = history_limit(query.get("limit").map(String::as_str));
    runtime
        .schedule_history(&schedule, after, limit)
        .await
        .map(Json)
}

const NOTE: &Rules = &[("note", &[Rule::Nullable, Rule::String, Rule::Max(1000)])];
const OVERLAP: &Rules = &[(
    "overlap_policy",
    &[
        Rule::Nullable,
        Rule::String,
        Rule::In(super::schedules::OVERLAP_POLICIES),
    ],
)];

async fn control(
    runtime: Runtime,
    schedule: String,
    operation: &str,
    request: Request,
) -> Result<Json<Value>> {
    let (parts, bytes) = request.into_parts();
    let payload = body(bytes).await?;
    let audit = context(&parts, &payload, runtime.namespace(), &schedule, operation);
    // PHP finds the schedule before it validates the body.
    runtime.describe_schedule(&schedule).await?;
    let normalized = normalize(&payload);
    match operation {
        "trigger" => {
            validate(&normalized, OVERLAP)?;
            let overlap = normalized["overlap_policy"].as_str().map(str::to_owned);
            runtime.trigger_schedule(&schedule, overlap, audit).await.map(Json)
        }
        "delete" => runtime
            .edit_schedule(&schedule, operation, None, audit)
            .await
            .map(Json),
        "backfill" => {
            let range = super::schedule_backfill::range(&payload)?;
            runtime.backfill_schedule(&schedule, range, audit).await.map(Json)
        }
        "update" => {
            let changes = super::schedules::changes(&payload)?;
            runtime.update_schedule(&schedule, changes, audit).await.map(Json)
        }
        _ => {
            validate(&normalized, NOTE)?;
            let note = normalized["note"].as_str().map(str::to_owned);
            runtime
                .edit_schedule(&schedule, operation, note, audit)
                .await
                .map(Json)
        }
    }
}

pub(super) async fn pause(
    Extension(runtime): Extension<Runtime>,
    Path(schedule): Path<String>,
    request: Request,
) -> Result<Json<Value>> {
    control(runtime, schedule, "pause", request).await
}
pub(super) async fn resume(
    Extension(runtime): Extension<Runtime>,
    Path(schedule): Path<String>,
    request: Request,
) -> Result<Json<Value>> {
    control(runtime, schedule, "resume", request).await
}
pub(super) async fn trigger(
    Extension(runtime): Extension<Runtime>,
    Path(schedule): Path<String>,
    request: Request,
) -> Result<Json<Value>> {
    control(runtime, schedule, "trigger", request).await
}
/// `POST /api/schedules/{id}/backfill`.
pub(super) async fn backfill(
    Extension(runtime): Extension<Runtime>,
    Path(schedule): Path<String>,
    request: Request,
) -> Result<Json<Value>> {
    control(runtime, schedule, "backfill", request).await
}
/// `PUT /api/schedules/{id}`.
pub(super) async fn update(
    Extension(runtime): Extension<Runtime>,
    Path(schedule): Path<String>,
    request: Request,
) -> Result<Json<Value>> {
    control(runtime, schedule, "update", request).await
}
pub(super) async fn remove(
    Extension(runtime): Extension<Runtime>,
    Path(schedule): Path<String>,
    request: Request,
) -> Result<Json<Value>> {
    control(runtime, schedule, "delete", request).await
}
