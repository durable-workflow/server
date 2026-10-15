//! Run snapshots are written after the transition's final durable state.
use super::{
    Result, backend::Backend, history_budget, run_observation::command_contract,
    run_observation_store::terminal, store::Store,
};
use serde_json::Value;
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use super::transaction::Transaction;
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
            .bind(run_id).execute(&mut **tx).await?;
        let last = DB::number(&run, "last_history_sequence")?;
        let mut count = 0_i64;
        let mut bytes = 0_i64;
        let mut fan_out = 0_i64;
        let mut groups = BTreeSet::new();
        let mut failures = BTreeSet::new();
        let mut started = None;
        let mut active_history_supported = true;
        loop {
            // Bound hydrated history to sixteen events. The transaction already
            // owns this run; sequence continuity detects missing retained rows.
            let page = Self::query(DB::SUMMARY_HISTORY_SQL)
                .bind(run_id)
                .bind(count)
                .fetch_all(&mut **tx)
                .await?;
            if page.is_empty() {
                break;
            }
            for event in page {
                let sequence = DB::number(&event, "sequence")?;
                if sequence != count + 1 {
                    return Ok(());
                }
                let kind = DB::string(&event, "event_type")?;
                active_history_supported &= matches!(kind.as_str(),
                    "StartAccepted" | "WorkflowStarted" | "RepairRequested");
                let raw =
                    DB::optional_string(&event, "payload_json")?.unwrap_or_else(|| "null".into());
                bytes += history_budget::event_size(&kind, &raw)? as i64;
                let payload: Value = serde_json::from_str(&raw)?;
                if kind == "WorkflowStarted" && started.is_none() {
                    started = Some(payload.clone());
                }
                if let Some(group) = payload["parallel_group_id"]
                    .as_str()
                    .filter(|s| !s.is_empty())
                {
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
                        if groups.insert(group.to_owned()) {
                            fan_out = fan_out.max(size);
                        }
                    }
                }
                if matches!(
                    kind.as_str(),
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
                        failures.insert(id.to_owned());
                    }
                }
                count = sequence;
            }
        }
        if count != last || count == 0 {
            return Ok(());
        }
        let active = if closed { None } else {
            if !active_history_supported { return Ok(()); }
            match Self::active_task_summary(tx, &run).await? {
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
            None => format!("Run closed as {}.", DB::optional_string(&run, "closed_reason")?.unwrap_or(status)),
        };
        let pressure = if count >= 10000 || bytes >= 5242880 || fan_out >= 200 {
            "continue_as_new_recommended"
        } else if count >= 8000 || bytes >= 4194304 || fan_out >= 160 {
            "approaching"
        } else {
            "ok"
        };
        let state = active.as_ref().map_or("closed", |summary| summary.state.as_str());
        let repair_blocked = active.as_ref().map_or(Some("run_closed"), |summary| summary.repair_blocked);
        Self::query("INSERT INTO workflow_run_summaries(id,workflow_instance_id,run_number,is_current_run,engine_source,projection_schema_version,class,workflow_type,namespace,compatibility,declared_entry_mode,declared_contract_source,business_key,visibility_labels,status,status_bucket,closed_reason,connection,queue,started_at,sort_timestamp,sort_key,closed_at,archived_at,archive_command_id,archive_reason,duration_ms,liveness_state,liveness_reason,repair_blocked_reason,repair_attention,task_problem,exception_count,history_event_count,history_size_bytes,history_fan_out,continue_as_new_recommended,history_budget_pressure,created_at,updated_at) SELECT r.id,r.workflow_instance_id,r.run_number,CASE WHEN i.current_run_id=r.id THEN TRUE ELSE FALSE END,'v2',2,r.workflow_class,r.workflow_type,COALESCE(r.namespace,i.namespace),r.compatibility,$1,$2,COALESCE(r.business_key,i.business_key),COALESCE(r.visibility_labels,i.visibility_labels),r.status,CASE WHEN r.status='completed' THEN 'completed' WHEN r.status IN ('failed','cancelled','terminated') THEN 'failed' ELSE 'running' END,r.closed_reason,r.connection,r.queue,r.started_at,$3,$4,r.closed_at,r.archived_at,r.archive_command_id,r.archive_reason,CASE WHEN r.started_at IS NULL OR r.closed_at IS NULL THEN NULL ELSE $5 END,$6,$7,CASE WHEN i.current_run_id=r.id THEN $8 ELSE 'selected_run_not_current' END,FALSE,FALSE,$9,$10,$11,$12,CASE WHEN $13='continue_as_new_recommended' THEN TRUE ELSE FALSE END,$14,r.created_at,COALESCE(r.closed_at,r.last_progress_at,r.updated_at) FROM workflow_runs r JOIN workflow_instances i ON i.id=r.workflow_instance_id WHERE r.id=$15")
            .bind(contract["entry_mode"].as_str()).bind(contract["source"].as_str()).bind(DB::optional_time(sort_time)).bind(sort_key.as_deref()).bind(duration).bind(state).bind(reason).bind(repair_blocked)
            .bind(failures.len() as i64).bind(count).bind(bytes).bind(fan_out).bind(pressure).bind(pressure).bind(run_id).execute(&mut **tx).await?;
        if let Some(active) = active {
            Self::write_active_summary_fields(tx, run_id, &active).await?;
        }
        Ok(())
    }
}
