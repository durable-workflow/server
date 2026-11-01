//! A pruned run's external payloads, as published PHP 2.11.8's
//! `ExternalPayloadRetentionCleanup::deleteForRun` releases them in the
//! prune's transaction (Server #325).
//!
//! Native stores a reference as `dw-native-external-payload-v1:` and the
//! reference JSON, whose id names a `runtime_external_payloads` row and its
//! object URI. A run's references that no retained run still holds are
//! deletable. A retained run is another run that keeps its details; it holds
//! an object when its payload columns name any reference registered for the
//! object's URI, in any namespace, as PHP matches stored URIs.
//!
//! With nothing deletable the prune needs no storage. Otherwise the
//! namespace's enabled local storage must name the objects, or the run is
//! skipped with `external_payload_storage_driver_unavailable`. Each object is
//! asked again under its object lock, in lock order, after the run's rows
//! are deleted: a reference still held keeps it; otherwise this namespace's
//! registration goes, and when no other namespace registers the object its
//! deletion is recorded (`deleteRegisteredUri`, `stage`). Each object asked
//! and not held counts as deleted, as PHP counts it.
//!
//! No object is deleted before the prune commits: the caller settles the
//! recorded deletions afterwards (`payload_deletions`). A refused recording
//! (an active online-backup hold) fails the prune with PHP's message; the
//! transaction rolls back, so the run, its registrations and every object stay
//! for a later pass.
use super::Result;
use super::external_registry::{object_bucket, uri_hash};
use super::external_resolution::STORED_PREFIX;
use super::retention::{self, Outcome};
use super::transaction::Transaction;
use crate::external_payload::{LocalStorage, Reference};
use serde_json::Value;

/// One object to settle: its URI and the reference this run holds for it.
pub(super) struct Target {
    uri: String,
    reference: Reference,
}

/// The run's deletable objects in lock order, with the storage to delete
/// them through.
pub(super) struct Plan {
    targets: Vec<Target>,
    local: Option<LocalStorage>,
    /// The namespace's storage policy, saved with each recorded deletion.
    policy: Value,
}

/// A whole column value holding native's stored reference; `None` for any
/// other value, which may hold a reference native cannot settle.
pub(super) fn native_reference(value: &str) -> Option<Reference> {
    let json = value.strip_prefix(STORED_PREFIX)?;
    Reference::validate(&serde_json::from_str::<Value>(json).ok()?).ok()
}

store_methods! {
    /// The run's references and which of them no retained run holds, or the
    /// outcome that keeps the run: a reference native cannot settle, or no
    /// storage for an object to delete.
    pub(super) async fn retention_payload_plan(
        &self,
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
    ) -> Result<std::result::Result<Plan, Outcome>> {
        let mut query = Self::query(retention::REFERENCE_VALUES);
        for _ in 0..retention::REFERENCE_VALUE_BINDS {
            query = query.bind(run_id);
        }
        let mut references: Vec<Reference> = Vec::new();
        for row in query.fetch_all(&mut **tx).await? {
            let Some(reference) = DB::optional_string(&row, "value")?.as_deref().and_then(native_reference) else {
                return Ok(Err(Outcome::Skipped("external_payload_cleanup_unavailable")));
            };
            if !references.iter().any(|known| known.reference_id == reference.reference_id) {
                references.push(reference);
            }
        }
        let mut deletable = Vec::new();
        for reference in references {
            let uri = Self::query("SELECT storage_uri FROM runtime_external_payloads WHERE id=$1 AND namespace=$2")
                .bind(&reference.reference_id)
                .bind(self.namespace.as_ref())
                .fetch_optional(&mut **tx)
                .await?
                .map(|row| DB::string(&row, "storage_uri"))
                .transpose()?;
            if !Self::payload_retained(tx, run_id, &reference, uri.as_deref()).await? {
                deletable.push((reference, uri));
            }
        }
        if deletable.is_empty() {
            return Ok(Ok(Plan { targets: Vec::new(), local: None, policy: Value::Null }));
        }
        let policy = Self::query("SELECT external_payload_storage FROM workflow_namespaces WHERE name=$1")
            .bind(self.namespace.as_ref())
            .fetch_optional(&mut **tx)
            .await?
            .map(|row| DB::optional_document_row(&row, "external_payload_storage"))
            .transpose()?
            .flatten()
            .unwrap_or(Value::Null);
        let local = match super::external_transport::local_for(&policy, self.namespace.as_ref()).await {
            Ok(Some(local)) => local,
            Ok(None) => return Ok(Err(Outcome::Skipped("external_payload_storage_driver_unavailable"))),
            Err(_) => return Ok(Err(Outcome::Error(retention::PAYLOAD_CLEANUP_FAILED.into()))),
        };
        let mut targets: Vec<Target> = Vec::new();
        for (reference, uri) in deletable {
            // A reference without a registration names its object by the
            // driver's address, as PHP's stored reference names its URI.
            let uri = match uri {
                Some(uri) => uri,
                None => match local.uri_for(&reference) {
                    Ok(uri) => uri,
                    Err(_) => return Ok(Err(Outcome::Error(retention::PAYLOAD_CLEANUP_FAILED.into()))),
                },
            };
            if !targets.iter().any(|target| target.uri == uri) {
                targets.push(Target { uri, reference });
            }
        }
        // `RuntimeExternalPayloadObjectLock::inLockOrder`: the transaction
        // holds every lock until it commits.
        targets.sort_by(|left, right| {
            (object_bucket(&left.uri), &left.uri).cmp(&(object_bucket(&right.uri), &right.uri))
        });
        Ok(Ok(Plan { targets, local: Some(local), policy }))
    }

    /// `deleteReferences` over the plan, after the run's rows are deleted:
    /// the objects released and the deletions recorded for the caller to
    /// settle once the prune commits. An error leaves the prune to roll back.
    pub(super) async fn delete_retention_payloads(
        &self,
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        plan: Plan,
    ) -> Result<(i64, Vec<String>)> {
        let mut deleted = 0;
        let mut staged = Vec::new();
        if plan.local.is_none() {
            return Ok((0, staged));
        }
        for target in plan.targets {
            Self::payload_object_lock(tx, &target.uri).await?;
            if Self::payload_retained(tx, run_id, &target.reference, Some(&target.uri)).await? {
                continue;
            }
            let mut owners = Vec::new();
            for row in Self::query("SELECT id,namespace FROM runtime_external_payloads WHERE storage_uri_sha256=$1 AND storage_uri=$2")
                .bind(uri_hash(&target.uri))
                .bind(&target.uri)
                .fetch_all(&mut **tx)
                .await?
            {
                owners.push((DB::string(&row, "id")?, DB::string(&row, "namespace")?));
            }
            let own = owners.iter().find(|(_, namespace)| namespace == self.namespace.as_ref()).map(|(id, _)| id.clone());
            // `deleteRegisteredUri`: the object goes when nothing else
            // registers it; this namespace's registration goes either way.
            if owners.is_empty() || (own.is_some() && owners.len() == 1) {
                staged.push(Self::stage_payload_deletion(tx, self.namespace.as_ref(), &target.uri, &plan.policy).await?);
            }
            if let Some(id) = own {
                Self::query("DELETE FROM runtime_external_payloads WHERE id=$1").bind(&id).execute(&mut **tx).await?;
            }
            deleted += 1;
        }
        Ok((deleted, staged))
    }

    /// `isReferencedByRetainedRun`: whether a retained run holds this
    /// reference, or any reference registered for the same object.
    async fn payload_retained(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        reference: &Reference,
        uri: Option<&str>,
    ) -> Result<bool> {
        let mut ids = vec![reference.reference_id.clone()];
        if let Some(uri) = uri {
            for row in Self::query("SELECT id FROM runtime_external_payloads WHERE storage_uri_sha256=$1 AND storage_uri=$2")
                .bind(uri_hash(uri))
                .bind(uri)
                .fetch_all(&mut **tx)
                .await?
            {
                let id = DB::string(&row, "id")?;
                if !ids.contains(&id) {
                    ids.push(id);
                }
            }
        }
        for id in ids {
            // Ids are unique, so any value naming one holds it.
            let pattern = format!("%{id}%");
            let mut query = Self::scalar(retention::RETAINED_SQL);
            for run in retention::RETAINED_BINDS {
                query = if run { query.bind(run_id) } else { query.bind(pattern.as_str()) };
            }
            if query.fetch_one(&mut **tx).await? > 0 {
                return Ok(true);
            }
        }
        Ok(false)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn only_a_whole_native_stored_reference_is_settled() {
        let json = r#"{"schema":"durable-workflow.v2.runtime-external-payload-reference.v1","reference_id":"ep_01M6AMBC6WGHS4SECC1QCWFC39","codec":"avro","size_bytes":7,"sha256":"1b88c046b1303353efef9574e010f49f7e1ddb6f0c9b682689100318e2b0f6ff"}"#;
        let stored = format!("{STORED_PREFIX}{json}");
        assert_eq!(native_reference(&stored).unwrap().reference_id, "ep_01M6AMBC6WGHS4SECC1QCWFC39");
        assert!(native_reference(json).is_none());
        assert!(native_reference(&format!("[\"{stored}\"]")).is_none());
        assert!(native_reference(&format!("{stored},{json}")).is_none());
        assert!(native_reference("dw-external-payload:v1:e30=").is_none());
        assert!(native_reference(&format!("{STORED_PREFIX}{{}}")).is_none());
    }
}
