//! Qualify ordinary external waits against complete typed history before summary use.
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
pub(super) struct ExternalWaitHistory {
    open: BTreeMap<String, ExpectedWait>,
}

struct ExpectedWait {
    kind: &'static str,
    name: String,
    sequence: i64,
}

impl ExternalWaitHistory {
    pub(super) fn apply(&mut self, kind: &str, payload: &Value) -> bool {
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
            "StartAccepted" | "WorkflowStarted" | "RepairRequested" | "MessageCursorAdvanced" => {
                return true;
            }
            _ => return false,
        };
        // Timeout-backed waits require the separately qualified timer classifier.
        if !payload["timeout_seconds"].is_null() || !payload["timer_id"].is_null() {
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
    pub(super) async fn apply_external_wait_summary(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        history: &ExternalWaitHistory,
        summary: &mut ActiveSummary,
    ) -> Result<bool> {
        let rows = Self::query("SELECT * FROM workflow_run_waits WHERE workflow_run_id=$1 AND status='open' AND kind IN ('condition','signal') ORDER BY sequence,wait_id LIMIT 101")
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
            if DB::string(row, "kind")? != expected.kind
                || DB::optional_string(row, "target_name")?.as_deref()
                    != Some(expected.name.as_str())
                || DB::optional_number(row, "sequence")? != Some(expected.sequence)
                || DB::optional_string(row, "history_authority")?.as_deref()
                    != Some("typed_history")
                || DB::optional_string(row, "source_status")?.as_deref() != Some("waiting")
                || DB::optional_string(row, "history_unsupported_reason")?.is_some()
                || DB::optional_instant(row, "deadline_at")?.is_some()
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
            reason: format!("Waiting for {} {}", expected.kind, expected.name),
            started_at: DB::optional_instant(row, "opened_at")?,
            deadline_at: None,
            source_kind: if expected.kind == "condition" {
                "external_input"
            } else {
                "signal"
            },
            source_id: None,
        });
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
}
