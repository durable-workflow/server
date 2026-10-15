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
        // Local, parallel and timeout paths retain their separate qualification.
        if snapshot["id"] != id
            || payload["sequence"] != sequence
            || payload["activity_type"] != kind
            || snapshot["local_activity"] == true
            || snapshot["execution_mode"] == "local"
            || !payload["parallel_group_id"].is_null()
            || !payload["timeout_kind"].is_null()
            || event == "ActivityTimedOut"
        {
            return false;
        }
        let status = match event {
            "ActivityScheduled" | "ActivityRetryScheduled" => "pending",
            "ActivityStarted" | "ActivityHeartbeatRecorded" => "running",
            "ActivityCompleted" => "completed",
            "ActivityFailed" => "failed",
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
        if event == "ActivityScheduled" {
            if self.open.len() >= 100
                || self.open.contains_key(id)
                || attempts != 0
                || attempt_id.is_some()
            {
                return false;
            }
        } else {
            let Some(previous) = self.open.get(id) else {
                return false;
            };
            if sequence != previous.sequence || kind != previous.kind {
                return false;
            }
            if event == "ActivityStarted" {
                if attempts != previous.attempts + 1 || attempt_id.is_none() || task_id.is_none() {
                    return false;
                }
            } else if attempts != previous.attempts || attempt_id != previous.attempt_id {
                return false;
            }
            if matches!(
                event,
                "ActivityHeartbeatRecorded"
                    | "ActivityRetryScheduled"
                    | "ActivityCompleted"
                    | "ActivityFailed"
            ) && previous.status != "running"
            {
                return false;
            }
            if event == "ActivityRetryScheduled" && task_id.is_none() {
                return false;
            }
        }
        if matches!(status, "completed" | "failed" | "cancelled") {
            self.open.remove(id);
        } else {
            let task_id = task_id.or_else(|| self.open.get(id).and_then(|v| v.task_id.clone()));
            self.open.insert(
                id.into(),
                ExpectedActivity {
                    sequence,
                    kind: kind.into(),
                    status,
                    attempts,
                    attempt_id,
                    task_id,
                },
            );
        }
        true
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
        let rows = Self::query("SELECT w.*,a.id AS activity_id,a.sequence AS activity_sequence,a.activity_type,a.status AS activity_status,a.attempt_count,a.current_attempt_id,q.id AS matched_task_id,q.task_type AS matched_task_type,q.payload AS matched_task_payload FROM workflow_run_waits w LEFT JOIN activity_executions a ON a.id=w.resume_source_id AND a.workflow_run_id=w.workflow_run_id LEFT JOIN workflow_tasks q ON q.id=w.task_id AND q.workflow_run_id=w.workflow_run_id WHERE w.workflow_run_id=$1 AND w.kind='activity' AND w.status='open' ORDER BY w.sequence,w.opened_at,w.resume_source_id LIMIT 101")
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
        if summary.wait.is_some()
            || summary
                .task_type
                .as_deref()
                .is_some_and(|kind| kind != "activity")
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
