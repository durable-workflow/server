//! Runtime database selection without duplicating durable transitions.
use super::{Result, mysql::MySqlStorage, postgres::PostgresStorage, refuse, sqlite, store::Store};
use axum::http::StatusCode;
use serde_json::Value;
use sqlx::{MySql, Postgres, Sqlite, mysql::MySqlConnectOptions, postgres::PgConnectOptions};
use std::{
    sync::{
        Arc, Mutex,
        atomic::{AtomicBool, Ordering},
    },
    time::Duration,
};
use tokio::{
    sync::{Semaphore, watch},
    task::JoinHandle,
};

enum Storage {
    Sqlite(Store<Sqlite>),
    Postgres(Store<Postgres>),
    MySql(Store<MySql>),
}

#[derive(Clone)]
pub struct Runtime {
    storage: Arc<Storage>,
    pub(crate) token: Arc<str>,
    scheduler: Arc<TimerScheduler>,
    signal_codec: Arc<Semaphore>,
}

struct TimerScheduler {
    stop: watch::Sender<bool>,
    task: Mutex<Option<JoinHandle<()>>>,
    healthy: Arc<AtomicBool>,
}

impl TimerScheduler {
    fn new() -> Self {
        let (stop, _) = watch::channel(false);
        Self {
            stop,
            task: Mutex::new(None),
            healthy: Arc::new(AtomicBool::new(true)),
        }
    }
    fn start(&self, storage: Arc<Storage>) {
        let mut stop = self.stop.subscribe();
        let healthy = self.healthy.clone();
        let task = tokio::spawn(async move {
            loop {
                tokio::select! {
                    _ = stop.changed() => break,
                    _ = tokio::time::sleep(Duration::from_millis(250)) => {
                        // One bounded batch per tick. A failed database turn is
                        // retried from durable state, without logging payloads.
                        // Timer transactions use the same cross-node lock as
                        // completion. CPU/deadline performance is unqualified.
                        let result = match &*storage {
                            Storage::Sqlite(store) => store.fire_due_timers().await,
                            Storage::Postgres(store) => store.fire_due_timers().await,
                            Storage::MySql(store) => store.fire_due_timers().await,
                        };
                        let success = result.is_ok();
                        if healthy.swap(success,Ordering::Relaxed) && !success {
                            eprintln!("timer_scheduler_storage_unavailable");
                        }
                    }
                }
            }
        });
        *self.task.lock().unwrap() = Some(task);
    }
    async fn close(&self) {
        self.stop.send_replace(true);
        let task = self.task.lock().unwrap().take();
        if let Some(task) = task {
            let _ = task.await;
        }
    }
    fn running(&self) -> bool {
        self.task
            .lock()
            .unwrap()
            .as_ref()
            .is_some_and(|task| !task.is_finished())
    }
}

impl Drop for TimerScheduler {
    fn drop(&mut self) {
        if let Some(task) = self.task.get_mut().unwrap().take() {
            task.abort();
        }
    }
}

macro_rules! delegate {
    ($name:ident($($arg:ident: $ty:ty),* $(,)?) -> $output:ty) => {
        pub(crate) async fn $name(&self, $($arg:$ty),*) -> $output {
            match &*self.storage {
                Storage::Sqlite(store) => store.$name($($arg),*).await,
                Storage::Postgres(store) => store.$name($($arg),*).await,
                Storage::MySql(store) => store.$name($($arg),*).await,
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
        Self::from_storage(Storage::Sqlite(store), token).await
    }

    pub async fn open_postgres(options: PgConnectOptions, token: String) -> Result<Self> {
        Self::validate_token(&token)?;
        let store = Store::new(PostgresStorage::open(options).await?.into_pool());
        Self::from_storage(Storage::Postgres(store), token).await
    }

    pub async fn open_mysql(options: MySqlConnectOptions, token: String) -> Result<Self> {
        Self::validate_token(&token)?;
        let store = Store::new(MySqlStorage::open(options).await?.into_pool());
        Self::from_storage(Storage::MySql(store), token).await
    }

    async fn from_storage(storage: Storage, token: String) -> Result<Self> {
        let runtime = Self {
            storage: Arc::new(storage),
            token: token.into(),
            scheduler: Arc::new(TimerScheduler::new()),
            signal_codec: Arc::new(Semaphore::new(1)),
        };
        runtime.verify_ready().await?;
        runtime.scheduler.start(runtime.storage.clone());
        Ok(runtime)
    }

    async fn verify_ready(&self) -> Result<()> {
        if !self.storage_ready().await {
            self.close().await;
            return Err(refuse(
                StatusCode::CONFLICT,
                "incomplete_development_schema",
            ));
        }
        Ok(())
    }

    pub async fn close(&self) {
        self.scheduler.close().await;
        match &*self.storage {
            Storage::Sqlite(store) => store.close().await,
            Storage::Postgres(store) => store.close().await,
            Storage::MySql(store) => store.close().await,
        }
    }

    #[cfg(test)]
    pub(crate) fn sqlite_pool(&self) -> &sqlx::SqlitePool {
        match &*self.storage {
            Storage::Sqlite(store) => &store.pool,
            Storage::Postgres(_) | Storage::MySql(_) => panic!("SQLite-only schema test"),
        }
    }

    pub(crate) async fn schema_ready(&self) -> bool {
        if !self.scheduler.running() || !self.scheduler.healthy.load(Ordering::Relaxed) {
            return false;
        }
        self.storage_ready().await
    }
    async fn storage_ready(&self) -> bool {
        match &*self.storage {
            Storage::Sqlite(store) => store.schema_ready().await,
            Storage::Postgres(store) => store.schema_ready().await,
            Storage::MySql(store) => store.schema_ready().await,
        }
    }
    delegate!(database_live() -> bool);
    delegate!(start(body: Value) -> Result<Value>);
    pub(crate) async fn signal(
        &self,
        workflow_id: &str,
        run_id: Option<&str>,
        name: &str,
        body: Value,
    ) -> Result<Value> {
        let arguments = super::envelope(&body, "input")?;
        let prepared =
            super::store::prepare_signal(self.signal_codec.clone(), name.to_owned(), arguments)
                .await?;
        match &*self.storage {
            Storage::Sqlite(store) => {
                store
                    .signal(workflow_id, run_id, name, body, prepared)
                    .await
            }
            Storage::Postgres(store) => {
                store
                    .signal(workflow_id, run_id, name, body, prepared)
                    .await
            }
            Storage::MySql(store) => {
                store
                    .signal(workflow_id, run_id, name, body, prepared)
                    .await
            }
        }
    }
    delegate!(describe(workflow_id: &str, run_id: Option<&str>) -> Result<Value>);
    pub(crate) async fn query_workflow(
        &self,
        workflow_id: &str,
        run_id: Option<&str>,
        name: &str,
        body: Value,
    ) -> Result<(StatusCode, Value)> {
        if body.as_object().is_none_or(|m| m.len() != 1) {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "unsupported_request_field",
            ));
        }
        let arguments = super::envelope(&body, "input")?;
        if arguments.len() > super::queries::ARGUMENT_BYTES {
            return Err(refuse(
                StatusCode::PAYLOAD_TOO_LARGE,
                "query_arguments_exceed_budget",
            ));
        }
        let values =
            super::queries::decode(self.signal_codec.clone(), arguments.clone(), true).await?;
        match &*self.storage {
            Storage::Sqlite(store) => {
                store
                    .query_workflow(workflow_id, run_id, name, arguments, values)
                    .await
            }
            Storage::Postgres(store) => {
                store
                    .query_workflow(workflow_id, run_id, name, arguments, values)
                    .await
            }
            Storage::MySql(store) => {
                store
                    .query_workflow(workflow_id, run_id, name, arguments, values)
                    .await
            }
        }
    }
    delegate!(poll_query(body: Value) -> Result<Value>);
    pub(crate) async fn complete_query(&self, task_id: &str, body: Value) -> Result<Value> {
        let result = super::envelope(&body, "result_envelope")?;
        if result.len() > super::queries::RESULT_BYTES {
            return Err(refuse(
                StatusCode::PAYLOAD_TOO_LARGE,
                "query_result_exceeds_budget",
            ));
        }
        super::queries::decode(self.signal_codec.clone(), result, false).await?;
        self.finish_query(task_id, body, false).await
    }
    delegate!(finish_query(task_id: &str, body: Value, failed: bool) -> Result<Value>);
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
    pub(crate) async fn complete_workflow(&self, task_id: &str, body: Value) -> Result<Value> {
        match &*self.storage {
            Storage::Sqlite(store) => {
                store
                    .complete_workflow(task_id, body, self.signal_codec.clone())
                    .await
            }
            Storage::Postgres(store) => {
                store
                    .complete_workflow(task_id, body, self.signal_codec.clone())
                    .await
            }
            Storage::MySql(store) => {
                store
                    .complete_workflow(task_id, body, self.signal_codec.clone())
                    .await
            }
        }
    }
    delegate!(complete_activity(task_id: &str, body: Value) -> Result<Value>);
}
