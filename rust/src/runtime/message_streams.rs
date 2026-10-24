//! Message streams, as published Server 2.11.1 serves them
//! (`MessageStreamController`, `MessageStreamService`,
//! `MessageStreamsContract`).
//!
//! A client appends ordered messages to a named stream of a workflow
//! instance. The server assigns each its position and delivers it to the
//! current run as the runtime-reserved `__durable_workflow_message_stream`
//! signal, in the append's transaction. Workers acknowledge what they consumed
//! with `message_stream_cursors` on a workflow task completion, which records
//! `MessageCursorAdvanced`, and report open receives with
//! `message_stream_waits`. When a run continues as new, the next run receives
//! a payload-free cursor checkpoint and every unconsumed message again.
//!
//! An external payload reference as message input is refused for now:
//! published PHP keeps it by reference and resolves it when history crosses
//! to a worker, which native does not do yet.
use super::laravel_rules::{self, Rule};
use super::store::{PreparedControlArguments, SignalWrite, Store, id, now};
use super::transaction::Transaction;
use super::{Result, RuntimeError, backend::Backend};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::collections::BTreeMap;
use std::sync::Arc;
use tokio::sync::Semaphore;

pub(super) const INTERNAL_SIGNAL: &str = "__durable_workflow_message_stream";
const MESSAGE_SCHEMA: &str = "durable-workflow.v2.message-stream.message";
const CURSOR_SCHEMA: &str = "durable-workflow.v2.message-stream.cursor";
pub(super) const MINIMUM_PROTOCOL_VERSION: &str = "1.15";
const MAX_BATCH: i64 = 100;
const NAME_MESSAGE: &str = "must contain 1-128 letters, numbers, periods, underscores, colons, or hyphens.";

/// `MessageStreamsContract::manifest`.
pub(super) fn manifest() -> Value {
    json!({
        "schema": "durable-workflow.v2.message-streams.contract",
        "version": 1,
        "capability_flag": "message_streams",
        "minimum_worker_protocol_version": MINIMUM_PROTOCOL_VERSION,
        "scope": "workflow_instance",
        "transport": {
            "append_path": "/api/workflows/{workflow_id}/message-streams/{stream_name}/messages",
            "diagnostics_path": "/api/workflows/{workflow_id}/message-streams",
            "internal_signal": INTERNAL_SIGNAL,
            "message_schema": MESSAGE_SCHEMA,
            "cursor_schema": CURSOR_SCHEMA,
            "payload_envelope": "inline_avro_at_the_server_to_worker_delivery_boundary",
            "durable_payload": "exact_inline_avro_bytes_or_the_verified_external_storage_reference",
            "payload_visibility": "payload_is_present_only_in_durable_signal_history_and_is_omitted_from_stream_diagnostics",
        },
        "identity": {
            "producer_key": "message_id",
            "order_key": "position",
            "first_position": 1,
            "duplicate_same_payload": "return_existing_position_without_redelivery",
            "duplicate_different_payload": "reject_message_identity_conflict",
        },
        "cursor": {
            "ownership": "server",
            "worker_completion_field": "message_stream_cursors",
            "monotonic": true,
            "advancement": "highest_contiguous_consumed_position",
            "history_event": "MessageCursorAdvanced",
            "continue_as_new": "a_payload_free_cursor_checkpoint_and_unconsumed_messages_are_delivered_to_the_current_run",
        },
        "waiting": {
            "worker_completion_field": "message_stream_waits",
            "durable_wakeup": "runtime_reserved_signal_task_resume",
            "pending_wait_is_diagnostic": true,
        },
        "batch": {
            "minimum": 1,
            "maximum": MAX_BATCH,
            "waits_for": "first_message",
            "returns": "up_to_maximum_currently_available_messages",
        },
        "replay": {
            "ordering_source": "server_assigned_position",
            "deduplication_source": "message_id_and_instance_cursor",
            "worker_replacement": "history_replay_reconstructs_pending_messages_and_cursor_acknowledgement_is_idempotent",
            "server_restart": "stream_items_cursor_and_wait_state_are_database_durable",
            "external_payloads": "references_remain_reference_backed_at_rest_and_are_resolved_for_worker_delivery_then_removed_with_namespace_retention",
        },
        "sdk_handoffs": {
            "php": "WorkflowContext::messageStream(string)->receive(int)",
            "python": "WorkflowContext.message_stream(str).receive(max_items)",
            "rust": "WorkflowContext::message_stream(name).receive(max_items).await",
        },
        "conformance": {
            "runtimes": ["php", "python", "rust"],
            "targets": ["server", "cloud"],
            "required_scenarios": [
                "repeated_messages_are_observed_in_position_order_exactly_once",
                "duplicate_delivery_preserves_one_logical_message",
                "worker_is_replaced_mid_stream",
                "continue_as_new_retains_cursor_and_pending_messages",
                "server_restart_retains_cursor_wait_and_order",
            ],
            "artifacts": "published",
        },
    })
}

/// The worker capability: the manifest, supported.
pub(super) fn worker_capability() -> Value {
    let mut capability = manifest();
    capability["supported"] = json!(true);
    capability
}

fn identifier(value: &str) -> bool {
    !value.is_empty() && value.bytes().all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'.' | b'_' | b':' | b'-'))
}

/// `/^[A-Za-z0-9._:-]{1,128}$/`.
pub(super) fn valid_name(name: &str) -> bool {
    name.len() <= 128 && identifier(name)
}

fn invalid(field: &str, message: String) -> RuntimeError {
    laravel_rules::failure(vec![(field.to_owned(), vec![message])]).expect("one message")
}

/// `validateStreamName`: a path stream name outside the pattern.
pub(super) fn check_path_name(name: &str) -> Result<()> {
    if valid_name(name) {
        Ok(())
    } else {
        Err(invalid("stream_name", format!("The stream name {NAME_MESSAGE}")))
    }
}

/// The append body's rules, after Laravel's normalization.
const APPEND_RULES: &laravel_rules::Rules = &[
    ("message_id", &[Rule::Required, Rule::String, Rule::Max(191), Rule::Pattern(identifier)]),
    ("input", &[Rule::Nullable, Rule::Array]),
    ("request_id", &[Rule::Nullable, Rule::String, Rule::Max(255)]),
];

pub(super) fn check_append(body: &Value) -> Result<()> {
    laravel_rules::validate(body, APPEND_RULES)
}

/// `diagnosticMessageId`: a refused message id as diagnostics keep it.
pub(super) fn diagnostic_message_id(value: &Value) -> String {
    match value.as_str() {
        None => "[omitted]".into(),
        Some(id) if id.len() <= 191 && identifier(id) => id.into(),
        Some(id) => {
            use sha2::Digest;
            format!("sha256:{}", hex(sha2::Sha256::digest(id.as_bytes())))
        }
    }
}

/// The payload hash PHP stores: sha256 of `codec \0 blob`.
pub(super) fn payload_hash(codec: &str, blob: &str) -> String {
    use sha2::Digest;
    let mut digest = sha2::Sha256::new();
    digest.update(codec.as_bytes());
    digest.update([0]);
    digest.update(blob.as_bytes());
    hex(digest.finalize())
}

fn hex(digest: impl AsRef<[u8]>) -> String {
    digest.as_ref().iter().map(|byte| format!("{byte:02x}")).collect()
}

/// The worker protocol's message-stream fields on a completion, checked as
/// PHP's first `validate` call does, before the completion's own rules.
const COMPLETION_RULES: &laravel_rules::Rules = &[
    ("message_stream_cursors", &[Rule::Nullable, Rule::Array, Rule::Max(100)]),
    ("message_stream_cursors.*.stream_name", &[Rule::Required, Rule::String, Rule::Max(128)]),
    ("message_stream_cursors.*.through_position", &[Rule::Required, Rule::Integer, Rule::Min(0)]),
    ("message_stream_waits", &[Rule::Nullable, Rule::Array, Rule::Max(100)]),
    ("message_stream_waits.*.stream_name", &[Rule::Required, Rule::String, Rule::Max(128)]),
    ("message_stream_waits.*.after_position", &[Rule::Required, Rule::Integer, Rule::Min(0)]),
];

pub(super) fn check_completion_fields(body: &Value) -> Result<()> {
    laravel_rules::validate(&laravel_rules::normalize(body), COMPLETION_RULES)
}

/// A completion's cursor advances and pending waits, in request order.
pub(super) struct Completion {
    pub(super) cursors: Vec<Value>,
    pub(super) waits: Vec<Value>,
}

impl Completion {
    pub(super) fn of(body: &Value) -> Self {
        let list = |field: &str| match &body[field] {
            Value::Array(items) => items.clone(),
            Value::Object(items) => items.values().cloned().collect(),
            _ => Vec::new(),
        };
        Self { cursors: list("message_stream_cursors"), waits: list("message_stream_waits") }
    }

    pub(super) fn is_empty(&self) -> bool {
        self.cursors.is_empty() && self.waits.is_empty()
    }
}

/// `metadataStreamName`.
fn metadata_name(item: &Value, field: &str) -> Result<String> {
    match item["stream_name"].as_str() {
        Some(name) if valid_name(name) => Ok(name.to_owned()),
        _ => Err(invalid(field, format!("The stream_name {NAME_MESSAGE}"))),
    }
}

fn non_negative(value: &Value) -> Option<i64> {
    value.as_i64().filter(|value| *value >= 0)
}

/// The rejection PHP's service answers for an append it cannot accept.
fn rejection(status: StatusCode, reason: &str, workflow_id: &str, stream: &str, message_id: &str, extra: Value) -> RuntimeError {
    let mut response = json!({"accepted":false,"duplicate":false,"workflow_id":workflow_id,"stream_name":stream,
        "message_id":message_id,"position":null,"outcome":"rejected","reason":reason});
    if let Value::Object(extra) = extra {
        response.as_object_mut().expect("object").extend(extra);
    }
    RuntimeError::Protocol { status, response }
}

/// The value a stream delivery signal carries: one map in a list.
fn map(entries: Vec<(&str, crate::codec::Value)>) -> crate::codec::Value {
    crate::codec::Value::Map(entries.into_iter().map(|(key, value)| (key.to_owned(), value)).collect::<BTreeMap<_, _>>())
}

/// A runtime signal's encoded arguments and command payload.
async fn runtime_arguments(codec: Arc<Semaphore>, delivery: crate::codec::Value) -> Result<PreparedControlArguments> {
    let values = vec![delivery];
    let arguments = super::queries::encode_arguments(codec.clone(), crate::codec::Value::Array(values.clone())).await?;
    let command = super::queries::encode_arguments(
        codec.clone(),
        map(vec![
            ("name", crate::codec::Value::String(INTERNAL_SIGNAL.into())),
            ("arguments", crate::codec::Value::Array(values.clone())),
            ("validation_errors", crate::codec::Value::Array(vec![])),
        ]),
    )
    .await?;
    let value = super::queries::encode_arguments(codec, values[0].clone()).await?;
    Ok(PreparedControlArguments { arguments, values, value, command })
}

/// `CommandContext::controlPlane`: the context of a delivery with no request.
fn control_plane_context() -> Value {
    json!({"caller":{"type":"control_plane","label":"Control Plane"},"auth":{"status":"not_applicable","method":"none"}})
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
    /// `NamespaceWorkflowScope::workflowBound`.
    pub(crate) async fn message_stream_workflow_bound(&self, workflow_id: &str) -> Result<bool> {
        Ok(Self::query("SELECT id FROM workflow_instances WHERE id=$1 AND namespace=$2")
            .bind(workflow_id)
            .bind(self.namespace.as_ref())
            .fetch_optional(&self.pool)
            .await?
            .is_some())
    }

    /// The stream row, created at position zero when absent.
    async fn locked_stream(&self, tx: &mut Transaction<'_, DB>, workflow_id: &str, stream: &str) -> Result<DB::Row> {
        const SELECT: &str = "SELECT * FROM workflow_inbound_streams WHERE namespace=$1 AND workflow_instance_id=$2 AND stream_name=$3";
        if let Some(row) = Self::query(SELECT)
            .bind(self.namespace.as_ref())
            .bind(workflow_id)
            .bind(stream)
            .fetch_optional(&mut **tx)
            .await?
        {
            return Ok(row);
        }
        let at = now();
        Self::query("INSERT INTO workflow_inbound_streams(namespace,workflow_instance_id,stream_name,last_position,cursor_position,duplicate_count,malformed_count,created_at,updated_at) VALUES ($1,$2,$3,0,0,0,0,$4,$5)")
            .bind(self.namespace.as_ref()).bind(workflow_id).bind(stream)
            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).execute(&mut **tx).await?;
        Ok(Self::query(SELECT).bind(self.namespace.as_ref()).bind(workflow_id).bind(stream).fetch_one(&mut **tx).await?)
    }

    /// The instance's current run, if it is still open.
    async fn active_current_run(tx: &mut Transaction<'_, DB>, workflow_id: &str) -> Result<Option<DB::Row>> {
        let Some(run) = Self::query("SELECT r.* FROM workflow_instances i JOIN workflow_runs r ON r.id=i.current_run_id WHERE i.id=$1")
            .bind(workflow_id)
            .fetch_optional(&mut **tx)
            .await?
        else {
            return Ok(None);
        };
        let open = matches!(DB::string(&run, "status")?.as_str(), "pending" | "running" | "waiting");
        Ok(open.then_some(run))
    }

    /// `MessageStreamService::append`, in one transaction.
    #[allow(clippy::too_many_arguments)]
    pub(crate) async fn append_message(
        &self,
        workflow_id: &str,
        stream: &str,
        message_id: &str,
        payload_codec: &str,
        payload_blob: &str,
        context: &Value,
        codec: Arc<Semaphore>,
        limits: super::memo_limits::StructuralLimits,
    ) -> Result<(StatusCode, Value)> {
        let mut tx = self.begin().await?;
        let bound = Self::query("SELECT id FROM workflow_instances WHERE id=$1 AND namespace=$2")
            .bind(workflow_id).bind(self.namespace.as_ref()).fetch_optional(&mut *tx).await?;
        if bound.is_none() {
            return Err(rejection(StatusCode::NOT_FOUND, "instance_not_found", workflow_id, stream, message_id, json!({})));
        }
        let Some(run) = Self::active_current_run(&mut tx, workflow_id).await? else {
            return Err(rejection(StatusCode::CONFLICT, "run_not_active", workflow_id, stream, message_id, json!({})));
        };
        let row = self.locked_stream(&mut tx, workflow_id, stream).await?;
        let stream_id = DB::number(&row, "id")?;
        let cursor = DB::number(&row, "cursor_position")?;
        let hash = payload_hash(payload_codec, payload_blob);
        let at = now();
        if let Some(existing) = Self::query("SELECT position,payload_hash FROM workflow_inbound_stream_items WHERE stream_id=$1 AND message_id=$2")
            .bind(stream_id).bind(message_id).fetch_optional(&mut *tx).await?
        {
            let same = DB::string(&existing, "payload_hash")? == hash;
            let outcome = if same { "duplicate" } else { "message_identity_conflict" };
            Self::query("UPDATE workflow_inbound_streams SET duplicate_count=duplicate_count+1,last_input_outcome=$1,last_input_message_id=$2,last_input_at=$3,updated_at=$4 WHERE id=$5")
                .bind(outcome).bind(message_id).bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(stream_id)
                .execute(&mut *tx).await?;
            tx.commit().await?;
            let response = json!({"accepted":same,"duplicate":same,"workflow_id":workflow_id,"stream_name":stream,
                "message_id":message_id,"position":DB::number(&existing,"position")?,"cursor_position":cursor,
                "outcome":if same { "duplicate" } else { "rejected" },"reason":if same { Value::Null } else { json!("message_identity_conflict") }});
            return Ok((if same { StatusCode::OK } else { StatusCode::CONFLICT }, response));
        }
        let position = DB::number(&row, "last_position")? + 1;
        Self::query("INSERT INTO workflow_inbound_stream_items(stream_id,namespace,workflow_instance_id,stream_name,message_id,position,payload_codec,payload_blob,payload_hash,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11)")
            .bind(stream_id).bind(self.namespace.as_ref()).bind(workflow_id).bind(stream).bind(message_id).bind(position)
            .bind(payload_codec).bind(payload_blob).bind(&hash).bind(DB::bind_time(at)).bind(DB::bind_time(at))
            .execute(&mut *tx).await?;
        Self::query("UPDATE workflow_inbound_streams SET last_position=$1,waiting_run_id=NULL,waiting_after_position=NULL,waiting_since=NULL,last_input_outcome='accepted',last_input_message_id=$2,last_input_at=$3,updated_at=$4 WHERE id=$5")
            .bind(position).bind(message_id).bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(stream_id)
            .execute(&mut *tx).await?;
        let delivery = Self::message_delivery(stream, message_id, position, payload_codec, payload_blob);
        let run_id = DB::string(&run, "id")?;
        match Self::deliver_runtime_signal(&mut tx, workflow_id, delivery, context, codec, limits).await? {
            Ok(command_id) => {
                Self::mark_delivered(&mut tx, stream_id, message_id, &run_id, &command_id).await?;
            }
            Err(reason) => {
                Self::query("DELETE FROM workflow_inbound_stream_items WHERE stream_id=$1 AND message_id=$2")
                    .bind(stream_id).bind(message_id).execute(&mut *tx).await?;
                Self::query("UPDATE workflow_inbound_streams SET last_position=$1,last_input_outcome='message_stream_not_supported' WHERE id=$2")
                    .bind(position - 1).bind(stream_id).execute(&mut *tx).await?;
                tx.commit().await?;
                self.wake.notify_waiters();
                return Err(rejection(StatusCode::CONFLICT, "message_stream_not_supported", workflow_id, stream, message_id,
                    json!({"detail":reason,"remediation":"Use a PHP, Python, or Rust worker that advertises the message_streams capability."})));
            }
        }
        tx.summary_changed(&run_id);
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok((StatusCode::ACCEPTED, json!({"accepted":true,"duplicate":false,"workflow_id":workflow_id,"run_id":run_id,
            "stream_name":stream,"message_id":message_id,"position":position,"cursor_position":cursor,
            "outcome":"accepted","reason":null})))
    }

    /// `MessageStreamService::recordMalformed`: counts a refused append
    /// against the stream of an open run, keeping no payload.
    pub(crate) async fn record_malformed_message(&self, workflow_id: &str, stream: &str, message_id: &Value) -> Result<()> {
        let mut tx = self.begin().await?;
        let bound = Self::query("SELECT id FROM workflow_instances WHERE id=$1 AND namespace=$2")
            .bind(workflow_id).bind(self.namespace.as_ref()).fetch_optional(&mut *tx).await?;
        if bound.is_none() || Self::active_current_run(&mut tx, workflow_id).await?.is_none() {
            return Ok(());
        }
        let row = self.locked_stream(&mut tx, workflow_id, stream).await?;
        let at = now();
        Self::query("UPDATE workflow_inbound_streams SET malformed_count=malformed_count+1,last_input_outcome='malformed',last_input_message_id=$1,last_input_at=$2,updated_at=$3 WHERE id=$4")
            .bind(diagnostic_message_id(message_id)).bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(DB::number(&row, "id")?)
            .execute(&mut *tx).await?;
        tx.commit().await?;
        Ok(())
    }

    /// `MessageStreamService::diagnostics`: every stream of the instance, or
    /// the named one, by name, without payloads.
    pub(crate) async fn message_stream_diagnostics(&self, workflow_id: &str, stream: Option<&str>) -> Result<Vec<Value>> {
        let rows = match stream {
            Some(stream) => Self::query("SELECT * FROM workflow_inbound_streams WHERE namespace=$1 AND workflow_instance_id=$2 AND stream_name=$3 ORDER BY stream_name")
                .bind(self.namespace.as_ref()).bind(workflow_id).bind(stream).fetch_all(&self.pool).await?,
            None => Self::query("SELECT * FROM workflow_inbound_streams WHERE namespace=$1 AND workflow_instance_id=$2 ORDER BY stream_name")
                .bind(self.namespace.as_ref()).bind(workflow_id).fetch_all(&self.pool).await?,
        };
        let time = |row: &DB::Row, field: &str| -> Result<Value> {
            Ok(super::task_queues::json_time(DB::optional_instant(row, field)?))
        };
        rows.iter()
            .map(|row| {
                let last = DB::number(row, "last_position")?;
                let cursor = DB::number(row, "cursor_position")?;
                let wait = match DB::optional_string(row, "waiting_run_id")? {
                    Some(run_id) => json!({"run_id":run_id,"after_position":DB::optional_number(row,"waiting_after_position")?,
                        "since":time(row,"waiting_since")?}),
                    None => Value::Null,
                };
                Ok(json!({"stream_name":DB::string(row,"stream_name")?,"last_position":last,"cursor_position":cursor,
                    "pending_count":(last - cursor).max(0),"pending_wait":wait,
                    "duplicate_count":DB::number(row,"duplicate_count")?,"malformed_count":DB::number(row,"malformed_count")?,
                    "last_input_outcome":DB::optional_string(row,"last_input_outcome")?,
                    "last_input_message_id":DB::optional_string(row,"last_input_message_id")?,
                    "last_input_at":time(row,"last_input_at")?}))
            })
            .collect()
    }

    /// `MessageStreamService::validateCompletion`: the worker's cursor
    /// advances and waits, checked before the completion changes anything.
    pub(super) async fn validate_stream_completion(
        &self,
        tx: &mut Transaction<'_, DB>,
        instance_id: &str,
        run_id: &str,
        completion: &Completion,
    ) -> Result<()> {
        let mut proposed = BTreeMap::new();
        for (index, cursor) in completion.cursors.iter().enumerate() {
            let field = |name: &str| format!("message_stream_cursors.{index}.{name}");
            let stream = metadata_name(cursor, &field("stream_name"))?;
            if proposed.contains_key(&stream) {
                return Err(invalid(&field("stream_name"), "A stream may be acknowledged only once per workflow task completion.".into()));
            }
            let Some(through) = non_negative(&cursor["through_position"]) else {
                return Err(invalid(&field("through_position"), "The through_position must be a non-negative integer.".into()));
            };
            let row = Self::query("SELECT id,last_position,cursor_position FROM workflow_inbound_streams WHERE namespace=$1 AND workflow_instance_id=$2 AND stream_name=$3")
                .bind(self.namespace.as_ref()).bind(instance_id).bind(&stream).fetch_optional(&mut **tx).await?;
            let Some(row) = row.filter(|row| DB::number(row, "last_position").is_ok_and(|last| through <= last)) else {
                return Err(invalid(&field("through_position"), "The cursor cannot advance beyond the last accepted stream position.".into()));
            };
            let current = DB::number(&row, "cursor_position")?;
            if through > current {
                let delivered = Self::scalar("SELECT COUNT(*) FROM workflow_inbound_stream_items WHERE stream_id=$1 AND position>$2 AND position<=$3 AND delivered_run_id=$4")
                    .bind(DB::number(&row, "id")?).bind(current).bind(through).bind(run_id).fetch_one(&mut **tx).await?;
                if delivered != through - current {
                    return Err(invalid(&field("through_position"), "The cursor must advance contiguously through messages delivered to this workflow run.".into()));
                }
            }
            proposed.insert(stream, current.max(through));
        }
        let mut waiting = std::collections::BTreeSet::new();
        for (index, wait) in completion.waits.iter().enumerate() {
            let field = |name: &str| format!("message_stream_waits.{index}.{name}");
            let stream = metadata_name(wait, &field("stream_name"))?;
            if !waiting.insert(stream.clone()) {
                return Err(invalid(&field("stream_name"), "A stream may have only one pending wait per workflow task completion.".into()));
            }
            let Some(after) = non_negative(&wait["after_position"]) else {
                return Err(invalid(&field("after_position"), "The after_position must be a non-negative integer.".into()));
            };
            let expected = match proposed.get(&stream) {
                Some(cursor) => *cursor,
                None => Self::query("SELECT cursor_position FROM workflow_inbound_streams WHERE namespace=$1 AND workflow_instance_id=$2 AND stream_name=$3")
                    .bind(self.namespace.as_ref()).bind(instance_id).bind(&stream).fetch_optional(&mut **tx).await?
                    .map(|row| DB::number(&row, "cursor_position")).transpose()?.unwrap_or(0),
            };
            if after != expected {
                return Err(invalid(&field("after_position"), "The pending wait must start after the durable stream cursor.".into()));
            }
        }
        Ok(())
    }

    /// `MessageStreamService::recordCompletion`, after the completion has
    /// been applied in the same transaction: consumed messages, each moved
    /// cursor with `MessageCursorAdvanced`, pending waits, then the hand-over
    /// to a run that continued this one.
    pub(super) async fn record_stream_completion(
        &self,
        tx: &mut Transaction<'_, DB>,
        instance_id: &str,
        run_id: &str,
        task_id: &str,
        completion: &Completion,
        codec: Arc<Semaphore>,
        limits: super::memo_limits::StructuralLimits,
    ) -> Result<()> {
        for cursor in &completion.cursors {
            let stream = cursor["stream_name"].as_str().unwrap_or_default();
            let through = cursor["through_position"].as_i64().unwrap_or(0);
            let row = self.locked_stream(tx, instance_id, stream).await?;
            let previous = DB::number(&row, "cursor_position")?;
            if through <= previous {
                continue;
            }
            let at = now();
            Self::query("UPDATE workflow_inbound_stream_items SET consumed_run_id=$1,consumed_task_id=$2,consumed_at=$3,updated_at=$4 WHERE stream_id=$5 AND position>$6 AND position<=$7")
                .bind(run_id).bind(task_id).bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(DB::number(&row, "id")?)
                .bind(previous).bind(through).execute(&mut **tx).await?;
            Self::query("UPDATE workflow_inbound_streams SET cursor_position=$1,waiting_run_id=NULL,waiting_after_position=NULL,waiting_since=NULL,updated_at=$2 WHERE id=$3")
                .bind(through).bind(DB::bind_time(at)).bind(DB::number(&row, "id")?).execute(&mut **tx).await?;
            let task = Self::query("SELECT * FROM workflow_tasks WHERE id=$1").bind(task_id).fetch_one(&mut **tx).await?;
            Self::append(tx, run_id, "MessageCursorAdvanced", json!({"stream_key":stream,"previous_position":previous,
                "new_position":through,"task":Self::task_snapshot(&task)?}), Some(task_id), None).await?;
        }
        for wait in &completion.waits {
            let stream = wait["stream_name"].as_str().unwrap_or_default();
            let after = wait["after_position"].as_i64().unwrap_or(0);
            let row = self.locked_stream(tx, instance_id, stream).await?;
            if DB::number(&row, "last_position")? > after {
                continue;
            }
            let at = now();
            Self::query("UPDATE workflow_inbound_streams SET waiting_run_id=$1,waiting_after_position=$2,waiting_since=$3,updated_at=$4 WHERE id=$5")
                .bind(run_id).bind(after).bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(DB::number(&row, "id")?)
                .execute(&mut **tx).await?;
        }
        let current = Self::query("SELECT current_run_id FROM workflow_instances WHERE id=$1")
            .bind(instance_id).fetch_one(&mut **tx).await?;
        let current = DB::optional_string(&current, "current_run_id")?;
        if let Some(current) = current.filter(|current| current != run_id) {
            self.carry_streams_forward(tx, instance_id, &current, codec, limits).await?;
        }
        Ok(())
    }

    /// `carryForward`: a cursor checkpoint for each acknowledged stream the
    /// current run has not received, then every unconsumed message the
    /// current run has not received, by append order.
    async fn carry_streams_forward(
        &self,
        tx: &mut Transaction<'_, DB>,
        instance_id: &str,
        current: &str,
        codec: Arc<Semaphore>,
        limits: super::memo_limits::StructuralLimits,
    ) -> Result<()> {
        let context = control_plane_context();
        let streams = Self::query("SELECT id,stream_name,cursor_position FROM workflow_inbound_streams WHERE namespace=$1 AND workflow_instance_id=$2 AND cursor_position>0 AND (cursor_checkpoint_run_id IS NULL OR cursor_checkpoint_run_id<>$3) ORDER BY stream_name")
            .bind(self.namespace.as_ref()).bind(instance_id).bind(current).fetch_all(&mut **tx).await?;
        for stream in streams {
            let checkpoint = map(vec![
                ("schema", crate::codec::Value::String(CURSOR_SCHEMA.into())),
                ("stream_name", crate::codec::Value::String(DB::string(&stream, "stream_name")?)),
                ("through_position", crate::codec::Value::Long(DB::number(&stream, "cursor_position")?)),
            ]);
            if Self::deliver_runtime_signal(tx, instance_id, checkpoint, &context, codec.clone(), limits).await?.is_err() {
                return Err(RuntimeError::Refused { status: StatusCode::INTERNAL_SERVER_ERROR, reason: "message_stream_cursor_carry_failed" });
            }
            Self::query("UPDATE workflow_inbound_streams SET cursor_checkpoint_run_id=$1 WHERE id=$2")
                .bind(current).bind(DB::number(&stream, "id")?).execute(&mut **tx).await?;
        }
        loop {
            let items = Self::query("SELECT stream_id,stream_name,message_id,position,payload_codec,payload_blob FROM workflow_inbound_stream_items WHERE namespace=$1 AND workflow_instance_id=$2 AND consumed_at IS NULL AND (delivered_run_id IS NULL OR delivered_run_id<>$3) ORDER BY id LIMIT 100")
                .bind(self.namespace.as_ref()).bind(instance_id).bind(current).fetch_all(&mut **tx).await?;
            if items.is_empty() {
                return Ok(());
            }
            for item in items {
                let (stream, message_id) = (DB::string(&item, "stream_name")?, DB::string(&item, "message_id")?);
                let delivery = Self::message_delivery(&stream, &message_id, DB::number(&item, "position")?,
                    &DB::string(&item, "payload_codec")?, &DB::string(&item, "payload_blob")?);
                match Self::deliver_runtime_signal(tx, instance_id, delivery, &context, codec.clone(), limits).await? {
                    Ok(command_id) => {
                        Self::mark_delivered(tx, DB::number(&item, "stream_id")?, &message_id, current, &command_id).await?;
                    }
                    Err(_) => {
                        return Err(RuntimeError::Refused { status: StatusCode::INTERNAL_SERVER_ERROR, reason: "message_stream_carry_failed" });
                    }
                }
            }
        }
    }

    /// The message a stream delivers: its schema, identity, position and the
    /// inline payload envelope.
    fn message_delivery(stream: &str, message_id: &str, position: i64, payload_codec: &str, payload_blob: &str) -> crate::codec::Value {
        use crate::codec::Value as Payload;
        map(vec![
            ("schema", Payload::String(MESSAGE_SCHEMA.into())),
            ("stream_name", Payload::String(stream.into())),
            ("message_id", Payload::String(message_id.into())),
            ("position", Payload::Long(position)),
            ("payload_envelope", map(vec![
                ("codec", Payload::String(payload_codec.into())),
                ("blob", Payload::String(payload_blob.into())),
            ])),
        ])
    }

    async fn mark_delivered(tx: &mut Transaction<'_, DB>, stream_id: i64, message_id: &str, run_id: &str, command_id: &str) -> Result<()> {
        let at = now();
        Self::query("UPDATE workflow_inbound_stream_items SET delivered_run_id=$1,workflow_command_id=$2,delivered_at=$3,updated_at=$4 WHERE stream_id=$5 AND message_id=$6")
            .bind(run_id).bind(command_id).bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(stream_id).bind(message_id)
            .execute(&mut **tx).await?;
        Ok(())
    }

    /// `WorkflowStub::attemptRuntimeSignalWithArguments` for the instance's
    /// current run: no declared-signal admission or argument contract, but
    /// the pending-signal and payload ceilings still apply. The accepted
    /// command's id, or the refusal's reason.
    async fn deliver_runtime_signal(
        tx: &mut Transaction<'_, DB>,
        workflow_id: &str,
        delivery: crate::codec::Value,
        context: &Value,
        codec: Arc<Semaphore>,
        limits: super::memo_limits::StructuralLimits,
    ) -> Result<std::result::Result<String, &'static str>> {
        let instance = Self::query("SELECT current_run_id,last_message_sequence FROM workflow_instances WHERE id=$1")
            .bind(workflow_id).fetch_one(&mut **tx).await?;
        let run_id = DB::string(&instance, "current_run_id")?;
        let run = Self::query("SELECT * FROM workflow_runs WHERE id=$1").bind(&run_id).fetch_one(&mut **tx).await?;
        if !matches!(DB::string(&run, "status")?.as_str(), "pending" | "running" | "waiting") {
            return Ok(Err("run_not_active"));
        }
        let prepared = runtime_arguments(codec.clone(), delivery).await?;
        let command_id = id();
        let signal_id = id();
        let command_sequence = DB::number(&run, "last_command_sequence")? + 1;
        let message_sequence = DB::number(&instance, "last_message_sequence")? + 1;
        let workflow_type = DB::string(&run, "workflow_type")?;
        let write = SignalWrite { workflow_id, run_id: &run_id, workflow_type: &workflow_type, name: INTERNAL_SIGNAL,
            command_id: &command_id, signal_id: &signal_id, command_sequence, message_sequence, context };
        let pending = Self::scalar("SELECT COUNT(*) FROM workflow_signal_records WHERE workflow_run_id=$1 AND status='received'")
            .bind(&run_id).fetch_one(&mut **tx).await?;
        let exceeded = super::memo_limits::Exceeded::pending_signals(limits.pending_signals, pending)
            .or_else(|| super::memo_limits::Exceeded::payload(limits.payload_bytes, &prepared.arguments));
        if let Some(exceeded) = exceeded {
            let oversized = exceeded.kind == "payload_size";
            let (values, errors, diagnostics) = if oversized {
                (vec![], json!({"arguments":[exceeded.message]}), exceeded.fields())
            } else {
                (prepared.values.clone(), json!([]), json!({}))
            };
            let command = super::store::prepare_rejected_command(codec.clone(), INTERNAL_SIGNAL.into(), values,
                errors.clone(), diagnostics, "signal_codec_unavailable").await?;
            let arguments = if oversized { super::queries::empty_arguments(codec).await? } else { prepared.arguments.clone() };
            let row_errors = if oversized { errors } else { json!([]) };
            Self::record_rejected_signal(tx, &write, None, "structural_limit_exceeded", &command, &arguments, &row_errors).await?;
            return Ok(Err("structural_limit_exceeded"));
        }
        Self::record_accepted_signal(tx, &write, &prepared.command, &prepared.arguments, &DB::string(&run, "queue")?).await?;
        tx.summary_changed(&run_id);
        Ok(Ok(command_id))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn names_follow_phps_pattern() {
        assert!(valid_name("orders"));
        assert!(valid_name("a.b_c:d-e"));
        assert!(valid_name(&"x".repeat(128)));
        assert!(!valid_name(&"x".repeat(129)));
        assert!(!valid_name(""));
        assert!(!valid_name("bad name"));
        assert!(!valid_name("bad/name"));
    }

    #[test]
    fn refused_message_ids_are_bounded_for_diagnostics() {
        assert_eq!(diagnostic_message_id(&Value::Null), "[omitted]");
        assert_eq!(diagnostic_message_id(&json!(7)), "[omitted]");
        assert_eq!(diagnostic_message_id(&json!("m3")), "m3");
        let long = "a".repeat(192);
        assert_eq!(diagnostic_message_id(&json!(long)).len(), "sha256:".len() + 64);
        assert!(diagnostic_message_id(&json!("bad id!")).starts_with("sha256:"));
    }

    #[test]
    fn payload_hash_covers_codec_and_blob() {
        assert_ne!(payload_hash("avro", "a"), payload_hash("avro", "b"));
        assert_ne!(payload_hash("avro", "ab"), payload_hash("avroa", "b"));
        assert_eq!(payload_hash("avro", "x").len(), 64);
    }

    #[test]
    fn the_worker_capability_is_the_supported_manifest() {
        let capability = worker_capability();
        assert_eq!(capability["supported"], true);
        assert_eq!(capability["minimum_worker_protocol_version"], "1.15");
        assert_eq!(capability["transport"]["internal_signal"], INTERNAL_SIGNAL);
        assert_eq!(manifest()["batch"]["maximum"], 100);
    }

    #[test]
    fn completion_fields_follow_laravels_rules() {
        assert!(check_completion_fields(&json!({})).is_ok());
        assert!(check_completion_fields(&json!({"message_stream_cursors":[{"stream_name":"s","through_position":0}]})).is_ok());
        let Err(RuntimeError::Protocol { response, .. }) =
            check_completion_fields(&json!({"message_stream_cursors":[{"stream_name":"s","through_position":-1}]}))
        else {
            panic!("expected a validation failure");
        };
        assert_eq!(response["message"], "The message_stream_cursors.0.through_position field must be at least 0.");
    }
}
