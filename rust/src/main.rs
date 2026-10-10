use std::{env, error::Error, net::SocketAddr, str::FromStr};

use durable_workflow_server::runtime::{
    Runtime, RuntimeError, mysql::MySqlStorage, postgres::PostgresStorage, router,
};
use sqlx::mysql::{MySqlConnectOptions, MySqlSslMode};
use sqlx::postgres::{PgConnectOptions, PgSslMode};

fn postgres_options() -> Result<PgConnectOptions, Box<dyn Error>> {
    if let Ok(url) = env::var("DB_URL") {
        // Parsing errors must not echo a connection URL or password.
        return PgConnectOptions::from_str(&url).map_err(|_| "invalid DB_URL".into());
    }
    let database = env::var("DB_DATABASE").map_err(|_| "DB_DATABASE is required")?;
    let username = env::var("DB_USERNAME").map_err(|_| "DB_USERNAME is required")?;
    let port = env::var("DB_PORT")
        .unwrap_or_else(|_| "5432".into())
        .parse::<u16>()?;
    let ssl = PgSslMode::from_str(&env::var("DB_SSLMODE").unwrap_or_else(|_| "prefer".into()))
        .map_err(|_| "invalid DB_SSLMODE")?;
    let options = PgConnectOptions::new()
        .host(&env::var("DB_HOST").unwrap_or_else(|_| "127.0.0.1".into()))
        .port(port)
        .database(&database)
        .username(&username)
        .password(&env::var("DB_PASSWORD").unwrap_or_default())
        .ssl_mode(ssl);
    Ok(match env::var("DB_SSLROOTCERT") {
        Ok(path) => options.ssl_root_cert(path),
        Err(_) => options,
    })
}

fn mysql_options() -> Result<MySqlConnectOptions, Box<dyn Error>> {
    if let Ok(url) = env::var("DB_URL") {
        return MySqlConnectOptions::from_str(&url).map_err(|_| "invalid DB_URL".into());
    }
    let database = env::var("DB_DATABASE").map_err(|_| "DB_DATABASE is required")?;
    let username = env::var("DB_USERNAME").map_err(|_| "DB_USERNAME is required")?;
    let port = env::var("DB_PORT")
        .unwrap_or_else(|_| "3306".into())
        .parse::<u16>()?;
    let ssl =
        MySqlSslMode::from_str(&env::var("DB_SSLMODE").unwrap_or_else(|_| "preferred".into()))
            .map_err(|_| "invalid DB_SSLMODE")?;
    let options = MySqlConnectOptions::new()
        .host(&env::var("DB_HOST").unwrap_or_else(|_| "127.0.0.1".into()))
        .port(port)
        .database(&database)
        .username(&username)
        .password(&env::var("DB_PASSWORD").unwrap_or_default())
        .ssl_mode(ssl);
    Ok(match env::var("DB_SSLROOTCERT") {
        Ok(path) => options.ssl_ca(path),
        Err(_) => options,
    })
}

#[tokio::main(worker_threads = 2)]
async fn main() -> Result<(), Box<dyn Error>> {
    if env::var("DW_RUST_EXPERIMENTAL").as_deref() != Ok("1") {
        return Err("Rust development runtime requires DW_RUST_EXPERIMENTAL=1; it is not a qualified replacement".into());
    }
    if let Some(command) = env::args().nth(1) {
        if env::args().count() != 2
            || !matches!(command.as_str(), "schema-bootstrap" | "schema-check")
        {
            return Err("unknown development command".into());
        }
        let backend = env::var("DB_CONNECTION").unwrap_or_default();
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
            let reason = match error {
                RuntimeError::Refused { reason, .. } => reason,
                _ => "storage_unavailable",
            };
            eprintln!("{reason}");
            return Err("development schema command refused".into());
        }
        println!("{{\"backend\":\"{backend}\",\"schema_version\":2,\"status\":\"ready\"}}");
        return Ok(());
    }
    let token = env::var("DW_AUTH_TOKEN").map_err(|_| "DW_AUTH_TOKEN is required")?;
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
        "pgsql" => Runtime::open_postgres(postgres_options()?, token).await?,
        "mysql" | "mariadb" => Runtime::open_mysql(mysql_options()?, token).await?,
        _ => {
            return Err("unsupported development database".into());
        }
    };
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
