//! Child liveness requires original typed identity and retained waits. A run
//! may hold any number of open children, each under any parent-close policy.
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
pub(super) struct ChildSummaryHistory {
    calls: BTreeMap<i64, ExpectedChild>,
}

struct ExpectedChild {
    id: String,
    /// The first run, which the parent's wait keeps naming.
    run: String,
    instance: String,
    label: String,
    started: bool,
    status: &'static str,
    /// The current attempt's run, link and run number. A retry
    /// (`ChildWorkflowRetryPolicy`) starts a new run under a new link.
    latest_run: String,
    latest_link: String,
    run_number: i64,
}

impl ChildSummaryHistory {
    pub(super) fn apply(&mut self, event: &str, payload: &Value) -> bool {
        let Some(sequence) = payload["sequence"].as_i64().filter(|v| *v > 0) else {
            return false;
        };
        if event == "ChildWorkflowScheduled" {
            let Some(identity) = ExpectedChild::identity(payload) else {
                return false;
            };
            // Any number of children may be open at once, and the parent-close
            // policy only decides what happens when the parent closes. Retry
            // and timeout policies only decide how the call's runs end.
            if self.calls.contains_key(&sequence)
                || self.calls.values().any(|child| child.id == identity.id)
                || payload["cancellation_policy"] != "abandon"
            {
                return false;
            }
            self.calls.insert(sequence, identity);
            return true;
        }
        let Some(previous) = self.calls.get_mut(&sequence) else {
            return false;
        };
        if !previous.same_call(payload) || previous.status != "waiting" {
            return false;
        }
        let text = |key: &str| payload[key].as_str().filter(|v| !v.is_empty()).map(str::to_owned);
        match event {
            "ChildRunStarted" if !previous.started => {
                if payload["child_run_number"] != 1
                    || payload["workflow_link_id"] != previous.id.as_str()
                    || payload["child_workflow_run_id"] != previous.run.as_str()
                {
                    return false;
                }
                previous.started = true;
            }
            "ChildRunStarted" if payload["retry_attempt"].is_i64() => {
                let (Some(run), Some(link), Some(number)) =
                    (text("child_workflow_run_id"), text("workflow_link_id"), payload["child_run_number"].as_i64())
                else {
                    return false;
                };
                if number <= previous.run_number || run == previous.latest_run || link == previous.latest_link {
                    return false;
                }
                previous.latest_run = run;
                previous.latest_link = link;
                previous.run_number = number;
            }
            "ChildRunCompleted" | "ChildRunCancelled" | "ChildRunTerminated" | "ChildRunFailed"
                if previous.started =>
            {
                let status = match event {
                    "ChildRunCompleted" => "completed",
                    "ChildRunCancelled" => "cancelled",
                    "ChildRunTerminated" => "terminated",
                    _ => "failed",
                };
                if payload["child_run_number"] != previous.run_number
                    || payload["child_status"] != status
                    || payload["workflow_link_id"] != previous.latest_link.as_str()
                    || payload["child_workflow_run_id"] != previous.latest_run.as_str()
                {
                    return false;
                }
                previous.status = status;
            }
            _ => return false,
        }
        true
    }
}

impl ExpectedChild {
    fn identity(payload: &Value) -> Option<Self> {
        let text = |key: &str| {
            payload[key]
                .as_str()
                .filter(|v| !v.is_empty())
                .map(str::to_owned)
        };
        let id = text("child_call_id")?;
        let label = text("child_workflow_type")?;
        if payload["workflow_link_id"] != id
            || payload["child_workflow_class"] != label
            || !payload["parallel_group_id"].is_null()
            || !payload["parallel_group_path"].is_null()
        {
            return None;
        }
        Some(Self {
            id,
            run: text("child_workflow_run_id")?,
            instance: text("child_workflow_instance_id")?,
            label,
            started: false,
            status: "waiting",
            latest_run: text("child_workflow_run_id")?,
            latest_link: text("child_call_id")?,
            run_number: 1,
        })
    }

    /// The call's identity, which every run of it shares.
    fn same_call(&self, payload: &Value) -> bool {
        payload["child_call_id"] == self.id.as_str()
            && payload["child_workflow_instance_id"] == self.instance.as_str()
            && payload["child_workflow_type"] == self.label.as_str()
            && payload["child_workflow_class"] == self.label.as_str()
            && payload["parallel_group_id"].is_null()
            && payload["parallel_group_path"].is_null()
    }

    fn wait_status(&self) -> &'static str {
        match self.status {
            "waiting" => "open",
            "cancelled" => "cancelled",
            _ => "resolved",
        }
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
    pub(super) async fn apply_child_summary(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        history: &ChildSummaryHistory,
        summary: &mut ActiveSummary,
    ) -> Result<bool> {
        let rows = Self::query("SELECT w.*,c.status AS call_status,c.resolved_child_run_id,c.resolved_child_instance_id,c.child_workflow_type,c.metadata AS call_metadata,l.id AS link_id,l.child_workflow_run_id AS link_run,l.child_workflow_instance_id AS link_instance FROM workflow_run_waits w LEFT JOIN workflow_child_calls c ON c.parent_workflow_run_id=w.workflow_run_id AND c.sequence=w.sequence LEFT JOIN workflow_links l ON l.parent_workflow_run_id=w.workflow_run_id AND l.sequence=w.sequence AND l.link_type='child_workflow' AND l.child_workflow_run_id=c.resolved_child_run_id WHERE w.workflow_run_id=$1 AND w.kind='child' ORDER BY w.sequence")
            .bind(run_id).fetch_all(&mut **tx).await?;
        if rows.len() != history.calls.len() {
            return Ok(false);
        }
        for row in &rows {
            let Some(sequence) = DB::optional_number(row, "sequence")? else {
                return Ok(false);
            };
            let Some(child) = history.calls.get(&sequence) else {
                return Ok(false);
            };
            if !Self::child_wait_matches(row, child)? {
                return Ok(false);
            }
        }
        if rows.is_empty() {
            return Ok(true);
        }
        // An open timer or activity wait describes the run ahead of open
        // children, as PHP's run description does, whichever was scheduled
        // first. Other wait families, and a resolved child beside them,
        // retain their separately qualified precedence.
        let all_open = rows.iter().all(|row| DB::string(row, "status").is_ok_and(|status| status == "open"));
        if let Some(kind) = summary.wait.as_ref().map(|wait| wait.kind).filter(|kind| matches!(*kind, "timer" | "activity"))
            && all_open
            && summary.task_type.as_deref().is_none_or(|task| task == kind)
        {
            return Ok(true);
        }
        // A resolved child beside an open timer, while a workflow task that
        // will apply it carries the run (a lease that completed without the
        // child's result, workflow#962): published 2.11.8 describes the
        // timer and reports no problem (`child-open-parent-task`, leased).
        if summary.wait.as_ref().is_some_and(|wait| wait.kind == "timer")
            && summary.task_type.as_deref() == Some("workflow")
            && rows.iter().all(|row| DB::string(row, "status").is_ok_and(|status| status != "open"))
        {
            return Ok(true);
        }
        if summary
            .wait
            .as_ref()
            .is_some_and(|wait| wait.kind != "workflow-task")
            || summary
                .task_type
                .as_deref()
                .is_some_and(|kind| kind != "workflow")
        {
            return Ok(false);
        }
        let open = if summary.task_id.is_none() {
            rows.iter()
                .find(|row| DB::string(row, "status").is_ok_and(|status| status == "open"))
        } else {
            None
        };
        let pending = rows.iter().find(|row| {
            DB::string(row, "source_status").is_ok_and(|status| {
                matches!(status.as_str(), "cancelled" | "failed")
                    || (status == "completed" && open.is_none())
            })
        });
        // PHP retains resolved-child problem evidence while the parent task is
        // ready or leased. That evidence does not change task liveness priority.
        summary.problem |= pending.is_some();
        if summary.task_id.is_some() || summary.state == "workflow_replay_blocked" {
            return Ok(true);
        }
        let Some(row) = pending.or(open) else {
            return Ok(true);
        };
        let label = DB::string(row, "target_type")?;
        summary.wait = Some(SummaryWait {
            kind: "child",
            id: DB::string(row, "wait_id")?,
            reason: if pending.is_some() {
                format!("Waiting to apply child workflow {label} result")
            } else {
                format!("Waiting for child workflow {label}")
            },
            started_at: DB::optional_instant(row, "resolved_at")?
                .or(DB::optional_instant(row, "opened_at")?),
            deadline_at: None,
            source_kind: "child_workflow_run",
            source_id: DB::optional_string(row, "resume_source_id")?,
        });
        if pending.is_some() {
            summary.state = "repair_needed".into();
            summary.reason =
                format!("Child workflow {label} is resolved without an open workflow task.");
            summary.repair_blocked = None;
            summary.problem = true;
        } else {
            summary.state = "waiting_for_child".into();
            summary.reason = format!("Waiting for child workflow {label}.");
            summary.repair_blocked = Some("repair_not_needed");
        }
        Ok(true)
    }

    fn child_wait_matches(row: &DB::Row, child: &ExpectedChild) -> Result<bool> {
        let matches = |column: &str, expected: &str| -> Result<bool> {
            Ok(DB::optional_string(row, column)?.as_deref() == Some(expected))
        };
        Ok(child.started
            && matches("wait_id", &format!("child:{}", child.id))?
            && matches("status", child.wait_status())?
            && matches("source_status", child.status)?
            && matches("target_name", &child.instance)?
            && matches("target_type", &child.label)?
            && matches("resume_source_kind", "child_workflow_run")?
            && matches("resume_source_id", &child.run)?
            && matches("history_authority", "typed_history")?
            && DB::optional_string(row, "history_unsupported_reason")?.is_none()
            && DB::optional_instant(row, "deadline_at")?.is_none()
            && DB::optional_instant(row, "opened_at")?.is_some()
            && (DB::optional_instant(row, "resolved_at")?.is_some() == (child.status != "waiting"))
            && matches(
                "call_status",
                if child.status == "waiting" {
                    "started"
                } else {
                    child.status
                },
            )?
            && matches("resolved_child_run_id", &child.latest_run)?
            && matches("resolved_child_instance_id", &child.instance)?
            && matches("child_workflow_type", &child.label)?
            && DB::optional_document_row(row, "call_metadata")?
                .as_ref()
                .is_some_and(|v| v["child_call_id"] == child.id)
            && matches("link_id", &child.latest_link)?
            && matches("link_run", &child.latest_run)?
            && matches("link_instance", &child.instance)?)
    }
}
