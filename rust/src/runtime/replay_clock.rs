//! The selected replay entry instant, derived from retained engine authority.
use super::{Result, backend::Backend, transaction::Transaction};
use chrono::{DateTime, Utc};
use regex_lite::Regex;
use serde_json::{Value, json};
use std::sync::OnceLock;

const SCHEMA: &str = "durable-workflow.worker-replay-clock/v1";

pub(super) struct Clock {
    run_id: String,
    started_at: String,
    source: &'static str,
}

fn represented(at: DateTime<Utc>) -> Option<String> {
    if at.timestamp_subsec_nanos() % 1_000 != 0 {
        return None;
    }
    let wire = super::cooperative::timestamp(at);
    (wire.len() == 27).then_some(wire)
}

fn retained_instant(value: &Value) -> Option<String> {
    static RFC3339: OnceLock<Regex> = OnceLock::new();
    let text = value.as_str()?;
    if text.len() > 35
        || !RFC3339
            .get_or_init(|| Regex::new(r"^[0-9]{4}-[0-9]{2}-[0-9]{2}[Tt][0-9]{2}:[0-9]{2}:[0-9]{2}(\.[0-9]{1,9})?([Zz]|[+-][0-9]{2}:[0-9]{2})$").expect("compiled clock grammar"))
            .is_match(text)
    {
        return None;
    }
    represented(DateTime::parse_from_rfc3339(text).ok()?.with_timezone(&Utc))
}

fn aliases(value: &Value, keys: &[&str], expected: &str) -> bool {
    value.is_object()
        && keys.iter().all(|key| match value.get(*key) {
            None => true,
            Some(value) => value.as_str() == Some(expected),
        })
}

/// Fresh claims only. Poll-request replay keeps its original object or absence.
pub(super) fn annotate(claim: &mut Value, clock: Option<Clock>, namespace: &str) {
    let Some(clock) = clock else {
        return;
    };
    if aliases(claim, &["run_id", "workflow_run_id"], &clock.run_id)
        && aliases(claim, &["namespace"], namespace)
    {
        claim["replay_clock"] = json!({"schema":SCHEMA,"workflow_run_id":clock.run_id,
            "started_at":clock.started_at,"source":clock.source});
    }
}

fn nonempty(value: &Value) -> Option<&str> {
    value.as_str().filter(|value| !value.is_empty())
}

fn row_string<DB: Backend>(row: &DB::Row, field: &str, expected: &str) -> bool {
    DB::string(row, field).ok().as_deref() == Some(expected)
}

store_methods! {
    pub(super) async fn worker_replay_clock(
        &self,
        tx: &mut Transaction<'_, DB>,
        task: &DB::Row,
        run: &DB::Row,
    ) -> Result<Option<Clock>> {
        let run_id = DB::string(run, "id")?;
        let workflow_id = DB::string(run, "workflow_instance_id")?;
        if run_id.is_empty() || workflow_id.is_empty()
            || !row_string::<DB>(task, "task_type", "workflow")
            || !row_string::<DB>(task, "workflow_run_id", &run_id)
            || !row_string::<DB>(task, "namespace", &self.namespace)
            || !row_string::<DB>(run, "namespace", &self.namespace)
        {
            return Ok(None);
        }
        // The run/type/sequence index makes both uniqueness and ambiguity a
        // bounded lookup. A first-row selection cannot establish authority.
        let starts = Self::query("SELECT id,workflow_run_id,workflow_command_id,event_type,sequence,payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='WorkflowStarted' ORDER BY sequence LIMIT 2")
            .bind(&run_id).fetch_all(&mut **tx).await?;
        let [started] = starts.as_slice() else {
            return Ok(None);
        };
        let Some(payload) = DB::document_row(started, "payload").ok().filter(Value::is_object) else {
            return Ok(None);
        };
        if !row_string::<DB>(started, "workflow_run_id", &run_id)
            || !DB::string(started, "id").is_ok_and(|id| !id.is_empty())
            || !row_string::<DB>(started, "event_type", "WorkflowStarted")
            || !DB::number(started, "sequence").is_ok_and(|sequence| sequence > 0)
            || !aliases(&payload, &["run_id", "workflow_run_id"], &run_id)
            || !aliases(&payload, &["workflow_id", "workflow_instance_id"], &workflow_id)
        {
            return Ok(None);
        }
        let command_id = match DB::optional_string(started, "workflow_command_id") {
            Ok(command_id) => command_id,
            Err(_) => return Ok(None),
        };
        if payload.get("workflow_command_id").is_some_and(|value| value.as_str() != command_id.as_deref()) {
            return Ok(None);
        }
        let command = match command_id.as_deref() {
            Some(command_id) if !command_id.is_empty() => Self::query("SELECT id,workflow_instance_id,workflow_run_id,requested_workflow_run_id,resolved_workflow_run_id,command_type,target_scope,source,status,outcome FROM workflow_commands WHERE id=$1 AND workflow_run_id=$2")
                .bind(command_id).bind(&run_id).fetch_optional(&mut **tx).await?,
            Some(_) => return Ok(None),
            None => None,
        };
        if command_id.is_some() && command.is_none() {
            return Ok(None);
        }
        if let Some(command) = &command
            && (!row_string::<DB>(command, "workflow_instance_id", &workflow_id)
                || !row_string::<DB>(command, "workflow_run_id", &run_id)
                || !row_string::<DB>(command, "resolved_workflow_run_id", &run_id)
                || !row_string::<DB>(command, "status", "accepted"))
        {
            return Ok(None);
        }
        let redrive_intent = ["recovery_kind", "replayed_started_at", "resume_step_sequence"]
            .iter().any(|key| payload.get(*key).is_some());
        let redrive_command = command.as_ref().is_some_and(|command| row_string::<DB>(command, "command_type", "redrive"));
        let run_number = DB::number(run, "run_number").ok();
        if run_number.is_none_or(|number| number < 1) {
            return Ok(None);
        }
        if !redrive_intent && !redrive_command {
            // Later ordinary starts have their accepted start command. A
            // continuation without one must prove its actual continuation
            // link, so stripping redrive markers cannot select a fresh start.
            let ordinary = match &command {
                Some(command) => row_string::<DB>(command, "command_type", "start")
                    && row_string::<DB>(command, "outcome", "started_new"),
                None if run_number == Some(1) => true,
                None if run_number.is_some_and(|number| number > 1) => self.replay_clock_link(
                    tx, run, &payload, "continue_as_new", None,
                ).await?,
                None => false,
            };
            let at = DB::optional_instant(run, "started_at").ok().flatten().and_then(represented);
            return Ok(ordinary.then_some(at).flatten().map(|started_at| Clock {
                run_id, started_at, source: "run_started_at",
            }));
        }
        let Some(command) = command.as_ref() else {
            return Ok(None);
        };
        let Some(resume) = payload["resume_step_sequence"].as_i64().filter(|value| *value > 0) else {
            return Ok(None);
        };
        if payload["recovery_kind"] != "redrive" || !redrive_command
            || payload["workflow_command_id"].as_str() != command_id.as_deref()
            || !run_number.is_some_and(|number| number > 1)
            || !row_string::<DB>(command, "target_scope", "run")
            || !row_string::<DB>(command, "source", "control_plane")
            || !row_string::<DB>(command, "outcome", "redriven")
            || nonempty(&payload["continued_from_run_id"]) != DB::optional_string(command, "requested_workflow_run_id").ok().flatten().as_deref()
            || !self.replay_clock_link(tx, run, &payload, "redrive", Some(resume)).await?
        {
            return Ok(None);
        }
        Ok(retained_instant(&payload["replayed_started_at"]).map(|started_at| Clock {
            run_id, started_at, source: "redrive_replayed_started_at",
        }))
    }

    async fn replay_clock_link(
        &self,
        tx: &mut Transaction<'_, DB>,
        run: &DB::Row,
        payload: &Value,
        kind: &str,
        resume: Option<i64>,
    ) -> Result<bool> {
        let (Some(link_id), Some(source_id)) = (
            nonempty(&payload["workflow_link_id"]), nonempty(&payload["continued_from_run_id"]),
        ) else {
            return Ok(false);
        };
        let run_id = DB::string(run, "id")?;
        let workflow_id = DB::string(run, "workflow_instance_id")?;
        if source_id == run_id {
            return Ok(false);
        }
        let source = Self::query("SELECT id,workflow_instance_id,namespace,run_number FROM workflow_runs WHERE id=$1 AND namespace=$2")
            .bind(source_id).bind(self.namespace.as_ref()).fetch_optional(&mut **tx).await?;
        let Some(source) = source else {
            return Ok(false);
        };
        if !row_string::<DB>(&source, "id", source_id)
            || !row_string::<DB>(&source, "namespace", &self.namespace)
            || !row_string::<DB>(&source, "workflow_instance_id", &workflow_id)
            || DB::number(&source, "run_number").ok().and_then(|number| number.checked_add(1)) != DB::number(run, "run_number").ok()
        {
            return Ok(false);
        }
        let link = Self::query("SELECT id,link_type,sequence,parent_workflow_instance_id,parent_workflow_run_id,child_workflow_instance_id,child_workflow_run_id FROM workflow_links WHERE id=$1 AND child_workflow_run_id=$2")
            .bind(link_id).bind(&run_id).fetch_optional(&mut **tx).await?;
        Ok(link.is_some_and(|link| row_string::<DB>(&link, "id", link_id)
            && row_string::<DB>(&link, "link_type", kind)
            && row_string::<DB>(&link, "parent_workflow_instance_id", &workflow_id)
            && row_string::<DB>(&link, "parent_workflow_run_id", source_id)
            && row_string::<DB>(&link, "child_workflow_instance_id", &workflow_id)
            && row_string::<DB>(&link, "child_workflow_run_id", &run_id)
            && resume.is_none_or(|resume| DB::optional_number(&link, "sequence").ok().flatten() == Some(resume))))
    }
}

#[cfg(test)]
store_methods! {
    pub(super) async fn qualify_replay_clock_start_uniqueness(&mut self) {
        use std::collections::BTreeMap;
        self.register(json!({"worker_id":"clock-worker","task_queue":"clock",
            "runtime":"php","supported_workflow_types":["clock"],"supported_activity_types":[]}),
            &super::QueryTaskConfig::default(),None).await.unwrap();
        let started = self.start(json!({"workflow_id":format!("clock-{}",ulid::Ulid::new()),
            "workflow_type":"clock","task_queue":"clock","input":{"codec":"avro","blob":"AA=="}}),
            BTreeMap::new(),None,None).await.unwrap();
        let run_id = started["run_id"].as_str().unwrap();
        let mut tx = self.begin().await.unwrap();
        let run = Self::query("SELECT * FROM workflow_runs WHERE id=$1").bind(run_id).fetch_one(&mut *tx).await.unwrap();
        let task = Self::query("SELECT * FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow'").bind(run_id).fetch_one(&mut *tx).await.unwrap();
        assert!(self.worker_replay_clock(&mut tx,&task,&run).await.unwrap().is_some());
        let event = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type='WorkflowStarted'").bind(run_id).fetch_one(&mut *tx).await.unwrap();
        let payload = DB::document_row(&event,"payload").unwrap();
        Self::append(&mut tx,run_id,"WorkflowStarted",payload,None,None).await.unwrap();
        assert!(self.worker_replay_clock(&mut tx,&task,&run).await.unwrap().is_none());
        tx.rollback().await.unwrap();
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn represented_instants_keep_offsets_and_actual_precision() {
        for text in ["2026-11-06T01:02:03Z", "2026-11-06T01:02:03+00:00", "2026-11-06T01:02:03-00:00", "2026-11-06T06:32:03+05:30"] {
            assert_eq!(retained_instant(&json!(text)).as_deref(), Some("2026-11-06T01:02:03.000000Z"));
        }
        assert_eq!(retained_instant(&json!("2026-11-06T01:02:03.123456Z")).as_deref(), Some("2026-11-06T01:02:03.123456Z"));
        assert_eq!(retained_instant(&json!("2026-11-06T01:02:03.123456000Z")).as_deref(), Some("2026-11-06T01:02:03.123456Z"));
        for value in [Value::Null, json!(true), json!(0), json!("2026-02-30T01:02:03Z"), json!("2026-11-06T24:00:00Z"), json!("2026-11-06"), json!("2026-11-06T01:02:03"), json!("2026-11-06T01:02:03.123456001Z"), json!(" 2026-11-06T01:02:03Z")] {
            assert!(retained_instant(&value).is_none(), "{value}");
        }
    }

    #[test]
    fn closed_carrier_binds_every_present_run_alias_and_namespace() {
        let make = || Some(Clock { run_id:"run".into(), started_at:"2026-11-06T01:02:03.123456Z".into(), source:"run_started_at" });
        let mut claim = json!({"run_id":"run","workflow_run_id":"run","namespace":"tenant"});
        annotate(&mut claim, make(), "tenant");
        assert_eq!(claim["replay_clock"], json!({"schema":SCHEMA,"workflow_run_id":"run","started_at":"2026-11-06T01:02:03.123456Z","source":"run_started_at"}));
        for (key, value) in [("run_id",json!("foreign")),("workflow_run_id",json!("foreign")),("workflow_run_id",Value::Null),("namespace",json!("foreign"))] {
            let mut claim = json!({"run_id":"run","workflow_run_id":"run","namespace":"tenant"});
            claim[key] = value;
            let before = claim.clone();
            annotate(&mut claim, make(), "tenant");
            assert_eq!(claim, before);
        }
        let mut claim = json!({"workflow_run_id":"run"});
        annotate(&mut claim, make(), "tenant");
        assert!(claim["replay_clock"].is_object());
        assert!(claim.get("run_id").is_none());
        let mut claim = json!({"run_id":"run"});
        annotate(&mut claim, None, "tenant");
        assert!(claim.get("replay_clock").is_none());
    }

    #[tokio::test]
    async fn sqlite_duplicate_start_authority_is_unavailable() {
        let directory = tempfile::tempdir().unwrap();
        let mut store = super::super::store::Store::<sqlx::Sqlite>::new(
            super::super::sqlite::open(directory.path().join("clock.sqlite").to_str().unwrap()).await.unwrap(),
        );
        store.qualify_replay_clock_start_uniqueness().await;
        use sqlx::Row;
        let rows = sqlx::query("EXPLAIN QUERY PLAN SELECT id,payload FROM workflow_history_events WHERE workflow_run_id=? AND event_type='WorkflowStarted' ORDER BY sequence LIMIT 2")
            .bind("absent").fetch_all(&store.pool).await.unwrap();
        assert!(rows.iter().any(|row| row.get::<String,_>("detail").contains("workflow_history_events_run_type_sequence_index")));
        store.close().await;
    }
}
