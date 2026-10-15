//! Normal published SDK Worker/client; only namespace setup uses plain HTTP.
use durable_workflow::{Client, Error, Worker, WorkerProtocolVersion, SDK_VERSION};
use serde_json::{json, Value};
use std::{
    path::Path,
    sync::{
        atomic::{AtomicUsize, Ordering},
        Arc,
    },
    time::{Duration, Instant},
};

type Result<T> = std::result::Result<T, Box<dyn std::error::Error + Send + Sync>>;
const TYPE: &str = "parity.query-authority.rust.v1";

#[derive(Default)]
struct Counters {
    calls: AtomicUsize,
    returned: AtomicUsize,
    cancelled: AtomicUsize,
}
struct Callback {
    counters: Arc<Counters>,
    returned: bool,
}
impl Drop for Callback {
    fn drop(&mut self) {
        if !self.returned {
            self.counters.cancelled.fetch_add(1, Ordering::SeqCst);
        }
    }
}
fn expected() -> Value {
    json!({"long":9223372036854775807_i64,"nil":null,"text":"rangeλ"})
}
fn events(path: &Path) -> Vec<Value> {
    let text = std::fs::read_to_string(path).unwrap_or_default();
    let complete = text
        .rsplit_once('\n')
        .map(|(prefix, _)| prefix)
        .unwrap_or("");
    complete
        .lines()
        .filter(|line| !line.is_empty())
        .map(|line| serde_json::from_str(line).unwrap())
        .collect()
}
fn named<'a>(events: &'a [Value], event: &str) -> Vec<&'a Value> {
    events
        .iter()
        .filter(|value| value["event"] == event)
        .collect()
}
async fn wait(until: Instant, mut condition: impl FnMut() -> bool) -> Result<()> {
    while !condition() {
        if Instant::now() >= until {
            return Err("Original thirty-second observation expired".into());
        }
        tokio::time::sleep(Duration::from_millis(20)).await;
    }
    Ok(())
}

#[tokio::main(flavor = "multi_thread", worker_threads = 2)]
async fn main() -> Result<()> {
    let arguments: Vec<String> = std::env::args().skip(1).collect();
    let [url, mode, protocol, journal] = arguments.as_slice() else {
        return Err("Expected URL MODE PROTOCOL JOURNAL".into());
    };
    let profile: Value =
        serde_json::from_slice(&std::fs::read(std::env::var("DW_PARITY_QUERY_PROFILE")?)?)?;
    assert_eq!(
        SDK_VERSION,
        format!(
            "durable-workflow-rust/{}",
            profile["sdk_rust"]["version"].as_str().unwrap()
        )
    );
    assert!(matches!(
        mode.as_str(),
        "timely" | "late-poll" | "late-handler-async" | "late-handler-sync"
    ));
    assert!(protocol == "1.20" || protocol == "1.19" && mode == "timely");
    let started = Instant::now();
    let until = started + Duration::from_secs(30);
    let namespace = format!("query-rust-{}", durable_workflow::Uuid::new_v4().simple());
    let token = std::env::var("DW_PARITY_TOKEN")?;
    let response = reqwest::Client::new()
        .post(format!("{url}/api/namespaces"))
        .bearer_auth(&token)
        .header("X-Namespace", "default")
        .header("X-Durable-Workflow-Control-Plane-Version", "2")
        .json(&json!({"name":namespace}))
        .send()
        .await?;
    assert_eq!(response.status(), reqwest::StatusCode::CREATED);
    let mut builder = Client::builder(url)
        .namespace(&namespace)
        .token(Some(token));
    if protocol == "1.19" {
        builder = builder.worker_protocol_version(WorkerProtocolVersion::V119);
    }
    let client = builder.build()?;
    let counters = Arc::new(Counters::default());
    let mut worker = Worker::new(client.clone(), &namespace)
        .worker_id(&namespace)
        .poll_timeout(Duration::from_secs(8))
        .require_query_task_authority(protocol == "1.20");
    worker.register_workflow(TYPE, |context, arguments| async move {
        if arguments != json!([7]) {
            return Err(Error::WorkerLoop("Original input changed".into()));
        }
        context.sleep(Duration::from_secs(60)).await?;
        Ok(json!(7))
    });
    let callback_counters = Arc::clone(&counters);
    let callback_mode = mode.clone();
    worker.register_query(TYPE, "value", move |context, arguments| {
        let counters = Arc::clone(&callback_counters);
        let mode = callback_mode.clone();
        async move {
            counters.calls.fetch_add(1, Ordering::SeqCst);
            let mut callback = Callback {
                counters: Arc::clone(&counters),
                returned: false,
            };
            if arguments != json!([]) || context.workflow_input() != &json!([7]) {
                return Err(Error::WorkerLoop("Original query snapshot changed".into()));
            }
            if mode == "late-handler-async" {
                tokio::time::sleep(Duration::from_secs(6)).await;
            }
            if mode == "late-handler-sync" {
                std::thread::sleep(Duration::from_secs(6));
            }
            callback.returned = true;
            counters.returned.fetch_add(1, Ordering::SeqCst);
            Ok(expected())
        }
    });
    let (shutdown, stopped) = tokio::sync::oneshot::channel();
    let runner = tokio::spawn(async move {
        worker
            .run_until(async {
                let _ = stopped.await;
            })
            .await
    });
    let outcome: Result<String> = async {
        wait(until, || {
            named(&events(Path::new(journal)), "registered")
                .iter()
                .any(|event| event["status"] == 201)
        })
        .await?;
        client
            .start_workflow(TYPE, &namespace, &namespace, json!([7]))
            .await?;
        let run_id = loop {
            let run = client.describe_workflow(&namespace).await?;
            if run.status.as_deref() == Some("waiting") {
                break run.run_id.ok_or("Original run ID missing")?;
            }
            if Instant::now() >= until {
                return Err("Original timer admission expired".into());
            }
            tokio::time::sleep(Duration::from_millis(20)).await;
        };
        let query = client.query_workflow(&namespace, "value", json!([])).await;
        if mode == "timely" {
            assert_eq!(query?, expected());
        } else {
            match query {
                Err(Error::QueryFailed(failure)) => {
                    assert_eq!(failure.status, 504);
                    assert_eq!(failure.reason, "query_worker_execution_timeout");
                }
                other => {
                    return Err(format!(
                        "Expired original did not retain its caller timeout: {other:?}"
                    )
                    .into())
                }
            }
            wait(until, || match mode.as_str() {
                "late-poll" => !named(&events(Path::new(journal)), "delivered").is_empty(),
                "late-handler-async" => counters.cancelled.load(Ordering::SeqCst) == 1,
                "late-handler-sync" => counters.returned.load(Ordering::SeqCst) == 1,
                _ => false,
            })
            .await?;
        }
        Ok(run_id)
    }
    .await;
    let _ = shutdown.send(());
    tokio::time::timeout(until.saturating_duration_since(Instant::now()), runner).await???;
    let run_id = outcome?;
    let observed = events(Path::new(journal));
    assert!(named(&observed, "observer-error").is_empty());
    assert_eq!(named(&observed, "claim").len(), 1);
    assert_eq!(named(&observed, "delivered").len(), 1);
    assert_eq!(named(&observed, "query-result").len(), 1);
    let submissions = if mode == "timely" { 1 } else { 0 };
    assert_eq!(named(&observed, "submission").len(), submissions);
    assert_eq!(named(&observed, "acknowledgement").len(), submissions);
    assert_eq!(
        named(&observed, "deregistered").last().unwrap()["status"],
        200
    );
    assert_eq!(
        counters.calls.load(Ordering::SeqCst),
        usize::from(mode != "late-poll")
    );
    assert_eq!(
        counters.returned.load(Ordering::SeqCst),
        usize::from(mode == "timely" || mode == "late-handler-sync")
    );
    assert_eq!(
        counters.cancelled.load(Ordering::SeqCst),
        usize::from(mode == "late-handler-async")
    );
    let claim_event = named(&observed, "claim")[0];
    let claim = &claim_event["claim"];
    assert_eq!(claim_event["protocol"], protocol.as_str());
    let status = named(&observed, "status");
    assert_eq!(!status.is_empty(), protocol == "1.20");
    for observation in &status {
        for field in [
            "query_task_id",
            "query_task_attempt",
            "lease_owner",
            "query_admitted_at",
            "query_deadline_at",
            "lease_expires_at",
        ] {
            assert_eq!(observation["observation"][field], claim[field], "{field}");
        }
    }
    assert!(Instant::now() < until);
    println!(
        "{}",
        json!({"outcome":"pass","language":"rust","mode":mode,"protocol":protocol,
        "sdk_version":SDK_VERSION,"namespace":namespace,"run_id":run_id,"claim":claim,
        "calls":counters.calls.load(Ordering::SeqCst),"returned":counters.returned.load(Ordering::SeqCst),
        "cancelled":counters.cancelled.load(Ordering::SeqCst),"status_reads":status.len(),
        "history_sha256":named(&observed,"query-result")[0]["history_sha256"],
        "original_budget_seconds":30,"elapsed_seconds":started.elapsed().as_secs_f64(),"worker_exit":0})
    );
    Ok(())
}
