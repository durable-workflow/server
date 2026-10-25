//! The payload checks published PHP 2.11.1 runs on a workflow task
//! completion before it applies any command
//! (`WorkerController::resolveWorkflowTaskCommandPayloadReferences`, then the
//! command normalizer): every payload a command carries must be one complete
//! Avro value, sent as a string or as a `{codec, blob}` envelope with the Avro
//! codec. A refusal is a Laravel validation failure naming the field.
use super::input_envelope::codec_refusal;
use super::laravel_validation::failure_messages;
use super::RuntimeError;
use serde_json::Value;

/// `WorkflowCommandNormalizer::PAYLOAD_ENVELOPE_FIELDS`.
const FIELDS: &[(&str, &[&str])] = &[
    ("complete_workflow", &["result"]),
    ("schedule_activity", &["arguments"]),
    ("start_child_workflow", &["arguments"]),
    ("continue_as_new", &["arguments"]),
    ("complete_update", &["result"]),
    ("record_side_effect", &["result"]),
    ("record_local_activity", &["arguments", "result"]),
    ("start_service_operation", &["request_payload"]),
    ("upsert_memo", &["entries"]),
];

/// Fields the normalizer accepts only as a string or an envelope.
const STRICT: &[(&str, &str)] = &[("complete_workflow", "result"), ("schedule_activity", "arguments")];

/// `AvroPayloadEnvelopeResolver::assertSerializedPayload`.
pub(super) const UNDECODABLE: &str =
    "The payload must contain one complete Avro Value encoded with the official codec. JSON is only the HTTP document transport.";

pub(super) fn invalid(field: &str, message: &str) -> RuntimeError {
    failure_messages(vec![(field.to_owned(), vec![message.to_owned()])]).expect("one message")
}

/// One check, in PHP's order: a blob to decode, or a refusal already known.
pub(super) enum Check {
    Decode { field: String, blob: String },
    Refuse(RuntimeError),
}

/// The checks for each command's payload fields, in command and field order.
/// A refusal ends the list, since PHP stops at the first invalid field.
pub(super) fn checks(body: &Value) -> Vec<Check> {
    let mut checks = Vec::new();
    let Some(commands) = body["commands"].as_array() else {
        return checks;
    };
    for (index, command) in commands.iter().enumerate() {
        // `PayloadCodecContract::canonicalize` on any command's codec.
        if let Some(codec) = command.get("payload_codec")
            && let Some(message) = codec_refusal(codec)
        {
            checks.push(Check::Refuse(invalid(&format!("commands.{index}.payload_codec"), &message)));
            return checks;
        }
        let Some(kind) = command["type"].as_str() else {
            continue;
        };
        let Some((_, fields)) = FIELDS.iter().find(|(name, _)| *name == kind) else {
            continue;
        };
        for field in *fields {
            let path = format!("commands.{index}.{field}");
            let strict = STRICT.contains(&(kind, *field));
            let not_payload = || {
                Check::Refuse(invalid(
                    &path,
                    &format!("Workflow task command field [{field}] must be a string or a payload envelope when provided."),
                ))
            };
            match &command[*field] {
                Value::Null => {}
                Value::String(blob) => checks.push(Check::Decode { field: path.clone(), blob: blob.clone() }),
                Value::Object(envelope) if envelope.contains_key("codec") => {
                    if let Some(message) = codec_refusal(&envelope["codec"]) {
                        checks.push(Check::Refuse(invalid(&format!("{path}.codec"), &message)));
                        return checks;
                    }
                    // External references are resolved, and refused, on their own path.
                    if envelope.contains_key("external_storage") || envelope.contains_key("external_payload") {
                        continue;
                    }
                    let mut keys: Vec<&str> = envelope.keys().map(String::as_str).collect();
                    keys.sort_unstable();
                    if keys != ["blob", "codec"] {
                        if strict {
                            checks.push(not_payload());
                            return checks;
                        }
                        continue;
                    }
                    if !envelope["codec"].as_str().is_some_and(|codec| !codec.is_empty()) {
                        checks.push(Check::Refuse(invalid(&format!("{path}.codec"), "The payload envelope codec must be a non-empty string.")));
                        return checks;
                    }
                    let Some(blob) = envelope["blob"].as_str() else {
                        checks.push(Check::Refuse(invalid(&format!("{path}.blob"), "The payload envelope blob must be a string.")));
                        return checks;
                    };
                    checks.push(Check::Decode { field: format!("{path}.blob"), blob: blob.to_owned() });
                }
                _ if strict => {
                    checks.push(not_payload());
                    return checks;
                }
                _ => {}
            }
        }
    }
    checks
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn summary(body: Value) -> Vec<String> {
        checks(&body)
            .into_iter()
            .map(|check| match check {
                Check::Decode { field, blob } => format!("decode {field} {blob}"),
                Check::Refuse(RuntimeError::Protocol { response, .. }) => format!("refuse {}", response["errors"]),
                Check::Refuse(other) => panic!("{other:?}"),
            })
            .collect()
    }

    #[test]
    fn checks_follow_php_s_order_and_messages() {
        let commands = |commands: Value| summary(json!({"commands": commands}));
        assert_eq!(commands(json!([{"type":"complete_workflow","result":{"codec":"avro","blob":"A"}}])), ["decode commands.0.result.blob A"]);
        assert_eq!(commands(json!([{"type":"record_side_effect","result":"B"}])), ["decode commands.0.result B"]);
        assert_eq!(commands(json!([{"type":"complete_workflow","result":[1]}])),
            [r#"refuse {"commands.0.result":["Workflow task command field [result] must be a string or a payload envelope when provided."]}"#]);
        assert_eq!(commands(json!([{"type":"complete_workflow","result":{"codec":"avro"}}])).len(), 1);
        assert_eq!(commands(json!([{"type":"complete_workflow","result":{"codec":"","blob":"A"}}])),
            [r#"refuse {"commands.0.result.codec":["The payload envelope codec must be a non-empty string."]}"#]);
        assert_eq!(commands(json!([{"type":"complete_workflow","result":{"codec":"avro","blob":5}}])),
            [r#"refuse {"commands.0.result.blob":["The payload envelope blob must be a string."]}"#]);
        assert!(commands(json!([{"type":"complete_workflow","result":{"codec":"json","blob":"[]"}}]))[0].contains("commands.0.result.codec"));
        // Plain continue-as-new arguments and memo entries are not payload strings.
        assert!(commands(json!([{"type":"continue_as_new","arguments":[1]},{"type":"upsert_memo","entries":{"k":1}}])).is_empty());
        // A later command's blob is checked after the earlier ones, in order.
        assert_eq!(commands(json!([{"type":"schedule_activity","arguments":{"codec":"avro","blob":"A"}},
            {"type":"schedule_activity","arguments":5}])).len(), 2);
        assert!(commands(json!([{"type":"complete_workflow","result":{"codec":"avro","external_payload":{}}}])).is_empty());
    }
}
