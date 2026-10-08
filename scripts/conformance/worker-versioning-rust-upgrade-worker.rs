//! Identical application source compiled against both published SDK versions.
use durable_workflow::{json, Client, Value, Worker, WorkflowResultOptions, SDK_VERSION};
use std::{
    env,
    io::{BufRead, Write},
    sync::{
        atomic::{AtomicUsize, Ordering},
        Arc, Mutex,
    },
    time::Duration,
};

#[path = "worker-versioning-rust-definition-v1.rs"]
mod definition;
type Result<T> = std::result::Result<T, Box<dyn std::error::Error>>;
const TYPE: &str = "conformance.rust-sdk-upgrade";

fn emit(value: Value) -> Result<()> {
    println!("{value}");
    std::io::stdout().flush()?;
    Ok(())
}

#[tokio::main]
async fn main() -> Result<()> {
    let args: Vec<String> = env::args().collect();
    let mode = args.get(1).ok_or("mode is required")?;
    let queue = args.get(2).ok_or("task queue is required")?;
    let id = args.get(3).ok_or("identity is required")?;
    let client = Client::builder(env::var("DW_WV_SERVER_URL")?)
        .token(Some(
            env::var("DW_WV_AUTH_TOKEN").unwrap_or_else(|_| "dev-token".into()),
        ))
        .namespace(env::var("DW_WV_NAMESPACE")?)
        .build()?;
    if mode == "--client" {
        let handle = client.start_workflow(TYPE, queue, id, json!([])).await?;
        emit(
            json!({"client":true,"pid":std::process::id(),"sdk_version":SDK_VERSION,
            "workflow_id":handle.workflow_id,"run_id":handle.run_id}),
        )?;
        for line in std::io::stdin().lock().lines() {
            let request: Value = serde_json::from_str(&line?)?;
            if request["action"] == "stop" {
                emit(json!({"stopped":true}))?;
                break;
            }
            if request["action"] != "result" {
                return Err("unknown client action".into());
            }
            let result = handle
                .result_selected_run(WorkflowResultOptions::default())
                .await?;
            emit(
                json!({"workflow_id":handle.workflow_id,"run_id":handle.run_id,
                "result":result,"sdk_version":SDK_VERSION}),
            )?;
        }
        return Ok(());
    }
    if mode != "--worker" {
        return Err("unknown worker mode".into());
    }
    let effects = Arc::new(AtomicUsize::new(0));
    let seen = Arc::new(Mutex::new(Vec::<Value>::new()));
    let callback_effects = effects.clone();
    let callback_seen = seen.clone();
    let mut worker = Worker::new(client, queue)
        .worker_id(id)
        .poll_timeout(Duration::from_secs(1));
    worker.register_workflow(TYPE, move |ctx, _| {
        let effects = callback_effects.clone();
        let seen = callback_seen.clone();
        async move { definition::execute(ctx, effects, seen).await }
    });
    worker.declare_workflow_signals(TYPE, &["ready", "finish"])?;
    worker.set_workflow_definition_sources(
        TYPE,
        &[
            include_str!("worker-versioning-rust-definition-v1.rs"),
            include_str!("worker-versioning-rust-upgrade-worker.rs"),
        ],
    )?;
    let registration = worker.register().await?;
    if !registration.registered || registration.worker_id != *id {
        return Err("worker registration refused".into());
    }
    emit(
        json!({"registered":true,"pid":std::process::id(),"sdk_version":SDK_VERSION,
        "side_effect_calls":effects.load(Ordering::SeqCst),"callbacks":*seen.lock().unwrap()}),
    )?;
    for line in std::io::stdin().lock().lines() {
        let request: Value = serde_json::from_str(&line?)?;
        let stop = request["action"] == "stop";
        let processed = if stop {
            worker.run_until(async {}).await?;
            0
        } else if request["action"] == "poll" {
            worker.run_once().await?
        } else {
            return Err("unknown worker action".into());
        };
        emit(
            json!({"pid":std::process::id(),"processed":processed,"sdk_version":SDK_VERSION,
            "side_effect_calls":effects.load(Ordering::SeqCst),"callbacks":*seen.lock().unwrap()}),
        )?;
        if stop {
            break;
        }
    }
    Ok(())
}
