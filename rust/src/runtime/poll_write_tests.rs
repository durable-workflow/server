//! Compare stored projections and count the actual cache/projection writes.
use super::store::Store;
use serde_json::json;
use std::collections::BTreeMap;

store_methods! {
    pub(super) async fn qualify_completion_waiting_status(&self) {
        let input = crate::codec::ValueCodec::new().unwrap()
            .encode(&crate::codec::Value::Array(vec![])).unwrap();
        self.register(json!({"worker_id":"waiting-status-worker","task_queue":"waiting-status",
            "runtime":"php","supported_workflow_types":["waiting-status"],"supported_activity_types":["waiting-status"]}),
            &super::QueryTaskConfig::default(),Some("1.20")).await.unwrap();
        let cases = [
            ("terminal",vec![json!({"type":"complete_workflow","result":{"codec":"avro","blob":input}})],false,"completed",0_usize),
            ("activity",vec![json!({"type":"schedule_activity","activity_type":"waiting-status",
                "arguments":{"codec":"avro","blob":input}})],false,"waiting",0),
            ("empty",vec![],false,"waiting",1),
            ("already-waiting",vec![],true,"waiting",0),
        ];
        for (name,commands,waiting,expected,updates) in cases {
            let run = self.start(json!({"workflow_id":format!("waiting-status-{name}"),
                "workflow_type":"waiting-status","task_queue":"waiting-status","input":{"codec":"avro","blob":input}}),
                BTreeMap::new(),None,None).await.unwrap();
            let run_id = run["run_id"].as_str().unwrap();
            let task = self.poll(json!({"worker_id":"waiting-status-worker","task_queue":"waiting-status","timeout_seconds":0}),
                "workflow","1.20").await.unwrap()["task"].clone();
            assert_eq!(task["run_id"],run_id);
            if waiting {
                Self::query("UPDATE workflow_runs SET status='waiting' WHERE id=$1")
                    .bind(run_id).execute(&self.pool).await.unwrap();
            }
            let (answer,count) = super::sql_counts::measure(self.complete_or_checkpoint(
                task["task_id"].as_str().unwrap(),json!({"lease_owner":"waiting-status-worker",
                    "workflow_task_attempt":task["workflow_task_attempt"],"commands":commands}),
                std::sync::Arc::new(tokio::sync::Semaphore::new(1)),BTreeMap::new(),None,
                super::carried_polls::Config {protocol:Some("1.20"),pages:Default::default(),default_wait:30},
                Default::default(),None,None)).await;
            let answer = answer.unwrap();
            assert_eq!(answer["recorded"],true);
            assert_eq!(answer["run_status"],expected);
            assert_eq!(count.count("UPDATE workflow_runs SET status='waiting' WHERE id=$1 AND status IN ('pending','running')"),updates,
                "only pending/running needs the final waiting write: {name}");
            let row = Self::query("SELECT status FROM workflow_runs WHERE id=$1").bind(run_id)
                .fetch_one(&self.pool).await.unwrap();
            assert_eq!(DB::string(&row,"status").unwrap(),expected);
        }
    }
    pub(super) async fn qualify_summary_projection_bytes(&self) {
        let input = crate::codec::ValueCodec::new().unwrap()
            .encode(&crate::codec::Value::Array(vec![])).unwrap();
        let run = self.start(json!({"workflow_id":"projection-bytes","workflow_type":"projection",
            "task_queue":"projection","input":{"codec":"avro","blob":input}}), BTreeMap::new(), None, None).await.unwrap();
        let run_id = run["run_id"].as_str().unwrap();
        self.qualify_projection_census_passes(run_id,run["workflow_id"].as_str().unwrap()).await;
        // Keep transport classification far from a live clock boundary while
        // the two reference paths run one after another.
        Self::query("UPDATE workflow_tasks SET available_at='2035-01-01 00:00:00' WHERE workflow_run_id=$1")
            .bind(run_id).execute(&self.pool).await.unwrap();
        let mut tx = self.begin().await.unwrap();
        let (fast, old) = Self::compare_projection_bytes(&mut tx, run_id).await.unwrap();
        assert!(fast < old, "an ordinary ready run must issue fewer statements: {fast}/{old}");
        tx.rollback().await.unwrap();
        // Both insert and conflict paths compare every stored column with the
        // exact predecessor. Poison old nullable fields to prove they clear.
        for remove in [false,true] {
            let mut tx = self.begin().await.unwrap();
            if remove {
                Self::query("DELETE FROM workflow_run_summaries WHERE id=$1")
                    .bind(run_id).execute(&mut *tx).await.unwrap();
            } else {
                Self::query("UPDATE workflow_run_summaries SET wait_kind='stale',wait_reason='stale',open_wait_id='stale',resume_source_kind='stale',resume_source_id='stale',next_task_id='stale',next_task_type='stale',next_task_status='stale',next_task_at='2000-01-01 00:00:00',next_task_lease_expires_at='2000-01-01 00:00:00' WHERE id=$1")
                    .bind(run_id).execute(&mut *tx).await.unwrap();
            }
            Self::compare_projection_bytes(&mut tx, run_id).await.unwrap();
            tx.rollback().await.unwrap();
        }
        self.qualify_summary_projection_absence(run_id,run["workflow_id"].as_str().unwrap()).await;
        // Every column is compared on the same database/transaction. These
        // are stored-row classifier controls, not producer/admission claims.
        for sql in [
            "UPDATE workflow_tasks SET status='leased',lease_owner='projection',lease_expires_at='2035-01-01 00:00:00' WHERE workflow_run_id=$1",
            "UPDATE workflow_tasks SET status='leased',lease_owner='projection',lease_expires_at='2000-01-01 00:00:00' WHERE workflow_run_id=$1",
            "UPDATE workflow_tasks SET repair_count=1 WHERE workflow_run_id=$1",
            "UPDATE workflow_tasks SET status='completed' WHERE workflow_run_id=$1",
            "UPDATE workflow_runs SET compatibility='unqualified' WHERE id=$1",
            "UPDATE workflow_runs SET status='completed',closed_reason='completed',closed_at='2035-01-01 00:00:00' WHERE id=$1",
        ] {
            let mut tx = self.begin().await.unwrap();
            Self::query(sql).bind(run_id).execute(&mut *tx).await.unwrap();
            Self::compare_projection_bytes(&mut tx, run_id).await.unwrap();
            tx.rollback().await.unwrap();
        }
        let mut tx = self.begin().await.unwrap();
        Self::query("UPDATE workflow_tasks SET status='failed',payload=$1,last_error='retained replay error' WHERE workflow_run_id=$2")
            .bind(DB::document(&json!({"replay_blocked":true,"replay_blocked_reason":"worker_reported_replay_failure"})))
            .bind(run_id).execute(&mut *tx).await.unwrap();
        Self::compare_projection_bytes(&mut tx, run_id).await.unwrap();
        tx.rollback().await.unwrap();
        for (kind, status, source, available) in [
            ("other", "open", "pending", false),
            ("condition", "resolved", "timed_out", false),
            ("signal", "resolved", "timed_out", false),
            ("condition", "resolved", "satisfied", true),
            ("timer", "resolved", "fired", true),
            ("activity", "resolved", "completed", true),
            ("child", "resolved", "completed", false),
            ("update", "resolved", "completed", false),
        ] {
            let mut tx = self.begin().await.unwrap();
            Self::query("INSERT INTO workflow_run_waits(id,workflow_run_id,workflow_instance_id,wait_id,position,kind,status,source_status) VALUES ($1,$2,$3,'census-control',0,$4,$5,$6)")
                .bind(super::store::id()).bind(run_id).bind(run["workflow_id"].as_str().unwrap())
                .bind(kind).bind(status).bind(source).execute(&mut *tx).await.unwrap();
            Self::compare_projection_bytes(&mut tx, run_id).await.unwrap();
            assert_eq!(Self::scalar("SELECT COUNT(*) FROM workflow_run_summaries WHERE id=$1").bind(run_id).fetch_one(&mut *tx).await.unwrap() != 0,
                available, "{kind}/{status}/{source}");
            tx.rollback().await.unwrap();
        }
        self.register(json!({"worker_id":"projection-worker","task_queue":"projection-activity","runtime":"php",
            "supported_workflow_types":["projection-activity"],"supported_activity_types":["projection-activity"]}),
            &super::QueryTaskConfig::default(), Some("1.20")).await.unwrap();
        let input = crate::codec::ValueCodec::new().unwrap()
            .encode(&crate::codec::Value::Array(vec![])).unwrap();
        let activity_run = self.start(json!({"workflow_id":"projection-activity","workflow_type":"projection-activity",
            "task_queue":"projection-activity","input":{"codec":"avro","blob":input}}), BTreeMap::new(), None, None).await.unwrap();
        let task = self.poll(json!({"worker_id":"projection-worker","task_queue":"projection-activity","timeout_seconds":0}), "workflow", "1.20").await.unwrap()["task"].clone();
        self.complete_or_checkpoint(task["task_id"].as_str().unwrap(), json!({"lease_owner":"projection-worker",
            "workflow_task_attempt":task["workflow_task_attempt"],"commands":[{"type":"schedule_activity",
                "activity_type":"projection-activity","arguments":{"codec":"avro","blob":input}}]}),
            std::sync::Arc::new(tokio::sync::Semaphore::new(1)), BTreeMap::new(), None, super::carried_polls::Config { protocol: Some("1.20"), pages: Default::default(), default_wait: 30 }, Default::default(), None, None).await.unwrap();
        let mut tx = self.begin().await.unwrap();
        let run_id = activity_run["run_id"].as_str().unwrap();
        Self::query("UPDATE workflow_tasks SET available_at='2035-01-01 00:00:00' WHERE workflow_run_id=$1")
            .bind(run_id).execute(&mut *tx).await.unwrap();
        Self::compare_projection_bytes(&mut tx, run_id).await.unwrap();
        // A genuine scheduled activity's history still requires its wait,
        // even when the census proves there is no corresponding stored row.
        Self::query("DELETE FROM workflow_run_waits WHERE workflow_run_id=$1 AND kind='activity'")
            .bind(run_id).execute(&mut *tx).await.unwrap();
        Self::compare_projection_bytes(&mut tx, run_id).await.unwrap();
        assert_eq!(Self::scalar("SELECT COUNT(*) FROM workflow_run_summaries WHERE id=$1").bind(run_id).fetch_one(&mut *tx).await.unwrap(), 0);
        tx.rollback().await.unwrap();
    }
}

#[tokio::test]
async fn sqlite_poll_cache_writes_use_one_statement_and_rollback() {
    let dir = tempfile::tempdir().unwrap();
    let store = Store::<sqlx::Sqlite>::new(super::sqlite::open(dir.path().join("writes.sqlite").to_str().unwrap()).await.unwrap());
    store.qualify_completion_waiting_status().await;
    store.qualify_query_poll_cache_writes().await;
    store.qualify_workflow_poll_cache_writes().await;
    store.qualify_summary_projection_bytes().await;
    store.qualify_summary_history_pages().await;
    store.close().await;
}
