//! Standalone activities, as published PHP serves them (`ActivityController`,
//! `StandaloneActivityStartService`): an activity started as a top-level job.
//!
//! The server records each one inside a host run of type
//! `dw.standalone_activity`. The run has no workflow task: its one activity
//! is the work, and the activity's terminal outcome closes the run.
use super::laravel_rules::{self, Rule};
use super::transaction::Transaction;
use super::{Result, RuntimeError, backend::Backend, store::{Store, id, now}, wire};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

/// `StandaloneActivityHostType::WORKFLOW_TYPE`.
pub(super) const HOST_TYPE: &str = "dw.standalone_activity";

fn identifier(value: &str) -> bool {
    !value.is_empty()
        && value
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'.' | b'_' | b':' | b'-'))
}

/// `ActivityController::start`'s rules.
const START_RULES: &laravel_rules::Rules = &[
    ("activity_id", &[Rule::Nullable, Rule::String, Rule::Max(128), Rule::Pattern(identifier)]),
    ("activity_type", &[Rule::Required, Rule::String, Rule::Max(255)]),
    ("activity_class", &[Rule::Nullable, Rule::String, Rule::Max(255)]),
    ("task_queue", &[Rule::Nullable, Rule::String, Rule::Max(255)]),
    ("input", &[Rule::Nullable, Rule::Array]),
    ("business_key", &[Rule::Nullable, Rule::String, Rule::Max(255)]),
    ("retry_policy", &[Rule::Nullable, Rule::Array]),
    ("retry_policy.max_attempts", &[Rule::Nullable, Rule::Integer, Rule::Min(1)]),
    ("retry_policy.backoff_seconds", &[Rule::Nullable, Rule::Array]),
    ("retry_policy.backoff_seconds.*", &[Rule::Integer, Rule::Min(0)]),
    ("retry_policy.non_retryable_error_types", &[Rule::Nullable, Rule::Array]),
    ("retry_policy.non_retryable_error_types.*", &[Rule::String]),
    ("start_to_close_timeout_seconds", &[Rule::Nullable, Rule::Integer, Rule::Min(1)]),
    ("schedule_to_start_timeout_seconds", &[Rule::Nullable, Rule::Integer, Rule::Min(1)]),
    ("schedule_to_close_timeout_seconds", &[Rule::Nullable, Rule::Integer, Rule::Min(1)]),
    ("heartbeat_timeout_seconds", &[Rule::Nullable, Rule::Integer, Rule::Min(1)]),
];

/// `ActivityController::index`'s rules.
const LIST_RULES: &laravel_rules::Rules = &[
    ("status", &[Rule::Nullable, Rule::String, Rule::In(&["running", "completed", "failed"])]),
    ("page_size", &[Rule::Nullable, Rule::Integer, Rule::Min(1), Rule::Max(200)]),
    ("next_page_token", &[Rule::Nullable, Rule::String]),
];

/// A validated start request.
pub(super) struct StartRequest {
    pub(super) activity_id: Option<String>,
    pub(super) activity_type: String,
    pub(super) activity_class: String,
    pub(super) task_queue: String,
    pub(super) business_key: Option<String>,
    /// The worker-facing retry and timeout snapshot.
    pub(super) policy: Value,
}

/// A JSON integer, as PHP's `is_int` sees a decoded request value.
fn int(value: &Value) -> Option<i64> {
    value.as_i64().filter(|_| value.is_i64() || value.is_u64())
}

/// Validates a start request (already normalized) like PHP.
pub(super) fn start_request(body: &Value) -> Result<StartRequest> {
    laravel_rules::validate(body, START_RULES)?;
    let text = |field: &str| body[field].as_str().map(str::to_owned);
    let activity_type = text("activity_type").expect("validated activity type");
    let policy = &body["retry_policy"];
    // `StandaloneActivityStartService`: only integers and non-empty strings
    // reach the snapshot; anything else falls back to its default.
    let backoff: Vec<Value> = policy["backoff_seconds"]
        .as_array()
        .map(|values| values.iter().filter_map(int).map(Value::from).collect())
        .or_else(|| int(&policy["backoff_seconds"]).map(|value| vec![json!(value)]))
        .unwrap_or_default();
    let types: Vec<Value> = policy["non_retryable_error_types"]
        .as_array()
        .map(|values| {
            values
                .iter()
                .filter_map(Value::as_str)
                .filter(|kind| !kind.is_empty())
                .map(Value::from)
                .collect()
        })
        .unwrap_or_default();
    let command = json!({
        "retry_policy": {"max_attempts": int(&policy["max_attempts"]).unwrap_or(1),
            "backoff_seconds": backoff, "non_retryable_error_types": types},
        "start_to_close_timeout": int(&body["start_to_close_timeout_seconds"]),
        "schedule_to_start_timeout": int(&body["schedule_to_start_timeout_seconds"]),
        "schedule_to_close_timeout": int(&body["schedule_to_close_timeout_seconds"]),
        "heartbeat_timeout": int(&body["heartbeat_timeout_seconds"]),
    });
    let policy = super::activity_failures::retry_policy(&command)?
        .expect("an explicit policy always has a snapshot");
    Ok(StartRequest {
        activity_id: text("activity_id"),
        activity_class: text("activity_class").unwrap_or_else(|| activity_type.clone()),
        activity_type,
        // PHP's default queue is the database connection's queue.
        task_queue: text("task_queue").unwrap_or_else(|| "default".into()),
        business_key: text("business_key"),
        policy,
    })
}

/// A validated listing request.
pub(super) struct ListRequest {
    pub(super) status: Option<String>,
    pub(super) page_size: i64,
    pub(super) offset: i64,
}

pub(super) fn list_request(query: &Value) -> Result<ListRequest> {
    let query = laravel_rules::normalize(query);
    laravel_rules::validate(&query, LIST_RULES)?;
    // `decodePageToken`: base64 of a decimal offset, else the first page.
    let offset = query["next_page_token"]
        .as_str()
        .and_then(|token| {
            use base64::Engine;
            base64::engine::general_purpose::STANDARD.decode(token).ok()
        })
        .and_then(|bytes| String::from_utf8(bytes).ok())
        .filter(|text| !text.is_empty() && text.bytes().all(|byte| byte.is_ascii_digit()))
        .and_then(|text| text.parse::<i64>().ok())
        .unwrap_or(0);
    Ok(ListRequest {
        status: query["status"].as_str().map(str::to_owned),
        page_size: super::laravel_validation::integer(&query["page_size"]).unwrap_or(50),
        offset,
    })
}

pub(super) fn page_token(offset: i64) -> String {
    use base64::Engine;
    base64::engine::general_purpose::STANDARD.encode(offset.to_string())
}

fn not_found() -> RuntimeError {
    RuntimeError::Protocol {
        status: StatusCode::NOT_FOUND,
        response: json!({"message":"Standalone activity not found.","reason":"activity_not_found"}),
    }
}

fn invalid_id(message: String) -> RuntimeError {
    super::laravel_validation::failure_messages(vec![("activity_id".into(), vec![message])])
        .expect("one message")
}

/// How a host run closes with its activity.
pub(super) enum HostClose<'a> {
    /// `closeStandaloneHostRun` for a result: the run completes with it.
    Completed { output: &'a str },
    /// A terminal failure or timeout: `WorkflowFailed` with this payload.
    Failed { payload: Value, closed_reason: &'static str },
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
    /// `StandaloneActivityStartService::start`: the host run, its start
    /// command and history, the pending activity and its ready task, in one
    /// transaction.
    pub(crate) async fn start_standalone_activity(
        &self,
        request: StartRequest,
        arguments: Option<String>,
        external: Option<super::external_resolution::PreparedExternal>,
        context: Value,
    ) -> Result<Value> {
        let mut tx = self.begin().await?;
        if let Some(prepared) = &external {
            self.claim_external_in(&mut tx, &prepared.reference, &prepared.uri).await?;
        }
        let activity_id = request.activity_id.clone().unwrap_or_else(id);
        let existing = Self::query("SELECT i.namespace,i.workflow_type,i.run_count,r.status AS run_status,r.run_number FROM workflow_instances i LEFT JOIN workflow_runs r ON r.id=i.current_run_id WHERE i.id=$1")
            .bind(&activity_id).fetch_optional(&mut *tx).await?;
        let started_at = now();
        let run_id = id();
        let run_number = match &existing {
            None => {
                Self::query("INSERT INTO workflow_instances(id,workflow_type,workflow_class,current_run_id,run_count,created_at,updated_at,started_at,namespace) VALUES ($1,$2,$3,$4,1,$5,$6,$7,$8)")
                    .bind(&activity_id).bind(HOST_TYPE).bind(HOST_TYPE).bind(&run_id).bind(DB::bind_time(started_at))
                    .bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at)).bind(self.namespace.as_ref())
                    .execute(&mut *tx).await?;
                1
            }
            Some(instance) => {
                if DB::optional_string(instance, "namespace")?.as_deref() != Some(self.namespace.as_ref()) {
                    return Err(RuntimeError::Protocol {
                        status: StatusCode::CONFLICT,
                        response: json!({"message":format!("Identifier [{activity_id}] is already reserved in another namespace."),
                            "reason":"activity_id_reserved_in_namespace","activity_id":activity_id,
                            "command_status":"rejected","rejection_reason":"activity_id_reserved_in_namespace"}),
                    });
                }
                let workflow_type = DB::string(instance, "workflow_type")?;
                if workflow_type != HOST_TYPE {
                    return Err(invalid_id(format!("Identifier [{activity_id}] is already reserved for workflow type [{workflow_type}] and cannot be reused for a standalone activity.")));
                }
                if DB::optional_string(instance, "run_status")?
                    .is_some_and(|status| matches!(status.as_str(), "pending" | "running" | "waiting"))
                {
                    return Err(invalid_id(format!("Standalone activity [{activity_id}] is already running.")));
                }
                let number = DB::number(instance, "run_count")?
                    .max(DB::optional_number(instance, "run_number")?.unwrap_or(0))
                    .max(0)
                    + 1;
                Self::query("UPDATE workflow_instances SET current_run_id=$1,run_count=$2,updated_at=$3 WHERE id=$4")
                    .bind(&run_id).bind(number).bind(DB::bind_time(started_at)).bind(&activity_id)
                    .execute(&mut *tx).await?;
                number
            }
        };
        let queue = request.task_queue.as_str();
        Self::query("INSERT INTO workflow_runs(id,workflow_instance_id,workflow_type,workflow_class,run_number,status,payload_codec,arguments,queue,started_at,created_at,updated_at,namespace,last_progress_at,business_key,last_command_sequence) VALUES ($1,$2,$3,$4,$5,'running','avro',$6,$7,$8,$9,$10,$11,$12,$13,1)")
            .bind(&run_id).bind(&activity_id).bind(HOST_TYPE).bind(HOST_TYPE).bind(run_number).bind(arguments.as_deref())
            .bind(queue).bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at))
            .bind(self.namespace.as_ref()).bind(DB::bind_time(started_at)).bind(request.business_key.as_deref())
            .execute(&mut *tx).await?;
        let command_id = id();
        Self::query("INSERT INTO workflow_commands(id,workflow_instance_id,workflow_run_id,resolved_workflow_run_id,command_type,source,status,outcome,workflow_type,payload_codec,payload,command_sequence,accepted_at,applied_at,context) VALUES ($1,$2,$3,$4,'start','control_plane','accepted','started_new',$5,'avro',$6,1,$7,$8,$9)")
            .bind(&command_id).bind(&activity_id).bind(&run_id).bind(&run_id).bind(HOST_TYPE).bind(arguments.as_deref())
            .bind(DB::bind_time(started_at)).bind(DB::bind_time(started_at)).bind(DB::document(&context))
            .execute(&mut *tx).await?;
        let identity = json!({"workflow_instance_id":activity_id,"workflow_run_id":run_id,
            "workflow_class":HOST_TYPE,"workflow_type":HOST_TYPE,"workflow_command_id":command_id,
            "business_key":request.business_key});
        let mut accepted = identity.clone();
        for (key, value) in [("visibility_labels", Value::Null), ("memo", Value::Null),
            ("search_attributes", Value::Null), ("outcome", json!("started_new"))] {
            accepted[key] = value;
        }
        Self::append(&mut tx, &run_id, "StartAccepted", accepted, None, Some(&command_id)).await?;
        Self::append(&mut tx, &run_id, "WorkflowStarted", identity, None, Some(&command_id)).await?;
        let execution_id = id();
        let schedule_deadline = super::activity_deadlines::deadline(&request.policy, "schedule_to_start_timeout", started_at)?;
        let close_deadline = super::activity_deadlines::deadline(&request.policy, "schedule_to_close_timeout", started_at)?;
        Self::query("INSERT INTO activity_executions(id,workflow_run_id,sequence,activity_type,activity_class,status,attempt_count,payload_codec,arguments,queue,retry_policy,schedule_deadline_at,schedule_to_close_deadline_at) VALUES ($1,$2,1,$3,$4,'pending',0,'avro',$5,$6,$7,$8,$9)")
            .bind(&execution_id).bind(&run_id).bind(&request.activity_type).bind(&request.activity_class)
            .bind(arguments.as_deref()).bind(queue).bind(DB::document(&request.policy))
            .bind(DB::optional_time(schedule_deadline)).bind(DB::optional_time(close_deadline))
            .execute(&mut *tx).await?;
        let activity = Self::query("SELECT * FROM activity_executions WHERE id=$1")
            .bind(&execution_id)
            .fetch_one(&mut *tx)
            .await?;
        let scheduled_at = now();
        Self::append_at(&mut tx, &run_id, "ActivityScheduled", Self::activity_payload(&activity)?, None, None, Some(scheduled_at)).await?;
        // PHP dispatches this task when it creates it.
        let task_id = Self::create_task(&mut tx, &run_id, queue, "activity", json!({"activity_execution_id": execution_id})).await?;
        Self::create_activity_wait(&mut tx, &run_id, &execution_id, &task_id, scheduled_at).await?;
        tx.summary_changed(&run_id);
        tx.commit().await?;
        self.wake.notify_waiters();
        let time = |at: Option<chrono::DateTime<chrono::Utc>>| super::task_queues::json_time(at);
        Ok(json!({"activity_id":activity_id,"activity_execution_id":execution_id,"workflow_id":activity_id,
            "workflow_run_id":run_id,"workflow_type":HOST_TYPE,"activity_type":request.activity_type,
            "activity_class":request.activity_class,"task_queue":queue,"namespace":self.namespace.as_ref(),
            "status":"running","payload_codec":"avro","started_at":time(Some(started_at)),
            "schedule_to_start_deadline_at":time(schedule_deadline),"schedule_to_close_deadline_at":time(close_deadline),
            "command_status":"accepted","command_source":"control_plane"}))
    }

    /// The current host run of `activity_id` in this namespace and its
    /// activity, as the describe and list views read them.
    pub(crate) async fn standalone_host(&self, activity_id: &str) -> Result<(Value, Value)> {
        let run = Self::query("SELECT r.* FROM workflow_instances i JOIN workflow_runs r ON r.id=i.current_run_id WHERE i.id=$1 AND i.namespace=$2")
            .bind(activity_id).bind(self.namespace.as_ref()).fetch_optional(&self.pool).await?
            .ok_or_else(not_found)?;
        if DB::string(&run, "workflow_type")? != HOST_TYPE && DB::string(&run, "workflow_class")? != HOST_TYPE {
            return Err(not_found());
        }
        self.host_view(&run).await
    }

    /// One page of host runs, newest first, as the run list orders them.
    pub(crate) async fn standalone_hosts(&self, request: &ListRequest) -> Result<(Vec<(Value, Value)>, bool)> {
        let filter = super::visibility::VisibilityFilter::of_type(HOST_TYPE, request.status.clone(), request.page_size, request.offset);
        let rows = self.visibility_rows(&filter, request.page_size + 1).await?;
        let more = rows.len() as i64 > request.page_size;
        let mut views = Vec::new();
        for row in rows.iter().take(request.page_size as usize) {
            let run = Self::query("SELECT * FROM workflow_runs WHERE id=$1")
                .bind(DB::string(row, "id")?)
                .fetch_one(&self.pool)
                .await?;
            views.push(self.host_view(&run).await?);
        }
        Ok((views, more))
    }

    async fn host_view(&self, run: &DB::Row) -> Result<(Value, Value)> {
        let time = |at: Option<chrono::DateTime<chrono::Utc>>| super::task_queues::json_time(at);
        let status = DB::string(run, "status")?;
        let host = json!({"workflow_instance_id":DB::string(run,"workflow_instance_id")?,"id":DB::string(run,"id")?,
            "workflow_type":DB::string(run,"workflow_type")?,"queue":DB::string(run,"queue")?,
            "business_key":DB::optional_string(run,"business_key")?,"status":status,
            "status_bucket":super::visibility::status_bucket(&status)?,
            "closed_reason":DB::optional_string(run,"closed_reason")?,
            "started_at":time(DB::optional_instant(run,"started_at")?),"closed_at":time(DB::optional_instant(run,"closed_at")?),
            "last_progress_at":time(DB::optional_instant(run,"last_progress_at")?),
            "compatibility":DB::optional_string(run,"compatibility")?,"payload_codec":DB::optional_string(run,"payload_codec")?});
        let execution = match Self::query("SELECT * FROM activity_executions WHERE workflow_run_id=$1 ORDER BY sequence LIMIT 1")
            .bind(DB::string(run, "id")?)
            .fetch_optional(&self.pool)
            .await?
        {
            None => Value::Null,
            Some(row) => json!({"id":DB::string(&row,"id")?,"activity_type":DB::string(&row,"activity_type")?,
                "activity_class":DB::string(&row,"activity_class")?,"status":DB::string(&row,"status")?,
                "attempt_count":DB::number(&row,"attempt_count")?,"current_attempt_id":DB::optional_string(&row,"current_attempt_id")?,
                "schedule_deadline_at":time(DB::optional_instant(&row,"schedule_deadline_at")?),
                "schedule_to_close_deadline_at":time(DB::optional_instant(&row,"schedule_to_close_deadline_at")?),
                "last_heartbeat_at":time(DB::optional_instant(&row,"last_heartbeat_at")?),
                "result":DB::optional_string(&row,"result")?}),
        };
        Ok((host, execution))
    }

    /// Closes a standalone activity's host run with its activity's terminal
    /// outcome (`closeStandaloneHostRun`, `closeStandaloneHostRunAfterTimeout`)
    /// instead of resuming workflow code. Another run is left untouched.
    pub(super) async fn close_standalone_host(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        close: HostClose<'_>,
    ) -> Result<bool> {
        let run = Self::query("SELECT workflow_type,workflow_class FROM workflow_runs WHERE id=$1")
            .bind(run_id)
            .fetch_one(&mut **tx)
            .await?;
        if DB::string(&run, "workflow_type")? != HOST_TYPE && DB::string(&run, "workflow_class")? != HOST_TYPE {
            return Ok(false);
        }
        let at = now();
        match close {
            HostClose::Completed { output } => {
                Self::query("UPDATE workflow_runs SET status='completed',closed_reason='completed',output=$1,closed_at=$2,last_progress_at=$3 WHERE id=$4")
                    .bind(output).bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(run_id).execute(&mut **tx).await?;
                Self::append(tx, run_id, "WorkflowCompleted", json!({"output":wire(output),"payload_codec":"avro"}), None, None).await?;
            }
            HostClose::Failed { payload, closed_reason } => {
                Self::query("UPDATE workflow_runs SET status='failed',closed_reason=$1,closed_at=$2,last_progress_at=$3 WHERE id=$4")
                    .bind(closed_reason).bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(run_id).execute(&mut **tx).await?;
                Self::append(tx, run_id, "WorkflowFailed", payload, None, None).await?;
            }
        }
        tx.summary_changed(run_id);
        Ok(true)
    }
}

/// `closeStandaloneHostRun`'s failure payload from the activity's terminal
/// event: its failure fields, with nulls dropped.
pub(super) fn failed_payload(event: &Value, activity_id: &str) -> Value {
    let mut payload = json!({"failure_id":event["failure_id"],"source_kind":"activity_execution","source_id":activity_id,
        "failure_category":event["failure_category"],"non_retryable":event["non_retryable"],
        "exception_type":event["exception_type"],"exception_class":event["exception_class"],
        "message":event["message"],"exception":event["exception"]});
    payload.as_object_mut().expect("payload object").retain(|_, value| !value.is_null());
    payload
}

/// `closeStandaloneHostRunAfterTimeout`'s payload.
pub(super) fn timed_out_payload(failure_id: &str, activity_id: &str, category: &str, non_retryable: bool, class: &str, message: &str) -> Value {
    json!({"failure_id":failure_id,"source_kind":"activity_execution","source_id":activity_id,
        "failure_category":category,"non_retryable":non_retryable,"exception_type":null,
        "exception_class":class,"message":message,
        "exception":{"class":class,"message":message,"code":0,"file":"","line":0,"trace":[],"properties":[]}})
}

/// `ActivityController::formatAttempt` over one attempt of the run's
/// activity view.
fn attempt(view: &Value, execution: &Value) -> Value {
    let id = view["id"].as_str().filter(|id| !id.is_empty());
    let task = view["task_id"].as_str().filter(|id| !id.is_empty());
    json!({"id":id,"activity_attempt_id":id,"activity_execution_id":execution["id"],"workflow_task_id":task,"task_id":task,
        "attempt_number":view["attempt_number"].as_i64(),"status":view["status"].as_str(),
        "lease_owner":view["lease_owner"].as_str(),"lease_expires_at":view["lease_expires_at"],
        "started_at":view["started_at"],"last_heartbeat_at":view["last_heartbeat_at"],
        "last_heartbeat_progress":if view["last_heartbeat_progress"].is_object() || view["last_heartbeat_progress"].is_array() {
            view["last_heartbeat_progress"].clone() } else { Value::Null },
        "closed_at":view["closed_at"],"can_continue":view["can_continue"] == true,
        "cancel_requested":view["cancel_requested"] == true,"stop_reason":view["stop_reason"].as_str(),
        "is_current":id.is_some() && id == execution["current_attempt_id"].as_str()})
}

/// The attempt fields every describe and list entry carries.
pub(super) fn attempt_fields(execution: &Value, activities: &Value) -> Value {
    let attempts: Vec<Value> = activities
        .as_array()
        .and_then(|activities| activities.iter().find(|activity| !execution.is_null() && activity["id"] == execution["id"]))
        .and_then(|activity| activity["attempts"].as_array())
        .map(|attempts| attempts.iter().filter(|attempt| attempt.is_object()).map(|view| attempt(view, execution)).collect())
        .unwrap_or_default();
    let current = attempts
        .iter()
        .find(|attempt| attempt["is_current"] == true)
        .or(attempts.last())
        .cloned()
        .unwrap_or(Value::Null);
    let current_id = if current.is_null() { execution["current_attempt_id"].clone() } else { current["activity_attempt_id"].clone() };
    let current_status = current["status"].clone();
    json!({"activity_execution_id":execution["id"],"attempt_count":execution["attempt_count"],
        "current_attempt_id":current_id,"current_attempt_status":current_status,"current_attempt":current,
        "attempts":attempts})
}
