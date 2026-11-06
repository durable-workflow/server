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

fn workflow_poll() -> Value {
    json!({"worker_id":"owner","task_queue":"carried","poll_request_id":" original-workflow-request \t","timeout_seconds":0,"history_page_size":5})
}

fn polling() -> super::carried_polls::Config<'static> {
    super::carried_polls::Config { protocol: Some("1.20"), pages: super::history_pages::HistoryPages { default: 1, max: 1 }, default_wait: 30 }
}

fn assert_granted(result: &Value) -> &Value {
    assert_eq!(result["leased"], true);
    assert_eq!(result.as_object().unwrap().len(), 2);
    let answer = &result["answer"];
    assert_eq!(answer["poll_status"], "leased");
    assert_eq!(answer["protocol_version"], "1.20");
    assert!(answer["server_capabilities"].is_object());
    assert!(chrono::DateTime::parse_from_rfc3339(answer["server_time"].as_str().unwrap()).is_ok());
    answer
}

fn assert_store_poll_matches(answer: &Value, mut poll: Value, workflow: bool) {
    poll["protocol_version"] = json!(super::WORKER_PROTOCOL_VERSION);
    if workflow { poll["task"]["task_kind"] = json!("workflow"); }
    poll["server_time"] = answer["server_time"].clone();
    assert_eq!(*answer, poll, "all original authority/history fields match the ordinary poll");
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
            BTreeMap::new(), None, polling(), Default::default(), None, None).await
    }

    async fn carrier_activity_state(&self, task: &Value) -> (String, i64) {
        let row = Self::query("SELECT status,attempt_count FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='activity'")
            .bind(task["run_id"].as_str().unwrap()).fetch_one(&self.pool).await.unwrap();
        (DB::string(&row, "status").unwrap(), DB::number(&row, "attempt_count").unwrap())
    }

    async fn carrier_activity(&self, name: &str) -> Value {
        let task = self.carrier_task(name).await;
        self.carrier_complete(&task, None).await.unwrap();
        self.poll(json!({"worker_id":"owner","task_queue":"carried","timeout_seconds":0}), "activity", "1.20").await.unwrap()["task"].clone()
    }

    async fn carrier_standalone(&self, name: &str) -> Value {
        let request = super::standalone_activities::start_request(&json!({"activity_id":name,"activity_type":"carried","task_queue":"carried"})).unwrap();
        self.start_standalone_activity(request, Some(super::envelope(&json!({"input":input()}), "input").unwrap()), None, json!({})).await.unwrap();
        self.poll(json!({"worker_id":"owner","task_queue":"carried","timeout_seconds":0}), "activity", "1.20").await.unwrap()["task"].clone()
    }

    async fn carrier_finish_activity(&self, task: &Value, poll: Option<Value>) -> Result<Value> {
        let mut body = json!({"lease_owner":"owner","activity_attempt_id":task["activity_attempt_id"],"result":input()});
        if let Some(poll) = poll { body["workflow_poll"] = poll; }
        self.complete_activity(task["task_id"].as_str().unwrap(), body, None, polling()).await
    }

    async fn carrier_finish_workflow(&self, task: &Value, activity: Option<Value>, workflow: Option<Value>, schedule: bool) -> Result<Value> {
        let commands = if schedule {
            json!([{"type":"schedule_activity","activity_type":"carried","arguments":input()}])
        } else {
            json!([{"type":"record_side_effect","result":input()}])
        };
        let mut body = json!({"lease_owner":"owner","workflow_task_attempt":task["workflow_task_attempt"],"commands":commands});
        if let Some(poll) = activity { body["activity_poll"] = poll; }
        if let Some(poll) = workflow { body["workflow_poll"] = poll; }
        self.complete_or_checkpoint(task["task_id"].as_str().unwrap(), body, Arc::new(Semaphore::new(1)),
            BTreeMap::new(), None, polling(), Default::default(), None, None).await
    }

    pub(super) async fn qualify_carried_activity_polls(&self) {
        let store = self.in_namespace("carrier-positive");
        store.carrier_workers().await;
        let task = store.carrier_task("carrier-positive").await;
        store.test_transitions.store(0, Ordering::Relaxed);
        let completed = store.carrier_complete(&task, Some(activity_poll())).await.unwrap();
        assert_eq!(completed["recorded"], true);
        assert_granted(&completed["activity_poll"]);
        assert_eq!(store.test_transitions.load(Ordering::Relaxed), 1);
        assert_eq!(store.carrier_activity_state(&task).await, ("leased".into(), 1));
        store.test_transitions.store(0, Ordering::Relaxed);
        let first = store.poll(activity_poll(), "activity", "1.20").await.unwrap();
        assert_store_poll_matches(assert_granted(&completed["activity_poll"]), first.clone(), false);
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

        for unknown_deadline in [false, true] {
            let store = self.in_namespace(&format!("carrier-validation-{unknown_deadline}"));
            store.carrier_workers().await;
            let task = store.carrier_task(&format!("carrier-validation-{unknown_deadline}")).await;
            let id = ulid::Ulid::new().to_string().to_lowercase();
            let at = super::store::now();
            let expires = (!unknown_deadline).then_some(at + chrono::Duration::seconds(60));
            Self::query("INSERT INTO workflow_update_validation_tasks(id,idempotency_key,namespace,workflow_instance_id,workflow_run_id,workflow_type,task_queue,update_name,request_id,input_hash,payload_codec,arguments,status,attempt_count,lease_owner,lease_expires_at,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,'carried','carried','validate','original-validation-request',$6,'avro',$7,'leased',1,'owner',$8,$9,$10)")
                .bind(&id).bind(&id).bind(store.namespace.as_ref()).bind(task["workflow_id"].as_str().unwrap())
                .bind(task["run_id"].as_str().unwrap()).bind("a".repeat(64)).bind(input()["blob"].as_str().unwrap())
                .bind(DB::optional_time(expires)).bind(DB::bind_time(at)).bind(DB::bind_time(at))
                .execute(&store.pool).await.unwrap();
            let before = Self::query("SELECT * FROM workflow_update_validation_tasks WHERE id=$1").bind(&id).fetch_one(&store.pool).await.unwrap();
            let completed = store.carrier_complete(&task, Some(activity_poll())).await.unwrap();
            assert_eq!(completed["recorded"], true);
            assert!(completed.get("activity_poll").is_none(), "validation owns the workflow slot, unknown_deadline={unknown_deadline}");
            assert_eq!(store.carrier_activity_state(&task).await, ("ready".into(), 0));
            let activity = Self::query("SELECT status,attempt_count,current_attempt_id FROM activity_executions WHERE workflow_run_id=$1")
                .bind(task["run_id"].as_str().unwrap()).fetch_one(&store.pool).await.unwrap();
            assert_eq!(DB::string(&activity, "status").unwrap(), "pending");
            assert_eq!(DB::number(&activity, "attempt_count").unwrap(), 0);
            assert!(DB::optional_string(&activity, "current_attempt_id").unwrap().is_none());
            assert_eq!(Self::scalar("SELECT COUNT(*) FROM activity_attempts WHERE workflow_run_id=$1")
                .bind(task["run_id"].as_str().unwrap()).fetch_one(&store.pool).await.unwrap(), 0);
            assert_eq!(Self::scalar("SELECT COUNT(*) FROM dw_poll_receipts WHERE namespace=$1")
                .bind(store.namespace.as_ref()).fetch_one(&store.pool).await.unwrap(), 0);
            let after = Self::query("SELECT * FROM workflow_update_validation_tasks WHERE id=$1").bind(&id).fetch_one(&store.pool).await.unwrap();
            for key in ["id", "idempotency_key", "namespace", "workflow_instance_id", "workflow_run_id", "workflow_type", "task_queue", "update_name", "request_id", "input_hash", "payload_codec", "arguments", "status", "lease_owner"] {
                assert_eq!(DB::string(&after, key).unwrap(), DB::string(&before, key).unwrap(), "validation {key}");
            }
            assert_eq!(DB::number(&after, "attempt_count").unwrap(), 1);
            for key in ["lease_expires_at", "approved_at", "rejected_at", "failed_at", "timed_out_at", "created_at", "updated_at"] {
                assert_eq!(DB::optional_instant(&after, key).unwrap(), DB::optional_instant(&before, key).unwrap(), "validation {key}");
            }
        }

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
        let workflow = store.admit_workflow_carrier(Some(workflow_poll()), "owner", polling()).await.unwrap().unwrap();
        let mut tx = store.begin().await.unwrap();
        assert!(matches!(store.claim_carried_activity(&mut tx, &poll, "other").await.unwrap(), super::carried_polls::Outcome::Declined));
        assert!(matches!(store.claim_carried_workflow(&mut tx, &workflow, "other").await.unwrap(), super::carried_polls::Outcome::Declined));
        Self::query("UPDATE workflow_worker_registrations SET build_id='replacement' WHERE namespace=$1 AND worker_id='owner'")
            .bind(store.namespace.as_ref()).execute(&mut *tx).await.unwrap();
        assert!(matches!(store.claim_carried_activity(&mut tx, &poll, "owner").await.unwrap(), super::carried_polls::Outcome::Declined));
        assert!(matches!(store.claim_carried_workflow(&mut tx, &workflow, "owner").await.unwrap(), super::carried_polls::Outcome::Declined));
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
        assert_granted(&retried["activity_poll"]);
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

    pub(super) async fn qualify_carried_workflow_polls(&self) {
        let store = self.in_namespace("carried-workflow-positive");
        store.carrier_workers().await;
        let activity = store.carrier_activity("carried-workflow-positive").await;
        store.test_transitions.store(0, Ordering::Relaxed);
        let done = store.carrier_finish_activity(&activity, Some(workflow_poll())).await.unwrap();
        assert_eq!(done["recorded"], true);
        assert_granted(&done["workflow_poll"]);
        assert_eq!(store.test_transitions.load(Ordering::Relaxed), 1);
        store.test_transitions.store(0, Ordering::Relaxed);
        let replay = store.poll_with_pages(workflow_poll(), "workflow", "1.20", polling().pages, polling().default_wait).await.unwrap();
        assert_store_poll_matches(assert_granted(&done["workflow_poll"]), replay.clone(), true);
        assert_eq!(store.test_transitions.load(Ordering::Relaxed), 0, "a carried workflow lease replays without another transition");
        assert_eq!(replay["task"]["task_id"], done["next_task_id"]);
        assert_eq!(replay["task"]["history_events"].as_array().unwrap().len(), 1, "use the actual configured page maximum");
        assert!(replay["task"]["next_history_page_token"].is_string());
        assert_eq!(replay["task"]["workflow_task_attempt"], 1);
        assert!(replay["task"]["ready_age_ms"].is_u64());
        assert_eq!(store.poll_with_pages(workflow_poll(), "workflow", "1.20", polling().pages, polling().default_wait).await.unwrap(), replay);
        let duplicate = store.carrier_finish_activity(&activity, Some(workflow_poll())).await.unwrap_err();
        assert!(matches!(duplicate, super::RuntimeError::Protocol { ref response, .. } if response["reason"] == "stale_attempt" && response.get("workflow_poll").is_none()), "{duplicate:?}");

        for case in ["omitted", "wrong-owner", "wrong-queue", "missing-id", "validation-kind", "draining"] {
            let store = self.in_namespace(&format!("workflow-carrier-{case}"));
            store.carrier_workers().await;
            let task = store.carrier_activity(&format!("workflow-carrier-{case}")).await;
            let mut poll = workflow_poll();
            match case {
                "wrong-owner" => poll["worker_id"] = json!("other"),
                "wrong-queue" => poll["task_queue"] = json!("other"),
                "missing-id" => { poll.as_object_mut().unwrap().remove("poll_request_id"); }
                "validation-kind" => poll["task_kinds"] = json!(["workflow","update_validation"]),
                "draining" => {
                    Self::query("UPDATE workflow_worker_registrations SET status='draining' WHERE namespace=$1 AND worker_id='owner'")
                        .bind(store.namespace.as_ref()).execute(&store.pool).await.unwrap();
                }
                _ => {}
            }
            let completed = store.carrier_finish_activity(&task, (case != "omitted").then_some(poll)).await.unwrap();
            assert_eq!(completed["recorded"], true, "{case}");
            assert!(completed.get("workflow_poll").is_none(), "{case}");
            let row = Self::query("SELECT status,attempt_count,lease_owner FROM workflow_tasks WHERE id=$1")
                .bind(completed["next_task_id"].as_str().unwrap()).fetch_one(&store.pool).await.unwrap();
            assert_eq!(DB::string(&row, "status").unwrap(), "ready", "{case}");
            assert_eq!(DB::number(&row, "attempt_count").unwrap(), 0);
            assert!(DB::optional_string(&row, "lease_owner").unwrap().is_none());
        }

        let store = self.in_namespace("workflow-carrier-occupied");
        store.carrier_workers().await;
        let first = store.carrier_activity("first-occupied-activity").await;
        let other = store.carrier_standalone("other-occupied-activity").await;
        assert_ne!(first["task_id"], other["task_id"]);
        Self::query("UPDATE workflow_tasks SET lease_expires_at=NULL WHERE id=$1")
            .bind(other["task_id"].as_str().unwrap()).execute(&store.pool).await.unwrap();
        let done = store.carrier_finish_activity(&first, Some(workflow_poll())).await.unwrap();
        assert_eq!(done["recorded"], true);
        assert!(done.get("workflow_poll").is_none(), "unknown opposite expiry protects the activity slot");

        let store = self.in_namespace("workflow-carrier-prior-empty");
        store.carrier_workers().await;
        let activity = store.carrier_activity("prior-empty-activity").await;
        let empty = store.poll(workflow_poll(), "workflow", "1.20").await.unwrap();
        assert!(empty["task"].is_null());
        let done = store.carrier_finish_activity(&activity, Some(workflow_poll())).await.unwrap();
        assert_eq!(done["workflow_poll"], json!({"leased":false}));
        assert_eq!(store.poll(workflow_poll(), "workflow", "1.20").await.unwrap(), empty);
        let mut fresh = workflow_poll();
        fresh["poll_request_id"] = json!("new-id-after-prior-empty");
        assert_eq!(store.poll(fresh, "workflow", "1.20").await.unwrap()["task"]["task_id"], done["next_task_id"]);

        let store = self.in_namespace("workflow-carrier-empty-arrival");
        store.carrier_workers().await;
        let activity = store.carrier_standalone("closed-carrier-host").await;
        let done = store.carrier_finish_activity(&activity, Some(workflow_poll())).await.unwrap();
        assert_eq!(done["recorded"], true);
        assert_eq!(done["workflow_poll"], json!({"leased":false}));
        let next = store.start(json!({"workflow_id":"later-arrival","workflow_type":"carried","task_queue":"carried","input":input()}),
            BTreeMap::new(), None, None).await.unwrap();
        let polled = store.poll(workflow_poll(), "workflow", "1.20").await.unwrap();
        assert_eq!(polled["task"]["run_id"], next["run_id"]);
        assert_eq!(polled["task"]["workflow_task_attempt"], 1);

        let store = self.in_namespace("workflow-carrier-claim-fault");
        store.carrier_workers().await;
        let activity = store.carrier_activity("workflow-claim-fault").await;
        let create = match DB::NAME {
            "SQLite" => "CREATE TRIGGER dw_carried_workflow_fault BEFORE UPDATE ON workflow_tasks WHEN NEW.task_type='workflow' AND NEW.status='leased' BEGIN SELECT RAISE(ABORT,'carried workflow claim fault'); END",
            "PostgreSQL" => "CREATE FUNCTION dw_carried_workflow_fault_fn() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.task_type='workflow' AND NEW.status='leased' THEN RAISE EXCEPTION 'carried workflow claim fault'; END IF; RETURN NEW; END $$; CREATE TRIGGER dw_carried_workflow_fault BEFORE UPDATE ON workflow_tasks FOR EACH ROW EXECUTE FUNCTION dw_carried_workflow_fault_fn()",
            "MySQL" => "CREATE TRIGGER dw_carried_workflow_fault BEFORE UPDATE ON workflow_tasks FOR EACH ROW BEGIN IF NEW.task_type='workflow' AND NEW.status='leased' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='carried workflow claim fault'; END IF; END",
            _ => unreachable!(),
        };
        let mut connection = store.pool.acquire().await.unwrap();
        (&mut *connection).execute(create).await.unwrap();
        drop(connection);
        let error = store.carrier_finish_activity(&activity, Some(workflow_poll())).await.unwrap_err();
        assert!(format!("{error:?}").contains("carried workflow claim fault"), "{error:?}");
        let mut connection = store.pool.acquire().await.unwrap();
        (&mut *connection).execute(match DB::NAME {
            "PostgreSQL" => "DROP TRIGGER dw_carried_workflow_fault ON workflow_tasks; DROP FUNCTION dw_carried_workflow_fault_fn()",
            _ => "DROP TRIGGER dw_carried_workflow_fault",
        }).await.unwrap();
        drop(connection);
        let row = Self::query("SELECT status FROM workflow_tasks WHERE id=$1").bind(activity["task_id"].as_str().unwrap())
            .fetch_one(&store.pool).await.unwrap();
        assert_eq!(DB::string(&row, "status").unwrap(), "leased", "unexpected claim failure rolls back the parent completion");
        assert_granted(&store.carrier_finish_activity(&activity, Some(workflow_poll())).await.unwrap()["workflow_poll"]);
    }

    pub(super) async fn qualify_workflow_completion_carriers(&self) {
        for case in ["activity-first", "activity-empty", "workflow-only", "foreign-activity", "foreign-workflow"] {
            let store = self.in_namespace(&format!("paired-{case}"));
            store.carrier_workers().await;
            let task = store.carrier_task(&format!("paired-parent-{case}")).await;
            let next = store.start(json!({"workflow_id":format!("paired-next-{case}"),"workflow_type":"carried","task_queue":"carried","input":input()}),
                BTreeMap::new(), None, None).await.unwrap();
            let mut activity = activity_poll();
            let mut workflow = workflow_poll();
            if case == "foreign-activity" { activity["worker_id"] = json!("other"); }
            if case == "foreign-workflow" { workflow["worker_id"] = json!("other"); }
            let (done, counts) = super::sql_counts::measure(store.carrier_finish_workflow(&task,
                (case != "workflow-only").then_some(activity), Some(workflow), case == "activity-first")).await;
            let done = done.unwrap();
            assert_eq!(done["recorded"], true, "{case}");
            match case {
                "activity-first" => {
                    assert_granted(&done["activity_poll"]);
                    assert!(done.get("workflow_poll").is_none(), "absence delivers no workflow lease and releases its SDK reservation");
                    assert_eq!(counts.count_prefix("SELECT COUNT(*) FROM workflow_tasks WHERE namespace=$1 AND lease_owner=$2 AND task_type='activity'"), 0,
                        "workflow carrier is not attempted after an activity grant");
                    let row = Self::query("SELECT status,attempt_count FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow'")
                        .bind(next["run_id"].as_str().unwrap()).fetch_one(&store.pool).await.unwrap();
                    assert_eq!(DB::string(&row, "status").unwrap(), "ready");
                    assert_eq!(DB::number(&row, "attempt_count").unwrap(), 0);
                }
                "activity-empty" | "workflow-only" => {
                    assert_granted(&done["workflow_poll"]);
                    if case == "activity-empty" { assert_eq!(done["activity_poll"], json!({"leased":false})); }
                    let replay = store.poll(workflow_poll(), "workflow", "1.20").await.unwrap();
                    assert_eq!(replay["task"]["run_id"], next["run_id"]);
                    let occupied = Self::scalar("SELECT COUNT(*) FROM workflow_tasks WHERE namespace=$1 AND lease_owner='owner' AND task_type='activity' AND status='leased'")
                        .bind(store.namespace.as_ref()).fetch_one(&store.pool).await.unwrap();
                    assert_eq!(occupied, 0, "the same completion cannot hold both kinds");
                }
                _ => {
                    assert!(done.get("activity_poll").is_none());
                    assert!(done.get("workflow_poll").is_none(), "both carriers must bind to the completion owner");
                    assert_eq!(Self::scalar("SELECT COUNT(*) FROM workflow_tasks WHERE namespace=$1 AND status='leased'")
                        .bind(store.namespace.as_ref()).fetch_one(&store.pool).await.unwrap(), 0);
                }
            }
        }
    }

    pub(super) async fn qualify_retained_workflow_poll_keys(&self) {
        for expired_binding in [false, true] {
            for carrier in [false, true] {
                let name = format!("retained-workflow-key-{expired_binding}-{carrier}");
                let store = self.in_namespace(&name);
                store.carrier_workers().await;
                let parent = store.carrier_task(&format!("{name}-parent")).await;
                let next = store.start(json!({"workflow_id":format!("{name}-next"),"workflow_type":"carried","task_queue":"carried","input":input()}),
                    BTreeMap::new(), None, None).await.unwrap();
                let raw_id = " \u{feff}\u{200b}original-legacy-id\u{200e} ";
                let mut body = workflow_poll();
                body["poll_request_id"] = json!(raw_id);
                // Use the actual former key recipe and normal claim/receipt
                // writers, rather than fabricating a cached lease document.
                let legacy = store.workflow_poll_request("owner", "carried", raw_id.trim().to_owned(), None, 30, &["workflow"]).await.unwrap();
                let mut tx = store.begin().await.unwrap();
                let leased = store.claim_in(&mut tx, &body, "workflow", "1.20", Some(1), Some(&legacy)).await.unwrap().unwrap();
                assert_eq!(leased["run_id"], next["run_id"]);
                tx.commit().await.unwrap();
                if expired_binding { store.expire_workflow_poll_binding_for_test(&legacy).await; }
                let before = Self::scalar("SELECT COUNT(*) FROM dw_query_cache").fetch_one(&store.pool).await.unwrap();
                store.test_transitions.store(0, Ordering::Relaxed);
                let replay = if carrier {
                    let done = store.carrier_finish_workflow(&parent, None, Some(body.clone()), false).await.unwrap();
                    assert_eq!(done["recorded"], true);
                    assert_granted(&done["workflow_poll"]).clone()
                } else {
                    store.poll(body.clone(), "workflow", "1.20").await.unwrap()
                };
                let mut expected = leased.clone();
                if carrier { expected["task_kind"] = json!("workflow"); }
                assert_eq!(replay["task"], expected, "the exact supplied legacy identity retains its committed lease and page");
                assert_eq!(store.test_transitions.load(Ordering::Relaxed), usize::from(carrier || expired_binding));
                assert_eq!(Self::scalar("SELECT COUNT(*) FROM dw_query_cache").fetch_one(&store.pool).await.unwrap(), before,
                    "do not create a new alias, binding or answer for a retained legacy receipt");
                let row = Self::query("SELECT attempt_count FROM workflow_tasks WHERE id=$1").bind(leased["task_id"].as_str().unwrap())
                    .fetch_one(&store.pool).await.unwrap();
                assert_eq!(DB::number(&row, "attempt_count").unwrap(), 1);
                let again = store.poll(body, "workflow", "1.20").await.unwrap();
                assert_eq!(again["task"], leased);
            }
        }

        for (index, malformed) in [json!(false), json!({"task":null,"poll_status":"leased"}), json!({"task":[],"poll_status":"empty"})].into_iter().enumerate() {
            let name = format!("malformed-workflow-receipt-{index}");
            let store = self.in_namespace(&name);
            store.carrier_workers().await;
            let activity = store.carrier_activity(&name).await;
            let poll = workflow_poll();
            let request = store.workflow_poll_request("owner", "carried", super::workflow_poll_requests::request_id(&poll).unwrap(),
                poll.get("poll_request_id").and_then(Value::as_str), 30, &["workflow"]).await.unwrap();
            let mut tx = store.begin().await.unwrap();
            Self::remember_workflow_poll(&mut tx, &request, &malformed).await.unwrap();
            tx.commit().await.unwrap();
            let error = store.carrier_finish_activity(&activity, Some(poll)).await.unwrap_err();
            assert!(matches!(error, super::RuntimeError::Refused { status: axum::http::StatusCode::INTERNAL_SERVER_ERROR, reason: "invalid_poll_receipt" }), "{error:?}");
            let row = Self::query("SELECT status FROM workflow_tasks WHERE id=$1").bind(activity["task_id"].as_str().unwrap())
                .fetch_one(&store.pool).await.unwrap();
            assert_eq!(DB::string(&row, "status").unwrap(), "leased", "malformed stored replies cannot become an empty eager result or commit the parent");
        }
    }
}

#[tokio::test]
async fn sqlite_carried_activity_polls_preserve_owner_capacity_and_faults() {
    let dir = tempfile::tempdir().unwrap();
    let store = Store::<sqlx::Sqlite>::new(super::sqlite::open(dir.path().join("carried.sqlite").to_str().unwrap()).await.unwrap());
    store.qualify_carried_activity_polls().await;
    store.qualify_carried_workflow_polls().await;
    store.qualify_workflow_completion_carriers().await;
    store.qualify_retained_workflow_poll_keys().await;
    store.close().await;
}
