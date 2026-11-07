//! The actual cache writers prepare and execute exactly one statement per row.
use super::store::Store;
use serde_json::json;
use std::collections::BTreeMap;

store_methods! {
    pub(super) async fn qualify_transaction_run_tags(&self) {
        let input = crate::codec::ValueCodec::new().unwrap()
            .encode(&crate::codec::Value::Array(vec![])).unwrap();
        let run = self.start(json!({"workflow_id":"run-tags","workflow_type":"run-tags",
            "task_queue":"run-tags","input":{"codec":"avro","blob":input}}),BTreeMap::new(),None,None).await.unwrap();
        let foreign = self.start(json!({"workflow_id":"run-tags-other","workflow_type":"run-tags",
            "task_queue":"run-tags","input":{"codec":"avro","blob":input}}),BTreeMap::new(),None,None).await.unwrap();
        let run_id = run["run_id"].as_str().unwrap();
        Self::query("UPDATE workflow_runs SET priority=7,fairness_key=' raw λ ',fairness_weight=3,compatibility=' build ' WHERE id=$1")
            .bind(run_id).execute(&self.pool).await.unwrap();
        let read = "SELECT namespace,priority,fairness_key,fairness_weight,compatibility FROM workflow_runs WHERE id=$1";
        let mut tx = self.begin().await.unwrap();
        Self::active_run(&mut tx,run_id).await.unwrap();
        let (task,count) = super::sql_counts::measure(Self::create_task(&mut tx,run_id,"run-tags","workflow",json!({"own":"payload"}))).await;
        let task = task.unwrap();
        assert_eq!(count.count(read),0,"the authoritative active-run row supplies its tags");
        assert_eq!(count.total(),1,"only the original INSERT remains");
        let row = Self::query("SELECT namespace,priority,fairness_key,fairness_weight,compatibility,payload FROM workflow_tasks WHERE id=$1")
            .bind(&task).fetch_one(&mut *tx).await.unwrap();
        assert_eq!(DB::string(&row,"namespace").unwrap(),self.namespace.as_ref());
        assert_eq!(DB::number(&row,"priority").unwrap(),7);
        assert_eq!(DB::optional_string(&row,"fairness_key").unwrap().as_deref(),Some(" raw λ "));
        assert_eq!(DB::number(&row,"fairness_weight").unwrap(),3);
        assert_eq!(DB::optional_string(&row,"compatibility").unwrap().as_deref(),Some(" build "));
        assert_eq!(DB::document_row(&row,"payload").unwrap(),json!({"own":"payload"}));
        let saved = tx.poll_savepoint().await.unwrap();
        Self::query("UPDATE workflow_runs SET priority=19 WHERE id=$1").bind(run_id).execute(&mut *tx).await.unwrap();
        Self::active_run(&mut tx,run_id).await.unwrap();
        assert_eq!(tx.run_tags[run_id].priority,19);
        let discarded = Self::create_task(&mut tx,run_id,"run-tags","activity",json!({})).await.unwrap();
        tx.rollback_poll_claim(saved).await.unwrap();
        assert!(tx.run_tags.is_empty(),"a rolled-back view cannot remain cached");
        assert!(Self::query("SELECT id FROM workflow_tasks WHERE id=$1").bind(discarded)
            .fetch_optional(&mut *tx).await.unwrap().is_none());
        let (task,count) = super::sql_counts::measure(Self::create_task(&mut tx,run_id,"run-tags","activity",json!({}))).await;
        let task = task.unwrap();
        assert_eq!(count.count(read),1,"rollback must force the original row read");
        let row = Self::query("SELECT priority FROM workflow_tasks WHERE id=$1").bind(task)
            .fetch_one(&mut *tx).await.unwrap();
        assert_eq!(DB::number(&row,"priority").unwrap(),7);
        let (_,count) = super::sql_counts::measure(Self::create_task(&mut tx,foreign["run_id"].as_str().unwrap(),"run-tags","workflow",json!({}))).await;
        assert_eq!(count.count(read),1,"another run cannot consume these tags");
        tx.rollback().await.unwrap();
        Self::query("UPDATE workflow_runs SET priority=13 WHERE id=$1").bind(run_id).execute(&self.pool).await.unwrap();
        let mut tx = self.begin().await.unwrap();
        let (task,count) = super::sql_counts::measure(Self::create_task(&mut tx,run_id,"run-tags","workflow",json!({}))).await;
        let task = task.unwrap();
        assert_eq!(count.count(read),1,"a fresh transaction starts with no cached view");
        let row = Self::query("SELECT priority FROM workflow_tasks WHERE id=$1").bind(task)
            .fetch_one(&mut *tx).await.unwrap();
        assert_eq!(DB::number(&row,"priority").unwrap(),13);
        assert!(Self::create_task(&mut tx,"missing-run-tags","run-tags","workflow",json!({})).await.is_err());
        tx.rollback().await.unwrap();
        if DB::PHP_CONNECTION == "sqlite" {
            let mut tx = self.begin().await.unwrap();
            Self::active_run(&mut tx,run_id).await.unwrap();
            Self::query("UPDATE workflow_runs SET priority='not-an-integer' WHERE id=$1")
                .bind(run_id).execute(&mut *tx).await.unwrap();
            Self::active_run(&mut tx,run_id).await.unwrap();
            assert!(!tx.run_tags.contains_key(run_id),"a failed seed must remove an earlier cached value");
            assert!(Self::create_task(&mut tx,run_id,"run-tags","workflow",json!({})).await.is_err());
            tx.rollback().await.unwrap();
        }
    }
    pub(super) async fn qualify_summary_projection_bytes(&self) {
        let input = crate::codec::ValueCodec::new().unwrap()
            .encode(&crate::codec::Value::Array(vec![])).unwrap();
        let run = self.start(json!({"workflow_id":"projection-bytes","workflow_type":"projection",
            "task_queue":"projection","input":{"codec":"avro","blob":input}}), BTreeMap::new(), None, None).await.unwrap();
        let run_id = run["run_id"].as_str().unwrap();
        // Keep transport classification far from a live clock boundary while
        // the two reference paths run one after another.
        Self::query("UPDATE workflow_tasks SET available_at='2035-01-01 00:00:00' WHERE workflow_run_id=$1")
            .bind(run_id).execute(&self.pool).await.unwrap();
        let mut tx = self.begin().await.unwrap();
        let (fast, old) = Self::compare_projection_bytes(&mut tx, run_id).await.unwrap();
        assert!(fast < old, "an ordinary ready run must issue fewer statements: {fast}/{old}");
        tx.rollback().await.unwrap();
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
    store.qualify_transaction_run_tags().await;
    store.qualify_query_poll_cache_writes().await;
    store.qualify_workflow_poll_cache_writes().await;
    store.qualify_summary_projection_bytes().await;
    store.qualify_summary_history_pages().await;
    store.close().await;
}
