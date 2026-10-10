//! Registration authority, original-task recovery and immutable shutdown receipts.
use super::{
    Result, RuntimeError,
    backend::Backend,
    refuse,
    store::{Store, id, now},
    text,
};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Transaction, Type};
use std::sync::Arc;
use tokio::sync::Semaphore;

pub(super) fn transient_reason(error: &RuntimeError) -> Option<&'static str> {
    match error {
        RuntimeError::Database(
            sqlx::Error::PoolTimedOut
            | sqlx::Error::PoolClosed
            | sqlx::Error::Io(_)
            | sqlx::Error::Tls(_),
        ) => Some("backend_unavailable"),
        RuntimeError::Database(sqlx::Error::Database(error)) => {
            if let Some(mysql) = error.try_downcast_ref::<sqlx::mysql::MySqlDatabaseError>() {
                return match mysql.number() {
                    1205 | 1213 => Some("backend_lock_pressure"),
                    2002 | 2003 | 2006 | 2013 => Some("backend_unavailable"),
                    _ => None,
                };
            }
            let code = error.code()?;
            if matches!(code.as_ref(), "55P03" | "40P01" | "40001")
                || (error
                    .try_downcast_ref::<sqlx::sqlite::SqliteError>()
                    .is_some()
                    && code
                        .parse::<u32>()
                        .is_ok_and(|code| matches!(code & 0xff, 5 | 6)))
            {
                Some("backend_lock_pressure")
            } else if code.starts_with("08") || matches!(code.as_ref(), "57P01" | "57P02" | "57P03")
            {
                Some("backend_unavailable")
            } else {
                None
            }
        }
        _ => None,
    }
}

fn failure(status: StatusCode, reason: &'static str, worker_id: &str, token: &str) -> RuntimeError {
    RuntimeError::Protocol {
        status,
        response: json!({"reason":reason,"worker_id":worker_id,
        "registration_token":token,"retryable":false}),
    }
}

async fn repair_payload(codec: Arc<Semaphore>, task_id: String) -> Result<String> {
    let permit = codec
        .acquire_owned()
        .await
        .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "repair_codec_unavailable"))?;
    tokio::task::spawn_blocking(move || {
        let _permit = permit;
        let codec = crate::codec::ValueCodec::new()
            .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "repair_codec_unavailable"))?;
        let value = crate::codec::Value::Map(std::collections::BTreeMap::from([
            (
                "liveness_state".into(),
                crate::codec::Value::String("repair_needed".into()),
            ),
            (
                "wait_kind".into(),
                crate::codec::Value::String("workflow-task".into()),
            ),
            ("task_id".into(), crate::codec::Value::String(task_id)),
            (
                "task_type".into(),
                crate::codec::Value::String("workflow".into()),
            ),
        ]));
        codec
            .encode(&value)
            .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "repair_codec_unavailable"))
    })
    .await
    .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "repair_codec_unavailable"))?
}

pub(super) async fn token() -> Result<String> {
    // The OS entropy source may block during boot. Keep it off request executors,
    // and refuse registration rather than substitute a weaker identity.
    tokio::task::spawn_blocking(|| {
        let mut bytes = [0u8; 16];
        getrandom::fill(&mut bytes).map_err(|_| {
            refuse(
                StatusCode::SERVICE_UNAVAILABLE,
                "worker_registration_identity_unavailable",
            )
        })?;
        Ok(format!("{:032x}", u128::from_be_bytes(bytes)))
    })
    .await
    .map_err(|_| {
        refuse(
            StatusCode::SERVICE_UNAVAILABLE,
            "worker_registration_identity_unavailable",
        )
    })?
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::runtime::{Runtime, RuntimeError};
    use serde_json::json;

    #[tokio::test]
    async fn failed_incarnation_insert_rolls_back_worker_and_prior_authority() {
        let directory = tempfile::tempdir().unwrap();
        let path = directory.path().join("runtime.sqlite");
        let runtime = Runtime::open(path.to_str().unwrap(), "test-token".into())
            .await
            .unwrap();
        let definition = json!({"worker_id":"original-worker","task_queue":"original-queue",
            "runtime":"php","supported_workflow_types":["echo"],"supported_activity_types":[]});
        runtime.register(definition.clone()).await.unwrap();
        let pool = sqlx::SqlitePool::connect(&format!("sqlite://{}", path.display()))
            .await
            .unwrap();
        let before: Vec<(String,String,String,String,Option<i64>,Option<String>,String,String)> =
            sqlx::query_as("SELECT token,namespace,worker_id,status,recovered_workflow_task_count,finished_at,created_at,updated_at FROM dw_worker_registration_incarnations ORDER BY token")
                .fetch_all(&pool).await.unwrap();
        sqlx::raw_sql("CREATE TRIGGER reject_incarnation BEFORE INSERT ON dw_worker_registration_incarnations BEGIN SELECT RAISE(ABORT,'qualification storage fault'); END")
            .execute(&pool).await.unwrap();
        let mut replacement = definition;
        replacement["task_queue"] = json!("replacement-queue");
        assert!(matches!(
            runtime.register(replacement).await,
            Err(RuntimeError::Database(_))
        ));
        let after: Vec<(String,String,String,String,Option<i64>,Option<String>,String,String)> =
            sqlx::query_as("SELECT token,namespace,worker_id,status,recovered_workflow_task_count,finished_at,created_at,updated_at FROM dw_worker_registration_incarnations ORDER BY token")
                .fetch_all(&pool).await.unwrap();
        assert_eq!(after, before);
        let queue: String = sqlx::query_scalar("SELECT task_queue FROM workflow_worker_registrations WHERE namespace='default' AND worker_id='original-worker'")
            .fetch_one(&pool).await.unwrap();
        assert_eq!(queue, "original-queue");
        sqlx::raw_sql("DROP TRIGGER reject_incarnation")
            .execute(&pool)
            .await
            .unwrap();
        pool.close().await;
        runtime.close().await;
    }
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
    pub(crate) async fn deregister_fenced(
        &self,
        worker_id: &str,
        body: Value,
        codec: Arc<Semaphore>,
    ) -> Result<Value> {
        let token = text(&body, "registration_token")?;
        if token.len() != 32
            || !token
                .bytes()
                .all(|byte| byte.is_ascii_digit() || (b'a'..=b'f').contains(&byte))
        {
            return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_request"));
        }
        // One physical transaction owns the registration, tasks, repair history
        // and receipt. The backend lock also fences independent server nodes.
        let mut tx = self.begin().await?;
        let receipt = Self::query("SELECT * FROM dw_worker_registration_incarnations WHERE namespace=$1 AND worker_id=$2 AND token=$3")
            .bind(self.namespace.as_ref()).bind(worker_id).bind(token).fetch_optional(&mut *tx).await?
            .ok_or_else(|| failure(StatusCode::NOT_FOUND,"worker_registration_token_not_found",worker_id,token))?;
        // Reconcile the completed original token before inspecting any newer
        // registration or lease belonging to the same worker ID.
        if DB::string(&receipt, "status")? == "deregistered" {
            let response = Self::replay_receipt(&receipt, worker_id, token)?;
            tx.commit().await?;
            return Ok(response);
        }
        let worker = Self::query("SELECT worker_id FROM workflow_worker_registrations WHERE namespace=$1 AND worker_id=$2")
            .bind(self.namespace.as_ref()).bind(worker_id).fetch_optional(&mut *tx).await?;
        if DB::string(&receipt, "status")? != "active" || worker.is_none() {
            return Err(failure(
                StatusCode::CONFLICT,
                "worker_registration_lost_authority",
                worker_id,
                token,
            ));
        }
        Self::query("UPDATE workflow_worker_registrations SET status='superseded' WHERE namespace=$1 AND worker_id=$2")
            .bind(self.namespace.as_ref()).bind(worker_id).execute(&mut *tx).await?;
        let tasks = Self::query("SELECT * FROM workflow_tasks WHERE namespace=$1 AND lease_owner=$2 AND task_type='workflow' AND status='leased' ORDER BY available_at,id")
            .bind(self.namespace.as_ref()).bind(worker_id).fetch_all(&mut *tx).await?;
        let recovered = i64::try_from(tasks.len())
            .map_err(|_| refuse(StatusCode::CONFLICT, "task_count_exhausted"))?;
        for task in tasks {
            self.repair_abandoned_task(&mut tx, &task, codec.clone())
                .await?;
        }
        Self::query(
            "DELETE FROM workflow_worker_registrations WHERE namespace=$1 AND worker_id=$2",
        )
        .bind(self.namespace.as_ref())
        .bind(worker_id)
        .execute(&mut *tx)
        .await?;
        let timestamp = now();
        Self::query("UPDATE dw_worker_registration_incarnations SET status='deregistered',recovered_workflow_task_count=$1,finished_at=$2,updated_at=$3 WHERE token=$4")
            .bind(recovered).bind(DB::bind_time(timestamp)).bind(DB::bind_time(timestamp)).bind(token).execute(&mut *tx).await?;
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(
            json!({"worker_id":worker_id,"registration_token":token,"outcome":"deregistered","recovered_workflow_task_count":recovered}),
        )
    }

    fn replay_receipt(receipt: &DB::Row, worker_id: &str, token: &str) -> Result<Value> {
        if DB::optional_instant(receipt, "finished_at")?
            .is_none_or(|finished| finished <= now() - chrono::Duration::seconds(600))
        {
            return Err(failure(
                StatusCode::GONE,
                "worker_registration_receipt_expired",
                worker_id,
                token,
            ));
        }
        Ok(
            json!({"worker_id":worker_id,"registration_token":token,"outcome":"deregistered",
            "recovered_workflow_task_count":DB::number(receipt,"recovered_workflow_task_count")?}),
        )
    }

    async fn repair_abandoned_task(
        &self,
        tx: &mut Transaction<'_, DB>,
        task: &DB::Row,
        codec: Arc<Semaphore>,
    ) -> Result<()> {
        let task_id = DB::string(task, "id")?;
        let run_id = DB::string(task, "workflow_run_id")?;
        let run = Self::query("SELECT r.*,i.current_run_id FROM workflow_runs r JOIN workflow_instances i ON i.id=r.workflow_instance_id WHERE r.id=$1 AND r.namespace=$2")
            .bind(&run_id).bind(self.namespace.as_ref()).fetch_one(&mut **tx).await?;
        if !matches!(
            DB::string(&run, "status")?.as_str(),
            "pending" | "running" | "waiting"
        ) || DB::string(&run, "current_run_id")? != run_id
        {
            // Closed/historical work cannot be made runnable by shutdown.
            Self::query("UPDATE workflow_tasks SET lease_expires_at=$1 WHERE id=$2")
                .bind(DB::bind_time(now()))
                .bind(&task_id)
                .execute(&mut **tx)
                .await?;
            return Ok(());
        }
        let repair_count = DB::number(task, "repair_count")?
            .checked_add(1)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "repair_count_exhausted"))?;
        let command_sequence = DB::number(&run, "last_command_sequence")?
            .checked_add(1)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "command_sequence_exhausted"))?;
        let payload = repair_payload(codec, task_id.clone()).await?;
        let timestamp = now();
        let command_id = id();
        let workflow_id = DB::string(&run, "workflow_instance_id")?;
        let context = json!({"metadata":{"trigger":"expired_workflow_task_lease","task_id":task_id,
            "lease_owner":DB::optional_string(task,"lease_owner")?,"workflow_task_attempt":DB::number(task,"attempt_count")?}});
        Self::query("UPDATE workflow_tasks SET status='ready',leased_at=NULL,lease_owner=NULL,lease_expires_at=NULL,repair_count=$1,repair_available_at=NULL,last_error=NULL WHERE id=$2")
            .bind(repair_count).bind(&task_id).execute(&mut **tx).await?;
        Self::query("INSERT INTO workflow_commands(id,workflow_instance_id,workflow_run_id,requested_workflow_run_id,resolved_workflow_run_id,command_type,source,target_scope,status,outcome,workflow_type,payload_codec,payload,command_sequence,accepted_at,applied_at,context) VALUES ($1,$2,$3,$4,$5,'repair','control_plane','run','accepted','repair_dispatched',$6,'avro',$7,$8,$9,$10,$11)")
            .bind(&command_id).bind(&workflow_id).bind(&run_id).bind(&run_id).bind(&run_id).bind(DB::string(&run,"workflow_type")?)
            .bind(&payload).bind(command_sequence).bind(DB::bind_time(timestamp)).bind(DB::bind_time(timestamp)).bind(DB::document(&context))
            .execute(&mut **tx).await?;
        Self::query(
            "UPDATE workflow_runs SET last_command_sequence=$1,last_progress_at=$2 WHERE id=$3",
        )
        .bind(command_sequence)
        .bind(DB::bind_time(timestamp))
        .bind(&run_id)
        .execute(&mut **tx)
        .await?;
        let repaired = Self::query("SELECT * FROM workflow_tasks WHERE id=$1")
            .bind(&task_id)
            .fetch_one(&mut **tx)
            .await?;
        Self::append(tx,&run_id,"RepairRequested",json!({"workflow_command_id":command_id,"workflow_instance_id":workflow_id,
            "workflow_run_id":run_id,"command_type":"repair","outcome":"repair_dispatched","liveness_state":"repair_needed",
            "wait_kind":"workflow-task","task_id":task_id,"task_type":"workflow","task":Self::task_snapshot(&repaired)?,
            "command":{"id":command_id,"sequence":command_sequence,"type":"repair","target_scope":"run","requested_run_id":run_id,
                "resolved_run_id":run_id,"status":"accepted","outcome":"repair_dispatched","source":"control_plane","payload_codec":"avro","payload":payload,"context":context}}),
            Some(&task_id),Some(&command_id)).await?;
        Ok(())
    }

    pub(super) fn task_snapshot(task: &DB::Row) -> Result<Value> {
        let mut snapshot = json!({"id":DB::string(task,"id")?,"type":DB::string(task,"task_type")?,
            "status":DB::string(task,"status")?,"attempt_count":DB::number(task,"attempt_count")?,
            "repair_count":DB::number(task,"repair_count")?,"queue":DB::string(task,"queue")?});
        for field in [
            "available_at",
            "leased_at",
            "lease_expires_at",
            "repair_available_at",
        ] {
            if let Some(value) = DB::optional_instant(task, field)? {
                snapshot[field] = json!(value);
            }
        }
        if let Some(owner) = DB::optional_string(task, "lease_owner")? {
            snapshot["lease_owner"] = json!(owner);
        }
        Ok(snapshot)
    }
    pub(super) async fn retire_incarnation(
        &self,
        tx: &mut Transaction<'_, DB>,
        worker_id: &str,
    ) -> Result<()> {
        let timestamp = now();
        Self::query("UPDATE dw_worker_registration_incarnations SET status='superseded',finished_at=$1,updated_at=$2 WHERE namespace=$3 AND worker_id=$4 AND status='active'")
            .bind(DB::bind_time(timestamp)).bind(DB::bind_time(timestamp))
            .bind(self.namespace.as_ref()).bind(worker_id)
            .execute(&mut **tx).await?;
        Ok(())
    }

    pub(super) async fn rotate_incarnation(
        &self,
        tx: &mut Transaction<'_, DB>,
        worker_id: &str,
        token: &str,
    ) -> Result<()> {
        // The registration transaction holds the backend's cross-node write
        // fence. Retirement, insertion and registration upsert commit together.
        self.retire_incarnation(tx, worker_id).await?;
        let timestamp = now();
        Self::query("INSERT INTO dw_worker_registration_incarnations(token,namespace,worker_id,status,created_at,updated_at) VALUES ($1,$2,$3,'active',$4,$5)")
            .bind(token).bind(self.namespace.as_ref()).bind(worker_id)
            .bind(DB::bind_time(timestamp)).bind(DB::bind_time(timestamp))
            .execute(&mut **tx).await?;
        // Bound retirement bookkeeping during registration churn. Keep the
        // complete ten-minute interval; active identities are never selected.
        // The derived table also supports MySQL's self-table DELETE rules.
        Self::query("DELETE FROM dw_worker_registration_incarnations WHERE token IN (SELECT token FROM (SELECT token FROM dw_worker_registration_incarnations WHERE namespace=$1 AND status IN ('superseded','deregistered') AND finished_at<=$2 ORDER BY finished_at,token LIMIT 64) AS expired_incarnations)")
            .bind(self.namespace.as_ref())
            .bind(DB::bind_time(timestamp - chrono::Duration::seconds(600)))
            .execute(&mut **tx).await?;
        Ok(())
    }
}
