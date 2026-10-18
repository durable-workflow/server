//! Durable updates admitted at a quiescent wait, with original request receipts.
//! Concurrent queued control routing and validator tasks are not qualified.
use super::transaction::Transaction;
use super::{
    Result, RuntimeError,
    backend::Backend,
    envelope, refuse,
    store::{PreparedControlArguments, Store, id, now, reject_fields, validate_arguments},
    text, wire,
};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::time::Duration;

const MAX_UPDATES_PER_RUN: i64 = 64;
const UPDATE_ROW: &str = "SELECT u.*,c.workflow_type,c.message_sequence FROM workflow_updates u JOIN workflow_commands c ON c.id=u.workflow_command_id WHERE u.id=$1";

#[derive(Clone, Copy)]
enum UpdateLookup {
    AdmissionOwnership,
    AdmissionRun,
    StartContract,
    Inventory,
    WaitLookup,
    HistoryAppend,
    TaskEnqueue,
    ReceiptRead,
}

impl UpdateLookup {
    fn label(self) -> &'static str {
        match self {
            Self::AdmissionOwnership => "admission_ownership",
            Self::AdmissionRun => "admission_run",
            Self::StartContract => "start_contract",
            Self::Inventory => "inventory",
            Self::WaitLookup => "wait_lookup",
            Self::HistoryAppend => "history_append",
            Self::TaskEnqueue => "task_enqueue",
            Self::ReceiptRead => "receipt_read",
        }
    }
}

fn update_lookup_diagnostic(error: &RuntimeError, lookup: UpdateLookup) -> Option<Value> {
    matches!(error, RuntimeError::Database(sqlx::Error::RowNotFound))
        .then(|| json!({"event":"runtime_update_lookup_missing","lookup":lookup.label()}))
}

fn at_update_lookup(error: RuntimeError, lookup: UpdateLookup) -> RuntimeError {
    if let Some(diagnostic) = update_lookup_diagnostic(&error, lookup) {
        // A closed label inventory identifies the lookup without SQL, IDs,
        // error text or inputs. Keep the original error and HTTP behavior.
        eprintln!("{diagnostic}");
    }
    error
}

// Match PHP trim(), including NUL and vertical tab, without stripping Unicode
// whitespace from an exception's identity or message.
fn php_string(value: &Value) -> Option<&str> {
    value
        .as_str()
        .map(|value| value.trim_matches([' ', '\t', '\n', '\r', '\0', '\x0b']))
        .filter(|value| !value.is_empty())
}

pub(super) struct UpdateFailure<'a> {
    pub(super) update_id: &'a str,
    message: &'a str,
    exception_class: &'a str,
    exception_type: Option<&'a str>,
    non_retryable: bool,
    category: &'static str,
}

pub(super) fn update_failure(command: &Value) -> Result<UpdateFailure<'_>> {
    // PHP normalizes the recognized failure fields and ignores extra fields.
    // In particular, an unrelated result cannot turn a failure into success.
    let update_id = php_string(&command["update_id"])
        .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_update_failure"))?;
    let message = php_string(&command["message"])
        .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_update_failure"))?;
    let exception_class = php_string(&command["exception_class"]).unwrap_or("RuntimeException");
    let exception_type = php_string(&command["exception_type"]);
    // Unknown external class names are not PHP inheritance declarations. Keep
    // the original name while applying the published portable message rules.
    let category = super::failure_commands::category(exception_class, message);
    let non_retryable = command["non_retryable"]
        .as_bool()
        .unwrap_or_else(|| super::failure_commands::default_non_retryable(exception_class));
    Ok(UpdateFailure {
        update_id,
        message,
        exception_class,
        exception_type,
        non_retryable,
        category,
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn missing_lookup_diagnostics_have_only_closed_labels() {
        let lookups = [
            UpdateLookup::AdmissionOwnership,
            UpdateLookup::AdmissionRun,
            UpdateLookup::StartContract,
            UpdateLookup::Inventory,
            UpdateLookup::WaitLookup,
            UpdateLookup::HistoryAppend,
            UpdateLookup::TaskEnqueue,
            UpdateLookup::ReceiptRead,
        ];
        let expected = [
            "admission_ownership",
            "admission_run",
            "start_contract",
            "inventory",
            "wait_lookup",
            "history_append",
            "task_enqueue",
            "receipt_read",
        ];
        for (lookup, label) in lookups.into_iter().zip(expected) {
            assert_eq!(
                update_lookup_diagnostic(&RuntimeError::Database(sqlx::Error::RowNotFound), lookup),
                Some(json!({"event":"runtime_update_lookup_missing","lookup":label}))
            );
        }
    }

    #[test]
    fn unrelated_errors_and_refusals_have_no_lookup_diagnostic() {
        for error in [
            RuntimeError::Database(sqlx::Error::Protocol(
                "SYNTHETIC_PRIVATE_SQL_PAYLOAD_AUTH".into(),
            )),
            RuntimeError::Database(sqlx::Error::ColumnNotFound(
                "SYNTHETIC_PRIVATE_COLUMN".into(),
            )),
            RuntimeError::Database(sqlx::Error::PoolTimedOut),
            refuse(StatusCode::NOT_FOUND, "instance_not_found"),
        ] {
            assert!(update_lookup_diagnostic(&error, UpdateLookup::ReceiptRead).is_none());
        }
    }

    #[tokio::test]
    async fn lookup_annotation_preserves_the_original_http_response() {
        use axum::response::IntoResponse;
        let original = RuntimeError::Database(sqlx::Error::RowNotFound).into_response();
        let annotated = at_update_lookup(
            RuntimeError::Database(sqlx::Error::RowNotFound),
            UpdateLookup::ReceiptRead,
        )
        .into_response();
        assert_eq!(original.status(), annotated.status());
        assert_eq!(original.headers(), annotated.headers());
        assert_eq!(
            axum::body::to_bytes(original.into_body(), 256)
                .await
                .unwrap(),
            axum::body::to_bytes(annotated.into_body(), 256)
                .await
                .unwrap()
        );
    }

    #[test]
    fn failure_commands_keep_php_normalization_and_classification() {
        let fixture: Value = serde_json::from_str(include_str!(
            "../../../tests/Fixtures/ServerParityNormalization/update-failure-commands.json"
        ))
        .unwrap();
        for case in fixture["cases"].as_array().unwrap() {
            let failure = update_failure(&case["command"]).unwrap();
            assert_eq!(
                json!({"update_id":failure.update_id,"message":failure.message,
                "exception_class":failure.exception_class,"exception_type":failure.exception_type,
                "non_retryable":failure.non_retryable,"category":failure.category}),
                case["expected"]
            );
        }
        for command in fixture["rejected"].as_array().unwrap() {
            assert!(update_failure(command).is_err());
        }
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
        let mut tx = self
            .begin()
            .await
            .map_err(|error| at_update_lookup(error, UpdateLookup::AdmissionOwnership))?;
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
            let run = Self::active_run(&mut tx, &run_id)
                .await
                .map_err(|error| at_update_lookup(error, UpdateLookup::AdmissionRun))?;
            let started = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='WorkflowStarted' ORDER BY sequence LIMIT 1")
                .bind(&run_id).fetch_one(&mut *tx).await
                .map_err(|error| at_update_lookup(error.into(), UpdateLookup::StartContract))?;
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
                .bind(&run_id).fetch_one(&mut *tx).await
                .map_err(|error| at_update_lookup(error.into(), UpdateLookup::Inventory))?;
            let pending_signals = Self::scalar("SELECT COUNT(*) FROM workflow_signal_records WHERE workflow_run_id=$1 AND status='received'")
                .bind(&run_id).fetch_one(&mut *tx).await
                .map_err(|error| at_update_lookup(error.into(), UpdateLookup::Inventory))?;
            let pending_updates = Self::scalar("SELECT COUNT(*) FROM workflow_updates WHERE workflow_run_id=$1 AND status='accepted'")
                .bind(&run_id).fetch_one(&mut *tx).await
                .map_err(|error| at_update_lookup(error.into(), UpdateLookup::Inventory))?;
            if open_tasks != 0
                || pending_signals != 0
                || pending_updates != 0
                || Self::open_wait(&mut tx, &run_id)
                    .await
                    .map_err(|error| at_update_lookup(error, UpdateLookup::WaitLookup))?
                    .is_none()
            {
                return Err(refuse(StatusCode::CONFLICT, "update_snapshot_busy"));
            }
            let count =
                Self::scalar("SELECT COUNT(*) FROM workflow_updates WHERE workflow_run_id=$1")
                    .bind(&run_id)
                    .fetch_one(&mut *tx)
                    .await
                    .map_err(|error| at_update_lookup(error.into(), UpdateLookup::Inventory))?;
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
            }), None, Some(&command_id)).await
                .map_err(|error| at_update_lookup(error, UpdateLookup::HistoryAppend))?;
            let task_id = Self::create_task(&mut tx, &run_id, &DB::string(&run, "queue")?, "workflow",
                json!({"resume_source_kind":"workflow_update","workflow_update_id":update_id,"workflow_command_id":command_id})).await
                .map_err(|error| at_update_lookup(error, UpdateLookup::TaskEnqueue))?;
            Self::query("UPDATE workflow_run_waits SET task_id=$1,task_type='workflow',task_status='ready',task_backed=TRUE WHERE workflow_run_id=$2 AND wait_id=$3 AND kind='update' AND status='open'")
                .bind(task_id).bind(&run_id).bind(format!("update:{update_id}")).execute(&mut *tx).await?;
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
                .await
                .map_err(|error| at_update_lookup(error.into(), UpdateLookup::ReceiptRead))?;
            let status = DB::string(&update, "status")?;
            let completed = status == "completed";
            let failed = status == "failed";
            let timed_out = wait_for == "completed"
                && !completed
                && !failed
                && tokio::time::Instant::now() >= deadline;
            if completed || failed || wait_for == "accepted" || timed_out {
                return Ok((
                    if failed {
                        StatusCode::UNPROCESSABLE_ENTITY
                    } else if completed {
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
        let failure_id = DB::optional_string(update, "failure_id")?;
        let failure_message = DB::optional_string(update, "failure_message")?;
        if status == "failed"
            && (failure_id.as_ref().is_none_or(|value| value.is_empty())
                || failure_message
                    .as_ref()
                    .is_none_or(|value| value.is_empty())
                || result.is_some())
        {
            return Err(refuse(
                StatusCode::CONFLICT,
                "invalid_stored_update_failure",
            ));
        }
        // MariaDB promotes COALESCE(unsigned BIGINT,0) to DECIMAL. Preserve
        // the physical nullable integer and its exact logical value instead.
        let sequence = DB::optional_number(update, "workflow_sequence")?;
        let response = json!({
            "accepted":true,"workflow_id":DB::string(update,"workflow_instance_id")?,"run_id":DB::string(update,"workflow_run_id")?,
            "resolved_run_id":DB::string(update,"resolved_workflow_run_id")?,"requested_run_id":null,"target_scope":"instance",
            "workflow_type":DB::string(update,"workflow_type")?,"command_id":DB::string(update,"workflow_command_id")?,
            "command_sequence":DB::number(update,"command_sequence")?,"command_status":"accepted","command_source":"control_plane",
            "update_id":DB::string(update,"id")?,"update_name":DB::string(update,"update_name")?,"update_status":status,
            "workflow_sequence":sequence,
            "outcome":DB::optional_string(update,"outcome")?,"result":null,"result_envelope":result.as_deref().map(wire),
            "failure_id":failure_id,"failure_message":failure_message,
            "accepted_at":DB::instant(update,"accepted_at")?,"applied_at":DB::optional_instant(update,"applied_at")?,
            "closed_at":DB::optional_instant(update,"closed_at")?,"reason":null,"rejection_reason":null,"validation_errors":[],
            "wait_for":wait_for,"wait_timed_out":timed_out,"wait_timeout_seconds":timeout
        });
        Ok(super::control_plane::Operation::Update.response(response))
    }

    pub(super) fn update_worker_supported(
        worker: &DB::Row,
        workflow_type: &str,
        name: &str,
    ) -> Result<bool> {
        let capabilities =
            DB::optional_document_row(worker, "capabilities")?.unwrap_or(Value::Null);
        let contracts =
            DB::optional_document_row(worker, "workflow_command_contracts")?.unwrap_or(Value::Null);
        Ok(DB::string(worker, "status")? == "active"
            && DB::instant(worker, "last_heartbeat_at")? > now() - chrono::Duration::seconds(60)
            && DB::document_row(worker, "supported_workflow_types")?
                .as_array()
                .is_some_and(|types| types.iter().any(|value| value == workflow_type))
            && capabilities
                .as_array()
                .is_some_and(|names| names.iter().any(|value| value == "workflow_updates"))
            && contracts[workflow_type]["updates"]
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
        let failure = (command["type"] == "fail_update")
            .then(|| update_failure(command))
            .transpose()?;
        let update_id = match &failure {
            Some(failure) => failure.update_id,
            None => text(command, "update_id")?,
        };
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
        let payload = json!({
            "workflow_command_id":command_id,"update_id":update_id,"workflow_instance_id":workflow_id,
            "workflow_run_id":run_id,"update_name":name,"sequence":sequence,
            "command":{"id":command_id,"sequence":DB::number(&update,"command_sequence")?,"message_sequence":message_sequence,
                "type":"update","target_scope":"instance","resolved_run_id":run_id,"status":"accepted","outcome":null}
        });
        if let Some(failure) = failure {
            let failure_id = id();
            let closed_at = now();
            Self::query("INSERT INTO workflow_failures(id,workflow_run_id,source_kind,source_id,propagation_kind,failure_category,exception_class,message,non_retryable,handled,file,line,trace_preview,created_at,updated_at) VALUES ($1,$2,'workflow_command',$3,'update',$4,$5,$6,CASE WHEN $7='true' THEN TRUE ELSE FALSE END,FALSE,'',0,'',$8,$9)")
                .bind(&failure_id).bind(&run_id).bind(&command_id).bind(failure.category)
                .bind(failure.exception_class).bind(failure.message).bind(if failure.non_retryable {"true"} else {"false"})
                .bind(DB::bind_time(closed_at)).bind(DB::bind_time(closed_at)).execute(&mut **tx).await?;
            let mut completed = payload;
            completed["failure_id"] = json!(failure_id);
            completed["failure_category"] = json!(failure.category);
            completed["non_retryable"] = json!(failure.non_retryable);
            completed["exception_type"] = json!(failure.exception_type);
            completed["exception_class"] = json!(failure.exception_class);
            completed["message"] = json!(failure.message);
            completed["code"] = json!(0);
            completed["exception"] = json!({"class":failure.exception_class,"type":failure.exception_type,
                "message":failure.message,"code":0,"file":"","line":0,"trace":[],"properties":[]});
            Self::append(
                tx,
                &run_id,
                "UpdateCompleted",
                completed,
                Some(&task_id),
                Some(&command_id),
            )
            .await?;
            Self::query("UPDATE workflow_updates SET status='failed',outcome='update_failed',workflow_sequence=$1,failure_id=$2,failure_message=$3,applied_at=$4,closed_at=$5 WHERE id=$6")
                .bind(sequence).bind(&failure_id).bind(failure.message).bind(DB::bind_time(closed_at))
                .bind(DB::bind_time(closed_at)).bind(update_id).execute(&mut **tx).await?;
            Self::query(
                "UPDATE workflow_commands SET outcome='update_failed',applied_at=$1 WHERE id=$2",
            )
            .bind(DB::bind_time(closed_at))
            .bind(&command_id)
            .execute(&mut **tx)
            .await?;
        } else {
            let result = envelope(command, "result")?;
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
        }
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
