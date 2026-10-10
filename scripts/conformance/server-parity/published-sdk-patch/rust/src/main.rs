use std::sync::{Arc, Mutex};
use std::time::Duration;

use durable_workflow::{Client, Error, Result, Worker};
use serde_json::{json, Value};

#[tokio::main]
async fn main() -> Result<()> {
    let arguments: Vec<String> = std::env::args().skip(1).collect();
    if arguments.len() != 4 {
        return Err(Error::WorkerLoop(
            "Expected URL, workflow ID, run ID and patch ID".into(),
        ));
    }
    let client = Client::builder(&arguments[0])
        .namespace("default")
        .token(std::env::var("DW_PARITY_TOKEN").ok())
        .build()?;
    let decisions = Arc::new(Mutex::new(Vec::<Vec<bool>>::new()));
    let recorded = Arc::clone(&decisions);
    let change_id = arguments[3].clone();
    let mut worker = Worker::new(client.clone(), "server-parity-v1")
        .worker_id(format!("{}:replacement", arguments[1]))
        .poll_timeout(Duration::from_secs(1));
    worker.register_typed_workflow("parity.v1.one_activity", move |ctx, value: Value| {
        let recorded = Arc::clone(&recorded);
        let change_id = change_id.clone();
        async move {
            let selected = vec![ctx.patched(change_id.clone())?, ctx.patched(change_id)?];
            recorded.lock().unwrap().push(selected.clone());
            if selected != [false, false] {
                return Err(Error::WorkerLoop("Legacy patch decisions changed".into()));
            }
            ctx.activity_typed::<Value, Value>("parity.v1.echo_activity", value)
                .await
        }
    });
    worker.register_typed_activity("parity.v1.echo_activity", |_ctx, value: Value| async move {
        Ok(value)
    });
    let watcher = client.clone();
    let workflow_id = arguments[1].clone();
    let run_id = arguments[2].clone();
    worker
        .run_until(async move {
            let _ = tokio::time::timeout(Duration::from_secs(25), async move {
                loop {
                    if watcher
                        .describe_workflow_run(&workflow_id, &run_id)
                        .await
                        .map(|run| run.status.as_deref() == Some("completed"))
                        .unwrap_or(false)
                    {
                        break;
                    }
                    tokio::time::sleep(Duration::from_millis(50)).await;
                }
            })
            .await;
        })
        .await?;
    let run = client
        .describe_workflow_run(&arguments[1], &arguments[2])
        .await?;
    if run.status.as_deref() != Some("completed") {
        return Err(Error::WorkerLoop(
            "Original run did not complete within the replay budget".into(),
        ));
    }
    println!(
        "{}",
        json!({"pid": std::process::id(), "language": "rust", "sdk_version": "3.4.2",
        "decisions": *decisions.lock().unwrap(), "worker_finished": true})
    );
    Ok(())
}
