use std::{fs, io::Write, time::Duration};

use durable_workflow::{Client, Result, Worker};
use serde_json::json;
use sha2::{Digest, Sha256};

#[tokio::main]
async fn main() -> Result<()> {
    let client = Client::builder("http://server:8080")
        .token(Some("storage-fixture".to_string()))
        .build()?;
    let mut worker = Worker::new(client, "storage-rust")
        .worker_id("storage-rust")
        .poll_timeout(Duration::from_secs(1));
    worker.register_workflow("storage.rust", |context, _input| async move {
        let value = context.activity("storage.rust.echo", json!([])).await?;
        let text = value.as_str().expect("fixture activity returns a string");
        Ok(json!({"runtime": "rust", "bytes": text.len(), "sha256": format!("{:x}", Sha256::digest(text.as_bytes()))}))
    });
    worker.register_activity("storage.rust.echo", |_context, _input| async move {
        let value = format!("rust:{}", "x".repeat(262144));
        let mut effects = fs::OpenOptions::new().create(true).append(true)
            .open("/observation/rust.effects").expect("fixture effects file");
        writeln!(effects, "executed").expect("record fixture effect");
        tokio::time::timeout(Duration::from_secs(120), async {
            while !std::path::Path::new("/observation/release-activities").is_file() {
                tokio::time::sleep(Duration::from_millis(100)).await;
            }
        }).await.expect("fixture release barrier timed out");
        fs::write("/observation/rust.returned", "ready").expect("fixture return marker");
        Ok(json!(value))
    });
    worker.run().await
}
