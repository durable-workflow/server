//! In-process wakes for held worker polls, per namespace and task queue
//! (Server #1372).
//!
//! A commit used to wake every held poll on the node through one
//! `Notify`, and each woken poll read the database: with many held polls on
//! many queues, almost every one of those reads found nothing, and they
//! queued behind each other for the read budget. A transaction that creates
//! a ready task (`Store::create_task_dispatched`) now records its namespace
//! and queue, and its commit wakes only the held workflow and activity polls
//! of those queues. The hottest commits (starts, signals and task
//! completions) no longer wake every poll; they wake the queues they made
//! ready and the waiters that are not held worker polls ([`PollWakes::others`]:
//! query-task polls and update, query and validation result waits). Every
//! other commit still wakes everyone through `Store::wake`.
//!
//! A missed wake costs latency, never a task: a held poll still reads on its
//! own timed schedule ([`super::poll_readiness::ReadPace`]), which is also
//! how it sees work another server process committed.
use std::{
    collections::{BTreeSet, HashMap},
    sync::{
        Arc, Mutex,
        atomic::{AtomicUsize, Ordering},
    },
};
use tokio::sync::Notify;

/// A queue's key: namespace and task queue.
pub(super) type QueueKey = (String, String);

#[derive(Default)]
pub(super) struct PollWakes {
    queues: Mutex<HashMap<QueueKey, QueueWaiters>>,
    /// Waiters that are not held worker polls, which the hottest commits
    /// wake instead of `Store::wake`.
    pub(super) others: Notify,
    /// Held polls now waiting between reads, node-wide.
    waiting: AtomicUsize,
    /// When this node last checked each queue for abandoned workflow task
    /// leases on a held poll's later probe.
    recovery_checks: Mutex<HashMap<QueueKey, std::time::Instant>>,
}

/// How often this node checks one queue for abandoned workflow task leases on
/// held polls' later probes. A lease is abandoned only once its owner's
/// registration is stale (no heartbeat for `worker_stale_after_seconds`,
/// 30 s by default), so a check a second later finds the same leases; every
/// poll's first probe still checks.
pub(super) const RECOVERY_CHECK_INTERVAL: std::time::Duration = std::time::Duration::from_secs(1);

#[derive(Default)]
struct QueueWaiters {
    notify: Arc<Notify>,
    count: usize,
}

impl PollWakes {
    /// Subscribes a held poll to its queue's wakes until the returned value
    /// is dropped.
    pub(super) fn subscribe(&self, namespace: &str, queue: &str) -> QueueWait<'_> {
        let key = (namespace.to_owned(), queue.to_owned());
        let mut queues = self.queues.lock().unwrap();
        let waiters = queues.entry(key.clone()).or_default();
        waiters.count += 1;
        QueueWait { wakes: self, notify: waiters.notify.clone(), key }
    }

    /// Wakes the held polls of `queues` and the other waiters, after a
    /// commit that made those queues ready.
    pub(super) fn committed<'a>(&self, queues: impl IntoIterator<Item = &'a QueueKey>) {
        self.wake_queues(queues);
        self.others.notify_waiters();
    }

    /// Wakes the held polls of `queues` only.
    pub(super) fn wake_queues<'a>(&self, queues: impl IntoIterator<Item = &'a QueueKey>) {
        let waiting = self.queues.lock().unwrap();
        if waiting.is_empty() {
            return;
        }
        for key in queues {
            if let Some(waiters) = waiting.get(key) {
                waiters.notify.notify_waiters();
            }
        }
    }

    /// Whether a held poll's later probe should check `queue` for abandoned
    /// workflow task leases (Server #1372): at most once per
    /// [`RECOVERY_CHECK_INTERVAL`] per queue on this node. Each of them used
    /// to check on every probe of every poll, and those reads were about
    /// half of all held-poll reads with many idle polls.
    pub(super) fn recovery_due(&self, namespace: &str, queue: &str) -> bool {
        let now = std::time::Instant::now();
        let mut checks = self.recovery_checks.lock().unwrap();
        let key = (namespace.to_owned(), queue.to_owned());
        if checks.get(&key).is_some_and(|last| now.duration_since(*last) < RECOVERY_CHECK_INTERVAL) {
            return false;
        }
        if checks.len() > 4096 {
            checks.retain(|_, last| now.duration_since(*last) < RECOVERY_CHECK_INTERVAL);
        }
        checks.insert(key, now);
        true
    }

    /// Counts a held poll as waiting until the returned guard is dropped.
    pub(super) fn waiting(&self) -> Waiting<'_> {
        self.waiting.fetch_add(1, Ordering::Relaxed);
        Waiting(self)
    }

    /// Held polls now waiting between reads.
    pub(super) fn waiting_now(&self) -> usize {
        self.waiting.load(Ordering::Relaxed)
    }
}

pub(super) struct QueueWait<'a> {
    wakes: &'a PollWakes,
    key: QueueKey,
    pub(super) notify: Arc<Notify>,
}

impl Drop for QueueWait<'_> {
    fn drop(&mut self) {
        let mut queues = self.wakes.queues.lock().unwrap();
        let waiters = queues.get_mut(&self.key).expect("subscribed queue");
        waiters.count -= 1;
        if waiters.count == 0 {
            queues.remove(&self.key);
        }
    }
}

pub(super) struct Waiting<'a>(&'a PollWakes);

impl Drop for Waiting<'_> {
    fn drop(&mut self) {
        self.0.waiting.fetch_sub(1, Ordering::Relaxed);
    }
}

/// Resolves when either wake resolves. Both were registered when created, so
/// neither misses a wake that comes before this is first polled.
pub(super) async fn any(first: impl std::future::Future<Output = ()>, second: impl std::future::Future<Output = ()>) {
    tokio::select! {
        () = first => {}
        () = second => {}
    }
}

/// The queues a transaction made ready, woken when it commits.
pub(super) type ReadyQueues = BTreeSet<QueueKey>;

#[cfg(test)]
mod tests {
    use super::*;
    use std::time::Duration;
    use tokio::time::timeout;

    #[tokio::test]
    async fn a_commit_wakes_only_the_polls_of_the_queues_it_made_ready() {
        let wakes = PollWakes::default();
        let orders = wakes.subscribe("default", "orders");
        let same = wakes.subscribe("default", "orders");
        let other_queue = wakes.subscribe("default", "billing");
        let other_namespace = wakes.subscribe("tenant", "orders");
        let (orders_woken, same_woken) = (orders.notify.notified(), same.notify.notified());
        let (queue_woken, namespace_woken) = (other_queue.notify.notified(), other_namespace.notify.notified());
        let others_woken = wakes.others.notified();

        wakes.committed([&("default".to_owned(), "orders".to_owned())]);

        timeout(Duration::ZERO, orders_woken).await.unwrap();
        timeout(Duration::ZERO, same_woken).await.unwrap();
        timeout(Duration::ZERO, others_woken).await.unwrap();
        assert!(timeout(Duration::from_millis(20), queue_woken).await.is_err());
        assert!(timeout(Duration::from_millis(20), namespace_woken).await.is_err());

        drop((orders, same, other_queue, other_namespace));
        assert!(wakes.queues.lock().unwrap().is_empty(), "unsubscribed queues leave no entry");
    }

    #[test]
    fn a_queue_is_checked_for_abandoned_leases_at_most_once_an_interval() {
        let wakes = PollWakes::default();
        assert!(wakes.recovery_due("default", "orders"));
        assert!(!wakes.recovery_due("default", "orders"), "the same queue within the interval");
        assert!(wakes.recovery_due("default", "billing"), "another queue");
        assert!(wakes.recovery_due("tenant", "orders"), "another namespace");
        wakes.recovery_checks.lock().unwrap().insert(
            ("default".to_owned(), "orders".to_owned()),
            std::time::Instant::now() - RECOVERY_CHECK_INTERVAL,
        );
        assert!(wakes.recovery_due("default", "orders"), "once the interval has passed");
    }

    #[test]
    fn waiting_polls_are_counted_while_they_wait() {
        let wakes = PollWakes::default();
        let first = wakes.waiting();
        let second = wakes.waiting();
        assert_eq!(wakes.waiting_now(), 2);
        drop(first);
        assert_eq!(wakes.waiting_now(), 1);
        drop(second);
        assert_eq!(wakes.waiting_now(), 0);
    }
}
