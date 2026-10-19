//! Published activity/timer all barriers retain every authored ancestor.
use super::{Result, refuse};
use axum::http::StatusCode;
use serde_json::{Value, json};
use std::collections::{BTreeMap, BTreeSet};

fn invalid() -> super::RuntimeError {
    refuse(
        StatusCode::UNPROCESSABLE_ENTITY,
        "development_parallel_group_unqualified",
    )
}

fn entry(value: &Value, leaf: &str) -> Result<Value> {
    let base = value["parallel_group_base_sequence"]
        .as_i64()
        .filter(|base| *base > 0)
        .ok_or_else(invalid)?;
    let size = value["parallel_group_size"]
        .as_i64()
        .filter(|size| (1..=100).contains(size))
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
    if !matches!(kind, "activity" | "timer") {
        return Err(invalid());
    }
    let top = entry(value, kind)?;
    let path = value["parallel_group_path"]
        .as_array()
        .filter(|path| !path.is_empty())
        .ok_or_else(invalid)?;
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

/// Every group in a path must have all its leaves in this atomic batch.
pub(super) fn validate_batch(commands: &[Value]) -> Result<()> {
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
    for (descriptor, members) in groups.values() {
        if members.len() != descriptor["parallel_group_size"].as_u64().unwrap() as usize {
            return Err(invalid());
        }
    }
    Ok(())
}

#[derive(Default)]
pub(super) struct AllHistory {
    groups: BTreeMap<String, Group>,
}

struct Group {
    size: usize,
    members: BTreeMap<usize, Member>,
}

struct Member {
    metadata: Value,
    kind: &'static str,
    identity: String,
    terminal: bool,
}

impl AllHistory {
    pub(super) fn complete(&self) -> bool {
        self.groups
            .values()
            .all(|group| group.members.len() == group.size)
    }

    pub(super) fn apply(&mut self, event: &str, payload: &Value) -> bool {
        let (kind, key) = if event.starts_with("Activity") {
            ("activity", "activity_execution_id")
        } else if event.starts_with("Timer") {
            ("timer", "timer_id")
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
        if payload["sequence"].as_i64()
            != Some(entry["parallel_group_base_sequence"].as_i64().unwrap() + index as i64)
        {
            return false;
        }
        if matches!(event, "ActivityScheduled" | "TimerScheduled") {
            let group = self.groups.entry(group_id).or_insert_with(|| Group {
                size,
                members: BTreeMap::new(),
            });
            if group.size != size
                || group.members.contains_key(&index)
                || group
                    .members
                    .values()
                    .any(|member| member.identity == identity)
            {
                return false;
            }
            group.members.insert(
                index,
                Member {
                    metadata: metadata.clone(),
                    kind,
                    identity: identity.into(),
                    terminal: false,
                },
            );
            return true;
        }
        let Some(group) = self.groups.get_mut(&group_id) else {
            return false;
        };
        let Some(member) = group.members.get_mut(&index) else {
            return false;
        };
        if group.size != size
            || member.terminal
            || member.metadata != *metadata
            || member.identity != identity
            || member.kind != kind
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
        ) {
            member.terminal = true;
            if group.members.len() == size && group.members.values().all(|member| member.terminal) {
                self.groups.remove(&group_id);
            }
        }
        true
    }
}
