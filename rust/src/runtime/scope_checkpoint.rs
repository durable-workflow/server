//! `POST /api/worker/workflow-tasks/{taskId}/cancellation-scopes/checkpoint`:
//! Server's `WorkerController::checkpointCancellationScopePrefix` and Core's
//! `DefaultWorkflowTaskBridge::checkpointCancellationScopePrefix`.
//!
//! A cooperative worker that recorded commands earlier in its turn (a side
//! effect, a version marker, a memo or search-attribute upsert, or an
//! activity, timer or child) checkpoints them before it opens or delivers a
//! cancellation scope. The commands are applied as a completion applies them,
//! under the same claim: the task stays leased with its lease unchanged, and
//! no history event of its own is written. The task payload keeps one
//! receipt, `portable_scope_checkpoint`, which an identical retry returns
//! with `duplicate: true`.
//!
//! The receipt's `fingerprint` is a SHA-256 over the checkpointed commands.
//! It identifies a retry of the same prefix on this server. Core hashes its
//! own normalized command arrays; native hashes the validated request
//! commands, so the two servers' values differ for the same commands. No
//! client computes or compares it (Server #325).
use super::laravel_rules::{self, Rule};
use super::store::now;
use super::transaction::Transaction;
use super::{Result, RuntimeError};
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use serde_json::{Value, json};
use sha2::{Digest, Sha256};

/// The checkpoint fields Server validates before the completion's own rules,
/// in its order (`mutateWorkflowTaskCommands`).
pub(super) const RULES: &laravel_rules::Rules = &[
    ("checkpoint_id", &[Rule::Required, Rule::String, Rule::Max(255)]),
    ("start_sequence", &[Rule::Required, Rule::Integer, Rule::Min(1)]),
    ("message_stream_cursors", &[Rule::Prohibited]),
    ("message_stream_waits", &[Rule::Prohibited]),
    ("sticky_cache", &[Rule::Prohibited]),
];

/// The validated checkpoint request.
pub(crate) struct Checkpoint {
    pub(crate) id: String,
    pub(crate) start_sequence: i64,
}

impl Checkpoint {
    /// The validated fields of a normalized body that passed [`RULES`].
    pub(super) fn of(body: &Value) -> Self {
        let start_sequence = match &body["start_sequence"] {
            Value::Number(number) => number.as_i64().or_else(|| number.as_f64().map(|float| float as i64)),
            Value::String(text) => text.trim().parse().ok(),
            _ => None,
        };
        Self {
            id: body["checkpoint_id"].as_str().unwrap_or_default().to_owned(),
            start_sequence: start_sequence.unwrap_or_default(),
        }
    }
}

/// The reason a checkpoint answer carries, and the status Server answers it
/// with (`workflowOutcomeStatus`).
pub(super) fn status(response: &Value) -> StatusCode {
    match response["reason"].as_str() {
        None => StatusCode::OK,
        Some("task_not_found") => StatusCode::NOT_FOUND,
        Some(_) => StatusCode::CONFLICT,
    }
}

/// Core's refused checkpoint.
fn refused(task_id: &str, reason: &str) -> Value {
    json!({"checkpointed": false, "duplicate": false, "task_id": task_id, "reason": reason})
}

const INVALID_COMMANDS: &str = "invalid_cancellation_scope_checkpoint_commands";

/// Commands that cannot be part of a retained-claim prefix: a terminal
/// command, a wait (it closes a turn) and a local report (it reconstructs an
/// executed callback). Core also refuses `cancel_selection_operation` here;
/// native refuses that command type for any workflow task before this point
/// (`unsupported_workflow_command`).
fn excluded(command: &Value) -> bool {
    matches!(
        command["type"].as_str(),
        Some("record_local_activity" | "open_condition_wait" | "open_signal_wait" | "complete_workflow" | "fail_workflow"
            | "continue_as_new")
    )
}

/// The prefix's identity for retries: SHA-256 over its commands' JSON, with
/// keys sorted so that a retry's key order does not change it.
fn fingerprint(commands: &[Value]) -> Result<String> {
    let encoded = serde_json::to_vec(&super::json_order::sorted_keys(&Value::Array(commands.to_vec())))
        .map_err(|_| super::refuse(StatusCode::CONFLICT, INVALID_COMMANDS))?;
    Ok(super::bundle_verifier::hex(&Sha256::digest(&encoded)))
}

/// A context's RFC 3339 instant.
fn instant(value: &Value) -> Option<DateTime<Utc>> {
    value.as_str().and_then(|text| DateTime::parse_from_rfc3339(text).ok()).map(|at| at.with_timezone(&Utc))
}

store_methods! {
    /// `PortableLocalActivityCleanup::snapshot` for the run's own request: a
    /// prefix may follow a root cancellation request only after the request
    /// was delivered, starting past the delivered boundary, with the
    /// delivery's context the request's own.
    async fn root_cleanup_proved(tx: &mut Transaction<'_, DB>, run: &DB::Row, start_sequence: i64) -> Result<bool> {
        let Some(request_id) = DB::optional_string(run, "cancellation_request_command_id")? else {
            return Ok(false);
        };
        let run_id = DB::string(run, "id")?;
        let event = |kind: &'static str| {
            Self::query("SELECT sequence,payload,recorded_at FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type=$2 AND workflow_command_id=$3 ORDER BY sequence LIMIT 1")
                .bind(run_id.clone()).bind(kind).bind(request_id.clone())
        };
        let request = event("CooperativeCancellationRequested").fetch_optional(&mut **tx).await?;
        let delivery = event("CooperativeCancellationDelivered").fetch_optional(&mut **tx).await?;
        let (Some(request), Some(delivery)) = (request, delivery) else {
            return Ok(false);
        };
        let payload = DB::document_row(&delivery, "payload")?;
        let delivered_at = DB::optional_instant(run, "cancellation_delivered_at")?;
        let Some(sequence) = payload["sequence"].as_i64() else {
            return Ok(false);
        };
        if DB::number(&delivery, "sequence")? <= DB::number(&request, "sequence")?
            || payload["workflow_command_id"].as_str() != Some(request_id.as_str())
            || payload["workflow_run_id"].as_str() != Some(run_id.as_str())
            || DB::optional_number(run, "cancellation_delivery_sequence")? != Some(sequence)
            || delivered_at.is_none_or(|delivered_at| DB::optional_instant(&delivery, "recorded_at").ok().flatten()
                .is_none_or(|recorded_at| recorded_at < delivered_at))
        {
            return Ok(false);
        }
        let span = match payload.get("sequence_span") {
            None | Some(Value::Null) => 1,
            Some(span) => match span.as_i64() {
                Some(span) if span >= 1 && span <= i64::MAX - sequence => span,
                _ => return Ok(false),
            },
        };
        if start_sequence < sequence + span {
            return Ok(false);
        }
        let requested = &DB::document_row(&request, "payload")?["cancellation"];
        let delivered = &payload["cancellation"];
        let deadline = DB::optional_instant(run, "cancellation_deadline_at")?;
        Ok(requested.is_object() && delivered.is_object()
            && requested["request_id"].as_str() == Some(request_id.as_str())
            && requested["root_request_id"] == delivered["root_request_id"]
            && requested["request_id"] == delivered["request_id"]
            && instant(&requested["requested_at"]).is_some_and(|at| Some(at) == instant(&delivered["requested_at"]))
            && instant(&requested["cleanup_deadline_at"]).is_some_and(|at| {
                Some(at) == instant(&delivered["cleanup_deadline_at"]) && Some(at) == deadline
            }))
    }

    /// Core's `checkpointCommands` for a cancellation scope prefix, after
    /// Server's guards: refusals answer Core's refused checkpoint and change
    /// nothing; an accepted prefix commits its commands and the receipt.
    #[allow(clippy::too_many_arguments)]
    pub(super) async fn checkpoint_prefix(
        &self,
        mut tx: Transaction<'_, DB>,
        task: DB::Row,
        body: &Value,
        commands: &[Value],
        checkpoint: &Checkpoint,
        transition_codec: std::sync::Arc<tokio::sync::Semaphore>,
        external: &super::external_resolution::PreparedCommands,
        local: &std::collections::BTreeMap<usize, super::local_activities::Record>,
        exceptions: &std::collections::BTreeMap<usize, String>,
        limits: super::memo_limits::StructuralLimits,
    ) -> Result<Value> {
        let task_id = DB::string(&task, "id")?;
        // Server: the claim must be the cooperative claim it issued.
        if !Self::claim_supports_cancellation(&task)? {
            tx.rollback().await?;
            return Ok(json!({"checkpointed": false, "reason": "active_claim_cancellation_not_supported"}));
        }
        let refusal = |reason: &str| Ok(refused(&task_id, reason));
        let owner = body["lease_owner"].as_str().unwrap_or_default();
        let attempt = body["workflow_task_attempt"].as_i64().unwrap_or_default();
        // Core's argument checks. Its command-count bound (100) is already
        // the completion's, refused before this point.
        if attempt < 1 || owner.is_empty() || checkpoint.id.trim().is_empty() || checkpoint.id.len() > 255 {
            tx.rollback().await?;
            return refusal("invalid_cancellation_scope_checkpoint");
        }
        if commands.iter().any(excluded) {
            tx.rollback().await?;
            return refusal(INVALID_COMMANDS);
        }
        let fingerprint = fingerprint(commands)?;
        // The claim, read in this transaction.
        let expires = DB::optional_instant(&task, "lease_expires_at")?;
        if DB::string(&task, "task_type")? != "workflow" || DB::string(&task, "status")? != "leased"
            || DB::optional_string(&task, "lease_owner")?.as_deref() != Some(owner)
            || DB::number(&task, "attempt_count")? != attempt
        {
            tx.rollback().await?;
            return refusal("workflow_claim_mismatch");
        }
        let Some(expires) = expires.filter(|expires| *expires > now()) else {
            tx.rollback().await?;
            return refusal("workflow_claim_expired");
        };
        let run_id = DB::string(&task, "workflow_run_id")?;
        let run = Self::query("SELECT * FROM workflow_runs WHERE id=$1").bind(&run_id).fetch_one(&mut *tx).await?;
        if super::run_observation_store::terminal(&DB::string(&run, "status")?) {
            tx.rollback().await?;
            return refusal("run_closed");
        }
        if [DB::optional_instant(&run, "execution_deadline_at")?, DB::optional_instant(&run, "run_deadline_at")?]
            .into_iter()
            .flatten()
            .any(|deadline| deadline <= now())
        {
            tx.rollback().await?;
            return refusal("run_deadline_expired");
        }
        // A retry of the retained receipt.
        let payload = DB::document_row(&task, "payload")?;
        let receipt = &payload[RECEIPT];
        if receipt.is_object() && receipt["checkpoint_id"].as_str() == Some(checkpoint.id.as_str()) {
            tx.rollback().await?;
            if receipt["workflow_task_attempt"].as_i64() != Some(attempt)
                || receipt["lease_owner"].as_str() != Some(owner)
                || receipt["start_sequence"].as_i64() != Some(checkpoint.start_sequence)
                || receipt["fingerprint"].as_str() != Some(fingerprint.as_str())
            {
                return refusal("cancellation_scope_checkpoint_mismatch");
            }
            let mut response = receipt.clone();
            response["checkpointed"] = json!(true);
            response["duplicate"] = json!(true);
            response["reason"] = Value::Null;
            return Ok(response);
        }
        if DB::optional_string(&run, "cancellation_request_command_id")?.is_some() {
            if !Self::root_cleanup_proved(&mut tx, &run, checkpoint.start_sequence).await? {
                tx.rollback().await?;
                return refusal("cancellation_requested");
            }
            if DB::optional_instant(&run, "cancellation_deadline_at")?.is_none_or(|deadline| deadline <= now()) {
                tx.rollback().await?;
                return refusal("cancellation_deadline_expired");
            }
        }
        let mut sequence = Self::authored_sequence(&mut tx, &run_id).await?;
        if sequence + 1 != checkpoint.start_sequence {
            tx.rollback().await?;
            return refusal("cancellation_scope_checkpoint_sequence_mismatch");
        }
        let members: Vec<&Value> = commands.iter().collect();
        if let Some(reason) = Self::cancellation_scope_completion_refusal(&mut tx, &run_id, &members).await? {
            tx.rollback().await?;
            return refusal(reason);
        }
        match Self::validate_update_commands(&mut tx, &run_id, &task, commands).await {
            Err(RuntimeError::Refused { reason, .. }) => {
                tx.rollback().await?;
                return refusal(reason);
            }
            other => other?,
        }
        // Metered like a completion (Workflow #930).
        tx.meter = Some(super::history_meter::Meter::new(&limits, &task_id, &body["workflow_task_attempt"]));
        // Core answers its own refusals as a refused checkpoint.
        match Self::apply_task_signal(&mut tx, &run_id, &task_id, &task, transition_codec, true, commands).await {
            Ok(()) => {}
            Err(RuntimeError::Refused { reason, .. }) => {
                tx.rollback().await?;
                return refusal(reason);
            }
            Err(error) => return Err(error),
        }
        let apply = super::store::Apply {
            run: &run,
            task: &task,
            task_id: &task_id,
            run_id: &run_id,
            attempt: &body["workflow_task_attempt"],
            limits: &limits,
            external,
            local,
            exceptions,
        };
        let mut created = Vec::new();
        for (index, command) in commands.iter().enumerate() {
            match self.apply_non_terminal(&mut tx, &apply, index, command, &mut sequence, &mut created).await {
                Ok(()) => {}
                // `parallelCommandsMatchSequences`, and the meter's refusal.
                Err(RuntimeError::Refused { reason: "parallel_sequence_mismatch", .. }) => {
                    tx.rollback().await?;
                    return refusal(INVALID_COMMANDS);
                }
                Err(RuntimeError::Protocol { response, .. })
                    if response["reason"] == "history_transaction_limit_exceeded" =>
                {
                    tx.rollback().await?;
                    return refusal("history_transaction_limit_exceeded");
                }
                Err(error) => return Err(error),
            }
        }
        // Command application can change the task payload (a consumed
        // signal): keep that, and add the receipt.
        let current = Self::query("SELECT payload FROM workflow_tasks WHERE id=$1").bind(&task_id).fetch_one(&mut *tx).await?;
        let mut payload = DB::document_row(&current, "payload")?;
        if !payload.is_object() {
            payload = json!({});
        }
        let receipt = json!({
            "checkpoint_id": checkpoint.id,
            "task_id": task_id,
            "workflow_run_id": run_id,
            "workflow_task_attempt": attempt,
            "lease_owner": owner,
            "lease_expires_at": super::cancellation_scopes::iso(expires),
            "start_sequence": checkpoint.start_sequence,
            "next_sequence": sequence + 1,
            "fingerprint": fingerprint,
            "created_task_ids": created,
            "recorded_at": super::cancellation_scopes::iso(now()),
        });
        payload[RECEIPT] = receipt.clone();
        Self::query("UPDATE workflow_tasks SET payload=$1 WHERE id=$2")
            .bind(DB::document(&payload))
            .bind(&task_id)
            .execute(&mut *tx)
            .await?;
        if let Some(error) = tx.meter.as_ref().and_then(|meter| meter.tripped()) {
            tx.rollback().await?;
            return match error {
                RuntimeError::Protocol { .. } => refusal("history_transaction_limit_exceeded"),
                error => Err(error),
            };
        }
        // Core projects the run at completion, not at a checkpoint: the run's
        // status and summary stay as the claim found them.
        tx.commit().await?;
        self.wake.notify_waiters();
        let mut response = receipt;
        response["checkpointed"] = json!(true);
        response["duplicate"] = json!(false);
        response["reason"] = Value::Null;
        Ok(response)
    }
}

/// The task payload key of the retained receipt.
const RECEIPT: &str = "portable_scope_checkpoint";
