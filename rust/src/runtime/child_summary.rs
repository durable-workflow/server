//! Child liveness requires original typed identity and retained waits. A run
//! may hold any number of open children, each under any parent-close policy.
use super::{
    Result,
    active_summary::{ActiveSummary, SummaryWait},
    backend::Backend,
    history_budget,
    selections::{ChildSelectionAwait, child_status_failed, is_selection},
    store::Store,
    transaction::{ChildSummaryChange, Transaction},
};
use serde_json::Value;
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::{collections::BTreeMap, ops::Range};

macro_rules! checkpoint_proof {
    ($writes:literal, $run_proof:literal) => {
        concat!(
            "SELECT s.open_wait_id,s.history_size_bytes,s.history_fan_out,g.group_id
             FROM workflow_runs r JOIN workflow_instances i ON i.id=r.workflow_instance_id AND i.current_run_id=r.id
             JOIN workflow_run_summaries s ON s.id=r.id AND s.workflow_instance_id=i.id AND s.namespace=r.namespace
             JOIN dw_child_summary_checkpoints g ON g.run_id=r.id AND g.history_sequence=s.history_event_count
             AND g.generation=g.verified_generation+", $writes, "
             WHERE r.id=$1 AND s.history_event_count=$2 AND r.last_history_sequence=$3
             AND r.status='waiting' AND s.status='waiting' AND r.import_source IS NULL AND r.details_pruned_at IS NULL
             AND s.engine_source='v2' AND s.projection_schema_version=2 AND s.is_current_run=TRUE
             AND s.wait_kind='child' AND s.liveness_state='waiting_for_child' AND s.next_task_id IS NULL
             AND s.open_wait_id IS NOT NULL AND s.task_problem=FALSE AND s.repair_attention=FALSE
             AND s.repair_blocked_reason='repair_not_needed'", $run_proof
        )
    };
}

const CHECKPOINT_PROOF: &str = checkpoint_proof!("4", "");

// An UPDATE trigger on workflow_runs changes how MySQL-family engines
// report LAST_INSERT_ID for ordinary history advancement. Compare the
// source columns of the prior summary's run row in the same bounded join.
const MYSQL_CHECKPOINT_PROOF: &str = checkpoint_proof!("3", "
    AND s.run_number=r.run_number
    AND BINARY s.class <=> BINARY r.workflow_class
    AND BINARY s.workflow_type <=> BINARY r.workflow_type
    AND BINARY s.compatibility <=> BINARY r.compatibility
    AND BINARY s.business_key <=> BINARY COALESCE(r.business_key,i.business_key)
    AND BINARY s.visibility_labels <=> BINARY COALESCE(r.visibility_labels,i.visibility_labels)
    AND BINARY s.closed_reason <=> BINARY r.closed_reason
    AND BINARY s.connection <=> BINARY r.connection
    AND BINARY s.queue <=> BINARY r.queue
    AND s.started_at <=> r.started_at
    AND s.closed_at <=> r.closed_at
    AND s.archived_at <=> r.archived_at
    AND BINARY s.archive_command_id <=> BINARY r.archive_command_id
    AND BINARY s.archive_reason <=> BINARY r.archive_reason
    AND s.created_at <=> r.created_at
    AND s.updated_at <=> COALESCE(r.closed_at,r.last_progress_at,r.updated_at)
    AND s.sort_timestamp <=> COALESCE(r.started_at,r.created_at,r.updated_at)");

#[derive(Default)]
pub(super) struct ChildSummaryHistory {
    calls: BTreeMap<i64, ExpectedChild>,
    awaited_selection: Option<Value>,
    selected_result: Option<Range<i64>>,
    resolved_children: Vec<i64>,
    resumed_selections: std::collections::BTreeSet<i64>,
}

struct ExpectedChild {
    id: String,
    parallel: Value,
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
    pub(super) fn supersede_selection_await(&mut self) {
        self.consume_selected_result();
        if let Some(record) = self.awaited_selection.take()
            && let Some(handle) = ChildSelectionAwait::parse(&record)
            && self.selection_resolved(&handle)
        {
            self.resumed_selections.extend(handle.sequence..handle.sequence + handle.size);
        }
    }

    fn consume_selected_result(&mut self) {
        if let Some(range) = self.selected_result.take() {
            self.resumed_selections.extend(range);
        }
    }

    // A validated child winner also authorizes a wake before replay reaches
    // an explicit handle await. Its losing signal or condition may stay open.
    pub(super) fn selection_winner(&mut self, payload: &Value) {
        if matches!(payload["operation_kind"].as_str(), Some("child" | "group")) {
            let base = payload["member_base_sequence"].as_i64().unwrap();
            let size = payload["member_size"].as_i64().unwrap();
            self.selected_result = Some(base..base + size);
        }
    }

    fn active_selection(&self) -> Option<Range<i64>> {
        self.awaited_selection.as_ref()
            .and_then(ChildSelectionAwait::parse)
            .map(|handle| handle.sequence..handle.sequence + handle.size)
            .or_else(|| self.selected_result.clone())
    }

    fn selection_resolution(&self, range: Range<i64>) -> Option<i64> {
        let mut completed = 0;
        let mut last = None;
        for &sequence in self.resolved_children.iter().filter(|sequence| range.contains(sequence)) {
            if child_status_failed(self.calls[&sequence].status) {
                return Some(sequence);
            }
            completed += 1;
            last = Some(sequence);
        }
        if completed == range.end - range.start { last } else { None }
    }

    fn selection_resolved(&self, handle: &ChildSelectionAwait<'_>) -> bool {
        self.selection_resolution(handle.sequence..handle.sequence + handle.size).is_some()
    }

    pub(super) fn checkpoint_group(&self) -> Option<&str> {
        let first = self.calls.first_key_value()?.1;
        let group = first.parallel["parallel_group_id"].as_str()?;
        let size = self.calls.len();
        (size > 1
            && first.parallel["parallel_group_kind"] == "child"
            && !is_selection(&first.parallel)
            && first.parallel["parallel_group_size"].as_u64() == Some(size as u64)
            && self.calls.values().all(|child| {
                child.parallel["parallel_group_id"] == group
                    && matches!(child.status, "waiting" | "completed")
            }))
        .then_some(group)
    }

    pub(super) fn apply(&mut self, event: &str, payload: &Value) -> bool {
        if event == "ChildWorkflowAwaited" && payload.get("selection_handle").is_some() {
            let record = &payload["selection_handle"];
            let Some(handle) = ChildSelectionAwait::parse(record) else {
                return false;
            };
            if !(handle.sequence..handle.sequence + handle.size).all(|sequence| {
                self.calls.get(&sequence).is_some_and(|call| handle.matches(&call.run, &call.parallel))
            }) {
                return false;
            }
            if handle.waiting {
                self.consume_selected_result();
                self.awaited_selection = Some(record.clone());
            } else {
                let mut waiting = record.clone();
                waiting["status"] = serde_json::json!("waiting");
                if self.awaited_selection.as_ref() != Some(&waiting) || !self.selection_resolved(&handle) {
                    return false;
                }
                self.awaited_selection = None;
                self.resumed_selections.extend(handle.sequence..handle.sequence + handle.size);
            }
            return true;
        }
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
        let text = |key: &str| {
            payload[key]
                .as_str()
                .filter(|v| !v.is_empty())
                .map(str::to_owned)
        };
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
            "ChildRunStarted"
                if payload["retry_attempt"].is_i64()
                    || previous.parallel.get("parallel_group_id").is_some() =>
            {
                let (Some(run), Some(link), Some(number)) = (
                    text("child_workflow_run_id"),
                    text("workflow_link_id"),
                    payload["child_run_number"].as_i64(),
                ) else {
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
                self.resolved_children.push(sequence);
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
        let parallel = super::selections::metadata(payload, "child").ok()?;
        super::selections::check_sequence(&parallel, payload["sequence"].as_i64()?).ok()?;
        if payload["workflow_link_id"] != id || payload["child_workflow_class"] != label {
            return None;
        }
        Some(Self {
            id,
            parallel,
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
            && super::selections::metadata(payload, "child").ok().as_ref() == Some(&self.parallel)
    }

    fn wait_status(&self) -> &'static str {
        match self.status {
            "waiting" => "open",
            "cancelled" | "terminated" => "cancelled",
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
    /// The preceding summary was validated before this parent transition.
    /// Use it only for an exact, single successful child close while the
    /// parent remains parked. Status updates leave the wait order intact.
    pub(super) async fn project_completed_barrier_member(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        change: &ChildSummaryChange,
    ) -> Result<bool> {
        let Some(sequence) = change.previous_sequence.checked_add(1) else {
            return Ok(false);
        };
        let proof = if DB::NAME == "MySQL" { MYSQL_CHECKPOINT_PROOF } else { CHECKPOINT_PROOF };
        let Some(previous) = Self::query(proof)
        .bind(run_id)
        .bind(change.previous_sequence)
        .bind(sequence)
        .fetch_optional(&mut **tx)
        .await? else { return Ok(false); };
        let events = Self::query(DB::SUMMARY_HISTORY_SQL).bind(run_id).bind(change.previous_sequence)
            .fetch_all(&mut **tx).await?;
        let [event] = events.as_slice() else { return Ok(false); };
        if DB::string(event, "event_type")? != "ChildRunCompleted"
            || DB::number(event, "sequence")? != sequence
            || DB::string(event, "id")? != change.event_id
        {
            return Ok(false);
        }
        let raw = DB::string(event, "payload_json")?;
        let payload: Value = serde_json::from_str(&raw)?;
        if payload["parallel_group_id"] != DB::string(&previous, "group_id")? {
            return Ok(false);
        }
        let Some(mut child) = ExpectedChild::identity(&payload) else { return Ok(false); };
        let authored = payload["sequence"].as_i64().expect("validated child sequence");
        child.started = true;
        child.status = "completed";
        let wait_id = format!("child:{}", child.id);
        let resolved = Self::query("SELECT sequence,status,source_status,target_name,target_type,resume_source_kind,resume_source_id,history_authority,opened_at,resolved_at,deadline_at,history_unsupported_reason FROM workflow_run_waits WHERE workflow_run_id=$1 AND wait_id=$2 AND kind='child'")
            .bind(run_id).bind(&wait_id).fetch_optional(&mut **tx).await?;
        let Some(resolved) = resolved else { return Ok(false); };
        if DB::optional_number(&resolved, "sequence")? != Some(authored)
            || !Self::child_projection_matches(&resolved, &child)?
        {
            return Ok(false);
        }

        let previous_wait = DB::string(&previous, "open_wait_id")?;
        let candidate_wait = if previous_wait == wait_id {
            // The old summary had already established the first open child.
            // This close cannot add or open a child before that sequence.
            let next_call = Self::query("SELECT metadata FROM workflow_child_calls
                WHERE parent_workflow_run_id=$1 AND status='started' AND sequence>$2 ORDER BY sequence LIMIT 1")
                .bind(run_id).bind(authored).fetch_optional(&mut **tx).await?;
            let Some(next_call) = next_call else { return Ok(false); };
            let metadata = DB::document_row(&next_call, "metadata")?;
            let Some(id) = metadata["child_call_id"].as_str().filter(|id| !id.is_empty()) else { return Ok(false); };
            format!("child:{id}")
        } else {
            previous_wait
        };
        let next = Self::query("SELECT w.target_type,w.opened_at,w.resume_source_id,c.metadata AS call_metadata
            FROM workflow_run_waits w JOIN workflow_child_calls c ON w.workflow_run_id=c.parent_workflow_run_id AND w.sequence=c.sequence
            AND w.resume_source_id=c.resolved_child_run_id AND w.target_name=c.resolved_child_instance_id AND w.target_type=c.child_workflow_type
            WHERE w.workflow_run_id=$1 AND w.wait_id=$2 AND w.kind='child' AND w.status='open' AND c.status='started'
            AND w.source_status='waiting' AND w.resume_source_kind='child_workflow_run'
            AND w.resolved_at IS NULL AND w.deadline_at IS NULL AND w.history_authority='typed_history'
            AND w.history_unsupported_reason IS NULL LIMIT 2")
            .bind(run_id).bind(&candidate_wait).fetch_all(&mut **tx).await?;
        let [next] = next.as_slice() else { return Ok(false); };
        let Some(opened_at) = DB::optional_instant(next, "opened_at")? else { return Ok(false); };
        let label = DB::string(next, "target_type")?;
        let next_run = DB::string(next, "resume_source_id")?;
        let metadata = DB::document_row(next, "call_metadata")?;
        if !metadata["child_call_id"].as_str().is_some_and(|id| candidate_wait == format!("child:{id}")) {
            return Ok(false);
        }
        let bytes = DB::number(&previous, "history_size_bytes")?;
        let fan_out = DB::number(&previous, "history_fan_out")?;
        if bytes <= 0 || fan_out < payload["parallel_group_size"].as_i64().unwrap_or(i64::MAX) {
            return Ok(false);
        }
        let added_bytes = history_budget::parsed_event_size("ChildRunCompleted", &raw, &payload)? as i64;
        let Some(total_bytes) = bytes.checked_add(added_bytes) else { return Ok(false); };
        let budget = tx.history_budget.summarize(sequence, total_bytes, fan_out);
        let reason = format!("Waiting for child workflow {label}");
        let result = Self::query("UPDATE workflow_run_summaries SET history_event_count=$1,history_size_bytes=$2,
            history_budget_pressure=$3,continue_as_new_recommended=CASE WHEN $4=1 THEN TRUE ELSE FALSE END,
            open_wait_id=$5,resume_source_id=$6,wait_started_at=$7,wait_reason=$8,liveness_reason=$9 WHERE id=$10 AND history_event_count=$11")
            .bind(sequence).bind(budget.history_size_bytes).bind(budget.pressure).bind(i64::from(budget.continue_as_new_recommended))
            .bind(&candidate_wait).bind(&next_run).bind(DB::bind_time(opened_at)).bind(&reason).bind(format!("{reason}."))
            .bind(run_id).bind(change.previous_sequence).execute(&mut **tx).await?;
        if DB::affected(result) != 1 {
            return Ok(false);
        }
        Self::query("UPDATE dw_child_summary_checkpoints SET history_sequence=$1,verified_generation=generation WHERE run_id=$2")
            .bind(sequence).bind(run_id).execute(&mut **tx).await?;
        Ok(true)
    }

    pub(super) async fn verify_child_summary_checkpoint(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        group_id: &str,
        sequence: i64,
    ) -> Result<()> {
        let sql = if DB::NAME == "MySQL" {
            "INSERT INTO dw_child_summary_checkpoints(run_id,generation,verified_generation,history_sequence,group_id)
             VALUES($1,0,0,$2,$3) ON DUPLICATE KEY UPDATE
             verified_generation=dw_child_summary_checkpoints.generation,
             history_sequence=VALUES(history_sequence),group_id=VALUES(group_id)"
        } else {
            "INSERT INTO dw_child_summary_checkpoints(run_id,generation,verified_generation,history_sequence,group_id)
             VALUES($1,0,0,$2,$3) ON CONFLICT(run_id) DO UPDATE SET
             verified_generation=dw_child_summary_checkpoints.generation,
             history_sequence=excluded.history_sequence,group_id=excluded.group_id"
        };
        Self::query(sql)
        .bind(run_id).bind(sequence).bind(group_id).execute(&mut **tx).await?;
        Ok(())
    }

    pub(super) async fn apply_child_summary(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        read_rows: bool,
        history: &ChildSummaryHistory,
        summary: &mut ActiveSummary,
    ) -> Result<bool> {
        let rows = if read_rows { Self::query("SELECT w.*,c.status AS call_status,c.resolved_child_run_id,c.resolved_child_instance_id,c.child_workflow_type,c.metadata AS call_metadata,l.id AS link_id,l.child_workflow_run_id AS link_run,l.child_workflow_instance_id AS link_instance,l.parallel_group_path AS link_group_path FROM workflow_run_waits w LEFT JOIN workflow_child_calls c ON c.parent_workflow_run_id=w.workflow_run_id AND c.sequence=w.sequence LEFT JOIN workflow_links l ON l.parent_workflow_run_id=w.workflow_run_id AND l.sequence=w.sequence AND l.link_type='child_workflow' AND l.child_workflow_run_id=c.resolved_child_run_id WHERE w.workflow_run_id=$1 AND w.kind='child' ORDER BY w.sequence")
            .bind(run_id).fetch_all(&mut **tx).await? } else { Vec::new() };
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
        let rows = rows.iter().filter(|row| {
            DB::optional_number(row, "sequence").ok().flatten()
                .is_none_or(|sequence| !history.resumed_selections.contains(&sequence))
        }).collect::<Vec<_>>();
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
        let selection_await = if summary.wait.as_ref()
            .is_none_or(|wait| matches!(wait.kind, "workflow-task" | "signal" | "condition"))
        {
            history.active_selection()
        } else {
            None
        };
        if summary
            .wait
            .as_ref()
            .is_some_and(|wait| wait.kind != "workflow-task" && selection_await.is_none())
            || summary
                .task_type
                .as_deref()
                .is_some_and(|kind| kind != "workflow")
        {
            return Ok(false);
        }
        let selected;
        let applicable = if let Some(range) = &selection_await {
            selected = rows.iter().copied().filter(|row| {
                DB::optional_number(row, "sequence").ok().flatten()
                    .is_some_and(|sequence| range.contains(&sequence))
            }).collect::<Vec<_>>();
            if selected.len() != (range.end - range.start) as usize {
                return Ok(false);
            }
            selected.as_slice()
        } else {
            rows.as_slice()
        };
        let open = if summary.task_id.is_none() {
            applicable.iter()
                .find(|row| DB::string(row, "status").is_ok_and(|status| status == "open"))
        } else {
            None
        };
        let pending = if let Some(range) = selection_await {
            history.selection_resolution(range).and_then(|sequence| {
                applicable.iter().find(|row| {
                    DB::optional_number(row, "sequence").ok().flatten() == Some(sequence)
                })
            })
        } else {
            applicable.iter().find(|row| {
                DB::string(row, "source_status").is_ok_and(|status| {
                    child_status_failed(&status) || (status == "completed" && open.is_none())
                })
            })
        };
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

    fn child_projection_matches(row: &DB::Row, child: &ExpectedChild) -> Result<bool> {
        let matches = |column: &str, expected: &str| -> Result<bool> {
            Ok(DB::optional_string(row, column)?.as_deref() == Some(expected))
        };
        Ok(child.started
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
            && (DB::optional_instant(row, "resolved_at")?.is_some() == (child.status != "waiting")))
    }

    fn child_wait_matches(row: &DB::Row, child: &ExpectedChild) -> Result<bool> {
        let matches = |column: &str, expected: &str| -> Result<bool> {
            Ok(DB::optional_string(row, column)?.as_deref() == Some(expected))
        };
        Ok(Self::child_projection_matches(row, child)?
            && matches("wait_id", &format!("child:{}", child.id))?
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
            && matches("link_instance", &child.instance)?
            && DB::optional_document_row(row, "link_group_path")?
                == child.parallel.get("parallel_group_path").cloned())
    }
}
