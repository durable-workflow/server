//! Operational diagnostics for workflow tasks that lose their lease before completing.
use super::{
    Result, backend::Backend, store::Store, task_queues::json_time, transaction::Transaction,
};
use chrono::{DateTime, Utc};
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::collections::{BTreeMap, btree_map::Entry};

pub(super) const DEFAULT_THRESHOLD: i64 = 3;

pub(super) struct TaskAttentionMetrics {
    pub(super) runs: i64,
    pub(super) tasks: i64,
    pub(super) max_losses: i64,
}

macro_rules! open_tasks {
    () => {
        " FROM workflow_tasks t JOIN workflow_runs r ON r.id=t.workflow_run_id \
         WHERE r.namespace=$1 AND r.status IN ('pending','running','waiting') \
         AND t.task_type='workflow' AND t.status IN ('ready','leased')"
    };
}

macro_rules! attention_for_runs {
    ($members:literal) => {
        concat!(
            "SELECT t.id,t.workflow_run_id,t.lost_lease_count,t.last_lease_lost_at",
            open_tasks!(),
            " AND t.lost_lease_count >= $2 AND r.id IN (",
            $members,
            ") ORDER BY t.lost_lease_count DESC,t.id"
        )
    };
}

impl<DB: Backend> Store<DB>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
    for<'q> &'q str: Encode<'q, DB> + Type<DB>,
    for<'q> Option<&'q str>: Encode<'q, DB>,
    for<'q> Option<DB::EncodedDocument>: Encode<'q, DB>,
    for<'q> String: Encode<'q, DB> + Type<DB>,
    for<'q> i64: Encode<'q, DB> + Type<DB>,
    for<'r> i64: Decode<'r, DB>,
    usize: ColumnIndex<DB::Row>,
{
    /// Record the loss on the same transaction that recovers the held lease.
    pub(super) async fn record_workflow_lease_loss(
        tx: &mut Transaction<'_, DB>,
        task_id: &str,
        at: DateTime<Utc>,
    ) -> Result<()> {
        Self::query(
            "UPDATE workflow_tasks SET lost_lease_count=CASE WHEN lost_lease_count < 9223372036854775807 THEN lost_lease_count+1 ELSE lost_lease_count END,last_lease_lost_at=$1 WHERE id=$2 AND task_type='workflow' AND status='leased'",
        )
        .bind(DB::bind_time(at))
        .bind(task_id)
        .execute(&mut **tx)
        .await?;
        Ok(())
    }

    /// Fetch all positive diagnostics for the already selected run page in one query.
    pub(super) async fn task_attention_for_runs(
        &self,
        connection: &mut DB::Connection,
        run_ids: &[String],
    ) -> Result<BTreeMap<String, Value>> {
        if run_ids.is_empty() {
            return Ok(BTreeMap::new());
        }
        let sql = match DB::NAME {
            "PostgreSQL" => {
                attention_for_runs!("SELECT jsonb_array_elements_text(CAST($3 AS jsonb))")
            }
            "MySQL" => attention_for_runs!(
                "SELECT ids.id FROM JSON_TABLE($3, '$[*]' COLUMNS (id VARCHAR(255) COLLATE utf8mb4_unicode_ci PATH '$')) ids"
            ),
            _ => attention_for_runs!("SELECT value FROM json_each($3)"),
        };
        let threshold = self.workflow_task_lost_lease_attention_threshold;
        let ids = serde_json::to_string(run_ids)?;
        let mut attention = BTreeMap::new();
        for row in Self::query(sql)
            .bind(self.namespace.as_ref())
            .bind(threshold)
            .bind(ids.as_str())
            .fetch_all(&mut *connection)
            .await?
        {
            let run_id = DB::string(&row, "workflow_run_id")?;
            if let Entry::Vacant(slot) = attention.entry(run_id) {
                slot.insert(json!({
                    "reason": "workflow_task_lease_lost",
                    "consecutive": DB::number(&row, "lost_lease_count")?,
                    "threshold": threshold,
                    "workflow_task_id": DB::string(&row, "id")?,
                    "last_lease_lost_at": json_time(DB::optional_instant(&row, "last_lease_lost_at")?),
                }));
            }
        }
        Ok(attention)
    }

    pub(super) async fn task_attention_for_run(
        &self,
        connection: &mut DB::Connection,
        run_id: &str,
    ) -> Result<Value> {
        Ok(self
            .task_attention_for_runs(connection, &[run_id.to_owned()])
            .await?
            .remove(run_id)
            .unwrap_or(Value::Null))
    }

    pub(super) async fn task_attention_metrics(&self) -> Result<TaskAttentionMetrics> {
        let threshold = self.workflow_task_lost_lease_attention_threshold;
        let counts = concat!(
            "WITH open_task_losses AS (SELECT r.id AS run_id,t.lost_lease_count AS losses",
            open_tasks!(),
            " AND t.lost_lease_count > 0) ",
            "SELECT COUNT(DISTINCT CASE WHEN losses >= $2 THEN run_id END) AS runs,",
            "COUNT(CASE WHEN losses >= $3 THEN 1 END) AS tasks,",
            "COALESCE(MAX(losses),0) AS max_losses FROM open_task_losses"
        );
        let row = Self::query(counts)
            .bind(self.namespace.as_ref())
            .bind(threshold)
            .bind(threshold)
            .fetch_one(&self.pool)
            .await?;
        Ok(TaskAttentionMetrics {
            runs: DB::number(&row, "runs")?,
            tasks: DB::number(&row, "tasks")?,
            max_losses: DB::number(&row, "max_losses")?,
        })
    }
}
