use std::{env, process, time::Duration};

use durable_workflow::{
    json, Client, Error, Result, Worker, WorkflowInstance, DEFAULT_CODEC, SDK_VERSION,
};
use serde_json::Value;

#[derive(Clone, Default)]
struct CounterState {
    value: i64,
}

fn env_value(name: &str) -> String {
    env::var(name).unwrap_or_default()
}

fn client() -> Result<Client> {
    Client::builder(env_value("DURABLE_WORKFLOW_SERVER_URL"))
        .token(Some(env_value("DURABLE_WORKFLOW_TOKEN")))
        .namespace(env_value("DURABLE_WORKFLOW_NAMESPACE"))
        .timeout(Duration::from_secs(45))
        .build()
}

fn print_record(value: Value) {
    println!("{}", value);
}

fn print_error(operation: &str, error: &Error) {
    let mut record = json!({
        "ok": false,
        "operation": operation,
        "sdk_version": SDK_VERSION,
        "error": error.to_string(),
    });
    match error {
        Error::QueryFailed(failure) => {
            record["status_code"] = json!(failure.status);
            record["reason"] = json!(failure.reason);
            record["body"] = failure.body.clone();
        }
        Error::Protocol(failure) => {
            record["status_code"] = json!(failure.status);
            record["reason"] = json!(failure.reason);
            record["body"] = failure.body.clone();
        }
        Error::Http { status, body } => {
            record["status_code"] = json!(status.as_u16());
            let parsed: Value = serde_json::from_str(body).unwrap_or_else(|_| json!({"raw": body}));
            if let Some(reason) = parsed.get("reason") {
                record["reason"] = reason.clone();
            }
            if let Some(rejection_reason) = parsed.get("rejection_reason") {
                record["rejection_reason"] = rejection_reason.clone();
            }
            record["body"] = parsed;
        }
        _ => {}
    }
    print_record(record);
}

fn snapshot_value(ctx: &durable_workflow::QueryContext) -> i64 {
    let mut value = 0_i64;
    for signal in ctx.signal_events() {
        let amount = signal
            .arguments
            .first()
            .and_then(Value::as_i64)
            .unwrap_or_default();
        match signal.name.as_str() {
            "increment" => value += amount,
            "set" => value = amount,
            _ => {}
        }
    }
    value
}

async fn run_worker(model: &str) -> Result<()> {
    let task_queue = env_value("TASK_QUEUE");
    let worker_id = env_value("WORKER_ID");
    let mut worker = Worker::new(client()?, task_queue)
        .worker_id(worker_id)
        .poll_timeout(Duration::from_secs(3))
        .heartbeat_interval(Duration::from_secs(3));

    if model == "replay" {
        worker.register_replayed_workflow(
            "conformance.counter.rust.replayed",
            CounterState::default,
            |ctx, _input, state: WorkflowInstance<CounterState>| async move {
                loop {
                    let signal = ctx.wait_signal("increment").await?;
                    let amount = signal.first().and_then(Value::as_i64).unwrap_or_default();
                    if amount == 0 {
                        return Ok(json!(state.read(|current| current.value)?));
                    }
                    state.update(|current| current.value += amount)?;
                }
            },
        );
        worker.register_replayed_query::<CounterState, _, _>(
            "conformance.counter.rust.replayed",
            "current",
            |_ctx, state, _args| async move { Ok(json!(state.value)) },
        );
    } else {
        worker.register_workflow(
            "conformance.counter.rust.snapshot",
            |ctx, _input| async move {
                let _ = ctx.wait_signal("finish").await?;
                Ok(json!("finished"))
            },
        );
        worker.register_query(
            "conformance.counter.rust.snapshot",
            "current",
            |ctx, _args| async move { Ok(json!(snapshot_value(&ctx))) },
        );
        worker.register_workflow(
            "conformance.counter.rust.unavailable",
            |ctx, _input| async move {
                let _ = ctx.wait_signal("finish").await?;
                Ok(Value::Null)
            },
        );
    }

    print_record(json!({
        "event": "rust_worker_starting",
        "model": model,
        "sdk_version": SDK_VERSION,
        "process_id": process::id(),
    }));
    worker.run().await
}

async fn run_client(args: &[String]) -> Result<()> {
    let operation = args.get(2).map(String::as_str).unwrap_or_default();
    let workflow_id = args.get(3).map(String::as_str).unwrap_or_default();
    let name = args.get(4).map(String::as_str).unwrap_or_default();
    let input: Value = args
        .get(5)
        .map(String::as_str)
        .filter(|value| !value.is_empty())
        .map(serde_json::from_str)
        .transpose()?
        .unwrap_or_else(|| json!([]));
    let sdk = client()?;

    let result = match operation {
        "start" => sdk
            .start_workflow(name, &env_value("TASK_QUEUE"), workflow_id, input)
            .await
            .map(|handle| {
                json!({
                    "workflow_id": handle.workflow_id,
                    "run_id": handle.run_id,
                    "workflow_type": handle.workflow_type,
                })
            }),
        "signal" => sdk.signal_workflow(workflow_id, name, input).await,
        "query" => sdk.query_workflow(workflow_id, name, input).await,
        "describe" => sdk.describe_workflow(workflow_id).await.map(|description| {
            json!({
                "workflow_id": description.workflow_id,
                "run_id": description.run_id,
                "workflow_type": description.workflow_type,
                "status": description.status,
                "result": description.output,
            })
        }),
        _ => Err(Error::WorkerLoop(format!(
            "unsupported probe operation {operation:?}"
        ))),
    };

    match result {
        Ok(value) => {
            print_record(json!({
                "ok": true,
                "operation": operation,
                "operation_name": name,
                "workflow_id": workflow_id,
                "sdk_version": SDK_VERSION,
                "default_codec": DEFAULT_CODEC,
                "payload_codec": DEFAULT_CODEC,
                "result": value,
            }));
            Ok(())
        }
        Err(error) => {
            print_error(operation, &error);
            Err(error)
        }
    }
}

#[tokio::main]
async fn main() -> Result<()> {
    let args: Vec<String> = env::args().collect();
    match args.get(1).map(String::as_str) {
        Some("worker") => run_worker(args.get(2).map(String::as_str).unwrap_or("snapshot")).await,
        Some("client") => run_client(&args).await,
        _ => Err(Error::WorkerLoop(
            "usage: signals-queries-rust-probe <worker MODEL|client OP WORKFLOW_ID NAME INPUT_JSON>"
                .to_string(),
        )),
    }
}
