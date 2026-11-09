//! Hold one description after its run row while another connection commits
//! a real termination. The returned run and command journal must agree.
use super::cancellation::TerminalCommand;
use crate::codec::{Value as Payload, ValueCodec};
use serde_json::{Value, json};
use sqlx::Database;
use std::{sync::{Arc, atomic::{AtomicUsize, Ordering}}, time::Duration};
use tokio::sync::Notify;

#[derive(Default)]
struct Gate { reached: Notify, release: Notify }

tokio::task_local! { static AT_RUN_ROW: Arc<Gate>; }

pub(super) async fn after_run_row() {
    if let Ok(gate) = AT_RUN_ROW.try_with(Arc::clone) {
        gate.reached.notify_one();
        gate.release.notified().await;
    }
}

/// One eager connection, with every subsequent checkout counted. A nested
/// pool read while the description owns it would time out, not pass silently.
pub(super) fn reader_pool<DB: Database>(
    options: sqlx::pool::PoolOptions<DB>, checkouts: Arc<AtomicUsize>,
) -> sqlx::pool::PoolOptions<DB> {
    options.max_connections(1).min_connections(1)
        .acquire_timeout(Duration::from_secs(2))
        .before_acquire(move |_, _| {
            checkouts.fetch_add(1, Ordering::Relaxed);
            Box::pin(async { Ok(true) })
        })
}

fn input() -> Value {
    json!({"codec":"avro","blob":ValueCodec::new().unwrap().encode(&Payload::Array(vec![])).unwrap()})
}

store_methods! {
    /// The reader has one pool connection; the writer has an independent pool
    /// on the same database. `ordinary` is the READ COMMITTED counterexample.
    pub(super) async fn qualify_describe_snapshot(
        &self, writer: &Self, checkouts: &AtomicUsize, ordinary: bool,
    ) {
        let workflow_id = format!("describe-snapshot-{}",ulid::Ulid::new());
        let started = writer.start(json!({"workflow_id":workflow_id,"workflow_type":"snapshot","input":input()}),
            super::memos::initial(json!({"sample":"before"})).unwrap(),None,None).await.unwrap();
        let run_id = started["run_id"].as_str().unwrap();
        let before = self.describe_with(&workflow_id,None,Some(60)).await.unwrap();
        let gate = Arc::new(Gate::default());
        checkouts.store(0,Ordering::Relaxed);
        let read = AT_RUN_ROW.scope(gate.clone(),async {
            if ordinary {
                let mut tx = self.begin_read().await?;
                let answer = self.describe_in(&mut tx,&workflow_id,None,Some(60)).await?;
                tx.commit().await?;
                Ok::<_,super::RuntimeError>(answer)
            } else {
                self.describe_with(&workflow_id,None,Some(60)).await
            }
        });
        let write = async {
            gate.reached.notified().await;
            let (status,_) = writer.close_workflow(TerminalCommand::Terminate,&workflow_id,None,None,None,None).await.unwrap();
            assert!(status.is_success());
            gate.release.notify_one();
        };
        let ((answer,reads),()) = tokio::time::timeout(Duration::from_secs(10),async {
            tokio::join!(super::sql_counts::measure(read),write)
        }).await.expect("description and concurrent termination must finish");
        let answer = answer.unwrap();
        assert_eq!(checkouts.load(Ordering::Relaxed),1,"one checkout for the whole description");
        assert_eq!(reads.count(super::run_describe::STARTED_PAYLOAD_SQL),1,"one shared start-payload read");
        assert_eq!(answer["status"],before["status"],"run row was read before the termination");
        if ordinary {
            assert_ne!(answer["commands"],before["commands"],"READ COMMITTED exposes the later journal and mixes generations");
        } else {
            assert_eq!(answer,before,"every section must come from the pre-termination snapshot");
        }
        self.assert_describe_connection_returned().await;
        let fresh = self.describe_with(&workflow_id,Some(run_id),Some(60)).await.unwrap();
        assert_eq!(fresh["status"],"terminated");
        assert_eq!(fresh["commands"].as_array().unwrap().len(),before["commands"].as_array().unwrap().len()+1);

        // Both not-found paths return the only connection, including an
        // explicitly selected run outside the instance's namespace/identity.
        for (workflow,run) in [("missing-instance",None),(workflow_id.as_str(),Some("missing-run"))] {
            assert!(self.describe_with(workflow,run,Some(60)).await.is_err());
            self.assert_describe_connection_returned().await;
        }
        // Cancellation at an actual suspended read must also release it.
        let gate = Arc::new(Gate::default());
        let mut reading = Box::pin(AT_RUN_ROW.scope(gate.clone(),self.describe_with(&workflow_id,None,Some(60))));
        tokio::time::timeout(Duration::from_secs(5),async {
            tokio::select! {
                _ = gate.reached.notified() => {},
                result = &mut reading => panic!("description did not pause: {result:?}"),
            }
        }).await.unwrap();
        assert!(self.pool.try_acquire().is_none(),"description holds the one connection until cancellation");
        drop(reading);
        self.assert_describe_connection_returned().await;

        // A stored-payload decoding failure occurs after the transaction has
        // read real rows, unlike the early not-found refusals above.
        Self::query("UPDATE workflow_memos SET portable_value=$1,portable_value_sequence=upserted_at_sequence WHERE workflow_run_id=$2")
            .bind(DB::document(&json!({"codec":"avro","blob":"invalid"}))).bind(run_id)
            .execute(&writer.pool).await.unwrap();
        assert!(matches!(self.describe_with(&workflow_id,None,Some(60)).await,
            Err(super::RuntimeError::Refused { reason:"unsupported_stored_memo", .. })));
        self.assert_describe_connection_returned().await;
    }

    async fn assert_describe_connection_returned(&self) {
        tokio::time::timeout(Duration::from_secs(3),async {
            let mut connection = self.pool.acquire().await.unwrap();
            Self::query("SELECT 1").fetch_one(&mut *connection).await.unwrap();
        }).await.expect("description must return its connection on success, error and cancellation");
    }
}

#[tokio::test]
async fn sqlite_describe_is_coherent_and_returns_one_connection() {
    let dir = tempfile::tempdir().unwrap();
    let path = dir.path().join("describe.sqlite");
    let writer = super::store::Store::<sqlx::Sqlite>::new(super::sqlite::open(path.to_str().unwrap()).await.unwrap());
    let checkouts = Arc::new(AtomicUsize::new(0));
    let pool = reader_pool(sqlx::sqlite::SqlitePoolOptions::new(),checkouts.clone())
        .connect_with(sqlx::sqlite::SqliteConnectOptions::new().filename(path).read_only(true)
            .busy_timeout(Duration::from_secs(5))).await.unwrap();
    let reader = super::store::Store::new(pool);
    reader.qualify_describe_snapshot(&writer,&checkouts,false).await;
    reader.close().await;
    writer.close().await;
}
