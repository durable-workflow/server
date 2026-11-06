//! Exercise eager dispatch and its failure boundary against each real backend.
use super::{Result, store::Store};
use crate::codec::{Value as Payload, ValueCodec};
use serde_json::{Value, json};
use sqlx::Executor;
use std::{collections::BTreeMap, sync::{Arc, atomic::Ordering}};
use tokio::sync::Semaphore;

fn input() -> Value {
    json!({"codec":"avro","blob":ValueCodec::new().unwrap().encode(&Payload::Array(vec![])).unwrap()})
}

fn activity_poll() -> Value {
    json!({"worker_id":"owner","task_queue":"carried","poll_request_id":"original-activity-request","timeout_seconds":0})
}

store_methods! {
    async fn carrier_workers(&self) {
        for worker in ["owner", "other"] {
            self.register(json!({"worker_id":worker,"task_queue":"carried","runtime":"php",
                "supported_workflow_types":["carried"],"supported_activity_types":["carried"]}),
                &super::QueryTaskConfig::default(), Some("1.20")).await.unwrap();
        }
    }

    async fn carrier_task(&self, name: &str) -> Value {
        self.start(json!({"workflow_id":name,"workflow_type":"carried","task_queue":"carried","input":input()}),
            BTreeMap::new(), None, None).await.unwrap();
        self.poll(json!({"worker_id":"owner","task_queue":"carried","timeout_seconds":0}), "workflow", "1.20")
            .await.unwrap()["task"].clone()
    }

    async fn carrier_complete(&self, task: &Value, poll: Option<Value>) -> Result<Value> {
        let mut body = json!({"lease_owner":"owner","workflow_task_attempt":task["workflow_task_attempt"],
            "commands":[{"type":"schedule_activity","activity_type":"carried","arguments":input()}]});
        if let Some(poll) = poll { body["activity_poll"] = poll; }
        self.complete_or_checkpoint(task["task_id"].as_str().unwrap(), body, Arc::new(Semaphore::new(1)),
            BTreeMap::new(), None, Some("1.20"), Default::default(), None, None).await
    }

    async fn carrier_activity_state(&self, task: &Value) -> (String, i64) {
        let row = Self::query("SELECT status,attempt_count FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='activity'")
            .bind(task["run_id"].as_str().unwrap()).fetch_one(&self.pool).await.unwrap();
        (DB::string(&row, "status").unwrap(), DB::number(&row, "attempt_count").unwrap())
    }

    pub(super) async fn qualify_carried_activity_polls(&self) {
        let store = self.in_namespace("carrier-positive");
        store.carrier_workers().await;
        let task = store.carrier_task("carrier-positive").await;
        store.test_transitions.store(0, Ordering::Relaxed);
        let completed = store.carrier_complete(&task, Some(activity_poll())).await.unwrap();
        assert_eq!(completed["recorded"], true);
        assert_eq!(completed["activity_poll"], json!({"leased":true}));
        assert_eq!(store.test_transitions.load(Ordering::Relaxed), 1);
        assert_eq!(store.carrier_activity_state(&task).await, ("leased".into(), 1));
        store.test_transitions.store(0, Ordering::Relaxed);
        let first = store.poll(activity_poll(), "activity", "1.20").await.unwrap();
        assert_eq!(store.test_transitions.load(Ordering::Relaxed), 0, "the committed receipt replays without a transition lock");
        let mut second = store.poll(activity_poll(), "activity", "1.20").await.unwrap();
        second["server_time"] = first["server_time"].clone();
        assert_eq!(first, second, "only the response observation clock may change");
        let duplicate = store.carrier_complete(&task, Some(activity_poll())).await.unwrap();
        assert_eq!(duplicate["recorded"], false);
        assert_eq!(duplicate["reason"], "already_completed");
        assert!(duplicate.get("activity_poll").is_none(), "a duplicate completion dispatches nothing new");
        assert_eq!(store.carrier_activity_state(&task).await, ("leased".into(), 1));

        for (case, poll) in [
            ("omitted", None),
            ("wrong-owner", Some(json!({"worker_id":"other","task_queue":"carried","poll_request_id":"wrong-owner"}))),
            ("wrong-queue", Some(json!({"worker_id":"owner","task_queue":"other","poll_request_id":"wrong-queue"}))),
            ("missing-id", Some(json!({"worker_id":"owner","task_queue":"carried"}))),
            ("unknown-field", Some(json!({"worker_id":"owner","task_queue":"carried","poll_request_id":"unknown","unknown":true}))),
        ] {
            let store = self.in_namespace(&format!("carrier-{case}"));
            store.carrier_workers().await;
            let task = store.carrier_task(case).await;
            let completed = store.carrier_complete(&task, poll).await.unwrap();
            assert_eq!(completed["recorded"], true, "{case}");
            assert!(completed.get("activity_poll").is_none(), "{case}");
            assert_eq!(store.carrier_activity_state(&task).await, ("ready".into(), 0), "{case}");
            let receipts = Self::scalar("SELECT COUNT(*) FROM dw_poll_receipts WHERE namespace=$1")
                .bind(store.namespace.as_ref()).fetch_one(&store.pool).await.unwrap();
            assert_eq!(receipts, 0, "{case}: no refused carrier receipt");
        }

        let store = self.in_namespace("carrier-occupied");
        store.carrier_workers().await;
        let task = store.carrier_task("first").await;
        let other = store.carrier_task("second").await;
        assert_ne!(task["task_id"], other["task_id"]);
        let completed = store.carrier_complete(&task, Some(activity_poll())).await.unwrap();
        assert_eq!(completed["recorded"], true);
        assert!(completed.get("activity_poll").is_none());
        assert_eq!(store.carrier_activity_state(&task).await, ("ready".into(), 0));

        let store = self.in_namespace("carrier-occupied-null-deadline");
        store.carrier_workers().await;
        let task = store.carrier_task("null-deadline-parent").await;
        let other = store.carrier_task("null-deadline-other").await;
        Self::query("UPDATE workflow_tasks SET lease_expires_at=NULL WHERE id=$1")
            .bind(other["task_id"].as_str().unwrap()).execute(&store.pool).await.unwrap();
        let completed = store.carrier_complete(&task, Some(activity_poll())).await.unwrap();
        assert_eq!(completed["recorded"], true);
        assert!(completed.get("activity_poll").is_none(), "unknown opposite lease expiry is not a free slot");

        let store = self.in_namespace("carrier-nondeliverable-receipt");
        store.carrier_workers().await;
        let first = store.carrier_task("old-receipt-parent").await;
        store.carrier_complete(&first, Some(activity_poll())).await.unwrap();
        Self::query("UPDATE workflow_tasks SET status='completed',lease_expires_at=NULL WHERE workflow_run_id=$1 AND task_type='activity'")
            .bind(first["run_id"].as_str().unwrap()).execute(&store.pool).await.unwrap();
        let task = store.carrier_task("new-receipt-parent").await;
        let completed = store.carrier_complete(&task, Some(activity_poll())).await.unwrap();
        assert_eq!(completed["recorded"], true);
        assert!(completed.get("activity_poll").is_none(), "the closed expired-receipt refusal preserves the valid completion");
        assert_eq!(store.carrier_activity_state(&task).await, ("ready".into(), 0));

        let store = self.in_namespace("carrier-retained-fence");
        store.carrier_workers().await;
        let poll = store.admit_activity_carrier(Some(activity_poll()), "owner", Some("1.20")).await.unwrap().unwrap();
        let mut tx = store.begin().await.unwrap();
        assert!(matches!(store.claim_carried_activity(&mut tx, &poll, "other").await.unwrap(), super::carried_polls::Outcome::Declined));
        Self::query("UPDATE workflow_worker_registrations SET build_id='replacement' WHERE namespace=$1 AND worker_id='owner'")
            .bind(store.namespace.as_ref()).execute(&mut *tx).await.unwrap();
        assert!(matches!(store.claim_carried_activity(&mut tx, &poll, "owner").await.unwrap(), super::carried_polls::Outcome::Declined));
        tx.rollback().await.unwrap();

        let store = self.in_namespace("carrier-parent-guard");
        store.carrier_workers().await;
        let task = store.carrier_task("draining-parent").await;
        Self::query("UPDATE workflow_worker_registrations SET status='draining' WHERE namespace=$1 AND worker_id='owner'")
            .bind(store.namespace.as_ref()).execute(&store.pool).await.unwrap();
        assert!(store.admit_activity_carrier(Some(activity_poll()), "owner", Some("1.20")).await.unwrap().is_none());
        for poll in [None, Some(activity_poll())] {
            let error = store.carrier_complete(&task, poll).await.unwrap_err();
            assert!(format!("{error:?}").contains("stale_worker_registration"), "{error:?}");
        }
        assert_eq!(Self::scalar("SELECT COUNT(*) FROM activity_executions WHERE workflow_run_id=$1")
            .bind(task["run_id"].as_str().unwrap()).fetch_one(&store.pool).await.unwrap(), 0);

        let store = self.in_namespace("carrier-claim-fault");
        store.carrier_workers().await;
        let task = store.carrier_task("fault").await;
        let create = match DB::NAME {
            "SQLite" => "CREATE TRIGGER dw_carried_claim_fault BEFORE UPDATE ON workflow_tasks WHEN NEW.task_type='activity' AND NEW.status='leased' BEGIN SELECT RAISE(ABORT,'carried claim qualification fault'); END",
            "PostgreSQL" => "CREATE FUNCTION dw_carried_claim_fault_fn() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.task_type='activity' AND NEW.status='leased' THEN RAISE EXCEPTION 'carried claim qualification fault'; END IF; RETURN NEW; END $$; CREATE TRIGGER dw_carried_claim_fault BEFORE UPDATE ON workflow_tasks FOR EACH ROW EXECUTE FUNCTION dw_carried_claim_fault_fn()",
            "MySQL" => "CREATE TRIGGER dw_carried_claim_fault BEFORE UPDATE ON workflow_tasks FOR EACH ROW BEGIN IF NEW.task_type='activity' AND NEW.status='leased' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='carried claim qualification fault'; END IF; END",
            _ => unreachable!(),
        };
        let mut connection = store.pool.acquire().await.unwrap();
        (&mut *connection).execute(create).await.unwrap();
        drop(connection);
        let error = store.carrier_complete(&task, Some(activity_poll())).await.unwrap_err();
        assert!(format!("{error:?}").contains("carried claim qualification fault"), "the actual claim write must fail visibly: {error:?}");
        let mut connection = store.pool.acquire().await.unwrap();
        (&mut *connection).execute(match DB::NAME {
            "PostgreSQL" => "DROP TRIGGER dw_carried_claim_fault ON workflow_tasks; DROP FUNCTION dw_carried_claim_fault_fn()",
            _ => "DROP TRIGGER dw_carried_claim_fault",
        }).await.unwrap();
        drop(connection);
        let row = Self::query("SELECT status FROM workflow_tasks WHERE id=$1").bind(task["task_id"].as_str().unwrap())
            .fetch_one(&store.pool).await.unwrap();
        assert_eq!(DB::string(&row, "status").unwrap(), "leased");
        assert_eq!(Self::scalar("SELECT COUNT(*) FROM activity_executions WHERE workflow_run_id=$1")
            .bind(task["run_id"].as_str().unwrap()).fetch_one(&store.pool).await.unwrap(), 0);
        let retried = store.carrier_complete(&task, Some(activity_poll())).await.unwrap();
        assert_eq!(retried["activity_poll"], json!({"leased":true}));
        assert_eq!(store.carrier_activity_state(&task).await, ("leased".into(), 1));

        let store = self.in_namespace("carrier-corrupt-candidate");
        store.carrier_workers().await;
        let earlier = store.carrier_task("corrupt-candidate").await;
        store.carrier_complete(&earlier, None).await.unwrap();
        Self::query("UPDATE workflow_tasks SET payload=$1 WHERE workflow_run_id=$2 AND task_type='activity'")
            .bind(DB::document(&json!({}))).bind(earlier["run_id"].as_str().unwrap()).execute(&store.pool).await.unwrap();
        let task = store.carrier_task("corrupt-candidate-parent").await;
        let error = store.carrier_complete(&task, Some(activity_poll())).await.unwrap_err();
        assert!(matches!(error, super::RuntimeError::Refused { reason: "invalid_request", .. }), "{error:?}");
        let row = Self::query("SELECT status FROM workflow_tasks WHERE id=$1").bind(task["task_id"].as_str().unwrap())
            .fetch_one(&store.pool).await.unwrap();
        assert_eq!(DB::string(&row, "status").unwrap(), "leased", "stored corruption must not look like an empty eager poll");
        assert_eq!(Self::scalar("SELECT COUNT(*) FROM activity_executions WHERE workflow_run_id=$1")
            .bind(task["run_id"].as_str().unwrap()).fetch_one(&store.pool).await.unwrap(), 0);
    }
}

#[tokio::test]
async fn sqlite_carried_activity_polls_preserve_owner_capacity_and_faults() {
    let dir = tempfile::tempdir().unwrap();
    let store = Store::<sqlx::Sqlite>::new(super::sqlite::open(dir.path().join("carried.sqlite").to_str().unwrap()).await.unwrap());
    store.qualify_carried_activity_polls().await;
    store.close().await;
}
