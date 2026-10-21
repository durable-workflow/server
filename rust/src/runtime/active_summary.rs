//! Workflow-task liveness for native runs with a complete, supported history.
use super::{
    Result,
    activity_summary::ActivitySummaryHistory,
    backend::Backend,
    child_summary::ChildSummaryHistory,
    external_wait_summary::ExternalWaitHistory,
    store::{Store, now},
    timer_summary::TimerSummaryHistory,
    transaction::Transaction,
    update_summary::UpdateSummaryHistory,
};
use chrono::{DateTime, Utc};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

pub(super) struct ActiveSummary {
    pub(super) state: String,
    pub(super) reason: String,
    pub(super) task_id: Option<String>,
    pub(super) task_type: Option<String>,
    pub(super) task_status: Option<String>,
    available_at: Option<DateTime<Utc>>,
    lease_expires_at: Option<DateTime<Utc>>,
    pub(super) wait: Option<SummaryWait>,
    pub(super) repair_blocked: Option<&'static str>,
    pub(super) problem: bool,
}

pub(super) struct SummaryWait {
    pub(super) kind: &'static str,
    pub(super) id: String,
    pub(super) reason: String,
    pub(super) started_at: Option<DateTime<Utc>>,
    pub(super) deadline_at: Option<DateTime<Utc>>,
    pub(super) source_kind: &'static str,
    pub(super) source_id: Option<String>,
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
    pub(super) async fn active_task_summary(
        tx: &mut Transaction<'_, DB>,
        run: &DB::Row,
        external_waits: &ExternalWaitHistory,
        timers: &TimerSummaryHistory,
        activities: &ActivitySummaryHistory,
        children: &ChildSummaryHistory,
        updates: &UpdateSummaryHistory,
    ) -> Result<Option<ActiveSummary>> {
        // Compatibility/fleet and task-backed wait classifiers are separate slices.
        // Leave an explicit unavailable summary instead of inventing liveness.
        if DB::optional_string(run, "compatibility")?.is_some_and(|v| !v.trim().is_empty()) {
            return Ok(None);
        }
        let run_id = DB::string(run, "id")?;
        if Self::scalar("SELECT COUNT(*) FROM workflow_run_waits WHERE workflow_run_id=$1 AND status='open' AND kind NOT IN ('condition','signal','timer','activity','child','update')")
            .bind(&run_id).fetch_one(&mut **tx).await? > 0 { return Ok(None); }
        let at = now();
        let mut summary = ActiveSummary {
            state: "repair_needed".into(),
            reason: "Run is non-terminal but has no durable next-resume source.".into(),
            task_id: None,
            task_type: None,
            task_status: None,
            available_at: None,
            lease_expires_at: None,
            wait: None,
            repair_blocked: None,
            problem: Self::scalar("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND repair_count>0").bind(&run_id).fetch_one(&mut **tx).await? > 0,
        };
        let failed = Self::query("SELECT payload,last_error FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status='failed' ORDER BY id LIMIT 101")
            .bind(&run_id).fetch_all(&mut **tx).await?;
        if failed.len() == 101 {
            return Ok(None);
        }
        let mut blocked = None;
        for task in failed {
            let payload = DB::document_row(&task, "payload")?;
            if payload["replay_blocked"].as_bool() == Some(true) {
                if payload["replay_blocked_reason"] != "worker_reported_replay_failure" {
                    return Ok(None);
                }
                blocked = Some(
                    DB::optional_string(&task, "last_error")?
                        .unwrap_or_else(|| "Workflow replay is blocked.".into())
                        .trim()
                        .to_owned(),
                );
                break;
            }
        }
        let task = Self::query("SELECT * FROM workflow_tasks WHERE workflow_run_id=$1 AND status IN ('ready','leased') ORDER BY CASE WHEN status='leased' AND lease_expires_at<=$2 THEN 0 ELSE 1 END,CASE WHEN status='leased' THEN 0 ELSE 1 END,CASE WHEN available_at IS NULL THEN 1 ELSE 0 END,available_at,CASE WHEN created_at IS NULL THEN 1 ELSE 0 END,created_at,id LIMIT 1")
            .bind(&run_id).bind(DB::bind_time(at)).fetch_optional(&mut **tx).await?;
        if let Some(task) = task.as_ref() {
            if !Self::apply_summary_task(&mut summary, &task, at, None)? {
                return Ok(None);
            }
        }
        if let Some(reason) = blocked {
            summary.state = "workflow_replay_blocked".into();
            summary.reason = reason;
            summary.repair_blocked = Some("unsupported_history");
            summary.problem = true;
        }
        // A received signal without a task has its own application-repair
        // classifier. Do not mislabel it as an ordinary external wait.
        if summary.task_id.is_none() && Self::scalar("SELECT COUNT(*) FROM workflow_signal_records WHERE workflow_run_id=$1 AND status='received'")
            .bind(&run_id).fetch_one(&mut **tx).await? > 0 { return Ok(None); }
        if !Self::apply_external_wait_summary(tx, &run_id, external_waits, &mut summary).await? {
            return Ok(None);
        }
        if !Self::apply_timer_summary(tx, &run_id, timers, &mut summary).await? {
            return Ok(None);
        }
        if !Self::apply_activity_summary(tx, &run_id, activities, &mut summary).await? {
            return Ok(None);
        }
        if summary
            .wait
            .as_ref()
            .is_some_and(|wait| wait.kind == "activity")
            && summary.state != "workflow_replay_blocked"
            && let Some(task) = task.as_ref()
        {
            // Published PHP labels the selected task Activity while an activity
            // remains open, including the workflow task from a selection winner.
            let wait = summary.wait.take();
            if !Self::apply_summary_task(&mut summary, task, at, Some("Activity"))? {
                return Ok(None);
            }
            summary.wait = wait;
        }
        if !Self::apply_child_summary(tx, &run_id, children, &mut summary).await? {
            return Ok(None);
        }
        if !Self::apply_update_summary(tx, &run_id, updates, &mut summary).await? {
            return Ok(None);
        }
        if summary.wait.is_none() && summary.task_id.is_none() {
            summary.problem = true;
        }
        Ok(Some(summary))
    }

    pub(super) fn apply_summary_task(
        summary: &mut ActiveSummary,
        task: &DB::Row,
        at: DateTime<Utc>,
        label: Option<&str>,
    ) -> Result<bool> {
        let kind = DB::string(task, "task_type")?;
        if !matches!(kind.as_str(), "workflow" | "timer" | "activity")
            || DB::optional_string(task, "compatibility")?.is_some_and(|v| !v.trim().is_empty())
        {
            return Ok(false);
        }
        let id = DB::string(task, "id")?;
        let status = DB::string(task, "status")?;
        let expires = DB::optional_instant(task, "lease_expires_at")?;
        let transport = TaskTransport::read::<DB>(task, &status, at)?;
        let label = label.unwrap_or(match kind.as_str() {
            "timer" => "Timer",
            "activity" => "Activity",
            _ => "Workflow",
        });
        let json_time = |at: Option<DateTime<Utc>>| {
            at.map_or_else(
                || "an unknown time".to_owned(),
                |at| at.format("%Y-%m-%dT%H:%M:%S%.6fZ").to_string(),
            )
        };
        // PHP's RunSummaryProjector::taskLiveness, in its order.
        (summary.state, summary.reason) = if transport.lease_expired {
            (
                "repair_needed".into(),
                format!("{label} task {id} lease expired at {}.", json_time(expires)),
            )
        } else if let Some(error) = &transport.dispatch_failed {
            (
                "repair_needed".into(),
                format!(
                    "{label} task {id} could not be dispatched at {}. {error}",
                    json_time(transport.last_dispatch_attempt_at)
                ),
            )
        } else if let Some(error) = &transport.claim_failed {
            (
                format!("{kind}_task_claim_failed"),
                format!(
                    "{label} task {id} could not be claimed by a worker at {}. {error}",
                    json_time(transport.last_claim_failed_at)
                ),
            )
        } else if let Some(reference) = transport.dispatch_overdue {
            (
                "repair_needed".into(),
                format!(
                    "{label} task {id} is ready but has not been dispatched since {}.",
                    json_time(Some(reference))
                ),
            )
        } else if status == "leased" {
            (
                format!("{kind}_task_{status}"),
                format!("{label} task {id} is leased to a worker."),
            )
        } else {
            (
                format!("{kind}_task_{status}"),
                format!("{label} task {id} is ready to run."),
            )
        };
        // PHP's wait reason checks the same states in another order.
        let wait_reason = if transport.dispatch_failed.is_some() {
            "Workflow task dispatch failed"
        } else if transport.claim_failed.is_some() {
            "Workflow task claim failed"
        } else if transport.lease_expired {
            "Workflow task lease expired"
        } else if transport.dispatch_overdue.is_some() {
            "Workflow task ready but dispatch is overdue"
        } else if status == "leased" {
            "Workflow task leased to worker"
        } else {
            "Workflow task ready"
        }
        .into();
        summary.available_at = DB::optional_instant(task, "available_at")?;
        summary.wait = (kind == "workflow").then_some(SummaryWait {
            kind: "workflow-task",
            id: format!("workflow-task:{id}"),
            reason: wait_reason,
            started_at: DB::optional_instant(task, "leased_at")?.or(summary.available_at),
            deadline_at: expires,
            source_kind: "workflow_task",
            source_id: Some(id.clone()),
        });
        summary.lease_expires_at = expires;
        summary.task_id = Some(id);
        summary.task_status = Some(status);
        summary.problem |= kind == "workflow" && transport.problem();
        summary.repair_blocked = (summary.state != "repair_needed").then_some("repair_not_needed");
        summary.task_type = Some(kind);
        Ok(true)
    }

    pub(super) async fn write_active_summary_fields(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        summary: &ActiveSummary,
    ) -> Result<()> {
        let wait = summary.wait.as_ref();
        Self::query("UPDATE workflow_run_summaries SET wait_kind=$1,wait_reason=$2,wait_started_at=$3,wait_deadline_at=$4,open_wait_id=$5,resume_source_kind=$6,resume_source_id=$7,next_task_at=$8,next_task_id=$9,next_task_type=$10,next_task_status=$11,next_task_lease_expires_at=$12,repair_attention=CASE WHEN $13=1 THEN TRUE ELSE FALSE END,task_problem=CASE WHEN $14=1 THEN TRUE ELSE FALSE END WHERE id=$15")
            .bind(wait.map(|w| w.kind)).bind(wait.map(|w| w.reason.as_str()))
            .bind(DB::optional_time(wait.and_then(|w| w.started_at))).bind(DB::optional_time(wait.and_then(|w| w.deadline_at))).bind(wait.map(|w| w.id.as_str()))
            .bind(wait.map(|w| w.source_kind)).bind(wait.and_then(|w| w.source_id.as_deref()))
            .bind(DB::optional_time(summary.available_at)).bind(summary.task_id.as_deref()).bind(summary.task_type.as_deref())
            .bind(summary.task_status.as_deref()).bind(DB::optional_time(summary.lease_expires_at))
            .bind(i64::from(summary.repair_blocked == Some("unsupported_history"))).bind(i64::from(summary.problem)).bind(run_id).execute(&mut **tx).await?;
        Ok(())
    }
}

/// PHP's `TaskRepairPolicy` per-task transport predicates.
struct TaskTransport {
    lease_expired: bool,
    /// The trimmed dispatch error when the latest dispatch attempt failed.
    dispatch_failed: Option<String>,
    /// The trimmed claim error when a worker could not claim the task.
    claim_failed: Option<String>,
    /// When a ready task was last made available, if that is past the
    /// redispatch window.
    dispatch_overdue: Option<DateTime<Utc>>,
    last_dispatch_attempt_at: Option<DateTime<Utc>>,
    last_claim_failed_at: Option<DateTime<Utc>>,
}

impl TaskTransport {
    fn read<DB: Backend>(task: &DB::Row, status: &str, at: DateTime<Utc>) -> Result<Self> {
        let ready = status == "ready";
        let error = |field: &str| -> Result<Option<String>> {
            Ok(DB::optional_string(task, field)?
                .map(|error| error.trim().to_owned())
                .filter(|error| !error.is_empty()))
        };
        let attempted = DB::optional_instant(task, "last_dispatch_attempt_at")?;
        let dispatched = DB::optional_instant(task, "last_dispatched_at")?;
        let claim_failed_at = DB::optional_instant(task, "last_claim_failed_at")?;
        let dispatch_failed = match (ready, attempted) {
            (true, Some(attempted))
                if dispatched.is_none_or(|dispatched| attempted > dispatched) =>
            {
                error("last_dispatch_error")?
            }
            _ => None,
        };
        let claim_failed = if ready && claim_failed_at.is_some() {
            error("last_claim_error")?
        } else {
            None
        };
        let available = DB::optional_instant(task, "available_at")?;
        let reference = dispatched.or(DB::optional_instant(task, "created_at")?);
        let cutoff =
            at - chrono::Duration::seconds(super::operator_metrics::REDISPATCH_AFTER_SECONDS);
        let dispatch_overdue = (ready
            && available.is_none_or(|available| available <= at)
            && dispatch_failed.is_none()
            && claim_failed.is_none())
        .then_some(reference)
        .flatten()
        .filter(|reference| *reference <= cutoff);
        Ok(Self {
            lease_expired: status == "leased"
                && DB::optional_instant(task, "lease_expires_at")?
                    .is_some_and(|expires| expires <= at),
            dispatch_failed,
            claim_failed,
            dispatch_overdue,
            last_dispatch_attempt_at: attempted,
            last_claim_failed_at: claim_failed_at,
        })
    }

    /// `workflowTaskProblemEvidence` for this task's transport state.
    fn problem(&self) -> bool {
        self.lease_expired
            || self.dispatch_failed.is_some()
            || self.claim_failed.is_some()
            || self.dispatch_overdue.is_some()
    }
}
