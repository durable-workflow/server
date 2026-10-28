//! A run's signal and condition waits, derived from its history as Core
//! 2.15.1 derives them (`SignalWaits::forRun`, `ConditionWaits::forRun`).
//! Every decision about open waits, pending identities and resumption reads
//! this one view, so none can disagree with another. Like Core, it reads
//! the run's wait-related history on each use: one query through the
//! `workflow_run_id` index, limited to those event types and columns. The
//! fold indexes waits by id, so it is linear in that history.
use super::{Result, backend::Backend, store::Store, transaction::Transaction};
use serde_json::{Map, Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::collections::{HashMap, HashSet};

const WAIT_EVENTS_SQL: &str = "SELECT sequence,event_type,payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type IN ('SignalWaitOpened','SignalReceived','SignalApplied','SignalWaitCancelled','ConditionWaitOpened','ConditionWaitSatisfied','ConditionWaitTimedOut','ConditionWaitCancelled','SelectionOperationCancelled','TimerScheduled','TimerFired','TimerCancelled') ORDER BY sequence";

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(super) enum SignalStatus {
    Open,
    /// A `SignalReceived` holds the wait until its signal is applied.
    Received,
    Applied,
    TimedOut,
    Cancelled,
}

#[derive(Debug)]
pub(super) struct SignalWait {
    pub(super) id: String,
    pub(super) name: String,
    pub(super) sequence: Option<i64>,
    pub(super) status: SignalStatus,
}

/// The first `SignalWaitOpened` / `ConditionWaitOpened` that named a wait's
/// id explicitly, as `recordAppliedSignalForSignalResume` and
/// `recordSatisfiedConditionWaitForSignalResume` find it.
#[derive(Debug)]
pub(super) struct Opening {
    /// Its `sequence` when that is an integer (Core's `is_int`).
    pub(super) sequence: Option<i64>,
    pub(super) payload: Value,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
enum ConditionStatus {
    Open,
    Resolved,
    Cancelled,
}

#[derive(Debug)]
pub(super) struct ConditionWait {
    pub(super) id: String,
    pub(super) sequence: Option<i64>,
    status: ConditionStatus,
    /// The history sequence of its fired timeout. Over the worker protocol
    /// Core records no `ConditionWaitTimedOut`: the wait stays open with
    /// `source_status: timeout_fired`, and no signal can satisfy it.
    pub(super) fired_at: Option<i64>,
    timer_id: Option<String>,
    timeout_seconds: Option<i64>,
    key: Option<String>,
    fingerprint: Option<String>,
    occurrence: Option<String>,
}

impl ConditionWait {
    pub(super) fn is_open(&self) -> bool {
        self.status == ConditionStatus::Open
    }

    /// `hasAdvanceableConditionWait` / `latestOpenConditionWait`.
    pub(super) fn advanceable(&self) -> bool {
        self.is_open() && self.fired_at.is_none()
    }

    pub(super) fn occurrence(&self) -> Option<&str> {
        self.occurrence.as_deref()
    }

    /// `recordSatisfiedConditionWaitForSignalResume`: the satisfaction
    /// event's payload, from the wait, its opening's parallel group and the
    /// resuming signal task.
    pub(super) fn satisfied(&self, opening: Option<&Opening>, task: &Value) -> Value {
        let mut payload = Map::new();
        let mut put = |key: &str, value: Option<Value>| {
            if let Some(value) = value.filter(|value| !value.is_null()) {
                payload.insert(key.to_owned(), value);
            }
        };
        put("condition_wait_id", Some(json!(self.id)));
        put("condition_wait_occurrence_id", self.occurrence.clone().map(Value::from));
        put("condition_key", self.key.clone().map(Value::from));
        put("condition_definition_fingerprint", self.fingerprint.clone().map(Value::from));
        put("sequence", self.sequence.map(Value::from));
        put("timer_id", self.timer_id.clone().map(Value::from));
        put("timeout_seconds", self.timeout_seconds.map(Value::from));
        for key in ["workflow_signal_id", "signal_name", "signal_wait_id"] {
            put(key, string(&task[key]).map(Value::from));
        }
        if let Some(opening) = opening {
            payload.extend(super::activity_metadata::payload_for_path(&opening.payload));
        }
        Value::Object(payload)
    }
}

#[derive(Debug, Default)]
pub(super) struct Waits {
    signals: Vec<SignalWait>,
    signal_index: HashMap<String, usize>,
    pub(super) conditions: Vec<ConditionWait>,
    condition_index: HashMap<String, usize>,
    /// Signal openings by signal name and explicit id.
    signal_openings: HashMap<(String, String), Opening>,
    /// Condition openings by explicit id.
    condition_openings: HashMap<String, Opening>,
    /// Ids a `SignalWaitCancelled` names (`ScopedWaitCancellation::cancelled`).
    scope_cancelled: HashSet<String>,
    /// The history sequence of each signal's `SignalReceived`.
    received: HashMap<String, i64>,
}

fn string(value: &Value) -> Option<String> {
    value.as_str().filter(|text| !text.is_empty()).map(str::to_owned)
}

/// `intValue`: an integer, or a numeric string.
/// A JSON float is not an integer (`is_int`); a numeric string is cast as
/// PHP's `(int)` casts it, so `"1.0"` and `"1e0"` read as 1.
fn int(value: &Value) -> Option<i64> {
    value.as_i64().or_else(|| value.as_str().and_then(super::php_value::int_cast))
}

/// Whether a cancelled selection's member range holds a sequence.
fn covers(selection: &Value, sequence: Option<i64>) -> bool {
    match (int(&selection["member_base_sequence"]), int(&selection["member_size"]), sequence) {
        (Some(base), Some(size), Some(sequence)) if size >= 1 => sequence >= base && sequence < base + size,
        _ => false,
    }
}

/// `ConditionWaits::waitIdForEvent`.
fn condition_id(payload: &Value) -> Option<String> {
    string(&payload["condition_wait_id"]).or_else(|| int(&payload["sequence"]).map(|sequence| format!("condition:{sequence}")))
}

fn opening(payload: &Value) -> Opening {
    Opening { sequence: payload["sequence"].as_i64(), payload: payload.clone() }
}

impl Waits {
    /// Folds a run's wait-related history, in recorded order.
    pub(super) fn fold(events: impl IntoIterator<Item = (i64, String, Value)>) -> Self {
        let mut waits = Self::default();
        // `openWaitIdsByName`: each name's still-open signal waits in
        // opening order.
        let mut open: HashMap<String, Vec<String>> = HashMap::new();
        for (at, event, payload) in events {
            waits.signal_event(&mut open, at, &event, &payload);
            waits.condition_event(at, &event, &payload);
        }
        waits
    }

    /// `SignalWaits::consumeOpenWaitId`: the named wait, else the oldest
    /// still-open wait of the name.
    fn consume(open: &mut HashMap<String, Vec<String>>, name: &str, explicit: Option<String>) -> Option<String> {
        let list = open.entry(name.to_owned()).or_default();
        match explicit {
            Some(id) => {
                list.retain(|open| *open != id);
                Some(id)
            }
            None => (!list.is_empty()).then(|| list.remove(0)),
        }
    }

    fn signal(&self, id: &str) -> Option<&SignalWait> {
        self.signal_index.get(id).map(|index| &self.signals[*index])
    }

    fn signal_mut(&mut self, id: &str) -> Option<&mut SignalWait> {
        self.signal_index.get(id).map(|index| &mut self.signals[*index])
    }

    fn signal_event(&mut self, open: &mut HashMap<String, Vec<String>>, at: i64, event: &str, payload: &Value) {
        let name = string(&payload["signal_name"]);
        match event {
            "SignalWaitOpened" => {
                let Some(name) = name else { return };
                let explicit = string(&payload["signal_wait_id"]);
                let sequence = int(&payload["sequence"]);
                let Some(id) = explicit.clone().or_else(|| sequence.map(|sequence| format!("signal:{sequence}:{name}"))) else { return };
                if explicit.is_some() {
                    self.signal_openings.entry((name.clone(), id.clone())).or_insert_with(|| opening(payload));
                }
                let wait = SignalWait { id: id.clone(), name: name.clone(), sequence, status: SignalStatus::Open };
                match self.signal_index.get(&id) {
                    Some(index) => self.signals[*index] = wait,
                    None => {
                        self.signal_index.insert(id.clone(), self.signals.len());
                        self.signals.push(wait);
                    }
                }
                open.entry(name).or_default().push(id);
            }
            "TimerFired" | "TimerCancelled" if payload["timer_kind"] == "signal_timeout" => {
                let Some(name) = name else { return };
                let Some(id) = Self::consume(open, &name, string(&payload["signal_wait_id"])) else { return };
                if let Some(wait) = self.signal_mut(&id).filter(|wait| wait.status == SignalStatus::Open) {
                    wait.status = if event == "TimerFired" { SignalStatus::TimedOut } else { SignalStatus::Cancelled };
                }
            }
            "SignalWaitCancelled" => {
                // Core resolves a wait cancellation only by its id.
                let Some(id) = string(&payload["signal_wait_id"]) else { return };
                self.scope_cancelled.insert(id.clone());
                if let Some(wait) = self.signal_mut(&id).filter(|wait| wait.status == SignalStatus::Open) {
                    wait.status = SignalStatus::Cancelled;
                    let name = wait.name.clone();
                    Self::consume(open, &name, Some(id));
                }
            }
            "SelectionOperationCancelled" => {
                for wait in &mut self.signals {
                    if wait.status == SignalStatus::Open && covers(payload, wait.sequence) {
                        wait.status = SignalStatus::Cancelled;
                        if let Some(list) = open.get_mut(&wait.name) {
                            list.retain(|open| *open != wait.id);
                        }
                    }
                }
            }
            "SignalReceived" | "SignalApplied" => {
                if event == "SignalReceived"
                    && let Some(signal) = string(&payload["signal_id"])
                {
                    self.received.entry(signal).or_insert(at);
                }
                let Some(name) = name else { return };
                let Some(id) = Self::consume(open, &name, string(&payload["signal_wait_id"])) else { return };
                let Some(wait) = self.signal_mut(&id) else { return };
                wait.status = match (wait.status, event) {
                    (SignalStatus::Open, "SignalReceived") => SignalStatus::Received,
                    (SignalStatus::Open | SignalStatus::Received, "SignalApplied") => SignalStatus::Applied,
                    (status, _) => status,
                };
            }
            _ => {}
        }
    }

    fn condition_event(&mut self, at: i64, event: &str, payload: &Value) {
        let timer = payload["timer_kind"] == "condition_timeout";
        match event {
            "ConditionWaitOpened" => {
                let Some(id) = condition_id(payload) else { return };
                if let Some(explicit) = string(&payload["condition_wait_id"]) {
                    self.condition_openings.entry(explicit).or_insert_with(|| opening(payload));
                }
                let wait = Self::condition(id.clone(), payload);
                match self.condition_index.get(&id) {
                    Some(index) => self.conditions[*index] = wait,
                    None => {
                        self.condition_index.insert(id, self.conditions.len());
                        self.conditions.push(wait);
                    }
                }
            }
            "TimerScheduled" | "TimerFired" if timer => {
                let Some(id) = condition_id(payload) else { return };
                let wait = self.condition_or_new(id, payload);
                if event == "TimerFired" && wait.status == ConditionStatus::Cancelled {
                    return;
                }
                wait.fill(payload);
                if event == "TimerFired" {
                    wait.fired_at = Some(at);
                }
            }
            "ConditionWaitCancelled" => {
                let Some(id) = condition_id(payload) else { return };
                if let Some(wait) = self.condition_index.get(&id).map(|index| &mut self.conditions[*index]).filter(|wait| wait.is_open()) {
                    wait.status = ConditionStatus::Cancelled;
                }
            }
            "ConditionWaitSatisfied" | "ConditionWaitTimedOut" => {
                let Some(id) = condition_id(payload) else { return };
                let wait = self.condition_or_new(id, payload);
                if wait.status != ConditionStatus::Cancelled {
                    wait.status = ConditionStatus::Resolved;
                    wait.fill(payload);
                }
            }
            "SelectionOperationCancelled" => {
                for wait in &mut self.conditions {
                    if wait.is_open() && covers(payload, wait.sequence) {
                        wait.status = ConditionStatus::Cancelled;
                    }
                }
            }
            _ => {}
        }
    }

    /// `ConditionWaits::openWait` / `wait`.
    fn condition(id: String, payload: &Value) -> ConditionWait {
        ConditionWait {
            id,
            sequence: int(&payload["sequence"]),
            status: ConditionStatus::Open,
            fired_at: None,
            timer_id: string(&payload["timer_id"]),
            timeout_seconds: int(&payload["timeout_seconds"]).or_else(|| int(&payload["delay_seconds"])),
            key: string(&payload["condition_key"]),
            fingerprint: string(&payload["condition_definition_fingerprint"]),
            occurrence: string(&payload["condition_wait_occurrence_id"]),
        }
    }

    fn condition_or_new(&mut self, id: String, payload: &Value) -> &mut ConditionWait {
        let index = match self.condition_index.get(&id) {
            Some(index) => *index,
            None => {
                self.condition_index.insert(id.clone(), self.conditions.len());
                self.conditions.push(Self::condition(id, payload));
                self.conditions.len() - 1
            }
        };
        &mut self.conditions[index]
    }

    /// `advanceableSignalWaitsById`: whether a signal wait of this name
    /// under this id can take a signal; one a received signal holds counts
    /// only when `include_received`.
    pub(super) fn signal_advanceable(&self, name: &str, id: &str, include_received: bool) -> bool {
        self.signal(id).is_some_and(|wait| {
            wait.name == name
                && (wait.status == SignalStatus::Open || include_received && wait.status == SignalStatus::Received)
        })
    }

    /// `SignalWaits::openWaitIdForName`: the latest wait of the name still
    /// open (not held by a received signal).
    pub(super) fn latest_open_signal(&self, name: &str) -> Option<&SignalWait> {
        self.signals.iter().enumerate()
            .filter(|(_, wait)| wait.status == SignalStatus::Open && wait.name == name)
            .max_by_key(|(index, wait)| (wait.sequence.unwrap_or(i64::MIN), *index))
            .map(|(_, wait)| wait)
    }

    /// The `SignalWaitOpened` that named this id for this signal name
    /// (`recordAppliedSignalForSignalResume`'s `$opened`), whatever has
    /// happened to that wait since.
    pub(super) fn signal_opening(&self, name: &str, id: &str) -> Option<&Opening> {
        self.signal_openings.get(&(name.to_owned(), id.to_owned()))
    }

    /// The first `ConditionWaitOpened` naming this id.
    pub(super) fn condition_opening(&self, id: &str) -> Option<&Opening> {
        self.condition_openings.get(id)
    }

    /// `pendingSignalWaitIdForOpenSignalWait`: whether a wait of this name
    /// was already opened under this id.
    pub(super) fn taken(&self, name: &str, id: &str) -> bool {
        self.signal_opening(name, id).is_some()
    }

    pub(super) fn scope_cancelled(&self, id: &str) -> bool {
        self.scope_cancelled.contains(id)
    }

    /// Whether a signal wait the run has had carries this id.
    pub(super) fn knows(&self, id: &str) -> bool {
        self.signal_index.contains_key(id)
    }

    pub(super) fn received_at(&self, signal: &str) -> Option<i64> {
        self.received.get(signal).copied()
    }

    /// `hasAdvanceableConditionWait`.
    pub(super) fn advanceable_condition(&self) -> bool {
        self.conditions.iter().any(ConditionWait::advanceable)
    }

    /// `latestOpenConditionWait`: the open condition wait with the highest
    /// sequence whose timeout has not fired.
    pub(super) fn latest_open_condition(&self) -> Option<&ConditionWait> {
        self.conditions.iter().enumerate()
            .filter(|(_, wait)| wait.advanceable())
            .max_by_key(|(index, wait)| (wait.sequence.unwrap_or(0), *index))
            .map(|(_, wait)| wait)
    }

    /// Whether the run holds any open signal or condition wait.
    pub(super) fn any_current(&self) -> bool {
        self.signals.iter().any(|wait| matches!(wait.status, SignalStatus::Open | SignalStatus::Received))
            || self.conditions.iter().any(ConditionWait::is_open)
    }
}

impl ConditionWait {
    /// The timeout timer's and later events' fields, where the wait has none.
    fn fill(&mut self, payload: &Value) {
        self.sequence = self.sequence.or_else(|| int(&payload["sequence"]));
        self.key = self.key.take().or_else(|| string(&payload["condition_key"]));
        self.fingerprint = self.fingerprint.take().or_else(|| string(&payload["condition_definition_fingerprint"]));
        self.occurrence = self.occurrence.take().or_else(|| string(&payload["condition_wait_occurrence_id"]));
        self.timeout_seconds = self.timeout_seconds.or_else(|| int(&payload["delay_seconds"]));
        if let Some(timer) = string(&payload["timer_id"]) {
            self.timer_id = Some(timer);
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
    /// The run's waits, derived from its history.
    pub(super) async fn waits(tx: &mut Transaction<'_, DB>, run_id: &str) -> Result<Waits> {
        let rows = Self::query(WAIT_EVENTS_SQL).bind(run_id).fetch_all(&mut **tx).await?;
        let mut events = Vec::with_capacity(rows.len());
        for row in rows {
            events.push((
                DB::number(&row, "sequence")?,
                DB::string(&row, "event_type")?,
                DB::optional_document_row(&row, "payload")?.unwrap_or(Value::Null),
            ));
        }
        Ok(Waits::fold(events))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn fold(events: &[(&str, Value)]) -> Waits {
        Waits::fold(events.iter().enumerate().map(|(index, (event, payload))| (index as i64 + 1, (*event).to_owned(), payload.clone())))
    }

    fn status(waits: &Waits, id: &str) -> SignalStatus {
        waits.signals.iter().find(|wait| wait.id == id).unwrap().status
    }

    #[test]
    fn waits_recorded_without_ids_take_core_s_derived_ids() {
        let waits = fold(&[
            ("SignalWaitOpened", json!({"signal_name":"go","sequence":1})),
            ("ConditionWaitOpened", json!({"sequence":2})),
        ]);
        assert_eq!(waits.signals[0].id, "signal:1:go");
        assert_eq!(waits.conditions[0].id, "condition:2");
        assert!(!waits.taken("go", "signal:1:go"), "only an explicit id is taken");
    }

    // `consumeOpenWaitId`: an id-less receipt holds the oldest open wait of
    // its name; an id-less application then takes the next open one, as
    // Core's fold does, and the held wait stays held.
    #[test]
    fn id_less_receipt_and_application_consume_open_waits_in_order() {
        let waits = fold(&[
            ("SignalWaitOpened", json!({"signal_name":"go","signal_wait_id":"a","sequence":1})),
            ("SignalWaitOpened", json!({"signal_name":"go","signal_wait_id":"b","sequence":2})),
            ("SignalReceived", json!({"signal_name":"go","signal_id":"s1"})),
            ("SignalApplied", json!({"signal_name":"go","signal_id":"s1"})),
        ]);
        assert_eq!((status(&waits, "a"), status(&waits, "b")), (SignalStatus::Received, SignalStatus::Applied));
        // With no other open wait, the id-less application consumes none.
        let waits = fold(&[
            ("SignalWaitOpened", json!({"signal_name":"go","signal_wait_id":"a","sequence":1})),
            ("SignalReceived", json!({"signal_name":"go","signal_id":"s1"})),
            ("SignalApplied", json!({"signal_name":"go","signal_id":"s1"})),
        ]);
        assert_eq!(status(&waits, "a"), SignalStatus::Received);
        // An application naming the held wait applies it.
        let waits = fold(&[
            ("SignalWaitOpened", json!({"signal_name":"go","signal_wait_id":"a","sequence":1})),
            ("SignalReceived", json!({"signal_name":"go","signal_wait_id":"a"})),
            ("SignalApplied", json!({"signal_name":"go","signal_wait_id":"a"})),
        ]);
        assert_eq!(status(&waits, "a"), SignalStatus::Applied);
    }

    #[test]
    fn cancellations_close_only_waits_still_open() {
        let waits = fold(&[
            ("SignalWaitOpened", json!({"signal_name":"a","signal_wait_id":"a","sequence":1})),
            ("SignalWaitOpened", json!({"signal_name":"b","signal_wait_id":"b","sequence":2})),
            ("SignalReceived", json!({"signal_name":"a","signal_wait_id":"a"})),
            ("SelectionOperationCancelled", json!({"member_base_sequence":1,"member_size":3})),
            ("SignalWaitOpened", json!({"signal_name":"c","signal_wait_id":"c","sequence":3})),
            ("SignalWaitCancelled", json!({"signal_name":"c"})),
            ("ConditionWaitOpened", json!({"condition_wait_id":"k","sequence":4})),
            ("ConditionWaitCancelled", json!({"sequence":4})),
        ]);
        assert_eq!(status(&waits, "a"), SignalStatus::Received, "a received wait is not cancelled");
        assert_eq!(status(&waits, "b"), SignalStatus::Cancelled);
        assert_eq!(status(&waits, "c"), SignalStatus::Open, "opened after the selection; an id-less wait cancellation names none");
        assert!(waits.conditions[0].is_open(), "`condition:4` names no wait opened as `k`");
        let waits = fold(&[
            ("ConditionWaitOpened", json!({"sequence":4})),
            ("ConditionWaitCancelled", json!({"sequence":4})),
            ("ConditionWaitOpened", json!({"sequence":5})),
            ("ConditionWaitTimedOut", json!({"sequence":5})),
        ]);
        assert!(waits.conditions.iter().all(|wait| !wait.is_open()), "id-less closings resolve `condition:{{sequence}}`");
    }

    #[test]
    fn a_wait_that_took_a_buffered_identity_is_open_as_in_core() {
        let waits = fold(&[
            ("SignalReceived", json!({"signal_name":"dup","signal_wait_id":"signal-command:x","signal_id":"s"})),
            ("SignalWaitOpened", json!({"signal_name":"dup","signal_wait_id":"signal-command:x","sequence":1})),
        ]);
        assert_eq!(status(&waits, "signal-command:x"), SignalStatus::Open);
        assert!(waits.taken("dup", "signal-command:x"));
        assert_eq!(waits.received_at("s"), Some(1));
    }

    #[test]
    fn a_fired_condition_timeout_leaves_its_wait_open_but_unable_to_advance() {
        let waits = fold(&[
            ("ConditionWaitOpened", json!({"condition_wait_id":"early","sequence":1,"timeout_seconds":600})),
            ("TimerScheduled", json!({"timer_kind":"condition_timeout","condition_wait_id":"early","timer_id":"t1","sequence":1,"delay_seconds":600})),
            ("ConditionWaitOpened", json!({"condition_wait_id":"late","sequence":2,"timeout_seconds":1})),
            ("TimerScheduled", json!({"timer_kind":"condition_timeout","condition_wait_id":"late","timer_id":"t2","sequence":2,"delay_seconds":1})),
            ("TimerFired", json!({"timer_kind":"condition_timeout","condition_wait_id":"late","timer_id":"t2","sequence":2})),
        ]);
        assert!(waits.conditions[1].is_open() && !waits.conditions[1].advanceable());
        assert_eq!(waits.conditions[1].fired_at, Some(5));
        let latest = waits.latest_open_condition().unwrap();
        assert_eq!(latest.id, "early");
        let task = json!({"workflow_signal_id":"s","signal_name":"x","signal_wait_id":"signal-command:c","open_wait_id":"o"});
        assert_eq!(latest.satisfied(waits.condition_opening(&latest.id), &task), json!({"condition_wait_id":"early","sequence":1,"timer_id":"t1","timeout_seconds":600,
            "workflow_signal_id":"s","signal_name":"x","signal_wait_id":"signal-command:c"}));
    }

    #[test]
    fn satisfaction_carries_the_opening_s_parallel_group_and_no_opening_only_fields() {
        let member = json!({"parallel_group_id":"g","parallel_group_kind":"selection","parallel_group_mode":"select",
            "parallel_group_base_sequence":1,"parallel_group_size":2,"parallel_group_index":0,"selection_member_key":"k"});
        let mut opened = member.clone();
        opened["condition_wait_id"] = json!("w");
        opened["sequence"] = json!(1);
        opened["task"] = json!({"id":"opening-task"});
        opened["parallel_group_path"] = json!([member]);
        let waits = fold(&[("ConditionWaitOpened", opened)]);
        let satisfied = waits.conditions[0].satisfied(waits.condition_opening("w"), &json!({}));
        let mut expected = member.clone();
        expected["condition_wait_id"] = json!("w");
        expected["sequence"] = json!(1);
        expected["parallel_group_path"] = json!([member]);
        assert_eq!(satisfied, expected);
    }

    // Core's `singleMetadataFromPayload`: an all-mode group drops its mode,
    // a kind missing from the payload comes from the group id's prefix, and
    // an entry with an invalid size or selection key is no group at all.
    #[test]
    fn satisfaction_metadata_follows_core_s_normalization() {
        let all = json!({"parallel_group_id":"parallel-timers:1:2","parallel_group_mode":"all",
            "parallel_group_base_sequence":1,"parallel_group_size":2,"parallel_group_index":0});
        let mut opened = all.clone();
        opened["condition_wait_id"] = json!("w");
        opened["sequence"] = json!(1);
        let waits = fold(&[("ConditionWaitOpened", opened)]);
        let satisfied = waits.conditions[0].satisfied(waits.condition_opening("w"), &json!({}));
        let entry = json!({"parallel_group_id":"parallel-timers:1:2","parallel_group_kind":"timer",
            "parallel_group_base_sequence":1,"parallel_group_size":2,"parallel_group_index":0});
        let mut expected = entry.clone();
        expected["condition_wait_id"] = json!("w");
        expected["sequence"] = json!(1);
        expected["parallel_group_path"] = json!([entry]);
        assert_eq!(satisfied, expected);
        for invalid in [json!({"parallel_group_size":0}), json!({"parallel_group_mode":"select","selection_member_key":""})] {
            let mut opened = all.clone();
            opened.as_object_mut().unwrap().extend(invalid.as_object().unwrap().clone());
            opened["condition_wait_id"] = json!("w");
            let waits = fold(&[("ConditionWaitOpened", opened)]);
            let satisfied = waits.conditions[0].satisfied(waits.condition_opening("w"), &json!({}));
            assert!(satisfied.get("parallel_group_id").is_none(), "{satisfied}");
        }
    }

    // `SignalWaits::intValue` and `ConditionWaits::intValue`: an integer, or
    // any `is_numeric` string cast to one; a float or other text is none.
    #[test]
    fn int_values_follow_php_numeric_strings() {
        for (value, expected) in [
            (json!(3), Some(3)), (json!("3"), Some(3)), (json!(" 3 "), Some(3)), (json!("+3"), Some(3)),
            (json!("1.0"), Some(1)), (json!("1e0"), Some(1)), (json!("2.9"), Some(2)), (json!("-1.5"), Some(-1)),
            (json!(1.0), None), (json!("1x"), None), (json!("0x1A"), None), (json!(""), None), (json!(null), None),
            (json!(true), None), (json!("1e30"), None),
        ] {
            assert_eq!(int(&value), expected, "{value}");
        }
    }

    // The opening Core reads is the first one naming the wait's id, and its
    // sequence counts only as an integer (`is_int`). A timer creates a
    // condition wait but is no opening.
    #[test]
    fn openings_are_the_first_explicit_ones_with_integer_sequences() {
        let waits = fold(&[
            ("SignalWaitOpened", json!({"signal_name":"go","signal_wait_id":"a","sequence":"1"})),
            ("SignalWaitOpened", json!({"signal_name":"go","signal_wait_id":"a","sequence":2})),
            ("TimerScheduled", json!({"timer_kind":"condition_timeout","condition_wait_id":"k","sequence":3,"parallel_group_id":"parallel-timers:3:1",
                "parallel_group_base_sequence":3,"parallel_group_size":1,"parallel_group_index":0})),
            ("ConditionWaitOpened", json!({"condition_wait_id":"c","sequence":4,"condition_key":"first"})),
            ("ConditionWaitOpened", json!({"condition_wait_id":"c","sequence":5,"condition_key":"second"})),
        ]);
        let opening = waits.signal_opening("go", "a").unwrap();
        assert_eq!(opening.sequence, None, "a numeric string is not an integer sequence");
        assert_eq!(waits.signal("a").unwrap().sequence, Some(2), "the fold keeps the latest opening's state");
        assert!(waits.condition_opening("k").is_none());
        assert_eq!(waits.condition_opening("c").unwrap().payload["condition_key"], "first");
    }
}
