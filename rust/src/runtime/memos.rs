//! Initial memo storage and lossless reads. Authored upserts remain unqualified.
use super::{Result, refuse};
use crate::codec::{Value as Payload, ValueCodec};
use axum::http::StatusCode;
use base64::{Engine, engine::general_purpose::STANDARD};
use serde_json::{Value, json};
use std::{collections::BTreeMap, sync::Arc};
use tokio::sync::Semaphore;

pub(super) const MAX_ENTRIES: usize = 100;
const MAX_VALUE_BYTES: usize = 10_240;
const MAX_TOTAL_BYTES: usize = 65_536;
const INVALID: &str = "workflow_memo_validation_failed";

pub(super) struct StoredMemo {
    pub(super) envelope: Value,
    pub(super) projection: Value,
}
pub(super) type PreparedMemo = BTreeMap<String, StoredMemo>;

fn invalid() -> super::RuntimeError {
    refuse(StatusCode::UNPROCESSABLE_ENTITY, INVALID)
}

fn valid_key(key: &str) -> bool {
    let numeric = key.strip_prefix('-').unwrap_or(key);
    !key.is_empty()
        && key.len() <= 64
        && key
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || b"_.:-".contains(&byte))
        && !(!numeric.is_empty() && numeric.bytes().all(|byte| byte.is_ascii_digit()))
}

// Initial HTTP memos are JSON-like values. A JSON object resembling an Avro
// envelope or byte projection remains a literal map, as in StartOptions.
fn from_json(value: Value) -> Result<Payload> {
    Ok(match value {
        Value::Null => Payload::Null,
        Value::Bool(value) => Payload::Boolean(value),
        Value::String(value) => Payload::String(value),
        Value::Number(value) if value.is_i64() => {
            Payload::Long(value.as_i64().ok_or_else(invalid)?)
        }
        Value::Number(value) if value.is_f64() => {
            Payload::Double(value.as_f64().ok_or_else(invalid)?)
        }
        Value::Number(_) => return Err(invalid()),
        Value::Array(values) => {
            Payload::Array(values.into_iter().map(from_json).collect::<Result<_>>()?)
        }
        Value::Object(values) => Payload::Map(
            values
                .into_iter()
                .map(|(key, value)| Ok((key, from_json(value)?)))
                .collect::<Result<_>>()?,
        ),
    })
}

fn project(value: Payload) -> Value {
    match value {
        Payload::Null => Value::Null,
        Payload::Boolean(value) => json!(value),
        Payload::Long(value) => json!(value),
        Payload::Double(value) => json!(value),
        Payload::String(value) => json!(value),
        Payload::Bytes(value) => json!({"$type":"bytes", "base64":STANDARD.encode(value)}),
        Payload::Array(values) => Value::Array(values.into_iter().map(project).collect()),
        Payload::Map(values) => {
            // PHP uses the public map adapter when arrays would erase an empty
            // map or coerce an exact string key to a signed 64-bit integer.
            let wrapped = values.is_empty()
                || values.keys().any(|key| {
                    key.parse::<i64>()
                        .is_ok_and(|value| value.to_string() == *key)
                });
            if wrapped {
                json!({"$type":"map", "entries":values.into_iter().map(|(key, value)|
                    json!({"key":key, "value":project(value)})).collect::<Vec<_>>()})
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

fn encode(values: BTreeMap<String, Payload>) -> Result<PreparedMemo> {
    if values.len() > MAX_ENTRIES || values.keys().any(|key| !valid_key(key)) {
        return Err(invalid());
    }
    let codec = ValueCodec::new().map_err(|_| invalid())?;
    let mut total = 0;
    let mut prepared = BTreeMap::new();
    for (key, value) in values {
        let blob = codec.encode(&value).map_err(|_| invalid())?;
        let size = STANDARD.decode(&blob).map_err(|_| invalid())?.len();
        total += size;
        if size > MAX_VALUE_BYTES || total > MAX_TOTAL_BYTES {
            return Err(invalid());
        }
        prepared.insert(
            key,
            StoredMemo {
                envelope: json!({"codec":"avro", "blob":blob}),
                projection: project(value),
            },
        );
    }
    Ok(prepared)
}

fn initial(value: Value) -> Result<PreparedMemo> {
    let entries = match value {
        Value::Null => return Ok(BTreeMap::new()),
        Value::Array(values) if values.is_empty() => return Ok(BTreeMap::new()),
        Value::Object(values) => values,
        _ => return Err(invalid()),
    };
    if entries.keys().any(|key| !valid_key(key)) {
        return Err(invalid());
    }
    encode(
        entries
            .into_iter()
            .filter(|(_, value)| !value.is_null())
            .map(|(key, value)| Ok((key, from_json(value)?)))
            .collect::<Result<_>>()?,
    )
}

pub(super) async fn prepare_initial(codec: Arc<Semaphore>, value: Value) -> Result<PreparedMemo> {
    if value.is_null() {
        return Ok(BTreeMap::new());
    }
    let permit = codec
        .acquire_owned()
        .await
        .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "memo_codec_unavailable"))?;
    tokio::task::spawn_blocking(move || {
        let _permit = permit;
        initial(value)
    })
    .await
    .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "memo_codec_unavailable"))?
}

// The Server host retains a predecessor-readable projection and an Avro
// envelope. Only a portable envelope at the SAME command sequence is authority.
// Otherwise the published compatibility cast treats value as literal JSON.
pub(super) async fn read_stored(
    codec: Arc<Semaphore>,
    rows: Vec<(String, Value, Option<Value>)>,
) -> Result<Value> {
    if rows.is_empty() {
        return Ok(json!({}));
    }
    let permit = codec
        .acquire_owned()
        .await
        .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "memo_codec_unavailable"))?;
    tokio::task::spawn_blocking(move || {
        let _permit = permit;
        let decode = || -> Result<Value> {
            let codec = ValueCodec::new().map_err(|_| invalid())?;
            let mut values = BTreeMap::new();
            for (key, projection, portable) in rows {
                if values.contains_key(&key) {
                    return Err(invalid());
                }
                let value = if let Some(portable) = portable {
                    let blob = super::envelope(&json!({"value":portable}), "value")?;
                    codec.decode(&blob).map_err(|_| invalid())?
                } else {
                    from_json(projection)?
                };
                values.insert(key, value);
            }
            let prepared = encode(values)?;
            Ok(Value::Object(
                prepared
                    .into_iter()
                    .map(|(key, value)| (key, value.projection))
                    .collect(),
            ))
        };
        decode().map_err(|_| refuse(StatusCode::CONFLICT, "unsupported_stored_memo"))
    })
    .await
    .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "memo_codec_unavailable"))?
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn initial_values_retain_int64_null_false_lists_and_literal_envelopes() {
        let values = initial(
            json!({"integer":9007199254740993_i64, "optional":null, "false":false,
            "list":[], "map":{}, "literal":{"codec":"avro", "blob":"literal"}}),
        )
        .unwrap();
        let codec = ValueCodec::new().unwrap();
        assert_eq!(
            codec
                .decode(values["integer"].envelope["blob"].as_str().unwrap())
                .unwrap(),
            Payload::Long(9007199254740993)
        );
        assert_eq!(
            codec
                .decode(values["map"].envelope["blob"].as_str().unwrap())
                .unwrap(),
            Payload::Map(BTreeMap::new())
        );
        assert_eq!(
            codec
                .decode(values["list"].envelope["blob"].as_str().unwrap())
                .unwrap(),
            Payload::Array(vec![])
        );
        assert_eq!(
            values["literal"].projection,
            json!({"codec":"avro", "blob":"literal"})
        );
        assert!(!values.contains_key("optional"));
        assert_eq!(values["false"].projection, json!(false));
    }

    #[test]
    fn structural_guards_measure_avro_bytes_and_reject_integer_like_keys() {
        for key in ["", "0", "-12", "01", "bad key", "λ"] {
            assert!(initial(json!({(key):1})).is_err(), "{key}");
        }
        assert!(initial(json!({"-":1})).is_ok());
        assert!(initial(json!({"value":"x".repeat(MAX_VALUE_BYTES)})).is_err());
        let many: serde_json::Map<_, _> =
            (0..101).map(|n| (format!("key-{n}"), json!(1))).collect();
        assert!(initial(Value::Object(many)).is_err());
        let deletions: serde_json::Map<_, _> = (0..101)
            .map(|n| (format!("key-{n}"), Value::Null))
            .collect();
        assert!(initial(Value::Object(deletions)).unwrap().is_empty());
        let large: serde_json::Map<_, _> = (0..7)
            .map(|n| (format!("key-{n}"), json!("x".repeat(10_000))))
            .collect();
        assert!(initial(Value::Object(large)).is_err());
        assert!(initial(json!({"overflow":u64::MAX})).is_err());
    }

    #[tokio::test]
    async fn stored_reads_use_exact_authority_and_preserve_byte_and_double_branches() {
        let codec = ValueCodec::new().unwrap();
        let portable = |value| json!({"codec":"avro", "blob":codec.encode(&value).unwrap()});
        let rows = vec![
            (
                "double".into(),
                json!(7),
                Some(portable(Payload::Double(7.0))),
            ),
            (
                "bytes".into(),
                json!("wrong"),
                Some(portable(Payload::Bytes(vec![255, 0]))),
            ),
            (
                "literal".into(),
                json!({"codec":"avro", "blob":"literal"}),
                None,
            ),
            (
                "empty_map".into(),
                json!([]),
                Some(portable(Payload::Map(BTreeMap::new()))),
            ),
            (
                "numeric_map".into(),
                Value::Null,
                Some(portable(Payload::Map(BTreeMap::from([(
                    "0".into(),
                    Payload::String("literal key".into()),
                )])))),
            ),
        ];
        let result = read_stored(Arc::new(Semaphore::new(1)), rows)
            .await
            .unwrap();
        assert_eq!(result["double"].as_f64(), Some(7.0));
        assert!(result["double"].as_i64().is_none());
        assert_eq!(result["bytes"], json!({"$type":"bytes", "base64":"/wA="}));
        assert_eq!(result["literal"], json!({"codec":"avro", "blob":"literal"}));
        assert_eq!(result["empty_map"], json!({"$type":"map", "entries":[]}));
        assert_eq!(
            result["numeric_map"],
            json!({"$type":"map", "entries":[{"key":"0","value":"literal key"}]})
        );
        assert!(
            read_stored(
                Arc::new(Semaphore::new(1)),
                vec![(
                    "bad".into(),
                    Value::Null,
                    Some(json!({"codec":"avro", "blob":"broken"}))
                )]
            )
            .await
            .is_err()
        );
    }
}
