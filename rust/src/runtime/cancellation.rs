//! Immediate cancellation of root runs. Cooperative cleanup and child/update
//! propagation remain refused until their separate fixtures are qualified.
use super::{
    Result, RuntimeError,
    backend::Backend,
    refuse,
    store::{Store, id, now, reject_fields},
};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::sync::Arc;
use tokio::sync::Semaphore;

// Frozen PHP HTTP runs Laravel TrimStrings before cancellation validation.
// Pin its reviewed character set rather than a changing Unicode whitespace
// predicate. Internal characters and the embedded API's reasons stay intact.
pub(super) fn normalize_http_reason(reason: &str) -> &str {
    reason.trim_matches(|ch| {
        matches!(ch,
            '\0' | '\t'..='\r' | ' ' | '\u{85}' | '\u{a0}' | '\u{ad}' |
            '\u{34f}' | '\u{61c}' | '\u{115f}' | '\u{1160}' | '\u{1680}' |
            '\u{17b4}' | '\u{17b5}' | '\u{180e}' | '\u{2000}'..='\u{200f}' |
            '\u{2028}' | '\u{2029}' | '\u{202f}' | '\u{205f}' |
            '\u{2060}'..='\u{2065}' | '\u{206a}'..='\u{206f}' | '\u{2800}' |
            '\u{3000}' | '\u{3164}' | '\u{feff}' | '\u{ffa0}' | '\u{1d159}' |
            '\u{1d173}'..='\u{1d17a}' | '\u{e0020}')
    })
}

pub(super) async fn prepare_reason(
    body: &Value,
    semaphore: Arc<Semaphore>,
) -> Result<(Option<String>, Option<String>)> {
    reject_fields(body, &["reason"])?;
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
    pub(crate) async fn cancel_workflow(
        &self,
        workflow_id: &str,
        selected_run: Option<&str>,
        reason: Option<String>,
        blob: Option<String>,
    ) -> Result<(StatusCode, Value)> {
        let mut tx = self.begin().await?;
        let run = Self::query("SELECT r.* FROM workflow_instances i JOIN workflow_runs r ON r.id=i.current_run_id WHERE i.id=$1 AND i.namespace='default'")
            .bind(workflow_id).fetch_optional(&mut *tx).await?
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
        if !closed {
            let children =
                Self::scalar("SELECT COUNT(*) FROM workflow_links WHERE child_workflow_run_id=$1")
                    .bind(&run_id)
                    .fetch_one(&mut *tx)
                    .await?;
            let pending_children = Self::scalar("SELECT COUNT(*) FROM workflow_child_calls WHERE parent_workflow_run_id=$1 AND status!='completed'")
                .bind(&run_id).fetch_one(&mut *tx).await?;
            let updates = Self::scalar("SELECT COUNT(*) FROM workflow_updates WHERE workflow_run_id=$1 AND status='accepted'")
                .bind(&run_id).fetch_one(&mut *tx).await?;
            let pending_queries =
                Self::query_entries(&mut tx)
                    .await?
                    .into_iter()
                    .any(|(_, task)| {
                        task["run_id"] == run_id
                            && matches!(task["status"].as_str(), Some("pending" | "leased"))
                    });
            if children != 0 || pending_children != 0 || updates != 0 || pending_queries {
                return Err(refuse(
                    StatusCode::UNPROCESSABLE_ENTITY,
                    "cancellation_propagation_not_available",
                ));
            }
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
        let outcome = if closed { None } else { Some("cancelled") };
        // The frozen HTTP selected-run route guards the current run but records
        // an instance-scoped command. Preserve this public receipt representation.
        Self::query("INSERT INTO workflow_commands(id,workflow_instance_id,workflow_run_id,resolved_workflow_run_id,command_type,target_scope,source,status,outcome,rejection_reason,workflow_type,payload_codec,payload,command_sequence,accepted_at,applied_at,rejected_at) VALUES ($1,$2,$3,$4,'cancel','instance','control_plane',$5,$6,$7,$8,'avro',$9,$10,CASE WHEN $11='accepted' THEN $12 ELSE NULL END,CASE WHEN $13='accepted' THEN $14 ELSE NULL END,CASE WHEN $15='rejected' THEN $16 ELSE NULL END)")
            .bind(&command_id).bind(workflow_id).bind(&run_id).bind(&run_id).bind(status).bind(outcome).bind(rejection)
            .bind(DB::string(&run,"workflow_type")?).bind(if closed {None} else {blob.as_deref()}).bind(command_sequence)
            .bind(status).bind(DB::bind_time(at)).bind(status).bind(DB::bind_time(at))
            .bind(status).bind(DB::bind_time(at)).execute(&mut *tx).await?;
        Self::query("UPDATE workflow_runs SET last_command_sequence=$1 WHERE id=$2")
            .bind(command_sequence)
            .bind(&run_id)
            .execute(&mut *tx)
            .await?;
        let response = json!({"workflow_id":workflow_id,"run_id":run_id,"requested_run_id":null,"resolved_run_id":run_id,
            "workflow_type":DB::string(&run,"workflow_type")?,"command_id":command_id,"command_sequence":command_sequence,
            "command_status":status,"command_source":"control_plane","target_scope":"instance","outcome":outcome,
            "reason":if closed {rejection} else {reason.as_deref()},"rejection_reason":rejection,"validation_errors":[]});
        if closed {
            tx.commit().await?;
            return Ok((StatusCode::CONFLICT, response));
        }
        let mut identity = json!({"workflow_command_id":command_id,"workflow_instance_id":workflow_id,"workflow_run_id":run_id});
        if let Some(reason) = &reason {
            identity["reason"] = json!(reason);
        }
        let mut requested = identity.clone();
        requested["command_type"] = json!("cancel");
        Self::append(
            &mut tx,
            &run_id,
            "CancelRequested",
            requested,
            None,
            Some(&command_id),
        )
        .await?;
        Self::query("UPDATE workflow_tasks SET status='cancelled',lease_expires_at=NULL,last_error=NULL WHERE workflow_run_id=$1 AND status IN ('ready','leased')")
            .bind(&run_id).execute(&mut *tx).await?;
        Self::cancel_open_activities(&mut tx, &run_id, &command_id, activities, at).await?;
        for timer in timers {
            let timer_id = DB::string(&timer, "id")?;
            Self::query("UPDATE workflow_run_timers SET status='cancelled' WHERE id=$1")
                .bind(&timer_id)
                .execute(&mut *tx)
                .await?;
            let payload = json!({"timer_id":timer_id,"sequence":DB::number(&timer,"sequence")?,
                "delay_seconds":DB::number(&timer,"delay_seconds")?,"fire_at":DB::instant(&timer,"fire_at")?,"cancelled_at":at});
            Self::append(
                &mut tx,
                &run_id,
                "TimerCancelled",
                payload,
                None,
                Some(&command_id),
            )
            .await?;
        }
        let failure_id = id();
        let message = reason.as_ref().map_or_else(
            || "Workflow cancelled.".to_owned(),
            |reason| format!("Workflow cancelled: {reason}"),
        );
        // Corrected PHP stores the entire diagnostic as one JSON string
        // literal when NUL would truncate or invalidate a SQL text value.
        // Keep the original reason in history and ordinary messages unchanged.
        let message = if message.contains('\0') {
            serde_json::to_string(&message)?
        } else {
            message
        };
        const EXCEPTION: &str = "Workflow\\V2\\Exceptions\\WorkflowCancelledException";
        Self::query("INSERT INTO workflow_failures(id,workflow_run_id,source_kind,source_id,propagation_kind,failure_category,handled,exception_class,message,file,line,trace_preview,created_at,updated_at) VALUES ($1,$2,'workflow_run',$3,'cancelled','cancelled',FALSE,$4,$5,'',0,'',$6,$7)")
            .bind(&failure_id).bind(&run_id).bind(&run_id).bind(EXCEPTION).bind(&message)
            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).execute(&mut *tx).await?;
        let mut terminal = identity;
        terminal["failure_id"] = json!(failure_id);
        terminal["failure_category"] = json!("cancelled");
        terminal["closed_reason"] = json!("cancelled");
        terminal["exception_class"] = json!(EXCEPTION);
        terminal["message"] = json!(message);
        Self::query("UPDATE workflow_runs SET status='cancelled',closed_reason='cancelled',closed_at=$1,last_progress_at=$2 WHERE id=$3")
            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(&run_id).execute(&mut *tx).await?;
        Self::append(
            &mut tx,
            &run_id,
            "WorkflowCancelled",
            terminal,
            None,
            Some(&command_id),
        )
        .await?;
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok((StatusCode::OK, response))
    }

    pub(super) async fn cancel_open_activities(
        tx: &mut sqlx::Transaction<'_, DB>,
        run_id: &str,
        command_id: &str,
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
                Some(command_id),
            )
            .await?;
        }
        Ok(())
    }

    pub(super) async fn cancelled_activity_outcome(
        tx: &mut sqlx::Transaction<'_, DB>,
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
        if let Some(attempt) = attempt {
            if DB::string(&run, "status")? == "cancelled"
                && DB::string(&attempt, "status")? == "cancelled"
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
                    "outcome":"ignored","recorded":false,"reason":"run_cancelled","next_task_id":null,
                    "cancel_requested":true,"can_continue":false,"run_status":"cancelled",
                    "run_closed_reason":DB::optional_string(&run,"closed_reason")?,"run_closed_at":DB::optional_instant(&run,"closed_at")?,
                    "activity_status":DB::string(&activity,"status")?,"attempt_status":"cancelled","task_status":"cancelled",
                    "lease_owner":owner,"lease_expires_at":null}),
                });
            }
        }
        Err(refuse(StatusCode::CONFLICT, "stale_attempt"))
    }
}
