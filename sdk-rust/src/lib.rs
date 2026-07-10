//! Minimal Rust SDK for the Durable Workflow worker protocol.
//!
//! The crate covers the v1 Rust round-trip: start and signal workflows,
//! register a Rust worker, poll workflow and activity tasks, heartbeat worker
//! and activity liveness, and exchange JSON-native payloads through the same
//! `avro` generic wrapper used by the existing first-party SDKs.

use std::{
    collections::HashMap,
    future::Future,
    pin::Pin,
    sync::{
        atomic::{AtomicBool, Ordering},
        Arc, Mutex,
    },
    task::{Context as TaskContext, Poll},
    time::{Duration, Instant, SystemTime, UNIX_EPOCH},
};

use base64::{engine::general_purpose::STANDARD as BASE64, Engine as _};
use futures_util::{future::OptionFuture, task::noop_waker_ref};
use serde::{de::DeserializeOwned, Deserialize, Serialize};
pub use serde_json::{json, Value};
use thiserror::Error;

pub const WORKER_PROTOCOL_VERSION: &str = "1.2";
pub const CONTROL_PLANE_VERSION: &str = "2";
pub const DEFAULT_CODEC: &str = "avro";
pub const JSON_CODEC: &str = "json";
pub const SDK_VERSION: &str = concat!("durable-workflow-rust/", env!("CARGO_PKG_VERSION"));

pub type Result<T> = std::result::Result<T, Error>;

#[derive(Debug, Error)]
pub enum Error {
    #[error("transport error: {0}")]
    Transport(#[from] reqwest::Error),
    #[error("json error: {0}")]
    Json(#[from] serde_json::Error),
    #[error("http {status}: {body}")]
    Http {
        status: reqwest::StatusCode,
        body: String,
    },
    #[error("codec error: {0}")]
    Codec(String),
    #[error("workflow handler {0:?} is not registered")]
    WorkflowNotRegistered(String),
    #[error("activity handler {0:?} is not registered")]
    ActivityNotRegistered(String),
    #[error("workflow future yielded without emitting a durable command")]
    WorkflowYieldedWithoutCommand,
    #[error("workflow state lock is poisoned")]
    WorkflowStatePoisoned,
    #[error("operation timed out")]
    Timeout,
    #[error("worker loop error: {0}")]
    WorkerLoop(String),
}

#[derive(Clone, Debug, Serialize, Deserialize, PartialEq, Eq)]
pub struct PayloadEnvelope {
    pub codec: String,
    pub blob: String,
}

impl PayloadEnvelope {
    pub fn avro<T: Serialize>(value: &T) -> Result<Self> {
        encode_payload(value, DEFAULT_CODEC)
    }

    pub fn json<T: Serialize>(value: &T) -> Result<Self> {
        encode_payload(value, JSON_CODEC)
    }
}

pub fn encode_payload<T: Serialize>(value: &T, codec: &str) -> Result<PayloadEnvelope> {
    let value = serde_json::to_value(value)?;
    let blob = encode_value_blob(&value, codec)?;

    Ok(PayloadEnvelope {
        codec: codec.to_string(),
        blob,
    })
}

pub fn decode_payload<T: DeserializeOwned>(envelope: &PayloadEnvelope) -> Result<T> {
    let value = decode_blob(&envelope.blob, &envelope.codec)?;
    Ok(serde_json::from_value(value)?)
}

fn encode_value_envelope(value: &Value, codec: &str) -> Result<Value> {
    Ok(serde_json::to_value(encode_payload(value, codec)?)?)
}

fn encode_value_blob(value: &Value, codec: &str) -> Result<String> {
    match codec {
        JSON_CODEC => Ok(serde_json::to_string(value)?),
        DEFAULT_CODEC => encode_avro_generic(value),
        other => Err(Error::Codec(format!("unsupported payload codec {other:?}"))),
    }
}

fn decode_wire_value(value: &Value, fallback_codec: &str) -> Result<Value> {
    if value.is_null() {
        return Ok(Value::Null);
    }

    if let Some(object) = value.as_object() {
        if let (Some(codec), Some(blob)) = (
            object.get("codec").and_then(Value::as_str),
            object.get("blob").and_then(Value::as_str),
        ) {
            return decode_blob(blob, codec);
        }
    }

    if let Some(blob) = value.as_str() {
        return decode_blob(blob, fallback_codec);
    }

    Ok(value.clone())
}

fn decode_blob(blob: &str, codec: &str) -> Result<Value> {
    match codec {
        JSON_CODEC => Ok(serde_json::from_str(blob)?),
        DEFAULT_CODEC => decode_avro_generic(blob),
        other => Err(Error::Codec(format!("unsupported payload codec {other:?}"))),
    }
}

fn encode_avro_generic(value: &Value) -> Result<String> {
    let json = serde_json::to_string(value)?;
    let mut bytes = Vec::with_capacity(json.len() + 8);
    bytes.push(0x00);
    write_avro_long(json.len() as i64, &mut bytes);
    bytes.extend_from_slice(json.as_bytes());
    write_avro_int(1, &mut bytes);
    Ok(BASE64.encode(bytes))
}

fn decode_avro_generic(blob: &str) -> Result<Value> {
    let bytes = BASE64
        .decode(blob)
        .map_err(|err| Error::Codec(format!("invalid avro base64 payload: {err}")))?;

    if bytes.is_empty() {
        return Err(Error::Codec("avro payload is empty".to_string()));
    }

    match bytes[0] {
        0x00 => {}
        0x01 => {
            return Err(Error::Codec(
                "typed avro payloads require a schema context; v1 supports the generic wrapper"
                    .to_string(),
            ));
        }
        other => {
            return Err(Error::Codec(format!(
                "unknown avro payload prefix 0x{other:02x}"
            )));
        }
    }

    let mut cursor = 1;
    let len = read_avro_long(&bytes, &mut cursor)?;
    if len < 0 {
        return Err(Error::Codec("avro string length was negative".to_string()));
    }

    let len = len as usize;
    let end = cursor
        .checked_add(len)
        .ok_or_else(|| Error::Codec("avro string length overflowed".to_string()))?;
    if end > bytes.len() {
        return Err(Error::Codec(
            "avro string extends beyond payload".to_string(),
        ));
    }

    let json = std::str::from_utf8(&bytes[cursor..end])
        .map_err(|err| Error::Codec(format!("avro json field is not utf-8: {err}")))?;
    cursor = end;

    let version = read_avro_int(&bytes, &mut cursor)?;
    if version != 1 {
        return Err(Error::Codec(format!(
            "unsupported avro generic wrapper version {version}"
        )));
    }

    Ok(serde_json::from_str(json)?)
}

fn write_avro_int(value: i32, bytes: &mut Vec<u8>) {
    write_unsigned_varint(((value << 1) ^ (value >> 31)) as u64, bytes);
}

fn write_avro_long(value: i64, bytes: &mut Vec<u8>) {
    write_unsigned_varint(((value << 1) ^ (value >> 63)) as u64, bytes);
}

fn write_unsigned_varint(mut value: u64, bytes: &mut Vec<u8>) {
    while (value & !0x7f) != 0 {
        bytes.push(((value & 0x7f) as u8) | 0x80);
        value >>= 7;
    }
    bytes.push(value as u8);
}

fn read_avro_int(bytes: &[u8], cursor: &mut usize) -> Result<i32> {
    let value = read_unsigned_varint(bytes, cursor)?;
    Ok(((value >> 1) as i32) ^ (-((value & 1) as i32)))
}

fn read_avro_long(bytes: &[u8], cursor: &mut usize) -> Result<i64> {
    let value = read_unsigned_varint(bytes, cursor)?;
    Ok(((value >> 1) as i64) ^ (-((value & 1) as i64)))
}

fn read_unsigned_varint(bytes: &[u8], cursor: &mut usize) -> Result<u64> {
    let mut value = 0u64;
    let mut shift = 0u32;

    loop {
        if *cursor >= bytes.len() {
            return Err(Error::Codec("truncated avro varint".to_string()));
        }

        let byte = bytes[*cursor];
        *cursor += 1;
        value |= ((byte & 0x7f) as u64) << shift;

        if (byte & 0x80) == 0 {
            return Ok(value);
        }

        shift += 7;
        if shift >= 64 {
            return Err(Error::Codec("avro varint is too large".to_string()));
        }
    }
}

#[derive(Clone, Debug)]
pub struct Client {
    http: reqwest::Client,
    base_url: String,
    token: Option<String>,
    control_token: Option<String>,
    worker_token: Option<String>,
    namespace: String,
}

impl Client {
    pub fn new(base_url: impl Into<String>) -> Result<Self> {
        Self::builder(base_url).build()
    }

    pub fn builder(base_url: impl Into<String>) -> ClientBuilder {
        ClientBuilder {
            base_url: base_url.into(),
            token: None,
            control_token: None,
            worker_token: None,
            namespace: "default".to_string(),
            timeout: Duration::from_secs(60),
        }
    }

    pub async fn health(&self) -> Result<Value> {
        self.request_json(
            reqwest::Method::GET,
            "/health",
            false,
            Option::<&Value>::None,
        )
        .await
    }

    pub async fn cluster_info(&self) -> Result<Value> {
        self.request_json(
            reqwest::Method::GET,
            "/cluster/info",
            false,
            Option::<&Value>::None,
        )
        .await
    }

    pub async fn start_workflow<T: Serialize>(
        &self,
        workflow_type: &str,
        task_queue: &str,
        workflow_id: &str,
        input: T,
    ) -> Result<WorkflowHandle> {
        let input = serde_json::to_value(input)?;
        let input_envelope = encode_value_envelope(&normalize_arguments(input), DEFAULT_CODEC)?;
        let body = json!({
            "workflow_id": workflow_id,
            "workflow_type": workflow_type,
            "task_queue": task_queue,
            "input": input_envelope,
            "execution_timeout_seconds": 3600,
            "run_timeout_seconds": 600
        });

        let data: Value = self
            .request_json(reqwest::Method::POST, "/workflows", false, Some(&body))
            .await?;

        Ok(WorkflowHandle {
            client: self.clone(),
            workflow_id: data
                .get("workflow_id")
                .and_then(Value::as_str)
                .unwrap_or(workflow_id)
                .to_string(),
            run_id: data
                .get("run_id")
                .and_then(Value::as_str)
                .map(str::to_string),
            workflow_type: data
                .get("workflow_type")
                .and_then(Value::as_str)
                .unwrap_or(workflow_type)
                .to_string(),
        })
    }

    pub async fn signal_workflow<T: Serialize>(
        &self,
        workflow_id: &str,
        signal_name: &str,
        input: T,
    ) -> Result<Value> {
        let input = serde_json::to_value(input)?;
        let input_envelope = encode_value_envelope(&normalize_arguments(input), DEFAULT_CODEC)?;
        let body = json!({
            "input": input_envelope
        });
        let path = format!("/workflows/{workflow_id}/signal/{signal_name}");
        self.request_json(reqwest::Method::POST, &path, false, Some(&body))
            .await
    }

    pub async fn describe_workflow(&self, workflow_id: &str) -> Result<WorkflowDescription> {
        let path = format!("/workflows/{workflow_id}");
        let mut data: WorkflowDescription = self
            .request_json(reqwest::Method::GET, &path, false, Option::<&Value>::None)
            .await?;
        data.decode_payloads()?;
        Ok(data)
    }

    pub async fn register_worker(
        &self,
        worker_id: &str,
        task_queue: &str,
        supported_workflow_types: Vec<String>,
        supported_activity_types: Vec<String>,
        max_concurrent_workflow_tasks: usize,
        max_concurrent_activity_tasks: usize,
    ) -> Result<RegisterWorkerResponse> {
        let body = json!({
            "worker_id": worker_id,
            "task_queue": task_queue,
            "runtime": "rust",
            "sdk_version": SDK_VERSION,
            "supported_workflow_types": supported_workflow_types,
            "supported_activity_types": supported_activity_types,
            "max_concurrent_workflow_tasks": max_concurrent_workflow_tasks,
            "max_concurrent_activity_tasks": max_concurrent_activity_tasks
        });

        self.request_json(reqwest::Method::POST, "/worker/register", true, Some(&body))
            .await
    }

    pub async fn heartbeat_worker(
        &self,
        worker_id: &str,
        workflow_available: usize,
        activity_available: usize,
    ) -> Result<Value> {
        let body = json!({
            "worker_id": worker_id,
            "task_slots": {
                "workflow_available": workflow_available,
                "activity_available": activity_available
            },
            "process_metrics": {
                "process_id": std::process::id(),
                "process_uptime_seconds": 0
            }
        });

        self.request_json(
            reqwest::Method::POST,
            "/worker/heartbeat",
            true,
            Some(&body),
        )
        .await
    }

    pub async fn poll_workflow_task(
        &self,
        worker_id: &str,
        task_queue: &str,
        timeout: Duration,
    ) -> Result<Option<WorkflowTask>> {
        Ok(self
            .poll_workflow_task_response(worker_id, task_queue, timeout)
            .await?
            .task)
    }

    pub async fn poll_workflow_task_response(
        &self,
        worker_id: &str,
        task_queue: &str,
        timeout: Duration,
    ) -> Result<PollWorkflowTaskResponse> {
        let body = json!({
            "worker_id": worker_id,
            "task_queue": task_queue,
        });
        let mut data: PollWorkflowTaskResponse = self
            .request_json_with_timeout(
                reqwest::Method::POST,
                "/worker/workflow-tasks/poll",
                true,
                Some(&body),
                timeout + Duration::from_secs(5),
            )
            .await?;

        if let Some(task) = data.task.as_mut() {
            self.fetch_remaining_workflow_history(worker_id, task)
                .await?;
        }

        Ok(data)
    }

    async fn fetch_remaining_workflow_history(
        &self,
        worker_id: &str,
        task: &mut WorkflowTask,
    ) -> Result<()> {
        let mut next_token = task.next_history_page_token.clone();

        while let Some(token) = next_token.take().filter(|token| !token.is_empty()) {
            let lease_owner = task
                .lease_owner
                .clone()
                .unwrap_or_else(|| worker_id.to_string());
            let page = self
                .workflow_task_history_page(
                    &task.task_id,
                    &lease_owner,
                    task.workflow_task_attempt,
                    &token,
                )
                .await?;

            task.append_history_page(page);

            if task.next_history_page_token.as_deref() == Some(token.as_str()) {
                return Err(Error::Codec(
                    "workflow history pagination returned the same page token".to_string(),
                ));
            }

            next_token = task.next_history_page_token.clone();
        }

        Ok(())
    }

    async fn workflow_task_history_page(
        &self,
        task_id: &str,
        lease_owner: &str,
        workflow_task_attempt: u64,
        next_history_page_token: &str,
    ) -> Result<WorkflowTaskHistoryPage> {
        let body = json!({
            "lease_owner": lease_owner,
            "workflow_task_attempt": workflow_task_attempt,
            "next_history_page_token": next_history_page_token
        });
        let path = format!("/worker/workflow-tasks/{task_id}/history");

        self.request_json(reqwest::Method::POST, &path, true, Some(&body))
            .await
    }

    pub async fn complete_workflow_task(
        &self,
        task_id: &str,
        lease_owner: &str,
        workflow_task_attempt: u64,
        commands: Vec<Value>,
    ) -> Result<Value> {
        let body = json!({
            "lease_owner": lease_owner,
            "workflow_task_attempt": workflow_task_attempt,
            "commands": commands
        });
        let path = format!("/worker/workflow-tasks/{task_id}/complete");
        self.request_json(reqwest::Method::POST, &path, true, Some(&body))
            .await
    }

    pub async fn fail_workflow_task(
        &self,
        task_id: &str,
        lease_owner: &str,
        workflow_task_attempt: u64,
        message: impl Into<String>,
    ) -> Result<Value> {
        let body = json!({
            "lease_owner": lease_owner,
            "workflow_task_attempt": workflow_task_attempt,
            "failure": {
                "message": message.into(),
                "type": "RustWorkflowTaskFailure"
            }
        });
        let path = format!("/worker/workflow-tasks/{task_id}/fail");
        self.request_json(reqwest::Method::POST, &path, true, Some(&body))
            .await
    }

    pub async fn poll_activity_task(
        &self,
        worker_id: &str,
        task_queue: &str,
        timeout: Duration,
    ) -> Result<Option<ActivityTask>> {
        let body = json!({
            "worker_id": worker_id,
            "task_queue": task_queue,
        });
        let data: PollActivityTaskResponse = self
            .request_json_with_timeout(
                reqwest::Method::POST,
                "/worker/activity-tasks/poll",
                true,
                Some(&body),
                timeout + Duration::from_secs(5),
            )
            .await?;
        Ok(data.task)
    }

    pub async fn complete_activity_task(
        &self,
        task_id: &str,
        activity_attempt_id: &str,
        lease_owner: &str,
        result: Value,
        codec: &str,
    ) -> Result<Value> {
        let result = encode_value_envelope(&result, codec)?;
        let body = json!({
            "activity_attempt_id": activity_attempt_id,
            "lease_owner": lease_owner,
            "result": result
        });
        let path = format!("/worker/activity-tasks/{task_id}/complete");
        self.request_json(reqwest::Method::POST, &path, true, Some(&body))
            .await
    }

    pub async fn fail_activity_task(
        &self,
        task_id: &str,
        activity_attempt_id: &str,
        lease_owner: &str,
        message: impl Into<String>,
        non_retryable: bool,
    ) -> Result<Value> {
        let body = json!({
            "activity_attempt_id": activity_attempt_id,
            "lease_owner": lease_owner,
            "failure": {
                "message": message.into(),
                "type": "RustActivityFailure",
                "non_retryable": non_retryable
            }
        });
        let path = format!("/worker/activity-tasks/{task_id}/fail");
        self.request_json(reqwest::Method::POST, &path, true, Some(&body))
            .await
    }

    pub async fn heartbeat_activity_task(
        &self,
        task_id: &str,
        activity_attempt_id: &str,
        lease_owner: &str,
        details: Value,
    ) -> Result<ActivityHeartbeatResponse> {
        let body = json!({
            "activity_attempt_id": activity_attempt_id,
            "lease_owner": lease_owner,
            "details": details
        });
        let path = format!("/worker/activity-tasks/{task_id}/heartbeat");
        self.request_json(reqwest::Method::POST, &path, true, Some(&body))
            .await
    }

    async fn request_json<T: DeserializeOwned, B: Serialize + ?Sized>(
        &self,
        method: reqwest::Method,
        path: &str,
        worker: bool,
        body: Option<&B>,
    ) -> Result<T> {
        self.request_json_with_timeout(method, path, worker, body, Duration::from_secs(60))
            .await
    }

    async fn request_json_with_timeout<T: DeserializeOwned, B: Serialize + ?Sized>(
        &self,
        method: reqwest::Method,
        path: &str,
        worker: bool,
        body: Option<&B>,
        timeout: Duration,
    ) -> Result<T> {
        let mut request = self
            .http
            .request(method, format!("{}/api{}", self.base_url, path))
            .timeout(timeout)
            .header(reqwest::header::ACCEPT, "application/json")
            .header(reqwest::header::CONTENT_TYPE, "application/json")
            .header("X-Namespace", &self.namespace);

        if worker {
            request = request.header(
                "X-Durable-Workflow-Protocol-Version",
                WORKER_PROTOCOL_VERSION,
            );
        } else {
            request = request.header(
                "X-Durable-Workflow-Control-Plane-Version",
                CONTROL_PLANE_VERSION,
            );
        }

        if let Some(token) = self.auth_token(worker) {
            request = request.bearer_auth(token);
        }

        if let Some(body) = body {
            request = request.json(body);
        }

        let response = request.send().await?;
        let status = response.status();
        let bytes = response.bytes().await?;

        if !status.is_success() {
            let body = String::from_utf8_lossy(&bytes).to_string();
            return Err(Error::Http { status, body });
        }

        if bytes.is_empty() {
            return Ok(serde_json::from_value(Value::Null)?);
        }

        Ok(serde_json::from_slice(&bytes)?)
    }

    fn auth_token(&self, worker: bool) -> Option<&str> {
        if worker {
            self.worker_token
                .as_deref()
                .or(self.token.as_deref())
                .or(self.control_token.as_deref())
        } else {
            self.control_token
                .as_deref()
                .or(self.token.as_deref())
                .or(self.worker_token.as_deref())
        }
    }
}

#[derive(Debug)]
pub struct ClientBuilder {
    base_url: String,
    token: Option<String>,
    control_token: Option<String>,
    worker_token: Option<String>,
    namespace: String,
    timeout: Duration,
}

impl ClientBuilder {
    pub fn token(mut self, token: Option<String>) -> Self {
        self.token = token;
        self
    }

    pub fn control_token(mut self, token: Option<String>) -> Self {
        self.control_token = token;
        self
    }

    pub fn worker_token(mut self, token: Option<String>) -> Self {
        self.worker_token = token;
        self
    }

    pub fn namespace(mut self, namespace: impl Into<String>) -> Self {
        self.namespace = namespace.into();
        self
    }

    pub fn timeout(mut self, timeout: Duration) -> Self {
        self.timeout = timeout;
        self
    }

    pub fn build(self) -> Result<Client> {
        Ok(Client {
            http: reqwest::Client::builder().timeout(self.timeout).build()?,
            base_url: self.base_url.trim_end_matches('/').to_string(),
            token: self.token,
            control_token: self.control_token,
            worker_token: self.worker_token,
            namespace: self.namespace,
        })
    }
}

#[derive(Clone, Debug)]
pub struct WorkflowHandle {
    client: Client,
    pub workflow_id: String,
    pub run_id: Option<String>,
    pub workflow_type: String,
}

impl WorkflowHandle {
    pub async fn describe(&self) -> Result<WorkflowDescription> {
        self.client.describe_workflow(&self.workflow_id).await
    }

    pub async fn signal<T: Serialize>(&self, signal_name: &str, input: T) -> Result<Value> {
        self.client
            .signal_workflow(&self.workflow_id, signal_name, input)
            .await
    }

    pub async fn result(&self, options: WorkflowResultOptions) -> Result<Value> {
        let started = Instant::now();

        loop {
            let description = self.describe().await?;
            if description.is_completed() {
                return Ok(description.output.unwrap_or(Value::Null));
            }

            if description.is_terminal() {
                return Err(Error::Codec(format!(
                    "workflow {} closed with status {:?}",
                    self.workflow_id, description.status
                )));
            }

            if started.elapsed() >= options.timeout {
                return Err(Error::Timeout);
            }

            tokio::time::sleep(options.poll_interval).await;
        }
    }
}

#[derive(Clone, Copy, Debug)]
pub struct WorkflowResultOptions {
    pub poll_interval: Duration,
    pub timeout: Duration,
}

impl Default for WorkflowResultOptions {
    fn default() -> Self {
        Self {
            poll_interval: Duration::from_millis(500),
            timeout: Duration::from_secs(30),
        }
    }
}

#[derive(Clone, Debug, Deserialize)]
pub struct WorkflowDescription {
    pub workflow_id: Option<String>,
    pub run_id: Option<String>,
    pub workflow_type: Option<String>,
    pub status: Option<String>,
    #[serde(default)]
    pub output: Option<Value>,
    #[serde(default)]
    pub output_envelope: Option<Value>,
    #[serde(flatten)]
    pub raw: HashMap<String, Value>,
}

impl WorkflowDescription {
    pub fn is_completed(&self) -> bool {
        matches!(self.status.as_deref(), Some("completed" | "Completed"))
    }

    pub fn is_terminal(&self) -> bool {
        matches!(
            self.status.as_deref(),
            Some(
                "completed"
                    | "Completed"
                    | "failed"
                    | "Failed"
                    | "cancelled"
                    | "Cancelled"
                    | "terminated"
                    | "Terminated"
                    | "timed_out"
                    | "TimedOut",
            )
        )
    }

    fn decode_payloads(&mut self) -> Result<()> {
        if let Some(envelope) = &self.output_envelope {
            self.output = Some(decode_wire_value(envelope, DEFAULT_CODEC)?);
        }

        Ok(())
    }
}

#[derive(Clone, Debug, Deserialize)]
pub struct RegisterWorkerResponse {
    pub worker_id: String,
    pub registered: bool,
    #[serde(default)]
    pub heartbeat_interval_seconds: Option<u64>,
    #[serde(default)]
    pub protocol_version: Option<String>,
    #[serde(default)]
    pub server_capabilities: Option<Value>,
}

#[derive(Clone, Debug, Deserialize)]
pub struct PollWorkflowTaskResponse {
    #[serde(default)]
    pub task: Option<WorkflowTask>,
    #[serde(default)]
    pub poll_status: Option<String>,
    #[serde(default)]
    pub reason: Option<String>,
    #[serde(default)]
    pub protocol_version: Option<String>,
    #[serde(default)]
    pub server_capabilities: Option<Value>,
}

#[derive(Clone, Debug, Deserialize)]
struct PollActivityTaskResponse {
    #[serde(default)]
    task: Option<ActivityTask>,
}

#[derive(Clone, Debug, Deserialize)]
pub struct WorkflowTask {
    pub task_id: String,
    #[serde(default)]
    pub workflow_id: Option<String>,
    #[serde(default)]
    pub run_id: Option<String>,
    pub workflow_type: String,
    #[serde(default = "default_payload_codec")]
    pub payload_codec: String,
    #[serde(default)]
    pub arguments: Option<Value>,
    #[serde(default)]
    pub history_events: Vec<HistoryEvent>,
    #[serde(default)]
    pub total_history_events: Option<u64>,
    #[serde(default)]
    pub next_history_page_token: Option<String>,
    #[serde(default = "default_workflow_task_attempt")]
    pub workflow_task_attempt: u64,
    #[serde(default)]
    pub workflow_signal_id: Option<String>,
    #[serde(default)]
    pub signal_name: Option<String>,
    #[serde(default)]
    pub signal_arguments: Option<Value>,
    #[serde(default)]
    pub lease_owner: Option<String>,
}

impl WorkflowTask {
    fn append_history_page(&mut self, page: WorkflowTaskHistoryPage) {
        self.history_events.extend(page.history_events);

        if page.total_history_events.is_some() {
            self.total_history_events = page.total_history_events;
        }

        self.next_history_page_token = page
            .next_history_page_token
            .filter(|token| !token.is_empty());
    }
}

#[derive(Clone, Debug, Deserialize)]
struct WorkflowTaskHistoryPage {
    #[serde(default)]
    history_events: Vec<HistoryEvent>,
    #[serde(default)]
    total_history_events: Option<u64>,
    #[serde(default)]
    next_history_page_token: Option<String>,
}

#[derive(Clone, Debug, Deserialize)]
pub struct ActivityTask {
    pub task_id: String,
    #[serde(default)]
    pub activity_attempt_id: Option<String>,
    #[serde(default)]
    pub attempt_id: Option<String>,
    pub activity_type: String,
    #[serde(default = "default_payload_codec")]
    pub payload_codec: String,
    #[serde(default)]
    pub arguments: Option<Value>,
    #[serde(default = "default_attempt_number")]
    pub attempt_number: u64,
    #[serde(default)]
    pub lease_owner: Option<String>,
}

#[derive(Clone, Debug, Deserialize)]
pub struct HistoryEvent {
    pub event_type: String,
    #[serde(default)]
    pub payload: Value,
    #[serde(flatten)]
    pub raw: HashMap<String, Value>,
}

#[derive(Clone, Debug, Deserialize)]
pub struct ActivityHeartbeatResponse {
    #[serde(default)]
    pub cancel_requested: bool,
    #[serde(default)]
    pub heartbeat_recorded: bool,
}

fn default_payload_codec() -> String {
    DEFAULT_CODEC.to_string()
}

fn default_workflow_task_attempt() -> u64 {
    1
}

fn default_attempt_number() -> u64 {
    1
}

type WorkflowFuture = Pin<Box<dyn Future<Output = Result<Value>> + Send + 'static>>;
type WorkflowHandler = Arc<dyn Fn(WorkflowContext, Value) -> WorkflowFuture + Send + Sync>;
type ActivityFuture = Pin<Box<dyn Future<Output = Result<Value>> + Send + 'static>>;
type ActivityHandler = Arc<dyn Fn(ActivityContext, Value) -> ActivityFuture + Send + Sync>;
type WorkerHeartbeatObserver = Arc<dyn Fn(&WorkerHeartbeatObservation) + Send + Sync>;

#[derive(Clone, Debug)]
pub struct WorkerHeartbeatObservation {
    pub worker_id: String,
    pub task_queue: String,
    pub acknowledged_at_unix_millis: u64,
    pub acknowledgement: Value,
}

#[derive(Clone)]
pub struct Worker {
    client: Client,
    worker_id: String,
    task_queue: String,
    workflows: HashMap<String, WorkflowHandler>,
    activities: HashMap<String, ActivityHandler>,
    max_concurrent_workflow_tasks: usize,
    max_concurrent_activity_tasks: usize,
    poll_timeout: Duration,
    heartbeat_interval: Duration,
    heartbeat_observer: Option<WorkerHeartbeatObserver>,
}

impl Worker {
    pub fn new(client: Client, task_queue: impl Into<String>) -> Self {
        Self {
            client,
            worker_id: default_worker_id(),
            task_queue: task_queue.into(),
            workflows: HashMap::new(),
            activities: HashMap::new(),
            max_concurrent_workflow_tasks: 10,
            max_concurrent_activity_tasks: 10,
            poll_timeout: Duration::from_secs(30),
            heartbeat_interval: Duration::from_secs(60),
            heartbeat_observer: None,
        }
    }

    pub fn worker_id(mut self, worker_id: impl Into<String>) -> Self {
        self.worker_id = worker_id.into();
        self
    }

    pub fn poll_timeout(mut self, timeout: Duration) -> Self {
        self.poll_timeout = timeout;
        self
    }

    pub fn heartbeat_interval(mut self, interval: Duration) -> Self {
        self.heartbeat_interval = interval;
        self
    }

    pub fn on_worker_heartbeat<F>(mut self, observer: F) -> Self
    where
        F: Fn(&WorkerHeartbeatObservation) + Send + Sync + 'static,
    {
        self.heartbeat_observer = Some(Arc::new(observer));
        self
    }

    pub fn max_concurrent_workflow_tasks(mut self, count: usize) -> Self {
        self.max_concurrent_workflow_tasks = count.max(1);
        self
    }

    pub fn max_concurrent_activity_tasks(mut self, count: usize) -> Self {
        self.max_concurrent_activity_tasks = count.max(1);
        self
    }

    pub fn register_workflow<F, Fut>(&mut self, workflow_type: impl Into<String>, handler: F)
    where
        F: Fn(WorkflowContext, Value) -> Fut + Send + Sync + 'static,
        Fut: Future<Output = Result<Value>> + Send + 'static,
    {
        self.workflows.insert(
            workflow_type.into(),
            Arc::new(move |ctx, input| Box::pin(handler(ctx, input))),
        );
    }

    pub fn register_activity<F, Fut>(&mut self, activity_type: impl Into<String>, handler: F)
    where
        F: Fn(ActivityContext, Value) -> Fut + Send + Sync + 'static,
        Fut: Future<Output = Result<Value>> + Send + 'static,
    {
        self.activities.insert(
            activity_type.into(),
            Arc::new(move |ctx, args| Box::pin(handler(ctx, args))),
        );
    }

    pub async fn register(&self) -> Result<RegisterWorkerResponse> {
        self.client
            .register_worker(
                &self.worker_id,
                &self.task_queue,
                self.workflows.keys().cloned().collect(),
                self.activities.keys().cloned().collect(),
                self.max_concurrent_workflow_tasks,
                self.max_concurrent_activity_tasks,
            )
            .await
    }

    pub async fn run(&self) -> Result<()> {
        self.run_until(std::future::pending::<()>()).await
    }

    pub async fn run_until<F>(&self, shutdown: F) -> Result<()>
    where
        F: Future<Output = ()>,
    {
        let registration = self.register().await?;
        let mut heartbeat = tokio::time::interval(Duration::from_secs(
            registration
                .heartbeat_interval_seconds
                .unwrap_or(self.heartbeat_interval.as_secs().max(1)),
        ));
        tokio::pin!(shutdown);
        let stop = Arc::new(AtomicBool::new(false));
        // Poll responses may already have leased server-side work by the time
        // they become ready, so each poller owns its responses through
        // completion or failure instead of racing raw polls in this select.
        let mut workflow_poller = (!self.workflows.is_empty()).then(|| {
            let worker = self.clone();
            let stop = Arc::clone(&stop);
            tokio::spawn(async move { worker.poll_workflows_until_stopped(stop).await })
        });
        let mut activity_poller = (!self.activities.is_empty()).then(|| {
            let worker = self.clone();
            let stop = Arc::clone(&stop);
            tokio::spawn(async move { worker.poll_activities_until_stopped(stop).await })
        });

        loop {
            tokio::select! {
                _ = &mut shutdown => {
                    stop.store(true, Ordering::SeqCst);
                    break;
                }
                _ = heartbeat.tick() => {
                    match self.client
                        .heartbeat_worker(
                            &self.worker_id,
                            self.max_concurrent_workflow_tasks,
                            self.max_concurrent_activity_tasks,
                        )
                        .await
                    {
                        Ok(acknowledgement) => {
                            if let Some(observer) = &self.heartbeat_observer {
                                observer(&WorkerHeartbeatObservation {
                                    worker_id: self.worker_id.clone(),
                                    task_queue: self.task_queue.clone(),
                                    acknowledged_at_unix_millis: SystemTime::now()
                                        .duration_since(UNIX_EPOCH)
                                        .unwrap_or_default()
                                        .as_millis()
                                        .min(u64::MAX as u128)
                                        as u64,
                                    acknowledgement,
                                });
                            }
                        }
                        Err(error) => {
                            stop.store(true, Ordering::SeqCst);
                            join_pollers(workflow_poller.take(), activity_poller.take()).await?;
                            return Err(error);
                        }
                    }
                }
                result = OptionFuture::from(workflow_poller.as_mut()), if workflow_poller.is_some() => {
                    workflow_poller = None;
                    stop.store(true, Ordering::SeqCst);
                    let poller_result = optional_poller_result("workflow", result);
                    let join_result =
                        join_pollers(workflow_poller.take(), activity_poller.take()).await;
                    poller_result?;
                    join_result?;
                    return Err(Error::WorkerLoop(
                        "workflow poller stopped unexpectedly".to_string(),
                    ));
                }
                result = OptionFuture::from(activity_poller.as_mut()), if activity_poller.is_some() => {
                    activity_poller = None;
                    stop.store(true, Ordering::SeqCst);
                    let poller_result = optional_poller_result("activity", result);
                    let join_result =
                        join_pollers(workflow_poller.take(), activity_poller.take()).await;
                    poller_result?;
                    join_result?;
                    return Err(Error::WorkerLoop(
                        "activity poller stopped unexpectedly".to_string(),
                    ));
                }
            }
        }

        join_pollers(workflow_poller.take(), activity_poller.take()).await
    }

    pub async fn run_once(&self) -> Result<usize> {
        let mut handled = 0;
        if self.poll_workflow_once().await? {
            handled += 1;
        }
        if self.poll_activity_once().await? {
            handled += 1;
        }
        Ok(handled)
    }

    async fn poll_workflow_once(&self) -> Result<bool> {
        let Some(task) = self
            .client
            .poll_workflow_task(&self.worker_id, &self.task_queue, self.poll_timeout)
            .await?
        else {
            return Ok(false);
        };

        let task_id = task.task_id.clone();
        let attempt = task.workflow_task_attempt;
        let lease_owner = task
            .lease_owner
            .clone()
            .unwrap_or_else(|| self.worker_id.clone());

        match self.execute_workflow_task(task) {
            Ok(commands) => {
                self.client
                    .complete_workflow_task(&task_id, &lease_owner, attempt, commands)
                    .await?;
            }
            Err(error) => {
                self.client
                    .fail_workflow_task(&task_id, &lease_owner, attempt, error.to_string())
                    .await?;
            }
        }

        Ok(true)
    }

    async fn poll_workflows_until_stopped(self, stop: Arc<AtomicBool>) -> Result<()> {
        while !stop.load(Ordering::SeqCst) {
            self.poll_workflow_once().await?;
        }

        Ok(())
    }

    async fn poll_activity_once(&self) -> Result<bool> {
        let Some(task) = self
            .client
            .poll_activity_task(&self.worker_id, &self.task_queue, self.poll_timeout)
            .await?
        else {
            return Ok(false);
        };

        let task_id = task.task_id.clone();
        let attempt_id = task
            .activity_attempt_id
            .clone()
            .or(task.attempt_id.clone())
            .unwrap_or_default();
        let lease_owner = task
            .lease_owner
            .clone()
            .unwrap_or_else(|| self.worker_id.clone());
        let codec = task.payload_codec.clone();
        let result = self.execute_activity_task(task).await;
        match result {
            Ok(value) => {
                self.client
                    .complete_activity_task(&task_id, &attempt_id, &lease_owner, value, &codec)
                    .await?;
            }
            Err(error) => {
                self.client
                    .fail_activity_task(
                        &task_id,
                        &attempt_id,
                        &lease_owner,
                        error.to_string(),
                        false,
                    )
                    .await?;
            }
        }

        Ok(true)
    }

    async fn poll_activities_until_stopped(self, stop: Arc<AtomicBool>) -> Result<()> {
        while !stop.load(Ordering::SeqCst) {
            self.poll_activity_once().await?;
        }

        Ok(())
    }

    fn execute_workflow_task(&self, task: WorkflowTask) -> Result<Vec<Value>> {
        let handler = self
            .workflows
            .get(&task.workflow_type)
            .ok_or_else(|| Error::WorkflowNotRegistered(task.workflow_type.clone()))?;
        let input = decode_task_arguments(task.arguments.as_ref(), &task.payload_codec)?;
        let resume_signal = decode_resume_signal(&task)?;
        let state = Arc::new(Mutex::new(WorkflowState {
            history: task.history_events,
            task_queue: self.task_queue.clone(),
            payload_codec: task.payload_codec.clone(),
            resume_signal,
            activity_cursor: 0,
            signal_cursors: HashMap::new(),
            commands: Vec::new(),
        }));
        let ctx = WorkflowContext { state };
        let mut future = handler(ctx.clone(), input);
        let mut cx = TaskContext::from_waker(noop_waker_ref());

        match future.as_mut().poll(&mut cx) {
            Poll::Ready(Ok(result)) => {
                let result = encode_value_envelope(&result, &task.payload_codec)?;
                Ok(vec![json!({
                    "type": "complete_workflow",
                    "result": result
                })])
            }
            Poll::Ready(Err(error)) => Err(error),
            Poll::Pending => {
                let commands = ctx.take_commands()?;
                if commands.is_empty() {
                    Err(Error::WorkflowYieldedWithoutCommand)
                } else {
                    Ok(commands)
                }
            }
        }
    }

    async fn execute_activity_task(&self, task: ActivityTask) -> Result<Value> {
        let handler = self
            .activities
            .get(&task.activity_type)
            .ok_or_else(|| Error::ActivityNotRegistered(task.activity_type.clone()))?;
        let args = decode_task_arguments(task.arguments.as_ref(), &task.payload_codec)?;
        let attempt_id = task
            .activity_attempt_id
            .clone()
            .or(task.attempt_id.clone())
            .unwrap_or_default();
        let lease_owner = task
            .lease_owner
            .clone()
            .unwrap_or_else(|| self.worker_id.clone());
        let ctx = ActivityContext {
            client: self.client.clone(),
            task_id: task.task_id,
            activity_attempt_id: attempt_id,
            lease_owner,
            activity_type: task.activity_type,
            attempt_number: task.attempt_number,
            task_queue: self.task_queue.clone(),
            worker_id: self.worker_id.clone(),
        };

        handler(ctx, args).await
    }
}

fn poller_result(
    kind: &str,
    result: std::result::Result<Result<()>, tokio::task::JoinError>,
) -> Result<()> {
    match result {
        Ok(result) => result,
        Err(error) => Err(Error::WorkerLoop(format!(
            "{kind} poller join error: {error}"
        ))),
    }
}

fn optional_poller_result(
    kind: &str,
    result: Option<std::result::Result<Result<()>, tokio::task::JoinError>>,
) -> Result<()> {
    match result {
        Some(result) => poller_result(kind, result),
        None => Ok(()),
    }
}

async fn join_pollers(
    workflow_poller: Option<tokio::task::JoinHandle<Result<()>>>,
    activity_poller: Option<tokio::task::JoinHandle<Result<()>>>,
) -> Result<()> {
    let mut first_error = None;

    if let Some(handle) = workflow_poller {
        if let Err(error) = poller_result("workflow", handle.await) {
            first_error.get_or_insert(error);
        }
    }

    if let Some(handle) = activity_poller {
        if let Err(error) = poller_result("activity", handle.await) {
            first_error.get_or_insert(error);
        }
    }

    if let Some(error) = first_error {
        Err(error)
    } else {
        Ok(())
    }
}

fn default_worker_id() -> String {
    let millis = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap_or_default()
        .as_millis();
    format!("rust-worker-{}-{millis}", std::process::id())
}

#[derive(Clone, Debug)]
pub struct WorkflowContext {
    state: Arc<Mutex<WorkflowState>>,
}

impl WorkflowContext {
    pub fn activity<T: Serialize>(
        &self,
        activity_type: impl Into<String>,
        args: T,
    ) -> ActivityCall {
        self.activity_on_queue(activity_type, None::<String>, args)
    }

    pub fn activity_on_queue<T, Q>(
        &self,
        activity_type: impl Into<String>,
        task_queue: Option<Q>,
        args: T,
    ) -> ActivityCall
    where
        T: Serialize,
        Q: Into<String>,
    {
        ActivityCall {
            ctx: self.clone(),
            activity_type: activity_type.into(),
            task_queue: task_queue.map(Into::into),
            args: Some(serde_json::to_value(args).map_err(Error::from)),
            scheduled: false,
        }
    }

    pub fn wait_signal(&self, signal_name: impl Into<String>) -> SignalCall {
        SignalCall {
            ctx: self.clone(),
            signal_name: signal_name.into(),
            opened_wait: false,
        }
    }

    fn take_commands(&self) -> Result<Vec<Value>> {
        let mut state = self
            .state
            .lock()
            .map_err(|_| Error::WorkflowStatePoisoned)?;
        Ok(std::mem::take(&mut state.commands))
    }
}

#[derive(Debug)]
struct WorkflowState {
    history: Vec<HistoryEvent>,
    task_queue: String,
    payload_codec: String,
    resume_signal: Option<ResumeSignal>,
    activity_cursor: usize,
    signal_cursors: HashMap<String, usize>,
    commands: Vec<Value>,
}

#[derive(Clone, Debug)]
struct ResumeSignal {
    signal_id: Option<String>,
    signal_name: String,
    arguments: Vec<Value>,
}

pub struct ActivityCall {
    ctx: WorkflowContext,
    activity_type: String,
    task_queue: Option<String>,
    args: Option<Result<Value>>,
    scheduled: bool,
}

impl Future for ActivityCall {
    type Output = Result<Value>;

    fn poll(mut self: Pin<&mut Self>, _cx: &mut TaskContext<'_>) -> Poll<Self::Output> {
        let ctx = self.ctx.clone();
        let mut state = match ctx.state.lock() {
            Ok(state) => state,
            Err(_) => return Poll::Ready(Err(Error::WorkflowStatePoisoned)),
        };

        let completed = match completed_activity_results(&state.history, &state.payload_codec) {
            Ok(completed) => completed,
            Err(error) => return Poll::Ready(Err(error)),
        };

        if state.activity_cursor < completed.len() {
            let value = completed[state.activity_cursor].clone();
            state.activity_cursor += 1;
            return Poll::Ready(Ok(value));
        }

        if !self.scheduled {
            let task_queue = self
                .task_queue
                .clone()
                .unwrap_or_else(|| state.task_queue.clone());
            let args = match self.args.take().unwrap_or(Ok(Value::Null)) {
                Ok(args) => args,
                Err(error) => return Poll::Ready(Err(error)),
            };
            let arguments = normalize_arguments(args);
            let envelope = match encode_value_envelope(&arguments, &state.payload_codec) {
                Ok(envelope) => envelope,
                Err(error) => return Poll::Ready(Err(error)),
            };

            state.commands.push(json!({
                "type": "schedule_activity",
                "activity_type": self.activity_type.clone(),
                "queue": task_queue,
                "arguments": envelope
            }));
            self.scheduled = true;
        }

        Poll::Pending
    }
}

pub struct SignalCall {
    ctx: WorkflowContext,
    signal_name: String,
    opened_wait: bool,
}

impl Future for SignalCall {
    type Output = Result<Vec<Value>>;

    fn poll(mut self: Pin<&mut Self>, _cx: &mut TaskContext<'_>) -> Poll<Self::Output> {
        let ctx = self.ctx.clone();
        let mut state = match ctx.state.lock() {
            Ok(state) => state,
            Err(_) => return Poll::Ready(Err(Error::WorkflowStatePoisoned)),
        };

        let signals = match signal_values(
            &state.history,
            &self.signal_name,
            &state.payload_codec,
            state.resume_signal.as_ref(),
        ) {
            Ok(signals) => signals,
            Err(error) => return Poll::Ready(Err(error)),
        };
        let cursor = *state.signal_cursors.get(&self.signal_name).unwrap_or(&0);

        if cursor < signals.len() {
            state
                .signal_cursors
                .insert(self.signal_name.clone(), cursor + 1);
            return Poll::Ready(Ok(signals[cursor].clone()));
        }

        if !self.opened_wait {
            state.commands.push(json!({
                "type": "open_condition_wait",
                "condition_key": format!("signal:{}", self.signal_name)
            }));
            self.opened_wait = true;
        }

        Poll::Pending
    }
}

#[derive(Clone, Debug)]
pub struct ActivityContext {
    client: Client,
    pub task_id: String,
    pub activity_attempt_id: String,
    pub lease_owner: String,
    pub activity_type: String,
    pub attempt_number: u64,
    pub task_queue: String,
    pub worker_id: String,
}

impl ActivityContext {
    pub async fn heartbeat<T: Serialize>(&self, details: T) -> Result<ActivityHeartbeatResponse> {
        self.client
            .heartbeat_activity_task(
                &self.task_id,
                &self.activity_attempt_id,
                &self.lease_owner,
                serde_json::to_value(details)?,
            )
            .await
    }
}

fn decode_task_arguments(value: Option<&Value>, codec: &str) -> Result<Value> {
    match value {
        Some(value) => Ok(normalize_arguments(decode_wire_value(value, codec)?)),
        None => Ok(Value::Array(Vec::new())),
    }
}

fn decode_resume_signal(task: &WorkflowTask) -> Result<Option<ResumeSignal>> {
    let Some(signal_name) = task
        .signal_name
        .as_deref()
        .filter(|value| !value.is_empty())
    else {
        return Ok(None);
    };
    let Some(arguments) = task.signal_arguments.as_ref() else {
        return Ok(None);
    };

    let decoded = normalize_arguments(decode_wire_value(arguments, &task.payload_codec)?);
    let Value::Array(arguments) = decoded else {
        unreachable!("normalize_arguments always returns an array");
    };

    Ok(Some(ResumeSignal {
        signal_id: task.workflow_signal_id.clone(),
        signal_name: signal_name.to_string(),
        arguments,
    }))
}

fn normalize_arguments(value: Value) -> Value {
    match value {
        Value::Null => Value::Array(Vec::new()),
        Value::Array(_) => value,
        other => Value::Array(vec![other]),
    }
}

fn completed_activity_results(events: &[HistoryEvent], fallback_codec: &str) -> Result<Vec<Value>> {
    let mut results = Vec::new();

    for event in events {
        if event.event_type != "ActivityCompleted" {
            continue;
        }

        let codec = event
            .payload
            .get("payload_codec")
            .and_then(Value::as_str)
            .unwrap_or(fallback_codec);
        let result = event.payload.get("result").unwrap_or(&Value::Null);
        results.push(decode_wire_value(result, codec)?);
    }

    Ok(results)
}

fn signal_values(
    events: &[HistoryEvent],
    signal_name: &str,
    fallback_codec: &str,
    resume_signal: Option<&ResumeSignal>,
) -> Result<Vec<Vec<Value>>> {
    let mut signals = Vec::new();

    for event in events {
        if event.event_type != "SignalApplied" && event.event_type != "SignalReceived" {
            continue;
        }

        if event.payload.get("signal_name").and_then(Value::as_str) != Some(signal_name) {
            continue;
        }

        let codec = event
            .payload
            .get("payload_codec")
            .and_then(Value::as_str)
            .unwrap_or(fallback_codec);
        let raw = event
            .payload
            .get("value")
            .or_else(|| event.payload.get("input"))
            .or_else(|| event.payload.get("arguments"));
        let decoded = match raw.filter(|value| !value.is_null()) {
            Some(value) => decode_wire_value(value, codec)?,
            None => resume_signal
                .filter(|signal| resume_signal_matches_event(signal, event, signal_name))
                .map(|signal| Value::Array(signal.arguments.clone()))
                .unwrap_or_else(|| Value::Array(Vec::new())),
        };
        let args = match normalize_arguments(decoded) {
            Value::Array(values) => values,
            _ => unreachable!("normalize_arguments always returns an array"),
        };
        signals.push(args);
    }

    Ok(signals)
}

fn resume_signal_matches_event(
    resume_signal: &ResumeSignal,
    event: &HistoryEvent,
    signal_name: &str,
) -> bool {
    if resume_signal.signal_name != signal_name {
        return false;
    }

    match (
        resume_signal.signal_id.as_deref(),
        event.payload.get("signal_id").and_then(Value::as_str),
    ) {
        (Some(resume_id), Some(event_id)) => resume_id == event_id,
        _ => true,
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::{
        io::{Read, Write},
        net::{SocketAddr, TcpListener, TcpStream},
        thread,
    };

    #[test]
    fn avro_generic_wrapper_round_trips_json_values() {
        let value = json!({"greeting": "hello", "count": 3, "ok": true});
        let envelope = PayloadEnvelope::avro(&value).expect("encode");
        assert_eq!(envelope.codec, DEFAULT_CODEC);
        assert_eq!(decode_payload::<Value>(&envelope).expect("decode"), value);
    }

    #[test]
    fn workflow_context_schedules_activity_until_completion_is_in_history() {
        let ctx = WorkflowContext {
            state: Arc::new(Mutex::new(WorkflowState {
                history: Vec::new(),
                task_queue: "rust-workers".to_string(),
                payload_codec: DEFAULT_CODEC.to_string(),
                resume_signal: None,
                activity_cursor: 0,
                signal_cursors: HashMap::new(),
                commands: Vec::new(),
            })),
        };

        let mut call = Box::pin(ctx.activity("hello.activity", json!(["Ada"])));
        let mut task_context = TaskContext::from_waker(noop_waker_ref());
        assert!(matches!(
            call.as_mut().poll(&mut task_context),
            Poll::Pending
        ));

        let commands = ctx.take_commands().expect("commands");
        assert_eq!(commands[0]["type"], "schedule_activity");
        assert_eq!(commands[0]["activity_type"], "hello.activity");
    }

    #[test]
    fn rust_hello_world_uses_signal_arguments_from_resume_payload() {
        let client = Client::new("http://127.0.0.1:8080").expect("client");
        let mut worker = Worker::new(client, "rust-workers");

        worker.register_workflow("rust.hello_workflow", |ctx, _input| async move {
            let signal = ctx.wait_signal("start").await?;
            let name = signal
                .first()
                .and_then(|value| value.as_str())
                .unwrap_or("world");
            let greeting = ctx.activity("rust.hello_activity", json!([name])).await?;
            Ok(json!({
                "greeting": greeting,
                "language": "rust"
            }))
        });

        let signal_arguments =
            encode_value_envelope(&json!(["Rust"]), DEFAULT_CODEC).expect("signal arguments");
        let task = WorkflowTask {
            task_id: "wft-rust-signal-1".to_string(),
            workflow_id: Some("wf-rust-hello".to_string()),
            run_id: Some("run-rust-hello".to_string()),
            workflow_type: "rust.hello_workflow".to_string(),
            payload_codec: DEFAULT_CODEC.to_string(),
            arguments: Some(encode_value_envelope(&json!([]), DEFAULT_CODEC).expect("input")),
            history_events: vec![HistoryEvent {
                event_type: "SignalReceived".to_string(),
                payload: json!({
                    "signal_id": "sig-rust-1",
                    "signal_name": "start"
                }),
                raw: HashMap::new(),
            }],
            total_history_events: Some(1),
            next_history_page_token: None,
            workflow_task_attempt: 1,
            workflow_signal_id: Some("sig-rust-1".to_string()),
            signal_name: Some("start".to_string()),
            signal_arguments: Some(signal_arguments),
            lease_owner: Some("rust-worker".to_string()),
        };

        let commands = worker.execute_workflow_task(task).expect("workflow task");

        assert_eq!(commands.len(), 1);
        assert_eq!(commands[0]["type"], "schedule_activity");
        assert_eq!(commands[0]["activity_type"], "rust.hello_activity");
        assert_eq!(
            decode_wire_value(&commands[0]["arguments"], DEFAULT_CODEC).expect("activity args"),
            json!(["Rust"])
        );
    }

    #[test]
    fn workflow_task_appends_paginated_history_events() {
        let mut task = WorkflowTask {
            task_id: "wft-rust-pages-1".to_string(),
            workflow_id: Some("wf-rust-pages".to_string()),
            run_id: Some("run-rust-pages".to_string()),
            workflow_type: "rust.hello_workflow".to_string(),
            payload_codec: DEFAULT_CODEC.to_string(),
            arguments: Some(encode_value_envelope(&json!([]), DEFAULT_CODEC).expect("input")),
            history_events: vec![HistoryEvent {
                event_type: "WorkflowStarted".to_string(),
                payload: json!({}),
                raw: HashMap::new(),
            }],
            total_history_events: Some(3),
            next_history_page_token: Some("MQ==".to_string()),
            workflow_task_attempt: 1,
            workflow_signal_id: None,
            signal_name: None,
            signal_arguments: None,
            lease_owner: Some("rust-worker".to_string()),
        };

        task.append_history_page(WorkflowTaskHistoryPage {
            history_events: vec![
                HistoryEvent {
                    event_type: "SignalReceived".to_string(),
                    payload: json!({
                        "signal_id": "sig-rust-1",
                        "signal_name": "start",
                        "arguments": encode_value_envelope(&json!(["Rust"]), DEFAULT_CODEC)
                            .expect("signal arguments")
                    }),
                    raw: HashMap::new(),
                },
                HistoryEvent {
                    event_type: "MarkerRecorded".to_string(),
                    payload: json!({"sequence": 3}),
                    raw: HashMap::new(),
                },
            ],
            total_history_events: Some(3),
            next_history_page_token: None,
        });

        assert_eq!(task.history_events.len(), 3);
        assert_eq!(task.total_history_events, Some(3));
        assert_eq!(task.next_history_page_token, None);

        let signals =
            signal_values(&task.history_events, "start", DEFAULT_CODEC, None).expect("signals");
        assert_eq!(signals, vec![vec![json!("Rust")]]);
    }

    #[tokio::test]
    async fn activity_only_worker_can_shutdown_without_workflow_poller() {
        let server = MockWorkerServer::start();
        let client = Client::builder(server.base_url())
            .timeout(Duration::from_secs(2))
            .build()
            .expect("client");
        let mut worker = Worker::new(client, "rust-workers")
            .worker_id("activity-only-worker")
            .poll_timeout(Duration::from_millis(10));

        worker.register_activity(
            "activity.only",
            |_ctx, _args| async move { Ok(Value::Null) },
        );

        worker.run_until(async {}).await.expect("run worker");
    }

    #[tokio::test]
    async fn workflow_only_worker_can_shutdown_without_activity_poller() {
        let server = MockWorkerServer::start();
        let client = Client::builder(server.base_url())
            .timeout(Duration::from_secs(2))
            .build()
            .expect("client");
        let mut worker = Worker::new(client, "rust-workers")
            .worker_id("workflow-only-worker")
            .poll_timeout(Duration::from_millis(10));

        worker.register_workflow(
            "workflow.only",
            |_ctx, _input| async move { Ok(Value::Null) },
        );

        worker.run_until(async {}).await.expect("run worker");
    }

    #[tokio::test]
    async fn worker_heartbeat_observer_receives_server_acknowledgements() {
        let server = MockWorkerServer::start();
        let client = Client::builder(server.base_url())
            .timeout(Duration::from_secs(2))
            .build()
            .expect("client");
        let observations = Arc::new(Mutex::new(Vec::new()));
        let observed = Arc::clone(&observations);
        let mut worker = Worker::new(client, "rust-workers")
            .worker_id("observed-heartbeat-worker")
            .poll_timeout(Duration::from_millis(10))
            .on_worker_heartbeat(move |observation| {
                observed
                    .lock()
                    .expect("heartbeat observations")
                    .push(observation.clone());
            });

        worker.register_workflow("workflow.observed", |_ctx, _input| async move {
            Ok(Value::Null)
        });
        worker
            .run_until(tokio::time::sleep(Duration::from_millis(20)))
            .await
            .expect("run worker");

        let observations = observations.lock().expect("heartbeat observations");
        let first = observations.first().expect("heartbeat acknowledgement");
        assert_eq!(first.worker_id, "observed-heartbeat-worker");
        assert_eq!(first.task_queue, "rust-workers");
        assert!(first.acknowledged_at_unix_millis > 0);
        assert_eq!(first.acknowledgement, json!({}));
    }

    struct MockWorkerServer {
        addr: SocketAddr,
        stop: Arc<AtomicBool>,
        thread: Option<thread::JoinHandle<()>>,
    }

    impl MockWorkerServer {
        fn start() -> Self {
            let listener = TcpListener::bind("127.0.0.1:0").expect("bind mock server");
            listener
                .set_nonblocking(true)
                .expect("configure mock listener");
            let addr = listener.local_addr().expect("mock server address");
            let stop = Arc::new(AtomicBool::new(false));
            let server_stop = Arc::clone(&stop);
            let thread = thread::spawn(move || {
                while !server_stop.load(Ordering::SeqCst) {
                    match listener.accept() {
                        Ok((mut stream, _)) => handle_mock_worker_request(&mut stream),
                        Err(error) if error.kind() == std::io::ErrorKind::WouldBlock => {
                            thread::sleep(Duration::from_millis(5));
                        }
                        Err(_) => break,
                    }
                }
            });

            Self {
                addr,
                stop,
                thread: Some(thread),
            }
        }

        fn base_url(&self) -> String {
            format!("http://{}", self.addr)
        }
    }

    impl Drop for MockWorkerServer {
        fn drop(&mut self) {
            self.stop.store(true, Ordering::SeqCst);
            let _ = TcpStream::connect(self.addr);

            if let Some(thread) = self.thread.take() {
                thread.join().expect("join mock server");
            }
        }
    }

    fn handle_mock_worker_request(stream: &mut TcpStream) {
        let _ = stream.set_read_timeout(Some(Duration::from_millis(200)));
        let mut buffer = [0_u8; 8192];
        let mut request = Vec::new();

        loop {
            match stream.read(&mut buffer) {
                Ok(0) => break,
                Ok(read) => {
                    request.extend_from_slice(&buffer[..read]);
                    if request.windows(4).any(|window| window == b"\r\n\r\n") {
                        break;
                    }
                }
                Err(error)
                    if matches!(
                        error.kind(),
                        std::io::ErrorKind::WouldBlock | std::io::ErrorKind::TimedOut
                    ) =>
                {
                    break;
                }
                Err(_) => return,
            }
        }

        let request = String::from_utf8_lossy(&request);
        let path = request
            .lines()
            .next()
            .and_then(|line| line.split_whitespace().nth(1))
            .unwrap_or_default();
        let (status, body) = match path {
            "/api/worker/register" => (
                "200 OK",
                r#"{"worker_id":"mock-worker","registered":true,"heartbeat_interval_seconds":3600}"#,
            ),
            "/api/worker/heartbeat" => ("200 OK", "{}"),
            "/api/worker/activity-tasks/poll" | "/api/worker/workflow-tasks/poll" => {
                ("200 OK", r#"{"task":null}"#)
            }
            _ => ("404 Not Found", r#"{"message":"not found"}"#),
        };
        let response = format!(
            "HTTP/1.1 {status}\r\ncontent-type: application/json\r\ncontent-length: {}\r\nconnection: close\r\n\r\n{body}",
            body.len()
        );

        let _ = stream.write_all(response.as_bytes());
        let _ = stream.flush();
    }
}
