//! Bind native mixed selections to their typed members and physical winner event.
use super::selections::{member_follows, member_identity, member_position, selection};
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
    child_call_id: Option<String>,
    condition: Option<ConditionMember>,
    terminal: Option<(String, String)>,
}

struct ConditionMember {
    id: String,
    sequence: i64,
    occurrence: Value,
    reopening: bool,
}

impl Member {
    fn entry(&self) -> &Value {
        selection(&self.metadata).expect("selection member metadata")
    }

    fn completed(&self) -> bool {
        self.terminal.as_ref().is_some_and(|(_, event)| event == "ChildRunCompleted")
    }
}

impl Group {
    fn complete(&self) -> bool {
        self.members.last_key_value().is_some_and(|(_, member)| {
            member.entry()["parallel_group_size"] == self.members.len()
                && member.entry()["selection_member_index"] == 1
        })
    }

    fn accepts(&self, entry: &Value, index: usize) -> bool {
        !self.resolved && index == self.members.len()
            && member_follows(self.members.last_key_value().map(|(_, member)| member.entry()), entry)
    }

    fn child_group_completed(&self, entry: &Value) -> bool {
        let base = entry["selection_member_base_sequence"].as_i64().unwrap()
            - entry["parallel_group_base_sequence"].as_i64().unwrap();
        let size = entry["selection_member_size"].as_i64().unwrap();
        (base..base + size).all(|index| {
            self.members.get(&(index as usize)).is_some_and(Member::completed)
        })
    }
}

impl SelectionHistory {
    pub(super) fn complete(&self) -> bool {
        self.pending.is_none() && self.groups.values().all(|group| {
            group.complete() && group.members.values().all(|member| {
                member.condition.as_ref().is_none_or(|condition| !condition.reopening)
            })
        })
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
        } else if event == "SignalWaitOpened" || event == "SignalApplied"
            || (event == "TimerFired" && payload["timer_kind"] == "signal_timeout")
        {
            ("signal", "signal_wait_id")
        } else if event == "ConditionWaitOpened" || event == "ConditionWaitSatisfied"
            || (event == "TimerFired" && payload["timer_kind"] == "condition_timeout")
        {
            ("condition", "condition_wait_id")
        } else if matches!(event,
            "ChildWorkflowScheduled" | "ChildRunCompleted" | "ChildRunFailed"
            | "ChildRunCancelled" | "ChildRunTerminated"
        ) {
            ("child", "child_workflow_run_id")
        } else if matches!(payload["timer_kind"].as_str(), Some("signal_timeout" | "condition_timeout")) {
            return true;
        } else if event.starts_with("Timer") {
            ("timer", "timer_id")
        } else {
            return true;
        };
        let Ok(metadata) = super::selections::metadata(payload, kind) else {
            return false;
        };
        let Some(entry) = selection(&metadata) else {
            return true;
        };
        let group_id = entry["parallel_group_id"].as_str().unwrap().to_owned();
        let Some(identity) = payload[identity_key].as_str().filter(|id| !id.is_empty()) else {
            return false;
        };
        let index = entry["parallel_group_index"].as_u64().unwrap() as usize;
        let Some(sequence) = payload["sequence"].as_i64() else {
            return false;
        };
        let child_call_id = if kind == "child" {
            let Some(call_id) = payload["child_call_id"].as_str().filter(|id| !id.is_empty()) else {
                return false;
            };
            Some(call_id.to_owned())
        } else {
            None
        };
        let group = self.groups.entry(group_id.clone()).or_default();
        if event == "ConditionWaitOpened" && sequence > member_position(entry) {
            let Some(member) = group.members.get_mut(&index) else { return false };
            let Some(condition) = member.condition.as_mut() else { return false };
            if !condition.reopening || member.terminal.is_some() || member.metadata != metadata
                || condition.occurrence != payload["condition_wait_occurrence_id"]
                || condition.sequence >= sequence || condition.id == identity
            {
                return false;
            }
            condition.id = identity.into();
            condition.sequence = sequence;
            condition.reopening = false;
            return true;
        }
        if matches!(event, "ActivityScheduled" | "TimerScheduled" | "ChildWorkflowScheduled" | "SignalWaitOpened" | "ConditionWaitOpened") {
            if sequence != member_position(entry) || !group.accepts(entry, index) {
                return false;
            }
            group.members.insert(
                index,
                Member {
                    metadata,
                    kind,
                    identity: identity.into(),
                    child_call_id,
                    condition: (kind == "condition").then(|| ConditionMember {
                        id: identity.into(),
                        sequence,
                        occurrence: payload["condition_wait_occurrence_id"].clone(),
                        reopening: false,
                    }),
                    terminal: None,
                },
            );
            return true;
        }
        if !group.complete() {
            return false;
        }
        let Some(member) = group.members.get_mut(&index) else {
            return false;
        };
        let correct_subject = if let Some(condition) = member.condition.as_ref() {
            !condition.reopening
                && condition.id == identity
                && condition.sequence == sequence
                && condition.occurrence == payload["condition_wait_occurrence_id"]
        } else {
            sequence == member_position(entry)
                && (member.child_call_id.is_some() || member.identity == identity)
        };
        if !correct_subject || member.child_call_id != child_call_id
            || member.kind != kind
            || member.metadata != metadata
            || member.terminal.is_some()
        {
            return false;
        }
        if let Some(reopened) = payload.get("condition_wait_reopened") {
            let Some(condition) = member.condition.as_mut() else { return false };
            if event != "ConditionWaitSatisfied" || reopened != true
                || condition.occurrence.as_str().is_none_or(str::is_empty)
            {
                return false;
            }
            condition.reopening = true;
            return true;
        }
        if matches!(
            event,
            "ActivityCompleted"
                | "ActivityTimedOut"
                | "TimerFired"
                | "TimerCancelled"
                | "ActivityCancelled"
                | "ChildRunCompleted"
                | "ChildRunFailed"
                | "ChildRunCancelled"
                | "ChildRunTerminated"
                | "SignalApplied"
                | "ConditionWaitSatisfied"
        ) {
            member.terminal = Some((id.into(), event.into()));
            if !group.resolved {
                if !matches!(
                    event,
                    "ActivityCompleted" | "ActivityTimedOut" | "TimerFired"
                        | "ChildRunCompleted" | "ChildRunFailed" | "ChildRunCancelled"
                        | "ChildRunTerminated" | "SignalApplied" | "ConditionWaitSatisfied"
                ) {
                    return false;
                }
                if entry["selection_member_kind"] != "group"
                    || event != "ChildRunCompleted" || group.child_group_completed(entry)
                {
                    self.pending = Some((group_id.clone(), index));
                }
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
        let entry = member.entry();
        if !group.complete()
            || group.resolved
            || payload["selection_group_id"] != group_id
            || payload["selection_group_base_sequence"] != entry["parallel_group_base_sequence"]
            || payload["selection_group_size"] != entry["parallel_group_size"]
            || payload["member_key"] != entry["selection_member_key"]
            || payload["member_index"] != entry["selection_member_index"]
            || payload["member_base_sequence"] != entry["selection_member_base_sequence"]
            || payload["member_size"] != entry["selection_member_size"]
            || payload["operation_kind"] != entry["selection_member_kind"]
            || payload["operation_identity"] != member_identity(entry, &member.identity)
            || payload["resolution_event_id"] != *event_id
            || payload["resolution_event_type"] != *event_type
            || payload["outcome"] != super::selections::outcome(event_type)
        {
            return false;
        }
        group.resolved = true;
        true
    }
}
