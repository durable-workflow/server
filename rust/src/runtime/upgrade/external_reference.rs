//! Read PHP's stored reference metadata without fetching or adopting an object.
//! Recognizing this representation does not bind its registry/provider state,
//! verify its bytes or authorize conversion or takeover.
use base64::{Engine, engine::general_purpose::STANDARD};
use serde::Deserialize;
use serde_json::Value;

const PHP_PREFIX: &str = "dw-external-payload:v1:";
const MAX_STORED_BYTES: usize = 64 * 1024;
const REFERENCE_SCHEMA: &str = "durable-workflow.v2.external-payload-reference.v1";

/// Errors contain no URI, encoded envelope or provider-controlled data.
#[derive(Debug, PartialEq, Eq)]
pub enum ReferenceDecodeError {
    TooLarge,
    UnsupportedFormat,
    InvalidBase64,
    InvalidEnvelope,
    CodecMismatch,
    InvalidReference,
}

// Deliberately no Debug/Serialize: inspection reports must not expose locations.
#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct PhpStoredEnvelope {
    codec: String,
    external_storage: PhpStoredReference,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
pub struct PhpStoredReference {
    schema: String,
    uri: String,
    sha256: String,
    size_bytes: i64,
    codec: String,
}

impl PhpStoredEnvelope {
    pub fn codec(&self) -> &str {
        &self.codec
    }

    pub fn reference(&self) -> &PhpStoredReference {
        &self.external_storage
    }
}

impl PhpStoredReference {
    pub fn uri(&self) -> &str {
        &self.uri
    }

    pub fn sha256(&self) -> &str {
        &self.sha256
    }

    pub fn size_bytes(&self) -> i64 {
        self.size_bytes
    }
}

/// `None` means only that this string is not a recognized reference prefix.
/// Inline Avro validation is a separate obligation. Unknown reference versions
/// and native opaque references refuse instead of masquerading as inline data.
pub fn inspect_php_reference(
    stored: &str,
    row_codec: Option<&str>,
) -> Result<Option<PhpStoredEnvelope>, ReferenceDecodeError> {
    let Some(encoded) = stored.strip_prefix(PHP_PREFIX) else {
        return if stored.starts_with("dw-external-payload:")
            || stored.starts_with("dw-native-external-payload-")
        {
            Err(ReferenceDecodeError::UnsupportedFormat)
        } else {
            Ok(None)
        };
    };
    if stored.len() > MAX_STORED_BYTES {
        return Err(ReferenceDecodeError::TooLarge);
    }
    let decoded = STANDARD
        .decode(encoded)
        .map_err(|_| ReferenceDecodeError::InvalidBase64)?;
    // Serde structs can also accept positional sequences. PHP's stored format
    // requires two JSON objects; check that shape without using the projection
    // for the typed decode, so duplicate fields still fail on the original JSON.
    let shape: Value =
        serde_json::from_slice(&decoded).map_err(|_| ReferenceDecodeError::InvalidEnvelope)?;
    if !shape.is_object() || !shape["external_storage"].is_object() {
        return Err(ReferenceDecodeError::InvalidEnvelope);
    }
    let envelope: PhpStoredEnvelope =
        serde_json::from_slice(&decoded).map_err(|_| ReferenceDecodeError::InvalidEnvelope)?;
    let reference = &envelope.external_storage;
    if envelope.codec != "avro"
        || reference.codec != "avro"
        || row_codec.is_some_and(|codec| !codec.is_empty() && codec != "avro")
    {
        return Err(ReferenceDecodeError::CodecMismatch);
    }
    if reference.schema != REFERENCE_SCHEMA
        || reference.uri.is_empty()
        || reference.size_bytes < 0
        || reference.sha256.len() != 64
        || !reference
            .sha256
            .bytes()
            .all(|byte| byte.is_ascii_hexdigit())
    {
        return Err(ReferenceDecodeError::InvalidReference);
    }
    Ok(Some(envelope))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn fixture() -> Value {
        serde_json::from_str(include_str!(
            "../../../../tests/Fixtures/ServerUpgrade/php-2.5.20-external-references.json"
        ))
        .unwrap()
    }

    fn stored(json: &str) -> String {
        format!("{PHP_PREFIX}{}", STANDARD.encode(json))
    }

    fn error(input: &str, codec: Option<&str>, expected: ReferenceDecodeError) {
        match inspect_php_reference(input, codec) {
            Err(actual) => assert_eq!(actual, expected),
            Ok(_) => panic!("unqualified stored reference accepted"),
        }
    }

    #[test]
    fn released_php_serializer_preserves_original_reference_fields() {
        for case in fixture()["cases"].as_array().unwrap() {
            let original = case["stored"].as_str().unwrap();
            for row_codec in [None, Some(""), Some("avro")] {
                let decoded = inspect_php_reference(original, row_codec).unwrap().unwrap();
                let expected = &case["envelope"]["external_storage"];
                assert_eq!(decoded.codec(), "avro");
                assert_eq!(decoded.reference().uri(), expected["uri"].as_str().unwrap());
                assert_eq!(
                    decoded.reference().sha256(),
                    expected["sha256"].as_str().unwrap()
                );
                assert_eq!(
                    decoded.reference().size_bytes(),
                    expected["size_bytes"].as_i64().unwrap()
                );
            }
            error(original, Some("json"), ReferenceDecodeError::CodecMismatch);
        }
    }

    #[test]
    fn reference_versions_and_oversized_metadata_never_become_inline_data() {
        assert!(
            inspect_php_reference("inline-avro-is-validated-elsewhere", None)
                .unwrap()
                .is_none()
        );
        for original in [
            "dw-external-payload:v2:any",
            "dw-native-external-payload-v1:{}",
        ] {
            error(original, None, ReferenceDecodeError::UnsupportedFormat);
        }
        error(
            &format!("{PHP_PREFIX}{}", "A".repeat(MAX_STORED_BYTES)),
            None,
            ReferenceDecodeError::TooLarge,
        );
        error(
            &format!("{PHP_PREFIX}%%%"),
            None,
            ReferenceDecodeError::InvalidBase64,
        );
    }

    #[test]
    fn duplicate_unknown_positional_and_trailing_fields_refuse_without_projection() {
        let envelope = fixture()["cases"][0]["envelope"].to_string();
        let reference = fixture()["cases"][0]["envelope"]["external_storage"].to_string();
        for json in [
            "null".to_string(),
            format!("[\"avro\",{reference}]"),
            format!("{{\"codec\":\"avro\",\"codec\":\"avro\",\"external_storage\":{reference}}}"),
            envelope.replacen("\"uri\":", "\"uri\":\"foreign\",\"uri\":", 1),
            envelope.replacen("{", "{\"extra\":true,", 1),
            envelope.replacen(
                "\"external_storage\":{",
                "\"external_storage\":{\"extra\":true,",
                1,
            ),
            format!("{envelope}{{}}"),
            "{\"codec\":\"avro\",\"external_storage\":[]}".to_string(),
        ] {
            error(&stored(&json), None, ReferenceDecodeError::InvalidEnvelope);
        }
    }

    #[test]
    fn exact_codec_integer_digest_and_original_uri_remain_authoritative() {
        let original = fixture()["cases"][0]["envelope"].clone();
        for (field, value, expected) in [
            (
                "schema",
                Value::String("foreign".into()),
                ReferenceDecodeError::InvalidReference,
            ),
            (
                "uri",
                Value::String("".into()),
                ReferenceDecodeError::InvalidReference,
            ),
            (
                "size_bytes",
                Value::from(-1),
                ReferenceDecodeError::InvalidReference,
            ),
            (
                "size_bytes",
                Value::String("14".into()),
                ReferenceDecodeError::InvalidEnvelope,
            ),
            (
                "size_bytes",
                serde_json::json!(14.0),
                ReferenceDecodeError::InvalidEnvelope,
            ),
            (
                "sha256",
                Value::String("A".repeat(63)),
                ReferenceDecodeError::InvalidReference,
            ),
            (
                "sha256",
                Value::String("g".repeat(64)),
                ReferenceDecodeError::InvalidReference,
            ),
            (
                "codec",
                Value::String("json".into()),
                ReferenceDecodeError::CodecMismatch,
            ),
        ] {
            let mut envelope = original.clone();
            envelope["external_storage"][field] = value;
            error(&stored(&envelope.to_string()), None, expected);
        }
        let mut envelope = original;
        envelope["external_storage"]["sha256"] = Value::String("A".repeat(64));
        envelope["external_storage"]["size_bytes"] = Value::from(i64::MAX);
        let decoded = inspect_php_reference(&stored(&envelope.to_string()), None)
            .unwrap()
            .unwrap();
        assert_eq!(decoded.reference().sha256(), "A".repeat(64));
        assert_eq!(decoded.reference().size_bytes(), i64::MAX);
        envelope["external_storage"]["size_bytes"] = serde_json::json!(9223372036854775808_u64);
        error(
            &stored(&envelope.to_string()),
            None,
            ReferenceDecodeError::InvalidEnvelope,
        );
    }
}
