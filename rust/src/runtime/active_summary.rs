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
    ) -> Result<Option<ActiveSummary>> {
        // Compatibility/fleet and task-backed wait classifiers are separate slices.
        // Leave an explicit unavailable summary instead of inventing liveness.
        if DB::optional_string(run, "compatibility")?.is_some_and(|v| !v.trim().is_empty()) {
            return Ok(None);
        }
        let run_id = DB::string(run, "id")?;
        if Self::scalar("SELECT COUNT(*) FROM workflow_run_waits WHERE workflow_run_id=$1 AND status='open' AND kind NOT IN ('condition','signal','timer','activity','child')")
            .bind(&run_id).fetch_one(&mut **tx).await? > 0 { return Ok(None); }
        // These native task transitions do not author queue-driver metadata.
        // Its presence needs the separately qualified transport classifier,
        // including historical task problems and redispatch configuration.
        if Self::scalar("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id=$1 AND (created_at IS NOT NULL OR last_dispatch_attempt_at IS NOT NULL OR last_claim_failed_at IS NOT NULL OR last_dispatched_at IS NOT NULL)")
            .bind(&run_id).fetch_one(&mut **tx).await? > 0 {
            return Ok(None);
        }
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
        if let Some(task) = task {
            if !Self::apply_summary_task(&mut summary, &task, at)? {
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
        if !Self::apply_child_summary(tx, &run_id, children, &mut summary).await? {
            return Ok(None);
        }
        if summary.wait.is_none() && summary.task_id.is_none() {
            summary.problem = true;
        }
        Ok(Some(summary))
    }

    fn apply_summary_task(
        summary: &mut ActiveSummary,
        task: &DB::Row,
        at: DateTime<Utc>,
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
        let expired = status == "leased" && expires.is_some_and(|v| v <= at);
        let label = match kind.as_str() {
            "timer" => "Timer",
            "activity" => "Activity",
            _ => "Workflow",
        };
        summary.state = if expired {
            "repair_needed".into()
        } else {
            format!("{kind}_task_{status}")
        };
        summary.reason = if expired {
            format!(
                "{label} task {id} lease expired at {}.",
                expires.unwrap().format("%Y-%m-%dT%H:%M:%S%.6fZ")
            )
        } else if status == "leased" {
            format!("{label} task {id} is leased to a worker.")
        } else {
            format!("{label} task {id} is ready to run.")
        };
        let wait_reason = if expired {
            "Workflow task lease expired"
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
        summary.problem |= expired && kind == "workflow";
        summary.task_type = Some(kind);
        summary.repair_blocked = (!expired).then_some("repair_not_needed");
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
