//! MySQL/MariaDB initialization and ownership checks for the native runtime.
//! DDL implicitly commits: only unchanged native initialization can resume.
use super::{Result, refuse};

#[path = "mysql_checkpoint_catalog.rs"]
mod checkpoint_catalog;
use checkpoint_catalog::CheckpointCode;
use axum::http::StatusCode;
use sqlx::{
    AssertSqlSafe, Connection, Executor, MySqlConnection, MySqlPool, Row, SqlSafeStr,
    migrate::{Migration, MigrationType, Migrator},
    mysql::{MySqlConnectOptions, MySqlPoolOptions},
};
use std::{collections::BTreeMap, sync::OnceLock, time::Duration};

/// The native schema version on MySQL. Version 13 stores JSON documents as
/// exact text (server#1016); MariaDB already stores them as text and stayed
/// at 12. Each then adds task queue pauses (server#577): MySQL 14, MariaDB
/// 13, and keyed command intents (server#934): MySQL 15, MariaDB 14.
/// Immutable external storage bindings are MySQL 17 / MariaDB 16, child
/// supervisions MySQL 18 / MariaDB 17, and the maintenance scan indexes
/// (server#1256) MySQL 19 / MariaDB 18. Workflow task lease loss uses
/// MySQL 20 / MariaDB 19. Child summary checkpoints use MySQL 21 / MariaDB 20,
/// the same-run fence index (server#1327) MySQL 22 / MariaDB 21, and the
/// checkpoint catalog function's this-database trigger lookup (server#1339)
/// MySQL 23 / MariaDB 22. `Flavor::version` selects the one a server needs.
pub const MYSQL_VERSION: i64 = 23;
pub const MARIADB_VERSION: i64 = 22;
type Catalog = Vec<(String, String)>;
const CHILD_SUMMARY_CHECKPOINT_SCHEMA: &str = include_str!("../../migrations/mysql/0021_child_summary_checkpoints.sql");
const CHILD_SUMMARY_CALL_INDEX: &str = "CREATE INDEX dw_child_calls_run_status_sequence ON workflow_child_calls(parent_workflow_run_id,status,sequence);";

fn child_summary_checkpoint_bootstrap_sql(sql: String) -> String {
    child_summary_checkpoint_bootstrap_sql_with(sql, checkpoint_catalog::bootstrap_sql())
}

/// `code` is the checkpoint code a bootstrap ends with: the current one, or
/// the predecessor one that schemas MySQL 21-22 / MariaDB 20-21 recorded.
fn child_summary_checkpoint_bootstrap_sql_with(mut sql: String, code: &str) -> String {
    let start = sql.find("CREATE TABLE IF NOT EXISTS `workflow_child_calls` (").expect("compiled child calls");
    let key = start + sql[start..].find("  CONSTRAINT").expect("compiled child call foreign key");
    sql.insert_str(key, "  KEY `dw_child_calls_run_status_sequence` (`parent_workflow_run_id`,`status`,`sequence`),\n");
    sql = sql.replace(CHILD_SUMMARY_CALL_INDEX, "");
    sql.push('\n');
    sql.push_str(code);
    sql
}

pub const MARKER_SQL: &str = "CREATE TABLE dw_server_schema (engine VARCHAR(64) NOT NULL PRIMARY KEY,version BIGINT NOT NULL,bootstrap_checksum VARBINARY(48) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin";
/// The marker row of a takeover in progress: `rust-takeover:<layout>`, with
/// the completed step count as its version and the backup fingerprint's
/// digest as its checksum. Native startup refuses it and PHP sees the marker.
pub(super) const TAKEOVER_ENGINE: &str = "rust-takeover:";
pub const LEDGER_SQL: &str ="CREATE TABLE IF NOT EXISTS _sqlx_migrations (version BIGINT PRIMARY KEY,description TEXT NOT NULL,installed_on TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,success BOOLEAN NOT NULL,checksum BLOB NOT NULL,execution_time BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

#[derive(Clone, Copy)]
pub(super) enum Flavor {
    MySql,
    MariaDb,
}
impl Flavor {
    pub(super) async fn detect(connection: &mut MySqlConnection) -> Result<Self> {
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
                concat!(
                    include_str!("../../migrations/mysql/0003_full_schema.sql"),
                    "\n",
                    include_str!("../../migrations/mysql/0004_worker_incarnations.sql"),
                    "\n",
                    include_str!("../../migrations/mysql/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/mysql/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/mysql/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/mysql/0008_external_payload_deletions.sql"),
                "\n",
                include_str!("../../migrations/mysql/0009_task_ready_origin.sql"),
                "\n",
                include_str!("../../migrations/mysql/0010_poll_expiry_indexes.sql"),
                "\n",
                include_str!("../../migrations/mysql/0011_scoped_dispatch_index.sql"),
                "\n",
                include_str!("../../migrations/mysql/0012_run_command_contracts.sql"),
                "\n",
                include_str!("../../migrations/mysql/0013_json_text.sql"),
                "\n",
                include_str!("../../migrations/mysql/0014_task_queue_pauses.sql"),
                "\n",
                include_str!("../../migrations/mysql/0015_command_intents.sql"), "\n", include_str!("../../migrations/mysql/0016_schedule_dispatch_summary.sql"), "\n", include_str!("../../migrations/mysql/0017_external_storage_bindings.sql"), "\n", include_str!("../../migrations/mysql/0018_child_supervisions.sql"), "\n", include_str!("../../migrations/mysql/0019_maintenance_scan_indexes.sql"), "\n", include_str!("../../migrations/mysql/0020_workflow_task_lease_loss.sql"), "\n", include_str!("../../migrations/mysql/0021_child_summary_checkpoints.sql"), "\n", include_str!("../../migrations/mysql/0022_same_run_fence_index.sql")
                ),
            ),
            Self::MariaDb => (
                &MARIA,
                concat!(
                    include_str!("../../migrations/mariadb/0003_full_schema.sql"),
                    "\n",
                    include_str!("../../migrations/mariadb/0004_worker_incarnations.sql"),
                    "\n",
                    include_str!("../../migrations/mariadb/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0008_external_payload_deletions.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0009_task_ready_origin.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0010_poll_expiry_indexes.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0011_scoped_dispatch_index.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0012_run_command_contracts.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0013_task_queue_pauses.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0014_command_intents.sql"), "\n", include_str!("../../migrations/mariadb/0015_schedule_dispatch_summary.sql"), "\n", include_str!("../../migrations/mariadb/0016_external_storage_bindings.sql"), "\n", include_str!("../../migrations/mariadb/0017_child_supervisions.sql"), "\n", include_str!("../../migrations/mariadb/0018_maintenance_scan_indexes.sql"), "\n", include_str!("../../migrations/mariadb/0019_workflow_task_lease_loss.sql"), "\n", include_str!("../../migrations/mysql/0021_child_summary_checkpoints.sql"), "\n", include_str!("../../migrations/mariadb/0021_same_run_fence_index.sql")
                ),
            ),
        };
        slot.get_or_init(|| {
            let description = match self {
                Self::MySql => "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions and task ready origins and poll expiry indexes and scoped dispatch index and bound run command contracts and exact JSON text documents and task queue activity dispatch pauses and keyed command intents and bounded schedule dispatch summaries and immutable external storage bindings and child supervisions across continue-as-new and maintenance scan indexes and workflow task lease loss and child summary checkpoints and same-run fence index and child summary catalog lookup",
                Self::MariaDb => "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions and task ready origins and poll expiry indexes and scoped dispatch index and bound run command contracts and task queue activity dispatch pauses and keyed command intents and bounded schedule dispatch summaries and immutable external storage bindings and child supervisions across continue-as-new and maintenance scan indexes and workflow task lease loss and child summary checkpoints and same-run fence index and child summary catalog lookup",
            };
            let mut migrations = Migrator::with_migrations(vec![Migration::new(
                self.version(),
                description.into(),
                MigrationType::Simple,
                AssertSqlSafe(child_summary_checkpoint_bootstrap_sql(self.bootstrap_sql(sql))).into_sql_str(),
                false,
            )]);
            // A bounded named lock on this dedicated initialization connection
            // covers preflight, DDL, resumption and final marking.
            migrations.set_locking(false);
            migrations
        })
    }

    /// The schema MySQL 22 / MariaDB 21 bootstrap, byte for byte: the
    /// current relations followed by the checkpoint code with the predecessor
    /// catalog function body, which is all schema 23 / 22 changed.
    fn retained_catalog_lookup_predecessor(self) -> &'static Migrator {
        static MYSQL: OnceLock<Migrator> = OnceLock::new();
        static MARIA: OnceLock<Migrator> = OnceLock::new();
        let (slot, version, description) = match self {
            Self::MySql => (&MYSQL, 22, "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions and task ready origins and poll expiry indexes and scoped dispatch index and bound run command contracts and exact JSON text documents and task queue activity dispatch pauses and keyed command intents and bounded schedule dispatch summaries and immutable external storage bindings and child supervisions across continue-as-new and maintenance scan indexes and workflow task lease loss and child summary checkpoints and same-run fence index"),
            Self::MariaDb => (&MARIA, 21, "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions and task ready origins and poll expiry indexes and scoped dispatch index and bound run command contracts and task queue activity dispatch pauses and keyed command intents and bounded schedule dispatch summaries and immutable external storage bindings and child supervisions across continue-as-new and maintenance scan indexes and workflow task lease loss and child summary checkpoints and same-run fence index"),
        };
        slot.get_or_init(|| {
            let current: &str = self.migration().sql.as_ref();
            let relations = current.strip_suffix(checkpoint_catalog::bootstrap_sql())
                .expect("compiled checkpoint code at the end of the bootstrap");
            let sql = format!("{relations}{}", checkpoint_catalog::predecessor_bootstrap_sql());
            let mut migrations = Migrator::with_migrations(vec![Migration::new(
                version,
                description.into(),
                MigrationType::Simple,
                AssertSqlSafe(sql).into_sql_str(),
                false,
            )]);
            migrations.set_locking(false);
            migrations
        })
    }

    // Keep the exact released schema bootstraps before the same-run fence index (3.29.0).
    fn retained_fence_predecessor(self) -> &'static Migrator {
        static MYSQL: OnceLock<Migrator> = OnceLock::new();
        static MARIA: OnceLock<Migrator> = OnceLock::new();
        let (slot, sql) = match self {
            Self::MySql => (
                &MYSQL,
                concat!(
                    include_str!("../../migrations/mysql/0003_full_schema.sql"),
                    "\n",
                    include_str!("../../migrations/mysql/0004_worker_incarnations.sql"),
                    "\n",
                    include_str!("../../migrations/mysql/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/mysql/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/mysql/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/mysql/0008_external_payload_deletions.sql"),
                "\n",
                include_str!("../../migrations/mysql/0009_task_ready_origin.sql"),
                "\n",
                include_str!("../../migrations/mysql/0010_poll_expiry_indexes.sql"),
                "\n",
                include_str!("../../migrations/mysql/0011_scoped_dispatch_index.sql"),
                "\n",
                include_str!("../../migrations/mysql/0012_run_command_contracts.sql"),
                "\n",
                include_str!("../../migrations/mysql/0013_json_text.sql"),
                "\n",
                include_str!("../../migrations/mysql/0014_task_queue_pauses.sql"),
                "\n",
                include_str!("../../migrations/mysql/0015_command_intents.sql"), "\n", include_str!("../../migrations/mysql/0016_schedule_dispatch_summary.sql"), "\n", include_str!("../../migrations/mysql/0017_external_storage_bindings.sql"), "\n", include_str!("../../migrations/mysql/0018_child_supervisions.sql"), "\n", include_str!("../../migrations/mysql/0019_maintenance_scan_indexes.sql"), "\n", include_str!("../../migrations/mysql/0020_workflow_task_lease_loss.sql"), "\n", include_str!("../../migrations/mysql/0021_child_summary_checkpoints.sql")
                ),
            ),
            Self::MariaDb => (
                &MARIA,
                concat!(
                    include_str!("../../migrations/mariadb/0003_full_schema.sql"),
                    "\n",
                    include_str!("../../migrations/mariadb/0004_worker_incarnations.sql"),
                    "\n",
                    include_str!("../../migrations/mariadb/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0008_external_payload_deletions.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0009_task_ready_origin.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0010_poll_expiry_indexes.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0011_scoped_dispatch_index.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0012_run_command_contracts.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0013_task_queue_pauses.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0014_command_intents.sql"), "\n", include_str!("../../migrations/mariadb/0015_schedule_dispatch_summary.sql"), "\n", include_str!("../../migrations/mariadb/0016_external_storage_bindings.sql"), "\n", include_str!("../../migrations/mariadb/0017_child_supervisions.sql"), "\n", include_str!("../../migrations/mariadb/0018_maintenance_scan_indexes.sql"), "\n", include_str!("../../migrations/mariadb/0019_workflow_task_lease_loss.sql"), "\n", include_str!("../../migrations/mysql/0021_child_summary_checkpoints.sql")
                ),
            ),
        };
        slot.get_or_init(|| {
            let description = match self {
                Self::MySql => "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions and task ready origins and poll expiry indexes and scoped dispatch index and bound run command contracts and exact JSON text documents and task queue activity dispatch pauses and keyed command intents and bounded schedule dispatch summaries and immutable external storage bindings and child supervisions across continue-as-new and maintenance scan indexes and workflow task lease loss and child summary checkpoints",
                Self::MariaDb => "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions and task ready origins and poll expiry indexes and scoped dispatch index and bound run command contracts and task queue activity dispatch pauses and keyed command intents and bounded schedule dispatch summaries and immutable external storage bindings and child supervisions across continue-as-new and maintenance scan indexes and workflow task lease loss and child summary checkpoints",
            };
            let mut migrations = Migrator::with_migrations(vec![Migration::new(
                match self { Self::MySql => 21, Self::MariaDb => 20 },
                description.into(),
                MigrationType::Simple,
                AssertSqlSafe(child_summary_checkpoint_bootstrap_sql_with(
                    self.bootstrap_sql(sql), checkpoint_catalog::predecessor_bootstrap_sql())).into_sql_str(),
                false,
            )]);
            // A bounded named lock on this dedicated initialization connection
            // covers preflight, DDL, resumption and final marking.
            migrations.set_locking(false);
            migrations
        })
    }

    fn retained_checkpoint_predecessor(self) -> &'static Migrator {
        static MYSQL: OnceLock<Migrator> = OnceLock::new();
        static MARIA: OnceLock<Migrator> = OnceLock::new();
        let (slot, sql) = match self {
            Self::MySql => (
                &MYSQL,
                concat!(
                    include_str!("../../migrations/mysql/0003_full_schema.sql"),
                    "\n",
                    include_str!("../../migrations/mysql/0004_worker_incarnations.sql"),
                    "\n",
                    include_str!("../../migrations/mysql/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/mysql/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/mysql/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/mysql/0008_external_payload_deletions.sql"),
                "\n",
                include_str!("../../migrations/mysql/0009_task_ready_origin.sql"),
                "\n",
                include_str!("../../migrations/mysql/0010_poll_expiry_indexes.sql"),
                "\n",
                include_str!("../../migrations/mysql/0011_scoped_dispatch_index.sql"),
                "\n",
                include_str!("../../migrations/mysql/0012_run_command_contracts.sql"),
                "\n",
                include_str!("../../migrations/mysql/0013_json_text.sql"),
                "\n",
                include_str!("../../migrations/mysql/0014_task_queue_pauses.sql"),
                "\n",
                include_str!("../../migrations/mysql/0015_command_intents.sql"), "\n", include_str!("../../migrations/mysql/0016_schedule_dispatch_summary.sql"), "\n", include_str!("../../migrations/mysql/0017_external_storage_bindings.sql"), "\n", include_str!("../../migrations/mysql/0018_child_supervisions.sql"), "\n", include_str!("../../migrations/mysql/0019_maintenance_scan_indexes.sql"), "\n", include_str!("../../migrations/mysql/0020_workflow_task_lease_loss.sql")
                ),
            ),
            Self::MariaDb => (
                &MARIA,
                concat!(
                    include_str!("../../migrations/mariadb/0003_full_schema.sql"),
                    "\n",
                    include_str!("../../migrations/mariadb/0004_worker_incarnations.sql"),
                    "\n",
                    include_str!("../../migrations/mariadb/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0008_external_payload_deletions.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0009_task_ready_origin.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0010_poll_expiry_indexes.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0011_scoped_dispatch_index.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0012_run_command_contracts.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0013_task_queue_pauses.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0014_command_intents.sql"), "\n", include_str!("../../migrations/mariadb/0015_schedule_dispatch_summary.sql"), "\n", include_str!("../../migrations/mariadb/0016_external_storage_bindings.sql"), "\n", include_str!("../../migrations/mariadb/0017_child_supervisions.sql"), "\n", include_str!("../../migrations/mariadb/0018_maintenance_scan_indexes.sql"), "\n", include_str!("../../migrations/mariadb/0019_workflow_task_lease_loss.sql")
                ),
            ),
        };
        slot.get_or_init(|| {
            let description = match self {
                Self::MySql => "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions and task ready origins and poll expiry indexes and scoped dispatch index and bound run command contracts and exact JSON text documents and task queue activity dispatch pauses and keyed command intents and bounded schedule dispatch summaries and immutable external storage bindings and child supervisions across continue-as-new and maintenance scan indexes and workflow task lease loss",
                Self::MariaDb => "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions and task ready origins and poll expiry indexes and scoped dispatch index and bound run command contracts and task queue activity dispatch pauses and keyed command intents and bounded schedule dispatch summaries and immutable external storage bindings and child supervisions across continue-as-new and maintenance scan indexes and workflow task lease loss",
            };
            let mut migrations = Migrator::with_migrations(vec![Migration::new(
                match self { Self::MySql => 20, Self::MariaDb => 19 },
                description.into(),
                MigrationType::Simple,
                AssertSqlSafe(self.bootstrap_sql(sql)).into_sql_str(),
                false,
            )]);
            // A bounded named lock on this dedicated initialization connection
            // covers preflight, DDL, resumption and final marking.
            migrations.set_locking(false);
            migrations
        })
    }

    fn retained_lease_loss_predecessor(self) -> &'static Migrator {
        static MYSQL: OnceLock<Migrator> = OnceLock::new();
        static MARIA: OnceLock<Migrator> = OnceLock::new();
        let (slot, sql) = match self {
            Self::MySql => (
                &MYSQL,
                concat!(
                    include_str!("../../migrations/mysql/0003_full_schema.sql"),
                    "\n",
                    include_str!("../../migrations/mysql/0004_worker_incarnations.sql"),
                    "\n",
                    include_str!("../../migrations/mysql/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/mysql/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/mysql/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/mysql/0008_external_payload_deletions.sql"),
                "\n",
                include_str!("../../migrations/mysql/0009_task_ready_origin.sql"),
                "\n",
                include_str!("../../migrations/mysql/0010_poll_expiry_indexes.sql"),
                "\n",
                include_str!("../../migrations/mysql/0011_scoped_dispatch_index.sql"),
                "\n",
                include_str!("../../migrations/mysql/0012_run_command_contracts.sql"),
                "\n",
                include_str!("../../migrations/mysql/0013_json_text.sql"),
                "\n",
                include_str!("../../migrations/mysql/0014_task_queue_pauses.sql"),
                "\n",
                include_str!("../../migrations/mysql/0015_command_intents.sql"), "\n", include_str!("../../migrations/mysql/0016_schedule_dispatch_summary.sql"), "\n", include_str!("../../migrations/mysql/0017_external_storage_bindings.sql"), "\n", include_str!("../../migrations/mysql/0018_child_supervisions.sql"), "\n", include_str!("../../migrations/mysql/0019_maintenance_scan_indexes.sql")
                ),
            ),
            Self::MariaDb => (
                &MARIA,
                concat!(
                    include_str!("../../migrations/mariadb/0003_full_schema.sql"),
                    "\n",
                    include_str!("../../migrations/mariadb/0004_worker_incarnations.sql"),
                    "\n",
                    include_str!("../../migrations/mariadb/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0008_external_payload_deletions.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0009_task_ready_origin.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0010_poll_expiry_indexes.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0011_scoped_dispatch_index.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0012_run_command_contracts.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0013_task_queue_pauses.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0014_command_intents.sql"), "\n", include_str!("../../migrations/mariadb/0015_schedule_dispatch_summary.sql"), "\n", include_str!("../../migrations/mariadb/0016_external_storage_bindings.sql"), "\n", include_str!("../../migrations/mariadb/0017_child_supervisions.sql"), "\n", include_str!("../../migrations/mariadb/0018_maintenance_scan_indexes.sql")
                ),
            ),
        };
        slot.get_or_init(|| {
            let description = match self {
                Self::MySql => "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions and task ready origins and poll expiry indexes and scoped dispatch index and bound run command contracts and exact JSON text documents and task queue activity dispatch pauses and keyed command intents and bounded schedule dispatch summaries and immutable external storage bindings and child supervisions across continue-as-new and maintenance scan indexes",
                Self::MariaDb => "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions and task ready origins and poll expiry indexes and scoped dispatch index and bound run command contracts and task queue activity dispatch pauses and keyed command intents and bounded schedule dispatch summaries and immutable external storage bindings and child supervisions across continue-as-new and maintenance scan indexes",
            };
            let mut migrations = Migrator::with_migrations(vec![Migration::new(
                task_lease_loss_step(self),
                description.into(),
                MigrationType::Simple,
                AssertSqlSafe(self.bootstrap_sql(sql)).into_sql_str(),
                false,
            )]);
            // A bounded named lock on this dedicated initialization connection
            // covers preflight, DDL, resumption and final marking.
            migrations.set_locking(false);
            migrations
        })
    }
    // Keep the exact released schema bootstraps before maintenance scan indexes.
    fn retained_scan_predecessor(self) -> &'static Migrator {
        static MYSQL: OnceLock<Migrator> = OnceLock::new();
        static MARIA: OnceLock<Migrator> = OnceLock::new();
        let (slot, sql) = match self {
            Self::MySql => (
                &MYSQL,
                concat!(
                    include_str!("../../migrations/mysql/0003_full_schema.sql"),
                    "\n",
                    include_str!("../../migrations/mysql/0004_worker_incarnations.sql"),
                    "\n",
                    include_str!("../../migrations/mysql/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/mysql/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/mysql/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/mysql/0008_external_payload_deletions.sql"),
                "\n",
                include_str!("../../migrations/mysql/0009_task_ready_origin.sql"),
                "\n",
                include_str!("../../migrations/mysql/0010_poll_expiry_indexes.sql"),
                "\n",
                include_str!("../../migrations/mysql/0011_scoped_dispatch_index.sql"),
                "\n",
                include_str!("../../migrations/mysql/0012_run_command_contracts.sql"),
                "\n",
                include_str!("../../migrations/mysql/0013_json_text.sql"),
                "\n",
                include_str!("../../migrations/mysql/0014_task_queue_pauses.sql"),
                "\n",
                include_str!("../../migrations/mysql/0015_command_intents.sql"), "\n", include_str!("../../migrations/mysql/0016_schedule_dispatch_summary.sql"), "\n", include_str!("../../migrations/mysql/0017_external_storage_bindings.sql"), "\n", include_str!("../../migrations/mysql/0018_child_supervisions.sql")
                ),
            ),
            Self::MariaDb => (
                &MARIA,
                concat!(
                    include_str!("../../migrations/mariadb/0003_full_schema.sql"),
                    "\n",
                    include_str!("../../migrations/mariadb/0004_worker_incarnations.sql"),
                    "\n",
                    include_str!("../../migrations/mariadb/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0008_external_payload_deletions.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0009_task_ready_origin.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0010_poll_expiry_indexes.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0011_scoped_dispatch_index.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0012_run_command_contracts.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0013_task_queue_pauses.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0014_command_intents.sql"), "\n", include_str!("../../migrations/mariadb/0015_schedule_dispatch_summary.sql"), "\n", include_str!("../../migrations/mariadb/0016_external_storage_bindings.sql"), "\n", include_str!("../../migrations/mariadb/0017_child_supervisions.sql")
                ),
            ),
        };
        slot.get_or_init(|| {
            let description = match self {
                Self::MySql => "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions and task ready origins and poll expiry indexes and scoped dispatch index and bound run command contracts and exact JSON text documents and task queue activity dispatch pauses and keyed command intents and bounded schedule dispatch summaries and immutable external storage bindings and child supervisions across continue-as-new",
                Self::MariaDb => "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions and task ready origins and poll expiry indexes and scoped dispatch index and bound run command contracts and task queue activity dispatch pauses and keyed command intents and bounded schedule dispatch summaries and immutable external storage bindings and child supervisions across continue-as-new",
            };
            let mut migrations = Migrator::with_migrations(vec![Migration::new(
                match self { Self::MySql => 18, Self::MariaDb => 17 },
                description.into(),
                MigrationType::Simple,
                AssertSqlSafe(self.bootstrap_sql(sql)).into_sql_str(),
                false,
            )]);
            // A bounded named lock on this dedicated initialization connection
            // covers preflight, DDL, resumption and final marking.
            migrations.set_locking(false);
            migrations
        })
    }
    // Keep the exact released schema bootstraps before child supervisions.
    fn retained_supervision_predecessor(self) -> &'static Migrator {
        static MYSQL: OnceLock<Migrator> = OnceLock::new();
        static MARIA: OnceLock<Migrator> = OnceLock::new();
        let (slot, sql) = match self {
            Self::MySql => (
                &MYSQL,
                concat!(
                    include_str!("../../migrations/mysql/0003_full_schema.sql"),
                    "\n",
                    include_str!("../../migrations/mysql/0004_worker_incarnations.sql"),
                    "\n",
                    include_str!("../../migrations/mysql/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/mysql/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/mysql/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/mysql/0008_external_payload_deletions.sql"),
                "\n",
                include_str!("../../migrations/mysql/0009_task_ready_origin.sql"),
                "\n",
                include_str!("../../migrations/mysql/0010_poll_expiry_indexes.sql"),
                "\n",
                include_str!("../../migrations/mysql/0011_scoped_dispatch_index.sql"),
                "\n",
                include_str!("../../migrations/mysql/0012_run_command_contracts.sql"),
                "\n",
                include_str!("../../migrations/mysql/0013_json_text.sql"),
                "\n",
                include_str!("../../migrations/mysql/0014_task_queue_pauses.sql"),
                "\n",
                include_str!("../../migrations/mysql/0015_command_intents.sql"), "\n", include_str!("../../migrations/mysql/0016_schedule_dispatch_summary.sql"), "\n", include_str!("../../migrations/mysql/0017_external_storage_bindings.sql")
                ),
            ),
            Self::MariaDb => (
                &MARIA,
                concat!(
                    include_str!("../../migrations/mariadb/0003_full_schema.sql"),
                    "\n",
                    include_str!("../../migrations/mariadb/0004_worker_incarnations.sql"),
                    "\n",
                    include_str!("../../migrations/mariadb/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0008_external_payload_deletions.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0009_task_ready_origin.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0010_poll_expiry_indexes.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0011_scoped_dispatch_index.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0012_run_command_contracts.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0013_task_queue_pauses.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0014_command_intents.sql"), "\n", include_str!("../../migrations/mariadb/0015_schedule_dispatch_summary.sql"), "\n", include_str!("../../migrations/mariadb/0016_external_storage_bindings.sql")
                ),
            ),
        };
        slot.get_or_init(|| {
            let description = match self {
                Self::MySql => "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions and task ready origins and poll expiry indexes and scoped dispatch index and bound run command contracts and exact JSON text documents and task queue activity dispatch pauses and keyed command intents and bounded schedule dispatch summaries and immutable external storage bindings",
                Self::MariaDb => "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions and task ready origins and poll expiry indexes and scoped dispatch index and bound run command contracts and task queue activity dispatch pauses and keyed command intents and bounded schedule dispatch summaries and immutable external storage bindings",
            };
            let mut migrations = Migrator::with_migrations(vec![Migration::new(
                match self { Self::MySql => 17, Self::MariaDb => 16 },
                description.into(),
                MigrationType::Simple,
                AssertSqlSafe(self.bootstrap_sql(sql)).into_sql_str(),
                false,
            )]);
            // A bounded named lock on this dedicated initialization connection
            // covers preflight, DDL, resumption and final marking.
            migrations.set_locking(false);
            migrations
        })
    }
    // Keep exact current-predecessor bootstraps, including SQLx checksums.
    fn retained_storage_predecessor(self) -> &'static Migrator {
        static MYSQL: OnceLock<Migrator> = OnceLock::new();
        static MARIA: OnceLock<Migrator> = OnceLock::new();
        let (slot, sql) = match self {
            Self::MySql => (
                &MYSQL,
                concat!(
                    include_str!("../../migrations/mysql/0003_full_schema.sql"),
                    "\n",
                    include_str!("../../migrations/mysql/0004_worker_incarnations.sql"),
                    "\n",
                    include_str!("../../migrations/mysql/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/mysql/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/mysql/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/mysql/0008_external_payload_deletions.sql"),
                "\n",
                include_str!("../../migrations/mysql/0009_task_ready_origin.sql"),
                "\n",
                include_str!("../../migrations/mysql/0010_poll_expiry_indexes.sql"),
                "\n",
                include_str!("../../migrations/mysql/0011_scoped_dispatch_index.sql"),
                "\n",
                include_str!("../../migrations/mysql/0012_run_command_contracts.sql"),
                "\n",
                include_str!("../../migrations/mysql/0013_json_text.sql"),
                "\n",
                include_str!("../../migrations/mysql/0014_task_queue_pauses.sql"),
                "\n",
                include_str!("../../migrations/mysql/0015_command_intents.sql"), "\n", include_str!("../../migrations/mysql/0016_schedule_dispatch_summary.sql")
                ),
            ),
            Self::MariaDb => (
                &MARIA,
                concat!(
                    include_str!("../../migrations/mariadb/0003_full_schema.sql"),
                    "\n",
                    include_str!("../../migrations/mariadb/0004_worker_incarnations.sql"),
                    "\n",
                    include_str!("../../migrations/mariadb/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0008_external_payload_deletions.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0009_task_ready_origin.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0010_poll_expiry_indexes.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0011_scoped_dispatch_index.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0012_run_command_contracts.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0013_task_queue_pauses.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0014_command_intents.sql"), "\n", include_str!("../../migrations/mariadb/0015_schedule_dispatch_summary.sql")
                ),
            ),
        };
        slot.get_or_init(|| {
            let description = match self {
                Self::MySql => "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions and task ready origins and poll expiry indexes and scoped dispatch index and bound run command contracts and exact JSON text documents and task queue activity dispatch pauses and keyed command intents and bounded schedule dispatch summaries",
                Self::MariaDb => "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions and task ready origins and poll expiry indexes and scoped dispatch index and bound run command contracts and task queue activity dispatch pauses and keyed command intents and bounded schedule dispatch summaries",
            };
            let mut migrations = Migrator::with_migrations(vec![Migration::new(
                match self { Self::MySql => 16, Self::MariaDb => 15 },
                description.into(),
                MigrationType::Simple,
                AssertSqlSafe(self.bootstrap_sql(sql)).into_sql_str(),
                false,
            )]);
            // A bounded named lock on this dedicated initialization connection
            // covers preflight, DDL, resumption and final marking.
            migrations.set_locking(false);
            migrations
        })
    }
    /// The retained schema-12 catalog. Steps before MySQL's JSON text step
    /// build toward it; a resumed upgrade may also find a table already past
    /// it, in its current definition.
    fn v12_catalog(self) -> Result<Catalog> {
        Ok(serde_json::from_str(&canonical(self.earlier(12).expect("retained v12 schema").1))?)
    }
    fn expected(self) -> Result<Catalog> {
        Ok(serde_json::from_str(&canonical(match self {
            Self::MySql => include_str!("../../migrations/mysql/0022_catalog.json"),
            Self::MariaDb => include_str!("../../migrations/mariadb/0021_catalog.json"),
        }))?)
    }
    /// The single native migration, which a takeover records as applied.
    pub(super) fn migration(self) -> &'static Migration {
        self.migrator().iter().next().expect("one native MySQL migration")
    }
    /// The native relations a fresh bootstrap adds after the frozen PHP
    /// layout, each created only if missing so a resumed takeover can repeat
    /// them. The poll index on `workflow_tasks` is added separately.
    pub(super) fn native_relations_sql(self) -> Result<String> {
        let (frozen, incarnations) = match self {
            Self::MySql => (
                include_str!("../../migrations/mysql/0003_full_schema.sql"),
                concat!(
                    include_str!("../../migrations/mysql/0004_worker_incarnations.sql"),
                    "\n",
                    include_str!("../../migrations/mysql/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/mysql/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/mysql/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/mysql/0008_external_payload_deletions.sql")
                ),
            ),
            Self::MariaDb => (
                include_str!("../../migrations/mariadb/0003_full_schema.sql"),
                concat!(
                    include_str!("../../migrations/mariadb/0004_worker_incarnations.sql"),
                    "\n",
                    include_str!("../../migrations/mariadb/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0008_external_payload_deletions.sql")
                ),
            ),
        };
        Ok(super::upgrade::native_relations(
            frozen,
            "-- Native receipts are separate",
            incarnations,
        )?
        .replace(
            "CREATE TABLE dw_worker_registration_incarnations",
            "CREATE TABLE IF NOT EXISTS dw_worker_registration_incarnations",
        )
        .replace(
            "CREATE TABLE dw_worker_session_holders",
            "CREATE TABLE IF NOT EXISTS dw_worker_session_holders",
        )
        .replace(
            "CREATE TABLE dw_stream_reclaims",
            "CREATE TABLE IF NOT EXISTS dw_stream_reclaims",
        )
        .replace(
            "CREATE TABLE dw_service_call_deadlines",
            "CREATE TABLE IF NOT EXISTS dw_service_call_deadlines",
        )
        .replace(
            "CREATE TABLE dw_service_admission_counters",
            "CREATE TABLE IF NOT EXISTS dw_service_admission_counters",
        ))
    }
    pub(super) fn version(self) -> i64 {
        match self {
            Self::MySql => MYSQL_VERSION,
            Self::MariaDb => MARIADB_VERSION,
        }
    }
    pub(super) fn name(self) -> &'static str {
        match self {
            Self::MySql => "mysql",
            Self::MariaDb => "mariadb",
        }
    }
    /// The cumulative bootstrap an earlier native version recorded, byte for
    /// byte, with the catalog captured from a real server for that version.
    fn earlier(self, version: i64) -> Option<(&'static Migration, &'static str)> {
        static MYSQL_7: OnceLock<Migration> = OnceLock::new();
        static MARIA_7: OnceLock<Migration> = OnceLock::new();
        static MYSQL_8: OnceLock<Migration> = OnceLock::new();
        static MARIA_8: OnceLock<Migration> = OnceLock::new();
        static MYSQL_10: OnceLock<Migration> = OnceLock::new();
        static MARIA_10: OnceLock<Migration> = OnceLock::new();
        static MYSQL_9: OnceLock<Migration> = OnceLock::new();
        static MARIA_9: OnceLock<Migration> = OnceLock::new();
        static MYSQL_11: OnceLock<Migration> = OnceLock::new();
        static MYSQL_12: OnceLock<Migration> = OnceLock::new();
        static MARIA_11: OnceLock<Migration> = OnceLock::new();
        static MYSQL_13: OnceLock<Migration> = OnceLock::new();
        static MARIA_12: OnceLock<Migration> = OnceLock::new();
        static MYSQL_14: OnceLock<Migration> = OnceLock::new();
        static MARIA_13: OnceLock<Migration> = OnceLock::new();
        let description = "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines and service admission counters";
        let earlier = |slot: &'static OnceLock<Migration>, version, description: &'static str, sql: &'static str| {
            slot.get_or_init(|| {
                Migration::new(version, description.into(), MigrationType::Simple, sql.into_sql_str(), false)
            })
        };
    static DISPATCH_MYSQL: OnceLock<Migration> = OnceLock::new();
    static DISPATCH_MARIADB: OnceLock<Migration> = OnceLock::new();
        match (self, version) {
            (Self::MySql, 22) => self.retained_catalog_lookup_predecessor().iter().next().map(|migration| (migration, include_str!("../../migrations/mysql/0022_catalog.json"))),
            (Self::MariaDb, 21) => self.retained_catalog_lookup_predecessor().iter().next().map(|migration| (migration, include_str!("../../migrations/mariadb/0021_catalog.json"))),
            (Self::MySql, 21) => self.retained_fence_predecessor().iter().next().map(|migration| (migration, include_str!("../../migrations/mysql/0021_catalog.json"))),
            (Self::MariaDb, 20) => self.retained_fence_predecessor().iter().next().map(|migration| (migration, include_str!("../../migrations/mariadb/0020_catalog.json"))),
            (Self::MySql, 20) => self.retained_checkpoint_predecessor().iter().next().map(|migration| (migration, include_str!("../../migrations/mysql/0020_catalog.json"))),
            (Self::MariaDb, 19) => self.retained_checkpoint_predecessor().iter().next().map(|migration| (migration, include_str!("../../migrations/mariadb/0019_catalog.json"))),
            (Self::MySql, 19) => self.retained_lease_loss_predecessor().iter().next().map(|migration| (migration, include_str!("../../migrations/mysql/0019_catalog.json"))),
            (Self::MariaDb, 18) => self.retained_lease_loss_predecessor().iter().next().map(|migration| (migration, include_str!("../../migrations/mariadb/0018_catalog.json"))),
            (Self::MySql, 18) => self.retained_scan_predecessor().iter().next().map(|migration| (migration, include_str!("../../migrations/mysql/0018_catalog.json"))),
            (Self::MariaDb, 17) => self.retained_scan_predecessor().iter().next().map(|migration| (migration, include_str!("../../migrations/mariadb/0017_catalog.json"))),
            (Self::MySql, 17) => self.retained_supervision_predecessor().iter().next().map(|migration| (migration, include_str!("../../migrations/mysql/0017_catalog.json"))),
            (Self::MariaDb, 16) => self.retained_supervision_predecessor().iter().next().map(|migration| (migration, include_str!("../../migrations/mariadb/0016_catalog.json"))),
            (Self::MySql, 16) => self.retained_storage_predecessor().iter().next().map(|migration| (migration, include_str!("../../migrations/mysql/0016_catalog.json"))),
            (Self::MariaDb, 15) => self.retained_storage_predecessor().iter().next().map(|migration| (migration, include_str!("../../migrations/mariadb/0015_catalog.json"))),
            (Self::MariaDb, 14) => Some((DISPATCH_MARIADB.get_or_init(|| Migration::new(14, "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions and task ready origins and poll expiry indexes and scoped dispatch index and bound run command contracts and task queue activity dispatch pauses and keyed command intents".into(), MigrationType::Simple, AssertSqlSafe(self.bootstrap_sql(concat!(
                    include_str!("../../migrations/mariadb/0003_full_schema.sql"),
                    "\n",
                    include_str!("../../migrations/mariadb/0004_worker_incarnations.sql"),
                    "\n",
                    include_str!("../../migrations/mariadb/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0008_external_payload_deletions.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0009_task_ready_origin.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0010_poll_expiry_indexes.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0011_scoped_dispatch_index.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0012_run_command_contracts.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0013_task_queue_pauses.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0014_command_intents.sql")
                ))).into_sql_str(), false)), include_str!("../../migrations/mariadb/0014_catalog.json"))),

            (Self::MySql, 15) => Some((DISPATCH_MYSQL.get_or_init(|| Migration::new(15, "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions and task ready origins and poll expiry indexes and scoped dispatch index and bound run command contracts and exact JSON text documents and task queue activity dispatch pauses and keyed command intents".into(), MigrationType::Simple, AssertSqlSafe(self.bootstrap_sql(concat!(
                    include_str!("../../migrations/mysql/0003_full_schema.sql"),
                    "\n",
                    include_str!("../../migrations/mysql/0004_worker_incarnations.sql"),
                    "\n",
                    include_str!("../../migrations/mysql/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/mysql/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/mysql/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/mysql/0008_external_payload_deletions.sql"),
                "\n",
                include_str!("../../migrations/mysql/0009_task_ready_origin.sql"),
                "\n",
                include_str!("../../migrations/mysql/0010_poll_expiry_indexes.sql"),
                "\n",
                include_str!("../../migrations/mysql/0011_scoped_dispatch_index.sql"),
                "\n",
                include_str!("../../migrations/mysql/0012_run_command_contracts.sql"),
                "\n",
                include_str!("../../migrations/mysql/0013_json_text.sql"),
                "\n",
                include_str!("../../migrations/mysql/0014_task_queue_pauses.sql"),
                "\n",
                include_str!("../../migrations/mysql/0015_command_intents.sql")
                ))).into_sql_str(), false)), include_str!("../../migrations/mysql/0015_catalog.json"))),

            (Self::MySql, 14) => Some((
                MYSQL_14.get_or_init(|| Migration::new(
                    14,
                    "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions and task ready origins and poll expiry indexes and scoped dispatch index and bound run command contracts and exact JSON text documents and task queue activity dispatch pauses".into(),
                    MigrationType::Simple,
                    AssertSqlSafe(json_text_bootstrap_sql(&bootstrap_sql_v11(concat!(
                include_str!("../../migrations/mysql/0003_full_schema.sql"),
                "\n",
                include_str!("../../migrations/mysql/0004_worker_incarnations.sql"),
                "\n",
                include_str!("../../migrations/mysql/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/mysql/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/mysql/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/mysql/0008_external_payload_deletions.sql"),
                "\n",
                include_str!("../../migrations/mysql/0009_task_ready_origin.sql"),
                "\n",
                include_str!("../../migrations/mysql/0010_poll_expiry_indexes.sql"),
                "\n",
                include_str!("../../migrations/mysql/0011_scoped_dispatch_index.sql"),
                "\n",
                include_str!("../../migrations/mysql/0012_run_command_contracts.sql"),
                "\n",
                include_str!("../../migrations/mysql/0013_json_text.sql"),
                "\n",
                include_str!("../../migrations/mysql/0014_task_queue_pauses.sql")
            )))).into_sql_str(),
                    false,
                )),
                include_str!("../../migrations/mysql/0014_catalog.json"),
            )),
            (Self::MariaDb, 13) => Some((
                MARIA_13.get_or_init(|| Migration::new(
                    13,
                    "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions and task ready origins and poll expiry indexes and scoped dispatch index and bound run command contracts and task queue activity dispatch pauses".into(),
                    MigrationType::Simple,
                    AssertSqlSafe(bootstrap_sql_v11(concat!(
                include_str!("../../migrations/mariadb/0003_full_schema.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0004_worker_incarnations.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0008_external_payload_deletions.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0009_task_ready_origin.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0010_poll_expiry_indexes.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0011_scoped_dispatch_index.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0012_run_command_contracts.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0013_task_queue_pauses.sql")
            ))).into_sql_str(),
                    false,
                )),
                include_str!("../../migrations/mariadb/0013_catalog.json"),
            )),
            (Self::MySql, 13) => Some((
                MYSQL_13.get_or_init(|| Migration::new(
                    13,
                    "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions and task ready origins and poll expiry indexes and scoped dispatch index and bound run command contracts and exact JSON text documents".into(),
                    MigrationType::Simple,
                    AssertSqlSafe(json_text_bootstrap_sql(&bootstrap_sql_v11(concat!(
                include_str!("../../migrations/mysql/0003_full_schema.sql"),
                "\n",
                include_str!("../../migrations/mysql/0004_worker_incarnations.sql"),
                "\n",
                include_str!("../../migrations/mysql/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/mysql/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/mysql/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/mysql/0008_external_payload_deletions.sql"),
                "\n",
                include_str!("../../migrations/mysql/0009_task_ready_origin.sql"),
                "\n",
                include_str!("../../migrations/mysql/0010_poll_expiry_indexes.sql"),
                "\n",
                include_str!("../../migrations/mysql/0011_scoped_dispatch_index.sql"),
                "\n",
                include_str!("../../migrations/mysql/0012_run_command_contracts.sql"),
                "\n",
                include_str!("../../migrations/mysql/0013_json_text.sql")
            )))).into_sql_str(),
                    false,
                )),
                include_str!("../../migrations/mysql/0013_catalog.json"),
            )),
            (Self::MariaDb, 12) => Some((
                MARIA_12.get_or_init(|| Migration::new(
                    12,
                    "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions and task ready origins and poll expiry indexes and scoped dispatch index and bound run command contracts".into(),
                    MigrationType::Simple,
                    AssertSqlSafe(bootstrap_sql_v11(concat!(
                include_str!("../../migrations/mariadb/0003_full_schema.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0004_worker_incarnations.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0008_external_payload_deletions.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0009_task_ready_origin.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0010_poll_expiry_indexes.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0011_scoped_dispatch_index.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0012_run_command_contracts.sql")
            ))).into_sql_str(),
                    false,
                )),
                include_str!("../../migrations/mariadb/0012_catalog.json"),
            )),
            (Self::MySql, 12) => Some((
                MYSQL_12.get_or_init(|| Migration::new(
                    12,
                    "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions and task ready origins and poll expiry indexes and scoped dispatch index and bound run command contracts".into(),
                    MigrationType::Simple,
                    AssertSqlSafe(bootstrap_sql_v11(concat!(
                include_str!("../../migrations/mysql/0003_full_schema.sql"),
                "\n",
                include_str!("../../migrations/mysql/0004_worker_incarnations.sql"),
                "\n",
                include_str!("../../migrations/mysql/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/mysql/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/mysql/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/mysql/0008_external_payload_deletions.sql"),
                "\n",
                include_str!("../../migrations/mysql/0009_task_ready_origin.sql"),
                "\n",
                include_str!("../../migrations/mysql/0010_poll_expiry_indexes.sql"),
                "\n",
                include_str!("../../migrations/mysql/0011_scoped_dispatch_index.sql"),
                "\n",
                include_str!("../../migrations/mysql/0012_run_command_contracts.sql")
            ))).into_sql_str(),
                    false,
                )),
                include_str!("../../migrations/mysql/0012_catalog.json"),
            )),
            (Self::MySql, 11) => Some((
                MYSQL_11.get_or_init(|| Migration::new(
                    11,
                    "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions and task ready origins and poll expiry indexes and scoped dispatch index".into(),
                    MigrationType::Simple,
                    AssertSqlSafe(bootstrap_sql_v11(concat!(
                include_str!("../../migrations/mysql/0003_full_schema.sql"),
                "\n",
                include_str!("../../migrations/mysql/0004_worker_incarnations.sql"),
                "\n",
                include_str!("../../migrations/mysql/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/mysql/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/mysql/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/mysql/0008_external_payload_deletions.sql"),
                "\n",
                include_str!("../../migrations/mysql/0009_task_ready_origin.sql"),
                "\n",
                include_str!("../../migrations/mysql/0010_poll_expiry_indexes.sql"),
                "\n",
                include_str!("../../migrations/mysql/0011_scoped_dispatch_index.sql")
            ))).into_sql_str(),
                    false,
                )),
                include_str!("../../migrations/mysql/0011_catalog.json"),
            )),
            (Self::MariaDb, 11) => Some((
                MARIA_11.get_or_init(|| Migration::new(
                    11,
                    "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions and task ready origins and poll expiry indexes and scoped dispatch index".into(),
                    MigrationType::Simple,
                    AssertSqlSafe(bootstrap_sql_v11(concat!(
                include_str!("../../migrations/mariadb/0003_full_schema.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0004_worker_incarnations.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0008_external_payload_deletions.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0009_task_ready_origin.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0010_poll_expiry_indexes.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0011_scoped_dispatch_index.sql")
            ))).into_sql_str(),
                    false,
                )),
                include_str!("../../migrations/mariadb/0011_catalog.json"),
            )),
            (_, 10) => {
                let (slot, expected) = match self {
                    Self::MySql => (&MYSQL_10, include_str!("../../migrations/mysql/0010_catalog.json")),
                    Self::MariaDb => (&MARIA_10, include_str!("../../migrations/mariadb/0010_catalog.json")),
                };
                Some((slot.get_or_init(|| {
                    let previous = self.earlier(9).expect("retained v9 schema").0;
                    let previous_sql: &str = previous.sql.as_ref();
                    let sql = format!("{previous_sql}\n{}", self.step(9).expect("compiled v10 step"));
                    Migration::new(10,
                        "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions and task ready origins and poll expiry indexes".into(),
                        MigrationType::Simple, AssertSqlSafe(poll_expiry_bootstrap_sql(sql)).into_sql_str(), false)
                }), expected))
            },
            (Self::MySql, 9) => Some((
                MYSQL_9.get_or_init(|| Migration::new(
                    9,
                    "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions and task ready origins".into(),
                    MigrationType::Simple,
                    AssertSqlSafe(bootstrap_sql_v9(concat!(
                include_str!("../../migrations/mysql/0003_full_schema.sql"),
                "\n",
                include_str!("../../migrations/mysql/0004_worker_incarnations.sql"),
                "\n",
                include_str!("../../migrations/mysql/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/mysql/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/mysql/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/mysql/0008_external_payload_deletions.sql"),
                "\n",
                include_str!("../../migrations/mysql/0009_task_ready_origin.sql")
            ))).into_sql_str(),
                    false,
                )),
                include_str!("../../migrations/mysql/0009_catalog.json"),
            )),
            (Self::MariaDb, 9) => Some((
                MARIA_9.get_or_init(|| Migration::new(
                    9,
                    "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions and task ready origins".into(),
                    MigrationType::Simple,
                    AssertSqlSafe(bootstrap_sql_v9(concat!(
                include_str!("../../migrations/mariadb/0003_full_schema.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0004_worker_incarnations.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0005_worker_session_holders.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0006_stream_reclaims.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0007_service_calls.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0008_external_payload_deletions.sql"),
                "\n",
                include_str!("../../migrations/mariadb/0009_task_ready_origin.sql")
            ))).into_sql_str(),
                    false,
                )),
                include_str!("../../migrations/mariadb/0009_catalog.json"),
            )),
            (Self::MySql, 7) => Some((
                earlier(
                    &MYSQL_7, 7, description,
                    concat!(
                        include_str!("../../migrations/mysql/0003_full_schema.sql"),
                        "\n",
                        include_str!("../../migrations/mysql/0004_worker_incarnations.sql"),
                        "\n",
                        include_str!("../../migrations/mysql/0005_worker_session_holders.sql"),
                        "\n",
                        include_str!("../../migrations/mysql/0006_stream_reclaims.sql"),
                        "\n",
                        include_str!("../../migrations/mysql/0007_service_calls.sql")
                    ),
                ),
                include_str!("../../migrations/mysql/0007_catalog.json"),
            )),
            (Self::MariaDb, 7) => Some((
                earlier(
                    &MARIA_7, 7, description,
                    concat!(
                        include_str!("../../migrations/mariadb/0003_full_schema.sql"),
                        "\n",
                        include_str!("../../migrations/mariadb/0004_worker_incarnations.sql"),
                        "\n",
                        include_str!("../../migrations/mariadb/0005_worker_session_holders.sql"),
                        "\n",
                        include_str!("../../migrations/mariadb/0006_stream_reclaims.sql"),
                        "\n",
                        include_str!("../../migrations/mariadb/0007_service_calls.sql")
                    ),
                ),
                include_str!("../../migrations/mariadb/0007_catalog.json"),
            )),
            (Self::MySql, 8) => Some((
                earlier(
                    &MYSQL_8, 8, "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions",
                    concat!(
                        include_str!("../../migrations/mysql/0003_full_schema.sql"),
                        "\n",
                        include_str!("../../migrations/mysql/0004_worker_incarnations.sql"),
                        "\n",
                        include_str!("../../migrations/mysql/0005_worker_session_holders.sql"),
                        "\n",
                        include_str!("../../migrations/mysql/0006_stream_reclaims.sql"),
                        "\n",
                        include_str!("../../migrations/mysql/0007_service_calls.sql"),
                        "\n",
                        include_str!("../../migrations/mysql/0008_external_payload_deletions.sql")
                    ),
                ),
                include_str!("../../migrations/mysql/0008_catalog.json"),
            )),
            (Self::MariaDb, 8) => Some((
                earlier(
                    &MARIA_8, 8, "full frozen PHP schema, native worker incarnations, session holders, stream reclaim deadlines, service call deadlines, service admission counters and committed external payload deletions",
                    concat!(
                        include_str!("../../migrations/mariadb/0003_full_schema.sql"),
                        "\n",
                        include_str!("../../migrations/mariadb/0004_worker_incarnations.sql"),
                        "\n",
                        include_str!("../../migrations/mariadb/0005_worker_session_holders.sql"),
                        "\n",
                        include_str!("../../migrations/mariadb/0006_stream_reclaims.sql"),
                        "\n",
                        include_str!("../../migrations/mariadb/0007_service_calls.sql"),
                        "\n",
                        include_str!("../../migrations/mariadb/0008_external_payload_deletions.sql")
                    ),
                ),
                include_str!("../../migrations/mariadb/0008_catalog.json"),
            )),
            _ => None,
        }
    }
    /// The step file that moves a native database from `version` to the next.
    fn step(self, version: i64) -> Option<&'static str> {
        match (self, version) {
            (Self::MySql, 22) | (Self::MariaDb, 21) => Some(include_str!("../../migrations/mysql/0023_child_summary_catalog_lookup.sql")),
            (Self::MySql, 21) => Some(include_str!("../../migrations/mysql/0022_same_run_fence_index.sql")),
            (Self::MariaDb, 20) => Some(include_str!("../../migrations/mariadb/0021_same_run_fence_index.sql")),
            (Self::MySql, 20) | (Self::MariaDb, 19) => Some(CHILD_SUMMARY_CHECKPOINT_SCHEMA),
            (Self::MySql, 19) => Some(include_str!("../../migrations/mysql/0020_workflow_task_lease_loss.sql")),
            (Self::MariaDb, 18) => Some(include_str!("../../migrations/mariadb/0019_workflow_task_lease_loss.sql")),
            (Self::MySql, 18) => Some(include_str!("../../migrations/mysql/0019_maintenance_scan_indexes.sql")),
            (Self::MariaDb, 17) => Some(include_str!("../../migrations/mariadb/0018_maintenance_scan_indexes.sql")),
            (Self::MySql, 17) => Some(include_str!("../../migrations/mysql/0018_child_supervisions.sql")),
            (Self::MariaDb, 16) => Some(include_str!("../../migrations/mariadb/0017_child_supervisions.sql")),
            (Self::MySql, 16) => Some(include_str!("../../migrations/mysql/0017_external_storage_bindings.sql")),
            (Self::MariaDb, 15) => Some(include_str!("../../migrations/mariadb/0016_external_storage_bindings.sql")),
            (Self::MySql, 7) => Some(include_str!("../../migrations/mysql/0008_external_payload_deletions.sql")),
            (Self::MariaDb, 7) => Some(include_str!("../../migrations/mariadb/0008_external_payload_deletions.sql")),
            (Self::MySql, 8) => Some(include_str!("../../migrations/mysql/0009_task_ready_origin.sql")),
            (Self::MySql, 9) => Some(include_str!("../../migrations/mysql/0010_poll_expiry_indexes.sql")),
            (Self::MariaDb, 8) => Some(include_str!("../../migrations/mariadb/0009_task_ready_origin.sql")),
            (Self::MariaDb, 9) => Some(include_str!("../../migrations/mariadb/0010_poll_expiry_indexes.sql")),
            (Self::MySql, 10) => Some(include_str!("../../migrations/mysql/0011_scoped_dispatch_index.sql")),
            (Self::MariaDb, 10) => Some(include_str!("../../migrations/mariadb/0011_scoped_dispatch_index.sql")),
            (Self::MySql, 11) => Some(include_str!("../../migrations/mysql/0012_run_command_contracts.sql")),
            (Self::MariaDb, 11) => Some(include_str!("../../migrations/mariadb/0012_run_command_contracts.sql")),
            (Self::MySql, 12) => Some(include_str!("../../migrations/mysql/0013_json_text.sql")),
            (Self::MySql, 13) => Some(include_str!("../../migrations/mysql/0014_task_queue_pauses.sql")),
            (Self::MariaDb, 12) => Some(include_str!("../../migrations/mariadb/0013_task_queue_pauses.sql")),
            (Self::MySql, 14) => Some(include_str!("../../migrations/mysql/0015_command_intents.sql")),
            (Self::MariaDb, 13) => Some(include_str!("../../migrations/mariadb/0014_command_intents.sql")),
            (Self::MySql, 15) => Some(include_str!("../../migrations/mysql/0016_schedule_dispatch_summary.sql")),
            (Self::MariaDb, 14) => Some(include_str!("../../migrations/mariadb/0015_schedule_dispatch_summary.sql")),
            _ => None,
        }
    }
    pub(super) fn checksum(self) -> &'static [u8] {
        self.migrator().iter().next().unwrap().checksum.as_ref()
    }
}

/// Only compiled migration text enters this transformation. Fresh and resumed
/// initialization create the final task definition without replaying an ALTER.
fn bootstrap_sql_v9(sql: &str) -> String {
    let start = sql.find("CREATE TABLE IF NOT EXISTS `workflow_tasks` (")
        .expect("compiled workflow task definition");
    let key = start + sql[start..].find("  PRIMARY KEY (`id`),")
        .expect("compiled workflow task primary key");
    let mut sql = sql.to_owned();
    sql.insert_str(key, "  `ready_origin_at` datetime(6) DEFAULT NULL,\n");
    sql = sql.replace("ALTER TABLE workflow_tasks ADD COLUMN ready_origin_at DATETIME(6) NULL;", "");
    sql.replace("CREATE TABLE ", "CREATE TABLE IF NOT EXISTS ")
        .replace("IF NOT EXISTS IF NOT EXISTS", "IF NOT EXISTS")
}

fn bootstrap_sql_v10(sql: &str) -> String {
    poll_expiry_bootstrap_sql(bootstrap_sql_v9(sql))
}

fn poll_expiry_bootstrap_sql(mut sql: String) -> String {
    let start = sql.find("CREATE TABLE IF NOT EXISTS dw_query_cache (").expect("compiled query cache");
    let end = start + sql[start..].find("\n) ENGINE=").expect("compiled query cache end");
    sql.insert_str(end, ",\n    KEY dw_query_cache_expiration (expiration)");
    let start = sql.find("CREATE TABLE IF NOT EXISTS dw_poll_receipts (").expect("compiled poll receipts");
    let key = start + sql[start..].find("    CONSTRAINT dw_poll_receipts_task").expect("compiled receipt foreign key");
    sql.insert_str(key, "    KEY dw_poll_receipts_task (task_id),\n    KEY dw_poll_receipts_expiration (expires_at),\n");
    sql.replace("CREATE INDEX dw_query_cache_expiration ON dw_query_cache (expiration);", "")
        .replace("CREATE INDEX dw_poll_receipts_expiration ON dw_poll_receipts (expires_at);", "")
}

fn bootstrap_sql_v11(sql: &str) -> String {
    let mut sql = bootstrap_sql_v10(sql);
    let start = sql.find("CREATE TABLE IF NOT EXISTS `workflow_tasks` (").expect("compiled workflow task definition");
    let end = start + sql[start..].find("\n) ENGINE=").expect("compiled workflow task end");
    sql.insert_str(end, ",\n  KEY `dw_workflow_tasks_scoped_dispatch` (`namespace`,`queue`,`task_type`(16),`status`(16),`priority`,`available_at`,`id`)");
    sql.replace("CREATE INDEX dw_workflow_tasks_scoped_dispatch ON workflow_tasks (namespace,queue,task_type(16),status(16),priority,available_at,id);", "")
}

impl Flavor {
    /// Versions 12 and MySQL 14 / MariaDB 13 add only a native table, created
    /// like every other native table of the cumulative bootstrap. MySQL's
    /// version 13 creates each JSON document column as exact text directly,
    /// as its upgrade ALTERs it.
    fn bootstrap_sql(self, sql: &str) -> String {
        let sql = match self {
            Self::MySql => json_text_bootstrap_sql(&bootstrap_sql_v11(sql)),
            Self::MariaDb => bootstrap_sql_v11(sql),
        };
        let sql = maintenance_scan_bootstrap_sql(external_storage_bootstrap_sql(dispatch_summary_bootstrap_sql(sql)));
        run_fence_bootstrap_sql(task_lease_loss_bootstrap_sql(sql))
    }
}

/// The same-run fence index (server#1327), in the step's statement text and
/// as the task table's catalog line.
const RUN_FENCE_INDEX: &str = "CREATE INDEX dw_workflow_tasks_run_fence ON workflow_tasks (workflow_run_id,task_type,status);";
const RUN_FENCE_KEY: &str = "  KEY `dw_workflow_tasks_run_fence` (`workflow_run_id`,`task_type`,`status`)";

/// A fresh or resumed initialization creates the task table with the index,
/// so it never repeats a CREATE INDEX that already committed. Retained
/// predecessor bootstraps do not contain the step and stay exact.
fn run_fence_bootstrap_sql(mut sql: String) -> String {
    if !sql.contains(RUN_FENCE_INDEX) {
        return sql;
    }
    let start = sql.find("CREATE TABLE IF NOT EXISTS `workflow_tasks` (").expect("compiled workflow tasks");
    let end = start + sql[start..].find("\n) ENGINE=").expect("compiled workflow task end");
    sql.insert_str(end, &format!(",\n{RUN_FENCE_KEY}"));
    sql.replace(RUN_FENCE_INDEX, "")
}

/// Init creates the final table directly so a lost bootstrap acknowledgement
/// cannot cause a second implicit-commit ALTER.
fn task_lease_loss_bootstrap_sql(mut sql: String) -> String {
    let alter = include_str!("../../migrations/mysql/0020_workflow_task_lease_loss.sql").trim();
    assert_eq!(alter, include_str!("../../migrations/mariadb/0019_workflow_task_lease_loss.sql").trim());
    if !sql.contains(alter) {
        return sql;
    }
    let start = sql.find("CREATE TABLE IF NOT EXISTS `workflow_tasks` (").expect("compiled workflow tasks");
    let key = start + sql[start..].find("  PRIMARY KEY").expect("compiled workflow task primary key");
    sql.insert_str(key, "  `lost_lease_count` BIGINT NOT NULL DEFAULT 0,\n  `last_lease_lost_at` DATETIME(6) NULL,\n");
    sql.replace(alter, "")
}

/// The maintenance scan indexes (server#1256): each table and the index the
/// step creates on it, in the step's own statement text.
const MAINTENANCE_SCAN_INDEXES: [(&str, &str, &str); 3] = [
    ("workflow_runs", "CREATE INDEX dw_workflow_runs_status_deadline ON workflow_runs (status,cancellation_deadline_at);",
        "  KEY `dw_workflow_runs_status_deadline` (`status`,`cancellation_deadline_at`)"),
    ("activity_executions", "CREATE INDEX dw_activity_executions_status ON activity_executions (status);",
        "  KEY `dw_activity_executions_status` (`status`)"),
    ("workflow_run_summaries", "CREATE INDEX dw_workflow_run_summaries_retention ON workflow_run_summaries (namespace,closed_at,id);",
        "  KEY `dw_workflow_run_summaries_retention` (`namespace`,`closed_at`,`id`)"),
];

/// A fresh or resumed initialization creates each indexed table in its final
/// definition, so it never repeats a CREATE INDEX that already committed.
/// Retained predecessor bootstraps do not contain the step and stay exact.
fn maintenance_scan_bootstrap_sql(mut sql: String) -> String {
    for (table, statement, key) in MAINTENANCE_SCAN_INDEXES {
        if !sql.contains(statement) { continue; }
        let start = sql.find(&format!("CREATE TABLE IF NOT EXISTS `{table}` (")).expect("compiled maintenance scan table");
        let end = start + sql[start..].find("\n) ENGINE=").expect("compiled maintenance scan table end");
        sql.insert_str(end, &format!(",\n{key}"));
        sql = sql.replace(statement, "");
    }
    sql
}

/// Fresh bootstrap creates final definitions directly; it cannot repeat an
/// ALTER after a lost initialization acknowledgement. Old SQL is a no-op here.
fn external_storage_bootstrap_sql(mut sql: String) -> String {
    for (table, column, alter) in [
        ("runtime_external_payloads", "  `storage_binding` TEXT NULL,\n", "ALTER TABLE runtime_external_payloads ADD COLUMN storage_binding TEXT NULL;"),
        ("workflow_namespaces", "  `external_payload_storage_generation` BIGINT NOT NULL DEFAULT 0,\n", "ALTER TABLE workflow_namespaces ADD COLUMN external_payload_storage_generation BIGINT NOT NULL DEFAULT 0;"),
    ] {
        if !sql.contains(alter) { continue; }
        let start = sql.find(&format!("CREATE TABLE IF NOT EXISTS `{table}` (")).expect("compiled storage relation");
        let end = start + sql[start..].find("  PRIMARY KEY").expect("compiled storage primary key");
        sql.insert_str(end, column);
        sql = sql.replace(alter, "");
    }
    sql
}

/// Fresh initialization can resume after any implicit DDL commit. Create the
/// final schedule table directly so a resumed bootstrap never repeats ALTER.
/// Retained predecessor bootstraps do not contain this new step and stay exact.
fn dispatch_summary_bootstrap_sql(mut sql: String) -> String {
    let alter = "ALTER TABLE workflow_schedules ADD COLUMN dispatch_summary TEXT NULL;";
    if !sql.contains(alter) { return sql; }
    let start = sql.find("CREATE TABLE IF NOT EXISTS `workflow_schedules` (").expect("compiled schedule table");
    let end = start + sql[start..].find("  PRIMARY KEY").expect("compiled schedule primary key");
    sql.insert_str(end, "  `dispatch_summary` text DEFAULT NULL,\n");
    sql.replace(alter, "")
}

/// The exact text definition and enforced check for each MySQL `json`
/// column (server#1016). The constraint name is `dw_json_text_` and the first
/// 20 hex digits of SHA-256 over table, NUL and column.
fn json_text_check_name(table: &str, column: &str) -> String {
    use sha2::{Digest, Sha256};
    let digest = Sha256::digest(format!("{table}\0{column}").as_bytes());
    let hex: String = digest.iter().map(|byte| format!("{byte:02x}")).collect();
    format!("dw_json_text_{}", &hex[..20])
}

/// Rewrites every compiled `json` column of the MySQL bootstrap to the
/// version-13 text definition with its check, and drops the upgrade ALTERs,
/// so a fresh or resumed initialization creates final tables directly.
fn json_text_bootstrap_sql(sql: &str) -> String {
    let column = regex_lite::Regex::new(r"(?i)^(\s*)`?(\w+)`? json( NOT NULL| DEFAULT NULL)?( COMMENT '[^']*')?(,?)\s*$")
        .expect("json column pattern");
    let table = regex_lite::Regex::new(r"^\s*CREATE TABLE (?:IF NOT EXISTS )?`?(\w+)`?").expect("table pattern");
    let sql = sql.replace(include_str!("../../migrations/mysql/0013_json_text.sql"), "");
    let mut out: Vec<String> = Vec::new();
    let (mut current, mut checks) = (String::new(), Vec::new());
    for line in sql.split('\n') {
        if let Some(found) = table.captures(line) {
            current = found[1].to_owned();
            checks.clear();
        }
        if let Some(found) = column.captures(line) {
            let name = found[2].to_owned();
            checks.push(format!("  CONSTRAINT `{}` CHECK (json_valid(`{name}`))", json_text_check_name(&current, &name)));
            out.push(format!("{}`{name}` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin{}{}{}", &found[1],
                found.get(3).map_or("", |m| m.as_str()), found.get(4).map_or("", |m| m.as_str()), &found[5]));
            continue;
        }
        if line.starts_with(")") && line.contains("ENGINE") && !checks.is_empty() {
            let last = out.last_mut().expect("a definition before the table footer");
            last.push(',');
            last.push('\n');
            last.push_str(&checks.join(",\n"));
            checks.clear();
        }
        out.push(line.to_owned());
    }
    out.join("\n")
}

/// Version 13 on MySQL: each JSON document column becomes exact text, one
/// atomic ALTER per table. A resumed upgrade or takeover accepts each table
/// only in its exact version-12 or version-13 definition.
pub(super) async fn ensure_json_text(connection: &mut MySqlConnection, flavor: Flavor) -> Result<()> {
    let Flavor::MySql = flavor else { return Ok(()) };
    let (_, old) = flavor.earlier(12).expect("retained v12 schema");
    let old: BTreeMap<String, String> = serde_json::from_str::<Catalog>(&canonical(old))?.into_iter().collect();
    let expected: BTreeMap<String, String> = flavor.expected()?.into_iter().collect();
    let actual: BTreeMap<String, String> = catalog(connection).await?.into_iter().collect();
    let pre_binding: BTreeMap<_, _> = serde_json::from_str::<Catalog>(&canonical(flavor.earlier(storage_binding_step(flavor)).expect("retained storage predecessor").1))?.into_iter().collect();
    let pre_scan = pre_scan_index_tables(flavor)?;
    // A resumed upgrade may find the task table past this step, before task
    // lease loss or before the same-run fence index.
    let later_tasks = [pre_lease_loss_task_table(flavor)?, pre_fence_task_table(flavor)?];
    for statement in flavor.step(12).expect("compiled json text step").split(";\n").map(str::trim) {
        let Some(rest) = statement.lines().find(|line| line.starts_with("ALTER TABLE `")) else { continue };
        let name = rest.trim_start_matches("ALTER TABLE `").trim_end_matches('`').to_owned();
        match actual.get(&name) {
            found if found == expected.get(&name) || (name == "workflow_namespaces" && found == pre_binding.get(&name))
                || (found.is_some() && found == pre_scan.get(&name))
                || (name == "workflow_tasks" && later_tasks.iter().flatten().any(|(_, ddl)| found == Some(ddl))) => continue,
            found if found.is_some() && found == old.get(&name) => {
                let alter = statement[statement.find("ALTER TABLE").expect("alter")..].to_owned();
                connection.execute(AssertSqlSafe(alter)).await?;
            }
            _ => return Err(refuse(StatusCode::CONFLICT, "native_schema_catalog_mismatch")),
        }
    }
    Ok(())
}

/// Creating the bound-contract table commits on its own. A resumed upgrade
/// or takeover accepts only the exact new definition once it exists.
pub(super) async fn ensure_run_command_contracts(connection: &mut MySqlConnection, flavor: Flavor) -> Result<()> {
    let sql = flavor.step(11).expect("compiled run command contracts");
    connection.execute(AssertSqlSafe(sql.replace("CREATE TABLE ", "CREATE TABLE IF NOT EXISTS "))).await?;
    let table = |rows: Catalog| rows.into_iter().find(|(name, _)| name == "dw_run_command_contracts");
    if table(catalog(connection).await?) != table(flavor.v12_catalog()?) {
        return Err(refuse(StatusCode::CONFLICT, "native_schema_catalog_mismatch"));
    }
    Ok(())
}

/// The step that creates the task queue pause table (server#577): MySQL's
/// from 13, MariaDB's from 12.
fn task_queue_pauses_step(flavor: Flavor) -> i64 {
    match flavor {
        Flavor::MySql => 13,
        Flavor::MariaDb => 12,
    }
}

/// The step that creates the keyed command intent table (server#934):
/// MySQL's from 14, MariaDB's from 13.
fn command_intents_step(flavor: Flavor) -> i64 {
    match flavor {
        Flavor::MySql => 14,
        Flavor::MariaDb => 13,
    }
}

/// Creating one native table commits on its own. A resumed upgrade or
/// takeover accepts only the exact current definition once it exists.
async fn ensure_native_table(connection: &mut MySqlConnection, flavor: Flavor, step: i64, name: &str) -> Result<()> {
    let sql = flavor.step(step).expect("compiled native table step");
    connection.execute(AssertSqlSafe(sql.replace("CREATE TABLE ", "CREATE TABLE IF NOT EXISTS "))).await?;
    let table = |rows: Catalog| rows.into_iter().find(|(table, _)| table == name);
    if table(catalog(connection).await?) != table(flavor.expected()?) {
        return Err(refuse(StatusCode::CONFLICT, "native_schema_catalog_mismatch"));
    }
    Ok(())
}

pub(super) async fn ensure_task_queue_pauses(connection: &mut MySqlConnection, flavor: Flavor) -> Result<()> {
    ensure_native_table(connection, flavor, task_queue_pauses_step(flavor), "dw_task_queue_pauses").await
}

pub(super) async fn ensure_command_intents(connection: &mut MySqlConnection, flavor: Flavor) -> Result<()> {
    ensure_native_table(connection, flavor, command_intents_step(flavor), "dw_command_intents").await
}

fn storage_binding_step(flavor: Flavor) -> i64 {
    match flavor { Flavor::MySql => 16, Flavor::MariaDb => 15 }
}

/// Each ALTER commits independently. Resume only exact old or exact new table
/// definitions; a column-name probe cannot admit unknown schema drift.
pub(super) async fn ensure_external_storage_bindings(connection: &mut MySqlConnection, flavor: Flavor) -> Result<()> {
    let old: std::collections::BTreeMap<_, _> = serde_json::from_str::<Catalog>(&canonical(flavor.earlier(storage_binding_step(flavor)).expect("retained storage predecessor").1))?.into_iter().collect();
    let current: std::collections::BTreeMap<_, _> = flavor.expected()?.into_iter().collect();
    for (table, statement) in [
        ("runtime_external_payloads", "ALTER TABLE runtime_external_payloads ADD COLUMN storage_binding TEXT NULL;"),
        ("workflow_namespaces", "ALTER TABLE workflow_namespaces ADD COLUMN external_payload_storage_generation BIGINT NOT NULL DEFAULT 0;"),
    ] {
        let actual: std::collections::BTreeMap<_, _> = catalog(connection).await?.into_iter().collect();
        if actual.get(table) == current.get(table) { continue; }
        if actual.get(table) != old.get(table) { return Err(refuse(StatusCode::CONFLICT, "native_schema_catalog_mismatch")); }
        connection.execute(statement).await?;
        let after: std::collections::BTreeMap<_, _> = catalog(connection).await?.into_iter().collect();
        if after.get(table) != current.get(table) { return Err(refuse(StatusCode::CONFLICT, "native_schema_catalog_mismatch")); }
    }
    Ok(())
}

/// The step that creates the child supervision table (server#1184):
/// MySQL's from 17, MariaDB's from 16.
fn child_supervisions_step(flavor: Flavor) -> i64 {
    match flavor { Flavor::MySql => 17, Flavor::MariaDb => 16 }
}

pub(super) async fn ensure_child_supervisions(connection: &mut MySqlConnection, flavor: Flavor) -> Result<()> {
    ensure_native_table(connection, flavor, child_supervisions_step(flavor), "dw_child_supervisions").await
}

/// The step that adds the maintenance scan indexes (server#1256): MySQL's
/// from 18, MariaDB's from 17.
fn maintenance_scan_step(flavor: Flavor) -> i64 {
    match flavor { Flavor::MySql => 18, Flavor::MariaDb => 17 }
}

/// Each indexed table before the maintenance scan step: the retained
/// predecessor's definition, which every earlier step builds toward.
fn pre_scan_index_tables(flavor: Flavor) -> Result<BTreeMap<String, String>> {
    let (_, old) = flavor.earlier(maintenance_scan_step(flavor)).expect("retained maintenance scan predecessor");
    Ok(serde_json::from_str::<Catalog>(&canonical(old))?
        .into_iter()
        .filter(|(name, _)| MAINTENANCE_SCAN_INDEXES.iter().any(|(table, _, _)| table == name))
        .collect())
}

/// Each CREATE INDEX commits on its own; InnoDB builds it in place without
/// blocking writes. A resumed upgrade or takeover accepts each table only in
/// its exact predecessor or exact current definition, never by index name.
pub(super) async fn ensure_maintenance_scan_indexes(connection: &mut MySqlConnection, flavor: Flavor) -> Result<()> {
    let old = pre_scan_index_tables(flavor)?;
    let current: BTreeMap<_, _> = flavor.expected()?.into_iter().collect();
    for (table, statement, _) in MAINTENANCE_SCAN_INDEXES {
        let found: BTreeMap<_, _> = catalog(connection).await?.into_iter().collect();
        if found.get(table) == current.get(table) { continue; }
        if found.get(table) != old.get(table) { return Err(refuse(StatusCode::CONFLICT, "native_schema_catalog_mismatch")); }
        connection.execute(statement.trim_end_matches(';')).await?;
        let after: BTreeMap<_, _> = catalog(connection).await?.into_iter().collect();
        if after.get(table) != current.get(table) { return Err(refuse(StatusCode::CONFLICT, "native_schema_catalog_mismatch")); }
    }
    Ok(())
}

fn task_lease_loss_step(flavor: Flavor) -> i64 {
    match flavor { Flavor::MySql => 19, Flavor::MariaDb => 18 }
}

fn workflow_task_table(rows: Catalog) -> Option<(String, String)> {
    rows.into_iter().find(|(name, _)| name == "workflow_tasks")
}

fn pre_lease_loss_task_table(flavor: Flavor) -> Result<Option<(String, String)>> {
    let (_, old) = flavor.earlier(task_lease_loss_step(flavor)).expect("retained lease loss predecessor");
    Ok(workflow_task_table(serde_json::from_str(&canonical(old))?))
}

pub(super) async fn ensure_task_lease_loss(connection: &mut MySqlConnection, flavor: Flavor) -> Result<()> {
    let found = workflow_task_table(catalog(connection).await?);
    // The task table this step produces, before the same-run fence index.
    let expected = pre_fence_task_table(flavor)?;
    if found == expected || found == workflow_task_table(flavor.expected()?) {
        return Ok(());
    }
    if found != pre_lease_loss_task_table(flavor)? {
        return Err(refuse(StatusCode::CONFLICT, "native_schema_catalog_mismatch"));
    }
    connection.execute(flavor.step(task_lease_loss_step(flavor)).expect("compiled lease loss step")).await?;
    if workflow_task_table(catalog(connection).await?) != expected {
        return Err(refuse(StatusCode::CONFLICT, "native_schema_catalog_mismatch"));
    }
    Ok(())
}

/// The step that installs child summary checkpoints: MySQL's from 20,
/// MariaDB's from 19.
fn child_summary_checkpoint_step(flavor: Flavor) -> i64 {
    match flavor { Flavor::MySql => 20, Flavor::MariaDb => 19 }
}

pub(super) async fn ensure_child_summary_checkpoints(connection: &mut MySqlConnection, flavor: Flavor) -> Result<()> {
    let actual: BTreeMap<_, _> = catalog(connection).await?.into_iter().collect();
    let current: BTreeMap<_, _> = flavor.expected()?.into_iter().collect();
    let (_, earlier) = flavor.earlier(child_summary_checkpoint_step(flavor)).expect("retained checkpoint predecessor");
    let earlier: BTreeMap<_, _> = serde_json::from_str::<Catalog>(&canonical(earlier))?.into_iter().collect();
    let table = "dw_child_summary_checkpoints";
    let indexed = "workflow_child_calls";
    let (table_sql, _) = CHILD_SUMMARY_CHECKPOINT_SCHEMA.split_once(CHILD_SUMMARY_CALL_INDEX)
        .expect("compiled checkpoint index statement");
    let (_, installed) = physical_scope(connection).await?;
    if !installed.empty() && (actual.get(table) != current.get(table) || actual.get(indexed) != current.get(indexed)) {
        return Err(refuse(StatusCode::CONFLICT, "native_schema_catalog_mismatch"));
    }
    match actual.get(table) {
        None => { connection.execute(AssertSqlSafe(table_sql)).await?; }
        Some(ddl) if current.get(table) == Some(ddl) => {}
        _ => return Err(refuse(StatusCode::CONFLICT, "native_schema_catalog_mismatch")),
    }
    if actual.get(indexed) == earlier.get(indexed) {
        connection.execute(CHILD_SUMMARY_CALL_INDEX).await?;
    } else if actual.get(indexed) != current.get(indexed) {
        return Err(refuse(StatusCode::CONFLICT, "native_schema_catalog_mismatch"));
    }
    let actual: BTreeMap<_, _> = catalog(connection).await?.into_iter().collect();
    if actual.get(table) != current.get(table) || actual.get(indexed) != current.get(indexed) {
        return Err(refuse(StatusCode::CONFLICT, "native_schema_catalog_mismatch"));
    }
    ensure_child_summary_code(connection).await
}

async fn ensure_child_summary_code(connection: &mut MySqlConnection) -> Result<()> {
    let (_, installed) = physical_scope(connection).await?;
    for statement in installed.missing_ddl() {
        if let Err(error) = connection.execute(AssertSqlSafe(statement)).await {
            if child_summary_trigger_privilege_required(&error) {
                return Err(refuse(StatusCode::CONFLICT, "native_child_checkpoint_trigger_privilege_required"));
            }
            return Err(error.into());
        }
    }
    if !physical_scope(connection).await?.1.complete() {
        return Err(refuse(StatusCode::CONFLICT, "native_schema_catalog_mismatch"));
    }
    Ok(())
}

/// The step that adds the same-run fence index (server#1327): MySQL's from
/// 21, MariaDB's from 20.
fn run_fence_step(flavor: Flavor) -> i64 {
    match flavor { Flavor::MySql => 21, Flavor::MariaDb => 20 }
}

/// The task table before the same-run fence index, which every step since
/// task lease loss leaves in place.
fn pre_fence_task_table(flavor: Flavor) -> Result<Option<(String, String)>> {
    let (_, old) = flavor.earlier(run_fence_step(flavor)).expect("retained run fence predecessor");
    Ok(workflow_task_table(serde_json::from_str(&canonical(old))?))
}

/// A catalog in which the task table still lacks the same-run fence index.
fn before_run_fence(flavor: Flavor, mut rows: Catalog) -> Result<Catalog> {
    let Some((_, old)) = pre_fence_task_table(flavor)? else { return Ok(rows) };
    for (table, ddl) in &mut rows {
        if table == "workflow_tasks" {
            *ddl = old.clone();
        }
    }
    Ok(rows)
}

/// CREATE INDEX commits on its own, and InnoDB builds it in place without
/// blocking writes. A resumed upgrade or takeover accepts the task table only
/// in its exact predecessor or exact current definition, never by index name.
pub(super) async fn ensure_run_fence_index(connection: &mut MySqlConnection, flavor: Flavor) -> Result<()> {
    let found = workflow_task_table(catalog(connection).await?);
    let expected = workflow_task_table(flavor.expected()?);
    if found == expected {
        return Ok(());
    }
    if found != pre_fence_task_table(flavor)? {
        return Err(refuse(StatusCode::CONFLICT, "native_schema_catalog_mismatch"));
    }
    connection.execute(flavor.step(run_fence_step(flavor)).expect("compiled run fence step")).await?;
    if workflow_task_table(catalog(connection).await?) != expected {
        return Err(refuse(StatusCode::CONFLICT, "native_schema_catalog_mismatch"));
    }
    Ok(())
}

fn child_summary_trigger_privilege_required(error: &sqlx::Error) -> bool {
    // 1419: binary logging without SUPER or trusted function creators.
    // 1370: replacing a function this account was not granted ALTER ROUTINE
    // on, as when an operator installed it from the staged DDL.
    error.as_database_error().is_some_and(|error| {
        error.try_downcast_ref::<sqlx::mysql::MySqlDatabaseError>().is_some_and(|error| matches!(error.number(), 1419 | 1370))
    })
}

/// The step that replaces the checkpoint catalog function's body
/// (server#1339): MySQL's from 22, MariaDB's from 21.
fn child_summary_catalog_lookup_step(flavor: Flavor) -> i64 {
    match flavor { Flavor::MySql => 22, Flavor::MariaDb => 21 }
}

fn dispatch_summary_step(flavor: Flavor) -> i64 {
    match flavor { Flavor::MySql => 15, Flavor::MariaDb => 14 }
}

/// ALTER TABLE commits independently. Resumption admits the complete old or
/// new table definition, and verifies the resulting table before proceeding.
pub(super) async fn ensure_dispatch_summary(connection: &mut MySqlConnection, flavor: Flavor) -> Result<()> {
    let table = |rows: Catalog| rows.into_iter().find(|(name, _)| name == "workflow_schedules");
    let found = table(catalog(connection).await?);
    let expected = table(flavor.expected()?);
    if found == expected { return Ok(()); }
    let step = dispatch_summary_step(flavor);
    let (_, old) = flavor.earlier(step).expect("retained dispatch predecessor");
    if found != table(serde_json::from_str(&canonical(old))?) {
        return Err(refuse(StatusCode::CONFLICT, "native_schema_catalog_mismatch"));
    }
    connection.execute(flavor.step(step).expect("compiled dispatch summary step")).await?;
    if table(catalog(connection).await?) != expected {
        return Err(refuse(StatusCode::CONFLICT, "native_schema_catalog_mismatch"));
    }
    Ok(())
}

/// An implicit-commit step may already have committed before interruption.
/// Accept only the exact old or exact new task table, never a column-name probe.
pub(super) async fn ensure_ready_origin(connection: &mut MySqlConnection, flavor: Flavor) -> Result<()> {
    let (_, old) = flavor.earlier(8).expect("released v8 schema");
    let old: Catalog = serde_json::from_str(&canonical(old))?;
    let actual = catalog(connection).await?;
    let task = |rows: Catalog| rows.into_iter().find(|(name, _)| name == "workflow_tasks");
    let found = task(actual.clone());
    if found == task(flavor.v12_catalog()?) || found == task(flavor.expected()?) || found == pre_lease_loss_task_table(flavor)?
        || found == pre_fence_task_table(flavor)? { return Ok(()); }
    let (_, ready) = flavor.earlier(10).expect("retained v10 schema");
    let expected = task(serde_json::from_str(&canonical(ready))?);
    match task(actual) {
        found if found == expected => Ok(()),
        found if found == task(old) => {
            connection.execute(flavor.step(8).expect("compiled ready-origin step")).await?;
            if task(catalog(connection).await?) != expected {
                return Err(refuse(StatusCode::CONFLICT, "native_schema_catalog_mismatch"));
            }
            Ok(())
        }
        _ => Err(refuse(StatusCode::CONFLICT, "native_schema_catalog_mismatch")),
    }
}

/// Each implicit-commit DDL may already have completed. Only an exact old
/// or exact current table is admissible when resuming the fenced upgrade.
pub(super) async fn ensure_poll_expiry_indexes(connection: &mut MySqlConnection, flavor: Flavor) -> Result<()> {
    let (_, old) = flavor.earlier(9).expect("retained v9 schema");
    let old: Catalog = serde_json::from_str(&canonical(old))?;
    let expected = flavor.v12_catalog()?;
    let current = flavor.expected()?;
    for (table, sql) in [
        ("dw_query_cache", "CREATE INDEX dw_query_cache_expiration ON dw_query_cache (expiration)"),
        ("dw_poll_receipts", "CREATE INDEX dw_poll_receipts_expiration ON dw_poll_receipts (expires_at)"),
    ] {
        let found = catalog(connection).await?;
        let row = |rows: &Catalog| rows.iter().find(|(name, _)| name == table).cloned();
        if row(&found) == row(&expected) || row(&found) == row(&current) { continue; }
        if row(&found) != row(&old) { return Err(refuse(StatusCode::CONFLICT, "native_schema_catalog_mismatch")); }
        connection.execute(sql).await?;
        if row(&catalog(connection).await?) != row(&expected) {
            return Err(refuse(StatusCode::CONFLICT, "native_schema_catalog_mismatch"));
        }
    }
    Ok(())
}

/// Index DDL commits independently. A resumed upgrade accepts only the
/// exact retained task table or the exact new definition, never name presence.
pub(super) async fn ensure_scoped_dispatch_index(connection: &mut MySqlConnection, flavor: Flavor) -> Result<()> {
    let (_, old) = flavor.earlier(10).expect("retained v10 schema");
    let old: Catalog = serde_json::from_str(&canonical(old))?;
    let task = |rows: Catalog| rows.into_iter().find(|(name, _)| name == "workflow_tasks");
    let expected = task(flavor.v12_catalog()?);
    let found = task(catalog(connection).await?);
    if found == expected || found == task(flavor.expected()?) || found == pre_lease_loss_task_table(flavor)?
        || found == pre_fence_task_table(flavor)? { return Ok(()); }
    if found != task(old) { return Err(refuse(StatusCode::CONFLICT, "native_schema_catalog_mismatch")); }
    connection.execute(flavor.step(10).expect("compiled scoped dispatch index")).await?;
    if task(catalog(connection).await?) != expected {
        return Err(refuse(StatusCode::CONFLICT, "native_schema_catalog_mismatch"));
    }
    Ok(())
}

fn options(options: MySqlConnectOptions) -> MySqlConnectOptions {
    options
        .charset("utf8mb4")
        .collation("utf8mb4_unicode_ci")
        .timezone(Some("+00:00".into()))
}
pub(super) async fn configure(connection: &mut MySqlConnection) -> Result<()> {
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

/// `CHARACTER SET x COLLATE x_…` and `COLLATE x_…` define the same column,
/// because a collation names its character set. MySQL prints the longer form
/// for columns restored from a dump and the shorter one for the same columns
/// as PHP's migrations created them, so catalogs compare the shorter form.
pub(super) fn canonical(sql: &str) -> String {
    const CHARSET: &str = " CHARACTER SET ";
    let mut result = String::with_capacity(sql.len());
    let mut rest = sql;
    while let Some(at) = rest.find(CHARSET) {
        let after = &rest[at + CHARSET.len()..];
        let length = after
            .bytes()
            .take_while(|b| b.is_ascii_alphanumeric() || *b == b'_')
            .count();
        let (charset, tail) = after.split_at(length);
        if length > 0
            && tail
                .strip_prefix(" COLLATE ")
                .is_some_and(|collation| {
                    collation.starts_with(charset) && collation[length..].starts_with('_')
                })
        {
            result.push_str(&rest[..at]);
        } else {
            result.push_str(&rest[..at + CHARSET.len() + length]);
        }
        rest = tail;
    }
    result.push_str(rest);
    result
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

async fn table_inventory(connection: &mut MySqlConnection) -> Result<(Vec<String>, bool)> {
    let relations: Vec<(String, String)> = sqlx::query_as(
        "SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME")
        .fetch_all(connection).await?;
    let mut tables = Vec::new();
    let mut checkpoint_view = false;
    for (name, kind) in relations {
        match kind.as_str() {
            "BASE TABLE" => tables.push(name),
            "VIEW" if name == checkpoint_catalog::VIEW => checkpoint_view = true,
            _ => return Err(refuse(StatusCode::CONFLICT, "existing_database_requires_qualified_takeover")),
        }
    }
    Ok((tables, checkpoint_view))
}

async fn physical_scope(connection: &mut MySqlConnection) -> Result<(Vec<String>, CheckpointCode)> {
    let (tables, view) = table_inventory(connection).await?;
    let marked = tables.iter().any(|name| name == "dw_server_schema");
    let code = checkpoint_catalog::direct(connection, marked, view, None).await?;
    Ok((tables, code))
}

async fn scope(connection: &mut MySqlConnection) -> Result<Vec<String>> {
    Ok(physical_scope(connection).await?.0)
}

async fn catalog_tables(connection: &mut MySqlConnection, tables: Vec<String>) -> Result<Catalog> {
    let mut result = vec![];
    for name in tables {
        let row = sqlx::query(table_sql("SHOW CREATE TABLE ", &name, "")?)
            .fetch_one(&mut *connection)
            .await?;
        result.push((name, canonical(&definition(row.try_get(1)?))));
    }
    Ok(result)
}

async fn catalog(connection: &mut MySqlConnection) -> Result<Catalog> {
    let tables = scope(connection).await?;
    catalog_tables(connection, tables).await
}

pub(super) async fn php_source_catalog(connection: &mut MySqlConnection) -> Result<(Catalog, &'static str)> {
    let marked: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LOWER(TABLE_NAME)='dw_server_schema'")
        .fetch_one(&mut *connection).await?;
    if marked != 0 {
        return Err(refuse(StatusCode::CONFLICT, "upgrade_source_already_owned"));
    }
    // Table-only grants can hide an ownership marker from information_schema.
    // A denied probe is unknown ownership, never proof of absence.
    match sqlx::query("SELECT 1 FROM `dw_server_schema` LIMIT 0")
        .fetch_all(&mut *connection)
        .await
    {
        Err(sqlx::Error::Database(error))
            if error
                .try_downcast_ref::<sqlx::mysql::MySqlDatabaseError>()
                .is_some_and(|error| error.number() == 1146 && error.code() == Some("42S02")) => {}
        Ok(_) => return Err(refuse(StatusCode::CONFLICT, "upgrade_source_already_owned")),
        Err(_) => {
            return Err(refuse(
                StatusCode::SERVICE_UNAVAILABLE,
                "upgrade_source_ownership_unknown",
            ));
        }
    }
    let actual = catalog(connection).await?;
    source_layout(connection, actual).await
}

/// The PHP source catalog of a database whose takeover has written its
/// marker and nothing else yet: native's exact marker table, set aside.
pub(super) async fn fenced_source_catalog(connection: &mut MySqlConnection) -> Result<(Catalog, &'static str)> {
    let flavor = Flavor::detect(connection).await?;
    let marker = flavor
        .expected()?
        .into_iter()
        .find(|(table, _)| table == "dw_server_schema");
    let mut actual = catalog(connection).await?;
    let position = actual.iter().position(|(table, _)| table == "dw_server_schema");
    match position {
        Some(position) if Some(&actual[position]) == marker.as_ref() => {
            actual.remove(position);
        }
        _ => {
            return Err(refuse(
                StatusCode::CONFLICT,
                "upgrade_takeover_marker_unqualified",
            ));
        }
    }
    source_layout(connection, actual).await
}

async fn source_layout(
    connection: &mut MySqlConnection,
    mut actual: Catalog,
) -> Result<(Catalog, &'static str)> {
    let flavor = Flavor::detect(connection).await?;
    let (_, frozen) = flavor.earlier(8).expect("released v8 schema");
    let expected: Catalog = serde_json::from_str::<Catalog>(&canonical(frozen))?.into_iter()
        .filter(|(table, _)| !super::upgrade::native_relation(table))
        .map(|(table, ddl)| (table, ddl.replace(
            ",\n  KEY `dw_workflow_tasks_poll` (`namespace`(64),`queue`(64),`task_type`(16),`status`(16),`available_at`)", "")))
        .collect();
    // MariaDB's information_schema collation ignores some underscores. Only
    // canonicalize catalog presentation; preserve every full definition.
    actual.sort_by(|left, right| left.0.cmp(&right.0));
    let deltas = match flavor {
        Flavor::MySql => [
            canonical(include_str!("../../upgrade/php-2.8.0/mysql.json")),
            canonical(include_str!("../../upgrade/php-2.9.7/mysql.json")),
            canonical(include_str!("../../upgrade/php-2.11.1/mysql.json")),
            canonical(include_str!("../../upgrade/php-2.11.1-from-2.9.7/mysql.json")),
            canonical(include_str!("../../upgrade/php-2.11.3/mysql.json")),
            canonical(include_str!("../../upgrade/php-2.11.3-from-2.9.7/mysql.json")),
            canonical(include_str!("../../upgrade/php-2.11.8/mysql.json")),
            canonical(include_str!("../../upgrade/php-2.11.8-from-2.9.7/mysql.json")),
        ],
        Flavor::MariaDb => [
            canonical(include_str!("../../upgrade/php-2.8.0/mariadb.json")),
            canonical(include_str!("../../upgrade/php-2.9.7/mariadb.json")),
            canonical(include_str!("../../upgrade/php-2.11.1/mariadb.json")),
            canonical(include_str!("../../upgrade/php-2.11.1-from-2.9.7/mariadb.json")),
            canonical(include_str!("../../upgrade/php-2.11.3/mariadb.json")),
            canonical(include_str!("../../upgrade/php-2.11.3-from-2.9.7/mariadb.json")),
            canonical(include_str!("../../upgrade/php-2.11.8/mariadb.json")),
            canonical(include_str!("../../upgrade/php-2.11.8-from-2.9.7/mariadb.json")),
        ],
    };
    let deltas = deltas.each_ref().map(String::as_str);
    let layout = super::upgrade::source_layout(&actual, expected.clone(), deltas, |row| row.0.clone());
    // Laravel's separate mariadb connection stores the backup hold owner as
    // uuid rather than char(36); every release layout is otherwise the same.
    let layout = match (layout, flavor) {
        (Err(_), Flavor::MariaDb) => {
            let driver = super::upgrade::source_catalog_variant(
                &expected,
                &canonical(include_str!("../../upgrade/mariadb-driver.json")),
            )?;
            match super::upgrade::source_layout(&actual, driver, deltas, |row| row.0.clone())? {
                "frozen" => "frozen+mariadb-driver",
                "php-2.8.0" => "php-2.8.0+mariadb-driver",
                "php-2.9.7" => "php-2.9.7+mariadb-driver",
                "php-2.11.1" => "php-2.11.1+mariadb-driver",
                "php-2.11.1-from-2.9.7" => "php-2.11.1-from-2.9.7+mariadb-driver",
                "php-2.11.3" => "php-2.11.3+mariadb-driver",
                "php-2.11.3-from-2.9.7" => "php-2.11.3-from-2.9.7+mariadb-driver",
                "php-2.11.8" => "php-2.11.8+mariadb-driver",
                "php-2.11.8-from-2.9.7" => "php-2.11.8-from-2.9.7+mariadb-driver",
                _ => {
                    return Err(refuse(
                        StatusCode::CONFLICT,
                        "upgrade_source_catalog_unqualified",
                    ));
                }
            }
        }
        (layout, _) => layout?,
    };
    Ok((actual, layout))
}

pub(super) fn source_options(options: MySqlConnectOptions) -> MySqlConnectOptions {
    self::options(options)
}

async fn history(
    connection: &mut MySqlConnection,
    flavor: Flavor,
    initializing: bool,
    tables: &[String],
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
        || markers[0].1 != flavor.version()
        || markers[0].2 != flavor.checksum()
    {
        return Err(match markers.as_slice() {
            [(found, version, _)] if !initializing && (found != engine || *version != flavor.version()) => {
                super::schema_upgrade::marker_refusal(found, *version, flavor.version())
            }
            _ => refuse(StatusCode::CONFLICT, "unsupported_rust_schema"),
        });
    }
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
        || applied[0].0 != flavor.version()
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

/// Export the missing, compiled checkpoint code from a fenced schema.
pub(super) async fn child_summary_checkpoint_ddl(connect_options: MySqlConnectOptions) -> Result<String> {
    let mut connection = MySqlConnection::connect_with(&options(connect_options)).await?;
    configure(&mut connection).await?;
    let mut tx = connection.begin_with("START TRANSACTION READ ONLY").await?;
    let flavor = Flavor::detect(&mut tx).await?;
    let (tables, installed) = physical_scope(&mut tx).await?;
    let refused = || refuse(StatusCode::CONFLICT, "native_child_checkpoint_schema_not_staged");
    if !tables.iter().any(|table| table == "dw_server_schema") {
        return Err(refused());
    }
    let markers: Vec<(String, i64, Vec<u8>)> =
        sqlx::query_as("SELECT engine,version,bootstrap_checksum FROM dw_server_schema LIMIT 2")
            .fetch_all(&mut *tx)
            .await?;
    let [(engine, version, checksum)] = markers.as_slice() else {
        return Err(refused());
    };
    let mut expected = flavor.expected()?;
    match engine.as_str() {
        "rust-initializing" if verify_initializing(&mut tx, flavor).await? == Some(false) => {}
        super::schema_upgrade::UPGRADING_ENGINE => {
            let (previous, _) = flavor.earlier(*version).ok_or_else(refused)?;
            let ledger: Vec<(i64, bool, Vec<u8>)> =
                sqlx::query_as("SELECT version,success,checksum FROM _sqlx_migrations ORDER BY version LIMIT 2")
                    .fetch_all(&mut *tx)
                    .await?;
            if checksum.as_slice() != previous.checksum.as_ref() || ledger != [(*version, true, checksum.clone())] {
                return Err(refused());
            }
        }
        engine if engine.starts_with(TAKEOVER_ENGINE)
            && *version == super::upgrade::takeover::MYSQL_FINAL_STEP
            && checksum.len() == 32 =>
        {
            // The initial ledger is created after the takeover's final DDL.
            expected.retain(|(table, _)| table != "_sqlx_migrations");
        }
        _ => return Err(refused()),
    }
    // An upgrade or takeover pauses for this code before it adds the
    // same-run fence index, which a fresh bootstrap creates with its table.
    let actual = catalog(&mut tx).await?;
    if actual != expected && actual != before_run_fence(flavor, expected)? {
        return Err(refused());
    }
    let (database, account): (String, String) =
        sqlx::query_as("SELECT DATABASE(),CURRENT_USER()").fetch_one(&mut *tx).await?;
    tx.rollback().await?;
    let (user, host) = account.rsplit_once('@').ok_or_else(refused)?;
    let quote = |identifier: &str| format!("`{}`", identifier.replace('`', "``"));
    let definer = format!("{}@{}", quote(user), quote(host));
    let mut ddl = format!("USE {};\n", quote(&database));
    for create in installed.missing_ddl() {
        // The predecessor catalog function is dropped before its replacement.
        if create.starts_with("DROP ") {
            ddl.push_str(&create);
            continue;
        }
        let statement = create.strip_prefix("CREATE ").expect("compiled checkpoint statement");
        ddl.push_str(&format!("CREATE DEFINER={definer} {statement}"));
    }
    Ok(ddl)
}

pub(super) async fn verify_history(connection: &mut MySqlConnection) -> Result<()> {
    let flavor = Flavor::detect(connection).await?;
    let (tables, code) = physical_scope(connection).await?;
    history(connection, flavor, false, &tables).await?;
    if !code.complete() {
        return Err(checkpoint_catalog::mismatch());
    }
    Ok(())
}

pub(super) async fn verify(connection: &mut MySqlConnection, flavor: Flavor) -> Result<()> {
    let (tables, code) = physical_scope(connection).await?;
    history(connection, flavor, false, &tables).await?;
    if !code.complete() || catalog_tables(connection, tables).await? != flavor.expected()? {
        return Err(checkpoint_catalog::mismatch());
    }
    Ok(())
}

async fn verify_inspection(connection: &mut MySqlConnection, flavor: Flavor) -> Result<()> {
    let (tables, view) = table_inventory(connection).await?;
    history(connection, flavor, false, &tables).await?;
    if !view || catalog_tables(connection, tables).await? != flavor.expected()? {
        return Err(checkpoint_catalog::mismatch());
    }
    // The ordinary application account attests every definition directly.
    // A SELECT-only account cannot see triggers, but can read the fixed
    // native metadata view without any write or DDL privileges.
    let inspection = sqlx::query("SHOW CREATE TRIGGER dw_child_summary_history_insert")
        .fetch_one(&mut *connection).await;
    match inspection {
        Ok(_) => {
            let definer: String = sqlx::query_scalar(
                "SELECT DEFINER FROM information_schema.VIEWS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dw_child_summary_catalog'")
                .fetch_one(&mut *connection).await?;
            if !checkpoint_catalog::direct(connection, true, true, Some(&definer)).await?.complete() {
                return Err(checkpoint_catalog::mismatch());
            }
        }
        Err(error) if error.as_database_error()
            .and_then(|error| error.try_downcast_ref::<sqlx::mysql::MySqlDatabaseError>())
            .is_some_and(|error| matches!(error.number(), 1142 | 1227)
                && error.message().contains("TRIGGER")) =>
        {
            checkpoint_catalog::read_only(connection).await?;
        }
        _ => return Err(checkpoint_catalog::mismatch()),
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
    let tables: Vec<String> = actual.iter().map(|(name, _)| name.clone()).collect();
    let applied = history(connection, flavor, true, &tables).await?;
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
    // Another node is between creating the marker table and recording its
    // marker (DDL commits on its own). Its bootstrap lock decides the rest.
    if engines.is_empty() && tables == ["dw_server_schema"] {
        return Ok(());
    }
    if engines == ["rust-initializing"] {
        verify_initializing(connection, flavor).await?;
        Ok(())
    } else if engines.iter().any(|engine| engine.starts_with(TAKEOVER_ENGINE)) {
        // An interrupted takeover resumes only through upgrade-takeover.
        Err(refuse(StatusCode::CONFLICT, "native_takeover_incomplete"))
    } else {
        verify(connection, flavor).await
    }
}

/// Upgrade an earlier native schema version to this one under the bootstrap
/// named lock. DDL commits on its own here, so the marker first moves to
/// `rust-upgrading`, which fences every node, and a rerun resumes from
/// whatever the interrupted run left. Returns the backend and the version
/// found; a current database is only verified.
pub(super) async fn upgrade(connect_options: MySqlConnectOptions) -> Result<(&'static str, i64, i64)> {
    use super::schema_upgrade::{DEVELOPMENT_ENGINE, UPGRADING_ENGINE, marker_refusal};
    let mut connection = MySqlConnection::connect_with(&options(connect_options)).await?;
    let result = async {
        configure(&mut connection).await?;
        let locked: Option<i64> = sqlx::query_scalar("SELECT GET_LOCK(CONCAT('dw-native-bootstrap:',SHA1(DATABASE())),60)")
            .fetch_one(&mut connection)
            .await?;
        if locked != Some(1) {
            return Err(refuse(StatusCode::SERVICE_UNAVAILABLE, "native_initialization_busy"));
        }
        let flavor = Flavor::detect(&mut connection).await?;
        let (tables, installed_code) = physical_scope(&mut connection).await?;
        if !tables.iter().any(|name| name == "dw_server_schema") {
            return Err(refuse(StatusCode::CONFLICT, "native_schema_not_initialized"));
        }
        let markers: Vec<(String, i64, Vec<u8>)> =
            sqlx::query_as("SELECT engine,version,bootstrap_checksum FROM dw_server_schema")
                .fetch_all(&mut connection)
                .await?;
        let [(engine, from, checksum)] = markers.as_slice() else {
            return Err(refuse(StatusCode::CONFLICT, "unsupported_rust_schema"));
        };
        let from = *from;
        if engine == DEVELOPMENT_ENGINE && from == flavor.version() {
            verify(&mut connection, flavor).await?;
            return Ok((flavor.name(), from, flavor.version()));
        }
        let Some((earlier, earlier_catalog)) = flavor
            .earlier(from)
            .filter(|_| engine == DEVELOPMENT_ENGINE || engine == UPGRADING_ENGINE)
        else {
            return Err(marker_refusal(engine, from, flavor.version()));
        };
        if checksum.as_slice() != earlier.checksum.as_ref() {
            return Err(refuse(StatusCode::CONFLICT, "unsupported_rust_schema"));
        }
        let applied: Vec<(i64, bool, Vec<u8>)> =
            sqlx::query_as("SELECT version,success,checksum FROM _sqlx_migrations ORDER BY version")
                .fetch_all(&mut connection)
                .await?;
        if applied != [(from, true, earlier.checksum.to_vec())] {
            return Err(refuse(StatusCode::CONFLICT, "native_migration_history_mismatch"));
        }
        let earlier_catalog: Catalog = serde_json::from_str(&canonical(earlier_catalog))?;
        let actual = catalog(&mut connection).await?;
        if engine == DEVELOPMENT_ENGINE {
            // Checkpoint code exists exactly from the checkpoint schema on,
            // with the predecessor catalog function body until the catalog
            // lookup step. Under this marker that step has not started, so
            // only the exact predecessor function is accepted; a resumed
            // upgrade accepts a dropped one.
            let code_matches = if from > child_summary_checkpoint_step(flavor) {
                installed_code.predecessor_routine && installed_code.awaiting_routine()
            } else {
                installed_code.empty()
            };
            if !code_matches || actual != earlier_catalog {
                return Err(refuse(StatusCode::CONFLICT, "native_schema_catalog_mismatch"));
            }
            let fenced = sqlx::query("UPDATE dw_server_schema SET engine=? WHERE engine=? AND version=? AND bootstrap_checksum=?")
                .bind(UPGRADING_ENGINE)
                .bind(DEVELOPMENT_ENGINE)
                .bind(from)
                .bind(earlier.checksum.as_ref())
                .execute(&mut connection)
                .await?;
            if fenced.rows_affected() != 1 {
                return Err(refuse(StatusCode::CONFLICT, "unsupported_rust_schema"));
            }
        } else {
            // A resumed upgrade: every relation is exactly the earlier or the
            // new definition, and every earlier relation is still there.
            let earlier_tables: BTreeMap<_, _> = earlier_catalog.into_iter().collect();
            let current: BTreeMap<_, _> = flavor.expected()?.into_iter().collect();
            // On MySQL a table may also stand at its schema-12 definition,
            // before the JSON text step reached it.
            let v12: BTreeMap<_, _> = flavor.v12_catalog()?.into_iter().collect();
            // The ready-origin step can commit before the scoped index step.
            // Accept that exact retained intermediate task definition too.
            let intermediate: Catalog = serde_json::from_str(&canonical(flavor.earlier(10).expect("retained v10 schema").1))?;
            let intermediate_task = intermediate.into_iter().find(|(name, _)| name == "workflow_tasks");
            let pre_binding: BTreeMap<_, _> = serde_json::from_str::<Catalog>(&canonical(flavor.earlier(storage_binding_step(flavor)).expect("retained storage predecessor").1))?.into_iter().collect();
            // An indexed table may stand at its definition before the
            // maintenance scan step, after an earlier step changed it.
            let pre_scan = pre_scan_index_tables(flavor)?;
            let pre_lease_loss = pre_lease_loss_task_table(flavor)?;
            let pre_fence = pre_fence_task_table(flavor)?;
            let partial = actual.iter().all(|(name, ddl)| {
                earlier_tables.get(name) == Some(ddl) || current.get(name) == Some(ddl) || v12.get(name) == Some(ddl) || (["runtime_external_payloads", "workflow_namespaces"].contains(&name.as_str()) && pre_binding.get(name) == Some(ddl))
                    || pre_scan.get(name) == Some(ddl)
                    || name == "workflow_tasks" && [intermediate_task.as_ref(), pre_lease_loss.as_ref(), pre_fence.as_ref()]
                        .into_iter().flatten().any(|(_, task)| task == ddl)
            }) && earlier_tables
                .keys()
                .all(|name| actual.iter().any(|(table, _)| table == name));
            if !partial {
                return Err(refuse(StatusCode::CONFLICT, "native_schema_catalog_mismatch"));
            }
        }
        // Each step's own marker update names the development engine, so it
        // changes nothing while the marker fences the upgrade.
        for version in from..flavor.version() {
            if version == child_summary_catalog_lookup_step(flavor) {
                ensure_child_summary_code(&mut connection).await?;
                continue;
            }
            if version == run_fence_step(flavor) {
                ensure_run_fence_index(&mut connection, flavor).await?;
                continue;
            }
            if version == child_summary_checkpoint_step(flavor) {
                ensure_child_summary_checkpoints(&mut connection, flavor).await?;
                continue;
            }
            if version == task_lease_loss_step(flavor) {
                ensure_task_lease_loss(&mut connection, flavor).await?;
                continue;
            }
            if version == maintenance_scan_step(flavor) {
                ensure_maintenance_scan_indexes(&mut connection, flavor).await?;
                continue;
            }
            if version == child_supervisions_step(flavor) {
                ensure_child_supervisions(&mut connection, flavor).await?;
                continue;
            }
            if version == storage_binding_step(flavor) {
                ensure_external_storage_bindings(&mut connection, flavor).await?;
                continue;
            }
            if version == dispatch_summary_step(flavor) {
                ensure_dispatch_summary(&mut connection, flavor).await?;
                continue;
            }
            if version == task_queue_pauses_step(flavor) {
                ensure_task_queue_pauses(&mut connection, flavor).await?;
                continue;
            }
            if version == command_intents_step(flavor) {
                ensure_command_intents(&mut connection, flavor).await?;
                continue;
            }
            if version == 12 {
                ensure_json_text(&mut connection, flavor).await?;
                continue;
            }
            if version == 8 {
                ensure_ready_origin(&mut connection, flavor).await?;
                continue;
            }
            if version == 9 {
                ensure_poll_expiry_indexes(&mut connection, flavor).await?;
                continue;
            }
            if version == 10 {
                ensure_scoped_dispatch_index(&mut connection, flavor).await?;
                continue;
            }
            if version == 11 {
                ensure_run_command_contracts(&mut connection, flavor).await?;
                continue;
            }
            let sql = flavor
                .step(version)
                .ok_or(refuse(StatusCode::CONFLICT, "unsupported_rust_schema"))?;
            connection
                .execute(AssertSqlSafe(sql.replace("CREATE TABLE ", "CREATE TABLE IF NOT EXISTS ")))
                .await?;
        }
        if catalog(&mut connection).await? != flavor.expected()? {
            return Err(refuse(StatusCode::CONFLICT, "native_schema_catalog_mismatch"));
        }
        let migration = flavor.migration();
        let mut tx = connection.begin().await?;
        sqlx::query("DELETE FROM _sqlx_migrations WHERE version=?")
            .bind(from)
            .execute(&mut *tx)
            .await?;
        sqlx::query("INSERT INTO _sqlx_migrations(version,description,success,checksum,execution_time) VALUES (?,?,TRUE,?,0)")
            .bind(migration.version)
            .bind(migration.description.as_ref())
            .bind(migration.checksum.as_ref())
            .execute(&mut *tx)
            .await?;
        let marked = sqlx::query("UPDATE dw_server_schema SET engine=?,version=?,bootstrap_checksum=? WHERE engine=? AND version=? AND bootstrap_checksum=?")
            .bind(DEVELOPMENT_ENGINE)
            .bind(flavor.version())
            .bind(flavor.checksum())
            .bind(UPGRADING_ENGINE)
            .bind(from)
            .bind(earlier.checksum.as_ref())
            .execute(&mut *tx)
            .await?;
        if marked.rows_affected() != 1 {
            return Err(refuse(StatusCode::CONFLICT, "unsupported_rust_schema"));
        }
        tx.commit().await?;
        verify(&mut connection, flavor).await?;
        Ok::<_, super::RuntimeError>((flavor.name(), from, flavor.version()))
    }
    .await;
    // Named locks are session scoped, including on errors/process death.
    connection.close().await?;
    result
}

pub struct MySqlStorage {
    pool: MySqlPool,
}

fn runtime_pool_options() -> MySqlPoolOptions {
    runtime_pool_options_within(super::backend::ReplyBounds::RUNTIME)
}

/// [`runtime_pool_options`] with explicit reply bounds (tests shorten them).
/// A new connection starts with the statement reply bound and a released
/// one waits for the session reset and SQLx's release ping only up to the
/// release bound, then is discarded (Server #1368).
pub(super) fn runtime_pool_options_within(bounds: super::backend::ReplyBounds) -> MySqlPoolOptions {
    super::backend::test_idle_connections_within(MySqlPoolOptions::new(), bounds)
        .max_connections(crate::statement_cache::REQUEST_CONNECTIONS)
        .acquire_timeout(Duration::from_secs(5))
        .after_connect(move |connection, _| {
            Box::pin(async move {
                connection.set_reply_timeout(Some(bounds.statement));
                sqlx::query("SET lock_wait_timeout=5,innodb_lock_wait_timeout=5")
                    .execute(connection)
                    .await?;
                Ok(())
            })
        })
        .after_release(move |connection, _| {
            Box::pin(async move {
                connection.set_reply_timeout(Some(bounds.release));
                // A cancelled BEGIN can leave a physical transaction open
                // before SQLx increments its logical transaction depth. Drain
                // pending replies, then reset the session before pool reuse
                // unless it is known to be idle outside a transaction. The
                // released connection keeps its pool slot until this hook
                // returns, so an unneeded reset round trip kept the slot busy
                // for a whole database stall (Server #1204). After an error
                // reply the status is not known, so the session is reset; a
                // half-read statement prepare cannot be drained and fails the
                // hook. SQLx closes the connection if this hook fails.
                tokio::time::timeout(Duration::from_secs(5), async {
                    connection.read_pending_replies().await?;
                    if !connection.is_idle_outside_transaction() {
                        connection.execute("ROLLBACK").await?;
                    }
                    Ok::<_, sqlx::Error>(())
                })
                .await
                .map_err(|_| {
                    sqlx::Error::Protocol("native MySQL pool reset deadline exceeded".into())
                })??;
                Ok(true)
            })
        })
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
            }
            // A node killed between creating the marker table and recording its
            // marker leaves the table empty. Preflight accepted that state only
            // when nothing else exists yet.
            let marked:i64=sqlx::query_scalar("SELECT COUNT(*) FROM dw_server_schema").fetch_one(&mut connection).await?;
            if marked==0 {
                sqlx::query("INSERT INTO dw_server_schema(engine,version,bootstrap_checksum) VALUES ('rust-initializing',?,?)").bind(flavor.version()).bind(flavor.checksum()).execute(&mut connection).await?;
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
                    let tables = migration.sql.as_ref().strip_suffix(checkpoint_catalog::bootstrap_sql())
                        .expect("compiled checkpoint code at the end of the bootstrap");
                    connection.execute(AssertSqlSafe(tables)).await?;
                    ensure_child_summary_code(&mut connection).await?;
                    sqlx::query("UPDATE _sqlx_migrations SET success=TRUE WHERE version=? AND checksum=? AND success=FALSE").bind(flavor.version()).bind(flavor.checksum()).execute(&mut connection).await?;
                } else if let Err(error) = flavor.migrator().run(&mut connection).await {
                    if matches!(&error, sqlx::migrate::MigrateError::ExecuteMigration(cause, _)
                        if child_summary_trigger_privilege_required(cause))
                    {
                        return Err(refuse(StatusCode::CONFLICT, "native_child_checkpoint_trigger_privilege_required"));
                    }
                    return Err(error.into());
                }
                if catalog(&mut connection).await?!=flavor.expected()? {return Err(refuse(StatusCode::CONFLICT,"native_schema_catalog_mismatch"));}
                let mut tx=connection.begin().await?;
                sqlx::query("UPDATE dw_server_schema SET engine='rust-development' WHERE engine='rust-initializing' AND version=? AND bootstrap_checksum=?").bind(flavor.version()).bind(flavor.checksum()).execute(&mut *tx).await?;
                tx.commit().await?;
            }
            verify(&mut connection,flavor).await?;
            Ok::<_,super::RuntimeError>(())
        }.await;
        // Named locks are session scoped, including on errors/process death.
        connection.close().await?;
        result?;
        let pool = runtime_pool_options().connect_with(connect_options).await?;
        // Server #1234: every session starts with the global packet limit.
        let packet: u64 = sqlx::query_scalar("SELECT CAST(@@max_allowed_packet AS UNSIGNED)").fetch_one(&pool).await?;
        super::payload_limits::record_database_packet(packet);
        Ok(Self { pool })
    }
    /// Open an initialized native database for an offline console command
    /// (Server #973). There is no bootstrap path: the pool's own connection
    /// must find the native marker and the exact schema, or the database is
    /// refused as it is. The check only reads, in one read-only transaction.
    pub(super) async fn open_existing(connect_options: MySqlConnectOptions) -> Result<MySqlPool> {
        let pool = runtime_pool_options()
            .max_connections(1)
            .connect_with(options(connect_options))
            .await?;
        let result = async {
            let mut connection = pool.acquire().await?;
            configure(&mut connection).await?;
            let flavor = Flavor::detect(&mut connection).await?;
            let mut tx = connection.begin_with("START TRANSACTION READ ONLY").await?;
            if !scope(&mut tx).await?.iter().any(|table| table == "dw_server_schema") {
                return Err(refuse(StatusCode::CONFLICT, "native_schema_not_initialized"));
            }
            verify(&mut tx, flavor).await?;
            tx.rollback().await?;
            Ok::<_, super::RuntimeError>(())
        }
        .await;
        if let Err(error) = result {
            pool.close().await;
            return Err(error);
        }
        Ok(pool)
    }
    pub async fn check(connect_options: MySqlConnectOptions) -> Result<()> {
        let mut connection = MySqlConnection::connect_with(&options(connect_options)).await?;
        let result = async {
            configure(&mut connection).await?;
            let flavor = Flavor::detect(&mut connection).await?;
            let mut tx = connection.begin_with("START TRANSACTION READ ONLY").await?;
            verify_inspection(&mut tx, flavor).await?;
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
#[path = "mysql_pool_tests.rs"]
mod pool_tests;

#[cfg(test)]
#[path = "mysql_stall_tests.rs"]
mod stall_tests;

#[cfg(test)]
#[path = "mysql_held_poll_bench.rs"]
mod held_poll_bench;

#[cfg(test)]
mod tests {

    #[test]
    fn supervision_predecessors_keep_their_pinned_source_checksums() {
        for (flavor, version, pinned) in [
            (Flavor::MySql, 17, include_str!("../../migrations/mysql/0017_checksum.txt")),
            (Flavor::MariaDb, 16, include_str!("../../migrations/mariadb/0016_checksum.txt")),
        ] {
            assert_eq!(checksum_hex(flavor.earlier(version).unwrap().0), pinned.trim());
            assert_eq!(child_supervisions_step(flavor), version);
            let previous: &str = flavor.earlier(version).unwrap().0.sql.as_ref();
            assert!(!previous.contains("dw_child_supervisions"));
            let sql: &str = flavor.migration().sql.as_ref();
            assert_eq!(sql.matches("CREATE TABLE IF NOT EXISTS dw_child_supervisions (").count(), 1);
            assert!(flavor.step(version).unwrap().contains("CREATE TABLE dw_child_supervisions ("));
        }
    }

    #[test]
    fn storage_predecessors_keep_their_pinned_source_checksums_and_bootstrap_has_no_alters() {
        for (flavor, version, pinned) in [
            (Flavor::MySql, 16, include_str!("../../migrations/mysql/0016_checksum.txt")),
            (Flavor::MariaDb, 15, include_str!("../../migrations/mariadb/0015_checksum.txt")),
        ] {
            assert_eq!(checksum_hex(flavor.earlier(version).unwrap().0), pinned.trim());
            let sql = flavor.migration().sql.as_ref();
            assert_eq!(sql.matches("`storage_binding` TEXT NULL").count(), 1);
            assert_eq!(sql.matches("`external_payload_storage_generation` BIGINT NOT NULL DEFAULT 0").count(), 1);
            assert!(!sql.contains("ALTER TABLE runtime_external_payloads"));
            assert!(!sql.contains("ALTER TABLE workflow_namespaces ADD COLUMN external_payload_storage_generation"));
        }
    }
    use super::*;
    use futures_util::FutureExt;
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
            .bind(flavor.version())
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
        sqlx::query("INSERT INTO _sqlx_migrations VALUES (?, 'interrupted qualification', CURRENT_TIMESTAMP, FALSE, ?, -1)").bind(flavor.version()).bind(flavor.checksum()).execute(connection).await.unwrap();
    }

    #[test]
    fn restored_column_character_sets_compare_as_their_collation() {
        let created = "CREATE TABLE `t` (\n  `a` varchar(26) COLLATE utf8mb4_unicode_ci NOT NULL,\n  `b` char(8) COLLATE ascii_bin\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        let restored = "CREATE TABLE `t` (\n  `a` varchar(26) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,\n  `b` char(8) CHARACTER SET ascii COLLATE ascii_bin\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        assert_eq!(canonical(restored), created);
        assert_eq!(canonical(created), created);
        // Only a character set its collation names is redundant.
        for kept in [
            "`a` text CHARACTER SET utf8 COLLATE utf8mb4_bin",
            "`a` text CHARACTER SET utf8mb4",
            "`a` text CHARACTER SET latin1 COLLATE utf8mb4_bin",
            "`a` text COMMENT ' CHARACTER SET '",
        ] {
            assert_eq!(canonical(kept), kept);
        }
    }

    /// Server #1234: a statement over the server's `max_allowed_packet` is a
    /// size fact, not an outage. A completion that meets it is refused with a
    /// typed, non-retryable 413, never `503 storage_unavailable`.
    #[tokio::test]
    #[ignore = "requires the existing disposable mysql source suite"]
    async fn qualification_packet_overflow_is_a_typed_size_refusal() {
        let database = Database::new().await;
        let storage = MySqlStorage::open(database.options.clone()).await.unwrap();
        let pool = storage.into_pool();
        let packet: u64 = sqlx::query_scalar("SELECT CAST(@@max_allowed_packet AS UNSIGNED)").fetch_one(&pool).await.unwrap();
        assert_eq!(super::super::payload_limits::database_packet_bytes(), packet);
        if packet <= 128 * 1024 * 1024 {
            let oversized = "z".repeat(packet as usize + 1024);
            let error = sqlx::query_scalar::<_, i64>("SELECT CAST(LENGTH(?) AS SIGNED)").bind(oversized)
                .fetch_one(&pool).await.map_err(super::super::RuntimeError::from).unwrap_err();
            match super::super::payload_limits::packet_refusal(error) {
                super::super::RuntimeError::Protocol { status, response } => {
                    assert_eq!(status, StatusCode::PAYLOAD_TOO_LARGE);
                    assert_eq!(response["reason"], "payload_too_large");
                    assert_eq!(response["limit_kind"], "database_max_allowed_packet");
                    assert_eq!(response["limit"], packet);
                }
                other => panic!("packet overflow was not a typed refusal: {other:?}"),
            }
        }
        // Other storage errors keep their own classification.
        let error = sqlx::query("SELECT * FROM dw_no_such_table").execute(&pool).await
            .map_err(super::super::RuntimeError::from).unwrap_err();
        assert!(matches!(super::super::payload_limits::packet_refusal(error), super::super::RuntimeError::Database(_)));
        pool.close().await;
        database.remove().await;
    }

    /// Server #1174: a runtime pool connection keeps a working set larger
    /// than SQLx's 100-entry default, so a statement is prepared once and
    /// never closed and prepared again. SQLx's default re-prepares all of it.
    #[tokio::test]
    #[ignore = "requires the existing disposable mysql source suite"]
    async fn qualification_runtime_statement_cache_holds_the_hot_path() {
        async fn status(connection: &mut MySqlConnection, name: &'static str) -> i64 {
            // Text protocol: the probe itself is neither prepared nor cached.
            let row = connection.fetch_one(name).await.unwrap();
            sqlx::Row::get::<String, _>(&row, 1).parse().unwrap()
        }
        const PREPARED: &str = "SHOW SESSION STATUS LIKE 'Com_stmt_prepare'";
        const CLOSED: &str = "SHOW SESSION STATUS LIKE 'Com_stmt_close'";
        let db = Database::new().await;
        let capacity = crate::statement_cache::capacity(None, None).unwrap();
        let working_set: i64 = 180;
        assert!(working_set > 100 && working_set as usize <= capacity);
        let storage = MySqlStorage::open(db.options.clone().statement_cache_capacity(capacity)).await.unwrap();
        let pool = storage.into_pool();
        let mut connection = pool.acquire().await.unwrap();
        for pass in 0..2 {
            let (prepared, closed) = (status(&mut connection, PREPARED).await, status(&mut connection, CLOSED).await);
            for statement in 0..working_set {
                let sql = AssertSqlSafe(format!("SELECT ?+{statement}"));
                let value: i64 = sqlx::query_scalar(sql).bind(1_i64).fetch_one(&mut *connection).await.unwrap();
                assert_eq!(value, statement + 1);
            }
            let prepared = status(&mut connection, PREPARED).await - prepared;
            let closed = status(&mut connection, CLOSED).await - closed;
            assert_eq!((prepared, closed), (if pass == 0 { working_set } else { 0 }, 0), "pass {pass}");
        }
        drop(connection);
        pool.close().await;
        db.remove().await;
    }

    #[tokio::test]
    #[ignore = "requires the existing disposable mysql source suite"]
    async fn qualification_activity_completion_snapshot_skips_readback() {
        let db = Database::new().await;
        let store = super::super::store::Store::new(MySqlStorage::open(db.options.clone()).await.unwrap().into_pool());
        store.qualify_activity_completion_without_readback().await;
        store.qualify_sticky_completion_statements().await;
        store.qualify_replay_clock_statements().await;
        store.close().await;
        db.remove().await;
    }

    #[tokio::test]
    #[ignore = "requires the existing disposable mysql source suite"]
    async fn qualification_poll_cache_single_statement_writes() {
        let db = Database::new().await;
        let store = super::super::store::Store::new(MySqlStorage::open(db.options.clone()).await.unwrap().into_pool());
        store.qualify_completion_waiting_status().await;
        store.qualify_authored_run_sequences().await;
        store.qualify_transaction_run_tags().await;
        store.qualify_query_poll_cache_writes().await;
        store.qualify_workflow_poll_cache_writes().await;
        store.qualify_summary_projection_bytes().await;
        store.qualify_summary_history_pages().await;
        store.close().await;
        db.remove().await;
    }

    #[tokio::test]
    #[ignore = "requires the existing disposable mysql source suite"]
    async fn qualification_carried_activity_polls() {
        let db = Database::new().await;
        let store = super::super::store::Store::new(MySqlStorage::open(db.options.clone()).await.unwrap().into_pool());
        // Boxed: these qualifications' futures are large enough that, in a
        // debug test thread's default stack, one more poll frame overflowed.
        Box::pin(store.qualify_carried_activity_polls()).await;
        Box::pin(store.qualify_carried_workflow_polls()).await;
        Box::pin(store.qualify_workflow_completion_carriers()).await;
        Box::pin(store.qualify_retained_workflow_poll_keys()).await;
        store.close().await;
        db.remove().await;
    }

    #[tokio::test]
    #[ignore = "requires the existing disposable mysql source suite"]
    async fn qualification_poll_transaction_counts() {
        let db = Database::new().await;
        let store = super::super::store::Store::new(MySqlStorage::open(db.options.clone()).await.unwrap().into_pool());
        store.qualify_workflow_poll_transactions().await;
        store.qualify_first_candidate_batch().await;
        store.qualify_same_run_offer_fence().await;
        store.qualify_claim_history_sequence().await;
        store.qualify_fenced_registration_reuse().await;
        store.qualify_query_poll_transactions().await;
        store.qualify_task_offer_key_usage().await;
        store.qualify_exact_update_validation_claim().await;
        store.qualify_execution_offer_receipts().await;
        store.close().await;
        db.remove().await;
    }

    #[tokio::test]
    #[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
    async fn qualification_describe_snapshot_under_repeatable_read_and_read_committed() {
        use super::super::{describe_snapshot_tests::reader_pool, store::Store};
        use std::sync::{Arc, atomic::AtomicUsize};
        let db = Database::new().await;
        let writer = Store::new(MySqlStorage::open(db.options.clone()).await.unwrap().into_pool());
        for level in ["SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ",
                      "SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED"] {
            let checkouts = Arc::new(AtomicUsize::new(0));
            let pool = reader_pool(runtime_pool_options(),checkouts.clone())
                .connect_with(db.options.clone()).await.unwrap();
            pool.acquire().await.unwrap().execute(level).await.unwrap();
            let reader = Store::new(pool);
            if level.ends_with("READ COMMITTED") {
                reader.qualify_describe_snapshot(&writer,&checkouts,true).await;
            }
            reader.qualify_describe_snapshot(&writer,&checkouts,false).await;
            if level.ends_with("READ COMMITTED") {
                // The one-transaction override must leave the session intact.
                reader.qualify_describe_snapshot(&writer,&checkouts,true).await;
            }
            reader.close().await;
        }
        writer.close().await;
        db.remove().await;
    }

    #[tokio::test]
    #[ignore = "requires the existing disposable mysql source suite"]
    async fn qualification_transition_queue_preserves_reads_fifo_and_timeout_admission() {
        tokio::time::timeout(Duration::from_secs(30), async {
            let db = Database::new().await;
            let store = super::super::store::Store::new(MySqlStorage::open(db.options.clone()).await.unwrap().into_pool());
            store.qualify_transition_queue().await;
            store.close().await;
            db.remove().await;
        }).await.expect("bounded MySQL transition queue qualification");
    }

    // Server #1181 (#1079 controls) on this backend: remembered history
    // follows every write and both savepoints rewind it, and a registration
    // replaced after a v2 continuation's admission declines it.
    #[tokio::test]
    #[ignore = "requires the existing disposable mysql source suite"]
    async fn qualification_continuation_v2_transition_state() {
        tokio::time::timeout(Duration::from_secs(60), async {
            let db = Database::new().await;
            let store = super::super::store::Store::new(MySqlStorage::open(db.options.clone()).await.unwrap().into_pool());
            store.qualify_remembered_history().await;
            store.close().await;
            let runtime = super::super::Runtime::open_mysql(db.options.clone(), "test-token".into()).await.unwrap();
            super::super::execution::continuation_atomic_tests::qualify_registration_replaced_after_admission(&runtime).await;
            runtime.close().await;
            db.remove().await;
        }).await.expect("bounded MySQL v2 transition-state qualification");
    }

    #[tokio::test]
    #[ignore = "requires the existing disposable mysql source suite"]
    async fn qualification_commit_handoff_and_history_allocation() {
        tokio::time::timeout(Duration::from_secs(30), async {
            let db = Database::new().await;
            let store = super::super::store::Store::new(MySqlStorage::open(db.options.clone()).await.unwrap().into_pool());
            store.qualify_commit_handoff().await;
            store.qualify_history_sequence_allocation().await;
            store.qualify_local_write_batches().await;
            store.qualify_authored_sequence_reads().await;
            store.close().await;
            db.remove().await;
        }).await.expect("bounded MySQL commit/sequence qualification");
    }

    #[tokio::test]
    #[ignore = "requires the existing disposable mysql source suite"]
    async fn qualification_sweep_expiry_and_batch_bounds() {
        let db = Database::new().await;
        let mut store = super::super::store::Store::new(MySqlStorage::open(db.options.clone()).await.unwrap().into_pool());
        store.qualify_poll_sweeps().await;
        store.close().await;
        db.remove().await;
    }

    #[tokio::test]
    #[ignore = "requires the existing disposable mysql source suite"]
    async fn qualification_mysql_candidates_use_ordered_status_ranges() {
        tokio::time::timeout(Duration::from_secs(60), async {
            let db = Database::new().await;
            let store = super::super::store::Store::new(MySqlStorage::open(db.options.clone()).await.unwrap().into_pool());
            store.qualify_mysql_candidate_ranges().await;
            store.close().await;
            db.remove().await;
        }).await.expect("bounded MySQL ordered candidate qualification");
    }

    #[tokio::test]
    #[ignore = "requires the existing disposable mysql source suite"]
    async fn qualification_maintenance_scans_seek_open_work_only() {
        let db = Database::new().await;
        let store = super::super::store::Store::new(MySqlStorage::open(db.options.clone()).await.unwrap().into_pool());
        store.qualify_maintenance_scan_plans().await;
        store.close().await;
        db.remove().await;
    }

    #[test]
    fn maintenance_scan_predecessors_keep_their_pinned_source_checksums() {
        for (flavor, version, pinned) in [
            (Flavor::MySql, 18, include_str!("../../migrations/mysql/0018_checksum.txt")),
            (Flavor::MariaDb, 17, include_str!("../../migrations/mariadb/0017_checksum.txt")),
        ] {
            assert_eq!(checksum_hex(flavor.earlier(version).unwrap().0), pinned.trim());
            assert_eq!(maintenance_scan_step(flavor), version);
            let previous: &str = flavor.earlier(version).unwrap().0.sql.as_ref();
            let sql: &str = flavor.migration().sql.as_ref();
            for (_, statement, key) in MAINTENANCE_SCAN_INDEXES {
                assert!(!previous.contains(key.trim()));
                // A fresh or resumed bootstrap creates each table with its index.
                assert!(!sql.contains(statement));
                assert_eq!(sql.matches(key.trim()).count(), 1);
                assert!(flavor.step(version).unwrap().contains(statement));
            }
        }
    }

    #[tokio::test]
    #[ignore = "requires the existing disposable mysql source suite"]
    async fn qualification_poll_query_plan_bounds() {
        let db = Database::new().await;
        let store = super::super::store::Store::new(MySqlStorage::open(db.options.clone()).await.unwrap().into_pool());
        store.qualify_poll_query_plans().await;
        store.close().await;
        db.remove().await;
    }

    #[tokio::test]
    #[ignore = "requires the existing disposable mysql source suite"]
    async fn qualification_ready_age_origins_and_committed_poll_answers() {
        let db=Database::new().await;
        let mut store=super::super::store::Store::new(MySqlStorage::open(db.options.clone()).await.unwrap().into_pool());
        store.qualify_ready_age().await;
        store.close().await;
        db.remove().await;
    }

    #[tokio::test]
    #[ignore = "requires the existing disposable mysql source suite"]
    async fn qualification_schedule_list_complete_summary_and_join_plans() {
        let db=Database::new().await;
        let store=super::super::store::Store::new(MySqlStorage::open(db.options.clone()).await.unwrap().into_pool());
        store.qualify_schedule_list_summary().await;
        store.qualify_schedule_dispatch_summary().await;
        store.close().await;
        db.remove().await;
    }

    #[tokio::test]
    #[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
    async fn qualification_schedule_creation_keeps_original_audit_and_unsigned_counters() {
        use super::super::{schedules, store::Store};
        use crate::codec::{Value as Payload, ValueCodec};
        let db = Database::new().await;
        let store = Store::new(
            MySqlStorage::open(db.options.clone())
                .await
                .unwrap()
                .into_pool(),
        );
        let input = ValueCodec::new()
            .unwrap()
            .encode(&Payload::Array(vec![Payload::Long(9007199254740993)]))
            .unwrap();
        let definition = schedules::definition(&json!({"schedule_id":"schedule-storage-audit",
            "spec":{"intervals":[{"every":"P1D"}],"timezone":"UTC"},
            "action":{"workflow_type":"echo","task_queue":"test","input":{"codec":"avro","blob":input},
                "execution_timeout_seconds":3600,"run_timeout_seconds":600},
            "overlap_policy":"skip","jitter_seconds":0})).unwrap();
        // This isolated synthetic test surfaces typed-driver failures which
        // the public HTTP boundary correctly redacts as storage_unavailable.
        store
            .create_schedule(definition, json!({"test":"schedule admission"}), json!({}))
            .await
            .unwrap();
        let history = store
            .schedule_history("schedule-storage-audit", 0, 2)
            .await
            .unwrap();
        assert_eq!(history["events"].as_array().unwrap().len(), 1);
        assert_eq!(history["events"][0]["sequence"], 1);
        assert_eq!(
            history["events"][0]["payload"]["schedule"]["fires_count"],
            0
        );
        assert_eq!(
            store
                .describe_schedule("schedule-storage-audit")
                .await
                .unwrap()["fires_count"],
            0
        );
        store.close().await;
        db.remove().await;
    }

    #[tokio::test]
    #[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
    async fn qualification_read_snapshot_hides_later_commits_and_refuses_writes() {
        let database = Database::new().await;
        let mut writer = database.connection().await;
        sqlx::query("CREATE TABLE snapshot_probe (id BIGINT PRIMARY KEY)")
            .execute(&mut writer)
            .await
            .unwrap();
        let mut reader = database.connection().await;
        let mut read = reader
            .begin_with(<MySql as super::super::backend::Backend>::READ_SNAPSHOT_BEGIN)
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

    /// Server #1057: a session below REPEATABLE READ. The ordinary snapshot
    /// begin keeps that level, so a later commit becomes visible inside it;
    /// the lock-free poll's begin fixes one snapshot for its transaction only.
    #[tokio::test]
    #[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
    async fn qualification_repeatable_snapshot_is_fixed_under_a_read_committed_session() {
        use super::super::backend::Backend;
        let database = Database::new().await;
        let mut writer = database.connection().await;
        sqlx::query("CREATE TABLE snapshot_probe (id BIGINT PRIMARY KEY)")
            .execute(&mut writer)
            .await
            .unwrap();
        let mut reader = database.connection().await;
        sqlx::query("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED")
            .execute(&mut reader)
            .await
            .unwrap();
        let count = "SELECT COUNT(*) FROM snapshot_probe";
        // Returns (first read, read after a concurrent commit) inside one
        // transaction begun with `begin`.
        async fn reads(reader: &mut MySqlConnection, writer: &mut MySqlConnection, begin: &'static str, id: i64) -> (i64, i64) {
            let mut read = reader.begin_with(begin).await.unwrap();
            let count = "SELECT COUNT(*) FROM snapshot_probe";
            let before: i64 = sqlx::query_scalar(count).fetch_one(&mut *read).await.unwrap();
            sqlx::query("INSERT INTO snapshot_probe VALUES (?)").bind(id).execute(&mut *writer).await.unwrap();
            let during: i64 = sqlx::query_scalar(count).fetch_one(&mut *read).await.unwrap();
            read.rollback().await.unwrap();
            (before, during)
        }
        // The predecessor's premise fails on this session: no fixed snapshot.
        assert_eq!(reads(&mut reader, &mut writer, MySql::READ_SNAPSHOT_BEGIN, 1).await, (0, 1));
        // The successor's begin fixes one, and it stays read-only.
        let mut read = reader.begin_with(MySql::REPEATABLE_READ_SNAPSHOT_BEGIN).await.unwrap();
        let before: i64 = sqlx::query_scalar(count).fetch_one(&mut *read).await.unwrap();
        sqlx::query("INSERT INTO snapshot_probe VALUES (2)").execute(&mut writer).await.unwrap();
        let during: i64 = sqlx::query_scalar(count).fetch_one(&mut *read).await.unwrap();
        assert_eq!((before, during), (1, 1), "the later commit stays invisible to the fixed snapshot");
        assert!(sqlx::query("INSERT INTO snapshot_probe VALUES (3)").execute(&mut *read).await.is_err());
        read.rollback().await.unwrap();
        // The level was for that transaction only: the session is still
        // READ COMMITTED, so the ordinary begin sees later commits again.
        // (Rows 1 and 2 exist; the refused read-only insert added nothing.)
        assert_eq!(reads(&mut reader, &mut writer, MySql::READ_SNAPSHOT_BEGIN, 4).await, (2, 3));
        drop((reader, writer));
        database.remove().await;
    }

    #[tokio::test]
    #[ignore = "requires the existing disposable mysql source suite"]
    async fn qualification_empty_query_poll_is_lock_free_only_when_proven() {
        let db = Database::new().await;
        let mut store = super::super::store::Store::new(MySqlStorage::open(db.options.clone()).await.unwrap().into_pool());
        store.qualify_empty_query_poll().await;
        store.close().await;
        db.remove().await;
    }

    /// Server #1204: the readiness and bootstrap catalog check reads this
    /// database's triggers only. MariaDB does not use `TRIGGER_SCHEMA` to
    /// select schemas, so filtering on it read every database's tables and
    /// waited behind DDL holding their metadata locks; `EVENT_OBJECT_SCHEMA`
    /// is the schema it does look up. Another database here holds a `DROP
    /// TABLE` that has taken one table and waits on a transaction's read of
    /// the other, as a test database drop waited in CI.
    #[tokio::test]
    #[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
    async fn qualification_catalog_check_does_not_wait_on_another_databases_ddl() {
        let db = Database::new().await;
        let storage = MySqlStorage::open(db.options.clone()).await.unwrap();
        let blocked = BlockedDrop::start().await;
        let pool = storage.into_pool();
        let mut connection = pool.acquire().await.unwrap();
        let started = std::time::Instant::now();
        // Well inside the pool session's own lock_wait_timeout (5 s), which
        // would otherwise end the old query's wait at the same moment.
        let checked = tokio::time::timeout(std::time::Duration::from_secs(2), verify_history(&mut *connection)).await;
        let elapsed = started.elapsed();
        blocked.release().await;
        checked.expect("the catalog check waited on another database's metadata lock").unwrap();
        assert!(elapsed < std::time::Duration::from_secs(2), "{elapsed:?}");
        drop(connection);
        pool.close().await;
        db.remove().await;
    }

    /// Another database whose `DROP TABLE a, b` has taken `a` (which has a
    /// trigger) and waits on a transaction's read of `b`, as a test database
    /// drop waited in CI (Server #1204, #1339).
    struct BlockedDrop {
        other: Database,
        holder: MySqlConnection,
        dropping: tokio::task::JoinHandle<(MySqlConnection, sqlx::Result<()>)>,
    }

    impl BlockedDrop {
        async fn start() -> Self {
            let other = Database::new().await;
            let mut holder = other.connection().await;
            for statement in [
                "CREATE TABLE a (id INT PRIMARY KEY) ENGINE=InnoDB",
                "CREATE TABLE b (id INT PRIMARY KEY) ENGINE=InnoDB",
                "CREATE TRIGGER a_insert AFTER INSERT ON a FOR EACH ROW SET @dw_other=1",
                "CREATE TRIGGER b_insert AFTER INSERT ON b FOR EACH ROW SET @dw_other=2",
                "INSERT INTO b VALUES (1)",
                "START TRANSACTION",
                "SELECT id FROM b",
            ] {
                holder.execute(statement).await.unwrap();
            }
            let mut dropper = other.connection().await;
            let dropper_id: u64 = sqlx::query_scalar("SELECT CONNECTION_ID()").fetch_one(&mut dropper).await.unwrap();
            dropper.execute("SET SESSION lock_wait_timeout=30").await.unwrap();
            let dropping = tokio::spawn(async move {
                let dropped = dropper.execute("DROP TABLE a, b").await.map(|_| ());
                (dropper, dropped)
            });
            let mut observer = other.connection().await;
            let waiting = async {
                loop {
                    let state: Option<String> = sqlx::query_scalar(
                        "SELECT STATE FROM information_schema.PROCESSLIST WHERE ID=?")
                        .bind(dropper_id).fetch_optional(&mut observer).await.unwrap().flatten();
                    if state.is_some_and(|state| state.contains("metadata lock")) {
                        break;
                    }
                    tokio::time::sleep(std::time::Duration::from_millis(20)).await;
                }
            };
            tokio::time::timeout(std::time::Duration::from_secs(10), waiting).await
                .expect("the other database's DROP TABLE waits on the open transaction");
            observer.close().await.unwrap();
            Self { other, holder, dropping }
        }

        async fn release(mut self) {
            self.holder.execute("ROLLBACK").await.unwrap();
            let (dropper, dropped) = self.dropping.await.unwrap();
            dropped.unwrap();
            for connection in [self.holder, dropper] {
                connection.close().await.unwrap();
            }
            self.other.remove().await;
        }
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
        // A fresh password for every run, from the operating system's secure
        // source (Server #779). It is hex, so it cannot leave the quoted
        // literal below, and the connection that sends it logs no statements.
        let mut secret = [0_u8; 32];
        getrandom::fill(&mut secret).unwrap();
        let password: String = secret.iter().map(|byte| format!("{byte:02x}")).collect();
        assert!(password.bytes().all(|byte| byte.is_ascii_hexdigit()));
        let mut admin = MySqlConnection::connect_with(&db.options.clone().disable_statement_logging())
            .await
            .unwrap();
        // These identifiers contain only a fixed prefix and a ULID alphabet.
        admin
            .execute(AssertSqlSafe(format!("CREATE USER '{role}'@'%' IDENTIFIED BY '{password}'")))
            .await
            .unwrap();
        let reader = db.options.clone().username(&role).password(&password);
        // The user's removal is attempted whether or not the grant and the
        // checks pass: MySQL commits CREATE USER on its own.
        let checked = std::panic::AssertUnwindSafe(async {
            admin
                .execute(AssertSqlSafe(format!("GRANT SELECT ON `{database}`.* TO '{role}'@'%'")))
                .await
                .unwrap();
            MySqlStorage::check(reader.clone()).await.unwrap();
            let mut read = MySqlConnection::connect_with(&reader).await.unwrap();
            assert!(
                sqlx::query("DELETE FROM workflow_instances")
                    .execute(&mut read)
                    .await
                    .is_err()
            );
            read.close().await.unwrap();
            admin.execute("DROP TRIGGER dw_child_summary_history_update").await.unwrap();
            let changed = MySqlStorage::check(reader).await.unwrap_err();
            assert!(matches!(changed, super::super::RuntimeError::Refused {
                reason: "native_schema_catalog_mismatch", ..
            }));
        })
        .catch_unwind()
        .await;
        let dropped = admin
            .execute(AssertSqlSafe(format!("DROP USER '{role}'@'%'")))
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
            "cleanup failed (user dropped {dropped}, connections closed {closed}, database removed {removed}); checks passed {}",
            checked.is_ok()
        );
        if let Err(panic) = checked {
            std::panic::resume_unwind(panic);
        }
    }

    #[tokio::test]
    #[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
    async fn qualification_corrupt_catalog_and_history_refusal() {
        for (mutation, reason) in [
            (
                "UPDATE dw_server_schema SET version=99",
                "native_schema_newer_than_binary",
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
        if phase == "marker-table" {
            // Killed after the marker table is created, before its marker.
            connection.execute(MARKER_SQL).await.unwrap();
        } else {
            initializing(&mut connection, flavor).await;
        }
        if phase == "dirty-partial" {
            dirty(&mut connection, flavor).await;
            partial(&mut connection, flavor).await;
        }
        if phase == "before-marker" {
            connection.execute(LEDGER_SQL).await.unwrap();
            flavor.migrator().run(&mut connection).await.unwrap();
        }
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
    #[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
    async fn qualification_real_kill_resumes_only_recognized_initialization() {
        use std::os::unix::process::ExitStatusExt;
        for phase in ["marker-table", "marker", "dirty-partial", "before-marker"] {
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
            if phase == "marker-table" {
                // A node starting now sees only the empty marker table. It must
                // wait for the holder's bootstrap lock rather than refuse.
                let open = MySqlStorage::open(db.options.clone());
                tokio::pin!(open);
                tokio::select! {
                    result = &mut open => panic!(
                        "a concurrent start finished while the bootstrap lock was held (ok: {})",
                        result.is_ok()
                    ),
                    _ = tokio::time::sleep(Duration::from_secs(1)) => {}
                }
                child.kill().unwrap();
                assert_eq!(child.wait().unwrap().signal(), Some(9));
                let storage = open.await.unwrap();
                storage.close().await;
                MySqlStorage::check(db.options.clone()).await.unwrap();
                db.remove().await;
                continue;
            }
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

    use crate::runtime::upgrade::{fingerprint, queue_binding::QueueSourceConfig, takeover};

    const TAKEOVER_QUEUES: QueueSourceConfig<'static> = QueueSourceConfig {
        connection: "database",
        default_connection: "database",
        default_queue: "default",
    };

    /// The frozen PHP layout, or a release layout rebuilt from the recorded
    /// deltas (each changed table exactly as that release's PHP created it).
    async fn php_layout(db: &Database, release: Option<&str>) -> Flavor {
        let mut connection = db.connection().await;
        let flavor = Flavor::detect(&mut connection).await.unwrap();
        let (frozen, deltas) = match flavor {
            Flavor::MySql => (
                include_str!("../../migrations/mysql/0003_full_schema.sql"),
                [
                    include_str!("../../upgrade/php-2.8.0/mysql.json"),
                    include_str!("../../upgrade/php-2.9.7/mysql.json"),
                    include_str!("../../upgrade/php-2.11.1/mysql.json"),
                    include_str!("../../upgrade/php-2.11.1-from-2.9.7/mysql.json"),
                    include_str!("../../upgrade/php-2.11.3/mysql.json"),
                    include_str!("../../upgrade/php-2.11.3-from-2.9.7/mysql.json"),
                    include_str!("../../upgrade/php-2.11.8/mysql.json"),
                    include_str!("../../upgrade/php-2.11.8-from-2.9.7/mysql.json"),
                ],
            ),
            Flavor::MariaDb => (
                include_str!("../../migrations/mariadb/0003_full_schema.sql"),
                [
                    include_str!("../../upgrade/php-2.8.0/mariadb.json"),
                    include_str!("../../upgrade/php-2.9.7/mariadb.json"),
                    include_str!("../../upgrade/php-2.11.1/mariadb.json"),
                    include_str!("../../upgrade/php-2.11.1-from-2.9.7/mariadb.json"),
                    include_str!("../../upgrade/php-2.11.3/mariadb.json"),
                    include_str!("../../upgrade/php-2.11.3-from-2.9.7/mariadb.json"),
                    include_str!("../../upgrade/php-2.11.8/mariadb.json"),
                    include_str!("../../upgrade/php-2.11.8-from-2.9.7/mariadb.json"),
                ],
            ),
        };
        let php = &frozen[..frozen.find("-- Native receipts are separate").unwrap()];
        connection.execute(php).await.unwrap();
        connection
            .execute("SET FOREIGN_KEY_CHECKS=1; ALTER TABLE workflow_tasks DROP KEY dw_workflow_tasks_poll")
            .await
            .unwrap();
        let lineage = super::super::upgrade::layout_lineage(release.unwrap_or("frozen"));
        assert_eq!(lineage.is_empty(), release.is_none(), "{release:?} is a recorded layout");
        // A replaced table can have inbound foreign keys (2.11.1's runs); they
        // name the table, so they hold again once it is recreated.
        connection.execute("SET FOREIGN_KEY_CHECKS=0").await.unwrap();
        for delta in lineage.into_iter().map(|index| deltas[index]) {
            let delta: Value = serde_json::from_str(delta).unwrap();
            for added in delta["add"].as_array().unwrap() {
                let (table, ddl) = (added[0].as_str().unwrap(), added[1].as_str().unwrap());
                connection
                    .execute(table_sql("DROP TABLE IF EXISTS ", table, "").unwrap())
                    .await
                    .unwrap();
                connection.execute(AssertSqlSafe(ddl.to_owned())).await.unwrap();
            }
        }
        connection.execute("SET FOREIGN_KEY_CHECKS=1").await.unwrap();
        connection.close().await.unwrap();
        flavor
    }

    async fn backup_fingerprint(db: &Database) -> String {
        fingerprint::fingerprint_mysql(db.options.clone())
            .await
            .unwrap()["fingerprint"]
            .as_str()
            .unwrap()
            .to_owned()
    }

    /// The physical schema and the fingerprint of every PHP row.
    async fn source_state(db: &Database) -> (Catalog, String) {
        let mut connection = db.connection().await;
        let schema = catalog(&mut connection).await.unwrap();
        connection.close().await.unwrap();
        (schema, backup_fingerprint(db).await)
    }

    async fn sessions_ended(db: &Database) {
        let deadline = tokio::time::Instant::now() + Duration::from_secs(15);
        loop {
            let mut connection = db.connection().await;
            let others: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB=DATABASE() AND ID<>CONNECTION_ID()")
                .fetch_one(&mut connection)
                .await
                .unwrap();
            connection.close().await.unwrap();
            if others == 0 {
                return;
            }
            assert!(tokio::time::Instant::now() < deadline, "a takeover session survived");
            tokio::time::sleep(Duration::from_millis(50)).await;
        }
    }

    fn takeover_refused(result: Result<Value>, expected: &str) {
        match result {
            Err(super::super::RuntimeError::Refused { reason, .. }) => assert_eq!(reason, expected),
            Err(error) => panic!("unexpected takeover error: {error}"),
            Ok(report) => panic!("takeover was accepted: {report}"),
        }
    }

    async fn registrations(db: &Database) {
        let mut connection = db.connection().await;
        connection.execute(
            "INSERT INTO workflow_worker_registrations(worker_id,namespace,task_queue,runtime,registration_token,created_at,updated_at) VALUES ('current','default','q','php','0123456789abcdef0123456789abcdef','2026-10-20 09:00:00','2026-10-20 09:00:00');
             INSERT INTO workflow_worker_registrations(worker_id,namespace,task_queue,runtime,created_at,updated_at) VALUES ('older','default','q','php','2026-10-20 09:00:00','2026-10-20 09:00:00');
             INSERT INTO workflow_worker_registration_incarnations VALUES ('0123456789abcdef0123456789abcdef','default','current','active',0,NULL,'2026-10-20 09:00:00','2026-10-20 09:00:00');
             INSERT INTO workflow_worker_registration_incarnations VALUES ('11111111111111111111111111111111','default','current','superseded',0,'2026-10-20 08:00:00','2026-10-20 07:00:00','2026-10-20 08:00:00');
             INSERT INTO workflow_worker_registration_incarnations VALUES ('22222222222222222222222222222222','default','gone','deregistered',3,'2026-10-20 08:30:00','2026-10-20 07:00:00','2026-10-20 08:30:00');",
        )
        .await
        .unwrap();
        connection.close().await.unwrap();
    }

    /// Native opens the database and holds the converted incarnations.
    async fn converted(db: &Database) {
        let storage = MySqlStorage::open(db.options.clone()).await.unwrap();
        storage.close().await;
        MySqlStorage::check(db.options.clone()).await.unwrap();
        let mut connection = db.connection().await;
        let incarnations: Vec<(String, String, String, Option<i64>, bool)> = sqlx::query_as(
            "SELECT token,worker_id,status,recovered_workflow_task_count,finished_at IS NOT NULL FROM dw_worker_registration_incarnations ORDER BY worker_id,status",
        )
        .fetch_all(&mut connection)
        .await
        .unwrap();
        connection.close().await.unwrap();
        assert_eq!(incarnations.len(), 4, "{incarnations:?}");
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
        assert!(incarnations.iter().any(|row| row.1 == "older" && row.2 == "active" && row.0.len() == 32));
    }

    /// PHP 2.11.1, fresh and as a 2.9.7 database upgrades to it: both carry
    /// Core 2.10's trace carriers on runs, commands and tasks, which takeover
    /// counts and drops.
    #[tokio::test]
    #[ignore = "requires an isolated MySQL/MariaDB create-database role"]
    async fn qualification_takeover_converts_php_2111_and_native_opens_it() {
        for release in ["php-2.11.1", "php-2.11.1-from-2.9.7"] {
            let db = Database::new().await;
            php_layout(&db, Some(release)).await;
            registrations(&db).await;
            let mut connection = db.connection().await;
            connection.execute(concat!(
                "INSERT INTO workflow_instances(id,workflow_class,workflow_type,namespace,current_run_id,run_count) VALUES ('traced','Traced','traced','default','01jrun0000000000000000000a',1);",
                "INSERT INTO workflow_runs(id,workflow_instance_id,run_number,workflow_class,workflow_type,namespace,status,observability_context) VALUES ('01jrun0000000000000000000a','traced',1,'Traced','traced','default','completed','{\"traceparent\":\"00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01\"}');",
                "INSERT INTO workflow_tasks(id,workflow_run_id,namespace,task_type,status,observability_context) VALUES ('01jtask000000000000000000a','01jrun0000000000000000000a','default','workflow','completed','{\"traceparent\":\"00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01\"}');",
                "INSERT INTO workflow_worker_sessions(namespace,session_id,queue,status,lease_owner,lease_expires_at,ttl_expires_at,created_at,updated_at) VALUES ('default','held','q','active','current','2030-01-01 00:00:00','2030-01-01 00:00:00','2026-10-20 09:00:00','2026-10-20 09:00:00');",
                "INSERT INTO workflow_service_calls(id,namespace,endpoint_name,service_name,operation_name,caller_namespace,target_namespace,status,outcome,operation_mode,accepted_at,call_deadline_at,created_at,updated_at) VALUES ('01m5wvy8d1j3dtr13es05kcall','default','pay','billing','charge','default','default','accepted','accepted','async','2026-10-26 10:00:00.000000','2027-02-03 04:05:06.500000','2026-10-26 10:00:00','2026-10-26 10:00:00');",
            )).await.unwrap();
            for statement in crate::runtime::runtime_credentials::PHP_CREDENTIAL_ROWS {
                sqlx::raw_sql(statement).execute(&mut connection).await.unwrap();
            }
            connection.close().await.unwrap();
            let fingerprint = backup_fingerprint(&db).await;
            let report = takeover::take_over_mysql(db.options.clone(), &fingerprint, &TAKEOVER_QUEUES)
                .await
                .unwrap();
            assert_eq!(report["status"], "taken_over", "{report}");
            assert_eq!(report["source_layout"], release);
            assert_eq!(report["converted"]["observability_contexts_removed"], 2, "{report}");
            assert_eq!(report["converted"]["incarnations_copied"], 3, "{report}");
            assert_eq!(report["converted"]["session_holders_recorded"], 1, "{report}");
            assert_eq!(report["converted"]["service_call_deadlines_copied"], 1, "{report}");
            converted(&db).await;
            let mut connection = db.connection().await;
            let holder: String = sqlx::query_scalar("SELECT incarnation FROM dw_worker_session_holders WHERE namespace='default' AND session_id='held'")
                .fetch_one(&mut connection).await.unwrap();
            assert_eq!(holder, super::super::worker_sessions::incarnation_digest("0123456789abcdef0123456789abcdef"));
            let deadline: (String, String) = sqlx::query_as("SELECT namespace,DATE_FORMAT(call_deadline_at,'%Y-%m-%d %H:%i:%s.%f') FROM dw_service_call_deadlines WHERE service_call_id='01m5wvy8d1j3dtr13es05kcall'")
                .fetch_one(&mut connection).await.unwrap();
            assert_eq!(deadline, ("default".to_owned(), "2027-02-03 04:05:06.500000".to_owned()));
            let carriers: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME IN ('observability_context','queue_delivery_reference')")
                .fetch_one(&mut connection).await.unwrap();
            assert_eq!(carriers, 0, "{release}: the PHP-only columns are gone");
            connection.close().await.unwrap();
            takeover_refused(
                takeover::take_over_mysql(db.options.clone(), &fingerprint, &TAKEOVER_QUEUES).await,
                "upgrade_source_already_owned",
            );
            let runtime = crate::runtime::Runtime::open_mysql(db.options.clone(), "test-token".into()).await.unwrap();
            crate::runtime::runtime_credentials::assert_php_credentials_served(&runtime).await;
            runtime.close().await;
            db.remove().await;
        }
    }

    #[tokio::test]
    #[ignore = "requires an isolated MySQL/MariaDB create-database role"]
    async fn qualification_takeover_converts_php_2113_and_native_opens_it() {
        for release in ["php-2.11.3", "php-2.11.3-from-2.9.7"] {
            let db = Database::new().await;
            php_layout(&db, Some(release)).await;
            registrations(&db).await;
            let recorded = super::super::worker_sessions::incarnation_digest("0123456789abcdef0123456789abcdef");
            let mut connection = db.connection().await;
            for statement in [
                format!("INSERT INTO workflow_worker_sessions(namespace,session_id,queue,status,lease_owner,lease_owner_incarnation,lease_expires_at,ttl_expires_at,created_at,updated_at) VALUES ('default','held','q','active','current','{recorded}','2030-01-01 00:00:00','2030-01-01 00:00:00','2026-10-20 09:00:00','2026-10-20 09:00:00')"),
                "INSERT INTO workflow_failures(id,workflow_run_id,source_kind,source_id,propagation_kind,exception_class,message,file,message_full,created_at,updated_at) VALUES ('01m5wvy8d1j3dtr13es05kfail','01jrun0000000000000000000a','activity_execution','a','activity','RuntimeException','preview','','base64:ZnVsbA==','2026-10-26 10:00:00','2026-10-26 10:00:00')".to_owned(),
                "INSERT INTO workflow_service_calls(id,namespace,endpoint_name,service_name,operation_name,caller_namespace,target_namespace,status,outcome,operation_mode,idempotency_key,idempotency_scope_hash,accepted_at,completed_at,created_at,updated_at) VALUES ('01m5wvy8d1j3dtr13es05kcall','default','pay','billing','charge','default','default','completed','succeeded','async','order-1','0000000000000000000000000000000000000000000000000000000000000001','2026-10-26 10:00:00','2026-10-26 10:00:01','2026-10-26 10:00:00','2026-10-26 10:00:01')".to_owned(),
                "INSERT INTO workflow_service_call_dispatches(service_call_id,state,dispatch_options,available_at,completed_at,created_at,updated_at) VALUES ('01m5wvy8d1j3dtr13es05kcall','completed','{}','2026-10-26 10:00:00','2026-10-26 10:00:01','2026-10-26 10:00:00','2026-10-26 10:00:01')".to_owned(),
            ] {
                connection.execute(sqlx::AssertSqlSafe(statement)).await.unwrap();
            }
            connection.close().await.unwrap();
            let fingerprint = backup_fingerprint(&db).await;
            let report = takeover::take_over_mysql(db.options.clone(), &fingerprint, &TAKEOVER_QUEUES)
                .await
                .unwrap();
            assert_eq!(report["status"], "taken_over", "{report}");
            assert_eq!(report["source_layout"], release);
            assert_eq!(report["converted"]["session_holders_recorded"], 1, "{report}");
            assert_eq!(report["converted"]["full_failure_messages_removed"], 1, "{report}");
            assert_eq!(report["converted"]["service_call_dispatches_removed"], 1, "{report}");
            converted(&db).await;
            let mut connection = db.connection().await;
            let holder: String = sqlx::query_scalar("SELECT incarnation FROM dw_worker_session_holders WHERE namespace='default' AND session_id='held'")
                .fetch_one(&mut connection).await.unwrap();
            assert_eq!(holder, recorded);
            let left: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND (TABLE_NAME,COLUMN_NAME) IN (('workflow_failures','message_full'),('workflow_service_calls','idempotency_scope_hash'),('workflow_worker_sessions','lease_owner_incarnation'),('workflow_durable_streams','reclaim_at'))")
                .fetch_one(&mut connection).await.unwrap();
            let dispatches: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='workflow_service_call_dispatches'")
                .fetch_one(&mut connection).await.unwrap();
            assert_eq!((left, dispatches), (0, 0), "{release}: PHP 2.11.3's additions are gone");
            connection.close().await.unwrap();
            db.remove().await;
        }
    }

    #[tokio::test]
    #[ignore = "requires an isolated MySQL/MariaDB create-database role"]
    async fn qualification_takeover_carries_php_2118_pending_deletions_over() {
        for release in ["php-2.11.8", "php-2.11.8-from-2.9.7"] {
            let db = Database::new().await;
            php_layout(&db, Some(release)).await;
            registrations(&db).await;
            let mut connection = db.connection().await;
            for statement in [
                r#"INSERT INTO runtime_external_payload_deletions(id,namespace,storage_uri,storage_uri_sha256,storage_policy,attempts,failure_code,created_at,updated_at) VALUES ('01m5wvy8d1j3dtr13es05kdel1','default','file:///payloads/default/a','aaaa','{"driver":"local","enabled":true}',0,NULL,'2026-11-01 10:00:00','2026-11-01 10:00:00')"#,
                r#"INSERT INTO runtime_external_payload_deletions(id,namespace,storage_uri,storage_uri_sha256,storage_policy,attempts,failure_code,created_at,updated_at) VALUES ('01m5wvy8d1j3dtr13es05kdel2','gone','file:///payloads/gone/b','bbbb','{"driver":"local"}',3,'backup_hold','2026-11-01 09:00:00','2026-11-01 09:30:00')"#,
            ] {
                connection.execute(statement).await.unwrap();
            }
            connection.close().await.unwrap();
            let fingerprint = backup_fingerprint(&db).await;
            let report = takeover::take_over_mysql(db.options.clone(), &fingerprint, &TAKEOVER_QUEUES)
                .await
                .unwrap();
            assert_eq!(report["status"], "taken_over", "{report}");
            assert_eq!(report["source_layout"], release);
            assert_eq!(report["converted"]["external_payload_deletions_copied"], 2, "{report}");
            converted(&db).await;
            let mut connection = db.connection().await;
            let copied: Vec<(String, i64, Option<String>, String)> = sqlx::query_as("SELECT id,attempts,failure_code,DATE_FORMAT(updated_at,'%Y-%m-%d %H:%i:%s') FROM dw_external_payload_deletions ORDER BY id")
                .fetch_all(&mut connection).await.unwrap();
            assert_eq!(
                copied,
                [
                    ("01m5wvy8d1j3dtr13es05kdel1".to_owned(), 0, None, "2026-11-01 10:00:00".to_owned()),
                    ("01m5wvy8d1j3dtr13es05kdel2".to_owned(), 3, Some("backup_hold".to_owned()), "2026-11-01 09:30:00".to_owned()),
                ],
                "{release}"
            );
            let policy: String = sqlx::query_scalar("SELECT storage_policy FROM dw_external_payload_deletions WHERE id='01m5wvy8d1j3dtr13es05kdel1'")
                .fetch_one(&mut connection).await.unwrap();
            assert_eq!(serde_json::from_str::<Value>(&policy).unwrap(), json!({"driver":"local","enabled":true}));
            let left: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='runtime_external_payload_deletions'")
                .fetch_one(&mut connection).await.unwrap();
            assert_eq!(left, 0, "{release}: PHP's table is gone");
            connection.close().await.unwrap();
            db.remove().await;
        }
    }

    #[tokio::test]
    #[ignore = "requires an isolated MySQL/MariaDB create-database role"]
    async fn qualification_takeover_converts_php_297_and_native_opens_it() {
        let db = Database::new().await;
        php_layout(&db, Some("php-2.9.7")).await;
        registrations(&db).await;
        let fingerprint = backup_fingerprint(&db).await;
        let report = takeover::take_over_mysql(db.options.clone(), &fingerprint, &TAKEOVER_QUEUES)
            .await
            .unwrap();
        assert_eq!(report["status"], "taken_over", "{report}");
        assert_eq!(report["source_layout"], "php-2.9.7");
        assert_eq!(report["converted"]["incarnations_copied"], 3, "{report}");
        assert_eq!(report["converted"]["incarnations_created"], 1, "{report}");
        converted(&db).await;
        takeover_refused(
            takeover::take_over_mysql(db.options.clone(), &fingerprint, &TAKEOVER_QUEUES).await,
            "upgrade_source_already_owned",
        );
        db.remove().await;
    }

    #[tokio::test]
    #[ignore = "requires an isolated MySQL/MariaDB create-database role"]
    async fn qualification_takeover_keeps_php_catalog_rows_that_native_then_serves() {
        let db = Database::new().await;
        php_layout(&db, Some("php-2.9.7")).await;
        let mut connection = db.connection().await;
        for statement in crate::runtime::service_endpoints::PHP_CATALOG_ROWS {
            connection.execute(statement).await.unwrap();
        }
        connection.close().await.unwrap();
        let fingerprint = backup_fingerprint(&db).await;
        let report = takeover::take_over_mysql(db.options.clone(), &fingerprint, &TAKEOVER_QUEUES).await.unwrap();
        assert_eq!(report["status"], "taken_over", "{report}");
        let runtime = crate::runtime::Runtime::open_mysql(db.options.clone(), "test-token".into()).await.unwrap();
        crate::runtime::service_endpoints::assert_php_catalog_served(&runtime).await;
        runtime.close().await;
        db.remove().await;
    }

    #[tokio::test]
    #[ignore = "requires an isolated MySQL/MariaDB create-database role"]
    async fn qualification_takeover_converts_the_mariadb_driver_layout() {
        let db = Database::new().await;
        if let Flavor::MySql = php_layout(&db, Some("php-2.9.7")).await {
            db.remove().await;
            return;
        }
        // Laravel's mariadb connection stores the backup hold owner as uuid.
        let mut connection = db.connection().await;
        connection
            .execute("ALTER TABLE runtime_external_payload_backup_hold MODIFY owner uuid DEFAULT NULL")
            .await
            .unwrap();
        connection.close().await.unwrap();
        let fingerprint = backup_fingerprint(&db).await;
        let report = takeover::take_over_mysql(db.options.clone(), &fingerprint, &TAKEOVER_QUEUES)
            .await
            .unwrap();
        assert_eq!(report["source_layout"], "php-2.9.7+mariadb-driver", "{report}");
        MySqlStorage::check(db.options.clone()).await.unwrap();
        db.remove().await;
    }

    #[tokio::test]
    #[ignore = "requires an isolated MySQL/MariaDB create-database role"]
    async fn qualification_takeover_of_the_frozen_layout_adds_only_native_relations() {
        let db = Database::new().await;
        php_layout(&db, None).await;
        let fingerprint = backup_fingerprint(&db).await;
        let report = takeover::take_over_mysql(db.options.clone(), &fingerprint, &TAKEOVER_QUEUES)
            .await
            .unwrap();
        assert_eq!(report["source_layout"], "frozen", "{report}");
        MySqlStorage::check(db.options.clone()).await.unwrap();
        db.remove().await;
    }

    #[tokio::test]
    #[ignore = "requires an isolated MySQL/MariaDB create-database role"]
    async fn qualification_takeover_refusals_before_the_marker_leave_php_unchanged() {
        let db = Database::new().await;
        php_layout(&db, Some("php-2.9.7")).await;
        let fingerprint = backup_fingerprint(&db).await;
        let original = source_state(&db).await;
        takeover_refused(
            takeover::take_over_mysql(db.options.clone(), &format!("sha256:{}", "0".repeat(64)), &TAKEOVER_QUEUES).await,
            "upgrade_backup_fingerprint_mismatch",
        );
        takeover_refused(
            takeover::take_over_mysql(db.options.clone(), &format!("sha256:{}", "z".repeat(64)), &TAKEOVER_QUEUES).await,
            "upgrade_backup_fingerprint_required",
        );
        // Any other session on the database refuses the takeover.
        let php = db.connection().await;
        takeover_refused(
            takeover::take_over_mysql(db.options.clone(), &fingerprint, &TAKEOVER_QUEUES).await,
            "upgrade_source_in_use",
        );
        php.close().await.unwrap();
        assert_eq!(source_state(&db).await, original);
        // A write after the backup changes the digest, so the old one is refused.
        let mut php = db.connection().await;
        php.execute("INSERT INTO migrations(migration,batch) VALUES ('after-backup',9)")
            .await
            .unwrap();
        php.close().await.unwrap();
        let written = source_state(&db).await;
        takeover_refused(
            takeover::take_over_mysql(db.options.clone(), &fingerprint, &TAKEOVER_QUEUES).await,
            "upgrade_backup_fingerprint_mismatch",
        );
        assert_eq!(source_state(&db).await, written);
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
            php_layout(&db, Some("php-2.9.7")).await;
            let mut connection = db.connection().await;
            connection.execute(statement).await.unwrap();
            connection.close().await.unwrap();
            let fingerprint = backup_fingerprint(&db).await;
            let before = source_state(&db).await;
            takeover_refused(
                takeover::take_over_mysql(db.options.clone(), &fingerprint, &TAKEOVER_QUEUES).await,
                expected,
            );
            assert_eq!(source_state(&db).await, before, "{expected}");
            db.remove().await;
        }
    }

    // Named under bootstrap_child so the qualification run's existing skip of
    // that subprocess helper also skips this one.
    #[tokio::test]
    #[ignore = "invoked only as a subprocess by the takeover interruption qualification"]
    async fn bootstrap_child_takeover() {
        let url = std::env::var("DW_TAKEOVER_TEST_DATABASE").unwrap();
        let fingerprint = std::env::var("DW_TAKEOVER_TEST_FINGERPRINT").unwrap();
        // Parks at DW_TAKEOVER_TEST_BOUNDARY, where the parent kills it.
        let report = takeover::take_over_mysql(
            MySqlConnectOptions::from_str(&url).unwrap(),
            &fingerprint,
            &TAKEOVER_QUEUES,
        )
        .await;
        panic!("takeover returned instead of parking: {report:?}");
    }

    #[cfg(unix)]
    #[tokio::test]
    #[ignore = "requires an isolated MySQL/MariaDB create-database role"]
    async fn qualification_takeover_killed_at_each_step_resumes_with_the_same_fingerprint() {
        use std::os::unix::process::ExitStatusExt;
        for (release, boundary) in [
            ("php-2.9.7", "before-marker-row"),
            ("php-2.9.7", "after-step-1"),
            ("php-2.9.7", "after-step-3"),
            ("php-2.9.7", "after-step-8"),
            // Killed before PHP 2.11.3's own step.
            ("php-2.11.3", "after-step-9"),
        ] {
            let db = Database::new().await;
            php_layout(&db, Some(release)).await;
            registrations(&db).await;
            let fingerprint = backup_fingerprint(&db).await;
            let directory = tempfile::tempdir().unwrap();
            let receipt = directory.path().join("boundary");
            let mut child = Command::new(std::env::current_exe().unwrap())
                .args([
                    "--exact",
                    "runtime::mysql::tests::bootstrap_child_takeover",
                    "--ignored",
                    "--nocapture",
                ])
                .env("DW_TAKEOVER_TEST_DATABASE", &db.url)
                .env("DW_TAKEOVER_TEST_FINGERPRINT", &fingerprint)
                .env("DW_TAKEOVER_TEST_BOUNDARY", boundary)
                .env("DW_TAKEOVER_TEST_RECEIPT", &receipt)
                .spawn()
                .unwrap();
            let deadline = tokio::time::Instant::now() + Duration::from_secs(90);
            while !receipt.exists() && tokio::time::Instant::now() < deadline {
                if let Some(status) = child.try_wait().unwrap() {
                    panic!("child exited before {boundary}: {status}");
                }
                tokio::time::sleep(Duration::from_millis(10)).await;
            }
            if !receipt.exists() {
                child.kill().unwrap();
                child.wait().unwrap();
                panic!("child did not reach {boundary}");
            }
            child.kill().unwrap();
            assert_eq!(child.wait().unwrap().signal(), Some(9));
            sessions_ended(&db).await;
            // The marker already fences PHP, and native will not open the
            // database until the takeover finishes.
            let mut connection = db.connection().await;
            let marked: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dw_server_schema'")
                .fetch_one(&mut connection)
                .await
                .unwrap();
            connection.close().await.unwrap();
            assert_eq!(marked, 1, "{boundary}");
            assert!(MySqlStorage::open(db.options.clone()).await.is_err(), "{boundary}");
            if boundary == "after-step-3" {
                takeover_refused(
                    takeover::take_over_mysql(db.options.clone(), &format!("sha256:{}", "0".repeat(64)), &TAKEOVER_QUEUES).await,
                    "upgrade_backup_fingerprint_mismatch",
                );
            }
            let report = takeover::take_over_mysql(db.options.clone(), &fingerprint, &TAKEOVER_QUEUES)
                .await
                .unwrap();
            assert_eq!(report["status"], "taken_over", "{boundary}: {report}");
            assert_eq!(report["source_layout"], release, "{boundary}");
            converted(&db).await;
            db.remove().await;
        }
    }

    fn checksum_hex(migration: &Migration) -> String {
        migration.checksum.iter().map(|byte| format!("{byte:02x}")).collect()
    }

    #[test]
    fn mysql_version_13_moves_json_to_text_and_mariadb_stays_at_12() {
        // MySQL's retained version 12 is byte for byte the released one; its
        // current bootstrap creates exact text columns with enforced checks.
        assert_eq!(checksum_hex(Flavor::MySql.earlier(12).unwrap().0),
            include_str!("../../migrations/mysql/0012_checksum.txt").trim());
        assert_ne!(Flavor::MySql.checksum(), Flavor::MySql.earlier(12).unwrap().0.checksum.as_ref());
        // MariaDB stores JSON as text already: its schema 12 bootstrap was
        // unchanged by MySQL's 13, and stays byte for byte the released one.
        assert_eq!(checksum_hex(Flavor::MariaDb.earlier(12).unwrap().0),
            include_str!("../../migrations/mariadb/0012_checksum.txt").trim());
        let sql: &str = Flavor::MySql.migration().sql.as_ref();
        assert!(!sql.contains("ALTER TABLE `activity_executions`"), "upgrade ALTERs are folded into the bootstrap");
        assert!(!regex_lite::Regex::new(r"(?i)`?\w+`? json( |,)").unwrap().is_match(sql), "no json column remains");
        assert_eq!(sql.matches("CHECK (json_valid(").count(), 75);
        assert_eq!(sql.matches("longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin").count(), 75);
        let step = Flavor::MySql.step(12).unwrap();
        assert_eq!(step.matches("ALTER TABLE `").count(), 36);
        assert_eq!(step.matches("ADD CONSTRAINT `dw_json_text_").count(), 75);
        assert_eq!(json_text_check_name("activity_executions", "retry_policy"), "dw_json_text_da40cddf645f92a0f5d3");
        let expected: Catalog = serde_json::from_str(&canonical(include_str!("../../migrations/mysql/0013_catalog.json"))).unwrap();
        assert_eq!(expected.iter().map(|(_, ddl)| ddl.matches("CHECK (json_valid(").count()).sum::<usize>(), 75);
    }

    #[test]
    fn version_8_checksums_and_resumable_current_bootstrap_are_distinct() {
        for (flavor, pinned) in [
            (Flavor::MySql, include_str!("../../migrations/mysql/0008_checksum.txt")),
            (Flavor::MariaDb, include_str!("../../migrations/mariadb/0008_checksum.txt")),
        ] {
            assert_eq!(checksum_hex(flavor.earlier(8).unwrap().0), pinned.trim());
            let sql=flavor.migration().sql.as_ref();
            assert_eq!(sql.matches("`ready_origin_at` datetime(6) DEFAULT NULL").count(),1);
            assert!(!sql.contains("ALTER TABLE workflow_tasks ADD COLUMN ready_origin_at"));
            assert!(!sql.contains("CREATE TABLE dw_"));
        }
    }

    #[test]
    fn released_step_files_keep_the_checksum_their_release_recorded() {
        // Release candidates 1 and 2 recorded these checksums for version 7.
        // Editing 0003-0007 would make their databases unrecognisable.
        for (flavor, pinned) in [
            (Flavor::MySql, include_str!("../../migrations/mysql/0007_checksum.txt")),
            (Flavor::MariaDb, include_str!("../../migrations/mariadb/0007_checksum.txt")),
        ] {
            assert_eq!(checksum_hex(flavor.earlier(7).unwrap().0), pinned.trim());
        }
    }

    /// A database exactly as release candidates 1 and 2 bootstrapped it, with
    /// its catalog checked against the one captured for version 7.
    async fn version_7_database(db: &Database) -> Flavor {
        let mut connection = db.connection().await;
        configure(&mut connection).await.unwrap();
        let flavor = Flavor::detect(&mut connection).await.unwrap();
        let (earlier, earlier_catalog) = flavor.earlier(7).unwrap();
        connection.execute(MARKER_SQL).await.unwrap();
        sqlx::query("INSERT INTO dw_server_schema VALUES ('rust-initializing',7,?)")
            .bind(earlier.checksum.as_ref())
            .execute(&mut connection)
            .await
            .unwrap();
        connection.execute(LEDGER_SQL).await.unwrap();
        let mut migrator = Migrator::with_migrations(vec![earlier.clone()]);
        migrator.set_locking(false);
        migrator.run(&mut connection).await.unwrap();
        let expected: Catalog = serde_json::from_str(&canonical(earlier_catalog)).unwrap();
        assert_eq!(catalog(&mut connection).await.unwrap(), expected);
        sqlx::query("UPDATE dw_server_schema SET engine='rust-development' WHERE engine='rust-initializing'")
            .execute(&mut connection)
            .await
            .unwrap();
        sqlx::query("INSERT INTO workflow_namespaces(name,description) VALUES ('kept','seeded at version 7')")
            .execute(&mut connection)
            .await
            .unwrap();
        connection.close().await.unwrap();
        flavor
    }

    async fn version_10_database(db: &Database) -> Flavor {
        retained_version_database(db, 10).await
    }

    async fn retained_version_database(db: &Database, version: i64) -> Flavor {
        let mut connection = db.connection().await;
        configure(&mut connection).await.unwrap();
        let flavor = Flavor::detect(&mut connection).await.unwrap();
        let (earlier, earlier_catalog) = flavor.earlier(version).unwrap();
        connection.execute(MARKER_SQL).await.unwrap();
        sqlx::query("INSERT INTO dw_server_schema VALUES ('rust-initializing',?,?)")
            .bind(version)
            .bind(earlier.checksum.as_ref())
            .execute(&mut connection)
            .await
            .unwrap();
        connection.execute(LEDGER_SQL).await.unwrap();
        let mut migrator = Migrator::with_migrations(vec![earlier.clone()]);
        migrator.set_locking(false);
        migrator.run(&mut connection).await.unwrap();
        let expected: Catalog = serde_json::from_str(&canonical(earlier_catalog)).unwrap();
        assert_eq!(catalog(&mut connection).await.unwrap(), expected);
        sqlx::query("UPDATE dw_server_schema SET engine='rust-development' WHERE engine='rust-initializing'")
            .execute(&mut connection)
            .await
            .unwrap();
        sqlx::query("INSERT INTO workflow_namespaces(name,description) VALUES ('kept',?)")
            .bind(format!("seeded at version {version}"))
            .execute(&mut connection)
            .await
            .unwrap();
        connection.close().await.unwrap();
        flavor
    }

    async fn version_9_database(db: &Database) -> Flavor {
        let mut connection = db.connection().await;
        configure(&mut connection).await.unwrap();
        let flavor = Flavor::detect(&mut connection).await.unwrap();
        let (earlier, earlier_catalog) = flavor.earlier(9).unwrap();
        connection.execute(MARKER_SQL).await.unwrap();
        sqlx::query("INSERT INTO dw_server_schema VALUES ('rust-initializing',9,?)")
            .bind(earlier.checksum.as_ref())
            .execute(&mut connection)
            .await
            .unwrap();
        connection.execute(LEDGER_SQL).await.unwrap();
        let mut migrator = Migrator::with_migrations(vec![earlier.clone()]);
        migrator.set_locking(false);
        migrator.run(&mut connection).await.unwrap();
        let expected: Catalog = serde_json::from_str(&canonical(earlier_catalog)).unwrap();
        assert_eq!(catalog(&mut connection).await.unwrap(), expected);
        sqlx::query("UPDATE dw_server_schema SET engine='rust-development' WHERE engine='rust-initializing'")
            .execute(&mut connection)
            .await
            .unwrap();
        sqlx::query("INSERT INTO workflow_namespaces(name,description) VALUES ('kept','seeded at version 9')")
            .execute(&mut connection)
            .await
            .unwrap();
        connection.close().await.unwrap();
        flavor
    }

    async fn version_8_database(db: &Database) -> Flavor {
        let mut connection = db.connection().await;
        configure(&mut connection).await.unwrap();
        let flavor = Flavor::detect(&mut connection).await.unwrap();
        let (earlier, earlier_catalog) = flavor.earlier(8).unwrap();
        connection.execute(MARKER_SQL).await.unwrap();
        sqlx::query("INSERT INTO dw_server_schema VALUES ('rust-initializing',8,?)")
            .bind(earlier.checksum.as_ref())
            .execute(&mut connection)
            .await
            .unwrap();
        connection.execute(LEDGER_SQL).await.unwrap();
        let mut migrator = Migrator::with_migrations(vec![earlier.clone()]);
        migrator.set_locking(false);
        migrator.run(&mut connection).await.unwrap();
        let expected: Catalog = serde_json::from_str(&canonical(earlier_catalog)).unwrap();
        assert_eq!(catalog(&mut connection).await.unwrap(), expected);
        sqlx::query("UPDATE dw_server_schema SET engine='rust-development' WHERE engine='rust-initializing'")
            .execute(&mut connection)
            .await
            .unwrap();
        sqlx::query("INSERT INTO workflow_namespaces(name,description) VALUES ('kept','seeded at version 8')")
            .execute(&mut connection)
            .await
            .unwrap();
        connection.close().await.unwrap();
        flavor
    }

    fn upgrade_refused(result: Result<(&'static str, i64, i64)>, expected: &str) {
        match result {
            Err(super::super::RuntimeError::Refused { reason, .. }) => assert_eq!(reason, expected),
            Err(error) => panic!("unexpected upgrade error: {error}"),
            Ok(found) => panic!("upgrade accepted {found:?}"),
        }
    }

    #[test]
    fn command_intent_bootstraps_keep_the_previous_checksums() {
        // server#934: MySQL 14 and MariaDB 13 stay byte for byte the released
        // bootstraps; the command-intent step adds only its own table.
        assert_eq!(checksum_hex(Flavor::MySql.earlier(14).unwrap().0),
            include_str!("../../migrations/mysql/0014_checksum.txt").trim());
        assert_eq!(checksum_hex(Flavor::MariaDb.earlier(13).unwrap().0),
            include_str!("../../migrations/mariadb/0013_checksum.txt").trim());
        for flavor in [Flavor::MySql, Flavor::MariaDb] {
            let sql: &str = flavor.migration().sql.as_ref();
            assert_eq!(sql.matches("CREATE TABLE IF NOT EXISTS dw_command_intents (").count(), 1);
            let previous: &str = flavor.earlier(command_intents_step(flavor)).unwrap().0.sql.as_ref();
            assert!(!previous.contains("dw_command_intents"));
            assert!(flavor.step(command_intents_step(flavor)).unwrap().contains("CREATE TABLE dw_command_intents ("));
        }
    }

    #[test]
    fn dispatch_summary_bootstraps_create_final_tables_without_repeated_alters() {
        for flavor in [Flavor::MySql, Flavor::MariaDb] {
            let current: &str = flavor.migration().sql.as_ref();
            assert_eq!(current.matches("`dispatch_summary` text DEFAULT NULL").count(), 1);
            assert!(!current.contains("ALTER TABLE workflow_schedules ADD COLUMN dispatch_summary"));
            let previous: &str = flavor.earlier(dispatch_summary_step(flavor)).unwrap().0.sql.as_ref();
            assert!(!previous.contains("dispatch_summary"));
            assert_eq!(dispatch_summary_bootstrap_sql(previous.to_owned()), previous);
            assert!(flavor.step(dispatch_summary_step(flavor)).unwrap().contains("ALTER TABLE workflow_schedules"));
        }
    }

    #[test]
    fn task_queue_pause_bootstraps_keep_the_previous_checksums() {
        // server#577: MySQL 13 and MariaDB 12 stay byte for byte the released
        // bootstraps; the current ones add only the pause table.
        assert_eq!(checksum_hex(Flavor::MySql.earlier(13).unwrap().0),
            include_str!("../../migrations/mysql/0013_checksum.txt").trim());
        for flavor in [Flavor::MySql, Flavor::MariaDb] {
            let sql: &str = flavor.migration().sql.as_ref();
            assert_eq!(sql.matches("CREATE TABLE IF NOT EXISTS dw_task_queue_pauses (").count(), 1);
            let previous: &str = flavor.earlier(task_queue_pauses_step(flavor)).unwrap().0.sql.as_ref();
            assert!(!previous.contains("dw_task_queue_pauses"));
            assert!(flavor.step(task_queue_pauses_step(flavor)).unwrap().contains("CREATE TABLE dw_task_queue_pauses ("));
        }
    }

    #[test]
    fn version_11_bootstraps_keep_their_pinned_source_checksums() {
        for (flavor, pinned) in [
            (Flavor::MySql, include_str!("../../migrations/mysql/0011_checksum.txt")),
            (Flavor::MariaDb, include_str!("../../migrations/mariadb/0011_checksum.txt")),
        ] {
            assert_eq!(checksum_hex(flavor.earlier(11).unwrap().0), pinned.trim());
            let sql = flavor.migration().sql.as_ref();
            assert_eq!(sql.matches("CREATE TABLE IF NOT EXISTS dw_run_command_contracts (").count(), 1);
        }
    }

    #[test]
    fn version_10_bootstraps_keep_their_pinned_source_checksums() {
        for (flavor, pinned) in [
            (Flavor::MySql, include_str!("../../migrations/mysql/0010_checksum.txt")),
            (Flavor::MariaDb, include_str!("../../migrations/mariadb/0010_checksum.txt")),
        ] {
            assert_eq!(checksum_hex(flavor.earlier(10).unwrap().0), pinned.trim());
            let sql = flavor.migration().sql.as_ref();
            assert_eq!(sql.matches("KEY `dw_workflow_tasks_scoped_dispatch`").count(), 1);
            assert!(!sql.contains("CREATE INDEX dw_workflow_tasks_scoped_dispatch"));
        }
    }

    #[tokio::test]
    #[ignore = "requires the existing disposable mysql source suite"]
    async fn qualification_task_lease_loss_upgrade_preserves_tasks_and_resumes() {
        for alter_committed in [false, true] {
            let db = Database::new().await;
            let mut connection = db.connection().await;
            let flavor = Flavor::detect(&mut connection).await.unwrap();
            connection.close().await.unwrap();
            let from = task_lease_loss_step(flavor);
            retained_version_database(&db, from).await;
            let mut connection = db.connection().await;
            connection.execute("INSERT INTO workflow_tasks(id,workflow_run_id,task_type,status,attempt_count) VALUES ('legacy-task','run','workflow','leased',5)").await.unwrap();
            if alter_committed {
                connection.execute("UPDATE dw_server_schema SET engine='rust-upgrading'").await.unwrap();
                connection.execute(flavor.step(from).unwrap()).await.unwrap();
            }
            connection.close().await.unwrap();
            assert_eq!(upgrade(db.options.clone()).await.unwrap(), (flavor.name(), from, flavor.version()));
            MySqlStorage::check(db.options.clone()).await.unwrap();
            let mut connection = db.connection().await;
            let task: (String, u32, i64, Option<chrono::NaiveDateTime>) = sqlx::query_as(
                "SELECT status,attempt_count,lost_lease_count,last_lease_lost_at FROM workflow_tasks WHERE id='legacy-task'",
            ).fetch_one(&mut connection).await.unwrap();
            assert_eq!(task, ("leased".into(), 5, 0, None));
            connection.close().await.unwrap();
            db.remove().await;
        }
    }

    /// Server #1339: schema MySQL 23 / MariaDB 22 changes only the catalog
    /// function's trigger predicate. The retained bootstrap is the recorded
    /// schema MySQL 22 / MariaDB 21 one, byte for byte.
    #[test]
    fn catalog_lookup_predecessors_keep_their_pinned_source_checksums() {
        let current_body = include_str!("../../migrations/mysql/0023_child_summary_catalog_lookup.sql").trim_end();
        let previous_body = include_str!("../../migrations/mysql/0021_child_summary_checkpoint_catalog.sql").trim_end();
        assert!(current_body.contains("WHERE EVENT_OBJECT_SCHEMA=DATABASE()") && !current_body.contains("TRIGGER_SCHEMA"));
        assert_eq!(current_body.replace("EVENT_OBJECT_SCHEMA", "TRIGGER_SCHEMA"), previous_body);
        for (flavor, version, pinned) in [
            (Flavor::MySql, 22, include_str!("../../migrations/mysql/0022_checksum.txt")),
            (Flavor::MariaDb, 21, include_str!("../../migrations/mariadb/0021_checksum.txt")),
        ] {
            assert_eq!(child_summary_catalog_lookup_step(flavor), version);
            assert_eq!(flavor.version(), version + 1);
            let (retained, _) = flavor.earlier(version).unwrap();
            assert_eq!(retained.version, version);
            assert_eq!(checksum_hex(retained), pinned.trim());
            let previous: &str = retained.sql.as_ref();
            let current: &str = flavor.migration().sql.as_ref();
            assert_eq!(previous.matches(previous_body).count(), 1);
            assert_eq!(current.matches(current_body).count(), 1);
            assert_eq!(current.replace(current_body, previous_body), previous);
            assert_eq!(flavor.step(version).unwrap().trim_end(), current_body);
        }
    }

    #[test]
    fn run_fence_predecessors_keep_their_released_checksums() {
        for (flavor, version, pinned) in [
            (Flavor::MySql, 21, include_str!("../../migrations/mysql/0021_checksum.txt")),
            (Flavor::MariaDb, 20, include_str!("../../migrations/mariadb/0020_checksum.txt")),
        ] {
            assert_eq!(run_fence_step(flavor), version);
            assert_eq!(checksum_hex(flavor.earlier(version).unwrap().0), pinned.trim());
            let previous: &str = flavor.earlier(version).unwrap().0.sql.as_ref();
            assert!(!previous.contains("dw_workflow_tasks_run_fence"));
            // A fresh or resumed bootstrap creates the task table with its index.
            let sql: &str = flavor.migration().sql.as_ref();
            assert!(!sql.contains(RUN_FENCE_INDEX));
            assert_eq!(sql.matches(RUN_FENCE_KEY.trim()).count(), 1);
            assert!(flavor.step(version).unwrap().contains(RUN_FENCE_INDEX));
            let (_, catalog) = flavor.earlier(version).unwrap();
            let catalog: Catalog = serde_json::from_str(&canonical(catalog)).unwrap();
            assert_eq!(before_run_fence(flavor, flavor.expected().unwrap()).unwrap(), catalog);
        }
        assert_eq!((Flavor::MySql.version(), Flavor::MariaDb.version()), (23, 22));
    }

    #[tokio::test]
    #[ignore = "requires the existing disposable mysql source suite"]
    async fn qualification_run_fence_index_upgrade_preserves_tasks_and_resumes() {
        for index_committed in [false, true] {
            let db = Database::new().await;
            let mut connection = db.connection().await;
            let flavor = Flavor::detect(&mut connection).await.unwrap();
            connection.close().await.unwrap();
            let from = run_fence_step(flavor);
            retained_version_database(&db, from).await;
            let mut connection = db.connection().await;
            connection.execute("INSERT INTO workflow_tasks(id,workflow_run_id,task_type,status,attempt_count) VALUES ('kept-task','run','workflow','leased',3)").await.unwrap();
            if index_committed {
                connection.execute("UPDATE dw_server_schema SET engine='rust-upgrading'").await.unwrap();
                connection.execute(flavor.step(from).unwrap()).await.unwrap();
            }
            connection.close().await.unwrap();
            assert_eq!(upgrade(db.options.clone()).await.unwrap(), (flavor.name(), from, flavor.version()));
            MySqlStorage::check(db.options.clone()).await.unwrap();
            let mut connection = db.connection().await;
            let task: (String, u32) = sqlx::query_as("SELECT status,attempt_count FROM workflow_tasks WHERE id='kept-task'")
                .fetch_one(&mut connection).await.unwrap();
            assert_eq!(task, ("leased".into(), 3));
            connection.execute("DROP INDEX dw_workflow_tasks_run_fence ON workflow_tasks").await.unwrap();
            connection.close().await.unwrap();
            refused(MySqlStorage::open(db.options.clone()).await, "native_schema_catalog_mismatch");
            db.remove().await;
        }
    }

    #[test]
    fn current_bootstraps_create_the_lease_loss_columns_once() {
        assert_eq!((Flavor::MySql.version(), Flavor::MariaDb.version()), (23, 22));
        for flavor in [Flavor::MySql, Flavor::MariaDb] {
            let predecessor = task_lease_loss_step(flavor);
            let (retained, _) = flavor.earlier(predecessor).unwrap();
            let previous: &str = retained.sql.as_ref();
            let current = flavor.migration().sql.as_ref();
            for column in ["`lost_lease_count`", "`last_lease_lost_at`"] {
                assert!(!previous.contains(column));
                assert_eq!(current.matches(column).count(), 1);
            }
            assert!(!current.contains(flavor.step(predecessor).unwrap().trim()));
        }
    }

    #[tokio::test]
    #[ignore = "requires the existing disposable mysql source suite"]
    async fn qualification_child_summary_checkpoint_upgrade_resumes_and_verifies_trigger_bodies() {
        let db = Database::new().await;
        let mut connection = db.connection().await;
        let flavor = Flavor::detect(&mut connection).await.unwrap();
        connection.close().await.unwrap();
        let from = child_summary_checkpoint_step(flavor);
        retained_version_database(&db, from).await;
        let mut connection = db.connection().await;
        connection.execute("INSERT INTO dw_query_cache VALUES ('kept','original',1)").await.unwrap();
        connection.execute("UPDATE dw_server_schema SET engine='rust-upgrading'").await.unwrap();
        connection.execute(CHILD_SUMMARY_CHECKPOINT_SCHEMA).await.unwrap();
        for statement in CheckpointCode::default().missing_ddl().into_iter().take(3) {
            connection.execute(AssertSqlSafe(statement)).await.unwrap();
        }
        connection.close().await.unwrap();

        assert_eq!(upgrade(db.options.clone()).await.unwrap(), (flavor.name(), from, flavor.version()));
        MySqlStorage::check(db.options.clone()).await.unwrap();
        let mut connection = db.connection().await;
        let kept: String = sqlx::query_scalar("SELECT value FROM dw_query_cache WHERE `key`='kept'")
            .fetch_one(&mut connection).await.unwrap();
        assert_eq!(kept, "original");
        let markers: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM dw_child_summary_checkpoints")
            .fetch_one(&mut connection).await.unwrap();
        assert_eq!(markers, 0);
        connection.execute("DROP TRIGGER dw_child_summary_history_insert").await.unwrap();
        let changed = checkpoint_catalog::first_trigger().replace("generation + 1", "generation + 2");
        connection.execute(AssertSqlSafe(changed)).await.unwrap();
        connection.close().await.unwrap();
        refused(MySqlStorage::open(db.options.clone()).await, "native_schema_catalog_mismatch");
        db.remove().await;
    }

    /// Server #1339: upgrading a schema MySQL 22 / MariaDB 21 database
    /// replaces only the catalog function, keeps every row, and resumes when
    /// the replacement stopped after dropping the predecessor function.
    #[tokio::test]
    #[ignore = "requires the existing disposable mysql source suite"]
    async fn qualification_child_summary_catalog_lookup_upgrade_replaces_the_function() {
        for stopped_after_drop in [false, true] {
            let db = Database::new().await;
            let mut connection = db.connection().await;
            let flavor = Flavor::detect(&mut connection).await.unwrap();
            connection.close().await.unwrap();
            let from = child_summary_catalog_lookup_step(flavor);
            retained_version_database(&db, from).await;
            let mut connection = db.connection().await;
            let (_, installed) = physical_scope(&mut connection).await.unwrap();
            assert!(installed.predecessor_routine && !installed.routine && installed.awaiting_routine());
            refused(MySqlStorage::open(db.options.clone()).await, "native_schema_upgrade_required");
            if stopped_after_drop {
                connection.execute("UPDATE dw_server_schema SET engine='rust-upgrading'").await.unwrap();
                connection.execute("DROP FUNCTION dw_child_summary_catalog").await.unwrap();
                let (_, installed) = physical_scope(&mut connection).await.unwrap();
                assert!(!installed.predecessor_routine && !installed.routine && installed.awaiting_routine());
            }
            connection.close().await.unwrap();

            assert_eq!(upgrade(db.options.clone()).await.unwrap(), (flavor.name(), from, flavor.version()));
            MySqlStorage::check(db.options.clone()).await.unwrap();
            let mut connection = db.connection().await;
            let (_, installed) = physical_scope(&mut connection).await.unwrap();
            assert!(installed.complete() && !installed.predecessor_routine);
            let body: String = sqlx::query_scalar(
                "SELECT ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME='dw_child_summary_catalog'")
                .fetch_one(&mut connection).await.unwrap();
            assert!(body.contains("EVENT_OBJECT_SCHEMA=DATABASE()") && !body.contains("TRIGGER_SCHEMA"), "{body}");
            // The SELECT-only inspection evaluates the replaced function.
            checkpoint_catalog::read_only(&mut connection).await.unwrap();
            let kept: String = sqlx::query_scalar("SELECT description FROM workflow_namespaces WHERE name='kept'")
                .fetch_one(&mut connection).await.unwrap();
            assert_eq!(kept, format!("seeded at version {from}"));
            connection.close().await.unwrap();
            MySqlStorage::open(db.options.clone()).await.unwrap().close().await;

            // At the current version neither in-progress state verifies.
            let mut connection = db.connection().await;
            connection.execute("DROP FUNCTION dw_child_summary_catalog").await.unwrap();
            assert!(checkpoint_catalog::read_only(&mut connection).await.is_err());
            refused(MySqlStorage::open(db.options.clone()).await, "native_schema_catalog_mismatch");
            let predecessor = checkpoint_catalog::predecessor_bootstrap_sql();
            let routine = &predecessor[..predecessor.find("CREATE SQL SECURITY DEFINER VIEW").unwrap()];
            connection.execute(AssertSqlSafe(routine.to_owned())).await.unwrap();
            assert!(checkpoint_catalog::read_only(&mut connection).await.is_err());
            refused(MySqlStorage::open(db.options.clone()).await, "native_schema_catalog_mismatch");
            for statement in physical_scope(&mut connection).await.unwrap().1.missing_ddl() {
                connection.execute(AssertSqlSafe(statement)).await.unwrap();
            }
            checkpoint_catalog::read_only(&mut connection).await.unwrap();
            connection.close().await.unwrap();
            MySqlStorage::open(db.options.clone()).await.unwrap().close().await;
            db.remove().await;
        }
    }

    /// Server #1339: the SELECT-only inspection evaluates the catalog
    /// function, which now reads only this database's triggers. It answers
    /// promptly, without error or skipped-table warning, while another
    /// database's `DROP TABLE` holds one table and waits on the other.
    #[tokio::test]
    #[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
    async fn qualification_catalog_function_ignores_another_databases_ddl() {
        let db = Database::new().await;
        MySqlStorage::open(db.options.clone()).await.unwrap().close().await;
        let blocked = BlockedDrop::start().await;
        let mut connection = db.connection().await;
        let started = std::time::Instant::now();
        let inspected = tokio::time::timeout(std::time::Duration::from_secs(2),
            checkpoint_catalog::read_only(&mut connection)).await;
        let elapsed = started.elapsed();
        let warnings: Vec<(String, u32, String)> = sqlx::raw_sql("SHOW WARNINGS")
            .fetch_all(&mut connection).await.unwrap().iter()
            .map(|row| (row.try_get("Level").unwrap(), row.try_get("Code").unwrap(), row.try_get("Message").unwrap()))
            .collect();
        blocked.release().await;
        inspected.expect("the catalog function waited on another database's metadata lock").unwrap();
        assert!(elapsed < std::time::Duration::from_secs(2), "{elapsed:?}");
        assert!(warnings.iter().all(|(_, code, _)| *code != 1684), "{warnings:?}");
        connection.close().await.unwrap();
        db.remove().await;
    }

    /// Server #1339: an account without ALTER ROUTINE on the predecessor
    /// function, as when an administrator installed it, is refused with the
    /// staged-DDL reason; the export drops and recreates only the function.
    #[tokio::test]
    #[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
    async fn qualification_catalog_lookup_upgrade_stages_the_function_without_alter_routine() {
        let db = Database::new().await;
        let mut connection = db.connection().await;
        let flavor = Flavor::detect(&mut connection).await.unwrap();
        let database: String = sqlx::query_scalar("SELECT DATABASE()").fetch_one(&mut connection).await.unwrap();
        assert!(database.starts_with("dw_schema_") && database.bytes().all(|byte| byte.is_ascii_alphanumeric() || byte == b'_'));
        let account = format!("lkp_{}", ulid::Ulid::new().to_string().to_lowercase());
        let password = format!("{}{}a!", ulid::Ulid::new(), ulid::Ulid::new());
        connection.execute(AssertSqlSafe(format!("CREATE USER '{account}'@'%' IDENTIFIED BY '{password}'"))).await.unwrap();
        connection.execute(AssertSqlSafe(format!("GRANT ALL PRIVILEGES ON \x60{database}\x60.* TO '{account}'@'%'"))).await.unwrap();
        let app = db.options.clone().username(&account).password(&password);
        // The application account owns the schema MySQL 22 / MariaDB 21 code,
        // then loses ALTER ROUTINE on the function, as when an administrator
        // installed it from schema-checkpoint-ddl.
        let from = child_summary_catalog_lookup_step(flavor);
        retained_version_database(&Database { options: app.clone(), url: db.url.clone() }, from).await;
        connection.execute(AssertSqlSafe(format!("REVOKE ALTER ROUTINE ON \x60{database}\x60.* FROM '{account}'@'%'"))).await.unwrap();
        let routine_grants: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM mysql.procs_priv WHERE Db=? AND User=? AND Routine_name='dw_child_summary_catalog'")
            .bind(&database).bind(&account).fetch_one(&mut connection).await.unwrap();
        if routine_grants > 0 {
            connection.execute(AssertSqlSafe(format!("REVOKE ALTER ROUTINE ON FUNCTION \x60{database}\x60.dw_child_summary_catalog FROM '{account}'@'%'"))).await.unwrap();
        }
        let denied = upgrade(app.clone()).await.unwrap_err();
        assert!(matches!(denied, super::super::RuntimeError::Refused { reason: "native_child_checkpoint_trigger_privilege_required", .. }), "{denied}");
        let marker: String = sqlx::query_scalar("SELECT engine FROM dw_server_schema").fetch_one(&mut connection).await.unwrap();
        assert_eq!(marker, "rust-upgrading");
        let ddl = child_summary_checkpoint_ddl(app.clone()).await.unwrap();
        assert_eq!(ddl.matches("DROP FUNCTION dw_child_summary_catalog;").count(), 1, "{ddl}");
        assert_eq!(ddl.matches("CREATE DEFINER=").count(), 1, "{ddl}");
        assert!(ddl.find("DROP FUNCTION").unwrap() < ddl.find("CREATE DEFINER=").unwrap(), "{ddl}");
        connection.execute(AssertSqlSafe(ddl)).await.unwrap();
        assert_eq!(upgrade(app.clone()).await.unwrap(), (flavor.name(), from, flavor.version()));
        MySqlStorage::check(app.clone()).await.unwrap();
        MySqlStorage::check(db.options.clone()).await.unwrap();
        connection.execute(AssertSqlSafe(format!("DROP USER '{account}'@'%'"))).await.unwrap();
        connection.close().await.unwrap();
        db.remove().await;
    }

    #[tokio::test]
    #[ignore = "requires an isolated binlogged database with trigger trust disabled"]
    async fn binlogged_checkpoint_upgrade_resumes_with_scoped_trigger_definer() {
        for upgrading in [true, false] {
            let db = Database::new().await;
            let mut connection = db.connection().await;
            let flavor = Flavor::detect(&mut connection).await.unwrap();
            let logging: (i64, i64) = sqlx::query_as("SELECT CAST(@@GLOBAL.log_bin AS SIGNED), CAST(@@GLOBAL.log_bin_trust_function_creators AS SIGNED)")
                .fetch_one(&mut connection).await.unwrap();
            assert_eq!(logging, (1, 0));
            let database: String = sqlx::query_scalar("SELECT DATABASE()").fetch_one(&mut connection).await.unwrap();
            assert!(database.starts_with("dw_schema_") && database.bytes().all(|byte| byte.is_ascii_alphanumeric() || byte == b'_'));
            let account = format!("ckp_{}", ulid::Ulid::new().to_string().to_lowercase());
            let password = format!("{}{}a!", ulid::Ulid::new(), ulid::Ulid::new());
            connection.execute(AssertSqlSafe(format!("CREATE USER '{account}'@'%' IDENTIFIED BY '{password}'"))).await.unwrap();
            connection.execute(AssertSqlSafe(format!("GRANT ALL PRIVILEGES ON \x60{database}\x60.* TO '{account}'@'%'"))).await.unwrap();
            connection.close().await.unwrap();
            let from = child_summary_checkpoint_step(flavor);
            if upgrading {
                retained_version_database(&db, from).await;
            }
            let app = db.options.clone().username(&account).password(&password);
            let denied = if upgrading {
                upgrade(app.clone()).await.unwrap_err()
            } else {
                MySqlStorage::open(app.clone()).await.err().expect("bootstrap requires trigger privileges")
            };
            assert!(matches!(denied, super::super::RuntimeError::Refused { reason: "native_child_checkpoint_trigger_privilege_required", .. }));

            let mut connection = db.connection().await;
            let marker: String = sqlx::query_scalar("SELECT engine FROM dw_server_schema").fetch_one(&mut connection).await.unwrap();
            assert_eq!(marker, if upgrading { "rust-upgrading" } else { "rust-initializing" });
            let ddl = child_summary_checkpoint_ddl(app.clone()).await.unwrap();
            assert_eq!(ddl.matches("CREATE DEFINER=").count(), checkpoint_catalog::trigger_count() + 2);
            connection.execute(AssertSqlSafe(ddl)).await.unwrap();
            connection.close().await.unwrap();
            if upgrading {
                assert_eq!(upgrade(app.clone()).await.unwrap(), (flavor.name(), from, flavor.version()));
            } else {
                MySqlStorage::open(app.clone()).await.unwrap().close().await;
            }
            MySqlStorage::check(app.clone()).await.unwrap();
            MySqlStorage::check(db.options.clone()).await.unwrap();
            assert!(matches!(child_summary_checkpoint_ddl(app).await,
                Err(super::super::RuntimeError::Refused { reason: "native_child_checkpoint_schema_not_staged", .. })));
            let mut connection = db.connection().await;
            let retained: (i64, i64) = sqlx::query_as("SELECT CAST(@@GLOBAL.log_bin AS SIGNED), CAST(@@GLOBAL.log_bin_trust_function_creators AS SIGNED)")
                .fetch_one(&mut connection).await.unwrap();
            assert_eq!(retained, logging);
            connection.execute(AssertSqlSafe(format!("DROP USER '{account}'@'%'"))).await.unwrap();
            connection.close().await.unwrap();
            db.remove().await;
        }
    }

    #[tokio::test]
    #[ignore = "requires the existing disposable mysql source suite"]
    async fn qualification_storage_binding_upgrade_resumes_each_committed_alter_and_preserves_rows() {
        for committed in 0..=2 {
            let db = Database::new().await;
            let mut connection = db.connection().await;
            let flavor = Flavor::detect(&mut connection).await.unwrap();
            connection.close().await.unwrap();
            let from = storage_binding_step(flavor);
            retained_version_database(&db, from).await;
            let mut connection = db.connection().await;
            connection.execute("UPDATE workflow_namespaces SET external_payload_storage='{\"driver\":\"local\",\"enabled\":false,\"config\":{\"uri\":\"file:///original/root\"}}' WHERE name='kept'").await.unwrap();
            connection.execute("INSERT INTO runtime_external_payloads(id,namespace,storage_uri,storage_uri_sha256,codec,sha256,size_bytes,upload_status) VALUES ('original','kept','file:///original/root/avro/aa/object','digest','avro','original-digest',17,'ready')").await.unwrap();
            if committed > 0 {
                connection.execute("UPDATE dw_server_schema SET engine='rust-upgrading'").await.unwrap();
                for statement in [
                    "ALTER TABLE runtime_external_payloads ADD COLUMN storage_binding TEXT NULL",
                    "ALTER TABLE workflow_namespaces ADD COLUMN external_payload_storage_generation BIGINT NOT NULL DEFAULT 0",
                ].into_iter().take(committed) {
                    connection.execute(statement).await.unwrap();
                }
            }
            connection.close().await.unwrap();
            assert_eq!(upgrade(db.options.clone()).await.unwrap(), (flavor.name(), from, flavor.version()));
            MySqlStorage::check(db.options.clone()).await.unwrap();
            let mut connection = db.connection().await;
            let object: (String, String, u64, Option<String>) = sqlx::query_as("SELECT storage_uri,sha256,size_bytes,storage_binding FROM runtime_external_payloads WHERE id='original'").fetch_one(&mut connection).await.unwrap();
            assert_eq!(object, ("file:///original/root/avro/aa/object".into(), "original-digest".into(), 17, None));
            let namespace: (String, i64) = sqlx::query_as("SELECT external_payload_storage,external_payload_storage_generation FROM workflow_namespaces WHERE name='kept'").fetch_one(&mut connection).await.unwrap();
            assert_eq!(serde_json::from_str::<Value>(&namespace.0).unwrap(), serde_json::json!({"driver":"local","enabled":false,"config":{"uri":"file:///original/root"}}));
            assert_eq!(namespace.1, 0);
            connection.close().await.unwrap();
            db.remove().await;
        }
    }

    #[tokio::test]
    #[ignore = "requires the existing disposable mysql source suite"]
    async fn qualification_command_intents_upgrade_resumes() {
        // server#934: MySQL 14 -> 15 and MariaDB 13 -> 14 add only the intent table.
        for stage in ["before", "committed"] {
            let db = Database::new().await;
            let mut connection = db.connection().await;
            let from = command_intents_step(Flavor::detect(&mut connection).await.unwrap());
            connection.close().await.unwrap();
            let flavor = retained_version_database(&db, from).await;
            let mut connection = db.connection().await;
            sqlx::query("INSERT INTO workflow_commands(id,workflow_instance_id,workflow_run_id,command_type,source,status,request_id) VALUES ('legacy-signal','legacy-workflow','legacy-run','signal','control_plane','accepted','RawSignalKey'),('legacy-update','legacy-workflow','legacy-run','update','control_plane','accepted','RawUpdateKey')")
                .execute(&mut connection).await.unwrap();
            connection.close().await.unwrap();
            if stage == "before" {
                refused(MySqlStorage::open(db.options.clone()).await, "native_schema_upgrade_required");
            } else {
                // The table DDL committed before the interrupted upgrade recorded it.
                let mut connection = db.connection().await;
                connection.execute("UPDATE dw_server_schema SET engine='rust-upgrading'").await.unwrap();
                connection.execute(flavor.step(from).unwrap()).await.unwrap();
                connection.close().await.unwrap();
            }
            assert_eq!(upgrade(db.options.clone()).await.unwrap(), (flavor.name(), from, flavor.version()));
            MySqlStorage::check(db.options.clone()).await.unwrap();
            let mut connection = db.connection().await;
            let kept: String = sqlx::query_scalar("SELECT description FROM workflow_namespaces WHERE name='kept'").fetch_one(&mut connection).await.unwrap();
            assert_eq!(kept, format!("seeded at version {from}"));
            let keys: Vec<(String, String, String)> = sqlx::query_as("SELECT command_type,request_id,workflow_run_id FROM workflow_commands WHERE workflow_instance_id='legacy-workflow' ORDER BY command_type")
                .fetch_all(&mut connection).await.unwrap();
            assert_eq!(keys, vec![("signal".into(),"RawSignalKey".into(),"legacy-run".into()),("update".into(),"RawUpdateKey".into(),"legacy-run".into())]);
            let intents: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM dw_command_intents").fetch_one(&mut connection).await.unwrap();
            assert_eq!(intents, 0);
            assert_eq!(catalog(&mut connection).await.unwrap(), flavor.expected().unwrap());
            connection.execute("ALTER TABLE dw_command_intents ADD COLUMN extra TEXT NULL").await.unwrap();
            connection.close().await.unwrap();
            refused(MySqlStorage::open(db.options.clone()).await, "native_schema_catalog_mismatch");
            db.remove().await;
        }
    }

    #[tokio::test]
    #[ignore = "requires the existing disposable mysql source suite"]
    async fn qualification_task_queue_pauses_upgrade_resumes() {
        // server#577: MySQL 13 -> 14 and MariaDB 12 -> 13 add only the pause table.
        for stage in ["before", "committed"] {
            let db = Database::new().await;
            let mut connection = db.connection().await;
            let from = task_queue_pauses_step(Flavor::detect(&mut connection).await.unwrap());
            connection.close().await.unwrap();
            let flavor = retained_version_database(&db, from).await;
            if stage == "before" {
                refused(MySqlStorage::open(db.options.clone()).await, "native_schema_upgrade_required");
            } else {
                // The table DDL committed before the interrupted upgrade recorded it.
                let mut connection = db.connection().await;
                connection.execute("UPDATE dw_server_schema SET engine='rust-upgrading'").await.unwrap();
                connection.execute(flavor.step(from).unwrap()).await.unwrap();
                connection.close().await.unwrap();
            }
            assert_eq!(upgrade(db.options.clone()).await.unwrap(), (flavor.name(), from, flavor.version()));
            MySqlStorage::check(db.options.clone()).await.unwrap();
            let mut connection = db.connection().await;
            let kept: String = sqlx::query_scalar("SELECT description FROM workflow_namespaces WHERE name='kept'").fetch_one(&mut connection).await.unwrap();
            assert_eq!(kept, format!("seeded at version {from}"));
            assert_eq!(catalog(&mut connection).await.unwrap(), flavor.expected().unwrap());
            connection.execute("ALTER TABLE dw_task_queue_pauses ADD COLUMN extra TEXT NULL").await.unwrap();
            connection.close().await.unwrap();
            refused(MySqlStorage::open(db.options.clone()).await, "native_schema_catalog_mismatch");
            db.remove().await;
        }
    }

    #[tokio::test]
    #[ignore = "requires the existing disposable mysql source suite"]
    async fn qualification_version_11_run_command_contracts_upgrade_resumes() {
        for stage in ["before", "committed"] {
            let db = Database::new().await;
            let flavor = retained_version_database(&db, 11).await;
            if stage == "before" {
                refused(MySqlStorage::open(db.options.clone()).await, "native_schema_upgrade_required");
            } else {
                // The table DDL committed before the interrupted upgrade recorded it.
                let mut connection = db.connection().await;
                connection.execute("UPDATE dw_server_schema SET engine='rust-upgrading'").await.unwrap();
                connection.execute(flavor.step(11).unwrap()).await.unwrap();
                connection.close().await.unwrap();
            }
            assert_eq!(upgrade(db.options.clone()).await.unwrap(), (flavor.name(), 11, flavor.version()));
            MySqlStorage::check(db.options.clone()).await.unwrap();
            let mut connection = db.connection().await;
            let kept: String = sqlx::query_scalar("SELECT description FROM workflow_namespaces WHERE name='kept'").fetch_one(&mut connection).await.unwrap();
            assert_eq!(kept, "seeded at version 11");
            assert_eq!(catalog(&mut connection).await.unwrap(), flavor.expected().unwrap());
            connection.execute("DROP INDEX dw_run_command_contracts_namespace ON dw_run_command_contracts").await.unwrap();
            connection.close().await.unwrap();
            refused(MySqlStorage::open(db.options.clone()).await, "native_schema_catalog_mismatch");
            db.remove().await;
        }
    }

    #[tokio::test]
    #[ignore = "requires the existing disposable mysql source suite"]
    async fn qualification_version_12_json_text_upgrade_resumes_and_keeps_rows() {
        for stage in ["before", "partial", "foreign"] {
            let db = Database::new().await;
            let mut connection = db.connection().await;
            let flavor = Flavor::detect(&mut connection).await.unwrap();
            connection.close().await.unwrap();
            let Flavor::MySql = flavor else {
                // MariaDB stores JSON as text already: it has no JSON text step.
                assert!(flavor.step(12).is_some_and(|step| !step.contains("ALTER TABLE")));
                db.remove().await;
                return;
            };
            retained_version_database(&db, 12).await;
            let mut connection = db.connection().await;
            // A row written at 12 holds MySQL's own double; it keeps it.
            connection.execute(r#"INSERT INTO workflow_history_events(id,workflow_run_id,sequence,event_type,payload) VALUES ('01HISTORYROW0000000000000A','01RUN0000000000000000000AA',1,'WorkflowStarted','{"x":1.4820142857142857,"y":"λ"}')"#).await.unwrap();
            let before: String = sqlx::query_scalar("SELECT CAST(payload AS CHAR) FROM workflow_history_events").fetch_one(&mut connection).await.unwrap();
            assert_eq!(before, r#"{"x": 1.4820142857142855, "y": "λ"}"#);
            match stage {
                "before" => {}
                "partial" => {
                    // Two table rebuilds committed before the run was interrupted.
                    connection.execute("UPDATE dw_server_schema SET engine='rust-upgrading'").await.unwrap();
                    for statement in flavor.step(12).unwrap().split(";\n").filter(|s| s.contains("ALTER TABLE")).take(2) {
                        let alter = &statement[statement.find("ALTER TABLE").unwrap()..];
                        connection.execute(AssertSqlSafe(alter.to_owned())).await.unwrap();
                    }
                }
                _ => {
                    // A table that is neither the 12 nor the 13 definition.
                    connection.execute("UPDATE dw_server_schema SET engine='rust-upgrading'").await.unwrap();
                    connection.execute("ALTER TABLE workflow_history_events MODIFY COLUMN payload LONGTEXT").await.unwrap();
                }
            }
            connection.close().await.unwrap();
            if stage == "before" {
                refused(MySqlStorage::open(db.options.clone()).await, "native_schema_upgrade_required");
            }
            if stage == "foreign" {
                upgrade_refused(upgrade(db.options.clone()).await, "native_schema_catalog_mismatch");
                db.remove().await;
                continue;
            }
            assert_eq!(upgrade(db.options.clone()).await.unwrap(), (flavor.name(), 12, flavor.version()));
            MySqlStorage::check(db.options.clone()).await.unwrap();
            let mut connection = db.connection().await;
            assert_eq!(catalog(&mut connection).await.unwrap(), flavor.expected().unwrap());
            let after: String = sqlx::query_scalar("SELECT payload FROM workflow_history_events").fetch_one(&mut connection).await.unwrap();
            assert_eq!(after, before, "the upgrade keeps each stored document as MySQL rendered it");
            let enforced: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND CONSTRAINT_TYPE='CHECK' AND ENFORCED='YES' AND CONSTRAINT_NAME LIKE 'dw\\_json\\_text\\_%'")
                .fetch_one(&mut connection).await.unwrap();
            assert_eq!(enforced, 75);
            // New text keeps every digit, and invalid JSON is refused.
            connection.execute(r#"UPDATE workflow_history_events SET payload='{"x":1.4820142857142857}'"#).await.unwrap();
            let exact: String = sqlx::query_scalar("SELECT payload FROM workflow_history_events").fetch_one(&mut connection).await.unwrap();
            assert_eq!(exact, r#"{"x":1.4820142857142857}"#);
            assert!(connection.execute("UPDATE workflow_history_events SET payload='{not json'").await.is_err());
            connection.close().await.unwrap();
            db.remove().await;
        }
    }

    /// Server #1016 gate: seeds `DW_JSON_TEXT_TIMING_ROWS` history rows of
    /// about 1 KiB at schema 12, then times the upgrade to 13. Skipped unless
    /// the variable is set.
    #[tokio::test]
    #[ignore = "requires the existing disposable mysql source suite"]
    async fn qualification_json_text_upgrade_timing() {
        let Ok(rows) = std::env::var("DW_JSON_TEXT_TIMING_ROWS") else { return };
        let rows: i64 = rows.parse().unwrap();
        let db = Database::new().await;
        let mut connection = db.connection().await;
        let flavor = Flavor::detect(&mut connection).await.unwrap();
        connection.close().await.unwrap();
        let Flavor::MySql = flavor else { db.remove().await; return };
        retained_version_database(&db, 12).await;
        let mut connection = db.connection().await;
        // A seeding helper, dropped before the upgrade so the catalog is exact.
        connection.execute("CREATE TABLE timing_digits (d INT PRIMARY KEY)").await.unwrap();
        connection.execute("INSERT INTO timing_digits VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9)").await.unwrap();
        let payload = r#"{"activity_execution_id":"01JZ8Y4K3Q8V6B2N1M0C9X7Z5A","activity_type":"billing.charge-card","sequence":7,"result":{"codec":"avro","blob":"BASE64PAYLOADBASE64PAYLOADBASE64PAYLOADBASE64PAYLOADBASE64PAYLOADBASE64PAYLOADBASE64PAYLOADBASE64PAYLOADBASE64PAYLOADBASE64PAYLOADBASE64PAYLOADBASE64PAYLOADBASE64PAYLOADBASE64PAYLOADBASE64PAYLOADBASE64PAYLOADBASE64PAYLOADBASE64PAYLOADBASE64PAYLOADBASE64PAYLOADBASE64PAYLOAD"},"payload_codec":"avro","attempt_number":1,"activity_attempt_id":"01JZ8Y4K3Q8V6B2N1M0C9X7Z5B","metrics":{"duration_ms":1234.5678901234567,"ratio":0.7071067811865476,"amount":124435.71428571429},"task":{"id":"01JZ8Y4K3Q8V6B2N1M0C9X7Z5C","queue":"default","connection":"default"}}"#;
        let seeded = std::time::Instant::now();
        let mut done = 0;
        while done < rows {
            let batch = (rows - done).min(100_000);
            sqlx::query("INSERT INTO workflow_history_events(id,workflow_run_id,sequence,event_type,payload,recorded_at,recorded_at_utc) SELECT LPAD(?+n,26,'0'),LPAD(FLOOR((?+n)/50),26,'0'),MOD(?+n,50)+1,'ActivityCompleted',?,NOW(6),UTC_TIMESTAMP(6) FROM (SELECT a.d+10*b.d+100*c.d+1000*e.d+10000*f.d AS n FROM timing_digits a,timing_digits b,timing_digits c,timing_digits e,timing_digits f) g WHERE n<?")
                .bind(done).bind(done).bind(done).bind(payload).bind(batch)
                .execute(&mut connection).await.unwrap();
            done += batch.min(100_000);
        }
        connection.execute("DROP TABLE timing_digits").await.unwrap();
        connection.execute("ANALYZE TABLE workflow_history_events").await.unwrap();
        let bytes: i64 = sqlx::query_scalar("SELECT CAST(DATA_LENGTH AS SIGNED) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='workflow_history_events'")
            .fetch_one(&mut connection).await.unwrap();
        let version: String = sqlx::query_scalar("SELECT VERSION()").fetch_one(&mut connection).await.unwrap();
        connection.close().await.unwrap();
        let seeding = seeded.elapsed();
        let started = std::time::Instant::now();
        assert_eq!(upgrade(db.options.clone()).await.unwrap(), (flavor.name(), 12, flavor.version()));
        let elapsed = started.elapsed().as_secs_f64();
        println!("JSON_TEXT_TIMING mysql={version} rows={rows} history_data_bytes={bytes} seed_seconds={:.1} upgrade_seconds={elapsed:.1} seconds_per_million_rows={:.1}",
            seeding.as_secs_f64(), elapsed * 1_000_000.0 / rows as f64);
        db.remove().await;
    }

    #[tokio::test]
    #[ignore = "requires the existing disposable mysql source suite"]
    async fn qualification_version_10_scoped_index_upgrade_resumes_only_exact_definitions() {
        for stage in ["before", "committed", "wrong-prefix"] {
            let db = Database::new().await;
            let flavor = version_10_database(&db).await;
            if stage == "before" {
                refused(MySqlStorage::open(db.options.clone()).await, "native_schema_upgrade_required");
            } else {
                let mut connection = db.connection().await;
                connection.execute("UPDATE dw_server_schema SET engine='rust-upgrading'").await.unwrap();
                if stage == "committed" {
                    connection.execute(flavor.step(10).unwrap()).await.unwrap();
                } else {
                    connection.execute("CREATE INDEX dw_workflow_tasks_scoped_dispatch ON workflow_tasks (namespace(64),queue(64),task_type(16),status(16),priority,available_at,id)").await.unwrap();
                }
                connection.close().await.unwrap();
            }
            if stage == "wrong-prefix" {
                let mut connection = db.connection().await;
                let before = catalog(&mut connection).await.unwrap();
                upgrade_refused(upgrade(db.options.clone()).await, "native_schema_catalog_mismatch");
                assert_eq!(catalog(&mut connection).await.unwrap(), before);
                connection.close().await.unwrap();
            } else {
                assert_eq!(upgrade(db.options.clone()).await.unwrap(), (flavor.name(), 10, flavor.version()));
                MySqlStorage::check(db.options.clone()).await.unwrap();
                let mut connection = db.connection().await;
                let kept: String = sqlx::query_scalar("SELECT description FROM workflow_namespaces WHERE name='kept'").fetch_one(&mut connection).await.unwrap();
                assert_eq!(kept, "seeded at version 10");
                assert_eq!(catalog(&mut connection).await.unwrap(), flavor.expected().unwrap());
                connection.execute("DROP INDEX dw_workflow_tasks_scoped_dispatch ON workflow_tasks").await.unwrap();
                connection.close().await.unwrap();
                refused(MySqlStorage::open(db.options.clone()).await, "native_schema_catalog_mismatch");
            }
            db.remove().await;
        }
    }

    #[test]
    fn version_9_bootstraps_keep_their_pinned_source_checksums() {
        for (flavor, pinned) in [
            (Flavor::MySql, include_str!("../../migrations/mysql/0009_checksum.txt")),
            (Flavor::MariaDb, include_str!("../../migrations/mariadb/0009_checksum.txt")),
        ] {
            assert_eq!(checksum_hex(flavor.earlier(9).unwrap().0), pinned.trim());
            let sql = flavor.migration().sql.as_ref();
            assert_eq!(sql.matches("KEY dw_query_cache_expiration (expiration)").count(), 1);
            assert_eq!(sql.matches("KEY dw_poll_receipts_expiration (expires_at)").count(), 1);
            assert!(!sql.contains("CREATE INDEX dw_poll_receipts_expiration"));
        }
    }

    #[tokio::test]
    #[ignore = "requires the existing disposable mysql source suite"]
    async fn qualification_version_9_expiry_indexes_resume_without_row_changes() {
        for completed in 0..=2 {
            let db = Database::new().await;
            let flavor = version_9_database(&db).await;
            if completed > 0 {
                let mut connection = db.connection().await;
                connection.execute("UPDATE dw_server_schema SET engine='rust-upgrading'").await.unwrap();
                connection.execute("CREATE INDEX dw_query_cache_expiration ON dw_query_cache (expiration)").await.unwrap();
                if completed == 2 {
                    connection.execute("CREATE INDEX dw_poll_receipts_expiration ON dw_poll_receipts (expires_at)").await.unwrap();
                }
                connection.close().await.unwrap();
            }
            assert_eq!(upgrade(db.options.clone()).await.unwrap(), (flavor.name(), 9, flavor.version()));
            MySqlStorage::check(db.options.clone()).await.unwrap();
            let mut connection = db.connection().await;
            let kept: String = sqlx::query_scalar("SELECT description FROM workflow_namespaces WHERE name='kept'").fetch_one(&mut connection).await.unwrap();
            assert_eq!(kept, "seeded at version 9");
            assert_eq!(catalog(&mut connection).await.unwrap(), flavor.expected().unwrap());
            connection.execute("DROP INDEX dw_poll_receipts_expiration ON dw_poll_receipts").await.unwrap();
            connection.close().await.unwrap();
            refused(MySqlStorage::open(db.options.clone()).await, "native_schema_catalog_mismatch");
            db.remove().await;
        }
    }

    #[tokio::test]
    #[ignore = "requires the existing disposable mysql source suite"]
    async fn qualification_version_8_ready_origin_upgrade_resumes_without_backfill() {
        for already_added in [false,true] {
            let db=Database::new().await;
            let flavor=version_8_database(&db).await;
            let mut connection=db.connection().await;
            connection.execute("INSERT INTO workflow_instances(id,workflow_class,workflow_type,namespace) VALUES ('legacy-instance','Legacy','legacy','default');
INSERT INTO workflow_runs(id,workflow_instance_id,run_number,workflow_class,workflow_type,namespace,status) VALUES ('legacy-run','legacy-instance',1,'Legacy','legacy','default','pending');
INSERT INTO workflow_tasks(id,workflow_run_id,task_type,status,namespace,available_at,created_at) VALUES ('legacy-task','legacy-run','workflow','ready','default','2026-01-01 00:00:00','2026-01-01 00:00:00');").await.unwrap();
            if already_added {
                connection.execute("UPDATE dw_server_schema SET engine='rust-upgrading'").await.unwrap();
                connection.execute(flavor.step(8).unwrap()).await.unwrap();
            }
            connection.close().await.unwrap();
            assert_eq!(upgrade(db.options.clone()).await.unwrap(),(flavor.name(),8,flavor.version()));
            MySqlStorage::check(db.options.clone()).await.unwrap();
            let mut connection=db.connection().await;
            let count:i64=sqlx::query_scalar("SELECT COUNT(*) FROM workflow_tasks WHERE id='legacy-task' AND status='ready' AND ready_origin_at IS NULL").fetch_one(&mut connection).await.unwrap();
            assert_eq!(count,1);
            connection.close().await.unwrap();
            db.remove().await;
        }
    }

    #[tokio::test]
    #[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
    async fn qualification_schema_upgrade_moves_version_7_to_the_current_schema() {
        let db = Database::new().await;
        let flavor = version_7_database(&db).await;
        refused(MySqlStorage::open(db.options.clone()).await, "native_schema_upgrade_required");
        assert_eq!(upgrade(db.options.clone()).await.unwrap(), (flavor.name(), 7, flavor.version()));
        // Opening verifies marker, ledger checksum and the captured catalog
        // of a fresh bootstrap; rows written at version 7 are kept.
        let storage = MySqlStorage::open(db.options.clone()).await.unwrap();
        storage.close().await;
        let mut connection = db.connection().await;
        let kept: String = sqlx::query_scalar("SELECT description FROM workflow_namespaces WHERE name='kept'")
            .fetch_one(&mut connection)
            .await
            .unwrap();
        assert_eq!(kept, "seeded at version 7");
        let ledger: Vec<(i64, bool)> = sqlx::query_as("SELECT version,success FROM _sqlx_migrations")
            .fetch_all(&mut connection)
            .await
            .unwrap();
        assert_eq!(ledger, [(flavor.version(), true)]);
        connection.close().await.unwrap();
        assert_eq!(upgrade(db.options.clone()).await.unwrap(), (flavor.name(), flavor.version(), flavor.version()));
        db.remove().await;
    }

    #[tokio::test]
    #[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
    async fn qualification_schema_upgrade_resumes_simulated_interrupted_states() {
        // DDL commits on its own, so a killed upgrade leaves the fencing
        // marker, with the new table either not yet or already created. These
        // states are constructed here; no upgrade process is killed.
        for created in [false, true] {
            let db = Database::new().await;
            let flavor = version_7_database(&db).await;
            let mut connection = db.connection().await;
            sqlx::query("UPDATE dw_server_schema SET engine='rust-upgrading'")
                .execute(&mut connection)
                .await
                .unwrap();
            if created {
                connection
                    .execute(AssertSqlSafe(flavor.step(7).unwrap().to_owned()))
                    .await
                    .unwrap();
            }
            connection.close().await.unwrap();
            refused(MySqlStorage::open(db.options.clone()).await, "native_schema_upgrade_incomplete");
            assert_eq!(upgrade(db.options.clone()).await.unwrap(), (flavor.name(), 7, flavor.version()), "created {created}");
            MySqlStorage::open(db.options.clone()).await.unwrap().close().await;
            db.remove().await;
        }
    }

    #[tokio::test]
    #[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
    async fn qualification_schema_upgrade_refuses_what_it_cannot_recognise() {
        for (mutation, reason) in [
            ("ALTER TABLE workflow_namespaces ADD COLUMN unexpected TEXT NULL", "native_schema_catalog_mismatch"),
            ("UPDATE _sqlx_migrations SET checksum=X'00'", "native_migration_history_mismatch"),
            ("UPDATE dw_server_schema SET bootstrap_checksum=X'00'", "unsupported_rust_schema"),
            ("UPDATE dw_server_schema SET version=99", "native_schema_newer_than_binary"),
            ("UPDATE dw_server_schema SET version=5", "unsupported_rust_schema"),
        ] {
            let db = Database::new().await;
            version_7_database(&db).await;
            let mut connection = db.connection().await;
            connection.execute(AssertSqlSafe(mutation.to_owned())).await.unwrap();
            upgrade_refused(upgrade(db.options.clone()).await, reason);
            // Refused before any DDL or marker change.
            let added: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dw_external_payload_deletions'")
                .fetch_one(&mut connection)
                .await
                .unwrap();
            assert_eq!(added, 0, "{mutation}");
            let engine: String = sqlx::query_scalar("SELECT engine FROM dw_server_schema")
                .fetch_one(&mut connection)
                .await
                .unwrap();
            assert_eq!(engine, "rust-development", "{mutation}");
            connection.close().await.unwrap();
            db.remove().await;
        }
        let empty = Database::new().await;
        upgrade_refused(upgrade(empty.options.clone()).await, "native_schema_not_initialized");
        empty.remove().await;
    }
}
