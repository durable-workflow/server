//! Bounded transient queries over an immutable, quiescent history snapshot.
//! These cache entries are native-owned; PHP cache values are never decoded.
use super::{
    Result,
    backend::Backend,
    envelope,
    query_authority::{self as authority, QueryTaskConfig},
    refuse,
    store::{Store, validate_arguments},
    text, wire,
};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Transaction, Type};
use std::{sync::Arc, time::Duration};
use tokio::sync::Semaphore;

const PREFIX: &str = "dw-native-query:";
const MAX_PENDING: usize = 16;
const MAX_ENTRIES: usize = 64;
const SNAPSHOT_BYTES: usize = 1024 * 1024;
pub(super) const ARGUMENT_BYTES: usize = 64 * 1024;
pub(super) const RESULT_BYTES: usize = 256 * 1024;

enum QueryClaim {
    Task(Value),
    Empty,
    StaleWorker,
}

fn poll_result(task: Option<Value>, status: &str) -> Value {
    json!({"task":task,"poll_status":status,"server_capabilities":super::store::capabilities()})
}

fn now() -> i64 {
    chrono::Utc::now().timestamp()
}
fn key(id: &str) -> Result<String> {
    // A task ID cannot address another application's cache key.
    if id.parse::<ulid::Ulid>().is_err() {
        return Err(refuse(StatusCode::NOT_FOUND, "query_task_not_found"));
    }
    Ok(format!("{PREFIX}{id}"))
}

pub(super) async fn empty_arguments(semaphore: Arc<Semaphore>) -> Result<String> {
    let permit = semaphore
        .acquire_owned()
        .await
        .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "query_codec_unavailable"))?;
    tokio::task::spawn_blocking(move || {
        let _permit = permit;
        let codec = crate::codec::ValueCodec::new()
            .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "query_codec_unavailable"))?;
        codec
            .encode(&crate::codec::Value::Array(vec![]))
            .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "query_codec_unavailable"))
    })
    .await
    .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "query_codec_unavailable"))?
}

pub(super) async fn decode(
    semaphore: Arc<Semaphore>,
    blob: String,
    arguments: bool,
    invalid: &'static str,
) -> Result<Vec<crate::codec::Value>> {
    let permit = semaphore
        .acquire_owned()
        .await
        .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "query_codec_unavailable"))?;
    tokio::task::spawn_blocking(move || {
        let _permit = permit;
        let codec = crate::codec::ValueCodec::new()
            .map_err(|_| refuse(StatusCode::UNPROCESSABLE_ENTITY, invalid))?;
        let value = codec
            .decode(&blob)
            .map_err(|_| refuse(StatusCode::UNPROCESSABLE_ENTITY, invalid))?;
        if arguments {
            match value {
                crate::codec::Value::Array(values) => Ok(values),
                _ => Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, invalid)),
            }
        } else {
            Ok(vec![value])
        }
    })
    .await
    .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "query_codec_unavailable"))?
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
    pub(super) async fn query_entries(
        &self,
        tx: &mut Transaction<'_, DB>,
    ) -> Result<Vec<(String, Value)>> {
        let rows = Self::query(DB::QUERY_CACHE_SCAN)
            .bind(self.namespace.to_string())
            .fetch_all(&mut **tx)
            .await?;
        let mut entries = vec![];
        for row in rows {
            let key = DB::string(&row, "key")?;
            if DB::number(&row, "expiration")? <= now() {
                Self::query(DB::QUERY_CACHE_DELETE)
                    .bind(&key)
                    .execute(&mut **tx)
                    .await?;
                continue;
            }
            let mut task: Value = serde_json::from_str(&DB::string(&row, "value")?)?;
            if authority::expire(&mut task, authority::now())? {
                Self::put_query(tx, &key, &task, authority::expiration(&task)?).await?;
            }
            entries.push((key, task));
        }
        if entries.len() > MAX_ENTRIES {
            return Err(refuse(
                StatusCode::SERVICE_UNAVAILABLE,
                "query_task_queue_full",
            ));
        }
        Ok(entries)
    }

    async fn put_query(
        tx: &mut Transaction<'_, DB>,
        key: &str,
        value: &Value,
        expiration: i64,
    ) -> Result<()> {
        Self::query(DB::QUERY_CACHE_UPDATE)
            .bind(value.to_string())
            .bind(expiration)
            .bind(key)
            .execute(&mut **tx)
            .await?;
        Ok(())
    }

    pub(super) async fn query_workflow(
        &self,
        workflow_id: &str,
        selected_run: Option<&str>,
        name: &str,
        arguments: String,
        values: Vec<crate::codec::Value>,
        config: &QueryTaskConfig,
    ) -> Result<(StatusCode, Value)> {
        if name.is_empty() || name.len() > 255 {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "invalid_query_name",
            ));
        }
        let mut tx = self.begin().await?;
        let run = Self::query("SELECT r.* FROM workflow_runs r JOIN workflow_instances i ON i.id=r.workflow_instance_id WHERE i.id=$1 AND r.id=COALESCE($2,i.current_run_id) AND r.namespace=$3")
            .bind(workflow_id).bind(selected_run).bind(self.namespace.as_ref()).fetch_optional(&mut *tx).await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "instance_not_found"))?;
        if DB::string(&run, "namespace")? != self.namespace.as_ref()
            || DB::string(&run, "workflow_instance_id")? != workflow_id
            || selected_run.is_some_and(|id| DB::string(&run, "id").ok().as_deref() != Some(id))
        {
            return Err(refuse(StatusCode::NOT_FOUND, "instance_not_found"));
        }
        let run_id = DB::string(&run, "id")?;
        let status = DB::string(&run, "status")?;
        if !matches!(
            status.as_str(),
            "pending" | "running" | "waiting" | "completed"
        ) {
            return Err(refuse(
                StatusCode::CONFLICT,
                "query_unavailable_for_run_status",
            ));
        }
        let busy = Self::scalar("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status IN ('ready','leased')")
            .bind(&run_id).fetch_one(&mut *tx).await?;
        if busy != 0 {
            return Err(refuse(StatusCode::CONFLICT, "query_snapshot_busy"));
        }
        let contract = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='WorkflowStarted' ORDER BY sequence LIMIT 1")
            .bind(&run_id).fetch_one(&mut *tx).await?;
        let contract = DB::document_row(&contract, "payload")?;
        if !contract["declared_queries"]
            .as_array()
            .is_some_and(|names| names.iter().any(|v| v == name))
        {
            return Err(refuse(StatusCode::NOT_FOUND, "rejected_unknown_query"));
        }
        validate_arguments(
            &contract,
            "declared_query_contracts",
            name,
            &values,
            "unsupported_query_contract",
            "invalid_query_arguments",
        )?;
        let entries = self.query_entries(&mut tx).await?;
        if entries.len() >= MAX_ENTRIES
            || entries
                .iter()
                .filter(|(_, v)| matches!(v["status"].as_str(), Some("pending" | "leased")))
                .count()
                >= MAX_PENDING
        {
            return Err(refuse(
                StatusCode::SERVICE_UNAVAILABLE,
                "query_task_queue_full",
            ));
        }
        let (history, next) = Self::history_page_connection(&mut tx, &run_id, 0, 1000).await?;
        if next.is_some() || serde_json::to_vec(&history)?.len() > SNAPSHOT_BYTES {
            return Err(refuse(
                StatusCode::PAYLOAD_TOO_LARGE,
                "query_snapshot_exceeds_budget",
            ));
        }
        let task_id = ulid::Ulid::new().to_string();
        let key = key(&task_id)?;
        let admitted = authority::now();
        let (deadline, retained_until) = config.timing(admitted)?;
        let mut task = json!({"query_task_id":task_id,"query_task_attempt":0,"status":"pending",
            "namespace":self.namespace.as_ref(),"workflow_id":workflow_id,"run_id":run_id,
            "workflow_type":DB::string(&run,"workflow_type")?,"workflow_class":DB::string(&run,"workflow_class")?,
            "task_queue":DB::string(&run,"queue")?,"payload_codec":"avro","query_name":name,
            "workflow_arguments":wire(&DB::string(&run,"arguments")?),"query_arguments":wire(&arguments),
            "run_status":status,"history_events":history,"last_history_sequence":DB::number(&run,"last_history_sequence")?,
            "history_cutoff_sequence":DB::number(&run,"last_history_sequence")?,
            "query_admitted_at":authority::wire(admitted),"query_deadline_at":authority::wire(deadline),
            "receipt_expires_at":authority::wire(retained_until)});
        authority::expire(&mut task, authority::now())?;
        if serde_json::to_vec(&task)?.len() > SNAPSHOT_BYTES {
            return Err(refuse(
                StatusCode::PAYLOAD_TOO_LARGE,
                "query_snapshot_exceeds_budget",
            ));
        }
        Self::query(DB::QUERY_CACHE_INSERT)
            .bind(&key)
            .bind(task.to_string())
            .bind(authority::expiration(&task)?)
            .execute(&mut *tx)
            .await?;
        tx.commit().await?;
        self.wake.notify_waiters();
        // Never hold the transition lock or a pooled connection across the wait.
        // Periodic probes observe results committed by another server process.
        let timed_out = || super::RuntimeError::Protocol {
            status: StatusCode::GATEWAY_TIMEOUT,
            response: json!({"workflow_id":workflow_id,"run_id":run_id,"query_name":name,
                "reason":"query_task_timed_out","message":"Timed out waiting for the original query result."}),
        };
        loop {
            let wake = self.wake.notified();
            let remaining = (deadline - authority::now()).to_std().unwrap_or_default();
            let read = Self::query(DB::QUERY_CACHE_GET)
                .bind(&key)
                .fetch_optional(&self.pool);
            let row = tokio::time::timeout(remaining, read)
                .await
                .map_err(|_| timed_out())??
                .ok_or_else(|| refuse(StatusCode::CONFLICT, "query_task_timed_out"))?;
            let task: Value = serde_json::from_str(&DB::string(&row, "value")?)?;
            match task["status"].as_str() {
                Some("completed") => {
                    return Ok((
                        StatusCode::OK,
                        json!({"workflow_id":workflow_id,"run_id":run_id,
                    "query_name":name,"result":null,"result_envelope":task["result_envelope"],"reason":null}),
                    ));
                }
                Some("failed") => {
                    let status = match task["failure"]["reason"].as_str() {
                        Some("rejected_unknown_query") => StatusCode::NOT_FOUND,
                        Some("invalid_query_arguments") => StatusCode::UNPROCESSABLE_ENTITY,
                        _ => StatusCode::CONFLICT,
                    };
                    let mut body = task["failure"].clone();
                    body["workflow_id"] = json!(workflow_id);
                    body["run_id"] = json!(run_id);
                    body["query_name"] = json!(name);
                    return Ok((status, body));
                }
                Some("timed_out") => {
                    return Err(timed_out());
                }
                _ if authority::deadline(&task)
                    .is_some_and(|deadline| authority::now() >= deadline) =>
                {
                    // The stored deadline already fences every new outcome.
                    // Cleanup must not delay the caller behind a busy writer.
                    return Err(timed_out());
                }
                _ if authority::deadline(&task).is_none() => {
                    let mut tx = self.begin().await?;
                    // Recheck under the same lock as completion: a committed
                    // result wins over a racing timeout.
                    let row = Self::query(DB::QUERY_CACHE_GET)
                        .bind(&key)
                        .fetch_one(&mut *tx)
                        .await?;
                    let mut task: Value = serde_json::from_str(&DB::string(&row, "value")?)?;
                    if matches!(task["status"].as_str(), Some("completed" | "failed")) {
                        tx.rollback().await?;
                        continue;
                    }
                    authority::expire(&mut task, authority::now())?;
                    Self::put_query(&mut tx, &key, &task, authority::expiration(&task)?).await?;
                    tx.commit().await?;
                    continue;
                }
                _ => {}
            }
            tokio::select! { _ = wake => {}, _ = tokio::time::sleep(Duration::from_millis(50)) => {} }
        }
    }

    pub(super) async fn poll_query(&self, body: Value, config: &QueryTaskConfig) -> Result<Value> {
        let owner = text(&body, "worker_id")?;
        text(&body, "task_queue")?;
        let timeout = body
            .get("timeout_seconds")
            .map_or(Some(5), Value::as_u64)
            .filter(|v| *v <= 60)
            .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_poll_timeout"))?;
        let timeout = timeout.min(config.poll_timeout_seconds.max(1) as u64);
        let deadline = tokio::time::Instant::now() + Duration::from_secs(timeout);
        // One request belongs to the registration observed before its wait.
        // A later same-ID registration cannot silently upgrade this poll.
        let Some(proof) =
            Self::query_worker_proof(&self.pool, self.namespace.as_ref(), owner, config).await?
        else {
            return Ok(poll_result(None, "stale_worker_registration"));
        };
        loop {
            let wake = self.wake.notified();
            match self.claim_query(&body, config, &proof).await? {
                QueryClaim::Task(task) => return Ok(poll_result(Some(task), "task")),
                QueryClaim::StaleWorker => {
                    return Ok(poll_result(None, "stale_worker_registration"));
                }
                QueryClaim::Empty => {}
            }
            if tokio::time::Instant::now() >= deadline {
                return Ok(poll_result(None, "timeout"));
            }
            tokio::select! { _ = wake => {}, _ = tokio::time::sleep(Duration::from_millis(50)) => {} }
        }
    }

    async fn query_worker_proof<'e, E>(
        executor: E,
        namespace: &str,
        owner: &str,
        config: &QueryTaskConfig,
    ) -> Result<Option<String>>
    where
        E: Executor<'e, Database = DB>,
    {
        let rows = Self::query("SELECT w.worker_id,w.namespace,w.last_heartbeat_at,i.token,i.worker_id AS incarnation_worker_id,i.namespace AS incarnation_namespace FROM workflow_worker_registrations w JOIN dw_worker_registration_incarnations i ON i.worker_id=w.worker_id AND i.namespace=w.namespace AND i.status='active' WHERE w.namespace=$1 AND w.worker_id=$2 AND w.status='active' LIMIT 2")
            .bind(namespace.to_owned()).bind(owner.to_owned()).fetch_all(executor).await?;
        if rows.len() != 1 {
            return Ok(None);
        }
        let row = &rows[0];
        if DB::string(row, "worker_id")? != owner
            || DB::string(row, "namespace")? != namespace
            || DB::string(row, "incarnation_worker_id")? != owner
            || DB::string(row, "incarnation_namespace")? != namespace
            || DB::instant(row, "last_heartbeat_at")?
                <= QueryTaskConfig::add(
                    authority::now(),
                    -config.worker_stale_after_seconds.max(1),
                )?
        {
            return Ok(None);
        }
        use sha2::{Digest, Sha256};
        let digest: String = Sha256::digest(DB::string(row, "token")?.as_bytes())
            .iter()
            .map(|byte| format!("{byte:02x}"))
            .collect();
        Ok(Some(digest))
    }

    fn query_delivery(task: &Value) -> Value {
        let mut delivered = task.clone();
        for field in [
            "receipt_expires_at",
            "terminal_receipt",
            "registration_incarnation",
            "legacy_refusal_at",
        ] {
            delivered
                .as_object_mut()
                .expect("native query task")
                .remove(field);
        }
        delivered["server_time"] = json!(authority::wire(authority::now()));
        delivered
    }

    async fn claim_query(
        &self,
        body: &Value,
        config: &QueryTaskConfig,
        expected_proof: &str,
    ) -> Result<QueryClaim> {
        let owner = text(body, "worker_id")?;
        let queue = text(body, "task_queue")?;
        let mut tx = self.begin().await?;
        let worker = Self::query("SELECT * FROM workflow_worker_registrations WHERE namespace=$1 AND worker_id=$2 AND status='active'")
            .bind(self.namespace.as_ref()).bind(owner)
            .fetch_optional(&mut *tx).await?;
        let Some(worker) = worker else {
            return Ok(QueryClaim::StaleWorker);
        };
        let proof =
            Self::query_worker_proof(&mut *tx, self.namespace.as_ref(), owner, config).await?;
        let Some(proof) = proof.filter(|proof| proof == expected_proof) else {
            return Ok(QueryClaim::StaleWorker);
        };
        if DB::string(&worker, "task_queue")? != queue {
            return Err(refuse(StatusCode::CONFLICT, "task_queue_mismatch"));
        }
        let types = DB::document_row(&worker, "supported_workflow_types")?;
        let contracts = DB::document_row(&worker, "workflow_command_contracts")?;
        let capabilities = DB::document_row(&worker, "capabilities")?;
        if !capabilities
            .as_array()
            .is_some_and(|values| values.iter().any(|v| v == "query_tasks"))
        {
            return Ok(QueryClaim::Empty);
        }
        for (key, mut task) in self.query_entries(&mut tx).await? {
            if !matches!(task["status"].as_str(), Some("pending" | "leased"))
                || task["namespace"] != self.namespace.as_ref()
                || authority::deadline(&task).is_none_or(|deadline| deadline <= authority::now())
                || task["task_queue"] != queue
                || !types
                    .as_array()
                    .is_some_and(|types| types.contains(&task["workflow_type"]))
            {
                continue;
            }
            let Some(workflow_type) = task["workflow_type"].as_str() else {
                continue;
            };
            if !contracts[workflow_type]["queries"]
                .as_array()
                .is_some_and(|names| names.contains(&task["query_name"]))
            {
                continue;
            }
            if task["status"] == "leased"
                && authority::timestamp(&task["lease_expires_at"])
                    .is_some_and(|lease| lease > authority::now())
            {
                let existing_owner = task["lease_owner"].as_str().unwrap_or("");
                let existing_proof = Self::query_worker_proof(
                    &mut *tx,
                    self.namespace.as_ref(),
                    existing_owner,
                    config,
                )
                .await?;
                if existing_proof
                    .as_deref()
                    .is_some_and(|proof| task["registration_incarnation"] == proof)
                {
                    if existing_owner == owner {
                        let delivered = Self::query_delivery(&task);
                        tx.commit().await?;
                        return Ok(QueryClaim::Task(delivered));
                    }
                    continue;
                }
            }
            let next_attempt = task["query_task_attempt"]
                .as_i64()
                .and_then(|attempt| attempt.checked_add(1))
                .ok_or_else(|| refuse(StatusCode::CONFLICT, "query_task_attempt_exhausted"))?;
            task["query_task_attempt"] = json!(next_attempt);
            task["status"] = json!("leased");
            task["lease_owner"] = json!(owner);
            let time = authority::now();
            let expires = QueryTaskConfig::add(time, config.lease_timeout_seconds.max(1))?
                .min(authority::deadline(&task).expect("checked original deadline"));
            if expires <= time {
                continue;
            }
            task["lease_expires_at"] = json!(authority::wire(expires));
            task["registration_incarnation"] = json!(proof);
            Self::put_query(&mut tx, &key, &task, authority::expiration(&task)?).await?;
            tx.commit().await?;
            return Ok(QueryClaim::Task(Self::query_delivery(&task)));
        }
        // Commit cleanup even when no compatible task is found.
        tx.commit().await?;
        Ok(QueryClaim::Empty)
    }

    fn cached_query(row: &DB::Row, id: &str, namespace: &str) -> Result<Value> {
        if DB::number(row, "expiration")? <= now() {
            return Err(refuse(StatusCode::NOT_FOUND, "query_task_not_found"));
        }
        let task: Value = serde_json::from_str(&DB::string(row, "value")?)?;
        authority::scope(&task, id, namespace)?;
        Ok(task)
    }

    async fn query_claim_reason<'e, E>(
        executor: E,
        task: &Value,
        owner: &str,
        attempt: i64,
        config: &QueryTaskConfig,
    ) -> Result<Option<&'static str>>
    where
        E: Executor<'e, Database = DB>,
    {
        let reason = authority::claim_reason(task, owner, attempt, authority::now());
        if reason.is_some_and(|reason| reason != "lease_expired") {
            return Ok(reason);
        }
        let proof = Self::query_worker_proof(
            executor,
            task["namespace"].as_str().unwrap_or(""),
            owner,
            config,
        )
        .await?;
        if proof
            .as_deref()
            .is_none_or(|proof| task["registration_incarnation"] != proof)
        {
            return Ok(Some("stale_worker_registration"));
        }
        // Database reads can consume the remaining lease. Recheck against a
        // fresh clock after observing current registration authority.
        Ok(authority::claim_reason(
            task,
            owner,
            attempt,
            authority::now(),
        ))
    }

    pub(super) async fn query_status(
        &self,
        id: &str,
        owner: &str,
        attempt: i64,
        config: &QueryTaskConfig,
    ) -> Result<Value> {
        let key = key(id)?;
        let row = Self::query(DB::QUERY_CACHE_GET)
            .bind(&key)
            .fetch_optional(&self.pool)
            .await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "query_task_not_found"))?;
        let task = Self::cached_query(&row, id, self.namespace.as_ref())?;
        // Observation reveals timing only to the bound claim. It neither
        // expires a row nor writes a tombstone, heartbeat or receipt.
        if task["lease_owner"] != owner {
            return Err(authority::reject_reason("lease_owner_mismatch"));
        }
        if task["query_task_attempt"] != attempt {
            return Err(authority::reject_reason("query_task_attempt_mismatch"));
        }
        let reason = Self::query_claim_reason(&self.pool, &task, owner, attempt, config).await?;
        let status = match reason {
            Some("query_deadline_unavailable") => "failed",
            Some("query_task_timed_out") => "timed_out",
            _ => task["status"].as_str().unwrap_or("failed"),
        };
        Ok(
            json!({"query_task_id":id,"query_task_attempt":attempt,"lease_owner":owner,
            "query_admitted_at":task["query_admitted_at"],"query_deadline_at":task["query_deadline_at"],
            "lease_expires_at":task["lease_expires_at"],"server_time":authority::wire(authority::now()),
            "query_task_status":status,"can_continue":reason.is_none(),"reason":reason,"status":200}),
        )
    }

    pub(super) async fn guard_query_outcome(
        &self,
        id: &str,
        body: &Value,
        failed: bool,
        config: &QueryTaskConfig,
    ) -> Result<Option<Value>> {
        self.query_outcome(id, body, failed, config, false).await
    }

    pub(super) async fn finish_query(
        &self,
        task_id: &str,
        body: Value,
        failed: bool,
        config: &QueryTaskConfig,
    ) -> Result<Value> {
        self.query_outcome(task_id, &body, failed, config, true)
            .await?
            .ok_or_else(|| refuse(StatusCode::SERVICE_UNAVAILABLE, "query_outcome_unavailable"))
    }

    async fn query_outcome(
        &self,
        task_id: &str,
        body: &Value,
        failed: bool,
        config: &QueryTaskConfig,
        commit: bool,
    ) -> Result<Option<Value>> {
        if serde_json::to_vec(&body)?.len() > 512 * 1024 {
            return Err(refuse(
                StatusCode::PAYLOAD_TOO_LARGE,
                "query_completion_exceeds_budget",
            ));
        }
        let key = key(task_id)?;
        let owner = text(body, "lease_owner")?;
        let attempt = body["query_task_attempt"]
            .as_i64()
            .filter(|v| *v > 0)
            .ok_or_else(|| {
                refuse(
                    StatusCode::UNPROCESSABLE_ENTITY,
                    "invalid_query_task_attempt",
                )
            })?;
        let mut tx = self.begin().await?;
        let row = Self::query(DB::QUERY_CACHE_GET)
            .bind(&key)
            .fetch_optional(&mut *tx)
            .await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "query_task_not_found"))?;
        let mut task = Self::cached_query(&row, task_id, self.namespace.as_ref())?;
        let fingerprint = authority::operation(body, failed)?;
        if let Some(receipt) =
            authority::receipt(&task, owner, attempt, &fingerprint, authority::now())?
        {
            tx.rollback().await?;
            return Ok(Some(receipt));
        }
        if authority::expire(&mut task, authority::now())? {
            let reason = if authority::deadline(&task).is_none() {
                "query_deadline_unavailable"
            } else {
                "query_task_timed_out"
            };
            Self::put_query(&mut tx, &key, &task, authority::expiration(&task)?).await?;
            tx.commit().await?;
            self.wake.notify_waiters();
            return Err(authority::reject_reason(reason));
        }
        if let Some(reason) =
            Self::query_claim_reason(&mut *tx, &task, owner, attempt, config).await?
        {
            return Err(authority::reject_reason(reason));
        }
        if !commit {
            tx.rollback().await?;
            return Ok(None);
        }
        let reason: Option<String>;
        let failure;
        let result;
        if failed {
            let supplied = &body["failure"];
            if !supplied.is_object() || serde_json::to_vec(supplied)?.len() > 32 * 1024 {
                return Err(refuse(
                    StatusCode::UNPROCESSABLE_ENTITY,
                    "invalid_query_failure",
                ));
            }
            reason = Some(
                supplied["reason"]
                    .as_str()
                    .filter(|v| !v.is_empty() && v.len() <= 255)
                    .unwrap_or("query_rejected")
                    .to_owned(),
            );
            failure = Some(supplied.clone());
            result = None;
        } else {
            let supplied = envelope(body, "result_envelope")?;
            if supplied.len() > RESULT_BYTES {
                return Err(refuse(
                    StatusCode::PAYLOAD_TOO_LARGE,
                    "query_result_exceeds_budget",
                ));
            }
            result = Some(wire(&supplied));
            failure = None;
            reason = None;
        }
        let decided_at = authority::now();
        if let Some(reason) = authority::claim_reason(&task, owner, attempt, decided_at) {
            return Err(authority::reject_reason(reason));
        }
        let decision = json!({"query_task_id":task_id,"query_task_attempt":attempt,
            "outcome":if failed {"failed"} else {"completed"},"reason":reason,"status":200,"recorded":true});
        task["status"] = json!(if failed { "failed" } else { "completed" });
        if let Some(failure) = failure {
            task["failure"] = failure;
            task["failure"]["reason"] = json!(reason);
        }
        if let Some(result) = result {
            task["result_envelope"] = result;
        }
        task["terminal_receipt"] = json!({"lease_owner":owner,"attempt":attempt,
            "fingerprint":fingerprint,"decision":decision,"decided_at":authority::wire(decided_at)});
        // Results reserve their slot at admission, and discard the large replay
        // snapshot. No workflow rows, histories, cursors or waits are changed.
        authority::discard_snapshot(&mut task);
        Self::put_query(&mut tx, &key, &task, authority::expiration(&task)?).await?;
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(Some(decision))
    }
}
