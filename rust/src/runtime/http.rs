use axum::{
    Extension, Json, Router,
    extract::{DefaultBodyLimit, Path, Query, Request, State},
    body::Body,
    http::{HeaderMap, HeaderValue, Method, StatusCode, header},
    middleware::{self, Next},
    response::{IntoResponse, Response},
    routing::{delete, get, post},
};
use base64::{Engine, engine::general_purpose::STANDARD};
use serde::Deserialize;
use serde_json::{Value, json};
use subtle::ConstantTimeEq;

use super::command_context::CommandRequest;
use super::runtime_credentials::Principal;
use super::{Result, Runtime, cancellation::TerminalCommand, refuse, store::capabilities};

#[derive(Clone)]
pub(super) struct AuthenticatedPrincipal {
    pub(super) principal: super::runtime_credentials::Principal,
}

pub fn router(runtime: Runtime) -> Router {
    Router::new()
        .route("/api/health", get(health))
        .route("/api/ready", get(ready))
        .route("/api/cluster/info", get(cluster))
        .route(
            "/api/namespaces",
            get(list_namespaces).post(create_namespace),
        )
        .route(
            "/api/namespaces/{namespace}",
            get(describe_namespace).put(update_namespace),
        )
        .route(
            "/api/namespaces/{namespace}/external-storage",
            axum::routing::put(super::external_transport::set_policy),
        )
        .route(
            "/api/external-payloads/v1",
            post(super::external_transport::upload).layer(DefaultBodyLimit::disable()),
        )
        .route(
            "/api/external-payloads/v1/{reference_id}",
            get(super::external_transport::fetch),
        )
        .route("/api/storage/test", post(storage_test))
        .route(
            "/api/search-attributes",
            get(list_search_attributes).post(create_search_attribute),
        )
        .route("/api/runtime-credentials", get(list_runtime_credentials))
        .route(
            "/api/runtime-credentials/{credential_id}",
            get(show_runtime_credential)
                .put(upsert_runtime_credential)
                .delete(revoke_runtime_credential),
        )
        .route(
            "/api/runtime-credentials/{credential_id}/rotate",
            post(rotate_runtime_credential),
        )
        .route(
            "/api/search-attributes/{name}",
            delete(delete_search_attribute),
        )
        .route("/api/workflows", get(list_workflows).post(start))
        .route(
            "/api/schedules",
            get(super::schedule_list::list).post(super::schedule_http::create),
        )
        .route(
            "/api/schedules/{schedule_id}",
            get(super::schedule_http::describe)
                .put(super::schedule_http::update)
                .delete(super::schedule_http::remove),
        )
        .route(
            "/api/schedules/{schedule_id}/history",
            get(super::schedule_http::history),
        )
        .route(
            "/api/schedules/{schedule_id}/pause",
            post(super::schedule_http::pause),
        )
        .route(
            "/api/schedules/{schedule_id}/resume",
            post(super::schedule_http::resume),
        )
        .route(
            "/api/schedules/{schedule_id}/trigger",
            post(super::schedule_http::trigger),
        )
        .route(
            "/api/schedules/{schedule_id}/backfill",
            post(super::schedule_http::backfill),
        )
        .route("/api/workflows/{workflow_id}", get(describe_current))
        .route(
            "/api/workflows/{workflow_id}/observation",
            get(super::run_observation::current),
        )
        .route(
            "/api/workflows/{workflow_id}/runs/{run_id}/observation",
            get(super::run_observation::selected),
        )
        .route("/api/workflows/{workflow_id}/runs", get(list_runs))
        .route("/api/workflows/{workflow_id}/cancel", post(cancel_current))
        .route(
            "/api/workflows/{workflow_id}/terminate",
            post(terminate_current),
        )
        .route("/api/workflows/{workflow_id}/repair", post(repair_current))
        .route(
            "/api/workflows/{workflow_id}/runs/{run_id}/repair",
            post(repair_run),
        )
        .route("/api/workflows/{workflow_id}/debug", get(debug_current))
        .route(
            "/api/workflows/{workflow_id}/runs/{run_id}/debug",
            get(debug_run),
        )
        .route(
            "/api/workflows/{workflow_id}/runs/{run_id}/streams",
            get(super::workflow_streams::list),
        )
        .route(
            "/api/workflows/{workflow_id}/runs/{run_id}/streams/{stream_name}",
            get(super::workflow_streams::show),
        )
        .route(
            "/api/workflows/{workflow_id}/runs/{run_id}/streams/{stream_name}/items",
            get(super::workflow_streams::items),
        )
        .route(
            "/api/workflows/{workflow_id}/request-cancellation",
            post(request_cancellation_current),
        )
        .route(
            "/api/workflows/{workflow_id}/runs/{run_id}/request-cancellation",
            post(request_cancellation_run),
        )
        .route(
            "/api/workflows/{workflow_id}/runs/{run_id}/cancel",
            post(cancel_run),
        )
        .route(
            "/api/workflows/{workflow_id}/runs/{run_id}/terminate",
            post(terminate_run),
        )
        .route(
            "/api/workflows/{workflow_id}/query/{query_name}",
            post(query_current),
        )
        .route(
            "/api/workflows/{workflow_id}/update/{update_name}",
            post(update_current),
        )
        .route(
            "/api/workflows/{workflow_id}/runs/{run_id}/update/{update_name}",
            post(update_run),
        )
        .route(
            "/api/workflows/{workflow_id}/runs/{run_id}/query/{query_name}",
            post(query_run),
        )
        .route(
            "/api/workflows/{workflow_id}/signal/{signal_name}",
            post(signal_current),
        )
        .route(
            "/api/workflows/{workflow_id}/runs/{run_id}/signal/{signal_name}",
            post(signal_run),
        )
        .route(
            "/api/workflows/{workflow_id}/runs/{run_id}",
            get(describe_run),
        )
        .route(
            "/api/workflows/{workflow_id}/runs/{run_id}/history",
            get(history),
        )
        .route(
            "/api/workflows/{workflow_id}/runs/{run_id}/history/export",
            get(history_export),
        )
        .route(
            "/api/workflows/{workflow_id}/runs/{run_id}/activities",
            get(activities),
        )
        .route(
            "/api/workflows/{workflow_id}/runs/{run_id}/activities/{activity_id}/result",
            get(activity_result),
        )
        .route("/api/worker/register", post(register))
        .route("/api/worker/heartbeat", post(worker_heartbeat))
        .route("/api/worker/registrations/{worker_id}", delete(deregister))
        .route(
            "/api/worker/registrations/{worker_id}/deregister",
            post(deregister_fenced),
        )
        .route("/api/system/operator-metrics", get(operator_metrics))
        .route("/api/system/health", get(system_health))
        .route("/api/system/operator-dashboard", get(operator_dashboard))
        .route("/api/system/operator-dashboard/bounded", get(bounded_operator_dashboard))
        .route(
            "/api/system/operator-dashboard/bounded/workflow-types",
            get(workflow_type_operator_dashboard),
        )
        .route("/api/system/repair", get(repair_status))
        .route("/api/system/retention", get(retention_status))
        .route("/api/system/retention/pass", post(retention_pass))
        .route("/api/system/activity-timeouts", get(activity_timeout_status))
        .route("/api/system/activity-timeouts/pass", post(activity_timeout_pass))
        .route("/api/workers", get(list_workers))
        .route("/api/task-queues", get(list_task_queues))
        .route("/api/task-queues/{task_queue}", get(describe_task_queue))
        .route("/api/task-queues/{task_queue}/build-ids", get(task_queue_build_ids))
        .route(
            "/api/task-queues/{task_queue}/build-ids/promote",
            post(build_id_rollout_unqualified),
        )
        .route(
            "/api/task-queues/{task_queue}/build-ids/drain",
            post(build_id_rollout_unqualified),
        )
        .route(
            "/api/task-queues/{task_queue}/build-ids/resume",
            post(build_id_rollout_unqualified),
        )
        .route(
            "/api/workers/{worker_id}",
            get(describe_worker).delete(deregister),
        )
        .route("/api/worker/workflow-tasks/poll", post(poll_workflow))
        .route(
            "/api/worker/workflow-tasks/{task_id}/deliver-cancellation",
            post(deliver_cancellation),
        )
        .route(
            "/api/worker/workflow-tasks/{task_id}/heartbeat",
            post(heartbeat_task),
        )
        .route(
            "/api/worker/workflow-tasks/{task_id}/complete",
            post(complete_workflow),
        )
        .route(
            "/api/worker/workflow-tasks/{task_id}/fail",
            post(fail_workflow_task),
        )
        .route("/api/worker/activity-tasks/poll", post(poll_activity))
        .route(
            "/api/worker/activity-tasks/{task_id}/status",
            post(activity_status),
        )
        .route(
            "/api/worker/activity-tasks/{task_id}/heartbeat",
            post(heartbeat_activity),
        )
        .route(
            "/api/worker/activity-tasks/{task_id}/fail",
            post(fail_activity),
        )
        .route(
            "/api/worker/workflow-tasks/{task_id}/history",
            post(task_history),
        )
        .route(
            "/api/worker/activity-tasks/{task_id}/complete",
            post(complete_activity),
        )
        .route("/api/worker/query-tasks/poll", post(poll_query))
        .route(
            "/api/worker/query-tasks/{task_id}/status",
            get(query_status),
        )
        .route(
            "/api/worker/query-tasks/{task_id}/complete",
            post(complete_query),
        )
        .route("/api/worker/query-tasks/{task_id}/fail", post(fail_query))
        .fallback(unavailable)
        .layer(middleware::from_fn(empty_list_body))
        // `payload_limits` reads and bounds every body first.
        .layer(DefaultBodyLimit::disable())
        .layer(middleware::from_fn_with_state(runtime.clone(), guard))
        .layer(middleware::from_fn(super::payload_limits::enforce))
        .with_state(runtime)
}

/// Laravel reads a JSON `[]` body as the same empty input as `{}`. Clients
/// such as the CLI send `[]` for a command without fields.
async fn empty_list_body(request: Request, next: Next) -> Response {
    let declared = request
        .headers()
        .get(header::CONTENT_LENGTH)
        .and_then(|length| length.to_str().ok())
        .and_then(|length| length.parse::<u64>().ok());
    let known = declared.or_else(|| axum::body::HttpBody::size_hint(request.body()).exact());
    let short = known.is_some_and(|length| length <= 16);
    if !short {
        return next.run(request).await;
    }
    let (mut parts, body) = request.into_parts();
    let Ok(bytes) = axum::body::to_bytes(body, 16).await else {
        return StatusCode::BAD_REQUEST.into_response();
    };
    let body = if bytes.trim_ascii() == b"[]" {
        parts.headers.insert(header::CONTENT_LENGTH, HeaderValue::from_static("2"));
        Body::from("{}")
    } else {
        Body::from(bytes)
    };
    next.run(Request::from_parts(parts, body)).await
}

async fn guard(State(runtime): State<Runtime>, mut request: Request, next: Next) -> Response {
    request.extensions_mut().insert(runtime.clone());
    let path = request.uri().path().to_owned();
    if path == "/api/health" || path == "/api/ready" {
        return next.run(request).await;
    }
    let worker = path == "/api/worker" || path.starts_with("/api/worker/");
    let payload =
        path == "/api/external-payloads/v1" || path.starts_with("/api/external-payloads/v1/");
    let authorization = request
        .headers()
        .get("authorization")
        .and_then(|v| v.to_str().ok());
    let provided = authorization.and_then(|v| v.strip_prefix("Bearer "));
    let roles = ["worker", "operator", "admin"];
    let configured = provided.and_then(|provided| {
        // Match configured roles before the legacy credential, matching PHP's
        // collision precedence. Equal-length secret contents use constant-time
        // comparison; secrets never enter response or diagnostic values.
        for (secret, role) in runtime.role_tokens.iter().zip(roles) {
            if secret
                .as_ref()
                .is_some_and(|secret| bool::from(provided.as_bytes().ct_eq(secret.as_bytes())))
            {
                return Some((Principal::role(role, "token", false), false));
            }
        }
        bool::from(provided.as_bytes().ct_eq(runtime.token.as_bytes())).then(|| {
            let mut principal = Principal::role(
                "admin",
                "token",
                runtime.role_tokens.iter().all(Option::is_none),
            );
            principal.subject = "legacy-token".into();
            principal.claims = json!({"legacy_credential": true});
            (principal, true)
        })
    });
    // `ConfiguredAuthProvider::authenticate`: the none driver admits every
    // request as the anonymous administrator; otherwise database runtime
    // credentials follow the configured tokens.
    let authenticated = if runtime.auth.anonymous {
        let mut principal = Principal::role("admin", "none", true);
        principal.subject = "anonymous".into();
        Some((principal, false))
    } else if configured.is_some() {
        configured
    } else if let (true, Some(provided)) = (runtime.auth.runtime_credentials, provided) {
        match runtime.runtime_credential_principal(provided).await {
            Ok(principal) => principal.map(|principal| (principal, false)),
            Err(_) => {
                return admission_error(request, worker, StatusCode::INTERNAL_SERVER_ERROR,
                    json!({"reason":"server_error",
                        "message":"Database runtime credential authentication is enabled but unavailable."})).await;
            }
        }
    } else {
        None
    };
    let Some((principal, _legacy_credential)) = authenticated else {
        if payload {
            return admission_error(
                request,
                false,
                StatusCode::UNAUTHORIZED,
                super::external_transport::error_body(
                    StatusCode::UNAUTHORIZED,
                    "external_payload_unauthorized",
                ),
            )
            .await;
        }
        return admission_error(
            request,
            worker,
            StatusCode::UNAUTHORIZED,
            json!({"reason":"unauthorized", "message":"Invalid or missing authentication token."}),
        )
        .await;
    };
    let allowed_roles: &[&str] = if path == "/api/external-payloads/v1"
        && request.method() == Method::POST
        && request
            .headers()
            .contains_key("x-durable-workflow-payload-completion")
    {
        &["worker"]
    } else if payload || path == "/api/worker/register" || path == "/api/cluster/info" {
        &["worker", "operator", "admin"]
    } else if worker {
        &["worker"]
    } else if path.starts_with("/api/workers/") && request.method() != Method::GET
        || path == "/api/namespaces" && request.method() == Method::POST
        || path == "/api/storage/test"
        // Updating a namespace or its external storage needs an admin.
        || path.starts_with("/api/namespaces/") && request.method() == Method::PUT
        // PHP's system routes are admin-only.
        || path.starts_with("/api/system/")
        || path == "/api/runtime-credentials"
        || path.starts_with("/api/runtime-credentials/")
    {
        &["admin"]
    } else {
        &["operator", "admin"]
    };
    // `RouteAuthorizationResource`: the requested namespace, and the target
    // of a namespace route.
    let requested_namespace = match request.headers().get("x-namespace") {
        Some(header) => header.to_str().unwrap_or("").to_lowercase(),
        None => Query::<std::collections::HashMap<String, String>>::try_from_uri(request.uri())
            .ok()
            .and_then(|Query(fields)| fields.get("namespace").cloned())
            .unwrap_or_else(|| "default".into())
            .to_lowercase(),
    };
    let namespace_family = path == "/api/namespaces" || path.starts_with("/api/namespaces/");
    // A namespace route names its target in the path, or else in `name`.
    // PHP also reads `name` from a JSON body; only creating a namespace sends
    // one, and that needs an admin, which native never binds to a tenant.
    let target_namespace = match path.strip_prefix("/api/namespaces/").and_then(|rest| rest.split('/').next()) {
        Some(segment) if !segment.is_empty() => Some(segment.trim().to_lowercase()),
        _ if namespace_family => Query::<std::collections::HashMap<String, String>>::try_from_uri(request.uri())
            .ok()
            .and_then(|Query(fields)| fields.get("name").map(|name| name.trim().to_lowercase()))
            .filter(|name| !name.is_empty()),
        _ => None,
    };
    if !principal.allows(allowed_roles, &requested_namespace, target_namespace.as_deref(), namespace_family) {
        return admission_error(request, worker, StatusCode::FORBIDDEN, json!({
            "reason":"forbidden", "message":"Authenticated role is not allowed to access this endpoint.",
            "role":principal.primary_role(), "allowed_roles":allowed_roles,
        })).await;
    }
    let header = if worker {
        "x-durable-workflow-protocol-version"
    } else {
        "x-durable-workflow-control-plane-version"
    };
    let version = if worker { super::WORKER_PROTOCOL_VERSION } else { "2" };
    let requested = request
        .headers()
        .get(header)
        .and_then(|v| v.to_str().ok())
        .map(str::trim)
        .filter(|v| !v.is_empty());
    let compatible = if worker {
        requested.is_some_and(|v| {
            v.split_once('.').is_some_and(|(major, minor)| {
                major == "1"
                    && !minor.is_empty()
                    && minor.bytes().all(|c| c.is_ascii_digit())
                    && minor.parse::<u8>().is_ok_and(|n| n <= 20)
            })
        })
    } else {
        requested == Some(version) || path == "/api/cluster/info" && requested.is_none()
    };
    if !compatible && !payload {
        let missing = requested.is_none();
        let reason = match (worker, missing) {
            (true, true) => "missing_protocol_version",
            (true, false) => "unsupported_protocol_version",
            (false, true) => "missing_control_plane_version",
            (false, false) => "unsupported_control_plane_version",
        };
        let diagnostic = match (worker, missing) {
            (true, true) => "Missing worker protocol version header.",
            (true, false) => "Unsupported worker protocol version.",
            (false, true) => "Missing control-plane version header.",
            (false, false) => "Unsupported control-plane version.",
        };
        let remediation = if missing {
            if worker {
                "Send the X-Durable-Workflow-Protocol-Version: 1.20 header on worker protocol requests.".to_owned()
            } else {
                "Send the X-Durable-Workflow-Control-Plane-Version: 2 header on control-plane requests.".to_owned()
            }
        } else if worker {
            format!(
                "Worker requested protocol version {}; this server supports 1.20. Workers may target any 1.x version with x ≤ 20. Upgrade the worker to a release that targets a compatible version, or connect to a server that matches.",
                requested.unwrap()
            )
        } else {
            format!(
                "Client requested control-plane version {}; this server only supports 2. Upgrade the client to a release that targets control-plane 2, or connect to a server that supports {}.",
                requested.unwrap(),
                requested.unwrap()
            )
        };
        let mut body = json!({"reason":reason, "supported_version":version, "requested_version":requested, "remediation":remediation});
        body[if worker { "error" } else { "message" }] = json!(diagnostic);
        return admission_error(request, worker, StatusCode::BAD_REQUEST, body).await;
    }
    // Namespace resolution must precede every runtime mutation. A header wins
    // over the query selector; neither may silently select the default peer.
    if path != "/api/cluster/info"
        && path != "/api/namespaces"
        && !path.starts_with("/api/namespaces/")
    {
        let namespace = if let Some(header) = request.headers().get("x-namespace") {
            header.to_str().unwrap_or("").to_ascii_lowercase()
        } else {
            let fields =
                Query::<std::collections::HashMap<String, String>>::try_from_uri(request.uri());
            match fields {
                Ok(Query(fields)) if !fields.keys().any(|key| key.starts_with("namespace[")) =>
                    fields.get("namespace").map(String::as_str).unwrap_or("default").to_ascii_lowercase(),
                // Non-scalar or malformed selectors are outside this bounded
                // contract; refuse them without reaching default-namespace state.
                _ => return admission_error(request, worker, StatusCode::BAD_REQUEST,
                    json!({"reason":"invalid_namespace", "message":"Namespace must be a scalar string."})).await,
            }
        };
        let exists = match runtime.namespace_exists(&namespace).await {
            Ok(exists) => exists,
            Err(error) => return error.into_response(),
        };
        if !exists {
            let body = json!({"reason":"namespace_not_found", "namespace":namespace,
                "message":format!("Namespace '{namespace}' does not exist."),
                "remediation":"Register the namespace via POST /api/namespaces, or send an X-Namespace header naming an existing namespace."});
            return admission_error(request, worker, StatusCode::NOT_FOUND, body).await;
        }
        if namespace != "default" && !named_namespace_operation(request.method().as_str(), &path) {
            return admission_error(request,worker,StatusCode::UNPROCESSABLE_ENTITY,
                json!({"reason":"development_namespace_operation_unqualified","namespace":namespace,
                    "message":"This operation is not implemented for named namespaces in the unpublished execution slice."})).await;
        }
        request
            .extensions_mut()
            .insert(runtime.in_namespace(&namespace));
    }
    request.extensions_mut().insert(AuthenticatedPrincipal {
        principal,
    });
    let mut response = next.run(request).await;
    response
        .headers_mut()
        .insert(header, HeaderValue::from_static(version));
    response
}

fn named_namespace_operation(method: &str, path: &str) -> bool {
    // Runtime credentials are server-wide; the namespace only scopes a tenant.
    if path == "/api/runtime-credentials" || path.starts_with("/api/runtime-credentials/") {
        return true;
    }
    if path == "/api/external-payloads/v1" {
        return method == "POST";
    }
    if path.starts_with("/api/external-payloads/v1/") {
        return method == "GET";
    }
    // Schedules are namespace-scoped rows, as in PHP.
    if path == "/api/schedules" || path.starts_with("/api/schedules/") {
        return true;
    }
    // Keep unimplemented families from reaching default-namespace selectors.
    // Further families require their own shared namespace fixtures.
    if path == "/api/workflows" {
        return matches!(method, "GET" | "POST");
    }
    if path == "/api/storage/test" {
        return method == "POST";
    }
    // The registry is namespace-scoped state with no default-namespace selector.
    if path == "/api/search-attributes" {
        return matches!(method, "GET" | "POST");
    }
    if path
        .strip_prefix("/api/search-attributes/")
        .is_some_and(|name| !name.is_empty() && !name.contains('/'))
    {
        return method == "DELETE";
    }
    // Deregistration deletes only the namespace's own registration.
    if path
        .strip_prefix("/api/workers/")
        .is_some_and(|id| !id.is_empty() && !id.contains('/'))
    {
        return matches!(method, "GET" | "DELETE");
    }
    // Task queue views are namespace-scoped reads.
    if path == "/api/task-queues"
        || path
            .strip_prefix("/api/task-queues/")
            .is_some_and(|queue| !queue.is_empty() && !queue.contains('/'))
    {
        return method == "GET";
    }
    // Build-id cohorts are a namespace-scoped read too. Rollout changes are
    // refused alike in every namespace.
    if let Some(rest) = path.strip_prefix("/api/task-queues/") {
        match rest.split('/').collect::<Vec<_>>().as_slice() {
            [queue, "build-ids"] if !queue.is_empty() => return method == "GET",
            [queue, "build-ids", "promote" | "drain" | "resume"] if !queue.is_empty() => {
                return method == "POST";
            }
            _ => {}
        }
    }
    if path == "/api/workers"
        || path == "/api/system/operator-metrics"
        || path == "/api/system/health"
        || path == "/api/system/operator-dashboard"
        || path == "/api/system/operator-dashboard/bounded"
        || path == "/api/system/operator-dashboard/bounded/workflow-types"
        // Retention reads and prunes the namespace's own runs; the activity
        // timeout scan spans namespaces in PHP too.
        || path == "/api/system/retention"
        || path == "/api/system/activity-timeouts"
        || path == "/api/system/repair"
    {
        return method == "GET";
    }
    if path == "/api/system/retention/pass" || path == "/api/system/activity-timeouts/pass" {
        return method == "POST";
    }
    if let Some(path) = path.strip_prefix("/api/workflows/") {
        let parts: Vec<_> = path.split('/').collect();
        if matches!(
            parts.as_slice(),
            [_, "observation"] | [_, "runs", _, "observation"]
        ) {
            return method == "GET";
        }
        if matches!(parts.as_slice(), [_, "runs", _, "activities", _, "result"]) {
            return method == "GET";
        }
        // Waterline's run detail: the run list, debug view and history export.
        // Each reads the instance within the request's namespace first.
        if matches!(
            parts.as_slice(),
            [_, "runs"] | [_, "debug"] | [_, "runs", _, "debug"] | [_, "runs", _, "history", "export"]
        ) {
            return method == "GET";
        }
        if matches!(
            parts.as_slice(),
            [_, "runs", _, "streams"] | [_, "runs", _, "streams", _] | [_, "runs", _, "streams", _, "items"]
        ) {
            return method == "GET";
        }
        if matches!(
            parts.as_slice(),
            [_, "signal", _] | [_, "runs", _, "signal", _]
        ) {
            return method == "POST";
        }
        if matches!(
            parts.as_slice(),
            [_, "query", _] | [_, "runs", _, "query", _]
        ) {
            return method == "POST";
        }
        return matches!(
            parts.as_slice(),
            [_] | [_, "cancel" | "terminate"]
                | [_, "runs", _]
                | [
                    _,
                    "runs",
                    _,
                    "history" | "cancel" | "terminate" | "activities"
                ]
        );
    }
    if let Some(path) = path.strip_prefix("/api/worker/") {
        let parts: Vec<_> = path.split('/').collect();
        return matches!(
            parts.as_slice(),
            ["register" | "heartbeat"]
                | ["registrations", _]
                | ["registrations", _, "deregister"]
                | ["workflow-tasks" | "activity-tasks", "poll"]
                | ["query-tasks", "poll"]
                | ["query-tasks", _, "complete" | "fail" | "status"]
                | ["workflow-tasks", _, "complete" | "history" | "heartbeat"]
                | [
                    "activity-tasks",
                    _,
                    "complete" | "fail" | "heartbeat" | "status"
                ]
        );
    }
    false
}

async fn list_namespaces(Extension(runtime): Extension<Runtime>) -> Result<Json<Value>> {
    Ok(Json(runtime.list_namespaces().await?))
}
async fn describe_namespace(
    Extension(runtime): Extension<Runtime>,
    Path(namespace): Path<String>,
) -> Result<Json<Value>> {
    Ok(Json(runtime.describe_namespace(&namespace).await?))
}
async fn update_namespace(
    Extension(runtime): Extension<Runtime>,
    Path(namespace): Path<String>,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    Ok(Json(runtime.update_namespace(&namespace, body).await?))
}
async fn create_namespace(
    Extension(runtime): Extension<Runtime>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    Ok((
        StatusCode::CREATED,
        Json(runtime.create_namespace(body).await?),
    ))
}

async fn storage_test(
    Extension(runtime): Extension<Runtime>,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    Ok(Json(super::storage_test::storage_test(&runtime, body).await?))
}

async fn list_runtime_credentials(Extension(runtime): Extension<Runtime>) -> Result<Json<Value>> {
    Ok(Json(runtime.list_runtime_credentials().await?))
}

async fn show_runtime_credential(
    Extension(runtime): Extension<Runtime>,
    Path(credential_id): Path<String>,
) -> Result<Json<Value>> {
    Ok(Json(runtime.show_runtime_credential(&credential_id).await?))
}

async fn upsert_runtime_credential(
    Extension(runtime): Extension<Runtime>,
    Path(credential_id): Path<String>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    let (status, body) = runtime.upsert_runtime_credential(&credential_id, body).await?;
    Ok((status, Json(body)))
}

async fn rotate_runtime_credential(
    Extension(runtime): Extension<Runtime>,
    Path(credential_id): Path<String>,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    Ok(Json(runtime.rotate_runtime_credential(&credential_id, body).await?))
}

async fn revoke_runtime_credential(
    Extension(runtime): Extension<Runtime>,
    Path(credential_id): Path<String>,
) -> Result<Json<Value>> {
    Ok(Json(runtime.revoke_runtime_credential(&credential_id).await?))
}

async fn list_search_attributes(Extension(runtime): Extension<Runtime>) -> Result<Json<Value>> {
    Ok(Json(runtime.list_search_attributes().await?))
}
async fn create_search_attribute(
    Extension(runtime): Extension<Runtime>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    Ok((
        StatusCode::CREATED,
        Json(runtime.create_search_attribute(body).await?),
    ))
}
async fn delete_search_attribute(
    Extension(runtime): Extension<Runtime>,
    Path(name): Path<String>,
) -> Result<Json<Value>> {
    Ok(Json(runtime.delete_search_attribute(&name).await?))
}

pub(super) async fn admission_error(
    request: Request,
    worker: bool,
    status: StatusCode,
    mut body: Value,
) -> Response {
    let (header, version) = if worker {
        body["protocol_version"] = json!(super::WORKER_PROTOCOL_VERSION);
        body["server_capabilities"] = capabilities();
        ("x-durable-workflow-protocol-version", super::WORKER_PROTOCOL_VERSION)
    } else {
        body = super::control_plane::admission_response(request, body).await;
        ("x-durable-workflow-control-plane-version", "2")
    };
    let mut response = (status, Json(body)).into_response();
    response
        .headers_mut()
        .insert(header, HeaderValue::from_static(version));
    response
}

async fn health(Extension(runtime): Extension<Runtime>) -> Response {
    let database = runtime.database_live().await;
    (if database { StatusCode::OK } else { StatusCode::SERVICE_UNAVAILABLE },
        Json(json!({"status": if database { "serving" } else { "degraded" }, "checks": {"database": database}}))).into_response()
}

async fn ready(Extension(runtime): Extension<Runtime>) -> Response {
    let ready = runtime.schema_ready().await;
    let mut database = json!({"status": if ready { "ok" } else { "failed" },
        "mode": "schema_read", "write_capacity_verified": false});
    // As PHP does, only a reachable database reports what its TLS guarantees.
    if ready {
        database["tls"] = crate::database_tls::current();
    }
    (
        if ready {
            StatusCode::OK
        } else {
            StatusCode::SERVICE_UNAVAILABLE
        },
        Json(json!({
        "status": if ready { "ready" } else { "not_ready" }, "development": true,
        "checks": {"database": database},
        "qualification": "unpublished_execution_slice"})),
    )
        .into_response()
}

async fn cluster() -> Json<Value> {
    let auth_composition: Value = serde_json::from_str(include_str!("auth-composition.v1.json"))
        .expect("valid bundled carrier auth composition contract");
    // Published PHP's `PrincipalAttributionContract::manifest()`, identical in
    // Server 2.5.14, 2.10.0 and 2.11.0. Native records the authenticated
    // principal as it describes.
    let principal_attribution: Value =
        serde_json::from_str(include_str!("principal-attribution-contract.v2.json"))
            .expect("valid bundled principal attribution contract");
    Json(
        json!({"version": env!("CARGO_PKG_VERSION"), "implementation": "rust", "development": true,
        "control_plane": {"version": "2", "request_contract": super::visibility::request_contract()}, "worker_protocol": {"version": super::WORKER_PROTOCOL_VERSION, "server_capabilities": capabilities()},
        "auth_composition_contract": auth_composition,
        "principal_attribution_contract": principal_attribution,
        "payload_codec": "avro", "qualified_for_php_takeover": false}),
    )
}

async fn activity_result(
    Extension(runtime): Extension<Runtime>,
    Path((workflow_id, run_id, activity_id)): Path<(String, String, String)>,
) -> Result<Json<Value>> {
    Ok(Json(
        runtime
            .activity_result(&workflow_id, &run_id, &activity_id)
            .await?,
    ))
}

/// PHP Server admits an optional top-level caller trace context on start,
/// signal, update and cancellation requests when its tracing is enabled.
/// Native has no carrier and never advertises one, so it drops that one field
/// unread, as PHP does with tracing disabled. The same name anywhere else
/// stays ordinary request data, and other unknown fields are still refused.
fn without_trace_carrier(mut body: Value) -> Value {
    if let Some(fields) = body.as_object_mut() {
        fields.remove("observability_context");
    }
    body
}

/// The operation fields PHP records in a command context, without nulls;
/// `[]` when there are none.
fn metadata(fields: &[(&str, Option<Value>)]) -> Value {
    let fields: serde_json::Map<String, Value> = fields
        .iter()
        .filter_map(|(name, value)| value.clone().filter(|value| !value.is_null()).map(|value| ((*name).to_owned(), value)))
        .collect();
    if fields.is_empty() { json!([]) } else { Value::Object(fields) }
}

fn present(body: &Value, field: &str) -> Option<Value> {
    body.get(field).filter(|value| !value.is_null()).cloned()
}

fn signal_context(request: &CommandRequest, body: &Value, workflow_id: &str, name: &str) -> Value {
    request.context(body, workflow_id, "signal", metadata(&[
        ("request_id", present(body, "request_id")),
        ("signal_name", Some(json!(name))),
    ]))
}

fn update_context(request: &CommandRequest, body: &Value, workflow_id: &str, name: &str) -> Value {
    request.context(body, workflow_id, "update", metadata(&[
        ("request_id", present(body, "request_id")),
        ("update_name", Some(json!(name))),
        ("wait_for", Some(present(body, "wait_for").unwrap_or(json!("accepted")))),
    ]))
}

async fn start(
    Extension(runtime): Extension<Runtime>,
    command: CommandRequest,
    Json(body): Json<super::memo_json::StartBody>,
) -> Result<(StatusCode, Json<Value>)> {
    // PHP names the target `pending` until the start assigns an id.
    let target = body.0["workflow_id"].as_str().filter(|id| !id.is_empty()).unwrap_or("pending");
    let queue = body.0.get("task_queue").filter(|queue| !queue.is_null()).cloned().unwrap_or(json!("default"));
    let context = command.context(&body.0, target, "start", metadata(&[
        ("workflow_type", body.0.get("workflow_type").cloned()),
        ("task_queue", Some(queue)),
        ("duplicate_policy", body.0.get("duplicate_policy").cloned()),
    ]));
    let body = without_trace_carrier(body.0);
    let workflow_id = body["workflow_id"].as_str().unwrap_or("").to_owned();
    let result = runtime.start(body, Some(context)).await.map_err(|error| {
        super::control_plane::Operation::Start.refusal(error, &workflow_id, None)
    })?;
    // Clients such as the CLI require the control-plane contract on every
    // start response, as PHP sends it.
    let status = match result["outcome"].as_str() {
        Some("started_new") => StatusCode::CREATED,
        Some("rejected_duplicate") => StatusCode::CONFLICT,
        _ => StatusCode::OK,
    };
    Ok((status, Json(super::control_plane::Operation::Start.response(result))))
}

async fn list_workflows(
    Extension(runtime): Extension<Runtime>,
    Query(query): Query<super::visibility::VisibilityQuery>,
) -> Result<Json<Value>> {
    Ok(Json(runtime.list_workflows(query.validate()?).await?))
}

async fn describe_current(
    Extension(runtime): Extension<Runtime>,
    Path(workflow_id): Path<String>,
) -> Result<Json<Value>> {
    let body = runtime
        .describe(&workflow_id, None)
        .await
        .map_err(|error| {
            super::control_plane::Operation::Describe.refusal(error, &workflow_id, None)
        })?;
    Ok(Json(
        super::control_plane::Operation::Describe.response(body),
    ))
}

async fn list_runs(
    Extension(runtime): Extension<Runtime>,
    Path(workflow): Path<String>,
) -> Result<Json<Value>> {
    let operation = || super::control_plane::Operation::ListRuns;
    let runs = runtime
        .list_runs(&workflow)
        .await
        .map_err(|error| operation().refusal(error, &workflow, None))?;
    Ok(Json(operation().response(runs)))
}

async fn close(
    runtime: Runtime,
    request: CommandRequest,
    command: TerminalCommand,
    workflow_id: &str,
    run_id: Option<&str>,
    body: Value,
) -> Result<(StatusCode, Json<Value>)> {
    let operation = || match command {
        TerminalCommand::Cancel => super::control_plane::Operation::Cancel,
        TerminalCommand::Terminate => super::control_plane::Operation::Terminate,
    };
    let name = match command {
        TerminalCommand::Cancel => "cancel",
        TerminalCommand::Terminate => "terminate",
    };
    let context = request.context(&body, workflow_id, name, metadata(&[
        ("request_id", present(&body, "request_id")),
        ("reason", present(&body, "reason")),
    ]));
    // Cancellation ignores a top-level trace carrier; termination keeps its
    // existing request handling.
    let body = match command {
        TerminalCommand::Cancel => without_trace_carrier(body),
        TerminalCommand::Terminate => body,
    };
    let (status, response) = runtime
        .close_workflow(command, workflow_id, run_id, body, Some(context))
        .await
        .map_err(|error| operation().refusal(error, workflow_id, run_id))?;
    Ok((status, Json(operation().response(response))))
}

async fn cancel_current(
    Extension(runtime): Extension<Runtime>,
    request: CommandRequest,
    Path(workflow_id): Path<String>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    close(runtime, request, TerminalCommand::Cancel, &workflow_id, None, body).await
}

async fn terminate_current(
    Extension(runtime): Extension<Runtime>,
    request: CommandRequest,
    Path(workflow_id): Path<String>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    close(
        runtime,
        request,
        TerminalCommand::Terminate,
        &workflow_id,
        None,
        body,
    )
    .await
}

async fn repair_current(
    Extension(runtime): Extension<Runtime>,
    Path(workflow_id): Path<String>,
    request: Request,
) -> Result<(StatusCode, Json<Value>)> {
    let (body, context) = super::task_repair_http::request(request, &workflow_id).await?;
    let (status, response) = runtime
        .repair_workflow(&workflow_id, None, body, context)
        .await
        .map_err(|error| {
            super::control_plane::Operation::Repair.refusal(error, &workflow_id, None)
        })?;
    Ok((status, Json(super::control_plane::Operation::Repair.response(response))))
}

/// Laravel's `$request->boolean('include_last_event_payload')`.
fn include_last_event_payload(query: &std::collections::HashMap<String, String>) -> bool {
    query
        .get("include_last_event_payload")
        .is_some_and(|value| matches!(value.as_str(), "1" | "true" | "on" | "yes"))
}

async fn debug_current(
    Extension(runtime): Extension<Runtime>,
    Path(workflow_id): Path<String>,
    Query(query): Query<std::collections::HashMap<String, String>>,
) -> Result<Json<Value>> {
    let response = runtime
        .run_diagnostics(&workflow_id, None, include_last_event_payload(&query))
        .await
        .map_err(|error| {
            super::control_plane::Operation::Debug.refusal(error, &workflow_id, None)
        })?;
    Ok(Json(
        super::control_plane::Operation::Debug.response(response),
    ))
}

async fn debug_run(
    Extension(runtime): Extension<Runtime>,
    Path((workflow_id, run_id)): Path<(String, String)>,
    Query(query): Query<std::collections::HashMap<String, String>>,
) -> Result<Json<Value>> {
    let response = runtime
        .run_diagnostics(&workflow_id, Some(&run_id), include_last_event_payload(&query))
        .await
        .map_err(|error| {
            super::control_plane::Operation::Debug.refusal(error, &workflow_id, Some(&run_id))
        })?;
    Ok(Json(
        super::control_plane::Operation::Debug.response(response),
    ))
}

async fn repair_run(
    Extension(runtime): Extension<Runtime>,
    Path((workflow_id, run_id)): Path<(String, String)>,
    request: Request,
) -> Result<(StatusCode, Json<Value>)> {
    let (body, context) = super::task_repair_http::request(request, &workflow_id).await?;
    let (status, response) = runtime
        .repair_workflow(&workflow_id, Some(&run_id), body, context)
        .await
        .map_err(|error| {
            super::control_plane::Operation::Repair.refusal(error, &workflow_id, Some(&run_id))
        })?;
    Ok((status, Json(super::control_plane::Operation::Repair.response(response))))
}

async fn cancel_run(
    Extension(runtime): Extension<Runtime>,
    request: CommandRequest,
    Path((workflow_id, run_id)): Path<(String, String)>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    close(
        runtime,
        request,
        TerminalCommand::Cancel,
        &workflow_id,
        Some(&run_id),
        body,
    )
    .await
}

async fn terminate_run(
    Extension(runtime): Extension<Runtime>,
    request: CommandRequest,
    Path((workflow_id, run_id)): Path<(String, String)>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    close(
        runtime,
        request,
        TerminalCommand::Terminate,
        &workflow_id,
        Some(&run_id),
        body,
    )
    .await
}

async fn request_cancellation_current(
    Extension(runtime): Extension<Runtime>,
    request: CommandRequest,
    Path(workflow_id): Path<String>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    let context = request.context(&body, &workflow_id, "request_cancellation", json!([]));
    let (status, response) = runtime
        .request_cancellation(&workflow_id, None, without_trace_carrier(body), Some(context))
        .await?;
    Ok((status, Json(response)))
}

async fn request_cancellation_run(
    Extension(runtime): Extension<Runtime>,
    request: CommandRequest,
    Path((workflow_id, run_id)): Path<(String, String)>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    let context = request.context(&body, &workflow_id, "request_cancellation", json!([]));
    let (status, response) = runtime
        .request_cancellation(&workflow_id, Some(&run_id), without_trace_carrier(body), Some(context))
        .await?;
    Ok((status, Json(response)))
}

async fn signal_current(
    Extension(runtime): Extension<Runtime>,
    request: CommandRequest,
    Path((workflow_id, name)): Path<(String, String)>,
    Json(super::memo_json::StartBody(body)): Json<super::memo_json::StartBody>,
) -> Result<(StatusCode, Json<Value>)> {
    let context = signal_context(&request, &body, &workflow_id, &name);
    let response = runtime
        .signal(&workflow_id, None, &name, without_trace_carrier(body), Some(context))
        .await
        .map_err(|error| {
            super::control_plane::Operation::Signal(&name).refusal(error, &workflow_id, None)
        })?;
    Ok((
        StatusCode::ACCEPTED,
        Json(super::control_plane::Operation::Signal(&name).response(response)),
    ))
}

async fn update_current(
    Extension(runtime): Extension<Runtime>,
    request: CommandRequest,
    Path((workflow_id, name)): Path<(String, String)>,
    Json(super::memo_json::StartBody(body)): Json<super::memo_json::StartBody>,
) -> Result<(StatusCode, Json<Value>)> {
    let context = update_context(&request, &body, &workflow_id, &name);
    let (status, result) = runtime
        .update_workflow(&workflow_id, None, &name, without_trace_carrier(body), Some(context))
        .await
        .map_err(|error| super::control_plane::Operation::Update(&name).refusal(error, &workflow_id, None))?;
    Ok((status, Json(result)))
}

async fn update_run(
    Extension(runtime): Extension<Runtime>,
    request: CommandRequest,
    Path((workflow_id, run_id, name)): Path<(String, String, String)>,
    Json(super::memo_json::StartBody(body)): Json<super::memo_json::StartBody>,
) -> Result<(StatusCode, Json<Value>)> {
    let context = update_context(&request, &body, &workflow_id, &name);
    let (status, result) = runtime
        .update_workflow(&workflow_id, Some(&run_id), &name, without_trace_carrier(body), Some(context))
        .await
        .map_err(|error| {
            super::control_plane::Operation::Update(&name).refusal(error, &workflow_id, Some(&run_id))
        })?;
    Ok((status, Json(result)))
}

async fn signal_run(
    Extension(runtime): Extension<Runtime>,
    request: CommandRequest,
    Path((workflow_id, run_id, name)): Path<(String, String, String)>,
    Json(super::memo_json::StartBody(body)): Json<super::memo_json::StartBody>,
) -> Result<(StatusCode, Json<Value>)> {
    let context = signal_context(&request, &body, &workflow_id, &name);
    let response = runtime
        .signal(&workflow_id, Some(&run_id), &name, without_trace_carrier(body), Some(context))
        .await
        .map_err(|error| {
            super::control_plane::Operation::Signal(&name).refusal(
                error,
                &workflow_id,
                Some(&run_id),
            )
        })?;
    Ok((
        StatusCode::ACCEPTED,
        Json(super::control_plane::Operation::Signal(&name).response(response)),
    ))
}

async fn describe_run(
    Extension(runtime): Extension<Runtime>,
    Path((workflow_id, run_id)): Path<(String, String)>,
) -> Result<Json<Value>> {
    let body = runtime
        .describe(&workflow_id, Some(&run_id))
        .await
        .map_err(|error| {
            super::control_plane::Operation::DescribeRun.refusal(error, &workflow_id, Some(&run_id))
        })?;
    Ok(Json(
        super::control_plane::Operation::DescribeRun.response(body),
    ))
}

#[derive(Deserialize)]
struct HistoryQuery {
    page_size: Option<i64>,
    next_page_token: Option<String>,
}

async fn activities(
    Extension(runtime): Extension<Runtime>,
    Path((workflow_id, run_id)): Path<(String, String)>,
) -> Result<Json<Value>> {
    runtime.activities(&workflow_id, &run_id).await.map(Json)
}

/// `HistoryController::export`: the run's replay bundle.
async fn history_export(
    Extension(runtime): Extension<Runtime>,
    Path((workflow_id, run_id)): Path<(String, String)>,
) -> Result<Json<Value>> {
    runtime.history_export(&workflow_id, &run_id).await.map(Json).map_err(|error| {
        super::control_plane::Operation::History.refusal(error, &workflow_id, Some(&run_id))
    })
}

async fn history(
    Extension(runtime): Extension<Runtime>,
    Path((workflow_id, run_id)): Path<(String, String)>,
    Query(query): Query<HistoryQuery>,
) -> Result<Json<Value>> {
    let page_size = query.page_size.unwrap_or(100);
    if !(1..=1000).contains(&page_size) {
        return Err(refuse(
            StatusCode::UNPROCESSABLE_ENTITY,
            "invalid_page_size",
        ));
    }
    // Match the current public history cursor's sequence/base64 contract.
    let after = decode_cursor(query.next_page_token.as_deref());
    let body = runtime
        .history(&workflow_id, &run_id, after, page_size)
        .await
        .map_err(|error| {
            super::control_plane::Operation::History.refusal(error, &workflow_id, Some(&run_id))
        })?;
    Ok(Json(
        super::control_plane::Operation::History.response(body),
    ))
}

pub(super) fn decode_cursor(token: Option<&str>) -> i64 {
    token
        .and_then(|v| STANDARD.decode(v).ok())
        .and_then(|b| String::from_utf8(b).ok())
        .and_then(|v| v.parse::<i64>().ok())
        .filter(|n| *n >= 0)
        .unwrap_or(0)
}

async fn task_history(
    Extension(runtime): Extension<Runtime>,
    Path(task_id): Path<String>,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    let pages = runtime.history_pages();
    runtime.task_history(&task_id, body, pages).await.map(Json)
}

async fn register(
    Extension(runtime): Extension<Runtime>,
    headers: HeaderMap,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    if body["capabilities"]
        .as_array()
        .is_some_and(|flags| flags.iter().any(|flag| flag == "memo_upserts"))
    {
        memo_protocol(&headers)?;
    }
    if body["capabilities"].as_array().is_some_and(|flags| {
        flags
            .iter()
            .any(|flag| flag == super::search_attribute_upserts::CAPABILITY)
    }) && !protocol_at_least(&headers, 16)?
    {
        return Err(super::RuntimeError::Protocol {
            status: StatusCode::CONFLICT,
            response: json!({"reason":"workflow_metadata_capability_protocol_mismatch",
                "worker_capability":super::search_attribute_upserts::CAPABILITY,
                "minimum_protocol_version":super::search_attribute_upserts::MINIMUM_PROTOCOL_VERSION,"recorded":false}),
        });
    }
    Ok((
        StatusCode::CREATED,
        Json(worker_response(runtime.register(body).await?)),
    ))
}

fn worker_response(mut body: Value) -> Value {
    body["protocol_version"] = json!(super::WORKER_PROTOCOL_VERSION);
    body["server_capabilities"] = capabilities();
    body
}
async fn worker_heartbeat(
    Extension(runtime): Extension<Runtime>,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    runtime.worker_heartbeat(body).await.map(Json)
}
fn metrics_config(runtime: &Runtime) -> super::operator_metrics::MetricsConfig {
    super::operator_metrics::MetricsConfig {
        // `MatchingRoleSnapshot` reports the configured task leases.
        workflow_task_lease_seconds: runtime.queries.workflow_task_lease_seconds,
        activity_task_lease_seconds: runtime.queries.activity_task_lease_seconds,
        abandoned_upload_expiry_seconds: runtime.external.abandoned_seconds.max(1),
    }
}

async fn operator_metrics(Extension(runtime): Extension<Runtime>) -> Result<Json<Value>> {
    Ok(Json(
        runtime.operator_metrics(&metrics_config(&runtime)).await?,
    ))
}

async fn operator_dashboard(Extension(runtime): Extension<Runtime>) -> Result<Json<Value>> {
    Ok(Json(runtime.operator_dashboard(&metrics_config(&runtime), false).await?))
}

async fn bounded_operator_dashboard(Extension(runtime): Extension<Runtime>) -> Result<Json<Value>> {
    Ok(Json(runtime.operator_dashboard(&metrics_config(&runtime), true).await?))
}

/// PHP validates `workflow_types` and then answers 501 when its observer
/// cannot filter dashboard totals by workflow type; native cannot either.
async fn workflow_type_operator_dashboard(
    Query(query): Query<std::collections::HashMap<String, String>>,
) -> Result<Response> {
    super::operator_dashboard::workflow_types(query.get("workflow_types").map(String::as_str))?;
    Ok((
        StatusCode::NOT_IMPLEMENTED,
        Json(json!({"message":"The installed workflow observer cannot filter dashboard totals by workflow type.",
            "reason":"backend_capability_unavailable","capability":"workflow_type_dashboard"})),
    )
        .into_response())
}

type QueryMap = Query<std::collections::HashMap<String, String>>;

async fn repair_status(Extension(runtime): Extension<Runtime>) -> Result<Json<Value>> {
    Ok(Json(runtime.repair_status().await?))
}

async fn retention_status(Extension(runtime): Extension<Runtime>, Query(query): QueryMap) -> Result<Json<Value>> {
    let limit = super::retention::status_limit(query.get("limit").map(String::as_str))?;
    Ok(Json(runtime.retention_status(limit).await?))
}

/// PHP's passes answer 207 when any item failed.
fn pass_response((report, failed): (Value, bool)) -> Response {
    let status = if failed { StatusCode::MULTI_STATUS } else { StatusCode::OK };
    (status, Json(report)).into_response()
}

/// PHP reads an empty body as empty input and refuses malformed JSON.
fn request_json(body: &[u8]) -> Result<Value> {
    if body.trim_ascii().is_empty() {
        return Ok(Value::Null);
    }
    serde_json::from_slice(body).map_err(|_| super::RuntimeError::Protocol {
        status: StatusCode::BAD_REQUEST,
        response: json!({"message": "Request bodies must contain valid JSON.", "reason": "malformed_json",
            "json_error": "Syntax error"}),
    })
}

async fn retention_pass(Extension(runtime): Extension<Runtime>, body: axum::body::Bytes) -> Result<Response> {
    let (limit, run_ids) = super::retention::pass_request(&request_json(&body)?, "run_ids")?;
    Ok(pass_response(runtime.retention_pass(limit, run_ids).await?))
}

async fn activity_timeout_pass(Extension(runtime): Extension<Runtime>, body: axum::body::Bytes) -> Result<Response> {
    let (limit, ids) = super::retention::pass_request(&request_json(&body)?, "execution_ids")?;
    Ok(pass_response(runtime.activity_timeout_pass(limit, ids).await?))
}

async fn activity_timeout_status(
    Extension(runtime): Extension<Runtime>,
    Query(query): QueryMap,
) -> Result<Json<Value>> {
    let limit = super::retention::status_limit(query.get("limit").map(String::as_str))?;
    Ok(Json(runtime.activity_timeout_status(limit).await?))
}

/// PHP answers 503 while a check reports an error.
async fn system_health(Extension(runtime): Extension<Runtime>) -> Result<Response> {
    let (unavailable, body) = runtime.system_health(&metrics_config(&runtime)).await?;
    let status = if unavailable {
        StatusCode::SERVICE_UNAVAILABLE
    } else {
        StatusCode::OK
    };
    Ok((status, Json(body)).into_response())
}

async fn list_workers(
    Extension(runtime): Extension<Runtime>,
    Query(query): Query<std::collections::HashMap<String, String>>,
) -> Result<Json<Value>> {
    runtime
        .list_workers(
            query.get("task_queue").map(String::as_str),
            query.get("status").map(String::as_str),
        )
        .await
        .map(Json)
}
/// `GET /api/task-queues`.
async fn list_task_queues(Extension(runtime): Extension<Runtime>) -> Result<Json<Value>> {
    runtime.list_task_queues().await.map(Json)
}
/// `GET /api/task-queues/{name}`.
async fn describe_task_queue(
    Extension(runtime): Extension<Runtime>,
    Path(task_queue): Path<String>,
) -> Result<Json<Value>> {
    runtime.describe_task_queue(&task_queue).await.map(Json)
}
/// `GET /api/task-queues/{name}/build-ids`.
async fn task_queue_build_ids(
    Extension(runtime): Extension<Runtime>,
    Path(task_queue): Path<String>,
) -> Result<Json<Value>> {
    runtime.task_queue_build_ids(&task_queue).await.map(Json)
}
/// `POST /api/task-queues/{name}/build-ids/{promote|drain|resume}`. Native
/// keeps no build-id rollouts, so it refuses to promote, drain or resume one.
async fn build_id_rollout_unqualified() -> Result<Json<Value>> {
    Err(refuse(
        StatusCode::UNPROCESSABLE_ENTITY,
        "task_queue_build_id_rollout_unqualified",
    ))
}
async fn describe_worker(
    Extension(runtime): Extension<Runtime>,
    Path(worker_id): Path<String>,
) -> Result<Json<Value>> {
    runtime.describe_worker(&worker_id).await.map(Json)
}
async fn deregister(
    Extension(runtime): Extension<Runtime>,
    Path(worker_id): Path<String>,
) -> Result<Json<Value>> {
    runtime.deregister(&worker_id).await.map(Json)
}
async fn deregister_fenced(
    Extension(runtime): Extension<Runtime>,
    Path(worker_id): Path<String>,
    Json(body): Json<Value>,
) -> Result<Response> {
    match runtime.deregister_fenced(&worker_id, body.clone()).await {
        Ok(receipt) => Ok(Json(worker_response(receipt)).into_response()),
        Err(error) => {
            if let super::RuntimeError::Protocol { status, response } = error {
                return Err(super::RuntimeError::Protocol {
                    status,
                    response: worker_response(response),
                });
            }
            let Some(reason) = super::worker_incarnations::transient_reason(&error) else {
                return Err(error);
            };
            let mut response = (
                StatusCode::SERVICE_UNAVAILABLE,
                Json(worker_response(json!({
                    "reason":reason,"operation":"deregister_worker","worker_id":worker_id,
                    "registration_token":body["registration_token"],"outcome":"unknown",
                    "retryable":true,"retry_after_seconds":1
                }))),
            )
                .into_response();
            response
                .headers_mut()
                .insert("retry-after", HeaderValue::from_static("1"));
            Ok(response)
        }
    }
}
async fn poll_workflow(
    Extension(runtime): Extension<Runtime>,
    headers: HeaderMap,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    let pages = runtime.history_pages();
    let wait = runtime.worker_poll_wait();
    runtime
        .poll_with_pages(body, "workflow", request_protocol(&headers)?, pages, wait)
        .await
        .map(Json)
}
async fn poll_activity(
    Extension(runtime): Extension<Runtime>,
    headers: HeaderMap,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    let wait = runtime.worker_poll_wait();
    runtime
        .poll_with_pages(body, "activity", request_protocol(&headers)?, Default::default(), wait)
        .await
        .map(Json)
}

fn request_protocol(headers: &HeaderMap) -> Result<&str> {
    headers
        .get("x-durable-workflow-protocol-version")
        .and_then(|value| value.to_str().ok())
        .ok_or_else(|| refuse(StatusCode::BAD_REQUEST, "missing_protocol_version"))
}
async fn heartbeat_task(
    Extension(runtime): Extension<Runtime>,
    Path(task_id): Path<String>,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    runtime.heartbeat_task(&task_id, body).await.map(Json)
}

async fn deliver_cancellation(
    Extension(runtime): Extension<Runtime>,
    Path(task_id): Path<String>,
    headers: HeaderMap,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    runtime
        .deliver_cancellation(&task_id, body, request_protocol(&headers)?)
        .await
        // `WorkerProtocol::json`: every answer carries the worker envelope.
        .map(|body| Json(worker_response(body)))
}
async fn complete_workflow(
    Extension(runtime): Extension<Runtime>,
    request: CommandRequest,
    Path(task_id): Path<String>,
    headers: HeaderMap,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    if body["commands"].as_array().is_some_and(|commands| {
        commands
            .iter()
            .any(|command| command["type"] == "upsert_memo")
    }) {
        memo_protocol(&headers)?;
    }
    // `guardTypedSearchAttributeCommandsAvailable`.
    if body["commands"].as_array().is_some_and(|commands| {
        commands.iter().any(|command| {
            command["type"] == "upsert_search_attributes" && command.get("attribute_types").is_some()
        })
    }) && !protocol_at_least(&headers, 16)?
    {
        let minimum = super::search_attribute_upserts::MINIMUM_PROTOCOL_VERSION;
        return Err(super::RuntimeError::Protocol {
            status: StatusCode::CONFLICT,
            response: json!({"task_id":task_id,"workflow_task_attempt":body["workflow_task_attempt"],
                "outcome":"rejected","recorded":false,"reason":"typed_search_attributes_unavailable",
                "requested_version":request_protocol(&headers)?,"minimum_protocol_version":minimum,
                "remediation":format!("Complete typed search-attribute updates only through server nodes advertising worker protocol {minimum} or newer.")}),
        });
    }
    let attempt = body["workflow_task_attempt"].clone();
    runtime
        .complete_workflow(&task_id, body, Some(request.principal().clone()), headers.get("x-durable-workflow-protocol-version").and_then(|value| value.to_str().ok()))
        .await
        .map_err(|error| {
            completion_refusal(
                error,
                &task_id,
                "workflow_task_attempt",
                attempt,
                "Workflow task not found.",
            )
        })
        .map(Json)
}

/// Whether the request's worker protocol is `1.<minor>` or newer.
fn protocol_at_least(headers: &HeaderMap, minor: u32) -> Result<bool> {
    Ok(request_protocol(headers)?
        .strip_prefix("1.")
        .and_then(|value| value.parse::<u32>().ok())
        .is_some_and(|value| value >= minor))
}

fn memo_protocol(headers: &HeaderMap) -> Result<()> {
    let version = request_protocol(headers)?;
    if version
        .strip_prefix("1.")
        .and_then(|minor| minor.parse::<u32>().ok())
        .is_none_or(|minor| minor < 14)
    {
        return Err(super::RuntimeError::Protocol {
            status: StatusCode::CONFLICT,
            response: json!({"reason":"workflow_metadata_capability_protocol_mismatch",
                "worker_capability":"memo_upserts","minimum_protocol_version":"1.14","recorded":false}),
        });
    }
    Ok(())
}
async fn fail_workflow_task(
    Extension(runtime): Extension<Runtime>,
    Path(task_id): Path<String>,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    let attempt = body["workflow_task_attempt"].clone();
    runtime
        .fail_workflow_task(&task_id, body)
        .await
        .map_err(|error| {
            completion_refusal(
                error,
                &task_id,
                "workflow_task_attempt",
                attempt,
                "Workflow task not found.",
            )
        })
        .map(Json)
}

async fn complete_activity(
    Extension(runtime): Extension<Runtime>,
    Path(task_id): Path<String>,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    let attempt = body["activity_attempt_id"].clone();
    runtime
        .complete_activity(&task_id, body)
        .await
        .map_err(|error| {
            completion_refusal(
                error,
                &task_id,
                "activity_attempt_id",
                attempt,
                "Activity task not found.",
            )
        })
        .map(Json)
}

fn completion_refusal(
    error: super::RuntimeError,
    task_id: &str,
    attempt_field: &str,
    attempt: Value,
    message: &str,
) -> super::RuntimeError {
    match error {
        super::RuntimeError::Refused { status, reason } if status == StatusCode::CONFLICT => {
            let mut response = json!({"reason":reason,"message":reason,"task_id":task_id});
            response[attempt_field] = attempt;
            super::RuntimeError::Protocol { status, response }
        }
        super::RuntimeError::Refused {
            status,
            reason: "task_not_found",
        } if status == StatusCode::NOT_FOUND => {
            let mut response = json!({"task_id":task_id,"error":message,"reason":"task_not_found",
                "protocol_version":super::WORKER_PROTOCOL_VERSION,"server_capabilities":capabilities()});
            response[attempt_field] = attempt;
            super::RuntimeError::Protocol { status, response }
        }
        other => other,
    }
}

async fn heartbeat_activity(
    Extension(runtime): Extension<Runtime>,
    Path(task_id): Path<String>,
    headers: HeaderMap,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    runtime
        .heartbeat_activity(&task_id, body, request_protocol(&headers)?)
        .await
        .map(Json)
}

async fn activity_status(
    Extension(runtime): Extension<Runtime>,
    Path(task_id): Path<String>,
    headers: HeaderMap,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    runtime
        .activity_status(&task_id, body, request_protocol(&headers)?)
        .await
        .map(Json)
}
async fn fail_activity(
    Extension(runtime): Extension<Runtime>,
    Path(task_id): Path<String>,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    runtime.fail_activity(&task_id, body).await.map(Json)
}
/// The query's command context, as PHP's controller builds it.
fn query_context(request: &CommandRequest, body: &Value, workflow_id: &str, name: &str) -> Value {
    let context = request.context(body, workflow_id, "query", metadata(&[("query_name", Some(json!(name)))]));
    json!({"source":"control_plane","context":context})
}

async fn query_current(
    Extension(runtime): Extension<Runtime>,
    request: CommandRequest,
    Path((workflow_id, name)): Path<(String, String)>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    let command = query_context(&request, &body, &workflow_id, &name);
    let (status, body) = runtime
        .query_workflow(&workflow_id, None, &name, body, Some(command))
        .await
        .map_err(|error| super::control_plane::Operation::Query(&name).refusal(error, &workflow_id, None))?;
    Ok((status, Json(super::control_plane::Operation::Query(&name).response(body))))
}
async fn query_run(
    Extension(runtime): Extension<Runtime>,
    request: CommandRequest,
    Path((workflow_id, run_id, name)): Path<(String, String, String)>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    let command = query_context(&request, &body, &workflow_id, &name);
    let (status, body) = runtime
        .query_workflow(&workflow_id, Some(&run_id), &name, body, Some(command))
        .await
        .map_err(|error| {
            super::control_plane::Operation::Query(&name).refusal(error, &workflow_id, Some(&run_id))
        })?;
    Ok((status, Json(super::control_plane::Operation::Query(&name).response(body))))
}
async fn poll_query(
    Extension(runtime): Extension<Runtime>,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    runtime.poll_query(body).await.map(Json)
}
async fn complete_query(
    Extension(runtime): Extension<Runtime>,
    Path(task_id): Path<String>,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    runtime.complete_query(&task_id, body).await.map(Json)
}
#[derive(Deserialize)]
struct QueryStatusRequest {
    lease_owner: String,
    query_task_attempt: i64,
}
async fn query_status(
    Extension(runtime): Extension<Runtime>,
    Path(task_id): Path<String>,
    request: Request,
) -> Result<Json<Value>> {
    let minor = request
        .headers()
        .get("x-durable-workflow-protocol-version")
        .and_then(|value| value.to_str().ok())
        .map(str::trim)
        .and_then(|version| version.split_once('.'))
        .and_then(|(_, minor)| minor.parse::<u8>().ok());
    if minor.is_none_or(|minor| minor < 20) {
        return Err(super::RuntimeError::Protocol {
            status: StatusCode::UNPROCESSABLE_ENTITY,
            response: json!({"reason":"query_task_status_unavailable","error":"Query task observation requires worker protocol 1.20.","minimum_protocol_version":"1.20"}),
        });
    }
    let Query(claim) = Query::<QueryStatusRequest>::try_from_uri(request.uri())
        .map_err(|_| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_query_status"))?;
    if claim.lease_owner.is_empty() || claim.query_task_attempt < 1 {
        return Err(refuse(
            StatusCode::UNPROCESSABLE_ENTITY,
            "invalid_query_status",
        ));
    }
    runtime
        .query_status(&task_id, &claim.lease_owner, claim.query_task_attempt)
        .await
        .map(Json)
}
async fn fail_query(
    Extension(runtime): Extension<Runtime>,
    Path(task_id): Path<String>,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    runtime.finish_query(&task_id, body, true).await.map(Json)
}
async fn unavailable() -> Response {
    refuse(
        StatusCode::NOT_IMPLEMENTED,
        "rust_capability_not_implemented",
    )
    .into_response()
}
