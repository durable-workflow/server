//! Exercise process exit as well as refusal: a cancelled filesystem open can
//! otherwise keep Tokio's blocking pool alive after an async timeout.
#![cfg(unix)]

use std::{
    ffi::CString,
    os::unix::ffi::OsStrExt,
    process::{Command, Stdio},
    time::{Duration, Instant},
};

#[test]
fn special_sqlite_sources_refuse_without_waiting_for_a_writer() {
    let directory = tempfile::tempdir().unwrap();
    let fifo = directory.path().join("source.sqlite");
    let name = CString::new(fifo.as_os_str().as_bytes()).unwrap();
    // The path belongs to this test's new private temporary directory.
    assert_eq!(unsafe { libc::mkfifo(name.as_ptr(), 0o600) }, 0);

    for (source, command) in [
        (fifo.as_path(), "upgrade-inspect"),
        (directory.path(), "upgrade-inspect"),
        (fifo.as_path(), "upgrade-queue-inspect"),
        (directory.path(), "upgrade-queue-inspect"),
        (fifo.as_path(), "upgrade-payload-inspect"),
        (directory.path(), "upgrade-payload-inspect"),
    ] {
        let mut child = Command::new(env!("CARGO_BIN_EXE_durable-workflow-server"))
            .env_clear()
            .env("DW_RUST_EXPERIMENTAL", "1")
            .env("DB_CONNECTION", "sqlite")
            .env("DB_DATABASE", source)
            .env("DW_RUST_UPGRADE_QUEUE_CONNECTION", "database")
            .env("DW_RUST_UPGRADE_QUEUE_DEFAULT_CONNECTION", "database")
            .env("DW_RUST_UPGRADE_QUEUE_DEFAULT_QUEUE", "default")
            .arg(command)
            .stdout(Stdio::piped())
            .stderr(Stdio::piped())
            .spawn()
            .unwrap();
        let deadline = Instant::now() + Duration::from_secs(3);
        while child.try_wait().unwrap().is_none() {
            if Instant::now() >= deadline {
                child.kill().unwrap();
                child.wait().unwrap();
                panic!("source refusal did not retire the CLI process");
            }
            std::thread::sleep(Duration::from_millis(10));
        }
        let output = child.wait_with_output().unwrap();
        assert!(!output.status.success());
        assert!(output.stdout.is_empty());
        assert!(
            String::from_utf8(output.stderr)
                .unwrap()
                .contains("upgrade_source_regular_file_required")
        );
    }
    assert_eq!(std::fs::read_dir(directory.path()).unwrap().count(), 1);
}

#[tokio::test]
async fn payload_cli_bounds_allocation_and_preserves_valid_and_malformed_original_bytes() {
    use base64::{Engine, engine::general_purpose::STANDARD};
    use durable_workflow_server::codec::{Value, ValueCodec};
    use sqlx::{
        Connection, Executor, SqliteConnection,
        sqlite::{SqliteConnectOptions, SqliteJournalMode},
    };
    let directory = tempfile::tempdir().unwrap();
    let path = directory.path().join("source.sqlite");
    let mut connection = SqliteConnection::connect_with(
        &SqliteConnectOptions::new()
            .filename(&path)
            .create_if_missing(true)
            .foreign_keys(false)
            .journal_mode(SqliteJournalMode::Delete),
    )
    .await
    .unwrap();
    sqlx::raw_sql(concat!(
        include_str!("../migrations/sqlite/0003_full_schema.sql"),
        "\n",
        include_str!("../migrations/sqlite/0004_worker_incarnations.sql")
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
        sqlx::query(sqlx::AssertSqlSafe(format!("DROP TABLE {table}")))
            .execute(&mut connection)
            .await
            .unwrap();
    }
    connection
        .execute("DROP INDEX dw_workflow_tasks_poll")
        .await
        .unwrap();
    sqlx::query("INSERT INTO workflow_runs(id,workflow_instance_id,run_number,workflow_class,workflow_type,status,payload_codec) VALUES ('run','workflow',1,'SourceFixture','source.fixture','waiting','avro')")
        .execute(&mut connection).await.unwrap();
    let codec = ValueCodec::new().unwrap();
    let valid = codec
        .encode(&Value::Array(vec![
            Value::Long(9007199254740993),
            Value::Null,
            Value::String("λ".into()),
        ]))
        .unwrap();
    // Only the length is corrupt. Apache produces the length encoding and owns
    // parsing it in the child; no second production Avro implementation exists.
    let mut oversized = STANDARD
        .decode(codec.encode(&Value::String(String::new())).unwrap())
        .unwrap();
    assert_eq!(oversized.pop(), Some(0));
    oversized.extend(
        apache_avro::to_avro_datum(
            &apache_avro::Schema::Long,
            apache_avro::types::Value::Long(500 * 1024 * 1024),
        )
        .unwrap(),
    );
    connection.close().await.unwrap();
    for (blob, expected) in [
        (valid, "inspected"),
        (STANDARD.encode(oversized), "unqualified"),
    ] {
        let mut connection =
            SqliteConnection::connect_with(&SqliteConnectOptions::new().filename(&path))
                .await
                .unwrap();
        sqlx::query("UPDATE workflow_runs SET arguments=? WHERE id='run'")
            .bind(blob)
            .execute(&mut connection)
            .await
            .unwrap();
        connection.close().await.unwrap();
        let before = std::fs::read(&path).unwrap();
        let mut child = Command::new(env!("CARGO_BIN_EXE_durable-workflow-server"))
            .env_clear()
            .env("DW_RUST_EXPERIMENTAL", "1")
            .env("DB_CONNECTION", "sqlite")
            .env("DB_DATABASE", &path)
            .arg("upgrade-payload-inspect")
            .stdout(Stdio::piped())
            .stderr(Stdio::piped())
            .spawn()
            .unwrap();
        let deadline = Instant::now() + Duration::from_secs(3);
        while child.try_wait().unwrap().is_none() {
            if Instant::now() >= deadline {
                child.kill().unwrap();
                child.wait().unwrap();
                panic!("payload inspection did not retire its process");
            }
            std::thread::sleep(Duration::from_millis(10));
        }
        let output = child.wait_with_output().unwrap();
        assert!(
            output.status.success(),
            "{}",
            String::from_utf8_lossy(&output.stderr)
        );
        let report: serde_json::Value = serde_json::from_slice(&output.stdout).unwrap();
        assert_eq!(report["status"], expected);
        assert_eq!(
            report["payload_inspection"]["inline_validation_limits"]["scalar_allocation_bytes"],
            65536
        );
        assert_eq!(report["takeover_allowed"], false);
        assert_eq!(
            report["stored_representation_compatibility_verified"],
            false
        );
        if expected == "unqualified" {
            assert_eq!(
                report["payload_inspection"]["diagnostics"][0]["reason"],
                "stored_inline_frame_unqualified"
            );
        }
        assert_eq!(std::fs::read(&path).unwrap(), before);
    }
}
