//! Reported application failure, durable retries and catchable terminal failure.
//! Timeout policies and broader workflow/child failure remain explicit gates.
use super::{
    Result,
    backend::Backend,
    refuse,
    store::{Store, now, reject_fields},
    text,
};
use super::laravel_validation::{Rule, failure_messages, rule_errors};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::sync::Arc;
use tokio::sync::Semaphore;

pub(super) fn retry_policy(command: &Value) -> Result<Option<Value>> {
    let empty = json!({});
    let supplied = command.get("retry_policy").filter(|v| !v.is_null());
    let policy = supplied
        .filter(|value| !value.as_array().is_some_and(Vec::is_empty))
        .unwrap_or(&empty);
    let timeouts = super::activity_deadlines::timeout_options(command)?;
    if policy.as_object().is_some_and(|value| value.is_empty())
        && timeouts.iter().all(Option::is_none)
    {
        return Ok(None);
    }
    reject_fields(
        policy,
        &[
            "max_attempts",
            "backoff_seconds",
            "non_retryable_error_types",
        ],
    )?;
    let max = match policy.get("max_attempts").filter(|v| !v.is_null()) {
        Some(value) => value
            .as_i64()
            .filter(|n| *n > 0 && *n <= i32::MAX as i64)
            .ok_or_else(|| {
                refuse(
                    StatusCode::UNPROCESSABLE_ENTITY,
                    "invalid_activity_retry_policy",
                )
            })?,
        None => 1,
    };
    let backoff = match policy.get("backoff_seconds").filter(|v| !v.is_null()) {
        Some(value) => value
            .as_array()
            .filter(|values| values.len() <= 1000)
            .ok_or_else(|| {
                refuse(
                    StatusCode::UNPROCESSABLE_ENTITY,
                    "invalid_activity_retry_policy",
                )
            })?
            .clone(),
        None => vec![],
    };
    for value in &backoff {
        value
            .as_i64()
            .filter(|n| *n >= 0)
            .and_then(chrono::Duration::try_seconds)
            .and_then(|duration| now().checked_add_signed(duration))
            .ok_or_else(|| {
                refuse(
                    StatusCode::UNPROCESSABLE_ENTITY,
                    "invalid_activity_retry_policy",
                )
            })?;
    }
    let mut filters = Vec::new();
    if let Some(value) = policy
        .get("non_retryable_error_types")
        .filter(|v| !v.is_null())
    {
        let list = value
            .as_array()
            .filter(|list| list.len() <= 1000)
            .ok_or_else(|| {
                refuse(
                    StatusCode::UNPROCESSABLE_ENTITY,
                    "invalid_activity_retry_policy",
                )
            })?;
        for value in list {
            let kind = value
                .as_str()
                // PHP trim() removes these six bytes, not Unicode whitespace.
                // A Unicode-padded class/type must keep its exact identity.
                .map(|kind| {
                    kind.trim_matches(|ch| matches!(ch, ' ' | '\t' | '\n' | '\r' | '\0' | '\x0b'))
                })
                .filter(|kind| !kind.is_empty() && kind.len() <= 255)
                .ok_or_else(|| {
                    refuse(
                        StatusCode::UNPROCESSABLE_ENTITY,
                        "invalid_activity_retry_policy",
                    )
                })?;
            let kind = json!(kind);
            if !filters.contains(&kind) {
                filters.push(kind);
            }
        }
    }
    Ok(Some(
        json!({"snapshot_version":1,"max_attempts":max,"backoff_seconds":backoff,
        "non_retryable_error_types":filters,"start_to_close_timeout":timeouts[0],"schedule_to_start_timeout":timeouts[1],
        "schedule_to_close_timeout":timeouts[2],"heartbeat_timeout":timeouts[3]}),
    ))
}

const FAILURE_KINDS: &[&str] = &[
    "application",
    "timeout",
    "cancellation",
    "malformed_output",
    "handler_crash",
    "decode_failure",
    "unsupported_payload",
];
const TIMEOUT_TYPES: &[&str] = &[
    "schedule_to_start",
    "start_to_close",
    "schedule_to_close",
    "heartbeat",
    "deadline_exceeded",
];

/// Published PHP's `ActivityTaskController::fail` rules, in their order.
const FAILURE_RULES: &[(&str, &[Rule])] = &[
    ("activity_attempt_id", &[Rule::Required, Rule::String]),
    ("lease_owner", &[Rule::Required, Rule::String]),
    ("failure", &[Rule::Required, Rule::Array]),
    ("failure.message", &[Rule::Required, Rule::String]),
    ("failure.type", &[Rule::Nullable, Rule::String]),
    ("failure.class", &[Rule::Nullable, Rule::String]),
    ("failure.code", &[Rule::Nullable, Rule::Integer]),
    ("failure.stack_trace", &[Rule::Nullable, Rule::String]),
    ("failure.non_retryable", &[Rule::Nullable, Rule::Boolean]),
    ("failure.retryable", &[Rule::Nullable, Rule::Boolean]),
    ("failure.kind", &[Rule::Nullable, Rule::String, Rule::In(FAILURE_KINDS)]),
    ("failure.timeout_type", &[Rule::Nullable, Rule::String, Rule::In(TIMEOUT_TYPES)]),
    ("failure.cancelled", &[Rule::Nullable, Rule::Boolean]),
    ("failure.malformed_output", &[Rule::Nullable, Rule::Boolean]),
    ("failure.details", &[Rule::Nullable]),
    ("failure.diagnostics", &[Rule::Nullable, Rule::Array]),
    ("failure.runtime_diagnostics", &[Rule::Nullable, Rule::Array]),
];

fn no_custom(_: &str, _: Rule) -> Option<&'static str> {
    None
}

fn invalid(field: &str, message: impl Into<String>) -> super::RuntimeError {
    failure_messages(vec![(field.to_owned(), vec![message.into()])]).expect("one message")
}

/// Validates the trimmed request with PHP's rules. Laravel shows a nested
/// attribute such as `failure.non_retryable` as `failure.non retryable`.
fn validate_failure(body: &Value) -> Result<()> {
    let mut flat = body.clone();
    if let (Some(flat), Some(failure)) = (flat.as_object_mut(), body["failure"].as_object()) {
        for (key, value) in failure {
            flat.insert(format!("failure.{key}"), value.clone());
        }
    }
    let errors = rule_errors(&flat, FAILURE_RULES, no_custom)
        .into_iter()
        .map(|(field, messages)| {
            let shown = field.replace('_', " ");
            let messages = messages.into_iter().map(|message| message.replace(&field, &shown)).collect();
            (field, messages)
        })
        .collect();
    match failure_messages(errors) {
        Some(error) => Err(error),
        None => Ok(()),
    }
}

/// `AvroPayloadEnvelopeResolver::resolveCommandPayloadWithCodec` for
/// `failure.details`: an Avro envelope becomes its blob and codec, any other
/// value is kept as given.
fn resolve_details(details: &Value) -> Result<(Value, Option<&'static str>)> {
    let Some(fields) = details.as_object() else {
        return Ok((details.clone(), None));
    };
    if let Some(message) = fields.get("codec").and_then(super::input_envelope::codec_refusal) {
        return Err(invalid("failure.details.codec", message));
    }
    if fields.contains_key("external_storage") {
        return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, "external_payload_unsupported"));
    }
    let mut keys: Vec<&str> = fields.keys().map(String::as_str).collect();
    keys.sort_unstable();
    if keys != ["blob", "codec"] {
        return Ok((details.clone(), None));
    }
    if !fields["codec"].as_str().is_some_and(|codec| !codec.is_empty()) {
        return Err(invalid("failure.details.codec", "The payload envelope codec must be a non-empty string."));
    }
    let Some(blob) = fields["blob"].as_str() else {
        return Err(invalid("failure.details.blob", "The payload envelope blob must be a string."));
    };
    let decodable = crate::codec::ValueCodec::new().and_then(|codec| codec.decode(blob)).is_ok();
    if !decodable {
        return Err(invalid(
            "failure.details.blob",
            "The payload must contain one complete Avro Value encoded with the official codec. JSON is only the HTTP document transport.",
        ));
    }
    Ok((json!(blob), Some("avro")))
}

/// A PHP array round trip: an object keyed 0..n, including an empty one,
/// comes back as a list.
fn php_array(value: Value) -> Value {
    match value {
        Value::Array(items) => Value::Array(items.into_iter().map(php_array).collect()),
        Value::Object(fields) => {
            if (0..fields.len()).all(|index| fields.contains_key(&index.to_string())) {
                let mut fields = fields;
                Value::Array((0..fields.len()).map(|index| php_array(fields.remove(&index.to_string()).expect("contiguous key"))).collect())
            } else {
                Value::Object(fields.into_iter().map(|(key, value)| (key, php_array(value))).collect())
            }
        }
        other => other,
    }
}

/// `FailureFactory::normalizePayload` of PHP's external worker failure: the
/// reported class (`RuntimeException` when absent), type, message and a
/// JSON integer code, then the optional fields PHP keeps. The stack trace
/// and the classification flags are validated but not kept.
fn normalized_failure(failure: &Value) -> Result<Value> {
    let mut normalized = json!({
        "class": failure["class"].as_str().unwrap_or("RuntimeException"),
        "type": failure["type"].as_str(),
        "message": failure["message"],
        "code": failure["code"].as_i64().unwrap_or(0),
    });
    if let Some(file) = failure["file"].as_str().filter(|file| !file.is_empty()) {
        normalized["file"] = json!(file);
    }
    if let Some(line) = failure["line"].as_i64() {
        normalized["line"] = json!(line);
    }
    for frames in ["trace", "properties"] {
        if let Some(value) = failure.get(frames) {
            let kept: Vec<Value> = match php_array(value.clone()) {
                Value::Array(items) => items.into_iter().filter(|item| item.is_array() || item.is_object()).collect(),
                Value::Object(fields) => fields.into_values().filter(|item| item.is_array() || item.is_object()).collect(),
                _ => Vec::new(),
            };
            normalized[frames] = json!(kept);
        }
    }
    if let Some(details) = failure.get("details") {
        let (payload, codec) = resolve_details(details)?;
        normalized["details"] = php_array(payload);
        if let Some(codec) = codec {
            normalized["details_payload_codec"] = json!(codec);
        }
    }
    if let Some(flag) = failure["non_retryable"].as_bool() {
        normalized["non_retryable"] = json!(flag);
    }
    for field in ["diagnostics", "runtime_diagnostics"] {
        if let Some(value) = failure.get(field).filter(|value| value.is_array() || value.is_object()) {
            normalized[field] = php_array(value.clone());
        }
    }
    Ok(normalized)
}

/// The failure as history presents it: without its class, which history
/// carries as `exception_class`, and with a string `details` as its envelope.
pub(super) fn presented_failure(stored: &Value) -> Value {
    let mut presented = stored.clone();
    if let Some(fields) = presented.as_object_mut() {
        fields.remove("class");
        if let Some(blob) = fields.get("details").and_then(Value::as_str).map(str::to_owned) {
            let codec = fields.get("details_payload_codec").and_then(Value::as_str).unwrap_or("avro").to_owned();
            fields.insert("details".into(), json!({"codec":codec,"blob":blob}));
        }
    }
    presented
}

/// The failure as the activity snapshot keeps it: without its class.
pub(super) fn snapshot_failure(stored: &Value) -> Value {
    let mut snapshot = stored.clone();
    if let Some(fields) = snapshot.as_object_mut() {
        fields.remove("class");
    }
    snapshot
}

/// `ActivityOutcomeRecorder`: a result over the payload ceiling fails its
/// activity with PHP's structural-limit exception instead.
pub(super) async fn structural_failure(
    exceeded: &super::memo_limits::Exceeded,
    semaphore: Arc<Semaphore>,
) -> Result<(Value, String)> {
    let failure = json!({"class":"Workflow\\V2\\Exceptions\\StructuralLimitExceededException",
        "message":exceeded.message,"code":0});
    let stored = failure.clone();
    let permit = semaphore
        .acquire_owned()
        .await
        .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "failure_codec_unavailable"))?;
    let blob = tokio::task::spawn_blocking(move || {
        let _permit = permit;
        super::memos::from_json(stored)
            .and_then(|value| {
                crate::codec::ValueCodec::new()
                    .and_then(|codec| codec.encode(&value))
                    .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "failure_codec_unavailable"))
            })
    })
    .await
    .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "failure_codec_unavailable"))??;
    Ok((failure, blob))
}

/// Validates a reported failure like published PHP and returns its
/// normalized form with the Avro blob stored on the execution.
pub(super) async fn prepare_failure(
    body: &Value,
    semaphore: Arc<Semaphore>,
) -> Result<(Value, String)> {
    let body = super::start_request::normalized(body.clone());
    validate_failure(&body)?;
    let normalized = normalized_failure(&body["failure"])?;
    let stored = normalized.clone();
    let permit = semaphore
        .acquire_owned()
        .await
        .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "failure_codec_unavailable"))?;
    let blob = tokio::task::spawn_blocking(move || {
        let _permit = permit;
        let value = super::memos::from_json(stored)
            .map_err(|_| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_activity_failure"))?;
        crate::codec::ValueCodec::new()
            .and_then(|codec| codec.encode(&value))
            .map_err(|_| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_activity_failure"))
    })
    .await
    .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "failure_codec_unavailable"))??;
    Ok((normalized, blob))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn php_error_filter_normalization_preserves_unicode_and_strips_nul() {
        let fixture: Value = serde_json::from_str(include_str!(
            "../../../tests/Fixtures/ServerParityNormalization/error-filter-types.json"
        ))
        .unwrap();
        let normalized = retry_policy(&json!({"retry_policy": {
            "max_attempts":2,"backoff_seconds":[1],"non_retryable_error_types":fixture["input"]
        }}))
        .unwrap()
        .unwrap();
        assert_eq!(normalized["non_retryable_error_types"], fixture["expected"]);
        for rejected in fixture["rejected"].as_array().unwrap() {
            assert!(
                retry_policy(&json!({"retry_policy": {
                    "max_attempts":2,"non_retryable_error_types":[rejected]
                }}))
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
    pub(crate) async fn fail_activity(
        &self,
        task_id: &str,
        body: Value,
        failure: Value,
        blob: String,
        limit: Option<super::memo_limits::Exceeded>,
    ) -> Result<Value> {
        // An oversized result is still answered as the completion it was.
        let reported = if limit.is_some() { "completed" } else { "failed" };
        let attempt_id = text(&body, "activity_attempt_id")?;
        let mut tx = self.begin().await?;
        let task = self.task_row(&mut tx, task_id).await?;
        if DB::string(&task, "task_type")? != "activity" {
            return Err(refuse(StatusCode::NOT_FOUND, "task_not_found"));
        }
        Self::cancelled_activity_outcome(&mut tx, &task, &body).await?;
        if Self::duplicate_receipt(&task, &body)? {
            return Ok(
                json!({"task_id":task_id,"activity_attempt_id":attempt_id,"outcome":reported,"recorded":false,"reason":"already_completed"}),
            );
        }
        let attempt =
            Self::query("SELECT * FROM activity_attempts WHERE id=$1 AND workflow_task_id=$2")
                .bind(attempt_id)
                .bind(task_id)
                .fetch_optional(&mut *tx)
                .await?
                .ok_or_else(|| refuse(StatusCode::CONFLICT, "stale_attempt"))?;
        let count = DB::number(&attempt, "attempt_number")?;
        // As for completions, PHP judges the attempt before the task's lease.
        if DB::string(&attempt, "status")? != "running" || count != DB::number(&task, "attempt_count")? {
            return Err(super::RuntimeError::Protocol {
                status: StatusCode::CONFLICT,
                response: json!({"task_id":task_id,"activity_attempt_id":attempt_id,"outcome":reported,
                    "recorded":false,"reason":"stale_attempt","next_task_id":null}),
            });
        }
        Self::closed_run_outcome(&mut tx, &task, &body, reported).await?;
        Self::fence(&task, &body)?;
        let run_id = DB::string(&task, "workflow_run_id")?;
        Self::active_run(&mut tx, &run_id).await?;
        let activity_id = DB::string(&attempt, "activity_execution_id")?;
        let activity =
            Self::query("SELECT * FROM activity_executions WHERE id=$1 AND workflow_run_id=$2")
                .bind(&activity_id)
                .bind(&run_id)
                .fetch_one(&mut *tx)
                .await?;
        if DB::string(&attempt, "status")? != "running"
            || count < 1
            || count != DB::number(&task, "attempt_count")?
            || DB::string(&attempt, "workflow_run_id")? != run_id
            || DB::optional_string(&activity, "current_attempt_id")?.as_deref() != Some(attempt_id)
            || DB::number(&activity, "attempt_count")? != count
            || DB::string(&activity, "status")? != "running"
        {
            return Err(refuse(StatusCode::CONFLICT, "stale_attempt"));
        }
        super::activity_deadlines::fence::<DB>(&activity)?;
        let metadata = super::selections::activity_metadata(DB::optional_document_row(
            &activity,
            "parallel_group_path",
        )?)?;
        if super::selections::is_selection(&metadata) {
            // The bounded selection contract covers successful member
            // resolution. Failed/retrying members need a separate fixture;
            // preserve this attempt rather than recording a false winner.
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "development_selection_activity_failure_unqualified",
            ));
        }
        let policy = DB::optional_document_row(&activity, "retry_policy")?.unwrap_or(Value::Null);
        super::activity_deadlines::validate_policy(&policy)?;
        let max = policy["max_attempts"].as_i64().unwrap_or(1);
        // Class/type filters use exact PHP matching. Preserve the original
        // report's flag in its payload; the derived terminal classification
        // also accounts for the immutable execution policy.
        // `isNonRetryableFailure` matches the reported class or type.
        let non_retryable = limit.is_some()
            || failure["non_retryable"] == true
            || policy["non_retryable_error_types"].as_array().is_some_and(|types| {
                types.contains(&failure["class"]) || types.contains(&failure["type"])
            });
        let terminal = non_retryable || count >= max;
        let backoff = policy["backoff_seconds"]
            .as_array()
            .and_then(|values| values.get((count - 1) as usize).or_else(|| values.last()))
            .and_then(Value::as_i64)
            .unwrap_or(0);
        let closed_at = now();
        let available = chrono::Duration::try_seconds(backoff)
            .and_then(|duration| closed_at.checked_add_signed(duration))
            .ok_or_else(|| {
                refuse(
                    StatusCode::UNPROCESSABLE_ENTITY,
                    "invalid_activity_retry_policy",
                )
            })?;
        Self::query("UPDATE activity_attempts SET status='failed',lease_expires_at=NULL,closed_at=$1 WHERE id=$2")
            .bind(DB::bind_time(closed_at))
            .bind(attempt_id)
            .execute(&mut *tx)
            .await?;
        Self::query(
            "UPDATE workflow_tasks SET status='completed',lease_expires_at=NULL WHERE id=$1",
        )
        .bind(task_id)
        .execute(&mut *tx)
        .await?;
        Self::record_completion(&mut tx, task_id, &body).await?;
        if terminal {
            Self::query("UPDATE activity_executions SET status='failed',exception=$1,last_heartbeat_at=NULL,closed_at=$2 WHERE id=$3")
                .bind(blob).bind(DB::bind_time(closed_at)).bind(&activity_id).execute(&mut *tx).await?;
            let failure_id = super::store::id();
            let (category, file) = if limit.is_some() { ("structural_limit", "") } else { ("activity", "external-worker") };
            Self::query("INSERT INTO workflow_failures(id,workflow_run_id,source_kind,source_id,propagation_kind,failure_category,exception_class,message,non_retryable,handled,file,line,created_at,updated_at) VALUES ($1,$2,'activity_execution',$3,'activity',$4,$5,$6,CASE WHEN $7='true' THEN TRUE ELSE FALSE END,FALSE,$8,0,$9,$10)")
                .bind(&failure_id).bind(&run_id).bind(&activity_id).bind(category).bind(failure["class"].as_str().unwrap_or("RuntimeException"))
                .bind(failure["message"].as_str().unwrap()).bind(if non_retryable {"true"} else {"false"}).bind(file)
                .bind(DB::bind_time(closed_at)).bind(DB::bind_time(closed_at)).execute(&mut *tx).await?;
            let updated = Self::query("SELECT * FROM activity_executions WHERE id=$1")
                .bind(&activity_id)
                .fetch_one(&mut *tx)
                .await?;
            let mut payload = Self::activity_payload(&updated)?;
            payload["activity_attempt_id"] = json!(attempt_id);
            payload["attempt_number"] = json!(count);
            payload["failure_id"] = json!(failure_id);
            payload["failure_category"] = json!(category);
            payload["non_retryable"] = json!(non_retryable);
            payload["exception_type"] = failure["type"].clone();
            payload["exception_class"] = failure["class"].clone();
            payload["message"] = failure["message"].clone();
            payload["code"] = failure["code"].clone();
            payload["exception"] = presented_failure(&failure);
            payload["activity"]["exception"] = snapshot_failure(&failure);
            if let Some(limit) = &limit {
                // PHP's own exception payload, with the limit beside it.
                payload["exception"] = json!({"message":limit.message,"code":0});
                payload["activity"]["exception"] = payload["exception"].clone();
                payload.as_object_mut().unwrap().extend(limit.fields().as_object().unwrap().clone());
            }
            payload["task"] = json!({"id":task_id,"type":"activity","status":"leased",
                "available_at":DB::optional_instant(&task,"available_at")?,"leased_at":DB::optional_instant(&task,"leased_at")?,
                "lease_owner":DB::optional_string(&task,"lease_owner")?,"lease_expires_at":DB::optional_instant(&task,"lease_expires_at")?,
                "attempt_count":count,"repair_count":DB::number(&task,"repair_count")?,"queue":DB::string(&task,"queue")?,
                "connection":DB::PHP_CONNECTION});
            let failed_event = payload.clone();
            Self::append(
                &mut tx,
                &run_id,
                "ActivityFailed",
                payload,
                Some(task_id),
                None,
            )
            .await?;
            let run = Self::active_run(&mut tx, &run_id).await?;
            let host = super::standalone_activities::HostClose::Failed {
                payload: super::standalone_activities::failed_payload(&failed_event, &activity_id),
                closed_reason: "failed",
            };
            let next = if Self::close_standalone_host(&mut tx, &run_id, host).await? {
                None
            } else {
                Self::selection_resume(
                    &mut tx,
                    &run_id,
                    &DB::string(&run, "queue")?,
                    json!({"activity_execution_id":activity_id}),
                    &metadata,
                    false,
                )
                .await?
            };
            tx.commit().await?;
            self.wake.notify_waiters();
            return Ok(
                json!({"task_id":task_id,"activity_attempt_id":attempt_id,"outcome":reported,"recorded":true,"reason":null,"next_task_id":next}),
            );
        }
        Self::query("UPDATE activity_executions SET status='pending',exception=$1,last_heartbeat_at=NULL,close_deadline_at=NULL,heartbeat_deadline_at=NULL,schedule_deadline_at=$2 WHERE id=$3")
            .bind(blob).bind(DB::optional_time(super::activity_deadlines::deadline(&policy, "schedule_to_start_timeout", available)?))
            .bind(&activity_id).execute(&mut *tx).await?;
        let retry_task = Self::create_task(&mut tx,&run_id,&DB::string(&activity,"queue")?,"activity",
            json!({"activity_execution_id":activity_id,"retry_of_task_id":task_id,"retry_after_attempt_id":attempt_id,
                "retry_after_attempt":count,"retry_backoff_seconds":backoff,"max_attempts":max,"retry_policy":policy})).await?;
        Self::query("UPDATE workflow_tasks SET available_at=$1,attempt_count=$2 WHERE id=$3")
            .bind(DB::bind_time(available))
            .bind(count)
            .bind(&retry_task)
            .execute(&mut *tx)
            .await?;
        let updated = Self::query("SELECT * FROM activity_executions WHERE id=$1")
            .bind(&activity_id)
            .fetch_one(&mut *tx)
            .await?;
        let mut payload = Self::activity_payload(&updated)?;
        payload["activity_attempt_id"] = json!(attempt_id);
        payload["retry_task_id"] = json!(retry_task);
        payload["retry_of_task_id"] = json!(task_id);
        payload["retry_available_at"] = json!(available);
        payload["retry_backoff_seconds"] = json!(backoff);
        payload["retry_after_attempt_id"] = json!(attempt_id);
        payload["retry_after_attempt"] = json!(count);
        payload["max_attempts"] = json!(max);
        payload["retry_policy"] = policy;
        payload["exception_type"] = failure["type"].clone();
        payload["exception_class"] = failure["class"].clone();
        payload["message"] = failure["message"].clone();
        payload["code"] = failure["code"].clone();
        payload["exception"] = presented_failure(&failure);
        payload["activity"]["exception"] = snapshot_failure(&failure);
        payload["activity_attempt"] = json!({"id":attempt_id,"activity_execution_id":activity_id,"task_id":task_id,
            "status":"failed","lease_owner":DB::optional_string(&task,"lease_owner")?,"closed_at":closed_at});
        Self::append(
            &mut tx,
            &run_id,
            "ActivityRetryScheduled",
            payload,
            Some(task_id),
            None,
        )
        .await?;
        // No workflow resumption until the activity has a terminal result.
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(
            json!({"task_id":task_id,"activity_attempt_id":attempt_id,"outcome":"failed","recorded":true,"reason":null,"next_task_id":retry_task}),
        )
    }
}
