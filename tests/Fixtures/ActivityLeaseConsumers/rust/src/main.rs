use std::{env, fs::OpenOptions, io::Write, time::Duration};

use durable_workflow::{ActivityOptions, ActivityRetryPolicy, Client, Worker};
use serde_json::{json, Value};

fn record(value: Value) -> durable_workflow::Result<()> {
    let mut file = OpenOptions::new()
        .create(true)
        .append(true)
        .open("/state/invocations.jsonl")
        .map_err(|error| durable_workflow::Error::WorkerLoop(error.to_string()))?;
    writeln!(file, "{value}")
        .map_err(|error| durable_workflow::Error::WorkerLoop(error.to_string()))
}

// The callback deliberately blocks one executor thread. The other services
// ordinary asynchronous transport and the SDK's runtime-lease maintainer.
#[tokio::main(worker_threads = 2)]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    std::fs::write("/state/worker.pid", std::process::id().to_string())?;
    let client = Client::builder(env::var("SERVER_URL")?)
        .namespace(env::var("NAMESPACE").unwrap_or_else(|_| "default".to_owned()))
        .token(Some("synthetic".to_owned()))
        .build()?;
    let mut worker = Worker::new(client, "lease-qualification-rust")
        .worker_id(env::var("WORKER_ID")?)
        .poll_timeout(Duration::from_secs(1))
        .heartbeat_interval(Duration::from_secs(1));
    worker.register_typed_activity(
        "qualification.blocking-rust",
        |context, (seconds, mode): (u64, String)| async move {
            record(json!({"mode": mode, "attempt": context.attempt_number,
                "task_id": context.task_id, "activity_attempt_id": context.activity_attempt_id,
                "pid": std::process::id()}))?;
            if mode == "long" || context.attempt_number == 1 {
                std::thread::sleep(Duration::from_secs(seconds));
            }
            Ok(vec![json!(9007199254740993_i64), Value::Null, json!("λ")])
        },
    );
    worker.register_typed_workflow(
        "qualification.runtime-lease-rust",
        |context, input: (u64, String)| async move {
            let result: Vec<Value> = context
                .activity_typed_with_options(
                    "qualification.blocking-rust",
                    ActivityOptions::new().retry_policy(
                        ActivityRetryPolicy::new(2).backoff_intervals([Duration::ZERO]),
                    ),
                    input,
                )
                .await?;
            Ok(result)
        },
    );
    let mut terminate = tokio::signal::unix::signal(tokio::signal::unix::SignalKind::terminate())?;
    worker.run_until(async move { terminate.recv().await; }).await?;
    Ok(())
}
