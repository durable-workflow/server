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
//! Its slot comes from a node-wide budget of the pool size less one (at
//! least one), held until that return finishes, so held-poll probes can
//! never occupy the last slot: producers and registrations always find one.
//! A claim is a transition and keeps its own connection outside the budget,
//! acquired only after the probe connection is back, so the transition
//! FIFO is never entered while holding a probe slot.
use super::Result;
use sqlx::pool::PoolConnection;
use std::sync::Arc;
use tokio::sync::{OwnedSemaphorePermit, Semaphore};

/// The probe budget for a pool of `connections`: all but one, at least one.
pub(super) fn slots(connections: u32) -> Arc<Semaphore> {
    let connections = usize::try_from(connections).unwrap_or(usize::MAX);
    Arc::new(Semaphore::new(connections.saturating_sub(1).max(1)))
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
            connection.return_to_pool().await;
        }
        self.slot.take();
    }
}

impl<DB: sqlx::Database> Drop for ProbeConnection<DB> {
    fn drop(&mut self) {
        if let Some(mut connection) = self.connection.take() {
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
    /// A probe slot and then a pool connection, each within the pool's
    /// acquisition deadline; a timed-out slot wait is the same typed pool
    /// refusal a timed-out acquisition returns.
    pub(super) async fn probe_connection(&self) -> Result<ProbeConnection<DB>> {
        let slot = tokio::time::timeout(
            self.pool.options().get_acquire_timeout(),
            self.probe_slots.clone().acquire_owned(),
        )
        .await
        .map_err(|_| sqlx::Error::PoolTimedOut)?
        .expect("the probe budget is never closed");
        let connection = self.pool.acquire().await?;
        Ok(ProbeConnection { connection: Some(connection), slot: Some(slot) })
    }
}

#[cfg(test)]
mod tests {
    use super::super::{QueryTaskConfig, describe_snapshot_tests::reader_pool, store::Store};
    use serde_json::json;
    use std::{sync::{Arc, atomic::{AtomicUsize, Ordering}}, time::Duration};

    /// Between two arrivals at its wait point, a held activity poll's probe
    /// borrows exactly one connection for all of its reads (Server #1204).
    /// The reader pool has a single connection, so a read that borrowed a
    /// second one while the probe held the first would time out instead.
    #[cfg(debug_assertions)]
    #[tokio::test]
    async fn sqlite_held_activity_probe_borrows_one_connection() {
        use super::super::poll_pauses::{PollPoint, pause_workflow_poll};
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("probe.sqlite");
        let writer = Store::<sqlx::Sqlite>::new(super::super::sqlite::open(path.to_str().unwrap()).await.unwrap());
        writer.register(json!({"worker_id":"probe-worker","task_queue":"probe-queue","runtime":"php",
            "supported_workflow_types":["probe"],"supported_activity_types":["act"]}),
            &QueryTaskConfig::default(), Some("1.20")).await.unwrap();
        let checkouts = Arc::new(AtomicUsize::new(0));
        let pool = reader_pool(sqlx::sqlite::SqlitePoolOptions::new(), checkouts.clone())
            .connect_with(sqlx::sqlite::SqliteConnectOptions::new().filename(&path)
                .busy_timeout(Duration::from_secs(5))).await.unwrap();
        let store = Store::new(pool);
        let request = format!("probe-request-{}", ulid::Ulid::new());
        let first = pause_workflow_poll(&request, PollPoint::Waiting);
        let body = json!({"worker_id":"probe-worker","task_queue":"probe-queue","timeout_seconds":2,"poll_request_id":request});
        let (answer, probe) = tokio::join!(store.poll(body, "activity", "1.20"), async {
            first.reached.await.unwrap();
            checkouts.store(0, Ordering::SeqCst);
            let second = pause_workflow_poll(&request, PollPoint::Waiting);
            first.release.send(()).unwrap();
            second.reached.await.unwrap();
            let probe = checkouts.load(Ordering::SeqCst);
            second.release.send(()).unwrap();
            probe
        });
        assert_eq!(answer.unwrap()["poll_status"], "empty");
        assert_eq!(probe, 1, "one held probe borrows one connection");
        store.close().await;
        writer.close().await;
    }

    #[test]
    fn the_budget_leaves_one_slot_and_never_reaches_zero() {
        for (connections, budget) in [(0, 1), (1, 1), (2, 1), (4, 3), (10, 9)] {
            assert_eq!(super::slots(connections).available_permits(), budget, "{connections}");
        }
    }
}
