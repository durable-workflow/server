//! Adapter for the immutable protocol Value. Apache Avro owns binary encoding,
//! schema resolution and the single-object header. Full ingress qualification,
//! streaming external payloads and bounded CPU scheduling remain separate work.

use std::collections::BTreeMap;
use std::io::{self, Read};
mod ordered;
mod validation;

use apache_avro::{
    GenericSingleObjectReader, GenericSingleObjectWriter, Schema, types::Value as Datum,
};
use base64::{
    Engine, alphabet,
    engine::{DecodePaddingMode, GeneralPurpose, GeneralPurposeConfig, general_purpose::STANDARD},
};

/// PHP's strict `base64_decode`: padding is absent or canonical, and unused
/// trailing bits may be set.
const PHP_BASE64: GeneralPurpose = GeneralPurpose::new(
    &alphabet::STANDARD,
    GeneralPurposeConfig::new()
        .with_decode_padding_mode(DecodePaddingMode::Indifferent)
        .with_decode_allow_trailing_bits(true),
);

/// Decodes a blob as published PHP does: ASCII whitespace is skipped, and
/// any padding must be exactly what the final quantum needs.
pub(crate) fn php_base64(blob: &str) -> Result<Vec<u8>, base64::DecodeError> {
    let compact: String = blob.chars().filter(|c| !matches!(c, ' ' | '\t' | '\n' | '\r')).collect();
    let unpadded = compact.trim_end_matches('=');
    let padding = compact.len() - unpadded.len();
    if padding > 0 && padding != (4 - unpadded.len() % 4) % 4 {
        return Err(base64::DecodeError::InvalidPadding);
    }
    PHP_BASE64.decode(unpadded)
}

pub const VALUE_SCHEMA: &str = include_str!("../schema/durable_workflow.protocol.Value.v1.avsc");

/// Logical values retain bytes/text, list/map and signed 64-bit distinctions.
/// Map key order is not a wire identity; every key remains a string.
#[derive(Clone, Debug)]
pub enum Value {
    Null,
    Boolean(bool),
    Long(i64),
    Double(f64),
    Bytes(Vec<u8>),
    String(String),
    Array(Vec<Value>),
    Map(BTreeMap<String, Value>),
}

impl PartialEq for Value {
    fn eq(&self, other: &Self) -> bool {
        match (self, other) {
            (Self::Null, Self::Null) => true,
            (Self::Boolean(left), Self::Boolean(right)) => left == right,
            (Self::Long(left), Self::Long(right)) => left == right,
            (Self::Double(left), Self::Double(right)) => left.to_bits() == right.to_bits(),
            (Self::Bytes(left), Self::Bytes(right)) => left == right,
            (Self::String(left), Self::String(right)) => left == right,
            (Self::Array(left), Self::Array(right)) => left == right,
            (Self::Map(left), Self::Map(right)) => left == right,
            _ => false,
        }
    }
}

#[derive(Debug, thiserror::Error)]
pub enum CodecError {
    #[error("invalid_payload_framing: expected base64 Avro single-object bytes")]
    Base64(#[from] base64::DecodeError),
    #[error("invalid_payload_framing: {0}")]
    Avro(#[from] apache_avro::Error),
    #[error("invalid_payload_framing: trailing bytes after Avro Value datum")]
    TrailingBytes,
    #[error("invalid_payload_framing: truncated Avro Value datum")]
    Truncated,
    #[error("invalid_payload_framing: invalid Value record or branch")]
    InvalidValue,
    #[error("non_finite_float: Avro Value doubles must be finite")]
    NonFiniteDouble,
}

/// The writer schema fingerprint of a Value single-object frame.
const VALUE_FINGERPRINT: [u8; 8] = [0xe2, 0xa3, 0x3d, 0xff, 0x55, 0x80, 0x22, 0x37];

/// Published PHP's `CodecDecodeException` message for a blob that
/// [`ValueCodec::decode`] refused: what the decoder saw, then a remediation.
pub fn ingress_diagnosis(blob: &str, error: &CodecError) -> String {
    const SCHEMA: &str = "Provide a schema-conformant Avro Value datum.";
    let (detail, remediation) = match php_base64(blob) {
        Err(_) if looks_like_json(blob) => (
            "invalid_payload_framing: payload bytes look like JSON, not base64-encoded Avro.".to_owned(),
            "Encode the value with the fixed Avro Value codec; JSON is only the HTTP document transport.",
        ),
        Err(_) => (
            "invalid_payload_framing: failed to base64-decode Avro payload bytes.".to_owned(),
            "Avro payloads must be strict base64 containing a c301 single-object frame.",
        ),
        Ok(bytes) if bytes.len() < 10 || !bytes.starts_with(&[0xc3, 0x01]) => (
            "invalid_payload_framing: expected Avro single-object magic c301.".to_owned(),
            "Encode the value with the Durable Workflow 2.0 fixed Avro Value schema and single-object framing; JSON is only the HTTP document transport.",
        ),
        Ok(bytes) if bytes[2..10] != VALUE_FINGERPRINT => (
            format!(
                "unsupported_payload_schema: unknown CRC-64-AVRO fingerprint {}.",
                bytes[2..10].iter().map(|byte| format!("{byte:02x}")).collect::<String>()
            ),
            "Upgrade to a runtime that bundles this writer schema; never guess or fall back to JSON.",
        ),
        Ok(_) => match error {
            CodecError::TrailingBytes => (
                "invalid_payload_framing: trailing bytes after Avro Value datum.".to_owned(),
                "Provide exactly one Avro Value datum in each single-object frame.",
            ),
            CodecError::Truncated => (
                "invalid_payload_framing: truncated Avro Value datum.".to_owned(),
                "Provide one complete Avro Value datum after the single-object fingerprint.",
            ),
            CodecError::InvalidValue => (
                "invalid_payload_framing: datum is not a durable_workflow.protocol.Value record.".to_owned(),
                SCHEMA,
            ),
            _ => (
                "invalid_payload_framing: malformed Avro Value datum.".to_owned(),
                "Provide one complete datum encoded with the writer schema selected by the single-object fingerprint.",
            ),
        },
    };
    format!("{detail} Remediation: {remediation}")
}

/// PHP's `looksLikeJson`: the first byte could start a JSON document.
fn looks_like_json(blob: &str) -> bool {
    blob.bytes().next().is_some_and(|first| b"{[\"-tfn".contains(&first) || first.is_ascii_digit())
}

pub struct ValueCodec {
    schema: Schema,
    reader: GenericSingleObjectReader,
}

impl ValueCodec {
    pub fn new() -> Result<Self, CodecError> {
        let schema = Schema::parse_str(VALUE_SCHEMA)?;
        let reader = GenericSingleObjectReader::builder()
            .schema(schema.clone())
            .build()?;
        Ok(Self { schema, reader })
    }

    pub fn encode(&self, value: &Value) -> Result<String, CodecError> {
        let datum = to_datum(value)?;
        let mut bytes = Vec::new();
        GenericSingleObjectWriter::new_with_capacity(&self.schema, 0)?
            .write_value_ref(&datum, &mut bytes)?;
        Ok(STANDARD.encode(bytes))
    }

    pub(crate) fn encode_ordered(&self, value: &Value) -> Result<String, CodecError> {
        Ok(STANDARD.encode(ordered::encode(value)?))
    }

    pub fn decode(&self, blob: &str) -> Result<Value, CodecError> {
        let bytes = php_base64(blob)?;
        let mut input = CompleteInput {
            remaining: bytes.as_slice(),
            truncated: false,
        };
        let datum = self.reader.read_value(&mut input);
        // Apache's streaming reader treats EOF at a union as a null sentinel.
        // A single-object protocol frame must contain a complete datum instead.
        if input.truncated {
            return Err(CodecError::Truncated);
        }
        let datum = datum?;
        if !input.remaining.is_empty() {
            return Err(CodecError::TrailingBytes);
        }
        from_datum(datum)
    }
}

struct CompleteInput<'a> {
    remaining: &'a [u8],
    truncated: bool,
}

impl Read for CompleteInput<'_> {
    fn read(&mut self, buffer: &mut [u8]) -> io::Result<usize> {
        let count = self.remaining.read(buffer)?;
        self.truncated |= count < buffer.len();
        Ok(count)
    }
}

fn record(name: &str, value: Datum) -> Datum {
    Datum::Record(vec![(name.into(), value)])
}

fn to_datum(value: &Value) -> Result<Datum, CodecError> {
    let (index, branch) = match value {
        Value::Null => (0, Datum::Null),
        Value::Boolean(value) => (1, record("boolean", Datum::Boolean(*value))),
        Value::Long(value) => (2, record("long", Datum::Long(*value))),
        Value::Double(value) => {
            if !value.is_finite() {
                return Err(CodecError::NonFiniteDouble);
            }
            (3, record("double", Datum::Double(*value)))
        }
        Value::Bytes(value) => (4, record("bytes", Datum::Bytes(value.clone()))),
        Value::String(value) => (5, record("string", Datum::String(value.clone()))),
        Value::Array(values) => (
            6,
            record(
                "items",
                Datum::Array(values.iter().map(to_datum).collect::<Result<_, _>>()?),
            ),
        ),
        Value::Map(values) => (
            7,
            record(
                "entries",
                Datum::Map(
                    values
                        .iter()
                        .map(|(key, value)| Ok((key.clone(), to_datum(value)?)))
                        .collect::<Result<_, CodecError>>()?,
                ),
            ),
        ),
    };
    Ok(record("value", Datum::Union(index, Box::new(branch))))
}

fn single_field(datum: Datum) -> Result<(String, Datum), CodecError> {
    match datum {
        Datum::Record(mut fields) if fields.len() == 1 => Ok(fields.pop().unwrap()),
        _ => Err(CodecError::InvalidValue),
    }
}

fn from_datum(datum: Datum) -> Result<Value, CodecError> {
    let (name, branch) = single_field(datum)?;
    if name != "value" {
        return Err(CodecError::InvalidValue);
    }
    let Datum::Union(index, branch) = branch else {
        return Err(CodecError::InvalidValue);
    };
    if index == 0 {
        return match *branch {
            Datum::Null => Ok(Value::Null),
            _ => Err(CodecError::InvalidValue),
        };
    }
    let (name, datum) = single_field(*branch)?;
    match (index, name.as_str(), datum) {
        (1, "boolean", Datum::Boolean(value)) => Ok(Value::Boolean(value)),
        (2, "long", Datum::Long(value)) => Ok(Value::Long(value)),
        (3, "double", Datum::Double(value)) if value.is_finite() => Ok(Value::Double(value)),
        (3, "double", Datum::Double(_)) => Err(CodecError::NonFiniteDouble),
        (4, "bytes", Datum::Bytes(value)) => Ok(Value::Bytes(value)),
        (5, "string", Datum::String(value)) => Ok(Value::String(value)),
        (6, "items", Datum::Array(values)) => values
            .into_iter()
            .map(from_datum)
            .collect::<Result<_, _>>()
            .map(Value::Array),
        (7, "entries", Datum::Map(values)) => values
            .into_iter()
            .map(|(key, value)| Ok((key, from_datum(value)?)))
            .collect::<Result<_, CodecError>>()
            .map(Value::Map),
        _ => Err(CodecError::InvalidValue),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn existing_shared_golden_fixture_selects_the_same_schema_and_wire() {
        let fixture: serde_json::Value = serde_json::from_str(include_str!(
            "../../tests/Fixtures/CodecRegression/avro-value-v1-long-zero.json"
        ))
        .unwrap();
        let codec = ValueCodec::new().unwrap();
        let wire = fixture["framing"]["wire_base64"].as_str().unwrap();
        assert_eq!(codec.encode(&Value::Long(0)).unwrap(), wire);
        assert_eq!(codec.decode(wire).unwrap(), Value::Long(0));
        assert_eq!(
            codec
                .schema
                .fingerprint::<apache_avro::rabin::Rabin>()
                .bytes,
            [0xe2, 0xa3, 0x3d, 0xff, 0x55, 0x80, 0x22, 0x37]
        );
    }

    #[test]
    fn retains_nested_types_and_full_signed_integer_range() {
        let codec = ValueCodec::new().unwrap();
        let values = Value::Array(vec![
            Value::Null,
            Value::Boolean(true),
            Value::Long(i64::MIN),
            Value::Long(i64::MAX),
            Value::Long(9_007_199_254_740_993),
            Value::Double(1.25),
            Value::Bytes(vec![0, 255]),
            Value::String("é".into()),
            Value::Array(vec![]),
            Value::Map(BTreeMap::new()),
            Value::Map(BTreeMap::from([("0".into(), Value::Bytes(vec![1]))])),
        ]);
        assert_eq!(
            codec.decode(&codec.encode(&values).unwrap()).unwrap(),
            values
        );
    }

    #[test]
    fn base64_follows_php_strict_decoding() {
        for accepted in ["QUJD", "QUI", "QUI=", "QQ", "QQ==", "QR==", "QU JD", "QUJD\nQUJD", ""] {
            assert!(php_base64(accepted).is_ok(), "{accepted:?}");
        }
        for refused in ["QUJD=", "QUI==", "QQ=", "QQ===", "QUJD====", "Q", "QUJD\tQ", "QU-D", "QU_D", "Q=UJD"] {
            assert!(php_base64(refused).is_err(), "{refused:?}");
        }
        let codec = ValueCodec::new().unwrap();
        let padded = codec.encode(&Value::Long(7)).unwrap();
        assert_eq!(codec.decode(padded.trim_end_matches('=')).unwrap(), Value::Long(7));
    }

    #[test]
    fn ingress_diagnosis_names_what_php_saw() {
        let codec = ValueCodec::new().unwrap();
        let good = codec.encode(&Value::Array(vec![Value::Long(1)])).unwrap();
        let diagnose = |blob: &str| ingress_diagnosis(blob, &codec.decode(blob).unwrap_err());
        assert!(diagnose("not-avro").starts_with("invalid_payload_framing: payload bytes look like JSON, not base64-encoded Avro. Remediation: Encode"));
        assert!(diagnose("@@@@").starts_with("invalid_payload_framing: failed to base64-decode Avro payload bytes."));
        assert!(diagnose("QUJD").starts_with("invalid_payload_framing: expected Avro single-object magic c301."));
        let mut bytes = STANDARD.decode(&good).unwrap();
        bytes[2..10].fill(0);
        assert!(diagnose(&STANDARD.encode(&bytes)).starts_with(
            "unsupported_payload_schema: unknown CRC-64-AVRO fingerprint 0000000000000000. Remediation: Upgrade"
        ));
        let mut trailing = STANDARD.decode(&good).unwrap();
        trailing.push(0);
        assert!(diagnose(&STANDARD.encode(&trailing)).starts_with("invalid_payload_framing: trailing bytes after Avro Value datum."));
        let truncated = STANDARD.decode(&good).unwrap();
        assert!(diagnose(&STANDARD.encode(&truncated[..truncated.len() - 1])).starts_with("invalid_payload_framing: truncated Avro Value datum."));
    }

    #[test]
    fn rejects_bad_base64_header_fingerprint_truncation_and_trailing_bytes() {
        let codec = ValueCodec::new().unwrap();
        let good = STANDARD
            .decode(codec.encode(&Value::Long(0)).unwrap())
            .unwrap();
        for length in 0..good.len() {
            assert!(
                codec.decode(&STANDARD.encode(&good[..length])).is_err(),
                "length {length}"
            );
        }
        for offset in 0..10 {
            let mut bad = good.clone();
            bad[offset] ^= 0xff;
            assert!(
                codec.decode(&STANDARD.encode(bad)).is_err(),
                "header {offset}"
            );
        }
        assert!(codec.decode("!invalid!").is_err());
        let mut trailing = good;
        trailing.push(0);
        assert!(matches!(
            codec.decode(&STANDARD.encode(trailing)),
            Err(CodecError::TrailingBytes)
        ));
    }

    #[test]
    fn rejects_non_finite_doubles_and_preserves_negative_zero_bits() {
        let codec = ValueCodec::new().unwrap();
        for value in [f64::NAN, f64::INFINITY, f64::NEG_INFINITY] {
            assert!(matches!(
                codec.encode(&Value::Double(value)),
                Err(CodecError::NonFiniteDouble)
            ));
        }
        let Value::Double(value) = codec
            .decode(&codec.encode(&Value::Double(-0.0)).unwrap())
            .unwrap()
        else {
            panic!("decoded a different type");
        };
        assert_eq!(value.to_bits(), (-0.0f64).to_bits());
        let mut infinite = STANDARD
            .decode(codec.encode(&Value::Double(0.0)).unwrap())
            .unwrap();
        infinite[11..].copy_from_slice(&f64::INFINITY.to_le_bytes());
        assert!(matches!(
            codec.decode(&STANDARD.encode(infinite)),
            Err(CodecError::NonFiniteDouble)
        ));
    }
}
