//! Observe committed visibility while the local queue hands off before COMMIT.
use super::{RuntimeError, store::Store};
use std::{sync::{Arc, atomic::Ordering}, time::Duration};
use tokio::sync::Notify;

fn allocation_outcome(result: super::Result<i64>) -> std::result::Result<i64, String> {
    result.map_err(|error| match error {
        RuntimeError::Refused { status, reason } => format!("refused:{}:{reason}", status.as_u16()),
        RuntimeError::Database(sqlx::Error::Database(error)) => format!("database:{}", error.code().unwrap_or_default()),
        RuntimeError::Database(sqlx::Error::RowNotFound) => "row_not_found".into(),
        RuntimeError::Database(sqlx::Error::ColumnDecode { .. }) => "column_decode".into(),
        RuntimeError::Database(sqlx::Error::Decode(_)) => "decode".into(),
        other => panic!("unexpected allocation failure: {other:?}"),
    })
}

#[derive(Default)]
pub(super) struct CommitGate {
    pub(super) entered: Notify,
    pub(super) resume: Notify,
}

store_methods! {
    pub(super) async fn qualify_commit_handoff(&self) {
        Self::query("CREATE TABLE dw_commit_handoff_probe (ordinal BIGINT PRIMARY KEY)")
            .execute(&self.pool).await.unwrap();
        for cancel in [false, true] {
            let ordinal = i64::from(cancel);
            let mut holder = self.begin().await.unwrap();
            Self::query("INSERT INTO dw_commit_handoff_probe (ordinal) VALUES ($1)")
                .bind(ordinal).execute(&mut *holder).await.unwrap();
            let gate = Arc::new(CommitGate::default());
            holder.commit_gate = Some(gate.clone());
            let mut committing = Box::pin(holder.commit());
            tokio::select! {
                result = &mut committing => panic!("commit passed its gate: {}", result.is_ok()),
                _ = gate.entered.notified() => {}
            }
            let before = self.test_transitions.load(Ordering::Relaxed);
            let sibling = self.in_namespace("commit-handoff-reader");
            let mut writer = Box::pin(sibling.begin());
            // The same future stays alive after this timeout: it must not
            // complete while the first transaction still has uncommitted data.
            assert!(tokio::time::timeout(Duration::from_millis(150), writer.as_mut()).await.is_err());
            let admitted = self.test_transitions.load(Ordering::Relaxed) - before;
            assert_eq!(admitted, usize::from(DB::NAME != "SQLite"),
                "SQLite keeps the local gate; the other adapters wait on their real database lock");
            let mut read = self.begin_read().await.unwrap();
            let row = Self::query("SELECT COUNT(*) AS n FROM dw_commit_handoff_probe WHERE ordinal=$1")
                .bind(ordinal).fetch_one(&mut *read).await.unwrap();
            assert_eq!(DB::number(&row, "n").unwrap(), 0, "read snapshot must not see uncommitted data");
            read.commit().await.unwrap();

            let mut next = if cancel {
                drop(committing);
                writer.await.unwrap()
            } else {
                gate.resume.notify_one();
                let (committed, next) = tokio::join!(committing, writer);
                committed.unwrap();
                next.unwrap()
            };
            let row = Self::query("SELECT COUNT(*) AS n FROM dw_commit_handoff_probe WHERE ordinal=$1")
                .bind(ordinal).fetch_one(&mut *next).await.unwrap();
            assert_eq!(DB::number(&row, "n").unwrap(), i64::from(!cancel),
                "the next locked transition sees only the acknowledged commit or rolled-back cancellation");
            next.rollback().await.unwrap();
        }
        Self::query("DROP TABLE dw_commit_handoff_probe").execute(&self.pool).await.unwrap();
    }

    pub(super) async fn qualify_history_sequence_allocation(&self) {
        use crate::codec::{Value as Payload, ValueCodec};
        use serde_json::json;
        let blob = ValueCodec::new().unwrap().encode(&Payload::Array(vec![])).unwrap();
        let started = self.start(json!({"workflow_id":"history-allocation", "workflow_type":"probe", "task_queue":"allocation",
            "input":{"codec":"avro","blob":blob}}), Default::default(), None, None).await.unwrap();
        let run_id = started["run_id"].as_str().unwrap();
        let initial = Self::query("SELECT last_history_sequence FROM workflow_runs WHERE id=$1")
            .bind(run_id).fetch_one(&self.pool).await.unwrap();
        let initial_sequence = DB::number(&initial, "last_history_sequence").unwrap();
        let maximum = match DB::NAME {
            "SQLite" => i64::MAX,
            "PostgreSQL" => i32::MAX as i64,
            "MySQL" => u32::MAX as i64,
            _ => panic!("unsupported qualification backend"),
        };
        for value in [0, 42, maximum - 1, maximum] {
            Self::query("UPDATE workflow_runs SET last_history_sequence=$1 WHERE id=$2")
                .bind(value).bind(run_id).execute(&self.pool).await.unwrap();
            let mut original = self.begin().await.unwrap();
            let expected = allocation_outcome(Self::allocate_history_sequence_fallback(&mut original, run_id).await);
            original.rollback().await.unwrap();
            let mut candidate = self.begin().await.unwrap();
            let (result, statements) = super::sql_counts::measure(Self::allocate_history_sequence(&mut candidate, run_id)).await;
            assert_eq!(allocation_outcome(result), expected, "stored counter {value}");
            assert_eq!(statements.count(DB::HISTORY_ADVANCE_SQL), 1);
            assert_eq!(statements.count("SELECT last_history_sequence FROM workflow_runs WHERE id=$1"), usize::from(value == maximum),
                "ordinary allocation uses one statement; exhaustion keeps the original read");
            candidate.rollback().await.unwrap();
            let row = Self::query("SELECT last_history_sequence FROM workflow_runs WHERE id=$1")
                .bind(run_id).fetch_one(&self.pool).await.unwrap();
            assert_eq!(DB::number(&row, "last_history_sequence").unwrap(), value);
        }
        // A missing run still produces the original RowNotFound refusal.
        let missing = "00000000000000000000000000";
        let mut original = self.begin().await.unwrap();
        let expected = allocation_outcome(Self::allocate_history_sequence_fallback(&mut original, missing).await);
        original.rollback().await.unwrap();
        let mut candidate = self.begin().await.unwrap();
        assert_eq!(allocation_outcome(Self::allocate_history_sequence(&mut candidate, missing).await), expected);
        candidate.rollback().await.unwrap();

        // Do not repair or skip a noncanonical stored counter through coercion.
        if DB::NAME != "MySQL" {
            Self::query("UPDATE workflow_runs SET last_history_sequence=-1 WHERE id=$1")
                .bind(run_id).execute(&self.pool).await.unwrap();
            let mut transaction = self.begin().await.unwrap();
            assert_eq!(Self::allocate_history_sequence(&mut transaction, run_id).await.unwrap(), 0);
            transaction.rollback().await.unwrap();
        }
        if DB::NAME == "SQLite" {
            for statement in [
                "UPDATE workflow_runs SET last_history_sequence=1.5 WHERE id=$1",
                "UPDATE workflow_runs SET last_history_sequence='not-an-integer' WHERE id=$1",
            ] {
                Self::query(statement).bind(run_id).execute(&self.pool).await.unwrap();
                let mut original = self.begin().await.unwrap();
                let expected = allocation_outcome(Self::allocate_history_sequence_fallback(&mut original, run_id).await);
                original.rollback().await.unwrap();
                let mut candidate = self.begin().await.unwrap();
                let (result, statements) = super::sql_counts::measure(Self::allocate_history_sequence(&mut candidate, run_id)).await;
                assert_eq!(allocation_outcome(result), expected);
                assert_eq!(statements.count("SELECT last_history_sequence FROM workflow_runs WHERE id=$1"), 1);
                candidate.rollback().await.unwrap();
            }
        }
        Self::query("UPDATE workflow_runs SET last_history_sequence=$1 WHERE id=$2")
            .bind(initial_sequence).bind(run_id).execute(&self.pool).await.unwrap();
    }

    pub(super) async fn qualify_authored_sequence_reads(&self) {
        use crate::codec::{Value as Payload, ValueCodec};
        use serde_json::json;
        let input = json!({"codec":"avro","blob":ValueCodec::new().unwrap().encode(&Payload::Array(vec![])).unwrap()});
        self.register(json!({"worker_id":"authored-once-worker","task_queue":"authored-once","runtime":"php",
            "supported_workflow_types":["probe"],"supported_activity_types":[]}),
            &super::QueryTaskConfig::default(), Some("1.20")).await.unwrap();
        let started = self.start(json!({"workflow_id":"authored-once","workflow_type":"probe","task_queue":"authored-once","input":input}),
            Default::default(), None, None).await.unwrap();
        let claimed = self.poll(json!({"worker_id":"authored-once-worker","task_queue":"authored-once","timeout_seconds":0}),
            "workflow", "1.20").await.unwrap();
        let (result, statements) = super::sql_counts::measure(self.complete_or_checkpoint(
            claimed["task"]["task_id"].as_str().unwrap(),
            json!({"lease_owner":"authored-once-worker","workflow_task_attempt":claimed["task"]["workflow_task_attempt"],
                "commands":[{"type":"complete_workflow","result":input}]}),
            Arc::new(tokio::sync::Semaphore::new(1)), Default::default(), None,
            super::carried_polls::Config { protocol: Some("1.20"), pages: Default::default(), default_wait: 30 },
            Default::default(), None, None,
        )).await;
        assert_eq!(result.unwrap()["recorded"], true);
        assert_eq!(statements.count("SELECT sequence FROM activity_executions WHERE workflow_run_id=$1 ORDER BY sequence DESC LIMIT 1"), 1,
            "scope validation and command application share one authored-sequence scan");
        let rows = Self::query("SELECT sequence,event_type FROM workflow_history_events WHERE workflow_run_id=$1 ORDER BY sequence")
            .bind(started["run_id"].as_str().unwrap()).fetch_all(&self.pool).await.unwrap();
        let sequences: Vec<_> = rows.iter().map(|row| DB::number(row, "sequence").unwrap()).collect();
        assert!(sequences.len() >= 2);
        assert_eq!(sequences, (1..=sequences.len() as i64).collect::<Vec<_>>());
        assert_eq!(DB::string(rows.last().unwrap(), "event_type").unwrap(), "WorkflowCompleted");
    }
}

#[tokio::test]
async fn sqlite_commit_handoff_keeps_the_queue_and_committed_visibility() {
    tokio::time::timeout(Duration::from_secs(30), async {
        let directory = tempfile::tempdir().unwrap();
        let store = Store::<sqlx::Sqlite>::new(super::sqlite::open(directory.path().join("handoff.sqlite").to_str().unwrap()).await.unwrap());
        store.qualify_commit_handoff().await;
        store.qualify_history_sequence_allocation().await;
        store.qualify_authored_sequence_reads().await;
        store.close().await;
    }).await.expect("bounded SQLite commit handoff qualification");
}
