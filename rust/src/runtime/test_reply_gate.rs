//! A TCP proxy that can hold a database's replies (a stall) or, while held,
//! act as a peer that has gone silent. Shared by the MySQL and PostgreSQL
//! pool qualifications (Server #1204, #1355).
use std::sync::{Arc, atomic::{AtomicBool, Ordering}};
use tokio::{
    io::{AsyncReadExt, AsyncWriteExt},
    net::{TcpListener, TcpStream},
    sync::Notify,
};

#[derive(Default)]
pub(crate) struct ReplyGate {
    pub(crate) hold: AtomicBool,
    pub(crate) hold_upstream: AtomicBool,
    pub(crate) blocked: Notify,
    released: Notify,
}

impl ReplyGate {
    pub(crate) fn release(&self) {
        self.hold.store(false, Ordering::SeqCst);
        self.hold_upstream.store(false, Ordering::SeqCst);
        self.released.notify_waiters();
    }
}

pub(crate) async fn forward(listener: TcpListener, upstream: String, gate: Arc<ReplyGate>) {
    loop {
        let (client, _) = listener.accept().await.unwrap();
        let server = TcpStream::connect(&upstream).await.unwrap();
        let gate = gate.clone();
        tokio::spawn(async move {
            let (mut client_read, mut client_write) = client.into_split();
            let (mut server_read, mut server_write) = server.into_split();
            let downstream = async {
                let mut bytes = [0; 65536];
                loop {
                    let count = server_read.read(&mut bytes).await?;
                    if count == 0 {
                        break;
                    }
                    while gate.hold.load(Ordering::SeqCst) {
                        let release = gate.released.notified();
                        gate.blocked.notify_one();
                        if !gate.hold.load(Ordering::SeqCst) {
                            break;
                        }
                        release.await;
                    }
                    client_write.write_all(&bytes[..count]).await?;
                }
                client_write.shutdown().await
            };
            let upstream = async {
                let mut bytes = [0; 65536];
                loop {
                    while gate.hold_upstream.load(Ordering::SeqCst) {
                        let release = gate.released.notified();
                        gate.blocked.notify_one();
                        if !gate.hold_upstream.load(Ordering::SeqCst) {
                            break;
                        }
                        release.await;
                    }
                    let count = client_read.read(&mut bytes).await?;
                    if count == 0 {
                        break;
                    }
                    server_write.write_all(&bytes[..count]).await?;
                }
                server_write.shutdown().await
            };
            let _: (std::io::Result<()>, std::io::Result<()>) = tokio::join!(downstream, upstream);
        });
    }
}

