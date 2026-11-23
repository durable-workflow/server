//! Bounded two-member selection, with a durable first committed winner.
use super::transaction::Transaction;
use super::{Result, backend::Backend, refuse, store::Store};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

const FIELDS: [&str; 11] = [
    "parallel_group_id",
    "parallel_group_kind",
    "parallel_group_mode",
    "parallel_group_base_sequence",
    "parallel_group_size",
    "parallel_group_index",
    "selection_member_key",
    "selection_member_index",
    "selection_member_base_sequence",
    "selection_member_size",
    "selection_member_kind",
];

fn unqualified() -> super::RuntimeError {
    refuse(
        StatusCode::UNPROCESSABLE_ENTITY,
        "development_selection_shape_unqualified",
    )
}

fn present(value: &Value) -> bool {
    value.get("parallel_group_path").is_some()
        || FIELDS.iter().any(|field| value.get(*field).is_some())
}

pub(super) fn is_selection(value: &Value) -> bool {
    value["parallel_group_mode"] == "select"
}

pub(super) struct ChildSelectionAwait<'a> {
    pub sequence: i64,
    operation_identity: &'a str,
    group: &'a str,
    pub waiting: bool,
}

impl<'a> ChildSelectionAwait<'a> {
    pub(super) fn parse(handle: &'a Value) -> Option<Self> {
        if handle.as_object()?.len() != 6 || handle["member_size"] != 1 {
            return None;
        }
        let sequence = handle["member_base_sequence"].as_i64().filter(|sequence| *sequence > 0)?;
        handle["await_position"].as_i64().filter(|position| *position > sequence)?;
        let operation_identity = handle["operation_identity"].as_str()
            .filter(|identity| !identity.is_empty() && identity.len() <= 191)?;
        let group = handle["selection_group_id"].as_str().filter(|group| !group.is_empty() && group.len() <= 191)?;
        let waiting = match handle["status"].as_str()? {
            "waiting" => true,
            "resumed" => false,
            _ => return None,
        };
        Some(Self { sequence, operation_identity, group, waiting })
    }

    pub(super) fn matches(&self, identity: &str, metadata: &Value) -> bool {
        self.operation_identity == identity
            && is_selection(metadata)
            && metadata["parallel_group_id"] == self.group
            && metadata["selection_member_kind"] == "child"
            && metadata["selection_member_base_sequence"] == self.sequence
            && metadata["selection_member_size"] == 1
    }
}

pub(super) fn pair_kind(first: &str, second: &str) -> Option<&'static str> {
    match (first, second) {
        ("activity", "timer") | ("timer", "activity") | ("child", "signal") | ("signal", "child")
        | ("child", "condition") | ("condition", "child") => Some("mixed"),
        ("signal", "signal") => Some("signal"),
        _ => None,
    }
}

pub(super) fn metadata(value: &Value, kind: &str) -> Result<Value> {
    if !present(value) {
        return Ok(json!({}));
    }
    if !is_selection(value) {
        return super::all_groups::metadata(value, kind);
    }
    let base = value["parallel_group_base_sequence"]
        .as_i64()
        .filter(|base| *base > 0)
        .ok_or_else(unqualified)?;
    let index = value["parallel_group_index"]
        .as_i64()
        .filter(|index| (0..2).contains(index))
        .ok_or_else(unqualified)?;
    let member_base = base.checked_add(index).ok_or_else(unqualified)?;
    let key = &value["selection_member_key"];
    if !matches!(kind, "activity" | "timer" | "child" | "signal" | "condition")
        || value["parallel_group_id"] != format!("select-calls:{base}:2")
        || (value["parallel_group_kind"] != "mixed"
            && !(kind == "signal" && value["parallel_group_kind"] == "signal"))
        || value["parallel_group_mode"] != "select"
        || value["parallel_group_size"] != 2
        || value["selection_member_index"] != index
        || value["selection_member_base_sequence"] != member_base
        || value["selection_member_size"] != 1
        || value["selection_member_kind"] != kind
        || !(key.as_str().is_some_and(|key| !key.is_empty())
            || key.as_i64().is_some_and(|key| key >= 0))
    {
        return Err(unqualified());
    }
    let mut leaf = serde_json::Map::new();
    for field in FIELDS {
        leaf.insert(
            field.to_owned(),
            value.get(field).cloned().ok_or_else(unqualified)?,
        );
    }
    let path = value["parallel_group_path"]
        .as_array()
        .filter(|path| path.len() == 1)
        .ok_or_else(unqualified)?;
    if path[0] != Value::Object(leaf.clone()) {
        return Err(unqualified());
    }
    leaf.insert("parallel_group_path".into(), Value::Array(path.clone()));
    Ok(Value::Object(leaf))
}

pub(super) fn fields(command: &Value, ordinary: &[&str]) -> Result<()> {
    let mut allowed = ordinary.to_vec();
    allowed.extend(FIELDS);
    allowed.push("parallel_group_path");
    super::store::reject_fields(command, &allowed)
}

/// A complete group must arrive in one atomic authored batch. Other shapes
/// remain refused rather than treating parallel metadata as ignorable options.
pub(super) fn validate_batch(commands: &[Value]) -> Result<std::collections::BTreeSet<String>> {
    let partial_occurrences = super::all_groups::validate_batch(commands)?;
    let mut members = Vec::new();
    for (offset, command) in commands.iter().enumerate() {
        if !present(command) {
            continue;
        }
        if !is_selection(command) {
            let kind = match command["type"].as_str() {
                Some("schedule_activity") => "activity",
                Some("start_timer") => "timer",
                Some("start_child_workflow") => "child",
                Some("open_condition_wait") => "condition",
                _ => return Err(unqualified()),
            };
            metadata(command, kind)?;
            continue;
        }
        let kind = match command["type"].as_str() {
            Some("schedule_activity") => "activity",
            Some("start_child_workflow") => "child",
            Some("open_signal_wait")
                if command.get("timeout_seconds").is_none_or(|timeout| {
                    timeout.is_null() || timeout.as_i64().is_some_and(|seconds| seconds > 0)
                }) =>
            {
                "signal"
            }
            Some("open_condition_wait")
                if command.get("timeout_seconds").is_none_or(|timeout| {
                    timeout.is_null() || timeout.as_i64().is_some_and(|seconds| seconds > 0)
                }) =>
            {
                "condition"
            }
            Some("start_timer")
                if command["delay_seconds"]
                    .as_i64()
                    .is_some_and(|delay| delay > 0) =>
            {
                "timer"
            }
            _ => return Err(unqualified()),
        };
        members.push((offset, kind, metadata(command, kind)?));
    }
    if members.is_empty() {
        return Ok(partial_occurrences);
    }
    if members.len() != 2 {
        return Err(unqualified());
    }
    let (first_offset, first_kind, first) = &members[0];
    let (second_offset, second_kind, second) = &members[1];
    let group_kind = pair_kind(first_kind, second_kind).ok_or_else(unqualified)?;
    if *second_offset != *first_offset + 1
        || first["parallel_group_index"] != 0
        || second["parallel_group_index"] != 1
        || first["parallel_group_kind"] != group_kind
        || second["parallel_group_kind"] != group_kind
        || first["parallel_group_id"] != second["parallel_group_id"]
        || first["selection_member_key"] == second["selection_member_key"]
    {
        return Err(unqualified());
    }
    Ok(partial_occurrences)
}

pub(super) fn check_sequence(metadata: &Value, sequence: i64) -> Result<()> {
    if metadata.get("parallel_group_id").is_some() && !is_selection(metadata) {
        return if metadata["parallel_group_path"]
            .as_array()
            .unwrap()
            .iter()
            .all(|entry| {
                entry["parallel_group_base_sequence"].as_i64().unwrap()
                    + entry["parallel_group_index"].as_i64().unwrap()
                    == sequence
            }) {
            Ok(())
        } else {
            Err(refuse(StatusCode::CONFLICT, "parallel_sequence_mismatch"))
        };
    }
    if metadata.get("parallel_group_id").is_some()
        && metadata["selection_member_base_sequence"] != sequence
    {
        return Err(refuse(StatusCode::CONFLICT, "selection_sequence_mismatch"));
    }
    Ok(())
}

/// Replaces a command's authored group fields in an event payload with their
/// normalized metadata, so the record never keeps unvalidated group fields.
pub(super) fn replace_group(payload: &mut Value, metadata: &Value) {
    let object = payload.as_object_mut().expect("event object");
    for field in FIELDS {
        object.remove(field);
    }
    object.remove("parallel_group_path");
    merge(payload, metadata);
}

pub(super) fn merge(payload: &mut Value, metadata: &Value) {
    payload
        .as_object_mut()
        .expect("event object")
        .extend(metadata.as_object().expect("metadata object").clone());
}

pub(super) fn outcome(event_type: &str) -> &'static str {
    match event_type {
        "ActivityTimedOut" | "ChildRunFailed" | "ChildRunCancelled" | "ChildRunTerminated" => "failed",
        _ => "completed",
    }
}

pub(super) fn activity_metadata(path: Option<Value>) -> Result<Value> {
    path_metadata(path, "activity")
}

pub(super) fn child_metadata(path: Option<Value>) -> Result<Value> {
    path_metadata(path, "child")
}

fn path_metadata(path: Option<Value>, kind: &str) -> Result<Value> {
    let Some(path) = path else {
        return Ok(json!({}));
    };
    let leaf = path
        .as_array()
        .filter(|path| !path.is_empty())
        .and_then(|path| path.last())
        .ok_or_else(unqualified)?;
    let mut value = leaf.clone();
    value["parallel_group_path"] = path.clone();
    metadata(&value, kind)
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
    pub(super) async fn record_child_selection_await(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        task_id: &str,
        handle: &Value,
    ) -> Result<()> {
        let invalid = || refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_child_selection_await");
        let authored = ChildSelectionAwait::parse(handle).ok_or_else(invalid)?;
        let original = Self::query(
            "SELECT l.id,l.child_workflow_run_id,l.parallel_group_path,c.metadata,c.status AS call_status FROM workflow_links l
             JOIN workflow_child_calls c ON c.parent_workflow_run_id=l.parent_workflow_run_id AND c.sequence=l.sequence
             WHERE l.parent_workflow_run_id=$1 AND l.child_workflow_run_id=$2 AND l.sequence=$3
             AND l.link_type='child_workflow' LIMIT 2",
        )
        .bind(run_id).bind(authored.operation_identity).bind(authored.sequence)
        .fetch_all(&mut **tx).await?;
        let [original] = original.as_slice() else { return Err(invalid()); };
        let metadata = child_metadata(DB::optional_document_row(original, "parallel_group_path")?)?;
        if DB::document_row(original, "metadata")?["child_call_id"] != DB::string(original, "id")?
            || !authored.matches(&DB::string(original, "child_workflow_run_id")?, &metadata)
        {
            return Err(invalid());
        }
        let last = Self::query(
            "SELECT event_type,payload FROM workflow_history_events WHERE workflow_run_id=$1
             AND event_type IN ('ChildWorkflowAwaited','SignalWaitOpened','ConditionWaitOpened') ORDER BY sequence DESC LIMIT 1",
        ).bind(run_id).fetch_optional(&mut **tx).await?;
        let previous = match last {
            Some(last) if DB::string(&last, "event_type")? == "ChildWorkflowAwaited" => {
                DB::document_row(&last, "payload")?["selection_handle"].clone()
            }
            _ => Value::Null,
        };
        if previous == *handle {
            return Ok(());
        }
        if !authored.waiting {
            let mut waiting = handle.clone();
            waiting["status"] = json!("waiting");
            if previous != waiting
                || !matches!(DB::string(original, "call_status")?.as_str(),
                    "completed" | "cancelled" | "terminated" | "failed")
            {
                return Err(invalid());
            }
        }
        Self::append(tx, run_id, "ChildWorkflowAwaited", json!({"selection_handle": handle}), Some(task_id), None).await
    }

    pub(super) async fn resolve_selection(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        metadata: &Value,
        kind: &str,
        identity: &str,
        event_type: &str,
    ) -> Result<bool> {
        if !is_selection(metadata) {
            return Ok(false);
        }
        let Some(group_id) = metadata["parallel_group_id"].as_str() else {
            return Ok(false);
        };
        if Self::query(DB::SELECTION_RESOLUTION_SQL)
            .bind(run_id)
            .bind(group_id)
            .fetch_optional(&mut **tx)
            .await?
            .is_some()
        {
            return Ok(false);
        }
        // Called immediately after the member's actual terminal event under
        // the same transition lock; bind its physical durable history ID.
        let event = Self::query("SELECT id,event_type FROM workflow_history_events WHERE workflow_run_id=$1 ORDER BY sequence DESC LIMIT 1")
            .bind(run_id).fetch_one(&mut **tx).await?;
        if DB::string(&event, "event_type")? != event_type {
            return Err(refuse(
                StatusCode::CONFLICT,
                "selection_resolution_mismatch",
            ));
        }
        Self::append(tx, run_id, "SelectionResolved", json!({
            "selection_group_id":group_id,"selection_group_base_sequence":metadata["parallel_group_base_sequence"],
            "selection_group_size":metadata["parallel_group_size"],"member_key":metadata["selection_member_key"],
            "member_index":metadata["selection_member_index"],"member_base_sequence":metadata["selection_member_base_sequence"],
            "member_size":1,"operation_kind":kind,"operation_identity":identity,"outcome":outcome(event_type),
            "resolution_event_id":DB::string(&event,"id")?,"resolution_event_type":event_type,
        }), None, None).await?;
        Ok(true)
    }

    /// Wakes the workflow after a member's close, or returns `None` when
    /// published PHP's `ParallelChildGroup::shouldWakeParentOnClosure` would
    /// not: a successful close inside an all-of group waits until every
    /// member of every enclosing group has closed successfully. A failed
    /// member wakes it at once. Selections resolve their own winner.
    pub(super) async fn selection_resume(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        queue: &str,
        payload: Value,
        metadata: &Value,
        successful: bool,
    ) -> Result<Option<String>> {
        if metadata.get("parallel_group_id").is_some() {
            if successful && !is_selection(metadata) && !Self::all_groups_closed(tx, run_id, metadata).await? {
                return Ok(None);
            }
            // A leased worker may be replaying an older history snapshot.
            // Coalesce only a ready wakeup, otherwise preserve a later task
            // even if the active lease subsequently acknowledges waiting.
            let open = Self::query("SELECT id FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status='ready' ORDER BY available_at,id LIMIT 1")
                .bind(run_id).fetch_optional(&mut **tx).await?;
            if let Some(task) = open {
                return DB::string(&task, "id").map(Some);
            }
        }
        Self::create_task(tx, run_id, queue, "workflow", payload).await.map(Some)
    }

    /// `groupCompletedSuccessfully` for each all-of group on a member's path:
    /// Every authored member has completed. A retried child or a reopened
    /// condition retains its original position in the barrier.
    pub(super) async fn all_groups_closed(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        metadata: &Value,
    ) -> Result<bool> {
        let Some(path) = metadata["parallel_group_path"].as_array() else {
            return Ok(true);
        };
        let mut waits = None;
        for group in path {
            let (Some(base), Some(size)) = (
                group["parallel_group_base_sequence"].as_i64(),
                group["parallel_group_size"].as_i64(),
            ) else {
                return Ok(true);
            };
            let last = base + size - 1;
            if group["parallel_group_kind"] == "child" {
                // Use the open-wait index to get a hint, then confirm the
                // authored sequence and status against the authoritative call.
                let pending = Self::query(
                    "SELECT 1 FROM (
                        SELECT workflow_run_id,sequence FROM workflow_run_waits
                        WHERE workflow_run_id=$1 AND status='open' AND kind='child'
                        ORDER BY position LIMIT 1
                    ) w JOIN workflow_child_calls c
                    ON c.parent_workflow_run_id=w.workflow_run_id AND c.sequence=w.sequence
                    WHERE c.sequence>=$2 AND c.sequence<=$3
                    AND c.status IN ('scheduled','started') LIMIT 1",
                )
                .bind(run_id)
                .bind(base)
                .bind(last)
                .fetch_optional(&mut **tx)
                .await?;
                if pending.is_some() {
                    return Ok(false);
                }
            }
            let mut closed = std::collections::BTreeSet::new();
            for (sql, done) in [
                (
                    "SELECT sequence,status FROM workflow_run_timers WHERE workflow_run_id=$1 AND sequence>=$2 AND sequence<=$3",
                    "fired",
                ),
                (
                    "SELECT sequence,status FROM activity_executions WHERE workflow_run_id=$1 AND sequence>=$2 AND sequence<=$3",
                    "completed",
                ),
                (
                    "SELECT sequence,status FROM workflow_child_calls WHERE parent_workflow_run_id=$1 AND sequence>=$2 AND sequence<=$3",
                    "completed",
                ),
            ] {
                for row in Self::query(sql).bind(run_id).bind(base).bind(last).fetch_all(&mut **tx).await? {
                    if DB::string(&row, "status")? == done {
                        closed.insert(DB::number(&row, "sequence")?);
                    }
                }
            }
            if closed.len() < size as usize && matches!(group["parallel_group_kind"].as_str(), Some("condition" | "mixed")) {
                if waits.is_none() {
                    waits = Some(Self::waits(tx, run_id).await?);
                }
                let history = waits.as_ref().unwrap();
                let mut positions = std::collections::BTreeSet::new();
                for wait in history.conditions.iter().rev() {
                    let Some(opening) = history.condition_opening(&wait.id) else { continue };
                    let Some(member) = opening.payload["parallel_group_path"]
                        .as_array()
                        .and_then(|path| path.iter().find(|member| member["parallel_group_id"] == group["parallel_group_id"]))
                    else { continue };
                    if let Some(index) = member["parallel_group_index"].as_i64().filter(|index| (0..size).contains(index)) {
                        let position = base + index;
                        if positions.insert(position) && wait.completed_successfully() {
                            closed.insert(position);
                        }
                    }
                }
            }
            if (base..=last).any(|sequence| !closed.contains(&sequence)) {
                return Ok(false);
            }
        }
        Ok(true)
    }

    /// Only the proven timer-won, unclaimed losing activity checkpoint widens
    /// explicit range repair. Running activities and unrelated open work remain
    /// refused; none of their rows or attempts are changed by this inspection.
    pub(super) async fn range_repair_wait(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
    ) -> Result<Option<&'static str>> {
        let open = Self::query("SELECT * FROM workflow_tasks WHERE workflow_run_id=$1 AND status IN ('ready','leased') ORDER BY id LIMIT 3")
            .bind(run_id).fetch_all(&mut **tx).await?;
        let pending = Self::scalar("SELECT COUNT(*) FROM activity_executions WHERE workflow_run_id=$1 AND status IN ('pending','running')")
            .bind(run_id).fetch_one(&mut **tx).await?;
        if open.is_empty() && pending == 0 {
            return Ok(None);
        }
        let bad = || {
            refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "development_workflow_task_repair_active_work_unqualified",
            )
        };
        if open.len() != 1 || pending != 1 {
            return Err(bad());
        }
        let task = &open[0];
        if DB::string(task, "task_type")? != "activity"
            || DB::string(task, "status")? != "ready"
            || DB::number(task, "attempt_count")? != 0
            || DB::optional_string(task, "lease_owner")?.is_some()
        {
            return Err(bad());
        }
        let payload = DB::document_row(task, "payload")?;
        let activity_id = payload["activity_execution_id"].as_str().ok_or_else(bad)?;
        let activity =
            Self::query("SELECT * FROM activity_executions WHERE id=$1 AND workflow_run_id=$2")
                .bind(activity_id)
                .bind(run_id)
                .fetch_optional(&mut **tx)
                .await?
                .ok_or_else(bad)?;
        if DB::string(&activity, "status")? != "pending"
            || DB::number(&activity, "attempt_count")? != 0
            || DB::optional_string(&activity, "current_attempt_id")?.is_some()
            || DB::optional_instant(&activity, "started_at")?.is_some()
        {
            return Err(bad());
        }
        let metadata =
            activity_metadata(DB::optional_document_row(&activity, "parallel_group_path")?)?;
        let group = metadata["parallel_group_id"].as_str().ok_or_else(bad)?;
        let resolution = Self::query(DB::SELECTION_RESOLUTION_SQL)
            .bind(run_id)
            .bind(group)
            .fetch_optional(&mut **tx)
            .await?
            .ok_or_else(bad)?;
        let winner = DB::document_row(&resolution, "payload")?;
        if winner["operation_kind"] != "timer"
            || winner["outcome"] != "completed"
            || winner["selection_group_base_sequence"] != metadata["parallel_group_base_sequence"]
            || winner["selection_group_size"] != 2
        {
            return Err(bad());
        }
        let timers = Self::scalar("SELECT COUNT(*) FROM workflow_run_timers WHERE workflow_run_id=$1 AND status='pending'")
            .bind(run_id).fetch_one(&mut **tx).await?;
        if timers != 0 {
            return Err(bad());
        }
        Ok(Some("activity"))
    }
}
