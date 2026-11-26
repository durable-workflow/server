//! Database-stall qualifications (Server #1204, #1355). A reply gate between
//! the runtime pool and MySQL/MariaDB stops the database from answering
//! while held polls, claims and maintenance work have statements in flight;
//! a registration that arrives during the stall must wait it out and
//! succeed, never be refused with the typed pool timeout (HTTP 503
//! `pool_timeout`) after the five-second acquisition deadline.
#![cfg(debug_assertions)]
use super::pool_tests::{ReplyGate, forward};
use super::*;
use super::super::{QueryTaskConfig, poll_pauses::{PollPoint, pause_workflow_poll}, store::Store};
use serde_json::{Value, json};
use sqlx::{ConnectOptions, MySql, migrate::MigrateDatabase};
use std::{str::FromStr, sync::{Arc, atomic::Ordering}};
use tokio::{net::TcpListener, sync::oneshot, task::JoinHandle, time::Instant};

/// How long every qualification here keeps the database from answering.
const STALL: Duration = Duration::from_secs(6);

fn registration(worker: &str) -> Value {
    json!({"worker_id":worker,"task_queue":worker,"runtime":"php",
        "supported_workflow_types":["stall"],"supported_activity_types":["act"]})
}

/// A runtime store behind a reply gate, and the polls it holds.
struct Stall {
    database: String,
    database_url: String,
    gate: Arc<ReplyGate>,
    proxy: JoinHandle<()>,
    pool: sqlx::MySqlPool,
    store: Arc<Store<MySql>>,
    polls: Vec<JoinHandle<Result<Value>>>,
    releases: Vec<oneshot::Sender<()>>,
    resume: Option<JoinHandle<()>>,
}

impl Stall {
    async fn open() -> Self {
        let database = format!("dw_stall_{}", ulid::Ulid::new().to_string().to_lowercase());
        let direct = MySqlConnectOptions::from_str(&std::env::var("DW_TEST_MYSQL_URL").unwrap())
            .unwrap()
            .database(&database);
        let database_url = direct.to_url_lossy().to_string();
        MySql::create_database(&database_url).await.unwrap();
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let port = listener.local_addr().unwrap().port();
        let gate = Arc::new(ReplyGate::default());
        let proxy = tokio::spawn(forward(
            listener,
            format!("{}:{}", direct.get_host(), direct.get_port()),
            gate.clone(),
        ));
        let pool = MySqlStorage::open(direct.host("127.0.0.1").port(port)).await.unwrap().into_pool();
        let store = Arc::new(Store::new(pool.clone()));
        Self { database, database_url, gate, proxy, pool, store, polls: Vec::new(), releases: Vec::new(), resume: None }
    }

    async fn register(&self, worker: &str) -> Result<Value> {
        self.store.register(registration(worker), &QueryTaskConfig::default(), Some("1.20")).await
    }

    /// Starts an activity poll for `worker` that stops at `point`, and waits
    /// until it does; [`stall`](Self::stall) lets it go on.
    async fn hold_poll(&mut self, worker: &str, point: PollPoint) {
        let request = format!("{}-{worker}", self.database);
        let pause = pause_workflow_poll(&request, point);
        let body = json!({"worker_id":worker,"task_queue":worker,"timeout_seconds":8,"poll_request_id":request});
        let store = self.store.clone();
        self.polls.push(tokio::spawn(async move { store.poll(body, "activity", "1.20").await }));
        pause.reached.await.unwrap();
        self.releases.push(pause.release);
    }

    /// Registers `count` workers and holds one activity poll each at its
    /// wait point, right after a probe.
    async fn hold_waiting_polls(&mut self, count: usize) {
        for n in 0..count {
            let worker = format!("stall-{n}");
            self.register(&worker).await.unwrap();
            self.hold_poll(&worker, PollPoint::Waiting).await;
        }
    }

    /// Opens every connection but the `held` ones paused work keeps, and
    /// returns each at once: no connection needs opening during the stall,
    /// and a checkout right after this does not ping first.
    async fn warm(&self, held: u32) {
        let max = self.pool.options().get_max_connections();
        let mut warmed = Vec::new();
        for _ in held..max {
            warmed.push(self.pool.acquire().await.unwrap());
        }
        for mut connection in warmed {
            connection.return_to_pool().await;
        }
        assert_eq!(self.pool.size(), max, "every connection is open before the stall");
    }

    /// The database stops answering, the held polls go on into the stall,
    /// and the database answers again [`STALL`] after this returns its start.
    async fn stall(&mut self) -> Instant {
        self.gate.hold.store(true, Ordering::SeqCst);
        let stalled = Instant::now();
        for release in self.releases.drain(..) {
            release.send(()).unwrap();
        }
        tokio::time::sleep(Duration::from_millis(300)).await;
        let gate = self.gate.clone();
        self.resume = Some(tokio::spawn(async move {
            tokio::time::sleep_until(stalled + STALL).await;
            gate.release();
        }));
        stalled
    }

    /// Waits for the stall to end, the polls to answer and every connection
    /// to return, then removes everything; whether the connections returned.
    /// Callers assert only after this, so a failure still cleans up.
    async fn close(self) -> bool {
        if let Some(resume) = self.resume {
            resume.await.unwrap();
        }
        self.gate.release();
        for poll in self.polls {
            // A poll that found no connection within its deadline answers the
            // typed pool refusal; the others answer normally.
            let _ = poll.await.unwrap();
        }
        let returned = tokio::time::timeout(Duration::from_secs(5), async {
            while self.pool.num_idle() != self.pool.size() as usize {
                tokio::time::sleep(Duration::from_millis(20)).await;
            }
        })
        .await
        .is_ok();
        self.store.close().await;
        self.proxy.abort();
        MySql::drop_database(&self.database_url).await.unwrap();
        returned
    }
}

/// Registers `worker` during the stall that began at `stalled`, then cleans
/// up and requires that the registration waited out the stall and succeeded.
async fn register_through(stall: Stall, stalled: Instant, worker: &str) {
    let registered = stall.register(worker).await;
    let waited = stalled.elapsed();
    let returned = stall.close().await;
    assert!(
        registered.is_ok(),
        "a registration during a stall must succeed: {registered:?} after {waited:?}"
    );
    assert!(waited >= STALL, "the registration waited out the stall");
    assert!(returned, "every connection returns after the stall");
}

/// Server #1204: as many held activity polls as the pool has connections
/// run their next probe into the stall. Held probes take at most all but one
/// connection, so the registration borrows the last one.
#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
#[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
async fn qualification_held_polls_leave_registration_a_connection_during_a_stall() {
    tokio::time::timeout(Duration::from_secs(60), async {
        let mut stall = Stall::open().await;
        stall.hold_waiting_polls(stall.pool.options().get_max_connections() as usize).await;
        stall.warm(0).await;
        let stalled = stall.stall().await;
        register_through(stall, stalled, "stall-0").await;
    })
    .await
    .expect("bounded held-poll stall qualification");
}

/// Server #1355, idle connection: every connection has been idle for over a
/// second, so each checkout pings first, and the held probes fill their
/// budget. The registration's checkout of the last connection must not wait
/// out the stall inside SQLx's acquisition deadline.
#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
#[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
async fn qualification_registration_survives_a_stall_on_an_idle_last_connection() {
    tokio::time::timeout(Duration::from_secs(60), async {
        let mut stall = Stall::open().await;
        stall.hold_waiting_polls(stall.store.probe_slots.available_permits()).await;
        stall.warm(0).await;
        tokio::time::sleep(Duration::from_millis(1200)).await;
        let stalled = stall.stall().await;
        register_through(stall, stalled, "stall-0").await;
    })
    .await
    .expect("bounded idle-connection stall qualification");
}
