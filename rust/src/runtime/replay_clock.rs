//! The `replay_clock` entry carrier on workflow tasks (Server #1132,
//! durable-workflow/.github#215): the instant a run's replay starts from, so
//! an SDK's replay-safe clock has a value at ordinary workflow entry.
//!
//! An ordinary, continued or child run uses its own canonical start. A
//! redrive successor uses the replay anchor its `WorkflowStarted` recorded,
//! only when the redrive command and link that created it agree. Anything
//! missing, ambiguous or malformed omits the object; a redrive successor's
//! fresh start never stands in for its anchor. The lookups are `Store`
//! methods in store.rs, beside the workflow-task claim that uses them.
use chrono::{DateTime, SecondsFormat, Utc};
use serde_json::{Value, json};

pub(super) const SCHEMA: &str = "durable-workflow.worker-replay-clock/v1";
pub(super) const RUN_STARTED: &str = "run_started_at";
pub(super) const REDRIVE_ANCHOR: &str = "redrive_replayed_started_at";
/// A run's start events are among its first few; the lookup reads only
/// these, through the run and sequence key.
pub(super) const OPENING_EVENTS: i64 = 8;

/// Strict UTC RFC 3339 with six fractional digits and `Z`. A whole-second
/// instant stays whole seconds, padded with zeros.
fn timestamp(instant: DateTime<Utc>) -> String {
    instant.to_rfc3339_opts(SecondsFormat::Micros, true)
}

pub(super) fn carrier(run_id: &str, started_at: DateTime<Utc>, source: &str) -> Value {
    json!({"schema":SCHEMA,"workflow_run_id":run_id,"started_at":timestamp(started_at),"source":source})
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn instants_keep_their_represented_precision_in_utc() {
        let whole = DateTime::parse_from_rfc3339("2026-11-12T10:00:00+00:00").unwrap().with_timezone(&Utc);
        assert_eq!(timestamp(whole), "2026-11-12T10:00:00.000000Z");
        let micros = DateTime::parse_from_rfc3339("2026-11-12T11:00:00.123456+01:00").unwrap().with_timezone(&Utc);
        assert_eq!(timestamp(micros), "2026-11-12T10:00:00.123456Z");
        assert_eq!(carrier("run", whole, RUN_STARTED),
            json!({"schema":SCHEMA,"workflow_run_id":"run","started_at":"2026-11-12T10:00:00.000000Z","source":"run_started_at"}));
    }
}
