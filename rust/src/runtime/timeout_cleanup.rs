//! Opt-in deadline-triggered timeout cleanup (Server #1201). The portable
//! contract is Workflow's `docs/architecture/timeout-cleanup.md`; the payloads
//! here follow the embedded implementation released in Workflow 2.29.0.
//!
//! A start that opts in reserves the last `allowance_seconds` of each hard
//! timeout for workflow-authored cleanup. The allowance is recorded once in
//! `WorkflowStarted.timeout_cleanup` and read from there: native adds no
//! column. The work deadline is the earliest hard deadline minus the
//! allowance; the hard deadline never moves. At the work deadline the engine
//! records `WorkflowTimeoutCleanupStarted` and an engine-owned cooperative
//! cancellation request whose context carries `cause: timeout`, so the
//! existing cooperative delivery and cleanup-deadline enforcement apply. The
//! run always ends `WorkflowTimedOut`, or with the preempting terminal event,
//! and the terminal payload carries a separate `timeout_cleanup` outcome.
use super::{
    Result, RuntimeError, refuse,
    store::{id, now},
    transaction::Transaction,
};
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use serde_json::{Value, json};

/// The `WorkflowStarted` and terminal payload key.
pub(super) const STARTED_KEY: &str = "timeout_cleanup";
/// The worker capability, and the server capability once advertised.
pub(super) const CAPABILITY: &str = "timeout_cleanup";
/// The cancellation context's `cause` for an engine timeout request.
pub(super) const CAUSE_TIMEOUT: &str = "timeout";
/// The language-neutral marker on `fail_workflow.failure.interruption`.
pub(super) const INTERRUPTION: &str = "timeout_cleanup";
/// The largest admitted allowance.
pub(super) const MAX_ALLOWANCE_SECONDS: i64 = 86_400;
/// Whether `/api/cluster/info` and worker responses advertise
/// `timeout_cleanup: {supported: true, version: 1}`. On since installed
/// acceptance of the contract vectors 1-12 passed against published native
/// with the published PHP SDK 3.31.0 worker, the first SDK that advertises
/// the capability and sends the interruption marker. Admission does not
/// depend on it: a start is refused unless an eligible worker on its queue
/// advertises support.
pub(super) const ADVERTISED: bool = true;

const REASON: &str = "Workflow work deadline passed; timeout cleanup requested.";

/// Carbon's `toIso8601String()` for a UTC instant.
pub(super) fn iso(at: DateTime<Utc>) -> String {
    at.format("%Y-%m-%dT%H:%M:%S+00:00").to_string()
}

/// `StartOptions::normalizeTimeoutCleanup` after the field rules: an
/// allowance needs a hard timeout and must be shorter than each one.
pub(super) fn admission_error(allowance: i64, execution: Option<i64>, run: Option<i64>) -> Option<String> {
    if execution.is_none() && run.is_none() {
        return Some("Workflow v2 timeout cleanup needs an execution or run timeout to reserve time from.".to_owned());
    }
    [("execution", execution), ("run", run)].into_iter().find_map(|(kind, timeout)| {
        timeout
            .filter(|timeout| allowance >= *timeout)
            .map(|timeout| format!("Workflow v2 timeout cleanup ({allowance} s) must be shorter than the {kind} timeout ({timeout} s)."))
    })
}

/// `TimeoutCleanup::startedPayload`.
pub(super) fn started_payload(allowance: i64, execution: Option<DateTime<Utc>>, run: Option<DateTime<Utc>>) -> Value {
    let mut work = serde_json::Map::new();
    for (kind, deadline) in [("execution", execution), ("run", run)] {
        if let Some(deadline) = deadline {
            work.insert(kind.into(), json!(iso(deadline - chrono::Duration::seconds(allowance))));
        }
    }
    json!({"allowance_seconds": allowance, "work_deadlines": work})
}

/// The admitted allowance in a `WorkflowStarted` payload.
pub(super) fn allowance(started: &Value) -> Option<i64> {
    started[STARTED_KEY]["allowance_seconds"].as_i64().filter(|seconds| (1..=MAX_ALLOWANCE_SECONDS).contains(seconds))
}

/// `TimeoutCleanup::earliestHardDeadline`: the earlier deadline and its
/// kind; the execution deadline wins a tie.
pub(super) fn earliest_hard_deadline(
    execution: Option<DateTime<Utc>>,
    run: Option<DateTime<Utc>>,
) -> Option<(&'static str, DateTime<Utc>)> {
    let mut earliest: Option<(&'static str, DateTime<Utc>)> = None;
    for (kind, deadline) in [("execution", execution), ("run", run)] {
        if let Some(deadline) = deadline
            && earliest.is_none_or(|(_, current)| deadline < current)
        {
            earliest = Some((kind, deadline));
        }
    }
    earliest
}

/// `TimeoutCleanup::summary`: how cleanup ended, said plainly wherever a
/// timeout is shown.
pub(super) fn summary(outcome: &Value) -> Option<&'static str> {
    match outcome["outcome"].as_str()? {
        "completed" => Some("Timeout cleanup completed."),
        "failed" => Some("Timeout cleanup failed."),
        "expired" => Some("Timeout cleanup did not finish before the hard deadline."),
        "preempted" => Some("Timeout cleanup was preempted."),
        _ => None,
    }
}

/// The history event the bounded run observation reads for a run in
/// `status`, as embedded `TimeoutCleanup::observe` does: the terminal event
/// of a closed run, or the cleanup entry of an open one. A completed run
/// reports nothing.
pub(super) fn observation_event(status: &str) -> Option<&'static str> {
    match status {
        "failed" => Some("WorkflowTimedOut"),
        "cancelled" => Some("WorkflowCancelled"),
        "terminated" => Some("WorkflowTerminated"),
        "pending" | "running" | "waiting" => Some("WorkflowTimeoutCleanupStarted"),
        _ => None,
    }
}

/// The bounded run observation's top-level `timeout_cleanup` from the
/// event `observation_event` chose, in the portable shape embedded Workflow
/// 2.40.0 returns: `outcome: running` with the cause, kind, start and
/// deadlines while cleanup is open, then the outcome recorded on the
/// terminal event. Only string fields are kept; nothing is reported when
/// cleanup was never recorded.
pub(super) fn observation(event_type: &str, payload: &Value) -> Option<Value> {
    if !payload.is_object() {
        return None;
    }
    let mut observed = serde_json::Map::new();
    if event_type == "WorkflowTimeoutCleanupStarted" {
        observed.insert("outcome".into(), json!("running"));
        observed.insert("cause".into(), json!(CAUSE_TIMEOUT));
        for (key, from) in [("timeout_kind", "timeout_kind"), ("started_at", "detected_at"),
            ("work_deadline_at", "work_deadline_at"), ("cleanup_deadline_at", "cleanup_deadline_at")] {
            if let Some(value) = payload.get(from).filter(|value| value.is_string()) {
                observed.insert(key.into(), value.clone());
            }
        }
        return Some(Value::Object(observed));
    }
    let recorded = &payload[STARTED_KEY];
    summary(recorded)?;
    for key in ["outcome", "timeout_kind", "started_at", "cleanup_deadline_at", "finished_at", "failure_id"] {
        if let Some(value) = recorded.get(key).filter(|value| value.is_string()) {
            observed.insert(key.into(), value.clone());
        }
    }
    Some(Value::Object(observed))
}

/// The runs of a list page that can report timeout cleanup: opted in (a
/// `WorkflowStarted.timeout_cleanup` object) and either closed in a way that can carry an outcome, or open and
/// holding a cooperative request. Cleanup entry always records the engine's
/// request on the run, and nothing clears it while the run is open. An
/// imported run is let through, since an import does not copy the request.
/// `WorkflowStarted` is a run's first event, or its second after
/// `StartAccepted`, on every native and embedded start, child, retry,
/// continuation and redrive, so it is read by unique `(run, sequence)` seeks.
macro_rules! cleanup_candidates {
    ($opted_in:literal, $members:literal) => {
        concat!(
            "SELECT r.id FROM workflow_runs r JOIN workflow_history_events e",
            " ON e.workflow_run_id=r.id AND e.sequence IN (1,2) AND e.event_type='WorkflowStarted'",
            " WHERE ", $opted_in,
            " AND (r.status IN ('failed','cancelled','terminated') OR (r.status IN ('pending','running','waiting')",
            " AND (r.cancellation_request_command_id IS NOT NULL OR r.import_source IS NOT NULL)))",
            " AND r.id IN (", $members, ")"
        )
    };
}

/// A registration that delivers the typed timeout interruption: it lists
/// the capability and its manifest supports version 1.
pub(super) fn worker_supports(capabilities: &Value, manifest: &Value) -> bool {
    let listed = capabilities.as_array().is_some_and(|items| items.iter().any(|item| item == CAPABILITY));
    let entry = &manifest[CAPABILITY];
    listed && entry["supported"] == Value::Bool(true) && entry.get("version").is_none_or(|version| version == 1)
}

/// The server's advertisement.
pub(super) fn advertisement() -> Option<Value> {
    ADVERTISED.then(|| json!({"supported": true, "version": 1}))
}

/// The marker on a `fail_workflow` command: absent, or exactly the reserved
/// interruption. Anything else is refused before any write.
pub(super) fn interruption_marker(command: &Value) -> Result<bool> {
    // A `failure` that is not an object carries no marker and stays
    // ignored, as before.
    let Some(failure) = command.get("failure").filter(|failure| failure.is_object()) else {
        return Ok(false);
    };
    match failure.get("interruption") {
        None | Some(Value::Null) => Ok(false),
        Some(Value::String(marker)) if marker == INTERRUPTION => Ok(true),
        Some(_) => Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_workflow_failure_interruption")),
    }
}

/// What a timed-out close adds beside the timeout: the cleanup outcome, or,
/// when a user's earlier cooperative request owned cleanup, that request
/// recorded as preempted.
pub(super) struct Closing {
    pub(super) timeout_cleanup: Option<Value>,
    pub(super) cancellation_cleanup: Option<Value>,
}

store_methods! {
    /// The bounded run observation's `timeout_cleanup` for a run in
    /// `status`, read from the newest event `observation_event` chooses, as
    /// embedded `TimeoutCleanup::observe` does. The caller applies the
    /// opt-in check.
    pub(super) async fn observed_timeout_cleanup_event(connection: &mut DB::Connection, run_id: &str, status: &str)
        -> Result<Option<Value>> {
        let Some(event_type) = observation_event(status) else {
            return Ok(None);
        };
        let row = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type=$2 ORDER BY sequence DESC LIMIT 1")
            .bind(run_id).bind(event_type).fetch_optional(&mut *connection).await?;
        let Some(row) = row else {
            return Ok(None);
        };
        let payload = DB::optional_document_row(&row, "payload")?.unwrap_or(Value::Null);
        Ok(observation(event_type, &payload))
    }

    /// Run lists' `timeout_cleanup` (workflow#863): the bounded run
    /// observation's value for each listed run that has one, keyed by run
    /// id. A list passes each run's id and status. One query selects the
    /// page's candidates with the observation's opt-in check (see
    /// `cleanup_candidates`); only those read the observation's event, which
    /// for a timed-out, cancelled or terminated run is its newest. Ordinary,
    /// armed and completed runs read nothing more.
    pub(super) async fn timeout_cleanup_for_runs(connection: &mut DB::Connection, runs: &[(String, String)])
        -> Result<std::collections::BTreeMap<String, Value>> {
        let mut observed = std::collections::BTreeMap::new();
        let listed: Vec<&str> = runs.iter()
            .filter(|(_, status)| observation_event(status).is_some())
            .map(|(run, _)| run.as_str())
            .collect();
        if listed.is_empty() {
            return Ok(observed);
        }
        let sql = match DB::NAME {
            "PostgreSQL" => cleanup_candidates!(
                "jsonb_typeof(CAST(e.payload AS jsonb)->'timeout_cleanup')='object'",
                "SELECT jsonb_array_elements_text(CAST($1 AS jsonb))"
            ),
            "MySQL" => cleanup_candidates!(
                "JSON_TYPE(JSON_EXTRACT(e.payload,'$.timeout_cleanup'))='OBJECT'",
                "SELECT ids.id FROM JSON_TABLE($1, '$[*]' COLUMNS (id VARCHAR(255) COLLATE utf8mb4_unicode_ci PATH '$')) ids"
            ),
            _ => cleanup_candidates!(
                "CASE WHEN json_valid(e.payload) THEN json_type(e.payload,'$.timeout_cleanup') END='object'",
                "SELECT value FROM json_each($1)"
            ),
        };
        let ids = serde_json::to_string(&listed)?;
        let mut candidates = std::collections::BTreeSet::new();
        for row in Self::query(sql).bind(ids.as_str()).fetch_all(&mut *connection).await? {
            candidates.insert(DB::string(&row, "id")?);
        }
        for (run, status) in runs {
            if candidates.contains(run)
                && let Some(value) = Self::observed_timeout_cleanup_event(&mut *connection, run, status).await?
            {
                observed.insert(run.clone(), value);
            }
        }
        Ok(observed)
    }

    /// The allowance the run was admitted with, from its `WorkflowStarted`.
    pub(super) async fn timeout_cleanup_allowance(tx: &mut Transaction<'_, DB>, run_id: &str) -> Result<Option<i64>> {
        let started = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='WorkflowStarted' ORDER BY sequence LIMIT 1")
            .bind(run_id).fetch_optional(&mut **tx).await?;
        Ok(match started {
            Some(row) => allowance(&DB::document_row(&row, "payload")?),
            None => None,
        })
    }

    /// The run's `WorkflowTimeoutCleanupStarted`: its command and payload.
    async fn timeout_cleanup_started(tx: &mut Transaction<'_, DB>, run_id: &str) -> Result<Option<(Option<String>, Value)>> {
        let started = Self::query("SELECT workflow_command_id,payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='WorkflowTimeoutCleanupStarted' ORDER BY sequence LIMIT 1")
            .bind(run_id).fetch_optional(&mut **tx).await?;
        started.map(|row| Ok((DB::optional_string(&row, "workflow_command_id")?, DB::document_row(&row, "payload")?))).transpose()
    }

    /// The `WorkflowTimeoutCleanupStarted` payload when the run's canonical
    /// cooperative request is the engine's timeout request.
    pub(super) async fn active_timeout_cleanup(tx: &mut Transaction<'_, DB>, run: &DB::Row) -> Result<Option<Value>> {
        let Some(request) = DB::optional_string(run, "cancellation_request_command_id")? else {
            return Ok(None);
        };
        Ok(match Self::timeout_cleanup_started(tx, &DB::string(run, "id")?).await? {
            Some((Some(command), payload)) if command == request => Some(payload),
            _ => None,
        })
    }

    /// `TimeoutCleanup::outcome`.
    pub(super) async fn timeout_cleanup_outcome(tx: &mut Transaction<'_, DB>, run: &DB::Row, outcome: &str,
        failure_id: Option<&str>, at: DateTime<Utc>) -> Result<Value> {
        let started = Self::timeout_cleanup_started(tx, &DB::string(run, "id")?).await?.map(|(_, payload)| payload).unwrap_or(Value::Null);
        let earliest = earliest_hard_deadline(DB::optional_instant(run, "execution_deadline_at")?, DB::optional_instant(run, "run_deadline_at")?);
        let mut value = serde_json::Map::new();
        value.insert("outcome".into(), json!(outcome));
        let kind = started.get("timeout_kind").filter(|kind| !kind.is_null()).cloned().or_else(|| earliest.map(|(kind, _)| json!(kind)));
        if let Some(kind) = kind {
            value.insert("timeout_kind".into(), kind);
        }
        if let Some(detected) = started.get("detected_at").filter(|value| !value.is_null()) {
            value.insert("started_at".into(), detected.clone());
        }
        let deadline = started.get("cleanup_deadline_at").filter(|value| !value.is_null()).cloned()
            .or_else(|| earliest.map(|(_, deadline)| json!(iso(deadline))));
        if let Some(deadline) = deadline {
            value.insert("cleanup_deadline_at".into(), deadline);
        }
        value.insert("finished_at".into(), json!(iso(at)));
        if let Some(failure_id) = failure_id {
            value.insert("failure_id".into(), json!(failure_id));
        }
        Ok(Value::Object(value))
    }

    /// What a hard timeout of an opted-in run records beside it: an open
    /// engine request or a run that never entered cleanup expired; a user's
    /// earlier cooperative request was preempted. A run without the opt-in
    /// records nothing new.
    pub(super) async fn hard_deadline_closing(tx: &mut Transaction<'_, DB>, run: &DB::Row, at: DateTime<Utc>) -> Result<Option<Closing>> {
        if Self::timeout_cleanup_allowance(tx, &DB::string(run, "id")?).await?.is_none() {
            return Ok(None);
        }
        if Self::active_timeout_cleanup(tx, run).await?.is_some()
            || DB::optional_string(run, "cancellation_request_command_id")?.is_none()
        {
            let outcome = Self::timeout_cleanup_outcome(tx, run, "expired", None, at).await?;
            return Ok(Some(Closing { timeout_cleanup: Some(outcome), cancellation_cleanup: None }));
        }
        let request = DB::optional_string(run, "cancellation_request_command_id")?;
        let deadline = DB::optional_instant(run, "cancellation_deadline_at")?;
        Ok(Some(Closing { timeout_cleanup: None, cancellation_cleanup: Some(json!({"request_id": request, "outcome": "preempted",
            "cleanup_deadline_at": deadline.map(super::cooperative::timestamp), "finished_at": super::cooperative::timestamp(at)})) }))
    }

    /// How `finish_root_cancellation` closes an opted-in run instead of
    /// cancelling it: the engine request ends timed out, completed before
    /// its deadline or expired at it; a user's request still open at the
    /// hard deadline loses to the timeout. Otherwise `None`.
    pub(super) async fn cooperative_timeout_closing(tx: &mut Transaction<'_, DB>, run: &DB::Row, at: DateTime<Utc>) -> Result<Option<Closing>> {
        if Self::active_timeout_cleanup(tx, run).await?.is_some() {
            let expired = DB::optional_instant(run, "cancellation_deadline_at")?.is_some_and(|deadline| at >= deadline);
            let outcome = Self::timeout_cleanup_outcome(tx, run, if expired { "expired" } else { "completed" }, None, at).await?;
            return Ok(Some(Closing { timeout_cleanup: Some(outcome), cancellation_cleanup: None }));
        }
        let hard = earliest_hard_deadline(DB::optional_instant(run, "execution_deadline_at")?, DB::optional_instant(run, "run_deadline_at")?);
        if hard.is_some_and(|(_, deadline)| at >= deadline) {
            return Self::hard_deadline_closing(tx, run, at).await;
        }
        Ok(None)
    }

    /// The `preempted` outcome a terminate or hard cancel records while the
    /// engine's timeout request is open.
    pub(super) async fn preempted_timeout_cleanup(tx: &mut Transaction<'_, DB>, run: &DB::Row, at: DateTime<Utc>) -> Result<Option<Value>> {
        if Self::active_timeout_cleanup(tx, run).await?.is_none() {
            return Ok(None);
        }
        Self::timeout_cleanup_outcome(tx, run, "preempted", None, at).await.map(Some)
    }

    /// A user cooperative request's deadline on an opted-in run never
    /// outlives the run's earliest hard deadline.
    pub(super) async fn timeout_cleanup_capped_deadline(tx: &mut Transaction<'_, DB>, run: &DB::Row, deadline: DateTime<Utc>) -> Result<DateTime<Utc>> {
        if Self::timeout_cleanup_allowance(tx, &DB::string(run, "id")?).await?.is_none() {
            return Ok(deadline);
        }
        let hard = earliest_hard_deadline(DB::optional_instant(run, "execution_deadline_at")?, DB::optional_instant(run, "run_deadline_at")?);
        Ok(hard.map_or(deadline, |(_, hard)| hard.min(deadline)))
    }

    /// A workflow task for a close the runtime enforces without a worker.
    pub(super) async fn timeout_cleanup_control_task(tx: &mut Transaction<'_, DB>, run: &DB::Row, reason: &str) -> Result<DB::Row> {
        let run_id = DB::string(run, "id")?;
        let open = Self::query("SELECT * FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status IN ('ready','leased') ORDER BY id LIMIT 1")
            .bind(&run_id).fetch_optional(&mut **tx).await?;
        if let Some(task) = open {
            return Ok(task);
        }
        let task_id = Self::create_task_dispatched(tx, &run_id, &DB::string(run, "queue")?, "workflow", json!({"reason": reason}), false).await?;
        Self::query("UPDATE workflow_tasks SET repair_count=1 WHERE id=$1").bind(&task_id).execute(&mut **tx).await?;
        Ok(Self::query("SELECT * FROM workflow_tasks WHERE id=$1").bind(&task_id).fetch_one(&mut **tx).await?)
    }

    pub(super) async fn timeout_cleanup_due(
        tx: &mut Transaction<'_, DB>, run: &DB::Row, at: DateTime<Utc>,
    ) -> Result<Option<(&'static str, DateTime<Utc>, DateTime<Utc>)>> {
        if !matches!(DB::string(run, "status")?.as_str(), "pending" | "running" | "waiting")
            || DB::optional_string(run, "cancellation_request_command_id")?.is_some()
        {
            return Ok(None);
        }
        let execution = DB::optional_instant(run, "execution_deadline_at")?;
        let run_deadline = DB::optional_instant(run, "run_deadline_at")?;
        let Some((kind, hard)) = earliest_hard_deadline(execution, run_deadline) else {
            return Ok(None);
        };
        // Nothing to read before the largest allowance could apply.
        if at >= hard || at < hard - chrono::Duration::seconds(MAX_ALLOWANCE_SECONDS) {
            return Ok(None);
        }
        let run_id = DB::string(run, "id")?;
        let Some(allowance) = Self::timeout_cleanup_allowance(tx, &run_id).await? else {
            return Ok(None);
        };
        let work = hard - chrono::Duration::seconds(allowance);
        if at < work || Self::timeout_cleanup_started(tx, &run_id).await?.is_some() {
            return Ok(None);
        }
        Ok(Some((kind, hard, work)))
    }

    /// `TimeoutCleanup::enter`, under the run lock: once, at or after the
    /// run's work deadline and before its earliest hard deadline, record an
    /// engine-owned cooperative request with `cause: timeout` whose cleanup
    /// deadline is that hard deadline, `WorkflowTimeoutCleanupStarted`, and
    /// the request's history, then request the root scope's descendants and
    /// give the run a workflow task when none is open.
    pub(super) async fn enter_timeout_cleanup(&self, tx: &mut Transaction<'_, DB>, run: &DB::Row, at: DateTime<Utc>) -> Result<bool> {
        let Some((kind, hard, work)) = Self::timeout_cleanup_due(tx, run, at).await? else {
            return Ok(false);
        };
        let run_id = DB::string(run, "id")?;
        let instance = DB::string(run, "workflow_instance_id")?;
        let request_id = id();
        let timestamp = super::cooperative::timestamp;
        let context = json!({"schema": "durable-workflow.cancellation-context/v1", "request_id": request_id,
            "root_request_id": request_id, "root_workflow_instance_id": instance, "root_workflow_run_id": run_id,
            "parent_request_id": null, "reason": REASON, "requester": {"type": "system", "id": "timeout_cleanup"},
            "source": "system", "requested_at": timestamp(at), "cleanup_deadline_at": timestamp(hard),
            "lineage": [{"request_id": request_id, "workflow_instance_id": instance, "workflow_run_id": run_id}],
            "cause": CAUSE_TIMEOUT});
        let blob = super::cooperative::encode_command_now(json!({"reason": REASON, "cleanup_deadline_at": timestamp(hard),
            "cancellation": context}))?;
        let sequence = DB::number(run, "last_command_sequence")?
            .checked_add(1)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "command_sequence_exhausted"))?;
        let command_context = json!({"caller": {"type": "system", "label": "timeout_cleanup"},
            "auth": {"status": "not_applicable", "method": "none"}});
        Self::query("INSERT INTO workflow_commands(id,workflow_instance_id,workflow_run_id,requested_workflow_run_id,resolved_workflow_run_id,command_type,target_scope,source,context,status,outcome,workflow_type,workflow_class,payload_codec,payload,command_sequence,accepted_at,applied_at) VALUES ($1,$2,$3,$4,$5,'request_cancellation','run','system',$6,'accepted','cancellation_requested',$7,$8,'avro',$9,$10,$11,$12)")
            .bind(&request_id).bind(&instance).bind(&run_id).bind(&run_id).bind(&run_id).bind(DB::document(&command_context))
            .bind(DB::string(run, "workflow_type")?).bind(DB::string(run, "workflow_class")?).bind(&blob).bind(sequence)
            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).execute(&mut **tx).await?;
        Self::query("UPDATE workflow_runs SET cancellation_request_command_id=$1,cancellation_requested_at=$2,cancellation_deadline_at=$3,last_progress_at=$4,last_command_sequence=$5 WHERE id=$6")
            .bind(&request_id).bind(DB::bind_time(at)).bind(DB::bind_time(hard)).bind(DB::bind_time(at)).bind(sequence).bind(&run_id)
            .execute(&mut **tx).await?;
        Self::append(tx, &run_id, "WorkflowTimeoutCleanupStarted", json!({"workflow_command_id": request_id,
            "timeout_kind": kind, "work_deadline_at": iso(work), "cleanup_deadline_at": iso(hard),
            "hard_deadline_at": iso(hard), "detected_at": iso(at)}), None, Some(&request_id)).await?;
        Self::append(tx, &run_id, "CooperativeCancellationRequested", json!({"workflow_command_id": request_id,
            "workflow_instance_id": instance, "workflow_run_id": run_id, "command_type": "request_cancellation",
            "reason": REASON, "cleanup_deadline_at": timestamp(hard), "cancellation": context}), None, Some(&request_id)).await?;
        self.request_scope_descendants(tx, &run_id, super::cancellation_scopes::ROOT).await?;
        let open = Self::scalar("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status IN ('ready','leased')")
            .bind(&run_id).fetch_one(&mut **tx).await?;
        if open == 0 {
            Self::create_task(tx, &run_id, &DB::string(run, "queue")?, "workflow", json!({"resume_source_kind": "cancellation_request",
                "resume_source_id": request_id, "workflow_command_id": request_id})).await?;
        }
        tx.summary_changed(&run_id);
        Ok(true)
    }

    /// The idle wake: an opted-in run whose work deadline passed enters
    /// cleanup without waiting for a worker, and one whose hard deadline
    /// passed before cleanup began closes timed out, expired. Expiry is
    /// runtime authority: an absent worker never keeps the run open.
    pub(super) async fn enter_due_timeout_cleanups(&self) -> Result<()> {
        let stamp = iso(now());
        if Self::query(DB::TIMEOUT_CLEANUP_DUE).bind(stamp.as_str()).bind(stamp.as_str()).fetch_optional(&self.pool).await?.is_none() {
            return Ok(());
        }
        let mut tx = self.begin().await?;
        let due = Self::query(DB::TIMEOUT_CLEANUP_DUE).bind(stamp.as_str()).bind(stamp.as_str()).fetch_all(&mut *tx).await?;
        for row in due {
            let run_id = DB::string(&row, "id")?;
            let run = Self::query("SELECT * FROM workflow_runs WHERE id=$1").bind(&run_id).fetch_one(&mut *tx).await?;
            if !matches!(DB::string(&run, "status")?.as_str(), "pending" | "running" | "waiting")
                || DB::optional_string(&run, "cancellation_request_command_id")?.is_some()
            {
                continue;
            }
            let at = now();
            if self.enter_timeout_cleanup(&mut tx, &run, at).await? {
                continue;
            }
            let hard = earliest_hard_deadline(DB::optional_instant(&run, "execution_deadline_at")?, DB::optional_instant(&run, "run_deadline_at")?);
            if hard.is_some_and(|(_, deadline)| at >= deadline) && Self::timeout_cleanup_allowance(&mut tx, &run_id).await?.is_some() {
                let control = Self::timeout_cleanup_control_task(&mut tx, &run, "deadline_expired").await?;
                Self::time_out_run(&mut tx, &run, &control, &Value::Null).await?;
            }
        }
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(())
    }

    /// Describe's `timeout_cleanup`, registered as the
    /// `describe-timeout-cleanup-state` divergence: embedded Workflow 2.40.0
    /// reports only entered or finished cleanup, with the same `outcome`
    /// values. Only an opted-in run has it:
    /// `armed` before the work deadline is noticed,
    /// `cancellation_cleanup_running` with `cause: timeout` and the portable
    /// `outcome: running` while the engine's request is open, then
    /// `finished` with the recorded outcome, or `not_entered` when the run
    /// closed without entering cleanup.
    pub(super) async fn timeout_cleanup_view(executor: &mut DB::Connection, run: &DB::Row) -> Result<Option<Value>> {
        let execution = DB::optional_instant(run, "execution_deadline_at")?;
        let run_deadline = DB::optional_instant(run, "run_deadline_at")?;
        let Some((kind, hard)) = earliest_hard_deadline(execution, run_deadline) else {
            return Ok(None);
        };
        let run_id = DB::string(run, "id")?;
        let started = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='WorkflowStarted' ORDER BY sequence LIMIT 1")
            .bind(&run_id).fetch_optional(&mut *executor).await?;
        let Some(allowance) = started.map(|row| DB::document_row(&row, "payload")).transpose()?.as_ref().and_then(allowance) else {
            return Ok(None);
        };
        let entered = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='WorkflowTimeoutCleanupStarted' ORDER BY sequence LIMIT 1")
            .bind(&run_id).fetch_optional(&mut *executor).await?
            .map(|row| DB::document_row(&row, "payload")).transpose()?;
        let open = matches!(DB::string(run, "status")?.as_str(), "pending" | "running" | "waiting");
        let outcome = if open {
            Value::Null
        } else {
            Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type IN ('WorkflowTimedOut','WorkflowTerminated','WorkflowCancelled') ORDER BY sequence DESC LIMIT 1")
                .bind(&run_id).fetch_optional(&mut *executor).await?
                .map(|row| DB::document_row(&row, "payload")).transpose()?
                .map_or(Value::Null, |payload| payload[STARTED_KEY].clone())
        };
        let state = match (&entered, open, outcome.is_object()) {
            (_, false, true) => "finished",
            (Some(_), true, _) => "cancellation_cleanup_running",
            (None, true, _) => "armed",
            _ => "not_entered",
        };
        let entered = entered.unwrap_or(Value::Null);
        Ok(Some(json!({"state": state, "cause": if entered.is_null() { Value::Null } else { json!(CAUSE_TIMEOUT) },
            "allowance_seconds": allowance, "timeout_kind": entered.get("timeout_kind").cloned().unwrap_or_else(|| json!(kind)),
            "work_deadline_at": entered.get("work_deadline_at").cloned().unwrap_or_else(|| json!(iso(hard - chrono::Duration::seconds(allowance)))),
            "cleanup_deadline_at": entered.get("cleanup_deadline_at").cloned().unwrap_or_else(|| json!(iso(hard))),
            "started_at": entered.get("detected_at").cloned().unwrap_or(Value::Null),
            "outcome": if state == "cancellation_cleanup_running" { json!("running") } else { outcome.get("outcome").cloned().unwrap_or(Value::Null) },
            "failure_id": outcome.get("failure_id").cloned().unwrap_or(Value::Null)})))
    }

    /// A user's cooperative request during timeout cleanup: an accepted
    /// command that names the engine's request and carries its context, so
    /// the request is audited without another interruption or deadline.
    pub(super) async fn audit_timeout_cleanup_request(&self, tx: &mut Transaction<'_, DB>, run: &DB::Row, reason: Option<String>,
        codec: std::sync::Arc<tokio::sync::Semaphore>, context: Option<Value>, target_scope: &str) -> Result<()> {
        let run_id = DB::string(run, "id")?;
        let existing = DB::optional_string(run, "cancellation_request_command_id")?
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "cancellation_request_missing"))?;
        let requested = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND workflow_command_id=$2 AND event_type='CooperativeCancellationRequested' ORDER BY sequence LIMIT 1")
            .bind(&run_id).bind(&existing).fetch_optional(&mut **tx).await?
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "cancellation_request_history_missing"))?;
        let cancellation = DB::document_row(&requested, "payload")?["cancellation"].clone();
        let deadline = DB::optional_instant(run, "cancellation_deadline_at")?.map(super::cooperative::timestamp);
        let blob = super::cooperative::encode_command(json!({"reason": reason, "existing_request_id": existing,
            "cleanup_deadline_at": deadline, "cancellation": cancellation}), codec).await?;
        let sequence = DB::number(run, "last_command_sequence")?
            .checked_add(1)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "command_sequence_exhausted"))?;
        let command_context = context.unwrap_or_else(|| json!({"caller":{"type":"server","label":"Standalone Server"},
            "auth":{"status":"authorized","method":"token","role":"admin"},
            "principal":{"type":"auth:token","id":"legacy-token","label":"Admin"}}));
        let at = now();
        Self::query("INSERT INTO workflow_commands(id,workflow_instance_id,workflow_run_id,requested_workflow_run_id,resolved_workflow_run_id,command_type,target_scope,source,context,status,outcome,workflow_type,workflow_class,payload_codec,payload,command_sequence,accepted_at,applied_at) VALUES ($1,$2,$3,$4,$5,'request_cancellation',$6,'control_plane',$7,'accepted','cancellation_requested',$8,$9,'avro',$10,$11,$12,$13)")
            .bind(id()).bind(DB::string(run, "workflow_instance_id")?).bind(&run_id).bind((target_scope == "run").then_some(run_id.as_str()))
            .bind(&run_id).bind(target_scope).bind(DB::document(&command_context))
            .bind(DB::string(run, "workflow_type")?).bind(DB::string(run, "workflow_class")?).bind(&blob).bind(sequence)
            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).execute(&mut **tx).await?;
        Self::query("UPDATE workflow_runs SET last_command_sequence=$1 WHERE id=$2").bind(sequence).bind(&run_id).execute(&mut **tx).await?;
        Ok(())
    }

    /// Admission: an opted-in start needs a live worker on its queue that
    /// advertises timeout cleanup. It never falls back to a hard timeout.
    pub(super) async fn assert_timeout_cleanup_worker(tx: &mut Transaction<'_, DB>, namespace: &str, queue: &str, stale_after: i64) -> Result<()> {
        let cutoff = now() - chrono::Duration::seconds(stale_after.max(1));
        let rows = Self::query("SELECT capabilities,capability_manifest FROM workflow_worker_registrations WHERE namespace=$1 AND task_queue=$2 AND (last_heartbeat_at IS NULL OR last_heartbeat_at>=$3) AND (status IS NULL OR status='active')")
            .bind(namespace).bind(queue).bind(DB::bind_time(cutoff)).fetch_all(&mut **tx).await?;
        for row in &rows {
            let capabilities = DB::optional_document_row(row, "capabilities")?.unwrap_or(Value::Null);
            let manifest = DB::optional_document_row(row, "capability_manifest")?.unwrap_or(Value::Null);
            if worker_supports(&capabilities, &manifest) {
                return Ok(());
            }
        }
        Err(RuntimeError::Protocol { status: StatusCode::UNPROCESSABLE_ENTITY, response: json!({
            "reason": "timeout_cleanup_not_supported", "task_queue": queue,
            "message": format!("No live worker on task queue [{queue}] advertises timeout cleanup. Start without timeout_cleanup_seconds or run a worker that supports it.")}) })
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use chrono::TimeZone;

    #[test]
    fn admission_matches_embedded_messages() {
        assert!(admission_error(10, Some(70), None).is_none());
        assert!(admission_error(10, None, Some(11)).is_none());
        assert_eq!(admission_error(10, None, None).unwrap(),
            "Workflow v2 timeout cleanup needs an execution or run timeout to reserve time from.");
        assert_eq!(admission_error(10, Some(10), None).unwrap(),
            "Workflow v2 timeout cleanup (10 s) must be shorter than the execution timeout (10 s).");
        assert_eq!(admission_error(40, Some(70), Some(40)).unwrap(),
            "Workflow v2 timeout cleanup (40 s) must be shorter than the run timeout (40 s).");
    }

    #[test]
    fn started_payload_and_earliest_deadline() {
        let t = Utc.with_ymd_and_hms(2026, 11, 18, 10, 0, 0).unwrap() + chrono::Duration::microseconds(250_000);
        let execution = t + chrono::Duration::seconds(70);
        let run = t + chrono::Duration::seconds(40);
        assert_eq!(started_payload(10, Some(execution), Some(run)), json!({"allowance_seconds": 10,
            "work_deadlines": {"execution": "2026-11-18T10:01:00+00:00", "run": "2026-11-18T10:00:30+00:00"}}));
        assert_eq!(earliest_hard_deadline(Some(execution), Some(run)), Some(("run", run)));
        assert_eq!(earliest_hard_deadline(Some(run), Some(run)), Some(("execution", run)));
        assert_eq!(earliest_hard_deadline(None, None), None);
        assert_eq!(allowance(&json!({"timeout_cleanup": {"allowance_seconds": 10}})), Some(10));
        assert_eq!(allowance(&json!({"timeout_cleanup": {"allowance_seconds": 0}})), None);
        assert_eq!(allowance(&json!({})), None);
    }

    #[test]
    fn worker_capability_needs_list_and_manifest() {
        let manifest = json!({"timeout_cleanup": {"supported": true, "minimum_protocol_version": "1.20", "version": 1}});
        assert!(worker_supports(&json!(["timeout_cleanup"]), &manifest));
        assert!(!worker_supports(&json!([]), &manifest));
        assert!(!worker_supports(&json!(["timeout_cleanup"]), &json!({"timeout_cleanup": {"supported": false}})));
        assert!(!worker_supports(&json!(["timeout_cleanup"]), &json!({"timeout_cleanup": {"supported": true, "version": 2}})));
        assert_eq!(advertisement(), Some(json!({"supported": true, "version": 1})));
    }

    #[test]
    fn interruption_marker_is_a_closed_set() {
        assert!(!interruption_marker(&json!({"type": "fail_workflow"})).unwrap());
        assert!(!interruption_marker(&json!({"failure": {"interruption": null}})).unwrap());
        assert!(interruption_marker(&json!({"failure": {"interruption": "timeout_cleanup"}})).unwrap());
        assert!(!interruption_marker(&json!({"failure": "timeout_cleanup"})).unwrap());
        for bad in [json!({"failure": {"interruption": "cancellation"}}), json!({"failure": {"interruption": 1}}),
            json!({"failure": {"interruption": ""}})] {
            assert!(interruption_marker(&bad).is_err(), "{bad}");
        }
    }

    #[test]
    fn the_observation_follows_embedded() {
        assert_eq!(observation_event("waiting"), Some("WorkflowTimeoutCleanupStarted"));
        assert_eq!(observation_event("failed"), Some("WorkflowTimedOut"));
        assert_eq!(observation_event("completed"), None, "completed and continued-as-new runs report nothing");
        assert_eq!(observation_event("cancelled"), Some("WorkflowCancelled"));
        assert_eq!(observation_event("terminated"), Some("WorkflowTerminated"));
        assert_eq!(observation_event("pending"), Some("WorkflowTimeoutCleanupStarted"));
        assert_eq!(observation("WorkflowTimeoutCleanupStarted", &Value::Null), None);
        let entered = json!({"timeout_kind": "run", "work_deadline_at": "w", "cleanup_deadline_at": "c",
            "hard_deadline_at": "h", "detected_at": "d", "workflow_command_id": "x"});
        assert_eq!(observation("WorkflowTimeoutCleanupStarted", &entered), Some(json!({"outcome": "running",
            "cause": "timeout", "timeout_kind": "run", "started_at": "d", "work_deadline_at": "w", "cleanup_deadline_at": "c"})));
        let closed = json!({"timeout_cleanup": {"outcome": "failed", "timeout_kind": "execution", "cleanup_deadline_at": "c",
            "finished_at": "f", "failure_id": "id", "started_at": null}});
        assert_eq!(observation("WorkflowTimedOut", &closed), Some(json!({"outcome": "failed", "timeout_kind": "execution",
            "cleanup_deadline_at": "c", "finished_at": "f", "failure_id": "id"})));
        assert_eq!(observation("WorkflowTimedOut", &json!({"cancellation_cleanup": {"outcome": "preempted"}})), None);
        assert_eq!(observation("WorkflowTimedOut", &json!({"timeout_cleanup": {"outcome": "unknown"}})), None);
    }

    #[test]
    fn summaries_are_plain() {
        assert_eq!(summary(&json!({"outcome": "failed"})), Some("Timeout cleanup failed."));
        assert_eq!(summary(&Value::Null), None);
    }
}
