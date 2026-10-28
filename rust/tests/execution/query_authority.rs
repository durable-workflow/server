//! The published query-authority contract is independent of activity and
//! workflow completion receipts. No business history is changed by a query.
use super::*;
use chrono::{DateTime, SecondsFormat, Utc};
use std::time::Duration;
use tokio::task::JoinHandle;

struct Original {
    database: TestDatabase,
    runtime: Runtime,
    app: Router,
    claim: Value,
    waiting: JoinHandle<(StatusCode, Value)>,
    history_path: String,
    history: Value,
}

async fn original(name: &str) -> Original {
    configured_original(name, None).await
}

async fn configured_original(
    name: &str,
    config: Option<durable_workflow_server::runtime::QueryTaskConfig>,
) -> Original {
    let database = TestDatabase::new().await;
    let mut runtime = database.open().await.unwrap();
    if let Some(config) = config {
        runtime = runtime.with_query_task_config(config);
    }
    let app = router(runtime.clone());
    query_worker(&app, "authority-worker").await;
    let started = start(&app, name).await;
    let task = poll(&app, "authority-worker", "workflow").await;
    let completed = request(
        &app,
        "POST",
        &format!(
            "/api/worker/workflow-tasks/{}/complete",
            task["task_id"].as_str().unwrap()
        ),
        completion(
            &task,
            json!([{"type":"complete_workflow","result":envelope(Payload::Long(7))}]),
        ),
    )
    .await;
    assert_eq!(completed.0, StatusCode::OK, "{}", completed.1);
    let history_path = format!(
        "/api/workflows/{name}/runs/{}/history",
        started["run_id"].as_str().unwrap()
    );
    let history = request(&app, "GET", &history_path, Value::Null).await.1;
    let waiting = {
        let app = app.clone();
        let path = format!("/api/workflows/{name}/query/state");
        tokio::spawn(async move {
            request(
                &app,
                "POST",
                &path,
                json!({"input":envelope(Payload::Array(vec![Payload::Long(7)]))}),
            )
            .await
        })
    };
    let claim = await_query(&app, "authority-worker").await;
    Original {
        database,
        runtime,
        app,
        claim,
        waiting,
        history_path,
        history,
    }
}

fn instant(value: &Value) -> DateTime<Utc> {
    let raw = value.as_str().expect("original query authority timestamp");
    let parsed = DateTime::parse_from_rfc3339(raw)
        .unwrap()
        .with_timezone(&Utc);
    assert_eq!(raw, parsed.to_rfc3339_opts(SecondsFormat::Micros, true));
    parsed
}

fn submitted(claim: &Value) -> Value {
    json!({"lease_owner":"authority-worker", "query_task_attempt":claim["query_task_attempt"],
        "result_envelope":envelope(Payload::Array(vec![Payload::Long(9007199254740993),Payload::Null,Payload::String("rangeλ".into())]))})
}

async fn cache_snapshot(database: &TestDatabase, id: &str) -> (String, i64) {
    let key = format!("dw-native-query:{id}");
    macro_rules! read {
        ($pool:expr,$sql:literal) => {{
            let pool = $pool;
            let snapshot = sqlx::query_as($sql)
                .bind(&key)
                .fetch_one(&pool)
                .await
                .unwrap();
            pool.close().await;
            snapshot
        }};
    }
    match database {
        TestDatabase::Sqlite(directory) => read!(
            sqlx::SqlitePool::connect(&format!(
                "sqlite://{}",
                directory.path().join("runtime.sqlite").display()
            ))
            .await
            .unwrap(),
            "SELECT value,expiration FROM dw_query_cache WHERE \"key\"=$1"
        ),
        TestDatabase::Postgres { options, .. } => read!(
            sqlx::postgres::PgPoolOptions::new()
                .connect_with(options.as_ref().clone())
                .await
                .unwrap(),
            "SELECT value,expiration FROM dw_query_cache WHERE \"key\"=$1"
        ),
        TestDatabase::MySql { options, .. } => read!(
            sqlx::mysql::MySqlPoolOptions::new()
                .connect_with(options.as_ref().clone())
                .await
                .unwrap(),
            "SELECT value,expiration FROM dw_query_cache WHERE `key`=?"
        ),
    }
}

async fn replace_cache(database: &TestDatabase, id: &str, task: &Value, expires: i64) {
    let key = format!("dw-native-query:{id}");
    macro_rules! write {
        ($pool:expr,$sql:literal) => {{
            let pool = $pool;
            assert_eq!(
                sqlx::query($sql)
                    .bind(task.to_string())
                    .bind(expires)
                    .bind(&key)
                    .execute(&pool)
                    .await
                    .unwrap()
                    .rows_affected(),
                1
            );
            pool.close().await;
        }};
    }
    match database {
        TestDatabase::Sqlite(directory) => write!(
            sqlx::SqlitePool::connect(&format!(
                "sqlite://{}",
                directory.path().join("runtime.sqlite").display()
            ))
            .await
            .unwrap(),
            "UPDATE dw_query_cache SET value=$1,expiration=$2 WHERE \"key\"=$3"
        ),
        TestDatabase::Postgres { options, .. } => write!(
            sqlx::postgres::PgPoolOptions::new()
                .connect_with(options.as_ref().clone())
                .await
                .unwrap(),
            "UPDATE dw_query_cache SET value=$1,expiration=$2 WHERE \"key\"=$3"
        ),
        TestDatabase::MySql { options, .. } => write!(
            sqlx::mysql::MySqlPoolOptions::new()
                .connect_with(options.as_ref().clone())
                .await
                .unwrap(),
            "UPDATE dw_query_cache SET value=?,expiration=? WHERE `key`=?"
        ),
    }
}

async fn observation(app: &Router, id: &str, owner: &str, attempt: i64) -> (StatusCode, Value) {
    let response = admission::admission_http(
        app,
        "GET",
        &format!(
            "/api/worker/query-tasks/{id}/status?lease_owner={owner}&query_task_attempt={attempt}"
        ),
        true,
        Some("test-token"),
        Some("1.20"),
        None,
        Value::Null,
    )
    .await;
    (response.0, response.2)
}

enum HeldWriter {
    Sqlite(sqlx::SqliteConnection),
    Postgres(sqlx::PgConnection),
    MySql(sqlx::MySqlConnection),
}

impl HeldWriter {
    async fn acquire(database: &TestDatabase) -> Self {
        use sqlx::Connection;
        match database {
            TestDatabase::Sqlite(directory) => {
                let mut connection = sqlx::SqliteConnection::connect(&format!(
                    "sqlite://{}",
                    directory.path().join("runtime.sqlite").display()
                ))
                .await
                .unwrap();
                sqlx::query("BEGIN IMMEDIATE")
                    .execute(&mut connection)
                    .await
                    .unwrap();
                Self::Sqlite(connection)
            }
            TestDatabase::Postgres { options, .. } => {
                let mut connection = sqlx::PgConnection::connect_with(options).await.unwrap();
                sqlx::query("BEGIN").execute(&mut connection).await.unwrap();
                sqlx::query("SELECT pg_advisory_xact_lock(4924501473315676163)")
                    .execute(&mut connection)
                    .await
                    .unwrap();
                Self::Postgres(connection)
            }
            TestDatabase::MySql { options, .. } => {
                let mut connection = sqlx::MySqlConnection::connect_with(options).await.unwrap();
                sqlx::raw_sql("START TRANSACTION")
                    .execute(&mut connection)
                    .await
                    .unwrap();
                sqlx::query("SELECT engine FROM dw_server_schema FOR UPDATE")
                    .execute(&mut connection)
                    .await
                    .unwrap();
                Self::MySql(connection)
            }
        }
    }

    async fn release(self) {
        match self {
            Self::Sqlite(mut connection) => {
                sqlx::query("ROLLBACK")
                    .execute(&mut connection)
                    .await
                    .unwrap();
            }
            Self::Postgres(mut connection) => {
                sqlx::query("ROLLBACK")
                    .execute(&mut connection)
                    .await
                    .unwrap();
            }
            Self::MySql(mut connection) => {
                sqlx::raw_sql("ROLLBACK")
                    .execute(&mut connection)
                    .await
                    .unwrap();
            }
        }
    }
}

#[tokio::test]
async fn concurrent_identical_failure_has_one_decision_and_conflicts_stay_refused() {
    let original = original("query-authority-failed-receipt").await;
    let id = original.claim["query_task_id"].as_str().unwrap();
    let path = format!("/api/worker/query-tasks/{id}/fail");
    let body = json!({"lease_owner":"authority-worker","query_task_attempt":original.claim["query_task_attempt"],
        "failure":{"reason":"application_refused","message":"rangeλ","details":{"value":9007199254740993_i64,"nil":null}}});
    let (first, second) = tokio::join!(
        request(&original.app, "POST", &path, body.clone()),
        request(&original.app, "POST", &path, body.clone())
    );
    assert_eq!(
        (first.0, second.0),
        (StatusCode::OK, StatusCode::OK),
        "{first:?} {second:?}"
    );
    let mut recorded = [
        first.1["recorded"].as_bool().unwrap(),
        second.1["recorded"].as_bool().unwrap(),
    ];
    recorded.sort();
    assert_eq!(recorded, [false, true]);
    assert_eq!(first.1["outcome"], "failed");
    let caller = original.waiting.await.unwrap();
    assert_eq!(caller.0, StatusCode::CONFLICT, "{}", caller.1);
    assert_eq!(caller.1["reason"], "application_refused");
    let snapshot = cache_snapshot(&original.database, id).await;
    let mut wrong_owner = body.clone();
    wrong_owner["lease_owner"] = json!("other-worker");
    for changed in [wrong_owner, Value::Null] {
        // Different operations and claims cannot borrow an earlier decision.
        let (route, payload, reason) = if changed.is_null() {
            (
                format!("/api/worker/query-tasks/{id}/complete"),
                submitted(&original.claim),
                "query_completion_conflict",
            )
        } else {
            (path.clone(), changed, "query_task_not_leased")
        };
        let refused = request(&original.app, "POST", &route, payload).await;
        assert_eq!(refused.0, StatusCode::CONFLICT, "{}", refused.1);
        assert_eq!(refused.1["reason"], reason);
    }
    let status = observation(&original.app, id, "authority-worker", 1).await;
    assert_eq!(status.0, StatusCode::OK, "{}", status.1);
    assert_eq!(status.1["can_continue"], false);
    assert_eq!(status.1["reason"], "query_task_not_leased");
    assert_eq!(cache_snapshot(&original.database, id).await, snapshot);
    assert_eq!(
        request(&original.app, "GET", &original.history_path, Value::Null)
            .await
            .1,
        original.history
    );
    original.runtime.close().await;
    original.database.remove().await;
}

#[tokio::test]
async fn status_checks_auth_namespace_and_claim_before_revealing_original_timing() {
    let original = original("query-authority-private-observation").await;
    let id = original.claim["query_task_id"].as_str().unwrap();
    let path = format!(
        "/api/worker/query-tasks/{id}/status?lease_owner=authority-worker&query_task_attempt=1"
    );
    let snapshot = cache_snapshot(&original.database, id).await;
    let unauthorized = admission::admission_http(
        &original.app,
        "GET",
        &path,
        true,
        None,
        Some("1.20"),
        Some("ghost"),
        Value::Null,
    )
    .await;
    assert_eq!(unauthorized.0, StatusCode::UNAUTHORIZED);
    namespaces::create(&original.app, "alpha").await;
    let foreign = admission::admission_http(
        &original.app,
        "GET",
        &path,
        true,
        Some("test-token"),
        Some("1.20"),
        Some("alpha"),
        Value::Null,
    )
    .await;
    assert_eq!(foreign.0, StatusCode::NOT_FOUND, "{}", foreign.2);
    assert!(foreign.2.get("query_deadline_at").is_none());
    for (owner, attempt, reason) in [
        ("other-worker", 1, "lease_owner_mismatch"),
        ("authority-worker", 2, "query_task_attempt_mismatch"),
    ] {
        let refused = observation(&original.app, id, owner, attempt).await;
        assert_eq!(refused.0, StatusCode::CONFLICT, "{}", refused.1);
        assert_eq!(refused.1["reason"], reason);
        assert!(refused.1.get("query_deadline_at").is_none());
    }
    assert_eq!(cache_snapshot(&original.database, id).await, snapshot);
    let complete = request(
        &original.app,
        "POST",
        &format!("/api/worker/query-tasks/{id}/complete"),
        submitted(&original.claim),
    )
    .await;
    assert_eq!(complete.0, StatusCode::OK, "{}", complete.1);
    assert_eq!(original.waiting.await.unwrap().0, StatusCode::OK);
    original.runtime.close().await;
    original.database.remove().await;
}

#[tokio::test]
async fn unavailable_legacy_deadline_gets_only_one_fixed_refusal_tombstone() {
    legacy_refusal(false).await;
    legacy_refusal(true).await;
}

async fn legacy_refusal(missing_retention: bool) {
    let original = original("query-authority-legacy-refusal").await;
    original.waiting.abort();
    assert!(original.waiting.await.unwrap_err().is_cancelled());
    let id = original.claim["query_task_id"].as_str().unwrap();
    let (raw, expires) = cache_snapshot(&original.database, id).await;
    let mut legacy: Value = serde_json::from_str(&raw).unwrap();
    legacy.as_object_mut().unwrap().remove("query_deadline_at");
    if missing_retention {
        legacy.as_object_mut().unwrap().remove("receipt_expires_at");
    }
    legacy["deadline"] = json!(9999999999_i64);
    replace_cache(&original.database, id, &legacy, expires).await;
    let before = cache_snapshot(&original.database, id).await;
    let status = observation(&original.app, id, "authority-worker", 1).await;
    assert_eq!(status.0, StatusCode::OK, "{}", status.1);
    assert_eq!(status.1["can_continue"], false);
    assert_eq!(status.1["reason"], "query_deadline_unavailable");
    assert_eq!(
        cache_snapshot(&original.database, id).await,
        before,
        "status must not create a tombstone"
    );
    let path = format!("/api/worker/query-tasks/{id}/complete");
    let refused = request(&original.app, "POST", &path, submitted(&original.claim)).await;
    assert_eq!(refused.0, StatusCode::CONFLICT, "{}", refused.1);
    assert_eq!(refused.1["reason"], "query_deadline_unavailable");
    let fixed = cache_snapshot(&original.database, id).await;
    let tombstone: Value = serde_json::from_str(&fixed.0).unwrap();
    if missing_retention {
        assert_eq!(
            instant(&tombstone["receipt_expires_at"]) - instant(&tombstone["legacy_refusal_at"]),
            chrono::Duration::seconds(60)
        );
    } else {
        assert_eq!(
            tombstone["receipt_expires_at"],
            legacy["receipt_expires_at"]
        );
    }
    assert_eq!(tombstone["query_admitted_at"], legacy["query_admitted_at"]);
    assert!(tombstone.get("query_deadline_at").is_none());
    assert!(tombstone.get("terminal_receipt").is_none());
    assert!(tombstone.get("history_events").is_none());
    tokio::time::sleep(Duration::from_millis(20)).await;
    let again = request(&original.app, "POST", &path, submitted(&original.claim)).await;
    assert_eq!(again.0, StatusCode::CONFLICT, "{}", again.1);
    assert_eq!(again.1["reason"], "query_deadline_unavailable");
    assert_eq!(cache_snapshot(&original.database, id).await, fixed);
    assert!(
        poll(&original.app, "authority-worker", "query")
            .await
            .is_null()
    );
    assert_eq!(cache_snapshot(&original.database, id).await, fixed);
    assert_eq!(
        request(&original.app, "GET", &original.history_path, Value::Null)
            .await
            .1,
        original.history
    );
    original.runtime.close().await;
    original.database.remove().await;
}

#[tokio::test]
async fn expired_original_refuses_before_decoding_and_status_does_not_extend_it() {
    let config = durable_workflow_server::runtime::QueryTaskConfig {
        timeout_seconds: 2,
        lease_timeout_seconds: 2,
        ..Default::default()
    };
    let original = configured_original("query-authority-expired-original", Some(config)).await;
    let id = original.claim["query_task_id"].as_str().unwrap();
    let caller = original.waiting.await.unwrap();
    assert_eq!(caller.0, StatusCode::GATEWAY_TIMEOUT);
    assert_eq!(caller.1["reason"], "query_worker_execution_timeout");
    let snapshot = cache_snapshot(&original.database, id).await;
    let status = observation(&original.app, id, "authority-worker", 1).await;
    assert_eq!(status.0, StatusCode::OK, "{}", status.1);
    assert_eq!(status.1["can_continue"], false);
    assert_eq!(status.1["reason"], "query_task_timed_out");
    for field in ["query_admitted_at", "query_deadline_at", "lease_expires_at"] {
        assert_eq!(status.1[field], original.claim[field]);
    }
    assert_eq!(cache_snapshot(&original.database, id).await, snapshot);
    let mut body = submitted(&original.claim);
    body["result_envelope"] = json!({"codec":"avro","blob":"invalid-avro"});
    let refused = request(
        &original.app,
        "POST",
        &format!("/api/worker/query-tasks/{id}/complete"),
        body,
    )
    .await;
    assert_eq!(refused.0, StatusCode::CONFLICT, "{}", refused.1);
    assert_eq!(refused.1["reason"], "query_task_timed_out");
    let (raw, _) = cache_snapshot(&original.database, id).await;
    let expired: Value = serde_json::from_str(&raw).unwrap();
    let before: Value = serde_json::from_str(&snapshot.0).unwrap();
    assert_eq!(expired["status"], "timed_out");
    for field in [
        "query_admitted_at",
        "query_deadline_at",
        "receipt_expires_at",
    ] {
        assert_eq!(expired[field], before[field]);
    }
    assert_eq!(
        request(&original.app, "GET", &original.history_path, Value::Null)
            .await
            .1,
        original.history
    );
    original.runtime.close().await;
    original.database.remove().await;
}

#[tokio::test]
async fn waiting_poll_cannot_claim_for_a_same_id_replacement_registration() {
    let original = original("query-authority-poll-incarnation").await;
    let id = original.claim["query_task_id"].as_str().unwrap();
    let finished = request(
        &original.app,
        "POST",
        &format!("/api/worker/query-tasks/{id}/complete"),
        submitted(&original.claim),
    )
    .await;
    assert_eq!(finished.0, StatusCode::OK, "{}", finished.1);
    assert_eq!(original.waiting.await.unwrap().0, StatusCode::OK);
    let waiting_poll = {
        let app = original.app.clone();
        tokio::spawn(async move {
            request(
                &app,
                "POST",
                "/api/worker/query-tasks/poll",
                json!({"worker_id":"authority-worker","task_queue":"test","timeout_seconds":5}),
            )
            .await
        })
    };
    // Wait through multiple empty poll probes before replacing the already
    // registered worker. This request belongs to the old registration.
    tokio::time::sleep(Duration::from_millis(150)).await;
    query_worker(&original.app, "authority-worker").await;
    let stale = tokio::time::timeout(Duration::from_secs(2), waiting_poll)
        .await
        .expect("a replaced poll must refuse without waiting for new work")
        .unwrap();
    // Published 2.11.1 ends a held query poll fenced off by a replacement
    // empty, leasing nothing (`performPoll`; worker-poll-fence fixture).
    assert_eq!(stale.0, StatusCode::OK, "{}", stale.1);
    assert_eq!(stale.1["poll_status"], "empty");
    assert!(stale.1["task"].is_null());
    let waiting = {
        let app = original.app.clone();
        tokio::spawn(async move {
            request(
                &app,
                "POST",
                "/api/workflows/query-authority-poll-incarnation/query/state",
                json!({"input":envelope(Payload::Array(vec![Payload::Long(9)]))}),
            )
            .await
        })
    };
    let replacement = await_query(&original.app, "authority-worker").await;
    assert_eq!(replacement["query_task_attempt"], 1);
    let complete = request(
        &original.app,
        "POST",
        &format!(
            "/api/worker/query-tasks/{}/complete",
            replacement["query_task_id"].as_str().unwrap()
        ),
        submitted(&replacement),
    )
    .await;
    assert_eq!(complete.0, StatusCode::OK, "{}", complete.1);
    assert_eq!(waiting.await.unwrap().0, StatusCode::OK);
    assert_eq!(
        request(&original.app, "GET", &original.history_path, Value::Null)
            .await
            .1,
        original.history
    );
    original.runtime.close().await;
    original.database.remove().await;
}

#[tokio::test]
async fn delivery_and_status_keep_original_microseconds_without_renewal() {
    let original = original("query-authority-observation").await;
    let admitted = instant(&original.claim["query_admitted_at"]);
    let deadline = instant(&original.claim["query_deadline_at"]);
    let lease = instant(&original.claim["lease_expires_at"]);
    let served = instant(&original.claim["server_time"]);
    let cached = cache_snapshot(
        &original.database,
        original.claim["query_task_id"].as_str().unwrap(),
    )
    .await;
    assert!(admitted <= served && served < deadline && lease <= deadline);
    let status_path = format!(
        "/api/worker/query-tasks/{}/status?lease_owner=authority-worker&query_task_attempt={}",
        original.claim["query_task_id"].as_str().unwrap(),
        original.claim["query_task_attempt"]
    );
    for _ in 0..2 {
        let (status, _, observation) = admission::admission_http(
            &original.app,
            "GET",
            &status_path,
            true,
            Some("test-token"),
            Some("1.20"),
            None,
            Value::Null,
        )
        .await;
        assert_eq!(status, StatusCode::OK, "{observation}");
        for field in [
            "query_task_id",
            "query_task_attempt",
            "lease_owner",
            "query_admitted_at",
            "query_deadline_at",
            "lease_expires_at",
        ] {
            assert_eq!(observation[field], original.claim[field], "{field}");
        }
        assert_eq!(observation["can_continue"], true);
        assert_eq!(observation["query_task_status"], "leased");
        assert!(instant(&observation["server_time"]) >= served);
        tokio::time::sleep(Duration::from_millis(10)).await;
    }
    let legacy = admission::admission_http(
        &original.app,
        "GET",
        &status_path,
        true,
        Some("test-token"),
        Some("1.19"),
        None,
        Value::Null,
    )
    .await;
    assert_eq!(legacy.0, StatusCode::UNPROCESSABLE_ENTITY);
    assert_eq!(legacy.2["reason"], "query_task_status_unavailable");
    assert_eq!(
        cache_snapshot(
            &original.database,
            original.claim["query_task_id"].as_str().unwrap()
        )
        .await,
        cached
    );
    let finished = request(
        &original.app,
        "POST",
        &format!(
            "/api/worker/query-tasks/{}/complete",
            original.claim["query_task_id"].as_str().unwrap()
        ),
        submitted(&original.claim),
    )
    .await;
    assert_eq!(finished.0, StatusCode::OK, "{}", finished.1);
    assert_eq!(original.waiting.await.unwrap().0, StatusCode::OK);
    assert_eq!(
        request(&original.app, "GET", &original.history_path, Value::Null)
            .await
            .1,
        original.history
    );
    original.runtime.close().await;
    original.database.remove().await;
}

#[tokio::test]
async fn identical_committed_result_reconciles_before_and_after_original_expiry() {
    let config = durable_workflow_server::runtime::QueryTaskConfig {
        timeout_seconds: 4,
        lease_timeout_seconds: 4,
        ..Default::default()
    };
    let original = configured_original("query-authority-receipt", Some(config)).await;
    let path = format!(
        "/api/worker/query-tasks/{}/complete",
        original.claim["query_task_id"].as_str().unwrap()
    );
    let body = submitted(&original.claim);
    let first = request(&original.app, "POST", &path, body.clone()).await;
    assert_eq!(first.0, StatusCode::OK, "{}", first.1);
    assert_eq!(first.1["recorded"], true);
    assert_eq!(original.waiting.await.unwrap().0, StatusCode::OK);
    let cache_before = cache_snapshot(
        &original.database,
        original.claim["query_task_id"].as_str().unwrap(),
    )
    .await;
    let writer = HeldWriter::acquire(&original.database).await;
    let before = tokio::time::timeout(
        Duration::from_millis(500),
        request(&original.app, "POST", &path, body.clone()),
    )
    .await
    .expect("retained query receipt must reconcile without writer admission");
    assert_eq!(before.0, StatusCode::OK, "{}", before.1);
    assert_eq!(before.1["recorded"], false);
    let mut conflicting = body.clone();
    conflicting["result_envelope"] = json!({"codec":"avro","blob":"not-the-committed-bytes"});
    let refused = tokio::time::timeout(
        Duration::from_millis(500),
        request(&original.app, "POST", &path, conflicting.clone()),
    )
    .await
    .expect("conflicting receipt must refuse without writer admission");
    assert_eq!(refused.0, StatusCode::CONFLICT, "{}", refused.1);
    assert_eq!(refused.1["reason"], "query_completion_conflict");
    let remaining = instant(&original.claim["query_deadline_at"]) - Utc::now();
    tokio::time::sleep(remaining.to_std().unwrap_or_default() + Duration::from_millis(20)).await;
    let after = tokio::time::timeout(
        Duration::from_millis(500),
        request(&original.app, "POST", &path, body),
    )
    .await
    .expect("original expiry must not add writer admission to a retained receipt");
    assert_eq!(after.0, StatusCode::OK, "{}", after.1);
    assert_eq!(after.1["recorded"], false);
    let refused = tokio::time::timeout(
        Duration::from_millis(500),
        request(&original.app, "POST", &path, conflicting),
    )
    .await
    .expect("expired conflicting receipt must also refuse without writer admission");
    assert_eq!(refused.0, StatusCode::CONFLICT, "{}", refused.1);
    assert_eq!(refused.1["reason"], "query_completion_conflict");
    assert_eq!(
        cache_snapshot(
            &original.database,
            original.claim["query_task_id"].as_str().unwrap()
        )
        .await,
        cache_before
    );
    writer.release().await;
    assert_eq!(
        request(&original.app, "GET", &original.history_path, Value::Null)
            .await
            .1,
        original.history
    );
    original.runtime.close().await;
    original.database.remove().await;
}

#[tokio::test]
async fn a_live_query_survives_continuation_and_is_answered_from_the_predecessor() {
    let database = TestDatabase::new().await;
    let runtime = database.open().await.unwrap().with_query_task_config(
        durable_workflow_server::runtime::QueryTaskConfig {
            // Room for the timer, the resume and the continuation on a
            // loaded CI database: nothing here waits for the deadline.
            timeout_seconds: 30,
            lease_timeout_seconds: 30,
            ..Default::default()
        },
    );
    let app = router(runtime.clone());
    query_worker(&app, "authority-worker").await;
    let started = start(&app, "query-authority-continuation").await;
    let initial = poll(&app, "authority-worker", "workflow").await;
    assert_eq!(
        request(
            &app,
            "POST",
            &format!(
                "/api/worker/workflow-tasks/{}/complete",
                initial["task_id"].as_str().unwrap()
            ),
            completion(&initial, json!([{"type":"start_timer","delay_seconds":1}])),
        )
        .await
        .0,
        StatusCode::OK
    );
    let waiting = {
        let app = app.clone();
        tokio::spawn(async move {
            request(
                &app,
                "POST",
                "/api/workflows/query-authority-continuation/query/state",
                json!({"input":envelope(Payload::Array(vec![Payload::Long(7)]))}),
            )
            .await
        })
    };
    let claim = await_query(&app, "authority-worker").await;
    let id = claim["query_task_id"].as_str().unwrap();
    let snapshot = cache_snapshot(&database, id).await;
    let resumed = loop {
        let resumed = poll(&app, "authority-worker", "workflow").await;
        if !resumed.is_null() {
            break resumed;
        }
        assert!(
            Utc::now() < instant(&claim["query_deadline_at"]),
            "timer did not produce a genuine workflow lease within the original query deadline"
        );
        tokio::time::sleep(Duration::from_millis(20)).await;
    };
    let path = format!(
        "/api/worker/workflow-tasks/{}/complete",
        resumed["task_id"].as_str().unwrap()
    );
    // Core's continue-as-new touches no query task (continue-transfers
    // fixture): the live claim's exact bytes and expiry survive it, and the
    // predecessor's query is still answered to the waiting caller.
    let body = completion(&resumed, json!([{"type":"continue_as_new"}]));
    let continued = request(&app, "POST", &path, body).await;
    assert_eq!(continued.0, StatusCode::OK, "{}", continued.1);
    assert_eq!(cache_snapshot(&database, id).await, snapshot);
    let answered = request(
        &app,
        "POST",
        &format!("/api/worker/query-tasks/{id}/complete"),
        submitted(&claim),
    )
    .await;
    assert_eq!(answered.0, StatusCode::OK, "{}", answered.1);
    let caller = waiting.await.unwrap();
    assert_eq!(caller.0, StatusCode::OK, "{}", caller.1);
    assert_eq!(caller.1["run_id"], started["run_id"]);
    let runs = request(
        &app,
        "GET",
        "/api/workflows/query-authority-continuation/runs",
        Value::Null,
    )
    .await;
    assert_eq!(runs.1["runs"].as_array().unwrap().len(), 2);
    assert_eq!(runs.1["runs"][0]["run_id"], started["run_id"]);
    let successor = poll(&app, "authority-worker", "workflow").await;
    assert_ne!(successor["run_id"], started["run_id"]);
    assert_eq!(
        request(
            &app,
            "POST",
            &format!(
                "/api/worker/workflow-tasks/{}/complete",
                successor["task_id"].as_str().unwrap()
            ),
            completion(
                &successor,
                json!([{"type":"complete_workflow","result":envelope(Payload::Long(7))}])
            )
        )
        .await
        .0,
        StatusCode::OK
    );
    runtime.close().await;
    database.remove().await;
}

#[tokio::test]
async fn zero_is_immediate_and_later_policy_does_not_rebase_an_existing_query() {
    let original = original("query-authority-policy").await;
    let changed = router(original.runtime.clone().with_query_task_config(
        durable_workflow_server::runtime::QueryTaskConfig {
            timeout_seconds: 0,
            receipt_ttl_seconds: 60,
            ..Default::default()
        },
    ));
    let expired = request(
        &changed,
        "POST",
        "/api/workflows/query-authority-policy/query/state",
        json!({"input":envelope(Payload::Array(vec![Payload::Long(8)]))}),
    )
    .await;
    assert_eq!(expired.0, StatusCode::GATEWAY_TIMEOUT, "{}", expired.1);
    assert_eq!(expired.1["reason"], "query_task_not_claimed");
    assert!(poll(&changed, "authority-worker", "query").await.is_null());
    let complete_path = format!(
        "/api/worker/query-tasks/{}/complete",
        original.claim["query_task_id"].as_str().unwrap()
    );
    assert_eq!(
        request(&changed, "POST", &complete_path, submitted(&original.claim))
            .await
            .0,
        StatusCode::OK
    );
    assert_eq!(original.waiting.await.unwrap().0, StatusCode::OK);
    assert_eq!(
        request(&changed, "GET", &original.history_path, Value::Null)
            .await
            .1,
        original.history
    );
    original.runtime.close().await;
    original.database.remove().await;
}

#[tokio::test]
async fn same_worker_id_across_namespaces_and_replacement_keep_separate_originals() {
    let original = original("query-authority-default").await;
    namespaces::create(&original.app, "alpha").await;
    let definition = json!({"worker_id":"authority-worker","task_queue":"test","runtime":"php",
        "supported_workflow_types":["echo"],"supported_activity_types":[],"capabilities":["query_tasks"],
        "workflow_command_contracts":{"echo":{"queries":["state"],"query_contracts":[{"name":"state",
            "parameters":[{"name":"request","position":0,"type":"int","required":true,
                "variadic":false,"default_available":false,"allows_null":false}]}]}}});
    assert_eq!(
        namespaces::scoped(
            &original.app,
            "alpha",
            "POST",
            "/api/worker/register",
            definition.clone()
        )
        .await
        .0,
        StatusCode::CREATED
    );
    let started = namespaces::scoped(
        &original.app,
        "alpha",
        "POST",
        "/api/workflows",
        json!({
        "workflow_id":"query-authority-alpha","workflow_type":"echo","task_queue":"test",
        "input":envelope(Payload::Array(vec![Payload::Long(7)]))}),
    )
    .await;
    assert_eq!(started.0, StatusCode::CREATED, "{}", started.1);
    let task = namespaces::scoped(
        &original.app,
        "alpha",
        "POST",
        "/api/worker/workflow-tasks/poll",
        json!({"worker_id":"authority-worker","task_queue":"test","timeout_seconds":0}),
    )
    .await
    .1["task"]
        .clone();
    assert!(!task.is_null());
    assert_eq!(
        namespaces::scoped(
            &original.app,
            "alpha",
            "POST",
            &format!(
                "/api/worker/workflow-tasks/{}/complete",
                task["task_id"].as_str().unwrap()
            ),
            completion(
                &task,
                json!([{"type":"complete_workflow","result":envelope(Payload::Long(9))}])
            )
        )
        .await
        .0,
        StatusCode::OK
    );
    let alpha_history_path = format!(
        "/api/workflows/query-authority-alpha/runs/{}/history",
        started.1["run_id"].as_str().unwrap()
    );
    let alpha_history = namespaces::scoped(
        &original.app,
        "alpha",
        "GET",
        &alpha_history_path,
        Value::Null,
    )
    .await;
    let alpha_waiting = {
        let app = original.app.clone();
        tokio::spawn(async move {
            namespaces::scoped(
                &app,
                "alpha",
                "POST",
                "/api/workflows/query-authority-alpha/query/state",
                json!({"input":envelope(Payload::Array(vec![Payload::Long(9)]))}),
            )
            .await
        })
    };
    let alpha_claim = tokio::time::timeout(Duration::from_secs(5), async {
        loop {
            let response = namespaces::scoped(
                &original.app,
                "alpha",
                "POST",
                "/api/worker/query-tasks/poll",
                json!({"worker_id":"authority-worker","task_queue":"test","timeout_seconds":0}),
            )
            .await;
            assert_eq!(response.0, StatusCode::OK, "{}", response.1);
            if !response.1["task"].is_null() {
                break response.1["task"].clone();
            }
            tokio::time::sleep(Duration::from_millis(10)).await;
        }
    })
    .await
    .unwrap();
    assert_eq!(alpha_claim["namespace"], "alpha");
    assert_ne!(
        alpha_claim["query_task_id"],
        original.claim["query_task_id"]
    );
    let default_complete = format!(
        "/api/worker/query-tasks/{}/complete",
        original.claim["query_task_id"].as_str().unwrap()
    );
    let foreign = namespaces::scoped(
        &original.app,
        "alpha",
        "POST",
        &default_complete,
        submitted(&original.claim),
    )
    .await;
    assert_eq!(foreign.0, StatusCode::NOT_FOUND, "{}", foreign.1);
    let alpha_complete = format!(
        "/api/worker/query-tasks/{}/complete",
        alpha_claim["query_task_id"].as_str().unwrap()
    );
    assert_eq!(
        request(
            &original.app,
            "POST",
            &alpha_complete,
            submitted(&alpha_claim)
        )
        .await
        .0,
        StatusCode::NOT_FOUND
    );
    assert_eq!(
        namespaces::scoped(
            &original.app,
            "alpha",
            "POST",
            "/api/worker/register",
            definition
        )
        .await
        .0,
        StatusCode::CREATED
    );
    let old = namespaces::scoped(
        &original.app,
        "alpha",
        "POST",
        &alpha_complete,
        submitted(&alpha_claim),
    )
    .await;
    assert_eq!(old.0, StatusCode::CONFLICT, "{}", old.1);
    assert_eq!(old.1["reason"], "stale_worker_registration");
    let replacement = namespaces::scoped(
        &original.app,
        "alpha",
        "POST",
        "/api/worker/query-tasks/poll",
        json!({"worker_id":"authority-worker","task_queue":"test","timeout_seconds":0}),
    )
    .await
    .1["task"]
        .clone();
    assert_eq!(replacement["query_task_id"], alpha_claim["query_task_id"]);
    assert_eq!(replacement["query_task_attempt"], 2);
    for field in ["query_admitted_at", "query_deadline_at", "history_events"] {
        assert_eq!(replacement[field], alpha_claim[field], "{field}");
    }
    assert_eq!(
        namespaces::scoped(
            &original.app,
            "alpha",
            "POST",
            &alpha_complete,
            submitted(&replacement)
        )
        .await
        .0,
        StatusCode::OK
    );
    assert_eq!(alpha_waiting.await.unwrap().0, StatusCode::OK);
    assert_eq!(
        request(
            &original.app,
            "POST",
            &default_complete,
            submitted(&original.claim)
        )
        .await
        .0,
        StatusCode::OK
    );
    assert_eq!(original.waiting.await.unwrap().0, StatusCode::OK);
    assert_eq!(
        namespaces::scoped(
            &original.app,
            "alpha",
            "GET",
            &alpha_history_path,
            Value::Null
        )
        .await,
        alpha_history
    );
    assert_eq!(
        request(&original.app, "GET", &original.history_path, Value::Null)
            .await
            .1,
        original.history
    );
    original.runtime.close().await;
    original.database.remove().await;
}

/// Polls for query work until a query poll ends held by the run's leased
/// workflow task (`performPoll`'s `workflow_task_leased`): the client's query
/// is then admitted against the run.
async fn await_held_query(app: &Router, worker: &str) {
    for _ in 0..20 {
        let (status, body) = request(app, "POST", "/api/worker/query-tasks/poll",
            json!({"worker_id":worker,"task_queue":"test","timeout_seconds":1})).await;
        assert_eq!(status, StatusCode::OK, "{body}");
        assert!(body["task"].is_null(), "{body}");
        if body["poll_status"] == "workflow_task_leased" {
            return;
        }
    }
    panic!("the query was never admitted behind the leased workflow task");
}

// A live query holds no close. PHP's cancel and terminate never consult query
// tasks, and its query broker claims a task whatever its run's status, so a
// query admitted before the close is still leased and answered.
#[tokio::test]
async fn a_live_query_holds_no_close_and_is_answered_from_the_closed_run() {
    for (command, status) in [("cancel", "cancelled"), ("terminate", "terminated")] {
        let database = TestDatabase::new().await;
        let runtime = database.open().await.unwrap().with_query_task_config(
            durable_workflow_server::runtime::QueryTaskConfig {
                timeout_seconds: 30,
                lease_timeout_seconds: 30,
                ..Default::default()
            },
        );
        let app = router(runtime.clone());
        query_worker(&app, "authority-worker").await;
        let name = format!("query-authority-{command}");
        let started = start(&app, &name).await;
        let leased = poll(&app, "authority-worker", "workflow").await;
        assert_eq!(leased["run_id"], started["run_id"], "{leased}");
        let waiting = {
            let app = app.clone();
            let path = format!("/api/workflows/{name}/query/state");
            tokio::spawn(async move {
                request(&app, "POST", &path, json!({"input":envelope(Payload::Array(vec![Payload::Long(7)]))})).await
            })
        };
        await_held_query(&app, "authority-worker").await;

        let (code, closed) = request(&app, "POST", &format!("/api/workflows/{name}/{command}"),
            json!({"reason":"live query"})).await;
        assert_eq!(code, StatusCode::OK, "{command}: {closed}");
        let (_, described) = request(&app, "GET", &format!("/api/workflows/{name}"), Value::Null).await;
        assert_eq!(described["status"], status, "{described}");
        let claim = await_query(&app, "authority-worker").await;
        assert_eq!(claim["run_id"], started["run_id"], "{claim}");
        let id = claim["query_task_id"].as_str().unwrap();
        let answered = request(&app, "POST", &format!("/api/worker/query-tasks/{id}/complete"), submitted(&claim)).await;
        assert_eq!(answered.0, StatusCode::OK, "{}", answered.1);
        let caller = waiting.await.unwrap();
        assert_eq!(caller.0, StatusCode::OK, "{command}: {}", caller.1);
        assert_eq!(caller.1["run_id"], started["run_id"]);
        runtime.close().await;
        database.remove().await;
    }
}
