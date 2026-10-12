use std::sync::{
    atomic::{AtomicUsize, Ordering},
    Arc,
};
use std::time::{Duration, Instant};

use durable_workflow::{
    ActivityOptions, ChildWorkflowOptions, Client, ConditionWaitOptions, Error, Result, Worker,
    SDK_VERSION,
};
use serde_json::{json, Value};

mod queries;
mod updates;

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
    if fixture.get("sdk_update_direction").is_some() {
        return updates::run(&arguments, fixture).await;
    }
    if fixture.get("sdk_query_direction").is_some() {
        return queries::run(&arguments, fixture).await;
    }
    let child_direction = fixture.get("sdk_child_direction").is_some();
    let timer_author = fixture.get("sdk_timer_author").is_some();
    let signal_direction = fixture.get("sdk_signal_direction").is_some();
    let phases: &[&str] = if signal_direction {
        &["original", "signal-1", "resumed", "signal-2", "replacement"]
    } else if timer_author {
        &["original", "replacement"]
    } else if child_direction {
        &["original", "child", "replacement"]
    } else {
        &["original", "activity", "replacement"]
    };
    if !phases.contains(&phase.as_str()) {
        return Err(Error::WorkerLoop("Unknown SDK direction phase".into()));
    }
    let queue = if signal_direction {
        "server-parity-sdk-signals"
    } else if timer_author {
        "server-parity-sdk-timer"
    } else if child_direction {
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
    if signal_direction && matches!(phase.as_str(), "signal-1" | "signal-2") {
        let result = client
            .signal_workflow(workflow_id, "payload", json!([fixture["signal_value"]]))
            .await?;
        println!(
            "{}",
            json!({"pid": std::process::id(), "language": "rust",
                "sdk_version": SDK_VERSION.trim_start_matches("durable-workflow-rust/"),
                "phase": phase, "workflow_id": workflow_id, "run_id": selected_run,
                "signal_sent": true, "sender_finished": true, "client_result": result})
        );
        return Ok(());
    }
    let mut run_id = if phase == "original" && !signal_direction {
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
    if signal_direction {
        let invocations = Arc::clone(&workflow_invocations);
        worker.register_workflow(
            "parity.v1.sdk_signals",
            move |ctx, arguments: Value| {
                invocations.fetch_add(1, Ordering::SeqCst);
                async move {
                    // The ordinary positional API preserves this two-argument input.
                    // Published typed handlers accept one request value only.
                    let target = arguments
                        .as_array()
                        .and_then(|values| values.get(1))
                        .and_then(Value::as_u64)
                        .and_then(|value| usize::try_from(value).ok())
                        .ok_or_else(|| Error::WorkerLoop("No signal count argument".into()))?;
                    for index in 0..target {
                        let predicate_ctx = ctx.clone();
                        ctx.wait_condition(
                            ConditionWaitOptions::new(
                                format!("payload:{index}"),
                                // SHA256 of the two authored predicate identities.
                                if index == 0 {
                                    "sha256:b67992a5ee48704611dcf6a181c3ab94b69d0933c44525137210dd2467aec563"
                                } else {
                                    "sha256:474b0d8e3a116beb308c37e8084f7f16a402f8142468144d34ff135bc5ef0910"
                                },
                            ),
                            move || Ok(predicate_ctx.signals("payload")?.len() > index),
                        )
                        .await?;
                    }
                    ctx.signals("payload")?
                        .last()
                        .and_then(|arguments| arguments.first())
                        .cloned()
                        .ok_or_else(|| Error::WorkerLoop("No final decoded signal value".into()))
                }
            },
        );
        worker.declare_workflow_signals("parity.v1.sdk_signals", &["payload"])?;
    } else if timer_author {
        let invocations = Arc::clone(&workflow_invocations);
        worker.register_typed_workflow("parity.v1.sdk_timer", move |ctx, value: Value| {
            invocations.fetch_add(1, Ordering::SeqCst);
            async move {
                let milliseconds = value["sleep_ms"]
                    .as_u64()
                    .ok_or_else(|| Error::WorkerLoop("Missing timer duration".into()))?;
                ctx.sleep(Duration::from_millis(milliseconds)).await?;
                Ok(value)
            }
        });
    } else if child_direction && phase == "child" {
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
            if signal_direction && run_id.is_empty() {
                if workflow_invocations.load(Ordering::SeqCst) == 0 {
                    continue;
                }
                run_id = client
                    .describe_workflow(workflow_id)
                    .await?
                    .run_id
                    .ok_or_else(|| Error::WorkerLoop("No original claimed run ID".into()))?;
            }
            let run = client.describe_workflow_run(workflow_id, &run_id).await?;
            let reached = match phase.as_str() {
                "original" | "resumed" if signal_direction => {
                    workflow_invocations.load(Ordering::SeqCst) == 1
                        && run.status.as_deref() == Some("waiting")
                }
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
