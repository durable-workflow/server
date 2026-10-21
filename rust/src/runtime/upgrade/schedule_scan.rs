//! Whether every live PHP schedule is one native can run and describe as PHP
//! does. Native runs PHP's cron, intervals, zones, overlap policies,
//! buffers, jitter, quotas, memo, notes and namespaces. It still refuses
//! search attributes, visibility labels, Laravel queue routing, an unknown
//! status, and a definition its own creation would refuse, including input
//! that is not a payload envelope and a specification it cannot evaluate. A
//! source schedule like that would stop firing, or lose fields, after
//! takeover. The report counts them by reason and never contains schedule
//! identities or values.
use crate::runtime::{Result, RuntimeError, backend::Backend, schedules, store::now};
use serde_json::{Value, json};
use sqlx::{Executor, IntoArguments};
use std::collections::BTreeMap;

const SCHEDULES: &str = "SELECT schedule_id,status,spec,action,overlap_policy,jitter_seconds,max_runs,note,memo,search_attributes,visibility_labels,connection,queue FROM workflow_schedules WHERE status<>'deleted' ORDER BY id";

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
    status: String,
    spec: Value,
    action: Value,
    overlap_policy: String,
    jitter_seconds: i64,
    max_runs: Option<i64>,
    note: Option<String>,
    metadata: [Option<Value>; 3],
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
        if !matches!(self.status.as_str(), "active" | "paused") {
            return Some("schedule_status_unsupported");
        }
        // Memo is carried; search attributes and visibility labels are not.
        if self.metadata[1..].iter().any(present) {
            return Some("schedule_metadata_unsupported");
        }
        if self.routing.iter().any(Option::is_some) {
            return Some("schedule_queue_routing_unsupported");
        }
        // Exactly the definition native's own schedule creation accepts.
        let body = json!({
            "schedule_id": self.schedule_id, "spec": self.spec, "action": self.action,
            "overlap_policy": self.overlap_policy, "jitter_seconds": self.jitter_seconds,
            "max_runs": self.max_runs, "memo": self.metadata[0], "note": self.note,
        });
        if let Err(error) = schedules::definition(&body) {
            return Some(match error {
                RuntimeError::Refused { reason, .. } => reason,
                _ => "schedule_definition_unsupported",
            });
        }
        if schedules::spec_problem(&self.spec).is_some()
            || schedules::next_fire(&self.spec, 0, now()).is_err()
        {
            return Some("schedule_spec_unsupported");
        }
        None
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn runnable() -> Schedule {
        Schedule {
            schedule_id: "daily".into(),
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
            routing: [None, None],
        }
    }

    #[test]
    fn schedules_native_runs_are_qualified_and_the_rest_refused() {
        assert_eq!(runnable().unsupported(), None);
        let runs: [fn(&mut Schedule); 9] = [
            |s| s.spec = json!({"cron_expressions":["0 9 * * 1-5","@yearly"],"timezone":"Europe/Budapest"}),
            |s| s.spec = json!({"intervals":[{"every":"PT1H","offset":"PT5M"}]}),
            |s| s.overlap_policy = "buffer_one".into(),
            |s| s.overlap_policy = "terminate_other".into(),
            |s| s.jitter_seconds = 30,
            |s| s.max_runs = Some(5),
            |s| s.metadata[0] = Some(json!({"team":"ops"})),
            |s| s.note = Some("paused for audit".into()),
            |s| s.status = "paused".into(),
        ];
        for change in runs {
            let mut schedule = runnable();
            change(&mut schedule);
            assert_eq!(schedule.unsupported(), None, "{}", schedule.spec);
        }
        let refused: [(fn(&mut Schedule), &str); 8] = [
            (|s| s.status = "archived".into(), "schedule_status_unsupported"),
            (|s| s.metadata[1] = Some(json!({"Team":"ops"})), "schedule_metadata_unsupported"),
            (|s| s.metadata[2] = Some(json!({"tier":"gold"})), "schedule_metadata_unsupported"),
            (|s| s.routing[1] = Some("schedules".into()), "schedule_queue_routing_unsupported"),
            (|s| s.action["input"] = json!([1, 2]), "unsupported_payload_codec"),
            (|s| s.action = json!({"workflow_class":"App\\Workflows\\Daily"}), "schedule_definition_unsupported"),
            (|s| s.spec = json!({"cron_expressions":["0 3 * *"]}), "schedule_spec_unsupported"),
            (|s| s.spec = json!({"cron_expressions":["0 3 * * *"],"timezone":"Mars/Base"}), "schedule_spec_unsupported"),
        ];
        for (change, reason) in refused {
            let mut schedule = runnable();
            change(&mut schedule);
            assert_eq!(schedule.unsupported(), Some(reason), "{}", schedule.spec);
        }
    }
}
