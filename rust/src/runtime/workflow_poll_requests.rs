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
//!
//! PHP elects one pending leader per ID, and duplicates only read its answer.
//! Native duplicates each probe, so every write of an answer first replays a
//! deliverable lease a duplicate committed: a claim replays it instead of
//! leasing again, and a poll that found nothing returns it instead of
//! recording `empty` or `task_queue_changed` over it.
use super::transaction::Transaction;
use super::{Result, RuntimeError, store::now};
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use serde_json::{Value, json};
use sha2::{Digest, Sha256};

// Distinct from the query poll prefix, so each sweep sees only its own rows.
const PREFIX: &str = "dw-native-workflow-poll-v2:";
const LEGACY_PREFIX: &str = "dw-native-workflow-poll:";

#[derive(Clone)]
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
    legacy_keys: Vec<WorkflowPollKeys>,
    expiration_cap: Option<i64>,
}

impl WorkflowPollRequest {
    fn legacy(&self, keys: &WorkflowPollKeys) -> Self {
        Self { keys: keys.clone(), id: self.id.clone(),
            pending_ttl: self.pending_ttl, kinds: self.kinds.clone(), legacy_keys: vec![], expiration_cap: None }
    }

    #[cfg(test)]
    pub(super) fn predecessor(&self) -> Self {
        self.legacy(self.legacy_keys.last().expect("the former normalized key is always present"))
    }

    pub(super) fn replayed_in<DB: crate::runtime::backend::Backend>(&self, tx: &Transaction<'_, DB>) -> bool {
        tx.workflow_poll_replayed.as_deref() == Some(self.keys.binding.as_str())
    }
}

/// The request ID with surrounding space removed, as Laravel's TrimStrings
/// and the poller's `nonEmptyString` leave it. A blank ID asks for no
/// idempotency.
pub(super) fn request_id(body: &serde_json::Value) -> Option<String> {
    match super::laravel_rules::normalize(body.get("poll_request_id")?) {
        Value::String(id) if !id.is_empty() => Some(id),
        _ => None,
    }
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

fn validate_cached_answer(answer: &Value) -> Result<()> {
    let valid = answer.as_object().is_some_and(|fields| fields.contains_key("task"))
        && if answer["task"].is_object() {
            let fields = match answer["task"]["task_kind"].as_str() {
                None | Some("workflow") => Some(("task_id", "workflow_task_attempt")),
                Some("update_validation") => Some(("update_validation_task_id", "update_validation_attempt")),
                _ => None,
            };
            answer["poll_status"] == "leased" && fields.is_some_and(|(id, attempt)|
                answer["task"][id].as_str().is_some_and(|id| !id.trim().is_empty())
                    && answer["task"][attempt].as_i64().is_some_and(|attempt| attempt > 0))
        } else {
            answer["task"].is_null() && matches!(answer["poll_status"].as_str(),
                Some("empty" | "timeout" | "task_queue_changed" | "stale_worker_registration"))
        };
    if valid { Ok(()) } else { Err(super::refuse(StatusCode::INTERNAL_SERVER_ERROR, "invalid_poll_receipt")) }
}

store_methods! {
    /// A live workflow receipt replays in one snapshot. For the exact raw
    /// spelling supplied by a legacy caller, also retain its former key;
    /// normalization never rewrites or renews that committed receipt.
    pub(super) async fn replay_workflow_poll_read_only(
        &self, request: &WorkflowPollRequest, worker_id: &str, queue: &str,
        fence: &super::worker_poll_fence::WorkerPollFence,
    ) -> Result<Option<Value>> {
        let mut read = self.begin_read().await?;
        if !self.worker_poll_fence_holds_in(&mut read, fence, worker_id).await? { return Ok(None); }
        let mut legacy = None;
        for keys in &request.legacy_keys {
            legacy = self.cached_workflow_poll_read_only_in(&mut read, request, keys, worker_id, queue, false).await?;
            if legacy.is_some() { break; }
        }
        let answer = match legacy {
            Some(answer) => Some(answer),
            None => self.cached_workflow_poll_read_only_in(&mut read, request, &request.keys, worker_id, queue, true).await?,
        };
        read.commit().await?;
        Ok(answer)
    }

    async fn cached_workflow_poll_read_only_in(
        &self, read: &mut Transaction<'_, DB>, request: &WorkflowPollRequest, keys: &WorkflowPollKeys,
        worker_id: &str, queue: &str, leases_only: bool,
    ) -> Result<Option<Value>> {
        let at = epoch();
        let Some(binding) = Self::query(DB::QUERY_CACHE_GET).bind(&keys.binding).fetch_optional(&mut **read).await? else { return Ok(None); };
        if DB::number(&binding, "expiration")? <= at { return Ok(None); }
        let bound: Value = serde_json::from_str(&DB::string(&binding, "value")?)?;
        if bound["task_kinds"] != json!(request.kinds) { return Err(kinds_conflict(request, bound["task_kinds"].clone())); }
        let Some(row) = Self::query(DB::QUERY_CACHE_GET).bind(&keys.result).fetch_optional(&mut **read).await? else { return Ok(None); };
        if DB::number(&row, "expiration")? <= at { return Ok(None); }
        let mut result: Value = serde_json::from_str(&DB::string(&row, "value")?)?;
        validate_cached_answer(&result)?;
        if !result["task"].is_object() { return Ok((!leases_only).then_some(result)); }
        let kind = task_kind(&result);
        if !request.kinds.contains(&kind) { return Ok(None); }
        let deliverable = if kind == "update_validation" {
            self.validation_deliverable(read, &result["task"], worker_id, queue).await?
        } else {
            Self::workflow_lease_deliverable(read, &mut result["task"], worker_id, queue).await?
        };
        Ok(deliverable.then_some(result))
    }

    /// Keys cover namespace, queue, the registration's build ID, the worker
    /// and the request ID, as PHP's cache keys do; the kind set is the
    /// binding's value, not part of the key.
    pub(super) async fn workflow_poll_request(
        &self,
        worker_id: &str,
        queue: &str,
        id: String,
        raw_id: Option<&str>,
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
        let key = |prefix: &str, kind: &str, request_id: &str| {
            let material = json!([kind, self.namespace.as_ref(), queue, build, worker_id, request_id]);
            let digest: String = Sha256::digest(material.to_string().as_bytes())[..16]
                .iter()
                .map(|byte| format!("{byte:02x}"))
                .collect();
            format!("{prefix}{digest}")
        };
        let keys = WorkflowPollKeys { binding: key(PREFIX, "binding", &id), result: key(PREFIX, "result", &id) };
        let mut legacy_keys = vec![];
        if let Some(raw) = raw_id.map(str::trim).filter(|raw| !raw.is_empty() && *raw != id) {
            legacy_keys.push(WorkflowPollKeys { binding: key(LEGACY_PREFIX, "binding", raw), result: key(LEGACY_PREFIX, "result", raw) });
        }
        legacy_keys.push(WorkflowPollKeys { binding: key(LEGACY_PREFIX, "binding", &id), result: key(LEGACY_PREFIX, "result", &id) });
        Ok(WorkflowPollRequest { keys, legacy_keys, expiration_cap: None, pending_ttl: pending_ttl(default_wait), kinds: normalized(kinds), id })
    }

    /// Only an existing answer can select the old exact-spelling key. When
    /// its binding needs renewal, do the original bind/replay under the
    /// transition lock; never create legacy aliases for new requests.
    async fn replay_legacy_workflow_poll_in(
        &self, tx: &mut Transaction<'_, DB>, request: &WorkflowPollRequest, worker_id: &str, queue: &str,
    ) -> Result<Option<Value>> {
        for keys in &request.legacy_keys {
            let mut legacy = request.legacy(keys);
            let Some(row) = Self::query(DB::QUERY_CACHE_GET).bind(&legacy.keys.result).fetch_optional(&mut **tx).await? else { continue; };
            let expiration = DB::number(&row, "expiration")?;
            if expiration <= epoch() { continue; }
            // Recovering an expired binding cannot start a new drain window
            // beyond the retained answer's original absolute expiry.
            legacy.expiration_cap = Some(expiration);
            Self::bind_workflow_poll(tx, &legacy).await?;
            if let Some(answer) = self.deliverable_workflow_poll(tx, &legacy, worker_id, queue).await? { return Ok(Some(answer)); }
        }
        Ok(None)
    }

    /// Former keys are irreversible digests. An unknown padded predecessor
    /// cannot be enumerated from a normalized ID. Until all former rows have
    /// expired, decline a new canonical claim instead of splitting its lease.
    /// Exact known predecessor answers still replay, without renewing them.
    async fn workflow_poll_normalization_ready(
        tx: &mut Transaction<'_, DB>, request: &WorkflowPollRequest,
    ) -> Result<()> {
        if request.keys.binding.starts_with(PREFIX)
            && Self::query(DB::WORKFLOW_POLL_LEGACY_ACTIVE).bind(epoch()).fetch_optional(&mut **tx).await?.is_some()
        {
            return Err(super::refuse(StatusCode::CONFLICT, "workflow_poll_normalization_pending"));
        }
        Ok(())
    }

    /// At the start of a poll: bind the request ID to its kind set, then
    /// replay its answer if that answer is still deliverable. A stale lease
    /// is forgotten, and the caller polls afresh under the same ID.
    pub(super) async fn replay_workflow_poll_in(
        &self,
        tx: &mut Transaction<'_, DB>,
        request: &WorkflowPollRequest,
        worker_id: &str,
        queue: &str,
    ) -> Result<Option<Value>> {
        if let Some(answer) = self.replay_legacy_workflow_poll_in(tx, request, worker_id, queue).await? {
            tx.workflow_poll_replayed = Some(request.keys.binding.clone());
            return Ok(Some(answer));
        }
        self.sweep_workflow_poll_rows(tx).await?;
        Self::bind_workflow_poll(tx, request).await?;
        let replay = self.deliverable_workflow_poll(tx, request, worker_id, queue).await?;
        if replay.is_none() { Self::workflow_poll_normalization_ready(tx, request).await?; }
        tx.workflow_poll_replayed = Some(request.keys.binding.clone());
        Ok(replay)
    }

    /// `bindTaskKinds`: a live binding to another set refuses the poll; an
    /// absent or expired one is written for the pending window. The binding
    /// row stays locked to the end of the transaction, so a duplicate's
    /// claim waits and then sees this one's answer.
    pub(super) async fn bind_workflow_poll(tx: &mut Transaction<'_, DB>, request: &WorkflowPollRequest) -> Result<()> {
        let binding = json!({"task_kinds": request.kinds});
        let expiration = (epoch() + request.pending_ttl).min(request.expiration_cap.unwrap_or(i64::MAX));
        Self::query(DB::QUERY_CACHE_LOCK)
            .bind(&request.keys.binding)
            .bind(binding.to_string())
            .bind(expiration)
            .execute(&mut **tx)
            .await?;
        match Self::workflow_poll_row(tx, &request.keys.binding).await? {
            Some(bound) if bound["task_kinds"] != binding["task_kinds"] => Err(kinds_conflict(request, bound["task_kinds"].clone())),
            Some(_) => Ok(()),
            None => Self::put_workflow_poll_row_until(tx, &request.keys.binding, &binding, expiration).await,
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
        validate_cached_answer(&result)?;
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

    /// A deliverable lease a duplicate left under this request ID, which a
    /// claim or a no-task answer must replay rather than overwrite. Call it
    /// after `bind_workflow_poll`, whose lock orders it after any duplicate's
    /// committed answer.
    pub(super) async fn deliverable_workflow_lease(
        &self,
        tx: &mut Transaction<'_, DB>,
        request: &WorkflowPollRequest,
        worker_id: &str,
        queue: &str,
    ) -> Result<Option<Value>> {
        let answer = self.deliverable_workflow_poll(tx, request, worker_id, queue).await?.filter(|answer| answer["task"].is_object());
        if answer.is_none() { Self::workflow_poll_normalization_ready(tx, request).await?; }
        Ok(answer)
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

    /// Remember an answer that leased nothing (`empty` or
    /// `task_queue_changed`), in its own transaction. A duplicate may have
    /// leased under this ID after this poll's last probe; its deliverable
    /// lease is returned for the caller to replay, and stays recorded.
    pub(super) async fn remember_empty_workflow_poll(
        &self,
        request: &WorkflowPollRequest,
        worker_id: &str,
        queue: &str,
        poll_status: &str,
    ) -> Result<Option<Value>> {
        #[cfg(debug_assertions)]
        super::poll_pauses::stop_at(&request.id, super::poll_pauses::PollPoint::Terminal).await;
        let mut tx = self.begin().await?;
        if let Some(answer) = self.replay_legacy_workflow_poll_in(&mut tx, request, worker_id, queue).await? {
            tx.commit().await?;
            return Ok(Some(answer));
        }
        Self::bind_workflow_poll(&mut tx, request).await?;
        let lease = self.deliverable_workflow_lease(&mut tx, request, worker_id, queue).await?;
        if lease.is_none() {
            Self::remember_workflow_poll(&mut tx, request, &json!({"task": null, "poll_status": poll_status})).await?;
        }
        tx.commit().await?;
        Ok(lease)
    }

    pub(super) async fn workflow_poll_row(tx: &mut Transaction<'_, DB>, key: &str) -> Result<Option<Value>> {
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
        Self::put_workflow_poll_row_until(tx, key, value, epoch() + ttl).await
    }

    async fn put_workflow_poll_row_until(tx: &mut Transaction<'_, DB>, key: &str, value: &Value, expiration: i64) -> Result<()> {
        Self::query(DB::QUERY_CACHE_PUT).bind(key).bind(value.to_string())
            .bind(expiration).execute(&mut **tx).await?;
        Ok(())
    }

    #[cfg(test)]
    pub(super) async fn expire_workflow_poll_binding_for_test(&self, request: &WorkflowPollRequest) {
        Self::query(DB::QUERY_CACHE_PUT).bind(&request.keys.binding)
            .bind(json!({"task_kinds":request.kinds}).to_string()).bind(epoch() - 1)
            .execute(&self.pool).await.unwrap();
        let row = Self::query(DB::QUERY_CACHE_GET).bind(&request.keys.result).fetch_one(&self.pool).await.unwrap();
        Self::query(DB::QUERY_CACHE_UPDATE).bind(DB::string(&row, "value").unwrap())
            .bind(epoch() + 20).bind(&request.keys.result).execute(&self.pool).await.unwrap();
    }

    #[cfg(test)]
    pub(super) async fn assert_workflow_poll_binding_expiry_for_test(&self, request: &WorkflowPollRequest) {
        let binding = Self::query(DB::QUERY_CACHE_GET).bind(&request.keys.binding).fetch_one(&self.pool).await.unwrap();
        let result = Self::query(DB::QUERY_CACHE_GET).bind(&request.keys.result).fetch_one(&self.pool).await.unwrap();
        assert!(DB::number(&binding, "expiration").unwrap() <= DB::number(&result, "expiration").unwrap(),
            "binding recovery cannot extend the original predecessor answer's drain window");
    }

    #[cfg(test)]
    pub(super) async fn expire_workflow_poll_for_test(&self, request: &WorkflowPollRequest) {
        for key in [&request.keys.binding, &request.keys.result] {
            let row = Self::query(DB::QUERY_CACHE_GET).bind(key).fetch_one(&self.pool).await.unwrap();
            Self::query(DB::QUERY_CACHE_UPDATE).bind(DB::string(&row, "value").unwrap())
                .bind(epoch() - 1).bind(key).execute(&self.pool).await.unwrap();
        }
    }

    #[cfg(test)]
    pub(super) async fn qualify_workflow_poll_cache_writes(&self) {
        let mut tx = self.begin().await.unwrap();
        let key = "dw-native-workflow-poll:upsert-control";
        for (index, value) in [json!({"first":true}), json!({"replacement":null}), json!({"expired":"replaced"})].into_iter().enumerate() {
            let (result, count) = super::sql_counts::measure(Self::put_workflow_poll_row(&mut tx, key, &value, 60)).await;
            result.unwrap();
            assert_eq!(count.total(), 1);
            assert_eq!(count.count(DB::QUERY_CACHE_PUT), 1);
            let row = Self::query(DB::QUERY_CACHE_GET).bind(key).fetch_one(&mut *tx).await.unwrap();
            assert_eq!(DB::string(&row,"value").unwrap(), value.to_string());
            assert!(DB::number(&row,"expiration").unwrap() > epoch());
            if index == 1 {
                Self::query(DB::QUERY_CACHE_UPDATE).bind(value.to_string()).bind(1_i64).bind(key).execute(&mut *tx).await.unwrap();
            }
        }
        tx.rollback().await.unwrap();
        assert!(Self::query(DB::QUERY_CACHE_GET).bind(key).fetch_optional(&self.pool).await.unwrap().is_none());
    }

    // Workers send a fresh request ID with most polls, so retire a bounded
    // batch of expired rows once per configured interval.
    pub(super) async fn sweep_workflow_poll_rows(&self, tx: &mut Transaction<'_, DB>) -> Result<()> {
        if !self.poll_sweeps.due(super::poll_sweeps::Kind::Workflow, self.poll_sweep_config.interval) { return Ok(()); }
        for row in Self::query(DB::WORKFLOW_POLL_EXPIRED).bind(epoch()).bind(self.poll_sweep_config.batch).fetch_all(&mut **tx).await? {
            Self::query(DB::QUERY_CACHE_DELETE).bind(DB::string(&row, "key")?).execute(&mut **tx).await?;
        }
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn workflow_poll_identity_uses_the_same_laravel_trimming_as_validation() {
        for padding in ["\u{feff}", "\u{200b}", "\u{200e}"] {
            let supplied = format!(" \t{padding}original-id{padding} \t");
            assert_ne!(supplied.trim(), "original-id", "the former Rust-only trimming splits this identity");
            assert_eq!(request_id(&json!({"poll_request_id":supplied})).as_deref(), Some("original-id"));
            assert_eq!(request_id(&json!({"poll_request_id":padding})), None);
        }
        for body in [json!({}), json!({"poll_request_id":null}), json!({"poll_request_id":true}), json!({"poll_request_id":1})] {
            assert_eq!(request_id(&body), None);
        }
    }

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
