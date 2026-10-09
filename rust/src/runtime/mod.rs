//! First execution slice, deliberately unpublished and opt-in. Signal/query/update
//! arguments use the official codec on a bounded blocking worker.
//! Typed database adapters share transitions; existing PHP data requires qualification.

mod backend;
mod execution;
mod http;
pub mod mysql;
pub mod postgres;
mod queries;
mod schema;
mod sqlite;
mod store;
mod updates;

pub use execution::Runtime;
pub use http::router;

use axum::{
    Json,
    http::StatusCode,
    response::{IntoResponse, Response},
};
use serde_json::{Value, json};

#[derive(Debug, thiserror::Error)]
pub enum RuntimeError {
    #[error("{0}")]
    Database(#[from] sqlx::Error),
    #[error("{0}")]
    Io(#[from] std::io::Error),
    #[error("{0}")]
    Json(#[from] serde_json::Error),
    #[error("native schema migration failed")]
    Migration(#[from] sqlx::migrate::MigrateError),
    #[error("{reason}")]
    Refused {
        status: StatusCode,
        reason: &'static str,
    },
}

impl IntoResponse for RuntimeError {
    fn into_response(self) -> Response {
        let (status, reason) = match self {
            Self::Refused { status, reason } => (status, reason),
            _ => {
                // Database errors can contain stored payloads. Keep responses
                // and logs bounded; never emit SQL bindings or auth values.
                (StatusCode::SERVICE_UNAVAILABLE, "storage_unavailable")
            }
        };
        (status, Json(json!({"reason": reason, "message": reason}))).into_response()
    }
}

type Result<T> = std::result::Result<T, RuntimeError>;

fn refuse(status: StatusCode, reason: &'static str) -> RuntimeError {
    RuntimeError::Refused { status, reason }
}

fn text<'a>(body: &'a Value, field: &str) -> Result<&'a str> {
    body.get(field)
        .and_then(Value::as_str)
        .filter(|s| !s.trim().is_empty() && s.len() <= 255)
        .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_request"))
}

/// This is transport validation only. Strict datum validation, external
/// references and streamed payload budgets remain required ingress gates.
fn envelope(body: &Value, field: &str) -> Result<String> {
    let value = &body[field];
    if value["codec"] != "avro" || value.as_object().is_none_or(|m| m.len() != 2) {
        return Err(refuse(
            StatusCode::UNPROCESSABLE_ENTITY,
            "unsupported_payload_codec",
        ));
    }
    let blob = value["blob"]
        .as_str()
        .filter(|s| !s.is_empty() && s.len() <= 2 * 1024 * 1024)
        .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_payload_envelope"))?;
    Ok(blob.to_owned())
}

fn wire(blob: &str) -> Value {
    json!({"codec": "avro", "blob": blob})
}
