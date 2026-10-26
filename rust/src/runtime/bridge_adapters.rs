//! Bridge adapter webhooks, as published Server 2.11.1 serves them
//! (`BridgeAdapterController`): an external system starts, signals or
//! updates a workflow through one idempotent event, and every answer is a
//! `durable-workflow.v2.bridge-adapter-outcome.contract` envelope.
//!
//! Published defects not copied:
//! - Server #747: PHP's bridge update skips external update admission, so an
//!   update to a workflow served by an SDK worker is always refused
//!   `workflow_definition_unavailable`. Native admits it as the regular
//!   update route does.
//! - A start for an instance id another namespace holds is refused without
//!   naming that instance's type or namespace. PHP names the reserved type.
//!   The draining gate and the instance reservation are decided under the
//!   transition lock, before anything is written; PHP's gate reads outside
//!   its transaction.
//! - The adapter name is at most 64 bytes, so a derived workflow id fits the
//!   128-byte workflow id contract.
//! - Signals and updates are deduplicated atomically (Server #777). A
//!   fixed-length bridge identity, derived from the adapter, action, raw key
//!   and target, is the command's `request_id`. The duplicate check runs
//!   inside the command's transaction, under the transition lock: the
//!   identity first, then the command metadata PHP recorded before takeover.
//!   The unique (instance, command type, request id) index backs it, and
//!   callers cannot send a `dw-bridge:` request id. Any command under an
//!   identity, accepted or rejected, must record the same tuple, or the
//!   bridge answers 409 `bridge_identity_conflict`. PHP checks once, outside
//!   its transaction, and uses the raw key as an update's request id.
use super::command_context::CommandRequest;
use super::execution::Runtime;
use super::laravel_validation::{Rule, rule_errors};
use super::transaction::Transaction;
use super::{Result, RuntimeError};
use axum::http::StatusCode;
use serde_json::{Map, Value, json};
use sha2::{Digest, Sha256};

const SCHEMA: &str = "durable-workflow.v2.bridge-adapter-outcome.contract";

const EVENT_RULES: &[(&str, &[Rule])] = &[
    ("action", &[Rule::Required, Rule::String]),
    ("idempotency_key", &[Rule::Required, Rule::String, Rule::Max(255)]),
    ("target", &[Rule::Required, Rule::Array]),
    ("input", &[Rule::Nullable, Rule::Array]),
    ("correlation", &[Rule::Nullable, Rule::Array]),
];

const START_RULES: &[(&str, &[Rule])] = &[
    ("workflow_type", &[Rule::Required, Rule::String, Rule::Max(255)]),
    ("workflow_id", &[Rule::Nullable, Rule::String, Rule::Max(128), Rule::Pattern(routing_name)]),
    ("task_queue", &[Rule::Nullable, Rule::String, Rule::Max(255)]),
    ("business_key", &[Rule::Nullable, Rule::String, Rule::Max(255)]),
    ("duplicate_policy", &[Rule::Nullable, Rule::String, Rule::In(&["reject_duplicate", "use_existing"])]),
];

const SIGNAL_RULES: &[(&str, &[Rule])] = &[
    ("workflow_id", &[Rule::Required, Rule::String, Rule::Max(128), Rule::Pattern(routing_name)]),
    ("signal_name", &[Rule::Required, Rule::String, Rule::Max(255)]),
];

const UPDATE_RULES: &[(&str, &[Rule])] = &[
    ("workflow_id", &[Rule::Required, Rule::String, Rule::Max(128), Rule::Pattern(routing_name)]),
    ("update_name", &[Rule::Required, Rule::String, Rule::Max(255)]),
    ("wait_for", &[Rule::Nullable, Rule::String, Rule::In(&["accepted", "completed"])]),
    ("wait_timeout_seconds", &[Rule::Nullable, Rule::Integer, Rule::Min(1),
        Rule::Max(super::command_requests::UPDATE_WAIT_TIMEOUT_SECONDS_MAX)]),
];

/// The longest adapter name: `bridge-<adapter>-<32 hex>` then fits the
/// 128-byte workflow id contract.
const ADAPTER_MAX_BYTES: usize = 64;

/// `/^[a-zA-Z0-9._:-]+$/`, for adapter names and workflow ids.
fn routing_name(text: &str) -> bool {
    !text.is_empty() && text.bytes().all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'.' | b'_' | b':' | b'-'))
}

fn no_custom(_: &str, _: Rule) -> Option<&'static str> {
    None
}

/// The rule failures for `body`, as Laravel's `errors()->toArray()`, or
/// `None` when every rule passes.
fn errors(body: &Value, rules: &[(&str, &[Rule])]) -> Option<Value> {
    let errors: Map<String, Value> = rule_errors(body, rules, no_custom)
        .into_iter()
        .filter(|(_, messages)| !messages.is_empty())
        .map(|(field, messages)| (field, json!(messages)))
        .collect();
    (!errors.is_empty()).then_some(Value::Object(errors))
}

/// `outcome`: the contract fields first, then the payload without nulls.
fn envelope(payload: Value) -> Value {
    let mut body = Map::new();
    body.insert("schema".into(), json!(SCHEMA));
    body.insert("version".into(), json!(1));
    if let Value::Object(fields) = payload {
        body.extend(fields.into_iter().filter(|(_, value)| !value.is_null()));
    }
    Value::Object(body)
}

fn answer(status: StatusCode, payload: Value) -> Result<(StatusCode, Value)> {
    Ok((status, envelope(payload)))
}

/// `rejected`: a 422 refusal with `extra` after the event fields.
fn rejected(adapter: &str, action: Option<&str>, key: Option<&str>, reason: &str, extra: Value) -> Result<(StatusCode, Value)> {
    let mut payload = json!({"adapter":adapter,"action":action,"accepted":false,"outcome":"rejected","reason":reason,"idempotency_key":key});
    if let (Some(fields), Value::Object(extra)) = (payload.as_object_mut(), extra) {
        fields.extend(extra);
    }
    answer(StatusCode::UNPROCESSABLE_ENTITY, payload)
}

/// `redactedTarget`: only the routing fields, without nulls or empty strings.
fn redacted(target: &Value) -> Value {
    let mut fields = Map::new();
    for name in ["workflow_id", "workflow_type", "signal_name", "update_name", "task_queue", "business_key"] {
        match target.get(name) {
            None | Some(Value::Null) => {}
            Some(Value::String(text)) if text.is_empty() => {}
            Some(value) => {
                fields.insert(name.into(), value.clone());
            }
        }
    }
    Value::Object(fields)
}

/// `$target + ['workflow_id' => $id]`: the target keeps its own key.
fn with_workflow_id(target: &Value, workflow_id: &str) -> Value {
    let mut target = target.clone();
    if let Some(fields) = target.as_object_mut() {
        fields.entry("workflow_id").or_insert_with(|| json!(workflow_id));
    }
    target
}

/// `arrayValue`: the field when it is an array, else an empty one.
fn array_value(body: &Value, field: &str) -> Value {
    match body.get(field) {
        Some(value @ (Value::Object(_) | Value::Array(_))) => value.clone(),
        _ => json!([]),
    }
}

fn empty(value: &Value) -> bool {
    match value {
        Value::Object(fields) => fields.is_empty(),
        Value::Array(items) => items.is_empty(),
        _ => true,
    }
}

fn hex(digest: &[u8]) -> String {
    digest.iter().map(|byte| format!("{byte:02x}")).collect()
}

/// `workflowIdFor`: `bridge-<adapter slug>-<sha256(key) prefix>`. A valid
/// adapter name is its own slug.
fn derived_workflow_id(adapter: &str, key: &str) -> String {
    format!("bridge-{adapter}-{}", &hex(&Sha256::digest(key.as_bytes()))[..32])
}

/// One bridge command: its identity and the metadata it is recorded with.
pub(crate) struct BridgeKey<'a> {
    /// `dw-bridge:v1:` and sha256 over the length-prefixed adapter, action,
    /// raw key, target field and target name: fixed length, and distinct for
    /// any other tuple. It is the command's `request_id`.
    pub(crate) identity: String,
    pub(crate) adapter: &'a str,
    pub(crate) action: &'a str,
    pub(crate) key: &'a str,
    pub(crate) field: &'static str,
    pub(crate) name: &'a str,
}

impl<'a> BridgeKey<'a> {
    fn new(adapter: &'a str, action: &'a str, key: &'a str, field: &'static str, name: &'a str) -> Self {
        let mut digest = Sha256::new();
        for part in [adapter, action, key, field, name] {
            digest.update((part.len() as u64).to_be_bytes());
            digest.update(part.as_bytes());
        }
        let identity = format!("dw-bridge:v1:{}", hex(&digest.finalize()));
        Self { identity, adapter, action, key, field, name }
    }

    /// Whether a command's context names this adapter, action, key and
    /// target.
    fn recorded_in(&self, context: &Value) -> bool {
        let metadata = &context["server"]["metadata"];
        metadata["adapter"] == self.adapter && metadata["action"] == self.action
            && metadata["idempotency_key"] == self.key && metadata[self.field] == self.name
    }

    /// Any command found under this identity, accepted or rejected, must
    /// record this exact tuple; anything else fails closed before its
    /// receipt is answered.
    pub(crate) fn verify(&self, context: Option<&Value>) -> Result<()> {
        if context.is_some_and(|context| self.recorded_in(context)) { Ok(()) } else { Err(identity_conflict()) }
    }
}

const IDENTITY_CONFLICT: &str = "bridge_identity_conflict";

/// A bridge identity whose command records another tuple: refused, not
/// answered with that command.
fn identity_conflict() -> RuntimeError {
    super::refuse(StatusCode::CONFLICT, IDENTITY_CONFLICT)
}

fn is_identity_conflict(error: &RuntimeError) -> bool {
    matches!(error, RuntimeError::Refused { reason, .. } if *reason == IDENTITY_CONFLICT)
}

/// Whether a write lost to the unique (instance, command type, request id)
/// index: another request recorded the same bridge command first.
fn duplicate_write(error: &RuntimeError) -> bool {
    matches!(error, RuntimeError::Database(sqlx::Error::Database(error)) if error.is_unique_violation())
}

/// The event that reached a route, after Laravel's request normalization.
struct Event<'a> {
    runtime: &'a Runtime,
    request: &'a CommandRequest,
    body: &'a Value,
    adapter: &'a str,
    key: &'a str,
    target: Value,
    correlation: Value,
}

impl Event<'_> {
    fn rejected(&self, action: &str, reason: &str, extra: Value) -> Result<(StatusCode, Value)> {
        rejected(self.adapter, Some(action), Some(self.key), reason, extra)
    }

    fn context(&self, workflow_id: &str, command: &str, metadata: Value) -> Value {
        self.request.context(self.body, workflow_id, command, metadata)
    }

    async fn start(&self) -> Result<(StatusCode, Value)> {
        let action = "start_workflow";
        let target = &self.target;
        if let Some(errors) = errors(target, START_RULES) {
            return self.rejected(action, "malformed_payload", json!({"message":"Bridge start target is malformed.",
                "errors":errors,"target":redacted(target),"correlation":self.correlation}));
        }
        let workflow_type = target["workflow_type"].as_str().unwrap_or_default();
        let workflow_id = target["workflow_id"].as_str().map_or_else(|| derived_workflow_id(self.adapter, self.key), str::to_owned);
        // `resolveTaskQueue`: a requested queue, or the default one.
        let task_queue = target["task_queue"].as_str().map_or("default", str::trim).to_owned();
        let policy = if target["duplicate_policy"] == "reject_duplicate" { "fail" } else { "use-existing" };
        let mut start = json!({"workflow_id":workflow_id,"workflow_type":workflow_type,"task_queue":target["task_queue"],
            "business_key":target["business_key"],"input":self.body.get("input"),"duplicate_policy":policy});
        start.as_object_mut().expect("object").retain(|_, value| !value.is_null());
        let context = self.context(&workflow_id, "bridge.start_workflow",
            json!({"adapter":self.adapter,"action":action,"idempotency_key":self.key,"task_queue":task_queue}));
        // `TaskQueueRoutingGate::workflowStartBlock`, then
        // `DefaultWorkflowControlPlane::reserveInstance` namespace first, all
        // decided by the store under the transition lock with nothing written
        // (`Store::bridge_start`). An instance another namespace holds is
        // unavailable, its type and namespace unnamed; PHP checks the type
        // first and names it.
        let started = match self.runtime.bridge_start(start, context).await {
            Ok(started) => started,
            Err(RuntimeError::Protocol { response, .. }) if response["reason"] == "validation_failed" => {
                return self.rejected(action, "malformed_payload", json!({"message":response["message"],
                    "target":redacted(target),"correlation":self.correlation}));
            }
            Err(RuntimeError::Protocol { response, .. }) if response["reason"] == "bridge_start_refused" => {
                let decision = &response["bridge"];
                return match decision["refusal"].as_str() {
                    Some("task_queue_draining") => {
                        let mut extra = json!({"message":format!("Task queue [{task_queue}] is draining and cannot accept new workflow starts until an active worker cohort is available."),
                            "target":redacted(&with_workflow_id(target, &workflow_id)),"correlation":self.correlation,"workflow_id":workflow_id,
                            "workflow_type":workflow_type,"task_queue":task_queue,"rejection_reason":"task_queue_draining",
                            "control_plane_outcome":"rejected_task_queue_draining"});
                        if let (Some(fields), Some(block)) = (extra.as_object_mut(), decision["block"].as_object()) {
                            fields.extend(block.clone());
                        }
                        self.rejected(action, "task_queue_draining", extra)
                    }
                    Some("instance_reserved_for_type") => {
                        let reserved_type = decision["reserved_type"].as_str().unwrap_or_default();
                        self.rejected(action, "unknown_target", json!({"message":format!("Workflow instance [{workflow_id}] is reserved for durable type [{reserved_type}] and cannot be reused for [{workflow_type}]."),
                            "target":redacted(target),"correlation":self.correlation}))
                    }
                    _ => self.rejected(action, "unknown_target", json!({"message":format!("Workflow instance [{workflow_id}] is unavailable."),
                        "target":redacted(target),"correlation":self.correlation})),
                };
            }
            Err(error) => return Err(error),
        };
        let outcome = started["outcome"].as_str().unwrap_or_default();
        let duplicate = outcome == "returned_existing_active";
        let started_id = started["workflow_id"].as_str().unwrap_or(&workflow_id).to_owned();
        if outcome != "started_new" && !duplicate {
            let reason = started["rejection_reason"].as_str().or(started["reason"].as_str()).unwrap_or("unsupported_routing").to_owned();
            return self.rejected(action, &reason, json!({"message":started["message"],
                "target":redacted(&with_workflow_id(target, &started_id)),"correlation":self.correlation,
                "workflow_id":started_id,"run_id":started["run_id"],"workflow_type":started["workflow_type"],
                "rejection_reason":started["rejection_reason"],"control_plane_outcome":outcome}));
        }
        answer(if duplicate { StatusCode::OK } else { StatusCode::ACCEPTED }, json!({"adapter":self.adapter,"action":action,
            "accepted":!duplicate,"outcome":if duplicate { "duplicate" } else { "accepted" },
            "reason":if duplicate { json!("duplicate_start") } else { Value::Null },"idempotency_key":self.key,
            "target":redacted(&with_workflow_id(target, &started_id)),"correlation":self.correlation,
            "workflow_id":started_id,"run_id":started["run_id"],"workflow_type":started["workflow_type"],
            "control_plane_outcome":outcome}))
    }

    /// The target of a signal or update, refused when malformed or not a
    /// workflow of this namespace. `None` lets the command run; its own
    /// transaction answers a duplicate.
    async fn command_guard(&self, action: &str, rules: &[(&str, &[Rule])], noun: &str) -> Result<Option<(StatusCode, Value)>> {
        let target = &self.target;
        if let Some(errors) = errors(target, rules) {
            return self.rejected(action, "malformed_payload", json!({"message":format!("Bridge {noun} target is malformed."),
                "errors":errors,"target":redacted(target),"correlation":self.correlation})).map(Some);
        }
        let workflow_id = target["workflow_id"].as_str().unwrap_or_default();
        if !self.runtime.workflow_in_namespace(workflow_id).await? {
            return self.rejected(action, "unknown_target", json!({"target":redacted(target),"correlation":self.correlation})).map(Some);
        }
        Ok(None)
    }

    /// The answer for a command this adapter already recorded.
    fn duplicate(&self, action: &str, workflow_id: &str, run_id: &Value) -> Result<(StatusCode, Value)> {
        answer(StatusCode::OK, json!({"adapter":self.adapter,"action":action,"accepted":false,"outcome":"duplicate",
            "idempotency_key":self.key,"target":redacted(&self.target),"correlation":self.correlation,
            "workflow_id":workflow_id,"run_id":run_id,"control_plane_outcome":"deduped_existing_command"}))
    }

    /// A bridge identity whose stored command records another tuple: a 409
    /// in the bridge envelope, with only the caller's own fields.
    fn identity_conflict(&self, action: &str) -> Result<(StatusCode, Value)> {
        answer(StatusCode::CONFLICT, json!({"adapter":self.adapter,"action":action,"accepted":false,"outcome":"rejected",
            "reason":IDENTITY_CONFLICT,"idempotency_key":self.key,
            "message":"The bridge command's identity is recorded for another command.",
            "target":redacted(&self.target),"correlation":self.correlation}))
    }

    /// A write that lost to the unique index. It is this command's duplicate
    /// only when the accepted command under the identity records the same
    /// adapter, action, key and target; anything else fails closed.
    async fn lost_race(&self, action: &str, kind: &str, key: &BridgeKey<'_>) -> Result<(StatusCode, Value)> {
        let workflow_id = self.target["workflow_id"].as_str().unwrap_or_default();
        match self.runtime.bridge_identity_receipt(workflow_id, kind, &key.identity).await? {
            Some((run, context)) if key.recorded_in(&context) => self.duplicate(action, workflow_id, &json!(run)),
            _ => self.identity_conflict(action),
        }
    }

    /// The command's bridge key.
    fn bridge_key<'k>(&'k self, action: &'k str, field: &'static str) -> BridgeKey<'k> {
        BridgeKey::new(self.adapter, action, self.key, field, self.target[field].as_str().unwrap_or_default())
    }

    /// `commandOutcome`: the control plane's answer in the envelope.
    fn command_outcome(&self, action: &str, status: StatusCode, result: &Value) -> Result<(StatusCode, Value)> {
        if result["reason"] == "instance_not_found" {
            return self.rejected(action, "unknown_target", json!({"target":redacted(&self.target),"correlation":self.correlation}));
        }
        let accepted = status.as_u16() < 400;
        answer(if accepted { StatusCode::ACCEPTED } else { StatusCode::UNPROCESSABLE_ENTITY }, json!({
            "adapter":self.adapter,"action":action,"accepted":accepted,"outcome":if accepted { "accepted" } else { "rejected" },
            "reason":if accepted { Value::Null } else { json!(result["reason"].as_str().unwrap_or("unsupported_routing")) },
            "idempotency_key":self.key,"target":redacted(&self.target),"correlation":self.correlation,
            "workflow_id":result.get("workflow_id").filter(|id| !id.is_null()).unwrap_or(&self.target["workflow_id"]),
            "run_id":result["run_id"],"control_plane_outcome":result["outcome"]}))
    }

    /// A refused command, as the control plane's result for it.
    fn refused(&self, action: &str, error: RuntimeError, operation: super::control_plane::Operation<'_>, workflow_id: &str) -> Result<(StatusCode, Value)> {
        match operation.refusal(error, workflow_id, None) {
            RuntimeError::Protocol { status, response } if response["reason"] != "validation_failed" => {
                self.command_outcome(action, status, &response)
            }
            RuntimeError::Refused { status, reason } => self.command_outcome(action, status, &json!({"reason":reason})),
            other => Err(other),
        }
    }

    async fn signal(&self) -> Result<(StatusCode, Value)> {
        let action = "signal_workflow";
        if let Some(answer) = self.command_guard(action, SIGNAL_RULES, "signal").await? {
            return Ok(answer);
        }
        let workflow_id = self.target["workflow_id"].as_str().unwrap_or_default();
        let name = self.target["signal_name"].as_str().unwrap_or_default();
        let context = self.context(workflow_id, "bridge.signal_workflow", json!({"adapter":self.adapter,"action":action,
            "idempotency_key":self.key,"request_id":self.key,"signal_name":name}));
        let body = json!({"input":self.body.get("input")});
        let key = self.bridge_key(action, "signal_name");
        match self.runtime.bridge_signal(workflow_id, name, body, context, &key).await {
            Ok(result) if result["outcome"] == "deduped_existing_command" => self.duplicate(action, workflow_id, &result["run_id"]),
            Ok(result) => self.command_outcome(action, StatusCode::ACCEPTED, &result),
            Err(error) if is_identity_conflict(&error) => self.identity_conflict(action),
            Err(error) if duplicate_write(&error) => self.lost_race(action, "signal", &key).await,
            Err(error) => self.refused(action, error, super::control_plane::Operation::Signal(name), workflow_id),
        }
    }

    async fn update(&self) -> Result<(StatusCode, Value)> {
        let action = "update_workflow";
        if let Some(answer) = self.command_guard(action, UPDATE_RULES, "update").await? {
            return Ok(answer);
        }
        let workflow_id = self.target["workflow_id"].as_str().unwrap_or_default();
        let name = self.target["update_name"].as_str().unwrap_or_default();
        let wait_for = self.target["wait_for"].as_str().unwrap_or("accepted");
        let context = self.context(workflow_id, "bridge.update_workflow", json!({"adapter":self.adapter,"action":action,
            "idempotency_key":self.key,"request_id":self.key,"update_name":name,"wait_for":wait_for}));
        // As `WorkflowController::update` admits it. The bridge identity is
        // the update's request id; the raw key stays in the metadata.
        let mut body = json!({"input":self.body.get("input"),"wait_for":wait_for,
            "wait_timeout_seconds":self.target.get("wait_timeout_seconds")});
        body.as_object_mut().expect("object").retain(|_, value| !value.is_null());
        let key = self.bridge_key(action, "update_name");
        match self.runtime.bridge_update(workflow_id, name, body, context, &key).await {
            Ok((_, result)) if result["outcome"] == "deduped_existing_command" => self.duplicate(action, workflow_id, &result["run_id"]),
            Ok((status, result)) => self.command_outcome(action, status, &result),
            Err(error) if is_identity_conflict(&error) => self.identity_conflict(action),
            Err(error) if duplicate_write(&error) => self.lost_race(action, "update", &key).await,
            Err(error) => self.refused(action, error, super::control_plane::Operation::Update(name), workflow_id),
        }
    }
}

/// `POST /api/bridge-adapters/webhook/{adapter}`.
pub(crate) async fn webhook(runtime: &Runtime, request: &CommandRequest, adapter: &str, body: Value) -> Result<(StatusCode, Value)> {
    let adapter = adapter.trim();
    if !routing_name(adapter) || adapter.len() > ADAPTER_MAX_BYTES {
        return rejected(adapter, None, None, "unsupported_routing", json!({"message":"Bridge adapter route is unsupported."}));
    }
    let body = super::start_request::normalized(body);
    if let Some(errors) = errors(&body, EVENT_RULES) {
        return rejected(adapter, None, None, "malformed_payload", json!({"message":"Bridge event payload is malformed.","errors":errors}));
    }
    let action = body["action"].as_str().unwrap_or_default();
    let key = body["idempotency_key"].as_str().unwrap_or_default();
    let correlation = Some(array_value(&body, "correlation")).filter(|value| !empty(value)).unwrap_or(Value::Null);
    let event = Event { runtime, request, body: &body, adapter, key, target: array_value(&body, "target"), correlation };
    match action {
        "start_workflow" => event.start().await,
        "signal_workflow" => event.signal().await,
        "update_workflow" => event.update().await,
        _ => event.rejected(action, "unsupported_action", json!({"message":"Bridge action is unsupported.",
            "target":redacted(&event.target),"correlation":event.correlation})),
    }
}

store_methods! {
    /// `NamespaceWorkflowScope::workflowBound`.
    pub(crate) async fn workflow_in_namespace(&self, workflow_id: &str) -> Result<bool> {
        let found = Self::scalar("SELECT COUNT(*) FROM workflow_instances WHERE id=$1 AND namespace=$2")
            .bind(workflow_id)
            .bind(self.namespace.as_ref())
            .fetch_one(&self.pool)
            .await?;
        Ok(found > 0)
    }

    /// The accepted command recorded under a bridge identity: its run and
    /// its context.
    pub(crate) async fn bridge_identity_receipt(&self, workflow_id: &str, kind: &str, identity: &str) -> Result<Option<(String, Value)>> {
        let Some(row) = Self::query("SELECT workflow_run_id,resolved_workflow_run_id,context FROM workflow_commands WHERE workflow_instance_id=$1 AND command_type=$2 AND status='accepted' AND request_id=$3")
            .bind(workflow_id).bind(kind).bind(identity).fetch_optional(&self.pool).await?
        else {
            return Ok(None);
        };
        Ok(Some((Self::bridge_run(&row)?, DB::document_row(&row, "context")?)))
    }

    /// `duplicateBridgeCommand`, inside the command's transaction and under
    /// the transition lock: the run of this bridge command's accepted
    /// command, found by its identity, or else by the metadata PHP recorded
    /// before takeover. PHP's commands carry no identity: a signal has no
    /// request id and an update has the raw key. A command of any status
    /// under the identity must record this tuple, or the request fails
    /// closed; a rejected one keeps its own receipt (the update path replays
    /// it), so only an accepted one is answered here.
    pub(super) async fn bridge_receipt(tx: &mut Transaction<'_, DB>, workflow_id: &str, kind: &str, key: &BridgeKey<'_>) -> Result<Option<String>> {
        if let Some(row) = Self::query("SELECT status,workflow_run_id,resolved_workflow_run_id,context FROM workflow_commands WHERE workflow_instance_id=$1 AND command_type=$2 AND request_id=$3")
            .bind(workflow_id).bind(kind).bind(key.identity.as_str()).fetch_optional(&mut **tx).await?
        {
            key.verify(DB::optional_document_row(&row, "context")?.as_ref())?;
            return if DB::string(&row, "status")? == "accepted" { Ok(Some(Self::bridge_run(&row)?)) } else { Ok(None) };
        }
        // PHP orders by `created_at`, which native never writes; an accepted
        // command's `accepted_at` is that same instant.
        let Some(row) = Self::query(DB::BRIDGE_COMMAND)
            .bind(workflow_id).bind(kind).bind(key.adapter).bind(key.action).bind(key.key).bind(key.field).bind(key.name).bind(key.key)
            .fetch_optional(&mut **tx).await?
        else {
            return Ok(None);
        };
        Ok(Some(Self::bridge_run(&row)?))
    }

    fn bridge_run(row: &DB::Row) -> Result<String> {
        match DB::optional_string(row, "resolved_workflow_run_id")? {
            Some(run) => Ok(run),
            None => DB::string(row, "workflow_run_id"),
        }
    }

}
