//! In-process history notifications for runs with active readers.
use std::{
    collections::HashMap,
    sync::{Arc, Mutex},
};
use tokio::sync::Notify;

#[derive(Default)]
pub(super) struct HistoryWakes {
    runs: Mutex<HashMap<String, RunWaiters>>,
}

#[derive(Default)]
struct RunWaiters {
    notify: Arc<Notify>,
    count: usize,
}

impl HistoryWakes {
    pub(super) fn subscribe<'a>(&'a self, run_id: &'a str) -> HistoryWait<'a> {
        let mut runs = self.runs.lock().unwrap();
        let waiters = runs.entry(run_id.to_owned()).or_default();
        waiters.count += 1;
        HistoryWait {
            wakes: self,
            run_id,
            notify: waiters.notify.clone(),
        }
    }

    pub(super) fn committed<'a>(&self, run_ids: impl IntoIterator<Item = &'a String>) {
        let runs = self.runs.lock().unwrap();
        if !runs.is_empty() {
            for run_id in run_ids {
                if let Some(waiters) = runs.get(run_id) {
                    waiters.notify.notify_waiters();
                }
            }
        }
    }
}

pub(super) struct HistoryWait<'a> {
    wakes: &'a HistoryWakes,
    run_id: &'a str,
    pub(super) notify: Arc<Notify>,
}

impl Drop for HistoryWait<'_> {
    fn drop(&mut self) {
        let mut runs = self.wakes.runs.lock().unwrap();
        let waiters = runs.get_mut(self.run_id).expect("subscribed run");
        waiters.count -= 1;
        if waiters.count == 0 {
            runs.remove(self.run_id);
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::time::Duration;
    use tokio::time::timeout;

    #[tokio::test]
    async fn committed_history_wakes_only_that_runs_readers() {
        let wakes = HistoryWakes::default();
        let run = String::from("committed-run");
        let first = wakes.subscribe(&run);
        let second = wakes.subscribe(&run);
        let other = wakes.subscribe("other-run");
        let first_change = first.notify.notified();
        let second_change = second.notify.notified();
        let other_change = other.notify.notified();

        wakes.committed([&run]);

        timeout(Duration::ZERO, first_change).await.unwrap();
        timeout(Duration::ZERO, second_change).await.unwrap();
        assert!(timeout(Duration::ZERO, other_change).await.is_err());
        drop(first);
        assert!(wakes.runs.lock().unwrap().contains_key(&run));
        drop(second);
        drop(other);
        assert!(wakes.runs.lock().unwrap().is_empty());
    }
}
