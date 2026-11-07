//! The actual cache writers prepare and execute exactly one statement per row.
use super::store::Store;
use serde_json::json;
use std::collections::BTreeMap;

store_methods! {
    pub(super) async fn qualify_authored_run_sequences(&self) {
        let input = crate::codec::ValueCodec::new().unwrap()
            .encode(&crate::codec::Value::Array(vec![])).unwrap();
        let run = self.start(json!({"workflow_id":"authored-counter-control","workflow_type":"authored-counter",
            "task_queue":"authored-counter","input":{"codec":"avro","blob":input}}), BTreeMap::new(), None, None).await.unwrap();
        let foreign = self.start(json!({"workflow_id":"authored-counter-foreign","workflow_type":"authored-counter",
            "task_queue":"authored-counter","input":{"codec":"avro","blob":input}}), BTreeMap::new(), None, None).await.unwrap();
        let run_id = run["run_id"].as_str().unwrap();
        // Physical counters, latest logical history, scope spans and redrive
        // all contribute independently. Control command order contributes none.
        let cases = [
            (vec![], vec![], vec![], None::<i64>, 1_i64, vec![], 0_i64),
            (vec![2_i64,7,3], vec![4_i64], vec![6_i64], None, 1, vec![], 7),
            (vec![2], vec![8,4], vec![6], None, 1, vec![], 8),
            (vec![2], vec![4], vec![6,9], None, 1, vec![], 9),
            (vec![0], vec![0], vec![0], Some(0), 1, vec![], 0),
            (vec![], vec![], vec![], Some(37), 1, vec![], 37),
            (vec![], vec![], vec![], None, 1,
                vec![("SignalWaitOpened", json!({"sequence":99})), ("ConditionWaitOpened", json!({"sequence":11}))], 11),
            (vec![], vec![], vec![], None, 1,
                vec![("VersionMarkerRecorded", json!({"sequence":12})), ("MemoUpserted", json!({"sequence":13})),
                    ("SideEffectRecorded", json!({"sequence":14})), ("SearchAttributesUpserted", json!({"sequence":15}))], 15),
            (vec![], vec![], vec![], None, 1,
                vec![("CancellationScopeOpened", json!({"sequence":17,"sequence_span":100})),
                    ("CancellationScopeDelivered", json!({"sequence":20,"sequence_span":4}))], 23),
            (vec![], vec![], vec![], None, 1,
                vec![("CancellationScopeDelivered", json!({"sequence":i64::MAX-1,"sequence_span":4}))], i64::MAX-1),
            (vec![], vec![], vec![], None, 2, vec![], 39),
        ];
        for (activities,timers,children,cancellation,run_number,events,expected) in cases {
            let mut tx = self.begin().await.unwrap();
            Self::query("UPDATE workflow_runs SET run_number=$1,cancellation_delivery_sequence=$2,last_command_sequence=1000000 WHERE id=$3")
                .bind(run_number).bind(DB::optional_integer(cancellation)).bind(run_id).execute(&mut *tx).await.unwrap();
            if run_number > 1 {
                Self::query("UPDATE workflow_history_events SET payload=$1 WHERE workflow_run_id=$2 AND event_type='WorkflowStarted'")
                    .bind(DB::document(&json!({"recovery_kind":"redrive","resume_step_sequence":40})))
                    .bind(run_id).execute(&mut *tx).await.unwrap();
            }
            for (value, owner) in activities.into_iter().map(|n| (n,run_id))
                .chain([(1000000,foreign["run_id"].as_str().unwrap())]) {
                Self::query("INSERT INTO activity_executions(id,workflow_run_id,sequence,activity_class,activity_type,status) VALUES ($1,$2,$3,'counter','counter','completed')")
                    .bind(super::store::id()).bind(owner).bind(value).execute(&mut *tx).await.unwrap();
            }
            for (value,owner) in timers.into_iter().map(|n| (n,run_id))
                .chain([(1000000,foreign["run_id"].as_str().unwrap())]) {
                Self::query("INSERT INTO workflow_run_timers(id,workflow_run_id,sequence,status,delay_seconds,fire_at) VALUES ($1,$2,$3,'fired',1,$4)")
                    .bind(super::store::id()).bind(owner).bind(value).bind(DB::bind_time(super::store::now()))
                    .execute(&mut *tx).await.unwrap();
            }
            for (value,owner,instance) in children.into_iter().map(|n| (n,run_id,run["workflow_id"].as_str().unwrap()))
                .chain([(1000000,foreign["run_id"].as_str().unwrap(),foreign["workflow_id"].as_str().unwrap())]) {
                Self::query("INSERT INTO workflow_child_calls(parent_workflow_run_id,parent_workflow_instance_id,sequence,child_workflow_type,child_workflow_class,scheduled_at) VALUES ($1,$2,$3,'counter','counter',$4)")
                    .bind(owner).bind(instance).bind(value).bind(DB::bind_time(super::store::now()))
                    .execute(&mut *tx).await.unwrap();
            }
            for (kind,payload) in events {
                Self::append(&mut tx,run_id,kind,payload,None,None).await.unwrap();
            }
            let (actual, fast) = super::sql_counts::measure(Self::authored_sequence(&mut tx,run_id)).await;
            let (previous, old) = super::sql_counts::measure(Self::authored_sequence_reference(&mut tx,run_id)).await;
            assert_eq!(actual.unwrap(),expected);
            assert_eq!(previous.unwrap(),expected);
            assert_eq!(fast.count(super::store::AUTHORED_RUN_SQL),1);
            assert_eq!(old.total(),fast.total()+3,"four physical/run reads become one, history reads remain");
            tx.rollback().await.unwrap();
        }
        let mut tx = self.begin().await.unwrap();
        let actual = Self::authored_sequence(&mut tx,"missing-counter-run").await.unwrap_err();
        let previous = Self::authored_sequence_reference(&mut tx,"missing-counter-run").await.unwrap_err();
        assert_eq!(actual.to_string(),previous.to_string());
        tx.rollback().await.unwrap();
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
    store.qualify_authored_run_sequences().await;
    store.qualify_query_poll_cache_writes().await;
    store.qualify_workflow_poll_cache_writes().await;
    store.qualify_summary_projection_bytes().await;
    store.qualify_summary_history_pages().await;
    store.close().await;
}
