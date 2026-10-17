//! Bind native mixed selections to their typed members and physical winner event.
use serde_json::Value;
use std::collections::BTreeMap;

#[derive(Default)]
pub(super) struct SelectionHistory {
    groups: BTreeMap<String, Group>,
    pending: Option<(String, usize)>,
}

#[derive(Default)]
struct Group {
    members: BTreeMap<usize, Member>,
    resolved: bool,
}

struct Member {
    metadata: Value,
    kind: &'static str,
    identity: String,
    terminal: Option<(String, String)>,
}

impl SelectionHistory {
    pub(super) fn complete(&self) -> bool {
        self.pending.is_none() && self.groups.values().all(|group| group.members.len() == 2)
    }

    pub(super) fn apply(&mut self, id: &str, event: &str, payload: &Value) -> bool {
        if event == "SelectionResolved" {
            return self.resolve(payload);
        }
        if self.pending.is_some() {
            return false;
        }
        let (kind, identity_key) = if event.starts_with("Activity") {
            ("activity", "activity_execution_id")
        } else if event.starts_with("Timer") {
            ("timer", "timer_id")
        } else {
            return true;
        };
        let Ok(metadata) = super::selections::metadata(payload, kind) else {
            return false;
        };
        if !super::selections::is_selection(&metadata) {
            return true;
        }
        let Some(group_id) = metadata["parallel_group_id"].as_str() else {
            return true;
        };
        let group_id = group_id.to_owned();
        let (Some(identity), Some(index)) = (
            payload[identity_key].as_str(),
            metadata["parallel_group_index"].as_u64(),
        ) else {
            return false;
        };
        if identity.is_empty() || payload["sequence"] != metadata["selection_member_base_sequence"]
        {
            return false;
        }
        let group = self.groups.entry(group_id.clone()).or_default();
        let index = index as usize;
        if matches!(event, "ActivityScheduled" | "TimerScheduled") {
            if group.resolved
                || group.members.contains_key(&index)
                || group.members.values().any(|member| {
                    member.kind == kind
                        || member.metadata["selection_member_key"]
                            == metadata["selection_member_key"]
                })
            {
                return false;
            }
            group.members.insert(
                index,
                Member {
                    metadata,
                    kind,
                    identity: identity.into(),
                    terminal: None,
                },
            );
            return true;
        }
        let Some(member) = group.members.get_mut(&index) else {
            return false;
        };
        if member.identity != identity
            || member.kind != kind
            || member.metadata != metadata
            || member.terminal.is_some()
        {
            return false;
        }
        if matches!(
            event,
            "ActivityCompleted"
                | "ActivityTimedOut"
                | "TimerFired"
                | "TimerCancelled"
                | "ActivityCancelled"
        ) {
            member.terminal = Some((id.into(), event.into()));
            if !group.resolved {
                if !matches!(
                    event,
                    "ActivityCompleted" | "ActivityTimedOut" | "TimerFired"
                ) {
                    return false;
                }
                self.pending = Some((group_id.clone(), index));
            } else if group
                .members
                .values()
                .all(|member| member.terminal.is_some())
            {
                self.groups.remove(&group_id);
            }
        }
        true
    }

    fn resolve(&mut self, payload: &Value) -> bool {
        let Some((group_id, index)) = self.pending.take() else {
            return false;
        };
        let Some(group) = self.groups.get_mut(&group_id) else {
            return false;
        };
        let Some(member) = group.members.get(&index) else {
            return false;
        };
        let Some((event_id, event_type)) = &member.terminal else {
            return false;
        };
        let metadata = &member.metadata;
        if group.members.len() != 2
            || group.resolved
            || payload["selection_group_id"] != group_id
            || payload["selection_group_base_sequence"] != metadata["parallel_group_base_sequence"]
            || payload["selection_group_size"] != 2
            || payload["member_key"] != metadata["selection_member_key"]
            || payload["member_index"] != index
            || payload["member_base_sequence"] != metadata["selection_member_base_sequence"]
            || payload["member_size"] != 1
            || payload["operation_kind"] != member.kind
            || payload["operation_identity"] != member.identity
            || payload["resolution_event_id"] != *event_id
            || payload["resolution_event_type"] != *event_type
            || payload["outcome"]
                != if event_type == "ActivityTimedOut" {
                    "failed"
                } else {
                    "completed"
                }
        {
            return false;
        }
        group.resolved = true;
        true
    }
}
