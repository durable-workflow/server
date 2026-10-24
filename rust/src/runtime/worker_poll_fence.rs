//! `WorkerPollFence`, as published PHP's workflow and activity pollers keep
//! it: a held poll belongs to the worker registration it started under. The
//! poll snapshots the registration when it is admitted; before every probe,
//! and inside the claim transaction, a snapshot that no longer matches the
//! current registration ends the poll with `stale_worker_registration`, so
//! a poll left behind by a replaced process cannot lease work meant for its
//! replacement.
//!
//! A snapshot is the routing revision (queue, build ID, workflow and
//! activity types, definition fingerprints, command contracts and
//! capabilities), the process identity a worker reports (host, process ID,
//! process start), and the registration's update and heartbeat times. A
//! worker that reports its process is matched by that identity; one that
//! does not, by those times, which PHP keeps in whole seconds, so a heartbeat
//! ends its held poll. A registration that is not active or has not
//! heartbeated within the stale window is never current.
use super::backend::Backend;
use super::{Result, store::now};
use serde_json::{Value, json};
use std::collections::BTreeMap;

pub(super) const STALE: &str = "stale_worker_registration";

/// The registration a fence reads.
const FENCE_ROW: &str = "SELECT status,task_queue,build_id,supported_workflow_types,workflow_definition_fingerprints,workflow_command_contracts,supported_activity_types,capabilities,process_metrics,updated_at,last_heartbeat_at FROM workflow_worker_registrations WHERE namespace=$1 AND worker_id=$2";

/// How a poll is admitted.
pub(super) enum PollAdmission {
    /// No registration for this worker on this queue; the claim refuses it.
    Unregistered,
    /// A registration that is not fresh: the poll is answered at once.
    Stale,
    /// The registration the poll belongs to.
    Fenced(WorkerPollFence),
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub(super) struct WorkerPollFence {
    routing: Value,
    process: BTreeMap<&'static str, Value>,
    updated_at: Option<i64>,
    last_heartbeat_at: Option<i64>,
}

/// `stringValue`: a trimmed, non-empty string.
fn string_value(value: Option<String>) -> Value {
    value.map(|text| text.trim().to_owned()).filter(|text| !text.is_empty()).map_or(Value::Null, Value::String)
}

/// `stringList`: the distinct trimmed, non-empty strings, sorted.
fn string_list(value: Option<Value>) -> Value {
    let mut strings: Vec<String> = value
        .as_ref()
        .and_then(Value::as_array)
        .into_iter()
        .flatten()
        .filter_map(Value::as_str)
        .map(str::trim)
        .filter(|text| !text.is_empty())
        .map(str::to_owned)
        .collect();
    strings.sort();
    strings.dedup();
    json!(strings)
}

/// `arrayValue`: an array or object as stored (object keys compare
/// unordered, as PHP's `ksort` makes them); anything else, or an empty
/// object, is an empty array, as PHP decodes both.
fn array_value(value: Option<Value>) -> Value {
    match value {
        Some(Value::Object(map)) if map.is_empty() => json!([]),
        Some(value @ (Value::Array(_) | Value::Object(_))) => value,
        _ => json!([]),
    }
}

/// `workerProcessIdentity`: host and process start as trimmed, non-empty
/// strings, and the process ID as an integer.
fn process_identity(metrics: Option<Value>) -> BTreeMap<&'static str, Value> {
    let mut identity = BTreeMap::new();
    let Some(Value::Object(metrics)) = metrics else {
        return identity;
    };
    for key in ["host", "process_started_at"] {
        if let Some(text) = metrics.get(key).and_then(Value::as_str).map(str::trim).filter(|text| !text.is_empty()) {
            identity.insert(key, json!(text));
        }
    }
    let process_id = match metrics.get("process_id") {
        Some(Value::Number(number)) => number.as_i64(),
        Some(Value::String(text)) if !text.is_empty() && text.bytes().all(|byte| byte.is_ascii_digit()) => text.parse().ok(),
        _ => None,
    };
    if let Some(process_id) = process_id {
        identity.insert("process_id", json!(process_id));
    }
    identity
}

impl WorkerPollFence {
    /// The snapshot of a registration row read with `FENCE_ROW`.
    pub(super) fn snapshot<DB: Backend>(row: &DB::Row) -> Result<Self> {
        let routing = json!({
            "task_queue": string_value(DB::optional_string(row, "task_queue")?),
            "build_id": string_value(DB::optional_string(row, "build_id")?),
            "supported_workflow_types": string_list(DB::optional_document_row(row, "supported_workflow_types")?),
            "workflow_definition_fingerprints": array_value(DB::optional_document_row(row, "workflow_definition_fingerprints")?),
            "workflow_command_contracts": array_value(DB::optional_document_row(row, "workflow_command_contracts")?),
            "supported_activity_types": string_list(DB::optional_document_row(row, "supported_activity_types")?),
            "capabilities": string_list(DB::optional_document_row(row, "capabilities")?),
        });
        Ok(Self {
            routing,
            process: process_identity(DB::optional_document_row(row, "process_metrics")?),
            updated_at: DB::optional_instant(row, "updated_at")?.map(|at| at.timestamp()),
            last_heartbeat_at: DB::optional_instant(row, "last_heartbeat_at")?.map(|at| at.timestamp()),
        })
    }

    /// `isFresh`: active, and heartbeated within the stale window.
    pub(super) fn fresh<DB: Backend>(row: &DB::Row, stale_after_seconds: i64) -> Result<bool> {
        let cutoff = now() - chrono::Duration::seconds(stale_after_seconds);
        Ok(DB::optional_string(row, "status")?.as_deref() == Some("active")
            && DB::optional_instant(row, "last_heartbeat_at")?.is_some_and(|heartbeat| heartbeat >= cutoff))
    }

    /// `isCurrent`: the current registration row is fresh and still the one
    /// this snapshot was taken of.
    pub(super) fn holds<DB: Backend>(&self, current: Option<&DB::Row>, stale_after_seconds: i64) -> Result<bool> {
        let Some(row) = current else {
            return Ok(false);
        };
        if !Self::fresh::<DB>(row, stale_after_seconds)? {
            return Ok(false);
        }
        let current = Self::snapshot::<DB>(row)?;
        if current.routing != self.routing {
            return Ok(false);
        }
        if !self.process.is_empty() || !current.process.is_empty() {
            return Ok(current.process == self.process);
        }
        Ok(current.updated_at == self.updated_at && current.last_heartbeat_at == self.last_heartbeat_at)
    }
}

/// The poll answer for a fenced-off registration.
pub(super) fn stale_answer() -> Value {
    json!({"task": null, "poll_status": STALE, "server_capabilities": super::store::capabilities()})
}

/// The refusal a claim raises when its poll's registration was replaced;
/// the poll answers it as [`stale_answer`].
pub(super) fn stale_claim() -> super::RuntimeError {
    super::RuntimeError::Protocol { status: axum::http::StatusCode::OK, response: json!({"task": null, "poll_status": STALE}) }
}

/// Whether an error is [`stale_claim`].
pub(super) fn is_stale_claim(error: &super::RuntimeError) -> bool {
    matches!(error, super::RuntimeError::Protocol { status, response }
        if *status == axum::http::StatusCode::OK && response["poll_status"] == STALE)
}

store_methods! {
    /// `WorkerPollFence::snapshot` at admission, after `isFresh`.
    pub(super) async fn admit_worker_poll(&self, worker_id: &str, queue: &str) -> Result<PollAdmission> {
        let row = Self::query(FENCE_ROW).bind(self.namespace.as_ref()).bind(worker_id).fetch_optional(&self.pool).await?;
        let Some(row) = row.filter(|row| DB::optional_string(row, "task_queue").ok().flatten().as_deref() == Some(queue)) else {
            return Ok(PollAdmission::Unregistered);
        };
        if !WorkerPollFence::fresh::<DB>(&row, self.worker_stale_after_seconds)? {
            return Ok(PollAdmission::Stale);
        }
        Ok(PollAdmission::Fenced(WorkerPollFence::snapshot::<DB>(&row)?))
    }

    /// `isCurrent` before a probe.
    pub(super) async fn worker_poll_fence_holds(&self, fence: &WorkerPollFence, worker_id: &str) -> Result<bool> {
        let row = Self::query(FENCE_ROW).bind(self.namespace.as_ref()).bind(worker_id).fetch_optional(&self.pool).await?;
        fence.holds::<DB>(row.as_ref(), self.worker_stale_after_seconds)
    }

    /// `isCurrentForUpdate` inside a claim transaction, which transitions
    /// take one at a time.
    pub(super) async fn worker_poll_fence_holds_in(
        &self,
        tx: &mut super::transaction::Transaction<'_, DB>,
        fence: &WorkerPollFence,
        worker_id: &str,
    ) -> Result<bool> {
        let row = Self::query(FENCE_ROW).bind(self.namespace.as_ref()).bind(worker_id).fetch_optional(&mut **tx).await?;
        fence.holds::<DB>(row.as_ref(), self.worker_stale_after_seconds)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn routing_lists_compare_as_sets_and_identity_as_php_reads_it() {
        assert_eq!(string_list(Some(json!([" b", "a", "b", "", 3]))), json!(["a", "b"]));
        assert_eq!(string_value(Some("  q ".into())), json!("q"));
        assert_eq!(string_value(Some("   ".into())), Value::Null);
        assert_eq!(array_value(Some(json!({}))), json!([]));
        assert_eq!(array_value(Some(json!("x"))), json!([]));
        let identity = process_identity(Some(json!({"host":" h ","process_id":"42","process_started_at":"","cpu_percent":3})));
        assert_eq!(identity, BTreeMap::from([("host", json!("h")), ("process_id", json!(42))]));
        assert!(process_identity(Some(json!({"process_id":"4x"}))).is_empty());
        assert!(process_identity(None).is_empty());
    }
}
