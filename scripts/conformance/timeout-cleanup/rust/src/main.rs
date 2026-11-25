//! Installed timeout-cleanup worker (Server #1201), the Rust twin of
//! worker.php. It runs the published Rust SDK (run.sh pins the crate), never
//! product source, on the task queue in TC_QUEUE. TC_COOPERATIVE=0 registers
//! an ordinary worker, which does not advertise timeout_cleanup.
//! TC_DIE_ON_CLEANUP=1 kills this process with SIGKILL when a cleanup turn
//! reaches workflow code, so a replacement worker must finish it. Each
//! workflow type is one cleanup behavior; vectors.php names the vectors.

use std::{fs::OpenOptions, io::Write, sync::Arc, time::Duration};

use durable_workflow::{
    json, ActivityError, Client, CooperativeCancellationRequested, Error, Result, Value, Worker,
    WorkerProtocolVersion, WorkflowContext,
};

const WORK: Duration = Duration::from_secs(3600);

fn env(name: &str, default: &str) -> String {
    std::env::var(name)
        .ok()
        .filter(|value| !value.is_empty())
        .unwrap_or_else(|| default.to_string())
}

fn vector_of(input: &Value) -> String {
    input
        .get(0)
        .and_then(Value::as_str)
        .unwrap_or_default()
        .to_string()
}

/// What workflow code saw when the interruption reached it, written by an
/// activity so the driver reads it without decoding payloads.
async fn observe(
    ctx: &WorkflowContext,
    vector: &str,
    error: &CooperativeCancellationRequested,
    die: bool,
) -> Result<()> {
    if die {
        // SAFETY: signals this process; nothing else is touched.
        unsafe {
            libc::kill(libc::getpid(), libc::SIGKILL);
        }
    }
    let timeout = error.request.cause().is_timeout();
    // The recorded context cause, as PHP's `$error->context?->cause`:
    // "timeout", "cancellation", or null when absent.
    let cause = error
        .request
        .context
        .as_ref()
        .and_then(|context| context.to_value().get("cause").cloned())
        .unwrap_or(Value::Null);
    let class = if timeout {
        "durable_workflow::CooperativeCancellationRequested(CancellationCause::Timeout)"
    } else {
        "durable_workflow::CooperativeCancellationRequested(CancellationCause::Requested)"
    };
    let _shield = ctx.cancellation_shield()?;
    ctx.activity(
        "tc.observe",
        json!([{
            "vector": vector,
            "class": class,
            "timeout_subtype": timeout,
            "cause": cause,
            "request_id": error.request.request_id,
        }]),
    )
    .await?;
    Ok(())
}

#[derive(Clone, Copy)]
enum Mode {
    Return,
    Rethrow,
    Fail,
    Linger,
}

async fn cleanup(ctx: WorkflowContext, input: Value, mode: Mode, die: bool) -> Result<Value> {
    let vector = vector_of(&input);
    // Work waits on a long timer, so only the engine's deadline interrupts it.
    let interruption = match ctx.sleep(WORK).await {
        Ok(_) => return Ok(json!("work finished")),
        Err(Error::CooperativeCancellationRequested(interruption)) => interruption,
        Err(other) => return Err(other),
    };
    observe(&ctx, &vector, &interruption, die).await?;
    match mode {
        Mode::Return => Ok(json!("compensated")),
        // The delivered interruption, unchanged.
        Mode::Rethrow => Err(Error::CooperativeCancellationRequested(interruption)),
        Mode::Fail => Err(Error::ActivityError(ActivityError::new(
            "RuntimeException",
            "compensation failed",
        ))),
        Mode::Linger => {
            // Cleanup that outlives every deadline.
            let _shield = ctx.cancellation_shield()?;
            ctx.sleep(WORK).await?;
            Ok(json!("lingered"))
        }
    }
}

/// Continues as new once, then waits for cleanup and compensates.
async fn continuing(ctx: WorkflowContext, input: Value, die: bool) -> Result<Value> {
    let vector = vector_of(&input);
    let generation = input.get(1).and_then(Value::as_u64).unwrap_or(0);
    if generation == 0 {
        ctx.sleep(Duration::from_secs(2)).await?;
        return ctx.continue_as_new(json!([vector, 1]));
    }
    match ctx.sleep(WORK).await {
        Ok(_) => Ok(json!("work finished")),
        Err(Error::CooperativeCancellationRequested(interruption)) => {
            observe(&ctx, &vector, &interruption, die).await?;
            Ok(json!("compensated"))
        }
        Err(other) => Err(other),
    }
}

/// No opt-in: workflow code must never run again after the hard deadline.
async fn plain(ctx: WorkflowContext, input: Value, die: bool) -> Result<Value> {
    let vector = vector_of(&input);
    match ctx.sleep(WORK).await {
        Ok(_) => {}
        Err(Error::CooperativeCancellationRequested(interruption)) => {
            observe(&ctx, &vector, &interruption, die).await?;
        }
        Err(other) => return Err(other),
    }
    Ok(json!("unexpected"))
}

fn append_observation(path: &str, args: Value) -> Result<Value> {
    let seen = match args {
        Value::Array(mut items) if !items.is_empty() => items.swap_remove(0),
        other => other,
    };
    let Value::Object(mut line) = seen else {
        return Err(Error::ActivityError(
            ActivityError::new("InvalidArgument", "tc.observe expects an object").non_retryable(true),
        ));
    };
    line.insert(
        "at".into(),
        json!(chrono::Utc::now().to_rfc3339_opts(chrono::SecondsFormat::Secs, false)),
    );
    let mut text = Value::Object(line).to_string();
    text.push('\n');
    let io = |error: std::io::Error| Error::ActivityError(ActivityError::new("IOException", error.to_string()));
    let mut file = OpenOptions::new().create(true).append(true).open(path).map_err(io)?;
    // O_APPEND and one write per line, so concurrent workers never interleave.
    file.write_all(text.as_bytes()).map_err(io)?;
    Ok(json!(true))
}

#[tokio::main]
async fn main() -> Result<()> {
    let queue = env("TC_QUEUE", "timeout-cleanup");
    let cooperative = std::env::var("TC_COOPERATIVE").ok().as_deref() != Some("0");
    let die = std::env::var("TC_DIE_ON_CLEANUP").ok().as_deref() == Some("1");
    let observations: Arc<str> = env("TC_OBSERVATIONS", "/evidence/observations.jsonl").into();

    let client = Client::builder(env("TC_SERVER_URL", "http://server:8080"))
        .worker_token(Some(env("TC_TOKEN", "timeout-cleanup-token")))
        .namespace("default")
        .worker_protocol_version(WorkerProtocolVersion::V120)
        .build()?;
    let mut worker = Worker::new(client, queue.clone())
        .cooperative_cancellation(cooperative)
        .poll_timeout(Duration::from_secs(2));
    if let Ok(id) = std::env::var("TC_WORKER_ID") {
        if !id.is_empty() {
            worker = worker.worker_id(id);
        }
    }

    for (name, mode) in [
        ("tc.return", Mode::Return),
        ("tc.rethrow", Mode::Rethrow),
        ("tc.fail", Mode::Fail),
        ("tc.linger", Mode::Linger),
    ] {
        worker.register_workflow(name, move |ctx, input| cleanup(ctx, input, mode, die));
    }
    worker.register_workflow("tc.continue", move |ctx, input| continuing(ctx, input, die));
    worker.register_workflow("tc.plain", move |ctx, input| plain(ctx, input, die));
    worker.register_activity("tc.observe", move |_ctx, args| {
        let path = Arc::clone(&observations);
        async move { append_observation(&path, args) }
    });

    eprintln!("tc-worker: queue {queue}, cooperative {cooperative}, die on cleanup {die}");
    // Runs until killed; a returned worker-loop error is logged and retried.
    loop {
        if let Err(error) = worker.run().await {
            eprintln!("tc-worker: worker loop ended: {error}; retrying");
            tokio::time::sleep(Duration::from_secs(1)).await;
        }
    }
}
