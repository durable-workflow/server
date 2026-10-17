//! Maintain durable waits in the transactions that author their history.
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
    pub(super) async fn order_open_waits(tx: &mut Transaction<'_, DB>, run: &str) -> Result<()> {
        let mut position = 0_i64;
        loop {
            // Match the published projector's open-wait order without
            // rebuilding missing rows or hydrating the complete collection.
            let rows = Self::query("SELECT id FROM workflow_run_waits WHERE workflow_run_id=$1 AND status='open' ORDER BY CASE WHEN opened_at IS NULL THEN 1 ELSE 0 END,opened_at,wait_id LIMIT 100 OFFSET $2")
                .bind(run).bind(position).fetch_all(&mut **tx).await?;
            if rows.is_empty() {
                break;
            }
            for row in rows {
                Self::query(
                    "UPDATE workflow_run_waits SET position=$1 WHERE id=$2 AND workflow_run_id=$3",
                )
                .bind(position)
                .bind(DB::string(&row, "id")?)
                .bind(run)
                .execute(&mut **tx)
                .await?;
                position += 1;
            }
        }
        Ok(())
    }

    pub(super) async fn project_update_wait_event(
        tx: &mut Transaction<'_, DB>,
        run: &str,
        kind: &str,
        payload: &Value,
        at: DateTime<Utc>,
    ) -> Result<()> {
        if !matches!(kind, "UpdateAccepted" | "UpdateCompleted") {
            return Ok(());
        }
        let update = super::text(payload, "update_id")?;
        let name = super::text(payload, "update_name")?;
        let wait = format!("update:{update}");
        if kind == "UpdateAccepted" {
            let sequence = wait_sequence(&payload["command"])?;
            Self::query("INSERT INTO workflow_run_waits(id,workflow_run_id,workflow_instance_id,wait_id,position,kind,status,source_status,summary,opened_at,target_name,target_type,task_backed,external_only,resume_source_kind,resume_source_id,command_id,command_sequence,command_status,history_authority,created_at,updated_at) SELECT $1,id,workflow_instance_id,$2,$3,'update','open','accepted',$4,$5,$6,'update',FALSE,FALSE,'workflow_update',$7,$8,$9,'accepted','typed_history',$10,$11 FROM workflow_runs WHERE id=$12")
                .bind(projection_id(run, &wait)).bind(wait).bind(sequence - 1).bind(format!("Waiting for update {name}."))
                .bind(DB::bind_time(at)).bind(name).bind(update).bind(super::text(payload, "workflow_command_id")?)
                .bind(sequence).bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(run).execute(&mut **tx).await?;
        } else {
            let failed = payload["failure_id"]
                .as_str()
                .is_some_and(|id| !id.is_empty());
            let status = if failed { "failed" } else { "completed" };
            // Resolution updates only the admitted row. Missing historical
            // projections never reappear as a side effect of observation.
            Self::query("UPDATE workflow_run_waits SET sequence=$1,status='resolved',source_status=$2,summary=$3,resolved_at=$4,task_backed=FALSE,task_id=NULL,task_type=NULL,task_status=NULL,command_outcome=$5,updated_at=$6 WHERE workflow_run_id=$7 AND wait_id=$8 AND kind='update' AND status='open'")
                .bind(wait_sequence(payload)?).bind(status).bind(format!("Update {name} {status}."))
                .bind(DB::bind_time(at)).bind(format!("update_{status}")).bind(DB::bind_time(at)).bind(run).bind(wait).execute(&mut **tx).await?;
        }
        Ok(())
    }

    pub(super) async fn project_child_wait_event(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        event_type: &str,
        payload: &Value,
        at: DateTime<Utc>,
    ) -> Result<()> {
        if !matches!(
            event_type,
            "ChildWorkflowScheduled" | "ChildRunCompleted" | "ChildRunCancelled"
        ) {
            return Ok(());
        }
        let wait_id = format!("child:{}", super::text(payload, "child_call_id")?);
        let label = super::text(payload, "child_workflow_type")?;
        if event_type == "ChildWorkflowScheduled" {
            let sequence = wait_sequence(payload)?;
            Self::query("INSERT INTO workflow_run_waits(id,workflow_run_id,workflow_instance_id,wait_id,position,kind,sequence,status,source_status,summary,opened_at,target_name,target_type,task_backed,external_only,resume_source_kind,resume_source_id,history_authority,created_at,updated_at) SELECT $1,id,workflow_instance_id,$2,$3,'child',$4,'open','waiting',$5,$6,$7,$8,FALSE,FALSE,'child_workflow_run',$9,'typed_history',$10,$11 FROM workflow_runs WHERE id=$12")
                .bind(projection_id(run_id, &wait_id)).bind(wait_id).bind(sequence-1).bind(sequence)
                .bind(format!("Waiting for child workflow {label}."))
                .bind(DB::bind_time(at)).bind(super::text(payload,"child_workflow_instance_id")?).bind(label)
                .bind(super::text(payload,"child_workflow_run_id")?).bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(run_id)
                .execute(&mut **tx).await?;
        } else {
            let cancelled = event_type == "ChildRunCancelled";
            let source_status = if cancelled { "cancelled" } else { "completed" };
            // Only scheduling creates a row. Resolution cannot repair a lost projection.
            Self::query("UPDATE workflow_run_waits SET status=$1,source_status=$2,summary=$3,resolved_at=$4,updated_at=$5 WHERE workflow_run_id=$6 AND wait_id=$7 AND kind='child' AND status='open'")
                .bind(if cancelled { "cancelled" } else { "resolved" }).bind(source_status)
                .bind(format!("Child workflow {label} {source_status}."))
                .bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(run_id).bind(wait_id).execute(&mut **tx).await?;
        }
        Ok(())
    }

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
        let projection_id = projection_id(run_id, &wait_id);
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
        let projection_id = projection_id(run_id, &wait_id);
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
            let sequence = wait_sequence(payload)?;
            let projection_id = projection_id(run_id, wait_id);
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
            Self::close_external_wait(tx, run_id, event_type, "condition", "Condition", at).await?;
        }
        Ok(())
    }

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
            let sequence = wait_sequence(payload)?;
            let projection_id = projection_id(run_id, wait_id);
            let summary = if let Some(seconds) = payload["timeout_seconds"].as_i64() {
                format!(
                    "Waiting for signal {name} or timeout after {}.",
                    signal_duration(seconds)
                )
            } else {
                format!("Waiting for signal {name}.")
            };
            Self::query("INSERT INTO workflow_run_waits(id,workflow_run_id,workflow_instance_id,wait_id,position,kind,sequence,status,source_status,summary,opened_at,target_name,task_backed,external_only,resume_source_kind,history_authority,created_at,updated_at) SELECT $1,id,workflow_instance_id,$2,$3,'signal',$4,'open','waiting',$5,$6,$7,FALSE,TRUE,'signal','typed_history',$8,$9 FROM workflow_runs WHERE id=$10")
                .bind(projection_id).bind(wait_id).bind(sequence - 1).bind(sequence)
                .bind(summary)
                .bind(DB::bind_time(at)).bind(name).bind(DB::bind_time(at)).bind(DB::bind_time(at))
                .bind(run_id).execute(&mut **tx).await?;
        } else if payload["timer_kind"] == "signal_timeout"
            && matches!(
                event_type,
                "TimerScheduled" | "TimerFired" | "TimerCancelled"
            )
        {
            let wait_id = super::text(payload, "signal_wait_id")?;
            let name = super::text(payload, "signal_name")?;
            if event_type == "TimerScheduled" {
                let fire_at = payload["fire_at"]
                    .as_str()
                    .and_then(|value| DateTime::parse_from_rfc3339(value).ok())
                    .map(|value| value.with_timezone(&Utc))
                    .ok_or_else(|| {
                        super::refuse(axum::http::StatusCode::CONFLICT, "invalid_stored_wait")
                    })?;
                let delay = payload["delay_seconds"].as_i64().unwrap_or(0);
                Self::query("UPDATE workflow_run_waits SET deadline_at=$1,resume_source_kind='timer',resume_source_id=$2,external_only=FALSE,summary=$3,updated_at=$4 WHERE workflow_run_id=$5 AND wait_id=$6 AND kind='signal' AND status='open'")
                    .bind(DB::bind_time(fire_at)).bind(super::text(payload,"timer_id")?)
                    .bind(format!("Waiting for signal {name} or timeout after {}.", signal_duration(delay)))
                    .bind(DB::bind_time(at)).bind(run_id).bind(wait_id).execute(&mut **tx).await?;
            } else {
                let fired = event_type == "TimerFired";
                // A receipt has already resolved the visible wait, but its
                // associated timer task still transitions on fire/cancellation.
                Self::query("UPDATE workflow_run_waits SET task_backed=FALSE,task_status=$1,updated_at=$2 WHERE workflow_run_id=$3 AND wait_id=$4 AND kind='signal' AND resume_source_id=$5")
                    .bind(if fired {"completed"} else {"cancelled"}).bind(DB::bind_time(at)).bind(run_id).bind(wait_id)
                    .bind(super::text(payload,"timer_id")?).execute(&mut **tx).await?;
                let summary = if fired {
                    format!(
                        "Signal {name} timed out after {}.",
                        signal_duration(payload["delay_seconds"].as_i64().unwrap_or(0))
                    )
                } else {
                    format!("Signal {name} timeout was cancelled.")
                };
                Self::query("UPDATE workflow_run_waits SET status=$1,source_status=$2,summary=$3,resolved_at=$4,task_backed=FALSE,task_status=$5,updated_at=$6 WHERE workflow_run_id=$7 AND wait_id=$8 AND kind='signal' AND status='open'")
                    .bind(if fired {"resolved"} else {"cancelled"}).bind(if fired {"timed_out"} else {"timeout_cancelled"})
                    .bind(summary).bind(DB::bind_time(at)).bind(if fired {"completed"} else {"cancelled"})
                    .bind(DB::bind_time(at)).bind(run_id).bind(wait_id).execute(&mut **tx).await?;
            }
        } else if matches!(event_type, "SignalReceived" | "SignalApplied") {
            let wait_id = super::text(payload, "signal_wait_id")?;
            let name = super::text(payload, "signal_name")?;
            let command_id = super::text(payload, "workflow_command_id")?;
            let command = Self::query("SELECT command_sequence,status,outcome FROM workflow_commands WHERE id=$1 AND workflow_run_id=$2")
                .bind(command_id).bind(run_id).fetch_one(&mut **tx).await?;
            let outcome = DB::optional_string(&command, "outcome")?;
            // A buffered receipt can precede the opening event. Application then
            // resolves that authored wait. Neither event creates a missing row.
            Self::query("UPDATE workflow_run_waits SET status='resolved',source_status=$1,summary=$2,resolved_at=$3,resume_source_id=CASE WHEN resume_source_kind='timer' THEN resume_source_id ELSE $4 END,command_id=$5,command_sequence=$6,command_status=$7,command_outcome=$8,updated_at=$9 WHERE workflow_run_id=$10 AND wait_id=$11 AND kind='signal' AND (status='open' OR (status='resolved' AND source_status='received'))")
                .bind(if event_type == "SignalApplied" { "applied" } else { "received" })
                .bind(format!("Signal {name} received.")).bind(DB::bind_time(at))
                .bind(command_id).bind(command_id).bind(DB::number(&command, "command_sequence")?)
                .bind(DB::string(&command, "status")?).bind(outcome.as_deref())
                .bind(DB::bind_time(at)).bind(run_id).bind(wait_id).execute(&mut **tx).await?;
        } else {
            Self::close_external_wait(tx, run_id, event_type, "signal", "Signal", at).await?;
        }
        Ok(())
    }
    async fn close_external_wait(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        event_type: &str,
        kind: &str,
        label: &str,
        at: DateTime<Utc>,
    ) -> Result<()> {
        let (status, ending) = match event_type {
            "WorkflowCancelled" => ("cancelled", "was cancelled"),
            "WorkflowTerminated" => ("terminated", "was terminated"),
            "WorkflowContinuedAsNew" => ("continued", "continued as new"),
            "WorkflowCompleted" | "WorkflowFailed" => ("closed", "closed"),
            _ => return Ok(()),
        };
        let summary = format!("{label} wait ended when the run {ending}.");
        Self::query("UPDATE workflow_run_waits SET status='cancelled',source_status=$1,summary=$2,resolved_at=$3,updated_at=$4 WHERE workflow_run_id=$5 AND kind=$6 AND status='open'")
            .bind(status).bind(summary).bind(DB::bind_time(at)).bind(DB::bind_time(at))
            .bind(run_id).bind(kind).execute(&mut **tx).await?;
        Ok(())
    }
}
fn signal_duration(seconds: i64) -> String {
    format!("{seconds} second{}", if seconds == 1 { "" } else { "s" })
}
fn projection_id(run_id: &str, wait_id: &str) -> String {
    Sha256::digest(format!("{run_id}|{wait_id}"))
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect()
}

fn wait_sequence(payload: &Value) -> Result<i64> {
    payload["sequence"]
        .as_i64()
        .filter(|sequence| *sequence > 0)
        .ok_or_else(|| super::refuse(axum::http::StatusCode::CONFLICT, "invalid_stored_wait"))
}
