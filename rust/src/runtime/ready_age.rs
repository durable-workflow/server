//! Eligibility-to-lease measurement for an actual workflow/activity claim.
use super::backend::Backend;
use chrono::{DateTime, TimeDelta, Utc};
use serde_json::{Value, json};

pub(super) const MAX_MS: i64 = 9_007_199_254_740_991;

/// Inspect the canonical row before replacing its old lease. Invalid metric
/// data is unknown; it never falls back to task creation or a synthetic zero.
pub(super) fn origin<DB: Backend>(task: &DB::Row, kind: &str) -> Option<DateTime<Utc>> {
    if !matches!(kind, "workflow" | "activity") {
        return None;
    }
    let origin = match DB::string(task, "status").ok()?.as_str() {
        "ready" => DB::optional_instant(task, "ready_origin_at").ok()??,
        "leased" if kind == "workflow" => DB::optional_instant(task, "lease_expires_at").ok()??,
        _ => return None,
    };
    let available = DB::optional_instant(task, "available_at").ok()?;
    Some(available.map_or(origin, |available| origin.max(available)))
}

fn milliseconds(duration: TimeDelta) -> Option<i64> {
    // num_microseconds truncates a negative sub-microsecond duration to zero.
    // Check the signed value before either truncation or millisecond flooring.
    if duration < TimeDelta::zero() {
        return None;
    }
    let value = duration.num_microseconds()? / 1_000;
    (value <= MAX_MS).then_some(value)
}

pub(super) fn annotate(
    claim: &mut Value,
    kind: &str,
    origin: Option<DateTime<Utc>>,
    leased_at: DateTime<Utc>,
) {
    if matches!(kind, "workflow" | "activity")
        && let Some(value) = origin.and_then(|origin| milliseconds(leased_at.signed_duration_since(origin)))
    {
        claim["ready_age_ms"] = json!(value);
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn signed_duration_precedes_quantization_and_real_zero_survives() {
        for (nanos, expected) in [
            (-1_000_000, None),
            (-1, None),
            (0, Some(0)),
            (1, Some(0)),
            (999_999, Some(0)),
            (1_000_000, Some(1)),
            (1_999_999, Some(1)),
        ] {
            assert_eq!(milliseconds(TimeDelta::nanoseconds(nanos)), expected);
        }
    }

    #[test]
    fn portable_bound_and_unrepresentable_duration_are_omitted() {
        assert_eq!(milliseconds(TimeDelta::microseconds(MAX_MS * 1_000 + 999)), Some(MAX_MS));
        assert_eq!(milliseconds(TimeDelta::microseconds((MAX_MS + 1) * 1_000)), None);
        assert_eq!(milliseconds(TimeDelta::MAX), None);
    }

    #[test]
    fn only_a_known_workflow_or_activity_claim_gets_an_actual_integer() {
        let at = DateTime::from_timestamp(1_000, 0).unwrap();
        for kind in ["workflow", "activity"] {
            let mut claim = json!({});
            annotate(&mut claim, kind, Some(at), at);
            assert_eq!(claim, json!({"ready_age_ms":0}));
            assert!(claim["ready_age_ms"].is_i64());
        }
        for kind in ["workflow", "activity", "query", "update_validation", "timer"] {
            for origin in [None, Some(at + TimeDelta::microseconds(1))] {
                let mut claim = json!({});
                annotate(&mut claim, kind, origin, at);
                assert_eq!(claim, json!({}));
            }
        }
        for kind in ["query", "update_validation", "timer"] {
            let mut claim = json!({});
            annotate(&mut claim, kind, Some(at), at + TimeDelta::seconds(1));
            assert_eq!(claim, json!({}));
        }
    }
}
