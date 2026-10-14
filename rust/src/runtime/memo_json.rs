//! Preserve PHP's JSON-array shape at the initial memo admission boundary.
use serde::{
    Deserialize, Deserializer,
    de::{Error, MapAccess, SeqAccess, Visitor},
};
use serde_json::{Map, Number, Value};
use std::fmt;

pub(super) struct StartBody(pub(super) Value);
struct MemoJson(Value);

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
                Ok(MemoJson(value.into()))
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

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;
    #[test]
    fn initial_json_history_keeps_the_published_php_shapes_without_altering_other_fields() {
        let StartBody(body) = serde_json::from_str(r#"{"input":{"keep":{}},"memo":{"metadata":{"map":{},"numeric":{"1":"one","0":"zero"}},"deleted":null}}"#).unwrap();
        assert_eq!(body["input"], json!({"keep":{}}));
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
