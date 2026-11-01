//! In-place upgrades between native schema versions (Server #325).
//!
//! A native database records one SQLx migration, the cumulative bootstrap of
//! its schema version, and an ownership marker naming that version. The
//! `schema-upgrade` command moves a database from an earlier native version
//! to this binary's: it checks the earlier version exactly (marker, recorded
//! migration checksum and catalog), runs each later step file in order, then
//! records this version's cumulative migration in place of the earlier one.
//! The result is verified exactly as a fresh bootstrap is.
//!
//! Startup never upgrades. A server started on an earlier version refuses
//! with `native_schema_upgrade_required`, and on a later one with
//! `native_schema_newer_than_binary`, so an older binary cannot write to a
//! schema it does not know.
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{
    Connection, SqliteConnection, mysql::MySqlConnectOptions, postgres::PgConnectOptions,
    sqlite::{SqliteConnectOptions, SqliteJournalMode, SqliteSynchronous},
};
use std::{path::Path, time::Duration};

use super::{Result, RuntimeError, refuse};

/// The ownership marker engine of a native database in service.
pub(super) const DEVELOPMENT_ENGINE: &str = "rust-development";
/// The marker engine while a MySQL or MariaDB upgrade is under way. DDL
/// commits on its own there, so the marker fences the database until the
/// upgrade finishes; a rerun of `schema-upgrade` resumes it.
pub(super) const UPGRADING_ENGINE: &str = "rust-upgrading";
/// Earlier native schema versions this binary upgrades in place. Release
/// candidates `3.0.0-rc.1` and `3.0.0-rc.2` shipped version 7.
pub(super) const UPGRADABLE: &[i64] = &[7];

/// The refusal for a native marker this binary does not open as it is.
pub(super) fn marker_refusal(engine: &str, version: i64, current: i64) -> RuntimeError {
    let reason = if engine == UPGRADING_ENGINE {
        "native_schema_upgrade_incomplete"
    } else if engine != DEVELOPMENT_ENGINE {
        "unsupported_rust_schema"
    } else if version > current {
        "native_schema_newer_than_binary"
    } else if version < current && UPGRADABLE.contains(&version) {
        "native_schema_upgrade_required"
    } else {
        "unsupported_rust_schema"
    };
    refuse(StatusCode::CONFLICT, reason)
}

fn report(backend: &str, from: i64, to: i64) -> Value {
    json!({
        "backend": backend,
        "from_schema_version": from,
        "schema_version": to,
        "status": if from == to { "current" } else { "upgraded" },
    })
}

/// Upgrade a native SQLite database in one `BEGIN IMMEDIATE` transaction.
/// The database must already exist and be native-owned.
pub async fn upgrade_sqlite(database: &str) -> Result<Value> {
    if database.is_empty() || database.contains('?') || database.contains(':') {
        return Err(refuse(
            StatusCode::BAD_REQUEST,
            "invalid_development_configuration",
        ));
    }
    if !Path::new(database).exists() {
        return Err(refuse(StatusCode::CONFLICT, "native_schema_not_initialized"));
    }
    let mut connection = SqliteConnection::connect_with(
        &SqliteConnectOptions::new()
            .filename(database)
            .create_if_missing(false)
            .journal_mode(SqliteJournalMode::Wal)
            .synchronous(SqliteSynchronous::Full)
            .foreign_keys(true)
            .busy_timeout(Duration::from_secs(5)),
    )
    .await?;
    let result = async {
        let mut tx = connection.begin_with("BEGIN IMMEDIATE").await?;
        let owned: bool = sqlx::query_scalar(
            "SELECT EXISTS(SELECT 1 FROM sqlite_schema WHERE type='table' AND name='dw_server_schema')",
        )
        .fetch_one(&mut *tx)
        .await?;
        if !owned {
            return Err(refuse(StatusCode::CONFLICT, "native_schema_not_initialized"));
        }
        let from = super::schema::upgrade(&mut tx).await?;
        tx.commit().await?;
        Ok::<_, RuntimeError>(from)
    }
    .await;
    connection.close().await?;
    Ok(report("sqlite", result?, super::schema::VERSION))
}

/// Upgrade a native PostgreSQL database in one transaction, under the
/// bootstrap advisory lock.
pub async fn upgrade_postgres(options: PgConnectOptions) -> Result<Value> {
    let from = super::postgres::PostgresStorage::upgrade(options).await?;
    Ok(report("pgsql", from, super::postgres::VERSION))
}

/// Upgrade a native MySQL or MariaDB database under the bootstrap named
/// lock. An interrupted upgrade resumes when the command is run again.
pub async fn upgrade_mysql(options: MySqlConnectOptions) -> Result<Value> {
    let (backend, from) = super::mysql::upgrade(options).await?;
    Ok(report(backend, from, super::mysql::VERSION))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn reason(error: RuntimeError) -> &'static str {
        match error {
            RuntimeError::Refused { reason, .. } => reason,
            other => panic!("unexpected error {other}"),
        }
    }

    #[test]
    fn markers_refuse_with_the_reason_that_names_the_next_step() {
        assert_eq!(reason(marker_refusal(DEVELOPMENT_ENGINE, 7, 8)), "native_schema_upgrade_required");
        assert_eq!(reason(marker_refusal(DEVELOPMENT_ENGINE, 9, 8)), "native_schema_newer_than_binary");
        assert_eq!(reason(marker_refusal(DEVELOPMENT_ENGINE, 99, 8)), "native_schema_newer_than_binary");
        assert_eq!(reason(marker_refusal(DEVELOPMENT_ENGINE, 2, 8)), "unsupported_rust_schema");
        assert_eq!(reason(marker_refusal(UPGRADING_ENGINE, 7, 8)), "native_schema_upgrade_incomplete");
        assert_eq!(reason(marker_refusal("rust-initializing", 8, 8)), "unsupported_rust_schema");
        assert_eq!(reason(marker_refusal("php", 7, 8)), "unsupported_rust_schema");
    }
}
