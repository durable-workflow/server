//! Published PHP's checks on a control-plane `input` before it resolves it:
//! the runtime external payload transport, then `AvroPayloadEnvelopeResolver`.
//! Its refusals are Laravel validation failures on `input.codec` and
//! `input.blob`.
use super::laravel_validation::failure_messages;
use super::{Result, RuntimeError};
use serde_json::Value;

/// `PayloadCodecContract::canonicalize`: null, empty or `avro` names the
/// codec; anything else is refused with PHP's message.
pub(super) fn codec_refusal(codec: &Value) -> Option<String> {
    let rendered = match codec {
        Value::Null => return None,
        Value::String(codec) if codec.is_empty() || codec == "avro" => return None,
        Value::String(codec) => codec.as_str(),
        Value::Bool(_) => "bool",
        Value::Number(number) if number.is_i64() || number.is_u64() => "int",
        Value::Number(_) => "float",
        Value::Array(_) | Value::Object(_) => "array",
    };
    Some(format!(
        "unsupported_payload_codec: workflow payload codec \"{rendered}\" is not supported by Durable Workflow 2.0; use codec=\"avro\" with the fixed Avro Value schema and single-object framing. JSON remains the HTTP document transport, not a workflow payload codec."
    ))
}

fn invalid(field: &str, message: String) -> RuntimeError {
    failure_messages(vec![(field.to_owned(), vec![message])]).expect("one message")
}

/// `RuntimeExternalPayloadTransport` refuses a provider-specific reference
/// before the request reaches validation.
pub(super) fn transport(body: &Value) -> Result<()> {
    if body["input"].get("external_storage").is_some() {
        return Err(super::external_transport::provider_reference());
    }
    Ok(())
}

/// `assertEnvelope`, then `resolveExplicitEnvelope`, on the trimmed `input`.
/// Any object with a codec names an allowed codec; an exact envelope also
/// has a non-empty codec and a string blob.
pub(super) fn check(body: &Value) -> Result<()> {
    let Some(fields) = body["input"].as_object() else {
        return Ok(());
    };
    if let Some(message) = fields.get("codec").and_then(codec_refusal) {
        return Err(invalid("input.codec", message));
    }
    let mut keys: Vec<&str> = fields.keys().map(String::as_str).collect();
    keys.sort_unstable();
    if keys != ["blob", "codec"] {
        return Ok(());
    }
    if !fields["codec"].as_str().is_some_and(|codec| !codec.is_empty()) {
        return Err(invalid("input.codec", "The payload envelope codec must be a non-empty string.".into()));
    }
    if !fields["blob"].is_string() {
        return Err(invalid("input.blob", "The payload envelope blob must be a string.".into()));
    }
    Ok(())
}

/// `resolveToArray`'s refusal of a blob the codec cannot decode.
pub(super) fn undecodable(blob: &str, error: &crate::codec::CodecError) -> RuntimeError {
    invalid(
        "input.blob",
        format!(
            "The input envelope blob could not be decoded with codec \"avro\": {}",
            crate::codec::ingress_diagnosis(blob, error)
        ),
    )
}

/// `resolveToArray`'s refusal of a blob that is not a list or a map.
pub(super) fn not_a_list() -> RuntimeError {
    invalid("input.blob", "The input envelope blob must decode to an array.".into())
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn errors(result: Result<()>) -> Value {
        match result {
            Err(RuntimeError::Protocol { response, .. }) => response["errors"].clone(),
            other => panic!("{:?}", other.err()),
        }
    }

    #[test]
    fn input_checks_follow_php() {
        let codec = |value: Value| errors(check(&json!({"input": value})));
        assert!(codec(json!({"codec":"json"}))["input.codec"][0].as_str().unwrap().contains("codec \"json\" is not supported"));
        assert!(codec(json!({"codec":1,"blob":"x"}))["input.codec"][0].as_str().unwrap().contains("codec \"int\" is not supported"));
        assert_eq!(codec(json!({"codec":null,"blob":"x"}))["input.codec"], json!(["The payload envelope codec must be a non-empty string."]));
        assert_eq!(codec(json!({"codec":"avro","blob":null}))["input.blob"], json!(["The payload envelope blob must be a string."]));
        for accepted in [json!([1]), json!({"codec":"avro"}), json!({"codec":null}), json!({"codec":"avro","blob":"x"}), json!(null)] {
            assert!(check(&json!({"input": accepted})).is_ok(), "{accepted}");
        }
        assert!(transport(&json!({"input":{"codec":"avro","external_storage":"x"}})).is_err());
        assert!(transport(&json!({"input":[{"external_storage":1}]})).is_ok());
    }
}
