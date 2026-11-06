//! The actual cache writers prepare and execute exactly one statement per row.
use super::store::Store;
use serde_json::json;
use std::collections::BTreeMap;

store_methods! {
    pub(super) async fn qualify_summary_projection_bytes(&self) {
        let input = crate::codec::ValueCodec::new().unwrap()
            .encode(&crate::codec::Value::Array(vec![])).unwrap();
        let run = self.start(json!({"workflow_id":"projection-bytes","workflow_type":"projection",
            "task_queue":"projection","input":{"codec":"avro","blob":input}}), BTreeMap::new(), None, None).await.unwrap();
        let run_id = run["run_id"].as_str().unwrap();
        // Keep transport classification far from a live clock boundary while
        // the two reference paths run one after another.
        Self::query("UPDATE workflow_tasks SET available_at='2100-01-01 00:00:00' WHERE workflow_run_id=$1")
            .bind(run_id).execute(&self.pool).await.unwrap();
        let mut tx = self.begin().await.unwrap();
        let (fast, old) = Self::compare_projection_bytes(&mut tx, run_id).await.unwrap();
        assert!(fast < old, "an ordinary ready run must issue fewer statements: {fast}/{old}");
        tx.rollback().await.unwrap();
        // Every column is compared on the same database/transaction. These
        // are stored-row classifier controls, not producer/admission claims.
        for sql in [
            "UPDATE workflow_tasks SET status='leased',lease_owner='projection',lease_expires_at='2100-01-01 00:00:00' WHERE workflow_run_id=$1",
            "UPDATE workflow_tasks SET status='leased',lease_owner='projection',lease_expires_at='2000-01-01 00:00:00' WHERE workflow_run_id=$1",
            "UPDATE workflow_tasks SET repair_count=1 WHERE workflow_run_id=$1",
            "UPDATE workflow_tasks SET status='completed' WHERE workflow_run_id=$1",
            "UPDATE workflow_runs SET compatibility='unqualified' WHERE id=$1",
            "UPDATE workflow_runs SET status='completed',closed_reason='completed',closed_at='2100-01-01 00:00:00' WHERE id=$1",
        ] {
            let mut tx = self.begin().await.unwrap();
            Self::query(sql).bind(run_id).execute(&mut *tx).await.unwrap();
            Self::compare_projection_bytes(&mut tx, run_id).await.unwrap();
            tx.rollback().await.unwrap();
        }
        let mut tx = self.begin().await.unwrap();
        Self::query("UPDATE workflow_tasks SET status='failed',payload=$1,last_error='retained replay error' WHERE workflow_run_id=$2")
            .bind(DB::document(&json!({"replay_blocked":true,"replay_blocked_reason":"worker_reported_replay_failure"})))
            .bind(run_id).execute(&mut *tx).await.unwrap();
        Self::compare_projection_bytes(&mut tx, run_id).await.unwrap();
        tx.rollback().await.unwrap();
    }
}

#[tokio::test]
async fn sqlite_poll_cache_writes_use_one_statement_and_rollback() {
    let dir = tempfile::tempdir().unwrap();
    let store = Store::<sqlx::Sqlite>::new(super::sqlite::open(dir.path().join("writes.sqlite").to_str().unwrap()).await.unwrap());
    store.qualify_query_poll_cache_writes().await;
    store.qualify_workflow_poll_cache_writes().await;
    store.qualify_summary_projection_bytes().await;
    store.close().await;
}
