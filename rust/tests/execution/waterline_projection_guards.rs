use super::*;
use durable_workflow_server::runtime::StructuralLimits;

// A Waterline v1 projection is one standalone, read-only run. Native refuses
// a message stream append to it before reading the body (published 2.11.1
// accepts one and creates a signal and a workflow task), and refuses a
// projection larger than one transaction may write before reading or
// writing any row (published 2.11.1 has no limit).

const ROUTE: &str = "/api/workflows/import/waterline-v1";

/// The running source of the waterline-import fixture, with `logs` logs.
fn running(logs: usize) -> Value {
    json!({"id": "v1:4201", "legacy_id": "4201", "engine_source": "v1", "class": "App\\Workflows\\Legacy\\Subscription",
        "status": "running", "connection": "database", "queue": "legacy-billing", "arguments": "a:0:{}",
        "logs": (0..logs).map(|index| json!({"id": 9101 + index, "index": index, "now": "2026-09-30T09:00:01.000000Z",
            "class": "App\\Activities\\Bill", "result": "i:42;", "created_at": "2026-09-30T09:00:02.000000Z"})).collect::<Vec<_>>(),
        "signals": [], "exceptions": [], "created_at": "2026-09-30T09:00:00.000000Z"})
}

async fn import(app: &Router, source_id: &str, workflow: Value) -> (StatusCode, Value) {
    request(app, "POST", ROUTE, json!({"source_id": source_id, "workflow": workflow})).await
}

/// Every table's row count: a projection or a stream append may write no
/// other row than the ones each test expects.
async fn rows(database: &TestDatabase) -> Vec<(String, i64)> {
    let counts = table_counts(database).await;
    for table in ["workflow_links", "workflow_inbound_streams", "workflow_inbound_stream_items", "workflow_signal_records"] {
        assert!(counts.iter().any(|(name, _)| name == table), "{table} is not in {counts:?}");
    }
    counts
}

#[tokio::test]
async fn appends_to_a_projection_are_refused_before_their_body_is_read() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let (status, report) = import(&app, "guard-src", running(1)).await;
    assert_eq!(status, StatusCode::CREATED, "{report}");
    let workflow_id = report["identity"]["standalone"]["workflow_id"].as_str().unwrap().to_owned();
    let run_id = report["identity"]["standalone"]["run_id"].clone();
    let path = format!("/api/workflows/{}/message-streams/orders/messages", workflow_id.replace(':', "%3A"));
    let before = rows(&database).await;
    let (status, described) = request(&app, "GET", &format!("/api/workflows/{}", workflow_id.replace(':', "%3A")), Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{described}");

    for body in [json!({"message_id": "m-1", "input": ["x"]}), json!({"message_id": 7, "input": "not a list"}), json!("not an object")] {
        let (status, refusal) = request(&app, "POST", &path, body.clone()).await;
        assert_eq!(status, StatusCode::CONFLICT, "{body}: {refusal}");
        assert_eq!(refusal["reason"], "v1_projection_read_only", "{refusal}");
        assert_eq!((&refusal["operation"], &refusal["run_id"], &refusal["stream_name"]),
            (&json!("message_stream_append"), &run_id, &json!("orders")), "{refusal}");
        assert_eq!((&refusal["accepted"], &refusal["outcome"]), (&json!(false), &json!("rejected")), "{refusal}");
    }

    // No stream, item, signal, command, history, task or summary changed.
    assert_eq!(rows(&database).await, before);
    let (_, after) = request(&app, "GET", &format!("/api/workflows/{}", workflow_id.replace(':', "%3A")), Value::Null).await;
    assert_eq!(after, described);
}

#[tokio::test]
async fn a_projection_past_the_event_limit_is_refused_before_any_row() {
    let database = TestDatabase::new().await;
    let limits = StructuralLimits { history_events: Some(3), ..StructuralLimits::default() };
    let app = router(database.open().await.unwrap().with_structural_limits(limits));

    // The start event and two logs: exactly the limit.
    let (status, report) = import(&app, "events-a", running(2)).await;
    assert_eq!(status, StatusCode::CREATED, "{report}");
    let before = rows(&database).await;

    // One log more, and many more: refused, nothing written.
    for (source, logs, used) in [("events-b", 3, 4), ("events-c", 500, 501)] {
        let (status, refusal) = import(&app, source, running(logs)).await;
        assert_eq!(status, StatusCode::CONFLICT, "{refusal}");
        assert_eq!((&refusal["status"], &refusal["reason"]), (&json!("rejected"), &json!("v1_projection_too_large")), "{refusal}");
        assert_eq!(refusal["limit"], json!({"dimension": "history_transaction_size", "used": used, "limit": 3}), "{refusal}");
        assert_eq!(rows(&database).await, before);
    }
}

#[tokio::test]
async fn a_projection_past_the_byte_limit_is_refused_before_any_row() {
    let database = TestDatabase::new().await;
    // Measure one projection's history bytes as the summary records them.
    let open = router(database.open().await.unwrap());
    let (status, report) = import(&open, "bytes-a", running(2)).await;
    assert_eq!(status, StatusCode::CREATED, "{report}");
    let standalone = &report["identity"]["standalone"];
    let export = format!("/api/workflows/{}/runs/{}/history/export",
        standalone["workflow_id"].as_str().unwrap().replace(':', "%3A"), standalone["run_id"].as_str().unwrap());
    let (status, bundle) = request(&open, "GET", &export, Value::Null).await;
    assert_eq!(status, StatusCode::OK, "{bundle}");
    let bytes = usize::try_from(bundle["summary"]["history_size_bytes"].as_i64().unwrap()).unwrap();
    assert!(bytes > 0, "{bundle}");

    // Source ids of one length give projections of one size.
    let exact = router(database.open().await.unwrap()
        .with_structural_limits(StructuralLimits { history_bytes: Some(bytes), ..StructuralLimits::default() }));
    let (status, report) = import(&exact, "bytes-b", running(2)).await;
    assert_eq!(status, StatusCode::CREATED, "{report}");
    let before = rows(&database).await;

    let short = router(database.open().await.unwrap()
        .with_structural_limits(StructuralLimits { history_bytes: Some(bytes - 1), ..StructuralLimits::default() }));
    let (status, refusal) = import(&short, "bytes-c", running(2)).await;
    assert_eq!(status, StatusCode::CONFLICT, "{refusal}");
    assert_eq!(refusal["reason"], "v1_projection_too_large", "{refusal}");
    assert_eq!(refusal["limit"], json!({"dimension": "history_transaction_bytes", "used": bytes, "limit": bytes - 1}), "{refusal}");
    assert_eq!(rows(&database).await, before);
}

/// Appends racing the import that creates a projection: each answers that
/// the instance does not exist yet or that it is read-only, and no stream,
/// item, signal, command or task is ever written. The store decides under
/// the lock every durable transaction takes, so the race has one outcome.
#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
async fn appends_racing_a_projection_import_never_write() {
    let database = TestDatabase::new().await;
    let app = router(database.open().await.unwrap());
    let (status, dry) = request(&app, "POST", ROUTE, json!({"source_id": "race-src", "workflow": running(1), "dry_run": true})).await;
    assert_eq!(status, StatusCode::OK, "{dry}");
    let workflow_id = dry["identity"]["standalone"]["workflow_id"].as_str().unwrap().to_owned();
    let path = format!("/api/workflows/{}/message-streams/orders/messages", workflow_id.replace(':', "%3A"));
    let before = rows(&database).await;

    let mut appends = tokio::task::JoinSet::new();
    for index in 0..24 {
        let (app, path) = (app.clone(), path.clone());
        appends.spawn(async move {
            request(&app, "POST", &path, json!({"message_id": format!("m-{index}"), "input": ["x"]})).await
        });
    }
    let (status, report) = import(&app, "race-src", running(1)).await;
    assert_eq!(status, StatusCode::CREATED, "{report}");
    while let Some(answer) = appends.join_next().await {
        let (status, body) = answer.unwrap();
        let expected = match status {
            StatusCode::NOT_FOUND => "instance_not_found",
            StatusCode::CONFLICT => "v1_projection_read_only",
            other => panic!("{other}: {body}"),
        };
        assert_eq!(body["reason"], expected, "{body}");
    }
    // After the race, the projection and only the projection was written.
    let after = rows(&database).await;
    assert_eq!(before.len(), after.len());
    for ((table, was), (_, now)) in before.iter().zip(&after) {
        let grew = match table.as_str() {
            "workflow_instances" | "workflow_runs" | "workflow_run_summaries" => 1,
            "workflow_history_events" => 2,
            _ => 0,
        };
        assert_eq!(*now, was + grew, "{table}");
    }
}

/// A connection holding the lock every durable transaction takes on this
/// backend: while it is held, no transaction can begin its writes.
enum TransitionLock {
    Sqlite(sqlx::SqliteConnection),
    Postgres(sqlx::PgConnection),
    MySql(sqlx::MySqlConnection),
}

async fn hold_transition_lock(database: &TestDatabase) -> TransitionLock {
    use sqlx::Connection;
    match database {
        TestDatabase::Sqlite(dir) => {
            let url = format!("sqlite://{}", dir.path().join("runtime.sqlite").display());
            let mut connection = sqlx::SqliteConnection::connect(&url).await.unwrap();
            sqlx::query("BEGIN IMMEDIATE").execute(&mut connection).await.unwrap();
            TransitionLock::Sqlite(connection)
        }
        TestDatabase::Postgres { options, .. } => {
            let mut connection = sqlx::PgConnection::connect_with(options).await.unwrap();
            sqlx::query("SELECT pg_advisory_lock(4924501473315676163)").execute(&mut connection).await.unwrap();
            TransitionLock::Postgres(connection)
        }
        TestDatabase::MySql { options, .. } => {
            let mut connection = sqlx::MySqlConnection::connect_with(options).await.unwrap();
            // MySQL runs transaction control only outside prepared statements.
            sqlx::raw_sql("START TRANSACTION").execute(&mut connection).await.unwrap();
            sqlx::query("SELECT engine FROM dw_server_schema WHERE engine='rust-development' FOR UPDATE")
                .execute(&mut connection).await.unwrap();
            TransitionLock::MySql(connection)
        }
    }
}

/// Wait until another transaction waits on the transition lock, where the
/// backend shows it: PostgreSQL's ungranted advisory lock, or an InnoDB
/// transaction in LOCK WAIT, in this test's database only (other tests run
/// in parallel). One connection polls, so the observer adds no connection
/// churn. SQLite's writer waits inside its own busy handler, which no other
/// connection can see; returns false there.
async fn lock_waiter_observed(database: &TestDatabase) -> bool {
    use sqlx::Connection;
    const POSTGRES: &str = "SELECT COUNT(*) FROM pg_locks WHERE locktype='advisory' AND NOT granted \
        AND database=(SELECT oid FROM pg_database WHERE datname=current_database())";
    const MYSQL: &str = "SELECT COUNT(*) FROM information_schema.innodb_trx t \
        JOIN information_schema.processlist p ON p.id=t.trx_mysql_thread_id WHERE t.trx_state='LOCK WAIT' AND p.db=DATABASE()";
    let pause = || tokio::time::sleep(std::time::Duration::from_millis(50));
    match database {
        TestDatabase::Sqlite(_) => return false,
        TestDatabase::Postgres { options, .. } => {
            let mut connection = sqlx::PgConnection::connect_with(options).await.unwrap();
            for _ in 0..200 {
                let waiting: i64 = sqlx::query_scalar(POSTGRES).fetch_one(&mut connection).await.unwrap();
                if waiting > 0 {
                    return true;
                }
                pause().await;
            }
        }
        TestDatabase::MySql { options, .. } => {
            // On MariaDB the install did not reach its lock wait while this
            // observer polled information_schema from the start; it waits
            // a moment first, then still requires the wait to be seen.
            tokio::time::sleep(std::time::Duration::from_millis(1000)).await;
            let mut connection = sqlx::MySqlConnection::connect_with(options).await.unwrap();
            for _ in 0..200 {
                let waiting: i64 = sqlx::query_scalar(MYSQL).fetch_one(&mut connection).await.unwrap();
                if waiting > 0 {
                    return true;
                }
                pause().await;
            }
        }
    }
    panic!("no transaction waited on the transition lock within ten seconds");
}

async fn release(lock: TransitionLock) {
    match lock {
        TransitionLock::Sqlite(mut connection) => { sqlx::query("ROLLBACK").execute(&mut connection).await.unwrap(); }
        TransitionLock::Postgres(mut connection) => {
            sqlx::query("SELECT pg_advisory_unlock(4924501473315676163)").execute(&mut connection).await.unwrap();
        }
        TransitionLock::MySql(mut connection) => { sqlx::raw_sql("ROLLBACK").execute(&mut connection).await.unwrap(); }
    }
}

/// The projection's install waits on the transition lock, so its outcome is
/// fixed on both sides: an append made while the install is pending finds
/// no instance and writes nothing, and one made after it commits is refused.
/// An append reaches the store only for an existing instance, and a
/// projection's instance and run are written in one transaction, so no
/// append can pass the store's check and then see the projection appear.
#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
async fn an_append_cannot_cross_a_projection_install_waiting_on_the_lock() {
    let database = TestDatabase::new().await;
    if matches!(database, TestDatabase::Sqlite(_)) {
        // SQLite's writer waits where no other connection can see it; the
        // library control an_append_cannot_cross_an_install_waiting_on_the_sqlite_lock
        // observes the install reaching the lock through a test probe.
        return;
    }
    let app = router(database.open().await.unwrap());
    let (status, dry) = request(&app, "POST", ROUTE, json!({"source_id": "barrier-src", "workflow": running(1), "dry_run": true})).await;
    assert_eq!(status, StatusCode::OK, "{dry}");
    let workflow_id = dry["identity"]["standalone"]["workflow_id"].as_str().unwrap().to_owned();
    let path = format!("/api/workflows/{}/message-streams/orders/messages", workflow_id.replace(':', "%3A"));
    let before = rows(&database).await;

    let lock = hold_transition_lock(&database).await;
    let install = tokio::spawn({
        let app = app.clone();
        async move { import(&app, "barrier-src", running(1)).await }
    });
    // On PostgreSQL and MySQL the install is seen waiting on the lock; on
    // SQLite it cannot commit while the lock is held, wherever it is.
    assert!(lock_waiter_observed(&database).await);
    let (status, body) = request(&app, "POST", &path, json!({"message_id": "m-pending", "input": ["x"]})).await;
    assert_eq!((status, &body["reason"]), (StatusCode::NOT_FOUND, &json!("instance_not_found")), "{body}");
    assert!(!install.is_finished(), "the install committed while the transition lock was held");
    release(lock).await;

    let (status, report) = install.await.unwrap();
    assert_eq!(status, StatusCode::CREATED, "{report}");
    let (status, body) = request(&app, "POST", &path, json!({"message_id": "m-after", "input": ["x"]})).await;
    assert_eq!((status, &body["reason"]), (StatusCode::CONFLICT, &json!("v1_projection_read_only")), "{body}");

    let after = rows(&database).await;
    assert_eq!(before.len(), after.len());
    for ((table, was), (_, now)) in before.iter().zip(&after) {
        let grew = match table.as_str() {
            "workflow_instances" | "workflow_runs" | "workflow_run_summaries" => 1,
            "workflow_history_events" => 2,
            _ => 0,
        };
        assert_eq!(*now, was + grew, "{table}");
    }
}
