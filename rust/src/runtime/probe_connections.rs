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

    /// A slot of `budget` and then a pool connection, within one deadline.
    pub(super) async fn budgeted_connection(&self, budget: &Arc<tokio::sync::Semaphore>) -> Result<ProbeConnection<DB>> {
        let deadline = tokio::time::Instant::now() + self.pool.options().get_acquire_timeout();
        let slot = tokio::time::timeout_at(deadline, budget.clone().acquire_owned())
            .await
            .map_err(|_| sqlx::Error::PoolTimedOut)?
            .expect("the probe budget is never closed");
        let connection = tokio::time::timeout_at(deadline, self.pool.acquire())
            .await
            .map_err(|_| sqlx::Error::PoolTimedOut)??;
        Ok(ProbeConnection { connection: Some(connection), slot: Some(slot) })
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

    #[test]
    fn the_read_budget_leaves_two_slots_and_never_reaches_zero() {
        for (connections, budget) in [(0, 1), (1, 1), (2, 1), (3, 1), (4, 2), (10, 8)] {
            assert_eq!(super::slots(connections).available_permits(), budget, "{connections}");
        }
    }
}
