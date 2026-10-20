//! Presents stored history payloads the way published PHP Server does
//! (`ExternalPayloadEnvelopeService::historyPayload` and
//! `HistoryController::eventPrincipal`). PHP stores encoded values as bare
//! strings beside their codec and wraps them while reading; native stores
//! the envelope itself. With the same rules, history written by PHP before a
//! takeover reads as PHP returned it, and native's own envelopes pass through
//! unchanged.
use serde_json::{Map, Value, json};

/// Kept from a PHP activity failure; PHP redacts its runtime internals.
const NEUTRAL_EXCEPTION_KEYS: [&str; 12] = [
    "type",
    "message",
    "code",
    "kind",
    "classification",
    "retryable",
    "non_retryable",
    "timeout_type",
    "cancelled",
    "malformed_output",
    "details",
    "details_payload_codec",
];
const DIAGNOSTIC_KEYS: [&str; 2] = ["diagnostics", "runtime_diagnostics"];
/// PHP's stored provider reference, which only PHP's external payload store
/// can resolve. Takeover refuses sources that hold any, so it is left as is.
const PHP_EXTERNAL_REFERENCE: &str = "dw-external-payload:v1:";

pub(super) fn payload(mut payload: Value, event_type: &str) -> Value {
    let Value::Object(fields) = &mut payload else {
        return payload;
    };
    let codec = codec(fields, "payload_codec", Some("avro"));
    for field in ["arguments", "result", "output"] {
        wrap(fields, field, codec.as_deref());
    }
    if let Some(Value::Object(command)) = fields.get_mut("command") {
        let codec = self::codec(command, "payload_codec", codec.as_deref());
        wrap(command, "payload", codec.as_deref());
    }
    if let Some(Value::Object(activity)) = fields.get_mut("activity") {
        let codec = self::codec(activity, "payload_codec", codec.as_deref());
        for field in ["arguments", "result"] {
            wrap(activity, field, codec.as_deref());
        }
    }
    let activity_failure = event_type == "ActivityFailed";
    let opaque_legacy = activity_failure
        && fields
            .get("migration_projection")
            .and_then(|projection| projection.get("source_record"))
            == Some(&json!("workflow_exception"));
    if let Some(Value::Object(exception)) = fields.get_mut("exception") {
        let codec = self::codec(exception, "details_payload_codec", codec.as_deref());
        wrap(exception, "details", codec.as_deref());
        if activity_failure {
            let mut neutral = Map::new();
            for key in NEUTRAL_EXCEPTION_KEYS {
                if let Some(value) = exception.get(key) {
                    neutral.insert(key.to_owned(), value.clone());
                }
            }
            for key in DIAGNOSTIC_KEYS {
                if let Some(value @ (Value::Object(_) | Value::Array(_))) = exception.get(key) {
                    neutral.insert(key.to_owned(), value.clone());
                }
            }
            if opaque_legacy
                && let Some(value) = exception.get("value").filter(|value| opaque_descriptor(value))
            {
                neutral.insert("value".to_owned(), value.clone());
            }
            *exception = neutral;
        }
    }
    payload
}

/// The actor PHP surfaces at the top of a public history event: the
/// principal recorded on the event's command, or null.
pub(super) fn principal(payload: &Value) -> Value {
    let Some(command) = payload.get("command").filter(|command| command.is_object()) else {
        return Value::Null;
    };
    let mut principal = Map::new();
    for (key, field) in [
        ("type", "principal_type"),
        ("id", "principal_id"),
        ("label", "principal_label"),
    ] {
        if let Some(value) = command[field].as_str().filter(|value| !value.is_empty()) {
            principal.insert(key.to_owned(), json!(value));
        }
    }
    if principal.is_empty() {
        Value::Null
    } else {
        Value::Object(principal)
    }
}

/// The section's own codec, or the enclosing one. `None` means a codec PHP
/// rejects, so nothing in that section is wrapped.
fn codec(section: &Map<String, Value>, field: &str, fallback: Option<&str>) -> Option<String> {
    match section.get(field).and_then(Value::as_str).filter(|codec| !codec.is_empty()) {
        Some("avro") => Some("avro".to_owned()),
        Some(_) => None,
        None => fallback.map(str::to_owned),
    }
}

fn wrap(section: &mut Map<String, Value>, field: &str, codec: Option<&str>) {
    let Some(codec) = codec else {
        return;
    };
    if let Some(Value::String(blob)) = section.get(field)
        && !blob.starts_with(PHP_EXTERNAL_REFERENCE)
    {
        let envelope = json!({"codec": codec, "blob": blob});
        section.insert(field.to_owned(), envelope);
    }
}

fn opaque_descriptor(value: &Value) -> bool {
    value["available"].is_boolean()
        && value["encoding"] == "php-serialize"
        && value.get("value").is_some_and(|inner| inner.is_string() || inner.is_null())
        && value["portable"] == false
        && value["unsupported_reason"] == "legacy_php_serialization_not_portable"
        && value["remediation"]
            == "Treat the preserved value as opaque; export decoded JSON from the source application if portable payload access is required."
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn php_stored_strings_become_envelopes_and_native_envelopes_stay() {
        let stored = json!({"payload_codec":"avro","arguments":"AAA=","output":null,
            "command":{"payload_codec":"avro","payload":"BBB=","principal_type":"auth:token",
                "principal_id":"legacy-token","principal_label":"Admin"},
            "activity":{"arguments":"CCC=","result":{"codec":"avro","blob":"DDD="}}});
        let presented = payload(stored.clone(), "ActivityScheduled");
        assert_eq!(presented["arguments"], json!({"codec":"avro","blob":"AAA="}));
        assert_eq!(presented["output"], Value::Null);
        assert_eq!(presented["command"]["payload"], json!({"codec":"avro","blob":"BBB="}));
        assert_eq!(presented["activity"]["arguments"], json!({"codec":"avro","blob":"CCC="}));
        assert_eq!(presented["activity"]["result"], json!({"codec":"avro","blob":"DDD="}));
        assert_eq!(payload(presented.clone(), "ActivityScheduled"), presented);
        assert_eq!(
            principal(&stored),
            json!({"type":"auth:token","id":"legacy-token","label":"Admin"})
        );
        assert_eq!(principal(&json!({"command":{"type":"start"}})), Value::Null);
        assert_eq!(principal(&json!({})), Value::Null);
    }

    #[test]
    fn unsupported_codecs_and_php_references_are_left_unchanged() {
        let stored = json!({"payload_codec":"json","result":"plain",
            "command":{"payload":"dw-external-payload:v1:eyJ9"}});
        let presented = payload(stored.clone(), "ActivityCompleted");
        assert_eq!(presented["result"], "plain");
        assert_eq!(presented["command"]["payload"], "dw-external-payload:v1:eyJ9");
    }

    #[test]
    fn activity_failures_keep_only_neutral_fields() {
        let stored = json!({"exception":{"type":"RuntimeException","message":"boom","file":"/app/x.php",
            "line":3,"trace":["frame"],"details":"EEE=","diagnostics":{"hint":"retry"},"runtime_diagnostics":"text"}});
        let presented = payload(stored.clone(), "ActivityFailed");
        assert_eq!(
            presented["exception"],
            json!({"type":"RuntimeException","message":"boom","details":{"codec":"avro","blob":"EEE="},
                "diagnostics":{"hint":"retry"}})
        );
        // Other failures keep every field, as PHP presents them.
        assert_eq!(payload(stored, "WorkflowFailed")["exception"]["file"], "/app/x.php");
    }
}
