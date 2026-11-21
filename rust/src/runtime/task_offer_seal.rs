//! The authenticated packet format shared by task offer issuance and acceptance.
use base64::{
    Engine,
    engine::general_purpose::{STANDARD, URL_SAFE_NO_PAD},
};
use chacha20poly1305::{AeadInOut, KeyInit, Tag, XChaCha20Poly1305, XNonce};
use serde::{Deserialize, Deserializer, Serialize};
use sha2::{Digest, Sha256};
use zeroize::Zeroizing;

const SETTING: &str = "DW_TASK_DELIVERY_SEAL_KEYS";
const KEY_SCHEMA: &str = "durable-workflow.task-delivery-seal-keys.v1";
pub(super) const PACKET_SCHEMA: &str = "durable-workflow.task-delivery-sealed-context.v1";
const FRAME: &str = "dwo1";
const PROTOCOL: &str = "offer-accept.v1";
const MAX_CONFIG_BYTES: usize = 4096;
const MAX_TOKEN_BYTES: usize = 8192;
const MAX_PLAINTEXT_BYTES: usize = 6000;
const NONCE_BYTES: usize = 24;
const TAG_BYTES: usize = 16;

#[derive(Debug, PartialEq, Eq)]
pub(super) enum SealConfigError {
    Text,
    Container,
    KeyId,
    KeyText,
}

impl SealConfigError {
    pub(super) fn reason(&self) -> &'static str {
        match self {
            Self::Text => "DW_TASK_DELIVERY_SEAL_KEYS: invalid_config_text",
            Self::Container => "DW_TASK_DELIVERY_SEAL_KEYS: invalid_key_container",
            Self::KeyId => "DW_TASK_DELIVERY_SEAL_KEYS: invalid_key_id",
            Self::KeyText => "DW_TASK_DELIVERY_SEAL_KEYS: invalid_key_text",
        }
    }
}

#[derive(Debug, PartialEq, Eq, thiserror::Error)]
pub(super) enum SealError {
    #[error("invalid_task_offer_token")]
    Invalid,
    #[error("task_offer_nonce_unavailable")]
    Nonce,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct Config {
    schema: String,
    keys: Vec<ConfigKey>,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct ConfigKey {
    id: String,
    #[serde(deserialize_with = "secret_text")]
    key_base64: Zeroizing<String>,
}

fn secret_text<'de, D: Deserializer<'de>>(deserializer: D) -> Result<Zeroizing<String>, D::Error> {
    String::deserialize(deserializer).map(Zeroizing::new)
}

fn valid_key_id(id: &str) -> bool {
    (1..=32).contains(&id.len())
        && id
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'-' | b'_'))
}

fn valid_static_text(value: &str) -> bool {
    value.trim() == value && !value.chars().any(char::is_control)
}

fn valid_key_text(value: &str) -> bool {
    valid_static_text(value)
        && value.len() == 44
        && value.as_bytes()[43] == b'='
        && value.as_bytes()[..43]
            .iter()
            .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'+' | b'/'))
}

struct SealKey {
    id: String,
    material: Zeroizing<[u8; 32]>,
}

impl SealKey {
    fn fingerprint(&self) -> [u8; 32] {
        let mut digest = Sha256::new();
        digest.update(b"durable-workflow.task-delivery-key-fingerprint.v1");
        digest.update(self.material.as_ref());
        digest.finalize().into()
    }
}

pub(super) struct KeyRing(Vec<SealKey>);

#[derive(Clone, Copy, PartialEq, Eq, PartialOrd, Ord, Deserialize, Serialize)]
#[serde(rename_all = "snake_case")]
pub(super) enum TaskKind {
    Activity,
    Query,
    UpdateValidation,
    Workflow,
}

impl TaskKind {
    pub(super) fn from_wire(kind: &str) -> super::Result<Self> {
        match kind {
            "activity" => Ok(Self::Activity),
            "query" => Ok(Self::Query),
            "update_validation" => Ok(Self::UpdateValidation),
            "workflow" => Ok(Self::Workflow),
            _ => Err(super::refuse(
                axum::http::StatusCode::CONFLICT,
                "task_offer_scope_mismatch",
            )),
        }
    }

    pub(super) fn as_wire(&self) -> &'static str {
        match self {
            Self::Activity => "activity",
            Self::Query => "query",
            Self::UpdateValidation => "update_validation",
            Self::Workflow => "workflow",
        }
    }
}

#[derive(PartialEq, Eq, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub(super) struct PacketContext {
    pub(super) schema: String,
    pub(super) namespace: String,
    pub(super) worker_id: String,
    pub(super) queue: String,
    pub(super) endpoint: TaskKind,
    pub(super) request_id: String,
    pub(super) requested_kinds: Vec<TaskKind>,
    pub(super) registration_digest: [u8; 32],
    pub(super) routing_digest: [u8; 32],
    pub(super) eligibility_digest: [u8; 32],
    pub(super) task_id: String,
    pub(super) task_kind: TaskKind,
    pub(super) observed_attempt: i64,
    pub(super) expires_at_ms: i64,
}

impl PacketContext {
    fn valid(&self) -> bool {
        self.schema == PACKET_SCHEMA
            && [
                &self.namespace,
                &self.worker_id,
                &self.queue,
                &self.request_id,
                &self.task_id,
            ]
            .into_iter()
            .all(|field| !field.is_empty())
            && self.expires_at_ms > 0
            && (0..i64::MAX).contains(&self.observed_attempt)
            && self.requested_kinds.contains(&self.task_kind)
            && self
                .requested_kinds
                .windows(2)
                .all(|pair| pair[0] < pair[1])
            && self.requested_kinds.iter().all(|kind| match self.endpoint {
                TaskKind::Workflow => {
                    matches!(kind, TaskKind::Workflow | TaskKind::UpdateValidation)
                }
                endpoint => endpoint == *kind,
            })
    }
}

pub(super) struct OpenedToken {
    pub(super) context: PacketContext,
    pub(super) key_id: String,
    pub(super) packet_digest: [u8; 32],
}

pub(super) fn authority_digest(
    domain: &str,
    authority: &impl Serialize,
) -> super::Result<[u8; 32]> {
    Ok(Sha256::digest(serde_json::to_vec(&(domain, authority))?).into())
}

fn associated_data(id: &str) -> Vec<u8> {
    let mut data = Vec::new();
    for field in [FRAME, PACKET_SCHEMA, PROTOCOL, id] {
        data.push(field.len() as u8);
        data.extend_from_slice(field.as_bytes());
    }
    data
}

impl KeyRing {
    pub(super) fn from_env() -> Result<Option<Self>, SealConfigError> {
        match std::env::var(SETTING) {
            Ok(value) => Self::parse(&Zeroizing::new(value)).map(Some),
            Err(std::env::VarError::NotPresent) => Ok(None),
            Err(std::env::VarError::NotUnicode(_)) => Err(SealConfigError::Text),
        }
    }

    pub(super) fn parse(text: &str) -> Result<Self, SealConfigError> {
        if text.is_empty() || text.len() > MAX_CONFIG_BYTES {
            return Err(SealConfigError::Text);
        }
        let config: Config = serde_json::from_str(text).map_err(|_| SealConfigError::Container)?;
        if config.schema != KEY_SCHEMA || !(1..=4).contains(&config.keys.len()) {
            return Err(SealConfigError::Container);
        }
        for (index, key) in config.keys.iter().enumerate() {
            if !valid_key_id(&key.id) || config.keys[..index].iter().any(|old| old.id == key.id) {
                return Err(SealConfigError::KeyId);
            }
            if !valid_key_text(&key.key_base64) {
                return Err(SealConfigError::KeyText);
            }
        }
        let keys = config
            .keys
            .into_iter()
            .map(|key| {
                let mut bytes = Zeroizing::new([0; 33]);
                let size = STANDARD
                    .decode_slice(key.key_base64.as_bytes(), &mut *bytes)
                    .map_err(|_| SealConfigError::KeyText)?;
                if size != 32 {
                    return Err(SealConfigError::KeyText);
                }
                let canonical = Zeroizing::new(STANDARD.encode(&bytes[..size]));
                if canonical.as_str() != key.key_base64.as_str() {
                    return Err(SealConfigError::KeyText);
                }
                let mut material = Zeroizing::new([0; 32]);
                material.copy_from_slice(&bytes[..size]);
                Ok(SealKey {
                    id: key.id,
                    material,
                })
            })
            .collect::<Result<Vec<_>, _>>()?;
        Ok(Self(keys))
    }

    pub(super) fn key_ids(&self) -> impl Iterator<Item = &str> {
        self.0.iter().map(|key| key.id.as_str())
    }

    pub(super) fn key_fingerprint(&self, id: &str) -> Option<[u8; 32]> {
        self.0
            .iter()
            .find(|key| key.id == id)
            .map(|key| key.fingerprint())
    }

    pub(super) fn issuer_identity(&self) -> (&str, [u8; 32]) {
        let issuer = &self.0[0];
        (&issuer.id, issuer.fingerprint())
    }

    pub(super) fn seal(&self, context: &PacketContext) -> Result<String, SealError> {
        self.seal_with_nonce(context, |bytes| {
            getrandom::fill(bytes).map_err(|_| SealError::Nonce)
        })
    }

    fn seal_with_nonce(
        &self,
        context: &PacketContext,
        fill: impl FnOnce(&mut [u8; NONCE_BYTES]) -> Result<(), SealError>,
    ) -> Result<String, SealError> {
        if !context.valid() {
            return Err(SealError::Invalid);
        }
        let mut plaintext =
            Zeroizing::new(serde_json::to_vec(context).map_err(|_| SealError::Invalid)?);
        if plaintext.len() > MAX_PLAINTEXT_BYTES {
            return Err(SealError::Invalid);
        }
        let mut nonce = XNonce::default();
        fill(nonce.as_mut())?;
        let key = &self.0[0];
        let cipher = XChaCha20Poly1305::new_from_slice(key.material.as_ref())
            .map_err(|_| SealError::Invalid)?;
        let tag = cipher
            .encrypt_inout_detached(
                &nonce,
                &associated_data(&key.id),
                plaintext.as_mut_slice().into(),
            )
            .map_err(|_| SealError::Invalid)?;
        let mut packet = Vec::with_capacity(NONCE_BYTES + plaintext.len() + TAG_BYTES);
        packet.extend_from_slice(&nonce);
        packet.extend_from_slice(&plaintext);
        packet.extend_from_slice(&tag);
        Ok(format!(
            "{FRAME}.{}.{}",
            key.id,
            URL_SAFE_NO_PAD.encode(packet)
        ))
    }

    pub(super) fn open(&self, token: &str) -> Result<OpenedToken, SealError> {
        if token.len() > MAX_TOKEN_BYTES || !token.is_ascii() {
            return Err(SealError::Invalid);
        }
        let mut parts = token.split('.');
        let (Some(FRAME), Some(id), Some(encoded), None) =
            (parts.next(), parts.next(), parts.next(), parts.next())
        else {
            return Err(SealError::Invalid);
        };
        if !valid_key_id(id) || encoded.is_empty() {
            return Err(SealError::Invalid);
        }
        let key = self
            .0
            .iter()
            .find(|key| key.id == id)
            .ok_or(SealError::Invalid)?;
        let mut packet = Zeroizing::new(
            URL_SAFE_NO_PAD
                .decode(encoded)
                .map_err(|_| SealError::Invalid)?,
        );
        if !(NONCE_BYTES + TAG_BYTES..=NONCE_BYTES + MAX_PLAINTEXT_BYTES + TAG_BYTES)
            .contains(&packet.len())
            || URL_SAFE_NO_PAD.encode(packet.as_slice()) != encoded
        {
            return Err(SealError::Invalid);
        }
        let (nonce, tagged) = packet.split_at_mut(NONCE_BYTES);
        let (encrypted, tag) = tagged.split_at_mut(tagged.len() - TAG_BYTES);
        let nonce = XNonce::try_from(&*nonce).map_err(|_| SealError::Invalid)?;
        let tag = Tag::try_from(&*tag).map_err(|_| SealError::Invalid)?;
        let cipher = XChaCha20Poly1305::new_from_slice(key.material.as_ref())
            .map_err(|_| SealError::Invalid)?;
        cipher
            .decrypt_inout_detached(&nonce, &associated_data(id), encrypted.into(), &tag)
            .map_err(|_| SealError::Invalid)?;

        let context: PacketContext =
            serde_json::from_slice(encrypted).map_err(|_| SealError::Invalid)?;
        let canonical =
            Zeroizing::new(serde_json::to_vec(&context).map_err(|_| SealError::Invalid)?);
        if !context.valid() || canonical.as_slice() != encrypted {
            return Err(SealError::Invalid);
        }
        Ok(OpenedToken {
            context,
            key_id: id.to_owned(),
            packet_digest: Sha256::digest(token.as_bytes()).into(),
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn context() -> PacketContext {
        PacketContext {
            schema: PACKET_SCHEMA.to_owned(),
            namespace: "test".to_owned(),
            worker_id: "worker".to_owned(),
            queue: "tasks".to_owned(),
            endpoint: TaskKind::Workflow,
            request_id: "poll".to_owned(),
            requested_kinds: vec![TaskKind::UpdateValidation, TaskKind::Workflow],
            registration_digest: [1; 32],
            routing_digest: [2; 32],
            eligibility_digest: [3; 32],
            task_id: "validation".to_owned(),
            task_kind: TaskKind::UpdateValidation,
            observed_attempt: 0,
            expires_at_ms: 20_000,
        }
    }

    fn keys(ids: &[&str]) -> serde_json::Value {
        let keys: Vec<_> = ids
            .iter()
            .enumerate()
            .map(|(index, id)| json!({"id": id, "key_base64": STANDARD.encode([index as u8; 32])}))
            .collect();
        json!({"schema": KEY_SCHEMA, "keys": keys})
    }

    #[test]
    fn seal_roundtrip_enforces_framing_key_id_and_os_rng() {
        let mut config = keys(&["now", "alias"]);
        config["keys"][1]["key_base64"] = config["keys"][0]["key_base64"].clone();
        let ring = KeyRing::parse(&config.to_string()).unwrap();
        let context = context();
        let first = ring.seal(&context).unwrap();
        let second = ring.seal(&context).unwrap();
        assert!(first != second);
        let opened = ring.open(&first).unwrap();
        assert!(opened.context == context && opened.key_id == "now");
        assert_eq!(
            opened.packet_digest,
            <[u8; 32]>::from(Sha256::digest(first.as_bytes()))
        );
        let wrong_id = first.replacen(".now.", ".alias.", 1);
        for invalid in [
            wrong_id,
            format!("{first}="),
            format!("{first}."),
            format!("{first} "),
            format!(".{first}"),
        ] {
            assert!(matches!(ring.open(&invalid), Err(SealError::Invalid)));
        }
        assert!(matches!(
            ring.open(&"X".repeat(MAX_TOKEN_BYTES + 1)),
            Err(SealError::Invalid)
        ));
        assert!(matches!(
            ring.seal_with_nonce(&context, |_| Err(SealError::Nonce)),
            Err(SealError::Nonce)
        ));
        let fixed_nonce = |bytes: &mut [u8; NONCE_BYTES]| {
            bytes.fill(7);
            Ok(())
        };
        let fixed_first = ring.seal_with_nonce(&context, fixed_nonce).unwrap();
        let fixed_second = ring.seal_with_nonce(&context, fixed_nonce).unwrap();
        assert!(fixed_first == fixed_second);
    }

    #[test]
    fn config_validates_original_text_before_key_selection() {
        let fixture: serde_json::Value = serde_json::from_str(include_str!(
            "../../../tests/Fixtures/AuthConfiguration/static-credentials-v1.json"
        ))
        .unwrap();
        for case in fixture["common_scalar_cases"].as_array().unwrap() {
            let input: String = case["input_code_points"]
                .as_array()
                .unwrap()
                .iter()
                .map(|point| {
                    char::from_u32(u32::try_from(point.as_u64().unwrap()).unwrap()).unwrap()
                })
                .collect();
            assert_eq!(
                valid_static_text(&input),
                case["expected"] == "preserve",
                "{}",
                case["id"]
            );
        }
        let mut invalid = keys(&["now", "readonly"]);
        invalid["keys"][1]["key_base64"] =
            json!(" ".to_owned() + invalid["keys"][1]["key_base64"].as_str().unwrap());
        assert!(matches!(
            KeyRing::parse(&invalid.to_string()),
            Err(SealConfigError::KeyText)
        ));
        let duplicate = keys(&["same", "same"]);
        assert!(matches!(
            KeyRing::parse(&duplicate.to_string()),
            Err(SealConfigError::KeyId)
        ));
        let mut unknown = keys(&["now"]);
        unknown["keys"][0]["extra"] = json!(false);
        assert!(matches!(
            KeyRing::parse(&unknown.to_string()),
            Err(SealConfigError::Container)
        ));
        let duplicate_field =
            keys(&["now"])
                .to_string()
                .replacen(r#""id":"now""#, r#""id":"now","id":"now""#, 1);
        assert!(matches!(
            KeyRing::parse(&duplicate_field),
            Err(SealConfigError::Container)
        ));
        assert!(matches!(
            KeyRing::parse(&" ".repeat(MAX_CONFIG_BYTES + 1)),
            Err(SealConfigError::Text)
        ));
    }
}
