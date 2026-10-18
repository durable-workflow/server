//! Ordinary activity summaries require complete typed history and retained waits.
use super::{
    Result,
    active_summary::{ActiveSummary, SummaryWait},
    backend::Backend,
    store::Store,
    transaction::Transaction,
};
use serde_json::Value;
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::collections::BTreeMap;

#[derive(Default)]
pub(super) struct ActivitySummaryHistory {
    open: BTreeMap<String, ExpectedActivity>,
}

struct ExpectedActivity {
    sequence: i64,
    kind: String,
    status: &'static str,
    attempts: i64,
    attempt_id: Option<String>,
    task_id: Option<String>,
    metadata: Value,
}

impl ActivitySummaryHistory {
    pub(super) fn apply(&mut self, event: &str, payload: &Value) -> bool {
        let snapshot = &payload["activity"];
        let (Some(id), Some(sequence), Some(kind), Some(attempts)) = (
            payload["activity_execution_id"]
                .as_str()
                .filter(|v| !v.is_empty()),
            snapshot["sequence"].as_i64().filter(|v| *v > 0),
            snapshot["type"].as_str().filter(|v| !v.is_empty()),
            snapshot["attempt_count"].as_i64().filter(|v| *v >= 0),
        ) else {
            return false;
        };
        let timeout = payload["timeout_kind"].as_str();
        if event == "ActivityTimedOut" && timeout.is_none() {
            return false;
        }
        if !payload["timeout_kind"].is_null()
            && !matches!(
                timeout,
                Some("schedule_to_start" | "start_to_close" | "schedule_to_close" | "heartbeat")
            )
        {
            return false;
        }
        // Local execution still requires its separately qualified history.
        if snapshot["id"] != id
            || payload["sequence"] != sequence
            || payload["activity_type"] != kind
            || snapshot["local_activity"] == true
            || snapshot["execution_mode"] == "local"
        {
            return false;
        }
        let status = match event {
            "ActivityScheduled" | "ActivityRetryScheduled" => "pending",
            "ActivityStarted" | "ActivityHeartbeatRecorded" => "running",
            "ActivityCompleted" => "completed",
            "ActivityFailed" | "ActivityTimedOut" => "failed",
            "ActivityCancelled" => "cancelled",
            _ => return false,
        };
        if snapshot["status"] != status {
            return false;
        }
        let attempt_id = snapshot["attempt_id"]
            .as_str()
            .filter(|v| !v.is_empty())
            .map(str::to_owned);
        let task_id = if event == "ActivityRetryScheduled" {
            payload["retry_task_id"]
                .as_str()
                .filter(|v| !v.is_empty())
                .map(str::to_owned)
        } else if event == "ActivityStarted" {
            payload["task"]["id"]
                .as_str()
                .filter(|v| !v.is_empty())
                .map(str::to_owned)
        } else {
            None
        };
        let next = ExpectedActivity {
            sequence,
            kind: kind.into(),
            status,
            attempts,
            attempt_id,
            task_id,
            metadata: match super::selections::metadata(payload, "activity") {
                Ok(metadata) => metadata,
                Err(_) => return false,
            },
        };
        if event == "ActivityScheduled" {
            if self.open.len() >= 100
                || self.open.contains_key(id)
                || attempts != 0
                || next.attempt_id.is_some()
            {
                return false;
            }
        } else {
            let Some(previous) = self.open.get(id) else {
                return false;
            };
            if !next.follows(previous, event, timeout) {
                return false;
            }
        }
        if matches!(status, "completed" | "failed" | "cancelled") {
            self.open.remove(id);
        } else {
            let task_id = next
                .task_id
                .clone()
                .or_else(|| self.open.get(id).and_then(|v| v.task_id.clone()));
            self.open
                .insert(id.into(), ExpectedActivity { task_id, ..next });
        }
        true
    }
}

impl ExpectedActivity {
    fn follows(&self, previous: &Self, event: &str, timeout: Option<&str>) -> bool {
        if self.sequence != previous.sequence
            || self.kind != previous.kind
            || self.metadata != previous.metadata
        {
            return false;
        }
        if event == "ActivityStarted" {
            if Some(self.attempts) != previous.attempts.checked_add(1)
                || self.attempt_id.is_none()
                || self.task_id.is_none()
            {
                return false;
            }
        } else if self.attempts != previous.attempts || self.attempt_id != previous.attempt_id {
            return false;
        }
        if matches!(
            event,
            "ActivityHeartbeatRecorded"
                | "ActivityRetryScheduled"
                | "ActivityCompleted"
                | "ActivityFailed"
        ) && previous.status != "running"
            && !(event == "ActivityRetryScheduled"
                && timeout == Some("schedule_to_start")
                && previous.status == "pending")
        {
            return false;
        }
        event != "ActivityRetryScheduled" || self.task_id.is_some()
    }
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
    pub(super) async fn apply_activity_summary(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        history: &ActivitySummaryHistory,
        summary: &mut ActiveSummary,
    ) -> Result<bool> {
        let rows = Self::query("SELECT w.*,a.id AS activity_id,a.sequence AS activity_sequence,a.activity_type,a.status AS activity_status,a.attempt_count,a.current_attempt_id,a.parallel_group_path AS activity_group_path,q.id AS matched_task_id,q.task_type AS matched_task_type,q.payload AS matched_task_payload FROM workflow_run_waits w LEFT JOIN activity_executions a ON a.id=w.resume_source_id AND a.workflow_run_id=w.workflow_run_id LEFT JOIN workflow_tasks q ON q.id=w.task_id AND q.workflow_run_id=w.workflow_run_id WHERE w.workflow_run_id=$1 AND w.kind='activity' AND w.status='open' ORDER BY w.sequence,w.opened_at,w.resume_source_id LIMIT 101")
            .bind(run_id).fetch_all(&mut **tx).await?;
        if rows.len() != history.open.len() || rows.len() > 100 {
            return Ok(false);
        }
        let mut selected_activity_task = false;
        for row in &rows {
            let Some(id) = DB::optional_string(row, "activity_id")? else {
                return Ok(false);
            };
            let Some(expected) = history.open.get(&id) else {
                return Ok(false);
            };
            let task_id = DB::optional_string(row, "task_id")?;
            if DB::string(row, "wait_id")? != format!("activity:{id}")
                || DB::optional_number(row, "sequence")? != Some(expected.sequence)
                || DB::optional_number(row, "activity_sequence")? != Some(expected.sequence)
                || DB::optional_string(row, "activity_type")?.as_deref()
                    != Some(expected.kind.as_str())
                || DB::optional_string(row, "target_type")?.as_deref()
                    != Some(expected.kind.as_str())
                || DB::optional_string(row, "activity_status")?.as_deref() != Some(expected.status)
                || DB::optional_string(row, "source_status")?.as_deref() != Some(expected.status)
                || DB::optional_number(row, "attempt_count")? != Some(expected.attempts)
                || DB::optional_string(row, "current_attempt_id")? != expected.attempt_id
                || super::selections::activity_metadata(DB::optional_document_row(
                    row,
                    "activity_group_path",
                )?)
                .ok()
                .as_ref()
                    != Some(&expected.metadata)
                || DB::optional_string(row, "history_authority")?.as_deref()
                    != Some("typed_history")
                || DB::optional_string(row, "history_unsupported_reason")?.is_some()
                || DB::optional_string(row, "resume_source_kind")?.as_deref()
                    != Some("activity_execution")
                || task_id.is_none()
                || (expected.task_id.is_some() && task_id != expected.task_id)
            {
                return Ok(false);
            }
            if DB::optional_string(row, "matched_task_id")?.is_some()
                && (DB::optional_string(row, "matched_task_type")?.as_deref() != Some("activity")
                    || DB::document_row(row, "matched_task_payload")?["activity_execution_id"]
                        .as_str()
                        != Some(id.as_str()))
            {
                return Ok(false);
            }
            selected_activity_task |= task_id == summary.task_id;
        }
        if summary.task_type.as_deref() == Some("activity") && !selected_activity_task {
            return Ok(false);
        }
        let Some(first) = rows.first() else {
            return Ok(true);
        };
        // Concurrent open families and a different selected task need their
        // own precedence controls before this classifier can advertise them.
        if !history
            .open
            .values()
            .any(|activity| activity.metadata["parallel_group_id"].is_string())
            && (summary.wait.is_some()
                || summary
                    .task_type
                    .as_deref()
                    .is_some_and(|kind| kind != "activity"))
        {
            return Ok(false);
        }
        let id = DB::string(first, "activity_id")?;
        summary.wait = Some(SummaryWait {
            kind: "activity",
            id: format!("activity:{id}"),
            reason: format!(
                "Waiting for activity {}",
                DB::string(first, "activity_type")?
            ),
            started_at: DB::optional_instant(first, "opened_at")?,
            deadline_at: None,
            source_kind: "activity_execution",
            source_id: Some(id.clone()),
        });
        if summary.task_id.is_none() && summary.state != "workflow_replay_blocked" {
            let status = DB::string(first, "activity_status")?;
            (summary.state, summary.reason) = if status == "running" {
                (
                    "activity_running_without_task".into(),
                    format!(
                        "Activity {id} is already running without an open activity task. Repair is deferred to avoid duplicating in-flight work."
                    ),
                )
            } else {
                (
                    "repair_needed".into(),
                    format!("Activity {id} is {status} without an open activity task."),
                )
            };
            summary.repair_blocked = (status == "running").then_some("repair_not_needed");
        }
        Ok(true)
    }
}
