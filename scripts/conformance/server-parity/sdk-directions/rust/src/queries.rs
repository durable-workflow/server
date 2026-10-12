use std::sync::{
    atomic::{AtomicUsize, Ordering},
    Arc,
};
use std::time::{Duration, Instant};

use durable_workflow::{Client, ConditionWaitOptions, Error, Result, Worker, SDK_VERSION};
use serde_json::{json, Value};

pub async fn run(arguments: &[String], fixture: Value) -> Result<()> {
    let [phase, url, workflow_id, selected_run, _, remaining] = arguments else {
        return Err(Error::WorkerLoop("Invalid SDK query arguments".into()));
    };
    let phases = fixture["sdk_query_direction"]["phase_order"]
        .as_array()
        .ok_or_else(|| Error::WorkerLoop("No SDK query phases".into()))?;
    if !phases.iter().any(|value| value.as_str() == Some(phase.as_str())) {
        return Err(Error::WorkerLoop("Unknown SDK query phase".into()));
    }
    let seconds: f64 = remaining
        .parse()
        .map_err(|_| Error::WorkerLoop("Invalid SDK query budget".into()))?;
    if !seconds.is_finite() || seconds <= 0.0 {
        return Err(Error::WorkerLoop("SDK query deadline expired".into()));
    }
    let deadline = Instant::now() + Duration::from_secs_f64(seconds);
    let client = Client::builder(url)
        .namespace("default")
        .token(std::env::var("DW_PARITY_TOKEN").ok())
        .build()?;
    let mut result = json!({"pid": std::process::id(), "language": "rust",
        "sdk_version": SDK_VERSION.trim_start_matches("durable-workflow-rust/"),
        "phase": phase, "workflow_id": workflow_id, "run_id": selected_run});
    if matches!(phase.as_str(), "signal-1" | "signal-2") {
        result["client_result"] = client
            .signal_workflow(workflow_id, "payload", json!([fixture["signal_value"]]))
            .await?;
        result["signal_sent"] = json!(true);
        result["sender_finished"] = json!(true);
        println!("{result}");
        return Ok(());
    }
    if phase.starts_with("query-") {
        result["client_result"] = client
            .query_workflow(workflow_id, "state", json!([]))
            .await?;
        result["query_sent"] = json!(true);
        result["client_finished"] = json!(true);
        println!("{result}");
        return Ok(());
    }
    let workflow_type = fixture["workflow_type"]
        .as_str()
        .ok_or_else(|| Error::WorkerLoop("No SDK query workflow type".into()))?;
    let queue = fixture["sdk_query_direction"]["task_queue"]
        .as_str()
        .ok_or_else(|| Error::WorkerLoop("No SDK query task queue".into()))?;
    let worker_id = format!("{workflow_id}:{phase}");
    let workflow_invocations = Arc::new(AtomicUsize::new(0));
    let query_invocations = Arc::new(AtomicUsize::new(0));
    let mut worker = Worker::new(client.clone(), queue)
        .worker_id(&worker_id)
        .poll_timeout(Duration::from_secs(1));
    let invocations = Arc::clone(&workflow_invocations);
    worker.register_workflow(workflow_type, move |ctx, arguments: Value| {
        invocations.fetch_add(1, Ordering::SeqCst);
        async move {
            let target = arguments
                .as_array()
                .and_then(|values| values.get(1))
                .and_then(Value::as_u64)
                .and_then(|value| usize::try_from(value).ok())
                .ok_or_else(|| Error::WorkerLoop("No query-author signal target".into()))?;
            for index in 0..target {
                let predicate_ctx = ctx.clone();
                ctx.wait_condition(
                    ConditionWaitOptions::new(
                        format!("payload:{index}"),
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
                .and_then(|values| values.first())
                .cloned()
                .ok_or_else(|| Error::WorkerLoop("No query-author final signal".into()))
        }
    });
    worker.declare_workflow_signals(workflow_type, &["payload"])?;
    let invocations = Arc::clone(&query_invocations);
    worker.register_query(workflow_type, "state", move |ctx, arguments: Value| {
        invocations.fetch_add(1, Ordering::SeqCst);
        async move {
            if arguments != json!([]) {
                return Err(Error::WorkerLoop(
                    "State query expects zero arguments".into(),
                ));
            }
            let signals = ctx.signals("payload");
            Ok(json!({"received": signals.len(),
                "last": signals.last().and_then(|values| values.first()).cloned()}))
        }
    });
    worker.register().await?;
    let execution = async {
        loop {
            if Instant::now() >= deadline {
                return Err(Error::WorkerLoop(
                    "SDK query author exceeded fixture budget".into(),
                ));
            }
            // run_once returns after the normal query completion HTTP operation.
            worker.run_once().await?;
            if query_invocations.load(Ordering::SeqCst) != 1 {
                continue;
            }
            let run = client.describe_workflow(workflow_id).await?;
            let expected = if phase == "replacement" {
                "completed"
            } else {
                "waiting"
            };
            if run.status.as_deref() == Some(expected) {
                return run
                    .run_id
                    .ok_or_else(|| Error::WorkerLoop("No original query run ID".into()));
            }
        }
    }
    .await;
    let shutdown = client.deregister_worker_registration(&worker_id).await;
    let run_id = execution?;
    shutdown?;
    result["run_id"] = json!(run_id);
    result["worker_id"] = json!(worker_id);
    result["task_queue"] = json!(queue);
    result["workflow_invocations"] = json!(workflow_invocations.load(Ordering::SeqCst));
    result["query_invocations"] = json!(query_invocations.load(Ordering::SeqCst));
    result["activity_invocations"] = json!(0);
    result["worker_finished"] = json!(true);
    println!("{result}");
    Ok(())
}
