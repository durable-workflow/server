//! `GET /api/task-queues` and `GET /api/task-queues/{name}` as published
//! PHP's `TaskQueueController` answers them: `StandaloneWorkerVisibility`
//! pollers, `OperatorQueueVisibility` stats, current leases and repair
//! candidates, the recent task flow, activity attempt states and admission.
//!
//! Native enforces no server lease or dispatch budgets, so admission reports
//! PHP's unlimited defaults. Native tasks carry no queue-driver metadata
//! (creation, dispatch or dispatch-failure times), so for tasks native
//! created the recent-flow counters and the dispatch-failed and
//! dispatch-overdue candidates are honestly zero; PHP-written tasks keep
//! theirs.
use super::{
    Result,
    backend::Backend,
    query_authority::QueryTaskConfig,
    store::{Store, now},
};
use chrono::{DateTime, Duration, Utc};
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::collections::BTreeSet;

const CURRENT_LEASE_LIMIT: usize = 50;
/// `TaskRepairPolicy::REDISPATCH_AFTER_SECONDS`.
const REDISPATCH_AFTER_SECONDS: i64 = 3;
/// The pending query-task bound native enforces (`queries::MAX_PENDING`).
const QUERY_PENDING_LIMIT: i64 = 16;
const TASK_TYPES: [&str; 2] = ["workflow", "activity"];

/// Carbon's `toJSON()`.
pub(super) fn json_time(time: Option<DateTime<Utc>>) -> Value {
    json!(time.map(|time| time.format("%Y-%m-%dT%H:%M:%S%.6fZ").to_string()))
}

/// `OperatorQueueVisibility::ageLabel`.
fn age_label(seconds: Option<i64>) -> Value {
    json!(seconds.map(|seconds| match seconds {
        seconds if seconds < 60 => format!("{seconds}s"),
        seconds if seconds < 3600 => format!("{}m{:02}s", seconds / 60, seconds % 60),
        seconds => format!("{}h{:02}m{:02}s", seconds / 3600, seconds % 3600 / 60, seconds % 60),
    }))
}

fn age_ms(time: Option<DateTime<Utc>>, at: DateTime<Utc>) -> i64 {
    time.map_or(0, |time| (at - time).num_milliseconds())
}

/// `StandaloneWorkerVisibility::stringList`: trimmed, unique and sorted.
fn string_list(value: Option<Value>) -> Value {
    let values = match value {
        Some(Value::Array(values)) => values,
        Some(Value::String(text)) => serde_json::from_str::<Value>(&text)
            .ok()
            .and_then(|decoded| decoded.as_array().cloned())
            .unwrap_or_else(|| text.split(',').map(|part| json!(part)).collect()),
        _ => vec![],
    };
    let unique: BTreeSet<String> = values
        .iter()
        .filter_map(Value::as_str)
        .map(str::trim)
        .filter(|text| !text.is_empty())
        .map(str::to_owned)
        .collect();
    json!(unique)
}

/// `TaskQueueController::taskAdmission` under PHP's default unlimited
/// server budget.
fn task_admission(kind: &str, pollers: &[Value], stats: &Value, server: (i64, i64)) -> Value {
    let (source, slot_field) = if kind == "workflow" {
        ("max_concurrent_workflow_tasks", "max_concurrent_workflow_tasks")
    } else {
        ("max_concurrent_activity_tasks", "max_concurrent_activity_tasks")
    };
    let active: Vec<&Value> = pollers
        .iter()
        .filter(|poller| poller["is_stale"] != true && poller["status"] == "active")
        .collect();
    let configured: i64 = active
        .iter()
        .map(|poller| poller[slot_field].as_i64().unwrap_or(0).max(0))
        .sum();
    let group = &stats[format!("{kind}_tasks")];
    let leased = group["leased_count"].as_i64().unwrap_or(0).max(0);
    let ready = group["ready_count"].as_i64().unwrap_or(0).max(0);
    let status = if active.is_empty() {
        "no_active_workers"
    } else if configured <= 0 {
        "no_slots"
    } else if leased >= configured {
        "saturated"
    } else {
        "accepting"
    };
    json!({"budget_source":format!("worker_registration.{source}"),
        "server_budget_source":format!("server.admission.{kind}_tasks.max_active_leases_per_queue"),
        "active_worker_count":active.len(),"configured_slot_count":configured,"leased_count":leased,"ready_count":ready,
        "available_slot_count":(configured - leased).max(0),
        "server_max_active_leases_per_queue":null,"server_active_lease_count":server.0,
        "server_remaining_active_lease_capacity":null,"server_max_active_leases_per_namespace":null,
        "server_namespace_active_lease_count":server.1,"server_remaining_namespace_active_lease_capacity":null,
        "server_max_dispatches_per_minute":null,"server_dispatch_count_this_minute":0,"server_remaining_dispatch_capacity":null,
        "server_max_dispatches_per_minute_per_namespace":null,"server_namespace_dispatch_count_this_minute":0,
        "server_remaining_namespace_dispatch_capacity":null,"server_dispatch_budget_group":null,
        "server_max_dispatches_per_minute_per_budget_group":null,"server_budget_group_dispatch_count_this_minute":0,
        "server_remaining_budget_group_dispatch_capacity":null,"server_lock_required":false,"server_lock_supported":true,
        "status":status})
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
    /// `OperatorQueueVisibility::pollers` for one registration row.
    fn queue_poller(row: &DB::Row, at: DateTime<Utc>, stale_after: i64) -> Result<Value> {
        let text = |field: &str| -> Result<Value> {
            Ok(json!(DB::optional_string(row, field)?.map(|text| text.trim().to_owned()).filter(|text| !text.is_empty())))
        };
        let heartbeat = DB::optional_instant(row, "last_heartbeat_at")?;
        let deadline = heartbeat.map(|heartbeat| heartbeat + Duration::seconds(stale_after));
        let stale = deadline.is_some_and(|deadline| deadline <= at);
        let stored = DB::optional_string(row, "status")?
            .map(|status| status.trim().to_owned())
            .filter(|status| !status.is_empty())
            .unwrap_or_else(|| "active".into());
        Ok(json!({"worker_id":text("worker_id")?,"runtime":text("runtime")?,"sdk_version":text("sdk_version")?,
            "build_id":text("build_id")?,"last_heartbeat_at":json_time(heartbeat),"heartbeat_expires_at":json_time(deadline),
            "status":if stale { "stale".to_owned() } else { stored },"is_stale":stale,
            "supported_workflow_types":string_list(DB::optional_document_row(row, "supported_workflow_types")?),
            "supported_activity_types":string_list(DB::optional_document_row(row, "supported_activity_types")?),
            "max_concurrent_workflow_tasks":DB::optional_number(row, "max_concurrent_workflow_tasks")?.unwrap_or(0).max(0),
            "max_concurrent_activity_tasks":DB::optional_number(row, "max_concurrent_activity_tasks")?.unwrap_or(0).max(0)}))
    }

    /// Registered pollers of a queue: fresh first, then by worker id.
    async fn pollers_for(&self, queue: &str, at: DateTime<Utc>, stale_after: i64) -> Result<Vec<Value>> {
        let rows = Self::query("SELECT * FROM workflow_worker_registrations WHERE namespace=$1 AND task_queue=$2")
            .bind(self.namespace.as_ref())
            .bind(queue)
            .fetch_all(&self.pool)
            .await?;
        let mut pollers = rows
            .iter()
            .map(|row| Self::queue_poller(row, at, stale_after))
            .collect::<Result<Vec<_>>>()?;
        pollers.sort_by(|left, right| {
            (left["is_stale"] == true)
                .cmp(&(right["is_stale"] == true))
                .then_with(|| left["worker_id"].as_str().unwrap_or("").cmp(right["worker_id"].as_str().unwrap_or("")))
        });
        Ok(pollers)
    }

    /// Workflow and activity task counts for one condition.
    async fn grouped_counts(&self, condition: &'static str, queue: &str, at: DateTime<Utc>) -> Result<[i64; 2]> {
        let sql = match condition {
            "ready" => "SELECT task_type,COUNT(*) AS aggregate FROM workflow_tasks WHERE namespace=$1 AND queue=$2 AND task_type IN ('workflow','activity') AND status='ready' AND (available_at IS NULL OR available_at<=$3) GROUP BY task_type",
            "leased" => "SELECT task_type,COUNT(*) AS aggregate FROM workflow_tasks WHERE namespace=$1 AND queue=$2 AND task_type IN ('workflow','activity') AND status='leased' GROUP BY task_type",
            "expired" => "SELECT task_type,COUNT(*) AS aggregate FROM workflow_tasks WHERE namespace=$1 AND queue=$2 AND task_type IN ('workflow','activity') AND status='leased' AND lease_expires_at IS NOT NULL AND lease_expires_at<=$3 GROUP BY task_type",
            "added" => "SELECT task_type,COUNT(*) AS aggregate FROM workflow_tasks WHERE namespace=$1 AND queue=$2 AND task_type IN ('workflow','activity') AND created_at>=$3 GROUP BY task_type",
            "dispatched" => "SELECT task_type,COUNT(*) AS aggregate FROM workflow_tasks WHERE namespace=$1 AND queue=$2 AND task_type IN ('workflow','activity') AND last_dispatched_at IS NOT NULL AND last_dispatched_at>=$3 GROUP BY task_type",
            "active_leases" => "SELECT task_type,COUNT(*) AS aggregate FROM workflow_tasks WHERE namespace=$1 AND queue=$2 AND task_type IN ('workflow','activity') AND status='leased' AND (lease_expires_at IS NULL OR lease_expires_at>$3) GROUP BY task_type",
            _ => unreachable!("known condition"),
        };
        let bound = if matches!(condition, "added" | "dispatched") { at - Duration::minutes(1) } else { at };
        let query = Self::query(sql).bind(self.namespace.as_ref()).bind(queue);
        let query = if condition == "leased" { query } else { query.bind(DB::bind_time(bound)) };
        let rows = query.fetch_all(&self.pool).await?;
        let mut counts = [0, 0];
        for row in rows {
            if let Some(index) = TASK_TYPES.iter().position(|kind| DB::string(&row, "task_type").is_ok_and(|value| value == *kind)) {
                counts[index] = DB::number(&row, "aggregate")?;
            }
        }
        Ok(counts)
    }

    /// `OperatorQueueVisibility::stats`.
    async fn queue_stats(&self, queue: &str, pollers: &[Value], at: DateTime<Utc>, stale_after: i64) -> Result<Value> {
        let ready = self.grouped_counts("ready", queue, at).await?;
        let leased = self.grouped_counts("leased", queue, at).await?;
        let expired = self.grouped_counts("expired", queue, at).await?;
        let added = self.grouped_counts("added", queue, at).await?;
        let dispatched = self.grouped_counts("dispatched", queue, at).await?;
        let oldest = Self::query("SELECT t.id,t.task_type,t.workflow_run_id,t.available_at,t.created_at,r.workflow_instance_id FROM workflow_tasks t JOIN workflow_runs r ON r.id=t.workflow_run_id WHERE t.namespace=$1 AND t.queue=$2 AND t.task_type IN ('workflow','activity') AND t.status='ready' AND (t.available_at IS NULL OR t.available_at<=$3) ORDER BY CASE WHEN t.available_at IS NULL THEN 1 ELSE 0 END DESC,t.available_at,t.created_at,t.id LIMIT 1")
            .bind(self.namespace.as_ref()).bind(queue).bind(DB::bind_time(at)).fetch_optional(&self.pool).await?;
        let mut backlog_age = None;
        let mut oldest_task = Value::Null;
        if let Some(task) = &oldest {
            let since = DB::optional_instant(task, "available_at")?.or(DB::optional_instant(task, "created_at")?);
            backlog_age = since.map(|since| (at - since).num_seconds().max(0));
            oldest_task = json!({"task_id":DB::string(task,"id")?,"task_type":DB::string(task,"task_type")?,
                "workflow_id":DB::string(task,"workflow_instance_id")?,"run_id":DB::string(task,"workflow_run_id")?,
                "available_at":json_time(since),"age_seconds":backlog_age});
        }
        let active = pollers.iter().filter(|poller| poller["is_stale"] != true).count();
        let group = |index: usize| {
            json!({"ready_count":ready[index],"leased_count":leased[index],"expired_lease_count":expired[index],
                "added_last_minute":added[index],"dispatched_last_minute":dispatched[index]})
        };
        Ok(json!({"approximate_backlog_count":ready[0] + ready[1],"approximate_backlog_age":age_label(backlog_age),
            "approximate_backlog_age_seconds":backlog_age,"oldest_ready_task":oldest_task,
            "workflow_tasks":group(0),"activity_tasks":group(1),
            "pollers":{"active_count":active,"stale_count":pollers.len() - active,"stale_after_seconds":stale_after},
            "tasks_added_last_minute":added[0] + added[1],"tasks_dispatched_last_minute":dispatched[0] + dispatched[1]}))
    }

    /// `OperatorQueueVisibility::currentLeases`.
    async fn current_leases(&self, queue: &str, at: DateTime<Utc>) -> Result<Vec<Value>> {
        let workflow = Self::query("SELECT t.id,t.workflow_run_id,r.workflow_instance_id,t.lease_owner,t.lease_expires_at,t.attempt_count FROM workflow_tasks t JOIN workflow_runs r ON r.id=t.workflow_run_id WHERE t.namespace=$1 AND t.queue=$2 AND t.task_type='workflow' AND t.status='leased' ORDER BY t.lease_expires_at,t.id LIMIT 50")
            .bind(self.namespace.as_ref()).bind(queue).fetch_all(&self.pool).await?;
        let activity = Self::query("SELECT t.id,t.workflow_run_id,r.workflow_instance_id,t.lease_owner,t.lease_expires_at,a.id AS activity_attempt_id,a.attempt_number FROM workflow_tasks t JOIN workflow_runs r ON r.id=t.workflow_run_id LEFT JOIN activity_attempts a ON a.workflow_task_id=t.id AND a.status='running' WHERE t.namespace=$1 AND t.queue=$2 AND t.task_type='activity' AND t.status='leased' ORDER BY t.lease_expires_at,t.id LIMIT 50")
            .bind(self.namespace.as_ref()).bind(queue).fetch_all(&self.pool).await?;
        let mut leases = Vec::new();
        for row in &workflow {
            let expires = DB::optional_instant(row, "lease_expires_at")?;
            leases.push(json!({"task_id":DB::string(row,"id")?,"task_type":"workflow","workflow_id":DB::string(row,"workflow_instance_id")?,
                "run_id":DB::string(row,"workflow_run_id")?,"lease_owner":DB::optional_string(row,"lease_owner")?,
                "lease_expires_at":json_time(expires),"is_expired":expires.is_some_and(|expires| expires <= at),
                "workflow_task_attempt":DB::optional_number(row,"attempt_count")?}));
        }
        for row in &activity {
            let expires = DB::optional_instant(row, "lease_expires_at")?;
            leases.push(json!({"task_id":DB::string(row,"id")?,"task_type":"activity","workflow_id":DB::string(row,"workflow_instance_id")?,
                "run_id":DB::string(row,"workflow_run_id")?,"lease_owner":DB::optional_string(row,"lease_owner")?,
                "lease_expires_at":json_time(expires),"is_expired":expires.is_some_and(|expires| expires <= at),
                "activity_attempt_id":DB::optional_string(row,"activity_attempt_id")?,
                "attempt_number":DB::optional_number(row,"attempt_number")?}));
        }
        leases.sort_by(|left, right| {
            (right["is_expired"] == true)
                .cmp(&(left["is_expired"] == true))
                .then_with(|| left["task_id"].as_str().unwrap_or("").cmp(right["task_id"].as_str().unwrap_or("")))
        });
        leases.truncate(CURRENT_LEASE_LIMIT);
        Ok(leases)
    }

    /// One repair candidate class: its count and oldest timestamp.
    async fn repair_class(&self, sql: &'static str, oldest: &'static str, queue: &str, bound: Option<DateTime<Utc>>) -> Result<(i64, Option<DateTime<Utc>>)> {
        let count = Self::scalar(sql).bind(self.namespace.as_ref()).bind(queue);
        let count = match bound { Some(bound) => count.bind(DB::bind_time(bound)), None => count };
        let count = count.fetch_one(&self.pool).await?;
        let row = Self::query(oldest).bind(self.namespace.as_ref()).bind(queue);
        let row = match bound { Some(bound) => row.bind(DB::bind_time(bound)), None => row };
        let row = row.fetch_optional(&self.pool).await?;
        Ok((count, row.map(|row| DB::optional_instant(&row, "since")).transpose()?.flatten()))
    }

    /// `OperatorQueueVisibility::repairStats`.
    async fn repair_stats(&self, queue: &str, at: DateTime<Utc>) -> Result<Value> {
        let (failed, failed_at) = self.repair_class(
            "SELECT COUNT(*) FROM workflow_tasks WHERE namespace=$1 AND queue=$2 AND task_type IN ('workflow','activity') AND status='ready' AND last_dispatch_attempt_at IS NOT NULL AND last_dispatch_error IS NOT NULL AND last_dispatch_error<>'' AND (last_dispatched_at IS NULL OR last_dispatch_attempt_at>last_dispatched_at)",
            "SELECT last_dispatch_attempt_at AS since FROM workflow_tasks WHERE namespace=$1 AND queue=$2 AND task_type IN ('workflow','activity') AND status='ready' AND last_dispatch_attempt_at IS NOT NULL AND last_dispatch_error IS NOT NULL AND last_dispatch_error<>'' AND (last_dispatched_at IS NULL OR last_dispatch_attempt_at>last_dispatched_at) ORDER BY last_dispatch_attempt_at LIMIT 1",
            queue, None).await?;
        let (expired, expired_at) = self.repair_class(
            "SELECT COUNT(*) FROM workflow_tasks WHERE namespace=$1 AND queue=$2 AND task_type IN ('workflow','activity') AND status='leased' AND lease_expires_at IS NOT NULL AND lease_expires_at<=$3",
            "SELECT lease_expires_at AS since FROM workflow_tasks WHERE namespace=$1 AND queue=$2 AND task_type IN ('workflow','activity') AND status='leased' AND lease_expires_at IS NOT NULL AND lease_expires_at<=$3 ORDER BY lease_expires_at LIMIT 1",
            queue, Some(at)).await?;
        // A ready task is overdue once its last dispatch, or its creation when
        // never dispatched, is older than the redispatch window.
        let cutoff = at - Duration::seconds(REDISPATCH_AFTER_SECONDS);
        let overdue_count = Self::scalar("SELECT COUNT(*) FROM workflow_tasks WHERE namespace=$1 AND queue=$2 AND task_type IN ('workflow','activity') AND status='ready' AND (available_at IS NULL OR available_at<=$3) AND ((last_dispatched_at IS NOT NULL AND last_dispatched_at<=$4) OR (last_dispatched_at IS NULL AND created_at<=$5)) AND (last_dispatch_error IS NULL OR last_dispatch_error='')")
            .bind(self.namespace.as_ref()).bind(queue).bind(DB::bind_time(at)).bind(DB::bind_time(cutoff)).bind(DB::bind_time(cutoff))
            .fetch_one(&self.pool).await?;
        let overdue_row = Self::query("SELECT last_dispatched_at,created_at FROM workflow_tasks WHERE namespace=$1 AND queue=$2 AND task_type IN ('workflow','activity') AND status='ready' AND (available_at IS NULL OR available_at<=$3) AND ((last_dispatched_at IS NOT NULL AND last_dispatched_at<=$4) OR (last_dispatched_at IS NULL AND created_at<=$5)) AND (last_dispatch_error IS NULL OR last_dispatch_error='') ORDER BY COALESCE(last_dispatched_at,created_at) LIMIT 1")
            .bind(self.namespace.as_ref()).bind(queue).bind(DB::bind_time(at)).bind(DB::bind_time(cutoff)).bind(DB::bind_time(cutoff))
            .fetch_optional(&self.pool).await?;
        let overdue_since = match &overdue_row {
            Some(row) => DB::optional_instant(row, "last_dispatched_at")?.or(DB::optional_instant(row, "created_at")?),
            None => None,
        };
        let candidates = failed + expired + overdue_count;
        Ok(json!({"candidates":candidates,"dispatch_failed":failed,"oldest_dispatch_failed_at":json_time(failed_at),
            "max_dispatch_failed_age_ms":age_ms(failed_at, at),"expired_leases":expired,
            "oldest_lease_expired_at":json_time(expired_at),"max_lease_expired_age_ms":age_ms(expired_at, at),
            "dispatch_overdue":overdue_count,"oldest_dispatch_overdue_since":json_time(overdue_since),
            "max_dispatch_overdue_age_ms":age_ms(overdue_since, at),"needs_attention":candidates > 0,
            "policy":{"redispatch_after_seconds":REDISPATCH_AFTER_SECONDS}}))
    }

    /// `TaskQueueController::activityAttemptStateCounts`.
    async fn activity_attempt_counts(&self, queue: &str) -> Result<Value> {
        let rows = Self::query("SELECT a.status AS attempt_status,e.status AS execution_status,r.closed_reason AS run_closed_reason,COUNT(*) AS aggregate_count FROM activity_attempts a JOIN activity_executions e ON e.id=a.activity_execution_id JOIN workflow_runs r ON r.id=a.workflow_run_id WHERE r.namespace=$1 AND COALESCE(e.queue,r.queue,'default')=$2 GROUP BY a.status,e.status,r.closed_reason")
            .bind(self.namespace.as_ref()).bind(queue).fetch_all(&self.pool).await?;
        let mut counts = [0i64; 10];
        let [total, running, retrying, timed_out, expired, failed, completed, cancelled, open, terminal] = &mut counts;
        for row in rows {
            let attempt = DB::optional_string(&row, "attempt_status")?.unwrap_or_default();
            let execution = DB::optional_string(&row, "execution_status")?.unwrap_or_default();
            let closed = DB::optional_string(&row, "run_closed_reason")?.unwrap_or_default();
            let count = DB::number(&row, "aggregate_count")?;
            if count <= 0 {
                continue;
            }
            *total += count;
            match attempt.as_str() {
                "running" => {
                    *running += count;
                    *open += count;
                }
                "failed" if matches!(execution.as_str(), "pending" | "running") => {
                    *retrying += count;
                    *open += count;
                }
                "expired" => {
                    *timed_out += count;
                    *expired += count;
                    *terminal += count;
                }
                "failed" if execution == "failed" && closed == "timed_out" => {
                    *timed_out += count;
                    *terminal += count;
                }
                "failed" => {
                    *failed += count;
                    *terminal += count;
                }
                "completed" => {
                    *completed += count;
                    *terminal += count;
                }
                "cancelled" => {
                    *cancelled += count;
                    *terminal += count;
                }
                _ => {}
            }
        }
        let [total, running, retrying, timed_out, expired, failed, completed, cancelled, open, terminal] = counts;
        Ok(json!({"total_count":total,"running_count":running,"retrying_count":retrying,"timed_out_count":timed_out,
            "expired_count":expired,"failed_count":failed,"completed_count":completed,"cancelled_count":cancelled,
            "open_count":open,"terminal_count":terminal}))
    }

    /// `WorkflowQueryTaskBroker::queueAdmission` with native's bound.
    async fn query_admission(&self, queue: &str) -> Result<Value> {
        let mut tx = self.begin().await?;
        let entries = self.query_entries(&mut tx).await?;
        tx.commit().await?;
        let pending = entries
            .iter()
            .filter(|(_, task)| task["status"] == "pending" && task["task_queue"] == queue)
            .count() as i64;
        let namespace_pending = entries
            .iter()
            .filter(|(_, task)| matches!(task["status"].as_str(), Some("pending" | "leased")))
            .count() as i64;
        Ok(json!({"budget_source":"server.query_tasks.max_pending_per_queue","max_pending_per_queue":QUERY_PENDING_LIMIT,
            "approximate_pending_count":pending,"remaining_pending_capacity":(QUERY_PENDING_LIMIT - namespace_pending).max(0),
            "lock_required":true,"lock_supported":true,
            "status":if namespace_pending >= QUERY_PENDING_LIMIT { "full" } else { "accepting" }}))
    }

    /// `QueueVisibilityDetail::toArray`: pollers, stats, leases and repair.
    pub(super) async fn queue_detail(&self, queue: &str, config: &QueryTaskConfig) -> Result<Value> {
        let at = now();
        let stale_after = config.worker_stale_after_seconds.max(1);
        let pollers = self.pollers_for(queue, at, stale_after).await?;
        let stats = self.queue_stats(queue, &pollers, at, stale_after).await?;
        Ok(json!({"name":queue,"pollers":pollers,"stats":stats,
            "current_leases":self.current_leases(queue, at).await?,"repair":self.repair_stats(queue, at).await?}))
    }

    /// One queue's detail with recent flow, activity attempts and admission.
    async fn queue_view(&self, queue: &str, config: &QueryTaskConfig, detail: bool) -> Result<Value> {
        let at = now();
        let base = self.queue_detail(queue, config).await?;
        let pollers: Vec<Value> = base["pollers"].as_array().cloned().unwrap_or_default();
        let mut stats = base["stats"].clone();
        stats["activity_attempts"] = self.activity_attempt_counts(queue).await?;
        let repair = base["repair"].clone();
        let active = self.grouped_counts("active_leases", queue, at).await?;
        let namespace_active = Self::query("SELECT task_type,COUNT(*) AS aggregate FROM workflow_tasks WHERE namespace=$1 AND task_type IN ('workflow','activity') AND status='leased' AND (lease_expires_at IS NULL OR lease_expires_at>$2) GROUP BY task_type")
            .bind(self.namespace.as_ref()).bind(DB::bind_time(at)).fetch_all(&self.pool).await?;
        let mut namespace_counts = [0, 0];
        for row in namespace_active {
            if let Some(index) = TASK_TYPES.iter().position(|kind| DB::string(&row, "task_type").is_ok_and(|value| value == *kind)) {
                namespace_counts[index] = DB::number(&row, "aggregate")?;
            }
        }
        let admission = json!({
            "workflow_tasks":task_admission("workflow", &pollers, &stats, (active[0], namespace_counts[0])),
            "activity_tasks":task_admission("activity", &pollers, &stats, (active[1], namespace_counts[1])),
            "query_tasks":self.query_admission(queue).await?,
        });
        Ok(if detail {
            json!({"name":queue,"pollers":pollers,"stats":stats,"current_leases":base["current_leases"],
                "repair":repair,"admission":admission})
        } else {
            json!({"name":queue,"stats":stats,"repair":repair,"admission":admission})
        })
    }

    /// `GET /api/task-queues`: every queue with tasks or registered workers.
    pub(crate) async fn list_task_queues(&self, config: &QueryTaskConfig) -> Result<Value> {
        let mut names = BTreeSet::new();
        for row in Self::query("SELECT DISTINCT queue FROM workflow_tasks WHERE namespace=$1 AND queue IS NOT NULL AND queue<>''")
            .bind(self.namespace.as_ref()).fetch_all(&self.pool).await?
        {
            names.insert(DB::string(&row, "queue")?);
        }
        for row in Self::query("SELECT DISTINCT task_queue FROM workflow_worker_registrations WHERE namespace=$1 AND task_queue IS NOT NULL")
            .bind(self.namespace.as_ref()).fetch_all(&self.pool).await?
        {
            if let Some(queue) = DB::optional_string(&row, "task_queue")?.map(|queue| queue.trim().to_owned()).filter(|queue| !queue.is_empty()) {
                names.insert(queue);
            }
        }
        let mut queues = Vec::new();
        for name in names {
            queues.push(self.queue_view(&name, config, false).await?);
        }
        Ok(json!({"namespace":self.namespace.as_ref(),"task_queues":queues}))
    }

    /// `GET /api/task-queues/{name}`.
    pub(crate) async fn describe_task_queue(&self, queue: &str, config: &QueryTaskConfig) -> Result<Value> {
        self.queue_view(queue, config, true).await
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn ages_and_lists_read_as_php_writes_them() {
        assert_eq!(age_label(Some(5)), json!("5s"));
        assert_eq!(age_label(Some(125)), json!("2m05s"));
        assert_eq!(age_label(Some(3725)), json!("1h02m05s"));
        assert_eq!(age_label(None), Value::Null);
        assert_eq!(string_list(Some(json!([" b", "a", "b", 7]))), json!(["a", "b"]));
        assert_eq!(string_list(Some(json!("[\"x\",\"y\"]"))), json!(["x", "y"]));
    }

    #[test]
    fn admission_reports_slots_and_worker_states() {
        let stats = json!({"workflow_tasks":{"leased_count":2,"ready_count":5},"activity_tasks":{"leased_count":0,"ready_count":0}});
        let pollers = [json!({"is_stale":false,"status":"active","max_concurrent_workflow_tasks":2}),
            json!({"is_stale":true,"status":"stale","max_concurrent_workflow_tasks":8})];
        let admission = task_admission("workflow", &pollers, &stats, (2, 3));
        assert_eq!((admission["configured_slot_count"].clone(), admission["status"].clone()), (json!(2), json!("saturated")));
        assert_eq!(task_admission("activity", &[], &stats, (0, 0))["status"], "no_active_workers");
    }
}
