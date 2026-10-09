//! SQLite ownership preflight and atomic native bootstrap.
use super::{Result, refuse, schema};
use axum::http::StatusCode;
use sqlx::{
    Connection, SqliteConnection, SqlitePool,
    sqlite::{SqliteConnectOptions, SqliteJournalMode, SqlitePoolOptions, SqliteSynchronous},
};
use std::{path::Path, time::Duration};

pub(super) async fn open(database: &str) -> Result<SqlitePool> {
    // WAL setup and read-only inspection can race another node's startup.
    // SQLite can report BUSY immediately even with a busy handler. Retry
    // only startup BUSY, at most twice; every attempt repeats ownership
    // preflight and keeps the existing five-second lock/acquisition limits.
    for attempt in 0..3 {
        let result = open_once(database).await;
        let busy = matches!(&result,
            Err(super::RuntimeError::Database(sqlx::Error::Database(error)))
            if error.code().is_some_and(|code| code.parse::<u32>().is_ok_and(|code| code & 0xff == 5))
        );
        if !busy || attempt == 2 {
            return result;
        }
        tokio::time::sleep(Duration::from_millis(50)).await;
    }
    unreachable!("the final startup attempt always returns")
}

async fn open_once(database: &str) -> Result<SqlitePool> {
    if database.is_empty() || database.contains('?') || database.contains(':') {
        return Err(refuse(
            StatusCode::BAD_REQUEST,
            "invalid_development_configuration",
        ));
    }
    let path = Path::new(database);
    if !path.exists()
        && let Err(error) = std::fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .open(path)
        && error.kind() != std::io::ErrorKind::AlreadyExists
    {
        return Err(error.into());
    }
    // Preflight read-only, before enabling WAL or executing any migration.
    // A PHP/unknown database cannot be mutated by this development slice.
    let mut preflight = SqliteConnection::connect_with(
        &SqliteConnectOptions::new()
            .filename(path)
            .read_only(true)
            .foreign_keys(false),
    )
    .await?;
    let preflight_result = async {
        let tables: Vec<String> = sqlx::query_scalar(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'",
        )
        .fetch_all(&mut preflight)
        .await?;
        let initialized = tables.iter().any(|name| name == "dw_server_schema");
        if initialized {
            schema::verify(&mut preflight).await?;
        } else if !tables.is_empty() {
            return Err(refuse(
                StatusCode::CONFLICT,
                "existing_database_requires_qualified_takeover",
            ));
        }
        Ok::<_, super::RuntimeError>(initialized)
    }
    .await;
    // Await SQLite worker shutdown on refusal as well as success. An
    // error return must not abandon the preflight connection until exit.
    preflight.close().await?;
    let initialized = preflight_result?;
    let pool = SqlitePoolOptions::new()
        .max_connections(4)
        .acquire_timeout(Duration::from_secs(5))
        .connect_with(
            SqliteConnectOptions::new()
                .filename(path)
                .create_if_missing(false)
                .journal_mode(SqliteJournalMode::Wal)
                .synchronous(SqliteSynchronous::Full)
                .foreign_keys(true)
                .busy_timeout(Duration::from_secs(5)),
        )
        .await?;
    let bootstrap = async {
        if initialized {
            return Ok::<_, super::RuntimeError>(());
        }
        let mut tx = pool.begin_with("BEGIN IMMEDIATE").await?;
        // Recheck under the write lock so simultaneous fresh-node startup
        // cannot apply the same bootstrap twice.
        let exists: i64 = sqlx::query_scalar(
            "SELECT COUNT(*) FROM sqlite_master WHERE name='dw_server_schema' COLLATE NOCASE",
        )
        .fetch_one(&mut *tx)
        .await?;
        if exists == 0 {
            schema::bootstrap(&mut tx).await?;
        } else {
            schema::verify(&mut tx).await?;
        }
        tx.commit().await?;
        Ok(())
    }
    .await;
    if let Err(error) = bootstrap {
        // Finish transaction rollback and worker shutdown before retrying.
        // Refusal and non-BUSY errors close the pool too.
        pool.close().await;
        return Err(error);
    }
    Ok(pool)
}
