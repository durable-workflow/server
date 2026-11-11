//! Sticky workflow execution as published PHP Server 2.11.8 and Core
//! 2.15.12 run it (Server #1017).
//!
//! A worker's workflow task completion may claim the run with `sticky_cache`.
//! The run then records that worker and the claim's expiry. Routing does not
//! change: any eligible worker may claim the run's next task, and every claim
//! still delivers full history. The claim only tells the worker which replay
//! to expect: `sticky_hit_expected` when the sticky worker claims within the
//! expiry, `forced_cold_replay` when the affinity expired or another worker
//! claimed, and `cold_replay` when the run has no affinity.
use chrono::{DateTime, Utc};
use serde_json::{Value, json};

pub(super) const STICKY_HIT_EXPECTED: &str = "sticky_hit_expected";
pub(super) const COLD_REPLAY: &str = "cold_replay";
pub(super) const FORCED_COLD_REPLAY: &str = "forced_cold_replay";
const DEFAULT_TTL_SECONDS: i64 = 300;

/// `StickyExecution::describe()`, the server capability PHP advertises.
pub(super) fn describe() -> Value {
    json!({
        "feature": "sticky_execution",
        "supported": true,
        "worker_capability": "sticky_execution",
        "minimum_worker_protocol_version": super::local_activities::MINIMUM_PROTOCOL_VERSION,
        "kind": "v2_replay_optimization",
        "correctness_fallback": "cold_replay",
        "routing_identity": "worker_id",
        "cache_owner": "worker_process",
        "cache_key_fields": ["workflow_id", "run_id", "build_id"],
        "durable_affinity_fields": ["sticky_worker_id", "sticky_until"],
        "task_diagnostic_fields": ["sticky_replay_mode", "sticky_claimed_at"],
        "replay_modes": [STICKY_HIT_EXPECTED, COLD_REPLAY, FORCED_COLD_REPLAY],
        "metrics": ["hit", "miss", "eviction", "forced_cold_replay"],
        "default_ttl_seconds": DEFAULT_TTL_SECONDS,
        "default_cache_capacity": 100,
        "default_capacity_pressure_ratio": 0.9,
        "contract": [
            "sticky caches are process-local and owned by the worker that advertises them",
            "sticky affinity expires and ordinary cold replay is always the correctness fallback",
            "worker replacement, restart, drain, or cache eviction may force cold replay",
            "workflow code must remain replay-safe and must not rely on process-local state for correctness",
        ],
    })
}

/// `StickyExecution::claimReplayMode` for a claim by `lease_owner` of a run
/// whose affinity is `worker` until `until`.
pub(super) fn claim_mode(worker: Option<&str>, until: Option<DateTime<Utc>>, lease_owner: &str, now: DateTime<Utc>) -> &'static str {
    let Some(worker) = worker.filter(|worker| !worker.is_empty()) else {
        return COLD_REPLAY;
    };
    if until.is_none_or(|until| until <= now) {
        return FORCED_COLD_REPLAY;
    }
    if worker == lease_owner { STICKY_HIT_EXPECTED } else { FORCED_COLD_REPLAY }
}

/// A run in one of these states keeps no affinity.
pub(super) fn terminal(status: &str) -> bool {
    matches!(status, "completed" | "failed" | "cancelled" | "terminated" | "continued")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn claims_follow_cores_replay_rule() {
        let now = Utc::now();
        let later = Some(now + chrono::Duration::seconds(60));
        assert_eq!(claim_mode(None, later, "a", now), COLD_REPLAY);
        assert_eq!(claim_mode(Some(""), later, "a", now), COLD_REPLAY);
        assert_eq!(claim_mode(Some("a"), later, "a", now), STICKY_HIT_EXPECTED);
        assert_eq!(claim_mode(Some("a"), later, "b", now), FORCED_COLD_REPLAY);
        assert_eq!(claim_mode(Some("a"), Some(now), "a", now), FORCED_COLD_REPLAY);
        assert_eq!(claim_mode(Some("a"), None, "a", now), FORCED_COLD_REPLAY);
    }
}
