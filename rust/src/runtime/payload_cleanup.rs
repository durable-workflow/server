//! `GET /api/system/external-payload-cleanup` and
//! `POST /api/system/external-payload-cleanup/pass`, as published Server 2.11
//! serves them (`RuntimeExternalPayloadCleanup::runPass`).
//!
//! A pass first renews or drops the namespace's expired completion-upload
//! budgets, then reclaims its expired, unclaimed payload references, oldest
//! first. Each reference is reclaimed under its object lock: a reference that
//! shares its object with another only loses its row, and a sole reference
//! also deletes its object. Each pass is recorded in the namespace's cleanup
//! totals.
//!
//! Native stores objects only with the local driver. A reference it cannot
//! map to the namespace's local storage (no enabled local policy, or an object
//! outside the current root) is blocked rather than deleted. Native never
//! takes an online-backup hold; a hold row PHP left active still blocks
//! deletion, and a missing row means no hold.
use super::Result;
use super::activity_completion_uploads::Context;
use super::laravel_validation::{Rule, failure_messages, rule_errors};
use super::operator_metrics::whole_second;
use super::store::now;
use crate::external_payload::{LocalStorage, REFERENCE_SCHEMA, Reference};
use chrono::{DateTime, Duration, Utc};
use serde_json::{Value, json};

/// `RuntimeExternalPayloadCleanup::DEFAULT_BATCH_SIZE`.
pub(crate) const DEFAULT_BATCH_SIZE: i64 = 100;
/// `RuntimeExternalPayloadCleanup::MAX_BATCH_SIZE`.
pub(crate) const MAX_BATCH_SIZE: i64 = 1000;

/// The pass's rule: `limit` is `nullable|integer|min:1|max:1000`, by default 100.
pub(super) fn pass_limit(body: &Value) -> Result<i64> {
    let body = &super::start_request::normalized(body.clone());
    let rules: &[(&str, &[Rule])] = &[("limit", &[Rule::Nullable, Rule::Integer, Rule::Min(1), Rule::Max(MAX_BATCH_SIZE)])];
    if let Some(error) = failure_messages(rule_errors(body, rules, |_, _| None)) {
        return Err(error);
    }
    Ok(super::laravel_validation::integer(&body["limit"]).unwrap_or(DEFAULT_BATCH_SIZE))
}

enum Outcome {
    Skipped,
    DeletedObject,
    DeletedShared,
    Blocked,
    StorageFailure,
}

#[derive(Default)]
struct Report {
    processed: i64,
    deleted_references: i64,
    deleted_backing_objects: i64,
    shared_objects_preserved: i64,
    skipped: i64,
    blocked: i64,
    storage_driver_failures: i64,
}

store_methods! {
    /// `externalPayloadCleanupStatus`: the namespace's cleanup metrics.
    pub(crate) async fn payload_cleanup_status(&self, config: &super::operator_metrics::MetricsConfig) -> Result<Value> {
        Ok(json!({"namespace": self.namespace.as_ref(), "cleanup": self.external_payload_cleanup_metrics(config).await?,
            "retention_cleanup": self.payload_deletion_status(self.namespace.as_ref()).await?}))
    }

    /// The pass's report; the caller answers 207 when it blocked anything or
    /// a committed retention deletion is still pending.
    pub(crate) async fn payload_cleanup_pass(
        &self,
        limit: i64,
        retention_seconds: i64,
        local: Option<&LocalStorage>,
    ) -> Result<Value> {
        let limit = limit.clamp(1, MAX_BATCH_SIZE);
        let cutoff = now();
        // Committed retention deletions left pending, at most `limit` more.
        self.retry_payload_deletions(self.namespace.as_ref(), limit).await?;
        self.retry_orphaned_payload_deletions(limit).await?;
        self.completion_budget_cleanup(limit, cutoff, retention_seconds.max(1)).await?;
        let candidates = Self::query("SELECT id FROM runtime_external_payloads WHERE namespace=$1 AND retained_at IS NULL AND expires_at IS NOT NULL AND expires_at<=$2 ORDER BY expires_at,id LIMIT $3")
            .bind(self.namespace.as_ref())
            .bind(DB::bind_time(cutoff))
            .bind(limit)
            .fetch_all(&self.pool)
            .await?;
        let mut report = Report::default();
        for candidate in &candidates {
            report.processed += 1;
            match self.cleanup_reference(&DB::string(candidate, "id")?, cutoff, local).await? {
                Outcome::Skipped => report.skipped += 1,
                Outcome::DeletedObject => {
                    report.deleted_references += 1;
                    report.deleted_backing_objects += 1;
                }
                Outcome::DeletedShared => {
                    report.deleted_references += 1;
                    report.shared_objects_preserved += 1;
                }
                Outcome::Blocked => report.blocked += 1,
                Outcome::StorageFailure => {
                    report.blocked += 1;
                    report.storage_driver_failures += 1;
                }
            }
        }
        self.record_cleanup_stats(&report).await?;
        let backlog = Self::scalar("SELECT COUNT(*) FROM runtime_external_payloads WHERE namespace=$1 AND retained_at IS NULL AND expires_at IS NOT NULL AND expires_at<=$2")
            .bind(self.namespace.as_ref())
            .bind(DB::bind_time(cutoff))
            .fetch_one(&self.pool)
            .await?;
        Ok(json!({
            "processed": report.processed, "deleted_references": report.deleted_references,
            "deleted_backing_objects": report.deleted_backing_objects,
            "shared_objects_preserved": report.shared_objects_preserved, "skipped": report.skipped,
            "blocked": report.blocked, "storage_driver_failures": report.storage_driver_failures,
            "backlog": backlog, "scan_limit": limit,
            "scan_pressure": backlog > 0 && candidates.len() as i64 >= limit,
            "retention_cleanup": self.payload_deletion_status(self.namespace.as_ref()).await?,
        }))
    }

    /// `RuntimePayloadCompletionUploads::cleanup`: an expired budget whose
    /// activity may still complete gets another retention period; any other
    /// expired budget is dropped.
    async fn completion_budget_cleanup(&self, limit: i64, cutoff: DateTime<Utc>, retention_seconds: i64) -> Result<()> {
        let candidates = Self::query("SELECT id,context FROM runtime_payload_completion_budgets WHERE expires_at<=$1 AND namespace=$2 ORDER BY expires_at LIMIT $3")
            .bind(DB::bind_time(cutoff))
            .bind(self.namespace.as_ref())
            .bind(limit)
            .fetch_all(&self.pool)
            .await?;
        for candidate in &candidates {
            let id = DB::string(candidate, "id")?;
            // Native holds activity contexts only; any other cannot resume.
            let context = Context::parse(&DB::document_row(candidate, "context")?.to_string()).ok();
            let mut tx = self.begin().await?;
            // As in PHP, completion ownership is read before the object lock.
            let may_resume = match &context {
                Some(context) => {
                    let (task, attempt, owner) = context.lease_identity();
                    self.activity_completion_may_resume(&mut tx, task, attempt, owner).await?
                }
                None => false,
            };
            Self::payload_object_lock(&mut tx, &id).await?;
            let Some(row) = Self::query("SELECT expires_at FROM runtime_payload_completion_budgets WHERE id=$1")
                .bind(&id)
                .fetch_optional(&mut *tx)
                .await?
            else {
                tx.commit().await?;
                continue;
            };
            if DB::instant(&row, "expires_at")? > cutoff {
                tx.commit().await?;
                continue;
            }
            if may_resume {
                let at = now();
                Self::query("UPDATE runtime_payload_completion_budgets SET expires_at=$1,updated_at=$2 WHERE id=$3")
                    .bind(DB::bind_time(at + Duration::seconds(retention_seconds)))
                    .bind(DB::bind_time(at))
                    .bind(&id)
                    .execute(&mut *tx)
                    .await?;
            } else {
                Self::query("DELETE FROM runtime_payload_completion_budgets WHERE id=$1")
                    .bind(&id)
                    .execute(&mut *tx)
                    .await?;
            }
            tx.commit().await?;
        }
        Ok(())
    }

    /// `cleanupReference`, under the reference's object lock.
    async fn cleanup_reference(&self, id: &str, cutoff: DateTime<Utc>, local: Option<&LocalStorage>) -> Result<Outcome> {
        let Some(first) = Self::query("SELECT storage_uri FROM runtime_external_payloads WHERE id=$1")
            .bind(id)
            .fetch_optional(&self.pool)
            .await?
        else {
            return Ok(Outcome::Skipped);
        };
        let uri = DB::string(&first, "storage_uri")?;
        let mut tx = self.begin().await?;
        Self::payload_object_lock(&mut tx, &uri).await?;
        let Some(row) = Self::query("SELECT * FROM runtime_external_payloads WHERE id=$1")
            .bind(id)
            .fetch_optional(&mut *tx)
            .await?
        else {
            tx.commit().await?;
            return Ok(Outcome::Skipped);
        };
        if DB::optional_instant(&row, "retained_at")?.is_some()
            || DB::optional_instant(&row, "expires_at")?.is_none_or(|expires| expires > cutoff)
        {
            tx.commit().await?;
            return Ok(Outcome::Skipped);
        }
        let uri = DB::string(&row, "storage_uri")?;
        let owners = Self::scalar("SELECT COUNT(*) FROM runtime_external_payloads WHERE storage_uri_sha256=$1 AND storage_uri=$2")
            .bind(DB::string(&row, "storage_uri_sha256")?)
            .bind(&uri)
            .fetch_one(&mut *tx)
            .await?;
        if owners > 1 {
            Self::query("DELETE FROM runtime_external_payloads WHERE id=$1").bind(id).execute(&mut *tx).await?;
            tx.commit().await?;
            return Ok(Outcome::DeletedShared);
        }
        let reference = Reference {
            schema: REFERENCE_SCHEMA,
            reference_id: id.to_owned(),
            codec: "avro",
            size_bytes: DB::number(&row, "size_bytes")?,
            sha256: DB::string(&row, "sha256")?,
        };
        let Some(local) = local.filter(|local| local.uri_for(&reference).is_ok_and(|current| current == uri)) else {
            tx.commit().await?;
            return Ok(Outcome::Blocked);
        };
        if Self::backup_hold_active(&mut tx).await? {
            tx.commit().await?;
            return Ok(Outcome::Blocked);
        }
        if local.delete(&reference).await.is_err() {
            tx.commit().await?;
            return Ok(Outcome::StorageFailure);
        }
        Self::query("DELETE FROM runtime_external_payloads WHERE id=$1").bind(id).execute(&mut *tx).await?;
        tx.commit().await?;
        Ok(Outcome::DeletedObject)
    }

    /// `ExternalPayloadBackupHold::deleting`: an owner whose hold has not
    /// expired pauses reclamation.
    pub(super) async fn backup_hold_active(tx: &mut super::transaction::Transaction<'_, DB>) -> Result<bool> {
        // PostgreSQL keeps the owner as a uuid; only its presence matters.
        let Some(hold) = Self::query("SELECT CASE WHEN owner IS NULL THEN 0 ELSE 1 END AS held,expires_at FROM runtime_external_payload_backup_hold WHERE id=1")
            .fetch_optional(&mut **tx)
            .await?
        else {
            return Ok(false);
        };
        Ok(DB::number(&hold, "held")? == 1
            && DB::optional_instant(&hold, "expires_at")?.is_some_and(|expires| expires > now()))
    }

    /// `recordStats` for an existing namespace. Times are whole seconds, as
    /// Eloquent stores them.
    async fn record_cleanup_stats(&self, report: &Report) -> Result<()> {
        let namespace = self.namespace.as_ref();
        if Self::scalar("SELECT COUNT(*) FROM workflow_namespaces WHERE name=$1")
            .bind(namespace)
            .fetch_one(&self.pool)
            .await?
            == 0
        {
            return Ok(());
        }
        let at = whole_second(now());
        let mut tx = self.begin().await?;
        if Self::scalar("SELECT COUNT(*) FROM runtime_external_payload_cleanup_stats WHERE namespace=$1")
            .bind(namespace)
            .fetch_one(&mut *tx)
            .await?
            == 0
        {
            Self::query("INSERT INTO runtime_external_payload_cleanup_stats(namespace,created_at,updated_at) VALUES ($1,$2,$3)")
                .bind(namespace)
                .bind(DB::bind_time(at))
                .bind(DB::bind_time(at))
                .execute(&mut *tx)
                .await?;
        }
        let status = if report.storage_driver_failures > 0 {
            "failed"
        } else if report.blocked > 0 {
            "blocked"
        } else {
            "completed"
        };
        Self::query(concat!(
            "UPDATE runtime_external_payload_cleanup_stats SET passes_total=passes_total+1,",
            "deleted_references_total=deleted_references_total+$1,deleted_backing_objects_total=deleted_backing_objects_total+$2,",
            "shared_objects_preserved_total=shared_objects_preserved_total+$3,blocked_outcomes_total=blocked_outcomes_total+$4,",
            "storage_driver_failures_total=storage_driver_failures_total+$5,last_processed=$6,last_deleted_references=$7,",
            "last_deleted_backing_objects=$8,last_shared_objects_preserved=$9,last_blocked_outcomes=$10,",
            "last_storage_driver_failures=$11,last_pass_status=$12,last_completed_at=$13,updated_at=$14 WHERE namespace=$15"
        ))
        .bind(report.deleted_references)
        .bind(report.deleted_backing_objects)
        .bind(report.shared_objects_preserved)
        .bind(report.blocked)
        .bind(report.storage_driver_failures)
        .bind(report.processed)
        .bind(report.deleted_references)
        .bind(report.deleted_backing_objects)
        .bind(report.shared_objects_preserved)
        .bind(report.blocked)
        .bind(report.storage_driver_failures)
        .bind(status)
        .bind(DB::bind_time(at))
        .bind(DB::bind_time(at))
        .bind(namespace)
        .execute(&mut *tx)
        .await?;
        if report.storage_driver_failures > 0 {
            Self::query("UPDATE runtime_external_payload_cleanup_stats SET last_storage_failure_at=$1 WHERE namespace=$2")
                .bind(DB::bind_time(at))
                .bind(namespace)
                .execute(&mut *tx)
                .await?;
        }
        tx.commit().await?;
        Ok(())
    }
}
