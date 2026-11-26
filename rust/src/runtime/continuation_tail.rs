//! The history tail a workflow task completion returns when it asks with
//! `continuation_tail` (Server #1181, `docs/contracts/activity-workflow-continuation-v2.md`).
//!
//! The tail is every event after the worker's sticky cache boundary, read in
//! the completion's write transaction after its last write. It seeds the
//! cache for a v2 continuation of the activity the same answer leases. Over a
//! budget the tail is omitted; the completion still commits.
use super::{Result, refuse};
use axum::http::StatusCode;
use serde_json::{Value, json};

pub(super) const VERSION: i64 = 2;
pub(super) const TAIL_MAX_EVENTS: usize = 64;
/// The UTF-8 length of the JSON-encoded `history_events` array.
pub(super) const TAIL_MAX_BYTES: usize = 262_144;
pub(super) const UNAVAILABLE: &str = "tail_unavailable";
/// Why a tail the Server read was omitted.
pub(super) const BUDGET_REASONS: [&str; 2] = ["tail_event_budget_exceeded", "tail_byte_budget_exceeded"];

/// A requested tail: the events after the completion's cached boundary.
#[derive(Clone, Copy)]
pub(super) struct Request {
    pub(super) after_sequence: i64,
}

/// The optional `continuation_tail` member of a workflow task completion
/// `body`. It is valid only as exactly `{"version": 2}`, beside a
/// `sticky_cache` claim that reports its `history_last_sequence`. Anything
/// else is refused before any write.
pub(super) fn parse(member: Option<&Value>, body: &Value) -> Result<Option<Request>> {
    let Some(member) = member else { return Ok(None) };
    let invalid = || refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_continuation_tail");
    let fields = member.as_object().ok_or_else(invalid)?;
    let version = fields.get("version").ok_or_else(invalid)?;
    if fields.len() != 1 || version.is_f64() || version.as_i64() != Some(VERSION) {
        return Err(invalid());
    }
    let after_sequence = body["sticky_cache"]["history_last_sequence"].as_i64().ok_or_else(invalid)?;
    Ok(Some(Request { after_sequence }))
}

pub(super) enum Tail {
    Events { after_sequence: i64, last_sequence: i64, events: Vec<Value> },
    Omitted { after_sequence: i64, reason: &'static str },
}

impl Tail {
    /// The boundary the worker's cache holds once it applies this answer.
    pub(super) fn cached_through(&self) -> i64 {
        match self {
            Self::Events { last_sequence, .. } => *last_sequence,
            Self::Omitted { after_sequence, .. } => *after_sequence,
        }
    }

    pub(super) fn answer(self) -> Value {
        match self {
            Self::Events { after_sequence, last_sequence, events } => json!({"version": VERSION,
                "after_sequence": after_sequence, "last_sequence": last_sequence, "history_events": events}),
            Self::Omitted { after_sequence, reason } => json!({"version": VERSION,
                "after_sequence": after_sequence, "omitted": reason}),
        }
    }
}

store_methods! {
    /// The tail after `after_sequence`, read in `tx` after the completion's
    /// last write. It covers exactly the sequences up to the run's last one;
    /// anything else breaks the history invariant and rolls everything back.
    pub(super) async fn continuation_tail(
        tx: &mut super::transaction::Transaction<'_, DB>, run_id: &str, after_sequence: i64,
    ) -> Result<Tail> {
        let run = Self::query("SELECT last_history_sequence FROM workflow_runs WHERE id=$1")
            .bind(run_id).fetch_one(&mut **tx).await?;
        let last_sequence = DB::number(&run, "last_history_sequence")?;
        let limit = i64::try_from(TAIL_MAX_EVENTS + 1).expect("bounded tail");
        let rows = Self::query("SELECT * FROM workflow_history_events WHERE workflow_run_id=$1 AND sequence>$2 ORDER BY sequence LIMIT $3")
            .bind(run_id).bind(after_sequence).bind(limit).fetch_all(&mut **tx).await?;
        let violated = || refuse(StatusCode::INTERNAL_SERVER_ERROR, "history_invariant_violated");
        let mut expected = after_sequence;
        for row in &rows {
            expected += 1;
            if DB::number(row, "sequence")? != expected {
                return Err(violated());
            }
        }
        if rows.len() > TAIL_MAX_EVENTS {
            return Ok(Tail::Omitted { after_sequence, reason: BUDGET_REASONS[0] });
        }
        if expected != last_sequence {
            return Err(violated());
        }
        let mut events = rows.into_iter().map(Self::event).collect::<Result<Vec<_>>>()?;
        Self::with_signal_sequences(&mut **tx, run_id, &mut events).await?;
        if serde_json::to_vec(&events)?.len() > TAIL_MAX_BYTES {
            return Ok(Tail::Omitted { after_sequence, reason: BUDGET_REASONS[1] });
        }
        Ok(Tail::Events { after_sequence, last_sequence, events })
    }

    /// A tail answered before, read again by its range for an exact retry.
    /// Committed events never change; a range retention pruned is unavailable.
    pub(super) async fn continuation_tail_range(
        tx: &mut super::transaction::Transaction<'_, DB>, run_id: &str, after_sequence: i64, last_sequence: i64,
    ) -> Result<Tail> {
        let rows = Self::query("SELECT * FROM workflow_history_events WHERE workflow_run_id=$1 AND sequence>$2 AND sequence<=$3 ORDER BY sequence")
            .bind(run_id).bind(after_sequence).bind(last_sequence).fetch_all(&mut **tx).await?;
        let mut expected = after_sequence;
        for row in &rows {
            expected += 1;
            if DB::number(row, "sequence")? != expected {
                return Ok(Tail::Omitted { after_sequence, reason: UNAVAILABLE });
            }
        }
        if expected != last_sequence {
            return Ok(Tail::Omitted { after_sequence, reason: UNAVAILABLE });
        }
        let mut events = rows.into_iter().map(Self::event).collect::<Result<Vec<_>>>()?;
        Self::with_signal_sequences(&mut **tx, run_id, &mut events).await?;
        Ok(Tail::Events { after_sequence, last_sequence, events })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn only_version_two_beside_a_cached_boundary_is_a_request() {
        let body = json!({"sticky_cache":{"worker_id":"w","history_last_sequence":4}});
        assert_eq!(parse(None, &body).unwrap().map(|request| request.after_sequence), None);
        assert_eq!(parse(Some(&json!({"version":2})), &body).unwrap().map(|request| request.after_sequence), Some(4));
        for member in [json!(null), json!(2), json!([2]), json!("2"), json!({}), json!({"version":1}), json!({"version":"2"}),
            json!({"version":2.0}), json!({"version":null}), json!({"version":2,"max_events":10})]
        {
            let error = parse(Some(&member), &body).err().unwrap_or_else(|| panic!("{member} must be refused"));
            assert!(matches!(error, crate::runtime::RuntimeError::Refused { status: StatusCode::UNPROCESSABLE_ENTITY,
                reason: "invalid_continuation_tail" }), "{member}");
        }
        for body in [json!({}), json!({"sticky_cache":null}), json!({"sticky_cache":{"worker_id":"w"}}),
            json!({"sticky_cache":{"worker_id":"w","history_last_sequence":null}}),
            json!({"sticky_cache":{"worker_id":"w","history_last_sequence":"4"}})]
        {
            assert!(parse(Some(&json!({"version":2})), &body).is_err(), "{body}");
        }
    }

    #[test]
    fn the_cache_keeps_its_old_boundary_unless_events_arrive() {
        let omitted = Tail::Omitted { after_sequence: 6, reason: UNAVAILABLE };
        assert_eq!(omitted.cached_through(), 6);
        assert_eq!(omitted.answer(), json!({"version":2,"after_sequence":6,"omitted":"tail_unavailable"}));
        let events = Tail::Events { after_sequence: 6, last_sequence: 7, events: vec![json!({"sequence":7})] };
        assert_eq!(events.cached_through(), 7);
        assert_eq!(events.answer(), json!({"version":2,"after_sequence":6,"last_sequence":7,"history_events":[{"sequence":7}]}));
    }
}
