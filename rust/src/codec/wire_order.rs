//! Protocol values whose map entries keep the order the wire carried them.
//! PHP decodes an Avro map into an array in wire order, so a decoded
//! argument list can depend on it (a variadic named argument spreads a map's
//! values in that order). Apache's generic reader keeps maps in a hash map,
//! so this path reads and writes through a private binding schema that spells
//! each map as an array of key/value records, as PHP's `ValueDatumWriter`
//! does. The binary layout is identical; Apache still owns every primitive,
//! block and union, and the public schema still validates and frames.
use super::{CodecError, CompleteInput, VALUE_FINGERPRINT, VALUE_SCHEMA, ValueCodec, php_base64};
use apache_avro::{
    Schema, reader::datum::GenericDatumReader, types::Value as Datum,
};
use base64::{Engine, engine::general_purpose::STANDARD};
use std::{collections::HashMap, sync::LazyLock};

/// The single-object marker; the public fingerprint follows it.
const MARKER: [u8; 2] = [0xc3, 0x01];
const PUBLIC_MAP: &str = r#"{"type":"map","values":"Value"}"#;
const ENTRY_ARRAY: &str = r#"{"type":"array","items":{"type":"record","name":"MapEntry","fields":[{"name":"key","type":"string"},{"name":"value","type":"Value"}]}}"#;

/// The public Value schema with its map spelled as an array of entries.
static BINDING: LazyLock<Schema> = LazyLock::new(|| {
    assert_eq!(VALUE_SCHEMA.matches(PUBLIC_MAP).count(), 1, "one map in the protocol Value schema");
    Schema::parse_str(&VALUE_SCHEMA.replace(PUBLIC_MAP, ENTRY_ARRAY)).expect("Value binding schema")
});

/// A protocol Value with map entries in wire order. A repeated key keeps its
/// first position and its last value, as a PHP array does.
#[derive(Clone, Debug, PartialEq)]
pub enum OrderedValue {
    Null,
    Boolean(bool),
    Long(i64),
    Double(f64),
    Bytes(Vec<u8>),
    String(String),
    Array(Vec<OrderedValue>),
    Map(Vec<(String, OrderedValue)>),
}

impl ValueCodec {
    /// Decodes a blob as `decode` does, keeping map entries in wire order.
    pub fn decode_ordered(&self, blob: &str) -> Result<OrderedValue, CodecError> {
        // The public reader checks framing, the fingerprint, the schema,
        // truncation and trailing bytes first; the binding pass then reads
        // the same datum again only to recover the order.
        self.decode(blob)?;
        let bytes = php_base64(blob)?;
        let datum = bytes.get(MARKER.len() + VALUE_FINGERPRINT.len()..).ok_or(CodecError::Truncated)?;
        let mut input = CompleteInput { remaining: datum, truncated: false };
        let value = GenericDatumReader::builder(&BINDING).build()?.read_value(&mut input);
        if input.truncated {
            return Err(CodecError::Truncated);
        }
        let value = value?;
        if !input.remaining.is_empty() {
            return Err(CodecError::TrailingBytes);
        }
        from_binding(value)
    }

    /// Encodes a value with its map entries in the order given, under the
    /// public single-object header.
    pub fn encode_ordered_value(&self, value: &OrderedValue) -> Result<String, CodecError> {
        // Written directly in the protocol Value's Avro binary layout: a deep
        // value costs one small frame per level, where Apache's datum writer
        // over the binding schema needs far more stack than a 511-level
        // request leaves.
        let mut bytes = Vec::with_capacity(64);
        bytes.extend_from_slice(&MARKER);
        bytes.extend_from_slice(&VALUE_FINGERPRINT);
        write_value(value, &mut bytes)?;
        // The layout is checked against Apache's writer and PHP's published
        // bytes in tests; reading it back here would cost a deep value more
        // stack than writing it.
        Ok(STANDARD.encode(bytes))
    }
}

/// Avro's zig-zag variable-length long.
fn write_long(number: i64, out: &mut Vec<u8>) {
    let mut zigzag = ((number << 1) ^ (number >> 63)) as u64;
    while zigzag >= 0x80 {
        out.push((zigzag as u8 & 0x7f) | 0x80);
        zigzag >>= 7;
    }
    out.push(zigzag as u8);
}

fn write_bytes(bytes: &[u8], out: &mut Vec<u8>) {
    write_long(bytes.len() as i64, out);
    out.extend_from_slice(bytes);
}

/// One protocol Value datum: the union branch index, then the branch
/// record's one field. An array or map is one block of all its items (none
/// when empty) and the end marker, as Apache's and PHP's writers emit.
fn write_value(value: &OrderedValue, out: &mut Vec<u8>) -> Result<(), CodecError> {
    match value {
        OrderedValue::Null => write_long(0, out),
        OrderedValue::Boolean(flag) => {
            write_long(1, out);
            out.push(u8::from(*flag));
        }
        OrderedValue::Long(number) => {
            write_long(2, out);
            write_long(*number, out);
        }
        OrderedValue::Double(number) if !number.is_finite() => return Err(CodecError::NonFiniteDouble),
        OrderedValue::Double(number) => {
            write_long(3, out);
            out.extend_from_slice(&number.to_le_bytes());
        }
        OrderedValue::Bytes(bytes) => {
            write_long(4, out);
            write_bytes(bytes, out);
        }
        OrderedValue::String(text) => {
            write_long(5, out);
            write_bytes(text.as_bytes(), out);
        }
        OrderedValue::Array(items) => {
            write_long(6, out);
            if !items.is_empty() {
                write_long(items.len() as i64, out);
                for item in items {
                    write_value(item, out)?;
                }
            }
            write_long(0, out);
        }
        OrderedValue::Map(entries) => {
            write_long(7, out);
            if !entries.is_empty() {
                write_long(entries.len() as i64, out);
                for (key, item) in entries {
                    write_bytes(key.as_bytes(), out);
                    write_value(item, out)?;
                }
            }
            write_long(0, out);
        }
    }
    Ok(())
}

/// The one field of a record the binding schema read.
fn field(datum: Datum, name: &str) -> Result<Datum, CodecError> {
    match datum {
        Datum::Record(fields) => fields.into_iter().find(|(field, _)| field == name).map(|(_, value)| value),
        _ => None,
    }
    .ok_or(CodecError::InvalidValue)
}

fn from_binding(datum: Datum) -> Result<OrderedValue, CodecError> {
    let Datum::Union(index, branch) = field(datum, "value")? else {
        return Err(CodecError::InvalidValue);
    };
    Ok(match (index, *branch) {
        (0, Datum::Null) => OrderedValue::Null,
        (1, branch) => match field(branch, "boolean")? {
            Datum::Boolean(flag) => OrderedValue::Boolean(flag),
            _ => return Err(CodecError::InvalidValue),
        },
        (2, branch) => match field(branch, "long")? {
            Datum::Long(number) => OrderedValue::Long(number),
            _ => return Err(CodecError::InvalidValue),
        },
        (3, branch) => match field(branch, "double")? {
            Datum::Double(number) => OrderedValue::Double(number),
            _ => return Err(CodecError::InvalidValue),
        },
        (4, branch) => match field(branch, "bytes")? {
            Datum::Bytes(bytes) => OrderedValue::Bytes(bytes),
            _ => return Err(CodecError::InvalidValue),
        },
        (5, branch) => match field(branch, "string")? {
            Datum::String(text) => OrderedValue::String(text),
            _ => return Err(CodecError::InvalidValue),
        },
        (6, branch) => match field(branch, "items")? {
            Datum::Array(items) => OrderedValue::Array(items.into_iter().map(from_binding).collect::<Result<_, _>>()?),
            _ => return Err(CodecError::InvalidValue),
        },
        (7, branch) => match field(branch, "entries")? {
            Datum::Array(records) => {
                let mut entries: Vec<(String, OrderedValue)> = Vec::with_capacity(records.len());
                let mut slots: HashMap<String, usize> = HashMap::with_capacity(records.len());
                for entry in records {
                    let Datum::Record(fields) = entry else {
                        return Err(CodecError::InvalidValue);
                    };
                    let mut fields = fields.into_iter();
                    let (Some((_, Datum::String(key))), Some((_, value)), None) = (fields.next(), fields.next(), fields.next()) else {
                        return Err(CodecError::InvalidValue);
                    };
                    let value = from_binding(value)?;
                    match slots.get(&key) {
                        Some(&slot) => entries[slot].1 = value,
                        None => {
                            slots.insert(key.clone(), entries.len());
                            entries.push((key, value));
                        }
                    }
                }
                OrderedValue::Map(entries)
            }
            _ => return Err(CodecError::InvalidValue),
        },
        _ => return Err(CodecError::InvalidValue),
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn map_entries_keep_their_wire_order_both_ways() {
        let codec = ValueCodec::new().unwrap();
        // PHP's encoding of ['rest' => ['z' => 20, 'a' => 10]].
        let value = OrderedValue::Map(vec![("rest".into(),
            OrderedValue::Map(vec![("z".into(), OrderedValue::Long(20)), ("a".into(), OrderedValue::Long(10))]))]);
        let blob = codec.encode_ordered_value(&value).unwrap();
        assert_eq!(codec.decode_ordered(&blob).unwrap(), value);
        // The schema-aware reader accepts the same bytes.
        assert!(codec.decode(&blob).is_ok());
    }

    #[test]
    fn the_writer_matches_the_published_bytes_for_every_branch() {
        let codec = ValueCodec::new().unwrap();
        for blob in [
            "wwHioz3/VYAiNw4CDHByZWZpeAoEb2sA",
            "wwHioz3/VYAiNwwCCgRvawA=",
            "wwHioz3/VYAiNwwA",
            "wwHioz3/VYAiNw4A",
            "wwHioz3/VYAiNw4EAjAKAmEMcHJlZml4CgJiAA==",
            "wwHioz3/VYAiNw4CCnZhbHVlDgAA",
            "wwHioz3/VYAiNw4MDGJpbmFyeQgIc2FtZQxkb3VibGUGAAAAAAAAHEAcaW52YWxpZF9iaW5hcnkIBP8ACGxvbmcEDgxuZXN0ZWQOBAphbHBoYQQCCGJldGEEBAAIdGV4dAoIc2FtZQA=",
        ] {
            let value = codec.decode_ordered(blob).unwrap();
            assert_eq!(codec.encode_ordered_value(&value).unwrap(), blob, "{blob}");
        }
        assert!(codec.decode_ordered("AAAA").is_err());
    }

    /// Apache's datum writer over the binding schema, the reference the
    /// direct writer replaced.
    fn apache_reference(value: &OrderedValue) -> String {
        use apache_avro::writer::datum::GenericDatumWriter;
        fn record(field: &str, value: Datum) -> Datum {
            Datum::Record(vec![(field.into(), value)])
        }
        fn binding(value: &OrderedValue) -> Datum {
            let (index, branch) = match value {
                OrderedValue::Null => (0, Datum::Null),
                OrderedValue::Boolean(flag) => (1, record("boolean", Datum::Boolean(*flag))),
                OrderedValue::Long(number) => (2, record("long", Datum::Long(*number))),
                OrderedValue::Double(number) => (3, record("double", Datum::Double(*number))),
                OrderedValue::Bytes(bytes) => (4, record("bytes", Datum::Bytes(bytes.clone()))),
                OrderedValue::String(text) => (5, record("string", Datum::String(text.clone()))),
                OrderedValue::Array(items) => (6, record("items", Datum::Array(items.iter().map(binding).collect()))),
                OrderedValue::Map(entries) => (7, record("entries", Datum::Array(entries.iter()
                    .map(|(key, value)| Datum::Record(vec![("key".into(), Datum::String(key.clone())), ("value".into(), binding(value))]))
                    .collect()))),
            };
            record("value", Datum::Union(index, Box::new(branch)))
        }
        let datum = GenericDatumWriter::builder(&BINDING).build().unwrap().write_value_to_vec(binding(value)).unwrap();
        STANDARD.encode([MARKER.as_slice(), VALUE_FINGERPRINT.as_slice(), datum.as_slice()].concat())
    }

    #[test]
    fn the_direct_writer_matches_apache_s_binding_writer() {
        let codec = ValueCodec::new().unwrap();
        let many: Vec<OrderedValue> = (0..200).map(|index| OrderedValue::Long(index * 1_000_003 - 7)).collect();
        let entries: Vec<(String, OrderedValue)> = (0..70).map(|index| (format!("k{}", 70 - index), OrderedValue::Bytes(vec![index as u8; index]))).collect();
        for value in [
            OrderedValue::Null,
            OrderedValue::Boolean(true),
            OrderedValue::Long(i64::MIN),
            OrderedValue::Long(i64::MAX),
            OrderedValue::Long(-1),
            OrderedValue::Double(-0.0),
            OrderedValue::Double(1.5e300),
            OrderedValue::String("λ ключ 🙂".into()),
            OrderedValue::Bytes(vec![]),
            OrderedValue::Array(vec![]),
            OrderedValue::Map(vec![]),
            OrderedValue::Array(many),
            OrderedValue::Map(entries),
            OrderedValue::Array(vec![OrderedValue::Map(vec![("z".into(), OrderedValue::Array(vec![OrderedValue::Null])),
                ("a".into(), OrderedValue::Map(vec![("".into(), OrderedValue::Boolean(false))]))])]),
        ] {
            assert_eq!(codec.encode_ordered_value(&value).unwrap(), apache_reference(&value), "{value:?}");
        }
        assert!(matches!(codec.encode_ordered_value(&OrderedValue::Double(f64::NAN)), Err(CodecError::NonFiniteDouble)));
    }

    #[test]
    fn a_repeated_key_keeps_its_first_position_and_last_value() {
        let codec = ValueCodec::new().unwrap();
        let long = |number| OrderedValue::Long(number);
        let written = OrderedValue::Map(vec![("a".into(), long(1)), ("b".into(), long(2)), ("a".into(), long(3))]);
        let blob = codec.encode_ordered_value(&written).unwrap();
        assert_eq!(codec.decode_ordered(&blob).unwrap(), OrderedValue::Map(vec![("a".into(), long(3)), ("b".into(), long(2))]));
    }
}
