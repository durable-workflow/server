//! Worker sessions, as published Server 2.11.1 serves them
//! (`WorkerSessionController` and `WorkerSessionRegistry`): a worker leases a
//! named session in its namespace, renews it with heartbeats, and closes it.
//! A session's lease and absolute TTL expire only when the session is read;
//! a holder whose registration is gone or silent leaves the session orphaned
//! on the next read. Session times are whole seconds, as PHP stores them.
//!
//! One published defect is not reproduced: PHP's `renewHeldSession` filters
//! its own `failure_reason => null` out, so a reused or reacquired session
//! keeps its old failure reason (Server #737). Native clears it, as that code
//! intends; the shared fixture bounds the difference explicitly.
use super::laravel_validation::{Rule, failure_messages, rule_errors};
use super::transaction::Transaction;
use super::{Result, RuntimeError, store::now};
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use serde_json::{Map, Value, json};

const DEFAULT_LEASE_SECONDS: i64 = 120;
const DEFAULT_TTL_SECONDS: i64 = 1800;
const DEFAULT_MAX_CONCURRENT_ACTIVITIES: i64 = 1;
const MINIMUM_PROTOCOL: (u64, u64) = (1, 8);

const SESSION_BY_ID: &str = "SELECT id,session_id,connection,queue,requirements,status,lease_owner,lease_expires_at,ttl_expires_at,closed_at,failure_reason,lease_seconds,ttl_seconds,max_concurrent_activities,CASE WHEN create_if_missing THEN 1 ELSE 0 END AS create_if_missing_flag,CASE WHEN allow_reacquire_after_failure THEN 1 ELSE 0 END AS allow_reacquire_flag,last_heartbeat_at,created_at,updated_at FROM workflow_worker_sessions WHERE namespace=$1 AND session_id=$2";
const SESSIONS_TO_REFRESH: &str = "SELECT session_id FROM workflow_worker_sessions WHERE namespace=$1 AND (status='active' OR (status<>'closed' AND ttl_expires_at<=$2 AND (failure_reason IS NULL OR failure_reason<>'ttl_expired'))) ORDER BY id";
const SESSIONS_LISTED: &str = "SELECT id,session_id,connection,queue,requirements,status,lease_owner,lease_expires_at,ttl_expires_at,closed_at,failure_reason,lease_seconds,ttl_seconds,max_concurrent_activities,CASE WHEN create_if_missing THEN 1 ELSE 0 END AS create_if_missing_flag,CASE WHEN allow_reacquire_after_failure THEN 1 ELSE 0 END AS allow_reacquire_flag,last_heartbeat_at,created_at,updated_at FROM workflow_worker_sessions WHERE namespace=$1 ORDER BY updated_at DESC,session_id ASC";
const HELD_SESSIONS: &str = "SELECT COUNT(*) FROM workflow_worker_sessions WHERE namespace=$1 AND lease_owner=$2 AND status='active' AND (lease_expires_at IS NULL OR lease_expires_at>$3) AND session_id<>$4";
const HOLDER: &str = "SELECT task_queue,status,last_heartbeat_at FROM workflow_worker_registrations WHERE namespace=$1 AND worker_id=$2";

/// A session time: PHP keeps whole seconds.
fn second(at: DateTime<Utc>) -> DateTime<Utc> {
    DateTime::from_timestamp(at.timestamp(), 0).expect("whole-second timestamp")
}

fn wire(at: Option<DateTime<Utc>>) -> Value {
    at.map_or(Value::Null, |at| json!(super::cooperative::timestamp(at)))
}

/// `rejectWorkerSessionsUnavailable`: worker sessions need protocol 1.8.
pub(super) fn sessions_gate(protocol: &str) -> Result<()> {
    let version = protocol.split_once('.').and_then(|(major, minor)| {
        let digits = |text: &str| !text.is_empty() && text.bytes().all(|byte| byte.is_ascii_digit());
        (digits(major) && digits(minor)).then(|| Some((major.parse::<u64>().ok()?, minor.parse::<u64>().ok()?))).flatten()
    });
    if version.is_some_and(|version| version >= MINIMUM_PROTOCOL) {
        return Ok(());
    }
    Err(RuntimeError::Protocol {
        status: StatusCode::CONFLICT,
        response: json!({"error":"Worker sessions require worker protocol 1.8 or newer.","reason":"worker_sessions_unavailable",
            "supported_version":super::WORKER_PROTOCOL_VERSION,"requested_version":protocol,"minimum_protocol_version":"1.8",
            "remediation":"Send the X-Durable-Workflow-Protocol-Version header with worker protocol 1.8 or newer and use a worker SDK that implements worker-session semantics."}),
    })
}

/// The controller's rules, after Laravel's TrimStrings and
/// ConvertEmptyStringsToNull.
fn validate(body: &Value, create: bool) -> Result<Value> {
    use Rule::{Array, Boolean, Integer, Max, Min, Nullable, Required};
    let body = super::laravel_rules::normalize(body);
    let mut rules: Vec<(&str, &[Rule])> = vec![("worker_id", &[Required, Rule::String, Max(255)])];
    if create {
        rules.extend([
            ("session_id", &[Required, Rule::String, Max(255)][..]),
            ("connection", &[Nullable, Rule::String, Max(255)][..]),
            ("queue", &[Nullable, Rule::String, Max(255)][..]),
            ("requirements", &[Nullable, Array][..]),
        ]);
    }
    rules.push(("lease_seconds", &[Nullable, Integer, Min(1)]));
    if create {
        rules.extend([
            ("ttl_seconds", &[Nullable, Integer, Min(1)][..]),
            ("max_concurrent_activities", &[Nullable, Integer, Min(1)][..]),
            ("create_if_missing", &[Nullable, Boolean][..]),
            ("allow_reacquire_after_failure", &[Nullable, Boolean][..]),
        ]);
    }
    let mut errors = rule_errors(&body, &rules, |_, _| None);
    // `requirements.*`: each a string of at most 255 characters, reported
    // after `requirements` itself.
    if let Some(items) = body.get("requirements").and_then(Value::as_array) {
        let at = errors.iter().position(|(field, _)| field == "requirements").map_or(errors.len(), |at| at + 1);
        let item_errors: Vec<(String, Vec<String>)> = items
            .iter()
            .enumerate()
            .map(|(index, item)| {
                let field = format!("requirements.{index}");
                let mut messages = Vec::new();
                match item {
                    Value::Null => {}
                    Value::String(text) if text.chars().count() > 255 => {
                        messages.push(format!("The {field} field must not be greater than 255 characters."));
                    }
                    Value::String(_) => {}
                    _ => messages.push(format!("The {field} field must be a string.")),
                }
                (field, messages)
            })
            .collect();
        errors.splice(at..at, item_errors);
    }
    if let Some(error) = failure_messages(errors) {
        return Err(error);
    }
    Ok(body)
}

/// `positiveInt`: an integer above zero, or a string of digits. A number
/// written with a fraction, even an integral one, is not.
fn positive_int(value: &Value) -> Option<i64> {
    match value {
        Value::Number(number) => number.as_i64().filter(|value| *value > 0),
        Value::String(text) if !text.is_empty() && text.bytes().all(|byte| byte.is_ascii_digit()) => {
            text.parse::<i64>().ok().filter(|value| *value > 0)
        }
        _ => None,
    }
}

/// `boolValue`, as Laravel's `boolean` admits.
fn bool_value(value: &Value, default: bool) -> bool {
    match value {
        Value::Bool(flag) => *flag,
        Value::Number(number) => number.as_i64().map_or(default, |value| value == 1),
        Value::String(text) => match text.as_str() {
            "1" => true,
            "0" => false,
            _ => default,
        },
        _ => default,
    }
}

fn string_value(value: &Value) -> Option<String> {
    value.as_str().map(str::trim).filter(|text| !text.is_empty()).map(str::to_owned)
}

/// `normalizeOptions`.
struct Options {
    session_id: String,
    connection: Option<String>,
    queue: Option<String>,
    requirements: Vec<String>,
    lease_seconds: i64,
    ttl_seconds: i64,
    max_concurrent_activities: i64,
    create_if_missing: bool,
    allow_reacquire_after_failure: bool,
}

impl Options {
    fn from(body: &Value, fallback_queue: Option<String>) -> Option<Self> {
        let mut requirements: Vec<String> = Vec::new();
        for item in body["requirements"].as_array().into_iter().flatten() {
            if let Some(text) = string_value(item)
                && !requirements.contains(&text)
            {
                requirements.push(text);
            }
        }
        Some(Self {
            session_id: string_value(&body["session_id"])?,
            connection: string_value(&body["connection"]),
            queue: string_value(&body["queue"]).or(fallback_queue),
            requirements,
            lease_seconds: positive_int(&body["lease_seconds"]).unwrap_or(DEFAULT_LEASE_SECONDS),
            ttl_seconds: positive_int(&body["ttl_seconds"]).unwrap_or(DEFAULT_TTL_SECONDS),
            max_concurrent_activities: positive_int(&body["max_concurrent_activities"]).unwrap_or(DEFAULT_MAX_CONCURRENT_ACTIVITIES),
            create_if_missing: bool_value(&body["create_if_missing"], true),
            allow_reacquire_after_failure: bool_value(&body["allow_reacquire_after_failure"], true),
        })
    }
}

/// A session row.
struct Session {
    id: i64,
    session_id: String,
    connection: Option<String>,
    queue: Option<String>,
    requirements: Vec<String>,
    status: String,
    lease_owner: Option<String>,
    lease_expires_at: Option<DateTime<Utc>>,
    ttl_expires_at: Option<DateTime<Utc>>,
    closed_at: Option<DateTime<Utc>>,
    failure_reason: Option<String>,
    lease_seconds: i64,
    ttl_seconds: i64,
    max_concurrent_activities: i64,
    create_if_missing: bool,
    allow_reacquire_after_failure: bool,
    last_heartbeat_at: Option<DateTime<Utc>>,
    created_at: Option<DateTime<Utc>>,
    updated_at: Option<DateTime<Utc>>,
}

/// A registry answer: the HTTP status and PHP's body, which repeats it.
pub(crate) struct Answer {
    pub(crate) status: StatusCode,
    pub(crate) body: Value,
}

fn success(outcome: &str, session: Value, status: StatusCode) -> Answer {
    Answer { status, body: json!({"admitted":true,"outcome":outcome,"session":session,"activity_task_id":null,
        "status":status.as_u16(),"reason":null}) }
}

fn failure(reason: &str, error: &str, status: StatusCode, context: Map<String, Value>) -> Answer {
    let mut body = context;
    body.insert("admitted".into(), json!(false));
    body.insert("error".into(), json!(error));
    body.insert("reason".into(), json!(reason));
    body.insert("status".into(), json!(status.as_u16()));
    Answer { status, body: Value::Object(body) }
}

fn with_session(session: Value) -> Map<String, Value> {
    Map::from_iter([("session".to_owned(), session)])
}

store_methods! {
    /// `POST /api/worker/sessions`.
    pub(crate) async fn create_worker_session(&self, body: Value, protocol: &str) -> Result<Answer> {
        sessions_gate(protocol)?;
        let body = validate(&body, true)?;
        let worker_id = body["worker_id"].as_str().unwrap_or_default().to_owned();
        let Some(worker) = Self::query("SELECT task_queue,capabilities,max_concurrent_worker_sessions FROM workflow_worker_registrations WHERE namespace=$1 AND worker_id=$2")
            .bind(self.namespace.as_ref()).bind(&worker_id).fetch_optional(&self.pool).await?
        else {
            return Err(RuntimeError::Protocol { status: StatusCode::PRECONDITION_FAILED,
                response: json!({"error":"Worker must be registered before creating a worker session.","reason":"worker_not_registered","worker_id":worker_id}) });
        };
        let task_queue = DB::string(&worker, "task_queue")?;
        if let Some(queue) = string_value(&body["queue"]).filter(|queue| *queue != task_queue) {
            return Err(RuntimeError::Protocol { status: StatusCode::CONFLICT,
                response: json!({"error":format!("Worker [{worker_id}] is registered for task queue [{task_queue}], not session queue [{queue}]."),
                    "reason":"session_queue_mismatch","worker_id":worker_id,"registered_task_queue":task_queue,"requested_session_queue":queue}) });
        }
        let Some(options) = Options::from(&body, Some(task_queue.clone())) else {
            return Err(RuntimeError::Protocol { status: StatusCode::UNPROCESSABLE_ENTITY,
                response: json!({"error":"Worker session requires a non-empty session_id.","reason":"invalid_worker_session"}) });
        };
        let capabilities: Vec<String> = DB::optional_document_row(&worker, "capabilities")?
            .as_ref()
            .and_then(Value::as_array)
            .into_iter()
            .flatten()
            .filter_map(|value| value.as_str().map(str::to_owned))
            .collect();
        let limit = DB::optional_number(&worker, "max_concurrent_worker_sessions")?.unwrap_or(10);
        let mut tx = self.begin().await?;
        let answer = self.acquire(&mut tx, &worker_id, &task_queue, &capabilities, limit, &options).await?;
        tx.commit().await?;
        Ok(answer)
    }

    /// `acquireLocked`.
    async fn acquire(
        &self,
        tx: &mut Transaction<'_, DB>,
        worker_id: &str,
        task_queue: &str,
        capabilities: &[String],
        limit: i64,
        options: &Options,
    ) -> Result<Answer> {
        if options.queue.as_deref().is_some_and(|queue| queue != task_queue) {
            return Ok(failure("session_queue_mismatch", "Worker session queue does not match the worker's task queue.",
                StatusCode::CONFLICT, Map::new()));
        }
        // Requirements are checked before the session is looked up.
        let missing: Vec<&String> = options.requirements.iter().filter(|requirement| !capabilities.contains(requirement)).collect();
        if !missing.is_empty() {
            return Ok(failure("session_requirements_not_met", "Worker does not advertise every worker-session requirement.",
                StatusCode::CONFLICT, Map::from_iter([("missing_requirements".to_owned(), json!(missing))])));
        }
        let Some(mut session) = self.locked_session(tx, &options.session_id).await? else {
            if !options.create_if_missing {
                return Ok(failure("session_missing", "Worker session does not exist and creation is disabled.",
                    StatusCode::CONFLICT, Map::new()));
            }
            if let Some(refused) = self.session_limit(tx, worker_id, limit, &options.session_id).await? {
                return Ok(refused);
            }
            let at = second(now());
            Self::query("INSERT INTO workflow_worker_sessions(namespace,session_id,connection,queue,requirements,status,lease_owner,lease_expires_at,ttl_expires_at,lease_seconds,ttl_seconds,max_concurrent_activities,create_if_missing,allow_reacquire_after_failure,last_heartbeat_at,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,'active',$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16)")
                .bind(self.namespace.as_ref()).bind(&options.session_id).bind(options.connection.as_deref()).bind(options.queue.as_deref())
                .bind(DB::document(&json!(options.requirements))).bind(worker_id)
                .bind(DB::bind_time(at + chrono::Duration::seconds(options.lease_seconds)))
                .bind(DB::bind_time(at + chrono::Duration::seconds(options.ttl_seconds)))
                .bind(options.lease_seconds).bind(options.ttl_seconds).bind(options.max_concurrent_activities)
                .bind(DB::optional_flag(Some(options.create_if_missing))).bind(DB::optional_flag(Some(options.allow_reacquire_after_failure)))
                .bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(DB::bind_time(at))
                .execute(&mut **tx).await?;
            let created = self.locked_session(tx, &options.session_id).await?.expect("the inserted session");
            return Ok(success("created", self.snapshot(tx, &created).await?, StatusCode::CREATED));
        };
        if session.status == "active" {
            if session.lease_owner.as_deref() != Some(worker_id) {
                let snapshot = self.snapshot(tx, &session).await?;
                return Ok(failure("session_owned_by_another_worker", "Worker session is currently leased by another worker.",
                    StatusCode::CONFLICT, with_session(snapshot)));
            }
            self.renew(tx, &mut session, options, worker_id).await?;
            return Ok(success("reused", self.snapshot(tx, &session).await?, StatusCode::OK));
        }
        if session.status == "closed" {
            let snapshot = self.snapshot(tx, &session).await?;
            return Ok(failure("session_closed", "Worker session was closed and cannot be reacquired.",
                StatusCode::CONFLICT, with_session(snapshot)));
        }
        if session.failure_reason.as_deref() == Some("ttl_expired") || !session.allow_reacquire_after_failure {
            let snapshot = self.snapshot(tx, &session).await?;
            return Ok(failure("session_reacquire_disallowed", "Worker session is terminal and cannot be reacquired.",
                StatusCode::CONFLICT, with_session(snapshot)));
        }
        if !options.allow_reacquire_after_failure {
            let snapshot = self.snapshot(tx, &session).await?;
            return Ok(failure("session_reacquire_disallowed", "Worker session options disallow reacquisition after holder failure.",
                StatusCode::CONFLICT, with_session(snapshot)));
        }
        if let Some(refused) = self.session_limit(tx, worker_id, limit, &options.session_id).await? {
            return Ok(refused);
        }
        self.renew(tx, &mut session, options, worker_id).await?;
        Ok(success("reacquired", self.snapshot(tx, &session).await?, StatusCode::OK))
    }

    /// The worker session limit, not counting this session.
    async fn session_limit(&self, tx: &mut Transaction<'_, DB>, worker_id: &str, limit: i64, session_id: &str) -> Result<Option<Answer>> {
        let held = Self::scalar(HELD_SESSIONS).bind(self.namespace.as_ref()).bind(worker_id).bind(DB::bind_time(now())).bind(session_id)
            .fetch_one(&mut **tx).await?;
        Ok((held >= limit).then(|| failure("worker_session_limit_exceeded", "Worker has reached max_concurrent_worker_sessions.",
            StatusCode::CONFLICT, Map::from_iter([("max_concurrent_worker_sessions".to_owned(), json!(limit))]))))
    }

    /// `renewHeldSession`. Options the request leaves out reset to their
    /// defaults; the TTL deadline is never extended; an omitted connection
    /// keeps the stored one. Unlike published PHP, the failure reason is
    /// cleared (Server #737).
    async fn renew(&self, tx: &mut Transaction<'_, DB>, session: &mut Session, options: &Options, worker_id: &str) -> Result<()> {
        let at = second(now());
        if options.connection.is_some() {
            session.connection.clone_from(&options.connection);
        }
        session.queue.clone_from(&options.queue);
        session.requirements.clone_from(&options.requirements);
        session.status = "active".into();
        session.lease_owner = Some(worker_id.to_owned());
        session.lease_expires_at = Some(at + chrono::Duration::seconds(options.lease_seconds));
        session.closed_at = None;
        session.failure_reason = None;
        session.lease_seconds = options.lease_seconds;
        session.ttl_seconds = options.ttl_seconds;
        session.max_concurrent_activities = options.max_concurrent_activities;
        session.create_if_missing = options.create_if_missing;
        session.allow_reacquire_after_failure = options.allow_reacquire_after_failure;
        session.last_heartbeat_at = Some(at);
        self.save(tx, session).await
    }

    /// `POST /api/worker/sessions/{sessionId}/heartbeat`.
    pub(crate) async fn heartbeat_worker_session(&self, session_id: &str, body: Value, protocol: &str) -> Result<Answer> {
        sessions_gate(protocol)?;
        let body = validate(&body, false)?;
        let worker_id = body["worker_id"].as_str().unwrap_or_default();
        let mut tx = self.begin().await?;
        let Some(mut session) = self.locked_session(&mut tx, session_id).await? else {
            tx.commit().await?;
            return Ok(failure("session_not_found", "Worker session was not found.", StatusCode::NOT_FOUND, Map::new()));
        };
        let answer = if session.status != "active" {
            failure("session_not_active", "Worker session is not active.", StatusCode::CONFLICT, with_session(self.snapshot(&mut tx, &session).await?))
        } else if session.lease_owner.as_deref() != Some(worker_id) {
            failure("session_owner_mismatch", "Worker session lease is owned by another worker.", StatusCode::CONFLICT,
                with_session(self.snapshot(&mut tx, &session).await?))
        } else {
            let at = second(now());
            let lease = super::laravel_validation::integer(&body["lease_seconds"]).unwrap_or(session.lease_seconds).max(1);
            session.lease_expires_at = Some(at + chrono::Duration::seconds(lease));
            session.last_heartbeat_at = Some(at);
            session.failure_reason = None;
            self.save(&mut tx, &session).await?;
            success("heartbeat_recorded", self.snapshot(&mut tx, &session).await?, StatusCode::OK)
        };
        tx.commit().await?;
        Ok(answer)
    }

    /// `DELETE /api/worker/sessions/{sessionId}`.
    pub(crate) async fn close_worker_session(&self, session_id: &str, body: Value, protocol: &str) -> Result<Answer> {
        sessions_gate(protocol)?;
        let body = validate(&body, false)?;
        let worker_id = body["worker_id"].as_str().unwrap_or_default();
        let mut tx = self.begin().await?;
        let Some(mut session) = self.locked_session(&mut tx, session_id).await? else {
            tx.commit().await?;
            return Ok(failure("session_not_found", "Worker session was not found.", StatusCode::NOT_FOUND, Map::new()));
        };
        let owner = session.lease_owner.as_deref() == Some(worker_id);
        let answer = if session.status == "closed" && owner {
            success("already_closed", self.snapshot(&mut tx, &session).await?, StatusCode::OK)
        } else if !owner {
            failure("session_owner_mismatch", "Worker session lease is owned by another worker.", StatusCode::CONFLICT,
                with_session(self.snapshot(&mut tx, &session).await?))
        } else {
            session.status = "closed".into();
            session.closed_at = Some(second(now()));
            session.failure_reason = None;
            self.save(&mut tx, &session).await?;
            success("closed", self.snapshot(&mut tx, &session).await?, StatusCode::OK)
        };
        tx.commit().await?;
        Ok(answer)
    }

    /// `GET /api/worker-sessions`: refreshes the namespace's sessions, then
    /// lists them, newest change first, with the status counts.
    pub(crate) async fn list_worker_sessions(&self) -> Result<Value> {
        self.refresh_namespace_sessions().await?;
        let mut tx = self.begin().await?;
        let rows = Self::query(SESSIONS_LISTED).bind(self.namespace.as_ref()).fetch_all(&mut *tx).await?;
        let mut sessions = Vec::new();
        let mut metrics = json!({"total":0,"active":0,"closed":0,"expired":0,"failed":0,"orphaned":0});
        for row in &rows {
            let session = Self::session(row)?;
            metrics["total"] = json!(metrics["total"].as_i64().unwrap_or(0) + 1);
            if let Some(count) = metrics.get_mut(session.status.as_str()) {
                *count = json!(count.as_i64().unwrap_or(0) + 1);
            }
            sessions.push(self.snapshot(&mut tx, &session).await?);
        }
        tx.commit().await?;
        Ok(json!({"namespace":self.namespace.as_ref(),"metrics":metrics,"sessions":sessions}))
    }

    /// `GET /api/worker-sessions/{sessionId}`.
    pub(crate) async fn show_worker_session(&self, session_id: &str) -> Result<Value> {
        self.refresh_namespace_sessions().await?;
        let mut tx = self.begin().await?;
        let row = Self::query(SESSION_BY_ID).bind(self.namespace.as_ref()).bind(session_id).fetch_optional(&mut *tx).await?;
        let Some(row) = row else {
            tx.commit().await?;
            return Err(RuntimeError::Protocol { status: StatusCode::NOT_FOUND,
                response: json!({"message":format!("Worker session [{session_id}] not found in namespace [{}].", self.namespace),
                    "reason":"worker_session_not_found"}) });
        };
        let session = Self::session(&row)?;
        let snapshot = self.snapshot(&mut tx, &session).await?;
        tx.commit().await?;
        Ok(json!({"namespace":self.namespace.as_ref(),"session":snapshot}))
    }

    /// `refreshNamespace`: each session that may have expired, in its own
    /// transaction.
    async fn refresh_namespace_sessions(&self) -> Result<()> {
        let ids = Self::query(SESSIONS_TO_REFRESH).bind(self.namespace.as_ref()).bind(DB::bind_time(now())).fetch_all(&self.pool).await?;
        for row in ids {
            let mut tx = self.begin().await?;
            self.locked_session(&mut tx, &DB::string(&row, "session_id")?).await?;
            tx.commit().await?;
        }
        Ok(())
    }

    /// The session, refreshed (`refreshStatusLocked`).
    async fn locked_session(&self, tx: &mut Transaction<'_, DB>, session_id: &str) -> Result<Option<Session>> {
        let Some(row) = Self::query(SESSION_BY_ID).bind(self.namespace.as_ref()).bind(session_id).fetch_optional(&mut **tx).await? else {
            return Ok(None);
        };
        let mut session = Self::session(&row)?;
        if session.status == "closed" || session.failure_reason.as_deref() == Some("ttl_expired") {
            return Ok(Some(session));
        }
        let at = now();
        let change = if session.ttl_expires_at.is_some_and(|ttl| ttl <= at) {
            Some(("expired", "ttl_expired"))
        } else if session.status != "active" {
            None
        } else if session.lease_expires_at.is_some_and(|lease| lease <= at) {
            Some(("expired", "lease_expired"))
        } else if self.holder_stale(tx, session.lease_owner.as_deref()).await? {
            Some(("orphaned", "worker_heartbeat_stale"))
        } else {
            None
        };
        if let Some((status, reason)) = change {
            session.status = status.into();
            session.failure_reason = Some(reason.into());
            self.save(tx, &session).await?;
        }
        Ok(Some(session))
    }

    /// `holderIsStale`: no registration, no heartbeat, or a heartbeat older
    /// than the stale window.
    async fn holder_stale(&self, tx: &mut Transaction<'_, DB>, owner: Option<&str>) -> Result<bool> {
        let Some(owner) = owner else {
            return Ok(true);
        };
        let row = Self::query(HOLDER).bind(self.namespace.as_ref()).bind(owner).fetch_optional(&mut **tx).await?;
        let heartbeat = match row {
            Some(row) => DB::optional_instant(&row, "last_heartbeat_at")?,
            None => None,
        };
        Ok(heartbeat.is_none_or(|heartbeat| heartbeat < now() - chrono::Duration::seconds(self.worker_stale_after_seconds.max(1))))
    }

    async fn save(&self, tx: &mut Transaction<'_, DB>, session: &Session) -> Result<()> {
        Self::query("UPDATE workflow_worker_sessions SET connection=$1,queue=$2,requirements=$3,status=$4,lease_owner=$5,lease_expires_at=$6,ttl_expires_at=$7,closed_at=$8,failure_reason=$9,lease_seconds=$10,ttl_seconds=$11,max_concurrent_activities=$12,create_if_missing=$13,allow_reacquire_after_failure=$14,last_heartbeat_at=$15,updated_at=$16 WHERE id=$17")
            .bind(session.connection.as_deref()).bind(session.queue.as_deref()).bind(DB::document(&json!(session.requirements)))
            .bind(session.status.as_str()).bind(session.lease_owner.as_deref())
            .bind(DB::optional_time(session.lease_expires_at)).bind(DB::optional_time(session.ttl_expires_at)).bind(DB::optional_time(session.closed_at))
            .bind(session.failure_reason.as_deref()).bind(session.lease_seconds).bind(session.ttl_seconds).bind(session.max_concurrent_activities)
            .bind(DB::optional_flag(Some(session.create_if_missing))).bind(DB::optional_flag(Some(session.allow_reacquire_after_failure)))
            .bind(DB::optional_time(session.last_heartbeat_at)).bind(DB::bind_time(second(now()))).bind(session.id)
            .execute(&mut **tx).await?;
        Ok(())
    }

    fn session(row: &DB::Row) -> Result<Session> {
        let requirements = DB::optional_document_row(row, "requirements")?
            .as_ref()
            .and_then(Value::as_array)
            .into_iter()
            .flatten()
            .filter_map(|value| value.as_str().map(str::to_owned))
            .collect();
        Ok(Session {
            id: DB::number(row, "id")?,
            session_id: DB::string(row, "session_id")?,
            connection: DB::optional_string(row, "connection")?,
            queue: DB::optional_string(row, "queue")?,
            requirements,
            status: DB::string(row, "status")?,
            lease_owner: DB::optional_string(row, "lease_owner")?,
            lease_expires_at: DB::optional_instant(row, "lease_expires_at")?,
            ttl_expires_at: DB::optional_instant(row, "ttl_expires_at")?,
            closed_at: DB::optional_instant(row, "closed_at")?,
            failure_reason: DB::optional_string(row, "failure_reason")?,
            lease_seconds: DB::number(row, "lease_seconds")?,
            ttl_seconds: DB::number(row, "ttl_seconds")?,
            max_concurrent_activities: DB::number(row, "max_concurrent_activities")?,
            create_if_missing: DB::number(row, "create_if_missing_flag")? == 1,
            allow_reacquire_after_failure: DB::number(row, "allow_reacquire_flag")? == 1,
            last_heartbeat_at: DB::optional_instant(row, "last_heartbeat_at")?,
            created_at: DB::optional_instant(row, "created_at")?,
            updated_at: DB::optional_instant(row, "updated_at")?,
        })
    }

    /// PHP's session snapshot, with its holder read from the registration.
    async fn snapshot(&self, tx: &mut Transaction<'_, DB>, session: &Session) -> Result<Value> {
        let holder = match session.lease_owner.as_deref() {
            None => Value::Null,
            Some(owner) => {
                let row = Self::query(HOLDER).bind(self.namespace.as_ref()).bind(owner).fetch_optional(&mut **tx).await?;
                let (task_queue, status, heartbeat) = match &row {
                    Some(row) => (DB::optional_string(row, "task_queue")?, DB::optional_string(row, "status")?, DB::optional_instant(row, "last_heartbeat_at")?),
                    None => (None, None, None),
                };
                let stale = heartbeat.is_none_or(|heartbeat| heartbeat < now() - chrono::Duration::seconds(self.worker_stale_after_seconds.max(1)));
                json!({"worker_id":owner,"task_queue":task_queue,"status":status,"last_heartbeat_at":wire(heartbeat),"is_stale":stale})
            }
        };
        Ok(json!({"session_id":session.session_id,"namespace":self.namespace.as_ref(),"connection":session.connection,"queue":session.queue,
            "requirements":session.requirements,"status":session.status,"lease_owner":session.lease_owner,"holder":holder,
            "lease_expires_at":wire(session.lease_expires_at),"ttl_expires_at":wire(session.ttl_expires_at),"closed_at":wire(session.closed_at),
            "failure_reason":session.failure_reason,"lease_seconds":session.lease_seconds,"ttl_seconds":session.ttl_seconds,
            "max_concurrent_activities":session.max_concurrent_activities,"create_if_missing":session.create_if_missing,
            "allow_reacquire_after_failure":session.allow_reacquire_after_failure,"active_activity_count":0,
            "last_heartbeat_at":wire(session.last_heartbeat_at),"created_at":wire(session.created_at),"updated_at":wire(session.updated_at)}))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn sessions_need_protocol_one_eight() {
        assert!(sessions_gate("1.8").is_ok());
        assert!(sessions_gate("1.20").is_ok());
        assert!(sessions_gate("1.10").is_ok());
        assert!(sessions_gate("1.7").is_err());
        assert!(sessions_gate("1.x").is_err());
        assert!(sessions_gate("+1.9").is_err());
    }

    #[test]
    fn options_read_like_php() {
        assert_eq!(positive_int(&json!("60")), Some(60));
        assert_eq!(positive_int(&json!(7)), Some(7));
        assert_eq!(positive_int(&serde_json::from_str::<Value>("7.0").unwrap()), None);
        assert_eq!(positive_int(&json!("0")), None);
        let options = Options::from(&json!({"session_id":"s","requirements":["gpu",null,"gpu","tpu"]}), Some("q".into())).unwrap();
        assert_eq!(options.requirements, ["gpu", "tpu"]);
        assert_eq!((options.queue.as_deref(), options.lease_seconds, options.ttl_seconds), (Some("q"), 120, 1800));
    }
}
