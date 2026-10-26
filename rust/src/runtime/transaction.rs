//! Commit derived run summaries after all authoritative mutations in a transition.
use super::{Result, backend::Backend, store::Store};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::{
    collections::BTreeSet,
    ops::{Deref, DerefMut},
};

pub(super) struct Transaction<'a, DB: Backend> {
    inner: sqlx::Transaction<'a, DB>,
    summaries: BTreeSet<String>,
    pub(super) history_budget: super::history_budget::Config,
    /// A workflow task completion's history meter (Workflow #930).
    pub(super) meter: Option<super::history_meter::Meter>,
    /// The run history the cancellation scope engine has read (`scope_events`).
    pub(super) scope_history: Option<super::cancellation_scopes::HistoryCache>,
}

impl<'a, DB: Backend> Transaction<'a, DB> {
    pub(super) fn new(
        inner: sqlx::Transaction<'a, DB>,
        history_budget: super::history_budget::Config,
    ) -> Self {
        Self {
            inner,
            summaries: BTreeSet::new(),
            history_budget,
            meter: None,
            scope_history: None,
        }
    }

    pub(super) fn summary_changed(&mut self, run_id: &str) {
        self.summaries.insert(run_id.to_owned());
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
    pub(super) async fn commit(mut self) -> Result<()> {
        // A completion whose meter refused cannot commit, even if a caller
        // swallowed the refusal; dropping the transaction rolls it back.
        if let Some(refusal) = self.meter.as_ref().and_then(super::history_meter::Meter::tripped) {
            return Err(refusal);
        }
        for run_id in std::mem::take(&mut self.summaries) {
            Store::<DB>::order_waits(&mut self, &run_id).await?;
            Store::<DB>::project_run_summary(&mut self, &run_id).await?;
        }
        self.inner.commit().await?;
        Ok(())
    }
}
