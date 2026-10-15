use super::*;
use sqlx::{Executor, sqlite::SqliteJournalMode};
use std::path::Path;

async fn php_layout() -> (tempfile::TempDir, String) {
    // A unit layout derived from the frozen DDL is a refusal/control fixture,
    // not proof that an actual published PHP installation has been inspected.
    let directory = tempfile::tempdir().unwrap();
    let path = directory
        .path()
        .join("php.sqlite")
        .to_str()
        .unwrap()
        .to_owned();
    let mut connection = SqliteConnection::connect_with(
        &SqliteConnectOptions::new()
            .filename(&path)
            .create_if_missing(true)
            .foreign_keys(false)
            .journal_mode(SqliteJournalMode::Delete),
    )
    .await
    .unwrap();
    connection.execute("BEGIN").await.unwrap();
    sqlx::raw_sql(concat!(
        include_str!("../../migrations/sqlite/0003_full_schema.sql"),
        "\n",
        include_str!("../../migrations/sqlite/0004_worker_incarnations.sql")
    ))
    .execute(&mut connection)
    .await
    .unwrap();
    for table in [
        "dw_worker_registration_incarnations",
        "dw_task_completions",
        "dw_poll_receipts",
        "dw_query_cache",
        "dw_server_schema",
    ] {
        sqlx::query(AssertSqlSafe(format!("DROP TABLE {table}")))
            .execute(&mut connection)
            .await
            .unwrap();
    }
    connection
        .execute("DROP INDEX dw_workflow_tasks_poll")
        .await
        .unwrap();
    sqlx::query("INSERT INTO jobs(queue,payload,attempts,available_at,created_at) VALUES ('test','private-payload-λ',0,1,1)")
        .execute(&mut connection).await.unwrap();
    connection.execute("COMMIT").await.unwrap();
    connection.close().await.unwrap();
    (directory, path)
}

async fn mutate(path: &str, statement: &'static str) {
    let mut connection = SqliteConnection::connect_with(
        &SqliteConnectOptions::new()
            .filename(path)
            .foreign_keys(false)
            .busy_timeout(Duration::from_millis(50)),
    )
    .await
    .unwrap();
    connection.execute(statement).await.unwrap();
    connection.close().await.unwrap();
}

fn reason(result: Result<Value>, expected: &str) {
    assert!(
        matches!(result, Err(super::super::RuntimeError::Refused { reason, .. }) if reason == expected)
    );
}

#[tokio::test]
async fn missing_source_is_not_created() {
    let directory = tempfile::tempdir().unwrap();
    let path = directory.path().join("missing.sqlite");
    assert!(inspect_sqlite(path.to_str().unwrap()).await.is_err());
    assert!(!path.exists());
    assert_eq!(std::fs::read_dir(directory.path()).unwrap().count(), 0);
}

#[tokio::test]
async fn wal_source_refuses_before_creating_sidecars_or_ignoring_wal() {
    let (_directory, path) = php_layout().await;
    mutate(&path, "PRAGMA journal_mode=WAL").await;
    let before = std::fs::read(&path).unwrap();
    assert_eq!(&before[18..20], &[2, 2]);
    assert!(!Path::new(&format!("{path}-wal")).exists());
    assert!(!Path::new(&format!("{path}-shm")).exists());
    reason(
        inspect_sqlite(&path).await,
        "upgrade_source_sqlite_journal_unqualified",
    );
    assert_eq!(std::fs::read(&path).unwrap(), before);
    assert!(!Path::new(&format!("{path}-wal")).exists());
    assert!(!Path::new(&format!("{path}-shm")).exists());
    // A live WAL with committed changes must also refuse, never silently
    // inspect only the main file as an immutable connection could do.
    let mut writer = SqliteConnection::connect_with(&SqliteConnectOptions::new().filename(&path))
        .await
        .unwrap();
    writer.execute("UPDATE jobs SET attempts=1").await.unwrap();
    let wal_path = format!("{path}-wal");
    let wal = std::fs::read(&wal_path).unwrap();
    reason(
        inspect_sqlite(&path).await,
        "upgrade_source_sqlite_journal_unqualified",
    );
    assert_eq!(std::fs::read(&wal_path).unwrap(), wal);
    writer.close().await.unwrap();
}

#[tokio::test]
async fn inspection_preserves_source_bytes_reports_counts_and_never_grants_takeover() {
    let (_directory, path) = php_layout().await;
    let before = std::fs::read(&path).unwrap();
    let inspected = inspect_sqlite(&path).await.unwrap();
    assert_eq!(inspected["status"], "inspected");
    assert_eq!(inspected["backend"], "sqlite");
    assert_eq!(inspected["table_rows"].as_object().unwrap().len(), 49);
    assert_eq!(inspected["table_rows"]["jobs"], 1);
    assert_eq!(inspected["table_rows"]["workflow_runs"], 0);
    assert_eq!(inspected["catalog_sha256"].as_str().unwrap().len(), 64);
    for flag in [
        "source_mutated",
        "takeover_allowed",
        "backup_verified",
        "php_quiescence_verified",
        "stored_representation_compatibility_verified",
    ] {
        assert_eq!(inspected[flag], false, "{flag}");
    }
    assert!(!inspected.to_string().contains("private-payload"));
    assert_eq!(std::fs::read(&path).unwrap(), before);
    assert!(!Path::new(&format!("{path}-wal")).exists());
    assert!(!Path::new(&format!("{path}-journal")).exists());
    // No reader or abandoned transaction survives the command's return.
    mutate(&path, "BEGIN EXCLUSIVE; ROLLBACK").await;
    assert_eq!(std::fs::read(&path).unwrap(), before);
    let refused = super::super::sqlite::open(&path).await;
    assert!(matches!(
        refused,
        Err(super::super::RuntimeError::Refused {
            reason: "existing_database_requires_qualified_takeover",
            ..
        })
    ));
    assert_eq!(std::fs::read(&path).unwrap(), before);
}

#[tokio::test]
async fn unknown_source_catalog_refuses_without_repair_or_retained_reader() {
    let (_directory, path) = php_layout().await;
    mutate(&path, "ALTER TABLE jobs ADD COLUMN unexpected TEXT").await;
    let before = std::fs::read(&path).unwrap();
    reason(
        inspect_sqlite(&path).await,
        "upgrade_source_catalog_unqualified",
    );
    assert_eq!(std::fs::read(&path).unwrap(), before);
    mutate(&path, "BEGIN EXCLUSIVE; ROLLBACK").await;
    assert_eq!(std::fs::read(&path).unwrap(), before);
}

#[tokio::test]
async fn empty_malformed_and_view_ownership_markers_refuse_before_definition_execution() {
    for statement in [
        "CREATE TABLE dw_server_schema (unexpected INTEGER)",
        "CREATE VIEW DW_SERVER_SCHEMA AS SELECT missing_function()",
    ] {
        let (_directory, path) = php_layout().await;
        mutate(&path, statement).await;
        let before = std::fs::read(&path).unwrap();
        reason(inspect_sqlite(&path).await, "upgrade_source_already_owned");
        assert_eq!(std::fs::read(&path).unwrap(), before);
        mutate(&path, "BEGIN EXCLUSIVE; ROLLBACK").await;
    }
}

#[cfg(unix)]
#[tokio::test]
async fn read_only_source_file_can_be_inspected_without_write_permissions() {
    use std::os::unix::fs::PermissionsExt;
    let (_directory, path) = php_layout().await;
    std::fs::set_permissions(&path, std::fs::Permissions::from_mode(0o444)).unwrap();
    let before = std::fs::read(&path).unwrap();
    assert_eq!(
        inspect_sqlite(&path).await.unwrap()["table_rows"]["jobs"],
        1
    );
    assert_eq!(std::fs::read(&path).unwrap(), before);
}
