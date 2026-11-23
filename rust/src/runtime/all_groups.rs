//! Published all barriers retain every qualified authored ancestor.
use super::{Result, refuse};
use axum::http::StatusCode;
use serde_json::{Value, json};
use std::collections::{BTreeMap, BTreeSet};

pub(super) fn invalid() -> super::RuntimeError {
    refuse(
        StatusCode::UNPROCESSABLE_ENTITY,
        "development_parallel_group_unqualified",
    )
}

fn entry(value: &Value, leaf: &str) -> Result<Value> {
    // Until explicit policies are admitted, never turn one into a legacy group.
    if value.get("parallel_group_policy").is_some() {
        return Err(invalid());
    }
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
    Ok(
        json!({"parallel_group_id":value["parallel_group_id"],"parallel_group_kind":kind,
        "parallel_group_base_sequence":base,"parallel_group_size":size,"parallel_group_index":index}),
    )
}

pub(super) fn metadata(value: &Value, kind: &str) -> Result<Value> {
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
    // Child selections, nested barriers and mixed child groups have not been
    // qualified. Keep their refusal independent of activity/timer paths.
    if kind == "child" && (path.len() != 1 || top["parallel_group_kind"] != "child") {
        return Err(invalid());
    }
    let path = path
        .iter()
        .map(|value| entry(value, kind))
        .collect::<Result<Vec<_>>>()?;
    if path.last() != Some(&top) {
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
    let mut result = top;
    result["parallel_group_path"] = json!(path);
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
        let metadata = metadata(command, kind)?;
        for entry in metadata["parallel_group_path"].as_array().unwrap() {
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
