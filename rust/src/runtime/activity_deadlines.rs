//! Original authored activity deadlines, shared by claiming and outcome fences.
use super::store::{Store, id};
use super::{Result, backend::Backend, refuse, store::now};
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use serde_json::{Value, json};
use super::transaction::Transaction;
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

/// The failure PHP records when an attempt loses its task lease.
const LEASE_EXCEPTION: &str = "Workflow\\V2\\Exceptions\\ActivityLeaseExpiredException";

pub(super) const TIMEOUT_FIELDS: [&str; 4] = [
    "start_to_close_timeout",
    "schedule_to_start_timeout",
    "schedule_to_close_timeout",
    "heartbeat_timeout",
];

pub(super) fn timeout_options(command: &Value) -> Result<[Option<i64>; 4]> {
    let mut values = [None; 4];
    for (index, field) in TIMEOUT_FIELDS.iter().enumerate() {
        if let Some(value) = command.get(*field).filter(|value| !value.is_null()) {
            let seconds = value
                .as_i64()
                .filter(|seconds| *seconds > 0)
                .filter(|seconds| {
                    chrono::Duration::try_seconds(*seconds)
                        .and_then(|duration| now().checked_add_signed(duration))
                        .is_some()
                })
                .ok_or_else(|| {
                    refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_activity_timeout")
                })?;
            values[index] = Some(seconds);
        }
    }
    Ok(values)
}

pub(super) fn deadline(
    policy: &Value,
    field: &str,
    at: DateTime<Utc>,
) -> Result<Option<DateTime<Utc>>> {
    match policy.get(field).filter(|value| !value.is_null()) {
        None => Ok(None),
        Some(value) => value
            .as_i64()
            .filter(|seconds| *seconds > 0)
            .and_then(chrono::Duration::try_seconds)
            .and_then(|duration| at.checked_add_signed(duration))
            .map(Some)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "invalid_stored_activity_retry_policy")),
    }
}

pub(super) fn validate_policy(policy: &Value) -> Result<()> {
    if policy.is_null() {
        return Ok(());
    }
    let mut command = json!({"retry_policy":{"max_attempts":policy["max_attempts"], "backoff_seconds":policy["backoff_seconds"], "non_retryable_error_types":policy["non_retryable_error_types"]}});
    for field in TIMEOUT_FIELDS {
        command[field] = policy[field].clone();
    }
    let normalized = super::activity_failures::retry_policy(&command)?;
    if normalized.as_ref() != Some(policy) {
        return Err(refuse(
            StatusCode::CONFLICT,
            "invalid_stored_activity_retry_policy",
        ));
    }
    Ok(())
}

pub(super) fn expired_kind<DB: Backend>(
    activity: &DB::Row,
    at: DateTime<Utc>,
) -> Result<Option<&'static str>> {
    let status = DB::string(activity, "status")?;
    if !matches!(status.as_str(), "pending" | "running") {
        return Ok(None);
    }
    for (kind, field, applies) in [
        ("schedule_to_close", "schedule_to_close_deadline_at", true),
        (
            "schedule_to_start",
            "schedule_deadline_at",
            status == "pending",
        ),
        ("heartbeat", "heartbeat_deadline_at", status == "running"),
        ("start_to_close", "close_deadline_at", status == "running"),
    ] {
        if applies && DB::optional_instant(activity, field)?.is_some_and(|deadline| deadline <= at)
        {
            return Ok(Some(kind));
        }
    }
    Ok(None)
}

/// PHP's `deadlines` map on activity claims and status reads: each deadline
/// the execution has, in UTC with microseconds, or null when it has none.
pub(super) fn deadlines_view<DB: Backend>(activity: &DB::Row) -> Result<Value> {
    let mut deadlines = serde_json::Map::new();
    for (kind, field) in [
        ("schedule_to_start", "schedule_deadline_at"),
        ("start_to_close", "close_deadline_at"),
        ("schedule_to_close", "schedule_to_close_deadline_at"),
        ("heartbeat", "heartbeat_deadline_at"),
    ] {
        if let Some(at) = DB::optional_instant(activity, field)? {
            deadlines.insert(
                kind.into(),
                json!(at.to_rfc3339_opts(chrono::SecondsFormat::Micros, true)),
            );
        }
    }
    Ok(if deadlines.is_empty() { Value::Null } else { Value::Object(deadlines) })
}

/// PHP's message for an expired deadline.
fn timeout_message<DB: Backend>(activity: &DB::Row, kind: &str) -> Result<String>
where
    usize: ColumnIndex<DB::Row>,
{
    if kind == "lease" {
        let label = DB::string(activity, "activity_type")?;
        return Ok(format!("Activity {label} lost its original task lease before the attempt settled."));
    }
    let deadline_field = match kind {
        "schedule_to_close" => "schedule_to_close_deadline_at",
        "schedule_to_start" => "schedule_deadline_at",
        "heartbeat" => "heartbeat_deadline_at",
        _ => "close_deadline_at",
    };
    let expired_at =
        DB::instant(activity, deadline_field)?.to_rfc3339_opts(chrono::SecondsFormat::Secs, false);
    let label = DB::string(activity, "activity_type")?;
    if kind != "heartbeat" {
        return Ok(format!("Activity {label} {} deadline expired at {expired_at}.", kind.replace('_', "-")));
    }
    let last = DB::optional_instant(activity, "last_heartbeat_at")?
        .map_or("never".into(), |at| at.to_rfc3339_opts(chrono::SecondsFormat::Secs, false));
    Ok(format!("Activity {label} heartbeat deadline expired at {expired_at} (last heartbeat: {last})."))
}

/// What enforcing one execution's deadline did.
pub(super) enum Enforcement {
    Enforced { retried: bool },
    Skipped(&'static str),
}

/// `activityTimeoutEnforcePass`'s report over each execution's outcome.
pub(super) fn pass_report(outcomes: Vec<(String, Result<Enforcement>)>) -> (Value, bool) {
    let (mut enforced, mut skipped, mut failed) = (0, 0, 0);
    let processed = outcomes.len();
    let results: Vec<Value> = outcomes
        .into_iter()
        .map(|(id, outcome)| match outcome {
            Ok(Enforcement::Enforced { retried }) => {
                enforced += 1;
                json!({"execution_id": id, "outcome": "enforced", "has_retry": retried})
            }
            Ok(Enforcement::Skipped(reason)) => {
                skipped += 1;
                json!({"execution_id": id, "outcome": "skipped", "reason": reason})
            }
            Err(error) => {
                failed += 1;
                json!({"execution_id": id, "outcome": "error", "reason": error.to_string()})
            }
        })
        .collect();
    let report = json!({"processed": processed, "enforced": enforced, "skipped": skipped,
        "failed": failed, "results": results});
    (report, failed > 0)
}

pub(super) fn fence<DB: Backend>(activity: &DB::Row) -> Result<()> {
    if let Some(kind) = expired_kind::<DB>(activity, now())? {
        return Err(refuse(StatusCode::CONFLICT, timeout_reason(kind)));
    }
    Ok(())
}

pub(super) fn timeout_reason(kind: &str) -> &'static str {
    match kind {
        "schedule_to_close" => "schedule_to_close_timeout",
        "schedule_to_start" => "schedule_to_start_timeout",
        "heartbeat" => "heartbeat_timeout",
        _ => "start_to_close_timeout",
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
    pub(super) async fn expire_activity_deadlines(&self) -> Result<()> {
        const DUE: &str = "SELECT a.id FROM activity_executions a JOIN workflow_runs r ON r.id=a.workflow_run_id WHERE r.status IN ('pending','running','waiting') AND a.status IN ('pending','running') AND (a.schedule_to_close_deadline_at<=$1 OR (a.status='pending' AND a.schedule_deadline_at<=$2) OR (a.status='running' AND (a.close_deadline_at<=$3 OR a.heartbeat_deadline_at<=$4))) ORDER BY a.id LIMIT 50";
        let boundary = now();
        let candidates = Self::query(DUE)
            .bind(DB::bind_time(boundary))
            .bind(DB::bind_time(boundary))
            .bind(DB::bind_time(boundary))
            .bind(DB::bind_time(boundary))
            .fetch_all(&self.pool)
            .await?;
        for candidate in candidates {
            self.enforce_activity_deadline(&DB::string(&candidate, "id")?).await?;
        }
        Ok(())
    }

    /// `ActivityTimeoutGuard::enforce`: retries or times out one execution
    /// whose deadline passed, or gives PHP's reason for leaving it.
    pub(super) async fn enforce_activity_deadline(&self, activity_id: &str) -> Result<Enforcement> {
        use Enforcement::Skipped;
        let mut tx = self.begin().await?;
        let Some(activity) = Self::query("SELECT * FROM activity_executions WHERE id=$1")
            .bind(activity_id)
            .fetch_optional(&mut *tx)
            .await?
        else {
            return Ok(Skipped("execution_not_found"));
        };
        if !matches!(DB::string(&activity, "status")?.as_str(), "pending" | "running") {
            return Ok(Skipped("execution_already_terminal"));
        }
        if let Some(reason) = Self::attempt_state_reason(&mut tx, &activity).await? {
            return Ok(Skipped(reason));
        }
        let Some(kind) = expired_kind::<DB>(&activity, now())? else {
            return Ok(Skipped("no_deadline_expired"));
        };
        let run = match Self::active_run(&mut tx, &DB::string(&activity, "workflow_run_id")?).await {
            Ok(run) => run,
            Err(super::RuntimeError::Refused {
                reason: "run_closed" | "cancellation_deadline_expired",
                ..
            }) => return Ok(Skipped("run_already_terminal")),
            Err(error) => return Err(error),
        };
        let retried = Self::time_out_activity(&mut tx, &run, &activity, kind, None).await?;
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(Enforcement::Enforced { retried })
    }

    /// Published PHP's maintenance runs the repair pass every second, and the
    /// pass settles each expired activity lease. Native's scheduler settles
    /// them on its own ticks instead.
    pub(super) async fn expire_activity_leases(&self) -> Result<()> {
        const DUE: &str = "SELECT id FROM workflow_tasks WHERE status='leased' AND task_type='activity' AND lease_expires_at IS NOT NULL AND lease_expires_at<=$1 ORDER BY lease_expires_at,id LIMIT 50";
        let candidates = Self::query(DUE).bind(DB::bind_time(now())).fetch_all(&self.pool).await?;
        for candidate in candidates {
            self.enforce_lease_expiry(&DB::string(&candidate, "id")?).await?;
        }
        Ok(())
    }

    /// `ActivityTimeoutEnforcer::enforceLeaseExpiry`: an activity attempt
    /// whose task lease expired before it reported an outcome closes as
    /// expired, and the activity's retry policy decides between a retry and
    /// a terminal `ActivityFailed`. An expired business deadline is left to
    /// the deadline sweep, which owns that outcome.
    pub(super) async fn enforce_lease_expiry(&self, task_id: &str) -> Result<Enforcement> {
        use Enforcement::Skipped;
        let mut tx = self.begin().await?;
        let task = Self::query("SELECT * FROM workflow_tasks WHERE id=$1").bind(task_id).fetch_optional(&mut *tx).await?;
        let execution_id = match &task {
            Some(task) => DB::optional_document_row(task, "payload")?
                .and_then(|payload| payload["activity_execution_id"].as_str().map(str::to_owned)),
            None => None,
        };
        let (Some(task), Some(execution_id)) = (task, execution_id) else {
            return Ok(Skipped("activity_execution_missing"));
        };
        let Some(activity) = Self::query("SELECT * FROM activity_executions WHERE id=$1")
            .bind(&execution_id)
            .fetch_optional(&mut *tx)
            .await?
        else {
            return Ok(Skipped("execution_not_found"));
        };
        if !matches!(DB::string(&activity, "status")?.as_str(), "pending" | "running") {
            return Ok(Skipped("execution_already_terminal"));
        }
        let at = now();
        if expired_kind::<DB>(&activity, at)?.is_some() {
            return Ok(Skipped("activity_deadline_expired"));
        }
        if let Some(reason) = Self::attempt_state_reason(&mut tx, &activity).await? {
            return Ok(Skipped(reason));
        }
        let run = match Self::active_run(&mut tx, &DB::string(&activity, "workflow_run_id")?).await {
            Ok(run) => run,
            Err(super::RuntimeError::Refused {
                reason: "run_closed" | "cancellation_deadline_expired",
                ..
            }) => return Ok(Skipped("run_already_terminal")),
            Err(error) => return Err(error),
        };
        // `originalLeaseMatches`: the task still carries the current
        // attempt's lease, and that lease has expired.
        let attempt = match DB::optional_string(&activity, "current_attempt_id")? {
            Some(attempt_id) => Self::query("SELECT * FROM activity_attempts WHERE id=$1")
                .bind(attempt_id)
                .fetch_optional(&mut *tx)
                .await?,
            None => None,
        };
        let expires = DB::optional_instant(&task, "lease_expires_at")?;
        let owner = DB::optional_string(&task, "lease_owner")?;
        let original = match &attempt {
            Some(attempt) => {
                DB::optional_string(attempt, "workflow_task_id")?.as_deref() == Some(task_id)
                    && DB::string(&task, "status")? == "leased"
                    && DB::string(&task, "task_type")? == "activity"
                    && DB::number(&task, "attempt_count")? == DB::number(attempt, "attempt_number")?
                    && owner == DB::optional_string(attempt, "lease_owner")?
            }
            None => false,
        };
        let Some(expires) = expires.filter(|expires| original && at >= *expires) else {
            return Ok(Skipped("original_lease_not_expired"));
        };
        let lease = json!({"failure_reason": "lease_expired",
            "lease_expires_at": expires.to_rfc3339_opts(chrono::SecondsFormat::Micros, true), "lease_owner": owner});
        let retried = Self::time_out_activity(&mut tx, &run, &activity, "lease", Some(&lease)).await?;
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(Enforcement::Enforced { retried })
    }

    /// `currentAttemptStateReason`: a running execution's current attempt
    /// must still be the one it records, and running.
    async fn attempt_state_reason(
        tx: &mut Transaction<'_, DB>,
        activity: &DB::Row,
    ) -> Result<Option<&'static str>> {
        if DB::string(activity, "status")? != "running" {
            return Ok(None);
        }
        let Some(attempt_id) = DB::optional_string(activity, "current_attempt_id")? else {
            return Ok(Some("current_attempt_changed"));
        };
        let Some(attempt) = Self::query("SELECT * FROM activity_attempts WHERE id=$1")
            .bind(&attempt_id)
            .fetch_optional(&mut **tx)
            .await?
        else {
            return Ok(Some("current_attempt_not_found"));
        };
        if DB::string(&attempt, "activity_execution_id")? != DB::string(activity, "id")?
            || DB::number(&attempt, "attempt_number")? != DB::number(activity, "attempt_count")?
        {
            return Ok(Some("current_attempt_changed"));
        }
        // Native marks an attempt whose lease lapsed `expired`; it is still current.
        if !matches!(DB::string(&attempt, "status")?.as_str(), "running" | "expired") {
            return Ok(Some("current_attempt_not_running"));
        }
        Ok(None)
    }

    /// Fails the expired attempt, then schedules a retry or records the
    /// timeout. A lost lease (`lease` carries its metadata) closes the
    /// attempt as expired and settles as PHP's `ActivityLeaseExpiredException`
    /// instead. Returns whether it retried.
    async fn time_out_activity(
        tx: &mut Transaction<'_, DB>,
        run: &DB::Row,
        activity: &DB::Row,
        kind: &'static str,
        lease: Option<&Value>,
    ) -> Result<bool> {
        let activity_id = &DB::string(activity, "id")?;
        let run_id = DB::string(activity, "workflow_run_id")?;
        let policy = DB::optional_document_row(activity, "retry_policy")?.unwrap_or(Value::Null);
        validate_policy(&policy)?;
        let count = DB::number(activity, "attempt_count")?.max(1);
        let max = policy["max_attempts"].as_i64().unwrap_or(1);
        let current = DB::optional_string(activity, "current_attempt_id")?;
        // `isNonRetryableFailure` for the lease exception: its class is the
        // only class or type it reports.
        let non_retryable = lease.is_some()
            && policy["non_retryable_error_types"]
                .as_array()
                .is_some_and(|types| types.contains(&json!(LEASE_EXCEPTION)));
        let retried = count < max && kind != "schedule_to_close" && !non_retryable;
        let task = Self::query(DB::ACTIVITY_OPEN_TASK_SQL)
            .bind(&run_id)
            .bind(activity_id)
            .fetch_optional(&mut **tx)
            .await?;
        let task_id = task
            .as_ref()
            .map(|task| DB::string(task, "id"))
            .transpose()?;
        let at = now();
        if let Some(attempt_id) = current.as_deref() {
            let status = if lease.is_some() { "expired" } else { "failed" };
            Self::query("UPDATE activity_attempts SET status=$1,lease_expires_at=NULL,closed_at=COALESCE(closed_at,$2) WHERE id=$3 AND status='running'")
                .bind(status).bind(DB::bind_time(at)).bind(attempt_id).execute(&mut **tx).await?;
        }
        if let Some(task_id) = task_id.as_deref() {
            // A lost lease keeps its reason on the closed task, as PHP does.
            let error = lease.and_then(|lease| lease["failure_reason"].as_str());
            Self::query("UPDATE workflow_tasks SET status='cancelled',lease_expires_at=NULL,last_error=$1 WHERE id=$2")
                .bind(error).bind(task_id).execute(&mut **tx).await?;
        }
        let message = timeout_message::<DB>(activity, kind)?;
        // A lease loss names itself by its metadata and retry reason, not a
        // timeout kind.
        let lease_fields = |payload: &mut Value, retry_reason: bool| {
            if let (Some(fields), Some(Value::Object(lease))) = (payload.as_object_mut(), lease) {
                fields.remove("timeout_kind");
                for (key, value) in lease {
                    fields.insert(key.clone(), value.clone());
                }
                if retry_reason {
                    fields.insert("retry_reason".into(), lease["failure_reason"].clone());
                }
            }
        };
        if retried {
            let backoff = policy["backoff_seconds"]
                .as_array()
                .and_then(|values| values.get((count - 1) as usize).or_else(|| values.last()))
                .and_then(Value::as_i64)
                .unwrap_or(0);
            let available = chrono::Duration::try_seconds(backoff)
                .and_then(|duration| at.checked_add_signed(duration))
                .ok_or_else(|| {
                    refuse(StatusCode::CONFLICT, "invalid_stored_activity_retry_policy")
                })?;
            Self::query("UPDATE activity_executions SET status='pending',last_heartbeat_at=NULL,close_deadline_at=NULL,heartbeat_deadline_at=NULL,schedule_deadline_at=$1 WHERE id=$2")
                .bind(DB::optional_time(deadline(&policy,"schedule_to_start_timeout",available)?)).bind(activity_id).execute(&mut **tx).await?;
            let mut retry_payload = json!({"activity_execution_id":activity_id,"retry_of_task_id":task_id,"retry_after_attempt_id":current,"retry_after_attempt":count,"retry_backoff_seconds":backoff,"max_attempts":max,"retry_policy":policy,"timeout_kind":kind});
            lease_fields(&mut retry_payload, true);
            let retry = Self::create_task(tx,&run_id,&DB::string(&activity,"queue")?,"activity",retry_payload).await?;
            Self::query(
                "UPDATE workflow_tasks SET available_at=$1,attempt_count=$2 WHERE id=$3",
            )
            .bind(DB::bind_time(available))
            .bind(count)
            .bind(&retry)
            .execute(&mut **tx)
            .await?;
            let updated = Self::query("SELECT * FROM activity_executions WHERE id=$1")
                .bind(activity_id)
                .fetch_one(&mut **tx)
                .await?;
            let mut payload = Self::activity_payload(&updated)?;
            for (key,value) in json!({"activity_attempt_id":current,"retry_task_id":retry,"retry_of_task_id":task_id,"retry_available_at":available,"retry_backoff_seconds":backoff,"retry_after_attempt_id":current,"retry_after_attempt":count,"max_attempts":max,"retry_policy":policy,"timeout_kind":kind,"message":message}).as_object().unwrap() { if !value.is_null() {payload[key]=value.clone();} }
            lease_fields(&mut payload, true);
            Self::append(
                tx,
                &run_id,
                "ActivityRetryScheduled",
                payload,
                task_id.as_deref(),
                None,
            )
            .await?;
        } else {
            let failure_id = id();
            // A lost lease is an internal activity failure; a deadline is a
            // timeout.
            let (class, category, propagation, event) = if lease.is_some() {
                (LEASE_EXCEPTION, "internal", "activity", "ActivityFailed")
            } else {
                ("Workflow\\V2\\Exceptions\\ActivityTimeoutException", "timeout", "timeout", "ActivityTimedOut")
            };
            Self::query(
                "UPDATE activity_executions SET status='failed',closed_at=$1 WHERE id=$2",
            )
            .bind(DB::bind_time(at))
            .bind(activity_id)
            .execute(&mut **tx)
            .await?;
            Self::query("INSERT INTO workflow_failures(id,workflow_run_id,source_kind,source_id,propagation_kind,failure_category,exception_class,message,non_retryable,handled,file,line,created_at,updated_at) VALUES ($1,$2,'activity_execution',$3,$4,$5,$6,$7,CASE WHEN $8='true' THEN TRUE ELSE FALSE END,FALSE,'',0,$9,$10)")
                .bind(&failure_id).bind(&run_id).bind(activity_id).bind(propagation).bind(category).bind(class).bind(&message)
                .bind(if non_retryable { "true" } else { "false" }).bind(DB::bind_time(at)).bind(DB::bind_time(at)).execute(&mut **tx).await?;
            let updated = Self::query("SELECT * FROM activity_executions WHERE id=$1")
                .bind(activity_id)
                .fetch_one(&mut **tx)
                .await?;
            let mut payload = Self::activity_payload(&updated)?;
            for (key,value) in json!({"activity_attempt_id":current,"attempt_number":count,"failure_id":failure_id,"failure_category":category,"timeout_kind":kind,"message":message,"exception_class":class}).as_object().unwrap() {payload[key]=value.clone();}
            if lease.is_some() {
                lease_fields(&mut payload, false);
                payload["non_retryable"] = json!(non_retryable);
            } else {
                for field in [
                    "schedule_deadline_at",
                    "close_deadline_at",
                    "schedule_to_close_deadline_at",
                    "heartbeat_deadline_at",
                ] {
                    payload[field] = json!(
                        DB::optional_instant(&activity, field)?
                            .map(|at| at.to_rfc3339_opts(chrono::SecondsFormat::Secs, false))
                    );
                }
            }
            Self::append(
                tx,
                &run_id,
                event,
                payload,
                task_id.as_deref(),
                None,
            )
            .await?;
            let metadata = super::selections::activity_metadata(DB::optional_document_row(
                &activity,
                "parallel_group_path",
            )?)?;
            Self::resolve_selection(
                tx,
                &run_id,
                &metadata,
                "activity",
                activity_id,
                event,
            )
            .await?;
            Self::selection_resume(
                tx,
                &run_id,
                &DB::string(&run, "queue")?,
                json!({"activity_execution_id":activity_id}),
                &metadata,
                false,
            )
            .await?;
        }
        Ok(retried)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn pass_report_counts_each_outcome_like_php() {
        let outcomes = vec![
            ("a".to_owned(), Ok(Enforcement::Enforced { retried: true })),
            ("b".to_owned(), Ok(Enforcement::Skipped("no_deadline_expired"))),
            ("c".to_owned(), Err(refuse(StatusCode::CONFLICT, "invalid_stored_activity_retry_policy"))),
        ];
        let (report, failed) = pass_report(outcomes);
        assert!(failed);
        assert_eq!((&report["processed"], &report["enforced"], &report["skipped"], &report["failed"]),
            (&json!(3), &json!(1), &json!(1), &json!(1)));
        assert_eq!(report["results"][0], json!({"execution_id":"a","outcome":"enforced","has_retry":true}));
        assert_eq!(report["results"][1]["reason"], "no_deadline_expired");
        assert_eq!(report["results"][2]["outcome"], "error");
    }

    #[tokio::test]
    async fn original_deadline_boundary_is_inclusive_and_precedence_is_stable() {
        let pool = sqlx::SqlitePool::connect("sqlite::memory:").await.unwrap();
        let at = DateTime::parse_from_rfc3339("2020-01-02T03:04:05.123456Z")
            .unwrap()
            .with_timezone(&Utc);
        let row = sqlx::query("SELECT 'running' AS status, '2020-01-02 03:04:05.123456' AS schedule_to_close_deadline_at, NULL AS schedule_deadline_at, '2020-01-02 03:04:05.123456' AS heartbeat_deadline_at, '2020-01-02 03:04:05.123456' AS close_deadline_at").fetch_one(&pool).await.unwrap();
        assert_eq!(
            expired_kind::<sqlx::Sqlite>(&row, at - chrono::Duration::microseconds(1)).unwrap(),
            None
        );
        assert_eq!(
            expired_kind::<sqlx::Sqlite>(&row, at).unwrap(),
            Some("schedule_to_close")
        );
        let row = sqlx::query("SELECT 'running' AS status, NULL AS schedule_to_close_deadline_at, NULL AS schedule_deadline_at, '2020-01-02 03:04:05.123456' AS heartbeat_deadline_at, '2020-01-02 03:04:05.123456' AS close_deadline_at").fetch_one(&pool).await.unwrap();
        assert_eq!(
            expired_kind::<sqlx::Sqlite>(&row, at).unwrap(),
            Some("heartbeat")
        );
        pool.close().await;
    }

    #[test]
    fn supplied_timeouts_preserve_the_full_policy_and_refuse_invalid_bounds() {
        let command = json!({"retry_policy":{"max_attempts":1,"backoff_seconds":[1,2,5,10,15,30,60,120]},"start_to_close_timeout":120,"schedule_to_close_timeout":600});
        let policy = super::super::activity_failures::retry_policy(&command)
            .unwrap()
            .unwrap();
        assert_eq!(
            policy["backoff_seconds"],
            command["retry_policy"]["backoff_seconds"]
        );
        assert_eq!(policy["start_to_close_timeout"], 120);
        assert_eq!(policy["schedule_to_close_timeout"], 600);
        validate_policy(&policy).unwrap();
        for field in TIMEOUT_FIELDS {
            for invalid in [json!(0), json!(-1), json!(1.5), json!("1"), json!(i64::MAX)] {
                let mut bad = command.clone();
                bad[field] = invalid;
                assert!(super::super::activity_failures::retry_policy(&bad).is_err());
            }
        }
        assert!(
            super::super::activity_failures::retry_policy(&json!({}))
                .unwrap()
                .is_none()
        );
        let policy = super::super::activity_failures::retry_policy(&json!({"heartbeat_timeout":1}))
            .unwrap()
            .unwrap();
        assert_eq!(policy["max_attempts"], 1);
        assert_eq!(policy["heartbeat_timeout"], 1);
    }
}
