//! One durable execution state machine for typed database adapters.
use super::{Result, backend::Backend, envelope, refuse, text, wire};
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Pool, Transaction, Type};
use std::{sync::Arc, time::Duration};
use tokio::sync::Notify;
use ulid::Ulid;

const LEASE_SECONDS: i64 = 30;
pub(super) const WORKER_REGISTRATION_SQL: &str = "INSERT INTO workflow_worker_registrations(namespace,worker_id,task_queue,runtime,sdk_version,build_id,supported_workflow_types,supported_activity_types,capabilities,capability_manifest,last_heartbeat_at,created_at,updated_at) VALUES ('default',$1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12) ON CONFLICT(worker_id,namespace) DO UPDATE SET task_queue=excluded.task_queue,runtime=excluded.runtime,sdk_version=excluded.sdk_version,build_id=excluded.build_id,supported_workflow_types=excluded.supported_workflow_types,supported_activity_types=excluded.supported_activity_types,capabilities=excluded.capabilities,capability_manifest=excluded.capability_manifest,last_heartbeat_at=excluded.last_heartbeat_at,updated_at=excluded.updated_at";
pub(super) struct Store<DB: Backend> {
    pub(super) pool: Pool<DB>,
    wake: Arc<Notify>,
}
fn id() -> String {
    Ulid::new().to_string()
}
fn now() -> DateTime<Utc> {
    DateTime::from_timestamp_millis(Utc::now().timestamp_millis()).expect("current timestamp")
}
fn after(seconds: i64) -> DateTime<Utc> {
    now() + chrono::Duration::seconds(seconds)
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
    fn query(sql: &'static str) -> sqlx::query::Query<'static, DB, DB::Arguments> {
        // Dialect conversion starts from a compiled static template. Runtime
        // values are always encoded by bind(), never interpolated into SQL.
        sqlx::query(sqlx::AssertSqlSafe(DB::statement(sql)))
    }
    fn scalar(sql: &'static str) -> sqlx::query::QueryScalar<'static, DB, i64, DB::Arguments> {
        sqlx::query_scalar(sqlx::AssertSqlSafe(DB::statement(sql)))
    }
    pub(super) fn new(pool: Pool<DB>) -> Self {
        Self {
            pool,
            wake: Arc::new(Notify::new()),
        }
    }
    pub(super) async fn database_live(&self) -> bool {
        Self::query("SELECT 1").execute(&self.pool).await.is_ok()
    }
    pub(super) async fn schema_ready(&self) -> bool {
        if !DB::history_ready(&self.pool).await {
            return false;
        }
        for query in [
            "SELECT id,namespace,current_run_id FROM workflow_instances LIMIT 0",
            "SELECT id,status,arguments,output,last_history_sequence,last_command_sequence,run_deadline_at FROM workflow_runs LIMIT 0",
            "SELECT id,sequence,payload,recorded_at FROM workflow_history_events LIMIT 0",
            "SELECT id,status,payload,lease_owner,lease_expires_at,attempt_count FROM workflow_tasks LIMIT 0",
            "SELECT id,sequence,activity_type,status,arguments,result FROM activity_executions LIMIT 0",
            "SELECT id,workflow_task_id,attempt_number,status FROM activity_attempts LIMIT 0",
            "SELECT namespace,worker_id,supported_workflow_types,supported_activity_types FROM workflow_worker_registrations LIMIT 0",
            "SELECT task_id,receipt FROM dw_task_completions LIMIT 0",
            "SELECT request_id,task_id,attempt,response,expires_at FROM dw_poll_receipts LIMIT 0",
        ] {
            if Self::query(query).execute(&self.pool).await.is_err() {
                return false;
            }
        }
        true
    }

    pub(super) async fn close(&self) {
        self.pool.close().await;
    }
    async fn begin(&self) -> Result<Transaction<'static, DB>> {
        DB::begin(&self.pool).await
    }
    pub(crate) async fn start(&self, body: Value) -> Result<Value> {
        let workflow_id = text(&body, "workflow_id")?;
        if workflow_id.len() > 128
            || !workflow_id
                .bytes()
                .all(|c| c.is_ascii_alphanumeric() || b"._:-".contains(&c))
        {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "invalid_workflow_id",
            ));
        }
        let workflow_type = text(&body, "workflow_type")?;
        let queue = text(&body, "task_queue")?;
        let arguments = envelope(&body, "input")?;
        let execution_timeout = positive_seconds(&body, "execution_timeout_seconds", 3600)?;
        let run_timeout = positive_seconds(&body, "run_timeout_seconds", 600)?;
        reject_fields(
            &body,
            &[
                "workflow_id",
                "workflow_type",
                "task_queue",
                "input",
                "execution_timeout_seconds",
                "run_timeout_seconds",
                "duplicate_policy",
            ],
        )?;
        if body
            .get("duplicate_policy")
            .is_some_and(|v| v != "fail" && v != "use-existing")
        {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "invalid_duplicate_policy",
            ));
        }
        let mut tx = self.begin().await?;
        if let Some(existing) = Self::query("SELECT r.* FROM workflow_instances i JOIN workflow_runs r ON r.id=i.current_run_id WHERE i.id=$1")
            .bind(workflow_id).fetch_optional(&mut *tx).await? {
            if body["duplicate_policy"] == "use-existing" && matches!(DB::string(&existing,"status")?.as_str(), "running" | "waiting") {
                return Ok(json!({"workflow_id": workflow_id, "run_id": DB::string(&existing,"id")?,
                    "workflow_type": DB::string(&existing,"workflow_type")?, "namespace": "default",
                    "status": DB::string(&existing,"status")?, "payload_codec": "avro", "outcome": "used_existing_active"}));
            }
            return Err(refuse(StatusCode::CONFLICT, "workflow_id_already_exists"));
        }
        let run_id = id();
        let command_id = id();
        let started_at = now();
        let execution_deadline = after(execution_timeout);
        let run_deadline = after(run_timeout.min(execution_timeout));
        Self::query("INSERT INTO workflow_instances(id,namespace,workflow_type,workflow_class,current_run_id,run_count,execution_timeout_seconds,created_at,updated_at,started_at) VALUES ($1,'default',$2,$3,$4,1,$5,$6,$7,$8)")
            .bind(workflow_id).bind(workflow_type).bind(workflow_type).bind(&run_id).bind(execution_timeout)
            .bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at)).execute(&mut *tx).await?;
        Self::query("INSERT INTO workflow_runs(id,workflow_instance_id,namespace,workflow_type,workflow_class,run_number,status,payload_codec,arguments,queue,run_timeout_seconds,execution_deadline_at,run_deadline_at,started_at,created_at,updated_at) VALUES ($1,$2,'default',$3,$4,1,'running','avro',$5,$6,$7,$8,$9,$10,$11,$12)")
            .bind(&run_id).bind(workflow_id).bind(workflow_type).bind(workflow_type).bind(arguments)
            .bind(queue).bind(run_timeout).bind(DB::bind_time(execution_deadline)).bind(DB::bind_time(run_deadline))
            .bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at)).execute(&mut *tx).await?;
        let identity = json!({"workflow_instance_id": workflow_id, "workflow_run_id": run_id,
            "workflow_type": workflow_type, "workflow_class": workflow_type, "workflow_command_id": command_id});
        let mut accepted = identity.clone();
        accepted["outcome"] = json!("started_new");
        Self::append(
            &mut tx,
            &run_id,
            "StartAccepted",
            accepted,
            None,
            Some(&command_id),
        )
        .await?;
        let mut started = identity;
        started["execution_timeout_seconds"] = json!(execution_timeout);
        started["run_timeout_seconds"] = json!(run_timeout);
        started["execution_deadline_at"] = json!(execution_deadline);
        started["run_deadline_at"] = json!(run_deadline);
        Self::append(
            &mut tx,
            &run_id,
            "WorkflowStarted",
            started,
            None,
            Some(&command_id),
        )
        .await?;
        Self::create_task(&mut tx, &run_id, queue, "workflow", json!({})).await?;
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(
            json!({"workflow_id": workflow_id, "run_id": run_id, "workflow_type": workflow_type,
            "namespace": "default", "status": "running", "payload_codec": "avro", "outcome": "started_new",
            "command_status": "accepted", "command_source": "control_plane", "reason": null, "rejection_reason": null}),
        )
    }

    pub(crate) async fn describe(&self, workflow_id: &str, run_id: Option<&str>) -> Result<Value> {
        let row = Self::query("SELECT r.*,i.execution_timeout_seconds FROM workflow_runs r JOIN workflow_instances i ON i.id=r.workflow_instance_id WHERE i.id=$1 AND r.id=COALESCE($2,i.current_run_id) AND r.namespace='default'")
            .bind(workflow_id).bind(run_id).fetch_optional(&self.pool).await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "instance_not_found"))?;
        let output = DB::optional_string(&row, "output")?;
        let status = DB::string(&row, "status")?;
        Ok(
            json!({"workflow_id": workflow_id, "run_id": DB::string(&row,"id")?, "workflow_type": DB::string(&row,"workflow_type")?,
            "namespace": "default", "task_queue": DB::string(&row,"queue")?, "status": status,
            "status_bucket": if status == "completed" { "closed" } else { "open" },
            "is_terminal": status == "completed", "run_number": DB::number(&row,"run_number")?,
            "payload_codec": "avro", "input": null, "output": null,
            "input_envelope": wire(&DB::string(&row,"arguments")?), "output_envelope": output.as_deref().map(wire),
            "started_at": DB::instant(&row,"started_at")?, "closed_at": DB::optional_instant(&row,"closed_at")?,
            "execution_timeout_seconds": DB::number(&row,"execution_timeout_seconds")?,
            "run_timeout_seconds": DB::number(&row,"run_timeout_seconds")?,
            "execution_deadline_at": DB::instant(&row,"execution_deadline_at")?, "run_deadline_at": DB::instant(&row,"run_deadline_at")?}),
        )
    }

    pub(crate) async fn history(
        &self,
        workflow_id: &str,
        run_id: &str,
        after_sequence: i64,
        page_size: i64,
    ) -> Result<Value> {
        self.describe(workflow_id, Some(run_id)).await?;
        let (events, next) =
            Self::history_page(&self.pool, run_id, after_sequence, page_size).await?;
        let public_events: Vec<Value> = events
            .into_iter()
            .map(|e| {
                json!({"sequence": e["sequence"],
            "event_type": e["event_type"], "timestamp": e["recorded_at"], "payload": e["payload"]})
            })
            .collect();
        Ok(
            json!({"workflow_id": workflow_id, "run_id": run_id, "events": public_events, "next_page_token": next}),
        )
    }

    pub(crate) async fn register(&self, body: Value) -> Result<Value> {
        let worker_id = text(&body, "worker_id")?;
        let queue = text(&body, "task_queue")?;
        for field in ["supported_workflow_types", "supported_activity_types"] {
            if body[field].as_array().is_none_or(|types| {
                types.len() > 1000
                    || types
                        .iter()
                        .any(|v| v.as_str().is_none_or(|s| s.is_empty() || s.len() > 255))
            }) {
                return Err(refuse(
                    StatusCode::UNPROCESSABLE_ENTITY,
                    "invalid_worker_definition",
                ));
            }
        }
        let runtime = text(&body, "runtime")?;
        Self::query(DB::WORKER_REGISTRATION_SQL)
            .bind(worker_id)
            .bind(queue)
            .bind(runtime)
            .bind(body["sdk_version"].as_str())
            .bind(body["build_id"].as_str())
            .bind(DB::document(&body["supported_workflow_types"]))
            .bind(DB::document(&body["supported_activity_types"]))
            .bind(DB::optional_document(&body["capabilities"]))
            .bind(DB::optional_document(&body["capability_manifest"]))
            .bind(DB::bind_time(now()))
            .bind(DB::bind_time(now()))
            .bind(DB::bind_time(now()))
            .execute(&self.pool)
            .await?;
        Ok(
            json!({"worker_id": worker_id, "registered": true, "namespace": "default", "task_queue": queue,
            "runtime": body["runtime"], "build_id": body["build_id"], "heartbeat_interval_seconds": 10,
            "status": "active", "capabilities": body["capabilities"], "capability_manifest": body["capability_manifest"]}),
        )
    }

    pub(crate) async fn worker_heartbeat(&self, body: Value) -> Result<Value> {
        let worker_id = text(&body, "worker_id")?;
        let result = Self::query("UPDATE workflow_worker_registrations SET last_heartbeat_at=$1 WHERE namespace='default' AND worker_id=$2")
            .bind(DB::bind_time(now())).bind(worker_id).execute(&self.pool).await?;
        if DB::affected(result) != 1 {
            return Err(refuse(StatusCode::NOT_FOUND, "worker_not_registered"));
        }
        Ok(
            json!({"worker_id": worker_id, "heartbeat_recorded": true, "heartbeat_interval_seconds": 10}),
        )
    }

    pub(crate) async fn deregister(&self, worker_id: &str) -> Result<Value> {
        let mut tx = self.begin().await?;
        Self::query(
            "DELETE FROM workflow_worker_registrations WHERE namespace='default' AND worker_id=$1",
        )
        .bind(worker_id)
        .execute(&mut *tx)
        .await?;
        // Revocation expires the existing fence; later polling creates a new
        // attempt before work can be accepted from another worker.
        let recovered = DB::affected(Self::query("UPDATE workflow_tasks SET lease_expires_at=$1 WHERE namespace='default' AND lease_owner=$2 AND status='leased'")
            .bind(DB::bind_time(now())).bind(worker_id).execute(&mut *tx).await?);
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(
            json!({"worker_id": worker_id, "outcome": "deregistered", "recovered_workflow_task_count": recovered}),
        )
    }

    pub(crate) async fn poll(&self, body: Value, kind: &'static str) -> Result<Value> {
        text(&body, "worker_id")?;
        text(&body, "task_queue")?;
        let timeout = body
            .get("timeout_seconds")
            .map_or(Some(5), Value::as_u64)
            .filter(|t| *t <= 60)
            .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_poll_timeout"))?;
        let deadline = tokio::time::Instant::now() + Duration::from_secs(timeout);
        if body
            .get("history_page_size")
            .is_some_and(|v| v.as_i64().is_none_or(|n| !(1..=1000).contains(&n)))
        {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "invalid_page_size",
            ));
        }
        loop {
            // Register the notification before probing; periodic probing also
            // observes work committed by an independent process.
            let wake = self.wake.notified();
            if let Some(task) = self.claim(&body, kind).await? {
                return Ok(
                    json!({"task": task, "poll_status": "task", "server_capabilities": capabilities()}),
                );
            }
            if tokio::time::Instant::now() >= deadline {
                return Ok(
                    json!({"task": null, "poll_status": "timeout", "server_capabilities": capabilities()}),
                );
            }
            tokio::select! { _ = wake => {}, _ = tokio::time::sleep(Duration::from_millis(50)) => {} }
        }
    }

    async fn claim(&self, body: &Value, kind: &str) -> Result<Option<Value>> {
        let worker_id = text(body, "worker_id")?;
        let queue = text(body, "task_queue")?;
        let mut tx = self.begin().await?;
        let worker = Self::query("SELECT supported_workflow_types,supported_activity_types,task_queue FROM workflow_worker_registrations WHERE namespace='default' AND worker_id=$1")
            .bind(worker_id).fetch_optional(&mut *tx).await?
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "worker_not_registered"))?;
        if DB::string(&worker, "task_queue")? != queue {
            return Err(refuse(StatusCode::CONFLICT, "task_queue_mismatch"));
        }
        Self::query("DELETE FROM dw_poll_receipts WHERE expires_at<=$1 AND task_id IN (SELECT id FROM workflow_tasks WHERE status!='leased' OR lease_expires_at<=$2)")
            .bind(DB::bind_time(now())).bind(DB::bind_time(now())).execute(&mut *tx).await?;
        let request_id = body
            .get("poll_request_id")
            .map(|_| text(body, "poll_request_id"))
            .transpose()?;
        if let Some(request_id) = request_id
            && let Some(receipt) = Self::query("SELECT p.*,t.status,t.attempt_count,t.lease_owner,t.lease_expires_at FROM dw_poll_receipts p JOIN workflow_tasks t ON t.id=p.task_id WHERE p.namespace='default' AND p.worker_id=$1 AND p.kind=$2 AND p.request_id=$3")
                .bind(worker_id).bind(kind).bind(request_id).fetch_optional(&mut *tx).await? {
                if DB::string(&receipt,"status")? == "leased" && DB::number(&receipt,"attempt")? == DB::number(&receipt,"attempt_count")?
                    && DB::optional_string(&receipt,"lease_owner")?.as_deref() == Some(worker_id)
                    && DB::optional_instant(&receipt,"lease_expires_at")?.is_some_and(|expires| expires > now()) {
                    return Ok(Some(DB::document_row(&receipt,"response")?));
                }
                return Err(refuse(StatusCode::CONFLICT,"poll_cached_task_expired"));
        }
        let types: Value = DB::document_row(
            &worker,
            if kind == "workflow" {
                "supported_workflow_types"
            } else {
                "supported_activity_types"
            },
        )?;
        let candidates = Self::query("SELECT t.*,r.workflow_type FROM workflow_tasks t JOIN workflow_runs r ON r.id=t.workflow_run_id WHERE t.namespace='default' AND t.queue=$1 AND t.task_type=$2 AND r.status IN ('running','waiting') AND (t.status='ready' OR (t.status='leased' AND t.lease_expires_at<=$3)) AND t.available_at<=$4 ORDER BY t.available_at,t.id LIMIT 100")
            .bind(queue).bind(kind).bind(DB::bind_time(now())).bind(DB::bind_time(now())).fetch_all(&mut *tx).await?;
        for task in candidates {
            let run_id = DB::string(&task, "workflow_run_id")?;
            let activity = if kind == "activity" {
                let payload: Value = DB::document_row(&task, "payload")?;
                Self::query("SELECT * FROM activity_executions WHERE id=$1 AND workflow_run_id=$2")
                    .bind(text(&payload, "activity_execution_id")?)
                    .bind(&run_id)
                    .fetch_optional(&mut *tx)
                    .await?
            } else {
                None
            };
            let type_name = activity.as_ref().map_or_else(
                || DB::string(&task, "workflow_type"),
                |a| DB::string(a, "activity_type"),
            )?;
            if !types
                .as_array()
                .is_some_and(|list| list.iter().any(|v| v.as_str() == Some(&type_name)))
            {
                continue;
            }
            let task_id = DB::string(&task, "id")?;
            let attempt = DB::number(&task, "attempt_count")? + 1;
            let expires = after(LEASE_SECONDS);
            Self::query("UPDATE workflow_tasks SET status='leased',lease_owner=$1,lease_expires_at=$2,attempt_count=$3 WHERE id=$4")
                .bind(worker_id).bind(DB::bind_time(expires)).bind(attempt).bind(&task_id).execute(&mut *tx).await?;
            let run = Self::query("SELECT * FROM workflow_runs WHERE id=$1")
                .bind(&run_id)
                .fetch_one(&mut *tx)
                .await?;
            let mut claim = json!({"task_id": task_id, "workflow_id": DB::string(&run,"workflow_instance_id")?,
                "run_id": run_id, "workflow_instance_id": DB::string(&run,"workflow_instance_id")?, "workflow_run_id": run_id,
                "workflow_type": DB::string(&run,"workflow_type")?, "namespace": "default", "payload_codec": "avro",
                "queue": queue, "lease_owner": worker_id, "lease_expires_at": expires});
            if let Some(activity) = activity {
                let activity_id = DB::string(&activity, "id")?;
                let attempt_id = id();
                let started_at = now();
                Self::query("UPDATE activity_attempts SET status='expired',closed_at=$1 WHERE activity_execution_id=$2 AND status='running'")
                    .bind(DB::bind_time(started_at)).bind(&activity_id).execute(&mut *tx).await?;
                Self::query("INSERT INTO activity_attempts(id,activity_execution_id,workflow_run_id,workflow_task_id,attempt_number,status,lease_owner,lease_expires_at,started_at) VALUES ($1,$2,$3,$4,$5,'running',$6,$7,$8)")
                    .bind(&attempt_id).bind(&activity_id).bind(&run_id).bind(&task_id).bind(attempt)
                    .bind(worker_id).bind(DB::bind_time(expires)).bind(DB::bind_time(started_at)).execute(&mut *tx).await?;
                Self::query("UPDATE activity_executions SET status='running' WHERE id=$1")
                    .bind(&activity_id)
                    .execute(&mut *tx)
                    .await?;
                let mut payload = Self::activity_payload(&activity)?;
                payload["activity_attempt_id"] = json!(attempt_id);
                payload["attempt_number"] = json!(attempt);
                Self::append(
                    &mut tx,
                    &run_id,
                    "ActivityStarted",
                    payload,
                    Some(&task_id),
                    None,
                )
                .await?;
                claim["activity_execution_id"] = json!(activity_id);
                claim["activity_attempt_id"] = json!(attempt_id);
                claim["attempt_number"] = json!(attempt);
                claim["activity_type"] = json!(type_name);
                claim["idempotency_key"] = json!(activity_id);
                claim["arguments"] = wire(&DB::string(&activity, "arguments")?);
            } else {
                claim["workflow_task_attempt"] = json!(attempt);
                claim["arguments"] = wire(&DB::string(&run, "arguments")?);
                claim["sticky_replay_mode"] = json!("cold_replay");
                let page_size = body["history_page_size"].as_i64().unwrap_or(100);
                let (events, next) =
                    Self::history_page_connection(&mut tx, &run_id, 0, page_size).await?;
                claim["total_history_events"] = json!(DB::number(&run, "last_history_sequence")?);
                claim["history_events"] = json!(events);
                claim["next_history_page_token"] = json!(next);
            }
            if let Some(request_id) = request_id {
                Self::query("INSERT INTO dw_poll_receipts(namespace,worker_id,kind,request_id,task_id,attempt,response,expires_at) VALUES ('default',$1,$2,$3,$4,$5,$6,$7)")
                    .bind(worker_id).bind(kind).bind(request_id).bind(&task_id).bind(attempt).bind(DB::document(&claim)).bind(DB::bind_time(after(120))).execute(&mut *tx).await?;
            }
            tx.commit().await?;
            return Ok(Some(claim));
        }
        tx.commit().await?;
        Ok(None)
    }

    pub(crate) async fn heartbeat_task(&self, task_id: &str, body: Value) -> Result<Value> {
        let mut tx = self.begin().await?;
        let task = Self::task_row(&mut tx, task_id).await?;
        Self::fence(&task, &body)?;
        let expires = after(LEASE_SECONDS);
        if DB::string(&task, "task_type")? != "workflow" {
            return Err(refuse(StatusCode::NOT_FOUND, "task_not_found"));
        }
        Self::query("UPDATE workflow_tasks SET lease_expires_at=$1 WHERE id=$2")
            .bind(DB::bind_time(expires))
            .bind(task_id)
            .execute(&mut *tx)
            .await?;
        tx.commit().await?;
        Ok(
            json!({"task_id": task_id, "workflow_task_attempt": body["workflow_task_attempt"],
            "lease_owner": body["lease_owner"], "lease_expires_at": expires, "renewed": true}),
        )
    }

    pub(crate) async fn task_history(&self, task_id: &str, body: Value) -> Result<Value> {
        let after = super::http::decode_cursor(body["next_history_page_token"].as_str());
        let mut tx = self.begin().await?;
        let task = Self::task_row(&mut tx, task_id).await?;
        if DB::string(&task, "task_type")? != "workflow" {
            return Err(refuse(StatusCode::NOT_FOUND, "task_not_found"));
        }
        Self::fence(&task, &body)?;
        let run_id = DB::string(&task, "workflow_run_id")?;
        let (events, next) = Self::history_page_connection(&mut tx, &run_id, after, 100).await?;
        tx.commit().await?;
        Ok(
            json!({"task_id":task_id,"workflow_task_attempt":body["workflow_task_attempt"],"history_events":events,"next_history_page_token":next}),
        )
    }

    pub(crate) async fn complete_workflow(&self, task_id: &str, body: Value) -> Result<Value> {
        let commands = body["commands"]
            .as_array()
            .filter(|c| !c.is_empty() && c.len() <= 100)
            .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_commands"))?;
        // Validate the entire batch before mutation. Unknown commands and
        // options cannot be silently accepted as unsupported durable effects.
        for (index, command) in commands.iter().enumerate() {
            match text(command, "type")? {
                "complete_workflow" if index + 1 == commands.len() => {
                    reject_fields(command, &["type", "result"])?;
                    envelope(command, "result")?;
                }
                "schedule_activity" => {
                    reject_fields(command, &["type", "activity_type", "arguments", "queue"])?;
                    text(command, "activity_type")?;
                    envelope(command, "arguments")?;
                }
                _ => {
                    return Err(refuse(
                        StatusCode::UNPROCESSABLE_ENTITY,
                        "unsupported_workflow_command",
                    ));
                }
            }
        }
        let mut tx = self.begin().await?;
        let task = Self::task_row(&mut tx, task_id).await?;
        if DB::string(&task, "task_type")? != "workflow" {
            return Err(refuse(StatusCode::NOT_FOUND, "task_not_found"));
        }
        if Self::duplicate_receipt(&task, &body)? {
            return Ok(
                json!({"task_id": task_id, "workflow_task_attempt": body["workflow_task_attempt"],
                "outcome": "completed", "recorded": false, "reason": "already_completed"}),
            );
        }
        Self::fence(&task, &body)?;
        let run_id = DB::string(&task, "workflow_run_id")?;
        let run = Self::active_run(&mut tx, &run_id).await?;
        let mut sequence = DB::number(&run, "last_command_sequence")?;
        for command in commands {
            if command["type"] == "schedule_activity" {
                sequence += 1;
                let activity_id = id();
                let queue = command
                    .get("queue")
                    .map_or(Ok(DB::string(&run, "queue")?), |_| {
                        text(command, "queue").map(str::to_owned)
                    })?;
                let activity_type = text(command, "activity_type")?;
                let arguments = envelope(command, "arguments")?;
                Self::query("INSERT INTO activity_executions(id,workflow_run_id,sequence,activity_type,activity_class,status,payload_codec,arguments,queue) VALUES ($1,$2,$3,$4,$5,'scheduled','avro',$6,$7)")
                    .bind(&activity_id).bind(&run_id).bind(sequence).bind(activity_type).bind(activity_type)
                    .bind(&arguments).bind(&queue).execute(&mut *tx).await?;
                let activity = Self::query("SELECT * FROM activity_executions WHERE id=$1")
                    .bind(&activity_id)
                    .fetch_one(&mut *tx)
                    .await?;
                Self::append(
                    &mut tx,
                    &run_id,
                    "ActivityScheduled",
                    Self::activity_payload(&activity)?,
                    Some(task_id),
                    None,
                )
                .await?;
                Self::create_task(
                    &mut tx,
                    &run_id,
                    &queue,
                    "activity",
                    json!({"activity_execution_id": activity_id}),
                )
                .await?;
                Self::query(
                    "UPDATE workflow_runs SET status='waiting',last_command_sequence=$1 WHERE id=$2",
                )
                .bind(sequence)
                .bind(&run_id)
                .execute(&mut *tx)
                .await?;
            } else {
                let outstanding: i64 = Self::scalar("SELECT COUNT(*) FROM activity_executions WHERE workflow_run_id=$1 AND status!='completed'")
                    .bind(&run_id).fetch_one(&mut *tx).await?;
                if outstanding != 0 {
                    return Err(refuse(StatusCode::CONFLICT, "pending_durable_operations"));
                }
                let output = envelope(command, "result")?;
                Self::append(
                    &mut tx,
                    &run_id,
                    "WorkflowCompleted",
                    json!({"output": wire(&output), "payload_codec": "avro"}),
                    Some(task_id),
                    None,
                )
                .await?;
                Self::query("UPDATE workflow_runs SET status='completed',closed_reason='completed',output=$1,closed_at=$2 WHERE id=$3")
                    .bind(output).bind(DB::bind_time(now())).bind(&run_id).execute(&mut *tx).await?;
            }
        }
        Self::query("UPDATE workflow_tasks SET status='completed' WHERE id=$1")
            .bind(task_id)
            .execute(&mut *tx)
            .await?;
        Self::record_completion(&mut tx, task_id, &body).await?;
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(
            json!({"task_id": task_id, "workflow_task_attempt": body["workflow_task_attempt"], "outcome": "completed", "recorded": true, "reason": null}),
        )
    }

    pub(crate) async fn complete_activity(&self, task_id: &str, body: Value) -> Result<Value> {
        let result = envelope(&body, "result")?;
        let attempt_id = text(&body, "activity_attempt_id")?;
        let mut tx = self.begin().await?;
        let task = Self::task_row(&mut tx, task_id).await?;
        if DB::string(&task, "task_type")? != "activity" {
            return Err(refuse(StatusCode::NOT_FOUND, "task_not_found"));
        }
        if Self::duplicate_receipt(&task, &body)? {
            return Ok(
                json!({"task_id": task_id, "activity_attempt_id": attempt_id, "outcome": "completed", "recorded": false, "reason": "already_completed"}),
            );
        }
        Self::fence(&task, &body)?;
        let attempt =
            Self::query("SELECT * FROM activity_attempts WHERE id=$1 AND workflow_task_id=$2")
                .bind(attempt_id)
                .bind(task_id)
                .fetch_optional(&mut *tx)
                .await?
                .ok_or_else(|| refuse(StatusCode::CONFLICT, "stale_attempt"))?;
        if DB::string(&attempt, "status")? != "running"
            || DB::number(&attempt, "attempt_number")? != DB::number(&task, "attempt_count")?
        {
            return Err(refuse(StatusCode::CONFLICT, "stale_attempt"));
        }
        let run_id = DB::string(&task, "workflow_run_id")?;
        let run = Self::active_run(&mut tx, &run_id).await?;
        let activity_id = DB::string(&attempt, "activity_execution_id")?;
        let activity = Self::query("SELECT * FROM activity_executions WHERE id=$1")
            .bind(&activity_id)
            .fetch_one(&mut *tx)
            .await?;
        let mut payload = Self::activity_payload(&activity)?;
        payload["activity_attempt_id"] = json!(attempt_id);
        payload["attempt_number"] = json!(DB::number(&attempt, "attempt_number")?);
        payload["result"] = wire(&result);
        Self::append(
            &mut tx,
            &run_id,
            "ActivityCompleted",
            payload,
            Some(task_id),
            None,
        )
        .await?;
        Self::query("UPDATE activity_executions SET status='completed',result=$1 WHERE id=$2")
            .bind(result)
            .bind(&activity_id)
            .execute(&mut *tx)
            .await?;
        Self::query("UPDATE activity_attempts SET status='completed',closed_at=$1 WHERE id=$2")
            .bind(DB::bind_time(now()))
            .bind(attempt_id)
            .execute(&mut *tx)
            .await?;
        Self::query("UPDATE workflow_tasks SET status='completed' WHERE id=$1")
            .bind(task_id)
            .execute(&mut *tx)
            .await?;
        Self::record_completion(&mut tx, task_id, &body).await?;
        let next_task_id = Self::create_task(
            &mut tx,
            &run_id,
            &DB::string(&run, "queue")?,
            "workflow",
            json!({"activity_execution_id": activity_id}),
        )
        .await?;
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(
            json!({"task_id": task_id, "activity_attempt_id": attempt_id, "outcome": "completed", "recorded": true, "reason": null, "next_task_id": next_task_id}),
        )
    }
    async fn task_row(tx: &mut Transaction<'_, DB>, task_id: &str) -> Result<DB::Row> {
        Self::query("SELECT t.*,c.receipt FROM workflow_tasks t LEFT JOIN dw_task_completions c ON c.task_id=t.id WHERE t.id=$1 AND t.namespace='default'")
        .bind(task_id)
        .fetch_optional(&mut **tx)
        .await?
        .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "task_not_found"))
    }

    async fn record_completion(
        tx: &mut Transaction<'_, DB>,
        task_id: &str,
        body: &Value,
    ) -> Result<()> {
        Self::query("INSERT INTO dw_task_completions(task_id,receipt) VALUES ($1,$2)")
            .bind(task_id)
            .bind(DB::document(body))
            .execute(&mut **tx)
            .await?;
        Ok(())
    }

    fn duplicate_receipt(task: &DB::Row, body: &Value) -> Result<bool> {
        if DB::string(task, "status")? != "completed" {
            return Ok(false);
        }
        let receipt: Value = DB::document_row(task, "receipt")?;
        if receipt == *body {
            return Ok(true);
        }
        Err(refuse(StatusCode::CONFLICT, "conflicting_completion"))
    }

    fn fence(task: &DB::Row, body: &Value) -> Result<()> {
        if DB::string(task, "status")? != "leased" {
            return Err(refuse(StatusCode::CONFLICT, "task_not_leased"));
        }
        if DB::optional_string(task, "lease_owner")?.as_deref() != Some(text(body, "lease_owner")?)
        {
            return Err(refuse(StatusCode::CONFLICT, "lease_owner_mismatch"));
        }
        if DB::string(task, "task_type")? == "workflow"
            && body["workflow_task_attempt"].as_i64() != Some(DB::number(task, "attempt_count")?)
        {
            return Err(refuse(
                StatusCode::CONFLICT,
                "workflow_task_attempt_mismatch",
            ));
        }
        if DB::optional_instant(task, "lease_expires_at")?.is_none_or(|expires| expires <= now()) {
            return Err(refuse(StatusCode::CONFLICT, "lease_expired"));
        }
        Ok(())
    }

    async fn active_run(tx: &mut Transaction<'_, DB>, run_id: &str) -> Result<DB::Row> {
        let run = Self::query("SELECT * FROM workflow_runs WHERE id=$1")
            .bind(run_id)
            .fetch_one(&mut **tx)
            .await?;
        if !matches!(DB::string(&run, "status")?.as_str(), "running" | "waiting") {
            return Err(refuse(StatusCode::CONFLICT, "run_closed"));
        }
        if DB::instant(&run, "execution_deadline_at")? <= now()
            || DB::instant(&run, "run_deadline_at")? <= now()
        {
            return Err(refuse(StatusCode::CONFLICT, "run_timed_out"));
        }
        Ok(run)
    }

    async fn create_task(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        queue: &str,
        kind: &str,
        payload: Value,
    ) -> Result<String> {
        let task_id = id();
        Self::query("INSERT INTO workflow_tasks(id,workflow_run_id,namespace,task_type,status,payload,queue,available_at) VALUES ($1,$2,'default',$3,'ready',$4,$5,$6)")
        .bind(&task_id).bind(run_id).bind(kind).bind(DB::document(&payload)).bind(queue).bind(DB::bind_time(now())).execute(&mut **tx).await?;
        Ok(task_id)
    }

    async fn append(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        event_type: &str,
        payload: Value,
        task_id: Option<&str>,
        command_id: Option<&str>,
    ) -> Result<()> {
        // The backend transition lock protects this read and write together.
        // MySQL has no UPDATE RETURNING; all adapters use this same allocation.
        let row = Self::query("SELECT last_history_sequence FROM workflow_runs WHERE id=$1")
            .bind(run_id)
            .fetch_one(&mut **tx)
            .await?;
        let sequence = DB::number(&row, "last_history_sequence")?
            .checked_add(1)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "history_sequence_exhausted"))?;
        Self::query("UPDATE workflow_runs SET last_history_sequence=$1 WHERE id=$2")
            .bind(sequence)
            .bind(run_id)
            .execute(&mut **tx)
            .await?;
        Self::query("INSERT INTO workflow_history_events(id,workflow_run_id,sequence,event_type,payload,workflow_task_id,workflow_command_id,recorded_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8)")
        .bind(id()).bind(run_id).bind(sequence).bind(event_type).bind(DB::document(&payload)).bind(task_id).bind(command_id).bind(DB::bind_time(now())).execute(&mut **tx).await?;
        Ok(())
    }

    fn activity_payload(activity: &DB::Row) -> Result<Value> {
        Ok(
            json!({"activity_execution_id": DB::string(activity,"id")?, "activity_type": DB::string(activity,"activity_type")?,
        "activity_class": DB::string(activity,"activity_class")?, "sequence": DB::number(activity,"sequence")?,
        "activity": {"activity_type": DB::string(activity,"activity_type")?, "arguments": wire(&DB::string(activity,"arguments")?),
            "payload_codec": "avro", "queue": DB::string(activity,"queue")?}}),
        )
    }

    fn event(row: DB::Row) -> Result<Value> {
        Ok(
            json!({"id": DB::string(&row,"id")?, "sequence": DB::number(&row,"sequence")?, "event_type": DB::string(&row,"event_type")?,
        "payload": DB::document_row(&row,"payload")?, "recorded_at": DB::instant(&row,"recorded_at")?,
        "workflow_task_id": DB::optional_string(&row,"workflow_task_id")?, "workflow_command_id": DB::optional_string(&row,"workflow_command_id")?}),
        )
    }

    async fn history_page(
        pool: &Pool<DB>,
        run_id: &str,
        after_sequence: i64,
        page_size: i64,
    ) -> Result<(Vec<Value>, Option<String>)> {
        Self::history_page_connection(
            &mut *pool.acquire().await?,
            run_id,
            after_sequence,
            page_size,
        )
        .await
    }

    async fn history_page_connection(
        connection: &mut DB::Connection,
        run_id: &str,
        after_sequence: i64,
        page_size: i64,
    ) -> Result<(Vec<Value>, Option<String>)> {
        use base64::{Engine, engine::general_purpose::STANDARD};
        // Bound serialized history bytes before hydrating JSON. SQLite executes
        // on SQLx's connection worker, outside Tokio request executor threads.
        let rows = Self::query(DB::HISTORY_PAGE)
            .bind(run_id)
            .bind(after_sequence)
            .bind(page_size)
            .fetch_all(&mut *connection)
            .await?;
        let row = Self::query("SELECT last_history_sequence FROM workflow_runs WHERE id=$1")
            .bind(run_id)
            .fetch_one(connection)
            .await?;
        let total = DB::number(&row, "last_history_sequence")?;
        let last = match rows.last() {
            Some(row) => DB::number(row, "sequence")?,
            None => after_sequence,
        };
        if rows.is_empty() && after_sequence < total {
            return Err(refuse(
                StatusCode::PAYLOAD_TOO_LARGE,
                "history_event_exceeds_page_budget",
            ));
        }
        let next = (last < total).then(|| STANDARD.encode(last.to_string()));
        Ok((
            rows.into_iter().map(Self::event).collect::<Result<_>>()?,
            next,
        ))
    }
}

fn positive_seconds(body: &Value, field: &str, default: i64) -> Result<i64> {
    body.get(field)
        .map_or(Some(default), Value::as_i64)
        .filter(|n| *n > 0 && *n <= 315_360_000)
        .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_timeout"))
}

fn reject_fields(body: &Value, allowed: &[&str]) -> Result<()> {
    if body
        .as_object()
        .is_none_or(|map| map.keys().any(|key| !allowed.contains(&key.as_str())))
    {
        return Err(refuse(
            StatusCode::UNPROCESSABLE_ENTITY,
            "unsupported_request_field",
        ));
    }
    Ok(())
}

pub(crate) fn capabilities() -> Value {
    json!({"supported_workflow_task_commands": ["schedule_activity", "complete_workflow"],
        "workflow_memo_updates": false, "cooperative_cancellation": false, "prepared_local_activities": false,
        "worker_sessions": false, "sticky_execution": false, "local_activities": false, "message_streams": false,
        "workflow_updates": false, "query_tasks": false})
}
