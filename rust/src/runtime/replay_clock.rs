//! The `replay_clock` entry carrier on workflow tasks (Server #1132,
//! durable-workflow/.github#215): the instant a run's replay starts from, so
//! an SDK's replay-safe clock has a value at ordinary workflow entry.
//!
//! An ordinary, continued or child run uses its own canonical start. A
//! redrive successor uses the replay anchor its `WorkflowStarted` recorded,
//! only when the redrive command and link that created it agree. Anything
//! missing, ambiguous or malformed omits the object; a redrive successor's
//! fresh start never stands in for its anchor.
use super::{
    Result,
    backend::Backend,
    store::Store,
};
use chrono::{DateTime, SecondsFormat, Utc};
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

pub(super) const SCHEMA: &str = "durable-workflow.worker-replay-clock/v1";
const RUN_STARTED: &str = "run_started_at";
const REDRIVE_ANCHOR: &str = "redrive_replayed_started_at";
/// A run's start events are among its first few; the lookup reads only
/// these, through the run and sequence key.
const OPENING_EVENTS: i64 = 8;

/// Strict UTC RFC 3339 with six fractional digits and `Z`. A whole-second
/// instant stays whole seconds, padded with zeros.
fn timestamp(instant: DateTime<Utc>) -> String {
    instant.to_rfc3339_opts(SecondsFormat::Micros, true)
}

fn carrier(run_id: &str, started_at: DateTime<Utc>, source: &str) -> Value {
    json!({"schema":SCHEMA,"workflow_run_id":run_id,"started_at":timestamp(started_at),"source":source})
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
    /// The task's `replay_clock`, or `None` when its authority is not proved.
    pub(super) async fn replay_clock(connection: &mut DB::Connection, run: &DB::Row) -> Result<Option<Value>> {
        let run_id = DB::string(run, "id")?;
        let instance_id = DB::string(run, "workflow_instance_id")?;
        let opening = Self::query("SELECT event_type,payload,workflow_command_id FROM workflow_history_events WHERE workflow_run_id=$1 AND sequence<=$2 ORDER BY sequence")
            .bind(run_id.as_str())
            .bind(OPENING_EVENTS)
            .fetch_all(&mut *connection)
            .await?;
        let mut starts = Vec::new();
        for row in &opening {
            if DB::string(row, "event_type")? == "WorkflowStarted" {
                starts.push(row);
            }
        }
        let [started] = starts.as_slice() else {
            return Ok(None);
        };
        let payload = DB::optional_document_row(started, "payload")?.unwrap_or(Value::Null);
        if payload["workflow_run_id"].as_str().is_some_and(|recorded| recorded != run_id) {
            return Ok(None);
        }
        if payload.get("recovery_kind").is_some_and(|kind| !kind.is_null()) {
            if payload["recovery_kind"] != "redrive" {
                return Ok(None);
            }
            return Self::redrive_anchor(connection, &run_id, &instance_id, &payload, DB::optional_string(started, "workflow_command_id")?).await;
        }
        // An unmarked start of a run some redrive created is ambiguous.
        let redriven = Self::scalar("SELECT COUNT(*) FROM workflow_commands WHERE workflow_run_id=$1 AND command_type='redrive'")
            .bind(run_id.as_str())
            .fetch_one(&mut *connection)
            .await?;
        if redriven > 0 {
            return Ok(None);
        }
        Ok(DB::optional_instant(run, "started_at")?.map(|started_at| carrier(&run_id, started_at, RUN_STARTED)))
    }

    /// A redrive successor's anchor, when its command, link and single start
    /// all name this redrive.
    async fn redrive_anchor(connection: &mut DB::Connection, run_id: &str, instance_id: &str, payload: &Value,
        event_command: Option<String>) -> Result<Option<Value>> {
        let text = |field: &str| payload[field].as_str().filter(|value| !value.is_empty());
        let (Some(command_id), Some(source_run), Some(link_id), Some(resume), Some(anchor)) = (
            text("workflow_command_id"),
            text("continued_from_run_id"),
            text("workflow_link_id"),
            payload["resume_step_sequence"].as_i64(),
            text("replayed_started_at"),
        ) else {
            return Ok(None);
        };
        let Ok(anchor) = DateTime::parse_from_rfc3339(anchor) else {
            return Ok(None);
        };
        if event_command.as_deref() != Some(command_id) || source_run == run_id {
            return Ok(None);
        }
        let command = Self::query("SELECT id,workflow_instance_id,workflow_run_id,requested_workflow_run_id,command_type,status,outcome FROM workflow_commands WHERE id=$1")
            .bind(command_id)
            .fetch_optional(&mut *connection)
            .await?;
        let Some(command) = command else {
            return Ok(None);
        };
        // The lookup's collation may fold case; the identities must match exactly.
        let command_matches = DB::string(&command, "id")? == command_id
            && DB::string(&command, "workflow_instance_id")? == instance_id
            && DB::optional_string(&command, "workflow_run_id")?.as_deref() == Some(run_id)
            && DB::optional_string(&command, "requested_workflow_run_id")?.as_deref() == Some(source_run)
            && DB::string(&command, "command_type")? == "redrive"
            && DB::string(&command, "status")? == "accepted"
            && DB::optional_string(&command, "outcome")?.as_deref() == Some("redriven");
        if !command_matches {
            return Ok(None);
        }
        let link = Self::query("SELECT id,link_type,sequence,parent_workflow_instance_id,parent_workflow_run_id,child_workflow_instance_id,child_workflow_run_id FROM workflow_links WHERE id=$1")
            .bind(link_id)
            .fetch_optional(&mut *connection)
            .await?;
        let Some(link) = link else {
            return Ok(None);
        };
        let link_matches = DB::string(&link, "id")? == link_id
            && DB::string(&link, "link_type")? == "redrive"
            && DB::optional_number(&link, "sequence")? == Some(resume)
            && DB::optional_string(&link, "parent_workflow_instance_id")?.as_deref() == Some(instance_id)
            && DB::optional_string(&link, "parent_workflow_run_id")?.as_deref() == Some(source_run)
            && DB::optional_string(&link, "child_workflow_instance_id")?.as_deref() == Some(instance_id)
            && DB::optional_string(&link, "child_workflow_run_id")?.as_deref() == Some(run_id);
        if !link_matches {
            return Ok(None);
        }
        // The command started exactly one run.
        let starts = Self::scalar("SELECT COUNT(*) FROM workflow_history_events WHERE workflow_command_id=$1 AND event_type='WorkflowStarted'")
            .bind(command_id)
            .fetch_one(&mut *connection)
            .await?;
        if starts != 1 {
            return Ok(None);
        }
        Ok(Some(carrier(run_id, anchor.with_timezone(&Utc), REDRIVE_ANCHOR)))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn instants_keep_their_represented_precision_in_utc() {
        let whole = DateTime::parse_from_rfc3339("2026-11-12T10:00:00+00:00").unwrap().with_timezone(&Utc);
        assert_eq!(timestamp(whole), "2026-11-12T10:00:00.000000Z");
        let micros = DateTime::parse_from_rfc3339("2026-11-12T11:00:00.123456+01:00").unwrap().with_timezone(&Utc);
        assert_eq!(timestamp(micros), "2026-11-12T10:00:00.123456Z");
        assert_eq!(carrier("run", whole, RUN_STARTED),
            json!({"schema":SCHEMA,"workflow_run_id":"run","started_at":"2026-11-12T10:00:00.000000Z","source":"run_started_at"}));
    }
}
