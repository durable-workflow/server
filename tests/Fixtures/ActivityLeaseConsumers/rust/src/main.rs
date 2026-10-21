use durable_workflow::{
    json, ActivityOptions, ActivityRetryPolicy, Client, Error, Worker, WorkflowResultOptions,
    WorkflowStartOptions,
};
use serde_json::Value;
use std::fs::OpenOptions;
use std::io::{Error as IoError, ErrorKind, Write};
use std::path::PathBuf;
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};
use tokio::signal::unix::{signal, SignalKind};

const QUEUE: &str = "lease-qualification-rust";
const WORKFLOW: &str = "qualification.runtime-lease-rust";
const ACTIVITY: &str = "qualification.blocking-rust";

fn state() -> PathBuf {
    std::env::var("STATE_DIR").unwrap_or_else(|_| "/state".into()).into()
}

fn expected() -> Value {
    json!([9007199254740993_i64, null, "λ"])
}

fn append_invocation(record: &Value) -> durable_workflow::Result<()> {
    let mut output = OpenOptions::new()
        .append(true)
        .create(true)
        .open(state().join("invocations.jsonl"))
        .map_err(|error| Error::WorkerLoop(format!("fixture invocation file: {error}")))?;
    writeln!(output, "{record}")
        .map_err(|error| Error::WorkerLoop(format!("fixture invocation write: {error}")))
}

async fn run_worker(client: Client) -> Result<(), Box<dyn std::error::Error>> {
    let mut worker = Worker::new(client, QUEUE)
        .worker_id(std::env::var("WORKER_ID")?)
        .poll_timeout(Duration::from_secs(1));
    worker.register_workflow(WORKFLOW, |ctx, args| async move {
        ctx.activity_with_options(
            ACTIVITY,
            ActivityOptions::new().retry_policy(
                ActivityRetryPolicy::new(2).backoff_intervals([Duration::ZERO]),
            ),
            args,
        )
        .await
    });
    worker.register_activity(ACTIVITY, |ctx, args| async move {
        let seconds = args[0]
            .as_u64()
            .ok_or_else(|| Error::WorkerLoop("fixture seconds must be an integer".into()))?;
        let mode = args[1]
            .as_str()
            .ok_or_else(|| Error::WorkerLoop("fixture mode must be a string".into()))?;
        append_invocation(&json!({
            "mode": mode, "pid": std::process::id(),
            "unix_ms": SystemTime::now().duration_since(UNIX_EPOCH).unwrap().as_millis(),
            "task_id": ctx.task_id, "activity_attempt_id": ctx.activity_attempt_id,
            "attempt": ctx.attempt_number,
        }))?;
        if mode == "long" || ctx.attempt_number == 1 {
            tokio::task::spawn_blocking(move || std::thread::sleep(Duration::from_secs(seconds)))
                .await
                .map_err(|error| Error::WorkerLoop(format!("fixture callback thread: {error}")))?;
        }
        Ok(expected())
    });
    std::fs::write(state().join("worker.pid"), std::process::id().to_string())?;
    let mut terminate = signal(SignalKind::terminate())?;
    let mut interrupt = signal(SignalKind::interrupt())?;
    worker
        .run_until(async move {
            tokio::select! {
                _ = terminate.recv() => {},
                _ = interrupt.recv() => {},
            }
        })
        .await?;
    Ok(())
}

#[tokio::main(flavor = "multi_thread", worker_threads = 2)]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let role = std::env::args().nth(1).unwrap_or_default();
    if !["worker", "long", "loss"].contains(&role.as_str()) {
        return Err(IoError::new(ErrorKind::InvalidInput, "expected worker, long or loss").into());
    }
    let client = Client::builder(std::env::var("SERVER_URL")?)
        .token(Some("synthetic".into()))
        .namespace(std::env::var("NAMESPACE")?)
        .build()?;
    let discovery = client.cluster_info().await?;
    if discovery["worker_protocol"]["server_capabilities"]["activity_lease_renewal"] != true {
        return Err(IoError::new(ErrorKind::Unsupported, "runtime lease renewal is not advertised").into());
    }
    if role == "worker" {
        return run_worker(client).await;
    }
    let started = Instant::now();
    let workflow_id = std::env::var("WORKFLOW_ID")?;
    let handle = client
        .start_workflow_with_options(
            WORKFLOW,
            QUEUE,
            &workflow_id,
            WorkflowStartOptions::new().execution_timeout_seconds(420).run_timeout_seconds(420),
            json!([360, role]),
        )
        .await?;
    let description = handle.describe().await?;
    std::fs::write(state().join("started.json"), serde_json::to_vec(&json!({
        "workflow_id": workflow_id, "run_id": description.run_id, "mode": role,
    }))?)?;
    let result = handle.result(WorkflowResultOptions {
        timeout: Duration::from_secs(if role == "long" { 420 } else { 30 }),
        poll_interval: Duration::from_millis(100),
    }).await?;
    let elapsed = started.elapsed().as_secs_f64();
    if result != expected() || (role == "long" && elapsed < 360.0) {
        return Err(IoError::other("original real-duration/typed-result control failed").into());
    }
    println!("{}", json!({"mode": role, "workflow_id": workflow_id,
        "run_id": description.run_id, "elapsed_seconds": elapsed, "result": result}));
    Ok(())
}
