//! Native-owned bootstrap of the complete frozen PHP SQLite schema.
//! PHP takeover and the old abbreviated development schema remain refused.
use std::borrow::Cow;

use axum::http::StatusCode;
use sqlx::{SqliteConnection, migrate::{Migration, MigrationType, Migrator}};

use super::{Result, refuse};

pub(super) const VERSION: i64 = 2;

fn migrator() -> Migrator {
    Migrator {
        migrations: Cow::Owned(vec![Migration::new(
            VERSION,
            "full frozen PHP schema and native receipts".into(),
            MigrationType::Simple,
            include_str!("../../migrations/sqlite/0002_full_schema.sql").into(),
            false,
        )]),
        ..Migrator::DEFAULT
    }
}

pub(super) async fn verify(connection: &mut SqliteConnection) -> Result<()> {
    let markers: Vec<(String, i64)> = sqlx::query_as("SELECT engine,version FROM dw_server_schema")
        .fetch_all(&mut *connection).await?;
    if markers != [("rust-development".to_owned(), VERSION)] {
        return Err(refuse(StatusCode::CONFLICT, "unsupported_rust_schema"));
    }
    let applied: Vec<(i64, bool, Vec<u8>)> = sqlx::query_as(
        "SELECT version,success,checksum FROM _sqlx_migrations ORDER BY version"
    ).fetch_all(&mut *connection).await?;
    let migrations = migrator();
    if applied.len() != 1 || applied[0].0 != VERSION || !applied[0].1
        || applied[0].2.as_slice() != migrations.migrations[0].checksum.as_ref() {
        return Err(refuse(StatusCode::CONFLICT, "native_migration_history_mismatch"));
    }
    Ok(())
}

pub(super) async fn bootstrap(connection: &mut SqliteConnection) -> Result<()> {
    // The caller holds BEGIN IMMEDIATE. SQLx's schema and migration ledger
    // are committed with the ownership marker in that one outer transaction.
    // A killed initial bootstrap leaves an empty database, not PHP-shaped
    // tables without ownership or a partially acknowledged migration.
    migrator().run_direct(connection).await?;
    Ok(())
}
