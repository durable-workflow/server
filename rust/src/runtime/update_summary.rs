//! Accepted updates retain their original command and workflow-task authority.
use super::{
    Result, active_summary::{ActiveSummary, SummaryWait}, backend::Backend,
    store::{Store, now}, transaction::Transaction,
};
use serde_json::Value;
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::collections::BTreeMap;

#[derive(Default)]
pub(super) struct UpdateSummaryHistory {
    updates: BTreeMap<String, ExpectedUpdate>,
}

struct ExpectedUpdate {
    command: String,
    name: String,
    run: String,
    instance: String,
    command_sequence: i64,
    sequence: Option<i64>,
    status: &'static str,
    applied: bool,
}

impl UpdateSummaryHistory {
    pub(super) fn apply(&mut self, kind: &str, payload: &Value) -> bool {
        let (Some(id), Some(command), Some(name), Some(run), Some(instance), Some(command_sequence)) = (
            nonempty(payload, "update_id"), nonempty(payload, "workflow_command_id"),
            nonempty(payload, "update_name"), nonempty(payload, "workflow_run_id"),
            nonempty(payload, "workflow_instance_id"), payload["command"]["sequence"].as_i64().filter(|v| *v > 0),
        ) else { return false; };
        if payload["command"]["id"] != command || payload["command"]["resolved_run_id"] != run {
            return false;
        }
        if kind == "UpdateAccepted" {
            if self.updates.len() >= 64 || self.updates.contains_key(id) || payload["ordering_state"] != "ready" {
                return false;
            }
            self.updates.insert(id.into(), ExpectedUpdate {
                command: command.into(), name: name.into(), run: run.into(), instance: instance.into(),
                command_sequence, sequence: None, status: "accepted", applied: false,
            });
            return true;
        }
        let Some(update) = self.updates.get_mut(id) else { return false; };
        if update.command != command || update.name != name || update.run != run || update.instance != instance
            || update.command_sequence != command_sequence || update.status != "accepted" {
            return false;
        }
        let Some(sequence) = payload["sequence"].as_i64().filter(|v| *v > 0) else { return false; };
        if update.sequence.is_some_and(|previous| previous != sequence) { return false; }
        match kind {
            "UpdateApplied" if !update.applied => update.applied = true,
            "UpdateCompleted" if update.applied && payload["failure_id"].is_null() => update.status = "completed",
            "UpdateCompleted" if !update.applied && nonempty(payload, "failure_id").is_some() => update.status = "failed",
            _ => return false,
        }
        update.sequence = Some(sequence);
        true
    }
}

fn nonempty<'a>(value: &'a Value, field: &str) -> Option<&'a str> {
    value[field].as_str().filter(|v| !v.is_empty())
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
    pub(super) async fn apply_update_summary(
        tx: &mut Transaction<'_, DB>, run: &str, history: &UpdateSummaryHistory, summary: &mut ActiveSummary,
    ) -> Result<bool> {
        let rows = Self::query("SELECT w.*,u.id AS update_id,u.workflow_command_id,u.workflow_instance_id AS update_instance,u.update_name,u.status AS update_status,u.command_sequence AS update_command_sequence,u.workflow_sequence,c.id AS matched_command_id,c.command_sequence AS matched_command_sequence FROM workflow_run_waits w LEFT JOIN workflow_updates u ON u.id=w.resume_source_id AND u.workflow_run_id=w.workflow_run_id LEFT JOIN workflow_commands c ON c.id=u.workflow_command_id AND c.workflow_run_id=w.workflow_run_id WHERE w.workflow_run_id=$1 AND w.kind='update' ORDER BY w.command_sequence,w.wait_id LIMIT 65")
            .bind(run).fetch_all(&mut **tx).await?;
        if rows.len() != history.updates.len() || rows.len() > 64 { return Ok(false); }
        let mut open = None;
        for row in &rows {
            let Some(id) = DB::optional_string(row, "update_id")? else { return Ok(false); };
            let Some(expected) = history.updates.get(&id) else { return Ok(false); };
            if !Self::update_wait_matches(row, run, &id, expected)? { return Ok(false); }
            if expected.status == "accepted" {
                if open.is_some() { return Ok(false); }
                open = Some(row);
            }
        }
        let Some(wait) = open else { return Ok(true); };
        let id = DB::string(wait, "update_id")?;
        let name = DB::string(wait, "target_name")?;
        let Some(task_id) = DB::optional_string(wait, "task_id")? else { return Ok(false); };
        let task = Self::query("SELECT * FROM workflow_tasks WHERE id=$1 AND workflow_run_id=$2 AND status IN ('ready','leased')")
            .bind(&task_id).bind(run).fetch_optional(&mut **tx).await?;
        let blocked = summary.state == "workflow_replay_blocked";
        // Update precedence suppresses earlier child-result attention; historical
        // workflow-task repair evidence still belongs to this run.
        summary.problem = blocked || Self::scalar("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND repair_count>0")
            .bind(run).fetch_one(&mut **tx).await? > 0;
        if let Some(task) = task {
            let payload = DB::document_row(&task, "payload")?;
            if DB::string(&task, "task_type")? != "workflow" || payload["workflow_update_id"] != id
                || payload["workflow_command_id"].as_str() != DB::optional_string(wait, "command_id")?.as_deref() {
                return Ok(false);
            }
            if !blocked && !Self::apply_summary_task(summary, &task, now(), Some("Update"))? { return Ok(false); }
        } else {
            if summary.task_id.is_some() { return Ok(false); }
            summary.problem = true;
            if !blocked {
                summary.state = "repair_needed".into();
                summary.reason = format!("Accepted update {name} is open without an open workflow task.");
                summary.repair_blocked = None;
            }
        }
        summary.wait = Some(SummaryWait {
            kind: "update", id: DB::string(wait, "wait_id")?, reason: format!("Waiting for update {name}"),
            started_at: DB::optional_instant(wait, "opened_at")?, deadline_at: None,
            source_kind: "workflow_update", source_id: Some(id),
        });
        Ok(true)
    }

    fn update_wait_matches(row: &DB::Row, run: &str, id: &str, expected: &ExpectedUpdate) -> Result<bool> {
        Ok(expected.run == run
            && DB::string(row, "wait_id")? == format!("update:{id}")
            && DB::optional_string(row, "workflow_instance_id")?.as_deref() == Some(expected.instance.as_str())
            && DB::optional_string(row, "update_instance")?.as_deref() == Some(expected.instance.as_str())
            && DB::optional_string(row, "command_id")?.as_deref() == Some(expected.command.as_str())
            && DB::optional_string(row, "matched_command_id")?.as_deref() == Some(expected.command.as_str())
            && DB::optional_number(row, "command_sequence")? == Some(expected.command_sequence)
            && DB::optional_number(row, "update_command_sequence")? == Some(expected.command_sequence)
            && DB::optional_number(row, "matched_command_sequence")? == Some(expected.command_sequence)
            && DB::optional_string(row, "target_name")?.as_deref() == Some(expected.name.as_str())
            && DB::optional_string(row, "update_name")?.as_deref() == Some(expected.name.as_str())
            && DB::optional_string(row, "source_status")?.as_deref() == Some(expected.status)
            && DB::optional_string(row, "update_status")?.as_deref() == Some(expected.status)
            && DB::optional_number(row, "sequence")? == expected.sequence
            && DB::optional_number(row, "workflow_sequence")? == expected.sequence
            && DB::string(row, "status")? == if expected.status == "accepted" {"open"} else {"resolved"}
            && DB::optional_string(row, "history_authority")?.as_deref() == Some("typed_history")
            && DB::optional_string(row, "history_unsupported_reason")?.is_none()
            && DB::optional_string(row, "resume_source_kind")?.as_deref() == Some("workflow_update")
            && DB::optional_instant(row, "deadline_at")?.is_none())
    }
}
