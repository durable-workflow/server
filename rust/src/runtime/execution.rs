//! Runtime database selection without duplicating durable transitions.
use super::{Result, postgres::PostgresStorage, refuse, sqlite, store::Store};
use axum::http::StatusCode;
use serde_json::Value;
use sqlx::{Postgres, Sqlite, postgres::PgConnectOptions};
use std::sync::Arc;

enum Storage {
    Sqlite(Store<Sqlite>),
    Postgres(Store<Postgres>),
}

#[derive(Clone)]
pub struct Runtime {
    storage: Arc<Storage>,
    pub(crate) token: Arc<str>,
}

macro_rules! delegate {
    ($name:ident($($arg:ident: $ty:ty),* $(,)?) -> $output:ty) => {
        pub(crate) async fn $name(&self, $($arg:$ty),*) -> $output {
            match &*self.storage {
                Storage::Sqlite(store) => store.$name($($arg),*).await,
                Storage::Postgres(store) => store.$name($($arg),*).await,
            }
        }
    };
}

impl Runtime {
    fn validate_token(token: &str) -> Result<()> {
        if token.trim().is_empty() {
            return Err(refuse(
                StatusCode::BAD_REQUEST,
                "invalid_development_configuration",
            ));
        }
        Ok(())
    }

    pub async fn open(database: &str, token: String) -> Result<Self> {
        Self::validate_token(&token)?;
        let store = Store::new(sqlite::open(database).await?);
        let runtime = Self {
            storage: Arc::new(Storage::Sqlite(store)),
            token: token.into(),
        };
        runtime.verify_ready().await?;
        Ok(runtime)
    }

    pub async fn open_postgres(options: PgConnectOptions, token: String) -> Result<Self> {
        Self::validate_token(&token)?;
        let store = Store::new(PostgresStorage::open(options).await?.into_pool());
        let runtime = Self {
            storage: Arc::new(Storage::Postgres(store)),
            token: token.into(),
        };
        runtime.verify_ready().await?;
        Ok(runtime)
    }

    async fn verify_ready(&self) -> Result<()> {
        if !self.schema_ready().await {
            self.close().await;
            return Err(refuse(
                StatusCode::CONFLICT,
                "incomplete_development_schema",
            ));
        }
        Ok(())
    }

    pub async fn close(&self) {
        match &*self.storage {
            Storage::Sqlite(store) => store.close().await,
            Storage::Postgres(store) => store.close().await,
        }
    }

    #[cfg(test)]
    pub(crate) fn sqlite_pool(&self) -> &sqlx::SqlitePool {
        match &*self.storage {
            Storage::Sqlite(store) => &store.pool,
            Storage::Postgres(_) => panic!("SQLite-only schema test"),
        }
    }

    delegate!(schema_ready() -> bool);
    delegate!(database_live() -> bool);
    delegate!(start(body: Value) -> Result<Value>);
    delegate!(describe(workflow_id: &str, run_id: Option<&str>) -> Result<Value>);
    delegate!(
        history(
            workflow_id: &str,
            run_id: &str,
            after_sequence: i64,
            page_size: i64,
        ) -> Result<Value>
    );
    delegate!(register(body: Value) -> Result<Value>);
    delegate!(worker_heartbeat(body: Value) -> Result<Value>);
    delegate!(deregister(worker_id: &str) -> Result<Value>);
    delegate!(poll(body: Value, kind: &'static str) -> Result<Value>);
    delegate!(heartbeat_task(task_id: &str, body: Value) -> Result<Value>);
    delegate!(task_history(task_id: &str, body: Value) -> Result<Value>);
    delegate!(complete_workflow(task_id: &str, body: Value) -> Result<Value>);
    delegate!(complete_activity(task_id: &str, body: Value) -> Result<Value>);
}
