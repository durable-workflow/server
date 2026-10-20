//! Registration declarations become immutable admission snapshots. A missing
//! workflow entry stays unknown; a valid empty entry declares empty lists.
use super::{Result, refuse};
use axum::http::StatusCode;
use serde_json::{Value, json};
use std::collections::BTreeSet;

fn invalid() -> super::RuntimeError {
    refuse(
        StatusCode::UNPROCESSABLE_ENTITY,
        "invalid_worker_definition",
    )
}

fn name(value: &Value) -> Result<&str> {
    let text = value.as_str().ok_or_else(invalid)?;
    let text = text.trim_matches([' ', '\t', '\n', '\r', '\0', '\u{b}']);
    if text.is_empty() || text.chars().count() > 255 {
        return Err(invalid());
    }
    Ok(text)
}

fn list<'a>(entry: &'a Value, field: &str) -> Result<&'a [Value]> {
    match entry.get(field) {
        None => Ok(&[]),
        Some(Value::Array(values)) => Ok(values),
        Some(_) => Err(invalid()),
    }
}

pub(super) fn declaration(entry: &Value) -> Result<Value> {
    if !entry.is_object() && !entry.as_array().is_some_and(Vec::is_empty) {
        return Err(invalid());
    }
    let mut result = json!({});
    for (names, handlers) in [
        ("queries", "query_contracts"),
        ("signals", "signal_contracts"),
        ("updates", "update_contracts"),
    ] {
        let mut declared = BTreeSet::new();
        for value in list(entry, names)? {
            declared.insert(name(value)?.to_owned());
        }
        let mut seen = BTreeSet::new();
        let mut contracts = Vec::new();
        for handler in list(entry, handlers)? {
            let handler_name = name(&handler["name"])?;
            declared.insert(handler_name.to_owned());
            if seen.insert(handler_name.to_owned()) {
                contracts.push(
                    json!({"name":handler_name,"parameters":handler.get("parameters")
                    .filter(|value| value.is_array()).cloned().unwrap_or_else(|| json!([]))}),
                );
            }
        }
        result[names] = json!(declared);
        result[handlers] = json!(contracts);
    }
    if entry.get("update_validators").is_some() {
        let mut validators = BTreeSet::new();
        for value in list(entry, "update_validators")? {
            validators.insert(name(value)?.to_owned());
        }
        result["update_validators"] = json!(validators);
    }
    Ok(result)
}

pub(super) fn normalize(value: &Value, supported: &Value) -> Result<Value> {
    if value.is_null() || value.as_array().is_some_and(Vec::is_empty) {
        return Ok(json!({}));
    }
    let entries = value.as_object().ok_or_else(invalid)?;
    let mut result = json!({});
    for (workflow_type, entry) in entries {
        // Validate even an unadvertised declaration before filtering it out.
        // Otherwise malformed metadata could rotate an existing registration.
        let entry = declaration(entry)?;
        let workflow_type = workflow_type.trim_matches([' ', '\t', '\n', '\r', '\0', '\u{b}']);
        if !workflow_type.is_empty()
            && supported.as_array().is_some_and(|types| {
                types
                    .iter()
                    .any(|value| value.as_str() == Some(workflow_type))
            })
        {
            result[workflow_type] = entry;
        }
    }
    Ok(result)
}
