use durable_workflow::{
    json, wait_condition, Client, ConditionWaitResult, Error, Result, Value, Worker,
    WorkflowStartOptions,
};
use std::{collections::HashSet, env, path::PathBuf, time::Duration};

const WORKFLOW_TYPE: &str = "history-qualification.rust-mixed";
const ACTIVITY_TYPE: &str = "history-qualification.rust-boundary";

fn required(name: &str) -> Result<String> {
    env::var(name).map_err(|_| Error::WorkerLoop(format!("missing {name}")))
}

fn client() -> Result<Client> {
    Client::builder(required("PROBE_RUNTIME_URL")?)
        .namespace("default")
        .control_token(Some(required("PROBE_CONTROL_TOKEN")?))
        .worker_token(Some(required("PROBE_WORKER_TOKEN")?))
        .build()
}

fn worker(client: Client, queue: &str) -> Worker {
    let mut worker = Worker::new(client, queue)
        .poll_timeout(Duration::from_secs(1))
        .heartbeat_interval(Duration::from_secs(2));
    worker.register_activity(ACTIVITY_TYPE, |_ctx, arguments| async move {
        Ok(arguments.get(0).cloned().unwrap_or(Value::Null))
    });
    worker.register_workflow(WORKFLOW_TYPE, |ctx, input| async move {
        let target = input.get(0).and_then(Value::as_u64).unwrap_or(0);
        let side_effects = input.get(1).and_then(Value::as_u64).unwrap_or(0);
        let stage = input.get(2).and_then(Value::as_u64).unwrap_or(0);
        let carried_total = input.get(3).and_then(Value::as_u64).unwrap_or(0);
        if stage == 1 {
            return Ok(json!({"count": target, "total": carried_total}));
        }
        if target == 0 || target > 6000 || side_effects == 0 || side_effects > 6000 {
            return Err(Error::WorkerLoop("unbounded probe input".into()));
        }

        let predicate_ctx = ctx.clone();
        let outcome = wait_condition!(
            ctx,
            "all-signals",
            move || Ok(predicate_ctx.signals("append")?.len() == target as usize),
        )
        .await?;
        if outcome != ConditionWaitResult::Satisfied {
            return Err(Error::WorkerLoop("signal wait ended early".into()));
        }

        let signals = ctx.signals("append")?;
        let mut seen = HashSet::new();
        let mut total = 0_u64;
        for arguments in signals {
            let index = arguments.first().and_then(Value::as_u64).ok_or_else(|| {
                Error::WorkerLoop("signal argument was not an unsigned integer".into())
            })?;
            if index >= target || !seen.insert(index) {
                return Err(Error::WorkerLoop("signal index was duplicate or out of bounds".into()));
            }
            total += index;
        }
        if seen.len() != target as usize || total != target * (target - 1) / 2 {
            return Err(Error::WorkerLoop("acknowledged signals did not survive replay".into()));
        }

        for index in 0..side_effects {
            let recorded: u64 = ctx.side_effect(|| index)?;
            if recorded != index {
                return Err(Error::WorkerLoop(format!("side effect {index} replayed as {recorded}")));
            }
            if (index + 1) % 500 == 0 {
                let boundary = index + 1;
                let activity_result = ctx.activity(ACTIVITY_TYPE, json!([boundary])).await?;
                if activity_result != json!(boundary) {
                    return Err(Error::WorkerLoop(format!("activity boundary {boundary} changed")));
                }
                ctx.sleep(Duration::ZERO).await?;
            }
        }

        ctx.continue_as_new(json!([target, side_effects, 1, total]))
    });
    worker
}

#[tokio::main]
async fn main() -> Result<()> {
    let mode = env::args().nth(1).unwrap_or_default();
    let url = required("PROBE_RUNTIME_URL")?;
    if !url.starts_with("http://127.0.0.1:") {
        return Err(Error::WorkerLoop("use an isolated loopback Server".into()));
    }
    let workflow_id = required("PROBE_WORKFLOW_ID")?;
    let queue = required("PROBE_TASK_QUEUE")?;
    let target = required("PROBE_TARGET")?.parse::<u64>().map_err(|error| {
        Error::WorkerLoop(format!("invalid target: {error}"))
    })?;
    let side_effects = required("PROBE_SIDE_EFFECTS")?.parse::<u64>().map_err(|error| {
        Error::WorkerLoop(format!("invalid side effect count: {error}"))
    })?;
    if target == 0 || target > 6000 || side_effects == 0 || side_effects > 6000
        || side_effects % 500 != 0
    {
        return Err(Error::WorkerLoop("probe limits exceeded".into()));
    }
    let client = client()?;

    match mode.as_str() {
        "start" => {
            let handle = client
                .start_workflow_with_options(
                    WORKFLOW_TYPE,
                    &queue,
                    &workflow_id,
                    WorkflowStartOptions::new()
                        .execution_timeout_seconds(3600)
                        .run_timeout_seconds(3600),
                    json!([target, side_effects]),
                )
                .await?;
            println!("{}", json!({"phase": "started", "workflow_id": workflow_id, "run_id": handle.run_id}));
        }
        "worker" => {
            let stop_file = PathBuf::from(required("PROBE_STOP_FILE")?);
            worker(client, &queue)
                .run_until(async move {
                    while !stop_file.exists() {
                        tokio::time::sleep(Duration::from_millis(100)).await;
                    }
                })
                .await?;
            println!("{}", json!({"phase": "worker_stopped"}));
        }
        "wait" | "result" => {
            let deadline = tokio::time::Instant::now()
                + if mode == "wait" { Duration::from_secs(120) } else { Duration::from_secs(1800) };
            loop {
                let description = client.describe_workflow(&workflow_id).await?;
                let status = description.status.as_deref().unwrap_or("");
                if mode == "wait" && status == "waiting" {
                    println!("{}", json!({"phase": "waiting", "run_id": description.run_id}));
                    break;
                }
                if mode == "result" && status == "completed" {
                    let expected = json!({"count": target, "total": target * (target - 1) / 2});
                    if description.output.as_ref() != Some(&expected) {
                        return Err(Error::WorkerLoop(format!("wrong result: {:?}", description.output)));
                    }
                    println!("{}", json!({"phase": "completed", "run_id": description.run_id, "result": description.output}));
                    break;
                }
                if matches!(status, "failed" | "terminated" | "cancelled") {
                    return Err(Error::WorkerLoop(format!("workflow stopped: {status}")));
                }
                if tokio::time::Instant::now() >= deadline {
                    return Err(Error::WorkerLoop("workflow wait exceeded its bounded deadline".into()));
                }
                tokio::time::sleep(Duration::from_millis(500)).await;
            }
        }
        _ => return Err(Error::WorkerLoop("mode must be start, worker, wait, or result".into())),
    }

    Ok(())
}
