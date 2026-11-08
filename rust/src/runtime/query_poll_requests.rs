//! Per-request query poll results, kept as published PHP keeps them
//! (`QueryTaskPollRequestStore`). A retry of a poll request gets the answer the
//! original got, a duplicate still in flight waits for that answer, and a newer
//! request from the same worker ends an older one that is still waiting.
use super::{
    Backend, ColumnIndex, Decode, Duration, Encode, Executor, IntoArguments, QueryTaskConfig, Result, Store,
    Transaction, Type, Value, QueryClaim, CheckedQueryPoll, authority, json, key, now, poll_result, text,
};
use sha2::{Digest, Sha256};

// Distinct from the query task prefix, so query scans never see these rows.
const POLL_PREFIX: &str = "dw-native-query-poll:";

pub(super) struct PollKeys {
    request_id: String,
    pending: String,
    result: String,
    current: String,
    /// The namespace, queue, build ID and worker these keys cover.
    scope: Value,
}

impl PollKeys {
    // MySQL and MariaDB keys are varchar(64): keep 128 bits of the digest.
    fn key(scope: &Value, kind: &str, id: Option<&str>) -> String {
        let material = json!([kind, scope[0], scope[1], scope[2], scope[3], id]);
        let digest: String = Sha256::digest(material.to_string().as_bytes())[..16]
            .iter()
            .map(|byte| format!("{byte:02x}"))
            .collect();
        format!("{POLL_PREFIX}{digest}")
    }
}

/// A poll row as a lock-free reader sees it. Only a live row it can parse
/// counts as present; an expired or unreadable one proves nothing.
enum Peek {
    Absent,
    Live(Value),
    Unproven,
}

fn pending_ttl(config: &QueryTaskConfig) -> i64 {
    (config.poll_timeout_seconds + 5).max(5)
}

fn empty_ttl(config: &QueryTaskConfig) -> i64 {
    pending_ttl(config).min(60).max(5)
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
    // Keys cover namespace, queue, the registration's build ID, the worker and
    // the request ID, as PHP's cache keys do.
    pub(super) async fn poll_keys(&self, body: &Value, request_id: &str) -> Result<PollKeys> {
        let owner = text(body, "worker_id")?;
        let queue = text(body, "task_queue")?;
        let build = Self::query(
            "SELECT build_id FROM workflow_worker_registrations WHERE namespace=$1 AND worker_id=$2",
        )
        .bind(self.namespace.as_ref())
        .bind(owner)
        .fetch_optional(&self.pool)
        .await?
        .map(|row| DB::optional_string(&row, "build_id"))
        .transpose()?
        .flatten();
        let scope = json!([self.namespace.as_ref(), queue, build, owner]);
        Ok(PollKeys {
            request_id: request_id.to_owned(),
            pending: PollKeys::key(&scope, "pending", Some(request_id)),
            result: PollKeys::key(&scope, "result", Some(request_id)),
            current: PollKeys::key(&scope, "current", None),
            scope,
        })
    }

    pub(in crate::runtime) async fn poll_row(tx: &mut Transaction<'_, DB>, key: &str) -> Result<Option<Value>> {
        let Some(row) = Self::query(DB::QUERY_CACHE_GET)
            .bind(key)
            .fetch_optional(&mut **tx)
            .await?
        else {
            return Ok(None);
        };
        if DB::number(&row, "expiration")? <= now() {
            Self::query(DB::QUERY_CACHE_DELETE)
                .bind(key)
                .execute(&mut **tx)
                .await?;
            return Ok(None);
        }
        Ok(Some(serde_json::from_str(&DB::string(&row, "value")?)?))
    }

    async fn put_poll_row(
        tx: &mut Transaction<'_, DB>,
        key: &str,
        value: &Value,
        ttl: i64,
    ) -> Result<()> {
        Self::query(DB::QUERY_CACHE_PUT).bind(key).bind(value.to_string())
            .bind(now() + ttl).execute(&mut **tx).await?;
        Ok(())
    }

    /// Writes one poll row of this worker's scope exactly as given, for
    /// controls that need a row the maintained paths would not write.
    #[cfg(test)]
    pub(super) async fn put_test_poll_row(&self, keys: &PollKeys, kind: &str, request: Option<&str>, raw: &str, ttl: i64) {
        let mut tx = self.begin().await.unwrap();
        Self::query(DB::QUERY_CACHE_PUT).bind(PollKeys::key(&keys.scope, kind, request)).bind(raw.to_owned())
            .bind(now() + ttl).execute(&mut *tx).await.unwrap();
        tx.commit().await.unwrap();
    }

    #[cfg(test)]
    pub(super) async fn test_poll_row(&self, keys: &PollKeys, kind: &str, request: Option<&str>) -> Option<Value> {
        let row = Self::query(DB::QUERY_CACHE_GET).bind(PollKeys::key(&keys.scope, kind, request))
            .fetch_optional(&self.pool).await.unwrap()?;
        Some(serde_json::from_str(&DB::string(&row, "value").unwrap()).unwrap())
    }

    #[cfg(test)]
    pub(in crate::runtime) async fn qualify_query_poll_cache_writes(&self) {
        let mut tx = self.begin().await.unwrap();
        let key = "dw-native-query-poll:upsert-control";
        for (index, value) in [json!({"first":true}), json!({"replacement":null}), json!({"expired":"replaced"})].into_iter().enumerate() {
            let (result, count) = crate::runtime::sql_counts::measure(Self::put_poll_row(&mut tx, key, &value, 60)).await;
            result.unwrap();
            assert_eq!(count.total(), 1);
            assert_eq!(count.count(DB::QUERY_CACHE_PUT), 1);
            let row = Self::query(DB::QUERY_CACHE_GET).bind(key).fetch_one(&mut *tx).await.unwrap();
            assert_eq!(DB::string(&row,"value").unwrap(), value.to_string());
            assert!(DB::number(&row,"expiration").unwrap() > now());
            if index == 1 {
                Self::query(DB::QUERY_CACHE_UPDATE).bind(value.to_string()).bind(1_i64).bind(key).execute(&mut *tx).await.unwrap();
            }
        }
        tx.rollback().await.unwrap();
        assert!(Self::query(DB::QUERY_CACHE_GET).bind(key).fetch_optional(&self.pool).await.unwrap().is_none());
    }

    // Workers send a fresh request ID with every poll, so retire a bounded
    // batch of expired poll rows once per configured interval.
    pub(in crate::runtime) async fn sweep_poll_rows(&self, tx: &mut Transaction<'_, DB>) -> Result<()> {
        if !self.poll_sweeps.due(crate::runtime::poll_sweeps::Kind::Query, self.poll_sweep_config.interval) { return Ok(()); }
        let expired = Self::query(DB::QUERY_POLL_EXPIRED)
            .bind(now())
            .bind(self.poll_sweep_config.batch)
            .fetch_all(&mut **tx)
            .await?;
        for row in expired {
            Self::query(DB::QUERY_CACHE_DELETE)
                .bind(DB::string(&row, "key")?)
                .execute(&mut **tx)
                .await?;
        }
        Ok(())
    }

    pub(super) async fn poll_request_is_current(
        tx: &mut Transaction<'_, DB>,
        keys: &PollKeys,
    ) -> Result<bool> {
        Ok(Self::poll_row(tx, &keys.current)
            .await?
            .is_some_and(|current| current["poll_request_id"] == keys.request_id.as_str()))
    }

    /// Whether a newer request from the same worker has replaced this one,
    /// read without the transition lock. The claim confirms it under the lock
    /// with `poll_request_is_current`, which this mirrors.
    pub(super) async fn poll_request_superseded(&self, keys: &PollKeys) -> Result<bool> {
        let Some(row) = Self::query(DB::QUERY_CACHE_GET)
            .bind(&keys.current)
            .fetch_optional(&self.pool)
            .await?
        else {
            return Ok(true);
        };
        if DB::number(&row, "expiration")? <= now() {
            return Ok(true);
        }
        let current: Value = serde_json::from_str(&DB::string(&row, "value")?)?;
        Ok(current["poll_request_id"] != keys.request_id.as_str())
    }

    // A request becomes the worker's current one only when it is new. A retry
    // of an older request never takes that place back.
    async fn mark_current_if_fresh(&self, keys: &PollKeys, config: &QueryTaskConfig) -> Result<()> {
        let mut tx = self.begin().await?;
        self.sweep_poll_rows(&mut tx).await?;
        if Self::poll_row(&mut tx, &keys.pending).await?.is_none()
            && Self::poll_row(&mut tx, &keys.result).await?.is_none()
        {
            Self::put_poll_row(
                &mut tx,
                &keys.current,
                &json!({"poll_request_id": keys.request_id}),
                pending_ttl(config),
            )
            .await?;
        }
        tx.commit().await?;
        Ok(())
    }

    // The remembered answer for this request. A remembered task is delivered
    // again only while this worker's registration still holds its live lease.
    async fn cached_query_poll(
        &self,
        keys: &PollKeys,
        body: &Value,
        proof: &str,
        config: &QueryTaskConfig,
    ) -> Result<Option<Value>> {
        let mut tx = self.begin().await?;
        let response = self.cached_query_poll_in(&mut tx, keys, body, proof, config).await?;
        tx.commit().await?;
        Ok(response)
    }

    async fn cached_query_poll_in(
        &self,
        tx: &mut Transaction<'_, DB>,
        keys: &PollKeys,
        body: &Value,
        proof: &str,
        config: &QueryTaskConfig,
    ) -> Result<Option<Value>> {
        let owner = text(body, "worker_id")?;
        let queue = text(body, "task_queue")?;
        let Some(result) = Self::poll_row(tx, &keys.result).await? else {
            return Ok(None);
        };
        let Some(task_id) = result["query_task_id"].as_str() else {
            let status = result["poll_status"].as_str().unwrap_or("empty");
            return Ok(Some(poll_result(None, status)));
        };
        let at = authority::now();
        let task = Self::poll_row(tx, &key(task_id)?).await?;
        let deliverable = task.as_ref().filter(|task| {
            task["status"] == "leased"
                && task["namespace"] == self.namespace.as_ref()
                && task["task_queue"] == queue
                && task["lease_owner"] == owner
                && task["registration_incarnation"] == proof
                && authority::timestamp(&task["lease_expires_at"]).is_some_and(|lease| lease > at)
                && authority::deadline(task).is_some_and(|deadline| deadline > at)
        });
        let response = match deliverable {
            Some(task) => poll_result(Some(Self::query_delivery(task)), "leased"),
            None => {
                Self::put_poll_row(
                    tx,
                    &keys.result,
                    &json!({"query_task_id": null, "poll_status": "empty"}),
                    empty_ttl(config),
                )
                .await?;
                poll_result(None, "empty")
            }
        };
        Ok(Some(response))
    }

    async fn try_start_query_poll(&self, keys: &PollKeys, config: &QueryTaskConfig) -> Result<bool> {
        let mut tx = self.begin().await?;
        if Self::poll_row(&mut tx, &keys.pending).await?.is_some() {
            tx.commit().await?;
            return Ok(false);
        }
        Self::put_poll_row(&mut tx, &keys.pending, &json!({"pending": true}), pending_ttl(config))
            .await?;
        tx.commit().await?;
        Ok(true)
    }

    async fn remember_query_poll(
        &self,
        keys: &PollKeys,
        response: &Value,
        config: &QueryTaskConfig,
    ) -> Result<()> {
        let mut tx = self.begin().await?;
        Self::remember_query_answer(&mut tx, keys, response, config).await?;
        tx.commit().await?;
        Ok(())
    }

    async fn remember_query_answer(
        tx: &mut Transaction<'_, DB>,
        keys: &PollKeys,
        response: &Value,
        config: &QueryTaskConfig,
    ) -> Result<()> {
        let task_id = response["task"]["query_task_id"].as_str();
        let ttl = match authority::timestamp(&response["task"]["lease_expires_at"]) {
            Some(lease) if task_id.is_some() => {
                ((lease - authority::now()).num_seconds() + 5).clamp(5, 3600)
            }
            _ => empty_ttl(config),
        };
        Self::put_poll_row(
            tx,
            &keys.result,
            &json!({"query_task_id": task_id, "poll_status": response["poll_status"]}),
            ttl,
        )
        .await?;
        Self::query(DB::QUERY_CACHE_DELETE)
            .bind(&keys.pending)
            .execute(&mut **tx)
            .await?;
        Ok(())
    }

    async fn forget_pending_query_poll(&self, keys: &PollKeys) -> Result<()> {
        let mut tx = self.begin().await?;
        Self::query(DB::QUERY_CACHE_DELETE)
            .bind(&keys.pending)
            .execute(&mut *tx)
            .await?;
        tx.commit().await?;
        Ok(())
    }

    /// A new nonwaiting poll owns the transition lock through its cache
    /// lookup, claim and answer. A pending duplicate follows its live leader
    /// without holding the lock while waiting for that leader to commit.
    async fn immediate_query_poll(
        &self,
        body: &Value,
        config: &QueryTaskConfig,
        proof: &str,
        fence: Option<&crate::runtime::worker_poll_fence::WorkerPollFence>,
        keys: &PollKeys,
    ) -> Result<Option<Value>> {
        let owner = text(body, "worker_id")?;
        let mut tx = self.begin().await?;
        self.sweep_poll_rows(&mut tx).await?;
        let pending = Self::poll_row(&mut tx, &keys.pending).await?.is_some();
        let fresh = !pending && Self::poll_row(&mut tx, &keys.result).await?.is_none();
        if fresh {
            Self::put_poll_row(&mut tx, &keys.current, &json!({"poll_request_id":keys.request_id}), pending_ttl(config)).await?;
        }
        let current = Self::query_worker_proof(&mut *tx, self.namespace.as_ref(), owner, config).await?;
        let fenced = match fence {
            Some(fence) => !self.worker_poll_fence_holds_in(&mut tx, fence, owner).await?,
            None => false,
        };
        if current.as_deref() != Some(proof) || fenced {
            tx.commit().await?;
            return Ok(Some(poll_result(None, "stale_worker_registration")));
        }
        let cached = if fresh { None } else { self.cached_query_poll_in(&mut tx, keys, body, proof, config).await? };
        if let Some(cached) = cached {
            tx.commit().await?;
            return Ok(Some(cached));
        }
        if pending {
            tx.commit().await?;
            return Ok(None);
        }
        let saved = tx.poll_savepoint().await?;
        let checked = fresh.then_some(CheckedQueryPoll { proof });
        let claim = match self.claim_query_in(&mut tx, body, config, proof, fence, Some(keys), checked).await {
            Ok(claim) => {
                tx.release_poll_claim().await?;
                claim
            }
            Err(error) => {
                tx.rollback_poll_claim(saved).await?;
                tx.commit().await?;
                return Err(error);
            }
        };
        let response = match claim {
            QueryClaim::Task(task) => poll_result(Some(task), "leased"),
            QueryClaim::Blocked("workflow_task_pending") => poll_result(None, "workflow_task_pending"),
            QueryClaim::Blocked(_) => poll_result(None, "workflow_task_leased"),
            QueryClaim::Empty | QueryClaim::StaleWorker | QueryClaim::Superseded => poll_result(None, "empty"),
        };
        Self::remember_query_answer(&mut tx, keys, &response, config).await?;
        tx.commit().await?;
        Ok(Some(response))
    }

    /// A poll row as `peek_empty_poll` reads it: never deleted, and only a
    /// live row that parses is present. Storage faults are returned.
    async fn peek_poll_row(tx: &mut Transaction<'_, DB>, key: &str) -> Result<Peek> {
        let Some(row) = Self::query(DB::QUERY_CACHE_GET)
            .bind(key)
            .fetch_optional(&mut **tx)
            .await?
        else {
            return Ok(Peek::Absent);
        };
        if DB::number(&row, "expiration")? <= now() {
            return Ok(Peek::Unproven);
        }
        Ok(serde_json::from_str(&DB::string(&row, "value")?).map_or(Peek::Unproven, Peek::Live))
    }

    /// Server #1057: whether a new non-waiting poll would claim nothing and
    /// write nothing a later answer depends on, proven from one read
    /// snapshot without the transition lock. The locked path would make this
    /// request current, find no task and remember "empty". Without those rows
    /// a retry of this request polls again, as a poll a moment later would.
    ///
    /// Every fact holds at the snapshot's one instant:
    /// - nothing is pending or remembered under this request ID;
    /// - the worker's current request, if any, is another request that has
    ///   already answered, so no waiting request of this worker is one this
    ///   request would end by becoming current;
    /// - the claim would accept the registration: the same live proof and
    ///   poll fence, on this queue;
    /// - the namespace has no open query task and no query bookkeeping due
    ///   (`query_bookkeeping_idle`).
    ///
    /// A poll-row sweep due before or after the reads is left to the locked
    /// path, which reserves and runs it. So is every fact this cannot prove
    /// exactly; the locked path then keeps its own answers and refusals.
    /// `snapshot_taken` runs once the snapshot has read its first row; only
    /// tests use it, to order a racing commit against the snapshot.
    pub(super) async fn peek_empty_poll(
        &self,
        body: &Value,
        config: &QueryTaskConfig,
        proof: &str,
        fence: Option<&crate::runtime::worker_poll_fence::WorkerPollFence>,
        keys: &PollKeys,
        snapshot_taken: impl std::future::Future<Output = ()>,
    ) -> Result<bool> {
        use crate::runtime::poll_sweeps::Kind;
        let interval = self.poll_sweep_config.interval;
        if self.poll_sweeps.is_due(Kind::Query, interval) {
            return Ok(false);
        }
        // One fixed snapshot whatever the server's configured isolation.
        let mut read = self.begin_repeatable_read().await?;
        let proven = self.peek_empty_poll_in(&mut read, body, config, proof, fence, keys, snapshot_taken).await?;
        read.commit().await?;
        Ok(proven && !self.poll_sweeps.is_due(Kind::Query, interval))
    }

    #[allow(clippy::too_many_arguments)]
    async fn peek_empty_poll_in(
        &self,
        tx: &mut Transaction<'_, DB>,
        body: &Value,
        config: &QueryTaskConfig,
        proof: &str,
        fence: Option<&crate::runtime::worker_poll_fence::WorkerPollFence>,
        keys: &PollKeys,
        snapshot_taken: impl std::future::Future<Output = ()>,
    ) -> Result<bool> {
        let owner = text(body, "worker_id")?;
        let queue = text(body, "task_queue")?;
        let pending = Self::peek_poll_row(tx, &keys.pending).await?;
        snapshot_taken.await;
        if !matches!(pending, Peek::Absent) || !matches!(Self::peek_poll_row(tx, &keys.result).await?, Peek::Absent) {
            return Ok(false);
        }
        match Self::peek_poll_row(tx, &keys.current).await? {
            Peek::Absent => {}
            Peek::Unproven => return Ok(false),
            Peek::Live(current) => {
                // Exactly the row `mark_current_if_fresh` and the immediate
                // path write, naming another request.
                let Some(other) = current
                    .as_object()
                    .filter(|fields| fields.len() == 1)
                    .and_then(|fields| fields.get("poll_request_id"))
                    .and_then(Value::as_str)
                    .filter(|other| *other != keys.request_id)
                else {
                    return Ok(false);
                };
                // A waiting request has a pending row. Between becoming
                // current and starting to wait it has neither row, so only
                // a remembered answer shows it has finished.
                if !matches!(Self::peek_poll_row(tx, &PollKeys::key(&keys.scope, "pending", Some(other))).await?, Peek::Absent)
                    || !matches!(Self::peek_poll_row(tx, &PollKeys::key(&keys.scope, "result", Some(other))).await?, Peek::Live(_))
                {
                    return Ok(false);
                }
            }
        }
        let workers = Self::query("SELECT task_queue FROM workflow_worker_registrations WHERE namespace=$1 AND worker_id=$2 AND status='active'")
            .bind(self.namespace.as_ref())
            .bind(owner)
            .fetch_all(&mut **tx)
            .await?;
        if workers.len() != 1 || DB::string(&workers[0], "task_queue")? != queue {
            return Ok(false);
        }
        if Self::query_worker_proof(&mut **tx, self.namespace.as_ref(), owner, config).await?.as_deref() != Some(proof) {
            return Ok(false);
        }
        if let Some(fence) = fence
            && !self.worker_poll_fence_holds_in(tx, fence, owner).await?
        {
            return Ok(false);
        }
        Self::query_bookkeeping_idle(tx, self.namespace.as_ref()).await
    }

    pub(super) async fn coordinated_query_poll(
        &self,
        body: &Value,
        config: &QueryTaskConfig,
        proof: &str,
        fence: Option<&crate::runtime::worker_poll_fence::WorkerPollFence>,
        timeout: u64,
        deadline: tokio::time::Instant,
        keys: &PollKeys,
    ) -> Result<Value> {
        if timeout == 0 {
            if self.peek_empty_poll(body, config, proof, fence, keys, std::future::ready(())).await? {
                return Ok(poll_result(None, "empty"));
            }
            if let Some(response) = self.immediate_query_poll(body, config, proof, fence, keys).await? {
                return Ok(response);
            }
        } else {
            self.mark_current_if_fresh(keys, config).await?;
        }
        let owner = text(body, "worker_id")?;
        for _ in 0..3 {
            let current =
                Self::query_worker_proof(&self.pool, self.namespace.as_ref(), owner, config).await?;
            let fenced = match fence {
                Some(fence) => !self.worker_poll_fence_holds(fence, owner).await?,
                None => false,
            };
            if current.as_deref() != Some(proof) || fenced {
                return Ok(poll_result(None, "stale_worker_registration"));
            }
            if let Some(cached) = self.cached_query_poll(keys, body, proof, config).await? {
                return Ok(cached);
            }
            if self.try_start_query_poll(keys, config).await? {
                let outcome = self
                    .wait_for_query(body, config, proof, fence, timeout, deadline, Some(keys))
                    .await;
                match &outcome {
                    Ok(response) => self.remember_query_poll(keys, response, config).await?,
                    Err(_) => self.forget_pending_query_poll(keys).await?,
                }
                return outcome;
            }
            // Another poll with this request ID is waiting. Share its answer.
            let follower_deadline = tokio::time::Instant::now()
                + Duration::from_millis(config.poll_timeout_seconds.max(1) as u64 * 1000 + 250);
            loop {
                if let Some(cached) = self.cached_query_poll(keys, body, proof, config).await? {
                    return Ok(cached);
                }
                let mut tx = self.begin().await?;
                let pending = Self::poll_row(&mut tx, &keys.pending).await?.is_some();
                tx.commit().await?;
                if !pending || tokio::time::Instant::now() >= follower_deadline {
                    break;
                }
                tokio::time::sleep(Duration::from_millis(50)).await;
            }
        }
        Ok(self
            .cached_query_poll(keys, body, proof, config)
            .await?
            .unwrap_or_else(|| poll_result(None, "empty")))
    }
}
