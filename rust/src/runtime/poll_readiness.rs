//! Lock-free waiting for held long polls.
//!
//! A held poll takes the transition lock only to claim or refuse. After its
//! first claim it reads, without the lock, whether anything now exists that it
//! could claim or must refuse: an eligible task, a receipt for its own request,
//! or a changed registration (`Store::task_poll_ready`, `Store::query_poll_ready`).
//! A same-node commit wakes it to read at once; timed reads observe other
//! server processes. A false positive costs one locked claim that finds
//! nothing, and a commit racing a read is seen on the next one.
use std::time::Duration;
use tokio::{sync::futures::Notified, time::Instant};

/// Paces a held poll's timed reads, which are how it observes another server
/// process. The first read comes 50 ms after a probe, and each later one waits
/// twice as long, up to 250 ms. A same-node wake reads at once and starts again
/// from 50 ms. No wait runs past the poll's deadline.
pub(super) struct ReadPace {
    next: Duration,
}

impl ReadPace {
    const FIRST: Duration = Duration::from_millis(50);
    const LONGEST: Duration = Duration::from_millis(250);

    pub(super) fn new() -> Self {
        Self { next: Self::FIRST }
    }

    /// Returns at a same-node wake or when the next timed read is due.
    pub(super) async fn wait(&mut self, wake: Notified<'_>, deadline: Instant) {
        let pause = self.next.min(deadline.saturating_duration_since(Instant::now()));
        tokio::select! {
            _ = wake => self.next = Self::FIRST,
            _ = tokio::time::sleep(pause) => self.next = (self.next * 2).min(Self::LONGEST),
        }
    }
}
