//! Count due tasks whose exact type is missing from the fresh registrations
//! for their queue. Build, compatibility and available slots are separate
//! routing constraints.
use super::{Result, backend::Backend, store::Store, task_queues::registration_types};
use chrono::{DateTime, Duration, Utc};
use futures_util::TryStreamExt;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::collections::{BTreeMap, BTreeSet};

const TYPE_LIMIT: usize = 100;

macro_rules! ready_types {
    ($activity_id:literal, $availability:literal, $group:literal, $order:literal, $queue:literal) => {
        concat!(
            "SELECT task_queue, task_kind, type_name, COUNT(*) AS task_count FROM (",
            "SELECT t.queue AS task_queue, t.task_type AS task_kind, ",
            "CASE WHEN t.task_type='workflow' THEN r.workflow_type ELSE a.activity_type END AS type_name ",
            "FROM workflow_tasks t JOIN workflow_runs r ON r.id=t.workflow_run_id ",
            "LEFT JOIN activity_executions a ON t.task_type='activity' ",
            "AND a.id=", $activity_id, " AND a.workflow_run_id=r.id AND a.status IN ('pending','running') ",
            "WHERE t.namespace=$1 AND t.status='ready' AND t.task_type IN ('workflow','activity') ",
            "AND r.status IN ('pending','running','waiting') ",
            "AND (t.available_at IS NULL OR ", $availability, "<=$2)", $queue, ") AS ready_types ",
            "GROUP BY task_queue, task_kind, type_name", $group, $order,
        )
    };
}

macro_rules! ready_types_sqlite {
    ($queue:literal) => {
        ready_types!(
            "CASE WHEN json_valid(t.payload) THEN json_extract(t.payload,'$.activity_execution_id') END",
            "replace(replace(replace(t.available_at,'T',' '),'Z',''),'+00:00','')",
            "", " ORDER BY task_queue, task_kind, type_name", $queue
        )
    };
}

macro_rules! ready_types_postgres {
    ($queue:literal) => {
        ready_types!(
            "(t.payload->>'activity_execution_id')",
            "t.available_at",
            "",
            " ORDER BY task_queue, task_kind, type_name",
            $queue
        )
    };
}

macro_rules! ready_types_mysql {
    ($queue:literal) => {
        ready_types!(
            "CASE WHEN JSON_VALID(t.payload) THEN JSON_UNQUOTE(JSON_EXTRACT(t.payload,'$.activity_execution_id')) END",
            "t.available_at",
            ", BINARY task_queue, BINARY type_name",
            " ORDER BY BINARY task_queue, task_kind, BINARY type_name", $queue
        )
    };
}

#[derive(Default)]
struct AdvertisedTypes {
    workflows: BTreeSet<String>,
    activities: BTreeSet<String>,
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
    /// Queue and namespace views use the same single-snapshot classification.
    /// Totals cover every matching group. The detailed series is bounded.
    pub(super) async fn ready_task_type_coverage(
        &self,
        queue: Option<&str>,
        at: DateTime<Utc>,
    ) -> Result<Value> {
        const WORKERS: &str = concat!(
            "SELECT namespace,task_queue,status,last_heartbeat_at,supported_workflow_types,supported_activity_types ",
            "FROM workflow_worker_registrations WHERE namespace=$1 AND last_heartbeat_at>$2 AND status='active'",
        );
        const QUEUE_WORKERS: &str = concat!(
            "SELECT namespace,task_queue,status,last_heartbeat_at,supported_workflow_types,supported_activity_types ",
            "FROM workflow_worker_registrations WHERE namespace=$1 AND last_heartbeat_at>$2 AND status='active' AND task_queue=$3",
        );
        let cutoff = at - Duration::seconds(self.worker_stale_after_seconds.max(1));
        let mut tx = self.begin_repeatable_read().await?;
        let workers = Self::query(if queue.is_some() {
            QUEUE_WORKERS
        } else {
            WORKERS
        })
        .bind(self.namespace.as_ref())
        .bind(DB::bind_time(cutoff));
        let workers = match queue {
            Some(queue) => workers.bind(queue),
            None => workers,
        };
        let mut advertised = BTreeMap::<String, AdvertisedTypes>::new();
        {
            let mut rows = workers.fetch(&mut *tx);
            while let Some(row) = rows.try_next().await? {
                let Some(task_queue) = DB::optional_string(&row, "task_queue")? else {
                    continue;
                };
                // The database can use a case-insensitive collation, and
                // older SQLite timestamps can use an RFC3339 separator.
                if DB::string(&row, "namespace")? != self.namespace.as_ref()
                    || queue.is_some_and(|selected| selected != task_queue)
                    || DB::string(&row, "status")? != "active"
                    || DB::optional_instant(&row, "last_heartbeat_at")?
                        .is_none_or(|seen| seen <= cutoff)
                {
                    continue;
                }
                let types = advertised.entry(task_queue).or_default();
                for (field, names) in [
                    ("supported_workflow_types", &mut types.workflows),
                    ("supported_activity_types", &mut types.activities),
                ] {
                    names.extend(registration_types(DB::optional_document_row(&row, field)?));
                }
            }
        }

        let query_sql = match (DB::PHP_CONNECTION, queue.is_some()) {
            ("sqlite", false) => ready_types_sqlite!(""),
            ("sqlite", true) => ready_types_sqlite!(" AND t.queue=$3"),
            ("mysql", false) => ready_types_mysql!(" AND BINARY t.namespace=BINARY $3"),
            ("mysql", true) => {
                ready_types_mysql!(" AND BINARY t.namespace=BINARY $3 AND t.queue=$4")
            }
            (_, false) => ready_types_postgres!(""),
            (_, true) => ready_types_postgres!(" AND t.queue=$3"),
        };
        let tasks = Self::query(query_sql)
            .bind(self.namespace.as_ref())
            .bind(DB::bind_time(at));
        let tasks = if DB::PHP_CONNECTION == "mysql" {
            tasks.bind(self.namespace.as_ref())
        } else {
            tasks
        };
        let tasks = match queue {
            Some(queue) => tasks.bind(queue),
            None => tasks,
        };
        let mut by_type = Vec::new();
        let mut types_truncated = false;
        let mut without_pollers = 0;
        let mut unsupported = 0;
        let mut unclassified = 0;
        {
            let mut rows = tasks.fetch(&mut *tx);
            while let Some(row) = rows.try_next().await? {
                let selected = DB::optional_string(&row, "task_queue")?;
                if queue.is_some() && queue != selected.as_deref() {
                    continue;
                }
                let count = DB::number(&row, "task_count")?;
                let (Some(task_queue), Some(type_name)) =
                    (selected, DB::optional_string(&row, "type_name")?)
                else {
                    unclassified += count;
                    continue;
                };
                let kind = DB::string(&row, "task_kind")?;
                let reason = match advertised.get(&task_queue) {
                    None => {
                        without_pollers += count;
                        "no_active_pollers"
                    }
                    Some(types) => {
                        let supported = if kind == "workflow" {
                            &types.workflows
                        } else {
                            &types.activities
                        };
                        if supported.contains(&type_name) {
                            continue;
                        }
                        unsupported += count;
                        "unsupported_type"
                    }
                };
                if by_type.len() < TYPE_LIMIT {
                    by_type.push(json!({"task_queue": task_queue, "task_kind": kind,
                        "type": type_name, "ready_count": count, "reason": reason}));
                } else {
                    types_truncated = true;
                }
            }
        }
        tx.commit().await?;
        Ok(json!({
            "no_active_poller_count": without_pollers,
            "unsupported_type_count": unsupported,
            "unclassified_count": unclassified,
            "by_type": by_type,
            "type_limit": TYPE_LIMIT,
            "types_truncated": types_truncated,
        }))
    }
}
