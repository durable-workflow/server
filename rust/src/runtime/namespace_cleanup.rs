//! `DELETE /api/namespaces/{namespace}`: PHP's NamespaceController::destroy
//! over NamespaceLifecycleCleanup. One transaction deletes every row the
//! namespace owns in PHP's order and reports the non-zero counts under PHP's
//! keys. PHP checks each table's columns first; the statements here use the
//! columns the verified schema has, so a table without a run or instance
//! column is matched by the other, as PHP's column check does.
//!
//! The namespace's external payloads go first, as published 2.11.8's
//! `ExternalPayloadRetentionCleanup::deleteForNamespaceCleanup` and
//! `RuntimeExternalPayloadRegistry::deleteForNamespace` release them
//! (`delete_namespace_payloads`). Their provider deletions are recorded in the
//! delete's transaction and run only after it commits (`payload_deletions`),
//! so a failed delete keeps every object. The answer always carries their
//! `external_payload_cleanup` counts, as 2.11.8's does, and is 207 while a
//! deletion is still pending.
//! A namespace holding a reference native cannot settle (PHP's stored
//! format, an envelope, or one on a stream) refuses before changing anything,
//! with PHP's answer for unavailable external payload storage.
use super::external_registry::{object_bucket, uri_hash};
use super::external_transport::{local_for, typed_error};
use super::retention::reference;
use super::retention_payloads::native_reference;
use super::transaction::Transaction;
use super::{Result, RuntimeError};
use crate::external_payload::{LocalStorage, Reference};
use axum::http::StatusCode;
use serde_json::{Map, Value, json};

/// The namespace's runs, instances and tasks, as subqueries. Each takes the
/// namespace in its own numbered placeholder.
macro_rules! runs {
    ($n:literal) => {
        concat!("SELECT id FROM workflow_runs WHERE namespace=", $n)
    };
}
macro_rules! instances {
    ($a:literal, $b:literal) => {
        concat!(
            "SELECT id FROM workflow_instances WHERE namespace=", $a,
            " UNION SELECT workflow_instance_id FROM workflow_runs WHERE namespace=", $b
        )
    };
}
macro_rules! tasks {
    ($a:literal, $b:literal) => {
        concat!(
            "SELECT id FROM workflow_tasks WHERE namespace=", $a,
            " UNION SELECT id FROM workflow_tasks WHERE workflow_run_id IN (", runs!($b), ")"
        )
    };
}
/// A table matched by its run and instance columns.
macro_rules! by_run_or_instance {
    ($table:literal) => {
        (
            $table,
            concat!(
                "DELETE FROM ", $table, " WHERE workflow_run_id IN (", runs!("$1"),
                ") OR workflow_instance_id IN (", instances!("$2", "$3"), ")"
            ),
            3,
        )
    };
}
/// A table with only a run column.
macro_rules! by_run {
    ($table:literal) => {
        ($table, concat!("DELETE FROM ", $table, " WHERE workflow_run_id IN (", runs!("$1"), ")"), 1)
    };
}
macro_rules! by_instance_or_run {
    ($table:literal, $instance:literal, $run:literal) => {
        (
            $table,
            concat!(
                "DELETE FROM ", $table, " WHERE ", $instance, " IN (", instances!("$1", "$2"),
                ") OR ", $run, " IN (", runs!("$3"), ")"
            ),
            3,
        )
    };
}
macro_rules! by_namespace {
    ($table:literal) => {
        ($table, concat!("DELETE FROM ", $table, " WHERE namespace=$1"), 1)
    };
}

/// PHP's report key, statement and namespace binds, in PHP's order.
const CLEANUP: &[(&str, &str, usize)] = &[
    by_namespace!("runtime_external_payload_cleanup_stats"),
    (
        "activity_attempts",
        concat!(
            "DELETE FROM activity_attempts WHERE workflow_run_id IN (", runs!("$1"),
            ") OR workflow_task_id IN (", tasks!("$2", "$3"), ")"
        ),
        3,
    ),
    by_instance_or_run!("workflow_updates", "workflow_instance_id", "workflow_run_id"),
    by_instance_or_run!("workflow_signal_records", "workflow_instance_id", "workflow_run_id"),
    by_instance_or_run!("workflow_messages", "workflow_instance_id", "workflow_run_id"),
    by_instance_or_run!("workflow_child_calls", "parent_workflow_instance_id", "parent_workflow_run_id"),
    by_instance_or_run!("workflow_links", "parent_workflow_instance_id", "parent_workflow_run_id"),
    (
        "workflow_service_calls",
        "DELETE FROM workflow_service_calls WHERE namespace=$1 OR target_namespace=$2",
        2,
    ),
    // A caller namespace's context is cleared from the calls that remain, so
    // a namespace recreated with the same name does not inherit them.
    (
        "workflow_service_call_caller_contexts",
        concat!(
            "UPDATE workflow_service_calls SET caller_namespace=NULL,caller_workflow_instance_id=NULL,",
            "caller_workflow_run_id=NULL,caller_principal_subject=NULL,caller_principal_method=NULL,",
            "caller_principal_roles=NULL,caller_principal_tenant=NULL,caller_principal_claims=NULL ",
            "WHERE caller_namespace=$1 OR caller_workflow_instance_id IN (", instances!("$2", "$3"),
            ") OR caller_workflow_run_id IN (", runs!("$4"), ")"
        ),
        4,
    ),
    by_run!("activity_executions"),
    by_run_or_instance!("workflow_commands"),
    by_run!("workflow_failures"),
    by_run!("workflow_history_events"),
    by_run_or_instance!("workflow_memos"),
    by_run_or_instance!("workflow_run_lineage_entries"),
    by_run_or_instance!("workflow_run_timer_entries"),
    by_run_or_instance!("workflow_run_timeline_entries"),
    by_run_or_instance!("workflow_run_waits"),
    by_run!("workflow_run_timers"),
    by_run_or_instance!("workflow_search_attributes"),
    by_run!("workflow_tasks"),
    by_namespace!("workflow_schedule_history_events"),
    by_namespace!("workflow_schedules"),
    by_namespace!("workflow_service_operations"),
    by_namespace!("workflow_services"),
    by_namespace!("workflow_service_endpoints"),
    by_namespace!("workflow_worker_compatibility_heartbeats"),
    by_namespace!("workflow_worker_sessions"),
    by_namespace!("workflow_worker_build_id_rollouts"),
    by_namespace!("workflow_worker_registrations"),
    by_namespace!("search_attribute_definitions"),
    by_namespace!("workflow_durable_stream_items"),
    by_namespace!("workflow_durable_streams"),
    by_namespace!("workflow_inbound_stream_items"),
    by_namespace!("workflow_inbound_streams"),
    // Run summaries have no run column; PHP matches them by instance.
    (
        "workflow_run_summaries",
        concat!("DELETE FROM workflow_run_summaries WHERE workflow_instance_id IN (", instances!("$1", "$2"), ")"),
        2,
    ),
    by_namespace!("workflow_runs"),
    by_namespace!("workflow_instances"),
];

/// Native's own rows for the namespace. PHP has no such tables, so they are
/// not reported. Task-keyed receipts cascade with their tasks.
const NATIVE_CLEANUP: &[&str] = &[
    "DELETE FROM dw_poll_receipts WHERE namespace=$1",
    "DELETE FROM dw_worker_registration_incarnations WHERE namespace=$1",
    "DELETE FROM dw_worker_session_holders WHERE namespace=$1",
    "DELETE FROM dw_stream_reclaims WHERE namespace=$1",
    "DELETE FROM dw_service_call_deadlines WHERE namespace=$1",
];

/// The namespace's payload columns that can hold an external reference, each
/// value that may hold one: the columns native writes its stored reference
/// to, as run retention reads them (`retention::REFERENCE_VALUES`). Each
/// placeholder binds the namespace.
const REFERENCE_VALUES: &str = concat!(
    "SELECT arguments AS value FROM workflow_runs WHERE namespace=$1 AND ", reference!("arguments"),
    " UNION ALL SELECT output FROM workflow_runs WHERE namespace=$2 AND ", reference!("output"),
    " UNION ALL SELECT payload FROM workflow_commands WHERE workflow_run_id IN (", runs!("$3"), ") AND ", reference!("payload"),
    " UNION ALL SELECT arguments FROM workflow_signal_records WHERE workflow_run_id IN (", runs!("$4"), ") AND ", reference!("arguments"),
    " UNION ALL SELECT arguments FROM workflow_updates WHERE workflow_run_id IN (", runs!("$5"), ") AND ", reference!("arguments"),
    " UNION ALL SELECT result FROM workflow_updates WHERE workflow_run_id IN (", runs!("$6"), ") AND ", reference!("result"),
    " UNION ALL SELECT arguments FROM activity_executions WHERE workflow_run_id IN (", runs!("$7"), ") AND ", reference!("arguments"),
    " UNION ALL SELECT result FROM activity_executions WHERE workflow_run_id IN (", runs!("$8"), ") AND ", reference!("result"),
);
const REFERENCE_VALUE_BINDS: usize = 8;

/// Whether a row outside the namespace holds a reference whose id matches
/// the `LIKE` pattern, in the columns of `REFERENCE_VALUES`: PHP's
/// `retainedReferencesForNamespaceCleanup` for native references. Binds
/// alternate the namespace and the pattern, one per position.
const RETAINED_SQL: &str = concat!(
    "SELECT (SELECT COUNT(*) FROM workflow_runs WHERE namespace<>$1 AND (arguments LIKE $2 OR output LIKE $3))",
    "+(SELECT COUNT(*) FROM workflow_commands WHERE workflow_run_id IN (SELECT id FROM workflow_runs WHERE namespace<>$4) AND payload LIKE $5)",
    "+(SELECT COUNT(*) FROM workflow_signal_records WHERE workflow_run_id IN (SELECT id FROM workflow_runs WHERE namespace<>$6) AND arguments LIKE $7)",
    "+(SELECT COUNT(*) FROM workflow_updates WHERE workflow_run_id IN (SELECT id FROM workflow_runs WHERE namespace<>$8) AND (arguments LIKE $9 OR result LIKE $10))",
    "+(SELECT COUNT(*) FROM activity_executions WHERE workflow_run_id IN (SELECT id FROM workflow_runs WHERE namespace<>$11) AND (arguments LIKE $12 OR result LIKE $13))",
);
/// `RETAINED_SQL`'s binds: true for the namespace, false for the pattern.
const RETAINED_BINDS: [bool; 13] = [true, false, false, true, false, true, false, true, false, false, true, false, false];

/// A reference native cannot settle: refused before anything changes.
fn unsettled(name: &str) -> RuntimeError {
    RuntimeError::Protocol {
        status: StatusCode::SERVICE_UNAVAILABLE,
        response: json!({
            "message": "Namespace cleanup requires deleting external payloads, but external payload storage is unavailable.",
            "reason": "external_payload_storage_driver_unavailable",
            "namespace": name,
            "error": "The native server cannot settle this namespace's external payload references.",
        }),
    }
}

/// `ExternalPayloadStorageUnavailable` from the reference cleanup: the
/// namespace has no storage to delete an object it alone holds.
fn driver_unavailable(name: &str) -> RuntimeError {
    RuntimeError::Protocol {
        status: StatusCode::SERVICE_UNAVAILABLE,
        response: json!({
            "message": "Namespace cleanup requires deleting external payloads, but external payload storage is unavailable.",
            "reason": "external_payload_storage_driver_unavailable",
            "namespace": name,
            "error": "External payload storage driver is unavailable for namespace cleanup.",
        }),
    }
}

/// PHP wraps a failed delete in the reference cleanup in a plain
/// `RuntimeException`, which Laravel answers as a server error.
fn cleanup_failed() -> RuntimeError {
    RuntimeError::Protocol { status: StatusCode::INTERNAL_SERVER_ERROR, response: json!({"message": "Server Error"}) }
}

/// `RuntimeExternalPayloadRegistry::unavailable` from the registry's own
/// namespace cleanup, answered as PHP's typed external payload error.
fn registry_unavailable(message: &'static str) -> RuntimeError {
    typed_error(StatusCode::SERVICE_UNAVAILABLE, "external_payload_unavailable", message)
}

pub(super) fn not_found(name: &str) -> RuntimeError {
    RuntimeError::Protocol {
        status: StatusCode::NOT_FOUND,
        response: json!({"message": format!("Namespace [{name}] not found."), "reason": "namespace_not_found", "namespace": name}),
    }
}

store_methods! {
    pub(super) async fn delete_namespace(&self, name: &str) -> Result<Value> {
        let name = name.to_ascii_lowercase();
        let mut tx = self.begin().await?;
        if Self::scalar("SELECT COUNT(*) FROM workflow_namespaces WHERE name=$1")
            .bind(&name)
            .fetch_one(&mut *tx)
            .await?
            == 0
        {
            return Err(not_found(&name));
        }
        let ([external_payloads, registrations], staged) = Self::delete_namespace_payloads(&mut tx, &name).await?;
        let mut deleted = Map::new();
        for (key, count) in [("external_payloads_deleted", external_payloads), ("runtime_external_payload_references", registrations)] {
            if count > 0 {
                deleted.insert(key.into(), json!(count));
            }
        }
        for (key, sql, binds) in CLEANUP {
            let mut query = Self::query(sql);
            for _ in 0..*binds {
                query = query.bind(&name);
            }
            let count = DB::affected(query.execute(&mut *tx).await?);
            if count > 0 {
                deleted.insert((*key).into(), json!(count));
            }
        }
        // The namespace's histories are gone; no cached read may survive.
        tx.scope_history = None;
        for sql in NATIVE_CLEANUP {
            Self::query(sql).bind(&name).execute(&mut *tx).await?;
        }
        Self::query("DELETE FROM workflow_namespaces WHERE name=$1").bind(&name).execute(&mut *tx).await?;
        tx.commit().await?;
        // The delete has committed; only now may provider objects go. A
        // deletion left pending answers 207.
        self.settle_payload_deletions(&staged).await;
        Ok(json!({
            "name": name, "status": "deleted", "deleted": deleted,
            "external_payload_cleanup": self.payload_deletion_status(&name).await?,
        }))
    }

    /// The namespace's external payloads, inside its delete's transaction:
    /// the objects its references settled, then the registrations left, and
    /// the deletions recorded for the caller to settle after the commit.
    ///
    /// First `deleteForNamespaceCleanup`: each object the namespace's rows
    /// reference that no row outside it holds is asked again under its object
    /// lock, in lock order; this namespace's registration goes, and the object
    /// too when nothing else registers it (recorded, `stage`). Each one asked
    /// and not held counts.
    /// Deleting any needs the namespace's storage, or the delete refuses.
    ///
    /// Then `RuntimeExternalPayloadRegistry::deleteForNamespace`: every
    /// registration left goes, uploaded but unreferenced or held from
    /// outside, and its object too when no other namespace registers it.
    async fn delete_namespace_payloads(tx: &mut Transaction<'_, DB>, name: &str) -> Result<([i64; 2], Vec<String>)> {
        let mut query = Self::query(REFERENCE_VALUES);
        for _ in 0..REFERENCE_VALUE_BINDS {
            query = query.bind(name);
        }
        let mut references: Vec<Reference> = Vec::new();
        for row in query.fetch_all(&mut **tx).await? {
            let Some(reference) = DB::optional_string(&row, "value")?.as_deref().and_then(native_reference) else {
                return Err(unsettled(name));
            };
            if !references.iter().any(|known| known.reference_id == reference.reference_id) {
                references.push(reference);
            }
        }
        if Self::scalar(DB::NAMESPACE_STREAM_REFERENCES).bind(name).bind(name).bind(name).fetch_one(&mut **tx).await? > 0 {
            return Err(unsettled(name));
        }
        let mut deletable = Vec::new();
        for reference in references {
            let uri = Self::query("SELECT storage_uri FROM runtime_external_payloads WHERE id=$1 AND namespace=$2")
                .bind(&reference.reference_id)
                .bind(name)
                .fetch_optional(&mut **tx)
                .await?
                .map(|row| DB::string(&row, "storage_uri"))
                .transpose()?;
            if !Self::namespace_payload_retained(tx, name, &reference, uri.as_deref()).await? {
                deletable.push((reference, uri));
            }
        }
        let (policy, local) = Self::namespace_storage(tx, name).await?;
        let mut staged = Vec::new();
        let mut external_payloads = 0;
        if !deletable.is_empty() {
            let local = match &local {
                Ok(Some(local)) => local,
                Ok(None) => return Err(driver_unavailable(name)),
                Err(_) => return Err(cleanup_failed()),
            };
            let mut targets: Vec<(String, Reference)> = Vec::new();
            for (reference, uri) in deletable {
                // A reference without a registration names its object by the
                // driver's address, as PHP's stored reference names its URI.
                let uri = match uri {
                    Some(uri) => uri,
                    None => local.uri_for(&reference).map_err(|_| cleanup_failed())?,
                };
                if !targets.iter().any(|(known, _)| *known == uri) {
                    targets.push((uri, reference));
                }
            }
            targets.sort_by(|left, right| (object_bucket(&left.0), &left.0).cmp(&(object_bucket(&right.0), &right.0)));
            for (uri, reference) in targets {
                Self::payload_object_lock(tx, &uri).await?;
                if Self::namespace_payload_retained(tx, name, &reference, Some(&uri)).await? {
                    continue;
                }
                let owners = Self::payload_owners(tx, &uri).await?;
                let own = owners.iter().find(|(_, namespace)| namespace == name).map(|(id, _)| id.clone());
                if owners.is_empty() || (own.is_some() && owners.len() == 1) {
                    staged.push(Self::stage_payload_deletion(tx, name, &uri, &policy).await.map_err(|_| cleanup_failed())?);
                }
                if let Some(id) = own {
                    Self::query("DELETE FROM runtime_external_payloads WHERE id=$1").bind(&id).execute(&mut **tx).await?;
                }
                external_payloads += 1;
            }
        }
        let mut left = Vec::new();
        for row in Self::query("SELECT id,storage_uri FROM runtime_external_payloads WHERE namespace=$1 ORDER BY id")
            .bind(name)
            .fetch_all(&mut **tx)
            .await?
        {
            left.push((DB::string(&row, "id")?, DB::string(&row, "storage_uri")?));
        }
        let mut registrations = 0;
        for (id, uri) in left {
            Self::payload_object_lock(tx, &uri).await?;
            let owners = Self::payload_owners(tx, &uri).await?;
            if owners.len() == 1 {
                if !matches!(local, Ok(Some(_))) {
                    return Err(registry_unavailable("External payload storage is unavailable for namespace cleanup."));
                }
                let deletion = Self::stage_payload_deletion(tx, name, &uri, &policy)
                    .await
                    .map_err(|_| registry_unavailable("External payload storage could not complete namespace cleanup."))?;
                staged.push(deletion);
            }
            Self::query("DELETE FROM runtime_external_payloads WHERE id=$1").bind(&id).execute(&mut **tx).await?;
            registrations += 1;
        }
        Ok(([external_payloads, registrations], staged))
    }

    /// The namespace's storage policy, saved with each recorded deletion, and
    /// its enabled local storage as `driverFor` resolves it.
    async fn namespace_storage(tx: &mut Transaction<'_, DB>, name: &str) -> Result<(Value, Result<Option<LocalStorage>>)> {
        let policy = Self::query("SELECT external_payload_storage FROM workflow_namespaces WHERE name=$1")
            .bind(name)
            .fetch_optional(&mut **tx)
            .await?
            .map(|row| DB::optional_document_row(&row, "external_payload_storage"))
            .transpose()?
            .flatten()
            .unwrap_or(Value::Null);
        let local = local_for(&policy, name).await;
        Ok((policy, local))
    }

    /// Every namespace's registration of an object: id and namespace.
    async fn payload_owners(tx: &mut Transaction<'_, DB>, uri: &str) -> Result<Vec<(String, String)>> {
        let mut owners = Vec::new();
        for row in Self::query("SELECT id,namespace FROM runtime_external_payloads WHERE storage_uri_sha256=$1 AND storage_uri=$2")
            .bind(uri_hash(uri))
            .bind(uri)
            .fetch_all(&mut **tx)
            .await?
        {
            owners.push((DB::string(&row, "id")?, DB::string(&row, "namespace")?));
        }
        Ok(owners)
    }

    /// Whether a row outside the namespace holds this reference, or any
    /// reference registered for the same object.
    async fn namespace_payload_retained(tx: &mut Transaction<'_, DB>, name: &str, reference: &Reference, uri: Option<&str>) -> Result<bool> {
        let mut ids = vec![reference.reference_id.clone()];
        if let Some(uri) = uri {
            for (id, _) in Self::payload_owners(tx, uri).await? {
                if !ids.contains(&id) {
                    ids.push(id);
                }
            }
        }
        for id in ids {
            // Ids are unique, so any value naming one holds it.
            let pattern = format!("%{id}%");
            let mut query = Self::scalar(RETAINED_SQL);
            for namespace in RETAINED_BINDS {
                query = if namespace { query.bind(name) } else { query.bind(pattern.as_str()) };
            }
            if query.fetch_one(&mut **tx).await? > 0 {
                return Ok(true);
            }
        }
        Ok(false)
    }
}
