//! Schedule controls and firing as published PHP's `ScheduleManager` does
//! them, with each admission committed atomically with its original run.
use super::schedule_storage::{iso8601, occurrence as occurrence_text};
use super::transaction::Transaction;
use super::{
    Result, RuntimeError,
    backend::Backend,
    refuse, schedules,
    store::{Store, id, now},
};
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

/// The result of one trigger evaluation (`ScheduleTriggerResult`).
pub(crate) enum Trigger {
    Triggered { workflow: String, run: String },
    Buffered,
    BufferFull,
    Skipped(String),
}

/// What the locked evaluation decided before any start.
enum Decision {
    Start { policy: String },
    /// Close the latest run first (`cancel_other`/`terminate_other`).
    Close { policy: String, workflow: String },
    Done(Trigger),
}

const BUFFER_FULL: &str = "Previous workflow is still running and buffer is at capacity.";

fn close_reason(policy: &str) -> String {
    let reason = format!("Schedule overlap: {policy} policy applied.");
    crate::codec::ValueCodec::new()
        .and_then(|codec| {
            codec.encode(&crate::codec::Value::Map(std::collections::BTreeMap::from([(
                "reason".into(),
                crate::codec::Value::String(reason),
            )])))
        })
        .unwrap_or_default()
}

impl<DB: Backend> Store<DB>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
    for<'q> &'q str: Encode<'q, DB> + Type<DB>,
    for<'q> Option<&'q str>: Encode<'q, DB>,
    for<'q> Option<DB::EncodedDocument>: Encode<'q, DB>,
    for<'q> Option<i64>: Encode<'q, DB>,
    for<'q> String: Encode<'q, DB> + Type<DB>,
    for<'q> i64: Encode<'q, DB> + Type<DB>,
    for<'r> i64: Decode<'r, DB>,
    usize: ColumnIndex<DB::Row>,
{
    /// Pause, resume or delete a live schedule.
    pub(crate) async fn edit_schedule(
        &self,
        schedule: &str,
        operation: &str,
        note: Option<String>,
        context: Value,
    ) -> Result<Value> {
        let mut tx = self.begin().await?;
        let row = self.schedule_row(&mut tx, schedule, false).await?;
        let key = DB::string(&row, "id")?;
        let changed = now();
        let outcome = match operation {
            "pause" => {
                Self::pause_in(&mut tx, &key, note.as_deref(), &context).await?;
                "paused"
            }
            "resume" => {
                if DB::string(&row, "status")? != "paused" {
                    // PHP raises an unhandled LogicException (500) here.
                    return Err(RuntimeError::Protocol {
                        status: StatusCode::CONFLICT,
                        response: json!({"message":format!("Schedule [{schedule}] is not paused."),
                            "reason":"schedule_not_paused","schedule_id":schedule}),
                    });
                }
                let next = schedules::next_fire(
                    &DB::optional_document_row(&row, "spec")?.unwrap_or(Value::Null),
                    DB::number(&row, "jitter_seconds")?,
                    changed,
                )?;
                Self::set_next(&mut tx, &key, next).await?;
                Self::query("UPDATE workflow_schedules SET status='active',paused_at=NULL,updated_at=$1 WHERE id=$2")
                    .bind(DB::bind_time(changed)).bind(&key).execute(&mut *tx).await?;
                Self::append_schedule(&mut tx, &key, "ScheduleResumed",
                    json!({"next_fire_at":next.map(iso8601)}), Some(&context), None, None).await?;
                if let Some(note) = &note {
                    Self::query("UPDATE workflow_schedules SET note=$1 WHERE id=$2")
                        .bind(note.as_str()).bind(&key).execute(&mut *tx).await?;
                }
                "resumed"
            }
            "delete" => {
                Self::query("UPDATE workflow_schedules SET status='deleted',deleted_at=$1,next_fire_at=NULL,buffered_actions=NULL,updated_at=$2 WHERE id=$3")
                    .bind(DB::bind_time(changed)).bind(DB::bind_time(changed)).bind(&key).execute(&mut *tx).await?;
                Self::append_schedule(&mut tx, &key, "ScheduleDeleted",
                    json!({"reason":"deleted","deleted_at":iso8601(changed)}), Some(&context), None, None).await?;
                "deleted"
            }
            _ => return Err(refuse(StatusCode::NOT_FOUND, "schedule_operation_not_found")),
        };
        tx.commit().await?;
        Ok(json!({"schedule_id":schedule,"outcome":outcome}))
    }

    async fn set_next(tx: &mut Transaction<'_, DB>, key: &str, next: Option<DateTime<Utc>>) -> Result<()> {
        match next {
            Some(next) => Self::query("UPDATE workflow_schedules SET next_fire_at=$1 WHERE id=$2")
                .bind(DB::bind_time(next))
                .bind(key)
                .execute(&mut **tx)
                .await?,
            None => Self::query("UPDATE workflow_schedules SET next_fire_at=NULL WHERE id=$1")
                .bind(key)
                .execute(&mut **tx)
                .await?,
        };
        Ok(())
    }

    async fn recompute_next(tx: &mut Transaction<'_, DB>, row: &DB::Row) -> Result<()> {
        let next = schedules::next_fire(
            &DB::optional_document_row(row, "spec")?.unwrap_or(Value::Null),
            DB::number(row, "jitter_seconds")?,
            now(),
        )?;
        Self::set_next(tx, &DB::string(row, "id")?, next).await
    }

    /// `ScheduleController::trigger`: one manual evaluation, optionally with
    /// another overlap policy.
    pub(crate) async fn trigger_schedule(
        &self,
        schedule: &str,
        overlap: Option<String>,
        context: Value,
    ) -> Result<Value> {
        let key = {
            let mut tx = self.begin().await?;
            let row = self.schedule_row(&mut tx, schedule, false).await?;
            DB::string(&row, "id")?
        };
        let result = match self
            .trigger_detailed(&key, overlap.as_deref(), Some(&context), None, "scheduled")
            .await
        {
            Ok(result) => result,
            Err(error @ RuntimeError::Database(_)) => return Err(error),
            // PHP answers any other failure with this 500 body.
            Err(error) => {
                return Err(RuntimeError::Protocol {
                    status: StatusCode::INTERNAL_SERVER_ERROR,
                    response: json!({"schedule_id":schedule,"outcome":"trigger_failed","reason":error.to_string()}),
                });
            }
        };
        let response = match result {
            Trigger::Triggered { workflow, run } => json!({"schedule_id":schedule,"outcome":"triggered",
                "workflow_id":workflow,"run_id":run}),
            Trigger::Buffered => {
                let row = Self::query("SELECT buffered_actions FROM workflow_schedules WHERE id=$1")
                    .bind(&key)
                    .fetch_one(&self.pool)
                    .await?;
                let depth = DB::optional_document_row(&row, "buffered_actions")?
                    .and_then(|items| items.as_array().map(Vec::len))
                    .unwrap_or(0);
                json!({"schedule_id":schedule,"outcome":"buffered","buffer_depth":depth})
            }
            Trigger::BufferFull => json!({"schedule_id":schedule,"outcome":"buffer_full","reason":BUFFER_FULL}),
            Trigger::Skipped(reason) => json!({"schedule_id":schedule,"outcome":"skipped","reason":reason}),
        };
        self.wake.notify_waiters();
        Ok(response)
    }

    /// `ScheduleManager::triggerDetailed` on the schedule row `key`.
    pub(super) async fn trigger_detailed(
        &self,
        key: &str,
        overlap: Option<&str>,
        context: Option<&Value>,
        occurrence: Option<DateTime<Utc>>,
        outcome: &str,
    ) -> Result<Trigger> {
        let mut closed = false;
        loop {
            let mut tx = self.begin().await?;
            let row = Self::query("SELECT * FROM workflow_schedules WHERE id=$1")
                .bind(key)
                .fetch_one(&mut *tx)
                .await?;
            let store = self.in_namespace(DB::optional_string(&row, "namespace")?.as_deref().unwrap_or("default"));
            match store
                .decide(&mut tx, &row, overlap, context, occurrence, closed, outcome == "backfilled")
                .await?
            {
                Decision::Done(result) => {
                    tx.commit().await?;
                    return Ok(result);
                }
                Decision::Close { policy, workflow } => {
                    // PHP closes the other run best-effort before starting.
                    drop(tx);
                    let command = if policy == "cancel_other" {
                        super::cancellation::TerminalCommand::Cancel
                    } else {
                        super::cancellation::TerminalCommand::Terminate
                    };
                    let reason = format!("Schedule overlap: {policy} policy applied.");
                    let _ = store
                        .close_workflow(command, &workflow, None, Some(reason), Some(close_reason(&policy)), None)
                        .await;
                    closed = true;
                }
                Decision::Start { policy } => {
                    let result = store
                        .start_scheduled(&mut tx, &row, occurrence, outcome, &policy, context)
                        .await;
                    match result {
                        Ok(result) => {
                            tx.commit().await?;
                            return Ok(result);
                        }
                        Err(RuntimeError::Refused { reason, .. }) => {
                            // The start was refused: PHP's
                            // WorkflowExecutionUnavailableException path.
                            drop(tx);
                            let mut tx = self.begin().await?;
                            let row = Self::query("SELECT * FROM workflow_schedules WHERE id=$1")
                                .bind(key)
                                .fetch_one(&mut *tx)
                                .await?;
                            Self::record_skip(&mut tx, &row, reason, context).await?;
                            Self::recompute_next(&mut tx, &row).await?;
                            tx.commit().await?;
                            return Ok(Trigger::Skipped(reason.to_owned()));
                        }
                        Err(error) => return Err(error),
                    }
                }
            }
        }
    }

    /// The checks of `triggerDetailed` before a start, on the locked row.
    async fn decide(
        &self,
        tx: &mut Transaction<'_, DB>,
        row: &DB::Row,
        overlap: Option<&str>,
        context: Option<&Value>,
        occurrence: Option<DateTime<Utc>>,
        closed: bool,
        backfill: bool,
    ) -> Result<Decision> {
        let skip = |reason: &str| Decision::Done(Trigger::Skipped(reason.to_owned()));
        // triggerForBackfill has no stale check: its occurrences are past.
        if let Some(occurrence) = occurrence.filter(|_| !backfill) {
            let next = DB::optional_instant(row, "next_fire_at")?;
            if next.is_none_or(|next| occurrence_key(next) != occurrence_key(occurrence)) {
                // PHP records a stale_occurrence skip here. Native nodes all
                // evaluate due schedules, so the occurrence another node just
                // handled is left without a spurious skip record.
                return Ok(skip("stale_occurrence"));
            }
        }
        if DB::string(row, "status")? != "active" {
            Self::record_skip(tx, row, "status_not_triggerable", context).await?;
            return Ok(skip("status_not_triggerable"));
        }
        if DB::optional_number(row, "remaining_actions")?.is_some_and(|remaining| remaining <= 0) {
            Self::record_skip(tx, row, "remaining_actions_exhausted", context).await?;
            return Ok(skip("remaining_actions_exhausted"));
        }
        if let Some(occurrence) = occurrence
            && self.has_fired(tx, row, occurrence).await?
        {
            self.skip_fired(tx, row, occurrence, context).await?;
            return Ok(skip("already_fired_occurrence"));
        }
        let stored = DB::string(row, "overlap_policy")?;
        let policy = overlap
            .or(Some(stored.as_str()))
            .filter(|policy| schedules::OVERLAP_POLICIES.contains(policy))
            .unwrap_or("skip")
            .to_owned();
        let running = self.running_workflow(tx, row).await?;
        if policy.starts_with("buffer_") && running.is_some() {
            let buffered = DB::optional_document_row(row, "buffered_actions")?
                .and_then(|items| items.as_array().cloned())
                .unwrap_or_default();
            if policy == "buffer_one" && !buffered.is_empty() {
                Self::record_skip(tx, row, "buffer_full", context).await?;
                Self::recompute_next(tx, row).await?;
                return Ok(Decision::Done(Trigger::BufferFull));
            }
            let mut action = json!({"buffered_at":iso8601(now())});
            if let Some(occurrence) = occurrence {
                action["occurrence_time"] = json!(occurrence_text(occurrence));
            }
            let mut buffered = buffered;
            buffered.push(action);
            Self::query("UPDATE workflow_schedules SET buffered_actions=$1,updated_at=$2 WHERE id=$3")
                .bind(DB::document(&json!(buffered))).bind(DB::bind_time(now())).bind(DB::string(row, "id")?)
                .execute(&mut **tx).await?;
            Self::recompute_next(tx, row).await?;
            return Ok(Decision::Done(Trigger::Buffered));
        }
        let allowed = policy == "allow_all"
            || running.is_none()
            || matches!(policy.as_str(), "cancel_other" | "terminate_other");
        if !allowed {
            let reason = format!("overlap_policy_{policy}");
            Self::record_skip(tx, row, &reason, context).await?;
            Self::recompute_next(tx, row).await?;
            return Ok(Decision::Done(Trigger::Skipped(reason)));
        }
        if !closed
            && matches!(policy.as_str(), "cancel_other" | "terminate_other")
            && let Some(workflow) = DB::optional_string(row, "latest_workflow_instance_id")?
        {
            return Ok(Decision::Close { policy, workflow });
        }
        Ok(Decision::Start { policy })
    }

    /// `ScheduleManager::startRun` through the server's remote starter.
    async fn start_scheduled(
        &self,
        tx: &mut Transaction<'_, DB>,
        row: &DB::Row,
        occurrence: Option<DateTime<Utc>>,
        outcome: &str,
        policy: &str,
        context: Option<&Value>,
    ) -> Result<Trigger> {
        let key = DB::string(row, "id")?;
        let schedule = DB::string(row, "schedule_id")?;
        let stored_policy = DB::string(row, "overlap_policy")?;
        let spec = DB::optional_document_row(row, "spec")?.unwrap_or(Value::Null);
        let action = schedules::normalize_action_timeouts(
            &DB::optional_document_row(row, "action")?.unwrap_or(Value::Null),
        );
        let mut start = json!({"workflow_id":id(),"workflow_type":action["workflow_type"],
            "task_queue":action["task_queue"].as_str().filter(|queue| !queue.trim().is_empty()).map(str::trim).unwrap_or("default"),
            "input":action.get("input").filter(|input| !input.is_null()).cloned().unwrap_or_else(empty_arguments)});
        for field in ["execution_timeout_seconds", "run_timeout_seconds"] {
            if let Some(seconds) = action.get(field).filter(|value| !value.is_null()) {
                start[field] = seconds.clone();
            }
        }
        let memo = match DB::optional_document_row(row, "memo")?.filter(|memo| !memo.is_null()) {
            Some(memo) => super::memos::initial(memo)?,
            None => Default::default(),
        };
        let started = Self::start_in(tx, &start, &self.namespace, super::store::StartMetadata { memo: Some(&memo), search_attributes: None }, None, None, self.worker_stale_after_seconds).await?;
        let workflow = started["workflow_id"].as_str().unwrap_or_default().to_owned();
        let run = started["run_id"].as_str().unwrap_or_default().to_owned();
        let fires = DB::number(row, "fires_count")?;
        let trigger_number = fires
            .checked_add(1)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "schedule_fire_count_exhausted"))?;
        // recordScheduleTriggered: the run's own schedule marker.
        let mut marker = json!({"schedule_id":schedule,"schedule_ulid":key,"overlap_policy":stored_policy,
            "trigger_number":trigger_number});
        if let Some(cron) = spec["cron_expressions"]
            .as_array()
            .and_then(|items| items.first())
        {
            marker["cron_expression"] = json!(match cron {
                Value::String(text) => text.clone(),
                other => other.to_string(),
            });
        }
        if let Some(zone) = spec.get("timezone").filter(|zone| !zone.is_null()) {
            marker["timezone"] = zone.clone();
        }
        let mut audit = json!({"workflow_instance_id":workflow,"workflow_run_id":run,"outcome":outcome,
            "effective_overlap_policy":policy,"trigger_number":trigger_number});
        if let Some(occurrence) = occurrence {
            marker["occurrence_time"] = json!(occurrence_text(occurrence));
            audit["occurrence_time"] = marker["occurrence_time"].clone();
        }
        Self::append(tx, &run, "ScheduleTriggered", marker, None, None).await?;
        Self::append_schedule(tx, &key, "ScheduleTriggered", audit, context, Some(&workflow), Some(&run))
            .await?;
        // recordFire: the ten most recent actions and the next occurrence.
        let fired = now();
        let mut recent_action = json!({"workflow_id":workflow,"run_id":run,"outcome":outcome,"fired_at":iso8601(fired)});
        if let Some(occurrence) = occurrence {
            recent_action["occurrence_time"] = json!(occurrence_text(occurrence));
            let lag = (fired - occurrence).num_microseconds().unwrap_or(0) as f64 / 1000.0;
            recent_action["fire_lag_ms"] = json!((lag.round() as i64).max(0));
        }
        let recent = recent_actions::<DB>(row, recent_action)?;
        let next = schedules::next_fire(&spec, DB::number(row, "jitter_seconds")?, fired)?;
        let remaining = DB::optional_number(row, "remaining_actions")?.map(|remaining| (remaining - 1).max(0));
        Self::query("UPDATE workflow_schedules SET fires_count=$1,last_fired_at=$2,latest_workflow_instance_id=$3,recent_actions=$4,remaining_actions=$5,updated_at=$6 WHERE id=$7")
            .bind(trigger_number).bind(DB::bind_time(fired)).bind(workflow.as_str()).bind(DB::document(&recent))
            .bind(remaining).bind(DB::bind_time(fired)).bind(&key).execute(&mut **tx).await?;
        Self::set_next(tx, &key, next).await?;
        if remaining.is_some_and(|remaining| remaining <= 0) {
            Self::query("UPDATE workflow_schedules SET status='deleted',deleted_at=$1,next_fire_at=NULL,buffered_actions=NULL WHERE id=$2")
                .bind(DB::bind_time(fired)).bind(&key).execute(&mut **tx).await?;
            Self::append_schedule(
                tx,
                &key,
                "ScheduleDeleted",
                json!({"reason":"max_runs_exhausted","deleted_at":iso8601(fired)}),
                context,
                None,
                None,
            )
            .await?;
        }
        Ok(Trigger::Triggered { workflow, run })
    }

    /// `recordSkip`.
    async fn record_skip(
        tx: &mut Transaction<'_, DB>,
        row: &DB::Row,
        reason: &str,
        context: Option<&Value>,
    ) -> Result<()> {
        let changed = now();
        let key = DB::string(row, "id")?;
        let current = Self::query("SELECT skipped_trigger_count FROM workflow_schedules WHERE id=$1")
            .bind(&key)
            .fetch_one(&mut **tx)
            .await?;
        let skipped = DB::number(&current, "skipped_trigger_count")?
            .checked_add(1)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "schedule_skip_count_exhausted"))?;
        Self::query("UPDATE workflow_schedules SET skipped_trigger_count=$1,last_skip_reason=$2,last_skipped_at=$3,updated_at=$4 WHERE id=$5")
            .bind(skipped).bind(reason).bind(DB::bind_time(changed)).bind(DB::bind_time(changed)).bind(&key).execute(&mut **tx).await?;
        Self::append_schedule(
            tx,
            &key,
            "ScheduleTriggerSkipped",
            json!({"reason":reason,"skipped_trigger_count":skipped,"last_skipped_at":iso8601(changed)}),
            context,
            None,
            None,
        )
        .await
    }

    /// `recordFailure`, committed on its own after a failed evaluation.
    pub(super) async fn record_failure(&self, key: &str, reason: &str) -> Result<()> {
        let mut tx = self.begin().await?;
        let row = Self::query("SELECT * FROM workflow_schedules WHERE id=$1")
            .bind(key)
            .fetch_one(&mut *tx)
            .await?;
        let failed = now();
        let recent = recent_actions::<DB>(
            &row,
            json!({"outcome":"failed","reason":reason,"fired_at":iso8601(failed)}),
        )?;
        let failures = DB::number(&row, "failures_count")?.saturating_add(1);
        Self::query("UPDATE workflow_schedules SET recent_actions=$1,failures_count=$2,last_fired_at=$3,updated_at=$4 WHERE id=$5")
            .bind(DB::document(&recent)).bind(failures).bind(DB::bind_time(failed)).bind(DB::bind_time(failed)).bind(key)
            .execute(&mut *tx).await?;
        // A spec PHP cannot evaluate keeps its stored occurrence.
        if let Ok(next) = schedules::next_fire(
            &DB::optional_document_row(&row, "spec")?.unwrap_or(Value::Null),
            DB::number(&row, "jitter_seconds")?,
            failed,
        ) {
            Self::set_next(&mut tx, key, next).await?;
        }
        tx.commit().await?;
        Ok(())
    }

    /// `hasFiredOccurrence`, including history written without the key.
    async fn has_fired(
        &self,
        tx: &mut Transaction<'_, DB>,
        row: &DB::Row,
        occurrence: DateTime<Utc>,
    ) -> Result<bool> {
        let key = DB::string(row, "id")?;
        let wanted = occurrence_key(occurrence);
        let found = Self::query("SELECT id FROM workflow_schedule_history_events WHERE workflow_schedule_id=$1 AND event_type='ScheduleTriggered' AND occurrence_at_utc=$2 LIMIT 1")
            .bind(&key).bind(wanted.as_str()).fetch_optional(&mut **tx).await?;
        if found.is_some() {
            return Ok(true);
        }
        let legacy = Self::query("SELECT payload FROM workflow_schedule_history_events WHERE workflow_schedule_id=$1 AND event_type='ScheduleTriggered' AND occurrence_at_utc IS NULL")
            .bind(&key).fetch_all(&mut **tx).await?;
        for event in legacy {
            let payload = DB::optional_document_row(&event, "payload")?.unwrap_or(Value::Null);
            if payload["occurrence_time"]
                .as_str()
                .and_then(|text| DateTime::parse_from_rfc3339(text).ok())
                .is_some_and(|time| occurrence_key(time.with_timezone(&Utc)) == wanted)
            {
                return Ok(true);
            }
        }
        Ok(false)
    }

    /// `skipFiredOccurrence`.
    async fn skip_fired(
        &self,
        tx: &mut Transaction<'_, DB>,
        row: &DB::Row,
        occurrence: DateTime<Utc>,
        context: Option<&Value>,
    ) -> Result<()> {
        Self::record_skip(tx, row, "already_fired_occurrence", context).await?;
        if DB::optional_instant(row, "next_fire_at")?
            .is_some_and(|next| occurrence_key(next) == occurrence_key(occurrence))
            && now().timestamp() >= occurrence.timestamp()
        {
            Self::recompute_next(tx, row).await?;
        }
        Ok(())
    }

    /// `runningRunExists`: the latest generated workflow's current run, if
    /// it is still open.
    async fn running_workflow(&self, tx: &mut Transaction<'_, DB>, row: &DB::Row) -> Result<Option<String>> {
        let Some(latest) = DB::optional_string(row, "latest_workflow_instance_id")? else {
            return Ok(None);
        };
        let run = Self::query("SELECT r.status FROM workflow_instances i JOIN workflow_runs r ON r.id=i.current_run_id WHERE i.id=$1")
            .bind(latest.as_str()).fetch_optional(&mut **tx).await?;
        let open = run
            .as_ref()
            .map(|run| DB::string(run, "status"))
            .transpose()?
            .is_some_and(|status| matches!(status.as_str(), "pending" | "running" | "waiting"));
        Ok(open.then_some(latest))
    }

    /// `ScheduleManager::tick`: drain buffered actions whose run finished,
    /// then evaluate due schedules, in every namespace.
    pub(crate) async fn fire_due_schedules(&self) -> Result<()> {
        // A bounded read probe avoids the global transition lock on idle ticks.
        let buffered = Self::query("SELECT id FROM workflow_schedules WHERE status='active' AND buffered_actions IS NOT NULL ORDER BY updated_at,id LIMIT 100")
            .fetch_all(&self.pool).await?;
        for candidate in buffered {
            let key = DB::string(&candidate, "id")?;
            if let Err(error) = self.drain_one(&key).await {
                self.settle_failed_admission(&key, error).await?;
            }
        }
        let due = Self::query("SELECT id,next_fire_at FROM workflow_schedules WHERE status='active' AND next_fire_at IS NOT NULL AND next_fire_at<=$1 ORDER BY next_fire_at,id LIMIT 100")
            .bind(DB::bind_time(now())).fetch_all(&self.pool).await?;
        for candidate in due {
            let key = DB::string(&candidate, "id")?;
            let occurrence = DB::optional_instant(&candidate, "next_fire_at")?;
            match self
                .trigger_detailed(&key, None, None, occurrence, "scheduled")
                .await
            {
                Ok(_) => self.wake.notify_waiters(),
                Err(error) => self.settle_failed_admission(&key, error).await?,
            }
        }
        Ok(())
    }

    /// One schedule's failed admission in a maintenance tick, as PHP's
    /// `recordStartFailure` settles it (#955). A transient storage failure
    /// (lock pressure or an unavailable backend) keeps the stored occurrence
    /// and fails the tick, which retries it from durable state. Any other
    /// failure is recorded, which moves the schedule to its next occurrence,
    /// and the batch goes on. One schedule that always fails can then stop
    /// neither the other schedules, the repair pass nor readiness.
    async fn settle_failed_admission(&self, key: &str, error: RuntimeError) -> Result<()> {
        if super::worker_incarnations::transient_reason(&error).is_some() {
            return Err(error);
        }
        let reason = match &error {
            // Database text can carry stored payloads. The schedule's visible
            // actions get the HTTP surface's bounded reason, and the log gets
            // only the bounded driver diagnostic.
            RuntimeError::Database(_) | RuntimeError::Migration(_) => {
                if let Some(diagnostic) = error.storage_diagnostic() {
                    eprintln!("{diagnostic}");
                }
                "storage_unavailable".to_owned()
            }
            _ => error.to_string(),
        };
        // A failure to record it is a storage failure of its own: the tick
        // fails and the occurrence is retried.
        self.record_failure(key, &reason).await
    }

    /// One buffered action of the tick's first phase.
    async fn drain_one(&self, key: &str) -> Result<()> {
        let mut tx = self.begin().await?;
        let row = Self::query("SELECT * FROM workflow_schedules WHERE id=$1")
            .bind(key)
            .fetch_one(&mut *tx)
            .await?;
        let store = self.in_namespace(DB::optional_string(&row, "namespace")?.as_deref().unwrap_or("default"));
        let mut buffered = DB::optional_document_row(&row, "buffered_actions")?
            .and_then(|items| items.as_array().cloned())
            .unwrap_or_default();
        if buffered.is_empty() || store.running_workflow(&mut tx, &row).await?.is_some() {
            return Ok(());
        }
        let action = buffered.remove(0);
        let rest = (!buffered.is_empty()).then(|| DB::document(&json!(buffered)));
        Self::query("UPDATE workflow_schedules SET buffered_actions=$1,updated_at=$2 WHERE id=$3")
            .bind(rest).bind(DB::bind_time(now())).bind(key).execute(&mut *tx).await?;
        let occurrence = action["occurrence_time"]
            .as_str()
            .and_then(|text| DateTime::parse_from_rfc3339(text).ok())
            .map(|time| time.with_timezone(&Utc));
        if let Some(occurrence) = occurrence
            && store.has_fired(&mut tx, &row, occurrence).await?
        {
            store.skip_fired(&mut tx, &row, occurrence, None).await?;
            tx.commit().await?;
            return Ok(());
        }
        if DB::string(&row, "status")? != "active" {
            drop(tx);
            let mut tx = self.begin().await?;
            Self::record_skip(&mut tx, &row, "schedule_not_triggerable", None).await?;
            tx.commit().await?;
            return Ok(());
        }
        let policy = DB::string(&row, "overlap_policy")?;
        match store
            .start_scheduled(&mut tx, &row, occurrence, "drained", &policy, None)
            .await
        {
            Ok(_) => {
                tx.commit().await?;
                self.wake.notify_waiters();
                Ok(())
            }
            Err(RuntimeError::Refused { reason, .. }) => {
                // The drained action is kept: PHP rolls the drain back too.
                drop(tx);
                let mut tx = self.begin().await?;
                Self::record_skip(&mut tx, &row, reason, None).await?;
                tx.commit().await?;
                Ok(())
            }
            Err(error) => Err(error),
        }
    }
}

/// `utcOccurrenceKey`.
fn occurrence_key(time: DateTime<Utc>) -> String {
    time.format("%Y-%m-%d %H:%M:%S%.6f").to_string()
}

/// The empty argument list, as PHP serializes `[]`.
fn empty_arguments() -> Value {
    let blob = crate::codec::ValueCodec::new()
        .and_then(|codec| codec.encode(&crate::codec::Value::Array(vec![])))
        .unwrap_or_default();
    json!({"codec":"avro","blob":blob})
}

/// The recent-actions ring with one more action, keeping the last ten.
fn recent_actions<DB: Backend>(row: &DB::Row, action: Value) -> Result<Value>
where
    usize: ColumnIndex<DB::Row>,
{
    let mut actions = DB::optional_document_row(row, "recent_actions")?
        .and_then(|items| items.as_array().cloned())
        .unwrap_or_default();
    actions.push(action);
    let skip = actions.len().saturating_sub(10);
    Ok(json!(actions.split_off(skip)))
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::codec::{Value as Payload, ValueCodec};

    /// A one-run interval schedule whose stored occurrence is already due.
    async fn due_schedule(store: &Store<sqlx::Sqlite>, schedule: &str, overdue_seconds: i64) -> DateTime<Utc> {
        let input = ValueCodec::new()
            .unwrap()
            .encode(&Payload::Array(vec![Payload::Long(9007199254740993)]))
            .unwrap();
        let definition = schedules::definition(&json!({"schedule_id":schedule,
            "spec":{"intervals":[{"every":"PT2S"}],"timezone":"UTC"},
            "action":{"workflow_type":"echo","task_queue":"test","input":{"codec":"avro","blob":input},
                "execution_timeout_seconds":3600,"run_timeout_seconds":600},
            "overlap_policy":"skip","jitter_seconds":0,"max_runs":1}))
        .unwrap();
        store
            .create_schedule(definition, json!({"test":"original creation"}), json!({}))
            .await
            .unwrap();
        make_due(store, schedule, overdue_seconds).await
    }

    /// Makes the schedule's stored occurrence due and returns it, in whole
    /// seconds as the schedule description reports it.
    async fn make_due(store: &Store<sqlx::Sqlite>, schedule: &str, overdue_seconds: i64) -> DateTime<Utc> {
        let occurrence = DateTime::from_timestamp(now().timestamp() - overdue_seconds, 0).unwrap();
        sqlx::query("UPDATE workflow_schedules SET next_fire_at=? WHERE schedule_id=?")
            .bind(occurrence.format("%Y-%m-%d %H:%M:%S%.6f").to_string())
            .bind(schedule)
            .execute(&store.pool)
            .await
            .unwrap();
        occurrence
    }

    async fn count(store: &Store<sqlx::Sqlite>, table: &str) -> i64 {
        // Compiled test-only table names, never request input.
        sqlx::query_scalar(sqlx::AssertSqlSafe(format!("SELECT COUNT(*) FROM {table}")))
            .fetch_one(&store.pool)
            .await
            .unwrap()
    }

    fn instant(value: &Value) -> DateTime<Utc> {
        DateTime::parse_from_rfc3339(value.as_str().unwrap())
            .unwrap()
            .with_timezone(&Utc)
    }

    async fn sqlite_store(file: &std::path::Path) -> Store<sqlx::Sqlite> {
        Store::<sqlx::Sqlite>::new(super::super::sqlite::open(file.to_str().unwrap()).await.unwrap())
    }

    #[tokio::test]
    async fn failed_due_admission_rolls_back_start_keeps_quota_and_records_the_failure() {
        let directory = tempfile::tempdir().unwrap();
        let store = sqlite_store(&directory.path().join("schedule.sqlite")).await;
        due_schedule(&store, "atomic-occurrence", 4).await;
        // A database failure that is not transient: the trigger aborts the
        // admission's audit row (SQLITE_CONSTRAINT_TRIGGER).
        sqlx::query("CREATE TRIGGER reject_schedule_audit BEFORE INSERT ON workflow_schedule_history_events WHEN NEW.event_type='ScheduleTriggered' BEGIN SELECT RAISE(ABORT,'injected schedule audit failure: secret payload'); END")
            .execute(&store.pool).await.unwrap();
        let failed_at = now();
        store.fire_due_schedules().await.unwrap();
        for table in [
            "workflow_instances",
            "workflow_runs",
            "workflow_tasks",
            "workflow_commands",
            "workflow_history_events",
        ] {
            assert_eq!(count(&store, table).await, 0, "partial {table} survived failed schedule admission");
        }
        let after = store.describe_schedule("atomic-occurrence").await.unwrap();
        assert_eq!(after["fires_count"], 0);
        assert_eq!(after["remaining_actions"], 1, "the rolled-back start kept its quota");
        assert_eq!(after["status"], "active");
        assert_eq!(after["failures_count"], 1);
        let recent = after["info"]["recent_actions"].as_array().unwrap();
        assert_eq!(recent.len(), 1);
        assert_eq!(recent[0]["outcome"], "failed");
        // The database's text, which can carry payloads, stays out of view.
        assert_eq!(recent[0]["reason"], "storage_unavailable");
        assert!(!after.to_string().contains("secret payload"));
        // As PHP's recordFailure: the schedule moves to its next occurrence.
        assert!(instant(&after["next_fire_at"]) > failed_at);
        let history = store.schedule_history("atomic-occurrence", 0, 100).await.unwrap();
        assert_eq!(history["events"].as_array().unwrap().len(), 1);
        sqlx::query("DROP TRIGGER reject_schedule_audit")
            .execute(&store.pool)
            .await
            .unwrap();
        // The kept quota starts exactly one run at the next due occurrence.
        let next = make_due(&store, "atomic-occurrence", 1).await;
        store.fire_due_schedules().await.unwrap();
        make_due(&store, "atomic-occurrence", 1).await;
        store.fire_due_schedules().await.unwrap();
        let history = store.schedule_history("atomic-occurrence", 0, 100).await.unwrap();
        assert_eq!(history["events"].as_array().unwrap().len(), 3);
        assert_eq!(history["events"][1]["payload"]["occurrence_time"], occurrence_text(next));
        assert_eq!(history["events"][2]["payload"]["reason"], "max_runs_exhausted");
        assert_eq!(count(&store, "workflow_runs").await, 1);
        store.close().await;
    }

    #[tokio::test]
    async fn a_schedule_that_always_fails_does_not_stop_the_rest_of_the_batch() {
        // #955: the failing schedule is first in due order on every tick.
        let directory = tempfile::tempdir().unwrap();
        let store = sqlite_store(&directory.path().join("schedule.sqlite")).await;
        due_schedule(&store, "always-fails", 60).await;
        due_schedule(&store, "healthy", 30).await;
        let broken: String =
            sqlx::query_scalar("SELECT id FROM workflow_schedules WHERE schedule_id='always-fails'")
                .fetch_one(&store.pool)
                .await
                .unwrap();
        // The id is a generated ULID, never request input.
        sqlx::query(sqlx::AssertSqlSafe(format!("CREATE TRIGGER reject_one_schedule BEFORE INSERT ON workflow_schedule_history_events WHEN NEW.event_type='ScheduleTriggered' AND NEW.workflow_schedule_id='{broken}' BEGIN SELECT RAISE(ABORT,'injected'); END")))
            .execute(&store.pool).await.unwrap();
        for _ in 0..3 {
            store.fire_due_schedules().await.unwrap();
            make_due(&store, "always-fails", 60).await;
        }
        // Its one allowed run ends it, so its row is read directly.
        let (fires, failures): (i64, i64) = sqlx::query_as(
            "SELECT fires_count,failures_count FROM workflow_schedules WHERE schedule_id='healthy'",
        )
        .fetch_one(&store.pool)
        .await
        .unwrap();
        assert_eq!(fires, 1, "the later schedule fired despite the failing one");
        assert_eq!(failures, 0);
        let failing = store.describe_schedule("always-fails").await.unwrap();
        assert_eq!(failing["fires_count"], 0);
        assert_eq!(failing["failures_count"], 3, "each tick recorded the failure and went on");
        assert_eq!(failing["remaining_actions"], 1);
        assert_eq!(count(&store, "workflow_runs").await, 1);
        store.close().await;
    }

    #[tokio::test]
    async fn backend_unavailability_keeps_the_occurrence_without_recording() {
        let directory = tempfile::tempdir().unwrap();
        let store = sqlite_store(&directory.path().join("schedule.sqlite")).await;
        let occurrence = due_schedule(&store, "outage", 4).await;
        let key: String = sqlx::query_scalar("SELECT id FROM workflow_schedules WHERE schedule_id='outage'")
            .fetch_one(&store.pool)
            .await
            .unwrap();
        // A backend-wide outage observed by one admission, settled while the
        // database is reachable again: it must still not be recorded as this
        // schedule's failure or consume the occurrence.
        for error in [sqlx::Error::PoolTimedOut, sqlx::Error::PoolClosed] {
            let error = RuntimeError::from(error);
            assert_eq!(
                super::super::worker_incarnations::transient_reason(&error),
                Some("backend_unavailable")
            );
            assert!(store.settle_failed_admission(&key, error).await.is_err());
        }
        let after = store.describe_schedule("outage").await.unwrap();
        assert_eq!(after["failures_count"], 0, "an outage is retried, not recorded");
        assert_eq!(after["info"]["recent_actions"], json!([]));
        assert_eq!(instant(&after["next_fire_at"]), occurrence, "the original occurrence is kept");
        store.close().await;
    }

    #[tokio::test]
    async fn lock_pressure_keeps_the_occurrence_and_fails_the_tick_without_recording() {
        let directory = tempfile::tempdir().unwrap();
        let file = directory.path().join("schedule.sqlite");
        let store = sqlite_store(&file).await;
        let occurrence = due_schedule(&store, "locked", 4).await;
        store.close().await;
        // The same database with a short busy wait, while another writer
        // holds SQLite's single write lock: SQLITE_BUSY.
        let options = sqlx::sqlite::SqliteConnectOptions::new()
            .filename(&file)
            .journal_mode(sqlx::sqlite::SqliteJournalMode::Wal)
            .foreign_keys(true)
            .busy_timeout(std::time::Duration::from_millis(50));
        let pool = sqlx::sqlite::SqlitePoolOptions::new()
            .max_connections(2)
            .connect_with(options.clone())
            .await
            .unwrap();
        let store = Store::<sqlx::Sqlite>::new(pool);
        let mut holder = <sqlx::SqliteConnection as sqlx::Connection>::connect_with(&options)
            .await
            .unwrap();
        sqlx::query("BEGIN IMMEDIATE").execute(&mut holder).await.unwrap();
        let error = store.fire_due_schedules().await.unwrap_err();
        assert_eq!(
            super::super::worker_incarnations::transient_reason(&error),
            Some("backend_lock_pressure")
        );
        sqlx::query("ROLLBACK").execute(&mut holder).await.unwrap();
        let after = store.describe_schedule("locked").await.unwrap();
        assert_eq!(after["failures_count"], 0, "lock pressure is retried, not recorded");
        assert_eq!(after["info"]["recent_actions"], json!([]));
        assert_eq!(instant(&after["next_fire_at"]), occurrence, "the original occurrence is kept");
        // The same real BUSY error, settled once the lock is free again: the
        // transient rule, not the held lock, is what keeps it unrecorded.
        sqlx::query("BEGIN IMMEDIATE").execute(&mut holder).await.unwrap();
        let busy = RuntimeError::from(
            sqlx::query("UPDATE workflow_schedules SET updated_at=updated_at")
                .execute(&store.pool)
                .await
                .unwrap_err(),
        );
        sqlx::query("ROLLBACK").execute(&mut holder).await.unwrap();
        let key: String = sqlx::query_scalar("SELECT id FROM workflow_schedules WHERE schedule_id='locked'")
            .fetch_one(&store.pool)
            .await
            .unwrap();
        assert!(store.settle_failed_admission(&key, busy).await.is_err());
        let after = store.describe_schedule("locked").await.unwrap();
        assert_eq!(after["failures_count"], 0);
        assert_eq!(instant(&after["next_fire_at"]), occurrence);
        // The next tick admits that same occurrence.
        store.fire_due_schedules().await.unwrap();
        let history = store.schedule_history("locked", 0, 100).await.unwrap();
        assert_eq!(history["events"][1]["payload"]["occurrence_time"], occurrence_text(occurrence));
        store.close().await;
    }
}
