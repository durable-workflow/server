//! A real original loses authority while waiting for bounded codec capacity.
//! Holding this private semaphore is test instrumentation, not a wire clock.
use super::*;
use crate::{
    codec::{Value as Payload, ValueCodec},
    runtime::{QueryTaskConfig, RuntimeError},
};
use serde_json::json;

fn envelope(value: Payload) -> Value {
    json!({"codec":"avro","blob":ValueCodec::new().unwrap().encode(&value).unwrap()})
}

#[tokio::test]
async fn query_outcome_rechecks_original_deadline_after_codec_wait_without_holding_writer() {
    let directory = tempfile::tempdir().unwrap();
    let runtime = Runtime::open(
        directory.path().join("runtime.sqlite").to_str().unwrap(),
        "test-token".into(),
    )
    .await
    .unwrap()
    .with_query_task_config(QueryTaskConfig {
        timeout_seconds: 2,
        lease_timeout_seconds: 2,
        ..Default::default()
    });
    runtime.register(json!({"worker_id":"codec-worker","task_queue":"test","runtime":"php",
        "supported_workflow_types":["echo"],"supported_activity_types":[],"capabilities":["query_tasks"],
        "workflow_command_contracts":{"echo":{"queries":["state"],"query_contracts":[{"name":"state","parameters":[]}]}}})).await.unwrap();
    let started = runtime
        .start(
            json!({"workflow_id":"codec-query-original","workflow_type":"echo","task_queue":"test",
        "input":envelope(Payload::Array(vec![]))}),
        )
        .await
        .unwrap();
    let workflow = runtime
        .poll(
            json!({"worker_id":"codec-worker","task_queue":"test","timeout_seconds":0}),
            "workflow",
            "1.20",
        )
        .await
        .unwrap()["task"]
        .clone();
    runtime.complete_workflow(workflow["task_id"].as_str().unwrap(),json!({"lease_owner":"codec-worker",
        "workflow_task_attempt":workflow["workflow_task_attempt"],"last_history_sequence":workflow["last_history_sequence"],
        "commands":[{"type":"complete_workflow","result":envelope(Payload::Long(7))}]})).await.unwrap();
    let pool = runtime.sqlite_pool().clone();
    let history_before: Vec<(i64,String,String)> = sqlx::query_as("SELECT sequence,event_type,payload FROM workflow_history_events WHERE workflow_run_id=$1 ORDER BY sequence")
        .bind(started["run_id"].as_str().unwrap()).fetch_all(&pool).await.unwrap();
    let mut waiting = {
        let runtime = runtime.clone();
        tokio::spawn(async move {
            runtime
                .query_workflow(
                    "codec-query-original",
                    None,
                    "state",
                    json!({"input":envelope(Payload::Array(vec![]))}),
                )
                .await
        })
    };
    let task = tokio::time::timeout(Duration::from_secs(1), async {
        loop {
            let response = runtime
                .poll_query(
                    json!({"worker_id":"codec-worker","task_queue":"test","timeout_seconds":0}),
                )
                .await
                .unwrap();
            if !response["task"].is_null() {
                break response["task"].clone();
            }
            tokio::time::sleep(Duration::from_millis(10)).await;
        }
    })
    .await
    .unwrap();
    let held = runtime.signal_codec.clone().acquire_owned().await.unwrap();
    let mut complete = {
        let runtime = runtime.clone();
        let task = task.clone();
        tokio::spawn(async move {
            runtime
                .complete_query(
                    task["query_task_id"].as_str().unwrap(),
                    json!({
            "lease_owner":"codec-worker","query_task_attempt":task["query_task_attempt"],
            "result_envelope":envelope(Payload::Long(9007199254740993))}),
                )
                .await
        })
    };
    assert!(
        tokio::time::timeout(Duration::from_millis(50), &mut complete)
            .await
            .is_err(),
        "codec capacity must hold this submission"
    );
    // A separate writer remains usable while the submission waits for the
    // semaphore. The completion cannot hold its preflight transaction open.
    let key = format!(
        "dw-native-query:{}",
        task["query_task_id"].as_str().unwrap()
    );
    tokio::time::timeout(
        Duration::from_millis(250),
        sqlx::query("UPDATE dw_query_cache SET value=value WHERE \"key\"=$1")
            .bind(&key)
            .execute(&pool),
    )
    .await
    .expect("codec wait must release the writer")
    .unwrap();
    let mut writer = pool.acquire().await.unwrap();
    sqlx::query("BEGIN IMMEDIATE")
        .execute(&mut *writer)
        .await
        .unwrap();
    let deadline =
        chrono::DateTime::parse_from_rfc3339(task["query_deadline_at"].as_str().unwrap())
            .unwrap()
            .with_timezone(&chrono::Utc);
    let remaining = (deadline - chrono::Utc::now()).to_std().unwrap_or_default();
    let caller = tokio::time::timeout(remaining + Duration::from_millis(250), &mut waiting)
        .await
        .expect("bookkeeping lock pressure must not extend the original caller deadline")
        .unwrap()
        .unwrap_err();
    assert!(
        matches!(
            caller,
            RuntimeError::Protocol {
                status: StatusCode::GATEWAY_TIMEOUT,
                ..
            }
        ),
        "{caller:?}"
    );
    sqlx::query("ROLLBACK").execute(&mut *writer).await.unwrap();
    drop(writer);
    drop(held);
    let result = complete.await.unwrap().unwrap_err();
    assert!(
        matches!(
            result,
            RuntimeError::Refused {
                status: StatusCode::CONFLICT,
                reason: "query_task_timed_out"
            }
        ),
        "{result:?}"
    );
    let stored: String = sqlx::query_scalar("SELECT value FROM dw_query_cache WHERE \"key\"=$1")
        .bind(&key)
        .fetch_one(&pool)
        .await
        .unwrap();
    let stored: Value = serde_json::from_str(&stored).unwrap();
    assert_eq!(stored["query_admitted_at"], task["query_admitted_at"]);
    assert_eq!(stored["query_deadline_at"], task["query_deadline_at"]);
    assert_eq!(stored["status"], "timed_out");
    assert!(stored.get("result_envelope").is_none());
    assert!(stored.get("terminal_receipt").is_none());
    let history_after: Vec<(i64,String,String)> = sqlx::query_as("SELECT sequence,event_type,payload FROM workflow_history_events WHERE workflow_run_id=$1 ORDER BY sequence")
        .bind(started["run_id"].as_str().unwrap()).fetch_all(&pool).await.unwrap();
    assert_eq!(history_before, history_after);
    runtime.close().await;
}
