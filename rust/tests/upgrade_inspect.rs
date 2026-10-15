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
