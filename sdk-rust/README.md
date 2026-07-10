# Durable Workflow Rust SDK

`durable-workflow` is the first-party Rust SDK for running Durable Workflow
workers against the standalone server. The v1 surface is intentionally small:
register workflow and activity handlers, long-poll the worker protocol, start
and signal workflow executions, heartbeat workers and activities, and round-trip
JSON-native payloads through the same `avro` generic wrapper used by the PHP and
Python SDKs.

```sh
cargo add durable-workflow@0.1.0 --exact
```

For a reproducible application dependency, pin the exact public crates.io
release in `Cargo.toml`:

```toml
[dependencies]
durable-workflow = "=0.1.0"
```

Version `0.1.0` requires Rust `1.86` or newer and supports Durable Workflow
server `0.2.x`. It targets worker protocol `1.2` and control-plane protocol `2`;
the server's advertised protocol manifests remain authoritative when checking
compatibility at deployment time.

## Worker

```rust
use durable_workflow::{json, Client, Result, Worker};

#[tokio::main]
async fn main() -> Result<()> {
    let client = Client::builder("http://127.0.0.1:8080")
        .token(std::env::var("DURABLE_WORKFLOW_TOKEN").ok())
        .namespace("default")
        .build()?;

    let mut worker = Worker::new(client.clone(), "rust-workers");

    worker.register_activity("hello.activity", |ctx, args| async move {
        ctx.heartbeat(json!({"stage": "started"})).await?;
        let name = args.get(0).and_then(|v| v.as_str()).unwrap_or("world");
        Ok(json!(format!("hello, {name}")))
    });

    worker.register_workflow("hello.workflow", |ctx, _input| async move {
        let signal = ctx.wait_signal("start").await?;
        let name = signal.first().and_then(|v| v.as_str()).unwrap_or("world");
        let greeting = ctx.activity("hello.activity", json!([name])).await?;
        Ok(json!({"greeting": greeting}))
    });

    worker.run().await
}
```

## Client

```rust
let handle = client
    .start_workflow("hello.workflow", "rust-workers", "hello-rust-1", json!([]))
    .await?;

client
    .signal_workflow(&handle.workflow_id, "start", json!(["Rust"]))
    .await?;

let output = handle.result(Default::default()).await?;
```

See `examples/hello_world.rs` for a complete round-trip that registers a Rust
worker, starts a workflow, sends a signal, runs an activity, and waits for the
completed workflow result.

`Worker::on_worker_heartbeat` can observe successful server acknowledgements
for metrics or structured logging. The worker still owns the heartbeat cadence,
using the interval advertised by the server during registration.
