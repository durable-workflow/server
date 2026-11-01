//! Published PHP 2.11.8's pending external payload deletions
//! (`runtime_external_payload_deletions`, Server #789/#911). PHP records each
//! provider deletion in the transaction that released the object and deletes
//! the object after that commit, retrying a failure. Takeover carries every
//! pending deletion over to native's `dw_external_payload_deletions`, which
//! native retries the same way, so none is dropped and no bytes are deleted
//! during takeover.
//!
//! A deletion native could never finish is refused before takeover changes
//! anything: one whose saved storage policy names a driver native does not
//! serve (the rule `storage_scan` applies to live namespace policies), or one
//! whose recorded failure is not one PHP writes. The report counts rows by
//! reason and never contains namespaces, URIs or policy values.
use super::storage_scan;
use crate::runtime::{Result, backend::Backend};
use serde_json::{Value, json};
use sqlx::{Executor, IntoArguments};
use std::collections::BTreeMap;

const ROWS: &str = "SELECT storage_policy,failure_code FROM runtime_external_payload_deletions ORDER BY id";

pub(super) async fn inspect<DB: Backend>(
    connection: &mut DB::Connection,
    counts: &BTreeMap<String, i64>,
) -> Result<Value>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
{
    let pending = counts.get("runtime_external_payload_deletions").copied().unwrap_or(0);
    let mut reasons = BTreeMap::<&'static str, i64>::new();
    if pending != 0 {
        let rows = sqlx::query(sqlx::AssertSqlSafe(DB::statement(ROWS)))
            .fetch_all(&mut *connection)
            .await?;
        for row in &rows {
            let policy = DB::document_row(row, "storage_policy")?;
            let failure = DB::optional_string(row, "failure_code")?;
            if let Some(reason) = unsupported(&policy, failure.as_deref()) {
                *reasons.entry(reason).or_default() += 1;
            }
        }
    }
    Ok(json!({
        "pending": pending,
        "unsupported": reasons.values().sum::<i64>(),
        "reasons": reasons,
    }))
}

/// The failures 2.11.8's `RuntimeExternalPayloadDeletions::attempt` records.
fn unsupported(policy: &Value, failure: Option<&str>) -> Option<&'static str> {
    if !matches!(failure, None | Some("backup_hold") | Some("storage_unavailable")) {
        return Some("external_payload_deletion_failure_unrecognized");
    }
    storage_scan::unsupported(&Some(policy.clone()))
        .map(|_| "external_payload_deletion_storage_driver_unsupported")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn only_a_deletion_native_could_never_finish_is_refused() {
        for (policy, failure) in [
            (json!([]), None),
            (json!({}), Some("storage_unavailable")),
            (json!({"driver": "local", "enabled": true, "config": {"uri": "file:///data"}}), None),
            (json!({"driver": "local"}), Some("backup_hold")),
            (json!({"driver": "s3", "enabled": false}), None),
        ] {
            assert_eq!(unsupported(&policy, failure), None, "{policy} {failure:?}");
        }
        assert_eq!(
            unsupported(&json!({"driver": "s3", "config": {"bucket": "b"}}), None),
            Some("external_payload_deletion_storage_driver_unsupported")
        );
        assert_eq!(
            unsupported(&json!({"driver": "local"}), Some("provider_quota")),
            Some("external_payload_deletion_failure_unrecognized")
        );
    }
}
