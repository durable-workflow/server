//! Project ordinary timer waits within their scheduling and terminal transactions.
use super::{Result, backend::Backend, store::Store};
use chrono::{DateTime, Utc};
use serde_json::Value;
use sha2::{Digest, Sha256};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use super::transaction::Transaction;

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
    pub(super) async fn create_timer_wait(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        timer_id: &str,
        task_id: &str,
        sequence: i64,
        at: DateTime<Utc>,
        fire_at: DateTime<Utc>,
    ) -> Result<()> {
        // Creation is restricted to a newly authored ordinary timer. Condition
        // and signal timeout tasks retain their own wait identities.
        let wait_id = format!("timer:{timer_id}");
        let projection_id: String = Sha256::digest(format!("{run_id}|{wait_id}"))
            .iter()
            .map(|byte| format!("{byte:02x}"))
            .collect();
        Self::query("INSERT INTO workflow_run_waits(id,workflow_run_id,workflow_instance_id,wait_id,position,kind,sequence,status,source_status,summary,opened_at,deadline_at,target_type,task_backed,external_only,resume_source_kind,resume_source_id,task_id,task_type,task_status,history_authority,created_at,updated_at) SELECT $1,id,workflow_instance_id,$2,$3,'timer',$4,'open','pending','Waiting for timer.',$5,$6,'timer',TRUE,FALSE,'timer',$7,$8,'timer','ready','typed_history',$9,$10 FROM workflow_runs WHERE id=$11")
            .bind(projection_id).bind(wait_id).bind(sequence - 1).bind(sequence)
            .bind(DB::bind_time(at)).bind(DB::bind_time(fire_at)).bind(timer_id).bind(task_id)
            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(run_id)
            .execute(&mut **tx).await?;
        Ok(())
    }

    pub(super) async fn project_timer_wait_event(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        event_type: &str,
        payload: &Value,
        at: DateTime<Utc>,
    ) -> Result<()> {
        let (status, source_status, task_status, summary) = match event_type {
            "TimerFired" => ("resolved", "fired", "completed", "Timer fired."),
            "TimerCancelled" => (
                "cancelled",
                "cancelled",
                "cancelled",
                "Timer wait was cancelled.",
            ),
            _ => return Ok(()),
        };
        let wait_id = format!("timer:{}", super::text(payload, "timer_id")?);
        // An absent retained projection stays absent. Neither terminal events
        // nor observation reads may silently rebuild historical state.
        Self::query("UPDATE workflow_run_waits SET status=$1,source_status=$2,summary=$3,resolved_at=$4,task_backed=FALSE,task_status=$5,updated_at=$6 WHERE workflow_run_id=$7 AND wait_id=$8 AND kind='timer' AND status='open'")
            .bind(status).bind(source_status).bind(summary).bind(DB::bind_time(at))
            .bind(task_status).bind(DB::bind_time(at)).bind(run_id).bind(wait_id)
            .execute(&mut **tx).await?;
        Ok(())
    }
}
