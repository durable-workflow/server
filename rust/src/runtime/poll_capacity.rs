//! Node-wide long-poll capacity (Server #982), PHP's `LongPollWaitSlotStore`.
//!
//! A worker poll first probes for work without any slot. Only a poll that
//! found nothing and would now wait takes a wait slot, and holds it until it
//! answers, fails or its client goes away (the slot is released on drop). A
//! poll that cannot take one answers at once with PHP's typed
//! `429 long_poll_capacity_exhausted` and `Retry-After: 1`, so idle workers
//! back off instead of holding connections.
//!
//! Two budgets, as in PHP: `DW_WORKER_LONG_POLL_MAX_CONCURRENT` bounds held
//! workflow and activity waits, `DW_QUERY_TASK_POLL_MAX_CONCURRENT` bounds
//! held query-task and update-validation waits, so idle workflow/activity
//! polls cannot starve live queries. Unset (or not numeric) leaves a budget
//! unbounded, as PHP does outside its CLI server; zero refuses every wait.
//!
//! Native has no fixed pool of HTTP request workers: a held poll is a parked
//! task, not a process. The fixed shared resource is the database pool, which
//! health, readiness, admin and every other request need. So native reads
//! `DW_WORKER_LONG_POLL_RESERVED_HTTP_WORKERS` as the number of the node's
//! request-pool connections that long-poll probes may never occupy together:
//! every poll probe (the first claim and each re-probe of a held wait) takes
//! one of `request pool size - reserved` probe permits (at least one) and
//! returns it before it waits. Unset, probes take no permit here. Whatever
//! the setting, the held-poll work `probe_connections` lists draws its
//! connections from that module's budget and leaves the request pool's last
//! connection free; other work, listed there, does not (Server #1204, #1355).
use std::sync::{Arc, RwLock};

use axum::http::StatusCode;
use serde_json::{Value, json};
use tokio::sync::{OwnedSemaphorePermit, Semaphore};

/// PHP's `WorkerPollBackpressure::RETRY_AFTER_SECONDS`.
pub(super) const RETRY_AFTER_SECONDS: u64 = 1;
pub(super) const REASON: &str = "long_poll_capacity_exhausted";

/// The three settings, as PHP's `server.polling` and `server.query_tasks`
/// config reads them.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct Settings {
    /// `DW_WORKER_LONG_POLL_MAX_CONCURRENT`: held workflow and activity waits.
    pub worker_waits: Option<usize>,
    /// `DW_QUERY_TASK_POLL_MAX_CONCURRENT`: held query-task and
    /// update-validation waits.
    pub query_task_waits: Option<usize>,
    /// `DW_WORKER_LONG_POLL_RESERVED_HTTP_WORKERS`: database connections poll
    /// probes leave free.
    pub reserved: Option<usize>,
}

impl Settings {
    pub fn from_env() -> Self {
        Self::from_lookup(|key| std::env::var(key).ok())
    }

    /// `is_numeric($configured) ? max(0, (int) $configured) : null`.
    pub fn from_lookup(lookup: impl Fn(&str) -> Option<String>) -> Self {
        let count = |name: &str, legacy: &str| {
            crate::config_audit::effective(&lookup, name, Some(legacy))
                .filter(crate::config_audit::PhpValue::is_numeric)
                .map(|value| usize::try_from(value.int().max(0)).unwrap_or(usize::MAX).min(Semaphore::MAX_PERMITS))
        };
        Self {
            worker_waits: count("DW_WORKER_LONG_POLL_MAX_CONCURRENT", "WORKFLOW_SERVER_WORKER_LONG_POLL_MAX_CONCURRENT"),
            query_task_waits: count("DW_QUERY_TASK_POLL_MAX_CONCURRENT", "WORKFLOW_SERVER_QUERY_TASK_POLL_MAX_CONCURRENT"),
            reserved: count(
                "DW_WORKER_LONG_POLL_RESERVED_HTTP_WORKERS",
                "WORKFLOW_SERVER_WORKER_LONG_POLL_RESERVED_HTTP_WORKERS",
            ),
        }
    }
}

/// PHP's two wait pools, named as `wait_pool` reports them.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(super) enum Pool {
    Worker,
    QueryTask,
}

impl Pool {
    fn name(self) -> &'static str {
        match self {
            Self::Worker => "worker",
            Self::QueryTask => "query-task",
        }
    }
}

#[derive(Debug, Default)]
struct Gates {
    worker: Option<Arc<Semaphore>>,
    query_task: Option<Arc<Semaphore>>,
    probes: Option<Arc<Semaphore>>,
}

impl Gates {
    fn new(settings: Settings, connections: u32) -> Self {
        let gate = |limit: Option<usize>| limit.map(|limit| Arc::new(Semaphore::new(limit)));
        let connections = usize::try_from(connections).unwrap_or(usize::MAX).max(1);
        Self {
            worker: gate(settings.worker_waits),
            query_task: gate(settings.query_task_waits),
            probes: settings
                .reserved
                .filter(|reserved| *reserved > 0)
                .map(|reserved| Arc::new(Semaphore::new(connections.saturating_sub(reserved).max(1)))),
        }
    }
}

/// The node's wait slots and probe permits, shared by every namespace.
/// Settings can be replaced at runtime (tests do); polls already holding a
/// slot keep it until they answer.
#[derive(Debug, Default)]
pub struct PollCapacity {
    gates: RwLock<Gates>,
}

/// A held wait. Dropping it, when the poll answers or its future is dropped
/// because the client went away, returns the slot.
pub(super) struct WaitSlot(#[allow(dead_code)] Option<OwnedSemaphorePermit>);

impl PollCapacity {
    pub fn new(settings: Settings, connections: u32) -> Self {
        Self { gates: RwLock::new(Gates::new(settings, connections)) }
    }

    pub fn set(&self, settings: Settings, connections: u32) {
        *self.gates.write().expect("poll capacity") = Gates::new(settings, connections);
    }

    fn gate(&self, pick: impl Fn(&Gates) -> &Option<Arc<Semaphore>>) -> Option<Arc<Semaphore>> {
        pick(&self.gates.read().expect("poll capacity")).clone()
    }

    /// `tryAcquire`: a slot, or `None` when the pool's budget is spent.
    pub(super) fn try_wait(&self, pool: Pool) -> Option<WaitSlot> {
        let gate = self.gate(|gates| match pool {
            Pool::Worker => &gates.worker,
            Pool::QueryTask => &gates.query_task,
        });
        match gate {
            None => Some(WaitSlot(None)),
            Some(gate) => gate.try_acquire_owned().ok().map(|permit| WaitSlot(Some(permit))),
        }
    }

    /// A permit for one poll probe's database work. Waits while other probes
    /// hold every permit; `None` when no connections are reserved.
    pub(super) async fn probe(&self) -> Option<OwnedSemaphorePermit> {
        let gate = self.gate(|gates| &gates.probes)?;
        gate.acquire_owned().await.ok()
    }

    /// Takes a wait slot into `slot` unless the poll already holds one, or
    /// returns PHP's `WorkerPollBackpressure` refusal.
    pub(super) fn hold(
        &self,
        slot: &mut Option<WaitSlot>,
        pool: Pool,
        namespace: &str,
        task_queue: &str,
        task_kind: &str,
    ) -> super::Result<()> {
        if slot.is_none() {
            *slot = Some(self.try_wait(pool).ok_or_else(|| exhausted(pool, namespace, task_queue, task_kind))?);
        }
        Ok(())
    }
}

/// `WorkerPollBackpressure::response`, with the worker protocol envelope.
fn exhausted(pool: Pool, namespace: &str, task_queue: &str, task_kind: &str) -> super::RuntimeError {
    super::RuntimeError::Protocol {
        status: StatusCode::TOO_MANY_REQUESTS,
        response: json!({
            "task": null,
            "poll_status": REASON,
            "error": "Worker poll wait capacity is temporarily exhausted.",
            "reason": REASON,
            "message": "Retry the poll after the advertised delay so idle workers do not starve health and control-plane requests.",
            "namespace": namespace,
            "task_queue": task_queue,
            "task_kind": task_kind,
            "wait_pool": pool.name(),
            "retry_after_seconds": RETRY_AFTER_SECONDS,
            "retryable": true,
            "protocol_version": super::WORKER_PROTOCOL_VERSION,
            "server_capabilities": super::store::capabilities(),
        }),
    }
}

/// Whether an error response is this refusal, which carries `Retry-After`.
pub(super) fn is_exhausted(response: &Value) -> bool {
    response["reason"] == REASON && response["poll_status"] == REASON
}

#[cfg(test)]
mod tests {
    use super::*;

    fn settings(pairs: &[(&str, &str)]) -> Settings {
        let pairs: Vec<(String, String)> = pairs.iter().map(|(k, v)| ((*k).to_owned(), (*v).to_owned())).collect();
        Settings::from_lookup(|key| pairs.iter().find(|(name, _)| name == key).map(|(_, value)| value.clone()))
    }

    #[test]
    fn unset_or_non_numeric_settings_leave_polls_unbounded() {
        assert_eq!(settings(&[]), Settings::default());
        for value in ["", "null", "true", "many", "2x"] {
            assert_eq!(
                settings(&[("DW_WORKER_LONG_POLL_MAX_CONCURRENT", value), ("DW_QUERY_TASK_POLL_MAX_CONCURRENT", value),
                    ("DW_WORKER_LONG_POLL_RESERVED_HTTP_WORKERS", value)]),
                Settings::default(),
                "{value:?}"
            );
        }
        let capacity = PollCapacity::new(Settings::default(), 4);
        let held: Vec<_> = (0..1000).map(|_| capacity.try_wait(Pool::Worker).unwrap()).collect();
        assert_eq!(held.len(), 1000);
        assert!(capacity.gate(|gates| &gates.probes).is_none());
    }

    #[test]
    fn numeric_settings_read_like_php_with_legacy_fallback() {
        let read = settings(&[
            ("DW_WORKER_LONG_POLL_MAX_CONCURRENT", "6"),
            ("WORKFLOW_SERVER_QUERY_TASK_POLL_MAX_CONCURRENT", " 2.9"),
            ("DW_WORKER_LONG_POLL_RESERVED_HTTP_WORKERS", "-3"),
        ]);
        assert_eq!(read, Settings { worker_waits: Some(6), query_task_waits: Some(2), reserved: Some(0) });
        // The DW_ name wins over its legacy name.
        let read = settings(&[("DW_WORKER_LONG_POLL_MAX_CONCURRENT", "1"), ("WORKFLOW_SERVER_WORKER_LONG_POLL_MAX_CONCURRENT", "9")]);
        assert_eq!(read.worker_waits, Some(1));
    }

    #[test]
    fn each_pool_has_its_own_budget_and_dropped_slots_return() {
        let capacity = PollCapacity::new(Settings { worker_waits: Some(2), query_task_waits: Some(0), reserved: None }, 4);
        let first = capacity.try_wait(Pool::Worker).unwrap();
        let _second = capacity.try_wait(Pool::Worker).unwrap();
        assert!(capacity.try_wait(Pool::Worker).is_none());
        assert!(capacity.try_wait(Pool::QueryTask).is_none(), "zero refuses every query-task wait");
        drop(first);
        assert!(capacity.try_wait(Pool::Worker).is_some());

        let mut slot = None;
        let refusal = capacity.hold(&mut slot, Pool::QueryTask, "default", "orders", "query_task").unwrap_err();
        let super::super::RuntimeError::Protocol { status, response } = refusal else { panic!("typed refusal") };
        assert_eq!(status, StatusCode::TOO_MANY_REQUESTS);
        assert!(is_exhausted(&response));
        assert_eq!(response["wait_pool"], "query-task");
        assert_eq!(response["task_kind"], "query_task");
        assert_eq!(response["retry_after_seconds"], 1);
    }

    #[tokio::test]
    async fn reserved_connections_bound_concurrent_probes() {
        let capacity = PollCapacity::new(Settings { reserved: Some(2), ..Default::default() }, 4);
        let first = capacity.probe().await.unwrap();
        let _second = capacity.probe().await.unwrap();
        assert!(tokio::time::timeout(std::time::Duration::from_millis(50), capacity.probe()).await.is_err(),
            "two of four connections stay free of poll probes");
        drop(first);
        assert!(capacity.probe().await.is_some());
        // At least one probe can always run.
        let capacity = PollCapacity::new(Settings { reserved: Some(9), ..Default::default() }, 4);
        let _only = capacity.probe().await.unwrap();
        assert!(tokio::time::timeout(std::time::Duration::from_millis(50), capacity.probe()).await.is_err());
        // Zero reserves nothing.
        let capacity = PollCapacity::new(Settings { reserved: Some(0), ..Default::default() }, 4);
        assert!(capacity.probe().await.is_none());
    }
}
