//! Server #1181: the v2 accept path's statements do not depend on the
//! run's history length, and it reads no history page.
use super::{continuation_v2, store::Store};
use serde_json::{Value, json};
use std::{collections::BTreeMap, sync::Arc};

store_methods! {
    /// One v2 step after `prior` side-effect events, measured. The worker
    /// and its queue are named after the workflow, so each step's carried
    /// activity lease is its own.
    async fn measured_v2_step(&self, workflow: &str, prior: usize) -> (Value, super::sql_counts::Counts) {
        use crate::codec::{Value as Payload, ValueCodec};
        let entry = json!({"supported":true,"minimum_protocol_version":"1.18"});
        self.register(json!({"worker_id":workflow,"task_queue":workflow,"runtime":"php","build_id":"build-1",
            "supported_workflow_types":["echo"],"supported_activity_types":["activity"],
            "capabilities":["sticky_execution","sticky_history_suffix"],
            "capability_manifest":{"local_activities":{"supported":false,"minimum_protocol_version":"1.18"},
                "worker_sessions":{"supported":false,"minimum_protocol_version":"1.18"},"sticky_execution":entry,
                "sticky_history_suffix":{"supported":true,"minimum_protocol_version":"1.18","version":1}}}),
            &super::QueryTaskConfig::default(), Some("1.20")).await.unwrap();
        let codec = ValueCodec::new().unwrap();
        let empty = json!({"codec":"avro","blob":codec.encode(&Payload::Array(vec![])).unwrap()});
        let polling = super::carried_polls::Config { protocol: Some("1.20"), pages: Default::default(), default_wait: 30 };
        self.start(json!({"workflow_id":workflow,"workflow_type":"echo","task_queue":workflow,"input":empty}),
            BTreeMap::new(), None, None).await.unwrap();
        let task = self.poll(json!({"worker_id":workflow,"task_queue":workflow,"timeout_seconds":0}), "workflow", "1.20")
            .await.unwrap()["task"].clone();
        assert_eq!(task["workflow_id"], workflow);
        let mut commands: Vec<Value> = (0..prior).map(|_| json!({"type":"record_side_effect","result":empty})).collect();
        commands.push(json!({"type":"schedule_activity","activity_type":"activity","arguments":empty}));
        let sticky = |task: &Value, through: &Value| json!({"worker_id":workflow,"workflow_id":task["workflow_id"],
            "run_id":task["run_id"],"build_id":"build-1","ttl_seconds":300,"history_last_sequence":through});
        let seeded = self.complete_or_checkpoint(task["task_id"].as_str().unwrap(), json!({"lease_owner":workflow,
            "workflow_task_attempt":task["workflow_task_attempt"],"commands":commands,
            "sticky_cache":sticky(&task, &task["history_last_sequence"]),"continuation_tail":{"version":2},
            "activity_poll":{"worker_id":workflow,"task_queue":workflow,"poll_request_id":format!("{workflow}-seed"),"timeout_seconds":0}}),
            Arc::new(tokio::sync::Semaphore::new(1)), BTreeMap::new(), None, polling, Default::default(), None, None).await.unwrap();
        let activity = seeded["activity_poll"]["answer"]["task"].clone();
        // A long seed's tail is over its budget; the worker's cache is the
        // run's whole history either way.
        let last = Self::query("SELECT id,sequence FROM workflow_history_events WHERE workflow_run_id=$1 ORDER BY sequence DESC LIMIT 1")
            .bind(activity["run_id"].as_str().unwrap()).fetch_one(&self.pool).await.unwrap();
        let through = json!(DB::number(&last, "sequence").unwrap());
        let continuation = json!({"version":2,"history_through_sequence":through,
            "history_last_event_id":DB::string(&last, "id").unwrap(),
            "sticky_cache":sticky(&activity, &through),"commands":[{"type":"schedule_activity","activity_type":"activity","arguments":empty}]});
        let request = continuation_v2::parse(Some(&continuation)).unwrap();
        let body = json!({"lease_owner":workflow,"activity_attempt_id":activity["activity_attempt_id"],"result":empty,
            "workflow_poll":{"worker_id":workflow,"task_queue":workflow,"poll_request_id":format!("{workflow}-workflow"),"timeout_seconds":0},
            "activity_poll":{"worker_id":workflow,"task_queue":workflow,"poll_request_id":format!("{workflow}-activity"),"timeout_seconds":0},
            "continuation":continuation});
        let mut command_body = json!({"lease_owner":workflow,"workflow_task_attempt":1,"commands":request.commands,
            "sticky_cache":request.sticky_cache,"continuation_tail":{"version":2}});
        command_body["activity_poll"] = body["activity_poll"].clone();
        let result = super::envelope(&body, "result").unwrap();
        let (answer, counts) = super::sql_counts::measure(self.complete_activity_continuation_v2(activity["task_id"].as_str().unwrap(),
            body, request, None, result, command_body, BTreeMap::new(), Arc::new(tokio::sync::Semaphore::new(1)), None, polling,
            Default::default())).await;
        let answer = answer.unwrap();
        assert_eq!(answer["continuation"]["outcome"], "accepted", "{answer}");
        assert_eq!(answer["activity_poll"]["leased"], true, "{answer}");
        (answer, counts)
    }

    pub(super) async fn qualify_v2_statement_counts(&self) {
        let (_, short) = self.measured_v2_step("v2-counts-short", 0).await;
        let (_, long) = self.measured_v2_step("v2-counts-long", 300).await;
        // Shared maintenance outside the v2 path: every transition's commit
        // projects the run summary from its history in 16-event pages, a
        // wait-position pass moves waits that opened in the same millisecond,
        // and a throttled sweep drops expired poll answers.
        let shared = [DB::SUMMARY_HISTORY_SQL, DB::WAIT_POSITION_MOVES, DB::WORKFLOW_POLL_EXPIRED,
            "UPDATE workflow_run_waits SET position=$1 WHERE id=$2 AND workflow_run_id=$3"];
        let entries = |counts: &super::sql_counts::Counts| counts.entries().into_iter()
            .filter(|(sql, _)| !shared.contains(sql))
            .map(|(sql, count)| format!("{count} {}", &sql[..sql.len().min(160)])).collect::<Vec<_>>();
        for counts in [&short, &long] {
            assert_eq!(counts.count(DB::HISTORY_PAGE), 0, "the resumed task's claim presents no history: {:#?}", entries(counts));
            assert_eq!(counts.count("SELECT event_type,payload FROM workflow_history_events WHERE workflow_run_id=$1 AND sequence>$2 ORDER BY sequence"), 0,
                "the nested meter charges the remembered result");
            assert_eq!(counts.count("SELECT status,last_heartbeat_at FROM workflow_worker_registrations WHERE namespace=$1 AND worker_id=$2"), 0,
                "the nested guard reuses the carried claim's fenced owner");
        }
        let (short, long) = (entries(&short), entries(&long));
        let differing: Vec<_> = short.iter().filter(|entry| !long.contains(entry))
            .chain(long.iter().filter(|entry| !short.contains(entry))).collect();
        assert!(differing.is_empty(), "the same statements whatever the history length: {differing:#?}");
    }

    /// Every history write updates the remembered sequence, a remembering
    /// transaction keeps the events it wrote, and `rollback_continuation`
    /// rewinds both.
    pub(super) async fn qualify_remembered_history(&self) {
        use crate::codec::{Value as Payload, ValueCodec};
        let empty = json!({"codec":"avro","blob":ValueCodec::new().unwrap().encode(&Payload::Array(vec![])).unwrap()});
        let started = self.start(json!({"workflow_id":"remembered","workflow_type":"echo","task_queue":"remembered","input":empty}),
            BTreeMap::new(), None, None).await.unwrap();
        let run_id = started["run_id"].as_str().unwrap();
        let mut tx = self.begin().await.unwrap();
        let row = Self::query("SELECT last_history_sequence FROM workflow_runs WHERE id=$1").bind(run_id).fetch_one(&mut *tx).await.unwrap();
        let start = DB::number(&row, "last_history_sequence").unwrap();
        assert!(tx.history_sequences.is_empty(), "nothing is remembered before a write");
        Self::append(&mut tx, run_id, "SideEffectRecorded", json!({"result":null}), None, None).await.unwrap();
        assert_eq!(tx.history_sequences.get(run_id), Some(&(start + 1)));
        let saved = tx.continuation_savepoint().await.unwrap();
        tx.remembered_events = Some(Vec::new());
        Self::append(&mut tx, run_id, "SideEffectRecorded", json!({"result":"remembered"}), None, None).await.unwrap();
        // A batch allocation, as local activities write, is remembered too.
        Self::allocate_history_sequences(&mut tx, run_id, 2).await.unwrap();
        assert_eq!(tx.history_sequences.get(run_id), Some(&(start + 4)));
        let remembered = tx.remembered_events.as_ref().unwrap();
        assert_eq!(remembered.len(), 1);
        assert_eq!(remembered[0].0, "SideEffectRecorded");
        assert_eq!(remembered[0].1["result"], "remembered");
        tx.rollback_continuation(saved).await.unwrap();
        assert_eq!(tx.history_sequences.get(run_id), Some(&(start + 1)), "the savepoint rewinds the sequence");
        assert!(tx.remembered_events.is_none(), "and the remembered events");
        let row = Self::query("SELECT last_history_sequence FROM workflow_runs WHERE id=$1").bind(run_id).fetch_one(&mut *tx).await.unwrap();
        assert_eq!(DB::number(&row, "last_history_sequence").unwrap(), start + 1, "matching the database");
        tx.rollback().await.unwrap();
    }
}

#[tokio::test]
async fn sqlite_remembered_history_follows_every_write_and_rewinds() {
    let directory = tempfile::tempdir().unwrap();
    let store = Store::<sqlx::Sqlite>::new(super::sqlite::open(directory.path().join("remembered.sqlite").to_str().unwrap()).await.unwrap());
    store.qualify_remembered_history().await;
    store.close().await;
}

#[tokio::test]
async fn sqlite_v2_statements_do_not_depend_on_history_length() {
    let directory = tempfile::tempdir().unwrap();
    let store = Store::<sqlx::Sqlite>::new(super::sqlite::open(directory.path().join("v2-counts.sqlite").to_str().unwrap()).await.unwrap());
    store.qualify_v2_statement_counts().await;
    store.close().await;
}
