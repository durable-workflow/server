//! Whether every live PHP schedule is one native can run and describe as PHP
//! does. Native schedules are still a bounded subset (one UTC interval, the
//! skip policy, no jitter, at most one run, the default namespace), so a
//! source schedule outside it would stop firing, or lose fields, after
//! takeover. The report counts them by reason and never contains schedule
//! identities or values.
use crate::runtime::{Result, RuntimeError, backend::Backend, schedules, store::now};
use serde_json::{Value, json};
use sqlx::{Executor, IntoArguments};
use std::collections::BTreeMap;

const SCHEDULES: &str = "SELECT schedule_id,namespace,status,spec,action,overlap_policy,jitter_seconds,max_runs,note,memo,search_attributes,visibility_labels,buffered_actions,connection,queue FROM workflow_schedules WHERE status<>'deleted' ORDER BY id";

pub(super) async fn inspect<DB: Backend>(connection: &mut DB::Connection) -> Result<Value>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
{
    let rows = sqlx::query(sqlx::AssertSqlSafe(DB::statement(SCHEDULES)))
        .fetch_all(&mut *connection)
        .await?;
    let mut reasons = BTreeMap::<&'static str, i64>::new();
    for row in &rows {
        let schedule = Schedule {
            schedule_id: DB::string(row, "schedule_id")?,
            namespace: DB::optional_string(row, "namespace")?,
            status: DB::string(row, "status")?,
            spec: DB::optional_document_row(row, "spec")?.unwrap_or(Value::Null),
            action: DB::optional_document_row(row, "action")?.unwrap_or(Value::Null),
            overlap_policy: DB::string(row, "overlap_policy")?,
            jitter_seconds: DB::number(row, "jitter_seconds")?,
            max_runs: DB::optional_number(row, "max_runs")?,
            note: DB::optional_string(row, "note")?,
            metadata: [
                DB::optional_document_row(row, "memo")?,
                DB::optional_document_row(row, "search_attributes")?,
                DB::optional_document_row(row, "visibility_labels")?,
            ],
            buffered: DB::optional_document_row(row, "buffered_actions")?,
            routing: [
                DB::optional_string(row, "connection")?,
                DB::optional_string(row, "queue")?,
            ],
        };
        if let Some(reason) = schedule.unsupported() {
            *reasons.entry(reason).or_default() += 1;
        }
    }
    Ok(json!({
        "schedules": rows.len(),
        "unsupported": reasons.values().sum::<i64>(),
        "reasons": reasons,
    }))
}

struct Schedule {
    schedule_id: String,
    namespace: Option<String>,
    status: String,
    spec: Value,
    action: Value,
    overlap_policy: String,
    jitter_seconds: i64,
    max_runs: Option<i64>,
    note: Option<String>,
    metadata: [Option<Value>; 3],
    buffered: Option<Value>,
    routing: [Option<String>; 2],
}

/// A JSON column PHP leaves null when empty; an empty array or object is
/// treated the same.
fn present(value: &Option<Value>) -> bool {
    match value {
        None | Some(Value::Null) => false,
        Some(Value::Array(items)) => !items.is_empty(),
        Some(Value::Object(fields)) => !fields.is_empty(),
        Some(_) => true,
    }
}

impl Schedule {
    /// The first reason native cannot take this schedule over, if any.
    fn unsupported(&self) -> Option<&'static str> {
        if self.namespace.as_deref() != Some("default") {
            return Some("schedule_namespace_unsupported");
        }
        if !matches!(self.status.as_str(), "active" | "paused") {
            return Some("schedule_status_unsupported");
        }
        if self.metadata.iter().any(present) {
            return Some("schedule_metadata_unsupported");
        }
        if self.note.is_some() {
            return Some("schedule_note_unsupported");
        }
        if present(&self.buffered) {
            return Some("schedule_buffered_actions_unsupported");
        }
        if self.routing.iter().any(Option::is_some) {
            return Some("schedule_queue_routing_unsupported");
        }
        // Exactly the definition native's own schedule creation accepts.
        let body = json!({
            "schedule_id": self.schedule_id, "spec": self.spec, "action": self.action,
            "overlap_policy": self.overlap_policy, "jitter_seconds": self.jitter_seconds,
            "max_runs": self.max_runs,
        });
        match schedules::definition(&body, now()) {
            Ok(_) => None,
            Err(RuntimeError::Refused { reason, .. }) => Some(reason),
            Err(_) => Some("schedule_definition_unsupported"),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn runnable() -> Schedule {
        Schedule {
            schedule_id: "daily".into(),
            namespace: Some("default".into()),
            status: "active".into(),
            spec: json!({"intervals":[{"every":"P1D"}],"timezone":"UTC"}),
            action: json!({"workflow_type":"echo","task_queue":"default",
                "input":{"codec":"avro","blob":"AA=="},
                "execution_timeout_seconds":3600,"run_timeout_seconds":600}),
            overlap_policy: "skip".into(),
            jitter_seconds: 0,
            max_runs: None,
            note: None,
            metadata: [None, Some(Value::Null), Some(json!([]))],
            buffered: None,
            routing: [None, None],
        }
    }

    #[test]
    fn only_schedules_native_runs_and_describes_unchanged_are_qualified() {
        assert_eq!(runnable().unsupported(), None);
        let cases: [(fn(&mut Schedule), &str); 10] = [
            (
                |s| s.namespace = Some("billing".into()),
                "schedule_namespace_unsupported",
            ),
            (|s| s.namespace = None, "schedule_namespace_unsupported"),
            (
                |s| s.spec = json!({"cron_expressions":["0 9 * * *"],"timezone":"UTC"}),
                "unsupported_request_field",
            ),
            (
                |s| s.spec = json!({"intervals":[{"every":"PT1H"}],"timezone":"UTC"}),
                "unsupported_schedule_interval",
            ),
            (|s| s.overlap_policy = "buffer_one".into(), "unsupported_schedule_policy"),
            (|s| s.jitter_seconds = 30, "unsupported_schedule_policy"),
            (|s| s.max_runs = Some(5), "unsupported_schedule_quota"),
            (
                |s| s.metadata[0] = Some(json!({"team":"ops"})),
                "schedule_metadata_unsupported",
            ),
            (|s| s.note = Some("paused for audit".into()), "schedule_note_unsupported"),
            (
                |s| {
                    s.action["execution_timeout_seconds"] = Value::Null;
                },
                "unsupported_schedule_action_timeout",
            ),
        ];
        for (change, reason) in cases {
            let mut schedule = runnable();
            change(&mut schedule);
            assert_eq!(schedule.unsupported(), Some(reason));
        }
        let mut buffered = runnable();
        buffered.buffered = Some(json!([{"buffered_at":"2026-10-21T00:00:00+00:00"}]));
        assert_eq!(
            buffered.unsupported(),
            Some("schedule_buffered_actions_unsupported")
        );
        let mut routed = runnable();
        routed.routing[1] = Some("schedules".into());
        assert_eq!(
            routed.unsupported(),
            Some("schedule_queue_routing_unsupported")
        );
    }
}
