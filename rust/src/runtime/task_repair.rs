//! `WorkflowStub::attemptRepair` for control-plane repair commands: a run
//! whose summary needs repair gets its task redispatched; any other active run
//! records that no repair was needed; a closed run's repair is a durable
//! rejection.
use super::{
    Result, RuntimeError,
    backend::Backend,
    refuse,
    store::{Store, id, now},
    transaction::Transaction,
    wire,
};
use axum::http::StatusCode;
use chrono::{DateTime, Duration, Utc};
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::sync::Arc;
use tokio::sync::Semaphore;

/// `TaskRepairPolicy::readyTaskNeedsRedispatch` or `leaseExpired` for one
/// task row, as of `at`.
fn needs_recovery<DB: Backend>(task: &DB::Row, at: DateTime<Utc>) -> Result<bool>
where
    usize: ColumnIndex<DB::Row>,
    for<'r> i64: Decode<'r, DB>,
{
    let status = DB::string(task, "status")?;
    if status == "leased" {
        return Ok(DB::optional_instant(task, "lease_expires_at")?.is_some_and(|expires| expires <= at));
    }
    if status != "ready" {
        return Ok(false);
    }
    let error = |field: &str| -> Result<bool> {
        Ok(DB::optional_string(task, field)?.is_some_and(|error| !error.trim().is_empty()))
    };
    let attempted = DB::optional_instant(task, "last_dispatch_attempt_at")?;
    let dispatched = DB::optional_instant(task, "last_dispatched_at")?;
    let claim_failed_at = DB::optional_instant(task, "last_claim_failed_at")?;
    let backoff = DB::optional_instant(task, "repair_available_at")?;
    let cutoff = at - Duration::seconds(super::operator_metrics::REDISPATCH_AFTER_SECONDS);
    let dispatch_failed = attempted.is_some_and(|attempted| dispatched.is_none_or(|dispatched| attempted > dispatched))
        && error("last_dispatch_error")?;
    let claim_failed = claim_failed_at.is_some() && error("last_claim_error")?;
    if dispatch_failed {
        return Ok(backoff.is_none_or(|backoff| backoff <= at));
    }
    if claim_failed {
        return Ok(match backoff {
            Some(backoff) => backoff <= at,
            None => claim_failed_at.is_some_and(|failed| failed <= cutoff),
        });
    }
    let available = DB::optional_instant(task, "available_at")?;
    let reference = dispatched.or(DB::optional_instant(task, "created_at")?);
    Ok(available.is_none_or(|available| available <= at) && reference.is_some_and(|reference| reference <= cutoff))
}

/// `recoverableTask`'s order: earliest available, then created, then id.
fn recovery_order<DB: Backend>(task: &DB::Row) -> Result<(i64, i64, String)>
where
    usize: ColumnIndex<DB::Row>,
    for<'r> i64: Decode<'r, DB>,
{
    let millis = |field: &str| -> Result<i64> {
        Ok(DB::optional_instant(task, field)?.map_or(i64::MAX, |at| at.timestamp_millis()))
    };
    Ok((millis("available_at")?, millis("created_at")?, DB::string(task, "id")?))
}

fn unqualified() -> RuntimeError {
    refuse(StatusCode::UNPROCESSABLE_ENTITY, "development_workflow_task_repair_unqualified")
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
    pub(crate) async fn repair_workflow(
        &self,
        workflow_id: &str,
        requested_run_id: Option<&str>,
        body: Value,
        context: Value,
        codec: Arc<Semaphore>,
    ) -> Result<(StatusCode, Value)> {
        if !body.is_object()
            || body.get("request_id").is_some_and(|value| {
                !value.is_null() && value.as_str().is_none_or(|id| id.chars().count() > 255)
            })
        {
            return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_request"));
        }
        if self.namespace.as_ref() != "default" {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "development_workflow_task_repair_namespace_unqualified",
            ));
        }
        let mut tx = self.begin().await?;
        let instance = Self::query("SELECT * FROM workflow_instances WHERE id=$1 AND namespace=$2")
            .bind(workflow_id)
            .bind(self.namespace.as_ref())
            .fetch_optional(&mut *tx)
            .await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "instance_not_found"))?;
        let run_id = DB::string(&instance, "current_run_id")?;
        if requested_run_id.is_some_and(|selected| selected != run_id) {
            return Err(RuntimeError::Protocol {
                status: StatusCode::CONFLICT,
                response: json!({
                    "workflow_id":workflow_id,"run_id":requested_run_id,"target_scope":"run",
                    "reason":"historical_run_command_rejected",
                    "message":"Commands cannot target historical runs. Use the instance-level endpoint to command the current run, or omit the run ID."
                }),
            });
        }
        let current = Self::query("SELECT * FROM workflow_runs WHERE id=$1")
            .bind(&run_id)
            .fetch_one(&mut *tx)
            .await?;
        if !matches!(DB::string(&current, "status")?.as_str(), "pending" | "running" | "waiting") {
            return Self::reject_repair(tx, workflow_id, &current, &context).await;
        }
        let run = Self::active_run(&mut tx, &run_id).await?;
        if let Some(task) = Self::query(DB::RANGE_REPAIR_TASK_SQL)
            .bind(&run_id)
            .bind(self.namespace.as_ref())
            .fetch_optional(&mut *tx)
            .await?
        {
            let response = Self::repair_replay_blocked(&mut tx, workflow_id, &run, &task, &context, codec).await?;
            tx.commit().await?;
            self.wake.notify_waiters();
            return Ok((StatusCode::OK, response));
        }
        // PHP projects the run, then repairs only what its summary says needs it.
        Self::project_run_summary(&mut tx, &run_id).await?;
        let summary = Self::query("SELECT liveness_state,wait_kind FROM workflow_run_summaries WHERE id=$1")
            .bind(&run_id)
            .fetch_optional(&mut *tx)
            .await?;
        let field = |name: &str| -> Result<Option<String>> {
            summary.as_ref().map(|row| DB::optional_string(row, name)).transpose().map(Option::flatten)
        };
        let (liveness, wait_kind) = (field("liveness_state")?, field("wait_kind")?);
        let task = if liveness.as_deref() == Some("repair_needed") {
            Some(Self::recover_task(&mut tx, &run_id).await?)
        } else {
            None
        };
        let task_type = task.as_ref().map(|task| DB::string(task, "task_type")).transpose()?;
        let identity = task.as_ref().map(|task| DB::string(task, "id")).transpose()?.zip(task_type);
        let payload = super::worker_incarnations::repair_command_payload(codec, liveness.clone(), wait_kind.clone(), identity).await?;
        let recorded = Repair { liveness: liveness.as_deref(), wait_kind: wait_kind.as_deref(), payload: &payload, task: task.as_ref() };
        let response = Self::record_repair(&mut tx, workflow_id, &run, &context, recorded).await?;
        if let Some(task) = &task {
            // `TaskDispatcher::markDispatched`, which PHP runs after the commit.
            let at = now();
            Self::query("UPDATE workflow_tasks SET last_dispatch_attempt_at=$1,last_dispatched_at=$2,last_dispatch_error=NULL,repair_available_at=NULL WHERE id=$3 AND status='ready'")
                .bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(DB::string(task, "id")?).execute(&mut *tx).await?;
        }
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok((StatusCode::OK, response))
    }

    /// `TaskRepair::repairRun` for an existing task: a ready task due for
    /// redispatch counts one more repair, and a workflow task whose lease
    /// expired returns to ready. Creating a missing task, and recovering an
    /// expired activity lease, are not ported.
    async fn recover_task(tx: &mut Transaction<'_, DB>, run_id: &str) -> Result<DB::Row> {
        let at = now();
        let tasks = Self::query("SELECT * FROM workflow_tasks WHERE workflow_run_id=$1 AND status IN ('ready','leased')")
            .bind(run_id)
            .fetch_all(&mut **tx)
            .await?;
        let mut recoverable = Vec::new();
        for task in tasks {
            if needs_recovery::<DB>(&task, at)? {
                recoverable.push((recovery_order::<DB>(&task)?, task));
            }
        }
        recoverable.sort_by(|left, right| left.0.cmp(&right.0));
        let (_, task) = recoverable.into_iter().next().ok_or_else(unqualified)?;
        let task_id = DB::string(&task, "id")?;
        let repair_count = DB::number(&task, "repair_count")?
            .checked_add(1)
            .filter(|count| *count <= DB::REPAIR_COUNTER_MAX)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "repair_count_exhausted"))?;
        if DB::string(&task, "status")? == "leased" {
            if DB::string(&task, "task_type")? != "workflow" {
                return Err(unqualified());
            }
            Self::query("UPDATE workflow_tasks SET status='ready',leased_at=NULL,lease_owner=NULL,lease_expires_at=NULL,repair_count=$1,repair_available_at=NULL,last_error=NULL,updated_at=$2 WHERE id=$3")
                .bind(repair_count).bind(DB::bind_time(at)).bind(&task_id).execute(&mut **tx).await?;
        } else {
            Self::query("UPDATE workflow_tasks SET repair_count=$1,repair_available_at=NULL,last_error=NULL,updated_at=$2 WHERE id=$3")
                .bind(repair_count).bind(DB::bind_time(at)).bind(&task_id).execute(&mut **tx).await?;
        }
        Ok(Self::query("SELECT * FROM workflow_tasks WHERE id=$1").bind(&task_id).fetch_one(&mut **tx).await?)
    }

    /// Explicit recovery of a worker-reported version-range blockage.
    async fn repair_replay_blocked(
        tx: &mut Transaction<'_, DB>,
        workflow_id: &str,
        run: &DB::Row,
        task: &DB::Row,
        context: &Value,
        codec: Arc<Semaphore>,
    ) -> Result<Value> {
        let run_id = DB::string(run, "id")?;
        let signals = Self::scalar("SELECT COUNT(*) FROM workflow_signal_records WHERE workflow_run_id=$1 AND status='received'")
            .bind(&run_id).fetch_one(&mut **tx).await?;
        let updates = Self::scalar(
            "SELECT COUNT(*) FROM workflow_updates WHERE workflow_run_id=$1 AND status='accepted'",
        )
        .bind(&run_id)
        .fetch_one(&mut **tx)
        .await?;
        if signals != 0 || updates != 0 || Self::cancellation_request(run)?.is_some() {
            // Message/cancellation repair needs its own shared contract.
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "development_workflow_task_repair_active_work_unqualified",
            ));
        }
        let wait_kind = Self::range_repair_wait(tx, &run_id).await?;
        let task_id = DB::string(task, "id")?;
        let repair_count = DB::number(task, "repair_count")?
            .checked_add(1)
            .filter(|count| *count <= DB::REPAIR_COUNTER_MAX)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "repair_count_exhausted"))?;
        let payload = super::worker_incarnations::repair_payload(
            codec,
            task_id.clone(),
            "workflow_replay_blocked",
            wait_kind,
        )
        .await?;
        let mut task_payload = DB::document_row(task, "payload")?;
        task_payload
            .as_object_mut()
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "invalid_workflow_task_payload"))?
            .retain(|key, _| !key.starts_with("replay_blocked"));
        Self::query("UPDATE workflow_tasks SET status='ready',payload=$1,leased_at=NULL,lease_owner=NULL,lease_expires_at=NULL,repair_count=$2,repair_available_at=NULL,last_error=NULL,updated_at=$3 WHERE id=$4")
            .bind(DB::document(&task_payload)).bind(repair_count).bind(DB::bind_time(now())).bind(&task_id).execute(&mut **tx).await?;
        let repaired = Self::query("SELECT * FROM workflow_tasks WHERE id=$1")
            .bind(&task_id)
            .fetch_one(&mut **tx)
            .await?;
        let recorded = Repair { liveness: Some("workflow_replay_blocked"), wait_kind, payload: &payload, task: Some(&repaired) };
        Self::record_repair(tx, workflow_id, run, context, recorded).await
    }

    /// The accepted repair command, its `RepairRequested` event and PHP's
    /// response.
    async fn record_repair(
        tx: &mut Transaction<'_, DB>,
        workflow_id: &str,
        run: &DB::Row,
        context: &Value,
        repair: Repair<'_, DB::Row>,
    ) -> Result<Value> {
        let run_id = DB::string(run, "id")?;
        let sequence = DB::number(run, "last_command_sequence")?
            .checked_add(1)
            .filter(|count| *count <= DB::REPAIR_COUNTER_MAX)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "command_sequence_exhausted"))?;
        let outcome = if repair.task.is_some() { "repair_dispatched" } else { "repair_not_needed" };
        let timestamp = now();
        let command_id = id();
        // PHP guards a selected run then records an instance-targeted repair.
        // Preserve that command identity rather than treating the URL as scope.
        Self::query("INSERT INTO workflow_commands(id,workflow_instance_id,workflow_run_id,resolved_workflow_run_id,command_type,source,target_scope,status,outcome,workflow_type,payload_codec,payload,command_sequence,accepted_at,applied_at,context) VALUES ($1,$2,$3,$4,'repair','control_plane','instance','accepted',$5,$6,'avro',$7,$8,$9,$10,$11)")
            .bind(&command_id).bind(workflow_id).bind(&run_id).bind(&run_id).bind(outcome).bind(DB::string(run, "workflow_type")?)
            .bind(repair.payload).bind(sequence).bind(DB::bind_time(timestamp)).bind(DB::bind_time(timestamp))
            .bind(DB::document(context)).execute(&mut **tx).await?;
        Self::query(
            "UPDATE workflow_runs SET last_command_sequence=$1,last_progress_at=$2 WHERE id=$3",
        )
        .bind(sequence)
        .bind(DB::bind_time(timestamp))
        .bind(&run_id)
        .execute(&mut **tx)
        .await?;
        let task_id = repair.task.map(|task| DB::string(task, "id")).transpose()?;
        let task_type = repair.task.map(|task| DB::string(task, "task_type")).transpose()?;
        let mut event = json!({
            "workflow_command_id":command_id,"workflow_instance_id":workflow_id,"workflow_run_id":run_id,
            "command_type":"repair","outcome":outcome,"liveness_state":repair.liveness,
            "wait_kind":repair.wait_kind,"task_id":task_id,"task_type":task_type,
            "command":{"id":command_id,"sequence":sequence,"type":"repair","target_scope":"instance",
                "resolved_run_id":run_id,"status":"accepted","outcome":outcome,
                "source":"control_plane","payload_codec":"avro","payload":wire(repair.payload),
                "context":{"principal":context["principal"]},
                "caller_label":context["caller"]["label"],"auth_status":context["auth"]["status"],
                "auth_method":context["auth"]["method"],"principal_type":context["principal"]["type"],
                "principal_id":context["principal"]["id"],"principal_label":context["principal"]["label"],
                "request_method":context["request"]["method"],"request_path":context["request"]["path"],
                "request_fingerprint":context["request"]["fingerprint"],"accepted_at":timestamp,"applied_at":timestamp}
        });
        if let Some(task) = repair.task {
            event["task"] = Self::task_snapshot(task)?;
        }
        Self::append(tx, &run_id, "RepairRequested", event, task_id.as_deref(), Some(&command_id)).await?;
        Ok(json!({"workflow_id":workflow_id,"run_id":run_id,"workflow_type":DB::string(run, "workflow_type")?,
            "command_id":command_id,"command_sequence":sequence,"command_status":"accepted",
            "command_source":"control_plane","outcome":outcome,"requested_run_id":null,
            "resolved_run_id":run_id,"target_scope":"instance","reason":null,"rejection_reason":null,
            "validation_errors":[]}))
    }

    /// PHP's `rejectCommand` for a closed run: a durable rejected command and
    /// a 409, without a history event.
    async fn reject_repair(
        mut tx: Transaction<'_, DB>,
        workflow_id: &str,
        run: &DB::Row,
        context: &Value,
    ) -> Result<(StatusCode, Value)> {
        let run_id = DB::string(run, "id")?;
        let sequence = DB::number(run, "last_command_sequence")?
            .checked_add(1)
            .filter(|count| *count <= DB::REPAIR_COUNTER_MAX)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "command_sequence_exhausted"))?;
        let command_id = id();
        Self::query("INSERT INTO workflow_commands(id,workflow_instance_id,workflow_run_id,resolved_workflow_run_id,command_type,source,target_scope,status,outcome,rejection_reason,workflow_type,payload_codec,command_sequence,rejected_at,context) VALUES ($1,$2,$3,$4,'repair','control_plane','instance','rejected','rejected_not_active','run_not_active',$5,'avro',$6,$7,$8)")
            .bind(&command_id).bind(workflow_id).bind(&run_id).bind(&run_id).bind(DB::string(run, "workflow_type")?)
            .bind(sequence).bind(DB::bind_time(now())).bind(DB::document(context)).execute(&mut *tx).await?;
        Self::query("UPDATE workflow_runs SET last_command_sequence=$1 WHERE id=$2")
            .bind(sequence)
            .bind(&run_id)
            .execute(&mut *tx)
            .await?;
        tx.commit().await?;
        Ok((
            StatusCode::CONFLICT,
            json!({"accepted":false,"workflow_id":workflow_id,"run_id":run_id,"workflow_type":DB::string(run, "workflow_type")?,
                "command_id":command_id,"command_sequence":sequence,"command_status":"rejected",
                "command_source":"control_plane","outcome":"rejected_not_active","requested_run_id":null,
                "resolved_run_id":run_id,"target_scope":"instance","reason":"run_not_active",
                "rejection_reason":"run_not_active","validation_errors":[]}),
        ))
    }
}

/// What one accepted repair records.
struct Repair<'a, Row> {
    liveness: Option<&'a str>,
    wait_kind: Option<&'a str>,
    payload: &'a str,
    task: Option<&'a Row>,
}
