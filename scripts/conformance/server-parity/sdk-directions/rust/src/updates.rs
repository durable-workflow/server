use std::sync::{
    atomic::{AtomicUsize, Ordering},
    Arc,
};
use std::time::{Duration, Instant};

use durable_workflow::{Client, ConditionWaitOptions, Error, Result, Worker, SDK_VERSION};
use serde_json::{json, Value};

pub async fn run(arguments: &[String], fixture: Value) -> Result<()> {
    let [phase, url, workflow_id, selected_run, _, remaining] = arguments else {
        return Err(Error::WorkerLoop("Invalid SDK update arguments".into()));
    };
    let phases = fixture["sdk_update_direction"]["phase_order"]
        .as_array()
        .ok_or_else(|| Error::WorkerLoop("No SDK update phases".into()))?;
    if !phases
        .iter()
        .any(|value| value.as_str() == Some(phase.as_str()))
    {
        return Err(Error::WorkerLoop("Unknown SDK update phase".into()));
    }
    let seconds: f64 = remaining
        .parse()
        .map_err(|_| Error::WorkerLoop("Invalid SDK update budget".into()))?;
    if !seconds.is_finite() || seconds <= 0.0 {
        return Err(Error::WorkerLoop("SDK update deadline expired".into()));
    }
    let deadline = Instant::now() + Duration::from_secs_f64(seconds);
    let client = Client::builder(url)
        .namespace("default")
        .token(std::env::var("DW_PARITY_TOKEN").ok())
        .build()?;
    let mut result = json!({"pid": std::process::id(), "language": "rust",
        "sdk_version": SDK_VERSION.trim_start_matches("durable-workflow-rust/"),
        "phase": phase, "workflow_id": workflow_id, "run_id": selected_run});
    if phase.starts_with("update-")
        || phase.starts_with("duplicate-pending-")
        || phase.starts_with("duplicate-completed-")
    {
        let index = phase
            .rsplit('-')
            .next()
            .and_then(|value| value.parse::<usize>().ok())
            .and_then(|value| value.checked_sub(1))
            .filter(|value| *value < 2)
            .ok_or_else(|| Error::WorkerLoop("Invalid update index".into()))?;
        let value = if phase.starts_with("update-") {
            fixture["update_values"][index].clone()
        } else {
            fixture["duplicate_update_value"].clone()
        };
        let request_id = format!("{workflow_id}:update:{index}");
        let response = tokio::time::timeout(
            Duration::from_secs_f64(seconds),
            client.update_workflow(
                workflow_id,
                "set_payload",
                json!([value]),
                Some(&request_id),
            ),
        )
        .await
        .map_err(|_| Error::WorkerLoop("SDK update client exceeded fixture budget".into()))?;
        let expects_failure = fixture.get("sdk_update_failure").is_some() && index == 0;
        match response {
            Ok(_) if expects_failure => {
                return Err(Error::WorkerLoop(
                    "Failed update unexpectedly returned a successful result".into(),
                ));
            }
            Ok(value) => result["client_result"] = value,
            Err(Error::Http { status, body }) if expects_failure && status.as_u16() == 422 => {
                let response: Value = serde_json::from_str(&body)?;
                if response["outcome"] != "update_failed" {
                    return Err(Error::Http { status, body });
                }
                result["client_failure"] = json!({"class": "durable_workflow::Error::Http",
                    "message": format!("http {status}: {body}"), "status": status.as_u16(), "body": response});
            }
            Err(error) => return Err(error),
        }
        result["request_id"] = json!(request_id);
        result["update_sent"] = json!(true);
        result["client_finished"] = json!(true);
        println!("{result}");
        return Ok(());
    }
    if phase == "signal" {
        result["client_result"] = client
            .signal_workflow(workflow_id, "payload", json!([fixture["signal_value"]]))
            .await?;
        result["signal_sent"] = json!(true);
        result["sender_finished"] = json!(true);
        println!("{result}");
        return Ok(());
    }
    let workflow_type = fixture["workflow_type"]
        .as_str()
        .ok_or_else(|| Error::WorkerLoop("No SDK update workflow type".into()))?;
    let queue = fixture["sdk_update_direction"]["task_queue"]
        .as_str()
        .ok_or_else(|| Error::WorkerLoop("No SDK update queue".into()))?;
    let worker_id = format!("{workflow_id}:{phase}");
    let workflow_invocations = Arc::new(AtomicUsize::new(0));
    let update_invocations = Arc::new(AtomicUsize::new(0));
    let mut worker = Worker::new(client.clone(), queue)
        .worker_id(&worker_id)
        .poll_timeout(Duration::from_secs(1));
    let invocations = Arc::clone(&workflow_invocations);
    worker.register_workflow(workflow_type, move |ctx, _arguments: Value| {
        invocations.fetch_add(1, Ordering::SeqCst);
        async move {
            let predicate_ctx = ctx.clone();
            ctx.wait_condition(
                ConditionWaitOptions::new(
                    "payload:0",
                    "sha256:b67992a5ee48704611dcf6a181c3ab94b69d0933c44525137210dd2467aec563",
                ),
                move || Ok(!predicate_ctx.signals("payload")?.is_empty()),
            )
            .await?;
            let values =
                ctx.updates("set_payload")?
                    .into_iter()
                    // Accepted inputs include failed handlers. Replay this
                    // application's rejection before deriving its values.
                    .filter(|arguments| {
                        arguments
                            .first()
                            .is_none_or(|value| value["fail_handler"] != true)
                    })
                    .map(|arguments| {
                        arguments.into_iter().next().ok_or_else(|| {
                            Error::WorkerLoop("Missing committed update value".into())
                        })
                    })
                    .collect::<Result<Vec<_>>>()?;
            Ok(json!({"values": values}))
        }
    });
    worker.declare_workflow_signals(workflow_type, &["payload"])?;
    let invocations = Arc::clone(&update_invocations);
    worker.register_update(
        workflow_type,
        "set_payload",
        move |ctx, arguments: Value| {
            invocations.fetch_add(1, Ordering::SeqCst);
            async move {
                let values = arguments
                    .as_array()
                    .filter(|values| values.len() == 1)
                    .ok_or_else(|| Error::WorkerLoop("Update expects one map argument".into()))?;
                if !values[0].is_object() {
                    return Err(Error::WorkerLoop("Update expects a map argument".into()));
                }
                if values[0]["fail_handler"] == true {
                    return Err(Error::WorkerLoop("parity update handler failure λ".into()));
                }
                let applied = ctx
                    .history_events()
                    .iter()
                    .filter(|event| event.event_type == "UpdateApplied")
                    .count()
                    + 1;
                Ok(json!({"value": values[0], "applied": applied}))
            }
        },
    );
    worker.register().await?;
    let execution = async {
        loop {
            if Instant::now() >= deadline {
                return Err(Error::WorkerLoop(
                    "SDK update author exceeded fixture budget".into(),
                ));
            }
            // run_once returns after the normal fenced workflow completion.
            worker.run_once().await?;
            let expected_callback = if phase.starts_with("resumed-") {
                update_invocations.load(Ordering::SeqCst) == 1
            } else {
                workflow_invocations.load(Ordering::SeqCst) == 1
            };
            if !expected_callback {
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
                    .ok_or_else(|| Error::WorkerLoop("No original update run ID".into()));
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
    result["update_invocations"] = json!(update_invocations.load(Ordering::SeqCst));
    result["activity_invocations"] = json!(0);
    result["worker_finished"] = json!(true);
    println!("{result}");
    Ok(())
}
