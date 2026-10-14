//! Recoverable registry transitions, not yet exposed by HTTP/discovery.
//! Provider writes must occur between prepare and promote under the same
//! object lock as cleanup. Completion admission/lease fencing remains separate.
use super::{
    Result,
    backend::Backend,
    refuse,
    store::{Store, id, now},
};
use crate::external_payload::{REFERENCE_SCHEMA, Reference};
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use sha2::{Digest, Sha256};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Transaction, Type};

pub(super) struct RegisteredPayload {
    pub(super) reference: Reference,
    pub(super) uri: String,
}

fn hash(value: &str) -> String {
    Sha256::digest(value.as_bytes())
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect()
}
fn mismatch() -> super::RuntimeError {
    refuse(
        StatusCode::UNPROCESSABLE_ENTITY,
        "external_payload_integrity_mismatch",
    )
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
    fn payload_row(row: &DB::Row) -> Result<RegisteredPayload> {
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

    fn match_payload(row: &DB::Row, expected: &Reference, uri: Option<&str>) -> Result<()> {
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
        let bucket = i64::from(u16::from_str_radix(&hash(uri)[..4], 16).expect("digest hex")) % 256;
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

    /// The caller commits this writing row before any provider effect. A retry
    /// keeps the existing ID, ready status and retained ownership; abandoned
    /// unclaimed retries renew only their upload expiry, matching PHP.
    pub(super) async fn prepare_external_upload(
        &self,
        expected: &Reference,
        uri: &str,
        expires: DateTime<Utc>,
    ) -> Result<Reference> {
        let value = serde_json::to_value(expected)?;
        if !Reference::validate(&value).is_ok_and(|canonical| canonical == *expected)
            || uri.is_empty()
        {
            return Err(mismatch());
        }
        let mut tx = self.begin().await?;
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
            let reference = Reference {
                reference_id: format!("ep_{}", id()),
                ..expected.clone()
            };
            Self::query("INSERT INTO runtime_external_payloads(id,namespace,storage_uri,storage_uri_sha256,codec,sha256,size_bytes,upload_status,retained_at,expires_at,last_fetched_at,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,'writing',NULL,$8,NULL,$9,$10)")
                .bind(&reference.reference_id).bind(self.namespace.as_ref()).bind(uri).bind(&uri_hash)
                .bind(reference.codec).bind(&reference.sha256).bind(reference.size_bytes)
                .bind(DB::bind_time(expires)).bind(DB::bind_time(timestamp)).bind(DB::bind_time(timestamp))
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
