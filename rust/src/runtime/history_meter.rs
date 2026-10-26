//! `HistoryTransactionMeter` (Workflow #930): the whole-transaction budget
//! of one workflow task completion. Each history event is charged where it
//! is written ([`Store::record`]): one event, its bytes as `HistoryBudget`
//! measures a stored event, and two rows (the event and the run position it
//! advances). Local activity rows are charged before each write. Past a
//! hard structural limit the completion is refused with PHP's response and
//! its transaction rolls back, so nothing it wrote survives and the task
//! keeps its lease. A run's deadline close happens before the meter starts.
use super::memo_limits::StructuralLimits;
use super::RuntimeError;
use axum::http::StatusCode;
use serde_json::{Value, json};

/// The early whole-completion check's count for each command other than
/// `record_local_activity`: one event, a lower bound
/// (`WorkflowCommandNormalizer::MIN_COMMAND_HISTORY_EVENTS`).
pub(super) const MIN_COMMAND_HISTORY_EVENTS: usize = 1;

#[derive(Debug)]
pub(super) struct Meter {
    limits: [Option<usize>; 3],
    used: [usize; 3],
    /// The fields of PHP's refused completion response.
    refusal: Value,
    tripped: bool,
}

impl Meter {
    pub(super) fn new(limits: &StructuralLimits, task_id: &str, workflow_task_attempt: &Value) -> Self {
        Self {
            limits: [limits.history_events, limits.history_bytes, limits.history_rows],
            used: [0; 3],
            refusal: json!({"task_id": task_id, "workflow_task_attempt": workflow_task_attempt, "outcome": "completed",
                "recorded": false, "run_id": null, "run_status": null, "created_task_ids": [],
                "reason": "history_transaction_limit_exceeded"}),
            tripped: false,
        }
    }

    /// One history event about to be stored.
    pub(super) fn charge_event(&mut self, event_type: &str, payload: &Value) -> Result<(), RuntimeError> {
        self.charge(0, 1)?;
        self.charge(1, event_type.len().saturating_add(php_json_len(payload)))?;
        self.charge(2, 2)
    }

    /// Rows the next statement will write, charged before it runs.
    pub(super) fn charge_rows(&mut self, rows: usize) -> Result<(), RuntimeError> {
        self.charge(2, rows)
    }

    /// History bytes an already charged event grows by before it is
    /// rewritten, charged before the update.
    pub(super) fn charge_bytes(&mut self, bytes: usize) -> Result<(), RuntimeError> {
        self.charge(1, bytes)
    }

    fn charge(&mut self, dimension: usize, amount: usize) -> Result<(), RuntimeError> {
        if self.tripped {
            return Err(self.refused());
        }
        // An overflow can only exceed every limit.
        let next = self.used[dimension].checked_add(amount).unwrap_or(usize::MAX);
        if self.limits[dimension].is_some_and(|limit| limit > 0 && next > limit) {
            self.tripped = true;
            return Err(self.refused());
        }
        self.used[dimension] = next;
        Ok(())
    }

    /// The refusal again, if any charge has been refused.
    pub(super) fn tripped(&self) -> Option<RuntimeError> {
        self.tripped.then(|| self.refused())
    }

    fn refused(&self) -> RuntimeError {
        RuntimeError::Protocol { status: StatusCode::CONFLICT, response: self.refusal.clone() }
    }

    /// The completion's refused response with another reason.
    pub(super) fn refused_with(&self, reason: &str) -> RuntimeError {
        let mut response = self.refusal.clone();
        response["reason"] = json!(reason);
        RuntimeError::Protocol { status: StatusCode::CONFLICT, response }
    }
}

/// The length of `json_encode($payload, JSON_UNESCAPED_SLASHES |
/// JSON_UNESCAPED_UNICODE)`: serde's output, plus the escapes PHP still
/// writes for U+2028 and U+2029 under JSON_UNESCAPED_UNICODE.
pub(super) fn php_json_len(payload: &Value) -> usize {
    let encoded = serde_json::to_string(payload).unwrap_or_default();
    let separators = encoded.matches(['\u{2028}', '\u{2029}']).count();
    encoded.len().saturating_add(separators.saturating_mul(3))
}

/// The exact history events and rows a normalized `record_local_activity`
/// record writes (`localActivityHistoryEvents`, `localActivityRows`): one
/// scheduled event; per attempt a started event, its heartbeats and, before
/// the next attempt, a retry event; one terminal event. Each event is two
/// rows; the execution, each attempt and a failure add theirs.
pub(super) fn local_activity_budget(attempts: &[Value], failed: bool) -> (usize, usize) {
    let mut events = 2usize.saturating_add(attempts.len().saturating_sub(1));
    for attempt in attempts {
        let heartbeats = attempt["heartbeats"].as_array().map_or(0, Vec::len);
        events = events.saturating_add(1).saturating_add(heartbeats);
    }
    let rows = events.saturating_mul(2).saturating_add(1).saturating_add(attempts.len()).saturating_add(usize::from(failed));
    (events, rows)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn limits(events: usize, bytes: usize, rows: usize) -> StructuralLimits {
        StructuralLimits { history_events: Some(events), history_bytes: Some(bytes), history_rows: Some(rows), ..StructuralLimits::default() }
    }

    #[test]
    fn events_bytes_and_rows_follow_php_s_measure_and_limits() {
        assert_eq!(php_json_len(&json!({"a": "b/é"})), r#"{"a":"b/é"}"#.len());
        assert_eq!(php_json_len(&json!({"a": "\u{2028}"})), r#"{"a":"\u2028"}"#.len());
        let mut meter = Meter::new(&limits(2, 1_000, 5), "task", &json!(1));
        meter.charge_event("E", &json!({})).unwrap();
        meter.charge_rows(1).unwrap();
        // The second event would be the fourth and fifth rows: allowed.
        meter.charge_event("E", &json!({})).unwrap();
        let Err(RuntimeError::Protocol { status, response }) = meter.charge_rows(1) else { panic!("one row over") };
        assert_eq!(status, StatusCode::CONFLICT);
        assert_eq!(response["reason"], "history_transaction_limit_exceeded");
        assert_eq!((response["task_id"].clone(), response["recorded"].clone()), (json!("task"), json!(false)));
        // A tripped meter refuses everything after, and its commit.
        assert!(meter.charge_rows(0).is_err());
        assert!(matches!(meter.tripped(), Some(RuntimeError::Protocol { status: StatusCode::CONFLICT, .. })));
        // A disabled limit (0) refuses nothing.
        let mut open = Meter::new(&limits(0, 0, 0), "task", &json!(1));
        for _ in 0..10 { open.charge_event("E", &json!({"x": "y"})).unwrap(); }
        assert!(open.tripped().is_none());
    }

    #[test]
    fn local_activity_budget_matches_the_package_formula() {
        let attempts = vec![json!({"heartbeats": [{}, {}]}), json!({"heartbeats": []})];
        // 2 + 1 retry + (1 + 2) + (1 + 0) = 7 events; 14 + 1 + 2 + 1 rows.
        assert_eq!(local_activity_budget(&attempts, true), (7, 18));
        assert_eq!(local_activity_budget(&[json!({"heartbeats": []})], false), (3, 8));
    }
}
