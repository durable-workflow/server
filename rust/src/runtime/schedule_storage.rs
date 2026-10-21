//! PHP-layout schedule rows, descriptions and durable audit pages.
use super::transaction::Transaction;
use super::{
    Result, RuntimeError,
    backend::Backend,
    refuse,
    schedules::{self, Definition},
    store::{Store, id, now},
};
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

/// Carbon's `toIso8601String()` for a UTC instant.
pub(crate) fn iso8601(time: DateTime<Utc>) -> String {
    time.format("%Y-%m-%dT%H:%M:%S+00:00").to_string()
}

/// `format('Y-m-d\TH:i:s.uP')`, the occurrence form PHP records.
pub(crate) fn occurrence(time: DateTime<Utc>) -> String {
    time.format("%Y-%m-%dT%H:%M:%S%.6f+00:00").to_string()
}

pub(super) fn not_found(schedule_id: &str, namespace: &str) -> RuntimeError {
    RuntimeError::Protocol {
        status: StatusCode::NOT_FOUND,
        response: json!({"message":format!("Schedule [{schedule_id}] not found in namespace [{namespace}]."),
            "reason":"schedule_not_found"}),
    }
}

impl<DB: Backend> Store<DB>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
    for<'q> &'q str: Encode<'q, DB> + Type<DB>,
    for<'q> Option<&'q str>: Encode<'q, DB>,
    for<'q> Option<DB::EncodedDocument>: Encode<'q, DB>,
    for<'q> Option<i64>: Encode<'q, DB>,
    for<'q> String: Encode<'q, DB> + Type<DB>,
    for<'q> i64: Encode<'q, DB> + Type<DB>,
    for<'r> i64: Decode<'r, DB>,
    usize: ColumnIndex<DB::Row>,
{
    /// `ScheduleController::store`: the duplicate check, spec check, row,
    /// creation audit and optional pause commit together.
    pub(super) async fn create_schedule(
        &self,
        definition: Definition,
        created: Value,
        paused: Value,
    ) -> Result<Value> {
        let schedule_id = definition
            .schedule_id
            .clone()
            .unwrap_or_else(|| ulid::Ulid::new().to_string());
        let mut tx = self.begin().await?;
        let existing = Self::query(
            "SELECT status FROM workflow_schedules WHERE namespace=$1 AND schedule_id=$2",
        )
        .bind(self.namespace.as_ref())
        .bind(&schedule_id)
        .fetch_optional(&mut *tx)
        .await?;
        if existing.is_some() {
            // PHP refuses a live duplicate here; it fails a deleted one on
            // the unique key. Both stay a conflict.
            return Err(RuntimeError::Protocol {
                status: StatusCode::CONFLICT,
                response: json!({"message":format!("Schedule [{schedule_id}] already exists in namespace [{}].", self.namespace),
                    "reason":"schedule_already_exists","schedule_id":schedule_id}),
            });
        }
        if let Some(problem) = schedules::spec_problem(&definition.spec) {
            return Err(schedules::invalid_spec(problem, &schedule_id));
        }
        let created_at = now();
        let next = schedules::next_fire(&definition.spec, definition.jitter_seconds, created_at)?;
        let key = id();
        Self::query("INSERT INTO workflow_schedules(id,schedule_id,namespace,spec,action,status,overlap_policy,memo,jitter_seconds,max_runs,remaining_actions,fires_count,failures_count,skipped_trigger_count,note,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,'active',$6,$7,$8,$9,$10,0,0,0,$11,$12,$13)")
            .bind(&key).bind(&schedule_id).bind(self.namespace.as_ref())
            .bind(DB::document(&definition.spec)).bind(DB::document(&definition.action))
            .bind(&definition.overlap_policy).bind(definition.memo.as_ref().map(DB::document))
            .bind(definition.jitter_seconds).bind(definition.max_runs).bind(definition.max_runs)
            .bind(definition.note.as_deref())
            .bind(DB::bind_time(created_at)).bind(DB::bind_time(created_at)).execute(&mut *tx).await?;
        if let Some(next) = next {
            Self::query("UPDATE workflow_schedules SET next_fire_at=$1 WHERE id=$2")
                .bind(DB::bind_time(next))
                .bind(&key)
                .execute(&mut *tx)
                .await?;
        }
        Self::append_schedule(
            &mut tx,
            &key,
            "ScheduleCreated",
            json!({"spec":definition.spec,"action":definition.action,
                "overlap_policy":definition.overlap_policy,"next_fire_at":next.map(iso8601)}),
            Some(&created),
            None,
            None,
        )
        .await?;
        if definition.paused {
            Self::pause_in(&mut tx, &key, None, &paused).await?;
        }
        tx.commit().await?;
        Ok(json!({"schedule_id":schedule_id,"outcome":"created"}))
    }

    /// `ScheduleManager::pause`: an active or paused schedule becomes paused
    /// now, keeping its note unless one is given.
    pub(super) async fn pause_in(
        tx: &mut Transaction<'_, DB>,
        key: &str,
        note: Option<&str>,
        context: &Value,
    ) -> Result<()> {
        let changed = now();
        Self::query("UPDATE workflow_schedules SET status='paused',paused_at=$1,note=COALESCE($2,note),updated_at=$3 WHERE id=$4")
            .bind(DB::bind_time(changed)).bind(note).bind(DB::bind_time(changed)).bind(key).execute(&mut **tx).await?;
        let mut payload = json!({"paused_at":iso8601(changed)});
        if let Some(note) = note {
            payload["reason"] = json!(note);
        }
        Self::append_schedule(tx, key, "SchedulePaused", payload, Some(context), None, None).await
    }

    /// The live (or, when asked, deleted) schedule in this namespace.
    pub(super) async fn schedule_row(
        &self,
        tx: &mut Transaction<'_, DB>,
        schedule_id: &str,
        include_deleted: bool,
    ) -> Result<DB::Row> {
        let row = Self::query("SELECT * FROM workflow_schedules WHERE namespace=$1 AND schedule_id=$2")
            .bind(self.namespace.as_ref())
            .bind(schedule_id)
            .fetch_optional(&mut **tx)
            .await?
            .ok_or_else(|| not_found(schedule_id, &self.namespace))?;
        if !include_deleted && DB::string(&row, "status")? == "deleted" {
            return Err(not_found(schedule_id, &self.namespace));
        }
        Ok(row)
    }

    /// `WorkflowScheduleHistoryEvent::scheduleSnapshot`.
    pub(super) fn schedule_snapshot(row: &DB::Row) -> Result<Value> {
        let mut snapshot = json!({"id":DB::string(row,"id")?,"schedule_id":DB::string(row,"schedule_id")?,
            "status":DB::string(row,"status")?,"overlap_policy":DB::string(row,"overlap_policy")?,
            "fires_count":DB::number(row,"fires_count")?,"skipped_trigger_count":DB::number(row,"skipped_trigger_count")?});
        if let Some(namespace) = DB::optional_string(row, "namespace")? {
            snapshot["namespace"] = json!(namespace);
        }
        for field in [
            "next_fire_at",
            "last_fired_at",
            "paused_at",
            "deleted_at",
            "last_skipped_at",
        ] {
            if let Some(value) = DB::optional_instant(row, field)? {
                snapshot[field] = json!(iso8601(value));
            }
        }
        for field in ["last_skip_reason", "latest_workflow_instance_id", "note"] {
            if let Some(value) = DB::optional_string(row, field)? {
                snapshot[field] = json!(value);
            }
        }
        Ok(snapshot)
    }

    pub(super) async fn append_schedule(
        tx: &mut Transaction<'_, DB>,
        schedule: &str,
        kind: &str,
        mut payload: Value,
        context: Option<&Value>,
        workflow: Option<&str>,
        run: Option<&str>,
    ) -> Result<()> {
        let row = Self::query("SELECT * FROM workflow_schedules WHERE id=$1")
            .bind(schedule)
            .fetch_one(&mut **tx)
            .await?;
        if let Some(context) = context {
            payload["command_context"] = context.clone();
        }
        // PHP adds the snapshot after the event's own fields.
        payload["schedule"] = Self::schedule_snapshot(&row)?;
        // The transition lock already serializes appenders. Read the physical
        // integer column: MariaDB promotes COALESCE(MAX(unsigned),0) to DECIMAL.
        let position = Self::query("SELECT sequence FROM workflow_schedule_history_events WHERE workflow_schedule_id=$1 ORDER BY sequence DESC LIMIT 1")
            .bind(schedule).fetch_optional(&mut **tx).await?;
        let previous = position
            .as_ref()
            .map(|row| DB::number(row, "sequence"))
            .transpose()?
            .unwrap_or(0);
        let sequence = previous
            .checked_add(1)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "schedule_history_sequence_exhausted"))?;
        let recorded = now();
        let occurrence = (kind == "ScheduleTriggered")
            .then(|| payload.get("occurrence_time").and_then(Value::as_str))
            .flatten()
            .map(chrono::DateTime::parse_from_rfc3339)
            .transpose()
            .map_err(|_| refuse(StatusCode::CONFLICT, "invalid_stored_schedule_occurrence"))?
            .map(|value| {
                value
                    .with_timezone(&chrono::Utc)
                    .format("%Y-%m-%d %H:%M:%S%.6f")
                    .to_string()
            });
        Self::query("INSERT INTO workflow_schedule_history_events(id,workflow_schedule_id,schedule_id,namespace,sequence,event_type,payload,workflow_instance_id,workflow_run_id,recorded_at,created_at,updated_at,occurrence_at_utc) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13)")
            .bind(id()).bind(schedule).bind(DB::string(&row,"schedule_id")?).bind(DB::optional_string(&row,"namespace")?.as_deref())
            .bind(sequence).bind(kind).bind(DB::document(&payload))
            .bind(workflow).bind(run).bind(DB::bind_time(recorded)).bind(DB::bind_time(recorded)).bind(DB::bind_time(recorded)).bind(occurrence.as_deref()).execute(&mut **tx).await?;
        Ok(())
    }

    /// `ScheduleController::formatDetail`.
    pub(crate) fn schedule_detail(row: &DB::Row) -> Result<Value> {
        let status = DB::string(row, "status")?;
        let paused = status == "paused";
        let note = DB::optional_string(row, "note")?;
        let time = |field: &str| -> Result<Value> {
            Ok(json!(DB::optional_instant(row, field)?.map(iso8601)))
        };
        let next = time("next_fire_at")?;
        let last = time("last_fired_at")?;
        let fires = DB::number(row, "fires_count")?;
        let failures = DB::number(row, "failures_count")?;
        let action = DB::optional_document_row(row, "action")?
            .map(|action| schedules::normalize_action_timeouts(&action));
        Ok(json!({
            "schedule_id":DB::string(row,"schedule_id")?,
            "spec":DB::optional_document_row(row,"spec")?,
            "action":action,
            "overlap_policy":DB::string(row,"overlap_policy")?,
            "state":{"status":status,"paused":paused,"note":note},
            "info":{"next_fire":next,"last_fire":last,"fires_count":fires,"failures_count":failures,
                "recent_actions":DB::optional_document_row(row,"recent_actions")?.unwrap_or_else(|| json!([])),
                "buffered_actions":DB::optional_document_row(row,"buffered_actions")?.unwrap_or_else(|| json!([])),
                "skipped_trigger_count":DB::number(row,"skipped_trigger_count")?,
                "last_skip_reason":DB::optional_string(row,"last_skip_reason")?,
                "last_skipped_at":time("last_skipped_at")?},
            "memo":DB::optional_document_row(row,"memo")?,
            "search_attributes":DB::optional_document_row(row,"search_attributes")?,
            "created_at":time("created_at")?,
            "updated_at":time("updated_at")?,
            "namespace":DB::optional_string(row,"namespace")?,
            "status":status,"paused":paused,"note":note,
            "fires_count":fires,"failures_count":failures,
            "next_fire_at":next,"last_fired_at":last,"paused_at":time("paused_at")?,
            "jitter_seconds":DB::number(row,"jitter_seconds")?,
            "max_runs":DB::optional_number(row,"max_runs")?,
            "remaining_actions":DB::optional_number(row,"remaining_actions")?,
            "latest_workflow_instance_id":DB::optional_string(row,"latest_workflow_instance_id")?,
        }))
    }

    pub(crate) async fn describe_schedule(&self, schedule: &str) -> Result<Value> {
        let row = Self::query("SELECT * FROM workflow_schedules WHERE namespace=$1 AND schedule_id=$2 AND status!='deleted'")
            .bind(self.namespace.as_ref())
            .bind(schedule)
            .fetch_optional(&self.pool)
            .await?
            .ok_or_else(|| not_found(schedule, &self.namespace))?;
        Self::schedule_detail(&row)
    }

    pub(crate) async fn schedule_history(
        &self,
        schedule: &str,
        after: i64,
        limit: i64,
    ) -> Result<Value> {
        let exists = Self::query("SELECT schedule_id,namespace FROM workflow_schedules WHERE namespace=$1 AND schedule_id=$2")
            .bind(self.namespace.as_ref())
            .bind(schedule)
            .fetch_optional(&self.pool)
            .await?;
        let Some(schedule_row) = exists else {
            return Err(not_found(schedule, &self.namespace));
        };
        let rows = Self::query("SELECT * FROM workflow_schedule_history_events WHERE namespace=$1 AND schedule_id=$2 AND sequence>$3 ORDER BY sequence LIMIT $4")
            .bind(self.namespace.as_ref()).bind(schedule).bind(after).bind(limit+1).fetch_all(&self.pool).await?;
        let has_more = rows.len() > limit as usize;
        let mut events = Vec::new();
        for row in rows.iter().take(limit as usize) {
            events.push(json!({"id":DB::string(row,"id")?,"sequence":DB::number(row,"sequence")?,"event_type":DB::string(row,"event_type")?,
                "payload":DB::document_row(row,"payload")?,"workflow_instance_id":DB::optional_string(row,"workflow_instance_id")?,
                "workflow_run_id":DB::optional_string(row,"workflow_run_id")?,"recorded_at":DB::optional_instant(row,"recorded_at")?.map(iso8601)}));
        }
        let cursor = (has_more && !events.is_empty()).then(|| events.last().unwrap()["sequence"].clone());
        Ok(json!({"schedule_id":DB::string(&schedule_row,"schedule_id")?,"namespace":DB::optional_string(&schedule_row,"namespace")?,
            "events":events,"next_cursor":cursor,"has_more":has_more}))
    }
}
