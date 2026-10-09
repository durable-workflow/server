//! Native-owned bootstrap of the complete frozen PHP SQLite schema.
//! PHP takeover and the old abbreviated development schema remain refused.
use axum::http::StatusCode;
use sqlx::{
    Connection, SqlSafeStr, SqliteConnection,
    migrate::{Migration, MigrationType, Migrator},
    sqlite::SqliteConnectOptions,
};
use std::sync::OnceLock;
use tokio::sync::OnceCell;

use super::{Result, refuse};

pub(super) const VERSION: i64 = 3;

fn migrator() -> &'static Migrator {
    static MIGRATOR: OnceLock<Migrator> = OnceLock::new();
    MIGRATOR.get_or_init(|| {
        Migrator::with_migrations(vec![Migration::new(
            VERSION,
            "full frozen PHP schema and native receipts".into(),
            MigrationType::Simple,
            include_str!("../../migrations/sqlite/0003_full_schema.sql").into_sql_str(),
            false,
        )])
    })
}

type Catalog = Vec<(String, String, String, String)>;
static EXPECTED: OnceCell<Catalog> = OnceCell::const_new();

async fn catalog(connection: &mut SqliteConnection) -> Result<Catalog> {
    Ok(sqlx::query_as("SELECT type,name,tbl_name,sql FROM sqlite_schema WHERE sql IS NOT NULL AND name NOT GLOB 'sqlite_*' ORDER BY type,name")
        .fetch_all(connection).await?)
}

async fn verify_catalog(connection: &mut SqliteConnection) -> Result<()> {
    let expected = EXPECTED
        .get_or_try_init(|| async {
            // SQLite itself interprets the frozen DDL; do not invent a SQL parser.
            // This isolated in-memory catalog is built once per process on SQLx's
            // connection worker, away from the Tokio request executor.
            let mut reference =
                SqliteConnection::connect_with(&SqliteConnectOptions::new().in_memory(true))
                    .await?;
            migrator().run(&mut reference).await?;
            let rows = catalog(&mut reference).await?;
            reference.close().await?;
            Ok::<_, super::RuntimeError>(rows)
        })
        .await?;
    if &catalog(connection).await? != expected {
        return Err(refuse(
            StatusCode::CONFLICT,
            "native_schema_catalog_mismatch",
        ));
    }
    Ok(())
}

pub(super) async fn verify(connection: &mut SqliteConnection) -> Result<()> {
    let markers: Vec<(String, i64)> = sqlx::query_as("SELECT engine,version FROM dw_server_schema")
        .fetch_all(&mut *connection)
        .await?;
    if markers != [("rust-development".to_owned(), VERSION)] {
        return Err(refuse(StatusCode::CONFLICT, "unsupported_rust_schema"));
    }
    verify_history(connection).await?;
    verify_catalog(connection).await?;
    Ok(())
}

pub(super) async fn verify_history(connection: &mut SqliteConnection) -> Result<()> {
    let applied: Vec<(i64, bool, Vec<u8>)> =
        sqlx::query_as("SELECT version,success,checksum FROM _sqlx_migrations ORDER BY version")
            .fetch_all(&mut *connection)
            .await?;
    let migrations = migrator();
    if applied.len() != 1
        || applied[0].0 != VERSION
        || !applied[0].1
        || applied[0].2.as_slice() != migrations.iter().next().unwrap().checksum.as_ref()
    {
        return Err(refuse(
            StatusCode::CONFLICT,
            "native_migration_history_mismatch",
        ));
    }
    Ok(())
}

pub(super) async fn bootstrap(connection: &mut SqliteConnection) -> Result<()> {
    // The caller holds BEGIN IMMEDIATE. SQLx's schema and migration ledger
    // are committed with the ownership marker in that one outer transaction.
    // A killed initial bootstrap leaves an empty database, not PHP-shaped
    // tables without ownership or a partially acknowledged migration.
    migrator().run(connection).await?;
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use sqlx::{migrate::Migrate, sqlite::SqliteJournalMode};
    use std::{path::Path, process::Command, time::Duration};

    #[tokio::test]
    #[ignore = "subprocess used by interrupted_bootstrap_leaves_no_partial_schema"]
    async fn bootstrap_child() {
        let path = std::env::var("DW_SCHEMA_TEST_DATABASE").unwrap();
        let phase = std::env::var("DW_SCHEMA_TEST_PHASE").unwrap();
        let mut connection = SqliteConnection::connect_with(
            &SqliteConnectOptions::new()
                .filename(&path)
                .create_if_missing(true)
                .journal_mode(SqliteJournalMode::Wal)
                .pragma("cache_size", "-64"),
        )
        .await
        .unwrap();
        let mut tx = connection.begin_with("BEGIN IMMEDIATE").await.unwrap();
        if phase == "ledger" {
            tx.ensure_migrations_table("_sqlx_migrations")
                .await
                .unwrap();
        } else {
            bootstrap(&mut tx).await.unwrap();
            let rows: i64 = sqlx::query_scalar(
                "SELECT COUNT(*) FROM _sqlx_migrations WHERE version=3 AND success=1",
            )
            .fetch_one(&mut *tx)
            .await
            .unwrap();
            assert_eq!(rows, 1);
            verify(&mut tx).await.unwrap();
        }
        // This receipt acknowledges an actual SQLite boundary inside the
        // still-open outer transaction. The parent sends a real SIGKILL.
        std::fs::write(format!("{path}.boundary"), phase).unwrap();
        loop {
            std::thread::park();
        }
    }

    #[cfg(unix)]
    #[tokio::test]
    async fn interrupted_bootstrap_leaves_no_partial_schema() {
        for phase in ["ledger", "before-commit"] {
            let directory = tempfile::tempdir().unwrap();
            let path = directory.path().join("runtime.sqlite");
            let receipt = path.with_extension("sqlite.boundary");
            let mut child = Command::new(std::env::current_exe().unwrap())
                .args([
                    "--exact",
                    "runtime::schema::tests::bootstrap_child",
                    "--ignored",
                    "--nocapture",
                ])
                .env("DW_SCHEMA_TEST_DATABASE", &path)
                .env("DW_SCHEMA_TEST_PHASE", phase)
                .spawn()
                .unwrap();
            let deadline = tokio::time::Instant::now() + Duration::from_secs(10);
            while !receipt.exists() && tokio::time::Instant::now() < deadline {
                if let Some(status) = child.try_wait().unwrap() {
                    panic!("child exited before {phase}: {status}");
                }
                tokio::time::sleep(Duration::from_millis(10)).await;
            }
            if !receipt.exists() {
                child.kill().unwrap();
                child.wait().unwrap();
                panic!("child did not reach {phase}");
            }
            assert_eq!(std::fs::read_to_string(&receipt).unwrap(), phase);
            if phase == "before-commit" {
                assert!(
                    std::fs::metadata(path.with_extension("sqlite-wal"))
                        .unwrap()
                        .len()
                        > 32,
                    "dirty DDL pages must actually spill to WAL before the kill"
                );
            }
            child.kill().unwrap();
            use std::os::unix::process::ExitStatusExt;
            assert_eq!(child.wait().unwrap().signal(), Some(9));
            let mut read_only = SqliteConnection::connect_with(
                &SqliteConnectOptions::new().filename(&path).read_only(true),
            )
            .await
            .unwrap();
            let visible: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM sqlite_schema WHERE type='table' AND name NOT LIKE 'sqlite_%'")
                .fetch_one(&mut read_only).await.unwrap();
            assert_eq!(visible, 0, "partial schema survived {phase}");
            read_only.close().await.unwrap();
            let runtime = super::super::Runtime::open(path.to_str().unwrap(), "test-token".into())
                .await
                .unwrap();
            let mut connection = runtime.sqlite_pool().acquire().await.unwrap();
            verify(&mut connection).await.unwrap();
            let integrity: String = sqlx::query_scalar("PRAGMA integrity_check")
                .fetch_one(&mut *connection)
                .await
                .unwrap();
            assert_eq!(integrity, "ok");
            drop(connection);
            runtime.close().await;
        }
    }

    #[tokio::test]
    async fn concurrent_fresh_nodes_share_one_complete_migration() {
        // Exercise fresh-file journal setup repeatedly; a single successful
        // group does not expose the observed startup BUSY race reliably.
        for _ in 0..16 {
            let directory = tempfile::tempdir().unwrap();
            let path = directory.path().join("runtime.sqlite");
            let path = path.to_str().unwrap();
            let (a, b, c) = tokio::join!(
                super::super::Runtime::open(path, "test-token".into()),
                super::super::Runtime::open(path, "test-token".into()),
                super::super::Runtime::open(path, "test-token".into()),
            );
            for result in [a, b, c] {
                let runtime = result.unwrap();
                assert!(runtime.schema_ready().await);
                let rows: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM _sqlx_migrations")
                    .fetch_one(runtime.sqlite_pool())
                    .await
                    .unwrap();
                assert_eq!(rows, 1);
                runtime.close().await;
            }
        }
    }

    async fn assert_read_only_refusal(path: &Path) {
        let before = std::fs::read(path).unwrap();
        assert!(
            super::super::Runtime::open(path.to_str().unwrap(), "test-token".into())
                .await
                .is_err()
        );
        assert_eq!(std::fs::read(path).unwrap(), before);
        assert!(!path.with_extension("sqlite-wal").exists());
        assert!(!path.with_extension("sqlite-shm").exists());
    }

    #[tokio::test]
    async fn user_objects_are_refused_before_journal_or_schema_mutation() {
        // LIKE's underscore wildcard must not hide names such as sqlitex_*.
        for statement in [
            "CREATE VIEW acknowledged_view AS SELECT 'preserve-this' AS value",
            "CREATE VIEW sqlitex_customer_view AS SELECT 'preserve-this' AS value",
            "CREATE TABLE sqlitex_customer_data(id TEXT); INSERT INTO sqlitex_customer_data VALUES ('preserve-this')",
        ] {
            let directory = tempfile::tempdir().unwrap();
            let path = directory.path().join("runtime.sqlite");
            let mut connection = SqliteConnection::connect_with(
                &SqliteConnectOptions::new()
                    .filename(&path)
                    .create_if_missing(true),
            )
            .await
            .unwrap();
            sqlx::raw_sql(statement)
                .execute(&mut connection)
                .await
                .unwrap();
            connection.close().await.unwrap();
            assert_read_only_refusal(&path).await;
        }
    }

    #[tokio::test]
    async fn old_development_schema_is_refused_without_conversion() {
        for version in [1, 2] {
            let directory = tempfile::tempdir().unwrap();
            let path = directory.path().join("runtime.sqlite");
            let mut connection = SqliteConnection::connect_with(
                &SqliteConnectOptions::new()
                    .filename(&path)
                    .create_if_missing(true),
            )
            .await
            .unwrap();
            sqlx::raw_sql("CREATE TABLE dw_server_schema(engine TEXT,version INTEGER); CREATE TABLE acknowledged(id TEXT PRIMARY KEY); INSERT INTO acknowledged VALUES ('keep-this');")
            .execute(&mut connection).await.unwrap();
            sqlx::query("INSERT INTO dw_server_schema VALUES ('rust-development',$1)")
                .bind(version)
                .execute(&mut connection)
                .await
                .unwrap();
            connection.close().await.unwrap();
            assert_read_only_refusal(&path).await;
        }
    }

    #[tokio::test]
    async fn php_wal_refusal_preserves_database_and_committed_frames() {
        for keep_writer in [false, true] {
            let directory = tempfile::tempdir().unwrap();
            let path = directory.path().join("php.sqlite");
            let mut connection = SqliteConnection::connect_with(
                &SqliteConnectOptions::new()
                    .filename(&path)
                    .create_if_missing(true)
                    .journal_mode(SqliteJournalMode::Wal),
            )
            .await
            .unwrap();
            sqlx::raw_sql("CREATE TABLE acknowledged(id TEXT PRIMARY KEY,status TEXT); INSERT INTO acknowledged VALUES ('original-id','pending');")
                .execute(&mut connection).await.unwrap();
            let mut writer = Some(connection);
            if !keep_writer {
                writer.take().unwrap().close().await.unwrap();
            }
            let database_before = std::fs::read(&path).unwrap();
            let wal_path = path.with_extension("sqlite-wal");
            let wal_before = std::fs::read(&wal_path).unwrap_or_default();
            assert_eq!(!wal_before.is_empty(), keep_writer);
            let refused =
                super::super::Runtime::open(path.to_str().unwrap(), "test-token".into()).await;
            assert!(matches!(
                refused,
                Err(super::super::RuntimeError::Refused {
                    reason: "existing_database_requires_qualified_takeover",
                    ..
                })
            ));
            assert_eq!(std::fs::read(&path).unwrap(), database_before);
            assert_eq!(
                std::fs::read(&wal_path).unwrap_or_default(),
                wal_before,
                "only an absent/zero-byte temporary WAL is equivalent"
            );
            if let Some(writer) = writer {
                writer.close().await.unwrap();
            }
            let mut ordinary =
                SqliteConnection::connect_with(&SqliteConnectOptions::new().filename(&path))
                    .await
                    .unwrap();
            let row: (String, String) = sqlx::query_as("SELECT id,status FROM acknowledged")
                .fetch_one(&mut ordinary)
                .await
                .unwrap();
            assert_eq!(row, ("original-id".to_owned(), "pending".to_owned()));
            ordinary.close().await.unwrap();
        }
    }

    #[tokio::test]
    async fn altered_catalog_or_migration_history_cannot_enable_writes() {
        for mutation in [
            "UPDATE _sqlx_migrations SET checksum=X'00'",
            "UPDATE _sqlx_migrations SET success=0",
            "UPDATE _sqlx_migrations SET version=99",
            "DELETE FROM _sqlx_migrations",
            "DROP TABLE runtime_external_payload_backup_hold",
            "DROP INDEX workflow_runs_workflow_instance_id_run_number_unique",
            "ALTER TABLE workflow_runs ADD COLUMN unexpected TEXT",
            "UPDATE dw_server_schema SET version=99",
            "ALTER TABLE _sqlx_migrations ADD COLUMN unexpected TEXT",
        ] {
            let directory = tempfile::tempdir().unwrap();
            let path = directory.path().join("runtime.sqlite");
            let runtime = super::super::Runtime::open(path.to_str().unwrap(), "test-token".into())
                .await
                .unwrap();
            runtime.close().await;
            let mut connection = SqliteConnection::connect_with(
                &SqliteConnectOptions::new()
                    .filename(&path)
                    .journal_mode(SqliteJournalMode::Delete),
            )
            .await
            .unwrap();
            sqlx::raw_sql(mutation)
                .execute(&mut connection)
                .await
                .unwrap();
            connection.close().await.unwrap();
            assert_read_only_refusal(&path).await;
        }
    }
}
