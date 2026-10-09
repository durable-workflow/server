use axum::{
    Json, Router,
    extract::{DefaultBodyLimit, Path, Query, Request, State},
    http::{HeaderValue, StatusCode},
    middleware::{self, Next},
    response::{IntoResponse, Response},
    routing::{delete, get, post},
};
use base64::{Engine, engine::general_purpose::STANDARD};
use serde::Deserialize;
use serde_json::{Value, json};

use super::{Result, Runtime, refuse, store::capabilities};

pub fn router(runtime: Runtime) -> Router {
    Router::new()
        .route("/api/health", get(health))
        .route("/api/ready", get(ready))
        .route("/api/cluster/info", get(cluster))
        .route("/api/workflows", post(start))
        .route("/api/workflows/{workflow_id}", get(describe_current))
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
        .route("/api/worker/workflow-tasks/poll", post(poll_workflow))
        .route(
            "/api/worker/workflow-tasks/{task_id}/heartbeat",
            post(heartbeat_task),
        )
        .route(
            "/api/worker/workflow-tasks/{task_id}/complete",
            post(complete_workflow),
        )
        .route("/api/worker/activity-tasks/poll", post(poll_activity))
        .route(
            "/api/worker/workflow-tasks/{task_id}/history",
            post(task_history),
        )
        .route(
            "/api/worker/activity-tasks/{task_id}/complete",
            post(complete_activity),
        )
        .route("/api/worker/query-tasks/poll", post(query_unavailable))
        .fallback(unavailable)
        .layer(DefaultBodyLimit::max(3 * 1024 * 1024))
        .layer(middleware::from_fn_with_state(runtime.clone(), guard))
        .with_state(runtime)
}

async fn guard(State(runtime): State<Runtime>, request: Request, next: Next) -> Response {
    let path = request.uri().path();
    if path == "/api/health" || path == "/api/ready" {
        return next.run(request).await;
    }
    let authorization = request
        .headers()
        .get("authorization")
        .and_then(|v| v.to_str().ok());
    if authorization.and_then(|v| v.strip_prefix("Bearer ")) != Some(runtime.token.as_ref()) {
        return refuse(StatusCode::UNAUTHORIZED, "unauthenticated").into_response();
    }
    let worker = path.starts_with("/api/worker/");
    let header = if worker {
        "x-durable-workflow-protocol-version"
    } else {
        "x-durable-workflow-control-plane-version"
    };
    let version = if worker { "1.19" } else { "2" };
    let requested = request.headers().get(header).and_then(|v| v.to_str().ok());
    let compatible = if worker {
        requested.is_some_and(|v| {
            v.split_once('.').is_some_and(|(major, minor)| {
                major == "1"
                    && !minor.is_empty()
                    && minor.bytes().all(|c| c.is_ascii_digit())
                    && minor.parse::<u8>().is_ok_and(|n| n <= 19)
            })
        })
    } else {
        requested == Some(version) || path == "/api/cluster/info" && requested.is_none()
    };
    if !compatible {
        return (StatusCode::BAD_REQUEST, Json(json!({"reason": if requested.is_none() { "missing_protocol_version" } else { "unsupported_protocol_version" },
            "supported_version": version, "requested_version": requested}))).into_response();
    }
    if request
        .headers()
        .get("x-namespace")
        .is_some_and(|v| v != "default")
    {
        return refuse(StatusCode::NOT_FOUND, "namespace_not_found").into_response();
    }
    let mut response = next.run(request).await;
    response
        .headers_mut()
        .insert(header, HeaderValue::from_static(version));
    response
}

async fn health(State(runtime): State<Runtime>) -> Response {
    let database = runtime.database_live().await;
    (if database { StatusCode::OK } else { StatusCode::SERVICE_UNAVAILABLE },
        Json(json!({"status": if database { "serving" } else { "degraded" }, "checks": {"database": database}}))).into_response()
}

async fn ready(State(runtime): State<Runtime>) -> Response {
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
    Json(
        json!({"version": env!("CARGO_PKG_VERSION"), "implementation": "rust", "development": true,
        "control_plane": {"version": "2"}, "worker_protocol": {"version": "1.19", "server_capabilities": capabilities()},
        "payload_codec": "avro", "qualified_for_php_takeover": false}),
    )
}

async fn start(
    State(runtime): State<Runtime>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    let result = runtime.start(body).await?;
    let status = if result["outcome"] == "started_new" {
        StatusCode::CREATED
    } else {
        StatusCode::OK
    };
    Ok((status, Json(result)))
}

async fn describe_current(
    State(runtime): State<Runtime>,
    Path(workflow_id): Path<String>,
) -> Result<Json<Value>> {
    runtime.describe(&workflow_id, None).await.map(Json)
}

async fn signal_current(
    State(runtime): State<Runtime>,
    Path((workflow_id, name)): Path<(String, String)>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    Ok((
        StatusCode::ACCEPTED,
        Json(runtime.signal(&workflow_id, None, &name, body).await?),
    ))
}

async fn signal_run(
    State(runtime): State<Runtime>,
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
    State(runtime): State<Runtime>,
    Path((workflow_id, run_id)): Path<(String, String)>,
) -> Result<Json<Value>> {
    runtime
        .describe(&workflow_id, Some(&run_id))
        .await
        .map(Json)
}

#[derive(Deserialize)]
struct HistoryQuery {
    page_size: Option<i64>,
    next_page_token: Option<String>,
}

async fn history(
    State(runtime): State<Runtime>,
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
    runtime
        .history(&workflow_id, &run_id, after, page_size)
        .await
        .map(Json)
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
    State(runtime): State<Runtime>,
    Path(task_id): Path<String>,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    runtime.task_history(&task_id, body).await.map(Json)
}

async fn register(
    State(runtime): State<Runtime>,
    Json(body): Json<Value>,
) -> Result<(StatusCode, Json<Value>)> {
    Ok((StatusCode::CREATED, Json(runtime.register(body).await?)))
}
async fn worker_heartbeat(
    State(runtime): State<Runtime>,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    runtime.worker_heartbeat(body).await.map(Json)
}
async fn deregister(
    State(runtime): State<Runtime>,
    Path(worker_id): Path<String>,
) -> Result<Json<Value>> {
    runtime.deregister(&worker_id).await.map(Json)
}
async fn poll_workflow(
    State(runtime): State<Runtime>,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    runtime.poll(body, "workflow").await.map(Json)
}
async fn poll_activity(
    State(runtime): State<Runtime>,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    runtime.poll(body, "activity").await.map(Json)
}
async fn heartbeat_task(
    State(runtime): State<Runtime>,
    Path(task_id): Path<String>,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    runtime.heartbeat_task(&task_id, body).await.map(Json)
}
async fn complete_workflow(
    State(runtime): State<Runtime>,
    Path(task_id): Path<String>,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    runtime.complete_workflow(&task_id, body).await.map(Json)
}
async fn complete_activity(
    State(runtime): State<Runtime>,
    Path(task_id): Path<String>,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    runtime.complete_activity(&task_id, body).await.map(Json)
}
async fn query_unavailable() -> Json<Value> {
    Json(
        json!({"task": null, "poll_status": "no_query_capability", "server_capabilities": capabilities()}),
    )
}
async fn unavailable() -> Response {
    refuse(
        StatusCode::NOT_IMPLEMENTED,
        "rust_capability_not_implemented",
    )
    .into_response()
}
