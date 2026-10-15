//! Maintain activity waits inside the transaction that records their history.
use super::transaction::Transaction;
use super::{Result, backend::Backend, store::Store};
use chrono::{DateTime, Utc};
use serde_json::Value;
use sha2::{Digest, Sha256};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

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
    pub(super) async fn create_activity_wait(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        activity_id: &str,
        task_id: &str,
        at: DateTime<Utc>,
    ) -> Result<()> {
        // Only the authored ActivityScheduled transition creates this row.
        // Reads and later events never repair an absent projection implicitly.
        let activity = Self::query("SELECT a.sequence,a.activity_type,r.workflow_instance_id FROM activity_executions a JOIN workflow_runs r ON r.id=a.workflow_run_id WHERE a.id=$1 AND a.workflow_run_id=$2")
            .bind(activity_id).bind(run_id).fetch_one(&mut **tx).await?;
        let wait_id = format!("activity:{activity_id}");
        let projection_id: String = Sha256::digest(format!("{run_id}|{wait_id}"))
            .iter()
            .map(|byte| format!("{byte:02x}"))
            .collect();
        let sequence = DB::number(&activity, "sequence")?;
        let activity_type = DB::string(&activity, "activity_type")?;
        Self::query("INSERT INTO workflow_run_waits(id,workflow_run_id,workflow_instance_id,wait_id,position,kind,sequence,status,source_status,summary,opened_at,target_type,task_backed,external_only,resume_source_kind,resume_source_id,task_id,task_type,task_status,history_authority,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,'activity',$6,'open','pending',$7,$8,$9,TRUE,FALSE,'activity_execution',$10,$11,'activity','ready','typed_history',$12,$13)")
            .bind(projection_id).bind(run_id).bind(DB::string(&activity, "workflow_instance_id")?)
            .bind(wait_id).bind(sequence - 1).bind(sequence)
            .bind(format!("Waiting for activity {activity_type}."))
            .bind(DB::bind_time(at)).bind(activity_type).bind(activity_id).bind(task_id)
            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).execute(&mut **tx).await?;
        Ok(())
    }

    pub(super) async fn project_activity_wait_event(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        event_type: &str,
        payload: &Value,
        at: DateTime<Utc>,
    ) -> Result<()> {
        if !matches!(
            event_type,
            "ActivityStarted"
                | "ActivityRetryScheduled"
                | "ActivityCompleted"
                | "ActivityFailed"
                | "ActivityTimedOut"
                | "ActivityCancelled"
        ) {
            return Ok(());
        }
        let activity_id = super::text(payload, "activity_execution_id")?;
        let wait_id = format!("activity:{activity_id}");
        if event_type == "ActivityRetryScheduled" {
            let task_id = super::text(payload, "retry_task_id")?;
            Self::query("UPDATE workflow_run_waits SET source_status='pending',task_id=$1,task_type='activity',task_status='ready',task_backed=TRUE,updated_at=$2 WHERE workflow_run_id=$3 AND wait_id=$4 AND status='open'")
                .bind(task_id).bind(DB::bind_time(at)).bind(run_id).bind(wait_id).execute(&mut **tx).await?;
        } else if event_type == "ActivityStarted" {
            Self::query("UPDATE workflow_run_waits SET source_status='running',task_status='leased',opened_at=$1,updated_at=$2 WHERE workflow_run_id=$3 AND wait_id=$4 AND status='open'")
                .bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(run_id).bind(wait_id).execute(&mut **tx).await?;
        } else {
            let activity_type = super::text(payload, "activity_type")?;
            let (status, source_status, summary) = match event_type {
                "ActivityCancelled" => (
                    "cancelled",
                    "cancelled",
                    format!("Activity wait for {activity_type} was cancelled."),
                ),
                "ActivityCompleted" => (
                    "resolved",
                    "completed",
                    format!("Activity {activity_type} completed."),
                ),
                _ => (
                    "resolved",
                    "failed",
                    format!("Activity {activity_type} failed."),
                ),
            };
            Self::query("UPDATE workflow_run_waits SET status=$1,source_status=$2,summary=$3,resolved_at=$4,task_backed=FALSE,task_status=$5,updated_at=$6 WHERE workflow_run_id=$7 AND wait_id=$8 AND status='open'")
                .bind(status).bind(source_status).bind(summary).bind(DB::bind_time(at))
                .bind(if event_type == "ActivityCancelled" { "cancelled" } else { "completed" })
                .bind(DB::bind_time(at)).bind(run_id).bind(wait_id).execute(&mut **tx).await?;
        }
        Ok(())
    }
}
