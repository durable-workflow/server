use axum::{
    Extension, Json, Router,
    extract::{DefaultBodyLimit, Path, Query, Request, State},
    http::{HeaderMap, HeaderValue, Method, StatusCode},
    middleware::{self, Next},
    response::{IntoResponse, Response},
    routing::{delete, get, post},
};
use base64::{Engine, engine::general_purpose::STANDARD};
use serde::Deserialize;
use serde_json::{Value, json};
use subtle::ConstantTimeEq;

use super::{Result, Runtime, refuse, store::capabilities};

pub fn router(runtime: Runtime) -> Router {
    Router::new()
        .route("/api/health", get(health))
        .route("/api/ready", get(ready))
        .route("/api/cluster/info", get(cluster))
        .route(
            "/api/namespaces",
            get(list_namespaces).post(create_namespace),
        )
        .route("/api/namespaces/{namespace}", get(describe_namespace))
        .route("/api/workflows", get(list_workflows).post(start))
        .route("/api/schedules", post(super::schedule_http::create))
        .route(
            "/api/schedules/{schedule_id}",
            get(super::schedule_http::describe).delete(super::schedule_http::remove),
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
        .route("/api/workflows/{workflow_id}", get(describe_current))
        .route("/api/workflows/{workflow_id}/cancel", post(cancel_current))
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
        .route("/api/worker/register", post(register))
        .route("/api/worker/heartbeat", post(worker_heartbeat))
        .route("/api/worker/registrations/{worker_id}", delete(deregister))
        .route(
            "/api/worker/registrations/{worker_id}/deregister",
            post(deregister_fenced),
        )
        .route("/api/workers/{worker_id}", delete(deregister))
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
            "/api/worker/query-tasks/{task_id}/complete",
            post(complete_query),
        )
        .route("/api/worker/query-tasks/{task_id}/fail", post(fail_query))
        .fallback(unavailable)
        .layer(DefaultBodyLimit::max(3 * 1024 * 1024))
        .layer(middleware::from_fn_with_state(runtime.clone(), guard))
        .with_state(runtime)
}

async fn guard(State(runtime): State<Runtime>, mut request: Request, next: Next) -> Response {
    request.extensions_mut().insert(runtime.clone());
    let path = request.uri().path().to_owned();
    if path == "/api/health" || path == "/api/ready" {
        return next.run(request).await;
    }
    let worker = path == "/api/worker" || path.starts_with("/api/worker/");
    let authorization = request
        .headers()
        .get("authorization")
        .and_then(|v| v.to_str().ok());
    let provided = authorization.and_then(|v| v.strip_prefix("Bearer "));
    let roles = ["worker", "operator", "admin"];
    let authenticated = provided.and_then(|provided| {
        // Match configured roles before the legacy credential, matching PHP's
        // collision precedence. Equal-length secret contents use constant-time
        // comparison; secrets never enter response or diagnostic values.
        for (secret, role) in runtime.role_tokens.iter().zip(roles) {
            if secret
                .as_ref()
                .is_some_and(|secret| bool::from(provided.as_bytes().ct_eq(secret.as_bytes())))
            {
                return Some((role, false));
            }
        }
        bool::from(provided.as_bytes().ct_eq(runtime.token.as_bytes()))
            .then(|| ("admin", runtime.role_tokens.iter().all(Option::is_none)))
    });
    let Some((role, legacy_full_access)) = authenticated else {
        return admission_error(
            request,
            worker,
            StatusCode::UNAUTHORIZED,
            json!({"reason":"unauthorized", "message":"Invalid or missing authentication token."}),
        )
        .await;
    };
    let allowed_roles: &[&str] = if path == "/api/worker/register" || path == "/api/cluster/info" {
        &["worker", "operator", "admin"]
    } else if worker {
        &["worker"]
    } else if path.starts_with("/api/workers/")
        || path == "/api/namespaces" && request.method() == Method::POST
    {
        &["admin"]
    } else {
        &["operator", "admin"]
    };
    if !legacy_full_access && !allowed_roles.contains(&role) {
        return admission_error(request, worker, StatusCode::FORBIDDEN, json!({
            "reason":"forbidden", "message":"Authenticated role is not allowed to access this endpoint.",
            "role":role, "allowed_roles":allowed_roles,
        })).await;
    }
    let header = if worker {
        "x-durable-workflow-protocol-version"
    } else {
        "x-durable-workflow-control-plane-version"
    };
    let version = if worker { "1.20" } else { "2" };
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
    if !compatible {
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
    let mut response = next.run(request).await;
    response
        .headers_mut()
        .insert(header, HeaderValue::from_static(version));
    response
}

fn named_namespace_operation(method: &str, path: &str) -> bool {
    // Keep unimplemented families from reaching default-namespace selectors.
    // Further families require their own shared namespace fixtures.
    if path == "/api/workflows" {
        return method == "POST";
    }
    if let Some(path) = path.strip_prefix("/api/workflows/") {
        let parts: Vec<_> = path.split('/').collect();
        return matches!(
            parts.as_slice(),
            [_] | [_, "cancel"] | [_, "runs", _] | [_, "runs", _, "history" | "cancel"]
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
async fn create_namespace(
    Extension(runtime): Extension<Runtime>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    Ok((
        StatusCode::CREATED,
        Json(runtime.create_namespace(body).await?),
    ))
}

async fn admission_error(
    request: Request,
    worker: bool,
    status: StatusCode,
    mut body: Value,
) -> Response {
    let (header, version) = if worker {
        body["protocol_version"] = json!("1.20");
        body["server_capabilities"] = capabilities();
        ("x-durable-workflow-protocol-version", "1.20")
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
    (
        if ready {
            StatusCode::OK
        } else {
            StatusCode::SERVICE_UNAVAILABLE
        },
        Json(json!({
        "status": if ready { "ready" } else { "not_ready" }, "development": true,
        "checks": {"database": {"status": if ready { "ok" } else { "failed" },
            "mode": "schema_read", "write_capacity_verified": false}},
        "qualification": "unpublished_execution_slice"})),
    )
        .into_response()
}

async fn cluster() -> Json<Value> {
    let auth_composition: Value = serde_json::from_str(include_str!("auth-composition.v1.json"))
        .expect("valid bundled carrier auth composition contract");
    Json(
        json!({"version": env!("CARGO_PKG_VERSION"), "implementation": "rust", "development": true,
        "control_plane": {"version": "2", "request_contract": super::visibility::request_contract()}, "worker_protocol": {"version": "1.20", "server_capabilities": capabilities()},
        "auth_composition_contract": auth_composition,
        "payload_codec": "avro", "qualified_for_php_takeover": false}),
    )
}

async fn start(
    Extension(runtime): Extension<Runtime>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    let workflow_id = body["workflow_id"].as_str().unwrap_or("").to_owned();
    let result = runtime.start(body).await.map_err(|error| {
        super::control_plane::Operation::Start.refusal(error, &workflow_id, None)
    })?;
    let status = if result["outcome"] == "started_new" {
        StatusCode::CREATED
    } else {
        StatusCode::OK
    };
    Ok((status, Json(result)))
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

async fn cancel_current(
    Extension(runtime): Extension<Runtime>,
    Path(workflow_id): Path<String>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    let (status, response) = runtime
        .cancel_workflow(&workflow_id, None, body)
        .await
        .map_err(|error| {
            super::control_plane::Operation::Cancel.refusal(error, &workflow_id, None)
        })?;
    Ok((status, Json(response)))
}

async fn cancel_run(
    Extension(runtime): Extension<Runtime>,
    Path((workflow_id, run_id)): Path<(String, String)>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    let (status, response) = runtime
        .cancel_workflow(&workflow_id, Some(&run_id), body)
        .await
        .map_err(|error| {
            super::control_plane::Operation::Cancel.refusal(error, &workflow_id, Some(&run_id))
        })?;
    Ok((status, Json(response)))
}

async fn request_cancellation_current(
    Extension(runtime): Extension<Runtime>,
    Path(workflow_id): Path<String>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    let (status, response) = runtime
        .request_cancellation(&workflow_id, None, body)
        .await?;
    Ok((status, Json(response)))
}

async fn request_cancellation_run(
    Extension(runtime): Extension<Runtime>,
    Path((workflow_id, run_id)): Path<(String, String)>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    let (status, response) = runtime
        .request_cancellation(&workflow_id, Some(&run_id), body)
        .await?;
    Ok((status, Json(response)))
}

async fn signal_current(
    Extension(runtime): Extension<Runtime>,
    Path((workflow_id, name)): Path<(String, String)>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    Ok((
        StatusCode::ACCEPTED,
        Json(runtime.signal(&workflow_id, None, &name, body).await?),
    ))
}

async fn update_current(
    Extension(runtime): Extension<Runtime>,
    Path((workflow_id, name)): Path<(String, String)>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    let (status, result) = runtime
        .update_workflow(&workflow_id, None, &name, body)
        .await?;
    Ok((status, Json(result)))
}

async fn update_run(
    Extension(runtime): Extension<Runtime>,
    Path((workflow_id, run_id, name)): Path<(String, String, String)>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    let (status, result) = runtime
        .update_workflow(&workflow_id, Some(&run_id), &name, body)
        .await?;
    Ok((status, Json(result)))
}

async fn signal_run(
    Extension(runtime): Extension<Runtime>,
    Path((workflow_id, run_id, name)): Path<(String, String, String)>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    Ok((
        StatusCode::ACCEPTED,
        Json(
            runtime
                .signal(&workflow_id, Some(&run_id), &name, body)
                .await?,
        ),
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
    runtime.task_history(&task_id, body).await.map(Json)
}

async fn register(
    Extension(runtime): Extension<Runtime>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    Ok((
        StatusCode::CREATED,
        Json(worker_response(runtime.register(body).await?)),
    ))
}

fn worker_response(mut body: Value) -> Value {
    body["protocol_version"] = json!("1.20");
    body["server_capabilities"] = capabilities();
    body
}
async fn worker_heartbeat(
    Extension(runtime): Extension<Runtime>,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    runtime.worker_heartbeat(body).await.map(Json)
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
    runtime
        .poll(body, "workflow", request_protocol(&headers)?)
        .await
        .map(Json)
}
async fn poll_activity(
    Extension(runtime): Extension<Runtime>,
    headers: HeaderMap,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    runtime
        .poll(body, "activity", request_protocol(&headers)?)
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
        .map(Json)
}
async fn complete_workflow(
    Extension(runtime): Extension<Runtime>,
    Path(task_id): Path<String>,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    let attempt = body["workflow_task_attempt"].clone();
    runtime
        .complete_workflow(&task_id, body)
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
                "protocol_version":"1.20","server_capabilities":capabilities()});
            response[attempt_field] = attempt;
            super::RuntimeError::Protocol { status, response }
        }
        other => other,
    }
}

async fn heartbeat_activity(
    Extension(runtime): Extension<Runtime>,
    Path(task_id): Path<String>,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    runtime.heartbeat_activity(&task_id, body).await.map(Json)
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
async fn query_current(
    Extension(runtime): Extension<Runtime>,
    Path((workflow_id, name)): Path<(String, String)>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    runtime
        .query_workflow(&workflow_id, None, &name, body)
        .await
        .map(|(status, body)| (status, Json(body)))
}
async fn query_run(
    Extension(runtime): Extension<Runtime>,
    Path((workflow_id, run_id, name)): Path<(String, String, String)>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    runtime
        .query_workflow(&workflow_id, Some(&run_id), &name, body)
        .await
        .map(|(status, body)| (status, Json(body)))
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
