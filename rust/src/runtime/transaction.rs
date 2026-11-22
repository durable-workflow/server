//! Commit derived run summaries after all authoritative mutations in a transition.
use super::{Result, backend::Backend, store::Store};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::{
    collections::BTreeMap,
    ops::{Deref, DerefMut},
    sync::Arc,
};
use tokio::sync::OwnedMutexGuard;

#[derive(Clone)]
pub(super) struct RunTags {
    pub(super) namespace: String,
    pub(super) priority: i64,
    pub(super) fairness_key: Option<String>,
    pub(super) fairness_weight: i64,
    pub(super) compatibility: Option<String>,
}

impl RunTags {
    pub(super) fn from_row<DB: Backend>(row: &DB::Row) -> Result<Self> {
        Ok(Self { namespace: DB::string(row,"namespace")?, priority: DB::number(row,"priority")?,
            fairness_key: DB::optional_string(row,"fairness_key")?,
            fairness_weight: DB::number(row,"fairness_weight")?,
            compatibility: DB::optional_string(row,"compatibility")? })
    }
}

pub(super) struct PollSavepoint {
    summaries: BTreeMap<String, Option<ChildSummaryChange>>,
    workflow_poll_replayed: Option<String>,
}

#[derive(Clone)]
pub(super) struct ChildSummaryChange {
    pub(super) previous_sequence: i64,
    pub(super) event_id: String,
}

#[derive(Clone)]
pub(super) struct ClaimTarget {
    pub(super) task_id: String,
    pub(super) kind: &'static str,
    /// Delivery acceptance must still see the attempt that was observed.
    /// A carried continuation originates inside the current transaction.
    pub(super) observed_attempt: Option<i64>,
}

/// Every mutable transaction cache, taken before continuation speculation.
/// The database rollback and this restoration are one fallible operation.
pub(super) struct ContinuationSavepoint {
    run_tags: BTreeMap<String, RunTags>,
    summaries: BTreeMap<String, Option<ChildSummaryChange>>,
    workflow_poll_replayed: Option<String>,
    history_budget: super::history_budget::Config,
    meter: Option<super::history_meter::Meter>,
    scope_history: Option<super::cancellation_scopes::HistoryCache>,
    local_writes: super::local_activities::Writes,
    claim_only: Option<ClaimTarget>,
}

pub(super) struct Transaction<'a, DB: Backend> {
    inner: sqlx::Transaction<'a, DB>,
    pub(super) run_tags: BTreeMap<String,RunTags>,
    summaries: BTreeMap<String, Option<ChildSummaryChange>>,
    pub(super) workflow_poll_replayed: Option<String>,
    pub(super) history_budget: super::history_budget::Config,
    /// A workflow task completion's history meter (Workflow #930).
    pub(super) meter: Option<super::history_meter::Meter>,
    /// The run history the cancellation scope engine has read (`scope_events`).
    pub(super) scope_history: Option<super::cancellation_scopes::HistoryCache>,
    pub(super) local_writes: super::local_activities::Writes,
    /// A scoped claim can acquire only its selected task.
    pub(super) claim_only: Option<ClaimTarget>,
    history_wakes: Option<Arc<super::history_waits::HistoryWakes>>,
    // An implicit failure drops the database transaction before this guard.
    _queue_guard: Option<OwnedMutexGuard<()>>,
    #[cfg(test)]
    pub(super) commit_gate: Option<std::sync::Arc<super::commit_handoff_tests::CommitGate>>,
}

impl<'a, DB: Backend> Transaction<'a, DB> {
    pub(super) fn new(
        inner: sqlx::Transaction<'a, DB>,
        history_budget: super::history_budget::Config,
        queue_guard: Option<OwnedMutexGuard<()>>,
        history_wakes: Option<Arc<super::history_waits::HistoryWakes>>,
    ) -> Self {
        Self {
            inner,
            run_tags: BTreeMap::new(),
            summaries: BTreeMap::new(),
            workflow_poll_replayed: None,
            history_budget,
            meter: None,
            scope_history: None,
            local_writes: Default::default(),
            claim_only: None,
            history_wakes,
            _queue_guard: queue_guard,
            #[cfg(test)]
            commit_gate: None,
        }
    }

    /// Cache only immutable task tags from an authoritative row this
    /// transaction already read. A malformed tag is decoded at the original
    /// task-creation boundary if that path needs it, not on unrelated reads.
    pub(super) fn remember_run_tags(&mut self, run_id: &str, row: &DB::Row) {
        self.run_tags.remove(run_id);
        if let Ok(tags) = RunTags::from_row::<DB>(row) {
            self.run_tags.insert(run_id.to_owned(),tags);
        }
    }

    pub(super) fn summary_changed(&mut self, run_id: &str) {
        self.summaries.insert(run_id.to_owned(), None);
    }

    pub(super) fn summary_is_unchanged(&self, run_id: &str) -> bool {
        !self.summaries.contains_key(run_id)
    }

    /// A single successful child close changes the parent's history and one
    /// wait's status. Any later change replaces this hint with a full refresh.
    pub(super) fn summary_child_completed(&mut self, run_id: &str, change: ChildSummaryChange) {
        self.summaries.insert(run_id.to_owned(), Some(change));
    }

    /// Rows the next statement will write, charged to a metered completion.
    pub(super) fn charge_rows(&mut self, rows: usize) -> Result<()> {
        match self.meter.as_mut() {
            Some(meter) => meter.charge_rows(rows),
            None => Ok(()),
        }
    }

    /// History bytes a stored event grows by, charged to a metered completion.
    pub(super) fn charge_bytes(&mut self, bytes: usize) -> Result<()> {
        match self.meter.as_mut() {
            Some(meter) => meter.charge_bytes(bytes),
            None => Ok(()),
        }
    }

    /// One history event about to be stored, charged to a metered completion.
    pub(super) fn charge_event(&mut self, event_type: &str, payload: &serde_json::Value) -> Result<()> {
        match self.meter.as_mut() {
            Some(meter) => meter.charge_event(event_type, payload),
            None => Ok(()),
        }
    }

    pub(super) async fn rollback(self) -> Result<()> {
        self.inner.rollback().await?;
        Ok(())
    }
}

impl<DB: Backend> Deref for Transaction<'_, DB> {
    type Target = DB::Connection;
    fn deref(&self) -> &Self::Target {
        &self.inner
    }
}

impl<DB: Backend> DerefMut for Transaction<'_, DB> {
    fn deref_mut(&mut self) -> &mut Self::Target {
        &mut self.inner
    }
}

impl<DB: Backend> Transaction<'_, DB>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
    for<'q> &'q str: Encode<'q, DB> + Type<DB>,
    for<'q> Option<&'q str>: Encode<'q, DB>,
    for<'q> Option<DB::EncodedDocument>: Encode<'q, DB>,
    for<'q> String: Encode<'q, DB> + Type<DB>,
    for<'q> i64: Encode<'q, DB> + Type<DB>,
    for<'r> i64: Decode<'r, DB>,
    usize: ColumnIndex<DB::Row>,
{
    pub(super) async fn continuation_savepoint(&mut self) -> Result<ContinuationSavepoint> {
        (&mut **self).execute("SAVEPOINT dw_activity_continuation").await?;
        Ok(ContinuationSavepoint { run_tags: self.run_tags.clone(), summaries: self.summaries.clone(),
            workflow_poll_replayed: self.workflow_poll_replayed.clone(), history_budget: self.history_budget,
            meter: self.meter.clone(), scope_history: self.scope_history.clone(),
            local_writes: self.local_writes.clone(), claim_only: self.claim_only.clone() })
    }

    pub(super) async fn rollback_continuation(&mut self, saved: ContinuationSavepoint) -> Result<()> {
        (&mut **self).execute("ROLLBACK TO SAVEPOINT dw_activity_continuation").await?;
        self.run_tags = saved.run_tags;
        self.summaries = saved.summaries;
        self.workflow_poll_replayed = saved.workflow_poll_replayed;
        self.history_budget = saved.history_budget;
        self.meter = saved.meter;
        self.scope_history = saved.scope_history;
        self.local_writes = saved.local_writes;
        self.claim_only = saved.claim_only;
        self.release_continuation().await
    }

    pub(super) async fn release_continuation(&mut self) -> Result<()> {
        (&mut **self).execute("RELEASE SAVEPOINT dw_activity_continuation").await?;
        Ok(())
    }

    /// Poll refusal preserves the earlier request binding, while discarding
    /// a partial claim. Budget charges stay consumed and discarded reads
    /// cannot survive in the transaction's cancellation-history cache.
    pub(super) async fn poll_savepoint(&mut self) -> Result<PollSavepoint> {
        Store::<DB>::write_local_activities(self).await?;
        // MySQL does not support savepoint commands in its prepared-statement
        // protocol. Send these fixed transaction-control literals directly.
        (&mut **self).execute("SAVEPOINT dw_poll_claim").await?;
        Ok(PollSavepoint { summaries: self.summaries.clone(), workflow_poll_replayed: self.workflow_poll_replayed.clone() })
    }

    pub(super) async fn rollback_poll_claim(&mut self, saved: PollSavepoint) -> Result<()> {
        (&mut **self).execute("ROLLBACK TO SAVEPOINT dw_poll_claim").await?;
        self.summaries = saved.summaries;
        self.workflow_poll_replayed = saved.workflow_poll_replayed;
        self.scope_history = None;
        self.local_writes = Default::default();
        self.run_tags.clear();
        self.release_poll_claim().await
    }

    pub(super) async fn release_poll_claim(&mut self) -> Result<()> {
        (&mut **self).execute("RELEASE SAVEPOINT dw_poll_claim").await?;
        Ok(())
    }

    pub(super) async fn commit(mut self) -> Result<()> {
        // A completion whose meter refused cannot commit, even if a caller
        // swallowed the refusal; dropping the transaction rolls it back.
        if let Some(refusal) = self.meter.as_ref().and_then(super::history_meter::Meter::tripped) {
            return Err(refusal);
        }
        Store::<DB>::write_local_activities(&mut self).await?;
        let changed_runs = std::mem::take(&mut self.summaries);
        for (run_id, change) in &changed_runs {
            if let Some(change) = change
                && Store::<DB>::project_completed_barrier_member(&mut self, run_id, change).await?
            {
                continue;
            }
            Store::<DB>::order_waits(&mut self, run_id).await?;
            Store::<DB>::project_run_summary(&mut self, run_id).await?;
        }
        // The next writer can now wait on the database lock while this commit
        // finishes. SQLite keeps its process queue through the commit because
        // its busy handler polls rather than handing the lock to a waiter.
        if DB::COMMIT_HANDOFF { drop(self._queue_guard.take()); }
        #[cfg(test)]
        if let Some(gate) = self.commit_gate.as_ref() {
            gate.entered.notify_one();
            gate.resume.notified().await;
        }
        self.inner.commit().await?;
        if !changed_runs.is_empty() && let Some(wakes) = self.history_wakes {
            wakes.committed(changed_runs.keys());
        }
        Ok(())
    }
}
