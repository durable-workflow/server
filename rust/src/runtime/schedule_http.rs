//! Schedule HTTP controls and audit provenance derived after the shared guard.
use super::{Result, Runtime, command_context::CommandRequest, refuse};
use super::laravel_rules::{Rule, Rules, normalize, validate};
use axum::{
    Extension, Json,
    body::{Body, to_bytes},
    extract::{Path, Query, Request},
    http::{StatusCode, request::Parts},
};
use serde_json::{Value, json};

fn context(request: &Parts, payload: &Value, schedule: &str, operation: &str) -> Result<Value> {
    context_for(request, payload, schedule, operation, operation)
}

/// The command context of `command` for a request routed as `route`.
fn context_for(request: &Parts, payload: &Value, schedule: &str, route: &str, command: &str) -> Result<Value> {
    let context = CommandRequest::from_parts(request)?.routed_context(
        payload,
        schedule,
        &format!("schedule.{command}"),
        json!([]),
        Some(&format!("api.schedules.{route}")),
    );
    Ok(json!({"source":"control_plane","context":context}))
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
    // The audit names the caller's schedule id, or PHP's generated one.
    let target = payload["schedule_id"]
        .as_str()
        .map(str::trim)
        .filter(|id| !id.is_empty())
        .map_or_else(|| ulid::Ulid::new().to_string(), str::to_owned);
    let created = context(&parts, &payload, &target, "create")?;
    let paused = context_for(&parts, &payload, &target, "create", "pause")?;
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
    let audit = context(&parts, &payload, &schedule, operation)?;
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
