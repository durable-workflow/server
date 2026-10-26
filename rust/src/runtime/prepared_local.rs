//! The prepared local-activity worker routes, as a published PHP server
//! answers them when its installed Workflow bridge lacks the prepared role
//! (`PreparedLocalActivityController::unavailable` and `refused`, and
//! `WorkerController::checkpointLocalActivityPrefix` and
//! `checkpointLocalActivityGroup`).
//!
//! Native advertises `prepared_local_activities: false`, no groups and no
//! cancellation policies, so SDKs record local activities with
//! `record_local_activity` instead. A worker that calls one of these routes
//! anyway is refused before any task is read, with PHP's 409 body:
//! - `request_protocol` when its protocol version is below 1.20;
//! - `installed_runtime_prepared_local_activity`, always;
//! - for `checkpoint-group`, also `installed_runtime_prepared_local_activity_groups`.
//!
//! A version header native does not support at all is refused earlier, by the
//! worker protocol gate, with 400 as in PHP. PHP's own server-protocol reason
//! never applies: native serves cooperative cancellation.
//!
//! PHP reports `(string) $request->input('lease_owner', '')` as `worker_id`. An
//! array or object there is an array-to-string conversion, which Laravel turns
//! into a 500. Native reports an empty `worker_id` instead.
use super::laravel_rules;
use axum::http::StatusCode;
use serde_json::{Value, json};

/// `PreparedLocalActivityPolicy::MINIMUM_PROTOCOL_VERSION`.
const MINIMUM_PROTOCOL_VERSION: &str = "1.20";

/// Which prepared capability the refused route needs.
#[derive(Clone, Copy)]
pub(crate) enum Capability {
    /// Prepare, recover, control, heartbeat, outcome, acknowledge-cancellation
    /// and the local prefix checkpoint.
    Prepared,
    /// The group checkpoint.
    Groups,
}

/// PHP's 409 for a route whose prepared role the installed runtime lacks.
pub(crate) fn refusal(body: &Value, protocol: Option<&str>, capability: Capability) -> (StatusCode, Value) {
    let mut unavailable = Vec::new();
    if !super::worker_admission::at_least(protocol, MINIMUM_PROTOCOL_VERSION) {
        unavailable.push("request_protocol");
    }
    unavailable.push("installed_runtime_prepared_local_activity");
    let (reason, required) = match capability {
        Capability::Prepared => ("prepared_local_activity_not_supported", "prepared_local_activities"),
        Capability::Groups => {
            unavailable.push("installed_runtime_prepared_local_activity_groups");
            ("prepared_local_activity_groups_not_supported", "prepared_local_activity_groups")
        }
    };
    let body = laravel_rules::normalize(body);
    let worker_id = match &body["lease_owner"] {
        Value::String(text) => text.clone(),
        Value::Number(number) => php_number(number),
        Value::Bool(true) => "1".into(),
        _ => String::new(),
    };
    let response = json!({
        "reason": reason,
        "required_capability": required,
        "minimum_protocol_version": MINIMUM_PROTOCOL_VERSION,
        "worker_id": worker_id,
        "unavailable": unavailable,
        "remediation": "Use a prepared-local backend and a claim issued to a capable protocol 1.20 worker.",
    });
    (StatusCode::CONFLICT, super::cancellation_scopes::enveloped(response))
}

/// PHP's `(string)` of a JSON number.
fn php_number(number: &serde_json::Number) -> String {
    match number.as_i64() {
        Some(whole) => whole.to_string(),
        None => {
            let float = number.as_f64().unwrap_or_default();
            if float.fract() == 0.0 && float.abs() < 1e15 { format!("{float:.0}") } else { float.to_string() }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_current_worker_is_refused_for_the_missing_runtime_role_only() {
        let (status, body) = refusal(&json!({"lease_owner": " worker-1 "}), Some("1.20"), Capability::Prepared);
        assert_eq!(status, StatusCode::CONFLICT);
        assert_eq!(body["reason"], "prepared_local_activity_not_supported");
        assert_eq!(body["required_capability"], "prepared_local_activities");
        assert_eq!(body["worker_id"], "worker-1");
        assert_eq!(body["unavailable"], json!(["installed_runtime_prepared_local_activity"]));
    }

    #[test]
    fn an_older_worker_is_also_refused_for_its_protocol() {
        let (_, body) = refusal(&json!({}), Some("1.19"), Capability::Groups);
        assert_eq!(body["reason"], "prepared_local_activity_groups_not_supported");
        assert_eq!(body["required_capability"], "prepared_local_activity_groups");
        assert_eq!(body["worker_id"], "");
        assert_eq!(
            body["unavailable"],
            json!(["request_protocol", "installed_runtime_prepared_local_activity", "installed_runtime_prepared_local_activity_groups"])
        );
    }

    #[test]
    fn worker_id_is_php_string_cast_of_lease_owner() {
        for (owner, expected) in [(json!(7), "7"), (json!(true), "1"), (json!(false), ""), (json!(null), ""), (json!(["x"]), "")] {
            let (_, body) = refusal(&json!({"lease_owner": owner}), Some("1.20"), Capability::Prepared);
            assert_eq!(body["worker_id"], expected, "{owner}");
        }
    }
}
