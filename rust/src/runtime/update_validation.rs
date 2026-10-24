//! Published PHP 2.11.1's synchronous update validation
//! (`WorkflowUpdateValidationTaskBroker` and
//! `ValidatedExternalWorkflowUpdateAdmission`).
//!
//! An update whose durable contract declares a validator is not admitted until
//! a validator-capable worker approves it. The caller waits up to
//! `DW_UPDATE_VALIDATION_TIMEOUT` seconds. A worker claims the validation task
//! for `DW_UPDATE_VALIDATION_LEASE_TIMEOUT` seconds and approves or rejects it.
//! Every outcome stays on the task row, so a retry with the same `request_id`
//! and arguments gets the same answer.
use super::transaction::Transaction;
use super::workflow_poll_requests::WorkflowPollRequest;
use super::{Result, backend::Backend, refuse, store::{Store, id, now}, text, wire};
use axum::http::StatusCode;
use serde_json::{Map, Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::sync::OnceLock;
use std::time::Duration;

pub(super) const CAPABILITY: &str = "update_validation_tasks";
const CONTRACT_FIELD: &str = "update_validators";
const TERMINAL: [&str; 4] = ["approved", "rejected", "failed", "timed_out"];
pub(super) const VALIDATOR_REJECTED: &str = "update_validator_rejected";
const TIMEOUT_MESSAGE: &str = "Synchronous update validation did not complete before the acceptance deadline.";
const NOT_ADVERTISED: &str = "Worker registration does not advertise synchronous update validation.";

/// `server.update_validation`: how long a caller waits for a validator, and
/// how long a worker's claim lasts.
pub(super) fn timeouts() -> (i64, i64) {
    static TIMEOUTS: OnceLock<(i64, i64)> = OnceLock::new();
    *TIMEOUTS.get_or_init(|| crate::config_audit::update_validation_timeouts(|key| std::env::var(key).ok()))
}

/// `WorkerProtocol::serverCapabilities()['synchronous_update_validation']`.
pub(super) fn capability() -> Value {
    let (timeout, lease) = timeouts();
    json!({"supported":true,"minimum_protocol_version":"1.13","acceptance_boundary":"validator_approved",
        "worker_capability":CAPABILITY,"workflow_contract_field":CONTRACT_FIELD,
        "validator_execution":"replay_without_handler_or_state_commit","request_id_required":true,
        "duplicate_completion":"typed_rejection","stale_completion":"typed_rejection",
        "missing_contract":"typed_rejection","unsupported_worker":"typed_rejection",
        "task_poll":{"strategy":"multiplexed","endpoint":"/worker/workflow-tasks/poll","request_field":"task_kinds",
            "task_kinds":["workflow","update_validation"],"default_task_kinds":["workflow"],
            "response_discriminator":"task.task_kind","poll_request_id_binding":"normalized_task_kind_set",
            "poll_request_id_conflict_reason":"poll_request_task_kinds_conflict"},
        "completion":{"approve_endpoint":"/worker/update-validation-tasks/{taskId}/approve",
            "reject_endpoint":"/worker/update-validation-tasks/{taskId}/reject",
            "fence_fields":["lease_owner","update_validation_attempt"],
            "typed_failure_reasons":["update_validation_task_not_found","duplicate_update_validation_completion",
                "update_validation_task_not_leased","update_validation_lease_owner_mismatch",
                "stale_update_validation_completion","update_validation_lease_expired","update_validator_worker_lost"]},
        "timeout_seconds":timeout,"lease_timeout_seconds":lease,
        "sdk_surfaces":{"sdk-python":"validator_capable","sdk-php":"validators_not_exposed","sdk-rust":"validators_not_exposed"}})
}

/// The 409 a poll gets from a registration without the capability.
pub(super) fn not_advertised() -> super::RuntimeError {
    super::RuntimeError::Protocol {
        status: StatusCode::CONFLICT,
        response: json!({"task":null,"poll_status":"unsupported",
            "reason":"update_validation_capability_not_advertised","error":NOT_ADVERTISED}),
    }
}

/// `stringValue`: a trimmed, non-empty string.
fn string_value(value: &Value) -> Option<&str> {
    value.as_str().map(str::trim).filter(|text| !text.is_empty())
}

/// `stringList`: trimmed, unique, non-empty strings.
fn string_list(value: &Value) -> Vec<String> {
    let mut list: Vec<String> = value
        .as_array()
        .map(|items| items.iter().filter_map(string_value).map(str::to_owned).collect())
        .unwrap_or_default();
    list.sort();
    list.dedup();
    list
}

/// `validationErrors`: field names mapped to their non-empty string messages.
pub(super) fn validation_errors(value: &Value) -> Map<String, Value> {
    let fields: Vec<(String, &Value)> = match value {
        Value::Object(map) => map.iter().map(|(field, messages)| (field.clone(), messages)).collect(),
        Value::Array(list) => list.iter().enumerate().map(|(field, messages)| (field.to_string(), messages)).collect(),
        _ => vec![],
    };
    let mut errors = Map::new();
    for (field, messages) in fields {
        let Some(messages) = messages.as_array() else { continue };
        let kept: Vec<Value> = messages
            .iter()
            .filter(|message| message.as_str().is_some_and(|text| !text.is_empty()))
            .cloned()
            .collect();
        if !kept.is_empty() {
            errors.insert(field, Value::Array(kept));
        }
    }
    errors
}

fn sha256(text: &str) -> String {
    use sha2::{Digest, Sha256};
    Sha256::digest(text.as_bytes()).iter().map(|byte| format!("{byte:02x}")).collect()
}

/// `ValidatedExternalWorkflowUpdateAdmission::validationFailure`.
pub(super) fn admission_failure(
    workflow_id: &str,
    run_id: &str,
    name: &str,
    reason: &str,
    message: &str,
    retryable: bool,
    validation_errors: Option<Value>,
) -> Value {
    let mut body = json!({"workflow_id":workflow_id,"run_id":run_id,"update_name":name,
        "outcome":"update_validation_failed","reason":reason,"rejection_reason":reason,"message":message});
    if let Some(errors) = validation_errors.filter(|errors| errors.as_object().is_some_and(|map| !map.is_empty())) {
        body["validation_errors"] = errors;
    }
    body["retryable"] = json!(retryable);
    body
}

/// `WorkflowUpdateValidationTaskBroker::failure`.
#[allow(clippy::too_many_arguments)]
fn broker_failure(
    workflow_id: &str,
    run_id: &str,
    name: &str,
    reason: &str,
    message: &str,
    errors: Map<String, Value>,
    update_id: &str,
    retryable: bool,
) -> Value {
    let rejected = reason == VALIDATOR_REJECTED;
    let mut body = json!({"approved":false,"workflow_id":workflow_id,"run_id":run_id,"update_name":name,"update_id":update_id});
    if rejected {
        body["update_status"] = json!("rejected");
    }
    body["outcome"] = json!(if rejected { "rejected_invalid_arguments" } else { "update_validation_failed" });
    body["reason"] = json!(reason);
    body["rejection_reason"] = json!(reason);
    body["message"] = json!(message);
    if !errors.is_empty() {
        body["validation_errors"] = Value::Object(errors);
    }
    body["retryable"] = json!(retryable);
    body
}

/// `staleValidationFailure`: the run moved on between validation and commit.
pub(super) fn stale_failure(workflow_id: &str, run_id: &str, name: &str, update_id: &str, message: &str) -> (StatusCode, Value) {
    let mut body = json!({"workflow_id":workflow_id});
    if !run_id.is_empty() {
        body["run_id"] = json!(run_id);
    }
    body["update_name"] = json!(name);
    body["update_id"] = json!(update_id);
    body["outcome"] = json!("update_validation_failed");
    body["reason"] = json!("stale_update_validation_completion");
    body["rejection_reason"] = json!("stale_update_validation_completion");
    body["message"] = json!(message);
    body["retryable"] = json!(true);
    (StatusCode::CONFLICT, body)
}

/// `RunCommandContract::forRun` from durable history: the start event holds
/// every declared contract field, and each declared query and update has a
/// handler contract.
pub(super) fn durable_contract(started: &Value) -> bool {
    const FIELDS: [&str; 9] = ["declared_queries", "declared_query_contracts", "declared_signals",
        "declared_signal_contracts", "declared_updates", "declared_update_contracts", "declared_entry_method",
        "declared_entry_mode", "declared_entry_declaring_class"];
    if !FIELDS.iter().all(|field| started.get(*field).is_some()) {
        return false;
    }
    let lists = ["declared_queries", "declared_query_contracts", "declared_signals", "declared_signal_contracts",
        "declared_updates", "declared_update_contracts"];
    if !lists.iter().all(|field| started[*field].is_array()) {
        return false;
    }
    if started["declared_entry_method"] != "handle"
        || started["declared_entry_mode"] != "canonical"
        || string_value(&started["declared_entry_declaring_class"]).is_none()
    {
        return false;
    }
    let covered = |names: &str, contracts: &str| {
        let handled: Vec<&str> = started[contracts]
            .as_array()
            .map(|items| items.iter().filter_map(|item| string_value(&item["name"])).collect())
            .unwrap_or_default();
        string_list(&started[names]).iter().all(|name| handled.contains(&name.as_str()))
    };
    covered("declared_queries", "declared_query_contracts") && covered("declared_updates", "declared_update_contracts")
}

/// What one update's validation decided.
pub(super) enum Validation {
    /// Admit the update under the task's id.
    Approved { task_id: String, input_hash: String },
    /// The validator refused it; the rejection is recorded durably.
    Rejected { failure: Value, validation_errors: Value },
    /// Anything else: answer with this status and body, recording nothing.
    Failed(StatusCode, Value),
}

/// The update a caller asked to validate.
pub(super) struct ValidationRequest<'a> {
    pub(super) workflow_id: &'a str,
    pub(super) run_id: &'a str,
    pub(super) workflow_type: &'a str,
    pub(super) task_queue: &'a str,
    pub(super) compatibility: Option<&'a str>,
    pub(super) fingerprint: Option<&'a str>,
    pub(super) name: &'a str,
    pub(super) request_id: &'a str,
    pub(super) arguments: &'a str,
    pub(super) context: Option<&'a Value>,
}

/// `inputHash`: the codec and serialized arguments.
pub(super) fn input_hash(arguments: &str) -> String {
    sha256(&format!("avro\0{arguments}"))
}

/// One registration as validation routing reads it.
struct Registration {
    worker_id: String,
    fresh: bool,
    capable: bool,
    workflow_types: Vec<String>,
    contracts: Value,
    build_id: Option<String>,
    fingerprints: Value,
}

impl Registration {
    /// `workerMatchesRun` and the type, contract, build and fingerprint
    /// parts of `workerMatchesTask`.
    fn serves(&self, workflow_type: &str, name: &str, compatibility: Option<&str>, fingerprint: Option<&str>) -> bool {
        self.workflow_types.iter().any(|kind| kind == workflow_type)
            && self.contracts[workflow_type].is_object()
            && string_list(&self.contracts[workflow_type][CONTRACT_FIELD]).iter().any(|declared| declared == name)
            && compatibility.is_none_or(|required| self.build_id.as_deref() == Some(required))
            && fingerprint.is_none_or(|recorded| self.fingerprints[workflow_type].as_str() == Some(recorded))
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
    fn registration(&self, row: &DB::Row) -> Result<Registration> {
        let fresh_after = now() - chrono::Duration::seconds(self.worker_stale_after_seconds.max(1));
        // `WorkerPollFence::isFresh`: active, with a recent heartbeat.
        let fresh = DB::optional_string(row, "status")?.as_deref() == Some("active")
            && DB::optional_instant(row, "last_heartbeat_at")?.is_some_and(|heartbeat| heartbeat >= fresh_after);
        let capabilities = DB::optional_document_row(row, "capabilities")?.unwrap_or(Value::Null);
        Ok(Registration {
            worker_id: DB::string(row, "worker_id")?,
            fresh,
            capable: capabilities.as_array().is_some_and(|names| names.iter().any(|name| name == CAPABILITY)),
            workflow_types: string_list(&DB::optional_document_row(row, "supported_workflow_types")?.unwrap_or(Value::Null)),
            contracts: DB::optional_document_row(row, "workflow_command_contracts")?.unwrap_or(Value::Null),
            build_id: DB::optional_string(row, "build_id")?.filter(|build| !build.is_empty()),
            fingerprints: DB::optional_document_row(row, "workflow_definition_fingerprints")?.unwrap_or(Value::Null),
        })
    }

    /// Whether this registration may claim validation tasks at all.
    pub(super) async fn worker_validates(&self, worker_id: &str) -> Result<bool> {
        let row = Self::query("SELECT * FROM workflow_worker_registrations WHERE worker_id=$1 AND namespace=$2")
            .bind(worker_id)
            .bind(self.namespace.as_ref())
            .fetch_optional(&self.pool)
            .await?;
        Ok(match row {
            Some(row) => self.registration(&row)?.capable,
            None => false,
        })
    }

    /// `route`: the first missing link between the queue's workers and this
    /// run's validator, with its message and whether a retry may help.
    async fn validation_route(&self, request: &ValidationRequest<'_>) -> Result<Option<(&'static str, &'static str, bool)>> {
        let rows = Self::query("SELECT * FROM workflow_worker_registrations WHERE namespace=$1 AND task_queue=$2 AND status='active'")
            .bind(self.namespace.as_ref())
            .bind(request.task_queue)
            .fetch_all(&self.pool)
            .await?;
        let mut workers = Vec::with_capacity(rows.len());
        for row in &rows {
            let worker = self.registration(row)?;
            if worker.fresh {
                workers.push(worker);
            }
        }
        if workers.is_empty() {
            return Ok(Some(("update_validator_worker_unavailable",
                "No active worker is available to perform synchronous update validation.", true)));
        }
        workers.retain(|worker| worker.capable);
        if workers.is_empty() {
            return Ok(Some(("update_validation_capability_unsupported",
                "Active workers do not advertise synchronous pre-accept update validation.", false)));
        }
        if !workers.iter().any(|worker| worker.serves(request.workflow_type, request.name, request.compatibility, request.fingerprint)) {
            return Ok(Some(("update_validator_worker_incompatible",
                "Active workers do not advertise a validator-capable implementation matching this workflow run.", true)));
        }
        Ok(None)
    }

    /// `findOrCreateTask`: one task per run, update name and request id.
    async fn validation_task(&self, request: &ValidationRequest<'_>, input_hash: &str) -> Result<DB::Row> {
        let key = sha256(&[self.namespace.as_ref(), request.run_id, request.name, request.request_id].join("\0"));
        let mut tx = self.begin().await?;
        let existing = Self::query("SELECT * FROM workflow_update_validation_tasks WHERE idempotency_key=$1")
            .bind(&key)
            .fetch_optional(&mut *tx)
            .await?;
        if let Some(existing) = existing {
            tx.commit().await?;
            return Ok(existing);
        }
        let task_id = id();
        let at = now();
        Self::query("INSERT INTO workflow_update_validation_tasks(id,idempotency_key,namespace,workflow_instance_id,workflow_run_id,workflow_type,task_queue,compatibility,workflow_definition_fingerprint,update_name,request_id,input_hash,payload_codec,arguments,command_context,status,attempt_count,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,'avro',$13,$14,'pending',0,$15,$16)")
            .bind(&task_id).bind(&key).bind(self.namespace.as_ref()).bind(request.workflow_id).bind(request.run_id)
            .bind(request.workflow_type).bind(request.task_queue).bind(request.compatibility).bind(request.fingerprint)
            .bind(request.name).bind(request.request_id).bind(input_hash).bind(request.arguments)
            .bind(request.context.map(DB::document)).bind(DB::bind_time(at)).bind(DB::bind_time(at))
            .execute(&mut *tx)
            .await?;
        let row = Self::query("SELECT * FROM workflow_update_validation_tasks WHERE id=$1")
            .bind(&task_id)
            .fetch_one(&mut *tx)
            .await?;
        tx.commit().await?;
        Ok(row)
    }

    /// `WorkflowUpdateValidationTaskBroker::validate`.
    pub(super) async fn validate_update(&self, request: &ValidationRequest<'_>) -> Result<Validation> {
        let hash = input_hash(request.arguments);
        let task = self.validation_task(request, &hash).await?;
        let task_id = DB::string(&task, "id")?;
        if DB::string(&task, "input_hash")? != hash {
            return Ok(Validation::Failed(StatusCode::CONFLICT, broker_failure(
                request.workflow_id, request.run_id, request.name, "update_validation_idempotency_conflict",
                "The update request ID was already used with different arguments.", Map::new(), &task_id, false)));
        }
        if !TERMINAL.contains(&DB::string(&task, "status")?.as_str()) {
            if let Some((reason, message, retryable)) = self.validation_route(request).await? {
                return Ok(Validation::Failed(StatusCode::CONFLICT, broker_failure(
                    request.workflow_id, request.run_id, request.name, reason, message, Map::new(), &task_id, retryable)));
            }
            // Pollers on this node wake now; other nodes find the task on
            // their next read.
            self.wake.notify_waiters();
            let deadline = tokio::time::Instant::now() + Duration::from_secs(timeouts().0 as u64);
            loop {
                let wake = self.wake.notified();
                let status = Self::query("SELECT status FROM workflow_update_validation_tasks WHERE id=$1")
                    .bind(&task_id)
                    .fetch_one(&self.pool)
                    .await?;
                if TERMINAL.contains(&DB::string(&status, "status")?.as_str()) {
                    break;
                }
                if tokio::time::Instant::now() >= deadline {
                    self.time_out_validation(&task_id).await?;
                    break;
                }
                tokio::select! {
                    _ = wake => {},
                    _ = tokio::time::sleep(Duration::from_millis(50)) => {},
                    _ = tokio::time::sleep_until(deadline) => {},
                }
            }
        }
        let task = Self::query("SELECT * FROM workflow_update_validation_tasks WHERE id=$1")
            .bind(&task_id)
            .fetch_one(&self.pool)
            .await?;
        self.validation_result(&task, request)
    }

    /// `result`: an approval, or the stored failure with its HTTP status.
    fn validation_result(&self, task: &DB::Row, request: &ValidationRequest<'_>) -> Result<Validation> {
        let task_id = DB::string(task, "id")?;
        let status = DB::string(task, "status")?;
        if status == "approved" {
            return Ok(Validation::Approved { task_id, input_hash: DB::string(task, "input_hash")? });
        }
        let reason = DB::optional_string(task, "rejection_reason")?.unwrap_or_else(|| "update_validation_failed".into());
        let message = DB::optional_string(task, "rejection_message")?.unwrap_or_else(|| "Synchronous update validation failed.".into());
        let errors = validation_errors(&DB::optional_document_row(task, "validation_errors")?.unwrap_or(Value::Null));
        let mut failure = broker_failure(request.workflow_id, request.run_id, request.name, &reason, &message, errors.clone(), &task_id, false);
        if let Some(kind) = DB::optional_string(task, "failure_type")? {
            failure["failure_type"] = json!(kind);
        }
        failure["update_validation_attempt"] = json!(DB::number(task, "attempt_count")?);
        if status == "rejected" && reason == VALIDATOR_REJECTED {
            return Ok(Validation::Rejected { failure, validation_errors: Value::Object(errors) });
        }
        let http = match status.as_str() {
            "rejected" => StatusCode::UNPROCESSABLE_ENTITY,
            "timed_out" => StatusCode::GATEWAY_TIMEOUT,
            _ => StatusCode::CONFLICT,
        };
        Ok(Validation::Failed(http, failure))
    }

    /// `markTimedOut`: the reason names what was still missing.
    async fn time_out_validation(&self, task_id: &str) -> Result<()> {
        let mut tx = self.begin().await?;
        let task = Self::query("SELECT * FROM workflow_update_validation_tasks WHERE id=$1")
            .bind(task_id)
            .fetch_one(&mut *tx)
            .await?;
        let status = DB::string(&task, "status")?;
        if TERMINAL.contains(&status.as_str()) {
            tx.commit().await?;
            return Ok(());
        }
        let owner = DB::optional_string(&task, "lease_owner")?;
        let mut lost = false;
        if status == "leased" {
            let worker = match owner.as_deref() {
                Some(owner) => Self::query("SELECT * FROM workflow_worker_registrations WHERE namespace=$1 AND worker_id=$2")
                    .bind(DB::string(&task, "namespace")?)
                    .bind(owner)
                    .fetch_optional(&mut *tx)
                    .await?,
                None => None,
            };
            lost = match worker {
                Some(worker) => !self.registration(&worker)?.fresh,
                None => true,
            };
        }
        let (reason, message) = if lost {
            ("update_validator_worker_lost", "The validator worker was lost before returning a validation result.")
        } else if status == "pending" {
            ("update_validation_task_not_claimed", TIMEOUT_MESSAGE)
        } else {
            ("update_validation_execution_timeout", TIMEOUT_MESSAGE)
        };
        let at = now();
        Self::query("UPDATE workflow_update_validation_tasks SET status='timed_out',rejection_reason=$1,rejection_message=$2,timed_out_at=$3,lease_expires_at=NULL,updated_at=$4 WHERE id=$5")
            .bind(reason).bind(message).bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(task_id)
            .execute(&mut *tx)
            .await?;
        tx.commit().await?;
        Ok(())
    }

    /// `approvedTask`: the approval still stands for this run and input.
    pub(super) async fn approved_validation(
        tx: &mut super::transaction::Transaction<'_, DB>,
        task_id: &str,
        run_id: &str,
        input_hash: &str,
    ) -> Result<bool> {
        Ok(Self::query("SELECT id FROM workflow_update_validation_tasks WHERE id=$1 AND workflow_run_id=$2 AND input_hash=$3 AND status='approved'")
            .bind(task_id).bind(run_id).bind(input_hash)
            .fetch_optional(&mut **tx)
            .await?
            .is_some())
    }

    /// `claim`: the oldest claimable task this worker can validate, leased
    /// to it for the lease timeout.
    pub(super) async fn claim_update_validation(&self, worker_id: &str, task_queue: &str) -> Result<Option<String>> {
        let mut tx = self.begin().await?;
        let claimed = self.claim_update_validation_in(&mut tx, worker_id, task_queue).await?;
        tx.commit().await?;
        Ok(claimed.map(|claim| claim.task_id))
    }

    /// `claim` inside the caller's transaction, which commits it.
    async fn claim_update_validation_in(
        &self,
        tx: &mut Transaction<'_, DB>,
        worker_id: &str,
        task_queue: &str,
    ) -> Result<Option<ValidationClaim>> {
        let Some(worker) = Self::query("SELECT * FROM workflow_worker_registrations WHERE worker_id=$1 AND namespace=$2")
            .bind(worker_id)
            .bind(self.namespace.as_ref())
            .fetch_optional(&mut **tx)
            .await?
        else {
            return Ok(None);
        };
        let worker = self.registration(&worker)?;
        if !worker.fresh || !worker.capable {
            return Ok(None);
        }
        let at = now();
        let tasks = Self::query("SELECT * FROM workflow_update_validation_tasks WHERE namespace=$1 AND task_queue=$2 AND (status='pending' OR (status='leased' AND lease_expires_at<=$3)) ORDER BY created_at,id LIMIT 25")
            .bind(self.namespace.as_ref())
            .bind(task_queue)
            .bind(DB::bind_time(at))
            .fetch_all(&mut **tx)
            .await?;
        for task in tasks {
            let compatibility = DB::optional_string(&task, "compatibility")?;
            let fingerprint = DB::optional_string(&task, "workflow_definition_fingerprint")?;
            if !worker.serves(&DB::string(&task, "workflow_type")?, &DB::string(&task, "update_name")?,
                compatibility.as_deref(), fingerprint.as_deref())
            {
                continue;
            }
            let task_id = DB::string(&task, "id")?;
            let attempt = DB::number(&task, "attempt_count")?.saturating_add(1);
            let expires = at + chrono::Duration::seconds(timeouts().1);
            Self::query("UPDATE workflow_update_validation_tasks SET status='leased',attempt_count=$1,lease_owner=$2,lease_expires_at=$3,updated_at=$4 WHERE id=$5")
                .bind(attempt).bind(&worker.worker_id)
                .bind(DB::bind_time(expires))
                .bind(DB::bind_time(at)).bind(&task_id)
                .execute(&mut **tx)
                .await?;
            return Ok(Some(ValidationClaim { task_id, attempt, expires }));
        }
        Ok(None)
    }

    /// `taskPayload` without its history export, which the runtime adds.
    pub(super) async fn update_validation_payload(&self, task_id: &str) -> Result<Value> {
        let task = Self::query("SELECT * FROM workflow_update_validation_tasks WHERE id=$1")
            .bind(task_id)
            .fetch_one(&self.pool)
            .await?;
        let run_id = DB::string(&task, "workflow_run_id")?;
        let mut payload = json!({});
        let mut connection = self.pool.acquire().await?;
        if let Some(run) = Self::query("SELECT workflow_class,arguments,status,last_history_sequence FROM workflow_runs WHERE id=$1")
            .bind(&run_id)
            .fetch_optional(&mut *connection)
            .await?
        {
            // `workflowReplayPayload`: the whole history, unpaged.
            let mut history = Vec::new();
            let mut after = 0;
            loop {
                let (page, next) = Self::history_page_connection(&mut connection, &run_id, after, 1000).await?;
                after = page.last().and_then(|event| event["sequence"].as_i64()).unwrap_or(after);
                history.extend(page);
                if next.is_none() {
                    break;
                }
            }
            payload = json!({"workflow_class":DB::optional_string(&run, "workflow_class")?,
                "workflow_arguments":DB::optional_string(&run, "arguments")?.as_deref().map(wire),
                "run_status":DB::string(&run, "status")?,
                "last_history_sequence":DB::optional_number(&run, "last_history_sequence")?.unwrap_or(0),
                "history_events":history});
        }
        let fields = json!({"task_kind":"update_validation","update_validation_task_id":task_id,"update_id":task_id,
            "update_validation_attempt":DB::number(&task, "attempt_count")?,
            "workflow_id":DB::string(&task, "workflow_instance_id")?,"run_id":run_id,
            "workflow_type":DB::string(&task, "workflow_type")?,"compatibility":DB::optional_string(&task, "compatibility")?,
            "update_name":DB::string(&task, "update_name")?,"update_arguments":wire(&DB::string(&task, "arguments")?),
            "payload_codec":DB::string(&task, "payload_codec")?,"task_queue":DB::string(&task, "task_queue")?,
            "lease_owner":DB::optional_string(&task, "lease_owner")?,
            "lease_expires_at":super::task_queues::json_time(DB::optional_instant(&task, "lease_expires_at")?)});
        payload.as_object_mut().expect("payload").extend(fields.as_object().expect("fields").clone());
        Ok(payload)
    }

    /// `guardCompletion`, then `approve` or `reject`.
    pub(super) async fn complete_update_validation(&self, task_id: &str, body: &Value, failure: Option<&Value>) -> Result<(StatusCode, Value)> {
        let owner = text(body, "lease_owner")?;
        let attempt = super::laravel_validation::integer(&body["update_validation_attempt"])
            .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "validation_failed"))?;
        let mut tx = self.begin().await?;
        let task = Self::query("SELECT * FROM workflow_update_validation_tasks WHERE id=$1")
            .bind(task_id)
            .fetch_optional(&mut *tx)
            .await?
            .filter(|task| DB::string(task, "namespace").ok().as_deref() == Some(self.namespace.as_ref()));
        let refusal = |reason: &str, message: &str, status: StatusCode, current: Option<i64>| {
            let mut body = json!({"update_validation_task_id":task_id,"update_id":task_id});
            if let Some(current) = current {
                body["update_validation_attempt"] = json!(current);
            }
            body["outcome"] = json!("rejected");
            body["reason"] = json!(reason);
            body["error"] = json!(message);
            body["status"] = json!(status.as_u16());
            Ok((status, body))
        };
        let Some(task) = task else {
            tx.commit().await?;
            return refusal("update_validation_task_not_found", "Update validation task not found.", StatusCode::NOT_FOUND, None);
        };
        let status = DB::string(&task, "status")?;
        let current = DB::number(&task, "attempt_count")?;
        let lease_owner = DB::optional_string(&task, "lease_owner")?;
        let guard = if TERMINAL.contains(&status.as_str()) {
            Some(if current != attempt || lease_owner.as_deref() != Some(owner) {
                ("stale_update_validation_completion", "Update validation completion belongs to a stale delivery attempt.")
            } else {
                ("duplicate_update_validation_completion", "The update validation task already has a terminal result.")
            })
        } else if status != "leased" {
            Some(("update_validation_task_not_leased", "Update validation task is not leased."))
        } else if lease_owner.as_deref() != Some(owner) {
            Some(("update_validation_lease_owner_mismatch", "Update validation lease is owned by another worker."))
        } else if current != attempt {
            Some(("stale_update_validation_completion", "Update validation completion belongs to a stale delivery attempt."))
        } else if DB::optional_instant(&task, "lease_expires_at")?.is_none_or(|expires| expires <= now()) {
            Some(("update_validation_lease_expired", "Update validation task lease has expired."))
        } else {
            let worker = Self::query("SELECT * FROM workflow_worker_registrations WHERE namespace=$1 AND worker_id=$2")
                .bind(self.namespace.as_ref())
                .bind(owner)
                .fetch_optional(&mut *tx)
                .await?;
            let fresh = match worker {
                Some(worker) => self.registration(&worker)?.fresh,
                None => false,
            };
            (!fresh).then_some(("update_validator_worker_lost", "The validator worker is no longer active."))
        };
        if let Some((reason, message)) = guard {
            tx.commit().await?;
            return refusal(reason, message, StatusCode::CONFLICT, Some(current));
        }
        let at = now();
        let mut answer = json!({"update_validation_task_id":task_id,"update_id":task_id,"update_validation_attempt":attempt});
        match failure {
            None => {
                Self::query("UPDATE workflow_update_validation_tasks SET status='approved',approved_at=$1,lease_expires_at=NULL,updated_at=$2 WHERE id=$3")
                    .bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(task_id)
                    .execute(&mut *tx)
                    .await?;
                answer["outcome"] = json!("approved");
            }
            Some(failure) => {
                let reason = string_value(&failure["reason"]).unwrap_or(VALIDATOR_REJECTED).to_owned();
                let rejected = reason == VALIDATOR_REJECTED;
                let message = string_value(&failure["message"]).map(str::to_owned).unwrap_or_else(|| {
                    if rejected { "The update validator rejected the request." } else { "Update validation failed." }.to_owned()
                });
                let errors = Value::Object(validation_errors(&failure["validation_errors"]));
                let sql = if rejected {
                    "UPDATE workflow_update_validation_tasks SET status='rejected',rejection_reason=$1,rejection_message=$2,failure_type=$3,validation_errors=$4,rejected_at=$5,failed_at=NULL,lease_expires_at=NULL,updated_at=$6 WHERE id=$7"
                } else {
                    "UPDATE workflow_update_validation_tasks SET status='failed',rejection_reason=$1,rejection_message=$2,failure_type=$3,validation_errors=$4,rejected_at=NULL,failed_at=$5,lease_expires_at=NULL,updated_at=$6 WHERE id=$7"
                };
                Self::query(sql)
                    .bind(reason.as_str()).bind(message).bind(string_value(&failure["type"]))
                    .bind(DB::document(&errors)).bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(task_id)
                    .execute(&mut *tx)
                    .await?;
                answer["outcome"] = json!(if rejected { "rejected" } else { "failed" });
                answer["reason"] = json!(reason);
            }
        }
        answer["status"] = json!(200);
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok((StatusCode::OK, answer))
    }
}

/// The rules `pollUpdateValidationTasks` applies.
pub(super) fn poll_rules(body: &Value) -> Result<()> {
    use super::laravel_validation::{Rule, failure_messages, rule_errors};
    let rules: &[(&str, &[Rule])] = &[
        ("worker_id", &[Rule::Required, Rule::String]),
        ("task_queue", &[Rule::Required, Rule::String]),
        ("timeout_seconds", &[Rule::Nullable, Rule::Integer, Rule::Min(0), Rule::Max(60)]),
    ];
    failure_messages(rule_errors(body, rules, |_, _| None)).map_or(Ok(()), Err)
}

/// The rules `approveUpdateValidationTask` and `rejectUpdateValidationTask`
/// apply, nested failure fields named as Laravel names them.
pub(super) fn completion_rules(body: &Value, reject: bool) -> Result<()> {
    use super::laravel_validation::{Rule, failure_messages, rule_errors};
    let mut flat = Map::new();
    let mut rules: Vec<(String, Vec<Rule>)> = vec![
        ("lease_owner".into(), vec![Rule::Required, Rule::String]),
        ("update_validation_attempt".into(), vec![Rule::Required, Rule::Integer, Rule::Min(1)]),
    ];
    for field in ["lease_owner", "update_validation_attempt"] {
        if let Some(value) = body.get(field) {
            flat.insert(field.into(), value.clone());
        }
    }
    if reject {
        if let Some(failure) = body.get("failure") {
            flat.insert("failure".into(), failure.clone());
        }
        rules.push(("failure".into(), vec![Rule::Required, Rule::Array]));
        let failure = body.get("failure").filter(|failure| failure.is_object());
        let nested = |field: &str| failure.and_then(|failure| failure.get(field));
        for (field, field_rules) in [
            ("message", vec![Rule::Required, Rule::String]),
            ("reason", vec![Rule::Nullable, Rule::String]),
            ("type", vec![Rule::Nullable, Rule::String]),
            ("stack_trace", vec![Rule::Nullable, Rule::String]),
            ("validation_errors", vec![Rule::Nullable, Rule::Array]),
        ] {
            let name = format!("failure.{field}");
            if let Some(value) = nested(field) {
                flat.insert(name.clone(), value.clone());
            }
            rules.push((name, field_rules));
        }
        if let Some(errors) = nested("validation_errors").and_then(Value::as_object) {
            for (field, messages) in errors {
                let name = format!("failure.validation_errors.{field}");
                flat.insert(name.clone(), messages.clone());
                rules.push((name.clone(), vec![Rule::Array]));
                for (index, message) in messages.as_array().into_iter().flatten().enumerate() {
                    let item = format!("{name}.{index}");
                    flat.insert(item.clone(), message.clone());
                    rules.push((item, vec![Rule::String]));
                }
            }
        }
    }
    let borrowed: Vec<(&str, &[Rule])> = rules.iter().map(|(field, rules)| (field.as_str(), rules.as_slice())).collect();
    failure_messages(rule_errors(&Value::Object(flat), &borrowed, |_, _| None)).map_or(Ok(()), Err)
}

/// `task_kinds`: `nullable|array|min:1|max:2`, each a distinct `workflow` or
/// `update_validation`. Absent or null means workflow tasks only.
pub(super) fn task_kinds(body: &Value) -> Result<Vec<&'static str>> {
    use super::laravel_validation::{Rule, failure_messages, rule_errors};
    let Some(kinds) = body.get("task_kinds").filter(|kinds| !kinds.is_null()) else {
        return Ok(vec!["workflow"]);
    };
    let mut errors = rule_errors(body, &[("task_kinds", &[Rule::Nullable, Rule::Array, Rule::Min(1), Rule::Max(2)])], |_, _| None);
    let items: Vec<(String, Value)> = match kinds {
        Value::Array(items) => items.iter().enumerate().map(|(index, item)| (index.to_string(), item.clone())).collect(),
        Value::Object(items) => items.iter().map(|(key, item)| (key.clone(), item.clone())).collect(),
        _ => vec![],
    };
    for (key, item) in &items {
        let field = format!("task_kinds.{key}");
        let mut messages = Vec::new();
        if !item.is_string() {
            messages.push(format!("The {field} field must be a string."));
        }
        if items.iter().filter(|(_, other)| other == item).count() > 1 {
            messages.push(format!("The {field} field has a duplicate value."));
        }
        if !matches!(item.as_str(), Some("workflow" | "update_validation")) {
            messages.push(format!("The selected {field} is invalid."));
        }
        errors.push((field, messages));
    }
    if let Some(error) = failure_messages(errors) {
        return Err(error);
    }
    let mut requested = Vec::new();
    for kind in ["workflow", "update_validation"] {
        if items.iter().any(|(_, item)| item == kind) {
            requested.push(kind);
        }
    }
    Ok(requested)
}

/// What one multiplexed probe found: a duplicate's answer, a new lease, or
/// a registration replaced since the poll began.
enum Probe {
    Replayed(Value),
    Leased(Leased),
    Stale,
}

/// A validation lease: the task, its attempt and when the lease ends.
struct ValidationClaim {
    task_id: String,
    attempt: i64,
    expires: chrono::DateTime<chrono::Utc>,
}

/// What a multiplexed poll leased.
pub(super) enum Leased {
    /// A workflow poll's answer, leased or not.
    Workflow(Value),
    /// A validation task's id, leased to the polling worker.
    Validation(String),
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
    /// `resolveRegisteredWorker` for a validation poll, then its capability
    /// check. Answers the registration's supported workflow types.
    pub(super) async fn validation_poller(&self, worker_id: &str, task_queue: &str) -> Result<Vec<String>> {
        let Some(row) = Self::query("SELECT * FROM workflow_worker_registrations WHERE worker_id=$1 AND namespace=$2")
            .bind(worker_id)
            .bind(self.namespace.as_ref())
            .fetch_optional(&self.pool)
            .await?
        else {
            return Err(super::RuntimeError::Protocol {
                status: StatusCode::PRECONDITION_FAILED,
                response: json!({"error":"Worker must be registered before polling. Call POST /worker/register first.",
                    "reason":"worker_not_registered","worker_id":worker_id}),
            });
        };
        let registered = DB::string(&row, "task_queue")?;
        if registered != task_queue {
            return Err(super::RuntimeError::Protocol {
                status: StatusCode::CONFLICT,
                response: json!({"error":format!("Worker [{worker_id}] is registered for task queue [{registered}], not [{task_queue}]."),
                    "reason":"task_queue_mismatch","worker_id":worker_id,"registered_task_queue":registered,
                    "requested_task_queue":task_queue}),
            });
        }
        self.refuse_draining_worker(worker_id, task_queue).await?;
        Ok(self.registration(&row)?.workflow_types)
    }

    /// A dedicated validation poll: the registration, its capability, then
    /// the wait for a claim.
    pub(super) async fn update_validation_poll(&self, worker_id: &str, task_queue: &str, timeout: u64) -> Result<Option<String>> {
        self.validation_poller(worker_id, task_queue).await?;
        if !self.worker_validates(worker_id).await? {
            return Err(not_advertised());
        }
        self.poll_update_validation(worker_id, task_queue, timeout).await
    }

    /// A workflow poll that asked for validation tasks: the registration,
    /// a workflow worker, the capability, then both kinds.
    pub(super) async fn multiplexed_poll(
        &self,
        body: &Value,
        kinds: &[&'static str],
        protocol_version: &str,
        pages: super::history_pages::HistoryPages,
        timeout: u64,
        default_wait: u64,
    ) -> Result<Leased> {
        let worker_id = text(body, "worker_id")?;
        let types = self.validation_poller(worker_id, text(body, "task_queue")?).await?;
        if types.is_empty() {
            return Ok(Leased::Workflow(json!({"task":null,"poll_status":"no_workflow_capability"})));
        }
        if !self.worker_validates(worker_id).await? {
            return Err(not_advertised());
        }
        self.poll_task_kinds(body, kinds, protocol_version, pages, timeout, default_wait).await
    }

    /// Whether a claimable validation task waits on this queue.
    async fn validation_waiting(&self, task_queue: &str) -> Result<bool> {
        Ok(Self::query("SELECT id FROM workflow_update_validation_tasks WHERE namespace=$1 AND task_queue=$2 AND (status='pending' OR (status='leased' AND lease_expires_at<=$3)) LIMIT 1")
            .bind(self.namespace.as_ref())
            .bind(task_queue)
            .bind(DB::bind_time(now()))
            .fetch_optional(&self.pool)
            .await?
            .is_some())
    }

    /// `poll`: claim a validation task, waiting up to `timeout` seconds.
    pub(super) async fn poll_update_validation(&self, worker_id: &str, task_queue: &str, timeout: u64) -> Result<Option<String>> {
        let deadline = tokio::time::Instant::now() + Duration::from_secs(timeout);
        let mut pace = super::poll_readiness::ReadPace::new();
        let mut first = true;
        loop {
            let wake = self.wake.notified();
            if (std::mem::take(&mut first) || self.validation_waiting(task_queue).await?)
                && let Some(task_id) = self.claim_update_validation(worker_id, task_queue).await?
            {
                return Ok(Some(task_id));
            }
            if tokio::time::Instant::now() >= deadline {
                return Ok(None);
            }
            pace.wait(wake, deadline).await;
        }
    }

    /// `WorkflowTaskKindSelector`'s cursor: which kind this queue tries
    /// first. A missing cursor starts at `update_validation`; the row stays
    /// locked to the end of the probe, so concurrent probes take turns.
    async fn lock_task_kind_cursor(&self, tx: &mut Transaction<'_, DB>, task_queue: &str) -> Result<String> {
        let at = now();
        Self::query(DB::TASK_KIND_CURSOR_LOCK)
            .bind(self.namespace.as_ref())
            .bind(task_queue)
            .bind(DB::bind_time(at))
            .bind(DB::bind_time(at))
            .execute(&mut **tx)
            .await?;
        let cursor = Self::query("SELECT next_task_kind FROM workflow_task_poll_cursors WHERE namespace=$1 AND task_queue=$2")
            .bind(self.namespace.as_ref())
            .bind(task_queue)
            .fetch_one(&mut **tx)
            .await?;
        DB::string(&cursor, "next_task_kind")
    }

    /// After a lease, the queue tries the other kind first.
    async fn turn_task_kind(&self, tx: &mut Transaction<'_, DB>, task_queue: &str, leased: &str) -> Result<()> {
        let next = if leased == "workflow" { "update_validation" } else { "workflow" };
        Self::query("UPDATE workflow_task_poll_cursors SET next_task_kind=$1,updated_at=$2 WHERE namespace=$3 AND task_queue=$4")
            .bind(next).bind(DB::bind_time(now())).bind(self.namespace.as_ref()).bind(task_queue)
            .execute(&mut **tx)
            .await?;
        Ok(())
    }

    /// A replayed answer as the route delivers it: a validation lease is
    /// rebuilt from its task, any other answer is returned as cached.
    fn replayed(answer: Value, encoding: Option<&'static str>) -> Result<Leased> {
        if answer["task"]["task_kind"] == "update_validation" {
            return Ok(Leased::Validation(text(&answer["task"], "update_validation_task_id")?.to_owned()));
        }
        super::store::replayed_poll(answer, encoding).map(Leased::Workflow)
    }

    /// `WorkflowTaskPoller` with validation tasks requested. A request ID
    /// binds to the normalized kind set and replays its deliverable answer
    /// first. Each probe then runs `WorkflowTaskKindSelector` as one
    /// transaction: replay a duplicate's answer, lock the queue's cursor,
    /// claim the kind it names first and then the other, turn the cursor
    /// after a lease, and record the answer under the request ID. When
    /// neither kind has work, the workflow poll's answer stands, unless a
    /// duplicate leased under the request ID after the last probe: that
    /// lease replays.
    pub(super) async fn poll_task_kinds(
        &self,
        body: &Value,
        kinds: &[&'static str],
        protocol_version: &str,
        pages: super::history_pages::HistoryPages,
        timeout: u64,
        default_wait: u64,
    ) -> Result<Leased> {
        let worker_id = text(body, "worker_id")?.to_owned();
        let task_queue = text(body, "task_queue")?.to_owned();
        let encoding = super::history_pages::requested(body)?;
        let page = pages.poll(super::history_pages::requested_size(body)?, encoding);
        // `WorkerPollFence`, as for a workflow poll.
        let fence = match self.admit_worker_poll(&worker_id, &task_queue).await? {
            super::worker_poll_fence::PollAdmission::Stale => return Ok(Leased::Workflow(super::worker_poll_fence::stale_answer())),
            super::worker_poll_fence::PollAdmission::Fenced(fence) => Some(fence),
            super::worker_poll_fence::PollAdmission::Unregistered => None,
        };
        let request = match super::workflow_poll_requests::request_id(body) {
            Some(id) => Some(self.workflow_poll_request(&worker_id, &task_queue, id, default_wait, kinds).await?),
            None => None,
        };
        if let Some(request) = &request
            && let Some(answer) = self.replay_workflow_poll(request, &worker_id, &task_queue).await?
        {
            return Self::replayed(answer, encoding);
        }
        let deadline = tokio::time::Instant::now() + Duration::from_secs(timeout);
        let mut pace = super::poll_readiness::ReadPace::new();
        loop {
            let wake = self.wake.notified();
            let current = match &fence {
                Some(fence) => self.worker_poll_fence_holds(fence, &worker_id).await?,
                None => true,
            };
            let probed = if current {
                self.probe_task_kinds(body, kinds, protocol_version, page, request.as_ref(), fence.as_ref()).await?
            } else {
                Some(Probe::Stale)
            };
            match probed {
                Some(Probe::Stale) => {
                    if let Some(request) = &request {
                        self.remember_empty_workflow_poll(request, super::worker_poll_fence::STALE).await?;
                    }
                    return Ok(Leased::Workflow(super::worker_poll_fence::stale_answer()));
                }
                Some(Probe::Replayed(answer)) => return Self::replayed(answer, encoding),
                Some(Probe::Leased(Leased::Workflow(mut answer))) => {
                    super::history_pages::compress(&mut answer["task"], encoding)?;
                    return Ok(Leased::Workflow(answer));
                }
                Some(Probe::Leased(validation)) => return Ok(validation),
                None => {}
            }
            if tokio::time::Instant::now() >= deadline {
                if let Some(request) = &request
                    && let Some(lease) = self.remember_empty_workflow_poll(request, &worker_id, &task_queue, "empty").await?
                {
                    return Self::replayed(lease, encoding);
                }
                return Ok(Leased::Workflow(json!({"task":null,"poll_status":"empty","server_capabilities":super::store::capabilities()})));
            }
            pace.wait(wake, deadline).await;
        }
    }

    /// One probe of every requested kind, as one transaction.
    async fn probe_task_kinds(
        &self,
        body: &Value,
        kinds: &[&'static str],
        protocol_version: &str,
        page: Option<i64>,
        request: Option<&WorkflowPollRequest>,
        fence: Option<&super::worker_poll_fence::WorkerPollFence>,
    ) -> Result<Option<Probe>> {
        let worker_id = text(body, "worker_id")?;
        let task_queue = text(body, "task_queue")?;
        let mut tx = self.begin().await?;
        if let Some(fence) = fence
            && !self.worker_poll_fence_holds_in(&mut tx, fence, worker_id).await?
        {
            return Ok(Some(Probe::Stale));
        }
        // A concurrent duplicate may have leased since this poll began: the
        // binding row, locked first, makes it wait for that one's answer.
        if let Some(request) = request {
            Self::bind_workflow_poll(&mut tx, request).await?;
            if let Some(answer) = self.deliverable_workflow_lease(&mut tx, request, worker_id, task_queue).await? {
                tx.commit().await?;
                return Ok(Some(Probe::Replayed(answer)));
            }
        }
        let multiplexed = kinds.len() > 1;
        let first = if multiplexed { self.lock_task_kind_cursor(&mut tx, task_queue).await? } else { kinds[0].to_owned() };
        let mut order = kinds.to_vec();
        order.sort_by_key(|kind| *kind != first);
        for kind in order {
            let leased = if kind == "workflow" {
                self.claim_in(&mut tx, body, "workflow", protocol_version, page, request).await?.map(|task| {
                    Leased::Workflow(json!({"task":task,"poll_status":"leased","server_capabilities":super::store::capabilities()}))
                })
            } else if let Some(claim) = self.claim_update_validation_in(&mut tx, worker_id, task_queue).await? {
                if let Some(request) = request {
                    let delivered = json!({"task_kind":"update_validation","update_validation_task_id":claim.task_id,
                        "update_validation_attempt":claim.attempt,"lease_expires_at":super::cooperative::timestamp(claim.expires)});
                    Self::remember_workflow_poll(&mut tx, request, &json!({"task":delivered,"poll_status":"leased"})).await?;
                }
                Some(Leased::Validation(claim.task_id))
            } else {
                None
            };
            if let Some(leased) = leased {
                if multiplexed {
                    self.turn_task_kind(&mut tx, task_queue, kind).await?;
                }
                tx.commit().await?;
                return Ok(Some(Probe::Leased(leased)));
            }
        }
        tx.commit().await?;
        Ok(None)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn validation_errors_keep_non_empty_string_messages() {
        let errors = validation_errors(&json!({"v":["too small", "", 3],"w":"not a list","x":[]}));
        assert_eq!(Value::Object(errors), json!({"v":["too small"]}));
        let listed = validation_errors(&json!([["first"]]));
        assert_eq!(Value::Object(listed), json!({"0":["first"]}));
    }

    #[test]
    fn broker_failures_mark_only_validator_rejections_rejected() {
        let rejected = broker_failure("w", "r", "u", VALIDATOR_REJECTED, "no", Map::new(), "t", false);
        assert_eq!(rejected["update_status"], "rejected");
        assert_eq!(rejected["outcome"], "rejected_invalid_arguments");
        let failed = broker_failure("w", "r", "u", "validator_error", "no", Map::new(), "t", false);
        assert!(failed.get("update_status").is_none());
        assert_eq!(failed["outcome"], "update_validation_failed");
        assert_eq!(failed["approved"], false);
    }

    #[test]
    fn registrations_serve_only_runs_their_contract_validates() {
        let worker = Registration {
            worker_id: "w".into(),
            fresh: true,
            capable: true,
            workflow_types: vec!["t".into()],
            contracts: json!({"t":{"update_validators":["check"]}}),
            build_id: Some("b1".into()),
            fingerprints: json!({"t":"sha256:f"}),
        };
        assert!(worker.serves("t", "check", None, None));
        assert!(worker.serves("t", "check", Some("b1"), Some("sha256:f")));
        assert!(!worker.serves("t", "other", None, None));
        assert!(!worker.serves("u", "check", None, None));
        assert!(!worker.serves("t", "check", Some("b2"), None));
        assert!(!worker.serves("t", "check", None, Some("sha256:g")));
    }
}
