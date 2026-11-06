//! Shared producer controls selected by every existing backend source suite.
use super::store::{Store, now};
use chrono::{DateTime, TimeDelta, Utc};
use serde_json::json;
use std::{collections::BTreeMap, sync::Arc};
use tokio::sync::Semaphore;

store_methods! {
    async fn ready_age_task(&mut self, kind: &str) -> String {
        self.test_claim_at = None;
        let started = self.start(json!({"workflow_id":format!("ready-age-{}", ulid::Ulid::new()),
            "workflow_type":"ready-age-workflow","task_queue":"ready-age",
            "input":{"codec":"avro","blob":"AA=="}}), BTreeMap::new(), None, None).await.unwrap();
        let run = started["run_id"].as_str().unwrap();
        if kind == "activity" {
            let answer = self.poll(json!({"worker_id":"ready-age-worker","task_queue":"ready-age","timeout_seconds":0}), "workflow", "1.20").await.unwrap();
            let task = &answer["task"];
            self.complete_or_checkpoint(task["task_id"].as_str().unwrap(), json!({
                "lease_owner":"ready-age-worker","workflow_task_attempt":task["workflow_task_attempt"],
                "commands":[{"type":"schedule_activity","activity_type":"ready-age-activity",
                    "arguments":{"codec":"avro","blob":"AA=="},
                    "retry_policy":{"max_attempts":2,"backoff_seconds":[0]}}]}),
                Arc::new(Semaphore::new(1)), BTreeMap::new(), None, super::carried_polls::Config { protocol: Some("1.20"), pages: Default::default(), default_wait: 30 }, Default::default(), None, None).await.unwrap();
        }
        let row = Self::query("SELECT id FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type=$2 AND status='ready'")
            .bind(run).bind(kind).fetch_one(&self.pool).await.unwrap();
        DB::string(&row, "id").unwrap()
    }

    async fn ready_age_row(&self, task: &str) -> DB::Row {
        Self::query("SELECT * FROM workflow_tasks WHERE id=$1").bind(task).fetch_one(&self.pool).await.unwrap()
    }

    async fn ready_age_origin(&self, task: &str) -> Option<DateTime<Utc>> {
        DB::optional_instant(&self.ready_age_row(task).await, "ready_origin_at").unwrap()
    }

    async fn ready_age_registration(&self) {
        self.register(json!({"worker_id":"ready-age-worker","task_queue":"ready-age","runtime":"php",
            "supported_workflow_types":["ready-age-workflow"],"supported_activity_types":["ready-age-activity"]}),
            &super::QueryTaskConfig::default(), None).await.unwrap();
    }

    async fn ready_age_retire(&self, task: &str) {
        Self::query("UPDATE workflow_tasks SET status='completed' WHERE id=$1")
            .bind(task).execute(&self.pool).await.unwrap();
    }

    async fn qualify_ready_age_transitions(&mut self) {
        let legacy=DateTime::parse_from_rfc3339("2000-01-01T00:00:00Z").unwrap().with_timezone(&Utc);
        // Replacing a registration releases both task families in the same
        // production transaction, without reusing their old readiness episode.
        for kind in ["workflow","activity"] {
            let task=self.ready_age_task(kind).await;
            Self::query("UPDATE workflow_tasks SET ready_origin_at=$1 WHERE id=$2")
                .bind(DB::bind_time(legacy)).bind(&task).execute(&self.pool).await.unwrap();
            self.poll(json!({"worker_id":"ready-age-worker","task_queue":"ready-age","timeout_seconds":0}),kind,"1.20").await.unwrap();
            let before=now();
            self.ready_age_registration().await;
            let after=now();
            let origin=self.ready_age_origin(&task).await.unwrap();
            assert!(origin>=before && origin<=after,"{kind} replacement origin {origin}");
            assert_eq!(DB::string(&self.ready_age_row(&task).await,"status").unwrap(),"ready");
            self.ready_age_retire(&task).await;
        }
        // The ordinary workflow-task failure writer creates a fresh row,
        // carrying attempt count but not the failed row's readiness clock.
        let old=self.ready_age_task("workflow").await;
        let leased=self.poll(json!({"worker_id":"ready-age-worker","task_queue":"ready-age","timeout_seconds":0}),"workflow","1.20").await.unwrap();
        Self::query("UPDATE workflow_tasks SET ready_origin_at=$1 WHERE id=$2")
            .bind(DB::bind_time(legacy)).bind(&old).execute(&self.pool).await.unwrap();
        let failed=self.fail_workflow_task(&old,json!({"lease_owner":"ready-age-worker",
            "workflow_task_attempt":leased["task"]["workflow_task_attempt"],
            "failure":{"message":"retry source control","type":"RuntimeException"}}),
            Arc::new(Semaphore::new(1)),None).await.unwrap();
        let next=failed["next_task_id"].as_str().unwrap();
        let row=self.ready_age_row(next).await;
        assert_eq!(self.ready_age_origin(&old).await,Some(legacy));
        assert_eq!(DB::optional_instant(&row,"ready_origin_at").unwrap(),Some(DB::instant(&row,"created_at").unwrap()));
        self.ready_age_retire(next).await;

        // Repair's already-ready branch preserves its episode. Leased and
        // replay-blocked branches commit a new origin with the repair sample.
        for state in ["ready","leased","failed"] {
            let task=self.ready_age_task("workflow").await;
            if state!="ready" {
                let leased=self.poll(json!({"worker_id":"ready-age-worker","task_queue":"ready-age","timeout_seconds":0}),"workflow","1.20").await.unwrap();
                if state=="failed" {
                    self.fail_workflow_task(&task,json!({"lease_owner":"ready-age-worker",
                        "workflow_task_attempt":leased["task"]["workflow_task_attempt"],
                        "failure":{"message":"Recorded version 1 is outside supported range 2..2.",
                            "type":"DurableWorkflow\\Exception\\NonDeterministicWorkflow",
                            "reason":"version_marker_incompatible_range","sequence":1}}),
                        Arc::new(Semaphore::new(1)),None).await.unwrap();
                }
            }
            Self::query("UPDATE workflow_tasks SET ready_origin_at=$1 WHERE id=$2")
                .bind(DB::bind_time(legacy)).bind(&task).execute(&self.pool).await.unwrap();
            let row=self.ready_age_row(&task).await;
            let run_id=DB::string(&row,"workflow_run_id").unwrap();
            let run=Self::query("SELECT * FROM workflow_runs WHERE id=$1").bind(&run_id).fetch_one(&self.pool).await.unwrap();
            let at=now();
            let mut tx=self.begin().await.unwrap();
            Self::recover_task_row(&mut tx,&run,&row,at).await.unwrap();
            tx.commit().await.unwrap();
            assert_eq!(self.ready_age_origin(&task).await,Some(if state=="ready" {legacy} else {at}));
            self.ready_age_retire(&task).await;
        }

        // A reported failure's retry availability is its eligibility origin.
        let task=self.ready_age_task("activity").await;
        let leased=self.poll(json!({"worker_id":"ready-age-worker","task_queue":"ready-age","timeout_seconds":0}),"activity","1.20").await.unwrap();
        let body=json!({"lease_owner":"ready-age-worker","activity_attempt_id":leased["task"]["activity_attempt_id"],
            "failure":{"message":"retry source control","type":"RuntimeException"}});
        let (failure,blob)=super::activity_failures::prepare_failure(&body,Arc::new(Semaphore::new(1))).await.unwrap();
        let failed=self.fail_activity(&task,body,failure,blob,None).await.unwrap();
        let retry=failed["next_task_id"].as_str().unwrap();
        let row=self.ready_age_row(retry).await;
        assert_eq!(DB::optional_instant(&row,"ready_origin_at").unwrap(),Some(DB::instant(&row,"available_at").unwrap()));
        self.ready_age_retire(retry).await;

        // Lease-loss timeout owns a new retry row and its exact availability.
        let task=self.ready_age_task("activity").await;
        self.poll(json!({"worker_id":"ready-age-worker","task_queue":"ready-age","timeout_seconds":0}),"activity","1.20").await.unwrap();
        Self::query("UPDATE workflow_tasks SET lease_expires_at=$1 WHERE id=$2")
            .bind(DB::bind_time(legacy)).bind(&task).execute(&self.pool).await.unwrap();
        assert!(matches!(self.enforce_lease_expiry(&task).await.unwrap(),super::activity_deadlines::Enforcement::Enforced { .. }));
        let run_id=DB::string(&self.ready_age_row(&task).await,"workflow_run_id").unwrap();
        let row=Self::query("SELECT * FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='activity' AND status='ready'")
            .bind(&run_id).fetch_one(&self.pool).await.unwrap();
        assert_eq!(DB::optional_instant(&row,"ready_origin_at").unwrap(),Some(DB::instant(&row,"available_at").unwrap()));
        self.ready_age_retire(&DB::string(&row,"id").unwrap()).await;

        // The fenced abandoned-work owner writes a repair episode and origin
        // together, independently of the old task's admission timestamp.
        let task=self.ready_age_task("workflow").await;
        self.poll(json!({"worker_id":"ready-age-worker","task_queue":"ready-age","timeout_seconds":0}),"workflow","1.20").await.unwrap();
        Self::query("UPDATE workflow_tasks SET ready_origin_at=$1 WHERE id=$2")
            .bind(DB::bind_time(legacy)).bind(&task).execute(&self.pool).await.unwrap();
        let row=self.ready_age_row(&task).await;
        let before=now();
        let mut tx=self.begin().await.unwrap();
        self.repair_abandoned_task(&mut tx,&row,Arc::new(Semaphore::new(1))).await.unwrap();
        tx.commit().await.unwrap();
        let after=now();
        let origin=self.ready_age_origin(&task).await.unwrap();
        assert!(origin>=before && origin<=after);
        self.ready_age_retire(&task).await;
    }

    pub(super) async fn qualify_ready_age(&mut self) {
        self.ready_age_registration().await;
        self.qualify_ready_age_transitions().await;
        assert_eq!(super::store::capabilities()["claimed_task_ready_age"], true);
        for kind in ["workflow", "activity"] {
            for (origin_delta, available_delta, expected) in [
                (Some(0), -2_000_000, Some(0)),
                (Some(-999), -2_000_000, Some(0)),
                (Some(-1_000), -2_000_000, Some(1)),
                (Some(-30_000_000), -2_000_000, Some(2_000)),
                (Some(1), -2_000_000, None),
                (None, -2_000_000, None),
            ] {
                let task_id = self.ready_age_task(kind).await;
                // New production admission wrote its own origin before any
                // controlled historical/edge mutation below.
                assert!(self.ready_age_origin(&task_id).await.is_some());
                let at = now();
                let origin = origin_delta.map(|micros| at + TimeDelta::microseconds(micros));
                let available = at + TimeDelta::microseconds(available_delta);
                Self::query("UPDATE workflow_tasks SET ready_origin_at=$1,available_at=$2 WHERE id=$3")
                    .bind(DB::optional_time(origin)).bind(DB::bind_time(available)).bind(&task_id)
                    .execute(&self.pool).await.unwrap();
                self.test_claim_at = Some(at);
                let request = json!({"worker_id":"ready-age-worker","task_queue":"ready-age","timeout_seconds":0,
                    "poll_request_id":format!("age-{}", ulid::Ulid::new())});
                let answer = self.poll(request.clone(), kind, "1.20").await.unwrap();
                assert_eq!(answer["task"]["task_id"], task_id, "{answer}");
                assert_eq!(answer["task"].get("ready_age_ms"), expected.as_ref().map(|n| json!(n)).as_ref(), "{kind}: {answer}");
                let leased = self.ready_age_row(&task_id).await;
                assert_eq!(DB::instant(&leased, "leased_at").unwrap(), at);
                if let Some(value) = expected {
                    assert!(answer["task"]["ready_age_ms"].is_i64());
                    assert_eq!(value, (at - origin.unwrap().max(available)).num_microseconds().unwrap()/1_000);
                }
                // A duplicate, a changed sampling clock and another Store
                // object all read the serialized committed answer from SQL.
                self.test_claim_at = Some(at + TimeDelta::seconds(5));
                Self::query("UPDATE workflow_tasks SET ready_origin_at=NULL WHERE id=$1")
                    .bind(&task_id).execute(&self.pool).await.unwrap();
                if kind == "activity" {
                    let renewal = self.renew_activity_lease(&task_id, json!({"activity_attempt_id":answer["task"]["activity_attempt_id"],
                        "lease_owner":"ready-age-worker"})).await.unwrap();
                    assert!(renewal.get("ready_age_ms").is_none());
                }
                let replay = self.poll(request.clone(), kind, "1.20").await.unwrap();
                assert_eq!(replay["task"].get("ready_age_ms"), answer["task"].get("ready_age_ms"));
                let restored = Self::new(self.pool.clone());
                let replay = restored.poll(request, kind, "1.20").await.unwrap();
                assert_eq!(replay["task"].get("ready_age_ms"), answer["task"].get("ready_age_ms"));
                Self::query("UPDATE workflow_tasks SET status='completed' WHERE id=$1")
                    .bind(&task_id).execute(&self.pool).await.unwrap();
            }
        }
        self.test_claim_at = None;
        // A direct expired workflow lease has its own provable eligibility
        // even when the old legacy ready origin was never recorded.
        let task_id = self.ready_age_task("workflow").await;
        let at = now();
        let expiry = at - TimeDelta::seconds(3);
        Self::query("UPDATE workflow_tasks SET status='leased',attempt_count=1,lease_owner='lost-worker',lease_expires_at=$1,ready_origin_at=NULL,available_at=$2 WHERE id=$3")
            .bind(DB::bind_time(expiry)).bind(DB::bind_time(at-TimeDelta::seconds(20))).bind(&task_id)
            .execute(&self.pool).await.unwrap();
        self.test_claim_at = Some(at);
        let claim = self.poll(json!({"worker_id":"ready-age-worker","task_queue":"ready-age","timeout_seconds":0}),"workflow","1.20").await.unwrap();
        assert_eq!(claim["task"]["task_id"],task_id);
        assert_eq!(claim["task"]["ready_age_ms"],3_000);
        assert_eq!(claim["task"]["workflow_task_attempt"],2);
        assert_eq!(self.ready_age_origin(&task_id).await,Some(expiry));
        Self::query("UPDATE workflow_tasks SET status='completed' WHERE id=$1").bind(&task_id).execute(&self.pool).await.unwrap();
        // Rolled-back claims leave no lease, measurement receipt or activity
        // start event behind. A genuine later claim samples independently.
        for kind in ["workflow","activity"] {
            let task_id = self.ready_age_task(kind).await;
            let before = self.ready_age_origin(&task_id).await;
            let request=json!({"worker_id":"ready-age-worker","task_queue":"ready-age","timeout_seconds":0,
                "poll_request_id":format!("rollback-{}",ulid::Ulid::new())});
            let mut tx=self.begin().await.unwrap();
            let claim=self.claim_in(&mut tx,&request,kind,"1.20",None,None).await.unwrap().unwrap();
            assert_eq!(claim["task_id"],task_id);
            tx.rollback().await.unwrap();
            let row=self.ready_age_row(&task_id).await;
            assert_eq!(DB::string(&row,"status").unwrap(),"ready");
            assert!(DB::optional_instant(&row,"leased_at").unwrap().is_none());
            assert_eq!(self.ready_age_origin(&task_id).await,before);
            let at=now();
            self.test_claim_at=Some(at);
            let committed=self.poll(request,kind,"1.20").await.unwrap();
            assert_eq!(committed["task"]["task_id"],task_id);
            assert_eq!(committed["task"]["ready_age_ms"].as_i64(),Some((at-before.unwrap()).num_milliseconds()));
            Self::query("UPDATE workflow_tasks SET status='completed' WHERE id=$1").bind(&task_id).execute(&self.pool).await.unwrap();
        }
        self.test_claim_at=None;
        let empty=self.poll(json!({"worker_id":"ready-age-worker","task_queue":"ready-age","timeout_seconds":0}),"workflow","1.20").await.unwrap();
        assert!(empty["task"].is_null());
        assert!(empty.get("ready_age_ms").is_none());
    }
}

#[tokio::test]
async fn sqlite_claims_use_canonical_origins_and_committed_poll_answers() {
    let directory=tempfile::tempdir().unwrap();
    let mut store=Store::<sqlx::Sqlite>::new(super::sqlite::open(directory.path().join("ready-age.sqlite").to_str().unwrap()).await.unwrap());
    store.qualify_ready_age().await;
    store.close().await;
}
