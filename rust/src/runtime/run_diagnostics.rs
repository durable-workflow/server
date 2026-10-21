//! `GET /api/workflows/{id}/debug` and `.../runs/{run}/debug` as published
//! PHP's `WorkflowRunDiagnostics::forRun` composes them: execution state from
//! the run summary, pending workflow tasks and activities, the run's and its
//! activities' task queues, recent failures with their supporting events,
//! the latest workflow-task failure, compatibility, and findings.
//!
//! Native reports `cancellation_cascade_supported: false` and no cascade:
//! PHP's `CancellationCascadeView` is not ported yet. Native carries
//! PHP's default server compatibility (no supported markers).
use super::{
    Result,
    backend::Backend,
    query_authority::QueryTaskConfig,
    refuse,
    store::{Store, now},
    task_queues::json_time,
};
use axum::http::StatusCode;
use base64::{Engine, engine::general_purpose::STANDARD};
use chrono::{DateTime, Duration, Utc};
use serde_json::{Map, Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::collections::{BTreeMap, BTreeSet};

const TASK_LIMIT: i64 = 25;
const FAILURE_LIMIT: usize = 10;
const FINDING_WORKER_LIMIT: usize = 10;
const FINDING_WORKFLOW_TYPE_LIMIT: usize = 20;
const PREVIEW_BYTES: usize = 4096;
const PAYLOAD_KEY_LIMIT: usize = 20;
const REDISPATCH_AFTER_SECONDS: i64 = 3;
const FAILURE_BACKOFF_MAX_SECONDS: i64 = 60;
/// `workflows.v2.compatibility.heartbeat_ttl_seconds`.
const FLEET_TTL_SECONDS: i64 = 30;

/// `WorkflowRunDiagnostics::compact`: without nulls and empty arrays.
fn compact(value: Value) -> Value {
    match value {
        Value::Object(fields) => Value::Object(
            fields
                .into_iter()
                .filter(|(_, value)| !value.is_null() && value.as_array().is_none_or(|items| !items.is_empty()))
                .collect(),
        ),
        other => other,
    }
}

fn trimmed(value: Option<String>) -> Option<String> {
    value.map(|text| text.trim().to_owned()).filter(|text| !text.is_empty())
}

/// `WorkflowTaskProblem::metadata`.
pub(super) fn task_problem_badge(problem: bool, liveness: Option<&str>, wait: Option<&str>) -> Value {
    if !problem {
        return Value::Null;
    }
    let active = match liveness {
        None => false,
        Some("repair_needed") => {
            wait.is_none_or(|wait| ["workflow-task", "update", "signal", "child", "condition"].contains(&wait))
        }
        Some(state) => state.contains("task_claim_failed") || state.contains("waiting_for_compatible_worker"),
    };
    let (code, label, description, tone) = if liveness == Some("workflow_replay_blocked") {
        ("replay_blocked", "Task Failure", "The selected run has a workflow-task replay failure or unsupported history.", "dark")
    } else if active {
        ("active", "Task Problem", "The selected run currently has missing, retried, or transport-unhealthy workflow-task work.", "warning")
    } else {
        ("history", "Task History", "The selected run previously needed workflow-task repair or replay recovery.", "secondary")
    };
    json!({"code":code,"label":label,"description":description,"tone":tone,"badge_visible":true})
}

/// PHP's `json_encode` of a decoded payload with `JSON_UNESCAPED_SLASHES`,
/// from the stored text so that key order is the stored order.
struct PhpJson<'a> {
    bytes: &'a [u8],
    at: usize,
    out: String,
}

impl PhpJson<'_> {
    fn space(&mut self) {
        while self.at < self.bytes.len() && self.bytes[self.at].is_ascii_whitespace() {
            self.at += 1;
        }
    }

    fn string(&mut self) -> Option<String> {
        // Returns the decoded text; the caller decides how to emit it.
        let start = self.at + 1;
        let mut end = start;
        while end < self.bytes.len() && self.bytes[end] != b'"' {
            end += if self.bytes[end] == b'\\' { 2 } else { 1 };
        }
        let raw = std::str::from_utf8(self.bytes.get(self.at..=end)?).ok()?;
        self.at = end + 1;
        serde_json::from_str::<String>(raw).ok()
    }

    fn emit_string(&mut self, text: &str) {
        self.out.push('"');
        for character in text.chars() {
            match character {
                '"' => self.out.push_str("\\\""),
                '\\' => self.out.push_str("\\\\"),
                '\u{8}' => self.out.push_str("\\b"),
                '\u{c}' => self.out.push_str("\\f"),
                '\n' => self.out.push_str("\\n"),
                '\r' => self.out.push_str("\\r"),
                '\t' => self.out.push_str("\\t"),
                character if character.is_ascii() && (character as u32) >= 0x20 => self.out.push(character),
                character => {
                    let mut units = [0; 2];
                    for unit in character.encode_utf16(&mut units) {
                        self.out.push_str(&format!("\\u{unit:04x}"));
                    }
                }
            }
        }
        self.out.push('"');
    }

    fn number(&mut self) -> Option<()> {
        let start = self.at;
        while self.at < self.bytes.len() && matches!(self.bytes[self.at], b'-' | b'+' | b'.' | b'e' | b'E' | b'0'..=b'9') {
            self.at += 1;
        }
        let text = std::str::from_utf8(&self.bytes[start..self.at]).ok()?;
        if text.parse::<i64>().is_ok() {
            self.out.push_str(text);
            return Some(());
        }
        let value: f64 = text.parse().ok()?;
        self.out.push_str(&php_float(value));
        Some(())
    }

    /// One object, its members in stored order.
    fn object(&mut self) -> Option<()> {
        self.at += 1;
        self.out.push('{');
        let mut first = true;
        loop {
            self.space();
            if *self.bytes.get(self.at)? == b'}' {
                self.at += 1;
                break;
            }
            if *self.bytes.get(self.at)? == b',' {
                self.at += 1;
                self.space();
            }
            if !first {
                self.out.push(',');
            }
            first = false;
            let key = self.string()?;
            self.emit_string(&key);
            self.space();
            if *self.bytes.get(self.at)? != b':' {
                return None;
            }
            self.at += 1;
            self.out.push(':');
            self.value()?;
        }
        self.out.push('}');
        Some(())
    }

    fn array(&mut self) -> Option<()> {
        self.at += 1;
        self.out.push('[');
        let mut first = true;
        loop {
            self.space();
            if *self.bytes.get(self.at)? == b']' {
                self.at += 1;
                break;
            }
            if *self.bytes.get(self.at)? == b',' {
                self.at += 1;
            }
            if !first {
                self.out.push(',');
            }
            first = false;
            self.value()?;
        }
        self.out.push(']');
        Some(())
    }

    fn value(&mut self) -> Option<()> {
        self.space();
        match *self.bytes.get(self.at)? {
            b'{' => self.object()?,
            b'[' => self.array()?,
            b'"' => {
                let text = self.string()?;
                self.emit_string(&text);
            }
            b't' | b'f' | b'n' => {
                for word in ["true", "false", "null"] {
                    if self.bytes[self.at..].starts_with(word.as_bytes()) {
                        self.out.push_str(word);
                        self.at += word.len();
                        return Some(());
                    }
                }
                return None;
            }
            _ => self.number()?,
        }
        Some(())
    }
}

/// PHP's `json_encode` of a float under `serialize_precision = -1`: the
/// shortest digits, fixed notation unless the decimal point falls before
/// four leading zeros or after fifteen digits, and `.0` on integral values.
fn php_float(value: f64) -> String {
    let scientific = format!("{:e}", value.abs());
    let (mantissa, exponent) = scientific.split_once('e').unwrap_or((&scientific, "0"));
    let digits = mantissa.replace('.', "");
    let exponent: i32 = exponent.parse().unwrap_or(0);
    let point = exponent + 1;
    let sign = if value.is_sign_negative() && value != 0.0 { "-" } else { "" };
    if point < -3 || point > 15 {
        let mantissa = if digits.len() == 1 { format!("{digits}.0") } else { format!("{}.{}", &digits[..1], &digits[1..]) };
        format!("{sign}{mantissa}e{}{}", if exponent < 0 { '-' } else { '+' }, exponent.abs())
    } else if point <= 0 {
        format!("{sign}0.{}{digits}", "0".repeat((-point) as usize))
    } else if digits.len() as i32 <= point {
        format!("{sign}{digits}{}.0", "0".repeat((point - digits.len() as i32) as usize))
    } else {
        format!("{sign}{}.{}", &digits[..point as usize], &digits[point as usize..])
    }
}

/// The re-encoded payload, its top-level keys in stored order and whether
/// PHP decodes it to a list.
fn payload_shape(raw: Option<&str>) -> (String, Vec<String>, bool, usize) {
    let decoded: Value = raw.and_then(|raw| serde_json::from_str(raw).ok()).unwrap_or(Value::Null);
    let raw = raw.unwrap_or("");
    let keys = match &decoded {
        Value::Object(_) => {
            // Top-level keys in their stored order.
            let mut keys = Vec::new();
            let mut parser = PhpJson { bytes: raw.as_bytes(), at: 0, out: String::new() };
            parser.space();
            parser.at += 1;
            loop {
                parser.space();
                match parser.bytes.get(parser.at) {
                    Some(b'"') => {}
                    Some(b',') => {
                        parser.at += 1;
                        continue;
                    }
                    _ => break,
                }
                let Some(key) = parser.string() else { break };
                keys.push(key);
                parser.space();
                parser.at += 1;
                parser.out.clear();
                if parser.value().is_none() {
                    break;
                }
            }
            keys
        }
        Value::Array(items) => (0..items.len()).map(|index| index.to_string()).collect(),
        _ => vec![],
    };
    let list = match &decoded {
        Value::Array(_) => true,
        Value::Object(_) => keys.iter().enumerate().all(|(index, key)| *key == index.to_string()),
        _ => true,
    };
    let encoded = match &decoded {
        Value::Object(_) | Value::Array(_) => {
            let mut parser = PhpJson { bytes: raw.as_bytes(), at: 0, out: String::new() };
            match parser.value() {
                Some(()) => parser.out,
                None => "{}".into(),
            }
        }
        _ => "[]".into(),
    };
    let count = keys.len();
    (encoded, keys, list, count)
}

/// PHP's `TaskRepairPolicy` predicates on one task row.
struct TaskState {
    status: String,
    available_at: Option<DateTime<Utc>>,
    lease_expires_at: Option<DateTime<Utc>>,
    last_dispatch_attempt_at: Option<DateTime<Utc>>,
    last_dispatched_at: Option<DateTime<Utc>>,
    last_dispatch_error: Option<String>,
    last_claim_failed_at: Option<DateTime<Utc>>,
    last_claim_error: Option<String>,
    repair_available_at: Option<DateTime<Utc>>,
    created_at: Option<DateTime<Utc>>,
    repair_count: i64,
}

impl TaskState {
    fn lease_expired(&self, at: DateTime<Utc>) -> bool {
        self.status == "leased" && self.lease_expires_at.is_some_and(|expires| expires <= at)
    }
    fn dispatch_failed(&self) -> bool {
        self.status == "ready"
            && self.last_dispatch_attempt_at.is_some()
            && self.last_dispatch_error.as_deref().is_some_and(|error| !error.trim().is_empty())
            && self.last_dispatched_at.is_none_or(|dispatched| self.last_dispatch_attempt_at.is_some_and(|attempt| attempt > dispatched))
    }
    fn claim_failed(&self) -> bool {
        self.status == "ready"
            && self.last_claim_failed_at.is_some()
            && self.last_claim_error.as_deref().is_some_and(|error| !error.trim().is_empty())
    }
    fn dispatch_overdue(&self, at: DateTime<Utc>) -> bool {
        if self.status != "ready" || self.available_at.is_some_and(|available| available > at) {
            return false;
        }
        if self.dispatch_failed() || self.claim_failed() {
            return false;
        }
        self.last_dispatched_at
            .or(self.created_at)
            .is_some_and(|reference| reference <= at - Duration::seconds(REDISPATCH_AFTER_SECONDS))
    }
    fn needs_redispatch(&self, at: DateTime<Utc>, failed: bool) -> bool {
        match self.repair_available_at {
            Some(available) => available <= at,
            None => failed || self.last_claim_failed_at.is_some_and(|failed_at| failed_at <= at - Duration::seconds(REDISPATCH_AFTER_SECONDS)),
        }
    }
    fn backoff_seconds(&self) -> i64 {
        let mut seconds = REDISPATCH_AFTER_SECONDS;
        for _ in 0..(self.repair_count.max(0) - 1).max(0) {
            if seconds >= FAILURE_BACKOFF_MAX_SECONDS {
                break;
            }
            seconds = (seconds * 2).min(FAILURE_BACKOFF_MAX_SECONDS);
        }
        seconds
    }
    /// `WorkflowRunDiagnostics::taskTransportState`.
    fn transport_state(&self, at: DateTime<Utc>) -> &'static str {
        match self.status.as_str() {
            "leased" => {
                if self.lease_expired(at) { "lease_expired" } else { "leased" }
            }
            "ready" => {
                if self.available_at.is_some_and(|available| available > at) {
                    if self.dispatch_failed() { "dispatch_failed" } else { "scheduled" }
                } else if self.dispatch_failed() {
                    if self.needs_redispatch(at, true) { "dispatch_failed" } else { "repair_backoff" }
                } else if self.claim_failed() {
                    if self.needs_redispatch(at, false) { "claim_failed" } else { "repair_backoff" }
                } else if self.dispatch_overdue(at) {
                    "dispatch_overdue"
                } else {
                    "ready"
                }
            }
            _ => "unknown",
        }
    }
}

/// The run summary fields the diagnostics read.
#[derive(Default)]
struct Summary {
    present: bool,
    fields: Map<String, Value>,
}

impl Summary {
    fn text(&self, field: &str) -> Option<String> {
        self.fields.get(field).and_then(Value::as_str).map(str::trim).filter(|text| !text.is_empty()).map(str::to_owned)
    }
    fn get(&self, field: &str) -> Value {
        self.fields.get(field).cloned().unwrap_or(Value::Null)
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
    async fn diagnostic_summary(&self, run_id: &str) -> Result<Summary> {
        let row = Self::query("SELECT status_bucket,wait_kind,wait_reason,liveness_state,liveness_reason,CASE WHEN repair_attention THEN 1 ELSE 0 END AS repair_attention,CASE WHEN task_problem THEN 1 ELSE 0 END AS task_problem,next_task_id,next_task_type,next_task_status,next_task_at,next_task_lease_expires_at,wait_deadline_at,compatibility,connection,queue,open_wait_id,resume_source_kind,resume_source_id FROM workflow_run_summaries WHERE id=$1")
            .bind(run_id).fetch_optional(&self.pool).await?;
        let Some(row) = row else {
            return Ok(Summary::default());
        };
        let mut fields = Map::new();
        for field in ["status_bucket", "wait_kind", "wait_reason", "liveness_state", "liveness_reason", "next_task_id",
            "next_task_type", "next_task_status", "compatibility", "connection", "queue", "open_wait_id",
            "resume_source_kind", "resume_source_id"]
        {
            fields.insert(field.into(), json!(DB::optional_string(&row, field)?));
        }
        for field in ["next_task_at", "next_task_lease_expires_at", "wait_deadline_at"] {
            fields.insert(field.into(), json_time(DB::optional_instant(&row, field)?));
        }
        for field in ["repair_attention", "task_problem"] {
            fields.insert(field.into(), json!(DB::number(&row, field)? != 0));
        }
        Ok(Summary { present: true, fields })
    }

    /// `WorkflowCompatibilityFleet::detailsForNamespace` from the
    /// registrations whose heartbeat is within the fleet window.
    pub(super) async fn fleet_snapshots(&self, at: DateTime<Utc>) -> Result<Vec<(String, Option<String>, Vec<String>, DateTime<Utc>)>> {
        let rows = Self::query("SELECT worker_id,task_queue,build_id,last_heartbeat_at FROM workflow_worker_registrations WHERE namespace=$1 AND last_heartbeat_at IS NOT NULL")
            .bind(self.namespace.as_ref()).fetch_all(&self.pool).await?;
        let mut snapshots = Vec::new();
        for row in rows {
            let Some(heartbeat) = DB::optional_instant(&row, "last_heartbeat_at")? else { continue };
            if heartbeat + Duration::seconds(FLEET_TTL_SECONDS) <= at {
                continue;
            }
            snapshots.push((
                DB::string(&row, "worker_id")?,
                trimmed(DB::optional_string(&row, "task_queue")?),
                trimmed(DB::optional_string(&row, "build_id")?).into_iter().collect(),
                heartbeat,
            ));
        }
        Ok(snapshots)
    }


    /// `WorkerCompatibilityFleet::summaryForNamespace`.
    fn fleet_summary(&self, snapshots: &[(String, Option<String>, Vec<String>, DateTime<Utc>)]) -> Value {
        let mut workers: BTreeMap<&str, (BTreeSet<String>, BTreeSet<String>, DateTime<Utc>)> = BTreeMap::new();
        for (worker, queue, supported, heartbeat) in snapshots {
            let entry = workers.entry(worker).or_insert_with(|| (BTreeSet::new(), BTreeSet::new(), *heartbeat));
            entry.0.extend(queue.iter().cloned());
            entry.1.extend(supported.iter().cloned());
            entry.2 = entry.2.max(*heartbeat);
        }
        let queues: BTreeSet<String> = snapshots.iter().filter_map(|(_, queue, _, _)| queue.clone()).collect();
        let builds: BTreeSet<String> = snapshots.iter().flat_map(|(_, _, supported, _)| supported.iter().cloned()).collect();
        json!({"namespace":self.namespace.as_ref(),"active_workers":workers.len(),"active_worker_scopes":snapshots.len(),
            "queues":queues,"build_ids":builds,
            "workers":workers.into_iter().map(|(worker, (queues, builds, heartbeat))| json!({"worker_id":worker,
                "queues":queues,"build_ids":builds,"recorded_at":json_time(Some(heartbeat)),
                "expires_at":json_time(Some(heartbeat + Duration::seconds(FLEET_TTL_SECONDS)))})).collect::<Vec<_>>()})
    }

    async fn task_rows(&self, run: &DB::Row, summary: &Summary, at: DateTime<Utc>, fleet: &[(String, Option<String>, Vec<String>, DateTime<Utc>)]) -> Result<Vec<Value>> {
        let run_id = DB::string(run, "id")?;
        let rows = Self::query("SELECT * FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status IN ('ready','leased') ORDER BY available_at,created_at,id LIMIT 25")
            .bind(&run_id).fetch_all(&self.pool).await?;
        let run_compatibility = trimmed(DB::optional_string(run, "compatibility")?);
        let run_queue = DB::optional_string(run, "queue")?;
        let mut tasks = Vec::new();
        for row in &rows {
            let state = TaskState {
                status: DB::string(row, "status")?,
                available_at: DB::optional_instant(row, "available_at")?,
                lease_expires_at: DB::optional_instant(row, "lease_expires_at")?,
                last_dispatch_attempt_at: DB::optional_instant(row, "last_dispatch_attempt_at")?,
                last_dispatched_at: DB::optional_instant(row, "last_dispatched_at")?,
                last_dispatch_error: DB::optional_string(row, "last_dispatch_error")?,
                last_claim_failed_at: DB::optional_instant(row, "last_claim_failed_at")?,
                last_claim_error: DB::optional_string(row, "last_claim_error")?,
                repair_available_at: DB::optional_instant(row, "repair_available_at")?,
                created_at: DB::optional_instant(row, "created_at")?,
                repair_count: DB::optional_number(row, "repair_count")?.unwrap_or(0),
            };
            let payload = DB::optional_document_row(row, "payload")?.unwrap_or(Value::Null);
            let payload_text = |field: &str| payload[field].as_str().map(str::trim).filter(|text| !text.is_empty()).map(str::to_owned);
            let compatibility = trimmed(DB::optional_string(row, "compatibility")?).or(run_compatibility.clone());
            let queue = DB::optional_string(row, "queue")?.or(run_queue.clone());
            let (in_fleet, fleet_reason) = fleet_supports(fleet, compatibility.as_deref(), queue.as_deref());
            let replay_blocked = payload["replay_blocked"] == true;
            let wait_kind = payload_text("workflow_wait_kind");
            let resume = payload_text("resume_source_kind");
            let summary_text = if replay_blocked {
                "Workflow replay blocked."
            } else if state.lease_expired(at) {
                "Workflow task lease expired; waiting for recovery."
            } else if state.dispatch_failed() {
                "Workflow task dispatch failed; waiting for recovery."
            } else if state.claim_failed() {
                "Workflow task claim failed; worker backend capability is unsupported."
            } else if state.dispatch_overdue(at) {
                "Workflow task is ready but dispatch is overdue."
            } else {
                match (state.status.as_str(), wait_kind.as_deref()) {
                    ("ready", Some("update")) => "Workflow task ready to apply accepted update.",
                    ("ready", Some("signal")) if resume.as_deref() == Some("timer") => "Workflow task ready to apply signal timeout.",
                    ("ready", Some("signal")) => "Workflow task ready to apply accepted signal.",
                    ("ready", Some("condition")) => "Workflow task ready to apply condition timeout.",
                    ("ready", _) => "Workflow task ready to resume the selected run.",
                    ("leased", Some("update")) => "Workflow task leased to apply accepted update.",
                    ("leased", Some("signal")) if resume.as_deref() == Some("timer") => "Workflow task leased to apply signal timeout.",
                    ("leased", Some("signal")) => "Workflow task leased to apply accepted signal.",
                    ("leased", Some("condition")) => "Workflow task leased to apply condition timeout.",
                    ("leased", _) => "Workflow task leased to a worker.",
                    _ => "Workflow task is not pending.",
                }
            };
            let supported = compatibility.is_none();
            tasks.push(json!({"id":DB::string(row,"id")?,"type":"workflow","status":state.status,
                "transport_state":state.transport_state(at),"task_missing":false,"synthetic":false,"expected_task_id":null,
                "summary":summary_text,"compatibility":compatibility,"compatibility_supported":supported,
                "compatibility_reason":compatibility.as_ref().map(|required| format!("Requires compatibility [{required}]; this worker supports [none].")),
                "compatibility_supported_in_fleet":in_fleet,"compatibility_fleet_reason":fleet_reason,
                "dispatch_failed":state.dispatch_failed(),"dispatch_overdue":state.dispatch_overdue(at),
                "claim_failed":state.claim_failed(),"available_at":json_time(state.available_at),
                "leased_at":json_time(DB::optional_instant(row,"leased_at")?),
                "repair_available_at":json_time(state.repair_available_at),"repair_backoff_seconds":state.backoff_seconds(),
                "lease_expired":state.lease_expired(at),"lease_owner":DB::optional_string(row,"lease_owner")?,
                "lease_expires_at":json_time(state.lease_expires_at),"attempt_count":DB::optional_number(row,"attempt_count")?,
                "repair_count":DB::optional_number(row,"repair_count")?,"last_error":DB::optional_string(row,"last_error")?,
                "last_dispatch_error":state.last_dispatch_error,"last_claim_error":state.last_claim_error,
                "connection":DB::optional_string(row,"connection")?,"queue":queue,
                "workflow_wait_kind":wait_kind,"workflow_open_wait_id":payload_text("open_wait_id"),
                "workflow_resume_source_kind":resume,"workflow_resume_source_id":payload_text("resume_source_id"),
                "replay_blocked":replay_blocked,"replay_blocked_reason":payload_text("replay_blocked_reason")}));
        }
        // A summary that names a missing open workflow task.
        if (tasks.len() as i64) < TASK_LIMIT
            && let Some(task_id) = summary.text("next_task_id")
            && summary.text("next_task_type").as_deref() == Some("workflow")
            && matches!(summary.text("next_task_status").as_deref(), Some("ready" | "leased"))
            && !tasks.iter().any(|task| task["id"] == task_id.as_str())
            && Self::query("SELECT id FROM workflow_tasks WHERE id=$1 AND workflow_run_id=$2")
                .bind(task_id.as_str()).bind(&run_id).fetch_optional(&self.pool).await?.is_none()
        {
            let compatibility = summary.text("compatibility").or(run_compatibility.clone());
            let queue = summary.text("queue").or(trimmed(run_queue.clone()));
            let (in_fleet, fleet_reason) = fleet_supports(fleet, compatibility.as_deref(), queue.as_deref());
            tasks.push(json!({"id":format!("missing:workflow:{task_id}"),"type":"workflow","status":"missing",
                "transport_state":"missing","task_missing":true,"synthetic":true,"expected_task_id":task_id,
                "summary":"Workflow task referenced by the run summary is missing.","compatibility":compatibility,
                "compatibility_supported":compatibility.is_none(),
                "compatibility_reason":compatibility.as_ref().map(|required| format!("Requires compatibility [{required}]; this worker supports [none].")),
                "compatibility_supported_in_fleet":in_fleet,"compatibility_fleet_reason":fleet_reason,
                "dispatch_failed":false,"dispatch_overdue":false,"claim_failed":false,
                "available_at":summary.get("next_task_at"),"lease_expires_at":summary.get("next_task_lease_expires_at"),
                "lease_expired":false,"lease_owner":null,"attempt_count":0,"repair_count":0,
                "connection":summary.text("connection").or(DB::optional_string(run, "connection")?),"queue":queue,
                "workflow_wait_kind":summary.text("wait_kind"),"workflow_open_wait_id":summary.text("open_wait_id"),
                "workflow_resume_source_kind":summary.text("resume_source_kind"),
                "workflow_resume_source_id":summary.text("resume_source_id"),"replay_blocked":false}));
        }
        Ok(tasks)
    }

    async fn pending_activities(&self, run: &DB::Row, at: DateTime<Utc>) -> Result<Vec<Value>> {
        let run_id = DB::string(run, "id")?;
        let run_queue = trimmed(DB::optional_string(run, "queue")?);
        let rows = Self::query("SELECT * FROM activity_executions WHERE workflow_run_id=$1 AND status IN ('pending','running') ORDER BY started_at,sequence,id LIMIT 25")
            .bind(&run_id).fetch_all(&self.pool).await?;
        let mut activities = Vec::new();
        for row in &rows {
            let execution_id = DB::string(row, "id")?;
            let mut attempt = None;
            if let Some(current) = trimmed(DB::optional_string(row, "current_attempt_id")?) {
                attempt = Self::query("SELECT * FROM activity_attempts WHERE workflow_run_id=$1 AND id=$2")
                    .bind(&run_id).bind(current.as_str()).fetch_optional(&self.pool).await?;
            }
            if attempt.is_none() {
                attempt = Self::query("SELECT * FROM activity_attempts WHERE workflow_run_id=$1 AND activity_execution_id=$2 ORDER BY attempt_number DESC,started_at DESC,id DESC LIMIT 1")
                    .bind(&run_id).bind(&execution_id).fetch_optional(&self.pool).await?;
            }
            let current_attempt = match &attempt {
                Some(attempt) => {
                    let status = DB::string(attempt, "status")?;
                    let expires = DB::optional_instant(attempt, "lease_expires_at")?;
                    compact(json!({"activity_attempt_id":DB::string(attempt,"id")?,
                        "attempt_number":DB::optional_number(attempt,"attempt_number")?.unwrap_or(0),
                        "status":status,"lease_owner":DB::optional_string(attempt,"lease_owner")?,
                        "started_at":json_time(DB::optional_instant(attempt,"started_at")?),
                        "last_heartbeat_at":json_time(DB::optional_instant(attempt,"last_heartbeat_at")?),
                        "lease_expires_at":json_time(expires),
                        "lease_expired":status == "running" && expires.is_some_and(|expires| expires <= at),
                        "closed_at":json_time(DB::optional_instant(attempt,"closed_at")?)}))
                }
                None => Value::Null,
            };
            let time = |field: &str| -> Result<Value> { Ok(json_time(DB::optional_instant(row, field)?)) };
            activities.push(compact(json!({"activity_execution_id":execution_id,
                "activity_type":DB::optional_string(row,"activity_type")?,"activity_class":DB::optional_string(row,"activity_class")?,
                "status":DB::optional_string(row,"status")?,"queue":trimmed(DB::optional_string(row,"queue")?).or(run_queue.clone()),
                "attempt_count":DB::optional_number(row,"attempt_count")?.unwrap_or(0),
                "current_attempt_id":DB::optional_string(row,"current_attempt_id")?,"started_at":time("started_at")?,
                "last_heartbeat_at":time("last_heartbeat_at")?,"schedule_deadline_at":time("schedule_deadline_at")?,
                "close_deadline_at":time("close_deadline_at")?,"schedule_to_close_deadline_at":time("schedule_to_close_deadline_at")?,
                "heartbeat_deadline_at":time("heartbeat_deadline_at")?,"current_attempt":current_attempt})));
        }
        Ok(activities)
    }

    /// `WorkflowRunDiagnostics::taskQueue` with diagnostic poller counts.
    async fn diagnostic_queue(&self, queue: &str, config: &QueryTaskConfig) -> Result<Value> {
        let mut detail = self.queue_detail(queue, config).await?;
        let active = active_pollers(&detail).len();
        detail["stats"]["pollers"]["active_count"] = json!(active);
        Ok(detail)
    }

    async fn last_event(&self, run_id: &str, include: bool) -> Result<Value> {
        let sql = match DB::NAME {
            "PostgreSQL" => "SELECT sequence,event_type,recorded_at,payload::text AS payload_text FROM workflow_history_events WHERE workflow_run_id=$1 ORDER BY sequence DESC LIMIT 1",
            "MySQL" => "SELECT sequence,event_type,recorded_at,CAST(payload AS CHAR) AS payload_text FROM workflow_history_events WHERE workflow_run_id=$1 ORDER BY sequence DESC LIMIT 1",
            _ => "SELECT sequence,event_type,recorded_at,payload AS payload_text FROM workflow_history_events WHERE workflow_run_id=$1 ORDER BY sequence DESC LIMIT 1",
        };
        let Some(event) = Self::query(sql).bind(run_id).fetch_optional(&self.pool).await? else {
            return Ok(Value::Null);
        };
        let raw = DB::optional_string(&event, "payload_text")?;
        let (encoded, keys, list, count) = payload_shape(raw.as_deref());
        let summary = json!({"included":include,"encoding":"json","size_bytes":encoded.len(),
            "top_level_type":if list { "list" } else { "object" },"top_level_key_count":count,
            "top_level_keys":keys.iter().take(PAYLOAD_KEY_LIMIT).collect::<Vec<_>>(),
            "top_level_keys_truncated":keys.len() > PAYLOAD_KEY_LIMIT,"preview_max_bytes":PREVIEW_BYTES});
        let preview = include.then(|| {
            // PHP's substr cuts bytes; a cut character becomes invalid UTF-8.
            let mut end = encoded.len().min(PREVIEW_BYTES);
            while !encoded.is_char_boundary(end) {
                end -= 1;
            }
            json!({"encoding":"json","size_bytes":encoded.len(),"max_bytes":PREVIEW_BYTES,
                "truncated":encoded.len() > PREVIEW_BYTES,"data":&encoded[..end]})
        });
        Ok(compact(json!({"sequence":DB::number(&event,"sequence")?,"event_type":DB::string(&event,"event_type")?,
            "timestamp":json_time(DB::optional_instant(&event,"recorded_at")?),"payload_summary":summary,"payload_preview":preview})))
    }

    async fn recent_failures(&self, run: &DB::Row) -> Result<(Vec<Value>, bool)> {
        let run_id = DB::string(run, "id")?;
        let rows = Self::query("SELECT id,source_kind,source_id,propagation_kind,failure_category,exception_class,message,CASE WHEN non_retryable THEN 1 ELSE 0 END AS non_retryable,CASE WHEN handled THEN 1 ELSE 0 END AS handled,created_at FROM workflow_failures WHERE workflow_run_id=$1 ORDER BY created_at DESC,id DESC LIMIT 11")
            .bind(&run_id).fetch_all(&self.pool).await?;
        let pruned = DB::optional_instant(run, "details_pruned_at")?.is_some();
        let event_sql = match DB::NAME {
            "PostgreSQL" => "SELECT sequence,event_type,recorded_at FROM workflow_history_events WHERE workflow_run_id=$1 AND (payload->>'failure_id')=$2 AND event_type IN ('ActivityFailed','ActivityTimedOut','ChildRunFailed','ChildRunCancelled','ChildRunTerminated','WorkflowFailed','WorkflowTimedOut','WorkflowCancelled','WorkflowTerminated','UpdateCompleted') ORDER BY sequence DESC LIMIT 1",
            "MySQL" => "SELECT sequence,event_type,recorded_at FROM workflow_history_events WHERE workflow_run_id=$1 AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.failure_id'))=$2 AND event_type IN ('ActivityFailed','ActivityTimedOut','ChildRunFailed','ChildRunCancelled','ChildRunTerminated','WorkflowFailed','WorkflowTimedOut','WorkflowCancelled','WorkflowTerminated','UpdateCompleted') ORDER BY sequence DESC LIMIT 1",
            _ => "SELECT sequence,event_type,recorded_at FROM workflow_history_events WHERE workflow_run_id=$1 AND json_extract(payload,'$.failure_id')=$2 AND event_type IN ('ActivityFailed','ActivityTimedOut','ChildRunFailed','ChildRunCancelled','ChildRunTerminated','WorkflowFailed','WorkflowTimedOut','WorkflowCancelled','WorkflowTerminated','UpdateCompleted') ORDER BY sequence DESC LIMIT 1",
        };
        let mut failures = Vec::new();
        for row in rows.iter().take(FAILURE_LIMIT) {
            let failure_id = DB::string(row, "id")?;
            let event = Self::query(event_sql).bind(&run_id).bind(failure_id.as_str()).fetch_optional(&self.pool).await?;
            let supporting = match event {
                Some(event) => {
                    let sequence = DB::number(&event, "sequence")?;
                    json!({"state":"retained","sequence":sequence,"event_type":DB::string(&event,"event_type")?,
                        "recorded_at":json_time(DB::optional_instant(&event,"recorded_at")?),
                        "next_page_token":STANDARD.encode((sequence - 1).max(0).to_string())})
                }
                None => json!({"state":if pruned { "pruned" } else { "unavailable" }}),
            };
            failures.push(compact(json!({"failure_id":failure_id,"source_kind":DB::optional_string(row,"source_kind")?,
                "source_id":DB::optional_string(row,"source_id")?,"propagation_kind":DB::optional_string(row,"propagation_kind")?,
                "failure_category":DB::optional_string(row,"failure_category")?,"exception_class":DB::optional_string(row,"exception_class")?,
                "message":DB::optional_string(row,"message")?,"non_retryable":DB::number(row,"non_retryable")? != 0,
                "handled":DB::number(row,"handled")? != 0,"created_at":json_time(DB::optional_instant(row,"created_at")?),
                "supporting_event":supporting})));
        }
        Ok((failures, rows.len() > FAILURE_LIMIT))
    }

    async fn latest_task_failure(&self, run_id: &str) -> Result<Value> {
        let Some(task) = Self::query("SELECT id,last_error,payload,updated_at FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status='failed' ORDER BY updated_at DESC LIMIT 1")
            .bind(run_id).fetch_optional(&self.pool).await?
        else {
            return Ok(Value::Null);
        };
        let payload = DB::optional_document_row(&task, "payload")?.unwrap_or(Value::Null);
        let text = |value: &Value| value.as_str().map(str::trim).filter(|text| !text.is_empty()).map(str::to_owned);
        Ok(compact(json!({"task_id":DB::string(&task,"id")?,"message":DB::optional_string(&task,"last_error")?,
            "type":text(&payload["failure_type"]).or_else(|| text(&payload["replay_blocked_failure_type"])),
            "reason":text(&payload["failure_reason"]),"sequence":payload["failure_sequence"].as_i64(),
            "replay_blocked":payload["replay_blocked"] == true,"recorded_at":json_time(DB::optional_instant(&task,"updated_at")?)})))
    }

    /// `WorkflowRunDiagnostics::pendingWorkflowRoutingFindings`.
    async fn routing_findings(
        &self,
        run: &DB::Row,
        tasks: &[Value],
        at: DateTime<Utc>,
        fleet: &[(String, Option<String>, Vec<String>, DateTime<Utc>)],
        config: &QueryTaskConfig,
    ) -> Result<Vec<Value>> {
        let mut findings = Vec::new();
        let mut checked = BTreeSet::new();
        let workflow_type = DB::optional_string(run, "workflow_type")?.unwrap_or_default();
        for task in tasks {
            let future = task["available_at"]
                .as_str()
                .and_then(|time| DateTime::parse_from_rfc3339(time).ok())
                .is_some_and(|time| time.with_timezone(&Utc) > at);
            if task["status"] != "ready" || task["task_missing"] == true || future {
                continue;
            }
            let queue = task["queue"].as_str().map(str::to_owned).or(trimmed(DB::optional_string(run, "queue")?));
            let connection = task["connection"].as_str().map(str::to_owned).or(trimmed(DB::optional_string(run, "connection")?));
            let build = task["compatibility"].as_str().map(str::to_owned);
            let Some(queue) = queue else { continue };
            if !checked.insert((connection.clone(), queue.clone(), build.clone())) {
                continue;
            }
            let rollout = Self::query("SELECT drain_intent FROM workflow_worker_build_id_rollouts WHERE namespace=$1 AND task_queue=$2 AND build_id=$3 LIMIT 1")
                .bind(self.namespace.as_ref()).bind(queue.as_str()).bind(build.clone().unwrap_or_default())
                .fetch_optional(&self.pool).await?;
            let label = build.as_ref().map_or_else(|| "the unversioned build".to_owned(), |build| format!("build [{build}]"));
            let context = json!({"severity":"warning","task_id":task["id"],"task_queue":queue,"connection":connection,
                "required_build_id":build});
            let with = |mut finding: Value| {
                for (key, value) in context.as_object().expect("object") {
                    finding[key] = value.clone();
                }
                finding
            };
            if let Some(rollout) = rollout
                && DB::optional_string(&rollout, "drain_intent")?.as_deref() == Some("draining")
            {
                findings.push(with(json!({"code":"workflow_build_draining","routing_status":"draining",
                    "message":format!("A ready workflow task is blocked because {label} on task queue [{queue}] is drained. Resume the build and start a compatible workflow worker. Resume does not restart an exited process."),
                    "next_event":"Delivery can resume after the build is resumed and a compatible workflow worker is running.",
                    "expected_resolution":format!("Resume {label} on task queue [{queue}] and start or restart a compatible workflow worker. Resume does not restart an exited process. The existing run keeps its build and history.")})));
                continue;
            }
            // Unversioned work keeps the no-active-pollers finding instead.
            let Some(required) = build.as_deref() else { continue };
            let compatible: BTreeSet<&str> = fleet
                .iter()
                .filter(|(_, worker_queue, supported, _)| {
                    worker_queue.as_deref() == Some(queue.as_str()) && supported.iter().any(|marker| marker == "*" || marker == required)
                })
                .map(|(worker, _, _, _)| worker.as_str())
                .collect();
            let detail = self.diagnostic_queue(&queue, config).await?;
            let eligible = active_pollers(&detail).into_iter().any(|poller| {
                poller["build_id"].as_str() == Some(required)
                    && poller["worker_id"].as_str().is_some_and(|worker| compatible.contains(worker))
                    && strings(&poller["supported_workflow_types"]).iter().any(|kind| *kind == workflow_type)
            });
            if !eligible {
                findings.push(with(json!({"code":"no_eligible_workflow_worker","routing_status":"no_eligible_worker",
                    "message":format!("A ready workflow task requires {label} on task queue [{queue}], but no active compatible workflow worker can receive it. Start or restart a worker for this build and workflow type."),
                    "next_event":"The task stays ready until a compatible workflow worker registers and polls.",
                    "expected_resolution":format!("Start or restart a workflow worker for {label}, task queue [{queue}] and workflow type [{workflow_type}]. Resuming a build does not start a worker process. Keep the existing run and its recorded history.")})));
            }
        }
        Ok(findings)
    }

    /// `WorkflowRunDiagnostics::forRun`.
    pub(crate) async fn run_diagnostics(
        &self,
        workflow_id: &str,
        selected_run: Option<&str>,
        include_payload: bool,
        config: &QueryTaskConfig,
    ) -> Result<Value> {
        let run = Self::query("SELECT r.* FROM workflow_runs r JOIN workflow_instances i ON i.id=r.workflow_instance_id WHERE i.id=$1 AND i.namespace=$2 AND r.id=COALESCE($3,i.current_run_id)")
            .bind(workflow_id).bind(self.namespace.as_ref()).bind(selected_run).fetch_optional(&self.pool).await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND, if selected_run.is_some() { "run_not_found" } else { "instance_not_found" }))?;
        let at = now();
        let run_id = DB::string(&run, "id")?;
        let status = DB::string(&run, "status")?;
        let terminal = !matches!(status.as_str(), "pending" | "running" | "waiting");
        let summary = self.diagnostic_summary(&run_id).await?;
        let fleet = self.fleet_snapshots(at).await?;
        let rows = self.task_rows(&run, &summary, at, &fleet).await?;
        let pending_tasks: Vec<Value> = rows.iter().map(pending_task_view).collect();
        let activities = self.pending_activities(&run, at).await?;
        let run_queue = trimmed(DB::optional_string(&run, "queue")?);
        let task_queue = match &run_queue {
            Some(queue) => self.diagnostic_queue(queue, config).await?,
            None => Value::Null,
        };
        let mut activity_queues = Map::new();
        for activity in &activities {
            if let Some(queue) = activity["queue"].as_str()
                && !activity_queues.contains_key(queue)
            {
                activity_queues.insert(queue.to_owned(), self.diagnostic_queue(queue, config).await?);
            }
        }
        let last_event = self.last_event(&run_id, include_payload).await?;
        let (failures, truncated) = self.recent_failures(&run).await?;
        let fields = RunFields {
            workflow_id: DB::string(&run, "workflow_instance_id")?,
            run_id,
            status,
            workflow_type: DB::optional_string(&run, "workflow_type")?,
            queue: DB::optional_string(&run, "queue")?,
            compatibility: DB::optional_string(&run, "compatibility")?,
            run_number: DB::optional_number(&run, "run_number")?.unwrap_or(0),
            started_at: DB::optional_instant(&run, "started_at")?,
            closed_at: DB::optional_instant(&run, "closed_at")?,
            last_progress_at: DB::optional_instant(&run, "last_progress_at")?,
        };
        let latest_failure = self.latest_task_failure(&fields.run_id).await?;
        let mut payload = diagnostics_payload(Diagnostics {
            at,
            namespace: self.namespace.as_ref(),
            run: &fields,
            terminal,
            summary: &summary,
            rows: &rows,
            pending_tasks,
            activities,
            task_queue,
            activity_queues,
            last_event,
            failures,
            truncated,
            latest_failure,
            fleet: &fleet,
            fleet_summary: self.fleet_summary(&fleet),
        });
        let mut findings = findings(&payload);
        if !terminal {
            findings.extend(self.routing_findings(&run, &rows, at, &fleet, config).await?);
        }
        payload["findings"] = json!(findings);
        Ok(payload)
    }
}

/// `WorkerCompatibilityFleet::detailsForNamespace` support for a marker.
pub(super) fn fleet_supports(snapshots: &[(String, Option<String>, Vec<String>, DateTime<Utc>)], compatibility: Option<&str>, queue: Option<&str>) -> (bool, Option<String>) {
    let Some(required) = compatibility else {
        return (true, None);
    };
    let matching: Vec<_> = snapshots
        .iter()
        .filter(|(_, worker_queue, _, _)| queue.is_none() || worker_queue.as_deref() == queue)
        .collect();
    if matching.iter().any(|(_, _, supported, _)| supported.iter().any(|marker| marker == "*" || marker == required)) {
        return (true, None);
    }
    let advertised: BTreeSet<&str> = matching.iter().flat_map(|(_, _, supported, _)| supported.iter().map(String::as_str)).collect();
    let suffix = if advertised.is_empty() {
        String::new()
    } else {
        format!(" Active workers there advertise [{}].", advertised.into_iter().collect::<Vec<_>>().join(", "))
    };
    (false, Some(format!(
        "No active worker heartbeat for task queue [{}] advertises compatibility [{required}].{suffix}",
        queue.unwrap_or("default")
    )))
}

/// The run fields the diagnostics view reports.
struct RunFields {
    workflow_id: String,
    run_id: String,
    status: String,
    workflow_type: Option<String>,
    queue: Option<String>,
    compatibility: Option<String>,
    run_number: i64,
    started_at: Option<DateTime<Utc>>,
    closed_at: Option<DateTime<Utc>>,
    last_progress_at: Option<DateTime<Utc>>,
}

/// Everything `forRun` reports, gathered from the store.
struct Diagnostics<'a> {
    at: DateTime<Utc>,
    namespace: &'a str,
    run: &'a RunFields,
    terminal: bool,
    summary: &'a Summary,
    rows: &'a [Value],
    pending_tasks: Vec<Value>,
    activities: Vec<Value>,
    task_queue: Value,
    activity_queues: Map<String, Value>,
    last_event: Value,
    failures: Vec<Value>,
    truncated: bool,
    latest_failure: Value,
    fleet: &'a [(String, Option<String>, Vec<String>, DateTime<Utc>)],
    fleet_summary: Value,
}

/// `nextScheduledEvent`: the summary's next task, else the first open task
/// with a time.
fn next_event_view(summary: &Summary, rows: &[Value]) -> Value {
    if let Some(task_id) = summary.text("next_task_id") {
        return compact(json!({"source":"run_summary","task_id":task_id,"task_type":summary.get("next_task_type"),
            "task_status":summary.get("next_task_status"),"available_at":summary.get("next_task_at"),
            "lease_expires_at":summary.get("next_task_lease_expires_at"),"wait_deadline_at":summary.get("wait_deadline_at")}));
    }
    rows.iter()
        .find_map(|task| {
            let available = [&task["available_at"], &task["lease_expires_at"]].into_iter().find(|time| !time.is_null())?;
            Some(compact(json!({"source":"open_task","task_id":task["id"],"task_type":task["type"],
                "task_status":task["status"],"available_at":available})))
        })
        .unwrap_or(Value::Null)
}

/// The assembled `WorkflowRunDiagnostics::forRun` payload before findings.
/// Kept outside the generic store so each backend shares one compiled copy.
fn diagnostics_payload(view: Diagnostics<'_>) -> Value {
    let Diagnostics { at, namespace, run, terminal, summary, rows, pending_tasks, activities, task_queue,
        activity_queues, last_event, failures, truncated, latest_failure, fleet, fleet_summary } = view;
    let task_problem = summary.present.then(|| summary.get("task_problem") == true);
    let execution = compact(json!({"status":run.status,"status_bucket":summary.get("status_bucket"),"is_terminal":terminal,
        "workflow_type":run.workflow_type,"task_queue":run.queue,"compatibility":run.compatibility,"run_number":run.run_number,
        "started_at":json_time(run.started_at),"closed_at":json_time(run.closed_at),
        "last_progress_at":json_time(run.last_progress_at),"wait_kind":summary.get("wait_kind"),
        "wait_reason":summary.get("wait_reason"),"liveness_state":summary.get("liveness_state"),
        "liveness_reason":summary.get("liveness_reason"),
        "repair_attention":summary.present.then(|| summary.get("repair_attention") == true),"task_problem":task_problem,
        "task_problem_badge":if summary.present {
            task_problem_badge(task_problem == Some(true), summary.text("liveness_state").as_deref(), summary.text("wait_kind").as_deref())
        } else { Value::Null },
        "last_event":last_event,"next_scheduled_event":next_event_view(summary, rows)}));
    let diagnostic_status = if terminal {
        "terminal"
    } else if summary.get("task_problem") == true || summary.get("repair_attention") == true {
        "needs_attention"
    } else if !pending_tasks.is_empty() || !activities.is_empty() {
        "pending_work"
    } else if summary.text("wait_kind").is_some() {
        "waiting"
    } else if task_queue["stats"]["approximate_backlog_count"].as_i64().unwrap_or(0) > 0 {
        "queue_backlog"
    } else {
        "running"
    };
    let compatibility = trimmed(summary.text("compatibility")).or(trimmed(run.compatibility.clone()));
    let queue = summary.text("queue").or(trimmed(run.queue.clone()));
    let (in_fleet, fleet_reason) = fleet_supports(fleet, compatibility.as_deref(), queue.as_deref());
    let pollers = task_queue["pollers"].clone();
    json!({"generated_at":json_time(Some(at)),"workflow_id":run.workflow_id,
        "run_id":run.run_id,"namespace":namespace,"diagnostic_status":diagnostic_status,"execution":execution,
        "pending_workflow_tasks":pending_tasks,"pending_activities":activities,"task_queue":task_queue,
        "activity_task_queues":if activity_queues.is_empty() { json!([]) } else { Value::Object(activity_queues) },
        "recent_failures":failures,"recent_failures_truncated":truncated,
        "latest_workflow_task_failure":latest_failure,
        "compatibility":compact(json!({"run":{"compatibility":run.compatibility,
            "summary_compatibility":summary.get("compatibility"),"task_queue":run.queue,
            "compatibility_supported_in_fleet":in_fleet,"compatibility_fleet_reason":fleet_reason},
            "task_queue_pollers":if pollers.is_array() { pollers } else { json!([]) },
            "namespace_worker_fleet":fleet_summary})),
        "cancellation_cascade_supported":false,"cancellation_cascade":null})
}

/// One `pending_workflow_tasks` entry. Kept outside the generic store so
/// each backend shares one compiled copy of these large literals.
fn pending_task_view(task: &Value) -> Value {
    compact(json!({"task_id":task["id"],"task_type":task["type"],"status":task["status"],
        "transport_state":task["transport_state"],"summary":task["summary"],"queue":task["queue"],
        "compatibility":task["compatibility"],"compatibility_supported":task["compatibility_supported"],
        "compatibility_reason":task["compatibility_reason"],
        "compatibility_supported_in_fleet":task["compatibility_supported_in_fleet"],
        "compatibility_fleet_reason":task["compatibility_fleet_reason"],"available_at":task["available_at"],
        "leased_at":task["leased_at"],"lease_owner":task["lease_owner"],"lease_expires_at":task["lease_expires_at"],
        "lease_expired":task["lease_expired"],"attempt_count":task["attempt_count"],"repair_count":task["repair_count"],
        "dispatch_failed":task["dispatch_failed"],"dispatch_overdue":task["dispatch_overdue"],
        "claim_failed":task["claim_failed"],"last_error":task["last_error"],
        "last_dispatch_error":task["last_dispatch_error"],"last_claim_error":task["last_claim_error"],
        "repair_available_at":task["repair_available_at"],"workflow_wait_kind":task["workflow_wait_kind"],
        "workflow_open_wait_id":task["workflow_open_wait_id"],
        "workflow_resume_source_kind":task["workflow_resume_source_kind"],
        "workflow_resume_source_id":task["workflow_resume_source_id"],"replay_blocked":task["replay_blocked"],
        "replay_blocked_reason":task["replay_blocked_reason"],"task_missing":task["task_missing"],
        "expected_task_id":task["expected_task_id"],"synthetic":task["synthetic"]}))
}

/// `activePollers`: fresh pollers whose status is active.
fn active_pollers(detail: &Value) -> Vec<&Value> {
    detail["pollers"]
        .as_array()
        .map(|pollers| {
            pollers
                .iter()
                .filter(|poller| poller["is_stale"] != true && poller.get("status").and_then(Value::as_str).unwrap_or("active") == "active")
                .collect()
        })
        .unwrap_or_default()
}

fn strings(value: &Value) -> Vec<String> {
    value
        .as_array()
        .map(|items| items.iter().filter_map(Value::as_str).map(str::trim).filter(|text| !text.is_empty()).map(str::to_owned).collect())
        .unwrap_or_default()
}

/// `WorkflowRunDiagnostics::findings`.
fn findings(payload: &Value) -> Vec<Value> {
    let mut findings = Vec::new();
    let tasks = payload["pending_workflow_tasks"].as_array().cloned().unwrap_or_default();
    let activities = payload["pending_activities"].as_array().cloned().unwrap_or_default();
    let queue = &payload["task_queue"];
    let active = if queue.is_object() {
        active_pollers(queue).len() as i64
    } else {
        queue["stats"]["pollers"]["active_count"].as_i64().unwrap_or(0)
    };
    if (!tasks.is_empty() || !activities.is_empty()) && active == 0 {
        findings.push(json!({"severity":"warning","code":"no_active_pollers","message":"The run has pending work but no active pollers on its task queue."}));
    }
    if let (Some(workflow_type), true) = (payload["execution"]["workflow_type"].as_str(), queue.is_object()) {
        let pollers = active_pollers(queue);
        let name = queue["name"].as_str();
        if !pollers.is_empty()
            && let Some(task) = tasks.iter().find(|task| {
                task["status"] == "ready"
                    && task["task_missing"] != true
                    && task["dispatch_failed"] != true
                    && task["claim_failed"] != true
                    && task["lease_expired"] != true
                    && task["queue"].as_str().or(name) == name
            })
            && !pollers.iter().any(|poller| strings(&poller["supported_workflow_types"]).iter().any(|kind| kind == workflow_type))
        {
            let queue_name = task["queue"].as_str().or(name).unwrap_or_default();
            let workers: Vec<Value> = pollers.iter().take(FINDING_WORKER_LIMIT).map(|poller| {
                let types = strings(&poller["supported_workflow_types"]);
                let mut worker = json!({"worker_id":poller["worker_id"],"runtime":poller["runtime"],
                    "supported_workflow_types":types.iter().take(FINDING_WORKFLOW_TYPE_LIMIT).collect::<Vec<_>>(),
                    "supported_workflow_type_count":types.len(),"supported_workflow_type_limit":FINDING_WORKFLOW_TYPE_LIMIT,
                    "supported_workflow_types_truncated":types.len() > FINDING_WORKFLOW_TYPE_LIMIT});
                worker.as_object_mut().expect("object").retain(|_, value| !value.is_null());
                worker
            }).collect();
            findings.push(json!({"severity":"warning","code":"pending_workflow_type_unsupported",
                "message":format!("Workflow type [{workflow_type}] is pending on task queue [{queue_name}], but no active poller on that queue advertises that exact workflow type. Workflow type matching is exact and case-sensitive; register and start a worker that advertises [{workflow_type}] on [{queue_name}]."),
                "task_id":task["task_id"],"task_queue":queue_name,"workflow_type":workflow_type,"workflow_type_match":"exact_case_sensitive",
                "active_worker_count":pollers.len(),"active_worker_limit":FINDING_WORKER_LIMIT,
                "active_workers_truncated":pollers.len() > FINDING_WORKER_LIMIT,"active_workers":workers}));
        }
    }
    let expired = queue["stats"]["workflow_tasks"]["expired_lease_count"].as_i64().unwrap_or(0)
        + queue["stats"]["activity_tasks"]["expired_lease_count"].as_i64().unwrap_or(0);
    if expired > 0 {
        findings.push(json!({"severity":"warning","code":"expired_leases","message":"The task queue has expired workflow or activity leases."}));
    }
    let execution = &payload["execution"];
    if execution["task_problem"] == true {
        let historical = execution["task_problem_badge"]["code"] == "history";
        findings.push(json!({"severity":if historical { "info" } else { "warning" },
            "code":if historical { "task_recovery_history" } else { "task_problem" },
            "message":if historical { "The run previously needed workflow-task repair or replay recovery." } else { "The run summary has a task problem flag." }}));
    }
    if execution["repair_attention"] == true {
        findings.push(json!({"severity":"warning","code":"repair_attention","message":"The run needs repair attention."}));
    }
    if execution["liveness_state"] == "workflow_replay_blocked" {
        findings.push(json!({"severity":"error","code":"workflow_replay_blocked",
            "message":execution.get("liveness_reason").cloned().unwrap_or_else(|| json!("Workflow replay is blocked."))}));
    }
    if tasks.iter().any(|task| task["replay_blocked"] == true) {
        findings.push(json!({"severity":"error","code":"workflow_replay_blocked","message":"A pending workflow task is replay-blocked."}));
    }
    if let Some(task) = tasks.iter().find(|task| task["compatibility"].is_string() && task.get("compatibility_supported_in_fleet").is_some_and(|value| value != true)) {
        let compatibility = task["compatibility"].as_str().unwrap_or_default();
        findings.push(json!({"severity":"error","code":"no_compatible_worker",
            "message":format!("A workflow task requires compatibility [{compatibility}], but no active worker on its task queue advertises that marker."),
            "task_id":task["task_id"],"task_queue":task["queue"],"compatibility":compatibility,
            "compatibility_fleet_reason":task["compatibility_fleet_reason"]}));
    }
    let queues = &payload["activity_task_queues"];
    let mut seen = BTreeSet::new();
    for activity in &activities {
        let (Some(queue), Some(kind)) = (activity["queue"].as_str(), activity["activity_type"].as_str()) else { continue };
        if !seen.insert(format!("{queue}|{kind}")) {
            continue;
        }
        let pollers = if queues[queue].is_object() { active_pollers(&queues[queue]) } else { vec![] };
        if pollers.is_empty() {
            findings.push(json!({"severity":"warning","code":"pending_activity_queue_without_active_pollers",
                "message":format!("Activity [{kind}] is pending on task queue [{queue}], but that queue has no active pollers."),
                "task_queue":queue,"activity_type":kind,"activity_execution_id":activity["activity_execution_id"]}));
            continue;
        }
        if pollers.iter().any(|poller| strings(&poller["supported_activity_types"]).iter().any(|supported| supported == kind)) {
            continue;
        }
        findings.push(json!({"severity":"warning","code":"pending_activity_type_unsupported",
            "message":format!("Activity [{kind}] is pending on task queue [{queue}], but no active poller on that queue advertises that activity type."),
            "task_queue":queue,"activity_type":kind,"activity_execution_id":activity["activity_execution_id"],
            "active_workers":pollers.iter().map(|poller| compact(json!({"worker_id":poller["worker_id"],"runtime":poller["runtime"],
                "supported_activity_types":strings(&poller["supported_activity_types"])}))).collect::<Vec<_>>()}));
    }
    findings
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn payload_summaries_reencode_as_php_json_encode_does() {
        let stored = "{\"z\":\"a/b\",\"\u{e9}\":\"\u{3bb}\",\"n\":1.50,\"i\":7,\"x\":[1,{\"k\":null}]}";
        let (encoded, keys, list, count) = payload_shape(Some(stored));
        let escaped = format!("{{\"z\":\"a/b\",\"{0}u00e9\":\"{0}u03bb\",\"n\":1.5,\"i\":7,\"x\":[1,{{\"k\":null}}]}}", '\\');
        assert_eq!(encoded, escaped);
        assert_eq!((keys, list, count), (vec!["z".to_owned(), "\u{e9}".into(), "n".into(), "i".into(), "x".into()], false, 5));
        assert!(payload_shape(Some("[1,2]")).2);
        assert_eq!(payload_shape(Some("null")).0, "[]");
        for (value, php) in [(100.0, "100.0"), (1e25, "1.0e+25"), (0.0001, "0.0001"), (1e-5, "1.0e-5"), (1e14, "100000000000000.0"),
            (1e15, "1.0e+15"), (-2.5, "-2.5"), (0.0, "0.0"), (123456789012345678.0, "1.2345678901234568e+17")]
        {
            assert_eq!(php_float(value), php, "{value}");
        }
    }

    #[test]
    fn badges_follow_workflow_task_problem() {
        assert_eq!(task_problem_badge(false, Some("workflow_replay_blocked"), None), Value::Null);
        assert_eq!(task_problem_badge(true, Some("workflow_replay_blocked"), None)["code"], "replay_blocked");
        assert_eq!(task_problem_badge(true, Some("repair_needed"), Some("signal"))["code"], "active");
        assert_eq!(task_problem_badge(true, Some("workflow_task_ready"), None)["code"], "history");
    }
}
