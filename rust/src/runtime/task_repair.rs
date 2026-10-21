//! Explicit recovery of a worker-reported version-range blockage.
use super::{
    Result, RuntimeError,
    backend::Backend,
    refuse,
    store::{Store, id, now},
    wire,
};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::sync::Arc;
use tokio::sync::Semaphore;

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
    pub(crate) async fn repair_range_blocked(
        &self,
        workflow_id: &str,
        requested_run_id: Option<&str>,
        body: Value,
        context: Value,
        codec: Arc<Semaphore>,
    ) -> Result<Value> {
        if !body.is_object()
            || body.get("request_id").is_some_and(|value| {
                !value.is_null() && value.as_str().is_none_or(|id| id.chars().count() > 255)
            })
        {
            return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_request"));
        }
        if self.namespace.as_ref() != "default" {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "development_workflow_task_repair_namespace_unqualified",
            ));
        }
        let mut tx = self.begin().await?;
        let instance = Self::query("SELECT * FROM workflow_instances WHERE id=$1 AND namespace=$2")
            .bind(workflow_id)
            .bind(self.namespace.as_ref())
            .fetch_optional(&mut *tx)
            .await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "instance_not_found"))?;
        let run_id = DB::string(&instance, "current_run_id")?;
        if requested_run_id.is_some_and(|selected| selected != run_id) {
            return Err(RuntimeError::Protocol {
                status: StatusCode::CONFLICT,
                response: json!({
                    "workflow_id":workflow_id,"run_id":requested_run_id,"target_scope":"run",
                    "reason":"historical_run_command_rejected",
                    "message":"Commands cannot target historical runs. Use the instance-level endpoint to command the current run, or omit the run ID."
                }),
            });
        }
        let run = Self::active_run(&mut tx, &run_id).await?;
        let task = Self::query(DB::RANGE_REPAIR_TASK_SQL)
            .bind(&run_id)
            .bind(self.namespace.as_ref())
            .fetch_optional(&mut *tx)
            .await?
            .ok_or_else(|| {
                refuse(
                    StatusCode::UNPROCESSABLE_ENTITY,
                    "development_workflow_task_repair_unqualified",
                )
            })?;
        let signals = Self::scalar("SELECT COUNT(*) FROM workflow_signal_records WHERE workflow_run_id=$1 AND status='received'")
            .bind(&run_id).fetch_one(&mut *tx).await?;
        let updates = Self::scalar(
            "SELECT COUNT(*) FROM workflow_updates WHERE workflow_run_id=$1 AND status='accepted'",
        )
        .bind(&run_id)
        .fetch_one(&mut *tx)
        .await?;
        if signals != 0 || updates != 0 || Self::cancellation_request(&run)?.is_some() {
            // Message/cancellation repair needs its own shared contract.
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "development_workflow_task_repair_active_work_unqualified",
            ));
        }
        let wait_kind = Self::range_repair_wait(&mut tx, &run_id).await?;
        let task_id = DB::string(&task, "id")?;
        let repair_count = DB::number(&task, "repair_count")?
            .checked_add(1)
            .filter(|count| *count <= DB::REPAIR_COUNTER_MAX)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "repair_count_exhausted"))?;
        let sequence = DB::number(&run, "last_command_sequence")?
            .checked_add(1)
            .filter(|count| *count <= DB::REPAIR_COUNTER_MAX)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "command_sequence_exhausted"))?;
        let command_payload = super::worker_incarnations::repair_payload(
            codec,
            task_id.clone(),
            "workflow_replay_blocked",
            wait_kind,
        )
        .await?;
        let mut task_payload = DB::document_row(&task, "payload")?;
        task_payload
            .as_object_mut()
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "invalid_workflow_task_payload"))?
            .retain(|key, _| !key.starts_with("replay_blocked"));
        let timestamp = now();
        let command_id = id();
        Self::query("UPDATE workflow_tasks SET status='ready',payload=$1,leased_at=NULL,lease_owner=NULL,lease_expires_at=NULL,repair_count=$2,repair_available_at=NULL,last_error=NULL,updated_at=$3 WHERE id=$4")
            .bind(DB::document(&task_payload)).bind(repair_count).bind(DB::bind_time(timestamp)).bind(&task_id).execute(&mut *tx).await?;
        // PHP guards a selected run then records an instance-targeted repair.
        // Preserve that command identity rather than treating the URL as scope.
        Self::query("INSERT INTO workflow_commands(id,workflow_instance_id,workflow_run_id,resolved_workflow_run_id,command_type,source,target_scope,status,outcome,workflow_type,payload_codec,payload,command_sequence,accepted_at,applied_at,context) VALUES ($1,$2,$3,$4,'repair','control_plane','instance','accepted','repair_dispatched',$5,'avro',$6,$7,$8,$9,$10)")
            .bind(&command_id).bind(workflow_id).bind(&run_id).bind(&run_id).bind(DB::string(&run, "workflow_type")?)
            .bind(&command_payload).bind(sequence).bind(DB::bind_time(timestamp)).bind(DB::bind_time(timestamp))
            .bind(DB::document(&context)).execute(&mut *tx).await?;
        Self::query(
            "UPDATE workflow_runs SET last_command_sequence=$1,last_progress_at=$2 WHERE id=$3",
        )
        .bind(sequence)
        .bind(DB::bind_time(timestamp))
        .bind(&run_id)
        .execute(&mut *tx)
        .await?;
        let repaired = Self::query("SELECT * FROM workflow_tasks WHERE id=$1")
            .bind(&task_id)
            .fetch_one(&mut *tx)
            .await?;
        Self::append(&mut tx, &run_id, "RepairRequested", json!({
            "workflow_command_id":command_id,"workflow_instance_id":workflow_id,"workflow_run_id":run_id,
            "command_type":"repair","outcome":"repair_dispatched","liveness_state":"workflow_replay_blocked",
            "wait_kind":wait_kind,"task_id":task_id,"task_type":"workflow","task":Self::task_snapshot(&repaired)?,
            "command":{"id":command_id,"sequence":sequence,"type":"repair","target_scope":"instance",
                "resolved_run_id":run_id,"status":"accepted","outcome":"repair_dispatched",
                "source":"control_plane","payload_codec":"avro","payload":wire(&command_payload),
                "context":{"principal":context["principal"]},
                "caller_label":context["caller"]["label"],"auth_status":context["auth"]["status"],
                "auth_method":context["auth"]["method"],"principal_type":context["principal"]["type"],
                "principal_id":context["principal"]["id"],"principal_label":context["principal"]["label"],
                "request_method":context["request"]["method"],"request_path":context["request"]["path"],
                "request_fingerprint":context["request"]["fingerprint"],"accepted_at":timestamp,"applied_at":timestamp}
        }), Some(&task_id), Some(&command_id)).await?;
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(
            json!({"workflow_id":workflow_id,"run_id":run_id,"workflow_type":DB::string(&run, "workflow_type")?,
            "namespace":self.namespace.as_ref(),"command_id":command_id,"command_status":"accepted",
            "command_source":"control_plane","outcome":"repair_dispatched","requested_run_id":null,
            "resolved_run_id":run_id,"target_scope":"instance","reason":null}),
        )
    }
}
