//! Root cancellation keeps one durable request, budget and claim-time proof.
//! Unsupported propagation remains explicit while the wider corpus is pending.
use super::transaction::Transaction;
use super::{
    Result, RuntimeError,
    backend::Backend,
    refuse,
    store::{Store, id, now, reject_fields},
};
use axum::http::StatusCode;
use chrono::{DateTime, SecondsFormat, Utc};
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::{collections::BTreeMap, sync::Arc};
use tokio::sync::Semaphore;

pub(super) const CLAIM_KEY: &str = "_server_workflow_claim";

pub(super) fn validate_request(body: &Value) -> Result<(Option<String>, i64)> {
    // Fields outside the controller's rules are ignored, as Laravel does.
    let reason = match body.get("reason").filter(|value| !value.is_null()) {
        None => None,
        Some(value) => Some(
            value
                .as_str()
                .map(super::cancellation::normalize_http_reason)
                .filter(|value| value.chars().count() <= 1000)
                .ok_or_else(|| {
                    refuse(
                        StatusCode::UNPROCESSABLE_ENTITY,
                        "invalid_cancellation_reason",
                    )
                })?
                .to_owned(),
        ),
    }
    .filter(|value| !value.is_empty());
    let budget = body
        .get("cleanup_timeout_seconds")
        .filter(|value| !value.is_null())
        .map_or(Some(600), Value::as_i64)
        .filter(|value| (1..=3600).contains(value))
        .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_cleanup_timeout"))?;
    Ok((reason, budget))
}

pub(super) fn timestamp(at: DateTime<Utc>) -> String {
    at.to_rfc3339_opts(SecondsFormat::Micros, true)
}

pub(super) fn supports(capabilities: &Value, protocol: &str) -> bool {
    protocol.split_once('.').is_some_and(|(major, minor)| {
        major == "1" && minor.parse::<u8>().is_ok_and(|minor| minor >= 20)
    }) && capabilities
        .as_array()
        .is_some_and(|items| items.iter().any(|item| item == "cooperative_cancellation"))
}

// Convert only the bounded, server-authored command/context tree. Wire encoding
// remains the official Avro codec and runs off the async executor threads.
pub(super) async fn encode_command(value: Value, semaphore: Arc<Semaphore>) -> Result<String> {
    let permit = semaphore.acquire_owned().await.map_err(|_| {
        refuse(
            StatusCode::SERVICE_UNAVAILABLE,
            "cancellation_codec_unavailable",
        )
    })?;
    tokio::task::spawn_blocking(move || {
        let _permit = permit;
        use crate::codec::{Value as Datum, ValueCodec};
        fn datum(value: Value) -> Datum {
            match value {
                Value::Null => Datum::Null,
                Value::Bool(value) => Datum::Boolean(value),
                Value::String(value) => Datum::String(value),
                Value::Array(values) => Datum::Array(values.into_iter().map(datum).collect()),
                Value::Object(values) => Datum::Map(
                    values
                        .into_iter()
                        .map(|(key, value)| (key, datum(value)))
                        .collect::<BTreeMap<_, _>>(),
                ),
                // There are no numeric fields in the authored root context.
                Value::Number(_) => unreachable!("server-authored root cancellation context"),
            }
        }
        ValueCodec::new()
            .and_then(|codec| codec.encode(&datum(value)))
            .map_err(|_| {
                refuse(
                    StatusCode::SERVICE_UNAVAILABLE,
                    "cancellation_codec_unavailable",
                )
            })
    })
    .await
    .map_err(|_| {
        refuse(
            StatusCode::SERVICE_UNAVAILABLE,
            "cancellation_codec_unavailable",
        )
    })?
}

/// PHP's default `command_batch_size` structural limit.
const PARALLEL_SPAN_LIMIT: i64 = 1000;

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
    pub(super) fn cancellation_lease_expiry(&self, run: &DB::Row) -> Result<DateTime<Utc>> {
        if DB::optional_string(run, "cancellation_request_command_id")?.is_some() {
            let deadline = DB::optional_instant(run, "cancellation_deadline_at")?
                .ok_or_else(|| refuse(StatusCode::CONFLICT, "cancellation_deadline_missing"))?;
            Ok(deadline.min(now() + chrono::Duration::seconds(10)))
        } else {
            // PHP leases a workflow task for its configured DW_WORKFLOW_TASK_TIMEOUT.
            Ok(now() + chrono::Duration::seconds(self.workflow_task_lease_seconds))
        }
    }

    pub(super) fn cancellation_request(run: &DB::Row) -> Result<Option<Value>> {
        let Some(request_id) = DB::optional_string(run, "cancellation_request_command_id")? else {
            return Ok(None);
        };
        Ok(Some(json!({"request_id":request_id,
            "requested_at":DB::optional_instant(run,"cancellation_requested_at")?.map(timestamp),
            "cleanup_deadline_at":DB::optional_instant(run,"cancellation_deadline_at")?.map(timestamp),
            "delivery_sequence":DB::optional_number(run,"cancellation_delivery_sequence")?,
            "delivered_at":DB::optional_instant(run,"cancellation_delivered_at")?.map(timestamp),
            "history_refresh_page_token":"MA=="})))
    }

    pub(super) fn claim_supports_cancellation(task: &DB::Row) -> Result<bool> {
        let payload = DB::document_row(task, "payload")?;
        let claim = &payload[CLAIM_KEY];
        Ok(claim["task_id"] == DB::string(task, "id")?
            && claim["run_id"] == DB::string(task, "workflow_run_id")?
            && claim["namespace"] == DB::string(task, "namespace")?
            && claim["worker_id"].as_str() == DB::optional_string(task, "lease_owner")?.as_deref()
            && claim["lease_owner"].as_str()
                == DB::optional_string(task, "lease_owner")?.as_deref()
            && claim["attempt"].as_i64() == Some(DB::number(task, "attempt_count")?)
            && supports(
                &claim["capabilities"],
                claim["protocol_version"].as_str().unwrap_or(""),
            ))
    }

    pub(crate) async fn deliver_cancellation(
        &self,
        task_id: &str,
        body: Value,
        protocol: &str,
        codec: Arc<Semaphore>,
    ) -> Result<Value> {
        if !supports(&json!(["cooperative_cancellation"]), protocol) {
            return Err(refuse(
                StatusCode::CONFLICT,
                "cooperative_cancellation_not_supported",
            ));
        }
        reject_fields(
            &body,
            &[
                "lease_owner",
                "workflow_task_attempt",
                "request_id",
                "sequence",
                "call_kind",
                "sequence_span",
                "operation_sequence",
                "operation_sequence_span",
            ],
        )?;
        let request_id = super::text(&body, "request_id")?;
        let sequence = body["sequence"]
            .as_i64()
            .filter(|value| *value >= 1)
            .ok_or_else(|| {
                refuse(
                    StatusCode::UNPROCESSABLE_ENTITY,
                    "invalid_cancellation_delivery",
                )
            })?;
        let call_kind = super::text(&body, "call_kind")?;
        // `validateBoundarySyntax`: only a parallel boundary spans more than
        // one sequence, at most a command batch of them.
        let span = match body.get("sequence_span").filter(|v| !v.is_null()) {
            None => 1,
            Some(value) => value
                .as_i64()
                .filter(|span| (1..=PARALLEL_SPAN_LIMIT).contains(span) && sequence.checked_add(*span).is_some())
                .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_cancellation_delivery"))?,
        };
        if !matches!(call_kind, "timer" | "child" | "parallel" | "condition" | "signal" | "activity")
            || (call_kind != "parallel" && span != 1)
            || body.get("operation_sequence").is_some_and(|v| !v.is_null())
            || body
                .get("operation_sequence_span")
                .filter(|v| !v.is_null())
                .is_some_and(|v| v.as_i64() != Some(1))
        {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "cancellation_delivery_boundary_not_available",
            ));
        }
        let mut tx = self.begin().await?;
        let task = self.task_row(&mut tx, task_id).await?;
        if DB::string(&task, "task_type")? != "workflow" {
            return Err(refuse(StatusCode::NOT_FOUND, "task_not_found"));
        }
        Self::fence(&task, &body)?;
        if !Self::claim_supports_cancellation(&task)? {
            return Err(refuse(
                StatusCode::CONFLICT,
                "active_claim_cancellation_not_supported",
            ));
        }
        let worker=Self::query("SELECT status,last_heartbeat_at FROM workflow_worker_registrations WHERE namespace=$1 AND worker_id=$2")
            .bind(DB::string(&task, "namespace")?).bind(super::text(&body,"lease_owner")?).fetch_optional(&mut *tx).await?
            .ok_or_else(|| refuse(StatusCode::CONFLICT,"stale_worker_registration"))?;
        if DB::string(&worker, "status")? != "active"
            || DB::instant(&worker, "last_heartbeat_at")? <= now() - chrono::Duration::seconds(60)
        {
            return Err(refuse(StatusCode::CONFLICT, "stale_worker_registration"));
        }
        let run_id = DB::string(&task, "workflow_run_id")?;
        let run = Self::active_run(&mut tx, &run_id).await?;
        if DB::optional_string(&run, "cancellation_request_command_id")?.as_deref()
            != Some(request_id)
        {
            return Err(refuse(
                StatusCode::CONFLICT,
                "cancellation_request_mismatch",
            ));
        }
        if DB::optional_instant(&run, "cancellation_deadline_at")?
            .is_none_or(|deadline| deadline <= now())
        {
            return Err(refuse(
                StatusCode::CONFLICT,
                "cancellation_deadline_expired",
            ));
        }
        let response = json!({"delivered":true,"task_id":task_id,"workflow_run_id":run_id,"request_id":request_id,
            "sequence":sequence,"call_kind":call_kind,"sequence_span":span,"operation_sequence":null,
            "operation_sequence_span":1,"reason":null});
        let requested=Self::query("SELECT sequence,payload FROM workflow_history_events WHERE workflow_run_id=$1 AND workflow_command_id=$2 AND event_type='CooperativeCancellationRequested' ORDER BY sequence LIMIT 1")
            .bind(&run_id).bind(request_id).fetch_optional(&mut *tx).await?
            .ok_or_else(|| refuse(StatusCode::CONFLICT,"cancellation_request_history_missing"))?;
        let request_sequence = DB::number(&requested, "sequence")?;
        let requested = DB::document_row(&requested, "payload")?;
        // `validateRootOperationMembership`: root delivery has no frozen
        // scoped inventory, so a scoped operation in its range needs scope
        // delivery.
        if let Some(reason) = Self::root_operation_membership(&mut tx, &run_id, sequence, span).await? {
            return Err(RuntimeError::Protocol {
                status: StatusCode::CONFLICT,
                response: super::cancellation_scopes::enveloped(json!({"delivered":false,"task_id":task_id,"workflow_run_id":run_id,
                    "request_id":null,"sequence":null,"call_kind":null,"sequence_span":null,"operation_sequence":null,
                    "operation_sequence_span":null,"reason":reason})),
            });
        }
        if requested["workflow_command_id"] != request_id
            || requested["cancellation"]["request_id"] != request_id
        {
            return Err(refuse(
                StatusCode::CONFLICT,
                "cancellation_request_history_missing",
            ));
        }
        if let Some(delivery)=Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='CooperativeCancellationDelivered' ORDER BY sequence LIMIT 1")
            .bind(&run_id).fetch_optional(&mut *tx).await? {
            let payload=DB::document_row(&delivery,"payload")?;
            if payload["workflow_command_id"]!=request_id || payload["sequence"]!=sequence || payload["call_kind"]!=call_kind
                || payload["sequence_span"].as_i64().unwrap_or(1)!=span
                || payload["cancellation"]!=requested["cancellation"] || DB::optional_number(&run,"cancellation_delivery_sequence")?!=Some(sequence) {
                return Err(refuse(StatusCode::CONFLICT,"cancellation_delivery_mismatch"));
            }
            Self::query("UPDATE workflow_tasks SET lease_expires_at=$1 WHERE id=$2")
                .bind(DB::bind_time(self.cancellation_lease_expiry(&run)?))
                .bind(task_id).execute(&mut *tx).await?;
            tx.commit().await?;
            return Ok(response);
        }
        if DB::optional_number(&run, "cancellation_delivery_sequence")?.is_some() {
            return Err(refuse(
                StatusCode::CONFLICT,
                "cancellation_delivery_history_missing",
            ));
        }
        let next = Self::authored_sequence(&mut tx, &run_id)
            .await?
            .checked_add(1)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "command_sequence_exhausted"))?;
        if sequence > next {
            return Err(refuse(
                StatusCode::CONFLICT,
                "cancellation_delivery_sequence_mismatch",
            ));
        }
        if call_kind == "parallel" {
            // `parallelEligibility`; a boundary at the next sequence has no
            // authored member yet.
            if sequence < next {
                for timer in Self::parallel_timer_members(&mut tx, &run_id, sequence, span, request_sequence).await? {
                    if DB::string(&timer, "status")? == "pending" {
                        Self::cancel_root_timer(&mut tx, &run, request_id, &timer, Some(task_id), now()).await?;
                    }
                }
            }
        } else if call_kind == "child" {
            if sequence < next {
                let boundary = Self::validate_child_cancellation_boundary(
                    &mut tx,
                    &run_id,
                    sequence,
                    request_sequence,
                )
                .await?;
                // `ChildCancellation::prepare`: a waiting policy holds the
                // delivery and releases the claim until the child closes.
                if boundary.policy != "abandon"
                    && Self::prepare_child_cancellation(&mut tx, &task, request_id, &requested["cancellation"], sequence, &boundary, codec).await?
                {
                    let at = now();
                    Self::query("UPDATE workflow_runs SET status='waiting',last_progress_at=$1 WHERE id=$2")
                        .bind(DB::bind_time(at)).bind(&run_id).execute(&mut *tx).await?;
                    Self::query("UPDATE workflow_tasks SET status='completed',lease_expires_at=NULL WHERE id=$1")
                        .bind(task_id).execute(&mut *tx).await?;
                    tx.summary_changed(&run_id);
                    tx.commit().await?;
                    self.wake.notify_waiters();
                    return Ok(json!({"delivered":false,"task_id":task_id,"workflow_run_id":run_id,"request_id":null,
                        "sequence":null,"call_kind":null,"sequence_span":null,"operation_sequence":null,
                        "operation_sequence_span":null,"reason":"cancellation_waiting_for_child","claim_released":true}));
                }
            }
        } else if call_kind == "activity" {
            // `cancelOpenWaitAtSequence` with the default `try_cancel`
            // policy: an open activity at the sequence is cancelled under the
            // request. Native records no explicit activity cancellation
            // policy, so it has no waiting or abandoning delivery.
            if sequence < next {
                Self::deliver_activity_cancellation(&mut tx, &run_id, request_id, sequence, now()).await?;
            }
        } else if matches!(call_kind, "condition" | "signal") {
            if sequence < next {
                Self::validate_wait_cancellation_boundary(&mut tx, &run_id, sequence, call_kind, request_sequence).await?;
            }
            // `cancelOpenWaitAtSequence`: a timed wait's pending timeout timer is
            // cancelled, as at a timer boundary.
            if let Some(timer) = Self::query("SELECT * FROM workflow_run_timers WHERE workflow_run_id=$1 AND sequence=$2")
                .bind(&run_id).bind(sequence).fetch_optional(&mut *tx).await?
                && DB::string(&timer, "status")? == "pending"
            {
                Self::cancel_root_timer(&mut tx, &run, request_id, &timer, Some(task_id), now()).await?;
            }
        } else {
            let timer = Self::query(
                "SELECT * FROM workflow_run_timers WHERE workflow_run_id=$1 AND sequence=$2",
            )
            .bind(&run_id)
            .bind(sequence)
            .fetch_optional(&mut *tx)
            .await?;
            if sequence < next && timer.is_none() {
                return Err(refuse(
                    StatusCode::CONFLICT,
                    "cancellation_delivery_shape_mismatch",
                ));
            }
            if let Some(timer) = timer {
                if DB::string(&timer, "status")? != "pending" {
                    return Err(refuse(
                        StatusCode::CONFLICT,
                        "cancellation_delivery_not_eligible",
                    ));
                }
                Self::cancel_root_timer(&mut tx, &run, request_id, &timer, Some(task_id), now())
                    .await?;
            }
        }
        let at = now();
        let expires = self.cancellation_lease_expiry(&run)?;
        Self::query("UPDATE workflow_tasks SET lease_expires_at=$1 WHERE id=$2")
            .bind(DB::bind_time(expires))
            .bind(task_id)
            .execute(&mut *tx)
            .await?;
        let mut payload = json!({"workflow_command_id":request_id,"workflow_run_id":run_id,"sequence":sequence,"call_kind":call_kind,
            "cancellation":requested["cancellation"],"task":{"id":task_id,"type":"workflow","status":"leased",
                "available_at":DB::optional_instant(&task,"available_at")?,"leased_at":DB::optional_instant(&task,"leased_at")?,
                "lease_owner":DB::optional_string(&task,"lease_owner")?,"lease_expires_at":expires,
                "attempt_count":DB::number(&task,"attempt_count")?}});
        // PHP records `sequence_span` only beyond one sequence.
        if span != 1 {
            payload["sequence_span"] = json!(span);
        }
        Self::query("UPDATE workflow_runs SET cancellation_delivery_sequence=$1,cancellation_delivered_at=$2,last_progress_at=$3 WHERE id=$4")
            .bind(sequence).bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(&run_id).execute(&mut *tx).await?;
        // PHP records the delivery with the request's id, not its command,
        // so it carries no command snapshot or principal.
        Self::append_by_command_id(
            &mut tx,
            &run_id,
            "CooperativeCancellationDelivered",
            payload,
            Some(task_id),
            request_id,
        )
        .await?;
        tx.commit().await?;
        Ok(response)
    }

    /// The pending timers of the parallel group a delivery names. Like
    /// PHP's `parallelEligibility`, every member must belong to the group
    /// at its index, and a group whose members all resolved before the
    /// request is not eligible. Native cancels timer members only; a group
    /// with another kind of member keeps its earlier refusal.
    /// `ActivityCancellation::record` for a cooperative request: the open
    /// execution at `sequence`, its current attempt and that attempt's own
    /// task are cancelled, and `ActivityCancelled` names the request. PHP
    /// 2.11.1 picks the execution's first task here, which for a retried
    /// activity is an earlier, closed one (Workflow #923); native cancels the
    /// live attempt's task, so its worker can acknowledge the stop.
    async fn deliver_activity_cancellation(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        request_id: &str,
        sequence: i64,
        at: DateTime<Utc>,
    ) -> Result<()> {
        let Some(activity) = Self::query("SELECT * FROM activity_executions WHERE workflow_run_id=$1 AND sequence=$2 AND status IN ('pending','running')")
            .bind(run_id).bind(sequence).fetch_optional(&mut **tx).await?
        else {
            return Ok(());
        };
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
            let live = DB::string(&attempt, "workflow_task_id")?;
            // The task keeps its owner and attempt count, as PHP's does.
            Self::query("UPDATE workflow_tasks SET status='cancelled',lease_expires_at=NULL,last_error=NULL WHERE id=$1 AND workflow_run_id=$2 AND status IN ('ready','leased')")
                .bind(&live).bind(run_id).execute(&mut **tx).await?;
            attempt_snapshot = json!({"id":attempt_id,"activity_execution_id":activity_id,"task_id":live,
                "attempt_number":DB::number(&attempt,"attempt_number")?,"status":"cancelled",
                "lease_owner":DB::optional_string(&attempt,"lease_owner")?,
                "started_at":DB::optional_instant(&attempt,"started_at")?,"closed_at":DB::optional_instant(&attempt,"closed_at")?});
            task_id = Some(live);
        }
        let cancelled = Self::query("SELECT * FROM activity_executions WHERE id=$1")
            .bind(&activity_id).fetch_one(&mut **tx).await?;
        let mut payload = Self::activity_payload(&cancelled)?;
        payload["workflow_command_id"] = json!(request_id);
        payload["activity_attempt_id"] = json!(attempt_id);
        payload["attempt_number"] = json!(DB::number(&cancelled, "attempt_count")?);
        payload["cancelled_at"] = json!(DB::optional_instant(&cancelled, "closed_at")?);
        payload["activity_attempt"] = attempt_snapshot;
        if let Some(task_id) = &task_id {
            let task = Self::query("SELECT * FROM workflow_tasks WHERE id=$1").bind(task_id).fetch_one(&mut **tx).await?;
            payload["task"] = Self::task_snapshot(&task)?;
        }
        // Recorded with the request's id, as the delivery itself is.
        Self::append_by_command_id(tx, run_id, "ActivityCancelled", payload, task_id.as_deref(), request_id).await?;
        tx.summary_changed(run_id);
        Ok(())
    }

    async fn parallel_timer_members(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        sequence: i64,
        span: i64,
        request_sequence: i64,
    ) -> Result<Vec<DB::Row>> {
        let mismatch = || refuse(StatusCode::CONFLICT, "cancellation_delivery_shape_mismatch");
        let unavailable = || refuse(StatusCode::UNPROCESSABLE_ENTITY, "cancellation_delivery_boundary_not_available");
        let events = Self::query("SELECT sequence,event_type,payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type IN ('TimerScheduled','TimerFired','TimerCancelled','ActivityScheduled','ChildWorkflowScheduled','ConditionWaitOpened','SignalWaitOpened') ORDER BY sequence LIMIT 10001")
            .bind(run_id)
            .fetch_all(&mut **tx)
            .await?;
        if events.len() > 10000 {
            return Err(refuse(StatusCode::SERVICE_UNAVAILABLE, "cancellation_inventory_exceeds_budget"));
        }
        let mut members = Vec::new();
        let mut group_id = None;
        let mut all_resolved = true;
        for offset in 0..span {
            let member = sequence + offset;
            let mut scheduled = None;
            let mut resolved_before_request = false;
            for event in &events {
                let payload = DB::document_row(event, "payload")?;
                if payload["sequence"].as_i64() != Some(member) {
                    continue;
                }
                match DB::string(event, "event_type")?.as_str() {
                    "TimerScheduled" => scheduled = Some(payload),
                    "TimerFired" | "TimerCancelled" => {
                        resolved_before_request |= DB::number(event, "sequence")? < request_sequence;
                    }
                    _ => return Err(unavailable()),
                }
            }
            let scheduled = scheduled.ok_or_else(mismatch)?;
            let path = scheduled["parallel_group_path"].as_array().ok_or_else(mismatch)?;
            let group = path
                .iter()
                .find(|group| {
                    group["parallel_group_base_sequence"].as_i64() == Some(sequence)
                        && group["parallel_group_size"].as_i64() == Some(span)
                })
                .ok_or_else(mismatch)?;
            if group["parallel_group_index"].as_i64() != Some(offset)
                || group_id.get_or_insert_with(|| group["parallel_group_id"].clone()) != &group["parallel_group_id"]
            {
                return Err(mismatch());
            }
            all_resolved &= resolved_before_request;
            let timer = Self::query("SELECT * FROM workflow_run_timers WHERE workflow_run_id=$1 AND sequence=$2")
                .bind(run_id)
                .bind(member)
                .fetch_optional(&mut **tx)
                .await?
                .ok_or_else(mismatch)?;
            members.push(timer);
        }
        if all_resolved {
            return Err(refuse(StatusCode::CONFLICT, "cancellation_delivery_not_eligible"));
        }
        Ok(members)
    }

    /// `validateCallBoundary` for a condition or signal wait: the authored
    /// step at the sequence must be that wait (a timed wait's timeout timer
    /// belongs to it), and it must not have resolved before the request.
    async fn validate_wait_cancellation_boundary(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        sequence: i64,
        call_kind: &str,
        request_sequence: i64,
    ) -> Result<()> {
        let (opened, timeout_kind) = if call_kind == "condition" {
            ("ConditionWaitOpened", "condition_timeout")
        } else {
            ("SignalWaitOpened", "signal_timeout")
        };
        let mismatch = || refuse(StatusCode::CONFLICT, "cancellation_delivery_shape_mismatch");
        let events = Self::query("SELECT sequence,event_type,payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type IN ('TimerScheduled','ActivityScheduled','ChildWorkflowScheduled','ConditionWaitOpened','SignalWaitOpened','VersionMarkerRecorded','SideEffectRecorded','ConditionWaitSatisfied','ConditionWaitTimedOut','SignalApplied','TimerFired','TimerCancelled') ORDER BY sequence LIMIT 10001")
            .bind(run_id)
            .fetch_all(&mut **tx)
            .await?;
        if events.len() > 10000 {
            return Err(refuse(StatusCode::SERVICE_UNAVAILABLE, "cancellation_inventory_exceeds_budget"));
        }
        let mut found = false;
        for event in &events {
            let payload = DB::document_row(event, "payload")?;
            if payload["sequence"].as_i64() != Some(sequence) {
                continue;
            }
            match DB::string(event, "event_type")?.as_str() {
                kind if kind == opened => found = true,
                "TimerScheduled" if payload["timer_kind"] == timeout_kind => {}
                "ConditionWaitSatisfied" | "ConditionWaitTimedOut" | "SignalApplied" | "TimerFired" | "TimerCancelled" => {
                    if DB::number(event, "sequence")? < request_sequence {
                        return Err(refuse(StatusCode::CONFLICT, "cancellation_delivery_not_eligible"));
                    }
                }
                _ => return Err(mismatch()),
            }
        }
        if found { Ok(()) } else { Err(mismatch()) }
    }

    async fn validate_child_cancellation_boundary(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        sequence: i64,
        request_sequence: i64,
    ) -> Result<super::child_cancellation::ChildBoundary> {
        let mismatch = || refuse(StatusCode::CONFLICT, "cancellation_delivery_shape_mismatch");
        let links = Self::query(
            "SELECT * FROM workflow_links WHERE parent_workflow_run_id=$1 AND sequence=$2 LIMIT 2",
        )
        .bind(run_id)
        .bind(sequence)
        .fetch_all(&mut **tx)
        .await?;
        let link = links
            .first()
            .filter(|_| links.len() == 1)
            .ok_or_else(mismatch)?;
        let (parent_close, cancellation) = Self::child_link_policies(tx, link).await?.ok_or_else(mismatch)?;
        let call = Self::query("SELECT child_workflow_type,child_workflow_class FROM workflow_child_calls WHERE parent_workflow_run_id=$1 AND sequence=$2")
            .bind(run_id).bind(sequence).fetch_one(&mut **tx).await?;
        let events = Self::query("SELECT sequence,event_type,payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type IN ('ChildWorkflowScheduled','ChildRunStarted','ChildRunCompleted','ChildRunFailed','ChildRunCancelled','ChildRunTerminated') ORDER BY sequence LIMIT 1001")
            .bind(run_id).fetch_all(&mut **tx).await?;
        if events.len() > 1000 {
            return Err(refuse(
                StatusCode::SERVICE_UNAVAILABLE,
                "cancellation_inventory_exceeds_budget",
            ));
        }
        let mut scheduled = false;
        let mut started = false;
        let mut resolved_before_request = false;
        for event in events {
            let payload = DB::document_row(&event, "payload")?;
            if payload["sequence"] != sequence {
                continue;
            }
            let kind = DB::string(&event, "event_type")?;
            if matches!(kind.as_str(), "ChildWorkflowScheduled" | "ChildRunStarted") {
                if payload["workflow_link_id"] != DB::string(link, "id")?
                    || payload["child_call_id"] != DB::string(link, "id")?
                    || payload["child_workflow_instance_id"]
                        != DB::string(link, "child_workflow_instance_id")?
                    || payload["child_workflow_run_id"]
                        != DB::string(link, "child_workflow_run_id")?
                    || payload["child_workflow_type"] != DB::string(&call, "child_workflow_type")?
                    || payload["child_workflow_class"] != DB::string(&call, "child_workflow_class")?
                    || payload["parent_close_policy"] != parent_close.as_str()
                    || payload["cancellation_policy"] != cancellation.as_str()
                    || payload.get("child_workflow").is_some()
                {
                    return Err(mismatch());
                }
                if payload
                    .get("cancellation_scope_id")
                    .is_some_and(|scope| scope != "root")
                {
                    return Err(refuse(
                        StatusCode::CONFLICT,
                        "cancellation_delivery_requires_scope",
                    ));
                }
                let seen = if kind == "ChildWorkflowScheduled" {
                    &mut scheduled
                } else {
                    &mut started
                };
                if *seen {
                    return Err(mismatch());
                }
                *seen = true;
            } else if DB::number(&event, "sequence")? < request_sequence {
                resolved_before_request = true;
            }
        }
        if !scheduled || !started {
            return Err(mismatch());
        }
        if resolved_before_request {
            return Err(refuse(
                StatusCode::CONFLICT,
                "cancellation_delivery_not_eligible",
            ));
        }
        Ok(super::child_cancellation::ChildBoundary {
            child_instance: DB::string(link, "child_workflow_instance_id")?,
            child_run: DB::string(link, "child_workflow_run_id")?,
            policy: cancellation,
        })
    }

    async fn root_operation_membership(tx: &mut Transaction<'_, DB>, run_id: &str, start: i64, span: i64) -> Result<Option<&'static str>> {
        for event in Self::query("SELECT event_type,payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type IN ('ActivityScheduled','TimerScheduled','ChildWorkflowScheduled','ServiceCallStarted','ConditionWaitOpened','SignalWaitOpened') ORDER BY sequence")
            .bind(run_id).fetch_all(&mut **tx).await?
        {
            let payload = DB::document_row(&event, "payload")?;
            let Some(sequence) = payload["sequence"].as_i64() else {
                continue;
            };
            if sequence < start || sequence - start >= span {
                continue;
            }
            let descriptor = match DB::string(&event, "event_type")?.as_str() {
                "ActivityScheduled" => payload.get("activity"),
                "TimerScheduled" => payload.get("timer"),
                "ChildWorkflowScheduled" => payload.get("child_workflow"),
                "ServiceCallStarted" => payload.get("service_operation"),
                _ => None,
            };
            if descriptor.is_some_and(|descriptor| !descriptor.is_object() && descriptor.as_array().is_none_or(|items| !items.is_empty())) {
                return Ok(Some("cancellation_delivery_scope_membership_invalid"));
            }
            let nested = descriptor.and_then(|descriptor| descriptor.get("cancellation_scope_id"));
            let scope = match (payload.get("cancellation_scope_id"), nested) {
                (Some(flat), _) => flat,
                (None, Some(nested)) => nested,
                (None, None) => continue,
            };
            let Some(scope) = scope.as_str().filter(|scope| !scope.is_empty()) else {
                return Ok(Some("cancellation_delivery_scope_membership_invalid"));
            };
            if nested.is_some_and(|nested| nested != scope) {
                return Ok(Some("cancellation_delivery_scope_membership_invalid"));
            }
            if scope != super::cancellation_scopes::ROOT {
                return Ok(Some("cancellation_delivery_requires_scope"));
            }
        }
        Ok(None)
    }

    async fn cancel_root_timer(
        tx: &mut Transaction<'_, DB>,
        run: &DB::Row,
        request_id: &str,
        timer: &DB::Row,
        task_id: Option<&str>,
        at: DateTime<Utc>,
    ) -> Result<()> {
        let run_id = DB::string(run, "id")?;
        let namespace = DB::string(run, "namespace")?;
        let timer_id = DB::string(timer, "id")?;
        Self::query("UPDATE workflow_run_timers SET status='cancelled' WHERE id=$1")
            .bind(&timer_id)
            .execute(&mut **tx)
            .await?;
        for timer_task in Self::query(DB::TIMER_TASK_SQL)
            .bind(&run_id)
            .bind(&timer_id)
            .bind(&namespace)
            .fetch_all(&mut **tx)
            .await?
        {
            Self::query(
                "UPDATE workflow_tasks SET status='cancelled',lease_expires_at=NULL WHERE id=$1",
            )
            .bind(DB::string(&timer_task, "id")?)
            .execute(&mut **tx)
            .await?;
        }
        let payload = Self::timer_cancellation_payload(tx, &run_id, timer, at).await?;
        Self::append_by_command_id(tx, &run_id, "TimerCancelled", payload, task_id, request_id).await
    }

    pub(super) async fn finish_root_cancellation(
        tx: &mut Transaction<'_, DB>,
        run: &DB::Row,
        task: Option<&DB::Row>,
    ) -> Result<()> {
        let run_id = DB::string(run, "id")?;
        let task_id = task.map(|task| DB::string(task, "id")).transpose()?;
        let task_id = task_id.as_deref();
        let request_id = DB::optional_string(run, "cancellation_request_command_id")?
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "cancellation_request_missing"))?;
        let deadline = DB::optional_instant(run, "cancellation_deadline_at")?
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "cancellation_deadline_missing"))?;
        let activities=Self::query("SELECT * FROM activity_executions WHERE workflow_run_id=$1 AND status IN ('pending','running') ORDER BY id LIMIT 1001")
            .bind(&run_id).fetch_all(&mut **tx).await?;
        let timers=Self::query("SELECT * FROM workflow_run_timers WHERE workflow_run_id=$1 AND status='pending' ORDER BY id LIMIT 1001")
            .bind(&run_id).fetch_all(&mut **tx).await?;
        if activities.len() > 1000 || timers.len() > 1000 {
            return Err(refuse(
                StatusCode::SERVICE_UNAVAILABLE,
                "cancellation_inventory_exceeds_budget",
            ));
        }
        let delivery=Self::query("SELECT id,workflow_command_id,payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='CooperativeCancellationDelivered' ORDER BY sequence LIMIT 1")
            .bind(&run_id).fetch_optional(&mut **tx).await?;
        let mut delivery_id = None;
        let mut delivery_sequence = None;
        if let Some(delivery) = delivery {
            let payload = DB::document_row(&delivery, "payload")?;
            let sequence = payload["sequence"].as_i64();
            if DB::optional_string(&delivery, "workflow_command_id")?.as_deref()
                == Some(request_id.as_str())
                && payload["workflow_command_id"] == request_id
                && sequence.is_some()
                && sequence == DB::optional_number(run, "cancellation_delivery_sequence")?
            {
                delivery_id = Some(DB::string(&delivery, "id")?);
                delivery_sequence = sequence;
            }
        }
        let at = now();
        let expired = at >= deadline;
        let outcome = if expired {
            "deadline_expired"
        } else if delivery_id.is_some() {
            "completed"
        } else if DB::optional_number(run, "cancellation_delivery_sequence")?.is_none() {
            "not_delivered"
        } else {
            "unavailable"
        };
        let reason = if expired {
            "Cooperative cancellation cleanup deadline expired."
        } else {
            "Cooperative cancellation completed."
        };
        Self::query("UPDATE workflow_tasks SET status='cancelled',lease_expires_at=NULL,last_error=NULL WHERE workflow_run_id=$1 AND status IN ('ready','leased')")
            .bind(&run_id).execute(&mut **tx).await?;
        Self::query("UPDATE workflow_tasks SET lease_expires_at=NULL WHERE workflow_run_id=$1 AND status='completed'")
            .bind(&run_id).execute(&mut **tx).await?;
        Self::query("UPDATE activity_attempts SET lease_expires_at=NULL WHERE workflow_run_id=$1 AND status='completed'")
            .bind(&run_id).execute(&mut **tx).await?;
        Self::cancel_open_activities(tx, &run_id, Some(&request_id), activities, at).await?;
        for timer in timers {
            Self::cancel_root_timer(tx, run, &request_id, &timer, task_id, at).await?;
        }
        let failure_id = id();
        let message = format!("Workflow cancelled: {reason}");
        const EXCEPTION: &str = "Workflow\\V2\\Exceptions\\WorkflowCancelledException";
        Self::query("INSERT INTO workflow_failures(id,workflow_run_id,source_kind,source_id,propagation_kind,failure_category,handled,exception_class,message,file,line,trace_preview,created_at,updated_at) VALUES ($1,$2,'workflow_run',$3,'cancelled','cancelled',FALSE,$4,$5,'',0,'',$6,$7)")
            .bind(&failure_id).bind(&run_id).bind(&run_id).bind(EXCEPTION).bind(&message)
            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).execute(&mut **tx).await?;
        Self::query("UPDATE workflow_runs SET status='cancelled',closed_reason='cancelled',output=NULL,closed_at=$1,last_progress_at=$2 WHERE id=$3")
            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(&run_id).execute(&mut **tx).await?;
        let terminal = json!({"workflow_command_id":request_id,
            "workflow_instance_id":DB::string(run,"workflow_instance_id")?,"workflow_run_id":run_id,"failure_id":failure_id,
            "failure_category":"cancelled","closed_reason":"cancelled","exception_class":EXCEPTION,"message":message,"reason":reason,
            "cancellation_cleanup":{"request_id":request_id,"outcome":outcome,"cleanup_deadline_at":timestamp(deadline),
                "delivery_history_event_id":delivery_id,"delivery_sequence":delivery_sequence,"finished_at":timestamp(at)}});
        Self::append_by_command_id(tx, &run_id, "WorkflowCancelled", terminal.clone(), task_id, &request_id).await?;
        Self::close_pending_updates(tx, &run_id, task).await?;
        Self::enforce_parent_close_policies(tx, run, "cancelled", 0).await?;
        Self::resolve_child(
            tx,
            run,
            super::children::ChildResolution::Cancelled(&terminal),
            at,
        )
        .await
    }

    pub(super) async fn expire_cooperative_deadlines(&self) -> Result<()> {
        const EXPIRED: &str = "SELECT * FROM workflow_runs WHERE status IN ('pending','running','waiting') AND cancellation_request_command_id IS NOT NULL AND cancellation_deadline_at<=$1 ORDER BY cancellation_deadline_at,id LIMIT 50";
        if Self::query(EXPIRED)
            .bind(DB::bind_time(now()))
            .fetch_optional(&self.pool)
            .await?
            .is_none()
        {
            return Ok(());
        }
        let mut tx = self.begin().await?;
        for run in Self::query(EXPIRED)
            .bind(DB::bind_time(now()))
            .fetch_all(&mut *tx)
            .await?
        {
            if DB::optional_instant(&run, "cancellation_deadline_at")?
                .is_some_and(|deadline| deadline <= now())
            {
                Self::finish_root_cancellation(&mut tx, &run, None).await?;
                tx.summary_changed(&DB::string(&run, "id")?);
            }
        }
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(())
    }

    /// `CooperativeCancellationPolicy::resumeUndeliveredRequest`: after a
    /// cooperative claim completes, an undelivered request gets a workflow
    /// task when none is open. The new task is reported with the tasks the
    /// completion created.
    pub(super) async fn resume_undelivered_cancellation(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        task: &DB::Row,
        queue: &str,
    ) -> Result<Option<String>> {
        if !Self::claim_supports_cancellation(task)? {
            return Ok(None);
        }
        let run = Self::query("SELECT * FROM workflow_runs WHERE id=$1")
            .bind(run_id)
            .fetch_one(&mut **tx)
            .await?;
        if !matches!(
            DB::string(&run, "status")?.as_str(),
            "pending" | "running" | "waiting"
        ) || DB::optional_number(&run, "cancellation_delivery_sequence")?.is_some()
            || DB::optional_instant(&run, "cancellation_deadline_at")?
                .is_none_or(|deadline| deadline <= now())
        {
            return Ok(None);
        }
        let Some(request_id) = DB::optional_string(&run, "cancellation_request_command_id")? else {
            return Ok(None);
        };
        if Self::scalar("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status IN ('ready','leased')")
            .bind(run_id).fetch_one(&mut **tx).await?==0 {
            return Self::create_task(tx,run_id,queue,"workflow",json!({"resume_source_kind":"cancellation_request",
                "resume_source_id":request_id,"workflow_command_id":request_id})).await.map(Some);
        }
        Ok(None)
    }

    pub(crate) async fn request_cancellation(
        &self,
        workflow_id: &str,
        selected_run: Option<&str>,
        reason: Option<String>,
        budget: i64,
        codec: Arc<Semaphore>,
        context: Option<Value>,
    ) -> Result<(StatusCode, Value)> {
        let mut tx = self.begin().await?;
        let instance = Self::query(
            "SELECT current_run_id FROM workflow_instances WHERE id=$1 AND namespace=$2",
        )
        .bind(workflow_id)
        .bind(self.namespace.as_ref())
        .fetch_optional(&mut *tx)
        .await?
        .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "instance_not_found"))?;
        let current = DB::string(&instance, "current_run_id")?;
        let run_id = selected_run.unwrap_or(&current);
        let run = Self::query("SELECT * FROM workflow_runs WHERE id=$1 AND workflow_instance_id=$2 AND namespace=$3")
            .bind(run_id).bind(workflow_id).bind(self.namespace.as_ref()).fetch_optional(&mut *tx).await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND,"run_not_found"))?;
        if run_id != current {
            return Err(refuse(StatusCode::CONFLICT, "selected_run_not_current"));
        }
        let run_status = DB::string(&run, "status")?;
        let response = |duplicate: bool, pending: Value| {
            json!({"workflow_id":workflow_id,"run_id":run_id,
            "accepted":true,"duplicate":duplicate,"run_status":run_status,
            "cancellation_request":pending})
        };
        // Read the original durable receipt before checking terminal state or
        // current registrations. A duplicate never creates a new budget.
        if let Some(pending) = Self::cancellation_request(&run)? {
            return Ok((StatusCode::OK, response(true, pending)));
        }
        if !matches!(
            DB::string(&run, "status")?.as_str(),
            "pending" | "running" | "waiting"
        ) {
            return Err(refuse(StatusCode::CONFLICT, "run_not_active"));
        }
        let claims = Self::query("SELECT * FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status='leased' AND lease_expires_at>$2 ORDER BY id LIMIT 1001")
            .bind(run_id).bind(DB::bind_time(now())).fetch_all(&mut *tx).await?;
        if claims.len() > 1000 {
            return Err(refuse(
                StatusCode::SERVICE_UNAVAILABLE,
                "cancellation_inventory_exceeds_budget",
            ));
        }
        for claim in claims {
            if !Self::claim_supports_cancellation(&claim)? {
                return Err(RuntimeError::Protocol {
                    status: StatusCode::CONFLICT,
                    response: json!({
                    "reason":"active_claim_cancellation_not_supported","task_id":DB::string(&claim,"id")?,
                    "workflow_task_attempt":DB::number(&claim,"attempt_count")?}),
                });
            }
        }
        let children = Self::query("SELECT * FROM workflow_links WHERE parent_workflow_run_id=$1 OR child_workflow_run_id=$2 ORDER BY id LIMIT 1001")
            .bind(run_id).bind(run_id).fetch_all(&mut *tx).await?;
        if children.len() > 1000 {
            return Err(refuse(
                StatusCode::SERVICE_UNAVAILABLE,
                "cancellation_inventory_exceeds_budget",
            ));
        }
        let mut unsupported_child = false;
        for child in children {
            if DB::string(&child, "parent_workflow_run_id")?
                == DB::string(&child, "child_workflow_run_id")?
                || Self::child_link_policies(&mut tx, &child).await?.is_none()
            {
                unsupported_child = true;
                break;
            }
        }
        // Open activities no longer block a request: the workflow delivers it
        // to each activity it awaits (`call_kind: activity`), as PHP's does.
        if unsupported_child {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "cooperative_cancellation_propagation_not_available",
            ));
        }
        let request_id = id();
        let at = now();
        let deadline = at + chrono::Duration::seconds(budget);
        // The authenticated request's context; a direct call records the
        // legacy administrator.
        let command_context = context.unwrap_or_else(|| json!({"caller":{"type":"server","label":"Standalone Server"},
            "auth":{"status":"authorized","method":"token","role":"admin"},
            "principal":{"type":"auth:token","id":"legacy-token","label":"Admin"}}));
        // `WorkflowStub`: the requester is the context's principal, or else
        // its caller.
        let source = if command_context["principal"].is_object() { &command_context["principal"] } else { &command_context["caller"] };
        let mut requester = serde_json::Map::new();
        for field in ["type", "id", "label"] {
            if let Some(value) = source.get(field) {
                requester.insert(field.into(), value.clone());
            }
        }
        let requester = Value::Object(requester);
        let context = json!({"schema":"durable-workflow.cancellation-context/v1", "request_id":request_id,
            "root_request_id":request_id,"root_workflow_instance_id":workflow_id,"root_workflow_run_id":run_id,
            "parent_request_id":null,"reason":reason,"requester":requester,"source":"control_plane",
            "requested_at":timestamp(at),"cleanup_deadline_at":timestamp(deadline),
            "lineage":[{"request_id":request_id,"workflow_instance_id":workflow_id,"workflow_run_id":run_id}]});
        let blob = encode_command(json!({"reason":reason,"cleanup_deadline_at":timestamp(deadline),"cancellation":context}),codec).await?;
        let sequence = DB::number(&run, "last_command_sequence")?
            .checked_add(1)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "command_sequence_exhausted"))?;
        let target_scope = if selected_run.is_some() {
            "run"
        } else {
            "instance"
        };
        Self::query("INSERT INTO workflow_commands(id,workflow_instance_id,workflow_run_id,requested_workflow_run_id,resolved_workflow_run_id,command_type,target_scope,source,context,status,outcome,workflow_type,workflow_class,payload_codec,payload,command_sequence,accepted_at,applied_at) VALUES ($1,$2,$3,$4,$5,'request_cancellation',$6,'control_plane',$7,'accepted','cancellation_requested',$8,$9,'avro',$10,$11,$12,$13)")
            .bind(&request_id).bind(workflow_id).bind(run_id).bind(selected_run).bind(run_id).bind(target_scope).bind(DB::document(&command_context))
            .bind(DB::string(&run,"workflow_type")?).bind(DB::string(&run,"workflow_class")?).bind(&blob).bind(sequence)
            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).execute(&mut *tx).await?;
        Self::query("UPDATE workflow_runs SET cancellation_request_command_id=$1,cancellation_requested_at=$2,cancellation_deadline_at=$3,last_progress_at=$4,last_command_sequence=$5 WHERE id=$6")
            .bind(&request_id).bind(DB::bind_time(at)).bind(DB::bind_time(deadline)).bind(DB::bind_time(at)).bind(sequence).bind(run_id).execute(&mut *tx).await?;
        let mut payload = json!({"workflow_command_id":request_id,"workflow_instance_id":workflow_id,"workflow_run_id":run_id,
            "command_type":"request_cancellation","cleanup_deadline_at":timestamp(deadline),"cancellation":context});
        if let Some(reason) = reason {
            payload["reason"] = json!(reason);
        }
        Self::append(
            &mut tx,
            run_id,
            "CooperativeCancellationRequested",
            payload,
            None,
            Some(&request_id),
        )
        .await?;
        // The unshielded scope subtree is requested in the same transaction;
        // the first request leaves a scope-request task when none is open.
        self.request_scope_descendants(&mut tx, run_id, super::cancellation_scopes::ROOT).await?;
        let open_tasks = Self::scalar("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status IN ('ready','leased')")
            .bind(run_id).fetch_one(&mut *tx).await?;
        if open_tasks == 0 {
            Self::create_task(&mut tx,run_id,&DB::string(&run,"queue")?,"workflow",json!({
                "resume_source_kind":"cancellation_request","resume_source_id":request_id,"workflow_command_id":request_id})).await?;
        }
        let refreshed = Self::query("SELECT * FROM workflow_runs WHERE id=$1")
            .bind(run_id)
            .fetch_one(&mut *tx)
            .await?;
        let mut receipt = response(
            false,
            Self::cancellation_request(&refreshed)?.expect("authored request"),
        );
        receipt["reason"] = Value::Null;
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok((StatusCode::ACCEPTED, receipt))
    }
}
