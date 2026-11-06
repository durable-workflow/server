//! Count actual transition acquisitions through the maintained poll entrypoints.
use super::{QueryTaskConfig, store::Store};
use crate::codec::{Value as Payload, ValueCodec};
use serde_json::{Value, json};
use std::{collections::BTreeMap, sync::{Arc, atomic::Ordering}, time::Duration};
use tokio::sync::Semaphore;

fn input() -> Value {
    json!({"codec":"avro","blob":ValueCodec::new().unwrap().encode(&Payload::Array(vec![])).unwrap()})
}

store_methods! {
    async fn count_worker(&self) {
        self.register(json!({"worker_id":"count-worker","task_queue":"counted","runtime":"php",
            "supported_workflow_types":["counted"],"supported_activity_types":[],"capabilities":["query_tasks"],
            "workflow_command_contracts":{"counted":{"queries":["counted"],"query_contracts":[{"name":"counted","parameters":[]}]}}}),
            &QueryTaskConfig::default(), Some("1.20")).await.unwrap();
    }

    fn count_reset(&self) { self.test_transitions.store(0, Ordering::Relaxed); }
    fn count_get(&self) -> usize { self.test_transitions.load(Ordering::Relaxed) }

    async fn count_start(&self, name: &str) -> Value {
        self.start(json!({"workflow_id":name,"workflow_type":"counted","task_queue":"counted","input":input()}), BTreeMap::new(), None, None).await.unwrap()
    }

    async fn count_poll(&self, id: &str, multiplexed: bool) -> Value {
        let body = json!({"worker_id":"count-worker","task_queue":"counted","poll_request_id":id,"timeout_seconds":0});
        if multiplexed {
            match self.poll_task_kinds(&body, &["workflow","update_validation"], "1.20", Default::default(), 0, 30).await.unwrap() {
                super::update_validation::Leased::Workflow(answer) => answer,
                _ => panic!("fixture contains no validation task"),
            }
        } else {
            self.poll(body, "workflow", "1.20").await.unwrap()
        }
    }

    pub(super) async fn qualify_workflow_poll_transactions(&self) {
        self.count_worker().await;
        for multiplexed in [false,true] {
            let name = format!("counted-workflow-{multiplexed}");
            self.count_start(&name).await;
            self.count_reset();
            let claimed = self.count_poll(&name, multiplexed).await;
            assert!(claimed["task"].is_object());
            assert_eq!(self.count_get(), 1, "one replay/claim probe, multiplexed={multiplexed}");
            self.count_reset();
            let replayed = self.count_poll(&name, multiplexed).await;
            assert_eq!(self.count_get(), 1, "one cached replay");
            assert_eq!(claimed, replayed);
            self.count_reset();
            let empty = self.count_poll(&format!("{name}-empty"), multiplexed).await;
            assert!(empty["task"].is_null());
            // The probe takes one lock. Terminal receipt writing remains a
            // separate transition, preserving the existing duplicate-race seam.
            assert_eq!(self.count_get(), 2, "one probe plus terminal receipt");
            self.count_reset();
            assert_eq!(self.count_poll(&format!("{name}-empty"), multiplexed).await, empty);
            assert_eq!(self.count_get(), 1, "cached empty answer");
            Self::query("UPDATE workflow_tasks SET status='completed' WHERE id=$1")
                .bind(claimed["task"]["task_id"].as_str().unwrap()).execute(&self.pool).await.unwrap();
        }
        // A mismatched history high-watermark is refused after the task UPDATE. Its
        // error must escape without committing that lease, while the prior
        // request-kind binding remains committed as before this refactor.
        for multiplexed in [false,true] {
            let name = format!("counted-partial-claim-{multiplexed}");
            let started = self.count_start(&name).await;
            let original = Self::scalar("SELECT last_history_sequence FROM workflow_runs WHERE id=$1")
                .bind(started["run_id"].as_str().unwrap()).fetch_one(&self.pool).await.unwrap();
            Self::query("UPDATE workflow_runs SET last_history_sequence=$1 WHERE id=$2")
                .bind(original + 1).bind(started["run_id"].as_str().unwrap()).execute(&self.pool).await.unwrap();
            let body = json!({"worker_id":"count-worker","task_queue":"counted","poll_request_id":name,"timeout_seconds":0});
            self.count_reset();
            let (result, statements) = super::sql_counts::measure(async {
                if multiplexed {
                    self.poll_task_kinds(&body, &["workflow","update_validation"], "1.20", Default::default(), 0, 30).await.map(|_| ())
                } else {
                    self.poll(body.clone(), "workflow", "1.20").await.map(|_| ())
                }
            }).await;
            let error = result.unwrap_err();
            assert!(matches!(error, super::RuntimeError::Refused { reason:"history_budget_unavailable", .. }), "{error:?}");
            assert_eq!(statements.count_prefix("UPDATE workflow_tasks SET ready_origin_at="), 1, "the refused claim actually reached the lease write");
            assert_eq!(self.count_get(), 1);
            let row = Self::query("SELECT * FROM workflow_tasks WHERE workflow_run_id=$1")
                .bind(started["run_id"].as_str().unwrap()).fetch_one(&self.pool).await.unwrap();
            assert_eq!(DB::string(&row,"status").unwrap(), "ready");
            assert_eq!(DB::number(&row,"attempt_count").unwrap(), 0);
            assert!(DB::optional_string(&row,"lease_owner").unwrap().is_none());
            assert!(DB::optional_instant(&row,"lease_expires_at").unwrap().is_none());
            let payload = DB::document_row(&row,"payload").unwrap();
            assert!(payload.get("_server_poll_request_id").is_none());
            assert!(payload.get(super::cooperative::CLAIM_KEY).is_none());
            let conflict = if multiplexed {
                self.poll(body.clone(), "workflow", "1.20").await.unwrap_err()
            } else {
                self.poll_task_kinds(&body, &["workflow","update_validation"], "1.20", Default::default(), 0, 30)
                    .await.err().expect("different kinds must conflict")
            };
            match conflict {
                super::RuntimeError::Protocol { response, .. } => assert_eq!(response["reason"], "poll_request_task_kinds_conflict"),
                other => panic!("{other:?}"),
            }
            Self::query("UPDATE workflow_runs SET last_history_sequence=$1 WHERE id=$2")
                .bind(original).bind(started["run_id"].as_str().unwrap()).execute(&self.pool).await.unwrap();
            let retried = self.count_poll(&name, multiplexed).await;
            assert_eq!(retried["task"]["workflow_task_attempt"], 1);
            Self::query("UPDATE workflow_tasks SET status='completed' WHERE workflow_run_id=$1")
                .bind(started["run_id"].as_str().unwrap()).execute(&self.pool).await.unwrap();
        }
        if DB::NAME == "SQLite" {
            let started = self.count_start("counted-exhausted").await;
            Self::query("UPDATE workflow_tasks SET attempt_count=$1 WHERE workflow_run_id=$2")
                .bind(i64::MAX).bind(started["run_id"].as_str().unwrap()).execute(&self.pool).await.unwrap();
            let body = json!({"worker_id":"count-worker","task_queue":"counted","poll_request_id":"counted-refused","timeout_seconds":0});
            self.count_reset();
            let error = self.poll(body.clone(), "workflow", "1.20").await.unwrap_err();
            assert!(matches!(error, super::RuntimeError::Refused { reason:"task_attempt_exhausted", .. }));
            assert_eq!(self.count_get(), 1, "refusal preserves its binding in one transition");
            // A refused claim must not erase the already accepted kind set.
            let error = self.poll_task_kinds(&body, &["workflow","update_validation"], "1.20", Default::default(), 0, 30).await.err().expect("the original binding must refuse different kinds");
            match error {
                super::RuntimeError::Protocol { response, .. } => assert_eq!(response["reason"], "poll_request_task_kinds_conflict"),
                other => panic!("{other:?}"),
            }
            Self::query("UPDATE workflow_tasks SET status='completed' WHERE workflow_run_id=$1")
                .bind(started["run_id"].as_str().unwrap()).execute(&self.pool).await.unwrap();
        }
    }

    pub(super) async fn qualify_query_poll_transactions(&self) {
        self.count_worker().await;
        let workflow_id = "counted-query-workflow";
        self.count_start(workflow_id).await;
        let workflow = self.poll(json!({"worker_id":"count-worker","task_queue":"counted","timeout_seconds":0}), "workflow", "1.20").await.unwrap();
        self.complete_or_checkpoint(workflow["task"]["task_id"].as_str().unwrap(), json!({"lease_owner":"count-worker",
            "workflow_task_attempt":workflow["task"]["workflow_task_attempt"],
            "commands":[{"type":"complete_workflow","result":input()}]}),
            Arc::new(Semaphore::new(1)), BTreeMap::new(), None, Some("1.20"), Default::default(), None, None).await.unwrap();
        let config = QueryTaskConfig { timeout_seconds: 10, ..Default::default() };
        // A positive-wait query still uses the existing coordinated path but
        // finds a task immediately. It is the unchanged five-lock control.
        for timeout in [0,1] {
            let blob = input()["blob"].as_str().unwrap().to_owned();
            let values = super::queries::read_arguments(Arc::new(Semaphore::new(1)), blob.clone()).await.unwrap();
            let original = self.query_workflow(workflow_id, None, "counted", blob, values, &config, None);
            let worker = async {
                tokio::time::timeout(Duration::from_secs(5), async {
                    loop {
                        let rows = Self::query(DB::QUERY_CACHE_PAGE).bind("").fetch_all(&self.pool).await.unwrap();
                        if rows.iter().any(|row| {
                            let value: Value = serde_json::from_str(&DB::string(row, "value").unwrap()).unwrap();
                            value["query_name"] == "counted" && value["status"] == "pending"
                        }) { break; }
                        tokio::time::sleep(Duration::from_millis(1)).await;
                    }
                }).await.unwrap();
                let body = json!({"worker_id":"count-worker","task_queue":"counted","timeout_seconds":timeout,"poll_request_id":format!("counted-query-{timeout}")});
                self.count_reset();
                let (claimed, statements) = super::sql_counts::measure(self.poll_query(body.clone(), &config)).await;
                let claimed = claimed.unwrap();
                assert_eq!(statements.count(super::queries::QUERY_WORKER_PROOF_SQL), if timeout == 0 {2} else {3},
                    "fresh immediate admission reuses its proof under the same lock");
                if timeout == 0 {
                    assert_eq!(statements.count(DB::QUERY_CACHE_PUT), 2, "current request and remembered answer each use one write");
                }
                assert!(claimed["task"].is_object(), "{claimed}");
                assert_eq!(self.count_get(), if timeout == 0 {1} else {5});
                if timeout == 0 {
                    self.count_reset();
                    let mut repeated = self.poll_query(body.clone(), &config).await.unwrap();
                    assert_eq!(self.count_get(), 1);
                    repeated["task"]["server_time"] = claimed["task"]["server_time"].clone();
                    assert_eq!(repeated, claimed);
                    self.count_reset();
                    let empty = self.poll_query(json!({"poll_request_id":"counted-query-other","worker_id":"count-worker","task_queue":"counted","timeout_seconds":0}), &config).await.unwrap();
                    assert!(empty["task"].is_null());
                    assert_eq!(self.count_get(), 1);
                }
                self.finish_query(claimed["task"]["query_task_id"].as_str().unwrap(), json!({"lease_owner":"count-worker",
                    "query_task_attempt":claimed["task"]["query_task_attempt"],"result":7}), false, &config).await.unwrap();
            };
            let (answer, ()) = tokio::join!(original, worker);
            let (status, answer) = answer.unwrap();
            assert_eq!(status, axum::http::StatusCode::OK);
            assert_eq!(answer["result"], 7);
        }
    }
}

#[tokio::test]
async fn sqlite_workflow_and_query_poll_transaction_counts() {
    let dir = tempfile::tempdir().unwrap();
    let store = Store::<sqlx::Sqlite>::new(super::sqlite::open(dir.path().join("counts.sqlite").to_str().unwrap()).await.unwrap());
    store.qualify_workflow_poll_transactions().await;
    store.qualify_query_poll_transactions().await;
    store.close().await;
}
