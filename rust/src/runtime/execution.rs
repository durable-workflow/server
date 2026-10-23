//! Runtime database selection without duplicating durable transitions.
use super::{Result, mysql::MySqlStorage, postgres::PostgresStorage, refuse, sqlite, store::Store};
use axum::http::StatusCode;
use serde_json::{Value, json};
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

#[cfg(test)]
#[path = "query_codec_tests.rs"]
mod query_codec_tests;

#[cfg(test)]
#[path = "transition_codec_tests.rs"]
mod transition_codec_tests;

#[derive(Clone)]
pub struct Runtime {
    storage: Arc<Storage>,
    pub(crate) token: Arc<str>,
    pub(crate) role_tokens: [Option<Arc<str>>; 3],
    /// `DW_AUTH_DRIVER=none` and `DW_RUNTIME_CREDENTIALS_ENABLED`.
    pub(crate) auth: AuthOptions,
    scheduler: Arc<TimerScheduler>,
    signal_codec: Arc<Semaphore>,
    // Codec work done while holding the transition lock takes this permit,
    // never `signal_codec`, so a transition never queues behind reads for
    // codec capacity.
    transition_codec: Arc<Semaphore>,
    pub(super) external: Arc<super::ExternalPayloadConfig>,
    pub(super) queries: Arc<super::QueryTaskConfig>,
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
                        // Timer and schedule transactions share the cross-node lock with
                        // completion. CPU/deadline performance is unqualified.
                        let result = async { match &*storage {
                            Storage::Sqlite(store) => { store.fire_due_timers().await?; store.fire_due_schedules().await },
                            Storage::Postgres(store) => { store.fire_due_timers().await?; store.fire_due_schedules().await },
                            Storage::MySql(store) => { store.fire_due_timers().await?; store.fire_due_schedules().await },
                        }}.await;
                        let success = result.is_ok();
                        if healthy.swap(success,Ordering::Relaxed) && !success {
                            eprintln!("runtime_scheduler_storage_unavailable");
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

/// How requests authenticate beyond the configured tokens.
#[derive(Clone, Copy, Debug, Default)]
pub(crate) struct AuthOptions {
    /// `DW_AUTH_DRIVER=none`: every request is the anonymous administrator.
    pub(crate) anonymous: bool,
    /// Database runtime credentials authenticate bearer tokens.
    pub(crate) runtime_credentials: bool,
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
    pub fn with_external_payload_config(mut self, config: super::ExternalPayloadConfig) -> Self {
        self.external = Arc::new(config);
        self
    }
    pub fn with_query_task_config(mut self, config: super::QueryTaskConfig) -> Self {
        self.queries = Arc::new(config);
        self
    }
    /// The configured history page sizes (DW_HISTORY_PAGE_SIZE_DEFAULT and _MAX).
    pub(super) fn history_pages(&self) -> super::history_pages::HistoryPages {
        super::history_pages::HistoryPages {
            default: self.queries.history_page_size_default,
            max: self.queries.history_page_size_max,
        }
    }
    /// The wait of a workflow or activity poll that names none
    /// (DW_WORKER_POLL_TIMEOUT).
    pub(super) fn worker_poll_wait(&self) -> u64 {
        u64::try_from(self.queries.worker_poll_timeout_seconds).unwrap_or(0)
    }
    /// Immutable configured-token cohort. Database transitions and scheduler
    /// stay shared; configuring credentials does not mutate process state.
    pub fn with_role_tokens(
        mut self,
        worker: Option<String>,
        operator: Option<String>,
        admin: Option<String>,
    ) -> Self {
        self.role_tokens = [worker, operator, admin]
            .map(|token| token.filter(|value| !value.is_empty()).map(Arc::from));
        self
    }

    pub fn with_auth_options(mut self, anonymous: bool, runtime_credentials: bool) -> Self {
        self.auth = AuthOptions { anonymous, runtime_credentials };
        self
    }

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
        let external = super::ExternalPayloadConfig::from_env()?;
        let queries = super::QueryTaskConfig::from_env()?;
        let limit = super::memo_limits::configured_limit()?;
        let history_budget = super::history_budget::Config::from_env()?;
        let search_attribute_limit = super::search_attributes::configured_limit()?;
        let mut store = Store::new(sqlite::open(database).await?);
        store.memo_size_limit = limit;
        store.search_attribute_limit = search_attribute_limit;
        store.history_budget = history_budget;
        store.workflow_task_lease_seconds = queries.workflow_task_lease_seconds;
        store.activity_task_lease_seconds = queries.activity_task_lease_seconds;
        store.update_wait_seconds = queries.update_wait_completion_timeout_seconds;
        store.worker_stale_after_seconds = queries.worker_stale_after_seconds;
        Self::from_storage(Storage::Sqlite(store), token, external, queries).await
    }

    pub async fn open_postgres(options: PgConnectOptions, token: String) -> Result<Self> {
        Self::validate_token(&token)?;
        let external = super::ExternalPayloadConfig::from_env()?;
        let queries = super::QueryTaskConfig::from_env()?;
        let limit = super::memo_limits::configured_limit()?;
        let history_budget = super::history_budget::Config::from_env()?;
        let search_attribute_limit = super::search_attributes::configured_limit()?;
        let mut store = Store::new(PostgresStorage::open(options).await?.into_pool());
        store.memo_size_limit = limit;
        store.search_attribute_limit = search_attribute_limit;
        store.history_budget = history_budget;
        store.workflow_task_lease_seconds = queries.workflow_task_lease_seconds;
        store.activity_task_lease_seconds = queries.activity_task_lease_seconds;
        store.update_wait_seconds = queries.update_wait_completion_timeout_seconds;
        store.worker_stale_after_seconds = queries.worker_stale_after_seconds;
        Self::from_storage(Storage::Postgres(store), token, external, queries).await
    }

    pub async fn open_mysql(options: MySqlConnectOptions, token: String) -> Result<Self> {
        Self::validate_token(&token)?;
        let external = super::ExternalPayloadConfig::from_env()?;
        let queries = super::QueryTaskConfig::from_env()?;
        let limit = super::memo_limits::configured_limit()?;
        let history_budget = super::history_budget::Config::from_env()?;
        let search_attribute_limit = super::search_attributes::configured_limit()?;
        let mut store = Store::new(MySqlStorage::open(options).await?.into_pool());
        store.memo_size_limit = limit;
        store.search_attribute_limit = search_attribute_limit;
        store.history_budget = history_budget;
        store.workflow_task_lease_seconds = queries.workflow_task_lease_seconds;
        store.activity_task_lease_seconds = queries.activity_task_lease_seconds;
        store.update_wait_seconds = queries.update_wait_completion_timeout_seconds;
        store.worker_stale_after_seconds = queries.worker_stale_after_seconds;
        Self::from_storage(Storage::MySql(store), token, external, queries).await
    }

    async fn from_storage(
        storage: Storage,
        token: String,
        external: super::ExternalPayloadConfig,
        queries: super::QueryTaskConfig,
    ) -> Result<Self> {
        let runtime = Self {
            storage: Arc::new(storage),
            token: token.into(),
            role_tokens: [None, None, None],
            auth: AuthOptions::default(),
            scheduler: Arc::new(TimerScheduler::new()),
            signal_codec: Arc::new(Semaphore::new(1)),
            transition_codec: Arc::new(Semaphore::new(1)),
            external: Arc::new(external),
            queries: Arc::new(queries),
        };
        runtime.verify_ready().await?;
        if let Err(error) = runtime.initialize_namespace_registry().await {
            runtime.close().await;
            return Err(error);
        }
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
    pub(super) fn in_namespace(&self, namespace: &str) -> Self {
        if namespace == "default" {
            return self.clone();
        }
        let storage = match &*self.storage {
            Storage::Sqlite(store) => Storage::Sqlite(store.in_namespace(namespace)),
            Storage::Postgres(store) => Storage::Postgres(store.in_namespace(namespace)),
            Storage::MySql(store) => Storage::MySql(store.in_namespace(namespace)),
        };
        Self {
            storage: Arc::new(storage),
            token: self.token.clone(),
            role_tokens: self.role_tokens.clone(),
            auth: self.auth,
            scheduler: self.scheduler.clone(),
            signal_codec: self.signal_codec.clone(),
            transition_codec: self.transition_codec.clone(),
            external: self.external.clone(),
            queries: self.queries.clone(),
        }
    }
    pub(super) fn namespace(&self) -> &str {
        match &*self.storage {
            Storage::Sqlite(store) => &store.namespace,
            Storage::Postgres(store) => &store.namespace,
            Storage::MySql(store) => &store.namespace,
        }
    }
    delegate!(initialize_namespace_registry() -> Result<()>);
    delegate!(namespace_exists(name: &str) -> Result<bool>);
    delegate!(list_namespaces() -> Result<Value>);
    delegate!(describe_namespace(name: &str) -> Result<Value>);
    delegate!(operator_metrics(config: &super::operator_metrics::MetricsConfig) -> Result<Value>);
    delegate!(system_health(config: &super::operator_metrics::MetricsConfig) -> Result<(bool, Value)>);
    delegate!(operator_dashboard(config: &super::operator_metrics::MetricsConfig, bounded: bool) -> Result<Value>);
    delegate!(repair_status() -> Result<Value>);
    delegate!(retention_status(limit: i64) -> Result<Value>);
    delegate!(retention_pass(limit: i64, run_ids: Vec<String>) -> Result<(Value, bool)>);
    delegate!(activity_timeout_status(limit: i64) -> Result<Value>);
    delegate!(activity_timeout_pass(limit: i64, ids: Vec<String>) -> Result<(Value, bool)>);
    delegate!(create_namespace(body: Value) -> Result<Value>);
    delegate!(list_search_attributes() -> Result<Value>);
    delegate!(runtime_credential_principal(token: &str) -> Result<Option<super::runtime_credentials::Principal>>);
    delegate!(list_runtime_credentials() -> Result<Value>);
    delegate!(show_runtime_credential(id: &str) -> Result<Value>);
    delegate!(upsert_runtime_credential(id: &str, body: Value) -> Result<(axum::http::StatusCode, Value)>);
    delegate!(rotate_runtime_credential(id: &str, body: Value) -> Result<Value>);
    delegate!(revoke_runtime_credential(id: &str) -> Result<Value>);
    delegate!(
        storage_test_round_trip(
            local: &crate::external_payload::LocalStorage,
            reference: crate::external_payload::Reference,
            payload: Vec<u8>,
            max_bytes: u64,
            workers: Arc<Semaphore>,
        ) -> Result<()>
    );
    delegate!(create_search_attribute(body: Value) -> Result<Value>);
    delegate!(delete_search_attribute(name: &str) -> Result<Value>);
    delegate!(set_external_policy(name: &str, body: Value) -> Result<Value>);
    delegate!(update_namespace(name: &str, body: Value) -> Result<Value>);
    delegate!(delete_namespace(name: &str) -> Result<Value>);
    delegate!(
        upload_external_local(
            local: crate::external_payload::LocalStorage,
            expected: crate::external_payload::Reference,
            snapshot: crate::external_payload::Snapshot,
            expires: chrono::DateTime<chrono::Utc>,
            quota: super::external_config::Quota,
        ) -> Result<crate::external_payload::Reference>
    );
    delegate!(
        external_payload_for_fetch(
            expected: &crate::external_payload::Reference,
        ) -> Result<super::external_registry::RegisteredPayload>
    );
    delegate!(
        authorize_completion_upload(
            context: &super::activity_completion_uploads::Context,
            expected: &crate::external_payload::Reference,
        ) -> Result<Option<crate::external_payload::Reference>>
    );
    delegate!(
        upload_activity_completion(
            local: crate::external_payload::LocalStorage,
            expected: crate::external_payload::Reference,
            snapshot: crate::external_payload::Snapshot,
            expires: chrono::DateTime<chrono::Utc>,
            quota: super::external_config::Quota,
            context: super::activity_completion_uploads::Context,
            max_bytes: u64,
            retention: i64,
        ) -> Result<crate::external_payload::Reference>
    );
    delegate!(mark_external_fetched(expected: &crate::external_payload::Reference) -> Result<()>);
    delegate!(database_live() -> bool);
    /// PHP's `AvroPayloadEnvelopeResolver` for command input: absent, null or
    /// empty input is no arguments and any list or object other than an
    /// envelope is its values, both encoded here. An envelope is left as sent.
    async fn plain_arguments(&self, body: &Value) -> Result<Option<String>> {
        super::input_envelope::check(body)?;
        let arguments = match super::start_request::input(body) {
            super::start_request::Input::Envelope => return Ok(None),
            super::start_request::Input::Empty => Vec::new(),
            super::start_request::Input::Values(values) => values
                .into_iter()
                .map(|value| super::memos::from_json(value.clone()))
                .collect::<Result<_>>()
                .map_err(|_| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_workflow_arguments"))?,
        };
        let blob = super::queries::encode_arguments(self.signal_codec.clone(), crate::codec::Value::Array(arguments)).await?;
        Ok(Some(blob))
    }

    /// A signal, update or query's arguments: the plain input encoded, or the
    /// envelope's blob.
    async fn command_arguments(&self, body: &Value) -> Result<String> {
        match self.plain_arguments(body).await? {
            Some(blob) => Ok(blob),
            None => super::envelope(body, "input"),
        }
    }

    /// Refuses an envelope blob the codec cannot decode, with PHP's message.
    async fn decodable(&self, blob: &Value) -> Result<()> {
        let Some(blob) = blob.as_str().map(str::to_owned) else {
            return Ok(());
        };
        let permit = self.signal_codec.clone().acquire_owned().await
            .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "signal_codec_unavailable"))?;
        tokio::task::spawn_blocking(move || {
            let _permit = permit;
            let codec = crate::codec::ValueCodec::new()
                .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "signal_codec_unavailable"))?;
            codec.decode(&blob).map(drop).map_err(|error| super::input_envelope::undecodable(&blob, &error))
        })
        .await
        .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "signal_codec_unavailable"))?
    }

    pub(crate) async fn start(&self, body: Value, context: Option<Value>) -> Result<Value> {
        // PHP validates the request before it reads the memo or the input.
        let mut body = super::start_request::normalized(body);
        super::input_envelope::transport(&body)?;
        super::start_request::validate(&body)?;
        if body["search_attributes"].as_object().is_some_and(|fields| !fields.is_empty())
            || body["search_attributes"].as_array().is_some_and(|items| !items.is_empty())
        {
            return Err(refuse(StatusCode::NOT_IMPLEMENTED, "start_search_attributes_unavailable"));
        }
        match self.plain_arguments(&body).await? {
            Some(blob) => body["input"] = json!({"codec": "avro", "blob": blob}),
            // PHP 2.5.14 answers an undecodable start blob with a 500
            // (server#601); native refuses it as PHP's signal does.
            None => self.decodable(&body["input"]["blob"]).await?,
        }
        let memo =
            super::memos::prepare_initial(self.signal_codec.clone(), body["memo"].clone()).await?;
        let external = self.prepare_external(&body["input"]).await?;
        match &*self.storage {
            Storage::Sqlite(store) => store.start(body, memo, external, context).await,
            Storage::Postgres(store) => store.start(body, memo, external, context).await,
            Storage::MySql(store) => store.start(body, memo, external, context).await,
        }
    }
    pub(crate) async fn create_schedule(
        &self,
        body: Value,
        schedule_id: String,
        created: Value,
        paused: Value,
    ) -> Result<Value> {
        let mut definition = super::schedules::definition(&body)?;
        definition.schedule_id = Some(schedule_id);
        if definition.action.get("input").is_some_and(|input| !input.is_null()) {
            let arguments = super::envelope(&definition.action, "input")?;
            super::queries::decode(
                self.signal_codec.clone(),
                arguments,
                true,
                "invalid_schedule_arguments",
            )
            .await?;
        }
        match &*self.storage {
            Storage::Sqlite(store) => store.create_schedule(definition, created, paused).await,
            Storage::Postgres(store) => store.create_schedule(definition, created, paused).await,
            Storage::MySql(store) => store.create_schedule(definition, created, paused).await,
        }
    }
    delegate!(describe_schedule(schedule: &str) -> Result<Value>);
    delegate!(activity_result(workflow_id: &str, run_id: &str, activity_id: &str) -> Result<Value>);
    delegate!(
        run_observation(
            workflow_id: &str,
            run_id: Option<&str>,
            options: &super::run_observation::Options,
            key: &str,
        ) -> Result<Value>
    );
    delegate!(schedule_history(schedule: &str, after: i64, limit: i64) -> Result<Value>);
    delegate!(edit_schedule(schedule: &str, operation: &str, note: Option<String>, context: Value) -> Result<Value>);
    delegate!(trigger_schedule(schedule: &str, overlap: Option<String>, context: Value) -> Result<Value>);
    delegate!(update_schedule(schedule: &str, changes: super::schedules::Changes, context: Value) -> Result<Value>);
    delegate!(list_schedules(raw: Option<&str>, secret: &str) -> Result<Value>);
    delegate!(backfill_schedule(schedule: &str, range: super::schedule_backfill::Range, context: Value) -> Result<Value>);
    pub(crate) async fn signal(
        &self,
        workflow_id: &str,
        run_id: Option<&str>,
        name: &str,
        body: Value,
        context: Option<Value>,
    ) -> Result<Value> {
        let body = super::start_request::normalized(body);
        super::input_envelope::transport(&body)?;
        super::laravel_validation::operation_name(name, "signal")?;
        super::command_requests::signal(&body)?;
        let arguments = self.command_arguments(&body).await?;
        let prepared = super::store::prepare_control_arguments(
            self.signal_codec.clone(),
            name.to_owned(),
            arguments,
            "invalid_signal_arguments",
            true,
        )
        .await?;
        match &*self.storage {
            Storage::Sqlite(store) => {
                store
                    .signal(
                        workflow_id,
                        run_id,
                        name,
                        body,
                        prepared,
                        self.transition_codec.clone(),
                        context,
                    )
                    .await
            }
            Storage::Postgres(store) => {
                store
                    .signal(
                        workflow_id,
                        run_id,
                        name,
                        body,
                        prepared,
                        self.transition_codec.clone(),
                        context,
                    )
                    .await
            }
            Storage::MySql(store) => {
                store
                    .signal(
                        workflow_id,
                        run_id,
                        name,
                        body,
                        prepared,
                        self.transition_codec.clone(),
                        context,
                    )
                    .await
            }
        }
    }
    pub(crate) async fn repair_workflow(
        &self,
        workflow_id: &str,
        run_id: Option<&str>,
        body: Value,
        context: Value,
    ) -> Result<(StatusCode, Value)> {
        let codec = self.transition_codec.clone();
        match &*self.storage {
            Storage::Sqlite(store) => store.repair_workflow(workflow_id, run_id, body, context, codec).await,
            Storage::Postgres(store) => store.repair_workflow(workflow_id, run_id, body, context, codec).await,
            Storage::MySql(store) => store.repair_workflow(workflow_id, run_id, body, context, codec).await,
        }
    }

    delegate!(run_in_namespace(workflow_id: &str, run_id: &str) -> Result<bool>);
    pub(crate) async fn run_diagnostics(
        &self,
        workflow_id: &str,
        run_id: Option<&str>,
        include_payload: bool,
    ) -> Result<Value> {
        match &*self.storage {
            Storage::Sqlite(store) => store.run_diagnostics(workflow_id, run_id, include_payload, &self.queries).await,
            Storage::Postgres(store) => store.run_diagnostics(workflow_id, run_id, include_payload, &self.queries).await,
            Storage::MySql(store) => store.run_diagnostics(workflow_id, run_id, include_payload, &self.queries).await,
        }
    }
    pub(crate) async fn describe(&self, workflow_id: &str, run_id: Option<&str>) -> Result<Value> {
        let stale_after = self.queries.worker_stale_after_seconds;
        let description = match &*self.storage {
            Storage::Sqlite(store) => store.describe_with(workflow_id, run_id, Some(stale_after)).await?,
            Storage::Postgres(store) => store.describe_with(workflow_id, run_id, Some(stale_after)).await?,
            Storage::MySql(store) => store.describe_with(workflow_id, run_id, Some(stale_after)).await?,
        };
        super::previews::describe(self.signal_codec.clone(), description).await
    }
    pub(crate) async fn list_runs(&self, workflow: &str) -> Result<Value> {
        match &*self.storage {
            Storage::Sqlite(store) => store.list_runs(workflow).await,
            Storage::Postgres(store) => store.list_runs(workflow).await,
            Storage::MySql(store) => store.list_runs(workflow).await,
        }
    }
    pub(super) async fn list_workflows(
        &self,
        filter: super::visibility::VisibilityFilter,
    ) -> Result<Value> {
        match &*self.storage {
            Storage::Sqlite(store) => store.list_workflows(&filter).await,
            Storage::Postgres(store) => store.list_workflows(&filter).await,
            Storage::MySql(store) => store.list_workflows(&filter).await,
        }
    }
    pub(crate) async fn update_workflow(
        &self,
        workflow_id: &str,
        run_id: Option<&str>,
        name: &str,
        body: Value,
        context: Option<Value>,
    ) -> Result<(StatusCode, Value)> {
        let body = super::start_request::normalized(body);
        super::input_envelope::transport(&body)?;
        super::laravel_validation::operation_name(name, "update")?;
        super::command_requests::update(&body)?;
        let arguments = self.command_arguments(&body).await?;
        if arguments.len() > super::queries::ARGUMENT_BYTES {
            return Err(refuse(
                StatusCode::PAYLOAD_TOO_LARGE,
                "update_arguments_exceed_budget",
            ));
        }
        let prepared = super::store::prepare_control_arguments(
            self.signal_codec.clone(),
            name.to_owned(),
            arguments,
            "invalid_update_arguments",
            true,
        )
        .await?;
        match &*self.storage {
            Storage::Sqlite(store) => {
                store
                    .update_workflow(workflow_id, run_id, name, body, prepared, context, self.signal_codec.clone())
                    .await
            }
            Storage::Postgres(store) => {
                store
                    .update_workflow(workflow_id, run_id, name, body, prepared, context, self.signal_codec.clone())
                    .await
            }
            Storage::MySql(store) => {
                store
                    .update_workflow(workflow_id, run_id, name, body, prepared, context, self.signal_codec.clone())
                    .await
            }
        }
    }
    delegate!(query_target(workflow_id: &str, selected_run: Option<&str>) -> Result<()>);
    pub(crate) async fn query_workflow(
        &self,
        workflow_id: &str,
        run_id: Option<&str>,
        name: &str,
        body: Value,
        command: Option<Value>,
    ) -> Result<(StatusCode, Value)> {
        // `validateOperationName`, `workflowBound`, then `input` =>
        // nullable|array; other request fields are ignored, as in PHP.
        // Laravel trims strings and turns empty ones into null first.
        let body = super::start_request::normalized(body);
        super::input_envelope::transport(&body)?;
        let invalid = |field: &'static str, message: &'static str| {
            let mut error = super::laravel_validation::failure(vec![(field, vec![message])]).expect("one message");
            if let super::RuntimeError::Protocol { response, .. } = &mut error {
                response["workflow_id"] = json!(workflow_id);
            }
            error
        };
        super::laravel_validation::operation_name(name, "query")?;
        self.query_target(workflow_id, run_id).await?;
        let input = super::laravel_validation::normalized(body.get("input")).filter(|value| !value.is_null());
        if input.as_ref().is_some_and(|value| !value.is_object() && !value.is_array()) {
            return Err(invalid("input", "The input field must be an array."));
        }
        // Like PHP's AvroPayloadEnvelopeResolver, plain values are encoded
        // as the query's arguments.
        let arguments = match input {
            None => super::queries::empty_arguments(self.signal_codec.clone()).await?,
            Some(_) => self.command_arguments(&body).await?,
        };
        if arguments.len() > super::queries::ARGUMENT_BYTES {
            return Err(refuse(
                StatusCode::PAYLOAD_TOO_LARGE,
                "query_arguments_exceed_budget",
            ));
        }
        let values = super::queries::decode(
            self.signal_codec.clone(),
            arguments.clone(),
            true,
            "invalid_query_arguments",
        )
        .await?;
        match &*self.storage {
            Storage::Sqlite(store) => {
                store
                    .query_workflow(workflow_id, run_id, name, arguments, values, &self.queries, command.as_ref())
                    .await
            }
            Storage::Postgres(store) => {
                store
                    .query_workflow(workflow_id, run_id, name, arguments, values, &self.queries, command.as_ref())
                    .await
            }
            Storage::MySql(store) => {
                store
                    .query_workflow(workflow_id, run_id, name, arguments, values, &self.queries, command.as_ref())
                    .await
            }
        }
    }
    pub(crate) async fn poll_query(&self, body: Value) -> Result<Value> {
        match &*self.storage {
            Storage::Sqlite(store) => store.poll_query(body, &self.queries).await,
            Storage::Postgres(store) => store.poll_query(body, &self.queries).await,
            Storage::MySql(store) => store.poll_query(body, &self.queries).await,
        }
    }
    pub(crate) async fn query_status(&self, id: &str, owner: &str, attempt: i64) -> Result<Value> {
        match &*self.storage {
            Storage::Sqlite(store) => store.query_status(id, owner, attempt, &self.queries).await,
            Storage::Postgres(store) => store.query_status(id, owner, attempt, &self.queries).await,
            Storage::MySql(store) => store.query_status(id, owner, attempt, &self.queries).await,
        }
    }
    pub(crate) async fn complete_query(&self, task_id: &str, body: Value) -> Result<Value> {
        // Check retained decisions and live authority before codec work, then
        // repeat the same fences atomically after decoding.
        let receipt = match &*self.storage {
            Storage::Sqlite(store) => {
                store
                    .guard_query_outcome(task_id, &body, false, &self.queries)
                    .await
            }
            Storage::Postgres(store) => {
                store
                    .guard_query_outcome(task_id, &body, false, &self.queries)
                    .await
            }
            Storage::MySql(store) => {
                store
                    .guard_query_outcome(task_id, &body, false, &self.queries)
                    .await
            }
        }?;
        if let Some(receipt) = receipt {
            return Ok(receipt);
        }
        // A plain `result` without an envelope needs no decoding.
        let plain_only = body.get("result").is_some_and(|value| !value.is_null())
            && body.get("result_envelope").is_none_or(Value::is_null);
        if !plain_only {
            let result = super::envelope(&body, "result_envelope")?;
            if result.len() > super::queries::RESULT_BYTES {
                return Err(refuse(
                    StatusCode::PAYLOAD_TOO_LARGE,
                    "query_result_exceeds_budget",
                ));
            }
            super::queries::decode(
                self.signal_codec.clone(),
                result,
                false,
                "invalid_query_result",
            )
            .await?;
        }
        self.finish_query(task_id, body, false).await
    }
    pub(crate) async fn finish_query(
        &self,
        task_id: &str,
        body: Value,
        failed: bool,
    ) -> Result<Value> {
        match &*self.storage {
            Storage::Sqlite(store) => {
                store
                    .finish_query(task_id, body, failed, &self.queries)
                    .await
            }
            Storage::Postgres(store) => {
                store
                    .finish_query(task_id, body, failed, &self.queries)
                    .await
            }
            Storage::MySql(store) => {
                store
                    .finish_query(task_id, body, failed, &self.queries)
                    .await
            }
        }
    }
    delegate!(
        activity_metadata_input(
            workflow_id: &str,
            run_id: &str,
        ) -> Result<(Vec<Value>, Vec<Value>)>
    );
    /// `HistoryController::export`: one run's replay bundle.
    pub(crate) async fn history_export(&self, workflow_id: &str, run_id: &str) -> Result<Value> {
        let (mut rows, memo) = match &*self.storage {
            Storage::Sqlite(store) => store.history_export_rows(workflow_id, run_id).await?,
            Storage::Postgres(store) => store.history_export_rows(workflow_id, run_id).await?,
            Storage::MySql(store) => store.history_export_rows(workflow_id, run_id).await?,
        };
        (rows.memo, rows.memo_payload) = super::memos::export(self.signal_codec.clone(), memo).await?;
        let setting = |names: [&str; 2]| {
            names
                .iter()
                .find_map(|name| std::env::var(name).ok())
                .map(|value| value.trim().to_owned())
                .filter(|value| !value.is_empty())
        };
        let signing = setting(["DW_V2_HISTORY_EXPORT_SIGNING_KEY", "WORKFLOW_V2_HISTORY_EXPORT_SIGNING_KEY"]).map(|key| {
            super::history_export::Signing {
                key,
                key_id: setting(["DW_V2_HISTORY_EXPORT_SIGNING_KEY_ID", "WORKFLOW_V2_HISTORY_EXPORT_SIGNING_KEY_ID"]),
            }
        });
        let exported_at = super::task_queues::json_time(Some(super::store::now()));
        let exported_at = exported_at.as_str().unwrap_or_default().to_owned();
        // Decoding payload frames and hashing the bundle stay off the async workers.
        tokio::task::spawn_blocking(move || super::history_export::bundle(&rows, &exported_at, signing.as_ref()))
            .await
            .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "history_export_unavailable"))
    }
    pub(crate) async fn activities(&self, workflow_id: &str, run_id: &str) -> Result<Value> {
        // Finish every read before taking the codec permit, so a listing never
        // holds codec capacity while it waits on the database.
        let (events, executions) = self.activity_metadata_input(workflow_id, run_id).await?;
        let permit = self
            .signal_codec
            .clone()
            .acquire_owned()
            .await
            .map_err(|_| {
                refuse(
                    StatusCode::SERVICE_UNAVAILABLE,
                    "activity_metadata_unavailable",
                )
            })?;
        let workflow_id = workflow_id.to_owned();
        let run_id = run_id.to_owned();
        tokio::task::spawn_blocking(move || {
            let _permit = permit;
            json!({"workflow_id":workflow_id,"run_id":run_id,
                "activities":super::activity_metadata::project(&events,&executions)})
        })
        .await
        .map_err(|_| {
            refuse(
                StatusCode::SERVICE_UNAVAILABLE,
                "activity_metadata_unavailable",
            )
        })
    }
    delegate!(
        history(
            workflow_id: &str,
            run_id: &str,
            after_sequence: i64,
            page_size: i64,
        ) -> Result<Value>
    );
    #[cfg(test)]
    pub(crate) async fn register_unversioned(&self, body: Value) -> Result<Value> {
        self.register(body, None).await
    }
    pub(crate) async fn register(&self, body: Value, protocol: Option<&str>) -> Result<Value> {
        match &*self.storage {
            Storage::Sqlite(store) => store.register(body, &self.queries, protocol).await,
            Storage::Postgres(store) => store.register(body, &self.queries, protocol).await,
            Storage::MySql(store) => store.register(body, &self.queries, protocol).await,
        }
    }
    pub(crate) async fn worker_heartbeat(&self, body: Value, protocol: Option<&str>) -> Result<Value> {
        match &*self.storage {
            Storage::Sqlite(store) => store.worker_heartbeat(body, &self.queries, protocol).await,
            Storage::Postgres(store) => store.worker_heartbeat(body, &self.queries, protocol).await,
            Storage::MySql(store) => store.worker_heartbeat(body, &self.queries, protocol).await,
        }
    }
    pub(crate) async fn list_workers(
        &self,
        task_queue: Option<&str>,
        status: Option<&str>,
    ) -> Result<Value> {
        match &*self.storage {
            Storage::Sqlite(store) => store.list_workers(task_queue, status, &self.queries).await,
            Storage::Postgres(store) => store.list_workers(task_queue, status, &self.queries).await,
            Storage::MySql(store) => store.list_workers(task_queue, status, &self.queries).await,
        }
    }
    pub(crate) async fn list_task_queues(&self) -> Result<Value> {
        match &*self.storage {
            Storage::Sqlite(store) => store.list_task_queues(&self.queries).await,
            Storage::Postgres(store) => store.list_task_queues(&self.queries).await,
            Storage::MySql(store) => store.list_task_queues(&self.queries).await,
        }
    }
    pub(crate) async fn describe_task_queue(&self, queue: &str) -> Result<Value> {
        match &*self.storage {
            Storage::Sqlite(store) => store.describe_task_queue(queue, &self.queries).await,
            Storage::Postgres(store) => store.describe_task_queue(queue, &self.queries).await,
            Storage::MySql(store) => store.describe_task_queue(queue, &self.queries).await,
        }
    }
    pub(crate) async fn task_queue_build_ids(&self, queue: &str) -> Result<Value> {
        match &*self.storage {
            Storage::Sqlite(store) => store.task_queue_build_ids(queue, &self.queries).await,
            Storage::Postgres(store) => store.task_queue_build_ids(queue, &self.queries).await,
            Storage::MySql(store) => store.task_queue_build_ids(queue, &self.queries).await,
        }
    }
    pub(crate) async fn describe_worker(&self, worker_id: &str) -> Result<Value> {
        match &*self.storage {
            Storage::Sqlite(store) => store.describe_worker(worker_id, &self.queries).await,
            Storage::Postgres(store) => store.describe_worker(worker_id, &self.queries).await,
            Storage::MySql(store) => store.describe_worker(worker_id, &self.queries).await,
        }
    }
    delegate!(deregister(worker_id: &str) -> Result<Value>);
    pub(crate) async fn deregister_fenced(&self, worker_id: &str, body: Value) -> Result<Value> {
        let codec = self.transition_codec.clone();
        match &*self.storage {
            Storage::Sqlite(store) => store.deregister_fenced(worker_id, body, codec).await,
            Storage::Postgres(store) => store.deregister_fenced(worker_id, body, codec).await,
            Storage::MySql(store) => store.deregister_fenced(worker_id, body, codec).await,
        }
    }
    #[cfg(test)]
    delegate!(poll(body: Value, kind: &'static str, protocol_version: &str) -> Result<Value>);
    delegate!(poll_with_pages(body: Value, kind: &'static str, protocol_version: &str, pages: super::history_pages::HistoryPages, default_wait: u64) -> Result<Value>);
    delegate!(heartbeat_task(task_id: &str, body: Value) -> Result<Value>);
    pub(crate) async fn deliver_cancellation(&self, task_id: &str, body: Value, protocol: &str) -> Result<Value> {
        let codec = self.transition_codec.clone();
        match &*self.storage {
            Storage::Sqlite(store) => store.deliver_cancellation(task_id, body, protocol, codec).await,
            Storage::Postgres(store) => store.deliver_cancellation(task_id, body, protocol, codec).await,
            Storage::MySql(store) => store.deliver_cancellation(task_id, body, protocol, codec).await,
        }
    }
    delegate!(task_history(task_id: &str, body: Value, pages: super::history_pages::HistoryPages) -> Result<Value>);
    pub(crate) async fn fail_workflow_task(&self, task_id: &str, body: Value) -> Result<Value> {
        let codec = self.transition_codec.clone();
        match &*self.storage {
            Storage::Sqlite(store) => store.fail_workflow_task(task_id, body, codec).await,
            Storage::Postgres(store) => store.fail_workflow_task(task_id, body, codec).await,
            Storage::MySql(store) => store.fail_workflow_task(task_id, body, codec).await,
        }
    }
    pub(crate) async fn complete_workflow(
        &self,
        task_id: &str,
        body: Value,
        worker: Option<super::runtime_credentials::Principal>,
        protocol: Option<&str>,
    ) -> Result<Value> {
        let external = self.prepare_command_payloads(&body).await?;
        if let Some(commands) = body["commands"].as_array() {
            for command in commands
                .iter()
                .filter(|command| command["type"] == "continue_as_new")
            {
                if command
                    .get("arguments")
                    .is_some_and(|value| !value.is_null())
                {
                    super::queries::decode(
                        self.signal_codec.clone(),
                        super::envelope(command, "arguments")?,
                        true,
                        "invalid_continue_as_new_arguments",
                    )
                    .await?;
                }
            }
            for command in commands
                .iter()
                .filter(|command| command["type"] == "complete_update")
            {
                if commands.len() != 1 {
                    return Err(refuse(
                        StatusCode::UNPROCESSABLE_ENTITY,
                        "invalid_update_task_commands",
                    ));
                }
                let result = super::envelope(command, "result")?;
                if result.len() > super::queries::RESULT_BYTES {
                    return Err(refuse(
                        StatusCode::PAYLOAD_TOO_LARGE,
                        "update_result_exceeds_budget",
                    ));
                }
                super::queries::decode(
                    self.signal_codec.clone(),
                    result,
                    false,
                    "invalid_update_result",
                )
                .await?;
            }
        }
        match &*self.storage {
            Storage::Sqlite(store) => {
                store
                    .complete_workflow(task_id, body, self.transition_codec.clone(), external, worker, protocol)
                    .await
            }
            Storage::Postgres(store) => {
                store
                    .complete_workflow(task_id, body, self.transition_codec.clone(), external, worker, protocol)
                    .await
            }
            Storage::MySql(store) => {
                store
                    .complete_workflow(task_id, body, self.transition_codec.clone(), external, worker, protocol)
                    .await
            }
        }
    }
    pub(crate) async fn close_workflow(
        &self,
        command: super::cancellation::TerminalCommand,
        workflow_id: &str,
        run_id: Option<&str>,
        body: Value,
        context: Option<Value>,
    ) -> Result<(StatusCode, Value)> {
        let (reason, blob) =
            super::cancellation::prepare_reason(&body, self.signal_codec.clone()).await?;
        match &*self.storage {
            Storage::Sqlite(store) => {
                store
                    .close_workflow(command, workflow_id, run_id, reason, blob, context)
                    .await
            }
            Storage::Postgres(store) => {
                store
                    .close_workflow(command, workflow_id, run_id, reason, blob, context)
                    .await
            }
            Storage::MySql(store) => {
                store
                    .close_workflow(command, workflow_id, run_id, reason, blob, context)
                    .await
            }
        }
    }
    pub(crate) async fn request_cancellation(
        &self,
        workflow_id: &str,
        run_id: Option<&str>,
        body: Value,
        context: Option<Value>,
    ) -> Result<(StatusCode, Value)> {
        let (reason, budget) = super::cooperative::validate_request(&body)?;
        match &*self.storage {
            Storage::Sqlite(store) => {
                store
                    .request_cancellation(
                        workflow_id,
                        run_id,
                        reason,
                        budget,
                        self.transition_codec.clone(),
                        context,
                    )
                    .await
            }
            Storage::Postgres(store) => {
                store
                    .request_cancellation(
                        workflow_id,
                        run_id,
                        reason,
                        budget,
                        self.transition_codec.clone(),
                        context,
                    )
                    .await
            }
            Storage::MySql(store) => {
                store
                    .request_cancellation(
                        workflow_id,
                        run_id,
                        reason,
                        budget,
                        self.transition_codec.clone(),
                        context,
                    )
                    .await
            }
        }
    }
    pub(crate) async fn complete_activity(&self, task_id: &str, body: Value) -> Result<Value> {
        let external = self.prepare_external(&body["result"]).await?;
        match &*self.storage {
            Storage::Sqlite(store) => store.complete_activity(task_id, body, external).await,
            Storage::Postgres(store) => store.complete_activity(task_id, body, external).await,
            Storage::MySql(store) => store.complete_activity(task_id, body, external).await,
        }
    }
    delegate!(heartbeat_activity(task_id: &str, body: Value, protocol: &str) -> Result<Value>);
    delegate!(activity_status(task_id: &str, body: Value, protocol: &str) -> Result<Value>);
    delegate!(renew_activity_lease(task_id: &str, body: Value) -> Result<Value>);
    pub(crate) async fn fail_activity(&self, task_id: &str, body: Value) -> Result<Value> {
        let (failure, blob) =
            super::activity_failures::prepare_failure(&body, self.signal_codec.clone()).await?;
        match &*self.storage {
            Storage::Sqlite(store) => store.fail_activity(task_id, body, failure, blob).await,
            Storage::Postgres(store) => store.fail_activity(task_id, body, failure, blob).await,
            Storage::MySql(store) => store.fail_activity(task_id, body, failure, blob).await,
        }
    }
}
