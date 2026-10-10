//! Durable native registration identity. Fencing is not advertised by this slice.
use super::{
    Result,
    backend::Backend,
    refuse,
    store::{Store, now},
};
use axum::http::StatusCode;
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Transaction, Type};

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
        Ok(())
    }
}
