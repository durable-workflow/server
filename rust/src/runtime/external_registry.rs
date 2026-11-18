//! Recoverable registry transitions for the development byte transport.
//! Provider writes must occur between prepare and promote under the same
//! object lock as cleanup. Completion admission/lease fencing remains separate.
use super::transaction::Transaction;
use super::{
    Result,
    backend::Backend,
    external_config::Quota,
    refuse,
    store::{Store, id, now},
};
use crate::external_payload::{REFERENCE_SCHEMA, Reference};
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use sha2::{Digest, Sha256};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

pub(crate) struct RegisteredPayload {
    pub(super) reference: Reference,
    pub(super) uri: String,
}

enum StorageAdmission<'a> {
    Current(&'a crate::external_payload::LocalStorage),
    #[cfg(test)]
    LegacyTest,
}

fn hash(value: &str) -> String {
    Sha256::digest(value.as_bytes())
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect()
}

/// `storage_uri_sha256`.
pub(super) fn uri_hash(uri: &str) -> String {
    hash(uri)
}

/// The object lock bucket of a URI, PHP's fixed 256.
pub(super) fn object_bucket(uri: &str) -> i64 {
    i64::from(u16::from_str_radix(&hash(uri)[..4], 16).expect("digest hex")) % 256
}
fn mismatch() -> super::RuntimeError {
    refuse(
        StatusCode::UNPROCESSABLE_ENTITY,
        "external_payload_integrity_mismatch",
    )
}

/// The storage diagnostic step that failed, its classified failure, and
/// the outcome of deleting the written object afterwards, if attempted.
pub(crate) struct StorageStepFailure {
    pub(crate) operation: &'static str,
    pub(crate) error: crate::external_payload::SnapshotError,
    pub(crate) cleanup: Option<&'static str>,
}

impl<DB: Backend> Store<DB>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
    for<'q> &'q str: Encode<'q, DB> + Type<DB>,
    for<'q> Option<&'q str>: Encode<'q, DB>,
    for<'q> Option<DB::EncodedDocument>: Encode<'q, DB>,
    for<'q> String: Encode<'q, DB> + Type<DB>,
    for<'q> i64: Encode<'q, DB> + Type<DB>,
    for<'r> i64: Decode<'r, DB>,
    usize: ColumnIndex<DB::Row>,
{
    pub(super) fn payload_row(row: &DB::Row) -> Result<RegisteredPayload> {
        if DB::string(row, "codec")? != "avro" { return Err(mismatch()); }
        Ok(RegisteredPayload {
            reference: Reference {
                schema: REFERENCE_SCHEMA,
                reference_id: DB::string(row, "id")?,
                codec: "avro",
                size_bytes: DB::number(row, "size_bytes")?,
                sha256: DB::string(row, "sha256")?,
            },
            uri: DB::string(row, "storage_uri")?,
        })
    }

    pub(super) fn match_payload(row: &DB::Row, expected: &Reference, uri: Option<&str>) -> Result<()> {
        let uri_mismatch = if let Some(uri) = uri {
            DB::string(row, "storage_uri")? != uri
        } else {
            false
        };
        if DB::string(row, "codec")? != expected.codec
            || DB::number(row, "size_bytes")? != expected.size_bytes
            || DB::string(row, "sha256")? != expected.sha256
            || uri_mismatch
        {
            return Err(mismatch());
        }
        Ok(())
    }

    /// Fixed lock cardinality matches PHP. The native engine transaction lock
    /// serializes initialization; an initialized bucket is locked by UPDATE
    /// before registry reads, including SQLite's writer admission.
    pub(super) async fn payload_object_lock(tx: &mut Transaction<'_, DB>, uri: &str) -> Result<()> {
        let bucket = object_bucket(uri);
        let exists = Self::scalar(
            "SELECT COUNT(*) FROM runtime_external_payload_object_locks WHERE bucket=$1",
        )
        .bind(bucket)
        .fetch_one(&mut **tx)
        .await?;
        if exists == 0 {
            Self::query("INSERT INTO runtime_external_payload_object_locks(bucket) VALUES ($1)")
                .bind(bucket)
                .execute(&mut **tx)
                .await?;
        }
        Self::query(
            "UPDATE runtime_external_payload_object_locks SET bucket=bucket WHERE bucket=$1",
        )
        .bind(bucket)
        .execute(&mut **tx)
        .await?;
        Ok(())
    }

    /// The storage diagnostic's write, read and delete of a generated object,
    /// under the object lock that uploads and cleanup take. Local objects are
    /// content-addressed, so a registered payload with the same bytes keeps
    /// its object: the diagnostic deletes only an object nothing references.
    ///
    /// A storage refusal names the step it happened at (Server #589). When the
    /// read fails after a write, the unreferenced object is still deleted once;
    /// that cleanup's outcome is reported beside the read failure, never in
    /// its place.
    pub(super) async fn storage_test_round_trip(
        &self,
        local: &crate::external_payload::LocalStorage,
        reference: Reference,
        payload: Vec<u8>,
        max_bytes: u64,
        workers: std::sync::Arc<tokio::sync::Semaphore>,
    ) -> Result<std::result::Result<(), StorageStepFailure>> {
        use super::external_transport::snapshot_error;
        let uri = local.uri_for(&reference).map_err(snapshot_error)?;
        let mut tx = self.begin().await?;
        Self::payload_object_lock(&mut tx, &uri).await?;
        let registered =
            Self::scalar("SELECT COUNT(*) FROM runtime_external_payloads WHERE storage_uri=$1")
                .bind(&uri)
                .fetch_one(&mut *tx)
                .await?
                > 0;
        let snapshot = crate::external_payload::capture(
            std::io::Cursor::new(payload),
            reference.clone(),
            max_bytes,
            std::env::temp_dir(),
            workers.clone(),
        )
        .await
        .map_err(snapshot_error)?;
        let failure = |operation, error, cleanup| Ok(Err(StorageStepFailure { operation, error, cleanup }));
        if let Err(error) = local.put(reference.clone(), snapshot).await {
            return failure("put", error, None);
        }
        // Fetch verifies the stored size and digest against the reference.
        if let Err(error) = local
            .fetch(reference.clone(), &uri, max_bytes, std::env::temp_dir(), workers)
            .await
        {
            let cleanup = if registered {
                None
            } else {
                Some(if local.delete(&reference).await.is_ok() { "deleted" } else { "failed" })
            };
            tx.commit().await?;
            return failure("get", error, cleanup);
        }
        if !registered && let Err(error) = local.delete(&reference).await {
            return failure("delete", error, None);
        }
        tx.commit().await?;
        Ok(Ok(()))
    }

    /// The caller commits this writing row before any provider effect. A retry
    /// keeps the existing ID, ready status and retained ownership; abandoned
    /// unclaimed retries renew only their upload expiry, matching PHP.
    #[cfg(test)]
    pub(super) async fn prepare_external_upload(
        &self,
        expected: &Reference,
        uri: &str,
        expires: DateTime<Utc>,
    ) -> Result<Reference> {
        self.prepare_external_upload_with_quota(expected, uri, expires, Quota::default())
            .await
    }

    pub(super) async fn upload_external_local(
        &self,
        local: crate::external_payload::LocalStorage,
        expected: Reference,
        snapshot: crate::external_payload::Snapshot,
        expires: DateTime<Utc>,
        quota: Quota,
    ) -> Result<Reference> {
        self.upload_external_local_guarded(local, expected, snapshot, expires, quota, None)
            .await
    }

    pub(super) async fn upload_external_local_guarded(
        &self,
        local: crate::external_payload::LocalStorage,
        expected: Reference,
        snapshot: crate::external_payload::Snapshot,
        expires: DateTime<Utc>,
        quota: Quota,
        completion: Option<&super::activity_completion_uploads::Context>,
    ) -> Result<Reference> {
        let uri = local
            .uri_for(&expected)
            .map_err(super::external_transport::snapshot_error)?;
        let reference = self
            .prepare_external_upload_admitted(&expected, &uri, expires, quota, StorageAdmission::Current(&local))
            .await?;
        self.finish_external_local_upload(reference, &uri, snapshot, completion).await
    }

    /// The committed registration is the authority for a previously admitted
    /// write, including when the namespace policy changes between phases.
    async fn finish_external_local_upload(
        &self,
        reference: Reference,
        uri: &str,
        snapshot: crate::external_payload::Snapshot,
        completion: Option<&super::activity_completion_uploads::Context>,
    ) -> Result<Reference> {
        let mut tx = self.begin().await?;
        Self::payload_object_lock(&mut tx, uri).await?;
        // Recheck registration after acquiring the provider lock. Cleanup may
        // have removed an abandoned row before this write was admitted.
        let admitted = Self::query("SELECT storage_binding FROM runtime_external_payloads WHERE id=$1 AND namespace=$2 AND storage_uri=$3")
            .bind(&reference.reference_id).bind(self.namespace.as_ref()).bind(uri)
            .fetch_optional(&mut *tx).await?.ok_or_else(super::external_storage_binding::unavailable)?;
        let binding = DB::optional_string(&admitted, "storage_binding")?.ok_or_else(super::external_storage_binding::unavailable)?;
        let binding = crate::external_payload::StorageBinding::decode_stored(&binding, self.namespace.as_ref(), &reference, uri)
            .ok_or_else(super::external_storage_binding::unavailable)?;
        let local = binding.storage().await.map_err(super::external_storage_binding::storage_failure)?;
        if let Some(context) = completion {
            self.completion_upload_lease(&mut tx, context).await?;
        }
        local
            .put(reference.clone(), snapshot)
            .await
            .map_err(super::external_transport::snapshot_error)?;
        if let Some(context) = completion {
            self.completion_upload_lease(&mut tx, context).await?;
        }
        self.promote_external_upload(&mut tx, &reference, uri)
            .await?;
        tx.commit().await?;
        Ok(reference)
    }

    #[cfg(test)]
    pub(super) async fn prepare_external_upload_with_quota(
        &self,
        expected: &Reference,
        uri: &str,
        expires: DateTime<Utc>,
        quota: Quota,
    ) -> Result<Reference> {
        self.prepare_external_upload_admitted(expected, uri, expires, quota, StorageAdmission::LegacyTest).await
    }

    async fn prepare_external_upload_admitted(
        &self, expected: &Reference, uri: &str, expires: DateTime<Utc>, quota: Quota, admission: StorageAdmission<'_>,
    ) -> Result<Reference> {
        let value = serde_json::to_value(expected)?;
        if !Reference::validate(&value).is_ok_and(|canonical| canonical == *expected)
            || uri.is_empty()
        {
            return Err(mismatch());
        }
        let mut tx = self.begin().await?;
        let binding = match admission {
            StorageAdmission::Current(local) => Some(Self::admit_storage_binding(&mut tx, self.namespace.as_ref(), local, expected, uri).await?),
            #[cfg(test)]
            StorageAdmission::LegacyTest => None,
        };
        Self::payload_object_lock(&mut tx, uri).await?;
        let uri_hash = hash(uri);
        let existing = Self::query(
            "SELECT * FROM runtime_external_payloads WHERE namespace=$1 AND storage_uri_sha256=$2",
        )
        .bind(self.namespace.as_ref())
        .bind(&uri_hash)
        .fetch_optional(&mut *tx)
        .await?;
        let timestamp = now();
        let reference = if let Some(row) = existing {
            Self::match_payload(&row, expected, Some(uri))?;
            if binding.is_some() {
                let stored = DB::optional_string(&row, "storage_binding")?;
                let registered = Self::payload_row(&row)?;
                Self::retained_storage_binding(&mut tx, self.namespace.as_ref(), &registered.reference, uri, stored.as_deref()).await?;
            }
            let status = DB::string(&row, "upload_status")?;
            if status != "ready" && status != "writing" {
                return Err(refuse(StatusCode::CONFLICT, "external_payload_unavailable"));
            }
            if DB::optional_instant(&row, "retained_at")?.is_none() {
                Self::query("UPDATE runtime_external_payloads SET expires_at=$1,updated_at=$2 WHERE id=$3 AND namespace=$4")
                    .bind(DB::bind_time(expires)).bind(DB::bind_time(timestamp))
                    .bind(DB::string(&row, "id")?).bind(self.namespace.as_ref()).execute(&mut *tx).await?;
            }
            Self::payload_row(&row)?.reference
        } else {
            if quota.bytes.is_some() || quota.objects.is_some() {
                Self::query("UPDATE workflow_namespaces SET name=name WHERE name=$1")
                    .bind(self.namespace.as_ref())
                    .execute(&mut *tx)
                    .await?;
                if Self::scalar("SELECT COUNT(*) FROM workflow_namespaces WHERE name=$1")
                    .bind(self.namespace.as_ref())
                    .fetch_one(&mut *tx)
                    .await?
                    == 0
                {
                    return Err(refuse(
                        StatusCode::SERVICE_UNAVAILABLE,
                        "external_payload_namespace_quota_unavailable",
                    ));
                }
                let objects = Self::scalar(
                    "SELECT COUNT(*) FROM runtime_external_payloads WHERE namespace=$1",
                )
                .bind(self.namespace.as_ref())
                .fetch_one(&mut *tx)
                .await?;
                let bytes = Self::scalar(DB::EXTERNAL_PAYLOAD_BYTES)
                    .bind(self.namespace.as_ref())
                    .fetch_one(&mut *tx)
                    .await?;
                if quota.objects.is_some_and(|limit| objects >= limit) {
                    return Err(refuse(
                        StatusCode::TOO_MANY_REQUESTS,
                        "external_payload_namespace_objects_exhausted",
                    ));
                }
                if quota
                    .bytes
                    .is_some_and(|limit| expected.size_bytes > limit.saturating_sub(bytes.max(0)))
                {
                    return Err(refuse(
                        StatusCode::TOO_MANY_REQUESTS,
                        "external_payload_namespace_bytes_exhausted",
                    ));
                }
            }
            let reference = Reference {
                reference_id: format!("ep_{}", id()),
                ..expected.clone()
            };
            let encoded = match binding.as_ref() {
                Some(binding) => Some(binding.encode().ok_or_else(super::external_storage_binding::unavailable)?),
                None => None,
            };
            Self::query("INSERT INTO runtime_external_payloads(id,namespace,storage_uri,storage_uri_sha256,codec,sha256,size_bytes,upload_status,retained_at,expires_at,last_fetched_at,created_at,updated_at,storage_binding) VALUES ($1,$2,$3,$4,$5,$6,$7,'writing',NULL,$8,NULL,$9,$10,$11)")
                .bind(&reference.reference_id).bind(self.namespace.as_ref()).bind(uri).bind(&uri_hash)
                .bind(reference.codec).bind(&reference.sha256).bind(reference.size_bytes)
                .bind(DB::bind_time(expires)).bind(DB::bind_time(timestamp)).bind(DB::bind_time(timestamp))
                .bind(encoded.as_deref())
                .execute(&mut *tx).await?;
            reference
        };
        tx.commit().await?;
        Ok(reference)
    }

    /// Call after a provider write, while retaining its object lock. A failed
    /// transaction rolls back promotion, leaving the precommitted writing row.
    pub(super) async fn promote_external_upload(
        &self,
        tx: &mut Transaction<'_, DB>,
        expected: &Reference,
        uri: &str,
    ) -> Result<()> {
        let row =
            Self::query("SELECT * FROM runtime_external_payloads WHERE id=$1 AND namespace=$2")
                .bind(&expected.reference_id)
                .bind(self.namespace.as_ref())
                .fetch_optional(&mut **tx)
                .await?
                .ok_or_else(|| {
                    refuse(
                        StatusCode::SERVICE_UNAVAILABLE,
                        "external_payload_unavailable",
                    )
                })?;
        Self::match_payload(&row, expected, Some(uri))?;
        Self::query("UPDATE runtime_external_payloads SET upload_status='ready',updated_at=$1 WHERE id=$2 AND namespace=$3")
            .bind(DB::bind_time(now())).bind(&expected.reference_id).bind(self.namespace.as_ref())
            .execute(&mut **tx).await?;
        Ok(())
    }

    /// Metadata/namespace checks precede any provider read. Fetch does not claim
    /// retained ownership, renew expiry or rebase a missing original deadline.
    pub(super) async fn external_payload_for_fetch(
        &self,
        expected: &Reference,
    ) -> Result<RegisteredPayload> {
        if !Reference::validate(&serde_json::to_value(expected)?)
            .is_ok_and(|canonical| canonical == *expected)
        {
            return Err(mismatch());
        }
        let row = Self::query("SELECT * FROM runtime_external_payloads WHERE id=$1 AND namespace=$2 AND upload_status='ready'")
            .bind(&expected.reference_id).bind(self.namespace.as_ref()).fetch_optional(&self.pool).await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "external_payload_not_found"))?;
        if DB::optional_instant(&row, "retained_at")?.is_none()
            && DB::optional_instant(&row, "expires_at")?.is_some_and(|expires| expires < now())
        {
            return Err(refuse(StatusCode::GONE, "external_payload_expired"));
        }
        Self::match_payload(&row, expected, None)?;
        Self::payload_row(&row)
    }

    pub(super) async fn claim_external_in(
        &self,
        tx: &mut Transaction<'_, DB>,
        expected: &Reference,
        uri: &str,
    ) -> Result<()> {
        Self::claim_external_for(tx, self.namespace.as_ref(), expected, uri).await
    }

    /// `claim_external_in` for a transaction step that names its namespace.
    pub(super) async fn claim_external_for(
        tx: &mut Transaction<'_, DB>,
        namespace: &str,
        expected: &Reference,
        uri: &str,
    ) -> Result<()> {
        Self::payload_object_lock(tx, uri).await?;
        let row = Self::query("SELECT * FROM runtime_external_payloads WHERE id=$1 AND namespace=$2 AND upload_status='ready'")
            .bind(&expected.reference_id).bind(namespace).fetch_optional(&mut **tx).await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "external_payload_not_found"))?;
        Self::match_payload(&row, expected, Some(uri))?;
        let timestamp = now();
        if DB::optional_instant(&row, "retained_at")?.is_none()
            && DB::optional_instant(&row, "expires_at")?.is_some_and(|expires| expires < timestamp)
        {
            return Err(refuse(StatusCode::GONE, "external_payload_expired"));
        }
        Self::query("UPDATE runtime_external_payloads SET retained_at=COALESCE(retained_at,$1),expires_at=NULL,last_fetched_at=$2,updated_at=$3 WHERE id=$4 AND namespace=$5")
            .bind(DB::bind_time(timestamp)).bind(DB::bind_time(timestamp)).bind(DB::bind_time(timestamp))
            .bind(&expected.reference_id).bind(namespace).execute(&mut **tx).await?;
        Ok(())
    }

    /// Claims the reference in a transaction of its own, for a request
    /// that records nothing else.
    pub(super) async fn claim_external(&self, prepared: &super::external_resolution::PreparedExternal) -> Result<()> {
        let mut tx = self.begin().await?;
        self.claim_external_in(&mut tx, &prepared.reference, &prepared.uri).await?;
        tx.commit().await?;
        Ok(())
    }

    pub(super) async fn mark_external_fetched(&self, expected: &Reference) -> Result<()> {
        let mut tx = self.begin().await?;
        let timestamp = now();
        Self::query("UPDATE runtime_external_payloads SET last_fetched_at=$1,updated_at=$2 WHERE id=$3 AND namespace=$4 AND upload_status='ready'")
            .bind(DB::bind_time(timestamp)).bind(DB::bind_time(timestamp))
            .bind(&expected.reference_id).bind(self.namespace.as_ref()).execute(&mut *tx).await?;
        tx.commit().await?;
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::external_payload::{LocalStorage, capture};
    use sqlx::{Row, Sqlite};
    use std::{
        io::{Cursor, Read},
        sync::Arc,
    };
    use tokio::sync::Semaphore;

    fn expected(bytes: &[u8]) -> Reference {
        Reference {
            schema: REFERENCE_SCHEMA,
            reference_id: "ep_01J00000000000000000000000".into(),
            codec: "avro",
            size_bytes: bytes.len() as i64,
            sha256: Sha256::digest(bytes)
                .iter()
                .map(|byte| format!("{byte:02x}"))
                .collect(),
        }
    }
    fn reason(error: super::super::RuntimeError) -> &'static str {
        match error {
            super::super::RuntimeError::Refused { reason, .. } => reason,
            _ => panic!("unexpected error"),
        }
    }

    #[tokio::test]
    async fn a_policy_move_before_admission_refuses_the_cached_root_before_any_provider_write() {
        let temp = tempfile::tempdir().unwrap();
        let store = Store::<Sqlite>::new(super::super::sqlite::open(temp.path().join("db.sqlite").to_str().unwrap()).await.unwrap());
        store.initialize_namespace_registry().await.unwrap();
        let root = temp.path().join("original");
        let local = LocalStorage::open(root.clone()).await.unwrap();
        store.set_external_policy("default", serde_json::json!({"driver":"local","enabled":true,"config":{"uri":format!("file://{}",root.display())}})).await.unwrap();
        let replacement = temp.path().join("replacement");
        LocalStorage::open(replacement.clone()).await.unwrap();
        store.set_external_policy("default", serde_json::json!({"driver":"local","enabled":true,"config":{"uri":format!("file://{}",replacement.display())}})).await.unwrap();
        let bytes = b"cached provider root is no longer admitted";
        let metadata = expected(bytes);
        let snapshot = capture(Cursor::new(bytes.to_vec()), metadata.clone(), 100, temp.path().into(), Arc::new(Semaphore::new(1))).await.unwrap();
        let error = store.upload_external_local(local, metadata.clone(), snapshot, now()+chrono::Duration::seconds(60), Quota::default()).await.unwrap_err();
        assert_eq!(reason(error), "external_payload_storage_binding_unavailable");
        let count: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM runtime_external_payloads").fetch_one(&store.pool).await.unwrap();
        assert_eq!(count, 0);
        assert!(!root.join("avro").exists());
        assert!(!replacement.join("avro").exists());
        store.pool.close().await;
    }

    #[tokio::test]
    async fn a_committed_write_finishes_at_its_original_root_after_disable_or_move() {
        for disabled in [false, true] {
            let temp = tempfile::tempdir().unwrap();
            let store = Store::<Sqlite>::new(super::super::sqlite::open(temp.path().join("db.sqlite").to_str().unwrap()).await.unwrap());
            store.initialize_namespace_registry().await.unwrap();
            let root = temp.path().join("original");
            let local = LocalStorage::open(root.clone()).await.unwrap();
            store.set_external_policy("default", serde_json::json!({"driver":"local","enabled":true,"config":{"uri":format!("file://{}",root.display())}})).await.unwrap();
            let bytes = b"write admitted before policy change";
            let metadata = expected(bytes);
            let uri = local.uri_for(&metadata).unwrap();
            let reference = store.prepare_external_upload_admitted(&metadata, &uri, now()+chrono::Duration::seconds(60), Quota::default(), StorageAdmission::Current(&local)).await.unwrap();
            let original: String = sqlx::query_scalar("SELECT storage_binding FROM runtime_external_payloads").fetch_one(&store.pool).await.unwrap();
            let replacement = temp.path().join("replacement");
            store.set_external_policy("default", if disabled { serde_json::json!({"driver":"local","enabled":false}) } else { serde_json::json!({"driver":"local","enabled":true,"config":{"uri":format!("file://{}",replacement.display())}}) }).await.unwrap();
            let snapshot = capture(Cursor::new(bytes.to_vec()), reference.clone(), 100, temp.path().into(), Arc::new(Semaphore::new(1))).await.unwrap();
            assert_eq!(store.finish_external_local_upload(reference.clone(), &uri, snapshot, None).await.unwrap(), reference);
            let row: (String, String) = sqlx::query_as("SELECT upload_status,storage_binding FROM runtime_external_payloads").fetch_one(&store.pool).await.unwrap();
            assert_eq!(row, ("ready".into(), original));
            assert!(root.join("avro").exists());
            assert!(!replacement.exists());
            store.pool.close().await;
        }
    }

    #[tokio::test]
    async fn an_original_root_lost_after_write_admission_is_not_recreated() {
        let temp = tempfile::tempdir().unwrap();
        let store = Store::<Sqlite>::new(super::super::sqlite::open(temp.path().join("db.sqlite").to_str().unwrap()).await.unwrap());
        store.initialize_namespace_registry().await.unwrap();
        let root = temp.path().join("original");
        let local = LocalStorage::open(root.clone()).await.unwrap();
        store.set_external_policy("default", serde_json::json!({"driver":"local","enabled":true,"config":{"uri":format!("file://{}",root.display())}})).await.unwrap();
        let bytes = b"original provider is offline";
        let metadata = expected(bytes);
        let uri = local.uri_for(&metadata).unwrap();
        let reference = store.prepare_external_upload_admitted(&metadata, &uri, now()+chrono::Duration::seconds(60), Quota::default(), StorageAdmission::Current(&local)).await.unwrap();
        std::fs::remove_dir(&root).unwrap();
        let snapshot = capture(Cursor::new(bytes.to_vec()), reference.clone(), 100, temp.path().into(), Arc::new(Semaphore::new(1))).await.unwrap();
        assert_eq!(reason(store.finish_external_local_upload(reference, &uri, snapshot, None).await.unwrap_err()), "external_payload_storage_binding_unavailable");
        assert!(!root.exists());
        let status: String = sqlx::query_scalar("SELECT upload_status FROM runtime_external_payloads").fetch_one(&store.pool).await.unwrap();
        assert_eq!(status, "writing");
        store.pool.close().await;
    }

    #[tokio::test]
    async fn writing_row_survives_failed_promotion_and_same_id_retry_recovers_exact_bytes() {
        let temp = tempfile::tempdir().unwrap();
        let store = Store::<Sqlite>::new(
            super::super::sqlite::open(temp.path().join("db.sqlite").to_str().unwrap())
                .await
                .unwrap(),
        );
        let local = LocalStorage::open(temp.path().join("objects"))
            .await
            .unwrap();
        let bytes = b"original encoded string";
        let metadata = expected(bytes);
        let uri = local.uri_for(&metadata).unwrap();
        let deadline = now() + chrono::Duration::seconds(60);
        let reference = store
            .prepare_external_upload(&metadata, &uri, deadline)
            .await
            .unwrap();
        std::fs::write(temp.path().join("objects/avro"), b"provider unavailable").unwrap();
        let snapshot = capture(
            Cursor::new(bytes.to_vec()),
            reference.clone(),
            100,
            temp.path().into(),
            Arc::new(Semaphore::new(1)),
        )
        .await
        .unwrap();
        assert!(local.put(reference.clone(), snapshot).await.is_err());
        let writing: String =
            sqlx::query_scalar("SELECT upload_status FROM runtime_external_payloads")
                .fetch_one(&store.pool)
                .await
                .unwrap();
        assert_eq!(writing, "writing");
        std::fs::remove_file(temp.path().join("objects/avro")).unwrap();
        assert_eq!(
            reason(
                store
                    .external_payload_for_fetch(&reference)
                    .await
                    .err()
                    .unwrap()
            ),
            "external_payload_not_found"
        );
        let mut tx = store.begin().await.unwrap();
        Store::<Sqlite>::payload_object_lock(&mut tx, &uri)
            .await
            .unwrap();
        let snapshot = capture(
            Cursor::new(bytes.to_vec()),
            reference.clone(),
            100,
            temp.path().into(),
            Arc::new(Semaphore::new(1)),
        )
        .await
        .unwrap();
        local.put(reference.clone(), snapshot).await.unwrap();
        store
            .promote_external_upload(&mut tx, &reference, &uri)
            .await
            .unwrap();
        tx.rollback().await.unwrap();
        let row = sqlx::query(
            "SELECT upload_status,expires_at,retained_at FROM runtime_external_payloads",
        )
        .fetch_one(&store.pool)
        .await
        .unwrap();
        assert_eq!(row.get::<String, _>("upload_status"), "writing");
        assert!(row.get::<Option<String>, _>("retained_at").is_none());
        assert_eq!(
            row.get::<String, _>("expires_at"),
            <Sqlite as Backend>::bind_time(deadline)
        );
        assert_eq!(
            store
                .prepare_external_upload(&metadata, &uri, deadline)
                .await
                .unwrap(),
            reference
        );
        let mut tx = store.begin().await.unwrap();
        Store::<Sqlite>::payload_object_lock(&mut tx, &uri)
            .await
            .unwrap();
        store
            .promote_external_upload(&mut tx, &reference, &uri)
            .await
            .unwrap();
        tx.commit().await.unwrap();
        let registered = store.external_payload_for_fetch(&reference).await.unwrap();
        let mut snapshot = local
            .fetch(
                registered.reference,
                &registered.uri,
                100,
                temp.path().into(),
                Arc::new(Semaphore::new(1)),
            )
            .await
            .unwrap();
        let mut observed = Vec::new();
        snapshot.read_to_end(&mut observed).unwrap();
        assert_eq!(observed, bytes);
        assert_eq!(
            sqlx::query_scalar::<_, i64>("SELECT COUNT(*) FROM runtime_external_payloads")
                .fetch_one(&store.pool)
                .await
                .unwrap(),
            1
        );
        store.close().await;
    }

    #[tokio::test]
    async fn namespace_metadata_expiry_and_retained_retries_preserve_original_ownership() {
        let temp = tempfile::tempdir().unwrap();
        let store = Store::<Sqlite>::new(
            super::super::sqlite::open(temp.path().join("db.sqlite").to_str().unwrap())
                .await
                .unwrap(),
        );
        let metadata = expected(b"one");
        let uri = "file:///owned/avro/content";
        let reference = store
            .prepare_external_upload(&metadata, uri, now() + chrono::Duration::seconds(60))
            .await
            .unwrap();
        let other = store
            .in_namespace("other")
            .prepare_external_upload(&metadata, uri, now() + chrono::Duration::seconds(60))
            .await
            .unwrap();
        assert_ne!(other.reference_id, reference.reference_id);
        let mut tx = store.begin().await.unwrap();
        Store::<Sqlite>::payload_object_lock(&mut tx, uri)
            .await
            .unwrap();
        store
            .promote_external_upload(&mut tx, &reference, uri)
            .await
            .unwrap();
        tx.commit().await.unwrap();
        assert_eq!(
            reason(
                store
                    .in_namespace("other")
                    .external_payload_for_fetch(&reference)
                    .await
                    .err()
                    .unwrap()
            ),
            "external_payload_not_found"
        );
        let mut conflicting = reference.clone();
        conflicting.size_bytes += 1;
        assert_eq!(
            reason(
                store
                    .external_payload_for_fetch(&conflicting)
                    .await
                    .err()
                    .unwrap()
            ),
            "external_payload_integrity_mismatch"
        );
        assert_eq!(
            reason(
                store
                    .prepare_external_upload(&conflicting, uri, now())
                    .await
                    .err()
                    .unwrap()
            ),
            "external_payload_integrity_mismatch"
        );
        sqlx::query("UPDATE runtime_external_payloads SET expires_at='2000-01-01 00:00:00' WHERE namespace='default'")
            .execute(&store.pool)
            .await
            .unwrap();
        assert_eq!(
            reason(
                store
                    .external_payload_for_fetch(&reference)
                    .await
                    .err()
                    .unwrap()
            ),
            "external_payload_expired"
        );
        sqlx::query("UPDATE runtime_external_payloads SET retained_at='2026-01-01 00:00:00',expires_at=NULL WHERE namespace='default'").execute(&store.pool).await.unwrap();
        assert_eq!(
            store
                .prepare_external_upload(&metadata, uri, now())
                .await
                .unwrap(),
            reference
        );
        let row = sqlx::query(
            "SELECT upload_status,retained_at,expires_at FROM runtime_external_payloads WHERE namespace='default'",
        )
        .fetch_one(&store.pool)
        .await
        .unwrap();
        assert_eq!(row.get::<String, _>("upload_status"), "ready");
        assert_eq!(row.get::<String, _>("retained_at"), "2026-01-01 00:00:00");
        assert!(row.get::<Option<String>, _>("expires_at").is_none());
        assert_eq!(
            store
                .external_payload_for_fetch(&reference)
                .await
                .unwrap()
                .reference,
            reference
        );
        store.close().await;
    }
}
