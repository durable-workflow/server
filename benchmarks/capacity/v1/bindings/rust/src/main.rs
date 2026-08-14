use std::{collections::HashMap, env, io::Write, time::Duration, time::Instant};

use durable_workflow::{
    json, ChildWorkflowOptions, Client, ClientBuilder, Error, Result, Value, Worker,
    WorkflowHandle, WorkflowResultOptions,
};
use futures_util::future::try_join_all;
use sha2::{Digest, Sha256};
use tokio::io::{AsyncBufReadExt, BufReader};

const DESCRIPTOR: &str = include_str!("../adapter.json");

fn workflow_type(cell_id: &str) -> Option<&'static str> {
    match cell_id {
        "simple-start-complete" => Some("capacity.v1.simple"),
        "one-activity" => Some("capacity.v1.one_activity"),
        "multiple-activities" => Some("capacity.v1.multiple_activities"),
        "timer" => Some("capacity.v1.timer"),
        "signal" => Some("capacity.v1.signal"),
        "child-workflow-fanout" => Some("capacity.v1.child_parent"),
        "replay-heavy-history" => Some("capacity.v1.replay_heavy"),
        "query-inspection" => Some("capacity.v1.queryable_counter"),
        "mixed" => Some("capacity.v1.mixed_selector"),
        _ => None,
    }
}

fn request(input: &Value) -> &Value {
    input.get(0).unwrap_or(input)
}

fn blob(input: &Value) -> &str {
    input.get("blob").and_then(Value::as_str).unwrap_or("")
}

fn result_blob(input: &Value) -> &str {
    input
        .get("result_blob")
        .and_then(Value::as_str)
        .unwrap_or_else(|| blob(input))
}

#[derive(Clone, Copy)]
struct PayloadContract {
    workflow_input_bytes: usize,
    activity_input_bytes: usize,
    activity_result_bytes: usize,
}

fn payload_contract(input: &Value) -> std::result::Result<PayloadContract, String> {
    let candidate = input
        .get("payload_contract")
        .and_then(Value::as_object)
        .ok_or_else(|| "capacity workload omitted its payload contract".to_string())?;
    let size = |field: &str| {
        candidate
            .get(field)
            .and_then(Value::as_u64)
            .and_then(|value| usize::try_from(value).ok())
            .ok_or_else(|| format!("capacity payload contract has invalid {field}"))
    };
    for field in ["workflow_result_bytes", "signal_bytes"] {
        size(field)?;
    }
    Ok(PayloadContract {
        workflow_input_bytes: size("workflow_input_bytes")?,
        activity_input_bytes: size("activity_input_bytes")?,
        activity_result_bytes: size("activity_result_bytes")?,
    })
}

fn require_utf8_size(
    value: &Value,
    expected: usize,
    boundary: &str,
) -> std::result::Result<String, String> {
    let text = value
        .as_str()
        .ok_or_else(|| format!("{boundary} must be a string"))?;
    let actual = text.len();
    if actual != expected {
        return Err(format!(
            "{boundary} must contain {expected} UTF-8 bytes; observed {actual}"
        ));
    }
    Ok(text.to_string())
}

fn sized_ascii(seed: &str, size: usize) -> std::result::Result<String, String> {
    if seed.is_empty() || !seed.is_ascii() {
        return Err("capacity payload expansion requires a non-empty ASCII seed".into());
    }
    Ok(seed.repeat(size / seed.len() + 1)[..size].to_string())
}

fn initial_activity_input(input: &Value) -> std::result::Result<String, String> {
    let contract = payload_contract(input)?;
    let workflow_input = require_utf8_size(
        &json!(blob(input)),
        contract.workflow_input_bytes,
        "workflow input",
    )?;
    require_utf8_size(
        &json!(workflow_input),
        contract.activity_input_bytes,
        "activity input",
    )
}

fn checked_activity_result(
    value: &Value,
    contract: PayloadContract,
) -> std::result::Result<String, String> {
    require_utf8_size(value, contract.activity_result_bytes, "activity result")
}

fn required_environment(name: &str) -> std::result::Result<String, String> {
    env::var(name)
        .ok()
        .filter(|value| !value.trim().is_empty())
        .ok_or_else(|| format!("set {name}"))
}

fn client_builder(worker: bool) -> std::result::Result<ClientBuilder, String> {
    let mut builder = Client::builder(required_environment("DURABLE_WORKFLOW_RUNTIME_URL")?)
        .namespace(env::var("DURABLE_WORKFLOW_NAMESPACE").unwrap_or_else(|_| "default".into()));
    if let Ok(token) = env::var("DURABLE_WORKFLOW_TOKEN") {
        if !token.trim().is_empty() {
            return Ok(builder.token(Some(token)));
        }
    }
    builder = if worker {
        builder.worker_token(env::var("DURABLE_WORKFLOW_WORKER_TOKEN").ok())
    } else {
        builder.control_token(env::var("DURABLE_WORKFLOW_CLIENT_TOKEN").ok())
    };
    Ok(builder)
}

fn adapter_error(message: impl Into<String>) -> Error {
    Error::Codec(message.into())
}

async fn run_worker() -> Result<()> {
    let task_queue = required_environment("DURABLE_WORKFLOW_TASK_QUEUE").map_err(adapter_error)?;
    let client = client_builder(true).map_err(adapter_error)?.build()?;
    let concurrency = env::var("DURABLE_WORKFLOW_WORKER_CONCURRENCY")
        .ok()
        .and_then(|value| value.parse::<usize>().ok())
        .unwrap_or(32)
        .max(1);
    let mut worker = Worker::new(client, task_queue.clone())
        .max_concurrent_workflow_tasks(concurrency)
        .max_concurrent_activity_tasks(concurrency);

    worker.register_activity("capacity.v1.echo", |_ctx, arguments| async move {
        Ok(arguments.get(0).cloned().unwrap_or(Value::Null))
    });
    worker.register_activity("capacity.v1.hash", |_ctx, arguments| async move {
        let payload = arguments.get(0).and_then(Value::as_str).unwrap_or("");
        Ok(json!(format!("{:x}", Sha256::digest(payload.as_bytes()))))
    });

    worker.register_workflow("capacity.v1.simple", |_ctx, input| async move {
        Ok(json!(result_blob(request(&input))))
    });
    worker.register_workflow("capacity.v1.one_activity", |ctx, input| async move {
        let input = request(&input);
        let contract = payload_contract(input).map_err(adapter_error)?;
        let activity_input = initial_activity_input(input).map_err(adapter_error)?;
        let result = ctx
            .activity("capacity.v1.echo", json!([activity_input]))
            .await?;
        Ok(json!(
            checked_activity_result(&result, contract).map_err(adapter_error)?
        ))
    });
    worker.register_workflow("capacity.v1.multiple_activities", |ctx, input| async move {
        let input = request(&input);
        let contract = payload_contract(input).map_err(adapter_error)?;
        let mut digest = initial_activity_input(input).map_err(adapter_error)?;
        for index in 0..5 {
            let result = ctx.activity("capacity.v1.hash", json!([digest])).await?;
            digest = checked_activity_result(&result, contract).map_err(adapter_error)?;
            if index < 4 {
                digest =
                    sized_ascii(&digest, contract.activity_input_bytes).map_err(adapter_error)?;
            }
        }
        Ok(json!(digest))
    });
    worker.register_workflow("capacity.v1.timer", |ctx, _input| async move {
        ctx.sleep(Duration::from_secs(1)).await?;
        Ok(json!("capacity.timer"))
    });
    worker.register_workflow("capacity.v1.signal", |ctx, _input| async move {
        let mut sequences = Vec::with_capacity(4);
        for _ in 0..4 {
            let arguments = ctx.wait_signal("capacity.v1.append").await?;
            sequences.push(arguments.first().and_then(Value::as_i64).unwrap_or(-1));
        }
        if sequences != [0, 1, 2, 3] {
            return Err(adapter_error(
                "capacity.v1.append signals must retain sequence 0 through 3",
            ));
        }
        Ok(json!(sequences.len()))
    });

    let child_queue = task_queue.clone();
    worker.register_workflow("capacity.v1.child_parent", move |ctx, _input| {
        let child_queue = child_queue.clone();
        async move {
            let children = (0..10).map(|index| {
                ctx.start_child_workflow(
                    "capacity.v1.child_leaf",
                    ChildWorkflowOptions::new(child_queue.clone()),
                    json!([index]),
                )
            });
            let results = try_join_all(children).await?;
            let sum: i64 = results
                .iter()
                .map(|child| child.result.as_i64().unwrap_or(0))
                .sum();
            Ok(json!(sum))
        }
    });
    worker.register_workflow("capacity.v1.child_leaf", |_ctx, input| async move {
        Ok(input.get(0).cloned().unwrap_or(Value::Null))
    });
    worker.register_workflow("capacity.v1.replay_heavy", |ctx, _input| async move {
        for index in 0_i64..500 {
            let _: i64 = ctx.side_effect(|| index)?;
        }
        Ok(json!(500))
    });
    worker.register_workflow("capacity.v1.queryable_counter", |ctx, _input| async move {
        ctx.wait_signal("capacity.v1.finish").await?;
        Ok(json!(0))
    });
    worker.register_query(
        "capacity.v1.queryable_counter",
        "capacity.v1.inspect_counter",
        |_ctx, _arguments| async move { Ok(json!(0)) },
    );

    let mixed_child_queue = task_queue;
    worker.register_workflow("capacity.v1.mixed_selector", move |ctx, input| {
        let child_queue = mixed_child_queue.clone();
        async move {
            let input = request(&input);
            match input.get("shape").and_then(Value::as_str).unwrap_or("") {
                "simple-start-complete" => Ok(json!(result_blob(input))),
                "one-activity" => {
                    let contract = payload_contract(input).map_err(adapter_error)?;
                    let activity_input = initial_activity_input(input).map_err(adapter_error)?;
                    let result = ctx
                        .activity("capacity.v1.echo", json!([activity_input]))
                        .await?;
                    Ok(json!(
                        checked_activity_result(&result, contract).map_err(adapter_error)?
                    ))
                }
                "multiple-activities" => {
                    let contract = payload_contract(input).map_err(adapter_error)?;
                    let mut digest = initial_activity_input(input).map_err(adapter_error)?;
                    for index in 0..5 {
                        let result = ctx.activity("capacity.v1.hash", json!([digest])).await?;
                        digest =
                            checked_activity_result(&result, contract).map_err(adapter_error)?;
                        if index < 4 {
                            digest = sized_ascii(&digest, contract.activity_input_bytes)
                                .map_err(adapter_error)?;
                        }
                    }
                    Ok(json!(digest))
                }
                "timer" => {
                    ctx.sleep(Duration::from_secs(1)).await?;
                    Ok(json!("capacity.timer"))
                }
                "signal" => {
                    let mut sequences = Vec::with_capacity(4);
                    for _ in 0..4 {
                        let arguments = ctx.wait_signal("capacity.v1.append").await?;
                        sequences.push(arguments.first().and_then(Value::as_i64).unwrap_or(-1));
                    }
                    if sequences != [0, 1, 2, 3] {
                        return Err(adapter_error(
                            "capacity.v1.append signals must retain sequence 0 through 3",
                        ));
                    }
                    Ok(json!(sequences.len()))
                }
                "child-workflow-fanout" => {
                    let children = (0..10).map(|index| {
                        ctx.start_child_workflow(
                            "capacity.v1.child_leaf",
                            ChildWorkflowOptions::new(child_queue.clone()),
                            json!([index]),
                        )
                    });
                    let results = try_join_all(children).await?;
                    let sum: i64 = results
                        .iter()
                        .map(|child| child.result.as_i64().unwrap_or(0))
                        .sum();
                    Ok(json!(sum))
                }
                "replay-heavy-history" => {
                    for index in 0_i64..500 {
                        let _: i64 = ctx.side_effect(|| index)?;
                    }
                    Ok(json!(500))
                }
                "query-inspection" => {
                    ctx.wait_signal("capacity.v1.finish").await?;
                    Ok(json!(0))
                }
                shape => Err(adapter_error(format!(
                    "unsupported mixed workload shape: {shape}"
                ))),
            }
        }
    });
    worker.register_query(
        "capacity.v1.mixed_selector",
        "capacity.v1.inspect_counter",
        |_ctx, _arguments| async move { Ok(json!(0)) },
    );

    worker.run().await
}

fn command_response(
    operation: &str,
    workflow_id: &str,
    run_id: Option<&str>,
    started: Instant,
    result: Value,
) -> Value {
    json!({
        "ok": true,
        "operation": operation,
        "workflow_id": workflow_id,
        "run_id": run_id,
        "elapsed_ms": (started.elapsed().as_secs_f64() * 1000.0 * 1000.0).round() / 1000.0,
        "result": result,
    })
}

async fn execute_client_command(
    client: &Client,
    handles: &mut HashMap<String, WorkflowHandle>,
    command: Value,
) -> std::result::Result<Value, String> {
    let operation = command
        .get("operation")
        .and_then(Value::as_str)
        .ok_or_else(|| "every command requires operation".to_string())?;
    let workflow_id = command
        .get("workflow_id")
        .and_then(Value::as_str)
        .filter(|value| !value.is_empty())
        .ok_or_else(|| "every client operation requires workflow_id".to_string())?;
    let started = Instant::now();
    let mut result = Value::Null;
    let mut selected_run_id: Option<String> = None;

    match operation {
        "start" => {
            let cell_id = command
                .get("cell_id")
                .and_then(Value::as_str)
                .ok_or_else(|| "start requires cell_id".to_string())?;
            let task_queue = command
                .get("task_queue")
                .and_then(Value::as_str)
                .filter(|value| !value.is_empty())
                .ok_or_else(|| "start requires task_queue".to_string())?;
            let mut payload = command
                .get("payload")
                .cloned()
                .filter(Value::is_object)
                .unwrap_or_else(|| json!({}));
            payload["task_queue"] = json!(task_queue);
            let handle = client
                .start_workflow(
                    workflow_type(cell_id)
                        .ok_or_else(|| format!("unsupported capacity cell: {cell_id}"))?,
                    task_queue,
                    workflow_id,
                    json!([payload]),
                )
                .await
                .map_err(|error| error.to_string())?;
            selected_run_id = handle.run_id.clone();
            handles.insert(workflow_id.to_string(), handle);
        }
        "signal" => {
            let name = command
                .get("name")
                .and_then(Value::as_str)
                .ok_or_else(|| "signal requires name".to_string())?;
            let arguments = command
                .get("arguments")
                .cloned()
                .filter(Value::is_array)
                .unwrap_or_else(|| json!([]));
            client
                .signal_workflow(workflow_id, name, arguments)
                .await
                .map_err(|error| error.to_string())?;
        }
        "query" => {
            let name = command
                .get("name")
                .and_then(Value::as_str)
                .ok_or_else(|| "query requires name".to_string())?;
            let arguments = command
                .get("arguments")
                .cloned()
                .filter(Value::is_array)
                .unwrap_or_else(|| json!([]));
            result = client
                .query_workflow(workflow_id, name, arguments)
                .await
                .map_err(|error| error.to_string())?;
        }
        "result" => {
            let handle = handles.get(workflow_id).cloned().ok_or_else(|| {
                "result requires a handle started by this client process".to_string()
            })?;
            let timeout = command
                .get("timeout_seconds")
                .and_then(Value::as_u64)
                .unwrap_or(300);
            result = handle
                .result(WorkflowResultOptions {
                    poll_interval: Duration::from_millis(500),
                    timeout: Duration::from_secs(timeout),
                })
                .await
                .map_err(|error| error.to_string())?;
        }
        _ => return Err(format!("unsupported client operation: {operation}")),
    }

    Ok(command_response(
        operation,
        workflow_id,
        selected_run_id.as_deref(),
        started,
        result,
    ))
}

async fn run_client() -> std::result::Result<(), String> {
    let client = client_builder(false)?
        .build()
        .map_err(|error| error.to_string())?;
    let mut handles = HashMap::new();
    let stdin = BufReader::new(tokio::io::stdin());
    let mut lines = stdin.lines();

    while let Some(line) = lines.next_line().await.map_err(|error| error.to_string())? {
        let response = match serde_json::from_str::<Value>(&line) {
            Ok(command) if command.is_object() => {
                match execute_client_command(&client, &mut handles, command).await {
                    Ok(response) => response,
                    Err(error) => {
                        json!({"ok": false, "error_type": "AdapterError", "error": error})
                    }
                }
            }
            Ok(_) => {
                json!({"ok": false, "error_type": "AdapterError", "error": "each client command must be a JSON object"})
            }
            Err(error) => {
                json!({"ok": false, "error_type": "JsonError", "error": error.to_string()})
            }
        };
        println!("{response}");
        std::io::stdout()
            .flush()
            .map_err(|error| error.to_string())?;
    }
    Ok(())
}

fn conformance_evidence(fixtures: &Value) -> std::result::Result<Value, String> {
    let fixture_cases = fixtures
        .get("payload_cases")
        .and_then(Value::as_array)
        .ok_or_else(|| "conformance fixture omitted payload_cases".to_string())?;
    let mut cases = Vec::new();
    for fixture in fixture_cases {
        let contract_value = fixture
            .get("payload")
            .cloned()
            .filter(Value::is_object)
            .ok_or_else(|| "payload fixture omitted its contract".to_string())?;
        let workflow_input_bytes = contract_value
            .get("workflow_input_bytes")
            .and_then(Value::as_u64)
            .and_then(|value| usize::try_from(value).ok())
            .ok_or_else(|| "payload fixture has invalid workflow_input_bytes".to_string())?;
        let input = json!({
            "blob": sized_ascii("payload", workflow_input_bytes)?,
            "payload_contract": contract_value,
        });
        let contract = payload_contract(&input)?;
        let mut current = initial_activity_input(&input)?;
        let activity_count = fixture
            .get("activity_count")
            .and_then(Value::as_u64)
            .and_then(|value| usize::try_from(value).ok())
            .unwrap_or(0);
        let activity_type = fixture
            .get("activity_type")
            .and_then(Value::as_str)
            .unwrap_or("");
        let mut input_sizes = Vec::new();
        let mut result_sizes = Vec::new();
        for index in 0..activity_count {
            input_sizes.push(current.len());
            let result = match activity_type {
                "capacity.v1.echo" => current.clone(),
                "capacity.v1.hash" => format!("{:x}", Sha256::digest(current.as_bytes())),
                _ => {
                    return Err(format!(
                        "unsupported fixture activity type: {activity_type}"
                    ))
                }
            };
            current = checked_activity_result(&json!(result), contract)?;
            result_sizes.push(current.len());
            if index + 1 < activity_count {
                current = sized_ascii(&current, contract.activity_input_bytes)?;
            }
        }
        cases.push(json!({
            "id": fixture.get("id").cloned().unwrap_or(Value::Null),
            "activity_input_bytes": input_sizes,
            "activity_result_bytes": result_sizes,
        }));
    }
    Ok(json!({
        "schema": "durable-workflow.capacity-workload-conformance-evidence/v1",
        "cases": cases,
    }))
}

#[tokio::main]
async fn main() -> std::result::Result<(), Box<dyn std::error::Error>> {
    match env::args().nth(1).as_deref() {
        Some("describe") => {
            let descriptor: Value = serde_json::from_str(DESCRIPTOR)?;
            println!("{descriptor}");
            Ok(())
        }
        Some("conformance") => {
            let path = env::args()
                .nth(2)
                .ok_or("conformance requires a fixture path")?;
            let fixture: Value = serde_json::from_str(&std::fs::read_to_string(path)?)?;
            println!("{}", conformance_evidence(&fixture)?);
            Ok(())
        }
        Some("worker") => run_worker().await.map_err(Into::into),
        Some("client") => run_client().await.map_err(Into::into),
        _ => Err("usage: capacity adapter describe|conformance|worker|client".into()),
    }
}
