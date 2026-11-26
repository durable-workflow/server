//! Statement-cache measurement (Server #1372): the #1171 workload shape,
//! 200 workflows of five sequential 20 ms activities plus one signal each,
//! on six workers that long-poll and heartbeat, at the capacity in
//! `DW_BENCH_CACHE` (default: the runtime's). It reports the server's
//! prepare, execute and close counters per workflow and, when the server's
//! `performance_schema` is on, each connection's cached statements.
//!
//! Run against a disposable MySQL or MariaDB:
//! `DW_EXECUTION_MYSQL_URL=... DW_BENCH_CACHE=192 cargo test --test execution hot_path_cache -- --ignored --nocapture`
use super::*;
use sqlx::Connection;
use std::{
    collections::HashMap,
    sync::{Arc, atomic::{AtomicUsize, Ordering}},
    time::Duration,
};

async fn statement_counters(connection: &mut sqlx::MySqlConnection) -> HashMap<String, i64> {
    let rows = sqlx::raw_sql("SHOW GLOBAL STATUS LIKE 'Com_stmt_%'").fetch_all(&mut *connection).await.unwrap();
    rows.iter()
        .map(|row| (sqlx::Row::get::<String, _>(row, 0), sqlx::Row::get::<String, _>(row, 1).parse().unwrap()))
        .collect()
}

#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
#[ignore = "benchmark; requires DW_EXECUTION_MYSQL_URL"]
async fn hot_path_cache_measurement() {
    const ACTIVITIES: usize = 5;
    let workflows: usize = std::env::var("DW_BENCH_WORKFLOWS").ok().and_then(|value| value.parse().ok()).unwrap_or(200);
    let database = TestDatabase::new().await;
    let TestDatabase::MySql { options, .. } = &database else { panic!("the measurement needs DW_EXECUTION_MYSQL_URL") };
    let mut options = options.as_ref().clone();
    let capacity = std::env::var("DW_BENCH_CACHE").ok().and_then(|value| value.parse().ok())
        .unwrap_or(durable_workflow_server::statement_cache::DEFAULT);
    options = options.statement_cache_capacity(capacity);
    let runtime = Runtime::open_mysql(options.clone(), "test-token".into()).await.unwrap();
    let app = router(runtime.clone());
    let signal_contract = json!({"echo":{"signals":["payload"],"signal_contracts":[{"name":"payload","parameters":[{"name":"args",
        "position":0,"type":null,"required":false,"variadic":true,"default_available":false,"default":null,"allows_null":true}]}]}});
    for n in 0..6 {
        let (code, body) = request(&app, "POST", "/api/worker/register", json!({"worker_id":format!("w{n}"),"task_queue":"test",
            "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":["act"],
            "workflow_command_contracts":signal_contract})).await;
        assert_eq!(code, StatusCode::CREATED, "{body}");
    }
    let mut counters = sqlx::MySqlConnection::connect_with(&options.clone().database("")).await.unwrap();
    let before = statement_counters(&mut counters).await;
    let began = std::time::Instant::now();
    let done = Arc::new(AtomicUsize::new(0));
    let mut tasks = Vec::new();
    for producer in 0..2 {
        let app = app.clone();
        tasks.push(tokio::spawn(async move {
            for n in 0..workflows / 2 {
                let id = format!("wf-{producer}-{n}");
                let (code, body) = request(&app, "POST", "/api/workflows", json!({"workflow_id":id,"workflow_type":"echo",
                    "task_queue":"test","input":envelope(Payload::Array(vec![Payload::Long(1)]))})).await;
                assert_eq!(code, StatusCode::CREATED, "{body}");
                let (code, body) = request(&app, "POST", &format!("/api/workflows/{id}/signal/payload"),
                    json!({"input":envelope(Payload::Array(vec![Payload::Long(2)]))})).await;
                assert_eq!(code, StatusCode::ACCEPTED, "{body}");
            }
        }));
    }
    for n in 0..6 {
        let worker = format!("w{n}");
        let (app_heartbeat, worker_heartbeat, done_heartbeat) = (app.clone(), worker.clone(), done.clone());
        // As an SDK does: a registration not seen for 30 s is stale.
        tasks.push(tokio::spawn(async move {
            while done_heartbeat.load(Ordering::SeqCst) < workflows {
                let (code, body) = request(&app_heartbeat, "POST", "/api/worker/heartbeat", json!({"worker_id":worker_heartbeat})).await;
                assert_eq!(code, StatusCode::OK, "{body}");
                tokio::time::sleep(Duration::from_secs(5)).await;
            }
        }));
        for kind in ["workflow", "activity"] {
            let (app, worker, done) = (app.clone(), worker.clone(), done.clone());
            tasks.push(tokio::spawn(async move {
                while done.load(Ordering::SeqCst) < workflows {
                    let (code, body) = request(&app, "POST", &format!("/api/worker/{kind}-tasks/poll"),
                        json!({"worker_id":worker,"task_queue":"test","timeout_seconds":1})).await;
                    assert_eq!(code, StatusCode::OK, "{body}");
                    let task = &body["task"];
                    if task.is_null() {
                        continue;
                    }
                    let id = task["task_id"].as_str().unwrap();
                    if kind == "activity" {
                        tokio::time::sleep(Duration::from_millis(20)).await;
                        let (code, body) = request(&app, "POST", &format!("/api/worker/activity-tasks/{id}/complete"),
                            json!({"lease_owner":worker,"activity_attempt_id":task["activity_attempt_id"],"result":envelope(Payload::Long(7))})).await;
                        assert_eq!(code, StatusCode::OK, "{body}");
                        continue;
                    }
                    let events = task["history_events"].as_array().cloned().unwrap_or_default();
                    let count = |name: &str| events.iter().filter(|event| event["event_type"] == name).count();
                    let (scheduled, completed) = (count("ActivityScheduled"), count("ActivityCompleted"));
                    let commands = if scheduled > completed {
                        // A signal woke the workflow while its activity runs.
                        json!([{"type":"record_side_effect","result":envelope(Payload::Null)}])
                    } else if completed < ACTIVITIES {
                        json!([{"type":"schedule_activity","activity_type":"act","arguments":envelope(Payload::Array(vec![]))}])
                    } else {
                        json!([{"type":"complete_workflow","result":envelope(Payload::Long(9))}])
                    };
                    let finishing = completed >= ACTIVITIES && scheduled == completed;
                    let (code, body) = finish_task(&app, task, commands).await;
                    assert!(code.is_success(), "{code} {body}");
                    if finishing {
                        done.fetch_add(1, Ordering::SeqCst);
                    }
                }
            }));
        }
    }
    tokio::time::timeout(Duration::from_secs(600), async {
        for task in tasks {
            task.await.unwrap();
        }
    })
    .await
    .expect("bounded workload");
    let elapsed = began.elapsed().as_secs_f64();
    let after = statement_counters(&mut counters).await;
    let delta = |name: &str| after.get(name).copied().unwrap_or(0) - before.get(name).copied().unwrap_or(0);
    let (prepared, executed, closed) = (delta("Com_stmt_prepare"), delta("Com_stmt_execute"), delta("Com_stmt_close"));
    // Each cached connection's statements, when performance_schema is on.
    let cached: Vec<i64> = sqlx::query_scalar(
        "SELECT COUNT(*) FROM performance_schema.prepared_statements_instances GROUP BY OWNER_THREAD_ID ORDER BY COUNT(*)")
        .fetch_all(&mut counters).await.unwrap_or_default();
    eprintln!(
        "CACHE capacity={capacity} workflows={workflows} elapsed={elapsed:.1}s prepare={prepared} execute={executed} close={closed} \
         prepares_per_workflow={:.2} closes_per_workflow={:.2} hit_rate={:.4} cached_per_connection={cached:?}",
        prepared as f64 / workflows as f64, closed as f64 / workflows as f64,
        1.0 - prepared as f64 / executed.max(1) as f64,
    );
    drop(counters);
    runtime.close().await;
}
