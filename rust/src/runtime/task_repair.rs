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
};
use axum::http::StatusCode;
use chrono::{DateTime, Duration, Utc};
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::sync::Arc;
use tokio::sync::Semaphore;

/// `TaskRepairPolicy::readyTaskNeedsRedispatch` or `leaseExpired` for one
/// task row, as of `at`.
pub(super) fn needs_recovery<DB: Backend>(task: &DB::Row, at: DateTime<Utc>) -> Result<bool>
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
    // `dispatchOverdue`: a task made available after its last dispatch is
    // overdue from its availability.
    let available = DB::optional_instant(task, "available_at")?;
    let reference = dispatched
        .or(DB::optional_instant(task, "created_at")?)
        .map(|reference| available.filter(|available| *available > reference).unwrap_or(reference));
    Ok(available.is_none_or(|available| available <= at) && reference.is_some_and(|reference| reference <= cutoff))
}

/// `TaskRepair::replayBlocked`: a failed workflow task whose payload marks
/// its replay blocked.
pub(super) fn replay_blocked<DB: Backend>(task: &DB::Row) -> Result<bool>
where
    usize: ColumnIndex<DB::Row>,
    for<'r> i64: Decode<'r, DB>,
{
    Ok(DB::string(task, "task_type")? == "workflow"
        && DB::string(task, "status")? == "failed"
        && DB::optional_document_row(task, "payload")?.is_some_and(|payload| payload["replay_blocked"] == true))
}

/// `recoverableTask`'s order: earliest available, then created, then id.
pub(super) fn recovery_order<DB: Backend>(task: &DB::Row) -> Result<(i64, i64, String)>
where
    usize: ColumnIndex<DB::Row>,
    for<'r> i64: Decode<'r, DB>,
{
    let millis = |field: &str| -> Result<i64> {
        Ok(DB::optional_instant(task, field)?.map_or(i64::MAX, |at| at.timestamp_millis()))
    };
    Ok((millis("available_at")?, millis("created_at")?, DB::string(task, "id")?))
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
        let (status, mut response) = self.repair_run(workflow_id, requested_run_id, body, context, codec).await?;
        // `ServerWorkflowControlPlane::withDefinitionRouting`: the route of the
        // repaired run's next ready task.
        let mut tx = self.begin_read().await?;
        let run = match response["run_id"].as_str() {
            Some(run_id) => Self::query("SELECT * FROM workflow_runs WHERE id=$1 AND namespace=$2")
                .bind(run_id).bind(self.namespace.as_ref()).fetch_optional(&mut *tx).await?,
            None => Self::query("SELECT r.* FROM workflow_instances i JOIN workflow_runs r ON r.id=i.current_run_id WHERE i.id=$1 AND i.namespace=$2")
                .bind(workflow_id).bind(self.namespace.as_ref()).fetch_optional(&mut *tx).await?,
        };
        if let Some(run) = run {
            response["definition_routing"] = self.definition_inspect(&mut tx, &run, None).await?;
        }
        tx.commit().await?;
        Ok((status, response))
    }

    async fn repair_run(
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
        let summary = Self::query("SELECT liveness_state,wait_kind,open_wait_id,resume_source_kind,resume_source_id FROM workflow_run_summaries WHERE id=$1")
            .bind(&run_id)
            .fetch_optional(&mut *tx)
            .await?;
        let field = |name: &str| -> Result<Option<String>> {
            summary.as_ref().map(|row| DB::optional_string(row, name)).transpose().map(Option::flatten)
        };
        let (liveness, wait_kind) = (field("liveness_state")?, field("wait_kind")?);
        let task = match &summary {
            Some(summary) if liveness.as_deref() == Some("repair_needed") => Self::recover_task(&mut tx, &run, summary).await?,
            _ => None,
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

    /// `TaskRepair::repairRun`: the run's recoverable task is recovered (an
    /// overdue ready task counts one more repair, an expired workflow lease
    /// returns to ready, and a running activity's expired lease is settled by
    /// its retry policy, as `ActivityTimeoutEnforcer::enforceLeaseExpiry`
    /// settles it); without one, the missing task is created. Returns the task
    /// the repair dispatches, if any.
    async fn recover_task(tx: &mut Transaction<'_, DB>, run: &DB::Row, summary: &DB::Row) -> Result<Option<DB::Row>> {
        let run_id = DB::string(run, "id")?;
        let at = now();
        let tasks = Self::query("SELECT * FROM workflow_tasks WHERE workflow_run_id=$1 AND status IN ('ready','leased')")
            .bind(&run_id)
            .fetch_all(&mut **tx)
            .await?;
        let mut recoverable = Vec::new();
        for task in tasks {
            if needs_recovery::<DB>(&task, at)? {
                recoverable.push((recovery_order::<DB>(&task)?, task));
            }
        }
        recoverable.sort_by(|left, right| left.0.cmp(&right.0));
        let task_id = match recoverable.into_iter().next() {
            Some((_, task)) => {
                let task_id = DB::string(&task, "id")?;
                match Self::recover_task_row(tx, run, &task, at).await? {
                    super::repair_pass::Recovered::Task => task_id,
                    super::repair_pass::Recovered::Nothing => return Ok(None),
                    super::repair_pass::Recovered::ExpireLease => {
                        match Self::settle_lost_lease(tx, &task_id, super::activity_deadlines::LeaseLoss::Expired).await? {
                            // The enforcer's `next_task`: the retry, or the
                            // workflow task that sees the failure.
                            super::activity_deadlines::Enforcement::Enforced { .. } => {
                                match Self::query("SELECT id FROM workflow_tasks WHERE workflow_run_id=$1 AND status='ready' ORDER BY created_at DESC,id DESC LIMIT 1")
                                    .bind(&run_id).fetch_optional(&mut **tx).await?
                                {
                                    Some(row) => DB::string(&row, "id")?,
                                    None => return Ok(None),
                                }
                            }
                            super::activity_deadlines::Enforcement::Skipped(_) => return Ok(None),
                        }
                    }
                }
            }
            None => Self::create_missing_task(tx, run, summary).await?,
        };
        tx.summary_changed(&run_id);
        Ok(Some(Self::query("SELECT * FROM workflow_tasks WHERE id=$1").bind(&task_id).fetch_one(&mut **tx).await?))
    }

    /// `TaskRepair::createMissingTask` for a run whose summary needs repair:
    /// - waiting on an activity, the pending execution's activity task, with
    ///   its latest retry's fields and availability;
    /// - waiting on a timer, the pending timer's task, available at its fire
    ///   time;
    /// - waiting on a condition or signal wait's timeout timer, a workflow
    ///   task that applies the timeout once it has fired, or else the timer's
    ///   task, available at its deadline;
    /// - otherwise, or when that execution or timer is gone, a workflow task
    ///   with `forMissingWorkflowTask`'s payload: the accepted update's, the
    ///   received signal's or the resolved child's resume fields, or none.
    ///
    /// Each counts one repair. Returns the new task's id.
    pub(super) async fn create_missing_task(tx: &mut Transaction<'_, DB>, run: &DB::Row, summary: &DB::Row) -> Result<String> {
        let run_id = DB::string(run, "id")?;
        let field = |name: &str| -> Result<Option<String>> {
            Ok(DB::optional_string(summary, name)?.filter(|value| !value.is_empty()))
        };
        let wait_kind = field("wait_kind")?.unwrap_or_default();
        let resume_kind = field("resume_source_kind")?;
        let resume_id = field("resume_source_id")?.filter(|id| !id.trim().is_empty());
        let open_wait_id = field("open_wait_id")?;
        let run_queue = DB::string(run, "queue")?;
        if wait_kind == "activity"
            && let Some(execution) = Self::pending_execution(tx, &run_id, resume_id.as_deref()).await?
        {
            let execution_id = DB::string(&execution, "id")?;
            let mut payload = json!({"activity_execution_id": execution_id});
            let mut available = None;
            // `latestRetryScheduledEvent`: the retry that left this execution
            // pending carries its task's fields and availability.
            for row in Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='ActivityRetryScheduled' ORDER BY sequence DESC")
                .bind(&run_id).fetch_all(&mut **tx).await?
            {
                let retry = DB::document_row(&row, "payload")?;
                if retry["activity_execution_id"] != execution_id.as_str() {
                    continue;
                }
                for key in ["retry_of_task_id", "retry_after_attempt_id", "retry_after_attempt", "retry_backoff_seconds", "max_attempts", "retry_policy"] {
                    if let Some(value) = retry.get(key) {
                        payload[key] = value.clone();
                    }
                }
                available = retry["retry_available_at"].as_str().and_then(parse_time);
                break;
            }
            if payload.get("retry_after_attempt").is_some() && payload.get("retry_policy").is_none()
                && let Some(policy) = DB::optional_document_row(&execution, "retry_policy")?.filter(Value::is_object)
            {
                payload["retry_policy"] = policy;
            }
            let queue = DB::optional_string(&execution, "queue")?.unwrap_or(run_queue);
            let task_id = Self::create_task_dispatched(tx, &run_id, &queue, "activity", payload, false).await?;
            Self::query("UPDATE workflow_tasks SET repair_count=1,attempt_count=$1,available_at=$2 WHERE id=$3")
                .bind(DB::number(&execution, "attempt_count")?.max(0)).bind(DB::bind_time(available.unwrap_or_else(now)))
                .bind(&task_id).execute(&mut **tx).await?;
            Self::retarget_wait(tx, &run_id, "activity", &execution_id, "activity", &task_id).await?;
            return Ok(task_id);
        }
        if wait_kind == "timer"
            && let Some(timer) = Self::pending_timer(tx, &run_id, resume_id.as_deref()).await?
        {
            let timer_id = DB::string(&timer, "id")?;
            let task_id = Self::create_task_dispatched(tx, &run_id, &run_queue, "timer", json!({"timer_id": timer_id}), false).await?;
            let fire_at = DB::optional_instant(&timer, "fire_at")?.unwrap_or_else(now);
            Self::query("UPDATE workflow_tasks SET repair_count=1,available_at=$1 WHERE id=$2")
                .bind(DB::bind_time(fire_at)).bind(&task_id).execute(&mut **tx).await?;
            Self::retarget_wait(tx, &run_id, "timer", &timer_id, "timer", &task_id).await?;
            return Ok(task_id);
        }
        if matches!(wait_kind.as_str(), "condition" | "signal")
            && resume_kind.as_deref() == Some("timer")
            && let Some(task_id) = Self::create_missing_timeout_task(tx, &run_id, &run_queue, &wait_kind, open_wait_id.as_deref(), resume_id.as_deref()).await?
        {
            return Ok(task_id);
        }
        let payload = Self::missing_workflow_task_payload(tx, &run_id, &wait_kind, resume_kind.as_deref(), resume_id.as_deref(), open_wait_id.as_deref()).await?;
        let update = (payload["resume_source_kind"] == "workflow_update").then(|| payload["workflow_update_id"].as_str().map(str::to_owned)).flatten();
        let task_id = Self::create_task_dispatched(tx, &run_id, &run_queue, "workflow", payload, false).await?;
        Self::query("UPDATE workflow_tasks SET repair_count=1 WHERE id=$1").bind(&task_id).execute(&mut **tx).await?;
        if wait_kind == "update"
            && let Some(update) = update
        {
            Self::retarget_wait(tx, &run_id, "update", &update, "workflow", &task_id).await?;
        }
        Ok(task_id)
    }

    /// The condition or signal timeout branch of `createMissingTask`. The wait
    /// is the open one the summary names, or else the one on the summary's
    /// timer (a signal wait whose timeout fired counts as open). Returns
    /// `None` when there is no timer to restore.
    async fn create_missing_timeout_task(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        queue: &str,
        kind: &str,
        open_wait_id: Option<&str>,
        resume_id: Option<&str>,
    ) -> Result<Option<String>> {
        let mut waits = Vec::new();
        for row in Self::query("SELECT wait_id,status,source_status,sequence,deadline_at,resume_source_kind,resume_source_id FROM workflow_run_waits WHERE workflow_run_id=$1 AND kind=$2 ORDER BY sequence,wait_id")
            .bind(run_id).bind(kind).fetch_all(&mut **tx).await?
        {
            let open = DB::string(&row, "status")? == "open"
                || (kind == "signal" && DB::optional_string(&row, "source_status")?.as_deref() == Some("timed_out"));
            if open {
                waits.push(row);
            }
        }
        let timer_of = |row: &DB::Row| -> Result<Option<String>> {
            Ok(if DB::optional_string(row, "resume_source_kind")?.as_deref() == Some("timer") {
                DB::optional_string(row, "resume_source_id")?.filter(|id| !id.is_empty())
            } else {
                None
            })
        };
        let mut wait = None;
        for row in &waits {
            if open_wait_id.is_some_and(|id| DB::string(row, "wait_id").is_ok_and(|wait_id| wait_id == id)) {
                wait = Some(row);
                break;
            }
        }
        if wait.is_none()
            && let Some(resume_id) = resume_id
        {
            for row in &waits {
                if timer_of(row)?.as_deref() == Some(resume_id) {
                    wait = Some(row);
                    break;
                }
            }
        }
        let Some(timer_id) = wait.map(timer_of).transpose()?.flatten().or(resume_id.map(str::to_owned)) else {
            return Ok(None);
        };
        let timer = Self::query("SELECT * FROM workflow_run_timers WHERE id=$1 AND workflow_run_id=$2")
            .bind(&timer_id).bind(run_id).fetch_optional(&mut **tx).await?;
        // The wait's fields, as its timer was scheduled, and when it fired.
        let mut fields = json!({});
        let mut fired_at = None;
        for row in Self::query("SELECT event_type,payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type IN ('TimerScheduled','TimerFired') ORDER BY sequence")
            .bind(run_id).fetch_all(&mut **tx).await?
        {
            let payload = DB::document_row(&row, "payload")?;
            if payload["timer_id"] != timer_id.as_str() {
                continue;
            }
            if DB::string(&row, "event_type")? == "TimerScheduled" {
                fields = super::wait_timeouts::wait_fields(&payload);
            } else {
                fired_at = payload["fired_at"].as_str().and_then(parse_time);
            }
        }
        if wait.is_none() {
            fields = json!({});
        }
        let fired_at = fired_at.or(timer.as_ref().map(|timer| DB::optional_instant(timer, "fired_at")).transpose()?.flatten());
        let fired = fired_at.is_some()
            || timer.as_ref().map(|timer| DB::string(timer, "status")).transpose()?.as_deref() == Some("fired");
        let (wait_key, name_keys): (&str, &[&str]) = if kind == "condition" {
            ("condition_wait_id", &["condition_key", "condition_definition_fingerprint"])
        } else {
            ("signal_wait_id", &["signal_name"])
        };
        let text = |value: &Value| value.as_str().filter(|value| !value.is_empty()).map(str::to_owned);
        if fired {
            let mut payload = serde_json::Map::new();
            payload.insert("workflow_wait_kind".into(), json!(kind));
            if let Some(id) = text(&fields[wait_key]).or(open_wait_id.map(str::to_owned)) {
                payload.insert("open_wait_id".into(), json!(id));
            }
            payload.insert("resume_source_kind".into(), json!("timer"));
            payload.insert("resume_source_id".into(), json!(timer_id));
            payload.insert("timer_id".into(), json!(timer_id));
            for key in std::iter::once(&wait_key).chain(name_keys) {
                if let Some(value) = text(&fields[*key]) {
                    payload.insert((*key).into(), json!(value));
                }
            }
            if let Some(sequence) = wait.map(|wait| DB::optional_number(wait, "sequence")).transpose()?.flatten() {
                payload.insert("workflow_sequence".into(), json!(sequence));
            }
            let task_id = Self::create_task_dispatched(tx, run_id, queue, "workflow", Value::Object(payload), false).await?;
            Self::query("UPDATE workflow_tasks SET repair_count=1,available_at=$1 WHERE id=$2")
                .bind(DB::bind_time(fired_at.unwrap_or_else(now))).bind(&task_id).execute(&mut **tx).await?;
            return Ok(Some(task_id));
        }
        let Some(timer) = timer else {
            return Ok(None);
        };
        // The timer task carries the wait's fields as native schedules it, so
        // the timer fires with the identity its wait opened with.
        let mut payload = fields;
        payload["timer_id"] = json!(timer_id);
        let at = now();
        let deadline = wait.map(|wait| DB::optional_instant(wait, "deadline_at")).transpose()?.flatten()
            .or(DB::optional_instant(&timer, "fire_at")?)
            .filter(|deadline| *deadline > at)
            .unwrap_or(at);
        let task_id = Self::create_task_dispatched(tx, run_id, queue, "timer", payload, false).await?;
        Self::query("UPDATE workflow_tasks SET repair_count=1,available_at=$1 WHERE id=$2")
            .bind(DB::bind_time(deadline)).bind(&task_id).execute(&mut **tx).await?;
        if let Some(wait) = wait {
            Self::query("UPDATE workflow_run_waits SET task_backed=TRUE,task_id=$1,task_type='timer',task_status='ready',updated_at=$2 WHERE workflow_run_id=$3 AND kind=$4 AND wait_id=$5")
                .bind(&task_id).bind(DB::bind_time(at)).bind(run_id).bind(kind).bind(DB::string(wait, "wait_id")?)
                .execute(&mut **tx).await?;
        }
        Ok(Some(task_id))
    }

    /// `WorkflowTaskPayload::forMissingWorkflowTask`: an update or signal
    /// wait resumes from its update or signal row when it still exists
    /// (`forUpdate`, `forSignal`), else from the summary; a child wait resumes
    /// from its child run; any other wait has an empty payload.
    async fn missing_workflow_task_payload(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        wait_kind: &str,
        resume_kind: Option<&str>,
        resume_id: Option<&str>,
        open_wait_id: Option<&str>,
    ) -> Result<Value> {
        let filtered = |pairs: Vec<(&str, Option<String>)>| -> Value {
            Value::Object(pairs.into_iter().filter_map(|(key, value)| value.map(|value| (key.to_owned(), json!(value)))).collect())
        };
        let owned = |value: Option<&str>| value.map(str::to_owned);
        let summary_fallback = |id_key: &str, source: &str| {
            filtered(vec![
                ("workflow_wait_kind", Some(wait_kind.to_owned())),
                ("open_wait_id", owned(open_wait_id)),
                ("resume_source_kind", owned(resume_kind)),
                ("resume_source_id", owned(resume_id)),
                (id_key, owned(resume_id).filter(|_| resume_kind == Some(source))),
            ])
        };
        Ok(match wait_kind {
            "update" => {
                let update = match resume_id.filter(|_| resume_kind == Some("workflow_update")) {
                    Some(id) => Self::query("SELECT id,workflow_command_id FROM workflow_updates WHERE id=$1 AND workflow_run_id=$2")
                        .bind(id).bind(run_id).fetch_optional(&mut **tx).await?,
                    None => None,
                };
                match update {
                    Some(update) => {
                        let id = DB::string(&update, "id")?;
                        filtered(vec![
                            ("workflow_wait_kind", Some("update".into())),
                            ("open_wait_id", Some(format!("update:{id}"))),
                            ("resume_source_kind", Some("workflow_update".into())),
                            ("resume_source_id", Some(id.clone())),
                            ("workflow_update_id", Some(id)),
                            ("workflow_command_id", DB::optional_string(&update, "workflow_command_id")?),
                        ])
                    }
                    None => summary_fallback("workflow_update_id", "workflow_update"),
                }
            }
            "signal" => {
                let signal = match resume_id.filter(|_| resume_kind == Some("workflow_signal")) {
                    Some(id) => Self::query("SELECT id,signal_name,signal_wait_id,workflow_command_id FROM workflow_signal_records WHERE id=$1 AND workflow_run_id=$2")
                        .bind(id).bind(run_id).fetch_optional(&mut **tx).await?,
                    None => None,
                };
                match signal {
                    Some(signal) => {
                        let id = DB::string(&signal, "id")?;
                        filtered(vec![
                            ("workflow_wait_kind", Some("signal".into())),
                            ("open_wait_id", Some(format!("signal-application:{id}"))),
                            ("resume_source_kind", Some("workflow_signal".into())),
                            ("resume_source_id", Some(id.clone())),
                            ("workflow_signal_id", Some(id)),
                            ("signal_name", DB::optional_string(&signal, "signal_name")?),
                            ("signal_wait_id", DB::optional_string(&signal, "signal_wait_id")?),
                            ("workflow_command_id", DB::optional_string(&signal, "workflow_command_id")?),
                        ])
                    }
                    None => summary_fallback("workflow_signal_id", "workflow_signal"),
                }
            }
            "child" => filtered(vec![
                ("workflow_wait_kind", Some("child".into())),
                ("open_wait_id", owned(open_wait_id)),
                ("resume_source_kind", Some("child_workflow_run".into())),
                ("resume_source_id", owned(resume_id)),
                ("child_call_id", open_wait_id.and_then(|id| id.strip_prefix("child:")).filter(|id| !id.is_empty()).map(str::to_owned)),
                ("child_workflow_run_id", owned(resume_id)),
            ]),
            _ => json!({}),
        })
    }

    /// The run's open wait on that activity, timer or update is backed by the
    /// new task, as PHP's projection reads it from the task the repair created.
    async fn retarget_wait(tx: &mut Transaction<'_, DB>, run_id: &str, kind: &str, source_id: &str, task_type: &str, task_id: &str) -> Result<()> {
        Self::query("UPDATE workflow_run_waits SET task_backed=TRUE,task_id=$1,task_type=$2,task_status='ready',updated_at=$3 WHERE workflow_run_id=$4 AND kind=$5 AND resume_source_id=$6 AND status='open'")
            .bind(task_id).bind(task_type).bind(DB::bind_time(now())).bind(run_id).bind(kind).bind(source_id)
            .execute(&mut **tx).await?;
        Ok(())
    }

    /// `ActivityRecovery::pendingExecutionForSummary`: the summary's activity
    /// when it is pending, else the run's first pending activity.
    async fn pending_execution(tx: &mut Transaction<'_, DB>, run_id: &str, resume_id: Option<&str>) -> Result<Option<DB::Row>> {
        if let Some(resume_id) = resume_id
            && let Some(execution) = Self::query("SELECT * FROM activity_executions WHERE id=$1 AND workflow_run_id=$2 AND status='pending'")
                .bind(resume_id).bind(run_id).fetch_optional(&mut **tx).await?
        {
            return Ok(Some(execution));
        }
        Ok(Self::query("SELECT * FROM activity_executions WHERE workflow_run_id=$1 AND status='pending' ORDER BY sequence,id LIMIT 1")
            .bind(run_id).fetch_optional(&mut **tx).await?)
    }

    /// `restorePendingTimerFromSummary`: the summary's timer when it is
    /// pending, else the run's first pending timer that is not a condition or
    /// signal wait's timeout.
    async fn pending_timer(tx: &mut Transaction<'_, DB>, run_id: &str, resume_id: Option<&str>) -> Result<Option<DB::Row>> {
        if let Some(resume_id) = resume_id
            && let Some(timer) = Self::query("SELECT * FROM workflow_run_timers WHERE id=$1 AND workflow_run_id=$2 AND status='pending'")
                .bind(resume_id).bind(run_id).fetch_optional(&mut **tx).await?
        {
            return Ok(Some(timer));
        }
        let mut timeouts = Vec::new();
        for row in Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='TimerScheduled'")
            .bind(run_id).fetch_all(&mut **tx).await?
        {
            let payload = DB::document_row(&row, "payload")?;
            if matches!(payload["timer_kind"].as_str(), Some("condition_timeout" | "signal_timeout"))
                && let Some(id) = payload["timer_id"].as_str()
            {
                timeouts.push(id.to_owned());
            }
        }
        for timer in Self::query("SELECT * FROM workflow_run_timers WHERE workflow_run_id=$1 AND status='pending' ORDER BY sequence,id")
            .bind(run_id).fetch_all(&mut **tx).await?
        {
            if !timeouts.contains(&DB::string(&timer, "id")?) {
                return Ok(Some(timer));
            }
        }
        Ok(None)
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
        let response = Self::record_repair(tx, workflow_id, run, context, recorded).await?;
        // `TaskDispatcher::markDispatched`: PHP dispatches a replay-blocked
        // run's repaired task after the commit, as it does an overdue one.
        let at = now();
        Self::query("UPDATE workflow_tasks SET last_dispatch_attempt_at=$1,last_dispatched_at=$2,last_dispatch_error=NULL,repair_available_at=NULL WHERE id=$3 AND status='ready'")
            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(&task_id).execute(&mut **tx).await?;
        Ok(response)
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

/// An RFC 3339 instant in a history payload.
fn parse_time(at: &str) -> Option<DateTime<Utc>> {
    DateTime::parse_from_rfc3339(at).ok().map(|at| at.with_timezone(&Utc))
}

/// What one accepted repair records.
struct Repair<'a, Row> {
    liveness: Option<&'a str>,
    wait_kind: Option<&'a str>,
    payload: &'a str,
    task: Option<&'a Row>,
}
