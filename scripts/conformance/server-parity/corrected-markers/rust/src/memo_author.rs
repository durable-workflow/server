//! Published Rust author and cold reader for the unchanged memo recipe.
use std::{fs, time::Duration};

use durable_workflow::{
    ActivityOptions, ActivityRetryPolicy, Client, Error, Result, Worker, SDK_VERSION,
};
use serde_json::{json, Value};

pub async fn run(args: &[String]) -> Result<()> {
    if args.len() != 5 || !["original", "replacement"].contains(&args[1].as_str()) {
        return Err(Error::WorkerLoop(
            "Expected memo URL, phase, workflow ID, run ID and fixture".into(),
        ));
    }
    let fixture: Value = serde_json::from_str(
        &fs::read_to_string(&args[4]).map_err(|error| Error::WorkerLoop(error.to_string()))?,
    )
    .map_err(|error| Error::WorkerLoop(error.to_string()))?;
    let spec = fixture["memo_replay"].clone();
    let budget = spec["phase_budget_seconds"]
        .as_u64()
        .ok_or_else(|| Error::WorkerLoop("Missing original phase budget".into()))?;
    let workflow_type = fixture["workflow_type"]
        .as_str()
        .ok_or_else(|| Error::WorkerLoop("Missing workflow type".into()))?;
    let client = Client::builder(&args[0])
        .namespace("default")
        .token(std::env::var("DW_PARITY_TOKEN").ok())
        .timeout(Duration::from_secs(5))
        .build()?;
    let worker_id = format!("{}:memo:rust:{}", args[2], args[1]);
    let queue = format!("{}-workflow", args[2]);
    let activity_queue = format!("{}-activity", args[2]);
    let mut worker = Worker::new(client.clone(), &queue)
        .worker_id(&worker_id)
        .max_concurrent_workflow_tasks(1)
        .max_concurrent_activity_tasks(1)
        .poll_timeout(Duration::from_secs(1));
    worker.register_typed_workflow(workflow_type, move |ctx, input: Value| {
        let spec = spec.clone();
        let activity_queue = activity_queue.clone();
        async move {
            let updates = spec["updates"]
                .as_array()
                .ok_or_else(|| Error::WorkerLoop("Missing memo updates".into()))?;
            if let Some(entries) = updates.first() {
                ctx.upsert_memo(entries)?;
            }
            let policy = &spec["activity_options"];
            let retry = &policy["retry_policy"];
            let options = ActivityOptions::new()
                .task_queue(activity_queue)
                .retry_policy(
                    ActivityRetryPolicy::new(retry["max_attempts"].as_u64().unwrap() as u32)
                        .backoff_intervals(
                            retry["backoff_seconds"]
                                .as_array()
                                .unwrap()
                                .iter()
                                .map(|value| Duration::from_secs(value.as_u64().unwrap())),
                        ),
                )
                .start_to_close_timeout(Duration::from_secs(
                    policy["start_to_close_timeout"].as_u64().unwrap(),
                ))
                .schedule_to_close_timeout(Duration::from_secs(
                    policy["schedule_to_close_timeout"].as_u64().unwrap(),
                ));
            let result = ctx
                .activity_typed_with_options::<Value, Value>("parity.v1.memo_echo", options, input)
                .await?;
            if let Some(entries) = updates.get(1) {
                ctx.upsert_memo(entries)?;
            }
            Ok(result)
        }
    });
    let outcome = tokio::time::timeout(Duration::from_secs(budget), async {
        worker.register().await?;
        loop {
            let processed = worker.run_once().await?;
            let run = client.describe_workflow_run(&args[2], &args[3]).await?;
            if processed > 0
                && (args[1] == "original" || run.status.as_deref() == Some("completed"))
            {
                return Ok::<(), Error>(());
            }
            tokio::time::sleep(Duration::from_millis(25)).await;
        }
    })
    .await
    .map_err(|_| Error::WorkerLoop("Original memo phase observation expired".into()))?;
    let retired = client.deregister_worker_registration(&worker_id).await;
    outcome?;
    retired?;
    println!(
        "{}",
        json!({"phase":args[1],"pid":std::process::id(),
        "workflow_id":args[2],"run_id":args[3],"language":"rust","sdk_version":SDK_VERSION,
        "activity_invocations":0,"worker_finished":true,"shutdown":"public_deregister"})
    );
    Ok(())
}
