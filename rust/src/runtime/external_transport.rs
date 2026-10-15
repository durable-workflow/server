//! Versioned byte transport. Full discovery remains disabled until provider,
//! claims, completion allowances, cleanup and backup qualification are complete.
use super::{Result, Runtime, RuntimeError, refuse};
use crate::external_payload::{LocalStorage, REFERENCE_SCHEMA, Reference, SnapshotError, capture};
use axum::{
    Extension, Json,
    body::Body,
    extract::{Path, Request},
    http::{HeaderMap, StatusCode},
    response::{IntoResponse, Response},
};
use futures_util::{StreamExt, TryStreamExt};
use serde_json::{Value, json};
use std::{
    path::PathBuf,
    sync::{
        Arc,
        atomic::{AtomicBool, Ordering},
    },
};
use tokio::time::Instant;
use tokio_util::io::{ReaderStream, StreamReader, SyncIoBridge};

const CODEC: &str = "x-durable-workflow-payload-codec";
const SIZE: &str = "x-durable-workflow-payload-size";
const DIGEST: &str = "x-durable-workflow-payload-sha256";

pub(super) fn error_body(status: StatusCode, reason: &str) -> Value {
    let retryable =
        status == StatusCode::SERVICE_UNAVAILABLE || status == StatusCode::TOO_MANY_REQUESTS;
    let mut body = json!({"schema":"durable-workflow.v2.runtime-external-payload-error.v1",
        "reason":reason,"message":reason,"retryable":retryable,"status":status.as_u16()});
    if reason.starts_with("external_payload_namespace_") {
        body["retry_after_seconds"] = json!(60);
    } else if reason == "external_payload_completion_budget_exhausted" {
        body["retry_after_seconds"] = json!(5);
    }
    body
}

fn response_error(error: RuntimeError) -> Response {
    let (status, reason) = match &error {
        RuntimeError::Refused { status, reason } => (*status, *reason),
        _ => (
            StatusCode::SERVICE_UNAVAILABLE,
            "external_payload_unavailable",
        ),
    };
    let _diagnostic = error.into_response();
    let mut response = (status, Json(error_body(status, reason))).into_response();
    if reason.starts_with("external_payload_namespace_") {
        response
            .headers_mut()
            .insert("retry-after", "60".parse().expect("static header"));
    } else if reason == "external_payload_completion_budget_exhausted" {
        response
            .headers_mut()
            .insert("retry-after", "5".parse().expect("static header"));
    }
    response
}

pub(super) fn snapshot_error(error: SnapshotError) -> RuntimeError {
    let (status, reason) = match error {
        SnapshotError::Oversized => (StatusCode::PAYLOAD_TOO_LARGE, "external_payload_oversized"),
        SnapshotError::IntegrityMismatch => (
            StatusCode::UNPROCESSABLE_ENTITY,
            "external_payload_integrity_mismatch",
        ),
        SnapshotError::NotFound => (StatusCode::NOT_FOUND, "external_payload_not_found"),
        SnapshotError::Unavailable | SnapshotError::Cancelled => (
            StatusCode::SERVICE_UNAVAILABLE,
            "external_payload_unavailable",
        ),
    };
    refuse(status, reason)
}

fn header<'a>(headers: &'a HeaderMap, key: &str) -> Result<&'a str> {
    headers
        .get(key)
        .map(|value| value.to_str())
        .transpose()
        .map_err(|_| {
            refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "external_payload_unsupported",
            )
        })
        .map(|value| value.unwrap_or(""))
}

fn declared_size(headers: &HeaderMap) -> Result<i64> {
    let size = header(headers, SIZE)?;
    if size.is_empty()
        || size != "0" && size.starts_with('0')
        || !size.bytes().all(|byte| byte.is_ascii_digit())
    {
        return Err(refuse(
            StatusCode::UNPROCESSABLE_ENTITY,
            "external_payload_unsupported",
        ));
    }
    // PHP's integer cast saturates a canonical overlong decimal. The configured
    // payload ceiling then refuses it before reading any body or provider bytes.
    Ok(size.parse::<i64>().unwrap_or(i64::MAX))
}

fn metadata(headers: &HeaderMap, identity: &str) -> Result<Reference> {
    let size = declared_size(headers)?;
    Reference::validate(&json!({"schema":REFERENCE_SCHEMA,"reference_id":identity,
        "codec":header(headers, CODEC)?,"size_bytes":size,"sha256":header(headers, DIGEST)?}))
    .map_err(|_| {
        refuse(
            StatusCode::UNPROCESSABLE_ENTITY,
            "external_payload_unsupported",
        )
    })
}

pub(super) fn local_policy(body: Value) -> Result<Value> {
    let invalid = || {
        refuse(
            StatusCode::UNPROCESSABLE_ENTITY,
            "invalid_external_storage_policy",
        )
    };
    if !body.is_object() {
        return Err(invalid());
    }
    if body["driver"] != "local" {
        return Err(refuse(
            StatusCode::UNPROCESSABLE_ENTITY,
            "development_external_storage_driver_unqualified",
        ));
    }
    let enabled = match body.get("enabled") {
        None => true,
        Some(Value::Bool(value)) => *value,
        Some(value) if value == 1 || value == "1" => true,
        Some(value) if value == 0 || value == "0" => false,
        _ => return Err(invalid()),
    };
    let mut policy = json!({"driver":"local","enabled":enabled});
    if let Some(value) = body.get("threshold_bytes").filter(|value| !value.is_null()) {
        let threshold = value
            .as_i64()
            .or_else(|| value.as_str().and_then(|value| value.parse().ok()))
            .filter(|value| *value > 0)
            .ok_or_else(invalid)?;
        policy["threshold_bytes"] = json!(threshold);
    }
    if let Some(config) = body.get("config").filter(|value| !value.is_null()) {
        if !config.is_object() && !config.is_array() {
            return Err(invalid());
        }
        if let Some(uri) = config
            .get("uri")
            .filter(|value| !value.is_null() && *value != "")
        {
            let uri = uri
                .as_str()
                .filter(|value| value.chars().count() <= 2048)
                .ok_or_else(invalid)?;
            policy["config"] = json!({"uri":uri});
        }
    }
    Ok(policy)
}

pub(super) async fn local(runtime: &Runtime) -> Result<LocalStorage> {
    let metadata = runtime.describe_namespace(runtime.namespace()).await?;
    let policy = &metadata["external_payload_storage"];
    if policy.is_null() || policy["enabled"] == false || policy["driver"] != "local" {
        return Err(refuse(
            StatusCode::SERVICE_UNAVAILABLE,
            "external_payload_unavailable",
        ));
    }
    let root = match policy["config"]["uri"]
        .as_str()
        .and_then(|uri| uri.strip_prefix("file://"))
    {
        Some(root) => PathBuf::from(root.trim_end_matches('/')),
        None => std::env::current_dir()?
            .join("storage/app/external-payloads")
            .join(runtime.namespace()),
    };
    LocalStorage::open(root).await.map_err(snapshot_error)
}

pub(super) async fn set_policy(
    Extension(runtime): Extension<Runtime>,
    Path(namespace): Path<String>,
    Json(body): Json<Value>,
) -> Result<Json<Value>> {
    Ok(Json(runtime.set_external_policy(&namespace, body).await?))
}

pub(super) async fn upload(Extension(runtime): Extension<Runtime>, request: Request) -> Response {
    let deadline = Instant::now()
        .checked_add(runtime.external.timeout)
        .unwrap_or_else(Instant::now);
    match tokio::time::timeout_at(deadline, upload_inner(runtime, request, deadline)).await {
        Ok(Ok(response)) => response,
        Ok(Err(error)) => response_error(error),
        Err(_) => response_error(refuse(
            StatusCode::SERVICE_UNAVAILABLE,
            "external_payload_unavailable",
        )),
    }
}

async fn upload_inner(runtime: Runtime, request: Request, deadline: Instant) -> Result<Response> {
    let content_type = header(request.headers(), "content-type")?
        .split(';')
        .next()
        .unwrap_or("")
        .trim();
    if !content_type.eq_ignore_ascii_case("application/octet-stream") {
        return Err(refuse(
            StatusCode::UNSUPPORTED_MEDIA_TYPE,
            "external_payload_unsupported",
        ));
    }
    let context = if request
        .headers()
        .contains_key("x-durable-workflow-payload-completion")
    {
        Some(super::activity_completion_uploads::Context::parse(header(
            request.headers(),
            "x-durable-workflow-payload-completion",
        )?)?)
    } else {
        None
    };
    if declared_size(request.headers())? as u64 > runtime.external.max_bytes.max(1) {
        return Err(refuse(
            StatusCode::PAYLOAD_TOO_LARGE,
            "external_payload_oversized",
        ));
    }
    let expected = metadata(request.headers(), &format!("ep_{}", ulid::Ulid::new()))?;
    if let Some(context) = &context {
        runtime
            .authorize_completion_upload(context, &expected)
            .await?;
    }
    let timed_out = Arc::new(AtomicBool::new(false));
    let timeout_flag = timed_out.clone();
    let stream = request
        .into_body()
        .into_data_stream()
        .map_err(|_| std::io::Error::other("external_payload_unavailable"))
        .take_until(async move {
            tokio::time::sleep_until(deadline).await;
            timeout_flag.store(true, Ordering::Release);
        });
    let source = SyncIoBridge::new(Box::pin(StreamReader::new(stream)));
    let snapshot = capture(
        source,
        expected.clone(),
        runtime.external.max_bytes,
        std::env::temp_dir(),
        runtime.external.workers.clone(),
    )
    .await;
    if timed_out.load(Ordering::Acquire) {
        return Err(refuse(
            StatusCode::SERVICE_UNAVAILABLE,
            "external_payload_unavailable",
        ));
    }
    let snapshot = snapshot.map_err(snapshot_error)?;
    if let Some(context) = &context
        && let Some(reference) = runtime
            .authorize_completion_upload(context, &expected)
            .await?
    {
        return Ok(upload_response(reference));
    }
    let local = local(&runtime).await?;
    let quota = runtime.external.quota(runtime.namespace())?;
    let expires = chrono::Duration::try_seconds(runtime.external.abandoned_seconds.max(1))
        .and_then(|duration| super::store::now().checked_add_signed(duration))
        .ok_or_else(|| {
            refuse(
                StatusCode::SERVICE_UNAVAILABLE,
                "external_payload_unavailable",
            )
        })?;
    let reference = if let Some(context) = context {
        runtime
            .upload_activity_completion(
                local,
                expected,
                snapshot,
                expires,
                quota,
                context,
                runtime
                    .external
                    .completion_max_bytes
                    .unwrap_or(runtime.external.max_bytes),
                runtime.external.abandoned_seconds,
            )
            .await?
    } else {
        runtime
            .upload_external_local(local, expected, snapshot, expires, quota)
            .await?
    };
    Ok(upload_response(reference))
}

fn upload_response(reference: Reference) -> Response {
    (StatusCode::CREATED, [("cache-control", "no-store, private")], Json(json!({
        "schema":"durable-workflow.v2.runtime-external-payload-upload.v1","transport_version":1,"reference":reference
    }))).into_response()
}

pub(super) async fn fetch(
    Extension(runtime): Extension<Runtime>,
    Path(identity): Path<String>,
    headers: HeaderMap,
) -> Response {
    match tokio::time::timeout(
        runtime.external.timeout,
        fetch_inner(runtime.clone(), identity, headers),
    )
    .await
    {
        Ok(Ok(response)) => response,
        Ok(Err(error)) => response_error(error),
        Err(_) => response_error(refuse(
            StatusCode::SERVICE_UNAVAILABLE,
            "external_payload_unavailable",
        )),
    }
}

async fn fetch_inner(runtime: Runtime, identity: String, headers: HeaderMap) -> Result<Response> {
    let expected = metadata(&headers, &identity)?;
    if expected.size_bytes as u64 > runtime.external.max_bytes.max(1) {
        return Err(refuse(
            StatusCode::PAYLOAD_TOO_LARGE,
            "external_payload_oversized",
        ));
    }
    let registered = runtime.external_payload_for_fetch(&expected).await?;
    let snapshot = local(&runtime)
        .await?
        .fetch(
            registered.reference,
            &registered.uri,
            runtime.external.max_bytes,
            std::env::temp_dir(),
            runtime.external.workers.clone(),
        )
        .await
        .map_err(snapshot_error)?;
    runtime.mark_external_fetched(&expected).await?;
    let mut response = Response::new(Body::from_stream(ReaderStream::with_capacity(
        snapshot.into_async(),
        8192,
    )));
    for (name, value) in [
        ("content-type", "application/octet-stream".to_owned()),
        ("content-length", expected.size_bytes.to_string()),
        (CODEC, expected.codec.to_owned()),
        (SIZE, expected.size_bytes.to_string()),
        (DIGEST, expected.sha256.clone()),
        ("etag", format!("\"sha256:{}\"", expected.sha256)),
        ("cache-control", "private, max-age=60, immutable".to_owned()),
    ] {
        response.headers_mut().insert(
            axum::http::header::HeaderName::from_bytes(name.as_bytes()).expect("static header"),
            value.parse().expect("validated bounded metadata"),
        );
    }
    Ok(response)
}
