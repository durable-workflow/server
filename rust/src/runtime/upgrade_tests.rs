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
        json!([
            "workflow_durable_stream_items",
            "workflow_inbound_stream_items",
            "workflow_update_validation_tasks"
        ])
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

#[tokio::test]
async fn fingerprint_matches_a_byte_copy_and_changes_with_any_row() {
    let (directory, path) = php_layout().await;
    let before = tokio::fs::read(&path).await.unwrap();
    let first = fingerprint::fingerprint_sqlite(&path).await.unwrap();
    assert_eq!(first["status"], "fingerprinted");
    assert_eq!(first["table_rows"]["jobs"], 1);
    for flag in [
        "source_mutated",
        "takeover_allowed",
        "backup_verified",
        "php_quiescence_verified",
        "stored_representation_compatibility_verified",
    ] {
        assert_eq!(first[flag], false, "{flag}");
    }
    assert!(!first.to_string().contains("private-payload"), "{first}");
    assert_eq!(tokio::fs::read(&path).await.unwrap(), before);
    assert_eq!(
        fingerprint::fingerprint_sqlite(&path).await.unwrap()["fingerprint"],
        first["fingerprint"]
    );
    let copy = directory.path().join("copy.sqlite");
    tokio::fs::copy(&path, &copy).await.unwrap();
    let copy = copy.to_str().unwrap();
    assert_eq!(
        fingerprint::fingerprint_sqlite(copy).await.unwrap()["fingerprint"],
        first["fingerprint"]
    );
    let mut seen = vec![first["fingerprint"].clone()];
    for statement in [
        "UPDATE jobs SET attempts=1",
        "UPDATE jobs SET reserved_at=7",
        "UPDATE jobs SET reserved_at=0",
        "UPDATE jobs SET payload='private-payload-λ '",
        "INSERT INTO migrations(migration,batch) VALUES ('extra',1)",
    ] {
        mutate(copy, statement).await;
        let changed = fingerprint::fingerprint_sqlite(copy).await.unwrap()["fingerprint"].clone();
        assert!(!seen.contains(&changed), "{statement}");
        seen.push(changed);
    }
    // The digest describes contents, so restoring earlier values restores it.
    mutate(
        copy,
        "UPDATE jobs SET attempts=0,reserved_at=NULL,payload='private-payload-λ'; DELETE FROM migrations",
    )
    .await;
    assert_eq!(
        fingerprint::fingerprint_sqlite(copy).await.unwrap()["fingerprint"],
        first["fingerprint"]
    );
}

#[tokio::test]
async fn fingerprint_ignores_physical_row_order_but_not_storage_class() {
    let (_first_directory, first) = php_layout().await;
    let (_second_directory, second) = php_layout().await;
    mutate(&first, "INSERT INTO migrations(id,migration,batch) VALUES (5,'a',1),(3,'b',1)").await;
    mutate(&second, "INSERT INTO migrations(id,migration,batch) VALUES (3,'b',1)").await;
    mutate(&second, "INSERT INTO migrations(id,migration,batch) VALUES (5,'a',1)").await;
    let one = fingerprint::fingerprint_sqlite(&first).await.unwrap();
    assert_eq!(
        fingerprint::fingerprint_sqlite(&second).await.unwrap()["fingerprint"],
        one["fingerprint"]
    );
    // A text value is not the same row as an equal-looking blob.
    mutate(&second, "UPDATE migrations SET migration=CAST('a' AS BLOB) WHERE id=5").await;
    assert_ne!(
        fingerprint::fingerprint_sqlite(&second).await.unwrap()["fingerprint"],
        one["fingerprint"]
    );
}

#[tokio::test]
async fn fingerprint_refuses_an_owned_or_unknown_source() {
    let (_directory, path) = php_layout().await;
    mutate(&path, "CREATE TABLE dw_server_schema(engine TEXT)").await;
    reason(
        fingerprint::fingerprint_sqlite(&path).await,
        "upgrade_source_already_owned",
    );
    let (_directory, path) = php_layout().await;
    mutate(&path, "ALTER TABLE jobs ADD COLUMN unexpected TEXT").await;
    reason(
        fingerprint::fingerprint_sqlite(&path).await,
        "upgrade_source_catalog_unqualified",
    );
}

#[test]
fn source_layout_names_the_release_each_recorded_delta_reaches() {
    let rows = |rows: &[(&str, &str)]| -> Vec<(String, String)> {
        rows.iter()
            .map(|(name, ddl)| ((*name).to_owned(), (*ddl).to_owned()))
            .collect()
    };
    let frozen = rows(&[("a", "1"), ("b", "2")]);
    let deltas = [
        r#"{"remove":[["a","1"]],"add":[["a","1 changed"]]}"#,
        r#"{"remove":[],"add":[["c","3"]]}"#,
    ];
    let layout = |actual: &[(&str, &str)]| {
        source_layout(&rows(actual), frozen.clone(), deltas, |row| row.0.clone())
            .map(|layout| Value::from(layout))
    };
    assert_eq!(layout(&[("b", "2"), ("a", "1")]).unwrap(), "frozen");
    assert_eq!(layout(&[("a", "1 changed"), ("b", "2")]).unwrap(), "php-2.8.0");
    assert_eq!(
        layout(&[("c", "3"), ("a", "1 changed"), ("b", "2")]).unwrap(),
        "php-2.9.7"
    );
    // A later release's addition without the earlier change is no recorded layout.
    reason(
        layout(&[("a", "1"), ("b", "2"), ("c", "3")]),
        "upgrade_source_catalog_unqualified",
    );
    reason(layout(&[("a", "1")]), "upgrade_source_catalog_unqualified");
}

#[tokio::test]
async fn frozen_layout_is_reported_by_inspection_and_fingerprint() {
    let (_directory, path) = php_layout().await;
    assert_eq!(inspect_sqlite(&path).await.unwrap()["source_layout"], "frozen");
    assert_eq!(
        fingerprint::fingerprint_sqlite(&path).await.unwrap()["source_layout"],
        "frozen"
    );
}

/// The frozen test layout moved to PHP 2.9.7's, by the same column, table and
/// index changes PHP's migrations make. Inspection must call it php-2.9.7.
async fn php297_layout() -> (tempfile::TempDir, String) {
    let (directory, path) = php_layout().await;
    for statement in [
        // The frozen test layout's private job is not a recognized delivery.
        "DELETE FROM jobs",
        r#"ALTER TABLE "workflow_worker_registrations" ADD COLUMN "registration_token" varchar"#,
        r#"CREATE TABLE "workflow_worker_registration_incarnations" ("token" varchar not null, "namespace" varchar not null, "worker_id" varchar not null, "status" varchar not null, "recovered_workflow_task_count" integer not null default '0', "finished_at" datetime, "created_at" datetime, "updated_at" datetime, primary key ("token"))"#,
        r#"CREATE INDEX "workflow_worker_registration_incarnations_finished_at_index" on "workflow_worker_registration_incarnations" ("finished_at")"#,
        r#"CREATE INDEX "dw_worker_incarnation_scope_idx" on "workflow_worker_registration_incarnations" ("namespace", "worker_id")"#,
        r#"ALTER TABLE "workflow_service_calls" ADD COLUMN "call_deadline_at" datetime"#,
        r#"CREATE INDEX "wf_service_calls_deadline_idx" on "workflow_service_calls" ("status", "call_deadline_at")"#,
        r#"ALTER TABLE "workflow_tasks" ADD COLUMN "queue_delivery_reference" text"#,
    ] {
        mutate(&path, statement).await;
    }
    (directory, path)
}

async fn backup_fingerprint(path: &str) -> String {
    fingerprint::fingerprint_sqlite(path).await.unwrap()["fingerprint"]
        .as_str()
        .unwrap()
        .to_owned()
}

#[tokio::test]
async fn takeover_converts_a_php_297_database_that_native_then_opens() {
    let (_directory, path) = php297_layout().await;
    assert_eq!(inspect_sqlite(&path).await.unwrap()["source_layout"], "php-2.9.7");
    for statement in [
        "INSERT INTO workflow_worker_registrations(worker_id,namespace,task_queue,runtime,registration_token,created_at,updated_at) VALUES ('current','default','q','php','0123456789abcdef0123456789abcdef','2026-10-20 09:00:00','2026-10-20 09:00:00')",
        "INSERT INTO workflow_worker_registrations(worker_id,namespace,task_queue,runtime,created_at,updated_at) VALUES ('older','default','q','php','2026-10-20 09:00:00','2026-10-20 09:00:00')",
        "INSERT INTO workflow_worker_registration_incarnations VALUES ('0123456789abcdef0123456789abcdef','default','current','active',0,NULL,'2026-10-20 09:00:00.000000','2026-10-20 09:00:00.000000')",
        "INSERT INTO workflow_worker_registration_incarnations VALUES ('11111111111111111111111111111111','default','current','superseded',0,'2026-10-20 08:00:00.000000','2026-10-20 07:00:00.000000','2026-10-20 08:00:00.000000')",
        "INSERT INTO workflow_worker_registration_incarnations VALUES ('22222222222222222222222222222222','default','gone','deregistered',3,'2026-10-20 08:30:00.000000','2026-10-20 07:00:00.000000','2026-10-20 08:30:00.000000')",
    ] {
        mutate(&path, statement).await;
    }
    let fingerprint = backup_fingerprint(&path).await;
    let report = takeover::take_over_sqlite(&path, &fingerprint, &queue_config())
        .await
        .unwrap();
    assert_eq!(report["status"], "taken_over", "{report}");
    assert_eq!(report["source_layout"], "php-2.9.7");
    assert_eq!(report["converted"]["incarnations_copied"], 3, "{report}");
    assert_eq!(report["converted"]["incarnations_created"], 1, "{report}");
    let mut connection = SqliteConnection::connect(&format!("sqlite://{path}")).await.unwrap();
    let incarnations: Vec<(String, String, String, Option<i64>, Option<String>)> = sqlx::query_as(
        "SELECT token,worker_id,status,recovered_workflow_task_count,finished_at FROM dw_worker_registration_incarnations ORDER BY worker_id,status",
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
        None
    )));
    assert!(incarnations.iter().any(|row| row.1 == "gone" && row.2 == "deregistered" && row.3 == Some(3)));
    assert!(incarnations.iter().any(|row| row.1 == "current" && row.2 == "superseded" && row.3.is_none() && row.4.is_some()));
    assert!(incarnations.iter().any(|row| row.1 == "older" && row.2 == "active" && row.0.len() == 32));
    connection.close().await.unwrap();
    let runtime = crate::runtime::Runtime::open(&path, "test-token".into()).await.unwrap();
    runtime.close().await;
    let mut connection = SqliteConnection::connect(&format!("sqlite://{path}")).await.unwrap();
    let marker: (String, i64) = sqlx::query_as("SELECT engine,version FROM dw_server_schema")
        .fetch_one(&mut connection)
        .await
        .unwrap();
    assert_eq!(marker, ("rust-development".to_owned(), 4));
    connection.close().await.unwrap();
}

#[tokio::test]
#[ignore = "only invoked as a real subprocess by the takeover interruption test"]
async fn sqlite_takeover_child() {
    let path = std::env::var("DW_TAKEOVER_TEST_DATABASE").unwrap();
    let fingerprint = std::env::var("DW_TAKEOVER_TEST_FINGERPRINT").unwrap();
    // Parks just before the commit, where the parent kills this process.
    let report = takeover::take_over_sqlite(&path, &fingerprint, &queue_config()).await;
    panic!("takeover returned instead of parking: {report:?}");
}

#[cfg(unix)]
#[tokio::test]
async fn takeover_killed_before_commit_leaves_php_data_and_can_be_rerun() {
    use std::os::unix::process::ExitStatusExt;
    let (directory, path) = php297_layout().await;
    let fingerprint = backup_fingerprint(&path).await;
    let original = tokio::fs::read(&path).await.unwrap();
    let receipt = directory.path().join("boundary");
    // The child process is supervised on a blocking thread.
    let (child_path, child_fingerprint) = (path.clone(), fingerprint.clone());
    let status = tokio::task::spawn_blocking(move || {
        let mut child = std::process::Command::new(std::env::current_exe().unwrap())
            .args([
                "--exact",
                "runtime::upgrade::tests::sqlite_takeover_child",
                "--ignored",
                "--nocapture",
            ])
            .env("DW_TAKEOVER_TEST_DATABASE", &child_path)
            .env("DW_TAKEOVER_TEST_FINGERPRINT", &child_fingerprint)
            .env("DW_TAKEOVER_TEST_RECEIPT", &receipt)
            .spawn()
            .unwrap();
        let deadline = std::time::Instant::now() + Duration::from_secs(60);
        while !receipt.exists() && std::time::Instant::now() < deadline {
            if let Some(status) = child.try_wait().unwrap() {
                panic!("child exited before the commit point: {status}");
            }
            std::thread::sleep(Duration::from_millis(10));
        }
        if !receipt.exists() {
            child.kill().unwrap();
            child.wait().unwrap();
            panic!("child did not reach the commit point");
        }
        child.kill().unwrap();
        child.wait().unwrap()
    })
    .await
    .unwrap();
    assert_eq!(status.signal(), Some(9));
    // Either SQLite had not written the file yet, or its rollback journal
    // still holds the original pages. Read-only inspection never recovers it.
    if Path::new(&format!("{path}-journal")).exists() {
        reason(
            inspect_sqlite(&path).await,
            "upgrade_source_sqlite_journal_unqualified",
        );
    } else {
        assert_eq!(tokio::fs::read(&path).await.unwrap(), original);
    }
    // Rerunning with the same verified fingerprint rolls the journal back,
    // finds the backed-up PHP database and takes it over.
    let report = takeover::take_over_sqlite(&path, &fingerprint, &queue_config())
        .await
        .unwrap();
    assert_eq!(report["status"], "taken_over", "{report}");
    assert_eq!(report["fingerprint"], fingerprint.as_str());
    let runtime = crate::runtime::Runtime::open(&path, "test-token".into()).await.unwrap();
    runtime.close().await;
}

#[tokio::test]
async fn takeover_of_the_frozen_layout_adds_only_native_relations() {
    let (_directory, path) = php_layout().await;
    mutate(&path, "DELETE FROM jobs").await;
    let fingerprint = backup_fingerprint(&path).await;
    let report = takeover::take_over_sqlite(&path, &fingerprint, &queue_config())
        .await
        .unwrap();
    assert_eq!(report["source_layout"], "frozen", "{report}");
    let runtime = crate::runtime::Runtime::open(&path, "test-token".into()).await.unwrap();
    runtime.close().await;
}

#[tokio::test]
async fn takeover_refusals_leave_the_php_database_unchanged() {
    let (_directory, path) = php297_layout().await;
    let fingerprint = backup_fingerprint(&path).await;
    let original = std::fs::read(&path).unwrap();
    reason(
        takeover::take_over_sqlite(&path, &format!("sha256:{}", "0".repeat(64)), &queue_config()).await,
        "upgrade_backup_fingerprint_mismatch",
    );
    reason(
        takeover::take_over_sqlite(&path, "not-a-fingerprint", &queue_config()).await,
        "upgrade_backup_fingerprint_required",
    );
    assert_eq!(std::fs::read(&path).unwrap(), original);
    // A write after the backup changes the digest, so the old one is refused.
    mutate(&path, "INSERT INTO migrations(migration,batch) VALUES ('after-backup',9)").await;
    reason(
        takeover::take_over_sqlite(&path, &fingerprint, &queue_config()).await,
        "upgrade_backup_fingerprint_mismatch",
    );
    for (statement, expected) in [
        (
            "INSERT INTO workflow_service_calls(id,endpoint_name,service_name,operation_name,status,operation_mode) VALUES ('call','e','s','o','accepted','sync')",
            "upgrade_source_service_calls_unsupported",
        ),
        (
            "INSERT INTO runtime_external_payloads(id,namespace,storage_uri,storage_uri_sha256,codec,sha256,size_bytes) VALUES ('p','default','s3://b/k','x','avro','y',1)",
            "upgrade_source_external_payloads_unsupported",
        ),
        // Features native lacks: their rows would be left behind unread.
        (
            "INSERT INTO runtime_credentials(id,subject,roles,tenant,token_prefix,token_hash) VALUES ('c','worker','[]','default','dw_','h')",
            "upgrade_source_runtime_credentials_unsupported",
        ),
        (
            "INSERT INTO workflow_messages(workflow_instance_id,direction,channel,stream_key,sequence) VALUES ('w','inbound','c','k',1)",
            "upgrade_source_message_streams_unsupported",
        ),
    ] {
        let (_directory, path) = php297_layout().await;
        mutate(&path, statement).await;
        let fingerprint = backup_fingerprint(&path).await;
        let before = std::fs::read(&path).unwrap();
        reason(
            takeover::take_over_sqlite(&path, &fingerprint, &queue_config()).await,
            expected,
        );
        assert_eq!(std::fs::read(&path).unwrap(), before, "{expected}");
    }
}

/// The frozen layout in PHP Server's default WAL mode, copied while its
/// writer was still open: one committed row is only in the WAL, as a crashed
/// PHP would leave it.
async fn wal_source() -> (tempfile::TempDir, String) {
    let (directory, path) = php_layout().await;
    let mut writer = SqliteConnection::connect_with(
        &SqliteConnectOptions::new()
            .filename(&path)
            .journal_mode(SqliteJournalMode::Wal),
    )
    .await
    .unwrap();
    writer.execute("PRAGMA wal_autocheckpoint=0").await.unwrap();
    writer
        .execute("INSERT INTO migrations(migration,batch) VALUES ('only-in-wal',7)")
        .await
        .unwrap();
    let copy = directory.path().join("wal.sqlite").to_str().unwrap().to_owned();
    for suffix in ["", "-wal", "-shm"] {
        tokio::fs::copy(format!("{path}{suffix}"), format!("{copy}{suffix}"))
            .await
            .unwrap();
    }
    writer.close().await.unwrap();
    (directory, copy)
}

#[tokio::test]
async fn prepare_switches_a_wal_source_to_the_rollback_journal_and_keeps_its_rows() {
    let (_directory, path) = wal_source().await;
    assert!(Path::new(&format!("{path}-wal")).exists());
    reason(
        inspect_sqlite(&path).await,
        "upgrade_source_sqlite_journal_unqualified",
    );
    let report = prepare_sqlite_source(&path).await.unwrap();
    assert_eq!(report["status"], "rollback_journal", "{report}");
    assert_eq!(report["previous_journal_mode"], "wal");
    assert_eq!(report["source_mutated"], true);
    for suffix in ["-wal", "-shm", "-journal"] {
        assert!(!Path::new(&format!("{path}{suffix}")).exists(), "{suffix}");
    }
    assert_eq!(journal_format(&path).await.unwrap(), ROLLBACK_JOURNAL);
    let inventory = inspect_sqlite(&path).await.unwrap();
    assert_eq!(inventory["table_rows"]["migrations"], 1, "{inventory}");
    let mut connection = SqliteConnection::connect(&format!("sqlite://{path}?mode=ro"))
        .await
        .unwrap();
    let batch: i64 = sqlx::query_scalar("SELECT batch FROM migrations WHERE migration='only-in-wal'")
        .fetch_one(&mut connection)
        .await
        .unwrap();
    assert_eq!(batch, 7);
    connection.close().await.unwrap();
    // Preparing again changes nothing.
    let original = std::fs::read(&path).unwrap();
    assert_eq!(prepare_sqlite_source(&path).await.unwrap()["source_mutated"], false);
    assert_eq!(std::fs::read(&path).unwrap(), original);
}

#[tokio::test]
async fn prepare_refuses_while_another_connection_holds_the_source() {
    let (_directory, path) = php_layout().await;
    let mut writer = SqliteConnection::connect_with(
        &SqliteConnectOptions::new()
            .filename(&path)
            .journal_mode(SqliteJournalMode::Wal),
    )
    .await
    .unwrap();
    writer
        .execute("INSERT INTO migrations(migration,batch) VALUES ('open',8)")
        .await
        .unwrap();
    reason(prepare_sqlite_source(&path).await, "upgrade_source_in_use");
    assert_eq!(journal_format(&path).await.unwrap(), WRITE_AHEAD_LOG);
    writer.close().await.unwrap();
    let report = prepare_sqlite_source(&path).await.unwrap();
    assert_eq!(report["previous_journal_mode"], "wal", "{report}");
}

#[tokio::test]
async fn prepare_leaves_a_rollback_journal_source_byte_identical() {
    let (_directory, path) = php_layout().await;
    let original = std::fs::read(&path).unwrap();
    let report = prepare_sqlite_source(&path).await.unwrap();
    assert_eq!(report["previous_journal_mode"], "delete", "{report}");
    assert_eq!(report["source_mutated"], false);
    assert_eq!(std::fs::read(&path).unwrap(), original);
}
