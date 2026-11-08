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
            // A completion needs at least one command. A side effect changes
            // no run status itself, so only the final write can settle it.
            ("side-effect",vec![json!({"type":"record_side_effect","result":{"codec":"avro","blob":input}})],false,"waiting",1),
            ("already-waiting",vec![json!({"type":"record_side_effect","result":{"codec":"avro","blob":input}})],true,"waiting",0),
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
            // These are stored-reader controls, not admission examples for
            // the wait/scope writers or their independent projections.
            let first = Self::scalar("SELECT last_history_sequence FROM workflow_runs WHERE id=$1")
                .bind(run_id).fetch_one(&mut *tx).await.unwrap();
            let count = i64::try_from(events.len()).unwrap();
            for (index,(kind,payload)) in events.into_iter().enumerate() {
                Self::query("INSERT INTO workflow_history_events(id,workflow_run_id,sequence,event_type,payload,recorded_at) VALUES ($1,$2,$3,$4,$5,$6)")
                    .bind(super::store::id()).bind(run_id).bind(first+i64::try_from(index).unwrap()+1)
                    .bind(kind).bind(DB::document(&payload)).bind(DB::bind_time(super::store::now()))
                    .execute(&mut *tx).await.unwrap();
            }
            Self::query("UPDATE workflow_runs SET last_history_sequence=$1 WHERE id=$2")
                .bind(first+count).bind(run_id).execute(&mut *tx).await.unwrap();
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
    store.qualify_authored_run_sequences().await;
    store.qualify_transaction_run_tags().await;
    store.qualify_query_poll_cache_writes().await;
    store.qualify_workflow_poll_cache_writes().await;
    store.qualify_summary_projection_bytes().await;
    store.qualify_summary_history_pages().await;
    store.close().await;
}
