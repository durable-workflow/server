//! PHP-layout schedule rows and durable audit pages.
use super::{
    Result,
    backend::Backend,
    refuse,
    schedules::Definition,
    store::{Store, id, now},
};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Transaction, Type};

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
    pub(super) async fn create_schedule(
        &self,
        definition: Definition,
        context: Value,
    ) -> Result<Value> {
        let mut tx = self.begin().await?;
        let exists = Self::query(
            "SELECT id FROM workflow_schedules WHERE namespace='default' AND schedule_id=$1",
        )
        .bind(&definition.schedule_id)
        .fetch_optional(&mut *tx)
        .await?;
        if exists.is_some() {
            return Err(refuse(StatusCode::CONFLICT, "schedule_id_already_exists"));
        }
        let schedule = id();
        let created = now();
        Self::query("INSERT INTO workflow_schedules(id,schedule_id,namespace,spec,action,status,overlap_policy,jitter_seconds,fires_count,failures_count,skipped_trigger_count,next_fire_at,created_at,updated_at) VALUES ($1,$2,'default',$3,$4,'active','skip',0,0,0,0,$5,$6,$7)")
            .bind(&schedule).bind(&definition.schedule_id).bind(DB::document(&definition.spec)).bind(DB::document(&definition.action))
            .bind(DB::bind_time(definition.next_fire)).bind(DB::bind_time(created)).bind(DB::bind_time(created)).execute(&mut *tx).await?;
        if definition.max_runs.is_some() {
            Self::query("UPDATE workflow_schedules SET max_runs=1,remaining_actions=1 WHERE id=$1")
                .bind(&schedule)
                .execute(&mut *tx)
                .await?;
        }
        Self::append_schedule(
            &mut tx,
            &schedule,
            "ScheduleCreated",
            json!({"spec":definition.spec,"action":definition.action,
            "overlap_policy":"skip","next_fire_at":definition.next_fire}),
            Some(&context),
            None,
            None,
        )
        .await?;
        tx.commit().await?;
        Ok(json!({"schedule_id":definition.schedule_id,"outcome":"created"}))
    }

    pub(super) async fn schedule_row(
        tx: &mut Transaction<'_, DB>,
        schedule_id: &str,
        include_deleted: bool,
    ) -> Result<DB::Row> {
        let row = Self::query(
            "SELECT * FROM workflow_schedules WHERE namespace='default' AND schedule_id=$1",
        )
        .bind(schedule_id)
        .fetch_optional(&mut **tx)
        .await?
        .ok_or_else(|| refuse(StatusCode::NOT_FOUND, "schedule_not_found"))?;
        if !include_deleted && DB::string(&row, "status")? == "deleted" {
            return Err(refuse(StatusCode::NOT_FOUND, "schedule_not_found"));
        }
        Ok(row)
    }

    pub(super) fn schedule_snapshot(row: &DB::Row) -> Result<Value> {
        let mut snapshot = json!({"id":DB::string(row,"id")?,"schedule_id":DB::string(row,"schedule_id")?,"namespace":"default",
            "status":DB::string(row,"status")?,"overlap_policy":DB::string(row,"overlap_policy")?,
            "fires_count":DB::number(row,"fires_count")?,"skipped_trigger_count":DB::number(row,"skipped_trigger_count")?});
        for field in [
            "next_fire_at",
            "last_fired_at",
            "paused_at",
            "deleted_at",
            "last_skipped_at",
        ] {
            if let Some(value) = DB::optional_instant(row, field)? {
                snapshot[field] = json!(value);
            }
        }
        for field in ["last_skip_reason", "latest_workflow_instance_id"] {
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
        payload["schedule"] = Self::schedule_snapshot(&row)?;
        if let Some(context) = context {
            payload["command_context"] = context.clone();
        }
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
        let occurrence = payload
            .get("occurrence_time")
            .and_then(Value::as_str)
            .map(chrono::DateTime::parse_from_rfc3339)
            .transpose()
            .map_err(|_| refuse(StatusCode::CONFLICT, "invalid_stored_schedule_occurrence"))?
            .map(|value| {
                value
                    .with_timezone(&chrono::Utc)
                    .format("%Y-%m-%d %H:%M:%S%.6f")
                    .to_string()
            });
        Self::query("INSERT INTO workflow_schedule_history_events(id,workflow_schedule_id,schedule_id,namespace,sequence,event_type,payload,workflow_instance_id,workflow_run_id,recorded_at,created_at,updated_at,occurrence_at_utc) VALUES ($1,$2,$3,'default',$4,$5,$6,$7,$8,$9,$10,$11,$12)")
            .bind(id()).bind(schedule).bind(DB::string(&row,"schedule_id")?).bind(sequence).bind(kind).bind(DB::document(&payload))
            .bind(workflow).bind(run).bind(DB::bind_time(recorded)).bind(DB::bind_time(recorded)).bind(DB::bind_time(recorded)).bind(occurrence.as_deref()).execute(&mut **tx).await?;
        Ok(())
    }

    pub(crate) async fn describe_schedule(&self, schedule: &str) -> Result<Value> {
        let row = Self::query("SELECT * FROM workflow_schedules WHERE namespace='default' AND schedule_id=$1 AND status!='deleted'")
            .bind(schedule).fetch_optional(&self.pool).await?.ok_or_else(|| refuse(StatusCode::NOT_FOUND,"schedule_not_found"))?;
        let status = DB::string(&row, "status")?;
        let fires = DB::number(&row, "fires_count")?;
        let failures = DB::number(&row, "failures_count")?;
        let skipped = DB::number(&row, "skipped_trigger_count")?;
        let next = DB::optional_instant(&row, "next_fire_at")?;
        let last = DB::optional_instant(&row, "last_fired_at")?;
        let recent =
            DB::optional_document_row(&row, "recent_actions")?.unwrap_or_else(|| json!([]));
        let mut result = json!({"schedule_id":schedule,"namespace":"default","spec":DB::document_row(&row,"spec")?,"action":DB::document_row(&row,"action")?,
            "status":status,"paused":status=="paused","note":null,"overlap_policy":"skip","memo":null,"search_attributes":null,
            "state":{"status":status,"paused":status=="paused","note":null},
            "info":{"next_fire":next,"last_fire":last,"fires_count":fires,"failures_count":failures,"recent_actions":recent,"buffered_actions":[],
                "skipped_trigger_count":skipped,"last_skip_reason":DB::optional_string(&row,"last_skip_reason")?,"last_skipped_at":DB::optional_instant(&row,"last_skipped_at")?},
            "fires_count":fires,"failures_count":failures,"next_fire_at":next,"last_fired_at":last,"jitter_seconds":0,
            "max_runs":DB::optional_number(&row,"max_runs")?,"remaining_actions":DB::optional_number(&row,"remaining_actions")?,
            "latest_workflow_instance_id":DB::optional_string(&row,"latest_workflow_instance_id")?});
        for field in ["created_at", "updated_at", "paused_at"] {
            result[field] = json!(DB::optional_instant(&row, field)?);
        }
        Ok(result)
    }

    pub(crate) async fn schedule_history(
        &self,
        schedule: &str,
        after: i64,
        limit: i64,
    ) -> Result<Value> {
        if after < 0 || !(1..=100).contains(&limit) {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "invalid_schedule_history_page",
            ));
        }
        let exists = Self::query(
            "SELECT id FROM workflow_schedules WHERE namespace='default' AND schedule_id=$1",
        )
        .bind(schedule)
        .fetch_optional(&self.pool)
        .await?;
        if exists.is_none() {
            return Err(refuse(StatusCode::NOT_FOUND, "schedule_not_found"));
        }
        let rows = Self::query("SELECT * FROM workflow_schedule_history_events WHERE namespace='default' AND schedule_id=$1 AND sequence>$2 ORDER BY sequence LIMIT $3")
            .bind(schedule).bind(after).bind(limit+1).fetch_all(&self.pool).await?;
        let has_more = rows.len() > limit as usize;
        let mut events = Vec::new();
        for row in rows.iter().take(limit as usize) {
            events.push(json!({"id":DB::string(row,"id")?,"sequence":DB::number(row,"sequence")?,"event_type":DB::string(row,"event_type")?,
                "payload":DB::document_row(row,"payload")?,"workflow_instance_id":DB::optional_string(row,"workflow_instance_id")?,
                "workflow_run_id":DB::optional_string(row,"workflow_run_id")?,"recorded_at":DB::instant(row,"recorded_at")?}));
        }
        let cursor = has_more.then(|| events.last().unwrap()["sequence"].clone());
        Ok(json!({"events":events,"next_cursor":cursor}))
    }
}
