//! Immediate cancellation or termination of root runs and immediate
//! cancellation of direct child runs. Propagation trees and broader
//! propagation dependencies remain refused until their fixtures qualify.
use super::transaction::Transaction;
use super::{
    Result, RuntimeError,
    backend::Backend,
    children::ChildResolution,
    refuse,
    store::{Store, id, now},
};
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::sync::Arc;
use tokio::sync::Semaphore;

// Frozen PHP HTTP runs Laravel TrimStrings before cancellation validation.
// Pin its reviewed character set rather than a changing Unicode whitespace
// predicate. Internal characters and the embedded API's reasons stay intact.
pub(super) fn normalize_http_reason(reason: &str) -> &str {
    super::laravel_validation::laravel_trim(reason)
}

/// The two immediate terminal commands. Published PHP runs both through one
/// implementation; they differ only in the identity they record.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(crate) enum TerminalCommand {
    Cancel,
    Terminate,
}

impl TerminalCommand {
    pub(super) fn command_type(self) -> &'static str {
        match self {
            Self::Cancel => "cancel",
            Self::Terminate => "terminate",
        }
    }

    /// The outcome, run status, closed reason, failure category and
    /// propagation kind, which PHP records as one word.
    pub(super) fn closed(self) -> &'static str {
        match self {
            Self::Cancel => "cancelled",
            Self::Terminate => "terminated",
        }
    }

    fn events(self) -> (&'static str, &'static str) {
        match self {
            Self::Cancel => ("CancelRequested", "WorkflowCancelled"),
            Self::Terminate => ("TerminateRequested", "WorkflowTerminated"),
        }
    }

    fn exception(self) -> &'static str {
        match self {
            Self::Cancel => "Workflow\\V2\\Exceptions\\WorkflowCancelledException",
            Self::Terminate => "Workflow\\V2\\Exceptions\\WorkflowTerminatedException",
        }
    }

    pub(super) fn propagation_unavailable(self) -> &'static str {
        match self {
            Self::Cancel => "cancellation_propagation_not_available",
            Self::Terminate => "termination_propagation_not_available",
        }
    }
}

pub(super) async fn prepare_reason(
    body: &Value,
    semaphore: Arc<Semaphore>,
) -> Result<(Option<String>, Option<String>)> {
    // Laravel validates only its rules' fields and ignores the rest, such
    // as a client's `request_id` or identity fields a gateway adds.
    let reason = match body.get("reason").filter(|v| !v.is_null()) {
        None => None,
        Some(value) => Some(
            value
                .as_str()
                .map(normalize_http_reason)
                .filter(|s| s.chars().count() <= 1000)
                .ok_or_else(|| {
                    refuse(
                        StatusCode::UNPROCESSABLE_ENTITY,
                        "invalid_cancellation_reason",
                    )
                })?
                .to_owned(),
        ),
    }
    .filter(|s| !s.is_empty());
    let Some(value) = reason.clone() else {
        return Ok((None, None));
    };
    let permit = semaphore.acquire_owned().await.map_err(|_| {
        refuse(
            StatusCode::SERVICE_UNAVAILABLE,
            "cancellation_codec_unavailable",
        )
    })?;
    let blob = tokio::task::spawn_blocking(move || {
        let _permit = permit;
        use crate::codec::{Value as Datum, ValueCodec};
        ValueCodec::new()
            .and_then(|codec| {
                codec.encode(&Datum::Map(std::collections::BTreeMap::from([(
                    "reason".into(),
                    Datum::String(value),
                )])))
            })
            .map_err(|_| {
                refuse(
                    StatusCode::UNPROCESSABLE_ENTITY,
                    "invalid_cancellation_reason",
                )
            })
    })
    .await
    .map_err(|_| {
        refuse(
            StatusCode::SERVICE_UNAVAILABLE,
            "cancellation_codec_unavailable",
        )
    })??;
    Ok((reason, Some(blob)))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn reviewed_reasons() -> Value {
        serde_json::from_str(include_str!(
            "../../../tests/Fixtures/ServerParityNormalization/cancellation-reasons.json"
        ))
        .unwrap()
    }

    #[test]
    fn cancellation_boundary_trim_matches_the_frozen_php_character_set() {
        let expected: std::collections::BTreeSet<_> = reviewed_reasons()["trimmed_codepoints"]
            .as_array()
            .unwrap()
            .iter()
            .map(|point| u32::from_str_radix(&point.as_str().unwrap()[2..], 16).unwrap())
            .collect();
        for point in 0..=0x10ffff {
            if let Some(ch) = char::from_u32(point) {
                assert_eq!(
                    normalize_http_reason(&ch.to_string()).is_empty(),
                    expected.contains(&point),
                    "U+{point:04X}"
                );
            }
        }
    }

    #[tokio::test]
    async fn cancellation_reason_normalizes_before_nullable_unicode_length_validation() {
        let semaphore = Arc::new(Semaphore::new(1));
        for case in reviewed_reasons()["cases"].as_array().unwrap() {
            let (reason, blob) =
                prepare_reason(&json!({"reason":case["input"]}), semaphore.clone())
                    .await
                    .unwrap();
            assert_eq!(json!(reason), case["expected"]);
            assert_eq!(blob.is_some(), reason.is_some());
        }
        assert_eq!(
            prepare_reason(&json!({}), semaphore.clone()).await.unwrap(),
            (None, None)
        );
        let valid = format!("\u{a0}{}\u{200b}", "λ".repeat(1000));
        assert_eq!(
            prepare_reason(&json!({"reason":valid}), semaphore.clone())
                .await
                .unwrap()
                .0
                .unwrap(),
            "λ".repeat(1000)
        );
        for invalid in [json!("λ".repeat(1001)), json!(1), json!([])] {
            assert!(
                prepare_reason(&json!({"reason":invalid}), semaphore.clone())
                    .await
                    .is_err()
            );
        }
    }
}

impl<DB: Backend> Store<DB>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
    for<'q> &'q str: Encode<'q, DB> + Type<DB>,
    for<'q> Option<&'q str>: Encode<'q, DB>,
    for<'q> Option<DB::EncodedDocument>: Encode<'q, DB>,
    for<'q> String: Encode<'q, DB> + Type<DB>,
    for<'q> i64: Encode<'q, DB> + Type<DB>,
    for<'r> i64: Decode<'r, DB>,
    usize: ColumnIndex<DB::Row>,
{
    /// Whether native cannot close this open run yet, checked before anything
    /// is written. HTTP cancel and terminate and a parent-close policy share
    /// this rule.
    ///
    /// PHP's close follows only `child_workflow` links, to resolve the parent
    /// (`WorkflowStub::attemptCancel`, `attemptTerminate`). A
    /// `continue_as_new` or `redrive` link names a closed predecessor and
    /// needs nothing. Only a `request_cancellation` child still refuses: PHP
    /// gives it a new cancellation origin (`ParentCloseCancellation::ensureOrigin`),
    /// which native does not have yet. Live queries never hold a close: PHP's
    /// query broker has no run-status check, so a query admitted before the
    /// close is still leased and answered.
    pub(super) async fn close_unavailable(
        connection: &mut DB::Connection,
        run_id: &str,
    ) -> Result<bool> {
        let links = Self::scalar(
            "SELECT COUNT(*) FROM workflow_links WHERE child_workflow_run_id=$1 AND link_type NOT IN ('child_workflow','continue_as_new','redrive')",
        )
        .bind(run_id)
        .fetch_one(&mut *connection)
        .await?;
        let cooperative_children = Self::scalar("SELECT COUNT(*) FROM workflow_child_calls WHERE parent_workflow_run_id=$1 AND status NOT IN ('completed','cancelled','terminated','failed') AND parent_close_policy='request_cancellation'")
            .bind(run_id)
            .fetch_one(&mut *connection)
            .await?;
        Ok(links != 0 || cooperative_children != 0)
    }

    pub(crate) async fn close_workflow(
        &self,
        command: TerminalCommand,
        workflow_id: &str,
        selected_run: Option<&str>,
        reason: Option<String>,
        blob: Option<String>,
        context: Option<Value>,
    ) -> Result<(StatusCode, Value)> {
        let mut tx = self.begin().await?;
        let run = Self::query("SELECT r.* FROM workflow_instances i JOIN workflow_runs r ON r.id=i.current_run_id WHERE i.id=$1 AND i.namespace=$2")
            .bind(workflow_id).bind(self.namespace.as_ref()).fetch_optional(&mut *tx).await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "instance_not_found"))?;
        let run_id = DB::string(&run, "id")?;
        if selected_run.is_some_and(|id| id != run_id) {
            return Err(RuntimeError::Protocol {
                status: StatusCode::CONFLICT,
                response: json!({"workflow_id":workflow_id,"run_id":selected_run,
                    "reason":"historical_run_command_rejected","target_scope":"run"}),
            });
        }
        let closed = !matches!(
            DB::string(&run, "status")?.as_str(),
            "pending" | "running" | "waiting"
        );
        // A cancelled or terminated child resolves its parent with
        // ChildRunCancelled or ChildRunTerminated, as in PHP. Open children
        // follow their parent-close policies below.
        if !closed && Self::close_unavailable(&mut tx, &run_id).await? {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                command.propagation_unavailable(),
            ));
        }
        let activities = Self::query("SELECT * FROM activity_executions WHERE workflow_run_id=$1 AND status IN ('pending','running') ORDER BY id LIMIT 1001")
            .bind(&run_id).fetch_all(&mut *tx).await?;
        let timers = Self::query("SELECT * FROM workflow_run_timers WHERE workflow_run_id=$1 AND status='pending' ORDER BY id LIMIT 1001")
            .bind(&run_id).fetch_all(&mut *tx).await?;
        if !closed && (activities.len() > 1000 || timers.len() > 1000) {
            return Err(refuse(
                StatusCode::SERVICE_UNAVAILABLE,
                "cancellation_inventory_exceeds_budget",
            ));
        }
        let command_id = id();
        let command_sequence = DB::number(&run, "last_command_sequence")?
            .checked_add(1)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "command_sequence_exhausted"))?;
        let at = now();
        let status = if closed { "rejected" } else { "accepted" };
        let rejection = if closed { Some("run_not_active") } else { None };
        let outcome = if closed { None } else { Some(command.closed()) };
        // The frozen HTTP selected-run route guards the current run but records
        // an instance-scoped command. Preserve this public receipt representation.
        Self::query("INSERT INTO workflow_commands(id,workflow_instance_id,workflow_run_id,resolved_workflow_run_id,command_type,target_scope,source,status,outcome,rejection_reason,workflow_type,payload_codec,payload,command_sequence,accepted_at,applied_at,rejected_at,context) VALUES ($1,$2,$3,$4,$5,'instance','control_plane',$6,$7,$8,$9,'avro',$10,$11,CASE WHEN $12='accepted' THEN $13 ELSE NULL END,CASE WHEN $14='accepted' THEN $15 ELSE NULL END,CASE WHEN $16='rejected' THEN $17 ELSE NULL END,$18)")
            .bind(&command_id).bind(workflow_id).bind(&run_id).bind(&run_id).bind(command.command_type()).bind(status).bind(outcome).bind(rejection)
            .bind(DB::string(&run,"workflow_type")?).bind(if closed {None} else {blob.as_deref()}).bind(command_sequence)
            .bind(status).bind(DB::bind_time(at)).bind(status).bind(DB::bind_time(at))
            .bind(status).bind(DB::bind_time(at)).bind(DB::optional_document(context.as_ref().unwrap_or(&Value::Null)))
            .execute(&mut *tx).await?;
        Self::query("UPDATE workflow_runs SET last_command_sequence=$1 WHERE id=$2")
            .bind(command_sequence)
            .bind(&run_id)
            .execute(&mut *tx)
            .await?;
        let response = json!({"workflow_id":workflow_id,"run_id":run_id,"requested_run_id":null,"resolved_run_id":run_id,
            "workflow_type":DB::string(&run,"workflow_type")?,"command_id":command_id,"command_sequence":command_sequence,
            "command_status":status,"command_source":"control_plane","target_scope":"instance","outcome":if closed {Some("rejected_not_active")} else {outcome},
            "reason":if closed {rejection} else {reason.as_deref()},"rejection_reason":rejection,"validation_errors":[]});
        if closed {
            tx.commit().await?;
            return Ok((StatusCode::CONFLICT, response));
        }
        Self::finish_close(&mut tx, &run, command, &command_id, reason.as_deref(), at, 0).await?;
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok((StatusCode::OK, response))
    }

    /// The close itself once its command is recorded: the requested and
    /// terminal events, its open work cancelled, its failure, its own
    /// parent-close policies and the resolution of its parent.
    pub(super) async fn finish_close(
        tx: &mut Transaction<'_, DB>,
        run: &DB::Row,
        command: TerminalCommand,
        command_id: &str,
        reason: Option<&str>,
        at: DateTime<Utc>,
        depth: u8,
    ) -> Result<()> {
        let run_id = DB::string(run, "id")?;
        let workflow_id = DB::string(run, "workflow_instance_id")?;
        let activities = Self::query("SELECT * FROM activity_executions WHERE workflow_run_id=$1 AND status IN ('pending','running') ORDER BY id LIMIT 1001")
            .bind(&run_id).fetch_all(&mut **tx).await?;
        let timers = Self::query("SELECT * FROM workflow_run_timers WHERE workflow_run_id=$1 AND status='pending' ORDER BY id LIMIT 1001")
            .bind(&run_id).fetch_all(&mut **tx).await?;
        let mut identity = json!({"workflow_command_id":command_id,"workflow_instance_id":workflow_id,"workflow_run_id":run_id});
        if let Some(reason) = reason {
            identity["reason"] = json!(reason);
        }
        let (requested_event, terminal_event) = command.events();
        let mut requested = identity.clone();
        requested["command_type"] = json!(command.command_type());
        Self::append(
            tx,
            &run_id,
            requested_event,
            requested,
            None,
            Some(command_id),
        )
        .await?;
        Self::query("UPDATE workflow_tasks SET status='cancelled',lease_expires_at=NULL,last_error=NULL WHERE workflow_run_id=$1 AND status IN ('ready','leased')")
            .bind(&run_id).execute(&mut **tx).await?;
        Self::cancel_open_activities(tx, &run_id, Some(command_id), activities, at).await?;
        for timer in timers {
            let timer_id = DB::string(&timer, "id")?;
            Self::query("UPDATE workflow_run_timers SET status='cancelled' WHERE id=$1")
                .bind(&timer_id)
                .execute(&mut **tx)
                .await?;
            let payload = Self::timer_cancellation_payload(tx, &run_id, &timer, at).await?;
            Self::append(
                tx,
                &run_id,
                "TimerCancelled",
                payload,
                None,
                Some(command_id),
            )
            .await?;
        }
        let failure_id = id();
        let closed_as = command.closed();
        let message = reason.as_ref().map_or_else(
            || format!("Workflow {closed_as}."),
            |reason| format!("Workflow {closed_as}: {reason}"),
        );
        // Corrected PHP stores the entire diagnostic as one JSON string
        // literal when NUL would truncate or invalidate a SQL text value.
        // Keep the original reason in history and ordinary messages unchanged.
        let message = if message.contains('\0') {
            serde_json::to_string(&message)?
        } else {
            message
        };
        let exception = command.exception();
        Self::query("INSERT INTO workflow_failures(id,workflow_run_id,source_kind,source_id,propagation_kind,failure_category,handled,exception_class,message,file,line,trace_preview,created_at,updated_at) VALUES ($1,$2,'workflow_run',$3,$4,$5,FALSE,$6,$7,'',0,'',$8,$9)")
            .bind(&failure_id).bind(&run_id).bind(&run_id).bind(closed_as).bind(closed_as).bind(exception).bind(&message)
            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).execute(&mut **tx).await?;
        let mut terminal = identity;
        terminal["failure_id"] = json!(failure_id);
        terminal["failure_category"] = json!(closed_as);
        terminal["closed_reason"] = json!(closed_as);
        terminal["exception_class"] = json!(exception);
        terminal["message"] = json!(message);
        Self::query("UPDATE workflow_runs SET status=$1,closed_reason=$2,closed_at=$3,last_progress_at=$4 WHERE id=$5")
            .bind(closed_as).bind(closed_as).bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(&run_id).execute(&mut **tx).await?;
        Self::append(
            tx,
            &run_id,
            terminal_event,
            terminal.clone(),
            None,
            Some(command_id),
        )
        .await?;
        Self::close_pending_updates(tx, &run_id, None).await?;
        Box::pin(Self::enforce_parent_close_policies(tx, run, closed_as, depth)).await?;
        let resolution = match command {
            TerminalCommand::Cancel => ChildResolution::Cancelled(&terminal),
            TerminalCommand::Terminate => ChildResolution::Terminated(&terminal),
        };
        Self::resolve_child(tx, run, resolution, at).await?;
        tx.summary_changed(&run_id);
        Ok(())
    }

    pub(super) async fn timer_cancellation_payload(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        timer: &DB::Row,
        at: chrono::DateTime<chrono::Utc>,
    ) -> Result<Value> {
        let timer_id = DB::string(timer, "id")?;
        let scheduled = Self::query(DB::TIMER_SCHEDULE_SQL)
            .bind(run_id)
            .bind(&timer_id)
            .fetch_optional(&mut **tx)
            .await?
            .map(|row| DB::document_row(&row, "payload"))
            .transpose()?
            .unwrap_or(Value::Null);
        let mut payload = json!({"timer_id":timer_id,"sequence":DB::number(timer,"sequence")?,
            "delay_seconds":DB::number(timer,"delay_seconds")?,"fire_at":DB::instant(timer,"fire_at")?,"cancelled_at":at});
        for key in ["sequence", "delay_seconds"] {
            if let Some(value) = scheduled[key]
                .as_i64()
                .or_else(|| scheduled[key].as_str()?.parse().ok())
            {
                payload[key] = json!(value);
            }
        }
        for key in [
            "fire_at",
            "timer_kind",
            "condition_wait_id",
            "condition_wait_occurrence_id",
            "condition_key",
            "condition_definition_fingerprint",
            "signal_wait_id",
            "signal_name",
        ] {
            if scheduled[key]
                .as_str()
                .is_some_and(|value| !value.is_empty())
            {
                payload[key] = scheduled[key].clone();
            }
        }
        let metadata = super::selections::metadata(&scheduled, "timer")?;
        super::selections::merge(&mut payload, &metadata);
        Ok(payload)
    }

    pub(super) async fn cancel_open_activities(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        command_id: Option<&str>,
        activities: Vec<DB::Row>,
        at: chrono::DateTime<chrono::Utc>,
    ) -> Result<()> {
        for activity in activities {
            let activity_id = DB::string(&activity, "id")?;
            let attempt_id = DB::optional_string(&activity, "current_attempt_id")?;
            Self::query("UPDATE activity_executions SET status='cancelled',closed_at=COALESCE(closed_at,$1) WHERE id=$2")
                .bind(DB::bind_time(at)).bind(&activity_id).execute(&mut **tx).await?;
            let mut attempt_snapshot = Value::Null;
            let mut task_id = None;
            if let Some(attempt_id) = &attempt_id {
                Self::query("UPDATE activity_attempts SET status='cancelled',lease_expires_at=NULL,closed_at=COALESCE(closed_at,$1) WHERE id=$2 AND activity_execution_id=$3 AND workflow_run_id=$4")
                    .bind(DB::bind_time(at)).bind(attempt_id).bind(&activity_id).bind(run_id).execute(&mut **tx).await?;
                let attempt = Self::query("SELECT * FROM activity_attempts WHERE id=$1 AND activity_execution_id=$2 AND workflow_run_id=$3")
                    .bind(attempt_id).bind(&activity_id).bind(run_id).fetch_one(&mut **tx).await?;
                task_id = Some(DB::string(&attempt, "workflow_task_id")?);
                attempt_snapshot = json!({"id":attempt_id,"activity_execution_id":activity_id,"task_id":task_id,
                    "attempt_number":DB::number(&attempt,"attempt_number")?,"status":"cancelled",
                    "lease_owner":DB::optional_string(&attempt,"lease_owner")?,
                    "started_at":DB::optional_instant(&attempt,"started_at")?,"closed_at":DB::optional_instant(&attempt,"closed_at")?});
            }
            let cancelled = Self::query("SELECT * FROM activity_executions WHERE id=$1")
                .bind(&activity_id)
                .fetch_one(&mut **tx)
                .await?;
            let mut payload = Self::activity_payload(&cancelled)?;
            payload["workflow_command_id"] = json!(command_id);
            payload["activity_attempt_id"] = json!(attempt_id);
            payload["attempt_number"] = json!(DB::number(&cancelled, "attempt_count")?);
            payload["cancelled_at"] = json!(DB::optional_instant(&cancelled, "closed_at")?);
            payload["activity_attempt"] = attempt_snapshot;
            Self::append(
                tx,
                run_id,
                "ActivityCancelled",
                payload,
                task_id.as_deref(),
                command_id,
            )
            .await?;
        }
        Ok(())
    }

    /// `WorkflowExecutor::timeoutRun`: a workflow task finished after the
    /// run's execution or run deadline, so the run fails as timed out. Open
    /// tasks, activities and timers are cancelled, pending updates close and
    /// a parent sees the child fail. Returns the worker's refusal.
    pub(super) async fn time_out_run(
        tx: &mut Transaction<'_, DB>,
        run: &DB::Row,
        task: &DB::Row,
        attempt: &Value,
    ) -> Result<Value> {
        let run_id = DB::string(run, "id")?;
        let task_id = DB::string(task, "id")?;
        let at = now();
        let execution_deadline = DB::optional_instant(run, "execution_deadline_at")?;
        let run_deadline = DB::optional_instant(run, "run_deadline_at")?;
        let iso = |time: Option<chrono::DateTime<chrono::Utc>>| time.map(|time| time.format("%Y-%m-%dT%H:%M:%S+00:00").to_string());
        let (timeout_kind, message) = if execution_deadline.is_some_and(|deadline| deadline <= at) {
            ("execution_timeout", format!("Workflow execution deadline expired at {}.", iso(execution_deadline).unwrap_or_default()))
        } else {
            ("run_timeout", format!("Workflow run deadline expired at {}.", iso(run_deadline).unwrap_or_default()))
        };
        let exception = "Workflow\\V2\\Exceptions\\WorkflowTimeoutException";
        let activities = Self::query("SELECT * FROM activity_executions WHERE workflow_run_id=$1 AND status IN ('pending','running') ORDER BY id")
            .bind(&run_id).fetch_all(&mut **tx).await?;
        let timers = Self::query("SELECT * FROM workflow_run_timers WHERE workflow_run_id=$1 AND status='pending' ORDER BY id")
            .bind(&run_id).fetch_all(&mut **tx).await?;
        Self::query("UPDATE workflow_tasks SET status='cancelled',lease_expires_at=NULL,last_error=NULL WHERE workflow_run_id=$1 AND status IN ('ready','leased') AND id<>$2")
            .bind(&run_id).bind(&task_id).execute(&mut **tx).await?;
        Self::cancel_open_activities(tx, &run_id, None, activities, at).await?;
        for timer in timers {
            let timer_id = DB::string(&timer, "id")?;
            Self::query("UPDATE workflow_run_timers SET status='cancelled' WHERE id=$1")
                .bind(&timer_id)
                .execute(&mut **tx)
                .await?;
            let payload = Self::timer_cancellation_payload(tx, &run_id, &timer, at).await?;
            Self::append(tx, &run_id, "TimerCancelled", payload, None, None).await?;
        }
        let failure_id = id();
        Self::query("INSERT INTO workflow_failures(id,workflow_run_id,source_kind,source_id,propagation_kind,failure_category,handled,exception_class,message,file,line,trace_preview,created_at,updated_at) VALUES ($1,$2,'workflow_run',$3,'timeout','timeout',FALSE,$4,$5,'',0,'',$6,$7)")
            .bind(&failure_id).bind(&run_id).bind(&run_id).bind(exception).bind(&message)
            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).execute(&mut **tx).await?;
        Self::query("UPDATE workflow_runs SET status='failed',closed_reason='timed_out',closed_at=$1,last_progress_at=$2 WHERE id=$3")
            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(&run_id).execute(&mut **tx).await?;
        let timed_out = json!({"failure_id":failure_id,"timeout_kind":timeout_kind,"failure_category":"timeout",
            "message":message,"exception_class":exception,"execution_deadline_at":iso(execution_deadline),
            "run_deadline_at":iso(run_deadline),"task":Self::task_snapshot(task)?});
        Self::append(tx, &run_id, "WorkflowTimedOut", timed_out.clone(), Some(&task_id), None).await?;
        Self::close_pending_updates(tx, &run_id, Some(task)).await?;
        Self::enforce_parent_close_policies(tx, run, "timed_out", 0).await?;
        Self::query("UPDATE workflow_tasks SET status='completed',lease_expires_at=NULL WHERE id=$1")
            .bind(&task_id)
            .execute(&mut **tx)
            .await?;
        Self::resolve_child(tx, run, ChildResolution::Failed(&timed_out), at).await?;
        tx.summary_changed(&run_id);
        Ok(json!({"task_id":task_id,"workflow_task_attempt":attempt,"outcome":"completed","recorded":false,
            "run_id":run_id,"run_status":"failed","created_task_ids":[],"reason":"run_timed_out",
            "protocol_version":super::WORKER_PROTOCOL_VERSION,"server_capabilities":super::store::capabilities()}))
    }

    pub(super) async fn cancelled_activity_outcome(
        tx: &mut Transaction<'_, DB>,
        task: &DB::Row,
        body: &Value,
    ) -> Result<()> {
        if DB::string(task, "status")? != "cancelled" {
            return Ok(());
        }
        let attempt_id = super::text(body, "activity_attempt_id")?;
        let owner = super::text(body, "lease_owner")?;
        let run_id = DB::string(task, "workflow_run_id")?;
        let run =
            Self::query("SELECT status,closed_reason,closed_at FROM workflow_runs WHERE id=$1")
                .bind(&run_id)
                .fetch_one(&mut **tx)
                .await?;
        let attempt = Self::query("SELECT * FROM activity_attempts WHERE id=$1 AND workflow_task_id=$2 AND workflow_run_id=$3")
            .bind(attempt_id).bind(DB::string(task,"id")?).bind(&run_id).fetch_optional(&mut **tx).await?;
        let run_status = DB::string(&run, "status")?;
        let reason = match run_status.as_str() {
            "cancelled" => "run_cancelled",
            "terminated" => "run_terminated",
            // A deadline, not the run, cancelled this task. The caller
            // answers the late outcome with PHP's stale attempt receipt.
            _ => return Ok(()),
        };
        if let Some(attempt) = attempt {
            if DB::string(&attempt, "status")? == "cancelled"
                && DB::optional_string(&attempt, "lease_owner")?.as_deref() == Some(owner)
            {
                let activity = Self::query(
                    "SELECT status FROM activity_executions WHERE id=$1 AND workflow_run_id=$2",
                )
                .bind(DB::string(&attempt, "activity_execution_id")?)
                .bind(&run_id)
                .fetch_one(&mut **tx)
                .await?;
                return Err(RuntimeError::Protocol {
                    status: StatusCode::CONFLICT,
                    response: json!({"task_id":DB::string(task,"id")?,"activity_attempt_id":attempt_id,
                    "outcome":"ignored","recorded":false,"reason":reason,"next_task_id":null,
                    "cancel_requested":true,"can_continue":false,"run_status":run_status,
                    "run_closed_reason":DB::optional_string(&run,"closed_reason")?,"run_closed_at":DB::optional_instant(&run,"closed_at")?,
                    "activity_status":DB::string(&activity,"status")?,"attempt_status":"cancelled","task_status":"cancelled",
                    "lease_owner":owner,"lease_expires_at":null}),
                });
            }
        }
        Err(refuse(StatusCode::CONFLICT, "stale_attempt"))
    }
}
