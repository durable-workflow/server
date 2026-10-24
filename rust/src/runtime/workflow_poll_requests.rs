//! Workflow-task poll request idempotency, as published PHP keeps it
//! (`WorkflowTaskPollRequestStore` with `PollRequestLeaseBinding`). A request
//! ID replays its last answer while that answer is still deliverable: an
//! empty or queue-changed poll for the pending window, a lease for as long as
//! its task stays leased to the worker at the same attempt, with the task's
//! current lease. Once a leased task is no longer deliverable, the ID polls
//! afresh. PHP never refuses a retry as expired.
use super::transaction::Transaction;
use super::{Result, store::now};
use serde_json::{Value, json};
use sha2::{Digest, Sha256};

// Distinct from the query poll prefix, so each sweep sees only its own rows.
const PREFIX: &str = "dw-native-workflow-poll:";
/// The task kinds a workflow poll binds its request ID to.
const WORKFLOW_KINDS: [&str; 1] = ["workflow"];

pub(super) struct WorkflowPollKeys {
    binding: String,
    result: String,
}

/// A workflow poll's request ID, its keys and its pending window.
pub(super) struct WorkflowPollRequest {
    pub(super) id: String,
    pub(super) keys: WorkflowPollKeys,
    pub(super) pending_ttl: i64,
}

/// The request ID with surrounding space removed, as Laravel's TrimStrings
/// and the poller's `nonEmptyString` leave it. A blank ID asks for no
/// idempotency.
pub(super) fn request_id(body: &serde_json::Value) -> Option<String> {
    body.get("poll_request_id")
        .and_then(Value::as_str)
        .map(str::trim)
        .filter(|id| !id.is_empty())
        .map(str::to_owned)
}

/// PHP's pending window: the configured poll timeout plus five seconds.
pub(super) fn pending_ttl(default_wait: u64) -> i64 {
    (i64::try_from(default_wait).unwrap_or(60) + 5).max(5)
}

fn epoch() -> i64 {
    now().timestamp()
}

store_methods! {
    /// Keys cover namespace, queue, the registration's build ID, the worker
    /// and the request ID, as PHP's cache keys do.
    pub(super) async fn workflow_poll_keys(&self, worker_id: &str, queue: &str, request_id: &str) -> Result<WorkflowPollKeys> {
        let build = Self::query("SELECT build_id FROM workflow_worker_registrations WHERE namespace=$1 AND worker_id=$2")
            .bind(self.namespace.as_ref())
            .bind(worker_id)
            .fetch_optional(&self.pool)
            .await?
            .map(|row| DB::optional_string(&row, "build_id"))
            .transpose()?
            .flatten()
            .filter(|build| !build.is_empty());
        // MySQL and MariaDB keys are varchar(64): keep 128 bits of the digest.
        let key = |kind: &str| {
            let material = json!([kind, self.namespace.as_ref(), queue, build, worker_id, request_id]);
            let digest: String = Sha256::digest(material.to_string().as_bytes())[..16]
                .iter()
                .map(|byte| format!("{byte:02x}"))
                .collect();
            format!("{PREFIX}{digest}")
        };
        Ok(WorkflowPollKeys { binding: key("binding"), result: key("result") })
    }

    /// At the start of a poll: bind the request ID and replay its answer if
    /// that answer is still deliverable. A stale lease is forgotten, and the
    /// caller polls afresh under the same ID.
    pub(super) async fn replay_workflow_poll(
        &self,
        keys: &WorkflowPollKeys,
        worker_id: &str,
        queue: &str,
        pending_ttl: i64,
    ) -> Result<Option<Value>> {
        let mut tx = self.begin().await?;
        Self::sweep_workflow_poll_rows(&mut tx).await?;
        if Self::workflow_poll_row(&mut tx, &keys.binding).await?.is_none() {
            Self::put_workflow_poll_row(&mut tx, &keys.binding, &json!({"task_kinds": WORKFLOW_KINDS}), pending_ttl).await?;
        }
        let replay = Self::deliverable_workflow_poll(&mut tx, keys, worker_id, queue).await?;
        tx.commit().await?;
        Ok(replay)
    }

    /// The cached answer if its task can still be delivered, with the task's
    /// current lease, attempt and owner; a stale answer is deleted.
    pub(super) async fn deliverable_workflow_poll(
        tx: &mut Transaction<'_, DB>,
        keys: &WorkflowPollKeys,
        worker_id: &str,
        queue: &str,
    ) -> Result<Option<Value>> {
        let Some(mut result) = Self::workflow_poll_row(tx, &keys.result).await? else {
            return Ok(None);
        };
        let Some(task_id) = result["task"]["task_id"].as_str().map(str::to_owned) else {
            // An empty or queue-changed answer stays as it was.
            return Ok(Some(result));
        };
        let row = Self::query("SELECT status,queue,lease_owner,lease_expires_at,attempt_count FROM workflow_tasks WHERE id=$1 AND task_type='workflow'")
            .bind(&task_id)
            .fetch_optional(&mut **tx)
            .await?;
        if let Some(row) = row
            && DB::string(&row, "status")? == "leased"
            && DB::optional_string(&row, "queue")?.as_deref() == Some(queue)
            && DB::optional_string(&row, "lease_owner")?.as_deref() == Some(worker_id)
            && result["task"]["workflow_task_attempt"].as_i64() == Some(DB::number(&row, "attempt_count")?)
            && let Some(expires) = DB::optional_instant(&row, "lease_expires_at")?.filter(|expires| *expires > now())
        {
            result["task"]["lease_expires_at"] = json!(super::cooperative::timestamp(expires));
            return Ok(Some(result));
        }
        Self::query(DB::QUERY_CACHE_DELETE).bind(&keys.result).execute(&mut **tx).await?;
        Ok(None)
    }

    /// Remember a poll's answer under its request ID. A lease is kept while
    /// its task can be delivered (the row decides); any other answer for the
    /// pending window, capped at a minute, as PHP's result TTL is.
    pub(super) async fn remember_workflow_poll(
        tx: &mut Transaction<'_, DB>,
        keys: &WorkflowPollKeys,
        response: &Value,
        pending_ttl: i64,
    ) -> Result<()> {
        let ttl = if response["task"].is_object() { 3600 } else { pending_ttl.clamp(5, 60) };
        Self::put_workflow_poll_row(tx, &keys.binding, &json!({"task_kinds": WORKFLOW_KINDS}), ttl).await?;
        Self::put_workflow_poll_row(tx, &keys.result, response, ttl).await
    }

    /// Whether a concurrent duplicate already left an answer to replay.
    pub(super) async fn workflow_poll_answered(&self, keys: &WorkflowPollKeys) -> Result<bool> {
        Ok(match Self::query(DB::QUERY_CACHE_GET).bind(&keys.result).fetch_optional(&self.pool).await? {
            Some(row) => DB::number(&row, "expiration")? > epoch(),
            None => false,
        })
    }

    /// Remember an answer that leased nothing, in its own transaction.
    pub(super) async fn remember_empty_workflow_poll(&self, keys: &WorkflowPollKeys, poll_status: &str, pending_ttl: i64) -> Result<()> {
        let mut tx = self.begin().await?;
        Self::remember_workflow_poll(&mut tx, keys, &json!({"task": null, "poll_status": poll_status}), pending_ttl).await?;
        tx.commit().await
    }

    async fn workflow_poll_row(tx: &mut Transaction<'_, DB>, key: &str) -> Result<Option<Value>> {
        let Some(row) = Self::query(DB::QUERY_CACHE_GET).bind(key).fetch_optional(&mut **tx).await? else {
            return Ok(None);
        };
        if DB::number(&row, "expiration")? <= epoch() {
            Self::query(DB::QUERY_CACHE_DELETE).bind(key).execute(&mut **tx).await?;
            return Ok(None);
        }
        Ok(Some(serde_json::from_str(&DB::string(&row, "value")?)?))
    }

    async fn put_workflow_poll_row(tx: &mut Transaction<'_, DB>, key: &str, value: &Value, ttl: i64) -> Result<()> {
        let exists = Self::query(DB::QUERY_CACHE_GET).bind(key).fetch_optional(&mut **tx).await?.is_some();
        let expiration = epoch() + ttl;
        if exists {
            Self::query(DB::QUERY_CACHE_UPDATE).bind(value.to_string()).bind(expiration).bind(key).execute(&mut **tx).await?;
        } else {
            Self::query(DB::QUERY_CACHE_INSERT).bind(key).bind(value.to_string()).bind(expiration).execute(&mut **tx).await?;
        }
        Ok(())
    }

    // Workers send a fresh request ID with most polls, so retire a bounded
    // batch of expired rows whenever a request arrives.
    async fn sweep_workflow_poll_rows(tx: &mut Transaction<'_, DB>) -> Result<()> {
        for row in Self::query(DB::WORKFLOW_POLL_EXPIRED).bind(epoch()).fetch_all(&mut **tx).await? {
            Self::query(DB::QUERY_CACHE_DELETE).bind(DB::string(&row, "key")?).execute(&mut **tx).await?;
        }
        Ok(())
    }
}
