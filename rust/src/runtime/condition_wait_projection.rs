//! Project ordinary condition waits in the transaction that authors their history.
use super::{Result, backend::Backend, store::Store};
use chrono::{DateTime, Utc};
use serde_json::Value;
use sha2::{Digest, Sha256};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Transaction, Type};

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
    pub(super) async fn project_condition_wait_event(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        event_type: &str,
        payload: &Value,
        at: DateTime<Utc>,
    ) -> Result<()> {
        if event_type == "ConditionWaitOpened" {
            let wait_id = super::text(payload, "condition_wait_id")?;
            let key = super::text(payload, "condition_key")?;
            let sequence = payload["sequence"]
                .as_i64()
                .filter(|sequence| *sequence > 0)
                .ok_or_else(|| {
                    super::refuse(axum::http::StatusCode::CONFLICT, "invalid_stored_wait")
                })?;
            let projection_id: String = Sha256::digest(format!("{run_id}|{wait_id}"))
                .iter()
                .map(|byte| format!("{byte:02x}"))
                .collect();
            Self::query("INSERT INTO workflow_run_waits(id,workflow_run_id,workflow_instance_id,wait_id,position,kind,sequence,status,source_status,summary,opened_at,target_name,target_type,task_backed,external_only,resume_source_kind,history_authority,created_at,updated_at) SELECT $1,id,workflow_instance_id,$2,$3,'condition',$4,'open','waiting','Waiting for condition.',$5,$6,'condition',FALSE,TRUE,'external_input','typed_history',$7,$8 FROM workflow_runs WHERE id=$9")
                .bind(projection_id).bind(wait_id).bind(sequence - 1).bind(sequence)
                .bind(DB::bind_time(at)).bind(key).bind(DB::bind_time(at)).bind(DB::bind_time(at))
                .bind(run_id).execute(&mut **tx).await?;
        } else if event_type == "ConditionWaitSatisfied" {
            // Receipt alone is not satisfaction. Only the worker's committed
            // application event resolves this occurrence, without read repair.
            let wait_id = super::text(payload, "condition_wait_id")?;
            Self::query("UPDATE workflow_run_waits SET status='resolved',source_status='satisfied',summary='Condition satisfied.',resolved_at=$1,updated_at=$2 WHERE workflow_run_id=$3 AND wait_id=$4 AND kind='condition' AND status='open'")
                .bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(run_id).bind(wait_id)
                .execute(&mut **tx).await?;
        } else {
            let (source_status, summary) = match event_type {
                "WorkflowCancelled" => (
                    "cancelled",
                    "Condition wait ended when the run was cancelled.",
                ),
                "WorkflowTerminated" => (
                    "terminated",
                    "Condition wait ended when the run was terminated.",
                ),
                "WorkflowContinuedAsNew" => (
                    "continued",
                    "Condition wait ended when the run continued as new.",
                ),
                "WorkflowCompleted" | "WorkflowFailed" => {
                    ("closed", "Condition wait ended when the run closed.")
                }
                _ => return Ok(()),
            };
            Self::query("UPDATE workflow_run_waits SET status='cancelled',source_status=$1,summary=$2,resolved_at=$3,updated_at=$4 WHERE workflow_run_id=$5 AND kind='condition' AND status='open'")
                .bind(source_status).bind(summary).bind(DB::bind_time(at)).bind(DB::bind_time(at))
                .bind(run_id).execute(&mut **tx).await?;
        }
        Ok(())
    }
}
