//! Count actual transition acquisitions through the maintained poll entrypoints.
use super::{QueryTaskConfig, store::Store};
use crate::codec::{Value as Payload, ValueCodec};
use serde_json::{Value, json};
use std::{collections::BTreeMap, sync::{Arc, atomic::Ordering}, time::Duration};
use tokio::sync::Semaphore;
#[cfg(debug_assertions)]
use futures_util::FutureExt;

/// Catches the panic `future` is expected to raise, without its panic report.
///
/// The claimed-history negative control trips a debug assertion on purpose
/// and asserts its message. Reported normally, that caught panic printed
/// `thread ... panicked at src/runtime/store.rs ... claimed run history
/// sequence changed before its first page` in every passing run, which read
/// as a failure. Only a panic whose message contains `expected`, raised while
/// this future itself is polled on this thread, is silenced; every other
/// panic still reaches the previous hook with its location.
#[cfg(debug_assertions)]
async fn expected_panic<F: std::future::Future>(expected: &'static str, future: F) -> std::thread::Result<F::Output> {
    use std::cell::Cell;
    thread_local! {
        static EXPECTED: Cell<Option<&'static str>> = const { Cell::new(None) };
    }
    struct Polling(Option<&'static str>);
    impl Drop for Polling {
        fn drop(&mut self) {
            EXPECTED.with(|current| current.set(self.0));
        }
    }
    static HOOK: std::sync::Once = std::sync::Once::new();
    HOOK.call_once(|| {
        let previous = std::panic::take_hook();
        std::panic::set_hook(Box::new(move |info| {
            let payload = info.payload();
            let message = payload.downcast_ref::<String>().map(String::as_str)
                .or_else(|| payload.downcast_ref::<&str>().copied());
            let silenced = EXPECTED.with(Cell::get)
                .is_some_and(|expected| message.is_some_and(|message| message.contains(expected)));
            if !silenced {
                previous(info);
            }
        }));
    });
    let mut future = std::pin::pin!(future);
    std::panic::AssertUnwindSafe(std::future::poll_fn(move |context| {
        let _polling = Polling(EXPECTED.with(|current| current.replace(Some(expected))));
        future.as_mut().poll(context)
    }))
    .catch_unwind()
    .await
}

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
            let (claimed, reads) = super::sql_counts::measure(self.count_poll(&name, multiplexed)).await;
            assert!(claimed["task"].is_object());
            assert_eq!(reads.count(super::worker_poll_fence::FENCE_REGISTRATION),1);
            // The workflow claim reuses the row its in-transaction fence read.
            // A multiplexed probe tries the validation kind first here, and
            // claim_update_validation_in keeps its own registration read.
            assert_eq!(reads.count("SELECT * FROM workflow_worker_registrations WHERE worker_id=$1 AND namespace=$2"),usize::from(multiplexed),
                "the workflow claim must reuse the registration its in-transaction fence validated");
            assert_eq!(self.count_get(), 1, "one replay/claim probe, multiplexed={multiplexed}");
            self.count_reset();
            let replayed = self.count_poll(&name, multiplexed).await;
            assert_eq!(self.count_get(), usize::from(multiplexed), "a plain live lease replays in a read snapshot; multiplexed replay retains its transition");
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
            let original_row = Self::query("SELECT last_history_sequence FROM workflow_runs WHERE id=$1")
                .bind(started["run_id"].as_str().unwrap()).fetch_one(&self.pool).await.unwrap();
            let original = DB::number(&original_row,"last_history_sequence").unwrap();
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
            assert!(matches!(error, super::RuntimeError::Refused {
                status: axum::http::StatusCode::PAYLOAD_TOO_LARGE,
                reason: "history_event_exceeds_page_budget",
            }), "{error:?}");
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

    /// Server #1282: a workflow task whose run holds another live lease is
    /// never offered, claimed or counted in a candidate page, so it cannot
    /// make offer-mode workers spin on an unclaimable packet or hide another
    /// run's work. It is offered once that lease ends.
    pub(super) async fn qualify_same_run_offer_fence(&self) {
        self.register(json!({"worker_id":"fence-worker","task_queue":"fenced","runtime":"php",
            "supported_workflow_types":["counted"],"supported_activity_types":[]}),
            &QueryTaskConfig::default(), Some("1.20")).await.unwrap();
        let started = self.start(json!({"workflow_id":"offer-fence","workflow_type":"counted","task_queue":"fenced","input":input()}),
            BTreeMap::new(), None, None).await.unwrap();
        let run_id = started["run_id"].as_str().unwrap();
        let body = json!({"worker_id":"fence-worker","task_queue":"fenced","timeout_seconds":0});
        let leased = self.poll(body.clone(), "workflow", "1.20").await.unwrap();
        let leased_id = leased["task"]["task_id"].as_str().unwrap().to_owned();
        // More waiting turns of the same run than a first candidate page
        // holds, as signals and activity results would create.
        let mut waiting = Vec::new();
        let mut tx = self.begin().await.unwrap();
        for _ in 0..=super::priority_fairness::FAIRNESS_BATCH {
            waiting.push(Self::create_task(&mut tx, run_id, "fenced", "workflow", json!({})).await.unwrap());
        }
        tx.commit().await.unwrap();
        let super::worker_poll_fence::PollAdmission::Fenced(fence) = self.admit_worker_poll("fence-worker", "fenced").await.unwrap()
        else { panic!("the registered fixture worker must be fenced"); };
        for _ in 0..3 {
            assert!(self.peek_execution_offer("fence-worker", "fenced", "workflow", "1.20", &fence).await.unwrap().is_none(),
                "a fenced task is never offered");
        }
        assert!(self.poll(body.clone(), "workflow", "1.20").await.unwrap()["task"].is_null());

        // The fenced turns do not hide another run's ready task.
        let other = self.start(json!({"workflow_id":"offer-fence-other","workflow_type":"counted","task_queue":"fenced","input":input()}),
            BTreeMap::new(), None, None).await.unwrap();
        let at = super::store::now();
        let first = self.task_candidate_query("fenced","workflow",None,at,at,true).fetch_all(&self.pool).await.unwrap();
        assert_eq!(first.iter().map(|row| DB::string(row,"workflow_run_id").unwrap()).collect::<Vec<_>>(),
            vec![other["run_id"].as_str().unwrap().to_owned()]);
        let offer = self.peek_execution_offer("fence-worker", "fenced", "workflow", "1.20", &fence).await.unwrap().unwrap();
        assert_eq!(first.len(), 1);
        assert_eq!(offer.task_id, DB::string(&first[0], "id").unwrap());
        Self::query("UPDATE workflow_tasks SET status='completed' WHERE workflow_run_id=$1")
            .bind(other["run_id"].as_str().unwrap()).execute(&self.pool).await.unwrap();

        // Once the lease ends, the run's next turn is offered.
        Self::query("UPDATE workflow_tasks SET status='completed',lease_expires_at=NULL WHERE id=$1")
            .bind(&leased_id).execute(&self.pool).await.unwrap();
        let offer = self.peek_execution_offer("fence-worker", "fenced", "workflow", "1.20", &fence).await.unwrap().unwrap();
        assert_eq!(offer.task_id, waiting[0]);
        Self::query("UPDATE workflow_tasks SET status='completed' WHERE workflow_run_id=$1")
            .bind(run_id).execute(&self.pool).await.unwrap();
    }

    pub(super) async fn qualify_first_candidate_batch(&self) {
        self.count_worker().await;
        let mut runs = Vec::new();
        for index in 0..12 {
            let started = self.count_start(&format!("first-batch-{index}")).await;
            runs.push(started["run_id"].as_str().unwrap().to_owned());
        }
        let at = super::store::now();
        let first = self.task_candidate_query("counted","workflow",None,at,at,true)
            .fetch_all(&self.pool).await.unwrap();
        let full = self.task_candidate_query("counted","workflow",None,at,at,false)
            .fetch_all(&self.pool).await.unwrap();
        assert_eq!(first.len(),super::priority_fairness::FAIRNESS_BATCH);
        assert_eq!(full.len(),12);
        let ids = |rows: &[DB::Row]| rows.iter().map(|row| DB::string(row,"id").unwrap()).collect::<Vec<_>>();
        assert_eq!(ids(&first),ids(&full[..first.len()]));

        let candidate = &first[0];
        let run_id = DB::string(candidate, "workflow_run_id").unwrap();
        let task_id = DB::string(candidate, "id").unwrap();
        let mut connection = self.pool.acquire().await.unwrap();
        let run = Self::query("SELECT * FROM workflow_runs WHERE id=$1")
            .bind(&run_id).fetch_one(&mut *connection).await.unwrap();
        let worker = Self::query("SELECT * FROM workflow_worker_registrations WHERE worker_id=$1 AND namespace=$2")
            .bind("count-worker").bind(self.namespace.as_ref()).fetch_one(&mut *connection).await.unwrap();
        let payload = DB::document_row(candidate, "payload").unwrap();
        let types = DB::document_row(&worker, "supported_workflow_types").unwrap();
        let super::worker_poll_fence::PollAdmission::Fenced(fence) = self.admit_worker_poll("count-worker", "counted").await.unwrap()
        else { panic!("the registered fixture worker must be fenced"); };
        self.count_reset();
        assert!(self.task_eligibility(
            &mut connection, candidate, &payload, &run, &worker, &types, "workflow", "counted", "1.20",
        ).await.unwrap().is_some());
        let offer = self.peek_execution_offer("count-worker", "counted", "workflow", "1.20", &fence).await.unwrap().unwrap();
        assert_eq!((offer.task_id.as_str(), offer.kind, offer.observed_attempt), (task_id.as_str(), "workflow", Some(0)));
        assert_eq!(self.count_get(), 0, "looking at task eligibility must not acquire a transition");
        let after = Self::query("SELECT status,attempt_count,lease_owner FROM workflow_tasks WHERE id=$1")
            .bind(&task_id).fetch_one(&mut *connection).await.unwrap();
        assert_eq!(DB::string(&after, "status").unwrap(), "ready");
        assert_eq!(DB::number(&after, "attempt_count").unwrap(), 0);
        assert!(DB::optional_string(&after, "lease_owner").unwrap().is_none());
        let sequence = Self::query("SELECT last_history_sequence FROM workflow_runs WHERE id=$1")
            .bind(&run_id).fetch_one(&mut *connection).await.unwrap();
        assert_eq!(DB::number(&sequence, "last_history_sequence").unwrap(), DB::number(&run, "last_history_sequence").unwrap());
        drop(connection);

        let (answer, statements) = super::sql_counts::measure(self.count_poll("first-batch-poll",false)).await;
        assert_eq!(answer["task"]["task_id"], task_id);
        assert_eq!(statements.count(DB::FIRST_TASK_CANDIDATES_SQL),1);
        assert_eq!(statements.count(DB::TASK_CANDIDATES_SQL),0,"a claimable first batch must avoid the full page");
        for run in runs {
            Self::query("UPDATE workflow_tasks SET status='completed' WHERE workflow_run_id=$1")
                .bind(run).execute(&self.pool).await.unwrap();
        }
        // The raw first batch contains ten unsupported workflow types. It
        // must not hide the supported candidate at the end of the full page.
        let mut runs = Vec::new();
        let mut expected = Value::Null;
        for index in 0..=10 {
            let started = self.start(json!({"workflow_id":format!("unsupported-first-batch-{index}"),
                "workflow_type":if index < 10 { "unsupported" } else { "counted" },
                "task_queue":"counted","input":input()}), BTreeMap::new(), None, None).await.unwrap();
            let run = started["run_id"].as_str().unwrap();
            // Fix the order independently of timestamp precision or ULID ties.
            Self::query("UPDATE workflow_tasks SET available_at=$1 WHERE workflow_run_id=$2")
                .bind(DB::bind_time(at - chrono::Duration::seconds(30) + chrono::Duration::milliseconds(index)))
                .bind(run).execute(&self.pool).await.unwrap();
            if index == 10 { expected = started["run_id"].clone(); }
            runs.push(run.to_owned());
        }
        let (answer, statements) = super::sql_counts::measure(self.count_poll("unsupported-first-batch",false)).await;
        assert_eq!(answer["task"]["run_id"],expected);
        assert_eq!(statements.count(DB::FIRST_TASK_CANDIDATES_SQL),1);
        assert_eq!(statements.count(DB::TASK_CANDIDATES_SQL),1,"an incomplete eligible prefix must read the full page");
        for run in runs {
            Self::query("UPDATE workflow_tasks SET status='completed' WHERE workflow_run_id=$1")
                .bind(run).execute(&self.pool).await.unwrap();
        }
        self.qualify_exact_task_claim().await;
    }

    async fn qualify_exact_task_claim(&self) {
        let first = self.count_start("targeted-claim-first").await;
        let second = self.count_start("targeted-claim-second").await;
        let task_id = |workflow: Value| async move {
            let row = Self::query("SELECT id FROM workflow_tasks WHERE workflow_run_id=$1")
                .bind(workflow["run_id"].as_str().unwrap()).fetch_one(&self.pool).await.unwrap();
            DB::string(&row, "id").unwrap()
        };
        let first_id = task_id(first).await;
        let second_id = task_id(second).await;
        // Put the other ready task first in normal polling order.
        Self::query("UPDATE workflow_tasks SET available_at=$1 WHERE id=$2")
            .bind(DB::bind_time(super::store::now() - chrono::Duration::seconds(10)))
            .bind(&first_id).execute(&self.pool).await.unwrap();
        let body = json!({"worker_id":"count-worker","task_queue":"counted","poll_request_id":"targeted-claim","timeout_seconds":0});
        let request = self.workflow_poll_request("count-worker", "counted", "targeted-claim".into(),
            Some("targeted-claim"), 0, &["workflow"]).await.unwrap();
        let target = |id: &str, observed_attempt: i64| super::transaction::ClaimTarget {
            task_id: id.to_owned(),
            kind: "workflow",
            observed_attempt: Some(observed_attempt),
            eligibility_digest: None,
        };

        let mut tx = self.begin().await.unwrap();
        tx.claim_only = Some(super::transaction::ClaimTarget {
            kind: "activity",
            ..target(&second_id, 0)
        });
        assert!(self.claim_in(&mut tx, &body, "workflow", "1.20", None, None).await.unwrap().is_none());
        tx.commit().await.unwrap();

        let mut tx = self.begin().await.unwrap();
        tx.claim_only = Some(target(&second_id, 0));
        let claimed = self.claim_in(&mut tx, &body, "workflow", "1.20", None, Some(&request))
            .await.unwrap().unwrap();
        assert_eq!(claimed["task_id"], second_id);
        assert_eq!(claimed["workflow_task_attempt"], 1);
        tx.commit().await.unwrap();

        let mut tx = self.begin().await.unwrap();
        tx.claim_only = Some(target(&second_id, 0));
        assert_eq!(self.claim_in(&mut tx, &body, "workflow", "1.20", None, Some(&request))
            .await.unwrap().unwrap(), claimed);
        tx.commit().await.unwrap();

        let mut tx = self.begin().await.unwrap();
        tx.claim_only = Some(target(&first_id, 0));
        assert!(matches!(self.claim_in(&mut tx, &body, "workflow", "1.20", None, Some(&request)).await,
            Err(super::RuntimeError::Refused { reason: "task_offer_receipt_conflict", .. })));
        drop(tx);

        Self::query("UPDATE workflow_tasks SET lease_expires_at=$1 WHERE id=$2")
            .bind(DB::bind_time(super::store::now() - chrono::Duration::seconds(10)))
            .bind(&second_id).execute(&self.pool).await.unwrap();
        let mut tx = self.begin().await.unwrap();
        tx.claim_only = Some(target(&second_id, 0));
        assert!(self.claim_in(&mut tx, &body, "workflow", "1.20", None, None).await.unwrap().is_none());
        tx.commit().await.unwrap();

        let mut tx = self.begin().await.unwrap();
        tx.claim_only = Some(target(&second_id, 1));
        let recovered = self.claim_in(&mut tx, &body, "workflow", "1.20", None, None)
            .await.unwrap().unwrap();
        assert_eq!(recovered["task_id"], second_id);
        assert_eq!(recovered["workflow_task_attempt"], 2);
        tx.commit().await.unwrap();

        let untouched = Self::query("SELECT status,attempt_count,lease_owner FROM workflow_tasks WHERE id=$1")
            .bind(&first_id).fetch_one(&self.pool).await.unwrap();
        assert_eq!(DB::string(&untouched, "status").unwrap(), "ready");
        assert_eq!(DB::number(&untouched, "attempt_count").unwrap(), 0);
        assert!(DB::optional_string(&untouched, "lease_owner").unwrap().is_none());
        for id in [&first_id, &second_id] {
            Self::query("UPDATE workflow_tasks SET status='completed' WHERE id=$1")
                .bind(id).execute(&self.pool).await.unwrap();
        }
    }

    pub(super) async fn qualify_claim_history_sequence(&self) {
        let started = self.count_start("claimed-history-total").await;
        let run_id = started["run_id"].as_str().unwrap();
        let mut tx = self.begin().await.unwrap();
        for sequence in 1..=2 {
            Self::append(&mut tx,run_id,"SideEffectRecorded",json!({"sequence":sequence,"result":null}),None,None)
                .await.unwrap();
        }
        let run = Self::query("SELECT last_history_sequence FROM workflow_runs WHERE id=$1")
            .bind(run_id).fetch_one(&mut *tx).await.unwrap();
        let last = DB::number(&run,"last_history_sequence").unwrap();
        assert_eq!(last,4);
        for (after,size) in [(0,1),(1,2),(0,1000),(last,1)] {
            let (reference, reference_count) = super::sql_counts::measure(
                Self::history_page_connection(&mut *tx,run_id,after,size),
            ).await;
            let (known, known_count) = super::sql_counts::measure(
                Self::history_page_connection_with_total(&mut *tx,run_id,after,size,Some(last)),
            ).await;
            assert_eq!(known.unwrap(),reference.unwrap(),"after={after}, size={size}");
            let query = "SELECT last_history_sequence FROM workflow_runs WHERE id=$1";
            assert_eq!(reference_count.count(query),1);
            assert_eq!(known_count.count(query),usize::from(cfg!(debug_assertions)),
                "only debug builds re-read the claimed history total");
        }
        #[cfg(debug_assertions)]
        {
            Self::append(&mut tx,run_id,"SideEffectRecorded",json!({"sequence":3,"result":null}),None,None)
                .await.unwrap();
            let refused = expected_panic(
                "claimed run history sequence changed before its first page",
                Self::history_page_connection_with_total(&mut *tx,run_id,0,1,Some(last)),
            ).await.expect_err("an intervening history write must trip the debug assertion");
            let message = refused.downcast_ref::<String>().map(String::as_str)
                .or_else(|| refused.downcast_ref::<&str>().copied()).unwrap_or("");
            assert!(message.contains("claimed run history sequence changed before its first page"),"{message}");
        }
        tx.rollback().await.unwrap();
        Self::query("UPDATE workflow_tasks SET status='completed' WHERE workflow_run_id=$1")
            .bind(run_id).execute(&self.pool).await.unwrap();
    }

    pub(super) async fn qualify_fenced_registration_reuse(&self) {
        let registration = json!({"worker_id":"fence-reuse","task_queue":"fence-reuse","runtime":"php",
            "supported_workflow_types":["fence-reuse"],"supported_activity_types":["fence-reuse"]});
        self.register(registration.clone(),&QueryTaskConfig::default(),Some("1.20")).await.unwrap();
        let super::worker_poll_fence::PollAdmission::Fenced(fence) = self.admit_worker_poll("fence-reuse","fence-reuse").await.unwrap()
            else { panic!("fresh registration must admit its original poll"); };
        let mut tx = self.begin().await.unwrap();
        let (row,reads) = super::sql_counts::measure(self.fenced_claim_registration(&mut tx,&fence,"fence-reuse")).await;
        let row = row.unwrap().unwrap();
        assert_eq!(DB::string(&row,"worker_id").unwrap(),"fence-reuse");
        assert_eq!(DB::string(&row,"namespace").unwrap(),self.namespace.as_ref());
        assert_eq!(reads.total(),1);
        assert_eq!(reads.count(super::worker_poll_fence::FENCE_REGISTRATION),1);
        tx.rollback().await.unwrap();
        // No process or clock change is needed: native re-registration rotates
        // the authority token even inside the same stored second.
        self.register(registration,&QueryTaskConfig::default(),Some("1.20")).await.unwrap();
        let mut tx = self.begin().await.unwrap();
        assert!(self.fenced_claim_registration(&mut tx,&fence,"fence-reuse").await.unwrap().is_none());
        tx.rollback().await.unwrap();
        // An unfenced internal claim still performs its original registration
        // read. The optional optimization cannot bypass that fallback.
        let body = json!({"worker_id":"fence-reuse","task_queue":"fence-reuse"});
        let mut tx = self.begin().await.unwrap();
        let (claimed,reads) = super::sql_counts::measure(self.claim_in(&mut tx,&body,"activity","1.20",None,None)).await;
        assert!(claimed.unwrap().is_none());
        assert_eq!(reads.count("SELECT * FROM workflow_worker_registrations WHERE worker_id=$1 AND namespace=$2"),1);
        assert_eq!(reads.count(super::worker_poll_fence::FENCE_REGISTRATION),0);
        tx.rollback().await.unwrap();
    }

    pub(super) async fn qualify_query_poll_transactions(&self) {
        self.count_worker().await;
        let workflow_id = "counted-query-workflow";
        self.count_start(workflow_id).await;
        let workflow = self.poll(json!({"worker_id":"count-worker","task_queue":"counted","timeout_seconds":0}), "workflow", "1.20").await.unwrap();
        self.complete_or_checkpoint(workflow["task"]["task_id"].as_str().unwrap(), json!({"lease_owner":"count-worker",
            "workflow_task_attempt":workflow["task"]["workflow_task_attempt"],
            "commands":[{"type":"complete_workflow","result":input()}]}),
            Arc::new(Semaphore::new(1)), BTreeMap::new(), None, super::carried_polls::Config { protocol: Some("1.20"), pages: Default::default(), default_wait: 30 }, Default::default(), None, None).await.unwrap();
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
    store.qualify_first_candidate_batch().await;
    store.qualify_same_run_offer_fence().await;
    store.qualify_claim_history_sequence().await;
    store.qualify_fenced_registration_reuse().await;
    store.qualify_query_poll_transactions().await;
    store.qualify_task_offer_key_usage().await;
    store.qualify_exact_update_validation_claim().await;
    store.qualify_execution_offer_receipts().await;
    store.close().await;
}
