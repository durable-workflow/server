use std::{collections::BTreeMap, env, error::Error, net::SocketAddr, str::FromStr};

use durable_workflow_server::config_audit::{self, PhpValue};
use durable_workflow_server::database_tls::{self, Driver, Settings};
use durable_workflow_server::runtime::{
    Runtime, RuntimeError, mysql::MySqlStorage, postgres::PostgresStorage,
    router,
};
use sqlx::mysql::MySqlConnectOptions;
use sqlx::postgres::PgConnectOptions;

/// The connection's TLS settings and its DB_URL without TLS parameters, as
/// published PHP resolves them. A refusal names PHP's reason and message.
fn tls_settings(driver: Driver) -> Result<(Settings, Option<String>), Box<dyn Error>> {
    let (settings, url) = database_tls::resolve(driver, |key| env::var(key).ok())?;
    Ok((settings, url.map(|url| url.without_tls)))
}

/// Published PHP boots while its database is unavailable
/// (`server:assert-php-database --allow-unavailable`), so an unchanged
/// Compose file may start the server before the database accepts
/// connections. Native waits for the database instead of exiting. Only a
/// database that cannot be reached yet is awaited; a refused login or a
/// configuration error still stops the process.
async fn open_when_reachable<F, Fut>(open: F) -> Result<Option<Runtime>, RuntimeError>
where
    F: Fn() -> Fut,
    Fut: std::future::Future<Output = Result<Runtime, RuntimeError>>,
{
    // One listener covers every attempt: the pool retries a refused
    // connection inside each one, and a stop request must not wait for it.
    let shutdown = shutdown_signal();
    tokio::pin!(shutdown);
    let mut delay = std::time::Duration::from_millis(250);
    let mut waiting = false;
    loop {
        let attempt = tokio::select! {
            result = open() => result,
            () = &mut shutdown => return Ok(None),
        };
        match attempt {
            Err(error) if unreachable(&error) => {
                if !waiting {
                    eprintln!("database unavailable ({error}); waiting for it to accept connections");
                    waiting = true;
                }
                tokio::select! {
                    () = tokio::time::sleep(delay) => {}
                    () = &mut shutdown => return Ok(None),
                }
                delay = (delay * 2).min(std::time::Duration::from_secs(5));
            }
            Err(error) => return Err(error),
            Ok(runtime) => {
                if waiting {
                    eprintln!("database reachable");
                }
                return Ok(Some(runtime));
            }
        }
    }
}

/// A database that is not accepting connections yet: the connection is
/// refused or drops, its host does not resolve yet, the pool times out, or
/// PostgreSQL is still starting (SQLSTATE 57P03).
fn unreachable(error: &RuntimeError) -> bool {
    fn retryable(error: &sqlx::Error) -> bool {
        match error {
            sqlx::Error::Io(_) | sqlx::Error::PoolTimedOut => true,
            sqlx::Error::Database(error) => error.code().as_deref() == Some("57P03"),
            _ => false,
        }
    }
    match error {
        RuntimeError::Database(error) => retryable(error),
        RuntimeError::Migration(sqlx::migrate::MigrateError::Execute(error)) => retryable(error),
        _ => false,
    }
}

async fn shutdown_signal() {
    let ctrl_c = async {
        let _ = tokio::signal::ctrl_c().await;
    };
    #[cfg(unix)]
    let terminate = async {
        tokio::signal::unix::signal(tokio::signal::unix::SignalKind::terminate())
            .expect("install SIGTERM handler")
            .recv()
            .await;
    };
    #[cfg(not(unix))]
    let terminate = std::future::pending::<()>();
    tokio::select! { () = ctrl_c => {}, _ = terminate => {} }
}

/// A connection that required TLS and failed is reported as PHP reports it.
fn tls_connection_error(settings: &Settings, error: RuntimeError) -> Box<dyn Error> {
    if settings.required() && matches!(error, RuntimeError::Database(_)) {
        return Box::new(database_tls::CONNECTION_FAILED);
    }
    Box::new(error)
}

fn postgres_options() -> Result<PgConnectOptions, Box<dyn Error>> {
    let (tls, url) = tls_settings(Driver::Postgres)?;
    let options = if let Some(url) = url {
        // Parsing errors must not echo a connection URL or password.
        PgConnectOptions::from_str(&url).map_err(|_| "invalid DB_URL")?
    } else {
        let database = env::var("DB_DATABASE").map_err(|_| "DB_DATABASE is required")?;
        let username = env::var("DB_USERNAME").map_err(|_| "DB_USERNAME is required")?;
        let port = env::var("DB_PORT")
            .unwrap_or_else(|_| "5432".into())
            .parse::<u16>()?;
        PgConnectOptions::new()
            .host(&env::var("DB_HOST").unwrap_or_else(|_| "127.0.0.1".into()))
            .port(port)
            .database(&database)
            .username(&username)
            .password(&env::var("DB_PASSWORD").unwrap_or_default())
    };
    let options = options.ssl_mode(tls.postgres_mode());
    Ok(match &tls.root_cert {
        Some(path) => options.ssl_root_cert(path),
        None => options,
    })
}

fn mysql_options() -> Result<MySqlConnectOptions, Box<dyn Error>> {
    let (tls, url) = tls_settings(Driver::MySql)?;
    let options = if let Some(url) = url {
        MySqlConnectOptions::from_str(&url).map_err(|_| "invalid DB_URL")?
    } else {
        let database = env::var("DB_DATABASE").map_err(|_| "DB_DATABASE is required")?;
        let username = env::var("DB_USERNAME").map_err(|_| "DB_USERNAME is required")?;
        let port = env::var("DB_PORT")
            .unwrap_or_else(|_| "3306".into())
            .parse::<u16>()?;
        MySqlConnectOptions::new()
            .host(&env::var("DB_HOST").unwrap_or_else(|_| "127.0.0.1".into()))
            .port(port)
            .database(&database)
            .username(&username)
            .password(&env::var("DB_PASSWORD").unwrap_or_default())
    };
    let options = options.ssl_mode(tls.mysql_mode());
    Ok(match &tls.root_cert {
        Some(path) => options.ssl_ca(path),
        None => options,
    })
}

fn auth_value(key: &str) -> Result<Option<String>, Box<dyn Error>> {
    match env::var(key) {
        Ok(value) => Ok(Some(value)),
        Err(env::VarError::NotPresent) => Ok(None),
        Err(env::VarError::NotUnicode(_)) => {
            Err("unsupported development authentication configuration".into())
        }
    }
}

/// `EnvAuditor::env`: the primary name unless Laravel's `env()` reads it as
/// null, else PHP's legacy `WORKFLOW_SERVER_*` name, as PHP's `(string)`.
fn auth_setting(primary: &str, legacy: &str) -> Result<Option<String>, Box<dyn Error>> {
    auth_value(primary)?;
    auth_value(legacy)?;
    Ok(
        config_audit::effective(|key| env::var(key).ok(), primary, Some(legacy)).and_then(|value| match value {
            PhpValue::Null => None,
            value => Some(value.string().to_owned()),
        }),
    )
}

/// The process environment as PHP's `env:audit` sees it. Non-UTF-8 values
/// are compared lossily; the settings native reads itself refuse them.
fn environment_snapshot() -> BTreeMap<String, String> {
    env::vars_os()
        .filter_map(|(name, value)| Some((name.into_string().ok()?, value.to_string_lossy().into_owned())))
        .collect()
}

/// `server.auth.driver`: DW-primary, WORKFLOW-legacy, token by default.
fn anonymous_driver() -> Result<bool, Box<dyn Error>> {
    Ok(auth_setting("DW_AUTH_DRIVER", "WORKFLOW_SERVER_AUTH_DRIVER")?.as_deref() == Some("none"))
}

/// `server.auth.runtime_credentials.enabled`: PHP's `FILTER_VALIDATE_BOOLEAN`
/// with `FILTER_NULL_ON_FAILURE`, so an unrecognized value disables it.
fn runtime_credentials_enabled() -> Result<bool, Box<dyn Error>> {
    auth_value("DW_RUNTIME_CREDENTIALS_ENABLED")?;
    auth_value("WORKFLOW_SERVER_RUNTIME_CREDENTIALS_ENABLED")?;
    Ok(config_audit::effective(
        |key| env::var(key).ok(),
        "DW_RUNTIME_CREDENTIALS_ENABLED",
        Some("WORKFLOW_SERVER_RUNTIME_CREDENTIALS_ENABLED"),
    )
    .and_then(|value| value.filter_bool())
    .unwrap_or(false))
}

/// The contract audit refuses unqualified auth providers, principal tokens,
/// forwarded attribution and disabled backward compatibility. Only the token
/// and none drivers are qualified.
fn validate_auth_configuration() -> Result<(), Box<dyn Error>> {
    // The contract audit compares this flag lossily; refuse a value that is
    // not text before it could be read as PHP's default.
    auth_value("DW_AUTH_BACKWARD_COMPATIBLE")?;
    auth_value("WORKFLOW_SERVER_AUTH_BACKWARD_COMPATIBLE")?;
    if let Some(driver) = auth_setting("DW_AUTH_DRIVER", "WORKFLOW_SERVER_AUTH_DRIVER")? {
        auth_driver_allowed(&driver, release_build())?;
    }
    Ok(())
}

/// The token driver always; the `none` driver, which admits every request as
/// an administrator, only in a development build. A release build refuses it
/// under either name before storage opens or the listener binds.
fn auth_driver_allowed(driver: &str, release: bool) -> Result<(), &'static str> {
    match driver {
        "token" => Ok(()),
        "none" if !release => Ok(()),
        "none" => Err("DW_AUTH_DRIVER=none is refused in a release build; it admits every request as an administrator"),
        _ => Err("unsupported development authentication configuration"),
    }
}

/// Stack for each runtime thread, workers and blocking threads alike. A
/// request body may nest 511 levels, as PHP's `json_decode` allows, and its
/// handlers walk it recursively; the Avro encoder (`apache-avro`) is the
/// deepest user, about 80 KiB per level in a debug build. The stack is
/// reserved address space, not committed memory, so resident memory does not
/// grow with it.
const THREAD_STACK_BYTES: usize = 64 * 1024 * 1024;

fn main() -> Result<(), Box<dyn Error>> {
    tokio::runtime::Builder::new_multi_thread()
        .worker_threads(2)
        .thread_stack_size(THREAD_STACK_BYTES)
        .enable_all()
        .build()?
        .block_on(run())
}

/// A release build carries the version of its `native-` tag, built in as
/// `DW_SERVER_VERSION`, and starts without the opt-in. A development build
/// (no release version) still requires `DW_RUST_EXPERIMENTAL=1`, so an
/// untagged source build is never mistaken for a qualified release.
fn release_build() -> bool {
    option_env!("DW_SERVER_VERSION").is_some_and(|version| !version.is_empty())
}

async fn run() -> Result<(), Box<dyn Error>> {
    if !release_build() && env::var("DW_RUST_EXPERIMENTAL").as_deref() != Ok("1") {
        return Err("Rust development build requires DW_RUST_EXPERIMENTAL=1; release builds do not".into());
    }
    if let Some(command) = env::args().nth(1) {
        if env::args().count() != 2
            || !matches!(
                command.as_str(),
                "schema-bootstrap"
                    | "schema-check"
                    | "upgrade-inspect"
                    | "upgrade-queue-inspect"
                    | "upgrade-payload-inspect"
                    | "upgrade-fingerprint"
                    | "upgrade-prepare-sqlite"
                    | "upgrade-takeover"
            )
        {
            return Err("unknown development command".into());
        }
        let backend = env::var("DB_CONNECTION").unwrap_or_default();
        if command == "upgrade-prepare-sqlite" {
            if backend != "sqlite" {
                return Err("upgrade-prepare-sqlite requires DB_CONNECTION=sqlite".into());
            }
            let path = env::var("DB_DATABASE").map_err(|_| "DB_DATABASE is required")?;
            return match durable_workflow_server::runtime::upgrade::prepare_sqlite_source(&path).await
            {
                Ok(report) => {
                    println!("{report}");
                    Ok(())
                }
                Err(error) => {
                    let reason = match error {
                        RuntimeError::Refused { reason, .. } => reason,
                        _ => "upgrade_prepare_unavailable",
                    };
                    eprintln!("{reason}");
                    Err("SQLite source preparation refused".into())
                }
            };
        }
        if command == "upgrade-takeover" {
            use durable_workflow_server::runtime::upgrade::{
                queue_binding::QueueSourceConfig, takeover,
            };
            if !matches!(backend.as_str(), "sqlite" | "pgsql" | "mysql" | "mariadb") {
                eprintln!("upgrade_takeover_backend_unqualified");
                return Err("upgrade takeover refused".into());
            }
            let fingerprint = env::var("DW_UPGRADE_BACKUP_FINGERPRINT")
                .map_err(|_| "DW_UPGRADE_BACKUP_FINGERPRINT is required")?;
            // The original Laravel queue transport, as for upgrade-queue-inspect.
            let (connection, default_connection, default_queue) = (
                env::var("DW_RUST_UPGRADE_QUEUE_CONNECTION")
                    .map_err(|_| "original queue connection is required")?,
                env::var("DW_RUST_UPGRADE_QUEUE_DEFAULT_CONNECTION")
                    .map_err(|_| "original default queue connection is required")?,
                env::var("DW_RUST_UPGRADE_QUEUE_DEFAULT_QUEUE")
                    .map_err(|_| "original default transport queue is required")?,
            );
            let queues = QueueSourceConfig {
                connection: &connection,
                default_connection: &default_connection,
                default_queue: &default_queue,
            };
            let result = if backend == "pgsql" {
                takeover::take_over_postgres(postgres_options()?, &fingerprint, &queues).await
            } else if backend != "sqlite" {
                takeover::take_over_mysql(mysql_options()?, &fingerprint, &queues).await
            } else {
                let path = env::var("DB_DATABASE").map_err(|_| "DB_DATABASE is required")?;
                takeover::take_over_sqlite(&path, &fingerprint, &queues).await
            };
            return match result {
                Ok(report) => {
                    println!("{report}");
                    Ok(())
                }
                Err(error) => {
                    let reason = match error {
                        RuntimeError::Refused { reason, .. } => reason,
                        _ => "upgrade_takeover_unavailable",
                    };
                    eprintln!("{reason}");
                    Err("upgrade takeover refused".into())
                }
            };
        }
        if command == "upgrade-fingerprint" {
            use durable_workflow_server::runtime::upgrade::fingerprint;
            let result = match backend.as_str() {
                "sqlite" => {
                    let path = env::var("DB_DATABASE").map_err(|_| "DB_DATABASE is required")?;
                    fingerprint::fingerprint_sqlite_cli(&path).await
                }
                "mysql" | "mariadb" => fingerprint::fingerprint_mysql(mysql_options()?).await,
                "pgsql" => fingerprint::fingerprint_postgres(postgres_options()?).await,
                _ => {
                    return Err(
                        "upgrade fingerprint requires DB_CONNECTION=sqlite, pgsql, mysql or mariadb"
                            .into(),
                    );
                }
            };
            return match result {
                Ok(report) => {
                    println!("{report}");
                    Ok(())
                }
                Err(error) => {
                    let reason = match error {
                        RuntimeError::Refused { reason, .. } => reason,
                        _ => "upgrade_fingerprint_unavailable",
                    };
                    eprintln!("{reason}");
                    Err("upgrade source fingerprint refused".into())
                }
            };
        }
        if matches!(
            command.as_str(),
            "upgrade-inspect" | "upgrade-queue-inspect" | "upgrade-payload-inspect"
        ) {
            use durable_workflow_server::runtime::upgrade::{
                self, queue_binding::QueueSourceConfig,
            };
            // Apache's allocation setting is process-global and write-once.
            // This standalone command sets its small ceiling before any Avro
            // decoder is constructed; the HTTP runtime keeps its own policy.
            if command == "upgrade-payload-inspect"
                && apache_avro::util::max_allocation_bytes(65536) != 65536
            {
                return Err("upgrade_inline_codec_budget_unavailable".into());
            }
            // These describe the original Laravel queue transport, not SDK
            // task queues or a newly selected native execution destination.
            let queue_options = if command == "upgrade-queue-inspect" {
                Some((
                    env::var("DW_RUST_UPGRADE_QUEUE_CONNECTION")
                        .map_err(|_| "original queue connection is required")?,
                    env::var("DW_RUST_UPGRADE_QUEUE_DEFAULT_CONNECTION")
                        .map_err(|_| "original default queue connection is required")?,
                    env::var("DW_RUST_UPGRADE_QUEUE_DEFAULT_QUEUE")
                        .map_err(|_| "original default transport queue is required")?,
                ))
            } else {
                None
            };
            let queues =
                queue_options
                    .as_ref()
                    .map(
                        |(connection, default_connection, default_queue)| QueueSourceConfig {
                            connection,
                            default_connection,
                            default_queue,
                        },
                    );
            let result = match backend.as_str() {
                "sqlite" => {
                    let path = env::var("DB_DATABASE").map_err(|_| "DB_DATABASE is required")?;
                    upgrade::inspect_sqlite_cli(
                        &path,
                        queues.as_ref(),
                        command == "upgrade-payload-inspect",
                    )
                    .await
                }
                "mysql" | "mariadb" => {
                    if command == "upgrade-payload-inspect" {
                        upgrade::inspect_mysql_payloads(mysql_options()?).await
                    } else {
                        match queues.as_ref() {
                            Some(config) => {
                                upgrade::inspect_mysql_queues(mysql_options()?, config).await
                            }
                            None => upgrade::inspect_mysql(mysql_options()?).await,
                        }
                    }
                }
                "pgsql" => {
                    if command == "upgrade-payload-inspect" {
                        upgrade::inspect_postgres_payloads(postgres_options()?).await
                    } else {
                        match queues.as_ref() {
                            Some(config) => {
                                upgrade::inspect_postgres_queues(postgres_options()?, config).await
                            }
                            None => upgrade::inspect_postgres(postgres_options()?).await,
                        }
                    }
                }
                _ => {
                    return Err(
                        "upgrade inspection requires DB_CONNECTION=sqlite, pgsql, mysql or mariadb"
                            .into(),
                    );
                }
            };
            match result {
                Ok(report) => {
                    println!("{report}");
                    if report["status"] == "unqualified" {
                        eprintln!("upgrade_source_payload_metadata_unqualified");
                        return Err("upgrade source inspection refused".into());
                    }
                }
                Err(error) => {
                    let reason = match error {
                        RuntimeError::Refused { reason, .. } => reason,
                        _ => "upgrade_inspection_unavailable",
                    };
                    eprintln!("{reason}");
                    return Err("upgrade source inspection refused".into());
                }
            }
            return Ok(());
        }
        let result = match backend.as_str() {
            "pgsql" => {
                let options = postgres_options()?;
                if command == "schema-check" {
                    PostgresStorage::check(options).await
                } else {
                    match PostgresStorage::open(options).await {
                        Ok(storage) => {
                            storage.close().await;
                            Ok(())
                        }
                        Err(error) => Err(error),
                    }
                }
            }
            "mysql" | "mariadb" => {
                let options = mysql_options()?;
                if command == "schema-check" {
                    MySqlStorage::check(options).await
                } else {
                    match MySqlStorage::open(options).await {
                        Ok(storage) => {
                            storage.close().await;
                            Ok(())
                        }
                        Err(error) => Err(error),
                    }
                }
            }
            _ => return Err(
                "these development schema commands require DB_CONNECTION=pgsql, mysql or mariadb"
                    .into(),
            ),
        };
        if let Err(error) = result {
            let driver = if backend == "pgsql" {
                Driver::Postgres
            } else {
                Driver::MySql
            };
            let tls_required = tls_settings(driver).is_ok_and(|(tls, _)| tls.required());
            let reason = match error {
                RuntimeError::Refused { reason, .. } => reason,
                RuntimeError::Database(_) if tls_required => database_tls::CONNECTION_FAILED.reason,
                _ => "storage_unavailable",
            };
            eprintln!("{reason}");
            return Err("development schema command refused".into());
        }
        println!("{{\"backend\":\"{backend}\",\"schema_version\":2,\"status\":\"ready\"}}");
        return Ok(());
    }
    // PHP's `env:audit` warnings, plus the contract settings whose value
    // native cannot honor.
    let audit = config_audit::audit(&environment_snapshot());
    for warning in &audit.warnings {
        eprintln!("{warning}");
    }
    if let Some(refusal) = audit.refusal() {
        return Err(refusal.into());
    }
    validate_auth_configuration()?;
    // Every authentication setting is read before storage is selected, so an
    // unreadable value is refused as authentication configuration.
    let (anonymous, runtime_credentials) = (anonymous_driver()?, runtime_credentials_enabled()?);
    // Published PHP reads each token under its `DW_*` name, or else its
    // legacy `WORKFLOW_SERVER_*` name, so an unchanged deployment starts.
    let worker_token = auth_setting("DW_WORKER_TOKEN", "WORKFLOW_SERVER_WORKER_TOKEN")?;
    let operator_token = auth_setting("DW_OPERATOR_TOKEN", "WORKFLOW_SERVER_OPERATOR_TOKEN")?;
    let admin_token = auth_setting("DW_ADMIN_TOKEN", "WORKFLOW_SERVER_ADMIN_TOKEN")?;
    let token = auth_setting("DW_AUTH_TOKEN", "WORKFLOW_SERVER_AUTH_TOKEN")?
        .ok_or("DW_AUTH_TOKEN (or WORKFLOW_SERVER_AUTH_TOKEN) is required")?;
    if token.trim().is_empty() {
        return Err("DW_AUTH_TOKEN must not be empty".into());
    }
    let address: SocketAddr = env::var("DW_BIND_ADDRESS")
        .unwrap_or_else(|_| "127.0.0.1:8080".into())
        .parse()?;
    let runtime = match env::var("DB_CONNECTION")
        .unwrap_or_else(|_| "sqlite".into())
        .as_str()
    {
        "sqlite" => {
            let database = env::var("DB_DATABASE").map_err(|_| "DB_DATABASE is required")?;
            Runtime::open(&database, token).await?
        }
        "pgsql" => {
            let (tls, _) = tls_settings(Driver::Postgres)?;
            let options = postgres_options()?;
            let Some(runtime) =
                open_when_reachable(|| Runtime::open_postgres(options.clone(), token.clone()))
                    .await
                    .map_err(|error| tls_connection_error(&tls, error))?
            else {
                return Ok(());
            };
            database_tls::record(&tls);
            runtime
        }
        "mysql" | "mariadb" => {
            let (tls, _) = tls_settings(Driver::MySql)?;
            let options = mysql_options()?;
            let Some(runtime) =
                open_when_reachable(|| Runtime::open_mysql(options.clone(), token.clone()))
                    .await
                    .map_err(|error| tls_connection_error(&tls, error))?
            else {
                return Ok(());
            };
            database_tls::record(&tls);
            runtime
        }
        _ => {
            return Err("unsupported development database".into());
        }
    };
    let runtime = runtime
        .with_role_tokens(worker_token, operator_token, admin_token)
        .with_auth_options(anonymous, runtime_credentials);
    let listener = tokio::net::TcpListener::bind(address).await?;
    eprintln!(
        "Rust development runtime listening on {}",
        listener.local_addr()?
    );
    axum::serve(
        listener,
        router(runtime.clone()).into_make_service_with_connect_info::<std::net::SocketAddr>(),
    )
    .with_graceful_shutdown(shutdown_signal())
    .await?;
    runtime.close().await;
    Ok(())
}

#[cfg(test)]
mod startup_tests {
    use super::*;

    #[test]
    fn only_an_unreachable_database_is_awaited() {
        let refused = || std::io::Error::from(std::io::ErrorKind::ConnectionRefused);
        assert!(unreachable(&RuntimeError::Database(sqlx::Error::Io(refused()))));
        assert!(unreachable(&RuntimeError::Database(sqlx::Error::PoolTimedOut)));
        assert!(unreachable(&RuntimeError::Migration(
            sqlx::migrate::MigrateError::Execute(sqlx::Error::Io(refused()))
        )));
        assert!(!unreachable(&RuntimeError::Database(sqlx::Error::Configuration(
            "bad url".into()
        ))));
        assert!(!unreachable(&RuntimeError::Database(sqlx::Error::RowNotFound)));
        assert!(!unreachable(&RuntimeError::Io(refused())));
    }

    #[test]
    fn a_release_build_refuses_the_none_driver() {
        assert_eq!(auth_driver_allowed("token", true), Ok(()));
        assert_eq!(auth_driver_allowed("token", false), Ok(()));
        assert_eq!(auth_driver_allowed("none", false), Ok(()));
        assert!(auth_driver_allowed("none", true).unwrap_err().contains("refused in a release build"));
        assert!(auth_driver_allowed("signature", true).is_err());
        assert!(auth_driver_allowed("signature", false).is_err());
    }
}

