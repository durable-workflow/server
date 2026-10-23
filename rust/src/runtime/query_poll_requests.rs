//! Per-request query poll results, kept as published PHP keeps them
//! (`QueryTaskPollRequestStore`). A retry of a poll request gets the answer the
//! original got, a duplicate still in flight waits for that answer, and a newer
//! request from the same worker ends an older one that is still waiting.
use super::{
    Backend, ColumnIndex, Decode, Duration, Encode, Executor, IntoArguments, QueryTaskConfig, Result, Store,
    Transaction, Type, Value, authority, json, key, now, poll_result, text,
};
use sha2::{Digest, Sha256};

// Distinct from the query task prefix, so query scans never see these rows.
const POLL_PREFIX: &str = "dw-native-query-poll:";

pub(super) struct PollKeys {
    request_id: String,
    pending: String,
    result: String,
    current: String,
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
        // MySQL and MariaDB keys are varchar(64): keep 128 bits of the digest.
        let key = |kind: &str, id: Option<&str>| {
            let material = json!([kind, self.namespace.as_ref(), queue, build, owner, id]);
            let digest: String = Sha256::digest(material.to_string().as_bytes())[..16]
                .iter()
                .map(|byte| format!("{byte:02x}"))
                .collect();
            format!("{POLL_PREFIX}{digest}")
        };
        Ok(PollKeys {
            request_id: request_id.to_owned(),
            pending: key("pending", Some(request_id)),
            result: key("result", Some(request_id)),
            current: key("current", None),
        })
    }

    async fn poll_row(tx: &mut Transaction<'_, DB>, key: &str) -> Result<Option<Value>> {
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
        let exists = Self::query(DB::QUERY_CACHE_GET)
            .bind(key)
            .fetch_optional(&mut **tx)
            .await?
            .is_some();
        let expiration = now() + ttl;
        if exists {
            Self::query(DB::QUERY_CACHE_UPDATE)
                .bind(value.to_string())
                .bind(expiration)
                .bind(key)
                .execute(&mut **tx)
                .await?;
        } else {
            Self::query(DB::QUERY_CACHE_INSERT)
                .bind(key)
                .bind(value.to_string())
                .bind(expiration)
                .execute(&mut **tx)
                .await?;
        }
        Ok(())
    }

    // Workers send a fresh request ID with every poll, so retire a bounded
    // batch of expired poll rows whenever a new request arrives.
    async fn sweep_poll_rows(tx: &mut Transaction<'_, DB>) -> Result<()> {
        let expired = Self::query(DB::QUERY_POLL_EXPIRED)
            .bind(now())
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
        Self::sweep_poll_rows(&mut tx).await?;
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
        let owner = text(body, "worker_id")?;
        let queue = text(body, "task_queue")?;
        let mut tx = self.begin().await?;
        let Some(result) = Self::poll_row(&mut tx, &keys.result).await? else {
            tx.commit().await?;
            return Ok(None);
        };
        let Some(task_id) = result["query_task_id"].as_str() else {
            tx.commit().await?;
            let status = result["poll_status"].as_str().unwrap_or("empty");
            return Ok(Some(poll_result(None, status)));
        };
        let at = authority::now();
        let task = Self::poll_row(&mut tx, &key(task_id)?).await?;
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
                    &mut tx,
                    &keys.result,
                    &json!({"query_task_id": null, "poll_status": "empty"}),
                    empty_ttl(config),
                )
                .await?;
                poll_result(None, "empty")
            }
        };
        tx.commit().await?;
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
        let task_id = response["task"]["query_task_id"].as_str();
        let ttl = match authority::timestamp(&response["task"]["lease_expires_at"]) {
            Some(lease) if task_id.is_some() => {
                ((lease - authority::now()).num_seconds() + 5).clamp(5, 3600)
            }
            _ => empty_ttl(config),
        };
        let mut tx = self.begin().await?;
        Self::put_poll_row(
            &mut tx,
            &keys.result,
            &json!({"query_task_id": task_id, "poll_status": response["poll_status"]}),
            ttl,
        )
        .await?;
        Self::query(DB::QUERY_CACHE_DELETE)
            .bind(&keys.pending)
            .execute(&mut *tx)
            .await?;
        tx.commit().await?;
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

    pub(super) async fn coordinated_query_poll(
        &self,
        body: &Value,
        config: &QueryTaskConfig,
        proof: &str,
        timeout: u64,
        deadline: tokio::time::Instant,
        keys: &PollKeys,
    ) -> Result<Value> {
        self.mark_current_if_fresh(keys, config).await?;
        let owner = text(body, "worker_id")?;
        for _ in 0..3 {
            let current =
                Self::query_worker_proof(&self.pool, self.namespace.as_ref(), owner, config).await?;
            if current.as_deref() != Some(proof) {
                return Ok(poll_result(None, "stale_worker_registration"));
            }
            if let Some(cached) = self.cached_query_poll(keys, body, proof, config).await? {
                return Ok(cached);
            }
            if self.try_start_query_poll(keys, config).await? {
                let outcome = self
                    .wait_for_query(body, config, proof, timeout, deadline, Some(keys))
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
