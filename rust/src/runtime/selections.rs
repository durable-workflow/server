//! Bounded two-member selection, with a durable first committed winner.
use super::transaction::Transaction;
use super::{Result, backend::Backend, memo_limits::COMMAND_BATCH_SIZE, refuse, store::Store};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::collections::{BTreeMap, BTreeSet};

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

pub(super) fn selection(value: &Value) -> Option<&Value> {
    if value["parallel_group_mode"] == "select" {
        return Some(value);
    }
    value["parallel_group_path"].as_array()?.first()
        .filter(|entry| entry["parallel_group_mode"] == "select")
}

pub(super) fn is_selection(value: &Value) -> bool {
    selection(value).is_some()
}

pub(super) fn member_position(entry: &Value) -> i64 {
    entry["parallel_group_base_sequence"].as_i64().unwrap()
        + entry["parallel_group_index"].as_i64().unwrap()
}

pub(super) fn reopened_condition(payload: &Value) -> bool {
    payload["parallel_group_mode"] == "select"
        && payload["selection_member_kind"] == "condition"
        && matches!(
            (payload["sequence"].as_i64(), payload["selection_member_base_sequence"].as_i64()),
            (Some(physical), Some(logical)) if physical > logical
        )
}

pub(super) fn member_identity(entry: &Value, operation_identity: &str) -> String {
    if entry["selection_member_kind"] == "group" {
        format!(
            "group:{}:{}",
            entry["selection_member_base_sequence"], entry["selection_member_size"]
        )
    } else {
        operation_identity.to_owned()
    }
}

fn same_member(first: &Value, second: &Value) -> bool {
    FIELDS.iter().filter(|field| **field != "parallel_group_index")
        .all(|field| first[*field] == second[*field])
}

pub(super) fn member_follows(previous: Option<&Value>, entry: &Value) -> bool {
    let Some(previous) = previous else {
        return entry["parallel_group_index"] == 0 && entry["selection_member_index"] == 0;
    };
    let position = member_position(entry);
    if previous["parallel_group_id"] != entry["parallel_group_id"]
        || member_position(previous) + 1 != position
    {
        return false;
    }
    let end = |member: &Value| {
        member["selection_member_base_sequence"].as_i64().unwrap()
            + member["selection_member_size"].as_i64().unwrap()
    };
    if position < end(previous) {
        return same_member(previous, entry);
    }
    let selection_end = entry["parallel_group_base_sequence"].as_i64().unwrap()
        + entry["parallel_group_size"].as_i64().unwrap();
    previous["selection_member_index"] == 0
        && entry["selection_member_index"] == 1
        && previous["selection_member_key"] != entry["selection_member_key"]
        && end(previous) == position
        && end(entry) == selection_end
        && pair_kind(
            previous["selection_member_kind"].as_str().unwrap(),
            entry["selection_member_kind"].as_str().unwrap(),
        ).is_some_and(|kind| {
            previous["parallel_group_kind"] == kind && entry["parallel_group_kind"] == kind
        })
}

pub(super) struct ChildSelectionAwait<'a> {
    pub sequence: i64,
    pub size: i64,
    operation_identity: &'a str,
    group: &'a str,
    pub waiting: bool,
}

impl<'a> ChildSelectionAwait<'a> {
    pub(super) fn parse(handle: &'a Value) -> Option<Self> {
        if handle.as_object()?.len() != 6 {
            return None;
        }
        let sequence = handle["member_base_sequence"].as_i64().filter(|sequence| *sequence > 0)?;
        let size = handle["member_size"].as_i64()
            .filter(|size| (1..COMMAND_BATCH_SIZE).contains(size))?;
        let end = sequence.checked_add(size)?;
        handle["await_position"].as_i64().filter(|position| *position >= end)?;
        let operation_identity = handle["operation_identity"].as_str()
            .filter(|identity| !identity.is_empty() && identity.len() <= 191)?;
        let group = handle["selection_group_id"].as_str().filter(|group| !group.is_empty() && group.len() <= 191)?;
        let waiting = match handle["status"].as_str()? {
            "waiting" => true,
            "resumed" => false,
            _ => return None,
        };
        Some(Self { sequence, size, operation_identity, group, waiting })
    }

    pub(super) fn matches(&self, identity: &str, metadata: &Value) -> bool {
        selection(metadata).is_some_and(|entry| {
            matches!(entry["selection_member_kind"].as_str(), Some("child" | "group"))
                && entry["parallel_group_id"] == self.group
                && entry["selection_member_base_sequence"] == self.sequence
                && entry["selection_member_size"] == self.size
                && member_identity(entry, identity) == self.operation_identity
        })
    }
}

pub(super) fn child_status_failed(status: &str) -> bool {
    matches!(status, "failed" | "cancelled" | "terminated")
}

fn pair_kind(first: &str, second: &str) -> Option<&'static str> {
    match (first, second) {
        ("activity", "timer") | ("timer", "activity") | ("child", "signal") | ("signal", "child")
        | ("child" | "group", "condition") | ("condition", "child" | "group")
        | ("group", "signal") | ("signal", "group") => Some("mixed"),
        ("signal", "signal") => Some("signal"),
        _ => None,
    }
}

pub(super) fn metadata(value: &Value, kind: &str) -> Result<Value> {
    if !present(value) {
        return Ok(json!({}));
    }
    let Some(entry) = selection(value) else {
        return super::all_groups::metadata(value, kind);
    };
    let path = value["parallel_group_path"].as_array().ok_or_else(unqualified)?;
    let outer = selection_entry(entry, kind)?;
    if path.first() != Some(&outer) {
        return Err(unqualified());
    }
    if outer["selection_member_kind"] != "group" {
        if path.len() != 1 || value["parallel_group_mode"] != "select" {
            return Err(unqualified());
        }
        let mut leaf = outer;
        leaf["parallel_group_path"] = Value::Array(path.clone());
        return Ok(leaf);
    }
    if path.len() < 2 || value["parallel_group_mode"] == "select"
        || value["parallel_group_kind"] != "child"
        || FIELDS[6..].iter().any(|field| value.get(*field).is_some())
    {
        return Err(unqualified());
    }
    let mut suffix = value.clone();
    suffix["parallel_group_path"] = json!(&path[1..]);
    let mut leaf = super::all_groups::selected_child_metadata(&suffix)?;
    let inner = &leaf["parallel_group_path"][0];
    if inner["parallel_group_base_sequence"] != outer["selection_member_base_sequence"]
        || inner["parallel_group_size"] != outer["selection_member_size"]
        || member_position(inner) != member_position(&outer)
    {
        return Err(unqualified());
    }
    leaf["parallel_group_path"].as_array_mut().unwrap().insert(0, outer);
    Ok(leaf)
}

fn selection_entry(value: &Value, kind: &str) -> Result<Value> {
    let base = value["parallel_group_base_sequence"].as_i64()
        .filter(|base| *base > 0).ok_or_else(unqualified)?;
    let size = value["parallel_group_size"].as_i64()
        .filter(|size| (2..=COMMAND_BATCH_SIZE).contains(size)).ok_or_else(unqualified)?;
    let end = base.checked_add(size).ok_or_else(unqualified)?;
    let index = value["parallel_group_index"].as_i64()
        .filter(|index| (0..size).contains(index)).ok_or_else(unqualified)?;
    let member_base = value["selection_member_base_sequence"].as_i64()
        .filter(|position| (base..end).contains(position)).ok_or_else(unqualified)?;
    let member_size = value["selection_member_size"].as_i64()
        .filter(|size| (1..=end - member_base).contains(size)).ok_or_else(unqualified)?;
    let key = &value["selection_member_key"];
    let member_kind = &value["selection_member_kind"];
    let group = member_kind == "group";
    if !matches!(kind, "activity" | "timer" | "child" | "signal" | "condition")
        || value["parallel_group_id"] != format!("select-calls:{base}:{size}")
        || (value["parallel_group_kind"] != "mixed"
            && !(kind == "signal" && value["parallel_group_kind"] == "signal"))
        || !matches!(value["selection_member_index"].as_i64(), Some(0 | 1))
        || !(member_base..member_base + member_size).contains(&(base + index))
        || (group && kind != "child")
        || (!group && (member_kind != kind || member_size != 1))
        || !(key.as_str().is_some_and(|key| !key.is_empty())
            || key.as_i64().is_some_and(|key| key >= 0))
    {
        return Err(unqualified());
    }
    let mut entry = serde_json::Map::new();
    for field in FIELDS {
        entry.insert(
            field.to_owned(),
            value.get(field).cloned().ok_or_else(unqualified)?,
        );
    }
    Ok(Value::Object(entry))
}

pub(super) fn fields(command: &Value, ordinary: &[&str]) -> Result<()> {
    let mut allowed = ordinary.to_vec();
    allowed.extend(FIELDS);
    allowed.push("parallel_group_path");
    super::store::reject_fields(command, &allowed)
}

/// A new selection must arrive in one atomic batch. A partial batch may
/// repeat a condition only after its occurrence matches the locked history.
pub(super) fn validate_batch(commands: &[Value]) -> Result<BTreeSet<String>> {
    let mut partial_occurrences = super::all_groups::validate_batch(commands)?;
    let mut groups = BTreeMap::<String, Vec<(usize, Value)>>::new();
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
        let leaf = metadata(command, kind)?;
        let group = selection(&leaf).unwrap()["parallel_group_id"].as_str().unwrap();
        groups.entry(group.to_owned()).or_default().push((offset, leaf));
    }
    let mut new_selection = false;
    for members in groups.into_values() {
        let (first_offset, first_leaf) = &members[0];
        let first = selection(first_leaf).unwrap();
        if first["parallel_group_size"] != members.len() {
            for (offset, _) in members {
                let command = &commands[offset];
                let occurrence = command["condition_wait_occurrence_id"].as_str()
                    .filter(|id| !id.is_empty() && command["type"] == "open_condition_wait")
                    .ok_or_else(unqualified)?;
                if !partial_occurrences.insert(occurrence.to_owned()) {
                    return Err(unqualified());
                }
            }
            continue;
        }
        if new_selection {
            return Err(unqualified());
        }
        new_selection = true;
        let mut previous = None;
        for (index, (offset, leaf)) in members.iter().enumerate() {
            let entry = selection(leaf).unwrap();
            if *offset != first_offset + index || !member_follows(previous, entry) {
                return Err(unqualified());
            }
            previous = Some(entry);
        }
        if previous.is_none_or(|entry| entry["selection_member_index"] != 1) {
            return Err(unqualified());
        }
    }
    Ok(partial_occurrences)
}

pub(super) fn check_sequence(metadata: &Value, sequence: i64) -> Result<()> {
    if let Some(entry) = selection(metadata) {
        return if member_position(entry) == sequence {
            Ok(())
        } else {
            Err(refuse(StatusCode::CONFLICT, "selection_sequence_mismatch"))
        };
    }
    if let Some(path) = metadata["parallel_group_path"].as_array()
        && path.iter().any(|entry| member_position(entry) != sequence)
    {
        return Err(refuse(StatusCode::CONFLICT, "parallel_sequence_mismatch"));
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
        // In a retry chain the original link continues to name the handle.
        // Read one original link per authored child within the bounded span.
        let original = Self::query(
            "SELECT l.id,l.sequence,l.child_workflow_run_id,l.parallel_group_path,c.metadata,c.status AS call_status
             FROM workflow_child_calls c
             JOIN workflow_links l ON c.parent_workflow_run_id=l.parent_workflow_run_id AND c.sequence=l.sequence
             JOIN workflow_runs r ON r.id=l.child_workflow_run_id AND r.run_number=1
             WHERE c.parent_workflow_run_id=$1 AND c.sequence>=$2 AND c.sequence<$3
             AND l.link_type='child_workflow' ORDER BY l.sequence LIMIT $4",
        )
        .bind(run_id).bind(authored.sequence).bind(authored.sequence + authored.size).bind(authored.size + 1)
        .fetch_all(&mut **tx).await?;
        if original.len() as i64 != authored.size {
            return Err(invalid());
        }
        let mut all_completed = true;
        let mut failed = false;
        for (offset, row) in original.iter().enumerate() {
            let metadata = child_metadata(DB::optional_document_row(row, "parallel_group_path")?)?;
            if DB::number(row, "sequence")? != authored.sequence + offset as i64
                || DB::document_row(row, "metadata")?["child_call_id"] != DB::string(row, "id")?
                || !authored.matches(&DB::string(row, "child_workflow_run_id")?, &metadata)
            {
                return Err(invalid());
            }
            let status = DB::string(row, "call_status")?;
            all_completed &= status == "completed";
            failed |= child_status_failed(&status);
        }
        let previous = Self::latest_child_selection_await(tx, run_id).await?;
        if previous == *handle {
            return Ok(());
        }
        if !authored.waiting {
            let mut waiting = handle.clone();
            waiting["status"] = json!("waiting");
            if previous != waiting || !(all_completed || failed) {
                return Err(invalid());
            }
        }
        Self::append(tx, run_id, "ChildWorkflowAwaited", json!({"selection_handle": handle}), Some(task_id), None).await
    }

    async fn latest_child_selection_await(tx: &mut Transaction<'_, DB>, run_id: &str) -> Result<Value> {
        // A condition retry writes a new physical wait for an old member.
        // Only a new logical wait supersedes the child-await marker.
        let mut before = i64::MAX;
        loop {
            let page = Self::query(
                "SELECT sequence,event_type,payload FROM workflow_history_events WHERE workflow_run_id=$1
                 AND sequence<$2 AND event_type IN ('ChildWorkflowAwaited','SignalWaitOpened','ConditionWaitOpened')
                 ORDER BY sequence DESC LIMIT 16",
            ).bind(run_id).bind(before).fetch_all(&mut **tx).await?;
            for event in &page {
                let kind = DB::string(event, "event_type")?;
                let payload = DB::document_row(event, "payload")?;
                if kind == "ConditionWaitOpened" && reopened_condition(&payload) {
                    before = DB::number(event, "sequence")?;
                    continue;
                }
                return Ok(if kind == "ChildWorkflowAwaited" {
                    payload["selection_handle"].clone()
                } else {
                    Value::Null
                });
            }
            if page.len() < 16 {
                return Ok(Value::Null);
            }
        }
    }

    async fn selection_condition_identity(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        entry: &Value,
        completion: &Value,
        current_id: &str,
    ) -> Result<String> {
        if !reopened_condition(completion) {
            return Ok(current_id.into());
        }
        let mismatch = || refuse(StatusCode::CONFLICT, "selection_resolution_mismatch");
        let occurrence = completion["condition_wait_occurrence_id"].as_str().ok_or_else(mismatch)?;
        let waits = Self::waits(tx, run_id).await?;
        let original = waits.conditions.iter().find(|wait| {
            wait.sequence == Some(member_position(entry)) && wait.occurrence() == Some(occurrence)
        }).ok_or_else(mismatch)?;
        let opening = waits.condition_opening(&original.id).ok_or_else(mismatch)?;
        let metadata = metadata(&opening.payload, "condition")?;
        if !selection(&metadata).is_some_and(|original| same_member(original, entry)) {
            return Err(mismatch());
        }
        Ok(original.id.clone())
    }

    pub(super) async fn resolve_selection(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        metadata: &Value,
        kind: &str,
        identity: &str,
        event_type: &str,
    ) -> Result<bool> {
        let Some(entry) = selection(metadata) else {
            return Ok(false);
        };
        let group_id = entry["parallel_group_id"].as_str().unwrap();
        let operation_kind = if entry["selection_member_kind"] == "group" { "group" } else { kind };
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
        let event = Self::query("SELECT id,sequence,event_type,payload FROM workflow_history_events WHERE workflow_run_id=$1 ORDER BY sequence DESC LIMIT 1")
            .bind(run_id).fetch_one(&mut **tx).await?;
        if DB::string(&event, "event_type")? != event_type {
            return Err(refuse(
                StatusCode::CONFLICT,
                "selection_resolution_mismatch",
            ));
        }
        let identity = if kind == "condition" {
            let payload = DB::document_row(&event, "payload")?;
            Self::selection_condition_identity(tx, run_id, entry, &payload, identity).await?
        } else {
            member_identity(entry, identity)
        };
        let mut resolution = json!({
            "selection_group_id":group_id,"selection_group_base_sequence":entry["parallel_group_base_sequence"],
            "selection_group_size":entry["parallel_group_size"],"member_key":entry["selection_member_key"],
            "member_index":entry["selection_member_index"],"member_base_sequence":entry["selection_member_base_sequence"],
            "member_size":entry["selection_member_size"],"operation_kind":operation_kind,
            "operation_identity":identity,"outcome":outcome(event_type),
            "resolution_event_id":DB::string(&event,"id")?,"resolution_event_type":event_type,
        });
        if kind == "child"
            && Self::ordered_selection_has_open_condition(tx, run_id, group_id).await?
        {
            if Self::query(DB::SELECTION_CANDIDATE_SQL).bind(run_id).bind(group_id)
                .fetch_optional(&mut **tx).await?.is_none()
            {
                // The child terminal is authoritative. The winner waits for
                // the parent's predicate over inputs received before it.
                resolution["resolution_event_sequence"] = json!(DB::number(&event, "sequence")?);
                Self::append(tx, run_id, "SelectionCandidateReady", resolution, None, None).await?;
            }
            return Ok(false);
        }
        Self::append(tx, run_id, "SelectionResolved", resolution, None, None).await?;
        Ok(true)
    }

    async fn ordered_selection_has_open_condition(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        group_id: &str,
    ) -> Result<bool> {
        if Self::query(DB::ORDERED_CONDITION_HISTORY)
            .bind(run_id).fetch_optional(&mut **tx).await?.is_none()
        {
            return Ok(false);
        }
        let waits = Self::waits(tx, run_id).await?;
        Ok(waits.conditions.iter().filter(|wait| wait.is_open())
            .filter_map(|wait| waits.condition_opening(&wait.id))
            .any(|opening| super::wait_timeouts::uses_condition_input_order(&opening.payload)
                && selection(&opening.payload).is_some_and(|entry| entry["parallel_group_id"] == group_id)))
    }

    pub(super) async fn unresolved_selection_candidate(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        opening: &Value,
    ) -> Result<Option<Value>> {
        let Some(group_id) = selection(opening).and_then(|entry| entry["parallel_group_id"].as_str()) else {
            return Ok(None);
        };
        if Self::query(DB::SELECTION_RESOLUTION_SQL)
            .bind(run_id).bind(group_id).fetch_optional(&mut **tx).await?.is_some()
        {
            return Ok(None);
        }
        let candidate = Self::query(DB::SELECTION_CANDIDATE_SQL)
            .bind(run_id).bind(group_id).fetch_optional(&mut **tx).await?;
        candidate.map(|row| DB::document_row(&row, "payload")).transpose()
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
        for group in path.iter().filter(|group| group["parallel_group_mode"] != "select") {
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
