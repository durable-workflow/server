//! PostgreSQL storage foundation. Existing PHP data is never adopted here.
//! HTTP execution and backup-first takeover remain separate qualification gates.
use std::{sync::OnceLock, time::Duration};

use axum::http::StatusCode;
use serde_json::Value;
use sqlx::{
    Connection, PgConnection, PgPool, SqlSafeStr,
    migrate::{Migration, MigrationType, Migrator},
    postgres::{PgConnectOptions, PgPoolOptions},
    types::Json,
};

use super::{Result, refuse};

pub const VERSION: i64 = 8;
pub const CATALOG_QUERY: &str = include_str!("postgres-catalog.sql");
type Catalog = Vec<(String, String, Json<Value>)>;

fn migrator() -> &'static Migrator {
    static MIGRATOR: OnceLock<Migrator> = OnceLock::new();
    MIGRATOR.get_or_init(|| {
        let mut migrations = Migrator::with_migrations(vec![Migration::new(
            VERSION,
            "full frozen PHP PostgreSQL schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions".into(),
            MigrationType::Simple,
            concat!(
                include_str!("../../migrations/postgres/0003_full_schema.sql"),
                "\n",
                include_str!("../../migrations/postgres/0004_worker_incarnations.sql"),
                "\n",
                include_str!("../../migrations/postgres/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/postgres/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/postgres/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/postgres/0008_external_payload_deletions.sql")
            )
            .into_sql_str(),
            false,
        )]);
        // The caller's transaction-scoped PostgreSQL advisory lock covers
        // the pre-migration recheck, ledger, DDL and marker together.
        migrations.set_locking(false);
        migrations
    })
}

/// The single native migration, which a takeover records as applied once
/// conversion has produced its schema.
pub(super) fn migration() -> &'static Migration {
    migrator().iter().next().expect("one native PostgreSQL migration")
}

/// The native relations, indexes and ownership marker that a fresh
/// bootstrap adds after the frozen PHP layout. Takeover applies them to a
/// converted PHP database instead of running the whole migration.
pub(super) fn native_relations_sql() -> Result<String> {
    super::upgrade::native_relations(
        include_str!("../../migrations/postgres/0003_full_schema.sql"),
        "CREATE TABLE public.dw_server_schema ",
        concat!(
            include_str!("../../migrations/postgres/0004_worker_incarnations.sql"),
            "\n",
            include_str!("../../migrations/postgres/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/postgres/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/postgres/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/postgres/0008_external_payload_deletions.sql")
        ),
    )
}

fn options(options: PgConnectOptions) -> PgConnectOptions {
    options
        .application_name("durable-workflow-rust-development")
        .options([
            ("search_path", "public"),
            ("timezone", "UTC"),
            ("lock_timeout", "5000ms"),
            ("statement_timeout", "30000ms"),
        ])
}

async fn catalog(connection: &mut PgConnection) -> Result<Catalog> {
    Ok(sqlx::query_as(CATALOG_QUERY).fetch_all(connection).await?)
}

pub(super) async fn php_source_catalog(connection: &mut PgConnection) -> Result<(Catalog, &'static str)> {
    verify_scope(connection).await?;
    if marked(connection).await? {
        return Err(refuse(StatusCode::CONFLICT, "upgrade_source_already_owned"));
    }
    let row_security: bool = sqlx::query_scalar("SELECT EXISTS(SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND (c.relrowsecurity OR c.relforcerowsecurity))")
        .fetch_one(&mut *connection).await?;
    if row_security {
        return Err(refuse(
            StatusCode::CONFLICT,
            "upgrade_source_row_security_unqualified",
        ));
    }
    let native: Catalog =
        serde_json::from_str(include_str!("../../migrations/postgres/0008_catalog.json"))?;
    let expected: Catalog = native
        .into_iter()
        .filter(|(kind, name, _)| {
            if kind == "column" || kind == "constraint" {
                !super::upgrade::native_relation(name.split('.').next().unwrap_or(name))
            } else {
                !super::upgrade::native_object(name)
            }
        })
        .collect();
    let actual = catalog(connection).await?;
    let layout = super::upgrade::source_layout(
        &actual,
        expected,
        [
            include_str!("../../upgrade/php-2.8.0/postgres.json"),
            include_str!("../../upgrade/php-2.9.7/postgres.json"),
            include_str!("../../upgrade/php-2.11.1/postgres.json"),
            include_str!("../../upgrade/php-2.11.1-from-2.9.7/postgres.json"),
            include_str!("../../upgrade/php-2.11.3/postgres.json"),
            include_str!("../../upgrade/php-2.11.3-from-2.9.7/postgres.json"),
            include_str!("../../upgrade/php-2.11.8/postgres.json"),
            include_str!("../../upgrade/php-2.11.8-from-2.9.7/postgres.json"),
        ],
        |row| (row.0.clone(), row.1.clone()),
    )?;
    Ok((actual, layout))
}

pub(super) fn source_options(options: PgConnectOptions) -> PgConnectOptions {
    self::options(options)
}

async fn empty(connection: &mut PgConnection) -> Result<bool> {
    let occupied: bool = sqlx::query_scalar("SELECT EXISTS(SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind IN ('r','p','v','m','f','S')) OR EXISTS(SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='public')")
        .fetch_one(connection).await?;
    Ok(!occupied)
}

async fn marked(connection: &mut PgConnection) -> Result<bool> {
    Ok(sqlx::query_scalar("SELECT EXISTS(SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND lower(c.relname)='dw_server_schema')")
        .fetch_one(connection).await?)
}

async fn verify_scope(connection: &mut PgConnection) -> Result<()> {
    let public: bool = sqlx::query_scalar("SELECT COALESCE(current_schema()='public',false)")
        .fetch_one(&mut *connection)
        .await?;
    if !public {
        return Err(refuse(
            StatusCode::CONFLICT,
            "postgres_public_schema_required",
        ));
    }
    // A PHP installation in another user schema must not be mistaken for an
    // empty database merely because this development path fixes search_path.
    let outside: bool = sqlx::query_scalar("SELECT EXISTS(SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname NOT IN ('public','information_schema') AND left(n.nspname,3)<>'pg_' AND c.relkind IN ('r','p','v','m','f','S')) OR EXISTS(SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname NOT IN ('public','information_schema') AND left(n.nspname,3)<>'pg_')")
        .fetch_one(connection).await?;
    if outside {
        return Err(refuse(
            StatusCode::CONFLICT,
            "existing_database_requires_qualified_takeover",
        ));
    }
    Ok(())
}

pub(super) async fn verify_history(connection: &mut PgConnection) -> Result<()> {
    let markers: Vec<(String, i64)> =
        sqlx::query_as("SELECT engine,version FROM public.dw_server_schema")
            .fetch_all(&mut *connection)
            .await?;
    if markers != [("rust-development".into(), VERSION)] {
        return Err(refuse(StatusCode::CONFLICT, "unsupported_rust_schema"));
    }
    let applied: Vec<(i64, bool, Vec<u8>)> = sqlx::query_as(
        "SELECT version,success,checksum FROM public._sqlx_migrations ORDER BY version",
    )
    .fetch_all(connection)
    .await?;
    if applied.len() != 1
        || applied[0].0 != VERSION
        || !applied[0].1
        || applied[0].2.as_slice() != migrator().iter().next().unwrap().checksum.as_ref()
    {
        return Err(refuse(
            StatusCode::CONFLICT,
            "native_migration_history_mismatch",
        ));
    }
    Ok(())
}

pub(super) async fn verify(connection: &mut PgConnection) -> Result<()> {
    verify_scope(connection).await?;
    verify_history(connection).await?;
    // A canonical catalog is captured with PostgreSQL itself, not inferred
    // from SQL text. It retains types, precision, defaults, indexes, foreign
    // keys and sequence ownership while excluding live rows/sequence values.
    let expected: Catalog =
        serde_json::from_str(include_str!("../../migrations/postgres/0008_catalog.json"))?;
    let actual = catalog(connection).await?;
    if actual != expected {
        return Err(refuse(
            StatusCode::CONFLICT,
            "native_schema_catalog_mismatch",
        ));
    }
    Ok(())
}

async fn preflight(connection: &mut PgConnection) -> Result<()> {
    verify_scope(connection).await?;
    if marked(connection).await? {
        verify(connection).await
    } else if empty(connection).await? {
        Ok(())
    } else {
        Err(refuse(
            StatusCode::CONFLICT,
            "existing_database_requires_qualified_takeover",
        ))
    }
}

/// Prepared native PostgreSQL storage, with a bounded connection pool.
/// This does not expose PostgreSQL HTTP execution yet.
pub struct PostgresStorage {
    pool: PgPool,
}

impl PostgresStorage {
    pub(super) fn into_pool(self) -> PgPool {
        self.pool
    }
    /// Inspect before opening a writable pool. The database must already exist;
    /// the only admitted schema is public, with no concurrent PHP writers.
    pub async fn open(connect_options: PgConnectOptions) -> Result<Self> {
        let connect_options = options(connect_options);
        let mut connection = PgConnection::connect_with(&connect_options).await?;
        let result = async {
            let mut read = connection
                .begin_with("BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY")
                .await?;
            preflight(&mut read).await?;
            read.rollback().await?;
            Ok::<_, super::RuntimeError>(())
        }
        .await;
        connection.close().await?;
        result?;

        let pool = PgPoolOptions::new()
            .max_connections(4)
            .acquire_timeout(Duration::from_secs(5))
            .connect_with(connect_options)
            .await?;
        let result = async {
            let mut tx = pool.begin().await?;
            // Fixed product-specific key, scoped to this database. It is
            // released by PostgreSQL on commit, rollback or process death.
            sqlx::query("SELECT pg_advisory_xact_lock(4924501473315676162)")
                .execute(&mut *tx)
                .await?;
            verify_scope(&mut tx).await?;
            if marked(&mut tx).await? {
                verify(&mut tx).await?;
            } else if empty(&mut tx).await? {
                migrator().run(&mut *tx).await?;
                verify(&mut tx).await?;
            } else {
                return Err(refuse(
                    StatusCode::CONFLICT,
                    "existing_database_requires_qualified_takeover",
                ));
            }
            tx.commit().await?;
            Ok::<_, super::RuntimeError>(())
        }
        .await;
        if let Err(error) = result {
            pool.close().await;
            return Err(error);
        }
        Ok(Self { pool })
    }

    /// Read-only inspection of an already initialized native database.
    /// It creates no ledger or schema and refuses empty/PHP databases.
    pub async fn check(connect_options: PgConnectOptions) -> Result<()> {
        let mut connection = PgConnection::connect_with(&options(connect_options)).await?;
        let result = async {
            let mut tx = connection
                .begin_with("BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY")
                .await?;
            if !marked(&mut tx).await? {
                return Err(refuse(
                    StatusCode::CONFLICT,
                    "native_schema_not_initialized",
                ));
            }
            verify(&mut tx).await?;
            tx.rollback().await?;
            Ok::<_, super::RuntimeError>(())
        }
        .await;
        connection.close().await?;
        result
    }

    pub async fn close(&self) {
        self.pool.close().await;
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use futures_util::FutureExt;
    use sqlx::{
        ConnectOptions, SqlSafeStr,
        migrate::{Migrate, MigrateDatabase},
        postgres::Postgres,
    };
    use std::{process::Command, str::FromStr};

    struct Database {
        options: PgConnectOptions,
        url: String,
    }

    impl Database {
        async fn new() -> Self {
            let base = std::env::var("DW_TEST_POSTGRES_URL")
                .expect("explicit PostgreSQL qualification requires DW_TEST_POSTGRES_URL and a disposable create-database role");
            let base = PgConnectOptions::from_str(&base).unwrap();
            let name = format!(
                "dw_qualification_{}",
                ulid::Ulid::new().to_string().to_lowercase()
            );
            let options = options(base.database(&name));
            let url = options.to_url_lossy().to_string();
            Postgres::create_database(&url).await.unwrap();
            Self { options, url }
        }

        async fn connection(&self) -> PgConnection {
            PgConnection::connect_with(&self.options).await.unwrap()
        }

        async fn remove(self) {
            Postgres::drop_database(&self.url).await.unwrap();
        }
    }

    fn refused(result: Result<PostgresStorage>, expected: &str) {
        match result {
            Err(super::super::RuntimeError::Refused { reason, .. }) => assert_eq!(reason, expected),
            Err(error) => panic!("unexpected qualification error: {error}"),
            Ok(_) => panic!("unsafe database was accepted"),
        }
    }

    /// A request dropped while its transaction's `BEGIN` is in flight must not
    /// leave the pooled connection inside a transaction. With published SQLx
    /// 0.9.0 it did (vendor/sqlx-postgres/DURABLE-PATCH.md): the next borrower's
    /// probe ran inside it, native's read snapshot `BEGIN` then failed with
    /// 25001, and every later statement on that connection with 25P02.
    #[tokio::test]
    #[ignore = "requires a disposable PostgreSQL; run the qualification_ tests explicitly"]
    async fn qualification_a_begin_cancelled_in_flight_leaves_no_transaction_behind() {
        let db = Database::new().await;
        let pool = PgPoolOptions::new()
            .max_connections(1)
            .min_connections(0)
            .connect_with(db.options.clone())
            .await
            .unwrap();
        let pid: i32 = sqlx::query_scalar("SELECT pg_backend_pid()").fetch_one(&pool).await.unwrap();
        // The sleep holds the BEGIN's round trip open so the cancellation lands inside it.
        let cancelled = tokio::time::timeout(Duration::from_millis(300), pool.begin_with("BEGIN; SELECT pg_sleep(2)")).await;
        assert!(cancelled.is_err(), "the begin must still be in flight when it is cancelled");
        // The queued ROLLBACK leaves once the abandoned statement has answered.
        tokio::time::sleep(Duration::from_millis(3500)).await;

        let mut observer = db.connection().await;
        let state: Option<String> = sqlx::query_scalar("SELECT state FROM pg_stat_activity WHERE pid=$1")
            .bind(pid)
            .fetch_optional(&mut observer)
            .await
            .unwrap();
        assert_eq!(state.as_deref(), Some("idle"), "the pooled connection is still inside a transaction");

        // The scheduler's order on the same connection: a pooled probe, then a
        // read snapshot, then a pooled statement.
        let same: i32 = sqlx::query_scalar("SELECT pg_backend_pid()").fetch_one(&pool).await.unwrap();
        assert_eq!(same, pid);
        let mut read = pool.begin_with(<Postgres as super::super::backend::Backend>::READ_SNAPSHOT_BEGIN).await.unwrap();
        let one: i32 = sqlx::query_scalar("SELECT 1").fetch_one(&mut *read).await.unwrap();
        assert_eq!(one, 1);
        read.rollback().await.unwrap();
        let one: i32 = sqlx::query_scalar("SELECT 1").fetch_one(&pool).await.unwrap();
        assert_eq!(one, 1);

        observer.close().await.unwrap();
        pool.close().await;
        db.remove().await;
    }

    #[test]
    fn compiled_catalog_preserves_long_object_identities_and_int64_limits() {
        let rows: Catalog =
            serde_json::from_str(include_str!("../../migrations/postgres/0008_catalog.json"))
                .unwrap();
        let identities: std::collections::BTreeSet<_> =
            rows.iter().map(|(kind, name, _)| (kind, name)).collect();
        assert_eq!(
            identities.len(),
            rows.len(),
            "catalog keys were truncated or collided"
        );
        let sequence = rows
            .iter()
            .find(|(kind, name, _)| kind == "sequence" && name == "failed_jobs_id_seq")
            .unwrap();
        assert_eq!(sequence.2.0["max"].as_i64(), Some(i64::MAX));
        for name in [
            "workflow_worker_compatibility_heartbeats.workflow_worker_compatibility_heartbeats_pkey",
            "workflow_worker_compatibility_heartbeats.workflow_worker_compatibility_heartbeats_scope_unique",
        ] {
            assert!(identities.contains(&(&"constraint".to_owned(), &name.to_owned())));
        }
    }

    #[tokio::test]
    #[ignore = "requires a disposable PostgreSQL; run the qualification_ tests explicitly"]
    async fn qualification_read_snapshot_hides_later_commits_and_refuses_writes() {
        let database = Database::new().await;
        let mut writer = database.connection().await;
        sqlx::query("CREATE TABLE snapshot_probe (id BIGINT PRIMARY KEY)")
            .execute(&mut writer)
            .await
            .unwrap();
        let mut reader = database.connection().await;
        let mut read = reader
            .begin_with(<Postgres as super::super::backend::Backend>::READ_SNAPSHOT_BEGIN)
            .await
            .unwrap();
        let count = "SELECT COUNT(*) FROM snapshot_probe";
        let before: i64 = sqlx::query_scalar(count).fetch_one(&mut *read).await.unwrap();
        sqlx::query("INSERT INTO snapshot_probe VALUES (1)")
            .execute(&mut writer)
            .await
            .unwrap();
        let during: i64 = sqlx::query_scalar(count).fetch_one(&mut *read).await.unwrap();
        assert_eq!((before, during), (0, 0));
        assert!(
            sqlx::query("INSERT INTO snapshot_probe VALUES (2)")
                .execute(&mut *read)
                .await
                .is_err()
        );
        read.rollback().await.unwrap();
        let after: i64 = sqlx::query_scalar(count).fetch_one(&mut reader).await.unwrap();
        assert_eq!(after, 1);
        drop((reader, writer));
        database.remove().await;
    }

    #[tokio::test]
    #[ignore = "requires a disposable PostgreSQL; run the qualification_ tests explicitly"]
    async fn qualification_concurrent_bootstrap_and_reopen() {
        let db = Database::new().await;
        let (a, b, c) = tokio::join!(
            PostgresStorage::open(db.options.clone()),
            PostgresStorage::open(db.options.clone()),
            PostgresStorage::open(db.options.clone())
        );
        let (a, b, c) = (a.unwrap(), b.unwrap(), c.unwrap());
        let mut connection = db.connection().await;
        let rows: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM _sqlx_migrations")
            .fetch_one(&mut connection)
            .await
            .unwrap();
        assert_eq!(rows, 1);
        verify(&mut connection).await.unwrap();
        connection.close().await.unwrap();
        a.close().await;
        b.close().await;
        c.close().await;
        PostgresStorage::check(db.options.clone()).await.unwrap();
        let reopened = PostgresStorage::open(db.options.clone()).await.unwrap();
        reopened.close().await;
        db.remove().await;
    }

    #[tokio::test]
    #[ignore = "requires a disposable PostgreSQL; run the qualification_ tests explicitly"]
    async fn qualification_empty_check_and_existing_data_refusal() {
        let db = Database::new().await;
        assert!(PostgresStorage::check(db.options.clone()).await.is_err());
        let mut connection = db.connection().await;
        assert!(empty(&mut connection).await.unwrap());
        sqlx::raw_sql("CREATE TABLE acknowledged_php_data (id bigint PRIMARY KEY,payload text NOT NULL); INSERT INTO acknowledged_php_data VALUES (9007199254740993,'preserve acknowledged input')")
            .execute(&mut connection).await.unwrap();
        let before = catalog(&mut connection).await.unwrap();
        refused(
            PostgresStorage::open(db.options.clone()).await,
            "existing_database_requires_qualified_takeover",
        );
        assert_eq!(catalog(&mut connection).await.unwrap(), before);
        let row: (i64, String) = sqlx::query_as("SELECT id,payload FROM acknowledged_php_data")
            .fetch_one(&mut connection)
            .await
            .unwrap();
        assert_eq!(
            row,
            (9007199254740993, "preserve acknowledged input".into())
        );
        connection.close().await.unwrap();
        db.remove().await;
    }

    #[tokio::test]
    #[ignore = "requires a disposable PostgreSQL; run the qualification_ tests explicitly"]
    async fn qualification_custom_schema_data_cannot_be_treated_as_empty() {
        let db = Database::new().await;
        let mut connection = db.connection().await;
        sqlx::raw_sql("CREATE SCHEMA legacy_php; CREATE TABLE legacy_php.acknowledged (id bigint PRIMARY KEY); INSERT INTO legacy_php.acknowledged VALUES (9007199254740993)")
            .execute(&mut connection).await.unwrap();
        refused(
            PostgresStorage::open(db.options.clone()).await,
            "existing_database_requires_qualified_takeover",
        );
        assert!(
            empty(&mut connection).await.unwrap(),
            "refusal created objects in public"
        );
        let id: i64 = sqlx::query_scalar("SELECT id FROM legacy_php.acknowledged")
            .fetch_one(&mut connection)
            .await
            .unwrap();
        assert_eq!(id, 9007199254740993);
        connection.close().await.unwrap();
        db.remove().await;
    }

    #[tokio::test]
    #[ignore = "requires a disposable PostgreSQL; run the qualification_ tests explicitly"]
    async fn qualification_typed_values_and_read_only_role() {
        let db = Database::new().await;
        let storage = PostgresStorage::open(db.options.clone()).await.unwrap();
        let timestamp = chrono::NaiveDateTime::parse_from_str(
            "2026-10-09 12:34:56.123456",
            "%Y-%m-%d %H:%M:%S%.f",
        )
        .unwrap();
        let payload = serde_json::json!({"int64":9007199254740993_i64,"unicode":"雪 🦀","null":null,"nested":[true,{"text":"value"}]});
        sqlx::query("INSERT INTO workflow_instances(id,workflow_type,workflow_class,started_at,memo) VALUES ('typed-sentinel','echo','echo',$1,$2)")
            .bind(timestamp).bind(Json(&payload)).execute(&storage.pool).await.unwrap();
        storage.close().await;
        PostgresStorage::check(db.options.clone()).await.unwrap();
        let mut connection = db.connection().await;
        let row: (chrono::NaiveDateTime, Json<Value>) = sqlx::query_as(
            "SELECT started_at,memo FROM workflow_instances WHERE id='typed-sentinel'",
        )
        .fetch_one(&mut connection)
        .await
        .unwrap();
        assert_eq!(row, (timestamp, Json(payload)));

        let role = format!(
            "dw_readonly_{}",
            ulid::Ulid::new().to_string().to_lowercase()
        );
        // A fresh password for every run, from the operating system's secure
        // source (Server #779). It is hex, so it cannot leave the quoted
        // literal below, and the connection that sends it logs no statements.
        let mut secret = [0_u8; 32];
        getrandom::fill(&mut secret).unwrap();
        let password: String = secret.iter().map(|byte| format!("{byte:02x}")).collect();
        assert!(password.bytes().all(|byte| byte.is_ascii_hexdigit()));
        let mut admin = PgConnection::connect_with(&db.options.clone().disable_statement_logging())
            .await
            .unwrap();
        // Names are generated solely from the fixed prefix and ULID alphabet.
        let grant = sqlx::AssertSqlSafe(format!(
            "CREATE ROLE {role} LOGIN PASSWORD '{password}'; GRANT USAGE ON SCHEMA public TO {role}; GRANT SELECT ON ALL TABLES IN SCHEMA public TO {role}"
        ));
        sqlx::raw_sql(grant.into_sql_str())
            .execute(&mut admin)
            .await
            .unwrap();
        let reader = db.options.clone().username(&role).password(&password);
        // The role's removal is attempted whether or not the checks pass.
        let checked = std::panic::AssertUnwindSafe(async {
            PostgresStorage::check(reader.clone()).await.unwrap();
            let mut read_connection = PgConnection::connect_with(&reader).await.unwrap();
            assert!(
                sqlx::query("DELETE FROM workflow_instances")
                    .execute(&mut read_connection)
                    .await
                    .is_err()
            );
            read_connection.close().await.unwrap();
        })
        .catch_unwind()
        .await;
        let cleanup = sqlx::AssertSqlSafe(format!(
            "REVOKE ALL ON ALL TABLES IN SCHEMA public FROM {role}; REVOKE ALL ON SCHEMA public FROM {role}; DROP ROLE {role}"
        ));
        let dropped = sqlx::raw_sql(cleanup.into_sql_str())
            .execute(&mut admin)
            .await
            .is_ok();
        let admin_closed = admin.close().await.is_ok();
        let closed = connection.close().await.is_ok() && admin_closed;
        let removed = std::panic::AssertUnwindSafe(db.remove())
            .catch_unwind()
            .await
            .is_ok();
        // Every cleanup step has run. A failed one is reported before a failed
        // check, without its error text, so the check cannot hide it.
        assert!(
            dropped && closed && removed,
            "cleanup failed (role dropped {dropped}, connections closed {closed}, database removed {removed}); checks passed {}",
            checked.is_ok()
        );
        if let Err(panic) = checked {
            std::panic::resume_unwind(panic);
        }
    }

    #[tokio::test]
    #[ignore = "requires a disposable PostgreSQL; run the qualification_ tests explicitly"]
    async fn qualification_corrupt_history_and_catalog_are_refused_without_writes() {
        for (mutation, reason) in [
            (
                "UPDATE dw_server_schema SET version=99",
                "unsupported_rust_schema",
            ),
            (
                "UPDATE dw_server_schema SET version=2",
                "unsupported_rust_schema",
            ),
            ("DELETE FROM dw_server_schema", "unsupported_rust_schema"),
            (
                "ALTER TABLE dw_query_cache DROP COLUMN expiration",
                "native_schema_catalog_mismatch",
            ),
            (
                "INSERT INTO dw_server_schema VALUES ('rust-development',2)",
                "unsupported_rust_schema",
            ),
            (
                "UPDATE _sqlx_migrations SET checksum=decode('00','hex')",
                "native_migration_history_mismatch",
            ),
            (
                "UPDATE _sqlx_migrations SET success=false",
                "native_migration_history_mismatch",
            ),
            (
                "INSERT INTO _sqlx_migrations SELECT 99,description,installed_on,success,checksum,execution_time FROM _sqlx_migrations",
                "native_migration_history_mismatch",
            ),
            (
                "ALTER TABLE workflow_instances DROP COLUMN memo",
                "native_schema_catalog_mismatch",
            ),
            (
                "ALTER TABLE workflow_runs ALTER COLUMN started_at TYPE timestamp(3)",
                "native_schema_catalog_mismatch",
            ),
            (
                "DROP INDEX dw_workflow_tasks_poll",
                "native_schema_catalog_mismatch",
            ),
            (
                "ALTER TABLE workflow_memos ALTER CONSTRAINT workflow_memos_workflow_run_id_foreign DEFERRABLE",
                "native_schema_catalog_mismatch",
            ),
            (
                "CREATE VIEW unknown_view AS SELECT 1 AS value",
                "native_schema_catalog_mismatch",
            ),
            (
                "ALTER SEQUENCE jobs_id_seq INCREMENT BY 2",
                "native_schema_catalog_mismatch",
            ),
            (
                "CREATE FUNCTION unexpected() RETURNS integer LANGUAGE sql AS 'SELECT 1'",
                "native_schema_catalog_mismatch",
            ),
            // Neither needs a public function, so only trigger/rule catalog rows see them.
            (
                "CREATE TRIGGER unexpected BEFORE UPDATE ON jobs FOR EACH ROW EXECUTE FUNCTION suppress_redundant_updates_trigger()",
                "native_schema_catalog_mismatch",
            ),
            (
                "CREATE RULE unexpected AS ON DELETE TO jobs DO INSTEAD NOTHING",
                "native_schema_catalog_mismatch",
            ),
        ] {
            let db = Database::new().await;
            let storage = PostgresStorage::open(db.options.clone()).await.unwrap();
            storage.close().await;
            let mut connection = db.connection().await;
            sqlx::raw_sql(mutation)
                .execute(&mut connection)
                .await
                .unwrap();
            let before = catalog(&mut connection).await.unwrap();
            let history: Vec<(i64, bool, Vec<u8>)> = sqlx::query_as(
                "SELECT version,success,checksum FROM _sqlx_migrations ORDER BY version",
            )
            .fetch_all(&mut connection)
            .await
            .unwrap();
            refused(PostgresStorage::open(db.options.clone()).await, reason);
            assert_eq!(
                catalog(&mut connection).await.unwrap(),
                before,
                "{mutation}"
            );
            let after: Vec<(i64, bool, Vec<u8>)> = sqlx::query_as(
                "SELECT version,success,checksum FROM _sqlx_migrations ORDER BY version",
            )
            .fetch_all(&mut connection)
            .await
            .unwrap();
            assert_eq!(
                after, history,
                "refusal rewrote migration history: {mutation}"
            );
            connection.close().await.unwrap();
            db.remove().await;
        }
    }

    #[tokio::test]
    #[ignore = "only invoked as a real subprocess by the interruption qualification"]
    async fn bootstrap_child() {
        let url = std::env::var("DW_SCHEMA_TEST_DATABASE").unwrap();
        let phase = std::env::var("DW_SCHEMA_TEST_PHASE").unwrap();
        let mut connection =
            PgConnection::connect_with(&options(PgConnectOptions::from_str(&url).unwrap()))
                .await
                .unwrap();
        let mut tx = connection.begin().await.unwrap();
        sqlx::query("SELECT pg_advisory_xact_lock(4924501473315676162)")
            .execute(&mut *tx)
            .await
            .unwrap();
        if phase == "ledger" {
            tx.ensure_migrations_table("_sqlx_migrations")
                .await
                .unwrap();
        } else {
            migrator().run(&mut *tx).await.unwrap();
            verify(&mut tx).await.unwrap();
        }
        // An actual server acknowledgement at a still-uncommitted boundary.
        // The parent waits for the receipt to exist, so it must appear with
        // its contents: write beside it and rename into place.
        let receipt = std::env::var("DW_SCHEMA_TEST_RECEIPT").unwrap();
        std::fs::write(format!("{receipt}.partial"), &phase).unwrap();
        std::fs::rename(format!("{receipt}.partial"), &receipt).unwrap();
        loop {
            std::thread::park();
        }
    }

    #[cfg(unix)]
    #[tokio::test]
    #[ignore = "requires a disposable PostgreSQL; run the qualification_ tests explicitly"]
    async fn qualification_real_kill_rolls_back_bootstrap_and_releases_lock() {
        use std::os::unix::process::ExitStatusExt;
        for phase in ["ledger", "before-commit"] {
            let db = Database::new().await;
            let directory = tempfile::tempdir().unwrap();
            let receipt = directory.path().join("boundary");
            let mut child = Command::new(std::env::current_exe().unwrap())
                .args([
                    "--exact",
                    "runtime::postgres::tests::bootstrap_child",
                    "--ignored",
                    "--nocapture",
                ])
                .env("DW_SCHEMA_TEST_DATABASE", &db.url)
                .env("DW_SCHEMA_TEST_PHASE", phase)
                .env("DW_SCHEMA_TEST_RECEIPT", &receipt)
                .spawn()
                .unwrap();
            let deadline = tokio::time::Instant::now() + Duration::from_secs(15);
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
            assert_eq!(std::fs::read_to_string(receipt).unwrap(), phase);
            child.kill().unwrap();
            assert_eq!(child.wait().unwrap().signal(), Some(9));
            let mut connection = db.connection().await;
            assert!(
                empty(&mut connection).await.unwrap(),
                "partial DDL survived {phase}"
            );
            connection.close().await.unwrap();
            let storage = PostgresStorage::open(db.options.clone()).await.unwrap();
            storage.close().await;
            PostgresStorage::check(db.options.clone()).await.unwrap();
            db.remove().await;
        }
    }

    use crate::runtime::upgrade::{fingerprint, queue_binding::QueueSourceConfig, takeover};

    const TAKEOVER_QUEUES: QueueSourceConfig<'static> = QueueSourceConfig {
        connection: "database",
        default_connection: "database",
        default_queue: "default",
    };

    /// The frozen PHP layout moved to PHP 2.9.7's by the changes PHP's own
    /// migrations make (rust/upgrade/php-2.8.0 and php-2.9.7). Inspection
    /// must call it php-2.9.7.
    async fn php_297(db: &Database) -> PgConnection {
        php_release(db, &[PHP_280_CHANGES, QUEUE_DELIVERY]).await
    }

    /// PHP 2.8.0's changes to the frozen layout.
    const PHP_280_CHANGES: &str = "ALTER TABLE workflow_worker_registrations ADD COLUMN registration_token varchar(32);
         CREATE TABLE workflow_worker_registration_incarnations (token varchar(32) NOT NULL, namespace varchar(255) NOT NULL, worker_id varchar(255) NOT NULL, status varchar(32) NOT NULL, recovered_workflow_task_count integer NOT NULL DEFAULT 0, finished_at timestamp(6) without time zone, created_at timestamp(6) without time zone, updated_at timestamp(6) without time zone, PRIMARY KEY (token));
         CREATE INDEX workflow_worker_registration_incarnations_finished_at_index ON workflow_worker_registration_incarnations (finished_at);
         CREATE INDEX dw_worker_incarnation_scope_idx ON workflow_worker_registration_incarnations (namespace, worker_id);
         ALTER TABLE workflow_service_calls ADD COLUMN call_deadline_at timestamp(6) without time zone;
         CREATE INDEX wf_service_calls_deadline_idx ON workflow_service_calls (status, call_deadline_at);";
    /// Core 2.7.2's queue delivery reference (PHP 2.9.7).
    const QUEUE_DELIVERY: &str = "ALTER TABLE workflow_tasks ADD COLUMN queue_delivery_reference json;";
    /// Core 2.10's trace carriers (PHP 2.11.1), in its migrations' order.
    const TRACE_CARRIERS: &str = "ALTER TABLE workflow_runs ADD COLUMN observability_context json;
         ALTER TABLE workflow_commands ADD COLUMN observability_context json;
         ALTER TABLE workflow_tasks ADD COLUMN observability_context json;";
    /// PHP 2.11.3's changes (Core 2.14.2 and Server), in its migrations' order.
    const RELEASE_2113: &str = "ALTER TABLE workflow_failures ADD COLUMN message_full text;
         ALTER TABLE workflow_service_calls ADD COLUMN idempotency_scope_hash character(64);
         CREATE UNIQUE INDEX wf_service_calls_idem_scope_unique ON workflow_service_calls (idempotency_scope_hash);
         CREATE TABLE workflow_service_call_dispatches (service_call_id varchar(26) NOT NULL, state varchar(32) NOT NULL, dispatch_options json NOT NULL, attempt integer NOT NULL DEFAULT '1', max_attempts integer NOT NULL DEFAULT '1', available_at timestamp(6) without time zone NOT NULL, claim_generation bigint NOT NULL DEFAULT '0', claim_token character(64), claimed_at timestamp(6) without time zone, claim_expires_at timestamp(6) without time zone, authorized_catalog_revision character(64), authorized_policy_revision character(64), dispatch_attempted_at timestamp(6) without time zone, repair_available_at timestamp(6) without time zone, completed_at timestamp(6) without time zone, last_error text, created_at timestamp(6) without time zone, updated_at timestamp(6) without time zone, PRIMARY KEY (service_call_id));
         CREATE INDEX wf_service_dispatch_due_idx ON workflow_service_call_dispatches (state, available_at);
         CREATE INDEX wf_service_dispatch_claim_idx ON workflow_service_call_dispatches (state, claim_expires_at);
         CREATE INDEX wf_service_dispatch_repair_idx ON workflow_service_call_dispatches (state, repair_available_at);
         ALTER TABLE workflow_worker_sessions ADD COLUMN lease_owner_incarnation character(64);
         ALTER TABLE workflow_durable_streams ADD COLUMN reclaim_at timestamp(6) without time zone;
         CREATE INDEX wf_durable_streams_ns_reclaim_idx ON workflow_durable_streams (namespace, reclaim_at, id);";

    /// PHP 2.11.8's pending external payload deletions, as a published 2.11.8
    /// bootstrap creates them on PostgreSQL.
    const RELEASE_2118: &str = "CREATE TABLE runtime_external_payload_deletions (id varchar(26) NOT NULL, namespace varchar(191) NOT NULL, storage_uri text NOT NULL, storage_uri_sha256 varchar(64) NOT NULL, storage_policy json NOT NULL, attempts integer NOT NULL DEFAULT '0', failure_code varchar(255), created_at timestamp(0) without time zone, updated_at timestamp(0) without time zone, PRIMARY KEY (id));
         ALTER TABLE runtime_external_payload_deletions ADD CONSTRAINT runtime_payload_deletions_namespace_uri_unique UNIQUE (namespace, storage_uri_sha256);
         CREATE INDEX runtime_payload_deletions_namespace_created_index ON runtime_external_payload_deletions (namespace, created_at);";

    /// The frozen PHP layout with a release's changes applied in order.
    async fn php_release(db: &Database, changes: &[&'static str]) -> PgConnection {
        let frozen = include_str!("../../migrations/postgres/0003_full_schema.sql");
        let php = &frozen[..frozen.find("-- Native bookkeeping").unwrap()];
        let mut connection = db.connection().await;
        sqlx::raw_sql(php).execute(&mut connection).await.unwrap();
        for change in changes {
            sqlx::raw_sql(*change).execute(&mut connection).await.unwrap();
        }
        connection
    }

    /// PHP 2.11.1, fresh and as a 2.9.7 database upgrades to it: the trace
    /// carriers before or after 2.9.7's delivery reference.
    #[tokio::test]
    #[ignore = "requires a disposable PostgreSQL; run the qualification_ tests explicitly"]
    async fn qualification_takeover_converts_php_2111_and_native_opens_it() {
        for (release, changes) in [
            ("php-2.11.1", [PHP_280_CHANGES, TRACE_CARRIERS, QUEUE_DELIVERY]),
            ("php-2.11.1-from-2.9.7", [PHP_280_CHANGES, QUEUE_DELIVERY, TRACE_CARRIERS]),
        ] {
            let db = Database::new().await;
            let mut connection = php_release(&db, &changes).await;
            sqlx::raw_sql(
                "INSERT INTO workflow_instances(id,workflow_class,workflow_type,namespace,current_run_id,run_count) VALUES ('traced','Traced','traced','default','01jrun0000000000000000000a',1);
                 INSERT INTO workflow_runs(id,workflow_instance_id,run_number,workflow_class,workflow_type,namespace,status,observability_context) VALUES ('01jrun0000000000000000000a','traced',1,'Traced','traced','default','completed','{\"traceparent\":\"00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01\"}');
                 INSERT INTO workflow_tasks(id,workflow_run_id,namespace,task_type,status,observability_context) VALUES ('01jtask000000000000000000a','01jrun0000000000000000000a','default','workflow','completed','{\"traceparent\":\"00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01\"}');
                 INSERT INTO workflow_worker_registrations(worker_id,namespace,task_queue,runtime,registration_token,created_at,updated_at) VALUES ('current','default','q','php','0123456789abcdef0123456789abcdef','2026-10-20 09:00:00','2026-10-20 09:00:00');
                 INSERT INTO workflow_worker_registration_incarnations VALUES ('0123456789abcdef0123456789abcdef','default','current','active',0,NULL,'2026-10-20 09:00:00','2026-10-20 09:00:00');
                 INSERT INTO workflow_worker_sessions(namespace,session_id,queue,status,lease_owner,lease_expires_at,ttl_expires_at,created_at,updated_at) VALUES ('default','held','q','active','current','2030-01-01 00:00:00','2030-01-01 00:00:00','2026-10-20 09:00:00','2026-10-20 09:00:00');
                 INSERT INTO workflow_service_calls(id,namespace,endpoint_name,service_name,operation_name,caller_namespace,target_namespace,status,outcome,operation_mode,accepted_at,call_deadline_at,created_at,updated_at) VALUES ('01m5wvy8d1j3dtr13es05kcall','default','pay','billing','charge','default','default','accepted','accepted','async','2026-10-26 10:00:00','2027-02-03 04:05:06.5','2026-10-26 10:00:00','2026-10-26 10:00:00');",
            )
            .execute(&mut connection)
            .await
            .unwrap();
            for statement in crate::runtime::runtime_credentials::PHP_CREDENTIAL_ROWS {
                sqlx::raw_sql(statement).execute(&mut connection).await.unwrap();
            }
            connection.close().await.unwrap();
            let fingerprint = backup_fingerprint(&db).await;
            let report = takeover::take_over_postgres(db.options.clone(), &fingerprint, &TAKEOVER_QUEUES)
                .await
                .unwrap();
            assert_eq!(report["status"], "taken_over", "{report}");
            assert_eq!(report["source_layout"], release);
            assert_eq!(report["converted"]["observability_contexts_removed"], 2, "{report}");
            assert_eq!(report["converted"]["session_holders_recorded"], 1, "{report}");
            assert_eq!(report["converted"]["service_call_deadlines_copied"], 1, "{report}");
            let mut connection = db.connection().await;
            let deadline: (String, String) = sqlx::query_as("SELECT namespace,to_char(call_deadline_at,'YYYY-MM-DD HH24:MI:SS.US') FROM dw_service_call_deadlines WHERE service_call_id='01m5wvy8d1j3dtr13es05kcall'")
                .fetch_one(&mut connection).await.unwrap();
            assert_eq!(deadline, ("default".to_owned(), "2027-02-03 04:05:06.500000".to_owned()));
            let holder: String = sqlx::query_scalar("SELECT incarnation FROM dw_worker_session_holders WHERE namespace='default' AND session_id='held'")
                .fetch_one(&mut connection).await.unwrap();
            assert_eq!(holder, super::super::worker_sessions::incarnation_digest("0123456789abcdef0123456789abcdef"));
            let carriers: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND column_name IN ('observability_context','queue_delivery_reference')")
                .fetch_one(&mut connection).await.unwrap();
            assert_eq!(carriers, 0, "{release}: the PHP-only columns are gone");
            connection.close().await.unwrap();
            let storage = PostgresStorage::open(db.options.clone()).await.unwrap();
            storage.close().await;
            PostgresStorage::check(db.options.clone()).await.unwrap();
            let runtime = crate::runtime::Runtime::open_postgres(db.options.clone(), "test-token".into()).await.unwrap();
            crate::runtime::runtime_credentials::assert_php_credentials_served(&runtime).await;
            runtime.close().await;
            db.remove().await;
        }
    }

    #[tokio::test]
    #[ignore = "requires a disposable PostgreSQL; run the qualification_ tests explicitly"]
    async fn qualification_takeover_converts_php_2113_and_native_opens_it() {
        for (release, changes) in [
            ("php-2.11.3", [PHP_280_CHANGES, TRACE_CARRIERS, QUEUE_DELIVERY, RELEASE_2113]),
            ("php-2.11.3-from-2.9.7", [PHP_280_CHANGES, QUEUE_DELIVERY, TRACE_CARRIERS, RELEASE_2113]),
        ] {
            let db = Database::new().await;
            let mut connection = php_release(&db, &changes).await;
            let recorded = super::super::worker_sessions::incarnation_digest("0123456789abcdef0123456789abcdef");
            for statement in [
                "INSERT INTO workflow_worker_registrations(worker_id,namespace,task_queue,runtime,registration_token,created_at,updated_at) VALUES ('current','default','q','php','0123456789abcdef0123456789abcdef','2026-10-20 09:00:00','2026-10-20 09:00:00')".to_owned(),
                "INSERT INTO workflow_worker_registration_incarnations VALUES ('0123456789abcdef0123456789abcdef','default','current','active',0,NULL,'2026-10-20 09:00:00','2026-10-20 09:00:00')".to_owned(),
                format!("INSERT INTO workflow_worker_sessions(namespace,session_id,queue,status,lease_owner,lease_owner_incarnation,lease_expires_at,ttl_expires_at,created_at,updated_at) VALUES ('default','held','q','active','current','{recorded}','2030-01-01 00:00:00','2030-01-01 00:00:00','2026-10-20 09:00:00','2026-10-20 09:00:00')"),
                "INSERT INTO workflow_failures(id,workflow_run_id,source_kind,source_id,propagation_kind,exception_class,message,file,message_full,created_at,updated_at) VALUES ('01m5wvy8d1j3dtr13es05kfail','01jrun0000000000000000000a','activity_execution','a','activity','RuntimeException','preview','','base64:ZnVsbA==','2026-10-26 10:00:00','2026-10-26 10:00:00')".to_owned(),
                "INSERT INTO workflow_service_calls(id,namespace,endpoint_name,service_name,operation_name,caller_namespace,target_namespace,status,outcome,operation_mode,idempotency_key,idempotency_scope_hash,accepted_at,completed_at,created_at,updated_at) VALUES ('01m5wvy8d1j3dtr13es05kcall','default','pay','billing','charge','default','default','completed','succeeded','async','order-1','0000000000000000000000000000000000000000000000000000000000000001','2026-10-26 10:00:00','2026-10-26 10:00:01','2026-10-26 10:00:00','2026-10-26 10:00:01')".to_owned(),
                "INSERT INTO workflow_service_call_dispatches(service_call_id,state,dispatch_options,available_at,completed_at,created_at,updated_at) VALUES ('01m5wvy8d1j3dtr13es05kcall','completed','{}','2026-10-26 10:00:00','2026-10-26 10:00:01','2026-10-26 10:00:00','2026-10-26 10:00:01')".to_owned(),
            ] {
                sqlx::raw_sql(sqlx::AssertSqlSafe(statement)).execute(&mut connection).await.unwrap();
            }
            connection.close().await.unwrap();
            let fingerprint = backup_fingerprint(&db).await;
            let report = takeover::take_over_postgres(db.options.clone(), &fingerprint, &TAKEOVER_QUEUES)
                .await
                .unwrap();
            assert_eq!(report["status"], "taken_over", "{report}");
            assert_eq!(report["source_layout"], release);
            assert_eq!(report["converted"]["session_holders_recorded"], 1, "{report}");
            assert_eq!(report["converted"]["full_failure_messages_removed"], 1, "{report}");
            assert_eq!(report["converted"]["service_call_dispatches_removed"], 1, "{report}");
            let mut connection = db.connection().await;
            let holder: String = sqlx::query_scalar("SELECT incarnation FROM dw_worker_session_holders WHERE namespace='default' AND session_id='held'")
                .fetch_one(&mut connection).await.unwrap();
            assert_eq!(holder, recorded);
            let left: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND (table_name,column_name) IN (('workflow_failures','message_full'),('workflow_service_calls','idempotency_scope_hash'),('workflow_worker_sessions','lease_owner_incarnation'),('workflow_durable_streams','reclaim_at'))")
                .fetch_one(&mut connection).await.unwrap();
            let dispatches: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_name='workflow_service_call_dispatches'")
                .fetch_one(&mut connection).await.unwrap();
            assert_eq!((left, dispatches), (0, 0), "{release}: PHP 2.11.3's additions are gone");
            connection.close().await.unwrap();
            let storage = PostgresStorage::open(db.options.clone()).await.unwrap();
            storage.close().await;
            PostgresStorage::check(db.options.clone()).await.unwrap();
            db.remove().await;
        }
    }

    #[tokio::test]
    #[ignore = "requires a disposable PostgreSQL; run the qualification_ tests explicitly"]
    async fn qualification_takeover_carries_php_2118_pending_deletions_over() {
        for (release, changes) in [
            ("php-2.11.8", [PHP_280_CHANGES, TRACE_CARRIERS, QUEUE_DELIVERY, RELEASE_2113, RELEASE_2118]),
            ("php-2.11.8-from-2.9.7", [PHP_280_CHANGES, QUEUE_DELIVERY, TRACE_CARRIERS, RELEASE_2113, RELEASE_2118]),
        ] {
            let db = Database::new().await;
            let mut connection = php_release(&db, &changes).await;
            for statement in [
                "INSERT INTO workflow_worker_registrations(worker_id,namespace,task_queue,runtime,registration_token,created_at,updated_at) VALUES ('current','default','q','php','0123456789abcdef0123456789abcdef','2026-10-20 09:00:00','2026-10-20 09:00:00')",
                "INSERT INTO workflow_worker_registration_incarnations VALUES ('0123456789abcdef0123456789abcdef','default','current','active',0,NULL,'2026-10-20 09:00:00','2026-10-20 09:00:00')",
                r#"INSERT INTO runtime_external_payload_deletions VALUES ('01m5wvy8d1j3dtr13es05kdel1','default','file:///payloads/default/a','aaaa','{"driver":"local","enabled":true}',0,NULL,'2026-11-01 10:00:00','2026-11-01 10:00:00')"#,
                r#"INSERT INTO runtime_external_payload_deletions VALUES ('01m5wvy8d1j3dtr13es05kdel2','gone','file:///payloads/gone/b','bbbb','{"driver":"local"}',3,'backup_hold','2026-11-01 09:00:00','2026-11-01 09:30:00')"#,
            ] {
                sqlx::raw_sql(statement).execute(&mut connection).await.unwrap();
            }
            connection.close().await.unwrap();
            let fingerprint = backup_fingerprint(&db).await;
            let report = takeover::take_over_postgres(db.options.clone(), &fingerprint, &TAKEOVER_QUEUES)
                .await
                .unwrap();
            assert_eq!(report["status"], "taken_over", "{report}");
            assert_eq!(report["source_layout"], release);
            assert_eq!(report["converted"]["external_payload_deletions_copied"], 2, "{report}");
            let mut connection = db.connection().await;
            let copied: Vec<(String, i64, Option<String>, String, String)> = sqlx::query_as("SELECT id,attempts,failure_code,storage_policy,to_char(updated_at,'YYYY-MM-DD HH24:MI:SS') FROM dw_external_payload_deletions ORDER BY id")
                .fetch_all(&mut connection).await.unwrap();
            assert_eq!(copied.len(), 2, "{release}");
            assert_eq!((copied[0].1, copied[0].2.as_deref()), (0, None));
            assert_eq!(serde_json::from_str::<Value>(&copied[0].3).unwrap(), serde_json::json!({"driver":"local","enabled":true}));
            assert_eq!((copied[1].1, copied[1].2.as_deref(), copied[1].4.as_str()), (3, Some("backup_hold"), "2026-11-01 09:30:00"));
            let left: bool = sqlx::query_scalar("SELECT to_regclass('public.runtime_external_payload_deletions') IS NOT NULL")
                .fetch_one(&mut connection).await.unwrap();
            assert!(!left, "{release}: PHP's table is gone");
            connection.close().await.unwrap();
            PostgresStorage::check(db.options.clone()).await.unwrap();
            db.remove().await;
        }
    }

    async fn backup_fingerprint(db: &Database) -> String {
        fingerprint::fingerprint_postgres(db.options.clone())
            .await
            .unwrap()["fingerprint"]
            .as_str()
            .unwrap()
            .to_owned()
    }

    /// Every row and the physical schema, for byte-for-byte comparisons.
    async fn source_state(db: &Database) -> (Catalog, String) {
        let mut connection = db.connection().await;
        let state = (
            catalog(&mut connection).await.unwrap(),
            backup_fingerprint(db).await,
        );
        connection.close().await.unwrap();
        state
    }

    fn takeover_refused(result: Result<Value>, expected: &str) {
        match result {
            Err(super::super::RuntimeError::Refused { reason, .. }) => assert_eq!(reason, expected),
            Err(error) => panic!("unexpected takeover error: {error}"),
            Ok(report) => panic!("takeover was accepted: {report}"),
        }
    }

    #[tokio::test]
    #[ignore = "requires a disposable PostgreSQL; run the qualification_ tests explicitly"]
    async fn qualification_takeover_converts_php_297_and_native_opens_it() {
        let db = Database::new().await;
        let mut connection = php_297(&db).await;
        sqlx::raw_sql(
            "INSERT INTO workflow_worker_registrations(worker_id,namespace,task_queue,runtime,registration_token,created_at,updated_at) VALUES ('current','default','q','php','0123456789abcdef0123456789abcdef','2026-10-20 09:00:00','2026-10-20 09:00:00');
             INSERT INTO workflow_worker_registrations(worker_id,namespace,task_queue,runtime,created_at,updated_at) VALUES ('older','default','q','php','2026-10-20 09:00:00','2026-10-20 09:00:00');
             INSERT INTO workflow_worker_registration_incarnations VALUES ('0123456789abcdef0123456789abcdef','default','current','active',0,NULL,'2026-10-20 09:00:00','2026-10-20 09:00:00');
             INSERT INTO workflow_worker_registration_incarnations VALUES ('11111111111111111111111111111111','default','current','superseded',0,'2026-10-20 08:00:00','2026-10-20 07:00:00','2026-10-20 08:00:00');
             INSERT INTO workflow_worker_registration_incarnations VALUES ('22222222222222222222222222222222','default','gone','deregistered',3,'2026-10-20 08:30:00','2026-10-20 07:00:00','2026-10-20 08:30:00');",
        )
        .execute(&mut connection)
        .await
        .unwrap();
        connection.close().await.unwrap();
        let fingerprint = backup_fingerprint(&db).await;
        let report = takeover::take_over_postgres(db.options.clone(), &fingerprint, &TAKEOVER_QUEUES)
            .await
            .unwrap();
        assert_eq!(report["status"], "taken_over", "{report}");
        assert_eq!(report["backend"], "pgsql");
        assert_eq!(report["source_layout"], "php-2.9.7");
        assert_eq!(report["converted"]["incarnations_copied"], 3, "{report}");
        assert_eq!(report["converted"]["incarnations_created"], 1, "{report}");
        let mut connection = db.connection().await;
        let incarnations: Vec<(String, String, String, Option<i64>, bool)> = sqlx::query_as(
            "SELECT token,worker_id,status,recovered_workflow_task_count,finished_at IS NOT NULL FROM dw_worker_registration_incarnations ORDER BY worker_id,status",
        )
        .fetch_all(&mut connection)
        .await
        .unwrap();
        assert_eq!(incarnations.len(), 4, "{incarnations:?}");
        // The current worker keeps its PHP token; the retired ones keep their state.
        assert!(incarnations.contains(&(
            "0123456789abcdef0123456789abcdef".into(),
            "current".into(),
            "active".into(),
            None,
            false
        )));
        assert!(incarnations.contains(&(
            "22222222222222222222222222222222".into(),
            "gone".into(),
            "deregistered".into(),
            Some(3),
            true
        )));
        assert!(incarnations.iter().any(|row| row.1 == "current" && row.2 == "superseded" && row.3.is_none() && row.4));
        assert!(incarnations.iter().any(|row| row.1 == "older" && row.2 == "active" && row.0.len() == 32));
        connection.close().await.unwrap();
        let storage = PostgresStorage::open(db.options.clone()).await.unwrap();
        storage.close().await;
        PostgresStorage::check(db.options.clone()).await.unwrap();
        // A second takeover finds a native database.
        takeover_refused(
            takeover::take_over_postgres(db.options.clone(), &fingerprint, &TAKEOVER_QUEUES).await,
            "upgrade_source_already_owned",
        );
        db.remove().await;
    }

    #[tokio::test]
    #[ignore = "requires a disposable PostgreSQL; run the qualification_ tests explicitly"]
    async fn qualification_takeover_keeps_php_catalog_rows_that_native_then_serves() {
        let db = Database::new().await;
        let mut connection = php_297(&db).await;
        for statement in crate::runtime::service_endpoints::PHP_CATALOG_ROWS {
            sqlx::raw_sql(statement).execute(&mut connection).await.unwrap();
        }
        connection.close().await.unwrap();
        let fingerprint = backup_fingerprint(&db).await;
        let report = takeover::take_over_postgres(db.options.clone(), &fingerprint, &TAKEOVER_QUEUES).await.unwrap();
        assert_eq!(report["status"], "taken_over", "{report}");
        let runtime = crate::runtime::Runtime::open_postgres(db.options.clone(), "test-token".into()).await.unwrap();
        crate::runtime::service_endpoints::assert_php_catalog_served(&runtime).await;
        runtime.close().await;
        db.remove().await;
    }

    #[tokio::test]
    #[ignore = "requires a disposable PostgreSQL; run the qualification_ tests explicitly"]
    async fn qualification_takeover_of_the_frozen_layout_adds_only_native_relations() {
        let db = Database::new().await;
        let frozen = include_str!("../../migrations/postgres/0003_full_schema.sql");
        let mut connection = db.connection().await;
        sqlx::raw_sql(&frozen[..frozen.find("-- Native bookkeeping").unwrap()])
            .execute(&mut connection)
            .await
            .unwrap();
        connection.close().await.unwrap();
        let fingerprint = backup_fingerprint(&db).await;
        let report = takeover::take_over_postgres(db.options.clone(), &fingerprint, &TAKEOVER_QUEUES)
            .await
            .unwrap();
        assert_eq!(report["source_layout"], "frozen", "{report}");
        PostgresStorage::check(db.options.clone()).await.unwrap();
        db.remove().await;
    }

    #[tokio::test]
    #[ignore = "requires a disposable PostgreSQL; run the qualification_ tests explicitly"]
    async fn qualification_takeover_refusals_leave_the_php_database_unchanged() {
        let db = Database::new().await;
        php_297(&db).await.close().await.unwrap();
        let fingerprint = backup_fingerprint(&db).await;
        let original = source_state(&db).await;
        takeover_refused(
            takeover::take_over_postgres(db.options.clone(), &format!("sha256:{}", "0".repeat(64)), &TAKEOVER_QUEUES).await,
            "upgrade_backup_fingerprint_mismatch",
        );
        takeover_refused(
            takeover::take_over_postgres(db.options.clone(), "not-a-fingerprint", &TAKEOVER_QUEUES).await,
            "upgrade_backup_fingerprint_required",
        );
        assert_eq!(source_state(&db).await, original);
        // A PHP connection still holding a table refuses the takeover within
        // the lock wait instead of stalling it.
        let mut php = db.connection().await;
        sqlx::raw_sql("BEGIN; LOCK TABLE jobs IN ROW EXCLUSIVE MODE")
            .execute(&mut php)
            .await
            .unwrap();
        takeover_refused(
            takeover::take_over_postgres(db.options.clone(), &fingerprint, &TAKEOVER_QUEUES).await,
            "upgrade_source_in_use",
        );
        sqlx::raw_sql("ROLLBACK").execute(&mut php).await.unwrap();
        // A write after the backup changes the digest, so the old one is refused.
        sqlx::raw_sql("INSERT INTO migrations(migration,batch) VALUES ('after-backup',9)")
            .execute(&mut php)
            .await
            .unwrap();
        let written = source_state(&db).await;
        takeover_refused(
            takeover::take_over_postgres(db.options.clone(), &fingerprint, &TAKEOVER_QUEUES).await,
            "upgrade_backup_fingerprint_mismatch",
        );
        assert_eq!(source_state(&db).await, written);
        php.close().await.unwrap();
        db.remove().await;
        for (statement, expected) in [
            (
                "INSERT INTO runtime_external_payloads(id,namespace,storage_uri,storage_uri_sha256,codec,sha256,size_bytes) VALUES ('p','default','s3://b/k','x','avro','y',1)",
                "upgrade_source_external_payloads_unsupported",
            ),
            (
                // Native runs cron schedules; search attributes are still refused.
                "INSERT INTO workflow_schedules(id,schedule_id,namespace,spec,action,status,overlap_policy,jitter_seconds,search_attributes) VALUES ('s','nightly','default','{\"cron_expressions\":[\"0 2 * * *\"],\"timezone\":\"UTC\"}','{\"workflow_type\":\"echo\",\"task_queue\":\"default\"}','active','skip',0,'{\"Customer\":\"acme\"}')",
                "upgrade_source_schedules_unsupported",
            ),
        ] {
            let db = Database::new().await;
            let mut connection = php_297(&db).await;
            sqlx::raw_sql(statement).execute(&mut connection).await.unwrap();
            connection.close().await.unwrap();
            let fingerprint = backup_fingerprint(&db).await;
            let before = source_state(&db).await;
            takeover_refused(
                takeover::take_over_postgres(db.options.clone(), &fingerprint, &TAKEOVER_QUEUES).await,
                expected,
            );
            assert_eq!(source_state(&db).await, before, "{expected}");
            db.remove().await;
        }
    }

    #[tokio::test]
    #[ignore = "only invoked as a real subprocess by the takeover interruption qualification"]
    async fn takeover_child() {
        let url = std::env::var("DW_TAKEOVER_TEST_DATABASE").unwrap();
        let fingerprint = std::env::var("DW_TAKEOVER_TEST_FINGERPRINT").unwrap();
        // Parks just before the commit, where the parent kills this process.
        let report = takeover::take_over_postgres(
            PgConnectOptions::from_str(&url).unwrap(),
            &fingerprint,
            &TAKEOVER_QUEUES,
        )
        .await;
        panic!("takeover returned instead of parking: {report:?}");
    }

    #[cfg(unix)]
    #[tokio::test]
    #[ignore = "requires a disposable PostgreSQL; run the qualification_ tests explicitly"]
    async fn qualification_takeover_killed_before_commit_leaves_php_and_retries() {
        use std::os::unix::process::ExitStatusExt;
        let db = Database::new().await;
        php_297(&db).await.close().await.unwrap();
        let fingerprint = backup_fingerprint(&db).await;
        let original = source_state(&db).await;
        let directory = tempfile::tempdir().unwrap();
        let receipt = directory.path().join("boundary");
        let mut child = Command::new(std::env::current_exe().unwrap())
            .args([
                "--exact",
                "runtime::postgres::tests::takeover_child",
                "--ignored",
                "--nocapture",
            ])
            .env("DW_TAKEOVER_TEST_DATABASE", &db.url)
            .env("DW_TAKEOVER_TEST_FINGERPRINT", &fingerprint)
            .env("DW_TAKEOVER_TEST_RECEIPT", &receipt)
            .spawn()
            .unwrap();
        let deadline = tokio::time::Instant::now() + Duration::from_secs(60);
        while !receipt.exists() && tokio::time::Instant::now() < deadline {
            if let Some(status) = child.try_wait().unwrap() {
                panic!("child exited before the commit point: {status}");
            }
            tokio::time::sleep(Duration::from_millis(10)).await;
        }
        if !receipt.exists() {
            child.kill().unwrap();
            child.wait().unwrap();
            panic!("child did not reach the commit point");
        }
        assert_eq!(std::fs::read_to_string(&receipt).unwrap(), "before-commit");
        child.kill().unwrap();
        assert_eq!(child.wait().unwrap().signal(), Some(9));
        // PostgreSQL ends the killed session's transaction and its locks.
        let released = tokio::time::Instant::now() + Duration::from_secs(15);
        loop {
            let mut connection = db.connection().await;
            let sessions: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM pg_stat_activity WHERE datname=current_database() AND pid<>pg_backend_pid()")
                .fetch_one(&mut connection)
                .await
                .unwrap();
            connection.close().await.unwrap();
            if sessions == 0 {
                break;
            }
            assert!(tokio::time::Instant::now() < released, "killed takeover session survived");
            tokio::time::sleep(Duration::from_millis(50)).await;
        }
        assert_eq!(source_state(&db).await, original, "partial takeover survived");
        // The same verified backup fingerprint still takes the database over.
        let report = takeover::take_over_postgres(db.options.clone(), &fingerprint, &TAKEOVER_QUEUES)
            .await
            .unwrap();
        assert_eq!(report["status"], "taken_over", "{report}");
        PostgresStorage::check(db.options.clone()).await.unwrap();
        db.remove().await;
    }
}
