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
fn oversized(envelope: &Value) -> bool {
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
    /// `WorkflowRun::typedSearchAttributes`, sorted by key; PHP renders an
    /// empty set as `[]`.
    async fn typed_search_attributes(&self, run_id: &str) -> Result<Value> {
        // `key` needs dialect quoting, so select every column; the map sorts.
        let rows = Self::query("SELECT *,CASE WHEN value_bool IS NULL THEN NULL WHEN value_bool THEN 1 ELSE 0 END AS value_bool_flag FROM workflow_search_attributes WHERE workflow_run_id=$1")
            .bind(run_id)
            .fetch_all(&self.pool)
            .await?;
        let mut attributes = Map::new();
        for row in rows {
            let value = match DB::string(&row, "type")?.as_str() {
                "string" => json!(DB::optional_string(&row, "value_string")?),
                "keyword" => json!(DB::optional_string(&row, "value_keyword")?),
                "keyword_list" => DB::optional_string(&row, "value_keyword_list")?
                    .and_then(|text| serde_json::from_str::<Value>(&text).ok())
                    .filter(Value::is_array)
                    .unwrap_or_else(|| json!([])),
                "int" => json!(DB::optional_number(&row, "value_int")?),
                "float" => json!(DB::optional_float(&row, "value_float")?),
                "bool" => json!(DB::optional_number(&row, "value_bool_flag")?.map(|flag| flag != 0)),
                "datetime" => json_time(DB::optional_instant(&row, "value_datetime")?),
                _ => Value::Null,
            };
            attributes.insert(DB::string(&row, "key")?, value);
        }
        Ok(if attributes.is_empty() { json!([]) } else { Value::Object(attributes) })
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
        let (supported, fleet_reason) = Self::fleet_supports(
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
        fields.insert("compatibility".into(), json!(compatibility));
        fields.insert("compatibility_status".into(), json!(if supported { "compatible" } else { "no_compatible_worker" }));
        fields.insert("compatibility_supported_in_fleet".into(), json!(supported));
        fields.insert("compatibility_fleet_reason".into(), json!(fleet_reason));
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
