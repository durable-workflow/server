use std::time::Duration;

use durable_workflow::{Client, Error, Result, SDK_VERSION};
use serde_json::{json, Value};

#[tokio::main]
async fn main() -> Result<()> {
    let arguments: Vec<String> = std::env::args().skip(1).collect();
    let [phase, url, workflow_id, run_id, fixture_path, remaining] = arguments.as_slice() else {
        return Err(Error::WorkerLoop("Expected phase, URL, workflow ID, selected run, fixture and remaining seconds".into()));
    };
    let fixture: Value = serde_json::from_str(
        &std::fs::read_to_string(fixture_path).map_err(|error| Error::WorkerLoop(error.to_string()))?,
    ).map_err(|error| Error::WorkerLoop(error.to_string()))?;
    let seconds: f64 = remaining.parse().map_err(|_| Error::WorkerLoop("Invalid original phase budget".into()))?;
    if !seconds.is_finite() || seconds <= 0.0 || run_id.is_empty() {
        return Err(Error::WorkerLoop("Selected-run sender requires its original run and remaining budget".into()));
    }
    let namespace = fixture["namespace"].as_str()
        .filter(|value| !value.is_empty() && value.trim() == *value)
        .ok_or_else(|| Error::WorkerLoop("Invalid fixture namespace".into()))?;
    let (name, payload, expected_status) = match phase.as_str() {
        "foreign-signal" => ("payload", json!([fixture["signal_value"]]), Some(404)),
        "mismatched-run" | "inactive-signal" => ("payload", json!([fixture["signal_value"]]), Some(409)),
        "unknown-signal" => ("undeclared", json!([fixture["signal_value"]]), Some(404)),
        "invalid-signal" => ("payload", json!([]), Some(422)),
        "signal-1" | "signal-2" => ("payload", json!([fixture["signal_value"]]), None),
        _ => return Err(Error::WorkerLoop("Unknown selected-run sender phase".into())),
    };
    let client = Client::builder(url).namespace(namespace)
        .token(std::env::var("DW_PARITY_TOKEN").ok()).build()?;
    let outcome = tokio::time::timeout(Duration::from_secs_f64(seconds),
        client.signal_workflow_run(workflow_id, run_id, name, payload)).await
        .map_err(|_| Error::WorkerLoop("Original selected-run sender budget expired".into()))?;
    let mut result = json!({"pid": std::process::id(), "language": "rust",
        "sdk_version": SDK_VERSION.trim_start_matches("durable-workflow-rust/"),
        "phase": phase, "workflow_id": workflow_id, "run_id": run_id, "sender_finished": true});
    match outcome {
        Ok(receipt) if expected_status.is_none() => {
            result["signal_sent"] = json!(true);
            result["client_result"] = receipt;
        }
        Err(Error::Http { status, body }) if Some(status.as_u16()) == expected_status => {
            result["signal_sent"] = json!(false);
            result["typed_error"] = json!({"class": "durable_workflow::Error::Http", "status": status.as_u16(), "body": body});
        }
        Ok(_) => return Err(Error::WorkerLoop("Selected-run negative control was admitted".into())),
        Err(error) => return Err(error),
    }
    println!("{result}");
    Ok(())
}
