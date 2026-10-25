//! Verified state-bearing references remain opaque in durable native state.
use super::{Result, Runtime, refuse};
use crate::external_payload::{Reference, Snapshot};
use axum::http::StatusCode;
use serde_json::{Value, json};
use std::{collections::BTreeMap, sync::Arc};
use tokio::sync::OwnedSemaphorePermit;

pub(super) const STORED_PREFIX: &str = "dw-native-external-payload-v1:";

pub(crate) struct PreparedExternal {
    pub(super) reference: Reference,
    pub(super) uri: String,
    // Keep verified bytes and bounded transfer capacity until state commits.
    _snapshot: Snapshot,
}
pub(super) type PreparedCommands = BTreeMap<usize, PreparedExternal>;

impl PreparedExternal {
    /// The verified payload: the Avro blob the reference stands for, read
    /// once to validate it before the reference alone is stored.
    pub(super) fn blob(&mut self) -> Result<String> {
        use std::io::Read;
        let mut blob = String::new();
        self._snapshot
            .read_to_string(&mut blob)
            .map_err(|_| refuse(StatusCode::UNPROCESSABLE_ENTITY, "external_payload_unsupported"))?;
        self._snapshot.rewind().map_err(super::external_transport::snapshot_error)?;
        Ok(blob.trim().to_owned())
    }

    pub(super) fn stored(&self) -> String {
        format!(
            "{STORED_PREFIX}{}",
            serde_json::to_string(&self.reference).expect("reference JSON")
        )
    }
}

/// `WorkflowController::oversizedPayloadPreview` over a stored command
/// payload: an external reference, native's or one PHP stored, whose size is
/// above `max_payload_bytes`. Only the small reference is read.
pub(super) fn oversized_reference(payload: &str) -> bool {
    use base64::{Engine, engine::general_purpose::STANDARD};
    let size = if let Some(json) = payload.strip_prefix(STORED_PREFIX) {
        serde_json::from_str::<Value>(json).ok().and_then(|reference| reference["size_bytes"].as_u64())
    } else if let Some(encoded) = payload.strip_prefix(super::history_presentation::PHP_EXTERNAL_REFERENCE) {
        STANDARD.decode(encoded).ok()
            .and_then(|json| serde_json::from_slice::<Value>(&json).ok())
            .and_then(|envelope| envelope["external_storage"]["size_bytes"].as_u64())
    } else {
        None
    };
    size.is_some_and(|size| size > super::payload_limits::max_payload_bytes() as u64)
}

pub(super) fn stored_envelope(blob: &str) -> Option<Value> {
    let value = serde_json::from_str(blob.strip_prefix(STORED_PREFIX)?).ok()?;
    let reference = Reference::validate(&value).ok()?;
    Some(json!({"codec":"avro","external_payload":reference}))
}

impl Runtime {
    pub(super) async fn prepare_external(&self, value: &Value) -> Result<Option<PreparedExternal>> {
        self.prepare_external_with_permit(value, None).await
    }

    pub(super) async fn prepare_command_payloads(&self, body: &Value) -> Result<PreparedCommands> {
        let commands = body["commands"]
            .as_array()
            .filter(|commands| !commands.is_empty() && commands.len() <= 100)
            .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_commands"))?;
        let selected: Vec<_> = commands
            .iter()
            .enumerate()
            .filter_map(|(index, command)| {
                let value = match command["type"].as_str() {
                    Some("complete_workflow") => &command["result"],
                    Some("schedule_activity") => &command["arguments"],
                    _ => return None,
                };
                (value.get("external_payload").is_some() || value.get("external_storage").is_some())
                    .then_some((index, value))
            })
            .collect();
        if selected.is_empty() {
            return Ok(BTreeMap::new());
        }
        let prepare = async {
            let permit = Arc::new(
                self.external
                    .workers
                    .clone()
                    .acquire_owned()
                    .await
                    .map_err(|_| {
                        refuse(
                            StatusCode::SERVICE_UNAVAILABLE,
                            "external_payload_unavailable",
                        )
                    })?,
            );
            let mut prepared = BTreeMap::new();
            for (index, value) in selected {
                let payload = self
                    .prepare_external_with_permit(value, Some(permit.clone()))
                    .await?
                    .expect("selected external envelope");
                prepared.insert(index, payload);
            }
            Ok(prepared)
        };
        // One original deadline covers capacity admission and every reference.
        tokio::time::timeout(self.external.timeout, prepare)
            .await
            .map_err(|_| {
                refuse(
                    StatusCode::SERVICE_UNAVAILABLE,
                    "external_payload_unavailable",
                )
            })?
    }

    async fn prepare_external_with_permit(
        &self,
        value: &Value,
        permit: Option<Arc<OwnedSemaphorePermit>>,
    ) -> Result<Option<PreparedExternal>> {
        if value.get("external_storage").is_some() {
            return Err(super::external_transport::provider_reference());
        }
        let Some(reference) = value.get("external_payload") else {
            return Ok(None);
        };
        // `incomingEnvelope`: exactly codec and external_payload; the codec
        // is compared with the registered reference after its lookup.
        if value.as_object().is_none_or(|map| map.len() != 2 || !map.contains_key("codec")) {
            return Err(super::external_transport::typed_error(StatusCode::UNPROCESSABLE_ENTITY, "external_payload_unsupported",
                "External payload envelopes must contain exactly codec and external_payload."));
        }
        // `RuntimeExternalPayloadReference::validate`'s message.
        let reference = Reference::validate(reference).map_err(|error| {
            super::external_transport::typed_error(StatusCode::UNPROCESSABLE_ENTITY, "external_payload_unsupported", error.to_string())
        })?;
        if reference.size_bytes as u64 > self.external.max_bytes.max(1) {
            return Err(refuse(
                StatusCode::PAYLOAD_TOO_LARGE,
                "external_payload_oversized",
            ));
        }
        let prepare = async {
            let registered = self.external_payload_for_fetch(&reference).await?;
            if value["codec"] != reference.codec {
                return Err(refuse(
                    StatusCode::UNPROCESSABLE_ENTITY,
                    "external_payload_integrity_mismatch",
                ));
            }
            let storage = super::external_transport::local(self).await?;
            let snapshot = match permit {
                Some(permit) => {
                    storage
                        .fetch_with_permit(
                            reference.clone(),
                            &registered.uri,
                            self.external.max_bytes,
                            std::env::temp_dir(),
                            permit,
                        )
                        .await
                }
                None => {
                    storage
                        .fetch(
                            reference.clone(),
                            &registered.uri,
                            self.external.max_bytes,
                            std::env::temp_dir(),
                            self.external.workers.clone(),
                        )
                        .await
                }
            }
            .map_err(super::external_transport::snapshot_error)?;
            tokio::task::spawn_blocking(move || {
                let mut snapshot = snapshot;
                crate::codec::ValueCodec::new()
                    .and_then(|codec| codec.validate_external(&mut snapshot))
                    .map_err(|_| {
                        refuse(
                            StatusCode::UNPROCESSABLE_ENTITY,
                            "invalid_external_payload_datum",
                        )
                    })?;
                snapshot
                    .rewind()
                    .map_err(super::external_transport::snapshot_error)?;
                Ok(PreparedExternal {
                    reference,
                    uri: registered.uri,
                    _snapshot: snapshot,
                })
            })
            .await
            .map_err(|_| {
                refuse(
                    StatusCode::SERVICE_UNAVAILABLE,
                    "external_payload_unavailable",
                )
            })?
        };
        tokio::time::timeout(self.external.timeout, prepare)
            .await
            .map_err(|_| {
                refuse(
                    StatusCode::SERVICE_UNAVAILABLE,
                    "external_payload_unavailable",
                )
            })?
            .map(Some)
    }
}
