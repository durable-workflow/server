use std::time::{Duration, SystemTime, UNIX_EPOCH};

use durable_workflow::{json, Client, Result, Worker, WorkflowResultOptions};

#[tokio::main]
async fn main() -> Result<()> {
    let server_url = std::env::var("DURABLE_WORKFLOW_SERVER_URL")
        .unwrap_or_else(|_| "http://127.0.0.1:8080".to_string());
    let token = std::env::var("DURABLE_WORKFLOW_TOKEN").ok();
    let task_queue = std::env::var("TASK_QUEUE").unwrap_or_else(|_| "rust-workers".to_string());

    let client = Client::builder(server_url)
        .token(token)
        .namespace("default")
        .build()?;

    let mut worker = Worker::new(client.clone(), task_queue.clone())
        .worker_id(format!("rust-hello-{}", unique_suffix()))
        .poll_timeout(Duration::from_secs(5));

    worker.register_activity("rust.hello_activity", |ctx, args| async move {
        ctx.heartbeat(json!({"stage": "started"})).await?;
        let name = args
            .get(0)
            .and_then(|value| value.as_str())
            .unwrap_or("world");
        Ok(json!(format!("hello, {name}")))
    });

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

    worker.register().await?;

    let workflow_id = format!("rust-hello-{}", unique_suffix());
    let handle = client
        .start_workflow("rust.hello_workflow", &task_queue, &workflow_id, json!([]))
        .await?;

    client
        .signal_workflow(&handle.workflow_id, "start", json!(["Rust"]))
        .await?;

    let watcher = handle.clone();
    worker
        .run_until(async move {
            loop {
                if let Ok(description) = watcher.describe().await {
                    if description.is_terminal() {
                        break;
                    }
                }

                tokio::time::sleep(Duration::from_millis(500)).await;
            }
        })
        .await?;

    let result = handle
        .result(WorkflowResultOptions {
            poll_interval: Duration::from_millis(500),
            timeout: Duration::from_secs(30),
        })
        .await?;

    println!("{}", result);
    Ok(())
}

fn unique_suffix() -> u128 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap_or_default()
        .as_millis()
}
