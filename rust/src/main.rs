use std::{env, error::Error, net::SocketAddr};

use durable_workflow_server::runtime::{Runtime, router};

#[tokio::main(worker_threads = 2)]
async fn main() -> Result<(), Box<dyn Error>> {
    if env::var("DW_RUST_EXPERIMENTAL").as_deref() != Ok("1") {
        return Err("Rust development runtime requires DW_RUST_EXPERIMENTAL=1; it is not a qualified replacement".into());
    }
    if env::var("DB_CONNECTION").unwrap_or_else(|_| "sqlite".into()) != "sqlite" {
        return Err("this development slice implements SQLite; MySQL/PostgreSQL remain required port gates".into());
    }
    let token = env::var("DW_AUTH_TOKEN").map_err(|_| "DW_AUTH_TOKEN is required")?;
    if token.trim().is_empty() {
        return Err("DW_AUTH_TOKEN must not be empty".into());
    }
    let database = env::var("DB_DATABASE").map_err(|_| "DB_DATABASE is required")?;
    let address: SocketAddr = env::var("DW_BIND_ADDRESS")
        .unwrap_or_else(|_| "127.0.0.1:8080".into())
        .parse()?;
    let runtime = Runtime::open(&database, token).await?;
    let listener = tokio::net::TcpListener::bind(address).await?;
    eprintln!("Rust development runtime listening on {}", listener.local_addr()?);
    axum::serve(listener, router(runtime.clone()))
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
