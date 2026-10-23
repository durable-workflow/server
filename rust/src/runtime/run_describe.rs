//! The describe fields published PHP's `WorkflowController::formatRun` adds
//! to a run: the operator actions Waterline enables, compatibility against
//! the live fleet, payload preview limits, the current wait, search
//! attributes and update diagnostics.
//!
//! Native records no command context, so describe's commands still lack
//! PHP's caller, principal and request fields, and updates carry no
//! principal or request id.
use super::{
    Result,
    backend::Backend,
    store::{Store, now},
    task_queues::json_time,
};
use chrono::{DateTime, Utc};
use serde_json::{Map, Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::sync::OnceLock;

/// `server.limits.max_payload_bytes`, which describe reports and uses to
/// omit oversized previews.
fn max_payload_bytes() -> i64 {
    static LIMIT: OnceLock<i64> = OnceLock::new();
    *LIMIT.get_or_init(|| {
        ["DW_MAX_PAYLOAD_BYTES", "WORKFLOW_MAX_PAYLOAD_BYTES"]
            .iter()
            .find_map(|name| std::env::var(name).ok())
            .and_then(|value| value.trim().parse().ok())
            .unwrap_or(2 * 1024 * 1024)
    })
}

/// `oversizedPayloadPreview`: an external payload larger than the limit.
pub(super) fn oversized(envelope: &Value) -> bool {
    envelope["external_storage"]["size_bytes"].as_i64().is_some_and(|size| size > max_payload_bytes())
}

/// Carbon's `toIso8601String()`, which PHP's run description uses.
pub(super) fn iso8601(time: Option<DateTime<Utc>>) -> Value {
    json!(time.map(|time| time.format("%Y-%m-%dT%H:%M:%S+00:00").to_string()))
}

/// `withoutNullOrEmptyArrays`.
fn without_empty(mut value: Value) -> Value {
    if let Some(entries) = value.as_object_mut() {
        entries.retain(|_, item| !item.is_null() && item.as_array().is_none_or(|items| !items.is_empty()));
    }
    value
}

/// PHP's describe actions for a run: signal, cancel, terminate and repair
/// need the current open run; queries need a route; native never runs
/// in-process workflow classes, so updates are never offered.
pub(super) fn actions(current: bool, terminal: bool, archived: bool, query_servable: bool) -> Value {
    let open = current && !terminal;
    json!({"can_signal":open,"can_query":query_servable,"can_update":false,"can_cancel":open,
        "can_terminate":open,"can_repair":open,"can_archive":terminal && !archived})
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
    /// The registered query route for a run row, with workers whose
    /// heartbeat is newer than `stale_after` seconds.
    pub(super) async fn query_route(&self, run: &DB::Row, stale_after: i64) -> Result<super::query_route::QueryRoute> {
        let task_queue = super::query_route::trimmed(DB::optional_string(run, "queue")?.as_deref()).unwrap_or("default").to_owned();
        let workflow_type = DB::optional_string(run, "workflow_type")?;
        let compatibility = DB::optional_string(run, "compatibility")?;
        let started = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='WorkflowStarted' ORDER BY sequence LIMIT 1")
            .bind(DB::string(run, "id")?)
            .fetch_optional(&self.pool)
            .await?
            .map(|row| DB::document_row(&row, "payload"))
            .transpose()?
            .unwrap_or(Value::Null);
        let fresh_after = now() - chrono::Duration::seconds(stale_after.max(1));
        let rows = Self::query("SELECT capabilities,supported_workflow_types,build_id,workflow_definition_fingerprints,last_heartbeat_at FROM workflow_worker_registrations WHERE namespace=$1 AND task_queue=$2 AND status='active' ORDER BY worker_id")
            .bind(self.namespace.as_ref())
            .bind(task_queue.as_str())
            .fetch_all(&self.pool)
            .await?;
        let mut workers = Vec::with_capacity(rows.len());
        for row in rows {
            // `WorkerPollFence::isFresh`: a heartbeat within the stale window.
            if !DB::optional_instant(&row, "last_heartbeat_at")?.is_some_and(|heartbeat| heartbeat >= fresh_after) {
                continue;
            }
            workers.push(super::query_route::Worker {
                capabilities: super::query_route::strings(&DB::optional_document_row(&row, "capabilities")?.unwrap_or(Value::Null)),
                workflow_types: super::query_route::strings(&DB::optional_document_row(&row, "supported_workflow_types")?.unwrap_or(Value::Null)),
                build_id: super::query_route::trimmed(DB::optional_string(&row, "build_id")?.as_deref()).map(str::to_owned),
                fingerprints: DB::optional_document_row(&row, "workflow_definition_fingerprints")?.unwrap_or_else(|| json!({})),
            });
        }
        Ok(super::query_route::route(
            &task_queue,
            super::query_route::trimmed(workflow_type.as_deref()),
            super::query_route::trimmed(compatibility.as_deref()),
            &started,
            &workers,
        ))
    }

    /// `WorkflowRun::typedSearchAttributes`, sorted by key; PHP renders an
    /// empty set as `[]`.
    async fn typed_search_attributes(&self, run_id: &str) -> Result<Value> {
        let mut connection = self.pool.acquire().await?;
        let rows = Self::typed_rows(&mut connection, run_id).await?;
        Ok(if rows.is_empty() {
            json!([])
        } else {
            Value::Object(rows.into_iter().map(|(key, value)| (key, value.json())).collect())
        })
    }

    /// The rows `HistoryExport::forRun` reads for one run, as JSON.
    pub(super) async fn history_export_rows(
        &self,
        workflow_id: &str,
        run_id: &str,
    ) -> Result<(super::history_export::Rows, Vec<(String, Value, Option<Value>)>)> {
        use super::history_export as export;
        let mut tx = self.begin_read().await?;
        let run = Self::query(export::RUN_SQL)
            .bind(workflow_id)
            .bind(run_id)
            .bind(self.namespace.as_ref())
            .fetch_optional(&mut *tx)
            .await?
            .ok_or_else(|| super::refuse(axum::http::StatusCode::NOT_FOUND, "run_not_found"))?;
        let mut sections = Vec::with_capacity(11);
        for (sql, columns) in [
            (export::EVENTS_SQL, export::EVENTS),
            (export::COMMANDS_SQL, export::COMMANDS),
            (export::SIGNALS_SQL, export::SIGNALS),
            (export::UPDATES_SQL, export::UPDATES),
            (export::TASKS_SQL, export::TASKS),
            (export::EXECUTIONS_SQL, export::EXECUTIONS),
            (export::ATTEMPTS_SQL, export::ATTEMPTS),
            (export::FAILURES_SQL, export::FAILURES),
            (export::PARENTS_SQL, export::LINKS),
            (export::CHILDREN_SQL, export::LINKS),
            (export::SUMMARY_SQL, export::SUMMARY),
        ] {
            let rows = Self::query(sql).bind(run_id).fetch_all(&mut *tx).await?;
            sections.push(rows.iter().map(|row| export::row_json::<DB>(row, columns)).collect::<Result<Vec<_>>>()?);
        }
        let mut memo = Vec::new();
        for row in Self::query(DB::MEMO_READ).bind(run_id).fetch_all(&mut *tx).await? {
            let portable = (DB::optional_number(&row, "portable_value_sequence")? == Some(DB::number(&row, "upserted_at_sequence")?))
                .then(|| DB::optional_document_row(&row, "portable_value"))
                .transpose()?
                .flatten();
            memo.push((DB::string(&row, "key")?, DB::document_row(&row, "value")?, portable));
        }
        tx.commit().await?;
        let mut sections = sections.into_iter();
        let mut next = || sections.next().unwrap_or_default();
        let mut rows = export::Rows {
            run: export::row_json::<DB>(&run, export::RUN)?,
            events: next(),
            commands: next(),
            signals: next(),
            updates: next(),
            tasks: next(),
            executions: next(),
            attempts: next(),
            failures: next(),
            parents: next(),
            children: next(),
            ..Default::default()
        };
        rows.summary = next().into_iter().next();
        rows.search_attributes = self.typed_search_attributes(run_id).await?;
        if let Some(summary) = &rows.summary {
            rows.task_problem_badge = super::run_diagnostics::task_problem_badge(
                summary["task_problem"].as_bool().unwrap_or(false),
                summary["liveness_state"].as_str(),
                summary["wait_kind"].as_str(),
            );
        }
        Ok((rows, memo))
    }

    /// `runUpdateDiagnostics`: the run's updates in command order.
    async fn update_diagnostics(&self, run_id: &str) -> Result<Vec<Value>> {
        let rows = Self::query("SELECT id,workflow_command_id,workflow_instance_id,workflow_run_id,target_scope,requested_workflow_run_id,resolved_workflow_run_id,update_name,status,outcome,command_sequence,workflow_sequence,rejection_reason,validation_errors,failure_id,failure_message,accepted_at,applied_at,rejected_at,closed_at FROM workflow_updates WHERE workflow_run_id=$1 ORDER BY command_sequence,created_at,id")
            .bind(run_id)
            .fetch_all(&self.pool)
            .await?;
        let mut updates = Vec::with_capacity(rows.len());
        for row in rows {
            let text = |field: &str| DB::optional_string(&row, field);
            updates.push(without_empty(json!({"id":text("id")?,"command_id":text("workflow_command_id")?,
                "workflow_id":text("workflow_instance_id")?,"run_id":text("workflow_run_id")?,
                "target_scope":text("target_scope")?,"requested_run_id":text("requested_workflow_run_id")?,
                "resolved_run_id":text("resolved_workflow_run_id")?,"update_name":text("update_name")?,
                "status":text("status")?,"outcome":text("outcome")?,
                "command_sequence":DB::optional_number(&row, "command_sequence")?,
                "workflow_sequence":DB::optional_number(&row, "workflow_sequence")?,
                "rejection_reason":text("rejection_reason")?,
                "validation_errors":DB::optional_document_row(&row, "validation_errors")?,
                "failure_id":text("failure_id")?,"failure_message":text("failure_message")?,
                "accepted_at":json_time(DB::optional_instant(&row, "accepted_at")?),
                "applied_at":json_time(DB::optional_instant(&row, "applied_at")?),
                "rejected_at":json_time(DB::optional_instant(&row, "rejected_at")?),
                "closed_at":json_time(DB::optional_instant(&row, "closed_at")?)})));
        }
        Ok(updates)
    }

    /// The fields `formatRun` adds for a described run row.
    pub(super) async fn describe_fields(
        &self,
        run: &DB::Row,
        current: bool,
        terminal: bool,
        input: &Value,
        output: &Value,
        stale_after: i64,
    ) -> Result<Map<String, Value>> {
        let run_id = DB::string(run, "id")?;
        let status = DB::string(run, "status")?;
        let started = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='WorkflowStarted' ORDER BY sequence LIMIT 1")
            .bind(run_id.as_str())
            .fetch_optional(&self.pool)
            .await?
            .map(|row| DB::document_row(&row, "payload"))
            .transpose()?
            .unwrap_or(Value::Null);
        let summary = Self::query("SELECT wait_kind,wait_reason FROM workflow_run_summaries WHERE id=$1")
            .bind(run_id.as_str())
            .fetch_optional(&self.pool)
            .await?;
        let (wait_kind, wait_reason) = match summary {
            Some(row) => (DB::optional_string(&row, "wait_kind")?, DB::optional_string(&row, "wait_reason")?),
            None => (None, None),
        };
        let compatibility = DB::optional_string(run, "compatibility")?;
        let queue = DB::optional_string(run, "queue")?;
        let snapshots = self.fleet_snapshots(now()).await?;
        let (supported, fleet_reason) = super::run_diagnostics::fleet_supports(
            &snapshots,
            compatibility.as_deref().map(str::trim).filter(|value| !value.is_empty()),
            queue.as_deref(),
        );
        // `canServeQuery`: failed, cancelled and terminated runs refuse
        // queries; others need a registered route.
        let query_servable = current
            && (!terminal || status == "completed")
            && self.query_route(run, stale_after).await?.servable;
        let archived = DB::optional_instant(run, "archived_at")?.is_some();
        let (input_omitted, output_omitted) = (oversized(input), oversized(output));
        let mut fields = Map::new();
        fields.insert("business_key".into(), json!(DB::optional_string(run, "business_key")?));
        fields.insert("resume_step_sequence".into(), json!(started["resume_step_sequence"].as_i64()));
        fields.insert("recovery_kind".into(), json!(started["recovery_kind"].as_str()));
        // A redriven run continues its failed source, as its start records.
        if started["recovery_kind"] == "redrive" && started["continued_from_run_id"].is_string() {
            fields.insert("continued_from_run_id".into(), started["continued_from_run_id"].clone());
        }
        fields.insert("compatibility".into(), json!(compatibility));
        fields.insert("compatibility_status".into(), json!(if supported { "compatible" } else { "no_compatible_worker" }));
        fields.insert("compatibility_supported_in_fleet".into(), json!(supported));
        fields.insert("compatibility_fleet_reason".into(), json!(fleet_reason));
        // `ServerWorkflowControlPlane::withDefinitionRouting`.
        let mut tx = self.begin_read().await?;
        let routing = self.definition_inspect(&mut tx, run, None).await?;
        tx.commit().await?;
        fields.insert("definition_routing".into(), routing);
        fields.insert("payload_previews".into(), json!({"input_omitted":input_omitted,"output_omitted":output_omitted,
            "max_encoded_bytes":max_payload_bytes()}));
        fields.insert("last_progress_at".into(), iso8601(DB::optional_instant(run, "last_progress_at")?));
        fields.insert("wait_kind".into(), json!(wait_kind));
        fields.insert("wait_reason".into(), json!(wait_reason));
        fields.insert("search_attributes".into(), self.typed_search_attributes(&run_id).await?);
        fields.insert("actions".into(), actions(current, terminal, archived, query_servable));
        fields.insert("updates_scope".into(), json!("selected_run"));
        fields.insert("updates".into(), json!(self.update_diagnostics(&run_id).await?));
        Ok(fields)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn actions_follow_php_describe() {
        assert_eq!(
            actions(true, false, false, true),
            json!({"can_signal":true,"can_query":true,"can_update":false,"can_cancel":true,
                "can_terminate":true,"can_repair":true,"can_archive":false})
        );
        // A historical run offers nothing but archive once closed.
        assert_eq!(
            actions(false, true, false, false),
            json!({"can_signal":false,"can_query":false,"can_update":false,"can_cancel":false,
                "can_terminate":false,"can_repair":false,"can_archive":true})
        );
        assert_eq!(actions(true, true, true, false)["can_archive"], false);
    }

    #[test]
    fn previews_and_filters_follow_php() {
        assert!(oversized(&json!({"external_storage":{"size_bytes":2 * 1024 * 1024 + 1}})));
        assert!(!oversized(&json!({"external_storage":{"size_bytes":2 * 1024 * 1024}})));
        assert!(!oversized(&json!({"codec":"avro","blob":"AA=="})));
        assert_eq!(without_empty(json!({"a":null,"b":[],"c":{},"d":0})), json!({"c":{},"d":0}));
        let time = DateTime::parse_from_rfc3339("2026-10-21T07:38:51.123Z").unwrap().with_timezone(&Utc);
        assert_eq!(iso8601(Some(time)), json!("2026-10-21T07:38:51+00:00"));
    }
}
