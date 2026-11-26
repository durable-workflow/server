//! Lock-free waiting for held long polls.
//!
//! A held poll takes the transition lock only to claim or refuse. After its
//! first claim it reads, without the lock, whether anything now exists that it
//! could claim or must refuse: an eligible task, a receipt for its own request,
//! or a changed registration (`Store::task_poll_ready`, `Store::query_poll_ready`).
//! A same-node commit wakes it to read at once; timed reads observe other
//! server processes. A false positive costs one locked claim that finds
//! nothing, and a commit racing a read is seen on the next one.
use std::{future::Future, time::Duration};
use tokio::time::Instant;

/// Paces a held poll's timed reads, which are how it observes another server
/// process. The first read comes 50 ms after a probe, and each later one that
/// finds nothing waits twice as long, up to a ceiling that grows with the
/// number of held polls waiting on this node: 250 ms for up to 31 of them,
/// then 8 ms per waiting poll, at most 2 s (Server #1372). No pause is shorter
/// than a quarter of the ceiling, so a short poll does not spend its life in
/// the fast first steps. Many idle polls then read a few hundred times a
/// second between them instead of several times a second each, which had
/// filled the read budget and slowed the polls that had work and the
/// producers. A same-node wake reads at once, so a poll whose queue gets a
/// task on this node still claims it promptly; work committed by another
/// server process is seen within the ceiling. No wait runs past the poll's
/// deadline.
pub(super) struct ReadPace {
    next: Duration,
}

impl ReadPace {
    const FIRST: Duration = Duration::from_millis(50);
    /// The ceiling with few waiting polls.
    const LONGEST: Duration = Duration::from_millis(250);
    /// How much each waiting poll adds to the ceiling.
    const PER_WAITING_POLL: Duration = Duration::from_millis(8);
    /// The ceiling with many waiting polls.
    const SLOWEST: Duration = Duration::from_secs(2);

    pub(super) fn new() -> Self {
        Self { next: Self::FIRST }
    }

    /// The longest pause between timed reads while `waiting` held polls wait.
    pub(super) fn ceiling(waiting: usize) -> Duration {
        Self::PER_WAITING_POLL
            .saturating_mul(u32::try_from(waiting).unwrap_or(u32::MAX))
            .clamp(Self::LONGEST, Self::SLOWEST)
    }

    /// Returns at a same-node wake or when the next timed read is due.
    pub(super) async fn wait(
        &mut self, wake: impl Future<Output = ()>, deadline: Instant, wakes: &super::poll_wakes::PollWakes,
    ) {
        let _waiting = wakes.waiting();
        let ceiling = Self::ceiling(wakes.waiting_now());
        let pause = self.next.max(ceiling / 4).min(ceiling).min(deadline.saturating_duration_since(Instant::now()));
        tokio::select! {
            () = wake => self.next = Self::FIRST,
            () = tokio::time::sleep(pause) => self.next = (self.next * 2).min(ceiling),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::ReadPace;
    use std::time::Duration;

    #[test]
    fn the_timed_read_ceiling_grows_with_waiting_polls_within_bounds() {
        for (waiting, ceiling) in [(0, 250), (8, 250), (31, 250), (64, 512), (128, 1024), (250, 2000), (256, 2000), (100_000, 2000)] {
            assert_eq!(ReadPace::ceiling(waiting), Duration::from_millis(ceiling), "{waiting}");
        }
    }
}
