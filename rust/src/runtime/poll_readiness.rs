//! Lock-free waiting for held long polls.
//!
//! A held poll takes the transition lock only to claim or refuse. After its
//! first claim it reads, without the lock, whether anything now exists that it
//! could claim or must refuse: an eligible task, a receipt for its own request,
//! or a changed registration. A same-node commit wakes it to read at once;
//! timed reads observe other server processes. A false positive costs one locked
//! claim that finds nothing, and a commit racing a read is seen on the next one.
use super::{
    Result, backend::Backend, query_authority::{self, QueryTaskConfig}, store::Store, text,
};
use serde_json::Value;
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
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

impl<DB: Backend> Store<DB>
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
    /// Whether a held workflow or activity poll should claim again.
    pub(super) async fn task_poll_ready(&self, body: &Value, kind: &str) -> Result<bool> {
        let worker_id = text(body, "worker_id")?;
        let queue = text(body, "task_queue")?;
        // The claim refuses a removed or moved registration.
        let registration = Self::query(
            "SELECT task_queue FROM workflow_worker_registrations WHERE worker_id=$1 AND namespace=$2",
        )
        .bind(worker_id)
        .bind(self.namespace.as_ref())
        .fetch_optional(&self.pool)
        .await?;
        match registration {
            Some(row) if DB::string(&row, "task_queue")? == queue => {}
            _ => return Ok(true),
        }
        // A concurrent poll with the same request ID may have claimed already;
        // the claim replays its receipt.
        if let Some(request_id) = body.get("poll_request_id").and_then(Value::as_str)
            && Self::query("SELECT task_id FROM dw_poll_receipts WHERE worker_id=$1 AND kind=$2 AND request_id=$3 AND namespace=$4")
                .bind(worker_id).bind(kind).bind(request_id).bind(self.namespace.as_ref())
                .fetch_optional(&self.pool).await?.is_some()
        {
            return Ok(true);
        }
        let at = super::store::now();
        Ok(Self::query(DB::TASK_CANDIDATES_SQL)
            .bind(self.namespace.as_ref())
            .bind(queue)
            .bind(kind)
            .bind(DB::bind_time(at))
            .bind(DB::bind_time(at))
            .fetch_optional(&self.pool)
            .await?
            .is_some())
    }

    /// Whether a held query poll should claim again. `proof` is the
    /// registration this poll belongs to.
    pub(super) async fn query_poll_ready(
        &self,
        body: &Value,
        config: &QueryTaskConfig,
        proof: &str,
    ) -> Result<bool> {
        let owner = text(body, "worker_id")?;
        // The claim refuses a replaced, removed or stale registration.
        if Self::query_worker_proof(&self.pool, self.namespace.as_ref(), owner, config)
            .await?
            .as_deref()
            != Some(proof)
        {
            return Ok(true);
        }
        let at = super::store::now();
        let query_now = query_authority::wire(at);
        Ok(Self::query(DB::POLL_WAKE_QUERIES_SQL)
            .bind(at.timestamp())
            .bind(self.namespace.as_ref())
            .bind(text(body, "task_queue")?)
            .bind(query_now.clone())
            .bind(query_now)
            .fetch_optional(&self.pool)
            .await?
            .is_some())
    }
}
