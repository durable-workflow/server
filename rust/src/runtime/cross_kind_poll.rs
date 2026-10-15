//! Optional, scoped hints. Readiness observation never claims another task kind.
use super::{Result, backend::Backend, store::Store, text};
use serde_json::Value;
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

pub(super) struct PollWakeSnapshot {
    registration: String,
    ready: Vec<String>,
}

pub(super) const TASKS_SQL: &str = "SELECT t.id,t.namespace,t.queue FROM workflow_tasks t JOIN workflow_runs r ON r.id=t.workflow_run_id WHERE t.namespace=$1 AND t.queue=$2 AND t.task_type!=$3 AND t.task_type IN ('workflow','activity') AND r.status IN ('pending','running','waiting') AND (t.status='ready' OR (t.status='leased' AND t.lease_expires_at<=$4)) AND (t.available_at IS NULL OR t.available_at<=$5) ORDER BY t.available_at,t.id LIMIT 100";

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
    pub(super) async fn poll_wake_snapshot(
        &self,
        body: &Value,
        kind: &str,
    ) -> Result<Option<PollWakeSnapshot>> {
        let worker_id = text(body, "worker_id")?;
        let queue = text(body, "task_queue")?;
        let worker = Self::query("SELECT w.capabilities,w.namespace,w.task_queue,w.worker_id,i.token FROM workflow_worker_registrations w JOIN dw_worker_registration_incarnations i ON i.namespace=w.namespace AND i.worker_id=w.worker_id AND i.status='active' WHERE w.namespace=$1 AND w.worker_id=$2 AND w.task_queue=$3 AND w.status='active'")
            .bind(self.namespace.as_ref()).bind(text(body,"worker_id")?).bind(text(body,"task_queue")?)
            .fetch_optional(&self.pool).await?;
        let Some(worker) = worker else {
            return Ok(None);
        };
        if DB::string(&worker, "namespace")? != self.namespace.as_ref()
            || DB::string(&worker, "task_queue")? != queue
            || DB::string(&worker, "worker_id")? != worker_id
        {
            return Ok(None);
        }
        if !DB::optional_document_row(&worker, "capabilities")?.is_some_and(|caps| {
            caps.as_array()
                .is_some_and(|caps| caps.iter().any(|cap| cap == "cross_kind_poll_wake"))
        }) {
            return Ok(None);
        }
        // Only bounded routing metadata leaves the database, never task payloads or query
        // history snapshots. The same reads observe independent server nodes.
        let at = super::store::now();
        let rows = Self::query(DB::POLL_WAKE_TASKS_SQL)
            .bind(self.namespace.as_ref())
            .bind(text(body, "task_queue")?)
            .bind(kind)
            .bind(DB::bind_time(at))
            .bind(DB::bind_time(at))
            .fetch_all(&self.pool)
            .await?;
        let mut ready = Vec::new();
        for row in rows {
            // Keep the indexed SQL scope filter, then compare exact bytes:
            // the preserved MySQL collation folds case and accents.
            if DB::string(&row, "namespace")? == self.namespace.as_ref()
                && DB::string(&row, "queue")? == queue
            {
                ready.push(format!("task:{}", DB::string(&row, "id")?));
            }
        }
        if kind != "query" {
            // Query timing uses the immutable UTC6 authority fields. This
            // readiness hint still grants no claim or execution authority.
            let query_now = super::query_authority::wire(at);
            ready.extend(
                Self::query(DB::POLL_WAKE_QUERIES_SQL)
                    .bind(at.timestamp())
                    .bind(self.namespace.as_ref())
                    .bind(text(body, "task_queue")?)
                    .bind(query_now.clone())
                    .bind(query_now)
                    .fetch_all(&self.pool)
                    .await?
                    .iter()
                    .map(|row| DB::string(row, "id").map(|id| format!("query:{id}")))
                    .collect::<Result<Vec<_>>>()?,
            );
        }
        Ok(Some(PollWakeSnapshot {
            registration: DB::string(&worker, "token")?,
            ready,
        }))
    }

    pub(super) async fn poll_queue_changed(
        &self,
        body: &Value,
        kind: &str,
        previous: &mut Option<PollWakeSnapshot>,
    ) -> Result<bool> {
        let Some(before) = previous else {
            return Ok(false);
        };
        let Some(current) = self.poll_wake_snapshot(body, kind).await? else {
            *previous = None;
            return Ok(false);
        };
        // A replacement registration cannot lend its opt-in to a held poll.
        // The original claim path still owns all registration/lease fences.
        if current.registration != before.registration {
            *previous = None;
            return Ok(false);
        }
        let changed = current.ready.iter().any(|id| !before.ready.contains(id));
        *previous = Some(current);
        Ok(changed)
    }
}
