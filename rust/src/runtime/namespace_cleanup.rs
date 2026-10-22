//! `DELETE /api/namespaces/{namespace}`: PHP's NamespaceController::destroy
//! over NamespaceLifecycleCleanup. One transaction deletes every row the
//! namespace owns in PHP's order and reports the non-zero counts under PHP's
//! keys. PHP checks each table's columns first; the statements here use the
//! columns the verified schema has, so a table without a run or instance
//! column is matched by the other, as PHP's column check does.
//!
//! Native does not delete external payload objects yet. A namespace that
//! still references any refuses before changing anything, with PHP's answer
//! for unavailable external payload storage.
use super::retention::reference;
use super::{Result, RuntimeError, backend::Backend, store::Store};
use axum::http::StatusCode;
use serde_json::{Map, Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

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
];

/// Registered external payloads and payload columns holding a reference.
const EXTERNAL_PAYLOADS: &str = concat!(
    "SELECT (SELECT COUNT(*) FROM runtime_external_payloads WHERE namespace=$1)",
    "+(SELECT COUNT(*) FROM workflow_runs WHERE namespace=$2 AND (", reference!("arguments"), " OR ", reference!("output"), "))",
    "+(SELECT COUNT(*) FROM workflow_commands WHERE workflow_run_id IN (", runs!("$3"), ") AND ", reference!("payload"), ")",
    "+(SELECT COUNT(*) FROM workflow_signal_records WHERE workflow_run_id IN (", runs!("$4"), ") AND ", reference!("arguments"), ")",
    "+(SELECT COUNT(*) FROM workflow_updates WHERE workflow_run_id IN (", runs!("$5"), ") AND (", reference!("arguments"), " OR ", reference!("result"), "))",
    "+(SELECT COUNT(*) FROM activity_executions WHERE workflow_run_id IN (", runs!("$6"), ") AND (", reference!("arguments"), " OR ", reference!("result"), "))",
);

pub(super) fn not_found(name: &str) -> RuntimeError {
    RuntimeError::Protocol {
        status: StatusCode::NOT_FOUND,
        response: json!({"message": format!("Namespace [{name}] not found."), "reason": "namespace_not_found", "namespace": name}),
    }
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
        let mut external = Self::scalar(EXTERNAL_PAYLOADS);
        for _ in 0..6 {
            external = external.bind(&name);
        }
        if external.fetch_one(&mut *tx).await? > 0 {
            return Err(RuntimeError::Protocol {
                status: StatusCode::SERVICE_UNAVAILABLE,
                response: json!({
                    "message": "Namespace cleanup requires deleting external payloads, but external payload storage is unavailable.",
                    "reason": "external_payload_storage_driver_unavailable",
                    "namespace": name,
                    "error": "The native server does not delete external payload objects yet.",
                }),
            });
        }
        let mut deleted = Map::new();
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
        for sql in NATIVE_CLEANUP {
            Self::query(sql).bind(&name).execute(&mut *tx).await?;
        }
        Self::query("DELETE FROM workflow_namespaces WHERE name=$1").bind(&name).execute(&mut *tx).await?;
        tx.commit().await?;
        Ok(json!({"name": name, "status": "deleted", "deleted": deleted}))
    }
}
