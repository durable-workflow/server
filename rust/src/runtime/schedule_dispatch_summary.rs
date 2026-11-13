//! Bounded durable dispatch facts. Legacy action arrays cannot establish the
//! latest outcome or current buffer depth without their owning transition.
use chrono::{DateTime, Utc};
use serde::{Deserialize, Serialize};
use serde_json::Value;

pub(super) const MAX_BYTES: usize = 4096;
const MAX_COUNT: u64 = 9_007_199_254_740_991;

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub(super) struct Summary {
    version: u64,
    state: State,
    last_outcome: RequiredNullable<Outcome>,
    buffered_actions_count: RequiredNullable<u64>,
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(rename_all = "snake_case")]
enum State {
    Known,
    Never,
    Unavailable,
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub(super) struct Outcome {
    id: String,
    outcome: Kind,
    recorded_at: String,
    reason: RequiredNullable<String>,
    workflow_instance_id: RequiredNullable<String>,
    workflow_run_id: RequiredNullable<String>,
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(rename_all = "snake_case")]
pub(super) enum Kind {
    Started,
    Buffered,
    Skipped,
    Failed,
}

// Unlike Option, this wire value is required even when it is null. A
// missing field must not gain authority over legacy evidence. The untagged
// decoder also preserves duplicate-field refusal in the nested outcome.
#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(untagged)]
enum RequiredNullable<T> {
    Value(T),
    Null,
}

impl<T> From<Option<T>> for RequiredNullable<T> {
    fn from(value: Option<T>) -> Self {
        match value {
            Some(value) => Self::Value(value),
            None => Self::Null,
        }
    }
}

impl<T> RequiredNullable<T> {
    fn as_ref(&self) -> Option<&T> {
        match self {
            Self::Value(value) => Some(value),
            Self::Null => None,
        }
    }

    fn is_none(&self) -> bool {
        self.as_ref().is_none()
    }
}

fn ulid(value: &str) -> bool {
    value.len() == 26
        && matches!(value.as_bytes()[0], b'0'..=b'7')
        && value
            .bytes()
            .all(|byte| b"0123456789ABCDEFGHJKMNPQRSTVWXYZ".contains(&byte))
}

fn instant(value: &str) -> bool {
    value.len() == 27
        && value.is_ascii()
        && &value[17..19] != "60"
        && DateTime::parse_from_rfc3339(value).is_ok_and(|time| {
            time.with_timezone(&Utc)
                .format("%Y-%m-%dT%H:%M:%S%.6fZ")
                .to_string()
                == value
        })
}

impl Outcome {
    pub(super) fn new(
        kind: Kind,
        recorded: DateTime<Utc>,
        reason: Option<&str>,
        workflow: Option<&str>,
        run: Option<&str>,
    ) -> Self {
        Self {
            id: super::store::id(),
            outcome: kind,
            recorded_at: recorded.format("%Y-%m-%dT%H:%M:%S%.6fZ").to_string(),
            reason: reason.map(str::to_owned).into(),
            workflow_instance_id: workflow.map(str::to_owned).into(),
            workflow_run_id: run.map(str::to_owned).into(),
        }
    }

    fn valid(&self) -> bool {
        if !ulid(&self.id) || !instant(&self.recorded_at) {
            return false;
        }
        match self.outcome {
            Kind::Started => {
                self.reason.is_none()
                    && self
                        .workflow_instance_id
                        .as_ref()
                        .map(String::as_str)
                        .is_some_and(ulid)
                    && self
                        .workflow_run_id
                        .as_ref()
                        .map(String::as_str)
                        .is_some_and(ulid)
            }
            Kind::Buffered => {
                self.reason.is_none()
                    && self.workflow_instance_id.is_none()
                    && self.workflow_run_id.is_none()
            }
            Kind::Skipped | Kind::Failed => {
                self.reason
                    .as_ref()
                    .is_some_and(|reason| reason.len() <= 256 && reason.chars().count() <= 64)
                    && self.workflow_instance_id.is_none()
                    && self.workflow_run_id.is_none()
            }
        }
    }
}

impl Summary {
    pub(super) fn unavailable() -> Self {
        Self {
            version: 1,
            state: State::Unavailable,
            last_outcome: RequiredNullable::Null,
            buffered_actions_count: RequiredNullable::Null,
        }
    }

    pub(super) fn never() -> Self {
        Self {
            version: 1,
            state: State::Never,
            last_outcome: RequiredNullable::Null,
            buffered_actions_count: RequiredNullable::Value(0),
        }
    }

    fn valid(&self) -> bool {
        self.version == 1
            && match self.state {
                State::Known => {
                    self.last_outcome.as_ref().is_some_and(Outcome::valid)
                        && self
                            .buffered_actions_count
                            .as_ref()
                            .copied()
                            .is_some_and(|count| count <= MAX_COUNT)
                }
                State::Never => {
                    self.last_outcome.is_none() && self.buffered_actions_count.as_ref() == Some(&0)
                }
                State::Unavailable => {
                    self.last_outcome.is_none() && self.buffered_actions_count.is_none()
                }
            }
    }

    pub(super) fn read(raw: Option<&str>) -> Self {
        raw.filter(|raw| raw.len() <= MAX_BYTES)
            .and_then(|raw| serde_json::from_str::<Self>(raw).ok())
            .filter(Self::valid)
            .unwrap_or_else(Self::unavailable)
    }

    pub(super) fn recorded(outcome: Outcome, count: Option<u64>) -> Self {
        let summary = Self {
            version: 1,
            state: State::Known,
            last_outcome: RequiredNullable::Value(outcome),
            buffered_actions_count: count.into(),
        };
        if summary.valid() && summary.encoded().len() <= MAX_BYTES {
            summary
        } else {
            Self::unavailable()
        }
    }

    pub(super) fn count(&self) -> Option<u64> {
        self.buffered_actions_count.as_ref().copied()
    }

    /// A buffer mutation cannot create a dispatch or repair an unknown past.
    pub(super) fn with_count(mut self, count: u64) -> Self {
        if matches!(self.state, State::Unavailable) {
            return self;
        }
        self.buffered_actions_count = RequiredNullable::Value(count);
        if self.valid() {
            self
        } else {
            Self::unavailable()
        }
    }

    pub(super) fn encoded(&self) -> String {
        serde_json::to_string(self).expect("bounded scalar summary serialization")
    }

    pub(super) fn wire(raw: Option<String>) -> Value {
        serde_json::to_value(Self::read(raw.as_deref()))
            .expect("bounded scalar summary serialization")
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn outcome(kind: Kind, reason: Option<&str>) -> Outcome {
        let (workflow, run) = if matches!(kind, Kind::Started) {
            (
                Some("01ARZ3NDEKTSV4RRFFQ69G5FAW"),
                Some("01ARZ3NDEKTSV4RRFFQ69G5FAX"),
            )
        } else {
            (None, None)
        };
        Outcome::new(
            kind,
            "2026-11-06T08:00:00.123000Z".parse().unwrap(),
            reason,
            workflow,
            run,
        )
    }

    #[test]
    fn complete_shapes_and_literal_reasons_round_trip() {
        for kind in [Kind::Started, Kind::Buffered] {
            let summary = Summary::recorded(outcome(kind, None), Some(0));
            assert!(matches!(
                Summary::read(Some(&summary.encoded())).state,
                State::Known
            ));
        }
        for kind in [Kind::Skipped, Kind::Failed] {
            for reason in ["", "  ", "\0", " denied ", &"😀".repeat(64)] {
                let summary =
                    Summary::recorded(outcome(kind.clone(), Some(reason)), Some(MAX_COUNT));
                assert_eq!(
                    Summary::read(Some(&summary.encoded()))
                        .last_outcome
                        .as_ref()
                        .unwrap()
                        .reason
                        .as_ref()
                        .map(String::as_str),
                    Some(reason)
                );
                assert!(summary.encoded().len() <= MAX_BYTES);
            }
        }
    }

    #[test]
    fn invalid_new_outcomes_replace_old_authority_without_truncation() {
        for reason in ["x".repeat(65), "😀".repeat(65)] {
            assert!(matches!(
                Summary::recorded(outcome(Kind::Failed, Some(&reason)), Some(0)).state,
                State::Unavailable
            ));
        }
        assert!(matches!(
            Summary::recorded(outcome(Kind::Started, None), None).state,
            State::Unavailable
        ));
    }

    #[test]
    fn malformed_partial_or_unsupported_storage_is_unavailable() {
        let valid =
            serde_json::to_value(Summary::recorded(outcome(Kind::Started, None), Some(0))).unwrap();
        for raw in [None, Some(""), Some("null"), Some("{}"), Some("not JSON")] {
            assert!(matches!(Summary::read(raw).state, State::Unavailable));
        }
        for key in ["version", "state", "last_outcome", "buffered_actions_count"] {
            let mut changed = valid.clone();
            changed.as_object_mut().unwrap().remove(key);
            assert!(matches!(
                Summary::read(Some(&changed.to_string())).state,
                State::Unavailable
            ));
        }
        for (key, value) in [
            ("version", json!(2)),
            ("version", json!(1.0)),
            ("version", json!(true)),
            ("buffered_actions_count", json!("0")),
            ("buffered_actions_count", json!(-1)),
            ("buffered_actions_count", json!(0.0)),
            ("buffered_actions_count", json!(MAX_COUNT + 1)),
            ("extra", json!(null)),
        ] {
            let mut changed = valid.clone();
            changed[key] = value;
            assert!(matches!(
                Summary::read(Some(&changed.to_string())).state,
                State::Unavailable
            ));
        }
        for (key, value) in [
            ("id", json!("81ARZ3NDEKTSV4RRFFQ69G5FAV")),
            ("recorded_at", json!("2026-02-30T08:00:00.123000Z")),
            ("recorded_at", json!("2026-11-06T08:00:00.123Z")),
            ("reason", json!("")),
            ("workflow_run_id", json!(null)),
        ] {
            let mut changed = valid.clone();
            changed["last_outcome"][key] = value;
            assert!(matches!(
                Summary::read(Some(&changed.to_string())).state,
                State::Unavailable
            ));
        }
        assert!(matches!(Summary::read(Some("{\"version\":1,\"version\":1,\"state\":\"never\",\"last_outcome\":null,\"buffered_actions_count\":0}")).state, State::Unavailable));
    }

    #[test]
    fn count_only_mutations_preserve_outcome_identity_and_time() {
        let summary = Summary::recorded(outcome(Kind::Buffered, None), Some(1));
        let before = serde_json::to_value(&summary.last_outcome).unwrap();
        let after = summary.with_count(0);
        assert_eq!(serde_json::to_value(&after.last_outcome).unwrap(), before);
        assert_eq!(after.count(), Some(0));
        assert!(matches!(after.state, State::Known));
        assert!(matches!(
            Summary::unavailable().with_count(0).state,
            State::Unavailable
        ));
        assert!(matches!(Summary::never().with_count(0).state, State::Never));
    }
}
