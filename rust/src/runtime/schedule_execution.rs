//! Schedule controls and atomic admission through the existing start transition.
use super::{
    Result,
    backend::Backend,
    refuse, schedules,
    store::{Store, id, now},
};
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
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
    pub(crate) async fn edit_schedule(
        &self,
        schedule: &str,
        operation: &str,
        context: Value,
    ) -> Result<Value> {
        let mut tx = self.begin().await?;
        let row = Self::schedule_row(&mut tx, schedule, false).await?;
        let key = DB::string(&row, "id")?;
        let status = DB::string(&row, "status")?;
        let changed = now();
        let (event, outcome, payload) = match operation {
            "pause" if status == "active" => {
                Self::query("UPDATE workflow_schedules SET status='paused',paused_at=$1,updated_at=$2 WHERE id=$3")
                    .bind(DB::bind_time(changed)).bind(DB::bind_time(changed)).bind(&key).execute(&mut *tx).await?;
                ("SchedulePaused", "paused", json!({"paused_at":changed}))
            }
            "resume" if status == "paused" => {
                let next = schedules::next(&DB::document_row(&row, "spec")?, changed)?;
                Self::query("UPDATE workflow_schedules SET status='active',paused_at=NULL,next_fire_at=$1,updated_at=$2 WHERE id=$3")
                    .bind(DB::bind_time(next)).bind(DB::bind_time(changed)).bind(&key).execute(&mut *tx).await?;
                ("ScheduleResumed", "resumed", json!({"next_fire_at":next}))
            }
            "delete" => {
                Self::query("UPDATE workflow_schedules SET status='deleted',deleted_at=$1,next_fire_at=NULL,updated_at=$2 WHERE id=$3")
                    .bind(DB::bind_time(changed)).bind(DB::bind_time(changed)).bind(&key).execute(&mut *tx).await?;
                (
                    "ScheduleDeleted",
                    "deleted",
                    json!({"reason":"deleted","deleted_at":changed}),
                )
            }
            _ => return Err(refuse(StatusCode::CONFLICT, "schedule_status_conflict")),
        };
        Self::append_schedule(&mut tx, &key, event, payload, Some(&context), None, None).await?;
        tx.commit().await?;
        Ok(json!({"schedule_id":schedule,"outcome":outcome}))
    }

    pub(crate) async fn trigger_schedule(&self, schedule: &str, context: Value) -> Result<Value> {
        let mut tx = self.begin().await?;
        let row = Self::schedule_row(&mut tx, schedule, false).await?;
        if DB::string(&row, "status")? != "active" {
            let skipped =
                Self::skip_schedule(&mut tx, &row, "status_not_triggerable", Some(&context))
                    .await?;
            tx.commit().await?;
            return Ok(skipped);
        }
        let started = Self::fire_schedule(&mut tx, &row, None, Some(&context)).await?;
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(started)
    }

    pub(crate) async fn fire_due_schedules(&self) -> Result<()> {
        // A bounded read probe avoids the global transition lock on idle ticks.
        let due = Self::query("SELECT schedule_id FROM workflow_schedules WHERE namespace='default' AND status='active' AND next_fire_at IS NOT NULL AND next_fire_at<=$1 ORDER BY next_fire_at,id LIMIT 100")
            .bind(DB::bind_time(now())).fetch_all(&self.pool).await?;
        for candidate in due {
            let name = DB::string(&candidate, "schedule_id")?;
            let mut tx = self.begin().await?;
            let row = Self::schedule_row(&mut tx, &name, true).await?;
            if DB::string(&row, "status")? != "active" {
                continue;
            }
            let Some(occurrence) =
                DB::optional_instant(&row, "next_fire_at")?.filter(|deadline| *deadline <= now())
            else {
                continue;
            };
            // Rereading under the existing cross-node transition lock fences
            // competing evaluators. Failure rolls back the original occurrence.
            Self::fire_schedule(&mut tx, &row, Some(occurrence), None).await?;
            tx.commit().await?;
            self.wake.notify_waiters();
        }
        Ok(())
    }

    async fn skip_schedule(
        tx: &mut Transaction<'_, DB>,
        row: &DB::Row,
        reason: &str,
        context: Option<&Value>,
    ) -> Result<Value> {
        let changed = now();
        let skipped = DB::number(row, "skipped_trigger_count")?
            .checked_add(1)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "schedule_skip_count_exhausted"))?;
        let key = DB::string(row, "id")?;
        Self::query("UPDATE workflow_schedules SET skipped_trigger_count=$1,last_skip_reason=$2,last_skipped_at=$3,updated_at=$4 WHERE id=$5")
            .bind(skipped).bind(reason).bind(DB::bind_time(changed)).bind(DB::bind_time(changed)).bind(&key).execute(&mut **tx).await?;
        Self::append_schedule(
            tx,
            &key,
            "ScheduleTriggerSkipped",
            json!({"reason":reason,"skipped_trigger_count":skipped,"last_skipped_at":changed}),
            context,
            None,
            None,
        )
        .await?;
        Ok(
            json!({"schedule_id":DB::string(row,"schedule_id")?,"outcome":"skipped","reason":reason}),
        )
    }

    async fn fire_schedule(
        tx: &mut Transaction<'_, DB>,
        row: &DB::Row,
        occurrence: Option<DateTime<Utc>>,
        context: Option<&Value>,
    ) -> Result<Value> {
        let key = DB::string(row, "id")?;
        let schedule = DB::string(row, "schedule_id")?;
        let spec = DB::document_row(row, "spec")?;
        if let Some(latest) = DB::optional_string(row, "latest_workflow_instance_id")? {
            let active = Self::query("SELECT r.status FROM workflow_instances i JOIN workflow_runs r ON r.id=i.current_run_id WHERE i.id=$1 AND i.namespace='default'")
                .bind(latest).fetch_optional(&mut **tx).await?;
            if active
                .as_ref()
                .map(|run| DB::string(run, "status"))
                .transpose()?
                .is_some_and(|status| matches!(status.as_str(), "pending" | "running" | "waiting"))
            {
                let skipped = Self::skip_schedule(tx, row, "overlap_policy_skip", context).await?;
                let next = schedules::next(&spec, now())?;
                Self::query("UPDATE workflow_schedules SET next_fire_at=$1 WHERE id=$2")
                    .bind(DB::bind_time(next))
                    .bind(&key)
                    .execute(&mut **tx)
                    .await?;
                return Ok(skipped);
            }
        }
        let trigger_number = DB::number(row, "fires_count")?
            .checked_add(1)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "schedule_fire_count_exhausted"))?;
        let mut action = DB::document_row(row, "action")?;
        action["workflow_id"] = json!(id());
        let started = Self::start_in(tx, &action, "default").await?;
        let workflow = started["workflow_id"].as_str().unwrap();
        let run = started["run_id"].as_str().unwrap();
        let mut marker = json!({"schedule_id":schedule,"schedule_ulid":key,"timezone":"UTC","overlap_policy":"skip","trigger_number":trigger_number});
        let mut audit = json!({"workflow_instance_id":workflow,"workflow_run_id":run,"outcome":"scheduled","effective_overlap_policy":"skip","trigger_number":trigger_number});
        if let Some(occurrence) = occurrence {
            let value = occurrence.format("%Y-%m-%dT%H:%M:%S%.6f+00:00").to_string();
            marker["occurrence_time"] = json!(value);
            audit["occurrence_time"] = marker["occurrence_time"].clone();
        }
        Self::append(tx, run, "ScheduleTriggered", marker, None, None).await?;
        Self::append_schedule(
            tx,
            &key,
            "ScheduleTriggered",
            audit,
            context,
            Some(workflow),
            Some(run),
        )
        .await?;
        let fired = now();
        let mut recent =
            json!({"workflow_id":workflow,"run_id":run,"outcome":"scheduled","fired_at":fired});
        if let Some(occurrence) = occurrence {
            recent["occurrence_time"] =
                json!(occurrence.format("%Y-%m-%dT%H:%M:%S%.6f+00:00").to_string());
            recent["fire_lag_ms"] = json!((fired - occurrence).num_milliseconds());
        }
        Self::query("UPDATE workflow_schedules SET fires_count=$1,last_fired_at=$2,latest_workflow_instance_id=$3,recent_actions=$4,updated_at=$5 WHERE id=$6")
            .bind(trigger_number).bind(DB::bind_time(fired)).bind(workflow).bind(DB::document(&json!([recent]))).bind(DB::bind_time(fired)).bind(&key).execute(&mut **tx).await?;
        if DB::optional_number(row, "max_runs")? == Some(1) {
            Self::query("UPDATE workflow_schedules SET status='deleted',remaining_actions=0,next_fire_at=NULL,deleted_at=$1 WHERE id=$2")
                .bind(DB::bind_time(fired)).bind(&key).execute(&mut **tx).await?;
            Self::append_schedule(
                tx,
                &key,
                "ScheduleDeleted",
                json!({"reason":"max_runs_exhausted","deleted_at":fired}),
                None,
                None,
                None,
            )
            .await?;
        } else {
            let next = schedules::next(&spec, fired)?;
            Self::query("UPDATE workflow_schedules SET next_fire_at=$1 WHERE id=$2")
                .bind(DB::bind_time(next))
                .bind(&key)
                .execute(&mut **tx)
                .await?;
        }
        Ok(
            json!({"schedule_id":schedule,"outcome":"triggered","workflow_id":workflow,"run_id":run}),
        )
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::codec::{Value as Payload, ValueCodec};

    #[tokio::test]
    async fn failed_due_admission_rolls_back_start_and_retains_original_occurrence_and_quota() {
        let directory = tempfile::tempdir().unwrap();
        let file = directory.path().join("schedule.sqlite");
        let pool = super::super::sqlite::open(file.to_str().unwrap())
            .await
            .unwrap();
        let store = Store::<sqlx::Sqlite>::new(pool);
        let input = ValueCodec::new()
            .unwrap()
            .encode(&Payload::Array(vec![Payload::Long(9007199254740993)]))
            .unwrap();
        let at = now() - chrono::Duration::seconds(4);
        let definition = schedules::definition(&json!({"schedule_id":"atomic-occurrence",
            "spec":{"intervals":[{"every":"PT2S"}],"timezone":"UTC"},
            "action":{"workflow_type":"echo","task_queue":"test","input":{"codec":"avro","blob":input},
                "execution_timeout_seconds":3600,"run_timeout_seconds":600},
            "overlap_policy":"skip","jitter_seconds":0,"max_runs":1}),at).unwrap();
        let occurrence = definition.next_fire;
        store
            .create_schedule(definition, json!({"test":"original creation"}))
            .await
            .unwrap();
        sqlx::query("CREATE TRIGGER reject_schedule_audit BEFORE INSERT ON workflow_schedule_history_events WHEN NEW.event_type='ScheduleTriggered' BEGIN SELECT RAISE(ABORT,'injected schedule audit failure'); END")
            .execute(&store.pool).await.unwrap();
        assert!(store.fire_due_schedules().await.is_err());
        for table in [
            "workflow_instances",
            "workflow_runs",
            "workflow_tasks",
            "workflow_commands",
            "workflow_history_events",
        ] {
            // Compiled test-only table names, never request input.
            let count: i64 =
                sqlx::query_scalar(sqlx::AssertSqlSafe(format!("SELECT COUNT(*) FROM {table}")))
                    .fetch_one(&store.pool)
                    .await
                    .unwrap();
            assert_eq!(
                count, 0,
                "partial {table} survived failed schedule admission"
            );
        }
        let before = store.describe_schedule("atomic-occurrence").await.unwrap();
        assert_eq!(before["fires_count"], 0);
        assert_eq!(before["failures_count"], 0);
        assert_eq!(before["remaining_actions"], 1);
        assert_eq!(before["status"], "active");
        assert_eq!(
            chrono::DateTime::parse_from_rfc3339(before["next_fire_at"].as_str().unwrap()).unwrap(),
            occurrence
        );
        assert_eq!(
            store
                .schedule_history("atomic-occurrence", 0, 100)
                .await
                .unwrap()["events"]
                .as_array()
                .unwrap()
                .len(),
            1
        );
        sqlx::query("DROP TRIGGER reject_schedule_audit")
            .execute(&store.pool)
            .await
            .unwrap();
        store.fire_due_schedules().await.unwrap();
        store.fire_due_schedules().await.unwrap();
        let history = store
            .schedule_history("atomic-occurrence", 0, 100)
            .await
            .unwrap();
        assert_eq!(history["events"].as_array().unwrap().len(), 3);
        assert_eq!(
            chrono::DateTime::parse_from_rfc3339(
                history["events"][1]["payload"]["occurrence_time"]
                    .as_str()
                    .unwrap()
            )
            .unwrap(),
            occurrence
        );
        assert_eq!(
            history["events"][2]["payload"]["reason"],
            "max_runs_exhausted"
        );
        let runs: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM workflow_runs")
            .fetch_one(&store.pool)
            .await
            .unwrap();
        assert_eq!(runs, 1);
        store.close().await;
    }
}
