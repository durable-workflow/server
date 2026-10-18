//! Opaque runtime reference values. Transport, backing storage and retention
//! must qualify separately before this development runtime advertises support.

use serde::Serialize;
use serde_json::Value;
mod local;
mod snapshot;
pub use local::LocalStorage;
pub use snapshot::{AsyncSnapshot, Snapshot, SnapshotError, capture};

pub const REFERENCE_SCHEMA: &str = "durable-workflow.v2.runtime-external-payload-reference.v1";

#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
pub struct Reference {
    pub schema: &'static str,
    pub reference_id: String,
    pub codec: &'static str,
    pub size_bytes: i64,
    pub sha256: String,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq, thiserror::Error)]
pub enum InvalidReference {
    #[error(
        "Runtime external payload references must contain exactly schema, reference_id, codec, size_bytes, and sha256."
    )]
    Fields,
    #[error("Unsupported runtime external payload reference schema.")]
    Schema,
    #[error("Runtime external payload reference_id is malformed.")]
    Identity,
    #[error("unsupported_payload_codec")]
    Codec,
    #[error("Runtime external payload size_bytes must be a non-negative integer.")]
    Size,
    #[error("Runtime external payload sha256 must be a hexadecimal SHA-256 digest.")]
    Digest,
}

impl Reference {
    pub fn validate(value: &Value) -> Result<Self, InvalidReference> {
        let fields = ["schema", "reference_id", "codec", "size_bytes", "sha256"];
        let object = value
            .as_object()
            .filter(|object| {
                object.len() == fields.len()
                    && fields.iter().all(|field| object.contains_key(*field))
            })
            .ok_or(InvalidReference::Fields)?;
        if object["schema"] != REFERENCE_SCHEMA {
            return Err(InvalidReference::Schema);
        }
        let reference_id = object["reference_id"]
            .as_str()
            .filter(|identity| {
                identity.strip_prefix("ep_").is_some_and(|suffix| {
                    suffix.len() == 26
                        && suffix
                            .bytes()
                            .all(|byte| b"0123456789ABCDEFGHJKMNPQRSTVWXYZ".contains(&byte))
                })
            })
            .ok_or(InvalidReference::Identity)?;
        // Published PHP canonicalizes a present null/empty codec to Avro.
        // Missing keys still refuse; no other codec alias is accepted.
        if !object["codec"].is_null() && object["codec"] != "" && object["codec"] != "avro" {
            return Err(InvalidReference::Codec);
        }
        let size_bytes = object["size_bytes"]
            .as_i64()
            .filter(|size| *size >= 0)
            .ok_or(InvalidReference::Size)?;
        let sha256 = object["sha256"]
            .as_str()
            .filter(|digest| {
                digest.len() == 64 && digest.bytes().all(|byte| byte.is_ascii_hexdigit())
            })
            .ok_or(InvalidReference::Digest)?
            .to_ascii_lowercase();
        Ok(Self {
            schema: REFERENCE_SCHEMA,
            reference_id: reference_id.to_owned(),
            codec: "avro",
            size_bytes,
            sha256,
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn published_reference_boundary_preserves_identity_and_integer_size() {
        let fixture: Value = serde_json::from_str(include_str!(
            "../tests/fixtures/external-payload-reference-v1.json"
        ))
        .unwrap();
        for case in fixture["cases"].as_array().unwrap() {
            let observed = Reference::validate(&case["input"]);
            match case.get("expected") {
                Some(expected) => assert_eq!(
                    serde_json::to_value(observed.unwrap()).unwrap(),
                    *expected,
                    "{}",
                    case["id"]
                ),
                None => assert!(observed.is_err(), "{} unexpectedly accepted", case["id"]),
            }
        }
    }
}
