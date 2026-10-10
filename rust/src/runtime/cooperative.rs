//! Root cancellation keeps one durable request, budget and claim-time proof.
//! Unsupported propagation remains explicit while the wider corpus is pending.
use super::{
    Result, RuntimeError,
    backend::Backend,
    refuse,
    store::{Store, id, now, reject_fields},
    wire,
};
use axum::http::StatusCode;
use chrono::{DateTime, SecondsFormat, Utc};
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Transaction, Type};
use std::{collections::BTreeMap, sync::Arc};
use tokio::sync::Semaphore;

pub(super) const CLAIM_KEY: &str = "_server_workflow_claim";

pub(super) fn validate_request(body: &Value) -> Result<(Option<String>, i64)> {
    reject_fields(body, &["reason", "cleanup_timeout_seconds"])?;
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

fn timestamp(at: DateTime<Utc>) -> String {
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
async fn encode_command(value: Value, semaphore: Arc<Semaphore>) -> Result<String> {
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
    pub(super) fn cancellation_lease_expiry(run: &DB::Row) -> Result<DateTime<Utc>> {
        if DB::optional_string(run, "cancellation_request_command_id")?.is_some() {
            let deadline = DB::optional_instant(run, "cancellation_deadline_at")?
                .ok_or_else(|| refuse(StatusCode::CONFLICT, "cancellation_deadline_missing"))?;
            Ok(deadline.min(now() + chrono::Duration::seconds(10)))
        } else {
            Ok(now() + chrono::Duration::seconds(30))
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
        if call_kind != "timer"
            || body
                .get("sequence_span")
                .filter(|v| !v.is_null())
                .is_some_and(|v| v.as_i64() != Some(1))
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
        let worker=Self::query("SELECT status,last_heartbeat_at FROM workflow_worker_registrations WHERE namespace='default' AND worker_id=$1")
            .bind(super::text(&body,"lease_owner")?).fetch_optional(&mut *tx).await?
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
            "sequence":sequence,"call_kind":call_kind,"sequence_span":1,"operation_sequence":null,
            "operation_sequence_span":1,"reason":null});
        let requested=Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND workflow_command_id=$2 AND event_type='CooperativeCancellationRequested' ORDER BY sequence LIMIT 1")
            .bind(&run_id).bind(request_id).fetch_optional(&mut *tx).await?
            .ok_or_else(|| refuse(StatusCode::CONFLICT,"cancellation_request_history_missing"))?;
        let requested = DB::document_row(&requested, "payload")?;
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
                || payload["cancellation"]!=requested["cancellation"] || DB::optional_number(&run,"cancellation_delivery_sequence")?!=Some(sequence) {
                return Err(refuse(StatusCode::CONFLICT,"cancellation_delivery_mismatch"));
            }
            Self::query("UPDATE workflow_tasks SET lease_expires_at=$1 WHERE id=$2")
                .bind(DB::bind_time(Self::cancellation_lease_expiry(&run)?))
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
            Self::cancel_root_timer(&mut tx, &run_id, request_id, &timer, Some(task_id), now())
                .await?;
        }
        let at = now();
        let expires = Self::cancellation_lease_expiry(&run)?;
        Self::query("UPDATE workflow_tasks SET lease_expires_at=$1 WHERE id=$2")
            .bind(DB::bind_time(expires))
            .bind(task_id)
            .execute(&mut *tx)
            .await?;
        let payload = json!({"workflow_command_id":request_id,"workflow_run_id":run_id,"sequence":sequence,"call_kind":call_kind,
            "cancellation":requested["cancellation"],"task":{"id":task_id,"type":"workflow","status":"leased",
                "available_at":DB::optional_instant(&task,"available_at")?,"leased_at":DB::optional_instant(&task,"leased_at")?,
                "lease_owner":DB::optional_string(&task,"lease_owner")?,"lease_expires_at":expires,
                "attempt_count":DB::number(&task,"attempt_count")?}});
        Self::query("UPDATE workflow_runs SET cancellation_delivery_sequence=$1,cancellation_delivered_at=$2,last_progress_at=$3 WHERE id=$4")
            .bind(sequence).bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(&run_id).execute(&mut *tx).await?;
        Self::append(
            &mut tx,
            &run_id,
            "CooperativeCancellationDelivered",
            payload,
            Some(task_id),
            Some(request_id),
        )
        .await?;
        tx.commit().await?;
        Ok(response)
    }

    async fn cancel_root_timer(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        request_id: &str,
        timer: &DB::Row,
        task_id: Option<&str>,
        at: DateTime<Utc>,
    ) -> Result<()> {
        let timer_id = DB::string(timer, "id")?;
        Self::query("UPDATE workflow_run_timers SET status='cancelled' WHERE id=$1")
            .bind(&timer_id)
            .execute(&mut **tx)
            .await?;
        for timer_task in Self::query(DB::TIMER_TASK_SQL)
            .bind(run_id)
            .bind(&timer_id)
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
        Self::append(tx,run_id,"TimerCancelled",json!({"timer_id":timer_id,"sequence":DB::number(timer,"sequence")?,
            "delay_seconds":DB::number(timer,"delay_seconds")?,"fire_at":DB::instant(timer,"fire_at")?,"cancelled_at":at}),task_id,Some(request_id)).await
    }

    pub(super) async fn finish_root_cancellation(
        tx: &mut Transaction<'_, DB>,
        run: &DB::Row,
        task_id: Option<&str>,
    ) -> Result<()> {
        let run_id = DB::string(run, "id")?;
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
        Self::cancel_open_activities(tx, &run_id, &request_id, activities, at).await?;
        for timer in timers {
            Self::cancel_root_timer(tx, &run_id, &request_id, &timer, task_id, at).await?;
        }
        let failure_id = id();
        let message = format!("Workflow cancelled: {reason}");
        const EXCEPTION: &str = "Workflow\\V2\\Exceptions\\WorkflowCancelledException";
        Self::query("INSERT INTO workflow_failures(id,workflow_run_id,source_kind,source_id,propagation_kind,failure_category,handled,exception_class,message,file,line,trace_preview,created_at,updated_at) VALUES ($1,$2,'workflow_run',$3,'cancelled','cancelled',FALSE,$4,$5,'',0,'',$6,$7)")
            .bind(&failure_id).bind(&run_id).bind(&run_id).bind(EXCEPTION).bind(&message)
            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).execute(&mut **tx).await?;
        Self::query("UPDATE workflow_runs SET status='cancelled',closed_reason='cancelled',output=NULL,closed_at=$1,last_progress_at=$2 WHERE id=$3")
            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(&run_id).execute(&mut **tx).await?;
        Self::append(tx,&run_id,"WorkflowCancelled",json!({"workflow_command_id":request_id,
            "workflow_instance_id":DB::string(run,"workflow_instance_id")?,"workflow_run_id":run_id,"failure_id":failure_id,
            "failure_category":"cancelled","closed_reason":"cancelled","exception_class":EXCEPTION,"message":message,"reason":reason,
            "cancellation_cleanup":{"request_id":request_id,"outcome":outcome,"cleanup_deadline_at":timestamp(deadline),
                "delivery_history_event_id":delivery_id,"delivery_sequence":delivery_sequence,"finished_at":at}}),task_id,Some(&request_id)).await
    }

    pub(super) async fn expire_cooperative_deadlines(&self) -> Result<()> {
        const EXPIRED: &str = "SELECT * FROM workflow_runs WHERE namespace='default' AND status IN ('pending','running','waiting') AND cancellation_request_command_id IS NOT NULL AND cancellation_deadline_at<=$1 ORDER BY cancellation_deadline_at,id LIMIT 50";
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
            }
        }
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(())
    }

    pub(super) async fn resume_undelivered_cancellation(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        queue: &str,
    ) -> Result<()> {
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
            return Ok(());
        }
        let Some(request_id) = DB::optional_string(&run, "cancellation_request_command_id")? else {
            return Ok(());
        };
        if Self::scalar("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status IN ('ready','leased')")
            .bind(run_id).fetch_one(&mut **tx).await?==0 {
            Self::create_task(tx,run_id,queue,"workflow",json!({"resume_source_kind":"cancellation_request",
                "resume_source_id":request_id,"workflow_command_id":request_id})).await?;
        }
        Ok(())
    }

    pub(crate) async fn request_cancellation(
        &self,
        workflow_id: &str,
        selected_run: Option<&str>,
        reason: Option<String>,
        budget: i64,
        codec: Arc<Semaphore>,
    ) -> Result<(StatusCode, Value)> {
        let mut tx = self.begin().await?;
        let instance = Self::query(
            "SELECT current_run_id FROM workflow_instances WHERE id=$1 AND namespace='default'",
        )
        .bind(workflow_id)
        .fetch_optional(&mut *tx)
        .await?
        .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "instance_not_found"))?;
        let current = DB::string(&instance, "current_run_id")?;
        let run_id = selected_run.unwrap_or(&current);
        let run = Self::query("SELECT * FROM workflow_runs WHERE id=$1 AND workflow_instance_id=$2 AND namespace='default'")
            .bind(run_id).bind(workflow_id).fetch_optional(&mut *tx).await?
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
        let children = Self::scalar("SELECT COUNT(*) FROM workflow_links WHERE parent_workflow_run_id=$1 OR child_workflow_run_id=$2")
            .bind(run_id).bind(run_id).fetch_one(&mut *tx).await?;
        let activities = Self::scalar("SELECT COUNT(*) FROM activity_executions WHERE workflow_run_id=$1 AND status IN ('pending','running')")
            .bind(run_id).fetch_one(&mut *tx).await?;
        let updates = Self::scalar(
            "SELECT COUNT(*) FROM workflow_updates WHERE workflow_run_id=$1 AND status='accepted'",
        )
        .bind(run_id)
        .fetch_one(&mut *tx)
        .await?;
        if children != 0
            || activities != 0
            || updates != 0
            || Self::open_wait(&mut tx, run_id).await?.is_some()
        {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "cooperative_cancellation_propagation_not_available",
            ));
        }
        let request_id = id();
        let at = now();
        let deadline = at + chrono::Duration::seconds(budget);
        let requester = json!({"type":"auth:token","id":"legacy-token","label":"Admin"});
        let context = json!({"schema":"durable-workflow.cancellation-context/v1", "request_id":request_id,
            "root_request_id":request_id,"root_workflow_instance_id":workflow_id,"root_workflow_run_id":run_id,
            "parent_request_id":null,"reason":reason,"requester":requester,"source":"control_plane",
            "requested_at":timestamp(at),"cleanup_deadline_at":timestamp(deadline),
            "lineage":[{"request_id":request_id,"workflow_instance_id":workflow_id,"workflow_run_id":run_id}]});
        let blob = encode_command(json!({"reason":reason,"cleanup_deadline_at":timestamp(deadline),"cancellation":context}),codec).await?;
        let sequence = DB::number(&run, "last_command_sequence")?
            .checked_add(1)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "command_sequence_exhausted"))?;
        let command_context = json!({"caller":{"type":"server","label":"Standalone Server"},
            "auth":{"status":"authorized","method":"token","role":"admin"},"principal":requester});
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
            "command_type":"request_cancellation","cleanup_deadline_at":timestamp(deadline),"cancellation":context,
            "command":{"id":request_id,"sequence":sequence,"type":"request_cancellation","target_scope":target_scope,
                "requested_run_id":selected_run,"resolved_run_id":run_id,"source":"control_plane","context":command_context,
                "status":"accepted","outcome":"cancellation_requested","payload_codec":"avro","payload":wire(&blob),
                "accepted_at":timestamp(at),"applied_at":timestamp(at)}});
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
