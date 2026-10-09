//! Bounded transient queries over an immutable, quiescent history snapshot.
//! These cache entries are native-owned; PHP cache values are never decoded.
use super::{
    Result,
    backend::Backend,
    envelope, refuse,
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
const WAIT_SECONDS: i64 = 30;
const LEASE_SECONDS: i64 = 10;
const RECEIPT_SECONDS: i64 = 60;
const SNAPSHOT_BYTES: usize = 1024 * 1024;
pub(super) const ARGUMENT_BYTES: usize = 64 * 1024;
pub(super) const RESULT_BYTES: usize = 256 * 1024;

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
    async fn query_entries(tx: &mut Transaction<'_, DB>) -> Result<Vec<(String, Value)>> {
        let rows = Self::query(DB::QUERY_CACHE_SCAN)
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
            entries.push((key, serde_json::from_str(&DB::string(&row, "value")?)?));
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
    ) -> Result<(StatusCode, Value)> {
        if name.is_empty() || name.len() > 255 {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "invalid_query_name",
            ));
        }
        let mut tx = self.begin().await?;
        let run = Self::query("SELECT r.* FROM workflow_runs r JOIN workflow_instances i ON i.id=r.workflow_instance_id WHERE i.id=$1 AND r.id=COALESCE($2,i.current_run_id) AND r.namespace='default'")
            .bind(workflow_id).bind(selected_run).fetch_optional(&mut *tx).await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "instance_not_found"))?;
        let run_id = DB::string(&run, "id")?;
        let status = DB::string(&run, "status")?;
        if !matches!(status.as_str(), "running" | "waiting" | "completed") {
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
        let entries = Self::query_entries(&mut tx).await?;
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
        let deadline = now() + WAIT_SECONDS;
        let task = json!({"query_task_id":task_id,"query_task_attempt":0,"status":"pending",
            "namespace":"default","workflow_id":workflow_id,"run_id":run_id,
            "workflow_type":DB::string(&run,"workflow_type")?,"workflow_class":DB::string(&run,"workflow_class")?,
            "task_queue":DB::string(&run,"queue")?,"payload_codec":"avro","query_name":name,
            "workflow_arguments":wire(&DB::string(&run,"arguments")?),"query_arguments":wire(&arguments),
            "run_status":status,"history_events":history,"last_history_sequence":DB::number(&run,"last_history_sequence")?,
            "history_cutoff_sequence":DB::number(&run,"last_history_sequence")?,"deadline":deadline});
        if serde_json::to_vec(&task)?.len() > SNAPSHOT_BYTES {
            return Err(refuse(
                StatusCode::PAYLOAD_TOO_LARGE,
                "query_snapshot_exceeds_budget",
            ));
        }
        Self::query(DB::QUERY_CACHE_INSERT)
            .bind(&key)
            .bind(task.to_string())
            .bind(deadline + RECEIPT_SECONDS)
            .execute(&mut *tx)
            .await?;
        tx.commit().await?;
        self.wake.notify_waiters();
        // Never hold the transition lock or a pooled connection across the wait.
        // Periodic probes observe results committed by another server process.
        loop {
            let wake = self.wake.notified();
            let row = Self::query(DB::QUERY_CACHE_GET)
                .bind(&key)
                .fetch_optional(&self.pool)
                .await?
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
                _ if now() >= deadline => {
                    let mut tx = self.begin().await?;
                    // Recheck under the same lock as completion: a committed
                    // result wins over a racing timeout.
                    let row = Self::query(DB::QUERY_CACHE_GET)
                        .bind(&key)
                        .fetch_one(&mut *tx)
                        .await?;
                    let mut task: Value = serde_json::from_str(&DB::string(&row, "value")?)?;
                    if matches!(task["status"].as_str(), Some("completed" | "failed")) {
                        continue;
                    }
                    task["status"] = json!("timed_out");
                    task.as_object_mut().unwrap().remove("history_events");
                    Self::put_query(&mut tx, &key, &task, now() + RECEIPT_SECONDS).await?;
                    tx.commit().await?;
                    return Err(refuse(StatusCode::CONFLICT, "query_task_timed_out"));
                }
                _ => {}
            }
            tokio::select! { _ = wake => {}, _ = tokio::time::sleep(Duration::from_millis(50)) => {} }
        }
    }

    pub(super) async fn poll_query(&self, body: Value) -> Result<Value> {
        text(&body, "worker_id")?;
        text(&body, "task_queue")?;
        let timeout = body
            .get("timeout_seconds")
            .map_or(Some(5), Value::as_u64)
            .filter(|v| *v <= 60)
            .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_poll_timeout"))?;
        let deadline = tokio::time::Instant::now() + Duration::from_secs(timeout);
        loop {
            let wake = self.wake.notified();
            if let Some(task) = self.claim_query(&body).await? {
                return Ok(
                    json!({"task":task,"poll_status":"task","server_capabilities":super::store::capabilities()}),
                );
            }
            if tokio::time::Instant::now() >= deadline {
                return Ok(
                    json!({"task":null,"poll_status":"timeout","server_capabilities":super::store::capabilities()}),
                );
            }
            tokio::select! { _ = wake => {}, _ = tokio::time::sleep(Duration::from_millis(50)) => {} }
        }
    }

    async fn claim_query(&self, body: &Value) -> Result<Option<Value>> {
        let owner = text(body, "worker_id")?;
        let queue = text(body, "task_queue")?;
        let mut tx = self.begin().await?;
        let worker = Self::query("SELECT * FROM workflow_worker_registrations WHERE namespace='default' AND worker_id=$1 AND status='active' AND last_heartbeat_at>$2")
            .bind(owner).bind(DB::bind_time(chrono::Utc::now() - chrono::Duration::seconds(60)))
            .fetch_optional(&mut *tx).await?.ok_or_else(|| refuse(StatusCode::CONFLICT, "worker_not_registered"))?;
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
            return Ok(None);
        }
        for (key, mut task) in Self::query_entries(&mut tx).await? {
            if !matches!(task["status"].as_str(), Some("pending" | "leased"))
                || task["deadline"].as_i64().unwrap_or(0) <= now()
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
            if task["status"] == "leased" && task["lease_until"].as_i64().unwrap_or(0) > now() {
                if task["lease_owner"] == owner {
                    return Ok(Some(task));
                }
                continue;
            }
            task["query_task_attempt"] =
                json!(task["query_task_attempt"].as_i64().unwrap_or(0) + 1);
            task["status"] = json!("leased");
            task["lease_owner"] = json!(owner);
            let expires = (now() + LEASE_SECONDS).min(task["deadline"].as_i64().unwrap());
            task["lease_until"] = json!(expires);
            task["lease_expires_at"] = json!(chrono::DateTime::from_timestamp(expires, 0).unwrap());
            Self::put_query(
                &mut tx,
                &key,
                &task,
                task["deadline"].as_i64().unwrap() + RECEIPT_SECONDS,
            )
            .await?;
            tx.commit().await?;
            return Ok(Some(task));
        }
        // Commit cleanup even when no compatible task is found.
        tx.commit().await?;
        Ok(None)
    }

    pub(super) async fn finish_query(
        &self,
        task_id: &str,
        body: Value,
        failed: bool,
    ) -> Result<Value> {
        if serde_json::to_vec(&body)?.len() > 512 * 1024 {
            return Err(refuse(
                StatusCode::PAYLOAD_TOO_LARGE,
                "query_completion_exceeds_budget",
            ));
        }
        let key = key(task_id)?;
        let owner = text(&body, "lease_owner")?;
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
        let mut task: Value = serde_json::from_str(&DB::string(&row, "value")?)?;
        if task["deadline"].as_i64().unwrap_or(0) <= now()
            || DB::number(&row, "expiration")? <= now()
        {
            return Err(refuse(StatusCode::CONFLICT, "query_task_timed_out"));
        }
        if task["status"] != "leased" {
            return Err(refuse(StatusCode::CONFLICT, "query_task_not_leased"));
        }
        if task["lease_owner"] != owner {
            return Err(refuse(
                StatusCode::CONFLICT,
                "query_task_lease_owner_mismatch",
            ));
        }
        if task["query_task_attempt"] != attempt {
            return Err(refuse(StatusCode::CONFLICT, "query_task_attempt_mismatch"));
        }
        if task["lease_until"].as_i64().unwrap_or(0) <= now() {
            return Err(refuse(StatusCode::CONFLICT, "query_task_lease_expired"));
        }
        // Registration revocation also fences an otherwise unexpired claim.
        let registered = Self::query("SELECT worker_id FROM workflow_worker_registrations WHERE namespace='default' AND worker_id=$1 AND status='active'")
            .bind(owner).fetch_optional(&mut *tx).await?;
        if registered.is_none() {
            return Err(refuse(StatusCode::CONFLICT, "worker_not_registered"));
        }
        let reason;
        if failed {
            let failure = &body["failure"];
            if !failure.is_object() || serde_json::to_vec(failure)?.len() > 32 * 1024 {
                return Err(refuse(
                    StatusCode::UNPROCESSABLE_ENTITY,
                    "invalid_query_failure",
                ));
            }
            reason = failure["reason"]
                .as_str()
                .filter(|v| !v.is_empty() && v.len() <= 255)
                .unwrap_or("query_rejected");
            task["failure"] = failure.clone();
            task["failure"]["reason"] = json!(reason);
            task["status"] = json!("failed");
        } else {
            let result = envelope(&body, "result_envelope")?;
            if result.len() > RESULT_BYTES {
                return Err(refuse(
                    StatusCode::PAYLOAD_TOO_LARGE,
                    "query_result_exceeds_budget",
                ));
            }
            task["result_envelope"] = wire(&result);
            task["status"] = json!("completed");
            reason = "";
        }
        // Results reserve their slot at admission, and discard the large replay
        // snapshot. No workflow rows, histories, cursors or waits are changed.
        let object = task.as_object_mut().unwrap();
        for field in ["history_events", "workflow_arguments", "query_arguments"] {
            object.remove(field);
        }
        Self::put_query(&mut tx, &key, &task, now() + RECEIPT_SECONDS).await?;
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(
            json!({"query_task_id":task_id,"query_task_attempt":attempt,"outcome":if failed {"failed"} else {"completed"},
            "reason":if failed {Some(reason)} else {None},"status":200}),
        )
    }
}
