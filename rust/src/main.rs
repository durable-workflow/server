use std::{env, error::Error, net::SocketAddr, str::FromStr};

use durable_workflow_server::database_tls::{self, Driver, Settings};
use durable_workflow_server::runtime::{
    Runtime, RuntimeError, WORKER_PROTOCOL_VERSION, mysql::MySqlStorage, postgres::PostgresStorage,
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

/// `EnvAuditor::env`: the primary name when it is set, else PHP's legacy
/// `WORKFLOW_SERVER_*` name.
fn aliased<F>(lookup: F, primary: &str, legacy: &str) -> Result<Option<String>, Box<dyn Error>>
where
    F: Fn(&str) -> Result<Option<String>, Box<dyn Error>>,
{
    match lookup(primary)? {
        Some(value) => Ok(Some(value)),
        None => lookup(legacy),
    }
}

fn auth_setting(primary: &str, legacy: &str) -> Result<Option<String>, Box<dyn Error>> {
    aliased(auth_value, primary, legacy)
}

fn setting_value(key: &str) -> Result<Option<String>, Box<dyn Error>> {
    match env::var(key) {
        Ok(value) => Ok(Some(value)),
        Err(env::VarError::NotPresent) => Ok(None),
        Err(env::VarError::NotUnicode(_)) => Err(format!("{key} must be valid UTF-8").into()),
    }
}

/// A PHP setting whose value changes behavior that native does not offer.
/// Native refuses to start rather than run with PHP's default silently.
fn unhonored_php_setting<F>(lookup: F) -> Result<Option<String>, Box<dyn Error>>
where
    F: Fn(&str) -> Result<Option<String>, Box<dyn Error>>,
{
    // PHP lowercases the namespace it falls back to; native always falls
    // back to `default`.
    let namespace = aliased(&lookup, "DW_DEFAULT_NAMESPACE", "WORKFLOW_SERVER_DEFAULT_NAMESPACE")?;
    if namespace.is_some_and(|value| value.to_ascii_lowercase() != "default") {
        return Ok(Some(
            "DW_DEFAULT_NAMESPACE must be unset or `default`: native serves `default` to requests that name no namespace"
                .into(),
        ));
    }
    // PHP advertises this value and gates protocol features on it.
    let protocol = aliased(
        &lookup,
        "DW_WORKER_PROTOCOL_VERSION",
        "WORKFLOW_SERVER_WORKER_PROTOCOL_VERSION",
    )?;
    if protocol.is_some_and(|value| value != WORKER_PROTOCOL_VERSION) {
        return Ok(Some(format!(
            "DW_WORKER_PROTOCOL_VERSION must be unset or `{WORKER_PROTOCOL_VERSION}`: native implements only that worker protocol"
        )));
    }
    Ok(None)
}

fn validate_auth_configuration() -> Result<(), Box<dyn Error>> {
    // Unqualified credentials must not silently leave a full-access legacy
    // guard in place. These profiles require their own contract qualification.
    for key in ["DW_AUTH_DRIVER", "WORKFLOW_SERVER_AUTH_DRIVER"] {
        if let Some(value) = auth_value(key)? {
            if value != "token" {
                return Err("unsupported development authentication configuration".into());
            }
        }
    }
    for key in [
        "DW_AUTH_PROVIDER",
        "DW_PRINCIPAL_TOKENS",
        "WORKFLOW_SERVER_AUTH_PROVIDER",
        "WORKFLOW_SERVER_PRINCIPAL_TOKENS",
    ] {
        if auth_value(key)?.is_some_and(|value| !value.is_empty()) {
            return Err("unsupported development authentication configuration".into());
        }
    }
    for key in [
        "DW_RUNTIME_CREDENTIALS_ENABLED",
        "WORKFLOW_SERVER_RUNTIME_CREDENTIALS_ENABLED",
    ] {
        if auth_value(key)?.is_some_and(|value| {
            !matches!(
                value.to_ascii_lowercase().as_str(),
                "" | "0" | "false" | "off" | "no"
            )
        }) {
            return Err("unsupported development authentication configuration".into());
        }
    }
    for key in [
        "DW_AUTH_BACKWARD_COMPATIBLE",
        "WORKFLOW_SERVER_AUTH_BACKWARD_COMPATIBLE",
    ] {
        if auth_value(key)?.is_some_and(|value| {
            !matches!(
                value.to_ascii_lowercase().as_str(),
                "1" | "true" | "on" | "yes"
            )
        }) {
            return Err("unsupported development authentication configuration".into());
        }
    }
    Ok(())
}

#[tokio::main(worker_threads = 2)]
async fn main() -> Result<(), Box<dyn Error>> {
    if env::var("DW_RUST_EXPERIMENTAL").as_deref() != Ok("1") {
        return Err("Rust development runtime requires DW_RUST_EXPERIMENTAL=1; it is not a qualified replacement".into());
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
    validate_auth_configuration()?;
    if let Some(refusal) = unhonored_php_setting(setting_value)? {
        return Err(refusal.into());
    }
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
            let runtime = Runtime::open_postgres(postgres_options()?, token)
                .await
                .map_err(|error| tls_connection_error(&tls, error))?;
            database_tls::record(&tls);
            runtime
        }
        "mysql" | "mariadb" => {
            let (tls, _) = tls_settings(Driver::MySql)?;
            let runtime = Runtime::open_mysql(mysql_options()?, token)
                .await
                .map_err(|error| tls_connection_error(&tls, error))?;
            database_tls::record(&tls);
            runtime
        }
        _ => {
            return Err("unsupported development database".into());
        }
    };
    let runtime = runtime.with_role_tokens(worker_token, operator_token, admin_token);
    let listener = tokio::net::TcpListener::bind(address).await?;
    eprintln!(
        "Rust development runtime listening on {}",
        listener.local_addr()?
    );
    axum::serve(
        listener,
        router(runtime.clone()).into_make_service_with_connect_info::<std::net::SocketAddr>(),
    )
    .with_graceful_shutdown(async {
        let ctrl_c = tokio::signal::ctrl_c();
        #[cfg(unix)]
        let terminate = async {
            tokio::signal::unix::signal(tokio::signal::unix::SignalKind::terminate())
                .expect("install SIGTERM handler")
                .recv()
                .await;
        };
        #[cfg(not(unix))]
        let terminate = std::future::pending::<()>();
        tokio::select! { _ = ctrl_c => {}, _ = terminate => {} }
    })
    .await?;
    runtime.close().await;
    Ok(())
}

#[cfg(test)]
mod auth_env_tests {
    use super::*;

    fn lookup(values: &'static [(&'static str, &'static str)]) -> impl Fn(&str) -> Result<Option<String>, Box<dyn Error>> {
        move |key| Ok(values.iter().find(|(name, _)| *name == key).map(|(_, value)| (*value).to_owned()))
    }

    #[test]
    fn primary_names_win_and_legacy_names_fill_in_like_php() {
        let both = lookup(&[("DW_AUTH_TOKEN", "primary"), ("WORKFLOW_SERVER_AUTH_TOKEN", "legacy")]);
        assert_eq!(aliased(both, "DW_AUTH_TOKEN", "WORKFLOW_SERVER_AUTH_TOKEN").unwrap().as_deref(), Some("primary"));
        let legacy = lookup(&[("WORKFLOW_SERVER_AUTH_TOKEN", "legacy")]);
        assert_eq!(aliased(legacy, "DW_AUTH_TOKEN", "WORKFLOW_SERVER_AUTH_TOKEN").unwrap().as_deref(), Some("legacy"));
        // A set but empty primary still wins, as PHP's env() returns it.
        let empty = lookup(&[("DW_AUTH_TOKEN", ""), ("WORKFLOW_SERVER_AUTH_TOKEN", "legacy")]);
        assert_eq!(aliased(empty, "DW_AUTH_TOKEN", "WORKFLOW_SERVER_AUTH_TOKEN").unwrap().as_deref(), Some(""));
        let neither = lookup(&[]);
        assert_eq!(aliased(neither, "DW_AUTH_TOKEN", "WORKFLOW_SERVER_AUTH_TOKEN").unwrap(), None);
    }

    #[test]
    fn php_settings_native_cannot_honor_refuse_start() {
        let refused =
            |values: &'static [(&'static str, &'static str)]| unhonored_php_setting(lookup(values)).unwrap();
        let accepted: [&'static [(&str, &str)]; 7] = [
            &[],
            &[("DW_DEFAULT_NAMESPACE", "default")],
            &[("DW_DEFAULT_NAMESPACE", "Default")],
            &[("WORKFLOW_SERVER_DEFAULT_NAMESPACE", "default")],
            &[("DW_WORKER_PROTOCOL_VERSION", "1.20")],
            &[("WORKFLOW_SERVER_WORKER_PROTOCOL_VERSION", "1.20")],
            // Settings PHP reads but never applies stay accepted.
            &[("DW_MAX_PENDING_ACTIVITIES", "10"), ("DW_ACTIVITY_TASK_TIMEOUT", "30")],
        ];
        for accepted in accepted {
            assert_eq!(refused(accepted), None, "{accepted:?}");
        }
        let refusals: [(&'static [(&str, &str)], &str); 6] = [
            (&[("DW_DEFAULT_NAMESPACE", "billing")], "DW_DEFAULT_NAMESPACE"),
            (&[("DW_DEFAULT_NAMESPACE", "")], "DW_DEFAULT_NAMESPACE"),
            (&[("WORKFLOW_SERVER_DEFAULT_NAMESPACE", "billing")], "DW_DEFAULT_NAMESPACE"),
            // A set primary wins over a matching legacy value, as in PHP.
            (&[("DW_DEFAULT_NAMESPACE", "billing"), ("WORKFLOW_SERVER_DEFAULT_NAMESPACE", "default")], "DW_DEFAULT_NAMESPACE"),
            (&[("DW_WORKER_PROTOCOL_VERSION", "1.19")], "DW_WORKER_PROTOCOL_VERSION"),
            (&[("WORKFLOW_SERVER_WORKER_PROTOCOL_VERSION", "1.2")], "DW_WORKER_PROTOCOL_VERSION"),
        ];
        for (values, setting) in refusals {
            let refusal = refused(values).unwrap_or_else(|| panic!("{values:?} was accepted"));
            assert!(refusal.starts_with(setting), "{refusal}");
        }
    }
}
