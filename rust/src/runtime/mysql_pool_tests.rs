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

const COM_STMT_CLOSE: u8 = 0x19;

/// Forward clients to `upstream`, recording each TCP read chunk received from
/// a client. TCP is a byte stream: a chunk boundary shows bytes that arrived
/// separately, not a write call.
async fn record_client_chunks(
    listener: TcpListener,
    upstream: String,
    writes: Arc<std::sync::Mutex<Vec<Vec<u8>>>>,
) {
    loop {
        let (client, _) = listener.accept().await.unwrap();
        let server = TcpStream::connect(&upstream).await.unwrap();
        let writes = writes.clone();
        tokio::spawn(async move {
            let (mut client_read, mut client_write) = client.into_split();
            let (mut server_read, mut server_write) = server.into_split();
            let upstream = async {
                let mut bytes = vec![0; 65536];
                loop {
                    let count = client_read.read(&mut bytes).await?;
                    if count == 0 {
                        break;
                    }
                    writes.lock().unwrap().push(bytes[..count].to_vec());
                    server_write.write_all(&bytes[..count]).await?;
                }
                server_write.shutdown().await
            };
            let downstream = async {
                tokio::io::copy(&mut server_read, &mut client_write).await?;
                client_write.shutdown().await
            };
            let _: (std::io::Result<()>, std::io::Result<()>) = tokio::join!(upstream, downstream);
        });
    }
}

/// Each client command packet (sequence 0): its command byte, and whether a
/// received chunk ended with it.
fn recorded_commands(writes: &[Vec<u8>]) -> Vec<(u8, bool)> {
    let mut stream = Vec::new();
    let mut write_ends = std::collections::BTreeSet::new();
    for write in writes {
        stream.extend_from_slice(write);
        write_ends.insert(stream.len());
    }
    let mut commands = Vec::new();
    let mut at = 0;
    while at + 4 <= stream.len() {
        let length = usize::from(stream[at])
            | usize::from(stream[at + 1]) << 8
            | usize::from(stream[at + 2]) << 16;
        let end = at + 4 + length;
        if end > stream.len() {
            break;
        }
        if stream[at + 3] == 0 && length > 0 {
            commands.push((stream[at + 4], write_ends.contains(&end)));
        }
        at = end;
    }
    commands
}

/// A statement close has no reply. Sent on its own, it leaves
/// unacknowledged data in flight, and Nagle's algorithm then holds the next
/// command until the server's delayed acknowledgement, about 40 ms per close.
/// The proxy then receives the close as a chunk of its own. Under the
/// transition lock those stalls added up to a late timer (#714).
#[tokio::test]
#[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
async fn qualification_statement_close_leaves_with_the_next_command() {
    tokio::time::timeout(Duration::from_secs(15), async {
        let url = std::env::var("DW_TEST_MYSQL_URL").unwrap();
        let direct = MySqlConnectOptions::from_str(&url).unwrap();
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let port = listener.local_addr().unwrap().port();
        let writes = Arc::new(std::sync::Mutex::new(Vec::new()));
        let proxy = tokio::spawn(record_client_chunks(
            listener,
            format!("{}:{}", direct.get_host(), direct.get_port()),
            writes.clone(),
        ));
        // Plain text so the proxy can read packets; one cached statement, so
        // each new statement evicts and closes the previous one.
        let options = direct
            .host("127.0.0.1")
            .port(port)
            .ssl_mode(sqlx::mysql::MySqlSslMode::Disabled)
            .statement_cache_capacity(1);
        let mut connection = MySqlConnection::connect_with(&options).await.unwrap();
        for round in 0..4_i64 {
            for (sql, step) in [("SELECT ? + 1", 1), ("SELECT ? + 2", 2), ("SELECT ? + 3", 3)] {
                let value: i64 = sqlx::query_scalar(sql).bind(round).fetch_one(&mut connection).await.unwrap();
                assert_eq!(value, round + step);
            }
        }
        // An uncached statement is closed right after it executes.
        let value: i64 = sqlx::query_scalar("SELECT ? + 4")
            .bind(1_i64)
            .persistent(false)
            .fetch_one(&mut connection)
            .await
            .unwrap();
        assert_eq!(value, 5);
        let value: i64 = sqlx::query_scalar("SELECT ? + 5").bind(1_i64).fetch_one(&mut connection).await.unwrap();
        assert_eq!(value, 6);
        connection.close().await.unwrap();
        proxy.abort();
        let commands = recorded_commands(&writes.lock().unwrap());
        let closes = commands.iter().filter(|(command, _)| *command == COM_STMT_CLOSE).count();
        assert!(closes >= 12, "eviction and uncached closes were exercised: {closes}");
        assert!(
            commands.iter().all(|(command, ended)| *command != COM_STMT_CLOSE || !ended),
            "a statement close arrived as a chunk of its own: {commands:?}"
        );
    })
    .await
    .expect("bounded statement-close chunk qualification");
}

/// A plaintext connection through a recording proxy.
async fn proxied_connection(
    capacity: usize,
) -> (MySqlConnection, Arc<std::sync::Mutex<Vec<Vec<u8>>>>, tokio::task::JoinHandle<()>, MySqlConnectOptions) {
    let url = std::env::var("DW_TEST_MYSQL_URL").unwrap();
    let direct = MySqlConnectOptions::from_str(&url).unwrap();
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let writes = Arc::new(std::sync::Mutex::new(Vec::new()));
    let proxy = tokio::spawn(record_client_chunks(
        listener,
        format!("{}:{}", direct.get_host(), direct.get_port()),
        writes.clone(),
    ));
    let options = direct
        .clone()
        .host("127.0.0.1")
        .port(port)
        .ssl_mode(sqlx::mysql::MySqlSslMode::Disabled)
        .statement_cache_capacity(capacity);
    (MySqlConnection::connect_with(&options).await.unwrap(), writes, proxy, direct)
}

fn closes(writes: &std::sync::Mutex<Vec<Vec<u8>>>) -> usize {
    recorded_commands(&writes.lock().unwrap())
        .iter()
        .filter(|(command, _)| *command == COM_STMT_CLOSE)
        .count()
}

/// Wait, bounded, until the proxy has received `count` statement closes.
async fn closes_arrive(writes: &std::sync::Mutex<Vec<Vec<u8>>>, count: usize) {
    let deadline = tokio::time::Instant::now() + Duration::from_secs(5);
    while closes(writes) < count {
        assert!(
            tokio::time::Instant::now() < deadline,
            "{count} statement closes did not leave the client: {:?}",
            recorded_commands(&writes.lock().unwrap())
        );
        tokio::time::sleep(Duration::from_millis(10)).await;
    }
}

/// `Connection::flush` sends queued closes by itself: no later command is
/// needed, and the connection reports nothing left to flush.
#[tokio::test]
#[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
async fn qualification_explicit_flush_sends_queued_statement_closes() {
    use sqlx::Connection as _;
    tokio::time::timeout(Duration::from_secs(15), async {
        let (mut connection, writes, proxy, _) = proxied_connection(1).await;
        // An uncached statement queues its close after it executes.
        let value: i64 = sqlx::query_scalar("SELECT ? + 4")
            .bind(1_i64)
            .persistent(false)
            .fetch_one(&mut connection)
            .await
            .unwrap();
        assert_eq!(value, 5);
        assert!(connection.should_flush(), "the close is queued");
        tokio::time::sleep(Duration::from_millis(200)).await;
        assert_eq!(closes(&writes), 0, "the queued close waits for a command or flush");
        connection.flush().await.unwrap();
        assert!(!connection.should_flush(), "an explicit flush leaves nothing buffered");
        closes_arrive(&writes, 1).await;
        connection.close().await.unwrap();
        proxy.abort();
    })
    .await
    .expect("bounded explicit-flush qualification");
}

/// `clear_cached_statements` closes every cached statement on the server
/// before it returns, without a later command.
#[tokio::test]
#[ignore = "requires disposable MySQL/MariaDB; run qualification_ explicitly"]
async fn qualification_clearing_the_statement_cache_closes_them_now() {
    use sqlx::Connection as _;
    tokio::time::timeout(Duration::from_secs(15), async {
        let (mut connection, writes, proxy, direct) = proxied_connection(100).await;
        // A separate session can see this connection's server-side
        // statements where the performance schema is on (MySQL 8 default).
        let thread: u64 = sqlx::query_scalar("SELECT CONNECTION_ID()").fetch_one(&mut connection).await.unwrap();
        connection.clear_cached_statements().await.unwrap();
        let before = closes(&writes);
        for sql in ["SELECT ? + 1", "SELECT ? + 2", "SELECT ? + 3"] {
            let _: i64 = sqlx::query_scalar(sql).bind(1_i64).fetch_one(&mut connection).await.unwrap();
        }
        assert_eq!(connection.cached_statements_size(), 3);
        let mut observer = MySqlConnection::connect_with(&direct).await.unwrap();
        let schema: i64 = sqlx::query_scalar("SELECT @@performance_schema").fetch_one(&mut observer).await.unwrap();
        let server_statements = async |observer: &mut MySqlConnection| -> i64 {
            sqlx::query_scalar("SELECT COUNT(*) FROM performance_schema.prepared_statements_instances p JOIN performance_schema.threads t ON t.THREAD_ID=p.OWNER_THREAD_ID WHERE t.PROCESSLIST_ID=?")
                .bind(thread)
                .fetch_one(observer)
                .await
                .unwrap()
        };
        if schema == 1 {
            let held = server_statements(&mut observer).await;
            assert_eq!(held, 3, "the server holds this connection's three statements");
        }
        connection.clear_cached_statements().await.unwrap();
        assert_eq!(connection.cached_statements_size(), 0);
        assert!(!connection.should_flush(), "clearing leaves nothing buffered");
        closes_arrive(&writes, before + 3).await;
        if schema == 1 {
            let deadline = tokio::time::Instant::now() + Duration::from_secs(5);
            while server_statements(&mut observer).await != 0 {
                assert!(tokio::time::Instant::now() < deadline, "the server still holds the statements");
                tokio::time::sleep(Duration::from_millis(10)).await;
            }
        }
        observer.close().await.unwrap();
        connection.close().await.unwrap();
        proxy.abort();
    })
    .await
    .expect("bounded statement-cache clear qualification");
}
