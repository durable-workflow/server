//! Verified state-bearing references remain opaque in durable native state.
use super::{Result, Runtime, refuse};
use crate::external_payload::{Reference, Snapshot};
use axum::http::StatusCode;
use serde_json::{Value, json};

pub(super) const STORED_PREFIX: &str = "dw-native-external-payload-v1:";

pub(crate) struct PreparedExternal {
    pub(super) reference: Reference,
    pub(super) uri: String,
    // Keep verified bytes and bounded transfer capacity until state commits.
    _snapshot: Snapshot,
}

impl PreparedExternal {
    pub(super) fn stored(&self) -> String {
        format!(
            "{STORED_PREFIX}{}",
            serde_json::to_string(&self.reference).expect("reference JSON")
        )
    }
}

pub(super) fn stored_envelope(blob: &str) -> Option<Value> {
    let value = serde_json::from_str(blob.strip_prefix(STORED_PREFIX)?).ok()?;
    let reference = Reference::validate(&value).ok()?;
    Some(json!({"codec":"avro","external_payload":reference}))
}

impl Runtime {
    pub(super) async fn prepare_external(&self, value: &Value) -> Result<Option<PreparedExternal>> {
        if value.get("external_storage").is_some() {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "external_payload_unsupported",
            ));
        }
        let Some(reference) = value.get("external_payload") else {
            return Ok(None);
        };
        if value.as_object().is_none_or(|map| map.len() != 2) || value["codec"] != "avro" {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "external_payload_unsupported",
            ));
        }
        let reference = Reference::validate(reference).map_err(|_| {
            refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "external_payload_unsupported",
            )
        })?;
        if value["codec"] != reference.codec {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "external_payload_integrity_mismatch",
            ));
        }
        if reference.size_bytes as u64 > self.external.max_bytes.max(1) {
            return Err(refuse(
                StatusCode::PAYLOAD_TOO_LARGE,
                "external_payload_oversized",
            ));
        }
        let prepare = async {
            let registered = self.external_payload_for_fetch(&reference).await?;
            let snapshot = super::external_transport::local(self)
                .await?
                .fetch(
                    reference.clone(),
                    &registered.uri,
                    self.external.max_bytes,
                    std::env::temp_dir(),
                    self.external.workers.clone(),
                )
                .await
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
