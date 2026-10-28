//! External wait timeouts retain their authored occurrence and transaction.
use super::{
    Result,
    backend::Backend,
    refuse,
    store::{Store, id, now},
    transaction::Transaction,
};
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

pub(super) fn timeout(command: &Value) -> Result<Option<(i64, DateTime<Utc>)>> {
    timeout_for(command, "invalid_signal_timeout")
}

pub(super) fn timeout_for(
    command: &Value,
    reason: &'static str,
) -> Result<Option<(i64, DateTime<Utc>)>> {
    let Some(value) = command
        .get("timeout_seconds")
        .filter(|value| !value.is_null())
    else {
        return Ok(None);
    };
    value
        .as_i64()
        .filter(|seconds| *seconds >= 0)
        .and_then(|seconds| chrono::Duration::try_seconds(seconds).map(|delay| (seconds, delay)))
        .and_then(|(seconds, delay)| now().checked_add_signed(delay).map(|at| (seconds, at)))
        .map(Some)
        .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, reason))
}

pub(super) fn wait_fields(wait: &Value) -> Value {
    let mut fields = serde_json::Map::new();
    for key in [
        "condition_wait_id",
        "condition_wait_occurrence_id",
        "condition_key",
        "condition_definition_fingerprint",
        "signal_wait_id",
        "signal_name",
    ] {
        if let Some(value) = wait.get(key).filter(|value| !value.is_null()) {
            fields.insert(key.into(), value.clone());
        }
    }
    Value::Object(fields)
}

pub(super) fn resolution(payload: &Value) -> Value {
    let condition = payload["timer_kind"] == "condition_timeout";
    let mut value = json!({"workflow_wait_kind":if condition {"condition"} else {"signal"},"open_wait_id":payload[if condition {"condition_wait_id"} else {"signal_wait_id"}],
        "resume_source_kind":"timer","resume_source_id":payload["timer_id"],
        "timer_id":payload["timer_id"],"timer_kind":payload["timer_kind"],
        "workflow_sequence":payload["sequence"],"workflow_event_type":"TimerFired"});
    value
        .as_object_mut()
        .unwrap()
        .extend(wait_fields(payload).as_object().unwrap().clone());
    value
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
    pub(super) async fn schedule_wait_timeout(
        tx: &mut Transaction<'_, DB>,
        run: &DB::Row,
        wait: &Value,
        task_id: &str,
    ) -> Result<Option<String>> {
        let direct = wait.get("signal_name").is_some();
        let Some((seconds, fire_at)) = timeout_for(
            wait,
            if direct {
                "invalid_signal_timeout"
            } else {
                "invalid_condition_timeout"
            },
        )?
        else {
            return Ok(None);
        };
        let run_id = DB::string(run, "id")?;
        let timer_id = id();
        let sequence = wait["sequence"]
            .as_i64()
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "invalid_stored_wait"))?;
        Self::query("INSERT INTO workflow_run_timers(id,workflow_run_id,sequence,status,delay_seconds,fire_at,fired_at) VALUES ($1,$2,$3,$4,$5,$6,$7)")
            .bind(&timer_id).bind(&run_id).bind(sequence).bind(if seconds == 0 { "fired" } else { "pending" })
            .bind(seconds).bind(DB::bind_time(fire_at)).bind(DB::optional_time((seconds == 0).then_some(fire_at)))
            .execute(&mut **tx).await?;
        let mut payload = json!({"timer_id":timer_id,"sequence":sequence,"delay_seconds":seconds,
            "fire_at":super::cancellation_scopes::iso(fire_at),"timer_kind":if direct {"signal_timeout"} else {"condition_timeout"}});
        payload
            .as_object_mut()
            .unwrap()
            .extend(wait_fields(wait).as_object().unwrap().clone());
        Self::append(
            tx,
            &run_id,
            "TimerScheduled",
            payload.clone(),
            Some(task_id),
            None,
        )
        .await?;
        if seconds == 0 {
            payload.as_object_mut().unwrap().remove("fire_at");
            payload["fired_at"] = json!(fire_at);
            Self::append(
                tx,
                &run_id,
                "TimerFired",
                payload.clone(),
                Some(task_id),
                None,
            )
            .await?;
            let resume = Self::create_task(
                tx,
                &run_id,
                &DB::string(run, "queue")?,
                "workflow",
                resolution(&payload),
            )
            .await?;
            // The completion reports the task it created, as
            // `applyOpenSignalWait` and `applyOpenConditionWait` do.
            return Ok(Some(resume));
        } else {
            let mut task_payload = wait_fields(wait);
            task_payload["timer_id"] = json!(timer_id);
            let timer_task = Self::create_task(
                tx,
                &run_id,
                &DB::string(run, "queue")?,
                "timer",
                task_payload,
            )
            .await?;
            Self::query("UPDATE workflow_tasks SET available_at=$1 WHERE id=$2")
                .bind(DB::bind_time(fire_at))
                .bind(&timer_task)
                .execute(&mut **tx)
                .await?;
            Self::query("UPDATE workflow_run_waits SET task_id=$1,task_type='timer',task_status='ready',task_backed=TRUE WHERE workflow_run_id=$2 AND wait_id=$3 AND kind=$4")
                .bind(&timer_task).bind(&run_id).bind(super::text(wait,if direct {"signal_wait_id"} else {"condition_wait_id"})?)
                .bind(if direct {"signal"} else {"condition"}).execute(&mut **tx).await?;
            return Ok(Some(timer_task));
        }
        Ok(None)
    }

    pub(super) async fn signal_timeout_finished(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        wait: &Value,
    ) -> Result<bool> {
        if wait.get("signal_name").is_none() || wait["timeout_seconds"].is_null() {
            return Ok(false);
        }
        let timer = Self::query(
            "SELECT id,status FROM workflow_run_timers WHERE workflow_run_id=$1 AND sequence=$2",
        )
        .bind(run_id)
        .bind(wait["sequence"].as_i64().unwrap_or(0))
        .fetch_optional(&mut **tx)
        .await?;
        let Some(timer) = timer else {
            return Ok(false);
        };
        if DB::string(&timer, "status")? == "pending" {
            return Ok(false);
        }
        // Receipt before firing still wins when the worker applies that input.
        Ok(Self::scalar("SELECT COUNT(*) FROM workflow_signal_records WHERE workflow_run_id=$1 AND signal_wait_id=$2 AND status='received'")
            .bind(run_id).bind(super::text(wait,"signal_wait_id")?).fetch_one(&mut **tx).await? == 0)
    }

    pub(super) async fn condition_timer(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        wait: &Value,
    ) -> Result<Option<(String, bool)>> {
        if wait.get("condition_wait_id").is_none() || wait["timeout_seconds"].is_null() {
            return Ok(None);
        }
        let Some(timer) = Self::query(
            "SELECT id,status FROM workflow_run_timers WHERE workflow_run_id=$1 AND sequence=$2",
        )
        .bind(run_id)
        .bind(wait["sequence"].as_i64().unwrap_or(0))
        .fetch_optional(&mut **tx)
        .await?
        else {
            return Ok(None);
        };
        let timer_id = DB::string(&timer, "id")?;
        let Some(scheduled) = Self::query(DB::TIMER_SCHEDULE_SQL)
            .bind(run_id)
            .bind(&timer_id)
            .fetch_optional(&mut **tx)
            .await?
        else {
            return Ok(None);
        };
        let payload = DB::document_row(&scheduled, "payload")?;
        if payload["timer_kind"] != "condition_timeout"
            || payload["condition_wait_id"] != wait["condition_wait_id"]
        {
            return Ok(None);
        }
        Ok(Some((timer_id, DB::string(&timer, "status")? == "fired")))
    }

    pub(super) async fn condition_timeout_fired(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        wait: &Value,
    ) -> Result<bool> {
        Ok(Self::condition_timer(tx, run_id, wait)
            .await?
            .is_some_and(|(_, fired)| fired))
    }

    pub(super) async fn cancel_wait_timeout(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        wait: &Value,
        task_id: &str,
        at: DateTime<Utc>,
    ) -> Result<()> {
        let Some(timer) = Self::query("SELECT * FROM workflow_run_timers WHERE workflow_run_id=$1 AND sequence=$2 AND status='pending'")
            .bind(run_id).bind(wait["sequence"].as_i64().unwrap_or(0)).fetch_optional(&mut **tx).await? else {return Ok(());};
        let timer_id = DB::string(&timer, "id")?;
        let payload = Self::timer_cancellation_payload(tx, run_id, &timer, at).await?;
        let (kind, key) = if wait.get("condition_wait_id").is_some() {
            ("condition_timeout", "condition_wait_id")
        } else {
            ("signal_timeout", "signal_wait_id")
        };
        if payload["timer_kind"] != kind || payload[key] != wait[key] {
            return Ok(());
        }
        Self::query("UPDATE workflow_run_timers SET status='cancelled' WHERE id=$1")
            .bind(&timer_id)
            .execute(&mut **tx)
            .await?;
        let tasks = Self::query("SELECT id,payload FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='timer' AND status IN ('ready','leased')")
            .bind(run_id).fetch_all(&mut **tx).await?;
        for task in tasks {
            if DB::document_row(&task, "payload")?["timer_id"] == timer_id {
                Self::query("UPDATE workflow_tasks SET status='cancelled',lease_expires_at=NULL WHERE id=$1")
                    .bind(DB::string(&task,"id")?).execute(&mut **tx).await?;
            }
        }
        Self::append(tx, run_id, "TimerCancelled", payload, Some(task_id), None).await
    }
}
