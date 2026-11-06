//! Test-only counts of prepared statements in one measured async operation.
//! SQL templates are counted before binding. No values or payloads are kept.
use std::{cell::RefCell, collections::BTreeMap, future::Future};

#[derive(Default)]
pub(super) struct Counts(BTreeMap<&'static str, usize>);

impl Counts {
    pub(super) fn total(&self) -> usize { self.0.values().sum() }
    pub(super) fn count(&self, sql: &'static str) -> usize { self.0.get(sql).copied().unwrap_or(0) }
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
