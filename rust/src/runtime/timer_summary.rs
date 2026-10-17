//! Ordinary timer liveness requires matching typed history and retained wait metadata.
use super::{
    Result,
    active_summary::{ActiveSummary, SummaryWait},
    backend::Backend,
    store::Store,
    transaction::Transaction,
};
use chrono::{DateTime, Utc};
use serde_json::Value;
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::collections::BTreeMap;

#[derive(Default)]
pub(super) struct TimerSummaryHistory {
    open: BTreeMap<String, ExpectedTimer>,
}

struct ExpectedTimer {
    sequence: i64,
    delay: i64,
    fire_at: DateTime<Utc>,
    metadata: Value,
}

impl TimerSummaryHistory {
    pub(super) fn apply(&mut self, kind: &str, payload: &Value) -> bool {
        let Some(id) = payload["timer_id"].as_str().filter(|id| !id.is_empty()) else {
            return false;
        };
        if !payload["timer_kind"].is_null() {
            return false;
        }
        if matches!(kind, "TimerFired" | "TimerCancelled") {
            return self.open.remove(id).is_some();
        }
        let (Some(sequence), Some(delay), Some(fire_at)) = (
            payload["sequence"].as_i64().filter(|v| *v > 0),
            payload["delay_seconds"].as_i64().filter(|v| *v >= 0),
            payload["fire_at"]
                .as_str()
                .and_then(|v| DateTime::parse_from_rfc3339(v).ok())
                .map(|v| v.with_timezone(&Utc)),
        ) else {
            return false;
        };
        if kind != "TimerScheduled" || self.open.len() >= 100 || self.open.contains_key(id) {
            return false;
        }
        self.open.insert(
            id.into(),
            ExpectedTimer {
                sequence,
                delay,
                fire_at,
                metadata: match super::selections::metadata(payload, "timer") {
                    Ok(metadata) => metadata,
                    Err(_) => return false,
                },
            },
        );
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
    pub(super) async fn apply_timer_summary(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        history: &TimerSummaryHistory,
        summary: &mut ActiveSummary,
    ) -> Result<bool> {
        let rows = Self::query("SELECT w.*,t.id AS timer_id,t.sequence AS timer_sequence,t.status AS timer_status,t.delay_seconds,t.fire_at,q.id AS matched_task_id,q.task_type AS matched_task_type,q.payload AS matched_task_payload FROM workflow_run_waits w LEFT JOIN workflow_run_timers t ON t.id=w.resume_source_id AND t.workflow_run_id=w.workflow_run_id LEFT JOIN workflow_tasks q ON q.id=w.task_id AND q.workflow_run_id=w.workflow_run_id WHERE w.workflow_run_id=$1 AND w.kind='timer' AND w.status='open' ORDER BY w.sequence,w.deadline_at,w.opened_at,w.resume_source_id LIMIT 101")
            .bind(run_id).fetch_all(&mut **tx).await?;
        if rows.len() != history.open.len() || rows.len() > 100 {
            return Ok(false);
        }
        let mut selected_timer_task = false;
        for row in &rows {
            let Some(id) = DB::optional_string(row, "timer_id")? else {
                return Ok(false);
            };
            let Some(expected) = history.open.get(&id) else {
                return Ok(false);
            };
            if DB::string(row, "wait_id")? != format!("timer:{id}")
                || DB::optional_number(row, "sequence")? != Some(expected.sequence)
                || DB::optional_number(row, "timer_sequence")? != Some(expected.sequence)
                || DB::optional_number(row, "delay_seconds")? != Some(expected.delay)
                || DB::optional_instant(row, "fire_at")? != Some(expected.fire_at)
                || DB::optional_instant(row, "deadline_at")? != Some(expected.fire_at)
                || DB::optional_string(row, "timer_status")?.as_deref() != Some("pending")
                || DB::optional_string(row, "source_status")?.as_deref() != Some("pending")
                || DB::optional_string(row, "history_authority")?.as_deref()
                    != Some("typed_history")
                || DB::optional_string(row, "history_unsupported_reason")?.is_some()
                || DB::optional_string(row, "resume_source_kind")?.as_deref() != Some("timer")
                || DB::optional_string(row, "task_id")?.is_none()
            {
                return Ok(false);
            }
            if DB::optional_string(row, "matched_task_id")?.is_some()
                && (DB::optional_string(row, "matched_task_type")?.as_deref() != Some("timer")
                    || DB::document_row(row, "matched_task_payload")?["timer_id"].as_str()
                        != Some(id.as_str())
                    || super::selections::metadata(
                        &DB::document_row(row, "matched_task_payload")?,
                        "timer",
                    )
                    .ok()
                    .as_ref()
                        != Some(&expected.metadata))
            {
                return Ok(false);
            }
            selected_timer_task |= DB::optional_string(row, "task_id")? == summary.task_id;
        }
        let signal_timer = summary.wait.as_ref().is_some_and(|wait| {
            matches!(wait.kind, "signal" | "condition") && wait.source_kind == "timer"
        });
        if summary.task_type.as_deref() == Some("timer") && !selected_timer_task && !signal_timer {
            return Ok(false);
        }
        let Some(first) = rows.first() else {
            // An open timer task with no pending typed timer is not a workflow
            // resume source, even if an older mutable task row remains.
            return Ok(summary.task_type.as_deref() != Some("timer") || signal_timer);
        };
        let id = DB::string(first, "timer_id")?;
        let fire_at = DB::instant(first, "fire_at")?;
        let condition_waiting = summary.state == "waiting_for_condition";
        summary.wait = Some(SummaryWait {
            kind: "timer",
            id: format!("timer:{id}"),
            reason: "Waiting for timer".into(),
            started_at: DB::optional_instant(first, "opened_at")?,
            deadline_at: Some(fire_at),
            source_kind: "timer",
            source_id: Some(id.clone()),
        });
        if condition_waiting
            || summary.state == "workflow_replay_blocked"
            || (summary.state == "repair_needed" && summary.task_id.is_some())
        {
            return Ok(true);
        }
        if let Some(task_id) = summary.task_id.as_ref() {
            if summary.task_status.as_deref() == Some("leased") {
                summary.state = "timer_task_leased".into();
                summary.reason = format!("Timer task {task_id} is leased to a worker.");
            } else {
                summary.state = "timer_scheduled".into();
                summary.reason = format!(
                    "Timer task {task_id} is scheduled to fire at {}.",
                    fire_at.format("%Y-%m-%dT%H:%M:%S%.6fZ")
                );
            }
            summary.repair_blocked = Some("repair_not_needed");
        } else {
            summary.state = "repair_needed".into();
            summary.reason = format!("Timer {id} is pending without an open timer task.");
            summary.repair_blocked = None;
        }
        Ok(true)
    }
}
