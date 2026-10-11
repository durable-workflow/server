use std::sync::{
    atomic::{AtomicUsize, Ordering},
    Arc,
};
use std::time::{Duration, Instant};

use durable_workflow::{
    ActivityOptions, ChildWorkflowOptions, Client, Error, Result, Worker, SDK_VERSION,
};
use serde_json::{json, Value};

#[tokio::main]
async fn main() -> Result<()> {
    let arguments: Vec<String> = std::env::args().skip(1).collect();
    if arguments.len() != 6 {
        return Err(Error::WorkerLoop(
            "Expected phase, URL, workflow ID, run ID, fixture and remaining seconds".into(),
        ));
    }
    let [phase, url, workflow_id, selected_run, fixture_path, remaining] = arguments.as_slice()
    else {
        unreachable!()
    };
    let fixture: Value = serde_json::from_str(
        &std::fs::read_to_string(fixture_path)
            .map_err(|error| Error::WorkerLoop(error.to_string()))?,
    )
    .map_err(|error| Error::WorkerLoop(error.to_string()))?;
    let child_direction = fixture.get("sdk_child_direction").is_some();
    let phases = if child_direction {
        ["original", "child", "replacement"]
    } else {
        ["original", "activity", "replacement"]
    };
    if !phases.contains(&phase.as_str()) {
        return Err(Error::WorkerLoop("Unknown SDK direction phase".into()));
    }
    let queue = if child_direction {
        if phase == "child" {
            "server-parity-sdk-child"
        } else {
            "server-parity-sdk-parent"
        }
    } else if phase == "activity" {
        "server-parity-sdk-activity"
    } else {
        "server-parity-sdk-workflow"
    };
    let seconds: f64 = remaining
        .parse()
        .map_err(|_| Error::WorkerLoop("Invalid phase budget".into()))?;
    if !seconds.is_finite() || seconds <= 0.0 {
        return Err(Error::WorkerLoop("SDK direction deadline expired".into()));
    }
    let deadline = Instant::now() + Duration::from_secs_f64(seconds);
    let client = Client::builder(url)
        .namespace("default")
        .token(std::env::var("DW_PARITY_TOKEN").ok())
        .build()?;
    let run_id = if phase == "original" {
        client
            .start_workflow(
                fixture["workflow_type"]
                    .as_str()
                    .ok_or_else(|| Error::WorkerLoop("Missing workflow type".into()))?,
                queue,
                workflow_id,
                json!([fixture["input"]]),
            )
            .await?
            .run_id
            .ok_or_else(|| Error::WorkerLoop("Published client returned no run ID".into()))?
    } else {
        selected_run.clone()
    };
    let worker_id = format!("{workflow_id}:{phase}");
    let workflow_invocations = Arc::new(AtomicUsize::new(0));
    let activity_invocations = Arc::new(AtomicUsize::new(0));
    let mut worker = Worker::new(client.clone(), queue)
        .worker_id(&worker_id)
        .poll_timeout(Duration::from_secs(1));
    if child_direction && phase == "child" {
        let invocations = Arc::clone(&workflow_invocations);
        worker.register_typed_workflow("parity.v1.echo_child", move |_ctx, value: Value| {
            invocations.fetch_add(1, Ordering::SeqCst);
            async move { Ok(value) }
        });
    } else if child_direction {
        let invocations = Arc::clone(&workflow_invocations);
        worker.register_typed_workflow("parity.v1.one_child", move |ctx, value: Value| {
            invocations.fetch_add(1, Ordering::SeqCst);
            async move {
                let payloads = value["payloads"]
                    .as_array()
                    .ok_or_else(|| Error::WorkerLoop("Missing child payloads".into()))?;
                let mut results = Vec::new();
                for payload in payloads {
                    let child = ctx
                        .start_child_workflow(
                            "parity.v1.echo_child",
                            ChildWorkflowOptions::new("server-parity-sdk-child"),
                            json!([payload]),
                        )
                        .await?;
                    results.push(child.result);
                }
                Ok(json!({"child_results": results}))
            }
        });
    } else if phase == "activity" {
        let invocations = Arc::clone(&activity_invocations);
        worker.register_typed_activity("parity.v1.echo_activity", move |_ctx, value: Value| {
            invocations.fetch_add(1, Ordering::SeqCst);
            async move { Ok(value) }
        });
    } else {
        let invocations = Arc::clone(&workflow_invocations);
        worker.register_typed_workflow("parity.v1.one_activity", move |ctx, value: Value| {
            invocations.fetch_add(1, Ordering::SeqCst);
            async move {
                ctx.activity_typed_with_options::<Value, Value>(
                    "parity.v1.echo_activity",
                    ActivityOptions::new().task_queue("server-parity-sdk-activity"),
                    value,
                )
                .await
            }
        });
    }
    worker.register().await?;
    let execution = async {
        loop {
            if Instant::now() >= deadline {
                return Err(Error::WorkerLoop(
                    "SDK direction phase exceeded the remaining fixture budget".into(),
                ));
            }
            // Public SDK registration and task loop: no synthetic protocol or history.
            worker.run_once().await?;
            let run = client.describe_workflow_run(workflow_id, &run_id).await?;
            let reached = match phase.as_str() {
                "original" => run.status.as_deref() == Some("waiting"),
                "activity" => activity_invocations.load(Ordering::SeqCst) == 1,
                _ => run.status.as_deref() == Some("completed"),
            };
            if reached {
                return Ok(());
            }
        }
    }
    .await;
    let shutdown = client.deregister_worker_registration(&worker_id).await;
    execution?;
    shutdown?;
    println!(
        "{}",
        json!({"pid": std::process::id(), "language": "rust",
        "sdk_version": SDK_VERSION.trim_start_matches("durable-workflow-rust/"),
        "phase": phase, "run_id": run_id, "worker_id": worker_id, "task_queue": queue,
        "workflow_invocations": workflow_invocations.load(Ordering::SeqCst),
        "activity_invocations": activity_invocations.load(Ordering::SeqCst),
        "worker_finished": true})
    );
    Ok(())
}
