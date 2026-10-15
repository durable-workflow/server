//! Bounded projected waits use only their own task/activity/attempt metadata.
use super::{Result, backend::Backend, run_observation_store::iso, store::Store};
use chrono::{DateTime, Utc};
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
    pub(super) async fn observation_waits(
        &self,
        workflow: &str,
        run: &str,
        pruned: bool,
        terminal: bool,
        now: DateTime<Utc>,
    ) -> Result<Value> {
        let rows = if pruned || terminal {
            vec![]
        } else {
            Self::query(WAITS).bind(run).fetch_all(&self.pool).await?
        };
        let more = rows.len() > 50;
        let waits = rows
            .iter()
            .take(50)
            .map(|row| project::<DB>(row, now))
            .collect::<Result<Vec<_>>>()?;
        Ok(
            json!({"instance_id":workflow,"run_id":run,"namespace":self.namespace.as_ref(),"observed_at":iso(now),
            "source":"workflow_run_waits","history_audit":"not_evaluated",
            "state":if pruned {"pruned"} else if terminal {"available"} else if waits.is_empty() {"unavailable"} else {"partial"},
            "unavailable_reason":if !pruned && !terminal && waits.is_empty() {Some("current_wait_projection_unavailable")} else {None},
            "returned_count":waits.len(),"waits":waits,"total_count":if !pruned && terminal {Some(0)} else {None},"limit":50,"has_more":more}),
        )
    }
}

fn positive(value: &Value) -> Option<i64> {
    value
        .as_i64()
        .or_else(|| value.as_f64().map(|n| n as i64))
        .or_else(|| {
            value
                .as_str()
                .and_then(|s| s.trim().parse::<f64>().ok())
                .map(|n| n as i64)
        })
        .filter(|n| *n > 0)
}

fn project<DB: Backend>(row: &DB::Row, now: DateTime<Utc>) -> Result<Value> {
    let kind = DB::string(row, "kind")?;
    let id = DB::string(row, "wait_id")?;
    let source = DB::optional_string(row, "resume_source_id")?;
    let source_kind = DB::optional_string(row, "resume_source_kind")?;
    let task_id = DB::optional_string(row, "matched_task_id")?;
    let task_type = DB::optional_string(row, "matched_task_type")?;
    let payload = DB::optional_document_row(row, "task_payload")?.unwrap_or(Value::Null);
    let mut task_matches = task_id.is_some() && task_id == DB::optional_string(row, "task_id")?;
    task_matches &= if kind == "activity" {
        source.is_some()
            && task_type.as_deref() == Some("activity")
            && payload["activity_execution_id"].as_str() == source.as_deref()
    } else if kind == "timer" || source_kind.as_deref() == Some("timer") {
        source.is_some()
            && task_type.as_deref() == Some("timer")
            && payload["timer_id"].as_str() == source.as_deref()
    } else {
        task_type.as_deref() == Some("workflow")
            && (payload["open_wait_id"] == id
                || (kind == "child"
                    && ((source.is_some()
                        && payload["child_workflow_run_id"].as_str() == source.as_deref())
                        || payload["child_call_id"].as_str().is_some_and(|call| {
                            !call.is_empty() && format!("child:{call}") == id
                        }))))
    };
    let task_status = if task_matches {
        DB::optional_string(row, "matched_task_status")?
    } else {
        None
    };
    let task_open = matches!(task_status.as_deref(), Some("ready" | "leased"));
    let activity_id = DB::optional_string(row, "activity_id")?;
    let activity_matches = kind == "activity" && activity_id.is_some() && activity_id == source;
    let activity_status = if activity_matches {
        DB::optional_string(row, "activity_status")?
    } else {
        None
    };
    let mut unavailable = DB::optional_string(row, "history_unsupported_reason")?;
    if unavailable.is_none()
        && DB::optional_string(row, "history_authority")?.as_deref()
            == Some("mutable_open_fallback")
    {
        unavailable = Some("wait_without_typed_history".into());
    }
    if unavailable.is_none() && kind == "activity" {
        if !activity_matches {
            unavailable = Some("activity_metadata_unavailable".into());
        } else if !matches!(activity_status.as_deref(), Some("pending" | "running")) {
            unavailable = Some("activity_no_longer_open".into());
        }
    }
    let retry_after = if task_open && kind == "activity" {
        positive(&payload["retry_after_attempt"])
    } else {
        None
    };
    let retry_ready = retry_after.is_some() && task_status.as_deref() == Some("ready");
    let mut resume = if unavailable.is_none() && task_status.as_deref() == Some("ready") {
        DB::optional_instant(row, "task_available_at")?
    } else {
        None
    };
    let mut deadline = None;
    if unavailable.is_none() {
        if kind == "timer" {
            resume = resume.or(DB::optional_instant(row, "deadline_at")?);
        } else if activity_matches {
            let fields: &[&str] = if activity_status.as_deref() == Some("running") {
                &[
                    "close_deadline_at",
                    "heartbeat_deadline_at",
                    "schedule_to_close_deadline_at",
                ]
            } else {
                &["schedule_deadline_at", "schedule_to_close_deadline_at"]
            };
            for field in fields {
                if let Some(candidate) = DB::optional_instant(row, field)? {
                    deadline =
                        Some(deadline.map_or(candidate, |d: DateTime<Utc>| d.min(candidate)));
                }
            }
        } else {
            deadline = DB::optional_instant(row, "deadline_at")?;
        }
    }
    let state = if unavailable.is_some() {
        "unavailable"
    } else if deadline.is_some_and(|d| d <= now) {
        "deadline_elapsed"
    } else {
        match resume {
            None => "resume_time_unknown",
            Some(time) if time > now => "planned",
            Some(_) => "eligible",
        }
    };
    let attempt_id = DB::optional_string(row, "attempt_id")?;
    let attempt_matches = activity_matches
        && attempt_id.is_some()
        && attempt_id == DB::optional_string(row, "current_attempt_id")?
        && DB::optional_string(row, "attempt_activity_id")? == activity_id;
    let attempt_number = if retry_ready {
        retry_after.and_then(|n| n.checked_add(1))
    } else if attempt_matches {
        DB::optional_number(row, "attempt_number")?
    } else {
        None
    };
    let policy = if activity_matches {
        DB::optional_document_row(row, "retry_policy")?.unwrap_or(Value::Null)
    } else {
        Value::Null
    };
    let attempt_limit = if kind == "activity" {
        (if task_matches {
            positive(&payload["max_attempts"])
        } else {
            None
        })
        .or_else(|| positive(&policy["max_attempts"]))
    } else {
        None
    };
    let dependency_type = if activity_matches {
        DB::optional_string(row, "activity_type")?
    } else {
        None
    };
    Ok(
        json!({"id":id,"kind":if retry_after.is_some() {"activity_retry"} else {&kind},"state":state,
        "reason":DB::optional_string(row,"summary")?,"dependency_id":source,
        "dependency_type":dependency_type.or(DB::optional_string(row,"target_type")?),"target_name":DB::optional_string(row,"target_name")?,
        "task_id":if task_matches {task_id} else {None},"task_status":task_status,"task_metadata_state":if task_matches {"available"} else {"unavailable"},
        "next_scheduled_resume_at":resume.map(iso),"deadline_at":deadline.map(iso),
        "lease_expires_at":if task_open {DB::optional_instant(row,"task_lease_expires_at")?.map(iso)} else {None},
        "attempt_id":if attempt_matches && !retry_ready {attempt_id} else {None},"attempt_number":attempt_number,
        "attempt_limit":attempt_limit,"unavailable_reason":unavailable}),
    )
}

// Each join follows a unique stored identity within this run. Only the first 51
// open waits and their own metadata are materialized; no history is loaded here.
const WAITS: &str = "SELECT w.wait_id,w.kind,w.summary,w.target_name,w.target_type,w.deadline_at,w.resume_source_kind,w.resume_source_id,w.task_id,w.history_authority,w.history_unsupported_reason,t.id AS matched_task_id,t.task_type AS matched_task_type,t.status AS matched_task_status,t.payload AS task_payload,t.available_at AS task_available_at,t.lease_expires_at AS task_lease_expires_at,a.id AS activity_id,a.status AS activity_status,a.activity_type,a.current_attempt_id,a.retry_policy,a.schedule_deadline_at,a.close_deadline_at,a.schedule_to_close_deadline_at,a.heartbeat_deadline_at,p.id AS attempt_id,p.activity_execution_id AS attempt_activity_id,p.attempt_number FROM workflow_run_waits w LEFT JOIN workflow_tasks t ON t.id=w.task_id AND t.workflow_run_id=w.workflow_run_id LEFT JOIN activity_executions a ON w.kind='activity' AND a.id=w.resume_source_id AND a.workflow_run_id=w.workflow_run_id LEFT JOIN activity_attempts p ON p.id=a.current_attempt_id AND p.activity_execution_id=a.id AND p.workflow_run_id=w.workflow_run_id WHERE w.workflow_run_id=$1 AND w.status='open' ORDER BY w.position,w.wait_id LIMIT 51";
