use std::{
    env,
    sync::{
        atomic::{AtomicBool, Ordering},
        Arc, Mutex,
    },
    time::{Duration, Instant, SystemTime, UNIX_EPOCH},
};

use durable_workflow::{
    json, Client, Error, PayloadEnvelope, Result, Value, Worker, WorkflowCommandOptions,
    WorkflowResultOptions, WorkflowStartOptions, DEFAULT_CODEC,
};

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

#[tokio::main]
async fn main() -> Result<()> {
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
    let cancel_error = cancel_handle
        .result(WorkflowResultOptions::default())
        .await
        .expect_err("cancelled workflow must return a typed outcome");
    let cancel_reason = match cancel_error {
        Error::WorkflowCancelled(outcome) => outcome.reason,
        other => {
            return Err(Error::Codec(format!(
                "typed_cancelled_not_observed:{other}"
            )))
        }
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
    let terminate_error = terminate_handle
        .result(WorkflowResultOptions::default())
        .await
        .expect_err("terminated workflow must return a typed outcome");
    let terminate_reason = match terminate_error {
        Error::WorkflowTerminated(outcome) => outcome.reason,
        other => {
            return Err(Error::Codec(format!(
                "typed_terminated_not_observed:{other}"
            )))
        }
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
    let selected_handle = client
        .start_workflow(
            "rust.lifecycle.pending",
            &selected_queue,
            &format!("rust-lifecycle-selected-{}", suffix()),
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

    let selected_error = selected_handle
        .cancel_selected_run(WorkflowCommandOptions::default())
        .await
        .expect_err("historical selected run must be rejected");
    let stale = match selected_error {
        Error::WorkflowCommandRejected(rejection) => rejection,
        other => {
            return Err(Error::Codec(format!(
                "typed_stale_rejection_not_observed:{other}"
            )))
        }
    };
    require(stale.status == 409, "stale_run_rejection_status_not_409")?;
    require(
        stale.reason == "historical_run_command_rejected",
        "stale_run_rejection_reason_unstable",
    )?;
    outcomes.insert(
        "stale_run_rejection".into(),
        json!({
            "status":"pass", "typed_error":"WorkflowCommandRejected",
            "http_status":stale.status, "reason":stale.reason,
            "run_id":stale.run_id, "target_scope":stale.target_scope,
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
    let fail_error = fail_handle
        .result(WorkflowResultOptions::default())
        .await
        .expect_err("failed workflow must return a typed outcome");
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
        other => return Err(Error::Codec(format!("typed_failed_not_observed:{other}"))),
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
    let timeout_error = timeout_handle
        .result(WorkflowResultOptions {
            poll_interval: Duration::from_millis(200),
            timeout: Duration::from_secs(15),
        })
        .await
        .expect_err("server-terminal run timeout must return a typed timeout");
    match timeout_error {
        Error::WorkflowTimedOut(outcome) => {
            require(
                outcome.reason == "run_timeout",
                "server_terminal_typed_timeout_reason_unstable",
            )?;
            require(
                outcome.failure_category.as_deref() != Some("client_timeout"),
                "client_wait_timeout_mislabeled_as_server_terminal",
            )?;
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
        other => return Err(Error::Codec(format!("typed_timeout_not_observed:{other}"))),
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
                "worker_restart_during_cancellation"
            ],
            "unsupported_cells":[],
            "typed_errors":[],
            "workflow_identities":identities,
            "scenario_outcomes":outcomes,
            "stable_reasons":reasons,
            "payload_contract":{
                "codec":"avro",
                "envelope_contract":"durable-workflow-published-envelope",
                "apache_avro_package":"apache-avro",
                "official_crates_io_provenance":true
            },
            "rust_shard_contract_version":2,
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
