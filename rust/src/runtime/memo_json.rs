//! Preserve PHP's JSON-array shape at the initial memo admission boundary.
use serde::{
    Deserialize, Deserializer,
    de::{Error, MapAccess, SeqAccess, Visitor},
};
use serde_json::{Map, Number, Value};
use std::fmt;

pub(super) struct StartBody(pub(super) Value);
struct MemoJson(Value);
/// Start input: PHP keeps an explicit envelope and takes any other object's
/// values in their request order (`array_values`).
struct InputJson(Value);

impl<'de> Deserialize<'de> for StartBody {
    fn deserialize<D: Deserializer<'de>>(deserializer: D) -> Result<Self, D::Error> {
        struct StartVisitor;
        impl<'de> Visitor<'de> for StartVisitor {
            type Value = StartBody;
            fn expecting(&self, formatter: &mut fmt::Formatter) -> fmt::Result {
                formatter.write_str("a workflow start JSON value")
            }
            fn visit_unit<E: Error>(self) -> Result<StartBody, E> {
                Ok(StartBody(Value::Null))
            }
            fn visit_bool<E: Error>(self, value: bool) -> Result<StartBody, E> {
                Ok(StartBody(value.into()))
            }
            fn visit_i64<E: Error>(self, value: i64) -> Result<StartBody, E> {
                Ok(StartBody(value.into()))
            }
            fn visit_u64<E: Error>(self, value: u64) -> Result<StartBody, E> {
                Ok(StartBody(value.into()))
            }
            fn visit_f64<E: Error>(self, value: f64) -> Result<StartBody, E> {
                Number::from_f64(value)
                    .map(|value| StartBody(value.into()))
                    .ok_or_else(|| E::custom("non-finite JSON number"))
            }
            fn visit_str<E: Error>(self, value: &str) -> Result<StartBody, E> {
                Ok(StartBody(value.into()))
            }
            fn visit_string<E: Error>(self, value: String) -> Result<StartBody, E> {
                Ok(StartBody(value.into()))
            }
            fn visit_seq<A: SeqAccess<'de>>(self, mut sequence: A) -> Result<StartBody, A::Error> {
                let mut values = Vec::new();
                while let Some(value) = sequence.next_element::<Value>()? {
                    values.push(value);
                }
                Ok(StartBody(Value::Array(values)))
            }
            fn visit_map<A: MapAccess<'de>>(self, mut map: A) -> Result<StartBody, A::Error> {
                let mut body = Map::new();
                while let Some(key) = map.next_key::<String>()? {
                    let value = if key == "memo" {
                        map.next_value::<MemoJson>()?.0
                    } else if key == "input" {
                        map.next_value::<InputJson>()?.0
                    } else {
                        map.next_value::<Value>()?
                    };
                    body.insert(key, value);
                }
                Ok(StartBody(Value::Object(body)))
            }
        }
        deserializer.deserialize_any(StartVisitor)
    }
}

impl<'de> Deserialize<'de> for MemoJson {
    fn deserialize<D: Deserializer<'de>>(deserializer: D) -> Result<Self, D::Error> {
        struct MemoVisitor;
        impl<'de> Visitor<'de> for MemoVisitor {
            type Value = MemoJson;
            fn expecting(&self, formatter: &mut fmt::Formatter) -> fmt::Result {
                formatter.write_str("a JSON memo value")
            }
            fn visit_unit<E: Error>(self) -> Result<MemoJson, E> {
                Ok(MemoJson(Value::Null))
            }
            fn visit_bool<E: Error>(self, value: bool) -> Result<MemoJson, E> {
                Ok(MemoJson(value.into()))
            }
            fn visit_i64<E: Error>(self, value: i64) -> Result<MemoJson, E> {
                Ok(MemoJson(value.into()))
            }
            fn visit_u64<E: Error>(self, value: u64) -> Result<MemoJson, E> {
                // PHP's JSON decoder selects a double outside signed int64.
                // This boundary does not change typed Avro long handling.
                if value > i64::MAX as u64 {
                    self.visit_f64(value as f64)
                } else {
                    Ok(MemoJson(value.into()))
                }
            }
            fn visit_f64<E: Error>(self, value: f64) -> Result<MemoJson, E> {
                Number::from_f64(value)
                    .map(|value| MemoJson(value.into()))
                    .ok_or_else(|| E::custom("non-finite JSON number"))
            }
            fn visit_str<E: Error>(self, value: &str) -> Result<MemoJson, E> {
                Ok(MemoJson(value.into()))
            }
            fn visit_string<E: Error>(self, value: String) -> Result<MemoJson, E> {
                Ok(MemoJson(value.into()))
            }
            fn visit_seq<A: SeqAccess<'de>>(self, mut sequence: A) -> Result<MemoJson, A::Error> {
                let mut values = Vec::new();
                while let Some(value) = sequence.next_element::<MemoJson>()? {
                    values.push(value.0);
                }
                Ok(MemoJson(Value::Array(values)))
            }
            fn visit_map<A: MapAccess<'de>>(self, mut map: A) -> Result<MemoJson, A::Error> {
                let mut keys = Vec::new();
                let mut values = Map::new();
                while let Some((key, value)) = map.next_entry::<String, MemoJson>()? {
                    // Duplicate JSON keys replace the value at the original
                    // position, as PHP's associative decoder does.
                    if !values.contains_key(&key) {
                        keys.push(key.clone());
                    }
                    values.insert(key, value.0);
                }
                let value = if keys
                    .iter()
                    .enumerate()
                    .all(|(index, key)| *key == index.to_string())
                {
                    Value::Array(
                        keys.into_iter()
                            .map(|key| values.remove(&key).expect("seen memo key"))
                            .collect(),
                    )
                } else {
                    Value::Object(values)
                };
                Ok(MemoJson(value))
            }
        }
        deserializer.deserialize_any(MemoVisitor)
    }
}

impl<'de> Deserialize<'de> for InputJson {
    fn deserialize<D: Deserializer<'de>>(deserializer: D) -> Result<Self, D::Error> {
        struct InputVisitor;
        impl<'de> Visitor<'de> for InputVisitor {
            type Value = InputJson;
            fn expecting(&self, formatter: &mut fmt::Formatter) -> fmt::Result {
                formatter.write_str("a JSON workflow input")
            }
            fn visit_map<A: MapAccess<'de>>(self, mut map: A) -> Result<InputJson, A::Error> {
                let mut entries: Vec<(String, Value)> = Vec::new();
                while let Some((key, value)) = map.next_entry::<String, MemoJson>()? {
                    match entries.iter_mut().find(|(seen, _)| *seen == key) {
                        Some(entry) => entry.1 = value.0,
                        None => entries.push((key, value.0)),
                    }
                }
                let mut keys: Vec<&str> = entries.iter().map(|(key, _)| key.as_str()).collect();
                keys.sort_unstable();
                // PHP checks the codec of any input object, so one it would
                // refuse stays an object for that check.
                let refused_codec = entries.iter().any(|(key, value)| {
                    key == "codec"
                        && super::input_envelope::codec_refusal(&super::start_request::normalized(value.clone())).is_some()
                });
                Ok(InputJson(if refused_codec || super::start_request::envelope_keys(&keys) {
                    Value::Object(entries.into_iter().collect())
                } else {
                    Value::Array(entries.into_iter().map(|(_, value)| value).collect())
                }))
            }
            fn visit_seq<A: SeqAccess<'de>>(self, mut sequence: A) -> Result<InputJson, A::Error> {
                let mut values = Vec::new();
                while let Some(value) = sequence.next_element::<MemoJson>()? {
                    values.push(value.0);
                }
                Ok(InputJson(Value::Array(values)))
            }
            fn visit_unit<E: Error>(self) -> Result<InputJson, E> {
                Ok(InputJson(Value::Null))
            }
            fn visit_bool<E: Error>(self, value: bool) -> Result<InputJson, E> {
                Ok(InputJson(value.into()))
            }
            fn visit_i64<E: Error>(self, value: i64) -> Result<InputJson, E> {
                Ok(InputJson(value.into()))
            }
            fn visit_u64<E: Error>(self, value: u64) -> Result<InputJson, E> {
                Ok(InputJson(value.into()))
            }
            fn visit_f64<E: Error>(self, value: f64) -> Result<InputJson, E> {
                Number::from_f64(value)
                    .map(|value| InputJson(value.into()))
                    .ok_or_else(|| E::custom("non-finite JSON number"))
            }
            fn visit_str<E: Error>(self, value: &str) -> Result<InputJson, E> {
                Ok(InputJson(value.into()))
            }
        }
        deserializer.deserialize_any(InputVisitor)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;
    #[test]
    fn initial_json_history_keeps_the_published_php_shapes_without_altering_other_fields() {
        let StartBody(body) = serde_json::from_str(r#"{"input":{"keep":{}},"memo":{"metadata":{"map":{},"numeric":{"1":"one","0":"zero"}},"deleted":null}}"#).unwrap();
        assert_eq!(body["input"], json!([[]]));
        assert_eq!(
            body["memo"],
            json!({"metadata":{"map":[],"numeric":{"1":"one","0":"zero"}},"deleted":null})
        );
        let StartBody(body) = serde_json::from_str(r#"{"memo":{"ordered":{"0":"zero","1":"one"},"duplicate":{"0":"first","0":"last"},"leading":{"01":"one"}}}"#).unwrap();
        assert_eq!(
            body["memo"],
            json!({"ordered":["zero","one"],"duplicate":["last"],"leading":{"01":"one"}})
        );
    }

    #[test]
    fn start_input_keeps_envelopes_and_takes_other_values_in_order() {
        let StartBody(body) = serde_json::from_str(r#"{"input":{"b":1,"a":{"0":"x"},"b":2}}"#).unwrap();
        assert_eq!(body["input"], json!([2, ["x"]]));
        let StartBody(body) = serde_json::from_str(r#"{"input":{"codec":"avro","blob":"AA=="}}"#).unwrap();
        assert_eq!(body["input"], json!({"codec":"avro","blob":"AA=="}));
        let StartBody(body) = serde_json::from_str(r#"{"input":{"codec":"avro"}}"#).unwrap();
        assert_eq!(body["input"], json!(["avro"]));
    }

    #[test]
    fn malformed_start_roots_keep_the_existing_runtime_validation_path() {
        for raw in [
            "null",
            "true",
            "7",
            "7.5",
            "\"text\"",
            "[]",
            "[{\"memo\":{}}]",
        ] {
            let StartBody(body) = serde_json::from_str(raw).unwrap();
            assert_eq!(body, serde_json::from_str::<Value>(raw).unwrap());
        }
    }
}
