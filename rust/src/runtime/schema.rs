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

pub(super) const VERSION: i64 = 8;

fn migrator() -> &'static Migrator {
    static MIGRATOR: OnceLock<Migrator> = OnceLock::new();
    MIGRATOR.get_or_init(|| {
        Migrator::with_migrations(vec![Migration::new(
            VERSION,
            "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions".into(),
            MigrationType::Simple,
            concat!(
                include_str!("../../migrations/sqlite/0003_full_schema.sql"),
                "\n",
                include_str!("../../migrations/sqlite/0004_worker_incarnations.sql"),
                "\n",
                include_str!("../../migrations/sqlite/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/sqlite/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/sqlite/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/sqlite/0008_external_payload_deletions.sql")
            )
            .into_sql_str(),
            false,
        )])
    })
}

/// The cumulative bootstrap an earlier native version recorded, byte for
/// byte, so `schema-upgrade` recognises that version by its checksum. The
/// step files themselves never change once released.
fn earlier_migration(version: i64) -> Option<&'static Migration> {
    static V7: OnceLock<Migration> = OnceLock::new();
    match version {
        7 => Some(V7.get_or_init(|| {
            Migration::new(
                7,
                "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines and service admission counters".into(),
                MigrationType::Simple,
                concat!(
                    include_str!("../../migrations/sqlite/0003_full_schema.sql"),
                    "\n",
                    include_str!("../../migrations/sqlite/0004_worker_incarnations.sql"),
                    "\n",
                    include_str!("../../migrations/sqlite/0005_worker_session_holders.sql"),
                    "\n",
                    include_str!("../../migrations/sqlite/0006_stream_reclaims.sql"),
                    "\n",
                    include_str!("../../migrations/sqlite/0007_service_calls.sql")
                )
                .into_sql_str(),
                false,
            )
        })),
        _ => None,
    }
}

/// The step file that moves a native database from `version` to the next.
/// Each ends by moving the ownership marker to its own version.
fn step(version: i64) -> Option<&'static str> {
    match version {
        7 => Some(include_str!("../../migrations/sqlite/0008_external_payload_deletions.sql")),
        _ => None,
    }
}

async fn earlier_catalog(migration: &'static Migration) -> Result<Catalog> {
    let mut reference =
        SqliteConnection::connect_with(&SqliteConnectOptions::new().in_memory(true)).await?;
    let result = async {
        Migrator::with_migrations(vec![migration.clone()])
            .run(&mut reference)
            .await?;
        catalog(&mut reference).await
    }
    .await;
    reference.close().await?;
    result
}

/// Move a native database from an earlier version to this one. The caller
/// holds `BEGIN IMMEDIATE`, so the steps, the recorded migration and the
/// marker commit together or not at all. Returns the version found.
pub(super) async fn upgrade(connection: &mut SqliteConnection) -> Result<i64> {
    use super::schema_upgrade::{DEVELOPMENT_ENGINE, marker_refusal};
    let markers: Vec<(String, i64)> = sqlx::query_as("SELECT engine,version FROM dw_server_schema")
        .fetch_all(&mut *connection)
        .await?;
    let [(engine, from)] = markers.as_slice() else {
        return Err(refuse(StatusCode::CONFLICT, "unsupported_rust_schema"));
    };
    let from = *from;
    if engine == DEVELOPMENT_ENGINE && from == VERSION {
        verify(connection).await?;
        return Ok(from);
    }
    let Some(earlier) = earlier_migration(from).filter(|_| engine == DEVELOPMENT_ENGINE) else {
        return Err(marker_refusal(engine, from, VERSION));
    };
    let applied: Vec<(i64, bool, Vec<u8>)> =
        sqlx::query_as("SELECT version,success,checksum FROM _sqlx_migrations ORDER BY version")
            .fetch_all(&mut *connection)
            .await?;
    if applied != [(from, true, earlier.checksum.to_vec())] {
        return Err(refuse(
            StatusCode::CONFLICT,
            "native_migration_history_mismatch",
        ));
    }
    if catalog(connection).await? != earlier_catalog(earlier).await? {
        return Err(refuse(
            StatusCode::CONFLICT,
            "native_schema_catalog_mismatch",
        ));
    }
    for version in from..VERSION {
        let sql = step(version).ok_or(refuse(StatusCode::CONFLICT, "unsupported_rust_schema"))?;
        sqlx::raw_sql(sql).execute(&mut *connection).await?;
    }
    let migration = self::migration();
    sqlx::query("DELETE FROM _sqlx_migrations WHERE version=$1")
        .bind(from)
        .execute(&mut *connection)
        .await?;
    sqlx::query("INSERT INTO _sqlx_migrations(version,description,success,checksum,execution_time) VALUES ($1,$2,TRUE,$3,0)")
        .bind(migration.version)
        .bind(migration.description.as_ref())
        .bind(migration.checksum.as_ref())
        .execute(&mut *connection)
        .await?;
    verify(connection).await?;
    Ok(from)
}

/// The native relations, indexes and ownership marker that a fresh
/// bootstrap adds after the frozen PHP layout. Takeover applies them to a
/// converted PHP database instead of running the whole migration.
pub(super) fn native_relations_sql() -> Result<String> {
    super::upgrade::native_relations(
        include_str!("../../migrations/sqlite/0003_full_schema.sql"),
        "CREATE TABLE dw_server_schema ",
        concat!(
            include_str!("../../migrations/sqlite/0004_worker_incarnations.sql"),
            "\n",
            include_str!("../../migrations/sqlite/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/sqlite/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/sqlite/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/sqlite/0008_external_payload_deletions.sql")
        ),
    )
}

/// The single native migration, which a takeover records as applied once
/// conversion has produced its schema.
pub(super) fn migration() -> &'static Migration {
    migrator().iter().next().expect("one native SQLite migration")
}

type Catalog = Vec<(String, String, String, String)>;
static EXPECTED: OnceCell<Catalog> = OnceCell::const_new();

async fn catalog(connection: &mut SqliteConnection) -> Result<Catalog> {
    Ok(sqlx::query_as("SELECT type,name,tbl_name,sql FROM sqlite_schema WHERE sql IS NOT NULL AND name NOT GLOB 'sqlite_*' ORDER BY type,name")
        .fetch_all(connection).await?)
}

async fn expected_catalog() -> Result<&'static Catalog> {
    EXPECTED
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
        .await
}

async fn verify_catalog(connection: &mut SqliteConnection) -> Result<()> {
    let expected = expected_catalog().await?;
    if &catalog(connection).await? != expected {
        return Err(refuse(
            StatusCode::CONFLICT,
            "native_schema_catalog_mismatch",
        ));
    }
    Ok(())
}

pub(super) async fn php_source_catalog(connection: &mut SqliteConnection) -> Result<(Catalog, &'static str)> {
    let owned: bool = sqlx::query_scalar(
        "SELECT EXISTS(SELECT 1 FROM sqlite_schema WHERE name='dw_server_schema' COLLATE NOCASE)",
    )
    .fetch_one(&mut *connection)
    .await?;
    if owned {
        return Err(refuse(StatusCode::CONFLICT, "upgrade_source_already_owned"));
    }
    let expected: Catalog = expected_catalog()
        .await?
        .iter()
        .filter(|(_, name, table, _)| {
            !super::upgrade::native_relation(table) && name != "dw_workflow_tasks_poll"
        })
        .cloned()
        .collect();
    let actual = catalog(connection).await?;
    let layout = super::upgrade::source_layout(
        &actual,
        expected,
        [
            include_str!("../../upgrade/php-2.8.0/sqlite.json"),
            include_str!("../../upgrade/php-2.9.7/sqlite.json"),
            include_str!("../../upgrade/php-2.11.1/sqlite.json"),
            include_str!("../../upgrade/php-2.11.1-from-2.9.7/sqlite.json"),
            include_str!("../../upgrade/php-2.11.3/sqlite.json"),
            include_str!("../../upgrade/php-2.11.3-from-2.9.7/sqlite.json"),
        ],
        |row| (row.0.clone(), row.1.clone()),
    )?;
    Ok((actual, layout))
}

pub(super) async fn verify(connection: &mut SqliteConnection) -> Result<()> {
    let markers: Vec<(String, i64)> = sqlx::query_as("SELECT engine,version FROM dw_server_schema")
        .fetch_all(&mut *connection)
        .await?;
    if markers != [("rust-development".to_owned(), VERSION)] {
        return Err(match markers.as_slice() {
            [(engine, version)] => super::schema_upgrade::marker_refusal(engine, *version, VERSION),
            _ => refuse(StatusCode::CONFLICT, "unsupported_rust_schema"),
        });
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
                "SELECT COUNT(*) FROM _sqlx_migrations WHERE version=$1 AND success=1",
            )
            .bind(VERSION)
            .fetch_one(&mut *tx)
            .await
            .unwrap();
            assert_eq!(rows, 1);
            verify(&mut tx).await.unwrap();
        }
        // This receipt acknowledges an actual SQLite boundary inside the
        // still-open outer transaction. The parent sends a real SIGKILL.
        // The parent waits for the receipt to exist, so it must appear with
        // its contents: write beside it and rename into place.
        std::fs::write(format!("{path}.boundary.partial"), &phase).unwrap();
        std::fs::rename(format!("{path}.boundary.partial"), format!("{path}.boundary")).unwrap();
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
        for version in [1, 2, 3] {
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
    async fn complete_version_three_database_and_acknowledged_queue_are_refused_unchanged() {
        let directory = tempfile::tempdir().unwrap();
        let path = directory.path().join("runtime.sqlite");
        let mut connection = SqliteConnection::connect_with(
            &SqliteConnectOptions::new()
                .filename(&path)
                .create_if_missing(true),
        )
        .await
        .unwrap();
        let old = Migrator::with_migrations(vec![Migration::new(
            3,
            "full frozen PHP schema and native receipts".into(),
            MigrationType::Simple,
            include_str!("../../migrations/sqlite/0003_full_schema.sql").into_sql_str(),
            false,
        )]);
        old.run(&mut connection).await.unwrap();
        sqlx::query("INSERT INTO jobs(queue,payload,attempts,available_at,created_at) VALUES ('acknowledged','preserve original durable input',0,1,1)")
            .execute(&mut connection).await.unwrap();
        connection.close().await.unwrap();
        assert_read_only_refusal(&path).await;
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

    /// A database exactly as release candidates 1 and 2 bootstrapped it:
    /// schema version 7, its cumulative migration and its marker.
    async fn version_7_database(path: &Path) {
        let mut connection = SqliteConnection::connect_with(
            &SqliteConnectOptions::new()
                .filename(path)
                .create_if_missing(true)
                .journal_mode(SqliteJournalMode::Wal),
        )
        .await
        .unwrap();
        let mut tx = connection.begin_with("BEGIN IMMEDIATE").await.unwrap();
        Migrator::with_migrations(vec![earlier_migration(7).unwrap().clone()])
            .run(&mut tx)
            .await
            .unwrap();
        sqlx::query("INSERT INTO workflow_namespaces(name,description) VALUES ('kept','seeded at version 7')")
            .execute(&mut *tx)
            .await
            .unwrap();
        tx.commit().await.unwrap();
        connection.close().await.unwrap();
    }

    async fn reason<T>(result: Result<T>) -> &'static str {
        match result {
            Err(super::super::RuntimeError::Refused { reason, .. }) => reason,
            Err(error) => panic!("unexpected error {error}"),
            Ok(_) => panic!("refusal expected"),
        }
    }

    async fn raw(path: &Path, sql: &'static str) {
        let mut connection =
            SqliteConnection::connect_with(&SqliteConnectOptions::new().filename(path))
                .await
                .unwrap();
        sqlx::raw_sql(sql).execute(&mut connection).await.unwrap();
        connection.close().await.unwrap();
    }

    #[test]
    fn released_step_files_keep_the_checksum_their_release_recorded() {
        // Release candidates 1 and 2 recorded this checksum for version 7.
        // Editing 0003-0007 would make their databases unrecognisable.
        let hex: String = earlier_migration(7)
            .unwrap()
            .checksum
            .iter()
            .map(|byte| format!("{byte:02x}"))
            .collect();
        assert_eq!(hex, include_str!("../../migrations/sqlite/0007_checksum.txt").trim());
    }

    #[tokio::test]
    async fn schema_upgrade_moves_version_7_to_a_fresh_current_schema() {
        let directory = tempfile::tempdir().unwrap();
        let path = directory.path().join("v7.sqlite");
        version_7_database(&path).await;
        let database = path.to_str().unwrap();

        // Startup names the next step instead of opening an earlier version.
        assert_eq!(
            reason(super::super::sqlite::open(database).await).await,
            "native_schema_upgrade_required"
        );

        let report = super::super::schema_upgrade::upgrade_sqlite(database).await.unwrap();
        assert_eq!(report["from_schema_version"], 7);
        assert_eq!(report["schema_version"], VERSION);
        assert_eq!(report["status"], "upgraded");

        // The upgraded database is what a fresh bootstrap produces: verify
        // compares marker, migration ledger and catalog, and opening it runs
        // the same checks. Rows written at version 7 are kept.
        let pool = super::super::sqlite::open(database).await.unwrap();
        let fresh_path = directory.path().join("fresh.sqlite");
        let fresh = super::super::sqlite::open(fresh_path.to_str().unwrap()).await.unwrap();
        let mut upgraded = pool.acquire().await.unwrap();
        let mut reference = fresh.acquire().await.unwrap();
        assert_eq!(catalog(&mut upgraded).await.unwrap(), catalog(&mut reference).await.unwrap());
        let ledger = |connection| {
            sqlx::query_as::<_, (i64, String, bool, Vec<u8>)>(
                "SELECT version,description,success,checksum FROM _sqlx_migrations",
            )
            .fetch_all(connection)
        };
        assert_eq!(ledger(&mut *upgraded).await.unwrap(), ledger(&mut *reference).await.unwrap());
        let kept: String = sqlx::query_scalar("SELECT description FROM workflow_namespaces WHERE name='kept'")
            .fetch_one(&mut *upgraded)
            .await
            .unwrap();
        assert_eq!(kept, "seeded at version 7");
        drop((upgraded, reference));
        pool.close().await;
        fresh.close().await;

        // A rerun on the current version only verifies.
        let again = super::super::schema_upgrade::upgrade_sqlite(database).await.unwrap();
        assert_eq!(again["from_schema_version"], VERSION);
        assert_eq!(again["status"], "current");
    }

    #[tokio::test]
    async fn schema_upgrade_refuses_without_writing_what_it_cannot_recognise() {
        let directory = tempfile::tempdir().unwrap();
        for (mutation, expected) in [
            // An edited earlier catalog is not version 7 as released.
            ("ALTER TABLE workflow_namespaces ADD COLUMN unexpected TEXT", "native_schema_catalog_mismatch"),
            ("UPDATE _sqlx_migrations SET checksum=X'00'", "native_migration_history_mismatch"),
            ("UPDATE dw_server_schema SET version=9", "native_schema_newer_than_binary"),
            ("UPDATE dw_server_schema SET version=5", "unsupported_rust_schema"),
            ("UPDATE dw_server_schema SET engine='php'", "unsupported_rust_schema"),
        ] {
            let path = directory.path().join(format!("refused-{}.sqlite", ulid::Ulid::new()));
            version_7_database(&path).await;
            raw(&path, mutation).await;
            let database = path.to_str().unwrap();
            assert_eq!(
                reason(super::super::schema_upgrade::upgrade_sqlite(database).await).await,
                expected,
                "{mutation}"
            );
            // Nothing changed: still no version 8 table, ledger or marker.
            let mut connection =
                SqliteConnection::connect_with(&SqliteConnectOptions::new().filename(&path).read_only(true))
                    .await
                    .unwrap();
            let added: bool = sqlx::query_scalar(
                "SELECT EXISTS(SELECT 1 FROM sqlite_schema WHERE name='dw_external_payload_deletions')",
            )
            .fetch_one(&mut connection)
            .await
            .unwrap();
            assert!(!added, "{mutation}");
            let versions: Vec<i64> = sqlx::query_scalar("SELECT version FROM _sqlx_migrations")
                .fetch_all(&mut connection)
                .await
                .unwrap();
            assert_eq!(versions, [7], "{mutation}");
            connection.close().await.unwrap();
        }
        let missing = directory.path().join("missing.sqlite");
        assert_eq!(
            reason(super::super::schema_upgrade::upgrade_sqlite(missing.to_str().unwrap()).await).await,
            "native_schema_not_initialized"
        );
        assert!(!missing.exists());
    }
}
