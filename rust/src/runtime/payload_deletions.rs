//! Committed external payload deletion, as published Server 2.11.8's
//! `RuntimeExternalPayloadDeletions` reclaims provider objects (Server #325).
//!
//! A run, stream or namespace prune never deletes a provider object inside
//! its own transaction. Where it releases an object's last registration it
//! records a deletion in `dw_external_payload_deletions`, in that same
//! transaction and under the object's lock (`stage_payload_deletion`). The
//! object is deleted only after the prune commits
//! (`settle_payload_deletions`), so a later database failure rolls back the
//! references and leaves their bytes intact.
//!
//! Once the prune has committed, a provider failure leaves the deletion
//! pending, with `backup_hold` or `storage_unavailable`; it never restores
//! the pruned references. The cleanup and retention passes retry pending
//! deletions first (`retry_payload_deletions`), least recently tried first;
//! a cleanup pass also retries those of deleted namespaces.
//! A deletion keeps the namespace's storage policy from when it was
//! recorded, so a deleted namespace is still reclaimed.
//!
//! A new registration of the object, in any namespace, fences an older
//! deletion: the row goes and the object stays. PHP also scans its retained
//! payload inventory for the URI. Native references name a registration id,
//! not a URI: a prune records a deletion only when no retained row holds any
//! reference registered for the object, and no later claim can name a
//! registration that is gone. A registration of the object is therefore the
//! only way a retained reference can reach it again.
use super::Result;
use super::external_registry::uri_hash;
use super::external_transport::local_for;
use super::store::now;
use super::transaction::Transaction;
use axum::http::StatusCode;
use serde_json::{Value, json};

/// `retryPending` reads at most 1000 deletions per pass.
const MAX_RETRY: i64 = 1000;
/// An online backup holds reclamation.
const BACKUP_HOLD: &str = "backup_hold";
/// The saved storage policy has no driver, or the provider failed.
const STORAGE_UNAVAILABLE: &str = "storage_unavailable";

/// The prune that released the object maps this to its own failure.
fn reclamation_refused() -> super::RuntimeError {
    super::refuse(StatusCode::SERVICE_UNAVAILABLE, "external_payload_unavailable")
}

store_methods! {
    /// `stage`: records the object's deletion in the transaction that
    /// releases its last registration, which holds the object's lock. The
    /// deletion's id, for `settle_payload_deletions` after the commit.
    /// Refused while an online backup holds reclamation, as
    /// `assertReclamationAllowed` refuses it.
    pub(super) async fn stage_payload_deletion(
        tx: &mut Transaction<'_, DB>,
        namespace: &str,
        uri: &str,
        policy: &Value,
    ) -> Result<String> {
        if Self::backup_hold_active(tx).await? {
            return Err(reclamation_refused());
        }
        let hash = uri_hash(uri);
        if let Some(row) = Self::query("SELECT id,storage_uri FROM dw_external_payload_deletions WHERE namespace=$1 AND storage_uri_sha256=$2")
            .bind(namespace)
            .bind(&hash)
            .fetch_optional(&mut **tx)
            .await?
        {
            // One deletion per object and namespace: a repeat keeps the
            // first, and a hash collision is refused.
            if DB::string(&row, "storage_uri")? != uri {
                return Err(reclamation_refused());
            }
            return DB::string(&row, "id");
        }
        let id = ulid::Ulid::new().to_string();
        let at = now();
        Self::query("INSERT INTO dw_external_payload_deletions(id,namespace,storage_uri,storage_uri_sha256,storage_policy,attempts,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,0,$6,$7)")
            .bind(&id)
            .bind(namespace)
            .bind(uri)
            .bind(&hash)
            .bind(policy.to_string())
            .bind(DB::bind_time(at))
            .bind(DB::bind_time(at))
            .execute(&mut **tx)
            .await?;
        Ok(id)
    }

    /// `flushPending`: each deletion after the transaction that recorded it
    /// has committed. A deletion that cannot finish stays pending with its
    /// reason; nothing here reverses the committed prune.
    pub(super) async fn settle_payload_deletions(&self, ids: &[String]) {
        for id in ids {
            if self.settle_payload_deletion(id).await.is_err() {
                self.record_deletion_failure(id, STORAGE_UNAVAILABLE).await;
                eprintln!("external_payload_deletion_pending: {id}");
            }
        }
    }

    /// `attempt`: under the object's lock, a registration fences the
    /// deletion; otherwise the saved policy's storage deletes the object and
    /// the deletion goes with it. An absent object is already deleted, so a
    /// lost acknowledgement settles on the next attempt.
    async fn settle_payload_deletion(&self, id: &str) -> Result<()> {
        let Some(first) = Self::query("SELECT storage_uri FROM dw_external_payload_deletions WHERE id=$1")
            .bind(id)
            .fetch_optional(&self.pool)
            .await?
        else {
            return Ok(());
        };
        let uri = DB::string(&first, "storage_uri")?;
        let mut tx = self.begin().await?;
        Self::payload_object_lock(&mut tx, &uri).await?;
        let Some(row) = Self::query("SELECT namespace,storage_uri,storage_policy FROM dw_external_payload_deletions WHERE id=$1")
            .bind(id)
            .fetch_optional(&mut *tx)
            .await?
        else {
            tx.commit().await?;
            return Ok(());
        };
        let registered = Self::scalar("SELECT COUNT(*) FROM runtime_external_payloads WHERE storage_uri_sha256=$1 AND storage_uri=$2")
            .bind(uri_hash(&uri))
            .bind(&uri)
            .fetch_one(&mut *tx)
            .await?;
        if registered == 0 {
            let namespace = DB::string(&row, "namespace")?;
            let policy: Value = serde_json::from_str(&DB::string(&row, "storage_policy")?).unwrap_or(Value::Null);
            let failure = match local_for(&policy, &namespace).await {
                Ok(Some(local)) => {
                    if Self::backup_hold_active(&mut tx).await? {
                        Some(BACKUP_HOLD)
                    } else if local.delete_uri(&uri).await.is_err() {
                        Some(STORAGE_UNAVAILABLE)
                    } else {
                        None
                    }
                }
                Ok(None) | Err(_) => Some(STORAGE_UNAVAILABLE),
            };
            if let Some(failure) = failure {
                drop(tx);
                self.record_deletion_failure(id, failure).await;
                return Ok(());
            }
        }
        Self::query("DELETE FROM dw_external_payload_deletions WHERE id=$1").bind(id).execute(&mut *tx).await?;
        tx.commit().await?;
        Ok(())
    }

    /// A failed attempt: the deletion stays for a later pass. Even when this
    /// update fails the committed deletion remains to retry.
    async fn record_deletion_failure(&self, id: &str, failure: &str) {
        let recorded = Self::query("UPDATE dw_external_payload_deletions SET attempts=attempts+1,failure_code=$1,updated_at=$2 WHERE id=$3")
            .bind(failure)
            .bind(DB::bind_time(now()))
            .bind(id)
            .execute(&self.pool)
            .await;
        if recorded.is_err() {
            eprintln!("external_payload_deletion_failure_unrecorded: {id}");
        }
    }

    /// `retryPending`: at most `limit` of the namespace's pending deletions,
    /// least recently tried first.
    pub(super) async fn retry_payload_deletions(&self, namespace: &str, limit: i64) -> Result<()> {
        let mut ids = Vec::new();
        for row in Self::query("SELECT id FROM dw_external_payload_deletions WHERE namespace=$1 ORDER BY updated_at,id LIMIT $2")
            .bind(namespace)
            .bind(limit.clamp(1, MAX_RETRY))
            .fetch_all(&self.pool)
            .await?
        {
            ids.push(DB::string(&row, "id")?);
        }
        self.settle_payload_deletions(&ids).await;
        Ok(())
    }

    /// Up to `limit` deletions whose namespace has been deleted. PHP's
    /// scheduled cleanup retries every namespace's deletions; native has no
    /// scheduled cleanup, so each cleanup pass also reaches these, which no
    /// namespace's own pass would.
    pub(super) async fn retry_orphaned_payload_deletions(&self, limit: i64) -> Result<()> {
        let mut ids = Vec::new();
        for row in Self::query("SELECT id FROM dw_external_payload_deletions WHERE namespace NOT IN (SELECT name FROM workflow_namespaces) ORDER BY updated_at,id LIMIT $1")
            .bind(limit.clamp(1, MAX_RETRY))
            .fetch_all(&self.pool)
            .await?
        {
            ids.push(DB::string(&row, "id")?);
        }
        self.settle_payload_deletions(&ids).await;
        Ok(())
    }

    /// `status`: the namespace's pending deletions, those whose last attempt
    /// failed, and those an online backup holds.
    pub(super) async fn payload_deletion_status(&self, namespace: &str) -> Result<Value> {
        let pending = Self::scalar("SELECT COUNT(*) FROM dw_external_payload_deletions WHERE namespace=$1")
            .bind(namespace)
            .fetch_one(&self.pool)
            .await?;
        let failed = Self::scalar("SELECT COUNT(*) FROM dw_external_payload_deletions WHERE namespace=$1 AND failure_code IS NOT NULL AND failure_code<>$2")
            .bind(namespace)
            .bind(BACKUP_HOLD)
            .fetch_one(&self.pool)
            .await?;
        let blocked = Self::scalar("SELECT COUNT(*) FROM dw_external_payload_deletions WHERE namespace=$1 AND failure_code=$2")
            .bind(namespace)
            .bind(BACKUP_HOLD)
            .fetch_one(&self.pool)
            .await?;
        Ok(json!({"pending": pending, "failed": failed, "blocked": blocked}))
    }

    /// The status where a response reports it: 2.11.8 always adds the counts,
    /// but CI's parity reference is still 2.11.7, whose clean answers have no
    /// such member. Until the reference moves to 2.11.8 native adds them only
    /// when a deletion is pending; a clean answer stays 2.11.7's.
    pub(super) async fn reported_payload_deletions(&self, namespace: &str) -> Result<Option<Value>> {
        let status = self.payload_deletion_status(namespace).await?;
        Ok((status["pending"].as_i64().unwrap_or(0) > 0).then_some(status))
    }
}
