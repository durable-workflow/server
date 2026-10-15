use super::{Result, RuntimeError, backend::Backend, refuse, store::Store};
use axum::http::StatusCode;
use chrono::SecondsFormat;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::collections::BTreeMap;

const MAX_ROWS: usize = 10_000;

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
    pub(crate) async fn activity_metadata_input(
        &self,
        workflow_id: &str,
        run_id: &str,
    ) -> Result<(Vec<Value>, Vec<Value>)> {
        let missing = || RuntimeError::Protocol {
            status: StatusCode::NOT_FOUND,
            response: json!({"workflow_id":workflow_id,"run_id":run_id,
                "reason":"run_not_found","message":"Workflow run not found."}),
        };
        // A read transaction does not take the runtime's writer-admission lock.
        let mut tx = self.pool.begin().await?;
        let run = Self::query("SELECT id,workflow_instance_id,namespace FROM workflow_runs WHERE id=$1 AND workflow_instance_id=$2 AND namespace=$3")
            .bind(run_id).bind(workflow_id).bind(self.namespace.as_ref())
            .fetch_optional(&mut *tx).await?.ok_or_else(missing)?;
        if DB::string(&run, "id")? != run_id
            || DB::string(&run, "workflow_instance_id")? != workflow_id
            || DB::optional_string(&run, "namespace")?.as_deref() != Some(self.namespace.as_ref())
        {
            return Err(missing());
        }
        let budget = || {
            refuse(
                StatusCode::PAYLOAD_TOO_LARGE,
                "activity_metadata_exceeds_read_budget",
            )
        };
        // Bound stored JSON before driver allocation. Unrelated history and
        // mutable argument/result/exception blobs are not selected.
        let rows = Self::query(DB::ACTIVITY_METADATA_HISTORY)
            .bind(run_id)
            .fetch_all(&mut *tx)
            .await?;
        if rows.len() > MAX_ROWS {
            return Err(budget());
        }
        if let Some(row) = rows.first() {
            if DB::number(row, "projection_count")? != rows.len() as i64 {
                return Err(budget());
            }
        }
        let events = rows.iter().map(|row| {
            let payload = DB::optional_document_row(row,"payload")?.ok_or_else(budget)?;
            Ok(json!({
            "sequence":DB::number(row,"sequence")?,"event_type":DB::string(row,"event_type")?,
            "payload":payload,"workflow_task_id":DB::optional_string(row,"workflow_task_id")?,
            "recorded_at":DB::instant(row,"recorded_at")?.to_rfc3339_opts(SecondsFormat::Micros,true),
        }))}).collect::<Result<Vec<_>>>()?;
        let rows = Self::query("SELECT id,workflow_run_id,sequence,activity_type,activity_class,status,attempt_count,current_attempt_id,retry_policy,activity_options,parallel_group_path,connection,queue,created_at,started_at,last_heartbeat_at,closed_at FROM activity_executions WHERE workflow_run_id=$1 ORDER BY id LIMIT 10001")
            .bind(run_id).fetch_all(&mut *tx).await?;
        if rows.len() > MAX_ROWS {
            return Err(budget());
        }
        let mut executions = Vec::new();
        let mut execution_indices = BTreeMap::new();
        for row in rows {
            if DB::string(&row, "workflow_run_id")? != run_id {
                continue;
            }
            let mut value = json!({"sequence":DB::number(&row,"sequence")?,"attempt_count":DB::number(&row,"attempt_count")?,"attempts":[]});
            for field in [
                "id",
                "activity_type",
                "activity_class",
                "status",
                "current_attempt_id",
                "connection",
                "queue",
            ] {
                value[field] = json!(DB::optional_string(&row, field)?);
            }
            for field in ["retry_policy", "activity_options", "parallel_group_path"] {
                value[field] = json!(DB::optional_document_row(&row, field)?);
            }
            for field in ["created_at", "started_at", "last_heartbeat_at", "closed_at"] {
                value[field] = json!(
                    DB::optional_instant(&row, field)?
                        .map(|v| v.to_rfc3339_opts(SecondsFormat::Micros, true))
                );
            }
            execution_indices.insert(DB::string(&row, "id")?, executions.len());
            executions.push(value);
        }
        let rows = Self::query("SELECT id,activity_execution_id,workflow_run_id,workflow_task_id,worker_attempt_id,attempt_number,status,lease_owner,lease_expires_at,started_at,last_heartbeat_at,closed_at FROM activity_attempts WHERE workflow_run_id=$1 ORDER BY id LIMIT 10001")
            .bind(run_id).fetch_all(&mut *tx).await?;
        if rows.len() > MAX_ROWS {
            return Err(budget());
        }
        for row in rows {
            if DB::string(&row, "workflow_run_id")? != run_id {
                continue;
            }
            let activity_id = DB::string(&row, "activity_execution_id")?;
            let Some(index) = execution_indices.get(&activity_id) else {
                continue;
            };
            let execution = &mut executions[*index];
            let mut attempt = json!({"attempt_number":DB::number(&row,"attempt_number")?});
            for field in [
                "id",
                "workflow_task_id",
                "worker_attempt_id",
                "status",
                "lease_owner",
            ] {
                attempt[field] = json!(DB::optional_string(&row, field)?);
            }
            for field in [
                "lease_expires_at",
                "started_at",
                "last_heartbeat_at",
                "closed_at",
            ] {
                attempt[field] = json!(
                    DB::optional_instant(&row, field)?
                        .map(|v| v.to_rfc3339_opts(SecondsFormat::Micros, true))
                );
            }
            execution["attempts"].as_array_mut().unwrap().push(attempt);
        }
        tx.commit().await?;
        Ok((events, executions))
    }
}
