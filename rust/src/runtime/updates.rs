//! Durable updates admitted at a quiescent wait, with original request receipts.
//! Concurrent queued control routing and validator/failure tasks are not qualified.
use super::{
    Result,
    backend::Backend,
    envelope, refuse,
    store::{PreparedControlArguments, Store, id, now, reject_fields, validate_arguments},
    text, wire,
};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Transaction, Type};
use std::time::Duration;

const MAX_UPDATES_PER_RUN: i64 = 64;
const UPDATE_ROW: &str = "SELECT u.*,c.workflow_type,c.message_sequence,COALESCE(u.workflow_sequence,0) AS callback_sequence FROM workflow_updates u JOIN workflow_commands c ON c.id=u.workflow_command_id WHERE u.id=$1";

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
    pub(crate) async fn update_workflow(
        &self,
        workflow_id: &str,
        selected_run: Option<&str>,
        name: &str,
        body: Value,
        prepared: PreparedControlArguments,
    ) -> Result<(StatusCode, Value)> {
        reject_fields(
            &body,
            &["input", "request_id", "wait_for", "wait_timeout_seconds"],
        )?;
        if name.trim().is_empty() || name.len() > 255 {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "invalid_update_name",
            ));
        }
        let request_id = body
            .get("request_id")
            .filter(|value| !value.is_null())
            .map(|_| text(&body, "request_id"))
            .transpose()?;
        if request_id.is_some_and(|request| request.len() > 255) {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "invalid_request_id",
            ));
        }
        let wait_for = body
            .get("wait_for")
            .map_or(Ok("completed"), |_| text(&body, "wait_for"))?;
        if !matches!(wait_for, "accepted" | "completed") {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "invalid_update_wait_policy",
            ));
        }
        let timeout = body
            .get("wait_timeout_seconds")
            .filter(|value| !value.is_null())
            .map(|value| {
                value
                    .as_i64()
                    .filter(|seconds| (0..=30).contains(seconds))
                    .ok_or_else(|| {
                        refuse(
                            StatusCode::UNPROCESSABLE_ENTITY,
                            "unsupported_update_wait_timeout",
                        )
                    })
            })
            .transpose()?;
        let mut tx = self.begin().await?;
        let instance = Self::query("SELECT current_run_id,last_message_sequence FROM workflow_instances WHERE id=$1 AND namespace='default'")
            .bind(workflow_id).fetch_optional(&mut *tx).await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "instance_not_found"))?;
        let run_id = DB::string(&instance, "current_run_id")?;
        if selected_run.is_some_and(|selected| selected != run_id) {
            return Err(refuse(
                StatusCode::CONFLICT,
                "historical_run_command_rejected",
            ));
        }
        let existing = if let Some(request_id) = request_id {
            Self::query("SELECT u.id,u.update_name,u.workflow_run_id FROM workflow_updates u JOIN workflow_commands c ON c.id=u.workflow_command_id WHERE c.workflow_instance_id=$1 AND c.command_type='update' AND c.request_id=$2")
                .bind(workflow_id).bind(request_id).fetch_optional(&mut *tx).await?
        } else {
            None
        };
        let update_id = if let Some(existing) = existing {
            // Look up the original receipt before validating changed arguments
            // or admitting another mutation. No result or event is rewritten.
            if DB::string(&existing, "update_name")? != name
                || DB::string(&existing, "workflow_run_id")? != run_id
            {
                return Err(refuse(
                    StatusCode::CONFLICT,
                    "unsupported_update_request_retargeting",
                ));
            }
            DB::string(&existing, "id")?
        } else {
            let run = Self::active_run(&mut tx, &run_id).await?;
            let started = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='WorkflowStarted' ORDER BY sequence LIMIT 1")
                .bind(&run_id).fetch_one(&mut *tx).await?;
            let contract = DB::document_row(&started, "payload")?;
            if !contract["declared_updates"]
                .as_array()
                .is_some_and(|names| names.iter().any(|value| value == name))
            {
                return Err(refuse(StatusCode::NOT_FOUND, "unknown_update"));
            }
            if contract["declared_update_validators"]
                .as_array()
                .is_some_and(|names| names.iter().any(|value| value == name))
            {
                return Err(refuse(
                    StatusCode::UNPROCESSABLE_ENTITY,
                    "update_validation_not_implemented",
                ));
            }
            validate_arguments(
                &contract,
                "declared_update_contracts",
                name,
                &prepared.values,
                "unsupported_update_contract",
                "invalid_update_arguments",
            )?;
            let open_tasks = Self::scalar("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status IN ('ready','leased')")
                .bind(&run_id).fetch_one(&mut *tx).await?;
            let pending_signals = Self::scalar("SELECT COUNT(*) FROM workflow_signal_records WHERE workflow_run_id=$1 AND status='received'")
                .bind(&run_id).fetch_one(&mut *tx).await?;
            let pending_updates = Self::scalar("SELECT COUNT(*) FROM workflow_updates WHERE workflow_run_id=$1 AND status='accepted'")
                .bind(&run_id).fetch_one(&mut *tx).await?;
            if open_tasks != 0
                || pending_signals != 0
                || pending_updates != 0
                || Self::open_wait(&mut tx, &run_id).await?.is_none()
            {
                return Err(refuse(StatusCode::CONFLICT, "update_snapshot_busy"));
            }
            let count =
                Self::scalar("SELECT COUNT(*) FROM workflow_updates WHERE workflow_run_id=$1")
                    .bind(&run_id)
                    .fetch_one(&mut *tx)
                    .await?;
            if count >= MAX_UPDATES_PER_RUN {
                return Err(refuse(
                    StatusCode::SERVICE_UNAVAILABLE,
                    "update_inventory_exceeds_budget",
                ));
            }
            let command_id = id();
            let update_id = id();
            let command_sequence = DB::number(&run, "last_command_sequence")?
                .checked_add(1)
                .ok_or_else(|| refuse(StatusCode::CONFLICT, "command_sequence_exhausted"))?;
            let message_sequence = DB::number(&instance, "last_message_sequence")?
                .checked_add(1)
                .ok_or_else(|| refuse(StatusCode::CONFLICT, "message_sequence_exhausted"))?;
            let accepted_at = now();
            Self::query("INSERT INTO workflow_commands(id,workflow_instance_id,workflow_run_id,resolved_workflow_run_id,command_type,source,status,workflow_type,payload_codec,payload,command_sequence,message_sequence,accepted_at,context,request_id) VALUES ($1,$2,$3,$4,'update','control_plane','accepted',$5,'avro',$6,$7,$8,$9,$10,$11)")
                .bind(&command_id).bind(workflow_id).bind(&run_id).bind(&run_id).bind(DB::string(&run, "workflow_type")?)
                .bind(&prepared.command).bind(command_sequence).bind(message_sequence).bind(DB::bind_time(accepted_at))
                .bind(DB::document(&json!({"request":{"request_id":request_id},"update_name":name}))).bind(request_id).execute(&mut *tx).await?;
            Self::query("INSERT INTO workflow_updates(id,workflow_command_id,workflow_instance_id,workflow_run_id,resolved_workflow_run_id,update_name,status,command_sequence,payload_codec,arguments,accepted_at) VALUES ($1,$2,$3,$4,$5,$6,'accepted',$7,'avro',$8,$9)")
                .bind(&update_id).bind(&command_id).bind(workflow_id).bind(&run_id).bind(&run_id).bind(name).bind(command_sequence)
                .bind(&prepared.arguments).bind(DB::bind_time(accepted_at)).execute(&mut *tx).await?;
            Self::query("UPDATE workflow_runs SET last_command_sequence=$1 WHERE id=$2")
                .bind(command_sequence)
                .bind(&run_id)
                .execute(&mut *tx)
                .await?;
            Self::query("UPDATE workflow_instances SET last_message_sequence=$1 WHERE id=$2")
                .bind(message_sequence)
                .bind(workflow_id)
                .execute(&mut *tx)
                .await?;
            Self::append(&mut tx, &run_id, "UpdateAccepted", json!({
                "workflow_command_id":command_id,"update_id":update_id,"workflow_instance_id":workflow_id,
                "workflow_run_id":run_id,"update_name":name,"arguments":wire(&prepared.arguments),"ordering_state":"ready",
                "request_id":request_id,"command":{"id":command_id,"sequence":command_sequence,"message_sequence":message_sequence,
                    "type":"update","target_scope":"instance","resolved_run_id":run_id,"status":"accepted","outcome":null}
            }), None, Some(&command_id)).await?;
            Self::create_task(&mut tx, &run_id, &DB::string(&run, "queue")?, "workflow",
                json!({"resume_source_kind":"workflow_update","workflow_update_id":update_id,"workflow_command_id":command_id})).await?;
            update_id
        };
        // A completion wait owns neither the transaction nor a pool connection.
        tx.commit().await?;
        self.wake.notify_waiters();
        let deadline =
            tokio::time::Instant::now() + Duration::from_secs(timeout.unwrap_or(30) as u64);
        loop {
            let wake = self.wake.notified();
            let update = Self::query(UPDATE_ROW)
                .bind(&update_id)
                .fetch_one(&self.pool)
                .await?;
            let completed = DB::string(&update, "status")? == "completed";
            let timed_out =
                wait_for == "completed" && !completed && tokio::time::Instant::now() >= deadline;
            if completed || wait_for == "accepted" || timed_out {
                return Ok((
                    if completed {
                        StatusCode::OK
                    } else {
                        StatusCode::ACCEPTED
                    },
                    Self::update_result(&update, wait_for, timed_out, timeout)?,
                ));
            }
            tokio::select! { _ = wake => {}, _ = tokio::time::sleep(Duration::from_millis(50)) => {} }
        }
    }

    fn update_result(
        update: &DB::Row,
        wait_for: &str,
        timed_out: bool,
        timeout: Option<i64>,
    ) -> Result<Value> {
        let result = DB::optional_string(update, "result")?;
        let status = DB::string(update, "status")?;
        if status == "completed" && result.is_none() {
            return Err(refuse(StatusCode::CONFLICT, "invalid_stored_update_result"));
        }
        let sequence = DB::number(update, "callback_sequence")?;
        Ok(json!({
            "accepted":true,"workflow_id":DB::string(update,"workflow_instance_id")?,"run_id":DB::string(update,"workflow_run_id")?,
            "resolved_run_id":DB::string(update,"resolved_workflow_run_id")?,"requested_run_id":null,"target_scope":"instance",
            "workflow_type":DB::string(update,"workflow_type")?,"command_id":DB::string(update,"workflow_command_id")?,
            "command_sequence":DB::number(update,"command_sequence")?,"command_status":"accepted","command_source":"control_plane",
            "update_id":DB::string(update,"id")?,"update_name":DB::string(update,"update_name")?,"update_status":status,
            "workflow_sequence":if sequence == 0 { None } else { Some(sequence) },
            "outcome":DB::optional_string(update,"outcome")?,"result":null,"result_envelope":result.as_deref().map(wire),
            "accepted_at":DB::instant(update,"accepted_at")?,"applied_at":DB::optional_instant(update,"applied_at")?,
            "closed_at":DB::optional_instant(update,"closed_at")?,"reason":null,"rejection_reason":null,"validation_errors":[],
            "wait_for":wait_for,"wait_timed_out":timed_out,"wait_timeout_seconds":timeout
        }))
    }

    pub(super) fn update_worker_supported(
        worker: &DB::Row,
        workflow_type: &str,
        name: &str,
    ) -> Result<bool> {
        Ok(DB::string(worker, "status")? == "active"
            && DB::instant(worker, "last_heartbeat_at")? > now() - chrono::Duration::seconds(60)
            && DB::document_row(worker, "supported_workflow_types")?
                .as_array()
                .is_some_and(|types| types.iter().any(|value| value == workflow_type))
            && DB::document_row(worker, "capabilities")?
                .as_array()
                .is_some_and(|names| names.iter().any(|value| value == "workflow_updates"))
            && DB::document_row(worker, "workflow_command_contracts")?[workflow_type]["updates"]
                .as_array()
                .is_some_and(|names| names.iter().any(|value| value == name)))
    }

    pub(super) async fn apply_update(
        tx: &mut Transaction<'_, DB>,
        run: &DB::Row,
        task: &DB::Row,
        command: &Value,
        sequence: i64,
    ) -> Result<()> {
        let update_id = text(command, "update_id")?;
        let run_id = DB::string(run, "id")?;
        let update = Self::query(UPDATE_ROW)
            .bind(update_id)
            .fetch_one(&mut **tx)
            .await?;
        if DB::string(&update, "workflow_run_id")? != run_id
            || DB::string(&update, "status")? != "accepted"
        {
            return Err(refuse(StatusCode::CONFLICT, "update_not_pending"));
        }
        let name = DB::string(&update, "update_name")?;
        let worker = Self::query("SELECT status,last_heartbeat_at,supported_workflow_types,capabilities,workflow_command_contracts FROM workflow_worker_registrations WHERE namespace='default' AND worker_id=$1")
            .bind(DB::string(task,"lease_owner")?).fetch_optional(&mut **tx).await?
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "update_worker_unavailable"))?;
        if !Self::update_worker_supported(&worker, &DB::string(run, "workflow_type")?, &name)? {
            return Err(refuse(StatusCode::CONFLICT, "update_worker_unavailable"));
        }
        let workflow_id = DB::string(run, "workflow_instance_id")?;
        let command_id = DB::string(&update, "workflow_command_id")?;
        let task_id = DB::string(task, "id")?;
        let message_sequence = DB::number(&update, "message_sequence")?;
        let previous = DB::number(run, "message_cursor_position")?;
        if previous.checked_add(1) != Some(message_sequence) {
            return Err(refuse(
                StatusCode::CONFLICT,
                "update_message_order_mismatch",
            ));
        }
        let result = envelope(command, "result")?;
        let payload = json!({
            "workflow_command_id":command_id,"update_id":update_id,"workflow_instance_id":workflow_id,
            "workflow_run_id":run_id,"update_name":name,"sequence":sequence,
            "command":{"id":command_id,"sequence":DB::number(&update,"command_sequence")?,"message_sequence":message_sequence,
                "type":"update","target_scope":"instance","resolved_run_id":run_id,"status":"accepted","outcome":null}
        });
        let mut applied = payload.clone();
        applied["arguments"] = wire(&DB::string(&update, "arguments")?);
        Self::append(
            tx,
            &run_id,
            "UpdateApplied",
            applied,
            Some(&task_id),
            Some(&command_id),
        )
        .await?;
        let mut completed = payload;
        completed["result"] = wire(&result);
        Self::append(
            tx,
            &run_id,
            "UpdateCompleted",
            completed,
            Some(&task_id),
            Some(&command_id),
        )
        .await?;
        let applied_at = now();
        Self::query("UPDATE workflow_updates SET status='completed',outcome='update_completed',workflow_sequence=$1,result=$2,applied_at=$3,closed_at=$4 WHERE id=$5")
            .bind(sequence).bind(result).bind(DB::bind_time(applied_at)).bind(DB::bind_time(applied_at)).bind(update_id).execute(&mut **tx).await?;
        Self::query(
            "UPDATE workflow_commands SET outcome='update_completed',applied_at=$1 WHERE id=$2",
        )
        .bind(DB::bind_time(applied_at))
        .bind(&command_id)
        .execute(&mut **tx)
        .await?;
        Self::query("UPDATE workflow_runs SET message_cursor_position=$1 WHERE id=$2")
            .bind(message_sequence)
            .bind(&run_id)
            .execute(&mut **tx)
            .await?;
        Self::append(tx, &run_id, "MessageCursorAdvanced", json!({
            "stream_key":format!("instance:{workflow_id}"),"previous_position":previous,"new_position":message_sequence
        }), Some(&task_id), None).await?;
        Ok(())
    }
}
