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

fn queue_config() -> QueueSourceConfig<'static> {
    QueueSourceConfig {
        connection: "database",
        default_connection: "database",
        default_queue: "default",
    }
}

async fn queued_layout(job_count: i64) -> (tempfile::TempDir, String) {
    use super::queue_binding::{SourceQueueJob, SourceRun, SourceTask};
    #[derive(Deserialize)]
    struct Fixture {
        jobs: Vec<SourceQueueJob>,
        tasks: Vec<SourceTask>,
        runs: Vec<SourceRun>,
    }
    // Reconstruct the selected synthetic rows for scanner controls. The
    // committed JSON has a genuine PHP producer; this SQL layout is a unit
    // control, not a new actual PHP installation or takeover.
    let fixture: Fixture = serde_json::from_str(include_str!(
        "../../../tests/Fixtures/ServerUpgrade/php-2.8.2-pending-queue.json"
    ))
    .unwrap();
    let (directory, path) = php_layout().await;
    let mut connection = SqliteConnection::connect_with(
        &SqliteConnectOptions::new()
            .filename(&path)
            .foreign_keys(false),
    )
    .await
    .unwrap();
    connection.execute("DELETE FROM jobs").await.unwrap();
    for run in fixture.runs {
        sqlx::query("INSERT INTO workflow_runs(id,workflow_instance_id,run_number,workflow_class,workflow_type,namespace,queue,status) VALUES (?,?,1,?,?,?,?,?)")
            .bind(run.id).bind(run.workflow_instance_id).bind(run.workflow_class).bind(run.workflow_type)
            .bind(run.namespace).bind(run.queue).bind(run.status).execute(&mut connection).await.unwrap();
    }
    for task in fixture.tasks {
        sqlx::query("INSERT INTO workflow_tasks(id,workflow_run_id,namespace,task_type,status,connection,queue,available_at,lease_owner,lease_expires_at,attempt_count) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            .bind(task.id).bind(task.workflow_run_id).bind(task.namespace).bind(task.task_type).bind(task.status)
            .bind(task.connection).bind(task.queue).bind(task.available_at).bind(task.lease_owner)
            .bind(task.lease_expires_at).bind(task.attempt_count).execute(&mut connection).await.unwrap();
    }
    let job = &fixture.jobs[0];
    for id in 1..=job_count {
        sqlx::query("INSERT INTO jobs(id,queue,payload,attempts,reserved_at,available_at,created_at) VALUES (?,?,?,?,?,?,?)")
            .bind(id).bind(&job.queue).bind(&job.payload).bind(job.attempts).bind(job.reserved_at)
            .bind(job.available_at).bind(job.created_at).execute(&mut connection).await.unwrap();
    }
    connection.close().await.unwrap();
    (directory, path)
}

#[tokio::test]
async fn queue_scan_checks_every_page_without_mutating_original_tasks_or_jobs() {
    let (_directory, path) = queued_layout(33).await;
    let before = std::fs::read(&path).unwrap();
    let report = inspect_sqlite_queues(&path, &queue_config()).await.unwrap();
    assert_eq!(report["queue_inspection"]["jobs"], 33);
    assert_eq!(report["queue_inspection"]["timer_jobs"], 33);
    assert_eq!(report["queue_inspection"]["workflow_jobs"], 0);
    assert_eq!(report["table_rows"]["workflow_tasks"], 4);
    assert_eq!(report["takeover_allowed"], false);
    assert_eq!(
        report["stored_representation_compatibility_verified"],
        false
    );
    assert!(!report.to_string().contains("08:16:21.566122"));
    assert!(!report.to_string().contains("RunTimerTask"));
    assert_eq!(std::fs::read(&path).unwrap(), before);
    mutate(&path, "BEGIN EXCLUSIVE; ROLLBACK").await;
    assert_eq!(std::fs::read(&path).unwrap(), before);
}

#[tokio::test]
async fn queue_scan_refuses_unknown_jobs_after_the_first_page_without_partial_success() {
    let (_directory, path) = queued_layout(33).await;
    mutate(&path, "INSERT INTO jobs(id,queue,payload,attempts,available_at,created_at) VALUES (34,'default','private-unknown-job',0,1,1)").await;
    let before = std::fs::read(&path).unwrap();
    reason(
        inspect_sqlite_queues(&path, &queue_config()).await,
        "upgrade_source_queue_representation_unqualified",
    );
    assert_eq!(std::fs::read(&path).unwrap(), before);
    mutate(&path, "BEGIN EXCLUSIVE; ROLLBACK").await;
}

#[tokio::test]
async fn queue_scan_refuses_missing_or_foreign_canonical_bindings() {
    for (statement, expected) in [
        (
            "DELETE FROM workflow_tasks WHERE task_type='timer'",
            "upgrade_source_queue_task_missing",
        ),
        (
            "DELETE FROM workflow_runs WHERE workflow_type='parity.upgrade_timer'",
            "upgrade_source_queue_run_missing",
        ),
        (
            "UPDATE workflow_tasks SET namespace='foreign' WHERE task_type='timer'",
            "upgrade_source_queue_binding_unqualified",
        ),
        (
            "UPDATE jobs SET queue='foreign'",
            "upgrade_source_queue_binding_unqualified",
        ),
    ] {
        let (_directory, path) = queued_layout(1).await;
        mutate(&path, statement).await;
        let before = std::fs::read(&path).unwrap();
        reason(
            inspect_sqlite_queues(&path, &queue_config()).await,
            expected,
        );
        assert_eq!(std::fs::read(&path).unwrap(), before);
        mutate(&path, "BEGIN EXCLUSIVE; ROLLBACK").await;
    }
}

#[tokio::test]
async fn queue_scan_cannot_skip_nonpositive_ids_or_transfer_oversized_payloads() {
    for (statement, expected) in [
        (
            "INSERT INTO jobs(id,queue,payload,attempts,available_at,created_at) VALUES (0,'default','unread',0,1,1)",
            "upgrade_source_queue_binding_unqualified",
        ),
        (
            "UPDATE jobs SET payload=CAST(zeroblob(131073) AS TEXT)",
            "upgrade_source_queue_representation_unqualified",
        ),
        // SQLite keeps oversized varchar values that the frozen catalog cannot
        // reject, so every transferred text column is measured first.
        (
            "UPDATE jobs SET queue=printf('%.5000c','q')",
            "upgrade_source_queue_representation_unqualified",
        ),
        (
            "UPDATE workflow_tasks SET lease_owner=printf('%.5000c','o')",
            "upgrade_source_queue_representation_unqualified",
        ),
        (
            "UPDATE workflow_runs SET workflow_class=printf('%.5000c','c')",
            "upgrade_source_queue_representation_unqualified",
        ),
    ] {
        let (_directory, path) = queued_layout(1).await;
        mutate(&path, statement).await;
        let before = std::fs::read(&path).unwrap();
        reason(
            inspect_sqlite_queues(&path, &queue_config()).await,
            expected,
        );
        assert_eq!(std::fs::read(&path).unwrap(), before);
        mutate(&path, "BEGIN EXCLUSIVE; ROLLBACK").await;
    }
}

#[tokio::test]
async fn queue_scan_requires_explicit_transport_context_before_opening_source() {
    let directory = tempfile::tempdir().unwrap();
    let path = directory.path().join("missing.sqlite");
    let invalid = QueueSourceConfig {
        default_queue: "",
        ..queue_config()
    };
    reason(
        inspect_sqlite_queues(path.to_str().unwrap(), &invalid).await,
        "upgrade_source_queue_config_required",
    );
    assert!(!path.exists());
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

fn stored_reference() -> String {
    let fixture: Value = serde_json::from_str(include_str!(
        "../../../tests/Fixtures/ServerUpgrade/php-2.5.20-external-references.json"
    ))
    .unwrap();
    fixture["cases"][0]["stored"].as_str().unwrap().to_owned()
}

async fn payload_run(
    connection: &mut SqliteConnection,
    id: &str,
    arguments: Option<&str>,
    output: Option<&str>,
    codec: &str,
) {
    sqlx::query("INSERT INTO workflow_runs(id,workflow_instance_id,run_number,workflow_class,workflow_type,status,payload_codec,arguments,output,output_payload_codec) VALUES (?, ?,1,'SourceFixture','source.fixture','completed',?,?,?,'avro')")
        .bind(id).bind(format!("instance-{id}")).bind(codec).bind(arguments).bind(output)
        .execute(connection).await.unwrap();
}

fn column_counts<'a>(report: &'a Value, table: &str, column: &str) -> &'a Value {
    &report["payload_inspection"]["columns"]
        .as_array()
        .unwrap()
        .iter()
        .find(|value| value["table"] == table && value["column"] == column)
        .unwrap()["counts"]
}

#[tokio::test]
async fn payload_scan_validates_inline_pages_and_reports_late_bad_values_without_rewriting() {
    use crate::codec::{Value as Payload, ValueCodec};
    let (_directory, path) = php_layout().await;
    let codec = ValueCodec::new().unwrap();
    let arguments = codec
        .encode(&Payload::Array(vec![
            Payload::Long(9007199254740993),
            Payload::Null,
            Payload::String("日本語 λ".into()),
            Payload::Bytes(b"O:6:\"Sample\":0:{}".to_vec()),
        ]))
        .unwrap();
    let result = codec.encode(&Payload::Boolean(false)).unwrap();
    let mut connection = SqliteConnection::connect_with(
        &SqliteConnectOptions::new()
            .filename(&path)
            .foreign_keys(false),
    )
    .await
    .unwrap();
    for index in 0..33 {
        payload_run(
            &mut connection,
            &format!("run-{index:02}"),
            Some(&arguments),
            Some(&result),
            "avro",
        )
        .await;
    }
    sqlx::query("INSERT INTO activity_executions(id,workflow_run_id,sequence,activity_class,activity_type,status,payload_codec,arguments,result) VALUES ('inline-activity','run-00',1,'SourceFixture','source.fixture','completed','avro',?,?)")
        .bind(&arguments).bind(codec.encode(&Payload::Null).unwrap()).execute(&mut connection).await.unwrap();
    connection.close().await.unwrap();
    let before = std::fs::read(&path).unwrap();
    let report = inspect_sqlite_payloads(&path).await.unwrap();
    assert_eq!(report["status"], "inspected");
    assert_eq!(report["payload_inspection"]["inline_values_verified"], true);
    assert_eq!(report["payload_inspection"]["other_root_payload_columns_verified"], false);
    assert_eq!(
        report["payload_inspection"]["unscanned_payload_codec_tables"],
        json!(["workflow_commands", "workflow_signal_records", "workflow_updates"])
    );
    assert_eq!(report["payload_inspection"]["diagnostics"], json!([]));
    for (table, column, count) in [
        ("workflow_runs", "arguments", 33),
        ("workflow_runs", "output", 33),
        ("activity_executions", "arguments", 1),
        ("activity_executions", "result", 1),
    ] {
        assert_eq!(
            column_counts(&report, table, column)["avro_inline_values"],
            count
        );
    }
    assert_eq!(
        report["stored_representation_compatibility_verified"],
        false
    );
    assert_eq!(report["takeover_allowed"], false);
    assert_eq!(std::fs::read(&path).unwrap(), before);
    for (sql, expected) in [
        (
            "UPDATE workflow_runs SET arguments='private-invalid-inline' WHERE id='run-32'",
            "stored_inline_frame_unqualified",
        ),
        (
            "UPDATE workflow_runs SET payload_codec='legacy' WHERE id='run-32'",
            "stored_inline_codec_unqualified",
        ),
        (
            "UPDATE workflow_runs SET payload_codec=NULL WHERE id='run-32'",
            "stored_inline_codec_unqualified",
        ),
    ] {
        mutate(&path, sql).await;
        let before = std::fs::read(&path).unwrap();
        let report = inspect_sqlite_payloads(&path).await.unwrap();
        assert_eq!(report["status"], "unqualified");
        assert_eq!(
            report["payload_inspection"]["inline_values_verified"],
            false
        );
        assert_eq!(
            report["payload_inspection"]["diagnostics"][0]["record_id"],
            "run-32"
        );
        assert_eq!(
            report["payload_inspection"]["diagnostics"][0]["reason"],
            expected
        );
        assert!(!report.to_string().contains("private-invalid-inline"));
        assert_eq!(std::fs::read(&path).unwrap(), before);
    }
}

#[tokio::test]
async fn payload_scan_checks_all_four_columns_and_every_page_without_mutation_or_locations() {
    let (_directory, path) = php_layout().await;
    let mut connection = SqliteConnection::connect_with(
        &SqliteConnectOptions::new()
            .filename(&path)
            .foreign_keys(false),
    )
    .await
    .unwrap();
    let reference = stored_reference();
    let large_inline = "private-inline-value".repeat(100_000);
    for index in 0..33 {
        let arguments = match index {
            0 => Some(reference.as_str()),
            1 => None,
            _ => Some(large_inline.as_str()),
        };
        payload_run(
            &mut connection,
            &format!("run-{index:02}"),
            arguments,
            (index == 32).then_some(reference.as_str()),
            "avro",
        )
        .await;
    }
    sqlx::query("INSERT INTO activity_executions(id,workflow_run_id,sequence,activity_class,activity_type,status,payload_codec,arguments,result) VALUES ('activity-1','run-00',1,'SourceFixture','source.fixture','completed','avro',?,?)")
        .bind(&reference).bind(&reference).execute(&mut connection).await.unwrap();
    connection.close().await.unwrap();
    let before = std::fs::read(&path).unwrap();
    let report = inspect_sqlite_payloads(&path).await.unwrap();
    assert_eq!(report["status"], "unqualified");
    assert_eq!(
        report["payload_inspection"]["references_metadata_supported"],
        true
    );
    assert_eq!(
        column_counts(&report, "workflow_runs", "arguments"),
        &json!({"rows":33,"absent":1,"other_unvalidated":31,"avro_inline_values":0,"php_v1_references":1,"unqualified_references":0})
    );
    assert_eq!(
        column_counts(&report, "workflow_runs", "output")["php_v1_references"],
        1
    );
    for field in ["arguments", "result"] {
        assert_eq!(
            column_counts(&report, "activity_executions", field)["php_v1_references"],
            1
        );
    }
    assert_eq!(
        report["payload_inspection"]["diagnostics"]
            .as_array()
            .unwrap()
            .len(),
        16
    );
    assert_eq!(report["payload_inspection"]["diagnostics_omitted"], 15);
    assert_eq!(
        report["payload_inspection"]["diagnostics"][0]["reason"],
        "stored_inline_payload_too_large"
    );
    for flag in [
        "inline_values_verified",
        "nested_payloads_verified",
        "other_root_payload_columns_verified",
        "provider_bytes_verified",
        "registry_binding_verified",
        "source_mutated",
        "takeover_allowed",
    ] {
        assert_eq!(report["payload_inspection"][flag], false, "{flag}");
    }
    assert!(!report.to_string().contains(&reference));
    assert!(!report.to_string().contains("private-inline-value"));
    let decoded = external_reference::inspect_php_reference(&reference, Some("avro"))
        .unwrap()
        .unwrap();
    assert!(!report.to_string().contains(decoded.reference().uri()));
    assert!(!report.to_string().contains(decoded.reference().sha256()));
    assert_eq!(std::fs::read(&path).unwrap(), before);
    mutate(&path, "BEGIN EXCLUSIVE; ROLLBACK").await;
    assert_eq!(std::fs::read(&path).unwrap(), before);
}

#[tokio::test]
async fn payload_scan_reports_unknown_and_malformed_references_after_first_page() {
    let (_directory, path) = php_layout().await;
    let mut connection = SqliteConnection::connect_with(
        &SqliteConnectOptions::new()
            .filename(&path)
            .foreign_keys(false),
    )
    .await
    .unwrap();
    for index in 0..33 {
        payload_run(
            &mut connection,
            &format!("run-{index:02}"),
            None,
            None,
            "avro",
        )
        .await;
    }
    let reference = stored_reference();
    for (id, value, codec) in [
        ("run-33", "dw-external-payload:v2:private-uri", "avro"),
        ("run-34", "dw-external-payload:v1:%%%", "avro"),
        (
            "run-35",
            "dw-native-external-payload-v1:private-uri",
            "avro",
        ),
        ("run-36", reference.as_str(), "json"),
    ] {
        payload_run(&mut connection, id, Some(value), None, codec).await;
    }
    connection.close().await.unwrap();
    let before = std::fs::read(&path).unwrap();
    let report = inspect_sqlite_payloads(&path).await.unwrap();
    assert_eq!(report["status"], "unqualified");
    assert_eq!(
        column_counts(&report, "workflow_runs", "arguments")["unqualified_references"],
        4
    );
    let diagnostics = &report["payload_inspection"]["diagnostics"];
    assert_eq!(diagnostics.as_array().unwrap().len(), 4);
    assert_eq!(
        diagnostics[0],
        json!({"table":"workflow_runs","column":"arguments","record_id":"run-33","reason":"external_reference_format_unsupported"})
    );
    assert_eq!(
        diagnostics[1]["reason"],
        "external_reference_base64_invalid"
    );
    assert_eq!(
        diagnostics[2]["reason"],
        "external_reference_format_unsupported"
    );
    assert_eq!(
        diagnostics[3]["reason"],
        "external_reference_codec_mismatch"
    );
    assert!(!report.to_string().contains("private-uri"));
    assert_eq!(std::fs::read(&path).unwrap(), before);
    assert!(
        inspect_sqlite(&path)
            .await
            .unwrap()
            .get("payload_inspection")
            .is_none()
    );
    mutate(&path, "BEGIN EXCLUSIVE; ROLLBACK").await;
}

#[tokio::test]
async fn payload_scan_bounds_diagnostics_and_refuses_oversized_reference_metadata() {
    let (_directory, path) = php_layout().await;
    let mut connection = SqliteConnection::connect_with(
        &SqliteConnectOptions::new()
            .filename(&path)
            .foreign_keys(false),
    )
    .await
    .unwrap();
    let oversized = format!("dw-external-payload:v1:{}", "A".repeat(65536));
    for index in 0..20 {
        payload_run(
            &mut connection,
            &format!("run-{index:02}"),
            Some(&oversized),
            None,
            "avro",
        )
        .await;
    }
    connection.close().await.unwrap();
    let before = std::fs::read(&path).unwrap();
    let report = inspect_sqlite_payloads(&path).await.unwrap();
    assert_eq!(report["status"], "unqualified");
    assert_eq!(
        column_counts(&report, "workflow_runs", "arguments")["unqualified_references"],
        20
    );
    assert_eq!(
        report["payload_inspection"]["diagnostics"]
            .as_array()
            .unwrap()
            .len(),
        16
    );
    assert_eq!(report["payload_inspection"]["diagnostics_omitted"], 4);
    assert_eq!(
        report["payload_inspection"]["diagnostics"][0]["reason"],
        "external_reference_too_large"
    );
    assert!(report.to_string().len() < 8192);
    assert_eq!(std::fs::read(&path).unwrap(), before);
}

#[tokio::test]
async fn payload_scan_does_not_skip_empty_or_oversized_source_identities() {
    for id in [String::new(), "x".repeat(257)] {
        let (_directory, path) = php_layout().await;
        let mut connection = SqliteConnection::connect_with(
            &SqliteConnectOptions::new()
                .filename(&path)
                .foreign_keys(false),
        )
        .await
        .unwrap();
        payload_run(
            &mut connection,
            &id,
            Some(&stored_reference()),
            None,
            "avro",
        )
        .await;
        connection.close().await.unwrap();
        let before = std::fs::read(&path).unwrap();
        reason(
            inspect_sqlite_payloads(&path).await,
            if id.is_empty() {
                "upgrade_source_payload_rows_unqualified"
            } else {
                "upgrade_source_payload_identity_unqualified"
            },
        );
        assert_eq!(std::fs::read(&path).unwrap(), before);
        mutate(&path, "BEGIN EXCLUSIVE; ROLLBACK").await;
    }
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
