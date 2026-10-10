//! One durable execution state machine for typed database adapters.
use super::{Result, backend::Backend, envelope, refuse, text, wire};
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Pool, Transaction, Type};
use std::{sync::Arc, time::Duration};
use tokio::sync::{Notify, Semaphore};
use ulid::Ulid;

pub(super) struct PreparedControlArguments {
    pub(super) arguments: String,
    pub(super) values: Vec<crate::codec::Value>,
    pub(super) value: String,
    pub(super) command: String,
}
pub(super) const TASK_CANDIDATES_SQL: &str = "SELECT t.*,r.workflow_type FROM workflow_tasks t JOIN workflow_runs r ON r.id=t.workflow_run_id WHERE t.namespace=$1 AND t.queue=$2 AND t.task_type=$3 AND r.status IN ('pending','running','waiting') AND (t.status='ready' OR (t.status='leased' AND t.lease_expires_at<=$4)) AND (t.available_at IS NULL OR t.available_at<=$5) ORDER BY t.available_at,t.id LIMIT 100";
pub(super) const POLL_RECEIPT_CLEANUP_SQL: &str = "DELETE FROM dw_poll_receipts WHERE expires_at<=$1 AND task_id IN (SELECT id FROM workflow_tasks WHERE status!='leased' OR lease_expires_at<=$2)";
pub(super) const WORKER_REGISTRATION_SQL: &str = "INSERT INTO workflow_worker_registrations(namespace,worker_id,task_queue,runtime,sdk_version,build_id,supported_workflow_types,supported_activity_types,capabilities,capability_manifest,last_heartbeat_at,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13) ON CONFLICT(worker_id,namespace) DO UPDATE SET task_queue=excluded.task_queue,runtime=excluded.runtime,sdk_version=excluded.sdk_version,build_id=excluded.build_id,supported_workflow_types=excluded.supported_workflow_types,supported_activity_types=excluded.supported_activity_types,capabilities=excluded.capabilities,capability_manifest=excluded.capability_manifest,last_heartbeat_at=excluded.last_heartbeat_at,updated_at=excluded.updated_at";
pub(super) struct Store<DB: Backend> {
    pub(super) pool: Pool<DB>,
    pub(super) wake: Arc<Notify>,
    pub(super) namespace: Arc<str>,
}
pub(super) fn id() -> String {
    Ulid::new().to_string()
}
pub(super) fn now() -> DateTime<Utc> {
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
    pub(super) fn query(sql: &'static str) -> sqlx::query::Query<'static, DB, DB::Arguments> {
        // Dialect conversion starts from a compiled static template. Runtime
        // values are always encoded by bind(), never interpolated into SQL.
        sqlx::query(sqlx::AssertSqlSafe(DB::statement(sql)))
    }
    pub(super) fn scalar(
        sql: &'static str,
    ) -> sqlx::query::QueryScalar<'static, DB, i64, DB::Arguments> {
        sqlx::query_scalar(sqlx::AssertSqlSafe(DB::statement(sql)))
    }
    pub(super) fn new(pool: Pool<DB>) -> Self {
        Self {
            pool,
            wake: Arc::new(Notify::new()),
            namespace: "default".into(),
        }
    }
    pub(super) fn in_namespace(&self, namespace: &str) -> Self {
        Self {
            pool: self.pool.clone(),
            wake: self.wake.clone(),
            namespace: namespace.into(),
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
            "SELECT id,status,closed_reason,closed_at,last_progress_at,arguments,output,last_history_sequence,last_command_sequence,run_deadline_at FROM workflow_runs LIMIT 0",
            "SELECT id,sequence,payload,recorded_at FROM workflow_history_events LIMIT 0",
            "SELECT id,status,payload,lease_owner,lease_expires_at,attempt_count FROM workflow_tasks LIMIT 0",
            "SELECT id,sequence,activity_type,status,arguments,result,retry_policy,exception,attempt_count,current_attempt_id FROM activity_executions LIMIT 0",
            "SELECT id,workflow_task_id,attempt_number,status FROM activity_attempts LIMIT 0",
            "SELECT id,workflow_run_id,source_kind,source_id,propagation_kind,failure_category,non_retryable,handled,exception_class,message FROM workflow_failures LIMIT 0",
            "SELECT id,workflow_run_id,sequence,status,delay_seconds,fire_at,fired_at FROM workflow_run_timers LIMIT 0",
            "SELECT id,workflow_command_id,workflow_run_id,signal_name,signal_wait_id,status,arguments FROM workflow_signal_records LIMIT 0",
            "SELECT id,workflow_run_id,command_sequence,message_sequence,status,target_scope,rejection_reason,accepted_at,applied_at,rejected_at,payload FROM workflow_commands LIMIT 0",
            "SELECT namespace,worker_id,supported_workflow_types,supported_activity_types FROM workflow_worker_registrations LIMIT 0",
            "SELECT task_id,receipt FROM dw_task_completions LIMIT 0",
            "SELECT request_id,task_id,attempt,response,expires_at FROM dw_poll_receipts LIMIT 0",
            "SELECT value,expiration FROM dw_query_cache LIMIT 0",
            "SELECT id,workflow_command_id,workflow_run_id,update_name,status,arguments,result FROM workflow_updates LIMIT 0",
            "SELECT id,parent_workflow_run_id,sequence,status,resolved_child_run_id,metadata FROM workflow_child_calls LIMIT 0",
            "SELECT id,parent_workflow_run_id,child_workflow_run_id,sequence FROM workflow_links LIMIT 0",
            "SELECT id,schedule_id,namespace,status,spec,action,next_fire_at,remaining_actions,fires_count FROM workflow_schedules LIMIT 0",
            "SELECT workflow_schedule_id,sequence,event_type,payload,occurrence_at_utc FROM workflow_schedule_history_events LIMIT 0",
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
    pub(super) async fn begin(&self) -> Result<Transaction<'static, DB>> {
        DB::begin(&self.pool).await
    }
    pub(crate) async fn start(&self, body: Value) -> Result<Value> {
        let mut tx = self.begin().await?;
        let started = Self::start_in(&mut tx, &body, &self.namespace).await?;
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(started)
    }

    // Schedule admission and its original run/history must share one commit.
    // Existing explicit starts use the same transition and wake after commit.
    pub(super) async fn start_in(
        tx: &mut Transaction<'_, DB>,
        body: &Value,
        namespace: &str,
    ) -> Result<Value> {
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
        if let Some(existing) = Self::query("SELECT r.* FROM workflow_instances i JOIN workflow_runs r ON r.id=i.current_run_id WHERE i.id=$1")
            .bind(workflow_id).fetch_optional(&mut **tx).await? {
            if DB::optional_string(&existing,"namespace")?.as_deref() != Some(namespace) {
                return Err(super::RuntimeError::Protocol {status:StatusCode::CONFLICT,
                    response:json!({"workflow_id":workflow_id,"run_id":null,"reason":"workflow_id_reserved_in_namespace",
                        "outcome":"rejected_workflow_id_reserved_in_namespace","rejection_reason":"workflow_id_reserved_in_namespace",
                        "message":format!("Workflow [{workflow_id}] is already reserved in another namespace.")})});
            }
            if body["duplicate_policy"] == "use-existing" && matches!(DB::string(&existing,"status")?.as_str(), "pending" | "running" | "waiting") {
                return Ok(json!({"workflow_id": workflow_id, "run_id": DB::string(&existing,"id")?,
                    "workflow_type": DB::string(&existing,"workflow_type")?, "namespace": namespace,
                    "status": DB::string(&existing,"status")?, "payload_codec": "avro", "outcome": "used_existing_active"}));
            }
            return Err(refuse(StatusCode::CONFLICT, "workflow_id_already_exists"));
        }
        let run_id = id();
        let command_id = id();
        let started_at = now();
        let execution_deadline = after(execution_timeout);
        let run_deadline = after(run_timeout.min(execution_timeout));
        Self::query("INSERT INTO workflow_instances(id,workflow_type,workflow_class,current_run_id,run_count,execution_timeout_seconds,created_at,updated_at,started_at,namespace) VALUES ($1,$2,$3,$4,1,$5,$6,$7,$8,$9)")
            .bind(workflow_id).bind(workflow_type).bind(workflow_type).bind(&run_id).bind(execution_timeout)
            .bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at)).bind(namespace).execute(&mut **tx).await?;
        Self::query("INSERT INTO workflow_runs(id,workflow_instance_id,workflow_type,workflow_class,run_number,status,payload_codec,arguments,queue,run_timeout_seconds,execution_deadline_at,run_deadline_at,started_at,created_at,updated_at,namespace) VALUES ($1,$2,$3,$4,1,'pending','avro',$5,$6,$7,$8,$9,$10,$11,$12,$13)")
            .bind(&run_id).bind(workflow_id).bind(workflow_type).bind(workflow_type).bind(&arguments)
            .bind(queue).bind(run_timeout).bind(DB::bind_time(execution_deadline)).bind(DB::bind_time(run_deadline))
            .bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at)).bind(namespace).execute(&mut **tx).await?;
        Self::query("INSERT INTO workflow_commands(id,workflow_instance_id,workflow_run_id,resolved_workflow_run_id,command_type,source,status,outcome,workflow_type,payload_codec,payload,command_sequence,accepted_at,applied_at) VALUES ($1,$2,$3,$4,'start','control_plane','accepted','started_new',$5,'avro',$6,1,$7,$8)")
            .bind(&command_id).bind(workflow_id).bind(&run_id).bind(&run_id).bind(workflow_type).bind(&arguments).bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at)).execute(&mut **tx).await?;
        Self::query("UPDATE workflow_runs SET last_command_sequence=1 WHERE id=$1")
            .bind(&run_id)
            .execute(&mut **tx)
            .await?;
        let identity = json!({"workflow_instance_id": workflow_id, "workflow_run_id": run_id,
            "workflow_type": workflow_type, "workflow_class": workflow_type, "workflow_command_id": command_id});
        let mut accepted = identity.clone();
        accepted["outcome"] = json!("started_new");
        Self::append(
            tx,
            &run_id,
            "StartAccepted",
            accepted,
            None,
            Some(&command_id),
        )
        .await?;
        let mut started = identity;
        Self::attach_workflow_contract(tx, queue, workflow_type, &mut started).await?;
        started["execution_timeout_seconds"] = json!(execution_timeout);
        started["run_timeout_seconds"] = json!(run_timeout);
        started["execution_deadline_at"] = json!(execution_deadline);
        started["run_deadline_at"] = json!(run_deadline);
        Self::append(
            tx,
            &run_id,
            "WorkflowStarted",
            started,
            None,
            Some(&command_id),
        )
        .await?;
        Self::create_task(tx, &run_id, queue, "workflow", json!({})).await?;
        Ok(
            json!({"workflow_id": workflow_id, "run_id": run_id, "workflow_type": workflow_type,
            "namespace": namespace, "status": "pending", "payload_codec": "avro", "outcome": "started_new",
            "command_status": "accepted", "command_source": "control_plane", "reason": null, "rejection_reason": null}),
        )
    }

    pub(crate) async fn describe(&self, workflow_id: &str, run_id: Option<&str>) -> Result<Value> {
        let row = Self::query("SELECT r.*,i.execution_timeout_seconds,i.current_run_id,(SELECT COUNT(*) FROM workflow_runs counted WHERE counted.workflow_instance_id=i.id) AS run_count FROM workflow_runs r JOIN workflow_instances i ON i.id=r.workflow_instance_id WHERE i.id=$1 AND r.id=COALESCE($2,i.current_run_id) AND r.namespace=$3")
            .bind(workflow_id).bind(run_id).bind(self.namespace.as_ref()).fetch_optional(&self.pool).await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND, if run_id.is_some() {"run_not_found"} else {"instance_not_found"}))?;
        let output = DB::optional_string(&row, "output")?;
        let status = DB::string(&row, "status")?;
        let terminal = matches!(
            status.as_str(),
            "completed" | "failed" | "cancelled" | "terminated"
        );
        Ok(
            json!({"workflow_id": workflow_id, "run_id": DB::string(&row,"id")?, "workflow_type": DB::string(&row,"workflow_type")?,
            "namespace": self.namespace.as_ref(), "task_queue": DB::string(&row,"queue")?, "status": status,
            "status_bucket": super::visibility::status_bucket(&status)?,
            "is_current_run": DB::optional_string(&row,"current_run_id")?.as_deref() == Some(DB::string(&row,"id")?.as_str()),
            "run_count": DB::number(&row,"run_count")?,
            "is_terminal": terminal, "closed_reason": DB::optional_string(&row,"closed_reason")?, "run_number": DB::number(&row,"run_number")?,
            "payload_codec": "avro", "input": null, "output": null,
            "input_envelope": wire(&DB::string(&row,"arguments")?), "output_envelope": output.as_deref().map(wire),
            "started_at": DB::instant(&row,"started_at")?, "closed_at": DB::optional_instant(&row,"closed_at")?,
            "execution_timeout_seconds": DB::optional_number(&row,"execution_timeout_seconds")?,
            "run_timeout_seconds": DB::optional_number(&row,"run_timeout_seconds")?,
            "execution_deadline_at": DB::optional_instant(&row,"execution_deadline_at")?, "run_deadline_at": DB::optional_instant(&row,"run_deadline_at")?}),
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

    pub(crate) async fn signal(
        &self,
        workflow_id: &str,
        run_id: Option<&str>,
        name: &str,
        body: Value,
        prepared: PreparedControlArguments,
    ) -> Result<Value> {
        reject_fields(&body, &["input", "request_id"])?;
        if name.trim().is_empty() || name.len() > 255 {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "invalid_signal_name",
            ));
        }
        if body.get("request_id").is_some_and(|value| {
            !value.is_null() && value.as_str().is_none_or(|text| text.len() > 255)
        }) {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "invalid_request_id",
            ));
        }
        let mut tx = self.begin().await?;
        let instance = Self::query("SELECT current_run_id,last_message_sequence FROM workflow_instances WHERE id=$1 AND namespace='default'")
            .bind(workflow_id).fetch_optional(&mut *tx).await?.ok_or_else(|| refuse(StatusCode::NOT_FOUND, "instance_not_found"))?;
        let current_run = DB::string(&instance, "current_run_id")?;
        if run_id.is_some_and(|selected| selected != current_run) {
            return Err(refuse(
                StatusCode::CONFLICT,
                "historical_run_command_rejected",
            ));
        }
        let run = Self::active_run(&mut tx, &current_run).await?;
        let started = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='WorkflowStarted' ORDER BY sequence LIMIT 1")
            .bind(&current_run).fetch_one(&mut *tx).await?;
        let contract = DB::document_row(&started, "payload")?;
        if !contract["declared_signals"]
            .as_array()
            .is_some_and(|names| names.iter().any(|value| value == name))
        {
            return Err(refuse(StatusCode::NOT_FOUND, "unknown_signal"));
        }
        validate_signal_arguments(&contract, name, &prepared.values)?;
        let command_id = id();
        let signal_id = id();
        let command_sequence = DB::number(&run, "last_command_sequence")?
            .checked_add(1)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "command_sequence_exhausted"))?;
        let message_sequence = DB::number(&instance, "last_message_sequence")?
            .checked_add(1)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "message_sequence_exhausted"))?;
        let wait = Self::open_wait(&mut tx, &current_run).await?;
        let mut wait_id = format!("signal-command:{command_id}");
        if let Some(wait) = wait.as_ref().filter(|wait| wait["signal_name"] == name) {
            let reserved = Self::scalar("SELECT COUNT(*) FROM workflow_signal_records WHERE workflow_run_id=$1 AND signal_wait_id=$2 AND status='received'")
                .bind(&current_run).bind(text(wait, "signal_wait_id")?).fetch_one(&mut *tx).await?;
            if reserved == 0 {
                wait_id = text(wait, "signal_wait_id")?.to_owned();
            }
        }
        let accepted_at = now();
        Self::query("INSERT INTO workflow_commands(id,workflow_instance_id,workflow_run_id,resolved_workflow_run_id,command_type,source,status,outcome,workflow_type,payload_codec,payload,command_sequence,message_sequence,accepted_at,context) VALUES ($1,$2,$3,$4,'signal','control_plane','accepted','signal_received',$5,'avro',$6,$7,$8,$9,$10)")
            .bind(&command_id).bind(workflow_id).bind(&current_run).bind(&current_run).bind(DB::string(&run, "workflow_type")?)
            .bind(&prepared.command).bind(command_sequence).bind(message_sequence).bind(DB::bind_time(accepted_at))
            .bind(DB::document(&json!({"request":{"request_id":body["request_id"]},"signal_name":name}))).execute(&mut *tx).await?;
        Self::query("INSERT INTO workflow_signal_records(id,workflow_command_id,workflow_instance_id,workflow_run_id,resolved_workflow_run_id,signal_name,signal_wait_id,status,outcome,command_sequence,payload_codec,arguments,received_at) VALUES ($1,$2,$3,$4,$5,$6,$7,'received','signal_received',$8,'avro',$9,$10)")
            .bind(&signal_id).bind(&command_id).bind(workflow_id).bind(&current_run).bind(&current_run).bind(name).bind(&wait_id)
            .bind(command_sequence).bind(&prepared.arguments).bind(DB::bind_time(accepted_at)).execute(&mut *tx).await?;
        Self::query("UPDATE workflow_runs SET last_command_sequence=$1 WHERE id=$2")
            .bind(command_sequence)
            .bind(&current_run)
            .execute(&mut *tx)
            .await?;
        Self::query("UPDATE workflow_instances SET last_message_sequence=$1 WHERE id=$2")
            .bind(message_sequence)
            .bind(workflow_id)
            .execute(&mut *tx)
            .await?;
        Self::append(&mut tx, &current_run, "SignalReceived", json!({"workflow_command_id":command_id,"signal_id":signal_id,"workflow_instance_id":workflow_id,"workflow_run_id":current_run,"signal_name":name,"signal_wait_id":wait_id,"arguments":wire(&prepared.arguments),"payload_codec":"avro", "command":{"id":command_id,"sequence":command_sequence,"message_sequence":message_sequence,"type":"signal","target_scope":"instance","resolved_run_id":current_run,"status":"accepted","outcome":"signal_received"}}), None, Some(&command_id)).await?;
        Self::enqueue_pending_signal(&mut tx, &current_run, &DB::string(&run, "queue")?).await?;
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(
            json!({"workflow_id":workflow_id,"run_id":current_run,"resolved_run_id":current_run,"requested_run_id":null,"signal_name":name,"command_id":command_id,"command_sequence":command_sequence,"command_status":"accepted","command_source":"control_plane","target_scope":"instance","outcome":"signal_received","reason":null,"rejection_reason":null,"validation_errors":[]}),
        )
    }

    pub(super) async fn authored_sequence(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
    ) -> Result<i64> {
        // Public control command order and deterministic authored call order
        // are different counters in PHP. Signals must never shift a replayed
        // activity/timer/wait's authored position.
        let activities = Self::query(
            "SELECT sequence FROM activity_executions WHERE workflow_run_id=$1 ORDER BY sequence DESC LIMIT 1",
        )
        .bind(run_id)
        .fetch_optional(&mut **tx)
        .await?
        .map(|row| DB::number(&row, "sequence"))
        .transpose()?
        .unwrap_or(0);
        let timers = Self::query(
            "SELECT sequence FROM workflow_run_timers WHERE workflow_run_id=$1 ORDER BY sequence DESC LIMIT 1",
        )
        .bind(run_id)
        .fetch_optional(&mut **tx)
        .await?
        .map(|row| DB::number(&row, "sequence"))
        .transpose()?
        .unwrap_or(0);
        let wait = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type IN ('SignalWaitOpened','ConditionWaitOpened') ORDER BY sequence DESC LIMIT 1")
            .bind(run_id).fetch_optional(&mut **tx).await?;
        let wait_sequence = wait
            .map(|row| DB::document_row(&row, "payload"))
            .transpose()?
            .and_then(|value| value["sequence"].as_i64())
            .unwrap_or(0);
        let children = Self::query("SELECT sequence FROM workflow_child_calls WHERE parent_workflow_run_id=$1 ORDER BY sequence DESC LIMIT 1")
            .bind(run_id).fetch_optional(&mut **tx).await?.map(|row| DB::number(&row,"sequence")).transpose()?.unwrap_or(0);
        let run =
            Self::query("SELECT cancellation_delivery_sequence FROM workflow_runs WHERE id=$1")
                .bind(run_id)
                .fetch_one(&mut **tx)
                .await?;
        Ok(activities
            .max(timers)
            .max(wait_sequence)
            .max(children)
            .max(DB::optional_number(&run, "cancellation_delivery_sequence")?.unwrap_or(0)))
    }

    pub(super) async fn open_wait(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
    ) -> Result<Option<Value>> {
        let Some(row) = Self::query("SELECT sequence,event_type,payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type IN ('SignalWaitOpened','ConditionWaitOpened') ORDER BY sequence DESC LIMIT 1")
            .bind(run_id).fetch_optional(&mut **tx).await? else { return Ok(None); };
        let resolution = if DB::string(&row, "event_type")? == "SignalWaitOpened" {
            "SignalApplied"
        } else {
            "ConditionWaitSatisfied"
        };
        if Self::query("SELECT id FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type=$2 AND sequence>$3 LIMIT 1")
            .bind(run_id).bind(resolution).bind(DB::number(&row, "sequence")?).fetch_optional(&mut **tx).await?.is_some() {
            return Ok(None);
        }
        Ok(Some(DB::document_row(&row, "payload")?))
    }

    async fn enqueue_pending_signal(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        queue: &str,
    ) -> Result<()> {
        let status = Self::query("SELECT status FROM workflow_runs WHERE id=$1")
            .bind(run_id)
            .fetch_one(&mut **tx)
            .await?;
        if !matches!(
            DB::string(&status, "status")?.as_str(),
            "pending" | "running" | "waiting"
        ) {
            return Ok(());
        }
        let open = Self::scalar("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status IN ('ready','leased')")
            .bind(run_id).fetch_one(&mut **tx).await?;
        if open != 0 {
            return Ok(());
        }
        let Some(wait) = Self::open_wait(tx, run_id).await? else {
            return Ok(());
        };
        let signals = Self::query("SELECT id,signal_name,signal_wait_id FROM workflow_signal_records WHERE workflow_run_id=$1 AND status='received' ORDER BY command_sequence,id LIMIT 100")
            .bind(run_id).fetch_all(&mut **tx).await?;
        for signal in signals {
            let name = DB::string(&signal, "signal_name")?;
            let signal_wait_id = DB::string(&signal, "signal_wait_id")?;
            if wait.get("signal_name").is_some()
                && (wait["signal_name"] != name || wait["signal_wait_id"] != signal_wait_id)
            {
                continue;
            }
            Self::create_task(tx, run_id, queue, "workflow", json!({"resume_source_kind":"workflow_signal","workflow_signal_id":DB::string(&signal,"id")?,"signal_name":name,"signal_wait_id":signal_wait_id})).await?;
            break;
        }
        Ok(())
    }

    async fn apply_task_signal(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        task_id: &str,
        task: &DB::Row,
        signal_codec: Arc<Semaphore>,
    ) -> Result<()> {
        let payload = DB::document_row(task, "payload")?;
        if payload["resume_source_kind"] != "workflow_signal" {
            return Ok(());
        }
        let signal_id = text(&payload, "workflow_signal_id")?;
        let signal = Self::query("SELECT s.*,c.message_sequence FROM workflow_signal_records s JOIN workflow_commands c ON c.id=s.workflow_command_id WHERE s.id=$1 AND s.workflow_run_id=$2")
            .bind(signal_id).bind(run_id).fetch_one(&mut **tx).await?;
        if DB::string(&signal, "status")? != "received" {
            return Err(refuse(StatusCode::CONFLICT, "signal_already_applied"));
        }
        let Some(wait) = Self::open_wait(tx, run_id).await? else {
            return Err(refuse(StatusCode::CONFLICT, "signal_wait_not_open"));
        };
        let name = DB::string(&signal, "signal_name")?;
        let wait_id = DB::string(&signal, "signal_wait_id")?;
        let direct = wait.get("signal_name").is_some();
        if direct && (wait["signal_name"] != name || wait["signal_wait_id"] != wait_id) {
            return Err(refuse(StatusCode::CONFLICT, "signal_wait_mismatch"));
        }
        let prepared = prepare_control_arguments(
            signal_codec,
            name.clone(),
            DB::string(&signal, "arguments")?,
            "invalid_signal_arguments",
        )
        .await?;
        let command_id = DB::string(&signal, "workflow_command_id")?;
        let applied_at = now();
        Self::query("UPDATE workflow_commands SET applied_at=$1 WHERE id=$2")
            .bind(DB::bind_time(applied_at))
            .bind(&command_id)
            .execute(&mut **tx)
            .await?;
        Self::query("UPDATE workflow_signal_records SET status='applied',workflow_sequence=$1,applied_at=$2,closed_at=$3 WHERE id=$4")
            .bind(wait["sequence"].as_i64().ok_or_else(|| refuse(StatusCode::CONFLICT, "invalid_stored_wait"))?).bind(DB::bind_time(applied_at)).bind(DB::bind_time(applied_at)).bind(signal_id).execute(&mut **tx).await?;
        let run = Self::query(
            "SELECT workflow_instance_id,message_cursor_position FROM workflow_runs WHERE id=$1",
        )
        .bind(run_id)
        .fetch_one(&mut **tx)
        .await?;
        let previous = DB::number(&run, "message_cursor_position")?;
        let position = DB::number(&signal, "message_sequence")?;
        if position <= previous {
            return Err(refuse(
                StatusCode::CONFLICT,
                "signal_message_order_mismatch",
            ));
        }
        Self::query("UPDATE workflow_runs SET message_cursor_position=$1 WHERE id=$2")
            .bind(position)
            .bind(run_id)
            .execute(&mut **tx)
            .await?;
        Self::append(tx, run_id, "MessageCursorAdvanced", json!({"stream_key":format!("instance:{}",DB::string(&run,"workflow_instance_id")?),"previous_position":previous,"new_position":position}), Some(task_id), None).await?;
        let mut applied = json!({"workflow_command_id":command_id,"signal_id":signal_id,"signal_name":name,"signal_wait_id":wait_id,"value":wire(&prepared.value)});
        if direct {
            applied["sequence"] = wait["sequence"].clone();
        }
        Self::append(
            tx,
            run_id,
            "SignalApplied",
            applied,
            Some(task_id),
            Some(&command_id),
        )
        .await?;
        if !direct {
            let mut satisfied = wait;
            satisfied["workflow_signal_id"] = json!(signal_id);
            satisfied["signal_name"] = json!(name);
            satisfied["signal_wait_id"] = json!(wait_id);
            Self::append(
                tx,
                run_id,
                "ConditionWaitSatisfied",
                satisfied,
                Some(task_id),
                None,
            )
            .await?;
        }
        Ok(())
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
        if body
            .get("workflow_command_contracts")
            .is_some_and(|value| !value.is_object())
        {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "invalid_worker_definition",
            ));
        }
        let mut tx = self.begin().await?;
        Self::query(DB::WORKER_REGISTRATION_SQL)
            .bind(self.namespace.as_ref())
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
            .execute(&mut *tx)
            .await?;
        Self::query("UPDATE workflow_worker_registrations SET workflow_command_contracts=$1 WHERE worker_id=$2 AND namespace=$3")
            .bind(DB::document(body.get("workflow_command_contracts").unwrap_or(&json!({})))).bind(worker_id).bind(self.namespace.as_ref()).execute(&mut *tx).await?;
        tx.commit().await?;
        Ok(
            json!({"worker_id": worker_id, "registered": true, "namespace": self.namespace.as_ref(), "task_queue": queue,
            "runtime": body["runtime"], "build_id": body["build_id"], "heartbeat_interval_seconds": 10,
            "status": "active", "capabilities": body["capabilities"], "capability_manifest": body["capability_manifest"]}),
        )
    }

    pub(crate) async fn worker_heartbeat(&self, body: Value) -> Result<Value> {
        let worker_id = text(&body, "worker_id")?;
        let result = Self::query("UPDATE workflow_worker_registrations SET last_heartbeat_at=$1 WHERE worker_id=$2 AND namespace=$3")
            .bind(DB::bind_time(now())).bind(worker_id).bind(self.namespace.as_ref()).execute(&self.pool).await?;
        if DB::affected(result) != 1 {
            return Err(refuse(StatusCode::NOT_FOUND, "worker_not_registered"));
        }
        Ok(
            json!({"worker_id": worker_id, "heartbeat_recorded": true, "acknowledged":true, "heartbeat_interval_seconds": 10}),
        )
    }

    pub(crate) async fn deregister(&self, worker_id: &str) -> Result<Value> {
        let mut tx = self.begin().await?;
        let deleted = Self::query(
            "DELETE FROM workflow_worker_registrations WHERE worker_id=$1 AND namespace=$2",
        )
        .bind(worker_id)
        .bind(self.namespace.as_ref())
        .execute(&mut *tx)
        .await?;
        if DB::affected(deleted) == 0 {
            return Err(refuse(StatusCode::NOT_FOUND, "worker_not_found"));
        }
        // Revocation expires the existing fence; later polling creates a new
        // attempt before work can be accepted from another worker.
        let recovered = DB::affected(Self::query("UPDATE workflow_tasks SET lease_expires_at=$1 WHERE lease_owner=$2 AND namespace=$3 AND status='leased' AND task_type='workflow'")
            .bind(DB::bind_time(now())).bind(worker_id).bind(self.namespace.as_ref()).execute(&mut *tx).await?);
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(
            json!({"worker_id": worker_id, "outcome": "deregistered", "recovered_workflow_task_count": recovered}),
        )
    }

    pub(crate) async fn poll(
        &self,
        body: Value,
        kind: &'static str,
        protocol_version: &str,
    ) -> Result<Value> {
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
            if let Some(task) = self.claim(&body, kind, protocol_version).await? {
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

    async fn claim(
        &self,
        body: &Value,
        kind: &str,
        protocol_version: &str,
    ) -> Result<Option<Value>> {
        let worker_id = text(body, "worker_id")?;
        let queue = text(body, "task_queue")?;
        let mut tx = self.begin().await?;
        let worker = Self::query(
            "SELECT * FROM workflow_worker_registrations WHERE worker_id=$1 AND namespace=$2",
        )
        .bind(worker_id)
        .bind(self.namespace.as_ref())
        .fetch_optional(&mut *tx)
        .await?
        .ok_or_else(|| refuse(StatusCode::CONFLICT, "worker_not_registered"))?;
        if DB::string(&worker, "task_queue")? != queue {
            return Err(refuse(StatusCode::CONFLICT, "task_queue_mismatch"));
        }
        Self::query(DB::POLL_RECEIPT_CLEANUP_SQL)
            .bind(DB::bind_time(now()))
            .bind(DB::bind_time(now()))
            .execute(&mut *tx)
            .await?;
        let request_id = body
            .get("poll_request_id")
            .map(|_| text(body, "poll_request_id"))
            .transpose()?;
        if let Some(request_id) = request_id
            && let Some(receipt) = Self::query("SELECT p.*,t.status,t.attempt_count,t.lease_owner,t.lease_expires_at FROM dw_poll_receipts p JOIN workflow_tasks t ON t.id=p.task_id WHERE p.worker_id=$1 AND p.kind=$2 AND p.request_id=$3 AND p.namespace=$4")
                .bind(worker_id).bind(kind).bind(request_id).bind(self.namespace.as_ref()).fetch_optional(&mut *tx).await? {
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
        let candidates = Self::query(DB::TASK_CANDIDATES_SQL)
            .bind(self.namespace.as_ref())
            .bind(queue)
            .bind(kind)
            .bind(DB::bind_time(now()))
            .bind(DB::bind_time(now()))
            .fetch_all(&mut *tx)
            .await?;
        for task in candidates {
            // Unsupported stored timestamp shapes fail before leasing work.
            // Takeover preflight must validate all records, including rows
            // that are not eligible for this poll's queue/type/deadline.
            DB::optional_instant(&task, "available_at")?;
            DB::optional_instant(&task, "lease_expires_at")?;
            let run_id = DB::string(&task, "workflow_run_id")?;
            let mut task_payload = DB::document_row(&task, "payload")?;
            let run = Self::query("SELECT * FROM workflow_runs WHERE id=$1")
                .bind(&run_id)
                .fetch_one(&mut *tx)
                .await?;
            let cancellation = Self::cancellation_request(&run)?;
            if cancellation.is_some()
                && (DB::optional_instant(&run, "cancellation_deadline_at")?
                    .is_none_or(|deadline| deadline <= now())
                    || kind == "workflow"
                        && (!super::cooperative::supports(
                            &DB::optional_document_row(&worker, "capabilities")?
                                .unwrap_or(Value::Null),
                            protocol_version,
                        ) || DB::string(&worker, "status")? != "active"
                            || DB::instant(&worker, "last_heartbeat_at")?
                                <= now() - chrono::Duration::seconds(60)))
            {
                continue;
            }
            if kind == "workflow"
                && let Some(update_id) = task_payload["workflow_update_id"].as_str()
            {
                let update = Self::query("SELECT update_name FROM workflow_updates WHERE id=$1 AND workflow_run_id=$2 AND status='accepted'")
                    .bind(update_id).bind(&run_id).fetch_optional(&mut *tx).await?;
                let Some(update) = update else {
                    continue;
                };
                if !Self::update_worker_supported(
                    &worker,
                    &DB::string(&task, "workflow_type")?,
                    &DB::string(&update, "update_name")?,
                )? {
                    continue;
                }
            }
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
            let attempt = DB::number(&task, "attempt_count")?
                .checked_add(1)
                .ok_or_else(|| refuse(StatusCode::CONFLICT, "task_attempt_exhausted"))?;
            let expires = Self::cancellation_lease_expiry(&run)?;
            let leased_at = now();
            if kind == "workflow" {
                task_payload[super::cooperative::CLAIM_KEY] = json!({"registration_id":DB::number(&worker,"id")?,
                    "worker_id":worker_id,"namespace":self.namespace.as_ref(),"protocol_version":protocol_version,
                    "capabilities":DB::optional_document_row(&worker,"capabilities")?.unwrap_or(json!([])),
                    "updated_at":DB::instant(&worker,"updated_at")?,"last_heartbeat_at":DB::instant(&worker,"last_heartbeat_at")?,
                    "task_id":task_id,"run_id":run_id,"lease_owner":worker_id,"attempt":attempt});
            }
            Self::query("UPDATE workflow_tasks SET status='leased',lease_owner=$1,lease_expires_at=$2,attempt_count=$3,leased_at=$4,payload=$5 WHERE id=$6")
                .bind(worker_id).bind(DB::bind_time(expires)).bind(attempt).bind(DB::bind_time(leased_at))
                .bind(DB::document(&task_payload)).bind(&task_id).execute(&mut *tx).await?;
            // A lease is worker authority, not an authored lifecycle commit.
            // Published PHP keeps the admitted run pending until commands
            // change its state; the poll and description must agree.
            let mut claim = json!({"task_id": task_id, "workflow_id": DB::string(&run,"workflow_instance_id")?,
                "run_id": run_id, "workflow_instance_id": DB::string(&run,"workflow_instance_id")?, "workflow_run_id": run_id,
                "workflow_type": DB::string(&run,"workflow_type")?, "namespace": self.namespace.as_ref(), "payload_codec": "avro",
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
                Self::query("UPDATE activity_executions SET status='running',attempt_count=$1,current_attempt_id=$2,started_at=$3,last_heartbeat_at=NULL WHERE id=$4")
                    .bind(attempt).bind(&attempt_id).bind(DB::bind_time(started_at)).bind(&activity_id)
                    .execute(&mut *tx)
                    .await?;
                let activity = Self::query("SELECT * FROM activity_executions WHERE id=$1")
                    .bind(&activity_id)
                    .fetch_one(&mut *tx)
                    .await?;
                let mut payload = Self::activity_payload(&activity)?;
                payload["activity_attempt_id"] = json!(attempt_id);
                payload["attempt_number"] = json!(attempt);
                payload["task"] = json!({"id":task_id,"type":"activity","status":"leased",
                    "available_at":DB::optional_instant(&task,"available_at")?,"leased_at":leased_at,
                    "lease_owner":worker_id,"lease_expires_at":expires,"attempt_count":attempt,"queue":queue});
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
                claim["retry_policy"] =
                    DB::optional_document_row(&activity, "retry_policy")?.unwrap_or(Value::Null);
            } else {
                claim["workflow_task_attempt"] = json!(attempt);
                claim["arguments"] = wire(&DB::string(&run, "arguments")?);
                if let Some(update_id) = task_payload["workflow_update_id"].as_str() {
                    claim["workflow_update_id"] = json!(update_id);
                }
                claim["sticky_replay_mode"] = json!("cold_replay");
                if let Some(cancellation) = cancellation {
                    claim["cancellation_request"] = cancellation;
                }
                for field in [
                    "workflow_wait_kind",
                    "child_call_id",
                    "child_workflow_run_id",
                    "resume_source_kind",
                    "resume_source_id",
                    "workflow_sequence",
                    "workflow_event_type",
                    "open_wait_id",
                ] {
                    if let Some(value) = task_payload.get(field) {
                        claim[field] = value.clone();
                    }
                }
                let page_size = body["history_page_size"].as_i64().unwrap_or(100);
                let (events, next) =
                    Self::history_page_connection(&mut tx, &run_id, 0, page_size).await?;
                claim["total_history_events"] = json!(DB::number(&run, "last_history_sequence")?);
                claim["history_events"] = json!(events);
                claim["next_history_page_token"] = json!(next);
            }
            if let Some(request_id) = request_id {
                Self::query("INSERT INTO dw_poll_receipts(worker_id,kind,request_id,task_id,attempt,response,expires_at,namespace) VALUES ($1,$2,$3,$4,$5,$6,$7,$8)")
                    .bind(worker_id).bind(kind).bind(request_id).bind(&task_id).bind(attempt).bind(DB::document(&claim)).bind(DB::bind_time(after(120))).bind(self.namespace.as_ref()).execute(&mut *tx).await?;
            }
            tx.commit().await?;
            return Ok(Some(claim));
        }
        tx.commit().await?;
        Ok(None)
    }

    pub(crate) async fn heartbeat_task(&self, task_id: &str, body: Value) -> Result<Value> {
        let mut tx = self.begin().await?;
        let task = self.task_row(&mut tx, task_id).await?;
        Self::fence(&task, &body)?;
        if DB::string(&task, "task_type")? != "workflow" {
            return Err(refuse(StatusCode::NOT_FOUND, "task_not_found"));
        }
        let run = Self::active_run(&mut tx, &DB::string(&task, "workflow_run_id")?).await?;
        let expires = Self::cancellation_lease_expiry(&run)?;
        Self::query("UPDATE workflow_tasks SET lease_expires_at=$1 WHERE id=$2")
            .bind(DB::bind_time(expires))
            .bind(task_id)
            .execute(&mut *tx)
            .await?;
        tx.commit().await?;
        let mut response = json!({"task_id": task_id, "workflow_task_attempt": body["workflow_task_attempt"],
            "lease_owner": body["lease_owner"], "lease_expires_at": expires, "renewed": true});
        if let Some(cancellation) = Self::cancellation_request(&run)? {
            response["cancellation_request"] = cancellation;
        }
        Ok(response)
    }

    pub(crate) async fn task_history(&self, task_id: &str, body: Value) -> Result<Value> {
        let after = super::http::decode_cursor(body["next_history_page_token"].as_str());
        let mut tx = self.begin().await?;
        let task = self.task_row(&mut tx, task_id).await?;
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

    pub(crate) async fn complete_workflow(
        &self,
        task_id: &str,
        body: Value,
        signal_codec: Arc<Semaphore>,
    ) -> Result<Value> {
        let commands = body["commands"]
            .as_array()
            .filter(|c| !c.is_empty() && c.len() <= 100)
            .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_commands"))?;
        // Validate the entire batch before mutation. Unknown commands and
        // options cannot be silently accepted as unsupported durable effects.
        for (index, command) in commands.iter().enumerate() {
            if self.namespace.as_ref() != "default"
                && !matches!(
                    text(command, "type")?,
                    "complete_workflow" | "schedule_activity"
                )
            {
                return Err(refuse(
                    StatusCode::UNPROCESSABLE_ENTITY,
                    "development_namespace_command_unqualified",
                ));
            }
            match text(command, "type")? {
                "complete_workflow" if index + 1 == commands.len() => {
                    reject_fields(command, &["type", "result"])?;
                    envelope(command, "result")?;
                }
                "schedule_activity" => {
                    reject_fields(
                        command,
                        &[
                            "type",
                            "activity_type",
                            "arguments",
                            "queue",
                            "retry_policy",
                        ],
                    )?;
                    text(command, "activity_type")?;
                    envelope(command, "arguments")?;
                    super::activity_failures::retry_policy(command)?;
                }
                "start_child_workflow" if commands.len() == 1 => {
                    super::children::validate_child(command)?;
                }
                "start_timer" => {
                    reject_fields(command, &["type", "delay_seconds"])?;
                    timer_deadline(command)?;
                }
                "open_condition_wait" => {
                    reject_fields(
                        command,
                        &["type", "condition_key", "condition_definition_fingerprint"],
                    )?;
                    text(command, "condition_key")?;
                    let fingerprint = text(command, "condition_definition_fingerprint")?;
                    if !fingerprint.strip_prefix("sha256:").is_some_and(|hash| {
                        hash.len() == 64 && hash.bytes().all(|byte| byte.is_ascii_hexdigit())
                    }) {
                        return Err(refuse(
                            StatusCode::UNPROCESSABLE_ENTITY,
                            "invalid_condition_fingerprint",
                        ));
                    }
                }
                "open_signal_wait" => {
                    reject_fields(command, &["type", "signal_name"])?;
                    text(command, "signal_name")?;
                }
                "complete_update" if commands.len() == 1 => {
                    reject_fields(command, &["type", "update_id", "result"])?;
                    text(command, "update_id")?;
                    envelope(command, "result")?;
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
        let task = self.task_row(&mut tx, task_id).await?;
        if DB::string(&task, "task_type")? != "workflow" {
            return Err(refuse(StatusCode::NOT_FOUND, "task_not_found"));
        }
        if Self::duplicate_receipt(&task, &body)? {
            let run_id = DB::string(&task, "workflow_run_id")?;
            let current = Self::query("SELECT status FROM workflow_runs WHERE id=$1")
                .bind(&run_id)
                .fetch_one(&mut *tx)
                .await?;
            return Ok(
                json!({"task_id": task_id, "run_id": run_id, "run_status": DB::string(&current,"status")?, "workflow_task_attempt": body["workflow_task_attempt"],
                "outcome": "completed", "recorded": false, "reason": "already_completed"}),
            );
        }
        Self::fence(&task, &body)?;
        let run_id = DB::string(&task, "workflow_run_id")?;
        let run = Self::active_run(&mut tx, &run_id).await?;
        let task_payload = DB::document_row(&task, "payload")?;
        if Self::cancellation_request(&run)?.is_some()
            && commands.iter().any(|command| {
                !matches!(
                    command["type"].as_str(),
                    Some("schedule_activity" | "start_timer" | "complete_workflow")
                )
            })
        {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "cancellation_cleanup_command_not_available",
            ));
        }
        let update_id = task_payload["workflow_update_id"].as_str();
        if commands
            .iter()
            .any(|command| command["type"] == "complete_update")
            != update_id.is_some()
            || update_id.is_some_and(|update_id| {
                commands.len() != 1 || commands[0]["update_id"] != update_id
            })
        {
            return Err(refuse(StatusCode::CONFLICT, "update_task_mismatch"));
        }
        let mut sequence = Self::authored_sequence(&mut tx, &run_id).await?;
        Self::apply_task_signal(&mut tx, &run_id, task_id, &task, signal_codec).await?;
        for command in commands {
            if command["type"] == "complete_update" {
                Self::apply_update(&mut tx, &run, &task, command, sequence + 1).await?;
            } else if command["type"] == "start_child_workflow" {
                sequence += 1;
                Self::start_child(&mut tx, &run, task_id, command, sequence).await?;
            } else if command["type"] == "schedule_activity" {
                sequence += 1;
                let activity_id = id();
                let queue = command
                    .get("queue")
                    .map_or(Ok(DB::string(&run, "queue")?), |_| {
                        text(command, "queue").map(str::to_owned)
                    })?;
                let activity_type = text(command, "activity_type")?;
                let arguments = envelope(command, "arguments")?;
                let policy = super::activity_failures::retry_policy(command)?;
                Self::query("INSERT INTO activity_executions(id,workflow_run_id,sequence,activity_type,activity_class,status,payload_codec,arguments,queue,retry_policy) VALUES ($1,$2,$3,$4,$5,'pending','avro',$6,$7,$8)")
                    .bind(&activity_id).bind(&run_id).bind(sequence).bind(activity_type).bind(activity_type)
                    .bind(&arguments).bind(&queue).bind(policy.as_ref().map(DB::document)).execute(&mut *tx).await?;
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
                Self::query("UPDATE workflow_runs SET status='waiting' WHERE id=$1")
                    .bind(&run_id)
                    .execute(&mut *tx)
                    .await?;
            } else if command["type"] == "start_timer" {
                sequence += 1;
                let timer_id = id();
                let delay = command["delay_seconds"].as_i64().unwrap();
                let fire_at = timer_deadline(command)?;
                Self::query("INSERT INTO workflow_run_timers(id,workflow_run_id,sequence,status,delay_seconds,fire_at) VALUES ($1,$2,$3,'pending',$4,$5)")
                    .bind(&timer_id).bind(&run_id).bind(sequence).bind(delay).bind(DB::bind_time(fire_at)).execute(&mut *tx).await?;
                Self::append(&mut tx, &run_id, "TimerScheduled", json!({"timer_id":timer_id,"sequence":sequence,"delay_seconds":delay,"fire_at":fire_at,
                    "task":{"id":task_id,"type":"workflow","status":"leased","lease_owner":DB::optional_string(&task,"lease_owner")?,
                        "lease_expires_at":DB::optional_instant(&task,"lease_expires_at")?,"attempt_count":DB::number(&task,"attempt_count")?}}), Some(task_id), None).await?;
                let timer_task = Self::create_task(
                    &mut tx,
                    &run_id,
                    &DB::string(&run, "queue")?,
                    "timer",
                    json!({"timer_id":timer_id}),
                )
                .await?;
                Self::query("UPDATE workflow_tasks SET available_at=$1 WHERE id=$2")
                    .bind(DB::bind_time(fire_at))
                    .bind(timer_task)
                    .execute(&mut *tx)
                    .await?;
                Self::query("UPDATE workflow_runs SET status='waiting' WHERE id=$1")
                    .bind(&run_id)
                    .execute(&mut *tx)
                    .await?;
            } else if command["type"] == "open_condition_wait"
                || command["type"] == "open_signal_wait"
            {
                sequence += 1;
                if Self::open_wait(&mut tx, &run_id).await?.is_some() {
                    return Err(refuse(StatusCode::CONFLICT, "pending_durable_operations"));
                }
                let direct = command["type"] == "open_signal_wait";
                let mut payload = command.clone();
                payload.as_object_mut().unwrap().remove("type");
                let wait_id = if direct {
                    let pending = Self::query("SELECT signal_wait_id FROM workflow_signal_records WHERE workflow_run_id=$1 AND signal_name=$2 AND status='received' ORDER BY command_sequence,id LIMIT 1")
                        .bind(&run_id).bind(text(command, "signal_name")?).fetch_optional(&mut *tx).await?;
                    pending
                        .as_ref()
                        .map(|row| DB::string(row, "signal_wait_id"))
                        .transpose()?
                        .unwrap_or_else(id)
                } else {
                    id()
                };
                payload[if direct {
                    "signal_wait_id"
                } else {
                    "condition_wait_id"
                }] = json!(wait_id);
                payload["sequence"] = json!(sequence);
                Self::append(
                    &mut tx,
                    &run_id,
                    if direct {
                        "SignalWaitOpened"
                    } else {
                        "ConditionWaitOpened"
                    },
                    payload,
                    Some(task_id),
                    None,
                )
                .await?;
                Self::query("UPDATE workflow_runs SET status='waiting' WHERE id=$1")
                    .bind(&run_id)
                    .execute(&mut *tx)
                    .await?;
            } else {
                let outstanding: i64 = Self::scalar("SELECT COUNT(*) FROM activity_executions WHERE workflow_run_id=$1 AND status NOT IN ('completed','failed')")
                    .bind(&run_id).fetch_one(&mut *tx).await?;
                let timers: i64 = Self::scalar("SELECT COUNT(*) FROM workflow_run_timers WHERE workflow_run_id=$1 AND status='pending'")
                    .bind(&run_id).fetch_one(&mut *tx).await?;
                let children = Self::scalar("SELECT COUNT(*) FROM workflow_child_calls WHERE parent_workflow_run_id=$1 AND status NOT IN ('completed','cancelled')")
                    .bind(&run_id).fetch_one(&mut *tx).await?;
                if outstanding != 0
                    || timers != 0
                    || children != 0
                    || Self::open_wait(&mut tx, &run_id).await?.is_some()
                {
                    return Err(refuse(StatusCode::CONFLICT, "pending_durable_operations"));
                }
                let output = envelope(command, "result")?;
                let closed_at = now();
                if Self::cancellation_request(&run)?.is_some() {
                    Self::finish_root_cancellation(&mut tx, &run, Some(task_id)).await?;
                } else {
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
                    .bind(&output).bind(DB::bind_time(closed_at)).bind(&run_id).execute(&mut *tx).await?;
                    Self::complete_child(&mut tx, &run, &output, closed_at).await?;
                }
            }
        }
        Self::query("UPDATE workflow_tasks SET status='completed' WHERE id=$1")
            .bind(task_id)
            .execute(&mut *tx)
            .await?;
        Self::record_completion(&mut tx, task_id, &body).await?;
        Self::resume_undelivered_cancellation(&mut tx, &run_id, &DB::string(&run, "queue")?)
            .await?;
        Self::enqueue_pending_signal(&mut tx, &run_id, &DB::string(&run, "queue")?).await?;
        let current = Self::query("SELECT status FROM workflow_runs WHERE id=$1")
            .bind(&run_id)
            .fetch_one(&mut *tx)
            .await?;
        let run_status = DB::string(&current, "status")?;
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(
            json!({"task_id": task_id, "run_id": run_id, "run_status": run_status, "workflow_task_attempt": body["workflow_task_attempt"], "outcome": "completed", "recorded": true, "reason": null}),
        )
    }

    pub(crate) async fn complete_activity(&self, task_id: &str, body: Value) -> Result<Value> {
        let result = envelope(&body, "result")?;
        let attempt_id = text(&body, "activity_attempt_id")?;
        let mut tx = self.begin().await?;
        let task = self.task_row(&mut tx, task_id).await?;
        if DB::string(&task, "task_type")? != "activity" {
            return Err(refuse(StatusCode::NOT_FOUND, "task_not_found"));
        }
        Self::cancelled_activity_outcome(&mut tx, &task, &body).await?;
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
        let activity =
            Self::query("SELECT * FROM activity_executions WHERE id=$1 AND workflow_run_id=$2")
                .bind(&activity_id)
                .bind(&run_id)
                .fetch_one(&mut *tx)
                .await?;
        if DB::optional_string(&activity, "current_attempt_id")?.as_deref() != Some(attempt_id)
            || DB::number(&activity, "attempt_count")? != DB::number(&attempt, "attempt_number")?
            || DB::string(&activity, "status")? != "running"
        {
            return Err(refuse(StatusCode::CONFLICT, "stale_attempt"));
        }
        Self::query("UPDATE activity_executions SET status='completed',result=$1,exception=NULL,closed_at=$2 WHERE id=$3")
            .bind(&result).bind(DB::bind_time(now())).bind(&activity_id).execute(&mut *tx).await?;
        let activity = Self::query("SELECT * FROM activity_executions WHERE id=$1")
            .bind(&activity_id)
            .fetch_one(&mut *tx)
            .await?;
        let mut payload = Self::activity_payload(&activity)?;
        payload["activity_attempt_id"] = json!(attempt_id);
        payload["attempt_number"] = json!(DB::number(&attempt, "attempt_number")?);
        payload["result"] = wire(&result);
        payload["task"] = json!({"id":task_id,"type":"activity","status":"leased",
            "available_at":DB::optional_instant(&task,"available_at")?,"leased_at":DB::optional_instant(&task,"leased_at")?,
            "lease_owner":DB::optional_string(&task,"lease_owner")?,"attempt_count":DB::number(&task,"attempt_count")?});
        Self::append(
            &mut tx,
            &run_id,
            "ActivityCompleted",
            payload,
            Some(task_id),
            None,
        )
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
    pub(crate) async fn fire_due_timers(&self) -> Result<()> {
        // Expired root authority wins before any ordinary due timer fires.
        self.expire_cooperative_deadlines().await?;
        // New timers use the canonical PHP timestamp shape on SQLite and typed
        // UTC values elsewhere. Existing PHP data still cannot be adopted.
        // The read probe avoids acquiring the transition lock when idle.
        const DUE: &str = "SELECT t.*,r.queue FROM workflow_run_timers t JOIN workflow_runs r ON r.id=t.workflow_run_id WHERE r.namespace='default' AND r.status IN ('running','waiting') AND t.status='pending' AND t.fire_at<=$1 ORDER BY t.fire_at,t.id LIMIT 50";
        if Self::query(DUE)
            .bind(DB::bind_time(now()))
            .fetch_optional(&self.pool)
            .await?
            .is_none()
        {
            return Ok(());
        }
        let mut tx = self.begin().await?;
        // Re-read under the same durable transition lock as worker completion.
        // Another node may already have fired any candidate from the probe.
        let due = Self::query(DUE)
            .bind(DB::bind_time(now()))
            .fetch_all(&mut *tx)
            .await?;
        for timer in due {
            let fire_at = DB::instant(&timer, "fire_at")?;
            let fired_at = now();
            if fire_at > fired_at {
                continue;
            }
            let run_id = DB::string(&timer, "workflow_run_id")?;
            match Self::active_run(&mut tx, &run_id).await {
                Ok(_) => {}
                Err(super::RuntimeError::Refused {
                    reason: "run_timed_out" | "cancellation_deadline_expired",
                    ..
                }) => continue,
                Err(error) => return Err(error),
            }
            let timer_id = DB::string(&timer, "id")?;
            let task = Self::query(DB::TIMER_TASK_SQL)
                .bind(&run_id)
                .bind(&timer_id)
                .fetch_one(&mut *tx)
                .await?;
            let task_id = DB::string(&task, "id")?;
            Self::append(&mut tx, &run_id, "TimerFired", json!({"timer_id":timer_id,"sequence":DB::number(&timer,"sequence")?,"delay_seconds":DB::number(&timer,"delay_seconds")?,"fire_at":fire_at,"fired_at":fired_at}),Some(&task_id),None).await?;
            Self::query("UPDATE workflow_run_timers SET status='fired',fired_at=$1 WHERE id=$2")
                .bind(DB::bind_time(fired_at))
                .bind(&timer_id)
                .execute(&mut *tx)
                .await?;
            Self::query("UPDATE workflow_tasks SET status='completed' WHERE id=$1")
                .bind(&task_id)
                .execute(&mut *tx)
                .await?;
            Self::create_task(
                &mut tx,
                &run_id,
                &DB::string(&timer, "queue")?,
                "workflow",
                json!({"timer_id":timer_id}),
            )
            .await?;
        }
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(())
    }
    pub(super) async fn task_row(
        &self,
        tx: &mut Transaction<'_, DB>,
        task_id: &str,
    ) -> Result<DB::Row> {
        Self::query("SELECT t.*,c.receipt FROM workflow_tasks t LEFT JOIN dw_task_completions c ON c.task_id=t.id WHERE t.id=$1 AND t.namespace=$2")
        .bind(task_id)
        .bind(self.namespace.as_ref())
        .fetch_optional(&mut **tx)
        .await?
        .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "task_not_found"))
    }

    pub(super) async fn record_completion(
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

    pub(super) fn duplicate_receipt(task: &DB::Row, body: &Value) -> Result<bool> {
        if DB::string(task, "status")? != "completed" {
            return Ok(false);
        }
        let receipt: Value = DB::document_row(task, "receipt")?;
        if receipt == *body {
            return Ok(true);
        }
        Err(refuse(StatusCode::CONFLICT, "conflicting_completion"))
    }

    pub(super) fn fence(task: &DB::Row, body: &Value) -> Result<()> {
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

    pub(super) async fn active_run(tx: &mut Transaction<'_, DB>, run_id: &str) -> Result<DB::Row> {
        let run = Self::query("SELECT * FROM workflow_runs WHERE id=$1")
            .bind(run_id)
            .fetch_one(&mut **tx)
            .await?;
        if !matches!(
            DB::string(&run, "status")?.as_str(),
            "pending" | "running" | "waiting"
        ) {
            return Err(refuse(StatusCode::CONFLICT, "run_closed"));
        }
        if DB::optional_instant(&run, "execution_deadline_at")?
            .is_some_and(|deadline| deadline <= now())
            || DB::optional_instant(&run, "run_deadline_at")?
                .is_some_and(|deadline| deadline <= now())
        {
            return Err(refuse(StatusCode::CONFLICT, "run_timed_out"));
        }
        if DB::optional_string(&run, "cancellation_request_command_id")?.is_some()
            && DB::optional_instant(&run, "cancellation_deadline_at")?
                .is_none_or(|deadline| deadline <= now())
        {
            return Err(refuse(
                StatusCode::CONFLICT,
                "cancellation_deadline_expired",
            ));
        }
        Ok(run)
    }

    pub(super) async fn create_task(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        queue: &str,
        kind: &str,
        payload: Value,
    ) -> Result<String> {
        let task_id = id();
        let run = Self::query("SELECT namespace FROM workflow_runs WHERE id=$1")
            .bind(run_id)
            .fetch_one(&mut **tx)
            .await?;
        let namespace = DB::string(&run, "namespace")?;
        Self::query("INSERT INTO workflow_tasks(id,workflow_run_id,task_type,status,payload,queue,available_at,namespace) VALUES ($1,$2,$3,'ready',$4,$5,$6,$7)")
        .bind(&task_id).bind(run_id).bind(kind).bind(DB::document(&payload)).bind(queue).bind(DB::bind_time(now())).bind(&namespace).execute(&mut **tx).await?;
        Ok(task_id)
    }

    pub(super) async fn append(
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

    pub(super) fn activity_payload(activity: &DB::Row) -> Result<Value> {
        Ok(
            json!({"activity_execution_id": DB::string(activity,"id")?, "activity_type": DB::string(activity,"activity_type")?,
        "activity_class": DB::string(activity,"activity_class")?, "sequence": DB::number(activity,"sequence")?,
        "activity": {"id":DB::string(activity,"id")?,"idempotency_key":DB::string(activity,"id")?,
            "activity_type": DB::string(activity,"activity_type")?, "arguments": wire(&DB::string(activity,"arguments")?),
            "retry_policy":DB::optional_document_row(activity,"retry_policy")?,
            "status":DB::string(activity,"status")?,"attempt_count":DB::number(activity,"attempt_count")?,
            "closed_at":DB::optional_instant(activity,"closed_at")?,
            "attempt_id":DB::optional_string(activity,"current_attempt_id")?,
            "exception":DB::optional_string(activity,"exception")?.map(|value|wire(&value)),
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

    pub(super) async fn history_page_connection(
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

pub(super) async fn prepare_control_arguments(
    semaphore: Arc<Semaphore>,
    name: String,
    arguments: String,
    invalid: &'static str,
) -> Result<PreparedControlArguments> {
    let permit = semaphore
        .acquire_owned()
        .await
        .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "signal_codec_unavailable"))?;
    // A cancelled request cannot release this permit while its synchronous
    // official codec job is still running. Each runtime permits one such job.
    tokio::task::spawn_blocking(move || {
        let _permit = permit;
        let codec = crate::codec::ValueCodec::new()
            .map_err(|_| refuse(StatusCode::UNPROCESSABLE_ENTITY, invalid))?;
        let crate::codec::Value::Array(values) = codec
            .decode(&arguments)
            .map_err(|_| refuse(StatusCode::UNPROCESSABLE_ENTITY, invalid))?
        else {
            return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, invalid));
        };
        let value = match values.as_slice() {
            [] => crate::codec::Value::Null,
            [value] => value.clone(),
            _ => crate::codec::Value::Array(values.clone()),
        };
        let command = crate::codec::Value::Map(std::collections::BTreeMap::from([
            ("name".into(), crate::codec::Value::String(name)),
            (
                "arguments".into(),
                crate::codec::Value::Array(values.clone()),
            ),
            (
                "validation_errors".into(),
                crate::codec::Value::Array(vec![]),
            ),
        ]));
        Ok(PreparedControlArguments {
            arguments,
            values,
            value: codec
                .encode(&value)
                .map_err(|_| refuse(StatusCode::UNPROCESSABLE_ENTITY, invalid))?,
            command: codec
                .encode(&command)
                .map_err(|_| refuse(StatusCode::UNPROCESSABLE_ENTITY, invalid))?,
        })
    })
    .await
    .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "signal_codec_unavailable"))?
}

fn validate_signal_arguments(
    contract: &Value,
    name: &str,
    values: &[crate::codec::Value],
) -> Result<()> {
    validate_arguments(
        contract,
        "declared_signal_contracts",
        name,
        values,
        "unsupported_signal_contract",
        "invalid_signal_arguments",
    )
}

pub(super) fn validate_arguments(
    contract: &Value,
    field: &str,
    name: &str,
    values: &[crate::codec::Value],
    unsupported: &'static str,
    invalid: &'static str,
) -> Result<()> {
    let parameters = contract[field]
        .as_array()
        .and_then(|contracts| contracts.iter().find(|contract| contract["name"] == name))
        .and_then(|contract| contract["parameters"].as_array())
        .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, unsupported))?;
    if parameters.len() != values.len() {
        return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, invalid));
    }
    for (parameter, value) in parameters.iter().zip(values) {
        if parameter["variadic"] == true || parameter["default_available"] == true {
            return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, unsupported));
        }
        if matches!(value, crate::codec::Value::Null) && parameter["allows_null"] == true {
            continue;
        }
        use crate::codec::Value as Payload;
        let valid = match parameter["type"].as_str() {
            None | Some("mixed") => true,
            Some("array") => matches!(value, Payload::Array(_) | Payload::Map(_)),
            Some("int") => matches!(value, Payload::Long(_)),
            Some("float") => matches!(value, Payload::Double(_)),
            Some("bool") => matches!(value, Payload::Boolean(_)),
            Some("string") => matches!(value, Payload::String(_)),
            _ => {
                return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, unsupported));
            }
        };
        if !valid {
            return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, invalid));
        }
    }
    Ok(())
}

fn timer_deadline(command: &Value) -> Result<DateTime<Utc>> {
    command["delay_seconds"]
        .as_i64()
        .filter(|delay| *delay >= 0)
        .and_then(chrono::Duration::try_seconds)
        .and_then(|delay| now().checked_add_signed(delay))
        .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_timer_delay"))
}

pub(super) fn reject_fields(body: &Value, allowed: &[&str]) -> Result<()> {
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
    json!({"supported_workflow_task_commands": ["schedule_activity", "start_timer", "start_child_workflow", "open_condition_wait", "open_signal_wait", "complete_workflow"],
        "workflow_memo_updates": false, "cooperative_cancellation": true, "prepared_local_activities": false,
        "worker_sessions": false, "sticky_execution": false, "local_activities": false, "message_streams": false,
        "workflow_updates": true, "query_tasks": true, "query_task_poll_request_idempotency": false})
}
