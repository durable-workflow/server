//! JSON display projection matching frozen PHP; Avro envelopes remain authoritative.
use super::{Result, refuse};
use crate::codec::{Value as Payload, ValueCodec};
use axum::http::StatusCode;
use base64::{Engine, engine::general_purpose::STANDARD};
use serde_json::{Value, json};
use std::sync::Arc;
use tokio::sync::Semaphore;

fn project(value: Payload) -> Value {
    match value {
        Payload::Null => Value::Null,
        Payload::Boolean(value) => json!(value),
        Payload::Long(value) => json!(value),
        Payload::Double(value) => json!(value),
        Payload::String(value) => json!(value),
        Payload::Bytes(value) => json!({"$type":"bytes","base64":STANDARD.encode(value)}),
        Payload::Array(values) => Value::Array(values.into_iter().map(project).collect()),
        Payload::Map(values) => {
            // PHP converts canonical signed integer string keys to integer
            // keys. Its Avro adapter keeps those maps, and empty maps, explicit.
            let explicit = values.is_empty()
                || values.keys().any(|key| {
                    key.parse::<i64>()
                        .is_ok_and(|number| number.to_string() == *key)
                });
            if explicit {
                json!({"$type":"map","entries":values.into_iter().map(|(key,value)| json!({"key":key,"value":project(value)})).collect::<Vec<_>>()})
            } else {
                Value::Object(
                    values
                        .into_iter()
                        .map(|(key, value)| (key, project(value)))
                        .collect(),
                )
            }
        }
    }
}

pub(super) async fn describe(semaphore: Arc<Semaphore>, mut description: Value) -> Result<Value> {
    let permit = semaphore.acquire_owned().await.map_err(|_| {
        refuse(
            StatusCode::SERVICE_UNAVAILABLE,
            "description_codec_unavailable",
        )
    })?;
    tokio::task::spawn_blocking(move || {
        let _permit = permit;
        let codec = ValueCodec::new().map_err(|_| {
            refuse(
                StatusCode::SERVICE_UNAVAILABLE,
                "description_codec_unavailable",
            )
        })?;
        for (field, envelope) in [("input", "input_envelope"), ("output", "output_envelope")] {
            let Some(blob) = description[envelope]["blob"].as_str() else {
                continue;
            };
            if blob.len() > 2 * 1024 * 1024 {
                return Err(refuse(
                    StatusCode::SERVICE_UNAVAILABLE,
                    "stored_payload_exceeds_preview_budget",
                ));
            }
            let value = codec
                .decode(blob)
                .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "invalid_stored_payload"))?;
            description[field] = project(value);
        }
        Ok(description)
    })
    .await
    .map_err(|_| {
        refuse(
            StatusCode::SERVICE_UNAVAILABLE,
            "description_codec_unavailable",
        )
    })?
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::collections::BTreeMap;

    #[test]
    fn json_preview_preserves_int64_binary_and_explicit_php_maps() {
        let value = Payload::Map(BTreeMap::from([
            ("count".into(), Payload::Long(9007199254740993)),
            ("bytes".into(), Payload::Bytes(vec![0, 255])),
            ("empty".into(), Payload::Map(BTreeMap::new())),
            (
                "numeric".into(),
                Payload::Map(BTreeMap::from([("0".into(), Payload::String("λ".into()))])),
            ),
        ]));
        assert_eq!(
            project(value),
            json!({"count":9007199254740993i64,"bytes":{"$type":"bytes","base64":"AP8="},
            "empty":{"$type":"map","entries":[]},"numeric":{"$type":"map","entries":[{"key":"0","value":"λ"}]}})
        );
        for key in ["01", "+1", "-0", "9223372036854775808"] {
            let projected = project(Payload::Map(BTreeMap::from([(
                key.to_owned(),
                Payload::Null,
            )])));
            assert_eq!(
                projected,
                json!({key:null}),
                "PHP retains this string key: {key}"
            );
        }
    }
}
