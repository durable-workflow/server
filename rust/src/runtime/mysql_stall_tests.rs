//! Database-stall qualifications (Server #1204, #1355). A reply gate between
//! the runtime pool and MySQL/MariaDB stops the database from answering
//! while held polls, claims and maintenance work have statements in flight;
//! a registration that arrives during the stall must wait it out and
//! succeed, never be refused with the typed pool timeout (HTTP 503
//! `pool_timeout`) after the five-second acquisition deadline.
#![cfg(debug_assertions)]
use super::super::test_reply_gate::{ReplyGate, forward};
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

/// Drops the qualification's database when dropped, unless it already was:
/// a qualification that runs past its timeout still removes its database.
struct DatabaseGuard {
    url: String,
    dropped: bool,
}

impl Drop for DatabaseGuard {
    fn drop(&mut self) {
        if self.dropped {
            return;
        }
        let url = self.url.clone();
        // The dropping runtime may be shutting down, and a paused poll may
        // still hold locks that only its runtime's shutdown releases, so the
        // drop runs on a detached thread with a runtime of its own and a
        // bounded metadata-lock wait.
        std::thread::spawn(move || {
            let runtime = tokio::runtime::Builder::new_current_thread().enable_all().build().unwrap();
            runtime.block_on(async {
                let options = MySqlConnectOptions::from_str(&url).unwrap();
                let name = options.get_database().unwrap_or_default().to_owned();
                let Ok(mut connection) = MySqlConnection::connect_with(&options).await else { return };
                let _ = connection.execute("SET SESSION lock_wait_timeout=60").await;
                let _ = connection.execute(AssertSqlSafe(format!("DROP DATABASE IF EXISTS `{name}`"))).await;
            });
        });
    }
}

/// A runtime store behind a reply gate, and the polls it holds.
struct Stall {
    database: String,
    database_guard: DatabaseGuard,
    gate: Arc<ReplyGate>,
    proxy: JoinHandle<()>,
    pool: sqlx::MySqlPool,
    store: Arc<Store<MySql>>,
    polls: Vec<JoinHandle<Result<Value>>>,
    releases: Vec<oneshot::Sender<()>>,
    resume: Option<JoinHandle<()>>,
    tasks: Vec<JoinHandle<()>>,
}

impl Stall {
    async fn open() -> Self {
        let database = format!("dw_stall_{}", ulid::Ulid::new().to_string().to_lowercase());
        let direct = MySqlConnectOptions::from_str(&std::env::var("DW_TEST_MYSQL_URL").unwrap())
            .unwrap()
            .database(&database);
        let database_url = direct.to_url_lossy().to_string();
        MySql::create_database(&database_url).await.unwrap();
        let database_guard = DatabaseGuard { url: database_url, dropped: false };
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let port = listener.local_addr().unwrap().port();
        let gate = Arc::new(ReplyGate::default());
        let proxy = tokio::spawn(forward(
            listener,
            format!("{}:{}", direct.get_host(), direct.get_port()),
            gate.clone(),
        ));
        let pool = MySqlStorage::open(direct.host("127.0.0.1").port(port)).await.unwrap().into_pool();
        let store = Arc::new(Store::new(pool.clone()).with_maintenance_pool());
        Self { database, database_guard, gate, proxy, pool, store, polls: Vec::new(), releases: Vec::new(), resume: None, tasks: Vec::new() }
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
    async fn close(mut self) -> bool {
        if let Some(resume) = self.resume {
            resume.await.unwrap();
        }
        self.gate.release();
        for task in self.tasks {
            task.await.unwrap();
        }
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
        MySql::drop_database(&self.database_guard.url).await.unwrap();
        self.database_guard.dropped = true;
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

/// Server #1355, stuck transition: a held activity poll's claim holds the
/// transition queue and its connection when the database stops answering.
/// The registration queued behind it waits out the stall with it instead of
/// being refused at the pool's five-second acquisition deadline.
#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
#[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
async fn qualification_registration_waits_out_a_transition_stuck_in_a_stall() {
    tokio::time::timeout(Duration::from_secs(60), async {
        let mut stall = Stall::open().await;
        stall.register("stall-claim").await.unwrap();
        stall.hold_poll("stall-claim", PollPoint::Claiming).await;
        // The paused claim keeps its connection.
        stall.warm(1).await;
        let stalled = stall.stall().await;
        register_through(stall, stalled, "stall-other").await;
    })
    .await
    .expect("bounded stuck-transition stall qualification");
}

/// Server #1355, work outside the probe budget: held probes fill the budget,
/// then during the stall a new activity poll is admitted and the maintenance
/// loop ticks. Neither may take the request pool's last connection.
#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
#[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
async fn qualification_admission_and_maintenance_leave_registration_a_connection_during_a_stall() {
    tokio::time::timeout(Duration::from_secs(60), async {
        let mut stall = Stall::open().await;
        stall.hold_waiting_polls(stall.store.probe_slots.available_permits()).await;
        stall.register("stall-late").await.unwrap();
        stall.warm(0).await;
        let stalled = stall.stall().await;
        let late = json!({"worker_id":"stall-late","task_queue":"stall-late","timeout_seconds":2});
        let store = stall.store.clone();
        stall.polls.push(tokio::spawn(async move { store.poll(late, "activity", "1.20").await }));
        // The maintenance loop's 250 ms tick, through the stall.
        let store = stall.store.clone();
        stall.tasks.push(tokio::spawn(async move {
            while Instant::now() < stalled + STALL {
                let _ = store.for_maintenance().fire_due_timers().await;
                tokio::time::sleep(Duration::from_millis(250)).await;
            }
        }));
        tokio::time::sleep(Duration::from_millis(300)).await;
        register_through(stall, stalled, "stall-0").await;
    })
    .await
    .expect("bounded admission and maintenance stall qualification");
}

/// Server #1355, budgeted claim: a held activity poll's claim is inside its
/// transaction (holding the transition queue and the budget's transition
/// slot) and held probes fill the read budget when the database stops
/// answering; the maintenance loop ticks through the stall. The registration
/// queued behind the claim waits it out and succeeds.
#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
#[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
async fn qualification_budgeted_claim_and_probes_leave_registration_a_connection_during_a_stall() {
    tokio::time::timeout(Duration::from_secs(60), async {
        let mut stall = Stall::open().await;
        // Registrations and first probes queue behind the paused claim, so
        // the probes are held before the claim is.
        stall.register("stall-claim").await.unwrap();
        stall.hold_waiting_polls(stall.store.probe_slots.available_permits()).await;
        stall.hold_poll("stall-claim", PollPoint::Claiming).await;
        stall.warm(1).await;
        let stalled = stall.stall().await;
        let store = stall.store.clone();
        stall.tasks.push(tokio::spawn(async move {
            while Instant::now() < stalled + STALL {
                let _ = store.for_maintenance().fire_due_timers().await;
                tokio::time::sleep(Duration::from_millis(250)).await;
            }
        }));
        register_through(stall, stalled, "stall-other").await;
    })
    .await
    .expect("bounded budgeted-claim stall qualification");
}

/// Server #1355, query readiness: held probes fill the read budget, and a
/// held query-task poll starts its readiness reads during the stall. They
/// must not take the request pool's last connection.
#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
#[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
async fn qualification_query_readiness_leaves_registration_a_connection_during_a_stall() {
    tokio::time::timeout(Duration::from_secs(60), async {
        let mut stall = Stall::open().await;
        let config = QueryTaskConfig::default();
        stall.store.register(json!({"worker_id":"stall-query","task_queue":"stall-query","runtime":"php",
            "supported_workflow_types":["stall"],"supported_activity_types":[],"capabilities":["query_tasks"],
            "workflow_command_contracts":{"stall":{"queries":["stall"],"query_contracts":[{"name":"stall","parameters":[]}]}}}),
            &config, Some("1.20")).await.unwrap();
        let pause = pause_workflow_poll("stall-query", PollPoint::QueryReadiness);
        let store = stall.store.clone();
        let body = json!({"worker_id":"stall-query","task_queue":"stall-query","timeout_seconds":8});
        stall.polls.push(tokio::spawn(async move { store.poll_query(body, &QueryTaskConfig::default()).await }));
        pause.reached.await.unwrap();
        stall.releases.push(pause.release);
        stall.hold_waiting_polls(stall.store.probe_slots.available_permits()).await;
        stall.warm(0).await;
        let stalled = stall.stall().await;
        register_through(stall, stalled, "stall-0").await;
    })
    .await
    .expect("bounded query-readiness stall qualification");
}

/// Server #1355, update-validation probe: a workflow poll that also takes
/// update-validation tasks runs each probe as a transaction. Its probe is
/// inside that transaction (holding the transition queue and the budget's
/// transition slot) and held probes fill the read budget when the database
/// stops answering; the registration queued behind it waits it out.
#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
#[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
async fn qualification_update_validation_probe_leaves_registration_a_connection_during_a_stall() {
    tokio::time::timeout(Duration::from_secs(60), async {
        let mut stall = Stall::open().await;
        stall.register("stall-validation").await.unwrap();
        stall.hold_waiting_polls(stall.store.probe_slots.available_permits()).await;
        let request = format!("{}-stall-validation", stall.database);
        let pause = pause_workflow_poll(&request, PollPoint::Claiming);
        let store = stall.store.clone();
        let body = json!({"worker_id":"stall-validation","task_queue":"stall-validation","timeout_seconds":8,"poll_request_id":request});
        stall.polls.push(tokio::spawn(async move {
            store.poll_task_kinds(&body, &["workflow", "update_validation"], "1.20", Default::default(), 8, 30).await
                .map(|_| Value::Null)
        }));
        pause.reached.await.unwrap();
        stall.releases.push(pause.release);
        stall.warm(1).await;
        let stalled = stall.stall().await;
        register_through(stall, stalled, "stall-other").await;
    })
    .await
    .expect("bounded update-validation stall qualification");
}

/// Server #1355, silent peer: a connection handed out after its pre-checkout
/// ping went unanswered must see a reply within the bound, or its statement
/// fails and the connection is replaced; it never holds the request until
/// the operating system gives up on the peer. The proxy holds every reply
/// from the moment the pool's only connection has been idle for a second.
#[tokio::test(flavor = "multi_thread", worker_threads = 2)]
#[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
async fn qualification_a_connection_whose_ping_went_unanswered_fails_within_its_reply_bound() {
    let bound = Duration::from_secs(3);
    tokio::time::timeout(Duration::from_secs(30), async {
        let direct = MySqlConnectOptions::from_str(&std::env::var("DW_TEST_MYSQL_URL").unwrap()).unwrap();
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let port = listener.local_addr().unwrap().port();
        let gate = Arc::new(ReplyGate::default());
        let proxy = tokio::spawn(forward(listener, format!("{}:{}", direct.get_host(), direct.get_port()), gate.clone()));
        let pool = super::super::backend::test_idle_connections_within(
            sqlx::mysql::MySqlPoolOptions::new().max_connections(1).acquire_timeout(Duration::from_secs(5)),
            super::super::backend::CHECKOUT_PING_BUDGET,
            bound,
        )
        .connect_with(direct.host("127.0.0.1").port(port))
        .await
        .unwrap();
        let mut connection = pool.acquire().await.unwrap();
        let silent: i64 = sqlx::query_scalar("SELECT CAST(CONNECTION_ID() AS SIGNED)")
            .fetch_one(&mut *connection).await.unwrap();
        connection.return_to_pool().await;
        tokio::time::sleep(Duration::from_millis(1200)).await;
        gate.hold.store(true, Ordering::SeqCst);
        let started = Instant::now();
        let outcome = async {
            let mut connection = pool.acquire().await?;
            sqlx::query_scalar::<_, i64>("SELECT 1").fetch_one(&mut *connection).await
        }
        .await;
        let waited = started.elapsed();
        gate.release();
        assert!(matches!(&outcome, Err(sqlx::Error::Io(error)) if error.kind() == std::io::ErrorKind::TimedOut),
            "a silent peer fails the statement: {outcome:?}");
        let ceiling = super::super::backend::CHECKOUT_PING_BUDGET + bound + Duration::from_secs(2);
        assert!(waited < ceiling, "the statement failed within the bound: {waited:?}");
        assert!(waited >= super::super::backend::CHECKOUT_PING_BUDGET + bound - Duration::from_millis(100));
        let mut replacement = tokio::time::timeout(Duration::from_secs(10), pool.acquire()).await
            .expect("the silent connection's slot returns").unwrap();
        let next: i64 = sqlx::query_scalar("SELECT CAST(CONNECTION_ID() AS SIGNED)")
            .fetch_one(&mut *replacement).await.unwrap();
        assert_ne!(next, silent, "the silent connection was discarded");
        drop(replacement);
        pool.close().await;
        proxy.abort();
    })
    .await
    .expect("bounded silent-peer qualification");
    assert!(super::super::backend::UNANSWERED_PING_REPLY_BOUND
        > super::super::store::TRANSITION_QUEUE_DEADLINE + Duration::from_secs(5));
}
