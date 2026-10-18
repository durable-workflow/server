//! Normally installed public SDK author, independent activity, and cold reader.
//! Each invocation has one fixed 30-second observation budget. The recorder
//! owns persisted checkpoints, explicit repair, and original durable deadlines.
use std::sync::{Arc, Mutex};
use std::time::Duration;

use durable_workflow::{
    ActivityOptions, ActivityRetryPolicy, Client, Error, ParallelOperation, ParallelResult, Result,
    Worker, WorkflowStartOptions, SDK_VERSION,
};
use serde_json::{json, Value};

mod legacy_range;
mod memo_author;

const WORKFLOW: &str = "parity.v1.corrected_markers";
const ACTIVITY: &str = "parity.v1.echo_activity";

fn activity_options(queue: &str, policy: &str) -> Result<ActivityOptions> {
    let intervals: &[u64] = match policy {
        "empty" => &[],
        "php_full" => &[1, 2, 5, 10, 15, 30, 60, 120],
        "different" => &[1, 2, 5, 10, 15, 30, 60, 121],
        _ => return Err(Error::WorkerLoop("Unknown explicit retry policy".into())),
    };
    Ok(ActivityOptions::new()
        .task_queue(queue)
        .retry_policy(
            ActivityRetryPolicy::new(1)
                .backoff_intervals(intervals.iter().copied().map(Duration::from_secs)),
        )
        .start_to_close_timeout(Duration::from_secs(120))
        .schedule_to_close_timeout(Duration::from_secs(600)))
}

#[tokio::main]
async fn main() -> Result<()> {
    let args: Vec<String> = std::env::args().skip(1).collect();
    if args.first().is_some_and(|value| value == "memo") {
        return memo_author::run(&args[1..]).await;
    }
    if args.len() != 8 {
        return Err(Error::WorkerLoop(
            "Expected URL, mode, workflow ID, run ID, case, range profile, retry policy, typed value JSON".into(),
        ));
    }
    let value: Value = serde_json::from_str(&args[7])
        .map_err(|e| Error::WorkerLoop(format!("Invalid typed input: {e}")))?;
    let client = Client::builder(&args[0])
        .namespace("default")
        .token(std::env::var("DW_PARITY_TOKEN").ok())
        .timeout(Duration::from_secs(10))
        .build()?;
    let workflow_type = legacy_range::workflow_type(&args[4]).unwrap_or(WORKFLOW);
    let queue = if legacy_range::workflow_type(&args[4]).is_some() {
        "server-parity-v1".to_owned()
    } else {
        format!("{}-workflow", args[2])
    };
    let activity_queue = match args[4].as_str() {
        "range_pending" => "server-parity-range-activity".to_owned(),
        "range_completed" => queue.clone(),
        _ => format!("{}-activity", args[2]),
    };
    if args[1] == "start" {
        let handle = client
            .start_workflow_with_options(
                workflow_type,
                &queue,
                &args[2],
                WorkflowStartOptions::new()
                    .execution_timeout_seconds(1200)
                    .run_timeout_seconds(1200),
                json!([value]),
            )
            .await?;
        println!(
            "{}",
            json!({"pid":std::process::id(),"sdk_version":SDK_VERSION,
            "workflow_id":handle.workflow_id,"run_id":handle.run_id,
            "workflow_queue":queue,"activity_queue":activity_queue})
        );
        return Ok(());
    }
    if !["workflow", "activity"].contains(&args[1].as_str()) {
        return Err(Error::WorkerLoop("Unknown worker mode".into()));
    }
    let decisions = Arc::new(Mutex::new(Vec::<Value>::new()));
    let recorded = Arc::clone(&decisions);
    let selected_case = args[4].clone();
    let profile = args[5].clone();
    let policy = args[6].clone();
    let worker_id = format!("{}:{}:{}", args[2], args[1], profile);
    let worker_queue = if args[1] == "activity" {
        &activity_queue
    } else {
        &queue
    };
    let mut worker = Worker::new(client.clone(), worker_queue)
        .worker_id(&worker_id)
        .max_concurrent_workflow_tasks(1)
        .max_concurrent_activity_tasks(1)
        .poll_timeout(Duration::from_secs(1));
    if args[1] == "activity" {
        worker.register_typed_activity(ACTIVITY, |ctx, input: Value| async move {
            println!(
                "{}",
                json!({"event":"activity_callback","task_id":ctx.task_id,
                "attempt_id":ctx.activity_attempt_id})
            );
            Ok(input)
        });
    } else {
        worker.register_typed_workflow(workflow_type, move |ctx, value: Value| {
            let selected_case = selected_case.clone();
            let profile = profile.clone();
            let policy = policy.clone();
            let activity_queue = activity_queue.clone();
            let recorded = Arc::clone(&recorded);
            async move {
                if legacy_range::workflow_type(&selected_case).is_some() {
                    return legacy_range::execute(
                        ctx,
                        value,
                        &selected_case,
                        &profile,
                        &activity_queue,
                        &policy,
                        recorded,
                    )
                    .await;
                }
                let projection = match selected_case.as_str() {
                    "numeric" | "activity_repair" | "timer_repair" | "full_retry" => {
                        let (first, second) = match profile.as_str() {
                            "original" | "policy-refusal" => ((-3, 7), (-3, 7)),
                            "wider" => ((-10, 10), (5, 20)),
                            "excluding" => ((0, 6), (-3, 7)),
                            _ => return Err(Error::WorkerLoop("Unknown numeric profile".into())),
                        };
                        json!([
                            ctx.get_version("corrected-numeric", first.0, first.1)?,
                            ctx.get_version("corrected-numeric", second.0, second.1)?
                        ])
                    }
                    "zero" => {
                        let first = ctx.get_version("corrected-zero", -7, 0)?;
                        let patch = ctx.patched("corrected-zero")?;
                        ctx.deprecate_patch("corrected-zero")?;
                        let wider = ctx.get_version("corrected-zero", 0, 10)?;
                        json!([first, patch, null, wider, ctx.patched("corrected-zero")?])
                    }
                    "deprecated" | "genuine_one" => {
                        ctx.deprecate_patch("corrected-one")?;
                        let patch = ctx.patched("corrected-one")?;
                        let version = ctx.get_version("corrected-one", 0, 10)?;
                        ctx.deprecate_patch("corrected-one")?;
                        json!([null, patch, version, null])
                    }
                    _ => return Err(Error::WorkerLoop("Unknown marker case".into())),
                };
                recorded
                    .lock()
                    .map_err(|_| Error::WorkflowStatePoisoned)?
                    .push(projection.clone());
                let options = activity_options(&activity_queue, &policy)?;
                if selected_case == "timer_repair" {
                    let selected = ctx
                        .select_keyed(vec![
                            (
                                "pending",
                                ParallelOperation::activity_with_options(
                                    ACTIVITY,
                                    options,
                                    json!([value]),
                                ),
                            ),
                            ("timer", ParallelOperation::timer(Duration::from_secs(2))),
                        ])
                        .await?;
                    if selected.kind != "timer" || !selected.succeeded() {
                        return Err(Error::WorkerLoop("Original timer did not win".into()));
                    }
                    let typed = match selected.handles[0].await_result().await? {
                        ParallelResult::Activity(value) => value,
                        _ => return Err(Error::WorkerLoop("Pending handle changed kind".into())),
                    };
                    return Ok(json!({"projection":projection,"winner":"timer","typed":typed}));
                }
                let typed = ctx
                    .activity_typed_with_options::<Value, Value>(ACTIVITY, options, value)
                    .await?;
                Ok(json!({"projection":projection,"typed":typed}))
            }
        });
    }
    worker.register().await?;
    let outcome = tokio::time::timeout(Duration::from_secs(30), async {
        let mut processed = 0;
        loop {
            processed += worker.run_once().await?;
            let run = client.describe_workflow_run(&args[2], &args[3]).await?;
            let finished =
                if args[1] == "activity" || !["wider", "restored"].contains(&args[5].as_str()) {
                    processed > 0
                } else {
                    run.status.as_deref() == Some("completed")
                };
            if finished {
                return Ok(processed);
            }
            if matches!(
                run.status.as_deref(),
                Some("failed" | "cancelled" | "terminated" | "timed_out")
            ) {
                return Err(Error::WorkerLoop(
                    "Original run reached an unexpected terminal outcome".into(),
                ));
            }
            tokio::time::sleep(Duration::from_millis(25)).await;
        }
    })
    .await;
    let deregistered = client.deregister_worker_registration(&worker_id).await;
    let result = match outcome {
        Ok(result) => result,
        Err(_) => Err(Error::WorkerLoop(
            "Original 30-second worker observation expired".into(),
        )),
    };
    println!(
        "{}",
        json!({"pid":std::process::id(),"sdk_version":SDK_VERSION,
        "workflow_id":args[2],"run_id":args[3],"mode":args[1],"case":args[4],
        "range_profile":args[5],"retry_policy":args[6],"decisions":*decisions.lock().unwrap(),
        "outcome":result.as_ref().map(|count| *count).map_err(ToString::to_string),
        "deregistered":deregistered.as_ref().map(|_| true).map_err(ToString::to_string)})
    );
    deregistered?;
    result?;
    Ok(())
}
