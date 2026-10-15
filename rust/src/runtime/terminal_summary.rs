//! Run snapshots are written after the transition's final durable state.
use super::activity_summary::ActivitySummaryHistory;
use super::child_summary::ChildSummaryHistory;
use super::transaction::Transaction;
use super::{
    Result, backend::Backend, external_wait_summary::ExternalWaitHistory, history_budget,
    run_observation::command_contract, run_observation_store::terminal, store::Store,
    timer_summary::TimerSummaryHistory,
};
use serde_json::Value;
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::collections::BTreeSet;

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
    pub(super) async fn project_run_summary(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
    ) -> Result<()> {
        let run = Self::query("SELECT * FROM workflow_runs WHERE id=$1")
            .bind(run_id)
            .fetch_one(&mut **tx)
            .await?;
        let status = DB::string(&run, "status")?;
        let closed = terminal(&status);
        // Retention and imported histories need their separately qualified
        // counters. Never label a partial scan as a complete stored summary.
        if DB::optional_instant(&run, "details_pruned_at")?.is_some()
            || DB::optional_string(&run, "import_source")?.is_some()
        {
            return Ok(());
        }
        // A state outside the qualified producer must not retain an earlier
        // healthy snapshot. Reads still never create or repair this row.
        Self::query("DELETE FROM workflow_run_summaries WHERE id=$1")
            .bind(run_id)
            .execute(&mut **tx)
            .await?;
        let last = DB::number(&run, "last_history_sequence")?;
        let Some(history) = Self::summary_history(tx, run_id, closed, last).await? else {
            return Ok(());
        };
        let SummaryHistory {
            count,
            bytes,
            fan_out,
            mut failures,
            started,
            active_history_supported,
            external_waits,
            timers,
            activities,
            children,
            ..
        } = history;
        let active = if closed {
            None
        } else {
            if !active_history_supported {
                return Ok(());
            }
            match Self::active_task_summary(
                tx,
                &run,
                &external_waits,
                &timers,
                &activities,
                &children,
            )
            .await?
            {
                Some(summary) => Some(summary),
                None => return Ok(()),
            }
        };
        let mut after = String::new();
        loop {
            let page = Self::query("SELECT id FROM workflow_failures WHERE workflow_run_id=$1 AND id>$2 ORDER BY id LIMIT 100").bind(run_id).bind(&after).fetch_all(&mut **tx).await?;
            if page.is_empty() {
                break;
            }
            for row in page {
                after = DB::string(&row, "id")?;
                failures.insert(after.clone());
            }
        }
        let contract = command_contract(started.as_ref());
        let duration = match (
            DB::optional_instant(&run, "started_at")?,
            DB::optional_instant(&run, "closed_at")?,
        ) {
            (Some(start), Some(end)) => (end - start).num_milliseconds().max(0),
            _ => 0,
        };
        let sort_time = DB::optional_instant(&run, "started_at")?
            .or(DB::optional_instant(&run, "created_at")?)
            .or(DB::optional_instant(&run, "updated_at")?);
        let sort_key =
            sort_time.map(|time| format!("{}#{run_id}", time.format("%Y-%m-%dT%H:%M:%S%.6fZ")));
        let reason = match active.as_ref() {
            Some(summary) => summary.reason.clone(),
            None => format!(
                "Run closed as {}.",
                DB::optional_string(&run, "closed_reason")?.unwrap_or(status)
            ),
        };
        let pressure = if count >= 10000 || bytes >= 5242880 || fan_out >= 200 {
            "continue_as_new_recommended"
        } else if count >= 8000 || bytes >= 4194304 || fan_out >= 160 {
            "approaching"
        } else {
            "ok"
        };
        let state = active
            .as_ref()
            .map_or("closed", |summary| summary.state.as_str());
        let repair_blocked = active
            .as_ref()
            .map_or(Some("run_closed"), |summary| summary.repair_blocked);
        Self::query("INSERT INTO workflow_run_summaries(id,workflow_instance_id,run_number,is_current_run,engine_source,projection_schema_version,class,workflow_type,namespace,compatibility,declared_entry_mode,declared_contract_source,business_key,visibility_labels,status,status_bucket,closed_reason,connection,queue,started_at,sort_timestamp,sort_key,closed_at,archived_at,archive_command_id,archive_reason,duration_ms,liveness_state,liveness_reason,repair_blocked_reason,repair_attention,task_problem,exception_count,history_event_count,history_size_bytes,history_fan_out,continue_as_new_recommended,history_budget_pressure,created_at,updated_at) SELECT r.id,r.workflow_instance_id,r.run_number,CASE WHEN i.current_run_id=r.id THEN TRUE ELSE FALSE END,'v2',2,r.workflow_class,r.workflow_type,COALESCE(r.namespace,i.namespace),r.compatibility,$1,$2,COALESCE(r.business_key,i.business_key),COALESCE(r.visibility_labels,i.visibility_labels),r.status,CASE WHEN r.status='completed' THEN 'completed' WHEN r.status IN ('failed','cancelled','terminated') THEN 'failed' ELSE 'running' END,r.closed_reason,r.connection,r.queue,r.started_at,$3,$4,r.closed_at,r.archived_at,r.archive_command_id,r.archive_reason,CASE WHEN r.started_at IS NULL OR r.closed_at IS NULL THEN NULL ELSE $5 END,$6,$7,CASE WHEN i.current_run_id=r.id THEN $8 ELSE 'selected_run_not_current' END,FALSE,FALSE,$9,$10,$11,$12,CASE WHEN $13='continue_as_new_recommended' THEN TRUE ELSE FALSE END,$14,r.created_at,COALESCE(r.closed_at,r.last_progress_at,r.updated_at) FROM workflow_runs r JOIN workflow_instances i ON i.id=r.workflow_instance_id WHERE r.id=$15")
            .bind(contract["entry_mode"].as_str()).bind(contract["source"].as_str()).bind(DB::optional_time(sort_time)).bind(sort_key.as_deref()).bind(duration).bind(state).bind(reason).bind(repair_blocked)
            .bind(failures.len() as i64).bind(count).bind(bytes).bind(fan_out).bind(pressure).bind(pressure).bind(run_id).execute(&mut **tx).await?;
        if let Some(active) = active {
            Self::write_active_summary_fields(tx, run_id, &active).await?;
        }
        Ok(())
    }
    async fn summary_history(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        closed: bool,
        last: i64,
    ) -> Result<Option<SummaryHistory>> {
        let mut history = SummaryHistory {
            active_history_supported: true,
            ..SummaryHistory::default()
        };
        loop {
            // Bound hydrated history to sixteen events. The transaction already
            // owns this run; sequence continuity detects missing retained rows.
            let page = Self::query(DB::SUMMARY_HISTORY_SQL)
                .bind(run_id)
                .bind(history.count)
                .fetch_all(&mut **tx)
                .await?;
            if page.is_empty() {
                break;
            }
            for event in page {
                let sequence = DB::number(&event, "sequence")?;
                if sequence != history.count + 1 {
                    return Ok(None);
                }
                let kind = DB::string(&event, "event_type")?;
                let raw =
                    DB::optional_string(&event, "payload_json")?.unwrap_or_else(|| "null".into());
                history.record(&kind, &raw, closed)?;
                history.count = sequence;
            }
        }
        Ok((history.count == last && history.count != 0).then_some(history))
    }
}

#[derive(Default)]
struct SummaryHistory {
    count: i64,
    bytes: i64,
    fan_out: i64,
    groups: BTreeSet<String>,
    failures: BTreeSet<String>,
    started: Option<Value>,
    active_history_supported: bool,
    external_waits: ExternalWaitHistory,
    timers: TimerSummaryHistory,
    activities: ActivitySummaryHistory,
    children: ChildSummaryHistory,
}

impl SummaryHistory {
    fn record(&mut self, kind: &str, raw: &str, closed: bool) -> Result<()> {
        self.bytes += history_budget::event_size(kind, raw)? as i64;
        let payload: Value = serde_json::from_str(raw)?;
        if !closed {
            let supported = if matches!(kind, "TimerScheduled" | "TimerFired" | "TimerCancelled") {
                self.timers.apply(kind, &payload)
            } else if kind.starts_with("Activity") {
                self.activities.apply(kind, &payload)
            } else if kind.starts_with("Child") {
                self.children.apply(kind, &payload)
            } else {
                self.external_waits.apply(kind, &payload)
            };
            self.active_history_supported &= supported;
        }
        if kind == "WorkflowStarted" && self.started.is_none() {
            self.started = Some(payload.clone());
        }
        self.record_fan_out(&payload);
        self.record_failure(kind, &payload);
        Ok(())
    }

    fn record_fan_out(&mut self, payload: &Value) {
        let Some(group) = payload["parallel_group_id"]
            .as_str()
            .filter(|s| !s.is_empty())
        else {
            return;
        };
        let size = payload["parallel_group_size"]
            .as_i64()
            .or_else(|| payload["parallel_group_size"].as_f64().map(|v| v as i64))
            .or_else(|| {
                payload["parallel_group_size"]
                    .as_str()
                    .and_then(|s| s.trim().parse::<f64>().ok())
                    .filter(|v| v.is_finite())
                    .map(|v| v as i64)
            });
        if let Some(size) = size {
            if self.groups.insert(group.to_owned()) {
                self.fan_out = self.fan_out.max(size);
            }
        }
    }

    fn record_failure(&mut self, kind: &str, payload: &Value) {
        if matches!(
            kind,
            "ActivityFailed"
                | "ActivityTimedOut"
                | "ChildRunFailed"
                | "ChildRunCancelled"
                | "ChildRunTerminated"
                | "WorkflowFailed"
                | "WorkflowTimedOut"
                | "WorkflowCancelled"
                | "WorkflowTerminated"
                | "UpdateCompleted"
        ) {
            if let Some(id) = payload["failure_id"].as_str().filter(|s| !s.is_empty()) {
                self.failures.insert(id.to_owned());
            }
        }
    }
}
