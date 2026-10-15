//! Sequential child liveness requires original typed identity and retained waits.
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
    run: String,
    instance: String,
    label: String,
    started: bool,
    status: &'static str,
}

impl ChildSummaryHistory {
    pub(super) fn apply(&mut self, event: &str, payload: &Value) -> bool {
        let Some(sequence) = payload["sequence"].as_i64().filter(|v| *v > 0) else {
            return false;
        };
        let Some(identity) = ExpectedChild::identity(payload) else {
            return false;
        };
        if event == "ChildWorkflowScheduled" {
            if self.calls.len() >= 100
                || self.calls.contains_key(&sequence)
                || self
                    .calls
                    .values()
                    .any(|child| child.status == "waiting" || child.id == identity.id)
                || payload["parent_close_policy"] != "abandon"
                || payload["cancellation_policy"] != "abandon"
                || !payload["retry_policy"].is_null()
                || !payload["timeout_policy"].is_null()
            {
                return false;
            }
            self.calls.insert(sequence, identity);
            return true;
        }
        let Some(previous) = self.calls.get_mut(&sequence) else {
            return false;
        };
        if !previous.same_identity(&identity) || previous.status != "waiting" {
            return false;
        }
        match event {
            "ChildRunStarted" if !previous.started => {
                if payload["child_run_number"] != 1
                    || [
                        "execution_timeout_seconds",
                        "run_timeout_seconds",
                        "execution_deadline_at",
                        "run_deadline_at",
                    ]
                    .iter()
                    .any(|key| !payload[*key].is_null())
                {
                    return false;
                }
                previous.started = true;
            }
            "ChildRunCompleted" | "ChildRunCancelled" if previous.started => {
                let status = if event == "ChildRunCompleted" {
                    "completed"
                } else {
                    "cancelled"
                };
                if payload["child_run_number"] != 1 || payload["child_status"] != status {
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
        })
    }

    fn same_identity(&self, other: &Self) -> bool {
        self.id == other.id
            && self.run == other.run
            && self.instance == other.instance
            && self.label == other.label
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
        let rows = Self::query("SELECT w.*,c.status AS call_status,c.resolved_child_run_id,c.resolved_child_instance_id,c.child_workflow_type,c.metadata AS call_metadata,l.id AS link_id,l.child_workflow_run_id AS link_run,l.child_workflow_instance_id AS link_instance FROM workflow_run_waits w LEFT JOIN workflow_child_calls c ON c.parent_workflow_run_id=w.workflow_run_id AND c.sequence=w.sequence LEFT JOIN workflow_links l ON l.parent_workflow_run_id=w.workflow_run_id AND l.sequence=w.sequence AND l.link_type='child_workflow' WHERE w.workflow_run_id=$1 AND w.kind='child' ORDER BY w.sequence LIMIT 101")
            .bind(run_id).fetch_all(&mut **tx).await?;
        if rows.len() != history.calls.len() || rows.len() > 100 {
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
        // Other wait families retain their separately qualified precedence.
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
                status == "cancelled" || (status == "completed" && open.is_none())
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
            && matches("resolved_child_run_id", &child.run)?
            && matches("resolved_child_instance_id", &child.instance)?
            && matches("child_workflow_type", &child.label)?
            && DB::optional_document_row(row, "call_metadata")?
                .as_ref()
                .is_some_and(|v| v["child_call_id"] == child.id)
            && matches("link_id", &child.id)?
            && matches("link_run", &child.run)?
            && matches("link_instance", &child.instance)?)
    }
}
