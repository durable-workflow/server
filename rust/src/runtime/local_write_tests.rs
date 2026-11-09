//! Physical batch counts, byte splitting and completion-budget refusals.
use super::{RuntimeError, store::Store};
use serde_json::json;
use std::{collections::BTreeMap, sync::Arc};

store_methods! {
    pub(super) async fn qualify_local_write_batches(&self) {
        use crate::codec::{Value as Payload, ValueCodec};
        let codec = ValueCodec::new().unwrap();
        let empty = codec.encode(&Payload::Array(vec![])).unwrap();
        self.register(json!({"worker_id":"local-batch-worker", "task_queue":"local-batches", "runtime":"php",
            "supported_workflow_types":["local-batches"], "supported_activity_types":[], "capabilities":["local_activities"]}),
            &super::QueryTaskConfig::default(), Some("1.20")).await.unwrap();
        for (name, records, result_bytes) in [("small",40,0), ("split",4,250_000), ("single",1,900_000)] {
            let result = codec.encode(&Payload::String("x".repeat(result_bytes))).unwrap();
            let started = self.start(json!({"workflow_id":format!("local-batch-{name}"), "workflow_type":"local-batches",
                "task_queue":"local-batches", "input":{"codec":"avro","blob":empty}}),
                BTreeMap::new(), None, None).await.unwrap();
            let run_id = started["run_id"].as_str().unwrap();
            let task = self.poll(json!({"worker_id":"local-batch-worker","task_queue":"local-batches","timeout_seconds":0}),
                "workflow", "1.20").await.unwrap()["task"].clone();
            assert_eq!(task["run_id"],run_id);
            let mut commands: Vec<_> = (0..records).map(|index| json!({"type":"record_local_activity",
                "activity_type":format!("local.batch-{index}"), "arguments":{"codec":"avro","blob":empty},
                "result":{"codec":"avro","blob":result}, "outcome":"completed",
                "attempts":[{"attempt_number":1,"outcome":"completed","duration_ms":1}]})).collect();
            commands.push(json!({"type":"complete_workflow","result":{"codec":"avro","blob":empty}}));
            let (answer, statements) = super::sql_counts::measure(self.complete_or_checkpoint(
                task["task_id"].as_str().unwrap(),json!({"lease_owner":"local-batch-worker",
                    "workflow_task_attempt":task["workflow_task_attempt"],"commands":commands}),
                Arc::new(tokio::sync::Semaphore::new(1)),BTreeMap::new(),None,
                super::carried_polls::Config {protocol:Some("1.20"),pages:Default::default(),default_wait:30},
                Default::default(),None,None)).await;
            assert_eq!(answer.unwrap()["recorded"],true);
            let executions = statements.count_prefix("INSERT INTO activity_executions(");
            let attempts = statements.count_prefix("INSERT INTO activity_attempts(");
            let history = statements.count_prefix("INSERT INTO workflow_history_events(");
            assert_eq!(attempts,1,"{name}: small attempt rows stay together");
            assert_eq!(statements.count(DB::HISTORY_BATCH_ADVANCE_SQL),1,"{name}: one range allocation");
            if name == "small" {
                assert_eq!(executions,1,"40 executions share one INSERT");
                assert_eq!(history,3,"120 local events split at 100, followed by the terminal event");
            } else if name == "split" {
                assert!(executions > 1 && executions < records,"large legal results split before the row cap");
                assert!(history > 3,"large event payloads also split before the row cap");
            } else {
                assert_eq!(executions,1,"a legal oversized execution is written alone");
                assert_eq!(history,3,"the oversized terminal activity event is written alone");
            }
            let stored = Self::query("SELECT sequence,status,result FROM activity_executions WHERE workflow_run_id=$1 ORDER BY sequence")
                .bind(run_id).fetch_all(&self.pool).await.unwrap();
            assert_eq!(stored.len(),records);
            for (index,row) in stored.iter().enumerate() {
                assert_eq!(DB::number(row,"sequence").unwrap(),index as i64 + 1);
                assert_eq!(DB::string(row,"status").unwrap(),"completed");
                assert_eq!(DB::optional_string(row,"result").unwrap().as_deref(),Some(result.as_str()));
            }
            let history = Self::query("SELECT sequence,event_type FROM workflow_history_events WHERE workflow_run_id=$1 ORDER BY sequence")
                .bind(run_id).fetch_all(&self.pool).await.unwrap();
            assert_eq!(history.len(),2 + 3 * records + 1);
            assert_eq!(history.iter().map(|row| DB::number(row,"sequence").unwrap()).collect::<Vec<_>>(),
                (1..=history.len() as i64).collect::<Vec<_>>());
            assert_eq!(DB::string(history.last().unwrap(),"event_type").unwrap(),"WorkflowCompleted");
        }
        for (name, limits, reason) in [
            ("events",super::memo_limits::StructuralLimits {history_events:Some(3),..Default::default()},"completion_budget_exceeded"),
            ("rows",super::memo_limits::StructuralLimits {history_rows:Some(7),..Default::default()},"completion_budget_exceeded"),
            ("bytes",super::memo_limits::StructuralLimits {history_bytes:Some(1),..Default::default()},"history_transaction_limit_exceeded"),
        ] {
            let started = self.start(json!({"workflow_id":format!("local-batch-budget-{name}"),"workflow_type":"local-batches",
                "task_queue":"local-batches","input":{"codec":"avro","blob":empty}}),
                BTreeMap::new(),None,None).await.unwrap();
            let run_id = started["run_id"].as_str().unwrap();
            let task = self.poll(json!({"worker_id":"local-batch-worker","task_queue":"local-batches","timeout_seconds":0}),
                "workflow","1.20").await.unwrap()["task"].clone();
            let commands = json!([{"type":"record_local_activity","activity_type":"local.budget",
                "arguments":{"codec":"avro","blob":empty},"result":{"codec":"avro","blob":empty},"outcome":"completed",
                "attempts":[{"attempt_number":1,"outcome":"completed","duration_ms":1}]},
                {"type":"complete_workflow","result":{"codec":"avro","blob":empty}}]);
            let body = json!({"lease_owner":"local-batch-worker","workflow_task_attempt":task["workflow_task_attempt"],"commands":commands});
            let (answer,statements) = super::sql_counts::measure(self.complete_or_checkpoint(
                task["task_id"].as_str().unwrap(),body.clone(),Arc::new(tokio::sync::Semaphore::new(1)),BTreeMap::new(),None,
                super::carried_polls::Config {protocol:Some("1.20"),pages:Default::default(),default_wait:30},limits,None,None)).await;
            let Err(RuntimeError::Protocol {status,response}) = answer else { panic!("{name}: a refused budget"); };
            assert_eq!(status,axum::http::StatusCode::CONFLICT);
            assert_eq!(response["reason"],reason);
            assert_eq!(response["recorded"],false);
            for prefix in ["INSERT INTO activity_executions(","INSERT INTO activity_attempts(","INSERT INTO workflow_history_events("] {
                assert_eq!(statements.count_prefix(prefix),0,"{name}: refusal before the first local write");
            }
            assert_eq!(statements.count(DB::HISTORY_BATCH_ADVANCE_SQL),0);
            let task_row = Self::query("SELECT status FROM workflow_tasks WHERE id=$1")
                .bind(task["task_id"].as_str().unwrap()).fetch_one(&self.pool).await.unwrap();
            assert_eq!(DB::string(&task_row,"status").unwrap(),"leased");
            let row = Self::query("SELECT last_history_sequence FROM workflow_runs WHERE id=$1")
                .bind(run_id).fetch_one(&self.pool).await.unwrap();
            assert_eq!(DB::number(&row,"last_history_sequence").unwrap(),2);
            let accepted = self.complete_or_checkpoint(task["task_id"].as_str().unwrap(),body,
                Arc::new(tokio::sync::Semaphore::new(1)),BTreeMap::new(),None,
                super::carried_polls::Config {protocol:Some("1.20"),pages:Default::default(),default_wait:30},
                Default::default(),None,None).await.unwrap();
            assert_eq!(accepted["recorded"],true,"{name}: the refused claim remains usable");
        }
    }
}

#[tokio::test]
async fn sqlite_local_writes_are_bounded_and_keep_completion_budgets() {
    let directory = tempfile::tempdir().unwrap();
    let store = Store::<sqlx::Sqlite>::new(super::sqlite::open(directory.path().join("local-batches.sqlite").to_str().unwrap()).await.unwrap());
    store.qualify_local_write_batches().await;
    store.close().await;
}
