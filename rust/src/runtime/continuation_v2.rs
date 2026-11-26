//! Activity workflow continuation v2 (Server #1181,
//! `docs/contracts/activity-workflow-continuation-v2.md`).
//!
//! The worker speculates from its sticky cache, so the Server proves the
//! prefix in O(1): the run's last sequence is the worker's boundary and the
//! event there has the cached ID. One write transaction checks the receipt,
//! fences the attempt, proves the prefix, appends the result, completes the
//! resumed workflow task with the worker's commands, carries the next
//! activity and returns the history tail. A decline keeps the ordinary
//! completion with its carried workflow poll. Every fault rolls everything
//! back.
use super::continuation::{COMMANDS, MAX_COMMANDS, exact_fields, identifier};
use super::continuation_tail::{BUDGET_REASONS, Tail};
use super::{Result, refuse};
use axum::http::StatusCode;
use serde_json::{Value, json};

pub(super) const VERSION: i64 = 2;
const RECEIPT: &str = "activity_workflow_continuation_v2";
const RECEIPT_BYTES: usize = 16 * 1024;
/// The decline reasons, in the order the Server checks them.
const DECLINES: [&str; 12] = ["external_payload", "cache_identity_mismatch", "run_not_open", "standalone_activity",
    "cancellation_requested", "parallel_group", "pending_workflow_task", "pending_update", "history_changed",
    "workflow_slot_unavailable", "no_workflow_task", "dispatch_selected_other_task"];
/// The proof's reason, reported by `complete_activity_in`.
pub(super) const DECLINE_FIELD: &str = "continuation_v2_decline";

/// A version-2 continuation request's own member.
pub(crate) struct Request {
    pub(super) through: i64,
    pub(super) last_event_id: String,
    pub(super) sticky_cache: Value,
    pub(super) commands: Vec<Value>,
}

/// Whether the continuation asks for version 2: the integer 2 beside the
/// v2-only `sticky_cache` member. Any other version keeps v1's parser and
/// its refusals.
pub(super) fn requested(body: &Value) -> bool {
    let continuation = &body["continuation"];
    continuation["version"].as_i64() == Some(VERSION) && !continuation["version"].is_f64()
        && continuation.get("sticky_cache").is_some()
}

/// Malformed continuations are refused before anything is written.
pub(super) fn parse(value: Option<&Value>) -> Result<Request> {
    let invalid = |reason: &'static str| refuse(StatusCode::UNPROCESSABLE_ENTITY, reason);
    let Some(Value::Object(fields)) = value else { return Err(invalid("invalid_continuation")) };
    if !fields.keys().all(|key| matches!(key.as_str(),
        "version" | "history_through_sequence" | "history_last_event_id" | "sticky_cache" | "commands"))
    {
        return Err(invalid("invalid_continuation"));
    }
    let through = fields.get("history_through_sequence").filter(|through| !through.is_f64()).and_then(Value::as_i64)
        .filter(|through| *through >= 1).ok_or_else(|| invalid("invalid_continuation"))?;
    let last_event_id = fields.get("history_last_event_id").and_then(Value::as_str)
        .filter(|id| !id.is_empty() && id.chars().count() <= 64).ok_or_else(|| invalid("invalid_continuation"))?.to_owned();
    // The ordinary `sticky_cache` rules apply with the nested completion's
    // request checks; its boundary must be the speculated one.
    let sticky_cache = fields.get("sticky_cache").filter(|claim| claim.is_object())
        .filter(|claim| !claim["history_last_sequence"].is_f64() && claim["history_last_sequence"].as_i64() == Some(through))
        .ok_or_else(|| invalid("invalid_continuation"))?.clone();
    let commands = fields.get("commands").and_then(Value::as_array)
        .filter(|commands| !commands.is_empty() && commands.len() <= MAX_COMMANDS)
        .ok_or_else(|| invalid("invalid_continuation"))?;
    for command in commands {
        let supported = command["type"].as_str().is_some_and(|kind| COMMANDS.contains(&kind));
        let scoped = command.get("cancellation_scope_id").is_some() || command.get("parallel_group_path").is_some();
        if !supported || scoped {
            return Err(invalid("unsupported_continuation_command"));
        }
    }
    Ok(Request { through, last_event_id, sticky_cache, commands: commands.clone() })
}

fn declined(reason: &'static str) -> Value {
    json!({"version": VERSION, "outcome": "declined", "reason": reason})
}

/// The tail range a receipt keeps in place of the tail's events.
fn tail_range(tail: &Value) -> Value {
    match tail.get("omitted") {
        Some(reason) => json!({"after_sequence": tail["after_sequence"], "omitted": reason}),
        None => json!({"after_sequence": tail["after_sequence"], "last_sequence": tail["last_sequence"]}),
    }
}

fn integer(value: &Value) -> Option<i64> {
    value.as_i64().filter(|_| !value.is_f64())
}

fn valid_tail_range(range: &Value) -> bool {
    let Some(after) = integer(&range["after_sequence"]).filter(|after| *after >= 1) else { return false };
    if exact_fields(range, &["after_sequence", "omitted"]) {
        return range["omitted"].as_str().is_some_and(|reason| BUDGET_REASONS.contains(&reason));
    }
    exact_fields(range, &["after_sequence", "last_sequence"]) && integer(&range["last_sequence"]).is_some_and(|last| last > after)
}

/// A stored decision is authoritative only for the completed activity, its
/// own resumed task and a tail range after the worker's boundary.
fn valid_saved_answer(answer: &Value, task_id: &str, run_id: &str, attempt: &Value) -> bool {
    let decision = &answer["continuation"];
    let accepted = decision["outcome"] == "accepted";
    let mut fields = vec!["task_id", "activity_attempt_id", "outcome", "recorded", "reason", "next_task_id", "continuation"];
    if accepted {
        fields.push("continuation_tail");
    }
    if !exact_fields(answer, &fields) || answer["task_id"].as_str() != Some(task_id)
        || !identifier(attempt) || answer["activity_attempt_id"] != *attempt
        || answer["outcome"] != "completed" || answer["recorded"] != true || !answer["reason"].is_null()
        || !(answer["next_task_id"].is_null() || identifier(&answer["next_task_id"]))
        || integer(&decision["version"]) != Some(VERSION)
    {
        return false;
    }
    if !accepted {
        return decision["outcome"] == "declined" && exact_fields(decision, &["version", "outcome", "reason"])
            && decision["reason"].as_str().is_some_and(|reason| DECLINES.contains(&reason));
    }
    let completed = &decision["completion"];
    exact_fields(decision, &["version", "outcome", "workflow_task_id", "workflow_task_attempt", "completion"])
        && identifier(&decision["workflow_task_id"]) && decision["workflow_task_id"] == answer["next_task_id"]
        && integer(&decision["workflow_task_attempt"]).is_some_and(|attempt| attempt > 0)
        && exact_fields(completed, &["task_id", "run_id", "run_status", "workflow_task_attempt", "outcome", "recorded", "reason", "created_task_ids"])
        && completed["task_id"] == decision["workflow_task_id"]
        && completed["workflow_task_attempt"] == decision["workflow_task_attempt"]
        && completed["run_id"].as_str() == Some(run_id)
        && matches!(completed["run_status"].as_str(), Some("waiting" | "completed"))
        && completed["outcome"] == "completed" && completed["recorded"] == true && completed["reason"].is_null()
        && completed["created_task_ids"].as_array().is_some_and(|ids| ids.len() <= MAX_COMMANDS + 1 && ids.iter().all(identifier))
        && valid_tail_range(&answer["continuation_tail"])
}

store_methods! {
    /// The stored v2 decision for exactly this request, if any.
    fn continuation_v2_receipt(task: &DB::Row, request_hash: &str) -> Result<Option<Value>> {
        let Some(receipt) = DB::optional_document_row(task, "receipt")? else { return Ok(None); };
        let Some(wrapper) = receipt.get(RECEIPT) else { return Ok(None); };
        let stored_owner = DB::optional_string(task, "lease_owner")?;
        let valid = exact_fields(wrapper, &["version", "request_hash", "answer"])
            && integer(&wrapper["version"]) == Some(VERSION)
            && wrapper["request_hash"].as_str().is_some_and(|hash| hash.len() == 64
                && hash.bytes().all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b)))
            && DB::string(task, "task_type")? == "activity" && DB::string(task, "status")? == "completed"
            && receipt["lease_owner"].as_str().is_some_and(|owner| stored_owner.as_deref() == Some(owner))
            && valid_saved_answer(&wrapper["answer"], &DB::string(task, "id")?,
                &DB::string(task, "workflow_run_id")?, &receipt["activity_attempt_id"])
            && wrapper.to_string().len() <= RECEIPT_BYTES;
        if !valid { return Err(refuse(StatusCode::INTERNAL_SERVER_ERROR, "invalid_continuation_receipt")); }
        if wrapper["request_hash"].as_str() != Some(request_hash) {
            return Err(refuse(StatusCode::CONFLICT, "conflicting_completion"));
        }
        let mut answer = wrapper["answer"].clone();
        answer["recorded"] = json!(false);
        answer["reason"] = json!("already_completed");
        Ok(Some(answer))
    }

    /// The first decline among the checks under the transition lock, after
    /// the ordinary activity fencing and before the result is appended.
    /// `run` is the row `activity_run` read in this transaction.
    pub(super) async fn continuation_v2_decline(
        &self, tx: &mut super::transaction::Transaction<'_, DB>, task: &DB::Row, run: &DB::Row, activity: &DB::Row,
        request: &Request,
    ) -> Result<Option<&'static str>> {
        let run_id = DB::string(run, "id")?;
        let owner = DB::optional_string(task, "lease_owner")?.unwrap_or_default();
        let registration = Self::query("SELECT build_id,sdk_version FROM workflow_worker_registrations WHERE namespace=$1 AND worker_id=$2")
            .bind(self.namespace.as_ref()).bind(owner.as_str()).fetch_optional(&mut **tx).await?;
        let build = match &registration {
            Some(row) => DB::optional_string(row, "build_id")?.or(DB::optional_string(row, "sdk_version")?),
            None => None,
        };
        let claim = |field: &str| request.sticky_cache[field].as_str().unwrap_or_default();
        if claim("worker_id") != owner || claim("run_id") != run_id
            || claim("workflow_id") != DB::string(run, "workflow_instance_id")?
            || build.as_deref().is_none_or(|build| claim("build_id") != build)
        {
            return Ok(Some("cache_identity_mismatch"));
        }
        if !matches!(DB::string(run, "status")?.as_str(), "running" | "waiting")
            || [DB::optional_instant(run, "execution_deadline_at")?, DB::optional_instant(run, "run_deadline_at")?]
                .into_iter().flatten().any(|deadline| deadline <= super::store::now())
        {
            return Ok(Some("run_not_open"));
        }
        if DB::string(run, "workflow_type")? == super::standalone_activities::HOST_TYPE {
            return Ok(Some("standalone_activity"));
        }
        if Self::cancellation_request(run)?.is_some() {
            return Ok(Some("cancellation_requested"));
        }
        if DB::optional_document_row(activity, "parallel_group_path")?.is_some() {
            return Ok(Some("parallel_group"));
        }
        let pending = Self::scalar("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status IN ('ready','leased')")
            .bind(run_id.as_str()).fetch_one(&mut **tx).await?;
        if pending > 0 {
            return Ok(Some("pending_workflow_task"));
        }
        let updates = Self::scalar("SELECT COUNT(*) FROM workflow_updates WHERE workflow_run_id=$1 AND status='accepted'")
            .bind(run_id.as_str()).fetch_one(&mut **tx).await?;
        let validations = Self::scalar("SELECT COUNT(*) FROM workflow_update_validation_tasks WHERE workflow_run_id=$1 AND status IN ('pending','leased')")
            .bind(run_id.as_str()).fetch_one(&mut **tx).await?;
        if updates > 0 || validations > 0 {
            return Ok(Some("pending_update"));
        }
        let last = DB::number(run, "last_history_sequence")?;
        // Control 2 of the contract's bypass guard: the O(1) proof trusts the
        // allocator's invariant, which test builds check independently.
        if cfg!(any(test, debug_assertions)) {
            let row = Self::query("SELECT COUNT(*) AS event_count,MAX(sequence) AS max_sequence FROM workflow_history_events WHERE workflow_run_id=$1")
                .bind(run_id.as_str()).fetch_one(&mut **tx).await?;
            let (count, max) = (DB::number(&row, "event_count")?, DB::optional_number(&row, "max_sequence")?.unwrap_or(0));
            let holds = if DB::optional_string(run, "import_source")?.is_some() { max <= last } else { count == last && max == last };
            if !holds {
                return Err(refuse(StatusCode::INTERNAL_SERVER_ERROR, "history_invariant_violated"));
            }
        }
        if last != request.through {
            return Ok(Some("history_changed"));
        }
        let event = Self::query("SELECT id FROM workflow_history_events WHERE workflow_run_id=$1 AND sequence=$2")
            .bind(run_id.as_str()).bind(request.through).fetch_optional(&mut **tx).await?;
        let same = match event {
            Some(event) => DB::string(&event, "id")? == request.last_event_id,
            None => false,
        };
        Ok((!same).then_some("history_changed"))
    }

    /// One write transaction: the receipt first, then the ordinary fencing,
    /// the proof, the speculative completion and its tail, and the receipt.
    /// `command_body` is the nested completion: the worker's commands, its
    /// `sticky_cache` claim, a `continuation_tail` request and the carried
    /// `activity_poll`.
    #[allow(clippy::too_many_arguments)]
    pub(crate) async fn complete_activity_continuation_v2(
        &self, task_id: &str, mut body: Value, continuation: Request,
        external: Option<super::external_resolution::PreparedExternal>, result: String,
        command_body: Value, command_external: super::external_resolution::PreparedCommands,
        transition_codec: std::sync::Arc<tokio::sync::Semaphore>,
        worker: Option<super::runtime_credentials::Principal>,
        polling: super::carried_polls::Config<'_>, limits: super::memo_limits::StructuralLimits,
    ) -> Result<Value> {
        let hash = super::continuation::request_hash(&body, polling.protocol);
        let owner = super::text(&body, "lease_owner")?.to_owned();
        let fields = body.as_object_mut().expect("validated request object");
        fields.remove("continuation");
        let raw_workflow_poll = fields.remove("workflow_poll");
        let raw_activity_poll = fields.remove("activity_poll");
        let workflow_poll = self.admit_workflow_carrier(raw_workflow_poll, &owner, polling).await?;
        let mut tx = self.begin().await?;
        let task = self.task_row(&mut tx, task_id).await?;
        if let Some(mut answer) = Self::continuation_v2_receipt(&task, &hash)? {
            // Committed events never change, so the tail is read again by
            // its range. A pruned range is unavailable.
            let range = answer["continuation_tail"].clone();
            if let Some(after) = range["after_sequence"].as_i64() {
                let tail = match range["omitted"].as_str() {
                    Some(reason) => Tail::Omitted { after_sequence: after,
                        reason: BUDGET_REASONS.into_iter().find(|known| *known == reason).expect("validated tail range") },
                    None => Self::continuation_tail_range(&mut tx, &DB::string(&task, "workflow_run_id")?, after,
                        range["last_sequence"].as_i64().expect("validated tail range")).await?,
                };
                answer["continuation_tail"] = tail.answer();
            }
            tx.rollback().await?;
            return self.present_continuation_v2_replay(answer, &owner, workflow_poll, raw_activity_poll, polling).await;
        }
        let receipt_body = body.clone();
        let mut answer;
        let decision;
        if external.is_some() || !command_external.is_empty() {
            answer = self.complete_activity_in(&mut tx, task_id, body, external.as_ref(), result, workflow_poll, None).await?;
            decision = declined("external_payload");
        } else {
            let saved = tx.continuation_savepoint().await?;
            answer = self.complete_activity_in(&mut tx, task_id, body, None, result.clone(), workflow_poll.clone(),
                Some(super::continuation::Prefix::V2(&continuation))).await?;
            #[cfg(test)]
            {
                let gate = self.test_continuation_gate.lock().expect("continuation test gate").take();
                if let Some(gate) = gate {
                    gate.entered.notify_one();
                    gate.resume.notified().await;
                }
            }
            let proof = answer.as_object_mut().and_then(|fields| fields.remove(DECLINE_FIELD))
                .and_then(|reason| DECLINES.into_iter().find(|known| reason.as_str() == Some(*known)));
            let leased = answer["workflow_poll"]["leased"] == true;
            let selected = answer["workflow_poll"]["answer"]["task"].clone();
            let same_task = leased && selected["task_id"].is_string() && selected["task_id"] == answer["next_task_id"];
            let decline = proof.or(if !leased {
                Some(if answer.get("workflow_poll").is_none() { "workflow_slot_unavailable" } else { "no_workflow_task" })
            } else if !same_task {
                Some("dispatch_selected_other_task")
            } else {
                None
            });
            if let Some(reason) = decline {
                tx.rollback_continuation(saved).await?;
                answer = self.complete_activity_in(&mut tx, task_id, receipt_body.clone(), None, result, workflow_poll, None).await?;
                decision = declined(reason);
            } else {
                let workflow_task = selected["task_id"].as_str().expect("selected task identity");
                let mut completion = command_body;
                completion["workflow_task_attempt"] = selected["workflow_task_attempt"].clone();
                let (held, mut completed) = self.complete_or_checkpoint_in(workflow_task, completion, transition_codec,
                    command_external, worker, polling, limits, None, None, Some(tx), Some(continuation.through)).await?;
                tx = held.expect("nested completion retains its transition");
                tx.release_continuation().await?;
                let fields = completed.as_object_mut().expect("completion object");
                let tail = fields.remove("continuation_tail").expect("a nested completion answers its tail");
                let activity_poll = fields.remove("activity_poll");
                let fields = answer.as_object_mut().expect("completion object");
                fields.remove("workflow_poll");
                fields.insert("continuation_tail".into(), tail);
                if let Some(poll) = activity_poll {
                    fields.insert("activity_poll".into(), poll);
                }
                decision = json!({"version": VERSION, "outcome": "accepted", "workflow_task_id": workflow_task,
                    "workflow_task_attempt": selected["workflow_task_attempt"], "completion": completed});
            }
        }
        answer["continuation"] = decision;
        // The receipt keeps the decision, its IDs and the tail range, never
        // the events. The ordinary activity receipt fields stay readable.
        let mut stored_answer = answer.clone();
        let fields = stored_answer.as_object_mut().expect("completion object");
        fields.remove("workflow_poll");
        fields.remove("activity_poll");
        if let Some(tail) = fields.get_mut("continuation_tail") {
            *tail = tail_range(tail);
        }
        if !valid_saved_answer(&stored_answer, task_id, &DB::string(&task, "workflow_run_id")?, &receipt_body["activity_attempt_id"]) {
            return Err(refuse(StatusCode::INTERNAL_SERVER_ERROR, "invalid_continuation_receipt"));
        }
        let wrapper = json!({"version": VERSION, "request_hash": hash, "answer": stored_answer});
        if wrapper.to_string().len() > RECEIPT_BYTES {
            return Err(refuse(StatusCode::PAYLOAD_TOO_LARGE, "continuation_receipt_exceeds_budget"));
        }
        let mut receipt = receipt_body;
        receipt[RECEIPT] = wrapper;
        Self::query("UPDATE dw_task_completions SET receipt=$1 WHERE task_id=$2")
            .bind(DB::document(&receipt)).bind(task_id).execute(&mut *tx).await?;
        tx.commit().await?;
        super::carried_polls::refresh_workflow_clock(&mut answer);
        super::carried_polls::refresh_activity_clock(&mut answer);
        self.wake.notify_waiters();
        Ok(answer)
    }

    /// A retry never claims, renews or creates a lease. It replays only the
    /// carried poll the decision used, while that lease is still live.
    async fn present_continuation_v2_replay(
        &self, mut answer: Value, owner: &str, workflow_poll: Option<super::carried_polls::WorkflowPoll>,
        activity_poll: Option<Value>, polling: super::carried_polls::Config<'_>,
    ) -> Result<Value> {
        if answer["continuation"]["outcome"] == "accepted" {
            if let Some(poll) = self.admit_activity_carrier(activity_poll, owner, polling.protocol).await?
                && let Some(carried) = self.replay_carried_activity(&poll).await?
            {
                answer["activity_poll"] = carried;
            }
        } else if let Some(poll) = workflow_poll
            && let Some(carried) = self.replay_carried_workflow(&poll).await?
        {
            answer["workflow_poll"] = carried;
        }
        Ok(answer)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn request() -> Value {
        json!({"version":2,"history_through_sequence":6,"history_last_event_id":"event-6",
            "sticky_cache":{"worker_id":"w","workflow_id":"wf","run_id":"r","build_id":"b","ttl_seconds":300,"history_last_sequence":6},
            "commands":[{"type":"start_timer","delay_seconds":1}]})
    }

    #[test]
    fn only_an_integer_two_with_a_cache_claim_selects_version_two() {
        assert!(requested(&json!({"continuation":request()})));
        let mut v1 = request();
        v1.as_object_mut().unwrap().remove("sticky_cache");
        assert!(!requested(&json!({"continuation":v1})), "v1 keeps its own version refusal");
        let mut float = request();
        float["version"] = json!(2.0);
        assert!(!requested(&json!({"continuation":float})));
        let parsed = parse(Some(&request())).unwrap();
        assert_eq!((parsed.through, parsed.last_event_id.as_str(), parsed.commands.len()), (6, "event-6", 1));
    }

    #[test]
    fn malformed_requests_are_refused() {
        let invalid = |edit: &dyn Fn(&mut Value)| {
            let mut value = request();
            edit(&mut value);
            parse(Some(&value)).err().map(|error| match error {
                crate::runtime::RuntimeError::Refused { reason, .. } => reason,
                _ => "other",
            })
        };
        assert_eq!(invalid(&|value| value["extra"] = json!(true)), Some("invalid_continuation"));
        assert_eq!(invalid(&|value| value["history_through_sequence"] = json!(0)), Some("invalid_continuation"));
        assert_eq!(invalid(&|value| value["history_through_sequence"] = json!(6.0)), Some("invalid_continuation"));
        assert_eq!(invalid(&|value| value["history_last_event_id"] = json!("")), Some("invalid_continuation"));
        assert_eq!(invalid(&|value| value["history_last_event_id"] = json!("x".repeat(65))), Some("invalid_continuation"));
        assert_eq!(invalid(&|value| value["sticky_cache"] = json!([])), Some("invalid_continuation"));
        assert_eq!(invalid(&|value| value["sticky_cache"]["history_last_sequence"] = json!(5)), Some("invalid_continuation"));
        assert_eq!(invalid(&|value| value["commands"] = json!([])), Some("invalid_continuation"));
        assert_eq!(invalid(&|value| value["commands"] = Value::Array(vec![json!({"type":"start_timer"}); MAX_COMMANDS + 1])),
            Some("invalid_continuation"));
        assert_eq!(invalid(&|value| value["commands"] = json!([{"type":"open_signal_wait"}])), Some("unsupported_continuation_command"));
        assert_eq!(invalid(&|value| value["commands"][0]["cancellation_scope_id"] = json!("s")), Some("unsupported_continuation_command"));
        assert_eq!(invalid(&|_| {}), None);
    }

    #[test]
    fn a_saved_answer_keeps_a_tail_range_and_never_events() {
        let answer = json!({"task_id":"activity","activity_attempt_id":"attempt","outcome":"completed","recorded":true,"reason":null,
            "next_task_id":"workflow","continuation":{"version":2,"outcome":"accepted","workflow_task_id":"workflow","workflow_task_attempt":1,
                "completion":{"task_id":"workflow","run_id":"run","run_status":"waiting","workflow_task_attempt":1,"outcome":"completed",
                    "recorded":true,"reason":null,"created_task_ids":["next"]}},
            "continuation_tail":{"after_sequence":6,"last_sequence":9}});
        assert!(valid_saved_answer(&answer, "activity", "run", &json!("attempt")));
        for range in [json!({"after_sequence":6,"omitted":"tail_byte_budget_exceeded"}), json!({"after_sequence":6,"omitted":"tail_event_budget_exceeded"})] {
            let mut omitted = answer.clone();
            omitted["continuation_tail"] = range;
            assert!(valid_saved_answer(&omitted, "activity", "run", &json!("attempt")));
        }
        for range in [json!({"after_sequence":6,"last_sequence":6}), json!({"after_sequence":6,"last_sequence":9,"history_events":[]}),
            json!({"after_sequence":6,"omitted":"tail_unavailable"}), json!(null)]
        {
            let mut corrupt = answer.clone();
            corrupt["continuation_tail"] = range.clone();
            assert!(!valid_saved_answer(&corrupt, "activity", "run", &json!("attempt")), "{range}");
        }
        let declined = json!({"task_id":"activity","activity_attempt_id":"attempt","outcome":"completed","recorded":true,"reason":null,
            "next_task_id":"workflow","continuation":{"version":2,"outcome":"declined","reason":"pending_update"}});
        assert!(valid_saved_answer(&declined, "activity", "run", &json!("attempt")));
        let mut unknown = declined.clone();
        unknown["continuation"]["reason"] = json!("history_too_large");
        assert!(!valid_saved_answer(&unknown, "activity", "run", &json!("attempt")));
        let mut with_tail = declined;
        with_tail["continuation_tail"] = json!({"after_sequence":6,"last_sequence":9});
        assert!(!valid_saved_answer(&with_tail, "activity", "run", &json!("attempt")));
    }
}
