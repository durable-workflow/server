//! The online-backup hold on payload reclamation (Server #949).
use super::*;
use sqlx::AssertSqlSafe;

const OWNER: &str = "7f1c3a1e-0000-4000-8000-0000000000a1";
const OTHER: &str = "7f1c3a1e-0000-4000-8000-0000000000b2";
const HOLD: &str = "/api/system/external-payload-backup-hold";

async fn change(router: &Router, action: &str, body: Value) -> (StatusCode, Value) {
    json_request(router, "POST", &format!("{HOLD}/{action}"), "admin-token", body).await
}

async fn status(router: &Router, query: &str) -> (StatusCode, Value) {
    json_request(router, "GET", &format!("{HOLD}{query}"), "admin-token", Value::Null).await
}

async fn upload(router: &Router, bytes: &[u8]) -> Value {
    let (status, _, body) = request(router, "POST", "/api/external-payloads/v1", "worker-token", "default",
        Some(&metadata(bytes)), Body::from(bytes.to_vec())).await;
    assert_eq!(status, StatusCode::CREATED, "{}", String::from_utf8_lossy(&body));
    serde_json::from_slice::<Value>(&body).unwrap()["reference"].clone()
}

fn object(root: &std::path::Path, reference: &Value) -> std::path::PathBuf {
    let sha = reference["sha256"].as_str().unwrap();
    root.join("avro").join(&sha[..2]).join(sha)
}

async fn cleanup(router: &Router) -> (StatusCode, Value) {
    json_request(router, "POST", "/api/system/external-payload-cleanup/pass", "admin-token", json!({})).await
}

/// Seconds between two of the report's `Y-m-d H:i:s` times.
fn seconds(from: &Value, to: &Value) -> i64 {
    let parse = |value: &Value| chrono::NaiveDateTime::parse_from_str(value.as_str().unwrap(), "%Y-%m-%d %H:%M:%S").unwrap();
    (parse(to) - parse(from)).num_seconds()
}

/// From another connection, take the hold row's lock as a reclamation does
/// while it deletes an object, and keep it until the returned sender fires.
async fn lock_hold_row(database: &TestDatabase) -> tokio::sync::oneshot::Sender<()> {
    const LOCK: &str = "UPDATE runtime_external_payload_backup_hold SET id=id WHERE id=1";
    let (release, released) = tokio::sync::oneshot::channel::<()>();
    let (locked, is_locked) = tokio::sync::oneshot::channel::<()>();
    macro_rules! hold {
        ($pool:expr) => {{
            let pool = $pool;
            tokio::spawn(async move {
                let mut tx = pool.begin().await.unwrap();
                assert_eq!(sqlx::query(LOCK).execute(&mut *tx).await.unwrap().rows_affected(), 1);
                locked.send(()).unwrap();
                let _ = released.await;
                tx.commit().await.unwrap();
                pool.close().await;
            });
        }};
    }
    match database {
        TestDatabase::Sqlite(dir) => hold!(sqlx::SqlitePool::connect(&format!("sqlite://{}", dir.path().join("runtime.sqlite").display())).await.unwrap()),
        TestDatabase::Postgres { options, .. } => hold!(sqlx::postgres::PgPoolOptions::new().connect_with(options.as_ref().clone()).await.unwrap()),
        TestDatabase::MySql { options, .. } => hold!(sqlx::mysql::MySqlPoolOptions::new().connect_with(options.as_ref().clone()).await.unwrap()),
    }
    is_locked.await.unwrap();
    release
}

#[tokio::test]
async fn a_backup_acquires_renews_and_releases_the_hold_and_reclamation_waits_for_it() {
    let temp = tempfile::tempdir().unwrap();
    let root = temp.path().join("objects");
    let (database, _runtime, router) = setup(ExternalPayloadConfig::default(), &root).await;

    // Native schemas start without the hold row: no hold.
    let (code, body) = status(&router, "").await;
    assert_eq!(code, StatusCode::OK, "{body}");
    assert_eq!(body["schema"], "durable-workflow.external-payload-backup-hold.v1");
    assert_eq!([&body["active"], &body["owner"], &body["expires_at"]], [&json!(false), &Value::Null, &Value::Null]);

    let (code, held) = change(&router, "acquire", json!({"owner": OWNER, "ttl": 600})).await;
    assert_eq!(code, StatusCode::OK, "{held}");
    assert_eq!([&held["active"], &held["owner"]], [&json!(true), &json!(OWNER)]);
    assert_eq!(seconds(&held["acquired_at"], &held["expires_at"]), 600);

    // Another backup can't take an active hold, or act on it.
    let (code, body) = change(&router, "acquire", json!({"owner": OTHER})).await;
    assert_eq!((code, &body["reason"]), (StatusCode::CONFLICT, &json!("backup_hold_owned_elsewhere")), "{body}");
    for action in ["renew", "release"] {
        let (code, body) = change(&router, action, json!({"owner": OTHER})).await;
        assert_eq!((code, &body["reason"]), (StatusCode::CONFLICT, &json!("backup_hold_not_owned")), "{action} {body}");
    }
    let (code, body) = status(&router, &format!("?owner={OTHER}")).await;
    assert_eq!((code, &body["reason"]), (StatusCode::CONFLICT, &json!("backup_hold_not_owned")), "{body}");

    // While the hold is active an expired object is kept.
    let reference = upload(&router, b"held object").await;
    database.execute(AssertSqlSafe(format!("UPDATE runtime_external_payloads SET expires_at='2000-01-01 00:00:00' WHERE id='{}'",
        reference["reference_id"].as_str().unwrap()))).await;
    let (code, body) = cleanup(&router).await;
    assert_eq!(code, StatusCode::MULTI_STATUS, "{body}");
    assert_eq!(body["cleanup"]["blocked"], 1);
    assert!(object(&root, &reference).exists());

    // Renewing from the default TTL moves the expiry; the owner's status holds.
    let (code, renewed) = change(&router, "renew", json!({"owner": OWNER})).await;
    assert_eq!(code, StatusCode::OK, "{renewed}");
    assert_eq!(renewed["acquired_at"], held["acquired_at"]);
    assert_eq!(seconds(&renewed["database_time"], &renewed["expires_at"]), 900);
    let (code, body) = status(&router, &format!("?owner={OWNER}")).await;
    assert_eq!((code, &body["active"]), (StatusCode::OK, &json!(true)), "{body}");

    // Release ends the hold and keeps the owner; reclamation resumes.
    let (code, released) = change(&router, "release", json!({"owner": OWNER})).await;
    assert_eq!(code, StatusCode::OK, "{released}");
    assert_eq!([&released["active"], &released["owner"], &released["expires_at"]], [&json!(false), &json!(OWNER), &Value::Null]);
    let (code, body) = cleanup(&router).await;
    assert_eq!(code, StatusCode::OK, "{body}");
    assert!(!object(&root, &reference).exists());

    // An owner is single-use; the next backup takes the hold with a new one.
    for action in ["acquire", "renew"] {
        let (code, body) = change(&router, action, json!({"owner": OWNER})).await;
        assert_eq!((code, &body["reason"]), (StatusCode::CONFLICT, &json!("backup_hold_not_owned")), "{action} {body}");
    }
    let (code, body) = change(&router, "acquire", json!({"owner": OTHER, "ttl": "60"})).await;
    assert_eq!((code, &body["owner"]), (StatusCode::OK, &json!(OTHER)), "{body}");
}

#[tokio::test]
async fn a_hold_expires_by_the_database_clock_and_never_outlives_a_day() {
    let temp = tempfile::tempdir().unwrap();
    let (database, _runtime, router) = setup(ExternalPayloadConfig::default(), &temp.path().join("objects")).await;
    let (code, body) = change(&router, "acquire", json!({"owner": OWNER, "ttl": 3600})).await;
    assert_eq!(code, StatusCode::OK, "{body}");

    // Acquired nearly a day ago: a renewal stops at the day's end.
    database.execute("UPDATE runtime_external_payload_backup_hold SET acquired_at='2000-01-01 00:00:00',expires_at='2037-01-01 00:00:00'").await;
    let (code, body) = change(&router, "renew", json!({"owner": OWNER, "ttl": 3600})).await;
    assert_eq!(code, StatusCode::OK, "{body}");
    assert_eq!(body["expires_at"], "2000-01-02 00:00:00");
    assert_eq!(body["active"], false);

    // That hold has lapsed, so another backup may take it.
    let (code, body) = change(&router, "acquire", json!({"owner": OTHER})).await;
    assert_eq!((code, &body["owner"]), (StatusCode::OK, &json!(OTHER)), "{body}");
}

#[tokio::test]
async fn acquire_waits_for_a_deletion_holding_the_hold_row() {
    let temp = tempfile::tempdir().unwrap();
    let (database, _runtime, router) = setup(ExternalPayloadConfig::default(), &temp.path().join("objects")).await;
    let (code, body) = status(&router, "").await;
    assert_eq!(code, StatusCode::OK, "{body}");

    let release = lock_hold_row(&database).await;
    let pending = tokio::spawn({
        let router = router.clone();
        async move { change(&router, "acquire", json!({"owner": OWNER})).await }
    });
    tokio::time::sleep(Duration::from_millis(500)).await;
    assert!(!pending.is_finished(), "acquire must wait for the deletion's lock");
    release.send(()).unwrap();
    let (code, body) = pending.await.unwrap();
    assert_eq!((code, &body["active"]), (StatusCode::OK, &json!(true)), "{body}");
}

#[tokio::test]
async fn hold_requests_need_an_admin_a_uuid_owner_and_a_bounded_ttl() {
    let temp = tempfile::tempdir().unwrap();
    let (_database, _runtime, router) = setup(ExternalPayloadConfig::default(), &temp.path().join("objects")).await;
    for token in ["operator-token", "worker-token"] {
        let (code, body) = json_request(&router, "POST", &format!("{HOLD}/acquire"), token, json!({"owner": OWNER})).await;
        assert!(matches!(code, StatusCode::FORBIDDEN | StatusCode::UNAUTHORIZED), "{token} {code} {body}");
        let (code, body) = json_request(&router, "GET", HOLD, token, Value::Null).await;
        assert!(matches!(code, StatusCode::FORBIDDEN | StatusCode::UNAUTHORIZED), "{token} {code} {body}");
    }
    for (body, field, message) in [
        (json!({}), "owner", "The owner field is required."),
        (json!({"owner": "backup-1"}), "owner", "The owner field must be a valid UUID."),
        (json!({"owner": OWNER, "ttl": 0}), "ttl", "The ttl field must be at least 1."),
        (json!({"owner": OWNER, "ttl": 3601}), "ttl", "The ttl field must not be greater than 3600."),
        (json!({"owner": OWNER, "ttl": 1.5}), "ttl", "The ttl field must be an integer."),
    ] {
        let (code, answer) = change(&router, "acquire", body.clone()).await;
        assert_eq!(code, StatusCode::UNPROCESSABLE_ENTITY, "{body} {answer}");
        assert_eq!(answer["errors"][field], json!([message]), "{body}");
    }
    let (code, body) = status(&router, "?owner=backup-1").await;
    assert_eq!(code, StatusCode::UNPROCESSABLE_ENTITY, "{body}");
    // Nothing was acquired.
    let (code, body) = status(&router, "").await;
    assert_eq!((code, &body["owner"]), (StatusCode::OK, &Value::Null), "{body}");
}

#[tokio::test]
async fn a_restored_lapsed_hold_is_released_by_its_recorded_owner() {
    let temp = tempfile::tempdir().unwrap();
    let (database, _runtime, router) = setup(ExternalPayloadConfig::default(), &temp.path().join("objects")).await;
    let (code, body) = change(&router, "acquire", json!({"owner": OWNER, "ttl": 3600})).await;
    assert_eq!(code, StatusCode::OK, "{body}");
    // A restored snapshot carries the capturing backup's hold, long expired.
    database.execute("UPDATE runtime_external_payload_backup_hold SET acquired_at='2000-01-01 00:00:00',expires_at='2000-01-01 01:00:00'").await;

    let (code, body) = status(&router, "").await;
    assert_eq!(code, StatusCode::OK, "{body}");
    assert_eq!([&body["active"], &body["owner"]], [&json!(false), &json!(OWNER)]);

    // Its recorded owner releases it, and releasing again is idempotent.
    for _ in 0..2 {
        let (code, body) = change(&router, "release", json!({"owner": OWNER})).await;
        assert_eq!(code, StatusCode::OK, "{body}");
        assert_eq!(body["schema"], "durable-workflow.external-payload-backup-hold.v1");
        assert_eq!([&body["active"], &body["owner"], &body["expires_at"]], [&json!(false), &json!(OWNER), &Value::Null]);
    }
    // Another owner still can't release it.
    let (code, body) = change(&router, "release", json!({"owner": OTHER})).await;
    assert_eq!((code, &body["reason"]), (StatusCode::CONFLICT, &json!("backup_hold_not_owned")), "{body}");
}

/// The offline `backup-hold` command (Server #973), run as its own process
/// with only the DB settings: (succeeded, printed report, first stderr
/// line: the refusal reason; main's own error line follows it).
async fn console(database: &TestDatabase, args: &[&str]) -> (bool, Value, String) {
    let mut command = std::process::Command::new(env!("CARGO_BIN_EXE_durable-workflow-server"));
    command.env_clear().env("DW_RUST_EXPERIMENTAL", "1").arg("backup-hold").args(args);
    match database {
        TestDatabase::Sqlite(dir) => command.env("DB_CONNECTION", "sqlite").env("DB_DATABASE", dir.path().join("runtime.sqlite")),
        TestDatabase::Postgres { url, .. } => command.env("DB_CONNECTION", "pgsql").env("DB_URL", url),
        TestDatabase::MySql { url, .. } => command.env("DB_CONNECTION", "mysql").env("DB_URL", url),
    };
    // Waiting on the child blocks, so it runs on the blocking pool.
    let output = tokio::task::spawn_blocking(move || command.output().unwrap()).await.unwrap();
    let stdout = String::from_utf8_lossy(&output.stdout);
    (output.status.success(), serde_json::from_str(stdout.trim()).unwrap_or(Value::Null), first_line(&output.stderr))
}

fn first_line(stderr: &[u8]) -> String {
    String::from_utf8_lossy(stderr).lines().next().unwrap_or_default().to_owned()
}

#[tokio::test]
async fn the_offline_command_releases_a_restored_hold_before_any_server_starts() {
    let temp = tempfile::tempdir().unwrap();
    let (database, runtime, router) = setup(ExternalPayloadConfig::default(), &temp.path().join("objects")).await;
    let (code, body) = change(&router, "acquire", json!({"owner": OWNER, "ttl": 3600})).await;
    assert_eq!(code, StatusCode::OK, "{body}");
    database.execute("UPDATE runtime_external_payload_backup_hold SET acquired_at='2000-01-01 00:00:00',expires_at='2000-01-01 01:00:00'").await;
    // The cell is offline: no Server process holds the database.
    drop(router);
    runtime.close().await;

    let (ok, body, stderr) = console(&database, &["status"]).await;
    assert!(ok, "{stderr}");
    assert_eq!(body["schema"], "durable-workflow.external-payload-backup-hold.v1");
    assert_eq!([&body["active"], &body["owner"]], [&json!(false), &json!(OWNER)]);
    // A scoped status is refused once its owner no longer holds the hold.
    assert_eq!(console(&database, &["status", "--owner", OWNER]).await.2, "backup_hold_not_owned");

    // Only the recorded owner releases it, in either option form, and a
    // second release reports the same released hold.
    assert_eq!(console(&database, &["release", "--owner", OTHER]).await.2, "backup_hold_not_owned");
    for args in [["release", "--owner", OWNER].as_slice(), [&*format!("--owner={OWNER}")].as_slice()] {
        let args: Vec<&str> = if args.len() == 1 { vec!["release", args[0]] } else { args.to_vec() };
        let (ok, body, stderr) = console(&database, &args).await;
        assert!(ok, "{stderr}");
        assert_eq!([&body["active"], &body["owner"], &body["acquired_at"], &body["expires_at"]],
            [&json!(false), &json!(OWNER), &json!("2000-01-01 00:00:00"), &Value::Null]);
    }

    // PHP's validation, before the database is touched.
    for (args, refusal) in [
        (vec!["release"], "The owner field is required."),
        (vec!["release", "--owner", "not-a-uuid"], "The owner field must be a valid UUID."),
        (vec!["acquire", "--owner", OTHER, "--ttl", "3601"], "The ttl field must not be greater than 3600."),
        (vec!["status", "--ttl", "soon"], "backup_hold_ttl_invalid"),
        (vec!["inspect"], "backup_hold_action_invalid"),
        (vec![], "backup_hold_action_invalid"),
        (vec!["release", "--owner", OWNER, "--owner", OTHER], "backup_hold_command_invalid"),
        (vec!["release", "--owner"], "backup_hold_command_invalid"),
        (vec!["release", "--force"], "backup_hold_command_invalid"),
    ] {
        let (ok, body, stderr) = console(&database, &args).await;
        assert!(!ok && body.is_null(), "{args:?} succeeded: {body}");
        assert_eq!(stderr, refusal, "{args:?}");
    }

    // The released hold goes to the next backup, which renews and releases
    // it; the database's clock decides every expiry.
    let (ok, body, stderr) = console(&database, &["acquire", "--owner", OTHER, "--ttl", "60"]).await;
    assert!(ok, "{stderr}");
    assert_eq!([&body["active"], &body["owner"]], [&json!(true), &json!(OTHER)]);
    assert_eq!(seconds(&body["acquired_at"], &body["expires_at"]), 60);
    let (ok, body, stderr) = console(&database, &["renew", "--owner", OTHER, "--ttl=120"]).await;
    assert!(ok, "{stderr}");
    assert_eq!(seconds(&body["database_time"], &body["expires_at"]), 120);
    assert!(console(&database, &["status", "--owner", OTHER]).await.0);
    assert!(console(&database, &["release", "--owner", OTHER]).await.0);
    let (ok, body, _) = console(&database, &["status"]).await;
    assert!(ok);
    assert_eq!([&body["active"], &body["owner"]], [&json!(false), &json!(OTHER)]);
}

#[tokio::test]
async fn the_offline_command_never_initializes_a_database() {
    // A database with no native schema is refused, and left without one.
    let fresh = TestDatabase::new().await;
    for args in [["status"].as_slice(), &["release", "--owner", OWNER], &["acquire", "--owner", OWNER]] {
        let (ok, _, stderr) = console(&fresh, args).await;
        assert!(!ok);
        assert_eq!(stderr, "native_schema_not_initialized", "{args:?}");
    }
    match &fresh {
        TestDatabase::Sqlite(dir) => assert!(!dir.path().join("runtime.sqlite").exists(), "the command created a database"),
        _ => assert_eq!(table_counts(&fresh).await, Vec::<(String, i64)>::new(), "the command created tables"),
    }

    // An existing SQLite file that is not a native database is refused
    // unchanged.
    let temp = tempfile::tempdir().unwrap();
    let empty = temp.path().join("empty.sqlite");
    std::fs::write(&empty, b"").unwrap();
    let mut command = std::process::Command::new(env!("CARGO_BIN_EXE_durable-workflow-server"));
    command.env_clear().env("DW_RUST_EXPERIMENTAL", "1").env("DB_CONNECTION", "sqlite").env("DB_DATABASE", &empty)
        .args(["backup-hold", "release", "--owner", OWNER]);
    let output = tokio::task::spawn_blocking(move || command.output().unwrap()).await.unwrap();
    assert!(!output.status.success());
    assert_eq!(first_line(&output.stderr), "native_schema_not_initialized");
    assert_eq!(std::fs::metadata(&empty).unwrap().len(), 0, "the command wrote to a non-native database");
}
