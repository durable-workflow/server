//! Opt-in activity completion that also completes the run's next workflow
//! task with commands the worker computed ahead of time (Server #1078).
//!
//! The result, resumed task, commands and bounded exact-request receipt
//! commit under one transition lock. Eligibility misses keep an ordinary
//! completion; every command, budget, decoder and storage fault rolls the
//! whole transaction back. No speculative event timestamp becomes authority.
use super::{Result, refuse};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sha2::{Digest, Sha256};

pub(super) const VERSION: i64 = 1;
pub(super) const CAPABILITY: &str = "activity_workflow_continuation";
/// Longest history a worker may speculate over. Longer runs decline.
pub(super) const MAX_HISTORY_EVENTS: i64 = 256;
pub(super) const MAX_COMMANDS: usize = 16;
pub(super) const COMMANDS: [&str; 4] = ["schedule_activity", "start_timer", "complete_workflow", "record_version_marker"];

pub(super) fn capability() -> Value {
    json!({"version": VERSION,
        "history_endpoint": "/worker/activity-tasks/{taskId}/continuation/history",
        "complete_endpoint": "/worker/activity-tasks/{taskId}/continuation/complete",
        "supported_commands": COMMANDS, "max_history_events": MAX_HISTORY_EVENTS, "max_commands": MAX_COMMANDS})
}

/// A continuation request's own member, after the ordinary completion fields.
pub(super) struct Request {
    pub(super) through: i64,
    pub(super) last_event_id: String,
    pub(super) commands: Vec<Value>,
}

/// Malformed continuations are refused before anything is written.
pub(super) fn parse(value: Option<Value>) -> Result<Request> {
    let invalid = |reason: &'static str| refuse(StatusCode::UNPROCESSABLE_ENTITY, reason);
    let Some(Value::Object(fields)) = value else { return Err(invalid("invalid_continuation")) };
    if !fields.keys().all(|key| matches!(key.as_str(), "version" | "history_through_sequence" | "history_last_event_id" | "commands")) {
        return Err(invalid("invalid_continuation"));
    }
    if fields.get("version").and_then(Value::as_i64) != Some(VERSION) || fields.get("version").is_some_and(Value::is_f64) {
        return Err(invalid("unsupported_continuation_version"));
    }
    let through = fields.get("history_through_sequence").and_then(Value::as_i64).filter(|through| *through >= 1)
        .ok_or_else(|| invalid("invalid_continuation"))?;
    let last_event_id = fields.get("history_last_event_id").and_then(Value::as_str)
        .filter(|id| !id.is_empty() && id.len() <= 64).ok_or_else(|| invalid("invalid_continuation"))?.to_owned();
    let commands = fields.get("commands").and_then(Value::as_array)
        .filter(|commands| !commands.is_empty() && commands.len() <= MAX_COMMANDS)
        .ok_or_else(|| invalid("invalid_continuation"))?;
    for command in commands {
        let supported = command["type"].as_str().is_some_and(|kind| COMMANDS.contains(&kind));
        // Scopes and parallel groups need their own admission; v1 has none.
        let scoped = command.get("cancellation_scope_id").is_some() || command.get("parallel_group_path").is_some();
        if !supported || scoped {
            return Err(invalid("unsupported_continuation_command"));
        }
    }
    Ok(Request { through, last_event_id, commands: commands.clone() })
}

pub(super) fn declined(reason: &'static str) -> Value {
    json!({"version": VERSION, "outcome": "declined", "reason": reason})
}

fn contains_external(value: &Value) -> bool {
    match value {
        Value::Object(fields) => fields.contains_key("external_payload") || fields.contains_key("external_storage")
            || fields.values().any(contains_external),
        Value::Array(values) => values.iter().any(contains_external),
        Value::String(value) => value.starts_with(super::external_resolution::STORED_PREFIX)
            || value.starts_with(super::history_presentation::PHP_EXTERNAL_REFERENCE),
        _ => false,
    }
}

fn exact_fields(value: &Value, expected: &[&str]) -> bool {
    value.as_object().is_some_and(|fields| fields.len() == expected.len()
        && expected.iter().all(|key| fields.contains_key(*key)))
}

fn identifier(value: &Value) -> bool {
    value.as_str().is_some_and(|id| !id.is_empty() && id.len() <= 64)
}

/// A stored decision is authoritative only for the completed activity and
/// its own resumed task. Well-formed but contradictory IDs are corruption,
/// just like missing fields or an unsupported receipt version.
fn valid_saved_answer(answer: &Value, task_id: &str, run_id: &str, attempt: &Value) -> bool {
    if !exact_fields(answer, &["task_id", "activity_attempt_id", "outcome", "recorded", "reason", "next_task_id", "continuation"])
        || answer["task_id"].as_str() != Some(task_id)
        || !identifier(attempt) || answer["activity_attempt_id"] != *attempt
        || answer["outcome"] != "completed" || answer["recorded"] != true || !answer["reason"].is_null()
        || !(answer["next_task_id"].is_null() || identifier(&answer["next_task_id"])) {
        return false;
    }
    let decision = &answer["continuation"];
    if decision["version"].as_i64() != Some(VERSION) || decision["version"].is_f64() { return false; }
    match decision["outcome"].as_str() {
        Some("declined") => exact_fields(decision, &["version", "outcome", "reason"])
            && matches!(decision["reason"].as_str(), Some("external_payload" | "history_changed"
                | "workflow_slot_unavailable" | "no_workflow_task" | "dispatch_selected_other_task")),
        Some("accepted") => {
            let completed = &decision["completion"];
            exact_fields(decision, &["version", "outcome", "workflow_task_id", "workflow_task_attempt", "completion"])
                && identifier(&decision["workflow_task_id"])
                && decision["workflow_task_id"] == answer["next_task_id"]
                && decision["workflow_task_attempt"].as_i64().is_some_and(|attempt| attempt > 0)
                && !decision["workflow_task_attempt"].is_f64()
                && exact_fields(completed, &["task_id", "run_id", "run_status", "workflow_task_attempt", "outcome", "recorded", "reason", "created_task_ids"])
                && completed["task_id"] == decision["workflow_task_id"]
                && completed["workflow_task_attempt"] == decision["workflow_task_attempt"]
                && completed["run_id"].as_str() == Some(run_id)
                && matches!(completed["run_status"].as_str(), Some("waiting" | "completed"))
                && completed["outcome"] == "completed" && completed["recorded"] == true && completed["reason"].is_null()
                && completed["created_task_ids"].as_array().is_some_and(|ids| ids.len() <= MAX_COMMANDS + 1
                    && ids.iter().all(identifier))
        }
        _ => false,
    }
}

const RECEIPT: &str = "activity_workflow_continuation_v1";

/// Object ordering is transport spelling, while every supplied field and
/// array position belongs to the exact authored request.
pub(super) fn request_hash(body: &Value, protocol: Option<&str>) -> String {
    let mut canonical = body.clone();
    canonical.sort_all_objects();
    Sha256::digest(json!({"request": canonical, "protocol": protocol}).to_string().as_bytes()).iter().map(|byte| format!("{byte:02x}")).collect()
}

store_methods! {
    fn continuation_receipt(task: &DB::Row, request_hash: &str) -> Result<Option<Value>> {
        let Some(receipt) = DB::optional_document_row(task, "receipt")? else { return Ok(None); };
        let Some(wrapper) = receipt.get(RECEIPT) else { return Ok(None); };
        let stored_owner = DB::optional_string(task, "lease_owner")?;
        let valid = exact_fields(wrapper, &["version", "request_hash", "answer"])
            && wrapper["version"].as_i64() == Some(VERSION) && !wrapper["version"].is_f64()
            && wrapper["request_hash"].as_str().is_some_and(|hash| hash.len() == 64
                && hash.bytes().all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b)))
            && DB::string(task, "task_type")? == "activity" && DB::string(task, "status")? == "completed"
            && receipt["lease_owner"].as_str().is_some_and(|owner|
                stored_owner.as_deref() == Some(owner))
            && valid_saved_answer(&wrapper["answer"], &DB::string(task, "id")?,
                &DB::string(task, "workflow_run_id")?, &receipt["activity_attempt_id"])
            && wrapper.to_string().len() <= 16 * 1024;
        if !valid { return Err(refuse(StatusCode::INTERNAL_SERVER_ERROR, "invalid_continuation_receipt")); }
        if wrapper["request_hash"].as_str() != Some(request_hash) {
            return Err(refuse(StatusCode::CONFLICT, "conflicting_completion"));
        }
        let mut answer = wrapper["answer"].clone();
        answer["recorded"] = json!(false);
        answer["reason"] = json!("already_completed");
        Ok(Some(answer))
    }

    /// Read the durable decision before payload resolution, stale-attempt
    /// checks, or any poll admission. A retry never renews or creates a lease.
    pub(crate) async fn replay_activity_continuation(
        &self, task_id: &str, body: &Value, polling: super::carried_polls::Config<'_>,
    ) -> Result<Option<Value>> {
        let mut read = self.begin_read().await?;
        let task = self.task_row(&mut read, task_id).await?;
        let answer = Self::continuation_receipt(&task, &request_hash(body, polling.protocol))?;
        read.commit().await?;
        match answer {
            Some(answer) => self.present_continuation_replay(body, answer, polling).await.map(Some),
            None => Ok(None),
        }
    }

    async fn present_continuation_replay(
        &self, body: &Value, mut answer: Value, polling: super::carried_polls::Config<'_>,
    ) -> Result<Value> {
        if answer["continuation"]["outcome"] == "declined" {
            let owner = super::text(body, "lease_owner")?;
            if let Some(poll) = self.admit_workflow_carrier(body.get("workflow_poll").cloned(), owner, polling).await?
                && let Some(carried) = self.replay_carried_workflow(&poll).await? {
                answer["workflow_poll"] = carried;
            }
        }
        Ok(answer)
    }

    /// Both ordinary mutations retain the same durable transition lock.
    /// A command/backend/budget failure drops the entire transaction. Known
    /// eligibility misses apply no speculative commands and commit only the
    /// ordinary result and its recoverable carried claim.
    #[allow(clippy::too_many_arguments)]
    pub(crate) async fn complete_activity_continuation(
        &self, task_id: &str, mut body: Value, continuation: Request,
        external: Option<super::external_resolution::PreparedExternal>, result: String,
        command_body: Value, command_external: super::external_resolution::PreparedCommands,
        transition_codec: std::sync::Arc<tokio::sync::Semaphore>,
        worker: Option<super::runtime_credentials::Principal>,
        polling: super::carried_polls::Config<'_>, limits: super::memo_limits::StructuralLimits,
    ) -> Result<Value> {
        let hash = request_hash(&body, polling.protocol);
        let owner = super::text(&body, "lease_owner")?.to_owned();
        body.as_object_mut().expect("validated request object").remove("continuation");
        let raw_poll = body.as_object_mut().and_then(|fields| fields.remove("workflow_poll"));
        let workflow_poll = self.admit_workflow_carrier(raw_poll, &owner, polling).await?;
        let mut tx = self.begin().await?;
        let task = self.task_row(&mut tx, task_id).await?;
        if let Some(answer) = Self::continuation_receipt(&task, &hash)? {
            tx.rollback().await?;
            // A concurrent identical request committed after the read probe.
            return self.present_continuation_replay(&json!({"lease_owner": owner,
                "workflow_poll": workflow_poll.as_ref().map(|poll| poll.body.clone())}), answer, polling).await;
        }
        let receipt_body = body.clone();
        let (through, last_id) = (continuation.through, continuation.last_event_id.as_str());
        let external_input = external.is_some() || !command_external.is_empty();
        let prefix = if external_input { None } else { Some((through, last_id)) };
        let saved = tx.continuation_savepoint().await?;
        let mut answer = self.complete_activity_in(&mut tx, task_id, body, external.as_ref(), result.clone(), workflow_poll.clone(),
            prefix).await?;
        #[cfg(test)]
        {
            let gate = self.test_continuation_gate.lock().expect("continuation test gate").take();
            if let Some(gate) = gate {
                gate.entered.notify_one();
                gate.resume.notified().await;
            }
        }
        let prefix = answer.as_object_mut().and_then(|fields| fields.remove("continuation_prefix_holds"));
        let leased = answer["workflow_poll"]["leased"] == true;
        let selected = answer["workflow_poll"]["answer"]["task"].clone();
        let same_task = leased && selected["task_id"].is_string() && selected["task_id"] == answer["next_task_id"];
        let decline = if external_input { Some("external_payload") }
            else if prefix != Some(json!(true)) { Some("history_changed") }
            else if !leased { Some(if answer.get("workflow_poll").is_none() { "workflow_slot_unavailable" } else { "no_workflow_task" }) }
            else if !same_task { Some("dispatch_selected_other_task") }
            else { None };
        answer["continuation"] = if let Some(reason) = decline {
            // No speculative claim, generated ID, history accounting or cache
            // survives an eligibility miss. Apply the ordinary completion
            // afresh while retaining the original transition lock.
            tx.rollback_continuation(saved).await?;
            answer = self.complete_activity_in(&mut tx, task_id, receipt_body.clone(), external.as_ref(), result,
                workflow_poll, None).await?;
            declined(reason)
        } else {
            let workflow_task = selected["task_id"].as_str().expect("selected task identity");
            let mut completion = command_body;
            completion["workflow_task_attempt"] = selected["workflow_task_attempt"].clone();
            let (held, completed) = self.complete_or_checkpoint_in(workflow_task, completion, transition_codec,
                command_external, worker, polling, limits, None, None, Some(tx), Some(through)).await?;
            tx = held.expect("nested completion retains its transition");
            tx.release_continuation().await?;
            answer.as_object_mut().expect("completion object").remove("workflow_poll");
            json!({"version": VERSION, "outcome": "accepted", "workflow_task_id": workflow_task,
                "workflow_task_attempt": selected["workflow_task_attempt"], "completion": completed})
        };
        // The extension stores a hash and bounded decision/IDs only. Existing
        // activity receipt fields remain readable by an older server.
        let mut stored_answer = answer.clone();
        stored_answer.as_object_mut().expect("completion object").remove("workflow_poll");
        if !valid_saved_answer(&stored_answer, task_id, &DB::string(&task, "workflow_run_id")?, &receipt_body["activity_attempt_id"]) {
            return Err(refuse(StatusCode::INTERNAL_SERVER_ERROR, "invalid_continuation_receipt"));
        }
        let wrapper = json!({"version": VERSION, "request_hash": hash, "answer": stored_answer});
        if wrapper.to_string().len() > 16 * 1024 {
            return Err(refuse(StatusCode::PAYLOAD_TOO_LARGE, "continuation_receipt_exceeds_budget"));
        }
        let mut receipt = receipt_body;
        receipt[RECEIPT] = wrapper;
        Self::query("UPDATE dw_task_completions SET receipt=$1 WHERE task_id=$2")
            .bind(DB::document(&receipt)).bind(task_id).execute(&mut *tx).await?;
        tx.commit().await?;
        super::carried_polls::refresh_workflow_clock(&mut answer);
        self.wake.notify_waiters();
        Ok(answer)
    }

    /// The run's complete worker history, read under the activity attempt's
    /// own lease. A run the worker must not speculate over answers with an
    /// ineligible reason instead; nothing is written either way.
    pub(crate) async fn continuation_history(&self, task_id: &str, body: Value) -> Result<Value> {
        let owner = super::text(&body, "lease_owner")?;
        let attempt_id = super::text(&body, "activity_attempt_id")?;
        let mut tx = self.begin_read().await?;
        let task = self.task_row(&mut tx, task_id).await?;
        if DB::string(&task, "task_type")? != "activity" {
            return Err(refuse(StatusCode::NOT_FOUND, "task_not_found"));
        }
        let attempt = Self::query("SELECT * FROM activity_attempts WHERE id=$1 AND workflow_task_id=$2")
            .bind(attempt_id).bind(task_id).fetch_optional(&mut *tx).await?
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "stale_attempt"))?;
        if DB::string(&attempt, "status")? != "running"
            || DB::number(&attempt, "attempt_number")? != DB::number(&task, "attempt_count")?
            || DB::string(&task, "status")? != "leased"
            || DB::optional_string(&task, "lease_owner")?.as_deref() != Some(owner)
            || DB::optional_instant(&task, "lease_expires_at")?.is_none_or(|expires| expires <= super::store::now())
        {
            return Err(refuse(StatusCode::CONFLICT, "stale_attempt"));
        }
        let run_id = DB::string(&task, "workflow_run_id")?;
        let run = Self::query("SELECT * FROM workflow_runs WHERE id=$1").bind(&run_id).fetch_one(&mut *tx).await?;
        let activity = Self::query("SELECT parallel_group_path FROM activity_executions WHERE id=$1")
            .bind(DB::string(&attempt, "activity_execution_id")?).fetch_one(&mut *tx).await?;
        let pending = Self::scalar("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status IN ('ready','leased')")
            .bind(&run_id).fetch_one(&mut *tx).await?;
        let updates = Self::scalar("SELECT COUNT(*) FROM workflow_updates WHERE workflow_run_id=$1 AND status='accepted'")
            .bind(&run_id).fetch_one(&mut *tx).await?;
        let validations = Self::scalar("SELECT COUNT(*) FROM workflow_update_validation_tasks WHERE workflow_run_id=$1 AND status IN ('pending','leased')")
            .bind(&run_id).fetch_one(&mut *tx).await?;
        let last = DB::number(&run, "last_history_sequence")?;
        let ineligible = |reason: &'static str| json!({"task_id": task_id, "activity_attempt_id": attempt_id,
            "continuation": {"version": VERSION, "eligible": false, "reason": reason}});
        let reason = if !matches!(DB::string(&run, "status")?.as_str(), "running" | "waiting") {
            Some("run_not_open")
        } else if DB::string(&run, "workflow_type")? == super::standalone_activities::HOST_TYPE {
            Some("standalone_activity")
        } else if Self::cancellation_request(&run)?.is_some() {
            Some("cancellation_requested")
        } else if DB::optional_document_row(&activity, "parallel_group_path")?.is_some() {
            Some("parallel_group")
        } else if pending > 0 {
            Some("pending_workflow_task")
        } else if updates > 0 || validations > 0 {
            Some("pending_update")
        } else if last < 1 || last > MAX_HISTORY_EVENTS {
            Some("history_too_large")
        } else {
            None
        };
        if let Some(reason) = reason {
            tx.commit().await?;
            return Ok(ineligible(reason));
        }
        let (mut events, next) = Self::history_page_connection(&mut tx, &run_id, 0, MAX_HISTORY_EVENTS).await?;
        Self::with_signal_sequences(&mut tx, &run_id, &mut events).await?;
        tx.commit().await?;
        // The prefix proof relies on an append-only, gap-free, timed history.
        let complete = next.is_none() && events.len() as i64 == last
            && events.iter().enumerate().all(|(index, event)| event["sequence"].as_i64() == Some(index as i64 + 1));
        if !complete {
            return Ok(ineligible("history_not_contiguous"));
        }
        if events.iter().any(|event| event["recorded_at"].is_null()) {
            return Ok(ineligible("untimed_history"));
        }
        if contains_external(&json!([DB::string(&run, "arguments")?, events])) {
            return Ok(ineligible("external_payload"));
        }
        let last_event_id = events.last().map(|event| event["id"].clone()).unwrap_or(Value::Null);
        Ok(json!({"task_id": task_id, "activity_attempt_id": attempt_id,
            "continuation": {"version": VERSION, "eligible": true, "reason": null},
            "workflow_id": DB::string(&run, "workflow_instance_id")?, "run_id": run_id,
            "workflow_type": DB::string(&run, "workflow_type")?, "namespace": self.namespace.as_ref(), "payload_codec": "avro",
            "arguments": super::wire(&DB::string(&run, "arguments")?),
            "compatibility": DB::optional_string(&run, "compatibility")?.filter(|build| !build.trim().is_empty()),
            "history_through_sequence": last, "history_last_event_id": last_event_id, "history_events": events}))
    }

    /// Whether the run's history still ends exactly where the worker read it,
    /// with no workflow task already waiting. A waiting task can carry a
    /// signal or update the worker never replayed, and this completion's
    /// resume would join it. Called under the completion's transition lock,
    /// before its own append.
    pub(super) async fn continuation_prefix_holds(
        tx: &mut super::transaction::Transaction<'_, DB>, run_id: &str, through: i64, last_event_id: &str,
    ) -> Result<bool> {
        let pending = Self::scalar("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status IN ('ready','leased')")
            .bind(run_id).fetch_one(&mut **tx).await?;
        if pending > 0 {
            return Ok(false);
        }
        let run = Self::query("SELECT * FROM workflow_runs WHERE id=$1")
            .bind(run_id).fetch_one(&mut **tx).await?;
        if through < 1 || through > MAX_HISTORY_EVENTS
            || DB::number(&run, "last_history_sequence")? != through
            || !matches!(DB::string(&run, "status")?.as_str(), "running" | "waiting")
            || DB::string(&run, "workflow_type")? == super::standalone_activities::HOST_TYPE
            || Self::cancellation_request(&run)?.is_some()
            || [DB::optional_instant(&run, "execution_deadline_at")?, DB::optional_instant(&run, "run_deadline_at")?]
                .into_iter().flatten().any(|deadline| deadline <= super::store::now()) {
            return Ok(false);
        }
        let updates = Self::scalar("SELECT COUNT(*) FROM workflow_updates WHERE workflow_run_id=$1 AND status='accepted'")
            .bind(run_id).fetch_one(&mut **tx).await?;
        let validations = Self::scalar("SELECT COUNT(*) FROM workflow_update_validation_tasks WHERE workflow_run_id=$1 AND status IN ('pending','leased')")
            .bind(run_id).fetch_one(&mut **tx).await?;
        if updates > 0 || validations > 0 { return Ok(false); }
        let (events, next) = Self::history_page_connection(tx, run_id, 0, MAX_HISTORY_EVENTS).await?;
        if next.is_some() || events.len() as i64 != through
            || events.iter().enumerate().any(|(index, event)| event["sequence"].as_i64() != Some(index as i64 + 1)
                || event["recorded_at"].is_null())
            || contains_external(&json!([DB::string(&run, "arguments")?, events])) {
            return Ok(false);
        }
        let event = Self::query("SELECT id FROM workflow_history_events WHERE workflow_run_id=$1 AND sequence=$2")
            .bind(run_id).bind(through).fetch_optional(&mut **tx).await?;
        Ok(match event {
            Some(event) => DB::string(&event, "id")? == last_event_id,
            None => false,
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn request(commands: Value) -> Value {
        json!({"version": 1, "history_through_sequence": 3, "history_last_event_id": "event-3", "commands": commands})
    }

    #[test]
    fn accepts_only_the_versioned_v1_command_set() {
        for kind in COMMANDS {
            let parsed = parse(Some(request(json!([{"type": kind}])))).unwrap();
            assert_eq!((parsed.through, parsed.last_event_id.as_str(), parsed.commands.len()), (3, "event-3", 1));
        }
        for kind in ["start_child_workflow", "open_signal_wait", "open_condition_wait", "record_side_effect", "fail_workflow",
            "continue_as_new", "complete_update", "upsert_memo", "record_local_activity", "workflow_stream"] {
            assert!(parse(Some(request(json!([{"type": kind}])))).is_err(), "{kind} is outside v1");
        }
        assert!(parse(Some(request(json!([{"type": "start_timer", "cancellation_scope_id": "scope"}])))).is_err());
        assert!(parse(Some(request(json!([{"type": "schedule_activity", "parallel_group_path": []}])))).is_err());
    }

    #[test]
    fn refuses_malformed_or_unbounded_requests() {
        let mut cases = vec![
            None,
            Some(json!([])),
            Some(json!({"version": 2, "history_through_sequence": 3, "history_last_event_id": "e", "commands": [{"type": "start_timer"}]})),
            Some(json!({"version": 1.0, "history_through_sequence": 3, "history_last_event_id": "e", "commands": [{"type": "start_timer"}]})),
            Some(json!({"version": 1, "history_through_sequence": 0, "history_last_event_id": "e", "commands": [{"type": "start_timer"}]})),
            Some(json!({"version": 1, "history_through_sequence": 3, "history_last_event_id": "", "commands": [{"type": "start_timer"}]})),
            Some(json!({"version": 1, "history_through_sequence": 3, "history_last_event_id": "e", "commands": []})),
            Some(json!({"version": 1, "history_through_sequence": 3, "history_last_event_id": "e", "commands": [{"type": "start_timer"}], "extra": true})),
        ];
        cases.push(Some(request(Value::Array(vec![json!({"type": "start_timer"}); MAX_COMMANDS + 1]))));
        for case in cases {
            assert!(parse(case.clone()).is_err(), "{case:?}");
        }
        assert!(parse(Some(request(Value::Array(vec![json!({"type": "start_timer"}); MAX_COMMANDS])))).is_ok());
    }
}
