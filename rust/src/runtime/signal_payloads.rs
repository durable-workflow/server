//! Signal arguments a namespace keeps by reference (Server #979).
//!
//! A signal whose arguments are above the namespace's external payload
//! threshold is admitted by reference: native verifies the referenced blob
//! and stores only the reference. Applying it needs the arguments themselves,
//! as Core's `signalValueFromRecord` reads them back through
//! `ExternalPayloads::payloadBlob`. The read happens before the transaction
//! that applies the signal, because external storage is not reachable inside
//! it: the blob is fetched and verified against its registered reference, and
//! the signal value is computed. A value above the threshold is stored by
//! reference too (`ExternalPayloads::externalizeForNamespace`), and
//! `SignalApplied` records its envelope.
use super::external_resolution::STORED_PREFIX;
use super::{Result, Runtime, refuse};
use crate::external_payload::{REFERENCE_SCHEMA, Reference};
use axum::http::StatusCode;
use serde_json::Value;
use sha2::{Digest, Sha256};

/// A signal's stored arguments, resolved for the transaction that applies it.
pub(crate) struct ResolvedSignal {
    pub(super) signal_id: String,
    /// The stored reference this stands for; apply uses the resolution only
    /// while the signal still holds exactly this reference.
    pub(super) stored: String,
    /// The verified Avro blob of the signal's arguments.
    pub(super) arguments: String,
    /// The signal value by reference, when it is above the threshold.
    pub(super) value: Option<StoredValue>,
}

/// A signal value the runtime stored by reference: claimed when it is
/// recorded, otherwise left to abandoned-upload cleanup.
pub(crate) struct StoredValue {
    pub(super) stored: String,
    pub(super) reference: Reference,
    pub(super) uri: String,
    pub(super) namespace: String,
}

fn unavailable() -> super::RuntimeError {
    refuse(StatusCode::SERVICE_UNAVAILABLE, "external_payload_unavailable")
}

fn hex(digest: impl AsRef<[u8]>) -> String {
    digest.as_ref().iter().map(|byte| format!("{byte:02x}")).collect()
}

impl Runtime {
    /// The task's signal, resolved for the transaction that applies it:
    /// arguments stored by reference are read back, and a value above the
    /// namespace's threshold is stored by reference, however its arguments
    /// arrived. Nothing is read or written unless `body` holds the task's
    /// live claim, as the transaction's guard requires; otherwise the
    /// transaction refuses the request exactly as before.
    pub(super) async fn resolve_task_signal(&self, task_id: &str, body: &serde_json::Value) -> Result<Option<ResolvedSignal>> {
        let Some((signal_id, name, stored)) = self.claimed_task_signal(task_id, body.clone()).await? else {
            return Ok(None);
        };
        let by_reference = stored.starts_with(STORED_PREFIX);
        let storage = self.value_storage().await?;
        // An inline signal in a namespace that stores nothing by reference is
        // applied as before, without any work here.
        if !by_reference && storage.is_none() {
            return Ok(None);
        }
        let resolve = async {
            let arguments = if by_reference { self.read_stored(&stored).await? } else { stored.clone() };
            // Applying a signal is transition work: it never waits for read
            // codec capacity.
            let prepared = super::store::prepare_control_arguments(
                self.transition_codec.clone(),
                name,
                arguments.clone(),
                "invalid_signal_arguments",
                false,
            )
            .await?;
            let value = match storage {
                Some((local, threshold)) => self.store_large_value(local, threshold, &prepared.value).await?,
                None => None,
            };
            Ok((by_reference || value.is_some()).then(|| ResolvedSignal { signal_id, stored: stored.clone(), arguments, value }))
        };
        // One deadline covers the read and any write, as for uploads.
        tokio::time::timeout(self.external.timeout, resolve).await.map_err(|_| unavailable())?
    }

    /// The namespace's enabled local storage and its threshold, or none.
    async fn value_storage(&self) -> Result<Option<(crate::external_payload::LocalStorage, i64)>> {
        let metadata = self.describe_namespace(self.namespace()).await?;
        let policy = &metadata["external_payload_storage"];
        Ok(super::external_transport::local_for(policy, self.namespace())
            .await?
            .map(|local| (local, super::payload_discovery::threshold(policy))))
    }

    /// The Avro blob a stored native reference stands for, fetched from the
    /// namespace's storage and verified against the registered reference.
    async fn read_stored(&self, stored: &str) -> Result<String> {
        use std::io::Read;
        let value: Value = stored
            .strip_prefix(STORED_PREFIX)
            .and_then(|json| serde_json::from_str(json).ok())
            .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_signal_arguments"))?;
        let reference = Reference::validate(&value)
            .map_err(|_| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_signal_arguments"))?;
        let registered = self.external_payload_for_fetch(&reference).await?;
        let mut snapshot = super::external_transport::local(self)
            .await?
            .fetch(
                registered.reference,
                &registered.uri,
                self.external.max_bytes,
                std::env::temp_dir(),
                self.external.workers.clone(),
            )
            .await
            .map_err(super::external_transport::snapshot_error)?;
        let mut blob = String::new();
        snapshot
            .read_to_string(&mut blob)
            .map_err(|_| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_signal_arguments"))?;
        Ok(blob.trim().to_owned())
    }

    /// `externalizeForNamespace`: a value above the namespace's threshold is
    /// written to its storage and registered, unclaimed until the applying
    /// transaction records it. A value at or below it stays inline. Local
    /// objects are content-addressed and registered by their storage URI, so
    /// a retried request finds the same registration instead of adding one.
    async fn store_large_value(
        &self,
        local: crate::external_payload::LocalStorage,
        threshold: i64,
        value: &str,
    ) -> Result<Option<StoredValue>> {
        if i64::try_from(value.len()).unwrap_or(i64::MAX) <= threshold {
            return Ok(None);
        }
        let payload = value.as_bytes().to_vec();
        let sha256 = hex(Sha256::digest(&payload));
        let expected = Reference {
            schema: REFERENCE_SCHEMA,
            // The registry keeps an existing registration of the same
            // content-addressed object and assigns its own id otherwise.
            reference_id: format!("ep_{}", super::store::id()),
            codec: "avro",
            size_bytes: i64::try_from(payload.len()).map_err(|_| unavailable())?,
            sha256,
        };
        let uri = local.uri_for(&expected).map_err(super::external_transport::snapshot_error)?;
        let snapshot = crate::external_payload::capture(
            std::io::Cursor::new(payload),
            expected.clone(),
            self.external.max_bytes,
            std::env::temp_dir(),
            self.external.workers.clone(),
        )
        .await
        .map_err(super::external_transport::snapshot_error)?;
        let quota = self.external.quota(self.namespace())?;
        let expires = chrono::Duration::try_seconds(self.external.abandoned_seconds.max(1))
            .and_then(|duration| super::store::now().checked_add_signed(duration))
            .ok_or_else(unavailable)?;
        let reference = self.upload_external_local(local, expected, snapshot, expires, quota).await?;
        let stored = format!("{STORED_PREFIX}{}", serde_json::to_string(&reference)?);
        Ok(Some(StoredValue { stored, reference, uri, namespace: self.namespace().to_owned() }))
    }
}

store_methods! {
    /// The signal a workflow task resumes, while it is still received and
    /// `body` holds the task's live claim (`workflow_task_guard`, read in one
    /// snapshot): its id, name and stored arguments. A request without the
    /// claim gets nothing here and is refused by the transaction as before.
    pub(super) async fn claimed_task_signal(&self, task_id: &str, body: Value) -> Result<Option<(String, String, String)>> {
        let mut tx = self.begin_read().await?;
        let Some(task) = Self::query("SELECT * FROM workflow_tasks WHERE id=$1 AND namespace=$2 AND task_type='workflow'")
            .bind(task_id).bind(self.namespace.as_ref()).fetch_optional(&mut *tx).await?
        else {
            return Ok(None);
        };
        match Self::workflow_task_guard(&mut tx, &task, &body, self.worker_stale_after_seconds).await {
            Ok(()) => {}
            Err(super::RuntimeError::Refused { .. } | super::RuntimeError::Protocol { .. }) => return Ok(None),
            Err(error) => return Err(error),
        }
        let payload = DB::document_row(&task, "payload")?;
        let Some(signal_id) = payload["workflow_signal_id"].as_str().filter(|_| payload["resume_source_kind"] == "workflow_signal") else {
            return Ok(None);
        };
        let Some(signal) = Self::query("SELECT signal_name,arguments FROM workflow_signal_records WHERE id=$1 AND workflow_run_id=$2 AND status='received'")
            .bind(signal_id).bind(DB::string(&task, "workflow_run_id")?).fetch_optional(&mut *tx).await?
        else {
            return Ok(None);
        };
        let Some(arguments) = DB::optional_string(&signal, "arguments")? else {
            return Ok(None);
        };
        Ok(Some((signal_id.to_owned(), DB::string(&signal, "signal_name")?, arguments)))
    }
}
