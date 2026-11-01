use super::laravel_rules::Rule;
use super::json_body::Json;
use axum::{
    Extension, Router,
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
            get(describe_namespace).put(update_namespace).delete(delete_namespace),
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
        .route("/api/bridge-adapters/webhook/{adapter}", post(bridge_webhook))
        .route("/api/workflows/import/embedded-v2", post(import_embedded_v2))
        .route("/api/workflows/import/waterline-v1", post(import_waterline_v1))
        .route("/api/activities", get(list_activities).post(start_activity))
        .route("/api/activities/{activity_id}", get(show_activity))
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
        .route("/api/workflows/{workflow_id}/nexus-operations", get(nexus_operations_current))
        .route("/api/workflows/{workflow_id}/runs/{run_id}/nexus-operations", get(nexus_operations_run))
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
        .route("/api/workflows/{workflow_id}/message-streams", get(list_message_streams))
        .route("/api/workflows/{workflow_id}/message-streams/{stream_name}", get(show_message_stream))
        .route(
            "/api/workflows/{workflow_id}/message-streams/{stream_name}/messages",
            post(append_message),
        )
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
            get(super::workflow_streams::items).post(super::workflow_streams::append),
        )
        .route(
            "/api/workflows/{workflow_id}/runs/{run_id}/streams/{stream_name}/close",
            post(super::workflow_streams::close),
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
        .route("/api/workflows/{workflow_id}/archive", post(archive_current))
        .route("/api/workflows/{workflow_id}/runs/{run_id}/archive", post(archive_run))
        .route("/api/workflows/{workflow_id}/runs/{run_id}/redrive", post(redrive_run))
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
        .route("/api/worker/sessions", post(create_worker_session))
        .route("/api/worker/sessions/{session_id}", delete(close_worker_session))
        .route("/api/worker/sessions/{session_id}/heartbeat", post(heartbeat_worker_session))
        .route("/api/worker-sessions", get(list_worker_sessions))
        .route("/api/worker-sessions/{session_id}", get(show_worker_session))
        .route("/api/worker/registrations/{worker_id}", delete(deregister))
        .route(
            "/api/worker/registrations/{worker_id}/deregister",
            post(deregister_fenced),
        )
        .route("/api/system/operator-metrics", get(operator_metrics))
        .route("/api/system/health", get(system_health))
        .route("/api/system/metrics", get(system_metrics).post(system_metrics))
        .route("/api/system/prometheus-metrics", get(prometheus_metrics))
        .route("/api/system/external-payload-cleanup", get(payload_cleanup_status))
        .route("/api/system/external-payload-cleanup/pass", post(payload_cleanup_pass))
        .route("/api/system/operator-dashboard", get(operator_dashboard))
        .route("/api/system/operator-dashboard/bounded", get(bounded_operator_dashboard))
        .route(
            "/api/system/operator-dashboard/bounded/workflow-types",
            get(workflow_type_operator_dashboard),
        )
        .route("/api/system/repair", get(repair_status))
        .route("/api/system/repair/pass", post(repair_pass))
        .route("/api/system/retention", get(retention_status))
        .route("/api/system/retention/pass", post(retention_pass))
        .route("/api/system/activity-timeouts", get(activity_timeout_status))
        .route("/api/system/activity-timeouts/pass", post(activity_timeout_pass))
        .route("/api/workers", get(list_workers))
        .route("/api/task-queues", get(list_task_queues))
        .route("/api/service-endpoints", get(service_endpoints_index).post(service_endpoint_store))
        .route(
            "/api/service-endpoints/{endpoint}",
            get(service_endpoint_show).put(service_endpoint_update).delete(service_endpoint_destroy),
        )
        .route("/api/service-endpoints/{endpoint}/services", get(services_index).post(service_store))
        .route(
            "/api/service-endpoints/{endpoint}/services/{service}",
            get(service_show).put(service_update).delete(service_destroy),
        )
        .route(
            "/api/service-endpoints/{endpoint}/services/{service}/operations",
            get(operations_index).post(operation_store),
        )
        .route(
            "/api/service-endpoints/{endpoint}/services/{service}/operations/{operation}",
            get(operation_show).put(operation_update).delete(operation_destroy),
        )
        .route(
            "/api/service-endpoints/{endpoint}/services/{service}/operations/{operation}/execute",
            post(operation_execute),
        )
        .route(
            "/api/service-endpoints/{endpoint}/services/{service}/operations/{operation}/service-calls/{call}",
            get(service_call_show),
        )
        .route(
            "/api/service-endpoints/{endpoint}/services/{service}/operations/{operation}/service-calls/{call}/cancel",
            post(service_call_cancel),
        )
        .route("/api/service-catalog/{catalog}", get(service_catalog_list))
        .route("/api/service-catalog/{catalog}/{id}", get(service_catalog_show))
        .route("/api/task-queues/{task_queue}", get(describe_task_queue))
        .route("/api/task-queues/{task_queue}/build-ids", get(task_queue_build_ids))
        .route("/api/task-queues/{task_queue}/priority-fairness", get(priority_fairness))
        .route("/api/task-queues/{task_queue}/build-ids/promote", post(build_id_promote))
        .route("/api/task-queues/{task_queue}/build-ids/drain", post(build_id_drain))
        .route("/api/task-queues/{task_queue}/build-ids/resume", post(build_id_resume))
        .route("/api/deployments", get(list_deployments))
        .route("/api/deployments/{*name}", get(show_deployment).post(deployment_action))
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
            "/api/worker/workflow-tasks/{task_id}/cancellation-scopes/open",
            post(open_cancellation_scope),
        )
        .route(
            "/api/worker/workflow-tasks/{task_id}/cancellation-scopes/prepare",
            post(prepare_cancellation_scope),
        )
        .route(
            "/api/worker/workflow-tasks/{task_id}/cancellation-scopes/deliver",
            post(deliver_cancellation_scope),
        )
        .route(
            "/api/worker/workflow-tasks/{task_id}/cancellation-scopes/checkpoint",
            post(checkpoint_cancellation_scope),
        )
        // Native lacks the prepared local-activity role: refused as PHP
        // refuses them without it (prepared_local.rs).
        .route("/api/worker/workflow-tasks/{task_id}/local-activities/prepare", post(prepared_local_refused))
        .route("/api/worker/workflow-tasks/{task_id}/local-activities/recover", post(prepared_local_refused))
        .route("/api/worker/workflow-tasks/{task_id}/local-activities/checkpoint", post(prepared_local_refused))
        .route("/api/worker/workflow-tasks/{task_id}/local-activities/checkpoint-group", post(prepared_local_group_refused))
        .route("/api/worker/workflow-tasks/{task_id}/local-activities/{attempt_id}/control", post(prepared_local_refused))
        .route("/api/worker/workflow-tasks/{task_id}/local-activities/{attempt_id}/heartbeat", post(prepared_local_refused))
        .route("/api/worker/workflow-tasks/{task_id}/local-activities/{attempt_id}/outcome", post(prepared_local_refused))
        .route(
            "/api/worker/workflow-tasks/{task_id}/local-activities/{attempt_id}/acknowledge-cancellation",
            post(prepared_local_refused),
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
            "/api/worker/activity-tasks/{task_id}/renew-lease",
            post(renew_activity_lease),
        )
        .route(
            "/api/worker/activity-tasks/{task_id}/acknowledge-cancellation",
            post(acknowledge_activity_cancellation),
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
        .route("/api/worker/update-validation-tasks/poll", post(poll_update_validation))
        .route(
            "/api/worker/update-validation-tasks/{task_id}/approve",
            post(approve_update_validation),
        )
        .route(
            "/api/worker/update-validation-tasks/{task_id}/reject",
            post(reject_update_validation),
        )
        .fallback(route_fallback)
        .method_not_allowed_fallback(route_fallback)
        .layer(middleware::from_fn(super::waterline_import::read_only_guard))
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
    // PHP reads an empty body, like `[]`, as empty input.
    let empty = bytes.trim_ascii().is_empty()
        && matches!(parts.method, axum::http::Method::POST | axum::http::Method::PUT | axum::http::Method::PATCH);
    let body = if bytes.trim_ascii() == b"[]" || empty {
        parts.headers.insert(header::CONTENT_LENGTH, HeaderValue::from_static("2"));
        if empty && !parts.headers.contains_key(header::CONTENT_TYPE) {
            parts.headers.insert(header::CONTENT_TYPE, HeaderValue::from_static("application/json"));
        }
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
        // Updating or deleting a namespace, or its external storage, needs an admin.
        || path.starts_with("/api/namespaces/") && matches!(*request.method(), Method::PUT | Method::DELETE)
        // PHP's system routes are admin-only.
        || path.starts_with("/api/system/")
        || path == "/api/runtime-credentials"
        || path.starts_with("/api/runtime-credentials/")
        // History import writes runs into the store; PHP serves it to admins.
        || path.starts_with("/api/workflows/import/")
        // Service catalog administration.
        || catalog_admin_route(&path)
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
            // A request PHP has no route for gets PHP's 404 or 405 in any
            // namespace; only an operation PHP serves is refused here.
            if !matches!(super::route_fallback::answer(request.method().as_str(), &path),
                super::route_fallback::Answer::NotImplemented)
            {
                return route_fallback(request.method().clone(), request.uri().clone()).await;
            }
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
    // The workflow route a typed refusal came from, to name the workflow
    // in its envelope.
    let route = (!worker && path.starts_with("/api/workflows")).then(|| {
        let mut route = Request::new(Body::empty());
        *route.method_mut() = request.method().clone();
        *route.uri_mut() = request.uri().clone();
        *route.extensions_mut() = request.extensions().clone();
        route
    });
    let mut response = next.run(request).await;
    if response.extensions().get::<super::external_transport::TypedRefusal>().is_some() && (worker || route.is_some()) {
        response = typed_refusal_envelope(response, route, worker).await;
    }
    response
        .headers_mut()
        .insert(header, HeaderValue::from_static(version));
    response
}

/// PHP renders a typed external payload error through `WorkerProtocol` or
/// `ControlPlaneProtocol`, which add the route's envelope fields.
async fn typed_refusal_envelope(response: Response, route: Option<Request>, worker: bool) -> Response {
    let status = response.status();
    let (parts, body) = response.into_parts();
    let Ok(bytes) = axum::body::to_bytes(body, 64 * 1024).await else {
        return (status, Json(json!({"reason":"external_payload_unavailable"}))).into_response();
    };
    let Ok(body) = super::json_body::from_slice::<Value>(&bytes) else {
        return Response::from_parts(parts, Body::from(bytes));
    };
    let mut response = admission_error(route.unwrap_or_else(|| Request::new(Body::empty())), worker, status, body).await;
    if let Some(retry) = parts.headers.get("retry-after") {
        response.headers_mut().insert("retry-after", retry.clone());
    }
    response
}

/// The methods a named namespace may use on a route.
#[derive(Clone, Copy)]
enum Allowed {
    Any,
    Only(&'static [&'static str]),
}

const READ: Allowed = Allowed::Only(&["GET"]);
const WRITE: Allowed = Allowed::Only(&["POST"]);
const READ_WRITE: Allowed = Allowed::Only(&["GET", "POST"]);

/// What follows a family's prefix.
#[derive(Clone, Copy)]
enum Rest {
    Anything,
    NonEmpty,
    OneSegment,
}

/// Route families a named namespace may use, by prefix.
const NAMESPACE_FAMILIES: [(&str, Rest, Allowed); 10] = [
    // Runtime credentials are server-wide; the namespace only scopes a tenant.
    ("/api/runtime-credentials/", Rest::Anything, Allowed::Any),
    ("/api/external-payloads/v1/", Rest::Anything, READ),
    // The service catalog reads are namespace-scoped.
    ("/api/service-catalog/", Rest::Anything, READ),
    // Schedules are namespace-scoped rows, as in PHP.
    ("/api/schedules/", Rest::Anything, Allowed::Any),
    // Standalone activities are host runs in the requested namespace.
    ("/api/activities/", Rest::OneSegment, READ),
    // The registry is namespace-scoped state with no default-namespace selector.
    ("/api/search-attributes/", Rest::OneSegment, Allowed::Only(&["DELETE"])),
    // Deregistration deletes only the namespace's own registration.
    ("/api/workers/", Rest::OneSegment, Allowed::Only(&["GET", "DELETE"])),
    // Deployments are the namespace's own build-id rollouts.
    ("/api/deployments/", Rest::NonEmpty, READ_WRITE),
    // A bridge event acts on workflows of the requested namespace.
    ("/api/bridge-adapters/webhook/", Rest::OneSegment, WRITE),
    // Worker session visibility reads the namespace's own sessions.
    ("/api/worker-sessions/", Rest::OneSegment, READ),
];

fn named_namespace_operation(method: &str, path: &str) -> bool {
    match namespace_route(path) {
        Some(Allowed::Any) => true,
        Some(Allowed::Only(methods)) => methods.contains(&method),
        None => false,
    }
}

/// The methods a named namespace may use on `path`. Other routes are refused,
/// so unimplemented families never reach default-namespace selectors; each
/// further family needs its own shared namespace fixtures.
fn namespace_route(path: &str) -> Option<Allowed> {
    exact_namespace_route(path)
        // The catalog routes act on the namespace's own rows and calls.
        .or_else(|| (catalog_admin_route(path) || catalog_call_route(path)).then_some(Allowed::Any))
        .or_else(|| {
            NAMESPACE_FAMILIES.iter().find_map(|(prefix, shape, allowed)| {
                let rest = path.strip_prefix(prefix)?;
                let fits = match shape {
                    Rest::Anything => true,
                    Rest::NonEmpty => !rest.is_empty(),
                    Rest::OneSegment => !rest.is_empty() && !rest.contains('/'),
                };
                fits.then_some(*allowed)
            })
        })
        .or_else(|| path.strip_prefix("/api/task-queues/").and_then(task_queue_route))
        .or_else(|| path.strip_prefix("/api/workflows/").and_then(workflow_route))
        .or_else(|| path.strip_prefix("/api/worker/").and_then(worker_route))
}

fn exact_namespace_route(path: &str) -> Option<Allowed> {
    Some(match path {
        "/api/runtime-credentials" | "/api/schedules" => Allowed::Any,
        "/api/workflows" | "/api/activities" | "/api/search-attributes" => READ_WRITE,
        // The metrics describe the namespace's own state, read by GET or POST.
        "/api/system/metrics" => READ_WRITE,
        // An import writes into the requested namespace.
        "/api/external-payloads/v1" | "/api/storage/test" | "/api/workflows/import/embedded-v2"
        | "/api/workflows/import/waterline-v1" => WRITE,
        "/api/task-queues"
        | "/api/deployments"
        | "/api/workers"
        | "/api/worker-sessions"
        | "/api/system/operator-metrics"
        | "/api/system/health"
        | "/api/system/operator-dashboard"
        | "/api/system/operator-dashboard/bounded"
        | "/api/system/operator-dashboard/bounded/workflow-types"
        | "/api/system/prometheus-metrics"
        | "/api/system/external-payload-cleanup"
        // Retention reads and prunes the namespace's own runs; the activity
        // timeout scan spans namespaces in PHP too.
        | "/api/system/retention"
        | "/api/system/activity-timeouts"
        | "/api/system/repair" => READ,
        // The repair pass is server-wide in PHP too. The cleanup pass reclaims
        // only the namespace's own expired payloads.
        "/api/system/retention/pass"
        | "/api/system/activity-timeouts/pass"
        | "/api/system/repair/pass"
        | "/api/system/external-payload-cleanup/pass" => WRITE,
        _ => return None,
    })
}

/// Task queue views, build-id cohorts and fairness are namespace-scoped reads;
/// rollout changes act on the namespace's own cohorts.
fn task_queue_route(rest: &str) -> Option<Allowed> {
    match rest.split('/').collect::<Vec<_>>().as_slice() {
        [queue, ..] if queue.is_empty() => None,
        [_] | [_, "build-ids" | "priority-fairness"] => Some(READ),
        [_, "build-ids", "promote" | "drain" | "resume"] => Some(WRITE),
        _ => None,
    }
}

fn workflow_route(rest: &str) -> Option<Allowed> {
    match rest.split('/').collect::<Vec<_>>().as_slice() {
        [_, "observation"]
        | [_, "runs", _, "observation"]
        | [_, "runs", _, "activities", _, "result"]
        // Waterline's run detail: the run list, debug view and history export.
        // Each reads the instance within the request's namespace first.
        | [_, "runs"]
        | [_, "debug"]
        | [_, "runs", _, "debug"]
        | [_, "runs", _, "history", "export"]
        | [_, "runs", _, "streams"]
        | [_, "runs", _, "streams", _]
        | [_, "nexus-operations"]
        | [_, "runs", _, "nexus-operations"] => Some(READ),
        // A run's durable streams are rows in the request's namespace.
        [_, "runs", _, "streams", _, "items"] => Some(READ_WRITE),
        [_, "runs", _, "streams", _, "close"] => Some(WRITE),
        [_, "signal" | "query" | "update", _]
        | [_, "runs", _, "signal" | "query" | "update", _]
        | [_, "repair" | "request-cancellation"]
        | [_, "runs", _, "repair" | "request-cancellation" | "redrive"] => Some(WRITE),
        [_]
        | [_, "cancel" | "terminate" | "archive"]
        | [_, "runs", _]
        | [_, "runs", _, "history" | "cancel" | "terminate" | "archive" | "activities"] => Some(Allowed::Any),
        _ => None,
    }
}

fn worker_route(rest: &str) -> Option<Allowed> {
    matches!(
        rest.split('/').collect::<Vec<_>>().as_slice(),
        ["register" | "heartbeat"]
            | ["registrations", _]
            | ["registrations", _, "deregister"]
            | ["sessions"]
            | ["sessions", _]
            | ["sessions", _, "heartbeat"]
            | ["workflow-tasks" | "activity-tasks", "poll"]
            | ["query-tasks", "poll"]
            | ["query-tasks", _, "complete" | "fail" | "status"]
            | ["update-validation-tasks", "poll"]
            | ["update-validation-tasks", _, "approve" | "reject"]
            | ["workflow-tasks", _, "complete" | "fail" | "history" | "heartbeat" | "deliver-cancellation"]
            | ["workflow-tasks", _, "cancellation-scopes", "open" | "prepare" | "deliver" | "checkpoint"]
            | ["workflow-tasks", _, "local-activities", "prepare" | "recover" | "checkpoint" | "checkpoint-group"]
            | ["workflow-tasks", _, "local-activities", _, "control" | "heartbeat" | "outcome" | "acknowledge-cancellation"]
            | ["activity-tasks", _, "complete" | "fail" | "heartbeat" | "status" | "renew-lease" | "acknowledge-cancellation"]
    )
    .then_some(Allowed::Any)
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
/// PHP answers 207 while a committed provider deletion is still pending.
async fn delete_namespace(
    Extension(runtime): Extension<Runtime>,
    Path(namespace): Path<String>,
) -> Result<Response> {
    let body = runtime.delete_namespace(&namespace).await?;
    let pending = body["external_payload_cleanup"]["pending"].as_i64().is_some_and(|pending| pending > 0);
    let status = if pending { StatusCode::MULTI_STATUS } else { StatusCode::OK };
    Ok((status, Json(body)).into_response())
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

/// The namespace cluster info describes: the X-Namespace header, else the
/// `namespace` query selector, else the default. Like PHP, it need not exist.
fn discovery_namespace(headers: &axum::http::HeaderMap, uri: &axum::http::Uri) -> String {
    if let Some(header) = headers.get("x-namespace") {
        return header.to_str().unwrap_or("").to_ascii_lowercase();
    }
    Query::<std::collections::HashMap<String, String>>::try_from_uri(uri)
        .ok()
        .and_then(|Query(fields)| fields.get("namespace").cloned())
        .filter(|name| !name.is_empty())
        .unwrap_or_else(|| "default".to_owned())
        .to_ascii_lowercase()
}

async fn cluster(
    Extension(runtime): Extension<Runtime>,
    headers: axum::http::HeaderMap,
    uri: axum::http::Uri,
) -> Result<Json<Value>> {
    let auth_composition: Value = serde_json::from_str(include_str!("auth-composition.v1.json"))
        .expect("valid bundled carrier auth composition contract");
    // Published PHP's `PrincipalAttributionContract::manifest()`, identical in
    // Server 2.5.14, 2.10.0 and 2.11.0. Native records the authenticated
    // principal as it describes.
    let principal_attribution: Value =
        serde_json::from_str(include_str!("principal-attribution-contract.v2.json"))
            .expect("valid bundled principal attribution contract");
    // Server 2.11.1's service catalog read contract. Native serves its eight
    // reads; the SDK requires this advertisement before calling them.
    let service_catalog: Value =
        serde_json::from_str(include_str!("service-catalog-read-contract.v1.json"))
            .expect("valid bundled service catalog read contract");
    // Runtime external payload discovery, as SDKs read it before an upload.
    let namespace = runtime.in_namespace(&discovery_namespace(&headers, &uri)).namespace_discovery().await?;
    let limits = super::payload_discovery::limits()?;
    let structural_limits = runtime.cluster_structural_limits(&metrics_config(&runtime)).await?;
    Ok(Json(
        json!({"version": super::cluster_info::version(), "implementation": "rust", "development": true,
        "capabilities": super::cluster_info::capabilities(), "structural_limits": structural_limits,
        "discovery": super::cluster_info::discovery(), "native_unqualified": super::cluster_info::unqualified(),
        "server_id": super::payload_discovery::server_id(), "default_namespace": "default",
        "namespace": namespace, "limits": limits,
        "control_plane": {"version": "2", "request_contract": super::visibility::request_contract()}, "worker_protocol": {"version": super::WORKER_PROTOCOL_VERSION, "server_capabilities": capabilities()},
        "auth_composition_contract": auth_composition,
        "principal_attribution_contract": principal_attribution,
        "service_catalog_read_contract": service_catalog,
        "message_streams_contract": super::message_streams::manifest(),
        "payload_codec": "avro", "qualified_for_php_takeover": false}),
    ))
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

/// `POST /api/workflows/{workflow_id}/message-streams/{stream_name}/messages`.
async fn append_message(
    Extension(runtime): Extension<Runtime>,
    request: CommandRequest,
    Path((workflow_id, stream)): Path<(String, String)>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    let normalized = super::laravel_rules::normalize(&body);
    let context = request.context(&body, &workflow_id, "message_stream_append", metadata(&[
        ("stream_name", Some(json!(stream))),
        ("message_id", present(&normalized, "message_id")),
        ("request_id", present(&normalized, "request_id")),
    ]));
    let (status, response) = runtime.append_message(&workflow_id, &stream, body, context).await?;
    Ok((status, Json(response)))
}

/// `GET /api/workflows/{workflow_id}/message-streams`.
async fn list_message_streams(
    Extension(runtime): Extension<Runtime>,
    Path(workflow_id): Path<String>,
) -> Result<Json<Value>> {
    Ok(Json(runtime.message_streams(&workflow_id, None).await?))
}

/// `GET /api/workflows/{workflow_id}/message-streams/{stream_name}`.
async fn show_message_stream(
    Extension(runtime): Extension<Runtime>,
    Path((workflow_id, stream)): Path<(String, String)>,
) -> Result<Json<Value>> {
    Ok(Json(runtime.message_streams(&workflow_id, Some(&stream)).await?))
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

/// `POST /api/bridge-adapters/webhook/{adapter}`. PHP validates the body
/// itself, so a body that is not a JSON object reads as an empty one.
async fn bridge_webhook(
    Extension(runtime): Extension<Runtime>,
    command: CommandRequest,
    Path(adapter): Path<String>,
    body: axum::body::Bytes,
) -> Result<(StatusCode, Json<Value>)> {
    let body = super::json_body::from_slice::<Value>(&body).ok().filter(Value::is_object).unwrap_or_else(|| json!({}));
    let (status, answer) = super::bridge_adapters::webhook(&runtime, &command, &adapter, body).await?;
    Ok((status, Json(answer)))
}

/// `POST /api/workflows/import/embedded-v2`. The controller reads the body
/// and the query string itself, as Laravel's request input.
async fn import_embedded_v2(
    Extension(runtime): Extension<Runtime>,
    uri: axum::http::Uri,
    body: axum::body::Bytes,
) -> Result<(StatusCode, Json<Value>)> {
    let query: Vec<(String, String)> = uri
        .query()
        .map(|query| form_urlencoded::parse(query.as_bytes()).into_owned().collect())
        .unwrap_or_default();
    let (status, answer) = runtime.import_embedded_v2(&body, &query).await?;
    Ok((status, Json(answer)))
}

/// `POST /api/workflows/import/waterline-v1`.
async fn import_waterline_v1(
    Extension(runtime): Extension<Runtime>,
    body: axum::body::Bytes,
) -> Result<(StatusCode, Json<Value>)> {
    let (status, answer) = runtime.import_waterline_v1(&body).await?;
    Ok((status, Json(answer)))
}

/// `POST /api/activities`: a standalone activity in its own host run.
async fn start_activity(
    Extension(runtime): Extension<Runtime>,
    command: CommandRequest,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    // PHP names the target `pending` until the start assigns an id.
    let trimmed = |field: &str| body[field].as_str().map(str::trim).filter(|text| !text.is_empty()).map(str::to_owned);
    let target = trimmed("activity_id").unwrap_or_else(|| "pending".into());
    let queue = trimmed("task_queue").unwrap_or_else(|| "default".into());
    let context = command.context(&body, &target, "standalone_activity_start", metadata(&[
        ("activity_type", trimmed("activity_type").map(Value::from)),
        ("task_queue", Some(json!(queue))),
    ]));
    let started = runtime.start_standalone_activity(body, context).await?;
    Ok((StatusCode::CREATED, Json(started)))
}

async fn show_activity(
    Extension(runtime): Extension<Runtime>,
    Path(activity_id): Path<String>,
) -> Result<Json<Value>> {
    Ok(Json(runtime.standalone_activity(&activity_id).await?))
}

async fn list_activities(
    Extension(runtime): Extension<Runtime>,
    Query(query): Query<std::collections::BTreeMap<String, String>>,
) -> Result<Json<Value>> {
    let query = Value::Object(query.into_iter().map(|(key, value)| (key, Value::String(value))).collect());
    Ok(Json(runtime.standalone_activities(query).await?))
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

/// `GET /api/workflows/{workflow_id}/nexus-operations`.
async fn nexus_operations_current(
    Extension(runtime): Extension<Runtime>,
    Path(workflow_id): Path<String>,
    Query(query): Query<std::collections::HashMap<String, String>>,
) -> Result<Json<Value>> {
    nexus_operations(runtime, &workflow_id, None, query).await
}

/// `GET /api/workflows/{workflow_id}/runs/{run_id}/nexus-operations`.
async fn nexus_operations_run(
    Extension(runtime): Extension<Runtime>,
    Path((workflow_id, run_id)): Path<(String, String)>,
    Query(query): Query<std::collections::HashMap<String, String>>,
) -> Result<Json<Value>> {
    nexus_operations(runtime, &workflow_id, Some(&run_id), query).await
}

async fn nexus_operations(
    runtime: Runtime,
    workflow_id: &str,
    run_id: Option<&str>,
    query: std::collections::HashMap<String, String>,
) -> Result<Json<Value>> {
    let limit = super::nexus_operations::list_limit(query.get("limit").map(String::as_str));
    let calls = runtime.nexus_operations(workflow_id, run_id, limit).await?;
    Ok(Json(super::nexus_operations::listing(workflow_id, run_id, runtime.namespace(), limit, calls)))
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

/// `POST /api/workflows/{id}/archive` and its run-targeted route.
async fn archive(
    runtime: Runtime,
    request: CommandRequest,
    workflow_id: &str,
    run_id: Option<&str>,
    body: Value,
) -> Result<(StatusCode, Json<Value>)> {
    let operation = || super::control_plane::Operation::Archive;
    let context = request.context(&body, workflow_id, "archive", metadata(&[
        ("request_id", present(&body, "request_id")),
        ("reason", present(&body, "reason")),
    ]));
    let (status, response) = runtime
        .archive_workflow(workflow_id, run_id, body, context)
        .await
        .map_err(|error| operation().refusal(error, workflow_id, run_id))?;
    Ok((status, Json(operation().response(response))))
}

/// `POST /api/workflows/{id}/runs/{run}/redrive`.
async fn redrive_run(
    Extension(runtime): Extension<Runtime>,
    request: CommandRequest,
    Path((workflow_id, run_id)): Path<(String, String)>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    let operation = || super::control_plane::Operation::Redrive;
    let context = request.context(&body, &workflow_id, "redrive", metadata(&[
        ("request_id", present(&body, "request_id")),
        ("continued_from_run_id", Some(json!(run_id))),
    ]));
    let (status, response) = runtime
        .redrive_run(&workflow_id, &run_id, body, context)
        .await
        .map_err(|error| operation().refusal(error, &workflow_id, Some(&run_id)))?;
    Ok((status, Json(operation().response(response))))
}

async fn archive_current(
    Extension(runtime): Extension<Runtime>,
    request: CommandRequest,
    Path(workflow_id): Path<String>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    archive(runtime, request, &workflow_id, None, body).await
}

async fn archive_run(
    Extension(runtime): Extension<Runtime>,
    request: CommandRequest,
    Path((workflow_id, run_id)): Path<(String, String)>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    archive(runtime, request, &workflow_id, Some(&run_id), body).await
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
    let protocol = request_protocol(&headers)?;
    // Laravel trims every string and turns empty ones into null first.
    let mut body = super::laravel_rules::normalize(&body);
    super::worker_admission::admit(&body, Some(protocol.trim())).map_err(worker_refusal)?;
    // PHP names an unnamed worker with a fresh ULID and stores the
    // normalized capability manifest.
    if body["worker_id"].is_null() {
        body["worker_id"] = json!(ulid::Ulid::new().to_string());
    }
    let manifest = super::worker_admission::manifest(&body);
    body["capability_manifest"] = manifest;
    // Missing type lists are empty; capabilities keep their non-empty
    // strings (`nonEmptyStringArray`).
    for field in ["supported_workflow_types", "supported_activity_types"] {
        if body[field].is_null() {
            body[field] = json!([]);
        }
    }
    let flags: Vec<Value> = body["capabilities"]
        .as_array()
        .into_iter()
        .flatten()
        .filter(|flag| flag.as_str().is_some_and(|flag| !flag.is_empty()))
        .cloned()
        .collect();
    body["capabilities"] = json!(flags);
    let registered = runtime.register(body, Some(protocol)).await.map_err(worker_refusal)?;
    Ok((StatusCode::CREATED, Json(worker_response(registered))))
}

/// `WorkerProtocol::json` names the protocol and server capabilities on
/// every worker response, refusals included.
fn worker_refusal(error: super::RuntimeError) -> super::RuntimeError {
    match error {
        super::RuntimeError::Protocol { status, response } => super::RuntimeError::Protocol {
            status,
            response: worker_response(response),
        },
        other => other,
    }
}

fn worker_response(mut body: Value) -> Value {
    body["protocol_version"] = json!(super::WORKER_PROTOCOL_VERSION);
    body["server_capabilities"] = capabilities();
    body
}
/// A worker session answer: PHP's status, its body repeating it, and the
/// worker protocol envelope.
fn session_answer(answer: super::worker_sessions::Answer) -> Response {
    (answer.status, Json(worker_response(answer.body))).into_response()
}

async fn create_worker_session(
    Extension(runtime): Extension<Runtime>,
    headers: HeaderMap,
    Json(body): Json<Value>,
) -> Result<Response> {
    let protocol = request_protocol(&headers)?;
    runtime.create_worker_session(body, protocol).await.map(session_answer).map_err(worker_refusal)
}

async fn heartbeat_worker_session(
    Extension(runtime): Extension<Runtime>,
    Path(session_id): Path<String>,
    headers: HeaderMap,
    Json(body): Json<Value>,
) -> Result<Response> {
    let protocol = request_protocol(&headers)?;
    runtime.heartbeat_worker_session(&session_id, body, protocol).await.map(session_answer).map_err(worker_refusal)
}

/// The holder may name itself in the JSON body or as `?worker_id=`.
async fn close_worker_session(
    Extension(runtime): Extension<Runtime>,
    Path(session_id): Path<String>,
    Query(query): Query<std::collections::HashMap<String, String>>,
    headers: HeaderMap,
    body: axum::body::Bytes,
) -> Result<Response> {
    let protocol = request_protocol(&headers)?;
    let mut fields = if body.is_empty() {
        json!({})
    } else {
        super::json_body::from_slice::<Value>(&body).ok().filter(Value::is_object).unwrap_or_else(|| json!({}))
    };
    if let Some(worker_id) = query.get("worker_id")
        && fields.get("worker_id").is_none()
    {
        fields["worker_id"] = json!(worker_id);
    }
    runtime.close_worker_session(&session_id, fields, protocol).await.map(session_answer).map_err(worker_refusal)
}

async fn list_worker_sessions(Extension(runtime): Extension<Runtime>) -> Result<Json<Value>> {
    runtime.list_worker_sessions().await.map(Json)
}

async fn show_worker_session(Extension(runtime): Extension<Runtime>, Path(session_id): Path<String>) -> Result<Json<Value>> {
    runtime.show_worker_session(&session_id).await.map(Json)
}

async fn worker_heartbeat(
    Extension(runtime): Extension<Runtime>,
    headers: HeaderMap,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    runtime.worker_heartbeat(body, Some(request_protocol(&headers)?)).await.map(Json)
}
fn metrics_config(runtime: &Runtime) -> super::operator_metrics::MetricsConfig {
    super::operator_metrics::MetricsConfig {
        // `MatchingRoleSnapshot` reports the configured task leases.
        workflow_task_lease_seconds: runtime.queries.workflow_task_lease_seconds,
        activity_task_lease_seconds: runtime.queries.activity_task_lease_seconds,
        abandoned_upload_expiry_seconds: runtime.external.abandoned_seconds.max(1),
        structural: runtime.structural,
    }
}

async fn operator_metrics(Extension(runtime): Extension<Runtime>) -> Result<Json<Value>> {
    Ok(Json(
        runtime.operator_metrics(&metrics_config(&runtime)).await?,
    ))
}

async fn operator_dashboard(Extension(runtime): Extension<Runtime>) -> Result<Json<Value>> {
    Ok(Json(runtime.operator_dashboard(&metrics_config(&runtime), false, None).await?))
}

async fn bounded_operator_dashboard(Extension(runtime): Extension<Runtime>) -> Result<Json<Value>> {
    Ok(Json(runtime.operator_dashboard(&metrics_config(&runtime), true, None).await?))
}

/// `workflowTypeOperatorDashboard`: the bounded dashboard with its run
/// figures narrowed to the listed workflow types.
async fn workflow_type_operator_dashboard(
    Extension(runtime): Extension<Runtime>,
    Query(query): Query<std::collections::HashMap<String, String>>,
) -> Result<Json<Value>> {
    let types = super::operator_dashboard::workflow_types(query.get("workflow_types").map(String::as_str))?;
    Ok(Json(runtime.operator_dashboard(&metrics_config(&runtime), true, Some(&types)).await?))
}

type QueryMap = Query<std::collections::HashMap<String, String>>;

async fn repair_status(Extension(runtime): Extension<Runtime>) -> Result<Json<Value>> {
    Ok(Json(runtime.repair_status().await?))
}

/// `SystemController::repairPass`: 207 when a candidate failed.
async fn repair_pass(Extension(runtime): Extension<Runtime>, body: axum::body::Bytes) -> Result<Response> {
    let request = super::repair_pass::pass_request(&request_json(&body)?)?;
    let report = runtime.repair_pass(&request).await?;
    let failed = super::repair_pass::failed(&report);
    Ok(pass_response((report, failed)))
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
    super::json_body::from_slice(body).map_err(|_| super::RuntimeError::Protocol {
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

async fn system_metrics(Extension(runtime): Extension<Runtime>) -> Result<Json<Value>> {
    Ok(Json(runtime.system_metrics().await?))
}

async fn prometheus_metrics(Extension(runtime): Extension<Runtime>) -> Result<Json<Value>> {
    Ok(Json(runtime.prometheus_metrics().await?))
}

async fn payload_cleanup_status(Extension(runtime): Extension<Runtime>) -> Result<Json<Value>> {
    Ok(Json(runtime.payload_cleanup_status(&metrics_config(&runtime)).await?))
}

/// PHP answers 207 when the pass blocked any reference, or while a committed
/// retention deletion is still pending.
async fn payload_cleanup_pass(Extension(runtime): Extension<Runtime>, body: axum::body::Bytes) -> Result<Response> {
    let limit = super::payload_cleanup::pass_limit(&request_json(&body)?)?;
    let report = runtime.payload_cleanup_pass(limit).await?;
    let blocked = report["blocked"].as_i64().is_some_and(|blocked| blocked > 0)
        || report["retention_cleanup"]["pending"].as_i64().is_some_and(|pending| pending > 0);
    Ok(pass_response((json!({"namespace": runtime.namespace(), "cleanup": report}), blocked)))
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
/// `POST /api/task-queues/{name}/build-ids/promote`.
async fn build_id_promote(
    Extension(runtime): Extension<Runtime>,
    Path(task_queue): Path<String>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    build_id_action(runtime, task_queue, body, super::deployments::Action::Promote).await
}
/// `POST /api/task-queues/{name}/build-ids/drain`.
async fn build_id_drain(
    Extension(runtime): Extension<Runtime>,
    Path(task_queue): Path<String>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    build_id_action(runtime, task_queue, body, super::deployments::Action::Drain).await
}
/// `POST /api/task-queues/{name}/build-ids/resume`.
async fn build_id_resume(
    Extension(runtime): Extension<Runtime>,
    Path(task_queue): Path<String>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    build_id_action(runtime, task_queue, body, super::deployments::Action::Resume).await
}
async fn build_id_action(
    runtime: Runtime,
    task_queue: String,
    body: Value,
    action: super::deployments::Action,
) -> Result<(StatusCode, Json<Value>)> {
    let (status, body) = runtime.build_id_action(&task_queue, body, action).await?;
    Ok((status, Json(body)))
}
/// `GET /api/task-queues/{name}/priority-fairness`.
async fn priority_fairness(
    Extension(runtime): Extension<Runtime>,
    Path(task_queue): Path<String>,
) -> Result<Json<Value>> {
    runtime.priority_fairness(&task_queue).await.map(Json)
}
/// `GET /api/deployments`.
async fn list_deployments(Extension(runtime): Extension<Runtime>) -> Result<Json<Value>> {
    runtime.deployments().await.map(Json)
}
/// `GET /api/deployments/{name}`: the name holds slashes,
/// `namespace/task_queue@build_id`.
async fn show_deployment(
    Extension(runtime): Extension<Runtime>,
    Path(name): Path<String>,
) -> Result<(StatusCode, Json<Value>)> {
    let (status, body) = runtime.deployment(&name).await?;
    Ok((status, Json(body)))
}
/// `POST /api/deployments/{name}/{promote|drain|resume|rollback}`.
async fn deployment_action(
    Extension(runtime): Extension<Runtime>,
    Path(name): Path<String>,
    uri: axum::http::Uri,
) -> Response {
    let Some((name, action)) = name
        .rsplit_once('/')
        .and_then(|(name, action)| super::deployments::Action::named(action).map(|action| (name, action)))
    else {
        return route_fallback(axum::http::Method::POST, uri).await;
    };
    match runtime.deployment_action(name, action).await {
        Ok((status, body)) => (status, Json(body)).into_response(),
        Err(error) => error.into_response(),
    }
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
/// `WorkerController::pollWorkflowTasks`' request rules.
const WORKFLOW_POLL_RULES: &super::laravel_rules::Rules = &[
    ("worker_id", &[Rule::Required, Rule::String]),
    ("task_queue", &[Rule::Required, Rule::String]),
    ("build_id", &[Rule::Nullable, Rule::String]),
    ("poll_request_id", &[Rule::Nullable, Rule::String, Rule::Max(255)]),
    ("timeout_seconds", &[Rule::Nullable, Rule::Integer, Rule::Min(0), Rule::Max(60)]),
    ("history_page_size", &[Rule::Nullable, Rule::Integer, Rule::Min(1), Rule::Max(1000)]),
    ("accept_history_encoding", &[Rule::Nullable, Rule::String, Rule::Max(64)]),
    ("task_kinds", &[Rule::Nullable, Rule::Array, Rule::Min(1), Rule::Max(2)]),
    ("task_kinds.*", &[Rule::String, Rule::Distinct, Rule::In(&["workflow", "update_validation"])]),
];

/// `ActivityTaskController::poll`'s request rules.
const ACTIVITY_POLL_RULES: &super::laravel_rules::Rules = &[
    ("worker_id", &[Rule::Required, Rule::String]),
    ("task_queue", &[Rule::Required, Rule::String]),
    ("build_id", &[Rule::Nullable, Rule::String]),
    ("poll_request_id", &[Rule::Nullable, Rule::String, Rule::Max(255)]),
    ("timeout_seconds", &[Rule::Nullable, Rule::Integer, Rule::Min(0), Rule::Max(60)]),
];

/// A poll's request rules, answered as PHP's worker plane answers a
/// validation failure: in the worker envelope.
fn poll_rules(body: &Value, rules: &super::laravel_rules::Rules) -> Result<()> {
    super::laravel_rules::validate(&super::laravel_rules::normalize(body), rules).map_err(worker_refusal)
}

async fn poll_workflow(
    Extension(runtime): Extension<Runtime>,
    headers: HeaderMap,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    let protocol = request_protocol(&headers)?;
    poll_rules(&body, WORKFLOW_POLL_RULES)?;
    runtime
        .poll_workflow_tasks(body, protocol)
        .await
        .map(Json)
        .map_err(worker_refusal)
}

async fn poll_update_validation(
    Extension(runtime): Extension<Runtime>,
    headers: HeaderMap,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    request_protocol(&headers)?;
    runtime
        .poll_update_validation(body)
        .await
        .map(|body| Json(worker_response(body)))
        .map_err(worker_refusal)
}

async fn approve_update_validation(
    Extension(runtime): Extension<Runtime>,
    Path(task_id): Path<String>,
    headers: HeaderMap,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    request_protocol(&headers)?;
    let (status, body) = runtime.complete_update_validation(&task_id, body, false).await.map_err(worker_refusal)?;
    Ok((status, Json(worker_response(body))))
}

async fn reject_update_validation(
    Extension(runtime): Extension<Runtime>,
    Path(task_id): Path<String>,
    headers: HeaderMap,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    request_protocol(&headers)?;
    let (status, body) = runtime.complete_update_validation(&task_id, body, true).await.map_err(worker_refusal)?;
    Ok((status, Json(worker_response(body))))
}
async fn poll_activity(
    Extension(runtime): Extension<Runtime>,
    headers: HeaderMap,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    let protocol = request_protocol(&headers)?;
    poll_rules(&body, ACTIVITY_POLL_RULES)?;
    let wait = runtime.worker_poll_wait();
    runtime
        .poll_with_pages(body, "activity", protocol, Default::default(), wait)
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
    runtime.heartbeat_task(&task_id, body).await.map(|body| Json(worker_response(body)))
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
        // `WorkerProtocol::json`: every answer carries the worker envelope,
        // the bridge's refused deliveries included.
        .map(|body| Json(worker_response(body)))
        .map_err(worker_refusal)
}
async fn complete_workflow(
    Extension(runtime): Extension<Runtime>,
    request: CommandRequest,
    Path(task_id): Path<String>,
    headers: HeaderMap,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    let body = completion_checks(&task_id, &headers, body)?;
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
        .map(|body| Json(worker_response(body)))
}

/// The completion request's checks before the runtime, shared by the
/// completion and the cancellation scope checkpoint.
fn completion_checks(task_id: &str, headers: &HeaderMap, body: Value) -> Result<Value> {
    // PHP validates the message-stream fields first, then its transport
    // rules, before every guard, ownership included.
    super::message_streams::check_completion_fields(&body).map_err(worker_refusal)?;
    let body = super::completion_rules::checked(body).map_err(worker_refusal)?;
    if body["commands"].as_array().is_some_and(|commands| {
        commands
            .iter()
            .any(|command| command["type"] == "upsert_memo")
    }) {
        memo_protocol(headers)?;
    }
    // `guardTypedSearchAttributeCommandsAvailable`.
    if body["commands"].as_array().is_some_and(|commands| {
        commands.iter().any(|command| {
            command["type"] == "upsert_search_attributes" && command.get("attribute_types").is_some()
        })
    }) && !protocol_at_least(headers, 16)?
    {
        let minimum = super::search_attribute_upserts::MINIMUM_PROTOCOL_VERSION;
        return Err(super::RuntimeError::Protocol {
            status: StatusCode::CONFLICT,
            response: json!({"task_id":task_id,"workflow_task_attempt":body["workflow_task_attempt"],
                "outcome":"rejected","recorded":false,"reason":"typed_search_attributes_unavailable",
                "requested_version":request_protocol(headers)?,"minimum_protocol_version":minimum,
                "remediation":format!("Complete typed search-attribute updates only through server nodes advertising worker protocol {minimum} or newer.")}),
        });
    }
    if !super::message_streams::Completion::of(&body).is_empty() && !protocol_at_least(headers, 15)? {
        let minimum = super::message_streams::MINIMUM_PROTOCOL_VERSION;
        return Err(super::RuntimeError::Protocol {
            status: StatusCode::CONFLICT,
            response: json!({"task_id":task_id,"workflow_task_attempt":body["workflow_task_attempt"],
                "outcome":"rejected","reason":"message_streams_unavailable",
                "requested_version":request_protocol(headers)?,"minimum_protocol_version":minimum,
                "remediation":format!("Send the X-Durable-Workflow-Protocol-Version header with worker protocol {minimum} or newer.")}),
        });
    }
    Ok(body)
}

/// POST …/cancellation-scopes/checkpoint (`checkpointCancellationScopePrefix`):
/// protocol 1.20, the checkpoint fields, then a completion's checks and
/// guards, with Core's checkpoint in place of the completion. Every answer
/// is in the worker envelope; Core's answers carry the history refresh
/// token, at 200, 404 for an unknown task, or 409.
async fn checkpoint_cancellation_scope(
    Extension(runtime): Extension<Runtime>,
    request: CommandRequest,
    Path(task_id): Path<String>,
    headers: HeaderMap,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    let protocol = request_protocol(&headers)?;
    if !super::cancellation_scopes::protocol_supported(protocol) {
        return Ok((StatusCode::CONFLICT, Json(worker_response(json!({"checkpointed": false,
            "reason": "cancellation_scope_checkpoint_requires_protocol_1_20"})))));
    }
    let normalized = super::laravel_rules::normalize(&body);
    super::laravel_rules::validate(&normalized, super::scope_checkpoint::RULES).map_err(worker_refusal)?;
    let checkpoint = super::scope_checkpoint::Checkpoint::of(&normalized);
    let body = completion_checks(&task_id, &headers, body)?;
    let attempt = body["workflow_task_attempt"].clone();
    let mut response = runtime
        .checkpoint_cancellation_scope(&task_id, body, Some(request.principal().clone()), Some(protocol), &checkpoint)
        .await
        .map_err(|error| completion_refusal(error, &task_id, "workflow_task_attempt", attempt, "Workflow task not found."))?;
    let status = super::scope_checkpoint::status(&response);
    // Server's claim check answers before Core, without the token.
    if response["reason"] != "active_claim_cancellation_not_supported" {
        response["history_refresh_page_token"] = json!("MA==");
    }
    Ok((status, Json(worker_response(response))))
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
        .map(|body| Json(worker_response(body)))
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
        .map(|body| Json(worker_response(body)))
}

fn completion_refusal(
    error: super::RuntimeError,
    task_id: &str,
    attempt_field: &str,
    attempt: Value,
    message: &str,
) -> super::RuntimeError {
    match error {
        // The bridge's refusal of a batch it cannot apply, in PHP's
        // completion shape.
        super::RuntimeError::Refused { status, reason: "invalid_commands" } if status == StatusCode::CONFLICT => {
            let mut response = json!({"task_id":task_id,"outcome":"completed","recorded":false,"run_id":null,
                "run_status":null,"created_task_ids":[],"reason":"invalid_commands"});
            response[attempt_field] = attempt;
            super::RuntimeError::Protocol { status, response: worker_response(response) }
        }
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
    let attempt = body["activity_attempt_id"].clone();
    runtime
        .heartbeat_activity(&task_id, body, request_protocol(&headers)?)
        .await
        .map_err(|error| activity_not_found(error, &task_id, attempt))
        .map(Json)
}

/// `guardAttemptOwnership`'s answer for a task the namespace does not hold,
/// in the worker envelope, as the fail and heartbeat routes give it.
fn activity_not_found(error: super::RuntimeError, task_id: &str, attempt: Value) -> super::RuntimeError {
    match error {
        super::RuntimeError::Refused { status, reason: "task_not_found" } if status == StatusCode::NOT_FOUND => {
            super::RuntimeError::Protocol { status, response: worker_response(json!({"task_id":task_id,
                "activity_attempt_id":attempt,"error":"Activity task not found.","reason":"task_not_found"})) }
        }
        other => other,
    }
}

async fn renew_activity_lease(
    Extension(runtime): Extension<Runtime>,
    Path(task_id): Path<String>,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    runtime.renew_activity_lease(&task_id, body).await.map(Json)
}

/// POST …/cancellation-scopes/open, prepare and deliver: each answer is
/// the route's own body and status, in the worker envelope.
async fn open_cancellation_scope(
    Extension(runtime): Extension<Runtime>,
    Path(task_id): Path<String>,
    headers: HeaderMap,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    let (status, body) = runtime.open_cancellation_scope(&task_id, body, request_protocol(&headers)?).await?;
    Ok((status, Json(body)))
}

async fn prepare_cancellation_scope(
    Extension(runtime): Extension<Runtime>,
    Path(task_id): Path<String>,
    headers: HeaderMap,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    let (status, body) = runtime.prepare_cancellation_scope(&task_id, body, request_protocol(&headers)?).await?;
    Ok((status, Json(body)))
}

async fn deliver_cancellation_scope(
    Extension(runtime): Extension<Runtime>,
    Path(task_id): Path<String>,
    headers: HeaderMap,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    let (status, body) = runtime.deliver_cancellation_scope(&task_id, body, request_protocol(&headers)?).await?;
    Ok((status, Json(body)))
}

/// POST …/local-activities/*: refused before any task is read, as a PHP
/// server whose bridge lacks the prepared role refuses them.
async fn prepared_local_refused(headers: HeaderMap, body: axum::body::Bytes) -> Result<(StatusCode, Json<Value>)> {
    prepared_local(&headers, &body, super::prepared_local::Capability::Prepared)
}

async fn prepared_local_group_refused(headers: HeaderMap, body: axum::body::Bytes) -> Result<(StatusCode, Json<Value>)> {
    prepared_local(&headers, &body, super::prepared_local::Capability::Groups)
}

fn prepared_local(headers: &HeaderMap, body: &[u8], capability: super::prepared_local::Capability) -> Result<(StatusCode, Json<Value>)> {
    let protocol = headers.get("x-durable-workflow-protocol-version").and_then(|value| value.to_str().ok());
    let (status, response) = super::prepared_local::refusal(&request_json(body)?, protocol, capability);
    Ok((status, Json(response)))
}

async fn acknowledge_activity_cancellation(
    Extension(runtime): Extension<Runtime>,
    Path(task_id): Path<String>,
    headers: HeaderMap,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    runtime
        .acknowledge_activity_cancellation(&task_id, body, request_protocol(&headers)?)
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
    let attempt = body["activity_attempt_id"].clone();
    runtime
        .fail_activity(&task_id, body)
        .await
        .map_err(|error| activity_not_found(error, &task_id, attempt))
        .map(|body| Json(worker_response(body)))
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
/// `GET /api/service-catalog/{catalog}`: endpoints, services, operations or calls.
async fn service_catalog_list(
    Extension(runtime): Extension<Runtime>,
    Path(catalog): Path<String>,
    Query(query): Query<Vec<(String, String)>>,
) -> Response {
    match super::service_catalog::Catalog::from_path(&catalog) {
        Some(catalog) => runtime.service_catalog_list(&query, catalog).await.map(Json).into_response(),
        None => unavailable().await,
    }
}

/// `GET /api/service-catalog/{catalog}/{id}`.
async fn service_catalog_show(
    Extension(runtime): Extension<Runtime>,
    Path((catalog, id)): Path<(String, String)>,
    Query(query): Query<Vec<(String, String)>>,
) -> Response {
    match super::service_catalog::Catalog::from_path(&catalog) {
        Some(catalog) => runtime.service_catalog_show(&query, catalog, &id).await.map(Json).into_response(),
        None => unavailable().await,
    }
}

/// PHP's admin-only catalog routes: an endpoint, its services, a service's
/// operations, and each of those by name. Service-call reads, execution and
/// cancellation lie deeper and are operator routes.
fn catalog_admin_route(path: &str) -> bool {
    let Some(rest) = path.strip_prefix("/api/service-endpoints") else {
        return false;
    };
    let Some(rest) = rest.strip_prefix('/') else {
        return rest.is_empty();
    };
    let segments: Vec<&str> = rest.split('/').collect();
    segments.iter().all(|segment| !segment.is_empty())
        && matches!(
            segments.as_slice(),
            [_] | [_, "services"] | [_, "services", _] | [_, "services", _, "operations"] | [_, "services", _, "operations", _]
        )
}

/// PHP's operator routes for an operation's calls: execute, and a call's
/// show and cancel.
fn catalog_call_route(path: &str) -> bool {
    let Some(rest) = path.strip_prefix("/api/service-endpoints/") else {
        return false;
    };
    let segments: Vec<&str> = rest.split('/').collect();
    segments.iter().all(|segment| !segment.is_empty())
        && matches!(
            segments.as_slice(),
            [_, "services", _, "operations", _, "execute"]
                | [_, "services", _, "operations", _, "service-calls", _]
                | [_, "services", _, "operations", _, "service-calls", _, "cancel"]
        )
}

type CatalogQuery = Query<Vec<(String, String)>>;
type Created = Result<(StatusCode, Json<Value>)>;

/// The input a catalog write validates: `$request->all()`.
fn catalog_input(body: &[u8], query: &[(String, String)], level: super::service_endpoints::Level, create: bool) -> Result<Value> {
    let rules = super::service_endpoints::rules_for(level, create);
    Ok(super::service_endpoints::input(request_json(body)?, query, &rules))
}

/// `GET /api/service-endpoints`.
async fn service_endpoints_index(Extension(runtime): Extension<Runtime>) -> Result<Json<Value>> {
    runtime.list_service_endpoints().await.map(Json)
}

/// `POST /api/service-endpoints`.
async fn service_endpoint_store(Extension(runtime): Extension<Runtime>, Query(query): CatalogQuery, body: axum::body::Bytes) -> Created {
    let input = catalog_input(&body, &query, super::service_endpoints::Level::Endpoint, true)?;
    Ok((StatusCode::CREATED, Json(runtime.create_service_endpoint(input).await?)))
}

async fn service_endpoint_show(Extension(runtime): Extension<Runtime>, Path(endpoint): Path<String>) -> Result<Json<Value>> {
    runtime.show_service_endpoint(&endpoint).await.map(Json)
}

async fn service_endpoint_update(
    Extension(runtime): Extension<Runtime>,
    Path(endpoint): Path<String>,
    Query(query): CatalogQuery,
    body: axum::body::Bytes,
) -> Result<Json<Value>> {
    let input = catalog_input(&body, &query, super::service_endpoints::Level::Endpoint, false)?;
    runtime.update_service_endpoint(&endpoint, input).await.map(Json)
}

async fn service_endpoint_destroy(Extension(runtime): Extension<Runtime>, Path(endpoint): Path<String>) -> Result<Json<Value>> {
    runtime.delete_service_endpoint(&endpoint).await.map(Json)
}

async fn services_index(Extension(runtime): Extension<Runtime>, Path(endpoint): Path<String>) -> Result<Json<Value>> {
    runtime.list_services(&endpoint).await.map(Json)
}

async fn service_store(
    Extension(runtime): Extension<Runtime>,
    Path(endpoint): Path<String>,
    Query(query): CatalogQuery,
    body: axum::body::Bytes,
) -> Created {
    let input = catalog_input(&body, &query, super::service_endpoints::Level::Service, true)?;
    Ok((StatusCode::CREATED, Json(runtime.create_service(&endpoint, input).await?)))
}

async fn service_show(Extension(runtime): Extension<Runtime>, Path((endpoint, service)): Path<(String, String)>) -> Result<Json<Value>> {
    runtime.show_service(&endpoint, &service).await.map(Json)
}

async fn service_update(
    Extension(runtime): Extension<Runtime>,
    Path((endpoint, service)): Path<(String, String)>,
    Query(query): CatalogQuery,
    body: axum::body::Bytes,
) -> Result<Json<Value>> {
    let input = catalog_input(&body, &query, super::service_endpoints::Level::Service, false)?;
    runtime.update_service(&endpoint, &service, input).await.map(Json)
}

async fn service_destroy(Extension(runtime): Extension<Runtime>, Path((endpoint, service)): Path<(String, String)>) -> Result<Json<Value>> {
    runtime.delete_service(&endpoint, &service).await.map(Json)
}

async fn operations_index(Extension(runtime): Extension<Runtime>, Path((endpoint, service)): Path<(String, String)>) -> Result<Json<Value>> {
    runtime.list_operations(&endpoint, &service).await.map(Json)
}

async fn operation_store(
    Extension(runtime): Extension<Runtime>,
    Path((endpoint, service)): Path<(String, String)>,
    Query(query): CatalogQuery,
    body: axum::body::Bytes,
) -> Created {
    let input = catalog_input(&body, &query, super::service_endpoints::Level::Operation, true)?;
    Ok((StatusCode::CREATED, Json(runtime.create_operation(&endpoint, &service, input).await?)))
}

async fn operation_show(
    Extension(runtime): Extension<Runtime>,
    Path((endpoint, service, operation)): Path<(String, String, String)>,
) -> Result<Json<Value>> {
    runtime.show_operation([&endpoint, &service, &operation]).await.map(Json)
}

async fn operation_update(
    Extension(runtime): Extension<Runtime>,
    Path((endpoint, service, operation)): Path<(String, String, String)>,
    Query(query): CatalogQuery,
    body: axum::body::Bytes,
) -> Result<Json<Value>> {
    let input = catalog_input(&body, &query, super::service_endpoints::Level::Operation, false)?;
    runtime.update_operation([&endpoint, &service, &operation], input).await.map(Json)
}

async fn operation_destroy(
    Extension(runtime): Extension<Runtime>,
    Path((endpoint, service, operation)): Path<(String, String, String)>,
) -> Result<Json<Value>> {
    runtime.delete_operation([&endpoint, &service, &operation]).await.map(Json)
}

/// `POST .../operations/{operation}/execute`.
async fn operation_execute(
    Extension(runtime): Extension<Runtime>,
    Extension(authenticated): Extension<AuthenticatedPrincipal>,
    Path((endpoint, service, operation)): Path<(String, String, String)>,
    Query(query): CatalogQuery,
    body: axum::body::Bytes,
) -> Created {
    let caller = super::service_calls::Caller::from_principal(&authenticated.principal);
    let (status, body) = runtime
        .execute_service_operation([&endpoint, &service, &operation], request_json(&body)?, &query, caller)
        .await?;
    Ok((status, Json(body)))
}

/// `GET .../operations/{operation}/service-calls/{call}`.
async fn service_call_show(
    Extension(runtime): Extension<Runtime>,
    Path((endpoint, service, operation, call)): Path<(String, String, String, String)>,
) -> Result<Json<Value>> {
    runtime.show_service_call([&endpoint, &service, &operation], &call).await.map(Json)
}

/// `POST .../service-calls/{call}/cancel`.
async fn service_call_cancel(
    Extension(runtime): Extension<Runtime>,
    Path((endpoint, service, operation, call)): Path<(String, String, String, String)>,
    Query(query): CatalogQuery,
    body: axum::body::Bytes,
) -> Created {
    let (status, body) = runtime
        .cancel_service_operation_call([&endpoint, &service, &operation], &call, request_json(&body)?, &query)
        .await?;
    Ok((status, Json(body)))
}

/// A request no native route serves gets PHP's answer for it: 501 when PHP
/// serves it and native does not yet, else PHP's 405 or 404.
async fn route_fallback(method: axum::http::Method, uri: axum::http::Uri) -> Response {
    let path = uri.path();
    let (status, body) = match super::route_fallback::answer(method.as_str(), path) {
        super::route_fallback::Answer::NotImplemented => return unavailable().await,
        super::route_fallback::Answer::MethodNotAllowed(message) => {
            (StatusCode::METHOD_NOT_ALLOWED, json!({"message":message,"reason":"method_not_allowed"}))
        }
        super::route_fallback::Answer::NotFound(message) => (StatusCode::NOT_FOUND, json!({"message":message,"reason":"not_found"})),
    };
    // PHP's worker plane adds its protocol fields to every answer.
    let body = if path.starts_with("/api/worker/") { worker_response(body) } else { body };
    (status, Json(body)).into_response()
}
async fn unavailable() -> Response {
    refuse(
        StatusCode::NOT_IMPLEMENTED,
        "rust_capability_not_implemented",
    )
    .into_response()
}
