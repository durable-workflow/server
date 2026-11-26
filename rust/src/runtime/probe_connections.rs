//! One pool connection per held-poll probe, inside a reserved budget
//! (Server #1204).
//!
//! A held workflow or activity poll re-probes every 50-250 ms. Each read
//! used to borrow its own pool connection, and SQLx returns a dropped
//! connection in a spawned task that still holds the slot through its
//! return round trips. One probe could therefore pin three of the four
//! slots at once, and a database stall that began just after a probe kept
//! them pinned, so a registration or start waited out its acquisition
//! deadline (HTTP 503 `pool_timeout`).
//!
//! A probe now borrows one [`ProbeConnection`] for its lock-free reads and
//! awaits the connection's return to the pool before it claims or waits.
//! Its slot comes from a node-wide budget, held until that return finishes,
//! so held-poll work never occupies the last slot.
//!
//! Server #1355 brings the rest of a held poll's work under the same budget:
//! its admission and replay reads, query readiness reads, and its claim and
//! update-validation probe transactions. Reads take at most the pool size
//! less two slots (at least one, [`slots`]). A budgeted transaction takes its
//! turn on the transition queue first and then the budget's one transition
//! slot (`Store::queue_budgeted_transition`): a transaction holding a budget
//! slot never waits for the queue, and the queue holder never waits behind
//! probe reads. Together they take all but one connection of a pool of three
//! or more. The maintenance loop has a connection of
//! its own (`Store::for_maintenance`). Other work still competes for the last
//! connection: ordinary requests, query-task claims and duplicate query polls'
//! shared answers, recording an empty or stale poll answer, workflow lease
//! recovery transactions and polls carried by a task completion.
use super::Result;
use sqlx::pool::PoolConnection;
use std::sync::Arc;
use tokio::sync::{OwnedSemaphorePermit, Semaphore};

/// The probe read budget for a pool of `connections`: all but two, at least
/// one. With the transition slot of the transaction holding the transition
/// queue, the budget takes all but one connection (Server #1355).
pub(super) fn slots(connections: u32) -> Arc<Semaphore> {
    let connections = usize::try_from(connections).unwrap_or(usize::MAX);
    Arc::new(Semaphore::new(connections.saturating_sub(2).max(1)))
}

/// A probe's pool connection and its budget slot. [`finish`](Self::finish)
/// awaits the pool return before freeing the slot; dropping it instead (an
/// error or a cancelled poll) returns the connection in a task that also
/// frees the slot only once the return finishes.
pub(super) struct ProbeConnection<DB: sqlx::Database> {
    connection: Option<PoolConnection<DB>>,
    slot: Option<OwnedSemaphorePermit>,
}

impl<DB: sqlx::Database> ProbeConnection<DB> {
    pub(super) fn get(&mut self) -> &mut DB::Connection {
        self.connection.as_mut().expect("an unfinished probe connection")
    }

    pub(super) async fn finish(mut self) {
        if let Some(mut connection) = self.connection.take() {
            // SQLx 0.9 detaches the live connection before it returns the
            // future, so the PoolConnection left behind returns nothing on
            // drop. The exact version is pinned in Cargo.toml (`=0.9.0`).
            connection.return_to_pool().await;
        }
        self.slot.take();
    }
}

impl<DB: sqlx::Database> Drop for ProbeConnection<DB> {
    fn drop(&mut self) {
        if let Some(mut connection) = self.connection.take() {
            // The returned future owns the detached connection (see finish).
            let returned = connection.return_to_pool();
            let slot = self.slot.take();
            tokio::spawn(async move {
                returned.await;
                drop(slot);
            });
        }
    }
}

store_methods! {
    /// A probe slot and then a pool connection, both within one pool
    /// acquisition deadline; running out of it is the same typed pool
    /// refusal a timed-out acquisition returns.
    pub(super) async fn probe_connection(&self) -> Result<ProbeConnection<DB>> {
        self.budgeted_connection(&self.probe_slots).await
    }

    /// A held poll's later probe reads: as [`probe_connection`](Self::probe_connection),
    /// but the wait for a read slot lasts until the poll's own deadline
    /// (`answer_by`), however long, and is `None` once that passes. The poll
    /// then answers as at its deadline instead of waiting on other polls'
    /// reads past it (Server #1355), and a long poll whose slot wait outlasts
    /// the pool's acquisition deadline keeps waiting instead of failing
    /// (Server #1372). The connection, once the slot is held, keeps the
    /// acquisition deadline. The first probe, admission and every claim keep
    /// it for the slot too.
    pub(super) async fn probe_connection_by(&self, answer_by: tokio::time::Instant) -> Result<Option<ProbeConnection<DB>>> {
        self.budgeted_connection_by(&self.probe_slots, Some(answer_by)).await
    }

    /// The first probe's reads with the acquisition deadline, later ones with
    /// the poll's own ([`probe_connection_by`](Self::probe_connection_by)).
    pub(super) async fn probe_reads(&self, first_probe: bool, answer_by: tokio::time::Instant) -> Result<Option<ProbeConnection<DB>>> {
        if first_probe {
            self.probe_connection().await.map(Some)
        } else {
            self.probe_connection_by(answer_by).await
        }
    }

    /// A slot of `budget` and then a pool connection, within one deadline.
    pub(super) async fn budgeted_connection(&self, budget: &Arc<tokio::sync::Semaphore>) -> Result<ProbeConnection<DB>> {
        Ok(self.budgeted_connection_by(budget, None).await?.expect("no answer-by deadline"))
    }

    /// [`budgeted_connection`](Self::budgeted_connection). With `answer_by`,
    /// the slot wait ends there instead, with `None`, and the connection then
    /// has the acquisition deadline from the moment the slot is held. A slot
    /// already free is taken even after `answer_by`.
    async fn budgeted_connection_by(
        &self, budget: &Arc<tokio::sync::Semaphore>, answer_by: Option<tokio::time::Instant>,
    ) -> Result<Option<ProbeConnection<DB>>> {
        let acquire = self.pool.options().get_acquire_timeout();
        let mut deadline = tokio::time::Instant::now() + acquire;
        let slot = match answer_by {
            Some(answer_by) => {
                let slot = tokio::time::timeout_at(answer_by, budget.clone().acquire_owned()).await;
                deadline = tokio::time::Instant::now() + acquire;
                match slot {
                    Ok(slot) => slot,
                    Err(_) => return Ok(None),
                }
            }
            None => tokio::time::timeout_at(deadline, budget.clone().acquire_owned())
                .await
                .map_err(|_| sqlx::Error::PoolTimedOut)?,
        }
        .expect("the probe budget is never closed");
        let connection = tokio::time::timeout_at(deadline, self.pool.acquire())
            .await
            .map_err(|_| sqlx::Error::PoolTimedOut)??;
        Ok(Some(ProbeConnection { connection: Some(connection), slot: Some(slot) }))
    }
}

#[cfg(test)]
mod tests {
    use super::super::{QueryTaskConfig, store::Store};
    use serde_json::json;
    use std::{sync::{Arc, atomic::{AtomicUsize, Ordering}}, time::Duration};

    /// Between two arrivals at its wait point, a held activity or workflow
    /// poll's probe borrows exactly one connection for its fence and
    /// readiness reads, one more, in turn, for the cross-kind queue-changed
    /// read when the worker opted into it, and for a workflow poll one more
    /// for the abandoned-lease recovery reads (Server #1204). Every one is
    /// borrowed while the probe holds the budget's slot. The reader pool has
    /// a single connection, so a read that borrowed a second connection while
    /// the probe held the first would time out instead.
    #[cfg(debug_assertions)]
    #[tokio::test]
    async fn sqlite_held_poll_probe_borrows_one_budgeted_connection_at_a_time() {
        use super::super::poll_pauses::{PollPoint, pause_workflow_poll};
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("probe.sqlite");
        let writer = Store::<sqlx::Sqlite>::new(super::super::sqlite::open(path.to_str().unwrap()).await.unwrap());
        let checkouts = Arc::new(AtomicUsize::new(0));
        let unbudgeted = Arc::new(AtomicUsize::new(0));
        let budget = super::slots(1);
        let options = sqlx::sqlite::SqlitePoolOptions::new().max_connections(1).min_connections(1)
            .acquire_timeout(Duration::from_secs(2))
            .before_acquire({
                let (checkouts, unbudgeted, budget) = (checkouts.clone(), unbudgeted.clone(), budget.clone());
                move |_, _| {
                    checkouts.fetch_add(1, Ordering::SeqCst);
                    if budget.available_permits() > 0 {
                        unbudgeted.fetch_add(1, Ordering::SeqCst);
                    }
                    Box::pin(async { Ok(true) })
                }
            });
        let pool = options.connect_with(sqlx::sqlite::SqliteConnectOptions::new().filename(&path)
            .busy_timeout(Duration::from_secs(5))).await.unwrap();
        let mut store = Store::new(pool);
        store.probe_slots = budget;
        for (kind, worker, capabilities, expected) in [
            ("activity", "probe-worker", json!([]), 1),
            ("activity", "probe-wake-worker", json!(["cross_kind_poll_wake"]), 2),
            ("workflow", "probe-workflow-worker", json!([]), 2),
            ("workflow", "probe-workflow-wake-worker", json!(["cross_kind_poll_wake"]), 3),
        ] {
            writer.register(json!({"worker_id":worker,"task_queue":worker,"runtime":"php",
                "supported_workflow_types":["probe"],"supported_activity_types":["act"],"capabilities":capabilities}),
                &QueryTaskConfig::default(), Some("1.20")).await.unwrap();
            let request = format!("probe-request-{}", ulid::Ulid::new());
            let first = pause_workflow_poll(&request, PollPoint::Waiting);
            let body = json!({"worker_id":worker,"task_queue":worker,"timeout_seconds":2,"poll_request_id":request});
            let (answer, (probe, outside)) = tokio::join!(store.poll(body, kind, "1.20"), async {
                first.reached.await.unwrap();
                checkouts.store(0, Ordering::SeqCst);
                unbudgeted.store(0, Ordering::SeqCst);
                let second = pause_workflow_poll(&request, PollPoint::Waiting);
                first.release.send(()).unwrap();
                second.reached.await.unwrap();
                let counts = (checkouts.load(Ordering::SeqCst), unbudgeted.load(Ordering::SeqCst));
                second.release.send(()).unwrap();
                counts
            });
            assert_eq!(answer.unwrap()["poll_status"], "empty", "{worker}");
            assert_eq!(probe, expected, "{worker}: connections a held probe borrows, one at a time");
            assert_eq!(outside, 0, "{worker}: connections a held probe borrows outside the budget");
        }
        store.close().await;
        writer.close().await;
    }

    /// A store whose one request connection counts each checkout made while
    /// neither the read budget's slot nor the transition slot is held.
    async fn counted_store(path: &std::path::Path) -> (Store<sqlx::Sqlite>, Arc<AtomicUsize>) {
        let unbudgeted = Arc::new(AtomicUsize::new(0));
        let (reads, transition) = (super::slots(1), Arc::new(tokio::sync::Semaphore::new(1)));
        let options = sqlx::sqlite::SqlitePoolOptions::new().max_connections(1).min_connections(1)
            .acquire_timeout(Duration::from_secs(2))
            .before_acquire({
                let (unbudgeted, reads, transition) = (unbudgeted.clone(), reads.clone(), transition.clone());
                move |_, _| {
                    if reads.available_permits() > 0 && transition.available_permits() > 0 {
                        unbudgeted.fetch_add(1, Ordering::SeqCst);
                    }
                    Box::pin(async { Ok(true) })
                }
            });
        let pool = options.connect_with(sqlx::sqlite::SqliteConnectOptions::new().filename(path)
            .busy_timeout(Duration::from_secs(5))).await.unwrap();
        let mut store = Store::new(pool);
        store.probe_slots = reads;
        store.transition_slot = transition;
        (store, unbudgeted)
    }

    /// Server #1355: every connection a held poll borrows, from admission to
    /// its answer, comes from the budget: its admission and replay reads,
    /// a workflow claim, query readiness reads and an update-validation
    /// poll's probe transactions. Each case fails if its path borrows from
    /// the pool directly. A stall qualification cannot tell: with a read
    /// budget of one, one stray connection still leaves producers the last.
    #[tokio::test]
    async fn sqlite_held_poll_work_borrows_only_budgeted_connections() {
        use super::super::poll_pauses::{PollPoint, pause_workflow_poll};
        use crate::codec::{Value as Payload, ValueCodec};
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("budgeted.sqlite");
        let writer = Store::<sqlx::Sqlite>::new(super::super::sqlite::open(path.to_str().unwrap()).await.unwrap());
        let (store, unbudgeted) = counted_store(&path).await;
        let config = QueryTaskConfig::default();
        let register = |worker: &str, capabilities: serde_json::Value| json!({"worker_id":worker,"task_queue":worker,
            "runtime":"php","supported_workflow_types":["probe"],"supported_activity_types":["act"],
            "capabilities":capabilities,
            "workflow_command_contracts":{"probe":{"queries":["probe"],"query_contracts":[{"name":"probe","parameters":[]}]}}});
        let blob = ValueCodec::new().unwrap().encode(&Payload::Array(vec![])).unwrap();
        let start = |queue: &str| json!({"workflow_id":format!("{queue}-run"),"workflow_type":"probe","task_queue":queue,
            "input":{"codec":"avro","blob":blob}});

        // Admission, request replay and an activity poll's first claim
        // attempt: up to the poll's first wait.
        writer.register(register("admitted", json!([])), &config, Some("1.20")).await.unwrap();
        let request = format!("admitted-{}", ulid::Ulid::new());
        let waiting = pause_workflow_poll(&request, PollPoint::Waiting);
        let body = json!({"worker_id":"admitted","task_queue":"admitted","timeout_seconds":1,"poll_request_id":request});
        unbudgeted.store(0, Ordering::SeqCst);
        let (answer, admitted) = tokio::join!(store.poll(body, "activity", "1.20"), async {
            waiting.reached.await.unwrap();
            let admitted = unbudgeted.load(Ordering::SeqCst);
            waiting.release.send(()).unwrap();
            admitted
        });
        assert_eq!(answer.unwrap()["poll_status"], "empty");
        assert_eq!(admitted, 0, "admission and the first probe borrow only budgeted connections");

        // A workflow poll's first probe under a request ID, which replays an
        // earlier answer or claims.
        writer.register(register("claiming", json!([])), &config, Some("1.20")).await.unwrap();
        writer.start(start("claiming"), Default::default(), None, None).await.unwrap();
        unbudgeted.store(0, Ordering::SeqCst);
        let answer = store.poll(json!({"worker_id":"claiming","task_queue":"claiming","timeout_seconds":1,
            "poll_request_id":format!("claiming-{}", ulid::Ulid::new())}), "workflow", "1.20").await.unwrap();
        assert_eq!(answer["poll_status"], "leased");
        assert_eq!(unbudgeted.load(Ordering::SeqCst), 0, "a claim borrows only the transition slot's connection");

        // Query readiness reads, from the first readiness check (after the
        // poll's first, unbudgeted query-task claim) to a poll that found
        // nothing.
        writer.register(register("querying", json!(["query_tasks"])), &config, Some("1.20")).await.unwrap();
        let readiness = pause_workflow_poll("querying", PollPoint::QueryReadiness);
        let (answer, ()) = tokio::join!(
            store.poll_query(json!({"worker_id":"querying","task_queue":"querying","timeout_seconds":1}), &config),
            async {
                readiness.reached.await.unwrap();
                unbudgeted.store(0, Ordering::SeqCst);
                readiness.release.send(()).unwrap();
            });
        answer.unwrap();
        assert_eq!(unbudgeted.load(Ordering::SeqCst), 0, "query readiness borrows only budgeted connections");

        // An update-validation poll's admission and probe transactions.
        writer.register(register("validating", json!([])), &config, Some("1.20")).await.unwrap();
        writer.start(start("validating"), Default::default(), None, None).await.unwrap();
        unbudgeted.store(0, Ordering::SeqCst);
        let leased = store.poll_task_kinds(&json!({"worker_id":"validating","task_queue":"validating","timeout_seconds":1}),
            &["workflow", "update_validation"], "1.20", Default::default(), 1, 30).await.unwrap();
        assert!(matches!(&leased, super::super::update_validation::Leased::Workflow(answer) if answer["poll_status"] == "leased"));
        assert_eq!(unbudgeted.load(Ordering::SeqCst), 0, "update validation borrows only budgeted connections");

        store.close().await;
        writer.close().await;
    }

    /// Server #1355: once a held poll has probed, its later probes' read
    /// slot waits end at its own deadline. With another probe holding the
    /// read slot, a poll answers empty at its deadline instead of waiting
    /// for the slot past it (and failing with the typed pool timeout five
    /// seconds later). Its first probe, claims and request replay are not
    /// bounded this way.
    #[cfg(debug_assertions)]
    #[tokio::test]
    async fn sqlite_a_later_probe_answers_at_the_deadline_while_another_probe_holds_the_read_slot() {
        use super::super::poll_pauses::{PollPoint, pause_workflow_poll};
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("deadline.sqlite");
        let writer = Store::<sqlx::Sqlite>::new(super::super::sqlite::open(path.to_str().unwrap()).await.unwrap());
        let (store, _) = counted_store(&path).await;
        let config = QueryTaskConfig::default();
        for (kind, point) in [("activity", PollPoint::Waiting), ("workflow", PollPoint::Waiting), ("query", PollPoint::QueryReadiness)] {
            let worker = format!("deadline-{kind}");
            writer.register(json!({"worker_id":worker,"task_queue":worker,"runtime":"php",
                "supported_workflow_types":["probe"],"supported_activity_types":["act"],
                "capabilities": if kind == "query" { json!(["query_tasks"]) } else { json!([]) },
                "workflow_command_contracts":{"probe":{"queries":["probe"],"query_contracts":[{"name":"probe","parameters":[]}]}}}),
                &config, Some("1.20")).await.unwrap();
            let request = format!("{worker}-{}", ulid::Ulid::new());
            let pause = pause_workflow_poll(if kind == "query" { &worker } else { &request }, point);
            let body = json!({"worker_id":worker,"task_queue":worker,"timeout_seconds":1,"poll_request_id":request});
            let started = tokio::time::Instant::now();
            let poll = async {
                if kind == "query" {
                    let mut body = body.clone();
                    body.as_object_mut().unwrap().remove("poll_request_id");
                    store.poll_query(body, &config).await
                } else {
                    store.poll(body.clone(), kind, "1.20").await
                }
            };
            let (answer, held) = tokio::join!(poll, async {
                pause.reached.await.unwrap();
                let held = store.probe_slots.clone().acquire_owned().await.unwrap();
                pause.release.send(()).unwrap();
                held
            });
            let answered = started.elapsed();
            drop(held);
            assert_eq!(answer.unwrap()["poll_status"], "empty", "{kind}");
            assert!(answered >= Duration::from_millis(900) && answered < Duration::from_millis(1500),
                "{kind}: answered at its deadline, not after waiting for the read slot: {answered:?}");
        }
        store.close().await;
        writer.close().await;
    }

    /// Server #1372: a long poll's later probe that waits longer than the
    /// pool's acquisition deadline for a read slot, with time left, keeps
    /// waiting instead of failing the poll with the typed pool timeout (HTTP
    /// 503). Here the slot is held for longer than the two-second
    /// acquisition deadline, then a workflow starts and the slot frees: the
    /// poll leases it.
    #[cfg(debug_assertions)]
    #[tokio::test]
    async fn sqlite_a_long_poll_keeps_waiting_for_a_read_slot_past_the_acquisition_deadline() {
        use super::super::poll_pauses::{PollPoint, pause_workflow_poll};
        use crate::codec::{Value as Payload, ValueCodec};
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("long-wait.sqlite");
        let writer = Store::<sqlx::Sqlite>::new(super::super::sqlite::open(path.to_str().unwrap()).await.unwrap());
        let (store, _) = counted_store(&path).await;
        let acquire = store.pool.options().get_acquire_timeout();
        assert_eq!(acquire, Duration::from_secs(2));
        writer.register(json!({"worker_id":"long-wait","task_queue":"long-wait","runtime":"php",
            "supported_workflow_types":["probe"],"supported_activity_types":[]}), &QueryTaskConfig::default(), Some("1.20")).await.unwrap();
        let request = format!("long-wait-{}", ulid::Ulid::new());
        let waiting = pause_workflow_poll(&request, PollPoint::Waiting);
        let body = json!({"worker_id":"long-wait","task_queue":"long-wait","timeout_seconds":6,"poll_request_id":request});
        let blob = ValueCodec::new().unwrap().encode(&Payload::Array(vec![])).unwrap();
        let started = tokio::time::Instant::now();
        let poll = async {
            let answer = store.poll(body, "workflow", "1.20").await;
            (answer, started.elapsed())
        };
        let ((answer, answered), freed) = tokio::join!(poll, async {
            waiting.reached.await.unwrap();
            let held = store.probe_slots.clone().acquire_owned().await.unwrap();
            waiting.release.send(()).unwrap();
            tokio::time::sleep(acquire + Duration::from_millis(1500)).await;
            writer.start(json!({"workflow_id":"long-wait-run","workflow_type":"probe","task_queue":"long-wait",
                "input":{"codec":"avro","blob":blob}}), Default::default(), None, None).await.unwrap();
            drop(held);
            started.elapsed()
        });
        let answer = answer.expect("a slot wait past the acquisition deadline is not a pool timeout");
        assert_eq!(answer["poll_status"], "leased", "{answer}");
        assert!(freed > acquire + Duration::from_secs(1), "the slot was held past the acquisition deadline: {freed:?}");
        assert!(answered >= freed, "the poll was still waiting for the slot when it freed: answered at {answered:?}, freed at {freed:?}");
        assert!(answered < freed + Duration::from_secs(1), "and leased promptly once it freed: answered at {answered:?}, freed at {freed:?}");
        store.close().await;
        writer.close().await;
    }

    #[test]
    fn the_read_budget_leaves_two_slots_and_never_reaches_zero() {
        for (connections, budget) in [(0, 1), (1, 1), (2, 1), (3, 1), (4, 2), (10, 8)] {
            assert_eq!(super::slots(connections).available_permits(), budget, "{connections}");
        }
    }
}
