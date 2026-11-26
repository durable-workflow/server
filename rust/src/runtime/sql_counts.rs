//! Test-only counts of prepared statements in one measured async operation.
//! SQL templates are counted before binding. No values or payloads are kept.
use std::{cell::RefCell, collections::BTreeMap, future::Future};

#[derive(Default)]
pub(super) struct Counts(BTreeMap<&'static str, usize>);

impl Counts {
    pub(super) fn total(&self) -> usize { self.0.values().sum() }
    pub(super) fn count(&self, sql: &'static str) -> usize { self.0.get(sql).copied().unwrap_or(0) }
    /// Every counted template with its count, for a failure message.
    pub(super) fn entries(&self) -> Vec<(&'static str, usize)> { self.0.iter().map(|(sql, count)| (*sql, *count)).collect() }
    pub(super) fn count_prefix(&self, prefix: &str) -> usize {
        self.0.iter().filter(|(sql,_)| sql.starts_with(prefix)).map(|(_,count)| count).sum()
    }
}

tokio::task_local! { static COUNTS: RefCell<Counts>; }

pub(super) fn record(sql: &'static str) {
    let _ = COUNTS.try_with(|counts| *counts.borrow_mut().0.entry(sql).or_default() += 1);
}

pub(super) async fn measure<F: Future>(future: F) -> (F::Output, Counts) {
    COUNTS.scope(RefCell::new(Counts::default()), async move {
        let result = future.await;
        (result, COUNTS.with(|counts| counts.replace(Counts::default())))
    }).await
}
