use std::{
    env,
    time::{Duration, Instant},
};

use durable_workflow::{
    json, ChildWorkflowOptions, Client, Error, ParallelOperation, ParallelResult, Result, Value,
    Worker, WorkflowContext, WorkflowResultOptions, SDK_VERSION,
};

const PARENT_TYPE: &str = "capacity.v1_10.child_parent";
const LEAF_TYPE: &str = "capacity.v1_10.child_leaf";
const PARENT_QUEUE: &str = "capacity-v1-10-parent";
const LEAF_QUEUE: &str = "capacity-v1-10-leaf";

fn fixture_error(message: &str) -> Error {
    Error::Codec(message.into())
}

fn required_environment(name: &str) -> Result<String> {
    env::var(name)
        .map_err(|_| Error::Codec(format!("Set {name} for the isolated capacity runtime.")))
}

async fn parent(context: WorkflowContext, input: Value) -> Result<Value> {
    let width = input[0]
        .as_i64()
        .filter(|width| matches!(width, 10 | 1000))
        .ok_or_else(|| fixture_error("Expected width 10 or 1000."))?;
    let children = (1..=width)
        .map(|index| {
            ParallelOperation::child_workflow(
                LEAF_TYPE,
                ChildWorkflowOptions::new(LEAF_QUEUE),
                json!([index]),
            )
        })
        .collect();
    let results = context.parallel(children).await?;
    if results.len() != width as usize {
        return Err(fixture_error("The child barrier returned the wrong number of results."));
    }
    let mut sum = 0;
    for (expected, result) in (1..=width).zip(results) {
        let ParallelResult::ChildWorkflow(child) = result else {
            return Err(fixture_error("The child barrier returned a different result kind."));
        };
        if child.result.as_i64() != Some(expected) {
            return Err(fixture_error("The child barrier returned unexpected values or ordering."));
        }
        sum += expected;
    }
    Ok(json!(sum))
}

async fn client_run(client: Client, width: i64, workflow_id: &str) -> Result<()> {
    let start = Instant::now();
    let handle = client
        .start_workflow(PARENT_TYPE, PARENT_QUEUE, workflow_id, json!([width]))
        .await?;
    let result = handle
        .result(WorkflowResultOptions {
            timeout: Duration::from_secs(240),
            poll_interval: Duration::from_millis(250),
        })
        .await?;
    if result.as_i64() != Some(width * (width + 1) / 2) {
        return Err(fixture_error("The parent completed with the wrong result."));
    }
    println!(
        "{}",
        json!({
            "sdk_version": SDK_VERSION,
            "workflow_id": workflow_id,
            "run_id": handle.run_id,
            "width": width,
            "result": result,
            "elapsed_ms": start.elapsed().as_secs_f64() * 1000.0,
        })
    );
    Ok(())
}

#[tokio::main(flavor = "current_thread")]
async fn main() -> Result<()> {
    let args: Vec<String> = env::args().skip(1).collect();
    let client = Client::builder(required_environment("DURABLE_WORKFLOW_RUNTIME_URL")?)
        .namespace(env::var("DURABLE_WORKFLOW_NAMESPACE").unwrap_or_else(|_| "default".into()))
        .token(Some(required_environment("DURABLE_WORKFLOW_TOKEN")?))
        .build()?;

    match args.as_slice() {
        [role] if role == "parent" || role == "leaf" => {
            let queue = if role == "parent" { PARENT_QUEUE } else { LEAF_QUEUE };
            let mut worker = Worker::new(client, queue)
                .max_concurrent_workflow_tasks(1)
                .max_concurrent_activity_tasks(1)
                .poll_timeout(Duration::from_secs(1));
            if role == "parent" {
                worker.register_workflow(PARENT_TYPE, parent);
            } else {
                worker.register_workflow(LEAF_TYPE, |_context, input| async move {
                    input[0]
                        .as_i64()
                        .map(|index| json!(index))
                        .ok_or_else(|| fixture_error("Expected an integer child index."))
                });
            }
            worker.run().await
        }
        [role, width, id] if role == "run" => {
            let width = width
                .parse::<i64>()
                .ok()
                .filter(|width| matches!(width, 10 | 1000))
                .ok_or_else(|| fixture_error("Expected width 10 or 1000."))?;
            client_run(client, width, id).await
        }
        _ => Err(fixture_error("Expected parent, leaf, or run WIDTH WORKFLOW_ID.")),
    }
}
