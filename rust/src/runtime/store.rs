use std::{path::Path, sync::Arc, time::Duration};

use axum::http::StatusCode;
use chrono::{SecondsFormat, Utc};
use serde_json::{Value, json};
use sqlx::{
    Connection, Row, Sqlite, SqliteConnection, SqlitePool, Transaction,
    sqlite::{
        SqliteConnectOptions, SqliteJournalMode, SqlitePoolOptions, SqliteRow, SqliteSynchronous,
    },
};
use tokio::sync::Notify;
use ulid::Ulid;

use super::{Result, envelope, refuse, schema, text, wire};

const LEASE_SECONDS: i64 = 30;

#[derive(Clone)]
pub struct Runtime {
    pub(crate) pool: SqlitePool,
    pub(crate) token: Arc<str>,
    wake: Arc<Notify>,
}

fn id() -> String {
    Ulid::new().to_string()
}
fn now() -> String {
    Utc::now().to_rfc3339_opts(SecondsFormat::Millis, true)
}
fn after(seconds: i64) -> String {
    (Utc::now() + chrono::Duration::seconds(seconds)).to_rfc3339_opts(SecondsFormat::Millis, true)
}
fn string(row: &SqliteRow, field: &str) -> String {
    row.get::<String, _>(field)
}

impl Runtime {
    pub async fn open(database: &str, token: String) -> Result<Self> {
        if token.trim().is_empty()
            || database.is_empty()
            || database.contains('?')
            || database.contains(':')
        {
            return Err(refuse(
                StatusCode::BAD_REQUEST,
                "invalid_development_configuration",
            ));
        }
        let path = Path::new(database);
        if !path.exists() {
            std::fs::OpenOptions::new()
                .write(true)
                .create_new(true)
                .open(path)?;
        }
        // Preflight read-only, before enabling WAL or executing any migration.
        // A PHP/unknown database cannot be mutated by this development slice.
        let mut preflight = SqliteConnection::connect_with(
            &SqliteConnectOptions::new()
                .filename(path)
                .read_only(true)
                .foreign_keys(false),
        )
        .await?;
        let tables: Vec<String> = sqlx::query_scalar(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'",
        )
        .fetch_all(&mut preflight)
        .await?;
        let initialized = tables.iter().any(|name| name == "dw_server_schema");
        if initialized {
            schema::verify(&mut preflight).await?;
        } else if !tables.is_empty() {
            return Err(refuse(
                StatusCode::CONFLICT,
                "existing_database_requires_qualified_takeover",
            ));
        }
        preflight.close().await?;
        let pool = SqlitePoolOptions::new()
            .max_connections(4)
            .acquire_timeout(Duration::from_secs(5))
            .connect_with(
                SqliteConnectOptions::new()
                    .filename(path)
                    .create_if_missing(false)
                    .journal_mode(SqliteJournalMode::Wal)
                    .synchronous(SqliteSynchronous::Full)
                    .foreign_keys(true)
                    .busy_timeout(Duration::from_secs(5)),
            )
            .await?;
        if !initialized {
            let mut tx = pool.begin_with("BEGIN IMMEDIATE").await?;
            // Recheck under the write lock so simultaneous fresh-node startup
            // cannot apply the same bootstrap twice.
            let exists: i64 = sqlx::query_scalar(
                "SELECT COUNT(*) FROM sqlite_master WHERE name='dw_server_schema'",
            )
            .fetch_one(&mut *tx)
            .await?;
            if exists == 0 {
                schema::bootstrap(&mut tx).await?;
            }
            tx.commit().await?;
        }
        let runtime = Self {
            pool,
            token: token.into(),
            wake: Arc::new(Notify::new()),
        };
        if !runtime.schema_ready().await {
            return Err(refuse(
                StatusCode::CONFLICT,
                "incomplete_development_schema",
            ));
        }
        Ok(runtime)
    }

    pub(crate) async fn schema_ready(&self) -> bool {
        let marker: std::result::Result<Vec<(String, i64)>, _> =
            sqlx::query_as("SELECT engine,version FROM dw_server_schema")
                .fetch_all(&self.pool)
                .await;
        if !marker.is_ok_and(|rows| rows == [("rust-development".into(), schema::VERSION)]) {
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
            if sqlx::query(query).execute(&self.pool).await.is_err() {
                return false;
            }
        }
        true
    }

    pub async fn close(&self) {
        self.pool.close().await;
    }

    async fn begin(&self) -> Result<Transaction<'static, Sqlite>> {
        Ok(self.pool.begin_with("BEGIN IMMEDIATE").await?)
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
        if let Some(existing) = sqlx::query("SELECT r.* FROM workflow_instances i JOIN workflow_runs r ON r.id=i.current_run_id WHERE i.id=?")
            .bind(workflow_id).fetch_optional(&mut *tx).await? {
            if body["duplicate_policy"] == "use-existing" && matches!(string(&existing, "status").as_str(), "running" | "waiting") {
                return Ok(json!({"workflow_id": workflow_id, "run_id": string(&existing, "id"),
                    "workflow_type": string(&existing, "workflow_type"), "namespace": "default",
                    "status": string(&existing, "status"), "payload_codec": "avro", "outcome": "used_existing_active"}));
            }
            return Err(refuse(StatusCode::CONFLICT, "workflow_id_already_exists"));
        }
        let run_id = id();
        let command_id = id();
        let started_at = now();
        let execution_deadline = after(execution_timeout);
        let run_deadline = after(run_timeout.min(execution_timeout));
        sqlx::query("INSERT INTO workflow_instances(id,namespace,workflow_type,workflow_class,current_run_id,run_count,execution_timeout_seconds,created_at,updated_at,started_at) VALUES (?,'default',?,?,?,1,?,?,?,?)")
            .bind(workflow_id).bind(workflow_type).bind(workflow_type).bind(&run_id).bind(execution_timeout)
            .bind(&started_at).bind(&started_at).bind(&started_at).execute(&mut *tx).await?;
        sqlx::query("INSERT INTO workflow_runs(id,workflow_instance_id,namespace,workflow_type,workflow_class,run_number,status,payload_codec,arguments,queue,run_timeout_seconds,execution_deadline_at,run_deadline_at,started_at,created_at,updated_at) VALUES (?,?,'default',?,?,1,'running','avro',?,?,?,?,?,?,?,?)")
            .bind(&run_id).bind(workflow_id).bind(workflow_type).bind(workflow_type).bind(arguments)
            .bind(queue).bind(run_timeout).bind(&execution_deadline).bind(&run_deadline)
            .bind(&started_at).bind(&started_at).bind(&started_at).execute(&mut *tx).await?;
        let identity = json!({"workflow_instance_id": workflow_id, "workflow_run_id": run_id,
            "workflow_type": workflow_type, "workflow_class": workflow_type, "workflow_command_id": command_id});
        let mut accepted = identity.clone();
        accepted["outcome"] = json!("started_new");
        append(
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
        append(
            &mut tx,
            &run_id,
            "WorkflowStarted",
            started,
            None,
            Some(&command_id),
        )
        .await?;
        create_task(&mut tx, &run_id, queue, "workflow", json!({})).await?;
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(
            json!({"workflow_id": workflow_id, "run_id": run_id, "workflow_type": workflow_type,
            "namespace": "default", "status": "running", "payload_codec": "avro", "outcome": "started_new",
            "command_status": "accepted", "command_source": "control_plane", "reason": null, "rejection_reason": null}),
        )
    }

    pub(crate) async fn describe(&self, workflow_id: &str, run_id: Option<&str>) -> Result<Value> {
        let row = sqlx::query("SELECT r.*,i.execution_timeout_seconds FROM workflow_runs r JOIN workflow_instances i ON i.id=r.workflow_instance_id WHERE i.id=? AND r.id=COALESCE(?,i.current_run_id) AND r.namespace='default'")
            .bind(workflow_id).bind(run_id).fetch_optional(&self.pool).await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "instance_not_found"))?;
        let output: Option<String> = row.get("output");
        let status = string(&row, "status");
        Ok(
            json!({"workflow_id": workflow_id, "run_id": string(&row,"id"), "workflow_type": string(&row,"workflow_type"),
            "namespace": "default", "task_queue": string(&row,"queue"), "status": status,
            "status_bucket": if status == "completed" { "closed" } else { "open" },
            "is_terminal": status == "completed", "run_number": row.get::<i64,_>("run_number"),
            "payload_codec": "avro", "input": null, "output": null,
            "input_envelope": wire(&string(&row,"arguments")), "output_envelope": output.as_deref().map(wire),
            "started_at": string(&row,"started_at"), "closed_at": row.get::<Option<String>,_>("closed_at"),
            "execution_timeout_seconds": row.get::<i64,_>("execution_timeout_seconds"),
            "run_timeout_seconds": row.get::<i64,_>("run_timeout_seconds"),
            "execution_deadline_at": string(&row,"execution_deadline_at"), "run_deadline_at": string(&row,"run_deadline_at")}),
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
        let (events, next) = history_page(&self.pool, run_id, after_sequence, page_size).await?;
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
        sqlx::query("INSERT INTO workflow_worker_registrations(namespace,worker_id,task_queue,runtime,sdk_version,build_id,supported_workflow_types,supported_activity_types,capabilities,capability_manifest,last_heartbeat_at,created_at,updated_at) VALUES ('default',?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(worker_id,namespace) DO UPDATE SET task_queue=excluded.task_queue,runtime=excluded.runtime,sdk_version=excluded.sdk_version,build_id=excluded.build_id,supported_workflow_types=excluded.supported_workflow_types,supported_activity_types=excluded.supported_activity_types,capabilities=excluded.capabilities,capability_manifest=excluded.capability_manifest,last_heartbeat_at=excluded.last_heartbeat_at,updated_at=excluded.updated_at")
            .bind(worker_id).bind(queue).bind(runtime).bind(body["sdk_version"].as_str()).bind(body["build_id"].as_str())
            .bind(body["supported_workflow_types"].to_string()).bind(body["supported_activity_types"].to_string())
            .bind(json_column(&body["capabilities"])).bind(json_column(&body["capability_manifest"]))
            .bind(now()).bind(now()).bind(now()).execute(&self.pool).await?;
        Ok(
            json!({"worker_id": worker_id, "registered": true, "namespace": "default", "task_queue": queue,
            "runtime": body["runtime"], "build_id": body["build_id"], "heartbeat_interval_seconds": 10,
            "status": "active", "capabilities": body["capabilities"], "capability_manifest": body["capability_manifest"]}),
        )
    }

    pub(crate) async fn worker_heartbeat(&self, body: Value) -> Result<Value> {
        let worker_id = text(&body, "worker_id")?;
        let result = sqlx::query("UPDATE workflow_worker_registrations SET last_heartbeat_at=? WHERE namespace='default' AND worker_id=?")
            .bind(now()).bind(worker_id).execute(&self.pool).await?;
        if result.rows_affected() != 1 {
            return Err(refuse(StatusCode::NOT_FOUND, "worker_not_registered"));
        }
        Ok(
            json!({"worker_id": worker_id, "heartbeat_recorded": true, "heartbeat_interval_seconds": 10}),
        )
    }

    pub(crate) async fn deregister(&self, worker_id: &str) -> Result<Value> {
        let mut tx = self.begin().await?;
        sqlx::query("DELETE FROM workflow_worker_registrations WHERE namespace='default' AND worker_id=?")
            .bind(worker_id)
            .execute(&mut *tx)
            .await?;
        // Revocation expires the existing fence; later polling creates a new
        // attempt before work can be accepted from another worker.
        let recovered = sqlx::query("UPDATE workflow_tasks SET lease_expires_at=? WHERE namespace='default' AND lease_owner=? AND status='leased'")
            .bind(now()).bind(worker_id).execute(&mut *tx).await?.rows_affected();
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
        let worker = sqlx::query("SELECT supported_workflow_types,supported_activity_types,task_queue FROM workflow_worker_registrations WHERE namespace='default' AND worker_id=?")
            .bind(worker_id).fetch_optional(&mut *tx).await?
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "worker_not_registered"))?;
        if string(&worker, "task_queue") != queue {
            return Err(refuse(StatusCode::CONFLICT, "task_queue_mismatch"));
        }
        sqlx::query("DELETE FROM dw_poll_receipts WHERE expires_at<=? AND task_id IN (SELECT id FROM workflow_tasks WHERE status!='leased' OR lease_expires_at<=?)")
            .bind(now()).bind(now()).execute(&mut *tx).await?;
        let request_id = body
            .get("poll_request_id")
            .map(|_| text(body, "poll_request_id"))
            .transpose()?;
        if let Some(request_id) = request_id
            && let Some(receipt) = sqlx::query("SELECT p.*,t.status,t.attempt_count,t.lease_owner,t.lease_expires_at FROM dw_poll_receipts p JOIN workflow_tasks t ON t.id=p.task_id WHERE p.namespace='default' AND p.worker_id=? AND p.kind=? AND p.request_id=?")
                .bind(worker_id).bind(kind).bind(request_id).fetch_optional(&mut *tx).await? {
                if string(&receipt,"status") == "leased" && receipt.get::<i64,_>("attempt") == receipt.get::<i64,_>("attempt_count")
                    && receipt.get::<Option<String>,_>("lease_owner").as_deref() == Some(worker_id)
                    && receipt.get::<Option<String>,_>("lease_expires_at").is_some_and(|expires| expires > now()) {
                    return Ok(Some(serde_json::from_str(&string(&receipt,"response"))?));
                }
                return Err(refuse(StatusCode::CONFLICT,"poll_cached_task_expired"));
        }
        let types: Value = serde_json::from_str(&string(&worker, if kind == "workflow" {
            "supported_workflow_types"
        } else {
            "supported_activity_types"
        }))?;
        let candidates = sqlx::query("SELECT t.*,r.workflow_type FROM workflow_tasks t JOIN workflow_runs r ON r.id=t.workflow_run_id WHERE t.namespace='default' AND t.queue=? AND t.task_type=? AND r.status IN ('running','waiting') AND (t.status='ready' OR (t.status='leased' AND t.lease_expires_at<=?)) AND t.available_at<=? ORDER BY t.available_at,t.id LIMIT 100")
            .bind(queue).bind(kind).bind(now()).bind(now()).fetch_all(&mut *tx).await?;
        for task in candidates {
            let run_id = string(&task, "workflow_run_id");
            let activity = if kind == "activity" {
                let payload: Value = serde_json::from_str(&string(&task, "payload"))?;
                sqlx::query("SELECT * FROM activity_executions WHERE id=? AND workflow_run_id=?")
                    .bind(text(&payload, "activity_execution_id")?)
                    .bind(&run_id)
                    .fetch_optional(&mut *tx)
                    .await?
            } else {
                None
            };
            let type_name = activity.as_ref().map_or_else(
                || string(&task, "workflow_type"),
                |a| string(a, "activity_type"),
            );
            if !types
                .as_array()
                .is_some_and(|list| list.iter().any(|v| v.as_str() == Some(&type_name)))
            {
                continue;
            }
            let task_id = string(&task, "id");
            let attempt = task.get::<i64, _>("attempt_count") + 1;
            let expires = after(LEASE_SECONDS);
            sqlx::query("UPDATE workflow_tasks SET status='leased',lease_owner=?,lease_expires_at=?,attempt_count=? WHERE id=?")
                .bind(worker_id).bind(&expires).bind(attempt).bind(&task_id).execute(&mut *tx).await?;
            let run = sqlx::query("SELECT * FROM workflow_runs WHERE id=?")
                .bind(&run_id)
                .fetch_one(&mut *tx)
                .await?;
            let mut claim = json!({"task_id": task_id, "workflow_id": string(&run,"workflow_instance_id"),
                "run_id": run_id, "workflow_instance_id": string(&run,"workflow_instance_id"), "workflow_run_id": run_id,
                "workflow_type": string(&run,"workflow_type"), "namespace": "default", "payload_codec": "avro",
                "queue": queue, "lease_owner": worker_id, "lease_expires_at": expires});
            if let Some(activity) = activity {
                let activity_id = string(&activity, "id");
                let attempt_id = id();
                let started_at = now();
                sqlx::query("UPDATE activity_attempts SET status='expired',closed_at=? WHERE activity_execution_id=? AND status='running'")
                    .bind(&started_at).bind(&activity_id).execute(&mut *tx).await?;
                sqlx::query("INSERT INTO activity_attempts(id,activity_execution_id,workflow_run_id,workflow_task_id,attempt_number,status,lease_owner,lease_expires_at,started_at) VALUES (?,?,?,?,?,'running',?,?,?)")
                    .bind(&attempt_id).bind(&activity_id).bind(&run_id).bind(&task_id).bind(attempt)
                    .bind(worker_id).bind(&expires).bind(&started_at).execute(&mut *tx).await?;
                sqlx::query("UPDATE activity_executions SET status='running' WHERE id=?")
                    .bind(&activity_id)
                    .execute(&mut *tx)
                    .await?;
                let mut payload = activity_payload(&activity);
                payload["activity_attempt_id"] = json!(attempt_id);
                payload["attempt_number"] = json!(attempt);
                append(
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
                claim["arguments"] = wire(&string(&activity, "arguments"));
            } else {
                claim["workflow_task_attempt"] = json!(attempt);
                claim["arguments"] = wire(&string(&run, "arguments"));
                claim["sticky_replay_mode"] = json!("cold_replay");
                let page_size = body["history_page_size"].as_i64().unwrap_or(100);
                let (events, next) =
                    history_page_connection(&mut tx, &run_id, 0, page_size).await?;
                claim["total_history_events"] = json!(run.get::<i64, _>("last_history_sequence"));
                claim["history_events"] = json!(events);
                claim["next_history_page_token"] = json!(next);
            }
            if let Some(request_id) = request_id {
                sqlx::query("INSERT INTO dw_poll_receipts(namespace,worker_id,kind,request_id,task_id,attempt,response,expires_at) VALUES ('default',?,?,?,?,?,?,?)")
                    .bind(worker_id).bind(kind).bind(request_id).bind(&task_id).bind(attempt).bind(claim.to_string()).bind(after(120)).execute(&mut *tx).await?;
            }
            tx.commit().await?;
            return Ok(Some(claim));
        }
        tx.commit().await?;
        Ok(None)
    }

    pub(crate) async fn heartbeat_task(&self, task_id: &str, body: Value) -> Result<Value> {
        let mut tx = self.begin().await?;
        let task = task_row(&mut tx, task_id).await?;
        fence(&task, &body)?;
        let expires = after(LEASE_SECONDS);
        if string(&task, "task_type") != "workflow" {
            return Err(refuse(StatusCode::NOT_FOUND, "task_not_found"));
        }
        sqlx::query("UPDATE workflow_tasks SET lease_expires_at=? WHERE id=?")
            .bind(&expires)
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
        let task = task_row(&mut tx, task_id).await?;
        if string(&task, "task_type") != "workflow" {
            return Err(refuse(StatusCode::NOT_FOUND, "task_not_found"));
        }
        fence(&task, &body)?;
        let run_id = string(&task, "workflow_run_id");
        let (events, next) = history_page_connection(&mut tx, &run_id, after, 100).await?;
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
        let task = task_row(&mut tx, task_id).await?;
        if string(&task, "task_type") != "workflow" {
            return Err(refuse(StatusCode::NOT_FOUND, "task_not_found"));
        }
        if duplicate_receipt(&task, &body)? {
            return Ok(
                json!({"task_id": task_id, "workflow_task_attempt": body["workflow_task_attempt"],
                "outcome": "completed", "recorded": false, "reason": "already_completed"}),
            );
        }
        fence(&task, &body)?;
        let run_id = string(&task, "workflow_run_id");
        let run = active_run(&mut tx, &run_id).await?;
        let mut sequence = run.get::<i64, _>("last_command_sequence");
        for command in commands {
            if command["type"] == "schedule_activity" {
                sequence += 1;
                let activity_id = id();
                let queue = command
                    .get("queue")
                    .map_or(Ok(string(&run, "queue")), |_| {
                        text(command, "queue").map(str::to_owned)
                    })?;
                let activity_type = text(command, "activity_type")?;
                let arguments = envelope(command, "arguments")?;
                sqlx::query("INSERT INTO activity_executions(id,workflow_run_id,sequence,activity_type,activity_class,status,payload_codec,arguments,queue) VALUES (?,?,?,?,?,'scheduled','avro',?,?)")
                    .bind(&activity_id).bind(&run_id).bind(sequence).bind(activity_type).bind(activity_type)
                    .bind(&arguments).bind(&queue).execute(&mut *tx).await?;
                let activity = sqlx::query("SELECT * FROM activity_executions WHERE id=?")
                    .bind(&activity_id)
                    .fetch_one(&mut *tx)
                    .await?;
                append(
                    &mut tx,
                    &run_id,
                    "ActivityScheduled",
                    activity_payload(&activity),
                    Some(task_id),
                    None,
                )
                .await?;
                create_task(
                    &mut tx,
                    &run_id,
                    &queue,
                    "activity",
                    json!({"activity_execution_id": activity_id}),
                )
                .await?;
                sqlx::query(
                    "UPDATE workflow_runs SET status='waiting',last_command_sequence=? WHERE id=?",
                )
                .bind(sequence)
                .bind(&run_id)
                .execute(&mut *tx)
                .await?;
            } else {
                let outstanding: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM activity_executions WHERE workflow_run_id=? AND status!='completed'")
                    .bind(&run_id).fetch_one(&mut *tx).await?;
                if outstanding != 0 {
                    return Err(refuse(StatusCode::CONFLICT, "pending_durable_operations"));
                }
                let output = envelope(command, "result")?;
                append(
                    &mut tx,
                    &run_id,
                    "WorkflowCompleted",
                    json!({"output": wire(&output), "payload_codec": "avro"}),
                    Some(task_id),
                    None,
                )
                .await?;
                sqlx::query("UPDATE workflow_runs SET status='completed',closed_reason='completed',output=?,closed_at=? WHERE id=?")
                    .bind(output).bind(now()).bind(&run_id).execute(&mut *tx).await?;
            }
        }
        sqlx::query("UPDATE workflow_tasks SET status='completed' WHERE id=?")
            .bind(task_id)
            .execute(&mut *tx)
            .await?;
        record_completion(&mut tx, task_id, &body).await?;
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
        let task = task_row(&mut tx, task_id).await?;
        if string(&task, "task_type") != "activity" {
            return Err(refuse(StatusCode::NOT_FOUND, "task_not_found"));
        }
        if duplicate_receipt(&task, &body)? {
            return Ok(
                json!({"task_id": task_id, "activity_attempt_id": attempt_id, "outcome": "completed", "recorded": false, "reason": "already_completed"}),
            );
        }
        fence(&task, &body)?;
        let attempt =
            sqlx::query("SELECT * FROM activity_attempts WHERE id=? AND workflow_task_id=?")
                .bind(attempt_id)
                .bind(task_id)
                .fetch_optional(&mut *tx)
                .await?
                .ok_or_else(|| refuse(StatusCode::CONFLICT, "stale_attempt"))?;
        if string(&attempt, "status") != "running"
            || attempt.get::<i64, _>("attempt_number") != task.get::<i64, _>("attempt_count")
        {
            return Err(refuse(StatusCode::CONFLICT, "stale_attempt"));
        }
        let run_id = string(&task, "workflow_run_id");
        let run = active_run(&mut tx, &run_id).await?;
        let activity_id = string(&attempt, "activity_execution_id");
        let activity = sqlx::query("SELECT * FROM activity_executions WHERE id=?")
            .bind(&activity_id)
            .fetch_one(&mut *tx)
            .await?;
        let mut payload = activity_payload(&activity);
        payload["activity_attempt_id"] = json!(attempt_id);
        payload["attempt_number"] = json!(attempt.get::<i64, _>("attempt_number"));
        payload["result"] = wire(&result);
        append(
            &mut tx,
            &run_id,
            "ActivityCompleted",
            payload,
            Some(task_id),
            None,
        )
        .await?;
        sqlx::query("UPDATE activity_executions SET status='completed',result=? WHERE id=?")
            .bind(result)
            .bind(&activity_id)
            .execute(&mut *tx)
            .await?;
        sqlx::query("UPDATE activity_attempts SET status='completed',closed_at=? WHERE id=?")
            .bind(now())
            .bind(attempt_id)
            .execute(&mut *tx)
            .await?;
        sqlx::query("UPDATE workflow_tasks SET status='completed' WHERE id=?")
            .bind(task_id)
            .execute(&mut *tx)
            .await?;
        record_completion(&mut tx, task_id, &body).await?;
        let next_task_id = create_task(
            &mut tx,
            &run_id,
            &string(&run, "queue"),
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

async fn task_row(tx: &mut Transaction<'_, Sqlite>, task_id: &str) -> Result<SqliteRow> {
    sqlx::query("SELECT t.*,c.receipt FROM workflow_tasks t LEFT JOIN dw_task_completions c ON c.task_id=t.id WHERE t.id=? AND t.namespace='default'")
        .bind(task_id)
        .fetch_optional(&mut **tx)
        .await?
        .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "task_not_found"))
}

fn json_column(value: &Value) -> Option<String> {
    (!value.is_null()).then(|| value.to_string())
}

async fn record_completion(tx: &mut Transaction<'_, Sqlite>, task_id: &str, body: &Value) -> Result<()> {
    sqlx::query("INSERT INTO dw_task_completions(task_id,receipt) VALUES (?,?)")
        .bind(task_id).bind(body.to_string()).execute(&mut **tx).await?;
    Ok(())
}

fn duplicate_receipt(task: &SqliteRow, body: &Value) -> Result<bool> {
    if string(task, "status") != "completed" {
        return Ok(false);
    }
    let receipt: Value = serde_json::from_str(&task.get::<String, _>("receipt"))?;
    if receipt == *body {
        return Ok(true);
    }
    Err(refuse(StatusCode::CONFLICT, "conflicting_completion"))
}

fn fence(task: &SqliteRow, body: &Value) -> Result<()> {
    if string(task, "status") != "leased" {
        return Err(refuse(StatusCode::CONFLICT, "task_not_leased"));
    }
    if task.get::<Option<String>, _>("lease_owner").as_deref() != Some(text(body, "lease_owner")?) {
        return Err(refuse(StatusCode::CONFLICT, "lease_owner_mismatch"));
    }
    if string(task, "task_type") == "workflow"
        && body["workflow_task_attempt"].as_i64() != Some(task.get("attempt_count"))
    {
        return Err(refuse(
            StatusCode::CONFLICT,
            "workflow_task_attempt_mismatch",
        ));
    }
    if task
        .get::<Option<String>, _>("lease_expires_at")
        .is_none_or(|expires| expires <= now())
    {
        return Err(refuse(StatusCode::CONFLICT, "lease_expired"));
    }
    Ok(())
}

async fn active_run(tx: &mut Transaction<'_, Sqlite>, run_id: &str) -> Result<SqliteRow> {
    let run = sqlx::query("SELECT * FROM workflow_runs WHERE id=?")
        .bind(run_id)
        .fetch_one(&mut **tx)
        .await?;
    if !matches!(string(&run, "status").as_str(), "running" | "waiting") {
        return Err(refuse(StatusCode::CONFLICT, "run_closed"));
    }
    if string(&run, "execution_deadline_at") <= now() || string(&run, "run_deadline_at") <= now() {
        return Err(refuse(StatusCode::CONFLICT, "run_timed_out"));
    }
    Ok(run)
}

async fn create_task(
    tx: &mut Transaction<'_, Sqlite>,
    run_id: &str,
    queue: &str,
    kind: &str,
    payload: Value,
) -> Result<String> {
    let task_id = id();
    sqlx::query("INSERT INTO workflow_tasks(id,workflow_run_id,namespace,task_type,status,payload,queue,available_at) VALUES (?,?,'default',?,'ready',?,?,?)")
        .bind(&task_id).bind(run_id).bind(kind).bind(payload.to_string()).bind(queue).bind(now()).execute(&mut **tx).await?;
    Ok(task_id)
}

async fn append(
    tx: &mut Transaction<'_, Sqlite>,
    run_id: &str,
    event_type: &str,
    payload: Value,
    task_id: Option<&str>,
    command_id: Option<&str>,
) -> Result<()> {
    let sequence: i64 = sqlx::query_scalar("UPDATE workflow_runs SET last_history_sequence=last_history_sequence+1 WHERE id=? RETURNING last_history_sequence")
        .bind(run_id).fetch_one(&mut **tx).await?;
    sqlx::query("INSERT INTO workflow_history_events(id,workflow_run_id,sequence,event_type,payload,workflow_task_id,workflow_command_id,recorded_at) VALUES (?,?,?,?,?,?,?,?)")
        .bind(id()).bind(run_id).bind(sequence).bind(event_type).bind(payload.to_string()).bind(task_id).bind(command_id).bind(now()).execute(&mut **tx).await?;
    Ok(())
}

fn activity_payload(activity: &SqliteRow) -> Value {
    json!({"activity_execution_id": string(activity,"id"), "activity_type": string(activity,"activity_type"),
        "activity_class": string(activity,"activity_class"), "sequence": activity.get::<i64,_>("sequence"),
        "activity": {"activity_type": string(activity,"activity_type"), "arguments": wire(&string(activity,"arguments")),
            "payload_codec": "avro", "queue": string(activity,"queue")}})
}

fn event(row: SqliteRow) -> Result<Value> {
    Ok(
        json!({"id": string(&row,"id"), "sequence": row.get::<i64,_>("sequence"), "event_type": string(&row,"event_type"),
        "payload": serde_json::from_str::<Value>(&string(&row,"payload"))?, "recorded_at": string(&row,"recorded_at"),
        "workflow_task_id": row.get::<Option<String>,_>("workflow_task_id"), "workflow_command_id": row.get::<Option<String>,_>("workflow_command_id")}),
    )
}

async fn history_page(
    pool: &SqlitePool,
    run_id: &str,
    after_sequence: i64,
    page_size: i64,
) -> Result<(Vec<Value>, Option<String>)> {
    history_page_connection(
        &mut *pool.acquire().await?,
        run_id,
        after_sequence,
        page_size,
    )
    .await
}

async fn history_page_connection(
    connection: &mut SqliteConnection,
    run_id: &str,
    after_sequence: i64,
    page_size: i64,
) -> Result<(Vec<Value>, Option<String>)> {
    use base64::{Engine, engine::general_purpose::STANDARD};
    // Bound serialized history bytes before hydrating JSON. SQLite executes
    // on SQLx's connection worker, outside Tokio request executor threads.
    let rows = sqlx::query("SELECT * FROM (SELECT *,SUM(length(payload)) OVER (ORDER BY sequence) AS page_bytes FROM workflow_history_events WHERE workflow_run_id=? AND sequence>? ORDER BY sequence LIMIT ?) WHERE page_bytes<=8388608 ORDER BY sequence")
        .bind(run_id).bind(after_sequence).bind(page_size).fetch_all(&mut *connection).await?;
    let total: i64 =
        sqlx::query_scalar("SELECT last_history_sequence FROM workflow_runs WHERE id=?")
            .bind(run_id)
            .fetch_one(connection)
            .await?;
    let last = rows
        .last()
        .map_or(after_sequence, |row| row.get::<i64, _>("sequence"));
    if rows.is_empty() && after_sequence < total {
        return Err(refuse(
            StatusCode::PAYLOAD_TOO_LARGE,
            "history_event_exceeds_page_budget",
        ));
    }
    let next = (last < total).then(|| STANDARD.encode(last.to_string()));
    Ok((rows.into_iter().map(event).collect::<Result<_>>()?, next))
}
