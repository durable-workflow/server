//! Workflow-task liveness for native runs with a complete, supported history.
use super::{Result, backend::Backend, store::{Store, now}, transaction::Transaction};
use chrono::{DateTime, Utc};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

pub(super) struct ActiveSummary {
    pub(super) state: String,
    pub(super) reason: String,
    task_id: Option<String>,
    task_status: Option<String>,
    available_at: Option<DateTime<Utc>>,
    wait_started_at: Option<DateTime<Utc>>,
    lease_expires_at: Option<DateTime<Utc>>,
    wait_reason: Option<String>,
    pub(super) repair_blocked: Option<&'static str>,
    problem: bool,
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
        tx: &mut Transaction<'_, DB>, run: &DB::Row,
    ) -> Result<Option<ActiveSummary>> {
        // Compatibility/fleet and durable wait classifiers are separate slices.
        // Leave an explicit unavailable summary instead of inventing liveness.
        if DB::optional_string(run, "compatibility")?.is_some_and(|v| !v.trim().is_empty()) {
            return Ok(None);
        }
        let run_id = DB::string(run, "id")?;
        let at = now();
        let mut summary = ActiveSummary {
            state: "repair_needed".into(),
            reason: "Run is non-terminal but has no durable next-resume source.".into(),
            task_id: None, task_status: None, available_at: None, wait_started_at: None,
            lease_expires_at: None, wait_reason: None, repair_blocked: None, problem: true,
        };
        let failed = Self::query("SELECT payload,last_error FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status='failed' ORDER BY id LIMIT 101")
            .bind(&run_id).fetch_all(&mut **tx).await?;
        if failed.len() == 101 { return Ok(None); }
        let mut blocked = None;
        for task in failed {
            let payload = DB::document_row(&task, "payload")?;
            if payload["replay_blocked"].as_bool() == Some(true) {
                if payload["replay_blocked_reason"] != "worker_reported_replay_failure" { return Ok(None); }
                blocked = Some(DB::optional_string(&task, "last_error")?.unwrap_or_else(|| "Workflow replay is blocked.".into()).trim().to_owned());
                break;
            }
        }
        let task = Self::query("SELECT * FROM workflow_tasks WHERE workflow_run_id=$1 AND status IN ('ready','leased') ORDER BY CASE WHEN status='leased' AND lease_expires_at<=$2 THEN 0 ELSE 1 END,CASE WHEN status='leased' THEN 0 ELSE 1 END,CASE WHEN available_at IS NULL THEN 1 ELSE 0 END,available_at,CASE WHEN created_at IS NULL THEN 1 ELSE 0 END,created_at,id LIMIT 1")
            .bind(&run_id).bind(DB::bind_time(at)).fetch_optional(&mut **tx).await?;
        if let Some(task) = task {
            if DB::string(&task, "task_type")? != "workflow"
                || DB::optional_string(&task, "compatibility")?.is_some_and(|v| !v.trim().is_empty())
                || DB::optional_instant(&task, "last_dispatch_attempt_at")?.is_some()
                || DB::optional_instant(&task, "last_claim_failed_at")?.is_some()
                || DB::optional_instant(&task, "last_dispatched_at")?.is_some()
            { return Ok(None); }
            let id = DB::string(&task, "id")?;
            let status = DB::string(&task, "status")?;
            let expires = DB::optional_instant(&task, "lease_expires_at")?;
            let expired = status == "leased" && expires.is_some_and(|v| v <= at);
            summary.state = if expired { "repair_needed".into() } else { format!("workflow_task_{status}") };
            summary.reason = if expired {
                format!("Workflow task {id} lease expired at {}.", expires.unwrap().format("%Y-%m-%dT%H:%M:%S%.6fZ"))
            } else if status == "leased" {
                format!("Workflow task {id} is leased to a worker.")
            } else { format!("Workflow task {id} is ready to run.") };
            summary.wait_reason = Some(if expired { "Workflow task lease expired" } else if status == "leased" { "Workflow task leased to worker" } else { "Workflow task ready" }.into());
            summary.available_at = DB::optional_instant(&task, "available_at")?;
            summary.wait_started_at = DB::optional_instant(&task, "leased_at")?.or(summary.available_at);
            summary.lease_expires_at = expires;
            summary.task_id = Some(id);
            summary.task_status = Some(status);
            summary.problem = expired || Self::scalar("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND repair_count>0").bind(&run_id).fetch_one(&mut **tx).await? > 0;
            summary.repair_blocked = (!expired).then_some("repair_not_needed");
        }
        if let Some(reason) = blocked {
            summary.state = "workflow_replay_blocked".into();
            summary.reason = reason;
            summary.repair_blocked = Some("unsupported_history");
            summary.problem = true;
        }
        Ok(Some(summary))
    }

    pub(super) async fn write_active_summary_fields(tx: &mut Transaction<'_, DB>, run_id: &str, summary: &ActiveSummary) -> Result<()> {
        let wait_id = summary.task_id.as_ref().map(|id| format!("workflow-task:{id}"));
        Self::query("UPDATE workflow_run_summaries SET wait_kind=$1,wait_reason=$2,wait_started_at=$3,wait_deadline_at=$4,open_wait_id=$5,resume_source_kind=$6,resume_source_id=$7,next_task_at=$8,next_task_id=$9,next_task_type=$10,next_task_status=$11,next_task_lease_expires_at=$12,repair_attention=CASE WHEN $13=1 THEN TRUE ELSE FALSE END,task_problem=CASE WHEN $14=1 THEN TRUE ELSE FALSE END WHERE id=$15")
            .bind(summary.task_id.as_ref().map(|_| "workflow-task")).bind(summary.wait_reason.as_deref())
            .bind(DB::optional_time(summary.wait_started_at)).bind(DB::optional_time(summary.lease_expires_at)).bind(wait_id.as_deref())
            .bind(summary.task_id.as_ref().map(|_| "workflow_task")).bind(summary.task_id.as_deref())
            .bind(DB::optional_time(summary.available_at)).bind(summary.task_id.as_deref()).bind(summary.task_id.as_ref().map(|_| "workflow"))
            .bind(summary.task_status.as_deref()).bind(DB::optional_time(summary.lease_expires_at))
            .bind(i64::from(summary.repair_blocked == Some("unsupported_history"))).bind(i64::from(summary.problem)).bind(run_id).execute(&mut **tx).await?;
        Ok(())
    }
}
