//! Caller-indexed Nexus operation reads, as published Server 2.11.1 serves
//! them (`ServiceCatalogController::nexusOperationsForWorkflow` and
//! `nexusOperationsForRun`): the namespace's outbound service calls of one
//! workflow, or of one of its runs, newest first. Neither route checks that
//! the workflow or run exists. Native starts no Nexus operations and takeover
//! refuses a source that holds any service call, so today every list is
//! empty; rows a later service-call port writes render as PHP renders them.
use super::Result;
use serde_json::{Map, Value, json};

/// `config('server.limits.max_nexus_operations_per_caller')`. Native refuses
/// any other DW_MAX_NEXUS_OPERATIONS_PER_CALLER at startup.
const CALLER_LIMIT: i64 = 200;

/// `nexusListLimit`: the configured cap unless the query asks for a number;
/// a numeric request is cast like PHP's `(int)`, raised to 1 and capped.
pub(crate) fn list_limit(requested: Option<&str>) -> i64 {
    // Laravel trims the query string and turns an empty value into null.
    let Some(text) = requested.map(super::laravel_validation::laravel_trim).filter(|text| !text.is_empty()) else {
        return CALLER_LIMIT;
    };
    let Some(number) = super::laravel_validation::php_numeric(&json!(text)) else {
        return CALLER_LIMIT;
    };
    // `(int)` of a numeric string truncates toward zero and saturates.
    let value = if number.is_nan() { 0 } else { number.trunc().clamp(i64::MIN as f64, i64::MAX as f64) as i64 };
    if value < 1 { 1 } else { value.min(CALLER_LIMIT) }
}

/// PHP's `json_encode` of a decoded JSON column: an empty map is `[]`.
fn php_array(value: Value) -> Value {
    match value {
        Value::Object(fields) if fields.is_empty() => json!([]),
        Value::Object(fields) => Value::Object(fields.into_iter().map(|(key, value)| (key, php_array(value))).collect()),
        Value::Array(items) => Value::Array(items.into_iter().map(php_array).collect()),
        other => other,
    }
}

/// `metadataString`: a trimmed, non-empty string field of the metadata.
fn metadata_string(metadata: &Value, key: &str) -> Value {
    metadata.get(key).and_then(Value::as_str).map(str::trim).filter(|text| !text.is_empty()).map_or(Value::Null, |text| json!(text))
}

/// `serviceCallAttempts`: the first `service_call_attempts` list in the
/// call's metadata, then its outcome metadata, keeping only the maps.
fn attempts(metadata: &Value, outcome_metadata: &Value) -> Vec<Value> {
    for container in [metadata, outcome_metadata] {
        match container.get("service_call_attempts") {
            Some(Value::Array(items)) => return items.iter().filter(|item| item.is_object() || item.is_array()).cloned().collect(),
            Some(Value::Object(items)) => return items.values().filter(|item| item.is_object() || item.is_array()).cloned().collect(),
            _ => {}
        }
    }
    Vec::new()
}

store_methods! {
    /// The caller rows and the resolved limit, newest first.
    pub(crate) async fn nexus_operations(&self, workflow_id: &str, run_id: Option<&str>, limit: i64) -> Result<Vec<Value>> {
        let rows = match run_id {
            Some(run_id) => Self::query("SELECT * FROM workflow_service_calls WHERE caller_namespace=$1 AND caller_workflow_instance_id=$2 AND caller_workflow_run_id=$3 ORDER BY accepted_at DESC,created_at DESC LIMIT $4")
                .bind(self.namespace.as_ref()).bind(workflow_id).bind(run_id).bind(limit).fetch_all(&self.pool).await?,
            None => Self::query("SELECT * FROM workflow_service_calls WHERE caller_namespace=$1 AND caller_workflow_instance_id=$2 ORDER BY accepted_at DESC,created_at DESC LIMIT $3")
                .bind(self.namespace.as_ref()).bind(workflow_id).bind(limit).fetch_all(&self.pool).await?,
        };
        let iso = |row: &DB::Row, field: &str| -> Result<Value> {
            // Carbon's `toIso8601String`: seconds and a numeric offset.
            Ok(DB::optional_instant(row, field)?
                .map_or(Value::Null, |at| json!(at.to_rfc3339_opts(chrono::SecondsFormat::Secs, false))))
        };
        let mut calls = Vec::with_capacity(rows.len());
        for row in &rows {
            let text = |field: &str| -> Result<Value> { Ok(DB::optional_string(row, field)?.map_or(Value::Null, Value::from)) };
            let document = |field: &str| -> Result<Value> { Ok(DB::optional_document_row(row, field)?.map(php_array).unwrap_or(Value::Null)) };
            let metadata = document("metadata")?;
            let outcome_metadata = document("outcome_metadata")?;
            let attempts = attempts(&metadata, &outcome_metadata);
            let mut call = Map::new();
            for (name, value) in [
                ("service_call_id", text("id")?),
                ("caller_namespace", text("caller_namespace")?),
                ("caller_workflow_instance_id", text("caller_workflow_instance_id")?),
                ("caller_workflow_run_id", text("caller_workflow_run_id")?),
                ("target_namespace", text("target_namespace")?),
                ("endpoint_name", text("endpoint_name")?),
                ("service_name", text("service_name")?),
                ("operation_name", text("operation_name")?),
                ("operation_mode", text("operation_mode")?),
                ("status", text("status")?),
                ("outcome", text("outcome")?),
                ("outcome_category", text("outcome_category")?),
                ("outcome_reason", text("outcome_reason")?),
                ("outcome_message", text("outcome_message")?),
                ("service_error_type", metadata_string(&outcome_metadata, "service_error_type")),
                ("caller_observed_error_type", metadata_string(&outcome_metadata, "caller_observed_error_type")),
                ("typed_error_message", metadata_string(&outcome_metadata, "typed_error_message")),
                // PHP lists the metadata before the three fields read from it.
                ("outcome_metadata", outcome_metadata),
                ("resolved_binding_kind", text("resolved_binding_kind")?),
                ("resolved_target_reference", text("resolved_target_reference")?),
                ("linked_workflow_instance_id", text("linked_workflow_instance_id")?),
                ("linked_workflow_run_id", text("linked_workflow_run_id")?),
                ("linked_workflow_update_id", text("linked_workflow_update_id")?),
                ("idempotency_key", text("idempotency_key")?),
                ("retry_policy", document("retry_policy")?),
                ("retry_attempt_count", json!(attempts.len())),
                ("service_call_attempts", Value::Array(attempts)),
                ("failure_message", text("failure_message")?),
                ("caller_principal_subject", text("caller_principal_subject")?),
                ("caller_principal_method", text("caller_principal_method")?),
                ("accepted_at", iso(row, "accepted_at")?),
                ("started_at", iso(row, "started_at")?),
                ("completed_at", iso(row, "completed_at")?),
                ("failed_at", iso(row, "failed_at")?),
                ("cancelled_at", iso(row, "cancelled_at")?),
            ] {
                call.insert(name.into(), value);
            }
            calls.push(Value::Object(call));
        }
        Ok(calls)
    }
}

/// The response body, in PHP's field order.
pub(crate) fn listing(workflow_id: &str, run_id: Option<&str>, namespace: &str, limit: i64, calls: Vec<Value>) -> Value {
    let mut body = Map::new();
    body.insert("workflow_id".into(), json!(workflow_id));
    if let Some(run_id) = run_id {
        body.insert("workflow_run_id".into(), json!(run_id));
    }
    body.insert("caller_namespace".into(), json!(namespace));
    body.insert("count".into(), json!(calls.len()));
    body.insert("limit".into(), json!(limit));
    body.insert("nexus_operations".into(), Value::Array(calls));
    Value::Object(body)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn limits_cast_like_php() {
        for (requested, limit) in [(None, 200), (Some(""), 200), (Some("  "), 200), (Some("abc"), 200), (Some("5"), 5),
            (Some(" 7 "), 7), (Some("0"), 1), (Some("-3"), 1), (Some("2.7"), 2), (Some("1e2"), 100), (Some("1000"), 200),
            (Some("99999999999999999999"), 200), (Some("-0.5"), 1), (Some("0x1A"), 200)] {
            assert_eq!(list_limit(requested), limit, "{requested:?}");
        }
    }

    #[test]
    fn decoded_columns_reencode_like_php() {
        assert_eq!(php_array(json!({})), json!([]));
        assert_eq!(php_array(json!({"a": {}, "b": [{}]})), json!({"a": [], "b": [[]]}));
        assert_eq!(metadata_string(&json!({"service_error_type": "  x "}), "service_error_type"), json!("x"));
        assert_eq!(metadata_string(&json!({"service_error_type": " "}), "service_error_type"), Value::Null);
        assert_eq!(attempts(&json!({"service_call_attempts": [{"n": 1}, 2]}), &json!({})), vec![json!({"n": 1})]);
        assert_eq!(attempts(&json!({}), &json!({"service_call_attempts": [{"n": 2}]})), vec![json!({"n": 2})]);
    }
}
