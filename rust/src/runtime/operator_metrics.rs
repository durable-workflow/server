//! The operator metrics document of one namespace, as published PHP Server
//! 2.5.14 (Workflow 2.5.5 `OperatorMetrics::snapshot`) builds it for
//! `GET /api/system/operator-metrics`.
//!
//! The count sections read the same durable tables with PHP's predicates.
//! As Laravel binds a bare timestamp in whole seconds, task comparisons with
//! "now" use the current second, so a task made available within it still
//! counts as delayed; activity deadlines and schedule times compare with
//! microseconds, as PHP formats them. The policy sections describe this
//! runtime, and projections native derives from history instead of storing
//! are reported as not maintained.
use super::{
    Result,
    backend::Backend,
    store::{Store, now},
};
use chrono::{DateTime, Duration, SecondsFormat, Timelike, Utc};
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

/// PHP's `TaskRepairPolicy::redispatchAfterSeconds()` default: a ready task
/// still unclaimed this long after it was made available is overdue.
pub(super) const REDISPATCH_AFTER_SECONDS: i64 = 3;

/// Laravel binds a bare timestamp as `Y-m-d H:i:s`.
fn whole_second(at: DateTime<Utc>) -> DateTime<Utc> {
    at.with_nanosecond(0).unwrap_or(at)
}

/// Carbon's `toJSON()`.
pub(super) fn time(at: Option<DateTime<Utc>>) -> Value {
    json!(at.map(|at| at.to_rfc3339_opts(SecondsFormat::Micros, true)))
}

/// `(int) $oldest->diffInMilliseconds($now)`, or 0 without a timestamp.
pub(super) fn age(at: Option<DateTime<Utc>>, now: DateTime<Utc>) -> i64 {
    at.map_or(0, |at| (now - at).num_milliseconds().max(0))
}

fn earliest(times: &[Option<DateTime<Utc>>]) -> Option<DateTime<Utc>> {
    times.iter().flatten().min().copied()
}

/// Rows of a table that belong to the namespace through their run.
macro_rules! scoped {
    ($select:literal, $table:literal, $condition:expr) => {
        concat!(
            "SELECT ",
            $select,
            " FROM ",
            $table,
            " WHERE workflow_run_id IN (SELECT id FROM workflow_runs WHERE namespace=$1) AND ",
            $condition
        )
    };
}
macro_rules! summaries {
    ($condition:literal) => {
        concat!(
            "SELECT COUNT(*) FROM workflow_run_summaries WHERE namespace=$1 AND ",
            $condition
        )
    };
}
// PHP's task predicates. $2 is the bound "now".
macro_rules! ready_due {
    () => {
        "status='ready' AND (available_at IS NULL OR available_at<=$2)"
    };
}
macro_rules! lease_expired {
    () => {
        "status='leased' AND lease_expires_at IS NOT NULL AND lease_expires_at<=$2"
    };
}
macro_rules! dispatch_failed {
    () => {
        "status='ready' AND last_dispatch_attempt_at IS NOT NULL AND last_dispatch_error IS NOT NULL AND last_dispatch_error!='' AND (last_dispatched_at IS NULL OR last_dispatch_attempt_at>last_dispatched_at)"
    };
}
macro_rules! claim_failed {
    () => {
        "status='ready' AND last_claim_failed_at IS NOT NULL AND last_claim_error IS NOT NULL AND last_claim_error!=''"
    };
}
// Ready and due with healthy dispatch and claim, and made available (its last
// dispatch, or its creation when never dispatched) before the cutoff $3.
macro_rules! dispatch_overdue {
    () => {
        "status='ready' AND (available_at IS NULL OR available_at<=$2) AND (last_dispatch_attempt_at IS NULL OR last_dispatch_error IS NULL OR last_dispatch_error='' OR (last_dispatched_at IS NOT NULL AND last_dispatch_attempt_at<=last_dispatched_at)) AND (last_claim_failed_at IS NULL OR last_claim_error IS NULL OR last_claim_error='') AND COALESCE(last_dispatched_at,created_at)<=$3"
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
    /// A count over the namespace, with any further bound timestamps.
    async fn tally(&self, sql: &'static str, times: &[DateTime<Utc>]) -> Result<i64> {
        let mut query = Self::scalar(sql).bind(self.namespace.as_ref());
        for at in times {
            query = query.bind(DB::bind_time(*at));
        }
        Ok(query.fetch_one(&self.pool).await?)
    }

    /// The first row's `column`, or its `fallback` when that is null.
    async fn first_time(
        &self,
        sql: &'static str,
        times: &[DateTime<Utc>],
        column: &str,
        fallback: Option<&str>,
    ) -> Result<Option<DateTime<Utc>>> {
        let mut query = Self::query(sql).bind(self.namespace.as_ref());
        for at in times {
            query = query.bind(DB::bind_time(*at));
        }
        let Some(row) = query.fetch_optional(&self.pool).await? else {
            return Ok(None);
        };
        match DB::optional_instant(&row, column)? {
            Some(at) => Ok(Some(at)),
            None => fallback.map_or(Ok(None), |column| DB::optional_instant(&row, column)),
        }
    }

    async fn largest(&self, sql: &'static str) -> Result<i64> {
        let row = Self::query(sql)
            .bind(self.namespace.as_ref())
            .fetch_one(&self.pool)
            .await?;
        Ok(DB::optional_number(&row, "n")?.unwrap_or_default())
    }

    async fn statuses(&self, sql: &'static str) -> Result<Vec<String>> {
        Self::query(sql)
            .bind(self.namespace.as_ref())
            .fetch_all(&self.pool)
            .await?
            .iter()
            .map(|row| DB::string(row, "status"))
            .collect()
    }

    /// `OperatorMetrics::snapshot` for this namespace.
    pub(super) async fn operator_metrics_snapshot(&self, config: &MetricsConfig) -> Result<Value> {
        let now = now();
        Ok(json!({
            "generated_at": time(Some(now)),
            "runs": self.run_metrics(now).await?,
            "tasks": self.task_metrics(now).await?,
            "activities": self.activity_metrics(now).await?,
            "backlog": self.backlog_metrics(now).await?,
            "repair": empty_repair_snapshot(),
            "starts": self.start_metrics(now).await?,
            "history": self.history_metrics().await?,
            "command_contracts": self.command_contract_metrics().await?,
            "projections": self.projection_metrics(now).await?,
            "schedules": self.schedule_metrics(now).await?,
            "workers": self.worker_metrics().await?,
            "backend": backend_capabilities(now, DB::PHP_CONNECTION),
            "structural_limits": structural_limits(DB::PHP_CONNECTION),
            "update_wait": {"completion_timeout_seconds": 10, "poll_interval_milliseconds": 50},
            "repair_policy": repair_policy(),
            "matching_role": matching_role(config),
            "sticky_execution": sticky_execution(),
        }))
    }

    /// `GET /api/system/operator-metrics`: the snapshot with the sections the
    /// server adds.
    pub(super) async fn operator_metrics(&self, config: &MetricsConfig) -> Result<Value> {
        let mut metrics = self.operator_metrics_snapshot(config).await?;
        metrics["worker_sessions"] =
            json!({"total": 0, "active": 0, "closed": 0, "expired": 0, "failed": 0, "orphaned": 0});
        metrics["capacity_evidence"] =
            json!({"available": false, "reason": "native_capacity_evidence_unavailable"});
        metrics["runtime_external_payload_cleanup"] =
            self.external_payload_cleanup_metrics(config).await?;
        Ok(json!({"namespace": self.namespace.as_ref(), "operator_metrics": metrics}))
    }

    /// `GET /api/system/health`, with PHP's HTTP status.
    pub(super) async fn system_health(&self, config: &MetricsConfig) -> Result<(bool, Value)> {
        let health = super::system_health::snapshot(&self.operator_metrics_snapshot(config).await?);
        let retention = Self::query(
            "SELECT retention_mode,retention_days FROM workflow_namespaces WHERE name=$1",
        )
        .bind(self.namespace.as_ref())
        .fetch_optional(&self.pool)
        .await?;
        let (mode, days) = match &retention {
            Some(row) => (
                DB::optional_string(row, "retention_mode")?,
                DB::optional_number(row, "retention_days")?,
            ),
            None => (Some("bounded".to_owned()), Some(30)),
        };
        let unavailable = health["status"] == "error";
        Ok((
            unavailable,
            json!({"namespace": self.namespace.as_ref(), "retention_mode": mode, "retention_days": days, "health": health}),
        ))
    }

    /// PHP's `RunCommandContract::backfillStatus` over the namespace's runs
    /// with a WorkflowStarted event, 200 runs at a time. Native has no PHP
    /// class snapshot to backfill from, so none is available.
    async fn command_contract_metrics(&self) -> Result<Value> {
        let (mut needed, mut actionable) = (0_i64, 0_i64);
        let mut after = String::new();
        loop {
            let rows = Self::query("SELECT r.id,r.status,h.payload FROM workflow_runs r JOIN workflow_history_events h ON h.workflow_run_id=r.id AND h.event_type='WorkflowStarted' WHERE r.namespace=$1 AND r.id>$2 ORDER BY r.id,h.sequence LIMIT 200")
                .bind(self.namespace.as_ref())
                .bind(after.as_str())
                .fetch_all(&self.pool)
                .await?;
            let Some(last) = rows.last() else { break };
            let last = DB::string(last, "id")?;
            for row in &rows {
                let id = DB::string(row, "id")?;
                // The first WorkflowStarted event by sequence decides.
                if id == after {
                    continue;
                }
                after = id;
                let payload = DB::optional_document_row(row, "payload")?.unwrap_or(Value::Null);
                if super::run_observation::command_contract(Some(&payload))["backfill_needed"]
                    != true
                {
                    continue;
                }
                needed += 1;
                if !super::run_observation_store::terminal(&DB::string(row, "status")?) {
                    actionable += 1;
                }
            }
            after = last;
            if rows.len() < 200 {
                break;
            }
        }
        Ok(json!({
            "backfill_needed_runs": needed,
            "backfill_available_runs": 0,
            "backfill_unavailable_runs": needed,
            "actionable_backfill_needed_runs": actionable,
            "actionable_backfill_available_runs": 0,
            "actionable_backfill_unavailable_runs": actionable,
            "closed_backfill_needed_runs": needed - actionable,
        }))
    }

    async fn run_metrics(&self, now: DateTime<Utc>) -> Result<Value> {
        let repair = self.first_time("SELECT updated_at,started_at FROM workflow_run_summaries WHERE namespace=$1 AND liveness_state='repair_needed' ORDER BY COALESCE(updated_at,started_at) LIMIT 1",
            &[], "updated_at", Some("started_at")).await?;
        let wait = self.first_time("SELECT wait_started_at FROM workflow_run_summaries WHERE namespace=$1 AND status_bucket='running' AND wait_started_at IS NOT NULL ORDER BY wait_started_at LIMIT 1",
            &[], "wait_started_at", None).await?;
        Ok(json!({
            "total": self.tally("SELECT COUNT(*) FROM workflow_run_summaries WHERE namespace=$1", &[]).await?,
            "current": self.tally(summaries!("is_current_run=TRUE"), &[]).await?,
            "running": self.tally(summaries!("status_bucket='running'"), &[]).await?,
            "completed": self.tally(summaries!("status='completed'"), &[]).await?,
            "failed": self.tally(summaries!("status='failed'"), &[]).await?,
            "cancelled": self.tally(summaries!("status='cancelled'"), &[]).await?,
            "terminated": self.tally(summaries!("status='terminated'"), &[]).await?,
            "archived": self.tally(summaries!("archived_at IS NOT NULL"), &[]).await?,
            "repair_needed": self.tally(summaries!("liveness_state='repair_needed'"), &[]).await?,
            "oldest_repair_needed_at": time(repair),
            "max_repair_needed_age_ms": age(repair, now),
            "claim_failed": self.tally(summaries!("liveness_state LIKE '%_task_claim_failed'"), &[]).await?,
            "compatibility_blocked": self.compatibility_blocked_runs().await?,
            "waiting": self.tally(summaries!("status_bucket='running' AND wait_started_at IS NOT NULL"), &[]).await?,
            "oldest_wait_started_at": time(wait),
            "max_wait_age_ms": age(wait, now),
        }))
    }

    async fn compatibility_blocked_runs(&self) -> Result<i64> {
        self.tally(
            summaries!("liveness_state LIKE '%_task_waiting_for_compatible_worker'"),
            &[],
        )
        .await
    }

    async fn task_metrics(&self, now: DateTime<Utc>) -> Result<Value> {
        let bound = whole_second(now);
        let cutoff = whole_second(now - Duration::seconds(REDISPATCH_AFTER_SECONDS));
        let lease_expired_at = self
            .first_time(
                concat!(
                    scoped!("lease_expires_at", "workflow_tasks", lease_expired!()),
                    " ORDER BY lease_expires_at LIMIT 1"
                ),
                &[bound],
                "lease_expires_at",
                None,
            )
            .await?;
        let ready_due_at = self
            .first_time(
                concat!(
                    scoped!("available_at,created_at", "workflow_tasks", ready_due!()),
                    " ORDER BY COALESCE(available_at,created_at) LIMIT 1"
                ),
                &[bound],
                "available_at",
                Some("created_at"),
            )
            .await?;
        let overdue_since = self
            .first_time(
                concat!(
                    scoped!(
                        "last_dispatched_at,created_at",
                        "workflow_tasks",
                        dispatch_overdue!()
                    ),
                    " ORDER BY COALESCE(last_dispatched_at,created_at) LIMIT 1"
                ),
                &[bound, cutoff],
                "last_dispatched_at",
                Some("created_at"),
            )
            .await?;
        let claim_failed_at = self
            .first_time(
                concat!(
                    scoped!("last_claim_failed_at", "workflow_tasks", claim_failed!()),
                    " ORDER BY last_claim_failed_at LIMIT 1"
                ),
                &[],
                "last_claim_failed_at",
                None,
            )
            .await?;
        let dispatch_failed_at = self
            .first_time(
                concat!(
                    scoped!(
                        "last_dispatch_attempt_at",
                        "workflow_tasks",
                        dispatch_failed!()
                    ),
                    " ORDER BY last_dispatch_attempt_at LIMIT 1"
                ),
                &[],
                "last_dispatch_attempt_at",
                None,
            )
            .await?;
        let unhealthy = self.unhealthy_tasks(now).await?;
        let unhealthy_at = earliest(&[
            dispatch_failed_at,
            claim_failed_at,
            overdue_since,
            lease_expired_at,
        ]);
        Ok(json!({
            "open": self.tally(scoped!("COUNT(*)", "workflow_tasks", "status IN ('ready','leased')"), &[]).await?,
            "ready": self.tally(scoped!("COUNT(*)", "workflow_tasks", "status='ready'"), &[]).await?,
            "ready_due": self.tally(scoped!("COUNT(*)", "workflow_tasks", ready_due!()), &[bound]).await?,
            "delayed": self.tally(scoped!("COUNT(*)", "workflow_tasks", "status='ready' AND available_at>$2"), &[bound]).await?,
            "leased": self.tally(scoped!("COUNT(*)", "workflow_tasks", "status='leased'"), &[]).await?,
            "dispatch_failed": unhealthy.dispatch_failed,
            "claim_failed": unhealthy.claim_failed,
            "dispatch_overdue": unhealthy.dispatch_overdue,
            "lease_expired": unhealthy.lease_expired,
            "oldest_lease_expired_at": time(lease_expired_at),
            "max_lease_expired_age_ms": age(lease_expired_at, now),
            "oldest_ready_due_at": time(ready_due_at),
            "max_ready_due_age_ms": age(ready_due_at, now),
            "oldest_dispatch_overdue_since": time(overdue_since),
            "max_dispatch_overdue_age_ms": age(overdue_since, now),
            "oldest_claim_failed_at": time(claim_failed_at),
            "max_claim_failed_age_ms": age(claim_failed_at, now),
            "oldest_dispatch_failed_at": time(dispatch_failed_at),
            "max_dispatch_failed_age_ms": age(dispatch_failed_at, now),
            "max_attempt_count": self.largest(scoped!("MAX(attempt_count) AS n", "workflow_tasks", "status IN ('ready','leased')")).await?,
            "max_repair_count": self.largest(scoped!("MAX(repair_count) AS n", "workflow_tasks", "status IN ('ready','leased')")).await?,
            "unhealthy": unhealthy.total(),
            "oldest_unhealthy_at": time(unhealthy_at),
            "max_unhealthy_age_ms": age(unhealthy_at, now),
        }))
    }

    async fn unhealthy_tasks(&self, now: DateTime<Utc>) -> Result<UnhealthyTasks> {
        let bound = whole_second(now);
        let cutoff = whole_second(now - Duration::seconds(REDISPATCH_AFTER_SECONDS));
        Ok(UnhealthyTasks {
            dispatch_failed: self
                .tally(
                    scoped!("COUNT(*)", "workflow_tasks", dispatch_failed!()),
                    &[],
                )
                .await?,
            claim_failed: self
                .tally(scoped!("COUNT(*)", "workflow_tasks", claim_failed!()), &[])
                .await?,
            dispatch_overdue: self
                .tally(
                    scoped!("COUNT(*)", "workflow_tasks", dispatch_overdue!()),
                    &[bound, cutoff],
                )
                .await?,
            lease_expired: self
                .tally(
                    scoped!("COUNT(*)", "workflow_tasks", lease_expired!()),
                    &[bound],
                )
                .await?,
        })
    }

    async fn activity_metrics(&self, now: DateTime<Utc>) -> Result<Value> {
        let retrying_at = self.first_time(scoped!("started_at", "activity_executions", "status='pending' AND attempt_count>0 AND started_at IS NOT NULL ORDER BY started_at LIMIT 1"),
            &[], "started_at", None).await?;
        let mut deadlines = Vec::new();
        for (sql, column) in [
            (
                scoped!(
                    "schedule_deadline_at",
                    "activity_executions",
                    "status='pending' AND schedule_deadline_at IS NOT NULL AND schedule_deadline_at<=$2 ORDER BY schedule_deadline_at LIMIT 1"
                ),
                "schedule_deadline_at",
            ),
            (
                scoped!(
                    "close_deadline_at",
                    "activity_executions",
                    "status='running' AND close_deadline_at IS NOT NULL AND close_deadline_at<=$2 ORDER BY close_deadline_at LIMIT 1"
                ),
                "close_deadline_at",
            ),
            (
                scoped!(
                    "schedule_to_close_deadline_at",
                    "activity_executions",
                    "status IN ('pending','running') AND schedule_to_close_deadline_at IS NOT NULL AND schedule_to_close_deadline_at<=$2 ORDER BY schedule_to_close_deadline_at LIMIT 1"
                ),
                "schedule_to_close_deadline_at",
            ),
            (
                scoped!(
                    "heartbeat_deadline_at",
                    "activity_executions",
                    "status='running' AND heartbeat_deadline_at IS NOT NULL AND heartbeat_deadline_at<=$2 ORDER BY heartbeat_deadline_at LIMIT 1"
                ),
                "heartbeat_deadline_at",
            ),
        ] {
            deadlines.push(self.first_time(sql, &[now], column, None).await?);
        }
        let overdue_at = earliest(&deadlines);
        let open = self
            .tally(
                scoped!(
                    "COUNT(*)",
                    "activity_executions",
                    "status IN ('pending','running')"
                ),
                &[],
            )
            .await?;
        let pending = self
            .tally(
                scoped!("COUNT(*)", "activity_executions", "status='pending'"),
                &[],
            )
            .await?;
        let running = self
            .tally(
                scoped!("COUNT(*)", "activity_executions", "status='running'"),
                &[],
            )
            .await?;
        let failed_attempts = self
            .tally(
                scoped!("COUNT(*)", "activity_attempts", "status='failed'"),
                &[],
            )
            .await?;
        let local = self.statuses(DB::LOCAL_ACTIVITY_EXECUTIONS).await?;
        let local_count = |status: &[&str]| {
            local
                .iter()
                .filter(|s| status.contains(&s.as_str()))
                .count() as i64
        };
        let (local_open, local_pending, local_running) = (
            local_count(&["pending", "running"]),
            local_count(&["pending"]),
            local_count(&["running"]),
        );
        let local_attempts = self.statuses(DB::LOCAL_ACTIVITY_ATTEMPTS).await?;
        let local_failed_attempts = local_attempts.iter().filter(|s| *s == "failed").count() as i64;
        Ok(json!({
            "open": open,
            "pending": pending,
            "running": running,
            "local": local.len(),
            "local_open": local_open,
            "local_pending": local_pending,
            "local_running": local_running,
            "local_attempts": local_attempts.len(),
            "local_failed_attempts": local_failed_attempts,
            "queued_open": (open - local_open).max(0),
            "queued_pending": (pending - local_pending).max(0),
            "queued_running": (running - local_running).max(0),
            "queued_failed_attempts": (failed_attempts - local_failed_attempts).max(0),
            "retrying": self.retrying_activities().await?,
            "oldest_retrying_started_at": time(retrying_at),
            "max_retrying_age_ms": age(retrying_at, now),
            "timeout_overdue": self.tally(scoped!("COUNT(*)", "activity_executions", "status IN ('pending','running') AND ((status='pending' AND schedule_deadline_at IS NOT NULL AND schedule_deadline_at<=$2) OR (status='running' AND close_deadline_at IS NOT NULL AND close_deadline_at<=$3) OR (schedule_to_close_deadline_at IS NOT NULL AND schedule_to_close_deadline_at<=$4) OR (status='running' AND heartbeat_deadline_at IS NOT NULL AND heartbeat_deadline_at<=$5))"), &[now, now, now, now]).await?,
            "oldest_timeout_overdue_at": time(overdue_at),
            "max_timeout_overdue_age_ms": age(overdue_at, now),
            "failed_attempts": failed_attempts,
            "max_attempt_count": self.largest(scoped!("MAX(attempt_count) AS n", "activity_executions", "1=1")).await?,
        }))
    }

    async fn retrying_activities(&self) -> Result<i64> {
        self.tally(
            scoped!(
                "COUNT(*)",
                "activity_executions",
                "status='pending' AND attempt_count>0"
            ),
            &[],
        )
        .await
    }

    async fn backlog_metrics(&self, now: DateTime<Utc>) -> Result<Value> {
        let bound = whole_second(now);
        let minute = whole_second(now - Duration::minutes(1));
        let blocked_at = self.first_time("SELECT wait_started_at,next_task_at FROM workflow_run_summaries WHERE namespace=$1 AND liveness_state LIKE '%_task_waiting_for_compatible_worker' ORDER BY COALESCE(wait_started_at,next_task_at) LIMIT 1",
            &[], "wait_started_at", Some("next_task_at")).await?;
        Ok(json!({
            "runnable_tasks": self.tally(scoped!("COUNT(*)", "workflow_tasks", ready_due!()), &[bound]).await?,
            "delayed_tasks": self.tally(scoped!("COUNT(*)", "workflow_tasks", "status='ready' AND available_at>$2"), &[bound]).await?,
            "leased_tasks": self.tally(scoped!("COUNT(*)", "workflow_tasks", "status='leased'"), &[]).await?,
            "tasks_added_last_minute": self.tally(scoped!("COUNT(*)", "workflow_tasks", "created_at>=$2"), &[minute]).await?,
            "tasks_dispatched_last_minute": self.tally(scoped!("COUNT(*)", "workflow_tasks", "last_dispatched_at IS NOT NULL AND last_dispatched_at>=$2"), &[minute]).await?,
            "retrying_activities": self.retrying_activities().await?,
            "unhealthy_tasks": self.unhealthy_tasks(now).await?.total(),
            "repair_needed_runs": self.tally(summaries!("liveness_state='repair_needed'"), &[]).await?,
            "claim_failed_runs": self.tally(summaries!("liveness_state LIKE '%_task_claim_failed'"), &[]).await?,
            "compatibility_blocked_runs": self.compatibility_blocked_runs().await?,
            "oldest_compatibility_blocked_started_at": time(blocked_at),
            "max_compatibility_blocked_age_ms": age(blocked_at, now),
        }))
    }

    async fn start_metrics(&self, now: DateTime<Utc>) -> Result<Value> {
        let bound = whole_second(now);
        let accepted = self.first_time("SELECT accepted_at FROM workflow_commands WHERE workflow_run_id IN (SELECT id FROM workflow_runs WHERE namespace=$1 AND status='pending') AND command_type='start' AND status='accepted' AND outcome='started_new' AND accepted_at IS NOT NULL ORDER BY accepted_at,id LIMIT 1",
            &[], "accepted_at", None).await?;
        let pending_at = match accepted {
            Some(at) => Some(at),
            None => self.first_time("SELECT started_at FROM workflow_run_summaries WHERE namespace=$1 AND status='pending' AND started_at IS NOT NULL ORDER BY started_at,id LIMIT 1",
                &[], "started_at", None).await?,
        };
        Ok(json!({
            "pending_runs": self.tally(summaries!("status='pending'"), &[]).await?,
            "pending_commands": self.tally("SELECT COUNT(*) FROM workflow_commands WHERE workflow_run_id IN (SELECT id FROM workflow_runs WHERE namespace=$1 AND status='pending') AND command_type='start' AND status='accepted' AND outcome='started_new'", &[]).await?,
            "ready_tasks": self.tally("SELECT COUNT(*) FROM workflow_tasks WHERE workflow_run_id IN (SELECT id FROM workflow_runs WHERE namespace=$1 AND status='pending') AND task_type='workflow' AND status='ready' AND (available_at IS NULL OR available_at<=$2)", &[bound]).await?,
            "oldest_pending_start_at": time(pending_at),
            "max_pending_ms": age(pending_at, now),
        }))
    }

    async fn history_metrics(&self) -> Result<Value> {
        let [
            events,
            bytes,
            fan_out,
            events_warning,
            bytes_warning,
            fan_out_warning,
        ] = self.history_budget.thresholds();
        Ok(json!({
            "continue_as_new_recommended_runs": self.tally(summaries!("continue_as_new_recommended=TRUE"), &[]).await?,
            "approaching_budget_runs": self.tally(summaries!("history_budget_pressure='approaching'"), &[]).await?,
            "events": self.tally(scoped!("COUNT(*)", "workflow_history_events", "1=1"), &[]).await?,
            // PHP counts orphaned events only without a namespace.
            "history_orphan_total": 0,
            "max_event_count": self.largest("SELECT MAX(history_event_count) AS n FROM workflow_run_summaries WHERE namespace=$1").await?,
            "max_size_bytes": self.largest("SELECT MAX(history_size_bytes) AS n FROM workflow_run_summaries WHERE namespace=$1").await?,
            "max_fan_out": self.largest("SELECT MAX(history_fan_out) AS n FROM workflow_run_summaries WHERE namespace=$1").await?,
            "event_threshold": events,
            "size_bytes_threshold": bytes,
            "fan_out_threshold": fan_out,
            "event_warning_threshold": events_warning,
            "size_bytes_warning_threshold": bytes_warning,
            "fan_out_warning_threshold": fan_out_warning,
        }))
    }

    async fn projection_metrics(&self, now: DateTime<Utc>) -> Result<Value> {
        let runs = self
            .tally("SELECT COUNT(*) FROM workflow_runs WHERE namespace=$1", &[])
            .await?;
        let summaries = self
            .tally(
                "SELECT COUNT(*) FROM workflow_run_summaries WHERE namespace=$1",
                &[],
            )
            .await?;
        let missing = self.tally("SELECT COUNT(*) FROM workflow_runs r WHERE r.namespace=$1 AND NOT EXISTS (SELECT 1 FROM workflow_run_summaries s WHERE s.id=r.id)", &[]).await?;
        let missing_at = self.first_time("SELECT started_at,created_at FROM workflow_runs r WHERE r.namespace=$1 AND NOT EXISTS (SELECT 1 FROM workflow_run_summaries s WHERE s.id=r.id) ORDER BY COALESCE(started_at,created_at) LIMIT 1",
            &[], "started_at", Some("created_at")).await?;
        let summary_times = Self::query("SELECT MIN(updated_at) AS oldest,MAX(updated_at) AS newest FROM workflow_run_summaries WHERE namespace=$1")
            .bind(self.namespace.as_ref()).fetch_one(&self.pool).await?;
        let with_open_waits = self
            .tally(summaries!("open_wait_id IS NOT NULL"), &[])
            .await?;
        let projected_wait_runs = self
            .tally(
                scoped!(
                    "COUNT(DISTINCT workflow_run_id)",
                    "workflow_run_waits",
                    "1=1"
                ),
                &[],
            )
            .await?;
        let missing_open_waits = self.tally("SELECT COUNT(*) FROM workflow_run_summaries s WHERE s.namespace=$1 AND s.open_wait_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM workflow_run_waits w WHERE w.workflow_run_id=s.id AND w.wait_id=s.open_wait_id)", &[]).await?;
        let wait_times = Self::query("SELECT MIN(updated_at) AS oldest,MAX(updated_at) AS newest FROM workflow_run_waits WHERE workflow_run_id IN (SELECT id FROM workflow_runs WHERE namespace=$1)")
            .bind(self.namespace.as_ref()).fetch_one(&self.pool).await?;
        let not_maintained = |source: &str| {
            json!({
                "maintained": false,
                "reason": "native_derives_from_history",
                "source": source,
                "runs": runs,
                "needs_rebuild": 0,
            })
        };
        Ok(json!({
            "run_summaries": {
                "runs": runs,
                "summaries": summaries,
                "missing": missing,
                "orphaned": 0,
                "stale": 0,
                "schema_outdated": self.tally(summaries!("(projection_schema_version IS NULL OR projection_schema_version<2)"), &[]).await?,
                "needs_rebuild": missing,
                "oldest_updated_at": time(DB::optional_instant(&summary_times, "oldest")?),
                "newest_updated_at": time(DB::optional_instant(&summary_times, "newest")?),
                "oldest_missing_run_started_at": time(missing_at),
                "max_missing_run_age_ms": age(missing_at, now),
            },
            // Native writes a run's waits in the transaction that records its
            // history, so the waits cannot drift from it: every run with waits is
            // projected, and only a deleted run could leave rows orphaned.
            "run_waits": {
                "runs": runs,
                "rows": self.tally(scoped!("COUNT(*)", "workflow_run_waits", "1=1"), &[]).await?,
                "projected_runs": projected_wait_runs,
                "runs_with_waits": projected_wait_runs,
                "projected_runs_with_waits": projected_wait_runs,
                "missing_runs_with_waits": 0,
                "stale_projected_runs": 0,
                "summaries_with_open_waits": with_open_waits,
                "projected_current_open_waits": (with_open_waits - missing_open_waits).max(0),
                "missing_current_open_waits": missing_open_waits,
                "orphaned": 0,
                "needs_rebuild": 0,
                "oldest_updated_at": time(DB::optional_instant(&wait_times, "oldest")?),
                "newest_updated_at": time(DB::optional_instant(&wait_times, "newest")?),
            },
            "run_timeline_entries": not_maintained("workflow_history_events"),
            "run_timer_entries": not_maintained("workflow_history_events"),
            "run_lineage_entries": not_maintained("workflow_history_events"),
        }))
    }

    async fn schedule_metrics(&self, now: DateTime<Utc>) -> Result<Value> {
        let missed_at = self.first_time("SELECT next_fire_at FROM workflow_schedules WHERE namespace=$1 AND status='active' AND next_fire_at IS NOT NULL AND next_fire_at<=$2 ORDER BY next_fire_at LIMIT 1",
            &[now], "next_fire_at", None).await?;
        let mut fires = 0;
        let mut failures = 0;
        for row in Self::query("SELECT fires_count,failures_count FROM workflow_schedules WHERE namespace=$1 AND status='active'")
            .bind(self.namespace.as_ref()).fetch_all(&self.pool).await?
        {
            fires += DB::optional_number(&row, "fires_count")?.unwrap_or_default();
            failures += DB::optional_number(&row, "failures_count")?.unwrap_or_default();
        }
        Ok(json!({
            "active": self.tally("SELECT COUNT(*) FROM workflow_schedules WHERE namespace=$1 AND status='active'", &[]).await?,
            "paused": self.tally("SELECT COUNT(*) FROM workflow_schedules WHERE namespace=$1 AND status='paused'", &[]).await?,
            "missed": self.tally("SELECT COUNT(*) FROM workflow_schedules WHERE namespace=$1 AND status='active' AND next_fire_at IS NOT NULL AND next_fire_at<=$2", &[now]).await?,
            "oldest_overdue_at": time(missed_at),
            "max_overdue_ms": age(missed_at, now),
            "fires_total": fires,
            "failures_total": failures,
        }))
    }

    /// PHP's worker compatibility fleet: workers whose last registration or
    /// heartbeat is within the compatibility heartbeat TTL. Native records
    /// these on the worker registration itself.
    async fn worker_metrics(&self) -> Result<Value> {
        let fresh_after = now() - Duration::seconds(COMPATIBILITY_HEARTBEAT_TTL_SECONDS);
        let rows = Self::query("SELECT worker_id,task_queue,last_heartbeat_at FROM workflow_worker_registrations WHERE namespace=$1 AND status='active' AND last_heartbeat_at>$2 ORDER BY worker_id")
            .bind(self.namespace.as_ref()).bind(DB::bind_time(fresh_after)).fetch_all(&self.pool).await?;
        let mut fleet = Vec::new();
        for row in &rows {
            let recorded = DB::optional_instant(row, "last_heartbeat_at")?;
            fleet.push(json!({
                "worker_id": DB::string(row, "worker_id")?,
                "namespace": self.namespace.as_ref(),
                "host": null,
                "process_id": null,
                "connection": null,
                "queue": DB::optional_string(row, "task_queue")?,
                "supported": [],
                "supports_required": true,
                "recorded_at": time(recorded),
                "expires_at": time(recorded.map(|at| at + Duration::seconds(COMPATIBILITY_HEARTBEAT_TTL_SECONDS))),
                "source": "worker_registration",
            }));
        }
        Ok(json!({
            "compatibility_namespace": self.namespace.as_ref(),
            "required_compatibility": null,
            "active_workers": fleet.len(),
            "active_worker_scopes": fleet.len(),
            "active_workers_supporting_required": fleet.len(),
            "fleet": fleet,
        }))
    }

    /// PHP's `RuntimeExternalPayloadCleanupMetrics`: the expired registrations
    /// and the namespace's recorded cleanup passes.
    async fn external_payload_cleanup_metrics(&self, config: &MetricsConfig) -> Result<Value> {
        let bound = whole_second(now());
        let stats =
            Self::query("SELECT * FROM runtime_external_payload_cleanup_stats WHERE namespace=$1")
                .bind(self.namespace.as_ref())
                .fetch_optional(&self.pool)
                .await?;
        let number = |field: &str| -> Result<i64> {
            Ok(match &stats {
                Some(row) => DB::optional_number(row, field)?.unwrap_or_default(),
                None => 0,
            })
        };
        let instant = |field: &str| -> Result<Value> {
            Ok(time(match &stats {
                Some(row) => DB::optional_instant(row, field)?,
                None => None,
            }))
        };
        let status = match &stats {
            Some(row) => DB::optional_string(row, "last_pass_status")?,
            None => None,
        };
        Ok(json!({
            "schema": "durable-workflow.v2.runtime-external-payload-cleanup-metrics.v1",
            "backlog": {
                "expired_unclaimed": self.tally("SELECT COUNT(*) FROM runtime_external_payloads WHERE namespace=$1 AND retained_at IS NULL AND expires_at IS NOT NULL AND expires_at<=$2", &[bound]).await?,
                "failed_registration_objects": self.tally("SELECT COUNT(*) FROM runtime_external_payloads WHERE namespace=$1 AND retained_at IS NULL AND expires_at IS NOT NULL AND expires_at<=$2 AND upload_status='writing'", &[bound]).await?,
            },
            "bounds": {"abandoned_upload_expiry_seconds": config.abandoned_upload_expiry_seconds, "default_batch_size": 100, "maximum_batch_size": 1000},
            "totals": {
                "passes": number("passes_total")?,
                "deleted_references": number("deleted_references_total")?,
                "deleted_backing_objects": number("deleted_backing_objects_total")?,
                "shared_objects_preserved": number("shared_objects_preserved_total")?,
                "blocked_outcomes": number("blocked_outcomes_total")?,
                "storage_driver_failures": number("storage_driver_failures_total")?,
            },
            "last_pass": {
                "status": status,
                "processed": number("last_processed")?,
                "deleted_references": number("last_deleted_references")?,
                "deleted_backing_objects": number("last_deleted_backing_objects")?,
                "shared_objects_preserved": number("last_shared_objects_preserved")?,
                "blocked_outcomes": number("last_blocked_outcomes")?,
                "storage_driver_failures": number("last_storage_driver_failures")?,
                "completed_at": instant("last_completed_at")?,
                "last_storage_failure_at": instant("last_storage_failure_at")?,
            },
            "identity_policy": {"provider_credentials_exposed": false, "provider_locations_exposed": false, "reusable_reference_identities_exposed": false},
        }))
    }
}

struct UnhealthyTasks {
    dispatch_failed: i64,
    claim_failed: i64,
    dispatch_overdue: i64,
    lease_expired: i64,
}

impl UnhealthyTasks {
    fn total(&self) -> i64 {
        self.dispatch_failed + self.claim_failed + self.dispatch_overdue + self.lease_expired
    }
}

/// PHP's `workflows.v2.compatibility.heartbeat_ttl_seconds` default.
const COMPATIBILITY_HEARTBEAT_TTL_SECONDS: i64 = 30;

/// Runtime settings the document reports.
pub(crate) struct MetricsConfig {
    pub(crate) workflow_task_lease_seconds: i64,
    pub(crate) abandoned_upload_expiry_seconds: i64,
}

fn empty_repair_snapshot() -> Value {
    json!({
        "existing_task_candidates": 0,
        "missing_task_candidates": 0,
        "total_candidates": 0,
        "scan_limit": 25,
        "scan_strategy": "scope_fair_round_robin",
        "selected_existing_task_candidates": 0,
        "selected_missing_task_candidates": 0,
        "selected_total_candidates": 0,
        "existing_task_scan_limit_reached": false,
        "missing_task_scan_limit_reached": false,
        "scan_pressure": false,
        "oldest_task_candidate_created_at": null,
        "oldest_missing_run_started_at": null,
        "max_task_candidate_age_ms": 0,
        "max_missing_run_age_ms": 0,
        "scopes": [],
    })
}

fn structural_limits(connection: &str) -> Value {
    let configured = json!({
        "pending_activity_count": 2000,
        "pending_child_count": 1000,
        "pending_timer_count": 2000,
        "pending_signal_count": 5000,
        "pending_update_count": 500,
        "command_batch_size": 1000,
        "payload_size_bytes": 2_097_152,
        "memo_size_bytes": 262_144,
        "search_attribute_size_bytes": 40960,
        "history_transaction_size": 5000,
        "warning_threshold_percent": 80,
    });
    let sqlite = connection == "sqlite";
    let mut effective = configured.clone();
    let mut adjustments = json!({});
    let mut issues = Vec::new();
    if sqlite {
        effective["concurrent_write_safety"] = json!("limited");
        adjustments["concurrent_write_safety"] = json!("limited");
        issues.push(json!({"component": "structural_limits", "severity": "info", "code": "sqlite_concurrency_note",
            "message": "SQLite serializes writes; high pending-count limits may cause lock contention under concurrent worker load."}));
    }
    json!({"configured": configured, "backend_adjustments": adjustments, "effective": effective, "issues": issues})
}

/// What this runtime needs from its backends, in PHP's capability shape.
fn backend_capabilities(now: DateTime<Utc>, connection: &str) -> Value {
    let limits = structural_limits(connection);
    let issues = limits["issues"].clone();
    let severity = if issues.as_array().is_some_and(|issues| !issues.is_empty()) {
        "info"
    } else {
        "ok"
    };
    json!({
        "generated_at": time(Some(now)),
        "runtime": "rust",
        "supported": true,
        "database": {
            "connection": connection,
            "driver": connection,
            "supported": true,
            "capabilities": {"transactions": true, "after_commit_callbacks": false, "durable_ordering": true, "row_locks": connection != "sqlite"},
            "issues": [],
        },
        "queue": {
            "connection": null,
            "driver": "database_poll",
            "supported": true,
            "capabilities": {"async_delivery": false, "delayed_delivery": true, "requires_worker": false, "max_delay_seconds": null, "after_commit_option": false},
            "issues": [],
        },
        "cache": {"store": null, "driver": "none", "supported": true, "capabilities": {"atomic_locks": false}, "issues": []},
        "codec": {"configured": "avro", "configured_canonical": "avro", "canonical": "avro", "universal": true, "configured_universal": true, "supported": true, "issues": []},
        "structural_limits": limits,
        "severity": severity,
        "issues": issues,
    })
}

/// Native claims ready tasks on each poll; it runs no repair loop.
fn repair_policy() -> Value {
    json!({
        "redispatch_after_seconds": REDISPATCH_AFTER_SECONDS,
        "loop_throttle_seconds": null,
        "scan_limit": 25,
        "scan_strategy": "claim_time_lease_recovery",
        "failure_backoff_max_seconds": null,
        "failure_backoff_strategy": null,
    })
}

fn matching_role(config: &MetricsConfig) -> Value {
    json!({
        "queue_wake_enabled": true,
        "shape": "in_server",
        "wake_owner": "server_process",
        "task_dispatch_mode": "poll",
        "partition_primitives": ["queue", "namespace"],
        "backpressure_model": "lease_ownership",
        "discovery_limits": {
            "poll_batch_cap": 100,
            "availability_ceiling_seconds": null,
            "wake_signal_ttl_seconds": null,
            "workflow_task_lease_seconds": config.workflow_task_lease_seconds,
            "activity_task_lease_seconds": 300,
        },
    })
}

/// Native routes no task to a sticky worker.
fn sticky_execution() -> Value {
    json!({
        "enabled": false,
        "ttl_seconds": 0,
        "active_sticky_runs": 0,
        "ready_sticky_tasks": 0,
        "leased_sticky_tasks": 0,
        "hit_expected_last_minute": 0,
        "miss_last_minute": 0,
        "forced_cold_replay_last_minute": 0,
        "cold_replay_last_minute": 0,
        "hit_rate_last_minute": 0.0,
        "miss_rate_last_minute": 0.0,
        "capacity_pressure_tasks": 0,
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn comparisons_bind_whole_seconds_and_ages_truncate() {
        let at = DateTime::parse_from_rfc3339("2026-10-20T23:06:14.208471Z")
            .unwrap()
            .with_timezone(&Utc);
        assert_eq!(whole_second(at).to_rfc3339(), "2026-10-20T23:06:14+00:00");
        assert_eq!(time(Some(at)), json!("2026-10-20T23:06:14.208471Z"));
        assert_eq!(age(Some(at), at + Duration::microseconds(1_999)), 1);
        assert_eq!(age(None, at), 0);
        assert_eq!(
            earliest(&[None, Some(at), Some(at - Duration::seconds(1))]),
            Some(at - Duration::seconds(1))
        );
    }
}
