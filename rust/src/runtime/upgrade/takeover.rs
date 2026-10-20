//! Backup-first, one-way takeover of a stopped PHP SQLite database, following
//! docs/rust-server-upgrade-takeover.md.
//!
//! Everything happens in one `BEGIN EXCLUSIVE` transaction: the inspections,
//! the fingerprint check against the operator's verified backup, the layout
//! conversion, the native relations, the migration ledger and native's own
//! startup verification. An interrupted or refused takeover therefore leaves
//! the PHP database exactly as it was, and a committed one is a native
//! database that PHP 2.5.14 and later refuse to open.
use super::{
    Result, close, expired, fingerprint, open_sqlite_file, queue_binding::QueueSourceConfig,
    queue_config, refuse, schema, sqlite_inventory,
};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{AssertSqlSafe, Connection, SqliteConnection};
use std::time::Duration;
use tokio::time::{Instant, timeout_at};

/// Takeover reads every row twice (inspection and fingerprint).
const BUDGET: Duration = Duration::from_secs(3600);

pub async fn take_over_sqlite(
    path: &str,
    backup_fingerprint: &str,
    queues: &QueueSourceConfig<'_>,
) -> Result<Value> {
    queue_config(Some(queues))?;
    if !backup_fingerprint.starts_with("sha256:") || backup_fingerprint.len() != 71 {
        return Err(refuse(
            StatusCode::BAD_REQUEST,
            "upgrade_backup_fingerprint_required",
        ));
    }
    let deadline = Instant::now() + BUDGET;
    let mut connection = open_sqlite_file(path, deadline, true).await?;
    let result = timeout_at(deadline, async {
        let mut tx = connection.begin_with("BEGIN EXCLUSIVE").await?;
        match take_over(&mut tx, backup_fingerprint, queues).await {
            Ok(report) => {
                tx.commit().await?;
                Ok(report)
            }
            Err(error) => {
                tx.rollback().await?;
                Err(error)
            }
        }
    })
    .await
    .map_err(|_| expired())
    .and_then(|result| result);
    close(connection.close()).await?;
    result
}

async fn take_over(
    tx: &mut SqliteConnection,
    backup_fingerprint: &str,
    queues: &QueueSourceConfig<'_>,
) -> Result<Value> {
    let inventory = sqlite_inventory(&mut *tx, Some(queues), true).await?;
    qualified(&inventory)?;
    // The same digest the operator compared with the restored backup. Any
    // write after that backup changes it, so nothing below can run.
    let digest = fingerprint::sqlite_digest(&mut *tx).await?;
    if digest["fingerprint"] != backup_fingerprint {
        return Err(refuse(
            StatusCode::CONFLICT,
            "upgrade_backup_fingerprint_mismatch",
        ));
    }
    let layout = inventory["source_layout"]
        .as_str()
        .ok_or_else(|| refuse(StatusCode::CONFLICT, "upgrade_source_catalog_unqualified"))?;
    sqlx::raw_sql(AssertSqlSafe(schema::native_relations_sql()?))
        .execute(&mut *tx)
        .await?;
    let converted = convert(&mut *tx, layout).await?;
    schema::record_migration(&mut *tx).await?;
    // Exactly the check native startup makes: marker, ledger and catalog.
    schema::verify(&mut *tx).await?;
    Ok(json!({
        "schema":"durable-workflow.rust-upgrade-takeover.v1",
        "status":"taken_over", "backend":"sqlite", "source_layout":layout,
        "fingerprint":digest["fingerprint"], "table_rows":inventory["table_rows"],
        "converted":converted, "backup_fingerprint_matched":true,
        // What the inspections cannot yet establish stays explicit.
        "nested_payloads_verified":false, "other_root_payload_columns_verified":false,
        "stored_representation_compatibility_verified":false
    }))
}

/// Takeover needs every inspection result it relies on, and refuses what
/// native cannot represent yet: external payloads and service calls.
fn qualified(inventory: &Value) -> Result<()> {
    if inventory["queue_inspection"]["status"] != "known_subset_bound" {
        return Err(refuse(
            StatusCode::CONFLICT,
            "upgrade_source_queue_binding_unqualified",
        ));
    }
    let payloads = &inventory["payload_inspection"];
    if payloads["references_metadata_supported"] != true || payloads["inline_values_verified"] != true
    {
        return Err(refuse(
            StatusCode::CONFLICT,
            "upgrade_source_payload_metadata_unqualified",
        ));
    }
    let total = |field: &str| {
        payloads["columns"].as_array().map_or(0, |columns| {
            columns
                .iter()
                .map(|column| column["counts"][field].as_i64().unwrap_or(0))
                .sum::<i64>()
        })
    };
    if total("php_v1_references") != 0
        || total("unqualified_references") != 0
        || total("other_unvalidated") != 0
        || inventory["table_rows"]["runtime_external_payloads"] != 0
    {
        return Err(refuse(
            StatusCode::CONFLICT,
            "upgrade_source_external_payloads_unsupported",
        ));
    }
    Ok(())
}

/// Converts a recorded PHP release layout to native's (the design's recipes).
async fn convert(tx: &mut SqliteConnection, layout: &str) -> Result<Value> {
    let (incarnation_table, token_column) = match layout {
        "frozen" => (false, false),
        "php-2.8.0" | "php-2.9.7" => (true, true),
        _ => {
            return Err(refuse(
                StatusCode::CONFLICT,
                "upgrade_source_layout_unconverted",
            ));
        }
    };
    let calls: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM workflow_service_calls")
        .fetch_one(&mut *tx)
        .await?;
    if calls != 0 {
        return Err(refuse(
            StatusCode::CONFLICT,
            "upgrade_source_service_calls_unsupported",
        ));
    }
    let now = super::super::store::now()
        .format("%Y-%m-%d %H:%M:%S%.6f")
        .to_string();
    let mut copied = 0;
    if incarnation_table {
        // Same statuses as native. Native keeps the recovered count only on
        // deregistration and a finish time on every retired incarnation.
        copied = sqlx::query("INSERT INTO dw_worker_registration_incarnations(token,namespace,worker_id,status,recovered_workflow_task_count,finished_at,created_at,updated_at) SELECT token,namespace,worker_id,status,CASE WHEN status='deregistered' THEN recovered_workflow_task_count END,CASE WHEN status='active' THEN NULL ELSE COALESCE(finished_at,updated_at,created_at,$1) END,COALESCE(created_at,$1),COALESCE(updated_at,created_at,$1) FROM workflow_worker_registration_incarnations WHERE status IN ('active','superseded','deregistered')")
            .bind(&now)
            .execute(&mut *tx)
            .await?
            .rows_affected();
        let total: i64 =
            sqlx::query_scalar("SELECT COUNT(*) FROM workflow_worker_registration_incarnations")
                .fetch_one(&mut *tx)
                .await?;
        if copied != total as u64 {
            return Err(refuse(
                StatusCode::CONFLICT,
                "upgrade_source_incarnation_unqualified",
            ));
        }
    }
    // Every active registration gets the authority native expects. A PHP
    // 2.8.0+ worker keeps the token it was given; an older one gets a new token.
    let missing: Vec<(String, String, Option<String>)> = sqlx::query_as(AssertSqlSafe(format!(
        "SELECT r.namespace,r.worker_id,{} FROM workflow_worker_registrations r WHERE r.status='active' AND NOT EXISTS (SELECT 1 FROM dw_worker_registration_incarnations i WHERE i.namespace=r.namespace AND i.worker_id=r.worker_id AND i.status='active') ORDER BY r.namespace,r.worker_id",
        if token_column { "r.registration_token" } else { "NULL" }
    )))
    .fetch_all(&mut *tx)
    .await?;
    for (namespace, worker_id, token) in &missing {
        let token = match token {
            Some(token) => token.clone(),
            None => super::super::worker_incarnations::token().await?,
        };
        sqlx::query("INSERT INTO dw_worker_registration_incarnations(token,namespace,worker_id,status,created_at,updated_at) VALUES ($1,$2,$3,'active',$4,$5)")
            .bind(token)
            .bind(namespace)
            .bind(worker_id)
            .bind(&now)
            .bind(&now)
            .execute(&mut *tx)
            .await?;
    }
    if incarnation_table {
        sqlx::raw_sql(
            "DROP TABLE workflow_worker_registration_incarnations; \
             ALTER TABLE workflow_worker_registrations DROP COLUMN registration_token; \
             DROP INDEX wf_service_calls_deadline_idx; \
             ALTER TABLE workflow_service_calls DROP COLUMN call_deadline_at;",
        )
        .execute(&mut *tx)
        .await?;
    }
    let delivery_references = if layout == "php-2.9.7" {
        // PHP's own queue delivery bookkeeping; native does not use the jobs.
        let references: i64 = sqlx::query_scalar(
            "SELECT COUNT(*) FROM workflow_tasks WHERE queue_delivery_reference IS NOT NULL",
        )
        .fetch_one(&mut *tx)
        .await?;
        sqlx::raw_sql("ALTER TABLE workflow_tasks DROP COLUMN queue_delivery_reference")
            .execute(&mut *tx)
            .await?;
        references
    } else {
        0
    };
    Ok(json!({
        "incarnations_copied":copied, "incarnations_created":missing.len(),
        "queue_delivery_references_removed":delivery_references
    }))
}
