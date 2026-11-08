//! Server #1057: a new non-waiting query poll is answered "empty" from one
//! read snapshot, without the transition lock, only while every fact of that
//! answer holds at once. Each other state takes the maintained locked path.
// `now` is the query cache's clock: epoch seconds, as the bookkeeping rows use.
use super::{CUTOVER_MARK, EXPIRY_MARK, now, read_arguments};
use crate::codec::{Value as Payload, ValueCodec};
use crate::runtime::{QueryTaskConfig, RuntimeError, store::Store, worker_poll_fence::PollAdmission};
use serde_json::{Value, json};
use std::{collections::BTreeMap, sync::{Arc, atomic::Ordering}, time::{Duration, Instant}};
use tokio::sync::{Semaphore, oneshot};

const WORKER: &str = "empty-worker";
const QUEUE: &str = "empty-queue";
const WORKFLOW: &str = "empty-workflow";

fn input() -> Value {
    json!({"codec":"avro","blob":ValueCodec::new().unwrap().encode(&Payload::Array(vec![])).unwrap()})
}

fn body(id: &str) -> Value {
    json!({"worker_id":WORKER,"task_queue":QUEUE,"poll_request_id":id,"timeout_seconds":0})
}

fn config() -> QueryTaskConfig {
    QueryTaskConfig { timeout_seconds: 10, ..Default::default() }
}

store_methods! {
    /// A query-capable worker and a closed run it can answer queries for.
    async fn empty_fixture(&self) {
        self.register(json!({"worker_id":WORKER,"task_queue":QUEUE,"runtime":"php",
            "supported_workflow_types":["probe"],"supported_activity_types":[],"capabilities":["query_tasks"],
            "workflow_command_contracts":{"probe":{"queries":["probe"],"query_contracts":[{"name":"probe","parameters":[]}]}}}),
            &QueryTaskConfig::default(), Some("1.20")).await.unwrap();
        self.start(json!({"workflow_id":WORKFLOW,"workflow_type":"probe","task_queue":QUEUE,"input":input()}),
            BTreeMap::new(), None, None).await.unwrap();
        let task = self.poll(json!({"worker_id":WORKER,"task_queue":QUEUE,"timeout_seconds":0}), "workflow", "1.20").await.unwrap();
        self.complete_or_checkpoint(task["task"]["task_id"].as_str().unwrap(), json!({"lease_owner":WORKER,
            "workflow_task_attempt":task["task"]["workflow_task_attempt"],
            "commands":[{"type":"complete_workflow","result":input()}]}),
            Arc::new(Semaphore::new(1)), BTreeMap::new(), None,
            crate::runtime::carried_polls::Config { protocol: Some("1.20"), pages: Default::default(), default_wait: 30 },
            Default::default(), None, None).await.unwrap();
        // One locked poll finishes the cutover, starts the receipt sweep's
        // mark and reserves the poll-row sweep.
        let warm = self.poll_query(body("warm"), &config()).await.unwrap();
        assert!(warm["task"].is_null(), "{warm}");
        self.pin_expiry_mark().await;
    }

    /// Keep the receipt sweep idle across a minute boundary during a test.
    async fn pin_expiry_mark(&self) {
        let mut tx = self.begin().await.unwrap();
        Self::put_cache_document(&mut tx, EXPIRY_MARK, &json!({"minute": now().div_euclid(60) + 1000})).await.unwrap();
        tx.commit().await.unwrap();
    }

    async fn put_raw(&self, key: &str, raw: &str) {
        let mut tx = self.begin().await.unwrap();
        Self::query(DB::QUERY_CACHE_PUT).bind(key.to_owned()).bind(raw.to_owned()).bind(i64::MAX / 2)
            .execute(&mut *tx).await.unwrap();
        tx.commit().await.unwrap();
    }

    async fn delete_raw(&self, key: &str) {
        let mut tx = self.begin().await.unwrap();
        Self::query(DB::QUERY_CACHE_DELETE).bind(key.to_owned()).execute(&mut *tx).await.unwrap();
        tx.commit().await.unwrap();
    }

    async fn raw(&self, key: &str) -> Option<Value> {
        let row = Self::query(DB::QUERY_CACHE_GET).bind(key.to_owned()).fetch_optional(&self.pool).await.unwrap()?;
        serde_json::from_str(&DB::string(&row, "value").unwrap()).ok()
    }

    /// Every cache row, to show an answer wrote nothing.
    async fn cache_rows(&self) -> Vec<(String, String, i64)> {
        let mut rows = vec![];
        let mut after = String::new();
        loop {
            let page = Self::query(DB::QUERY_CACHE_PAGE).bind(after.clone()).fetch_all(&self.pool).await.unwrap();
            for row in &page {
                rows.push((DB::string(row, "key").unwrap(), DB::string(row, "value").unwrap(), DB::number(row, "expiration").unwrap()));
            }
            let last = rows.last().map(|(key, ..)| key.clone());
            match last {
                Some(key) if page.len() == 64 => after = key,
                _ => return rows,
            }
        }
    }

    /// One public non-waiting poll and the transition locks it took.
    async fn locks_for(&self, id: &str) -> (crate::runtime::Result<Value>, usize) {
        self.test_transitions.store(0, Ordering::Relaxed);
        let answer = self.poll_query(body(id), &config()).await;
        (answer, self.test_transitions.load(Ordering::Relaxed))
    }

    async fn assert_lock_free_empty(&self, id: &str, why: &str) {
        let before = self.cache_rows().await;
        let (answer, locks) = self.locks_for(id).await;
        let answer = answer.unwrap();
        assert_eq!((answer["task"].clone(), answer["poll_status"].clone()), (Value::Null, json!("empty")), "{why}: {answer}");
        assert_eq!(locks, 0, "{why}: the proven empty answer takes no transition lock");
        assert_eq!(self.cache_rows().await, before, "{why}: the proven empty answer writes nothing");
    }

    async fn assert_forced_lock(&self, id: &str, why: &str) -> Value {
        let (answer, locks) = self.locks_for(id).await;
        let answer = answer.unwrap_or_else(|error| panic!("{why}: {error}"));
        assert!(locks >= 1, "{why}: an unproven fact takes the locked path");
        answer
    }

    /// The proof inputs `poll_query` computes before it reaches the peek.
    async fn proof_inputs(&self, id: &str) -> (String, Option<crate::runtime::worker_poll_fence::WorkerPollFence>, super::poll_requests::PollKeys) {
        let proof = Self::query_worker_proof(&self.pool, self.namespace.as_ref(), WORKER, &config()).await.unwrap().unwrap();
        let fence = match self.admit_worker_poll(WORKER, QUEUE).await.unwrap() {
            PollAdmission::Fenced(fence) => Some(fence),
            _ => None,
        };
        (proof, fence, self.poll_keys(&body(id), id).await.unwrap())
    }

    async fn peek(&self, id: &str, proof: Option<&str>) -> bool {
        let (real, fence, keys) = self.proof_inputs(id).await;
        self.peek_empty_poll(&body(id), &config(), proof.unwrap_or(&real), fence.as_ref(), &keys, std::future::ready(())).await.unwrap()
    }

    async fn pending_query_visible(&self) -> bool {
        // Task rows sort after the poll rows, so read every page.
        self.cache_rows().await.iter().any(|(_, value, _)| {
            serde_json::from_str::<Value>(value)
                .is_ok_and(|value| value["query_name"] == "probe" && value["status"] == "pending")
        })
    }

    async fn wait_for_pending_query(&self) {
        tokio::time::timeout(Duration::from_secs(5), async {
            while !self.pending_query_visible().await {
                tokio::time::sleep(Duration::from_millis(1)).await;
            }
        }).await.expect("the racing query commits");
    }

    async fn submit_query(&self) -> crate::runtime::Result<(axum::http::StatusCode, Value)> {
        let blob = input()["blob"].as_str().unwrap().to_owned();
        let values = read_arguments(Arc::new(Semaphore::new(1)), blob.clone()).await.unwrap();
        self.query_workflow(WORKFLOW, None, "probe", blob, values, &config(), None).await
    }

    async fn answer(&self, claimed: &Value) {
        self.finish_query(claimed["task"]["query_task_id"].as_str().unwrap(), json!({"lease_owner":WORKER,
            "query_task_attempt":claimed["task"]["query_task_attempt"],"result":7}), false, &config()).await.unwrap();
    }

    async fn qualify_lock_free_empty_answer(&self) {
        // A retry of a remembered request replays it under the lock.
        assert!(self.assert_forced_lock("warm", "remembered request").await["task"].is_null());
        self.assert_lock_free_empty("fresh-1", "proven empty").await;
        // Nothing was remembered, so its retry polls again, also lock-free.
        self.assert_lock_free_empty("fresh-1", "retry of an unremembered empty answer").await;
        // A registration that no longer matches the poll's proof.
        assert!(!self.peek("fresh-2", Some("not-this-registration")).await);
        assert!(self.peek("fresh-2", None).await);
    }

    async fn qualify_bookkeeping_forces_lock(&self) {
        let namespace = self.namespace.to_string();
        let open = Self::open_set_key(&namespace);
        // The receipt sweep has whole minutes to retire: locked, and it runs.
        self.put_raw(EXPIRY_MARK, &json!({"minute": now().div_euclid(60) - 5}).to_string()).await;
        self.assert_forced_lock("mark-behind", "receipt sweep due").await;
        let mark = self.raw(EXPIRY_MARK).await.unwrap()["minute"].as_i64().unwrap();
        assert!(mark + 1 >= now().div_euclid(60), "the locked path advanced the mark: {mark}");
        self.delete_raw(EXPIRY_MARK).await;
        self.assert_forced_lock("mark-missing", "receipt sweep never started").await;
        assert!(self.raw(EXPIRY_MARK).await.is_some(), "the locked path started the mark");
        self.pin_expiry_mark().await;
        // An unfinished cutover: locked, and the locked path finishes it.
        self.put_raw(CUTOVER_MARK, &json!({"after":"~","done":false}).to_string()).await;
        self.assert_forced_lock("cutover", "cutover not done").await;
        assert_eq!(self.raw(CUTOVER_MARK).await.unwrap()["done"], true);
        self.put_raw(CUTOVER_MARK, "not json").await;
        assert!(!self.peek("cutover-malformed", None).await, "an unreadable cutover marker proves nothing");
        self.put_raw(CUTOVER_MARK, &json!({"after":"~","done":true}).to_string()).await;
        // Open-set rows this reader does not recognize exactly. The locked
        // path keeps its own reading of each.
        for (raw, why) in [
            ("not json".to_owned(), "unreadable open set"),
            (json!({"namespace":"another","ids":[]}).to_string(), "another namespace's open set"),
            (json!({"namespace":namespace,"ids":[],"extra":true}).to_string(), "unexpected open-set field"),
            (json!({"namespace":namespace,"ids":["not-a-ulid"]}).to_string(), "non-canonical member"),
            (json!({"namespace":namespace,"ids":"none"}).to_string(), "ids not a list"),
        ] {
            self.put_raw(&open, &raw).await;
            let answer = self.assert_forced_lock(&format!("open-{why}"), why).await;
            assert!(answer["task"].is_null(), "{why}: {answer}");
        }
        // Exactly empty, or absent, is proven.
        self.put_raw(&open, &json!({"namespace":namespace,"ids":[]}).to_string()).await;
        self.assert_lock_free_empty("open-empty", "exactly empty open set").await;
        self.delete_raw(&open).await;
        self.assert_lock_free_empty("open-absent", "absent open set").await;
    }

    async fn qualify_request_rows_force_lock(&self) {
        let (_, _, keys) = self.proof_inputs("own").await;
        // This request already has a remembered answer or a pending leader.
        self.put_test_poll_row(&keys, "result", Some("own"), &json!({"query_task_id":null,"poll_status":"empty"}).to_string(), 60).await;
        assert!(!self.peek("own", None).await, "remembered request");
        let (_, _, keys) = self.proof_inputs("own-pending").await;
        self.put_test_poll_row(&keys, "pending", Some("own-pending"), &json!({"pending":true}).to_string(), 60).await;
        assert!(!self.peek("own-pending", None).await, "pending request");
        // The worker's current request is another one that has not answered:
        // it may be about to wait, and only the locked path ends it.
        self.put_test_poll_row(&keys, "current", None, &json!({"poll_request_id":"other"}).to_string(), 60).await;
        self.assert_forced_lock("after-unanswered", "current request without an answer").await;
        self.put_test_poll_row(&keys, "current", None, &json!({"poll_request_id":"other"}).to_string(), 60).await;
        self.put_test_poll_row(&keys, "result", Some("other"), &json!({"query_task_id":null,"poll_status":"empty"}).to_string(), 60).await;
        self.put_test_poll_row(&keys, "pending", Some("other"), &json!({"pending":true}).to_string(), 60).await;
        assert!(!self.peek("after-pending", None).await, "current request still pending");
        // An expired row is unproven, not absent: the locked path deletes it.
        self.put_test_poll_row(&keys, "pending", Some("other"), &json!({"pending":true}).to_string(), -1).await;
        assert!(!self.peek("after-expired-pending", None).await, "an expired row is unproven");
        // A current row this reader cannot read exactly. The locked path
        // overwrites it as before, without a new refusal.
        for (raw, why) in [("not json", "unreadable current row"), ("\"other\"", "current row not an object"),
            (r#"{"poll_request_id":"other","extra":1}"#, "unexpected current field"), (r#"{"poll_request_id":7}"#, "current id not text")] {
            self.put_test_poll_row(&keys, "current", None, raw, 60).await;
            let answer = self.assert_forced_lock(&format!("current-{why}"), why).await;
            assert!(answer["task"].is_null(), "{why}: {answer}");
        }
        // The current row names this very request.
        self.put_test_poll_row(&keys, "current", None, &json!({"poll_request_id":"self-named"}).to_string(), 60).await;
        assert!(!self.peek("self-named", None).await, "current names this request");
    }

    /// A newer request still ends an older waiting one: it is never answered
    /// lock-free while that request is pending.
    async fn qualify_waiting_request_is_superseded(&self) {
        let (_, _, keys) = self.proof_inputs("waiting").await;
        let waiting = async {
            let started = Instant::now();
            let answer = self.poll_query(json!({"worker_id":WORKER,"task_queue":QUEUE,"poll_request_id":"waiting","timeout_seconds":5}), &config()).await.unwrap();
            (answer, started.elapsed())
        };
        let newer = async {
            tokio::time::timeout(Duration::from_secs(3), async {
                while self.test_poll_row(&keys, "pending", Some("waiting")).await.is_none() {
                    tokio::time::sleep(Duration::from_millis(1)).await;
                }
            }).await.expect("the older request starts waiting");
            assert!(!self.peek("newer", None).await, "a pending current request forces the lock");
            let newer = self.poll_query(body("newer"), &config()).await.unwrap();
            assert!(newer["task"].is_null(), "{newer}");
        };
        let ((answer, elapsed), ()) = tokio::join!(waiting, newer);
        assert_eq!(answer["poll_status"], "empty", "{answer}");
        assert!(elapsed < Duration::from_secs(4), "the newer request ended the older wait: {elapsed:?}");
    }

    async fn qualify_registration_mismatch_keeps_its_refusal(&self) {
        let other = json!({"worker_id":WORKER,"task_queue":"another-queue","poll_request_id":"wrong-queue","timeout_seconds":0});
        match self.poll_query(other, &config()).await {
            Err(RuntimeError::Refused { reason: "task_queue_mismatch", .. }) => {}
            answer => panic!("a queue the registration does not hold keeps the locked refusal: {answer:?}"),
        }
    }

    /// The race the issue requires, in both commit orders, ordered by
    /// barriers rather than sleeps.
    async fn qualify_query_arrival_race(&self) {
        // Order 1: the query commits after the snapshot read its first row.
        // The snapshot cannot see it, so "empty" is an answer from before it
        // existed, and nothing written hides it from the next poll.
        let (proof, fence, keys) = self.proof_inputs("race-before").await;
        let (taken_tx, taken_rx) = oneshot::channel::<()>();
        let (go_tx, go_rx) = oneshot::channel::<()>();
        let hook = async move {
            taken_tx.send(()).unwrap();
            go_rx.await.unwrap();
        };
        let (race_body, race_config) = (body("race-before"), config());
        let peek = self.peek_empty_poll(&race_body, &race_config, &proof, fence.as_ref(), &keys, hook);
        let racer = async move {
            taken_rx.await.unwrap();
            let query = self.submit_query();
            let release = async move {
                self.wait_for_pending_query().await;
                go_tx.send(()).unwrap();
            };
            tokio::join!(query, release).0
        };
        let worker = async move {
            let proven = peek.await.unwrap();
            assert!(proven, "the snapshot predates the query, so empty is proven");
            assert!(self.pending_query_visible().await, "the empty answer lost nothing");
            let (claimed, locks) = self.locks_for("race-next").await;
            let claimed = claimed.unwrap();
            assert!(claimed["task"].is_object(), "the next poll leases the query: {claimed}");
            assert!(locks >= 1, "a visible query is claimed under the lock");
            self.answer(&claimed).await;
        };
        let (answer, ()) = tokio::join!(racer, worker);
        let (status, answer) = answer.unwrap();
        assert_eq!((status, answer["result"].clone()), (axum::http::StatusCode::OK, json!(7)));

        // Order 2: the query commits before the snapshot. The open set shows
        // it, so the poll takes the lock and leases it at once.
        let query = self.submit_query();
        let worker = async {
            self.wait_for_pending_query().await;
            assert!(!self.peek("race-after", None).await, "a visible query is never answered empty lock-free");
            let (claimed, locks) = self.locks_for("race-after").await;
            let claimed = claimed.unwrap();
            assert!(claimed["task"].is_object(), "{claimed}");
            assert!(locks >= 1, "a visible query is claimed under the lock");
            self.answer(&claimed).await;
        };
        let (answer, ()) = tokio::join!(query, worker);
        assert_eq!(answer.unwrap().1["result"], 7);
    }
}

#[tokio::test]
async fn sqlite_empty_query_poll_takes_no_lock_only_when_proven() {
    let dir = tempfile::tempdir().unwrap();
    let mut store = Store::<sqlx::Sqlite>::new(crate::runtime::sqlite::open(dir.path().join("empty.sqlite").to_str().unwrap()).await.unwrap());
    // The poll-row sweep stays reserved for the test once warmed.
    store.poll_sweep_config.interval = Duration::from_secs(3600);
    store.empty_fixture().await;
    store.qualify_lock_free_empty_answer().await;
    store.qualify_bookkeeping_forces_lock().await;
    store.qualify_request_rows_force_lock().await;
    store.qualify_waiting_request_is_superseded().await;
    store.qualify_registration_mismatch_keeps_its_refusal().await;
    store.qualify_query_arrival_race().await;
    // A due poll-row sweep is left to the locked path, which takes it.
    store.poll_sweeps = Arc::default();
    store.assert_forced_lock("sweep-due", "poll-row sweep due").await;
    store.assert_lock_free_empty("after-sweep", "sweep reserved again").await;
    store.close().await;
}
