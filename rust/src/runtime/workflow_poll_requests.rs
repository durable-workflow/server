//! Workflow-task poll request idempotency, as published PHP keeps it
//! (`WorkflowTaskPollRequestStore` with `PollRequestLeaseBinding`). A request
//! ID binds to its normalized task-kind set: the same ID with another set is
//! refused with `poll_request_task_kinds_conflict`. It replays its last answer
//! while that answer is still deliverable: an empty or queue-changed poll for
//! the pending window, a workflow lease for as long as its task stays leased
//! to the worker at the same attempt, with the task's current lease, and a
//! validation lease while it stays leased at the same attempt. Once a lease is
//! no longer deliverable, the ID polls afresh. PHP never refuses a retry as
//! expired.
use super::transaction::Transaction;
use super::{Result, RuntimeError, store::now};
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use serde_json::{Value, json};
use sha2::{Digest, Sha256};

// Distinct from the query poll prefix, so each sweep sees only its own rows.
const PREFIX: &str = "dw-native-workflow-poll:";

pub(super) struct WorkflowPollKeys {
    binding: String,
    result: String,
}

/// A workflow poll's request ID, its keys, its pending window and the
/// normalized task-kind set it binds to.
pub(super) struct WorkflowPollRequest {
    pub(super) id: String,
    pub(super) keys: WorkflowPollKeys,
    pub(super) pending_ttl: i64,
    pub(super) kinds: Vec<&'static str>,
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

/// `normalizeTaskKinds`: the distinct kinds in string order, so either order
/// of a multiplexed request is one set.
pub(super) fn normalized(kinds: &[&'static str]) -> Vec<&'static str> {
    let mut kinds = kinds.to_vec();
    kinds.sort_unstable();
    kinds.dedup();
    kinds
}

/// `resultTtl`: an answer holding a live lease is kept until five seconds
/// after the lease ends, up to an hour; any other answer for the pending
/// window, up to a minute. The binding is rewritten with the same expiry.
fn result_ttl(response: &Value, pending_ttl: i64) -> i64 {
    let lease = response["task"]["lease_expires_at"]
        .as_str()
        .and_then(|at| DateTime::parse_from_rfc3339(at).ok())
        .map(|at| at.with_timezone(&Utc));
    match lease {
        Some(expires) if expires > now() => ((expires - now()).num_seconds() + 5).clamp(5, 3600),
        _ => pending_ttl.clamp(5, 60),
    }
}

/// The kind a cached answer delivered; native workflow answers name none.
fn task_kind(response: &Value) -> &str {
    response["task"]["task_kind"].as_str().unwrap_or("workflow")
}

/// The 409 for an ID already bound to another task-kind set.
fn kinds_conflict(request: &WorkflowPollRequest, bound: Value) -> RuntimeError {
    RuntimeError::Protocol {
        status: StatusCode::CONFLICT,
        response: json!({"task":null,"poll_status":"conflict","reason":"poll_request_task_kinds_conflict",
            "error":"Poll request ID is already bound to a different task-kind set.","poll_request_id":request.id,
            "requested_task_kinds":request.kinds,"bound_task_kinds":bound}),
    }
}

fn epoch() -> i64 {
    now().timestamp()
}

store_methods! {
    /// Keys cover namespace, queue, the registration's build ID, the worker
    /// and the request ID, as PHP's cache keys do; the kind set is the
    /// binding's value, not part of the key.
    pub(super) async fn workflow_poll_request(
        &self,
        worker_id: &str,
        queue: &str,
        id: String,
        default_wait: u64,
        kinds: &[&'static str],
    ) -> Result<WorkflowPollRequest> {
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
            let material = json!([kind, self.namespace.as_ref(), queue, build, worker_id, id]);
            let digest: String = Sha256::digest(material.to_string().as_bytes())[..16]
                .iter()
                .map(|byte| format!("{byte:02x}"))
                .collect();
            format!("{PREFIX}{digest}")
        };
        let keys = WorkflowPollKeys { binding: key("binding"), result: key("result") };
        Ok(WorkflowPollRequest { keys, pending_ttl: pending_ttl(default_wait), kinds: normalized(kinds), id })
    }

    /// At the start of a poll: bind the request ID to its kind set, then
    /// replay its answer if that answer is still deliverable. A stale lease
    /// is forgotten, and the caller polls afresh under the same ID.
    pub(super) async fn replay_workflow_poll(
        &self,
        request: &WorkflowPollRequest,
        worker_id: &str,
        queue: &str,
    ) -> Result<Option<Value>> {
        let mut tx = self.begin().await?;
        Self::sweep_workflow_poll_rows(&mut tx).await?;
        Self::bind_workflow_poll(&mut tx, request).await?;
        let replay = self.deliverable_workflow_poll(&mut tx, request, worker_id, queue).await?;
        tx.commit().await?;
        Ok(replay)
    }

    /// `bindTaskKinds`: a live binding to another set refuses the poll; an
    /// absent or expired one is written for the pending window. The binding
    /// row stays locked to the end of the transaction, so a duplicate's
    /// claim waits and then sees this one's answer.
    pub(super) async fn bind_workflow_poll(tx: &mut Transaction<'_, DB>, request: &WorkflowPollRequest) -> Result<()> {
        let binding = json!({"task_kinds": request.kinds});
        Self::query(DB::QUERY_CACHE_LOCK)
            .bind(&request.keys.binding)
            .bind(binding.to_string())
            .bind(epoch() + request.pending_ttl)
            .execute(&mut **tx)
            .await?;
        match Self::workflow_poll_row(tx, &request.keys.binding).await? {
            Some(bound) if bound["task_kinds"] != binding["task_kinds"] => Err(kinds_conflict(request, bound["task_kinds"].clone())),
            Some(_) => Ok(()),
            None => Self::put_workflow_poll_row(tx, &request.keys.binding, &binding, request.pending_ttl).await,
        }
    }

    /// The cached answer if its task can still be delivered; a stale answer
    /// is deleted. A workflow lease shows the task's current lease, as PHP
    /// rebuilds it; a validation lease replays as it was delivered. An
    /// answer of a kind this request did not ask for is not replayed.
    pub(super) async fn deliverable_workflow_poll(
        &self,
        tx: &mut Transaction<'_, DB>,
        request: &WorkflowPollRequest,
        worker_id: &str,
        queue: &str,
    ) -> Result<Option<Value>> {
        let Some(mut result) = Self::workflow_poll_row(tx, &request.keys.result).await? else {
            return Ok(None);
        };
        if !result["task"].is_object() {
            // An empty or queue-changed answer stays as it was.
            return Ok(Some(result));
        }
        if !request.kinds.contains(&task_kind(&result)) {
            return Ok(None);
        }
        let deliverable = if task_kind(&result) == "update_validation" {
            self.validation_deliverable(tx, &result["task"], worker_id, queue).await?
        } else {
            Self::workflow_lease_deliverable(tx, &mut result["task"], worker_id, queue).await?
        };
        if deliverable {
            return Ok(Some(result));
        }
        Self::query(DB::QUERY_CACHE_DELETE).bind(&request.keys.result).execute(&mut **tx).await?;
        Ok(None)
    }

    /// `cachedTaskStillDeliverable` for a workflow task: leased on this
    /// queue to this worker at the delivered attempt, with a live lease,
    /// which the replay then shows.
    async fn workflow_lease_deliverable(tx: &mut Transaction<'_, DB>, task: &mut Value, worker_id: &str, queue: &str) -> Result<bool> {
        let Some(task_id) = task["task_id"].as_str().map(str::to_owned) else {
            return Ok(false);
        };
        let row = Self::query("SELECT status,queue,lease_owner,lease_expires_at,attempt_count FROM workflow_tasks WHERE id=$1 AND task_type='workflow'")
            .bind(&task_id)
            .fetch_optional(&mut **tx)
            .await?;
        if let Some(row) = row
            && DB::string(&row, "status")? == "leased"
            && DB::optional_string(&row, "queue")?.as_deref() == Some(queue)
            && DB::optional_string(&row, "lease_owner")?.as_deref() == Some(worker_id)
            && task["workflow_task_attempt"].as_i64() == Some(DB::number(&row, "attempt_count")?)
            && let Some(expires) = DB::optional_instant(&row, "lease_expires_at")?.filter(|expires| *expires > now())
        {
            task["lease_expires_at"] = json!(super::cooperative::timestamp(expires));
            return Ok(true);
        }
        Ok(false)
    }

    /// The broker's `cachedTaskStillDeliverable`: the validation task is in
    /// this namespace and queue, leased to this worker with a live lease, at
    /// the delivered attempt.
    async fn validation_deliverable(&self, tx: &mut Transaction<'_, DB>, task: &Value, worker_id: &str, queue: &str) -> Result<bool> {
        let Some(task_id) = task["update_validation_task_id"].as_str() else {
            return Ok(false);
        };
        let row = Self::query("SELECT status,lease_owner,lease_expires_at,attempt_count FROM workflow_update_validation_tasks WHERE id=$1 AND namespace=$2 AND task_queue=$3")
            .bind(task_id)
            .bind(self.namespace.as_ref())
            .bind(queue)
            .fetch_optional(&mut **tx)
            .await?;
        let Some(row) = row else {
            return Ok(false);
        };
        let attempt_count = DB::number(&row, "attempt_count")?;
        let attempt = task.get("update_validation_attempt").and_then(Value::as_i64);
        Ok(DB::string(&row, "status")? == "leased"
            && DB::optional_string(&row, "lease_owner")?.as_deref() == Some(worker_id)
            && DB::optional_instant(&row, "lease_expires_at")?.is_some_and(|expires| expires > now())
            && attempt.is_none_or(|attempt| attempt == attempt_count))
    }

    /// Remember a poll's answer under its request ID, and rewrite the
    /// binding with PHP's result expiry. A workflow lease's answer is kept
    /// while its task can be delivered (the row decides), as PHP's lease
    /// binding replays for the whole live lease.
    pub(super) async fn remember_workflow_poll(tx: &mut Transaction<'_, DB>, request: &WorkflowPollRequest, response: &Value) -> Result<()> {
        let ttl = result_ttl(response, request.pending_ttl);
        Self::put_workflow_poll_row(tx, &request.keys.binding, &json!({"task_kinds": request.kinds}), ttl).await?;
        let kept = if response["task"].is_object() && task_kind(response) == "workflow" { 3600 } else { ttl };
        Self::put_workflow_poll_row(tx, &request.keys.result, response, kept).await
    }

    /// Whether a concurrent duplicate already left an answer to replay.
    pub(super) async fn workflow_poll_answered(&self, keys: &WorkflowPollKeys) -> Result<bool> {
        Ok(match Self::query(DB::QUERY_CACHE_GET).bind(&keys.result).fetch_optional(&self.pool).await? {
            Some(row) => DB::number(&row, "expiration")? > epoch(),
            None => false,
        })
    }

    /// Remember an answer that leased nothing, in its own transaction.
    pub(super) async fn remember_empty_workflow_poll(&self, request: &WorkflowPollRequest, poll_status: &str) -> Result<()> {
        let mut tx = self.begin().await?;
        Self::bind_workflow_poll(&mut tx, request).await?;
        Self::remember_workflow_poll(&mut tx, request, &json!({"task": null, "poll_status": poll_status})).await?;
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

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn either_order_of_a_multiplexed_request_is_one_set() {
        assert_eq!(normalized(&["workflow", "update_validation"]), ["update_validation", "workflow"]);
        assert_eq!(normalized(&["update_validation", "workflow"]), ["update_validation", "workflow"]);
        assert_eq!(normalized(&["workflow"]), ["workflow"]);
    }

    #[test]
    fn a_live_lease_keeps_its_answer_until_five_seconds_after_it_ends() {
        let lease = |seconds: i64| json!({"task":{"lease_expires_at":super::super::cooperative::timestamp(now() + chrono::Duration::seconds(seconds))}});
        assert!((64..=65).contains(&result_ttl(&lease(60), 35)));
        assert_eq!(result_ttl(&lease(7200), 35), 3600);
        assert_eq!(result_ttl(&lease(-1), 35), 35);
        assert_eq!(result_ttl(&json!({"task":null}), 35), 35);
        assert_eq!(result_ttl(&json!({"task":null}), 95), 60);
    }
}
