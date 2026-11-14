//! Read-only version-marker usage. Missing evidence cannot establish that a
//! legacy branch is unused.
use super::{Result, RuntimeError, backend::Backend};
use axum::http::StatusCode;
use base64::{Engine, engine::general_purpose::URL_SAFE_NO_PAD};
use futures_util::TryStreamExt;
use serde::{Deserialize, Serialize};
use serde_json::{Value, json};

const MARKER_BUDGET: usize = 1000;
pub(super) const CAPABILITY: &str = "workflow_version_usage";

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

pub(super) fn invalid(field: &str, message: &str) -> RuntimeError {
    RuntimeError::Protocol {
        status: StatusCode::UNPROCESSABLE_ENTITY,
        response: super::control_plane::Operation::VersionUsage.response(json!({"reason":"validation_failed", "message":message,
            "errors":{(field):[message]}, "validation_errors":{(field):[message]}})),
    }
}

impl Query {
    pub(super) fn validate(self) -> Result<Filter> {
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
            let cursor = (token.len() <= 8192).then(|| URL_SAFE_NO_PAD.decode(token).ok()).flatten()
                .and_then(|bytes| serde_json::from_slice::<Cursor>(&bytes).ok())
                .ok_or_else(|| invalid("cursor", "The version-usage cursor is invalid."))?;
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
    decision: Option<(i32, i32, i32)>,
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
            decision: None,
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
                let Some(change_id) = payload.get("change_id").and_then(Value::as_str) else {
                    // Its identity is unavailable; it might be the requested marker.
                    self.invalid = true;
                    return;
                };
                if change_id != self.change_id {
                    return;
                }
                let integer = |key| payload.get(key).and_then(Value::as_i64).and_then(|value| i32::try_from(value).ok());
                let (Some(version), Some(minimum), Some(maximum)) = (integer("version"), integer("min_supported"), integer("max_supported")) else {
                    self.invalid = true;
                    return;
                };
                if minimum > version || version > maximum || !payload.get("sequence").and_then(Value::as_i64).is_some_and(|sequence| sequence > 0) {
                    self.invalid = true;
                    return;
                }
                let decision = (version, minimum, maximum);
                if self.decision.is_some_and(|previous| previous != decision) {
                    self.conflicting = true;
                }
                self.decision = Some(decision);
            }
            _ => {}
        }
    }

    fn event(&mut self, sequence: i64, event_type: &str, payload: Option<&str>) {
        if self.sequence.checked_add(1) != Some(sequence) || (self.sequence == 0 && event_type != "WorkflowStarted") {
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
            match self.decision.map(|decision| decision.0) {
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
const SQLITE_ROWS: &str = "SELECT r.id,r.workflow_instance_id,r.status,r.details_pruned_at,r.import_source,r.last_history_sequence,h.sequence,h.event_type,CASE WHEN h.event_type='VersionMarkerRecorded' THEN h.payload ELSE NULL END AS marker FROM workflow_runs r JOIN workflow_instances i ON i.id=r.workflow_instance_id AND i.namespace=$1 LEFT JOIN workflow_history_events h ON h.workflow_run_id=r.id WHERE r.namespace=$2 AND r.workflow_type=$3 AND r.status IN ('pending','running','waiting') AND ($4 IS NULL OR r.id<=$5) ORDER BY r.id,h.sequence";
const POSTGRES_ROWS: &str = "SELECT r.id,r.workflow_instance_id,r.status,r.details_pruned_at,r.import_source,r.last_history_sequence,h.sequence,h.event_type,CASE WHEN h.event_type='VersionMarkerRecorded' THEN h.payload::text ELSE NULL END AS marker FROM workflow_runs r JOIN workflow_instances i ON i.id=r.workflow_instance_id AND i.namespace=$1 LEFT JOIN workflow_history_events h ON h.workflow_run_id=r.id WHERE r.namespace=$2 AND r.workflow_type=$3 AND r.status IN ('pending','running','waiting') AND ($4 IS NULL OR r.id COLLATE \"C\"<=$5 COLLATE \"C\") ORDER BY r.id COLLATE \"C\",h.sequence";
const MYSQL_ROWS: &str = "SELECT r.id,r.workflow_instance_id,r.status,r.details_pruned_at,r.import_source,r.last_history_sequence,h.sequence,h.event_type,CASE WHEN h.event_type='VersionMarkerRecorded' THEN CAST(h.payload AS CHAR) ELSE NULL END AS marker FROM workflow_runs r JOIN workflow_instances i ON BINARY i.id=BINARY r.workflow_instance_id AND BINARY i.namespace=BINARY $1 LEFT JOIN workflow_history_events h ON BINARY h.workflow_run_id=BINARY r.id WHERE BINARY r.namespace=BINARY $2 AND BINARY r.workflow_type=BINARY $3 AND BINARY r.status IN ('pending','running','waiting') AND ($4 IS NULL OR BINARY r.id<=BINARY $5) ORDER BY BINARY r.id,h.sequence";
const SQLITE_UPPER: &str = "SELECT r.id FROM workflow_runs r JOIN workflow_instances i ON i.id=r.workflow_instance_id AND i.namespace=$1 WHERE r.namespace=$2 AND r.workflow_type=$3 AND r.status IN ('pending','running','waiting') ORDER BY r.id DESC LIMIT 1";
const POSTGRES_UPPER: &str = "SELECT r.id FROM workflow_runs r JOIN workflow_instances i ON i.id=r.workflow_instance_id AND i.namespace=$1 WHERE r.namespace=$2 AND r.workflow_type=$3 AND r.status IN ('pending','running','waiting') ORDER BY r.id COLLATE \"C\" DESC LIMIT 1";
const MYSQL_UPPER: &str = "SELECT r.id FROM workflow_runs r JOIN workflow_instances i ON BINARY i.id=BINARY r.workflow_instance_id AND BINARY i.namespace=BINARY $1 WHERE BINARY r.namespace=BINARY $2 AND BINARY r.workflow_type=BINARY $3 AND BINARY r.status IN ('pending','running','waiting') ORDER BY BINARY r.id DESC LIMIT 1";

store_methods! {
    pub(super) async fn version_usage(&self, filter: &Filter) -> Result<Value> {
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
                        && matches!(source.as_deref(), None | Some("embedded_v2"));
                    current = Some(Run { id, instance: DB::string(&row,"workflow_instance_id")?,
                        status: DB::string(&row,"status")?, last_sequence: DB::number(&row,"last_history_sequence")?,
                        evidence: Evidence::new(&filter.change_id, retained) });
                }
                if let Some(sequence) = DB::optional_number(&row, "sequence")? {
                    current.as_mut().expect("current run").evidence.event(sequence,
                        &DB::string(&row,"event_type")?, DB::optional_string(&row,"marker")?.as_deref());
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
            Some(URL_SAFE_NO_PAD.encode(serde_json::to_vec(&Cursor {
                version: 1, namespace: self.namespace.to_string(), workflow_type: filter.workflow_type.clone(),
                change_id: filter.change_id.clone(), min_version: filter.min_version, state: filter.state.clone(),
                after: page.last().expect("nonempty page")["run_id"].as_str().expect("run ID").to_owned(),
                through: through.clone().expect("bounded cohort"),
            })?))
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
        e.record("VersionMarkerRecorded", &json!({"change_id":"unrelated", "version":99}));
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
        for version in [0, 2, 2] {
            e.record("VersionMarkerRecorded", &marker("pricing-v2", version));
        }
        assert_eq!(e.classify(1), Usage::Unknown(Unknown::ConflictingMarkers));
    }

    #[test]
    fn marker_identity_is_exact_and_duplicate_identical_evidence_is_allowed() {
        let mut e = evidence("λ-Pricing");
        for change_id in ["Λ-Pricing", "λ-pricing", "λ-Pricing "] {
            e.record("VersionMarkerRecorded", &json!({"change_id":change_id,"version":0}));
        }
        for _ in 0..2 {
            e.record("VersionMarkerRecorded", &marker("λ-Pricing", 2));
        }
        assert_eq!(e.classify(1), Usage::Current(2));
    }

    #[test]
    fn marker_budget_and_history_gaps_do_not_report_a_partial_current_decision() {
        let mut e = Evidence::new("pricing-v2", true);
        e.event(1, "WorkflowStarted", None);
        let payload = marker("pricing-v2", 2).to_string();
        for sequence in 2..=1001 {
            e.event(sequence, "VersionMarkerRecorded", Some(&payload));
        }
        assert_eq!(e.classify(1), Usage::Current(2));
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
}
