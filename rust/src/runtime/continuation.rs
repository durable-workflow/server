//! Opt-in activity completion that also completes the run's next workflow
//! task with commands the worker computed ahead of time (Server #1078).
//!
//! The worker reads the run's history through the activity's own authority,
//! replays it with its activity result appended, and sends the resulting
//! commands with its completion. The activity result commits exactly as an
//! ordinary completion does. The commands are applied only when, under the
//! same transition lock, the history still ends where the worker read it,
//! and normal dispatch then hands this worker that run's resumed task. They
//! go through the ordinary workflow-task completion, with its validators and
//! receipts. Anything else is a declined continuation: the worker keeps any
//! carried workflow lease and finishes it the ordinary way.
use super::{Result, refuse};
use axum::http::StatusCode;
use serde_json::{Value, json};

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

store_methods! {
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
        let run = Self::query("SELECT last_history_sequence FROM workflow_runs WHERE id=$1")
            .bind(run_id).fetch_one(&mut **tx).await?;
        if DB::number(&run, "last_history_sequence")? != through {
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
