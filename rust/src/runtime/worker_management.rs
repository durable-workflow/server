//! Worker registration facts and the worker management reads, as published
//! PHP's WorkerController and WorkerManagementController report them.
use super::transaction::Transaction;
use super::{
    QueryTaskConfig, Result, RuntimeError,
    backend::Backend,
    refuse,
    store::{Store, now},
};
use axum::http::StatusCode;
use chrono::{DateTime, SecondsFormat, Utc};
use serde_json::{Map, Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::collections::BTreeMap;

const PROCESS_METRICS: [&str; 6] = [
    "cpu_percent",
    "memory_bytes",
    "process_uptime_seconds",
    "process_id",
    "host",
    "process_started_at",
];
/// A nullable count column: its field in task_slots, then the static
/// statements that set it and clear it. Values are always bound.
struct Count {
    field: &'static str,
    set: &'static str,
    clear: &'static str,
}
const SLOTS: [Count; 3] = [
    Count {
        field: "workflow_available",
        set: "UPDATE workflow_worker_registrations SET available_workflow_slots=$1 WHERE worker_id=$2 AND namespace=$3",
        clear: "UPDATE workflow_worker_registrations SET available_workflow_slots=NULL WHERE worker_id=$1 AND namespace=$2",
    },
    Count {
        field: "activity_available",
        set: "UPDATE workflow_worker_registrations SET available_activity_slots=$1 WHERE worker_id=$2 AND namespace=$3",
        clear: "UPDATE workflow_worker_registrations SET available_activity_slots=NULL WHERE worker_id=$1 AND namespace=$2",
    },
    Count {
        field: "session_available",
        set: "UPDATE workflow_worker_registrations SET available_session_slots=$1 WHERE worker_id=$2 AND namespace=$3",
        clear: "UPDATE workflow_worker_registrations SET available_session_slots=NULL WHERE worker_id=$1 AND namespace=$2",
    },
];
const INTERVAL: Count = Count {
    field: "heartbeat_interval_seconds",
    set: "UPDATE workflow_worker_registrations SET heartbeat_interval_seconds=$1 WHERE worker_id=$2 AND namespace=$3",
    clear: "UPDATE workflow_worker_registrations SET heartbeat_interval_seconds=NULL WHERE worker_id=$1 AND namespace=$2",
};

fn invalid() -> RuntimeError {
    refuse(
        StatusCode::UNPROCESSABLE_ENTITY,
        "invalid_worker_definition",
    )
}

/// Laravel's `integer` rule: a JSON integer or a string of digits.
fn integer(value: &Value) -> Option<i64> {
    value.as_i64().or_else(|| {
        let text = value.as_str()?;
        let digits = text.strip_prefix('-').unwrap_or(text);
        (!digits.is_empty() && digits.bytes().all(|b| b.is_ascii_digit()))
            .then(|| text.parse().ok())
            .flatten()
    })
}

/// A nullable integer field with inclusive bounds; absent and null are None.
fn bounded(body: &Value, field: &str, min: i64, max: i64) -> Result<Option<i64>> {
    match body.get(field) {
        None | Some(Value::Null) => Ok(None),
        Some(value) => integer(value)
            .filter(|n| (min..=max).contains(n))
            .map(Some)
            .ok_or_else(invalid),
    }
}

/// PHP's boundedSlotCount: only an integer counts, clamped to the capacity.
fn slot(value: &Value, capacity: i64) -> Option<i64> {
    let count = value.as_i64().or_else(|| {
        let text = value.as_str()?;
        (!text.is_empty() && text.bytes().all(|b| b.is_ascii_digit()))
            .then(|| text.parse().ok())
            .flatten()
    })?;
    Some(count.max(0).min(capacity.max(0)))
}

/// The key under which PHP records a worker's protocol version.
pub(super) const PROTOCOL_METRIC: &str = "_worker_protocol_version";
const SLOT_OBSERVATIONS_METRIC: &str = "_task_slot_observations";

fn record_slot_observation(observations: &mut Map<String, Value>, field: &str, count: Option<i64>, at: DateTime<Utc>) {
    if !matches!(field, "workflow_available" | "activity_available") {
        return;
    }
    if count.is_some() {
        observations.insert(field.to_owned(), json!(at.timestamp_millis()));
    } else {
        observations.remove(field);
    }
}

fn store_slot_observations(metrics: &mut Value, observations: Map<String, Value>) {
    if observations.is_empty() {
        if let Some(metrics) = metrics.as_object_mut() {
            metrics.remove(SLOT_OBSERVATIONS_METRIC);
        }
    } else {
        metrics[SLOT_OBSERVATIONS_METRIC] = Value::Object(observations);
    }
}

/// A later heartbeat may omit slot counts. Its liveness timestamp does not
/// make an earlier slot observation current again.
pub(super) fn slot_observation_is_fresh(metrics: &Value, field: &str, at: DateTime<Utc>, stale_after: i64) -> bool {
    let at = at.timestamp_millis();
    metrics[SLOT_OBSERVATIONS_METRIC][field].as_i64().is_some_and(|reported| {
        reported <= at && reported > at.saturating_sub(stale_after.saturating_mul(1000))
    })
}

/// Process metrics with the request's protocol version added, as PHP's
/// `[...$metrics, PROTOCOL_METRIC => requestVersion]`.
pub(super) fn with_protocol(metrics: Option<Value>, protocol: Option<&str>) -> Value {
    let mut metrics = match metrics {
        Some(Value::Object(metrics)) => metrics,
        _ => Map::new(),
    };
    metrics.insert(PROTOCOL_METRIC.into(), json!(protocol));
    Value::Object(metrics)
}

/// Validates the process metrics a worker reports and keeps PHP's normalized
/// subset. Absent means "not reported"; the outer Option is presence.
fn process_metrics(body: &Value) -> Result<Option<Option<Value>>> {
    let Some(value) = body.get("process_metrics") else {
        return Ok(None);
    };
    if value.is_null() {
        return Ok(Some(None));
    }
    // A JSON list is a PHP array too; it holds none of the named metrics.
    let empty = Map::new();
    let metrics = match value {
        Value::Object(metrics) => metrics,
        Value::Array(_) => &empty,
        _ => return Err(invalid()),
    };
    let mut normalized = Map::new();
    for key in PROCESS_METRICS {
        let Some(entry) = metrics.get(key).filter(|v| !v.is_null()) else {
            continue;
        };
        match key {
            "host" | "process_started_at" => {
                let limit = if key == "host" { 255 } else { 64 };
                let text = entry.as_str().ok_or_else(invalid)?;
                if text.chars().count() > limit {
                    return Err(invalid());
                }
                let trimmed = text.trim();
                if !trimmed.is_empty() {
                    normalized.insert(
                        key.into(),
                        json!(trimmed.chars().take(255).collect::<String>()),
                    );
                }
            }
            "cpu_percent" => {
                // PHP validates a numeric string but stores only a number.
                let number = entry
                    .as_f64()
                    .or_else(|| entry.as_str()?.trim().parse::<f64>().ok())
                    .filter(|n| n.is_finite() && *n >= 0.0)
                    .ok_or_else(invalid)?;
                if entry.is_number() {
                    normalized.insert(key.into(), json!(number));
                }
            }
            _ => {
                let number = integer(entry).filter(|n| *n >= 0).ok_or_else(invalid)?;
                normalized.insert(key.into(), json!(number));
            }
        }
    }
    Ok(Some(
        (!normalized.is_empty()).then(|| Value::Object(normalized)),
    ))
}

/// PHP's workflowDefinitionFingerprints: trimmed non-empty string pairs.
fn fingerprints(value: &Value) -> BTreeMap<String, String> {
    let pairs: Vec<(String, &Value)> = match value {
        Value::Object(map) => map.iter().map(|(k, v)| (k.clone(), v)).collect(),
        Value::Array(list) => list
            .iter()
            .enumerate()
            .map(|(i, v)| (i.to_string(), v))
            .collect(),
        _ => Vec::new(),
    };
    pairs
        .into_iter()
        .filter_map(|(workflow_type, fingerprint)| {
            let workflow_type = workflow_type.trim();
            let fingerprint = fingerprint.as_str()?.trim();
            (!workflow_type.is_empty() && !fingerprint.is_empty())
                .then(|| (workflow_type.to_owned(), fingerprint.to_owned()))
        })
        .collect()
}

/// Published PHP stores and renders an empty map as a JSON list.
fn php_map(map: &BTreeMap<String, String>) -> Value {
    if map.is_empty() {
        json!([])
    } else {
        json!(map)
    }
}

fn definition_refusal(reason: &str, workflow_type: &str) -> RuntimeError {
    let (error, remediation) = if reason == "workflow_definition_changed" {
        (
            "Worker attempted to re-register a changed workflow definition.",
            "Restart the worker with a new worker_id before registering a changed workflow class definition.",
        )
    } else {
        (
            "Worker omitted a previously advertised workflow definition fingerprint.",
            "Advertise the matching definition fingerprint or restart with a new worker_id.",
        )
    };
    RuntimeError::Protocol {
        status: StatusCode::CONFLICT,
        response: json!({"error": error, "reason": reason, "workflow_type": workflow_type,
            "remediation": remediation}),
    }
}

/// The registration facts PHP keeps beside a worker's identity.
pub(super) struct Registration {
    fingerprints: Option<BTreeMap<String, String>>,
    capacity: [i64; 3],
    slots: [Option<i64>; 3],
    process_metrics: Option<Value>,
    heartbeat_interval: Option<i64>,
}

impl Registration {
    /// `WorkflowReplayEligibility::PROTOCOL_METRIC`: PHP keeps the protocol
    /// version of each registration and heartbeat in the worker's process
    /// metrics, after the metrics the worker reported.
    pub(super) fn record_protocol(&mut self, protocol: Option<&str>) {
        self.process_metrics = Some(with_protocol(self.process_metrics.take(), protocol));
    }

    pub(super) fn parse(body: &Value) -> Result<Self> {
        let fingerprints = match body.get("workflow_definition_fingerprints") {
            None => None,
            Some(Value::Null) => Some(BTreeMap::new()),
            Some(value @ (Value::Object(_) | Value::Array(_))) => {
                let entries: Vec<&Value> = match value {
                    Value::Object(map) => map.values().collect(),
                    Value::Array(list) => list.iter().collect(),
                    _ => unreachable!(),
                };
                if entries
                    .iter()
                    .any(|v| v.as_str().is_none_or(|s| s.chars().count() > 255))
                {
                    return Err(invalid());
                }
                Some(fingerprints(value))
            }
            Some(_) => return Err(invalid()),
        };
        let capacity = [
            bounded(
                body,
                "max_concurrent_workflow_tasks",
                0,
                i64::from(u32::MAX),
            )?
            .unwrap_or(100),
            bounded(
                body,
                "max_concurrent_activity_tasks",
                0,
                i64::from(u32::MAX),
            )?
            .unwrap_or(100),
            bounded(
                body,
                "max_concurrent_worker_sessions",
                1,
                i64::from(u32::MAX),
            )?
            .unwrap_or(10),
        ];
        if capacity[0] == 0 && capacity[1] == 0 {
            return Err(invalid());
        }
        let slots = task_slots(body, capacity)?.unwrap_or([None; 3]);
        Ok(Self {
            fingerprints,
            capacity,
            slots,
            process_metrics: process_metrics(body)?.flatten(),
            heartbeat_interval: bounded(body, "heartbeat_interval_seconds", 1, 3600)?,
        })
    }

    /// Re-registering an active worker may not change a workflow definition,
    /// nor omit one it advertised. Returns the fingerprints to store.
    pub(super) fn reconcile(
        &self,
        current: Option<&Value>,
        supported_types: &[String],
    ) -> Result<Value> {
        let mut incoming = self.fingerprints.clone().unwrap_or_default();
        if let Some(current) = current.map(fingerprints) {
            if self.fingerprints.is_some() {
                for workflow_type in supported_types {
                    if current.contains_key(workflow_type) && !incoming.contains_key(workflow_type)
                    {
                        return Err(definition_refusal(
                            "workflow_definition_fingerprint_missing",
                            workflow_type,
                        ));
                    }
                }
            }
            for (workflow_type, fingerprint) in &incoming {
                if current.get(workflow_type).is_some_and(|c| c != fingerprint) {
                    return Err(definition_refusal(
                        "workflow_definition_changed",
                        workflow_type,
                    ));
                }
            }
            for (workflow_type, fingerprint) in current {
                if supported_types.iter().any(|t| t.trim() == workflow_type) {
                    incoming.entry(workflow_type).or_insert(fingerprint);
                }
            }
        }
        Ok(php_map(&incoming))
    }
}

/// task_slots as PHP validates it, each count bounded by its capacity.
fn task_slots(body: &Value, capacity: [i64; 3]) -> Result<Option<[Option<i64>; 3]>> {
    let slots = match body.get("task_slots") {
        None | Some(Value::Null) => return Ok(None),
        Some(Value::Object(slots)) => slots,
        // A JSON list is a PHP array without the named slots.
        Some(Value::Array(_)) => return Ok(Some([None; 3])),
        Some(_) => return Err(invalid()),
    };
    let mut bounded = [None; 3];
    for (index, count) in SLOTS.iter().enumerate() {
        match slots.get(count.field) {
            None | Some(Value::Null) => {}
            Some(value) => {
                if integer(value).is_none_or(|n| n < 0) {
                    return Err(invalid());
                }
                bounded[index] = slot(value, capacity[index]);
            }
        }
    }
    Ok(Some(bounded))
}

fn time(at: Option<DateTime<Utc>>) -> Value {
    json!(at.map(|at| at.to_rfc3339_opts(SecondsFormat::Micros, true)))
}

/// The registrations a worker list asks for.
pub(crate) struct WorkerListFilter<'a> {
    pub(crate) task_queue: Option<&'a str>,
    pub(crate) status: Option<&'a str>,
    /// The page continues after this worker id.
    pub(crate) after: Option<&'a str>,
    pub(crate) page_size: i64,
}

/// The default and largest worker list page (Server #553).
pub(crate) const WORKER_PAGE_SIZE: i64 = 100;
/// How many expired registrations one registration removes inline.
pub(super) const INLINE_EXPIRY_LIMIT: i64 = 16;
/// How many expired registrations one maintenance turn removes per namespace.
pub(super) const MAINTENANCE_EXPIRY_LIMIT: i64 = 500;

/// The opaque continuation after a worker id.
pub(crate) fn page_token(worker_id: &str) -> String {
    use base64::Engine;
    base64::engine::general_purpose::URL_SAFE_NO_PAD.encode(worker_id)
}

/// The worker id a continuation names; None when it is not one.
pub(crate) fn page_after(token: &str) -> Option<String> {
    use base64::Engine;
    base64::engine::general_purpose::URL_SAFE_NO_PAD
        .decode(token)
        .ok()
        .and_then(|bytes| String::from_utf8(bytes).ok())
        .filter(|worker_id| !worker_id.is_empty())
}

/// The worker list statements: by queue or not, then live, stale, or live
/// with a stored status. The status is compared trimmed and lowercased, and
/// an empty one is `active`, as worker_summary reports it.
macro_rules! worker_list {
    ($queue:literal, $state:literal, $limit:literal) => {
        concat!("SELECT * FROM workflow_worker_registrations WHERE namespace=$1 AND worker_id>$2", $queue, $state,
            " ORDER BY worker_id LIMIT ", $limit)
    };
}
const LIST_LIVE: &str = worker_list!("", " AND last_heartbeat_at>=$3 AND LOWER(TRIM(status))<>'stale'", "$4");
const LIST_QUEUE_LIVE: &str =
    worker_list!(" AND task_queue=$3", " AND last_heartbeat_at>=$4 AND LOWER(TRIM(status))<>'stale'", "$5");
const LIST_STALE: &str =
    worker_list!("", " AND (last_heartbeat_at IS NULL OR last_heartbeat_at<$3 OR LOWER(TRIM(status))='stale')", "$4");
const LIST_QUEUE_STALE: &str = worker_list!(" AND task_queue=$3",
    " AND (last_heartbeat_at IS NULL OR last_heartbeat_at<$4 OR LOWER(TRIM(status))='stale')", "$5");
const LIST_STATUS: &str =
    worker_list!("", " AND last_heartbeat_at>=$3 AND COALESCE(NULLIF(LOWER(TRIM(status)),''),'active')=$4", "$5");
const LIST_QUEUE_STATUS: &str = worker_list!(" AND task_queue=$3",
    " AND last_heartbeat_at>=$4 AND COALESCE(NULLIF(LOWER(TRIM(status)),''),'active')=$5", "$6");

fn status_filter(status: Option<&str>) -> Option<String> {
    status
        .map(|s| s.trim().to_lowercase())
        .filter(|s| !s.is_empty())
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
    /// Stores the facts a registration reports. Slots and process metrics it
    /// does not report are cleared, as PHP's updateOrCreate does.
    pub(super) async fn record_registration(
        tx: &mut Transaction<'_, DB>,
        namespace: &str,
        worker_id: &str,
        registration: &Registration,
        fingerprints: &Value,
    ) -> Result<()> {
        let [workflow, activity, sessions] = registration.capacity;
        let mut metrics = registration.process_metrics.clone().unwrap_or(Value::Null);
        let mut observations = Map::new();
        let at = now();
        for (count, value) in SLOTS.iter().zip(registration.slots) {
            record_slot_observation(&mut observations, count.field, value, at);
        }
        store_slot_observations(&mut metrics, observations);
        Self::query("UPDATE workflow_worker_registrations SET workflow_definition_fingerprints=$1,max_concurrent_workflow_tasks=$2,max_concurrent_activity_tasks=$3,max_concurrent_worker_sessions=$4,process_metrics=$5 WHERE worker_id=$6 AND namespace=$7")
            .bind(DB::document(fingerprints)).bind(workflow).bind(activity).bind(sessions)
            .bind(DB::optional_document(&metrics))
            .bind(worker_id).bind(namespace)
            .execute(&mut **tx).await?;
        for (count, value) in SLOTS.iter().zip(registration.slots) {
            Self::set_count(tx, count, value, worker_id, namespace).await?;
        }
        Self::set_count(
            tx,
            &INTERVAL,
            registration.heartbeat_interval,
            worker_id,
            namespace,
        )
        .await
    }

    /// A new process registering a worker_id that holds activity leases
    /// replaces a process that may have died mid-task. As PHP does, close its
    /// running attempts and return the tasks at once, rather than waiting out
    /// the activity lease.
    pub(super) async fn release_replaced_activity_leases(
        tx: &mut Transaction<'_, DB>,
        namespace: &str,
        worker_id: &str,
    ) -> Result<()> {
        let tasks = Self::query("SELECT id,payload FROM workflow_tasks WHERE namespace=$1 AND task_type='activity' AND status='leased' AND lease_owner=$2 ORDER BY id")
            .bind(namespace).bind(worker_id).fetch_all(&mut **tx).await?;
        for task in tasks {
            let task_id = DB::string(&task, "id")?;
            let execution = DB::optional_document_row(&task, "payload")?
                .and_then(|payload| payload["activity_execution_id"].as_str().map(str::to_owned))
                .filter(|execution| !execution.is_empty());
            if let Some(execution) = execution {
                let attempt = Self::query("SELECT a.id FROM activity_attempts a JOIN activity_executions e ON e.id=a.activity_execution_id WHERE a.workflow_task_id=$1 AND a.activity_execution_id=$2 AND a.lease_owner=$3 AND a.status='running' ORDER BY a.attempt_number DESC LIMIT 1")
                    .bind(&task_id).bind(&execution).bind(worker_id).fetch_optional(&mut **tx).await?;
                if let Some(attempt) = attempt {
                    Self::query("UPDATE activity_attempts SET status='expired',lease_expires_at=NULL,closed_at=COALESCE(closed_at,$1) WHERE id=$2")
                        .bind(DB::bind_time(now())).bind(DB::string(&attempt, "id")?)
                        .execute(&mut **tx).await?;
                }
            }
            Self::query("UPDATE workflow_tasks SET status='ready',leased_at=NULL,lease_owner=NULL,lease_expires_at=NULL,last_claim_failed_at=NULL,last_claim_error=NULL,ready_origin_at=$1 WHERE id=$2")
                .bind(DB::bind_time(now())).bind(&task_id).execute(&mut **tx).await?;
        }
        Ok(())
    }

    async fn set_count(
        tx: &mut Transaction<'_, DB>,
        count: &Count,
        value: Option<i64>,
        worker_id: &str,
        namespace: &str,
    ) -> Result<()> {
        match value {
            Some(value) => {
                Self::query(count.set)
                    .bind(value)
                    .bind(worker_id)
                    .bind(namespace)
                    .execute(&mut **tx)
                    .await?
            }
            None => {
                Self::query(count.clear)
                    .bind(worker_id)
                    .bind(namespace)
                    .execute(&mut **tx)
                    .await?
            }
        };
        Ok(())
    }

    pub(crate) async fn worker_heartbeat(
        &self,
        body: Value,
        config: &QueryTaskConfig,
        protocol: Option<&str>,
    ) -> Result<Value> {
        let worker_id = super::text(&body, "worker_id")?;
        let metrics = process_metrics(&body)?;
        let interval = bounded(&body, "heartbeat_interval_seconds", 1, 3600)?;
        let mut tx = self.begin().await?;
        let Some(worker) = Self::query("SELECT task_queue,build_id,status,max_concurrent_workflow_tasks,max_concurrent_activity_tasks,max_concurrent_worker_sessions,process_metrics FROM workflow_worker_registrations WHERE worker_id=$1 AND namespace=$2")
            .bind(worker_id).bind(self.namespace.as_ref()).fetch_optional(&mut *tx).await?
        else {
            return Err(RuntimeError::Protocol {
                status: StatusCode::NOT_FOUND,
                response: json!({"error": "Worker not registered.", "reason": "worker_not_registered",
                    "worker_id": worker_id}),
            });
        };
        // A registration fenced after its leases were recovered stays fenced:
        // its worker restarts under a new worker ID (PHP 2.11.1 onward).
        if DB::optional_string(&worker, "status")?.as_deref() == Some("superseded") {
            return Err(RuntimeError::Protocol {
                status: StatusCode::CONFLICT,
                response: json!({"error": "Worker registration was fenced after its leases were recovered.",
                    "reason": "worker_registration_superseded", "worker_id": worker_id,
                    "remediation": "Restart and register the worker with a new worker_id."}),
            });
        }
        let capacity = [
            DB::number(&worker, "max_concurrent_workflow_tasks")?,
            DB::number(&worker, "max_concurrent_activity_tasks")?,
            DB::number(&worker, "max_concurrent_worker_sessions")?,
        ];
        // A heartbeat keeps a draining build's worker draining.
        let status = Self::registration_status(&mut tx, self.namespace.as_ref(), &DB::string(&worker, "task_queue")?,
            DB::optional_string(&worker, "build_id")?.as_deref()).await?;
        let at = now();
        Self::query("UPDATE workflow_worker_registrations SET last_heartbeat_at=$1,updated_at=$2,status=$3 WHERE worker_id=$4 AND namespace=$5")
            .bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(status).bind(worker_id).bind(self.namespace.as_ref())
            .execute(&mut *tx).await?;
        // Only the slots, metrics and cadence the heartbeat reports change.
        let reported = task_slots(&body, capacity)?;
        let namespace = self.namespace.as_ref();
        let previous_metrics = DB::optional_document_row(&worker, "process_metrics").ok().flatten();
        let mut observations = previous_metrics.as_ref()
            .and_then(|metrics| metrics[SLOT_OBSERVATIONS_METRIC].as_object()).cloned().unwrap_or_default();
        if let (Some(bounded), Some(slots)) = (reported, body["task_slots"].as_object()) {
            for (count, value) in SLOTS.iter().zip(bounded) {
                if slots.contains_key(count.field) {
                    Self::set_count(&mut tx, count, value, worker_id, namespace).await?;
                    record_slot_observation(&mut observations, count.field, value, at);
                }
            }
        }
        // PHP stores the reported metrics, else keeps the stored ones, and adds
        // this heartbeat's protocol version either way.
        let mut metrics = with_protocol(metrics.unwrap_or(previous_metrics), protocol);
        store_slot_observations(&mut metrics, observations);
        Self::query("UPDATE workflow_worker_registrations SET process_metrics=$1 WHERE worker_id=$2 AND namespace=$3")
            .bind(DB::document(&metrics)).bind(worker_id).bind(namespace)
            .execute(&mut *tx).await?;
        if interval.is_some() {
            Self::set_count(&mut tx, &INTERVAL, interval, worker_id, namespace).await?;
        }
        tx.commit().await?;
        // Like PHP, each heartbeat may prune one expired run of its namespace.
        let retention = self.retention_inline_pass().await?;
        Ok(json!({"worker_id": worker_id, "acknowledged": true,
            "heartbeat_interval_seconds": config.worker_heartbeat_interval_seconds,
            "stale_after_seconds": config.worker_stale_after_seconds, "retention": retention}))
    }

    fn worker_summary(row: &DB::Row, stale_after: i64, at: DateTime<Utc>) -> Result<Value> {
        let document = |field: &str| -> Result<Value> {
            Ok(DB::optional_document_row(row, field)?
                .filter(|v| !v.is_null())
                .unwrap_or_else(|| json!([])))
        };
        let empty_map_as_list = |value: Value| match value {
            Value::Object(map) if map.is_empty() => json!([]),
            other => other,
        };
        let capabilities: Vec<String> = document("capabilities")?
            .as_array()
            .map(|flags| {
                flags
                    .iter()
                    .filter_map(|f| f.as_str().map(str::trim).filter(|f| !f.is_empty()))
                    .map(str::to_owned)
                    .collect()
            })
            .unwrap_or_default();
        let heartbeat = DB::optional_instant(row, "last_heartbeat_at")?;
        let stale = heartbeat.is_none_or(|h| h < at - chrono::Duration::seconds(stale_after));
        let stored = DB::optional_string(row, "status")?
            .map(|s| s.trim().to_lowercase())
            .filter(|s| !s.is_empty())
            .unwrap_or_else(|| "active".into());
        let capacity = [
            DB::number(row, "max_concurrent_workflow_tasks")?,
            DB::number(row, "max_concurrent_activity_tasks")?,
            DB::number(row, "max_concurrent_worker_sessions")?,
        ];
        let mut metrics = DB::optional_document_row(row, "process_metrics")?.filter(|value| !value.is_null());
        if let Some(metrics) = metrics.as_mut().and_then(Value::as_object_mut) {
            metrics.remove(SLOT_OBSERVATIONS_METRIC);
        }
        Ok(json!({
            "worker_id": DB::string(row, "worker_id")?,
            "namespace": DB::string(row, "namespace")?,
            "task_queue": DB::string(row, "task_queue")?,
            "runtime": DB::string(row, "runtime")?,
            "sdk_version": DB::optional_string(row, "sdk_version")?,
            "build_id": DB::optional_string(row, "build_id")?,
            "supported_workflow_types": document("supported_workflow_types")?,
            "workflow_definition_fingerprints": empty_map_as_list(document("workflow_definition_fingerprints")?),
            "workflow_command_contracts": empty_map_as_list(document("workflow_command_contracts")?),
            "supported_activity_types": document("supported_activity_types")?,
            "capabilities": capabilities,
            "capability_manifest": document("capability_manifest")?,
            "max_concurrent_workflow_tasks": capacity[0],
            "max_concurrent_activity_tasks": capacity[1],
            "max_concurrent_worker_sessions": capacity[2],
            "task_slots": {
                "workflow_available": DB::optional_number(row, "available_workflow_slots")?,
                "activity_available": DB::optional_number(row, "available_activity_slots")?,
                "session_available": DB::optional_number(row, "available_session_slots")?,
                "workflow_capacity": capacity[0],
                "activity_capacity": capacity[1],
                "session_capacity": capacity[2],
            },
            "process_metrics": metrics,
            "heartbeat_interval_seconds": DB::optional_number(row, "heartbeat_interval_seconds")?,
            "status": if stale { "stale".to_owned() } else { stored },
            "last_heartbeat_at": time(heartbeat),
            "registered_at": time(DB::optional_instant(row, "created_at")?),
        }))
    }

    /// `GET /api/workers`, one bounded page in worker id order (Server #553).
    /// Staleness and the status filter are applied before the page is cut,
    /// so every page but the last is full and `next_page_token` continues
    /// after the page's last worker id.
    pub(crate) async fn list_workers(
        &self,
        filter: &WorkerListFilter<'_>,
        config: &QueryTaskConfig,
    ) -> Result<Value> {
        let stale_after = config.worker_stale_after_seconds.max(1);
        let at = now();
        let cutoff = at - chrono::Duration::seconds(stale_after);
        // PHP filters only on a truthy queue, and "0" is not truthy.
        let queue = filter.task_queue.filter(|q| !q.is_empty() && *q != "0");
        let wanted = status_filter(filter.status);
        // A registration without a heartbeat is stale, as worker_summary has it.
        let sql = match (queue.is_some(), wanted.as_deref()) {
            (false, None) => LIST_LIVE,
            (true, None) => LIST_QUEUE_LIVE,
            (false, Some("stale")) => LIST_STALE,
            (true, Some("stale")) => LIST_QUEUE_STALE,
            (false, Some(_)) => LIST_STATUS,
            (true, Some(_)) => LIST_QUEUE_STATUS,
        };
        let mut query = Self::query(sql)
            .bind(self.namespace.as_ref())
            .bind(filter.after.unwrap_or(""));
        if let Some(queue) = queue {
            query = query.bind(queue);
        }
        query = query.bind(DB::bind_time(cutoff));
        if let Some(wanted) = wanted.as_deref().filter(|wanted| *wanted != "stale") {
            query = query.bind(wanted.to_owned());
        }
        let rows = query.bind(filter.page_size + 1).fetch_all(&self.pool).await?;
        let size = usize::try_from(filter.page_size).unwrap_or(0);
        let more = rows.len() > size;
        let mut workers = Vec::new();
        for row in rows.iter().take(size) {
            workers.push(Self::worker_summary(row, stale_after, at)?);
        }
        let next_page_token = more
            .then(|| workers.last().and_then(|worker| worker["worker_id"].as_str()).map(page_token))
            .flatten();
        Ok(json!({"workers": workers, "stale_after_seconds": stale_after,
            "registration_retention_seconds": super::query_authority::worker_registration_retention(
                self.worker_registration_retention_seconds, self.worker_stale_after_seconds),
            "next_page_token": next_page_token}))
    }

    /// Server #553: deletes up to `limit` of this namespace's registrations
    /// with no heartbeat (else update, else creation) within the
    /// registration retention, oldest first. Active and draining workers
    /// heartbeat, so only a worker that stopped without deregistering ages
    /// out; a registration that still owns an unexpired task lease is kept.
    /// Each deletion retires the registration's incarnation, as
    /// deregistration does. Build rollouts, pinned runs and their pending
    /// tasks are separate records, so a build left without workers still
    /// reports `no_compatible_worker`.
    pub(super) async fn expire_worker_registrations(&self, limit: i64) -> Result<u64> {
        let at = now();
        let retention = super::query_authority::worker_registration_retention(
            self.worker_registration_retention_seconds,
            self.worker_stale_after_seconds,
        );
        let cutoff = at - chrono::Duration::seconds(retention);
        let namespace = self.namespace.as_ref();
        let candidates = Self::query("SELECT worker_id FROM workflow_worker_registrations WHERE namespace=$1 AND COALESCE(last_heartbeat_at,updated_at,created_at)<$2 ORDER BY COALESCE(last_heartbeat_at,updated_at,created_at),worker_id LIMIT $3")
            .bind(namespace).bind(DB::bind_time(cutoff)).bind(limit.max(1))
            .fetch_all(&self.pool).await?;
        if candidates.is_empty() {
            return Ok(0);
        }
        let mut tx = self.begin().await?;
        let mut expired = 0;
        for candidate in &candidates {
            let worker_id = DB::string(candidate, "worker_id")?;
            // Rechecked under the write: a heartbeat or re-registration since
            // the scan keeps the registration.
            let deleted = Self::query("DELETE FROM workflow_worker_registrations WHERE namespace=$1 AND worker_id=$2 AND COALESCE(last_heartbeat_at,updated_at,created_at)<$3 AND NOT EXISTS (SELECT 1 FROM workflow_tasks WHERE workflow_tasks.namespace=$4 AND workflow_tasks.lease_owner=$5 AND workflow_tasks.status='leased' AND workflow_tasks.lease_expires_at>$6)")
                .bind(namespace).bind(&worker_id).bind(DB::bind_time(cutoff))
                .bind(namespace).bind(&worker_id).bind(DB::bind_time(at))
                .execute(&mut *tx).await?;
            if DB::affected(deleted) > 0 {
                self.retire_incarnation(&mut tx, &worker_id).await?;
                expired += 1;
            }
        }
        tx.commit().await?;
        Ok(expired)
    }

    pub(crate) async fn describe_worker(
        &self,
        worker_id: &str,
        config: &QueryTaskConfig,
    ) -> Result<Value> {
        let stale_after = config.worker_stale_after_seconds.max(1);
        let Some(row) = Self::query(
            "SELECT * FROM workflow_worker_registrations WHERE worker_id=$1 AND namespace=$2",
        )
        .bind(worker_id)
        .bind(self.namespace.as_ref())
        .fetch_optional(&self.pool)
        .await?
        else {
            return Err(RuntimeError::Protocol {
                status: StatusCode::NOT_FOUND,
                response: json!({"message": format!("Worker [{worker_id}] not found in namespace [{}].", self.namespace),
                    "reason": "worker_not_found"}),
            });
        };
        let mut summary = Self::worker_summary(&row, stale_after, now())?;
        summary["updated_at"] = time(DB::optional_instant(&row, "updated_at")?);
        summary["stale_after_seconds"] = json!(stale_after);
        Ok(summary)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn slot_reports_expire_independently_of_worker_liveness() {
        let at = Utc::now();
        let mut observations = Map::new();
        record_slot_observation(&mut observations, "workflow_available", Some(0), at - chrono::Duration::seconds(30));
        record_slot_observation(&mut observations, "activity_available", Some(0), at);
        let mut metrics = json!({});
        store_slot_observations(&mut metrics, observations);
        assert!(!slot_observation_is_fresh(&metrics, "workflow_available", at, 30));
        assert!(slot_observation_is_fresh(&metrics, "activity_available", at, 30));
        assert!(!slot_observation_is_fresh(&metrics, "activity_available", at - chrono::Duration::seconds(1), 30));
        assert!(!slot_observation_is_fresh(&json!({}), "workflow_available", at, 30));
    }

    #[test]
    fn registration_keeps_php_facts_and_bounds_slots() {
        let parsed = Registration::parse(&json!({"max_concurrent_workflow_tasks": 3,
            "max_concurrent_activity_tasks": "5", "task_slots": {"workflow_available": 2,
            "activity_available": 9, "session_available": "1"}, "process_metrics": {"process_id": 7,
            "process_uptime_seconds": "0", "cpu_percent": 1.5, "host": "  h  ", "invented": 1},
            "heartbeat_interval_seconds": 15, "workflow_definition_fingerprints": {" a ": " f ", "b": " "}}))
        .unwrap();
        assert_eq!(parsed.capacity, [3, 5, 10]);
        assert_eq!(parsed.slots, [Some(2), Some(5), Some(1)]);
        assert_eq!(
            parsed.process_metrics,
            Some(
                json!({"cpu_percent": 1.5, "process_uptime_seconds": 0, "process_id": 7, "host": "h"})
            )
        );
        assert_eq!(parsed.heartbeat_interval, Some(15));
        assert_eq!(
            parsed.fingerprints,
            Some(BTreeMap::from([("a".into(), "f".into())]))
        );
        for invalid in [
            json!({"max_concurrent_workflow_tasks": 0, "max_concurrent_activity_tasks": 0}),
            json!({"max_concurrent_worker_sessions": 0}),
            json!({"task_slots": {"workflow_available": -1}}),
            json!({"heartbeat_interval_seconds": 3601}),
            json!({"process_metrics": {"memory_bytes": -1}}),
            json!({"workflow_definition_fingerprints": {"a": 1}}),
        ] {
            assert!(Registration::parse(&invalid).is_err(), "{invalid}");
        }
        let defaults = Registration::parse(&json!({})).unwrap();
        assert_eq!(defaults.capacity, [100, 100, 10]);
        assert_eq!(defaults.slots, [None; 3]);
        assert_eq!(defaults.process_metrics, None);
    }

    #[test]
    fn worker_page_tokens_round_trip_and_retention_never_precedes_staleness() {
        for worker_id in ["w", "worker/λ 1", "a+b=c"] {
            assert_eq!(page_after(&page_token(worker_id)).as_deref(), Some(worker_id));
        }
        for token in ["%%", "", "@@@", "/w=="] {
            assert_eq!(page_after(token), None, "{token}");
        }
        assert_eq!(super::super::query_authority::worker_registration_retention(86_400, 30), 86_400);
        assert_eq!(super::super::query_authority::worker_registration_retention(10, 30), 30);
        assert_eq!(super::super::query_authority::worker_registration_retention(-5, 0), 1);
    }

    #[test]
    fn re_registration_guards_and_keeps_advertised_fingerprints() {
        let types = ["a".to_owned(), "b".to_owned()];
        let current = json!({"a": "1", "b": "2", "gone": "3"});
        let reason = |body: Value| match Registration::parse(&body)
            .unwrap()
            .reconcile(Some(&current), &types)
        {
            Err(RuntimeError::Protocol { response, .. }) => (
                response["reason"].clone(),
                response["workflow_type"].clone(),
            ),
            other => panic!("{:?}", other.map(|_| ())),
        };
        assert_eq!(
            reason(json!({"workflow_definition_fingerprints": {"a": "1", "b": "changed"}})),
            (json!("workflow_definition_changed"), json!("b"))
        );
        assert_eq!(
            reason(json!({"workflow_definition_fingerprints": {"a": "1"}})),
            (json!("workflow_definition_fingerprint_missing"), json!("b"))
        );
        let omitted = Registration::parse(&json!({})).unwrap();
        assert_eq!(
            omitted.reconcile(Some(&current), &types).unwrap(),
            json!({"a": "1", "b": "2"})
        );
        assert_eq!(omitted.reconcile(None, &types).unwrap(), json!([]));
        assert_eq!(
            omitted.reconcile(Some(&json!([])), &types).unwrap(),
            json!([])
        );
    }
}
