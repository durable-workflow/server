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
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
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
