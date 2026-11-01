//! Whether every PHP namespace's external payload storage policy is one native
//! can serve. Native stores and serves only the `local` driver. After a
//! takeover, an enabled `s3`, `gcs`, `azure`, `custom` or unknown driver would
//! answer every upload in its namespace with 503, where PHP may have served it.
//! A disabled policy changes nothing, as in PHP. The report counts namespaces
//! by reason and never contains namespace names or policy values.
use crate::runtime::{Result, backend::Backend};
use serde_json::{Value, json};
use sqlx::{Executor, IntoArguments};
use std::collections::BTreeMap;

const NAMESPACES: &str = "SELECT external_payload_storage FROM workflow_namespaces ORDER BY id";

pub(super) async fn inspect<DB: Backend>(connection: &mut DB::Connection) -> Result<Value>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
{
    let rows = sqlx::query(sqlx::AssertSqlSafe(DB::statement(NAMESPACES)))
        .fetch_all(&mut *connection)
        .await?;
    let mut reasons = BTreeMap::<&'static str, i64>::new();
    for row in &rows {
        if let Some(reason) = unsupported(&DB::optional_document_row(row, "external_payload_storage")?) {
            *reasons.entry(reason).or_default() += 1;
        }
    }
    Ok(json!({
        "namespaces": rows.len(),
        "unsupported": reasons.values().sum::<i64>(),
        "reasons": reasons,
    }))
}

/// PHP's `NamespaceExternalPayloadStorage::untrackedDriverFor`: an empty
/// policy is unconfigured, `enabled: false` disables it, and anything else
/// names the driver that serves the namespace's uploads.
pub(super) fn unsupported(policy: &Option<Value>) -> Option<&'static str> {
    let policy = policy.as_ref()?;
    let configured = match policy {
        Value::Object(fields) => !fields.is_empty(),
        Value::Array(items) => !items.is_empty(),
        _ => false,
    };
    if !configured || policy["enabled"] == false || policy["driver"] == "local" {
        return None;
    }
    Some("external_payload_storage_driver_unsupported")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn only_an_enabled_policy_native_cannot_serve_is_refused() {
        let reason = |policy: Option<Value>| unsupported(&policy);
        for served in [
            None,
            Some(Value::Null),
            Some(json!({})),
            Some(json!([])),
            Some(json!({"driver": "local", "enabled": true, "threshold_bytes": 16})),
            Some(json!({"driver": "local"})),
            Some(json!({"driver": "s3", "enabled": false, "config": {"bucket": "b"}})),
        ] {
            assert_eq!(reason(served.clone()), None, "{served:?}");
        }
        for refused in [
            json!({"driver": "s3", "config": {"bucket": "b"}}),
            json!({"driver": "gcs", "enabled": true}),
            json!({"driver": "custom", "enabled": null, "config": {"scheme": "x"}}),
            json!({"enabled": true}),
            json!({"driver": "LOCAL"}),
        ] {
            assert_eq!(reason(Some(refused.clone())), Some("external_payload_storage_driver_unsupported"), "{refused}");
        }
    }
}
