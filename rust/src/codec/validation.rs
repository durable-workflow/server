//! Validate an external datum through Apache's schema traversal without
//! constructing decoded arrays/maps. Scalar allocations remain library-owned.
use super::{CodecError, ValueCodec};
use base64::{
    alphabet,
    engine::{DecodePaddingMode, GeneralPurpose, GeneralPurposeConfig},
    read::DecoderReader,
};
use serde::{
    Deserialize, Deserializer,
    de::{self, MapAccess, SeqAccess, Visitor},
};
use std::{
    fmt,
    io::{self, BufRead, BufReader, Read},
};

struct Validated;
struct Key;
impl<'de> Deserialize<'de> for Key {
    fn deserialize<D: Deserializer<'de>>(deserializer: D) -> Result<Self, D::Error> {
        struct Identifier;
        impl<'de> Visitor<'de> for Identifier {
            type Value = Key;
            fn expecting(&self, formatter: &mut fmt::Formatter) -> fmt::Result {
                formatter.write_str("an Avro field or map key")
            }
            fn visit_str<E: de::Error>(self, _: &str) -> Result<Key, E> {
                Ok(Key)
            }
            fn visit_string<E: de::Error>(self, _: String) -> Result<Key, E> {
                Ok(Key)
            }
        }
        deserializer.deserialize_any(Identifier)
    }
}

impl<'de> Deserialize<'de> for Validated {
    fn deserialize<D: Deserializer<'de>>(deserializer: D) -> Result<Self, D::Error> {
        struct Validate;
        impl<'de> Visitor<'de> for Validate {
            type Value = Validated;
            fn expecting(&self, formatter: &mut fmt::Formatter) -> fmt::Result {
                formatter.write_str("a complete finite Avro Value")
            }
            fn visit_unit<E: de::Error>(self) -> Result<Validated, E> {
                Ok(Validated)
            }
            fn visit_bool<E: de::Error>(self, _: bool) -> Result<Validated, E> {
                Ok(Validated)
            }
            fn visit_i64<E: de::Error>(self, _: i64) -> Result<Validated, E> {
                Ok(Validated)
            }
            fn visit_f64<E: de::Error>(self, value: f64) -> Result<Validated, E> {
                if value.is_finite() {
                    Ok(Validated)
                } else {
                    Err(E::custom("non_finite_float"))
                }
            }
            fn visit_string<E: de::Error>(self, _: String) -> Result<Validated, E> {
                Ok(Validated)
            }
            fn visit_str<E: de::Error>(self, _: &str) -> Result<Validated, E> {
                Ok(Validated)
            }
            fn visit_byte_buf<E: de::Error>(self, _: Vec<u8>) -> Result<Validated, E> {
                Ok(Validated)
            }
            fn visit_seq<A: SeqAccess<'de>>(self, mut values: A) -> Result<Validated, A::Error> {
                while values.next_element::<Validated>()?.is_some() {}
                Ok(Validated)
            }
            fn visit_map<A: MapAccess<'de>>(self, mut values: A) -> Result<Validated, A::Error> {
                while values.next_entry::<Key, Validated>()?.is_some() {}
                Ok(Validated)
            }
        }
        deserializer.deserialize_ignored_any(Validate)
    }
}

struct WhitespaceReader<R: Read>(BufReader<R>);
impl<R: Read> Read for WhitespaceReader<R> {
    fn read(&mut self, output: &mut [u8]) -> io::Result<usize> {
        let mut count = 0;
        while count < output.len() {
            let input = self.0.fill_buf()?;
            if input.is_empty() {
                break;
            }
            let mut consumed = 0;
            for byte in input {
                consumed += 1;
                if !b"\r\n\t ".contains(byte) {
                    output[count] = *byte;
                    count += 1;
                    if count == output.len() {
                        break;
                    }
                }
            }
            self.0.consume(consumed);
        }
        Ok(count)
    }
}

impl ValueCodec {
    /// The external PHP boundary permits whitespace, omitted padding and
    /// noncanonical padding bits. Integrity measures original encoded bytes;
    /// this adapter never rewrites the stored object or its reference hash.
    pub fn validate_external(&self, source: impl Read) -> Result<(), CodecError> {
        let engine = GeneralPurpose::new(
            &alphabet::STANDARD,
            GeneralPurposeConfig::new()
                .with_decode_padding_mode(DecodePaddingMode::Indifferent)
                .with_decode_allow_trailing_bits(true),
        );
        let source = WhitespaceReader(BufReader::with_capacity(8192, source));
        let mut decoded = DecoderReader::new(source, &engine);
        self.reader.read_deser::<Validated>(&mut decoded)?;
        let mut trailing = [0_u8; 1];
        match decoded.read(&mut trailing) {
            Ok(0) => Ok(()),
            Ok(_) => Err(CodecError::TrailingBytes),
            Err(_) => Err(CodecError::InvalidValue),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::codec::Value;
    use base64::{Engine, engine::general_purpose::STANDARD};
    use std::collections::BTreeMap;

    #[test]
    fn schema_traversal_validates_nested_types_and_external_base64_without_collections() {
        let codec = ValueCodec::new().unwrap();
        let values = Value::Array(vec![
            Value::Null,
            Value::Boolean(false),
            Value::Long(i64::MAX),
            Value::Double(-0.0),
            Value::Bytes(vec![0, 255]),
            Value::String("rangeλ".into()),
            Value::Map(BTreeMap::from([("0".into(), Value::Array(vec![]))])),
        ]);
        let blob = codec.encode(&values).unwrap();
        codec.validate_external(blob.as_bytes()).unwrap();
        let spaced = blob
            .as_bytes()
            .chunks(3)
            .map(|chunk| std::str::from_utf8(chunk).unwrap())
            .collect::<Vec<_>>()
            .join(" \r\n\t");
        codec.validate_external(spaced.as_bytes()).unwrap();
        codec
            .validate_external(blob.trim_end_matches('=').as_bytes())
            .unwrap();
    }

    #[test]
    fn malformed_external_frames_do_not_validate() {
        let codec = ValueCodec::new().unwrap();
        let bytes = STANDARD
            .decode(codec.encode(&Value::Long(0)).unwrap())
            .unwrap();
        for length in 0..bytes.len() {
            assert!(
                codec
                    .validate_external(STANDARD.encode(&bytes[..length]).as_bytes())
                    .is_err(),
                "{length}"
            );
        }
        let mut trailing = bytes.clone();
        trailing.push(0);
        assert!(
            codec
                .validate_external(STANDARD.encode(trailing).as_bytes())
                .is_err()
        );
        assert!(codec.validate_external(&b"invalid@"[..]).is_err());
        let mut boolean = bytes[..10].to_vec();
        boolean.extend([2, 2]);
        assert!(
            codec
                .validate_external(STANDARD.encode(boolean).as_bytes())
                .is_err()
        );
        let mut nonfinite = bytes[..10].to_vec();
        nonfinite.push(6);
        nonfinite.extend(f64::NAN.to_le_bytes());
        assert!(
            codec
                .validate_external(STANDARD.encode(nonfinite).as_bytes())
                .is_err()
        );
        let mut utf8 = bytes[..10].to_vec();
        utf8.extend([10, 2, 255]);
        assert!(
            codec
                .validate_external(STANDARD.encode(utf8).as_bytes())
                .is_err()
        );
    }

    #[test]
    fn external_negative_collection_blocks_keep_published_length_validation() {
        let codec = ValueCodec::new().unwrap();
        let header = STANDARD.decode("wwHioz3/VYAiNw==").unwrap();
        let mut exact = header.clone();
        exact.extend([12, 1, 4, 4, 0, 0]);
        codec
            .validate_external(STANDARD.encode(exact).as_bytes())
            .unwrap();
        for size in [1, 2, 6] {
            let mut bytes = header.clone();
            bytes.extend([12, 1, size, 4, 0, 0]);
            assert!(
                codec
                    .validate_external(STANDARD.encode(bytes).as_bytes())
                    .is_err(),
                "declared block size byte {size}"
            );
        }
    }
}
