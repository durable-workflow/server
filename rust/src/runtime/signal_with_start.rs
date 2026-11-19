//! Atomic signal-with-start intake (Server #1133, shared .github#222), the
//! service form of embedded `WorkflowStub::signalWithStart` (Workflow 2.33).
//!
//! One request starts or reuses the instance's run and admits one signal on
//! it in a single transaction. Published PHP Server has no such route; the
//! divergence is registered as `signal-with-start-intake`.
//!
//! - No instance: a new instance and run start under the request's own start
//!   options, then the signal is admitted on that run.
//! - An open current run (pending, running or waiting, including a run that
//!   continued as new): under `duplicate_policy: "use-existing"` (the
//!   default here, as embedded requires it) an accepted start command with
//!   outcome `returned_existing_active` is recorded and the signal joins that
//!   run. Its input, history, deadlines and earlier receipts are unchanged.
//!   Under `fail` the intake is refused as a duplicate.
//! - A closed current run: refused by default (`closed_run_policy:
//!   "reject"`), so a closed execution is never reopened silently. With
//!   `closed_run_policy: "start_new_run"` a fresh run, numbered after the
//!   closed one, starts under the request's own input and timeouts; the closed
//!   run keeps its status, history and outcome.
//!
//! The instance's namespace and durable workflow type, and an explicit task
//! queue on reuse, must match. A body `request_id` is the #934 command key on
//! the signal: a retry with the same intent returns the original pair of
//! receipts with nothing written, before any run gate, and a different
//! intent is a typed conflict. Every refusal, including the signal's own
//! contract and argument refusals, rolls the whole intake back: nothing is
//! started, signalled or reserved.
use super::backend::Backend;
use super::store::{PreparedControlArguments, Store, id};
use super::transaction::Transaction;
use super::{Result, RuntimeError, envelope};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::sync::Arc;
use tokio::sync::Semaphore;

/// The intake mode recorded in both commands' contexts, as Core records it.
pub(super) const MODE: &str = "signal_with_start";

/// The body fields that belong to the signal half or to the intake itself;
/// every other field is the start request's.
const INTAKE_FIELDS: [&str; 3] = ["signal_input", "request_id", "closed_run_policy"];

/// What a closed current run means for the intake.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(super) enum ClosedRunPolicy {
    Reject,
    StartNewRun,
}

/// The request body split into its start and signal halves.
pub(super) struct Intake {
    pub(super) start: Value,
    pub(super) signal: Value,
    pub(super) closed_run_policy: ClosedRunPolicy,
}

impl Intake {
    pub(super) fn split(workflow_id: &str, body: &Value) -> Result<Self> {
        let Some(fields) = body.as_object() else {
            return Err(invalid("body", "The request body must be a JSON object."));
        };
        if let Some(named) = fields.get("workflow_id").filter(|value| !value.is_null())
            && named.as_str() != Some(workflow_id)
        {
            return Err(invalid("workflow_id", "The workflow_id field must match the workflow id in the path."));
        }
        let closed_run_policy = match fields.get("closed_run_policy") {
            None | Some(Value::Null) => ClosedRunPolicy::Reject,
            Some(value) if value == "reject" => ClosedRunPolicy::Reject,
            Some(value) if value == "start_new_run" => ClosedRunPolicy::StartNewRun,
            Some(_) => {
                return Err(invalid("closed_run_policy", "The closed_run_policy field only supports reject or start_new_run."));
            }
        };
        let mut start = fields.clone();
        for field in INTAKE_FIELDS {
            start.remove(field);
        }
        start.insert("workflow_id".into(), json!(workflow_id));
        // Embedded signal-with-start requires return-existing-active; it is
        // the default here, and `fail` stays available for start-only-new.
        if start.get("duplicate_policy").is_none_or(Value::is_null) {
            start.insert("duplicate_policy".into(), json!("use-existing"));
        }
        let mut signal = serde_json::Map::new();
        for (field, target) in [("signal_input", "input"), ("request_id", "request_id")] {
            if let Some(value) = fields.get(field) {
                signal.insert(target.into(), value.clone());
            }
        }
        Ok(Self { start: Value::Object(start), signal: Value::Object(signal), closed_run_policy })
    }
}

fn invalid(field: &str, message: &str) -> RuntimeError {
    super::laravel_validation::failure_messages(vec![(field.to_owned(), vec![message.to_owned()])]).expect("one message")
}

/// The prepared halves of one intake.
pub(super) struct Request<'a> {
    pub(super) workflow_id: &'a str,
    pub(super) name: &'a str,
    pub(super) start: &'a Value,
    pub(super) memo: &'a super::memos::PreparedMemo,
    pub(super) start_external: Option<&'a super::external_resolution::PreparedExternal>,
    pub(super) signal: &'a Value,
    pub(super) signal_external: Option<&'a super::external_resolution::PreparedExternal>,
    pub(super) closed_run_policy: ClosedRunPolicy,
    pub(super) context: &'a Value,
}

/// Another node committed the same instance or key between this intake's
/// reads and its writes.
pub(super) fn lost_race(error: &RuntimeError) -> bool {
    matches!(error, RuntimeError::Database(sqlx::Error::Database(error)) if error.is_unique_violation())
}

/// The command intent a key binds: the operation, signal name and typed
/// arguments. The start half is bound separately by the retained start
/// command's workflow type.
fn digest(name: &str, prepared: &PreparedControlArguments) -> String {
    super::command_intent::digest(MODE, name, &prepared.values, None)
}

fn refused(status: StatusCode, workflow_id: &str, name: &str, reason: &str, outcome: &str, message: String, extra: Value) -> RuntimeError {
    let mut response = json!({"workflow_id":workflow_id,"signal_name":name,"command_status":"rejected",
        "command_source":"control_plane","target_scope":"instance","outcome":outcome,"reason":reason,
        "rejection_reason":reason,"message":message,"accepted":false});
    if let (Some(fields), Some(extra)) = (response.as_object_mut(), extra.as_object()) {
        fields.extend(extra.clone());
    }
    RuntimeError::Protocol { status, response }
}

/// The answer's status: `201` when the intake started a run, `202` when it
/// signalled an existing one. A retained receipt answers as its original did.
fn status_for(start_outcome: &Value) -> StatusCode {
    if start_outcome == "started_new" { StatusCode::CREATED } else { StatusCode::ACCEPTED }
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
    pub(crate) async fn signal_with_start(
        &self,
        request: &Request<'_>,
        prepared: &mut PreparedControlArguments,
        transition_codec: Arc<Semaphore>,
        limits: super::memo_limits::StructuralLimits,
    ) -> Result<(StatusCode, Value)> {
        let (workflow_id, name) = (request.workflow_id, request.name);
        let namespace = self.namespace.clone();
        let mut tx = self.begin().await?;
        // Referenced objects belong to this intake; a refusal rolls the
        // claims back with everything else.
        for prepared in [request.start_external, request.signal_external].into_iter().flatten() {
            self.claim_external_in(&mut tx, &prepared.reference, &prepared.uri).await?;
        }
        let start = super::start_request::validate(request.start)?;
        let search_attributes =
            Self::validate_start_search_attributes(&mut tx, &namespace, &request.start["search_attributes"]).await?;
        let start_arguments = match request.start_external {
            Some(prepared) => prepared.stored(),
            None => envelope(request.start, "input")?,
        };
        let instance = Self::query("SELECT * FROM workflow_instances WHERE id=$1")
            .bind(workflow_id).fetch_optional(&mut *tx).await?;
        if let Some(instance) = &instance
            && DB::optional_string(instance, "namespace")?.as_deref() != Some(namespace.as_ref())
        {
            return Err(refused(StatusCode::CONFLICT, workflow_id, name, "workflow_id_reserved_in_namespace",
                "rejected_workflow_id_reserved_in_namespace",
                format!("Workflow [{workflow_id}] is already reserved in another namespace."), json!({})));
        }
        // Server #934: the signal's key is answered from its receipt before
        // any run gate, including after closure and continuation.
        let key = super::command_intent::caller_key(request.signal);
        let digest = key.map(|_| digest(name, prepared));
        if let (Some(key), Some(digest), Some(_)) = (key, digest.as_deref(), &instance)
            && let Some((command, retained)) = Self::retained_command(&mut tx, workflow_id, "signal", key).await?
        {
            let (status, mut response) =
                Self::retained_intake(&mut tx, workflow_id, name, &start.workflow_type, digest, &command, retained.as_deref()).await?;
            response["namespace"] = json!(namespace.as_ref());
            response["request_id"] = json!(key);
            return Ok((status, response));
        }
        if name == super::message_streams::INTERNAL_SIGNAL {
            return Err(refused(StatusCode::UNPROCESSABLE_ENTITY, workflow_id, name, "runtime_reserved_signal", "rejected",
                "This signal name is reserved for the durable runtime transport.".into(), json!({})));
        }
        let group = id();
        let mut context = request.context.clone();
        context["intake"] = json!({"mode":MODE,"group_id":group});
        let mut closed_run = None;
        let started = match &instance {
            None => self.started_run(&mut tx, request, &start, search_attributes.as_ref(), &start_arguments, &context, None).await?,
            Some(instance) => {
                let reserved = DB::string(instance, "workflow_type")?;
                if reserved != start.workflow_type {
                    return Err(refused(StatusCode::CONFLICT, workflow_id, name, "workflow_type_conflict", "rejected_workflow_type_conflict",
                        format!("Workflow [{workflow_id}] is reserved for workflow type [{reserved}], not [{}].", start.workflow_type),
                        json!({"workflow_type":start.workflow_type,"reserved_workflow_type":reserved})));
                }
                let current = DB::string(instance, "current_run_id")?;
                let run = Self::query("SELECT * FROM workflow_runs WHERE id=$1").bind(current.as_str()).fetch_one(&mut *tx).await?;
                let status = DB::string(&run, "status")?;
                if matches!(status.as_str(), "pending" | "running" | "waiting") {
                    if !start.use_existing {
                        return Err(refused(StatusCode::CONFLICT, workflow_id, name, "instance_already_started", "rejected_duplicate",
                            format!("Workflow [{workflow_id}] already has an open run and duplicate_policy is fail."),
                            json!({"run_id":current,"run_status":status,"start_outcome":"rejected_duplicate"})));
                    }
                    let queue = DB::string(&run, "queue")?;
                    if request.start.get("task_queue").is_some_and(|queue| !queue.is_null()) && queue != start.task_queue {
                        return Err(refused(StatusCode::CONFLICT, workflow_id, name, "task_queue_conflict", "rejected_task_queue_conflict",
                            format!("Workflow [{workflow_id}]'s open run is on task queue [{queue}], not [{}].", start.task_queue),
                            json!({"run_id":current,"task_queue":start.task_queue,"run_task_queue":queue})));
                    }
                    Self::start_existing(&mut tx, &run, &start, workflow_id, &namespace, &start_arguments, Some(&context)).await?
                } else {
                    if request.closed_run_policy == ClosedRunPolicy::Reject {
                        return Err(refused(StatusCode::CONFLICT, workflow_id, name, "run_not_active", "rejected_not_active",
                            format!("Workflow [{workflow_id}]'s current run is {status}. Send closed_run_policy start_new_run to start a fresh run."),
                            json!({"run_id":current,"run_status":status,"closed_run_policy":"reject"})));
                    }
                    let successor = DB::number(instance, "run_count")?.max(DB::number(&run, "run_number")?).max(0) + 1;
                    let started = self.started_run(&mut tx, request, &start, search_attributes.as_ref(), &start_arguments, &context, Some(successor)).await?;
                    // Both runs' summaries follow the instance's new current run, as redrive's do.
                    tx.summary_changed(&current);
                    if let Some(run_id) = started["run_id"].as_str() {
                        tx.summary_changed(run_id);
                    }
                    closed_run = Some(current);
                    started
                }
            }
        };
        let run_id = started["run_id"].as_str().unwrap_or_default().to_owned();
        let start_command = Self::query("SELECT id,command_sequence,outcome FROM workflow_commands WHERE workflow_run_id=$1 AND command_type='start' ORDER BY command_sequence DESC LIMIT 1")
            .bind(run_id.as_str()).fetch_one(&mut *tx).await?;
        let sequence = Self::query("SELECT last_message_sequence FROM workflow_instances WHERE id=$1")
            .bind(workflow_id).fetch_one(&mut *tx).await?;
        let token = key.map(|key| super::command_intent::key_token("signal", key));
        let admitted = self
            .admit_signal_in(&mut tx, workflow_id, &run_id, DB::number(&sequence, "last_message_sequence")?, name, prepared,
                transition_codec, &context, limits, token.as_deref(), digest.as_deref(), false)
            .await?;
        // A refused signal writes nothing and leaves nothing started.
        let mut response = admitted?;
        tx.commit().await?;
        self.wake.notify_waiters();
        let start_outcome = json!(DB::optional_string(&start_command, "outcome")?);
        for (field, value) in [
            ("namespace", json!(namespace.as_ref())),
            ("start_command_id", json!(DB::string(&start_command, "id")?)),
            ("start_command_sequence", json!(DB::optional_number(&start_command, "command_sequence")?)),
            ("start_outcome", start_outcome.clone()),
            ("start_command_status", json!("accepted")),
            ("intake_mode", json!(MODE)),
            ("intake_group_id", json!(group)),
        ] {
            response[field] = value;
        }
        if let Some(closed) = closed_run {
            response["closed_run_id"] = json!(closed);
        }
        if let Some(key) = key {
            response["request_id"] = json!(key);
            response["retained"] = json!(false);
            response["intent_verified"] = json!(true);
        }
        Ok((status_for(&start_outcome), response))
    }

    /// A new run for the intake: the queue drain gate, then the start.
    #[allow(clippy::too_many_arguments)]
    async fn started_run(
        &self,
        tx: &mut Transaction<'static, DB>,
        request: &Request<'_>,
        start: &super::start_request::StartRequest,
        search_attributes: Option<&super::search_attribute_upserts::StartSearchAttributes>,
        arguments: &str,
        context: &Value,
        successor: Option<i64>,
    ) -> Result<Value> {
        let (workflow_id, name) = (request.workflow_id, request.name);
        if let Some(block) = Self::routing_block(tx, &self.namespace, &start.task_queue, self.worker_stale_after_seconds).await? {
            return Err(refused(StatusCode::CONFLICT, workflow_id, name, "task_queue_draining", "rejected_task_queue_draining",
                format!("Task queue [{}] is draining and cannot accept new workflow starts until an active worker cohort is available.", start.task_queue),
                Value::Object(block)));
        }
        Self::seed_run_in(tx, request.start, start, workflow_id, &self.namespace, Some(request.memo), search_attributes,
            arguments, Some(context), self.worker_stale_after_seconds, successor).await
    }

    /// The intake a key already admitted: its signal and start receipts when
    /// the retry's intent matches, a conflict otherwise. Nothing is written.
    async fn retained_intake(
        tx: &mut Transaction<'static, DB>,
        workflow_id: &str,
        name: &str,
        workflow_type: &str,
        digest: &str,
        command: &DB::Row,
        retained: Option<&str>,
    ) -> Result<(StatusCode, Value)> {
        let command_id = DB::string(command, "id")?;
        let outcome = DB::optional_string(command, "outcome")?;
        let conflict = |parts: Vec<&'static str>| {
            super::command_intent::conflict(workflow_id, "signal-with-start", &command_id, outcome.as_deref(), parts)
        };
        let context = DB::optional_document_row(command, "context")?.unwrap_or(Value::Null);
        // A plain signal's key is not a signal-with-start's.
        if context["intake"]["mode"] != MODE {
            return Err(conflict(vec!["operation"]));
        }
        let signal = Self::query("SELECT signal_name FROM workflow_signal_records WHERE workflow_command_id=$1")
            .bind(command_id.as_str()).fetch_optional(&mut **tx).await?;
        let retained_name = signal.as_ref().map(|row| DB::string(row, "signal_name")).transpose()?;
        if retained_name.as_deref() != Some(name) {
            return Err(conflict(vec!["name"]));
        }
        if super::command_intent::compare(retained, digest) != super::command_intent::Intent::Same {
            return Err(conflict(vec!["arguments"]));
        }
        let run_id = DB::string(command, "workflow_run_id")?;
        let sequence = DB::optional_number(command, "command_sequence")?;
        let start = Self::query("SELECT * FROM workflow_commands WHERE workflow_run_id=$1 AND command_type='start' AND command_sequence=$2")
            .bind(run_id.as_str()).bind(sequence.unwrap_or(0) - 1).fetch_optional(&mut **tx).await?;
        let start = match start {
            Some(start)
                if DB::optional_document_row(&start, "context")?.is_some_and(|start| start["intake"] == context["intake"]) => start,
            _ => return Err(super::command_intent::ambiguous_key(workflow_id)),
        };
        if DB::optional_string(&start, "workflow_type")?.as_deref() != Some(workflow_type) {
            return Err(conflict(vec!["workflow_type"]));
        }
        let start_outcome = json!(DB::optional_string(&start, "outcome")?);
        let response = json!({"workflow_id":workflow_id,"run_id":run_id,"resolved_run_id":run_id,"requested_run_id":null,
            "signal_name":name,"command_id":command_id,"command_sequence":sequence,
            "workflow_type":DB::optional_string(command, "workflow_type")?,"command_status":DB::string(command, "status")?,
            "command_source":"control_plane","target_scope":"instance","outcome":outcome,"reason":null,"rejection_reason":null,
            "validation_errors":[],"start_command_id":DB::string(&start, "id")?,
            "start_command_sequence":DB::optional_number(&start, "command_sequence")?,"start_outcome":start_outcome,
            "start_command_status":DB::string(&start, "status")?,"intake_mode":MODE,"intake_group_id":context["intake"]["group_id"],
            "retained":true,"intent_verified":true});
        Ok((status_for(&start_outcome), response))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_body_splits_into_start_and_signal_halves() {
        let body = json!({"workflow_type":"echo","task_queue":"q","input":[1],"signal_input":[2],"request_id":"k",
            "closed_run_policy":"start_new_run","memo":{"a":1}});
        let intake = Intake::split("order-1", &body).unwrap();
        assert_eq!(intake.start, json!({"workflow_id":"order-1","workflow_type":"echo","task_queue":"q","input":[1],
            "memo":{"a":1},"duplicate_policy":"use-existing"}));
        assert_eq!(intake.signal, json!({"input":[2],"request_id":"k"}));
        assert_eq!(intake.closed_run_policy, ClosedRunPolicy::StartNewRun);
        let fail = Intake::split("order-1", &json!({"workflow_type":"echo","duplicate_policy":"fail"})).unwrap();
        assert_eq!((fail.start["duplicate_policy"].clone(), fail.closed_run_policy), (json!("fail"), ClosedRunPolicy::Reject));
        assert!(Intake::split("order-1", &json!({"workflow_type":"echo","workflow_id":"order-2"})).is_err());
        assert!(Intake::split("order-1", &json!({"workflow_type":"echo","workflow_id":"order-1"})).is_ok());
        assert!(Intake::split("order-1", &json!({"workflow_type":"echo","closed_run_policy":"reopen"})).is_err());
        assert!(Intake::split("order-1", &json!([1])).is_err());
    }

    #[test]
    fn the_key_binds_the_operation_name_and_arguments() {
        let values = vec![crate::codec::Value::Long(1)];
        let signal = super::super::command_intent::digest("signal", "changed", &values, None);
        let intake = super::super::command_intent::digest(MODE, "changed", &values, None);
        assert_ne!(signal, intake);
        assert_ne!(intake, super::super::command_intent::digest(MODE, "other", &values, None));
    }
}
