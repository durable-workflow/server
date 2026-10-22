//! The command context published PHP Server records on a control-plane
//! command (`WorkflowCommandContextFactory`), and the command snapshot the
//! Workflow package copies into each history event recorded with that command
//! (`WorkflowHistoryEvent::commandSnapshot`).
//!
//! The principal comes only from the server's authentication. Request input
//! and forwarded headers never supply it. Native never trusts forwarded
//! attribution headers, PHP's default.
use super::{
    RuntimeError, http::AuthenticatedPrincipal, laravel_rules::normalize, refuse,
    runtime_credentials::Principal, store::id,
};
use axum::{
    extract::{ConnectInfo, FromRequestParts},
    http::{StatusCode, request::Parts},
};
use serde::Serialize;
use serde_json::{Map, Value, json};
use sha2::{Digest, Sha256};
use std::net::SocketAddr;

/// What a command context takes from its request, read before the body.
#[derive(Clone, Debug)]
pub(crate) struct CommandRequest {
    method: String,
    path: String,
    ip: Option<String>,
    user_agent: Option<String>,
    request_id: Option<String>,
    correlation_id: Option<String>,
    namespace: String,
    principal: Principal,
}

impl<S: Send + Sync> FromRequestParts<S> for CommandRequest {
    type Rejection = RuntimeError;

    async fn from_request_parts(parts: &mut Parts, _: &S) -> Result<Self, Self::Rejection> {
        Self::from_parts(parts)
    }
}

impl CommandRequest {
    pub(crate) fn from_parts(parts: &Parts) -> Result<Self, RuntimeError> {
        let principal = parts
            .extensions
            .get::<AuthenticatedPrincipal>()
            .ok_or_else(|| refuse(StatusCode::UNAUTHORIZED, "unauthorized"))?
            .principal
            .clone();
        let header = |name: &str| {
            parts
                .headers
                .get(name)
                .and_then(|value| value.to_str().ok())
                .filter(|value| !value.is_empty())
                .map(str::to_owned)
        };
        // `NamespaceResolver`: the header, else the query, else the default.
        let namespace = match parts.headers.get("x-namespace") {
            Some(value) => value.to_str().unwrap_or("").to_lowercase(),
            None => axum::extract::Query::<std::collections::HashMap<String, String>>::try_from_uri(&parts.uri)
                .ok()
                .and_then(|axum::extract::Query(fields)| fields.get("namespace").cloned())
                .unwrap_or_else(|| "default".into())
                .to_lowercase(),
        };
        Ok(Self {
            method: parts.method.as_str().to_owned(),
            path: parts.uri.path().to_owned(),
            ip: parts
                .extensions
                .get::<ConnectInfo<SocketAddr>>()
                .map(|peer| peer.0.ip().to_string()),
            user_agent: header("user-agent"),
            request_id: header("x-request-id"),
            correlation_id: header("x-correlation-id"),
            namespace,
            principal,
        })
    }

    pub(crate) fn principal(&self) -> &Principal {
        &self.principal
    }

    /// `WorkflowCommandContextFactory::make` for `command` on `workflow_id`.
    /// `payload` is the request body as the client sent it; `metadata` is
    /// the operation's own fields, or `[]` when it has none.
    pub(crate) fn context(&self, payload: &Value, workflow_id: &str, command: &str, metadata: Value) -> Value {
        self.routed_context(payload, workflow_id, command, metadata, None)
    }

    /// The context of a request through a named route. Published PHP names
    /// only some routes; the others get a generated name per deployment,
    /// which native leaves out.
    pub(crate) fn routed_context(
        &self,
        payload: &Value,
        workflow_id: &str,
        command: &str,
        metadata: Value,
        route_name: Option<&str>,
    ) -> Value {
        let principal = &self.principal;
        let mut auth = Map::new();
        auth.insert("status".into(), json!(auth_status(principal)));
        auth.insert("method".into(), json!(principal.method));
        if let Some(role) = principal.primary_role() {
            auth.insert("role".into(), json!(role));
        }
        auth.insert("roles".into(), json!(principal.roles));
        let audit = audit_context(principal);
        if audit.as_object().is_some_and(|fields| !fields.is_empty()) {
            auth.insert("principal".into(), audit);
        }
        let mut context = json!({
            "caller": {"type": "server", "label": "Standalone Server"},
            "auth": auth,
            "request": self.request_metadata(payload, route_name),
            // `Str::ulid()` is upper case.
            "server": {"operation_id": id().to_uppercase(), "namespace": self.namespace, "workflow_id": workflow_id,
                "command": command, "metadata": metadata},
        });
        if let Some(principal) = server_principal(principal) {
            context["principal"] = principal;
        }
        context
    }

    /// `WorkflowCommandContextFactory::requestMetadata`.
    fn request_metadata(&self, payload: &Value, route_name: Option<&str>) -> Value {
        // Laravel fingerprints its normalized input with keys sorted.
        let payload = php_arrays(&normalize(payload));
        let mut headers = Map::new();
        if let Some(value) = &self.request_id {
            headers.insert("x_request_id".into(), json!(value));
        }
        if let Some(value) = &self.correlation_id {
            headers.insert("x_correlation_id".into(), json!(value));
        }
        let headers = if headers.is_empty() { json!([]) } else { Value::Object(headers) };
        // PHP hashes the headers in the order it lists them.
        let fingerprint_headers = if headers.is_array() {
            FingerprintHeaders::Empty([])
        } else {
            FingerprintHeaders::Present {
                x_request_id: self.request_id.as_deref(),
                x_correlation_id: self.correlation_id.as_deref(),
            }
        };
        let encoded = serde_json::to_vec(&Fingerprint {
            method: &self.method,
            path: &self.path,
            payload: &payload,
            headers: fingerprint_headers,
        })
        .expect("JSON request fingerprint");
        let fingerprint = Sha256::digest(encoded)
            .iter()
            .map(|byte| format!("{byte:02x}"))
            .collect::<String>();
        // The header request id, else a non-empty body `request_id`. The
        // correlation id comes only from its header.
        let request_id = self.request_id.clone().or_else(|| {
            payload["request_id"]
                .as_str()
                .map(str::trim)
                .filter(|value| !value.is_empty())
                .map(str::to_owned)
        });
        let mut request = json!({"method": self.method, "path": self.path,
            "fingerprint": format!("sha256:{fingerprint}")});
        if let Some(route_name) = route_name {
            request["route_name"] = json!(route_name);
        }
        for (field, value) in [
            ("ip", &self.ip),
            ("user_agent", &self.user_agent),
            ("request_id", &request_id),
            ("correlation_id", &self.correlation_id),
        ] {
            if let Some(value) = value {
                request[field] = json!(value);
            }
        }
        if headers.is_object() {
            request["headers"] = headers;
        }
        request
    }
}

/// PHP's fingerprint document, in its key order.
#[derive(Serialize)]
struct Fingerprint<'a> {
    method: &'a str,
    path: &'a str,
    payload: &'a Value,
    headers: FingerprintHeaders<'a>,
}

/// The recorded headers: PHP's empty array, or its keys in PHP's order.
#[derive(Serialize)]
#[serde(untagged)]
enum FingerprintHeaders<'a> {
    Empty([&'a str; 0]),
    Present {
        #[serde(skip_serializing_if = "Option::is_none")]
        x_request_id: Option<&'a str>,
        #[serde(skip_serializing_if = "Option::is_none")]
        x_correlation_id: Option<&'a str>,
    },
}

/// PHP encodes an empty array as `[]` whether it began as an object or not.
fn php_arrays(value: &Value) -> Value {
    match value {
        Value::Object(map) if map.is_empty() => json!([]),
        Value::Object(map) => Value::Object(map.iter().map(|(key, value)| (key.clone(), php_arrays(value))).collect()),
        Value::Array(values) => Value::Array(values.iter().map(php_arrays).collect()),
        other => other.clone(),
    }
}

/// `WorkflowCommandContextFactory::principalAuthStatus`.
fn auth_status(principal: &Principal) -> &'static str {
    if principal.method == "none" {
        "not_configured"
    } else {
        "authorized"
    }
}

/// `Principal::toAuditContext`: empty values and a false legacy flag are left
/// out.
pub(crate) fn audit_context(principal: &Principal) -> Value {
    let mut audit = Map::new();
    if !principal.subject.is_empty() {
        audit.insert("subject".into(), json!(principal.subject));
    }
    if !principal.roles.is_empty() {
        audit.insert("roles".into(), json!(principal.roles));
    }
    if let Some(tenant) = principal.tenant.as_deref().filter(|tenant| !tenant.is_empty()) {
        audit.insert("tenant".into(), json!(tenant));
    }
    if principal.claims.as_object().is_some_and(|claims| !claims.is_empty()) {
        audit.insert("claims".into(), principal.claims.clone());
    }
    if principal.legacy_full_access {
        audit.insert("legacy_full_access".into(), json!(true));
    }
    Value::Object(audit)
}

/// `WorkflowCommandContextFactory::serverDerivedPrincipal`: the identity
/// recorded on history, or none for a principal without a subject.
pub(crate) fn server_principal(principal: &Principal) -> Option<Value> {
    let subject = principal.subject.trim();
    if subject.is_empty() {
        return None;
    }
    let method = principal.method.trim();
    let kind = if method.is_empty() || method == "none" {
        "server".to_owned()
    } else {
        format!("auth:{method}")
    };
    let mut value = json!({"type": kind, "id": subject});
    if let Some(label) = principal_label(principal) {
        value["label"] = json!(label);
    }
    Some(value)
}

/// `WorkflowCommandContextFactory::principalLabel`: a display claim, else
/// the primary role with its first letter capitalized.
fn principal_label(principal: &Principal) -> Option<String> {
    for claim in ["display_name", "name", "email"] {
        if let Some(value) = principal.claims[claim].as_str().map(str::trim).filter(|value| !value.is_empty()) {
            return Some(value.to_owned());
        }
    }
    let role = principal.primary_role()?.trim();
    let mut letters = role.chars();
    let first = letters.next()?;
    Some(first.to_uppercase().chain(letters).collect())
}

/// `WorkflowCommand::publicContext`: the parent workflow, intake and
/// principal parts of a stored context.
fn public_context(context: &Value) -> Value {
    let mut public = Map::new();
    let workflow = &context["workflow"];
    if workflow.is_object() {
        let mut fields = Map::new();
        for field in ["parent_instance_id", "parent_run_id", "child_call_id"] {
            if let Some(value) = workflow[field].as_str() {
                fields.insert(field.into(), json!(value));
            }
        }
        if let Some(sequence) = workflow["sequence"].as_i64() {
            fields.insert("sequence".into(), json!(sequence));
        }
        if !fields.is_empty() {
            public.insert("workflow".into(), Value::Object(fields));
        }
    }
    let intake = &context["intake"];
    if intake.is_object() {
        let mut fields = Map::new();
        for field in ["mode", "group_id"] {
            if let Some(value) = intake[field].as_str() {
                fields.insert(field.into(), json!(value));
            }
        }
        if !fields.is_empty() {
            public.insert("intake".into(), Value::Object(fields));
        }
    }
    let principal = &context["principal"];
    if principal.is_object() {
        let mut fields = Map::new();
        for field in ["type", "id", "label"] {
            if let Some(value) = principal[field].as_str().filter(|value| !value.trim().is_empty()) {
                fields.insert(field.into(), json!(value));
            }
        }
        if !fields.is_empty() {
            public.insert("principal".into(), Value::Object(fields));
        }
    }
    Value::Object(public)
}

/// A stored command's columns, for its history snapshot.
pub(crate) struct CommandColumns {
    pub(crate) id: String,
    pub(crate) sequence: Option<i64>,
    pub(crate) message_sequence: Option<i64>,
    pub(crate) command_type: Option<String>,
    pub(crate) target_scope: Option<String>,
    pub(crate) requested_run_id: Option<String>,
    pub(crate) resolved_run_id: Option<String>,
    pub(crate) target_name: Option<String>,
    pub(crate) payload_codec: Option<String>,
    pub(crate) payload: Option<String>,
    pub(crate) source: Option<String>,
    pub(crate) context: Value,
    pub(crate) request_id: Option<String>,
    pub(crate) status: Option<String>,
    pub(crate) outcome: Option<String>,
    pub(crate) rejection_reason: Option<String>,
    pub(crate) accepted_at: Option<String>,
    pub(crate) applied_at: Option<String>,
    pub(crate) rejected_at: Option<String>,
}

/// `WorkflowHistoryEvent::commandSnapshot`: null fields are left out.
pub(crate) fn snapshot(command: CommandColumns) -> Value {
    let context = &command.context;
    let text = |section: &str, field: &str| context[section][field].as_str().map(str::to_owned);
    let public = public_context(context);
    let request_id = command.request_id.filter(|value| !value.is_empty()).or_else(|| text("request", "request_id"));
    // Native history holds the envelope that PHP's history API presents for
    // the stored blob, so every reader sees the same payload. PHP's external
    // payload references stay as stored.
    let payload = command.payload.map(|payload| match command.payload_codec.as_deref() {
        Some(codec) if !codec.is_empty() && !payload.starts_with(super::history_presentation::PHP_EXTERNAL_REFERENCE) => {
            json!({"codec": codec, "blob": payload})
        }
        _ => json!(payload),
    });
    let mut snapshot = Map::new();
    let mut put = |field: &str, value: Option<Value>| {
        if let Some(value) = value.filter(|value| !value.is_null()) {
            snapshot.insert(field.into(), value);
        }
    };
    put("id", Some(json!(command.id)));
    put("sequence", command.sequence.map(Value::from));
    put("message_sequence", command.message_sequence.map(Value::from));
    put("type", command.command_type.map(Value::from));
    put("target_scope", command.target_scope.map(Value::from));
    put("requested_run_id", command.requested_run_id.map(Value::from));
    put("resolved_run_id", command.resolved_run_id.map(Value::from));
    put("target_name", command.target_name.map(Value::from));
    put("payload_codec", command.payload_codec.map(Value::from));
    put("payload", payload);
    put("source", command.source.map(Value::from));
    put("context", public.as_object().is_some_and(|fields| !fields.is_empty()).then_some(public));
    put("caller_label", text("caller", "label").map(Value::from));
    put("principal_type", text("principal", "type").map(Value::from));
    put("principal_id", text("principal", "id").map(Value::from));
    put("principal_label", text("principal", "label").map(Value::from));
    put("auth_status", text("auth", "status").map(Value::from));
    put("auth_method", text("auth", "method").map(Value::from));
    put("request_method", text("request", "method").map(Value::from));
    put("request_path", text("request", "path").map(Value::from));
    put("request_route_name", text("request", "route_name").map(Value::from));
    put("request_fingerprint", text("request", "fingerprint").map(Value::from));
    put("request_id", request_id.map(Value::from));
    put("correlation_id", text("request", "correlation_id").map(Value::from));
    put("status", command.status.map(Value::from));
    put("outcome", command.outcome.map(Value::from));
    put("rejection_reason", command.rejection_reason.map(Value::from));
    put("accepted_at", command.accepted_at.map(Value::from));
    put("applied_at", command.applied_at.map(Value::from));
    put("rejected_at", command.rejected_at.map(Value::from));
    Value::Object(snapshot)
}

/// The fields published PHP's run description adds to each command from its
/// context (`WorkflowController::formatCommand`).
pub(crate) fn described(context: &Value) -> Map<String, Value> {
    let text = |path: &[&str]| -> Option<Value> {
        let mut value = context;
        for key in path {
            value = value.get(*key)?;
        }
        value.as_str().filter(|value| !value.is_empty()).map(Value::from)
    };
    let pick = |section: &str, fields: &[&str]| -> Option<Value> {
        let picked: Map<String, Value> = fields
            .iter()
            .filter_map(|field| text(&[section, field]).map(|value| ((*field).to_owned(), value)))
            .collect();
        (!picked.is_empty()).then_some(Value::Object(picked))
    };
    let mut public = Map::new();
    for (section, fields) in [
        ("caller", &["type", "label"][..]),
        ("auth", &["status", "method", "role"][..]),
        ("principal", &["type", "id", "label"][..]),
    ] {
        if let Some(value) = pick(section, fields) {
            public.insert(section.into(), value);
        }
    }
    // `commandContextRequestId`: the request's id, else the operation's,
    // else the recorded header.
    let request_id = [
        &context["request"]["request_id"],
        &context["server"]["metadata"]["request_id"],
        &context["request"]["headers"]["x_request_id"],
    ]
    .into_iter()
    .find_map(|candidate| candidate.as_str().map(str::trim).filter(|value| !value.is_empty()).map(Value::from));
    let mut fields = Map::new();
    for (field, value) in [
        ("context", (!public.is_empty()).then_some(Value::Object(public))),
        ("caller_label", text(&["caller", "label"])),
        ("principal_type", text(&["principal", "type"])),
        ("principal_id", text(&["principal", "id"])),
        ("principal_label", text(&["principal", "label"])),
        ("principal", pick("principal", &["type", "id", "label"])),
        ("auth_status", text(&["auth", "status"])),
        ("auth_method", text(&["auth", "method"])),
        ("request_method", text(&["request", "method"])),
        ("request_path", text(&["request", "path"])),
        ("request_route_name", text(&["request", "route_name"])),
        ("request_fingerprint", text(&["request", "fingerprint"])),
        ("request_id", request_id),
        ("correlation_id", text(&["request", "correlation_id"])),
    ] {
        if let Some(value) = value {
            fields.insert(field.into(), value);
        }
    }
    fields
}

/// `WorkerTerminalEventAttribution::record`: a worker's terminal completion or
/// failure names the worker that reported it.
pub(crate) fn worker_terminal(payload: &mut Value, event_type: &str, principal: &Principal) {
    let Some(identity) = server_principal(principal) else {
        return;
    };
    let mut command = payload["command"].as_object().cloned().unwrap_or_default();
    command.insert(
        "type".into(),
        json!(if event_type == "WorkflowCompleted" { "complete_workflow" } else { "fail_workflow" }),
    );
    command.insert("source".into(), json!("worker_protocol"));
    for (field, key) in [("principal_type", "type"), ("principal_id", "id"), ("principal_label", "label")] {
        match identity[key].as_str() {
            Some(value) => command.insert(field.into(), json!(value)),
            None => command.remove(field),
        };
    }
    command.retain(|_, value| !value.is_null() && value != "");
    payload["command"] = Value::Object(command);
}

#[cfg(test)]
mod tests {
    use super::*;

    fn principal(method: &'static str, subject: &str, roles: &[&str], claims: Value) -> Principal {
        Principal {
            subject: subject.into(),
            roles: roles.iter().map(|role| (*role).to_owned()).collect(),
            method,
            tenant: None,
            claims,
            legacy_full_access: false,
        }
    }

    #[test]
    fn principals_follow_the_factory() {
        let worker = principal("runtime-token", "svc:worker", &["worker"], json!({}));
        assert_eq!(server_principal(&worker), Some(json!({"type":"auth:runtime-token","id":"svc:worker","label":"Worker"})));
        let named = principal("token", "alice", &["operator"], json!({"name":" Alice ","email":"a@example.test"}));
        assert_eq!(server_principal(&named).unwrap()["label"], "Alice");
        let mut anonymous = principal("none", "anonymous", &["admin"], json!({}));
        anonymous.legacy_full_access = true;
        assert_eq!(server_principal(&anonymous), Some(json!({"type":"server","id":"anonymous","label":"Admin"})));
        assert_eq!(auth_status(&anonymous), "not_configured");
        assert_eq!(audit_context(&anonymous), json!({"subject":"anonymous","roles":["admin"],"legacy_full_access":true}));
        assert_eq!(server_principal(&principal("token", "  ", &["admin"], json!({}))), None);
    }

    #[test]
    fn fingerprints_hash_php_s_document() {
        let request = CommandRequest {
            method: "POST".into(),
            path: "/api/workflows".into(),
            ip: None,
            user_agent: None,
            request_id: Some("req-d1".into()),
            correlation_id: Some("corr-d1".into()),
            namespace: "default".into(),
            principal: principal("token", "legacy-token", &["admin"], json!({})),
        };
        // Published PHP 2.5.14 recorded this fingerprint for the same request.
        let payload = json!({"workflow_id":"ctxd-1","workflow_type":"CtxProbe","task_queue":"ctx-q","input":[1],
            "principal":"mallory","principal_id":"mallory","principal_type":"attacker","actor":"mallory","user":"mallory"});
        assert_eq!(request.request_metadata(&payload, None)["fingerprint"],
            "sha256:9f91ac2be4f1b6465385894ed899b4a52f3a15f92f0d6d1de2e2c1dd81f0340f");
    }

    #[test]
    fn worker_terminal_events_name_the_worker() {
        let worker = principal("runtime-token", "svc:worker", &["worker"], json!({}));
        let mut payload = json!({"output":"x"});
        worker_terminal(&mut payload, "WorkflowFailed", &worker);
        assert_eq!(payload["command"], json!({"type":"fail_workflow","source":"worker_protocol",
            "principal_type":"auth:runtime-token","principal_id":"svc:worker","principal_label":"Worker"}));
    }

    #[test]
    fn snapshots_carry_the_public_context_and_derived_fields() {
        let context = json!({"caller":{"type":"server","label":"Standalone Server"},
            "auth":{"status":"authorized","method":"token","role":"admin","roles":["admin"]},
            "request":{"method":"POST","path":"/api/workflows","fingerprint":"sha256:00","request_id":"body-id"},
            "principal":{"type":"auth:token","id":"legacy-token","label":"Admin"},"server":{"command":"start"}});
        let snapshot = snapshot(CommandColumns {
            id: "c1".into(), sequence: Some(1), message_sequence: None, command_type: Some("start".into()),
            target_scope: Some("instance".into()), requested_run_id: None, resolved_run_id: Some("r1".into()),
            target_name: None, payload_codec: Some("avro".into()), payload: Some("AAA=".into()),
            source: Some("control_plane".into()), context, request_id: None, status: Some("accepted".into()),
            outcome: Some("started_new".into()), rejection_reason: None, accepted_at: Some("t".into()),
            applied_at: Some("t".into()), rejected_at: None,
        });
        assert_eq!(snapshot["context"], json!({"principal":{"type":"auth:token","id":"legacy-token","label":"Admin"}}));
        assert_eq!(snapshot["payload"], json!({"codec":"avro","blob":"AAA="}));
        assert_eq!((snapshot["principal_id"].clone(), snapshot["request_id"].clone(), snapshot["caller_label"].clone()),
            (json!("legacy-token"), json!("body-id"), json!("Standalone Server")));
        assert!(snapshot.get("message_sequence").is_none() && snapshot.get("correlation_id").is_none());
    }
}
