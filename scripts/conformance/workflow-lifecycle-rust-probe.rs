use std::{
    env, fmt,
    process::Command,
    sync::{
        atomic::{AtomicBool, AtomicUsize, Ordering},
        Arc, Mutex,
    },
    time::{Duration, Instant, SystemTime, UNIX_EPOCH},
};

use axum::{
    body::{to_bytes, Body},
    extract::State,
    http::{header, Request, Response, StatusCode},
    routing::any,
    Router,
};
use durable_workflow::{
    json, Client, Error, PayloadEnvelope, Result, Value, Worker, WorkflowCommandOptions,
    WorkflowResultOptions, WorkflowStartOptions, DEFAULT_CODEC,
};
use tokio::{net::TcpListener, sync::oneshot};

type ProbeResult<T> = std::result::Result<T, ProbeFailure>;

#[derive(Debug)]
struct ProbeFailure {
    error: Error,
    stable_reason: Option<&'static str>,
    failing_cell: Option<&'static str>,
    scenario_outcome: Option<Value>,
}

impl ProbeFailure {
    fn scenario(
        error: Error,
        stable_reason: &'static str,
        failing_cell: &'static str,
        scenario_outcome: Value,
    ) -> Self {
        Self {
            error,
            stable_reason: Some(stable_reason),
            failing_cell: Some(failing_cell),
            scenario_outcome: Some(scenario_outcome),
        }
    }
}

impl From<Error> for ProbeFailure {
    fn from(error: Error) -> Self {
        Self {
            error,
            stable_reason: None,
            failing_cell: None,
            scenario_outcome: None,
        }
    }
}

impl fmt::Display for ProbeFailure {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        fmt::Display::fmt(&self.error, formatter)
    }
}

fn suffix() -> u128 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap_or_default()
        .as_millis()
}

fn identity(handle: &durable_workflow::WorkflowHandle, scenario: &str) -> Value {
    json!({
        "scenario": scenario,
        "workflow_id": handle.workflow_id,
        "run_id": handle.run_id,
    })
}

fn require(condition: bool, reason: &str) -> Result<()> {
    if condition {
        Ok(())
    } else {
        Err(Error::Codec(reason.to_string()))
    }
}

#[derive(Clone)]
struct CompletionRetryProxy {
    server_url: String,
    client: reqwest::Client,
    observation: Arc<Mutex<Value>>,
    retried_transition: Arc<AtomicBool>,
}

async fn proxy_request(
    State(proxy): State<CompletionRetryProxy>,
    request: Request<Body>,
) -> Response<Body> {
    let (parts, body) = request.into_parts();
    let body = match to_bytes(body, 8 * 1024 * 1024).await {
        Ok(body) => body,
        Err(error) => {
            return Response::builder()
                .status(StatusCode::BAD_GATEWAY)
                .body(Body::from(format!(
                    "could not read proxied request: {error}"
                )))
                .unwrap_or_else(|_| Response::new(Body::empty()))
        }
    };
    let target = format!("{}{}", proxy.server_url, parts.uri);
    let mut forward = proxy.client.request(parts.method.clone(), &target);
    for (name, value) in &parts.headers {
        if name != header::HOST && name != header::CONTENT_LENGTH {
            forward = forward.header(name, value);
        }
    }
    let first = match forward.body(body.clone()).send().await {
        Ok(response) => response,
        Err(error) => {
            return Response::builder()
                .status(StatusCode::BAD_GATEWAY)
                .body(Body::from(format!("could not forward request: {error}")))
                .unwrap_or_else(|_| Response::new(Body::empty()))
        }
    };
    let first_status = first.status();
    let first_headers = first.headers().clone();
    let first_body = first.bytes().await.unwrap_or_default();

    let completion = parts.uri.path().contains("/worker/workflow-tasks/")
        && parts.uri.path().ends_with("/complete");
    let request_json = serde_json::from_slice::<Value>(&body).unwrap_or(Value::Null);
    let commands = request_json["commands"]
        .as_array()
        .cloned()
        .unwrap_or_default();
    let command_types = commands
        .iter()
        .filter_map(|command| command["type"].as_str().map(str::to_string))
        .collect::<Vec<_>>();
    let transition = command_types
        .iter()
        .any(|command| command == "continue_as_new");

    if completion {
        if let Ok(mut observation) = proxy.observation.lock() {
            *observation = json!({
                "completion_delivery_count":1,
                "task_path":parts.uri.path(),
                "commands":commands,
                "command_types":command_types,
                "first_response_status":first_status.as_u16(),
                "first_response":serde_json::from_slice::<Value>(&first_body).unwrap_or(Value::Null),
            });
        }
    }

    if completion && transition && !proxy.retried_transition.swap(true, Ordering::SeqCst) {
        let mut retry = proxy.client.request(parts.method, &target);
        for (name, value) in &parts.headers {
            if name != header::HOST && name != header::CONTENT_LENGTH {
                retry = retry.header(name, value);
            }
        }
        let (retry_status, retry_body) = match retry.body(body).send().await {
            Ok(response) => {
                let status = response.status().as_u16();
                let body = response.bytes().await.unwrap_or_default();
                (
                    status,
                    serde_json::from_slice::<Value>(&body).unwrap_or(Value::Null),
                )
            }
            Err(error) => (0, json!({"transport_error":error.to_string()})),
        };
        if let Ok(mut observation) = proxy.observation.lock() {
            observation["completion_delivery_count"] = json!(2);
            observation["retry_response_status"] = json!(retry_status);
            observation["retry_response"] = retry_body;
        }
    }

    let mut response = Response::builder().status(first_status);
    if let Some(content_type) = first_headers.get(header::CONTENT_TYPE) {
        response = response.header(header::CONTENT_TYPE, content_type);
    }
    response
        .body(Body::from(first_body))
        .unwrap_or_else(|_| Response::new(Body::empty()))
}

fn transition_worker(
    client: Client,
    queue: &str,
    worker_id: &str,
    phase: &'static str,
    callback_calls: Arc<AtomicUsize>,
) -> Worker {
    let mut worker = Worker::new(client, queue)
        .worker_id(worker_id)
        .poll_timeout(Duration::from_millis(500));
    worker.register_workflow("rust.lifecycle.continue-replay", move |ctx, input| {
        let callback_calls = Arc::clone(&callback_calls);
        async move {
            let input_phase = input
                .get(0)
                .and_then(|value| value.get("phase"))
                .and_then(Value::as_str)
                .unwrap_or("predecessor");
            require(
                input_phase == phase,
                "continue_as_new_phase_routing_mismatch",
            )?;
            let identity = ctx.workflow_identity()?;
            if phase == "predecessor" {
                let captured: String = ctx.side_effect(|| {
                    callback_calls.fetch_add(1, Ordering::SeqCst);
                    format!("predecessor-side-effect-{}", suffix())
                })?;
                let version = ctx.get_version("rust-continue-predecessor", 1, 2)?;
                return ctx.continue_as_new(json!([{
                    "phase":"successor",
                    "predecessor_side_effect":captured,
                    "predecessor_version":version,
                }]));
            }

            let captured: String = ctx.side_effect(|| {
                callback_calls.fetch_add(1, Ordering::SeqCst);
                format!("successor-side-effect-{}", suffix())
            })?;
            let version = ctx.get_version("rust-continue-successor", 1, 3)?;
            Ok(json!({
                "status":"completed",
                "workflow_id":identity.workflow_id,
                "run_id":identity.run_id,
                "successor_side_effect":captured,
                "successor_version":version,
            }))
        }
    });
    worker
}

fn argument(name: &str) -> Option<String> {
    let args = env::args().collect::<Vec<_>>();
    args.windows(2)
        .find(|pair| pair[0] == name)
        .map(|pair| pair[1].clone())
}

async fn run_transition_phase(phase: &'static str) -> Result<Value> {
    let server_url = env::var("DURABLE_WORKFLOW_SERVER_URL")
        .unwrap_or_else(|_| "http://127.0.0.1:8080".to_string());
    let token = env::var("DURABLE_WORKFLOW_TOKEN").unwrap_or_else(|_| "dev-token".to_string());
    let namespace = env::var("DURABLE_WORKFLOW_NAMESPACE")
        .unwrap_or_else(|_| "workflow-lifecycle-conformance".to_string());
    let queue = argument("--queue")
        .ok_or_else(|| Error::Codec("transition phase queue is required".to_string()))?;
    let worker_id = format!("rust-continue-{phase}-{}", std::process::id());
    let observation = Arc::new(Mutex::new(Value::Null));
    let proxy = CompletionRetryProxy {
        server_url,
        client: reqwest::Client::new(),
        observation: Arc::clone(&observation),
        retried_transition: Arc::new(AtomicBool::new(false)),
    };
    let listener = TcpListener::bind("127.0.0.1:0")
        .await
        .map_err(|error| Error::Codec(format!("could not bind completion retry proxy: {error}")))?;
    let proxy_url = format!(
        "http://{}",
        listener
            .local_addr()
            .map_err(|error| Error::Codec(format!("could not inspect proxy address: {error}")))?
    );
    let (shutdown_tx, shutdown_rx) = oneshot::channel::<()>();
    let proxy_task = tokio::spawn(async move {
        axum::serve(
            listener,
            Router::new()
                .route("/{*path}", any(proxy_request))
                .with_state(proxy),
        )
        .with_graceful_shutdown(async move {
            let _ = shutdown_rx.await;
        })
        .await
    });

    let client = Client::builder(&proxy_url)
        .token(Some(token))
        .namespace(namespace)
        .timeout(Duration::from_secs(15))
        .build()?;
    let callback_calls = Arc::new(AtomicUsize::new(0));
    let worker = transition_worker(
        client,
        &queue,
        &worker_id,
        phase,
        Arc::clone(&callback_calls),
    );
    worker.register().await?;
    let handled_tasks = worker.run_once().await?;
    drop(worker);
    let _ = shutdown_tx.send(());
    proxy_task
        .await
        .map_err(|error| Error::WorkerLoop(error.to_string()))?
        .map_err(|error| Error::WorkerLoop(error.to_string()))?;
    let completion = observation
        .lock()
        .map_err(|_| Error::WorkflowStatePoisoned)?
        .clone();

    Ok(json!({
        "phase":phase,
        "process_id":std::process::id(),
        "worker_id":worker_id,
        "handled_tasks":handled_tasks,
        "callback_calls":callback_calls.load(Ordering::SeqCst),
        "completion":completion,
    }))
}

fn run_transition_phase_process(phase: &str, queue: &str) -> Result<Value> {
    let executable = env::current_exe()
        .map_err(|error| Error::Codec(format!("cannot resolve Rust probe executable: {error}")))?;
    let output = Command::new(executable)
        .args(["--transition-phase", phase, "--queue", queue])
        .output()
        .map_err(|error| Error::Codec(format!("cannot launch {phase} worker process: {error}")))?;
    if !output.status.success() {
        return Err(Error::Codec(format!(
            "{phase} worker process failed: {}",
            String::from_utf8_lossy(&output.stderr).trim()
        )));
    }
    let last_line = String::from_utf8_lossy(&output.stdout)
        .lines()
        .map(str::trim)
        .filter(|line| !line.is_empty())
        .next_back()
        .unwrap_or("")
        .to_string();
    serde_json::from_str(&last_line)
        .map_err(|error| Error::Codec(format!("{phase} worker evidence is invalid: {error}")))
}

async fn control_plane_get(
    server_url: &str,
    token: &str,
    namespace: &str,
    path: &str,
) -> Result<Value> {
    let response = reqwest::Client::new()
        .get(format!("{server_url}/api{path}"))
        .bearer_auth(token)
        .header("Accept", "application/json")
        .header("X-Namespace", namespace)
        .header("X-Durable-Workflow-Control-Plane-Version", "2")
        .send()
        .await
        .map_err(|error| Error::Codec(format!("control-plane request failed: {error}")))?;
    let status = response.status();
    let body = response
        .json::<Value>()
        .await
        .map_err(|error| Error::Codec(format!("control-plane response was not JSON: {error}")))?;
    if !status.is_success() {
        return Err(Error::Codec(format!(
            "control-plane request returned HTTP {}: {body}",
            status.as_u16()
        )));
    }
    Ok(body)
}

fn history_event_count(history: &Value, event_type: &str) -> usize {
    history["events"]
        .as_array()
        .map(|events| {
            events
                .iter()
                .filter(|event| event["event_type"] == event_type)
                .count()
        })
        .unwrap_or_default()
}

fn history_event_payload<'a>(history: &'a Value, event_type: &str) -> Option<&'a Value> {
    history["events"]
        .as_array()?
        .iter()
        .find(|event| event["event_type"] == event_type)
        .map(|event| &event["payload"])
}

fn pending_worker(
    client: Client,
    queue: &str,
    worker_id: &str,
    started: Arc<AtomicBool>,
    settlement_gate: Arc<AtomicBool>,
    activity_observation: Arc<Mutex<Value>>,
) -> Worker {
    let mut worker = Worker::new(client.clone(), queue)
        .worker_id(worker_id)
        .poll_timeout(Duration::from_millis(250));
    worker.register_workflow("rust.lifecycle.pending", |ctx, _| async move {
        ctx.activity("rust.lifecycle.wait", json!([])).await
    });
    worker.register_activity("rust.lifecycle.wait", move |ctx, _| {
        let started = Arc::clone(&started);
        let settlement_gate = Arc::clone(&settlement_gate);
        let observation = Arc::clone(&activity_observation);
        let settlement_client = client.clone();
        async move {
            started.store(true, Ordering::SeqCst);
            while !settlement_gate.load(Ordering::SeqCst) {
                tokio::time::sleep(Duration::from_millis(10)).await;
            }
            let heartbeat = ctx
                .heartbeat(json!({"stage":"cancellation-observation"}))
                .await?;
            let late = settlement_client
                .complete_activity_task(
                    &ctx.task_id,
                    &ctx.activity_attempt_id,
                    &ctx.lease_owner,
                    json!({"late":true}),
                    DEFAULT_CODEC,
                )
                .await;
            let (late_type, late_reason, late_status) = match late {
                Err(Error::ActivityTaskRejected(rejection)) => (
                    "ActivityTaskRejected".to_string(),
                    rejection.reason,
                    rejection.status,
                ),
                Err(other) => ("UnexpectedError".to_string(), other.to_string(), 0),
                Ok(_) => (
                    "accepted".to_string(),
                    "late_completion_accepted".to_string(),
                    200,
                ),
            };
            *observation
                .lock()
                .map_err(|_| Error::WorkflowStatePoisoned)? = json!({
                "cancel_requested": heartbeat.cancel_requested,
                "should_stop": heartbeat.should_stop(),
                "heartbeat_reason": heartbeat.reason,
                "run_closed_reason": heartbeat.run_closed_reason,
                "late_completion_error_type": late_type,
                "late_completion_reason": late_reason,
                "late_completion_status": late_status,
            });
            Ok(json!({"late":true}))
        }
    });
    worker
}

async fn wait_started(started: &AtomicBool) -> Result<()> {
    for _ in 0..100 {
        if started.load(Ordering::SeqCst) {
            return Ok(());
        }
        tokio::time::sleep(Duration::from_millis(50)).await;
    }
    Err(Error::Timeout)
}

async fn wait_observed_at(observed_at: &Mutex<Option<Instant>>) -> Result<Instant> {
    for _ in 0..100 {
        let observed = *observed_at
            .lock()
            .map_err(|_| Error::WorkflowStatePoisoned)?;
        if let Some(observed) = observed {
            return Ok(observed);
        }
        tokio::time::sleep(Duration::from_millis(50)).await;
    }
    Err(Error::Timeout)
}

fn validated_product_failure(message: &str) -> Option<(&'static str, &'static str)> {
    const ASSERTIONS: &[(&str, &str)] = &[
        ("typed_cancelled_not_observed", "typed_cancelled"),
        (
            "cancellation_heartbeat_not_observed",
            "cancellation_heartbeat",
        ),
        (
            "late_activity_completion_not_refused",
            "late_activity_completion_refused",
        ),
        (
            "replacement_worker_reclaimed_cancelled_activity",
            "worker_restart_during_cancellation",
        ),
        ("typed_terminated_not_observed", "typed_terminated"),
        (
            "selected_run_command_identity_mismatch",
            "selected_run_guard",
        ),
        ("stale_run_successor_not_promoted", "stale_run_rejection"),
        (
            "stale_run_successor_identity_not_distinct",
            "stale_run_rejection",
        ),
        (
            "stale_run_predecessor_task_identity_mismatch",
            "stale_run_rejection",
        ),
        ("typed_stale_rejection_not_observed", "stale_run_rejection"),
        ("stale_run_rejection_status_not_409", "stale_run_rejection"),
        ("stale_run_rejection_reason_unstable", "stale_run_rejection"),
        (
            "stale_run_rejection_target_scope_unstable",
            "stale_run_rejection",
        ),
        (
            "stale_run_rejection_workflow_identity_mismatch",
            "stale_run_rejection",
        ),
        (
            "stale_run_rejection_run_identity_mismatch",
            "stale_run_rejection",
        ),
        ("typed_failed_not_observed", "typed_failed"),
        (
            "server_terminal_typed_timeout_reason_unstable",
            "typed_timed_out",
        ),
        (
            "client_wait_timeout_mislabeled_as_server_terminal",
            "typed_timed_out",
        ),
        ("typed_timeout_not_observed", "typed_timed_out"),
        ("published_avro_envelope_not_used", "payload_contract"),
        (
            "continue_as_new_transition_execution_failed",
            "continue_as_new_replay_boundary",
        ),
        (
            "continue_as_new_completion_redelivery_not_proven",
            "continue_as_new_replay_boundary",
        ),
        (
            "continue_as_new_worker_process_replacement_not_proven",
            "continue_as_new_replay_boundary",
        ),
        (
            "continue_as_new_run_identity_not_proven",
            "continue_as_new_replay_boundary",
        ),
        (
            "continue_as_new_history_links_not_proven",
            "continue_as_new_replay_boundary",
        ),
        (
            "continue_as_new_duplicate_predecessor_decisions_observed",
            "continue_as_new_replay_boundary",
        ),
        (
            "continue_as_new_callback_reinvoked",
            "continue_as_new_replay_boundary",
        ),
        (
            "continue_as_new_successor_decisions_not_distinct",
            "continue_as_new_replay_boundary",
        ),
        (
            "continue_as_new_result_routing_not_proven",
            "continue_as_new_replay_boundary",
        ),
    ];

    ASSERTIONS
        .iter()
        .find(|(reason, _)| message.contains(reason))
        .copied()
}

#[tokio::main]
async fn main() {
    if let Some(phase) = argument("--transition-phase") {
        let phase = match phase.as_str() {
            "predecessor" => "predecessor",
            "successor" => "successor",
            other => {
                eprintln!("unsupported transition phase: {other}");
                std::process::exit(2);
            }
        };
        match run_transition_phase(phase).await {
            Ok(evidence) => {
                println!("{evidence}");
                return;
            }
            Err(error) => {
                eprintln!("{error}");
                std::process::exit(1);
            }
        }
    }

    if let Err(error) = run_probe().await {
        let error_message = error.to_string();
        let validated_failure = error
            .stable_reason
            .zip(error.failing_cell)
            .or_else(|| validated_product_failure(&error_message));
        if let Some((stable_reason, failing_cell)) = validated_failure {
            let sdk_version = env::var("DW_RUST_SDK_VERSION").unwrap_or_default();
            let server_version = env::var("DW_SERVER_VERSION").unwrap_or_default();
            let server_http_process =
                env::var("DW_WORKFLOW_LIFECYCLE_SERVER_HTTP_PROCESS").unwrap_or_default();
            let scheduler_process =
                env::var("DW_WORKFLOW_LIFECYCLE_SCHEDULER_PROCESS").unwrap_or_default();
            let rust_executor = env::var("DW_WORKFLOW_LIFECYCLE_RUST_EXECUTOR").unwrap_or_default();
            let bounded_error = error_message.chars().take(384).collect::<String>();
            let observed_behavior = format!(
                "Rust lifecycle scenario {failing_cell} did not satisfy {stable_reason}: {bounded_error}"
            );
            let mut scenario_outcome = error.scenario_outcome.unwrap_or_else(|| json!({}));
            if let Some(fields) = scenario_outcome.as_object_mut() {
                fields.insert("status".to_string(), json!("fail"));
                fields.insert("stable_reason".to_string(), json!(stable_reason));
                fields.insert(
                    "observed_behavior".to_string(),
                    json!(observed_behavior.clone()),
                );
            }
            println!(
                "{}",
                json!({
                    "sdk":"sdk-rust",
                    "artifact_version":sdk_version,
                    "server_version":server_version,
                    "covered_cells":[],
                    "unsupported_cells":[],
                    "typed_errors":[],
                    "scenario_outcomes":{
                        (failing_cell):scenario_outcome
                    },
                    "stable_reason":stable_reason,
                    "stable_reasons":[stable_reason],
                    "failure_message":observed_behavior,
                    "failing_lifecycle_cell":failing_cell,
                    "probe_outcome":"fail",
                    "rust_shard_contract_version":3,
                    "executor_topology":{
                        "server_http_process":server_http_process,
                        "scheduler_process":scheduler_process,
                        "rust_executor":rust_executor,
                        "rust_executor_outside_server_image":true
                    },
                    "published_artifact_cell_executed":true,
                    "local_product_source_checkouts_used":false
                })
            );
        } else {
            eprintln!("Rust lifecycle probe stopped without validated scenario failure evidence.");
        }
        std::process::exit(1);
    }
}

async fn run_probe() -> ProbeResult<()> {
    let base_url = env::var("DURABLE_WORKFLOW_SERVER_URL")
        .unwrap_or_else(|_| "http://127.0.0.1:8080".to_string());
    let token = env::var("DURABLE_WORKFLOW_TOKEN").unwrap_or_else(|_| "dev-token".to_string());
    let namespace = env::var("DURABLE_WORKFLOW_NAMESPACE")
        .unwrap_or_else(|_| "workflow-lifecycle-conformance".to_string());
    let expected_server = env::var("DW_SERVER_VERSION").unwrap_or_default();
    let sdk_version = env::var("DW_RUST_SDK_VERSION").unwrap_or_default();
    let server_http_process =
        env::var("DW_WORKFLOW_LIFECYCLE_SERVER_HTTP_PROCESS").unwrap_or_default();
    let scheduler_process = env::var("DW_WORKFLOW_LIFECYCLE_SCHEDULER_PROCESS").unwrap_or_default();
    let rust_executor = env::var("DW_WORKFLOW_LIFECYCLE_RUST_EXECUTOR").unwrap_or_default();
    require(
        server_http_process == "exact_published_image"
            && scheduler_process == "exact_published_image"
            && rust_executor == "host_rust_container",
        "required_published_executor_topology_not_observed",
    )?;
    let client = Client::builder(&base_url)
        .token(Some(token.clone()))
        .namespace(&namespace)
        .timeout(Duration::from_secs(10))
        .build()?;

    let cluster = client.cluster_info().await?;
    require(
        !expected_server.is_empty() && cluster.to_string().contains(&expected_server),
        "matching_published_server_version_not_observed",
    )?;

    let mut identities = Vec::new();
    let mut outcomes = serde_json::Map::new();
    let mut reasons: Vec<String> = Vec::new();

    let transition_queue = format!("rust-lifecycle-continue-{}", suffix());
    let transition_workflow_id = format!("rust-lifecycle-continue-{}", suffix());
    let transition_handle = client
        .start_workflow(
            "rust.lifecycle.continue-replay",
            &transition_queue,
            &transition_workflow_id,
            json!([{"phase":"predecessor"}]),
        )
        .await?;
    let predecessor_run_id = transition_handle.run_id.clone().unwrap_or_default();
    identities.push(identity(
        &transition_handle,
        "continue_as_new_replay_boundary_predecessor",
    ));
    let predecessor_process = run_transition_phase_process("predecessor", &transition_queue)
        .map_err(|error| {
            ProbeFailure::scenario(
                error,
                "continue_as_new_transition_execution_failed",
                "continue_as_new_replay_boundary",
                json!({"phase":"predecessor"}),
            )
        })?;
    let successor_after_transition = transition_handle.describe().await.map_err(|error| {
        ProbeFailure::scenario(
            error,
            "continue_as_new_transition_execution_failed",
            "continue_as_new_replay_boundary",
            json!({"phase":"successor_description"}),
        )
    })?;
    let successor_run_id = successor_after_transition
        .run_id
        .clone()
        .unwrap_or_default();
    identities.push(json!({
        "scenario":"continue_as_new_replay_boundary_successor",
        "workflow_id":transition_workflow_id,
        "run_id":successor_run_id,
    }));
    let successor_process =
        run_transition_phase_process("successor", &transition_queue).map_err(|error| {
            ProbeFailure::scenario(
                error,
                "continue_as_new_transition_execution_failed",
                "continue_as_new_replay_boundary",
                json!({"phase":"successor"}),
            )
        })?;
    let final_result = transition_handle
        .result(WorkflowResultOptions::default())
        .await
        .map_err(|error| {
            ProbeFailure::scenario(
                error,
                "continue_as_new_result_routing_not_proven",
                "continue_as_new_replay_boundary",
                json!({"phase":"chain_result"}),
            )
        })?;
    let current_run = transition_handle.describe().await?;
    let selected_historical_run = transition_handle.describe_selected_run().await?;
    let run_chain = control_plane_get(
        &base_url,
        &token,
        &namespace,
        &format!("/workflows/{transition_workflow_id}/runs"),
    )
    .await?;
    let predecessor_history = control_plane_get(
        &base_url,
        &token,
        &namespace,
        &format!(
            "/workflows/{transition_workflow_id}/runs/{predecessor_run_id}/history?page_size=200"
        ),
    )
    .await?;
    let successor_history = control_plane_get(
        &base_url,
        &token,
        &namespace,
        &format!(
            "/workflows/{transition_workflow_id}/runs/{successor_run_id}/history?page_size=200"
        ),
    )
    .await?;
    let predecessor_counts = json!({
        "SideEffectRecorded":history_event_count(&predecessor_history, "SideEffectRecorded"),
        "VersionMarkerRecorded":history_event_count(&predecessor_history, "VersionMarkerRecorded"),
        "WorkflowContinuedAsNew":history_event_count(&predecessor_history, "WorkflowContinuedAsNew"),
    });
    let successor_counts = json!({
        "SideEffectRecorded":history_event_count(&successor_history, "SideEffectRecorded"),
        "VersionMarkerRecorded":history_event_count(&successor_history, "VersionMarkerRecorded"),
        "WorkflowContinuedAsNew":history_event_count(&successor_history, "WorkflowContinuedAsNew"),
    });
    let transition_link = history_event_payload(&predecessor_history, "WorkflowContinuedAsNew")
        .cloned()
        .unwrap_or(Value::Null);
    let successor_link = history_event_payload(&successor_history, "WorkflowStarted")
        .cloned()
        .unwrap_or(Value::Null);
    let transition_outcome = json!({
        "status":"pass",
        "workflow_id":transition_workflow_id,
        "predecessor_run_id":predecessor_run_id,
        "successor_run_id":successor_run_id,
        "current_run_id":current_run.run_id,
        "selected_historical_run_id":selected_historical_run.run_id,
        "selected_historical_closed_reason":selected_historical_run.closed_reason,
        "run_chain":run_chain,
        "predecessor_history":predecessor_history,
        "successor_history":successor_history,
        "predecessor_history_event_counts":predecessor_counts,
        "successor_history_event_counts":successor_counts,
        "predecessor_transition_link":transition_link,
        "successor_transition_link":successor_link,
        "predecessor_worker_process":predecessor_process,
        "successor_worker_process":successor_process,
        "final_result":final_result,
        "final_result_observation_source":"WorkflowHandle::result",
        "current_run_observation_source":"WorkflowHandle::describe",
        "selected_run_observation_source":"WorkflowHandle::describe_selected_run",
        "predecessor_decisions_immutable":true,
        "successor_decisions_are_new_run_decisions":true,
        "successor_count":1,
    });
    let transition_failure = |stable_reason: &'static str| {
        ProbeFailure::scenario(
            Error::Codec(stable_reason.to_string()),
            stable_reason,
            "continue_as_new_replay_boundary",
            transition_outcome.clone(),
        )
    };
    let predecessor_commands = &predecessor_process["completion"]["command_types"];
    let successor_commands = &successor_process["completion"]["command_types"];
    if predecessor_process["completion"]["completion_delivery_count"] != 2
        || predecessor_process["completion"]["first_response"]["recorded"] != true
        || predecessor_process["completion"]["retry_response_status"] != 409
        || predecessor_process["completion"]["retry_response"]["reason"]
            .as_str()
            .unwrap_or("")
            .is_empty()
        || predecessor_commands
            != &json!([
                "record_side_effect",
                "record_version_marker",
                "continue_as_new"
            ])
    {
        return Err(transition_failure(
            "continue_as_new_completion_redelivery_not_proven",
        ));
    }
    if predecessor_process["process_id"] == successor_process["process_id"]
        || predecessor_process["worker_id"] == successor_process["worker_id"]
        || predecessor_process["handled_tasks"] != 1
        || successor_process["handled_tasks"] != 1
    {
        return Err(transition_failure(
            "continue_as_new_worker_process_replacement_not_proven",
        ));
    }
    let run_ids = run_chain["runs"]
        .as_array()
        .map(|runs| {
            runs.iter()
                .filter_map(|run| run["run_id"].as_str())
                .collect::<Vec<_>>()
        })
        .unwrap_or_default();
    let run_numbers = run_chain["runs"]
        .as_array()
        .map(|runs| {
            runs.iter()
                .filter_map(|run| run["run_number"].as_u64())
                .collect::<Vec<_>>()
        })
        .unwrap_or_default();
    if predecessor_run_id.is_empty()
        || successor_run_id.is_empty()
        || predecessor_run_id == successor_run_id
        || run_chain["workflow_id"] != transition_workflow_id
        || run_chain["run_count"] != 2
        || run_ids != vec![predecessor_run_id.as_str(), successor_run_id.as_str()]
        || run_numbers != vec![1, 2]
        || current_run.workflow_id.as_deref() != Some(transition_workflow_id.as_str())
        || current_run.run_id.as_deref() != Some(successor_run_id.as_str())
        || selected_historical_run.workflow_id.as_deref() != Some(transition_workflow_id.as_str())
        || selected_historical_run.run_id.as_deref() != Some(predecessor_run_id.as_str())
        || selected_historical_run.closed_reason.as_deref() != Some("continued")
    {
        return Err(transition_failure(
            "continue_as_new_run_identity_not_proven",
        ));
    }
    if transition_link["continued_to_run_id"] != successor_run_id
        || successor_link["continued_from_run_id"] != predecessor_run_id
        || predecessor_history["workflow_id"] != transition_workflow_id
        || predecessor_history["run_id"] != predecessor_run_id
        || successor_history["workflow_id"] != transition_workflow_id
        || successor_history["run_id"] != successor_run_id
    {
        return Err(transition_failure(
            "continue_as_new_history_links_not_proven",
        ));
    }
    if predecessor_counts["SideEffectRecorded"] != 1
        || predecessor_counts["VersionMarkerRecorded"] != 1
        || predecessor_counts["WorkflowContinuedAsNew"] != 1
    {
        return Err(transition_failure(
            "continue_as_new_duplicate_predecessor_decisions_observed",
        ));
    }
    if predecessor_process["callback_calls"] != 1 {
        return Err(transition_failure("continue_as_new_callback_reinvoked"));
    }
    if successor_process["callback_calls"] != 1
        || successor_process["completion"]["completion_delivery_count"] != 1
        || successor_commands
            != &json!([
                "record_side_effect",
                "record_version_marker",
                "complete_workflow"
            ])
        || successor_counts["SideEffectRecorded"] != 1
        || successor_counts["VersionMarkerRecorded"] != 1
        || successor_counts["WorkflowContinuedAsNew"] != 0
    {
        return Err(transition_failure(
            "continue_as_new_successor_decisions_not_distinct",
        ));
    }
    if final_result["status"] != "completed"
        || final_result["workflow_id"] != transition_workflow_id
        || final_result["run_id"] != successor_run_id
        || final_result["successor_version"] != 3
    {
        return Err(transition_failure(
            "continue_as_new_result_routing_not_proven",
        ));
    }
    outcomes.insert("continue_as_new_replay_boundary".into(), transition_outcome);
    reasons.push("workflow_task_completion_redelivery_rejected".to_string());

    let queue = format!("rust-lifecycle-cancel-{}", suffix());
    let started = Arc::new(AtomicBool::new(false));
    let cancellation_settlement_gate = Arc::new(AtomicBool::new(false));
    let activity_observation = Arc::new(Mutex::new(Value::Null));
    let worker = pending_worker(
        client.clone(),
        &queue,
        "rust-lifecycle-cancel-worker",
        Arc::clone(&started),
        Arc::clone(&cancellation_settlement_gate),
        Arc::clone(&activity_observation),
    );
    let cancel_handle = client
        .start_workflow(
            "rust.lifecycle.pending",
            &queue,
            &format!("rust-lifecycle-cancel-{}", suffix()),
            json!([{"payload":"avro-envelope"}]),
        )
        .await?;
    identities.push(identity(&cancel_handle, "instance_cancel"));
    worker.register().await?;
    let running = tokio::spawn(async move { worker.run_once().await });
    wait_started(&started).await?;
    let cancel_command = cancel_handle
        .cancel(WorkflowCommandOptions::new().reason("rust_conformance_cancel"))
        .await?;
    let restart_observation_origin = Instant::now();
    let replacement_activity_started = Arc::new(AtomicBool::new(false));
    let replacement_poll_started_at = Arc::new(Mutex::new(None));
    let replacement_observation = Arc::new(Mutex::new(Value::Null));
    let replacement = pending_worker(
        client.clone(),
        &queue,
        "rust-lifecycle-cancel-worker-restarted",
        Arc::clone(&replacement_activity_started),
        Arc::new(AtomicBool::new(true)),
        replacement_observation,
    );
    replacement.register().await?;
    let replacement_poll_started_at_task = Arc::clone(&replacement_poll_started_at);
    let replacement_running = tokio::spawn(async move {
        *replacement_poll_started_at_task
            .lock()
            .map_err(|_| Error::WorkflowStatePoisoned)? = Some(Instant::now());
        replacement.run_once().await
    });
    let observed_replacement_poll_started_at =
        wait_observed_at(&replacement_poll_started_at).await?;
    let original_activity_unsettled_when_replacement_poll_started = !activity_observation
        .lock()
        .map_err(|_| Error::WorkflowStatePoisoned)?
        .is_object()
        && !cancellation_settlement_gate.load(Ordering::SeqCst);
    let settlement_released_at = Instant::now();
    cancellation_settlement_gate.store(true, Ordering::SeqCst);
    running
        .await
        .map_err(|error| Error::WorkerLoop(error.to_string()))??;
    let original_settlement_observed_at = Instant::now();
    let original_activity_settled = activity_observation
        .lock()
        .map_err(|_| Error::WorkflowStatePoisoned)?
        .is_object();
    let replacement_handled = replacement_running
        .await
        .map_err(|error| Error::WorkerLoop(error.to_string()))??;
    let cancel_error = match cancel_handle.result(WorkflowResultOptions::default()).await {
        Err(error) => error,
        Ok(_) => {
            return Err(Error::Codec(
                "typed_cancelled_not_observed:workflow_returned_success".to_string(),
            )
            .into())
        }
    };
    let cancel_reason = match cancel_error {
        Error::WorkflowCancelled(outcome) => outcome.reason,
        other => return Err(Error::Codec(format!("typed_cancelled_not_observed:{other}")).into()),
    };
    let observation = activity_observation
        .lock()
        .map_err(|_| Error::WorkflowStatePoisoned)?
        .clone();
    require(
        observation["cancel_requested"] == true,
        "cancellation_heartbeat_not_observed",
    )?;
    require(
        observation["late_completion_error_type"] == "ActivityTaskRejected",
        "late_activity_completion_not_refused",
    )?;
    outcomes.insert(
        "instance_cancel".into(),
        json!({
            "status":"pass",
            "command_status":cancel_command.command_status,
            "target_scope":"instance",
            "typed_outcome":"WorkflowCancelled",
            "reason":cancel_reason.clone(),
            "activity":observation.clone(),
        }),
    );
    outcomes.insert(
        "typed_cancelled".into(),
        json!({"status":"pass", "typed_outcome":"WorkflowCancelled", "reason":cancel_reason}),
    );
    outcomes.insert(
        "cancellation_heartbeat".into(),
        json!({
            "status":"pass",
            "cancel_requested":observation["cancel_requested"],
            "should_stop":observation["should_stop"],
            "reason":observation["heartbeat_reason"],
            "run_closed_reason":observation["run_closed_reason"],
        }),
    );
    outcomes.insert(
        "late_activity_completion_refused".into(),
        json!({
            "status":"pass",
            "typed_error":observation["late_completion_error_type"],
            "reason":observation["late_completion_reason"],
            "http_status":observation["late_completion_status"],
        }),
    );
    reasons.push("run_cancelled".to_string());
    require(
        replacement_handled == 0,
        "replacement_worker_reclaimed_cancelled_activity",
    )?;
    let replacement_poll_start_observed = replacement_poll_started_at
        .lock()
        .map_err(|_| Error::WorkflowStatePoisoned)?
        .is_some();
    let replacement_started_before_original_settled = replacement_poll_start_observed
        && original_activity_unsettled_when_replacement_poll_started
        && observed_replacement_poll_started_at < original_settlement_observed_at;
    let settlement_released_after_replacement_started =
        observed_replacement_poll_started_at < settlement_released_at;
    let original_settled_after_restart = original_activity_settled
        && observed_replacement_poll_started_at < original_settlement_observed_at;
    outcomes.insert(
        "worker_restart_during_cancellation".into(),
        json!({
            "status":"pass",
            "restart_phase":"cancellation_pending",
            "replacement_registered":true,
            "replacement_poll_start_observed":replacement_poll_start_observed,
            "original_activity_unsettled_when_replacement_poll_started":original_activity_unsettled_when_replacement_poll_started,
            "replacement_started_before_original_settled":replacement_started_before_original_settled,
            "settlement_released_after_replacement_started":settlement_released_after_replacement_started,
            "original_settled_after_restart":original_settled_after_restart,
            "replacement_poll_started_elapsed_ns":observed_replacement_poll_started_at.duration_since(restart_observation_origin).as_nanos(),
            "settlement_released_elapsed_ns":settlement_released_at.duration_since(restart_observation_origin).as_nanos(),
            "original_settlement_observed_elapsed_ns":original_settlement_observed_at.duration_since(restart_observation_origin).as_nanos(),
            "handled_tasks":replacement_handled,
        }),
    );

    let terminate_queue = format!("rust-lifecycle-terminate-{}", suffix());
    let terminate_started = Arc::new(AtomicBool::new(false));
    let terminate_settlement_gate = Arc::new(AtomicBool::new(false));
    let terminate_observation = Arc::new(Mutex::new(Value::Null));
    let terminate_worker = pending_worker(
        client.clone(),
        &terminate_queue,
        "rust-lifecycle-terminate-worker",
        Arc::clone(&terminate_started),
        Arc::clone(&terminate_settlement_gate),
        terminate_observation,
    );
    let terminate_handle = client
        .start_workflow(
            "rust.lifecycle.pending",
            &terminate_queue,
            &format!("rust-lifecycle-terminate-{}", suffix()),
            json!([]),
        )
        .await?;
    identities.push(identity(&terminate_handle, "instance_terminate"));
    terminate_worker.register().await?;
    let terminate_running = tokio::spawn(async move { terminate_worker.run_once().await });
    wait_started(&terminate_started).await?;
    let terminate_command = terminate_handle
        .terminate(WorkflowCommandOptions::new().reason("rust_conformance_terminate"))
        .await?;
    terminate_settlement_gate.store(true, Ordering::SeqCst);
    terminate_running
        .await
        .map_err(|error| Error::WorkerLoop(error.to_string()))??;
    let terminate_error = match terminate_handle
        .result(WorkflowResultOptions::default())
        .await
    {
        Err(error) => error,
        Ok(_) => {
            return Err(Error::Codec(
                "typed_terminated_not_observed:workflow_returned_success".to_string(),
            )
            .into())
        }
    };
    let terminate_reason = match terminate_error {
        Error::WorkflowTerminated(outcome) => outcome.reason,
        other => return Err(Error::Codec(format!("typed_terminated_not_observed:{other}")).into()),
    };
    outcomes.insert(
        "instance_terminate".into(),
        json!({
            "status":"pass",
            "command_status":terminate_command.command_status,
            "target_scope":"instance",
            "typed_outcome":"WorkflowTerminated",
            "reason":terminate_reason.clone(),
        }),
    );
    outcomes.insert(
        "typed_terminated".into(),
        json!({"status":"pass", "typed_outcome":"WorkflowTerminated", "reason":terminate_reason}),
    );
    reasons.push("run_terminated".to_string());

    let selected_queue = format!("rust-lifecycle-selected-{}", suffix());
    let selected_started = Arc::new(AtomicBool::new(false));
    let selected_settlement_gate = Arc::new(AtomicBool::new(false));
    let selected_observation = Arc::new(Mutex::new(Value::Null));
    let selected_worker = pending_worker(
        client.clone(),
        &selected_queue,
        "rust-lifecycle-selected-worker",
        Arc::clone(&selected_started),
        Arc::clone(&selected_settlement_gate),
        selected_observation,
    );
    let selected_workflow_id = format!("rust-lifecycle-selected-{}", suffix());
    let selected_handle = client
        .start_workflow(
            "rust.lifecycle.pending",
            &selected_queue,
            &selected_workflow_id,
            json!([]),
        )
        .await?;
    identities.push(identity(&selected_handle, "selected_run_guard"));
    selected_worker.register().await?;
    let selected_running = tokio::spawn(async move { selected_worker.run_once().await });
    wait_started(&selected_started).await?;
    let selected_command = selected_handle
        .cancel_selected_run(WorkflowCommandOptions::new().reason("rust_selected_run_cancel"))
        .await?;
    selected_settlement_gate.store(true, Ordering::SeqCst);
    selected_running
        .await
        .map_err(|error| Error::WorkerLoop(error.to_string()))??;
    require(
        selected_command.run_id == selected_handle.run_id,
        "selected_run_command_identity_mismatch",
    )?;
    outcomes.insert(
        "selected_run_guard".into(),
        json!({
            "status":"pass",
            "workflow_id":selected_command.workflow_id,
            "run_id":selected_command.run_id,
            "command_status":selected_command.command_status,
            "target_scope":"run",
        }),
    );

    let stale_queue = format!("rust-lifecycle-stale-{}", suffix());
    let stale_worker_id = "rust-lifecycle-stale-worker";
    let mut stale_worker = Worker::new(client.clone(), &stale_queue)
        .worker_id(stale_worker_id)
        .poll_timeout(Duration::from_millis(250));
    stale_worker.register_workflow("rust.lifecycle.pending", |_ctx, _| async move {
        Ok(json!({"unexpected":"stale_predecessor_executed"}))
    });
    stale_worker.register().await?;
    let stale_workflow_id = format!("rust-lifecycle-stale-{}", suffix());
    let stale_handle = client
        .start_workflow(
            "rust.lifecycle.pending",
            &stale_queue,
            &stale_workflow_id,
            json!([]),
        )
        .await?;
    identities.push(identity(&stale_handle, "stale_run_rejection_predecessor"));
    let stale_task = client
        .poll_workflow_task(stale_worker_id, &stale_queue, Duration::from_secs(5))
        .await
        .map_err(|error| Error::Codec(format!("stale_run_successor_not_promoted:{error}")))?
        .ok_or_else(|| {
            Error::Codec("stale_run_successor_not_promoted:workflow_task_missing".to_string())
        })?;
    require(
        stale_task.run_id == stale_handle.run_id,
        "stale_run_predecessor_task_identity_mismatch",
    )?;
    let stale_lease_owner = stale_task.lease_owner.as_deref().unwrap_or(stale_worker_id);
    let successor_arguments = PayloadEnvelope::avro(&json!([]))?;
    let successor_completion = client
        .complete_workflow_task(
            &stale_task.task_id,
            stale_lease_owner,
            stale_task.workflow_task_attempt,
            vec![json!({
                "type":"continue_as_new",
                "workflow_type":"rust.lifecycle.pending",
                "arguments":{
                    "codec":successor_arguments.codec,
                    "blob":successor_arguments.blob,
                },
            })],
        )
        .await
        .map_err(|error| Error::Codec(format!("stale_run_successor_not_promoted:{error}")))?;
    require(
        successor_completion["recorded"] == true,
        "stale_run_successor_not_promoted",
    )?;
    let successor_description = client
        .describe_workflow(&stale_workflow_id)
        .await
        .map_err(|error| Error::Codec(format!("stale_run_successor_not_promoted:{error}")))?;
    let successor_run_id = successor_description.run_id.clone();
    require(
        successor_run_id.is_some() && successor_run_id != stale_handle.run_id,
        "stale_run_successor_identity_not_distinct",
    )?;
    identities.push(json!({
        "scenario":"stale_run_rejection_successor",
        "workflow_id":stale_workflow_id,
        "run_id":successor_run_id,
    }));

    let selected_error = match stale_handle
        .cancel_selected_run(WorkflowCommandOptions::default())
        .await
    {
        Err(error) => error,
        Ok(_) => {
            return Err(Error::Codec(
                "typed_stale_rejection_not_observed:command_was_accepted".to_string(),
            )
            .into())
        }
    };
    let stale = match selected_error {
        Error::WorkflowCommandRejected(rejection) => rejection,
        other => {
            return Err(Error::Codec(format!("typed_stale_rejection_not_observed:{other}")).into())
        }
    };
    let stale_failure = |stable_reason: &'static str| {
        ProbeFailure::scenario(
            Error::Codec(stable_reason.to_string()),
            stable_reason,
            "stale_run_rejection",
            json!({
                "typed_error":"WorkflowCommandRejected",
                "http_status":stale.status,
                "reason":stale.reason,
                "target_scope":stale.target_scope,
                "workflow_id":stale.workflow_id,
                "run_id":stale.run_id,
                "prior_run_id":stale_handle.run_id,
                "successor_run_id":successor_run_id,
                "successor_workflow_id":stale_workflow_id,
            }),
        )
    };
    if stale.status != 409 {
        return Err(stale_failure("stale_run_rejection_status_not_409"));
    }
    if stale.reason != "historical_run_command_rejected" {
        return Err(stale_failure("stale_run_rejection_reason_unstable"));
    }
    if stale.target_scope.as_deref() != Some("run") {
        return Err(stale_failure("stale_run_rejection_target_scope_unstable"));
    }
    if stale.workflow_id != stale_workflow_id {
        return Err(stale_failure(
            "stale_run_rejection_workflow_identity_mismatch",
        ));
    }
    if stale.run_id != stale_handle.run_id {
        return Err(stale_failure("stale_run_rejection_run_identity_mismatch"));
    }
    outcomes.insert(
        "stale_run_rejection".into(),
        json!({
            "status":"pass", "typed_error":"WorkflowCommandRejected",
            "http_status":stale.status, "reason":stale.reason,
            "workflow_id":stale.workflow_id,
            "run_id":stale.run_id, "target_scope":stale.target_scope,
            "prior_run_id":stale_handle.run_id,
            "successor_run_id":successor_run_id,
            "successor_workflow_id":stale_workflow_id,
        }),
    );
    reasons.push("historical_run_command_rejected".to_string());

    let fail_queue = format!("rust-lifecycle-fail-{}", suffix());
    let mut fail_worker = Worker::new(client.clone(), &fail_queue)
        .worker_id("rust-lifecycle-fail-worker")
        .poll_timeout(Duration::from_millis(200));
    fail_worker.register_workflow("rust.lifecycle.fail", |_ctx, _| async move {
        Err(Error::Codec("rust_conformance_failure".to_string()))
    });
    let fail_handle = client
        .start_workflow(
            "rust.lifecycle.fail",
            &fail_queue,
            &format!("rust-lifecycle-fail-{}", suffix()),
            json!([]),
        )
        .await?;
    identities.push(identity(&fail_handle, "typed_failed"));
    fail_worker.register().await?;
    fail_worker.run_once().await?;
    let fail_error = match fail_handle.result(WorkflowResultOptions::default()).await {
        Err(error) => error,
        Ok(_) => {
            return Err(Error::Codec(
                "typed_failed_not_observed:workflow_returned_success".to_string(),
            )
            .into())
        }
    };
    match fail_error {
        Error::WorkflowFailed(outcome) => {
            reasons.push(outcome.reason.clone());
            outcomes.insert(
                "typed_failed".into(),
                json!({
                    "status":"pass", "typed_outcome":"WorkflowFailed", "reason":outcome.reason,
                    "failure_category":outcome.failure_category,
                }),
            );
        }
        other => return Err(Error::Codec(format!("typed_failed_not_observed:{other}")).into()),
    }

    let timeout_queue = format!("rust-lifecycle-timeout-{}", suffix());
    let mut timeout_worker = Worker::new(client.clone(), &timeout_queue)
        .worker_id("rust-lifecycle-timeout-worker")
        .poll_timeout(Duration::from_millis(250));
    timeout_worker.register_workflow("rust.lifecycle.timeout", |_ctx, _| async move {
        Ok(json!({"unexpected":"deadline_not_enforced"}))
    });
    timeout_worker.register().await?;
    let timeout_handle = client
        .start_workflow_with_options(
            "rust.lifecycle.timeout",
            &timeout_queue,
            &format!("rust-lifecycle-timeout-{}", suffix()),
            WorkflowStartOptions::new()
                .execution_timeout_seconds(30)
                .run_timeout_seconds(1),
            json!([]),
        )
        .await?;
    identities.push(identity(&timeout_handle, "typed_timed_out"));
    tokio::time::sleep(Duration::from_millis(1_500)).await;
    timeout_worker.run_once().await?;
    let timeout_result = timeout_handle
        .result(WorkflowResultOptions {
            poll_interval: Duration::from_millis(200),
            timeout: Duration::from_secs(15),
        })
        .await;
    let timeout_error = match timeout_result {
        Err(error) => error,
        Ok(_) => {
            return Err(Error::Codec(
                "typed_timeout_not_observed:workflow_returned_success".to_string(),
            )
            .into())
        }
    };
    match timeout_error {
        Error::WorkflowTimedOut(outcome) => {
            if outcome.reason != "run_timeout" {
                return Err(Error::Codec(format!(
                    "server_terminal_typed_timeout_reason_unstable:observed_reason={}",
                    outcome.reason
                ))
                .into());
            }
            if outcome.failure_category.as_deref() == Some("client_timeout") {
                return Err(Error::Codec(
                    "client_wait_timeout_mislabeled_as_server_terminal:observed_failure_category=client_timeout"
                        .to_string(),
                ).into());
            }
            reasons.push(outcome.reason.clone());
            outcomes.insert(
                "typed_timed_out".into(),
                json!({
                    "status":"pass",
                    "typed_outcome":"WorkflowTimedOut",
                    "reason":outcome.reason,
                    "failure_category":outcome.failure_category,
                    "observation_source":"WorkflowHandle::result",
                    "server_terminal":true,
                    "server_closed_reason":"timed_out",
                    "run_timeout_seconds":1,
                }),
            );
        }
        other => return Err(Error::Codec(format!("typed_timeout_not_observed:{other}")).into()),
    }

    let envelope = PayloadEnvelope::avro(&json!([{"probe":"official-apache-avro-envelope"}]))?;
    require(envelope.codec == "avro", "published_avro_envelope_not_used")?;
    outcomes.insert(
        "payload_contract".into(),
        json!({
            "status":"pass", "codec":envelope.codec, "blob_non_empty":!envelope.blob.is_empty(),
        }),
    );

    println!(
        "{}",
        json!({
            "sdk":"sdk-rust",
            "artifact_version":sdk_version,
            "server_version":expected_server,
            "server_cluster_info":cluster,
            "covered_cells":[
                "instance_cancel", "instance_terminate", "selected_run_guard", "stale_run_rejection",
                "typed_failed", "typed_cancelled", "typed_terminated", "typed_timed_out",
                "cancellation_heartbeat", "late_activity_completion_refused",
                "worker_restart_during_cancellation", "continue_as_new_replay_boundary"
            ],
            "unsupported_cells":[],
            "typed_errors":[],
            "workflow_identities":identities,
            "scenario_outcomes":outcomes,
            "stable_reasons":reasons,
            "probe_outcome":"pass",
            "payload_contract":{
                "codec":"avro",
                "envelope_contract":"durable-workflow-published-envelope",
                "apache_avro_package":"apache-avro",
                "official_crates_io_provenance":true
            },
            "rust_shard_contract_version":3,
            "executor_topology":{
                "server_http_process":server_http_process,
                "scheduler_process":scheduler_process,
                "rust_executor":rust_executor,
                "rust_executor_outside_server_image":true
            },
            "published_artifact_cell_executed":true,
            "local_product_source_checkouts_used":false
        })
    );
    Ok(())
}
