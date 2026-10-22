//! Original query timing and content-bound receipts. These helpers never
//! derive a new execution deadline from a poll, observation or retry.
use super::{Result, RuntimeError, refuse};
use axum::http::StatusCode;
use chrono::{DateTime, SecondsFormat, Utc};
use serde_json::{Value, json};
use sha2::{Digest, Sha256};
use std::env;

#[derive(Clone, Debug)]
pub struct QueryTaskConfig {
    pub timeout_seconds: i64,
    pub lease_timeout_seconds: i64,
    pub receipt_ttl_seconds: i64,
    pub worker_stale_after_seconds: i64,
    /// The worker heartbeat cadence advertised on registration and heartbeat.
    pub worker_heartbeat_interval_seconds: i64,
    /// How long a claimed workflow task stays leased, as PHP's DW_WORKFLOW_TASK_TIMEOUT.
    pub workflow_task_lease_seconds: i64,
    /// How long a claimed activity task stays leased, as PHP's
    /// `ActivityTaskLeaseConfiguration` (DW_ACTIVITY_TASK_TIMEOUT).
    pub activity_task_lease_seconds: i64,
    /// `UpdateWaitPolicy::completionTimeoutSeconds`
    /// (DW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS).
    pub update_wait_completion_timeout_seconds: i64,
    /// History page sizes (DW_HISTORY_PAGE_SIZE_DEFAULT and _MAX).
    pub history_page_size_default: i64,
    pub history_page_size_max: i64,
    pub poll_timeout_seconds: i64,
    /// How long a workflow or activity poll that names no `timeout_seconds`
    /// waits, as PHP's `LongPoller`: `max(0, DW_WORKER_POLL_TIMEOUT)`.
    pub worker_poll_timeout_seconds: i64,
}

impl Default for QueryTaskConfig {
    fn default() -> Self {
        Self {
            timeout_seconds: 45,
            lease_timeout_seconds: 60,
            receipt_ttl_seconds: 180,
            worker_stale_after_seconds: 30,
            worker_heartbeat_interval_seconds: 10,
            workflow_task_lease_seconds: 60,
            activity_task_lease_seconds: 300,
            update_wait_completion_timeout_seconds: 10,
            history_page_size_default: 500,
            history_page_size_max: 1000,
            poll_timeout_seconds: 5,
            worker_poll_timeout_seconds: 30,
        }
    }
}

impl QueryTaskConfig {
    pub(super) fn from_env() -> Result<Self> {
        fn integer(primary: &str, legacy: &str, default: i64) -> Result<i64> {
            for key in [primary, legacy] {
                match env::var(key) {
                    Ok(value) => {
                        if let Some(value) = environment_integer(&value) {
                            return Ok(value);
                        }
                    }
                    Err(env::VarError::NotPresent) => {}
                    Err(_) => {
                        return Err(refuse(
                            StatusCode::BAD_REQUEST,
                            "invalid_query_configuration",
                        ));
                    }
                }
            }
            Ok(default)
        }
        let poll = integer(
            "DW_WORKER_POLL_TIMEOUT",
            "WORKFLOW_SERVER_WORKER_POLL_TIMEOUT",
            30,
        )?;
        let lease = integer("DW_WORKFLOW_TASK_TIMEOUT", "WORKFLOW_TASK_TIMEOUT", 60)?;
        let heartbeat = integer(
            "DW_WORKER_HEARTBEAT_INTERVAL_SECONDS",
            "WORKFLOW_SERVER_WORKER_HEARTBEAT_INTERVAL_SECONDS",
            10,
        )?;
        Ok(Self {
            timeout_seconds: integer(
                "DW_QUERY_TASK_TIMEOUT",
                "WORKFLOW_SERVER_QUERY_TASK_TIMEOUT",
                poll.saturating_add(15).clamp(40, 55),
            )?
            .max(0),
            lease_timeout_seconds: integer(
                "DW_QUERY_TASK_LEASE_TIMEOUT",
                "WORKFLOW_SERVER_QUERY_TASK_LEASE_TIMEOUT",
                lease,
            )?
            .max(1),
            receipt_ttl_seconds: integer(
                "DW_QUERY_TASK_TTL_SECONDS",
                "WORKFLOW_SERVER_QUERY_TASK_TTL_SECONDS",
                180,
            )?
            .max(60),
            worker_stale_after_seconds: integer(
                "DW_WORKER_STALE_AFTER_SECONDS",
                "WORKFLOW_SERVER_WORKER_STALE_AFTER_SECONDS",
                heartbeat.saturating_mul(3).max(30),
            )?
            .max(1),
            // PHP clamps the advertised cadence to one second through an hour.
            worker_heartbeat_interval_seconds: heartbeat.clamp(1, 3600),
            workflow_task_lease_seconds: lease.max(1),
            activity_task_lease_seconds: crate::config_audit::activity_task_lease_seconds(|key| env::var(key).ok())
                .map_err(|_| refuse(StatusCode::BAD_REQUEST, "invalid_activity_task_timeout"))?,
            update_wait_completion_timeout_seconds: crate::config_audit::update_wait_completion_timeout_seconds(|key| env::var(key).ok()),
            history_page_size_default: crate::config_audit::history_page_sizes(|key| env::var(key).ok()).0,
            history_page_size_max: crate::config_audit::history_page_sizes(|key| env::var(key).ok()).1,
            poll_timeout_seconds: integer(
                "DW_QUERY_TASK_POLL_TIMEOUT",
                "WORKFLOW_SERVER_QUERY_TASK_POLL_TIMEOUT",
                5,
            )?
            .max(1),
            worker_poll_timeout_seconds: poll.max(0),
        })
    }

    pub(super) fn add(time: DateTime<Utc>, seconds: i64) -> Result<DateTime<Utc>> {
        chrono::Duration::try_seconds(seconds)
            .and_then(|duration| time.checked_add_signed(duration))
            .ok_or_else(|| refuse(StatusCode::BAD_REQUEST, "invalid_query_configuration"))
    }

    pub(super) fn timing(&self, admitted: DateTime<Utc>) -> Result<(DateTime<Utc>, DateTime<Utc>)> {
        let timeout = self.timeout_seconds.max(0);
        let deadline = Self::add(admitted, timeout)?;
        let retention = self.receipt_ttl_seconds.max(60).max(
            timeout
                .saturating_add(self.lease_timeout_seconds.max(1))
                .saturating_add(60),
        );
        Ok((deadline, Self::add(admitted, retention)?))
    }
}

fn environment_integer(raw: &str) -> Option<i64> {
    match raw.to_ascii_lowercase().as_str() {
        "null" | "(null)" => return None,
        "true" | "(true)" => return Some(1),
        "false" | "(false)" | "empty" | "(empty)" => return Some(0),
        _ => {}
    }
    let value = raw
        .strip_prefix('"')
        .and_then(|value| value.strip_suffix('"'))
        .or_else(|| {
            raw.strip_prefix('\'')
                .and_then(|value| value.strip_suffix('\''))
        })
        .unwrap_or(raw);
    Some(super::memo_limits::cast_numeric_string(value))
}

pub(super) fn now() -> DateTime<Utc> {
    DateTime::from_timestamp_micros(Utc::now().timestamp_micros()).expect("current UTC time")
}

pub(super) fn wire(time: DateTime<Utc>) -> String {
    time.to_rfc3339_opts(SecondsFormat::Micros, true)
}

pub(super) fn timestamp(value: &Value) -> Option<DateTime<Utc>> {
    let raw = value.as_str()?;
    if raw.len() != 27 || !raw.ends_with('Z') {
        return None;
    }
    let parsed = DateTime::parse_from_rfc3339(raw).ok()?.with_timezone(&Utc);
    (wire(parsed) == raw).then_some(parsed)
}

pub(super) fn deadline(task: &Value) -> Option<DateTime<Utc>> {
    let admitted = timestamp(&task["query_admitted_at"])?;
    let deadline = timestamp(&task["query_deadline_at"])?;
    let retention = timestamp(&task["receipt_expires_at"])?;
    let minimum = deadline.checked_add_signed(chrono::Duration::seconds(60))?;
    (deadline >= admitted && retention >= minimum).then_some(deadline)
}

pub(super) fn expiration(task: &Value) -> Result<i64> {
    let expires = timestamp(&task["receipt_expires_at"])
        .ok_or_else(|| refuse(StatusCode::CONFLICT, "query_deadline_unavailable"))?;
    // SQL cache expiry is second-resolution. Round cleanup up, while the
    // canonical microsecond timestamp remains the authority for every read.
    Ok(expires
        .timestamp()
        .saturating_add(i64::from(expires.timestamp_subsec_micros() != 0)))
}

pub(super) fn scope(task: &Value, id: &str, namespace: &str) -> Result<()> {
    if task["query_task_id"] != id || task["namespace"] != namespace {
        return Err(refuse(StatusCode::NOT_FOUND, "query_task_not_found"));
    }
    Ok(())
}

pub(super) fn operation(body: &Value, failed: bool) -> Result<String> {
    let payload = if failed {
        body["failure"].clone()
    } else {
        json!({"result":body["result"],"result_envelope":body["result_envelope"]})
    };
    // serde_json's ordered maps preserve object/list and scalar distinctions.
    // This fingerprint is private native cache state, never a wire primitive.
    let bytes = serde_json::to_vec(&json!([if failed { "fail" } else { "complete" }, payload]))?;
    Ok(Sha256::digest(bytes)
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect())
}

pub(super) fn receipt(
    task: &Value,
    owner: &str,
    attempt: i64,
    fingerprint: &str,
    time: DateTime<Utc>,
) -> Result<Option<Value>> {
    let receipt = &task["terminal_receipt"];
    if !receipt.is_object()
        || deadline(task).is_none()
        || timestamp(&task["receipt_expires_at"]).is_none_or(|expiry| expiry <= time)
        || receipt["lease_owner"] != owner
        || receipt["attempt"] != attempt
    {
        return Ok(None);
    }
    if receipt["fingerprint"] != fingerprint {
        return Err(refuse(StatusCode::CONFLICT, "query_completion_conflict"));
    }
    let mut decision = receipt["decision"].clone();
    if !decision.is_object() || decision["query_task_id"] != task["query_task_id"] {
        return Err(refuse(StatusCode::CONFLICT, "query_receipt_unavailable"));
    }
    decision["recorded"] = json!(false);
    Ok(Some(decision))
}

pub(super) fn claim_reason(
    task: &Value,
    owner: &str,
    attempt: i64,
    time: DateTime<Utc>,
) -> Option<&'static str> {
    let Some(deadline) = deadline(task) else {
        return Some("query_deadline_unavailable");
    };
    if matches!(task["status"].as_str(), Some("completed" | "failed")) {
        return Some("query_task_not_leased");
    }
    if time >= deadline {
        return Some("query_task_timed_out");
    }
    if task["status"] == "timed_out" {
        return Some("query_task_timed_out");
    }
    if task["status"] != "leased" {
        return Some("query_task_not_leased");
    }
    if task["lease_owner"] != owner {
        return Some("lease_owner_mismatch");
    }
    if task["query_task_attempt"] != attempt {
        return Some("query_task_attempt_mismatch");
    }
    if timestamp(&task["lease_expires_at"]).is_none_or(|lease| time >= lease) {
        return Some("lease_expired");
    }
    None
}

pub(super) fn discard_snapshot(task: &mut Value) {
    if let Some(object) = task.as_object_mut() {
        for field in ["history_events", "workflow_arguments", "query_arguments"] {
            object.remove(field);
        }
    }
}

pub(super) fn expire(task: &mut Value, time: DateTime<Utc>) -> Result<bool> {
    if !matches!(task["status"].as_str(), Some("pending" | "leased")) {
        return Ok(false);
    }
    let original = deadline(task);
    if original.is_some_and(|deadline| time < deadline) {
        return Ok(false);
    }
    if original.is_none() {
        task["status"] = json!("failed");
        task["failure"] = json!({"reason":"query_deadline_unavailable", "message":"The original query result deadline is unavailable. Submit a new query explicitly."});
        // A legacy entry gets only one refusal tombstone. This timestamp never
        // becomes an admission time, execution budget or successful receipt.
        if timestamp(&task["receipt_expires_at"]).is_none() {
            task["receipt_expires_at"] = json!(wire(QueryTaskConfig::add(time, 60)?));
        }
        task["legacy_refusal_at"] = json!(wire(time));
    } else {
        task["status"] = json!("timed_out");
        task["timed_out_at"] = json!(wire(time));
    }
    discard_snapshot(task);
    Ok(true)
}

pub(super) fn reject_reason(reason: &'static str) -> RuntimeError {
    refuse(StatusCode::CONFLICT, reason)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn zero_timeout_preserves_admission_and_retention_microseconds() {
        let admitted = timestamp(&json!("2026-10-15T00:00:00.123456Z")).unwrap();
        let policy = QueryTaskConfig {
            timeout_seconds: 0,
            receipt_ttl_seconds: 1,
            lease_timeout_seconds: 2,
            ..Default::default()
        };
        let (deadline, retention) = policy.timing(admitted).unwrap();
        assert_eq!(deadline, admitted);
        assert_eq!(retention, admitted + chrono::Duration::seconds(62));
        let task = json!({"query_admitted_at":wire(admitted),"query_deadline_at":wire(deadline),"receipt_expires_at":wire(retention)});
        assert_eq!(super::deadline(&task), Some(admitted));
        assert_eq!(expiration(&task).unwrap(), retention.timestamp() + 1);
        assert_eq!(
            claim_reason(&task, "worker", 1, admitted),
            Some("query_task_timed_out")
        );
    }

    #[test]
    fn missing_or_noncanonical_original_time_gets_one_refusal_without_rebasing() {
        let time = timestamp(&json!("2026-10-15T00:00:00.123456Z")).unwrap();
        for unavailable in [
            Value::Null,
            json!("2026-10-15T00:00:00Z"),
            json!("2026-10-15 00:00:00.123456"),
            json!("2026-10-15T00:00:00.123456+00:00"),
        ] {
            let mut task = json!({"status":"leased","deadline":9999999999_i64,"query_admitted_at":wire(time),
                "query_deadline_at":unavailable,"receipt_expires_at":wire(time+chrono::Duration::seconds(120)),
                "history_events":[{"must_not_be_consumed":true}]});
            assert_eq!(
                claim_reason(&task, "worker", 1, time),
                Some("query_deadline_unavailable")
            );
            assert!(expire(&mut task, time).unwrap());
            let once = task.clone();
            assert!(!expire(&mut task, time + chrono::Duration::seconds(20)).unwrap());
            assert_eq!(task, once);
            assert_eq!(task["status"], "failed");
            assert_eq!(task["query_admitted_at"], wire(time));
            assert!(task.get("history_events").is_none());
            assert_eq!(
                timestamp(&task["receipt_expires_at"]),
                Some(time + chrono::Duration::seconds(120))
            );
        }
    }

    #[test]
    fn environment_values_keep_php_null_fallback_and_integer_casts() {
        for (raw, expected) in [
            ("null", None),
            ("(null)", None),
            ("true", Some(1)),
            ("false", Some(0)),
            ("\"4\"", Some(4)),
            ("-2", Some(-2)),
            ("2e2", Some(200)),
            ("garbage", Some(0)),
        ] {
            assert_eq!(environment_integer(raw), expected, "{raw}");
        }
    }
}
