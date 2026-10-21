//! `HistoryRetentionEnforcer`: a closed run past its namespace's retention
//! loses its details, as published PHP prunes it. The run row stays, marked
//! with `details_pruned_at`.
//!
//! Native never deletes stored external payload objects. A run that holds a
//! reference to one, an open abandoned activity or a message stream is
//! skipped with its reason, so a prune never strands or loses data PHP would
//! have handled.
use super::laravel_validation::{Rule, failure_messages, rule_errors};
use chrono::{DateTime, Duration, Utc};
use serde_json::{Map, Value, json};
use std::{
    collections::HashMap,
    sync::{Mutex, OnceLock},
    time::Instant,
};

/// The most runs one status read or pass considers.
pub(super) const MAX_LIMIT: i64 = 100;
/// PHP's inline pass prunes one run per namespace at most once a minute.
const INLINE_THROTTLE_SECONDS: u64 = 60;

/// The deletes of PHP's `WorkflowRunRetentionCleanup::pruneRun`, in its
/// order: report key, statement, and how many times it binds the run id.
pub(super) const PRUNED: &[(&str, &str, usize)] = &[
    ("activity_attempts_deleted", "DELETE FROM activity_attempts WHERE workflow_run_id=$1", 1),
    ("updates_deleted", "DELETE FROM workflow_updates WHERE workflow_run_id=$1 OR requested_workflow_run_id=$2 OR resolved_workflow_run_id=$3", 3),
    ("signals_deleted", "DELETE FROM workflow_signal_records WHERE workflow_run_id=$1 OR requested_workflow_run_id=$2 OR resolved_workflow_run_id=$3", 3),
    ("messages_deleted", "DELETE FROM workflow_messages WHERE workflow_run_id=$1", 1),
    ("child_calls_deleted", "DELETE FROM workflow_child_calls WHERE parent_workflow_run_id=$1", 1),
    ("links_deleted", "DELETE FROM workflow_links WHERE parent_workflow_run_id=$1 OR child_workflow_run_id=$2", 2),
    ("activity_executions_deleted", "DELETE FROM activity_executions WHERE workflow_run_id=$1", 1),
    ("commands_deleted", "DELETE FROM workflow_commands WHERE workflow_run_id=$1 OR requested_workflow_run_id=$2 OR resolved_workflow_run_id=$3", 3),
    ("failures_deleted", "DELETE FROM workflow_failures WHERE workflow_run_id=$1", 1),
    ("child_projection_repairs_deleted", "DELETE FROM workflow_child_projection_repairs WHERE workflow_run_id=$1", 1),
    ("run_waits_deleted", "DELETE FROM workflow_run_waits WHERE workflow_run_id=$1", 1),
    ("timeline_entries_deleted", "DELETE FROM workflow_run_timeline_entries WHERE workflow_run_id=$1", 1),
    ("run_timer_entries_deleted", "DELETE FROM workflow_run_timer_entries WHERE workflow_run_id=$1", 1),
    ("run_lineage_entries_deleted", "DELETE FROM workflow_run_lineage_entries WHERE workflow_run_id=$1", 1),
    ("timers_deleted", "DELETE FROM workflow_run_timers WHERE workflow_run_id=$1", 1),
    ("memos_deleted", "DELETE FROM workflow_memos WHERE workflow_run_id=$1", 1),
    ("search_attributes_deleted", "DELETE FROM workflow_search_attributes WHERE workflow_run_id=$1", 1),
    ("history_events_deleted", "DELETE FROM workflow_history_events WHERE workflow_run_id=$1", 1),
    ("tasks_deleted", "DELETE FROM workflow_tasks WHERE workflow_run_id=$1", 1),
    ("run_summary_deleted", "DELETE FROM workflow_run_summaries WHERE id=$1", 1),
];

/// PHP's terminal run statuses.
pub(super) fn terminal(status: &str) -> bool {
    matches!(status, "completed" | "failed" | "cancelled" | "terminated")
}

/// A text column holding a stored external payload reference, PHP's or
/// native's, or an external storage envelope.
macro_rules! reference {
    ($column:literal) => {
        concat!(
            "(", $column, " LIKE '%dw-external-payload:v1:%' OR ", $column, " LIKE '%dw-native-external-payload-v1:%' OR ",
            $column, " LIKE '%external_storage%' OR ", $column, " LIKE '%external_payload%')"
        )
    };
}

/// The run's payload columns that can hold an external reference. History
/// events repeat these payloads, so they are not scanned again.
const REFERENCES_SQL: &str = concat!(
    "SELECT (SELECT COUNT(*) FROM workflow_runs WHERE id=$1 AND (", reference!("arguments"), " OR ", reference!("output"), "))",
    "+(SELECT COUNT(*) FROM workflow_commands WHERE workflow_run_id=$2 AND ", reference!("payload"), ")",
    "+(SELECT COUNT(*) FROM workflow_signal_records WHERE workflow_run_id=$3 AND ", reference!("arguments"), ")",
    "+(SELECT COUNT(*) FROM workflow_updates WHERE workflow_run_id=$4 AND (", reference!("arguments"), " OR ", reference!("result"), "))",
    "+(SELECT COUNT(*) FROM activity_executions WHERE workflow_run_id=$5 AND (", reference!("arguments"), " OR ", reference!("result"), "))",
);

/// `MessageStreamRetentionCleanup::apply`'s counts in the prune report.
pub(super) const STREAM_COUNTS: [&str; 3] =
    ["message_streams_deleted", "message_stream_items_deleted", "message_stream_items_compacted"];

/// Counts that hold a run's details, the run-id binds of each, and the
/// skip reason. Native closes a run only after cancelling its open
/// activities, so an open one is an activity PHP abandoned.
pub(super) const HOLDS: &[(&str, usize, &str)] = &[
    ("SELECT COUNT(*) FROM activity_executions WHERE workflow_run_id=$1 AND status IN ('pending','running')", 1, "detached_activity_still_open"),
    ("SELECT (SELECT COUNT(*) FROM workflow_durable_streams WHERE workflow_run_id=$1)+(SELECT COUNT(*) FROM workflow_messages WHERE workflow_run_id=$2)", 2, "message_stream_cleanup_unavailable"),
    (REFERENCES_SQL, 5, "external_payload_cleanup_unavailable"),
];

/// `server.history.retention_days`.
fn default_days() -> i64 {
    ["DW_HISTORY_RETENTION_DAYS", "WORKFLOW_HISTORY_RETENTION_DAYS"]
        .iter()
        .find_map(|name| std::env::var(name).ok())
        .and_then(|days| days.trim().parse().ok())
        .unwrap_or(30)
}

/// `HistoryRetentionEnforcer::retentionPolicy`.
pub(super) struct Policy {
    pub(super) forever: bool,
    pub(super) days: Option<i64>,
    pub(super) cutoff: Option<DateTime<Utc>>,
}

impl Policy {
    pub(super) fn new(mode: Option<&str>, days: Option<i64>, now: DateTime<Utc>) -> Self {
        let forever = mode == Some("forever");
        let days = (!forever).then(|| days.unwrap_or_else(default_days));
        Self { forever, days, cutoff: days.map(|days| now - Duration::days(days)) }
    }

    /// The policy fields of PHP's status and pass responses.
    pub(super) fn fields(&self, namespace: &str) -> Map<String, Value> {
        let mut fields = Map::new();
        fields.insert("namespace".into(), json!(namespace));
        fields.insert("retention_mode".into(), json!(if self.forever { "forever" } else { "bounded" }));
        fields.insert("retention_days".into(), json!(self.days));
        fields.insert(
            "cutoff".into(),
            json!(self.cutoff.map(|cutoff| cutoff.format("%Y-%m-%dT%H:%M:%S+00:00").to_string())),
        );
        fields
    }
}

/// `min(100, limit ?? 100)` after Laravel's `nullable|integer|min:1`.
pub(super) fn limit(value: Option<i64>) -> i64 {
    value.unwrap_or(MAX_LIMIT).min(MAX_LIMIT)
}

fn defaults(_: &str, _: Rule) -> Option<&'static str> {
    None
}

fn checked(errors: Vec<(String, Vec<String>)>) -> super::Result<()> {
    match failure_messages(errors) {
        Some(error) => Err(error),
        None => Ok(()),
    }
}

/// The `limit` rule of the status endpoints: `nullable|integer|min:1`.
pub(super) fn status_limit(value: Option<&str>) -> super::Result<i64> {
    let value = super::laravel_validation::normalized(value.map(|text| json!(text)).as_ref()).unwrap_or(Value::Null);
    let body = json!({"limit": value});
    checked(rule_errors(&body, &[("limit", &[Rule::Nullable, Rule::Integer, Rule::Min(1)])], defaults))?;
    Ok(limit(super::laravel_validation::integer(&body["limit"])))
}

/// The rules of PHP's retention and activity timeout passes: the limit and
/// up to 100 trimmed ids in `field`.
pub(super) fn pass_request(body: &Value, field: &'static str) -> super::Result<(i64, Vec<String>)> {
    let body = &super::start_request::normalized(body.clone());
    let rules: &[(&str, &[Rule])] = &[
        ("limit", &[Rule::Nullable, Rule::Integer, Rule::Min(1)]),
        (field, &[Rule::Nullable, Rule::Array, Rule::Max(MAX_LIMIT)]),
    ];
    let mut errors = rule_errors(body, rules, defaults);
    // Laravel names each item by its key, which is the index of a list.
    let items: Vec<(String, Value)> = match &body[field] {
        Value::Array(items) => items.iter().enumerate().map(|(index, item)| (index.to_string(), item.clone())).collect(),
        Value::Object(fields) => fields.iter().map(|(key, item)| (key.clone(), item.clone())).collect(),
        _ => Vec::new(),
    };
    let item_rules: &[Rule] = &[Rule::String, Rule::Min(1), Rule::Max(128)];
    for (key, item) in &items {
        let name = format!("{field}.{key}");
        let mut value = Map::new();
        value.insert(name.clone(), item.clone());
        let nested = rule_errors(&Value::Object(value), &[(name.as_str(), item_rules)], defaults);
        errors.extend(nested);
    }
    checked(errors)?;
    let ids = items.iter().filter_map(|(_, id)| id.as_str()).map(|id| id.trim().to_owned()).collect();
    Ok((limit(super::laravel_validation::integer(&body["limit"])), ids))
}

/// What a prune attempt did to one run.
pub(super) enum Outcome {
    Pruned(Map<String, Value>),
    Skipped(&'static str),
    Error(String),
}

/// `runPass`'s report over each run's outcome.
pub(super) fn report(outcomes: Vec<(String, Outcome)>) -> Map<String, Value> {
    let (mut pruned, mut skipped, mut failed) = (0, 0, 0);
    let processed = outcomes.len();
    let results: Vec<Value> = outcomes
        .into_iter()
        .map(|(run_id, outcome)| match outcome {
            Outcome::Pruned(deleted) => {
                pruned += 1;
                json!({"run_id": run_id, "outcome": "pruned", "history_events_deleted": deleted["history_events_deleted"],
                    "tasks_deleted": deleted["tasks_deleted"], "external_payloads_deleted": 0, "deleted": deleted})
            }
            Outcome::Skipped(reason) => {
                skipped += 1;
                json!({"run_id": run_id, "outcome": "skipped", "reason": reason})
            }
            Outcome::Error(reason) => {
                failed += 1;
                json!({"run_id": run_id, "outcome": "error", "reason": reason})
            }
        })
        .collect();
    let mut report = Map::new();
    report.insert("processed".into(), json!(processed));
    report.insert("pruned".into(), json!(pruned));
    report.insert("skipped".into(), json!(skipped));
    report.insert("failed".into(), json!(failed));
    report.insert("results".into(), json!(results));
    report
}

/// `runInlinePass`'s throttle: at most one inline pass per namespace a
/// minute in this process.
pub(super) fn inline_pass_due(namespace: &str) -> bool {
    static LAST: OnceLock<Mutex<HashMap<String, Instant>>> = OnceLock::new();
    let mut last = LAST.get_or_init(Default::default).lock().unwrap_or_else(std::sync::PoisonError::into_inner);
    let now = Instant::now();
    match last.get(namespace) {
        Some(at) if now.duration_since(*at).as_secs() < INLINE_THROTTLE_SECONDS => false,
        _ => {
            last.insert(namespace.to_owned(), now);
            true
        }
    }
}

/// `runInlinePass`'s heartbeat report.
pub(super) fn inline_report(throttled: bool, report: Option<&Map<String, Value>>) -> Value {
    let count = |key: &str| report.and_then(|report| report.get(key)).cloned().unwrap_or(json!(0));
    json!({"throttled": throttled, "processed": count("processed"), "pruned": count("pruned"),
        "skipped": count("skipped"), "failed": count("failed")})
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn every_delete_binds_the_run_once_per_reference() {
        for (_, sql, binds) in PRUNED {
            assert_eq!(sql.matches('$').count(), *binds, "{sql}");
        }
        assert_eq!(PRUNED.last().unwrap().0, "run_summary_deleted");
        for (sql, binds, _) in HOLDS {
            assert_eq!((1..=*binds).filter(|index| sql.contains(&format!("${index}"))).count(), *binds, "{sql}");
            assert!(!sql.contains(&format!("${}", binds + 1)), "{sql}");
        }
    }

    #[test]
    fn policies_follow_php() {
        let now = DateTime::parse_from_rfc3339("2026-10-21T12:00:00Z").unwrap().with_timezone(&Utc);
        let bounded = Policy::new(Some("bounded"), Some(7), now);
        assert_eq!(bounded.fields("default")["cutoff"], "2026-10-14T12:00:00+00:00");
        let forever = Policy::new(Some("forever"), Some(7), now);
        assert_eq!((forever.days, forever.cutoff), (None, None));
        assert_eq!(forever.fields("default")["retention_mode"], "forever");
        assert_eq!(limit(Some(500)), 100);
        assert_eq!(limit(None), 100);
    }

    #[test]
    fn reports_count_each_outcome() {
        let mut deleted = Map::new();
        deleted.insert("history_events_deleted".into(), json!(3));
        deleted.insert("tasks_deleted".into(), json!(1));
        let report = report(vec![
            ("a".into(), Outcome::Pruned(deleted)),
            ("b".into(), Outcome::Skipped("run_not_terminal")),
            ("c".into(), Outcome::Error("boom".into())),
        ]);
        assert_eq!((report["processed"].clone(), report["pruned"].clone(), report["skipped"].clone(), report["failed"].clone()),
            (json!(3), json!(1), json!(1), json!(1)));
        assert_eq!(report["results"][0]["history_events_deleted"], 3);
        assert_eq!(inline_report(true, None), json!({"throttled":true,"processed":0,"pruned":0,"skipped":0,"failed":0}));
    }
}
