//! Original storage authority for retained objects, independent of the latest
//! namespace write policy. Namespace/registry changes share the native DB lock.
use super::{Result, RuntimeError, refuse, transaction::Transaction};
use crate::external_payload::{LocalStorage, Reference, StorageBinding};
use axum::http::StatusCode;
use serde_json::Value;

pub(super) fn unavailable() -> RuntimeError {
    refuse(
        StatusCode::CONFLICT,
        "external_payload_storage_binding_unavailable",
    )
}

/// Resolve an admitted policy name without creating a missing original root.
/// Disabled means no new writes, not loss of authority over retained objects.
pub(super) async fn retained_local(
    policy: &Value,
    namespace: &str,
) -> Result<Option<LocalStorage>> {
    if policy.is_null() || policy["driver"] != "local" {
        return Ok(None);
    }
    LocalStorage::open_declared_existing(super::external_transport::local_root(policy, namespace)?)
        .await
        .map(Some)
        .map_err(|_| unavailable())
}

pub(super) async fn deletion_storage(
    encoded: &str,
    namespace: &str,
    uri: &str,
) -> Result<Option<LocalStorage>> {
    if encoded.len() > 65_536 {
        return Err(unavailable());
    }
    let shape: Value = serde_json::from_str(encoded).map_err(|_| unavailable())?;
    if shape.get("version").is_some()
        || shape.get("root_uri").is_some()
        || shape.get("generation").is_some()
    {
        let binding =
            StorageBinding::decode_deletion(encoded, namespace, uri).ok_or_else(unavailable)?;
        return binding.storage().await.map(Some).map_err(|_| unavailable());
    }
    // Previously committed journals retain their saved original policy. They
    // also must not create an empty replacement for a missing provider root.
    retained_local(&shape, namespace).await
}

store_methods! {
    pub(super) async fn external_storage_policy(
        tx: &mut Transaction<'_, DB>, namespace: &str,
    ) -> Result<(Value, i64)> {
        let row = Self::query("SELECT external_payload_storage,external_payload_storage_generation FROM workflow_namespaces WHERE name=$1")
            .bind(namespace).fetch_optional(&mut **tx).await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "namespace_not_found"))?;
        let policy = DB::optional_document_row(&row, "external_payload_storage")?.unwrap_or(Value::Null);
        let stored = DB::number(&row, "external_payload_storage_generation")?;
        if stored < 0 { return Err(unavailable()); }
        // Existing native/PHP policy rows predate the internal generation.
        let generation = if stored == 0 && !policy.is_null() { 1 } else { stored };
        Ok((policy, generation))
    }

    /// Must run in the same transaction that admits the writing registration.
    pub(super) async fn admit_storage_binding(
        tx: &mut Transaction<'_, DB>, namespace: &str, local: &LocalStorage,
        reference: &Reference, uri: &str,
    ) -> Result<StorageBinding> {
        let (policy, generation) = Self::external_storage_policy(tx, namespace).await?;
        if policy.is_null() || policy["driver"] != "local" || policy["enabled"] == false {
            return Err(refuse(StatusCode::SERVICE_UNAVAILABLE, "external_payload_unavailable"));
        }
        let selected = retained_local(&policy, namespace).await?.ok_or_else(unavailable)?;
        if selected.uri_for(reference).ok().as_deref() != Some(uri)
            || local.uri_for(reference).ok().as_deref() != Some(uri) {
            return Err(unavailable());
        }
        StorageBinding::new(namespace, generation, local).ok_or_else(unavailable)
    }

    async fn persist_storage_binding(
        tx: &mut Transaction<'_, DB>, namespace: &str, reference: &Reference, binding: &StorageBinding,
    ) -> Result<()> {
        let encoded = binding.encode().ok_or_else(unavailable)?;
        let changed = Self::query("UPDATE runtime_external_payloads SET storage_binding=$1 WHERE id=$2 AND namespace=$3 AND storage_binding IS NULL")
            .bind(encoded).bind(&reference.reference_id).bind(namespace).execute(&mut **tx).await?;
        if DB::affected(changed) != 1 { return Err(unavailable()); }
        Ok(())
    }

    pub(super) async fn retained_storage_binding(
        tx: &mut Transaction<'_, DB>, namespace: &str, reference: &Reference, uri: &str, stored: Option<&str>,
    ) -> Result<StorageBinding> {
        if let Some(stored) = stored {
            return StorageBinding::decode_stored(stored, namespace, reference, uri).ok_or_else(unavailable);
        }
        // Lazy backfill uses the admitted policy only if its exact address
        // reproduces the registration. A path in an arbitrary URI is not a grant.
        let (policy, generation) = Self::external_storage_policy(tx, namespace).await?;
        let local = retained_local(&policy, namespace).await?.ok_or_else(unavailable)?;
        if local.uri_for(reference).ok().as_deref() != Some(uri) { return Err(unavailable()); }
        let binding = StorageBinding::new(namespace, generation, &local).ok_or_else(unavailable)?;
        Self::persist_storage_binding(tx, namespace, reference, &binding).await?;
        Ok(binding)
    }

    /// Freeze old registrations before changing their policy. An explicit new
    /// root can also repair a legacy policy only where the recorded URI agrees.
    pub(super) async fn pin_legacy_payloads(
        tx: &mut Transaction<'_, DB>, namespace: &str, candidate: Option<(&Value, i64)>,
    ) -> Result<()> {
        let rows = Self::query("SELECT * FROM runtime_external_payloads WHERE namespace=$1 AND storage_binding IS NULL ORDER BY id")
            .bind(namespace).fetch_all(&mut **tx).await?;
        if rows.is_empty() { return Ok(()); }
        let (policy, generation) = Self::external_storage_policy(tx, namespace).await?;
        let original = retained_local(&policy, namespace).await.ok().flatten().map(|local| (local, generation));
        let replacement = match candidate {
            Some((policy, generation)) => retained_local(policy, namespace).await.ok().flatten().map(|local| (local, generation)),
            None => None,
        };
        for row in rows {
            if DB::string(&row, "codec")? != "avro" { return Err(unavailable()); }
            let registered = Self::payload_row(&row)?;
            let matches = |(local, _): &&(LocalStorage, i64)| local.uri_for(&registered.reference).ok().as_deref() == Some(registered.uri.as_str());
            let (local, generation) = original.as_ref().filter(matches).or_else(|| replacement.as_ref().filter(matches))
                .ok_or_else(unavailable)?;
            let binding = StorageBinding::new(namespace, *generation, local).ok_or_else(unavailable)?;
            Self::persist_storage_binding(tx, namespace, &registered.reference, &binding).await?;
        }
        Ok(())
    }

    /// Metadata and expiry are checked again under the object lock. Claiming
    /// retained ownership remains a separate original operation.
    pub(super) async fn external_payload_storage(&self, expected: &Reference, uri: &str) -> Result<LocalStorage> {
        let mut tx = self.begin().await?;
        Self::payload_object_lock(&mut tx, uri).await?;
        let row = Self::query("SELECT * FROM runtime_external_payloads WHERE id=$1 AND namespace=$2 AND upload_status='ready'")
            .bind(&expected.reference_id).bind(self.namespace.as_ref()).fetch_optional(&mut *tx).await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "external_payload_not_found"))?;
        Self::match_payload(&row, expected, Some(uri))?;
        if DB::optional_instant(&row, "retained_at")?.is_none()
            && DB::optional_instant(&row, "expires_at")?.is_some_and(|expiry| expiry < super::store::now()) {
            return Err(refuse(StatusCode::GONE, "external_payload_expired"));
        }
        let stored = DB::optional_string(&row, "storage_binding")?;
        let binding = Self::retained_storage_binding(&mut tx, self.namespace.as_ref(), expected, uri, stored.as_deref()).await?;
        tx.commit().await?;
        binding.storage().await.map_err(|_| unavailable())
    }

    pub(super) async fn bound_deletion_policy(
        tx: &mut Transaction<'_, DB>, namespace: &str, reference: &Reference, uri: &str, stored: Option<&str>,
    ) -> Result<Value> {
        let binding = Self::retained_storage_binding(tx, namespace, reference, uri, stored).await?;
        binding.storage().await.map_err(|_| unavailable())?;
        Ok(binding.deletion_policy())
    }
}
