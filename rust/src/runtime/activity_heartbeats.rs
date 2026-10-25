//! Fenced heartbeat progress used by real cleanup activities.
use super::transaction::Transaction;
use super::{
    Result,
    backend::Backend,
    refuse,
    store::{Store, now, reject_fields},
    text,
};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

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
    pub(crate) async fn activity_status(
        &self,
        task_id: &str,
        body: Value,
        protocol: &str,
    ) -> Result<Value> {
        if !super::cooperative::supports(&json!(["cooperative_cancellation"]), protocol) {
            return Err(super::RuntimeError::Protocol {
                status: StatusCode::UNPROCESSABLE_ENTITY,
                response: json!({"reason":"activity_attempt_status_unavailable","minimum_protocol_version":"1.20"}),
            });
        }
        self.observe_activity(task_id, body, protocol).await
    }

    // Heartbeat can report lost authority on legacy protocols without granting
    // the separately capability-gated non-renewing status endpoint.
    async fn observe_activity(&self, task_id: &str, body: Value, protocol: &str) -> Result<Value> {
        let mut tx = self.begin_read().await?;
        let (response, _) = self
            .activity_observation(&mut tx, task_id, body, protocol, true)
            .await?;
        tx.commit().await?;
        Ok(response)
    }

    async fn activity_observation(
        &self,
        tx: &mut Transaction<'_, DB>,
        task_id: &str,
        body: Value,
        protocol: &str,
        check_session: bool,
    ) -> Result<(Value, bool)> {
        reject_fields(&body, &["activity_attempt_id", "lease_owner"])?;
        let attempt_id = text(&body, "activity_attempt_id")?;
        let owner = text(&body, "lease_owner")?;
        let task = self.task_row(tx, task_id).await?;
        if DB::string(&task, "task_type")? != "activity" {
            return Err(refuse(StatusCode::NOT_FOUND, "task_not_found"));
        }
        let attempt = Self::query("SELECT a.* FROM activity_attempts a JOIN workflow_tasks t ON t.id=a.workflow_task_id WHERE a.id=$1 AND t.namespace=$2")
            .bind(attempt_id).bind(self.namespace.as_ref()).fetch_optional(&mut **tx).await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND,"attempt_not_found"))?;
        if DB::string(&attempt, "workflow_task_id")? != task_id {
            return Err(refuse(StatusCode::CONFLICT, "task_mismatch"));
        }
        if DB::optional_string(&attempt, "lease_owner")?.as_deref() != Some(owner) {
            return Err(refuse(StatusCode::CONFLICT, "lease_owner_mismatch"));
        }
        let run_id = DB::string(&task, "workflow_run_id")?;
        let run = Self::query("SELECT * FROM workflow_runs WHERE id=$1")
            .bind(&run_id)
            .fetch_one(&mut **tx)
            .await?;
        let activity =
            Self::query("SELECT * FROM activity_executions WHERE id=$1 AND workflow_run_id=$2")
                .bind(DB::string(&attempt, "activity_execution_id")?)
                .bind(&run_id)
                .fetch_one(&mut **tx)
                .await?;
        let run_status = DB::string(&run, "status")?;
        let activity_status = DB::string(&activity, "status")?;
        let attempt_status = DB::string(&attempt, "status")?;
        let task_status = DB::string(&task, "status")?;
        let expires = DB::optional_instant(&attempt, "lease_expires_at")?
            .or(DB::optional_instant(&task, "lease_expires_at")?);
        let activity_expiry = super::activity_deadlines::expired_kind::<DB>(&activity, now())?;
        let (can_continue, cancel_requested, reason) = if run_status == "cancelled" {
            (false, true, Some("run_cancelled"))
        } else if run_status == "terminated" {
            (false, true, Some("run_terminated"))
        } else if attempt_status != "running" {
            (false, attempt_status == "cancelled", Some("attempt_closed"))
        } else if activity_status == "cancelled" {
            (false, true, Some("activity_cancelled"))
        } else if task_status == "cancelled" {
            (false, true, Some("task_cancelled"))
        } else if !matches!(run_status.as_str(), "pending" | "running" | "waiting") {
            (false, false, Some("run_closed"))
        } else if activity_status != "running" {
            (false, false, Some("activity_not_running"))
        } else if DB::optional_string(&activity, "current_attempt_id")?.as_deref()
            != Some(attempt_id)
            || DB::number(&activity, "attempt_count")? != DB::number(&attempt, "attempt_number")?
        {
            (false, false, Some("stale_attempt"))
        } else if DB::number(&task, "attempt_count")? != DB::number(&attempt, "attempt_number")? {
            (false, false, Some("stale_task"))
        } else if task_status != "leased" {
            (false, false, Some("task_not_leased"))
        } else if expires.is_none_or(|expires| expires <= now())
            || DB::optional_instant(&task, "lease_expires_at")?
                .is_none_or(|expires| expires <= now())
        {
            (false, false, Some("lease_expired"))
        } else if let Some(kind) = activity_expiry {
            (
                false,
                false,
                Some(super::activity_deadlines::timeout_reason(kind)),
            )
        } else {
            (true, false, None)
        };
        // A session-bound attempt shows its session's compact form, read
        // without a refresh; on status, a session that is no longer the
        // holder's live lease stops an attempt that could otherwise continue.
        let session = DB::optional_document_row(&activity, "activity_options")?
            .and_then(|options| super::worker_sessions::CommandSession::from_snapshot(&options["worker_session"]));
        let (worker_session, can_continue, reason) = match &session {
            Some(session) => {
                let queue = DB::optional_string(&activity, "queue")?.unwrap_or_default();
                let connection = DB::optional_string(&activity, "connection")?;
                let (view, usable) = self.session_for_status(tx, session, &queue, connection.as_deref(), owner).await?;
                if check_session && can_continue && !usable {
                    (view, false, Some("worker_session_unavailable"))
                } else {
                    (view, can_continue, reason)
                }
            }
            None => (Value::Null, can_continue, reason),
        };
        // Observation preserves the existing lease and never records progress.
        let mut response = json!({"task_id":task_id,"activity_attempt_id":attempt_id,"lease_owner":owner,
            "can_continue":can_continue,"cancel_requested":cancel_requested,"reason":reason,"heartbeat_recorded":false,
            // PHP formats each time in UTC with microseconds.
            "lease_expires_at":expires.map(super::cooperative::timestamp),
            "last_heartbeat_at":DB::optional_instant(&attempt,"last_heartbeat_at")?.or(DB::optional_instant(&activity,"last_heartbeat_at")?).map(super::cooperative::timestamp),
            "run_status":run_status,"run_closed_reason":DB::optional_string(&run,"closed_reason")?,"run_closed_at":DB::optional_instant(&run,"closed_at")?.map(super::cooperative::timestamp),
            "activity_status":activity_status,"attempt_status":attempt_status,"task_status":task_status,
            "deadlines":super::activity_deadlines::deadlines_view::<DB>(&activity)?,"worker_session":worker_session,"protocol_version":protocol,"server_capabilities":super::store::capabilities()});
        // A cooperative request that reached this attempt shows its receipt.
        if cancel_requested
            && let Some(receipt) = Self::activity_cancellation_receipt(tx, &run, &DB::string(&activity, "id")?, attempt_id).await?
        {
            response["cancellation_acknowledgement"] = receipt;
        }
        Ok((response, Self::cancellation_request(&run)?.is_none()))
    }

    pub(crate) async fn heartbeat_activity(
        &self,
        task_id: &str,
        body: Value,
        protocol: &str,
    ) -> Result<Value> {
        reject_fields(
            &body,
            &[
                "activity_attempt_id",
                "lease_owner",
                "message",
                "current",
                "total",
                "unit",
                "details",
            ],
        )?;
        let attempt_id = text(&body, "activity_attempt_id")?;
        let mut progress = json!({});
        for field in ["message", "current", "total", "unit", "details"] {
            if let Some(value) = body.get(field).filter(|value| !value.is_null()) {
                let valid = match field {
                    "message" | "unit" => value.is_string(),
                    "current" | "total" => value
                        .as_f64()
                        .or_else(|| value.as_str().and_then(|value| value.parse::<f64>().ok()))
                        .is_some_and(f64::is_finite),
                    _ => value.is_object() || value.is_array(),
                };
                if !valid {
                    return Err(refuse(
                        StatusCode::UNPROCESSABLE_ENTITY,
                        "invalid_heartbeat_progress",
                    ));
                }
                progress[field] = value.clone();
            }
        }
        let mut tx = self.begin().await?;
        let (observed, ordinary) = self
            .activity_observation(
                &mut tx,
                task_id,
                json!({"activity_attempt_id":attempt_id,"lease_owner":text(&body,"lease_owner")?}),
                protocol,
                false,
            )
            .await
            .map_err(|error| match error {
                super::RuntimeError::Refused {
                    reason: "attempt_not_found",
                    ..
                } => refuse(StatusCode::CONFLICT, "stale_attempt"),
                other => other,
            })?;
        // Published PHP answers with this structured refusal for ordinary
        // expired authority, for any closed run, whose activity is told it
        // was cancelled, and for an attempt a cooperative cancellation
        // reached. This read never records progress or renews a lease; a
        // concurrently closed attempt remains fenced.
        let closed = !observed["run_closed_reason"].is_null();
        let delivered = observed["cancel_requested"] == true && observed["reason"] == "attempt_closed";
        if observed["can_continue"] == false && (closed || delivered || ordinary && observed["cancel_requested"] == false) {
            let response = json!({"task_id":task_id,"activity_attempt_id":attempt_id,"lease_owner":body["lease_owner"],
                "cancel_requested":observed["cancel_requested"],"can_continue":false,"reason":observed["reason"],
                "run_closed_reason":observed["run_closed_reason"],"run_closed_at":observed["run_closed_at"],
                "heartbeat_recorded":false,"lease_expires_at":observed["lease_expires_at"],
                "last_heartbeat_at":observed["last_heartbeat_at"],"worker_session":observed["worker_session"],
                "protocol_version":protocol,"server_capabilities":super::store::capabilities()});
            tx.commit().await?;
            return Ok(response);
        }
        let task = self.task_row(&mut tx, task_id).await?;
        if DB::string(&task, "task_type")? != "activity" {
            return Err(refuse(StatusCode::NOT_FOUND, "task_not_found"));
        }
        Self::cancelled_activity_outcome(&mut tx, &task, &body).await?;
        if DB::string(&task, "status")? == "cancelled" {
            return Err(refuse(StatusCode::CONFLICT, "stale_attempt"));
        }
        Self::fence(&task, &body)?;
        let attempt =
            Self::query("SELECT * FROM activity_attempts WHERE id=$1 AND workflow_task_id=$2")
                .bind(attempt_id)
                .bind(task_id)
                .fetch_optional(&mut *tx)
                .await?
                .ok_or_else(|| refuse(StatusCode::CONFLICT, "stale_attempt"))?;
        if DB::string(&attempt, "status")? != "running"
            || DB::number(&attempt, "attempt_number")? != DB::number(&task, "attempt_count")?
        {
            return Err(refuse(StatusCode::CONFLICT, "stale_attempt"));
        }
        let run_id = DB::string(&task, "workflow_run_id")?;
        let run = Self::active_run(&mut tx, &run_id).await?;
        let activity_id = DB::string(&attempt, "activity_execution_id")?;
        let activity =
            Self::query("SELECT * FROM activity_executions WHERE id=$1 AND workflow_run_id=$2")
                .bind(&activity_id)
                .bind(&run_id)
                .fetch_one(&mut *tx)
                .await?;
        if DB::string(&activity, "status")? != "running"
            || DB::optional_string(&activity, "current_attempt_id")?.as_deref() != Some(attempt_id)
        {
            return Err(refuse(StatusCode::CONFLICT, "stale_attempt"));
        }
        super::activity_deadlines::fence::<DB>(&activity)?;
        // `heartbeatForAttempt`: a session-bound attempt renews its session
        // by the command's lease first. A refused renewal records nothing.
        let mut worker_session = Value::Null;
        if let Some(session) = DB::optional_document_row(&activity, "activity_options")?
            .and_then(|options| super::worker_sessions::CommandSession::from_snapshot(&options["worker_session"]))
        {
            match self.renew_session_for_attempt(&mut tx, &session, text(&body, "lease_owner")?).await? {
                Ok(snapshot) => worker_session = snapshot,
                Err((status, snapshot, renewal)) => {
                    return Err(super::RuntimeError::Protocol { status,
                        response: json!({"task_id":task_id,"activity_attempt_id":attempt_id,"lease_owner":body["lease_owner"],
                            "cancel_requested":observed["cancel_requested"],"can_continue":false,"reason":renewal["reason"],
                            "run_closed_reason":observed["run_closed_reason"],"run_closed_at":observed["run_closed_at"],
                            "heartbeat_recorded":false,"lease_expires_at":observed["lease_expires_at"],
                            "last_heartbeat_at":observed["last_heartbeat_at"],"error":renewal["error"],"worker_session":snapshot,
                            "worker_session_renewal":renewal,"protocol_version":protocol,"server_capabilities":super::store::capabilities()}) });
                }
            }
        }
        let at = now();
        let expires = if Self::cancellation_request(&run)?.is_none() {
            at + chrono::Duration::seconds(self.activity_task_lease_seconds)
        } else {
            self.cancellation_lease_expiry(&run)?
        };
        let policy = DB::optional_document_row(&activity, "retry_policy")?.unwrap_or(Value::Null);
        super::activity_deadlines::validate_policy(&policy)?;
        let heartbeat_deadline =
            super::activity_deadlines::deadline(&policy, "heartbeat_timeout", at)?;
        Self::query(
            "UPDATE activity_attempts SET last_heartbeat_at=$1,lease_expires_at=$2 WHERE id=$3",
        )
        .bind(DB::bind_time(at))
        .bind(DB::bind_time(expires))
        .bind(attempt_id)
        .execute(&mut *tx)
        .await?;
        Self::query("UPDATE activity_executions SET last_heartbeat_at=$1,heartbeat_deadline_at=$2 WHERE id=$3")
            .bind(DB::bind_time(at))
            .bind(DB::optional_time(heartbeat_deadline))
            .bind(&activity_id)
            .execute(&mut *tx)
            .await?;
        Self::query("UPDATE workflow_tasks SET lease_expires_at=$1 WHERE id=$2")
            .bind(DB::bind_time(expires))
            .bind(task_id)
            .execute(&mut *tx)
            .await?;
        tx.summary_changed(&run_id);
        Self::query("UPDATE workflow_runs SET last_progress_at=$1 WHERE id=$2")
            .bind(DB::bind_time(at))
            .bind(&run_id)
            .execute(&mut *tx)
            .await?;
        let mut payload = Self::activity_payload(&activity)?;
        payload["activity"]["last_heartbeat_at"] = json!(at);
        payload["activity_attempt_id"] = json!(attempt_id);
        payload["attempt_number"] = json!(DB::number(&attempt, "attempt_number")?);
        payload["progress"] = progress;
        payload["heartbeat_at"] = json!(at);
        payload["lease_expires_at"] = json!(expires);
        payload["activity_attempt"] = json!({"id":attempt_id,"attempt_number":DB::number(&attempt,"attempt_number")?,
            "status":"running","task_id":task_id,"lease_owner":body["lease_owner"],"last_heartbeat_at":at,
            "lease_expires_at":expires,"started_at":DB::instant(&attempt,"started_at")?});
        Self::append(
            &mut tx,
            &run_id,
            "ActivityHeartbeatRecorded",
            payload,
            Some(task_id),
            None,
        )
        .await?;
        tx.commit().await?;
        Ok(
            json!({"task_id":task_id,"activity_attempt_id":attempt_id,"lease_owner":body["lease_owner"],
            "cancel_requested":false,"can_continue":true,"reason":null,"run_closed_reason":null,"run_closed_at":null,
            // PHP formats each time in UTC with microseconds, as status reads do.
            "heartbeat_recorded":true,"lease_expires_at":super::cooperative::timestamp(expires),
            "last_heartbeat_at":super::cooperative::timestamp(at),"worker_session":worker_session,
            "protocol_version":protocol,"server_capabilities":super::store::capabilities()}),
        )
    }
}
