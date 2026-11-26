//! Published all barriers retain every qualified authored ancestor.
//!
//! A group frame may record an explicit failure policy
//! (`parallel_group_policy`, .github#232 contract version 1). The policy
//! belongs to the group's identity: every member's frame for that group
//! agrees on it, including on its absence, and the flattened fields repeat
//! the innermost frame's. Absence keeps the recording binding's legacy
//! behavior and is never rewritten as an explicit policy.
use super::transaction::Transaction;
use super::{Result, backend::Backend, refuse, store::Store};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::collections::{BTreeMap, BTreeSet};

/// The recorded failure policy field of a group frame.
pub(super) const POLICY: &str = "parallel_group_policy";

/// The explicit policies native records and runs. A known policy outside
/// this list is refused at admission, before any write, until its runtime
/// behavior is implemented here; it is never recorded as a legacy group.
pub(super) const ADMITTED_POLICIES: &[&str] = &["propagate"];

pub(super) fn invalid() -> super::RuntimeError {
    refuse(
        StatusCode::UNPROCESSABLE_ENTITY,
        "development_parallel_group_unqualified",
    )
}

/// A frame's recorded policy: absent, or exactly one version 1 name. A null,
/// empty, non-string or unknown value is refused, never read as absence.
fn policy(value: &Value) -> Result<Option<&str>> {
    match value.get(POLICY) {
        None => Ok(None),
        Some(policy) => policy
            .as_str()
            .filter(|policy| super::worker_admission::GROUP_POLICIES.contains(policy))
            .map(Some)
            .ok_or_else(invalid),
    }
}

/// The explicit policies a command's group frames record, if any.
pub(super) fn explicit_policies(command: &Value) -> BTreeSet<String> {
    let mut policies = BTreeSet::new();
    let frames = command["parallel_group_path"].as_array().into_iter().flatten();
    for frame in frames.chain(std::iter::once(command)) {
        if let Some(policy) = frame.get(POLICY).and_then(Value::as_str) {
            policies.insert(policy.to_owned());
        }
    }
    policies
}

fn entry(value: &Value, leaf: &str) -> Result<Value> {
    let policy = policy(value)?;
    let base = value["parallel_group_base_sequence"]
        .as_i64()
        .filter(|base| *base > 0)
        .ok_or_else(invalid)?;
    let size = value["parallel_group_size"]
        .as_i64()
        .filter(|size| (1..=super::memo_limits::COMMAND_BATCH_SIZE).contains(size))
        .ok_or_else(invalid)?;
    let index = value["parallel_group_index"]
        .as_i64()
        .filter(|index| (0..size).contains(index))
        .ok_or_else(invalid)?;
    let kind = value["parallel_group_kind"]
        .as_str()
        .filter(|kind| *kind == leaf || *kind == "mixed")
        .ok_or_else(invalid)?;
    if value
        .get("parallel_group_mode")
        .is_some_and(|mode| !mode.is_null() && mode != "all")
        || base.checked_add(size).is_none()
    {
        return Err(invalid());
    }
    let prefix = match kind {
        "activity" => "parallel-activities",
        "timer" => "parallel-timers",
        "child" => "parallel-children",
        _ => "parallel-calls",
    };
    if value["parallel_group_id"] != format!("{prefix}:{base}:{size}") {
        return Err(invalid());
    }
    let mut entry = json!({"parallel_group_id":value["parallel_group_id"],"parallel_group_kind":kind,
        "parallel_group_base_sequence":base,"parallel_group_size":size,"parallel_group_index":index});
    if let Some(policy) = policy {
        entry[POLICY] = json!(policy);
    }
    Ok(entry)
}

pub(super) fn metadata(value: &Value, kind: &str) -> Result<Value> {
    validate_metadata(value, kind, false)
}

pub(super) fn selected_child_metadata(value: &Value) -> Result<Value> {
    validate_metadata(value, "child", true)
}

fn validate_metadata(value: &Value, kind: &str, selected_children: bool) -> Result<Value> {
    // A condition wait is a member as in Core's grouped condition wait
    // (Server #1142): kind `condition`, ids `parallel-calls:<base>:<size>`.
    if !matches!(kind, "activity" | "timer" | "child" | "condition") {
        return Err(invalid());
    }
    let top = entry(value, kind)?;
    let path = value["parallel_group_path"]
        .as_array()
        .filter(|path| !path.is_empty())
        .ok_or_else(invalid)?;
    // Only a child-only all barrier may be nested inside a selection.
    // Standalone child barriers keep their existing single-level contract.
    if kind == "child" && (top["parallel_group_kind"] != "child"
        || (!selected_children && path.len() != 1)
        || (selected_children && path.iter().any(|entry| entry["parallel_group_kind"] != "child")))
    {
        return Err(invalid());
    }
    let path = path
        .iter()
        .map(|value| entry(value, kind))
        .collect::<Result<Vec<_>>>()?;
    if path.last() != Some(&top) {
        return Err(invalid());
    }
    // Nested frames must agree on one policy, or all be legacy, until mixed
    // outcome composition is qualified. A child barrier selected as one
    // selection member keeps the legacy contract: the selection decides
    // its winner and handles.
    if path.iter().any(|group| group.get(POLICY) != top.get(POLICY))
        || (selected_children && top.get(POLICY).is_some())
    {
        return Err(invalid());
    }
    let sequence = top["parallel_group_base_sequence"].as_i64().unwrap()
        + top["parallel_group_index"].as_i64().unwrap();
    let mut parent = None;
    for group in &path {
        let base = group["parallel_group_base_sequence"].as_i64().unwrap();
        let size = group["parallel_group_size"].as_i64().unwrap();
        if base + group["parallel_group_index"].as_i64().unwrap() != sequence
            || parent.is_some_and(|(start, end)| base < start || base + size > end)
        {
            return Err(invalid());
        }
        parent = Some((base, base + size));
    }
    // The flattened policy follows the path, as the contract's histories
    // record it.
    let mut result = top;
    let policy = result.as_object_mut().unwrap().shift_remove(POLICY);
    result["parallel_group_path"] = json!(path);
    if let Some(policy) = policy {
        result[POLICY] = policy;
    }
    Ok(result)
}

/// A new group must have all its leaves in this atomic batch. A partial
/// group may contain only condition occurrences from a previously admitted
/// group; the completion verifies those occurrences against locked history.
pub(super) fn validate_batch(commands: &[Value]) -> Result<BTreeSet<String>> {
    let mut groups = BTreeMap::<String, (Value, BTreeMap<i64, usize>)>::new();
    for (offset, command) in commands.iter().enumerate() {
        if command.get("parallel_group_path").is_none()
            || command["parallel_group_mode"] == "select"
        {
            continue;
        }
        let kind = match command["type"].as_str() {
            Some("schedule_activity") => "activity",
            Some("start_timer") => "timer",
            Some("start_child_workflow") => "child",
            Some("open_condition_wait") => "condition",
            _ => return Err(invalid()),
        };
        let metadata = super::selections::metadata(command, kind)?;
        for entry in metadata["parallel_group_path"].as_array().unwrap().iter()
            .filter(|entry| entry["parallel_group_mode"] != "select")
        {
            let mut descriptor = entry.clone();
            let index = descriptor
                .as_object_mut()
                .unwrap()
                .remove("parallel_group_index")
                .unwrap()
                .as_i64()
                .unwrap();
            let (expected, members) = groups
                .entry(entry["parallel_group_id"].as_str().unwrap().into())
                .or_insert_with(|| (descriptor.clone(), BTreeMap::new()));
            if *expected != descriptor
                || members
                    .insert(index, offset)
                    .is_some_and(|prior| prior != offset)
            {
                return Err(invalid());
            }
        }
    }
    let mut partial_occurrences = BTreeSet::new();
    for (descriptor, members) in groups.values() {
        if members.len() != descriptor["parallel_group_size"].as_u64().unwrap() as usize {
            for offset in members.values() {
                let command = &commands[*offset];
                let occurrence = command["condition_wait_occurrence_id"]
                    .as_str()
                    .filter(|id| !id.is_empty() && command["type"] == "open_condition_wait")
                    .ok_or_else(invalid)?;
                partial_occurrences.insert(occurrence.to_owned());
            }
        }
    }
    Ok(partial_occurrences)
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
    /// Explicit policy admission: native must run every policy the batch
    /// records, and the claiming worker's registered descriptor must list it.
    /// Otherwise the whole completion is refused before any write. A policy
    /// is never dropped, downgraded to a legacy group or partially admitted.
    pub(super) async fn guard_parallel_group_policies(
        &self,
        tx: &mut Transaction<'_, DB>,
        task: &DB::Row,
        commands: &[Value],
    ) -> Result<()> {
        let requested: BTreeSet<String> = commands.iter().flat_map(explicit_policies).collect();
        if requested.is_empty() {
            return Ok(());
        }
        let worker_id = DB::optional_string(task, "lease_owner")?
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "task_not_leased"))?;
        let worker = Self::query("SELECT capability_manifest,status FROM workflow_worker_registrations WHERE namespace=$1 AND worker_id=$2")
            .bind(self.namespace.as_ref()).bind(&worker_id).fetch_optional(&mut **tx).await?;
        let mut registered = Vec::new();
        if let Some(worker) = worker
            && DB::optional_string(&worker, "status")?.is_none_or(|status| status == "active")
            && let Some(manifest) = DB::optional_document_row(&worker, "capability_manifest")?
            && let Some(policies) = manifest[super::worker_admission::PARALLEL_GROUP_POLICIES]["policies"].as_array()
        {
            registered.extend(policies.iter().filter_map(Value::as_str).map(str::to_owned));
        }
        for policy in super::worker_admission::GROUP_POLICIES.iter().filter(|policy| requested.contains(**policy)) {
            let unsupported_by = if !ADMITTED_POLICIES.contains(policy) {
                "server"
            } else if !registered.iter().any(|name| name == policy) {
                "worker"
            } else {
                continue;
            };
            return Err(super::RuntimeError::Protocol {
                status: StatusCode::CONFLICT,
                response: json!({"reason": "parallel_group_policy_not_supported", "parallel_group_policy": policy,
                    "unsupported_by": unsupported_by, "required_capability": super::worker_admission::PARALLEL_GROUP_POLICIES,
                    "worker_id": worker_id, "recorded": false}),
            });
        }
        Ok(())
    }
}

#[derive(Default)]
pub(super) struct AllHistory {
    groups: BTreeMap<String, Group>,
}

struct Group {
    size: usize,
    indices: BTreeSet<usize>,
    members: BTreeMap<String, Member>,
    terminal_count: usize,
}

struct Member {
    metadata: Value,
    kind: &'static str,
    sequence: i64,
    occurrence: Option<String>,
    terminal: bool,
    reopenable: bool,
}

impl AllHistory {
    pub(super) fn complete(&self) -> bool {
        self.groups
            .values()
            .all(|group| group.members.len() == group.size)
    }

    pub(super) fn apply(&mut self, event: &str, payload: &Value) -> bool {
        // A grouped condition's timeout timer belongs to its condition member:
        // only its firing resolves that member (Core `waitCompletedSuccessfully`).
        if event.starts_with("Timer") && payload["timer_kind"] == "signal_timeout" {
            return true;
        }
        let condition_timer = event.starts_with("Timer") && payload["timer_kind"] == "condition_timeout";
        if condition_timer && event != "TimerFired" {
            return true;
        }
        let (kind, key) = if condition_timer || event.starts_with("ConditionWait") {
            ("condition", "condition_wait_id")
        } else if event.starts_with("Activity") {
            ("activity", "activity_execution_id")
        } else if event.starts_with("Timer") {
            ("timer", "timer_id")
        } else if event.starts_with("Child") {
            ("child", "child_call_id")
        } else {
            return true;
        };
        let Ok(metadata) = super::selections::metadata(payload, kind) else {
            return false;
        };
        if metadata.get("parallel_group_id").is_none() || super::selections::is_selection(&metadata)
        {
            return true;
        }
        let mut seen = BTreeSet::new();
        for group in metadata["parallel_group_path"].as_array().unwrap() {
            if seen.insert(group["parallel_group_id"].as_str().unwrap())
                && !self.apply_member(event, payload, &metadata, group, (kind, key))
            {
                return false;
            }
        }
        true
    }

    fn apply_member(
        &mut self,
        event: &str,
        payload: &Value,
        metadata: &Value,
        entry: &Value,
        (kind, key): (&'static str, &str),
    ) -> bool {
        let Some(identity) = payload[key].as_str().filter(|id| !id.is_empty()) else {
            return false;
        };
        let group_id = entry["parallel_group_id"].as_str().unwrap().to_owned();
        let index = entry["parallel_group_index"].as_u64().unwrap() as usize;
        let size = entry["parallel_group_size"].as_u64().unwrap() as usize;
        let Some(sequence) = payload["sequence"].as_i64() else {
            return false;
        };
        if matches!(
            event,
            "ActivityScheduled" | "TimerScheduled" | "ChildWorkflowScheduled" | "ConditionWaitOpened"
        ) {
            let position = entry["parallel_group_base_sequence"].as_i64().unwrap() + index as i64;
            let occurrence = payload["condition_wait_occurrence_id"].as_str()
                .filter(|id| kind == "condition" && !id.is_empty());
            if sequence != position {
                let Some(occurrence) = occurrence.filter(|_| sequence > position) else { return false };
                let Some(group) = self.groups.get_mut(&group_id) else { return false };
                if group.size != size || group.members.contains_key(identity) {
                    return false;
                }
                let Some(previous_id) = group.members.iter().find_map(|(id, member)| {
                    (member.reopenable && member.sequence < sequence && member.metadata == *metadata
                        && member.occurrence.as_deref() == Some(occurrence)).then(|| id.clone())
                }) else { return false };
                group.members.remove(&previous_id);
                group.terminal_count -= 1;
                group.members.insert(identity.into(), Member {
                    metadata: metadata.clone(),
                    kind,
                    sequence,
                    occurrence: Some(occurrence.to_owned()),
                    terminal: false,
                    reopenable: false,
                });
                return true;
            }
            let group = self.groups.entry(group_id).or_insert_with(|| Group {
                size,
                indices: BTreeSet::new(),
                members: BTreeMap::new(),
                terminal_count: 0,
            });
            if group.size != size
                || group.members.contains_key(identity)
                || !group.indices.insert(index)
            {
                return false;
            }
            group.members.insert(
                identity.into(),
                Member {
                    metadata: metadata.clone(),
                    kind,
                    sequence,
                    occurrence: occurrence.map(str::to_owned),
                    terminal: false,
                    reopenable: false,
                },
            );
            return true;
        }
        let Some(group) = self.groups.get_mut(&group_id) else {
            return false;
        };
        let Some(member) = group.members.get_mut(identity) else {
            return false;
        };
        if group.size != size
            || member.terminal
            || member.metadata != *metadata
            || member.kind != kind
            || member.sequence != sequence
        {
            return false;
        }
        if matches!(
            event,
            "ActivityCompleted"
                | "ActivityFailed"
                | "ActivityTimedOut"
                | "ActivityCancelled"
                | "TimerFired"
                | "TimerCancelled"
                | "ChildRunCompleted"
                | "ChildRunFailed"
                | "ChildRunCancelled"
                | "ChildRunTerminated"
                | "ConditionWaitSatisfied"
                | "ConditionWaitTimedOut"
                | "ConditionWaitCancelled"
        ) {
            member.terminal = true;
            member.reopenable = event == "ConditionWaitSatisfied" && member.occurrence.is_some();
            group.terminal_count += 1;
            if group.terminal_count == size && !group.members.values().any(|member| member.reopenable) {
                self.groups.remove(&group_id);
            }
        }
        true
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn frame(base: i64, size: i64, index: i64, policy: Option<Value>) -> Value {
        let mut frame = json!({"parallel_group_id":format!("parallel-activities:{base}:{size}"),
            "parallel_group_kind":"activity","parallel_group_base_sequence":base,"parallel_group_size":size,
            "parallel_group_index":index});
        if let Some(policy) = policy {
            frame[POLICY] = policy;
        }
        frame
    }

    fn command(path: Vec<Value>) -> Value {
        let mut command = path.last().unwrap().clone();
        command["parallel_group_path"] = json!(path);
        command
    }

    #[test]
    fn an_explicit_policy_is_kept_in_every_frame_and_after_the_path() {
        for policy in super::super::worker_admission::GROUP_POLICIES {
            let recorded = metadata(&command(vec![frame(1, 2, 1, Some(json!(policy)))]), "activity").unwrap();
            assert_eq!(recorded[POLICY], *policy);
            assert_eq!(recorded["parallel_group_path"][0][POLICY], *policy);
            let keys: Vec<_> = recorded.as_object().unwrap().keys().map(String::as_str).collect();
            assert_eq!(keys.last(), Some(&POLICY), "{recorded}");
            assert_eq!(keys[keys.len() - 2], "parallel_group_path", "{recorded}");
        }
        // Absence stays absence.
        let legacy = metadata(&command(vec![frame(1, 2, 0, None)]), "activity").unwrap();
        assert!(legacy.get(POLICY).is_none() && legacy["parallel_group_path"][0].get(POLICY).is_none());
        let nested = metadata(&command(vec![frame(1, 3, 1, Some(json!("propagate"))),
            frame(1, 2, 1, Some(json!("propagate")))]), "activity").unwrap();
        assert_eq!(nested["parallel_group_path"].as_array().unwrap().len(), 2);
    }

    #[test]
    fn malformed_mismatched_or_mixed_policies_are_refused() {
        for policy in [Value::Null, json!(""), json!("best_effort"), json!("Propagate"), json!(1), json!(["propagate"])] {
            assert!(metadata(&command(vec![frame(1, 2, 0, Some(policy.clone()))]), "activity").is_err(), "{policy}");
        }
        let mut flattened_only = command(vec![frame(1, 2, 0, None)]);
        flattened_only[POLICY] = json!("propagate");
        let mut frame_only = command(vec![frame(1, 2, 0, Some(json!("propagate")))]);
        frame_only.as_object_mut().unwrap().remove(POLICY);
        let mut different = command(vec![frame(1, 2, 0, Some(json!("propagate")))]);
        different[POLICY] = json!("settle");
        for refused in [flattened_only, frame_only, different,
            command(vec![frame(1, 3, 0, Some(json!("propagate"))), frame(1, 2, 0, None)]),
            command(vec![frame(1, 3, 0, None), frame(1, 2, 0, Some(json!("propagate")))]),
            command(vec![frame(1, 3, 0, Some(json!("settle"))), frame(1, 2, 0, Some(json!("propagate")))])]
        {
            assert!(metadata(&refused, "activity").is_err(), "{refused}");
        }
        // A policy needs a group.
        assert!(super::super::selections::metadata(&json!({(POLICY): "propagate"}), "activity").is_err());
    }

    #[test]
    fn a_batch_refuses_members_that_disagree_on_the_policy() {
        let member = |index: i64, policy: Option<&str>| {
            let mut command = command(vec![frame(1, 2, index, policy.map(|policy| json!(policy)))]);
            command["type"] = json!("schedule_activity");
            command
        };
        assert!(validate_batch(&[member(0, Some("propagate")), member(1, Some("propagate"))]).is_ok());
        for (first, second) in [(Some("propagate"), None), (None, Some("propagate")), (Some("propagate"), Some("settle"))] {
            assert!(validate_batch(&[member(0, first), member(1, second)]).is_err(), "{first:?} {second:?}");
        }
        let explicit = member(0, Some("propagate"));
        assert_eq!(explicit_policies(&explicit), BTreeSet::from(["propagate".to_owned()]));
        assert!(explicit_policies(&member(0, None)).is_empty());
    }

    #[test]
    fn grouped_replacement_keeps_the_completed_barriers_original_membership() {
        let metadata = json!({"parallel_group_id":"parallel-calls:1:2","parallel_group_kind":"mixed",
            "parallel_group_base_sequence":1,"parallel_group_size":2,"parallel_group_index":0});
        let mut wait = metadata.clone();
        wait["parallel_group_path"] = json!([metadata]);
        wait["sequence"] = json!(1);
        wait["condition_wait_id"] = json!("first");
        wait["condition_wait_occurrence_id"] = json!("php:condition-wait:0");
        let mut sibling = metadata.clone();
        sibling["parallel_group_index"] = json!(1);
        let mut timer = sibling.clone();
        timer["parallel_group_path"] = json!([sibling]);
        timer["sequence"] = json!(2);
        timer["timer_id"] = json!("sibling");

        let mut history = AllHistory::default();
        assert!(history.apply("ConditionWaitOpened", &wait));
        assert!(history.apply("TimerScheduled", &timer));
        assert!(history.apply("TimerFired", &timer));
        assert!(history.apply("ConditionWaitSatisfied", &wait));
        let mut changed = wait.clone();
        changed["sequence"] = json!(3);
        changed["condition_wait_id"] = json!("replacement");
        changed["condition_wait_occurrence_id"] = json!("different");
        assert!(!history.apply("ConditionWaitOpened", &changed));

        changed["condition_wait_occurrence_id"] = wait["condition_wait_occurrence_id"].clone();
        assert!(history.apply("ConditionWaitOpened", &changed));
        assert!(history.complete());
        assert!(history.apply("ConditionWaitSatisfied", &changed));
    }
}
