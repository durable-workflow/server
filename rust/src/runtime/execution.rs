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

#[cfg(test)]
#[path = "continuation_atomic_tests.rs"]
pub(super) mod continuation_atomic_tests;

#[derive(Clone)]
pub struct Runtime {
    storage: Arc<Storage>,
    pub(crate) token: Arc<str>,
    pub(crate) role_tokens: [Option<Arc<str>>; 3],
    /// `DW_METRICS_TOKEN`: the scrape-only credential for `GET /metrics`
    /// (Server #534). It is not a role token, so it never changes the legacy
    /// token's access.
    pub(crate) metrics_token: Option<Arc<str>>,
    /// `DW_AUTH_DRIVER=none` and `DW_RUNTIME_CREDENTIALS_ENABLED`.
    pub(crate) auth: AuthOptions,
    scheduler: Arc<TimerScheduler>,
    pub(super) signal_codec: Arc<Semaphore>,
    // Codec work done while holding the transition lock takes this permit,
    // never `signal_codec`, so a transition never queues behind reads for
    // codec capacity.
    pub(super) transition_codec: Arc<Semaphore>,
    pub(super) external: Arc<super::ExternalPayloadConfig>,
    pub(super) queries: Arc<super::QueryTaskConfig>,
    pub(super) offer_keys: Option<Arc<super::task_offer_seal::KeyRing>>,
    offer_window: Arc<super::task_offer_key_usage::OfferWindow>,
    /// The package's signal, update and payload ceilings (DW_V2_LIMIT_*).
    pub(super) structural: super::StructuralLimits,
}

struct TimerScheduler {
    stop: watch::Sender<bool>,
    task: Mutex<Option<JoinHandle<()>>>,
    /// The maintenance loop's retention, apart from the ticker so a long
    /// prune never delays timers (Server #633).
    retention: Mutex<Option<JoinHandle<()>>>,
    offers: Mutex<Option<JoinHandle<()>>>,
    healthy: Arc<AtomicBool>,
    /// Whether the ticker also runs the repair pass (`maintenance_repair_enabled`).
    repair: Arc<AtomicBool>,
}

/// Whether the ticker runs the repair pass, as PHP's separate `scheduler`
/// container does. A PHP server container never repairs on its own, so the
/// native binary takes that role only when asked: `DW_RUST_MAINTENANCE_LOOP=1`
/// (the native image sets it, having no separate scheduler).
/// `DW_V2_MATCHING_ROLE_QUEUE_WAKE=false` or `0` still turns it off, as it
/// turns off PHP's.
fn maintenance_repair_enabled() -> bool {
    matches!(std::env::var("DW_RUST_MAINTENANCE_LOOP").as_deref(), Ok("1" | "true"))
        && !matches!(std::env::var("DW_V2_MATCHING_ROLE_QUEUE_WAKE").as_deref(), Ok("false" | "0"))
}

impl TimerScheduler {
    fn new() -> Self {
        let (stop, _) = watch::channel(false);
        Self {
            stop,
            task: Mutex::new(None),
            retention: Mutex::new(None),
            offers: Mutex::new(None),
            healthy: Arc::new(AtomicBool::new(true)),
            repair: Arc::new(AtomicBool::new(maintenance_repair_enabled())),
        }
    }
    fn start(&self, storage: Arc<Storage>) {
        // Under storage pressure no maintenance pass starts (Server #951): no
        // timer, schedule, repair, timeout or retention turn adds writes.
        let admission = match &*storage {
            Storage::Sqlite(store) => store.storage_admission.clone(),
            Storage::Postgres(store) => store.storage_admission.clone(),
            Storage::MySql(store) => store.storage_admission.clone(),
        };
        let retention_admission = admission.clone();
        // With the maintenance loop on, closed runs past their namespace's
        // retention are pruned every minute, whatever workers are connected.
        let mut retention_stop = self.stop.subscribe();
        let retention_enabled = self.repair.clone();
        let retention_storage = storage.clone();
        let retention = tokio::spawn(async move {
            let interval = Duration::from_secs(super::retention::MAINTENANCE_INTERVAL_SECONDS);
            loop {
                tokio::select! {
                    _ = retention_stop.changed() => break,
                    _ = tokio::time::sleep(interval) => {
                        if !retention_enabled.load(Ordering::Relaxed) || !retention_admission.snapshot().accepts_new_work() {
                            continue;
                        }
                        let result = match &*retention_storage {
                            Storage::Sqlite(store) => store.retention_maintenance().await,
                            Storage::Postgres(store) => store.retention_maintenance().await,
                            Storage::MySql(store) => store.retention_maintenance().await,
                        };
                        if let Err(error) = result {
                            eprintln!("retention_maintenance_failed: {error}");
                        }
                    }
                }
            }
        });
        *self.retention.lock().unwrap() = Some(retention);
        let mut stop = self.stop.subscribe();
        let healthy = self.healthy.clone();
        let repair = self.repair.clone();
        let task = tokio::spawn(async move {
            loop {
                tokio::select! {
                    _ = stop.changed() => break,
                    _ = tokio::time::sleep(Duration::from_millis(250)) => {
                        if !admission.snapshot().accepts_new_work() {
                            continue;
                        }
                        // One bounded batch per tick. A failed database turn is
                        // retried from durable state, without logging payloads.
                        // Timer and schedule transactions share the cross-node lock with
                        // completion. CPU/deadline performance is unqualified.
                        // Then PHP's maintenance loop (`workflow:v2:repair-pass`),
                        // at most once per repair throttle: run deadlines, task
                        // repair, activity and service-call timeouts.
                        let pass = repair.load(Ordering::Relaxed)
                            .then(|| super::repair_pass::PassRequest { respect_throttle: true, ..Default::default() });
                        let result: Result<()> = async { match &*storage {
                            Storage::Sqlite(store) => { store.fire_due_timers().await?; store.fire_due_schedules().await?; if let Some(pass) = &pass { store.repair_pass(pass).await?; } Ok(()) },
                            Storage::Postgres(store) => { store.fire_due_timers().await?; store.fire_due_schedules().await?; if let Some(pass) = &pass { store.repair_pass(pass).await?; } Ok(()) },
                            Storage::MySql(store) => { store.fire_due_timers().await?; store.fire_due_schedules().await?; if let Some(pass) = &pass { store.repair_pass(pass).await?; } Ok(()) },
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
    fn start_offers(&self, storage: Arc<Storage>, keys: Arc<super::task_offer_seal::KeyRing>,
        window: Arc<super::task_offer_key_usage::OfferWindow>,
    ) {
        let mut stop = self.stop.subscribe();
        let task = tokio::spawn(async move {
            let mut logged = None;
            loop {
                tokio::select! {
                    _ = stop.changed() => break,
                    _ = tokio::time::sleep(Duration::from_secs(10)) => {
                        let next = refresh_offer_window(&storage, &keys).await;
                        window.replace(logged_offer_window(next, &mut logged));
                    }
                }
            }
        });
        *self.offers.lock().unwrap() = Some(task);
    }

    async fn close(&self) {
        self.stop.send_replace(true);
        let task = self.task.lock().unwrap().take();
        if let Some(task) = task {
            let _ = task.await;
        }
        let retention = self.retention.lock().unwrap().take();
        if let Some(retention) = retention {
            let _ = retention.await;
        }
        let offers = self.offers.lock().unwrap().take();
        if let Some(offers) = offers {
            let _ = offers.await;
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
        if let Some(task) = self.offers.get_mut().unwrap().take() {
            task.abort();
        }
    }
}

/// A bounded, content-free classification of a failed issuance refresh:
/// a static refusal reason or the storage error kind, never Display text.
fn offer_refresh_failure(error: &super::RuntimeError) -> &'static str {
    match error {
        super::RuntimeError::Refused { reason, .. } => reason,
        super::RuntimeError::Database(_) => "database",
        super::RuntimeError::Io(_) => "io",
        super::RuntimeError::Json(_) => "json",
        super::RuntimeError::Migration(_) => "migration",
        super::RuntimeError::Protocol { .. } => "protocol",
    }
}

/// Keep a refreshed window, or suspend issuance and log a failed refresh at
/// warning level. A repeated identical failure is logged once until a
/// refresh succeeds or the failure changes.
fn logged_offer_window(
    refreshed: Result<Option<super::task_offer_key_usage::IssuanceWindow>>,
    logged: &mut Option<&'static str>,
) -> Option<super::task_offer_key_usage::IssuanceWindow> {
    match refreshed {
        Ok(window) => {
            if window.is_some() {
                *logged = None;
            }
            window
        }
        Err(error) => {
            let reason = offer_refresh_failure(&error);
            if *logged != Some(reason) {
                eprintln!("{}", json!({"event":"task_offer_issuance_refresh_failed","level":"warning","reason":reason}));
                *logged = Some(reason);
            }
            None
        }
    }
}

async fn refresh_offer_window(storage: &Storage, keys: &super::task_offer_seal::KeyRing)
    -> Result<Option<super::task_offer_key_usage::IssuanceWindow>>
{
    match storage {
        Storage::Sqlite(store) if store.storage_admission.snapshot().accepts_new_work() =>
            store.reserve_task_offer_issuance(keys).await.map(Some),
        Storage::Postgres(store) if store.storage_admission.snapshot().accepts_new_work() =>
            store.reserve_task_offer_issuance(keys).await.map(Some),
        Storage::MySql(store) if store.storage_admission.snapshot().accepts_new_work() =>
            store.reserve_task_offer_issuance(keys).await.map(Some),
        _ => Ok(None),
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
    pub fn with_structural_limits(mut self, limits: super::StructuralLimits) -> Self {
        self.structural = limits;
        self
    }
    /// Allow new ordered waits on workflow tasks claimed by this runtime.
    /// Existing marked waits remain replayable with the setting disabled.
    pub fn with_ordered_condition_wait_authoring(mut self, enabled: bool) -> Self {
        let storage = match &*self.storage {
            Storage::Sqlite(store) => Storage::Sqlite(store.with_ordered_condition_wait_authoring(enabled)),
            Storage::Postgres(store) => Storage::Postgres(store.with_ordered_condition_wait_authoring(enabled)),
            Storage::MySql(store) => Storage::MySql(store.with_ordered_condition_wait_authoring(enabled)),
        };
        self.storage = Arc::new(storage);
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

    /// The scrape-only `metrics` credential (`DW_METRICS_TOKEN`, Server #534).
    pub fn with_metrics_token(mut self, token: Option<String>) -> Self {
        self.metrics_token = token.filter(|value| !value.is_empty()).map(Arc::from);
        self
    }

    /// Replaces the storage admission settings (Server #951) for this
    /// runtime's request guard, polls and maintenance loop.
    pub fn with_storage_admission(self, settings: super::storage_admission::Settings) -> Self {
        self.storage_admission().set(settings);
        self
    }

    /// Replaces the long-poll capacity settings (Server #982) for every
    /// namespace's polls on this node.
    pub fn with_poll_capacity(self, settings: super::poll_capacity::Settings) -> Self {
        match &*self.storage {
            Storage::Sqlite(store) => store.poll_capacity.set(settings, store.pool.options().get_max_connections()),
            Storage::Postgres(store) => store.poll_capacity.set(settings, store.pool.options().get_max_connections()),
            Storage::MySql(store) => store.poll_capacity.set(settings, store.pool.options().get_max_connections()),
        }
        self
    }

    pub(super) fn storage_admission(&self) -> Arc<super::storage_admission::StorageAdmission> {
        match &*self.storage {
            Storage::Sqlite(store) => store.storage_admission.clone(),
            Storage::Postgres(store) => store.storage_admission.clone(),
            Storage::MySql(store) => store.storage_admission.clone(),
        }
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

    /// Turns the maintenance loop's repair pass on or off for this runtime,
    /// as `DW_V2_MATCHING_ROLE_QUEUE_WAKE` does at startup. Timers, schedules
    /// and deadlines still fire.
    pub fn set_maintenance_repair(&self, enabled: bool) {
        self.scheduler.repair.store(enabled, Ordering::Relaxed);
    }

    /// One maintenance retention turn over every namespace, as the
    /// maintenance loop runs each minute (Server #633).
    pub async fn retention_maintenance(&self) -> Result<()> {
        match &*self.storage {
            Storage::Sqlite(store) => store.retention_maintenance().await,
            Storage::Postgres(store) => store.retention_maintenance().await,
            Storage::MySql(store) => store.retention_maintenance().await,
        }
    }

    fn configured_offer_keys() -> Result<Option<Arc<super::task_offer_seal::KeyRing>>> {
        super::task_offer_seal::KeyRing::from_env()
            .map(|keys| keys.map(Arc::new))
            .map_err(|error| refuse(StatusCode::SERVICE_UNAVAILABLE, error.reason()))
    }

    pub async fn open(database: &str, token: String) -> Result<Self> {
        Self::validate_token(&token)?;
        let offer_keys = Self::configured_offer_keys()?;
        let external = super::ExternalPayloadConfig::from_env()?;
        let queries = super::QueryTaskConfig::from_env()?;
        let poll_sweeps = super::poll_sweeps::Config::from_env()?;
        let limit = super::memo_limits::configured_limit()?;
        let structural = super::StructuralLimits::from_env()?;
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
        store.worker_registration_retention_seconds = queries.worker_registration_retention_seconds;
        store.workflow_task_recovery_scan_limit = queries.workflow_task_recovery_scan_limit;
        store.poll_sweep_config = poll_sweeps;
        store.storage_admission = Arc::new(super::storage_admission::StorageAdmission::new(super::storage_admission::Settings::from_env()));
        store.poll_capacity = Arc::new(super::poll_capacity::PollCapacity::new(
            super::poll_capacity::Settings::from_env(),
            store.pool.options().get_max_connections(),
        ));
        Self::from_storage(Storage::Sqlite(store), token, external, queries, structural, offer_keys).await
    }

    pub async fn open_postgres(options: PgConnectOptions, token: String) -> Result<Self> {
        Self::validate_token(&token)?;
        let offer_keys = Self::configured_offer_keys()?;
        let external = super::ExternalPayloadConfig::from_env()?;
        let queries = super::QueryTaskConfig::from_env()?;
        let poll_sweeps = super::poll_sweeps::Config::from_env()?;
        let limit = super::memo_limits::configured_limit()?;
        let structural = super::StructuralLimits::from_env()?;
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
        store.worker_registration_retention_seconds = queries.worker_registration_retention_seconds;
        store.workflow_task_recovery_scan_limit = queries.workflow_task_recovery_scan_limit;
        store.poll_sweep_config = poll_sweeps;
        store.storage_admission = Arc::new(super::storage_admission::StorageAdmission::new(super::storage_admission::Settings::from_env()));
        store.poll_capacity = Arc::new(super::poll_capacity::PollCapacity::new(
            super::poll_capacity::Settings::from_env(),
            store.pool.options().get_max_connections(),
        ));
        Self::from_storage(Storage::Postgres(store), token, external, queries, structural, offer_keys).await
    }

    pub async fn open_mysql(options: MySqlConnectOptions, token: String) -> Result<Self> {
        Self::validate_token(&token)?;
        let offer_keys = Self::configured_offer_keys()?;
        let external = super::ExternalPayloadConfig::from_env()?;
        let queries = super::QueryTaskConfig::from_env()?;
        let poll_sweeps = super::poll_sweeps::Config::from_env()?;
        let limit = super::memo_limits::configured_limit()?;
        let structural = super::StructuralLimits::from_env()?;
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
        store.worker_registration_retention_seconds = queries.worker_registration_retention_seconds;
        store.workflow_task_recovery_scan_limit = queries.workflow_task_recovery_scan_limit;
        store.poll_sweep_config = poll_sweeps;
        store.storage_admission = Arc::new(super::storage_admission::StorageAdmission::new(super::storage_admission::Settings::from_env()));
        store.poll_capacity = Arc::new(super::poll_capacity::PollCapacity::new(
            super::poll_capacity::Settings::from_env(),
            store.pool.options().get_max_connections(),
        ));
        Self::from_storage(Storage::MySql(store), token, external, queries, structural, offer_keys).await
    }

    async fn from_storage(
        storage: Storage,
        token: String,
        external: super::ExternalPayloadConfig,
        queries: super::QueryTaskConfig,
        structural: super::StructuralLimits,
        offer_keys: Option<Arc<super::task_offer_seal::KeyRing>>,
    ) -> Result<Self> {
        let runtime = Self {
            storage: Arc::new(storage),
            token: token.into(),
            role_tokens: [None, None, None],
            metrics_token: None,
            auth: AuthOptions::default(),
            scheduler: Arc::new(TimerScheduler::new()),
            signal_codec: Arc::new(Semaphore::new(1)),
            transition_codec: Arc::new(Semaphore::new(1)),
            external: Arc::new(external),
            queries: Arc::new(queries),
            offer_keys,
            offer_window: Default::default(),
            structural,
        };
        runtime.verify_ready().await?;
        let ring = runtime.offer_keys.as_deref();
        let keys = match &*runtime.storage {
            Storage::Sqlite(store) => store.preflight_task_offer_keys(ring).await,
            Storage::Postgres(store) => store.preflight_task_offer_keys(ring).await,
            Storage::MySql(store) => store.preflight_task_offer_keys(ring).await,
        };
        match keys {
            // Durable obligations name a read key this process lacks. Start
            // and keep legacy routes serving, but open no issuance window, so
            // registration advertises no offers; acceptance and replay that
            // need the absent key are refused as retryable.
            Err(error) if super::task_offer_key_usage::is_missing_read_key(&error) => {
                eprintln!("{}", json!({"event":"task_offer_required_read_key_missing","level":"warning",
                    "issuance":"suspended"}));
            }
            Err(error) => {
                runtime.close().await;
                return Err(error);
            }
            Ok(()) => {}
        }
        if let Err(error) = runtime.initialize_namespace_registry().await {
            runtime.close().await;
            return Err(error);
        }
        runtime.scheduler.start(runtime.storage.clone());
        if let Some(keys) = runtime.offer_keys.as_ref() {
            let refreshed = refresh_offer_window(&runtime.storage, keys).await;
            runtime.offer_window.replace(logged_offer_window(refreshed, &mut None));
            runtime.scheduler.start_offers(runtime.storage.clone(), keys.clone(), runtime.offer_window.clone());
        }

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
    pub(super) fn sqlite_store(&self) -> &super::store::Store<sqlx::Sqlite> {
        match &*self.storage {
            Storage::Sqlite(store) => store,
            Storage::Postgres(_) | Storage::MySql(_) => panic!("SQLite-only store test"),
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
            metrics_token: self.metrics_token.clone(),
            auth: self.auth,
            scheduler: self.scheduler.clone(),
            signal_codec: self.signal_codec.clone(),
            transition_codec: self.transition_codec.clone(),
            external: self.external.clone(),
            queries: self.queries.clone(),
            offer_keys: self.offer_keys.clone(),
            offer_window: self.offer_window.clone(),
            structural: self.structural,
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
    delegate!(cluster_structural_limits(config: &super::operator_metrics::MetricsConfig) -> Result<Value>);
    delegate!(system_health(config: &super::operator_metrics::MetricsConfig) -> Result<(bool, Value)>);
    delegate!(operator_dashboard(config: &super::operator_metrics::MetricsConfig, bounded: bool, types: Option<&[String]>) -> Result<Value>);
    delegate!(repair_status() -> Result<Value>);
    delegate!(repair_pass(request: &super::repair_pass::PassRequest) -> Result<Value>);
    delegate!(system_metrics() -> Result<Value>);
    delegate!(prometheus_metrics() -> Result<Value>);
    delegate!(prometheus_exposition() -> Result<String>);
    delegate!(payload_cleanup_status(config: &super::operator_metrics::MetricsConfig) -> Result<Value>);
    /// Reclaim each registered object through its original storage binding.
    pub(crate) async fn payload_cleanup_pass(&self, limit: i64) -> Result<Value> {
        let retention = self.external.abandoned_seconds;
        match &*self.storage {
            Storage::Sqlite(store) => store.payload_cleanup_pass(limit, retention).await,
            Storage::Postgres(store) => store.payload_cleanup_pass(limit, retention).await,
            Storage::MySql(store) => store.payload_cleanup_pass(limit, retention).await,
        }
    }
    delegate!(change_backup_hold(action: super::backup_hold::Action, request: &super::backup_hold::Request) -> Result<Value>);
    delegate!(backup_hold_status(owner: Option<&str>) -> Result<Value>);
    delegate!(claimed_task_signal(task_id: &str, body: Value) -> Result<Option<(String, String, String)>>);
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
        ) -> Result<std::result::Result<(), super::external_registry::StorageStepFailure>>
    );
    delegate!(create_search_attribute(body: Value) -> Result<Value>);
    delegate!(delete_search_attribute(name: &str) -> Result<Value>);
    delegate!(set_external_policy(name: &str, body: Value) -> Result<Value>);
    delegate!(update_namespace(name: &str, body: Value) -> Result<Value>);
    delegate!(retention_plan(name: &str, request: super::retention_plan::PlanRequest) -> Result<Value>);
    delegate!(apply_retention_plan(name: &str, request: super::retention_plan::ApplyRequest) -> Result<Value>);
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
        upload_external_transport(
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
        external_payload_storage(
            expected: &crate::external_payload::Reference,
            uri: &str,
        ) -> Result<crate::external_payload::LocalStorage>
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
        self.plain_arguments_or(body, || refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_workflow_arguments")).await
    }

    /// The plain input in document order (`super::plain_input`), or
    /// `refusal` where PHP's serializer fails with `invalid_map_key`.
    async fn plain_arguments_or(
        &self,
        body: &Value,
        refusal: impl FnOnce() -> super::RuntimeError,
    ) -> Result<Option<String>> {
        super::input_envelope::check(body)?;
        let arguments = match super::start_request::input(body) {
            super::start_request::Input::Envelope => return Ok(None),
            super::start_request::Input::Empty => Vec::new(),
            super::start_request::Input::Values(values) => match values.into_iter().map(super::plain_input::ordered).collect() {
                Some(arguments) => arguments,
                None => return Err(refusal()),
            },
        };
        let blob = super::queries::encode_ordered_arguments(
            self.signal_codec.clone(),
            crate::codec::OrderedValue::Array(arguments),
        )
        .await?;
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

    /// `resolveWorkflowTaskCommandPayloadReferences`: each command payload
    /// must decode as one Avro value, checked in command and field order.
    async fn check_command_payloads(&self, body: &Value) -> Result<()> {
        use super::command_payloads::{Check, UNDECODABLE, invalid};
        let checks = super::command_payloads::checks(body);
        if checks.is_empty() {
            return Ok(());
        }
        // Completion work uses the transition codec, never read capacity.
        // The permit is released before the completion takes its own.
        let permit = self.transition_codec.clone().acquire_owned().await
            .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "signal_codec_unavailable"))?;
        tokio::task::spawn_blocking(move || {
            let _permit = permit;
            let codec = crate::codec::ValueCodec::new()
                .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "signal_codec_unavailable"))?;
            for check in checks {
                match check {
                    Check::Decode { field, blob } if codec.decode(&blob).is_err() => return Err(invalid(&field, UNDECODABLE)),
                    Check::Decode { .. } => {}
                    Check::Refuse(error) => return Err(error),
                }
            }
            Ok(())
        })
        .await
        .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "signal_codec_unavailable"))?
    }

    /// `AvroPayloadEnvelopeResolver::assertSerializedPayload`'s test: whether
    /// `blob` is one complete Avro value, decoded under the transition codec.
    pub(super) async fn avro_value_decodes(&self, blob: String) -> Result<bool> {
        let permit = self.transition_codec.clone().acquire_owned().await
            .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "signal_codec_unavailable"))?;
        tokio::task::spawn_blocking(move || {
            let _permit = permit;
            let codec = crate::codec::ValueCodec::new()
                .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "signal_codec_unavailable"))?;
            Ok(codec.decode(&blob).is_ok())
        })
        .await
        .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "signal_codec_unavailable"))?
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
        self.start_command(body, context, false).await
    }
    /// A bridge adapter's start: the store decides the draining queue and the
    /// instance reservation under the transition lock (`Store::bridge_start`).
    pub(crate) async fn bridge_start(&self, body: Value, context: Value) -> Result<Value> {
        self.start_command(body, Some(context), true).await
    }
    async fn start_command(&self, body: Value, context: Option<Value>, bridge: bool) -> Result<Value> {
        let (body, memo, external) = self.prepare_start(body, bridge).await?;
        match (&*self.storage, bridge) {
            (Storage::Sqlite(store), false) => store.start(body, memo, external, context).await,
            (Storage::Postgres(store), false) => store.start(body, memo, external, context).await,
            (Storage::MySql(store), false) => store.start(body, memo, external, context).await,
            (Storage::Sqlite(store), true) => store.bridge_start(body, memo, external, context).await,
            (Storage::Postgres(store), true) => store.bridge_start(body, memo, external, context).await,
            (Storage::MySql(store), true) => store.bridge_start(body, memo, external, context).await,
        }
    }
    /// A start request validated and its input, memo and external reference
    /// prepared, as the start route prepares them before storage.
    async fn prepare_start(
        &self,
        body: Value,
        bridge: bool,
    ) -> Result<(Value, super::memos::PreparedMemo, Option<super::external_resolution::PreparedExternal>)> {
        // PHP validates the request before it reads the memo or the input.
        let mut body = super::start_request::normalized(body);
        super::input_envelope::transport(&body)?;
        super::start_request::validate(&body)?;
        // Core's start reports the serializer's `invalid_map_key` under
        // `workflow_type`, as the controller reports Core's LogicException.
        let plain = if bridge {
            self.plain_arguments(&body).await?
        } else {
            self.plain_arguments_or(&body, || {
                super::laravel_validation::failure_messages(vec![(
                    "workflow_type".to_owned(),
                    vec![super::plain_input::INVALID_MAP_KEY.to_owned()],
                )])
                .expect("one message")
            })
            .await?
        };
        match plain {
            Some(blob) => body["input"] = json!({"codec": "avro", "blob": blob}),
            // PHP 2.5.14 answers an undecodable start blob with a 500
            // (server#601); native refuses it as PHP's signal does.
            None => self.decodable(&body["input"]["blob"]).await?,
        }
        let memo =
            super::memos::prepare_initial(self.signal_codec.clone(), body["memo"].clone()).await?;
        let external = self.prepare_external(&body["input"]).await?;
        Ok((body, memo, external))
    }
    /// `ActivityController::start`: validates like PHP, then starts the
    /// activity in its own host run.
    pub(crate) async fn start_standalone_activity(&self, body: Value, context: Value) -> Result<Value> {
        let mut body = super::laravel_rules::normalize(&body);
        let request = super::standalone_activities::start_request(&body)?;
        // `AvroPayloadEnvelopeResolver::resolve`: no input stores none; plain
        // values are encoded; an external reference is retained.
        let input = body.get("input").is_some_and(|input| !input.is_null());
        let mut external = None;
        if input {
            super::input_envelope::transport(&body)?;
            match self.plain_arguments(&body).await? {
                Some(blob) => body["input"] = json!({"codec": "avro", "blob": blob}),
                None => {
                    external = self.prepare_external(&body["input"]).await?;
                    if external.is_none() {
                        self.decodable(&body["input"]["blob"]).await?;
                    }
                }
            }
        }
        let arguments = match &external {
            Some(prepared) => Some(prepared.stored()),
            None if input => Some(super::envelope(&body, "input")?),
            None => None,
        };
        let started = match &*self.storage {
            Storage::Sqlite(store) => store.start_standalone_activity(request, arguments, external, context).await?,
            Storage::Postgres(store) => store.start_standalone_activity(request, arguments, external, context).await?,
            Storage::MySql(store) => store.start_standalone_activity(request, arguments, external, context).await?,
        };
        if started["outcome"] != "returned_existing_active" {
            return Ok(started);
        }
        // `use-existing` (Server #590): the running activity, in the start
        // response's shape, as it stands now.
        let activity_id = started["activity_id"].as_str().unwrap_or_default();
        let view = self.standalone_activity(activity_id).await?;
        let mut existing = json!({"outcome":"returned_existing_active","command_status":"accepted","command_source":"control_plane"});
        for key in ["activity_id", "activity_execution_id", "workflow_id", "workflow_run_id", "workflow_type", "activity_type",
            "activity_class", "task_queue", "namespace", "status", "payload_codec", "started_at",
            "schedule_to_start_deadline_at", "schedule_to_close_deadline_at"] {
            existing[key] = view[key].clone();
        }
        Ok(existing)
    }

    /// `ActivityController::show`.
    pub(crate) async fn standalone_activity(&self, activity_id: &str) -> Result<Value> {
        let (host, execution) = match &*self.storage {
            Storage::Sqlite(store) => store.standalone_host(activity_id).await?,
            Storage::Postgres(store) => store.standalone_host(activity_id).await?,
            Storage::MySql(store) => store.standalone_host(activity_id).await?,
        };
        let attempts = self.standalone_attempts(&host, &execution).await?;
        let mut view = json!({"activity_id":host["workflow_instance_id"],"workflow_id":host["workflow_instance_id"],
            "workflow_run_id":host["id"],"workflow_type":host["workflow_type"],"namespace":self.namespace(),
            "activity_type":execution["activity_type"],"activity_class":execution["activity_class"],
            "activity_execution_id":execution["id"],"activity_status":execution["status"]});
        Self::extend_attempts(&mut view, &attempts);
        for (key, value) in [("task_queue", &host["queue"]), ("business_key", &host["business_key"]), ("status", &host["status"]),
            ("closed_reason", &host["closed_reason"]), ("started_at", &host["started_at"]), ("closed_at", &host["closed_at"]),
            ("last_progress_at", &host["last_progress_at"]), ("compatibility", &host["compatibility"]),
            ("payload_codec", &host["payload_codec"]), ("schedule_to_start_deadline_at", &execution["schedule_deadline_at"]),
            ("schedule_to_close_deadline_at", &execution["schedule_to_close_deadline_at"]),
            ("last_heartbeat_at", &execution["last_heartbeat_at"])] {
            view[key] = value.clone();
        }
        view["result"] = match execution["result"].as_str() {
            Some(result) if execution["status"] == "completed" => super::wire(result),
            _ => Value::Null,
        };
        Ok(view)
    }

    delegate!(message_stream_workflow_bound(workflow_id: &str) -> Result<bool>);
    delegate!(record_malformed_message(workflow_id: &str, stream: &str, message_id: &Value) -> Result<()>);
    delegate!(message_stream_diagnostics(workflow_id: &str, stream: Option<&str>) -> Result<Vec<Value>>);

    /// `MessageStreamController::append`: the stream name, the workflow in
    /// this namespace, the request rules and the payload envelope, in PHP's
    /// order. A refused request or payload counts as malformed input.
    pub(crate) async fn append_message(&self, workflow_id: &str, stream: &str, body: Value, context: Value) -> Result<(StatusCode, Value)> {
        super::message_streams::check_path_name(stream)?;
        if !self.message_stream_workflow_bound(workflow_id).await? {
            return Err(super::RuntimeError::Protocol {
                status: StatusCode::NOT_FOUND,
                response: json!({"accepted":false,"workflow_id":workflow_id,"stream_name":stream,"outcome":"rejected","reason":"instance_not_found"}),
            });
        }
        // A read-only Waterline projection takes no messages, whatever the
        // body; the store checks again under its lock.
        if let Some(run_id) = self.projected_current_run(workflow_id).await? {
            return Err(super::RuntimeError::Protocol {
                status: StatusCode::CONFLICT,
                response: super::waterline_import::read_only_append(workflow_id, &run_id, stream),
            });
        }
        let body = super::laravel_rules::normalize(&body);
        let blob = match self.message_payload(&body).await {
            Ok(blob) => blob,
            Err(error) => {
                if matches!(&error, super::RuntimeError::Protocol { status, .. } | super::RuntimeError::Refused { status, .. } if *status == StatusCode::UNPROCESSABLE_ENTITY) {
                    self.record_malformed_message(workflow_id, stream, &body["message_id"]).await?;
                }
                return Err(error);
            }
        };
        let message_id = body["message_id"].as_str().unwrap_or_default().to_owned();
        let (codec, structural) = (self.signal_codec.clone(), self.structural);
        match &*self.storage {
            Storage::Sqlite(store) => store.append_message(workflow_id, stream, &message_id, "avro", &blob, &context, codec, structural).await,
            Storage::Postgres(store) => store.append_message(workflow_id, stream, &message_id, "avro", &blob, &context, codec, structural).await,
            Storage::MySql(store) => store.append_message(workflow_id, stream, &message_id, "avro", &blob, &context, codec, structural).await,
        }
    }

    /// The store's append alone, without the service's checks: tests of the
    /// store's own guard call it.
    #[cfg(test)]
    pub(crate) async fn store_append_message(&self, workflow_id: &str, stream: &str, message_id: &str, blob: &str)
        -> Result<(StatusCode, Value)> {
        let (codec, structural, context) = (self.signal_codec.clone(), self.structural, json!({}));
        match &*self.storage {
            Storage::Sqlite(store) => store.append_message(workflow_id, stream, message_id, "avro", blob, &context, codec, structural).await,
            Storage::Postgres(store) => store.append_message(workflow_id, stream, message_id, "avro", blob, &context, codec, structural).await,
            Storage::MySql(store) => store.append_message(workflow_id, stream, message_id, "avro", blob, &context, codec, structural).await,
        }
    }

    /// The request rules, then `AvroPayloadEnvelopeResolver::resolve`: no
    /// input is an empty argument list, plain values are encoded, and an
    /// inline envelope keeps its exact bytes.
    async fn message_payload(&self, body: &Value) -> Result<String> {
        super::message_streams::check_append(body)?;
        if body.get("input").is_none_or(Value::is_null) {
            return super::queries::empty_arguments(self.signal_codec.clone()).await;
        }
        super::input_envelope::transport(body)?;
        if let Some(blob) = self.plain_arguments(body).await? {
            return Ok(blob);
        }
        if self.prepare_external(&body["input"]).await?.is_some() {
            // PHP keeps the reference and resolves it for workers; native
            // does not resolve stream payload references yet.
            return Err(super::RuntimeError::Protocol {
                status: StatusCode::NOT_IMPLEMENTED,
                response: json!({"accepted":false,"workflow_id":null,"message_id":body["message_id"],"outcome":"rejected",
                    "reason":"message_stream_external_payload_unavailable"}),
            });
        }
        self.decodable(&body["input"]["blob"]).await?;
        super::envelope(body, "input")
    }

    /// `MessageStreamController::index` and `show`.
    pub(crate) async fn message_streams(&self, workflow_id: &str, stream: Option<&str>) -> Result<Value> {
        if let Some(stream) = stream {
            super::message_streams::check_path_name(stream)?;
        }
        if !self.message_stream_workflow_bound(workflow_id).await? {
            return Err(super::RuntimeError::Protocol {
                status: StatusCode::NOT_FOUND,
                response: json!({"message":"Workflow not found.","reason":"instance_not_found"}),
            });
        }
        let streams = self.message_stream_diagnostics(workflow_id, stream).await?;
        match stream {
            None => Ok(json!({"workflow_id":workflow_id,"count":streams.len(),"streams":streams})),
            Some(stream) => match streams.into_iter().next() {
                Some(found) => Ok(json!({"workflow_id":workflow_id,"stream":found})),
                None => Err(super::RuntimeError::Protocol {
                    status: StatusCode::NOT_FOUND,
                    response: json!({"workflow_id":workflow_id,"stream_name":stream,"reason":"message_stream_not_found"}),
                }),
            },
        }
    }

    /// `ActivityController::index`.
    pub(crate) async fn standalone_activities(&self, query: Value) -> Result<Value> {
        let request = super::standalone_activities::list_request(&query)?;
        let (page, more) = match &*self.storage {
            Storage::Sqlite(store) => store.standalone_hosts(&request).await?,
            Storage::Postgres(store) => store.standalone_hosts(&request).await?,
            Storage::MySql(store) => store.standalone_hosts(&request).await?,
        };
        let mut activities = Vec::with_capacity(page.len());
        for (host, execution) in &page {
            let attempts = self.standalone_attempts(host, execution).await?;
            let mut entry = json!({"activity_id":host["workflow_instance_id"],"workflow_id":host["workflow_instance_id"],
                "workflow_run_id":host["id"],"activity_execution_id":execution["id"],"activity_type":execution["activity_type"],
                "activity_class":execution["activity_class"],"activity_status":execution["status"]});
            Self::extend_attempts(&mut entry, &attempts);
            for (key, value) in [("task_queue", &host["queue"]), ("status", &host["status"]),
                ("status_bucket", &host["status_bucket"]), ("business_key", &host["business_key"]),
                ("started_at", &host["started_at"]), ("closed_at", &host["closed_at"])] {
                entry[key] = value.clone();
            }
            activities.push(entry);
        }
        Ok(json!({"activity_count":activities.len(),"activities":activities,
            "next_page_token":more.then(|| super::standalone_activities::page_token(request.offset + request.page_size))}))
    }

    /// The host run's activity view, as `RunActivityView` reads it.
    async fn standalone_attempts(&self, host: &Value, execution: &Value) -> Result<Value> {
        let activities = if execution.is_null() {
            json!([])
        } else {
            let workflow_id = host["workflow_instance_id"].as_str().unwrap_or_default();
            let run_id = host["id"].as_str().unwrap_or_default();
            self.activities(workflow_id, run_id).await?["activities"].clone()
        };
        Ok(super::standalone_activities::attempt_fields(execution, &activities))
    }

    fn extend_attempts(view: &mut Value, attempts: &Value) {
        for key in ["attempt_count", "current_attempt_id", "current_attempt_status", "current_attempt", "attempts"] {
            view[key] = attempts[key].clone();
        }
        view["attempt_state"] = json!({"activity_execution_id":attempts["activity_execution_id"],
            "attempt_count":attempts["attempt_count"],"current_attempt_id":attempts["current_attempt_id"],
            "current_attempt_status":attempts["current_attempt_status"],"attempts":attempts["attempts"]});
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
        self.signal_command(workflow_id, run_id, name, body, context, None).await
    }
    /// A bridge adapter's signal, recorded under its bridge identity. A
    /// command already recorded under it is answered as
    /// `deduped_existing_command`.
    pub(crate) async fn bridge_signal(&self, workflow_id: &str, name: &str, body: Value, context: Value,
        key: &super::bridge_adapters::BridgeKey<'_>) -> Result<Value> {
        self.signal_command(workflow_id, None, name, body, Some(context), Some(key)).await
    }
    async fn signal_command(
        &self,
        workflow_id: &str,
        run_id: Option<&str>,
        name: &str,
        body: Value,
        context: Option<Value>,
        bridge: Option<&super::bridge_adapters::BridgeKey<'_>>,
    ) -> Result<Value> {
        let (body, prepared, external) = self.prepare_signal(name, body).await?;
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
                        self.structural,
                        external.as_ref(),
                        bridge,
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
                        self.structural,
                        external.as_ref(),
                        bridge,
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
                        self.structural,
                        external.as_ref(),
                        bridge,
                    )
                    .await
            }
        }
    }
    /// A signal request validated and its arguments prepared, as the signal
    /// route prepares them before storage.
    async fn prepare_signal(
        &self,
        name: &str,
        body: Value,
    ) -> Result<(Value, super::store::PreparedControlArguments, Option<super::external_resolution::PreparedExternal>)> {
        let body = super::start_request::normalized(body);
        super::input_envelope::transport(&body)?;
        super::laravel_validation::operation_name(name, "signal")?;
        super::command_requests::signal(&body)?;
        // `AvroPayloadEnvelopeResolver::resolve` with the namespace's storage:
        // a runtime external reference is checked, then kept by reference.
        let mut external = self.prepare_external(&body["input"]).await?;
        let arguments = match external.as_mut() {
            Some(prepared) => prepared.blob()?,
            None => self.command_arguments(&body).await?,
        };
        let mut prepared = super::store::prepare_control_arguments(
            self.signal_codec.clone(),
            name.to_owned(),
            arguments,
            "invalid_signal_arguments",
            true,
        )
        .await?;
        if let Some(external) = &external {
            prepared.arguments = external.stored();
        }
        Ok((body, prepared, external))
    }

    /// `POST /api/workflows/{workflow_id}/signal-with-start/{signal_name}`
    /// (Server #1133, shared .github#222): starts or reuses the instance's
    /// run and admits one signal on it in one transaction. The start half is
    /// validated and prepared as a start, the signal half as a signal, both
    /// before storage. Intakes on several nodes serialize in storage like
    /// any other transition (`two_nodes_racing_one_new_instance_...`).
    pub(crate) async fn signal_with_start(
        &self,
        workflow_id: &str,
        name: &str,
        body: Value,
        context: Value,
    ) -> Result<(StatusCode, Value)> {
        let body = super::start_request::normalized(body);
        let intake = super::signal_with_start::Intake::split(workflow_id, &body)?;
        let (start, memo, start_external) = self.prepare_start(intake.start, false).await?;
        let (signal, mut prepared, signal_external) = self.prepare_signal(name, intake.signal).await?;
        let request = super::signal_with_start::Request {
            workflow_id,
            name,
            start: &start,
            memo: &memo,
            start_external: start_external.as_ref(),
            signal: &signal,
            signal_external: signal_external.as_ref(),
            closed_run_policy: intake.closed_run_policy,
            context: &context,
        };
        let (codec, limits) = (self.transition_codec.clone(), self.structural);
        match &*self.storage {
            Storage::Sqlite(store) => store.signal_with_start(&request, &mut prepared, codec, limits).await,
            Storage::Postgres(store) => store.signal_with_start(&request, &mut prepared, codec, limits).await,
            Storage::MySql(store) => store.signal_with_start(&request, &mut prepared, codec, limits).await,
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
    delegate!(stream_run(workflow_id: &str, run_id: &str) -> Result<Option<bool>>);
    delegate!(list_workflow_streams(run_id: &str) -> Result<Vec<Value>>);
    delegate!(show_workflow_stream(run_id: &str, name: &str) -> Result<Option<Value>>);
    delegate!(append_workflow_stream(
        workflow_id: &str,
        run_id: &str,
        name: &str,
        items: Vec<super::workflow_streams::StreamItem>,
        max_pending: i64,
    ) -> Result<super::workflow_streams::Appended>);
    delegate!(read_workflow_stream(run_id: &str, name: &str, from: i64, max_items: i64)
        -> Result<Option<super::workflow_streams::Window>>);
    delegate!(close_workflow_stream(
        run_id: &str,
        name: &str,
        error_reason: Option<String>,
        retention_seconds: Option<i64>,
    ) -> Result<Option<Value>>);
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
    pub(super) async fn version_usage(&self, filter: super::version_usage::Filter) -> Result<Value> {
        match &*self.storage {
            Storage::Sqlite(store) => store.version_usage(&filter, &self.token).await,
            Storage::Postgres(store) => store.version_usage(&filter, &self.token).await,
            Storage::MySql(store) => store.version_usage(&filter, &self.token).await,
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
        self.update_command(workflow_id, run_id, name, body, context, None).await
    }
    /// A retained update's receipt by id; it never admits an update (#1131).
    pub(crate) async fn read_update(&self, workflow_id: &str, update_id: &str, query: &Value) -> Result<(StatusCode, Value)> {
        let codec = self.signal_codec.clone();
        match &*self.storage {
            Storage::Sqlite(store) => store.read_update(workflow_id, update_id, query, codec).await,
            Storage::Postgres(store) => store.read_update(workflow_id, update_id, query, codec).await,
            Storage::MySql(store) => store.read_update(workflow_id, update_id, query, codec).await,
        }
    }
    /// A bridge adapter's update, whose request id is its bridge identity.
    /// An accepted command already recorded under it is answered as
    /// `deduped_existing_command`.
    pub(crate) async fn bridge_update(&self, workflow_id: &str, name: &str, body: Value, context: Value,
        key: &super::bridge_adapters::BridgeKey<'_>) -> Result<(StatusCode, Value)> {
        self.update_command(workflow_id, None, name, body, Some(context), Some(key)).await
    }
    async fn update_command(
        &self,
        workflow_id: &str,
        run_id: Option<&str>,
        name: &str,
        body: Value,
        context: Option<Value>,
        bridge: Option<&super::bridge_adapters::BridgeKey<'_>>,
    ) -> Result<(StatusCode, Value)> {
        let body = super::start_request::normalized(body);
        super::input_envelope::transport(&body)?;
        super::laravel_validation::operation_name(name, "update")?;
        super::command_requests::update(&body)?;
        // PHP bounds update arguments by its structural payload ceiling,
        // which rejects them durably, not by a request budget. A runtime
        // external reference is resolved to its bytes, which the update
        // keeps inline (`resolveToArray`).
        let mut external = self.prepare_external(&body["input"]).await?;
        let arguments = match external.as_mut() {
            Some(prepared) => prepared.blob()?,
            None => self.command_arguments(&body).await?,
        };
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
                    .update_workflow(workflow_id, run_id, name, body, prepared, context, self.signal_codec.clone(), self.structural, external.as_ref(), bridge)
                    .await
            }
            Storage::Postgres(store) => {
                store
                    .update_workflow(workflow_id, run_id, name, body, prepared, context, self.signal_codec.clone(), self.structural, external.as_ref(), bridge)
                    .await
            }
            Storage::MySql(store) => {
                store
                    .update_workflow(workflow_id, run_id, name, body, prepared, context, self.signal_codec.clone(), self.structural, external.as_ref(), bridge)
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
        // as the query's arguments. A runtime external reference is claimed
        // and resolved to its bytes, which the query carries inline.
        let mut external = match input {
            Some(_) => self.prepare_external(&body["input"]).await?,
            None => None,
        };
        let arguments = match (input, external.as_mut()) {
            (None, _) => super::queries::empty_arguments(self.signal_codec.clone()).await?,
            (Some(_), Some(prepared)) => prepared.blob()?,
            (Some(_), None) => self.command_arguments(&body).await?,
        };
        if let Some(prepared) = &external {
            match &*self.storage {
                Storage::Sqlite(store) => store.claim_external(prepared).await?,
                Storage::Postgres(store) => store.claim_external(prepared).await?,
                Storage::MySql(store) => store.claim_external(prepared).await?,
            }
        }
        if arguments.len() > super::queries::ARGUMENT_BYTES {
            return Err(refuse(
                StatusCode::PAYLOAD_TOO_LARGE,
                "query_arguments_exceed_budget",
            ));
        }
        // Read, not refused: only a declared query contract needs them
        // (`validateContractedQueryArguments`).
        let values = super::queries::read_arguments(self.signal_codec.clone(), arguments.clone()).await?;
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
    /// `pollUpdateValidationTasks`.
    pub(crate) async fn poll_update_validation(&self, body: Value) -> Result<Value> {
        super::update_validation::poll_rules(&body)?;
        let timeout = match body.get("timeout_seconds") {
            None | Some(Value::Null) => self.worker_poll_wait(),
            Some(value) => super::laravel_validation::integer(value).and_then(|value| u64::try_from(value).ok()).unwrap_or(0),
        };
        let worker_id = super::text(&body, "worker_id")?;
        let task_queue = super::text(&body, "task_queue")?;
        let leased = match &*self.storage {
            Storage::Sqlite(store) => store.update_validation_poll(worker_id, task_queue, timeout).await?,
            Storage::Postgres(store) => store.update_validation_poll(worker_id, task_queue, timeout).await?,
            Storage::MySql(store) => store.update_validation_poll(worker_id, task_queue, timeout).await?,
        };
        let task = match leased {
            Some(task_id) => Some(self.update_validation_task(&task_id).await?),
            None => None,
        };
        let status = if task.is_some() { "leased" } else { "empty" };
        Ok(json!({"task":task,"poll_status":status}))
    }
    /// `POST /worker/update-validation-tasks/{taskId}/approve` and `/reject`.
    pub(crate) async fn complete_update_validation(&self, task_id: &str, body: Value, reject: bool) -> Result<(StatusCode, Value)> {
        super::update_validation::completion_rules(&body, reject)?;
        let failure = reject.then(|| body["failure"].clone());
        match &*self.storage {
            Storage::Sqlite(store) => store.complete_update_validation(task_id, &body, failure.as_ref()).await,
            Storage::Postgres(store) => store.complete_update_validation(task_id, &body, failure.as_ref()).await,
            Storage::MySql(store) => store.complete_update_validation(task_id, &body, failure.as_ref()).await,
        }
    }
    /// A leased validation task as a worker receives it, with the run's
    /// replay bundle.
    async fn update_validation_task(&self, task_id: &str) -> Result<Value> {
        let task = match &*self.storage {
            Storage::Sqlite(store) => store.update_validation_payload(task_id).await?,
            Storage::Postgres(store) => store.update_validation_payload(task_id).await?,
            Storage::MySql(store) => store.update_validation_payload(task_id).await?,
        };
        self.with_validation_history(task).await
    }

    async fn with_validation_history(&self, mut task: Value) -> Result<Value> {
        if task.get("history_events").is_some()
            && let (Some(workflow_id), Some(run_id)) = (task["workflow_id"].as_str(), task["run_id"].as_str())
        {
            let export = self.history_bundle(&workflow_id.to_owned(), &run_id.to_owned(), false).await?;
            task["history_export"] = export;
        }
        Ok(task)
    }
    /// `pollWorkflowTasks`: workflow tasks, validation tasks or both, as
    /// `task_kinds` asks. Every task names its kind.
    pub(crate) async fn poll_workflow_tasks(&self, body: Value, protocol_version: &str) -> Result<Value> {
        let kinds = super::update_validation::task_kinds(&body)?;
        let pages = self.history_pages();
        let wait = self.worker_poll_wait();
        let mark = super::carried_polls::workflow_answer;
        if kinds == ["workflow"] {
            return self.poll_with_pages(body, "workflow", protocol_version, pages, wait).await.map(mark);
        }
        let timeout = match body.get("timeout_seconds") {
            None | Some(Value::Null) => wait,
            Some(value) => super::laravel_validation::integer(value)
                .filter(|seconds| (0..=60).contains(seconds))
                .and_then(|seconds| u64::try_from(seconds).ok())
                .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_poll_timeout"))?,
        };
        let leased = match &*self.storage {
            Storage::Sqlite(store) => store.multiplexed_poll(&body, &kinds, protocol_version, pages, timeout, wait).await?,
            Storage::Postgres(store) => store.multiplexed_poll(&body, &kinds, protocol_version, pages, timeout, wait).await?,
            Storage::MySql(store) => store.multiplexed_poll(&body, &kinds, protocol_version, pages, timeout, wait).await?,
        };
        match leased {
            super::update_validation::Leased::Workflow(answer) => Ok(mark(answer)),
            super::update_validation::Leased::Validation(task_id) => {
                let mut task = self.update_validation_task(&task_id).await?;
                // The multiplexed poll's history paging field.
                task["next_history_page_token"] = Value::Null;
                Ok(json!({"task":task,"poll_status":"leased","server_capabilities":super::store::capabilities()}))
            }
        }
    }
    pub(super) fn task_delivery_descriptor(&self) -> Option<Value> {
        (!self.auth.anonymous && self.offer_window.current().is_some() && self.storage_admission().snapshot().accepts_new_work())
            .then(super::task_offer_dispatch::descriptor)
    }

    pub(super) async fn handle_task_offer(&self, endpoint: &'static str, body: &Value, protocol: &str) -> Result<(StatusCode, Value)> {
        use super::{
            task_delivery_receipts::{Acceptance, OfferScope},
            task_offer_dispatch::{self as delivery, Delivery, IncomingOffer},
        };
        let Some(keys) = self.offer_keys.as_ref() else {
            // No configured ring: nothing is issued. A packet whose read key
            // storage still requires gets its own retryable refusal.
            let id = body.get("task_offer_token").and_then(Value::as_str)
                .and_then(super::task_offer_seal::KeyRing::frame_key_id);
            let required = match id {
                None => false,
                Some(id) => match &*self.storage {
                    Storage::Sqlite(store) => store.offer_read_key_required(id).await?,
                    Storage::Postgres(store) => store.offer_read_key_required(id).await?,
                    Storage::MySql(store) => store.offer_read_key_required(id).await?,
                },
            };
            return Err(if required { delivery::read_key_unavailable() } else { delivery::unavailable() });
        };
        let request_id = super::workflow_poll_requests::request_id(body)
            .filter(|id| id.chars().count() <= 255)
            .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_task_offer_poll_request_id"))?;
        let worker_id = super::text(body, "worker_id")?;
        let queue = super::text(body, "task_queue")?;
        let registration = super::text(body, "registration_token")?;
        if registration.len() != 32 || !registration.bytes().all(|byte| byte.is_ascii_digit() || (b'a'..=b'f').contains(&byte)) {
            return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_task_offer_registration_token"));
        }
        let kinds = if endpoint == "workflow" {
            super::update_validation::task_kinds(body)?
        } else {
            if body.get("task_kinds").is_some_and(|kinds| !kinds.is_null() && *kinds != json!([endpoint])) {
                return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_task_offer_kinds"));
            }
            vec![endpoint]
        };
        let packet = body.get("task_offer_token").map(|packet| packet.as_str().ok_or_else(|| {
            refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_task_offer_token")
        })).transpose()?;
        let timeout = match body.get("timeout_seconds") {
            None | Some(Value::Null) if packet.is_none() => self.worker_poll_wait(),
            Some(value) => super::laravel_validation::integer(value)
                .filter(|seconds| (0..=60).contains(seconds))
                .map(|seconds| seconds as u64)
                .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_poll_timeout"))?,
            _ => return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_poll_timeout")),
        };
        if packet.is_some() && timeout != 0 {
            return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_poll_timeout"));
        }
        let encoding = if endpoint == "workflow" { super::history_pages::requested(body)? } else { None };
        let page = if endpoint == "workflow" {
            self.history_pages().poll(super::history_pages::requested_size(body)?, encoding)
        } else { None };
        let request = IncomingOffer {
            scope: OfferScope { worker_id, queue, endpoint, request_id: &request_id, kinds: &kinds },
            body, protocol, page, timeout, packet,
        };
        let outcome = match &*self.storage {
            Storage::Sqlite(store) => store.dispatch_task_offer(&request, keys, &self.offer_window, &self.queries).await,
            Storage::Postgres(store) => store.dispatch_task_offer(&request, keys, &self.offer_window, &self.queries).await,
            Storage::MySql(store) => store.dispatch_task_offer(&request, keys, &self.offer_window, &self.queries).await,
        };
        let refusal = |status, reason| Ok((StatusCode::CONFLICT, json!({"task":null,"poll_status":status,"reason":reason})));
        let outcome = match outcome {
            Err(super::RuntimeError::Refused { reason: "worker_registration_lost_authority", .. }) =>
                return refusal("stale_worker_registration", "worker_registration_lost_authority"),
            other => other?,
        };
        let body = match outcome {
            Delivery::Offer(None) => json!({"task":null,"poll_status":"empty"}),
            Delivery::Offer(Some(offer)) => json!({"task":null,"poll_status":"offered","task_offer":{
                "schema":delivery::DELIVERY_SCHEMA,"task_id":offer.target.task_id,"task_kind":offer.target.kind,
                "token":offer.token,"expires_at_ms":offer.expires_at_ms,
            }}),
            Delivery::Acceptance(Acceptance::Expired) => return refusal("task_offer_expired", "task_offer_expired"),
            Delivery::Acceptance(Acceptance::ConflictingOffer) => return refusal("task_offer_conflict", "task_offer_conflict"),
            Delivery::Acceptance(Acceptance::LeaseEnded) => return refusal("task_offer_lease_ended", "task_offer_lease_ended"),
            Delivery::Acceptance(Acceptance::Unclaimable) => return refusal("task_offer_unclaimable", "task_offer_unclaimable"),
            Delivery::Acceptance(Acceptance::Claimed(mut task) | Acceptance::Replayed(mut task)) => {
                if task["task_kind"] == "update_validation" {
                    task = self.with_validation_history(task).await?;
                    if endpoint == "workflow" {
                        task["next_history_page_token"] = Value::Null;
                    }
                    json!({"task":task,"poll_status":"leased"})
                } else if endpoint == "workflow" {
                    super::history_pages::compress(&mut task, encoding)?;
                    super::carried_polls::workflow_answer(json!({"task":task,"poll_status":"leased"}))
                } else {
                    task["task_kind"] = json!(endpoint);
                    super::store::activity_server_time(endpoint, json!({"task":task,"poll_status":"leased"}))
                }
            }
        };
        Ok((StatusCode::OK, body))
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
        self.history_bundle(workflow_id, run_id, true).await
    }
    /// One run's replay bundle, signed when asked and configured.
    /// `HistoryExport::forRun` itself, as replay tasks carry it, is unsigned.
    async fn history_bundle(&self, workflow_id: &str, run_id: &str, signed: bool) -> Result<Value> {
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
        let signing = setting(["DW_V2_HISTORY_EXPORT_SIGNING_KEY", "WORKFLOW_V2_HISTORY_EXPORT_SIGNING_KEY"]).filter(|_| signed).map(|key| {
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
            wait_seconds: u64,
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
        filter: &super::worker_management::WorkerListFilter<'_>,
    ) -> Result<Value> {
        match &*self.storage {
            Storage::Sqlite(store) => store.list_workers(filter, &self.queries).await,
            Storage::Postgres(store) => store.list_workers(filter, &self.queries).await,
            Storage::MySql(store) => store.list_workers(filter, &self.queries).await,
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
    pub(crate) async fn build_id_action(&self, queue: &str, body: Value, action: super::deployments::Action) -> Result<(StatusCode, Value)> {
        match &*self.storage {
            Storage::Sqlite(store) => store.build_id_action(queue, &body, action).await,
            Storage::Postgres(store) => store.build_id_action(queue, &body, action).await,
            Storage::MySql(store) => store.build_id_action(queue, &body, action).await,
        }
    }
    /// Server #577: a queue's activity dispatch state.
    pub(crate) async fn activity_dispatch(&self, queue: &str) -> Result<Value> {
        match &*self.storage {
            Storage::Sqlite(store) => store.activity_dispatch(queue).await,
            Storage::Postgres(store) => store.activity_dispatch(queue).await,
            Storage::MySql(store) => store.activity_dispatch(queue).await,
        }
    }
    pub(crate) async fn pause_activity_dispatch(&self, queue: &str, body: &Value, paused_by: Option<Value>) -> Result<Value> {
        match &*self.storage {
            Storage::Sqlite(store) => store.pause_activity_dispatch(queue, body, paused_by).await,
            Storage::Postgres(store) => store.pause_activity_dispatch(queue, body, paused_by).await,
            Storage::MySql(store) => store.pause_activity_dispatch(queue, body, paused_by).await,
        }
    }
    pub(crate) async fn resume_activity_dispatch(&self, queue: &str, resumed_by: Option<Value>) -> Result<Value> {
        match &*self.storage {
            Storage::Sqlite(store) => store.resume_activity_dispatch(queue, resumed_by).await,
            Storage::Postgres(store) => store.resume_activity_dispatch(queue, resumed_by).await,
            Storage::MySql(store) => store.resume_activity_dispatch(queue, resumed_by).await,
        }
    }
    pub(crate) async fn priority_fairness(&self, queue: &str) -> Result<Value> {
        match &*self.storage {
            Storage::Sqlite(store) => store.priority_fairness(queue).await,
            Storage::Postgres(store) => store.priority_fairness(queue).await,
            Storage::MySql(store) => store.priority_fairness(queue).await,
        }
    }
    pub(crate) async fn deployments(&self) -> Result<Value> {
        match &*self.storage {
            Storage::Sqlite(store) => store.deployments().await,
            Storage::Postgres(store) => store.deployments().await,
            Storage::MySql(store) => store.deployments().await,
        }
    }
    pub(crate) async fn deployment(&self, name: &str) -> Result<(StatusCode, Value)> {
        match &*self.storage {
            Storage::Sqlite(store) => store.deployment(name).await,
            Storage::Postgres(store) => store.deployment(name).await,
            Storage::MySql(store) => store.deployment(name).await,
        }
    }
    pub(crate) async fn deployment_action(&self, name: &str, action: super::deployments::Action) -> Result<(StatusCode, Value)> {
        match &*self.storage {
            Storage::Sqlite(store) => store.deployment_action(name, action).await,
            Storage::Postgres(store) => store.deployment_action(name, action).await,
            Storage::MySql(store) => store.deployment_action(name, action).await,
        }
    }
    /// Server #519: a read-only retirement inventory.
    pub(crate) async fn retirement(&self, queue: &str, target: super::retirement::Target) -> Result<Value> {
        match &*self.storage {
            Storage::Sqlite(store) => store.retirement(queue, target).await,
            Storage::Postgres(store) => store.retirement(queue, target).await,
            Storage::MySql(store) => store.retirement(queue, target).await,
        }
    }
    /// Server #519: guarded retirement.
    pub(crate) async fn retire(&self, queue: &str, target: super::retirement::Target, body: Value, retired_by: Option<Value>) -> Result<(StatusCode, Value)> {
        match &*self.storage {
            Storage::Sqlite(store) => store.retire(queue, target, &body, retired_by).await,
            Storage::Postgres(store) => store.retire(queue, target, &body, retired_by).await,
            Storage::MySql(store) => store.retire(queue, target, &body, retired_by).await,
        }
    }
    pub(crate) async fn deployment_retirement(&self, name: &str) -> Result<Value> {
        match &*self.storage {
            Storage::Sqlite(store) => store.deployment_retirement(name).await,
            Storage::Postgres(store) => store.deployment_retirement(name).await,
            Storage::MySql(store) => store.deployment_retirement(name).await,
        }
    }
    pub(crate) async fn deployment_retire(&self, name: &str, body: Value, retired_by: Option<Value>) -> Result<(StatusCode, Value)> {
        match &*self.storage {
            Storage::Sqlite(store) => store.deployment_retire(name, &body, retired_by).await,
            Storage::Postgres(store) => store.deployment_retire(name, &body, retired_by).await,
            Storage::MySql(store) => store.deployment_retire(name, &body, retired_by).await,
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
    delegate!(create_worker_session(body: Value, protocol: &str) -> Result<super::worker_sessions::Answer>);
    delegate!(heartbeat_worker_session(session_id: &str, body: Value, protocol: &str) -> Result<super::worker_sessions::Answer>);
    delegate!(close_worker_session(session_id: &str, body: Value, protocol: &str) -> Result<super::worker_sessions::Answer>);
    delegate!(list_worker_sessions() -> Result<Value>);
    delegate!(show_worker_session(session_id: &str) -> Result<Value>);
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
        // Of the failures, only a waiting acknowledgement applies the task's
        // signal (Server #979).
        // Only a failure the store accepts in full can apply the signal, so
        // a malformed one writes nothing.
        let well_formed = super::store::workflow_task_failure_shape(&body).is_ok();
        let signal = if well_formed && super::store::waiting_acknowledgement(&body["failure"]) {
            self.resolve_task_signal(task_id, &body).await?
        } else {
            None
        };
        match &*self.storage {
            Storage::Sqlite(store) => store.fail_workflow_task(task_id, body, codec, signal.as_ref()).await,
            Storage::Postgres(store) => store.fail_workflow_task(task_id, body, codec, signal.as_ref()).await,
            Storage::MySql(store) => store.fail_workflow_task(task_id, body, codec, signal.as_ref()).await,
        }
    }
    pub(crate) async fn complete_workflow(
        &self,
        task_id: &str,
        body: Value,
        worker: Option<super::runtime_credentials::Principal>,
        protocol: Option<&str>,
    ) -> Result<Value> {
        self.complete_or_checkpoint(task_id, body, worker, protocol, None).await
    }

    /// POST …/cancellation-scopes/checkpoint, after its route checks: the
    /// completion's payload preparation, then the store's checkpoint.
    pub(crate) async fn checkpoint_cancellation_scope(
        &self,
        task_id: &str,
        body: Value,
        worker: Option<super::runtime_credentials::Principal>,
        protocol: Option<&str>,
        checkpoint: &super::scope_checkpoint::Checkpoint,
    ) -> Result<Value> {
        self.complete_or_checkpoint(task_id, body, worker, protocol, Some(checkpoint)).await
    }

    async fn complete_or_checkpoint(
        &self,
        task_id: &str,
        body: Value,
        worker: Option<super::runtime_credentials::Principal>,
        protocol: Option<&str>,
        checkpoint: Option<&super::scope_checkpoint::Checkpoint>,
    ) -> Result<Value> {
        // A task the namespace does not hold is answered before any payload
        // is read or fetched, as PHP's guards run before its normalizer.
        let held = match &*self.storage {
            Storage::Sqlite(store) => store.task_held(task_id).await?,
            Storage::Postgres(store) => store.task_held(task_id).await?,
            Storage::MySql(store) => store.task_held(task_id).await?,
        };
        if !held {
            return Err(refuse(StatusCode::NOT_FOUND, "task_not_found"));
        }
        self.check_command_payloads(&body).await?;
        let external = self.prepare_command_payloads(&body).await?;
        // A resumed signal is resolved before the transaction that applies
        // it (Server #979).
        let signal = self.resolve_task_signal(task_id, &body).await?;
        let body = super::completion_rules::canonical_payloads(body)?;
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
        let polling = super::carried_polls::Config { protocol, pages: self.history_pages(), default_wait: self.worker_poll_wait() };
        match &*self.storage {
            Storage::Sqlite(store) => {
                store
                    .complete_or_checkpoint(task_id, body, self.transition_codec.clone(), external, worker, polling, self.structural, checkpoint, signal.as_ref())
                    .await
            }
            Storage::Postgres(store) => {
                store
                    .complete_or_checkpoint(task_id, body, self.transition_codec.clone(), external, worker, polling, self.structural, checkpoint, signal.as_ref())
                    .await
            }
            Storage::MySql(store) => {
                store
                    .complete_or_checkpoint(task_id, body, self.transition_codec.clone(), external, worker, polling, self.structural, checkpoint, signal.as_ref())
                    .await
                    .map_err(super::payload_limits::packet_refusal)
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
    /// `WorkflowController::redriveRun`: the run must exist before its
    /// request is validated.
    pub(crate) async fn redrive_run(&self, workflow_id: &str, run_id: &str, body: Value, context: Value) -> Result<(StatusCode, Value)> {
        if !self.run_in_namespace(workflow_id, run_id).await? {
            return Err(super::redrive::run_not_found(workflow_id, run_id));
        }
        let request_id = super::redrive::request_id(&body)?;
        match &*self.storage {
            Storage::Sqlite(store) => store.redrive_run(workflow_id, run_id, request_id, context).await,
            Storage::Postgres(store) => store.redrive_run(workflow_id, run_id, request_id, context).await,
            Storage::MySql(store) => store.redrive_run(workflow_id, run_id, request_id, context).await,
        }
    }
    /// `WorkflowStub::attemptArchive` for the current run.
    pub(crate) async fn archive_workflow(
        &self,
        workflow_id: &str,
        run_id: Option<&str>,
        body: Value,
        context: Value,
    ) -> Result<(StatusCode, Value)> {
        let reason = super::archive::reason(&body, workflow_id)?;
        let blob = super::archive::payload(reason.as_deref())?;
        match &*self.storage {
            Storage::Sqlite(store) => store.archive_workflow(workflow_id, run_id, reason, blob, context).await,
            Storage::Postgres(store) => store.archive_workflow(workflow_id, run_id, reason, blob, context).await,
            Storage::MySql(store) => store.archive_workflow(workflow_id, run_id, reason, blob, context).await,
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
    pub(crate) async fn complete_activity(&self, task_id: &str, body: Value, protocol: &str) -> Result<Value> {
        self.complete_activity_continuing(task_id, body, protocol, None).await
    }

    /// An activity completion that reports whether a continuation's history
    /// prefix still holds (Server #1078).
    pub(crate) async fn complete_activity_continuing(
        &self, task_id: &str, body: Value, protocol: &str, prefix: Option<(i64, &str)>,
    ) -> Result<Value> {
        let external = self.prepare_external(&body["result"]).await?;
        // `ActivityOutcomeRecorder` measures the result it would store.
        let stored = match &external {
            Some(prepared) => prepared.stored(),
            None => super::envelope(&body, "result")?,
        };
        if let Some(exceeded) = super::memo_limits::Exceeded::payload(self.structural.payload_bytes, &stored) {
            let (failure, blob) =
                super::activity_failures::structural_failure(&exceeded, self.signal_codec.clone()).await?;
            let limit = Some(exceeded);
            return match &*self.storage {
                Storage::Sqlite(store) => store.fail_activity(task_id, body, failure, blob, limit).await,
                Storage::Postgres(store) => store.fail_activity(task_id, body, failure, blob, limit).await,
                Storage::MySql(store) => store.fail_activity(task_id, body, failure, blob, limit).await,
            };
        }
        let polling = super::carried_polls::Config { protocol: Some(protocol), pages: self.history_pages(), default_wait: self.worker_poll_wait() };
        match &*self.storage {
            Storage::Sqlite(store) => store.complete_activity_continuing(task_id, body, external, polling, prefix).await,
            Storage::Postgres(store) => store.complete_activity_continuing(task_id, body, external, polling, prefix).await,
            Storage::MySql(store) => store.complete_activity_continuing(task_id, body, external, polling, prefix).await,
        }
    }
    pub(crate) async fn complete_activity_continuation(
        &self, task_id: &str, body: Value, continuation: super::continuation::Request,
        worker: Option<super::runtime_credentials::Principal>, protocol: &str,
    ) -> Result<Value> {
        let polling = super::carried_polls::Config { protocol: Some(protocol), pages: self.history_pages(), default_wait: self.worker_poll_wait() };
        let replay = match &*self.storage {
            Storage::Sqlite(store) => store.replay_activity_continuation(task_id, &body, polling).await?,
            Storage::Postgres(store) => store.replay_activity_continuation(task_id, &body, polling).await?,
            Storage::MySql(store) => store.replay_activity_continuation(task_id, &body, polling).await?,
        };
        if let Some(answer) = replay { return Ok(answer); }
        let external = self.prepare_external(&body["result"]).await?;
        let result = match &external {
            Some(prepared) => prepared.stored(),
            None => super::envelope(&body, "result")?,
        };
        if super::memo_limits::Exceeded::payload(self.structural.payload_bytes, &result).is_some() {
            return Err(refuse(StatusCode::PAYLOAD_TOO_LARGE, "continuation_result_exceeds_budget"));
        }
        let commands = json!({"lease_owner": body["lease_owner"], "workflow_task_attempt": 1, "commands": continuation.commands});
        self.check_command_payloads(&commands).await?;
        let command_external = self.prepare_command_payloads(&commands).await?;
        let commands = super::completion_rules::canonical_payloads(commands)?;
        match &*self.storage {
            Storage::Sqlite(store) => store.complete_activity_continuation(task_id, body, continuation, external, result,
                commands, command_external, self.transition_codec.clone(), worker, polling, self.structural).await,
            Storage::Postgres(store) => store.complete_activity_continuation(task_id, body, continuation, external, result,
                commands, command_external, self.transition_codec.clone(), worker, polling, self.structural).await,
            Storage::MySql(store) => store.complete_activity_continuation(task_id, body, continuation, external, result,
                commands, command_external, self.transition_codec.clone(), worker, polling, self.structural).await
                .map_err(super::payload_limits::packet_refusal),
        }
    }
    /// A version-2 continuation (Server #1181). Its receipt is the first
    /// read of the store's write transaction, so there is no read probe.
    pub(crate) async fn complete_activity_continuation_v2(
        &self, task_id: &str, body: Value, continuation: super::continuation_v2::Request,
        worker: Option<super::runtime_credentials::Principal>, protocol: &str,
    ) -> Result<Value> {
        let polling = super::carried_polls::Config { protocol: Some(protocol), pages: self.history_pages(), default_wait: self.worker_poll_wait() };
        let external = self.prepare_external(&body["result"]).await?;
        let result = match &external {
            Some(prepared) => prepared.stored(),
            None => super::envelope(&body, "result")?,
        };
        if super::memo_limits::Exceeded::payload(self.structural.payload_bytes, &result).is_some() {
            return Err(refuse(StatusCode::PAYLOAD_TOO_LARGE, "continuation_result_exceeds_budget"));
        }
        let commands = json!({"lease_owner": body["lease_owner"], "workflow_task_attempt": 1,
            "commands": continuation.commands, "sticky_cache": continuation.sticky_cache});
        self.check_command_payloads(&commands).await?;
        let command_external = self.prepare_command_payloads(&commands).await?;
        let mut commands = super::completion_rules::canonical_payloads(commands)?;
        // The nested completion returns the tail and carries only an
        // activity it created.
        commands["continuation_tail"] = json!({"version": super::continuation_tail::VERSION});
        if let Some(poll) = body.get("activity_poll") {
            commands["activity_poll"] = poll.clone();
        }
        match &*self.storage {
            Storage::Sqlite(store) => store.complete_activity_continuation_v2(task_id, body, continuation, external, result,
                commands, command_external, self.transition_codec.clone(), worker, polling, self.structural).await,
            Storage::Postgres(store) => store.complete_activity_continuation_v2(task_id, body, continuation, external, result,
                commands, command_external, self.transition_codec.clone(), worker, polling, self.structural).await,
            Storage::MySql(store) => store.complete_activity_continuation_v2(task_id, body, continuation, external, result,
                commands, command_external, self.transition_codec.clone(), worker, polling, self.structural).await
                .map_err(super::payload_limits::packet_refusal),
        }
    }
    delegate!(continuation_history(task_id: &str, body: Value) -> Result<Value>);
    delegate!(heartbeat_activity(task_id: &str, body: Value) -> Result<Value>);
    delegate!(activity_status(task_id: &str, body: Value, protocol: &str) -> Result<Value>);
    delegate!(renew_activity_lease(task_id: &str, body: Value) -> Result<Value>);
    delegate!(workflow_in_namespace(workflow_id: &str) -> Result<bool>);
    delegate!(bridge_identity_receipt(workflow_id: &str, kind: &str, identity: &str) -> Result<Option<(String, Value)>>);
    delegate!(nexus_operations(workflow_id: &str, run_id: Option<&str>, limit: i64) -> Result<Vec<Value>>);
    delegate!(service_catalog_list(query: &[(String, String)], catalog: super::service_catalog::Catalog) -> Result<Value>);
    delegate!(service_catalog_show(query: &[(String, String)], catalog: super::service_catalog::Catalog, id: &str) -> Result<Value>);
    delegate!(list_service_endpoints() -> Result<Value>);
    delegate!(create_service_endpoint(input: Value) -> Result<Value>);
    delegate!(show_service_endpoint(name: &str) -> Result<Value>);
    delegate!(update_service_endpoint(name: &str, input: Value) -> Result<Value>);
    delegate!(delete_service_endpoint(name: &str) -> Result<Value>);
    delegate!(list_services(endpoint: &str) -> Result<Value>);
    delegate!(create_service(endpoint: &str, input: Value) -> Result<Value>);
    delegate!(show_service(endpoint: &str, service: &str) -> Result<Value>);
    delegate!(update_service(endpoint: &str, service: &str, input: Value) -> Result<Value>);
    delegate!(delete_service(endpoint: &str, service: &str) -> Result<Value>);
    delegate!(list_operations(endpoint: &str, service: &str) -> Result<Value>);
    delegate!(create_operation(endpoint: &str, service: &str, input: Value) -> Result<Value>);
    delegate!(show_operation(names: [&str; 3]) -> Result<Value>);
    delegate!(update_operation(names: [&str; 3], input: Value) -> Result<Value>);
    delegate!(delete_operation(names: [&str; 3]) -> Result<Value>);
    delegate!(service_operation_exists(names: [&str; 3]) -> Result<()>);
    delegate!(service_call_exists(names: [&str; 3], id: &str) -> Result<()>);
    delegate!(validate_service_call_search_attributes(attributes: Value) -> Result<()>);
    delegate!(execute_service_call(names: [&str; 3], execution: super::service_calls::Execution, caller: super::service_calls::Caller) -> Result<(StatusCode, Value)>);
    delegate!(show_service_call(names: [&str; 3], id: &str) -> Result<Value>);
    delegate!(cancel_service_call(names: [&str; 3], id: &str, reason: Option<String>) -> Result<(StatusCode, Value)>);
    delegate!(import_existing_run(run_id: &str) -> Result<Option<super::workflow_import::ExistingRun>>);
    delegate!(import_instance_namespace(instance_id: &str) -> Result<Option<String>>);
    delegate!(import_write(plan: &super::workflow_import::Plan) -> std::result::Result<super::workflow_import::Rows, String>);
    delegate!(waterline_existing(workflow_id: &str, run_id: &str) -> Result<super::waterline_import::Existing>);
    delegate!(projected_current_run(workflow_id: &str) -> Result<Option<String>>);
    delegate!(projected_run(workflow_id: &str, run_id: &str) -> Result<Option<String>>);
    delegate!(waterline_write(projection: &super::waterline_import::Normalized, imported_at: chrono::DateTime<chrono::Utc>) -> std::result::Result<(), bool>);
    /// The namespace this runtime's store serves.
    pub(crate) fn namespace_name(&self) -> String {
        match &*self.storage {
            Storage::Sqlite(store) => store.namespace_name(),
            Storage::Postgres(store) => store.namespace_name(),
            Storage::MySql(store) => store.namespace_name(),
        }
    }
    delegate!(acknowledge_activity_cancellation(task_id: &str, body: Value, protocol: &str) -> Result<Value>);
    delegate!(open_cancellation_scope(task_id: &str, body: Value, protocol: &str) -> Result<(axum::http::StatusCode, Value)>);
    delegate!(prepare_cancellation_scope(task_id: &str, body: Value, protocol: &str) -> Result<(axum::http::StatusCode, Value)>);
    delegate!(deliver_cancellation_scope(task_id: &str, body: Value, protocol: &str) -> Result<(axum::http::StatusCode, Value)>);
    pub(crate) async fn fail_activity(&self, task_id: &str, body: Value) -> Result<Value> {
        let (failure, blob) =
            super::activity_failures::prepare_failure(&body, self.signal_codec.clone()).await?;
        match &*self.storage {
            Storage::Sqlite(store) => store.fail_activity(task_id, body, failure, blob, None).await,
            Storage::Postgres(store) => store.fail_activity(task_id, body, failure, blob, None).await,
            Storage::MySql(store) => store.fail_activity(task_id, body, failure, blob, None).await,
        }
    }
}

#[cfg(test)]
#[tokio::test]
async fn task_offer_read_key_preflight_fences_an_incomplete_restart() {
    use super::task_offer_key_usage::fixture_ring;

    let directory = tempfile::tempdir().unwrap();
    let path = directory.path().join("runtime.sqlite");
    let path = path.to_str().unwrap();
    let open_with = |keys| async move {
        let pool = sqlite::open(path).await.unwrap();
        Runtime::from_storage(
            Storage::Sqlite(Store::new(pool)),
            "test-only-runtime-token".into(),
            Default::default(),
            Default::default(),
            Default::default(),
            keys,
        )
        .await
    };

    let store = Store::new(sqlite::open(path).await.unwrap());
    store.reserve_fixture_offer_key().await;
    store.close().await;

    // A packet under the obligated key, one under a key storage never used,
    // and the acceptance request that carries each. Opening stops at the key
    // lookup, so the frame needs no valid ciphertext.
    let accept = |token: &str| json!({"worker_id":"stranded-worker","task_queue":"stranded-queue",
        "poll_request_id":"stranded-poll","registration_token":"0123456789abcdef0123456789abcdef",
        "timeout_seconds":0,"task_offer_token":token});
    let required_packet = accept("dwo1.fixture-key.AAAA");
    let unused_packet = accept("dwo1.never-used.AAAA");
    let refusal = |result: Result<(StatusCode, Value)>| match result {
        Err(super::RuntimeError::Refused { status, reason }) => (status, reason),
        other => panic!("expected a typed refusal, got {:?}", other.map(|(status, _)| status)),
    };

    // Missing ring with a live obligation: start, serve legacy routes, issue
    // nothing, keep the obligation, and refuse the packet as retryable.
    let runtime = open_with(None).await.expect("a missing read key does not refuse startup");
    assert!(runtime.task_delivery_descriptor().is_none());
    assert!(runtime.offer_window.current().is_none());
    {
        use axum::{body::Body, http::Request};
        use tower::ServiceExt;
        runtime.create_namespace(json!({"name":"stranded"})).await.unwrap();
        let app = super::router(runtime.clone());
        let post = |path: &str, body: Value| Request::builder().method("POST").uri(path)
            .header("x-namespace", "stranded").header("x-durable-workflow-protocol-version", "1.20")
            .header("content-type", "application/json").header("authorization", "Bearer test-only-runtime-token")
            .body(Body::from(body.to_string())).unwrap();
        let worker = json!({"worker_id":"legacy-worker","task_queue":"legacy-queue","runtime":"php",
            "supported_workflow_types":["fixture"],"supported_activity_types":[],"capabilities":["task_delivery_offers"],
            "capability_manifest":{
                "sticky_execution":{"supported":false,"minimum_protocol_version":"1.18"},
                "local_activities":{"supported":false,"minimum_protocol_version":"1.18"},
                "worker_sessions":{"supported":false,"minimum_protocol_version":"1.18"}
            }});
        let registered = app.clone().oneshot(post("/api/worker/register", worker)).await.unwrap();
        assert_eq!(registered.status(), StatusCode::CREATED);
        let registered: Value = serde_json::from_slice(
            &axum::body::to_bytes(registered.into_body(), 1024 * 1024).await.unwrap()).unwrap();
        assert!(registered["server_capabilities"].get("task_delivery").is_none());
        let legacy = app.oneshot(post("/api/worker/workflow-tasks/poll",
            json!({"worker_id":"legacy-worker","task_queue":"legacy-queue","timeout_seconds":0}))).await.unwrap();
        assert_eq!(legacy.status(), StatusCode::OK, "legacy polling keeps serving");
    }
    assert_eq!(refusal(runtime.handle_task_offer("workflow", &required_packet, "1.20").await),
        (StatusCode::SERVICE_UNAVAILABLE, "task_offer_read_key_unavailable"));
    assert_eq!(refusal(runtime.handle_task_offer("workflow", &unused_packet, "1.20").await),
        (StatusCode::SERVICE_UNAVAILABLE, "task_offer_issuance_unavailable"));
    assert!(runtime.sqlite_store().offer_read_key_required("fixture-key").await.unwrap());
    runtime.close().await;

    // A configured ring that lacks the obligated key also starts without an
    // issuance window and refuses only packets that need the absent key.
    let incomplete = Arc::new(fixture_ring(&[("other", 3)]));
    let runtime = open_with(Some(incomplete)).await.expect("an incomplete ring does not refuse startup");
    assert!(runtime.task_delivery_descriptor().is_none());
    assert!(runtime.offer_window.current().is_none());
    assert_eq!(refusal(runtime.handle_task_offer("workflow", &required_packet, "1.20").await),
        (StatusCode::SERVICE_UNAVAILABLE, "task_offer_read_key_unavailable"));
    assert_eq!(refusal(runtime.handle_task_offer("workflow", &unused_packet, "1.20").await),
        (StatusCode::CONFLICT, "invalid_task_offer_token"));
    assert!(runtime.sqlite_store().offer_read_key_required("fixture-key").await.unwrap());
    runtime.close().await;

    // Reusing a key ID for different material still refuses startup.
    let changed = Arc::new(fixture_ring(&[("fixture-key", 8)]));
    assert!(matches!(
        open_with(Some(changed)).await,
        Err(super::RuntimeError::Refused { reason: "task_offer_key_identity_changed", .. })
    ));

    let current = Arc::new(fixture_ring(&[("next", 2), ("fixture-key", 7)]));
    let runtime = open_with(Some(current)).await.unwrap();
    assert!(runtime.task_delivery_descriptor().is_some());
    runtime.close().await;
}

#[cfg(test)]
#[tokio::test]
async fn task_offer_worker_routes_preserve_signed_scope_and_definitive_refusals() {
    use super::task_offer_key_usage::fixture_ring;
    use axum::{Router, body::{Body, to_bytes}, http::Request};
    use tower::ServiceExt;

    async fn request(app: &Router, path: &str, body: &Value, bearer: bool, mode: bool) -> (StatusCode, Value) {
        let mut request = Request::builder().method("POST").uri(path)
            .header("x-namespace", "offer-test")
            .header("x-durable-workflow-protocol-version", "1.20")
            .header("content-type", "application/json");
        if bearer {
            request = request.header("authorization", "Bearer fixture-worker-plane");
        }
        if mode {
            request = request.header("x-durable-workflow-task-delivery", "offer-accept.v1");
        }
        let response = app.clone().oneshot(request.body(Body::from(body.to_string())).unwrap()).await.unwrap();
        let status = response.status();
        let bytes = to_bytes(response.into_body(), 64 * 1024).await.unwrap();
        (status, serde_json::from_slice(&bytes).unwrap())
    }

    let directory = tempfile::tempdir().unwrap();
    let path = directory.path().join("runtime.sqlite");
    let storage = Storage::Sqlite(Store::new(sqlite::open(path.to_str().unwrap()).await.unwrap()));
    let keys = Arc::new(fixture_ring(&[("route-test", 1)]));
    let runtime = Runtime::from_storage(storage, "fixture-worker-plane".into(),
        Default::default(), Default::default(), Default::default(), Some(keys)).await.unwrap();
    runtime.create_namespace(json!({"name":"offer-test"})).await.unwrap();
    async fn read_horizon(runtime: &Runtime) -> i64 {
        let value: String = sqlx::query_scalar("SELECT value FROM dw_query_cache WHERE key=?")
            .bind("dw-native-offer-key:route-test").fetch_one(&runtime.sqlite_store().pool).await.unwrap();
        serde_json::from_str::<Value>(&value).unwrap()["read_until_ms"].as_i64().unwrap()
    }
    let initial_horizon = read_horizon(&runtime).await;
    let app = super::router(runtime.clone());
    let worker = json!({"worker_id":"offer-worker","task_queue":"offer-queue","runtime":"php",
        "supported_workflow_types":["fixture"],"supported_activity_types":[],
        "capabilities":["task_delivery_offers","grouped_condition_wait_replay"],
        "capability_manifest":{
            "sticky_execution":{"supported":false,"minimum_protocol_version":"1.18"},
            "local_activities":{"supported":false,"minimum_protocol_version":"1.18"},
            "worker_sessions":{"supported":false,"minimum_protocol_version":"1.18"}
        }});
    let (status, registered) = request(&app, "/api/worker/register", &worker, true, false).await;
    assert_eq!(status, StatusCode::CREATED, "registration refused: {:?}", registered["reason"]);
    assert_eq!(registered["server_capabilities"]["task_delivery"], super::task_offer_dispatch::descriptor());
    assert_eq!(registered["server_capabilities"]["grouped_condition_waits"], json!({"supported":true,"version":1}));
    let input = crate::codec::ValueCodec::new().unwrap().encode(&crate::codec::Value::Array(Vec::new())).unwrap();
    for id in ["route-offer-one", "route-offer-two"] {
        runtime.in_namespace("offer-test").start(json!({"workflow_id":id,"workflow_type":"fixture",
            "task_queue":"offer-queue","input":{"codec":"avro","blob":input}}), None).await.unwrap();
    }

    let path = "/api/worker/workflow-tasks/offers";
    let mut poll = json!({"worker_id":"offer-worker","task_queue":"offer-queue","poll_request_id":"route-claim",
        "registration_token":registered["registration_token"],"timeout_seconds":0});
    assert_eq!(request(&app, path, &poll, false, true).await.0, StatusCode::UNAUTHORIZED);
    let anonymous = super::router(runtime.clone().with_auth_options(true, false));
    assert_eq!(request(&anonymous, path, &poll, false, true).await.0, StatusCode::UNAUTHORIZED);
    assert_eq!(request(&app, path, &poll, true, false).await.0, StatusCode::UNPROCESSABLE_ENTITY);
    let (status, offered) = request(&app, path, &poll, true, true).await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(offered["poll_status"], "offered");
    assert!(offered["task"].is_null());
    let attempt: i64 = sqlx::query_scalar("SELECT attempt_count FROM workflow_tasks WHERE id=?")
        .bind(offered["task_offer"]["task_id"].as_str().unwrap()).fetch_one(&runtime.sqlite_store().pool).await.unwrap();
    assert_eq!(attempt, 0);

    poll["task_offer_token"] = offered["task_offer"]["token"].clone();
    let (status, legacy) = request(&app, "/api/worker/workflow-tasks/poll", &poll, true, false).await;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY);
    assert_eq!(legacy["reason"], "task_offer_endpoint_required");
    let (status, wrong) = request(&app, "/api/worker/query-tasks/offers", &poll, true, true).await;
    assert_eq!(status, StatusCode::CONFLICT);
    assert_eq!(wrong["reason"], "task_offer_scope_mismatch");
    for _ in 0..2 {
        let (status, leased) = request(&app, path, &poll, true, true).await;
        assert_eq!(status, StatusCode::OK);
        assert_eq!(leased["task"]["task_id"], offered["task_offer"]["task_id"]);
        assert_eq!(leased["task"]["task_kind"], "workflow");
        assert_eq!(leased["task"]["workflow_task_attempt"], 1);
    }

    poll["poll_request_id"] = json!("expire-before-accept");
    poll.as_object_mut().unwrap().remove("task_offer_token");
    let (status, offered) = request(&app, path, &poll, true, true).await;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(offered["poll_status"], "offered");
    poll["task_offer_token"] = offered["task_offer"]["token"].clone();
    tokio::time::sleep(Duration::from_millis(2050)).await;
    let (status, expired) = request(&app, path, &poll, true, true).await;
    assert_eq!(status, StatusCode::CONFLICT);
    assert_eq!(expired, json!({"task":null,"poll_status":"task_offer_expired","reason":"task_offer_expired"}));

    let (status, reregistered) = request(&app, "/api/worker/register", &worker, true, false).await;
    assert_eq!(status, StatusCode::CREATED);
    let (status, stale) = request(&app, path, &poll, true, true).await;
    assert_eq!(status, StatusCode::CONFLICT);
    assert_eq!(stale, json!({"task":null,"poll_status":"stale_worker_registration",
        "reason":"worker_registration_lost_authority"}));

    // Every other definitive answer is the same exact three-field 409.
    let exact = |reason: &str| json!({"task":null,"poll_status":reason,"reason":reason});
    let offer_poll = |worker_id: &str, token: &Value, request_id: &str| json!({"worker_id":worker_id,
        "task_queue":"offer-queue","poll_request_id":request_id,"registration_token":token,"timeout_seconds":0});

    // Two workers race for the one ready task. Offers reserve nothing, so both
    // observe it; the peer's acceptance wins and the loser's packet is
    // definitively unclaimable.
    let mut peer = worker.clone();
    peer["worker_id"] = json!("offer-peer");
    let (status, peer_registered) = request(&app, "/api/worker/register", &peer, true, false).await;
    assert_eq!(status, StatusCode::CREATED);
    let mut loser = offer_poll("offer-worker", &reregistered["registration_token"], "race-loser");
    let mut winner = offer_poll("offer-peer", &peer_registered["registration_token"], "race-winner");
    let (_, loser_offer) = request(&app, path, &loser, true, true).await;
    let (_, winner_offer) = request(&app, path, &winner, true, true).await;
    assert_eq!(loser_offer["poll_status"], "offered");
    assert_eq!(winner_offer["task_offer"]["task_id"], loser_offer["task_offer"]["task_id"]);
    loser["task_offer_token"] = loser_offer["task_offer"]["token"].clone();
    winner["task_offer_token"] = winner_offer["task_offer"]["token"].clone();
    let (status, won) = request(&app, path, &winner, true, true).await;
    assert_eq!((status, &won["task"]["task_id"]), (StatusCode::OK, &winner_offer["task_offer"]["task_id"]));
    let (status, lost) = request(&app, path, &loser, true, true).await;
    assert_eq!((status, lost), (StatusCode::CONFLICT, exact("task_offer_unclaimable")));

    // A second packet for a logical poll that already committed another one
    // can never be accepted.
    runtime.in_namespace("offer-test").start(json!({"workflow_id":"route-offer-three","workflow_type":"fixture",
        "task_queue":"offer-queue","input":{"codec":"avro","blob":input}}), None).await.unwrap();
    let mut first = offer_poll("offer-worker", &reregistered["registration_token"], "bound-poll");
    let mut second = first.clone();
    let (_, first_offer) = request(&app, path, &first, true, true).await;
    let (_, second_offer) = request(&app, path, &second, true, true).await;
    assert_ne!(first_offer["task_offer"]["token"], second_offer["task_offer"]["token"]);
    first["task_offer_token"] = first_offer["task_offer"]["token"].clone();
    second["task_offer_token"] = second_offer["task_offer"]["token"].clone();
    let (status, bound) = request(&app, path, &first, true, true).await;
    assert_eq!(status, StatusCode::OK);
    let (status, conflict) = request(&app, path, &second, true, true).await;
    assert_eq!((status, conflict), (StatusCode::CONFLICT, exact("task_offer_conflict")));

    // Once that claim's lease ends, its own packet no longer replays.
    let (status, failed) = request(&app, &format!("/api/worker/workflow-tasks/{}/fail", bound["task"]["task_id"].as_str().unwrap()),
        &json!({"lease_owner":bound["task"]["lease_owner"],"workflow_task_attempt":bound["task"]["workflow_task_attempt"],
            "failure":{"message":"end the accepted lease","type":"RuntimeException"}}), true, false).await;
    assert_eq!(status, StatusCode::OK, "{failed}");
    let (status, ended) = request(&app, path, &first, true, true).await;
    assert_eq!((status, ended), (StatusCode::CONFLICT, exact("task_offer_lease_ended")));
    tokio::time::timeout(Duration::from_secs(15), async {
        while read_horizon(&runtime).await <= initial_horizon {
            tokio::time::sleep(Duration::from_millis(100)).await;
        }
    }).await.expect("the service renews its durable signing window without a poll");
    runtime.close().await;
}

#[cfg(test)]
#[tokio::test]
async fn task_offer_replay_survives_heartbeat_and_fences_reregistration() {
    use super::task_offer_key_usage::fixture_ring;
    use axum::{Router, body::{Body, to_bytes}, http::Request};
    use tower::ServiceExt;

    async fn request(app: &Router, path: &str, body: &Value, mode: bool) -> (StatusCode, Value) {
        let mut request = Request::builder().method("POST").uri(path)
            .header("x-namespace", "offer-test")
            .header("x-durable-workflow-protocol-version", "1.20")
            .header("content-type", "application/json")
            .header("authorization", "Bearer fixture-worker-plane");
        if mode {
            request = request.header("x-durable-workflow-task-delivery", "offer-accept.v1");
        }
        let response = app.clone().oneshot(request.body(Body::from(body.to_string())).unwrap()).await.unwrap();
        let status = response.status();
        let bytes = to_bytes(response.into_body(), 1024 * 1024).await.unwrap();
        (status, serde_json::from_slice(&bytes).unwrap())
    }

    for with_process in [false, true] {
        let directory = tempfile::tempdir().unwrap();
        let path = directory.path().join("runtime.sqlite");
        let storage = Storage::Sqlite(Store::new(sqlite::open(path.to_str().unwrap()).await.unwrap()));
        let keys = Arc::new(fixture_ring(&[("heartbeat-test", 1)]));
        let runtime = Runtime::from_storage(storage, "fixture-worker-plane".into(),
            Default::default(), Default::default(), Default::default(), Some(keys)).await.unwrap();
        runtime.create_namespace(json!({"name":"offer-test"})).await.unwrap();
        let app = super::router(runtime.clone());
        let mut worker = json!({"worker_id":"offer-worker","task_queue":"offer-queue","runtime":"php",
            "supported_workflow_types":["fixture"],"supported_activity_types":[],
            "capabilities":["task_delivery_offers"],
            "capability_manifest":{
                "sticky_execution":{"supported":false,"minimum_protocol_version":"1.18"},
                "local_activities":{"supported":false,"minimum_protocol_version":"1.18"},
                "worker_sessions":{"supported":false,"minimum_protocol_version":"1.18"}
            }});
        let mut heartbeat = json!({"worker_id":"offer-worker"});
        if with_process {
            let process = json!({"host":"fixture-host","process_id":42,"process_started_at":"2026-01-01T00:00:00Z"});
            worker["process_metrics"] = process.clone();
            heartbeat["process_metrics"] = process;
        }
        let (status, registered) = request(&app, "/api/worker/register", &worker, false).await;
        assert_eq!(status, StatusCode::CREATED, "registration refused: {:?}", registered["reason"]);
        let input = crate::codec::ValueCodec::new().unwrap().encode(&crate::codec::Value::Array(Vec::new())).unwrap();
        runtime.in_namespace("offer-test").start(json!({"workflow_id":"heartbeat-replay","workflow_type":"fixture",
            "task_queue":"offer-queue","input":{"codec":"avro","blob":input}}), None).await.unwrap();

        let path = "/api/worker/workflow-tasks/offers";
        let mut poll = json!({"worker_id":"offer-worker","task_queue":"offer-queue","poll_request_id":"heartbeat-claim",
            "registration_token":registered["registration_token"],"timeout_seconds":0});
        let (status, offered) = request(&app, path, &poll, true).await;
        assert_eq!((status, &offered["poll_status"]), (StatusCode::OK, &json!("offered")), "process={with_process}");
        poll["task_offer_token"] = offered["task_offer"]["token"].clone();
        let (status, leased) = request(&app, path, &poll, true).await;
        assert_eq!((status, &leased["poll_status"]), (StatusCode::OK, &json!("leased")), "process={with_process}");

        // Stored heartbeat times have one-second resolution.
        tokio::time::sleep(Duration::from_millis(1100)).await;
        let (status, _) = request(&app, "/api/worker/heartbeat", &heartbeat, false).await;
        assert_eq!(status, StatusCode::OK);
        let (status, replayed) = request(&app, path, &poll, true).await;
        assert_eq!(status, StatusCode::OK, "process={with_process} reason={:?}", replayed["reason"]);
        assert_eq!(replayed["poll_status"], "leased");
        assert_eq!(replayed["task"]["task_id"], offered["task_offer"]["task_id"]);
        assert_eq!(replayed["task"]["workflow_task_attempt"], 1);

        // Re-registration rotates the incarnation, so the old packet loses
        // authority even though the routing and process identity are unchanged.
        let (status, _) = request(&app, "/api/worker/register", &worker, false).await;
        assert_eq!(status, StatusCode::CREATED);
        let (status, stale) = request(&app, path, &poll, true).await;
        assert_eq!(status, StatusCode::CONFLICT, "process={with_process}");
        assert_eq!(stale, json!({"task":null,"poll_status":"stale_worker_registration",
            "reason":"worker_registration_lost_authority"}), "process={with_process}");
        runtime.close().await;
    }
}
