//! Ordered protocol values through Apache's schema-aware serde writer.
//! The official writer owns headers, union selection and binary primitives.
use super::{CodecError, VALUE_SCHEMA, Value};
use apache_avro::{AvroSchema, Schema, SpecificSingleObjectWriter};
use serde::{
    Serialize, Serializer,
    ser::{SerializeMap, SerializeSeq, SerializeStruct},
};
use std::collections::BTreeMap;

struct WireValue<'a>(&'a Value);
struct Branch<'a>(&'a Value);
struct Bytes<'a>(&'a [u8]);
struct Items<'a>(&'a [Value]);
struct Entries<'a>(&'a BTreeMap<String, Value>);

impl AvroSchema for WireValue<'_> {
    fn get_schema() -> Schema {
        Schema::parse_str(VALUE_SCHEMA).expect("immutable protocol Value schema")
    }
}

fn record<S: Serializer, T: Serialize + ?Sized>(
    serializer: S,
    name: &'static str,
    field: &'static str,
    value: &T,
) -> Result<S::Ok, S::Error> {
    let mut record = serializer.serialize_struct(name, 1)?;
    record.serialize_field(field, value)?;
    record.end()
}

impl Serialize for WireValue<'_> {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        record(serializer, "Value", "value", &Branch(self.0))
    }
}

impl Serialize for Branch<'_> {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        match self.0 {
            Value::Null => serializer.serialize_unit(),
            Value::Boolean(value) => record(serializer, "BooleanValue", "boolean", value),
            Value::Long(value) => record(serializer, "LongValue", "long", value),
            Value::Double(value) => record(serializer, "DoubleValue", "double", value),
            Value::Bytes(value) => record(serializer, "BytesValue", "bytes", &Bytes(value)),
            Value::String(value) => record(serializer, "StringValue", "string", value),
            Value::Array(values) => record(serializer, "ArrayValue", "items", &Items(values)),
            Value::Map(values) => record(serializer, "MapValue", "entries", &Entries(values)),
        }
    }
}

impl Serialize for Bytes<'_> {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        serializer.serialize_bytes(self.0)
    }
}

impl Serialize for Items<'_> {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        let mut sequence = serializer.serialize_seq(Some(self.0.len()))?;
        for value in self.0 {
            sequence.serialize_element(&WireValue(value))?;
        }
        sequence.end()
    }
}

impl Serialize for Entries<'_> {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        let mut map = serializer.serialize_map(Some(self.0.len()))?;
        for (key, value) in self.0 {
            map.serialize_entry(key, &WireValue(value))?;
        }
        map.end()
    }
}

pub(super) fn encode(value: &Value) -> Result<Vec<u8>, CodecError> {
    // Retain the existing finite-number/value validation before serde. This
    // value is bounded by the memo limits and is serialized off the executor.
    super::to_datum(value)?;
    let writer = SpecificSingleObjectWriter::<WireValue<'_>>::new()?;
    let mut bytes = Vec::new();
    writer.write_ref(&WireValue(value), &mut bytes)?;
    Ok(bytes)
}

#[cfg(test)]
mod tests {
    use super::*;
    use base64::{Engine, engine::general_purpose::STANDARD};

    #[test]
    fn canonical_memo_map_matches_the_existing_php_byte_vector() {
        let value = Value::Map(BTreeMap::from([
            ("binary".into(), Value::Bytes(b"same".to_vec())),
            ("double".into(), Value::Double(7.0)),
            ("invalid_binary".into(), Value::Bytes(vec![255, 0])),
            ("long".into(), Value::Long(7)),
            (
                "nested".into(),
                Value::Map(BTreeMap::from([
                    ("alpha".into(), Value::Long(1)),
                    ("beta".into(), Value::Long(2)),
                ])),
            ),
            ("text".into(), Value::String("same".into())),
        ]));
        let expected = "wwHioz3/VYAiNw4MDGJpbmFyeQgIc2FtZQxkb3VibGUGAAAAAAAAHEAcaW52YWxpZF9iaW5hcnkIBP8ACGxvbmcEDgxuZXN0ZWQOBAphbHBoYQQCCGJldGEEBAAIdGV4dAoIc2FtZQA=";
        for _ in 0..32 {
            assert_eq!(STANDARD.encode(encode(&value).unwrap()), expected);
        }
    }

    #[test]
    fn ordered_writer_retains_every_branch_and_rejects_non_finite_values() {
        let value = Value::Array(vec![
            Value::Null,
            Value::Boolean(false),
            Value::Long(9007199254740993),
            Value::Double(-0.0),
            Value::Bytes(vec![255, 0]),
            Value::String("memo λ".into()),
            Value::Array(vec![]),
            Value::Map(BTreeMap::new()),
        ]);
        let codec = super::super::ValueCodec::new().unwrap();
        assert_eq!(
            codec
                .decode(&STANDARD.encode(encode(&value).unwrap()))
                .unwrap(),
            value
        );
        assert!(matches!(
            encode(&Value::Double(f64::INFINITY)),
            Err(CodecError::NonFiniteDouble)
        ));
    }
}
