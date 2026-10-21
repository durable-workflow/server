//! Qualify ordinary external waits against complete typed history before summary use.
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
pub(super) struct ExternalWaitHistory {
    open: BTreeMap<String, ExpectedWait>,
    timers: BTreeMap<String, Value>,
}

struct ExpectedWait {
    kind: &'static str,
    name: String,
    sequence: i64,
    timeout: Option<i64>,
    timer: Option<(String, DateTime<Utc>)>,
    fired: bool,
    identity: Value,
}

impl ExternalWaitHistory {
    pub(super) fn apply(&mut self, kind: &str, payload: &Value) -> bool {
        if matches!(kind, "TimerScheduled" | "TimerFired" | "TimerCancelled") {
            return self.apply_timer(kind, payload);
        }
        let (wait_kind, id_key, name_key) = match kind {
            "ConditionWaitOpened" => ("condition", "condition_wait_id", "condition_key"),
            "SignalWaitOpened" => ("signal", "signal_wait_id", "signal_name"),
            "ConditionWaitSatisfied" | "SignalReceived" | "SignalApplied" => {
                let key = if kind == "ConditionWaitSatisfied" {
                    "condition_wait_id"
                } else {
                    "signal_wait_id"
                };
                let Some(id) = payload[key].as_str().filter(|v| !v.is_empty()) else {
                    return false;
                };
                self.open.remove(id);
                return true;
            }
            // A schedule-started run's own trigger marker opens no wait.
            "StartAccepted"
            | "WorkflowStarted"
            | "ScheduleTriggered"
            | "RepairRequested"
            | "MessageCursorAdvanced"
            | "VersionMarkerRecorded" => {
                return true;
            }
            _ => return false,
        };
        let timeout = if payload["timeout_seconds"].is_null() {
            None
        } else if let Some(seconds) = payload["timeout_seconds"].as_i64().filter(|n| *n >= 0) {
            Some(seconds)
        } else {
            return false;
        };
        if !payload["timer_id"].is_null() {
            return false;
        }
        let (Some(id), Some(name), Some(sequence)) = (
            payload[id_key].as_str().filter(|v| !v.is_empty()),
            payload[name_key].as_str().filter(|v| !v.is_empty()),
            payload["sequence"].as_i64().filter(|v| *v > 0),
        ) else {
            return false;
        };
        if self.open.len() >= 100 || self.open.contains_key(id) {
            return false;
        }
        self.open.insert(
            id.into(),
            ExpectedWait {
                kind: wait_kind,
                name: name.into(),
                sequence,
                timeout,
                timer: None,
                fired: false,
                identity: super::wait_timeouts::wait_fields(payload),
            },
        );
        true
    }

    fn apply_timer(&mut self, kind: &str, payload: &Value) -> bool {
        let (wait_kind, id_key, name_key) = match payload["timer_kind"].as_str() {
            Some("signal_timeout") => ("signal", "signal_wait_id", "signal_name"),
            Some("condition_timeout") => ("condition", "condition_wait_id", "condition_key"),
            _ => return false,
        };
        let (Some(timer_id), Some(wait_id), Some(name), Some(sequence), Some(delay)) = (
            payload["timer_id"].as_str().filter(|v| !v.is_empty()),
            payload[id_key].as_str().filter(|v| !v.is_empty()),
            payload[name_key].as_str().filter(|v| !v.is_empty()),
            payload["sequence"].as_i64(),
            payload["delay_seconds"].as_i64(),
        ) else {
            return false;
        };
        if kind == "TimerScheduled" {
            let Some(wait) = self.open.get_mut(wait_id) else {
                return false;
            };
            let Some(at) = payload["fire_at"]
                .as_str()
                .and_then(|v| DateTime::parse_from_rfc3339(v).ok())
                .map(|v| v.with_timezone(&Utc))
            else {
                return false;
            };
            if wait.kind != wait_kind
                || wait.name != name
                || wait.sequence != sequence
                || wait.timeout != Some(delay)
                || wait.timer.is_some()
                || self.timers.len() >= 100
                || self.timers.contains_key(timer_id)
                || super::wait_timeouts::wait_fields(payload) != wait.identity
            {
                return false;
            }
            wait.timer = Some((timer_id.into(), at));
            self.timers.insert(timer_id.into(), payload.clone());
            return true;
        }
        let Some(scheduled) = self.timers.remove(timer_id) else {
            return false;
        };
        for key in [
            "timer_id",
            "signal_wait_id",
            "signal_name",
            "condition_wait_id",
            "condition_wait_occurrence_id",
            "condition_key",
            "condition_definition_fingerprint",
            "sequence",
            "delay_seconds",
            "timer_kind",
        ] {
            if scheduled[key] != payload[key] {
                return false;
            }
        }
        if kind == "TimerCancelled" && scheduled["fire_at"] != payload["fire_at"] {
            return false;
        }
        if kind == "TimerFired" {
            let Some(actual) = payload["fired_at"]
                .as_str()
                .and_then(|v| DateTime::parse_from_rfc3339(v).ok())
            else {
                return false;
            };
            let Some(planned) = scheduled["fire_at"]
                .as_str()
                .and_then(|v| DateTime::parse_from_rfc3339(v).ok())
            else {
                return false;
            };
            if actual < planned
                || (!payload["fire_at"].is_null() && payload["fire_at"] != scheduled["fire_at"])
            {
                return false;
            }
            if let Some(wait) = self.open.get_mut(wait_id) {
                wait.fired = true;
            }
        } else if wait_kind == "signal" {
            self.open.remove(wait_id);
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
    pub(super) async fn apply_external_wait_summary(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        history: &ExternalWaitHistory,
        summary: &mut ActiveSummary,
    ) -> Result<bool> {
        let rows = Self::query("SELECT w.*,t.id AS signal_timer_id,t.status AS signal_timer_status,t.sequence AS signal_timer_sequence,t.delay_seconds AS signal_timer_delay,t.fire_at AS signal_timer_fire_at,q.id AS signal_task_id,q.task_type AS signal_task_type,q.payload AS signal_task_payload FROM workflow_run_waits w LEFT JOIN workflow_run_timers t ON t.id=w.resume_source_id AND t.workflow_run_id=w.workflow_run_id AND w.resume_source_kind='timer' LEFT JOIN workflow_tasks q ON q.id=w.task_id AND q.workflow_run_id=w.workflow_run_id WHERE w.workflow_run_id=$1 AND (w.status='open' OR (w.status='resolved' AND w.source_status='timed_out')) AND w.kind IN ('condition','signal') ORDER BY w.sequence,w.wait_id LIMIT 101")
            .bind(run_id).fetch_all(&mut **tx).await?;
        if rows.len() != history.open.len() || rows.len() > 100 {
            return Ok(false);
        }
        let mut condition = None;
        let mut signal = None;
        for row in &rows {
            let id = DB::string(row, "wait_id")?;
            let Some(expected) = history.open.get(&id) else {
                return Ok(false);
            };
            if !Self::external_wait_matches(row, expected)? {
                return Ok(false);
            }
            if expected.kind == "condition" {
                condition = Some((row, expected));
            } else {
                signal = Some((row, expected));
            }
        }
        let Some((row, expected)) = condition.or(signal) else {
            return Ok(true);
        };
        summary.wait = Some(SummaryWait {
            kind: expected.kind,
            id: DB::string(row, "wait_id")?,
            reason: if expected.fired {
                format!(
                    "Waiting to apply {} {} timeout",
                    expected.kind, expected.name
                )
            } else if expected.timeout.is_some() {
                format!("Waiting for {} {} or timeout", expected.kind, expected.name)
            } else {
                format!("Waiting for {} {}", expected.kind, expected.name)
            },
            started_at: DB::optional_instant(row, "opened_at")?,
            deadline_at: expected.timer.as_ref().map(|(_, at)| *at),
            source_kind: if expected.timer.is_some() {
                "timer"
            } else if expected.kind == "condition" {
                "external_input"
            } else {
                "signal"
            },
            source_id: expected.timer.as_ref().map(|(id, _)| id.clone()),
        });
        if let Some((_, at)) = &expected.timer {
            return Self::apply_external_timeout_summary(tx, run_id, row, expected, summary, *at)
                .await;
        }
        // Replay failures and expired leases take priority. Ordinary conditions
        // otherwise remain waiting until application, even with a claimed task.
        if summary.state != "workflow_replay_blocked"
            && !(summary.state == "repair_needed" && summary.task_id.is_some())
            && (expected.kind == "condition" || summary.task_id.is_none())
        {
            summary.state = format!("waiting_for_{}", expected.kind);
            summary.reason = if expected.kind == "condition" {
                "Waiting for a condition-changing durable input.".into()
            } else {
                format!("Waiting for signal {}.", expected.name)
            };
            summary.repair_blocked = Some("repair_not_needed");
        }
        Ok(true)
    }
    fn external_wait_matches(row: &DB::Row, expected: &ExpectedWait) -> Result<bool> {
        if DB::string(row, "kind")? != expected.kind
            || DB::optional_string(row, "target_name")?.as_deref() != Some(expected.name.as_str())
            || DB::optional_number(row, "sequence")? != Some(expected.sequence)
            || DB::optional_string(row, "history_authority")?.as_deref() != Some("typed_history")
            || DB::optional_string(row, "source_status")?.as_deref()
                != Some(if expected.fired {
                    if expected.kind == "condition" {
                        "timeout_fired"
                    } else {
                        "timed_out"
                    }
                } else {
                    "waiting"
                })
            || DB::optional_string(row, "history_unsupported_reason")?.is_some()
        {
            return Ok(false);
        }
        if let Some((timer, at)) = &expected.timer {
            return Self::external_timeout_matches(row, expected, timer, *at);
        }
        if DB::optional_instant(row, "deadline_at")?.is_some()
            || DB::optional_string(row, "task_id")?.is_some()
            || DB::optional_string(row, "resume_source_id")?.is_some()
            || DB::optional_string(row, "resume_source_kind")?.as_deref()
                != Some(if expected.kind == "condition" {
                    "external_input"
                } else {
                    "signal"
                })
        {
            return Ok(false);
        }
        Ok(true)
    }

    fn external_timeout_matches(
        row: &DB::Row,
        expected: &ExpectedWait,
        timer: &str,
        at: DateTime<Utc>,
    ) -> Result<bool> {
        if DB::optional_string(row, "resume_source_kind")?.as_deref() != Some("timer")
            || DB::optional_string(row, "resume_source_id")?.as_deref() != Some(timer)
            || DB::optional_string(row, "signal_timer_id")?.as_deref() != Some(timer)
            || DB::optional_string(row, "signal_timer_status")?.as_deref()
                != Some(if expected.fired { "fired" } else { "pending" })
            || DB::optional_number(row, "signal_timer_sequence")? != Some(expected.sequence)
            || DB::optional_number(row, "signal_timer_delay")? != expected.timeout
            || DB::optional_instant(row, "signal_timer_fire_at")? != Some(at)
            || DB::optional_instant(row, "deadline_at")? != Some(at)
        {
            return Ok(false);
        }
        if expected.timeout != Some(0) {
            if DB::optional_string(row, "task_id")?.is_none() {
                return Ok(false);
            }
            if DB::optional_string(row, "signal_task_id")?.is_some() {
                let payload = DB::document_row(row, "signal_task_payload")?;
                if DB::optional_string(row, "signal_task_type")?.as_deref() != Some("timer")
                    || payload["timer_id"].as_str() != Some(timer)
                    || super::wait_timeouts::wait_fields(&payload) != expected.identity
                {
                    return Ok(false);
                }
            }
        }
        Ok(true)
    }

    async fn apply_external_timeout_summary(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        row: &DB::Row,
        expected: &ExpectedWait,
        summary: &mut ActiveSummary,
        at: DateTime<Utc>,
    ) -> Result<bool> {
        if summary.state == "workflow_replay_blocked"
            || (summary.state == "repair_needed" && summary.task_id.is_some())
        {
            return Ok(true);
        }
        if expected.fired {
            if let Some(id) = summary.task_id.clone() {
                let task =
                    Self::query("SELECT * FROM workflow_tasks WHERE id=$1 AND workflow_run_id=$2")
                        .bind(id)
                        .bind(run_id)
                        .fetch_one(&mut **tx)
                        .await?;
                let wait = summary.wait.take();
                Self::apply_summary_task(
                    summary,
                    &task,
                    super::store::now(),
                    Some(if expected.kind == "condition" {
                        "Condition timeout"
                    } else {
                        "Signal timeout"
                    }),
                )?;
                summary.wait = wait;
            } else {
                summary.state = "repair_needed".into();
                summary.reason = format!(
                    "{} wait {} has a fired timeout without an open workflow task.",
                    if expected.kind == "condition" {
                        "Condition"
                    } else {
                        "Signal"
                    },
                    DB::string(row, "wait_id")?
                );
                summary.repair_blocked = None;
            }
        } else if summary.task_id.is_some() {
            summary.state = format!("waiting_for_{}", expected.kind);
            summary.reason = if expected.kind == "condition" {
                format!(
                    "Waiting for condition-changing input or timeout at {}.",
                    at.format("%Y-%m-%dT%H:%M:%S%.6fZ")
                )
            } else {
                format!(
                    "Waiting for signal {} or timeout at {}.",
                    expected.name,
                    at.format("%Y-%m-%dT%H:%M:%S%.6fZ")
                )
            };
            summary.repair_blocked = Some("repair_not_needed");
        } else {
            summary.state = "repair_needed".into();
            summary.reason = format!(
                "{} wait {} is open without an open timeout task.",
                if expected.kind == "condition" {
                    "Condition"
                } else {
                    "Signal"
                },
                DB::string(row, "wait_id")?
            );
            summary.repair_blocked = None;
        }
        Ok(true)
    }
}
