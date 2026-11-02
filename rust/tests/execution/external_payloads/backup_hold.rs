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
    database.execute("UPDATE runtime_external_payload_backup_hold SET acquired_at='2000-01-01 00:00:00',expires_at='2999-01-01 00:00:00'").await;
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
