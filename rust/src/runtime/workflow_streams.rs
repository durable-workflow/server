//! Durable workflow streams as published PHP's `WorkflowStreamController`
//! and `WorkflowStreamService` serve them. A producer appends ordered items
//! to a named, run-scoped stream, which the first append opens. Offsets
//! start at 0, a repeated idempotency key returns the item it already
//! names, and a batch whose fresh items would pass the pending-items cap is
//! refused whole with 429 (Server #774). A close records a clean or errored
//! end once. Readers page by offset and may wait, and a read that reaches
//! the head resets `pending_items`. A closed stream past its retention
//! reads as not found, and a bounded pass reclaims its rows.
//!
//! PHP's models write these times in Eloquent's `Y-m-d H:i:s` format, so
//! every stored time is a whole second. Native differs in three places:
//! - An append whose items each hold none of the item fields fails in PHP,
//!   whose validated data then has no `items` (an undefined index, 500).
//!   Native refuses it with the service's own 400. A batch with some such
//!   items drops them, as PHP does.
//! - A read that reaches the head resets `pending_items` only while the
//!   stream is still at that head. PHP's unlocked save can also clear the
//!   count of an append committed after its read.
//! - A runtime external payload reference object in `payload_reference` is
//!   refused with 501; PHP resolves and claims it. Other values are refused
//!   as PHP's transport refuses them.
use super::laravel_rules::{self, Rule};
use super::laravel_validation::{failure, integer, normalized};
use super::{
    Result, Runtime, RuntimeError,
    backend::Backend,
    refuse,
    store::{Store, now},
};
use axum::{
    Extension, Json,
    extract::{Path, Query},
    http::StatusCode,
};
use chrono::{DateTime, SubsecRound, Utc};
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::{collections::HashMap, time::Duration};
use tokio::time::Instant;

/// `WorkflowStreamService::DEFAULT_MAX_PENDING_ITEMS`.
const DEFAULT_MAX_PENDING_ITEMS: i64 = 10_000;
/// `WorkflowStreamService::DEFAULT_MAX_ITEMS_PER_SUBSCRIBE`.
const DEFAULT_MAX_ITEMS_PER_SUBSCRIBE: i64 = 100;
/// `WorkflowStreamService::SUBSCRIBE_MAX_ITEMS`.
const SUBSCRIBE_MAX_ITEMS: i64 = 500;
/// `WorkflowStreamService::DEFAULT_CLOSED_RETENTION_SECONDS`.
const DEFAULT_CLOSED_RETENTION_SECONDS: i64 = 3600;
/// `WorkflowStreamService::MAX_PENDING_ITEMS_MAX`: a producer may lower its
/// cap, not raise it.
const MAX_PENDING_ITEMS_MAX: i64 = 10_000;
/// `WorkflowStreamService::RETENTION_SECONDS_MAX` (30 days).
const RETENTION_SECONDS_MAX: i64 = 2_592_000;
/// `WorkflowStreamRetention::ITEM_BATCH`: items deleted per transaction.
const SWEEP_ITEM_BATCH: i64 = 100;
/// `WorkflowStreamRetention::INLINE_STREAM_LIMIT` and `INLINE_ITEM_BUDGET`.
pub(super) const INLINE_SWEEP: (i64, i64) = (10, 500);
/// A namespace's due streams in deadline order, read from the
/// `(namespace, reclaim_at, stream_id)` index of `dw_stream_reclaims`.
pub(super) const DUE_STREAMS: &str =
    "SELECT stream_id FROM dw_stream_reclaims WHERE namespace=$1 AND reclaim_at<=$2 ORDER BY reclaim_at,stream_id LIMIT $3";
/// `LegacyV1ProjectionContract::IMPORT_SOURCE`.
const PROJECTION_SOURCE: &str = "waterline_v1_projection";
/// `readWithWait` checks again every 250 ms.
const WAIT_INTERVAL: Duration = Duration::from_millis(250);

const STREAM_BY_NAME: &str =
    "SELECT * FROM workflow_durable_streams WHERE namespace=$1 AND workflow_run_id=$2 AND stream_name=$3";
const STREAM_BY_ID: &str = "SELECT * FROM workflow_durable_streams WHERE id=$1";

/// `append()`'s Laravel rules.
const APPEND_RULES: &laravel_rules::Rules = &[
    ("items", &[Rule::Required, Rule::Array, Rule::Min(1), Rule::Max(500)]),
    ("items.*", &[Rule::Array]),
    ("items.*.payload", &[Rule::Nullable]),
    ("items.*.payload_reference", &[Rule::Nullable, Rule::String, Rule::Max(191)]),
    ("items.*.payload_codec", &[Rule::Nullable, Rule::String, Rule::Max(64)]),
    ("items.*.idempotency_key", &[Rule::Nullable, Rule::String, Rule::Max(191)]),
    ("items.*.origin", &[Rule::Nullable, Rule::String, Rule::Max(64)]),
    ("items.*.origin_reference", &[Rule::Nullable, Rule::String, Rule::Max(191)]),
    ("items.*.item_type", &[Rule::Nullable, Rule::String, Rule::Max(64)]),
    ("items.*.content_type", &[Rule::Nullable, Rule::String, Rule::Max(191)]),
    ("max_pending_items", &[Rule::Nullable, Rule::Integer, Rule::Min(1), Rule::Max(MAX_PENDING_ITEMS_MAX)]),
];

/// `close()`'s Laravel rules.
const CLOSE_RULES: &laravel_rules::Rules = &[
    ("error_reason", &[Rule::Nullable, Rule::String, Rule::Max(191)]),
    ("retention_seconds", &[Rule::Nullable, Rule::Integer, Rule::Min(1), Rule::Max(RETENTION_SECONDS_MAX)]),
];

/// The item fields `append()` validates, which are all its validated data
/// keeps of an item.
const ITEM_FIELDS: [&str; 8] = [
    "payload",
    "payload_reference",
    "payload_codec",
    "idempotency_key",
    "origin",
    "origin_reference",
    "item_type",
    "content_type",
];

/// One validated item as `WorkflowStreamService::append` reads it.
#[derive(Debug, Default, PartialEq)]
pub(crate) struct StreamItem {
    payload: Value,
    payload_reference: Option<String>,
    payload_codec: Option<String>,
    idempotency_key: Option<String>,
    origin: Option<String>,
    origin_reference: Option<String>,
    item_type: Option<String>,
    content_type: Option<String>,
}

pub(crate) enum Appended {
    Accepted {
        stream: Value,
        offsets: Vec<i64>,
        accepted: usize,
        deduped: usize,
    },
    /// The batch would pass the cap, so none of it was written.
    Full { pending_items: i64 },
    Closed,
    Errored,
    /// Past its retention the stream reads as not found, append included.
    Expired,
    /// The run is gone, or retention has pruned its details: it has no
    /// streams to write (Server #774).
    RunPruned,
}

/// One retention pass over a namespace's due streams.
#[derive(Default)]
pub(crate) struct StreamReclaim {
    pub(crate) streams_reclaimed: i64,
    pub(crate) items_removed: i64,
    pub(crate) budget_exhausted: bool,
    /// Why the pass stopped short of a due stream.
    pub(crate) blocked_reason: Option<&'static str>,
}

/// One `read()` window.
pub(crate) struct Window {
    stream: Value,
    items: Vec<Value>,
    next_offset: i64,
    terminal: bool,
}

/// Carbon's `toJSON`.
fn carbon_json(at: DateTime<Utc>) -> String {
    at.format("%Y-%m-%dT%H:%M:%S%.6fZ").to_string()
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
    /// `NamespaceWorkflowScope::workflowBound` and `run`: the instance and
    /// the run both belong to this namespace.
    pub(super) async fn run_in_namespace(&self, workflow_id: &str, run_id: &str) -> Result<bool> {
        let found = Self::scalar("SELECT COUNT(*) FROM workflow_runs WHERE namespace=$1 AND workflow_instance_id=$2 AND id=$3 AND EXISTS (SELECT 1 FROM workflow_instances WHERE id=$4 AND namespace=$5)")
            .bind(self.namespace.as_ref())
            .bind(workflow_id)
            .bind(run_id)
            .bind(workflow_id)
            .bind(self.namespace.as_ref())
            .fetch_one(&self.pool)
            .await?;
        Ok(found > 0)
    }

    /// `locateRun`, then `LegacyV1Projection::isProjectedRun`: nothing when
    /// the run is not in this namespace, else whether it is a read-only
    /// Waterline v1 projection.
    pub(super) async fn stream_run(&self, workflow_id: &str, run_id: &str) -> Result<Option<bool>> {
        let row = Self::query("SELECT import_source FROM workflow_runs WHERE namespace=$1 AND workflow_instance_id=$2 AND id=$3 AND EXISTS (SELECT 1 FROM workflow_instances WHERE id=$4 AND namespace=$5)")
            .bind(self.namespace.as_ref())
            .bind(workflow_id)
            .bind(run_id)
            .bind(workflow_id)
            .bind(self.namespace.as_ref())
            .fetch_optional(&self.pool)
            .await?;
        match row {
            Some(row) => Ok(Some(DB::optional_string(&row, "import_source")?.as_deref() == Some(PROJECTION_SOURCE))),
            None => Ok(None),
        }
    }

    /// `formatStream`.
    /// `WorkflowStreamService::isExpired`: a closed or errored stream past
    /// `closed_at` plus its retention (3600 seconds when none was recorded).
    fn stream_expired(row: &DB::Row, at: DateTime<Utc>) -> Result<bool> {
        if DB::string(row, "status")? == "open" {
            return Ok(false);
        }
        let Some(closed) = DB::optional_instant(row, "closed_at")? else {
            return Ok(false);
        };
        let retention = DB::optional_number(row, "retention_seconds")?.unwrap_or(DEFAULT_CLOSED_RETENTION_SECONDS);
        Ok(closed + chrono::Duration::seconds(retention) <= at)
    }

    fn stream_json(row: &DB::Row) -> Result<Value> {
        let time = |field: &str| Ok::<_, RuntimeError>(DB::optional_instant(row, field)?.map(carbon_json));
        Ok(json!({
            "stream_name": DB::string(row, "stream_name")?,
            "status": DB::string(row, "status")?,
            "last_offset": DB::number(row, "last_offset")?,
            "total_items": DB::number(row, "total_items")?,
            "pending_items": DB::number(row, "pending_items")?,
            "opened_at": time("opened_at")?,
            "last_appended_at": time("last_appended_at")?,
            "closed_at": time("closed_at")?,
            "error_reason": DB::optional_string(row, "error_reason")?,
            "retention_seconds": DB::optional_number(row, "retention_seconds")?,
        }))
    }

    /// One item as `items()` renders it.
    fn stream_item_json(row: &DB::Row) -> Result<Value> {
        Ok(json!({
            "offset": DB::number(row, "offset")?,
            "idempotency_key": DB::optional_string(row, "idempotency_key")?,
            "origin": DB::string(row, "origin")?,
            "origin_reference": DB::optional_string(row, "origin_reference")?,
            "item_type": DB::optional_string(row, "item_type")?,
            "content_type": DB::optional_string(row, "content_type")?,
            "payload": DB::optional_document_row(row, "payload")?.unwrap_or(Value::Null),
            "payload_reference": DB::optional_string(row, "payload_reference")?,
            "payload_codec": DB::optional_string(row, "payload_codec")?,
            "emitted_at": carbon_json(DB::instant(row, "emitted_at")?),
        }))
    }

    /// `listForRun`: the run's streams by name, in the database's order.
    pub(super) async fn list_workflow_streams(&self, run_id: &str) -> Result<Vec<Value>> {
        let at = now();
        let mut streams = Vec::new();
        for row in Self::query("SELECT * FROM workflow_durable_streams WHERE namespace=$1 AND workflow_run_id=$2 ORDER BY stream_name")
            .bind(self.namespace.as_ref())
            .bind(run_id)
            .fetch_all(&self.pool)
            .await?
        {
            if !Self::stream_expired(&row, at)? {
                streams.push(Self::stream_json(&row)?);
            }
        }
        Ok(streams)
    }

    /// `describe` for a valid name.
    pub(super) async fn show_workflow_stream(&self, run_id: &str, name: &str) -> Result<Option<Value>> {
        let row = Self::query(STREAM_BY_NAME)
            .bind(self.namespace.as_ref())
            .bind(run_id)
            .bind(name)
            .fetch_optional(&self.pool)
            .await?;
        match row {
            Some(row) if !Self::stream_expired(&row, now())? => Ok(Some(Self::stream_json(&row)?)),
            _ => Ok(None),
        }
    }

    /// `WorkflowStreamService::append` for a valid name and a non-empty
    /// batch, in one transaction.
    pub(super) async fn append_workflow_stream(
        &self,
        workflow_id: &str,
        run_id: &str,
        name: &str,
        items: Vec<StreamItem>,
        max_pending: i64,
    ) -> Result<Appended> {
        let at = now().trunc_subsecs(0);
        let mut tx = self.begin().await?;
        // Run retention deletes a run's streams and marks its details pruned
        // under the same transition lock, so an append after it must not open
        // a stream on the pruned run, as PHP's `lockStream` refuses it.
        let pruned = Self::query("SELECT details_pruned_at FROM workflow_runs WHERE id=$1 AND namespace=$2")
            .bind(run_id)
            .bind(self.namespace.as_ref())
            .fetch_optional(&mut *tx)
            .await?;
        match pruned {
            Some(row) if DB::optional_instant(&row, "details_pruned_at")?.is_none() => {}
            _ => return Ok(Appended::RunPruned),
        }
        let found = Self::query(STREAM_BY_NAME)
            .bind(self.namespace.as_ref())
            .bind(run_id)
            .bind(name)
            .fetch_optional(&mut *tx)
            .await?;
        let row = match found {
            Some(row) => row,
            None => {
                Self::query("INSERT INTO workflow_durable_streams(namespace,workflow_instance_id,workflow_run_id,stream_name,status,last_offset,total_items,pending_items,opened_at,created_at,updated_at) VALUES ($1,$2,$3,$4,'open',-1,0,0,$5,$6,$7)")
                    .bind(self.namespace.as_ref())
                    .bind(workflow_id)
                    .bind(run_id)
                    .bind(name)
                    .bind(DB::bind_time(at))
                    .bind(DB::bind_time(at))
                    .bind(DB::bind_time(at))
                    .execute(&mut *tx)
                    .await?;
                Self::query(STREAM_BY_NAME)
                    .bind(self.namespace.as_ref())
                    .bind(run_id)
                    .bind(name)
                    .fetch_one(&mut *tx)
                    .await?
            }
        };
        // From the expiry instant a stream reads as not found for append
        // too. Its row stays until its run is pruned, so the name is never
        // opened again at offset 0 (Server #774).
        if Self::stream_expired(&row, at)? {
            return Ok(Appended::Expired);
        }
        match DB::string(&row, "status")?.as_str() {
            "closed" => return Ok(Appended::Closed),
            "errored" => return Ok(Appended::Errored),
            _ => {}
        }
        let stream_id = DB::number(&row, "id")?;
        let mut last_offset = DB::number(&row, "last_offset")?;
        let mut total = DB::number(&row, "total_items")?;
        let mut pending = DB::number(&row, "pending_items")?;
        // The whole batch is admitted or refused before any item is written
        // (Server #774). An item whose key is already stored, or earlier in
        // this batch, takes no capacity.
        let mut stored: HashMap<&str, Option<i64>> = HashMap::new();
        let mut fresh = 0;
        for item in &items {
            match item.idempotency_key.as_deref() {
                Some(key) if stored.contains_key(key) => {}
                Some(key) => {
                    let existing = Self::scalar(DB::STREAM_ITEM_BY_KEY).bind(stream_id).bind(key).fetch_optional(&mut *tx).await?;
                    if existing.is_none() {
                        fresh += 1;
                    }
                    stored.insert(key, existing);
                }
                None => fresh += 1,
            }
        }
        if fresh > 0 && pending + fresh > max_pending {
            // Dropping the transaction rolls back a stream this append opened.
            return Ok(Appended::Full { pending_items: pending });
        }
        let mut offsets = Vec::with_capacity(items.len());
        let (mut accepted, mut deduped) = (0, 0);
        for item in &items {
            if let Some(key) = item.idempotency_key.as_deref()
                && let Some(Some(existing)) = stored.get(key)
            {
                offsets.push(*existing);
                deduped += 1;
                continue;
            }
            last_offset += 1;
            Self::query(DB::STREAM_ITEM_INSERT)
                .bind(stream_id)
                .bind(self.namespace.as_ref())
                .bind(run_id)
                .bind(name)
                .bind(last_offset)
                .bind(item.idempotency_key.as_deref())
                .bind(item.origin.as_deref().unwrap_or("workflow_command"))
                .bind(item.origin_reference.as_deref())
                .bind(DB::optional_document(&item.payload))
                .bind(item.payload_reference.as_deref())
                .bind(item.payload_codec.as_deref())
                .bind(item.item_type.as_deref())
                .bind(item.content_type.as_deref())
                .bind(DB::bind_time(at))
                .bind(DB::bind_time(at))
                .bind(DB::bind_time(at))
                .execute(&mut *tx)
                .await?;
            if let Some(key) = item.idempotency_key.as_deref() {
                stored.insert(key, Some(last_offset));
            }
            total += 1;
            pending += 1;
            accepted += 1;
            offsets.push(last_offset);
        }
        // Eloquent saves only changed attributes, so a batch that adds
        // nothing leaves the row as it was.
        if accepted > 0 {
            Self::query("UPDATE workflow_durable_streams SET last_offset=$1,total_items=$2,pending_items=$3,last_appended_at=$4,updated_at=$5 WHERE id=$6")
                .bind(last_offset)
                .bind(total)
                .bind(pending)
                .bind(DB::bind_time(at))
                .bind(DB::bind_time(at))
                .bind(stream_id)
                .execute(&mut *tx)
                .await?;
        }
        let row = Self::query(STREAM_BY_ID).bind(stream_id).fetch_one(&mut *tx).await?;
        tx.commit().await?;
        Ok(Appended::Accepted { stream: Self::stream_json(&row)?, offsets, accepted, deduped })
    }

    /// `WorkflowStreamRetention::pruneExpired` for this namespace (Server
    /// #774). It reads at most `stream_limit` due streams in deadline order
    /// from `dw_stream_reclaims` and deletes at most `item_budget` of their
    /// items, in transactions of at most SWEEP_ITEM_BATCH under the
    /// transition lock. A stream with no items left leaves
    /// `dw_stream_reclaims`; its row stays until its run is pruned. Native
    /// settles no external payloads, so a stream whose items hold an
    /// external reference stops the pass, blocked, with its items kept.
    pub(super) async fn prune_expired_streams(&self, stream_limit: i64, item_budget: i64) -> Result<StreamReclaim> {
        let at = now();
        let due = Self::query(DUE_STREAMS)
            .bind(self.namespace.as_ref())
            .bind(DB::bind_time(at))
            .bind(stream_limit.max(1))
            .fetch_all(&self.pool)
            .await?
            .iter()
            .map(|row| DB::number(row, "stream_id"))
            .collect::<Result<Vec<_>>>()?;
        let budget = item_budget.max(1);
        let mut report = StreamReclaim::default();
        for stream_id in due {
            loop {
                if report.items_removed >= budget {
                    report.budget_exhausted = true;
                    return Ok(report);
                }
                let take = SWEEP_ITEM_BATCH.min(budget - report.items_removed);
                let mut tx = self.begin().await?;
                let Some(row) = Self::query(STREAM_BY_ID).bind(stream_id).fetch_optional(&mut *tx).await? else {
                    // The stream went with its run; its deadline goes too.
                    Self::query("DELETE FROM dw_stream_reclaims WHERE stream_id=$1").bind(stream_id).execute(&mut *tx).await?;
                    tx.commit().await?;
                    break;
                };
                if !Self::stream_expired(&row, at)? {
                    break;
                }
                if Self::scalar(DB::STREAM_ITEM_REFERENCES).bind(stream_id).fetch_one(&mut *tx).await? > 0 {
                    report.blocked_reason = Some("external_payload_cleanup_unavailable");
                    return Ok(report);
                }
                // The offset of the `take`th item, when that many remain.
                let through = Self::scalar(DB::STREAM_SWEEP_BOUNDARY)
                    .bind(stream_id)
                    .bind(take - 1)
                    .fetch_optional(&mut *tx)
                    .await?;
                if let Some(offset) = through {
                    let deleted = Self::query(DB::STREAM_SWEEP_THROUGH).bind(stream_id).bind(offset).execute(&mut *tx).await?;
                    tx.commit().await?;
                    report.items_removed += DB::affected(deleted) as i64;
                    continue;
                }
                let deleted = Self::query("DELETE FROM workflow_durable_stream_items WHERE stream_id=$1")
                    .bind(stream_id)
                    .execute(&mut *tx)
                    .await?;
                // The terminal row keeps the name; without a deadline the
                // pass no longer reads it.
                Self::query("DELETE FROM dw_stream_reclaims WHERE stream_id=$1").bind(stream_id).execute(&mut *tx).await?;
                tx.commit().await?;
                report.items_removed += DB::affected(deleted) as i64;
                report.streams_reclaimed += 1;
                break;
            }
        }
        Ok(report)
    }

    /// `WorkflowStreamService::read` for a valid name and offset.
    pub(super) async fn read_workflow_stream(
        &self,
        run_id: &str,
        name: &str,
        from: i64,
        max_items: i64,
    ) -> Result<Option<Window>> {
        let Some(mut row) = Self::query(STREAM_BY_NAME)
            .bind(self.namespace.as_ref())
            .bind(run_id)
            .bind(name)
            .fetch_optional(&self.pool)
            .await?
        else {
            return Ok(None);
        };
        if Self::stream_expired(&row, now())? {
            return Ok(None);
        }
        let stream_id = DB::number(&row, "id")?;
        let rows = Self::query(DB::STREAM_ITEMS_WINDOW)
            .bind(stream_id)
            .bind(from)
            .bind(max_items.clamp(1, SUBSCRIBE_MAX_ITEMS))
            .fetch_all(&self.pool)
            .await?;
        let last = rows.last().map(|item| DB::number(item, "offset")).transpose()?;
        let next_offset = last.map_or(from, |offset| offset + 1);
        let head = DB::number(&row, "last_offset")?;
        // The window reached the head: nothing is pending past it.
        if last == Some(head) && DB::number(&row, "pending_items")? > 0 {
            let mut tx = self.begin().await?;
            Self::query("UPDATE workflow_durable_streams SET pending_items=0,updated_at=$1 WHERE id=$2 AND last_offset=$3")
                .bind(DB::bind_time(now().trunc_subsecs(0)))
                .bind(stream_id)
                .bind(head)
                .execute(&mut *tx)
                .await?;
            row = Self::query(STREAM_BY_ID).bind(stream_id).fetch_one(&mut *tx).await?;
            tx.commit().await?;
        }
        let terminal = DB::string(&row, "status")? != "open" && next_offset > DB::number(&row, "last_offset")?;
        Ok(Some(Window {
            stream: Self::stream_json(&row)?,
            items: rows.iter().map(Self::stream_item_json).collect::<Result<_>>()?,
            next_offset,
            terminal,
        }))
    }

    /// `WorkflowStreamService::close` for a valid name: a terminal stream is
    /// returned unchanged.
    pub(super) async fn close_workflow_stream(
        &self,
        run_id: &str,
        name: &str,
        error_reason: Option<String>,
        retention_seconds: Option<i64>,
    ) -> Result<Option<Value>> {
        let mut tx = self.begin().await?;
        let Some(row) = Self::query(STREAM_BY_NAME)
            .bind(self.namespace.as_ref())
            .bind(run_id)
            .bind(name)
            .fetch_optional(&mut *tx)
            .await?
        else {
            return Ok(None);
        };
        if Self::stream_expired(&row, now())? {
            return Ok(None);
        }
        if DB::string(&row, "status")? != "open" {
            return Ok(Some(Self::stream_json(&row)?));
        }
        let at = now().trunc_subsecs(0);
        let retention = match retention_seconds {
            Some(seconds) if seconds > 0 => seconds,
            _ => DB::optional_number(&row, "retention_seconds")?.unwrap_or(DEFAULT_CLOSED_RETENTION_SECONDS),
        };
        let stream_id = DB::number(&row, "id")?;
        Self::query("UPDATE workflow_durable_streams SET status=$1,error_reason=$2,closed_at=$3,retention_seconds=$4,updated_at=$5 WHERE id=$6")
            .bind(if error_reason.is_some() { "errored" } else { "closed" })
            .bind(error_reason.as_deref())
            .bind(DB::bind_time(at))
            .bind(retention)
            .bind(DB::bind_time(at))
            .bind(stream_id)
            .execute(&mut *tx)
            .await?;
        // The retention pass's deadline: the instant the stream expires.
        Self::query("INSERT INTO dw_stream_reclaims(stream_id,namespace,workflow_run_id,reclaim_at) VALUES ($1,$2,$3,$4)")
            .bind(stream_id)
            .bind(self.namespace.as_ref())
            .bind(run_id)
            .bind(DB::bind_time(at + chrono::Duration::seconds(retention)))
            .execute(&mut *tx)
            .await?;
        let row = Self::query(STREAM_BY_ID).bind(stream_id).fetch_one(&mut *tx).await?;
        tx.commit().await?;
        Ok(Some(Self::stream_json(&row)?))
    }
}

fn protocol(status: StatusCode, response: Value) -> RuntimeError {
    RuntimeError::Protocol { status, response }
}

fn run_not_found() -> RuntimeError {
    protocol(StatusCode::NOT_FOUND, json!({"message":"Workflow run not found.","reason":"instance_not_found"}))
}

fn stream_not_found() -> RuntimeError {
    protocol(StatusCode::NOT_FOUND, json!({"message":"Stream not found.","reason":"stream_not_found"}))
}

/// `WorkflowStreamService::STREAM_NAME_PATTERN`.
fn valid_name(name: &str) -> bool {
    let bytes = name.as_bytes();
    !bytes.is_empty()
        && bytes.len() <= 191
        && bytes[0].is_ascii_alphanumeric()
        && bytes[1..].iter().all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'.' | b'_' | b':' | b'-'))
}

/// The service's invalid-name refusal, which `append()` reports with its
/// general reason and the other routes with their own.
fn invalid_name(reason: &str) -> RuntimeError {
    protocol(StatusCode::BAD_REQUEST, json!({"message":"invalid stream name","reason":reason}))
}

/// The run lookup every route makes first.
async fn located(runtime: &Runtime, workflow_id: &str, run_id: &str) -> Result<bool> {
    runtime.stream_run(workflow_id, run_id).await?.ok_or_else(run_not_found)
}

/// `projectedRunReadOnly`.
fn projected_read_only(workflow_id: &str, run_id: &str) -> RuntimeError {
    protocol(StatusCode::CONFLICT, json!({
        "message": "Durable streams cannot mutate a read-only v1 projection.",
        "reason": "v1_projection_read_only",
        "remediation": "Continue execution and stream production on the source v1 application.",
        "workflow_id": workflow_id,
        "run_id": run_id,
        "execution_owner": "v1",
    }))
}

/// The items of a normalized append body, in data order, whether `items`
/// is a list or keyed.
fn body_items(body: &Value) -> Vec<&Value> {
    match &body["items"] {
        Value::Array(items) => items.iter().collect(),
        Value::Object(items) => items.values().collect(),
        _ => Vec::new(),
    }
}

/// `RuntimeExternalPayloadTransport::incoming` on `items.*.payload_reference`,
/// which runs before the controller: a value other than null or a reference
/// object is refused. Native does not resolve reference objects yet.
fn references(body: &Value) -> Result<()> {
    for item in body_items(body) {
        match item.get("payload_reference") {
            None | Some(Value::Null) => {}
            Some(Value::Array(_) | Value::Object(_)) => {
                return Err(refuse(StatusCode::NOT_IMPLEMENTED, "rust_capability_not_implemented"));
            }
            Some(_) => {
                return Err(super::external_transport::typed_error(
                    StatusCode::UNPROCESSABLE_ENTITY,
                    "external_payload_unsupported",
                    "Payload reference fields require an opaque runtime external payload reference object.",
                ));
            }
        }
    }
    Ok(())
}

/// The validated items `append()` passes on: Laravel's validated data keeps
/// only the item fields, so an item with none of them is left out. A
/// present codec is canonicalized, and the first one refused fails the
/// request as `items.{index}.payload_codec`.
fn validated_items(body: &Value) -> Result<Vec<StreamItem>> {
    let keyed: Vec<(String, &Value)> = match &body["items"] {
        Value::Array(items) => items.iter().enumerate().map(|(index, item)| (index.to_string(), item)).collect(),
        Value::Object(items) => items.iter().map(|(key, item)| (key.clone(), item)).collect(),
        _ => Vec::new(),
    };
    let mut items = Vec::with_capacity(keyed.len());
    for (key, item) in keyed {
        let Some(fields) = item.as_object() else {
            continue;
        };
        if !ITEM_FIELDS.iter().any(|field| fields.contains_key(*field)) {
            continue;
        }
        let text = |field: &str| fields.get(field).and_then(Value::as_str).map(str::to_owned);
        let payload_codec = match fields.get("payload_codec") {
            None => None,
            Some(codec) => match super::input_envelope::codec_refusal(codec) {
                None => Some("avro".to_owned()),
                Some(message) => {
                    return Err(super::laravel_validation::failure_messages(vec![(
                        format!("items.{key}.payload_codec"),
                        vec![message],
                    )])
                    .expect("one message"));
                }
            },
        };
        items.push(StreamItem {
            // The array cast stores a PHP array, so an empty object reads back
            // as a list, as PHP's other array round trips do.
            payload: super::activity_failures::php_array(fields.get("payload").cloned().unwrap_or(Value::Null)),
            payload_reference: text("payload_reference"),
            payload_codec,
            // The service treats an empty key as none; normalization already
            // turned empty strings into null.
            idempotency_key: text("idempotency_key"),
            origin: text("origin"),
            origin_reference: text("origin_reference"),
            item_type: text("item_type"),
            content_type: text("content_type"),
        });
    }
    Ok(items)
}

/// `items()`'s Laravel rules for `from`, `max_items` and `wait_seconds`.
fn items_rules(query: &HashMap<String, String>) -> Result<()> {
    let mut errors: Vec<(&'static str, Vec<&'static str>)> = Vec::new();
    let mut from = vec![];
    match query_integer(query, "from") {
        Some(Err(())) => from.push("The from field must be an integer."),
        Some(Ok(value)) if value < 0 => from.push("The from field must be at least 0."),
        _ => {}
    }
    errors.push(("from", from));
    let mut max_items = vec![];
    match query_integer(query, "max_items") {
        Some(Err(())) => max_items.push("The max items field must be an integer."),
        Some(Ok(value)) if value < 1 => max_items.push("The max items field must be at least 1."),
        Some(Ok(value)) if value > 500 => max_items.push("The max items field must not be greater than 500."),
        _ => {}
    }
    errors.push(("max_items", max_items));
    let mut wait = vec![];
    match query_integer(query, "wait_seconds") {
        Some(Err(())) => wait.push("The wait seconds field must be an integer."),
        Some(Ok(value)) if value < 0 => wait.push("The wait seconds field must be at least 0."),
        Some(Ok(value)) if value > 60 => wait.push("The wait seconds field must not be greater than 60."),
        _ => {}
    }
    errors.push(("wait_seconds", wait));
    match failure(errors) {
        Some(error) => Err(error),
        None => Ok(()),
    }
}

/// A query value as the `integer` rule reads it. Query values are strings;
/// empty ones become null and pass `nullable`.
fn query_integer(query: &HashMap<String, String>, field: &str) -> Option<std::result::Result<i64, ()>> {
    let value = normalized(query.get(field).map(|text| json!(text)).as_ref())?;
    (!value.is_null()).then(|| integer(&value).ok_or(()))
}

/// `GET /api/workflows/{id}/runs/{run}/streams`.
pub(super) async fn list(
    Extension(runtime): Extension<Runtime>,
    Path((workflow_id, run_id)): Path<(String, String)>,
) -> Result<Json<Value>> {
    located(&runtime, &workflow_id, &run_id).await?;
    let streams = runtime.list_workflow_streams(&run_id).await?;
    Ok(Json(json!({"workflow_id":workflow_id,"workflow_run_id":run_id,"count":streams.len(),"streams":streams})))
}

/// `GET /api/workflows/{id}/runs/{run}/streams/{name}`.
pub(super) async fn show(
    Extension(runtime): Extension<Runtime>,
    Path((workflow_id, run_id, name)): Path<(String, String, String)>,
) -> Result<Json<Value>> {
    located(&runtime, &workflow_id, &run_id).await?;
    if !valid_name(&name) {
        return Err(invalid_name("invalid_stream_name"));
    }
    let stream = runtime.show_workflow_stream(&run_id, &name).await?.ok_or_else(stream_not_found)?;
    Ok(Json(json!({"workflow_id":workflow_id,"workflow_run_id":run_id,"stream":stream})))
}

/// `GET /api/workflows/{id}/runs/{run}/streams/{name}/items`, with
/// `readWithWait`'s bounded wait for an open stream with nothing new.
pub(super) async fn items(
    Extension(runtime): Extension<Runtime>,
    Path((workflow_id, run_id, name)): Path<(String, String, String)>,
    Query(query): Query<HashMap<String, String>>,
) -> Result<Json<Value>> {
    located(&runtime, &workflow_id, &run_id).await?;
    items_rules(&query)?;
    let value = |field: &str, default: i64| query_integer(&query, field).and_then(|value| value.ok()).unwrap_or(default);
    let from = value("from", 0);
    let max_items = value("max_items", DEFAULT_MAX_ITEMS_PER_SUBSCRIBE);
    let wait_seconds = value("wait_seconds", 0);
    if !valid_name(&name) {
        return Err(invalid_name("invalid_stream_name"));
    }
    let (reader, run, stream) = (&runtime, run_id.as_str(), name.as_str());
    let read = move || async move {
        reader.read_workflow_stream(run, stream, from, max_items).await?.ok_or_else(stream_not_found)
    };
    let mut window = read().await?;
    if wait_seconds > 0 && window.items.is_empty() && !window.terminal {
        let deadline = Instant::now() + Duration::from_secs(wait_seconds as u64);
        while Instant::now() < deadline {
            tokio::time::sleep(WAIT_INTERVAL).await;
            window = read().await?;
            if !window.items.is_empty() || window.terminal {
                break;
            }
        }
    }
    Ok(Json(json!({"workflow_id":workflow_id,"workflow_run_id":run_id,"stream":window.stream,
        "items":window.items,"next_offset":window.next_offset,"terminal":window.terminal})))
}

/// `POST /api/workflows/{id}/runs/{run}/streams/{name}/items`.
pub(super) async fn append(
    Extension(runtime): Extension<Runtime>,
    Path((workflow_id, run_id, name)): Path<(String, String, String)>,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    // Laravel trims every string, payloads included, before anything reads it.
    let body = super::start_request::normalized(body);
    references(&body)?;
    if located(&runtime, &workflow_id, &run_id).await? {
        return Err(projected_read_only(&workflow_id, &run_id));
    }
    laravel_rules::validate(&body, APPEND_RULES)?;
    let items = validated_items(&body)?;
    let max_pending = body.get("max_pending_items").and_then(integer).unwrap_or(DEFAULT_MAX_PENDING_ITEMS);
    if !valid_name(&name) {
        return Err(invalid_name("invalid_request"));
    }
    if items.is_empty() {
        return Err(protocol(StatusCode::BAD_REQUEST,
            json!({"message":"append requires at least one item","reason":"invalid_request"})));
    }
    match runtime.append_workflow_stream(&workflow_id, &run_id, &name, items, max_pending).await? {
        Appended::Accepted { stream, offsets, accepted, deduped } => Ok(Json(json!({
            "workflow_id": workflow_id,
            "workflow_run_id": run_id,
            "stream": stream,
            "accepted_offsets": offsets,
            "accepted": accepted,
            "deduped": deduped,
        }))),
        Appended::Full { pending_items } => Err(protocol(StatusCode::TOO_MANY_REQUESTS, json!({
            "message": "Stream pending-items cap reached; consumer is draining too slowly.",
            "reason": "stream_full",
            "pending_items": pending_items,
            "max_pending_items": max_pending,
        }))),
        Appended::Expired | Appended::RunPruned => Err(stream_not_found()),
        Appended::Closed => Err(protocol(StatusCode::CONFLICT, json!({"message":"Stream is closed.","reason":"stream_closed"}))),
        Appended::Errored => Err(protocol(StatusCode::CONFLICT, json!({"message":"Stream is errored.","reason":"stream_errored"}))),
    }
}

/// `POST /api/workflows/{id}/runs/{run}/streams/{name}/close`.
pub(super) async fn close(
    Extension(runtime): Extension<Runtime>,
    Path((workflow_id, run_id, name)): Path<(String, String, String)>,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    let body = super::start_request::normalized(body);
    if located(&runtime, &workflow_id, &run_id).await? {
        return Err(projected_read_only(&workflow_id, &run_id));
    }
    laravel_rules::validate(&body, CLOSE_RULES)?;
    let error_reason = body.get("error_reason").and_then(Value::as_str).map(str::to_owned);
    let retention_seconds = body.get("retention_seconds").and_then(integer);
    if !valid_name(&name) {
        return Err(invalid_name("invalid_stream_name"));
    }
    let stream = runtime
        .close_workflow_stream(&run_id, &name, error_reason, retention_seconds)
        .await?
        .ok_or_else(stream_not_found)?;
    Ok(Json(json!({"workflow_id":workflow_id,"workflow_run_id":run_id,"stream":stream})))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn query(pairs: &[(&str, &str)]) -> HashMap<String, String> {
        pairs.iter().map(|(key, value)| ((*key).to_owned(), (*value).to_owned())).collect()
    }

    fn refusal(result: Result<impl std::fmt::Debug>) -> (StatusCode, Value) {
        match result {
            Err(RuntimeError::Protocol { status, response }) => (status, response),
            other => panic!("{other:?}"),
        }
    }

    #[test]
    fn stream_names_follow_php_pattern() {
        assert!(valid_name("orders.v1:main-2"));
        assert!(!valid_name("-leading"));
        assert!(!valid_name("has space"));
        assert!(!valid_name(&"a".repeat(192)));
        assert!(valid_name(&"a".repeat(191)));
    }

    #[test]
    fn item_rules_report_laravel_messages() {
        let (_, response) = refusal(items_rules(&query(&[("from", "x"), ("max_items", "501"), ("wait_seconds", "61")])));
        assert_eq!(response["message"], "The from field must be an integer. (and 2 more errors)");
        assert!(items_rules(&HashMap::new()).is_ok());
        let (_, response) = refusal(items_rules(&query(&[("from", "05"), ("max_items", "+5"), ("wait_seconds", " ")])));
        assert_eq!(response["errors"], json!({"from":["The from field must be an integer."]}));
    }

    #[test]
    fn append_rules_report_laravel_messages() {
        let check = |body: Value| refusal(laravel_rules::validate(&super::super::start_request::normalized(body), APPEND_RULES)).1;
        assert_eq!(check(json!({"items":[]}))["message"], "The items field is required.");
        assert_eq!(check(json!({"items":["text"]}))["errors"], json!({"items.0":["The items.0 field must be an array."]}));
        assert_eq!(check(json!({"items":[{"payload":1}],"max_pending_items":0}))["message"],
            "The max pending items field must be at least 1.");
        assert_eq!(check(json!({"items":[{"idempotency_key":"k".repeat(192)}]}))["message"],
            "The items.0.idempotency_key field must not be greater than 191 characters.");
        // Plain attributes report before the expanded item attributes.
        assert_eq!(check(json!({"items":[{"origin":5}],"max_pending_items":"x"}))["message"],
            "The max pending items field must be an integer. (and 1 more error)");
    }

    #[test]
    fn validated_items_keep_only_item_fields_and_canonicalize_codecs() {
        let body = super::super::start_request::normalized(json!({"items":[
            {"payload":{"token":" Hello ","empty":{}},"payload_codec":null,"idempotency_key":"  "},
            {},
            {"unknown":1},
            {"payload":null},
        ]}));
        let items = validated_items(&body).unwrap();
        assert_eq!(items.len(), 2);
        assert_eq!(items[0].payload, json!({"token":"Hello","empty":[]}));
        assert_eq!(items[0].payload_codec.as_deref(), Some("avro"));
        assert_eq!(items[0].idempotency_key, None);
        assert_eq!(items[1], StreamItem::default());
        let (status, response) = refusal(validated_items(&json!({"items":[{"payload":1},{"payload_codec":"json"}]})));
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY);
        assert!(response["errors"]["items.1.payload_codec"][0].as_str().unwrap()
            .starts_with("unsupported_payload_codec: workflow payload codec \"json\""));
    }

    #[test]
    fn references_refuse_strings_and_defer_reference_objects() {
        assert!(references(&json!({"items":[{"payload_reference":null},{"payload":1}]})).is_ok());
        assert!(references(&json!({"items":"text"})).is_ok());
        let (status, response) = refusal(references(&json!({"items":[{"payload_reference":"dw-ref"}]})));
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY);
        assert_eq!(response["reason"], "external_payload_unsupported");
        assert!(matches!(references(&json!({"items":{"a":{"payload_reference":{"reference_id":"x"}}}})),
            Err(RuntimeError::Refused { status: StatusCode::NOT_IMPLEMENTED, reason: "rust_capability_not_implemented" })));
    }

    #[test]
    fn times_render_as_carbon_json() {
        let at = DateTime::parse_from_rfc3339("2026-10-25T06:42:31.987Z").unwrap().with_timezone(&Utc);
        assert_eq!(carbon_json(at.trunc_subsecs(0)), "2026-10-25T06:42:31.000000Z");
    }
}
