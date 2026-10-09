//! MySQL/MariaDB initialization and ownership checks for the native runtime.
//! DDL implicitly commits: only unchanged native initialization can resume.
use super::{Result, refuse};
use axum::http::StatusCode;
use sqlx::{
    AssertSqlSafe, Connection, Executor, MySqlConnection, MySqlPool, Row, SqlSafeStr,
    migrate::{Migration, MigrationType, Migrator},
    mysql::{MySqlConnectOptions, MySqlPoolOptions},
};
use std::{collections::BTreeMap, sync::OnceLock, time::Duration};

pub const VERSION: i64 = 3;
type Catalog = Vec<(String, String)>;

pub const MARKER_SQL: &str = "CREATE TABLE dw_server_schema (engine VARCHAR(64) NOT NULL PRIMARY KEY,version BIGINT NOT NULL,bootstrap_checksum VARBINARY(48) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin";
pub const LEDGER_SQL: &str = "CREATE TABLE IF NOT EXISTS _sqlx_migrations (version BIGINT PRIMARY KEY,description TEXT NOT NULL,installed_on TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,success BOOLEAN NOT NULL,checksum BLOB NOT NULL,execution_time BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

#[derive(Clone, Copy)]
enum Flavor {
    MySql,
    MariaDb,
}
impl Flavor {
    async fn detect(connection: &mut MySqlConnection) -> Result<Self> {
        let version: String = sqlx::query_scalar("SELECT VERSION()")
            .fetch_one(connection)
            .await?;
        Ok(if version.contains("MariaDB") {
            Self::MariaDb
        } else {
            Self::MySql
        })
    }
    fn migrator(self) -> &'static Migrator {
        static MYSQL: OnceLock<Migrator> = OnceLock::new();
        static MARIA: OnceLock<Migrator> = OnceLock::new();
        let (slot, sql) = match self {
            Self::MySql => (
                &MYSQL,
                include_str!("../../migrations/mysql/0003_full_schema.sql"),
            ),
            Self::MariaDb => (
                &MARIA,
                include_str!("../../migrations/mariadb/0003_full_schema.sql"),
            ),
        };
        slot.get_or_init(|| {
            let mut migrations = Migrator::with_migrations(vec![Migration::new(
                VERSION,
                "full frozen PHP schema and native receipts".into(),
                MigrationType::Simple,
                sql.into_sql_str(),
                false,
            )]);
            // A bounded named lock on this dedicated initialization connection
            // covers preflight, DDL, resumption and final marking.
            migrations.set_locking(false);
            migrations
        })
    }
    fn expected(self) -> Result<Catalog> {
        Ok(serde_json::from_str(match self {
            Self::MySql => include_str!("../../migrations/mysql/catalog.json"),
            Self::MariaDb => include_str!("../../migrations/mariadb/catalog.json"),
        })?)
    }
    fn checksum(self) -> &'static [u8] {
        self.migrator().iter().next().unwrap().checksum.as_ref()
    }
}

fn options(options: MySqlConnectOptions) -> MySqlConnectOptions {
    options
        .charset("utf8mb4")
        .collation("utf8mb4_unicode_ci")
        .timezone(Some("+00:00".into()))
}
async fn configure(connection: &mut MySqlConnection) -> Result<()> {
    sqlx::query("SET sql_quote_show_create=1,lock_wait_timeout=5,innodb_lock_wait_timeout=5")
        .execute(connection)
        .await?;
    Ok(())
}

fn table_sql(prefix: &str, table: &str, suffix: &str) -> Result<AssertSqlSafe<String>> {
    if table.is_empty()
        || !table
            .bytes()
            .all(|c| c.is_ascii_alphanumeric() || c == b'_')
    {
        return Err(refuse(
            StatusCode::CONFLICT,
            "native_schema_catalog_mismatch",
        ));
    }
    // Only database-owned ASCII identifiers are interpolated, quoted. All
    // request data in the execution runtime continues to use bound parameters.
    Ok(AssertSqlSafe(format!("{prefix}`{table}`{suffix}")))
}

pub fn definition(sql: String) -> String {
    let Some(footer) = sql.rfind(") ENGINE=") else {
        return sql;
    };
    let Some(relative) = sql[footer..].find(" AUTO_INCREMENT=") else {
        return sql;
    };
    let start = footer + relative;
    if sql[footer..]
        .find(" COMMENT=")
        .is_some_and(|comment| footer + comment < start)
    {
        return sql;
    }
    let mut end = start + " AUTO_INCREMENT=".len();
    while sql.as_bytes().get(end).is_some_and(u8::is_ascii_digit) {
        end += 1;
    }
    format!("{}{}", &sql[..start], &sql[end..])
}

async fn scope(connection: &mut MySqlConnection) -> Result<Vec<String>> {
    let tables:Vec<(String,String)>=sqlx::query_as("SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME").fetch_all(&mut *connection).await?;
    let stored:i64=sqlx::query_scalar("SELECT (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE())+(SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE())+(SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE())").fetch_one(connection).await?;
    if stored != 0 || tables.iter().any(|(_, kind)| kind != "BASE TABLE") {
        return Err(refuse(
            StatusCode::CONFLICT,
            "existing_database_requires_qualified_takeover",
        ));
    }
    Ok(tables.into_iter().map(|(name, _)| name).collect())
}
async fn catalog(connection: &mut MySqlConnection) -> Result<Catalog> {
    let mut result = vec![];
    for name in scope(connection).await? {
        let row = sqlx::query(table_sql("SHOW CREATE TABLE ", &name, "")?)
            .fetch_one(&mut *connection)
            .await?;
        result.push((name, definition(row.try_get(1)?)));
    }
    Ok(result)
}

async fn history(
    connection: &mut MySqlConnection,
    flavor: Flavor,
    initializing: bool,
) -> Result<Option<bool>> {
    let markers: Vec<(String, i64, Vec<u8>)> =
        sqlx::query_as("SELECT engine,version,bootstrap_checksum FROM dw_server_schema")
            .fetch_all(&mut *connection)
            .await?;
    let engine = if initializing {
        "rust-initializing"
    } else {
        "rust-development"
    };
    if markers.len() != 1
        || markers[0].0 != engine
        || markers[0].1 != VERSION
        || markers[0].2 != flavor.checksum()
    {
        return Err(refuse(StatusCode::CONFLICT, "unsupported_rust_schema"));
    }
    let tables = scope(connection).await?;
    if !tables.iter().any(|name| name == "_sqlx_migrations") {
        if initializing {
            return Ok(None);
        }
        return Err(refuse(
            StatusCode::CONFLICT,
            "native_migration_history_mismatch",
        ));
    }
    let applied: Vec<(i64, bool, Vec<u8>)> =
        sqlx::query_as("SELECT version,success,checksum FROM _sqlx_migrations ORDER BY version")
            .fetch_all(connection)
            .await?;
    if initializing && applied.is_empty() {
        return Ok(None);
    }
    if applied.len() != 1
        || applied[0].0 != VERSION
        || applied[0].2 != flavor.checksum()
        || (!initializing && !applied[0].1)
    {
        return Err(refuse(
            StatusCode::CONFLICT,
            "native_migration_history_mismatch",
        ));
    }
    Ok(Some(applied[0].1))
}

pub(super) async fn verify_history(connection: &mut MySqlConnection) -> Result<()> {
    let flavor = Flavor::detect(connection).await?;
    history(connection, flavor, false).await?;
    Ok(())
}

async fn verify(connection: &mut MySqlConnection, flavor: Flavor) -> Result<()> {
    history(connection, flavor, false).await?;
    if catalog(connection).await? != flavor.expected()? {
        return Err(refuse(
            StatusCode::CONFLICT,
            "native_schema_catalog_mismatch",
        ));
    }
    Ok(())
}

async fn verify_initializing(
    connection: &mut MySqlConnection,
    flavor: Flavor,
) -> Result<Option<bool>> {
    let actual = catalog(connection).await?;
    let expected: BTreeMap<_, _> = flavor.expected()?.into_iter().collect();
    // A partial bootstrap may contain only exact compiled relations. Never
    // repair a changed table/index/default, adopt rows, or erase unknown history.
    for (name, ddl) in &actual {
        if expected.get(name) != Some(ddl) {
            return Err(refuse(
                StatusCode::CONFLICT,
                "native_schema_catalog_mismatch",
            ));
        }
    }
    let applied = history(connection, flavor, true).await?;
    for (name, _) in actual {
        if matches!(name.as_str(), "dw_server_schema" | "_sqlx_migrations") {
            continue;
        }
        let occupied: bool = sqlx::query_scalar(table_sql(
            "SELECT EXISTS(SELECT 1 FROM ",
            &name,
            " LIMIT 1)",
        )?)
        .fetch_one(&mut *connection)
        .await?;
        if occupied {
            return Err(refuse(
                StatusCode::CONFLICT,
                "native_initialization_contains_data",
            ));
        }
    }
    Ok(applied)
}

async fn preflight(connection: &mut MySqlConnection, flavor: Flavor) -> Result<()> {
    let tables = scope(connection).await?;
    if tables.is_empty() {
        return Ok(());
    }
    if !tables.iter().any(|name| name == "dw_server_schema") {
        return Err(refuse(
            StatusCode::CONFLICT,
            "existing_database_requires_qualified_takeover",
        ));
    }
    let engines: Vec<String> = sqlx::query_scalar("SELECT engine FROM dw_server_schema")
        .fetch_all(&mut *connection)
        .await?;
    if engines == ["rust-initializing"] {
        verify_initializing(connection, flavor).await?;
        Ok(())
    } else {
        verify(connection, flavor).await
    }
}

pub struct MySqlStorage {
    pool: MySqlPool,
}
impl MySqlStorage {
    pub(super) fn into_pool(self) -> MySqlPool {
        self.pool
    }
    pub async fn open(connect_options: MySqlConnectOptions) -> Result<Self> {
        let connect_options = options(connect_options);
        let mut read = MySqlConnection::connect_with(&connect_options).await?;
        let result = async {
            configure(&mut read).await?;
            let flavor = Flavor::detect(&mut read).await?;
            let mut tx = read.begin_with("START TRANSACTION READ ONLY").await?;
            preflight(&mut tx, flavor).await?;
            tx.rollback().await?;
            Ok::<_, super::RuntimeError>(())
        }
        .await;
        read.close().await?;
        result?;
        let mut connection = MySqlConnection::connect_with(&connect_options).await?;
        let result=async {
            configure(&mut connection).await?;
            let locked:Option<i64>=sqlx::query_scalar("SELECT GET_LOCK(CONCAT('dw-native-bootstrap:',SHA1(DATABASE())),60)").fetch_one(&mut connection).await?;
            if locked!=Some(1) {return Err(refuse(StatusCode::SERVICE_UNAVAILABLE,"native_initialization_busy"));}
            let flavor=Flavor::detect(&mut connection).await?;
            preflight(&mut connection,flavor).await?;
            if scope(&mut connection).await?.is_empty() {
                connection.execute(MARKER_SQL).await?;
                sqlx::query("INSERT INTO dw_server_schema(engine,version,bootstrap_checksum) VALUES ('rust-initializing',?,?)").bind(VERSION).bind(flavor.checksum()).execute(&mut connection).await?;
            }
            let engine:String=sqlx::query_scalar("SELECT engine FROM dw_server_schema").fetch_one(&mut connection).await?;
            if engine=="rust-initializing" {
                let applied=verify_initializing(&mut connection,flavor).await?;
                connection.execute(LEDGER_SQL).await?;
                if applied==Some(false) {
                    // SQLx correctly journals interrupted implicit-commit DDL
                    // as dirty. Resume only after the exact partial catalog,
                    // checksum and absence of application data were verified.
                    let migration=flavor.migrator().iter().next().unwrap();
                    connection.execute(migration.sql.clone()).await?;
                    sqlx::query("UPDATE _sqlx_migrations SET success=TRUE WHERE version=? AND checksum=? AND success=FALSE").bind(VERSION).bind(flavor.checksum()).execute(&mut connection).await?;
                } else {flavor.migrator().run(&mut connection).await?;}
                if catalog(&mut connection).await?!=flavor.expected()? {return Err(refuse(StatusCode::CONFLICT,"native_schema_catalog_mismatch"));}
                let mut tx=connection.begin().await?;
                sqlx::query("UPDATE dw_server_schema SET engine='rust-development' WHERE engine='rust-initializing' AND version=? AND bootstrap_checksum=?").bind(VERSION).bind(flavor.checksum()).execute(&mut *tx).await?;
                tx.commit().await?;
            }
            verify(&mut connection,flavor).await?;
            Ok::<_,super::RuntimeError>(())
        }.await;
        // Named locks are session scoped, including on errors/process death.
        connection.close().await?;
        result?;
        let pool = MySqlPoolOptions::new()
            .max_connections(4)
            .acquire_timeout(Duration::from_secs(5))
            .after_connect(|connection, _| {
                Box::pin(async move {
                    sqlx::query("SET lock_wait_timeout=5,innodb_lock_wait_timeout=5")
                        .execute(connection)
                        .await?;
                    Ok(())
                })
            })
            .connect_with(connect_options)
            .await?;
        Ok(Self { pool })
    }
    pub async fn check(connect_options: MySqlConnectOptions) -> Result<()> {
        let mut connection = MySqlConnection::connect_with(&options(connect_options)).await?;
        let result = async {
            configure(&mut connection).await?;
            let flavor = Flavor::detect(&mut connection).await?;
            let mut tx = connection.begin_with("START TRANSACTION READ ONLY").await?;
            verify(&mut tx, flavor).await?;
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
    use serde_json::{Value, json};
    use sqlx::{ConnectOptions, MySql, migrate::MigrateDatabase, types::Json};
    use std::{process::Command, str::FromStr};

    struct Database {
        options: MySqlConnectOptions,
        url: String,
    }
    impl Database {
        async fn new() -> Self {
            let url = std::env::var("DW_TEST_MYSQL_URL")
                .expect("Set DW_TEST_MYSQL_URL to an isolated create-database/create-user role");
            let name = format!("dw_schema_{}", ulid::Ulid::new().to_string().to_lowercase());
            let options = MySqlConnectOptions::from_str(&url).unwrap().database(&name);
            let url = options.to_url_lossy().to_string();
            MySql::create_database(&url).await.unwrap();
            // Serialize maintenance options before adding collation: SQLx 0.9
            // currently writes collation as a second charset URL parameter.
            Self {
                options: super::options(options),
                url,
            }
        }
        async fn connection(&self) -> MySqlConnection {
            MySqlConnection::connect_with(&self.options).await.unwrap()
        }
        async fn remove(self) {
            MySql::drop_database(&self.url).await.unwrap();
        }
    }
    fn refused(result: Result<MySqlStorage>, expected: &str) {
        match result {
            Err(super::super::RuntimeError::Refused { reason, .. }) => assert_eq!(reason, expected),
            Err(error) => panic!("unexpected qualification error: {error}"),
            Ok(_) => panic!("unsafe database accepted"),
        }
    }
    async fn initializing(connection: &mut MySqlConnection, flavor: Flavor) {
        connection.execute(MARKER_SQL).await.unwrap();
        sqlx::query("INSERT INTO dw_server_schema VALUES ('rust-initializing',?,?)")
            .bind(VERSION)
            .bind(flavor.checksum())
            .execute(connection)
            .await
            .unwrap();
    }
    async fn partial(connection: &mut MySqlConnection, flavor: Flavor) {
        let migration = flavor.migrator().iter().next().unwrap();
        // This prefix ends immediately before the third PHP relation; both
        // first relations have been acknowledged by the real database.
        let sql = migration.sql.as_ref();
        let end = sql
            .find("CREATE TABLE IF NOT EXISTS `failed_jobs`")
            .unwrap();
        connection
            .execute(AssertSqlSafe(sql[..end].to_owned()))
            .await
            .unwrap();
        sqlx::query("SET FOREIGN_KEY_CHECKS=1")
            .execute(connection)
            .await
            .unwrap();
    }
    async fn dirty(connection: &mut MySqlConnection, flavor: Flavor) {
        connection.execute(LEDGER_SQL).await.unwrap();
        sqlx::query("INSERT INTO _sqlx_migrations VALUES (?, 'interrupted qualification', CURRENT_TIMESTAMP, FALSE, ?, -1)").bind(VERSION).bind(flavor.checksum()).execute(connection).await.unwrap();
    }

    #[tokio::test]
    #[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
    async fn qualification_concurrent_bootstrap_and_reopen() {
        let db = Database::new().await;
        let (a, b, c) = tokio::join!(
            MySqlStorage::open(db.options.clone()),
            MySqlStorage::open(db.options.clone()),
            MySqlStorage::open(db.options.clone())
        );
        let (a, b, c) = (a.unwrap(), b.unwrap(), c.unwrap());
        a.close().await;
        b.close().await;
        c.close().await;
        MySqlStorage::check(db.options.clone()).await.unwrap();
        let reopened = MySqlStorage::open(db.options.clone()).await.unwrap();
        reopened.close().await;
        let mut connection = db.connection().await;
        let count: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM _sqlx_migrations")
            .fetch_one(&mut connection)
            .await
            .unwrap();
        assert_eq!(count, 1);
        connection.close().await.unwrap();
        db.remove().await;
    }

    #[tokio::test]
    #[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
    async fn qualification_empty_check_and_existing_rows_and_counter_refusal() {
        let db = Database::new().await;
        assert!(MySqlStorage::check(db.options.clone()).await.is_err());
        let mut connection = db.connection().await;
        assert!(scope(&mut connection).await.unwrap().is_empty());
        connection.execute("CREATE TABLE acknowledged_php_data (id BIGINT PRIMARY KEY AUTO_INCREMENT,payload TEXT NOT NULL) ENGINE=InnoDB").await.unwrap();
        sqlx::query("INSERT INTO acknowledged_php_data VALUES (9007199254740993,'preserve acknowledged input')").execute(&mut connection).await.unwrap();
        let before = catalog(&mut connection).await.unwrap();
        let counter:Option<u64>=sqlx::query_scalar("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='acknowledged_php_data'").fetch_one(&mut connection).await.unwrap();
        refused(
            MySqlStorage::open(db.options.clone()).await,
            "existing_database_requires_qualified_takeover",
        );
        assert_eq!(catalog(&mut connection).await.unwrap(), before);
        let after:Option<u64>=sqlx::query_scalar("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='acknowledged_php_data'").fetch_one(&mut connection).await.unwrap();
        assert_eq!(counter, after);
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
    #[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
    async fn qualification_typed_values_and_read_only_role() {
        let db = Database::new().await;
        let storage = MySqlStorage::open(db.options.clone()).await.unwrap();
        let timestamp = chrono::NaiveDateTime::parse_from_str(
            "2026-10-09 12:34:56.123456",
            "%Y-%m-%d %H:%M:%S%.f",
        )
        .unwrap()
        .and_utc();
        let payload = json!({"int64":9007199254740993_i64,"unicode":"雪 🦀","null":null,"nested":[true,{"text":"value"}]});
        sqlx::query("INSERT INTO workflow_instances(id,workflow_type,workflow_class,started_at,memo) VALUES ('typed-sentinel','echo','echo',?,?)").bind(timestamp).bind(Json(&payload)).execute(&storage.pool).await.unwrap();
        storage.close().await;
        let mut connection = db.connection().await;
        let row: (chrono::DateTime<chrono::Utc>, Json<Value>) = sqlx::query_as(
            "SELECT started_at,memo FROM workflow_instances WHERE id='typed-sentinel'",
        )
        .fetch_one(&mut connection)
        .await
        .unwrap();
        assert_eq!(row, (timestamp, Json(payload)));
        let role = format!("dw_ro_{}", ulid::Ulid::new().to_string().to_lowercase());
        let database = db.options.get_database().unwrap();
        // These identifiers contain only a fixed prefix and a ULID alphabet.
        connection.execute(AssertSqlSafe(format!("CREATE USER '{role}'@'%' IDENTIFIED BY 'parity-disposable-password'; GRANT SELECT ON `{database}`.* TO '{role}'@'%'"))).await.unwrap();
        let reader = db
            .options
            .clone()
            .username(&role)
            .password("parity-disposable-password");
        MySqlStorage::check(reader.clone()).await.unwrap();
        let mut read = MySqlConnection::connect_with(&reader).await.unwrap();
        assert!(
            sqlx::query("DELETE FROM workflow_instances")
                .execute(&mut read)
                .await
                .is_err()
        );
        read.close().await.unwrap();
        connection
            .execute(AssertSqlSafe(format!("DROP USER '{role}'@'%'")))
            .await
            .unwrap();
        connection.close().await.unwrap();
        db.remove().await;
    }

    #[tokio::test]
    #[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
    async fn qualification_corrupt_catalog_and_history_refusal() {
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
                "UPDATE dw_server_schema SET bootstrap_checksum=X'00'",
                "unsupported_rust_schema",
            ),
            (
                "UPDATE _sqlx_migrations SET checksum=X'00'",
                "native_migration_history_mismatch",
            ),
            (
                "UPDATE _sqlx_migrations SET success=FALSE",
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
                "ALTER TABLE workflow_runs MODIFY started_at TIMESTAMP(3) NULL",
                "native_schema_catalog_mismatch",
            ),
            (
                "ALTER TABLE workflow_tasks DROP INDEX dw_workflow_tasks_poll",
                "native_schema_catalog_mismatch",
            ),
            (
                "ALTER TABLE dw_task_completions DROP FOREIGN KEY dw_task_completions_task",
                "native_schema_catalog_mismatch",
            ),
            (
                "ALTER TABLE workflow_runs ALTER status SET DEFAULT 'unexpected'",
                "native_schema_catalog_mismatch",
            ),
            (
                "CREATE TABLE unexpected (id BIGINT)",
                "native_schema_catalog_mismatch",
            ),
        ] {
            let db = Database::new().await;
            let storage = MySqlStorage::open(db.options.clone()).await.unwrap();
            storage.close().await;
            let mut connection = db.connection().await;
            connection.execute(mutation).await.unwrap();
            let before = catalog(&mut connection).await.unwrap();
            let history: Vec<(i64, bool, Vec<u8>)> = sqlx::query_as(
                "SELECT version,success,checksum FROM _sqlx_migrations ORDER BY version",
            )
            .fetch_all(&mut connection)
            .await
            .unwrap();
            refused(MySqlStorage::open(db.options.clone()).await, reason);
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
            assert_eq!(history, after, "refusal changed journal: {mutation}");
            connection.close().await.unwrap();
            db.remove().await;
        }
    }

    #[tokio::test]
    #[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
    async fn qualification_changed_or_occupied_partial_initialization_is_refused() {
        for (mutation, reason) in [
            (
                "ALTER TABLE activity_attempts MODIFY started_at TIMESTAMP(3) NOT NULL",
                "native_schema_catalog_mismatch",
            ),
            (
                "INSERT INTO activity_executions(id,workflow_run_id,sequence,activity_class,activity_type,status) VALUES ('acknowledged','run',1,'echo','echo','scheduled')",
                "native_initialization_contains_data",
            ),
            (
                "UPDATE _sqlx_migrations SET checksum=X'00'",
                "native_migration_history_mismatch",
            ),
        ] {
            let db = Database::new().await;
            let mut connection = db.connection().await;
            let flavor = Flavor::detect(&mut connection).await.unwrap();
            initializing(&mut connection, flavor).await;
            dirty(&mut connection, flavor).await;
            partial(&mut connection, flavor).await;
            connection.execute(mutation).await.unwrap();
            let before = catalog(&mut connection).await.unwrap();
            refused(MySqlStorage::open(db.options.clone()).await, reason);
            assert_eq!(catalog(&mut connection).await.unwrap(), before);
            let marker: String = sqlx::query_scalar("SELECT engine FROM dw_server_schema")
                .fetch_one(&mut connection)
                .await
                .unwrap();
            assert_eq!(marker, "rust-initializing");
            connection.close().await.unwrap();
            db.remove().await;
        }
    }

    #[tokio::test]
    #[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
    async fn qualification_views_and_stored_code_cannot_look_empty() {
        for statement in [
            "CREATE VIEW unexpected AS SELECT 1 AS value",
            "CREATE FUNCTION unexpected() RETURNS INTEGER DETERMINISTIC RETURN 1",
            "CREATE EVENT unexpected ON SCHEDULE AT CURRENT_TIMESTAMP + INTERVAL 1 DAY DO SELECT 1",
            "CREATE TABLE acknowledged (id BIGINT); CREATE TRIGGER unexpected BEFORE INSERT ON acknowledged FOR EACH ROW SET NEW.id=1",
        ] {
            let db = Database::new().await;
            let mut connection = db.connection().await;
            connection.execute(statement).await.unwrap();
            refused(
                MySqlStorage::open(db.options.clone()).await,
                "existing_database_requires_qualified_takeover",
            );
            let marker:bool=sqlx::query_scalar("SELECT EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dw_server_schema')").fetch_one(&mut connection).await.unwrap();
            assert!(!marker, "refusal created a native marker: {statement}");
            connection.close().await.unwrap();
            db.remove().await;
        }
    }

    #[tokio::test]
    #[ignore = "invoked only as a subprocess by the interruption qualification"]
    async fn bootstrap_child() {
        let url = std::env::var("DW_SCHEMA_TEST_DATABASE").unwrap();
        let phase = std::env::var("DW_SCHEMA_TEST_PHASE").unwrap();
        let mut connection =
            MySqlConnection::connect_with(&options(MySqlConnectOptions::from_str(&url).unwrap()))
                .await
                .unwrap();
        configure(&mut connection).await.unwrap();
        let lock: Option<i64> = sqlx::query_scalar(
            "SELECT GET_LOCK(CONCAT('dw-native-bootstrap:',SHA1(DATABASE())),5)",
        )
        .fetch_one(&mut connection)
        .await
        .unwrap();
        assert_eq!(lock, Some(1));
        let flavor = Flavor::detect(&mut connection).await.unwrap();
        initializing(&mut connection, flavor).await;
        if phase == "dirty-partial" {
            dirty(&mut connection, flavor).await;
            partial(&mut connection, flavor).await;
        }
        if phase == "before-marker" {
            connection.execute(LEDGER_SQL).await.unwrap();
            flavor.migrator().run(&mut connection).await.unwrap();
        }
        std::fs::write(std::env::var("DW_SCHEMA_TEST_RECEIPT").unwrap(), phase).unwrap();
        loop {
            std::thread::park();
        }
    }

    #[cfg(unix)]
    #[tokio::test]
    #[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
    async fn qualification_real_kill_resumes_only_recognized_initialization() {
        use std::os::unix::process::ExitStatusExt;
        for phase in ["marker", "dirty-partial", "before-marker"] {
            let db = Database::new().await;
            let directory = tempfile::tempdir().unwrap();
            let receipt = directory.path().join("boundary");
            let mut child = Command::new(std::env::current_exe().unwrap())
                .args([
                    "--exact",
                    "runtime::mysql::tests::bootstrap_child",
                    "--ignored",
                    "--nocapture",
                ])
                .env("DW_SCHEMA_TEST_DATABASE", &db.url)
                .env("DW_SCHEMA_TEST_PHASE", phase)
                .env("DW_SCHEMA_TEST_RECEIPT", &receipt)
                .spawn()
                .unwrap();
            let deadline = tokio::time::Instant::now() + Duration::from_secs(60);
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
            let flavor = Flavor::detect(&mut connection).await.unwrap();
            let applied = verify_initializing(&mut connection, flavor).await.unwrap();
            assert_eq!(
                applied,
                match phase {
                    "marker" => None,
                    "dirty-partial" => Some(false),
                    _ => Some(true),
                }
            );
            let before: Option<(String, chrono::DateTime<chrono::Utc>)> = if applied.is_some() {
                sqlx::query_as("SELECT description,installed_on FROM _sqlx_migrations")
                    .fetch_optional(&mut connection)
                    .await
                    .unwrap()
            } else {
                None
            };
            connection.close().await.unwrap();
            let storage = MySqlStorage::open(db.options.clone()).await.unwrap();
            storage.close().await;
            MySqlStorage::check(db.options.clone()).await.unwrap();
            if let Some(before) = before {
                let mut connection = db.connection().await;
                let after: (String, chrono::DateTime<chrono::Utc>) =
                    sqlx::query_as("SELECT description,installed_on FROM _sqlx_migrations")
                        .fetch_one(&mut connection)
                        .await
                        .unwrap();
                assert_eq!(
                    before, after,
                    "resume replaced the acknowledged journal row"
                );
                connection.close().await.unwrap();
            }
            db.remove().await;
        }
    }
}
