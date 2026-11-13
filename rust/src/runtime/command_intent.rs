//! Canonical intent digest for keyed signal and update admissions (Server
//! #934), the native port of Core's `Workflow\V2\Support\CommandIntent`.
//!
//! Two admissions that reuse one caller key are the same command only when
//! their command type, name, typed arguments and explicit run target match.
//! Map entries are ordered by key bytes, integers and doubles keep their
//! types, strings and bytes stay distinct, and Unicode is never normalized.
//! A map holding only `__dw_intent` with a string is a protected value whose
//! caller-supplied plaintext intent replaces the ciphertext. The shared
//! vectors are `tests/Fixtures/CommandIntent/command-intent-v1-vectors.json`.
use crate::codec::Value;
use sha2::{Digest, Sha256};

pub(super) const VERSION: &str = "v1";
const PROTECTED_INTENT_KEY: &str = "__dw_intent";

/// `CommandIntent::digest`: `v1:` and the hex SHA-256 of the canonical
/// encoding of the command's type, name, arguments and target.
pub(super) fn digest(command_type: &str, name: &str, arguments: &[Value], run: Option<&str>) -> String {
    let mut out = Vec::new();
    // The top-level map, its keys already in byte order.
    out.extend_from_slice(b"m4{");
    key(&mut out, "arguments");
    list(&mut out, arguments);
    key(&mut out, "name");
    text(&mut out, b's', name.as_bytes());
    key(&mut out, "target");
    out.extend_from_slice(b"m2{");
    key(&mut out, "run");
    match run {
        Some(run) => text(&mut out, b's', run.as_bytes()),
        None => out.push(b'n'),
    }
    key(&mut out, "scope");
    text(&mut out, b's', if run.is_some() { b"run" } else { b"instance" });
    out.push(b'}');
    key(&mut out, "type");
    text(&mut out, b's', command_type.as_bytes());
    out.push(b'}');
    format!("{VERSION}:{}", hex(&Sha256::digest(&out)))
}

/// `CommandIntent::canonical` for a protocol value.
pub(super) fn canonical(value: &Value, out: &mut Vec<u8>) {
    match value {
        Value::Null => out.push(b'n'),
        Value::Boolean(value) => out.extend_from_slice(if *value { b"b1" } else { b"b0" }),
        Value::Long(value) => out.extend_from_slice(format!("i{value}").as_bytes()),
        Value::Double(value) => {
            out.push(b'd');
            if value.is_nan() {
                out.extend_from_slice(b"nan");
            } else if value.is_infinite() {
                out.extend_from_slice(if *value > 0.0 { b"inf" } else { b"-inf" });
            } else {
                // PHP's pack('E'): big-endian IEEE 754 bytes.
                out.extend_from_slice(format!("0x{}", hex(&value.to_be_bytes())).as_bytes());
            }
        }
        Value::String(value) => text(out, b's', value.as_bytes()),
        Value::Bytes(value) => text(out, b'x', value),
        Value::Array(items) => list(out, items),
        Value::Map(entries) => {
            if entries.len() == 1
                && let Some(Value::String(intent)) = entries.get(PROTECTED_INTENT_KEY)
            {
                text(out, b'p', intent.as_bytes());
                return;
            }
            // A BTreeMap of Strings iterates in byte order, as strcmp sorts.
            out.extend_from_slice(format!("m{}{{", entries.len()).as_bytes());
            for (name, item) in entries {
                key(out, name);
                canonical(item, out);
            }
            out.push(b'}');
        }
    }
}

/// Core's `CommandRequestIdentity::keyToken`: the caller's key as stored in
/// `workflow_commands.request_id`, `<type>-v1:` and its hex SHA-256. A
/// fixed-width token keeps every valid key inside the column and makes the
/// unique `(instance, type, request_id)` index the arbiter of one admission.
pub(super) fn key_token(command_type: &str, key: &str) -> String {
    format!("{command_type}-v1:{}", hex(&Sha256::digest(key.as_bytes())))
}

/// The caller's command key: a non-empty body `request_id`. A bridge
/// command keeps its own identity and is not keyed here.
pub(super) fn caller_key(body: &serde_json::Value) -> Option<&str> {
    body.get("request_id").and_then(serde_json::Value::as_str).filter(|key| !key.is_empty())
}

/// How a retry's intent compares with the receipt its key retains.
#[derive(Debug, PartialEq, Eq)]
pub(super) enum Intent {
    Same,
    Different,
    /// The receipt predates intent digests; nothing can prove equality.
    Unverified,
}

pub(super) fn compare(retained: Option<&str>, candidate: &str) -> Intent {
    match retained {
        None => Intent::Unverified,
        Some(retained) if retained == candidate => Intent::Same,
        Some(_) => Intent::Different,
    }
}

/// The refusal for a key reused with a different command intent. Nothing is
/// written; the key keeps its original receipt.
pub(super) fn conflict(workflow_id: &str, command_type: &str, retained_command_id: &str, retained_outcome: Option<&str>,
    parts: Vec<&'static str>) -> super::RuntimeError {
    let message = format!("The request_id was already used for a different {command_type} command on workflow [{workflow_id}]. Use a new request_id for a different command.");
    super::RuntimeError::Protocol {
        status: axum::http::StatusCode::CONFLICT,
        response: serde_json::json!({"workflow_id":workflow_id,"reason":"request_id_conflict","outcome":"rejected_request_id_conflict",
            "command_status":"rejected","rejection_reason":"request_id_conflict","message":message,"intent_verified":true,
            "retained":true,"retained_command_id":retained_command_id,"retained_outcome":retained_outcome,"conflict":parts}),
    }
}

fn list(out: &mut Vec<u8>, items: &[Value]) {
    out.extend_from_slice(format!("l{}[", items.len()).as_bytes());
    for item in items {
        canonical(item, out);
    }
    out.push(b']');
}

fn key(out: &mut Vec<u8>, name: &str) {
    text(out, b's', name.as_bytes());
}

fn text(out: &mut Vec<u8>, tag: u8, bytes: &[u8]) {
    out.push(tag);
    out.extend_from_slice(format!("{}:", bytes.len()).as_bytes());
    out.extend_from_slice(bytes);
}

fn hex(bytes: &[u8]) -> String {
    bytes.iter().map(|byte| format!("{byte:02x}")).collect()
}

#[cfg(test)]
mod tests {
    use super::*;
    use base64::Engine;
    use serde_json::Value as Json;
    use std::collections::{BTreeMap, HashMap};

    fn vectors() -> Json {
        serde_json::from_str(include_str!(concat!(env!("CARGO_MANIFEST_DIR"),
            "/../tests/Fixtures/CommandIntent/command-intent-v1-vectors.json"))).unwrap()
    }

    fn value(case: &Json) -> Value {
        match case["type"].as_str().unwrap() {
            "null" => Value::Null,
            "bool" => Value::Boolean(case["value"].as_bool().unwrap()),
            "int" => Value::Long(case["value"].as_str().unwrap().parse().unwrap()),
            "double" => Value::Double(f64::from_bits(u64::from_str_radix(case["bits"].as_str().unwrap(), 16).unwrap())),
            "string" => Value::String(case["value"].as_str().unwrap().to_owned()),
            "bytes" => Value::Bytes(base64::engine::general_purpose::STANDARD.decode(case["base64"].as_str().unwrap()).unwrap()),
            "array" => Value::Array(case["items"].as_array().unwrap().iter().map(value).collect()),
            "map" => Value::Map(case["entries"].as_array().unwrap().iter()
                .map(|entry| (entry[0].as_str().unwrap().to_owned(), value(&entry[1]))).collect::<BTreeMap<_, _>>()),
            other => panic!("unknown vector type {other}"),
        }
    }

    #[test]
    fn values_encode_as_the_shared_vectors() {
        let vectors = vectors();
        assert_eq!(vectors["schema"], "durable-workflow.command-intent/v1");
        let cases = vectors["value_cases"].as_array().unwrap();
        assert_eq!(cases.len(), 38);
        for case in cases {
            let mut out = Vec::new();
            canonical(&value(&case["value"]), &mut out);
            let expected = base64::engine::general_purpose::STANDARD.decode(case["canonical_base64"].as_str().unwrap()).unwrap();
            assert_eq!(out, expected, "{}", case["id"]);
        }
    }

    #[test]
    fn commands_digest_as_the_shared_vectors() {
        let vectors = vectors();
        let mut digests = HashMap::new();
        for case in vectors["command_cases"].as_array().unwrap() {
            let arguments: Vec<Value> = case["arguments"].as_array().unwrap().iter().map(value).collect();
            let run = (case["target_scope"] == "run").then(|| case["requested_run_id"].as_str().unwrap());
            let digest = digest(case["command_type"].as_str().unwrap(), case["name"].as_str().unwrap(), &arguments, run);
            assert_eq!(digest, case["digest"].as_str().unwrap(), "{}", case["id"]);
            digests.insert(case["id"].as_str().unwrap().to_owned(), digest);
        }
        assert_eq!(digests.len(), 19);
        for relation in vectors["digest_relations"].as_array().unwrap() {
            let equal = digests[relation["left"].as_str().unwrap()] == digests[relation["right"].as_str().unwrap()];
            assert_eq!(equal, relation["equal"].as_bool().unwrap(), "{relation}");
        }
    }

    /// Native's request root is an argument list, or a non-empty outer map
    /// whose values in key order are the arguments (`prepare_control_arguments`).
    #[test]
    fn native_request_roots_map_to_the_vector_arguments() {
        for case in vectors()["native_argument_cases"].as_array().unwrap() {
            let projected = match value(&case["request_root"]) {
                Value::Array(items) => Some(items),
                Value::Map(entries) if !entries.is_empty() => Some(entries.into_values().collect()),
                _ => None,
            };
            match case["outcome"].as_str().unwrap() {
                "argument_list" => {
                    let expected: Vec<Value> = case["arguments"].as_array().unwrap().iter().map(value).collect();
                    assert_eq!(projected, Some(expected), "{}", case["id"]);
                }
                _ => assert!(projected.is_none(), "{}", case["id"]),
            }
        }
    }
}
