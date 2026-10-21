//! First execution slice, deliberately unpublished and opt-in. Signal/query/update
//! arguments use the official codec on a bounded blocking worker.
//! Typed database adapters share transitions; existing PHP data requires qualification.

mod active_summary;
mod activity_completion_uploads;
mod activity_deadlines;
mod activity_failures;
mod activity_heartbeats;
mod activity_metadata;
mod activity_metadata_read;
mod activity_results;
mod activity_summary;
mod all_groups;
mod backend;
mod cancellation;
mod child_summary;
mod children;
mod continuations;
mod control_plane;
mod cooperative;
mod cross_kind_poll;
mod execution;
mod external_config;
mod external_registry;
mod external_resolution;
mod external_transport;
mod external_wait_summary;
mod failure_commands;
mod history_budget;
mod history_presentation;
mod http;
mod memo_json;
mod memo_limits;
mod memos;
pub mod mysql;
mod namespaces;
mod poll_readiness;
pub mod postgres;
mod previews;
mod queries;
mod query_authority;
mod run_observation;
mod run_observation_store;
mod run_observation_waits;
mod schedule_execution;
mod schedule_http;
mod schedule_storage;
mod schedules;
mod search_attributes;
mod schema;
mod selection_summary;
mod selections;
mod sqlite;
mod store;
mod task_failures;
mod task_repair;
mod task_repair_http;
mod terminal_summary;
mod terminal_updates;
mod timer_summary;
mod transaction;
mod update_summary;
mod updates;
pub mod upgrade;
mod visibility;
mod wait_projection;
mod wait_timeouts;
mod worker_contracts;
mod worker_incarnations;
mod worker_management;
mod workflow_failures;
mod workflow_task_guard;

pub use execution::Runtime;
pub use external_config::ExternalPayloadConfig;
pub use http::router;
pub use query_authority::QueryTaskConfig;

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
    #[error("protocol operation refused")]
    Protocol { status: StatusCode, response: Value },
}

fn diagnostic_database_code(code: &str) -> Option<&str> {
    (!code.is_empty()
        && code.len() <= 5
        && code
            .bytes()
            .all(|byte| byte.is_ascii_uppercase() || byte.is_ascii_digit()))
    .then_some(code)
}

impl RuntimeError {
    fn storage_diagnostic(&self) -> Option<Value> {
        let kind = match self {
            Self::Database(error) => match error {
                sqlx::Error::Database(_) => "database_query",
                sqlx::Error::PoolTimedOut => "pool_timeout",
                sqlx::Error::PoolClosed => "pool_closed",
                sqlx::Error::RowNotFound => "row_not_found",
                sqlx::Error::ColumnNotFound(_) => "column_not_found",
                sqlx::Error::ColumnDecode { .. } => "column_decode",
                sqlx::Error::Io(_) => "database_io",
                sqlx::Error::Tls(_) => "database_tls",
                sqlx::Error::Protocol(_) => "database_protocol",
                _ => "database_other",
            },
            Self::Io(_) => "io",
            Self::Json(_) => "json",
            Self::Migration(_) => "migration",
            Self::Refused { .. } | Self::Protocol { .. } => return None,
        };
        let (code, number) = match self {
            Self::Database(sqlx::Error::Database(error)) => (
                error
                    .code()
                    .and_then(|code| diagnostic_database_code(&code).map(str::to_owned)),
                error
                    .try_downcast_ref::<sqlx::mysql::MySqlDatabaseError>()
                    .map(|error| error.number()),
            ),
            _ => (None, None),
        };
        Some(
            json!({"event":"runtime_storage_unavailable","error_kind":kind,
            "database_code":code,"driver_error_number":number,
            "transient_reason":worker_incarnations::transient_reason(self)}),
        )
    }
}

impl IntoResponse for RuntimeError {
    fn into_response(self) -> Response {
        if let Some(diagnostic) = self.storage_diagnostic() {
            // Only bounded driver codes and static classifications, never the
            // error's Display/Debug text, payloads, SQL bindings or auth data.
            eprintln!("{diagnostic}");
        }
        let (status, reason) = match self {
            Self::Protocol { status, response } => return (status, Json(response)).into_response(),
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

#[cfg(test)]
mod error_diagnostic_tests {
    use super::*;
    use sqlx::Connection;

    #[test]
    fn diagnostic_codes_accept_only_bounded_driver_codes() {
        for code in ["HY000", "40001", "23000", "1205", "1555"] {
            assert_eq!(diagnostic_database_code(code), Some(code));
        }
        for code in [
            "",
            "123456",
            "private",
            "SQL payload",
            "123\n",
            "token:value",
        ] {
            assert_eq!(diagnostic_database_code(code), None);
        }
    }

    #[test]
    fn diagnostics_never_render_error_text_or_column_contents() {
        let marker = "SYNTHETIC_PRIVATE_PAYLOAD_AUTH_VALUE";
        for error in [
            RuntimeError::Database(sqlx::Error::Protocol(marker.into())),
            RuntimeError::Database(sqlx::Error::ColumnNotFound(marker.into())),
            RuntimeError::Database(sqlx::Error::ColumnDecode {
                index: marker.into(),
                source: Box::new(std::io::Error::other(marker)),
            }),
            RuntimeError::Io(std::io::Error::other(marker)),
        ] {
            let diagnostic = error.storage_diagnostic().unwrap();
            assert_eq!(diagnostic.as_object().unwrap().len(), 5);
            assert!(!diagnostic.to_string().contains(marker));
            assert_eq!(diagnostic["database_code"], Value::Null);
            assert_eq!(diagnostic["driver_error_number"], Value::Null);
        }
        let timeout = RuntimeError::Database(sqlx::Error::PoolTimedOut)
            .storage_diagnostic()
            .unwrap();
        assert_eq!(timeout["error_kind"], "pool_timeout");
        assert_eq!(timeout["transient_reason"], "backend_unavailable");
    }

    #[test]
    fn ordinary_protocol_refusals_emit_no_storage_diagnostic() {
        assert!(
            refuse(StatusCode::CONFLICT, "original_refusal")
                .storage_diagnostic()
                .is_none()
        );
        assert!(
            RuntimeError::Protocol {
                status: StatusCode::UNPROCESSABLE_ENTITY,
                response: json!({"reason":"original_protocol_refusal"})
            }
            .storage_diagnostic()
            .is_none()
        );
    }

    #[tokio::test]
    async fn real_database_constraint_is_classified_without_query_or_payload() {
        let mut connection = sqlx::SqliteConnection::connect("sqlite::memory:")
            .await
            .unwrap();
        sqlx::query(
            "CREATE TABLE diagnostic_private_relation (id INTEGER PRIMARY KEY, payload TEXT)",
        )
        .execute(&mut connection)
        .await
        .unwrap();
        let insert = "INSERT INTO diagnostic_private_relation VALUES (1, $1)";
        sqlx::query(insert)
            .bind("SYNTHETIC_PRIVATE_PAYLOAD_AUTH_VALUE")
            .execute(&mut connection)
            .await
            .unwrap();
        let error = sqlx::query(insert)
            .bind("SYNTHETIC_PRIVATE_PAYLOAD_AUTH_VALUE")
            .execute(&mut connection)
            .await
            .unwrap_err();
        let diagnostic = RuntimeError::Database(error).storage_diagnostic().unwrap();
        assert_eq!(diagnostic["error_kind"], "database_query");
        assert!(
            diagnostic["database_code"]
                .as_str()
                .is_some_and(|code| code.parse::<u32>().is_ok())
        );
        assert!(
            !diagnostic
                .to_string()
                .contains("diagnostic_private_relation")
        );
        assert!(
            !diagnostic
                .to_string()
                .contains("SYNTHETIC_PRIVATE_PAYLOAD_AUTH_VALUE")
        );
    }

    #[tokio::test]
    async fn storage_http_status_and_body_remain_redacted() {
        let response = RuntimeError::Database(sqlx::Error::Protocol(
            "SYNTHETIC_PRIVATE_PAYLOAD_AUTH_VALUE".into(),
        ))
        .into_response();
        assert_eq!(response.status(), StatusCode::SERVICE_UNAVAILABLE);
        let body = axum::body::to_bytes(response.into_body(), 1024)
            .await
            .unwrap();
        assert_eq!(
            serde_json::from_slice::<Value>(&body).unwrap(),
            json!({"reason":"storage_unavailable","message":"storage_unavailable"})
        );
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
        .filter(|s| {
            !s.is_empty()
                && s.len() <= 2 * 1024 * 1024
                && !s.starts_with(external_resolution::STORED_PREFIX)
        })
        .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_payload_envelope"))?;
    Ok(blob.to_owned())
}

fn wire(blob: &str) -> Value {
    if let Some(envelope) = external_resolution::stored_envelope(blob) {
        return envelope;
    }
    json!({"codec": "avro", "blob": blob})
}
