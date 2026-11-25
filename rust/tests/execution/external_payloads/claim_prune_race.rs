//! Server #960: a start that claims an external payload and a retention prune
//! that would delete the same object, run from two server nodes on one
//! database. The claim runs in the start's transition under
//! `payload_object_lock`, and the prune re-checks `payload_retained` under the
//! same lock, so neither order commits a run that references a deleted
//! registration or object.
//!
//! The order is forced with a held row lock, not with sleeps. The first
//! operation takes the transition lock and waits on a row the test
//! connection holds. The second then waits on the transition lock. Both waits
//! are read from the engine's lock tables before the test releases its row.
//! The start is held at its claim's write to the registration row, after its
//! checks outside the transaction. The prune is held at its object lock.
//! SQLite has one database-wide write lock and no lock tables to observe, so
//! it covers the two orders one after the other.
use super::retention::{closed_run, ns_request, object, registered, upload};
use super::*;
use sqlx::{AssertSqlSafe, Connection};
use std::time::Duration;

const PASS: &str = "/api/system/retention/pass";
/// The statements each operation waits at on MySQL and MariaDB.
const CLAIM: &str = "UPDATE runtime_external_payloads SET retained_at";
const OBJECT_LOCK: &str = "UPDATE runtime_external_payload_object_locks";
const TRANSITION: &str = "SELECT engine FROM dw_server_schema";

/// A test connection holding rows the first operation must write.
enum HeldRows {
    Postgres(sqlx::PgConnection),
    MySql(sqlx::MySqlConnection),
}

impl HeldRows {
    /// Every payload object lock row, which the prune takes before it
    /// deletes a registration. `None` on SQLite.
    async fn object_locks(database: &TestDatabase) -> Option<Self> {
        Self::hold(database, "SELECT bucket FROM runtime_external_payload_object_locks FOR UPDATE".into()).await
    }

    /// The payload's registration row, which the start's claim updates after
    /// its object lock. `None` on SQLite.
    async fn registration(database: &TestDatabase, reference: &Value) -> Option<Self> {
        let id = reference["reference_id"].as_str().unwrap();
        Self::hold(database, format!("SELECT id FROM runtime_external_payloads WHERE id='{id}' FOR UPDATE")).await
    }

    async fn hold(database: &TestDatabase, select: String) -> Option<Self> {
        let mut held = match database {
            TestDatabase::Sqlite(_) => return None,
            TestDatabase::Postgres { options, .. } => Self::Postgres(sqlx::PgConnection::connect_with(options.as_ref()).await.unwrap()),
            TestDatabase::MySql { options, .. } => Self::MySql(sqlx::MySqlConnection::connect_with(options.as_ref()).await.unwrap()),
        };
        match &mut held {
            Self::Postgres(connection) => {
                sqlx::raw_sql("BEGIN").execute(&mut *connection).await.unwrap();
                assert!(!sqlx::query(AssertSqlSafe(select)).fetch_all(&mut *connection).await.unwrap().is_empty());
            }
            Self::MySql(connection) => {
                sqlx::raw_sql("START TRANSACTION").execute(&mut *connection).await.unwrap();
                assert!(!sqlx::query(AssertSqlSafe(select)).fetch_all(&mut *connection).await.unwrap().is_empty());
            }
        }
        Some(held)
    }

    /// Whether another session of this test database waits for a lock: on
    /// PostgreSQL, `count` sessions in its lock table; on MySQL and MariaDB, a
    /// session running a statement that starts with `statement`, which only
    /// a lock this test holds can keep from finishing. InnoDB's own lock
    /// tables are a cache that concurrent readers can keep from refreshing.
    async fn waiting(&mut self, count: i64, statement: &str) -> bool {
        match self {
            Self::Postgres(connection) => sqlx::query_scalar::<_, i64>(
                "SELECT COUNT(DISTINCT l.pid) FROM pg_locks l JOIN pg_stat_activity a ON a.pid=l.pid \
                 WHERE NOT l.granted AND a.datname=current_database()",
            )
            .fetch_one(&mut *connection)
            .await
            .unwrap() >= count,
            Self::MySql(connection) => sqlx::query_scalar::<_, i64>(
                "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB=DATABASE() AND ID<>CONNECTION_ID() AND INFO LIKE ?",
            )
            .bind(format!("{statement}%"))
            .fetch_one(&mut *connection)
            .await
            .unwrap() > 0,
        }
    }

    /// Returns once the `count`th lock wait, at `statement`, is observed. The
    /// runtime's own lock waits end after five seconds, so this barrier must
    /// be reached well before that.
    async fn until_waiting(&mut self, count: i64, statement: &str, requests: &mut [&mut tokio::task::JoinHandle<(StatusCode, Value)>]) {
        let deadline = tokio::time::Instant::now() + Duration::from_secs(3);
        while !self.waiting(count, statement).await {
            for request in requests.iter_mut() {
                if request.is_finished() {
                    panic!("a request answered without waiting: {:?}", (&mut **request).await.unwrap());
                }
            }
            assert!(tokio::time::Instant::now() < deadline, "{count} lock waits were not observed");
            tokio::time::sleep(Duration::from_millis(20)).await;
        }
    }

    async fn release(self) {
        match self {
            Self::Postgres(mut connection) => sqlx::raw_sql("COMMIT").execute(&mut connection).await.map(drop).unwrap(),
            Self::MySql(mut connection) => sqlx::raw_sql("COMMIT").execute(&mut connection).await.map(drop).unwrap(),
        }
    }
}

/// Two nodes on one database, sharing one object root.
async fn two_nodes(root: &std::path::Path) -> (TestDatabase, Runtime, Router, Runtime, Router) {
    let (database, start_node, start_router) = setup(ExternalPayloadConfig::default(), root).await;
    let prune_node = database
        .open()
        .await
        .unwrap()
        .with_role_tokens(Some("worker-token".into()), Some("operator-token".into()), Some("admin-token".into()))
        .with_external_payload_config(ExternalPayloadConfig::default());
    let prune_router = router(prune_node.clone());
    (database, start_node, start_router, prune_node, prune_router)
}

fn start_body(workflow: &str, reference: &Value) -> Value {
    json!({"workflow_id": workflow, "workflow_type": "echo", "task_queue": workflow,
        "input": {"codec": "avro", "external_payload": reference}})
}

fn spawn_start(router: &Router, workflow: &str, reference: &Value) -> tokio::task::JoinHandle<(StatusCode, Value)> {
    let (router, body) = (router.clone(), start_body(workflow, reference));
    tokio::spawn(async move { ns_request(&router, "POST", "/api/workflows", "default", body).await })
}

fn spawn_prune(router: &Router, run: &str) -> tokio::task::JoinHandle<(StatusCode, Value)> {
    let (router, body) = (router.clone(), json!({"run_ids": [run]}));
    tokio::spawn(async move { ns_request(&router, "POST", PASS, "default", body).await })
}

fn prune_result(answer: (StatusCode, Value), deleted: i64) {
    let (status, body) = answer;
    assert_eq!(status, StatusCode::OK, "{body}");
    assert_eq!(body["results"][0]["outcome"], "pruned", "{body}");
    assert_eq!(body["results"][0]["external_payloads_deleted"], deleted, "{body}");
}

async fn runs_of(database: &TestDatabase, workflow: &str) -> i64 {
    count(database, AssertSqlSafe(format!("SELECT COUNT(*) FROM workflow_runs WHERE workflow_instance_id='{workflow}'"))).await
}

/// The start commits first: its run retains the reference, so the prune of
/// the only other run that held it keeps the registration and the object.
#[tokio::test]
async fn a_start_that_claims_first_keeps_the_object_from_a_concurrent_prune() {
    let temp = tempfile::tempdir().unwrap();
    let root = temp.path().join("objects");
    let (database, start_node, start_router, prune_node, prune_router) = two_nodes(&root).await;
    let reference = upload(&start_router, "default", "start wins").await;
    let holder = closed_run(&start_router, "default", "start-wins-holder", &reference).await;

    let (start, prune) = match HeldRows::registration(&database, &reference).await {
        Some(mut locks) => {
            // The start holds the transition lock and its object lock and
            // waits to claim the registration. The prune then waits on the
            // transition lock.
            let mut start = spawn_start(&start_router, "start-wins", &reference);
            locks.until_waiting(1, CLAIM, &mut [&mut start]).await;
            let mut prune = spawn_prune(&prune_router, &holder);
            locks.until_waiting(2, TRANSITION, &mut [&mut start, &mut prune]).await;
            locks.release().await;
            (start.await.unwrap(), prune.await.unwrap())
        }
        None => {
            let start = spawn_start(&start_router, "start-wins", &reference).await.unwrap();
            (start, spawn_prune(&prune_router, &holder).await.unwrap())
        }
    };
    assert_eq!(start.0, StatusCode::CREATED, "{}", start.1);
    prune_result(prune, 0);
    assert_eq!(registered(&database, &reference).await, 1);
    assert!(object(&root, &reference).exists());
    assert_eq!(runs_of(&database, "start-wins").await, 1);
    // The retained run still reads the payload's bytes.
    let (status, _, _) = request(&start_router, "GET", &format!("/api/external-payloads/v1/{}", reference["reference_id"].as_str().unwrap()),
        "worker-token", "default", Some(&reference), Body::empty()).await;
    assert_eq!(status, StatusCode::OK);
    start_node.close().await;
    prune_node.close().await;
    database.remove().await;
}

/// The prune commits first. The start has already read the registration
/// outside any transaction, and its next locked read finds it gone: the start
/// is refused, nothing is committed and no run references the deleted object.
#[tokio::test]
async fn a_prune_that_commits_first_refuses_the_concurrent_start_and_commits_nothing() {
    let temp = tempfile::tempdir().unwrap();
    let root = temp.path().join("objects");
    let (database, start_node, start_router, prune_node, prune_router) = two_nodes(&root).await;
    let reference = upload(&start_router, "default", "prune wins").await;
    let holder = closed_run(&start_router, "default", "prune-wins-holder", &reference).await;

    let (prune, start) = match HeldRows::object_locks(&database).await {
        Some(mut locks) => {
            // The prune holds the transition lock and waits on its object
            // lock. The start reads the still-registered payload, then waits
            // on the transition lock.
            let mut prune = spawn_prune(&prune_router, &holder);
            locks.until_waiting(1, OBJECT_LOCK, &mut [&mut prune]).await;
            let mut start = spawn_start(&start_router, "prune-wins", &reference);
            locks.until_waiting(2, TRANSITION, &mut [&mut prune, &mut start]).await;
            locks.release().await;
            (prune.await.unwrap(), start.await.unwrap())
        }
        None => {
            let prune = spawn_prune(&prune_router, &holder).await.unwrap();
            (prune, spawn_start(&start_router, "prune-wins", &reference).await.unwrap())
        }
    };
    prune_result(prune, 1);
    let (status, refused) = start;
    assert_eq!(status, StatusCode::NOT_FOUND, "{refused}");
    assert_eq!(refused["reason"], "external_payload_not_found", "{refused}");
    assert_eq!(runs_of(&database, "prune-wins").await, 0, "the refused start committed nothing");
    assert_eq!(count(&database, "SELECT COUNT(*) FROM workflow_instances WHERE id='prune-wins'").await, 0);
    assert_eq!(registered(&database, &reference).await, 0);
    assert!(!object(&root, &reference).exists());
    start_node.close().await;
    prune_node.close().await;
    database.remove().await;
}
