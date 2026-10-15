//! Keep ordinary signal waits aligned with committed receipt and application.
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
    pub(super) async fn project_signal_wait_event(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        event_type: &str,
        payload: &Value,
        at: DateTime<Utc>,
    ) -> Result<()> {
        if event_type == "SignalWaitOpened" {
            let wait_id = super::text(payload, "signal_wait_id")?;
            let name = super::text(payload, "signal_name")?;
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
            Self::query("INSERT INTO workflow_run_waits(id,workflow_run_id,workflow_instance_id,wait_id,position,kind,sequence,status,source_status,summary,opened_at,target_name,task_backed,external_only,resume_source_kind,history_authority,created_at,updated_at) SELECT $1,id,workflow_instance_id,$2,$3,'signal',$4,'open','waiting',$5,$6,$7,FALSE,TRUE,'signal','typed_history',$8,$9 FROM workflow_runs WHERE id=$10")
                .bind(projection_id).bind(wait_id).bind(sequence - 1).bind(sequence)
                .bind(format!("Waiting for signal {name}."))
                .bind(DB::bind_time(at)).bind(name).bind(DB::bind_time(at)).bind(DB::bind_time(at))
                .bind(run_id).execute(&mut **tx).await?;
        } else if matches!(event_type, "SignalReceived" | "SignalApplied") {
            let wait_id = super::text(payload, "signal_wait_id")?;
            let name = super::text(payload, "signal_name")?;
            let command_id = super::text(payload, "workflow_command_id")?;
            let command = Self::query("SELECT command_sequence,status,outcome FROM workflow_commands WHERE id=$1 AND workflow_run_id=$2")
                .bind(command_id).bind(run_id).fetch_one(&mut **tx).await?;
            let outcome = DB::optional_string(&command, "outcome")?;
            // A buffered receipt can precede the opening event. Application then
            // resolves that authored wait. Neither event creates a missing row.
            Self::query("UPDATE workflow_run_waits SET status='resolved',source_status=$1,summary=$2,resolved_at=$3,resume_source_id=$4,command_id=$5,command_sequence=$6,command_status=$7,command_outcome=$8,updated_at=$9 WHERE workflow_run_id=$10 AND wait_id=$11 AND kind='signal' AND (status='open' OR (status='resolved' AND source_status='received'))")
                .bind(if event_type == "SignalApplied" { "applied" } else { "received" })
                .bind(format!("Signal {name} received.")).bind(DB::bind_time(at))
                .bind(command_id).bind(command_id).bind(DB::number(&command, "command_sequence")?)
                .bind(DB::string(&command, "status")?).bind(outcome.as_deref())
                .bind(DB::bind_time(at)).bind(run_id).bind(wait_id).execute(&mut **tx).await?;
        } else {
            let (source_status, summary) = match event_type {
                "WorkflowCancelled" => {
                    ("cancelled", "Signal wait ended when the run was cancelled.")
                }
                "WorkflowTerminated" => (
                    "terminated",
                    "Signal wait ended when the run was terminated.",
                ),
                "WorkflowContinuedAsNew" => (
                    "continued",
                    "Signal wait ended when the run continued as new.",
                ),
                "WorkflowCompleted" | "WorkflowFailed" => {
                    ("closed", "Signal wait ended when the run closed.")
                }
                _ => return Ok(()),
            };
            Self::query("UPDATE workflow_run_waits SET status='cancelled',source_status=$1,summary=$2,resolved_at=$3,updated_at=$4 WHERE workflow_run_id=$5 AND kind='signal' AND status='open'")
                .bind(source_status).bind(summary).bind(DB::bind_time(at)).bind(DB::bind_time(at))
                .bind(run_id).execute(&mut **tx).await?;
        }
        Ok(())
    }
}
