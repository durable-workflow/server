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
        include_str!("../../migrations/sqlite/0004_worker_incarnations.sql"),
        "\n",
        include_str!("../../migrations/sqlite/0005_worker_session_holders.sql"),
        "\n",
        include_str!("../../migrations/sqlite/0006_stream_reclaims.sql"),
        "\n",
        include_str!("../../migrations/sqlite/0007_service_calls.sql"),
        "\n",
        include_str!("../../migrations/sqlite/0008_external_payload_deletions.sql")
    ))
    .execute(&mut connection)
    .await
    .unwrap();
    for table in [
        "dw_external_payload_deletions",
        "dw_service_admission_counters",
        "dw_service_call_deadlines",
        "dw_stream_reclaims",
        "dw_worker_session_holders",
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
    assert_eq!(
        inspected["schedule_inspection"],
        serde_json::json!({"schedules":0,"unsupported":0,"reasons":{}})
    );
    assert_eq!(inspected["storage_inspection"]["unsupported"], 0);
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
        r#"{"remove":[["c","3"]],"add":[["c","3 changed"]]}"#,
        r#"{"remove":[["b","2"]],"add":[["b","2 reordered"]]}"#,
        r#"{"remove":[],"add":[["d","4"]]}"#,
        r#"{"remove":[],"add":[["d","4 after reorder"]]}"#,
        r#"{"remove":[],"add":[["e","5"]]}"#,
        r#"{"remove":[],"add":[["e","5"]]}"#,
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
    assert_eq!(
        layout(&[("c", "3 changed"), ("a", "1 changed"), ("b", "2")]).unwrap(),
        "php-2.11.1"
    );
    assert_eq!(
        layout(&[("c", "3 changed"), ("a", "1 changed"), ("b", "2 reordered")]).unwrap(),
        "php-2.11.1-from-2.9.7"
    );
    // 2.11.3 descends from each 2.11.1 variant separately.
    assert_eq!(
        layout(&[("c", "3 changed"), ("a", "1 changed"), ("b", "2"), ("d", "4")]).unwrap(),
        "php-2.11.3"
    );
    assert_eq!(
        layout(&[("c", "3 changed"), ("a", "1 changed"), ("b", "2 reordered"), ("d", "4 after reorder")]).unwrap(),
        "php-2.11.3-from-2.9.7"
    );
    // 2.11.8 adds the same relation to each 2.11.3 variant.
    assert_eq!(
        layout(&[("c", "3 changed"), ("a", "1 changed"), ("b", "2"), ("d", "4"), ("e", "5")]).unwrap(),
        "php-2.11.8"
    );
    assert_eq!(
        layout(&[("c", "3 changed"), ("a", "1 changed"), ("b", "2 reordered"), ("d", "4 after reorder"), ("e", "5")]).unwrap(),
        "php-2.11.8-from-2.9.7"
    );
    reason(
        layout(&[("c", "3 changed"), ("a", "1 changed"), ("b", "2"), ("d", "4 after reorder")]),
        "upgrade_source_catalog_unqualified",
    );
    // A later release's addition without the earlier change is no recorded layout.
    reason(
        layout(&[("a", "1"), ("b", "2"), ("c", "3")]),
        "upgrade_source_catalog_unqualified",
    );
    reason(layout(&[("a", "1")]), "upgrade_source_catalog_unqualified");
}

#[test]
fn every_recorded_layout_descends_from_an_earlier_one() {
    for (index, (name, parent)) in SOURCE_LAYOUTS.iter().enumerate() {
        assert!(
            *parent == "frozen" || SOURCE_LAYOUTS[..index].iter().any(|(layout, _)| layout == parent),
            "{name} names a parent recorded before it"
        );
    }
    assert_eq!(layout_lineage("php-2.11.3"), [0, 1, 2, 4]);
    assert_eq!(layout_lineage("php-2.11.3-from-2.9.7"), [0, 1, 2, 3, 5]);
    assert_eq!(layout_lineage("php-2.11.8"), [0, 1, 2, 4, 6]);
    assert_eq!(layout_lineage("php-2.11.8-from-2.9.7"), [0, 1, 2, 3, 5, 7]);
    assert!(layout_lineage("frozen").is_empty());
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
    php_release_layout(&[PHP_280_CHANGES, QUEUE_DELIVERY]).await
}

/// PHP 2.8.0's changes to the frozen test layout.
const PHP_280_CHANGES: &[&str] = &[
    // The frozen test layout's private job is not a recognized delivery.
    "DELETE FROM jobs",
    r#"ALTER TABLE "workflow_worker_registrations" ADD COLUMN "registration_token" varchar"#,
    r#"CREATE TABLE "workflow_worker_registration_incarnations" ("token" varchar not null, "namespace" varchar not null, "worker_id" varchar not null, "status" varchar not null, "recovered_workflow_task_count" integer not null default '0', "finished_at" datetime, "created_at" datetime, "updated_at" datetime, primary key ("token"))"#,
    r#"CREATE INDEX "workflow_worker_registration_incarnations_finished_at_index" on "workflow_worker_registration_incarnations" ("finished_at")"#,
    r#"CREATE INDEX "dw_worker_incarnation_scope_idx" on "workflow_worker_registration_incarnations" ("namespace", "worker_id")"#,
    r#"ALTER TABLE "workflow_service_calls" ADD COLUMN "call_deadline_at" datetime"#,
    r#"CREATE INDEX "wf_service_calls_deadline_idx" on "workflow_service_calls" ("status", "call_deadline_at")"#,
];
/// Core 2.7.2's queue delivery reference (PHP 2.9.7).
const QUEUE_DELIVERY: &[&str] = &[r#"ALTER TABLE "workflow_tasks" ADD COLUMN "queue_delivery_reference" text"#];
/// Core 2.10's trace carriers (PHP 2.11.1), in its migrations' order.
const TRACE_CARRIERS: &[&str] = &[
    r#"ALTER TABLE "workflow_runs" ADD COLUMN "observability_context" text"#,
    r#"ALTER TABLE "workflow_commands" ADD COLUMN "observability_context" text"#,
    r#"ALTER TABLE "workflow_tasks" ADD COLUMN "observability_context" text"#,
];

/// PHP 2.11.3's changes (Core 2.14.2 and Server), in its migrations' order.
const RELEASE_2113: &[&str] = &[
    // Core 2.14: full failure messages.
    r#"ALTER TABLE "workflow_failures" ADD COLUMN "message_full" text"#,
    // Core 2.14: the service-call idempotency scope.
    r#"ALTER TABLE "workflow_service_calls" ADD COLUMN "idempotency_scope_hash" varchar"#,
    r#"CREATE UNIQUE INDEX "wf_service_calls_idem_scope_unique" on "workflow_service_calls" ("idempotency_scope_hash")"#,
    // Core 2.14: the service-call dispatch outbox.
    r#"CREATE TABLE "workflow_service_call_dispatches" ("service_call_id" varchar not null, "state" varchar not null, "dispatch_options" text not null, "attempt" integer not null default '1', "max_attempts" integer not null default '1', "available_at" datetime not null, "claim_generation" integer not null default '0', "claim_token" varchar, "claimed_at" datetime, "claim_expires_at" datetime, "authorized_catalog_revision" varchar, "authorized_policy_revision" varchar, "dispatch_attempted_at" datetime, "repair_available_at" datetime, "completed_at" datetime, "last_error" text, "created_at" datetime, "updated_at" datetime, primary key ("service_call_id"))"#,
    r#"CREATE INDEX "wf_service_dispatch_due_idx" on "workflow_service_call_dispatches" ("state", "available_at")"#,
    r#"CREATE INDEX "wf_service_dispatch_claim_idx" on "workflow_service_call_dispatches" ("state", "claim_expires_at")"#,
    r#"CREATE INDEX "wf_service_dispatch_repair_idx" on "workflow_service_call_dispatches" ("state", "repair_available_at")"#,
    // Server: each session's holder incarnation and each stream's reclaim deadline.
    r#"ALTER TABLE "workflow_worker_sessions" ADD COLUMN "lease_owner_incarnation" varchar"#,
    r#"ALTER TABLE "workflow_durable_streams" ADD COLUMN "reclaim_at" datetime"#,
    r#"CREATE INDEX "wf_durable_streams_ns_reclaim_idx" on "workflow_durable_streams" ("namespace", "reclaim_at", "id")"#,
];

/// PHP 2.11.8's change (Server): pending external payload deletions, as a
/// published 2.11.8 bootstrap creates them on SQLite.
const RELEASE_2118: &[&str] = &[
    r#"CREATE TABLE "runtime_external_payload_deletions" ("id" varchar not null, "namespace" varchar not null, "storage_uri" text not null, "storage_uri_sha256" varchar not null, "storage_policy" text not null, "attempts" integer not null default '0', "failure_code" varchar, "created_at" datetime, "updated_at" datetime, primary key ("id"))"#,
    r#"CREATE UNIQUE INDEX "runtime_payload_deletions_namespace_uri_unique" on "runtime_external_payload_deletions" ("namespace", "storage_uri_sha256")"#,
    r#"CREATE INDEX "runtime_payload_deletions_namespace_created_index" on "runtime_external_payload_deletions" ("namespace", "created_at")"#,
];

/// The frozen test layout with a release's changes applied in order.
async fn php_release_layout(changes: &[&[&'static str]]) -> (tempfile::TempDir, String) {
    let (directory, path) = php_layout().await;
    for statement in changes.iter().flat_map(|change| change.iter()) {
        mutate(&path, *statement).await;
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
        // A schedule native runs as PHP stored it, and a deleted cron one,
        // which only keeps its audit history.
        "INSERT INTO workflow_schedules(id,schedule_id,namespace,spec,action,status,overlap_policy,jitter_seconds) VALUES ('s1','daily','default','{\"intervals\":[{\"every\":\"P1D\"}],\"timezone\":\"UTC\"}','{\"workflow_type\":\"echo\",\"task_queue\":\"default\",\"input\":{\"codec\":\"avro\",\"blob\":\"AA==\"},\"execution_timeout_seconds\":3600,\"run_timeout_seconds\":600}','active','skip',0)",
        "INSERT INTO workflow_schedules(id,schedule_id,namespace,spec,action,status,overlap_policy,jitter_seconds,deleted_at) VALUES ('s2','nightly','default','{\"cron_expressions\":[\"0 2 * * *\"]}','{}','deleted','buffer_all',30,'2026-10-19 00:00:00')",
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
    assert_eq!(marker, ("rust-development".to_owned(), super::super::schema::VERSION));
    connection.close().await.unwrap();
}

/// PHP 2.11.1, fresh and as a 2.9.7 database upgrades to it: Core 2.10's
/// trace carriers on runs, commands and tasks, before or after 2.9.7's
/// delivery reference. Takeover counts the carried values and drops them.
#[tokio::test]
async fn takeover_converts_php_2111_databases_and_drops_their_trace_carriers() {
    for (release, changes) in [
        ("php-2.11.1", [PHP_280_CHANGES, TRACE_CARRIERS, QUEUE_DELIVERY]),
        ("php-2.11.1-from-2.9.7", [PHP_280_CHANGES, QUEUE_DELIVERY, TRACE_CARRIERS]),
    ] {
        takeover_converts_a_php_2111_database(release, &changes).await;
    }
}

async fn takeover_converts_a_php_2111_database(release: &str, changes: &[&[&'static str]]) {
    let (_directory, path) = php_release_layout(changes).await;
    assert_eq!(inspect_sqlite(&path).await.unwrap()["source_layout"], release);
    assert_eq!(fingerprint::fingerprint_sqlite(&path).await.unwrap()["source_layout"], release);
    for statement in [
        "INSERT INTO workflow_instances(id,workflow_class,workflow_type,namespace,current_run_id,run_count) VALUES ('traced','Traced','traced','default','01jrun0000000000000000000a',1)",
        "INSERT INTO workflow_runs(id,workflow_instance_id,run_number,workflow_class,workflow_type,namespace,status,observability_context) VALUES ('01jrun0000000000000000000a','traced',1,'Traced','traced','default','completed','{\"traceparent\":\"00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01\"}')",
        "INSERT INTO workflow_tasks(id,workflow_run_id,namespace,task_type,status,observability_context) VALUES ('01jtask000000000000000000a','01jrun0000000000000000000a','default','workflow','completed','{\"traceparent\":\"00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01\"}')",
        "INSERT INTO workflow_tasks(id,workflow_run_id,namespace,task_type,status) VALUES ('01jtask000000000000000000b','01jrun0000000000000000000a','default','workflow','completed')",
    ] {
        mutate(&path, statement).await;
    }
    let fingerprint = backup_fingerprint(&path).await;
    let report = takeover::take_over_sqlite(&path, &fingerprint, &queue_config())
        .await
        .unwrap();
    assert_eq!(report["status"], "taken_over", "{report}");
    assert_eq!(report["source_layout"], release);
    assert_eq!(report["converted"]["observability_contexts_removed"], 2, "{report}");
    assert_eq!(report["converted"]["queue_delivery_references_removed"], 0, "{report}");
    let mut connection = SqliteConnection::connect(&format!("sqlite://{path}")).await.unwrap();
    let columns: i64 = sqlx::query_scalar(concat!(
        "SELECT (SELECT COUNT(*) FROM pragma_table_info('workflow_tasks') WHERE name IN ('observability_context','queue_delivery_reference'))",
        "+(SELECT COUNT(*) FROM pragma_table_info('workflow_runs') WHERE name='observability_context')",
        "+(SELECT COUNT(*) FROM pragma_table_info('workflow_commands') WHERE name='observability_context')"))
        .fetch_one(&mut connection).await.unwrap();
    assert_eq!(columns, 0, "the PHP-only task columns are gone");
    let tasks: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM workflow_tasks").fetch_one(&mut connection).await.unwrap();
    assert_eq!(tasks, 2, "every task row stays");
    connection.close().await.unwrap();
    let runtime = crate::runtime::Runtime::open(&path, "test-token".into()).await.unwrap();
    runtime.close().await;
}

/// PHP 2.11.3 databases, fresh or created by 2.9.x, convert. Native keeps
/// each active session's recorded holder, so a session PHP already orphaned
/// stays orphaned, and drops what it does not keep: full failure messages
/// (the labelled preview and retained history remain), the idempotency scope
/// digest, completed dispatches and the stream reclaim deadline.
#[tokio::test]
async fn takeover_converts_php_2113_databases() {
    for (release, changes) in [
        ("php-2.11.3", [PHP_280_CHANGES, TRACE_CARRIERS, QUEUE_DELIVERY, RELEASE_2113]),
        ("php-2.11.3-from-2.9.7", [PHP_280_CHANGES, QUEUE_DELIVERY, TRACE_CARRIERS, RELEASE_2113]),
    ] {
        takeover_converts_a_php_2113_database(release, &changes).await;
    }
}

async fn takeover_converts_a_php_2113_database(release: &str, changes: &[&[&'static str]]) {
    use sha2::{Digest, Sha256};
    let (_directory, path) = php_release_layout(changes).await;
    assert_eq!(inspect_sqlite(&path).await.unwrap()["source_layout"], release);
    let digest = |token: &str| -> String { Sha256::digest(token.as_bytes()).iter().map(|byte| format!("{byte:02x}")).collect() };
    let at = |offset: i64| (chrono::Utc::now() + chrono::Duration::seconds(offset)).format("%Y-%m-%d %H:%M:%S").to_string();
    let token = "0123456789abcdef0123456789abcdef";
    let statements = [
        format!("INSERT INTO workflow_worker_registrations(worker_id,namespace,task_queue,runtime,registration_token,status,last_heartbeat_at,created_at,updated_at) VALUES ('worker-a','default','q','php','{token}','active','{}','{}','{}')", at(0), at(-60), at(0)),
        format!("INSERT INTO workflow_worker_registration_incarnations VALUES ('{token}','default','worker-a','active',0,NULL,'{}','{}')", at(-60), at(-60)),
        // Held by the current registration, as PHP recorded it.
        format!("INSERT INTO workflow_worker_sessions(namespace,session_id,queue,status,lease_owner,lease_owner_incarnation,lease_expires_at,ttl_expires_at,last_heartbeat_at,created_at,updated_at) VALUES ('default','held','q','active','worker-a','{}','{}','{}','{}','{}','{}')", digest(token), at(600), at(1800), at(-5), at(-60), at(-5)),
        // Held by a registration the worker has since replaced.
        format!("INSERT INTO workflow_worker_sessions(namespace,session_id,queue,status,lease_owner,lease_owner_incarnation,lease_expires_at,ttl_expires_at,last_heartbeat_at,created_at,updated_at) VALUES ('default','replaced','q','active','worker-a','{}','{}','{}','{}','{}','{}')", digest("an earlier registration token"), at(600), at(1800), at(-5), at(-60), at(-5)),
        "INSERT INTO workflow_failures(id,workflow_run_id,source_kind,source_id,propagation_kind,exception_class,message,file,message_full,created_at,updated_at) VALUES ('01m5wvy8d1j3dtr13es05kfail','01jrun0000000000000000000a','activity_execution','a','activity','RuntimeException','preview\n[full message stored separately]','','base64:ZnVsbA==','2026-10-26 10:00:00','2026-10-26 10:00:00')".to_owned(),
        "INSERT INTO workflow_service_calls(id,namespace,endpoint_name,service_name,operation_name,caller_namespace,target_namespace,status,outcome,operation_mode,idempotency_key,idempotency_scope_hash,accepted_at,completed_at,created_at,updated_at) VALUES ('01m5wvy8d1j3dtr13es05kcall','default','pay','billing','charge','default','default','completed','succeeded','async','order-1','0000000000000000000000000000000000000000000000000000000000000001','2026-10-26 10:00:00','2026-10-26 10:00:01','2026-10-26 10:00:00','2026-10-26 10:00:01')".to_owned(),
        "INSERT INTO workflow_service_call_dispatches(service_call_id,state,dispatch_options,available_at,completed_at,created_at,updated_at) VALUES ('01m5wvy8d1j3dtr13es05kcall','completed','{}','2026-10-26 10:00:00','2026-10-26 10:00:01','2026-10-26 10:00:00','2026-10-26 10:00:01')".to_owned(),
        "INSERT INTO workflow_durable_streams(namespace,workflow_instance_id,workflow_run_id,stream_name,status,retention_seconds,closed_at,reclaim_at) VALUES ('default','i','01jrun0000000000000000000a','progress','closed',600,'2026-10-26 10:00:00','2026-10-26 10:10:00')".to_owned(),
    ];
    for statement in &statements {
        mutate(&path, Box::leak(statement.clone().into_boxed_str())).await;
    }
    let fingerprint = backup_fingerprint(&path).await;
    let report = takeover::take_over_sqlite(&path, &fingerprint, &queue_config()).await.unwrap();
    assert_eq!(report["status"], "taken_over", "{report}");
    assert_eq!(report["source_layout"], release);
    assert_eq!(
        [&report["converted"]["session_holders_recorded"], &report["converted"]["full_failure_messages_removed"], &report["converted"]["service_call_dispatches_removed"], &report["converted"]["stream_reclaims_recorded"]],
        [&json!(2), &json!(1), &json!(1), &json!(1)],
        "{report}"
    );
    let mut connection = SqliteConnection::connect(&format!("sqlite://{path}")).await.unwrap();
    let message: String = sqlx::query_scalar("SELECT message FROM workflow_failures").fetch_one(&mut connection).await.unwrap();
    assert_eq!(message, "preview\n[full message stored separately]", "the labelled preview stays");
    let left: i64 = sqlx::query_scalar(concat!(
        "SELECT (SELECT COUNT(*) FROM pragma_table_info('workflow_failures') WHERE name='message_full')",
        "+(SELECT COUNT(*) FROM pragma_table_info('workflow_service_calls') WHERE name='idempotency_scope_hash')",
        "+(SELECT COUNT(*) FROM pragma_table_info('workflow_worker_sessions') WHERE name='lease_owner_incarnation')",
        "+(SELECT COUNT(*) FROM pragma_table_info('workflow_durable_streams') WHERE name='reclaim_at')",
        "+(SELECT COUNT(*) FROM sqlite_master WHERE name='workflow_service_call_dispatches')"))
        .fetch_one(&mut connection).await.unwrap();
    assert_eq!(left, 0, "PHP 2.11.3's additions are gone");
    connection.close().await.unwrap();
    let runtime = crate::runtime::Runtime::open(&path, "test-token".into()).await.unwrap();
    let held = runtime.show_worker_session("held").await.unwrap();
    assert_eq!((held["session"]["status"].clone(), held["session"]["failure_reason"].clone()), (json!("active"), Value::Null), "{held}");
    let replaced = runtime.show_worker_session("replaced").await.unwrap();
    assert_eq!(
        (replaced["session"]["status"].clone(), replaced["session"]["failure_reason"].clone()),
        (json!("orphaned"), json!("worker_registration_replaced")),
        "{replaced}"
    );
    runtime.close().await;
}

#[tokio::test]
async fn takeover_carries_php_2118_pending_deletions_over() {
    for (release, changes) in [
        ("php-2.11.8", [PHP_280_CHANGES, TRACE_CARRIERS, QUEUE_DELIVERY, RELEASE_2113, RELEASE_2118]),
        ("php-2.11.8-from-2.9.7", [PHP_280_CHANGES, QUEUE_DELIVERY, TRACE_CARRIERS, RELEASE_2113, RELEASE_2118]),
    ] {
        let (_directory, path) = php_release_layout(&changes).await;
        // Two deletions PHP recorded after commit: one not yet attempted,
        // one whose provider failed twice; and the policy each saved.
        mutate(&path, r#"INSERT INTO runtime_external_payload_deletions VALUES ('01m5wvy8d1j3dtr13es05kdel1','default','file:///payloads/default/a','5d41402abc4b2a76b9719d911017c592aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','{"driver":"local","enabled":true,"config":{"uri":"file:///payloads/default"}}',0,NULL,'2026-11-01 10:00:00','2026-11-01 10:00:00')"#).await;
        mutate(&path, r#"INSERT INTO runtime_external_payload_deletions VALUES ('01m5wvy8d1j3dtr13es05kdel2','gone','file:///payloads/gone/b','5d41402abc4b2a76b9719d911017c592bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','{"driver":"local","config":{"uri":"file:///payloads/gone"}}',2,'storage_unavailable','2026-11-01 09:00:00','2026-11-01 09:30:00')"#).await;
        let inspection = inspect_sqlite(&path).await.unwrap();
        assert_eq!(inspection["source_layout"], release);
        assert_eq!(inspection["status"], "inspected", "{inspection}");
        assert_eq!(
            inspection["external_payload_deletion_inspection"],
            json!({"pending": 2, "unsupported": 0, "reasons": {}})
        );
        let fingerprint = backup_fingerprint(&path).await;
        let report = takeover::take_over_sqlite(&path, &fingerprint, &queue_config()).await.unwrap();
        assert_eq!(report["status"], "taken_over", "{report}");
        assert_eq!(report["source_layout"], release);
        assert_eq!(report["converted"]["external_payload_deletions_copied"], 2, "{report}");
        let mut connection = SqliteConnection::connect(&format!("sqlite://{path}")).await.unwrap();
        let copied: Vec<(String, String, String, String, i64, Option<String>, String)> = sqlx::query_as(
            "SELECT id,namespace,storage_uri,storage_policy,attempts,failure_code,updated_at FROM dw_external_payload_deletions ORDER BY id",
        )
        .fetch_all(&mut connection)
        .await
        .unwrap();
        assert_eq!(copied.len(), 2);
        assert_eq!(copied[0].0, "01m5wvy8d1j3dtr13es05kdel1");
        assert_eq!(
            serde_json::from_str::<Value>(&copied[0].3).unwrap(),
            json!({"driver":"local","enabled":true,"config":{"uri":"file:///payloads/default"}})
        );
        assert_eq!((copied[1].1.as_str(), copied[1].2.as_str(), copied[1].4, copied[1].5.as_deref()), ("gone", "file:///payloads/gone/b", 2, Some("storage_unavailable")));
        assert_eq!(copied[1].6, "2026-11-01 09:30:00.000000", "PHP's time, in native's format");
        let left: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM sqlite_master WHERE name='runtime_external_payload_deletions'")
            .fetch_one(&mut connection)
            .await
            .unwrap();
        assert_eq!(left, 0, "PHP's table is gone");
        connection.close().await.unwrap();
        // Native opens it: marker, ledger and catalog are its own.
        crate::runtime::Runtime::open(&path, "test-token".into()).await.unwrap().close().await;
    }
}

/// A pending deletion native could never finish (a saved policy native's
/// deletion cannot execute: another driver, an empty policy or a disabled
/// one; or a failure PHP does not record) refuses takeover before any change.
#[tokio::test]
async fn takeover_refuses_a_php_2118_deletion_native_could_never_finish() {
    for (row, reason_counted) in [
        (r#"INSERT INTO runtime_external_payload_deletions VALUES ('01m5wvy8d1j3dtr13es05kdel3','default','s3://bucket/c','cccc','{"driver":"s3","config":{"bucket":"bucket"}}',1,'storage_unavailable','2026-11-01 10:00:00','2026-11-01 10:00:00')"#, "external_payload_deletion_storage_unexecutable"),
        (r#"INSERT INTO runtime_external_payload_deletions VALUES ('01m5wvy8d1j3dtr13es05kdel5','default','file:///payloads/e','eeee','[]',0,NULL,'2026-11-01 10:00:00','2026-11-01 10:00:00')"#, "external_payload_deletion_storage_unexecutable"),
        (r#"INSERT INTO runtime_external_payload_deletions VALUES ('01m5wvy8d1j3dtr13es05kdel6','default','file:///payloads/f','ffff','{}',0,NULL,'2026-11-01 10:00:00','2026-11-01 10:00:00')"#, "external_payload_deletion_storage_unexecutable"),
        (r#"INSERT INTO runtime_external_payload_deletions VALUES ('01m5wvy8d1j3dtr13es05kdel7','default','file:///payloads/g','gggg','{"driver":"local","enabled":false}',0,NULL,'2026-11-01 10:00:00','2026-11-01 10:00:00')"#, "external_payload_deletion_storage_unexecutable"),
        (r#"INSERT INTO runtime_external_payload_deletions VALUES ('01m5wvy8d1j3dtr13es05kdel4','default','file:///payloads/d','dddd','{"driver":"local"}',1,'provider_quota','2026-11-01 10:00:00','2026-11-01 10:00:00')"#, "external_payload_deletion_failure_unrecognized"),
    ] {
        let (_directory, path) = php_release_layout(&[PHP_280_CHANGES, TRACE_CARRIERS, QUEUE_DELIVERY, RELEASE_2113, RELEASE_2118]).await;
        mutate(&path, row).await;
        let inspection = inspect_sqlite(&path).await.unwrap();
        assert_eq!(inspection["status"], "unqualified", "{inspection}");
        assert_eq!(inspection["external_payload_deletion_inspection"]["reasons"][reason_counted], 1, "{inspection}");
        let before = std::fs::read(&path).unwrap();
        let fingerprint = backup_fingerprint(&path).await;
        reason(
            takeover::take_over_sqlite(&path, &fingerprint, &queue_config()).await,
            "upgrade_source_external_payload_deletions_unsupported",
        );
        assert_eq!(std::fs::read(&path).unwrap(), before, "the PHP database is unchanged");
    }
}

/// Native runs a service call's handler when it accepts the call, so it
/// cannot resume a dispatch PHP 2.11.3 scheduled or claimed. Inspection
/// reports it and takeover refuses before any change.
#[tokio::test]
async fn takeover_refuses_a_php_2113_dispatch_still_pending() {
    let (_directory, path) = php_release_layout(&[PHP_280_CHANGES, TRACE_CARRIERS, QUEUE_DELIVERY, RELEASE_2113]).await;
    mutate(&path, "INSERT INTO workflow_service_calls(id,namespace,endpoint_name,service_name,operation_name,caller_namespace,target_namespace,status,outcome,operation_mode,accepted_at,created_at,updated_at) VALUES ('01m5wvy8d1j3dtr13es05kcall','default','pay','billing','charge','default','default','accepted','accepted','async','2026-10-26 10:00:00','2026-10-26 10:00:00','2026-10-26 10:00:00')").await;
    mutate(&path, "INSERT INTO workflow_service_call_dispatches(service_call_id,state,dispatch_options,available_at,created_at,updated_at) VALUES ('01m5wvy8d1j3dtr13es05kcall','scheduled','{}','2026-10-26 10:00:00','2026-10-26 10:00:00','2026-10-26 10:00:00')").await;
    let inspection = inspect_sqlite(&path).await.unwrap();
    assert_eq!(inspection["status"], "unqualified", "{inspection}");
    assert_eq!(inspection["service_call_dispatch_inspection"], json!({"dispatches": 1, "pending": 1, "workflow_bound_open": 0}));
    let before = std::fs::read(&path).unwrap();
    let fingerprint = backup_fingerprint(&path).await;
    reason(
        takeover::take_over_sqlite(&path, &fingerprint, &queue_config()).await,
        "upgrade_source_service_call_dispatches_pending",
    );
    assert_eq!(std::fs::read(&path).unwrap(), before, "the PHP database is unchanged");
}

/// An open service call bound to a workflow run would never close under
/// native, which serves no workflow-bound handler (Server #794): inspection
/// reports it and takeover refuses before any change. A closed one, or an
/// open call of another binding, takes over.
#[tokio::test]
async fn takeover_refuses_an_open_workflow_bound_service_call() {
    let (_directory, path) = php_release_layout(&[PHP_280_CHANGES, TRACE_CARRIERS, QUEUE_DELIVERY, RELEASE_2113]).await;
    let call = |id: &str, kind: &str, status: &str| format!("INSERT INTO workflow_service_calls(id,namespace,endpoint_name,service_name,operation_name,caller_namespace,target_namespace,status,outcome,operation_mode,resolved_binding_kind,accepted_at,created_at,updated_at) VALUES ('{id}','default','pay','billing','charge','default','default','{status}','accepted','async','{kind}','2026-10-26 10:00:00','2026-10-26 10:00:00','2026-10-26 10:00:00')");
    mutate(&path, call("01m5wvy8d1j3dtr13es05kdone", "workflow_run", "completed").leak()).await;
    mutate(&path, call("01m5wvy8d1j3dtr13es05kactv", "activity_execution", "accepted").leak()).await;
    let inspection = inspect_sqlite(&path).await.unwrap();
    assert_eq!(inspection["service_call_dispatch_inspection"], json!({"dispatches": 0, "pending": 0, "workflow_bound_open": 0}));
    mutate(&path, call("01m5wvy8d1j3dtr13es05kopen", "workflow_run", "started").leak()).await;
    let inspection = inspect_sqlite(&path).await.unwrap();
    assert_eq!(inspection["status"], "unqualified", "{inspection}");
    assert_eq!(inspection["service_call_dispatch_inspection"], json!({"dispatches": 0, "pending": 0, "workflow_bound_open": 1}));
    let before = std::fs::read(&path).unwrap();
    let fingerprint = backup_fingerprint(&path).await;
    reason(
        takeover::take_over_sqlite(&path, &fingerprint, &queue_config()).await,
        "upgrade_source_service_calls_workflow_bound_open",
    );
    assert_eq!(std::fs::read(&path).unwrap(), before, "the PHP database is unchanged");
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
            "INSERT INTO runtime_external_payloads(id,namespace,storage_uri,storage_uri_sha256,codec,sha256,size_bytes) VALUES ('p','default','s3://b/k','x','avro','y',1)",
            "upgrade_source_external_payloads_unsupported",
        ),
        // Features native lacks: their rows would be left behind unread.
        (
            "INSERT INTO workflow_messages(workflow_instance_id,direction,channel,stream_key,sequence) VALUES ('w','inbound','c','k',1)",
            "upgrade_source_workflow_messages_unsupported",
        ),
        // A stream item native would carry over but cannot resolve.
        (
            "INSERT INTO workflow_durable_stream_items(stream_id,namespace,workflow_run_id,stream_name,\"offset\",payload_reference,emitted_at) VALUES (1,'default','r','s',0,'dw-external-payload:v1:x','2026-10-26 10:00:00')",
            "upgrade_source_external_payloads_unsupported",
        ),
        // A namespace whose uploads would all be refused: native serves
        // only the local driver.
        (
            "INSERT INTO workflow_namespaces(name,status,external_payload_storage) VALUES ('payments','active','{\"driver\":\"s3\",\"config\":{\"bucket\":\"b\"}}')",
            "upgrade_source_external_payload_storage_unsupported",
        ),
        // Schedules native would describe without their search attributes,
        // or start outside their Laravel queue routing.
        (
            "INSERT INTO workflow_schedules(id,schedule_id,namespace,spec,action,status,overlap_policy,jitter_seconds,search_attributes) VALUES ('s','nightly','default','{\"cron_expressions\":[\"0 2 * * *\"],\"timezone\":\"UTC\"}','{\"workflow_type\":\"echo\",\"task_queue\":\"default\"}','active','skip',0,'{\"Customer\":\"acme\"}')",
            "upgrade_source_schedules_unsupported",
        ),
        (
            "INSERT INTO workflow_schedules(id,schedule_id,namespace,spec,action,status,overlap_policy,jitter_seconds,queue) VALUES ('s','daily','billing','{\"intervals\":[{\"every\":\"P1D\"}],\"timezone\":\"UTC\"}','{\"workflow_type\":\"echo\",\"task_queue\":\"default\",\"input\":{\"codec\":\"avro\",\"blob\":\"AA==\"},\"execution_timeout_seconds\":3600,\"run_timeout_seconds\":600}','paused','skip',0,'schedules')",
            "upgrade_source_schedules_unsupported",
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

#[tokio::test]
async fn takeover_keeps_php_catalog_rows_that_native_then_serves() {
    let (_directory, path) = php297_layout().await;
    for statement in crate::runtime::service_endpoints::PHP_CATALOG_ROWS {
        mutate(&path, statement).await;
    }
    let fingerprint = backup_fingerprint(&path).await;
    let report = takeover::take_over_sqlite(&path, &fingerprint, &queue_config()).await.unwrap();
    assert_eq!(report["status"], "taken_over", "{report}");
    let runtime = crate::runtime::Runtime::open(&path, "test-token".into()).await.unwrap();
    crate::runtime::service_endpoints::assert_php_catalog_served(&runtime).await;
    runtime.close().await;
}

/// Custom search attribute definitions as PHP's SearchAttributeController
/// stores them. Native reads the same rows to list them, type upserts and
/// filter visibility, so takeover keeps them.
#[tokio::test]
async fn takeover_keeps_php_search_attribute_definitions_that_native_then_lists() {
    let (_directory, path) = php_release_layout(&[PHP_280_CHANGES, TRACE_CARRIERS, QUEUE_DELIVERY]).await;
    for statement in [
        "INSERT INTO search_attribute_definitions(namespace,name,type,created_at,updated_at) VALUES ('default','CustomerId','keyword','2026-10-26 10:00:00','2026-10-26 10:00:00')",
        "INSERT INTO search_attribute_definitions(namespace,name,type,created_at,updated_at) VALUES ('default','OrderTotal','float','2026-10-26 10:00:01','2026-10-26 10:00:01')",
        "INSERT INTO search_attribute_definitions(namespace,name,type,created_at,updated_at) VALUES ('other','Region','keyword','2026-10-26 10:00:02','2026-10-26 10:00:02')",
    ] {
        mutate(&path, statement).await;
    }
    let fingerprint = backup_fingerprint(&path).await;
    let report = takeover::take_over_sqlite(&path, &fingerprint, &queue_config()).await.unwrap();
    assert_eq!(report["status"], "taken_over", "{report}");
    let runtime = crate::runtime::Runtime::open(&path, "test-token".into()).await.unwrap();
    let listed = runtime.list_search_attributes().await.unwrap();
    assert_eq!(listed["custom_attributes"], json!({"CustomerId": "keyword", "OrderTotal": "float"}), "{listed}");
    runtime.close().await;
}

/// An active PHP worker session keeps its holder: takeover records the lease
/// owner's current registration incarnation, so native reads the session as
/// still held rather than orphaning it as held by a replaced registration.
#[tokio::test]
async fn takeover_keeps_php_worker_sessions_held_by_their_current_registration() {
    let (_directory, path) = php_release_layout(&[PHP_280_CHANGES, TRACE_CARRIERS, QUEUE_DELIVERY]).await;
    let at = |offset: i64| (chrono::Utc::now() + chrono::Duration::seconds(offset)).format("%Y-%m-%d %H:%M:%S").to_string();
    let statements = [
        format!("INSERT INTO workflow_worker_registrations(worker_id,namespace,task_queue,runtime,registration_token,status,last_heartbeat_at,created_at,updated_at) VALUES ('worker-a','default','q','php','0123456789abcdef0123456789abcdef','active','{}','{}','{}')", at(0), at(-60), at(0)),
        format!("INSERT INTO workflow_worker_registration_incarnations VALUES ('0123456789abcdef0123456789abcdef','default','worker-a','active',0,NULL,'{}','{}')", at(-60), at(-60)),
        format!("INSERT INTO workflow_worker_sessions(namespace,session_id,queue,status,lease_owner,lease_expires_at,ttl_expires_at,last_heartbeat_at,created_at,updated_at) VALUES ('default','held','q','active','worker-a','{}','{}','{}','{}','{}')", at(600), at(1800), at(-5), at(-60), at(-5)),
        format!("INSERT INTO workflow_worker_sessions(namespace,session_id,queue,status,closed_at,created_at,updated_at) VALUES ('default','done','q','closed','{}','{}','{}')", at(-30), at(-120), at(-30)),
    ];
    for statement in &statements {
        mutate(&path, Box::leak(statement.clone().into_boxed_str())).await;
    }
    let fingerprint = backup_fingerprint(&path).await;
    let report = takeover::take_over_sqlite(&path, &fingerprint, &queue_config()).await.unwrap();
    assert_eq!(report["status"], "taken_over", "{report}");
    assert_eq!(report["converted"]["session_holders_recorded"], 1, "{report}");
    let runtime = crate::runtime::Runtime::open(&path, "test-token".into()).await.unwrap();
    let held = runtime.show_worker_session("held").await.unwrap();
    assert_eq!((held["session"]["status"].clone(), held["session"]["failure_reason"].clone()), (json!("active"), Value::Null), "{held}");
    let done = runtime.show_worker_session("done").await.unwrap();
    assert_eq!(done["session"]["status"], "closed", "{done}");
    runtime.close().await;
}

/// PHP's service calls carry over: native reads PHP's rows as they are, and
/// takeover copies each call's deadline into the relation native keeps it in,
/// so native shows it and times the call out when it is due.
#[tokio::test]
async fn takeover_keeps_php_service_calls_and_their_deadlines() {
    let (_directory, path) = php_release_layout(&[PHP_280_CHANGES, TRACE_CARRIERS, QUEUE_DELIVERY]).await;
    for statement in [
        "INSERT INTO workflow_service_calls(id,namespace,endpoint_name,service_name,operation_name,caller_namespace,target_namespace,status,outcome,operation_mode,accepted_at,call_deadline_at,created_at,updated_at) VALUES ('01m5wvy8d1j3dtr13es05kcall','default','pay','billing','charge','default','default','accepted','accepted','async','2026-10-26 10:00:00.000000','2001-02-03 04:05:06.500000','2026-10-26 10:00:00','2026-10-26 10:00:00')",
        "INSERT INTO workflow_service_calls(id,namespace,endpoint_name,service_name,operation_name,caller_namespace,target_namespace,status,outcome,operation_mode,accepted_at,created_at,updated_at) VALUES ('01m5wvy8d1j3dtr13es05kopen','default','pay','billing','charge','default','default','accepted','accepted','async','2026-10-26 10:00:01.000000','2026-10-26 10:00:01','2026-10-26 10:00:01')",
    ] {
        mutate(&path, statement).await;
    }
    let fingerprint = backup_fingerprint(&path).await;
    let report = takeover::take_over_sqlite(&path, &fingerprint, &queue_config()).await.unwrap();
    assert_eq!(report["status"], "taken_over", "{report}");
    assert_eq!(report["converted"]["service_call_deadlines_copied"], 1, "{report}");
    let runtime = crate::runtime::Runtime::open(&path, "test-token".into()).await.unwrap();
    let call = runtime.service_catalog_show(&[], crate::runtime::service_catalog::Catalog::Calls, "01m5wvy8d1j3dtr13es05kcall").await.unwrap();
    assert_eq!((call["status"].clone(), call["call_deadline_at"].clone()), (json!("accepted"), json!("2001-02-03T04:05:06.500000Z")), "{call}");
    // The deadline has passed, so the repair pass times the call out.
    let report = runtime.repair_pass(&Default::default()).await.unwrap();
    assert_eq!(report["service_call_timeouts_enforced"], 1, "{report}");
    let call = runtime.service_catalog_show(&[], crate::runtime::service_catalog::Catalog::Calls, "01m5wvy8d1j3dtr13es05kcall").await.unwrap();
    assert_eq!((call["status"].clone(), call["outcome"].clone()), (json!("failed"), json!("timed_out")), "{call}");
    let open = runtime.service_catalog_show(&[], crate::runtime::service_catalog::Catalog::Calls, "01m5wvy8d1j3dtr13es05kopen").await.unwrap();
    assert_eq!((open["status"].clone(), open["call_deadline_at"].clone()), (json!("accepted"), Value::Null), "{open}");
    runtime.close().await;
}

/// Message streams and durable workflow streams carry over: native serves
/// them from PHP's own tables. A closed durable stream gets the reclaim
/// deadline native's retention pass reads, its `closed_at` plus its
/// retention, as PHP 2.11.3's migration backfills it; an open one gets none.
#[tokio::test]
async fn takeover_keeps_php_streams_and_records_their_reclaim_deadlines() {
    let (_directory, path) = php_release_layout(&[PHP_280_CHANGES, TRACE_CARRIERS, QUEUE_DELIVERY]).await;
    for statement in [
        "INSERT INTO workflow_durable_streams(id,namespace,workflow_instance_id,workflow_run_id,stream_name,status,last_offset,total_items,retention_seconds,closed_at) VALUES (7,'default','i','01jrun0000000000000000000a','progress','closed',0,1,600,'2026-10-26 10:00:00')",
        "INSERT INTO workflow_durable_streams(id,namespace,workflow_instance_id,workflow_run_id,stream_name,status) VALUES (8,'default','i','01jrun0000000000000000000a','log','open')",
        "INSERT INTO workflow_durable_stream_items(stream_id,namespace,workflow_run_id,stream_name,\"offset\",payload,emitted_at) VALUES (7,'default','01jrun0000000000000000000a','progress',0,'{\"step\":1}','2026-10-26 09:59:00')",
        "INSERT INTO workflow_inbound_streams(id,namespace,workflow_instance_id,stream_name,last_position) VALUES (3,'default','i','orders',1)",
        "INSERT INTO workflow_inbound_stream_items(stream_id,namespace,workflow_instance_id,stream_name,message_id,position,payload_codec,payload_blob,payload_hash) VALUES (3,'default','i','orders','m1',1,'avro','AA==','h')",
    ] {
        mutate(&path, statement).await;
    }
    let inspection = inspect_sqlite(&path).await.unwrap();
    assert_eq!(inspection["stream_reference_inspection"], json!({"references": 0}), "{inspection}");
    let fingerprint = backup_fingerprint(&path).await;
    let report = takeover::take_over_sqlite(&path, &fingerprint, &queue_config()).await.unwrap();
    assert_eq!(report["status"], "taken_over", "{report}");
    assert_eq!(report["converted"]["stream_reclaims_recorded"], 1, "{report}");
    let mut connection = SqliteConnection::connect(&format!("sqlite://{path}")).await.unwrap();
    let reclaims: Vec<(i64, String)> = sqlx::query_as("SELECT stream_id,reclaim_at FROM dw_stream_reclaims")
        .fetch_all(&mut connection).await.unwrap();
    assert_eq!(reclaims.len(), 1);
    assert_eq!(reclaims[0].0, 7);
    assert!(reclaims[0].1.starts_with("2026-10-26 10:10:00"), "{reclaims:?}");
    let kept: i64 = sqlx::query_scalar("SELECT (SELECT COUNT(*) FROM workflow_durable_streams)+(SELECT COUNT(*) FROM workflow_durable_stream_items)+(SELECT COUNT(*) FROM workflow_inbound_streams)+(SELECT COUNT(*) FROM workflow_inbound_stream_items)")
        .fetch_one(&mut connection).await.unwrap();
    assert_eq!(kept, 5, "every stream row carries over");
    connection.close().await.unwrap();
    let runtime = crate::runtime::Runtime::open(&path, "test-token".into()).await.unwrap();
    runtime.close().await;
}

/// PHP's update validation tasks carry over unchanged: native validates
/// updates in the same `workflow_update_validation_tasks` rows, with no state
/// of its own. A pending task whose caller was waiting on PHP is then what a
/// PHP restart leaves: a validator may still claim it, and a caller's retry
/// with the same request reads its recorded outcome.
#[tokio::test]
async fn takeover_keeps_php_update_validation_tasks() {
    let (_directory, path) = php_release_layout(&[PHP_280_CHANGES, TRACE_CARRIERS, QUEUE_DELIVERY]).await;
    let row = |id: &str, status: &str, decided: &str| format!(
        "INSERT INTO workflow_update_validation_tasks(id,idempotency_key,namespace,workflow_instance_id,workflow_run_id,workflow_type,task_queue,update_name,request_id,input_hash,payload_codec,arguments,status,attempt_count,approved_at,created_at,updated_at) VALUES ('{id}','key-{id}','default','order-1','01jrun0000000000000000000a','orders','q','approve','req-{id}','{}','avro','[]','{status}',{},{decided},'2026-10-26 10:00:00','2026-10-26 10:00:00')",
        "0".repeat(64), if status == "pending" { 0 } else { 1 });
    for statement in [row("01m5wvy8d1j3dtr13es05kval1", "pending", "NULL"), row("01m5wvy8d1j3dtr13es05kval2", "approved", "'2026-10-26 10:00:01'")] {
        mutate(&path, Box::leak(statement.into_boxed_str())).await;
    }
    let read = |path: String| async move {
        let mut connection = SqliteConnection::connect(&format!("sqlite://{path}")).await.unwrap();
        let rows: Vec<(String, String, i64, Option<String>)> = sqlx::query_as("SELECT id,status,attempt_count,approved_at FROM workflow_update_validation_tasks ORDER BY id")
            .fetch_all(&mut connection).await.unwrap();
        connection.close().await.unwrap();
        rows
    };
    let before = read(path.clone()).await;
    let fingerprint = backup_fingerprint(&path).await;
    let report = takeover::take_over_sqlite(&path, &fingerprint, &queue_config()).await.unwrap();
    assert_eq!(report["status"], "taken_over", "{report}");
    assert_eq!(read(path.clone()).await, before, "the tasks carry over unchanged");
    let runtime = crate::runtime::Runtime::open(&path, "test-token".into()).await.unwrap();
    runtime.close().await;
}

/// PHP's runtime credentials carry over: native stores and checks the same
/// SHA-256 token hashes in the same table, so a worker's PHP-issued token
/// still authenticates after takeover, and a revoked one still does not.
#[tokio::test]
async fn takeover_keeps_php_runtime_credentials_that_native_then_accepts() {
    let (_directory, path) = php_release_layout(&[PHP_280_CHANGES, TRACE_CARRIERS, QUEUE_DELIVERY]).await;
    for statement in crate::runtime::runtime_credentials::PHP_CREDENTIAL_ROWS {
        mutate(&path, statement).await;
    }
    let fingerprint = backup_fingerprint(&path).await;
    let report = takeover::take_over_sqlite(&path, &fingerprint, &queue_config()).await.unwrap();
    assert_eq!(report["status"], "taken_over", "{report}");
    let runtime = crate::runtime::Runtime::open(&path, "test-token".into()).await.unwrap();
    crate::runtime::runtime_credentials::assert_php_credentials_served(&runtime).await;
    runtime.close().await;
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

#[tokio::test]
async fn inspection_counts_namespace_storage_native_cannot_serve_without_naming_it() {
    let (_directory, path) = php297_layout().await;
    for statement in [
        "INSERT INTO workflow_namespaces(name,status,external_payload_storage) VALUES ('private-payments','active','{\"driver\":\"s3\",\"config\":{\"bucket\":\"private-bucket\"}}')",
        "INSERT INTO workflow_namespaces(name,status,external_payload_storage) VALUES ('archive','active','{\"driver\":\"gcs\",\"enabled\":false}')",
        "INSERT INTO workflow_namespaces(name,status,external_payload_storage) VALUES ('uploads','active','{\"driver\":\"local\",\"threshold_bytes\":16}')",
        "INSERT INTO workflow_namespaces(name,status) VALUES ('plain','active')",
    ] {
        mutate(&path, statement).await;
    }
    let before = std::fs::read(&path).unwrap();
    let inspected = inspect_sqlite(&path).await.unwrap();
    assert_eq!(inspected["status"], "unqualified");
    // Only the enabled S3 policy: a disabled policy and the local driver
    // serve the same uploads under native as under PHP.
    assert_eq!(
        inspected["storage_inspection"],
        serde_json::json!({"namespaces":inspected["table_rows"]["workflow_namespaces"],"unsupported":1,
            "reasons":{"external_payload_storage_driver_unsupported":1}})
    );
    let text = inspected.to_string();
    assert!(!text.contains("private-payments") && !text.contains("private-bucket"));
    assert_eq!(std::fs::read(&path).unwrap(), before);
}

#[tokio::test]
async fn inspection_counts_unrunnable_schedules_by_reason_without_naming_them() {
    let (_directory, path) = php297_layout().await;
    for statement in [
        "INSERT INTO workflow_schedules(id,schedule_id,namespace,spec,action,status,overlap_policy,jitter_seconds) VALUES ('s1','nightly-billing','default','{\"cron_expressions\":[\"0 2 * * *\"],\"timezone\":\"UTC\"}','{\"workflow_type\":\"echo\",\"task_queue\":\"default\",\"input\":{\"codec\":\"avro\",\"blob\":\"AA==\"},\"execution_timeout_seconds\":3600,\"run_timeout_seconds\":600}','active','skip',0)",
        "INSERT INTO workflow_schedules(id,schedule_id,namespace,spec,action,status,overlap_policy,jitter_seconds,memo) VALUES ('s2','tenant-report','default','{}','{}','paused','skip',0,'{\"tenant\":\"private-tenant\"}')",
        "INSERT INTO workflow_schedules(id,schedule_id,namespace,spec,action,status,overlap_policy,jitter_seconds) VALUES ('s3','old','default','{}','{}','deleted','skip',0)",
        "INSERT INTO workflow_schedules(id,schedule_id,namespace,spec,action,status,overlap_policy,jitter_seconds,search_attributes) VALUES ('s4','tagged','billing','{\"intervals\":[{\"every\":\"P1D\"}]}','{\"workflow_type\":\"echo\"}','active','skip',0,'{\"Customer\":\"private-customer\"}')",
    ] {
        mutate(&path, statement).await;
    }
    let before = std::fs::read(&path).unwrap();
    let inspected = inspect_sqlite(&path).await.unwrap();
    assert_eq!(inspected["status"], "unqualified");
    assert_eq!(
        inspected["schedule_inspection"],
        // The cron schedule runs natively; an empty definition and search
        // attribute values do not.
        serde_json::json!({"schedules":3,"unsupported":2,
            "reasons":{"schedule_definition_unsupported":1,"schedule_metadata_unsupported":1}})
    );
    let text = inspected.to_string();
    assert!(!text.contains("nightly-billing") && !text.contains("private-tenant") && !text.contains("private-customer"));
    assert_eq!(std::fs::read(&path).unwrap(), before);
}
