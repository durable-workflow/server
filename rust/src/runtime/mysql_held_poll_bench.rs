//! Held-poll benchmark (Server #1372). `polls` held workflow polls across
//! `max(polls / 8, 1)` worker queues re-poll continuously with 2 s waits while
//! four producers start workflows round-robin across those queues, for 15 s.
//! It reports start throughput and latency, read-slot waits (and typed pool
//! timeouts), leased and empty poll answer times, and dispatch latency: from
//! a start's return to the poll that leased its task.
//!
//! Run against a disposable MySQL or MariaDB:
//! `DW_TEST_MYSQL_URL=... cargo test --lib runtime::mysql::held_poll_bench -- --ignored --nocapture --test-threads=1`
#![cfg(test)]
use super::*;
use super::super::{QueryTaskConfig, probe_connections::probe_stats, store::Store};
use serde_json::json;
use sqlx::{ConnectOptions, MySql, migrate::MigrateDatabase};
use std::{collections::HashMap, str::FromStr, sync::{Arc, Mutex}};
use tokio::time::Instant;

const SECONDS: u64 = 15;

fn percentile(values: &mut [f64], p: f64) -> f64 {
    if values.is_empty() {
        return f64::NAN;
    }
    values.sort_by(|a, b| a.partial_cmp(b).unwrap());
    values[((values.len() as f64 - 1.0) * p) as usize]
}

#[derive(Default)]
struct Polls {
    leased: Vec<f64>,
    dispatch: Vec<f64>,
    empty: Vec<f64>,
    errors: HashMap<String, usize>,
}

async fn held_polls(polls: usize) {
    use crate::codec::{Value as Payload, ValueCodec};
    let database = format!("dw_bench_{}", ulid::Ulid::new().to_string().to_lowercase());
    let direct = MySqlConnectOptions::from_str(&std::env::var("DW_TEST_MYSQL_URL").unwrap()).unwrap().database(&database);
    let url = direct.to_url_lossy().to_string();
    MySql::create_database(&url).await.unwrap();
    let store = Arc::new(Store::new(MySqlStorage::open(direct).await.unwrap().into_pool()).with_maintenance_pool());
    let queues = (polls / 8).max(1);
    for n in 0..polls {
        store.register(json!({"worker_id":format!("bench-{n}"),"task_queue":format!("q-{}", n % queues),"runtime":"php",
            "supported_workflow_types":["bench"],"supported_activity_types":[]}), &QueryTaskConfig::default(), Some("1.20")).await.unwrap();
    }
    probe_stats::reset();
    let started_at: Arc<Mutex<HashMap<String, Instant>>> = Arc::default();
    let results: Arc<Mutex<Polls>> = Arc::default();
    let end = Instant::now() + Duration::from_secs(SECONDS);
    let mut tasks = Vec::new();
    for n in 0..polls {
        let (store, results, started_at) = (store.clone(), results.clone(), started_at.clone());
        tasks.push(tokio::spawn(async move {
            let body = json!({"worker_id":format!("bench-{n}"),"task_queue":format!("q-{}", n % queues),"timeout_seconds":2});
            while Instant::now() < end {
                let begun = Instant::now();
                let answer = store.poll(body.clone(), "workflow", "1.20").await;
                let took = begun.elapsed().as_secs_f64() * 1000.0;
                let failed = {
                    let mut results = results.lock().unwrap();
                    match answer {
                        Ok(answer) if answer["poll_status"] == "leased" => {
                            results.leased.push(took);
                            let started = answer["task"]["workflow_id"].as_str().and_then(|id| started_at.lock().unwrap().get(id).copied());
                            if let Some(started) = started {
                                results.dispatch.push(started.elapsed().as_secs_f64() * 1000.0);
                            }
                            false
                        }
                        Ok(_) => {
                            results.empty.push(took);
                            false
                        }
                        Err(error) => {
                            *results.errors.entry(format!("{error:?}").chars().take(60).collect()).or_default() += 1;
                            true
                        }
                    }
                };
                if failed {
                    tokio::time::sleep(Duration::from_millis(200)).await;
                }
            }
        }));
    }
    let starts: Arc<Mutex<Vec<f64>>> = Arc::default();
    let start_errors: Arc<Mutex<HashMap<String, usize>>> = Arc::default();
    for producer in 0..4 {
        let (store, starts, start_errors, started_at) = (store.clone(), starts.clone(), start_errors.clone(), started_at.clone());
        tasks.push(tokio::spawn(async move {
            let input = json!({"codec":"avro","blob":ValueCodec::new().unwrap().encode(&Payload::Array(vec![])).unwrap()});
            let mut n = 0;
            while Instant::now() < end {
                n += 1;
                let id = format!("w-{producer}-{n}");
                let begun = Instant::now();
                let started = store.start(json!({"workflow_id":id,"workflow_type":"bench",
                    "task_queue":format!("q-{}", (producer + n * 4) % queues),"input":input.clone()}),
                    super::super::memos::initial(json!({})).unwrap(), None, None).await;
                match started {
                    Ok(_) => {
                        starts.lock().unwrap().push(begun.elapsed().as_secs_f64() * 1000.0);
                        started_at.lock().unwrap().insert(id, Instant::now());
                    }
                    Err(error) => *start_errors.lock().unwrap().entry(format!("{error:?}").chars().take(60).collect()).or_default() += 1,
                }
            }
        }));
    }
    for task in tasks {
        task.await.unwrap();
    }
    let mut starts = std::mem::take(&mut *starts.lock().unwrap());
    let (mut waits, pool_timeouts) = probe_stats::take();
    let mut results = std::mem::take(&mut *results.lock().unwrap());
    eprintln!(
        "BENCH polls={polls} queues={queues} starts/s={:.1} start_p50={:.0} start_p99={:.0} start_errors={:?} \
         probe_n={} probe_p50={:.1} probe_p99={:.1} probe_max={:.0} probe_pool_timeouts={pool_timeouts} \
         leased_n={} leased_p50={:.0} leased_p99={:.0} dispatch_p50={:.0} dispatch_p99={:.0} \
         empty_n={} empty_p50={:.0} empty_p99={:.0} poll_errors={:?}",
        starts.len() as f64 / SECONDS as f64, percentile(&mut starts, 0.5), percentile(&mut starts, 0.99),
        start_errors.lock().unwrap(),
        waits.len(), percentile(&mut waits, 0.5), percentile(&mut waits, 0.99), percentile(&mut waits, 1.0),
        results.leased.len(), percentile(&mut results.leased, 0.5), percentile(&mut results.leased, 0.99),
        percentile(&mut results.dispatch, 0.5), percentile(&mut results.dispatch, 0.99),
        results.empty.len(), percentile(&mut results.empty, 0.5), percentile(&mut results.empty, 0.99), results.errors,
    );
    store.close().await;
    MySql::drop_database(&url).await.unwrap();
}

#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
#[ignore = "benchmark; requires disposable MySQL/MariaDB"]
async fn bench_8_held_polls() {
    held_polls(8).await
}

#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
#[ignore = "benchmark; requires disposable MySQL/MariaDB"]
async fn bench_64_held_polls() {
    held_polls(64).await
}

#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
#[ignore = "benchmark; requires disposable MySQL/MariaDB"]
async fn bench_256_held_polls() {
    held_polls(256).await
}
