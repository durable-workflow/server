//! Bridge adapter webhooks, as published Server 2.11.1 serves them
//! (`BridgeAdapterController`): an external system starts, signals or
//! updates a workflow through one idempotent event, and every answer is a
//! `durable-workflow.v2.bridge-adapter-outcome.contract` envelope.
//!
//! One published defect is not copied (Server #747): PHP's bridge update
//! skips external update admission, so an update to a workflow served by an
//! SDK worker is always refused `workflow_definition_unavailable`. Native
//! admits it as the regular update route does.
use super::command_context::CommandRequest;
use super::execution::Runtime;
use super::laravel_validation::{Rule, rule_errors};
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
    ("wait_timeout_seconds", &[Rule::Nullable, Rule::Integer, Rule::Min(1)]),
];

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

/// `workflowIdFor`: `bridge-<adapter slug>-<sha256(key) prefix>`. A valid
/// adapter name is its own slug.
fn derived_workflow_id(adapter: &str, key: &str) -> String {
    let digest = Sha256::digest(key.as_bytes());
    let hex: String = digest.iter().map(|byte| format!("{byte:02x}")).collect();
    format!("bridge-{adapter}-{}", &hex[..32])
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
        // `TaskQueueRoutingGate::workflowStartBlock`, before the start.
        if let Some(block) = self.runtime.bridge_start_block(&task_queue).await? {
            let mut extra = json!({"message":format!("Task queue [{task_queue}] is draining and cannot accept new workflow starts until an active worker cohort is available."),
                "target":redacted(&with_workflow_id(target, &workflow_id)),"correlation":self.correlation,"workflow_id":workflow_id,
                "workflow_type":workflow_type,"task_queue":task_queue,"rejection_reason":"task_queue_draining",
                "control_plane_outcome":"rejected_task_queue_draining"});
            extra.as_object_mut().expect("object").extend(block);
            return self.rejected(action, "task_queue_draining", extra);
        }
        // `DefaultWorkflowControlPlane::reserveInstance` throws a LogicException
        // for an instance reserved for another type, then for one in another
        // namespace.
        if let Some((reserved_type, namespace)) = self.runtime.instance_reservation(&workflow_id).await? {
            let message = if reserved_type != workflow_type {
                Some(format!("Workflow instance [{workflow_id}] is reserved for durable type [{reserved_type}] and cannot be reused for [{workflow_type}]."))
            } else if !namespace.as_deref().is_some_and(|namespace| self.runtime.is_namespace(namespace)) {
                Some(format!("Workflow instance [{workflow_id}] cannot be reused with a different namespace."))
            } else {
                None
            };
            if let Some(message) = message {
                return self.rejected(action, "unknown_target", json!({"message":message,"target":redacted(target),"correlation":self.correlation}));
            }
        }
        let mut start = json!({"workflow_id":workflow_id,"workflow_type":workflow_type,"task_queue":target["task_queue"],
            "business_key":target["business_key"],"input":self.body.get("input"),"duplicate_policy":policy});
        start.as_object_mut().expect("object").retain(|_, value| !value.is_null());
        let context = self.context(&workflow_id, "bridge.start_workflow",
            json!({"adapter":self.adapter,"action":action,"idempotency_key":self.key,"task_queue":task_queue}));
        let started = match self.runtime.start(start, Some(context)).await {
            Ok(started) => started,
            Err(RuntimeError::Protocol { response, .. }) if response["reason"] == "validation_failed" => {
                return self.rejected(action, "malformed_payload", json!({"message":response["message"],
                    "target":redacted(target),"correlation":self.correlation}));
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
    /// workflow of this namespace, or answered as a duplicate of an accepted
    /// bridge command. `None` lets the command run.
    async fn command_guard(&self, action: &str, kind: &str, field: &str, rules: &[(&str, &[Rule])], noun: &str) -> Result<Option<(StatusCode, Value)>> {
        let target = &self.target;
        if let Some(errors) = errors(target, rules) {
            return self.rejected(action, "malformed_payload", json!({"message":format!("Bridge {noun} target is malformed."),
                "errors":errors,"target":redacted(target),"correlation":self.correlation})).map(Some);
        }
        let workflow_id = target["workflow_id"].as_str().unwrap_or_default();
        if !self.runtime.workflow_in_namespace(workflow_id).await? {
            return self.rejected(action, "unknown_target", json!({"target":redacted(target),"correlation":self.correlation})).map(Some);
        }
        let name = target[field].as_str().unwrap_or_default();
        let Some(command) = self.runtime.bridge_command(workflow_id, kind, self.adapter, action, self.key, field, name).await? else {
            return Ok(None);
        };
        answer(StatusCode::OK, json!({"adapter":self.adapter,"action":action,"accepted":false,"outcome":"duplicate",
            "idempotency_key":self.key,"target":redacted(target),"correlation":self.correlation,
            "workflow_id":workflow_id,"run_id":command,"control_plane_outcome":"deduped_existing_command"})).map(Some)
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
        if let Some(answer) = self.command_guard(action, "signal", "signal_name", SIGNAL_RULES, "signal").await? {
            return Ok(answer);
        }
        let workflow_id = self.target["workflow_id"].as_str().unwrap_or_default();
        let name = self.target["signal_name"].as_str().unwrap_or_default();
        let context = self.context(workflow_id, "bridge.signal_workflow", json!({"adapter":self.adapter,"action":action,
            "idempotency_key":self.key,"request_id":self.key,"signal_name":name}));
        let body = json!({"input":self.body.get("input")});
        match self.runtime.signal(workflow_id, None, name, body, Some(context)).await {
            Ok(result) => self.command_outcome(action, StatusCode::ACCEPTED, &result),
            Err(error) => self.refused(action, error, super::control_plane::Operation::Signal(name), workflow_id),
        }
    }

    async fn update(&self) -> Result<(StatusCode, Value)> {
        let action = "update_workflow";
        if let Some(answer) = self.command_guard(action, "update", "update_name", UPDATE_RULES, "update").await? {
            return Ok(answer);
        }
        let workflow_id = self.target["workflow_id"].as_str().unwrap_or_default();
        let name = self.target["update_name"].as_str().unwrap_or_default();
        let wait_for = self.target["wait_for"].as_str().unwrap_or("accepted");
        let context = self.context(workflow_id, "bridge.update_workflow", json!({"adapter":self.adapter,"action":action,
            "idempotency_key":self.key,"request_id":self.key,"update_name":name,"wait_for":wait_for}));
        // As `WorkflowController::update` admits it: the idempotency key is
        // the update's request id.
        let mut body = json!({"input":self.body.get("input"),"request_id":self.key,"wait_for":wait_for,
            "wait_timeout_seconds":self.target.get("wait_timeout_seconds")});
        body.as_object_mut().expect("object").retain(|_, value| !value.is_null());
        match self.runtime.update_workflow(workflow_id, None, name, body, Some(context)).await {
            Ok((status, result)) => self.command_outcome(action, status, &result),
            Err(error) => self.refused(action, error, super::control_plane::Operation::Update(name), workflow_id),
        }
    }
}

/// `POST /api/bridge-adapters/webhook/{adapter}`.
pub(crate) async fn webhook(runtime: &Runtime, request: &CommandRequest, adapter: &str, body: Value) -> Result<(StatusCode, Value)> {
    let adapter = adapter.trim();
    if !routing_name(adapter) {
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
    /// The type and namespace an instance id is reserved for, in any namespace.
    pub(crate) async fn instance_reservation(&self, workflow_id: &str) -> Result<Option<(String, Option<String>)>> {
        let Some(row) = Self::query("SELECT workflow_type,namespace FROM workflow_instances WHERE id=$1")
            .bind(workflow_id)
            .fetch_optional(&self.pool)
            .await?
        else {
            return Ok(None);
        };
        Ok(Some((DB::string(&row, "workflow_type")?, DB::optional_string(&row, "namespace")?)))
    }

    /// Whether `namespace` is the one this store serves.
    pub(crate) fn is_namespace(&self, namespace: &str) -> bool {
        namespace == self.namespace.as_ref()
    }

    /// `TaskQueueRoutingGate::workflowStartBlock` for this namespace's queue.
    pub(crate) async fn bridge_start_block(&self, task_queue: &str) -> Result<Option<Map<String, Value>>> {
        let mut tx = self.begin().await?;
        let block = Self::routing_block(&mut tx, &self.namespace, task_queue, self.worker_stale_after_seconds).await?;
        tx.rollback().await?;
        Ok(block)
    }

    /// `NamespaceWorkflowScope::workflowBound`.
    pub(crate) async fn workflow_in_namespace(&self, workflow_id: &str) -> Result<bool> {
        let found = Self::scalar("SELECT COUNT(*) FROM workflow_instances WHERE id=$1 AND namespace=$2")
            .bind(workflow_id)
            .bind(self.namespace.as_ref())
            .fetch_one(&self.pool)
            .await?;
        Ok(found > 0)
    }

    /// `duplicateBridgeCommand`: the run of the latest accepted command of
    /// `kind` this adapter recorded for the same action, key and target name.
    /// The database filters on the command context, as PHP's JSON-path query
    /// does, so one request reads at most one row. PHP orders by
    /// `created_at`, which native never writes; an accepted command's
    /// `accepted_at` is that same instant.
    #[allow(clippy::too_many_arguments)]
    pub(crate) async fn bridge_command(&self, workflow_id: &str, kind: &str, adapter: &str, action: &str, key: &str, field: &str, name: &str) -> Result<Option<String>> {
        let Some(row) = Self::query(DB::BRIDGE_COMMAND)
            .bind(workflow_id)
            .bind(kind)
            .bind(adapter)
            .bind(action)
            .bind(key)
            .bind(field)
            .bind(name)
            .fetch_optional(&self.pool)
            .await?
        else {
            return Ok(None);
        };
        Ok(Some(match DB::optional_string(&row, "resolved_workflow_run_id")? {
            Some(run) => run,
            None => DB::string(&row, "workflow_run_id")?,
        }))
    }
}
