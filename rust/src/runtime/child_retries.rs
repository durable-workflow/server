//! A child's retry and timeout options (`ChildWorkflowRetryPolicy`): what
//! a `start_child_workflow` command records, and whether a failed child run
//! is retried as a new run of the same child instance.
use serde_json::{Value, json};

const SNAPSHOT_VERSION: i64 = 1;

/// `snapshotExternal`: a missing or empty policy records nothing; any other
/// policy records its attempts, backoff and non-retryable error types.
pub(super) fn retry_snapshot(command: &Value) -> Option<Value> {
    let policy = &command["retry_policy"];
    let empty = match policy {
        Value::Object(map) => map.is_empty(),
        Value::Array(list) => list.is_empty(),
        _ => true,
    };
    if empty {
        return None;
    }
    Some(json!({
        "snapshot_version": SNAPSHOT_VERSION,
        "max_attempts": max_attempts(policy),
        "backoff_seconds": backoff(&policy["backoff_seconds"]),
        "non_retryable_error_types": error_types(&policy["non_retryable_error_types"]),
    }))
}

/// `timeoutSnapshot`: recorded when either timeout is set.
pub(super) fn timeout_snapshot(execution: Option<i64>, run: Option<i64>) -> Option<Value> {
    (execution.is_some() || run.is_some()).then(|| {
        json!({
            "snapshot_version": SNAPSHOT_VERSION,
            "execution_timeout_seconds": execution,
            "run_timeout_seconds": run,
        })
    })
}

/// A command's timeout, when it is an integer (`is_int`).
pub(super) fn timeout(command: &Value, field: &str) -> Option<i64> {
    command[field].as_i64()
}

pub(super) fn max_attempts(policy: &Value) -> i64 {
    policy["max_attempts"].as_i64().map_or(1, |attempts| attempts.max(1))
}

/// The delay before the next attempt, after `completed` attempts: the
/// backoff list's entry for that attempt, or its last entry.
pub(super) fn backoff_seconds(policy: &Value, completed: i64) -> i64 {
    let backoff = backoff(&policy["backoff_seconds"]);
    if backoff.is_empty() {
        return 0;
    }
    let index = usize::try_from((completed - 1).max(0)).unwrap_or(usize::MAX);
    backoff[index.min(backoff.len() - 1)]
}

/// `isNonRetryableFailure`: the run's first failure is marked
/// non-retryable, or the policy names the type or class of the terminal
/// `WorkflowFailed` event or the failure's class.
pub(super) fn non_retryable(policy: &Value, failure: Option<(bool, Option<String>)>, failed_event: Option<&Value>) -> bool {
    if failure.as_ref().is_some_and(|(flag, _)| *flag) {
        return true;
    }
    let types = error_types(&policy["non_retryable_error_types"]);
    if types.is_empty() {
        return false;
    }
    let mut candidates = Vec::new();
    if let Some(event) = failed_event {
        for key in ["exception_type", "exception_class"] {
            if let Some(value) = event[key].as_str() {
                candidates.push(value.to_owned());
            }
        }
    }
    if let Some((_, Some(class))) = failure {
        candidates.push(class);
    }
    candidates.iter().any(|candidate| !candidate.is_empty() && types.contains(candidate))
}

/// `normalizeBackoff`: integers and numeric strings, at least zero.
fn backoff(value: &Value) -> Vec<i64> {
    let Some(entries) = list(value) else {
        return Vec::new();
    };
    entries
        .filter_map(|entry| match entry {
            Value::Number(number) if number.is_i64() => number.as_i64(),
            Value::String(text) => text.trim().parse::<f64>().ok().filter(|v| v.is_finite()).map(|v| v.trunc() as i64),
            _ => None,
        })
        .map(|seconds| seconds.max(0))
        .collect()
}

/// `normalizeErrorTypes`: trimmed, non-empty, distinct strings.
fn error_types(value: &Value) -> Vec<String> {
    let Some(entries) = list(value) else {
        return Vec::new();
    };
    let mut types: Vec<String> = Vec::new();
    for entry in entries {
        let text = entry.as_str().map(str::trim).unwrap_or_default();
        if !text.is_empty() && !types.iter().any(|known| known == text) {
            types.push(text.to_owned());
        }
    }
    types
}

/// A PHP array's values: a list, or an object's values in order.
fn list(value: &Value) -> Option<Box<dyn Iterator<Item = &Value> + '_>> {
    match value {
        Value::Array(entries) => Some(Box::new(entries.iter())),
        Value::Object(entries) => Some(Box::new(entries.values())),
        _ => None,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_policy_is_recorded_as_php_snapshots_it() {
        assert_eq!(retry_snapshot(&json!({})), None);
        assert_eq!(retry_snapshot(&json!({"retry_policy": {}})), None);
        assert_eq!(retry_snapshot(&json!({"retry_policy": []})), None);
        assert_eq!(
            retry_snapshot(&json!({"retry_policy": {"max_attempts": 3, "backoff_seconds": [2, "5", -1],
                "non_retryable_error_types": [" Fatal ", "Fatal", "", 7]}})),
            Some(json!({"snapshot_version": 1, "max_attempts": 3, "backoff_seconds": [2, 5, 0],
                "non_retryable_error_types": ["Fatal"]}))
        );
        assert_eq!(
            retry_snapshot(&json!({"retry_policy": {"max_attempts": 0}})),
            Some(json!({"snapshot_version": 1, "max_attempts": 1, "backoff_seconds": [],
                "non_retryable_error_types": []}))
        );
        assert_eq!(timeout_snapshot(None, None), None);
        assert_eq!(
            timeout_snapshot(None, Some(5)),
            Some(json!({"snapshot_version": 1, "execution_timeout_seconds": null, "run_timeout_seconds": 5}))
        );
    }

    #[test]
    fn backoff_uses_the_entry_for_the_attempt_then_the_last() {
        let policy = json!({"backoff_seconds": [1, 4]});
        assert_eq!(backoff_seconds(&policy, 1), 1);
        assert_eq!(backoff_seconds(&policy, 2), 4);
        assert_eq!(backoff_seconds(&policy, 7), 4);
        assert_eq!(backoff_seconds(&json!({}), 1), 0);
    }

    #[test]
    fn a_failure_is_non_retryable_by_flag_or_named_type() {
        let policy = json!({"non_retryable_error_types": ["Fatal"]});
        assert!(non_retryable(&json!({}), Some((true, None)), None));
        assert!(!non_retryable(&json!({}), Some((false, Some("Fatal".into()))), None));
        assert!(non_retryable(&policy, None, Some(&json!({"exception_type": "Fatal"}))));
        assert!(non_retryable(&policy, None, Some(&json!({"exception_class": "Fatal"}))));
        assert!(non_retryable(&policy, Some((false, Some("Fatal".into()))), None));
        assert!(!non_retryable(&policy, Some((false, Some("Boom".into()))), Some(&json!({"exception_type": "Boom"}))));
    }
}
