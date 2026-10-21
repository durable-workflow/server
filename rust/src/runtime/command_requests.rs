//! The request rules of `WorkflowController::signal` and `::update`. Fields
//! outside the rules are ignored, as Laravel's validated input leaves them.
use super::laravel_validation::{Rule, failure_messages, rule_errors};
use super::{Result, refuse};
use axum::http::StatusCode;
use serde_json::Value;
use Rule::{Array, Boolean, In, Integer, Max, Min, Nullable};

const SIGNAL_RULES: &[(&str, &[Rule])] = &[
    ("input", &[Nullable, Array]),
    ("request_id", &[Nullable, Rule::String, Max(255)]),
    ("dry_run", &[Nullable, Boolean]),
];

const UPDATE_RULES: &[(&str, &[Rule])] = &[
    ("input", &[Nullable, Array]),
    ("request_id", &[Nullable, Rule::String, Max(255)]),
    ("wait_for", &[Nullable, Rule::String, In(&["accepted", "completed"])]),
    ("wait_timeout_seconds", &[Nullable, Integer, Min(1)]),
];

fn defaults(_: &str, _: Rule) -> Option<&'static str> {
    None
}

fn checked(body: &Value, rules: &[(&str, &[Rule])]) -> Result<()> {
    match failure_messages(rule_errors(body, rules, defaults)) {
        Some(error) => Err(error),
        None => Ok(()),
    }
}

pub(super) fn signal(body: &Value) -> Result<()> {
    checked(body, SIGNAL_RULES)?;
    // PHP previews a dry run without recording the signal. Native has no
    // preview, so it refuses rather than send the signal.
    if matches!(&body["dry_run"], Value::Bool(true)) || body["dry_run"] == 1 || body["dry_run"] == "1" {
        return Err(refuse(StatusCode::NOT_IMPLEMENTED, "signal_dry_run_unavailable"));
    }
    Ok(())
}

pub(super) fn update(body: &Value) -> Result<()> {
    checked(body, UPDATE_RULES)?;
    // `rejectLegacyUpdateFields`.
    if body.get("wait_policy").is_some() {
        return Err(failure_messages(vec![(
            "wait_policy".into(),
            vec!["The wait_policy field is no longer supported. Use wait_for.".into()],
        )])
        .expect("one message"));
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn errors(result: Result<()>) -> Value {
        match result {
            Err(super::super::RuntimeError::Protocol { response, .. }) => response["errors"].clone(),
            other => panic!("{other:?}"),
        }
    }

    #[test]
    fn rules_report_php_messages_and_ignore_other_fields() {
        assert!(signal(&json!({"input":[1],"request_id":"r","dry_run":false,"other":1})).is_ok());
        assert_eq!(
            errors(signal(&json!({"input":"x","request_id":7,"dry_run":"yes"}))),
            json!({"input":["The input field must be an array."],"request_id":["The request id field must be a string."],
                "dry_run":["The dry run field must be true or false."]})
        );
        assert!(matches!(signal(&json!({"dry_run":1})), Err(super::super::RuntimeError::Refused { .. })));
        assert!(update(&json!({"wait_for":"accepted","wait_timeout_seconds":5,"other":1})).is_ok());
        assert_eq!(
            errors(update(&json!({"wait_for":"done","wait_timeout_seconds":0}))),
            json!({"wait_for":["The selected wait for is invalid."],
                "wait_timeout_seconds":["The wait timeout seconds field must be at least 1."]})
        );
        assert_eq!(
            errors(update(&json!({"wait_policy":"accepted"}))),
            json!({"wait_policy":["The wait_policy field is no longer supported. Use wait_for."]})
        );
    }
}
