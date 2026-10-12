use super::*;
use sqlx::{ConnectOptions, MySql, migrate::MigrateDatabase};
use std::{
    str::FromStr,
    sync::{
        Arc,
        atomic::{AtomicBool, Ordering},
    },
};
use tokio::{
    io::{AsyncReadExt, AsyncWriteExt},
    net::{TcpListener, TcpStream},
    sync::Notify,
};

#[derive(Default)]
struct ReplyGate {
    hold: AtomicBool,
    blocked: Notify,
    released: Notify,
}

impl ReplyGate {
    fn release(&self) {
        self.hold.store(false, Ordering::SeqCst);
        self.released.notify_waiters();
    }
}

async fn forward(listener: TcpListener, upstream: String, gate: Arc<ReplyGate>) {
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
                tokio::io::copy(&mut client_read, &mut server_write).await?;
                server_write.shutdown().await
            };
            let _: (std::io::Result<()>, std::io::Result<()>) = tokio::join!(downstream, upstream);
        });
    }
}

#[tokio::test]
#[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
async fn qualification_cancelled_pool_boundaries_preserve_fresh_reads_and_commits() {
    tokio::time::timeout(Duration::from_secs(15), async {
        let url = std::env::var("DW_TEST_MYSQL_URL").unwrap();
        let database = format!("dw_pool_{}", ulid::Ulid::new().to_string().to_lowercase());
        let direct = MySqlConnectOptions::from_str(&url)
            .unwrap()
            .database(&database);
        let database_url = direct.to_url_lossy().to_string();
        MySql::create_database(&database_url).await.unwrap();
        let mut writer = MySqlConnection::connect_with(&direct).await.unwrap();
        writer
            .execute("CREATE TABLE receipt_probe(id BIGINT PRIMARY KEY) ENGINE=InnoDB")
            .await
            .unwrap();
        writer
            .execute("INSERT INTO receipt_probe VALUES (1)")
            .await
            .unwrap();
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let port = listener.local_addr().unwrap().port();
        let gate = Arc::new(ReplyGate::default());
        let proxy = tokio::spawn(forward(
            listener,
            format!("{}:{}", direct.get_host(), direct.get_port()),
            gate.clone(),
        ));
        // One slot deterministically reuses the returned session. Production
        // uses the same factory and release hook with its unchanged four slots.
        let pool = runtime_pool_options()
            .max_connections(1)
            .connect_with(direct.clone().host("127.0.0.1").port(port))
            .await
            .unwrap();
        let mut connection = pool.acquire().await.unwrap();
        gate.hold.store(true, Ordering::SeqCst);
        let begin = tokio::spawn(async move {
            let transaction = connection.begin().await.unwrap();
            transaction.rollback().await.unwrap();
        });
        gate.blocked.notified().await;
        begin.abort();
        assert!(begin.await.unwrap_err().is_cancelled());
        gate.release();
        let mut connection = pool.acquire().await.unwrap();
        assert!(!connection.is_in_transaction());
        let before: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM receipt_probe WHERE id=2")
            .fetch_one(&mut *connection)
            .await
            .unwrap();
        assert_eq!(before, 0);
        writer
            .execute("INSERT INTO receipt_probe VALUES (2)")
            .await
            .unwrap();
        let fresh: i64 = sqlx::query_scalar("SELECT id FROM receipt_probe WHERE id=2")
            .fetch_one(&mut writer)
            .await
            .unwrap();
        assert_eq!(fresh, 2);
        let reused: i64 = sqlx::query_scalar("SELECT id FROM receipt_probe WHERE id=2")
            .fetch_one(&mut *connection)
            .await
            .expect("returned session must see the independently committed receipt");
        assert_eq!(reused, 2);

        // A normal acknowledged commit survives the pool-return reset.
        let mut transaction = connection.begin().await.unwrap();
        sqlx::query("INSERT INTO receipt_probe VALUES (3)")
            .execute(&mut *transaction)
            .await
            .unwrap();
        transaction.commit().await.unwrap();
        drop(connection);
        let mut connection = pool.acquire().await.unwrap();
        let committed: i64 = sqlx::query_scalar("SELECT id FROM receipt_probe WHERE id=3")
            .fetch_one(&mut *connection)
            .await
            .unwrap();
        assert_eq!(committed, 3);

        // Hold an actual COMMIT reply after the server acknowledges its write
        // to an independent reader. Losing that reply must not undo the row.
        let commit_gate = gate.clone();
        let commit = tokio::spawn(async move {
            let mut transaction = connection.begin().await.unwrap();
            sqlx::query("INSERT INTO receipt_probe VALUES (4)")
                .execute(&mut *transaction)
                .await
                .unwrap();
            commit_gate.hold.store(true, Ordering::SeqCst);
            transaction.commit().await.unwrap();
        });
        gate.blocked.notified().await;
        let visible: i64 = sqlx::query_scalar("SELECT id FROM receipt_probe WHERE id=4")
            .fetch_one(&mut writer)
            .await
            .unwrap();
        assert_eq!(visible, 4, "COMMIT happened before its reply was released");
        commit.abort();
        assert!(commit.await.unwrap_err().is_cancelled());
        gate.release();
        let mut connection = pool.acquire().await.unwrap();
        let preserved: i64 = sqlx::query_scalar("SELECT id FROM receipt_probe WHERE id=4")
            .fetch_one(&mut *connection)
            .await
            .unwrap();
        assert_eq!(
            preserved, 4,
            "reset never rolls back an already committed write"
        );
        let prior_connection: i64 = sqlx::query_scalar("SELECT CAST(CONNECTION_ID() AS SIGNED)")
            .fetch_one(&mut *connection)
            .await
            .unwrap();
        gate.hold.store(true, Ordering::SeqCst);
        let stalled = tokio::spawn(async move {
            let transaction = connection.begin().await.unwrap();
            transaction.rollback().await.unwrap();
        });
        gate.blocked.notified().await;
        stalled.abort();
        assert!(stalled.await.unwrap_err().is_cancelled());
        // Keep the original reply held: the return hook cannot finish its
        // reset. Its actual five-second budget must discard the session.
        tokio::time::timeout(Duration::from_secs(7), async {
            while pool.size() != 0 {
                tokio::time::sleep(Duration::from_millis(20)).await;
            }
        })
        .await
        .expect("stalled reset must release the pool slot");
        gate.release();
        let mut replacement = pool.acquire().await.unwrap();
        let replacement_connection: i64 =
            sqlx::query_scalar("SELECT CAST(CONNECTION_ID() AS SIGNED)")
                .fetch_one(&mut *replacement)
                .await
                .unwrap();
        assert_ne!(
            replacement_connection, prior_connection,
            "a timed-out reset cannot be reused"
        );
        let preserved: i64 = sqlx::query_scalar("SELECT id FROM receipt_probe WHERE id=4")
            .fetch_one(&mut *replacement)
            .await
            .unwrap();
        assert_eq!(preserved, 4);
        drop(replacement);
        pool.close().await;
        writer.close().await.unwrap();
        proxy.abort();
        MySql::drop_database(&database_url).await.unwrap();
    })
    .await
    .expect("bounded real transaction-boundary qualification");
}
