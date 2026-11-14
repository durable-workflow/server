//! Read-only version-marker usage. Missing evidence cannot establish that a
//! legacy branch is unused.
use super::{Result, RuntimeError};
use axum::http::StatusCode;
use base64::{Engine, engine::general_purpose::URL_SAFE_NO_PAD};
use futures_util::TryStreamExt;
use hmac::{Hmac, KeyInit, Mac};
use serde::{Deserialize, Serialize};
use serde_json::{Value, json};
use sha2::Sha256;
use std::collections::BTreeSet;

const MARKER_BUDGET: usize = 1000;
pub(super) const CAPABILITY: &str = "workflow_version_usage";

fn valid_reuse_clock(value: &str) -> bool {
    if !value.is_ascii() || !(20..=35).contains(&value.len()) || value.as_bytes()[10] != b'T' {
        return false;
    }
    let zone = if value.ends_with('Z') { value.len()-1 } else {
        let at = value.len()-6;
        if !matches!(value.as_bytes()[at], b'+' | b'-') || value.as_bytes()[at+3] != b':' { return false; }
        at
    };
    if zone < 19 { return false; }
    let fraction = &value[19..zone];
    let precision = fraction.is_empty() || fraction.strip_prefix('.').is_some_and(|digits|
        (1..=9).contains(&digits.len()) && digits.bytes().all(|byte| byte.is_ascii_digit()));
    precision && chrono::DateTime::parse_from_rfc3339(value).is_ok()
}

fn valid_reuse_provenance(payload: &Value) -> bool {
    match (payload.get("reused_from_run_id"), payload.get("reused_recorded_at")) {
        (None, None) => true,
        (Some(Value::String(source)), Some(Value::String(clock))) =>
            !source.is_empty() && source.len() <= 255 && source.trim() == source.as_str()
                && !source.chars().any(char::is_control) && valid_reuse_clock(clock),
        _ => false,
    }
}

#[derive(Default, Deserialize)]
pub(super) struct Query {
    workflow_type: Option<String>,
    change_id: Option<String>,
    min_version: Option<String>,
    state: Option<String>,
    limit: Option<String>,
    cursor: Option<String>,
}

pub(super) struct Filter {
    workflow_type: String,
    change_id: String,
    min_version: i32,
    state: Option<String>,
    limit: usize,
    cursor: Option<Cursor>,
}

#[derive(Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
struct Cursor {
    version: u8,
    namespace: String,
    workflow_type: String,
    change_id: String,
    min_version: i32,
    state: Option<String>,
    after: String,
    through: String,
}

impl Cursor {
    fn mac(key: &str, body: &[u8]) -> Hmac<Sha256> {
        let mut mac = Hmac::<Sha256>::new_from_slice(key.as_bytes()).expect("HMAC accepts any key length");
        mac.update(b"durable-workflow/version-usage/v1\0");
        mac.update(body);
        mac
    }

    fn encode(&self, key: &str) -> Result<String> {
        let body = serde_json::to_vec(self)?;
        Ok(format!("{}.{}", URL_SAFE_NO_PAD.encode(&body),
            URL_SAFE_NO_PAD.encode(Self::mac(key, &body).finalize().into_bytes())))
    }

    fn decode(token: &str, key: &str) -> Result<Self> {
        let bad = || invalid("cursor", "The version-usage cursor is invalid.");
        // Legal UTF-8 query values can expand sixfold in JSON escaping.
        if token.len() > 16384 { return Err(bad()); }
        let (body, tag) = token.split_once('.').ok_or_else(bad)?;
        let body = URL_SAFE_NO_PAD.decode(body).map_err(|_| bad())?;
        let tag = URL_SAFE_NO_PAD.decode(tag).map_err(|_| bad())?;
        Self::mac(key, &body).verify_slice(&tag).map_err(|_| bad())?;
        serde_json::from_slice(&body).map_err(|_| bad())
    }
}

pub(super) fn invalid(field: &str, message: &str) -> RuntimeError {
    RuntimeError::Protocol {
        status: StatusCode::UNPROCESSABLE_ENTITY,
        response: super::control_plane::Operation::VersionUsage.response(json!({"reason":"validation_failed", "message":message,
            "errors":{(field):[message]}, "validation_errors":{(field):[message]}})),
    }
}

impl Query {
    pub(super) fn validate(self, key: &str) -> Result<Filter> {
        let required = |value: Option<String>, key, maximum| {
            value.filter(|value| !value.trim().is_empty() && value.len() <= maximum)
                .ok_or_else(|| invalid(key, "A nonempty string within the supported byte limit is required."))
        };
        let workflow_type = required(self.workflow_type, "workflow_type", 255)?;
        let change_id = required(self.change_id, "change_id", 1024)?;
        let min_version = self.min_version.as_deref().and_then(|value| value.parse::<i32>().ok())
            .ok_or_else(|| invalid("min_version", "A signed 32-bit integer is required."))?;
        if self.state.as_deref().is_some_and(|state| !matches!(state, "legacy" | "current" | "unknown")) {
            return Err(invalid("state", "State must be legacy, current, or unknown."));
        }
        let limit = match self.limit {
            Some(value) => value.parse::<usize>().ok().filter(|value| (1..=200).contains(value))
                .ok_or_else(|| invalid("limit", "Limit must be an integer between 1 and 200."))?,
            None => 50,
        };
        let cursor = self.cursor.map(|token| {
            let cursor = Cursor::decode(&token, key)?;
            if cursor.version != 1 || cursor.workflow_type != workflow_type || cursor.change_id != change_id
                || cursor.min_version != min_version || cursor.state != self.state
                || cursor.after.is_empty() || cursor.through.is_empty() || cursor.after > cursor.through
                || cursor.after.len() > 255 || cursor.through.len() > 255
            {
                return Err(invalid("cursor", "The cursor does not match this version-usage query."));
            }
            Ok(cursor)
        }).transpose()?;
        Ok(Filter { workflow_type, change_id, min_version, state: self.state, limit, cursor })
    }
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(super) enum Usage {
    Legacy(i32),
    Current(i32),
    Unknown(Unknown),
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(super) enum Unknown {
    HistoryUnavailable,
    MarkerMissing,
    MarkerInvalid,
    ConflictingMarkers,
}

/// Accumulate only the requested change point, without retaining run history.
/// A second marker cannot silently replace evidence for the same change ID.
pub(super) struct Evidence<'a> {
    change_id: &'a str,
    history_available: bool,
    started: bool,
    version: Option<i32>,
    logical_sequences: BTreeSet<i64>,
    invalid: bool,
    conflicting: bool,
    markers: usize,
    sequence: i64,
}

impl<'a> Evidence<'a> {
    pub(super) fn new(change_id: &'a str, history_available: bool) -> Self {
        Self {
            change_id,
            history_available,
            started: false,
            version: None,
            logical_sequences: BTreeSet::new(),
            invalid: false,
            conflicting: false,
            markers: 0,
            sequence: 0,
        }
    }

    pub(super) fn record(&mut self, event_type: &str, payload: &Value) {
        match event_type {
            "WorkflowStarted" => self.started = true,
            "VersionMarkerRecorded" => {
                self.markers = self.markers.saturating_add(1);
                if self.markers > MARKER_BUDGET {
                    self.history_available = false;
                    return;
                }
                let Some(change_id) = payload.get("change_id").and_then(Value::as_str).filter(|value| !value.is_empty()) else {
                    // Its identity is unavailable; it might be the requested marker.
                    self.invalid = true;
                    return;
                };
                let integer = |key| payload.get(key).and_then(Value::as_i64).and_then(|value| i32::try_from(value).ok());
                let (Some(version), Some(minimum), Some(maximum)) = (integer("version"), integer("min_supported"), integer("max_supported")) else {
                    self.invalid = true;
                    return;
                };
                let logical = payload.get("sequence").and_then(Value::as_i64);
                if minimum > version || version > maximum
                    || !logical.is_some_and(|sequence| sequence > 0 && !self.logical_sequences.contains(&sequence))
                    || !valid_reuse_provenance(payload)
                {
                    self.invalid = true;
                    return;
                }
                // Native retains a task snapshot in addition to the portable
                // decision fields. Unknown extensions need a reader upgrade.
                if payload.as_object().expect("a decision object").keys().any(|key| !matches!(key.as_str(),
                    "change_id" | "version" | "min_supported" | "max_supported" | "sequence"
                    | "task" | "reused_from_run_id" | "reused_recorded_at"))
                    || payload.get("task").is_some_and(|task| !task.is_object())
                {
                    self.history_available = false;
                    return;
                }
                self.logical_sequences.insert(logical.expect("validated logical sequence"));
                if change_id != self.change_id {
                    return;
                }
                if self.version.is_some_and(|previous| previous != version) {
                    self.conflicting = true;
                }
                self.version = Some(version);
            }
            _ => {}
        }
    }

    fn event(&mut self, sequence: i64, event_type: &str, payload: Option<&str>) {
        // Ordinary native starts/redrives retain StartAccepted before
        // WorkflowStarted; child/continued runs start with WorkflowStarted.
        let prefix_valid = match (self.sequence, self.started, event_type) {
            (0, false, "StartAccepted" | "WorkflowStarted") => true,
            (1, false, "WorkflowStarted") => true,
            (_, true, "StartAccepted" | "WorkflowStarted") => false,
            (_, true, _) => true,
            _ => false,
        };
        if self.sequence.checked_add(1) != Some(sequence) || !prefix_valid
            || !super::workflow_import::HISTORY_EVENT_TYPES.contains(&event_type)
        {
            self.history_available = false;
        }
        self.sequence = sequence;
        if event_type == "VersionMarkerRecorded" {
            if self.markers >= MARKER_BUDGET {
                self.history_available = false;
                return;
            }
            match payload.and_then(|payload| serde_json::from_str(payload).ok()) {
                Some(payload) => self.record(event_type, &payload),
                None => { self.markers += 1; self.invalid = true; },
            }
        } else if event_type == "WorkflowStarted" {
            self.started = true;
        }
    }

    pub(super) fn classify(&self, minimum_version: i32) -> Usage {
        if !self.history_available || !self.started {
            Usage::Unknown(Unknown::HistoryUnavailable)
        } else if self.invalid {
            Usage::Unknown(Unknown::MarkerInvalid)
        } else if self.conflicting {
            Usage::Unknown(Unknown::ConflictingMarkers)
        } else {
            match self.version {
                Some(version) if version < minimum_version => Usage::Legacy(version),
                Some(version) => Usage::Current(version),
                None => Usage::Unknown(Unknown::MarkerMissing),
            }
        }
    }
}

impl Usage {
    fn fields(self) -> (&'static str, Option<i32>, Option<&'static str>) {
        match self {
            Self::Legacy(version) => ("legacy", Some(version), None),
            Self::Current(version) => ("current", Some(version), None),
            Self::Unknown(reason) => ("unknown", None, Some(match reason {
                Unknown::HistoryUnavailable => "history_unavailable",
                Unknown::MarkerMissing => "marker_absent",
                Unknown::MarkerInvalid | Unknown::ConflictingMarkers => "history_invalid",
            })),
        }
    }
}

struct Run<'a> {
    id: String,
    instance: String,
    status: String,
    last_sequence: i64,
    evidence: Evidence<'a>,
}

#[derive(Default, Serialize)]
struct Counts { total: u64, legacy: u64, current: u64, unknown: u64 }

fn finish(run: Run<'_>, filter: &Filter, counts: &mut Counts, page: &mut Vec<Value>) {
    let usage = if run.evidence.sequence != run.last_sequence {
        Usage::Unknown(Unknown::HistoryUnavailable)
    } else {
        run.evidence.classify(filter.min_version)
    };
    let (state, version, reason) = usage.fields();
    counts.total += 1;
    match state {
        "legacy" => counts.legacy += 1,
        "current" => counts.current += 1,
        _ => counts.unknown += 1,
    }
    if filter.cursor.as_ref().is_some_and(|cursor| run.id <= cursor.after)
        || filter.state.as_deref().is_some_and(|selected| selected != state)
        || page.len() > filter.limit
    {
        return;
    }
    page.push(json!({"instance_id":run.instance,"run_id":run.id,"status":run.status,
        "state":state,"recorded_version":version,"unknown_reason":reason}));
}

// Fixed SQL templates keep all caller values in binds. Explicit binary/C
// comparisons make namespace, type, and keyset identity independent of collation.
const SQLITE_ROWS: &str = "SELECT r.id,r.workflow_instance_id,r.status,r.details_pruned_at,r.import_source,r.last_history_sequence,i.id AS owner_id,h.sequence,h.event_type,CASE WHEN h.event_type='VersionMarkerRecorded' THEN h.payload ELSE NULL END AS marker,CASE WHEN h.event_type='WorkflowStarted' THEN CASE WHEN json_valid(h.payload) THEN CASE WHEN json_type(h.payload)='object' THEN 1 ELSE 0 END ELSE 0 END ELSE 1 END AS start_format_valid FROM workflow_runs r LEFT JOIN workflow_instances i ON i.id COLLATE BINARY=r.workflow_instance_id COLLATE BINARY AND i.namespace COLLATE BINARY=$1 LEFT JOIN workflow_history_events h ON h.workflow_run_id COLLATE BINARY=r.id COLLATE BINARY WHERE r.namespace COLLATE BINARY=$2 AND r.workflow_type COLLATE BINARY=$3 AND r.status COLLATE BINARY IN ('pending','running','waiting') AND ($4 IS NULL OR r.id COLLATE BINARY<=$5) ORDER BY r.id COLLATE BINARY,h.sequence";
const POSTGRES_ROWS: &str = "SELECT r.id,r.workflow_instance_id,r.status,r.details_pruned_at,r.import_source,r.last_history_sequence,i.id AS owner_id,h.sequence,h.event_type,CASE WHEN h.event_type='VersionMarkerRecorded' THEN h.payload::text ELSE NULL END AS marker,CASE WHEN h.event_type='WorkflowStarted' THEN CASE WHEN jsonb_typeof(h.payload::jsonb)='object' THEN 1 ELSE 0 END ELSE 1 END AS start_format_valid FROM workflow_runs r LEFT JOIN workflow_instances i ON i.id COLLATE \"C\"=r.workflow_instance_id COLLATE \"C\" AND i.namespace COLLATE \"C\"=$1 LEFT JOIN workflow_history_events h ON h.workflow_run_id COLLATE \"C\"=r.id COLLATE \"C\" WHERE r.namespace COLLATE \"C\"=$2 AND r.workflow_type COLLATE \"C\"=$3 AND r.status COLLATE \"C\" IN ('pending','running','waiting') AND ($4 IS NULL OR r.id COLLATE \"C\"<=$5 COLLATE \"C\") ORDER BY r.id COLLATE \"C\",h.sequence";
const MYSQL_ROWS: &str = "SELECT r.id,r.workflow_instance_id,r.status,r.details_pruned_at,r.import_source,r.last_history_sequence,i.id AS owner_id,h.sequence,h.event_type,CASE WHEN h.event_type='VersionMarkerRecorded' THEN CAST(h.payload AS CHAR) ELSE NULL END AS marker,CASE WHEN h.event_type='WorkflowStarted' THEN CASE WHEN JSON_TYPE(h.payload)='OBJECT' THEN 1 ELSE 0 END ELSE 1 END AS start_format_valid FROM workflow_runs r LEFT JOIN workflow_instances i ON BINARY i.id=BINARY r.workflow_instance_id AND BINARY i.namespace=BINARY $1 LEFT JOIN workflow_history_events h ON BINARY h.workflow_run_id=BINARY r.id WHERE BINARY r.namespace=BINARY $2 AND BINARY r.workflow_type=BINARY $3 AND BINARY r.status IN ('pending','running','waiting') AND ($4 IS NULL OR BINARY r.id<=BINARY $5) ORDER BY BINARY r.id,h.sequence";
const SQLITE_UPPER: &str = "SELECT r.id FROM workflow_runs r LEFT JOIN workflow_instances i ON i.id COLLATE BINARY=r.workflow_instance_id COLLATE BINARY AND i.namespace COLLATE BINARY=$1 WHERE r.namespace COLLATE BINARY=$2 AND r.workflow_type COLLATE BINARY=$3 AND r.status COLLATE BINARY IN ('pending','running','waiting') ORDER BY r.id COLLATE BINARY DESC LIMIT 1";
const POSTGRES_UPPER: &str = "SELECT r.id FROM workflow_runs r LEFT JOIN workflow_instances i ON i.id COLLATE \"C\"=r.workflow_instance_id COLLATE \"C\" AND i.namespace COLLATE \"C\"=$1 WHERE r.namespace COLLATE \"C\"=$2 AND r.workflow_type COLLATE \"C\"=$3 AND r.status COLLATE \"C\" IN ('pending','running','waiting') ORDER BY r.id COLLATE \"C\" DESC LIMIT 1";
const MYSQL_UPPER: &str = "SELECT r.id FROM workflow_runs r LEFT JOIN workflow_instances i ON BINARY i.id=BINARY r.workflow_instance_id AND BINARY i.namespace=BINARY $1 WHERE BINARY r.namespace=BINARY $2 AND BINARY r.workflow_type=BINARY $3 AND BINARY r.status IN ('pending','running','waiting') ORDER BY BINARY r.id DESC LIMIT 1";

store_methods! {
    pub(super) async fn version_usage(&self, filter: &Filter, key: &str) -> Result<Value> {
        if filter.cursor.as_ref().is_some_and(|cursor| cursor.namespace != self.namespace.as_ref()) {
            return Err(invalid("cursor", "The cursor does not match the authenticated namespace."));
        }
        let (upper_sql, rows_sql) = match DB::PHP_CONNECTION {
            "mysql" => (MYSQL_UPPER, MYSQL_ROWS),
            "pgsql" => (POSTGRES_UPPER, POSTGRES_ROWS),
            _ => (SQLITE_UPPER, SQLITE_ROWS),
        };
        let mut tx = self.begin_repeatable_read().await?;
        let through = match &filter.cursor {
            Some(cursor) => Some(cursor.through.clone()),
            None => Self::query(upper_sql).bind(self.namespace.as_ref()).bind(self.namespace.as_ref())
                .bind(filter.workflow_type.as_str()).fetch_optional(&mut *tx).await?
                .map(|row| DB::string(&row, "id")).transpose()?,
        };
        let mut counts = Counts::default();
        let mut page = Vec::with_capacity(filter.limit + 1);
        if through.is_some() {
            let mut rows = Self::query(rows_sql).bind(self.namespace.as_ref()).bind(self.namespace.as_ref())
                .bind(filter.workflow_type.as_str()).bind(through.as_deref()).bind(through.as_deref())
                .fetch(&mut *tx);
            let mut current: Option<Run<'_>> = None;
            while let Some(row) = rows.try_next().await? {
                let id = DB::string(&row, "id")?;
                if current.as_ref().is_some_and(|run| run.id != id) {
                    finish(current.take().expect("previous run"), filter, &mut counts, &mut page);
                }
                if current.is_none() {
                    let source = DB::optional_string(&row, "import_source")?;
                    let retained = DB::optional_instant(&row, "details_pruned_at")?.is_none()
                        && DB::optional_string(&row, "owner_id")?.is_some()
                        && matches!(source.as_deref(), None | Some("embedded_v2"));
                    current = Some(Run { id, instance: DB::string(&row,"workflow_instance_id")?,
                        status: DB::string(&row,"status")?, last_sequence: DB::number(&row,"last_history_sequence")?,
                        evidence: Evidence::new(&filter.change_id, retained) });
                }
                if let Some(sequence) = DB::optional_number(&row, "sequence")? {
                    let evidence = &mut current.as_mut().expect("current run").evidence;
                    if DB::number(&row, "start_format_valid")? != 1 {
                        evidence.history_available = false;
                    }
                    evidence.event(sequence, &DB::string(&row,"event_type")?,
                        DB::optional_string(&row,"marker")?.as_deref());
                }
            }
            if let Some(run) = current {
                finish(run, filter, &mut counts, &mut page);
            }
        }
        tx.commit().await?;
        let has_more = page.len() > filter.limit;
        page.truncate(filter.limit);
        let next_cursor = if has_more {
            Some(Cursor {
                version: 1, namespace: self.namespace.to_string(), workflow_type: filter.workflow_type.clone(),
                change_id: filter.change_id.clone(), min_version: filter.min_version, state: filter.state.clone(),
                after: page.last().expect("nonempty page")["run_id"].as_str().expect("run ID").to_owned(),
                through: through.clone().expect("bounded cohort"),
            }.encode(key)?)
        } else { None };
        Ok(json!({"schema_version":1,"namespace":self.namespace.as_ref(),"workflow_type":filter.workflow_type,
            "change_id":filter.change_id,"min_version":filter.min_version,"state":filter.state,
            "consistency":"live","through_run_id":through,"counts":counts,"returned_count":page.len(),
            "runs":page,"limit":filter.limit,"has_more":has_more,"next_cursor":next_cursor}))
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn marker(change_id: &str, version: i32) -> Value {
        json!({"change_id":change_id,"version":version,"min_supported":i32::MIN,"max_supported":i32::MAX,"sequence":1})
    }

    fn evidence<'a>(change_id: &'a str) -> Evidence<'a> {
        let mut evidence = Evidence::new(change_id, true);
        evidence.record("WorkflowStarted", &json!({}));
        evidence
    }

    #[test]
    fn absent_marker_never_proves_current_usage() {
        let mut e = evidence("pricing-v2");
        e.record("VersionMarkerRecorded", &marker("unrelated", 99));
        assert_eq!(e.classify(1), Usage::Unknown(Unknown::MarkerMissing));
    }

    #[test]
    fn versions_are_compared_as_signed_integers_at_the_boundary() {
        for (version, expected) in [(-1, Usage::Legacy(-1)), (0, Usage::Legacy(0)), (1, Usage::Current(1)), (2, Usage::Current(2)), (i32::MAX, Usage::Current(i32::MAX))] {
            let mut e = evidence("pricing-v2");
            e.record("VersionMarkerRecorded", &marker("pricing-v2", version));
            assert_eq!(e.classify(1), expected);
        }
    }

    #[test]
    fn unavailable_or_incomplete_history_overrides_a_retained_current_marker() {
        for available in [false, true] {
            let mut e = Evidence::new("pricing-v2", available);
            if !available {
                e.record("WorkflowStarted", &json!({}));
            }
            e.record("VersionMarkerRecorded", &marker("pricing-v2", 2));
            assert_eq!(e.classify(1), Usage::Unknown(Unknown::HistoryUnavailable));
        }
    }

    #[test]
    fn malformed_and_conflicting_markers_remain_unknown() {
        for payload in [json!({}), json!({"change_id":4,"version":2}), json!({"change_id":"pricing-v2","version":"2"}), json!({"change_id":"pricing-v2","version":2.0}), json!({"change_id":"pricing-v2","version":u64::MAX})] {
            let mut e = evidence("pricing-v2");
            e.record("VersionMarkerRecorded", &payload);
            assert_eq!(e.classify(1), Usage::Unknown(Unknown::MarkerInvalid));
        }
        let mut e = evidence("pricing-v2");
        for (index, version) in [0, 2, 2].into_iter().enumerate() {
            let mut payload = marker("pricing-v2", version);
            payload["sequence"] = json!(index + 1);
            e.record("VersionMarkerRecorded", &payload);
        }
        assert_eq!(e.classify(1), Usage::Unknown(Unknown::ConflictingMarkers));
    }

    #[test]
    fn marker_identity_is_exact_and_duplicate_identical_evidence_is_allowed() {
        let mut e = evidence("λ-Pricing");
        for (index, change_id) in ["Λ-Pricing", "λ-pricing", "λ-Pricing "].into_iter().enumerate() {
            let mut payload = marker(change_id, 0);
            payload["sequence"] = json!(index + 1);
            e.record("VersionMarkerRecorded", &payload);
        }
        for sequence in 4..=5 {
            let mut payload = marker("λ-Pricing", 2);
            payload["sequence"] = json!(sequence);
            e.record("VersionMarkerRecorded", &payload);
        }
        assert_eq!(e.classify(1), Usage::Current(2));
    }

    #[test]
    fn marker_budget_and_history_gaps_do_not_report_a_partial_current_decision() {
        let mut e = Evidence::new("pricing-v2", true);
        e.event(1, "WorkflowStarted", None);
        for sequence in 2..=1001 {
            let mut payload = marker("pricing-v2", 2);
            payload["sequence"] = json!(sequence - 1);
            e.event(sequence, "VersionMarkerRecorded", Some(&payload.to_string()));
        }
        assert_eq!(e.classify(1), Usage::Current(2));
        let payload = marker("pricing-v2", 2).to_string();
        e.event(1002, "VersionMarkerRecorded", Some(&payload));
        assert_eq!(e.classify(1), Usage::Unknown(Unknown::HistoryUnavailable));
        let mut e = Evidence::new("pricing-v2", true);
        e.event(1, "WorkflowStarted", None);
        e.event(3, "VersionMarkerRecorded", Some(&payload));
        assert_eq!(e.classify(1), Usage::Unknown(Unknown::HistoryUnavailable));
    }

    #[test]
    fn recorded_range_and_positive_logical_sequence_are_required() {
        for replacement in [json!({"version":2147483648_i64}),json!({"min_supported":3}),json!({"max_supported":0}),json!({"sequence":0}),json!({"sequence":"1"})] {
            let mut payload = marker("pricing-v2", 2);
            for (key, value) in replacement.as_object().unwrap() { payload[key] = value.clone(); }
            let mut e = evidence("pricing-v2");
            e.record("VersionMarkerRecorded", &payload);
            assert_eq!(e.classify(1), Usage::Unknown(Unknown::MarkerInvalid));
        }
    }

    #[test]
    fn repeated_versions_can_expand_the_range_but_cannot_reuse_a_logical_sequence() {
        let mut e = evidence("pricing-v2");
        for (sequence, minimum, maximum) in [(1,0,1), (2,-1,2)] {
            e.record("VersionMarkerRecorded", &json!({"change_id":"pricing-v2","version":1,
                "min_supported":minimum,"max_supported":maximum,"sequence":sequence}));
        }
        assert_eq!(e.classify(1), Usage::Current(1));
        e.record("VersionMarkerRecorded", &json!({"change_id":"pricing-v2","version":1,
            "min_supported":-1,"max_supported":2,"sequence":2}));
        assert_eq!(e.classify(1), Usage::Unknown(Unknown::MarkerInvalid));
    }

    #[test]
    fn cursor_authentication_rejects_unsigned_or_modified_bounds() {
        let cursor = Cursor { version:1, namespace:"default".into(), workflow_type:"echo".into(),
            change_id:"pricing-v2".into(), min_version:1, state:None, after:"A".into(), through:"Z".into() };
        let token = cursor.encode("test-secret").unwrap();
        assert!(Cursor::decode(&token, "test-secret").is_ok());
        assert!(Cursor::decode(&token, "different-key").is_err());
        let body = serde_json::to_vec(&cursor).unwrap();
        assert!(Cursor::decode(&URL_SAFE_NO_PAD.encode(&body), "test-secret").is_err());
        let (_, tag) = token.split_once('.').unwrap();
        for (field, value) in [("after","B"),("through","ZZ")] {
            let mut modified = serde_json::to_value(&cursor).unwrap();
            modified[field] = json!(value);
            let modified = format!("{}.{tag}",URL_SAFE_NO_PAD.encode(serde_json::to_vec(&modified).unwrap()));
            assert!(Cursor::decode(&modified,"test-secret").is_err());
        }
    }

    #[test]
    fn native_start_admission_prefix_and_child_start_prefix_are_supported() {
        let payload = marker("pricing-v2", 1).to_string();
        for prefix in [vec!["StartAccepted","WorkflowStarted"], vec!["WorkflowStarted"]] {
            let mut e = Evidence::new("pricing-v2", true);
            for (index, event_type) in prefix.iter().enumerate() {
                e.event(index as i64 + 1, event_type, None);
            }
            e.event(prefix.len() as i64 + 1, "VersionMarkerRecorded", Some(&payload));
            assert_eq!(e.classify(1), Usage::Current(1));
        }
        let mut e = Evidence::new("pricing-v2", true);
        e.event(1,"StartAccepted",None);
        e.event(2,"VersionMarkerRecorded",Some(&payload));
        assert_eq!(e.classify(1), Usage::Unknown(Unknown::HistoryUnavailable));
    }

    #[test]
    fn cursor_round_trips_maximum_query_values_even_when_json_escapes_them() {
        let cursor = Cursor { version:1, namespace:"default".into(), workflow_type:"\0".repeat(255),
            change_id:"\0".repeat(1024), min_version:1, state:None, after:"A".into(), through:"Z".into() };
        let token = cursor.encode("test-secret").unwrap();
        assert!(token.len() > 8192);
        assert!(Cursor::decode(&token, "test-secret").is_ok());
    }

    #[test]
    fn malformed_start_prefixes_never_establish_a_current_decision() {
        for prefix in [
            vec!["StartAccepted", "StartAccepted", "WorkflowStarted"],
            vec!["StartAccepted", "OtherEvent", "WorkflowStarted"],
            vec!["WorkflowStarted", "StartAccepted"],
            vec!["WorkflowStarted", "WorkflowStarted"],
            vec!["OtherEvent", "WorkflowStarted"],
        ] {
            let mut e = Evidence::new("pricing-v2", true);
            for (index, event_type) in prefix.iter().enumerate() {
                e.event(index as i64 + 1, event_type, None);
            }
            e.event(prefix.len() as i64 + 1, "VersionMarkerRecorded", Some(&marker("pricing-v2",1).to_string()));
            assert_eq!(e.classify(1), Usage::Unknown(Unknown::HistoryUnavailable), "{prefix:?}");
        }
    }

    #[test]
    fn parity_unsupported_event_after_a_current_marker_remains_unavailable() {
        let mut e = Evidence::new("pricing-v2", true);
        e.event(1,"WorkflowStarted",None);
        e.event(2,"VersionMarkerRecorded",Some(&marker("pricing-v2",1).to_string()));
        e.event(3,"FutureHistoryEvent",None);
        assert_eq!(e.classify(1), Usage::Unknown(Unknown::HistoryUnavailable));
    }

    #[test]
    fn parity_unknown_marker_fields_require_a_reader_upgrade() {
        for change_id in ["pricing-v2","other-change"] {
            let mut e = evidence("pricing-v2");
            e.record("VersionMarkerRecorded",&marker("pricing-v2",1));
            let mut future = marker(change_id,1);
            future["sequence"] = json!(2);
            future["future_format"] = json!(2);
            e.record("VersionMarkerRecorded",&future);
            assert_eq!(e.classify(1),Usage::Unknown(Unknown::HistoryUnavailable));
        }
    }

    #[test]
    fn parity_malformed_other_change_decisions_cannot_be_ignored() {
        let mut e = evidence("pricing-v2");
        e.record("VersionMarkerRecorded",&marker("pricing-v2",1));
        let mut invalid = marker("other-change",1);
        invalid["sequence"] = json!(2);
        invalid["min_supported"] = json!(2);
        e.record("VersionMarkerRecorded",&invalid);
        assert_eq!(e.classify(1),Usage::Unknown(Unknown::MarkerInvalid));
    }

    #[test]
    fn reuse_metadata_must_be_a_complete_valid_pair_for_every_change() {
        for change_id in ["pricing-v2", "other-change"] {
            for fields in [
                json!({"reused_from_run_id":"source"}),
                json!({"reused_recorded_at":"2026-01-02T03:04:05Z"}),
                json!({"reused_from_run_id":[],"reused_recorded_at":"2026-01-02T03:04:05Z"}),
                json!({"reused_from_run_id":"","reused_recorded_at":"2026-01-02T03:04:05Z"}),
                json!({"reused_from_run_id":" source ","reused_recorded_at":"2026-01-02T03:04:05Z"}),
                json!({"reused_from_run_id":"source","reused_recorded_at":null}),
                json!({"reused_from_run_id":"source","reused_recorded_at":""}),
                json!({"reused_from_run_id":"source","reused_recorded_at":"not-a-clock"}),
                json!({"reused_from_run_id":"source","reused_recorded_at":"2026-02-30T03:04:05Z"}),
                json!({"reused_from_run_id":"source","reused_recorded_at":"2026-01-02T03:04:05"}),
                json!({"reused_from_run_id":"source","reused_recorded_at":"2026-01-02T03:04:05.1234567890Z"}),
            ] {
                let mut e = evidence("pricing-v2");
                e.record("VersionMarkerRecorded",&marker("pricing-v2",1));
                let mut payload = marker(change_id,1);
                payload["sequence"] = json!(2);
                payload.as_object_mut().unwrap().extend(fields.as_object().unwrap().clone());
                e.record("VersionMarkerRecorded",&payload);
                assert_eq!(e.classify(1),Usage::Unknown(Unknown::MarkerInvalid),"{payload}");
            }
        }
    }

    #[test]
    fn reuse_metadata_accepts_native_precision_and_explicit_offsets() {
        for clock in ["2026-01-02T03:04:05Z","2026-01-02T03:04:05+00:00",
            "2026-01-02T03:04:05.123Z","2026-01-02T03:04:05.123456+00:00",
            "2026-01-02T03:04:05.123456789+02:30"] {
            let mut payload = marker("pricing-v2",1);
            payload["reused_from_run_id"] = json!("source-run");
            payload["reused_recorded_at"] = json!(clock);
            let mut e = evidence("pricing-v2");
            e.record("VersionMarkerRecorded",&payload);
            assert_eq!(e.classify(1),Usage::Current(1),"{clock}");
        }
    }
}
